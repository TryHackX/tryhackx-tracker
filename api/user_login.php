<?php
/**
 * POST user_login — user sign-in (username OR email + password).
 * CSRF → rate limit → CAPTCHA (smart mode, same 'login' context as the admin panel) → verify.
 * Body: {login, password, remember?, csrf_token, captcha_token?}
 */
requirePost();

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}
if (!usersEnabled($cfg)) {
    jsonResponse(['error' => 'accounts_disabled'], 400);
}
$ip = getClientIp($cfg);
$perWindow = (int)($cfg['rate_limit_user_login'] ?? 10);
if (!rateLimitAllow('user_login', ipBucket($ip), $perWindow, 900)) {
    jsonResponse(['error' => 'Too many login attempts. Please wait a few minutes and try again.'], 429);
}
if (isCaptchaRequired($cfg, 'login')) {
    if (!verifyCaptcha(captchaTokenFromInput($input), $cfg)) {
        jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

$user = userAuthenticate($db, (string)($input['login'] ?? ''), (string)($input['password'] ?? ''));
if (!$user) {
    resetCaptchaGrace($cfg);
    jsonResponse(['error' => 'Invalid credentials'], 401);
}
if ($user['status'] !== 'active') {
    jsonResponse(['error' => 'This account is suspended.'], 403);
}
userSessionStart($db, $user, $ip);
if (!empty($input['remember'])) userRememberIssue($db, (int)$user['id']);

jsonResponse(['success' => true, 'user' => ['id' => (int)$user['id'], 'username' => $user['username']]]);
