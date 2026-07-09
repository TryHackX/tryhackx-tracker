<?php
requirePost();

$input = readJsonBody();

// reCAPTCHA (smart)
if (isCaptchaRequired($cfg, 'status')) {
    $recaptcha = $input['g-recaptcha-response'] ?? '';
    if (!verifyRecaptcha($recaptcha, $cfg)) {
        jsonResponse(['error' => 'reCAPTCHA verification failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

$query = trim($input['search_query'] ?? '');
$email = trim($input['email'] ?? '');

if (empty($query)) {
    jsonResponse(['error' => 'Please provide a report number or info hash'], 400);
}

// Email is always required — prevents information leakage
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Email address is required to check report status'], 400);
}

// Per-IP rate limit: report IDs are sequential, so without a throttle an attacker could iterate
// them against a target email to confirm ownership. CAPTCHA is optional; this always applies.
if (!rateLimitAllow('status', getClientIp($cfg), (int)($cfg['rate_limit_status'] ?? 20))) {
    jsonResponse(['error' => 'Too many status checks. Please wait a while and try again.'], 429);
}

// maskName() / maskEmail() live in includes/functions.php (shared + unit-tested).

$report = null;
$isArchived = false;
$cols = 'id, name, email, company, representative, objectTitle, link, infoHash, magnet_link, checked, blocked, timestamp';

// Determine if query is an info hash (40 hex chars) or a report number
if (preg_match('/^[a-fA-F0-9]{40}$/', $query)) {
    // Search by info hash + email (must match)
    $hashLower = strtolower($query);

    $stmt = $db->prepare("SELECT $cols FROM reports WHERE infoHash = ? AND email = ?");
    $stmt->execute([$hashLower, $email]);
    $report = $stmt->fetch();

    if (!$report) {
        $stmt = $db->prepare("SELECT $cols FROM archives WHERE infoHash = ? AND email = ?");
        $stmt->execute([$hashLower, $email]);
        $report = $stmt->fetch();
        if ($report) $isArchived = true;
    }
} else {
    // Search by report ID + email (must match)
    $reportId = (int)$query;
    if ($reportId < 1) {
        jsonResponse(['error' => 'Invalid report number'], 400);
    }

    $stmt = $db->prepare("SELECT $cols FROM reports WHERE id = ? AND email = ?");
    $stmt->execute([$reportId, $email]);
    $report = $stmt->fetch();

    if (!$report) {
        $stmt = $db->prepare("SELECT $cols FROM archives WHERE id = ? AND email = ?");
        $stmt->execute([$reportId, $email]);
        $report = $stmt->fetch();
        if ($report) $isArchived = true;
    }
}

addCaptchaPoints($cfg, 'status');

// Generic "not found" — use 200 to avoid console errors; never reveal whether hash/report exists
if (!$report) {
    jsonResponse(['error' => 'not_found']);
}

if ($isArchived) {
    $status = 'archived';
} elseif ($report['blocked']) {
    $status = 'blocked';
} elseif ($report['checked']) {
    $status = 'checked';
} else {
    $status = 'pending';
}

// Always full access — email was verified by the query match
$response = [
    'success' => true,
    'id' => (int)$report['id'],
    'name' => maskName($report['name']),
    'email' => maskEmail($report['email']),
    'company' => $report['company'],
    'representative' => $report['representative'],
    'objectTitle' => $report['objectTitle'],
    'link' => $report['link'],
    'infoHash' => $report['infoHash'],
    'status' => $status,
    'blocked' => (bool)$report['blocked'],
    'checked' => (bool)$report['checked'],
    'archived' => $isArchived,
    'timestamp' => $report['timestamp'],
    'full_access' => true,
];
if (!empty($report['magnet_link'])) {
    $response['magnet_link'] = $report['magnet_link'];
}

$response['captcha_solved'] = wasCaptchaJustSolved();
jsonResponse($response);
