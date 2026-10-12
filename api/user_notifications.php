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
// `rich` / `rich_format` (v94, 1.75.0): the source of a notification that has formatting — read with the rest, and turned
// into `rich_html` below; the source itself is not sent on. A database whose migration has not run has neither.
$hasRich = true;
try {
    $db->query("SELECT rich, rich_format FROM user_notifications LIMIT 0");
} catch (\Throwable $e) {
    $hasRich = false;
}
$cols .= $hasRich ? ', rich, rich_format' : ', NULL AS rich, NULL AS rich_format';
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
    // Words with a format (1.75.0), drawn from their source NOW — so a renderer fixed later reaches what was sent earlier:
    //   bbcode, markdown  our own renderer (the input is escaped before a rule runs, richtext.php): `rich_html`, which the
    //                     page may put into the document;
    //   html              a campaign's HTML through the sanitizer for the SITE (htmlSafeClean(), includes/htmlsafe.php) —
    //                     and then NOT into the page: `rich_doc` is the whole document for an <iframe sandbox="allow-same-origin
    //                     allow-popups allow-popups-to-escape-sandbox" srcdoc>, with no allow-scripts, so a miss of the
    //                     sanitizer still runs nothing. Prod's CSP is report-only and allows 'unsafe-inline' (includes/csp.php).
    //                     `rich_html` stays EMPTY for it: a field the page could put into its own DOM is a field somebody
    //                     will one day put there (the page has no such path, and this keeps it that way).
    // A plain notification has none of these keys filled: it is `body`, drawn with textContent, exactly as before.
    $src = (string)($n['rich'] ?? '');
    $fmt = (string)($n['rich_format'] ?? '');
    unset($n['rich']);
    $n['rich_format'] = $src !== '' ? $fmt : null;
    $n['rich_html'] = '';
    $n['rich_doc'] = '';
    if ($src !== '' && ($fmt === 'bbcode' || $fmt === 'markdown')) {
        $n['rich_html'] = richtextRender($src, $fmt, $cfg, true);
    } elseif ($src !== '' && $fmt === 'html') {
        $clean = htmlSafeClean($src, 'site');
        if ($clean !== '') $n['rich_doc'] = htmlSafeSiteFrame($clean);     // nothing that survived the cleaner = no frame at all
    }
    $rows[] = $n;
}
jsonResponse(['success' => true, 'notifications' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
              'unread' => userUnreadCount($db, (int)$u['id'])]);
