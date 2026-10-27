<?php
// Observed-hash index list: pagination, sorting, hash/name/file search, meta filter.
// The query builder lives in includes/index.php (indexListSelect) — shared with the public search.
$perPage = (int)($_GET['per_page'] ?? 0);
if (!in_array($perPage, [15, 25, 50, 100, 200], true)) $perPage = (int)($cfg['items_per_page'] ?? 25);
try {
    $res = indexListSelect($db, $cfg, [
        'page'         => $_GET['page'] ?? 1,
        'per_page'     => $perPage,
        'sort'         => $_GET['sort'] ?? 'last:desc',
        'search'       => $_GET['search'] ?? '',
        'search_files' => ($_GET['search_files'] ?? '') === '1',
        'meta'         => $_GET['meta'] ?? '',
        'life'         => $_GET['life'] ?? '',
        'desc'         => $_GET['desc'] ?? '',
        'desc_flags'   => true,
    ]);
} catch (\Throwable $e) {
    if (!$e instanceof IndexSearchTimeout && !indexIsStatementTimeout($e)) throw $e;
    error_log('[admin] fetch_index: stopped for time: ' . mb_substr((string)($_GET['search'] ?? ''), 0, 80) . ' sort=' . (string)($_GET['sort'] ?? '') . ' — ' . $e->getMessage());
    header('Retry-After: 30');
    jsonResponse(['success' => false, 'error' => __('api.search.timeout'), 'code' => 'search_timeout'], 503);
}

$counts = ['total' => 0, 'protected' => 0, 'pending_meta' => 0];
try {
    $c = indexSharedCounts($db, 'counts', 'indexCountsCompute')[0];
    $counts = ['total' => (int)$c['total'], 'protected' => (int)$c['protected'],
               'pending_meta' => (int)$c['meta_pending'] + (int)$c['meta_fetching']];
} catch (\Throwable $e) {
    // Unknown, not zero (1.74.0, QUAL-26): full-table counts that can run into max_statement_time on a big index must
    // not take the list with them, so the rows go out and the counts say null — and the log says why.
    error_log('[admin] fetch_index: the list counts failed: ' . $e->getMessage());
    $counts = ['total' => null, 'protected' => null, 'pending_meta' => null];
}

jsonResponse(['rows' => $res['rows'], 'total' => $res['total'], 'total_capped' => !empty($res['total_capped']), 'page' => $res['page'], 'pages' => $res['pages'],
              'counts' => $counts, 'enabled' => indexEnabled($cfg)]);
