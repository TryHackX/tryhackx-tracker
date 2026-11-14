<?php
requirePost();

// Get all reviewed reports (blocking auto-archives, so only reviewed-only remain here)
$stmt = $db->query("SELECT * FROM reports WHERE checked = 1");
$reports = $stmt->fetchAll();

if (empty($reports)) {
    jsonResponse(['success' => true, 'archived' => 0]);
}

// Archive each report atomically (archiveReport handles the transaction + duplicate ids).
$count = 0;
foreach ($reports as $r) {
    if (archiveReport($db, $r)) $count++;
}

jsonResponse(['success' => true, 'archived' => $count]);
