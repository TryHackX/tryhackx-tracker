<?php
requirePost();

$input = readJsonBody();
if (!$input || !is_array($input)) {
    jsonResponse(['error' => __('api.settings.invalid_input')], 400);
}

// Whitelist of allowed setting keys
$allowed = [
    'site_name', 'site_url', 'site_email', 'mail_from_email',
    'announce_url', 'announce_url_https', 'github_url',
    'contact_visible', 'contact_obfuscate', 'hmac_secret',
    'recaptcha_enabled', 'recaptcha_site_key', 'recaptcha_secret',
    'recaptcha_on_report', 'recaptcha_on_login', 'recaptcha_on_status',
    'recaptcha_on_appeal', 'recaptcha_on_block_check',
    'captcha_threshold', 'captcha_grace_minutes',
    'captcha_pts_report', 'captcha_pts_status', 'captcha_pts_appeal',
    'captcha_pts_block_check', 'captcha_pts_login_fail',
    'delete_captcha_attempts', 'delete_lockout_attempts', 'delete_lockout_minutes',
    'login_lockout_attempts', 'login_lockout_minutes', 'admin_reauth_max_attempts',
    'rate_limit', 'rate_limit_status', 'rate_limit_block_check', 'rate_limit_appeal',
    'admin_session_idle_minutes', 'admin_session_absolute_hours',
    // where the panel lives (includes/auth.php)
    'admin_login_path', 'admin_hidden_behavior',
    'trusted_proxy_ips', 'client_ip_header',
    // schema v45: transport security (includes/functions.php)
    'cookie_secure_mode', 'client_proto_header',
    'hsts_enabled', 'hsts_max_age', 'hsts_include_subdomains', 'hsts_preload',
    // schema v46: Content-Security-Policy (includes/csp.php)
    'csp_mode', 'csp_report_enabled', 'csp_report_keep_rows', 'csp_extra_hosts',
    // the in-place language switch (assets/js/lang-swap.js, read through langJsBundle())
    'lang_swap_enabled',
    // the Share buttons on the search page (templates/pages/search.php)
    'search_share_enabled',
    // where the version line appears (templates/footer.php) and how long a search may run
    'version_display', 'search_time_budget',
    // schema v47: favourites, public profiles and "my torrents" (includes/favourites.php)
    'fav_enabled', 'fav_max_per_user', 'fav_public_enabled', 'fav_who_enabled', 'profiles_enabled',
    'lists_enabled', 'lists_public_enabled', 'lists_max_per_user', 'lists_max_items',
    // The sign-in bridge (v49). auth_bridge_enabled is the strongest switch on this page: it lets a
    // key holder assert who somebody is. It is here so an operator can turn it OFF again from the
    // same screen they turned it on from.
    'auth_bridge_enabled', 'auth_bridge_create', 'auth_bridge_merge', 'auth_bridge_ttl',
    'auth_bridge_return_url', 'auth_bridge_login_url', 'auth_bridge_logout',
    'wl_submitter_public',
    'items_per_page', 'admin_near_pages', 'blacklist_path',
    'max_magnet_link_length',
    'donations_enabled', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'donation_fields',
    'transparency_enabled', 'transparency_per_page',
    'tracker_stats_enabled', 'tracker_stats_url', 'tracker_stats_interval', 'tracker_stats_page_interval', 'tracker_stats_cache_ttl', 'tracker_stats_show_home', 'tracker_stats_timeout', 'tracker_stats_min_loading', 'tracker_stats_max_loading', 'tracker_stats_peer_label_style', 'tracker_stats_livesync_mode',
    // statistics timeline (includes/stats_timeline.php)
    'stats_timeline_enabled', 'stats_timeline_interval', 'stats_timeline_raw_days', 'stats_timeline_keep_days', 'stats_timeline_public',
    'stats_timeline_ranges', 'stats_timeline_default_range', 'stats_timeline_custom_range',
    // observed-hash index (includes/index.php)
    'index_enabled', 'index_source_url', 'index_poll_minutes', 'index_min_seeders', 'index_max_rows',
    'index_grace_days', 'index_protect_days', 'index_meta_daily_budget', 'index_keep_files', 'index_poll_budget',
    'index_meta_auto_queue',
    // schema v44: how a file list loads — the public search page, then the panel modals
    'index_files_mode', 'index_files_batch', 'index_files_max',
    'index_files_admin_mode', 'index_files_admin_batch', 'index_files_admin_max',
    'opentracker_service_name', 'opentracker_restart_use_sudo', 'opentracker_auto_reload',
    'tracker_uptime_warn_days', 'tracker_uptime_danger_days',
    'tracker_blacklist_warn_count', 'tracker_blacklist_danger_count',
    'auto_archive_days', 'auto_archive_appeal_days', 'sent_emails_retention_days',
    'max_message_length', 'max_appeal_message_length',
    'footer_start_year',
    'footer_brand_name', 'footer_brand_url', 'footer_brand_enabled',
    'footer_tracker_name', 'footer_tracker_url', 'footer_tracker_author', 'footer_tracker_author_url', 'footer_tracker_enabled',
    'footer_os_name', 'footer_os_url', 'footer_os_enabled', 'footer_os_since_year',
    // whitelist mode
    'tracker_mode', 'whitelist_path', 'whitelist_public_enabled', 'whitelist_max_per_submission',
    'rate_limit_whitelist', 'whitelist_ip_daily_max', 'whitelist_daily_cap', 'whitelist_reload_min_interval',
    'whitelist_scrape_url', 'whitelist_require_tracker', 'whitelist_tracker_hosts',
    // scheduled tracker mode (includes/schedule.php)
    'tracker_schedule_enabled', 'tracker_schedule', 'tracker_schedule_tz', 'tracker_mode_switch_cmd',
    // schema v11: inbound UDP monitor + rate limit (includes/netlimit.php). Changing the limit
    // itself does NOT touch the firewall here — that goes through admin/net_apply, which asks for
    // the admin password; saving only records what the panel should load.
    'net_monitor_enabled', 'net_sample_seconds', 'net_keep_days',
    'net_limit_enabled', 'net_limit_pps', 'net_limit_burst', 'net_limit_port', 'net_limit_cmd',
    'net_auto_enabled', 'net_auto_min', 'net_auto_max', 'net_auto_target', 'net_auto_target_cpu',
    'net_lists_enabled', 'net_lists_ttl_default',
    'index_poll_keep_days',
    // schema v11: panel-driven backups (includes/backup.php). Running, restoring and downloading
    // all live behind the admin password in admin/backup_action — saving only records the policy.
    'backup_enabled', 'backup_dir', 'backup_profile', 'backup_items', 'backup_schedule',
    'backup_schedule_tz', 'backup_keep', 'backup_keep_days', 'backup_max_size_gb',
    'backup_gpg_recipient', 'backup_nice', 'backup_verify_after', 'backup_cmd',
    'backup_script_path', 'backup_db_name',
    // captcha provider (reCAPTCHA v2 keys are the legacy recaptcha_* entries above)
    'captcha_provider', 'turnstile_site_key', 'turnstile_secret',
    'recaptcha_v3_site_key', 'recaptcha_v3_secret', 'recaptcha_v3_min_score',
    'hcaptcha_site_key', 'hcaptcha_secret',
    // server-to-server API
    'api_enabled', 'api_ban_days', 'api_ban_exempt_ips',
    // user accounts (includes/users.php)
    'users_enabled', 'users_registration_enabled', 'users_links_visible', 'users_default_group',
    'users_notify_expiry_days', 'rate_limit_user_login', 'rate_limit_user_register', 'rate_limit_index_search',
    'rate_limit_preview',
    'wl_content_autopublish', 'wl_edit_max_pending',
    'wl_scrape_every_hours', 'wl_scrape_batch', 'wl_dead_after_days', 'wl_dead_action', 'wl_dead_every_days',
    'wl_probe_required', 'wl_probe_timeout_minutes', 'wl_probe_on_fail', 'wl_probe_max_batch',
    'audit_enabled', 'audit_keep_days',
    'tuner_enabled', 'tuner_python',
    'rep_enabled', 'rep_mode', 'rep_who_can_vote', 'rep_show_in_results', 'rep_min_votes', 'rep_anon_weight', 'rep_rate_per_hour', 'captcha_pts_vote',
    'bulk_mail_enabled', 'bulk_mail_per_minute', 'bulk_mail_max_attempts',
    'wl_allow_source_url', 'wl_allow_description', 'wl_content_review',
    'livesync_enabled', 'livesync_cmd', 'livesync_bind_ip', 'livesync_peer_ip', 'livesync_port', 'desc_allow_bbcode', 'desc_allow_markdown', 'desc_max_chars', 'desc_max_images', 'desc_max_links', 'link_trusted_domains', 'search_allow_sl_refresh', 'search_sl_refresh_seconds',
    // whitelist registration audience + metadata worker concurrency (schema v8)
    'whitelist_submit_mode', 'meta_worker_concurrency',
    // schema v43: stored file paths per torrent, the second live worker override
    'meta_max_files',
    'meta_order_mode', 'meta_order_mix_oldest', 'meta_order_mix_newest',
    'meta_order_mix_seeders', 'meta_order_mix_random', 'meta_order_mix_whitelist',
    'meta_order_mix_seen', 'meta_order_mix_completed', 'net_limit_trusted', 'net_limit_blocked', 'tuner_load_headroom', 'tuner_load_hard',
    // schema v9: verification gate, terms, email-change cooldown, member-search switches
    'users_require_email_verify', 'users_terms_text', 'users_email_change_cooldown_days',
    'index_search_enabled', 'index_search_include_whitelist',
    // federation (includes/federation.php + worker/federation.py)
    'api_rate_limit_per_min', 'api_rate_limit_bytes_day',
    'ot_perf_cmd', 'ot_nice', 'ot_cpu_weight', 'ot_cpu_affinity', 'ot_limit_nofile', 'ot_udp_workers',
    'fed_enabled', 'fed_node_name', 'fed_export_enabled', 'fed_export_files', 'fed_export_max_batch',
    'fed_export_max_bytes', 'fed_export_max_files',
    'fed_import_batch_rows', 'fed_import_batch_bytes', 'fed_import_max_seconds', 'fed_worker_mem_mb',
    'fed_import_new', 'fed_import_mode', 'fed_pull_minutes',
    // Kernel network buffers: the plumbing only. The eight VALUES are written by
    // api/admin/sysctl_apply.php after the armed protocol, never by a form post, and there is
    // deliberately no key here that could rewrite the record of what the machine looked like
    // before the panel first touched it.
    'sysctl_cmd', 'sysctl_enabled', 'sysctl_confirm_seconds',
    'dbmem_cmd', 'dbmem_enabled',
    'ot_cluster_cmd', 'ot_cluster_enabled', 'ot_cluster_port_base',
];

