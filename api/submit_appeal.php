<?php
requirePost();

$input = readJsonBody();

// CSRF
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

// Per-IP rate limit (appeals were previously unthrottled — open to spam flooding the queue).
if (!rateLimitAllow('appeal', getClientIp($cfg), (int)($cfg['rate_limit_appeal'] ?? 5))) {
    jsonResponse(['error' => 'rate_limit'], 429);
}

// reCAPTCHA (smart)
if (isCaptchaRequired($cfg, 'appeal')) {
    $recaptcha = $input['g-recaptcha-response'] ?? '';
    if (!verifyRecaptcha($recaptcha, $cfg)) {
        jsonResponse(['error' => 'reCAPTCHA verification failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

// Sanitize & validate
$infoHash = strtolower(trim($input['infoHash'] ?? ''));
$reportId = (int)($input['report_id'] ?? 0);
$appealType = trim($input['appeal_type'] ?? 'unblock');
$name = sanitize(trim($input['name'] ?? ''));
$email = trim($input['email'] ?? '');
$rawMessage = trim($input['message'] ?? '');

$errors = [];
if (!isValidInfoHash($infoHash)) $errors[] = 'infoHash';
if (empty($name)) $errors[] = 'name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (empty($rawMessage)) $errors[] = 'message';
if (!in_array($appealType, ['unblock', 'block'], true)) $appealType = 'unblock';

if ($errors) {
    jsonResponse(['error' => 'Validation failed', 'fields' => $errors], 400);
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
        jsonResponse(['error' => 'Report not found or hash mismatch', 'fields' => ['report_id']], 404);
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
        jsonResponse(['error' => 'No report found for this info hash'], 404);
    }
    $reportId = (int)$report['id'];
}

// Rate limit (reuse existing)
$ip = getClientIp();
$maxPerHour = (int)($cfg['rate_limit'] ?? 5);
$stmt = $db->prepare("SELECT COUNT(*) FROM appeals WHERE ip = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmt->execute([$ip]);
if ((int)$stmt->fetchColumn() >= $maxPerHour) {
    jsonResponse(['error' => 'rate_limit'], 429);
}

// Duplicate check — no pending appeal for same hash+email
$stmt = $db->prepare("SELECT id FROM appeals WHERE infoHash = ? AND email = ? AND status = 'pending'");
$stmt->execute([$infoHash, $email]);
if ($stmt->fetch()) {
    jsonResponse(['error' => 'You already have a pending appeal for this hash.'], 409);
}

// Message length
$maxMsg = (int)($cfg['max_appeal_message_length'] ?? $cfg['max_message_length'] ?? 2000);
if (mb_strlen($rawMessage) > $maxMsg) {
    jsonResponse(['error' => 'Message too long (max ' . $maxMsg . ' characters)', 'fields' => ['message']], 400);
}
$message = sanitize($rawMessage);

// Reject a duplicate pending appeal of the same type for this hash from the same email — stops
// one appellant re-submitting the same request repeatedly and flooding the review queue.
$dupStmt = $db->prepare("SELECT id FROM appeals WHERE infoHash = ? AND email = ? AND appeal_type = ? AND status = 'pending' LIMIT 1");
$dupStmt->execute([$infoHash, $email, $appealType]);
if ($dupStmt->fetch()) {
    jsonResponse(['error' => 'You already have a pending appeal for this info hash awaiting review.'], 409);
}

// Insert
$stmt = $db->prepare(
    "INSERT INTO appeals (infoHash, report_id, name, email, message, appeal_type, status, ip, timestamp)
     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
);
$stmt->execute([$infoHash, $reportId, $name, $email, $message, $appealType, $ip]);

$appealId = (int)$db->lastInsertId();

// Send confirmation email to appellant
try {
    @sendAppealConfirmation($db, $appealId, $cfg);
} catch (\Throwable $e) {
    // Email failure should not block the appeal submission
}

addCaptchaPoints($cfg, 'appeal');
jsonResponse(['success' => true, 'id' => $appealId, 'captcha_solved' => wasCaptchaJustSolved()]);
