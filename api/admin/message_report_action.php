<?php
/**
 * What a moderator may do with a reported message.
 *
 *   POST admin/message_report_action {id, action, note, days?}
 *     action: 'close' | 'reopen' | 'delete_message' | 'mute' | 'unmute' | 'ban' | 'unban'
 *     days:   for 'mute' and 'ban' — 0 means "until somebody lifts it"
 *
 * Closing says a person looked; deleting removes the line that was reported and NOTHING else in the
 * conversation. Muting stops that account writing MESSAGES; banning stops the account. There is
 * still deliberately no "read the thread", no "message this user" and no "reply as the tracker" —
 * the panel's business here is one line of somebody else's correspondence.
 *
 * ── why a mute and a ban are DATES ─────────────────────────────────────────────────────────────
 * Because a punishment with an end needs nobody to remember to end it. A flag would need the person
 * who set it — usually the one who was angry a week ago — to come back and unset it, and the
 * accounts nobody remembers are exactly the ones that stay punished for ever. The janitor tidies
 * the columns; nothing depends on it, because every reader compares them with NOW().
 *
 * ── who cannot be punished from here ───────────────────────────────────────────────────────────
 * An account that can open the panel, and yourself. This card is a moderation queue, not a way for
 * one moderator to remove another — that decision belongs on the Users page, where it is visible as
 * what it is.
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

/* ── the two that change what an ACCOUNT may do ────────────────────────────────────────────── */

if (in_array($action, ['mute', 'unmute', 'ban', 'unban'], true)) {
    $target = (int)$rep['reported_user_id'];
    $actor  = auditActor($db);
    // Never a moderator, and never yourself — see the header.
    if (userIdHasPermission($db, $cfg, $target, 'panel.access')) {
        jsonResponse(['error' => 'target_is_staff'], 403);
    }
    if (!empty($_SESSION['admin_via_user']) && (int)$_SESSION['admin_via_user'] === $target) {
        jsonResponse(['error' => 'target_is_you'], 403);
    }
    // 0 = until somebody lifts it. Anything else is a number of days, clamped to something a
    // calendar can hold: a mute measured in centuries is a permanent one that nobody labelled.
    $days  = max(0, min(3650, (int)($input['days'] ?? 0)));
    $until = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

    if ($action === 'mute' || $action === 'unmute') {
        // "For ever" is stored as a date far enough away to mean it. The column stays one kind of
        // thing — a moment — so every reader is one comparison and there is no second case.
        $set = $action === 'mute' ? ($until ?? '2099-12-31 23:59:59') : null;
        $db->prepare("UPDATE users SET pm_muted_until = ? WHERE id = ?")->execute([$set, $target]);
        userNotify($db, $target, 'account',
            __($action === 'mute' ? 'notify.muted' : 'notify.unmuted'),
            $action === 'mute'
                ? ($until !== null ? __('notify.muted_until', ['date' => $until]) : __('notify.muted_forever'))
                : __('notify.unmuted_body'));
    } else {
        $ban = $action === 'ban';
        $db->prepare("UPDATE users SET status = ?, banned_until = ? WHERE id = ?")
           ->execute([$ban ? 'banned' : 'active', $ban ? $until : null, $target]);
        // A banned account's sessions end on their next request anyway (currentUser() refuses a
        // status that is not 'active'), and its remembered devices are worth taking with it.
        if ($ban && function_exists('userSignOutOthers')) userSignOutOthers($db, $target, false);
        if (!$ban) userNotify($db, $target, 'account', __('notify.unbanned'), __('notify.unbanned_body'));
    }

    $db->prepare("UPDATE message_reports SET note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?")
       ->execute([$note, mb_substr((string)($actor['name'] ?? 'admin'), 0, 64), $id]);
    auditLog($db, 'pm.user.' . $action, [
        'target_type' => 'user', 'target_id' => $target,
        'summary' => $who . ($days > 0 ? ' (' . $days . 'd)' : ''),
        'detail' => ['report' => $id, 'days' => $days, 'until' => $until, 'note' => $note !== '' ? $note : null],
    ]);
    jsonResponse(['success' => true, 'action' => $action, 'until' => $until]);
}

jsonResponse(['error' => 'unknown_action'], 400);