// The settings that decide what www-data EXECUTES, or whom the panel BELIEVES, are not ordinary
// settings either. A helper command is handed to the shell on every schedule tick, backup run and
// firewall change; the interpreter path is exec()ed; the service name and the sudo switch are the
// command line systemctl gets; the proxy pair decides which header names the client for every rate
// limit and lockout in the panel; the HMAC key signs the unsubscribe links. Every dangerous ACTION
// asks for the password again — but until now the setting that told the action what to run saved
// with a session cookie alone, and an admin-group account reaches this endpoint on that cookie. So
// the definition is put behind the same gate as the deed: any of these arriving with a value other
// than the stored one needs the OWNER's password, the same way the deletion limits below do.
//
// key => what the form shows when nothing is stored yet. The comparison must use the same
// fallback the template prints, or an untouched field on a fresh install would read as a change
// and the password would be asked for every save.
$reauthKeys = [
    'tracker_mode_switch_cmd'      => SCHEDULE_DEFAULT_CMD,   // includes/schedule.php
    'net_limit_cmd'                => NET_DEFAULT_CMD,        // includes/netlimit.php
    'backup_cmd'                   => BACKUP_DEFAULT_CMD,     // includes/backup.php
    'backup_script_path'           => BACKUP_DEFAULT_SCRIPT,
    'livesync_cmd'                 => '',                     // includes/livesync.php
    'ot_perf_cmd'                  => '',                     // includes/opentracker.php
    'sysctl_cmd'                   => '',                     // includes/sysctl.php
    'dbmem_cmd'                    => '',                     // includes/dbmem.php
    'ot_cluster_cmd'               => '',                     // includes/cluster.php
    'tuner_python'                 => 'python3',              // includes/tuner.php
    'opentracker_service_name'     => '',                     // restart/reload_tracker, functions.php
    'opentracker_restart_use_sudo' => '1',
    'trusted_proxy_ips'            => '',                     // getClientIp()
    'client_ip_header'             => '',
    'hmac_secret'                  => '',                     // generateUnsubscribeToken()
    // The two transport settings whose consequence is not undoable from this page. 'always' on a
    // panel really served over plain HTTP means nobody can sign in again — the browser refuses the
    // session cookie, so the CSRF token minted at login is never the one checked at submit — and
    // the way back is a SQL statement, not a click. `preload` is worse in kind: once the host is
    // accepted onto the browser preload list it is compiled into releases and removal takes months,
    // and nothing in this panel can accelerate it.
    'cookie_secure_mode'           => 'auto',                 // cookieSecureFlag(), functions.php
    'hsts_preload'                 => '0',                    // hstsHeaderValue()
    // A host in this box may RUN SCRIPTS on every page of this site, in every visitor's session.
    // That is a change to whom the site trusts, which is the one thing this array is for.
    'csp_extra_hosts'              => '',                     // cspPolicy(), includes/csp.php
];

