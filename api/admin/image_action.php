<?php
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');

if ($op === 'delete') {
    $id = (int)($input['id'] ?? 0);
    $row = imagesRow($db, $id);
    if ($row === null) jsonResponse(['error' => __('api.images.not_found'), 'code' => 'not_found'], 404);
    $used = count(imagesWhereUsed($db, $id));
    if (!imagesPanelDelete($db, $id, $cfg)) jsonResponse(['error' => __('api.images.not_found'), 'code' => 'not_found'], 404);
    auditNote(['action' => 'image.delete', 'target_type' => 'image', 'target_id' => (string)$id,
               'summary' => 'picture #' . $id . ' deleted' . ($used > 0 ? ', used in ' . $used . ' place(s)' : ''),
               'detail' => ['id' => $id, 'owner_id' => $row['user_id'] === null ? null : (int)$row['user_id'], 'source' => (string)$row['source'],
                            'source_url' => (string)($row['source_url'] ?? ''), 'bytes' => (int)$row['bytes'], 'used' => $used]]);
    jsonResponse(['success' => true, 'used' => $used, 'message' => __('api.images.panel_deleted')]);
}

if ($op === 'retry' || $op === 'forget') {
    $sha = strtolower((string)($input['id'] ?? ''));
    $ok = preg_match('/^[0-9a-f]{64}$/', $sha) === 1 && ($op === 'retry' ? imagesRemoteRetry($db, $sha) : imagesRemoteForget($db, $sha));
    if (!$ok) jsonResponse(['error' => __('api.images.not_found'), 'code' => 'not_found'], 404);
    auditNote(['action' => 'image.' . $op, 'target_type' => 'image_copy', 'target_id' => substr($sha, 0, 16)]);
    jsonResponse(['success' => true, 'message' => __($op === 'retry' ? 'api.images.retry_queued' : 'api.images.forgotten')]);
}

jsonResponse(['error' => __('api.images.unknown_op'), 'code' => 'unknown_op'], 400);
