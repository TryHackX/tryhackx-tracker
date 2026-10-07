<?php
requirePost();

$input = readJsonBody();
if (!$input) {
    jsonResponse(['error' => __('api.appeal.invalid_input')], 400);
}

$id = (int)($input['id'] ?? 0);
$newStatus = $input['status'] ?? '';
$adminResponse = trim($input['admin_response'] ?? '');

if ($id < 1) {
    jsonResponse(['error' => __('api.appeal.invalid_id')], 400);
}

if (!in_array($newStatus, ['accepted', 'rejected'], true)) {
    jsonResponse(['error' => __('api.appeal.status_invalid')], 400);
}

// Fetch appeal
$stmt = $db->prepare("SELECT * FROM appeals WHERE id = ?");
$stmt->execute([$id]);
$appeal = $stmt->fetch();
if (!$appeal) {
    jsonResponse(['error' => __('api.appeal.not_found')], 404);
}

$appealType = $appeal['appeal_type'] ?? 'unblock';

// Update appeal
$stmt = $db->prepare("UPDATE appeals SET status = ?, admin_response = ? WHERE id = ?");
$stmt->execute([$newStatus, $adminResponse ? sanitize($adminResponse) : null, $id]);

$unblocked = false;
$blocked = false;

// Handle unblock appeals
if ($appealType === 'unblock' && $newStatus === 'accepted' && !empty($input['unblock'])) {
    // Find blocked report in reports or archives
    $stmt = $db->prepare("SELECT id FROM reports WHERE infoHash = ? AND blocked = 1");
    $stmt->execute([$appeal['infoHash']]);
    $report = $stmt->fetch();
    if ($report) {
        $db->prepare("UPDATE reports SET blocked = 0 WHERE id = ?")->execute([$report['id']]);
        $unblocked = true;
    }

    // Also try archives
    $stmt = $db->prepare("SELECT id FROM archives WHERE infoHash = ? AND blocked = 1");
    $stmt->execute([$appeal['infoHash']]);
    $archived = $stmt->fetch();
    if ($archived) {
        $db->prepare("UPDATE archives SET blocked = 0 WHERE id = ?")->execute([$archived['id']]);
        $unblocked = true;
    }

    // Unblock on the tracker (mode-aware: lift ban / remove from blacklist file) + reload.
    $listChange = trackerUnblockHash($db, $cfg, $appeal['infoHash']);
    $unblocked = true; // an accepted unblock appeal always lifts the tracker-side block
}

// Handle block appeals
if ($appealType === 'block' && $newStatus === 'accepted' && !empty($input['do_block'])) {
    // Block the hash in archives
    $stmt = $db->prepare("SELECT id FROM archives WHERE infoHash = ? AND blocked = 0");
    $stmt->execute([$appeal['infoHash']]);
    $archived = $stmt->fetch();
    if ($archived) {
        $db->prepare("UPDATE archives SET blocked = 1 WHERE id = ?")->execute([$archived['id']]);
        $blocked = true;
    }

    // Also check reports table
    $stmt = $db->prepare("SELECT id FROM reports WHERE infoHash = ? AND blocked = 0");
    $stmt->execute([$appeal['infoHash']]);
    $report = $stmt->fetch();
    if ($report) {
        $db->prepare("UPDATE reports SET blocked = 1 WHERE id = ?")->execute([$report['id']]);
        $blocked = true;
    }

    // Block on the tracker (mode-aware) + reload. An accepted block request blocks the hash even when
    // no report row exists for it (whitelist mode: the ban prevents future registration too).
    $listChange = trackerBlockHash($db, $cfg, $appeal['infoHash'], ['source' => 'appeal', 'source_id' => $id, 'reason' => 'Block request #' . $id . ' accepted']);
    $blocked = true;
}

// Tell the appellant — through the dictionary, in the site's language (1.74.0, QUAL-18: an appeal has no account
// and stores no language; includes/mail.php mailAppealDecisionParts()). A failure is logged, never the answer.
$obLevel = ob_get_level();
ob_start();
try {
    // The reported object's title, from the report or its archive.
    $stmt = $db->prepare("SELECT objectTitle FROM reports WHERE infoHash = ? LIMIT 1");
    $stmt->execute([$appeal['infoHash']]);
    $objectTitle = $stmt->fetchColumn();
    if ($objectTitle === false) {
        $stmt = $db->prepare("SELECT objectTitle FROM archives WHERE infoHash = ? LIMIT 1");
        $stmt->execute([$appeal['infoHash']]);
        $objectTitle = $stmt->fetchColumn();
    }
    sendAppealDecision($db, $appeal, $newStatus, $cfg, [
        'listed' => $appealType === 'block' ? $blocked : $unblocked,
        'title' => (string)($objectTitle ?: ''),
        'response' => $adminResponse,
    ]);
} catch (\Throwable $e) {
    error_log('[appeal] the decision mail of appeal #' . $id . ' failed: ' . $e->getMessage());
}
while (ob_get_level() > $obLevel) ob_end_clean();

// Archive this resolved appeal
$stmt = $db->prepare("SELECT * FROM appeals WHERE id = ?");
$stmt->execute([$id]);
$updatedAppeal = $stmt->fetch();
$archived = false;
if ($updatedAppeal) {
    $archived = archiveAppeal($db, $updatedAppeal);
}

// Auto-close and archive other pending appeals for the same hash + type
$autoClosed = autoCloseRelatedAppeals($db, $appeal['infoHash'], $appealType, $id, $cfg);

// The tracker list changed (a hash was blocked or unblocked) — reload status comes from the helper.
$reload = isset($listChange) ? ($listChange['reload'] ?? null) : null;

$response = [
    'success' => true,
    'message' => __('api.appeal.resolved', ['status' => $newStatus]),
    'unblocked' => $unblocked,
    'blocked' => $blocked,
    'auto_closed' => $autoClosed,
];
if ($reload) $response['reload'] = $reload;
jsonResponse($response);
