<?php
requirePost();

$input = readJsonBody();

if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

// Brute-force lockout (defense in depth, independent of reCAPTCHA)
$ip = getClientIp();
if (isLoginLocked($ip, $cfg)) {
    jsonResponse(['error' => 'Too many failed login attempts. Please wait a few minutes and try again.'], 429);
}

// Moved sign-in address: this endpoint's URL cannot move with it, so without a marker a bot could
// keep hammering api.php?endpoint=admin/login and never need to find the hidden page. Require that
// THIS session actually rendered the sign-in form (templates/pages/adminlogin.php sets the marker),
// which nobody can do without knowing the address. Untouched default installs (path 'admin') keep
// the classic behaviour so existing tooling — deploy/smoke_admin.py included — is unaffected.
if (adminLoginPathCustom($cfg)) {
    $seen = (int)($_SESSION['admin_login_form_at'] ?? 0);
    if ($seen <= 0 || (time() - $seen) > 12 * 3600) {
        jsonResponse(['error' => 'Invalid credentials'], 401);   // same answer as a wrong password
    }
}

if (isCaptchaRequired($cfg, 'login')) {
    // v3 scores per action: the sign-in page mints its token with action 'admin_login'
    $capToken = captchaTokenFromInput($input);
    if (!verifyCaptcha($capToken, $cfg, 'admin_login')) {
        // A post that carried NO token is a CHALLENGE, not a failed sign-in: nobody has offered a
        // credential yet, and the page is being told to go and solve one. Logging it as `login.fail`
        // put a security event in the audit log before every single successful login — which is
        // exactly how an operator learns to scroll past the line that matters. A token that was
        // supplied and REJECTED is different, and still recorded.
        if ($capToken === '') auditSuppress();
        jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (adminCredentialsValid($username, $password, $cfg)) {
    // The password was right. With two-factor authentication on, that is only the first half: no
    // session is created here, so a stolen password on its own reaches nothing. The failure counter
    // is NOT cleared yet either -- clearing it here would let an attacker with the password reset the
    // lockout at will and then take unlimited guesses at the six-digit code.
    if (twofaEnabled()) {
        twofaPendingStart();
        jsonResponse(['success' => false, 'needs_2fa' => true,
                      'recovery_left' => twofaRecoveryLeft(),
                      'message' => 'Enter the six-digit code from your authenticator app.']);
    }
    adminGrantSession();
    clearLoginFailures($ip);
    jsonResponse(['success' => true]);
} else {
    recordLoginFailure($ip, $cfg);
    resetCaptchaGrace($cfg);
    jsonResponse(['error' => 'Invalid credentials'], 401);
}
