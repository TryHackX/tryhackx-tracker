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

// Move back to reports
$stmt = $db->prepare(
    "INSERT INTO reports (id, name, representative, company, email, objectTitle, link, infoHash, magnet_link, ip, add_message, checked, blocked, timestamp)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->execute([
    $report['id'], $report['name'], $report['representative'], $report['company'],
    $report['email'], $report['objectTitle'], $report['link'], $report['infoHash'],
    $report['magnet_link'] ?? null, $report['ip'], $report['add_message'], $report['checked'], 0,
    $report['timestamp']
]);

// Delete from archives
$stmt = $db->prepare("DELETE FROM archives WHERE id = ?");
$stmt->execute([$id]);

// Send restoration notification email
try {
    sendStatusNotification($db, $id, 'restored', $cfg);
} catch (Exception $e) {
    // Non-blocking — report is already restored
}

jsonResponse(['success' => true, 'message' => 'Report restored to active']);