$data = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $input)) {
        $data[$key] = trim((string)$input[$key]);
    }
}

if (empty($data)) {
    jsonResponse(['error' => __('api.settings.no_valid_settings')], 400);
}

// ── Whitelist / API / CAPTCHA settings validation ──
if (isset($data['tracker_mode']) && !in_array($data['tracker_mode'], ['blacklist', 'whitelist'], true)) {
    jsonResponse(['error' => __('api.settings.invalid_tracker_mode')], 400);
}
if (isset($data['whitelist_path'])) {
    $data['whitelist_path'] = normalizeListPath($data['whitelist_path']);
    if ($data['whitelist_path'] !== '') {
        $v = validateWhitelistPath($data['whitelist_path']);
        if (!$v['ok']) jsonResponse(['error' => __('api.settings.whitelist_path_rejected', ['error' => $v['error']])], 400);
    }
}
if (isset($data['captcha_provider']) && !in_array($data['captcha_provider'], captchaProviders(), true)) {
    jsonResponse(['error' => __('api.settings.invalid_captcha_provider')], 400);
}
// ── Where the admin panel lives ──
if (isset($data['admin_login_path'])) {
    $path = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($data['admin_login_path'])));
    if ($path === '' || $path === null) $path = 'admin';
    if (strlen($path) > 64) jsonResponse(['error' => __('api.settings.login_path_too_long')], 400);
    if ($path !== 'admin' && in_array($path, adminReservedActions(), true)) {
        jsonResponse(['error' => __('api.settings.login_path_taken')], 400);
    }
    $data['admin_login_path'] = $path;
}
if (isset($data['admin_hidden_behavior']) && !in_array($data['admin_hidden_behavior'], ['home', 'login', '404'], true)) {
    $data['admin_hidden_behavior'] = 'home';
}
// ── Content-Security-Policy ──
// Coerced rather than refused, and coerced to 'report': an unknown value is a bug in the form, and
// the answer to a bug in the form is the setting that cannot break a page for a visitor.
if (isset($data['csp_mode']) && !in_array($data['csp_mode'], CSP_MODES, true)) {
    $data['csp_mode'] = 'report';
}
if (isset($data['csp_extra_hosts'])) {
    // This value is written VERBATIM into a response header, so the validation is the whole security
    // boundary of the feature — and it lives in includes/csp.php, called from here AND from
    // cspPolicy(), because the settings table sits in a MariaDB three other applications can reach
    // and this endpoint is not the only way a row can be written.
    // The ceiling is checked INSIDE the loop. Checked after it, a body carrying a hundred thousand
    // syntactically valid hostnames is an O(n^2) walk (in_array over a list that keeps growing)
    // before anything refuses it, and readJsonBody() caps nothing but post_max_size.
    $keep = [];
    foreach (preg_split('/[\s,;]+/', (string)$data['csp_extra_hosts']) ?: [] as $h) {
        $h = trim($h);
        if ($h === '') continue;
        if (count($keep) >= CSP_EXTRA_HOSTS_MAX) {
            jsonResponse(['error' => __('api.settings.csp_hosts_too_many', ['max' => CSP_EXTRA_HOSTS_MAX])], 400);
        }
        if (!cspHostOk($h)) jsonResponse(['error' => __('api.settings.csp_host_invalid', ['entry' => $h])], 400);
        if (!in_array($h, $keep, true)) $keep[] = $h;
    }
    $data['csp_extra_hosts'] = implode(' ', $keep);
}
// ── How a file list loads: the two modes ──
// Each falls back to what its own audience did before 1.38.0, which is not the same value — the
// search page has always scrolled, the modals have always waited for a button. Falling back to one
// shared 'scroll' would have taken the panel out of its behaviour-preserving mode on a typo, and
// left the coercion here disagreeing with indexFilesAdminMode() in includes/index.php.
foreach (['index_files_mode' => 'scroll', 'index_files_admin_mode' => 'button'] as $k => $def) {
    if (isset($data[$k]) && !in_array($data[$k], IDX_FILES_MODES, true)) $data[$k] = $def;
}
// ── Timeline range buttons ──
if (isset($data['stats_timeline_ranges'])) {
    $known = array_keys(statsTimelineRangeButtons());
    $want = array_filter(array_map('trim', explode(',', strtolower($data['stats_timeline_ranges']))));
    $keep = array_values(array_intersect($known, $want));   // intersect keeps the display order
    $data['stats_timeline_ranges'] = implode(',', $keep ?: $known);
}
if (isset($data['stats_timeline_default_range'])) {
    $known = array_keys(statsTimelineRangeButtons());
    $def = strtolower(trim($data['stats_timeline_default_range']));
    $data['stats_timeline_default_range'] = in_array($def, $known, true) ? $def : '24h';
}
if (isset($data['recaptcha_v3_min_score'])) {
    // 0.0–1.0, one decimal; blank/garbage falls back to Google's suggested 0.5
    $raw = str_replace(',', '.', $data['recaptcha_v3_min_score']);
    $score = is_numeric($raw) ? (float)$raw : 0.5;
    $data['recaptcha_v3_min_score'] = number_format(max(0.0, min(1.0, $score)), 1, '.', '');
}
if (isset($data['whitelist_scrape_url']) && $data['whitelist_scrape_url'] !== '' && !preg_match('#^https?://[^\s]+$#i', $data['whitelist_scrape_url'])) {
    jsonResponse(['error' => __('api.settings.scrape_url_invalid')], 400);
}
$intClamp = [
    'whitelist_max_per_submission' => [1, 500, 20], 'rate_limit_whitelist' => [0, 1000, 10],
    'whitelist_ip_daily_max' => [0, 100000, 50], 'whitelist_daily_cap' => [0, 10000000, 2000],
    'whitelist_reload_min_interval' => [10, 3600, 45], 'api_ban_days' => [1, 3650, 30],
    'stats_timeline_interval' => [ST_INTERVAL_MIN, ST_INTERVAL_MAX, 60], 'stats_timeline_raw_days' => [1, 30, 7],
    'stats_timeline_keep_days' => [7, 3650, 60],
    'index_poll_minutes' => [5, 1440, 30], 'index_min_seeders' => [0, 100000, 1], 'index_max_rows' => [1000, 5000000, 200000],
    'index_grace_days' => [1, 90, 3], 'index_protect_days' => [1, 365, 10], 'index_meta_daily_budget' => [0, 1000000, 500],
    'index_poll_budget' => [5, 120, 45],
    // The ceilings are read from includes/index.php, never retyped: the same numbers bound the
    // endpoint, the clamp-on-read helpers and the fields' max= attributes. A max BELOW its own batch
    // is legal here and repaired on read (indexFilesMax() floors it at one batch) rather than
    // refused — one bad number must not reject a save of every other setting on the page.
    'index_files_batch' => [IDX_FILES_BATCH_MIN, IDX_FILES_BATCH_MAX, 2000],
    'index_files_max' => [IDX_FILES_BATCH_MIN, IDX_FILES_MAX_HARD, 20000],
    'index_files_admin_batch' => [IDX_FILES_BATCH_MIN, IDX_FILES_ADMIN_BATCH_MAX, 5000],
    'index_files_admin_max' => [IDX_FILES_BATCH_MIN, IDX_FILES_ADMIN_MAX_HARD, IDX_FILES_ADMIN_MAX_HARD],
    'admin_near_pages' => [1, 20, 2],
    // Clamped, never rejected, and 0 must survive the clamp: `max-age=0` with the switch still ON is
    // the documented way to WITHDRAW the pin from browsers that already hold it. Turning the switch
    // off only stops sending the header and leaves every existing pin running to its own expiry.
    'hsts_max_age' => [0, HSTS_MAX_AGE_CEILING, HSTS_MAX_AGE_DEFAULT],
    // Rows are already aggregated (one per KIND of violation), so this bounds how many DISTINCT
    // problems the panel will remember, not how many were seen. 0 means keep none, and the report
    // endpoint then refuses every write rather than merely letting the janitor undo it a minute later.
    'csp_report_keep_rows' => [0, CSP_ROWS_MAX, CSP_ROWS_DEFAULT],
    'users_notify_expiry_days' => [0, 30, 3], 'users_email_change_cooldown_days' => [0, 365, 30],
    'bulk_mail_per_minute' => [1, 500, 20], 'bulk_mail_max_attempts' => [1, 10, 3],
    'desc_max_chars' => [200, 20000, 4000], 'desc_max_images' => [0, 50, 3],
    'livesync_port' => [1024, 65535, 9696],
    'desc_max_links' => [0, 100, 10], 'search_sl_refresh_seconds' => [10, 3600, 120],
    'rate_limit_user_login' => [0, 1000, 10],
    'rate_limit_user_register' => [0, 1000, 5], 'rate_limit_index_search' => [0, 100000, 120],
    'rate_limit_preview' => [5, 300, 30],
    'rep_min_votes' => [1, 1000, 3], 'rep_anon_weight' => [0, 100, 25],
    // Lists (v51). The same shape as fav_max_per_user: a ceiling that keeps one person's collection
    // from becoming everybody's query cost, clamped rather than refused.
    'lists_max_per_user' => [1, 200, 20], 'lists_max_items' => [10, 5000, 500],
    'wl_edit_max_pending' => [0, 50, 3],
    'wl_scrape_every_hours' => [0, 8760, 0], 'wl_scrape_batch' => [1, 2000, 200],
    'wl_dead_after_days' => [0, 3650, 0], 'wl_dead_every_days' => [1, 365, 30],
    'wl_probe_timeout_minutes' => [1, 1440, 10], 'wl_probe_max_batch' => [1, 64, 8],
    'rep_rate_per_hour' => [1, 1000, 30], 'captcha_pts_vote' => [0, 100, 2],
    'fed_export_max_batch' => [100, 20000, 2000], 'fed_pull_minutes' => [5, 1440, 60],
    // 0 on either budget switches that half off; the ceilings are only a guard against typos
    'api_rate_limit_per_min' => [0, 100000, 60],
    'ot_nice' => [-20, 19, -2], 'ot_cpu_weight' => [1, 10000, 100], 'ot_limit_nofile' => [1024, 1048576, 65536],
    // Clamped here and rounded to whole minutes in sysctlConfirmSeconds(): the janitor is the
    // coarsest watchdog and it ticks once a minute, so a 30-second promise it cannot keep would
    // read as a guarantee to whoever is watching the countdown.
    'sysctl_confirm_seconds' => [60, 900, 120],
    'fed_export_max_bytes' => [0, 1073741824, 8388608], 'fed_export_max_files' => [0, 50000000, 200000],
    'fed_import_batch_rows' => [25, 5000, 500], 'fed_import_batch_bytes' => [1048576, 268435456, 33554432],
    'fed_import_max_seconds' => [30, 21600, 600], 'fed_worker_mem_mb' => [64, 4096, 256],
    // UDP monitor + rate limit (includes/netlimit.php)
    'net_sample_seconds' => [NET_SAMPLE_MIN, NET_SAMPLE_MAX, 60], 'net_keep_days' => [NET_KEEP_MIN, NET_KEEP_MAX, 14],
    'net_limit_pps' => [NET_PPS_MIN, NET_PPS_MAX, 30000], 'net_limit_burst' => [NET_BURST_MIN, NET_BURST_MAX, 100],
    'net_limit_port' => [1, 65535, 6969],
    'net_auto_min' => [NET_PPS_MIN, NET_PPS_MAX, 10000], 'net_auto_max' => [NET_PPS_MIN, NET_PPS_MAX, 80000],
    'net_auto_target' => [NET_PPS_MIN, NET_PPS_MAX, 30000], 'net_auto_target_cpu' => [10, 100, 70],
    'net_lists_ttl_default' => [IPLIST_TTL_MIN, IPLIST_TTL_MAX, IPLIST_TTL_DEFAULT],
    'index_poll_keep_days' => [1, 3650, 90],
    // backups (includes/backup.php)
    'backup_keep' => [0, BACKUP_KEEP_MAX, 7], 'backup_keep_days' => [0, BACKUP_DAYS_MAX, 30],
    'backup_max_size_gb' => [0, BACKUP_GB_MAX, 20], 'backup_nice' => [0, 19, 15],
];
foreach ($intClamp as $k => [$min, $max, $def]) {
    if (isset($data[$k])) {
        $n = is_numeric($data[$k]) ? (int)$data[$k] : $def;
        $data[$k] = (string)max($min, min($max, $n));
    }
}
if (isset($data['whitelist_tracker_hosts'])) {
    // keep only plausible hostnames / IPs (with optional port); the helper strips schemes/ports on read
    $clean = [];
    foreach (preg_split('/[\s,;]+/', (string)$data['whitelist_tracker_hosts']) ?: [] as $h) {
        $h = trim($h);
        if ($h !== '' && strlen($h) <= 253 && preg_match('#^([a-z]+://)?[A-Za-z0-9.:\[\]-]+(/.*)?$#', $h)) $clean[] = $h;
    }
    $data['whitelist_tracker_hosts'] = implode(', ', array_unique($clean));
}
if (isset($data['fed_import_mode'])) $data['fed_import_mode'] = $data['fed_import_mode'] === 'review' ? 'review' : 'fill';
if (isset($data['ot_cluster_cmd']) && !otClusterValidCommand((string)$data['ot_cluster_cmd'])) {
    jsonResponse(['error' => __('api.settings.cluster_cmd_invalid')], 400);
}
if (isset($data['ot_cluster_port_base']) && trim((string)$data['ot_cluster_port_base']) !== ''
    && ((int)$data['ot_cluster_port_base'] < 1024 || (int)$data['ot_cluster_port_base'] > 65500)) {
    jsonResponse(['error' => __('api.settings.cluster_port_base_invalid')], 400);
}
if (isset($data['dbmem_cmd']) && !dbmemValidCommand((string)$data['dbmem_cmd'])) {
    jsonResponse(['error' => __('api.settings.dbmem_cmd_invalid')], 400);
}
if (isset($data['sysctl_cmd']) && !sysctlValidCommand((string)$data['sysctl_cmd'])) {
    jsonResponse(['error' => __('api.settings.sysctl_cmd_invalid')], 400);
}
foreach (['whitelist_public_enabled', 'api_enabled', 'whitelist_require_tracker', 'tracker_schedule_enabled', 'stats_timeline_enabled', 'stats_timeline_public', 'stats_timeline_custom_range', 'index_enabled', 'index_keep_files', 'index_meta_auto_queue',
          'users_enabled', 'users_registration_enabled', 'users_links_visible',
          'users_require_email_verify', 'index_search_enabled', 'index_search_include_whitelist',
          'fed_enabled', 'fed_export_enabled', 'fed_export_files', 'fed_import_new', 'sysctl_enabled', 'ot_cluster_enabled',
          'net_monitor_enabled', 'net_limit_enabled', 'net_auto_enabled',
          'hsts_enabled', 'hsts_include_subdomains', 'hsts_preload', 'csp_report_enabled',
          'backup_enabled', 'backup_verify_after'] as $k) {
    if (isset($data[$k])) $data[$k] = $data[$k] === '1' ? '1' : '0';
}
// ── UDP rate limit ──
// The helper command is handed to the shell, so it gets the same treatment as the mode switch
// command: a strict character class here, escapeshellarg() on every argument in includes/netlimit.php.
if (isset($data['net_limit_cmd']) && !netlimitValidCommand($data['net_limit_cmd'])) {
    jsonResponse(['error' => __('api.settings.net_limit_cmd_invalid')], 400);
}
// An upside-down automatic band would let one save lock the limit at a single value.
if (isset($data['net_auto_min']) || isset($data['net_auto_max'])) {
    $min = (int)($data['net_auto_min'] ?? netlimitAutoMin($cfg));
    $max = (int)($data['net_auto_max'] ?? netlimitAutoMax($cfg));
    if ($max < $min) jsonResponse(['error' => __('api.settings.net_auto_band_inverted', ['max' => number_format($max), 'min' => number_format($min)])], 400);
}
// ── Backups ──
// The directory is where archives full of database passwords land, so it is checked here as well as
// in the helper — a save must never be able to point it at the web root.
if (isset($data['backup_dir']) && $data['backup_dir'] !== '') {
    $v = backupValidateDir($data['backup_dir']);
    if (!$v['ok']) jsonResponse(['error' => __('api.settings.backup_dir_rejected', ['error' => $v['error'], 'hint' => ($v['hint'] ? ' ' . $v['hint'] : '')])], 400);
    $data['backup_dir'] = rtrim(preg_replace('/[\x00-\x1F\x7F]/', '', trim($data['backup_dir'])), '/');
}
if (isset($data['backup_cmd']) && !backupValidCommand($data['backup_cmd'])) {
    jsonResponse(['error' => __('api.settings.backup_cmd_invalid')], 400);
}
if (isset($data['backup_script_path']) && $data['backup_script_path'] !== ''
    && !preg_match('#^/[A-Za-z0-9 _./-]{1,255}$#', $data['backup_script_path'])) {
    jsonResponse(['error' => __('api.settings.backup_script_path_invalid')], 400);
}
if (isset($data['backup_profile']) && !in_array($data['backup_profile'], BACKUP_PROFILES, true)) {
    jsonResponse(['error' => __('api.settings.backup_profile_unknown')], 400);
}
if (isset($data['backup_items'])) {
    $data['backup_items'] = backupSanitizeItems($data['backup_items']);
}
if (isset($data['backup_schedule']) && trim($data['backup_schedule']) !== ''
    && backupParseSchedule($data['backup_schedule']) === null) {
    jsonResponse(['error' => __('api.settings.backup_schedule_invalid')], 400);
}
if (isset($data['backup_schedule_tz']) && $data['backup_schedule_tz'] !== ''
    && !in_array($data['backup_schedule_tz'], timezone_identifiers_list(), true)) {
    jsonResponse(['error' => __('api.settings.backup_tz_invalid')], 400);
}
if (isset($data['backup_gpg_recipient']) && $data['backup_gpg_recipient'] !== ''
    && !preg_match('/^[A-Za-z0-9@._+-]{1,128}$/', $data['backup_gpg_recipient'])) {
    jsonResponse(['error' => __('api.settings.backup_gpg_invalid')], 400);
}
if (isset($data['backup_db_name']) && $data['backup_db_name'] !== ''
    && !preg_match('/^[A-Za-z0-9_]{1,64}$/', $data['backup_db_name'])) {
    jsonResponse(['error' => __('api.settings.backup_db_name_invalid')], 400);
}
if (isset($data['users_default_group'])) {
    $data['users_default_group'] = strtolower(trim((string)$data['users_default_group']));
    if (!preg_match('/^[a-z0-9_-]{2,64}$/', $data['users_default_group'])) $data['users_default_group'] = 'member';
    // A group that does not exist is not a default, it is a typo with a settings row. The page now
    // offers a select of the groups that exist, so anything else arrived from a stale form or from
    // somebody's script — and the honest answer to both is to refuse rather than to save a setting
    // that will quietly grant nothing to every account made afterwards.
    $sgChk = $db->prepare("SELECT 1 FROM user_groups WHERE slug = ? LIMIT 1");
    $sgChk->execute([$data['users_default_group']]);
    if (!$sgChk->fetchColumn()) jsonResponse(['error' => __('api.settings.default_group_missing')], 400);
}
if (isset($data['whitelist_submit_mode']) && !in_array($data['whitelist_submit_mode'], ['public', 'users'], true)) {
    jsonResponse(['error' => __('api.settings.whitelist_submit_mode_invalid')], 400);
}
if (isset($data['users_terms_text'])) {
    $data['users_terms_text'] = mb_substr($data['users_terms_text'], 0, 10000);
}
if (isset($data['mail_from_email']) && $data['mail_from_email'] !== '') {
    if (!filter_var($data['mail_from_email'], FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => __('api.settings.mail_from_invalid')], 400);
    }
    // From must live on the site's own domain (or a parent of it) — anything else breaks
    // SPF/DKIM/DMARC alignment and lands in spam. The check uses the site_url being saved
    // alongside, falling back to the stored one.
    $allowed = mailFromAllowedHosts(['site_url' => $data['site_url'] ?? ($cfg['site_url'] ?? '')]);
    $fromHost = strtolower(substr(strrchr($data['mail_from_email'], '@'), 1));
    if ($allowed && !in_array($fromHost, $allowed, true)) {
        jsonResponse(['error' => __('api.settings.mail_from_domain', ['hosts' => implode(', ', $allowed)])], 400);
    }
}
// ── the metadata fetch order ─────────────────────────────────────────────────
// Normalised, not merely validated: the worker acts on these every few seconds, and a queue is not
// a good place to discover that four numbers add up to 97. The rules live in includes/meta_order.php
// next to the list of selectors, so the form, the save path and the worker cannot drift apart.
if (isset($data['meta_order_mode'])) {
    require_once __DIR__ . '/../../includes/meta_order.php';
    $shIn = [];
    foreach (metaOrderMixKeys() as $nm) $shIn[$nm] = $data['meta_order_mix_' . $nm] ?? 0;
    [$mOrder, $mShares] = metaOrderNormalise((string)$data['meta_order_mode'], $shIn);
    $data['meta_order_mode'] = $mOrder;
    foreach ($mShares as $nm => $v) $data['meta_order_mix_' . $nm] = (string)$v;
}

