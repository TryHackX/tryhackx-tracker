<?php
requirePost();

$input = readJsonBody();
if (!$input || !is_array($input)) {
    jsonResponse(['error' => 'Invalid input'], 400);
}

$email = trim($input['email'] ?? '');
$token = trim($input['token'] ?? '');
$preferences = $input['preferences'] ?? [];

if (!$email || !$token) {
    jsonResponse(['error' => 'Missing parameters'], 400);
}

$secret = $cfg['hmac_secret'] ?? '';
if (!verifyUnsubscribeToken($email, $token, $secret)) {
    jsonResponse(['error' => 'Invalid token'], 403);
}

if (!is_array($preferences)) {
    jsonResponse(['error' => 'Invalid preferences'], 400);
}

$validTypes = ['submission', 'review', 'status', 'custom', 'appeal'];

// Check if "all off" → also add to legacy unsubscribed_emails for backward compat
$allDisabled = true;
foreach ($validTypes as $type) {
    if (!isset($preferences[$type]) || $preferences[$type]) {
        $allDisabled = false;
        break;
    }
}

// Update per-type preferences
$stmt = $db->prepare(
    "INSERT INTO email_preferences (email, type, enabled) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)"
);
foreach ($validTypes as $type) {
    $enabled = isset($preferences[$type]) ? ($preferences[$type] ? 1 : 0) : 1;
    $stmt->execute([$email, $type, $enabled]);
}

// Sync legacy table
if ($allDisabled) {
    $db->prepare("INSERT IGNORE INTO unsubscribed_emails (email) VALUES (?)")->execute([$email]);
} else {
    $db->prepare("DELETE FROM unsubscribed_emails WHERE email = ?")->execute([$email]);
}

jsonResponse(['success' => true]);
