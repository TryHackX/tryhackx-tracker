<?php
// Observed-hash index list: pagination, sorting, hash/name/file search, meta filter.
// The query builder lives in includes/index.php (indexListSelect) — shared with the public search.
$perPage = (int)($_GET['per_page'] ?? 0);
if (!in_array($perPage, [15, 25, 50, 100, 200], true)) $perPage = (int)($cfg['items_per_page'] ?? 25);
$res = indexListSelect($db, $cfg, [
    'page'         => $_GET['page'] ?? 1,
    'per_page'     => $perPage,
    'sort'         => $_GET['sort'] ?? 'last:desc',
    'search'       => $_GET['search'] ?? '',
    'search_files' => ($_GET['search_files'] ?? '') === '1',
    'meta'         => $_GET['meta'] ?? '',
    'life'         => $_GET['life'] ?? '',
]);

$counts = ['total' => 0, 'protected' => 0, 'pending_meta' => 0];
try {
    $counts['total'] = indexTotalCached($db);
    $counts['protected'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE protected_until IS NOT NULL AND protected_until >= NOW()")->fetchColumn();
    $counts['pending_meta'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status IN ('pending','fetching')")->fetchColumn();
} catch (\Throwable $e) {}

jsonResponse(['rows' => $res['rows'], 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'counts' => $counts, 'enabled' => indexEnabled($cfg)]);
