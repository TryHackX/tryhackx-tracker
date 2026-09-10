<?php
/**
 * The reported-messages queue.
 *
 *   GET admin/fetch_message_reports[&status=open|closed|all][&search=][&page=]
 *
 * ── what a moderator is shown, and what they are not ───────────────────────────────────────────
 *
 * The reported message, and the one before it so the line can be read as an answer to something.
 * NOT the conversation. Two people's correspondence is not evidence in bulk, and somebody deciding
 * about one line does not need the rest of it — which is why `message_reports` stores exactly two
 * ids, why this query joins exactly those two rows, and why there is no endpoint anywhere that
 * takes a thread id and returns its contents to the panel.
 *
 * Behind `panel.messages.view`, which the migration grants to NOBODY: reading somebody's private
 * message is a different kind of access from working the torrent-report queue, and an operator has
 * to hand it out on purpose.
 */
if (!panelCan($db, $cfg, 'panel.messages.view')) jsonResponse(['error' => 'no_permission'], 403);
if (!pmEnabled($cfg)) jsonResponse(['error' => 'pm_disabled'], 404);

$status  = (string)($_GET['status'] ?? 'open');
if (!in_array($status, ['open', 'closed', 'all'], true)) $status = 'open';
$search  = trim((string)($_GET['search'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($cfg['items_per_page'] ?? 25)));

$where  = [];
$params = [];
if ($status !== 'all') { $where[] = 'r.status = ?'; $params[] = $status; }
if ($search !== '') {
    $where[] = '(reporter.username LIKE ? OR reported.username LIKE ? OR r.reason LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
    array_push($params, $like, $like, $like);
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$base = "FROM message_reports r
          JOIN users reporter ON reporter.id = r.reporter_id
          JOIN users reported ON reported.id = r.reported_user_id";

$cnt = $db->prepare("SELECT COUNT(*) $base $w");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

// The two message rows come with the report, by id, and no query here can widen that: `m` is the
// reported message and `c` is its context, both LEFT JOINed by the ids the report itself carries.
$sql = "SELECT r.*, reporter.username AS reporter_name, reported.username AS reported_name,
               m.body AS msg_body, m.body_format AS msg_format, m.created_at AS msg_at, m.id AS msg_id,
               c.body AS ctx_body, c.body_format AS ctx_format, c.created_at AS ctx_at,
               c.sender_id AS ctx_sender
          $base
          LEFT JOIN user_messages m ON m.id = r.message_id
          LEFT JOIN user_messages c ON c.id = r.context_id
          $w ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$st = $db->prepare($sql);
$i = 1;
foreach ($params as $v) $st->bindValue($i++, $v, PDO::PARAM_STR);
$st->bindValue($i++, $perPage, PDO::PARAM_INT);
$st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();

$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        'id'         => (int)$r['id'],
        'status'     => (string)$r['status'],
        'reason'     => (string)$r['reason'],
        'note'       => (string)$r['note'],
        'reporter'   => (string)$r['reporter_name'],
        'reported'   => (string)$r['reported_name'],
        'created_at' => (string)$r['created_at'],
        'handled_by' => (string)$r['handled_by'],
        'handled_at' => $r['handled_at'] ? (string)$r['handled_at'] : null,
        // The message itself is rendered here rather than shipped raw: the panel displays it, and
        // the sanitizer that decides what may be displayed is the same one the public side uses.
        'message'    => $r['msg_id'] ? [
            'id'   => (int)$r['msg_id'],
            'html' => pmRenderBody((string)$r['msg_body'], (string)$r['msg_format'], $cfg, true),
            'at'   => (string)$r['msg_at'],
        ] : null,
        'context'    => $r['ctx_at'] ? [
            'html' => pmRenderBody((string)$r['ctx_body'], (string)$r['ctx_format'], $cfg, true),
            'at'   => (string)$r['ctx_at'],
            // Whose line the context was, by NAME — a moderator reading two lines needs to know
            // which of the two people said which, and nothing else about either of them.
            'from' => (int)$r['ctx_sender'] === (int)$r['reported_user_id'] ? (string)$r['reported_name'] : (string)$r['reporter_name'],
        ] : null,
    ];
}

$open = (int)$db->query("SELECT COUNT(*) FROM message_reports WHERE status = 'open'")->fetchColumn();
jsonResponse([
    'reports' => $rows,
    'total'   => $total,
    'page'    => $page,
    'pages'   => $pages,
    'open'    => $open,
    'may_handle' => panelCan($db, $cfg, 'panel.messages.handle'),
]);
