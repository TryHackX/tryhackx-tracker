<?php
/**
 * POST shout_delete {csrf_token, id} — take one line back, or take somebody else's down.
 *
 * Two authorities behind one endpoint, and the difference is inside shoutDelete(): your own needs
 * `shout.delete_own` (and, from 1.66.0, to be inside `shout_delete_own_minutes`), anybody else's
 * needs `shout.moderate` and is written to the audit log. A
 * shout that is already gone is 404 rather than a quiet success — the button the person clicked was
 * drawn from a list that is no longer the truth, and they should see the list catch up.
 */
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);

$r = shoutDelete($db, $cfg, $me, (int)($input['id'] ?? 0));
if (!empty($r['ok'])) jsonResponse(['success' => true]);
// 1.66.0: your own line only for `shout_delete_own_minutes`, measured by the database — the button
// is taken off the row once the window closes, so this is what a page left open for too long hears.
if ($r['error'] === 'too_late') jsonResponse(['error' => 'too_late', 'message' => __('api.shout.delete_too_late')], 403);
jsonResponse(['error' => $r['error']], $r['error'] === 'not_found' ? 404 : 403);
