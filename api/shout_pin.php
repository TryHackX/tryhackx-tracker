<?php
/**
 * POST shout_pin {csrf_token, id, pin} — put one line at the top of the room, or take it down.
 *
 * The same shape as api/shout_delete.php, and for the same reason: this is a moderator acting on
 * somebody else's line, so the gate is `shout.moderate` and the act is written to the audit log.
 * shoutPin() is what enforces both, and what keeps the "at most one pinned" rule in a transaction —
 * this file only unpacks the request and names the failure.
 *
 * `pin` absent means pin it: the button somebody pressed is far more often "pin this" than its
 * opposite, and an unpin always comes from the strip, which sends `pin: false` explicitly.
 */
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);

$on = !array_key_exists('pin', $input) || !in_array($input['pin'], [false, 0, '0', 'false'], true);
$r = shoutPin($db, $cfg, $me, (int)($input['id'] ?? 0), $on);
if (!empty($r['ok'])) {
    // The finished row back, so the strip is drawn from what the server would have sent anybody
    // else rather than from what the browser happened to have on screen.
    jsonResponse(['success' => true, 'pinned' => shoutPinned($db, $cfg, $me)]);
}
jsonResponse(['error' => $r['error']], $r['error'] === 'not_found' ? 404 : 403);
