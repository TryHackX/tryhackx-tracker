<?php
requirePost();

$input = readJsonBody();
$ids = $input['ids'] ?? [];
if (!is_array($ids)) $ids = [$ids];
if (isset($input['id'])) $ids[] = $input['id'];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
$refresh = !empty($input['refresh']) && $input['refresh'] !== '0' && $input['refresh'] !== 'false';

if (!$ids) {
    jsonResponse(['error' => 'No IDs provided'], 400);
}
if (count($ids) > 50) {
    jsonResponse(['error' => 'Too many IDs (max 50)'], 400);
}

$queued = whitelistRequestMeta($db, $ids, 10, $refresh);

jsonResponse(['success' => true, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
