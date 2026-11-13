<?php
/**
 * Admin creates one account.
 *
 * Registration already existed, but only from the outside: an operator who wanted to add somebody —
 * a moderator, a friend, an account for themselves to test with — had to go through the public form
 * with a real mailbox, or write the row by hand. This is the same creation path (`userCreate`, so
 * default groups and password rules are identical), with the one decision an admin needs to make
 * explicit rather than implied:
 *
 *   verify=auto      the address is trusted as it stands. No mail, usable immediately. This is what
 *                    you want for an account you are handing to somebody in person.
 *   verify=send      created unverified and a verification mail goes out — the public flow, driven
 *                    from here. The account acts as a guest until the link is clicked.
 *   verify=none      created unverified and NO mail. For an account with no address, or when you
 *                    intend to send the link yourself later.
 *
 * The distinction matters because `users_require_email_verify` decides what an unverified account
 * may DO, and an admin creating an account by hand should not have to guess which of the three they
 * just got.
 */
requirePost();

/** Human wording of the username policy, matching userValidUsername(). */
const USER_USERNAME_RULES_TXT = '3-32 characters, letters, digits, dot, dash or underscore';

// The permission gate is the map in api.php (default deny), the same as every other admin endpoint;
// nothing to check again here.
if (!usersEnabled($cfg)) jsonResponse(['error' => __('api.users.accounts_disabled_msg')], 400);

$input    = readJsonBody();
$username = trim((string)($input['username'] ?? ''));
$email    = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$verify   = (string)($input['verify'] ?? 'auto');
$status   = (string)($input['status'] ?? 'active');

if (!in_array($verify, ['auto', 'send', 'none'], true)) jsonResponse(['error' => __('api.users.invalid_verify_mode')], 400);
if (!in_array($status, ['active', 'banned'], true))     jsonResponse(['error' => __('api.users.invalid_status')], 400);
if (!userValidUsername($username)) jsonResponse(['error' => __('api.users.username_rules', ['rules' => USER_USERNAME_RULES_TXT])], 400);
if (!userValidPassword($password)) jsonResponse(['error' => __('api.users.password_rules', ['rules' => USER_PASSWORD_RULES])], 400);
if ($email === '' && $verify !== 'none') {
    // Saying "verified" or "we sent a link" about an address that does not exist would be a lie the
    // panel then displays as a badge.
    jsonResponse(['error' => __('api.users.email_required_unless_none')], 400);
}
if ($email !== '' && !userValidEmail($email)) jsonResponse(['error' => __('api.users.invalid_email_2')], 400);

$res = userCreate($db, $cfg, $username, $email, $password, getClientIp(), 'admin');
if (isset($res['error'])) {
    $msg = [
        'invalid_username' => __('api.users.username_rules', ['rules' => USER_USERNAME_RULES_TXT]),
        'invalid_email'    => __('api.users.invalid_email_2'),
        'weak_password'    => __('api.users.password_rules', ['rules' => USER_PASSWORD_RULES]),
        'username_taken'   => __('api.users.username_taken_2'),
        'email_taken'      => __('api.users.email_registered'),
    ][$res['error']] ?? __('api.users.create_failed');
    jsonResponse(['error' => $msg], 400);
}

$user = $res['user'];
$id   = (int)$user['id'];
$sent = false;

if ($verify === 'auto' && $email !== '') {
    $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
} elseif ($verify === 'send' && $email !== '') {
    // Best-effort by design: a mail server that is down must not undo an account that was created.
    // The panel reports which of the two happened rather than implying both.
    $sent = userVerifySend($db, $cfg, $user);
}
if ($status === 'banned') {
    $db->prepare("UPDATE users SET status = 'banned' WHERE id = ?")->execute([$id]);
}

auditNote([
    'target_type' => 'user',
    'target_id'   => (string)$id,
    'summary'     => 'created ' . $username . ' (' . $verify . ($status === 'banned' ? ', banned' : '') . ')',
    'detail'      => json_encode(['username' => $username, 'email' => $email, 'verify' => $verify,
                                  'status' => $status, 'mail_sent' => $sent]),
]);

jsonResponse([
    'success'   => true,
    'id'        => $id,
    'verified'  => $verify === 'auto' && $email !== '',
    'mail_sent' => $sent,
    'message'   => $verify === 'auto'
        ? __('api.users.created_verified')
        : ($verify === 'send'
            ? ($sent ? __('api.users.created_mail_sent')
                     : __('api.users.created_mail_failed'))
            : __('api.users.created_unverified')),
]);
