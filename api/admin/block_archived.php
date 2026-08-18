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

// Block on the tracker: whitelist mode = ban (remove from served list), blacklist mode = append to
// the blacklist file. Mode-aware helper; also triggers the (debounced) tracker reload.
$block = trackerBlockHash($db, $cfg, $report['infoHash'], ['source' => 'report', 'source_id' => $id, 'reason' => 'Report #' . $id . ' blocked (archive)']);

// Notify reporter about blocking
if ($report['email']) {
    sendStatusNotification($db, $id, 'blocked', $cfg, 'archives');
}

// Auto-close pending appeals for this hash
$autoClosedUnblock = autoCloseRelatedAppeals($db, $report['infoHash'], 'unblock', 0, $cfg);
$autoClosedBlock = autoCloseRelatedAppeals($db, $report['infoHash'], 'block', 0, $cfg);

$response = ['success' => true, 'message' => $block['mode'] === 'whitelist' ? 'Hash banned in archive' : 'Hash blocked in archive'];
if (!$block['file_ok'] && ($block['errors'] || ($block['mode'] === 'blacklist' && ($cfg['blacklist_path'] ?? '') !== ''))) {
    $response['blacklist_warning'] = 'Hash blocked in database but the tracker list file could not be updated.';
    $response['blacklist_errors'] = $block['errors'];
    $response['blacklist_suggestions'] = $block['suggestions'];
}
if ($block['reload']) $response['reload'] = $block['reload'];
$response['auto_closed'] = $autoClosedUnblock + $autoClosedBlock;
jsonResponse($response);
