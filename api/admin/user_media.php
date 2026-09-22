<?php
/**
 * admin/user_media — pictures and covers from the panel (1.63.0, includes/usermedia.php).
 *
 *   GET  ?id=N                                   one account's: has it a picture / a cover, and their addresses
 *   GET                                          the site's default picture and default cover
 *   POST {op: "remove_avatar"|"remove_cover", id}                 take one account's down
 *   POST multipart {op: "default_upload", kind, file, x, y, zoom}  set the site's default picture / cover
 *   POST {op: "default_position", kind, x, y, zoom}              reframe it
 *   POST {op: "default_remove", kind}                             take it away
 *
 * Gated like editing a user (`panel.users.edit`, in adminEndpointPermission()), because taking a
 * member's picture down is a moderator's act — the research's 6b list noted the extension had no way
 * to do it but "open their editor as them". It is DELETED, not hidden: the rows go in the same
 * transaction that clears the columns, so nothing a moderator removed is left answering at an old
 * address, and the member is told so in a notification. Every write is in the audit log.
 *
 * The site's own images are Settings, and Settings is the owner's: the default_* operations ask the
 * owner-level question the router asks for every owner-only endpoint (panelCan on an id nobody is
 * granted, which only the owner's own session and the admin group pass). The panel is exempt from
 * the members' rate limit — it is one person, and the audit log is its record.
 */
$umOwner = panelCan($db, $cfg, 'panel.owner.__never__');
$base = getBaseUrl();

/** The site's two images, as Settings → Profiles draws them. */
$siteState = function () use ($db, $base): array {
    $c = getSettings($db, true);
    $avSha = strtolower((string)($c['avatar_default_sha'] ?? ''));
    $cvSha = strtolower((string)($c['cover_default_sha'] ?? ''));
    $src = userAvatarSourceRow($db, null);
    return [
        'avatar' => ['has' => $src !== null && userMediaValidSha($avSha),
                     'src' => $src !== null ? userMediaUrl((string)$src['sha1'], 0, $base) : '',
                     'preview' => userMediaValidSha($avSha) ? userMediaUrl($avSha, 128, $base) : '',
                     'x' => (float)($c['avatar_default_x'] ?? 50), 'y' => (float)($c['avatar_default_y'] ?? 50),
                     'zoom' => (float)($c['avatar_default_zoom'] ?? 1)],
        'cover'  => ['has' => userMediaValidSha($cvSha),
                     'src' => userMediaValidSha($cvSha) ? userMediaUrl($cvSha, 0, $base) : '',
                     'x' => (float)($c['cover_default_x'] ?? 50), 'y' => (float)($c['cover_default_y'] ?? 50),
                     'zoom' => (float)($c['cover_default_zoom'] ?? 1)],
        'max_bytes' => userMediaMaxBytes($c), 'max_mp' => userMediaMaxMp($c),
        'cover_h' => userCoverHeight($c), 'cover_hm' => userCoverHeightMobile($c),
        'desk_w' => USER_COVER_DESKTOP_W, 'phone_w' => USER_COVER_PHONE_W,
    ];
};
/** One account's, as the user edit modal draws it. */
$userState = function (array $u) use ($base, $cfg): array {
    $hasAv = userMediaValidSha((string)($u['avatar_sha'] ?? ''));
    $hasCv = userMediaValidSha((string)($u['cover_sha'] ?? ''));
    return ['id' => (int)$u['id'], 'username' => (string)$u['username'],
            'has_avatar' => $hasAv, 'avatar' => $hasAv ? userMediaUrl((string)$u['avatar_sha'], 128, $base) : '',
            'has_cover' => $hasCv, 'cover' => $hasCv ? userMediaUrl((string)$u['cover_sha'], USER_COVER_THUMB_EDGE, $base) : '',
            // What the user list draws beside the name (1.63.0 phase B, as api/admin/fetch_users.php
            // sends it), so a picture taken down here leaves the edit window's own title right too.
            'name_avatar' => userAvatarField($u, 24, $base, $cfg)];
};
$fail = function (array $r): void {
    $key = (string)($r['error'] ?? 'api.media.store_failed');
    $detail = (string)($r['detail'] ?? '');
    $c = $GLOBALS['cfg'] ?? [];
    jsonResponse(['error' => __($key, ['kb' => (int)floor(userMediaMaxBytes($c) / 1024), 'mp' => userMediaMaxMp($c),
                                       'px' => $detail, 'kind' => strtoupper($detail)]),
                  'code' => substr($key, strlen('api.media.')), 'detail' => $detail], (int)($r['status'] ?? 400));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $u = userFindById($db, $id);
        if (!$u) jsonResponse(['error' => __('api.users.not_found')], 404);
        jsonResponse(['success' => true, 'user' => $userState($u)]);
    }
    jsonResponse(['success' => true, 'site' => $siteState(), 'owner' => $umOwner]);
}

