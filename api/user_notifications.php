<?php
/**
 * user_notifications — GET lists the newest 50 (unread first); POST marks read:
 * {csrf_token, ids:[...]} or {csrf_token, all:1}.
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
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

$st = $db->prepare("SELECT id, type, title, body, created_at, read_at FROM user_notifications
                    WHERE user_id = ? ORDER BY (read_at IS NULL) DESC, id DESC LIMIT 50");
$st->execute([(int)$u['id']]);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) { $n['id'] = (int)$n['id']; $rows[] = $n; }
jsonResponse(['success' => true, 'notifications' => $rows, 'unread' => userUnreadCount($db, (int)$u['id'])]);
