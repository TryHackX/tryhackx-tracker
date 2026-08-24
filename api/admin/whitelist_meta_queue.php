<?php
// Bulk "Fetch metadata": queue rows matching a scope in ONE UPDATE. The metadata worker consumes the
// queue itself (one hash after another, at its own concurrency) — nothing is fetched here.
// Scopes: missing | failed | missing_failed | all | date (added within a window: since_hours=N or
// from/to) | cancel (reset every queued 'pending' row back to 'none' — the stop button).
requirePost();

$input = readJsonBody();
$scope = strtolower(trim((string)($input['scope'] ?? '')));

if ($scope === 'cancel') {
    $n = whitelistMetaCancel($db);
    jsonResponse(['success' => true, 'scope' => 'cancel', 'cancelled' => $n['cancelled'], 'restored' => $n['restored']]);
}
if ($scope === 'restore') {
    jsonResponse(['success' => true, 'scope' => 'restore', 'restored' => whitelistMetaRestore($db)]);
}
if ($scope === 'date') {
    $range = parseDateRangeInput($input);
    if ($range === null) jsonResponse(['error' => 'Invalid date range: pass since_hours=N, or from (Y-m-d [H:i]) and optional to.'], 400);
    $queued = whitelistQueueMetaByDate($db, $range[0], $range[1]);
    jsonResponse(['success' => true, 'scope' => 'date', 'from' => $range[0], 'to' => $range[1], 'queued' => $queued,
                  'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
}
if (!in_array($scope, ['missing', 'failed', 'missing_failed', 'all'], true)) {
    jsonResponse(['error' => 'Invalid scope (missing | failed | missing_failed | all | date | cancel)'], 400);
}

$queued = whitelistQueueMetaByScope($db, $scope);
if ($queued === null) {
    jsonResponse(['error' => 'Invalid scope'], 400);
}

jsonResponse(['success' => true, 'scope' => $scope, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
