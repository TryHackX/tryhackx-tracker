<?php
/**
 * POST shout_emote_delete {csrf_token, id} — take a picture back out of the room.
 *
 * Two authorities behind one endpoint, exactly like shout_delete: your OWN upload needs nothing
 * beyond having made it, anybody else's — including the shipped examples, which belong to the site
 * and to nobody — needs `shout.moderate`. That is the same person who may remove somebody's line,
 * and for the same reason: a picture in a shared room is a thing a moderator has to be able to take
 * down without waiting for the owner.
 *
 * The row goes for good. Lines that used its `:code:` simply show the token again, which is what a
 * reader who never saw the picture would have seen anyway.
 */
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!shoutEmotesEnabled($cfg)) jsonResponse(['error' => 'disabled', 'message' => __('api.shout.disabled')], 403);

$mayModerate = userCan($db, $cfg, 'shout.moderate');
$r = shoutEmoteDelete($db, (int)($input['id'] ?? 0), $mayModerate ? null : (int)$me['id']);
if (empty($r['ok'])) {
    // 'not_found' rather than the lang key's own 'unknown': this endpoint answers in the same
    // vocabulary shout_delete does, so a script that has one list of codes has the whole list. The
    // browser treats it as "the row you clicked is already gone" and takes the card away.
    $code = substr((string)$r['error'], strlen('api.emote.'));
    jsonResponse(['error' => $code === 'unknown' ? 'not_found' : $code, 'message' => __((string)$r['error'])],
                 (int)($r['status'] ?? 400));
}
// Somebody else's picture is an audited act; your own is not. Removing what you added is tidying.
if ($r['row']['uploaded_by'] !== (int)$me['id'] && function_exists('auditLog')) {
    auditLog($db, 'shout.emote_delete', ['target_type' => 'shout', 'target_id' => (int)$r['row']['id'],
        'summary' => (string)($me['username'] ?? '#' . (int)$me['id']) . ' → :' . (string)$r['row']['code'] . ':']);
}
jsonResponse(['success' => true, 'message' => __('api.emote.deleted'), 'id' => (int)$r['row']['id']]);
