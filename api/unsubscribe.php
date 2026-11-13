<?php
// Supports both GET (link click) and POST (one-click unsubscribe from email clients). Strings only (strInput(),
// 1.74.0): `email[]=a` was an uncaught TypeError; now it is a missing parameter.
$email = strInput($_GET, 'email', strInput($_POST, 'email'));
$token = strInput($_GET, 'token', strInput($_POST, 'token'));

if (!$email || !$token) {
    jsonResponse(['error' => __('api.unsubscribe.missing_params')], 400);
}

$secret = $cfg['hmac_secret'] ?? '';
if (!verifyUnsubscribeToken($email, $token, $secret)) {
    jsonResponse(['error' => __('api.unsubscribe.invalid_token')], 403);
}

if (isUnsubscribed($db, $email)) {
    jsonResponse(['success' => true, 'message' => __('api.unsubscribe.already')]);
}

// One-click unsubscribe (POST from email client) → disable all (includes/mail.php unsubscribeAll(), the same
// as the page's own one-click: templates/pages/unsubscribe.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    unsubscribeAll($db, $email);
    jsonResponse(['success' => true]);
}

// Legacy GET unsubscribe
$stmt = $db->prepare("SELECT COUNT(*) FROM reports WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetchColumn() == 0) {
    // Also check archives and appeals
    $stmt = $db->prepare("SELECT COUNT(*) FROM archives WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() == 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM appeals WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() == 0) {
            jsonResponse(['error' => __('api.unsubscribe.email_not_found')], 404);
        }
    }
}

$db->prepare("INSERT IGNORE INTO unsubscribed_emails (email) VALUES (?)")->execute([$email]);

// Also set all types to disabled in preferences
$types = ['submission', 'review', 'status', 'custom', 'appeal'];
$stmt = $db->prepare(
    "INSERT INTO email_preferences (email, type, enabled) VALUES (?, ?, 0)
     ON DUPLICATE KEY UPDATE enabled = 0"
);
foreach ($types as $t) {
    $stmt->execute([$email, $t]);
}

jsonResponse(['success' => true]);
