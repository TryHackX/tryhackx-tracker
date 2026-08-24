<?php
/**
 * POST user_reset_request — email a password-reset link. Always answers success (no account
 * enumeration); CAPTCHA is always required. Needs a working site mail setup and a user email.
 * Body: {login, csrf_token, captcha_token}
 */
requirePost();

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
if (!captchaConfigured($cfg)) jsonResponse(['error' => 'unavailable'], 503);
$token = captchaTokenFromInput($input);
if ($token === '' || !verifyCaptcha($token, $cfg)) {
    jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
}
onCaptchaSolved();

$ip = getClientIp($cfg);
if (!rateLimitAllow('user_reset', ipBucket($ip), 5, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$u = userFindByLogin($db, (string)($input['login'] ?? ''));
if ($u && $u['status'] === 'active' && trim((string)$u['email']) !== '') {
    $reset = userResetCreate($db, (int)$u['id']);
    $link = rtrim(getBaseUrl(), '/') . '/?action=reset&token=' . $reset;
    userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — password reset',
        "A password reset was requested for your account.\n\nOpen this link to set a new password (valid for " . USER_RESET_TTL_MIN . " minutes):\n" . $link . "\n\nIf this was not you, ignore this message.");
}
// identical reply whether or not the account exists
jsonResponse(['success' => true, 'message' => 'If that account exists and has an email address, a reset link is on its way.']);
