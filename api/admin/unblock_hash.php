<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);

if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

$stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    jsonResponse(['error' => 'Report not found'], 404);
}

if (!$report['blocked']) {
    jsonResponse(['error' => 'Report is not blocked'], 400);
}

// Update DB
$stmt = $db->prepare("UPDATE reports SET blocked = 0 WHERE id = ?");
$stmt->execute([$id]);

// Remove ALL occurrences of hash from blacklist file (case-insensitive)
$blacklistPath = $cfg['blacklist_path'] ?? '';
$blacklistUpdated = removeHashFromBlacklist($report['infoHash'], $blacklistPath);

// Notify reporter
if ($report['email']) {
    $newStatus = $report['checked'] ? 'checked' : 'pending';
    sendStatusNotification($db, $id, $newStatus, $cfg);
}

// Auto-close pending unblock appeals for this hash (hash is now unblocked — their goal is achieved)
$autoClosed = autoCloseRelatedAppeals($db, $report['infoHash'], 'unblock', 0, $cfg);

$response = ['success' => true, 'message' => 'Hash unblocked'];
if ($blacklistPath && !$blacklistUpdated) {
    $response['blacklist_warning'] = 'Unblocked in database but could not remove from blacklist file.';
}
$response['auto_closed'] = $autoClosed;
jsonResponse($response);
