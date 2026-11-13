<?php
// Determine source table (active reports or archives)
$source = ($_GET['source'] ?? 'reports') === 'archives' ? 'archives' : 'reports';

// Single report by ID
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT r.*, c.label AS api_client_label FROM `$source` r
                          LEFT JOIN api_clients c ON c.id = r.api_client_id WHERE r.id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch();
    jsonResponse(['report' => $report ?: null]);
}

// List with pagination, sorting, search, and filters
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($cfg['items_per_page'] ?? 25);
$offset = ($page - 1) * $perPage;

// Safe sort whitelist
// Table-qualified, because the list query below joins api_clients to name the partner that filed
// the report — and `id` exists on both sides. The count query does not join and does not sort, so
// its WHERE keeps using bare column names; none of those exist on both tables.
$allowedSorts = [
    'date'           => 'r.timestamp',
    'checked'        => 'r.checked',
    'blocked'        => '(CASE WHEN r.blocked = 1 THEN 2 WHEN r.checked = 1 THEN 1 ELSE 0 END)',
    'id'             => 'r.id',
    'name'           => 'r.name',
    'company'        => 'r.company',
    'email'          => 'r.email',
    'representative' => 'r.representative',
    'object'         => 'r.objectTitle',
    'hash'           => 'r.infoHash',
    'ip'             => 'r.ip',
];

// Multi-sort support: sort=name:asc,company:desc,date:desc
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
    $orderParts[] = 'r.timestamp DESC';
}

// Build WHERE conditions
$where = [];
$params = [];

// Search
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $where[] = "(name LIKE ? OR company LIKE ? OR email LIKE ? OR infoHash LIKE ? OR objectTitle LIKE ? OR representative LIKE ? OR link LIKE ? OR magnet_link LIKE ? OR ip LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

// Status filter
$status = $_GET['status'] ?? 'all';
switch ($status) {
    case 'pending':
        $where[] = "checked = 0 AND blocked = 0";
        break;
    case 'reviewed':
        $where[] = "checked = 1 AND blocked = 0";
        break;
    case 'blocked':
        $where[] = "blocked = 1";
        break;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$countSql = "SELECT COUNT(*) FROM `$source` $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

// Fetch reports
$orderClause = implode(', ', $orderParts);
// The partner's LABEL, not just their id: a queue that says "api_client_id 3" tells the person
// working it nothing, and looking the number up is their job for as long as it is not done here.
// LEFT JOIN, because almost every report ever filed came from the public form and has no partner.
$sql = "SELECT r.*, c.label AS api_client_label FROM `$source` r
        LEFT JOIN api_clients c ON c.id = r.api_client_id
        $whereClause ORDER BY $orderClause LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$paramIdx = 1;
foreach ($params as $p) {
    $stmt->bindValue($paramIdx++, $p, PDO::PARAM_STR);
}
$stmt->bindValue($paramIdx++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIdx, $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll();

// Pending counts for badges
$pendingReports = (int)$db->query("SELECT COUNT(*) FROM reports WHERE checked = 0 AND blocked = 0")->fetchColumn();
$pendingArchives = (int)$db->query("SELECT COUNT(*) FROM archives WHERE checked = 0 AND blocked = 0")->fetchColumn();

jsonResponse([
    'reports' => $reports,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'source' => $source,
    'pending_reports' => $pendingReports,
    'pending_archives' => $pendingArchives,
]);
