<?php
/**
 * GET index_info&hash=… — everything the public Info panel shows about one hash.
 *
 * Read-only, and gated by the same permission as the search itself: a hash somebody cannot find is a
 * hash they cannot ask about either.
 *
 * The description arrives already rendered. The renderer lives on the server for a reason — it is
 * the only place that can guarantee what comes out — and shipping the raw text to the browser to be
 * turned into HTML there would move that guarantee to the least trustworthy place in the system.
 *
 * POST with {op:"refresh"} scrapes this one hash live, when the operator has allowed it. That is a
 * button which turns a stranger's click into a request to the tracker, so it is off by default and
 * rate-limited per hash across everybody — otherwise it is a load generator with a nice icon.
 */
if (!indexEnabled($cfg) || ($cfg['index_search_enabled'] ?? '1') !== '1') {
    jsonResponse(['error' => __('api.index.search_unavailable')], 404);
}
if (!userCan($db, $cfg, 'index.view')) {
    jsonResponse(['error' => __('api.index.search_access_required')], 403);
}

$hash = strtolower(trim((string)($_GET['hash'] ?? '')));

// GET only: the POST branch below verifies a CSRF token, which needs the session open.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();   // see api/index_search.php for why this is here and not in the router
}
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

/** Everything about this hash from both tables, whichever has it. */
$loadRow = function () use ($db, $hash): array {
    $out = ['index' => null, 'whitelist' => null];
    $st = $db->prepare(
        "SELECT info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers,
                last_completed, peak_seeders, meta_status, total_size, files_count, promoted_at
           FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $out['index'] = $st->fetch() ?: null;

    // AND banned = 0, the same filter api/index_files.php:80 uses. A banned hash is deleted out of
    // index_hashes on the next poll (includes/index.php), so for such a hash the banned whitelist
    // row is the ONLY row there is — and without this filter it was the whole answer: name, size,
    // file count and swarm counts for a torrent this tracker refuses to serve and the search will
    // not list. The two `!(int)$wl['banned']` guards further down are now belt as well as braces —
    // they are kept, and `banned` with them, so that gate does not depend on a WHERE clause fifty
    // lines away that a later edit could relax.
    $st = $db->prepare(
        "SELECT info_hash, name, created_at, total_size, files_count, scrape_seeders, scrape_leechers,
                scrape_completed, scraped_at, source_url, description, description_format,
                content_status, banned, source, source_ref
           FROM whitelist WHERE info_hash = ? AND banned = 0 LIMIT 1");
    $st->execute([$hash]);
    $out['whitelist'] = $st->fetch() ?: null;
    return $out;
};

// WHICH ROWS EXIST FOR THIS CALLER — DECIDED ONCE, ABOVE BOTH METHODS.
//
// This used to live below the POST branch, so the refresh arm never asked the question at all: it
// answered 200 with live seeders and leechers for a hash whose GET answered 404, and it ran
// `UPDATE whitelist SET scrape_* …` against a row the caller was not allowed to read. A write with
// no read permission, on columns that ARE load-bearing for the readers who do have it —
// scrape_seeders is the whitelist arm's sort key and scraped_at is its last_seen
// (the whitelist arm's column list in includes/index.php).
//
// A whitelisted hash is removed from index_hashes, so for such a hash $idx is null and $wl is the
// only row there is. `whitelist.view` (with index_search_include_whitelist) is what decides whether
// a reader may see those rows at all: indexSearchCatalogue() leaves them out of the results and
// api/index_files.php refuses the file list. Gating only the `whitelisted` flag, as this endpoint
// did, served the row's name, size, file count, swarm counts, source link and description to
// anybody who could type the hash. Dropping the row makes every answer agree: not in the results,
// no file list, no refresh, and the same 404 as a hash nobody has ever seen.
$canWl = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';
$row = $loadRow();
$idx = $row['index'];
$wl  = $canWl ? $row['whitelist'] : null;
if (!$idx && !$wl) jsonResponse(['error' => __('api.index.not_found_dot')], 404);

// ── the live refresh ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if ((string)($input['op'] ?? '') !== 'refresh') jsonResponse(['error' => __('api.index.unknown_op')], 400);
    if (($cfg['search_allow_sl_refresh'] ?? '0') !== '1') {
        jsonResponse(['error' => __('api.index.refresh_disabled')], 403);
    }
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
    // Per hash, across every visitor. A per-session limit would be no limit at all.
    $cool = max(10, min(3600, (int)($cfg['search_sl_refresh_seconds'] ?? 120) ?: 120));
    $stamp = sys_get_temp_dir() . '/idx_sl_' . $hash . '.stamp';
    $age = is_file($stamp) ? (time() - (int)@filemtime($stamp)) : PHP_INT_MAX;
    if ($age < $cool) {
        jsonResponse(['error' => __('api.index.just_refreshed', ['s' => $cool - $age]),
                      'retry_after' => $cool - $age], 429);
    }
    @touch($stamp);
    // force = true: the caller has already paid the cooldown above, and a cached answer is exactly
    // what they pressed the button to avoid.
    $sl = scrapeOpenTracker($db, $cfg, ['info_hash' => $hash], true);
    if (!is_array($sl)) jsonResponse(['error' => __('api.index.tracker_no_answer_2')], 502);
    $db->prepare("UPDATE index_hashes SET last_seeders = ?, last_leechers = ?, last_completed = ?,
                         peak_seeders = GREATEST(peak_seeders, ?) WHERE info_hash = ?")
       ->execute([(int)$sl['seeders'], (int)$sl['leechers'], (int)$sl['completed'], (int)$sl['seeders'], $hash]);
    // Only the row this caller may see. $wl is null when they lack whitelist.view, when the search
    // is configured not to include whitelist rows, and when the row is banned — in all three cases
    // the numbers they just fetched have no business being written into it.
    if ($wl) {
        $db->prepare("UPDATE whitelist SET scrape_seeders = ?, scrape_leechers = ?, scrape_completed = ?,
                             scraped_at = NOW() WHERE info_hash = ?")
           ->execute([(int)$sl['seeders'], (int)$sl['leechers'], (int)$sl['completed'], $hash]);
    }
    jsonResponse(['success' => true, 'seeders' => (int)$sl['seeders'],
                  'leechers' => (int)$sl['leechers'], 'completed' => (int)$sl['completed'],
                  'message' => __('api.index.refreshed')]);
}


// The link and the description belong to the whitelist row, and only once approved. A pending one is
// text nobody has looked at yet; a rejected one is text somebody decided against. Neither is public.
$sourceUrl = null;
$sourceAuto = false;
$descHtml  = '';
if ($wl && !(int)$wl['banned'] && ($wl['content_status'] ?? 'none') === 'approved') {
    $sourceUrl = $wl['source_url'] ?: null;
    $descHtml  = richtextRender($wl['description'] ?? '', (string)$wl['description_format'], $cfg,
                                richtextViewerSignedIn($db));
}

// A link the IMPORTER recorded (whitelist.source_ref, written by api/v1/whitelist_submit.php when the
// forum posts a magnet) belongs in the same row as one somebody typed into the form — it answers the
// same question, and hiding it in the admin table only meant visitors could not see where a torrent
// came from. It does NOT get the same trust: it never passed the review queue, so it is published
// only when richtextIsTrusted() says it points at this operator's own site. An importer run by
// somebody else can still record a link; that one stays admin-only until a moderator approves it as
// a normal source link. The form-supplied link always wins when both exist.
if ($sourceUrl === null && $wl && !(int)$wl['banned']) {
    $auto = richtextAutoSourceUrl($wl['source_ref'] ?? null, $cfg);
    if ($auto !== null) { $sourceUrl = $auto; $sourceAuto = true; }
}

jsonResponse([
    'success'   => true,
    'info_hash' => $hash,
    'name'      => $idx['name'] ?? ($wl['name'] ?? null),
    'whitelisted' => $canWl ? (bool)$wl : null,
    // The panel always has the hash, so the star is available here even where the row could not
    // carry one (api/index_search.php sends info_hash only with index.magnet).
    'fav'         => (favEnabled($cfg) && ($meFav = currentUser($db)) && userCan($db, $cfg, 'favourites.use'))
                     ? favHas($db, (int)$meFav['id'], $hash) : null,
    'source_url'  => $sourceUrl,
    'source_trusted' => $sourceUrl ? richtextIsTrusted($sourceUrl, $cfg) : false,
    'source_auto'    => $sourceAuto,
    'source_auto_note' => $sourceAuto
        ? ((($wl['source'] ?? '') === 'forum') ? __('api.index.source_auto_forum') : __('api.index.source_auto_importer'))
        : null,
    'description_html' => $descHtml,
    'stats' => [
        'first_seen'  => $idx['first_seen'] ?? ($wl['created_at'] ?? null),
        'last_seen'   => $idx['last_seen'] ?? null,
        'seen_count'  => $idx ? (int)$idx['seen_count'] : null,
        'seeders'     => $idx ? (int)$idx['last_seeders'] : ($wl ? (int)$wl['scrape_seeders'] : null),
        'leechers'    => $idx ? (int)$idx['last_leechers'] : ($wl ? (int)$wl['scrape_leechers'] : null),
        'completed'   => $idx ? (int)$idx['last_completed'] : ($wl ? (int)$wl['scrape_completed'] : null),
        'peak_seeders' => $idx ? (int)$idx['peak_seeders'] : null,
        'total_size'  => $idx['total_size'] ?? ($wl['total_size'] ?? null),
        'files_count' => $idx['files_count'] ?? ($wl['files_count'] ?? null),
        'scraped_at'  => $wl['scraped_at'] ?? null,
    ],
    // Ratings ride along with the rest of the panel: one request, not two, because the button and
    // the numbers appear together and a second round trip would show one before the other.
    'rating' => repEnabled($cfg) ? repFor($db, $cfg, $hash) : null,
    'my_vote' => repEnabled($cfg) ? repMyVote($db, $cfg, $hash) : 0,
    'can_vote' => repEnabled($cfg) && repVoteRefusal($db, $cfg) === null,
    'vote_refusal' => repEnabled($cfg) ? repVoteRefusal($db, $cfg) : null,
    'can_refresh' => ($cfg['search_allow_sl_refresh'] ?? '0') === '1',
    'can_files'   => userCan($db, $cfg, 'index.files'),
]);
