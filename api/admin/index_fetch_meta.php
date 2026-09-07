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
if ($scope === 'restore') {
    jsonResponse(['success' => true, 'scope' => 'restore', 'restored' => indexMetaRestore($db)]);
}
if ($scope === 'date') {
    $range = parseDateRangeInput($input);
    if ($range === null) jsonResponse(['error' => __('api.index.invalid_date_range')], 400);
    $queued = indexQueueMetaByDate($db, $range[0], $range[1]);
    jsonResponse(['success' => true, 'scope' => 'date', 'from' => $range[0], 'to' => $range[1], 'queued' => $queued,
                  'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
}
if ($scope !== '') {
    $queued = indexQueueMetaByScope($db, $scope);
    if ($queued === null) jsonResponse(['error' => __('api.index.invalid_meta_scope')], 400);
    jsonResponse(['success' => true, 'scope' => $scope, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
}
$hashes = $input['hashes'] ?? [];
if (!is_array($hashes)) $hashes = [$hashes];
if (!$hashes) jsonResponse(['error' => __('api.index.no_hashes_or_scope')], 400);
if (count($hashes) > 500) jsonResponse(['error' => __('api.index.too_many_hashes')], 400);
$queued = indexRequestMeta($db, $hashes, 5);
jsonResponse(['success' => true, 'queued' => $queued, 'worker_heartbeat_age' => whitelistWorkerHeartbeatAge($cfg)]);