if (isset($data['meta_worker_concurrency']) && $data['meta_worker_concurrency'] !== '') {
    // Empty means "use the worker's own config file"; a number is clamped to 1..64.
    //
    // The comment here used to say 1..16 while the code said 64 — the ceiling was raised and only
    // half the line was updated. And 0 or a negative number was silently rewritten to '', which reads
    // in the panel as "accepted" and means "ignore me": the operator sees their entry vanish into the
    // worker's default. Below one is now clamped to one, which is what asking for 0 can only mean.
    $n = is_numeric($data['meta_worker_concurrency']) ? (int)$data['meta_worker_concurrency'] : 1;
    $data['meta_worker_concurrency'] = (string)max(1, min(64, $n));
}
if (isset($data['meta_max_files']) && $data['meta_max_files'] !== '') {
    // Same contract as the concurrency field above, deliberately: empty stays empty and means "use
    // the worker's own config file", a number is clamped rather than rejected. Kept OUT of the
    // $intClamp loop for the one reason that loop cannot serve here — it rewrites a non-numeric
    // entry to the DEFAULT, and the default is '', so a typo would silently become "worker config"
    // while the operator believes they set a cap. Clamping below one to one is what asking for
    // "store 0 files" can only mean, and it never has to write the ceiling as a literal.
    // A typo is NOT "store one path per torrent". Both readers of this value — indexMetaMaxFiles()
    // here and effective_max_files() in the worker — treat anything non-numeric as "use the
    // worker's own config file", so the save has to mean the same thing or the panel would show a
    // cap of 1 that the worker never applies.
    if (!ctype_digit(trim((string)$data['meta_max_files']))) {
        $data['meta_max_files'] = '';
    } else {
        $data['meta_max_files'] = (string)max(1, min(META_MAX_FILES_MAX, (int)trim((string)$data['meta_max_files'])));
    }
}
if (isset($data['ot_perf_cmd']) && $data['ot_perf_cmd'] !== '' && !otValidCommand($data['ot_perf_cmd'])) {
    jsonResponse(['error' => __('api.settings.ot_perf_cmd_invalid')], 400);
}
if (isset($data['ot_cpu_affinity'])) {
    // systemd refuses to START a unit whose CPUAffinity it cannot parse, so a typo saved here would
    // take the tracker down at the next restart rather than at the moment of the mistake.
    $data['ot_cpu_affinity'] = trim((string)$data['ot_cpu_affinity']);
    if ($data['ot_cpu_affinity'] !== '' && !otValidAffinity($data['ot_cpu_affinity'])) {
        jsonResponse(['error' => __('api.settings.ot_cpu_affinity_invalid')], 400);
    }
}
if (isset($data['ot_udp_workers'])) {
    // Empty is a real answer: it means "do not touch opentracker's own config".
    $v = trim((string)$data['ot_udp_workers']);
    if ($v !== '') {
        if (!ctype_digit($v) || (int)$v < 1 || (int)$v > OT_WORKERS_MAX) {
            jsonResponse(['error' => __('api.settings.ot_udp_workers_invalid', ['max' => OT_WORKERS_MAX])], 400);
        }
        $v = (string)(int)$v;
    }
    $data['ot_udp_workers'] = $v;
}
if (isset($data['api_rate_limit_bytes_day'])) {
    // A day's transfer budget is measured in gigabytes, so it does not belong in the shared small-int
    // clamp. 0 = no limit; the 1 TB ceiling only catches a slipped keyboard.
    $v = $data['api_rate_limit_bytes_day'];
    $n = is_numeric($v) ? (int)$v : -1;
    if ($n < 0 || $n > 1099511627776) {
        jsonResponse(['error' => __('api.settings.api_bytes_budget_invalid')], 400);
    }
    $data['api_rate_limit_bytes_day'] = (string)$n;
}
if (isset($data['fed_node_name'])) {
    $data['fed_node_name'] = mb_substr(preg_replace('/[^\w .\-]/u', '', $data['fed_node_name']) ?? '', 0, 64);
}
if (isset($data['index_source_url']) && $data['index_source_url'] !== '' && !preg_match('#^https?://[^\s]+$#i', $data['index_source_url'])) {
    jsonResponse(['error' => __('api.settings.index_source_url_invalid')], 400);
}
// ── Scheduled tracker mode ──
if (isset($data['tracker_schedule'])) {
    $sched = scheduleParseJson($data['tracker_schedule']);
    if ($sched === null) jsonResponse(['error' => __('api.settings.schedule_invalid')], 400);
    $data['tracker_schedule'] = json_encode($sched);   // normalised, all 7 keys
}
if (isset($data['tracker_schedule_tz']) && !scheduleValidTimezone($data['tracker_schedule_tz'])) {
    jsonResponse(['error' => __('api.settings.schedule_tz_invalid')], 400);
}
if (isset($data['tracker_mode_switch_cmd']) && !scheduleValidSwitchCommand($data['tracker_mode_switch_cmd'])) {
    jsonResponse(['error' => __('api.settings.mode_switch_cmd_invalid')], 400);
}
if (isset($data['api_ban_exempt_ips'])) {
    $clean = [];
    foreach (apiParseIpList($data['api_ban_exempt_ips']) as $e) {
        $ipPart = explode('/', $e, 2)[0];
        if (filter_var($ipPart, FILTER_VALIDATE_IP)) $clean[] = $e;
    }
    $data['api_ban_exempt_ips'] = implode(', ', $clean);
}

