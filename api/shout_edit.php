<?php
/**
 * shout_edit — correct a line (1.66.0).
 *
 *   GET  shout_edit&id=<id>               the words as STORED and their format, for the editor
 *   POST shout_edit {csrf_token, id, body} save the correction
 *
 * The row's own authority is decided inside includes/shout.php (shoutEditRight): the author inside
 * `shout_edit_minutes`, or `shout.edit_any` at any time, and never a line the site said. The window
 * is the DATABASE's arithmetic on `created_at`. Nothing in the request can move it: the only fields
 * read are the id and the new words — an `author`, a `user_id`, a `created_at` or an `own` somebody
 * adds to the body is simply never looked at, so a client claiming a different author or a younger
 * line is refused exactly like any other.
 *
 * The gates run in the order api/shout_post.php runs them — a real request, somebody making it, a
 * room that exists, an address that is not hammering — and the rest in the order shoutEdit() asks:
 * may they write at all, may they touch THIS line, how fast are they editing, and only then what
 * they typed. The reply carries the finished row, rendered as the poll would render it, so the line
 * on the screen is replaced by the server's own version rather than by the browser's guess.
 */
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$input = [];
if ($isPost) {
    $input = readJsonBody();
    if (!is_array($input) || empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
}
if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
// Reading the words back only reads; the session lock goes before the query, as in shout_list.
if (!$isPost && session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

// The address ceiling posting has (api/shout_post.php), with a bucket of its own: an edit box is a
// flood vector too, and it must not borrow the budget somebody needs to say the next line.
if (!rateLimitAllow('shoutedit', ipBucket(getClientIp($cfg)), 120, 60)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 60], 429);
}

$id = max(0, (int)($isPost ? ($input['id'] ?? 0) : ($_GET['id'] ?? 0)));
$r = $isPost
    ? shoutEdit($db, $cfg, $me, $id, (string)($input['body'] ?? ''))
    : shoutEditSource($db, $cfg, $me, $id);

if (!empty($r['ok'])) {
    // `lang` says which language the row was written for, so a page that switched language while
    // this was in the air can tell (assets/js/shoutbox.js) — the same field shout_list carries.
    jsonResponse($isPost
        ? ['success' => true, 'row' => $r['row'], 'changed' => !empty($r['changed']), 'lang' => langCurrent()]
        : ['success' => true, 'id' => $r['id'], 'body' => $r['body'], 'format' => $r['format'], 'left' => (int)$r['left']]);
}

switch ((string)$r['error']) {
    case 'login':
        jsonResponse(['error' => 'login_required'], 401);
    case 'disabled':
        jsonResponse(['error' => 'disabled', 'message' => __('api.shout.disabled')], 403);
    case 'no_permission':
        jsonResponse(['error' => 'no_permission', 'message' => __('api.shout.edit_no_permission')], 403);
    case 'too_late':
        jsonResponse(['error' => 'too_late', 'message' => __('api.shout.edit_too_late')], 403);
    case 'muted':
        jsonResponse(['error' => 'muted', 'until' => $r['until'] ?? null,
                      'message' => __('api.shout.muted', ['until' => (string)($r['until'] ?? '')])], 403);
    case 'not_found':
        jsonResponse(['error' => 'not_found'], 404);
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
