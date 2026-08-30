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
require_once __DIR__ . '/includes/twofa.php';
require_once __DIR__ . '/includes/bulkmail.php';
require_once __DIR__ . '/includes/richtext.php';
require_once __DIR__ . '/includes/livesync.php';
require_once __DIR__ . '/includes/reputation.php';
require_once __DIR__ . '/includes/wlmaint.php';
require_once __DIR__ . '/includes/wlprobe.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/federation.php';
require_once __DIR__ . '/includes/netlimit.php';
require_once __DIR__ . '/includes/backup.php';
require_once __DIR__ . '/includes/opentracker.php';
require_once __DIR__ . '/includes/sysctl.php';
require_once __DIR__ . '/includes/cluster.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDb();
// A ceiling on how long ONE query may run inside a web request. Not a substitute for writing the
// query properly — a bad plan is still a bug — but the difference between a bad plan costing one
// visitor an error page and it holding a php-fpm child until the whole site stops answering. The
// pool here has five children; one search that ran for twenty-four minutes was enough to take
// every page down, and each retry started another.
//
// SESSION only, and only for requests served over the web: the janitor, the metadata worker and
// mariadb-dump all run legitimately long statements from the CLI and must not be touched. MariaDB
// applies it to SELECTs; anything it cannot apply to is left alone.
if (PHP_SAPI !== 'cli') {
    try { $db->exec('SET SESSION max_statement_time = 20'); } catch (\Throwable $e) { /* older server: no such variable */ }
}

$cfg = getSettings($db);
ensureSchema($db, $cfg);

