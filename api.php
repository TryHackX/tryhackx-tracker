<?php
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_samesite' => 'Lax',
]);

if (!file_exists(__DIR__ . '/config/installed.lock')) {
    http_response_code(503);
    echo json_encode(['error' => 'Not installed']);
    exit;
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mail.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDb();
$cfg = getSettings($db);

$endpoint = $_GET['endpoint'] ?? $_GET['action'] ?? '';
$endpoint = preg_replace('/[^a-z0-9_\/\-]/', '', strtolower($endpoint));

// Background janitors run on normal API traffic but are skipped for the high-frequency pollers —
// the stats poller and the admin tracker-service status poll are both hit repeatedly and have
// nothing to do with the report/appeal janitors, so running them there is pure overhead. They
// still run everywhere else.
if (!in_array($endpoint, ['tracker_stats', 'admin/tracker_service_status'], true)) {
    autoArchiveOldReports($db, $cfg);
    autoArchiveOldAppeals($db, $cfg);
    pruneOldSentEmails($db, $cfg);
}

$apiRoutes = [
    'submit_report'         => 'api/submit_report.php',
    'check_status'          => 'api/check_status.php',
    'check_block'           => 'api/check_block.php',
    'submit_appeal'         => 'api/submit_appeal.php',
    'unsubscribe'           => 'api/unsubscribe.php',
    'save_email_preferences' => 'api/save_email_preferences.php',
    'transparency'          => 'api/transparency.php',
    'tracker_stats'         => 'api/tracker_stats.php',
    'admin/login'           => 'api/admin/login.php',
    'admin/logout'          => 'api/admin/logout.php',
    'admin/fetch_reports'   => 'api/admin/fetch_reports.php',
    'admin/change_status'   => 'api/admin/change_status.php',
    'admin/block_hash'      => 'api/admin/block_hash.php',
    'admin/unblock_hash'    => 'api/admin/unblock_hash.php',
    'admin/send_email'      => 'api/admin/send_email.php',
    'admin/delete_report'   => 'api/admin/delete_report.php',
    'admin/delete_all'      => 'api/admin/delete_all.php',
    'admin/save_settings'   => 'api/admin/save_settings.php',
    'admin/change_password' => 'api/admin/change_password.php',
    'admin/check_blacklist' => 'api/admin/check_blacklist.php',
    'admin/notify_review'   => 'api/admin/notify_review.php',
    'admin/restore_report'  => 'api/admin/restore_report.php',
    'admin/fetch_appeals'   => 'api/admin/fetch_appeals.php',
    'admin/resolve_appeal'  => 'api/admin/resolve_appeal.php',
    'admin/block_archived'  => 'api/admin/block_archived.php',
    'admin/restore_appeal'  => 'api/admin/restore_appeal.php',
    'admin/update_field'    => 'api/admin/update_field.php',
    'admin/delete_permanently' => 'api/admin/delete_permanently.php',
    'admin/tracker_service_status' => 'api/admin/tracker_service_status.php',
    'admin/restart_tracker' => 'api/admin/restart_tracker.php',
    'admin/reload_tracker'  => 'api/admin/reload_tracker.php',
    'admin/test_tracker_permission' => 'api/admin/test_tracker_permission.php',
];

if (!isset($apiRoutes[$endpoint])) {
    jsonResponse(['error' => 'Unknown endpoint'], 404);
}

if (str_starts_with($endpoint, 'admin/') && $endpoint !== 'admin/login') {
    requireAuth($cfg);
    // CSRF: every admin write (non-GET) must carry a valid X-CSRF-Token header.
    // Reads (GET: fetch_reports/fetch_appeals) are exempt. Login uses its own body token.
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !verifyCsrfHeader()) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
}

require_once __DIR__ . '/' . $apiRoutes[$endpoint];
