<?php
requirePost();

$input = readJsonBody();
if (!$input) {
    jsonResponse(['error' => 'Invalid input'], 400);
}

$id = (int)($input['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => 'Invalid appeal ID'], 400);
}

// Fetch from appeal_archives
$stmt = $db->prepare("SELECT * FROM appeal_archives WHERE id = ?");
$stmt->execute([$id]);
$appeal = $stmt->fetch();
if (!$appeal) {
    jsonResponse(['error' => 'Archived appeal not found'], 404);
}

// Move back to appeals table with pending status
try {
    $stmt = $db->prepare(
        "INSERT INTO appeals (id, infoHash, report_id, name, email, message, appeal_type, status, admin_response, ip, timestamp)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NULL, ?, ?)"
    );
    $stmt->execute([
        $appeal['id'], $appeal['infoHash'], $appeal['report_id'], $appeal['name'],
        $appeal['email'], $appeal['message'], $appeal['appeal_type'] ?? 'unblock',
        $appeal['ip'], $appeal['timestamp']
    ]);
    $db->prepare("DELETE FROM appeal_archives WHERE id = ?")->execute([$id]);
} catch (PDOException $e) {
    error_log('restore_appeal failed: ' . $e->getMessage());
    jsonResponse(['error' => 'Failed to restore the appeal due to a database error.'], 500);
}

// Send email notification
if (!isUnsubscribed($db, $appeal['email'], 'appeal')) {
    try {
        ob_start();
        $appealType = $appeal['appeal_type'] ?? 'unblock';
        $subject = 'Appeal Reopened for Review — ' . ($cfg['site_name'] ?? 'Tracker');
        $body = "Your " . ($appealType === 'block' ? 'block request' : 'unblock appeal') .
                " for the info hash below has been reopened and will be reviewed again.";

        $details = [
            'Info Hash' => '<code>' . sanitize($appeal['infoHash']) . '</code>',
            'Request Type' => $appealType === 'block' ? 'Block Request' : 'Unblock Appeal',
            'Status' => '<strong>Reopened for Review</strong>',
        ];

        $unsubUrl = getUnsubscribeUrl($appeal['email'], $cfg);

        $htmlBody = buildEmailHtml([
            'title' => $subject,
            'greeting' => 'Hello ' . sanitize($appeal['name']),
            'body' => $body,
            'details' => $details,
            'unsubscribe_url' => $unsubUrl,
        ], $cfg);

        $plainText = 'Your appeal for hash ' . $appeal['infoHash'] . ' has been reopened for review.';
        @sendEmail($appeal['email'], $subject, $plainText, $htmlBody, $cfg, $unsubUrl);
        ob_end_clean();
    } catch (\Throwable $e) {
        if (ob_get_level()) ob_end_clean();
    }
}

jsonResponse([
    'success' => true,
    'message' => 'Appeal restored to active reviews',
]);
