<?php
// Bulk "Fetch metadata": queue every active row matching a scope in ONE UPDATE. The metadata worker
// consumes the queue itself (one hash after another, at its own concurrency) — nothing is fetched here.
requirePost();

$input = readJsonBody();
$scope = strtolower(trim((string)($input['scope'] ?? '')));
if (!in_array($scope, ['missing', 'failed', 'missing_failed', 'all'], true)) {
    jsonResponse(['error' => 'Invalid scope (missing | failed | missing_failed | all)'], 400);
}

$queued = whitelistQueueMetaByScope($db, $scope);
if ($queued === null) {
    jsonResponse(['error' => 'Invalid scope'], 400);
}

jsonResponse(['success' => true, 'scope' => $scope, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
