<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);

if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

$db = getDb();
$stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    jsonResponse(['error' => 'Report not found'], 404);
}

// Notify reporter about archiving
if ($report['email']) {
    sendStatusNotification($db, $id, 'archived', $cfg);
}

// Move to archives
$stmt = $db->prepare(
    "INSERT INTO archives (id, name, representative, company, email, objectTitle, link, infoHash, magnet_link, ip, add_message, checked, blocked, timestamp)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->execute([
    $report['id'], $report['name'], $report['representative'], $report['company'],
    $report['email'], $report['objectTitle'], $report['link'], $report['infoHash'],
    $report['magnet_link'] ?? null, $report['ip'], $report['add_message'], $report['checked'], $report['blocked'],
    $report['timestamp']
]);

// Delete from reports
$stmt = $db->prepare("DELETE FROM reports WHERE id = ?");
$stmt->execute([$id]);

jsonResponse(['success' => true]);
