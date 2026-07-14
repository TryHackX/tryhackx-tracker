<?php
requirePost();

$input = readJsonBody();
if (!$input) {
    jsonResponse(['error' => 'Invalid input'], 400);
}

$id = (int)($input['id'] ?? 0);
$newStatus = $input['status'] ?? '';
$adminResponse = trim($input['admin_response'] ?? '');

if ($id < 1) {
    jsonResponse(['error' => 'Invalid appeal ID'], 400);
}

if (!in_array($newStatus, ['accepted', 'rejected'], true)) {
    jsonResponse(['error' => 'Status must be accepted or rejected'], 400);
}

// Fetch appeal
$stmt = $db->prepare("SELECT * FROM appeals WHERE id = ?");
$stmt->execute([$id]);
$appeal = $stmt->fetch();
if (!$appeal) {
    jsonResponse(['error' => 'Appeal not found'], 404);
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

    // Remove ALL occurrences from blacklist file (case-insensitive)
    $blacklistPath = $cfg['blacklist_path'] ?? '';
    removeHashFromBlacklist($appeal['infoHash'], $blacklistPath);
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

    // Add to blacklist file (deduplicated)
    $blacklistPath = $cfg['blacklist_path'] ?? '';
    if ($blocked) {
        addHashToBlacklist($appeal['infoHash'], $blacklistPath);
    }
}

// Send email notification to appellant
if (!isUnsubscribed($db, $appeal['email'], 'appeal')) {
    try {
        ob_start();
        $statusLabel = $newStatus === 'accepted' ? 'Accepted' : 'Rejected';
        $statusColor = $newStatus === 'accepted' ? '#22c55e' : '#ef4444';

        // Fetch objectTitle from reports or archives
        $objectTitle = '';
        $stmt = $db->prepare("SELECT objectTitle FROM reports WHERE infoHash = ? LIMIT 1");
        $stmt->execute([$appeal['infoHash']]);
        $row = $stmt->fetch();
        if ($row) {
            $objectTitle = $row['objectTitle'];
        } else {
            $stmt = $db->prepare("SELECT objectTitle FROM archives WHERE infoHash = ? LIMIT 1");
            $stmt->execute([$appeal['infoHash']]);
            $row = $stmt->fetch();
            if ($row) $objectTitle = $row['objectTitle'];
        }

        if ($appealType === 'block') {
            $subject = 'Block Request ' . $statusLabel . ' — ' . ($cfg['site_name'] ?? 'Tracker');
            $body = "Your request to block the info hash below has been <strong style=\"color:{$statusColor}\">" . $statusLabel . "</strong>.";
            if ($blocked) {
                $body .= " The hash has been added to the tracker blacklist.";
            }
        } else {
            $subject = 'Unblock Appeal ' . $statusLabel . ' — ' . ($cfg['site_name'] ?? 'Tracker');
            $body = "Your appeal to unblock the info hash below has been <strong style=\"color:{$statusColor}\">" . $statusLabel . "</strong>.";
            if ($unblocked) {
                $body .= " The hash has been removed from the tracker blacklist.";
            }
        }

        $details = [
            'Info Hash' => '<code>' . sanitize($appeal['infoHash']) . '</code>',
            'Request Type' => $appealType === 'block' ? 'Block Request' : 'Unblock Appeal',
            'Decision' => '<strong style="color:' . $statusColor . '">' . $statusLabel . '</strong>',
        ];
        if (!empty($objectTitle)) {
            $details = ['Object' => sanitize($objectTitle)] + $details;
        }

        $unsubUrl = getUnsubscribeUrl($appeal['email'], $cfg);

        $htmlBody = buildEmailHtml([
            'title' => $subject,
            'greeting' => 'Hello ' . sanitize($appeal['name']),
            'body' => $body,
            'details' => $details,
            'custom_message' => $adminResponse,
            'unsubscribe_url' => $unsubUrl,
        ], $cfg);

        $plainObj = !empty($objectTitle) ? " ({$objectTitle})" : '';
        $plainText = 'Your ' . ($appealType === 'block' ? 'block request' : 'unblock appeal') . ' for hash ' . $appeal['infoHash'] . $plainObj . ' has been ' . $statusLabel . '.';
        @sendEmail($appeal['email'], $subject, $plainText, $htmlBody, $cfg, $unsubUrl);
        ob_end_clean();
    } catch (\Throwable $e) {
        if (ob_get_level()) ob_end_clean();
    }
}

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

// If the blacklist file changed (a hash was blocked or unblocked), reload the tracker (SIGHUP).
$reload = ($blocked || $unblocked) ? autoReloadTrackerBlacklist($cfg) : null;

$response = [
    'success' => true,
    'message' => 'Appeal ' . $newStatus,
    'unblocked' => $unblocked,
    'blocked' => $blocked,
    'auto_closed' => $autoClosed,
];
if ($reload) $response['reload'] = $reload;
jsonResponse($response);
