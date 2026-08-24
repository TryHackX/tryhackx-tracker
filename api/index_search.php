<?php
/**
 * GET index_search — the user-facing search over the observed-hash index (?action=search page).
 * Gated by the `index.view` permission (users system): anonymous visitors get it only if the
 * admin granted it to the `guest` group. `index.files` gates file-name search + file counts,
 * `index.magnet` gates the info hash (and with it the magnet link the client builds).
 * Rate limited per IP bucket; only resolved rows (meta done) are searchable.
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
if (!indexEnabled($cfg)) jsonResponse(['error' => 'search_disabled'], 400);
if (!userCan($db, $cfg, 'index.view')) {
    jsonResponse(['error' => currentUser($db) ? 'no_permission' : 'login_required'], 403);
}

$perHour = (int)($cfg['rate_limit_index_search'] ?? 120);
if (!rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$canFiles = userCan($db, $cfg, 'index.files');
$canMagnet = userCan($db, $cfg, 'index.magnet');

// user-facing sort keys are a safe subset of the admin ones
$sort = (string)($_GET['sort'] ?? 'seeders:desc');
if (!preg_match('/^(seeders|size|last|name|seen):(asc|desc)$/', $sort)) $sort = 'seeders:desc';

$res = indexListSelect($db, $cfg, [
    'page'         => $_GET['page'] ?? 1,
    'per_page'     => 25,
    'sort'         => $sort,
    'search'       => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200),
    'search_files' => $canFiles && ($_GET['search_files'] ?? '') === '1',
    'meta'         => 'done',   // only resolved rows are useful to a searcher
]);

$rows = [];
foreach ($res['rows'] as $r) {
    $row = [
        'name'    => $r['name'],
        'size'    => $r['total_size'],
        'seeders'  => $r['scrape_seeders'] !== null ? $r['scrape_seeders'] : $r['last_seeders'],
        'leechers' => $r['scrape_leechers'] !== null ? $r['scrape_leechers'] : $r['last_leechers'],
        'last_seen' => $r['last_seen'],
    ];
    if ($canFiles) $row['files_count'] = $r['files_count'];
    if ($canMagnet) $row['info_hash'] = $r['info_hash'];
    $rows[] = $row;
}

jsonResponse([
    'success' => true, 'rows' => $rows, 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'],
    'can' => ['files' => $canFiles, 'magnet' => $canMagnet],
]);
