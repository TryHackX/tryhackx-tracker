<?php
// Support both GET (legacy) and POST (with reCAPTCHA)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    $hash = strtolower(trim($input['hash'] ?? ''));

    // CAPTCHA (smart)
    if (isCaptchaRequired($cfg, 'block_check')) {
        if (!verifyCaptcha(captchaTokenFromInput($input), $cfg)) {
            jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
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

// Whitelist mode: a hash can be banned without any report (admin/appeal/import) — banned_hashes is
// authoritative there. Blacklist mode keeps the report-driven answer.
$bannedNoReport = trackerMode($cfg) === 'whitelist' && isHashBanned($db, $hash);
$whitelisted = trackerMode($cfg) === 'whitelist' ? (isHashWhitelisted($db, $hash) !== null) : null;

if ((!$report || !$report['blocked']) && !$bannedNoReport) {
    // Always return "not blocked" — never reveal whether reports exist
    jsonResponse([
        'success' => true,
        'infoHash' => $hash,
        'blocked' => false,
        'whitelisted' => $whitelisted,
        'captcha_solved' => wasCaptchaJustSolved(),
    ]);
}

jsonResponse([
    'success' => true,
    'infoHash' => $hash,
    'blocked' => true,
    'company' => ($report && $report['blocked']) ? $report['company'] : null,
    'representative' => ($report && $report['blocked']) ? $report['representative'] : null,
    'whitelisted' => $whitelisted,
    'captcha_solved' => wasCaptchaJustSolved(),
]);
