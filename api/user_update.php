<?php
/**
 * POST user_update — self-service profile changes; every change requires the CURRENT password.
 * Body: {csrf_token, current_password, new_password?} and/or {email?} (empty email = remove).
 * Everything is validated first, then written in one transaction (a bad new password must not
 * leave a half-committed email change behind).
 */
requirePost();

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);

if (!password_verify((string)($input['current_password'] ?? ''), (string)$u['pass_hash'])) {
    jsonResponse(['error' => 'Current password is incorrect.'], 403);
}

// ── validate everything first ──
$email = null; $emailSet = false;
if (array_key_exists('email', $input)) {
    $emailSet = true;
    $email = trim((string)$input['email']);
    if ($email !== '' && !userValidEmail($email)) jsonResponse(['error' => 'That email address does not look valid.'], 400);
}
$newPass = (string)($input['new_password'] ?? '');
if ($newPass !== '' && !userValidPassword($newPass)) {
    jsonResponse(['error' => 'New password: ' . USER_PASSWORD_RULES . '.'], 400);
}
if (!$emailSet && $newPass === '') jsonResponse(['error' => 'Nothing to change.'], 400);

// ── apply atomically ──
$changed = [];
$db->beginTransaction();
try {
    if ($emailSet) {
        $db->prepare("UPDATE users SET email = ?, email_verified = 0 WHERE id = ?")
           ->execute([$email !== '' ? $email : null, (int)$u['id']]);
        $changed[] = 'email';
    }
    if ($newPass !== '') {
        $db->prepare("UPDATE users SET pass_hash = ? WHERE id = ?")
           ->execute([password_hash($newPass, PASSWORD_DEFAULT), (int)$u['id']]);
        // a password change invalidates every remember-me token (stolen-cookie hygiene)
        $db->prepare("DELETE FROM user_tokens WHERE type = 'remember' AND user_id = ?")->execute([(int)$u['id']]);
        $changed[] = 'password';
    }
    $db->commit();
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ((int)$e->errorInfo[1] === 1062) jsonResponse(['error' => 'An account with this email already exists.'], 400);
    throw $e;
}
userNotify($db, (int)$u['id'], 'account', 'Your ' . implode(' and ', $changed) . ' ' . (count($changed) > 1 ? 'were' : 'was') . ' changed',
    'If this was not you, change your password immediately.');
// a NEW address starts unverified — send the confirmation link right away (best effort); the OLD
// address gets a heads-up so a hijacked session can't silently steal the account's mailbox
$verifySent = false;
if ($emailSet) {
    $oldEmail = trim((string)($u['email'] ?? ''));
    if ($oldEmail !== '' && $oldEmail !== $email) {
        userNotifyMail($db, $cfg, ['email' => $oldEmail, 'username' => $u['username']],
            ($cfg['site_name'] ?? 'Tracker') . ' — your email address was changed',
            'The email address on your account was just changed' . ($email !== '' ? ' to ' . $email : ' (removed)') . ".\nIf this was not you, reset your password immediately.");
    }
    if ($email !== '') {
        $fresh = userFindById($db, (int)$u['id']);
        if ($fresh) $verifySent = userVerifySend($db, $cfg, $fresh);
    }
}
jsonResponse(['success' => true, 'changed' => $changed, 'verify_sent' => $verifySent]);
