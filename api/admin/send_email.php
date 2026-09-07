<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$message = trim($input['message'] ?? '');

if ($id < 1) {
    jsonResponse(['error' => __('api.report.invalid_id')], 400);
}

$result = sendCustomEmail($db, $id, $message, $cfg);

if ($result) {
    jsonResponse(['success' => true]);
} else {
    jsonResponse(['error' => __('api.report.email_send_failed')], 500);
}
