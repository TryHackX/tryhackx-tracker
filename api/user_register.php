<?php
/**
 * POST user_register — public account registration.
 * CSRF → feature gates → CAPTCHA (ALWAYS — a registration form without one is a spam sink) →
 * rate limit → validate → create + auto-login.
 * Body: {username, email?, password, csrf_token, captcha_token}
 */
requirePost();

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!usersRegistrationEnabled($cfg)) {
    jsonResponse(['error' => 'registration_disabled'], 400);
}
if (!captchaConfigured($cfg)) {
    jsonResponse(['error' => 'registration_unavailable'], 503);
}
$token = captchaTokenFromInput($input);
if ($token === '' || !verifyCaptcha($token, $cfg)) {
    jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
}
onCaptchaSolved();

$ip = getClientIp($cfg);
$perHour = (int)($cfg['rate_limit_user_register'] ?? 5);
if (!rateLimitAllow('user_register', ipBucket($ip), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

// terms must be accepted, and with the verification gate on an email address is REQUIRED
// (unverified accounts act as guests until the link is clicked)
if (empty($input['terms_accepted'])) {
    jsonResponse(['error' => __('api.users.terms_required'), 'code' => 'terms_required'], 400);
}
if (userEmailVerifyRequired($cfg) && trim((string)($input['email'] ?? '')) === '') {
    jsonResponse(['error' => __('api.users.email_required'), 'code' => 'email_required'], 400);
}

$r = userCreate($db, $cfg, (string)($input['username'] ?? ''), (string)($input['email'] ?? ''), (string)($input['password'] ?? ''), $ip);
if (isset($r['error'])) {
    $msgs = [
        'invalid_username' => __('api.users.invalid_username'),
        'invalid_email'    => __('api.users.invalid_email'),
        'weak_password'    => __('api.users.weak_password', ['rules' => USER_PASSWORD_RULES]),
        'username_taken'   => __('api.users.username_taken'),
        'email_taken'      => __('api.users.email_taken'),
    ];
    jsonResponse(['error' => $msgs[$r['error']] ?? $r['error'], 'code' => $r['error']], 400);
}
$user = $r['user'];
userSessionStart($db, $user, $ip);
userNotify($db, (int)$user['id'], 'welcome', 'Welcome to ' . ($cfg['site_name'] ?? 'the tracker') . '!',
    'Your account is ready. Your groups and their expiry dates are listed on this page.');
// best-effort verification mail when an address was given (account works without confirming)
$verifySent = trim((string)$user['email']) !== '' ? userVerifySend($db, $cfg, $user) : false;

jsonResponse(['success' => true, 'user' => ['id' => (int)$user['id'], 'username' => $user['username']],
              'verify_sent' => $verifySent, 'captcha_solved' => true]);
