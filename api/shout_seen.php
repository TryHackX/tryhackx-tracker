<?php
/**
 * POST shout_seen {csrf_token, id} — "I have read up to here".
 *
 * Sent by a widget that is actually on the screen when new lines arrive, and by nothing else: a
 * hidden tab must not clear a badge nobody looked at. One indexed UPDATE with GREATEST, so two tabs
 * racing cannot move the mark backwards, and the reply says where the mark ended up.
 */
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!shoutMayView($db, $cfg)) jsonResponse(['error' => 'no_permission'], 403);

$id = max(0, (int)($input['id'] ?? 0));
shoutSeen($db, (int)$me['id'], $id);
$st = $db->prepare("SELECT shout_seen_id FROM users WHERE id = ?");
$st->execute([(int)$me['id']]);
jsonResponse(['success' => true, 'seen' => (int)($st->fetchColumn() ?: 0)]);
