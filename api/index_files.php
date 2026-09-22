<?php
/**
 * GET index_files&hash=<40 hex>[&offset=n][&search=<term>] — file list of one catalogue entry for the
 * public search page. Gated exactly like the search itself PLUS index.files; whitelist entries
 * additionally need whitelist.view (the same visibility rule as index_search). Shares the search
 * rate-limit bucket. `search` (1.62.0, first page only) adds `matches`: the files that match the
 * term the list was opened from, so a capped page of a huge torrent still shows the one that hit.
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
if (!indexEnabled($cfg) || ($cfg['index_search_enabled'] ?? '1') !== '1') jsonResponse(['error' => 'search_disabled'], 400);
if (!userCan($db, $cfg, 'index.view') || !userCan($db, $cfg, 'index.files')) {
    jsonResponse(['error' => currentUser($db) ? 'no_permission' : 'login_required'], 403);
}
$perHour = (int)($cfg['rate_limit_index_search'] ?? 120);
if (!rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

// Past the permission checks and only reading from here on, so the session lock can go. See
// api/index_search.php for why this lives in the endpoint and not in the router.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$hash = strtolower(trim((string)($_GET['hash'] ?? '')));
if (!isValidInfoHash($hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

// Paged: the page asks for the next slice with offset=, and `truncated` says whether one exists.
// How big a slice is and how many of them one page may accumulate are operator settings since
// 1.38.0 (Settings → File list loading), clamped again here on read because that row can also come
// from a restored backup or a MySQL client on a database three other applications share. A caller's
// own ?limit= may still ask for LESS than the configured batch — never for more, which is what it
// could do while the number was a literal.
$batch    = indexFilesBatch($cfg);
$maxTotal = indexFilesMax($cfg);
$limit    = max(IDX_FILES_BATCH_MIN, min($batch, (int)($_GET['limit'] ?? $batch)));
$offset   = max(0, (int)($_GET['offset'] ?? 0));
// The first page comes with index.files; every page after it needs index.files_all. The reply says
// whether the caller may ask for more (`can_more`), so the page shows a button only where one works.
$canMore = userCan($db, $cfg, 'index.files_all');
if ($offset > 0 && !$canMore) jsonResponse(['error' => 'no_permission'], 403);
// Paging stays LIMIT/OFFSET. A keyset cursor was drafted and dropped: both gates on this endpoint —
// the 403 above and the ceiling below — are tests on $offset, so an `after=<id>` parameter would
// have walked the whole table with neither of them, on a URL anyone can type.
//
// The ceiling is enforced here rather than by the browser stopping politely, and `capped` never
// travels with `truncated`. The page's loop condition is `truncated && can_more` and its
// IntersectionObserver re-fires whenever the sentinel is on screen, so a capped reply that still
// claimed truncation would leave an open tab asking for the same empty page for ever — against the
// per-IP idxsearch bucket, whose accounting rewrites the whole of config/rate_limits.json under an
// exclusive lock on every single call. Tabs left open across a deploy still run the old app.js, so
// this has to be right on the wire and not only in the client.
//
// This answers before the row is looked up, so a hash that does not exist gets the same reply as
// one that does instead of the usual 404 — which tells the caller nothing, because at this depth
// both answers are identical. It also cannot be reached without index.files_all, checked above.
if ($offset >= $maxTotal) {
    jsonResponse(['success' => true, 'name' => null, 'files' => [], 'truncated' => false, 'capped' => true,
                  'offset' => $offset, 'next' => $offset, 'can_more' => $canMore,
                  'limit' => 0, 'max' => $maxTotal,
                  'files_count' => null, 'stored_total' => null, 'stored_short' => false]);
}
$limit = min($limit, $maxTotal - $offset);
$files = [];
$name = null;
// The torrent's own file count, which is NOT the length of the stored list: the worker records
// libtorrent's count but only ever writes the first max_files paths, so a big torrent has a
// 5 000-row list under an 18 000-file number. Both arms read it so the page can say which it has.
$filesCount = null;

// The ids of the page's rows, kept beside it and never sent: the one thing they are for is saying
// where this page ENDS, so the matching files below can be split into "already on the screen" and
// "beyond it" without a second walk of the list.
$fileIds = [];
// Which table the rows came from, for the matches below: 'index' + the hash, or 'whitelist' + the id.
$filesKind = '';
$filesOwner = null;

$st = $db->prepare("SELECT name, files_count FROM index_hashes WHERE info_hash = ?");
$st->execute([$hash]);
$idx = $st->fetch(PDO::FETCH_ASSOC);
if ($idx) {
    $name = $idx['name'];
    $filesCount = $idx['files_count'] !== null ? (int)$idx['files_count'] : null;
    $fs = $db->prepare("SELECT id, path, size FROM index_files WHERE info_hash = ? ORDER BY id LIMIT ? OFFSET ?");
    $fs->bindValue(1, $hash, PDO::PARAM_STR);
    $fs->bindValue(2, $limit + 1, PDO::PARAM_INT);
    $fs->bindValue(3, $offset, PDO::PARAM_INT);
    $fs->execute();
    foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
        $fileIds[] = (int)$f['id'];
    }
    $filesKind = 'index';
    $filesOwner = $hash;
} elseif (userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1') {
    $st = $db->prepare("SELECT id, name, files_count FROM whitelist WHERE info_hash = ? AND banned = 0");
    $st->execute([$hash]);
    $wl = $st->fetch(PDO::FETCH_ASSOC);
    if ($wl) {
        $name = $wl['name'];
        $filesCount = $wl['files_count'] !== null ? (int)$wl['files_count'] : null;
        $fs = $db->prepare("SELECT id, path, size FROM whitelist_files WHERE whitelist_id = ? ORDER BY id LIMIT ? OFFSET ?");
        $fs->bindValue(1, (int)$wl['id'], PDO::PARAM_INT);
        $fs->bindValue(2, $limit + 1, PDO::PARAM_INT);
        $fs->bindValue(3, $offset, PDO::PARAM_INT);
        $fs->execute();
        foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
            $fileIds[] = (int)$f['id'];
        }
        $filesKind = 'whitelist';
        $filesOwner = (int)$wl['id'];
    }
}
if ($name === null && !$files) jsonResponse(['error' => __('api.common.not_found')], 404);

$short = indexFilesShortfall($filesCount, count($files), $limit, $offset);
$truncated = $short['truncated'];
if ($truncated) { $files = array_slice($files, 0, $limit); $fileIds = array_slice($fileIds, 0, $limit); }

// ── the files that made this a hit (1.62.0) ─────────────────────────────────────────────────────
//
// A list opened from a search with "Also search file names" on names the term it came from, and the
// FIRST page answers with the files that match it beside the ordinary page — up to
// INDEX_FILE_LIST_MATCHES of them, the ones beyond the page first. A torrent with thousands of files
// shows a capped page, and the file somebody searched for is very often not on it: the page could not
// show the one thing the reader came to see. The ordinary page is unchanged, and so is every rule
// above about how much of it may be loaded; this is a small appendix the page merges into the tree
// it already draws (assets/js/app.js), skipping anything it already has.
//
// Asked only on the first page, because that is the page the tree is first drawn from, and matched
// with the search's own clause (indexFilePathClause()), so the list and the search cannot disagree
// about which file matched. The term is bounded exactly as api/index_search.php bounds it.
$matchTerm = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200);
$matches = null;
if ($matchTerm !== '' && $offset === 0 && $filesKind !== '') {
    $pageLast = $fileIds ? (int)end($fileIds) : 0;
    $matches = indexFileListMatches($db, $filesKind, $filesOwner, $matchTerm, $pageLast);
}
// Rows are waiting and this page ends on the operator's total: the list stops here, and it stops for
// a reason worth saying out loud. `capped` REPLACES `truncated` — see the note above the ceiling.
$capped = indexFilesCapped($truncated, $offset, count($files), $maxTotal);
if ($capped) $truncated = false;
// `truncated` says another page exists; `stored_total` is filled in only on the page that ENDS the
// stored list, and `stored_short` says that end came before the torrent's own count. Without those
// two the page could not tell "ask again" apart from "this is everything that was ever written",
// and with index.files_all granted it silently stopped at 5 000 under an 18 000 heading. A capped
// page ends neither of those ways — it was cut by this site, and it may not claim the stored list
// ends where the ceiling happens to fall. An EMPTY page past the start says nothing either: the
// offset came from the caller, nothing bounds it, and `offset + 0` is not a measurement of what
// was stored.
$blind = $capped || $truncated || (!$files && $offset > 0);
$out = ['success' => true, 'name' => $name, 'files' => $files, 'truncated' => $truncated,
        'capped' => $capped, 'offset' => $offset, 'next' => $offset + count($files), 'can_more' => $canMore,
        'limit' => $limit, 'max' => $maxTotal,
        'files_count' => $filesCount,
        'stored_total' => $blind ? null : $short['stored'],
        'stored_short' => !$blind && $short['short']];
// Only when a term was asked about: an ordinary list's reply stays exactly what it was.
if ($matches !== null) {
    $out['matches'] = $matches['rows'];
    $out['matches_more'] = $matches['more'];
}
jsonResponse($out);
