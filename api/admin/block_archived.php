<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);

if ($id < 1) {
    jsonResponse(['error' => __('api.report.invalid_id')], 400);
}

$stmt = $db->prepare("SELECT * FROM archives WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    jsonResponse(['error' => __('api.report.archived_not_found')], 404);
}

if ($report['blocked']) {
    jsonResponse(['error' => __('api.report.already_blocked_2')], 400);
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

$response = ['success' => true, 'message' => $block['mode'] === 'whitelist' ? __('api.report.hash_banned_archive') : __('api.report.hash_blocked_archive')];
if (!$block['file_ok'] && ($block['errors'] || ($block['mode'] === 'blacklist' && ($cfg['blacklist_path'] ?? '') !== ''))) {
    $response['blacklist_warning'] = __('api.report.list_file_not_updated');
    $response['blacklist_errors'] = $block['errors'];
    $response['blacklist_suggestions'] = $block['suggestions'];
}
if ($block['reload']) $response['reload'] = $block['reload'];
$response['auto_closed'] = $autoClosedUnblock + $autoClosedBlock;
jsonResponse($response);
