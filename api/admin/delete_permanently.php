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
        jsonResponse(['error' => __('api.report.locked_out_remaining', ['minutes' => $timeLeft])], 403);
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
        if (!verifyCaptcha(captchaTokenFromInput($input), $cfg)) {
            jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
        }
        onCaptchaSolved();
    }
}

if ($id < 1) {
    jsonResponse(['error' => __('api.report.invalid_id')], 400);
}

if (empty($password)) {
    jsonResponse(['error' => __('api.report.admin_password_required')], 400);
}

// The password itself goes through the one shared check, so this action is throttled and signs the
// session out on repeat failures like every other. The timed cool-down below is kept on top of it:
// permanent deletion is the one action here with no undo at all, and a pause is worth more than a
// clearer error message.
$reauth = adminReauth($password, $cfg);
if (!$reauth['ok']) {
    if ($reauth['locked_out']) {
        jsonResponse(['error' => $reauth['error'], 'signed_out' => true], 401);
    }
    $_SESSION['delete_last_attempt_time'] = time();
    $_SESSION['delete_attempts'] = ($deleteAttempts + 1);
    $lockoutAttempts = (int)($cfg['delete_lockout_attempts'] ?? 5);
    if ($_SESSION['delete_attempts'] >= $lockoutAttempts) {
        $lockoutMinutes = (int)($cfg['delete_lockout_minutes'] ?? 60);
        $_SESSION['delete_lockout_until'] = time() + ($lockoutMinutes * 60);
        jsonResponse(['error' => __('api.report.incorrect_password_locked_out', ['minutes' => $lockoutMinutes])], 403);
    }
    jsonResponse(['error' => $reauth['error'] . ' ' . __('api.report.failed_attempts', ['n' => $_SESSION['delete_attempts'], 'max' => $lockoutAttempts]),
                  'attempts_left' => $reauth['left']], 403);
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
    jsonResponse(['error' => __('api.report.not_found')], 404);
}

// Remove hash from blacklist file if it was blocked and no other blocked reports exist for it
$blacklistChanged = false;
if ($report['blocked']) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM reports WHERE infoHash = ? AND blocked = 1 AND id != ?");
    $stmt->execute([$report['infoHash'], $id]);
    $otherBlockedReports = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM archives WHERE infoHash = ? AND blocked = 1 AND id != ?");
    $stmt->execute([$report['infoHash'], $id]);
    $otherBlockedArchives = (int)$stmt->fetchColumn();

    if ($otherBlockedReports === 0 && $otherBlockedArchives === 0) {
        $unblock = trackerUnblockHash($db, $cfg, $report['infoHash']);
        $blacklistChanged = $unblock['file_ok'];
        $unblockReload = $unblock['reload'] ?? null;
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
    jsonResponse(['error' => __('api.report.delete_db_error')], 500);
}

// The tracker list changed — reload status came from the mode-aware unblock helper.
$reload = $unblockReload ?? null;

$response = [
    'success' => true,
    'message' => __('api.report.deleted_permanently', ['notified' => $emailSent ? __('api.report.yes') : __('api.report.no')])
];
if ($reload) $response['reload'] = $reload;
jsonResponse($response);
