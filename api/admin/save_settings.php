<?php
requirePost();

$input = readJsonBody();
if (!$input || !is_array($input)) {
    jsonResponse(['error' => 'Invalid input'], 400);
}

// Whitelist of allowed setting keys
$allowed = [
    'site_name', 'site_url', 'site_email',
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
    'trusted_proxy_ips', 'client_ip_header',
    'items_per_page', 'blacklist_path',
    'max_magnet_link_length',
    'donations_enabled', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'donation_fields',
    'transparency_enabled', 'transparency_per_page',
    'tracker_stats_enabled', 'tracker_stats_url', 'tracker_stats_interval', 'tracker_stats_page_interval', 'tracker_stats_cache_ttl', 'tracker_stats_show_home', 'tracker_stats_timeout', 'tracker_stats_min_loading', 'tracker_stats_max_loading', 'tracker_stats_peer_label_style', 'tracker_stats_livesync_mode',
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
    'whitelist_scrape_url',
    // captcha provider
    'captcha_provider', 'turnstile_site_key', 'turnstile_secret',
    // server-to-server API
    'api_enabled', 'api_ban_days', 'api_ban_exempt_ips',
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
if (isset($data['captcha_provider']) && !in_array($data['captcha_provider'], ['recaptcha', 'turnstile'], true)) {
    jsonResponse(['error' => 'Invalid CAPTCHA provider.'], 400);
}
if (isset($data['whitelist_scrape_url']) && $data['whitelist_scrape_url'] !== '' && !preg_match('#^https?://[^\s]+$#i', $data['whitelist_scrape_url'])) {
    jsonResponse(['error' => 'Scrape URL must be an http(s) URL.'], 400);
}
$intClamp = [
    'whitelist_max_per_submission' => [1, 500, 20], 'rate_limit_whitelist' => [0, 1000, 10],
    'whitelist_ip_daily_max' => [0, 100000, 50], 'whitelist_daily_cap' => [0, 10000000, 2000],
    'whitelist_reload_min_interval' => [10, 3600, 45], 'api_ban_days' => [1, 3650, 30],
];
foreach ($intClamp as $k => [$min, $max, $def]) {
    if (isset($data[$k])) {
        $n = is_numeric($data[$k]) ? (int)$data[$k] : $def;
        $data[$k] = (string)max($min, min($max, $n));
    }
}
foreach (['whitelist_public_enabled', 'api_enabled'] as $k) {
    if (isset($data[$k])) $data[$k] = $data[$k] === '1' ? '1' : '0';
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

jsonResponse(['success' => true]);
