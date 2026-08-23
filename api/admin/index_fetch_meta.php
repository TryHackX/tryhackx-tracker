<?php
// Queue metadata for the index: specific hashes[] (manual), or a scope (missing|failed|missing_failed|all).
requirePost();
$input = readJsonBody();
$scope = strtolower(trim((string)($input['scope'] ?? '')));
if ($scope !== '') {
    $queued = indexQueueMetaByScope($db, $scope);
    if ($queued === null) jsonResponse(['error' => 'Invalid scope (missing | failed | missing_failed | all)'], 400);
    jsonResponse(['success' => true, 'scope' => $scope, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
}
$hashes = $input['hashes'] ?? [];
if (!is_array($hashes)) $hashes = [$hashes];
if (!$hashes) jsonResponse(['error' => 'No hashes or scope provided'], 400);
if (count($hashes) > 500) jsonResponse(['error' => 'Too many hashes (max 500)'], 400);
$queued = indexRequestMeta($db, $hashes, 5);
jsonResponse(['success' => true, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
