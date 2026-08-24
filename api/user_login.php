<?php
/**
 * POST user_login — user sign-in (username OR email + password).
 * CSRF → rate limit → CAPTCHA (smart mode, same 'login' context as the admin panel) → verify.
 * Body: {login, password, session?, remember?, csrf_token, captcha_token?}
 *   session: forever (default) | 1h | 1d | 30d — how long the sign-in lasts. "forever" and the
 *   day-based choices set a remember-me cookie with that absolute expiry (forever ≈ 10 years);
 *   "1h" is session-only with a server-side deadline. Legacy remember=1 maps to 30d.
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
$choice = (string)($input['session'] ?? '');
if ($choice === '' && !empty($input['remember'])) $choice = '30d';   // legacy checkbox
if (!array_key_exists($choice, userSessionChoices())) $choice = 'forever';
[$ttl] = userSessionChoices()[$choice];

userSessionStart($db, $user, $ip, $ttl);
if ($choice === 'forever') {
    userRememberIssue($db, (int)$user['id'], time() + 3650 * 86400);
} elseif ($ttl !== null && $ttl >= 86400) {
    // day-based choices survive a browser restart via a remember cookie with the same deadline
    userRememberIssue($db, (int)$user['id'], time() + $ttl);
}

jsonResponse(['success' => true, 'user' => ['id' => (int)$user['id'], 'username' => $user['username']], 'session' => $choice]);
