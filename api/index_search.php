<?php
/**
 * GET index_search — the user-facing search over the observed-hash catalogue (?action=search page).
 * Gated by the `index.view` permission (users system): anonymous visitors get it only if the
 * admin granted it to the `guest` group. `index.files` gates file-name search + file counts,
 * `index.magnet` gates the info hash (and with it the magnet link the client builds),
 * `whitelist.view` additionally folds the live whitelist into the results (whitelisted hashes are
 * removed from the index, so this is the only way they can be found).
 * Default order is relevance (fulltext score, rarer/longer words weigh more), seeders break ties.
 * Rate limited per IP bucket; only resolved rows (meta done / named whitelist rows) are searchable.
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
if (!indexEnabled($cfg) || ($cfg['index_search_enabled'] ?? '1') !== '1') jsonResponse(['error' => 'search_disabled'], 400);
if (!userCan($db, $cfg, 'index.view')) {
    jsonResponse(['error' => currentUser($db) ? 'no_permission' : 'login_required'], 403);
}

// Refused before it costs anything, and before it counts. A one- or two-character term skips the
// fulltext index and scans the whole catalogue twice (see indexSearchTooShort); the browser does not
// send one, so what arrives here is a script or a stale client, and neither should be able to spend
// this IP's hourly budget on requests that were never going to be answered. The message is a
// sentence rather than a code because it is shown as-is under the search box; `code` is there for
// the client to recognise it without comparing translated text.
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200);
if (indexSearchTooShort($search)) {
    jsonResponse(['success' => false, 'error' => __('api.search.too_short'), 'code' => 'search_too_short'], 400);
}

$perHour = (int)($cfg['rate_limit_index_search'] ?? 120);
if (!rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$canFiles = userCan($db, $cfg, 'index.files');
$canMagnet = userCan($db, $cfg, 'index.magnet');
$canWl = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';

// Let go of the session before the slow part.
//
// PHP's file session handler holds an EXCLUSIVE lock for the whole request, so while a catalogue
// search runs — seconds, on a table of millions — every other request from the same visitor waits.
// Clicking Stats a second after clicking Search meant waiting for the search, which is exactly the
// complaint the admin panel had, from the public side.
//
// AFTER the permission checks, not before. The first attempt at this released the session in the
// router, before this file ran, and userCan()/currentUser() then saw nothing: a signed-in member got
// "login_required" on their own search. Everything below only reads, and it has already established
// who is asking.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// The catalogue can be bigger than php-fpm's own patience.
//
// On the live deployment `max_execution_time` is 30 s. A search that crossed it was killed mid-query
// and the reader got a bare 500 — nothing in the Apache error log, nothing in the fpm log, because
// `error_log` is unset there and `catch_workers_output` is off. It took reading `$9 == 500` out of
// the ACCESS log to find it at all. So the limit for THIS endpoint is a number the operator can see
// and change, and it is asked for here rather than assumed: a catalogue of three million rows is a
// different machine from one of three thousand.
$budget = max(10, min(300, (int)($cfg['search_time_budget'] ?? 60) ?: 60));
if (function_exists('set_time_limit')) @set_time_limit($budget);


// comma-separated multi-sort stack; unknown keys are dropped by indexSearchCatalogue
$sort = (string)($_GET['sort'] ?? 'relevance:desc');
if (!preg_match('/^(relevance|seeders|leechers|size|last|name|files)(:(asc|desc))?(,(relevance|seeders|leechers|size|last|name|files)(:(asc|desc))?)*$/', $sort)) {
    $sort = 'relevance:desc';
}

$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [15, 25, 50, 100, 200], true)) $perPage = 25;

// A search the database stopped for running past max_statement_time (api.php, 20 s) is an answer, not a 500 (1.74.0):
// production's log had five bare 500s from exactly this — a common word with "search in files", a deep sort — and the
// reader saw a blank error with nothing to do about it. 503 with a sentence in their language, and the code for the
// page to recognise; nothing was retried behind their back (see indexSearchCatalogue()).
try {
    $res = indexSearchCatalogue($db, $cfg, [
        'page'              => $_GET['page'] ?? 1,
        'per_page'          => $perPage,
        'sort'              => $sort,
        'search'            => $search,
        'search_files'      => $canFiles && ($_GET['search_files'] ?? '') === '1',
        'include_whitelist' => $canWl,
        'content'           => (string)($_GET['content'] ?? 'not_rejected'),
        // A reader who is not shown hashes does not search by one either (1.69.0): see indexSearchCatalogue().
        'hash_search'       => $canMagnet,
    ]);
} catch (IndexSearchTimeout $e) {
    error_log('[index search] stopped for time: ' . mb_substr($search, 0, 80) . ' sort=' . $sort . ' — ' . $e->getMessage());
    header('Retry-After: 30');
    jsonResponse(['success' => false, 'error' => __('api.search.timeout'), 'code' => 'search_timeout'], 503);
}

$repInResults = repEnabled($cfg) && repShowInResults($cfg);
$repMin = repMinVotes($cfg);
$repMode = repMode($cfg);

// Which of THIS page's hashes the reader has already favourited — one query for the page, never one
// per row. A page of 200 results would otherwise be 200 round trips against a table this database
// shares with the mail and the forum.
$favMark = [];
if (favEnabled($cfg) && $canMagnet && userCan($db, $cfg, 'favourites.use')) {
    $meFav = currentUser($db);
    if ($meFav) $favMark = favMarkFor($db, (int)$meFav['id'], array_column($res['rows'], 'info_hash'));
}

// "Last seen" in the READER's clock and the site's one short format (1.74.0, part D — UX-23): the script read the raw
// DATETIME as the browser's own time and wrote it in the browser's language ("10/05/2026, 04:00 PM" on a Polish page,
// two hours off). The column holds the database session's clock, which is PHP's offset (config/database.php).
$readerTz = function_exists('userDisplayTimezone') ? userDisplayTimezone(usersEnabled($cfg) ? currentUser($db) : null, $cfg) : null;
$dbZone = new DateTimeZone(date('P'));
$readerTime = static function ($dt) use ($readerTz, $dbZone): string {
    if ($readerTz === null || !is_string($dt) || $dt === '') return '';
    try { return (new DateTimeImmutable($dt, $dbZone))->setTimezone($readerTz)->format('Y-m-d H:i'); }
    catch (\Throwable $e) { return ''; }
};

$rows = [];
foreach ($res['rows'] as $r) {
    $row = [
        'name'     => $r['name'],
        'size'     => $r['total_size'],
        'seeders'  => $r['seeders'],
        'leechers' => $r['leechers'],
        'last_seen' => $r['last_seen'],
        'last_seen_time' => $readerTime($r['last_seen'] ?? null),
        'src'      => $r['src'],
    ];
    if ($canFiles) $row['files_count'] = $r['files_count'];
    if ($canMagnet) {
        $row['info_hash'] = $r['info_hash'];
        // Only sent when there is a star to paint: `fav` on a row the page cannot show a star for
        // would be a fact about the reader travelling for no reason.
        if ($favMark !== [] || (favEnabled($cfg) && $canMagnet)) $row['fav'] = isset($favMark[$r['info_hash']]);
    }
    // Only when the operator asked for it, and only above the threshold: a column showing "100%"
    // next to a single vote would be worse than no column.
    if ($repInResults) {
        // votes_count, not votes_up + votes_down: in star mode there is no "up" and no "down", and
        // adding two columns that mean nothing there would produce a confident zero.
        $cnt = (int)($r['votes_count'] ?? 0);
        $row['rep'] = $cnt >= $repMin
            ? ($repMode === 'stars'
                ? ['mode' => 'stars', 'stars' => round((int)($r['score_x100'] ?? 0) / 100, 1), 'total' => $cnt]
                : ['mode' => 'thumbs', 'pct' => (int)round((int)($r['score_x100'] ?? 0) / 100), 'total' => $cnt])
            : null;
    }
    $row['content_status'] = (string)($r['content_status'] ?? 'none');
    $rows[] = $row;
}

jsonResponse([
    'success' => true, 'rows' => $rows, 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'],
    // 1.74.0 (PERF-2): true when `total` is only how far the search counted — the page shows "N+".
    'total_capped' => !empty($res['total_capped']),
    'can' => ['files' => $canFiles, 'magnet' => $canMagnet, 'whitelist' => $canWl],
    'rep_in_results' => $repInResults,
]);
