<?php
requirePost();

$input = readJsonBody();
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$newUsername = trim($input['admin_username'] ?? '');

if (!$currentPassword) {
    jsonResponse(['error' => __('api.account.current_password_required')], 400);
}

requireAdminReauth($currentPassword, $cfg);

$changes = [];

// Handle username change
if ($newUsername && $newUsername !== ($cfg['admin_username'] ?? 'admin')) {
    if (mb_strlen($newUsername) < 3) {
        jsonResponse(['error' => __('api.account.username_min')], 400);
    }
    setSettings($db, ['admin_username' => $newUsername]);
    $changes[] = __('api.account.username_updated');
}

// Handle password change (optional — only if new password provided)
if ($newPassword) {
    if (mb_strlen($newPassword) < 10) {
        jsonResponse(['error' => __('api.account.pw_min')], 400);
    }
    if (!preg_match('/[a-z]/', $newPassword)) {
        jsonResponse(['error' => __('api.account.pw_lower')], 400);
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        jsonResponse(['error' => __('api.account.pw_upper')], 400);
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        jsonResponse(['error' => __('api.account.pw_digit')], 400);
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
        jsonResponse(['error' => __('api.account.pw_special')], 400);
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $hashFile = __DIR__ . '/../../config/hash.txt';

    if (file_put_contents($hashFile, $newHash) === false) {
        jsonResponse(['error' => __('api.account.hash_write_failed')], 500);
    }
    $changes[] = __('api.account.password_changed');
    // The owner is mirrored into `users` as a member of the admin group (the account sign-in opens
    // the panel for that row). A password rotated here must reach the mirror, or the OLD password
    // keeps opening the panel through ?action=login. Same hash, and the mirror's other sessions end.
    $db->prepare("UPDATE users SET pass_hash = ?, sessions_valid_from = ? WHERE username = ?")
       ->execute([$newHash, time(), (string)($cfg['admin_username'] ?? 'admin')]);
}

if (empty($changes)) {
    jsonResponse(['error' => __('api.account.no_changes')], 400);
}

jsonResponse(['success' => true, 'message' => implode('. ', $changes)]);
