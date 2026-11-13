<?php
/**
 * user_avatar / user_cover — a member's own picture and profile cover (1.63.0, includes/usermedia.php).
 *
 *   GET                                  what the editor needs: the source address, the framing, the limits
 *   POST multipart {csrf_token, file, x, y, zoom}           a new image AND its framing, in ONE request
 *   POST {csrf_token, op: "position", x, y, zoom}          reframe what is there, no upload
 *   POST {csrf_token, op: "remove"}                         delete it for good
 *
 * ONE request for the upload and the framing on purpose. The extension this follows (the research's
 * 6b.5) uploaded the file the moment it was picked — centred, public, stored — and let the owner
 * frame it afterwards, so an accidental pick was a live cover and a file kept for ever. Here the page
 * previews the file locally, the owner frames it, and nothing leaves the browser until Save.
 *
 * MULTIPART, not base64 inside JSON: a phone photo is several megabytes, and a third more of it
 * encoded as text is a third more upload on the slowest connection anybody has.
 *
 * The gates run in the order the questions come: does the body fit at all (a body over PHP's
 * post_max_size arrives with every field missing, which would otherwise read as a bad CSRF token), is
 * the request real, is somebody signed in, does the feature exist — and then REMOVE is answered
 * before the permission: taking your own face off a site is never something to be allowed to do.
 * Setting one asks `profile.avatar` / `profile.cover` of the ACCOUNT (userIdHasPermission, not the
 * session — a panel session in the same browser is not this member's grant), then the rate limit,
 * then the numbers, then the file. Everything after that is includes/usermedia.php.
 *
 * api/user_cover.php sets $umWhat = 'cover' and includes this file: one set of gates for two kinds.
 */
$umWhat = ($umWhat ?? 'avatar') === 'cover' ? 'cover' : 'avatar';
$umPerm = $umWhat === 'cover' ? 'profile.cover' : 'profile.avatar';
$umOn = $umWhat === 'cover' ? userCoversEnabled($cfg) : userAvatarsEnabled($cfg);
$umMaxBytes = userMediaMaxBytes($cfg);
$umVars = ['kb' => (int)floor($umMaxBytes / 1024), 'mp' => userMediaMaxMp($cfg)];

/** One refusal from the pipeline, in the caller's language, with the numbers it was judged by. */
$umFail = function (array $r) use ($umVars): void {
    $key = (string)($r['error'] ?? 'api.media.store_failed');
    $detail = (string)($r['detail'] ?? '');
    jsonResponse(['error' => substr($key, strlen('api.media.')), 'detail' => $detail,
                  'message' => __($key, $umVars + ['px' => $detail, 'kind' => strtoupper($detail)])],
                 (int)($r['status'] ?? 400));
};

/** What the page draws after a change: every address it shows, and the framing. */
$umState = function () use ($db, $cfg, $umWhat): array {
    $u = userFindById($db, (int)(currentUser($db)['id'] ?? 0)) ?? [];
    $base = getBaseUrl();
    $ed = userMediaEditorState($db, $cfg, $u, $base);
    if ($umWhat === 'cover') {
        return $ed['cover'] + ['css' => ($c = userCoverFor($u, $base, $cfg)) !== null ? userCoverCssVars($c) : '',
                               'is_default' => $c !== null && !empty($c['default'])];
    }
    return $ed['avatar'] + ['sha' => (string)($u['avatar_sha'] ?? ''),
                            'url64' => userAvatarUrl($u, 32, $base, $cfg), 'url128' => userAvatarUrl($u, 64, $base, $cfg),
                            'url256' => userAvatarUrl($u, 128, $base, $cfg)];
};

$me = currentUser($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (!$umOn) jsonResponse(['error' => 'disabled', 'message' => __('api.media.disabled_' . $umWhat)], 403);
    jsonResponse(['success' => true, $umWhat => $umState(),
                  'may' => userIdHasPermission($db, $cfg, (int)$me['id'], $umPerm),
                  'max_bytes' => $umMaxBytes, 'max_mp' => userMediaMaxMp($cfg)]);
}

