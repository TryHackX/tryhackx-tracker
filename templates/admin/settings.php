<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
</head>
<body class="admin-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>">
    <div class="admin-container">
        <div class="admin-header">
            <h2>Settings</h2>
            <div class="admin-header-actions">
                <a href="<?= $baseUrl ?>?action=admin" class="btn btn-sm btn-outline-info"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <button class="btn btn-sm btn-outline-danger" id="btn-logout"><i class="bi bi-box-arrow-right"></i> Logout</button>
            </div>
        </div>

        <form id="settings-form">
            <!-- Site Configuration -->
            <div class="settings-section">
                <h5>Site Configuration</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Site Name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="site_name" value="<?= sanitize($cfg['site_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Site URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="site_url" value="<?= sanitize($cfg['site_url'] ?? '') ?>" placeholder="https://example.com/">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Announce URL (HTTP/S)</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="announce_url_https" value="<?= sanitize($cfg['announce_url_https'] ?? '') ?>" placeholder="https://tracker.example.com:443/announce">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Announce URL (UDP)</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="announce_url" value="<?= sanitize($cfg['announce_url'] ?? '') ?>" placeholder="udp://tracker.example.com:6969/announce">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">GitHub URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="github_url" value="<?= sanitize($cfg['github_url'] ?? '') ?>" placeholder="https://github.com/YourOrg">
                    </div>
                </div>
            </div>

            <!-- Contact & Email -->
            <div class="settings-section">
                <h5>Contact &amp; Email</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Site Email</label>
                        <input type="email" class="form-control bg-dark text-light border-secondary" name="site_email" value="<?= sanitize($cfg['site_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Show Contact</label>
                        <select class="form-select bg-dark text-light border-secondary" name="contact_visible">
                            <option value="1" <?= ($cfg['contact_visible'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['contact_visible'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Obfuscate Email</label>
                        <select class="form-select bg-dark text-light border-secondary" name="contact_obfuscate">
                            <option value="1" <?= ($cfg['contact_obfuscate'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['contact_obfuscate'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">HMAC Secret (for unsubscribe tokens)</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="hmac_secret" value="<?= sanitize($cfg['hmac_secret'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- reCAPTCHA v2 -->
            <div class="settings-section">
                <h5>reCAPTCHA v2</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Enable reCAPTCHA</label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_enabled">
                            <option value="1" <?= ($cfg['recaptcha_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['recaptcha_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Site Key</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="recaptcha_site_key" value="<?= sanitize($cfg['recaptcha_site_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Secret Key</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="recaptcha_secret" value="<?= sanitize($cfg['recaptcha_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">On Report Form</label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_report">
                            <option value="1" <?= ($cfg['recaptcha_on_report'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['recaptcha_on_report'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">On Admin Login</label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_login">
                            <option value="1" <?= ($cfg['recaptcha_on_login'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['recaptcha_on_login'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">On Status Check</label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_status">
                            <option value="1" <?= ($cfg['recaptcha_on_status'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['recaptcha_on_status'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">On Appeal Forms</label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_appeal">
                            <option value="1" <?= ($cfg['recaptcha_on_appeal'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['recaptcha_on_appeal'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">On Block Check</label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_block_check">
                            <option value="1" <?= ($cfg['recaptcha_on_block_check'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['recaptcha_on_block_check'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Smart CAPTCHA -->
            <div class="settings-section">
                <h5>Smart CAPTCHA</h5>
                <small class="settings-hint d-block mb-3">CAPTCHA only appears after a user accumulates enough activity points. Solving it grants a grace period where no CAPTCHA is required. Failed admin logins always reset the grace period.</small>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Point threshold</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_threshold" value="<?= sanitize($cfg['captcha_threshold'] ?? '6') ?>" min="1" max="100">
                        <small class="settings-hint">CAPTCHA appears when points reach this value.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Grace period (minutes)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_grace_minutes" value="<?= sanitize($cfg['captcha_grace_minutes'] ?? '5') ?>" min="0" max="60">
                        <small class="settings-hint">After solving CAPTCHA, bypass all CAPTCHAs for this many minutes.</small>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info">Points per action</small></div>
                    <div class="col-md-2">
                        <label class="form-label">Report</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_report" value="<?= sanitize($cfg['captcha_pts_report'] ?? '2') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Report status</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_status" value="<?= sanitize($cfg['captcha_pts_status'] ?? '1') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Block check</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_block_check" value="<?= sanitize($cfg['captcha_pts_block_check'] ?? '1') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Appeal</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_appeal" value="<?= sanitize($cfg['captcha_pts_appeal'] ?? '3') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Failed login</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_login_fail" value="<?= sanitize($cfg['captcha_pts_login_fail'] ?? '6') ?>" min="0" max="100">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info">Report deletion security limits</small></div>
                    <div class="col-md-4">
                        <label class="form-label">Failed attempts before CAPTCHA</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="delete_captcha_attempts" value="<?= sanitize($cfg['delete_captcha_attempts'] ?? '2') ?>" min="1" max="50">
                        <small class="settings-hint">Forces reCAPTCHA on deletion modal after this many password mistakes.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Failed attempts before Lockout</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="delete_lockout_attempts" value="<?= sanitize($cfg['delete_lockout_attempts'] ?? '5') ?>" min="1" max="50">
                        <small class="settings-hint">Locks out report deletions after this many password mistakes.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Lockout duration (minutes)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="delete_lockout_minutes" value="<?= sanitize($cfg['delete_lockout_minutes'] ?? '60') ?>" min="1" max="1440">
                        <small class="settings-hint">Duration of lockout in minutes.</small>
                    </div>
                </div>
            </div>

            <!-- Public Pages -->
            <div class="settings-section">
                <h5>Public Pages</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Auto-archive reports <small class="settings-hint">(0 = disabled)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="auto_archive_days" value="<?= sanitize($cfg['auto_archive_days'] ?? '90') ?>" min="0" max="9999">
                        <small class="settings-hint">Automatically archive reviewed reports older than X days.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Auto-archive appeals <small class="settings-hint">(0 = disabled)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="auto_archive_appeal_days" value="<?= sanitize($cfg['auto_archive_appeal_days'] ?? '90') ?>" min="0" max="9999">
                        <small class="settings-hint">Automatically archive resolved appeals older than X days.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Delete email log after <small class="settings-hint">(0 = keep forever)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="sent_emails_retention_days" value="<?= sanitize($cfg['sent_emails_retention_days'] ?? '0') ?>" min="0" max="9999">
                        <small class="settings-hint">Prune sent_emails rows older than X days. Off by default.</small>
                    </div>
                </div>
            </div>

            <!-- Rate Limits & Blacklist -->
            <div class="settings-section">
                <h5>Rate Limits &amp; Blacklist</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Reports per hour (per IP)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit" value="<?= sanitize($cfg['rate_limit'] ?? '5') ?>" min="1" max="100">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status checks / hour (per IP) <small class="settings-hint">(0 = off)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_status" value="<?= sanitize($cfg['rate_limit_status'] ?? '20') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Block lookups / hour (per IP) <small class="settings-hint">(0 = off)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_block_check" value="<?= sanitize($cfg['rate_limit_block_check'] ?? '30') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Appeals / hour (per IP) <small class="settings-hint">(0 = off)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_appeal" value="<?= sanitize($cfg['rate_limit_appeal'] ?? '5') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Reports per page (admin)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="items_per_page" value="<?= sanitize($cfg['items_per_page'] ?? '25') ?>" min="5" max="200">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Max message length</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="max_message_length" value="<?= sanitize($cfg['max_message_length'] ?? '2000') ?>" min="100" max="10000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Max appeal reason length</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="max_appeal_message_length" value="<?= sanitize($cfg['max_appeal_message_length'] ?? '2000') ?>" min="100" max="10000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Max magnet link length <small class="settings-hint">(0 = unlimited)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="max_magnet_link_length" value="<?= sanitize($cfg['max_magnet_link_length'] ?? '0') ?>" min="0" max="100000">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-8">
                        <label class="form-label">Blacklist file path</label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="blacklist_path" value="<?= sanitize($cfg['blacklist_path'] ?? '') ?>" placeholder="/home/tracker/blacklist">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-blacklist">Test</button>
                        </div>
                        <div id="blacklist-result" class="mt-1 blacklist-result"></div>
                    </div>
                </div>
            </div>

            <!-- Admin Sessions, Login Lockout & Proxy -->
            <div class="settings-section">
                <h5>Admin Sessions &amp; Proxy</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Session idle timeout (min) <small class="settings-hint">(0 = off)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_session_idle_minutes" value="<?= sanitize($cfg['admin_session_idle_minutes'] ?? '30') ?>" min="0" max="1440">
                        <small class="settings-hint">Logs the admin out after this long with no activity.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Session absolute cap (hours) <small class="settings-hint">(0 = off)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_session_absolute_hours" value="<?= sanitize($cfg['admin_session_absolute_hours'] ?? '12') ?>" min="0" max="720">
                        <small class="settings-hint">Hard limit since login regardless of activity.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Login lockout attempts</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="login_lockout_attempts" value="<?= sanitize($cfg['login_lockout_attempts'] ?? '5') ?>" min="1" max="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Login lockout window (min)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="login_lockout_minutes" value="<?= sanitize($cfg['login_lockout_minutes'] ?? '15') ?>" min="1" max="1440">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Trusted proxy IPs <small class="settings-hint">(comma separated, leave empty if no proxy)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="trusted_proxy_ips" value="<?= sanitize($cfg['trusted_proxy_ips'] ?? '') ?>" placeholder="e.g. 173.245.48.1, 103.21.244.0">
                        <small class="settings-hint">Only when the request comes from one of these is the forwarded-IP header trusted.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Client IP header <small class="settings-hint">(leave empty to use the raw connection IP)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="client_ip_header" value="<?= sanitize($cfg['client_ip_header'] ?? '') ?>" placeholder="e.g. CF-Connecting-IP or X-Forwarded-For">
                        <small class="settings-hint">Needed for correct per-IP rate limiting behind Cloudflare / a reverse proxy.</small>
                    </div>
                </div>
            </div>

            <!-- Donation Fields -->
            <div class="settings-section">
                <h5>Donation Fields</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Show Donations</label>
                        <select class="form-select bg-dark text-light border-secondary" name="donations_enabled">
                            <option value="1" <?= ($cfg['donations_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['donations_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <small class="settings-hint">Add up to 15 custom fields. URLs (http/https) will display as clickable links. Other values (wallet addresses, hashes) will display as copyable code.</small>
                    </div>
                </div>
                <?php
                    $donationFields = json_decode($cfg['donation_fields'] ?? '[]', true);
                    if (!is_array($donationFields)) $donationFields = [];
                ?>
                <div id="donation-fields-list" class="mt-2">
                    <?php foreach ($donationFields as $i => $field): ?>
                    <div class="row g-2 mb-2 donation-field-row">
                        <div class="col-md-3">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="Label" value="<?= sanitize($field['label'] ?? '') ?>" data-df="label">
                        </div>
                        <div class="col">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="Address, hash, or URL" value="<?= sanitize($field['value'] ?? '') ?>" data-df="value">
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-danger donation-field-remove" title="Remove"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-info mt-1" id="donation-field-add"><i class="bi bi-plus-lg"></i> Add Field</button>
            </div>

            <!-- Transparency Page -->
            <div class="settings-section">
                <h5>Transparency Page</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Enable Transparency</label>
                        <select class="form-select bg-dark text-light border-secondary" name="transparency_enabled">
                            <option value="1" <?= ($cfg['transparency_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['transparency_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Results per page</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="transparency_per_page" value="<?= sanitize($cfg['transparency_per_page'] ?? '150') ?>" min="10" max="500">
                    </div>
                </div>
            </div>

            <!-- Tracker Statistics -->
            <div class="settings-section">
                <h5>Tracker Statistics</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Enable Tracker Stats</label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_enabled">
                            <option value="1" <?= ($cfg['tracker_stats_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['tracker_stats_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Stats Source URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="tracker_stats_url" value="<?= sanitize($cfg['tracker_stats_url'] ?? '') ?>" placeholder="http://YOUR_TRACKER_HOST:6969/stats?mode=everything">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Home Refresh Interval (Sec)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_interval" value="<?= sanitize($cfg['tracker_stats_interval'] ?? '10') ?>" min="2" max="3600">
                        <small class="settings-hint">How often the homepage widget polls for fresh data.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Stats Page Refresh Interval (Sec)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_page_interval" value="<?= sanitize($cfg['tracker_stats_page_interval'] ?? ($cfg['tracker_stats_interval'] ?? '10')) ?>" min="2" max="3600">
                        <small class="settings-hint">How often the /?action=stats page re-checks the cache. Cheap cache hits — does not re-fetch upstream.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cache Lifetime / TTL (Sec)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_cache_ttl" value="<?= sanitize($cfg['tracker_stats_cache_ttl'] ?? '60') ?>" min="2" max="86400">
                        <small class="settings-hint">Shared server-side lifetime of the fetched stats. While the cache is younger than this, everyone is served the same data and the upstream tracker is <strong>not</strong> re-fetched. Set this &ge; the typical upstream fetch time (often 30&ndash;60s) so reloads don't trigger constant re-syncs.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Show on Homepage</label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_show_home">
                            <option value="1" <?= ($cfg['tracker_stats_show_home'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['tracker_stats_show_home'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Seeds/Leechers Label Style</label>
                        <?php $pls = $cfg['tracker_stats_peer_label_style'] ?? 'percent'; ?>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_peer_label_style">
                            <option value="percent" <?= $pls === 'percent' ? 'selected' : '' ?>>Percent of total peers (44% / 56%)</option>
                            <option value="absolute" <?= $pls === 'absolute' ? 'selected' : '' ?>>Absolute (of N peers)</option>
                            <option value="peers_card" <?= $pls === 'peers_card' ? 'selected' : '' ?>>Peers card (leechers &middot; seeds)</option>
                        </select>
                        <small class="settings-hint">How the Seeds/Leechers cards on the stats page are labelled.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Live Syncs Counter</label>
                        <?php $lsm = ($cfg['tracker_stats_livesync_mode'] ?? 'upstream') === 'local' ? 'local' : 'upstream'; ?>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_livesync_mode">
                            <option value="upstream" <?= $lsm === 'upstream' ? 'selected' : '' ?>>Tracker value (raw, often 0)</option>
                            <option value="local" <?= $lsm === 'local' ? 'selected' : '' ?>>Count our cache refreshes</option>
                        </select>
                        <small class="settings-hint">OpenTracker's livesync is 0 on single-node setups. &ldquo;Count our cache refreshes&rdquo; repurposes the <em>Live Syncs</em> stat as the number of times we refreshed the cache since the tracker last started (auto-resets on tracker restart).</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Request Timeout (Sec)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_timeout" value="<?= sanitize($cfg['tracker_stats_timeout'] ?? '30') ?>" min="2" max="300">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Min Loading Delay (ms)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_min_loading" value="<?= sanitize($cfg['tracker_stats_min_loading'] ?? '1000') ?>" min="0" max="10000" step="50">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max Loading Delay (ms)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_max_loading" value="<?= sanitize($cfg['tracker_stats_max_loading'] ?? '1000') ?>" min="0" max="10000" step="50">
                    </div>
                </div>
                <div class="row mt-1">
                    <div class="col-12">
                        <small class="settings-hint">Define a delay range in milliseconds (e.g. 100 to 2000). The server will pick a random duration within this range for visual animation simulation. Set both fields to the same value for a fixed delay.</small>
                    </div>
                </div>
            </div>

            <!-- OpenTracker Service -->
            <div class="settings-section">
                <h5>OpenTracker Service</h5>
                <small class="settings-hint d-block mb-3">Define the systemd unit of your tracker (e.g. <code>opentracker</code> or <code>opentracker.service</code>). When set, a <strong>Restart tracker</strong> button appears on the Dashboard that runs <code>systemctl restart &lt;name&gt;</code> on the server, together with smart recommendations that turn <span class="text-warning">orange</span> or <span class="text-danger">red</span> when a restart is advisable (after blacklist changes, or a long uptime). The web/PHP user must be allowed to run that command &mdash; see the README (sudoers). Leave the name empty to hide the button entirely.</small>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Service name <small class="settings-hint">(empty = disabled)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="opentracker_service_name" value="<?= sanitize($cfg['opentracker_service_name'] ?? '') ?>" placeholder="opentracker" pattern="[A-Za-z0-9._@-]+" maxlength="128">
                        <small class="settings-hint">Only letters, digits and <code>. _ @ -</code> are allowed.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Run via sudo</label>
                        <select class="form-select bg-dark text-light border-secondary" name="opentracker_restart_use_sudo">
                            <option value="1" <?= ($cfg['opentracker_restart_use_sudo'] ?? '1') === '1' ? 'selected' : '' ?>>Yes &mdash; <code>sudo -n systemctl</code></option>
                            <option value="0" <?= ($cfg['opentracker_restart_use_sudo'] ?? '1') === '0' ? 'selected' : '' ?>>No &mdash; direct <code>systemctl</code></option>
                        </select>
                        <small class="settings-hint">Most setups need sudo: php-fpm runs unprivileged and cannot restart services directly.</small>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info">Restart recommendation thresholds</small></div>
                    <div class="col-md-3">
                        <label class="form-label">Blacklist changes &rarr; orange</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_blacklist_warn_count" value="<?= sanitize($cfg['tracker_blacklist_warn_count'] ?? '1') ?>" min="1" max="1000">
                        <small class="settings-hint">Pending blacklist changes since last start before a restart is recommended.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Blacklist changes &rarr; red</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_blacklist_danger_count" value="<?= sanitize($cfg['tracker_blacklist_danger_count'] ?? '5') ?>" min="1" max="1000">
                        <small class="settings-hint">At or above this many, the alert turns red (restart required).</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Uptime days &rarr; orange</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_uptime_warn_days" value="<?= sanitize($cfg['tracker_uptime_warn_days'] ?? '14') ?>" min="1" max="3650">
                        <small class="settings-hint">Recommend a restart once uptime reaches this many days.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Uptime days &rarr; red</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_uptime_danger_days" value="<?= sanitize($cfg['tracker_uptime_danger_days'] ?? '30') ?>" min="1" max="3650">
                        <small class="settings-hint">Uptime warning turns red at this many days.</small>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="settings-section">
                <h5>Footer</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Copyright start year</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="footer_start_year" value="<?= sanitize($cfg['footer_start_year'] ?? date('Y')) ?>" min="2020" max="2099">
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12"><small class="text-info">Element 1 &mdash; Brand</small></div>
                    <div class="col-md-2">
                        <label class="form-label">Enabled</label>
                        <select class="form-select bg-dark text-light border-secondary" name="footer_brand_enabled">
                            <option value="1" <?= ($cfg['footer_brand_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['footer_brand_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_brand_name" value="<?= sanitize($cfg['footer_brand_name'] ?? 'TryHackX') ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_brand_url" value="<?= sanitize($cfg['footer_brand_url'] ?? '') ?>">
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12"><small class="text-info">Element 2 &mdash; Tracker Software</small></div>
                    <div class="col-md-2">
                        <label class="form-label">Enabled</label>
                        <select class="form-select bg-dark text-light border-secondary" name="footer_tracker_enabled">
                            <option value="1" <?= ($cfg['footer_tracker_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['footer_tracker_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_name" value="<?= sanitize($cfg['footer_tracker_name'] ?? 'OpenTracker') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_url" value="<?= sanitize($cfg['footer_tracker_url'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Author</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_author" value="<?= sanitize($cfg['footer_tracker_author'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Author URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_author_url" value="<?= sanitize($cfg['footer_tracker_author_url'] ?? '') ?>">
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12"><small class="text-info">Element 3 &mdash; Operating System</small></div>
                    <div class="col-md-2">
                        <label class="form-label">Enabled</label>
                        <select class="form-select bg-dark text-light border-secondary" name="footer_os_enabled">
                            <option value="1" <?= ($cfg['footer_os_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['footer_os_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_os_name" value="<?= sanitize($cfg['footer_os_name'] ?? 'Debian') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_os_url" value="<?= sanitize($cfg['footer_os_url'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Since year</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="footer_os_since_year" value="<?= sanitize($cfg['footer_os_since_year'] ?? date('Y')) ?>" min="2000" max="2099">
                    </div>
                </div>
            </div>

            <div id="settings-alert" class="mt-2 mb-2"></div>
            <div class="mt-3 mb-4">
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </div>
        </form>

        <hr class="border-secondary">

        <!-- Security -->
        <div class="settings-section">
            <h5>Security &amp; Credentials</h5>
            <form id="password-form">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Admin Username</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="admin_username" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>">
                        <small class="settings-hint">Current password is required to change username.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Current Password *</label>
                        <input type="password" class="form-control bg-dark text-light border-secondary" name="current_password" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">New Password <small style="color: #a0a0b0;">(min. 10 chars, a-Z, 0-9, special)</small></label>
                        <input type="password" class="form-control bg-dark text-light border-secondary" name="new_password" minlength="10" placeholder="Leave blank to keep current">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control bg-dark text-light border-secondary" name="confirm_password" minlength="10">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-outline-warning">Save Credentials</button>
                </div>
            </form>
            <div id="password-alert" class="mt-2"></div>
        </div>
    </div>

    <!-- Settings Save Confirmation Modal -->
    <div class="modal fade" id="settingsConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> Confirm Settings Changes</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-3" style="font-size:0.9rem;">You are modifying report deletion security limits. Enter your admin password to confirm and apply these changes.</p>
                    <form id="settings-confirm-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="settings-confirm-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning btn-sm text-dark"><i class="bi bi-check-lg"></i> Confirm Changes</button>
                        </div>
                    </form>
                    <div id="settings-confirm-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast container -->
    <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" id="toast-container" style="z-index: 1080;"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" integrity="sha384-G/EV+4j2dNv+tEPo3++6LCgdCROaejBqfUeNjuKAiuXbjrxilcCdDz6ZAVfHWe1Y" crossorigin="anonymous"></script>
    <script>
    const API_BASE = document.body.dataset.apiBase;
    const CSRF = document.body.dataset.csrf || '';
    let currentCaptchaAttempts = '<?= sanitize($cfg['delete_captcha_attempts'] ?? '2') ?>';
    let currentLockoutAttempts = '<?= sanitize($cfg['delete_lockout_attempts'] ?? '5') ?>';
    let currentLockoutMinutes = '<?= sanitize($cfg['delete_lockout_minutes'] ?? '60') ?>';

    document.getElementById('btn-test-blacklist').addEventListener('click', async () => {
        const el = document.getElementById('blacklist-result');
        const pathInput = document.querySelector('input[name="blacklist_path"]');
        const pathVal = pathInput ? pathInput.value.trim() : '';

        el.innerHTML = '<span class="text-info">Testing...</span>';
        try {
            const res = await fetch(API_BASE + 'admin/check_blacklist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ blacklist_path: pathVal })
            });
            const json = await res.json();
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; Path is accessible and writable.</span>' +
                    (json.suggestions.length ? '<br><small style="color: #a0a0b0;">' + json.suggestions.join('<br>') + '</small>' : '');
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + json.errors.join('<br>') + '</span>' +
                    (json.suggestions.length ? '<br><small class="text-warning">' + json.suggestions.join('<br>') + '</small>' : '') +
                    '<br><small style="color: #a0a0b0;">OS: ' + json.os + ' | PHP user: ' + json.php_user + '</small>';
            }
        } catch {
            el.innerHTML = '<span class="text-danger">Network error</span>';
        }
    });

    document.getElementById('btn-logout').addEventListener('click', async () => {
        await fetch(API_BASE + 'admin/logout', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
        window.location.reload();
    });

    // Donation fields management
    const dfList = document.getElementById('donation-fields-list');
    const dfAdd = document.getElementById('donation-field-add');
    const DF_MAX = 15;

    function dfRowHtml() {
        return `<div class="row g-2 mb-2 donation-field-row">
            <div class="col-md-3"><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="Label" data-df="label"></div>
            <div class="col"><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="Address, hash, or URL" data-df="value"></div>
            <div class="col-auto"><button type="button" class="btn btn-sm btn-outline-danger donation-field-remove" title="Remove"><i class="bi bi-x-lg"></i></button></div>
        </div>`;
    }

    dfAdd.addEventListener('click', () => {
        if (dfList.querySelectorAll('.donation-field-row').length >= DF_MAX) return;
        dfList.insertAdjacentHTML('beforeend', dfRowHtml());
    });

    dfList.addEventListener('click', (e) => {
        const btn = e.target.closest('.donation-field-remove');
        if (btn) btn.closest('.donation-field-row').remove();
    });

    function collectDonationFields() {
        const fields = [];
        dfList.querySelectorAll('.donation-field-row').forEach(row => {
            const label = row.querySelector('[data-df="label"]').value.trim();
            const value = row.querySelector('[data-df="value"]').value.trim();
            if (label && value) fields.push({ label, value });
        });
        return JSON.stringify(fields);
    }

    let settingsPayloadToSubmit = null;

    async function saveSettingsSubmit(data) {
        try {
            const res = await fetch(API_BASE + 'admin/save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(data),
            });
            const json = await res.json();
            if (json.success) {
                showToast('success', 'Settings saved successfully.');
                return true;
            } else {
                const errMsg = json.error || 'Error saving settings';
                const confirmAlert = document.getElementById('settings-confirm-alert');
                const confirmModalEl = document.getElementById('settingsConfirmModal');
                if (confirmAlert && confirmModalEl.classList.contains('show')) {
                    confirmAlert.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(errMsg)}</div>`;
                    setTimeout(() => {
                        const alertDiv = confirmAlert.querySelector('.modal-alert-sm');
                        if (alertDiv) alertDiv.classList.add('alert-fade');
                    }, 4500);
                    setTimeout(() => confirmAlert.innerHTML = '', 5000);
                } else {
                    showToast('error', errMsg);
                }
                return false;
            }
        } catch {
            const confirmAlert = document.getElementById('settings-confirm-alert');
            const confirmModalEl = document.getElementById('settingsConfirmModal');
            if (confirmAlert && confirmModalEl.classList.contains('show')) {
                confirmAlert.innerHTML = '<div class="alert alert-danger py-1 px-2 modal-alert-sm">Network error.</div>';
                setTimeout(() => {
                    const alertDiv = confirmAlert.querySelector('.modal-alert-sm');
                    if (alertDiv) alertDiv.classList.add('alert-fade');
                }, 4500);
                setTimeout(() => confirmAlert.innerHTML = '', 5000);
            } else {
                showToast('error', 'Network error.');
            }
            return false;
        }
    }

    function esc(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function showToast(type, msg) {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const icon = type === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-circle-fill text-danger';
        const id = 'toast-' + Date.now();
        container.insertAdjacentHTML('beforeend', `
            <div id="${id}" class="toast align-items-center border-0 show toast-dark" role="alert">
                <div class="d-flex">
                    <div class="toast-body text-light"><i class="bi ${icon}"></i> ${esc(msg)}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
                </div>
            </div>
        `);
        setTimeout(() => document.getElementById(id)?.remove(), 4000);
    }

    document.getElementById('settings-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const data = {};
        new FormData(form).forEach((v, k) => data[k] = v);
        data.donation_fields = collectDonationFields();

        const limitsChanged = data.delete_captcha_attempts !== currentCaptchaAttempts ||
                              data.delete_lockout_attempts !== currentLockoutAttempts ||
                              data.delete_lockout_minutes !== currentLockoutMinutes;

        if (limitsChanged) {
            settingsPayloadToSubmit = data;
            document.getElementById('settings-confirm-password').value = '';
            document.getElementById('settings-confirm-alert').innerHTML = '';
            const modal = new bootstrap.Modal(document.getElementById('settingsConfirmModal'));
            modal.show();
        } else {
            const success = await saveSettingsSubmit(data);
            if (success) {
                currentCaptchaAttempts = data.delete_captcha_attempts;
                currentLockoutAttempts = data.delete_lockout_attempts;
                currentLockoutMinutes = data.delete_lockout_minutes;
            }
        }
    });

    document.getElementById('settings-confirm-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!settingsPayloadToSubmit) return;
        const password = document.getElementById('settings-confirm-password').value;
        settingsPayloadToSubmit.confirm_password = password;

        const btn = e.target.querySelector('button[type="submit"]');
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';

        const success = await saveSettingsSubmit(settingsPayloadToSubmit);
        btn.disabled = false;
        btn.innerHTML = origHtml;

        if (success) {
            currentCaptchaAttempts = settingsPayloadToSubmit.delete_captcha_attempts;
            currentLockoutAttempts = settingsPayloadToSubmit.delete_lockout_attempts;
            currentLockoutMinutes = settingsPayloadToSubmit.delete_lockout_minutes;
            
            const modalEl = document.getElementById('settingsConfirmModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) {
                document.activeElement.blur();
                const scrollX = window.scrollX;
                const scrollY = window.scrollY;
                modal.hide();
                setTimeout(() => window.scrollTo(scrollX, scrollY), 50);
            }
        }
    });

    document.getElementById('password-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const username = form.admin_username.value.trim();
        const current = form.current_password.value;
        const newPass = form.new_password.value;
        const confirm = form.confirm_password.value;

        if (!current) {
            showToast('error', 'Current password is required.');
            return;
        }

        if (newPass && newPass !== confirm) {
            showToast('error', 'New passwords do not match.');
            return;
        }

        if (newPass) {
            if (newPass.length < 10) {
                showToast('error', 'New password must be at least 10 characters long.');
                return;
            }
            if (!/[a-z]/.test(newPass)) {
                showToast('error', 'New password must contain at least one lowercase letter.');
                return;
            }
            if (!/[A-Z]/.test(newPass)) {
                showToast('error', 'New password must contain at least one uppercase letter.');
                return;
            }
            if (!/[0-9]/.test(newPass)) {
                showToast('error', 'New password must contain at least one digit.');
                return;
            }
            if (!/[^a-zA-Z0-9]/.test(newPass)) {
                showToast('error', 'New password must contain at least one special character.');
                return;
            }
        }

        const payload = { current_password: current, admin_username: username };
        if (newPass) payload.new_password = newPass;

        try {
            const res = await fetch(API_BASE + 'admin/change_password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (json.success) {
                showToast('success', json.message || 'Saved successfully.');
                form.current_password.value = '';
                form.new_password.value = '';
                form.confirm_password.value = '';
            } else {
                showToast('error', json.error || 'Error');
            }
        } catch {
            showToast('error', 'Network error.');
        }
    });
    </script>
</body>
</html>
