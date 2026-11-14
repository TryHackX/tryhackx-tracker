<?php
requirePost();

$input = readJsonBody();
if (!$input) {
    jsonResponse(['error' => __('api.appeal.invalid_input')], 400);
}

$id = (int)($input['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => __('api.appeal.invalid_id')], 400);
}

// Fetch from appeal_archives
$stmt = $db->prepare("SELECT * FROM appeal_archives WHERE id = ?");
$stmt->execute([$id]);
$appeal = $stmt->fetch();
if (!$appeal) {
    jsonResponse(['error' => __('api.appeal.archived_not_found')], 404);
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
    error_log('[appeal] restore_appeal failed: ' . $e->getMessage());
    jsonResponse(['error' => __('api.appeal.restore_db_error')], 500);
}

// Tell the appellant — through the dictionary, in the site's language (1.74.0, QUAL-18; includes/mail.php
// mailAppealDecisionParts()). A failure is logged, never the answer.
$obLevel = ob_get_level();
ob_start();
try {
    sendAppealDecision($db, $appeal, 'reopened', $cfg);
} catch (\Throwable $e) {
    error_log('[appeal] the "reopened" mail of appeal #' . $id . ' failed: ' . $e->getMessage());
}
while (ob_get_level() > $obLevel) ob_end_clean();

jsonResponse([
    'success' => true,
    'message' => __('api.appeal.restored'),
]);
