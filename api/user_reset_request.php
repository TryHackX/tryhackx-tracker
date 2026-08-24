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

// Identical reply whether or not the account exists — and to keep the RESPONSE TIME identical too,
// answer FIRST and only then do the account-dependent work (token insert + synchronous mail());
// under php-fpm fastcgi_finish_request() hands the reply to the client before the mail is sent.
ignore_user_abort(true);
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => 'If that account exists and has an email address, a reset link is on its way.']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();

if ($u && $u['status'] === 'active' && trim((string)$u['email']) !== '') {
    $reset = userResetCreate($db, (int)$u['id']);
    $link = mailAbsoluteUrl($cfg, '?action=reset&token=' . $reset);
    userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — password reset',
        'A password reset was requested for your account. The link below sets a new password and is valid for ' . USER_RESET_TTL_MIN . " minutes.\nIf this was not you, ignore this message — your password stays unchanged.",
        ['title' => 'Password reset', 'action_url' => $link, 'action_label' => 'Set a new password']);
}
exit;
