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

$perHour = (int)($cfg['rate_limit_index_search'] ?? 120);
if (!rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$canFiles = userCan($db, $cfg, 'index.files');
$canMagnet = userCan($db, $cfg, 'index.magnet');
$canWl = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';

// comma-separated multi-sort stack; unknown keys are dropped by indexSearchCatalogue
$sort = (string)($_GET['sort'] ?? 'relevance:desc');
if (!preg_match('/^(relevance|seeders|leechers|size|last|name|files)(:(asc|desc))?(,(relevance|seeders|leechers|size|last|name|files)(:(asc|desc))?)*$/', $sort)) {
    $sort = 'relevance:desc';
}

$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [15, 25, 50, 100, 200], true)) $perPage = 25;

$res = indexSearchCatalogue($db, $cfg, [
    'page'              => $_GET['page'] ?? 1,
    'per_page'          => $perPage,
    'sort'              => $sort,
    'search'            => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200),
    'search_files'      => $canFiles && ($_GET['search_files'] ?? '') === '1',
    'include_whitelist' => $canWl,
]);

$rows = [];
foreach ($res['rows'] as $r) {
    $row = [
        'name'     => $r['name'],
        'size'     => $r['total_size'],
        'seeders'  => $r['seeders'],
        'leechers' => $r['leechers'],
        'last_seen' => $r['last_seen'],
        'src'      => $r['src'],
    ];
    if ($canFiles) $row['files_count'] = $r['files_count'];
    if ($canMagnet) $row['info_hash'] = $r['info_hash'];
    $rows[] = $row;
}

jsonResponse([
    'success' => true, 'rows' => $rows, 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'],
    'can' => ['files' => $canFiles, 'magnet' => $canMagnet, 'whitelist' => $canWl],
]);
