<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);

if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

$stmt = $db->prepare("SELECT * FROM archives WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    jsonResponse(['error' => 'Archived report not found'], 404);
}

if ($report['blocked']) {
    jsonResponse(['error' => 'Already blocked'], 400);
}

// Update DB — mark as blocked in archives
$stmt = $db->prepare("UPDATE archives SET blocked = 1, checked = 1 WHERE id = ?");
$stmt->execute([$id]);

// Write to blacklist file (deduplicated)
$blacklistPath = $cfg['blacklist_path'] ?? '';
$blacklistWritten = false;
if ($blacklistPath) {
    $permCheck = checkBlacklistPermissions($blacklistPath);
    if ($permCheck['ok']) {
        $blacklistWritten = addHashToBlacklist($report['infoHash'], $blacklistPath);
    }
}

// Notify reporter about blocking
if ($report['email']) {
    sendStatusNotification($db, $id, 'blocked', $cfg, 'archives');
}

// Auto-close pending appeals for this hash
$autoClosedUnblock = autoCloseRelatedAppeals($db, $report['infoHash'], 'unblock', 0, $cfg);
$autoClosedBlock = autoCloseRelatedAppeals($db, $report['infoHash'], 'block', 0, $cfg);

$response = ['success' => true, 'message' => 'Hash blocked in archive'];
if ($blacklistPath && !$blacklistWritten) {
    $response['blacklist_warning'] = 'Hash blocked in database but could not write to blacklist file.';
}
$response['auto_closed'] = $autoClosedUnblock + $autoClosedBlock;
jsonResponse($response);
