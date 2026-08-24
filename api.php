<?php
// Resolve the endpoint BEFORE starting a session: server-to-server calls (v1/*) are stateless and
// must not create a PHP session per request.
$endpoint = $_GET['endpoint'] ?? $_GET['action'] ?? '';
$endpoint = preg_replace('/[^a-z0-9_\/\-]/', '', strtolower($endpoint));
$isS2S = str_starts_with($endpoint, 'v1/');

if (!$isS2S) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Lax',
    ]);
}

if (!file_exists(__DIR__ . '/config/installed.lock')) {
    http_response_code(503);
    echo json_encode(['error' => 'Not installed']);
    exit;
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/whitelist.php';
require_once __DIR__ . '/includes/schedule.php';
require_once __DIR__ . '/includes/stats_timeline.php';
require_once __DIR__ . '/includes/index.php';
require_once __DIR__ . '/includes/api_auth.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/federation.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);

// Background janitors run on normal API traffic but are skipped for the high-frequency pollers —
// the stats poller and the admin tracker-service status poll are both hit repeatedly and have
// nothing to do with the report/appeal janitors, so running them there is pure overhead. They
// still run everywhere else. S2S calls never run them either.
if (!$isS2S && !in_array($endpoint, ['tracker_stats', 'stats_timeline', 'admin/tracker_service_status', 'admin/whitelist_status', 'admin/index_status'], true)) {
    autoArchiveOldReports($db, $cfg);
    autoArchiveOldAppeals($db, $cfg);
    pruneOldSentEmails($db, $cfg);
}
// The whitelist janitor is cheap (one small state-file read) and deliberately runs on EVERY request,
// pollers included, so a debounced tracker reload fires even on a quiet site.
whitelistJanitor($db, $cfg);

$apiRoutes = [
    'submit_report'         => 'api/submit_report.php',
    'check_status'          => 'api/check_status.php',
    'check_block'           => 'api/check_block.php',
    'submit_appeal'         => 'api/submit_appeal.php',
    'unsubscribe'           => 'api/unsubscribe.php',
    'save_email_preferences' => 'api/save_email_preferences.php',
    'transparency'          => 'api/transparency.php',
    'tracker_stats'         => 'api/tracker_stats.php',
    'stats_timeline'        => 'api/stats_timeline.php',
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
    // ── Whitelist (public) ──
    'whitelist_submit'      => 'api/whitelist_submit.php',
    'whitelist_check'       => 'api/whitelist_check.php',
    // ── Whitelist (admin) ──
    'admin/check_whitelist_path' => 'api/admin/check_whitelist_path.php',
    'admin/whitelist_status'     => 'api/admin/whitelist_status.php',
    'admin/fetch_whitelist'      => 'api/admin/fetch_whitelist.php',
    'admin/whitelist_item'       => 'api/admin/whitelist_item.php',
    'admin/whitelist_add'        => 'api/admin/whitelist_add.php',
    'admin/whitelist_delete'     => 'api/admin/whitelist_delete.php',
    'admin/whitelist_ban'        => 'api/admin/whitelist_ban.php',
    'admin/whitelist_unban'      => 'api/admin/whitelist_unban.php',
    'admin/whitelist_fetch_meta' => 'api/admin/whitelist_fetch_meta.php',
    'admin/whitelist_scrape'     => 'api/admin/whitelist_scrape.php',
    'admin/whitelist_scrape_bulk' => 'api/admin/whitelist_scrape_bulk.php',
    'admin/whitelist_meta_queue' => 'api/admin/whitelist_meta_queue.php',
    'admin/whitelist_regenerate' => 'api/admin/whitelist_regenerate.php',
    'admin/whitelist_import_blacklist' => 'api/admin/whitelist_import_blacklist.php',
    'admin/fetch_banned'         => 'api/admin/fetch_banned.php',
    'admin/banned_add'           => 'api/admin/banned_add.php',
    // ── Observed-hash index (admin) ──
    'admin/fetch_index'          => 'api/admin/fetch_index.php',
    'admin/index_item'           => 'api/admin/index_item.php',
    'admin/index_delete'         => 'api/admin/index_delete.php',
    'admin/index_promote'        => 'api/admin/index_promote.php',
    'admin/index_fetch_meta'     => 'api/admin/index_fetch_meta.php',
    'admin/index_scrape'         => 'api/admin/index_scrape.php',
    'admin/index_scrape_bulk'    => 'api/admin/index_scrape_bulk.php',
    'admin/index_status'         => 'api/admin/index_status.php',
    'admin/index_poll_now'       => 'api/admin/index_poll_now.php',
    // ── API clients / bans (admin) ──
    'admin/fetch_api_clients'    => 'api/admin/fetch_api_clients.php',
    'admin/api_client_create'    => 'api/admin/api_client_create.php',
    'admin/api_client_update'    => 'api/admin/api_client_update.php',
    'admin/api_client_delete'    => 'api/admin/api_client_delete.php',
    'admin/fetch_api_bans'       => 'api/admin/fetch_api_bans.php',
    'admin/api_ban_lift'         => 'api/admin/api_ban_lift.php',
    'admin/api_ban_add'          => 'api/admin/api_ban_add.php',
    // ── User accounts (public; includes/users.php) ──
    'user_register'              => 'api/user_register.php',
    'user_login'                 => 'api/user_login.php',
    'user_logout'                => 'api/user_logout.php',
    'user_me'                    => 'api/user_me.php',
    'user_update'                => 'api/user_update.php',
    'user_notifications'         => 'api/user_notifications.php',
    'user_reset_request'         => 'api/user_reset_request.php',
    'user_reset_confirm'         => 'api/user_reset_confirm.php',
    'user_verify_send'           => 'api/user_verify_send.php',
    'user_email_prefs'           => 'api/user_email_prefs.php',
    'index_search'               => 'api/index_search.php',
    'index_files'                => 'api/index_files.php',
    // ── User accounts (admin) ──
    'admin/fetch_users'          => 'api/admin/fetch_users.php',
    'admin/user_update'          => 'api/admin/user_update.php',
    'admin/user_delete'          => 'api/admin/user_delete.php',
    'admin/user_grant'           => 'api/admin/user_grant.php',
    'admin/user_revoke'          => 'api/admin/user_revoke.php',
    'admin/user_notify'          => 'api/admin/user_notify.php',
    'admin/fetch_groups'         => 'api/admin/fetch_groups.php',
    'admin/group_save'           => 'api/admin/group_save.php',
    'admin/group_delete'         => 'api/admin/group_delete.php',
    // ── Federation peers (admin; includes/federation.php) ──
    'admin/fetch_fed_peers'      => 'api/admin/fetch_fed_peers.php',
    'admin/fed_peer_save'        => 'api/admin/fed_peer_save.php',
    'admin/fed_peer_delete'      => 'api/admin/fed_peer_delete.php',
    'admin/fed_peer_test'        => 'api/admin/fed_peer_test.php',
    // ── Server-to-server API (bearer key; see includes/api_auth.php) ──
    'v1/whitelist/submit'        => 'api/v1/whitelist_submit.php',
    'v1/whitelist/ping'          => 'api/v1/whitelist_ping.php',
    'v1/users/lookup'            => 'api/v1/users_lookup.php',
    'v1/users/grant'             => 'api/v1/users_grant.php',
    'v1/users/revoke'            => 'api/v1/users_revoke.php',
    'v1/users/provision'         => 'api/v1/users_provision.php',
    'v1/federation/ping'         => 'api/v1/federation_ping.php',
    'v1/federation/export'       => 'api/v1/federation_export.php',
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
