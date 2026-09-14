<?php
/**
 * POST shout_post {csrf_token, body, format?} — say one line.
 *
 * The gates run in the order a person would ask the questions: is this request real (CSRF), is
 * there somebody making it, does the room exist, may THEY write in it, are they silenced, are they
 * typing faster than the room allows — and only then, is what they typed acceptable. Being told
 * "too long" by a room you may not write in is an answer to a question nobody asked.
 *
 * The reply carries the finished row, rendered exactly as the poll would have rendered it, so the
 * line appears the moment Enter is pressed instead of on the next tick.
 */
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);

// An address ceiling on top of the per-account flood interval, and they answer different questions:
// the flood is about one person's cadence, this is about a script with a list of accounts.
if (!rateLimitAllow('shoutpost', ipBucket(getClientIp($cfg)), 120, 60)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 60], 429);
}

$r = shoutPost($db, $cfg, $me, (string)($input['body'] ?? ''), (string)($input['format'] ?? ''), getClientIp($cfg));
if (!empty($r['ok'])) jsonResponse(['success' => true, 'row' => $r['row']]);

switch ((string)$r['error']) {
    case 'login':          // the session went away between the check above and the write
        jsonResponse(['error' => 'login_required'], 401);
    case 'disabled':
        jsonResponse(['error' => 'disabled', 'message' => __('api.shout.disabled')], 403);
    case 'no_permission':
        jsonResponse(['error' => 'no_permission', 'message' => __('api.shout.no_permission')], 403);
    case 'muted':
        jsonResponse(['error' => 'muted', 'until' => $r['until'],
                      'message' => __('api.shout.muted', ['until' => (string)$r['until']])], 403);
    case 'flood':
        jsonResponse(['error' => 'flood', 'retry_after' => (int)$r['retry_after'],
                      'message' => __('api.shout.flood', ['seconds' => (int)$r['retry_after']])], 429);
    case 'too_long':
        jsonResponse(['error' => 'too_long', 'limit' => (int)$r['limit'],
                      'message' => __('api.shout.too_long', ['limit' => (int)$r['limit']])], 400);
    case 'invalid_body':
        jsonResponse(['error' => 'invalid_body', 'detail' => (string)($r['detail'] ?? '')], 400);
}
jsonResponse(['error' => 'empty'], 400);
