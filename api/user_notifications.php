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
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
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
    if (!$ids) jsonResponse(['error' => __('api.notifications.no_ids')], 400);
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
// `link` (v83): where it happened, site-relative — the page offers it as a button (a comment's "Show").
// A database whose migration has not run yet has no such column: the list is read without it.
$cols = 'id, type, title, body, created_at, UNIX_TIMESTAMP(created_at) AS created_ts, read_at, link';
try {
    $db->query("SELECT link FROM user_notifications LIMIT 0");
} catch (\Throwable $e) {
    $cols = 'id, type, title, body, created_at, UNIX_TIMESTAMP(created_at) AS created_ts, read_at, NULL AS link';
}
$st = $db->prepare("SELECT $cols FROM user_notifications
                    WHERE user_id = ? ORDER BY (read_at IS NULL) DESC, id DESC LIMIT ? OFFSET ?");
$st->bindValue(1, (int)$u['id'], PDO::PARAM_INT);
$st->bindValue(2, $perPage, PDO::PARAM_INT);
$st->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();
$rows = [];
// When it arrived on the READER's clock (1.73.0 part E): 'Y-m-d H:i' in their zone (users.timezone, the site's
// otherwise), from the instant the database computes — the page wrote the raw DATETIME (the database session's
// wall clock) through the browser's own reading of it. `created_at` stays as it was.
$readerTz = userDisplayTimezone($u, $cfg);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
    $n['id'] = (int)$n['id'];
    $n['created_ts'] = is_numeric($n['created_ts'] ?? null) ? (int)$n['created_ts'] : null;
    $n['created_time'] = $n['created_ts'] !== null ? userDisplayTime($n['created_ts'], $readerTz, 'Y-m-d H:i') : '';
    $rows[] = $n;
}
jsonResponse(['success' => true, 'notifications' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
              'unread' => userUnreadCount($db, (int)$u['id'])]);
