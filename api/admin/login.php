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

if (isCaptchaRequired($cfg, 'login')) {
    $recaptcha = $input['g-recaptcha-response'] ?? '';
    if (!verifyRecaptcha($recaptcha, $cfg)) {
        jsonResponse(['error' => 'reCAPTCHA failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (attemptLogin($username, $password, $cfg)) {
    clearLoginFailures($ip);
    jsonResponse(['success' => true]);
} else {
    recordLoginFailure($ip, $cfg);
    resetCaptchaGrace($cfg);
    jsonResponse(['error' => 'Invalid credentials'], 401);
}