// Background janitors run on normal API traffic but are skipped for the high-frequency pollers —
// the stats poller and the admin tracker-service status poll are both hit repeatedly and have
// nothing to do with the report/appeal janitors, so running them there is pure overhead. They
// still run everywhere else. S2S calls never run them either.
if (!$isS2S && !in_array($endpoint, ['tracker_stats', 'stats_timeline', 'admin/tracker_service_status', 'admin/whitelist_status', 'admin/index_status', 'admin/net_status', 'admin/backup_status', 'admin/ot_status', 'admin/sysctl_status', 'admin/ot_cluster_status'], true)) {
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
    'admin/login_2fa'       => 'api/admin/login_2fa.php',
    'admin/twofa'           => 'api/admin/twofa.php',
    'admin/logout'          => 'api/admin/logout.php',
    'admin/fetch_reports'   => 'api/admin/fetch_reports.php',
    'admin/change_status'   => 'api/admin/change_status.php',
    'admin/block_hash'      => 'api/admin/block_hash.php',
    'admin/unblock_hash'    => 'api/admin/unblock_hash.php',
    'admin/send_email'      => 'api/admin/send_email.php',
    'admin/delete_report'   => 'api/admin/delete_report.php',
    'admin/delete_all'      => 'api/admin/delete_all.php',
    'admin/save_settings'   => 'api/admin/save_settings.php',
    'admin/settings_catalog' => 'api/admin/settings_catalog.php',
    'admin/change_password' => 'api/admin/change_password.php',
    'admin/account_email'   => 'api/admin/account_email.php',
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
    // ── Inbound UDP monitor + rate limit (admin; includes/netlimit.php) ──
    'admin/net_status'      => 'api/admin/net_status.php',
    'admin/ot_status'       => 'api/admin/ot_status.php',
    'admin/ot_apply'        => 'api/admin/ot_apply.php',
    'admin/ot_test'         => 'api/admin/ot_test.php',
    // ── Kernel network buffers (admin; includes/sysctl.php) ──
    'admin/sysctl_status'   => 'api/admin/sysctl_status.php',
    'admin/sysctl_apply'    => 'api/admin/sysctl_apply.php',
    'admin/sysctl_test'     => 'api/admin/sysctl_test.php',
    // -- Extra opentracker instances (admin; includes/cluster.php) --
    'admin/ot_cluster_status' => 'api/admin/ot_cluster_status.php',
    'admin/ot_cluster_apply'  => 'api/admin/ot_cluster_apply.php',
    'admin/ot_cluster_test'   => 'api/admin/ot_cluster_test.php',
    'admin/net_samples'     => 'api/admin/net_samples.php',
    'admin/net_apply'       => 'api/admin/net_apply.php',
    'admin/net_test'        => 'api/admin/net_test.php',
    // ── Backups (admin; includes/backup.php) ──
    'admin/backup_status'   => 'api/admin/backup_status.php',
    'admin/backup_action'   => 'api/admin/backup_action.php',
    'admin/backup_test_path' => 'api/admin/backup_test_path.php',
    'admin/backup_download' => 'api/admin/backup_download.php',
    // ── Whitelist (public) ──
    'whitelist_submit'      => 'api/whitelist_submit.php',
    'whitelist_check'       => 'api/whitelist_check.php',
    // ── Whitelist (admin) ──
    'admin/check_whitelist_path' => 'api/admin/check_whitelist_path.php',
    'admin/whitelist_status'     => 'api/admin/whitelist_status.php',
    'admin/tracker_mode'         => 'api/admin/tracker_mode.php',
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
    'index_info'                 => 'api/index_info.php',
    'richtext_preview'           => 'api/richtext_preview.php',
    'rate_hash'                  => 'api/rate_hash.php',
    'whitelist_probe'            => 'api/whitelist_probe.php',
    // ── User accounts (admin) ──
    'admin/fetch_users'          => 'api/admin/fetch_users.php',
    'admin/user_update'          => 'api/admin/user_update.php',
    'admin/user_delete'          => 'api/admin/user_delete.php',
    'admin/user_grant'           => 'api/admin/user_grant.php',
    'admin/user_revoke'          => 'api/admin/user_revoke.php',
    'admin/user_notify'          => 'api/admin/user_notify.php',
    'admin/bulk_send'            => 'api/admin/bulk_send.php',
    'admin/wl_content'           => 'api/admin/wl_content.php',
    'admin/livesync_test'        => 'api/admin/livesync_test.php',
    'admin/livesync_apply'       => 'api/admin/livesync_apply.php',
    'admin/fetch_groups'         => 'api/admin/fetch_groups.php',
    'admin/group_save'           => 'api/admin/group_save.php',
    'admin/group_delete'         => 'api/admin/group_delete.php',
    // ── Federation peers (admin; includes/federation.php) ──
    'admin/fetch_fed_peers'      => 'api/admin/fetch_fed_peers.php',
    'admin/fed_peer_save'        => 'api/admin/fed_peer_save.php',
    'admin/fed_peer_delete'      => 'api/admin/fed_peer_delete.php',
    'admin/fed_peer_test'        => 'api/admin/fed_peer_test.php',
    'admin/fed_review'           => 'api/admin/fed_review.php',
    'admin/fed_purge'            => 'api/admin/fed_purge.php',
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

// admin/login_2fa is the second half of a sign-in, so by definition there is no session yet. It has
// its own gate instead: it only works from a session that has just passed the password step, only
// for five minutes, it carries its own CSRF token in the body like admin/login, and every failure
// counts against the same brute-force lockout.
/**
 * Which panel permission each admin endpoint needs.
 *
 * DEFAULT DENY: an endpoint that is not in this map can be reached only by the OWNER's own session.
 * That is the safe direction — a new endpoint is admin-only until somebody deliberately decides
 * otherwise, rather than quietly inheriting whatever a moderator happens to hold. It also means this
 * table is the whole answer to "what can a moderator do", in one place, auditable by reading it.
 *
 * Everything absent is absent on purpose: Settings, the credential and 2FA endpoints, group editing,
 * user deletion, API clients and bans, federation, the firewall / sysctl / opentracker helpers,
 * tracker restarts, whitelist regeneration, backup restore-delete-download, and bulk mail. Most of
 * those also demand the owner's password through adminReauth(), which a moderator does not have —
 * this map simply agrees with that boundary instead of contradicting it.
 */
function adminEndpointPermission(string $endpoint): ?string {
    static $map = [
        // Reports
        'admin/fetch_reports'      => 'panel.reports.view',
        'admin/fetch_appeals'      => 'panel.reports.view',
        'admin/change_status'      => 'panel.reports.status',
        'admin/update_field'       => 'panel.reports.status',
        'admin/block_hash'         => 'panel.reports.block',
        'admin/unblock_hash'       => 'panel.reports.block',
        'admin/check_blacklist'    => 'panel.reports.block',
        'admin/block_archived'     => 'panel.reports.block',
        'admin/send_email'         => 'panel.reports.email',
        'admin/notify_review'      => 'panel.reports.email',
        'admin/delete_report'      => 'panel.reports.archive',
        'admin/restore_report'     => 'panel.reports.archive',
        'admin/resolve_appeal'     => 'panel.appeals.resolve',
        'admin/restore_appeal'     => 'panel.appeals.resolve',
        // Whitelist + Index (one page each, one view permission)
        'admin/whitelist_status'   => 'panel.whitelist.view',
        'admin/fetch_whitelist'    => 'panel.whitelist.view',
        'admin/whitelist_item'     => 'panel.whitelist.view',
        'admin/index_status'       => 'panel.whitelist.view',
        'admin/fetch_index'        => 'panel.whitelist.view',
        'admin/index_item'         => 'panel.whitelist.view',
        'admin/fetch_banned'       => 'panel.whitelist.view',
        'admin/whitelist_add'      => 'panel.whitelist.add',
        'admin/whitelist_delete'   => 'panel.whitelist.delete',
        'admin/index_delete'       => 'panel.whitelist.delete',
        'admin/index_promote'      => 'panel.whitelist.add',
        'admin/whitelist_ban'      => 'panel.whitelist.ban',
        'admin/whitelist_unban'    => 'panel.whitelist.ban',
        'admin/banned_add'         => 'panel.whitelist.ban',
        'admin/whitelist_fetch_meta' => 'panel.whitelist.meta',
        'admin/whitelist_meta_queue' => 'panel.whitelist.meta',
        'admin/whitelist_scrape'   => 'panel.whitelist.meta',
        'admin/whitelist_scrape_bulk' => 'panel.whitelist.meta',
        'admin/index_fetch_meta'   => 'panel.whitelist.meta',
        'admin/index_scrape_bulk'  => 'panel.whitelist.meta',
        'admin/index_scrape'       => 'panel.whitelist.meta',
        'admin/wl_content'         => 'panel.whitelist.content',
        // Users
        'admin/fetch_users'        => 'panel.users.view',
        'admin/fetch_groups'       => 'panel.users.view',
        'admin/user_update'        => 'panel.users.edit',
        'admin/user_notify'        => 'panel.users.notify',
        'admin/user_grant'         => 'panel.users.groups',
        'admin/user_revoke'        => 'panel.users.groups',
        // Backups — see and run, never restore or delete
        'admin/backup_status'      => 'panel.backups.view',
        // backup_action is NOT here: its op decides. Running a backup is a moderator action,
        // restoring or deleting one is not, and a single grant for the endpoint cannot tell them
        // apart — so the endpoint checks its own op (api/admin/backup_action.php).
        // Traffic, read-only
        'admin/net_status'         => 'panel.traffic.view',
        'admin/net_samples'        => 'panel.traffic.view',
        'admin/ot_status'          => 'panel.traffic.view',
        'admin/ot_cluster_status'  => 'panel.traffic.view',
        'admin/sysctl_status'      => 'panel.traffic.view',
        'admin/tracker_service_status' => 'panel.traffic.view',
        // Shared chrome every panel page needs to render at all
        'admin/logout'             => 'panel.access',
    ];
    return $map[$endpoint] ?? null;
}

if (str_starts_with($endpoint, 'admin/') && $endpoint !== 'admin/login' && $endpoint !== 'admin/login_2fa') {
    requireAuth($cfg);
    // The session is valid; now, is it allowed to do THIS? For the owner's own session panelCan()
    // is always true and this costs one function call. For a moderator it is the whole boundary.
    $needPerm = adminEndpointPermission($endpoint);
    if (!panelCan($db, $cfg, $needPerm ?? 'panel.owner.__never__')) {
        jsonResponse(['error' => $needPerm === null
            ? 'That action is reserved for the site owner.'
            : 'Your panel account does not have access to that.'], 403);
    }
    // CSRF: every admin write (non-GET) must carry a valid X-CSRF-Token header.
    // Reads (GET: fetch_reports/fetch_appeals) are exempt. Login uses its own body token.
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !verifyCsrfHeader()) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    // PHP's file session handler holds an EXCLUSIVE lock for the whole request, so two requests from
    // the same admin never overlap — they queue. The panel's pages poll several of these endpoints at
    // once, and one of them forks a firewall helper: without this, a single slow poll stalls every
    // other request the page makes, and with only a handful of php-fpm children that is how a card
    // ends up stuck on "loading" while the server is answering fine. These endpoints only read, and
    // they are past the auth check, so nothing below needs the session open any more.
    //
    // The LISTINGS belong here too, and their absence is what made the Index page feel like it jammed
    // the panel. A catalogue search is three to nine seconds of MariaDB; while it ran it held the
    // session lock, so clicking through to another admin page did not load until the search finished.
    // It looked like PHP or the database was struggling and it was neither: one request was simply
    // waiting for another to let go of a file lock. Every endpoint below is a GET that only reads and
    // is already past the auth check, so nothing after this point needs the session open.
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($endpoint, [
            'admin/net_status', 'admin/net_samples', 'admin/backup_status',
            'admin/whitelist_status', 'admin/index_status', 'admin/tracker_service_status',
            'admin/ot_status', 'admin/sysctl_status', 'admin/ot_cluster_status',
            'admin/fetch_index', 'admin/fetch_whitelist', 'admin/fetch_banned',
            'admin/fetch_reports', 'admin/fetch_appeals', 'admin/fetch_users',
            'admin/fetch_groups', 'admin/fetch_api_clients', 'admin/fetch_api_bans',
            'admin/fetch_fed_peers'], true)) {
        session_write_close();
    }
}

require_once __DIR__ . '/' . $apiRoutes[$endpoint];
