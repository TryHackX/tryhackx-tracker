<?php
requirePost();

$input = readJsonBody();

// CSRF
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

// reCAPTCHA (smart)
if (isCaptchaRequired($cfg, 'report')) {
    $recaptcha = $input['g-recaptcha-response'] ?? '';
    if (!verifyRecaptcha($recaptcha, $cfg)) {
        jsonResponse(['error' => 'reCAPTCHA verification failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

// Sanitize & validate
$name = sanitize($input['name'] ?? '');
$representative = sanitize($input['representative'] ?? '');
$company = sanitize($input['company'] ?? '');
$email = trim($input['email'] ?? '');
$objectTitle = sanitize($input['objectTitle'] ?? '');
$link = trim($input['link'] ?? '');
$infoHash = strtolower(trim($input['infoHash'] ?? ''));
$rawMagnet = trim($input['magnet_link'] ?? '');
$rawMessage = trim($input['add_message'] ?? '');

// Required fields
$errors = [];
if (empty($name)) $errors[] = 'name';
if (empty($representative)) $errors[] = 'representative';
if (empty($company)) $errors[] = 'company';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (empty($objectTitle)) $errors[] = 'objectTitle';
if (!filter_var($link, FILTER_VALIDATE_URL)) $errors[] = 'link';
if (!isValidInfoHash($infoHash)) $errors[] = 'infoHash';

// Validate magnet link (optional, but if provided must be valid and match infoHash)
$magnetLink = null;
$maxMagnetLen = (int)($cfg['max_magnet_link_length'] ?? 0);
if ($rawMagnet !== '' && $maxMagnetLen > 0 && mb_strlen($rawMagnet) > $maxMagnetLen) {
    jsonResponse(['error' => 'Magnet link too long (max ' . $maxMagnetLen . ' characters)', 'fields' => ['magnet_link']], 400);
}
if ($rawMagnet !== '') {
    if (!preg_match('/^magnet:\?/', $rawMagnet) || !preg_match('/[?&]xt=urn:btih:/i', $rawMagnet)) {
        $errors[] = 'magnet_link';
    } else {
        // Extract hash from magnet link (hex40 or base32)
        $magnetHash = null;
        if (preg_match('/urn:btih:([a-fA-F0-9]{40})/i', $rawMagnet, $m)) {
            $magnetHash = strtolower($m[1]);
        } elseif (preg_match('/urn:btih:([A-Z2-7]{32})/i', $rawMagnet, $m)) {
            $decoded = @base32ToHex($m[1]);
            if ($decoded && strlen($decoded) === 40) $magnetHash = strtolower($decoded);
        }
        if (!$magnetHash) {
            $errors[] = 'magnet_link';
        } elseif ($magnetHash !== $infoHash) {
            $errors[] = 'magnet_link';
        } else {
            $magnetLink = $rawMagnet;
        }
    }
}

if ($errors) {
    jsonResponse(['error' => 'Validation failed', 'fields' => $errors], 400);
}

// Length limits (check raw input before sanitization to avoid htmlspecialchars inflation)
$maxMsg = (int)($cfg['max_message_length'] ?? 2000);
if (mb_strlen($name) > 255 || mb_strlen($representative) > 255 || mb_strlen($company) > 255 ||
    mb_strlen($objectTitle) > 255 || mb_strlen($link) > 500) {
    jsonResponse(['error' => 'Field too long', 'fields' => ['length']], 400);
}
if (mb_strlen($rawMessage) > $maxMsg) {
    jsonResponse(['error' => 'Message too long (max ' . $maxMsg . ' characters)', 'fields' => ['add_message']], 400);
}
$message = sanitize($rawMessage);

$ip = getClientIp();
$maxPerHour = (int)($cfg['rate_limit'] ?? 5);

// Rate limit
if (!checkRateLimit($db, $ip, $maxPerHour)) {
    jsonResponse(['error' => 'rate_limit'], 429);
}

// Duplicate check
$stmt = $db->prepare("SELECT id FROM reports WHERE infoHash = ?");
$stmt->execute([$infoHash]);
if ($stmt->fetch()) {
    jsonResponse(['error' => 'duplicate'], 409);
}

// Check if hash is already on the blacklist file
$blacklistPath = $cfg['blacklist_path'] ?? '';
if (isHashInBlacklist($infoHash, $blacklistPath)) {
    jsonResponse(['error' => 'This info hash is already blocked on the tracker.', 'fields' => ['infoHash']], 409);
}

// Insert
$stmt = $db->prepare(
    "INSERT INTO reports (name, representative, company, email, objectTitle, link, infoHash, magnet_link, ip, add_message, checked, blocked, timestamp)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())"
);
$stmt->execute([$name, $representative, $company, $email, $objectTitle, $link, $infoHash, $magnetLink, $ip, $message]);
$reportId = (int)$db->lastInsertId();

// Send submission confirmation email (non-blocking — don't fail the response if mail fails)
// Use output buffering to prevent any stray PHP warnings from corrupting the JSON response
try {
    ob_start();
    @sendSubmissionConfirmation($db, $reportId, $cfg);
    ob_end_clean();
} catch (\Throwable $e) {
    if (ob_get_level()) ob_end_clean();
}

addCaptchaPoints($cfg, 'report');
jsonResponse(['success' => true, 'id' => $reportId, 'captcha_solved' => wasCaptchaJustSolved()]);
