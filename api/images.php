<?php
if (!usersEnabled($cfg)) jsonResponse(['error' => __('api.images.not_allowed'), 'code' => 'not_allowed'], 404);
$me = currentUser($db);
if (!imagesMayUpload($db, $cfg, $me)) jsonResponse(['error' => __('api.images.not_allowed'), 'code' => 'not_allowed'], 403);
$uid = (int)$me['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    jsonResponse(['success' => true] + imagesList($db, $cfg, $uid, (int)($_GET['page'] ?? 1)));
}

$tooBig = fn() => jsonResponse(['error' => __('api.media.too_large', ['kb' => (int)floor(imagesMaxBytes($cfg) / 1024)]), 'code' => 'too_big'], 413);

if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0) {
    if (!$_FILES && !$_POST) $tooBig();
    if (!verifyCsrfToken((string)($_POST['csrf_token'] ?? ''))) jsonResponse(['error' => __('api.csrf.invalid'), 'code' => 'csrf'], 403);
    if (!rateLimitAllow('imgupload', 'u' . $uid, IMAGES_RATE_PER_MINUTE, 60)
        || !rateLimitAllow('imgupload', ipBucket(getClientIp($cfg)), IMAGES_RATE_PER_MINUTE * 2, 60)) {
        jsonResponse(['error' => __('api.media.rate_limit'), 'code' => 'rate_limited'], 429);
    }
    $f = $_FILES['file'] ?? null;
    $err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) $tooBig();
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        jsonResponse(['error' => __('api.media.no_file'), 'code' => 'upload_failed'], 400);
    }
    if ((int)$f['size'] > imagesMaxBytes($cfg)) $tooBig();
    $r = imagesStore($db, $cfg, $uid, (string)file_get_contents((string)$f['tmp_name']), (string)($f['name'] ?? ''));
    if (empty($r['ok'])) jsonResponse(['error' => $r['message'], 'code' => $r['code']], (int)$r['status']);
    jsonResponse(['success' => true, 'image' => imagesRowOut($cfg, $r['row'])]);
}

$input = readJsonBody(false);
if (!verifyCsrfToken((string)($input['csrf_token'] ?? ''))) jsonResponse(['error' => __('api.csrf.invalid'), 'code' => 'csrf'], 403);
if ((string)($input['op'] ?? '') !== 'delete') jsonResponse(['error' => __('api.images.unknown_op'), 'code' => 'unknown_op'], 400);
$row = imagesRow($db, (int)($input['id'] ?? 0));
if ($row === null || (int)($row['user_id'] ?? 0) !== $uid) jsonResponse(['error' => __('api.images.not_found'), 'code' => 'not_found'], 404);
if (imagesReferenced($db, (int)$row['id'], false)) jsonResponse(['error' => __('api.images.in_use'), 'code' => 'in_use'], 409);
imagesDelete($db, (int)$row['id']);
jsonResponse(['success' => true]);
