<?php
$appealSource = ($_GET['source'] ?? 'appeals') === 'archives' ? 'appeal_archives' : 'appeals';

// Single appeal by ID
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM `$appealSource` WHERE id = ?");
    $stmt->execute([$id]);
    $appeal = $stmt->fetch();
    jsonResponse(['appeal' => $appeal ?: null]);
}

// List with pagination, sorting, search, and filters
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($cfg['items_per_page'] ?? 25);
$offset = ($page - 1) * $perPage;

$allowedSorts = [
    'date'   => 'timestamp',
    'id'     => 'id',
    'name'   => 'name',
    'email'  => 'email',
    'hash'   => 'infoHash',
    'status' => 'status',
    'report' => 'report_id',
    'type'   => 'appeal_type',
    'ip'     => 'ip',
];

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
    $orderParts[] = 'timestamp DESC';
}

$where = [];
$params = [];

// Search
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR infoHash LIKE ? OR message LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

// Status filter
$status = $_GET['status'] ?? 'all';
if ($status !== 'all' && in_array($status, ['pending', 'reviewed', 'accepted', 'rejected'], true)) {
    $where[] = "status = ?";
    $params[] = $status;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM `$appealSource` $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

// Pending count (always return for badge — from active appeals only)
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM appeals WHERE status = 'pending'")->fetchColumn();

// Fetch appeals
$orderClause = implode(', ', $orderParts);
$sql = "SELECT * FROM `$appealSource` $whereClause ORDER BY $orderClause LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$paramIdx = 1;
foreach ($params as $p) {
    $stmt->bindValue($paramIdx++, $p, PDO::PARAM_STR);
}
$stmt->bindValue($paramIdx++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIdx, $offset, PDO::PARAM_INT);
$stmt->execute();
$appeals = $stmt->fetchAll();

jsonResponse([
    'appeals' => $appeals,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'pending_count' => $pendingCount,
]);
