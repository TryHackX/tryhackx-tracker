<?php
// Both GET and POST answer. POST is what the status page's form sends, and it asks for a CAPTCHA when the
// `block_check` context is on. GET never does — it is the old address scripts and bookmarks use, and it stays
// (the owner's decision for 1.74.0, PUB-6): the answer is read-only (blocked or not, and by whom, for ONE hash) and
// the same for everybody, so what guards it is the per-address limit below, counted by address GROUP since 1.74.0
// (an IPv6 host is its /64 — before, every address of a /64 was a fresh count). README "Rate limits" says so.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = readJsonBody();
    $hash = strtolower(trim(strInput($input, 'hash')));

    // CAPTCHA (smart)
    if (isCaptchaRequired($cfg, 'block_check')) {
        if (!verifyCaptcha(captchaTokenFromInput($input), $cfg)) {
            jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
        }
        onCaptchaSolved();
    }
} else {
    $hash = strtolower(trim(strInput($_GET, 'hash')));
}

if (!isValidInfoHash($hash)) {
    jsonResponse(['error' => __('api.check.invalid_info_hash')], 400);
}

// Per-address-group rate limit (defence against automated blacklist scraping).
if (!rateLimitAllow('block_check', ipBucket(getClientIp($cfg)), (int)($cfg['rate_limit_block_check'] ?? 30))) {
    jsonResponse(['error' => __('api.check.too_many_lookups')], 429);
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