/* ── Transport security: whose header we believe, Secure on the cookies, HSTS ──
 *
 * EVERY refusal below fires on a CHANGE, never on a value. The settings page posts every named
 * control in #settings-form on every save, so `$data` always carries all six of these keys — and a
 * guard shaped "if the value is X and this request is not HTTPS, refuse" would refuse saves of
 * completely unrelated fields for as long as the condition held. Set Secure to `always` while TLS
 * detection works, have it break later (a vhost edit, a proxy moved), and the entire Settings page
 * would become unsavable with an error about cookies. So the comparison is the same one the
 * $reauthKeys loop makes further down: is this key arriving with a different value than the stored one.
 *
 * And the HTTPS questions are asked of `$data + $cfg`, never of $cfg alone. $cfg is not refreshed
 * until setSettings() at the bottom of this file, so judging by $cfg would judge the save that FIXES
 * detection by the configuration it is replacing: listing the proxy and switching Secure on in one
 * click is exactly the save that would be refused.
 */
if (array_key_exists('trusted_proxy_ips', $data)) {
    // The box had no validation whatsoever, which is how a CIDR came to sit in it doing nothing at
    // all. Normalising and checking only when the value actually CHANGED has a second purpose
    // besides the guard rule above: an untouched field is passed through byte-identical, so the
    // first save after this upgrade cannot open the password modal for a field nobody touched, and
    // an installation already holding an over-wide entry can still save everything else on the page.
    // The runtime ignores such an entry regardless — see trustedProxyList() in includes/functions.php.
    if ($data['trusted_proxy_ips'] !== (string)($cfg['trusted_proxy_ips'] ?? '')) {
        $clean = [];
        foreach (ipParseList((string)$data['trusted_proxy_ips']) as $entry) {
            // Named, not merely counted: an error that says only "invalid" makes an operator retype
            // the whole list instead of fixing the one entry that is wrong.
            if (!ipCidrValid($entry)) {
                jsonResponse(['error' => __('api.settings.trusted_proxy_invalid', ['entry' => $entry])], 400);
            }
            if (!trustedProxyEntryOk($entry)) {
                // ':four' / ':six' and not ':v4' / ':v6': the dictionary's placeholder syntax is
                // `:name` and tests/lang_test.php extracts them with /:[a-z_]+/, so a digit ends the
                // name — ':v4' would be read as the placeholder ':v' and never substituted.
                jsonResponse(['error' => __('api.settings.trusted_proxy_too_wide', ['entry' => $entry,
                              'four' => TRUSTED_PROXY_MIN_BITS4, 'six' => TRUSTED_PROXY_MIN_BITS6])], 400);
            }
            $clean[] = $entry;
        }
        $data['trusted_proxy_ips'] = implode(', ', $clean);
    }
}
if (isset($data['cookie_secure_mode'])) {
    $mode = strtolower(trim((string)$data['cookie_secure_mode']));
    if (!in_array($mode, COOKIE_SECURE_MODES, true)) {
        jsonResponse(['error' => __('api.settings.cookie_secure_mode_invalid')], 400);
    }
    $data['cookie_secure_mode'] = $mode;
}
if (isset($data['client_proto_header'])) {
    // Empty is a real answer: it switches the proxy-header path off completely.
    $ph = trim((string)$data['client_proto_header']);
    if ($ph !== '' && !preg_match('/^[A-Za-z0-9-]{1,64}$/', $ph)) {
        jsonResponse(['error' => __('api.settings.proto_header_invalid')], 400);
    }
    $data['client_proto_header'] = $ph;
}

