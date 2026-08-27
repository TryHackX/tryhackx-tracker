<?php
requirePost();

$input = readJsonBody();
if (!$input || !is_array($input)) {
    jsonResponse(['error' => 'Invalid input'], 400);
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
    'login_lockout_attempts', 'login_lockout_minutes',
    'rate_limit', 'rate_limit_status', 'rate_limit_block_check', 'rate_limit_appeal',
    'admin_session_idle_minutes', 'admin_session_absolute_hours',
    // where the panel lives (includes/auth.php)
    'admin_login_path', 'admin_hidden_behavior',
    'trusted_proxy_ips', 'client_ip_header',
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
    // whitelist registration audience + metadata worker concurrency (schema v8)
    'whitelist_submit_mode', 'meta_worker_concurrency',
    // schema v9: verification gate, terms, email-change cooldown, member-search switches
    'users_require_email_verify', 'users_terms_text', 'users_email_change_cooldown_days',
    'index_search_enabled', 'index_search_include_whitelist',
    // federation (includes/federation.php + worker/federation.py)
    'fed_enabled', 'fed_node_name', 'fed_export_enabled', 'fed_export_files', 'fed_export_max_batch',
    'fed_import_new', 'fed_pull_minutes',
];

$data = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $input)) {
        $data[$key] = trim((string)$input[$key]);
    }
}

if (empty($data)) {
    jsonResponse(['error' => 'No valid settings provided'], 400);
}

