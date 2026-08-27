<?php
/**
 * POST admin/login_2fa — the second half of the admin sign-in.
 *
 *   {"code":"123456"}          a code from the authenticator app
 *   {"code":"ABCDE-FGHIJ"}     or one of the recovery codes, which is then spent
 *
 * Reachable only from a session that has just passed the password step, and only for five minutes.
 * The brute-force lockout counts failures here exactly as it does for a wrong password: without that,
 * an attacker who has the password could sit and try six-digit codes until one worked.
 */
requirePost();
$input = readJsonBody();

if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

$ip = getClientIp();
if (isLoginLocked($ip, $cfg)) {
    jsonResponse(['error' => 'Too many failed attempts. Please wait a few minutes and try again.'], 429);
}
if (!twofaPendingActive()) {
    // Either nobody entered a password, or they took too long. Say the same thing in both cases.
    jsonResponse(['error' => 'Start again from the sign-in form.'], 401);
}
if (!twofaEnabled()) {
    // It was turned off while this was in flight. Nothing to verify, so nothing to grant.
    unset($_SESSION['2fa_pending']);
    jsonResponse(['error' => 'Start again from the sign-in form.'], 401);
}

$code = trim((string)($input['code'] ?? ''));
if ($code === '') jsonResponse(['error' => 'Enter the code.'], 400);

// A six-digit code is the app; anything else is treated as a recovery code. Both paths cost the same
// failure on the way out.
$ok = false;
$usedRecovery = false;
if (preg_match('/^\s*\d(\s*\d){5}\s*$/', $code)) {
    $ok = twofaCheck($code);
} else {
    $left = twofaUseRecovery($code);
    if ($left !== null) { $ok = true; $usedRecovery = true; }
}

if (!$ok) {
    recordLoginFailure($ip, $cfg);
    jsonResponse(['error' => 'That code is not right.'], 401);
}

adminGrantSession();
clearLoginFailures($ip);
twofaSyncSetting($db, $cfg);

$left = twofaRecoveryLeft();
jsonResponse([
    'success' => true,
    'used_recovery' => $usedRecovery,
    'recovery_left' => $left,
    // Said at the moment it matters, not in a settings page nobody opens: recovery codes are what
    // stand between a lost phone and a lost panel, and they only run out once.
    'message' => $usedRecovery
        ? ('Signed in with a recovery code. ' . $left . ' left — generate a new set from Settings once you have your app working again.')
        : null,
]);
