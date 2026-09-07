<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);

if ($id < 1) {
    jsonResponse(['error' => __('api.common.invalid_id')], 400);
}

$stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    jsonResponse(['error' => __('api.report.not_found')], 404);
}

if (!$report['blocked']) {
    jsonResponse(['error' => __('api.report.not_blocked')], 400);
}

// Update DB
$stmt = $db->prepare("UPDATE reports SET blocked = 0 WHERE id = ?");
$stmt->execute([$id]);

// Unblock on the tracker (mode-aware: lift the ban / remove from the blacklist file) + reload.
$unblock = trackerUnblockHash($db, $cfg, $report['infoHash']);

// Notify reporter
if ($report['email']) {
    $newStatus = $report['checked'] ? 'checked' : 'pending';
    sendStatusNotification($db, $id, $newStatus, $cfg);
}

// Auto-close pending unblock appeals for this hash (hash is now unblocked — their goal is achieved)
$autoClosed = autoCloseRelatedAppeals($db, $report['infoHash'], 'unblock', 0, $cfg);

$response = ['success' => true, 'message' => $unblock['mode'] === 'whitelist' ? __('api.report.hash_unbanned') : __('api.report.hash_unblocked')];
if (!$unblock['file_ok'] && ($unblock['mode'] === 'blacklist' && ($cfg['blacklist_path'] ?? '') !== '')) {
    $response['blacklist_warning'] = __('api.report.blacklist_remove_failed');
}
if ($unblock['reload']) $response['reload'] = $unblock['reload'];
$response['auto_closed'] = $autoClosed;
jsonResponse($response);
