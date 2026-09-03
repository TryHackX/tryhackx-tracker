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
if (!usersEnabled($cfg)) jsonResponse(['error' => 'User accounts are disabled.'], 400);

$input    = readJsonBody();
$username = trim((string)($input['username'] ?? ''));
$email    = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$verify   = (string)($input['verify'] ?? 'auto');
$status   = (string)($input['status'] ?? 'active');

if (!in_array($verify, ['auto', 'send', 'none'], true)) jsonResponse(['error' => 'Invalid verification mode'], 400);
if (!in_array($status, ['active', 'banned'], true))     jsonResponse(['error' => 'Invalid status'], 400);
if (!userValidUsername($username)) jsonResponse(['error' => 'Username: ' . USER_USERNAME_RULES_TXT], 400);
if (!userValidPassword($password)) jsonResponse(['error' => 'Password: ' . USER_PASSWORD_RULES], 400);
if ($email === '' && $verify !== 'none') {
    // Saying "verified" or "we sent a link" about an address that does not exist would be a lie the
    // panel then displays as a badge.
    jsonResponse(['error' => 'An email address is required unless verification is set to "no email".'], 400);
}
if ($email !== '' && !userValidEmail($email)) jsonResponse(['error' => 'Invalid email'], 400);

$res = userCreate($db, $cfg, $username, $email, $password, getClientIp(), 'admin');
if (isset($res['error'])) {
    $msg = [
        'invalid_username' => 'Username: ' . USER_USERNAME_RULES_TXT,
        'invalid_email'    => 'Invalid email',
        'weak_password'    => 'Password: ' . USER_PASSWORD_RULES,
        'username_taken'   => 'That username is already taken.',
        'email_taken'      => 'That email address is already registered.',
    ][$res['error']] ?? 'Could not create the account.';
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
        ? 'Account created and the address marked verified — it can sign in now.'
        : ($verify === 'send'
            ? ($sent ? 'Account created; a verification link has been emailed.'
                     : 'Account created, but the verification mail could NOT be sent — check the mail settings, or verify the address by hand.')
            : 'Account created without verification. It acts as a guest until the address is verified.'),
]);
