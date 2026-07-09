<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$message = trim($input['message'] ?? '');

if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

$result = sendCustomEmail($db, $id, $message, $cfg);

if ($result) {
    jsonResponse(['success' => true]);
} else {
    jsonResponse(['error' => 'Failed to send email or email is unsubscribed'], 500);
}
