<?php
// API bans: list (without snapshots) or a single ban with its full request snapshot (&id=).

$single = (int)($_GET['id'] ?? 0);
if ($single > 0) {
    $st = $db->prepare("SELECT * FROM api_bans WHERE id = ?");
    $st->execute([$single]);
    $ban = $st->fetch();
    if (!$ban) jsonResponse(['error' => 'Not found'], 404);
    $ban['id'] = (int)$ban['id'];
    $snap = null;
    if ($ban['request_snapshot'] !== null && $ban['request_snapshot'] !== '') {
        $decoded = json_decode((string)$ban['request_snapshot'], true);
        $snap = is_array($decoded) ? $decoded : (string)$ban['request_snapshot'];
    }
    $ban['request_snapshot'] = $snap;
    $ban['active'] = ($ban['lifted_at'] === null && strtotime((string)$ban['expires_at']) > time());
    jsonResponse(['ban' => $ban]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($cfg['items_per_page'] ?? 25));
$offset = ($page - 1) * $perPage;

$allowedSorts = ['date' => 'created_at', 'ip' => 'ip', 'reason' => 'reason', 'expires' => 'expires_at'];
$sortParam = trim($_GET['sort'] ?? 'date:desc');
$orderParts = [];
foreach (explode(',', $sortParam) as $part) {
    $pieces = explode(':', trim($part));
    $col = $allowedSorts[$pieces[0] ?? ''] ?? null;
    if (!$col) continue;
    $dir = (strtolower($pieces[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
    $orderParts[] = "$col $dir";
}
if (empty($orderParts)) $orderParts[] = 'created_at DESC';
$orderParts[] = 'id DESC';

$where = [];
$params = [];
$status = $_GET['status'] ?? 'active';
if ($status !== 'all') {
    $where[] = 'lifted_at IS NULL AND expires_at > NOW()';
}
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $where[] = '(ip LIKE ? OR ip_bucket LIKE ? OR key_id LIKE ? OR reason = ?)';
    $params[] = $search . '%';
    $params[] = $search . '%';
    $params[] = $search . '%';
    $params[] = $search;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM api_bans $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT id, ip, ip_bucket, reason, detail, key_id, endpoint, created_at, expires_at, lifted_at, lifted_by,
               (request_snapshot IS NOT NULL) AS has_snapshot, COALESCE(LENGTH(request_snapshot), 0) AS snapshot_len
        FROM api_bans $whereClause ORDER BY " . implode(', ', $orderParts) . " LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$i = 1;
foreach ($params as $v) $stmt->bindValue($i++, $v, PDO::PARAM_STR);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $r['id'] = (int)$r['id'];
    $r['has_snapshot'] = (bool)$r['has_snapshot'];
    $r['snapshot_len'] = (int)$r['snapshot_len'];
    $r['active'] = ($r['lifted_at'] === null && strtotime((string)$r['expires_at']) > time());
    $rows[] = $r;
}
jsonResponse(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage))]);
