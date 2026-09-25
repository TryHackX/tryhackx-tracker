<?php
/**
 * admin/user_bio — a member's profile description, from the panel (1.69.0, includes/profilebio.php).
 *
 *   POST {op: "clear", id}   → {success, cleared, message, user: {id, username, has_bio, html, shown}}
 *
 * Gated like editing a user (`panel.users.edit`, in adminEndpointPermission()): clearing somebody's
 * words is the same authority as taking their picture down (admin/user_media). It is DELETED, not
 * hidden — the column goes back to NULL — the member is told in a notification, and the audit log
 * records who did it and the first few hundred characters of what was there (action `user.bio`).
 *
 * Explicit and idempotent: clearing a description that is already gone changes nothing, and says so —
 * no audit line and no notification for an act that did not happen. The user edit modal reads the
 * description from admin/fetch_users; this answers with the new state so the modal can redraw.
 */
requirePost();

$bioState = function (array $u) use ($db, $cfg): array {
    $src = profileBioClean((string)($u['bio'] ?? ''));
    return ['id' => (int)$u['id'], 'username' => (string)$u['username'], 'has_bio' => $src !== '',
            'html' => profileBioRender($src, $cfg), 'shown' => profileBioFor($db, $cfg, $u) !== ''];
};

$input = readJsonBody();
if ((string)($input['op'] ?? '') !== 'clear') jsonResponse(['error' => __('api.bio.unknown_op')], 400);
$u = userFindById($db, (int)($input['id'] ?? 0));
if (!$u) jsonResponse(['error' => __('api.users.not_found')], 404);

$was = profileBioClean((string)($u['bio'] ?? ''));
if (!profileBioClear($db, (int)$u['id'])) {
    auditSuppress();
    jsonResponse(['success' => true, 'cleared' => false, 'message' => __('api.bio.admin_nothing'), 'user' => $bioState($u)]);
}
auditNote(['summary' => 'profile description cleared for ' . (string)$u['username'],
           'target_type' => 'user', 'target_id' => (int)$u['id'],
           'detail' => ['chars' => mb_strlen($was, 'UTF-8'), 'excerpt' => mb_substr($was, 0, 300, 'UTF-8')]]);
// Told, not left to discover it — the same courtesy as a picture taken down.
userNotify($db, (int)$u['id'], 'account', __('notify.bio_cleared'), __('notify.bio_cleared_body'));
jsonResponse(['success' => true, 'cleared' => true, 'message' => __('api.bio.admin_cleared'),
              'user' => $bioState(userFindById($db, (int)$u['id']) ?? $u)]);
