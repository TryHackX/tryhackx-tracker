<?php
// Queue metadata for the index: specific hashes[] (manual), a scope (missing|failed|missing_failed|all),
// a date window (scope=date + since_hours or from/to on first_seen), or scope=cancel (reset every queued
// 'pending' row back to 'none' — the stop button).
requirePost();
$input = readJsonBody();
$scope = strtolower(trim((string)($input['scope'] ?? '')));
if ($scope === 'cancel') {
    $n = indexMetaCancel($db);
    jsonResponse(['success' => true, 'scope' => 'cancel', 'cancelled' => $n['cancelled'], 'restored' => $n['restored']]);
}
if ($scope === 'date') {
    $range = parseDateRangeInput($input);
    if ($range === null) jsonResponse(['error' => 'Invalid date range: pass since_hours=N, or from (Y-m-d [H:i]) and optional to.'], 400);
    $queued = indexQueueMetaByDate($db, $range[0], $range[1]);
    jsonResponse(['success' => true, 'scope' => 'date', 'from' => $range[0], 'to' => $range[1], 'queued' => $queued,
                  'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
}
if ($scope !== '') {
    $queued = indexQueueMetaByScope($db, $scope);
    if ($queued === null) jsonResponse(['error' => 'Invalid scope (missing | failed | missing_failed | all | date | cancel)'], 400);
    jsonResponse(['success' => true, 'scope' => $scope, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
}
$hashes = $input['hashes'] ?? [];
if (!is_array($hashes)) $hashes = [$hashes];
if (!$hashes) jsonResponse(['error' => 'No hashes or scope provided'], 400);
if (count($hashes) > 500) jsonResponse(['error' => 'Too many hashes (max 500)'], 400);
$queued = indexRequestMeta($db, $hashes, 5);
jsonResponse(['success' => true, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
