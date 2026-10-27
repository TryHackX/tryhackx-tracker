<?php
if (!profileImagesEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);
$me = currentUser($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $who = trim((string)($_GET['user'] ?? ''));
    if ($who === '') {
        if (!$me) jsonResponse(['error' => 'login_required'], 401);
        $owner = $me;
    } else {
        if (!$me || !userValidUsername($who)) jsonResponse(['error' => 'not_found'], 404);
        $owner = userFindByLogin($db, $who);
        if (!$owner || !profileImagesShownTo($db, $cfg, $owner, $me)) jsonResponse(['error' => 'not_found'], 404);
    }
    $own = (int)$owner['id'] === (int)$me['id'] && (string)($_GET['public'] ?? '') !== '1';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Cache-Control: private, no-store');
    if ((string)($_GET['view'] ?? '') === 'where') {
        $row = imagesRow($db, (int)($_GET['id'] ?? 0));
        if (!$own || !$row || (int)($row['user_id'] ?? 0) !== (int)$me['id']) jsonResponse(['error' => 'not_found'], 404);
        jsonResponse(['success' => true, 'id' => (int)$row['id'], 'used' => profileImagesWhere($db, (int)$row['id'])]);
    }
    jsonResponse(['success' => true, 'own' => $own] + profileImagesList($db, $cfg, (int)$owner['id'], $own, $_GET));
}

requirePost();
if (!$me) jsonResponse(['error' => 'login_required'], 401);
$input = readJsonBody(false);
if (!verifyCsrfToken((string)($input['csrf_token'] ?? ''))) jsonResponse(['error' => __('api.csrf.invalid'), 'code' => 'csrf'], 403);
$op = (string)($input['op'] ?? '');
$row = imagesRow($db, (int)($input['id'] ?? 0));
if (!$row || (int)($row['user_id'] ?? 0) !== (int)$me['id']) jsonResponse(['error' => __('api.images.not_found'), 'code' => 'not_found'], 404);

if ($op === 'delete') {
    if ($row['removal_at'] !== null) jsonResponse(['success' => true, 'deleted' => false, 'waiting' => true, 'message' => __('api.images.removal_waits')]);
    $r = imagesRequestRemoval($db, $cfg, $row, $me);
    jsonResponse(['success' => true, 'waiting' => !$r['deleted']] + $r
        + ['message' => $r['deleted'] ? __('api.images.removed_now') : __('api.images.removal_waits')]);
}

if ($op === 'cancel') {
    if ($row['removal_at'] === null) jsonResponse(['error' => __('api.images.removal_none'), 'code' => 'not_waiting'], 409);
    imagesCancelRemoval($db, $cfg, $row, (int)$me['id']);
    jsonResponse(['success' => true, 'message' => __('api.images.removal_cancelled')]);
}

jsonResponse(['error' => __('api.images.unknown_op'), 'code' => 'unknown_op'], 400);