$transportChanged = function (string $k, string $default) use ($data, $cfg): bool {
    return array_key_exists($k, $data) && $data[$k] !== (string)($cfg[$k] ?? $default);
};
$effCfg   = $data + $cfg;                 // what the site looks like AFTER this save
$effHttps = requestIsHttps($effCfg);

if ($transportChanged('cookie_secure_mode', 'auto') && $data['cookie_secure_mode'] === 'always' && !$effHttps) {
    jsonResponse(['error' => __('api.settings.cookie_secure_needs_https')], 400);
}
if ($transportChanged('hsts_enabled', '0') && $data['hsts_enabled'] === '1' && !$effHttps) {
    jsonResponse(['error' => __('api.settings.hsts_needs_https')], 400);
}
// preload is the one setting here nothing on this server can undo, so its two preconditions — the
// preload list's OWN requirements — are checked against the values this save would leave behind,
// not against the field alone. hstsHeaderValue() checks them a second time before printing the
// token, so a row edited by hand cannot publish a claim the site does not satisfy either.
if ($transportChanged('hsts_preload', '0') && $data['hsts_preload'] === '1'
    && ((string)($effCfg['hsts_include_subdomains'] ?? '0') !== '1'
        || (int)($effCfg['hsts_max_age'] ?? HSTS_MAX_AGE_DEFAULT) < HSTS_PRELOAD_MIN_AGE)) {
    jsonResponse(['error' => __('api.settings.hsts_preload_requires', ['age' => HSTS_PRELOAD_MIN_AGE])], 400);
}

