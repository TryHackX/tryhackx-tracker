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
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
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

$r = userCreate($db, $cfg, (string)($input['username'] ?? ''), (string)($input['email'] ?? ''), (string)($input['password'] ?? ''), $ip);
if (isset($r['error'])) {
    $msgs = [
        'invalid_username' => 'Username: 3-32 characters, letters/digits and _ . - only.',
        'invalid_email'    => 'That email address does not look valid.',
        'weak_password'    => 'Password must be at least 8 characters.',
        'username_taken'   => 'This username is already taken.',
        'email_taken'      => 'An account with this email already exists.',
    ];
    jsonResponse(['error' => $msgs[$r['error']] ?? $r['error'], 'code' => $r['error']], 400);
}
$user = $r['user'];
userSessionStart($db, $user, $ip);
userNotify($db, (int)$user['id'], 'welcome', 'Welcome to ' . ($cfg['site_name'] ?? 'the tracker') . '!',
    'Your account is ready. Your groups and their expiry dates are listed on this page.');

jsonResponse(['success' => true, 'user' => ['id' => (int)$user['id'], 'username' => $user['username']], 'captcha_solved' => true]);
