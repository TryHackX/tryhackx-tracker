<?php
requirePost();

$input = readJsonBody();
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$newUsername = trim($input['admin_username'] ?? '');

if (!$currentPassword) {
    jsonResponse(['error' => 'Current password is required'], 400);
}

if (!password_verify($currentPassword, ADMIN_PASSWORD_HASH)) {
    jsonResponse(['error' => 'Current password is incorrect'], 403);
}

$changes = [];

// Handle username change
if ($newUsername && $newUsername !== ($cfg['admin_username'] ?? 'admin')) {
    if (mb_strlen($newUsername) < 3) {
        jsonResponse(['error' => 'Username must be at least 3 characters'], 400);
    }
    setSettings($db, ['admin_username' => $newUsername]);
    $changes[] = 'Username updated';
}

// Handle password change (optional — only if new password provided)
if ($newPassword) {
    if (mb_strlen($newPassword) < 10) {
        jsonResponse(['error' => 'New password must be at least 10 characters long'], 400);
    }
    if (!preg_match('/[a-z]/', $newPassword)) {
        jsonResponse(['error' => 'New password must contain at least one lowercase letter (a-z)'], 400);
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        jsonResponse(['error' => 'New password must contain at least one uppercase letter (A-Z)'], 400);
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        jsonResponse(['error' => 'New password must contain at least one digit (0-9)'], 400);
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
        jsonResponse(['error' => 'New password must contain at least one special character (!@#$...)'], 400);
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $hashFile = __DIR__ . '/../../config/hash.txt';

    if (file_put_contents($hashFile, $newHash) === false) {
        jsonResponse(['error' => 'Failed to write new password hash'], 500);
    }
    $changes[] = 'Password changed';
}

if (empty($changes)) {
    jsonResponse(['error' => 'No changes to save'], 400);
}

jsonResponse(['success' => true, 'message' => implode('. ', $changes)]);
