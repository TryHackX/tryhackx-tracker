<?php
/**
 * What a moderator may do with a reported message.
 *
 *   POST admin/message_report_action {id, action:'close'|'reopen'|'delete_message', note}
 *
 * Three actions and no fourth. Closing says a person looked; deleting removes the line that was
 * reported and NOTHING else in the conversation. There is deliberately no "read the thread", no
 * "message this user" and no "reply as the tracker" — the panel's business here is one line of
 * somebody else's correspondence, and every one of those would widen it.
 *
 * Every action is written to the audit log with the two accounts named. Somebody reading a private
 * message, even a reported one, is exactly the kind of act an audit log exists for.
 */
requirePost();
if (!panelCan($db, $cfg, 'panel.messages.handle')) jsonResponse(['error' => 'no_permission'], 403);

$input  = readJsonBody();
$id     = (int)($input['id'] ?? 0);
$action = (string)($input['action'] ?? '');
$note   = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
if ($id < 1) jsonResponse(['error' => __('api.report.invalid_id')], 400);

$st = $db->prepare("SELECT r.*, reporter.username AS reporter_name, reported.username AS reported_name
                      FROM message_reports r
                      JOIN users reporter ON reporter.id = r.reporter_id
                      JOIN users reported ON reported.id = r.reported_user_id
                     WHERE r.id = ? LIMIT 1");
$st->execute([$id]);
$rep = $st->fetch(PDO::FETCH_ASSOC);
if (!$rep) jsonResponse(['error' => __('api.report.not_found')], 404);

$who = $rep['reporter_name'] . ' → ' . $rep['reported_name'];

if ($action === 'close' || $action === 'reopen') {
    $to = $action === 'close' ? 'closed' : 'open';
    $db->prepare("UPDATE message_reports SET status = ?, note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?")
       ->execute([$to, $note, mb_substr((string)(auditActor($db)['name'] ?? 'admin'), 0, 64), $id]);
    auditLog($db, 'pm.report.' . $action, [
        'target_type' => 'user', 'target_id' => (int)$rep['reported_user_id'],
        'summary' => $who, 'detail' => ['report' => $id, 'note' => $note !== '' ? $note : null],
    ]);
    jsonResponse(['success' => true, 'status' => $to]);
}

if ($action === 'delete_message') {
    // The reported line only. The context row is left where it is: it belongs to the other person,
    // it was never what anybody complained about, and removing it would edit a conversation rather
    // than answer a report.
    $db->prepare("DELETE FROM user_messages WHERE id = ?")->execute([(int)$rep['message_id']]);
    $db->prepare("UPDATE message_reports SET status = 'closed', note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?")
       ->execute([$note, mb_substr((string)(auditActor($db)['name'] ?? 'admin'), 0, 64), $id]);
    auditLog($db, 'pm.message.delete', [
        'target_type' => 'user', 'target_id' => (int)$rep['reported_user_id'],
        'summary' => $who, 'detail' => ['report' => $id, 'message' => (int)$rep['message_id'], 'note' => $note !== '' ? $note : null],
    ]);
    jsonResponse(['success' => true, 'deleted' => (int)$rep['message_id'], 'status' => 'closed']);
}

jsonResponse(['error' => 'unknown_action'], 400);
