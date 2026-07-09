<?php
if (($cfg['transparency_enabled'] ?? '0') !== '1') {
    jsonResponse(['error' => 'Transparency page is disabled'], 403);
}

$perPage = max(10, (int)($cfg['transparency_per_page'] ?? 150));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Multi-sort support: sort=total:desc,company:asc
// Company and representative are mutually exclusive in sort stack
$allowedTransSorts = [
    'total'          => 'total_requests',
    'company'        => 'company',
    'representative' => 'representative',
    'accepted'       => 'accepted',
    'blocked'        => 'blocked',
    'pending'        => 'pending',
];
$sortParam = trim($_GET['sort'] ?? 'total:desc');
$orderParts = [];
foreach (explode(',', $sortParam) as $part) {
    $pieces = explode(':', trim($part));
    $col = $allowedTransSorts[$pieces[0] ?? ''] ?? null;
    if (!$col) continue;
    $dir = (strtolower($pieces[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
    $orderParts[] = "$col $dir";
}
if (empty($orderParts)) {
    $orderParts[] = 'total_requests DESC';
}
$orderBy = implode(', ', $orderParts);

// Count distinct company+representative combinations across both tables
$totalGroups = (int)$db->query("SELECT COUNT(*) FROM (
    SELECT company, representative FROM reports GROUP BY company, representative
    UNION
    SELECT company, representative FROM archives GROUP BY company, representative
) AS combined")->fetchColumn();
$totalPages = max(1, (int)ceil($totalGroups / $perPage));

// Fetch paginated, aggregated data using an optimized SQL UNION query
$sql = "SELECT 
    company, 
    representative, 
    SUM(total_requests) AS total_requests,
    SUM(accepted) AS accepted,
    SUM(blocked) AS blocked,
    SUM(pending) AS pending
FROM (
    SELECT company, representative, COUNT(*) AS total_requests,
           SUM(CASE WHEN checked = 1 AND blocked = 0 THEN 1 ELSE 0 END) AS accepted,
           SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) AS blocked,
           SUM(CASE WHEN checked = 0 AND blocked = 0 THEN 1 ELSE 0 END) AS pending
    FROM reports GROUP BY company, representative
    UNION ALL
    SELECT company, representative, COUNT(*) AS total_requests,
           SUM(CASE WHEN checked = 1 AND blocked = 0 THEN 1 ELSE 0 END) AS accepted,
           SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) AS blocked,
           0 AS pending
    FROM archives GROUP BY company, representative
) AS combined
GROUP BY company, representative
ORDER BY $orderBy
LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$pagedData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cast database outputs to correct types for consistent JSON schema
foreach ($pagedData as &$row) {
    $row['total_requests'] = (int)$row['total_requests'];
    $row['accepted'] = (int)$row['accepted'];
    $row['blocked'] = (int)$row['blocked'];
    $row['pending'] = (int)$row['pending'];
}
unset($row);

// Aggregate totals across active reports
$agg = $db->query("SELECT COUNT(*) AS total_requests,
    SUM(CASE WHEN checked = 1 AND blocked = 0 THEN 1 ELSE 0 END) AS total_reviewed,
    SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) AS total_blocked,
    SUM(CASE WHEN checked = 0 AND blocked = 0 THEN 1 ELSE 0 END) AS total_pending
FROM reports")->fetch();

// Aggregate totals across archives
$aggArchive = $db->query("SELECT COUNT(*) AS total_requests,
    SUM(CASE WHEN checked = 1 AND blocked = 0 THEN 1 ELSE 0 END) AS total_reviewed,
    SUM(CASE WHEN blocked = 1 THEN 1 ELSE 0 END) AS total_blocked
FROM archives")->fetch();

// Get count of unique companies via a lightweight query
$totalEntities = (int)$db->query("SELECT COUNT(*) FROM (
    SELECT company FROM reports
    UNION
    SELECT company FROM archives
) AS combined_companies")->fetchColumn();

$aggregates = [
    'total_entities' => $totalEntities,
    'total_groups' => $totalGroups,
    'total_requests' => (int)($agg['total_requests'] ?? 0) + (int)($aggArchive['total_requests'] ?? 0),
    'total_reviewed' => (int)($agg['total_reviewed'] ?? 0) + (int)($aggArchive['total_reviewed'] ?? 0),
    'total_blocked'  => (int)($agg['total_blocked'] ?? 0) + (int)($aggArchive['total_blocked'] ?? 0),
    'total_pending'  => (int)($agg['total_pending'] ?? 0),
];

jsonResponse([
    'success' => true,
    'data' => $pagedData,
    'page' => $page,
    'pages' => $totalPages,
    'total' => $totalGroups,
    'aggregates' => $aggregates,
]);