// The same answer to an oversized body as the member endpoint gives: a body over post_max_size has
// lost every field, the op with it.
$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$post = userMediaIniBytes((string)ini_get('post_max_size'));
if ($len > 0 && $post > 0 && $len > $post && empty($_POST) && empty($_FILES)) {
    jsonResponse(['error' => __('api.media.too_large', ['kb' => (int)floor(userMediaMaxBytes($cfg) / 1024)])], 413);
}
$input = readJsonBody();
$op = (string)($input['op'] ?? '');

if ($op === 'remove_avatar' || $op === 'remove_cover') {
    $what = $op === 'remove_cover' ? 'cover' : 'avatar';
    $u = userFindById($db, (int)($input['id'] ?? 0));
    if (!$u) jsonResponse(['error' => __('api.users.not_found')], 404);
    $had = userMediaValidSha((string)($u[$what . '_sha'] ?? ''));
    $r = userMediaRemove($db, (int)$u['id'], $what);
    if (empty($r['ok'])) $fail($r);
    auditNote(['summary' => ($what === 'cover' ? 'cover' : 'picture') . ' removed from ' . (string)$u['username'],
               'target_type' => 'user', 'target_id' => (int)$u['id'], 'detail' => ['what' => $what, 'rows' => (int)$r['removed']]]);
    // Told, not left to discover it: the extension this follows changed a member's cover without a word.
    if ($had) userNotify($db, (int)$u['id'], 'account', __('notify.media_removed_' . $what), __('notify.media_removed_body'));
    jsonResponse(['success' => true, 'message' => __('api.media.removed_' . $what), 'user' => $userState(userFindById($db, (int)$u['id']) ?? $u)]);
}

if (!in_array($op, ['default_upload', 'default_position', 'default_remove'], true)) {
    jsonResponse(['error' => __('api.media.unknown_op')], 400);
}
if (!$umOwner) jsonResponse(['error' => 'That action is reserved for the site owner.'], 403);
$kind = (string)($input['kind'] ?? '') === 'cover' ? 'cover' : ((string)($input['kind'] ?? '') === 'avatar' ? 'avatar' : '');
if ($kind === '') jsonResponse(['error' => __('api.media.unknown_op')], 400);

if ($op === 'default_remove') {
    $r = userMediaRemove($db, null, $kind);
    if (empty($r['ok'])) $fail($r);
    auditNote(['summary' => 'default ' . ($kind === 'cover' ? 'cover' : 'picture') . ' removed', 'target_type' => 'settings', 'target_id' => $kind]);
    jsonResponse(['success' => true, 'message' => __('api.media.removed_' . $kind), 'site' => $siteState()]);
}

$c = getSettings($db, true);
$pre = $kind === 'cover' ? 'cover_default_' : 'avatar_default_';
$keep = $op === 'default_position';
$x = userMediaFocusValue($input['x'] ?? null, $keep ? (float)($c[$pre . 'x'] ?? 50) : 50.0);
$y = userMediaFocusValue($input['y'] ?? null, $keep ? (float)($c[$pre . 'y'] ?? 50) : 50.0);
$zoom = userMediaZoomValue($input['zoom'] ?? null, $keep ? (float)($c[$pre . 'zoom'] ?? 1) : 1.0);
if ($x === null || $y === null) jsonResponse(['error' => __('api.media.bad_focus')], 422);
if ($zoom === null) jsonResponse(['error' => __('api.media.bad_zoom')], 422);

if ($op === 'default_position') {
    $r = $kind === 'cover' ? userCoverReposition($db, $cfg, null, $x, $y, $zoom) : userAvatarReposition($db, $cfg, null, $x, $y, $zoom);
    if (empty($r['ok'])) $fail($r);
    auditNote(['summary' => 'default ' . ($kind === 'cover' ? 'cover' : 'picture') . ' reframed', 'target_type' => 'settings', 'target_id' => $kind,
               'detail' => ['x' => $x, 'y' => $y, 'zoom' => $zoom]]);
    jsonResponse(['success' => true, 'message' => __('api.media.saved'), 'site' => $siteState()]);
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) jsonResponse(['error' => __('api.media.no_file')], 400);
$err = (int)($file['error'] ?? UPLOAD_ERR_OK);
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)@filesize((string)($file['tmp_name'] ?? '')) > userMediaMaxBytes($cfg)) {
    jsonResponse(['error' => __('api.media.too_large', ['kb' => (int)floor(userMediaMaxBytes($cfg) / 1024)])], 413);
}
if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) jsonResponse(['error' => __('api.media.upload_failed')], 400);
$bytes = (string)@file_get_contents((string)$file['tmp_name']);
$r = $kind === 'cover' ? userCoverStore($db, $cfg, null, $bytes, $x, $y, $zoom) : userAvatarStore($db, $cfg, null, $bytes, $x, $y, $zoom);
unset($bytes);
if (empty($r['ok'])) $fail($r);
auditNote(['summary' => 'default ' . ($kind === 'cover' ? 'cover' : 'picture') . ' set', 'target_type' => 'settings', 'target_id' => $kind,
           'detail' => ['w' => (int)($r['w'] ?? 0), 'h' => (int)($r['h'] ?? 0), 'x' => $x, 'y' => $y, 'zoom' => $zoom]]);
jsonResponse(['success' => true, 'message' => __('api.media.saved'), 'site' => $siteState()]);
