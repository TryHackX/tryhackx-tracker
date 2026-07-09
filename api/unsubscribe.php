<?php
// Supports both GET (link click) and POST (one-click unsubscribe from email clients)
$email = $_GET['email'] ?? ($_POST['email'] ?? '');
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

if (!$email || !$token) {
    jsonResponse(['error' => 'Missing parameters'], 400);
}

$secret = $cfg['hmac_secret'] ?? '';
if (!verifyUnsubscribeToken($email, $token, $secret)) {
    jsonResponse(['error' => 'Invalid token'], 403);
}

if (isUnsubscribed($db, $email)) {
    jsonResponse(['success' => true, 'message' => 'Already unsubscribed']);
}

// One-click unsubscribe (POST from email client) → disable all
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $types = ['submission', 'review', 'status', 'custom', 'appeal'];
    $stmt = $db->prepare(
        "INSERT INTO email_preferences (email, type, enabled) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE enabled = 0"
    );
    foreach ($types as $t) {
        $stmt->execute([$email, $t]);
    }
    $db->prepare("INSERT IGNORE INTO unsubscribed_emails (email) VALUES (?)")->execute([$email]);
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
            jsonResponse(['error' => 'Email not found'], 404);
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
