<?php
// Observed-hash index list: pagination, sorting, hash/name/file search, meta filter.
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($cfg['items_per_page'] ?? 25));
$offset = ($page - 1) * $perPage;

$allowedSorts = [
    'hash'      => 'info_hash', 'name' => 'name', 'size' => 'total_size',
    'seeders'   => 'last_seeders', 'leechers' => 'last_leechers', 'seen' => 'seen_count',
    'first'     => 'first_seen', 'last' => 'last_seen', 'meta' => 'meta_status',
    'sseeders'  => 'scrape_seeders', 'peak' => 'peak_seeders',
];
$orderParts = [];
foreach (explode(',', trim($_GET['sort'] ?? 'last:desc')) as $part) {
    $pieces = explode(':', trim($part));
    $col = $allowedSorts[$pieces[0] ?? ''] ?? null;
    if (!$col) continue;
    $dir = (strtolower($pieces[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
    $orderParts[] = "$col $dir";
}
if (!$orderParts) $orderParts[] = 'last_seen DESC';
$orderParts[] = 'info_hash ASC'; // deterministic tie-break

function indexFulltextTerm(string $term): string {
    $clean = preg_replace('/[+\-><()~"@*]/u', ' ', $term) ?? '';
    $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($words as $w) { if (mb_strlen($w) >= 2) $out[] = $w . '*'; }
    return implode(' ', $out);
}

$where = [];
$params = [];
$search = trim($_GET['search'] ?? '');
$searchFiles = ($_GET['search_files'] ?? '') === '1';
$fulltextClause = null; $likeClause = null;
if ($search !== '') {
    if (preg_match('/^[a-f0-9]{6,40}$/i', $search)) {
        $where[] = "info_hash LIKE ?";
        $params[] = strtolower($search) . '%';
    } else {
        $likeClause = ['sql' => "name LIKE ?", 'params' => ['%' . $search . '%']];
        if ($searchFiles) $likeClause = ['sql' => "(name LIKE ? OR info_hash IN (SELECT info_hash FROM index_files WHERE path LIKE ?))", 'params' => ['%' . $search . '%', '%' . $search . '%']];
        $ft = mb_strlen($search) >= 3 ? indexFulltextTerm($search) : '';
        if ($ft !== '') {
            $fulltextClause = ['sql' => "MATCH(name) AGAINST(? IN BOOLEAN MODE)", 'params' => [$ft]];
            if ($searchFiles) $fulltextClause = ['sql' => "(MATCH(name) AGAINST(? IN BOOLEAN MODE) OR info_hash IN (SELECT info_hash FROM index_files WHERE MATCH(path) AGAINST(? IN BOOLEAN MODE)))", 'params' => [$ft, $ft]];
        }
    }
}
$metaFilter = $_GET['meta'] ?? '';
if (in_array($metaFilter, ['none', 'pending', 'fetching', 'done', 'failed'], true)) { $where[] = "meta_status = ?"; $params[] = $metaFilter; }
$lifeFilter = $_GET['life'] ?? '';
if ($lifeFilter === 'protected') $where[] = "protected_until IS NOT NULL AND protected_until >= NOW()";
elseif ($lifeFilter === 'grace')  $where[] = "meta_status <> 'done' AND grace_until >= NOW()";
elseif ($lifeFilter === 'promoted') $where[] = "promoted_at IS NOT NULL";

$columns = "info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers, last_completed, peak_seeders,
            grace_until, protected_until, promoted_at, meta_status, meta_error, total_size, files_count,
            scrape_seeders, scrape_leechers, scrape_completed, scraped_at";
$orderClause = implode(', ', $orderParts);

$runQuery = function (?array $extra) use ($db, $where, $params, $columns, $orderClause, $perPage, $offset): array {
    $w = $where; $p = $params;
    if ($extra) { $w[] = $extra['sql']; $p = array_merge($p, $extra['params']); }
    $whereClause = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $countStmt = $db->prepare("SELECT COUNT(*) FROM index_hashes $whereClause");
    $countStmt->execute($p);
    $total = (int)$countStmt->fetchColumn();
    $stmt = $db->prepare("SELECT $columns FROM index_hashes $whereClause ORDER BY $orderClause LIMIT ? OFFSET ?");
    $i = 1;
    foreach ($p as $v) $stmt->bindValue($i++, $v, PDO::PARAM_STR);
    $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($i, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return [$total, $stmt->fetchAll()];
};

$total = 0; $rows = [];
if ($fulltextClause) {
    try { [$total, $rows] = $runQuery($fulltextClause); }
    catch (\Throwable $e) { [$total, $rows] = $runQuery($likeClause); }
} else {
    [$total, $rows] = $runQuery($likeClause);
}
$pages = max(1, (int)ceil($total / $perPage));

$now = time();
foreach ($rows as &$row) {
    foreach (['seen_count', 'last_seeders', 'last_leechers', 'last_completed', 'peak_seeders', 'total_size', 'files_count', 'scrape_seeders', 'scrape_leechers', 'scrape_completed'] as $k) {
        $row[$k] = $row[$k] !== null ? (int)$row[$k] : null;
    }
    $row['protected'] = ($row['protected_until'] !== null && strtotime($row['protected_until']) >= $now);
    $row['promoted'] = $row['promoted_at'] !== null;
}
unset($row);

$counts = ['total' => 0, 'protected' => 0, 'pending_meta' => 0];
try {
    $counts['total'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn();
    $counts['protected'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE protected_until IS NOT NULL AND protected_until >= NOW()")->fetchColumn();
    $counts['pending_meta'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status IN ('pending','fetching')")->fetchColumn();
} catch (\Throwable $e) {}

jsonResponse(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts, 'enabled' => indexEnabled($cfg)]);
