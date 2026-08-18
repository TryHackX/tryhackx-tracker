<?php
// Banned hashes list (banned_hashes joined with the whitelist row, if any).

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($cfg['items_per_page'] ?? 25));
$offset = ($page - 1) * $perPage;

// Safe sort whitelist
$allowedSorts = [
    'hash'   => 'b.info_hash',
    'reason' => 'b.reason',
    'source' => 'b.source',
    'date'   => 'b.created_at',
];

// Multi-sort support: sort=source:asc,date:desc
$sortParam = trim($_GET['sort'] ?? 'date:desc');
$orderParts = [];
foreach (explode(',', $sortParam) as $part) {
    $pieces = explode(':', trim($part));
    $col = $allowedSorts[$pieces[0] ?? ''] ?? null;
    if (!$col) continue;
    $dir = (strtolower($pieces[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
    $orderParts[] = "$col $dir";
}
if (empty($orderParts)) {
    $orderParts[] = 'b.created_at DESC';
}
$orderParts[] = 'b.info_hash ASC'; // deterministic tie-break

// Build WHERE conditions
$where = [];
$params = [];

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    if (preg_match('/^[a-f0-9]{1,40}$/i', $search)) {
        $where[] = "b.info_hash LIKE ?";
        $params[] = strtolower($search) . '%';
    } else {
        $where[] = "(b.reason LIKE ? OR w.name LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from = "banned_hashes b LEFT JOIN whitelist w ON w.info_hash = b.info_hash";

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM $from $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

// Fetch rows
$orderClause = implode(', ', $orderParts);
$sql = "SELECT b.info_hash, b.reason, b.source, b.source_id, b.created_at, w.id AS whitelist_id, w.name
        FROM $from $whereClause ORDER BY $orderClause LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$paramIdx = 1;
foreach ($params as $p) {
    $stmt->bindValue($paramIdx++, $p, PDO::PARAM_STR);
}
$stmt->bindValue($paramIdx++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIdx, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    $row['source_id'] = $row['source_id'] !== null ? (int)$row['source_id'] : null;
    $row['whitelist_id'] = $row['whitelist_id'] !== null ? (int)$row['whitelist_id'] : null;
}
unset($row);

jsonResponse([
    'rows' => $rows,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
]);
