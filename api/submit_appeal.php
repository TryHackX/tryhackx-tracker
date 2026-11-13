<?php
requirePost();

$input = readJsonBody();

// CSRF
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}

// Per-address-group rate limit (appeals were previously unthrottled — open to spam flooding the queue). An IPv6
// host is its /64 (ipBucket(), 1.74.0): counted by the full address, every address of it was a fresh count.
if (!rateLimitAllow('appeal', ipBucket(getClientIp($cfg)), (int)($cfg['rate_limit_appeal'] ?? 5))) {
    jsonResponse(['error' => 'rate_limit'], 429);
}

// CAPTCHA (smart)
if (isCaptchaRequired($cfg, 'appeal')) {
    if (!verifyCaptcha(captchaTokenFromInput($input), $cfg)) {
        jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

// Sanitize & validate (strInput(), 1.74.0: an array where a string belongs is an empty field, not a TypeError)
$infoHash = strtolower(trim(strInput($input, 'infoHash')));
$reportId = (int)strInput($input, 'report_id', '0');
$appealType = trim(strInput($input, 'appeal_type', 'unblock'));
$name = sanitize(trim(strInput($input, 'name')));
$email = trim(strInput($input, 'email'));
$rawMessage = trim(strInput($input, 'message'));

$errors = [];
if (!isValidInfoHash($infoHash)) $errors[] = 'infoHash';
if (empty($name)) $errors[] = 'name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (empty($rawMessage)) $errors[] = 'message';
if (!in_array($appealType, ['unblock', 'block'], true)) $appealType = 'unblock';

if ($errors) {
    jsonResponse(['error' => __('api.common.validation_failed'), 'fields' => $errors], 400);
}

// If report_id is provided, verify it exists and matches infoHash
if ($reportId > 0) {
    $report = null;
    $stmt = $db->prepare("SELECT id FROM reports WHERE id = ? AND infoHash = ?");
    $stmt->execute([$reportId, $infoHash]);
    $report = $stmt->fetch();
    if (!$report) {
        $stmt = $db->prepare("SELECT id FROM archives WHERE id = ? AND infoHash = ?");
        $stmt->execute([$reportId, $infoHash]);
        $report = $stmt->fetch();
    }
    if (!$report) {
        jsonResponse(['error' => __('api.appeal.report_mismatch'), 'fields' => ['report_id']], 404);
    }
} else {
    // Verify hash exists in reports or archives
    $stmt = $db->prepare("SELECT id FROM reports WHERE infoHash = ? LIMIT 1");
    $stmt->execute([$infoHash]);
    $report = $stmt->fetch();
    if (!$report) {
        $stmt = $db->prepare("SELECT id FROM archives WHERE infoHash = ? LIMIT 1");
        $stmt->execute([$infoHash]);
        $report = $stmt->fetch();
    }
    if (!$report) {
        jsonResponse(['error' => __('api.appeal.no_report_for_hash')], 404);
    }
    $reportId = (int)$report['id'];
}

// Rate limit (reuse existing) — this address GROUP's appeals in the last hour (ipBucketSql(), 1.74.0)
$ip = getClientIp($cfg);
$maxPerHour = (int)($cfg['rate_limit'] ?? 5);
$where = ipBucketSql('ip', $ip);   // a literal condition on the literal column; the address is bound
$stmt = $db->prepare("SELECT COUNT(*) FROM appeals WHERE " . $where['sql'] . " AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmt->execute([$where['arg']]);
if ((int)$stmt->fetchColumn() >= $maxPerHour) {
    jsonResponse(['error' => 'rate_limit'], 429);
}

// Duplicate check — no pending appeal for same hash+email
$stmt = $db->prepare("SELECT id FROM appeals WHERE infoHash = ? AND email = ? AND status = 'pending'");
$stmt->execute([$infoHash, $email]);
if ($stmt->fetch()) {
    jsonResponse(['error' => __('api.appeal.pending_exists')], 409);
}

// Message length
$maxMsg = (int)($cfg['max_appeal_message_length'] ?? $cfg['max_message_length'] ?? 2000);
if (mb_strlen($rawMessage) > $maxMsg) {
    jsonResponse(['error' => __('api.report.message_too_long', ['max' => $maxMsg]), 'fields' => ['message']], 400);
}
$message = sanitize($rawMessage);

// Reject a duplicate pending appeal of the same type for this hash from the same email — stops
// one appellant re-submitting the same request repeatedly and flooding the review queue.
$dupStmt = $db->prepare("SELECT id FROM appeals WHERE infoHash = ? AND email = ? AND appeal_type = ? AND status = 'pending' LIMIT 1");
$dupStmt->execute([$infoHash, $email, $appealType]);
if ($dupStmt->fetch()) {
    jsonResponse(['error' => __('api.appeal.pending_exists_review')], 409);
}

// Insert
$stmt = $db->prepare(
    "INSERT INTO appeals (infoHash, report_id, name, email, message, appeal_type, status, ip, timestamp)
     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
);
$stmt->execute([$infoHash, $reportId, $name, $email, $message, $appealType, $ip]);

$appealId = (int)$db->lastInsertId();

// Send confirmation email to appellant — while today's site-wide count of confirmation mails is under
// `confirm_mail_daily_cap` (confirmMailAllow(), 1.74.0, PUB-1); the appeal itself is taken either way.
try {
    if (confirmMailAllow($cfg)) @sendAppealConfirmation($db, $appealId, $cfg);
} catch (\Throwable $e) {
    // Email failure should not block the appeal submission
}

addCaptchaPoints($cfg, 'appeal');
jsonResponse(['success' => true, 'id' => $appealId, 'captcha_solved' => wasCaptchaJustSolved()]);
