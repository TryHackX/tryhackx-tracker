<?php
/**
 * What `panel.moderation.handle` may do (1.75.0, includes/moderation.php) — without a report card in front of them.
 *
 *   POST admin/moderation_action {op: 'apply',  user_id, action: warn|mute|ban, days, loud, reason, reason_format}
 *       a warning, a silence or a ban by hand (source 'manual'); days 0 = until somebody lifts it; loud: the member is told,
 *       with the reason (required then). From the Users page's edit window.
 *   POST admin/moderation_action {op: 'lift',   user_id, what: mute|ban, loud, note, note_format}
 *       end a silence or a ban; loud: the member is told, with the optional note. A ban with NO end date (the owner's, set
 *       from the Users page) is lifted only by a session that also holds panel.users.edit.
 *   POST admin/moderation_action {op: 'revoke', id, note, note_format, tell, lift_current}
 *       revoke a warning (it stays, struck through, and counts nowhere); tell: the member is told it was withdrawn (only a
 *       loud one was ever told); lift_current: also lift the silence and the ban that run now.
 *
 * All of it is userModerate() / userWarningRevoke(): the staff and "yourself" refusals, the row lock, the automatic rules.
 * Each writes its own audit lines (user.warn, user.lift, warning.revoke, user.autopenalty), so the router's generic one is
 * suppressed on success; a refusal leaves the router's line (the code as its summary).
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');

/** A refused call: its code, with the sentence and the field when it is a refused text. */
$refuse = function (array $m): void {
    $body = ['success' => false, 'error' => (string)$m['error']];
    foreach (['message', 'field'] as $k) if (isset($m[$k])) $body[$k] = $m[$k];
    jsonResponse($body, (int)($m['status'] ?? 400));
};
$mayDateless = panelCan($db, $cfg, 'panel.users.edit');
$flag = static fn($v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true';

if ($op === 'apply') {
    $action = (string)($input['action'] ?? '');
    if (!in_array($action, ['warn', 'mute', 'ban'], true)) jsonResponse(['success' => false, 'error' => 'unknown_action'], 400);
    $m = userModerate($db, $cfg, ['user_id' => (int)($input['user_id'] ?? 0), 'action' => $action, 'days' => (int)($input['days'] ?? 0),
                                  'loud' => $action === 'warn' || $flag($input['loud'] ?? false), 'reason' => $input['reason'] ?? '',
                                  'reason_format' => $input['reason_format'] ?? 'plain', 'src' => ['kind' => 'manual']]);
    if (empty($m['ok'])) $refuse($m);
    auditSuppress();
    jsonResponse(['success' => true, 'op' => 'apply', 'action' => $action, 'warning' => $m['warning'], 'count' => $m['count'],
                  'until' => $m['forever'] ? null : $m['until'], 'forever' => $m['forever'],
                  'auto' => $m['auto'] ? ['rule' => $m['auto']['rule'], 'action' => $m['auto']['action']] : null]);
}

if ($op === 'lift') {
    $what = (string)($input['what'] ?? '');
    if (!in_array($what, ['mute', 'ban'], true)) jsonResponse(['success' => false, 'error' => 'unknown_action'], 400);
    $m = userModerate($db, $cfg, ['user_id' => (int)($input['user_id'] ?? 0), 'action' => $what === 'mute' ? 'unmute' : 'unban',
                                  'loud' => $flag($input['loud'] ?? false), 'reason' => $input['note'] ?? '', 'reason_format' => $input['note_format'] ?? 'plain',
                                  'may_dateless' => $mayDateless]);
    if (empty($m['ok'])) $refuse($m);
    auditSuppress();
    jsonResponse(['success' => true, 'op' => 'lift', 'what' => $what, 'noop' => $m['noop']]);
}

if ($op === 'revoke') {
    $m = userWarningRevoke($db, $cfg, (int)($input['id'] ?? 0), ['note' => $input['note'] ?? '', 'note_format' => $input['note_format'] ?? 'plain',
                           'tell' => $flag($input['tell'] ?? false), 'lift_current' => $flag($input['lift_current'] ?? false), 'may_dateless' => $mayDateless]);
    if (empty($m['ok'])) $refuse($m);
    auditSuppress();
    jsonResponse(['success' => true, 'op' => 'revoke', 'told' => $m['told'], 'lifted' => $m['lifted'], 'lift_skipped' => $m['lift_skipped']]);
}

jsonResponse(['success' => false, 'error' => 'unknown_action'], 400);