// A body larger than post_max_size is discarded by PHP before this line runs: $_POST and $_FILES are
// empty and the CSRF token with them. Say what actually happened instead of "invalid token".
$umLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$umPost = userMediaIniBytes((string)ini_get('post_max_size'));
if ($umLen > 0 && $umPost > 0 && $umLen > $umPost && empty($_POST) && empty($_FILES)) {
    jsonResponse(['error' => 'too_large', 'message' => __('api.media.too_large', $umVars)], 413);
}
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!$me) jsonResponse(['error' => 'login_required'], 401);
$uid = (int)$me['id'];
if (!$umOn) jsonResponse(['error' => 'disabled', 'message' => __('api.media.disabled_' . $umWhat)], 403);
// Everything below is image work that takes a moment; the rest of this browser's requests must not
// queue behind this one's session lock while it happens.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$file = $_FILES['file'] ?? null;
$op = (string)($input['op'] ?? ($file !== null ? 'upload' : ''));

if ($op === 'remove') {
    $r = userMediaRemove($db, $uid, $umWhat);
    if (empty($r['ok'])) $umFail($r);
    jsonResponse(['success' => true, 'message' => __('api.media.removed_' . $umWhat), $umWhat => $umState()]);
}

if ($op !== 'upload' && $op !== 'position') {
    jsonResponse(['error' => 'unknown_op', 'message' => __('api.media.unknown_op')], 400);
}
if (!userIdHasPermission($db, $cfg, $uid, $umPerm)) {
    jsonResponse(['error' => 'no_permission', 'message' => __('api.media.no_permission_' . $umWhat)], 403);
}
if (!userMediaRateAllow($op === 'upload' ? 'upload' : 'recrop', $uid, getClientIp($cfg))) {
    jsonResponse(['error' => 'rate_limit', 'message' => __('api.media.rate_limit'), 'retry_after' => 60], 429);
}

// The framing. Missing values are "as it is now" for a reframe and "centred, not zoomed" for a new
// image; anything present and not a number is refused rather than guessed at.
$fx = $umWhat === 'cover' ? 'cover_' : 'avatar_';
$keep = $op === 'position';
$x = userMediaFocusValue($input['x'] ?? null, $keep ? (float)($me[$fx . 'x'] ?? 50) : 50.0);
$y = userMediaFocusValue($input['y'] ?? null, $keep ? (float)($me[$fx . 'y'] ?? 50) : 50.0);
$zoom = userMediaZoomValue($input['zoom'] ?? null, $keep ? (float)($me[$fx . 'zoom'] ?? 1) : 1.0);
if ($x === null || $y === null) jsonResponse(['error' => 'bad_focus', 'message' => __('api.media.bad_focus')], 422);
if ($zoom === null) jsonResponse(['error' => 'bad_zoom', 'message' => __('api.media.bad_zoom')], 422);

if ($op === 'position') {
    $r = $umWhat === 'cover' ? userCoverReposition($db, $cfg, $uid, $x, $y, $zoom)
                             : userAvatarReposition($db, $cfg, $uid, $x, $y, $zoom);
    if (empty($r['ok'])) $umFail($r);
    jsonResponse(['success' => true, 'message' => __('api.media.saved'), $umWhat => $umState()]);
}

// The file. PHP's own verdict first — a file over upload_max_filesize never reaches the temp dir —
// then its size BEFORE a byte of it is read, then the pipeline.
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    jsonResponse(['error' => 'no_file', 'message' => __('api.media.no_file')], 400);
}
$err = (int)($file['error'] ?? UPLOAD_ERR_OK);
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    jsonResponse(['error' => 'too_large', 'message' => __('api.media.too_large', $umVars)], 413);
}
if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
    jsonResponse(['error' => 'upload_failed', 'message' => __('api.media.upload_failed')], 400);
}
$size = (int)@filesize((string)$file['tmp_name']);
if ($size > $umMaxBytes) jsonResponse(['error' => 'too_large', 'message' => __('api.media.too_large', $umVars)], 413);
$bytes = (string)@file_get_contents((string)$file['tmp_name']);
// The file's NAME and its declared type are never read: two more things somebody typed.
$r = $umWhat === 'cover' ? userCoverStore($db, $cfg, $uid, $bytes, $x, $y, $zoom)
                         : userAvatarStore($db, $cfg, $uid, $bytes, $x, $y, $zoom);
unset($bytes);
if (empty($r['ok'])) $umFail($r);
jsonResponse(['success' => true, 'message' => __('api.media.saved'), $umWhat => $umState(),
              'first_frame' => ($r['from'] ?? '') === 'gif']);
