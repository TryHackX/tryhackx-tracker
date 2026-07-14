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

// The report is restored as ACTIVE and UNBLOCKED (blocked forced to 0 above). If it was blocked in
// the archive and nothing else keeps this hash blocked, drop it from the blacklist file too —
// otherwise the DB (unblocked) and the tracker (still blocking) drift apart. This removal path was
// previously missing, so a "Restore to active" left the hash blocked at the tracker.
$blacklistChanged = false;
if (!empty($report['blocked'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM reports WHERE infoHash = ? AND blocked = 1 AND id != ?");
    $stmt->execute([$report['infoHash'], $id]);
    $otherBlockedReports = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM archives WHERE infoHash = ? AND blocked = 1");
    $stmt->execute([$report['infoHash']]);
    $otherBlockedArchives = (int)$stmt->fetchColumn();

    if ($otherBlockedReports === 0 && $otherBlockedArchives === 0) {
        $blacklistPath = $cfg['blacklist_path'] ?? '';
        if ($blacklistPath !== '') {
            $blacklistChanged = removeHashFromBlacklist($report['infoHash'], $blacklistPath);
        }
    }
}

// Send restoration notification email
try {
    sendStatusNotification($db, $id, 'restored', $cfg);
} catch (Exception $e) {
    // Non-blocking — report is already restored
}

// The blacklist file changed — ask the tracker to reload it immediately (SIGHUP, no downtime).
$reload = $blacklistChanged ? autoReloadTrackerBlacklist($cfg) : null;

$response = ['success' => true, 'message' => 'Report restored to active'];
if ($blacklistChanged) $response['blacklist_updated'] = true;
if ($reload) $response['reload'] = $reload;
jsonResponse($response);
