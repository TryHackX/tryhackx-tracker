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
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!usersEnabled($cfg)) {
    jsonResponse(['error' => 'accounts_disabled'], 400);
}
$ip = getClientIp($cfg);
$perWindow = (int)($cfg['rate_limit_user_login'] ?? 10);
if (!rateLimitAllow('user_login', ipBucket($ip), $perWindow, 900)) {
    jsonResponse(['error' => __('api.login.too_many')], 429);
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
    jsonResponse(['error' => __('api.login.invalid_credentials')], 401);
}
if ($user['status'] !== 'active') {
    jsonResponse(['error' => __('api.login.suspended')], 403);
}

/* ── the second factor ─────────────────────────────────────────────────────────────────────────
 *
 * After the password, never instead of it, and asked for in a SECOND request: the first reply says
 * `twofa: true` and the form grows a field. That shape matters — a form that always shows a code box
 * teaches everybody that this site wants one, and a reply that asked for the code and the password
 * together would have to hold the password somewhere between the two.
 *
 * The code is checked before anything is opened: no session, no remember cookie, no panel. A wrong
 * code leaves the account exactly as it was, and the attempt has already cost one of this address's
 * ten tries in fifteen minutes.
 */
if (user2faEnabled($db, (int)$user['id'])) {
    $code = trim((string)($input['code'] ?? ''));
    if ($code === '') {
        jsonResponse(['error' => 'twofa_required', 'twofa' => true], 401);
    }
    if (!user2faVerify($db, (int)$user['id'], $code)) {
        resetCaptchaGrace($cfg);
        jsonResponse(['error' => 'twofa_invalid', 'twofa' => true], 401);
    }
}
$choice = (string)($input['session'] ?? '');
if ($choice === '' && !empty($input['remember'])) $choice = '30d';   // legacy checkbox
if (!array_key_exists($choice, userSessionChoices())) $choice = 'forever';
[$ttl] = userSessionChoices()[$choice];

userSessionStart($db, $user, $ip, $ttl);
// admin-group members get the panel session opened alongside (its own idle limits still apply)
userMaybeOpenPanelSession($db, $user);
if ($choice === 'forever') {
    userRememberIssue($db, (int)$user['id'], time() + 3650 * 86400);
} elseif ($ttl !== null && $ttl >= 86400) {
    // day-based choices survive a browser restart via a remember cookie with the same deadline
    userRememberIssue($db, (int)$user['id'], time() + $ttl);
}

jsonResponse(['success' => true, 'user' => ['id' => (int)$user['id'], 'username' => $user['username']], 'session' => $choice]);
