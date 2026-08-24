<?php
/**
 * user_notifications — GET lists a page (10/page, unread first; &page=N) and returns the total;
 * POST marks read {csrf_token, ids:[...]} / {csrf_token, all:1} or deletes every already-read one
 * {csrf_token, delete_read:1}. Old notifications are also pruned automatically by the janitor
 * (read > 90 days, everything > 365 days).
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    if (!empty($input['delete_read'])) {
        $st = $db->prepare("DELETE FROM user_notifications WHERE user_id = ? AND read_at IS NOT NULL");
        $st->execute([(int)$u['id']]);
        jsonResponse(['success' => true, 'deleted' => $st->rowCount()]);
    }
    if (!empty($input['all'])) {
        $st = $db->prepare("UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
        $st->execute([(int)$u['id']]);
        jsonResponse(['success' => true, 'marked' => $st->rowCount()]);
    }
    $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($v) => $v > 0));
    if (!$ids) jsonResponse(['error' => 'No ids'], 400);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN ($in)");
    $st->execute(array_merge([(int)$u['id']], $ids));
    jsonResponse(['success' => true, 'marked' => $st->rowCount()]);
}

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$cnt = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ?");
$cnt->execute([(int)$u['id']]);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$st = $db->prepare("SELECT id, type, title, body, created_at, read_at FROM user_notifications
                    WHERE user_id = ? ORDER BY (read_at IS NULL) DESC, id DESC LIMIT ? OFFSET ?");
$st->bindValue(1, (int)$u['id'], PDO::PARAM_INT);
$st->bindValue(2, $perPage, PDO::PARAM_INT);
$st->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) { $n['id'] = (int)$n['id']; $rows[] = $n; }
jsonResponse(['success' => true, 'notifications' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
              'unread' => userUnreadCount($db, (int)$u['id'])]);
