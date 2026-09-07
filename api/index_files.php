<?php
/**
 * GET index_files&hash=<40 hex> — file list of one catalogue entry for the public search page.
 * Gated exactly like the search itself PLUS index.files; whitelist entries additionally need
 * whitelist.view (the same visibility rule as index_search). Shares the search rate-limit bucket.
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
// 2000 per page keeps a reply under a few hundred KB; the client keeps loading while the reader
// scrolls, so a 40 000-file torrent is readable without one 5 MB answer.
$limit  = max(100, min(5000, (int)($_GET['limit'] ?? 2000)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$files = [];
$name = null;

$st = $db->prepare("SELECT name FROM index_hashes WHERE info_hash = ?");
$st->execute([$hash]);
$idx = $st->fetch(PDO::FETCH_ASSOC);
if ($idx) {
    $name = $idx['name'];
    $fs = $db->prepare("SELECT path, size FROM index_files WHERE info_hash = ? ORDER BY id LIMIT ? OFFSET ?");
    $fs->bindValue(1, $hash, PDO::PARAM_STR);
    $fs->bindValue(2, $limit + 1, PDO::PARAM_INT);
    $fs->bindValue(3, $offset, PDO::PARAM_INT);
    $fs->execute();
    foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
} elseif (userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1') {
    $st = $db->prepare("SELECT id, name FROM whitelist WHERE info_hash = ? AND banned = 0");
    $st->execute([$hash]);
    $wl = $st->fetch(PDO::FETCH_ASSOC);
    if ($wl) {
        $name = $wl['name'];
        $fs = $db->prepare("SELECT path, size FROM whitelist_files WHERE whitelist_id = ? ORDER BY id LIMIT ? OFFSET ?");
        $fs->bindValue(1, (int)$wl['id'], PDO::PARAM_INT);
        $fs->bindValue(2, $limit + 1, PDO::PARAM_INT);
        $fs->bindValue(3, $offset, PDO::PARAM_INT);
        $fs->execute();
        foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
    }
}
if ($name === null && !$files) jsonResponse(['error' => __('api.common.not_found')], 404);

$truncated = count($files) > $limit;
if ($truncated) $files = array_slice($files, 0, $limit);
jsonResponse(['success' => true, 'name' => $name, 'files' => $files, 'truncated' => $truncated,
              'offset' => $offset, 'next' => $offset + count($files)]);
