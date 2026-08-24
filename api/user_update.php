<?php
/**
 * POST user_update — self-service profile changes; every change requires the CURRENT password.
 * Body: {csrf_token, current_password, new_password?} and/or {email?} (empty = remove) and/or
 * {cancel_email_change:1}.
 *
 * Email changes are TWO-STEP (schema v9): nothing is written immediately — a confirmation link
 * goes to the OLD address, then one to the NEW address; only the second click applies the change
 * (see userEmailChangeStart/Consume). A cooldown blocks another change for
 * users_email_change_cooldown_days after the last completed one.
 */
requirePost();

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);

// cancelling a pending email change only needs the session (nothing sensitive happens)
if (!empty($input['cancel_email_change'])) {
    userEmailChangeCancel($db, (int)$u['id']);
    jsonResponse(['success' => true, 'changed' => ['email_change_cancelled']]);
}

if (!password_verify((string)($input['current_password'] ?? ''), (string)$u['pass_hash'])) {
    jsonResponse(['error' => 'Current password is incorrect.'], 403);
}

$emailSet = array_key_exists('email', $input);
$newPass = (string)($input['new_password'] ?? '');
if ($newPass !== '' && !userValidPassword($newPass)) {
    jsonResponse(['error' => 'New password: ' . USER_PASSWORD_RULES . '.'], 400);
}
if (!$emailSet && $newPass === '') jsonResponse(['error' => 'Nothing to change.'], 400);

$changed = [];
$emailStage = null;

if ($emailSet) {
    $r = userEmailChangeStart($db, $cfg, $u, (string)$input['email']);
    if (isset($r['error'])) {
        $msgs = [
            'invalid_email' => 'That email address does not look valid.',
            'same_email'    => 'That is already your address.',
            'email_taken'   => 'An account with this email already exists.',
            'cooldown'      => 'The email address was changed recently — the next change is possible after ' . ($r['until'] ?? '') . '.',
        ];
        jsonResponse(['error' => $msgs[$r['error']] ?? $r['error']], 400);
    }
    $emailStage = $r['stage'];
    $changed[] = $emailStage === 'done_direct' ? 'email' : 'email_change_started';
}

$verifySent = false;
if ($newPass !== '') {
    $db->prepare("UPDATE users SET pass_hash = ? WHERE id = ?")
       ->execute([password_hash($newPass, PASSWORD_DEFAULT), (int)$u['id']]);
    // a password change invalidates every remember-me token (stolen-cookie hygiene)
    $db->prepare("DELETE FROM user_tokens WHERE type = 'remember' AND user_id = ?")->execute([(int)$u['id']]);
    $changed[] = 'password';
    userNotify($db, (int)$u['id'], 'account', 'Your password was changed',
        'If this was not you, reset it immediately and contact the site admin.');
}
if ($emailStage === 'done_direct') {
    // first address on an account that had none — standard verification mail guards it
    $fresh = userFindById($db, (int)$u['id']);
    if ($fresh && trim((string)$fresh['email']) !== '') $verifySent = userVerifySend($db, $cfg, $fresh);
    userNotify($db, (int)$u['id'], 'account', 'Your email was set',
        'A verification link was sent to the new address.');
}

jsonResponse(['success' => true, 'changed' => $changed, 'email_stage' => $emailStage, 'verify_sent' => $verifySent]);
