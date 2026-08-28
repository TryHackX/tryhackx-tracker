<?php
/**
 * user_email_prefs — the signed-in user's mail preferences (the same email_preferences store the
 * unsubscribe page uses).
 *
 *   type 'account' — expiry warnings, security notices. The account's own business.
 *   type 'bulk'    — announcements the admin writes to everyone. Off here means off, and it is
 *                    mirrored into users.bulk_optout so a send can filter on it in SQL rather than
 *                    asking the preferences table once per recipient.
 *
 * Turning bulk off never touches transactional mail: a password reset is not an announcement, and
 * somebody who wants no newsletter still needs to be able to get back into their account.
 *
 * GET  → {enabled, bulk_enabled}; POST {csrf_token, enabled:0|1, type?:'account'|'bulk'} updates one.
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
    $type = ((string)($input['type'] ?? 'account')) === 'bulk' ? 'bulk' : 'account';
    $db->prepare("INSERT INTO email_preferences (email, type, enabled) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)")
       ->execute([$email, $type, $enabled]);
    if ($type === 'bulk') {
        // Mirrored onto the row so an audience can be filtered in one query instead of one lookup
        // per person. The preferences table stays authoritative for anyone without an account.
        $db->prepare("UPDATE users SET bulk_optout = ? WHERE id = ?")->execute([$enabled ? 0 : 1, (int)$u['id']]);
    }
    if ($enabled && $type === 'account') {
        // a full unsubscribe (legacy table) would still block sends — lift it when opting back in
        $db->prepare("DELETE FROM unsubscribed_emails WHERE email = ?")->execute([$email]);
    }
    jsonResponse(['success' => true, 'enabled' => $enabled === 1, 'type' => $type]);
}

jsonResponse(['success' => true,
              'enabled' => !isUnsubscribed($db, $email, 'account'),
              'bulk_enabled' => (int)($u['bulk_optout'] ?? 0) === 0 && !isUnsubscribed($db, $email, 'bulk'),
              'manage_url' => getUnsubscribeUrl($email, $cfg)]);
