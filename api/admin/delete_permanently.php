<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$password = $input['password'] ?? '';
$reason = trim($input['reason'] ?? '');
$source = trim($input['source'] ?? 'reports');

// Check lockout
if (isset($_SESSION['delete_lockout_until'])) {
    if (time() < $_SESSION['delete_lockout_until']) {
        $timeLeft = ceil(($_SESSION['delete_lockout_until'] - time()) / 60);
        jsonResponse(['error' => "Too many failed attempts. Locked out for another $timeLeft minutes."], 403);
    } else {
        // Lockout expired, reset attempts counter
        unset($_SESSION['delete_attempts']);
        unset($_SESSION['delete_lockout_until']);
        unset($_SESSION['delete_last_attempt_time']);
    }
}

// Check if the last failed attempt was more than 15 minutes (900 seconds) ago
$lastAttemptTime = $_SESSION['delete_last_attempt_time'] ?? 0;
if ($lastAttemptTime > 0 && (time() - $lastAttemptTime) > 900) {
    unset($_SESSION['delete_attempts']);
    unset($_SESSION['delete_last_attempt_time']);
}

// Check CAPTCHA trigger (after X failed attempts)
$deleteAttempts = $_SESSION['delete_attempts'] ?? 0;
$captchaAttempts = (int)($cfg['delete_captcha_attempts'] ?? 2);
if ($deleteAttempts >= $captchaAttempts && isRecaptchaEnabled($cfg, 'login')) {
    $grace = (int)($cfg['captcha_grace_minutes'] ?? 5);
    $inGrace = isset($_SESSION['captcha_solved_at']) && (time() - $_SESSION['captcha_solved_at']) < $grace * 60;
    
    if (!$inGrace) {
        $recaptcha = $input['g-recaptcha-response'] ?? '';
        if (!verifyRecaptcha($recaptcha, $cfg)) {
            jsonResponse(['error' => 'reCAPTCHA verification failed', 'captcha_required' => true], 400);
        }
        onCaptchaSolved();
    }
}

if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

if (empty($password)) {
    jsonResponse(['error' => 'Admin password is required'], 400);
}

// Verify admin password
if (!password_verify($password, ADMIN_PASSWORD_HASH)) {

    // Record time of last failed attempt
    $_SESSION['delete_last_attempt_time'] = time();

    // Increment attempts
    $_SESSION['delete_attempts'] = ($deleteAttempts + 1);
    
    // Check if lockout limit is reached (X failed attempts)
    $lockoutAttempts = (int)($cfg['delete_lockout_attempts'] ?? 5);
    if ($_SESSION['delete_attempts'] >= $lockoutAttempts) {
        $lockoutMinutes = (int)($cfg['delete_lockout_minutes'] ?? 60);
        $_SESSION['delete_lockout_until'] = time() + ($lockoutMinutes * 60); // X minutes lockout
        jsonResponse(['error' => "Incorrect admin password. Too many failed attempts, locked out for $lockoutMinutes minutes."], 403);
    }
    
    jsonResponse(['error' => 'Incorrect admin password. Failed attempts: ' . $_SESSION['delete_attempts'] . '/' . $lockoutAttempts], 403);
}

// If password verified, reset rate limit counters
unset($_SESSION['delete_attempts']);
unset($_SESSION['delete_lockout_until']);
unset($_SESSION['delete_last_attempt_time']);

// Restrict source to avoid SQL injection on table name
$table = ($source === 'archives') ? 'archives' : 'reports';

$stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    jsonResponse(['error' => 'Report not found'], 404);
}

// Remove hash from blacklist file if it was blocked and no other blocked reports exist for it
if ($report['blocked']) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM reports WHERE infoHash = ? AND blocked = 1 AND id != ?");
    $stmt->execute([$report['infoHash'], $id]);
    $otherBlockedReports = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM archives WHERE infoHash = ? AND blocked = 1 AND id != ?");
    $stmt->execute([$report['infoHash'], $id]);
    $otherBlockedArchives = (int)$stmt->fetchColumn();

    if ($otherBlockedReports === 0 && $otherBlockedArchives === 0) {
        $blacklistPath = $cfg['blacklist_path'] ?? '';
        // Security check: strip null bytes and control characters
        $blacklistPath = preg_replace('/[\x00-\x1F\x7F]/', '', $blacklistPath);
        if ($blacklistPath) {
            removeHashFromBlacklist($report['infoHash'], $blacklistPath);
        }
    }
}

// Send deletion notification to the reporter
$emailSent = false;
if (!empty($report['email'])) {
    $emailSent = sendDeletionNotification($db, $report, $reason, $cfg);
}

// Perform database deletion inside a transaction
$db->beginTransaction();
try {
    // Delete related rows from sent_emails
    $stmt = $db->prepare("DELETE FROM sent_emails WHERE report_id = ?");
    $stmt->execute([$id]);

    // Delete related rows from appeals
    $stmt = $db->prepare("DELETE FROM appeals WHERE report_id = ?");
    $stmt->execute([$id]);

    // Delete related rows from appeal_archives
    $stmt = $db->prepare("DELETE FROM appeal_archives WHERE report_id = ?");
    $stmt->execute([$id]);

    // Delete the report row itself
    $stmt = $db->prepare("DELETE FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);

    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    // Log the detail server-side; never echo raw DB errors (schema/paths) back to the client.
    error_log('delete_permanently failed: ' . $e->getMessage());
    jsonResponse(['error' => 'A database error occurred while deleting the report.'], 500);
}

jsonResponse([
    'success' => true,
    'message' => 'Report and all associated data deleted permanently. Reporter notified: ' . ($emailSent ? 'Yes' : 'No')
]);
