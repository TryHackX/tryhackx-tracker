<?php
/**
 * user_email_prefs — the signed-in user's account-mail preference (the same email_preferences
 * store the unsubscribe page uses; type 'account' covers expiry warnings / security notices).
 * GET → {enabled}; POST {csrf_token, enabled:0|1} updates it.
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);
$email = trim((string)($u['email'] ?? ''));
if ($email === '') jsonResponse(['error' => 'no_email'], 400);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $db->prepare("INSERT INTO email_preferences (email, type, enabled) VALUES (?, 'account', ?)
                  ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)")
       ->execute([$email, $enabled]);
    if ($enabled) {
        // a full unsubscribe (legacy table) would still block sends — lift it when opting back in
        $db->prepare("DELETE FROM unsubscribed_emails WHERE email = ?")->execute([$email]);
    }
    jsonResponse(['success' => true, 'enabled' => $enabled === 1]);
}

jsonResponse(['success' => true, 'enabled' => !isUnsubscribed($db, $email, 'account'),
              'manage_url' => getUnsubscribeUrl($email, $cfg)]);
