<?php
// Support both GET (legacy) and POST (with reCAPTCHA)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    $hash = strtolower(trim($input['hash'] ?? ''));

    // reCAPTCHA (smart)
    if (isCaptchaRequired($cfg, 'block_check')) {
        $recaptcha = $input['g-recaptcha-response'] ?? '';
        if (!verifyRecaptcha($recaptcha, $cfg)) {
            jsonResponse(['error' => 'reCAPTCHA verification failed', 'captcha_required' => true], 400);
        }
        onCaptchaSolved();
    }
} else {
    $hash = strtolower(trim($_GET['hash'] ?? ''));
}

if (!isValidInfoHash($hash)) {
    jsonResponse(['error' => 'Invalid info hash'], 400);
}

// Per-IP rate limit (defence against automated blacklist scraping).
if (!rateLimitAllow('block_check', getClientIp($cfg), (int)($cfg['rate_limit_block_check'] ?? 30))) {
    jsonResponse(['error' => 'Too many lookups. Please wait a while and try again.'], 429);
}

// Search in reports first, then archives
$report = null;
$stmt = $db->prepare("SELECT company, representative, blocked FROM reports WHERE infoHash = ? ORDER BY blocked DESC, timestamp DESC LIMIT 1");
$stmt->execute([$hash]);
$report = $stmt->fetch();

if (!$report) {
    $stmt = $db->prepare("SELECT company, representative, blocked FROM archives WHERE infoHash = ? ORDER BY blocked DESC, timestamp DESC LIMIT 1");
    $stmt->execute([$hash]);
    $report = $stmt->fetch();
}

addCaptchaPoints($cfg, 'block_check');

if (!$report || !$report['blocked']) {
    // Always return "not blocked" — never reveal whether reports exist
    jsonResponse([
        'success' => true,
        'infoHash' => $hash,
        'blocked' => false,
        'captcha_solved' => wasCaptchaJustSolved(),
    ]);
}

jsonResponse([
    'success' => true,
    'infoHash' => $hash,
    'blocked' => true,
    'company' => $report['company'],
    'representative' => $report['representative'],
    'captcha_solved' => wasCaptchaJustSolved(),
]);