// Validate and sanitize donation_fields JSON
if (isset($data['donation_fields'])) {
    $fields = json_decode($data['donation_fields'], true);
    if (!is_array($fields)) $fields = [];
    $fields = array_slice($fields, 0, 15);
    $clean = [];
    foreach ($fields as $f) {
        $label = trim($f['label'] ?? '');
        $value = trim($f['value'] ?? '');
        if ($label !== '' && $value !== '') {
            $clean[] = ['label' => mb_substr($label, 0, 100), 'value' => mb_substr($value, 0, 500)];
        }
    }
    $data['donation_fields'] = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// Guard the tracker service name: it is later handed to systemctl, so reject anything that isn't a
// plain systemd unit name (letters, digits and . _ @ -). Empty is allowed — it disables the feature.
if (isset($data['opentracker_service_name']) && $data['opentracker_service_name'] !== ''
    && !isServiceNameValid($data['opentracker_service_name'])) {
    jsonResponse(['error' => __('api.settings.service_name_invalid')], 400);
}

// Password confirmation when changing deletion limits
$deleteCaptchaAttempts = trim((string)($input['delete_captcha_attempts'] ?? '2'));
$deleteLockoutAttempts = trim((string)($input['delete_lockout_attempts'] ?? '5'));
$deleteLockoutMinutes = trim((string)($input['delete_lockout_minutes'] ?? '60'));

$currentDeleteCaptcha = $cfg['delete_captcha_attempts'] ?? '2';
$currentDeleteLockout = $cfg['delete_lockout_attempts'] ?? '5';
$currentDeleteLockoutMin = $cfg['delete_lockout_minutes'] ?? '60';

$limitsChanged = $deleteCaptchaAttempts !== $currentDeleteCaptcha ||
                 $deleteLockoutAttempts !== $currentDeleteLockout ||
                 $deleteLockoutMinutes !== $currentDeleteLockoutMin;

if ($limitsChanged) {
    $confirmPassword = $input['confirm_password'] ?? '';
    if (empty($confirmPassword)) {
        jsonResponse(['error' => __('api.settings.reauth_required_limits'),
                      'reauth_required' => true], 403);
    }
    requireAdminReauth($confirmPassword, $cfg);
}

// Password confirmation when changing what the server runs or whom it trusts ($reauthKeys above).
// Checked AFTER validation so the prompt is only ever shown for a save that would otherwise go
// through, and against the normalised value, so a cosmetic difference (trailing space) is not a
// change. `reauth_required` is what the settings page keys the password modal on: the page does
// not carry a copy of the list, it asks, gets this, and asks the operator.
$reauthChanged = [];
foreach ($reauthKeys as $k => $fallback) {
    if (array_key_exists($k, $data) && $data[$k] !== (string)($cfg[$k] ?? $fallback)) $reauthChanged[] = $k;
}
if ($reauthChanged) {
    $confirmPassword = (string)($input['confirm_password'] ?? '');
    if ($confirmPassword === '') {
        jsonResponse(['error' => __('api.settings.reauth_required', ['keys' => implode(', ', $reauthChanged)]),
                      'reauth_required' => true, 'reauth_keys' => $reauthChanged], 403);
    }
    requireAdminReauth($confirmPassword, $cfg);
}

// The tracker mode is not an ordinary setting: it describes something OUTSIDE the database.
//
// Writing this row tells the panel which list to generate and what the public pages promise; it does
// not move the symlinks or restart the service, and until now nothing said so. An operator could
// select "whitelist", watch a whitelist file appear, and be served by the blacklist build — with
// every status card agreeing, because they all read the row that had just been written.
//
// Saving still writes it (it really does govern the panel), but the answer now carries the truth,
// and it is asked of the helper AFTER the write so it describes the state the admin is now in.
$modeWasChanged = array_key_exists('tracker_mode', $data)
    && (string)$data['tracker_mode'] !== (string)($cfg['tracker_mode'] ?? 'blacklist');

// The BEFORE snapshot has to be taken while $cfg still holds it — after setSettings() there is
// nothing left to compare against, and "what did this change" is the only question anybody asks of a
// settings log.
$auditDiff = function_exists('auditSettingsDiff') ? auditSettingsDiff($cfg, $data) : [];

setSettings($db, $data);

if ($auditDiff) {
    auditNote([
        'summary' => count($auditDiff) . ' setting' . (count($auditDiff) === 1 ? '' : 's') . ' changed: '
                   . mb_substr(implode(', ', array_keys($auditDiff)), 0, 180),
        'detail'  => $auditDiff,
    ]);
}

// The panel bakes the sign-in address into <body data-login-path> at render time and the Logout
// buttons read it from there; saving is pure AJAX, so hand the applied value back and let the page
// refresh it — otherwise Logout would keep pointing at the address that was just replaced.
$applied = [];
if (array_key_exists('admin_login_path', $data)) $applied['admin_login_path'] = adminLoginPath($data + $cfg);

$warning = null;
if ($modeWasChanged) {
    $cfg['tracker_mode'] = (string)$data['tracker_mode'];
    $agree = function_exists('scheduleModeAgreement') ? scheduleModeAgreement($cfg, true) : ['known' => false];
    if (!empty($agree['known']) && $agree['match'] === false) {
        $warning = __('api.settings.saved_tracker_not_switched', ['actual' => $agree['actual'], 'panel' => $agree['panel']]);
    } elseif (empty($agree['known'])) {
        $warning = __('api.settings.saved_mode_unconfirmed', ['detail' => (!empty($agree['error']) ? ' (' . $agree['error'] . ')' : '')]);
    }
}

jsonResponse(['success' => true] + ($applied ? ['applied' => $applied] : [])
             + ($warning !== null ? ['warning' => $warning] : []));