// ── Whitelist / API / CAPTCHA settings validation ──
if (isset($data['tracker_mode']) && !in_array($data['tracker_mode'], ['blacklist', 'whitelist'], true)) {
    jsonResponse(['error' => 'Invalid tracker mode.'], 400);
}
if (isset($data['whitelist_path'])) {
    $data['whitelist_path'] = normalizeListPath($data['whitelist_path']);
    if ($data['whitelist_path'] !== '') {
        $v = validateWhitelistPath($data['whitelist_path']);
        if (!$v['ok']) jsonResponse(['error' => 'Whitelist path rejected: ' . $v['error']], 400);
    }
}
if (isset($data['captcha_provider']) && !in_array($data['captcha_provider'], captchaProviders(), true)) {
    jsonResponse(['error' => 'Invalid CAPTCHA provider.'], 400);
}
// ── Where the admin panel lives ──
if (isset($data['admin_login_path'])) {
    $path = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($data['admin_login_path'])));
    if ($path === '' || $path === null) $path = 'admin';
    if (strlen($path) > 64) jsonResponse(['error' => 'Admin sign-in address is too long (max 64 characters).'], 400);
    if ($path !== 'admin' && in_array($path, adminReservedActions(), true)) {
        jsonResponse(['error' => 'That admin sign-in address is already used by another page. Pick a different one.'], 400);
    }
    $data['admin_login_path'] = $path;
}
if (isset($data['admin_hidden_behavior']) && !in_array($data['admin_hidden_behavior'], ['home', 'login', '404'], true)) {
    $data['admin_hidden_behavior'] = 'home';
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
    jsonResponse(['error' => 'Scrape URL must be an http(s) URL.'], 400);
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
    'admin_near_pages' => [1, 20, 2],
    'users_notify_expiry_days' => [0, 30, 3], 'users_email_change_cooldown_days' => [0, 365, 30],
    'rate_limit_user_login' => [0, 1000, 10],
    'rate_limit_user_register' => [0, 1000, 5], 'rate_limit_index_search' => [0, 100000, 120],
    'fed_export_max_batch' => [100, 20000, 2000], 'fed_pull_minutes' => [5, 1440, 60],
    // UDP monitor + rate limit (includes/netlimit.php)
    'net_sample_seconds' => [NET_SAMPLE_MIN, NET_SAMPLE_MAX, 60], 'net_keep_days' => [NET_KEEP_MIN, NET_KEEP_MAX, 14],
    'net_limit_pps' => [NET_PPS_MIN, NET_PPS_MAX, 30000], 'net_limit_burst' => [NET_BURST_MIN, NET_BURST_MAX, 100],
    'net_limit_port' => [1, 65535, 6969],
    'net_auto_min' => [NET_PPS_MIN, NET_PPS_MAX, 10000], 'net_auto_max' => [NET_PPS_MIN, NET_PPS_MAX, 80000],
    'net_auto_target' => [NET_PPS_MIN, NET_PPS_MAX, 30000], 'net_auto_target_cpu' => [10, 100, 70],
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
foreach (['whitelist_public_enabled', 'api_enabled', 'whitelist_require_tracker', 'tracker_schedule_enabled', 'stats_timeline_enabled', 'stats_timeline_public', 'stats_timeline_custom_range', 'index_enabled', 'index_keep_files', 'index_meta_auto_queue',
          'users_enabled', 'users_registration_enabled', 'users_links_visible',
          'users_require_email_verify', 'index_search_enabled', 'index_search_include_whitelist',
          'fed_enabled', 'fed_export_enabled', 'fed_export_files', 'fed_import_new',
          'net_monitor_enabled', 'net_limit_enabled', 'net_auto_enabled',
          'backup_enabled', 'backup_verify_after'] as $k) {
    if (isset($data[$k])) $data[$k] = $data[$k] === '1' ? '1' : '0';
}
// ── UDP rate limit ──
// The helper command is handed to the shell, so it gets the same treatment as the mode switch
// command: a strict character class here, escapeshellarg() on every argument in includes/netlimit.php.
if (isset($data['net_limit_cmd']) && !netlimitValidCommand($data['net_limit_cmd'])) {
    jsonResponse(['error' => 'Invalid rate-limit helper command: only letters, digits, space and _ . / - are allowed (no shell metacharacters); the action arguments are appended automatically. Leave empty to disable the feature.'], 400);
}
// An upside-down automatic band would let one save lock the limit at a single value.
if (isset($data['net_auto_min']) || isset($data['net_auto_max'])) {
    $min = (int)($data['net_auto_min'] ?? netlimitAutoMin($cfg));
    $max = (int)($data['net_auto_max'] ?? netlimitAutoMax($cfg));
    if ($max < $min) jsonResponse(['error' => 'The automatic band is upside down: the maximum (' . number_format($max) . ' pps) must not be below the minimum (' . number_format($min) . ' pps).'], 400);
}
// ── Backups ──
// The directory is where archives full of database passwords land, so it is checked here as well as
// in the helper — a save must never be able to point it at the web root.
if (isset($data['backup_dir']) && $data['backup_dir'] !== '') {
    $v = backupValidateDir($data['backup_dir']);
    if (!$v['ok']) jsonResponse(['error' => 'Backup directory rejected: ' . $v['error'] . ($v['hint'] ? ' ' . $v['hint'] : '')], 400);
    $data['backup_dir'] = rtrim(preg_replace('/[\x00-\x1F\x7F]/', '', trim($data['backup_dir'])), '/');
}
if (isset($data['backup_cmd']) && !backupValidCommand($data['backup_cmd'])) {
    jsonResponse(['error' => 'Invalid backup helper command: only letters, digits, space and _ . / - are allowed (no shell metacharacters); the action arguments are appended automatically.'], 400);
}
if (isset($data['backup_script_path']) && $data['backup_script_path'] !== ''
    && !preg_match('#^/[A-Za-z0-9 _./-]{1,255}$#', $data['backup_script_path'])) {
    jsonResponse(['error' => 'The path to Backup-serwera.sh must be absolute and free of shell metacharacters.'], 400);
}
if (isset($data['backup_profile']) && !in_array($data['backup_profile'], BACKUP_PROFILES, true)) {
    jsonResponse(['error' => 'Unknown backup profile.'], 400);
}
if (isset($data['backup_items'])) {
    $data['backup_items'] = backupSanitizeItems($data['backup_items']);
}
if (isset($data['backup_schedule']) && trim($data['backup_schedule']) !== ''
    && backupParseSchedule($data['backup_schedule']) === null) {
    jsonResponse(['error' => 'Invalid backup schedule: pick at least one weekday and a time between 00:00 and 23:59.'], 400);
}
if (isset($data['backup_schedule_tz']) && $data['backup_schedule_tz'] !== ''
    && !in_array($data['backup_schedule_tz'], timezone_identifiers_list(), true)) {
    jsonResponse(['error' => 'Invalid backup timezone. Use an IANA identifier such as Europe/Warsaw or UTC.'], 400);
}
if (isset($data['backup_gpg_recipient']) && $data['backup_gpg_recipient'] !== ''
    && !preg_match('/^[A-Za-z0-9@._+-]{1,128}$/', $data['backup_gpg_recipient'])) {
    jsonResponse(['error' => 'Invalid GPG recipient: use a key id, fingerprint or email address.'], 400);
}
if (isset($data['backup_db_name']) && $data['backup_db_name'] !== ''
    && !preg_match('/^[A-Za-z0-9_]{1,64}$/', $data['backup_db_name'])) {
    jsonResponse(['error' => 'Invalid database name: letters, digits and _ only.'], 400);
}
if (isset($data['users_default_group'])) {
    $data['users_default_group'] = strtolower($data['users_default_group']);
    if (!preg_match('/^[a-z0-9_-]{2,64}$/', $data['users_default_group'])) $data['users_default_group'] = 'member';
}
if (isset($data['whitelist_submit_mode']) && !in_array($data['whitelist_submit_mode'], ['public', 'users'], true)) {
    jsonResponse(['error' => 'Invalid whitelist registration audience.'], 400);
}
if (isset($data['users_terms_text'])) {
    $data['users_terms_text'] = mb_substr($data['users_terms_text'], 0, 10000);
}
if (isset($data['mail_from_email']) && $data['mail_from_email'] !== '') {
    if (!filter_var($data['mail_from_email'], FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Sender address is not a valid email.'], 400);
    }
    // From must live on the site's own domain (or a parent of it) — anything else breaks
    // SPF/DKIM/DMARC alignment and lands in spam. The check uses the site_url being saved
    // alongside, falling back to the stored one.
    $allowed = mailFromAllowedHosts(['site_url' => $data['site_url'] ?? ($cfg['site_url'] ?? '')]);
    $fromHost = strtolower(substr(strrchr($data['mail_from_email'], '@'), 1));
    if ($allowed && !in_array($fromHost, $allowed, true)) {
        jsonResponse(['error' => 'Sender domain must be the site domain or its parent (' . implode(', ', $allowed) . ').'], 400);
    }
}
if (isset($data['meta_worker_concurrency']) && $data['meta_worker_concurrency'] !== '') {
    // empty = keep the worker's own config; otherwise clamp to a sane 1..16
    $n = is_numeric($data['meta_worker_concurrency']) ? (int)$data['meta_worker_concurrency'] : 0;
    if ($n < 1) $data['meta_worker_concurrency'] = '';
    else $data['meta_worker_concurrency'] = (string)min(16, $n);
}
if (isset($data['fed_node_name'])) {
    $data['fed_node_name'] = mb_substr(preg_replace('/[^\w .\-]/u', '', $data['fed_node_name']) ?? '', 0, 64);
}
if (isset($data['index_source_url']) && $data['index_source_url'] !== '' && !preg_match('#^https?://[^\s]+$#i', $data['index_source_url'])) {
    jsonResponse(['error' => 'Index source URL must be an http(s) URL.'], 400);
}
// ── Scheduled tracker mode ──
if (isset($data['tracker_schedule'])) {
    $sched = scheduleParseJson($data['tracker_schedule']);
    if ($sched === null) jsonResponse(['error' => 'Invalid schedule: use "all", "none" or {"from":"HH:MM","to":"HH:MM"} for each of mon..sun (times 00:00–23:59).'], 400);
    $data['tracker_schedule'] = json_encode($sched);   // normalised, all 7 keys
}
if (isset($data['tracker_schedule_tz']) && !scheduleValidTimezone($data['tracker_schedule_tz'])) {
    jsonResponse(['error' => 'Invalid schedule timezone. Use an IANA identifier such as Europe/Warsaw or UTC.'], 400);
}
if (isset($data['tracker_mode_switch_cmd']) && !scheduleValidSwitchCommand($data['tracker_mode_switch_cmd'])) {
    jsonResponse(['error' => 'Invalid mode switch command: only letters, digits, space and _ . / - are allowed (no shell metacharacters); the mode argument is appended automatically. Leave empty to only flip the setting.'], 400);
}
if (isset($data['api_ban_exempt_ips'])) {
    $clean = [];
    foreach (apiParseIpList($data['api_ban_exempt_ips']) as $e) {
        $ipPart = explode('/', $e, 2)[0];
        if (filter_var($ipPart, FILTER_VALIDATE_IP)) $clean[] = $e;
    }
    $data['api_ban_exempt_ips'] = implode(', ', $clean);
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
    jsonResponse(['error' => 'Invalid tracker service name. Use only letters, digits and . _ @ - (e.g. "opentracker").'], 400);
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
        jsonResponse(['error' => 'Password confirmation is required to change deletion limits.'], 403);
    }
    if (!password_verify($confirmPassword, ADMIN_PASSWORD_HASH)) {
        jsonResponse(['error' => 'Incorrect password.'], 403);
    }
}

setSettings($db, $data);

// The panel bakes the sign-in address into <body data-login-path> at render time and the Logout
// buttons read it from there; saving is pure AJAX, so hand the applied value back and let the page
// refresh it — otherwise Logout would keep pointing at the address that was just replaced.
$applied = [];
if (array_key_exists('admin_login_path', $data)) $applied['admin_login_path'] = adminLoginPath($data + $cfg);

jsonResponse(['success' => true] + ($applied ? ['applied' => $applied] : []));
