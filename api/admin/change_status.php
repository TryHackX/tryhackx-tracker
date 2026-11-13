<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);

if ($id < 1) {
    jsonResponse(['error' => __('api.common.invalid_id')], 400);
}

$stmt = $db->prepare("SELECT id, checked, email FROM reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    jsonResponse(['error' => __('api.report.not_found')], 404);
}

$newChecked = $report['checked'] ? 0 : 1;
$stmt = $db->prepare("UPDATE reports SET checked = ? WHERE id = ?");
$stmt->execute([$newChecked, $id]);

// Auto-notify reporter about status change
if ($report['email']) {
    sendStatusNotification($db, $id, $newChecked ? 'checked' : 'pending', $cfg);
}

jsonResponse([
    'success' => true,
    'checked' => $newChecked,
    'message' => $newChecked ? __('api.report.marked_checked') : __('api.report.unchecked'),
]);
