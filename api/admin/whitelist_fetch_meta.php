<?php
requirePost();

$input = readJsonBody();
$ids = $input['ids'] ?? [];
if (!is_array($ids)) $ids = [$ids];
if (isset($input['id'])) $ids[] = $input['id'];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
$refresh = !empty($input['refresh']) && $input['refresh'] !== '0' && $input['refresh'] !== 'false';

if (!$ids) {
    jsonResponse(['error' => __('api.wl.no_ids')], 400);
}
if (count($ids) > 500) {
    jsonResponse(['error' => __('api.wl.too_many_ids')], 400);
}

$queued = whitelistRequestMeta($db, $ids, 10, $refresh);

jsonResponse(['success' => true, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
