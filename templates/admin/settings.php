<?php require_once __DIR__ . '/../../includes/settings_catalog.php'; ?>
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
<body class="admin-body admin-hc" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide">
        <div class="admin-header">
            <h2><i class="bi bi-gear"></i> Settings</h2>
            <?php $current = 'settings'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <!-- Sub-menu + search. The visible labels/hints below are indexed by the browser; the hidden
             synonyms come from api.php?endpoint=admin/settings_catalog and are never printed here. -->
        <div class="settings-toolbar" id="settings-toolbar">
            <div class="settings-search-row">
                <div class="settings-search-box">
                    <i class="bi bi-search settings-search-icon"></i>
                    <input type="search" id="settings-search" class="form-control bg-dark text-light border-secondary" placeholder="Search settings &mdash; try captcha, sender, timeout, whitelist&hellip;" autocomplete="off" spellcheck="false" aria-label="Search settings" aria-describedby="settings-search-count">
                    <button type="button" class="settings-search-clear d-hidden" id="settings-search-clear" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                </div>
                <span class="settings-search-count" id="settings-search-count" role="status" aria-live="polite"></span>
            </div>
            <div class="settings-groups" id="settings-groups" role="group" aria-label="Settings groups">
                <button type="button" class="settings-group-btn active" data-group="all" aria-pressed="true"><i class="bi bi-ui-checks-grid"></i> All settings</button>
                <?php foreach (settingsCatalogGroups() as $g): ?>
                <button type="button" class="settings-group-btn" data-group="<?= sanitize($g['id']) ?>" aria-pressed="false"><i class="bi <?= sanitize($g['icon']) ?>"></i> <?= sanitize($g['title']) ?><span class="settings-group-count" data-count-for="<?= sanitize($g['id']) ?>"></span></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="settings-empty d-hidden" id="settings-empty">
            <i class="bi bi-search"></i> No setting matches <strong id="settings-empty-q"></strong>.
            <span class="settings-hint">Try a shorter word &mdash; the search also knows synonyms (e.g. <em>bot</em>, <em>smtp</em>, <em>cron</em>, <em>hidden url</em>).</span>
        </div>

        <form id="settings-form">
            <!-- Site Configuration -->
            <div class="settings-section" id="section-site" data-group="general" data-title="Site Configuration">
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
                        <label class="form-label">GitHub URL <small class="settings-hint">(the project repository)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="github_url" value="<?= sanitize($cfg['github_url'] ?? '') ?>" placeholder="https://github.com/YourOrg/your-tracker">
                        <small class="settings-hint">Shown as the <strong>GitHub</strong> link in the footer. Point it at the <em>repository</em> of this tracker (so a visitor lands on the source, not on an account page); leave empty to hide the link.</small>
                    </div>
                </div>
            </div>

            <!-- Contact & Email -->
            <div class="settings-section" id="section-mail" data-group="mail" data-title="Contact &amp; Email">
                <h5>Contact &amp; Email</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Site Email <small class="settings-hint">(public contact; replies go here)</small></label>
                        <input type="email" class="form-control bg-dark text-light border-secondary" name="site_email" value="<?= sanitize($cfg['site_email'] ?? '') ?>">
                    </div>
                    <?php
                    // Only the site's own domain (and its parents) can be used as the sender, or SPF/
                    // DKIM/DMARC alignment breaks and the mail lands in spam — so the domain is a
                    // fixed list, not free text; the admin only types the local part.
                    $mfah = mailFromAllowedHosts($cfg);
                    $mfCur = trim((string)($cfg['mail_from_email'] ?? ''));
                    $mfLocal = ''; $mfDomain = $mfah[0] ?? '';
                    if ($mfCur !== '' && str_contains($mfCur, '@')) {
                        [$mfLocal, $mfDomain] = explode('@', $mfCur, 2);
                        $mfDomain = strtolower($mfDomain);
                    }
                    $mfDomains = $mfah;
                    // a stored domain that is no longer allowed (Site URL changed) stays selectable so
                    // saving something else does not silently rewrite it
                    if ($mfDomain !== '' && !in_array($mfDomain, $mfDomains, true)) array_unshift($mfDomains, $mfDomain);
                    ?>
                    <div class="col-md-6">
                        <label class="form-label">Sender address (From) <small class="settings-hint">(empty = Site Email)</small></label>
                        <?php if ($mfDomains): ?>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="mail-from-local" value="<?= sanitize($mfLocal) ?>" placeholder="noreply" maxlength="64" autocomplete="off" spellcheck="false">
                            <span class="input-group-text bg-dark text-secondary border-secondary">@</span>
                            <select class="form-select bg-dark text-light border-secondary" id="mail-from-domain" style="max-width:16rem;">
                                <?php foreach ($mfDomains as $d): ?>
                                <option value="<?= sanitize($d) ?>" <?= $d === $mfDomain ? 'selected' : '' ?>><?= sanitize($d) ?><?= in_array($d, $mfah, true) ? '' : ' (not allowed any more)' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="mail_from_email" id="mail-from-email" value="<?= sanitize($mfCur) ?>">
                        <small class="settings-hint">All outgoing mail (resets, verifications, notices) is sent FROM this address; replies still go to Site Email. Leave the left box empty to send from Site Email. The domain list is fixed to the Site URL host and its parent domains &mdash; anything else breaks SPF/DKIM/DMARC alignment. Best deliverability: the domain your mail server signs DKIM for and has an SPF record on (usually the root domain).</small>
                        <?php else: ?>
                        <input type="email" class="form-control bg-dark text-light border-secondary" name="mail_from_email" value="<?= sanitize($mfCur) ?>" placeholder="noreply@example.com">
                        <small class="settings-hint">Set a proper <strong>Site URL</strong> (a domain, not an IP) first &mdash; the sender domain is then picked from that domain and its parents. Until then this is a free-text field and the value is not domain-checked.</small>
                        <?php endif; ?>
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

            <!-- CAPTCHA (reCAPTCHA v2 / reCAPTCHA v3 / Cloudflare Turnstile) -->
            <?php $captchaProviderSel = captchaProvider($cfg); ?>
            <div class="settings-section" id="section-captcha" data-group="security" data-title="CAPTCHA">
                <h5>CAPTCHA</h5>
                <p class="settings-hint mb-2">Pick a provider and fill its keys. The switches below decide where a CAPTCHA is asked; the public whitelist registration page always requires one (it is disabled when CAPTCHA is not configured).</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Enable CAPTCHA</label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_enabled">
                            <option value="1" <?= ($cfg['recaptcha_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['recaptcha_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Provider</label>
                        <select class="form-select bg-dark text-light border-secondary" name="captcha_provider">
                            <option value="recaptcha" <?= $captchaProviderSel === 'recaptcha' ? 'selected' : '' ?>>Google reCAPTCHA v2 (checkbox)</option>
                            <option value="recaptcha_v3" <?= $captchaProviderSel === 'recaptcha_v3' ? 'selected' : '' ?>>Google reCAPTCHA v3 (invisible, score)</option>
                            <option value="turnstile" <?= $captchaProviderSel === 'turnstile' ? 'selected' : '' ?>>Cloudflare Turnstile</option>
                            <option value="hcaptcha" <?= $captchaProviderSel === 'hcaptcha' ? 'selected' : '' ?>>hCaptcha (checkbox)</option>
                        </select>
                    </div>
                    <div class="col-md-4"></div>
                    <div class="col-md-6" data-captcha-provider="recaptcha">
                        <label class="form-label">reCAPTCHA v2 Site Key</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="recaptcha_site_key" value="<?= sanitize($cfg['recaptcha_site_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-6" data-captcha-provider="recaptcha">
                        <label class="form-label">reCAPTCHA v2 Secret Key</label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="recaptcha_secret" value="<?= sanitize($cfg['recaptcha_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-4" data-captcha-provider="recaptcha_v3">
                        <label class="form-label">reCAPTCHA v3 Site Key</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="recaptcha_v3_site_key" value="<?= sanitize($cfg['recaptcha_v3_site_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-4" data-captcha-provider="recaptcha_v3">
                        <label class="form-label">reCAPTCHA v3 Secret Key</label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="recaptcha_v3_secret" value="<?= sanitize($cfg['recaptcha_v3_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-4" data-captcha-provider="recaptcha_v3">
                        <label class="form-label">reCAPTCHA v3 Min Score</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="recaptcha_v3_min_score" value="<?= sanitize($cfg['recaptcha_v3_min_score'] ?? '0.5') ?>" min="0" max="1" step="0.1">
                        <small class="settings-hint">Score below this &rarr; CAPTCHA failed; 0.5 is Google's suggested start. v3 has no widget &mdash; users are scored silently (a "protected by reCAPTCHA" notice is shown under the forms instead of the badge).</small>
                    </div>
                    <div class="col-md-6" data-captcha-provider="turnstile">
                        <label class="form-label">Turnstile Site Key</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="turnstile_site_key" value="<?= sanitize($cfg['turnstile_site_key'] ?? '') ?>" placeholder="0x4AAAAAAA...">
                    </div>
                    <div class="col-md-6" data-captcha-provider="turnstile">
                        <label class="form-label">Turnstile Secret Key</label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="turnstile_secret" value="<?= sanitize($cfg['turnstile_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-6" data-captcha-provider="hcaptcha">
                        <label class="form-label">hCaptcha Site Key</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="hcaptcha_site_key" value="<?= sanitize($cfg['hcaptcha_site_key'] ?? '') ?>" placeholder="00000000-0000-0000-0000-000000000000">
                    </div>
                    <div class="col-md-6" data-captcha-provider="hcaptcha">
                        <label class="form-label">hCaptcha Secret Key</label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="hcaptcha_secret" value="<?= sanitize($cfg['hcaptcha_secret'] ?? '') ?>" placeholder="ES_...">
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
            <div class="settings-section" id="section-captcha-smart" data-group="security" data-title="Smart CAPTCHA">
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
            <div class="settings-section" id="section-public-pages" data-group="general" data-title="Public Pages">
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

            <!-- Tracker mode & whitelist -->
            <div class="settings-section" id="section-reputation" data-group="tracker" data-title="Ratings">
                <h5><i class="bi bi-hand-thumbs-up"></i> Ratings</h5>
                <small class="settings-hint d-block mb-3">Up or down on a torrent, shown in the Info panel and optionally beside every search result. <strong>Off by default.</strong>
                <br><br><strong>What a public voting button really is:</strong> the easiest thing on a site to automate. One loop, a thousand negatives, and the score means nothing for ever. Four things stand in the way here, because no single one holds: one vote per identity enforced by a <strong>unique key in the database</strong> (not a check in PHP, which is a race two requests can walk straight through), the shared rate limiter, the CAPTCHA points scheme this site already uses, and a weight that makes an anonymous vote count for less than an account's.
                <br><br><strong>About attributing votes to an IP address:</strong> <code>REMOTE_ADDR</code> comes from the TCP connection and cannot be forged over the internet — forging it would mean completing a handshake from the forged address. Headers can be forged, and this panel only reads one when the request genuinely arrived from an address you listed under <em>Trusted proxy IPs</em>. What none of that fixes is one person with a VPN and a phone: IPv6 is bucketed to a /64 so a single allocation counts once, but a score built from anonymous votes is a weak signal and the panel will not pretend otherwise.</small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="rep_enabled">
                        <label class="form-label">Ratings</label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_enabled">
                            <option value="0" <?= ($cfg['rep_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Ratings are off</option>
                            <option value="1" <?= ($cfg['rep_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Ratings are on</option>
                        </select>
                        <small class="settings-hint">Ratings apply to <strong>any hash the catalogue knows</strong>, not only registered ones &mdash; a torrent does not have to be on the whitelist to be rated.</small>
                    </div>
                    <div class="col-md-3" data-setting="rep_mode">
                        <label class="form-label">How people rate</label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_mode">
                            <option value="thumbs" <?= repMode($cfg) === 'thumbs' ? 'selected' : '' ?>>Up or down</option>
                            <option value="stars" <?= repMode($cfg) === 'stars' ? 'selected' : '' ?>>Five stars, in half steps</option>
                        </select>
                        <small class="settings-hint">Stars are stored in ten steps and shown as five, so half a star is a real value rather than something inferred. Switching mode does not delete anything, but the two scales are not comparable &mdash; ratings cast in one mode will read oddly in the other.</small>
                    </div>
                    <div class="col-md-3" data-setting="rep_who_can_vote">
                        <label class="form-label">Who can rate</label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_who_can_vote">
                            <option value="off" <?= ($cfg['rep_who_can_vote'] ?? 'users') === 'off' ? 'selected' : '' ?>>Nobody &mdash; show scores only</option>
                            <option value="users" <?= ($cfg['rep_who_can_vote'] ?? 'users') === 'users' ? 'selected' : '' ?>>Signed-in accounts</option>
                            <option value="all" <?= ($cfg['rep_who_can_vote'] ?? 'users') === 'all' ? 'selected' : '' ?>>Anyone, including anonymous</option>
                        </select>
                        <small class="settings-hint">Anonymous voting is attributed to an address bucket, which one person can have several of. Open it knowing that.</small>
                    </div>
                    <div class="col-md-3" data-setting="rep_show_in_results">
                        <label class="form-label">Show in search results</label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_show_in_results">
                            <option value="0" <?= ($cfg['rep_show_in_results'] ?? '0') !== '1' ? 'selected' : '' ?>>Only in the Info panel</option>
                            <option value="1" <?= ($cfg['rep_show_in_results'] ?? '0') === '1' ? 'selected' : '' ?>>Also as a column in the list</option>
                        </select>
                    </div>
                    <div class="col-md-3" data-setting="rep_min_votes">
                        <label class="form-label">Votes before a score is shown</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rep_min_votes" value="<?= sanitize($cfg['rep_min_votes'] ?? '3') ?>" min="1" max="1000">
                        <small class="settings-hint">Below this the panel says &ldquo;too few ratings&rdquo; instead of a number. &ldquo;100% from one vote&rdquo; and &ldquo;100% from four hundred&rdquo; are not the same fact.</small>
                    </div>
                    <div class="col-md-3" data-setting="rep_anon_weight">
                        <label class="form-label">Weight of an anonymous vote (%)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rep_anon_weight" value="<?= sanitize($cfg['rep_anon_weight'] ?? '25') ?>" min="0" max="100">
                        <small class="settings-hint">Against 100% for a signed-in account. 0 = anonymous votes are counted but change nothing.</small>
                    </div>
                    <div class="col-md-3" data-setting="rep_rate_per_hour">
                        <label class="form-label">Votes per hour, per voter</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rep_rate_per_hour" value="<?= sanitize($cfg['rep_rate_per_hour'] ?? '30') ?>" min="1" max="1000">
                    </div>
                    <div class="col-md-3" data-setting="captcha_pts_vote">
                        <label class="form-label">CAPTCHA points per vote</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_vote" value="<?= sanitize($cfg['captcha_pts_vote'] ?? '2') ?>" min="0" max="100">
                        <small class="settings-hint">A vote adds this to the same score every other action feeds. Steady use is never interrupted; fifty votes in a minute meets a challenge, without anybody having to write a bot detector.</small>
                    </div>
                </div>
            </div>

            <div class="settings-section" id="section-livesync" data-group="tracker" data-title="Live peer sync (second machine)">
                <h5><i class="bi bi-diagram-3"></i> Live peer sync</h5>
                <small class="settings-hint d-block mb-3">Two opentrackers on <strong>two machines</strong> telling each other who is in which swarm, live. This is <em>not</em> federation: federation moves metadata between panels over HTTPS with a key, this moves the peers themselves between trackers. Neither replaces the other, and on a single machine this does nothing at all &mdash; there is nobody to sync with. <strong>Off by default.</strong>
                <br><br><strong>The protocol has no authentication and no encryption.</strong> Anything that can reach the port can inject peers into every swarm this tracker serves, so the helper <strong>refuses</strong> to arm unless the address belongs to a tunnel interface. There is no override for that, on purpose. The panel does not set up WireGuard for you either &mdash; generating a private key and writing it into <code>/etc</code> is a bigger claim on the machine than anything else here makes, and it would be doing it without being able to see the other end. Press <strong>Test</strong> and it prints the commands.</small>
                <div class="row g-3">
                    <div class="col-md-6" data-setting="livesync_cmd">
                        <label class="form-label">Helper command</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="livesync_cmd" value="<?= sanitize($cfg['livesync_cmd'] ?? '') ?>" maxlength="255" placeholder="e.g. sudo -n /usr/local/sbin/tracker-livesync.sh">
                        <small class="settings-hint">Empty = the feature does not exist: no card, no polling, no <code>sudo</code>.</small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_enabled">
                        <label class="form-label">Enabled</label>
                        <select class="form-select bg-dark text-light border-secondary" name="livesync_enabled">
                            <option value="0" <?= ($cfg['livesync_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= ($cfg['livesync_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                        </select>
                        <small class="settings-hint">Turning this on only shows the card; arming it is a separate, password-confirmed action on the <a href="<?= $baseUrl ?>?action=admin-traffic#livesync-card">Traffic page</a>.</small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_port">
                        <label class="form-label">Sync port (UDP)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="livesync_port" value="<?= sanitize($cfg['livesync_port'] ?? '9696') ?>" min="1024" max="65535">
                        <small class="settings-hint">Its own port, not the tracker's 6969.</small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_bind_ip">
                        <label class="form-label">This machine, inside the tunnel</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="livesync_bind_ip" value="<?= sanitize($cfg['livesync_bind_ip'] ?? '') ?>" maxlength="45" placeholder="e.g. 10.9.0.1">
                        <small class="settings-hint">A private address on a tunnel interface. A public one is refused.</small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_peer_ip">
                        <label class="form-label">The other tracker, inside the tunnel</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="livesync_peer_ip" value="<?= sanitize($cfg['livesync_peer_ip'] ?? '') ?>" maxlength="45" placeholder="e.g. 10.9.0.2">
                    </div>
                </div>
                <div class="settings-test mt-3">
                    <button type="button" class="btn btn-sm btn-outline-info settings-test-btn" data-test="admin/livesync_test"><i class="bi bi-diagram-3"></i> Test</button>
                    <div class="settings-test-out"></div>
                </div>
            </div>

            <div class="settings-section" id="section-whitelist" data-group="tracker" data-title="Tracker mode &amp; the accesslist file">
                <h5>Tracker mode &amp; the accesslist file</h5>
                <p class="settings-hint mb-2">
                    <strong>Blacklist</strong> = OpenTracker built with <code>-DWANT_ACCESSLIST_BLACK</code>: everything is served except blocked hashes.
                    <strong>Whitelist</strong> = built with <code>-DWANT_ACCESSLIST_WHITE</code>: <em>only</em> registered hashes are served (public registration page, forum sync, API).
                    The mode must match the compiled binary and its <code>access.whitelist</code> / <code>access.blacklist</code> config line.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Tracker mode</label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_mode">
                            <option value="blacklist" <?= ($cfg['tracker_mode'] ?? 'blacklist') !== 'whitelist' ? 'selected' : '' ?>>Blacklist (classic)</option>
                            <option value="whitelist" <?= ($cfg['tracker_mode'] ?? '') === 'whitelist' ? 'selected' : '' ?>>Whitelist (registration required)</option>
                        </select>
                        <?php if (function_exists('scheduleEnabled') && scheduleEnabled($cfg)): ?>
                        <small class="settings-hint text-warning d-block mt-1"><i class="bi bi-exclamation-triangle"></i> <strong>Scheduled mode is ON</strong> — the janitor re-applies the schedule's mode within a minute, so a manually saved mode is reverted. To switch manually, disable Scheduled Tracker Mode below first (and remember the setting alone does not swap the OpenTracker binary — the schedule / <code>tracker-mode.sh</code> does that).</small>
                        <?php else: ?>
                        <small class="settings-hint d-block mt-1">Saving this changes the web app's mode only. The OpenTracker binary/config are swapped by <code>tracker-mode.sh white|black</code> (run via the schedule, or manually over SSH).</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Whitelist file path <small class="settings-hint">(outside the web root; directory writable by PHP, e.g. <code>/home/tracker/accesslist/whitelist</code>)</small></label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="whitelist_path" value="<?= sanitize($cfg['whitelist_path'] ?? '') ?>" placeholder="/home/tracker/accesslist/whitelist">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-whitelist">Test</button>
                        </div>
                        <div id="whitelist-result" class="mt-1 blacklist-result"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Public registration page</label>
                        <select class="form-select bg-dark text-light border-secondary" name="whitelist_public_enabled">
                            <option value="1" <?= ($cfg['whitelist_public_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= ($cfg['whitelist_public_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Who can register hashes</label>
                        <select class="form-select bg-dark text-light border-secondary" name="whitelist_submit_mode">
                            <option value="public" <?= ($cfg['whitelist_submit_mode'] ?? 'public') !== 'users' ? 'selected' : '' ?>>Anyone (CAPTCHA required)</option>
                            <option value="users" <?= ($cfg['whitelist_submit_mode'] ?? 'public') === 'users' ? 'selected' : '' ?>>Registered users (whitelist.add permission, no CAPTCHA)</option>
                        </select>
                        <small class="settings-hint">"Registered users" needs the account system ON and the <code>whitelist.add</code> permission granted (Users &rarr; Groups); otherwise it falls back to public.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max hashes per submission</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_max_per_submission" value="<?= sanitize($cfg['whitelist_max_per_submission'] ?? '20') ?>" min="1" max="500">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Submissions / hour (per IP)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_whitelist" value="<?= sanitize($cfg['rate_limit_whitelist'] ?? '10') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">New hashes / day (per IP) <small class="settings-hint">(0 = off)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_ip_daily_max" value="<?= sanitize($cfg['whitelist_ip_daily_max'] ?? '50') ?>" min="0" max="100000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">New hashes / day (global cap) <small class="settings-hint">(0 = off)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_daily_cap" value="<?= sanitize($cfg['whitelist_daily_cap'] ?? '2000') ?>" min="0" max="10000000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Min. seconds between reloads <small class="settings-hint">(additions; removals reload sooner)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_reload_min_interval" value="<?= sanitize($cfg['whitelist_reload_min_interval'] ?? '45') ?>" min="10" max="3600">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">OpenTracker scrape URL <small class="settings-hint">(seeders/leechers in the whitelist panel)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="whitelist_scrape_url" value="<?= sanitize($cfg['whitelist_scrape_url'] ?? 'http://127.0.0.1:6969/scrape') ?>" placeholder="http://127.0.0.1:6969/scrape">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Public registration: require our tracker</label>
                        <select class="form-select bg-dark text-light border-secondary" name="whitelist_require_tracker">
                            <option value="0" <?= ($cfg['whitelist_require_tracker'] ?? '0') !== '1' ? 'selected' : '' ?>>Off — any hash / magnet</option>
                            <option value="1" <?= ($cfg['whitelist_require_tracker'] ?? '0') === '1' ? 'selected' : '' ?>>On — magnet must announce to us</option>
                        </select>
                        <div class="settings-hint mt-1">When on, bare hashes are refused and a magnet is accepted only if one of its <code>tr=</code> URLs points at a host below (announce URL hosts always count). Admin adds and the API are not affected.</div>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Our tracker hosts <small class="settings-hint">(comma-separated hostnames / IPs, e.g. <code>tryhackx.org, 203.0.113.10</code>; the hosts of the announce URLs above are always included)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="whitelist_tracker_hosts" value="<?= sanitize($cfg['whitelist_tracker_hosts'] ?? '') ?>" placeholder="tryhackx.org, 203.0.113.10">
                    </div>
                </div>
                <div class="settings-hint mt-2">Manage the list itself (browse, add, ban, regenerate the file) on the <a href="<?= $baseUrl ?>?action=admin-whitelist">Whitelist page</a>.</div>

                <!-- A submission has to prove itself (includes/wlprobe.php) -->
            </div>

            <div class="settings-section" id="section-probe" data-group="tracker" data-title="Submissions must prove themselves">
                <h5>Submissions must prove themselves</h5>
                <p class="settings-hint mb-2">Magnet check, peer verification and the proving queue</p>
                <small class="settings-hint d-block mb-3">Anybody can paste forty hex characters. Most of what a public whitelist accumulates is not abuse &mdash; it is hashes nobody is seeding, and hashes whose torrent never named this tracker. Each one becomes a row the accesslist carries for ever.
                <br><br>With this on, a new submission has to show two things before the tracker serves it: <strong>the metadata resolves</strong> (somebody really is sharing it) and <strong>a scrape finds at least one peer</strong> (and they are announcing here). It reuses the metadata worker rather than adding a second queue, but jumps the queue &mdash; a person is watching this one, and nothing else in the queue is. <strong>Off by default:</strong> it changes what registering <em>means</em>, and that is not something to inherit from an upgrade.</small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="wl_probe_required">
                        <label class="form-label">Verify the magnet before serving it</label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_probe_required">
                            <option value="0" <?= ($cfg['wl_probe_required'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['wl_probe_required'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                        </select>
                        <small class="settings-hint">Existing rows are unaffected: everything registered before this counts as already accepted, so turning it on never unpublishes anything.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_probe_timeout_minutes">
                        <label class="form-label">Verification timeout (minutes)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_probe_timeout_minutes" value="<?= sanitize($cfg['wl_probe_timeout_minutes'] ?? '10') ?>" min="1" max="1440">
                        <small class="settings-hint">Fetching metadata from the DHT is not instant. Too short and honest submissions fail.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_probe_on_fail">
                        <label class="form-label">If the magnet never verifies</label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_probe_on_fail">
                            <option value="delete" <?= ($cfg['wl_probe_on_fail'] ?? 'delete') === 'delete' ? 'selected' : '' ?>>Remove the row</option>
                            <option value="keep" <?= ($cfg['wl_probe_on_fail'] ?? 'delete') === 'keep' ? 'selected' : '' ?>>Keep it, unserved, with the reason</option>
                        </select>
                        <small class="settings-hint">Deleting is the default here, unlike the dead-row rule: this row was never served, and nobody has come to rely on it.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_probe_max_batch">
                        <label class="form-label">Magnets verified at once, per submission</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_probe_max_batch" value="<?= sanitize($cfg['wl_probe_max_batch'] ?? '') ?>" min="1" max="64" placeholder="e.g. 8 (defaults to the worker's concurrency)">
                        <small class="settings-hint">Five hundred rows in the priority lane are not resolved faster &mdash; they just make everyone wait together. Keep this at or below <em>Worker parallel fetches</em>.</small>
                    </div>
                </div>

                <!-- Keeping the list honest over time (includes/wlmaint.php) -->
            </div>

            <div class="settings-section" id="section-wlupkeep" data-group="tracker" data-title="Whitelist upkeep">
                <h5>Whitelist upkeep</h5>
                <p class="settings-hint mb-2">Refreshing seeders and retiring dead torrents</p>
                <small class="settings-hint d-block mb-3">A whitelist accumulates. Somebody registers a torrent, seeds it for a week and moves on, and years later the tracker is still serving a swarm with nobody in it. Both jobs below run from the <strong>janitor</strong>, never from a page view, and both are <strong>off by default</strong>.</small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="wl_scrape_every_hours">
                        <label class="form-label">Refresh S/L every (hours)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_scrape_every_hours" value="<?= sanitize($cfg['wl_scrape_every_hours'] ?? '0') ?>" min="0" max="8760">
                        <small class="settings-hint">0 = never. Rows are taken stalest-first, so a list larger than one batch is covered evenly instead of the same first two hundred rows being refreshed for ever.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_scrape_batch">
                        <label class="form-label">Rows per run</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_scrape_batch" value="<?= sanitize($cfg['wl_scrape_batch'] ?? '200') ?>" min="1" max="2000">
                        <small class="settings-hint">Each row is a scrape request to your own tracker. The janitor runs every minute.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_dead_after_days">
                        <label class="form-label">Call a torrent dead after (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_dead_after_days" value="<?= sanitize($cfg['wl_dead_after_days'] ?? '0') ?>" min="0" max="3650">
                        <small class="settings-hint">0 = off. Counted from the last scrape that showed <strong>no seeders and no leechers</strong>. A row that has never been scraped is <em>never</em> counted &mdash; no data is not the same as no peers, and the difference matters most when the scrape path is broken, which is exactly when a delete-on-zero rule would empty the list.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_dead_action">
                        <label class="form-label">What to do with them</label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_dead_action">
                            <option value="mark" <?= ($cfg['wl_dead_action'] ?? 'mark') === 'mark' ? 'selected' : '' ?>>Mark them &mdash; nothing is removed</option>
                            <option value="delete" <?= ($cfg['wl_dead_action'] ?? 'mark') === 'delete' ? 'selected' : '' ?>>Delete them</option>
                            <option value="none" <?= ($cfg['wl_dead_action'] ?? 'mark') === 'none' ? 'selected' : '' ?>>Nothing</option>
                        </select>
                        <small class="settings-hint"><strong>Mark is the default deliberately.</strong> An automation that removes other people&rsquo;s registrations is something an operator should choose in as many words, not inherit.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_dead_every_days">
                        <label class="form-label">Run the dead pass every (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_dead_every_days" value="<?= sanitize($cfg['wl_dead_every_days'] ?? '30') ?>" min="1" max="365">
                    </div>
                    <div class="col-md-9">
                        <div class="settings-hint mt-4"><?php
                            $wmCount = function_exists('wlMaintDeadCount') ? wlMaintDeadCount($db, $cfg) : 0;
                            if (wlMaintDeadDays($cfg) > 0) {
                                echo 'With the current settings this rule matches <strong>' . (int)$wmCount . '</strong> row'
                                   . ($wmCount === 1 ? '' : 's') . ' right now.';
                            } else {
                                echo 'Set a number of days above to see how many rows the rule would match.';
                            }
                        ?></div>
                    </div>
                </div>

                <!-- Source link + description on a whitelist row (includes/richtext.php) -->
            </div>

            <div class="settings-section" id="section-content" data-group="content" data-title="Descriptions &amp; source links">
                <h5>Descriptions &amp; source links</h5>
                <p class="settings-hint mb-2">What submitters may write, and who reviews it</p>
                <small class="settings-hint d-block mb-3">Two optional fields on the registration form: <strong>where the torrent came from</strong>, and <strong>what it is</strong>. They appear on the public whitelist, the Index and the public search. Both are <strong>off by default</strong>, because this is text an anonymous stranger types and you then publish under your own domain &mdash; a description can carry images and a link goes wherever its author decided. With review on (the default when you switch these on), the torrent still registers <em>immediately</em>; only the link and the description wait for you.</small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="wl_allow_source_url">
                        <label class="form-label">Source link field</label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_allow_source_url">
                            <option value="0" <?= ($cfg['wl_allow_source_url'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['wl_allow_source_url'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                        </select>
                        <small class="settings-hint"><strong>https only.</strong> Plain HTTP is refused rather than upgraded: this page is served over TLS and must not hand anyone a downgrade. Private addresses and credentials in the URL are refused too.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_allow_description">
                        <label class="form-label">Description field</label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_allow_description">
                            <option value="0" <?= ($cfg['wl_allow_description'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['wl_allow_description'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                        </select>
                    </div>
                    <div class="col-md-3" data-setting="wl_content_review">
                        <label class="form-label">Review before publishing</label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_content_review">
                            <option value="1" <?= ($cfg['wl_content_review'] ?? '1') === '1' ? 'selected' : '' ?>>Yes &mdash; hold for review</option>
                            <option value="0" <?= ($cfg['wl_content_review'] ?? '1') !== '1' ? 'selected' : '' ?>>No &mdash; publish at once</option>
                        </select>
                        <small class="settings-hint">Held items appear under <strong>To review</strong> on the <a href="<?= $baseUrl ?>?action=admin-whitelist">Whitelist page</a>. Turning this off means the first bad description is public before anyone has seen it.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_content_autopublish">
                        <label class="form-label">Always publish</label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_content_autopublish">
                            <option value="0" <?= ($cfg['wl_content_autopublish'] ?? '0') !== '1' ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= ($cfg['wl_content_autopublish'] ?? '0') === '1' ? 'selected' : '' ?>>Yes &mdash; skip the queue entirely</option>
                        </select>
                        <small class="settings-hint">Separate from <em>Review before publishing</em> on purpose: &ldquo;I do not moderate&rdquo; and &ldquo;I moderate, but let this one through&rdquo; are different decisions, and collapsing them into one switch means you cannot make the second.</small>
                    </div>
                    <div class="col-md-3" data-setting="wl_edit_max_pending">
                        <label class="form-label">Rewrite proposals per torrent</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_edit_max_pending" value="<?= sanitize($cfg['wl_edit_max_pending'] ?? '3') ?>" min="0" max="50">
                        <small class="settings-hint">Registering a hash that already exists, with a description, <strong>proposes a rewrite</strong> rather than changing anything &mdash; anyone can register somebody else&rsquo;s torrent, so the first description is not automatically the right one. Proposals appear under <strong>Whitelist &rarr; To review &rarr; Rewrites</strong>, old and new side by side. 0 = do not accept proposals.</small>
                    </div>
                    <div class="col-md-3" data-setting="link_trusted_domains">
                        <label class="form-label">Domains that need no warning</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="link_trusted_domains" value="<?= sanitize($cfg['link_trusted_domains'] ?? '') ?>" placeholder="e.g. tryhackx.org, files.example.org">
                        <small class="settings-hint">Every off-site link asks the visitor to confirm first. These domains (and their subdomains) skip that. Empty = warn on everything, including your own.</small>
                    </div>
                    <div class="col-md-3" data-setting="desc_allow_bbcode">
                        <label class="form-label">Allow BBCode</label>
                        <select class="form-select bg-dark text-light border-secondary" name="desc_allow_bbcode">
                            <option value="1" <?= ($cfg['desc_allow_bbcode'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['desc_allow_bbcode'] ?? '1') !== '1' ? 'selected' : '' ?>>No</option>
                        </select>
                        <small class="settings-hint"><code>[b] [i] [u] [s] [code] [quote] [list] [url] [img]</code></small>
                    </div>
                    <div class="col-md-3" data-setting="desc_allow_markdown">
                        <label class="form-label">Allow Markdown</label>
                        <select class="form-select bg-dark text-light border-secondary" name="desc_allow_markdown">
                            <option value="1" <?= ($cfg['desc_allow_markdown'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['desc_allow_markdown'] ?? '1') !== '1' ? 'selected' : '' ?>>No</option>
                        </select>
                        <small class="settings-hint">With both on, whoever writes picks. With both off, BBCode is still offered &mdash; a description field with no syntax at all would be a trap.</small>
                    </div>
                    <div class="col-md-2" data-setting="desc_max_chars">
                        <label class="form-label">Max characters</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="desc_max_chars" value="<?= sanitize($cfg['desc_max_chars'] ?? '4000') ?>" min="200" max="20000">
                    </div>
                    <div class="col-md-2" data-setting="desc_max_images">
                        <label class="form-label">Max images</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="desc_max_images" value="<?= sanitize($cfg['desc_max_images'] ?? '3') ?>" min="0" max="50">
                        <small class="settings-hint">0 = none. Every image is a request from your visitor to somebody else's server.</small>
                    </div>
                    <div class="col-md-2" data-setting="desc_max_links">
                        <label class="form-label">Max links</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="desc_max_links" value="<?= sanitize($cfg['desc_max_links'] ?? '10') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-3" data-setting="search_allow_sl_refresh">
                        <label class="form-label">Let visitors refresh S/L</label>
                        <select class="form-select bg-dark text-light border-secondary" name="search_allow_sl_refresh">
                            <option value="0" <?= ($cfg['search_allow_sl_refresh'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['search_allow_sl_refresh'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                        </select>
                        <small class="settings-hint">Adds a refresh button to the Info panel in the public search, scraping that one hash live. Off by default: it is a button that makes strangers' clicks into tracker requests.</small>
                    </div>
                    <div class="col-md-3" data-setting="search_sl_refresh_seconds">
                        <label class="form-label">Refresh cooldown (s)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="search_sl_refresh_seconds" value="<?= sanitize($cfg['search_sl_refresh_seconds'] ?? '120') ?>" min="10" max="3600">
                        <small class="settings-hint">Per hash, across every visitor. Without this the button is a free load generator pointed at your own tracker.</small>
                    </div>
                </div>

                <!-- Scheduled mode (whitelist hours) — includes/schedule.php -->
                <?php
                $schedDays   = function_exists('scheduleParseJson') ? (scheduleParseJson((string)($cfg['tracker_schedule'] ?? '')) ?? array_fill_keys(SCHEDULE_DAYS, 'none')) : [];
                $schedTz     = function_exists('scheduleTimezone') ? scheduleTimezone($cfg) : 'Europe/Warsaw';
                $schedOn     = function_exists('scheduleEnabled') && scheduleEnabled($cfg);
                $schedSt     = function_exists('scheduleStatus') ? scheduleStatus($cfg) : null;
                $schedTzList = function_exists('timezone_identifiers_list') ? timezone_identifiers_list() : [$schedTz];
                $schedTzGroups = [];
                foreach ($schedTzList as $tzId) { $schedTzGroups[strpos($tzId, '/') !== false ? substr($tzId, 0, strpos($tzId, '/')) : 'Other'][] = $tzId; }
                ?>
            </div>

            <div class="settings-section" id="section-schedule" data-group="tracker" data-title="Scheduled tracker mode">
                <h5>Scheduled tracker mode</h5>
                <p class="settings-hint mb-2">Whitelist during set hours, open otherwise</p>
                <p class="settings-hint mb-2">
                    Run the tracker in <strong>whitelist</strong> mode during the hours below and in <strong>open (blacklist)</strong> mode at every other moment.
                    The switch itself is done by the root helper below (it swaps the OpenTracker binary + config and restarts the service; argument <code>white</code> / <code>black</code>),
                    called by the janitor timer (<code>tools/janitor.php</code>, every minute) — never by a web request. While the schedule is ON the "Tracker mode" select above is
                    overridden by the schedule within a minute; bans are kept consistent between the whitelist (banned hashes) and the blacklist file at every switch.
                    Public registration stays open in both modes; hashes registered during open hours become active at the next whitelist window.
                </p>
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Scheduled mode</label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_schedule_enabled" id="sched-enabled">
                            <option value="0" <?= !$schedOn ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= $schedOn ? 'selected' : '' ?>>Enabled</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Timezone</label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_schedule_tz">
                            <?php foreach ($schedTzGroups as $grp => $ids): ?>
                            <optgroup label="<?= sanitize($grp) ?>">
                                <?php foreach ($ids as $tzId): ?>
                                <option value="<?= sanitize($tzId) ?>" <?= $tzId === $schedTz ? 'selected' : '' ?>><?= sanitize($tzId) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mode switch command <small class="settings-hint">(root helper; <code>white</code>/<code>black</code> is appended; empty = only flip the web setting)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="tracker_mode_switch_cmd" value="<?= sanitize($cfg['tracker_mode_switch_cmd'] ?? 'sudo -n /usr/local/sbin/tracker-mode.sh') ?>" placeholder="e.g. sudo -n /usr/local/sbin/tracker-mode.sh" maxlength="255">
                    </div>
                    <div class="col-12">
                        <input type="hidden" name="tracker_schedule" id="sched-json" value="<?= sanitize(json_encode($schedDays)) ?>">
                        <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-1 sched-table" id="sched-table">
                                <thead><tr><th style="width:5rem">Day</th><th style="width:16rem">Rule</th><th style="width:9rem">From</th><th style="width:9rem">To</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach (SCHEDULE_DAYS as $d):
                                    $v = $schedDays[$d] ?? 'none';
                                    $kind = is_array($v) ? 'window' : $v;
                                    $from = is_array($v) ? $v['from'] : '10:00';
                                    $to   = is_array($v) ? $v['to'] : '02:30';
                                ?>
                                <tr data-sched-day="<?= $d ?>">
                                    <td><strong><?= SCHEDULE_DAY_LABELS[$d] ?></strong></td>
                                    <td>
                                        <select class="form-select form-select-sm bg-dark text-light border-secondary" data-sched-kind>
                                            <option value="all" <?= $kind === 'all' ? 'selected' : '' ?>>Whitelist all day</option>
                                            <option value="window" <?= $kind === 'window' ? 'selected' : '' ?>>Whitelist window</option>
                                            <option value="none" <?= $kind === 'none' ? 'selected' : '' ?>>Blacklist (open) all day</option>
                                        </select>
                                    </td>
                                    <td><input type="time" class="form-control form-control-sm bg-dark text-light border-secondary" data-sched-from value="<?= sanitize($from) ?>" step="60"></td>
                                    <td><input type="time" class="form-control form-control-sm bg-dark text-light border-secondary" data-sched-to value="<?= sanitize($to) ?>" step="60"></td>
                                    <td class="settings-hint" data-sched-note></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="settings-hint">
                            A window starts on that weekday at <em>From</em>; when <em>To</em> &le; <em>From</em> it ends on the <strong>next day</strong> at <em>To</em>
                            (e.g. Mon 10:00 → 02:30 = Monday 10:00 until Tuesday 02:30). Outside every window the tracker runs <strong>OPEN (blacklist)</strong> mode.
                        </div>
                        <?php if ($schedSt): ?>
                        <div class="settings-hint mt-2" id="sched-summary">
                            <strong>Saved schedule:</strong> <?= sanitize($schedSt['describe']) ?>.
                            <?php if ($schedSt['enabled']): ?>
                                Desired mode now: <strong><?= sanitize($schedSt['desired'] ?? 'invalid') ?></strong> (tracker is in <strong><?= sanitize($schedSt['current']) ?></strong>);
                                next change: <strong><?= sanitize($schedSt['next_change_local'] ?? 'none') ?></strong> <?= $schedSt['next_change_local'] ? '(' . sanitize($schedSt['tz']) . ')' : '' ?>.
                                <?php if ($schedSt['last_result']): ?>
                                    Last switch: <strong><?= sanitize($schedSt['last_result']) ?></strong><?= $schedSt['last_switch_at'] ? ' at ' . date('Y-m-d H:i', (int)$schedSt['last_switch_at']) . ' (' . sanitize((string)$schedSt['last_from']) . ' → ' . sanitize((string)$schedSt['last_to']) . ')' : '' ?><?= $schedSt['last_error'] ? ' — <span class="text-danger">' . sanitize($schedSt['last_error']) . '</span>' : '' ?>.
                                <?php endif; ?>
                            <?php else: ?>
                                Schedule is off — the tracker stays in the mode selected above.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Server-to-server API -->
            <div class="settings-section" id="section-api" data-group="integrations" data-title="Server-to-server API">
                <h5>Server-to-server API</h5>
                <p class="settings-hint mb-2">
                    <code>POST v1/whitelist/submit</code> / <code>GET v1/whitelist/ping</code> with <code>Authorization: Bearer key_id.secret</code>
                    (used by the forum extension). <strong>Every failed authentication that carries an Authorization header bans the source IP</strong>
                    (IPv4 exactly, IPv6 per /64) for the number of days below and stores the offending request. Requests without a header get 401 without a ban.
                    Clients (keys) and bans are managed on the <a href="<?= $baseUrl ?>?action=admin-whitelist">Whitelist page</a>.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">API</label>
                        <select class="form-select bg-dark text-light border-secondary" name="api_enabled">
                            <option value="1" <?= ($cfg['api_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= ($cfg['api_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ban length (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="api_ban_days" value="<?= sanitize($cfg['api_ban_days'] ?? '30') ?>" min="1" max="3650">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Never-ban IPs <small class="settings-hint">(comma-separated IPs or CIDRs; keep this server's own addresses here — the forum on the same host must never lock itself out)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="api_ban_exempt_ips" value="<?= sanitize($cfg['api_ban_exempt_ips'] ?? '127.0.0.1, ::1') ?>" placeholder="127.0.0.1, ::1, 203.0.113.10">
                    </div>
                </div>
                <p class="settings-hint mt-3 mb-2">
                    The ban machinery above only reacts to <em>bad</em> authentication. These two budgets are what
                    limits a key that is perfectly valid &mdash; a federation peer pulling pages, or a key that has
                    leaked. They are counted <strong>per key</strong> (not per IP, so one partner cannot be starved by
                    a stranger behind the same NAT), and going over answers <code>429</code> with <code>Retry-After</code>
                    rather than a ban: pulling too fast is a misconfiguration between partners, not an attack.
                    Addresses in <em>Never-ban IPs</em> are exempt from these too. <strong>0 switches a budget off.</strong>
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Requests per minute <small class="settings-hint">(per key)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="api_rate_limit_per_min" value="<?= sanitize($cfg['api_rate_limit_per_min'] ?? '60') ?>" min="0" max="100000">
                        <small class="settings-hint">0 = unlimited. A federation pull is one request per page, so 60 is generous.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Bytes per day <small class="settings-hint">(per key)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="api_rate_limit_bytes_day" value="<?= sanitize($cfg['api_rate_limit_bytes_day'] ?? '5368709120') ?>" min="0" max="1099511627776" step="1073741824">
                        <small class="settings-hint">0 = unlimited. 5368709120 = 5 GB. Counts what we <em>send</em> as well as what we receive, so a runaway export is caught.</small>
                    </div>
                </div>
            </div>

            <!-- User accounts -->
            <div class="settings-section" id="section-tuner" data-group="tracker" data-title="Stability probe">
                <h5>Stability probe</h5>
                <p class="settings-hint mb-2">
                    Finds where this machine's limits actually are, by moving the inbound limit through a few steps and
                    watching what happens &mdash; including to <strong>everything else on the box</strong>. A formula can
                    suggest a number from past traffic; only a run can answer &ldquo;does raising this make the other
                    services drop packets&rdquo;, which is usually the real question.
                    <br><strong>Off by default.</strong> The way back is written down before the first change, so the
                    settings return even if the run is killed or the machine reboots &mdash; the janitor restores them.
                    Nothing is applied automatically: a run ends exactly where it started and leaves a report.
                </p>
                <div class="row g-3">
                    <div class="col-md-4" data-setting="tuner_enabled">
                        <label class="form-label">Stability probe</label>
                        <select class="form-select bg-dark text-light border-secondary" name="tuner_enabled">
                            <option value="0" <?= ($cfg['tuner_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['tuner_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled &mdash; the button appears on Traffic</option>
                        </select>
                        <small class="settings-hint">Enabling it only shows the control; a run still needs the admin password.</small>
                    </div>
                    <div class="col-md-4" data-setting="tuner_python">
                        <label class="form-label">Python command</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="tuner_python" value="<?= sanitize($cfg['tuner_python'] ?? 'python3') ?>" placeholder="python3">
                        <small class="settings-hint">What the janitor runs <code>worker/tuner.py</code> with. Letters, digits and <code>_ . / -</code> only.</small>
                    </div>
                </div>
            </div>

            <div class="settings-section" id="section-audit" data-group="users" data-title="Audit log">
                <h5>Audit log</h5>
                <p class="settings-hint mb-2">Who did what in the panel. The log is read-only from here on purpose &mdash; a record the panel it describes can edit is not a record. Credentials are never written to it: a setting that holds one appears as &ldquo;changed&rdquo;, never as its value.</p>
                <div class="row g-3">
                    <div class="col-md-4" data-setting="audit_enabled">
                        <label class="form-label">Recording</label>
                        <select class="form-select bg-dark text-light border-secondary" name="audit_enabled">
                            <option value="1" <?= ($cfg['audit_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Record panel actions</option>
                            <option value="0" <?= ($cfg['audit_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Do not record</option>
                        </select>
                        <small class="settings-hint">Turning this off stops new entries; it does not remove the ones already written.</small>
                    </div>
                    <div class="col-md-4" data-setting="audit_keep_days">
                        <label class="form-label">Keep for (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="audit_keep_days" value="<?= sanitize($cfg['audit_keep_days'] ?? '180') ?>" min="7" max="3650">
                        <small class="settings-hint">The janitor removes anything older, a few thousand rows per run.</small>
                    </div>
                </div>
            </div>

            <div class="settings-section" id="section-users" data-group="users" data-title="User Accounts">
                <h5>User Accounts</h5>
                <small class="settings-hint d-block mb-3">Optional member system: registration + login (CAPTCHA-protected), groups with per-feature permissions, timed access (sellable via the <code>v1/users/*</code> API with a <em>users</em>-scope key), in-app notifications and a member search over the Index. Users and groups are managed on the <a href="<?= $baseUrl ?>?action=admin-users">Users page</a>. The <strong>guest</strong> group defines what anonymous visitors may see — with accounts <em>disabled</em> everything behaves exactly as before (public pages public, Index admin-only).</small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">User accounts</label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_enabled">
                            <option value="1" <?= ($cfg['users_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= ($cfg['users_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Public registration</label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_registration_enabled">
                            <option value="1" <?= ($cfg['users_registration_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Open</option>
                            <option value="0" <?= ($cfg['users_registration_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Closed (admin / API only)</option>
                        </select>
                        <small class="settings-hint">Registration always requires a configured CAPTCHA.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Login / register links</label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_links_visible">
                            <option value="1" <?= ($cfg['users_links_visible'] ?? '1') === '1' ? 'selected' : '' ?>>Visible in the menu</option>
                            <option value="0" <?= ($cfg['users_links_visible'] ?? '1') !== '1' ? 'selected' : '' ?>>Hidden (direct URL only)</option>
                        </select>
                        <small class="settings-hint">Hidden: <code>?action=login</code> / <code>?action=register</code> still work.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Default group (slug)</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="users_default_group" value="<?= sanitize($cfg['users_default_group'] ?? 'member') ?>" pattern="[a-z0-9_\-]{2,64}">
                        <small class="settings-hint">Granted to every new account (plus any group flagged &ldquo;default&rdquo;).</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Expiry warning (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="users_notify_expiry_days" value="<?= sanitize($cfg['users_notify_expiry_days'] ?? '3') ?>" min="0" max="30">
                        <small class="settings-hint">Notify (+email when possible) this many days before a timed group ends. 0 = off.</small>
                    </div>
                    <div class="col-md-3" data-setting="bulk_mail_enabled">
                        <label class="form-label">Write to everyone (bulk mail)</label>
                        <select class="form-select bg-dark text-light border-secondary" name="bulk_mail_enabled">
                            <option value="0" <?= ($cfg['bulk_mail_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['bulk_mail_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                        </select>
                        <small class="settings-hint">Adds <strong>Write to members</strong> on the <a href="<?= $baseUrl ?>?action=admin-users">Users page</a>: a message to a selection, a group, or everyone. In-app notifications work with this off &mdash; this switch is about <em>email</em>. Off by default.</small>
                    </div>
                    <div class="col-md-3" data-setting="bulk_mail_per_minute">
                        <label class="form-label">Bulk mail per minute</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="bulk_mail_per_minute" value="<?= sanitize($cfg['bulk_mail_per_minute'] ?? '20') ?>" min="1" max="500">
                        <small class="settings-hint">This server sends through <code>mail()</code> with no relay in front of it, so a hundred messages leaving at once from a domain that normally sends a handful is what gets it filed under bulk &mdash; and the first casualty is the password-reset mail. The panel only queues; the janitor sends this many a minute.</small>
                    </div>
                    <div class="col-md-3" data-setting="bulk_mail_max_attempts">
                        <label class="form-label">Bulk mail retries</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="bulk_mail_max_attempts" value="<?= sanitize($cfg['bulk_mail_max_attempts'] ?? '3') ?>" min="1" max="10">
                        <small class="settings-hint">Tries per message before it is marked failed, backing off longer each time. A mailer that is refusing does not want to be asked again immediately.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Require email verification</label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_require_email_verify">
                            <option value="1" <?= ($cfg['users_require_email_verify'] ?? '1') === '1' ? 'selected' : '' ?>>Yes — unverified accounts act as guests</option>
                            <option value="0" <?= ($cfg['users_require_email_verify'] ?? '1') !== '1' ? 'selected' : '' ?>>No — groups apply immediately</option>
                        </select>
                        <small class="settings-hint">With Yes, registration requires an email and group permissions only apply after the confirmation link is clicked (the account page itself stays reachable; admins are exempt).</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email change cooldown (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="users_email_change_cooldown_days" value="<?= sanitize($cfg['users_email_change_cooldown_days'] ?? '30') ?>" min="0" max="365">
                        <small class="settings-hint">After a completed change the next one is blocked for this many days (anti-hijack). 0 = off. Changes are always confirmed from the OLD address first, then the NEW one.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Registration terms <small class="settings-hint">(empty = the checkbox links to <code>?action=tos</code>)</small></label>
                        <textarea class="form-control bg-dark text-light border-secondary" name="users_terms_text" rows="4" placeholder="Plain text shown in a modal when the user clicks the terms link on the registration form."><?= sanitize($cfg['users_terms_text'] ?? '') ?></textarea>
                        <small class="settings-hint">Registration always requires ticking the &ldquo;I accept the terms&rdquo; box; this only controls what the link shows.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Logins / 15 min (per IP)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_user_login" value="<?= sanitize($cfg['rate_limit_user_login'] ?? '10') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Registrations / hour (per IP)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_user_register" value="<?= sanitize($cfg['rate_limit_user_register'] ?? '5') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Searches / hour (per IP)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_index_search" value="<?= sanitize($cfg['rate_limit_index_search'] ?? '120') ?>" min="0" max="100000">
                    </div>
                    <div class="col-md-3" data-setting="rate_limit_preview">
                        <label class="form-label">Description previews / min (per IP)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_preview" value="<?= sanitize($cfg['rate_limit_preview'] ?? '30') ?>" min="5" max="300">
                        <small class="settings-hint">The Preview button renders on the server, because that is the only place that can guarantee what comes out &mdash; which makes it a parser anybody can call.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Member search</label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_search_enabled">
                            <option value="1" <?= ($cfg['index_search_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= ($cfg['index_search_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Disabled (even with permissions)</option>
                        </select>
                        <small class="settings-hint">Master switch for <code>?action=search</code> — overrides <code>index.view</code> grants.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Whitelist in search results</label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_search_include_whitelist">
                            <option value="1" <?= ($cfg['index_search_include_whitelist'] ?? '1') === '1' ? 'selected' : '' ?>>Included (needs whitelist.view)</option>
                            <option value="0" <?= ($cfg['index_search_include_whitelist'] ?? '1') !== '1' ? 'selected' : '' ?>>Never shown</option>
                        </select>
                        <small class="settings-hint">Whether registered (whitelisted) torrents can appear in the member search.</small>
                        <small class="settings-hint">The member Index search (<code>?action=search</code>).</small>
                    </div>
                </div>
            </div>

            <!-- OpenTracker performance -->
            <div class="settings-section" id="section-ot-perf" data-group="tracker" data-title="OpenTracker Performance">
                <h5>OpenTracker &mdash; performance</h5>
                <p class="settings-hint mb-2">
                    The knobs that already exist on any systemd machine, and they are worth nearly all of the
                    available gain at nearly none of the risk. Everything the panel writes goes into
                    <strong>one file it owns</strong> &mdash; <code>90-tracker-panel.conf</code> in the unit's drop-in
                    directory. <code>override.conf</code> and <code>limits.conf</code> were put there by the installer
                    or by hand and are <strong>never touched</strong>; undo is deleting the panel's one file, which is
                    what <em>Reset</em> does. <strong>Saving here changes nothing by itself</strong> &mdash; these values
                    describe what you want, and the Apply button on the
                    <a href="<?= $baseUrl ?>?action=admin-traffic#ot-card">Traffic page</a> (admin password) is what
                    puts them in force.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Helper command</label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_perf_cmd" value="<?= sanitize($cfg['ot_perf_cmd'] ?? '') ?>" placeholder="e.g. sudo -n /usr/local/sbin/tracker-instance.sh">
                            <button class="btn btn-outline-info" type="button" id="btn-ot-test"><i class="bi bi-clipboard-check"></i> Test</button>
                        </div>
                        <small class="settings-hint">Needs a sudoers rule: <code>www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-instance.sh</code>. Empty = the panel can only read, never change.</small>
                        <div id="ot-test-result" class="mt-2"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Nice</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_nice" value="<?= sanitize($cfg['ot_nice'] ?? '-2') ?>" min="-20" max="19">
                        <small class="settings-hint">Lower = more CPU when the box is busy. &minus;2 is what the install notes recommend; below &minus;5 you start competing with the kernel's own threads.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">CPU weight</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_cpu_weight" value="<?= sanitize($cfg['ot_cpu_weight'] ?? '100') ?>" min="1" max="10000">
                        <small class="settings-hint">Share of CPU against other services under contention. 100 is the default for everything, so 200 means &ldquo;twice the forum's&rdquo;.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">CPU affinity</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_cpu_affinity" value="<?= sanitize($cfg['ot_cpu_affinity'] ?? '') ?>" placeholder="e.g. 2-5">
                        <small class="settings-hint">Empty = every core. Pinning helps only when something else on this machine needs cores kept free for it. <strong>Needs a restart.</strong></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Open file limit</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_limit_nofile" value="<?= sanitize($cfg['ot_limit_nofile'] ?? '65536') ?>" min="1024" max="1048576">
                        <small class="settings-hint"><code>LimitNOFILE</code>. <strong>Needs a restart.</strong></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">UDP workers</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_udp_workers" value="<?= sanitize($cfg['ot_udp_workers'] ?? '') ?>" placeholder="leave empty to keep">
                        <small class="settings-hint"><code>listen.udp.workers</code> in opentracker's own config &mdash; written to <strong>both</strong> mode files so it cannot change when the tracker switches white/black. Empty leaves it alone. <strong>Read only at start-up: needs a restart.</strong></small>
                    </div>
                </div>
            </div>

            <!-- Kernel network buffers -->
            <!-- Extra opentracker instances -->
            <div class="settings-section" id="section-cluster" data-group="tracker" data-title="OpenTracker instances">
                <h5>OpenTracker instances</h5>
                <small class="settings-hint d-block mb-3">Run <strong>more than one</strong> opentracker on this machine, on extra UDP ports, when one has genuinely run out of CPU. Check the <a href="<?= $baseUrl ?>?action=admin-traffic#ot-card">performance card</a> first: it says outright whether this would help, and on most machines it will not &mdash; the cheap knobs (worker count, priority, socket buffers) are worth nearly all of the available gain at nearly none of the risk. The installer&rsquo;s own <code>opentracker.service</code> is <strong>never touched</strong>; extras are added beside it, share the same accesslist and the same white/black mode, and removing them removes every trace. Off by default.</small>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Helper command</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_cluster_cmd" value="<?= sanitize($cfg['ot_cluster_cmd'] ?? '') ?>" maxlength="255" placeholder="e.g. sudo -n /usr/local/sbin/tracker-cluster.sh">
                        <small class="settings-hint">Empty = the feature does not exist: no card, no polling, no <code>sudo</code>.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Enabled</label>
                        <select class="form-select bg-dark text-light border-secondary" name="ot_cluster_enabled">
                            <option value="0" <?= ($cfg['ot_cluster_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= ($cfg['ot_cluster_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                        </select>
                        <small class="settings-hint">Turning it on only shows the card; it creates nothing.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">First extra port</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_cluster_port_base" value="<?= sanitize($cfg['ot_cluster_port_base'] ?? '') ?>" min="1024" max="65500" placeholder="(next to the primary&rsquo;s)">
                        <small class="settings-hint">Empty = the panel proposes the first free port next to the tracker&rsquo;s own. Ports below 1024 are refused.</small>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-cluster-test"><i class="bi bi-plug"></i> Test</button>
                    <div id="cluster-test-result" class="mt-2"></div>
                    <small class="settings-hint d-block mt-2">The Test button also checks something the panel cannot see by itself: whether the <em>installed</em> <code>/usr/local/sbin/tracker-mode.sh</code> understands <code>--all</code>. No deploy updates that file, and if the nightly schedule calls a flag the installed copy does not know, the scheduled whitelist hours simply never begin &mdash; silently, every night.</small>
                </div>
            </div>


            <div class="settings-section" id="section-sysctl" data-group="tracker" data-title="Kernel network buffers">
                <h5>Kernel network buffers</h5>
                <small class="settings-hint d-block mb-3">The eight kernel settings that decide how many announces survive a burst &mdash; the socket buffers, the per-CPU packet queue and the machine-wide UDP memory pool. This is the first thing the panel touches that is <strong>not</strong> the tracker&rsquo;s own: these belong to the whole machine, which here also runs mail, the forum, the file service and the database they share. So nothing is applied by saving. A change is <em>armed</em>, takes effect while you watch it, and <strong>puts itself back automatically unless you confirm</strong> &mdash; the undo is scheduled through systemd before the change is made, so it does not need this panel, the database, or an administrator who can still log in. Nothing is written to <code>/etc</code> until you confirm either, so until then a reboot also undoes it. The values live on the <a href="<?= $baseUrl ?>?action=admin-traffic#sysctl-card">Traffic page</a>, next to the counters that say which of them is worth touching at all.</small>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Helper command</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="sysctl_cmd" value="<?= sanitize($cfg['sysctl_cmd'] ?? '') ?>" maxlength="255" placeholder="e.g. sudo -n /usr/local/sbin/tracker-sysctl.sh">
                        <small class="settings-hint">Empty = the feature does not exist: no card, no polling, no <code>sudo</code>. Install <code>tools/opentracker/tracker-sysctl.sh</code> and its <code>sudoers.d</code> rule first, then press Test.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Enabled</label>
                        <select class="form-select bg-dark text-light border-secondary" name="sysctl_enabled">
                            <option value="0" <?= ($cfg['sysctl_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= ($cfg['sysctl_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                        </select>
                        <small class="settings-hint">Off by default. Turning it on only shows the card; it changes nothing.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Confirmation window (s)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="sysctl_confirm_seconds" value="<?= sanitize($cfg['sysctl_confirm_seconds'] ?? '120') ?>" min="60" max="900" step="60">
                        <small class="settings-hint">How long an armed change waits for your confirmation before undoing itself. Rounded to whole minutes, because the fallback watchdog is the janitor and it runs once a minute &mdash; a 30-second promise it cannot keep would still read as a guarantee.</small>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-sysctl-test"><i class="bi bi-plug"></i> Test</button>
                    <div id="sysctl-test-result" class="mt-2"></div>
                </div>
            </div>


            <!-- Federation / cluster -->
            <div class="settings-section" id="section-federation" data-group="integrations" data-title="Federation / Cluster">
                <h5>Federation / Cluster</h5>
                <small class="settings-hint d-block mb-3">Exchange resolved Index <strong>metadata</strong> with other tracker nodes so everyone builds a bigger search catalogue without re-fetching from the DHT. Pull-based: each node pulls compressed JSON pages from its peers' <code>v1/federation/export</code> (bearer key with the <em>federation</em> scope) and merges them with <code>worker/federation.py</code> (systemd timer — <strong>not</strong> PHP web time; see <code>worker/README.md</code>). By default an import only <em>fills in metadata</em> for hashes this tracker has itself observed; &ldquo;accept new hashes&rdquo; also inserts hashes never seen here. Needs the S2S API enabled above. Peers are managed below.</small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Federation</label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_enabled">
                            <option value="1" <?= ($cfg['fed_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= ($cfg['fed_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Node name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="fed_node_name" value="<?= sanitize($cfg['fed_node_name'] ?? '') ?>" maxlength="64" placeholder="my-tracker">
                        <small class="settings-hint">Shown to peers in export replies.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Export to peers</label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_export_enabled">
                            <option value="1" <?= ($cfg['fed_export_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['fed_export_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>No (pull only)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Export file lists</label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_export_files">
                            <option value="1" <?= ($cfg['fed_export_files'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['fed_export_files'] ?? '1') !== '1' ? 'selected' : '' ?>>No (name/size only)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Rows per export page</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_export_max_batch" value="<?= sanitize($cfg['fed_export_max_batch'] ?? '2000') ?>" min="100" max="20000">
                        <small class="settings-hint">Counts torrents, not what they contain &mdash; see the two budgets beside it.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Bytes per export page</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_export_max_bytes" value="<?= sanitize($cfg['fed_export_max_bytes'] ?? '8388608') ?>" min="0" max="1073741824" step="1048576">
                        <small class="settings-hint">0 = no limit. 8388608 = 8 MB, measured on the wire.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Import batch (rows)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_import_batch_rows" value="<?= sanitize($cfg['fed_import_batch_rows'] ?? '500') ?>" min="25" max="5000">
                        <small class="settings-hint">Rows the importer holds before it commits. The cursor moves with the batch, so an interrupted run costs at most this many rows &mdash; never a repair job.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Import batch (bytes)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_import_batch_bytes" value="<?= sanitize($cfg['fed_import_batch_bytes'] ?? '33554432') ?>" min="1048576" max="268435456" step="1048576">
                        <small class="settings-hint">Whichever fills first ends the batch. 33554432 = 32 MB.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Import time budget (s)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_import_max_seconds" value="<?= sanitize($cfg['fed_import_max_seconds'] ?? '600') ?>" min="30" max="21600">
                        <small class="settings-hint">One pass. What is left is picked up next time &mdash; the cursor makes that free.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Importer memory ceiling (MB)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_worker_mem_mb" value="<?= sanitize($cfg['fed_worker_mem_mb'] ?? '256') ?>" min="64" max="4096">
                        <small class="settings-hint">A hard <code>RLIMIT_AS</code> on the importer process. Every other guard is a promise about arithmetic; this one is the floor under them &mdash; the process dies rather than the machine, and the timer restarts it from the last committed batch.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">File records per export page</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_export_max_files" value="<?= sanitize($cfg['fed_export_max_files'] ?? '200000') ?>" min="0" max="50000000" step="10000">
                        <small class="settings-hint">0 = no limit. A page ends on whichever of the three runs out first, and hands back the cursor &mdash; so a heavy catalogue produces smaller pages on its own.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Accept new hashes</label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_import_new">
                            <option value="1" <?= ($cfg['fed_import_new'] ?? '0') === '1' ? 'selected' : '' ?>>Yes (grow the index from peers)</option>
                            <option value="0" <?= ($cfg['fed_import_new'] ?? '0') !== '1' ? 'selected' : '' ?>>No (only fill my observed hashes)</option>
                        </select>
                        <small class="settings-hint">Imported rows count against the Index row cap.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Trust incoming metadata</label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_import_mode">
                            <option value="fill" <?= ($cfg['fed_import_mode'] ?? 'fill') !== 'review' ? 'selected' : '' ?>>Straight in (fill)</option>
                            <option value="review" <?= ($cfg['fed_import_mode'] ?? 'fill') === 'review' ? 'selected' : '' ?>>Hold for review (quarantine)</option>
                        </select>
                        <small class="settings-hint">In review mode nothing a peer sends reaches the catalogue until you accept it &mdash; it waits in the queue below. Names are rendered as text, never as markup, so this is about <em>what you publish</em>, not about script injection. The first sync of a large peer can park tens of thousands of rows, so accept per peer rather than one at a time.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Pull interval (min)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_pull_minutes" value="<?= sanitize($cfg['fed_pull_minutes'] ?? '60') ?>" min="5" max="1440">
                        <small class="settings-hint">Honoured by <code>federation.py</code> when run in loop mode; a systemd timer uses its own schedule.</small>
                    </div>
                </div>
                <div class="mt-3" id="fed-peers-card">
                    <label class="form-label">Peers</label>
                    <div class="table-responsive">
                        <table class="table table-dark table-sm align-middle" id="fed-peers-table">
                            <thead><tr><th>Name</th><th>Base URL</th><th>Pull</th><th>Inbound key</th><th>Last pull</th><th>Imported</th><th>Status</th><th></th></tr></thead>
                            <tbody id="fed-peers-body"><tr><td colspan="8" class="text-muted">Loading&hellip;</td></tr></tbody>
                        </table>
                    </div>
                    <div class="row g-2 align-items-end" id="fed-peer-add">
                        <div class="col-md-2"><label class="form-label">Name</label><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="fp-name" maxlength="64" placeholder="other-tracker"></div>
                        <div class="col-md-3"><label class="form-label">Base URL</label><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="fp-url" maxlength="255" placeholder="https://tracker.example.org"></div>
                        <div class="col-md-3"><label class="form-label">Their bearer <small class="settings-hint">(for pulling FROM them; optional)</small></label><input type="password" class="form-control form-control-sm bg-dark text-light border-secondary" id="fp-bearer" autocomplete="off" placeholder="key_id.secret"></div>
                        <div class="col-md-1"><label class="form-label">Pull</label><select class="form-select form-select-sm bg-dark text-light border-secondary" id="fp-pull"><option value="1">Yes</option><option value="0" selected>No</option></select></div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-sm btn-outline-info" id="fp-add"><i class="bi bi-plus-lg"></i> Add peer</button>
                            <div class="form-check form-check-inline ms-1" title="Also create an API key (scope: federation) the peer uses to pull FROM us — shown once">
                                <input class="form-check-input" type="checkbox" id="fp-grant">
                                <label class="form-check-label settings-hint" for="fp-grant">grant inbound access</label>
                            </div>
                        </div>
                    </div>
                    <div id="fp-alert" class="mt-2"></div>
                    <small class="settings-hint d-block mt-1">Exchange flow: each admin adds the other as a peer, ticks <em>grant inbound access</em>, and sends the generated bearer to the other side, who pastes it into <em>Their bearer</em> and enables <em>Pull</em>. Test verifies the outbound direction. <em>Undo import</em> returns everything a peer contributed to unresolved &mdash; the hashes and their local history stay, only the borrowed descriptions go.</small>
                </div>

                <!-- The quarantine queue. Hidden while it is empty AND review mode is off, because a
                     node that trusts its peers should not have to look at a control it never uses. -->
                <div class="mt-4 d-hidden" id="fed-review-card">
                    <label class="form-label">Waiting for review <span class="badge bg-warning text-dark" id="fr-count">0</span></label>
                    <small class="settings-hint d-block mb-2">Everything below arrived from a peer while <em>Trust incoming metadata</em> was set to review. Nothing here is in the catalogue or in search. Accepting merges a package exactly as a normal import would; rejecting keeps a note so the peer does not offer it again on every pull.</small>
                    <div class="d-flex gap-2 align-items-center flex-wrap mb-2">
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="fr-peer" style="max-width:14rem;"><option value="">All peers</option></select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="fr-state" style="max-width:11rem;">
                            <option value="pending">Waiting</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="fr-refresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                        <span class="flex-grow-1"></span>
                        <button type="button" class="btn btn-sm btn-outline-success" id="fr-accept-sel" disabled><i class="bi bi-check2"></i> Accept selected</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="fr-reject-sel" disabled><i class="bi bi-x"></i> Reject selected</button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="fr-accept-peer" disabled title="Accept everything still waiting from the peer selected on the left"><i class="bi bi-check2-all"></i> Accept whole peer&hellip;</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark table-sm align-middle" id="fed-review-table">
                            <thead><tr><th style="width:2rem;"><input type="checkbox" class="form-check-input" id="fr-all"></th><th>Name</th><th>Size</th><th>Files</th><th>Peer</th><th>Resolved</th><th></th></tr></thead>
                            <tbody id="fed-review-body"></tbody>
                        </table>
                    </div>
                    <div id="fr-alert" class="mt-2"></div>
                </div>
            </div>

            <!-- Rate Limits & Blacklist -->
            <div class="settings-section" id="section-limits" data-group="security" data-title="Rate Limits &amp; Blacklist">
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
                        <label class="form-label">&ldquo;Near pages&rdquo; radius (admin)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_near_pages" value="<?= sanitize($cfg['admin_near_pages'] ?? '2') ?>" min="1" max="20">
                        <small class="settings-hint">Whitelist / Index bulk tools: <em>Near pages</em> covers the current page &plusmn; this many pages (same search, filters and sort).</small>
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

            <!-- Admin address, Sessions, Login Lockout & Proxy -->
            <div class="settings-section" id="section-admin-access" data-group="security" data-title="Admin Access &amp; Sessions">
                <h5>Admin Access &amp; Sessions</h5>
                <?php $adminPathNow = adminLoginPath($cfg); $adminHiddenNow = adminHiddenBehavior($cfg); ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Admin sign-in address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark text-secondary border-secondary">?action=</span>
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="admin_login_path" value="<?= sanitize($adminPathNow) ?>" maxlength="64" pattern="[A-Za-z0-9_\-]+" placeholder="admin">
                        </div>
                        <small class="settings-hint">The only address that shows this sign-in form. Moving it somewhere unguessable (e.g. <code>admin123yzxadminxxx</code>) keeps crawlers and drive-by bots off the form &mdash; it is <em>not</em> a substitute for a strong password: the login API lives at a fixed address and the real brute-force protection is the lockout below (plus <em>On Admin Login</em> in the CAPTCHA section). While a custom address is set, that API additionally refuses any sign-in from a session that never opened this page. Letters, digits, <code>-</code> and <code>_</code>; empty, or a name already used by another page, falls back to <code>admin</code>. The panel pages keep their normal addresses (<code>?action=admin</code>, <code>?action=settings</code>, &hellip;) once you are signed in, so bookmarks, links and the Logout button keep working. <strong>Write the new address down before saving.</strong></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Other admin URLs when signed out</label>
                        <select class="form-select bg-dark text-light border-secondary" name="admin_hidden_behavior">
                            <option value="home" <?= $adminHiddenNow === 'home' ? 'selected' : '' ?>>Redirect to the front page (default)</option>
                            <option value="login" <?= $adminHiddenNow === 'login' ? 'selected' : '' ?>>Show the sign-in form (classic)</option>
                            <option value="404" <?= $adminHiddenNow === '404' ? 'selected' : '' ?>>Answer 404 Not Found</option>
                        </select>
                        <small class="settings-hint">What a visitor who is not signed in gets on a panel URL other than the sign-in address above. The default shows no login form anywhere except at your own address (the API still answers <code>401</code> to admin calls, so this hides the panel from browsing, not from a determined scan).</small>
                    </div>
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
                    <div class="col-md-3" data-setting="admin_reauth_max_attempts">
                        <label class="form-label">Wrong password confirmations before sign-out</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_reauth_max_attempts" value="<?= sanitize($cfg['admin_reauth_max_attempts'] ?? '5') ?>" min="1" max="20">
                        <small class="settings-hint">Every dangerous action asks for the password again. This many wrong answers <strong>signs the session out</strong> &mdash; getting back in means the sign-in page, with its CAPTCHA and address lockout. Each wrong answer also costs progressively more time, starting at once. The session gate keeps strangers out; this is for whoever is already sitting at the machine.</small>
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
            <div class="settings-section" id="section-donations" data-group="general" data-title="Donation Fields">
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
                <div id="donation-fields-list" class="mt-2" data-setting="donation_fields">
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
                <button type="button" class="btn btn-sm btn-outline-info mt-1" id="donation-field-add" data-setting="donation_fields"><i class="bi bi-plus-lg"></i> Add Field</button>
            </div>

            <!-- Transparency Page -->
            <div class="settings-section" id="section-transparency" data-group="general" data-title="Transparency Page">
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
            <div class="settings-section" id="section-stats" data-group="stats" data-title="Tracker Statistics">
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

            <!-- Statistics Timeline -->
            <div class="settings-section" id="section-timeline" data-group="stats" data-title="Statistics Timeline">
                <h5>Statistics Timeline</h5>
                <small class="settings-hint d-block mb-3">Records the tracker statistics over time (seeds, leechers, peers, torrents, announce rates, tracker mode) and draws a stock-style chart on the public <strong>/?action=stats</strong> page and on the admin Traffic page. Samples are taken by the <strong>janitor timer</strong> (<code>tools/janitor.php</code>, every minute — see README) and, for free, by every upstream refresh the stats page makes; roll-ups (5 min / 1 h) and retention run from the same timer. Requires <em>Tracker Statistics</em> above to be enabled with a valid Stats Source URL.</small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Enable Timeline</label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_enabled">
                            <option value="1" <?= ($cfg['stats_timeline_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['stats_timeline_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sample Interval (Sec)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="stats_timeline_interval" value="<?= sanitize($cfg['stats_timeline_interval'] ?? '60') ?>" min="30" max="600">
                        <small class="settings-hint">One sample per this many seconds (30&ndash;600). 60 matches the janitor timer.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Keep Raw Samples (Days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="stats_timeline_raw_days" value="<?= sanitize($cfg['stats_timeline_raw_days'] ?? '7') ?>" min="1" max="30">
                        <small class="settings-hint">Raw resolution (1&ndash;30 days); used by the 24 h view.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Keep 5-min Roll-ups (Days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="stats_timeline_keep_days" value="<?= sanitize($cfg['stats_timeline_keep_days'] ?? '60') ?>" min="7" max="3650">
                        <small class="settings-hint">7&ndash;3650 days; hourly roll-ups are kept forever (~9 k rows/year).</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Public Chart</label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_public">
                            <option value="1" <?= ($cfg['stats_timeline_public'] ?? '1') === '1' ? 'selected' : '' ?>>Yes &mdash; on the stats page</option>
                            <option value="0" <?= ($cfg['stats_timeline_public'] ?? '1') === '0' ? 'selected' : '' ?>>No &mdash; admins only</option>
                        </select>
                        <small class="settings-hint">When off, the chart and the <code>stats_timeline</code> API answer only logged-in admins.</small>
                    </div>
                </div>
                <?php $tlEnabledRanges = statsTimelineEnabledRanges($cfg); $tlDefaultRange = statsTimelineDefaultRange($cfg); ?>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info">Range buttons</small></div>
                    <div class="col-md-3">
                        <label class="form-label">Default range</label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_default_range">
                            <?php foreach (statsTimelineRangeButtons() as $rKey => $rLabel): ?>
                            <option value="<?= $rKey ?>" <?= $tlDefaultRange === $rKey ? 'selected' : '' ?>><?= sanitize($rLabel) ?> (<?= $rKey ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="settings-hint">The range a visitor's first view opens on (their own last pick is then remembered in the browser). A short default is the cheapest one to serve.</small>
                    </div>
                    <div class="col-md-6" data-setting="stats_timeline_ranges">
                        <label class="form-label">Buttons offered</label>
                        <div class="tl-range-picker">
                            <?php foreach (statsTimelineRangeButtons() as $rKey => $rLabel): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input tl-range-check" type="checkbox" id="tlr-<?= $rKey ?>" value="<?= $rKey ?>" <?= in_array($rKey, $tlEnabledRanges, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="tlr-<?= $rKey ?>"><?= sanitize($rLabel) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="settings-hint">Unchecked ranges disappear from the chart everywhere (stats page, Index, Whitelist). Clearing them all falls back to the full set; the default range must stay checked or the first remaining button wins.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Custom span slider</label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_custom_range">
                            <option value="0" <?= statsTimelineCustomRange($cfg) ? '' : 'selected' ?>>No</option>
                            <option value="1" <?= statsTimelineCustomRange($cfg) ? 'selected' : '' ?>>Yes &mdash; add a "Custom" button</option>
                        </select>
                        <small class="settings-hint">Adds a slider that sweeps any span from 1 h to 5 years. These replies are computed per request (the fixed ranges are served from a 30 s file cache), so leave it off on a busy public page.</small>
                    </div>
                </div>
            </div>

            <!-- Observed-hash Index -->
            <div class="settings-section" id="section-index" data-group="index" data-title="Index (observed hashes)">
                <h5>Index (observed hashes)</h5>
                <small class="settings-hint d-block mb-3">A catalogue of info hashes <strong>seen on the tracker</strong> (mostly during OPEN hours, when the whole swarm is served), browsable at <a href="<?= $baseUrl ?>?action=admin-index">Index</a>. <strong>This is not a whitelist</strong> &mdash; nothing here is served or written to the accesslist; it is a read-only catalogue with metadata, S/L, search and a <em>Promote &rarr; whitelist</em> action. The janitor polls a full scrape (<code>GET&nbsp;/scrape</code>) every <em>Poll interval</em>, keeps hashes with at least <em>Min seeders</em>, and the metadata worker resolves names in the background (second queue, below the whitelist &mdash; needs DB grants, see <code>worker/README.md</code>). <strong>Measure the full-scrape cost during OPEN hours before enabling on a busy tracker.</strong></small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Enable Index</label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_enabled">
                            <option value="1" <?= ($cfg['index_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['index_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Full-scrape Source URL</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="index_source_url" value="<?= sanitize($cfg['index_source_url'] ?? 'http://127.0.0.1:6969/scrape') ?>" placeholder="http://127.0.0.1:6969/scrape">
                        <small class="settings-hint">OpenTracker <code>/scrape</code> with no info_hash = full scrape. Localhost recommended.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Poll Interval (min)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_poll_minutes" value="<?= sanitize($cfg['index_poll_minutes'] ?? '30') ?>" min="5" max="1440">
                        <small class="settings-hint">5&ndash;1440. OpenTracker's modest-fullscrape limit is 5&nbsp;min/IP.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Min Seeders to Index</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_min_seeders" value="<?= sanitize($cfg['index_min_seeders'] ?? '1') ?>" min="0" max="100000">
                        <small class="settings-hint">Drop the dead-swarm tail. 1 = at least one seeder.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max Rows (cap)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_max_rows" value="<?= sanitize($cfg['index_max_rows'] ?? '200000') ?>" min="1000" max="5000000">
                        <small class="settings-hint">Oldest unprotected rows are pruned above this.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Poll Time Budget (s)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_poll_budget" value="<?= sanitize($cfg['index_poll_budget'] ?? '45') ?>" min="5" max="120">
                        <small class="settings-hint">Wall-clock cap per poll; a huge scrape is processed partially.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Grace (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_grace_days" value="<?= sanitize($cfg['index_grace_days'] ?? '3') ?>" min="1" max="90">
                        <small class="settings-hint">A new hash is dropped after this unless its metadata resolves.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Protect (days)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_protect_days" value="<?= sanitize($cfg['index_protect_days'] ?? '10') ?>" min="1" max="365">
                        <small class="settings-hint">A resolved hash lives this long; extended while it still has seeders.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Metadata Budget (per day)</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_meta_daily_budget" value="<?= sanitize($cfg['index_meta_daily_budget'] ?? '500') ?>" min="0" max="1000000">
                        <small class="settings-hint">Rows queued for metadata per day, spread across 24&nbsp;h. 0 = never auto-fetch.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Auto-queue Metadata</label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_meta_auto_queue">
                            <option value="1" <?= ($cfg['index_meta_auto_queue'] ?? '0') === '1' ? 'selected' : '' ?>>Yes (queue every new hash)</option>
                            <option value="0" <?= ($cfg['index_meta_auto_queue'] ?? '0') === '0' ? 'selected' : '' ?>>No (daily budget only)</option>
                        </select>
                        <small class="settings-hint">When on, every observed hash without metadata is queued automatically (spread over ~1&nbsp;h, best seeded first) and the daily budget is <strong>ignored</strong>. Mind the DHT load on a big index.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Worker parallel fetches <small class="settings-hint">(empty = worker config)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="meta_worker_concurrency" value="<?= sanitize($cfg['meta_worker_concurrency'] ?? '') ?>" min="1" max="64" placeholder="e.g. 8">
                        <small class="settings-hint">How many hashes the metadata worker resolves at once (whitelist + index queues). The worker re-reads this every ~60&nbsp;s &mdash; no restart needed. <strong>1&ndash;64.</strong> Each fetch is one libtorrent handle holding a small set of DHT and peer connections, so the ceiling here is file descriptors and memory, not libtorrent: at the top end expect a few hundred sockets, a few hundred MB, and outbound traffic to match. Raise it because this machine has spare capacity, not because the number is available.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Keep File Lists</label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_keep_files">
                            <option value="1" <?= ($cfg['index_keep_files'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= ($cfg['index_keep_files'] ?? '1') === '0' ? 'selected' : '' ?>>No (name/size only)</option>
                        </select>
                        <small class="settings-hint">The worker also honours <code>index_keep_files</code> in its own conf.</small>
                    </div>
                </div>
            </div>

            <!-- OpenTracker Service -->
            <div class="settings-section" id="section-service" data-group="tracker" data-title="OpenTracker Service">
                <h5>OpenTracker Service</h5>
                <small class="settings-hint d-block mb-3">Define the systemd unit of your tracker (e.g. <code>opentracker</code> or <code>opentracker.service</code>). When set, <strong>Reload</strong> and <strong>Restart tracker</strong> buttons appear on the Dashboard, together with smart recommendations that turn <span class="text-warning">orange</span> or <span class="text-danger">red</span> when a restart is advisable (after blacklist changes, or a long uptime). <strong>Reload</strong> runs <code>systemctl reload &lt;name&gt;</code> &mdash; a <strong>SIGHUP</strong> that makes OpenTracker re-read its white/blacklist with <em>no downtime</em>; <strong>Restart</strong> runs <code>systemctl restart &lt;name&gt;</code> (brief downtime). The web/PHP user must be allowed to run those commands &mdash; use the <strong>Test</strong> buttons below and see the README (sudoers). Leave the name empty to hide the buttons entirely.</small>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Service name <small class="settings-hint">(empty = disabled)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="opentracker_service_name" value="<?= sanitize($cfg['opentracker_service_name'] ?? '') ?>" placeholder="opentracker" pattern="[A-Za-z0-9._@\-]+" maxlength="128">
                        <small class="settings-hint">Only letters, digits and <code>. _ @ -</code> are allowed.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Run via sudo</label>
                        <select class="form-select bg-dark text-light border-secondary" name="opentracker_restart_use_sudo">
                            <option value="1" <?= ($cfg['opentracker_restart_use_sudo'] ?? '1') === '1' ? 'selected' : '' ?>>Yes &mdash; <code>sudo -n</code></option>
                            <option value="0" <?= ($cfg['opentracker_restart_use_sudo'] ?? '1') === '0' ? 'selected' : '' ?>>No &mdash; direct</option>
                        </select>
                        <small class="settings-hint">Most setups need sudo: php-fpm runs unprivileged.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Auto-reload blacklist</label>
                        <select class="form-select bg-dark text-light border-secondary" name="opentracker_auto_reload">
                            <option value="1" <?= ($cfg['opentracker_auto_reload'] ?? '1') === '1' ? 'selected' : '' ?>>Yes &mdash; SIGHUP on change</option>
                            <option value="0" <?= ($cfg['opentracker_auto_reload'] ?? '1') === '0' ? 'selected' : '' ?>>No &mdash; manual only</option>
                        </select>
                        <small class="settings-hint">Auto <code>systemctl reload</code> after every block/unblock/restore so changes apply instantly.</small>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12">
                        <label class="form-label d-block">Permission test</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-restart"><i class="bi bi-shield-check"></i> Test restart permission</button>
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-reload"><i class="bi bi-shield-check"></i> Test reload permission</button>
                        </div>
                        <small class="settings-hint d-block mt-1">Checks whether the web user may run the command (via <code>sudo -n -l</code>) &mdash; it does <strong>not</strong> restart or reload anything. Save the service name first. Fix instructions are shown if the test fails.</small>
                        <div id="tracker-perm-result" class="mt-1 blacklist-result"></div>
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

            <!-- UDP traffic & rate limit — includes/netlimit.php + tools/opentracker/tracker-netlimit.sh -->
            <div class="settings-section" id="section-netlimit" data-group="tracker" data-title="UDP traffic &amp; rate limit">
                <h5>UDP traffic &amp; rate limit</h5>
                <p class="settings-hint mb-2">
                    Two different problems, two different levers.
                    <strong>Inbound</strong> (this section): packets dropped by the firewall never reach OpenTracker, so a swarm that keeps
                    hammering the port costs no CPU. <strong>Outbound</strong> (<code>tools/opentracker/egress-budget/ottrack.nft</code>):
                    capping what the tracker <em>answers</em> is what keeps the rest of the machine reachable. The panel drives the first and
                    shows the second side by side on the <a href="<?= $baseUrl ?>?action=admin-traffic#net-card">Traffic page</a>.
                    <br>
                    Everything here is applied through the root helper <code>tools/opentracker/tracker-netlimit.sh</code>, which writes
                    <strong>one file</strong> (<code>/etc/nftables.d/ottrack-in.nft</code>) in <strong>its own nftables table</strong>
                    (<code>inet ottrack_in</code>). Your distribution's <code>inet filter</code> table and any rule you added there by hand are
                    never read or written &mdash; undoing all of this is one click, or <code>nft delete table inet ottrack_in</code>.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Traffic monitor</label>
                        <select class="form-select bg-dark text-light border-secondary" name="net_monitor_enabled">
                            <option value="0" <?= ($cfg['net_monitor_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['net_monitor_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>On &mdash; record packets/second</option>
                        </select>
                        <small class="settings-hint">
                            Turn it on <em>first</em>: the suggested threshold is computed from what was measured, not guessed.
                            The counters live in the firewall, so the Traffic page offers <strong>Start counting</strong> &mdash; the same table with
                            <strong>no drop rule at all</strong>, which measures without throttling anything.
                        </small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sample every <small class="settings-hint">(seconds)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_sample_seconds" value="<?= (int)netlimitSampleSeconds($cfg) ?>" min="<?= NET_SAMPLE_MIN ?>" max="<?= NET_SAMPLE_MAX ?>">
                        <small class="settings-hint">The janitor timer runs every minute, so anything below 60 s samples at most once a minute anyway.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Keep samples for <small class="settings-hint">(days)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_keep_days" value="<?= (int)netlimitKeepDays($cfg) ?>" min="<?= NET_KEEP_MIN ?>" max="<?= NET_KEEP_MAX ?>">
                        <small class="settings-hint">At one sample a minute that is ~1 440 rows a day &mdash; 14 days is about 20 000 tiny rows.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tracker UDP port</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_limit_port" value="<?= (int)netlimitPort($cfg) ?>" min="1" max="65535">
                        <small class="settings-hint">The port in your announce URL (<code>6969</code> by default). Only this port is touched.</small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-netlimit-throttle">Inbound limit <small class="settings-hint fw-normal">(the throttle)</small></h6>
                <p class="settings-hint mb-2">
                    Saving here records what the panel <em>should</em> load &mdash; it does not touch the firewall. Loading, previewing and
                    removing the rule live on the <a href="<?= $baseUrl ?>?action=admin-traffic#net-card">Traffic page</a>, behind the
                    admin password, with the measured median / P95 / peak drawn on the slider.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Inbound limit</label>
                        <select class="form-select bg-dark text-light border-secondary" name="net_limit_enabled">
                            <option value="0" <?= ($cfg['net_limit_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Off &mdash; nothing is dropped</option>
                            <option value="1" <?= ($cfg['net_limit_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Packets / second</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_limit_pps" value="<?= (int)netlimitPps($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint">Everything above this is dropped before OpenTracker sees it. <?= NET_PPS_MIN ?>&ndash;<?= NET_PPS_MAX ?>.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Burst <small class="settings-hint">(packets)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_limit_burst" value="<?= (int)netlimitBurst($cfg) ?>" min="<?= NET_BURST_MIN ?>" max="<?= NET_BURST_MAX ?>">
                        <small class="settings-hint">How far over the budget a short spike may go before packets are dropped. 100 is a sane default; 5 makes the limit bite almost immediately.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label d-block">Availability test</label>
                        <button type="button" class="btn btn-outline-info btn-sm w-100" id="btn-test-netlimit"><i class="bi bi-shield-check"></i> Test firewall access</button>
                        <small class="settings-hint d-block mt-1">Read-only: checks <code>nft</code>, the sudoers rule and whether the rule would survive a reboot. It never loads or removes anything.</small>
                    </div>
                    <div class="col-12">
                        <div id="netlimit-result" class="blacklist-result"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Root helper command <small class="settings-hint">(the action arguments are appended automatically; empty = feature disabled)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="net_limit_cmd" value="<?= sanitize($cfg['net_limit_cmd'] ?? NET_DEFAULT_CMD) ?>" placeholder="e.g. <?= NET_DEFAULT_CMD ?>" maxlength="255">
                        <small class="settings-hint">Only letters, digits, space and <code>_ . / -</code> &mdash; no shell metacharacters, exactly like the mode switch command above.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Install it once, on the server</label>
                        <pre class="settings-code mb-0"><code>sudo install -m 0755 tools/opentracker/tracker-netlimit.sh /usr/local/sbin/
echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-netlimit.sh' \
  | sudo tee /etc/sudoers.d/tracker-netlimit
sudo chmod 440 /etc/sudoers.d/tracker-netlimit</code></pre>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-netlimit-auto">Automatic mode <small class="settings-hint fw-normal">(off by default)</small></h6>
                <p class="settings-hint mb-2">
                    Once a minute the janitor compares the packets/second that actually reached the tracker with your target and moves the limit by
                    &plusmn;10 % inside the band below. It takes <strong>three</strong> samples in a row on the same side before anything moves
                    (so one spike changes nothing), and there is a two-minute cool-down between moves. Needs both the monitor and the inbound
                    limit switched on.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Automatic mode</label>
                        <select class="form-select bg-dark text-light border-secondary" name="net_auto_enabled">
                            <option value="0" <?= ($cfg['net_auto_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['net_auto_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>On &mdash; move the limit for me</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Target <small class="settings-hint">(packets/second)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_target" value="<?= (int)netlimitAutoTarget($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint">How many packets a second you are willing to hand to OpenTracker. Above it the limit tightens, comfortably below it (&minus;20 %) it loosens.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Band: minimum</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_min" value="<?= (int)netlimitAutoMin($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint">The automatic mode never throttles below this.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Band: maximum</label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_max" value="<?= (int)netlimitAutoMax($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint">&hellip; and never loosens above this.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">CPU guard <small class="settings-hint">(% load per core)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_target_cpu" value="<?= (int)netlimitAutoTargetCpu($cfg) ?>" min="10" max="100">
                        <small class="settings-hint">
                            When the 1-minute load average per core is above this, the automatic mode tightens even if the packet rate is under target.
                            <?php $nlCpus = netlimitCpuCount(); if ($nlCpus > 0): ?>This machine has <strong><?= (int)$nlCpus ?></strong> cores, so <?= (int)netlimitAutoTargetCpu($cfg) ?> % means a load average above <strong><?= number_format($nlCpus * netlimitAutoTargetCpu($cfg) / 100, 1) ?></strong>.<?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Backups — includes/backup.php + tools/opentracker/tracker-backup.sh -->
            <?php
            $bkPlan = backupParseSchedule((string)($cfg['backup_schedule'] ?? ''));
            $bkDays = $bkPlan['days'] ?? [];
            $bkTime = $bkPlan ? sprintf('%02d:%02d', intdiv($bkPlan['minutes'], 60), $bkPlan['minutes'] % 60) : '04:00';
            $bkTz   = backupTimezone($cfg);
            $bkTzGroups = [];
            foreach ((function_exists('timezone_identifiers_list') ? timezone_identifiers_list() : [$bkTz]) as $tzId) {
                $bkTzGroups[strpos($tzId, '/') !== false ? substr($tzId, 0, strpos($tzId, '/')) : 'Other'][] = $tzId;
            }
            $bkItems = backupSanitizeItems((string)($cfg['backup_items'] ?? ''));
            $bkItemsSel = $bkItems === '' ? [] : explode(',', $bkItems);
            ?>
            <div class="settings-section" id="section-backups" data-group="maintenance" data-title="Backups">
                <h5>Backups</h5>
                <p class="settings-hint mb-2">
                    This backs up <strong>the tracker</strong>. Backing up the whole machine — mail, the forum, certificates — is a separate
                    job for the server toolkit, and the panel does not try to be it.
                    By default it dumps the tracker database with <code>mariadb-dump</code>. If <code>Backup-serwera.sh</code> is installed here,
                    the panel drives <em>that</em> instead, through the root helper <code>tools/opentracker/tracker-backup.sh</code> — so there is one
                    backup program on this machine, not two — and the profiles below gain its configuration, list, unit and firewall items.
                    Browse, verify, download and restore archives on the
                    <a href="<?= $baseUrl ?>?action=admin-backups">Backups page</a>; this section is the policy.
                    <br>
                    Nothing heavy ever runs in a web request: a backup is started detached and reports back through a state file the page polls.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Backups</label>
                        <select class="form-select bg-dark text-light border-secondary" name="backup_enabled">
                            <option value="0" <?= ($cfg['backup_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= ($cfg['backup_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>On &mdash; allow the schedule to run</option>
                        </select>
                        <small class="settings-hint">Off means nothing runs on a timer. You can still make a backup by hand from the Backups page.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Directory <small class="settings-hint">(outside the web root; created <code>0700 root</code> on the first run)</small></label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_dir" value="<?= sanitize(backupDir($cfg)) ?>" placeholder="<?= BACKUP_DEFAULT_DIR ?>">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-backup-dir">Test</button>
                        </div>
                        <small class="settings-hint">An archive contains every database password on this box, so a path the web server could serve is refused outright.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Database to dump / restore</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_db_name" value="<?= sanitize(backupDbName($cfg)) ?>" placeholder="tracker" pattern="[A-Za-z0-9_]+" maxlength="64">
                        <small class="settings-hint">Letters, digits and <code>_</code>. This is also the name you have to type to confirm a restore.</small>
                    </div>
                    <div class="col-12">
                        <div id="backup-dir-result" class="blacklist-result"></div>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-what">What goes in</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Profile</label>
                        <select class="form-select bg-dark text-light border-secondary" name="backup_profile" id="backup-profile">
                            <?php foreach (BACKUP_PROFILES as $p): ?>
                            <option value="<?= sanitize($p) ?>" <?= backupProfile($cfg) === $p ? 'selected' : '' ?>><?= sanitize(backupProfileLabel($p)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="settings-hint">
                            <strong>Light</strong> leaves out <code>index_hashes</code> / <code>index_files</code> &mdash; on this install those are the two biggest tables by far and they rebuild themselves from the swarm.
                            <strong>Full</strong> takes everything.
                        </small>
                    </div>
                    <div class="col-md-8" data-setting="backup_items" id="backup-items-cell">
                        <label class="form-label">Custom selection <small class="settings-hint">(used by the "Custom selection" profile)</small></label>
                        <div class="bk-item-grid">
                            <?php foreach (backupTrackerItems() as $it): ?>
                            <div class="form-check">
                                <input class="form-check-input bk-item-check" type="checkbox" id="bk-set-<?= sanitize($it) ?>" value="<?= sanitize($it) ?>" <?= in_array($it, $bkItemsSel, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bk-set-<?= sanitize($it) ?>"><code><?= sanitize($it) ?></code></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="backup_items" id="backup-items-json" value="<?= sanitize($bkItems) ?>">
                        <small class="settings-hint">These are <code>Backup-serwera.sh</code> item names. Other services on this machine (mail, forum, files) have their own items &mdash; add them by hand in the field the toolkit's <code>--list</code> shows if you want one archive for everything.</small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-when">When</h6>
                <div class="row g-3">
                    <div class="col-12" data-setting="backup_schedule">
                        <label class="form-label">Automatic backup</label>
                        <div class="bk-sched-row">
                            <?php foreach (BACKUP_DAY_LABELS as $d => $label): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input bk-day-check" type="checkbox" id="bk-day-<?= $d ?>" value="<?= $d ?>" <?= in_array($d, $bkDays, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bk-day-<?= $d ?>"><?= $label ?></label>
                            </div>
                            <?php endforeach; ?>
                            <span class="bk-sched-at">at</span>
                            <input type="time" class="form-control form-control-sm bg-dark text-light border-secondary bk-sched-time" id="bk-sched-time" value="<?= sanitize($bkTime) ?>" step="60">
                        </div>
                        <input type="hidden" name="backup_schedule" id="backup-schedule-json" value="<?= sanitize((string)($cfg['backup_schedule'] ?? '')) ?>">
                        <small class="settings-hint">
                            Tick no days and nothing runs automatically. The janitor timer fires it (never a web request), and a slot that was missed
                            because the machine was off still runs later the same day &mdash; but never twice for the same slot.
                            <span id="bk-sched-summary" class="d-block mt-1"><?= sanitize(backupScheduleDescribe((string)($cfg['backup_schedule'] ?? ''), $bkTz)) ?></span>
                        </small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Timezone</label>
                        <select class="form-select bg-dark text-light border-secondary" name="backup_schedule_tz" id="backup-tz">
                            <?php foreach ($bkTzGroups as $grp => $ids): ?>
                            <optgroup label="<?= sanitize($grp) ?>">
                                <?php foreach ($ids as $tzId): ?>
                                <option value="<?= sanitize($tzId) ?>" <?= $tzId === $bkTz ? 'selected' : '' ?>><?= sanitize($tzId) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Process priority <small class="settings-hint">(nice, 0&ndash;19)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_nice" value="<?= (int)backupNice($cfg) ?>" min="0" max="19">
                        <small class="settings-hint">15 keeps a dump from fighting the tracker for CPU and disk; the helper also asks systemd for idle I/O.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Check each archive after writing it</label>
                        <select class="form-select bg-dark text-light border-secondary" name="backup_verify_after">
                            <option value="1" <?= ($cfg['backup_verify_after'] ?? '1') === '1' ? 'selected' : '' ?>>Yes &mdash; checksum + read it back</option>
                            <option value="0" <?= ($cfg['backup_verify_after'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                        <small class="settings-hint">An archive nobody has ever read back is a guess, not a backup.</small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-keep">How long they stay</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Keep at most <small class="settings-hint">(archives; 0 = no limit)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_keep" value="<?= (int)backupKeep($cfg) ?>" min="0" max="<?= BACKUP_KEEP_MAX ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Delete older than <small class="settings-hint">(days; 0 = no limit)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_keep_days" value="<?= (int)backupKeepDays($cfg) ?>" min="0" max="<?= BACKUP_DAYS_MAX ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Directory ceiling <small class="settings-hint">(GB; 0 = no limit)</small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_max_size_gb" value="<?= (int)backupMaxGb($cfg) ?>" min="0" max="<?= BACKUP_GB_MAX ?>">
                        <small class="settings-hint">Oldest first, until the directory fits. The last archive standing is never deleted &mdash; a full disk is bad, no backup at all is worse.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Encrypt for <small class="settings-hint">(GPG key id or email; empty = no encryption)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_gpg_recipient" value="<?= sanitize(backupGpgRecipient($cfg)) ?>" placeholder="backup@example.org" maxlength="128">
                        <small class="settings-hint">
                            <strong>Public-key</strong> encryption (<code>gpg --encrypt --recipient</code>), because it needs no passphrase and therefore no terminal.
                            Import the key as root first (<code>gpg --import</code>). Leave empty and the archives sit on disk in the clear &mdash; acceptable while they never leave a server only root can read, and not acceptable the moment you copy one off it.
                        </small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-tools">Tools</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Root helper command <small class="settings-hint">(the action arguments are appended automatically)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_cmd" value="<?= sanitize($cfg['backup_cmd'] ?? BACKUP_DEFAULT_CMD) ?>" placeholder="e.g. <?= BACKUP_DEFAULT_CMD ?>" maxlength="255">
                        <small class="settings-hint">Only letters, digits, space and <code>_ . / -</code> &mdash; no shell metacharacters.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Path to <code>Backup-serwera.sh</code> <small class="settings-hint">(empty = look in the usual places)</small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_script_path" value="<?= sanitize($cfg['backup_script_path'] ?? BACKUP_DEFAULT_SCRIPT) ?>" placeholder="<?= BACKUP_DEFAULT_SCRIPT ?>" maxlength="255">
                        <small class="settings-hint">Not found? The panel falls back to dumping the tracker database only, and the Backups page says so.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Install it once, on the server</label>
                        <pre class="settings-code mb-0"><code>sudo install -m 0755 tools/opentracker/tracker-backup.sh /usr/local/sbin/
echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-backup.sh' \
  | sudo tee /etc/sudoers.d/tracker-backup
sudo chmod 440 /etc/sudoers.d/tracker-backup
sudo install -d -m 0700 <?= sanitize(backupDir($cfg)) ?></code></pre>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="settings-section" id="section-footer" data-group="general" data-title="Footer">
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
            <div class="mt-3 mb-4" id="settings-save-row">
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </div>
        </form>

        <!-- Security & Credentials lives outside #settings-form (own endpoint) but must share its width cap. -->
        <div class="settings-narrow">
        <hr class="border-secondary" id="settings-rule">

        <!-- Security -->
        <?php
        // The panel login is mirrored into `users` (schema v8), so the admin's own account — and its
        // email address — is managed right here, with the same two-step confirmation a member gets.
        $adminAccount = userFindByLogin($db, (string)($cfg['admin_username'] ?? 'admin'));
        $adminEmail = $adminAccount ? trim((string)($adminAccount['email'] ?? '')) : '';
        $adminEmailVerified = $adminAccount && (int)$adminAccount['email_verified'] === 1;
        $adminEmailPending = $adminAccount ? userEmailChangeState($db, $adminAccount) : null;
        $adminEmailCooldown = userEmailChangeCooldownDays($cfg);
        ?>
        <div class="settings-section" id="section-credentials" data-group="credentials" data-title="Security &amp; Credentials">
            <h5>Security &amp; Credentials</h5>
            <?php if ($adminEmailPending): ?>
            <div class="alert alert-info py-2 px-3" id="admin-email-pending">
                <i class="bi bi-hourglass-split"></i> Email change to
                <strong><?= $adminEmailPending['pending_email'] === '' ? '(removal)' : sanitize($adminEmailPending['pending_email']) ?></strong>
                is waiting for confirmation from the <strong><?= $adminEmailPending['stage'] === 'old' ? 'current' : 'new' ?></strong> address.
                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="btn-admin-email-cancel">Cancel change</button>
            </div>
            <?php endif; ?>
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
                        <label class="form-label">Admin Email
                            <?php if ($adminAccount): ?><span class="badge <?= $adminEmailVerified ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $adminEmailVerified ? 'verified' : 'unverified' ?></span><?php endif; ?>
                        </label>
                        <input type="email" class="form-control bg-dark text-light border-secondary" name="admin_email" maxlength="190" autocomplete="off"
                               value="<?= sanitize($adminEmail) ?>" placeholder="<?= $adminAccount ? 'none — add one' : 'no account linked to this login' ?>" <?= $adminAccount ? '' : 'disabled' ?>>
                        <small class="settings-hint">
                            <?php if ($adminAccount): ?>
                            The address of <strong>your own account</strong> (<?= sanitize($adminAccount['username']) ?>) &mdash; account notices, the verification link and site password resets go here.
                            This is <em>not</em> the public contact address (Site Email) nor the sender (From) &mdash; both live under Contact &amp; Email.
                            Editing it starts the same two-step change a member gets: confirm from the <strong>current</strong> mailbox first, then from the new one; clear the box to remove the address.
                            <?= $adminEmailCooldown > 0 ? 'The next change is possible ' . (int)$adminEmailCooldown . ' days after the previous one.' : '' ?>
                            <?php else: ?>
                            This panel login has no account row in <code>users</code>, so there is no address to manage. Create a user with the same username on the Users page to link one.
                            <?php endif; ?>
                        </small>
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

            <!-- Two-factor authentication -->
            <div class="settings-section" id="section-2fa" data-group="credentials" data-title="Two-factor authentication">
                <h5>Two-factor authentication</h5>
                <small class="settings-hint d-block mb-3">A six-digit code from an authenticator app (TOTP, RFC&nbsp;6238 &mdash; Google Authenticator, Aegis, 1Password, anything) on top of the password. <strong>Off by default.</strong> The secret lives in <code>config/admin_2fa.json</code>, beside the password hash and away from the settings table that every backup dumps. Turning it <em>off</em> needs the password <strong>and</strong> a current code: this exists for the case where somebody else has the password, so the password alone must not be able to switch it off.</small>
                <!-- data-setting marks a setting the search should find even though this block is
                     rendered by JavaScript rather than being a form field: admin_2fa_enabled is a
                     mirror of config/admin_2fa.json and no form post may change it. -->
                <div id="tf-panel" data-setting="admin_2fa_enabled">
                    <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Reading&hellip;</div>
                </div>
            </div>

        </div><!-- /.settings-narrow -->
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-settings.js<?= assetVer('assets/js/admin-settings.js') ?>"></script>
    <!-- admin-common.js only defines window.AdminCommon (apiCall / el / showToast) and adds no globals
         of its own, so it can join this page without colliding with the inline script above. Without
         it admin-twofa.js returns immediately and the section sits on "Reading…" for ever. -->
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-twofa.js<?= assetVer('assets/js/admin-twofa.js') ?>"></script>
    <script>
    const API_BASE = document.body.dataset.apiBase;
    const CSRF = document.body.dataset.csrf || '';
    // the credentials block compares against these before deciding which endpoint to call
    let ADMIN_USERNAME_CURRENT = <?= json_encode((string)($cfg['admin_username'] ?? 'admin')) ?>;
    let ADMIN_EMAIL_CURRENT = <?= json_encode($adminEmail) ?>;
    let currentCaptchaAttempts = '<?= sanitize($cfg['delete_captcha_attempts'] ?? '2') ?>';
    let currentLockoutAttempts = '<?= sanitize($cfg['delete_lockout_attempts'] ?? '5') ?>';
    let currentLockoutMinutes = '<?= sanitize($cfg['delete_lockout_minutes'] ?? '60') ?>';

    // ── CAPTCHA: show only the keys of the selected provider ──
    // The other providers' fields stay in the DOM (and are still submitted, so switching back never
    // loses a key) — they are only hidden. A search hit reveals one anyway, see .settings-hit in
    // assets/css/admin.css, so looking for "turnstile" still finds its keys.
    (function () {
        const sel = document.querySelector('[name="captcha_provider"]');
        if (!sel) return;
        const cells = [...document.querySelectorAll('[data-captcha-provider]')];
        const sync = () => cells.forEach(c => c.classList.toggle('captcha-prov-hidden', c.dataset.captchaProvider !== sel.value));
        sel.addEventListener('change', sync);
        sync();
    })();

    // ── Sender address: local part + a domain from the allowed list, joined into the hidden field ──
    (function () {
        const local = document.getElementById('mail-from-local');
        const domain = document.getElementById('mail-from-domain');
        const hidden = document.getElementById('mail-from-email');
        if (!local || !domain || !hidden) return;
        const sync = () => {
            const l = local.value.trim().replace(/@.*$/, '');   // pasting a full address keeps the local part
            if (local.value !== l) local.value = l;
            hidden.value = l ? l + '@' + domain.value : '';
        };
        local.addEventListener('input', sync);
        local.addEventListener('change', sync);
        domain.addEventListener('change', sync);
        document.getElementById('settings-form').addEventListener('submit', sync, true);   // belt and braces
    })();

    // ── Federation peers (Settings → Federation / Cluster). All rendering via textContent —
    //    peer names/URLs/status come from the DB and, indirectly, from remote admins. ──
    (function () {
        const body = document.getElementById('fed-peers-body');
        if (!body) return;
        const alertBox = document.getElementById('fp-alert');
        const el = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (cls) n.className = cls; return n; };
        const note = (msg, cls) => { alertBox.innerHTML = ''; const d = el('div', msg, 'alert py-2 px-3 ' + (cls || 'alert-info')); d.style.display = 'block'; alertBox.appendChild(d); };
        const call = async (endpoint, bodyObj) => {
            try {
                const res = await fetch(API_BASE + endpoint, {
                    method: bodyObj === undefined ? 'GET' : 'POST',
                    headers: bodyObj === undefined ? { 'Accept': 'application/json' } : { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: bodyObj === undefined ? undefined : JSON.stringify(bodyObj),
                });
                return await res.json();
            } catch { return { error: 'Network error' }; }
        };
        async function loadPeers() {
            const json = await call('admin/fetch_fed_peers');
            body.innerHTML = '';
            if (json.error) { body.appendChild(el('tr')).appendChild(el('td', json.error, 'text-danger')).colSpan = 8; return; }
            if (!json.peers.length) {
                const td = el('td', 'No peers yet — add the first one below.', 'text-muted');
                td.colSpan = 8;
                body.appendChild(el('tr')).appendChild(td);
            }
            json.peers.forEach(p => {
                const tr = el('tr');
                tr.appendChild(el('td', p.name));
                tr.appendChild(el('td', p.base_url, 'text-break'));
                tr.appendChild(el('td', p.pull_enabled ? (p.has_bearer ? 'yes' : 'yes (no bearer!)') : 'no', p.pull_enabled && !p.has_bearer ? 'text-warning' : ''));
                tr.appendChild(el('td', p.api_client_id ? ((p.client_key_id || '?') + (p.client_enabled === 0 ? ' (disabled)' : '')) : '—', p.client_enabled === 0 ? 'text-warning' : ''));
                tr.appendChild(el('td', p.last_pull_at || 'never', 'text-muted'));
                tr.appendChild(el('td', String(p.rows_imported || 0)));
                tr.appendChild(el('td', p.last_status || '—', 'text-muted small'));
                const act = el('td');
                act.className = 'text-nowrap';
                const testBtn = el('button', 'Test', 'btn btn-sm btn-outline-info me-1');
                testBtn.type = 'button';
                testBtn.addEventListener('click', async () => {
                    testBtn.disabled = true;
                    const r = await call('admin/fed_peer_test', { id: p.id });
                    testBtn.disabled = false;
                    if (r.success) note('Peer "' + p.name + '" answered: node "' + (r.reply.node || '?') + '", export ' + (r.reply.export_enabled ? 'ON' : 'OFF') + ', ' + (r.reply.exportable_rows || 0).toLocaleString() + ' exportable rows.', 'alert-success');
                    else note('Test failed: ' + (r.error || '?'), 'alert-danger');
                });
                const togglePull = el('button', p.pull_enabled ? 'Pull off' : 'Pull on', 'btn btn-sm btn-outline-secondary me-1');
                togglePull.type = 'button';
                togglePull.addEventListener('click', async () => {
                    const r = await call('admin/fed_peer_save', { id: p.id, name: p.name, base_url: p.base_url, pull_enabled: p.pull_enabled ? 0 : 1, pull_files: p.pull_files });
                    if (r.error) note(r.error, 'alert-danger'); else loadPeers();
                });
                const bearerBtn = el('button', 'Bearer…', 'btn btn-sm btn-outline-secondary me-1');
                bearerBtn.type = 'button';
                bearerBtn.title = 'Set / replace the outbound bearer used to pull from this peer';
                bearerBtn.addEventListener('click', async () => {
                    const val = window.prompt('Bearer for pulling FROM "' + p.name + '" (key_id.secret, empty = remove):');
                    if (val === null) return;
                    const r = await call('admin/fed_peer_save', { id: p.id, name: p.name, base_url: p.base_url, pull_enabled: p.pull_enabled, pull_files: p.pull_files, bearer: val.trim() === '' ? 'CLEAR' : val.trim() });
                    if (r.error) note(r.error, 'alert-danger'); else { note('Bearer updated.', 'alert-success'); loadPeers(); }
                });
                const grantBtn = el('button', 'Grant inbound', 'btn btn-sm btn-outline-secondary me-1');
                grantBtn.type = 'button';
                grantBtn.title = 'Create the API key (scope: federation) this peer uses to pull FROM us';
                if (p.api_client_id) grantBtn.disabled = true;
                grantBtn.addEventListener('click', async () => {
                    const r = await call('admin/fed_peer_save', { id: p.id, name: p.name, base_url: p.base_url, pull_enabled: p.pull_enabled, pull_files: p.pull_files, grant_inbound: 1 });
                    if (r.error) { note(r.error, 'alert-danger'); return; }
                    if (r.inbound) note('Inbound bearer for "' + p.name + '" (copy it NOW — shown once): ' + r.inbound.bearer, 'alert-warning');
                    loadPeers();
                });
                const delBtn = el('button', 'Delete', 'btn btn-sm btn-outline-danger');
                delBtn.type = 'button';
                delBtn.addEventListener('click', async () => {
                    if (!window.confirm('Delete peer "' + p.name + '"? Its inbound API key is removed too.')) return;
                    const r = await call('admin/fed_peer_delete', { id: p.id });
                    if (r.error) note(r.error, 'alert-danger'); else loadPeers();
                });
                // Undo import: count first, then slice. A peer that has fed this node for a month
                // can own a million rows, so the browser walks it in bounded pieces instead of
                // asking MariaDB — shared with mail, the forum and the tracker — for one huge
                // statement. The endpoint offers the CLI equivalent for the genuinely large cases.
                const undoBtn = el('button', 'Undo import', 'btn btn-sm btn-outline-warning me-1');
                undoBtn.type = 'button';
                undoBtn.title = 'Return everything this peer contributed to unresolved';
                undoBtn.addEventListener('click', async () => {
                    const c = await call('admin/fed_purge', { op: 'count', peer: p.name });
                    if (c.error) { note(c.error, 'alert-danger'); return; }
                    if (!c.rows) { note('Nothing in the index came from "' + p.name + '".', 'alert-info'); return; }
                    if (!window.confirm('Undo the import from "' + p.name + '"?\n\n'
                        + c.rows.toLocaleString() + ' package(s) and ' + c.files.toLocaleString() + ' file record(s) go back to unresolved.\n'
                        + 'The hashes stay, with their local history — only the descriptions this peer supplied are removed.\n'
                        + 'They can be fetched again from the DHT, or re-imported if you keep the peer.')) return;
                    const pw = window.prompt('Admin password to confirm:');
                    if (!pw) return;
                    undoBtn.disabled = true;
                    let total = 0;
                    for (;;) {
                        const r = await call('admin/fed_purge', { op: 'run', peer: p.name, password: pw });
                        if (r.error) { note(r.error, 'alert-danger'); break; }
                        total += r.done;
                        note('Undoing "' + p.name + '": ' + total.toLocaleString() + ' done, '
                             + r.remaining.toLocaleString() + ' left…', 'alert-info');
                        if (!r.remaining || !r.done) { note(r.message, 'alert-success'); break; }
                    }
                    undoBtn.disabled = false;
                    loadPeers();
                    if (window.fedReviewReload) window.fedReviewReload();
                });
                act.append(testBtn, togglePull, bearerBtn, grantBtn, undoBtn, delBtn);
                tr.appendChild(act);
                body.appendChild(tr);
            });
            const sel = document.getElementById('fr-peer');
            if (sel) {
                const keep = sel.value;
                sel.innerHTML = '';
                sel.appendChild(el('option', 'All peers')).value = '';
                json.peers.forEach(p => { sel.appendChild(el('option', p.name)).value = p.name; });
                sel.value = keep;
            }
        }
        document.getElementById('fp-add').addEventListener('click', async () => {
            const r = await call('admin/fed_peer_save', {
                name: document.getElementById('fp-name').value.trim(),
                base_url: document.getElementById('fp-url').value.trim(),
                bearer: document.getElementById('fp-bearer').value.trim(),
                pull_enabled: document.getElementById('fp-pull').value === '1' ? 1 : 0,
                pull_files: 1,
                grant_inbound: document.getElementById('fp-grant').checked ? 1 : 0,
            });
            if (r.error) { note(r.error, 'alert-danger'); return; }
            document.getElementById('fp-name').value = '';
            document.getElementById('fp-url').value = '';
            document.getElementById('fp-bearer').value = '';
            document.getElementById('fp-grant').checked = false;
            if (r.inbound) note('Peer added. Inbound bearer (copy it NOW — shown once): ' + r.inbound.bearer, 'alert-warning');
            else note('Peer added.', 'alert-success');
            loadPeers();
        });
        loadPeers();

        /* ── the quarantine queue ──────────────────────────────────────────
         * Rendered with textContent throughout: these names came from another machine, and the one
         * place they must never become is markup. (The catalogue renders them the same way, which is
         * why review mode is about what you publish rather than about script injection.)
         */
        const card = document.getElementById('fed-review-card');
        const rbody = document.getElementById('fed-review-body');
        const ralert = document.getElementById('fr-alert');
        const rnote = (msg, cls) => { ralert.innerHTML = ''; const d = el('div', msg, 'alert py-2 px-3 ' + (cls || 'alert-info')); d.style.display = 'block'; ralert.appendChild(d); };
        const bytes = (n) => {
            if (n === null || n === undefined) return '—';
            const u = ['B', 'KB', 'MB', 'GB', 'TB'];
            let i = 0, v = Number(n);
            while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
            return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + u[i];
        };
        function selectedIds() {
            return Array.from(rbody.querySelectorAll('input.fr-pick:checked')).map(c => parseInt(c.value, 10));
        }
        function syncButtons() {
            const n = selectedIds().length;
            document.getElementById('fr-accept-sel').disabled = !n;
            document.getElementById('fr-reject-sel').disabled = !n;
            document.getElementById('fr-accept-peer').disabled = !document.getElementById('fr-peer').value;
        }
        async function loadReview() {
            const peer = document.getElementById('fr-peer').value;
            const state = document.getElementById('fr-state').value;
            const r = await call('admin/fed_review', { op: 'list', peer: peer, state: state, limit: 200 });
            if (r.error) { rnote(r.error, 'alert-danger'); return; }
            const pending = (r.counts && r.counts.pending) || 0;
            const rejected = (r.counts && r.counts.rejected) || 0;
            document.getElementById('fr-count').textContent = pending.toLocaleString();
            // Visible when there is something to decide, or when review mode is on and the operator
            // is entitled to see that the queue is empty.
            const reviewOn = (document.querySelector('[name="fed_import_mode"]') || {}).value === 'review';
            card.classList.toggle('d-hidden', !(pending || rejected || reviewOn));
            rbody.innerHTML = '';
            if (!r.rows.length) {
                const td = el('td', state === 'rejected' ? 'Nothing has been rejected.' : 'Nothing is waiting.', 'text-muted');
                td.colSpan = 7;
                rbody.appendChild(el('tr')).appendChild(td);
                syncButtons();
                return;
            }
            r.rows.forEach(row => {
                const tr = el('tr');
                const pick = document.createElement('input');
                pick.type = 'checkbox'; pick.className = 'form-check-input fr-pick'; pick.value = String(row.id);
                pick.addEventListener('change', syncButtons);
                tr.appendChild(el('td')).appendChild(pick);
                const nameTd = el('td', row.name || '(no name)', 'text-break');
                nameTd.title = row.info_hash;
                tr.appendChild(nameTd);
                tr.appendChild(el('td', bytes(row.total_size), 'text-nowrap'));
                tr.appendChild(el('td', (row.files_count || 0).toLocaleString() + (row.files_truncated ? ' (list capped)' : ''), 'text-nowrap'));
                tr.appendChild(el('td', row.peer_name, 'text-muted'));
                tr.appendChild(el('td', row.origin_at || '—', 'text-muted small text-nowrap'));
                const act = el('td', undefined, 'text-nowrap');
                if (state === 'pending') {
                    const yes = el('button', 'Accept', 'btn btn-sm btn-outline-success me-1');
                    yes.type = 'button';
                    yes.addEventListener('click', () => decide('accept', [row.id]));
                    const no = el('button', 'Reject', 'btn btn-sm btn-outline-danger');
                    no.type = 'button';
                    no.addEventListener('click', () => decide('reject', [row.id]));
                    act.append(yes, no);
                } else {
                    const un = el('button', 'Allow again', 'btn btn-sm btn-outline-secondary');
                    un.type = 'button';
                    un.addEventListener('click', () => decide('unreject', [row.id]));
                    act.appendChild(un);
                }
                tr.appendChild(act);
                rbody.appendChild(tr);
            });
            syncButtons();
        }
        async function decide(op, ids, peer, password) {
            const r = await call('admin/fed_review', { op: op, ids: ids || [], peer: peer || '', password: password || '' });
            if (r.error) { rnote(r.error, 'alert-danger'); return; }
            rnote(r.message, 'alert-success');
            loadReview();
        }
        window.fedReviewReload = loadReview;
        document.getElementById('fr-refresh').addEventListener('click', loadReview);
        document.getElementById('fr-peer').addEventListener('change', loadReview);
        document.getElementById('fr-state').addEventListener('change', loadReview);
        document.getElementById('fr-all').addEventListener('change', (e) => {
            rbody.querySelectorAll('input.fr-pick').forEach(c => { c.checked = e.target.checked; });
            syncButtons();
        });
        document.getElementById('fr-accept-sel').addEventListener('click', () => decide('accept', selectedIds()));
        document.getElementById('fr-reject-sel').addEventListener('click', () => decide('reject', selectedIds()));
        document.getElementById('fr-accept-peer').addEventListener('click', () => {
            const peer = document.getElementById('fr-peer').value;
            if (!peer) return;
            if (!window.confirm('Accept EVERYTHING still waiting from "' + peer + '"?\n\n'
                + 'Every package it sent becomes visible in the catalogue and in search, including the ones nobody has read.')) return;
            const pw = window.prompt('Admin password to confirm:');
            if (!pw) return;
            decide('accept', [], peer, pw);
        });
        const modeSel = document.querySelector('[name="fed_import_mode"]');
        if (modeSel) modeSel.addEventListener('change', loadReview);
        loadReview();
    })();

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

    document.getElementById('btn-test-whitelist').addEventListener('click', async () => {
        const el = document.getElementById('whitelist-result');
        const pathInput = document.querySelector('input[name="whitelist_path"]');
        const pathVal = pathInput ? pathInput.value.trim() : '';
        el.innerHTML = '<span class="text-info">Testing...</span>';
        try {
            const res = await fetch(API_BASE + 'admin/check_whitelist_path', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ whitelist_path: pathVal })
            });
            const json = await res.json();
            const sug = (json.suggestions || []).map(esc);
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; Directory is writable and the file is readable.</span>' +
                    (sug.length ? '<br><small style="color: #a0a0b0;">' + sug.join('<br>') + '</small>' : '') +
                    (json.file ? '<br><small style="color:#a0a0b0;">File: ' + (json.file.exists ? esc(String(json.file.lines)) + ' lines, ' + esc(String(json.file.size)) + ' B, mode ' + esc(json.file.mode || '') + ', owner ' + esc(json.file.owner || '') : 'does not exist yet') + '</small>' : '');
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || ['Test failed']).map(esc).join('<br>') + '</span>' +
                    (sug.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') +
                    '<br><small style="color: #a0a0b0;">OS: ' + esc(json.os || '') + ' | PHP user: ' + esc(json.php_user || '') + '</small>';
            }
        } catch {
            el.innerHTML = '<span class="text-danger">Network error</span>';
        }
    });

    // --- OpenTracker restart/reload permission test -------------------------
    // Calls the read-only `sudo -n -l` check on the server. It never restarts or reloads anything;
    // it only reports whether the web user is allowed to, plus copy-paste sudoers fixes on failure.
    async function runTrackerPermTest(op, btn) {
        const el = document.getElementById('tracker-perm-result');
        const label = op === 'reload' ? 'reload' : 'restart';
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...';
        el.innerHTML = '<span class="text-info">Testing ' + label + ' permission...</span>';
        try {
            const res = await fetch(API_BASE + 'admin/test_tracker_permission&op=' + encodeURIComponent(op), {
                method: 'GET',
                headers: { 'X-CSRF-Token': CSRF },
            });
            const json = await res.json();
            const suggestions = (json.suggestions || []);
            const meta = '<br><small style="color:#a0a0b0;">Command: <code>' + esc(json.command || '') + '</code>'
                + (json.output ? '<br>Output: <code>' + esc(json.output) + '</code>' : '')
                + '<br>OS: ' + esc(json.os || '') + ' | PHP user: ' + esc(json.php_user || '') + '</small>';
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; The web user can ' + label + ' the tracker.</span>'
                    + (suggestions.length ? '<br><small style="color:#a0a0b0;">' + suggestions.map(esc).join('<br>') + '</small>' : '')
                    + meta;
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || ['Permission test failed']).map(esc).join('<br>') + '</span>'
                    + (suggestions.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + suggestions.map(esc).join('<br>') + '</small>' : '')
                    + meta;
            }
        } catch {
            el.innerHTML = '<span class="text-danger">Network error</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    document.getElementById('btn-test-restart').addEventListener('click', (e) => runTrackerPermTest('restart', e.currentTarget));
    document.getElementById('btn-test-reload').addEventListener('click', (e) => runTrackerPermTest('reload', e.currentTarget));

    // --- Backups: schedule editor, item checkboxes, directory test -----------
    // The schedule is a set of weekday checkboxes plus a time; the form submits it as the same JSON
    // the server parses ({"days":[…],"time":"HH:MM"}), so there is one representation, not two.
    (function () {
        const dayChecks = [...document.querySelectorAll('.bk-day-check')];
        const timeEl = document.getElementById('bk-sched-time');
        const jsonEl = document.getElementById('backup-schedule-json');
        const summary = document.getElementById('bk-sched-summary');
        const tzEl = document.getElementById('backup-tz');
        if (!dayChecks.length || !timeEl || !jsonEl) return;
        const LABELS = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };
        function sync() {
            const days = dayChecks.filter(c => c.checked).map(c => c.value);
            const time = /^\d{2}:\d{2}$/.test(timeEl.value) ? timeEl.value : '04:00';
            jsonEl.value = days.length ? JSON.stringify({ days: days, time: time }) : '';
            if (summary) {
                summary.textContent = days.length
                    ? (days.length === 7 ? 'Every day' : days.map(d => LABELS[d]).join(', ')) + ' at ' + time + ' (' + (tzEl ? tzEl.value : '') + ')'
                    : 'No automatic backups — the schedule is empty.';
            }
        }
        dayChecks.forEach(c => c.addEventListener('change', sync));
        timeEl.addEventListener('input', sync);
        timeEl.addEventListener('change', sync);
        if (tzEl) tzEl.addEventListener('change', sync);
        document.getElementById('settings-form').addEventListener('submit', sync, true);
        sync();
    })();

    // The custom item checkboxes carry no name, so mirror them into the hidden field the form posts.
    (function () {
        const checks = [...document.querySelectorAll('.bk-item-check')];
        const hidden = document.getElementById('backup-items-json');
        const profile = document.getElementById('backup-profile');
        const cell = document.getElementById('backup-items-cell');
        if (!checks.length || !hidden) return;
        const sync = () => { hidden.value = checks.filter(c => c.checked).map(c => c.value).join(','); };
        const dim = () => { if (cell && profile) cell.classList.toggle('bk-items-dim', profile.value !== 'custom'); };
        checks.forEach(c => c.addEventListener('change', sync));
        if (profile) profile.addEventListener('change', dim);
        document.getElementById('settings-form').addEventListener('submit', sync, true);
        sync(); dim();
    })();

    document.getElementById('btn-test-backup-dir').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const el = document.getElementById('backup-dir-result');
        const dir = (document.querySelector('[name="backup_dir"]') || {}).value || '';
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...';
        el.innerHTML = '<span class="text-info">Checking the directory and the tooling&hellip;</span>';
        try {
            const res = await fetch(API_BASE + 'admin/backup_test_path', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ backup_dir: dir.trim() }),
            });
            const json = await res.json();
            const sug = (json.suggestions || []).map(esc);
            const c = json.check || {};
            const facts = [];
            if (json.exists) {
                facts.push('mode ' + esc(json.mode || '?') + ', owner ' + esc(json.owner || '?'));
                if (json.archives !== undefined) facts.push(esc(String(json.archives)) + ' archive(s)');
            } else { facts.push('does not exist yet'); }
            if (json.free_bytes) facts.push(Math.round(json.free_bytes / 1073741824) + ' GiB free');
            if (c.mode) facts.push('mode: ' + esc(c.mode === 'script' ? 'full (Backup-serwera.sh)' : 'built-in (database only)'));
            const meta = '<br><small style="color:#a0a0b0;">' + facts.join(' | ')
                + '<br>OS: ' + esc(json.os || '') + ' | PHP user: ' + esc(json.php_user || '') + '</small>';
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; The directory works and the panel can make a backup here.</span>'
                    + (sug.length ? '<br><small style="color:#a0a0b0;white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || ['Test failed']).map(esc).join('<br>') + '</span>'
                    + (sug.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            }
        } catch {
            el.innerHTML = '<span class="text-danger">Network error</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    // --- Inbound UDP rate limit: availability test ---------------------------
    // Read-only on the server too (see api/admin/net_test.php): it checks nft, the sudoers rule and
    // the reboot-persistence include, and never loads or removes a rule.
    document.getElementById('btn-test-netlimit').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const el = document.getElementById('netlimit-result');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...';
        el.innerHTML = '<span class="text-info">Checking the firewall helper&hellip;</span>';
        try {
            const res = await fetch(API_BASE + 'admin/net_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const json = await res.json();
            const sug = (json.suggestions || []).map(esc);
            const meta = '<br><small style="color:#a0a0b0;">'
                + (json.command ? 'Command: <code>' + esc(json.command) + '</code><br>' : '')
                + (json.output ? 'Output: <code>' + esc(json.output) + '</code><br>' : '')
                + 'OS: ' + esc(json.os || '') + ' | PHP user: ' + esc(json.php_user || '')
                + (json.cpus ? ' | CPU cores: ' + esc(String(json.cpus)) : '') + '</small>';
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; The panel can load and remove the inbound limit.</span>'
                    + (sug.length ? '<br><small style="color:#a0a0b0;white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || ['Test failed']).map(esc).join('<br>') + '</span>'
                    + (sug.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            }
        } catch {
            el.innerHTML = '<span class="text-danger">Network error</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    // Same shape as the firewall's Test: say what works, and when it does not, say exactly what is
    // missing rather than a generic failure.
    document.getElementById('btn-ot-test')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const box = document.getElementById('ot-test-result');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...';
        box.innerHTML = '<span class="text-info">Asking the helper&hellip;</span>';
        try {
            const res = await fetch(API_BASE + 'admin/ot_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const j = await res.json();
            const meta = '<br><small style="color:#a0a0b0;">'
                + (j.unit ? 'Unit: <code>' + esc(j.unit) + '</code> | ' : '')
                + (j.cpus ? 'cores: ' + esc(String(j.cpus)) + ' | ' : '')
                + 'drop-in dir: <code>' + esc(j.dropin_dir || '?') + '</code>'
                + (j.dropin_writable === false ? ' <span class="text-warning">(read-only for this process)</span>' : '')
                + '</small>';
            if (j.ok) {
                box.innerHTML = '<span class="text-success">&#10003; The panel can read the unit and write its own drop-in.</span>'
                    + (j.hint ? '<br><small style="color:#a0a0b0;white-space:pre-wrap;">' + esc(j.hint) + '</small>' : '') + meta;
            } else {
                box.innerHTML = '<span class="text-danger">&#10007; ' + esc(j.error || 'Test failed') + '</span>'
                    + (j.hint ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + esc(j.hint) + '</small>' : '') + meta;
            }
        } catch {
            box.innerHTML = '<span class="text-danger">Network error</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    // The instances Test button. Shares the renderer with the kernel-buffer one because the two
    // endpoints answer in the same shape, and the interesting half is the same: some checks are
    // expected to be false and are rendered as information rather than as failures.
    document.getElementById('btn-cluster-test')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const box = document.getElementById('cluster-test-result');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...';
        box.innerHTML = '<span class="text-info">Asking the helper&hellip;</span>';
        try {
            const res = await fetch(API_BASE + 'admin/ot_cluster_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const j = await res.json();
            let html = j.ok
                ? '<span class="text-success">&#10003; The panel can manage extra instances from here.</span>'
                : '<span class="text-danger">&#10007; Something on the path is missing.</span>';
            html += '<ul style="margin:.4rem 0 0 1rem;padding:0;list-style:none;font-size:.85rem;">';
            (j.checks || []).forEach(c => {
                const mark = c.ok ? '<span class="text-success">&#10003;</span>'
                                  : (c.info ? '<span style="color:#a0a0b0;">&#8226;</span>' : '<span class="text-danger">&#10007;</span>');
                html += '<li>' + mark + ' ' + esc(c.name)
                     + (c.detail ? ' <small style="color:#a0a0b0;">&mdash; ' + esc(c.detail) + '</small>' : '') + '</li>';
            });
            html += '</ul>';
            (j.errors || []).forEach(x => { html += '<div class="text-warning" style="font-size:.85rem;">' + esc(x) + '</div>'; });
            if ((j.suggestions || []).length) {
                html += '<pre class="nl-preview mt-2" style="white-space:pre-wrap;">' + esc(j.suggestions.join(String.fromCharCode(10))) + '</pre>';
            }
            box.innerHTML = html;
        } catch {
            box.innerHTML = '<span class="text-danger">Network error</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    // The kernel-buffer Test button. Read-only in every branch: it establishes whether the path from
    // the panel to the kernel exists, and never uses it. Two of its checks are EXPECTED to be false on
    // a hardened box (php-fpm cannot write /proc/sys or /etc), so they are rendered as information
    // rather than as failures — otherwise a correctly configured machine would report itself broken.
    document.getElementById('btn-sysctl-test')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const box = document.getElementById('sysctl-test-result');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...';
        box.innerHTML = '<span class="text-info">Asking the helper&hellip;</span>';
        try {
            const res = await fetch(API_BASE + 'admin/sysctl_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const j = await res.json();
            let html = j.ok
                ? '<span class="text-success">&#10003; The panel can reach the kernel-buffer helper.</span>'
                : '<span class="text-danger">&#10007; Something on the path is missing.</span>';
            html += '<ul style="margin:.4rem 0 0 1rem;padding:0;list-style:none;font-size:.85rem;">';
            (j.checks || []).forEach(c => {
                const mark = c.ok ? '<span class="text-success">&#10003;</span>'
                                  : (c.info ? '<span style="color:#a0a0b0;">&#8226;</span>' : '<span class="text-danger">&#10007;</span>');
                html += '<li>' + mark + ' ' + esc(c.name)
                     + (c.detail ? ' <small style="color:#a0a0b0;">&mdash; ' + esc(c.detail) + '</small>' : '') + '</li>';
            });
            html += '</ul>';
            (j.errors || []).forEach(x => { html += '<div class="text-warning" style="font-size:.85rem;">' + esc(x) + '</div>'; });
            if ((j.suggestions || []).length) {
                html += '<pre class="nl-preview mt-2" style="white-space:pre-wrap;">' + esc(j.suggestions.join(String.fromCharCode(10))) + '</pre>';
            }
            box.innerHTML = html;
        } catch {
            box.innerHTML = '<span class="text-danger">Network error</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    document.getElementById('btn-logout').addEventListener('click', async () => {
        await fetch(API_BASE + 'admin/logout', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
        // back to the configured sign-in address — a reload would land on the public front page
        window.location.href = API_BASE.replace('api.php?endpoint=', '') + '?action=' + (document.body.dataset.loginPath || 'admin');
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

    // Scheduled mode editor: 7 weekday rows → JSON in the hidden tracker_schedule input.
    // {"mon":"all"|"none"|{"from":"HH:MM","to":"HH:MM"}, ...}; time inputs only apply to "window".
    const schedRows = Array.from(document.querySelectorAll('#sched-table tr[data-sched-day]'));
    function schedSyncRow(row) {
        const kind = row.querySelector('[data-sched-kind]').value;
        const from = row.querySelector('[data-sched-from]');
        const to = row.querySelector('[data-sched-to]');
        const note = row.querySelector('[data-sched-note]');
        from.disabled = to.disabled = (kind !== 'window');
        if (kind === 'window' && from.value && to.value) {
            note.textContent = to.value <= from.value ? 'ends next day at ' + to.value : 'same day';
        } else if (kind === 'window') {
            note.textContent = 'set both times';
        } else {
            note.textContent = kind === 'all' ? 'whitelist 00:00–24:00' : 'open (blacklist) mode';
        }
    }
    function collectSchedule() {
        const out = {};
        schedRows.forEach(row => {
            const kind = row.querySelector('[data-sched-kind]').value;
            if (kind === 'window') {
                out[row.dataset.schedDay] = {
                    from: row.querySelector('[data-sched-from]').value || '00:00',
                    to: row.querySelector('[data-sched-to]').value || '00:00',
                };
            } else {
                out[row.dataset.schedDay] = kind === 'all' ? 'all' : 'none';
            }
        });
        const json = JSON.stringify(out);
        const hidden = document.getElementById('sched-json');
        if (hidden) hidden.value = json;
        return json;
    }
    schedRows.forEach(row => {
        schedSyncRow(row);
        row.querySelectorAll('select, input').forEach(i => i.addEventListener('change', () => { schedSyncRow(row); collectSchedule(); }));
        row.querySelectorAll('input').forEach(i => i.addEventListener('input', () => schedSyncRow(row)));
    });

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
                // keep the Logout target in sync when the sign-in address was just changed
                if (json.applied && json.applied.admin_login_path) document.body.dataset.loginPath = json.applied.admin_login_path;
                // A save can succeed and still leave the machine disagreeing with what was saved --
                // switching the tracker mode writes a row, it does not move the symlinks. That is a
                // warning, not an error, and it must not be dressed up as a success and forgotten.
                if (json.warning) showToast('error', json.warning);
                else showToast('success', 'Settings saved successfully.');
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
        if (schedRows.length) data.tracker_schedule = collectSchedule();
        // timeline range buttons: checkboxes carry no name, so FormData skips them
        const tlChecks = document.querySelectorAll('.tl-range-check');
        if (tlChecks.length) data.stats_timeline_ranges = Array.from(tlChecks).filter(c => c.checked).map(c => c.value).join(',');

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

        // The email lives on the admin's own account row and moves through the same two-step
        // confirmation a member gets, so it goes to its own endpoint — but shares this password gate.
        const emailField = form.admin_email;
        const emailNow = (emailField && !emailField.disabled) ? emailField.value.trim() : null;
        const wantEmail = emailNow !== null && emailNow !== ADMIN_EMAIL_CURRENT;
        const wantCreds = username !== ADMIN_USERNAME_CURRENT || !!newPass;
        if (!wantCreds && !wantEmail) {
            showToast('error', 'Nothing to change.');
            return;
        }

        const post = async (endpoint, payload) => {
            const res = await fetch(API_BASE + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(payload),
            });
            return await res.json();
        };

        const saveBtn = form.querySelector('button[type="submit"]');
        const btnHtml = saveBtn ? saveBtn.innerHTML : '';
        if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving…'; }
        try {
            let ok = true;
            if (wantCreds) {
                const payload = { current_password: current, admin_username: username };
                if (newPass) payload.new_password = newPass;
                const json = await post('admin/change_password', payload);
                if (json.success) {
                    showToast('success', json.message || 'Saved successfully.');
                    ADMIN_USERNAME_CURRENT = username;
                } else {
                    ok = false;
                    showToast('error', json.error || 'Error');
                }
            }
            if (ok && wantEmail) {
                const json = await post('admin/account_email', { current_password: current, email: emailNow });
                if (json.success) {
                    showToast('success', json.message || 'Email change started.');
                    // the address only really moves once the mailboxes confirm it, so keep showing
                    // the stored one until then
                    if (json.stage === 'done_direct') ADMIN_EMAIL_CURRENT = emailNow;
                    else emailField.value = ADMIN_EMAIL_CURRENT;
                } else {
                    ok = false;
                    showToast('error', json.error || 'Error');
                    emailField.value = ADMIN_EMAIL_CURRENT;
                }
            }
            if (ok) {
                form.current_password.value = '';
                form.new_password.value = '';
                form.confirm_password.value = '';
            }
        } catch {
            showToast('error', 'Network error.');
        } finally {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = btnHtml; }
        }
    });

    // "Cancel change" on the pending-email banner (no password needed — it only undoes a request)
    document.getElementById('btn-admin-email-cancel')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        try {
            const res = await fetch(API_BASE + 'admin/account_email', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ cancel: 1 }),
            });
            const json = await res.json();
            if (json.success) {
                showToast('success', json.message || 'Cancelled.');
                document.getElementById('admin-email-pending')?.remove();
            } else {
                showToast('error', json.error || 'Error');
                btn.disabled = false;
            }
        } catch {
            showToast('error', 'Network error.');
            btn.disabled = false;
        }
    });
    </script>
</body>
</html>
