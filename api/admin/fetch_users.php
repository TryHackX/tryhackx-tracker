<?php
// Admin user browser: pagination, username/email search, status filter, sort. Groups summarised per row.
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($cfg['items_per_page'] ?? 25));
$offset = ($page - 1) * $perPage;

// 'group' sorts by the user's top group name (highest priority active membership; groupless last)
$groupSortExpr = "(SELECT g2.name FROM user_group_members m2 JOIN user_groups g2 ON g2.id = m2.group_id
                   WHERE m2.user_id = users.id AND m2.granted_at <= NOW() AND (m2.expires_at IS NULL OR m2.expires_at >= NOW())
                   ORDER BY g2.priority DESC, g2.name LIMIT 1)";
$allowedSorts = ['id' => 'id', 'username' => 'username', 'email' => 'email', 'status' => 'status',
                 'created' => 'created_at', 'login' => 'last_login_at', 'group' => $groupSortExpr];
$orderParts = [];
foreach (explode(',', trim((string)($_GET['sort'] ?? 'created:desc'))) as $part) {
    $pieces = explode(':', trim($part));
    $col = $allowedSorts[$pieces[0] ?? ''] ?? null;
    if (!$col) continue;
    $orderParts[] = $col . ((strtolower($pieces[1] ?? 'asc') === 'desc') ? ' DESC' : ' ASC');
}
if (!$orderParts) $orderParts[] = 'created_at DESC';
$orderParts[] = 'id DESC';

$where = []; $params = [];
$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') {
    $where[] = "(username LIKE ? OR email LIKE ?)";
    $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%';
}
$status = (string)($_GET['status'] ?? '');
if (in_array($status, ['active', 'banned'], true)) { $where[] = "status = ?"; $params[] = $status; }
$groupFilter = (int)($_GET['group_id'] ?? 0);
if ($groupFilter > 0) { $where[] = "id IN (SELECT user_id FROM user_group_members WHERE group_id = ?)"; $params[] = $groupFilter; }
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $db->prepare("SELECT COUNT(*) FROM users $whereClause");
$count->execute($params);
$total = (int)$count->fetchColumn();

$stmt = $db->prepare("SELECT id, username, email, status, email_verified, created_at, created_ip, last_login_at, last_login_ip
                      FROM users $whereClause ORDER BY " . implode(', ', $orderParts) . " LIMIT ? OFFSET ?");
$i = 1;
foreach ($params as $v) $stmt->bindValue($i++, $v, PDO::PARAM_STR);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ids = array_map(fn($r) => (int)$r['id'], $rows);
$byUser = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $gm = $db->prepare(
        "SELECT m.user_id, g.slug, g.name, g.color, m.expires_at, m.granted_at,
                (m.granted_at <= NOW() AND (m.expires_at IS NULL OR m.expires_at >= NOW())) AS active
         FROM user_group_members m JOIN user_groups g ON g.id = m.group_id WHERE m.user_id IN ($in)
         ORDER BY g.priority DESC, g.name");
    $gm->execute($ids);
    foreach ($gm->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $byUser[(int)$g['user_id']][] = ['slug' => $g['slug'], 'name' => $g['name'], 'color' => $g['color'],
            'granted_at' => $g['granted_at'], 'expires_at' => $g['expires_at'], 'active' => (bool)$g['active']];
    }
}
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['email_verified'] = (int)$r['email_verified'];
    $r['groups'] = $byUser[$r['id']] ?? [];
}
unset($r);

$counts = ['total' => 0, 'active' => 0, 'banned' => 0];
try {
    foreach ($db->query("SELECT status, COUNT(*) c FROM users GROUP BY status") as $c) {
        $counts['total'] += (int)$c['c'];
        if (isset($counts[$c['status']])) $counts[$c['status']] = (int)$c['c'];
    }
} catch (\Throwable $e) {}

jsonResponse(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)),
              'counts' => $counts, 'enabled' => usersEnabled($cfg)]);
