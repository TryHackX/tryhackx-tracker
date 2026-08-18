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

if ($report['blocked']) {
    jsonResponse(['error' => 'Already blocked'], 400);
}

// Update DB — mark as blocked and reviewed
$stmt = $db->prepare("UPDATE reports SET blocked = 1, checked = 1 WHERE id = ?");
$stmt->execute([$id]);

// Block on the tracker: whitelist mode = ban (remove from served list), blacklist mode = append to
// the blacklist file. Mode-aware helper; also triggers the (debounced) tracker reload.
$block = trackerBlockHash($db, $cfg, $report['infoHash'], ['source' => 'report', 'source_id' => $id, 'reason' => 'Report #' . $id . ' blocked']);

// Notify reporter about blocking
if ($report['email']) {
    sendStatusNotification($db, $id, 'blocked', $cfg);
}

// Auto-archive: move to archives table atomically (transaction + duplicate-id safe).
$report['blocked'] = 1;
$report['checked'] = 1;
archiveReport($db, $report);

// Auto-close pending unblock appeals for this hash (hash is now blocked)
$autoClosedUnblock = autoCloseRelatedAppeals($db, $report['infoHash'], 'unblock', 0, $cfg);
// Auto-close pending block appeals (hash is now blocked — their goal is achieved)
$autoClosedBlock = autoCloseRelatedAppeals($db, $report['infoHash'], 'block', 0, $cfg);

$response = ['success' => true, 'message' => ($block['mode'] === 'whitelist' ? 'Hash banned' : 'Hash blocked') . ' and report archived', 'archived' => true];
if (!$block['file_ok'] && ($block['errors'] || ($block['mode'] === 'blacklist' && ($cfg['blacklist_path'] ?? '') !== ''))) {
    $response['blacklist_warning'] = 'Hash blocked in database but the tracker list file could not be updated.';
    $response['blacklist_errors'] = $block['errors'];
    $response['blacklist_suggestions'] = $block['suggestions'];
}
if ($block['reload']) $response['reload'] = $block['reload'];
$response['auto_closed'] = $autoClosedUnblock + $autoClosedBlock;
jsonResponse($response);
