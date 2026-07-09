<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);

if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

// Mark as reviewed (checked) when admin opens it
$stmt = $db->prepare("UPDATE reports SET checked = 1 WHERE id = ? AND checked = 0");
$stmt->execute([$id]);
$wasMarked = $stmt->rowCount() > 0;

// Check if we already sent an "under review" notification for this report
$stmt = $db->prepare("SELECT COUNT(*) FROM sent_emails WHERE report_id = ? AND subject LIKE '%Under Review%'");
$stmt->execute([$id]);
$alreadySent = $stmt->fetchColumn() > 0;

if ($alreadySent) {
    jsonResponse(['success' => true, 'already_sent' => true, 'marked_reviewed' => $wasMarked, 'message' => 'Review notification was already sent']);
}

$result = sendUnderReviewNotification($db, $id, $cfg);

if ($result) {
    jsonResponse(['success' => true, 'marked_reviewed' => $wasMarked, 'message' => 'Review notification sent']);
} else {
    jsonResponse(['success' => true, 'skipped' => true, 'marked_reviewed' => $wasMarked, 'message' => 'Notification skipped (no email or unsubscribed)']);
}
