<?php require_once __DIR__ . '/../../includes/settings_catalog.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('settings.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
</head>
<body class="admin-body admin-hc" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide">
        <div class="admin-header">
            <h2><i class="bi bi-gear"></i> <?= _h('settings.title') ?></h2>
            <?php $current = 'settings'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <!-- Sub-menu + search. The visible labels/hints below are indexed by the browser; the hidden
             synonyms come from api.php?endpoint=admin/settings_catalog and are never printed here. -->
        <div class="settings-toolbar" id="settings-toolbar">
            <div class="settings-search-row">
                <span class="tb-lang" id="tb-lang" aria-hidden="true"></span>
                <div class="settings-search-box">
                    <i class="bi bi-search settings-search-icon"></i>
                    <?php // readonly until the first focus keeps Chrome's PROFILE autofill away; what still offered a saved
      // e-mail here was the PASSWORD manager, which pairs the nearest text box before a password field as
      // the username. The credentials form below now names its own username field (autocomplete="username")
      // and its password roles, so this box is no longer the candidate. data-* attributes tell the other
      // managers the same. admin-settings.js drops readonly on focus. ?>
                    <input type="search" id="settings-search" name="settings-q" class="form-control bg-dark text-light border-secondary" placeholder="<?= _h('settings.search_ph') ?>" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore data-form-type="other" autocapitalize="off" spellcheck="false" readonly data-unlock-on-focus="1" aria-label="<?= _h('settings.search_aria') ?>" aria-describedby="settings-search-count">
                    <?php // The language switcher's understudy: a copy of the header's switcher that fades in here
                          // when the header has scrolled away (admin-settings.js clones it and watches the header).
                          // Settings is the one page long enough for the header to be a scroll away. ?>
                    <button type="button" class="settings-search-clear d-hidden" id="settings-search-clear" title="<?= _h('settings.search_clear') ?>" aria-label="<?= _h('settings.search_clear') ?>"><i class="bi bi-x-lg"></i></button>
                </div>
                <span class="settings-search-count" id="settings-search-count" role="status" aria-live="polite"></span>
            </div>
            <div class="settings-groups" id="settings-groups" role="group" aria-label="<?= _h('settings.groups_aria') ?>">
                <button type="button" class="settings-group-btn active" data-group="all" aria-pressed="true"><i class="bi bi-ui-checks-grid"></i> <?= _h('settings.groups_all') ?></button>
                <?php foreach (settingsCatalogGroups() as $g): ?>
                <button type="button" class="settings-group-btn" data-group="<?= sanitize($g['id']) ?>" aria-pressed="false"><i class="bi <?= sanitize($g['icon']) ?>"></i> <?= sanitize(settingsGroupTitle($g)) ?><span class="settings-group-count" data-count-for="<?= sanitize($g['id']) ?>"></span></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="settings-empty d-hidden" id="settings-empty">
            <i class="bi bi-search"></i> <?= _h('settings.search_no_match') ?> <strong id="settings-empty-q"></strong>.
            <span class="settings-hint"><?= __('settings.search_no_match_hint') ?></span>
        </div>

        <form id="settings-form">
            <!-- Site Configuration -->
            <div class="settings-section" id="section-site" data-group="general" data-title="<?= _h('settings.site_heading') ?>">
                <h5><?= _h('settings.site_heading') ?></h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.site_name') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="site_name" value="<?= sanitize($cfg['site_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.site_url') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="site_url" value="<?= sanitize($cfg['site_url'] ?? '') ?>" placeholder="https://example.com/">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.site_announce_https') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="announce_url_https" value="<?= sanitize($cfg['announce_url_https'] ?? '') ?>" placeholder="https://tracker.example.com:443/announce">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.site_announce_udp') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="announce_url" value="<?= sanitize($cfg['announce_url'] ?? '') ?>" placeholder="udp://tracker.example.com:6969/announce">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.site_github_url') ?> <small class="settings-hint"><?= _h('settings.site_github_url_sub') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="github_url" value="<?= sanitize($cfg['github_url'] ?? '') ?>" placeholder="https://github.com/YourOrg/your-tracker">
                        <small class="settings-hint"><?= __('settings.site_github_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- Contact & Email -->
            <div class="settings-section" id="section-mail" data-group="mail" data-title="<?= _h('settings.mail_heading') ?>">
                <h5><?= _h('settings.mail_heading') ?></h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.mail_site_email') ?> <small class="settings-hint"><?= _h('settings.mail_site_email_sub') ?></small></label>
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
                        <label class="form-label"><?= _h('settings.mail_from') ?> <small class="settings-hint"><?= _h('settings.mail_from_sub') ?></small></label>
                        <?php if ($mfDomains): ?>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="mail-from-local" value="<?= sanitize($mfLocal) ?>" placeholder="<?= _h('settings.mail_local_ph') ?>" maxlength="64" autocomplete="off" spellcheck="false">
                            <span class="input-group-text bg-dark text-secondary border-secondary">@</span>
                            <select class="form-select bg-dark text-light border-secondary" id="mail-from-domain" style="max-width:16rem;">
                                <?php foreach ($mfDomains as $d): ?>
                                <option value="<?= sanitize($d) ?>" <?= $d === $mfDomain ? 'selected' : '' ?>><?= sanitize($d) ?><?= in_array($d, $mfah, true) ? '' : ' ' . _h('settings.mail_domain_not_allowed') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="mail_from_email" id="mail-from-email" value="<?= sanitize($mfCur) ?>">
                        <small class="settings-hint"><?= _h('settings.mail_from_hint') ?></small>
                        <?php else: ?>
                        <input type="email" class="form-control bg-dark text-light border-secondary" name="mail_from_email" value="<?= sanitize($mfCur) ?>" placeholder="noreply@example.com">
                        <small class="settings-hint"><?= __('settings.mail_from_hint_nodomain') ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.mail_show_contact') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="contact_visible">
                            <option value="1" <?= ($cfg['contact_visible'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['contact_visible'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.mail_obfuscate') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="contact_obfuscate">
                            <option value="1" <?= ($cfg['contact_obfuscate'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['contact_obfuscate'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?= _h('settings.mail_hmac') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="hmac_secret" value="<?= sanitize($cfg['hmac_secret'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- CAPTCHA (reCAPTCHA v2 / reCAPTCHA v3 / Cloudflare Turnstile) -->
            <?php $captchaProviderSel = captchaProvider($cfg); ?>
            <div class="settings-section" id="section-captcha" data-group="security" data-title="<?= _h('settings.captcha_heading') ?>">
                <h5><?= _h('settings.captcha_heading') ?></h5>
                <p class="settings-hint mb-2"><?= _h('settings.captcha_intro') ?></p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_enable') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_enabled">
                            <option value="1" <?= ($cfg['recaptcha_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['recaptcha_enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_provider') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="captcha_provider">
                            <option value="recaptcha" <?= $captchaProviderSel === 'recaptcha' ? 'selected' : '' ?>><?= _h('settings.captcha_provider_recaptcha') ?></option>
                            <option value="recaptcha_v3" <?= $captchaProviderSel === 'recaptcha_v3' ? 'selected' : '' ?>><?= _h('settings.captcha_provider_recaptcha_v3') ?></option>
                            <option value="turnstile" <?= $captchaProviderSel === 'turnstile' ? 'selected' : '' ?>>Cloudflare Turnstile</option>
                            <option value="hcaptcha" <?= $captchaProviderSel === 'hcaptcha' ? 'selected' : '' ?>><?= _h('settings.captcha_provider_hcaptcha') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4"></div>
                    <div class="col-md-6" data-captcha-provider="recaptcha">
                        <label class="form-label"><?= _h('settings.captcha_recaptcha_site_key') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="recaptcha_site_key" value="<?= sanitize($cfg['recaptcha_site_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-6" data-captcha-provider="recaptcha">
                        <label class="form-label"><?= _h('settings.captcha_recaptcha_secret') ?></label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="recaptcha_secret" value="<?= sanitize($cfg['recaptcha_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-4" data-captcha-provider="recaptcha_v3">
                        <label class="form-label"><?= _h('settings.captcha_recaptcha_v3_site_key') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="recaptcha_v3_site_key" value="<?= sanitize($cfg['recaptcha_v3_site_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-4" data-captcha-provider="recaptcha_v3">
                        <label class="form-label"><?= _h('settings.captcha_recaptcha_v3_secret') ?></label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="recaptcha_v3_secret" value="<?= sanitize($cfg['recaptcha_v3_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-4" data-captcha-provider="recaptcha_v3">
                        <label class="form-label"><?= _h('settings.captcha_recaptcha_v3_min_score') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="recaptcha_v3_min_score" value="<?= sanitize($cfg['recaptcha_v3_min_score'] ?? '0.5') ?>" min="0" max="1" step="0.1">
                        <small class="settings-hint"><?= _h('settings.captcha_recaptcha_v3_score_hint') ?></small>
                    </div>
                    <div class="col-md-6" data-captcha-provider="turnstile">
                        <label class="form-label"><?= _h('settings.captcha_turnstile_site_key') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="turnstile_site_key" value="<?= sanitize($cfg['turnstile_site_key'] ?? '') ?>" placeholder="0x4AAAAAAA...">
                    </div>
                    <div class="col-md-6" data-captcha-provider="turnstile">
                        <label class="form-label"><?= _h('settings.captcha_turnstile_secret') ?></label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="turnstile_secret" value="<?= sanitize($cfg['turnstile_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-6" data-captcha-provider="hcaptcha">
                        <label class="form-label"><?= _h('settings.captcha_hcaptcha_site_key') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="hcaptcha_site_key" value="<?= sanitize($cfg['hcaptcha_site_key'] ?? '') ?>" placeholder="00000000-0000-0000-0000-000000000000">
                    </div>
                    <div class="col-md-6" data-captcha-provider="hcaptcha">
                        <label class="form-label"><?= _h('settings.captcha_hcaptcha_secret') ?></label>
                        <input type="password" autocomplete="off" class="form-control bg-dark text-light border-secondary" name="hcaptcha_secret" value="<?= sanitize($cfg['hcaptcha_secret'] ?? '') ?>" placeholder="ES_...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_on_report') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_report">
                            <option value="1" <?= ($cfg['recaptcha_on_report'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['recaptcha_on_report'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_on_login') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_login">
                            <option value="1" <?= ($cfg['recaptcha_on_login'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['recaptcha_on_login'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_on_status') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_status">
                            <option value="1" <?= ($cfg['recaptcha_on_status'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['recaptcha_on_status'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_on_appeal') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_appeal">
                            <option value="1" <?= ($cfg['recaptcha_on_appeal'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['recaptcha_on_appeal'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_on_block_check') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="recaptcha_on_block_check">
                            <option value="1" <?= ($cfg['recaptcha_on_block_check'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.yes') ?></option>
                            <option value="0" <?= ($cfg['recaptcha_on_block_check'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.no') ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Smart CAPTCHA -->
            <div class="settings-section" id="section-captcha-smart" data-group="security" data-title="<?= _h('settings.captcha_smart_heading') ?>">
                <h5><?= _h('settings.captcha_smart_heading') ?></h5>
                <small class="settings-hint d-block mb-3"><?= _h('settings.captcha_smart_intro') ?></small>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_smart_threshold') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_threshold" value="<?= sanitize($cfg['captcha_threshold'] ?? '6') ?>" min="1" max="100">
                        <small class="settings-hint"><?= _h('settings.captcha_smart_threshold_hint') ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_smart_grace') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_grace_minutes" value="<?= sanitize($cfg['captcha_grace_minutes'] ?? '5') ?>" min="0" max="60">
                        <small class="settings-hint"><?= _h('settings.captcha_smart_grace_hint') ?></small>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info"><?= _h('settings.captcha_smart_points_title') ?></small></div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.captcha_smart_pts_report') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_report" value="<?= sanitize($cfg['captcha_pts_report'] ?? '2') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.captcha_smart_pts_status') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_status" value="<?= sanitize($cfg['captcha_pts_status'] ?? '1') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.captcha_smart_pts_block_check') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_block_check" value="<?= sanitize($cfg['captcha_pts_block_check'] ?? '1') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.captcha_smart_pts_appeal') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_appeal" value="<?= sanitize($cfg['captcha_pts_appeal'] ?? '3') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.captcha_smart_pts_login_fail') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_login_fail" value="<?= sanitize($cfg['captcha_pts_login_fail'] ?? '6') ?>" min="0" max="100">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info"><?= _h('settings.captcha_smart_delete_title') ?></small></div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_smart_delete_attempts') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="delete_captcha_attempts" value="<?= sanitize($cfg['delete_captcha_attempts'] ?? '2') ?>" min="1" max="50">
                        <small class="settings-hint"><?= _h('settings.captcha_smart_delete_attempts_hint') ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_smart_lockout_attempts') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="delete_lockout_attempts" value="<?= sanitize($cfg['delete_lockout_attempts'] ?? '5') ?>" min="1" max="50">
                        <small class="settings-hint"><?= _h('settings.captcha_smart_lockout_attempts_hint') ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.captcha_smart_lockout_minutes') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="delete_lockout_minutes" value="<?= sanitize($cfg['delete_lockout_minutes'] ?? '60') ?>" min="1" max="1440">
                        <small class="settings-hint"><?= _h('settings.captcha_smart_lockout_minutes_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- Public Pages -->
            <div class="settings-section" id="section-public-pages" data-group="general" data-title="<?= _h('settings.pages_heading') ?>">
                <h5><?= _h('settings.pages_heading') ?></h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.pages_archive_reports') ?> <small class="settings-hint"><?= _h('settings.pages_zero_disabled') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="auto_archive_days" value="<?= sanitize($cfg['auto_archive_days'] ?? '90') ?>" min="0" max="9999">
                        <small class="settings-hint"><?= _h('settings.pages_archive_reports_hint') ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.pages_archive_appeals') ?> <small class="settings-hint"><?= _h('settings.pages_zero_disabled') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="auto_archive_appeal_days" value="<?= sanitize($cfg['auto_archive_appeal_days'] ?? '90') ?>" min="0" max="9999">
                        <small class="settings-hint"><?= _h('settings.pages_archive_appeals_hint') ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.pages_email_log') ?> <small class="settings-hint"><?= _h('settings.pages_email_log_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="sent_emails_retention_days" value="<?= sanitize($cfg['sent_emails_retention_days'] ?? '0') ?>" min="0" max="9999">
                        <small class="settings-hint"><?= _h('settings.pages_email_log_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- Tracker mode & whitelist -->
            <div class="settings-section" id="section-reputation" data-group="content" data-title="<?= _h('settings.rep_heading') ?>">
                <h5><i class="bi bi-hand-thumbs-up"></i> <?= _h('settings.rep_heading') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.rep_intro_1') ?>
                <br><br><?= __('settings.rep_intro_2') ?>
                <br><br><?= __('settings.rep_intro_3') ?></small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="rep_enabled">
                        <label class="form-label"><?= _h('settings.rep_heading') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_enabled">
                            <option value="0" <?= ($cfg['rep_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.rep_off') ?></option>
                            <option value="1" <?= ($cfg['rep_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.rep_on') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.rep_enabled_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="rep_mode">
                        <label class="form-label"><?= _h('settings.rep_mode') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_mode">
                            <option value="thumbs" <?= repMode($cfg) === 'thumbs' ? 'selected' : '' ?>><?= _h('settings.rep_mode_thumbs') ?></option>
                            <option value="stars" <?= repMode($cfg) === 'stars' ? 'selected' : '' ?>><?= _h('settings.rep_mode_stars') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.rep_mode_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="rep_who_can_vote">
                        <label class="form-label"><?= _h('settings.rep_who') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_who_can_vote">
                            <option value="off" <?= ($cfg['rep_who_can_vote'] ?? 'users') === 'off' ? 'selected' : '' ?>><?= _h('settings.rep_who_off') ?></option>
                            <option value="users" <?= ($cfg['rep_who_can_vote'] ?? 'users') === 'users' ? 'selected' : '' ?>><?= _h('settings.rep_who_users') ?></option>
                            <option value="all" <?= ($cfg['rep_who_can_vote'] ?? 'users') === 'all' ? 'selected' : '' ?>><?= _h('settings.rep_who_all') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.rep_who_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="rep_show_in_results">
                        <label class="form-label"><?= _h('settings.rep_show') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="rep_show_in_results">
                            <option value="0" <?= ($cfg['rep_show_in_results'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.rep_show_info') ?></option>
                            <option value="1" <?= ($cfg['rep_show_in_results'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.rep_show_column') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3" data-setting="rep_min_votes">
                        <label class="form-label"><?= _h('settings.rep_min_votes') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rep_min_votes" value="<?= sanitize($cfg['rep_min_votes'] ?? '3') ?>" min="1" max="1000">
                        <small class="settings-hint"><?= _h('settings.rep_min_votes_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="rep_anon_weight">
                        <label class="form-label"><?= _h('settings.rep_anon_weight') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rep_anon_weight" value="<?= sanitize($cfg['rep_anon_weight'] ?? '25') ?>" min="0" max="100">
                        <small class="settings-hint"><?= _h('settings.rep_anon_weight_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="rep_rate_per_hour">
                        <label class="form-label"><?= _h('settings.rep_rate_per_hour') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rep_rate_per_hour" value="<?= sanitize($cfg['rep_rate_per_hour'] ?? '30') ?>" min="1" max="1000">
                    </div>
                    <div class="col-md-3" data-setting="captcha_pts_vote">
                        <label class="form-label"><?= _h('settings.rep_captcha_pts_vote') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="captcha_pts_vote" value="<?= sanitize($cfg['captcha_pts_vote'] ?? '2') ?>" min="0" max="100">
                        <small class="settings-hint"><?= _h('settings.rep_captcha_pts_vote_hint') ?></small>
                    </div>
                </div>
            </div>

            <div class="settings-section" id="section-livesync" data-group="opentracker" data-title="<?= _h('settings.livesync_title') ?>">
                <h5><i class="bi bi-diagram-3"></i> <?= _h('settings.livesync_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.livesync_intro_p1') ?>
                <br><br><?= __('settings.livesync_intro_p2') ?></small>
                <div class="row g-3">
                    <div class="col-md-6" data-setting="livesync_cmd">
                        <label class="form-label"><?= _h('settings.livesync_cmd') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="livesync_cmd" value="<?= sanitize($cfg['livesync_cmd'] ?? '') ?>" maxlength="255" placeholder="<?= _h('settings.livesync_cmd_ph') ?>">
                        <small class="settings-hint"><?= __('settings.livesync_cmd_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_enabled">
                        <label class="form-label"><?= _h('settings.livesync_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="livesync_enabled">
                            <option value="0" <?= ($cfg['livesync_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.livesync_no') ?></option>
                            <option value="1" <?= ($cfg['livesync_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.livesync_yes') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.livesync_enabled_hint') ?> <a href="<?= $baseUrl ?>?action=admin-traffic#livesync-card"><?= _h('settings.livesync_enabled_hint_link') ?></a>.</small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_port">
                        <label class="form-label"><?= _h('settings.livesync_port') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="livesync_port" value="<?= sanitize($cfg['livesync_port'] ?? '9696') ?>" min="1024" max="65535">
                        <small class="settings-hint"><?= _h('settings.livesync_port_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_bind_ip">
                        <label class="form-label"><?= _h('settings.livesync_bind_ip') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="livesync_bind_ip" value="<?= sanitize($cfg['livesync_bind_ip'] ?? '') ?>" maxlength="45" placeholder="<?= _h('settings.livesync_bind_ip_ph') ?>">
                        <small class="settings-hint"><?= _h('settings.livesync_bind_ip_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="livesync_peer_ip">
                        <label class="form-label"><?= _h('settings.livesync_peer_ip') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="livesync_peer_ip" value="<?= sanitize($cfg['livesync_peer_ip'] ?? '') ?>" maxlength="45" placeholder="<?= _h('settings.livesync_peer_ip_ph') ?>">
                    </div>
                </div>
                <div class="settings-test mt-3">
                    <button type="button" class="btn btn-sm btn-outline-info settings-test-btn" data-test="admin/livesync_test" data-ok-text="<?= _h('settings.livesync_test_ok') ?>"><i class="bi bi-diagram-3"></i> <?= _h('settings.livesync_test_btn') ?></button>
                    <div class="settings-test-out"></div>
                </div>
            </div>

            <div class="settings-section" id="section-whitelist" data-group="tracker" data-title="<?= _h('settings.whitelist_title') ?>">
                <h5><?= _h('settings.whitelist_title') ?></h5>
                <p class="settings-hint mb-2">
                    <?= __('settings.whitelist_intro_black') ?>
                    <?= __('settings.whitelist_intro_white') ?>
                    <?= __('settings.whitelist_intro_match') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_tracker_mode') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_mode">
                            <option value="blacklist" <?= ($cfg['tracker_mode'] ?? 'blacklist') !== 'whitelist' ? 'selected' : '' ?>><?= _h('settings.whitelist_mode_blacklist') ?></option>
                            <option value="whitelist" <?= ($cfg['tracker_mode'] ?? '') === 'whitelist' ? 'selected' : '' ?>><?= _h('settings.whitelist_mode_whitelist') ?></option>
                        </select>
                        <?php if (function_exists('scheduleEnabled') && scheduleEnabled($cfg)): ?>
                        <small class="settings-hint text-warning d-block mt-1"><i class="bi bi-exclamation-triangle"></i> <?= __('settings.whitelist_mode_sched_warn') ?></small>
                        <?php else: ?>
                        <small class="settings-hint d-block mt-1"><?= __('settings.whitelist_mode_hint') ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label"><?= _h('settings.whitelist_path') ?> <small class="settings-hint"><?= __('settings.whitelist_path_small') ?></small></label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="whitelist_path" value="<?= sanitize($cfg['whitelist_path'] ?? '') ?>" placeholder="/home/tracker/accesslist/whitelist">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-whitelist"><?= _h('settings.whitelist_test_btn') ?></button>
                        </div>
                        <div id="whitelist-result" class="mt-1 blacklist-result"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_public_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="whitelist_public_enabled">
                            <option value="1" <?= ($cfg['whitelist_public_enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.whitelist_enabled') ?></option>
                            <option value="0" <?= ($cfg['whitelist_public_enabled'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.whitelist_disabled') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_submit_mode') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="whitelist_submit_mode">
                            <option value="public" <?= ($cfg['whitelist_submit_mode'] ?? 'public') !== 'users' ? 'selected' : '' ?>><?= _h('settings.whitelist_submit_public') ?></option>
                            <option value="users" <?= ($cfg['whitelist_submit_mode'] ?? 'public') === 'users' ? 'selected' : '' ?>><?= _h('settings.whitelist_submit_users') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.whitelist_submit_mode_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_max_per_submission') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_max_per_submission" value="<?= sanitize($cfg['whitelist_max_per_submission'] ?? '20') ?>" min="1" max="500">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_rate_limit') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_whitelist" value="<?= sanitize($cfg['rate_limit_whitelist'] ?? '10') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_ip_daily_max') ?> <small class="settings-hint"><?= _h('settings.whitelist_zero_off') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_ip_daily_max" value="<?= sanitize($cfg['whitelist_ip_daily_max'] ?? '50') ?>" min="0" max="100000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_daily_cap') ?> <small class="settings-hint"><?= _h('settings.whitelist_zero_off') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_daily_cap" value="<?= sanitize($cfg['whitelist_daily_cap'] ?? '2000') ?>" min="0" max="10000000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_reload_min_interval') ?> <small class="settings-hint"><?= _h('settings.whitelist_reload_min_interval_small') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="whitelist_reload_min_interval" value="<?= sanitize($cfg['whitelist_reload_min_interval'] ?? '45') ?>" min="10" max="3600">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.whitelist_scrape_url') ?> <small class="settings-hint"><?= _h('settings.whitelist_scrape_url_small') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="whitelist_scrape_url" value="<?= sanitize($cfg['whitelist_scrape_url'] ?? 'http://127.0.0.1:6969/scrape') ?>" placeholder="http://127.0.0.1:6969/scrape">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.whitelist_require_tracker') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="whitelist_require_tracker">
                            <option value="0" <?= ($cfg['whitelist_require_tracker'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.whitelist_require_tracker_off') ?></option>
                            <option value="1" <?= ($cfg['whitelist_require_tracker'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.whitelist_require_tracker_on') ?></option>
                        </select>
                        <div class="settings-hint mt-1"><?= __('settings.whitelist_require_tracker_hint') ?></div>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label"><?= _h('settings.whitelist_tracker_hosts') ?> <small class="settings-hint"><?= __('settings.whitelist_tracker_hosts_small') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="whitelist_tracker_hosts" value="<?= sanitize($cfg['whitelist_tracker_hosts'] ?? '') ?>" placeholder="tryhackx.org, 203.0.113.10">
                    </div>
                </div>
                <div class="settings-hint mt-2"><?= _h('settings.whitelist_manage_hint') ?> <a href="<?= $baseUrl ?>?action=admin-whitelist"><?= _h('settings.whitelist_manage_link') ?></a>.</div>

                <!-- A submission has to prove itself (includes/wlprobe.php) -->
            </div>

            <div class="settings-section" id="section-probe" data-group="tracker" data-title="<?= _h('settings.probe_title') ?>">
                <h5><?= _h('settings.probe_title') ?></h5>
                <p class="settings-hint mb-2"><?= _h('settings.probe_subtitle') ?></p>
                <small class="settings-hint d-block mb-3"><?= _h('settings.probe_intro_p1') ?>
                <br><br><?= __('settings.probe_intro_p2') ?></small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="wl_probe_required">
                        <label class="form-label"><?= _h('settings.probe_required') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_probe_required">
                            <option value="0" <?= ($cfg['wl_probe_required'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.probe_disabled') ?></option>
                            <option value="1" <?= ($cfg['wl_probe_required'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.probe_enabled') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.probe_required_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_probe_timeout_minutes">
                        <label class="form-label"><?= _h('settings.probe_timeout') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_probe_timeout_minutes" value="<?= sanitize($cfg['wl_probe_timeout_minutes'] ?? '10') ?>" min="1" max="1440">
                        <small class="settings-hint"><?= _h('settings.probe_timeout_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_probe_on_fail">
                        <label class="form-label"><?= _h('settings.probe_on_fail') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_probe_on_fail">
                            <option value="delete" <?= ($cfg['wl_probe_on_fail'] ?? 'delete') === 'delete' ? 'selected' : '' ?>><?= _h('settings.probe_on_fail_delete') ?></option>
                            <option value="keep" <?= ($cfg['wl_probe_on_fail'] ?? 'delete') === 'keep' ? 'selected' : '' ?>><?= _h('settings.probe_on_fail_keep') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.probe_on_fail_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_probe_max_batch">
                        <label class="form-label"><?= _h('settings.probe_max_batch') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_probe_max_batch" value="<?= sanitize($cfg['wl_probe_max_batch'] ?? '') ?>" min="1" max="64" placeholder="<?= _h('settings.probe_max_batch_ph') ?>">
                        <small class="settings-hint"><?= __('settings.probe_max_batch_hint') ?></small>
                    </div>
                </div>

                <!-- Keeping the list honest over time (includes/wlmaint.php) -->
            </div>

            <div class="settings-section" id="section-wlupkeep" data-group="tracker" data-title="<?= _h('settings.wlupkeep_title') ?>">
                <h5><?= _h('settings.wlupkeep_title') ?></h5>
                <p class="settings-hint mb-2"><?= _h('settings.wlupkeep_subtitle') ?></p>
                <small class="settings-hint d-block mb-3"><?= __('settings.wlupkeep_intro') ?></small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="wl_scrape_every_hours">
                        <label class="form-label"><?= _h('settings.wlupkeep_scrape_every') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_scrape_every_hours" value="<?= sanitize($cfg['wl_scrape_every_hours'] ?? '0') ?>" min="0" max="8760">
                        <small class="settings-hint"><?= _h('settings.wlupkeep_scrape_every_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_scrape_batch">
                        <label class="form-label"><?= _h('settings.wlupkeep_scrape_batch') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_scrape_batch" value="<?= sanitize($cfg['wl_scrape_batch'] ?? '200') ?>" min="1" max="2000">
                        <small class="settings-hint"><?= _h('settings.wlupkeep_scrape_batch_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_dead_after_days">
                        <label class="form-label"><?= _h('settings.wlupkeep_dead_after') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_dead_after_days" value="<?= sanitize($cfg['wl_dead_after_days'] ?? '0') ?>" min="0" max="3650">
                        <small class="settings-hint"><?= __('settings.wlupkeep_dead_after_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_dead_action">
                        <label class="form-label"><?= _h('settings.wlupkeep_dead_action') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_dead_action">
                            <option value="mark" <?= ($cfg['wl_dead_action'] ?? 'mark') === 'mark' ? 'selected' : '' ?>><?= _h('settings.wlupkeep_dead_mark') ?></option>
                            <option value="delete" <?= ($cfg['wl_dead_action'] ?? 'mark') === 'delete' ? 'selected' : '' ?>><?= _h('settings.wlupkeep_dead_delete') ?></option>
                            <option value="none" <?= ($cfg['wl_dead_action'] ?? 'mark') === 'none' ? 'selected' : '' ?>><?= _h('settings.wlupkeep_dead_none') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.wlupkeep_dead_action_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_dead_every_days">
                        <label class="form-label"><?= _h('settings.wlupkeep_dead_every') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_dead_every_days" value="<?= sanitize($cfg['wl_dead_every_days'] ?? '30') ?>" min="1" max="365">
                    </div>
                    <div class="col-md-9">
                        <div class="settings-hint mt-4"><?php
                            $wmCount = function_exists('wlMaintDeadCount') ? wlMaintDeadCount($db, $cfg) : 0;
                            if (wlMaintDeadDays($cfg) > 0) {
                                echo $wmCount === 1 ? __('settings.wlupkeep_match_one', ['n' => (int)$wmCount]) : __('settings.wlupkeep_match_many', ['n' => (int)$wmCount]);
                            } else {
                                echo _h('settings.wlupkeep_match_unset');
                            }
                        ?></div>
                    </div>
                </div>

                <!-- Source link + description on a whitelist row (includes/richtext.php) -->
            </div>

            <div class="settings-section" id="section-content" data-group="content" data-title="<?= _h('settings.content_title') ?>">
                <h5><?= _h('settings.content_title') ?></h5>
                <p class="settings-hint mb-2"><?= _h('settings.content_subtitle') ?></p>
                <small class="settings-hint d-block mb-3"><?= __('settings.content_intro') ?></small>
                <div class="row g-3">
                    <div class="col-md-3" data-setting="wl_allow_source_url">
                        <label class="form-label"><?= _h('settings.content_source_url') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_allow_source_url">
                            <option value="0" <?= ($cfg['wl_allow_source_url'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.content_disabled') ?></option>
                            <option value="1" <?= ($cfg['wl_allow_source_url'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.content_enabled') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.content_source_url_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_allow_description">
                        <label class="form-label"><?= _h('settings.content_description') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_allow_description">
                            <option value="0" <?= ($cfg['wl_allow_description'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.content_disabled') ?></option>
                            <option value="1" <?= ($cfg['wl_allow_description'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.content_enabled') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3" data-setting="wl_content_review">
                        <label class="form-label"><?= _h('settings.content_review') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_content_review">
                            <option value="1" <?= ($cfg['wl_content_review'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.content_review_yes') ?></option>
                            <option value="0" <?= ($cfg['wl_content_review'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.content_review_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.content_review_hint_pre') ?> <a href="<?= $baseUrl ?>?action=admin-whitelist"><?= _h('settings.content_review_hint_link') ?></a>. <?= _h('settings.content_review_hint_post') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_content_autopublish">
                        <label class="form-label"><?= _h('settings.content_autopublish') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="wl_content_autopublish">
                            <option value="0" <?= ($cfg['wl_content_autopublish'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.content_no') ?></option>
                            <option value="1" <?= ($cfg['wl_content_autopublish'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.content_autopublish_yes') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.content_autopublish_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="wl_edit_max_pending">
                        <label class="form-label"><?= _h('settings.content_edit_max_pending') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="wl_edit_max_pending" value="<?= sanitize($cfg['wl_edit_max_pending'] ?? '3') ?>" min="0" max="50">
                        <small class="settings-hint"><?= __('settings.content_edit_max_pending_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="link_trusted_domains">
                        <label class="form-label"><?= _h('settings.content_trusted_domains') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="link_trusted_domains" value="<?= sanitize($cfg['link_trusted_domains'] ?? '') ?>" placeholder="<?= _h('settings.content_trusted_domains_ph') ?>">
                        <small class="settings-hint"><?= _h('settings.content_trusted_domains_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="desc_allow_bbcode">
                        <label class="form-label"><?= _h('settings.content_allow_bbcode') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="desc_allow_bbcode">
                            <option value="1" <?= ($cfg['desc_allow_bbcode'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.content_yes') ?></option>
                            <option value="0" <?= ($cfg['desc_allow_bbcode'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.content_no') ?></option>
                        </select>
                        <small class="settings-hint"><code>[b] [i] [u] [s] [code] [quote] [list] [url] [img]</code></small>
                    </div>
                    <div class="col-md-3" data-setting="desc_allow_markdown">
                        <label class="form-label"><?= _h('settings.content_allow_markdown') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="desc_allow_markdown">
                            <option value="1" <?= ($cfg['desc_allow_markdown'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.content_yes') ?></option>
                            <option value="0" <?= ($cfg['desc_allow_markdown'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.content_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.content_allow_markdown_hint') ?></small>
                    </div>
                    <div class="col-md-2" data-setting="desc_max_chars">
                        <label class="form-label"><?= _h('settings.content_max_chars') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="desc_max_chars" value="<?= sanitize($cfg['desc_max_chars'] ?? '4000') ?>" min="200" max="20000">
                    </div>
                    <div class="col-md-2" data-setting="desc_max_images">
                        <label class="form-label"><?= _h('settings.content_max_images') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="desc_max_images" value="<?= sanitize($cfg['desc_max_images'] ?? '3') ?>" min="0" max="50">
                        <small class="settings-hint"><?= _h('settings.content_max_images_hint') ?></small>
                    </div>
                    <div class="col-md-2" data-setting="desc_max_links">
                        <label class="form-label"><?= _h('settings.content_max_links') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="desc_max_links" value="<?= sanitize($cfg['desc_max_links'] ?? '10') ?>" min="0" max="100">
                    </div>
                    <div class="col-md-3" data-setting="search_allow_sl_refresh">
                        <label class="form-label"><?= _h('settings.content_sl_refresh') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="search_allow_sl_refresh">
                            <option value="0" <?= ($cfg['search_allow_sl_refresh'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.content_disabled') ?></option>
                            <option value="1" <?= ($cfg['search_allow_sl_refresh'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.content_enabled') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.content_sl_refresh_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="search_sl_refresh_seconds">
                        <label class="form-label"><?= _h('settings.content_sl_refresh_seconds') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="search_sl_refresh_seconds" value="<?= sanitize($cfg['search_sl_refresh_seconds'] ?? '120') ?>" min="10" max="3600">
                        <small class="settings-hint"><?= _h('settings.content_sl_refresh_seconds_hint') ?></small>
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

            <div class="settings-section" id="section-schedule" data-group="tracker" data-title="<?= _h('settings.schedule_title') ?>">
                <h5><?= _h('settings.schedule_title') ?></h5>
                <p class="settings-hint mb-2"><?= _h('settings.schedule_subtitle') ?></p>
                <p class="settings-hint mb-2">
                    <?= __('settings.schedule_intro_1') ?>
                    <?= __('settings.schedule_intro_2') ?>
                    <?= __('settings.schedule_intro_3') ?>
                    <?= _h('settings.schedule_intro_4') ?>
                    <?= _h('settings.schedule_intro_5') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.schedule_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_schedule_enabled" id="sched-enabled">
                            <option value="0" <?= !$schedOn ? 'selected' : '' ?>><?= _h('settings.schedule_disabled') ?></option>
                            <option value="1" <?= $schedOn ? 'selected' : '' ?>><?= _h('settings.schedule_enabled_opt') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.schedule_tz') ?></label>
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
                        <label class="form-label"><?= _h('settings.schedule_switch_cmd') ?> <small class="settings-hint"><?= __('settings.schedule_switch_cmd_hint') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="tracker_mode_switch_cmd" value="<?= sanitize($cfg['tracker_mode_switch_cmd'] ?? 'sudo -n /usr/local/sbin/tracker-mode.sh') ?>" placeholder="<?= _h('settings.schedule_switch_cmd_ph') ?>" maxlength="255">
                    </div>
                    <div class="col-12">
                        <input type="hidden" name="tracker_schedule" id="sched-json" value="<?= sanitize(json_encode($schedDays)) ?>">
                        <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-1 sched-table" id="sched-table">
                                <thead><tr><th style="width:5rem"><?= _h('settings.schedule_th_day') ?></th><th style="width:16rem"><?= _h('settings.schedule_th_rule') ?></th><th style="width:9rem"><?= _h('settings.schedule_th_from') ?></th><th style="width:9rem"><?= _h('settings.schedule_th_to') ?></th><th></th></tr></thead>
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
                                            <option value="all" <?= $kind === 'all' ? 'selected' : '' ?>><?= _h('settings.schedule_kind_all') ?></option>
                                            <option value="window" <?= $kind === 'window' ? 'selected' : '' ?>><?= _h('settings.schedule_kind_window') ?></option>
                                            <option value="none" <?= $kind === 'none' ? 'selected' : '' ?>><?= _h('settings.schedule_kind_none') ?></option>
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
                            <?= __('settings.schedule_window_hint') ?>
                        </div>
                        <?php if ($schedSt): ?>
                        <div class="settings-hint mt-2" id="sched-summary">
                            <strong><?= _h('settings.schedule_saved') ?></strong> <?= sanitize($schedSt['describe']) ?>.
                            <?php if ($schedSt['enabled']): ?>
                                <?= _h('settings.schedule_desired_now') ?> <strong><?= sanitize($schedSt['desired'] ?? __('settings.schedule_invalid')) ?></strong> (<?= _h('settings.schedule_tracker_is_in') ?> <strong><?= sanitize($schedSt['current']) ?></strong>);
                                <?= _h('settings.schedule_next_change') ?> <strong><?= sanitize($schedSt['next_change_local'] ?? __('settings.schedule_none')) ?></strong> <?= $schedSt['next_change_local'] ? '(' . sanitize($schedSt['tz']) . ')' : '' ?>.
                                <?php if ($schedSt['last_result']): ?>
                                    <?= _h('settings.schedule_last_switch') ?> <strong><?= sanitize($schedSt['last_result']) ?></strong><?= $schedSt['last_switch_at'] ? ' ' . __('settings.schedule_switch_at') . ' ' . date('Y-m-d H:i', (int)$schedSt['last_switch_at']) . ' (' . sanitize((string)$schedSt['last_from']) . ' → ' . sanitize((string)$schedSt['last_to']) . ')' : '' ?><?= $schedSt['last_error'] ? ' — <span class="text-danger">' . sanitize($schedSt['last_error']) . '</span>' : '' ?>.
                                <?php endif; ?>
                            <?php else: ?>
                                <?= _h('settings.schedule_off_note') ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Server-to-server API -->
            <div class="settings-section" id="section-api" data-group="integrations" data-title="<?= _h('settings.api_title') ?>">
                <h5><?= _h('settings.api_title') ?></h5>
                <p class="settings-hint mb-2">
                    <?= __('settings.api_intro') ?>
                    <?= _h('settings.api_managed_on') ?> <a href="<?= $baseUrl ?>?action=admin-whitelist"><?= _h('settings.api_whitelist_page') ?></a>.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.api_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="api_enabled">
                            <option value="1" <?= ($cfg['api_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.opt_enabled') ?></option>
                            <option value="0" <?= ($cfg['api_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.opt_disabled') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.api_ban_days') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="api_ban_days" value="<?= sanitize($cfg['api_ban_days'] ?? '30') ?>" min="1" max="3650">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.api_exempt_ips') ?> <small class="settings-hint"><?= _h('settings.api_exempt_ips_hint') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="api_ban_exempt_ips" value="<?= sanitize($cfg['api_ban_exempt_ips'] ?? '127.0.0.1, ::1') ?>" placeholder="127.0.0.1, ::1, 203.0.113.10">
                    </div>
                </div>
                <p class="settings-hint mt-3 mb-2">
                    <?= __('settings.api_budgets_intro') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.api_rpm') ?> <small class="settings-hint"><?= _h('settings.api_per_key') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="api_rate_limit_per_min" value="<?= sanitize($cfg['api_rate_limit_per_min'] ?? '60') ?>" min="0" max="100000">
                        <small class="settings-hint"><?= _h('settings.api_rpm_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.api_bytes_day') ?> <small class="settings-hint"><?= _h('settings.api_per_key') ?></small></label>
                        <div class="input-group settings-size">
                            <input type="number" class="form-control bg-dark text-light border-secondary"
                                   id="api-rate-limit-bytes-day-num" min="0" step="1" aria-label="<?= _h('settings.api_size_aria') ?>">
                            <select class="form-select bg-dark text-light border-secondary" id="api-rate-limit-bytes-day-unit" aria-label="<?= _h('settings.api_unit_aria') ?>">
                                <option value="1"><?= _h('settings.api_unit_bytes') ?></option>
                                <option value="1024">KiB</option>
                                <option value="1048576">MiB</option>
                                <option value="1073741824">GiB</option>
                                <option value="1099511627776">TiB</option>
                            </select>
                        </div>
                        <!-- The setting itself. Bytes, named literally, never edited by hand:
                             the pair above writes into it. -->
                        <input type="hidden" name="api_rate_limit_bytes_day" id="api-rate-limit-bytes-day-raw"
                               data-size-min="0" data-size-max="1099511627776"
                               value="<?= sanitize($cfg['api_rate_limit_bytes_day'] ?? '5368709120') ?>">
                        <small class="settings-hint"><?= __('settings.api_bytes_day_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- User accounts -->
            <div class="settings-section" id="section-tuner" data-group="network" data-title="<?= _h('settings.tuner_title') ?>">
                <h5><?= _h('settings.tuner_title') ?></h5>
                <p class="settings-hint mb-2">
                    <?= __('settings.tuner_intro') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-4" data-setting="tuner_enabled">
                        <label class="form-label"><?= _h('settings.tuner_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="tuner_enabled">
                            <option value="0" <?= ($cfg['tuner_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.opt_disabled') ?></option>
                            <option value="1" <?= ($cfg['tuner_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.tuner_enabled_opt') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.tuner_enabled_hint') ?></small>
                    </div>
                    <div class="col-md-4" data-setting="tuner_python">
                        <label class="form-label"><?= _h('settings.tuner_python') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="tuner_python" value="<?= sanitize($cfg['tuner_python'] ?? 'python3') ?>" placeholder="python3">
                        <small class="settings-hint"><?= __('settings.tuner_python_hint') ?></small>
                    </div>
                    <div class="col-md-4" data-setting="tuner_load_headroom">
                        <label class="form-label"><?= _h('settings.tuner_headroom') ?> <small class="settings-hint"><?= _h('settings.tuner_per_core') ?></small></label>
                        <input type="number" step="0.05" min="0.05" max="4" class="form-control bg-dark text-light border-secondary" name="tuner_load_headroom" value="<?= sanitize($cfg['tuner_load_headroom'] ?? '0.35') ?>">
                        <small class="settings-hint"><?= __('settings.tuner_headroom_hint') ?></small>
                    </div>
                    <div class="col-md-4" data-setting="tuner_load_hard">
                        <label class="form-label"><?= _h('settings.tuner_hard') ?> <small class="settings-hint"><?= _h('settings.tuner_load_per_core') ?></small></label>
                        <input type="number" step="0.1" min="0.5" max="20" class="form-control bg-dark text-light border-secondary" name="tuner_load_hard" value="<?= sanitize($cfg['tuner_load_hard'] ?? '2.0') ?>">
                        <small class="settings-hint"><?= _h('settings.tuner_hard_hint') ?></small>
                    </div>
                </div>
            </div>

            <div class="settings-section" id="section-audit" data-group="users" data-title="<?= _h('settings.audit_title') ?>">
                <h5><?= _h('settings.audit_title') ?></h5>
                <p class="settings-hint mb-2"><?= _h('settings.audit_intro') ?></p>
                <div class="row g-3">
                    <div class="col-md-4" data-setting="audit_enabled">
                        <label class="form-label"><?= _h('settings.audit_recording') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="audit_enabled">
                            <option value="1" <?= ($cfg['audit_enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.audit_opt_record') ?></option>
                            <option value="0" <?= ($cfg['audit_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.audit_opt_no_record') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.audit_recording_hint') ?></small>
                    </div>
                    <div class="col-md-4" data-setting="audit_keep_days">
                        <label class="form-label"><?= _h('settings.audit_keep_days') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="audit_keep_days" value="<?= sanitize($cfg['audit_keep_days'] ?? '180') ?>" min="7" max="3650">
                        <small class="settings-hint"><?= _h('settings.audit_keep_days_hint') ?></small>
                    </div>
                </div>
            </div>

            <div class="settings-section" id="section-users" data-group="users" data-title="<?= _h('settings.users_title') ?>">
                <h5><?= _h('settings.users_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.users_intro_a') ?> <a href="<?= $baseUrl ?>?action=admin-users"><?= _h('settings.users_page_link') ?></a>. <?= __('settings.users_intro_b') ?></small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_enabled">
                            <option value="1" <?= ($cfg['users_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.opt_enabled') ?></option>
                            <option value="0" <?= ($cfg['users_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.opt_disabled') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_registration') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_registration_enabled">
                            <option value="1" <?= ($cfg['users_registration_enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.users_registration_open') ?></option>
                            <option value="0" <?= ($cfg['users_registration_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.users_registration_closed') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.users_registration_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_links') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_links_visible">
                            <option value="1" <?= ($cfg['users_links_visible'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.users_links_visible') ?></option>
                            <option value="0" <?= ($cfg['users_links_visible'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.users_links_hidden') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.users_links_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_default_group') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="users_default_group" value="<?= sanitize($cfg['users_default_group'] ?? 'member') ?>" pattern="[a-z0-9_\-]{2,64}">
                        <small class="settings-hint"><?= _h('settings.users_default_group_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_expiry_days') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="users_notify_expiry_days" value="<?= sanitize($cfg['users_notify_expiry_days'] ?? '3') ?>" min="0" max="30">
                        <small class="settings-hint"><?= _h('settings.users_expiry_days_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="bulk_mail_enabled">
                        <label class="form-label"><?= _h('settings.bulk_mail_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="bulk_mail_enabled">
                            <option value="0" <?= ($cfg['bulk_mail_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.opt_disabled') ?></option>
                            <option value="1" <?= ($cfg['bulk_mail_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.opt_enabled') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.bulk_mail_hint_a') ?> <a href="<?= $baseUrl ?>?action=admin-users"><?= _h('settings.users_page_link') ?></a>: <?= __('settings.bulk_mail_hint_b') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="bulk_mail_per_minute">
                        <label class="form-label"><?= _h('settings.bulk_mail_per_minute') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="bulk_mail_per_minute" value="<?= sanitize($cfg['bulk_mail_per_minute'] ?? '20') ?>" min="1" max="500">
                        <small class="settings-hint"><?= __('settings.bulk_mail_per_minute_hint') ?></small>
                    </div>
                    <div class="col-md-3" data-setting="bulk_mail_max_attempts">
                        <label class="form-label"><?= _h('settings.bulk_mail_retries') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="bulk_mail_max_attempts" value="<?= sanitize($cfg['bulk_mail_max_attempts'] ?? '3') ?>" min="1" max="10">
                        <small class="settings-hint"><?= _h('settings.bulk_mail_retries_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_email_verify') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="users_require_email_verify">
                            <option value="1" <?= ($cfg['users_require_email_verify'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.users_email_verify_yes') ?></option>
                            <option value="0" <?= ($cfg['users_require_email_verify'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.users_email_verify_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.users_email_verify_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_email_cooldown') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="users_email_change_cooldown_days" value="<?= sanitize($cfg['users_email_change_cooldown_days'] ?? '30') ?>" min="0" max="365">
                        <small class="settings-hint"><?= _h('settings.users_email_cooldown_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.users_terms') ?> <small class="settings-hint"><?= __('settings.users_terms_hint') ?></small></label>
                        <textarea class="form-control bg-dark text-light border-secondary" name="users_terms_text" rows="4" placeholder="<?= _h('settings.users_terms_ph') ?>"><?= sanitize($cfg['users_terms_text'] ?? '') ?></textarea>
                        <small class="settings-hint"><?= _h('settings.users_terms_note') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_rl_login') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_user_login" value="<?= sanitize($cfg['rate_limit_user_login'] ?? '10') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_rl_register') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_user_register" value="<?= sanitize($cfg['rate_limit_user_register'] ?? '5') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_rl_search') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_index_search" value="<?= sanitize($cfg['rate_limit_index_search'] ?? '120') ?>" min="0" max="100000">
                    </div>
                    <div class="col-md-3" data-setting="rate_limit_preview">
                        <label class="form-label"><?= _h('settings.users_rl_preview') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_preview" value="<?= sanitize($cfg['rate_limit_preview'] ?? '30') ?>" min="5" max="300">
                        <small class="settings-hint"><?= _h('settings.users_rl_preview_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_search') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_search_enabled">
                            <option value="1" <?= ($cfg['index_search_enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.opt_enabled') ?></option>
                            <option value="0" <?= ($cfg['index_search_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.users_search_disabled') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.users_search_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.users_search_whitelist') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_search_include_whitelist">
                            <option value="1" <?= ($cfg['index_search_include_whitelist'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.users_search_whitelist_yes') ?></option>
                            <option value="0" <?= ($cfg['index_search_include_whitelist'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.users_search_whitelist_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.users_search_whitelist_hint') ?></small>
                        <small class="settings-hint"><?= __('settings.users_search_note') ?></small>
                    </div>
                </div>
            </div>

            <!-- OpenTracker performance -->
            <div class="settings-section" id="section-ot-perf" data-group="opentracker" data-title="<?= _h('settings.ot_perf_title') ?>">
                <h5><?= _h('settings.ot_perf_title') ?></h5>
                <p class="settings-hint mb-2">
                    <?= __('settings.ot_perf_intro_a') ?>
                    <a href="<?= $baseUrl ?>?action=admin-traffic#ot-card"><?= _h('settings.ot_perf_traffic_page') ?></a> <?= _h('settings.ot_perf_intro_b') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.ot_perf_cmd') ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_perf_cmd" value="<?= sanitize($cfg['ot_perf_cmd'] ?? '') ?>" placeholder="<?= _h('settings.ot_perf_cmd_ph') ?>">
                            <button class="btn btn-outline-info" type="button" id="btn-ot-test"><i class="bi bi-clipboard-check"></i> <?= _h('settings.ot_perf_test_btn') ?></button>
                        </div>
                        <small class="settings-hint"><?= __('settings.ot_perf_cmd_hint') ?></small>
                        <div id="ot-test-result" class="mt-2"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_nice') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_nice" value="<?= sanitize($cfg['ot_nice'] ?? '-2') ?>" min="-20" max="19">
                        <small class="settings-hint"><?= _h('settings.ot_nice_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_cpu_weight') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_cpu_weight" value="<?= sanitize($cfg['ot_cpu_weight'] ?? '100') ?>" min="1" max="10000">
                        <small class="settings-hint"><?= _h('settings.ot_cpu_weight_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_cpu_affinity') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_cpu_affinity" value="<?= sanitize($cfg['ot_cpu_affinity'] ?? '') ?>" placeholder="<?= _h('settings.ot_cpu_affinity_ph') ?>">
                        <small class="settings-hint"><?= __('settings.ot_cpu_affinity_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_nofile') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_limit_nofile" value="<?= sanitize($cfg['ot_limit_nofile'] ?? '65536') ?>" min="1024" max="1048576">
                        <small class="settings-hint"><?= __('settings.ot_nofile_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_udp_workers') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_udp_workers" value="<?= sanitize($cfg['ot_udp_workers'] ?? '') ?>" placeholder="<?= _h('settings.ot_udp_workers_ph') ?>">
                        <small class="settings-hint"><?= __('settings.ot_udp_workers_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- Kernel network buffers -->
            <!-- Extra opentracker instances -->
            <div class="settings-section" id="section-cluster" data-group="opentracker" data-title="<?= _h('settings.cluster_title') ?>">
                <h5><?= _h('settings.cluster_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.cluster_intro_a') ?> <a href="<?= $baseUrl ?>?action=admin-traffic#ot-card"><?= _h('settings.cluster_perf_card_link') ?></a> <?= __('settings.cluster_intro_b') ?></small>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.cluster_cmd') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="ot_cluster_cmd" value="<?= sanitize($cfg['ot_cluster_cmd'] ?? '') ?>" maxlength="255" placeholder="<?= _h('settings.cluster_cmd_ph') ?>">
                        <small class="settings-hint"><?= __('settings.cluster_cmd_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_cluster_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="ot_cluster_enabled">
                            <option value="0" <?= ($cfg['ot_cluster_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.ot_cluster_no') ?></option>
                            <option value="1" <?= ($cfg['ot_cluster_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.ot_cluster_yes') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.ot_cluster_enabled_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_cluster_port_base') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="ot_cluster_port_base" value="<?= sanitize($cfg['ot_cluster_port_base'] ?? '') ?>" min="1024" max="65500" placeholder="<?= _h('settings.ot_cluster_port_base_ph') ?>">
                        <small class="settings-hint"><?= _h('settings.ot_cluster_port_base_hint') ?></small>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-cluster-test"><i class="bi bi-plug"></i> <?= _h('settings.ot_cluster_test') ?></button>
                    <div id="cluster-test-result" class="mt-2"></div>
                    <small class="settings-hint d-block mt-2"><?= __('settings.ot_cluster_test_hint') ?></small>
                </div>
            </div>


            <div class="settings-section" id="section-sysctl" data-group="network" data-title="<?= _h('settings.sysctl_title') ?>">
                <h5><?= _h('settings.sysctl_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.sysctl_intro') ?> <a href="<?= $baseUrl ?>?action=admin-traffic#sysctl-card"><?= _h('settings.sysctl_intro_link') ?></a><?= _h('settings.sysctl_intro_tail') ?></small>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.sysctl_cmd') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="sysctl_cmd" value="<?= sanitize($cfg['sysctl_cmd'] ?? '') ?>" maxlength="255" placeholder="<?= _h('settings.sysctl_cmd_ph') ?>">
                        <small class="settings-hint"><?= __('settings.sysctl_cmd_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.sysctl_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="sysctl_enabled">
                            <option value="0" <?= ($cfg['sysctl_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.sysctl_no') ?></option>
                            <option value="1" <?= ($cfg['sysctl_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.sysctl_yes') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.sysctl_enabled_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.sysctl_confirm_seconds') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="sysctl_confirm_seconds" value="<?= sanitize($cfg['sysctl_confirm_seconds'] ?? '120') ?>" min="60" max="900" step="60">
                        <small class="settings-hint"><?= _h('settings.sysctl_confirm_seconds_hint') ?></small>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-sysctl-test"><i class="bi bi-plug"></i> <?= _h('settings.sysctl_test') ?></button>
                    <div id="sysctl-test-result" class="mt-2"></div>
                </div>
            </div>


            <!-- Federation / cluster -->
            <div class="settings-section" id="section-federation" data-group="integrations" data-title="<?= _h('settings.federation_title') ?>">
                <h5><?= _h('settings.federation_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.federation_intro') ?></small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_enabled">
                            <option value="1" <?= ($cfg['fed_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.federation_enabled_on') ?></option>
                            <option value="0" <?= ($cfg['fed_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.federation_enabled_off') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_node_name') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="fed_node_name" value="<?= sanitize($cfg['fed_node_name'] ?? '') ?>" maxlength="64" placeholder="<?= _h('settings.federation_node_name_ph') ?>">
                        <small class="settings-hint"><?= _h('settings.federation_node_name_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_export_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_export_enabled">
                            <option value="1" <?= ($cfg['fed_export_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.federation_yes') ?></option>
                            <option value="0" <?= ($cfg['fed_export_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.federation_export_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_export_files') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_export_files">
                            <option value="1" <?= ($cfg['fed_export_files'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.federation_yes') ?></option>
                            <option value="0" <?= ($cfg['fed_export_files'] ?? '1') !== '1' ? 'selected' : '' ?>><?= _h('settings.federation_export_files_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_export_max_batch') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_export_max_batch" value="<?= sanitize($cfg['fed_export_max_batch'] ?? '2000') ?>" min="100" max="20000">
                        <small class="settings-hint"><?= _h('settings.federation_export_max_batch_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_export_max_bytes') ?></label>
                        <div class="input-group settings-size">
                            <input type="number" class="form-control bg-dark text-light border-secondary"
                                   id="fed-export-max-bytes-num" min="0" step="1" aria-label="<?= _h('settings.federation_size_aria') ?>">
                            <select class="form-select bg-dark text-light border-secondary" id="fed-export-max-bytes-unit" aria-label="<?= _h('settings.federation_unit_aria') ?>">
                                <option value="1"><?= _h('settings.federation_unit_bytes') ?></option>
                                <option value="1024">KiB</option>
                                <option value="1048576">MiB</option>
                                <option value="1073741824">GiB</option>
                                <option value="1099511627776">TiB</option>
                            </select>
                        </div>
                        <!-- The setting itself. Bytes, named literally, never edited by hand:
                             the pair above writes into it. -->
                        <input type="hidden" name="fed_export_max_bytes" id="fed-export-max-bytes-raw"
                               data-size-min="0" data-size-max="1073741824"
                               value="<?= sanitize($cfg['fed_export_max_bytes'] ?? '8388608') ?>">
                        <small class="settings-hint"><?= _h('settings.federation_export_max_bytes_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_import_batch_rows') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_import_batch_rows" value="<?= sanitize($cfg['fed_import_batch_rows'] ?? '500') ?>" min="25" max="5000">
                        <small class="settings-hint"><?= _h('settings.federation_import_batch_rows_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_import_batch_bytes') ?></label>
                        <div class="input-group settings-size">
                            <input type="number" class="form-control bg-dark text-light border-secondary"
                                   id="fed-import-batch-bytes-num" min="0" step="1" aria-label="<?= _h('settings.federation_size_aria') ?>">
                            <select class="form-select bg-dark text-light border-secondary" id="fed-import-batch-bytes-unit" aria-label="<?= _h('settings.federation_unit_aria') ?>">
                                <option value="1"><?= _h('settings.federation_unit_bytes') ?></option>
                                <option value="1024">KiB</option>
                                <option value="1048576">MiB</option>
                                <option value="1073741824">GiB</option>
                                <option value="1099511627776">TiB</option>
                            </select>
                        </div>
                        <!-- The setting itself. Bytes, named literally, never edited by hand:
                             the pair above writes into it. -->
                        <input type="hidden" name="fed_import_batch_bytes" id="fed-import-batch-bytes-raw"
                               data-size-min="1048576" data-size-max="268435456"
                               value="<?= sanitize($cfg['fed_import_batch_bytes'] ?? '33554432') ?>">
                        <small class="settings-hint"><?= _h('settings.federation_import_batch_bytes_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_import_max_seconds') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_import_max_seconds" value="<?= sanitize($cfg['fed_import_max_seconds'] ?? '600') ?>" min="30" max="21600">
                        <small class="settings-hint"><?= _h('settings.federation_import_max_seconds_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_worker_mem_mb') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_worker_mem_mb" value="<?= sanitize($cfg['fed_worker_mem_mb'] ?? '256') ?>" min="64" max="4096">
                        <small class="settings-hint"><?= __('settings.federation_worker_mem_mb_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_export_max_files') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_export_max_files" value="<?= sanitize($cfg['fed_export_max_files'] ?? '200000') ?>" min="0" max="50000000" step="10000">
                        <small class="settings-hint"><?= _h('settings.federation_export_max_files_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_import_new') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_import_new">
                            <option value="1" <?= ($cfg['fed_import_new'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.federation_import_new_yes') ?></option>
                            <option value="0" <?= ($cfg['fed_import_new'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.federation_import_new_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.federation_import_new_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_import_mode') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="fed_import_mode">
                            <option value="fill" <?= ($cfg['fed_import_mode'] ?? 'fill') !== 'review' ? 'selected' : '' ?>><?= _h('settings.federation_import_mode_fill') ?></option>
                            <option value="review" <?= ($cfg['fed_import_mode'] ?? 'fill') === 'review' ? 'selected' : '' ?>><?= _h('settings.federation_import_mode_review') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.federation_import_mode_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.federation_pull_minutes') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="fed_pull_minutes" value="<?= sanitize($cfg['fed_pull_minutes'] ?? '60') ?>" min="5" max="1440">
                        <small class="settings-hint"><?= __('settings.federation_pull_minutes_hint') ?></small>
                    </div>
                </div>
                <div class="mt-3" id="fed-peers-card">
                    <label class="form-label"><?= _h('settings.federation_peers') ?></label>
                    <div class="table-responsive">
                        <table class="table table-dark table-sm align-middle" id="fed-peers-table">
                            <thead><tr><th><?= _h('settings.federation_th_name') ?></th><th><?= _h('settings.federation_th_base_url') ?></th><th><?= _h('settings.federation_th_pull') ?></th><th><?= _h('settings.federation_th_inbound_key') ?></th><th><?= _h('settings.federation_th_last_pull') ?></th><th><?= _h('settings.federation_th_imported') ?></th><th><?= _h('settings.federation_th_status') ?></th><th></th></tr></thead>
                            <tbody id="fed-peers-body"><tr><td colspan="8" class="text-muted"><?= _h('settings.federation_loading') ?></td></tr></tbody>
                        </table>
                    </div>
                    <div class="row g-2 align-items-end" id="fed-peer-add">
                        <div class="col-md-2"><label class="form-label"><?= _h('settings.federation_th_name') ?></label><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="fp-name" maxlength="64" placeholder="<?= _h('settings.federation_peer_name_ph') ?>"></div>
                        <div class="col-md-3"><label class="form-label"><?= _h('settings.federation_th_base_url') ?></label><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="fp-url" maxlength="255" placeholder="https://tracker.example.org"></div>
                        <div class="col-md-3"><label class="form-label"><?= _h('settings.federation_peer_bearer') ?> <small class="settings-hint"><?= _h('settings.federation_peer_bearer_note') ?></small></label><input type="password" class="form-control form-control-sm bg-dark text-light border-secondary" id="fp-bearer" autocomplete="off" placeholder="key_id.secret"></div>
                        <div class="col-md-1"><label class="form-label"><?= _h('settings.federation_th_pull') ?></label><select class="form-select form-select-sm bg-dark text-light border-secondary" id="fp-pull"><option value="1"><?= _h('settings.federation_yes') ?></option><option value="0" selected><?= _h('settings.federation_no') ?></option></select></div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-sm btn-outline-info" id="fp-add"><i class="bi bi-plus-lg"></i> <?= _h('settings.federation_add_peer') ?></button>
                            <div class="form-check form-check-inline ms-1" title="<?= _h('settings.federation_grant_title') ?>">
                                <input class="form-check-input" type="checkbox" id="fp-grant">
                                <label class="form-check-label settings-hint" for="fp-grant"><?= _h('settings.federation_grant_label') ?></label>
                            </div>
                        </div>
                    </div>
                    <div id="fp-alert" class="mt-2"></div>
                    <small class="settings-hint d-block mt-1"><?= __('settings.federation_exchange_hint') ?></small>
                </div>

                <!-- The quarantine queue. Hidden while it is empty AND review mode is off, because a
                     node that trusts its peers should not have to look at a control it never uses. -->
                <div class="mt-4 d-hidden" id="fed-review-card">
                    <label class="form-label"><?= _h('settings.federation_review_title') ?> <span class="badge bg-warning text-dark" id="fr-count">0</span></label>
                    <small class="settings-hint d-block mb-2"><?= __('settings.federation_review_hint') ?></small>
                    <div class="d-flex gap-2 align-items-center flex-wrap mb-2">
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="fr-peer" style="max-width:14rem;"><option value=""><?= _h('settings.federation_review_all_peers') ?></option></select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="fr-state" style="max-width:11rem;">
                            <option value="pending"><?= _h('settings.federation_review_state_pending') ?></option>
                            <option value="rejected"><?= _h('settings.federation_review_state_rejected') ?></option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="fr-refresh"><i class="bi bi-arrow-clockwise"></i> <?= _h('settings.federation_review_refresh') ?></button>
                        <span class="flex-grow-1"></span>
                        <button type="button" class="btn btn-sm btn-outline-success" id="fr-accept-sel" disabled><i class="bi bi-check2"></i> <?= _h('settings.federation_review_accept_sel') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="fr-reject-sel" disabled><i class="bi bi-x"></i> <?= _h('settings.federation_review_reject_sel') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="fr-accept-peer" disabled title="<?= _h('settings.federation_review_accept_peer_title') ?>"><i class="bi bi-check2-all"></i> <?= _h('settings.federation_review_accept_peer') ?></button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark table-sm align-middle" id="fed-review-table">
                            <thead><tr><th style="width:2rem;"><input type="checkbox" class="form-check-input" id="fr-all"></th><th><?= _h('settings.federation_th_name') ?></th><th><?= _h('settings.federation_review_th_size') ?></th><th><?= _h('settings.federation_review_th_files') ?></th><th><?= _h('settings.federation_review_th_peer') ?></th><th><?= _h('settings.federation_review_th_resolved') ?></th><th></th></tr></thead>
                            <tbody id="fed-review-body"></tbody>
                        </table>
                    </div>
                    <div id="fr-alert" class="mt-2"></div>
                </div>
            </div>

            <!-- Rate Limits & Blacklist -->
            <div class="settings-section" id="section-limits" data-group="security" data-title="<?= _h('settings.limits_title') ?>">
                <h5><?= _h('settings.limits_title') ?></h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_rate_limit') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit" value="<?= sanitize($cfg['rate_limit'] ?? '5') ?>" min="1" max="100">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_rate_limit_status') ?> <small class="settings-hint"><?= _h('settings.limits_zero_off') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_status" value="<?= sanitize($cfg['rate_limit_status'] ?? '20') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_rate_limit_block_check') ?> <small class="settings-hint"><?= _h('settings.limits_zero_off') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_block_check" value="<?= sanitize($cfg['rate_limit_block_check'] ?? '30') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_rate_limit_appeal') ?> <small class="settings-hint"><?= _h('settings.limits_zero_off') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="rate_limit_appeal" value="<?= sanitize($cfg['rate_limit_appeal'] ?? '5') ?>" min="0" max="1000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_items_per_page') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="items_per_page" value="<?= sanitize($cfg['items_per_page'] ?? '25') ?>" min="5" max="200">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_admin_near_pages') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_near_pages" value="<?= sanitize($cfg['admin_near_pages'] ?? '2') ?>" min="1" max="20">
                        <small class="settings-hint"><?= __('settings.limits_admin_near_pages_hint') ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_max_message_length') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="max_message_length" value="<?= sanitize($cfg['max_message_length'] ?? '2000') ?>" min="100" max="10000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_max_appeal_message_length') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="max_appeal_message_length" value="<?= sanitize($cfg['max_appeal_message_length'] ?? '2000') ?>" min="100" max="10000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.limits_max_magnet_link_length') ?> <small class="settings-hint"><?= _h('settings.limits_zero_unlimited') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="max_magnet_link_length" value="<?= sanitize($cfg['max_magnet_link_length'] ?? '0') ?>" min="0" max="100000">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-8">
                        <label class="form-label"><?= _h('settings.limits_blacklist_path') ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="blacklist_path" value="<?= sanitize($cfg['blacklist_path'] ?? '') ?>" placeholder="/home/tracker/blacklist">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-blacklist"><?= _h('settings.limits_blacklist_test') ?></button>
                        </div>
                        <div id="blacklist-result" class="mt-1 blacklist-result"></div>
                    </div>
                </div>
            </div>

            <!-- Admin address, Sessions, Login Lockout & Proxy -->
            <div class="settings-section" id="section-admin-access" data-group="security" data-title="<?= _h('settings.admin_access_title') ?>">
                <h5><?= _h('settings.admin_access_title') ?></h5>
                <?php $adminPathNow = adminLoginPath($cfg); $adminHiddenNow = adminHiddenBehavior($cfg); ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.admin_access_login_path') ?></label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark text-secondary border-secondary">?action=</span>
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="admin_login_path" value="<?= sanitize($adminPathNow) ?>" maxlength="64" pattern="[A-Za-z0-9_\-]+" placeholder="admin">
                        </div>
                        <small class="settings-hint"><?= __('settings.admin_access_login_path_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.admin_access_hidden_behavior') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="admin_hidden_behavior">
                            <option value="home" <?= $adminHiddenNow === 'home' ? 'selected' : '' ?>><?= _h('settings.admin_access_hidden_home') ?></option>
                            <option value="login" <?= $adminHiddenNow === 'login' ? 'selected' : '' ?>><?= _h('settings.admin_access_hidden_login') ?></option>
                            <option value="404" <?= $adminHiddenNow === '404' ? 'selected' : '' ?>><?= _h('settings.admin_access_hidden_404') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.admin_access_hidden_behavior_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.admin_access_session_idle') ?> <small class="settings-hint"><?= _h('settings.limits_zero_off') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_session_idle_minutes" value="<?= sanitize($cfg['admin_session_idle_minutes'] ?? '30') ?>" min="0" max="1440">
                        <small class="settings-hint"><?= _h('settings.admin_access_session_idle_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.admin_access_session_absolute') ?> <small class="settings-hint"><?= _h('settings.limits_zero_off') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_session_absolute_hours" value="<?= sanitize($cfg['admin_session_absolute_hours'] ?? '12') ?>" min="0" max="720">
                        <small class="settings-hint"><?= _h('settings.admin_access_session_absolute_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.admin_access_lockout_attempts') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="login_lockout_attempts" value="<?= sanitize($cfg['login_lockout_attempts'] ?? '5') ?>" min="1" max="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.admin_access_lockout_minutes') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="login_lockout_minutes" value="<?= sanitize($cfg['login_lockout_minutes'] ?? '15') ?>" min="1" max="1440">
                    </div>
                    <div class="col-md-3" data-setting="admin_reauth_max_attempts">
                        <label class="form-label"><?= _h('settings.admin_access_reauth_max_attempts') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="admin_reauth_max_attempts" value="<?= sanitize($cfg['admin_reauth_max_attempts'] ?? '5') ?>" min="1" max="20">
                        <small class="settings-hint"><?= __('settings.admin_access_reauth_max_attempts_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.admin_access_trusted_proxy_ips') ?> <small class="settings-hint"><?= _h('settings.admin_access_trusted_proxy_ips_note') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="trusted_proxy_ips" value="<?= sanitize($cfg['trusted_proxy_ips'] ?? '') ?>" placeholder="<?= _h('settings.admin_access_trusted_proxy_ips_ph') ?>">
                        <small class="settings-hint"><?= _h('settings.admin_access_trusted_proxy_ips_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.admin_access_client_ip_header') ?> <small class="settings-hint"><?= _h('settings.admin_access_client_ip_header_note') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="client_ip_header" value="<?= sanitize($cfg['client_ip_header'] ?? '') ?>" placeholder="<?= _h('settings.admin_access_client_ip_header_ph') ?>">
                        <small class="settings-hint"><?= _h('settings.admin_access_client_ip_header_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- Donation Fields -->
            <div class="settings-section" id="section-donations" data-group="general" data-title="<?= _h('settings.donations_title') ?>">
                <h5><?= _h('settings.donations_title') ?></h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.donations_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="donations_enabled">
                            <option value="1" <?= ($cfg['donations_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.donations_yes') ?></option>
                            <option value="0" <?= ($cfg['donations_enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.donations_no') ?></option>
                        </select>
                    </div>
                    <div class="col-12">
                        <small class="settings-hint"><?= _h('settings.donations_hint') ?></small>
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
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="<?= _h('settings.donation_field_label_ph') ?>" value="<?= sanitize($field['label'] ?? '') ?>" data-df="label">
                        </div>
                        <div class="col">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="<?= _h('settings.donation_field_value_ph') ?>" value="<?= sanitize($field['value'] ?? '') ?>" data-df="value">
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-danger donation-field-remove" title="<?= _h('settings.donation_field_remove_title') ?>"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-info mt-1" id="donation-field-add" data-setting="donation_fields"><i class="bi bi-plus-lg"></i> <?= _h('settings.donation_field_add') ?></button>
            </div>

            <!-- Transparency Page -->
            <div class="settings-section" id="section-transparency" data-group="general" data-title="<?= _h('settings.transparency_title') ?>">
                <h5><?= _h('settings.transparency_title') ?></h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.transparency_enable') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="transparency_enabled">
                            <option value="1" <?= ($cfg['transparency_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.stats_opt_yes') ?></option>
                            <option value="0" <?= ($cfg['transparency_enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.stats_opt_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.transparency_per_page') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="transparency_per_page" value="<?= sanitize($cfg['transparency_per_page'] ?? '150') ?>" min="10" max="500">
                    </div>
                </div>
            </div>

            <!-- Tracker Statistics -->
            <div class="settings-section" id="section-stats" data-group="stats" data-title="<?= _h('settings.stats_title') ?>">
                <h5><?= _h('settings.stats_title') ?></h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_enable') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_enabled">
                            <option value="1" <?= ($cfg['tracker_stats_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.stats_opt_yes') ?></option>
                            <option value="0" <?= ($cfg['tracker_stats_enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.stats_opt_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.stats_source_url') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="tracker_stats_url" value="<?= sanitize($cfg['tracker_stats_url'] ?? '') ?>" placeholder="http://YOUR_TRACKER_HOST:6969/stats?mode=everything">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_home_interval') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_interval" value="<?= sanitize($cfg['tracker_stats_interval'] ?? '10') ?>" min="2" max="3600">
                        <small class="settings-hint"><?= _h('settings.stats_home_interval_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_page_interval') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_page_interval" value="<?= sanitize($cfg['tracker_stats_page_interval'] ?? ($cfg['tracker_stats_interval'] ?? '10')) ?>" min="2" max="3600">
                        <small class="settings-hint"><?= _h('settings.stats_page_interval_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_cache_ttl') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_cache_ttl" value="<?= sanitize($cfg['tracker_stats_cache_ttl'] ?? '60') ?>" min="2" max="86400">
                        <small class="settings-hint"><?= __('settings.stats_cache_ttl_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_show_home') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_show_home">
                            <option value="1" <?= ($cfg['tracker_stats_show_home'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.stats_opt_yes') ?></option>
                            <option value="0" <?= ($cfg['tracker_stats_show_home'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.stats_opt_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_peer_label_style') ?></label>
                        <?php $pls = $cfg['tracker_stats_peer_label_style'] ?? 'percent'; ?>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_peer_label_style">
                            <option value="percent" <?= $pls === 'percent' ? 'selected' : '' ?>><?= _h('settings.stats_pls_percent') ?></option>
                            <option value="absolute" <?= $pls === 'absolute' ? 'selected' : '' ?>><?= _h('settings.stats_pls_absolute') ?></option>
                            <option value="peers_card" <?= $pls === 'peers_card' ? 'selected' : '' ?>><?= _h('settings.stats_pls_peers_card') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.stats_peer_label_style_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_livesync') ?></label>
                        <?php $lsm = ($cfg['tracker_stats_livesync_mode'] ?? 'upstream') === 'local' ? 'local' : 'upstream'; ?>
                        <select class="form-select bg-dark text-light border-secondary" name="tracker_stats_livesync_mode">
                            <option value="upstream" <?= $lsm === 'upstream' ? 'selected' : '' ?>><?= _h('settings.stats_livesync_upstream') ?></option>
                            <option value="local" <?= $lsm === 'local' ? 'selected' : '' ?>><?= _h('settings.stats_livesync_local') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.stats_livesync_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_timeout') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_timeout" value="<?= sanitize($cfg['tracker_stats_timeout'] ?? '30') ?>" min="2" max="300">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_min_loading') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_min_loading" value="<?= sanitize($cfg['tracker_stats_min_loading'] ?? '1000') ?>" min="0" max="10000" step="50">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.stats_max_loading') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_stats_max_loading" value="<?= sanitize($cfg['tracker_stats_max_loading'] ?? '1000') ?>" min="0" max="10000" step="50">
                    </div>
                </div>
                <div class="row mt-1">
                    <div class="col-12">
                        <small class="settings-hint"><?= _h('settings.stats_loading_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- Statistics Timeline -->
            <div class="settings-section" id="section-timeline" data-group="stats" data-title="<?= _h('settings.timeline_title') ?>">
                <h5><?= _h('settings.timeline_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.timeline_intro') ?></small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.timeline_enable') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_enabled">
                            <option value="1" <?= ($cfg['stats_timeline_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.stats_opt_yes') ?></option>
                            <option value="0" <?= ($cfg['stats_timeline_enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.stats_opt_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.timeline_interval') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="stats_timeline_interval" value="<?= sanitize($cfg['stats_timeline_interval'] ?? '60') ?>" min="30" max="600">
                        <small class="settings-hint"><?= _h('settings.timeline_interval_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.timeline_raw_days') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="stats_timeline_raw_days" value="<?= sanitize($cfg['stats_timeline_raw_days'] ?? '7') ?>" min="1" max="30">
                        <small class="settings-hint"><?= _h('settings.timeline_raw_days_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.timeline_keep_days') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="stats_timeline_keep_days" value="<?= sanitize($cfg['stats_timeline_keep_days'] ?? '60') ?>" min="7" max="3650">
                        <small class="settings-hint"><?= _h('settings.timeline_keep_days_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.timeline_public') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_public">
                            <option value="1" <?= ($cfg['stats_timeline_public'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.timeline_public_yes') ?></option>
                            <option value="0" <?= ($cfg['stats_timeline_public'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.timeline_public_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.timeline_public_hint') ?></small>
                    </div>
                </div>
                <?php $tlEnabledRanges = statsTimelineEnabledRanges($cfg); $tlDefaultRange = statsTimelineDefaultRange($cfg); ?>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info"><?= _h('settings.timeline_ranges_head') ?></small></div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.timeline_default_range') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_default_range">
                            <?php foreach (statsTimelineRangeButtons() as $rKey => $rLabel): ?>
                            <option value="<?= $rKey ?>" <?= $tlDefaultRange === $rKey ? 'selected' : '' ?>><?= sanitize($rLabel) ?> (<?= $rKey ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="settings-hint"><?= _h('settings.timeline_default_range_hint') ?></small>
                    </div>
                    <div class="col-md-6" data-setting="stats_timeline_ranges">
                        <label class="form-label"><?= _h('settings.timeline_buttons') ?></label>
                        <div class="tl-range-picker">
                            <?php foreach (statsTimelineRangeButtons() as $rKey => $rLabel): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input tl-range-check" type="checkbox" id="tlr-<?= $rKey ?>" value="<?= $rKey ?>" <?= in_array($rKey, $tlEnabledRanges, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="tlr-<?= $rKey ?>"><?= sanitize($rLabel) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="settings-hint"><?= _h('settings.timeline_buttons_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.timeline_custom') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="stats_timeline_custom_range">
                            <option value="0" <?= statsTimelineCustomRange($cfg) ? '' : 'selected' ?>><?= _h('settings.stats_opt_no') ?></option>
                            <option value="1" <?= statsTimelineCustomRange($cfg) ? 'selected' : '' ?>><?= _h('settings.timeline_custom_yes') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.timeline_custom_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- Interface languages — includes/lang.php -->
            <div class="settings-section" id="section-languages" data-group="languages" data-title="<?= _h('settings.languages_title') ?>">
                <h5><?= _h('settings.languages_title') ?></h5>
                <p class="settings-hint mb-3"><?= __('settings.languages_intro1') ?></p>
                <p class="settings-hint mb-3"><?= __('settings.languages_intro2') ?></p>

                <div class="row g-3 mb-3">
                    <div class="col-md-6" data-setting="default_language">
                        <label class="form-label" for="lang-default"><?= _h('settings.languages_default') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" id="lang-default"></select>
                        <div class="settings-hint"><?= __('settings.languages_default_hint') ?></div>
                    </div>
                    <div class="col-md-6" data-setting="language_auto">
                        <label class="form-label"><?= _h('settings.languages_auto') ?></label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="lang-auto">
                            <label class="form-check-label" for="lang-auto"><?= __('settings.languages_auto_check') ?></label>
                        </div>
                        <div class="settings-hint"><?= __('settings.languages_auto_hint') ?></div>
                    </div>
                </div>

                <div class="alert alert-warning py-2 wl-small d-none" id="lang-writable"><?= __('settings.languages_not_writable') ?></div>

                <div class="lang-table-wrap">
                    <table class="table table-dark table-sm align-middle lang-table">
                        <thead>
                            <tr>
                                <th><?= _h('settings.languages_col_language') ?></th>
                                <th><?= _h('settings.languages_col_completeness') ?> <span class="wl-small text-muted"><?= _h('settings.languages_col_vs') ?> <span id="lang-ref"></span></span></th>
                                <th class="lang-col-sw" title="<?= _h('settings.languages_col_offered_title') ?>"><?= _h('settings.languages_col_offered') ?></th>
                                <th class="lang-col-sw" title="<?= _h('settings.languages_col_switcher_title') ?>"><?= _h('settings.languages_col_switcher') ?></th>
                                <th class="lang-col-sw" title="<?= _h('settings.languages_col_accounts_title') ?>"><?= _h('settings.languages_col_accounts') ?></th>
                                <th class="lang-col-acts"></th>
                            </tr>
                        </thead>
                        <tbody id="lang-body"></tbody>
                    </table>
                </div>

                <div class="pc-acts mt-2">
                    <button type="button" class="btn btn-sm btn-outline-info" id="lang-add">
                        <i class="bi bi-plus-lg"></i> <?= _h('settings.languages_install') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="lang-template">
                        <i class="bi bi-download"></i> <?= _h('settings.languages_download_template') ?>
                    </button>
                </div>
            </div>

            <!-- The home page's own layout — includes/homelayout.php -->
            <div class="settings-section" id="section-home-layout" data-group="general" data-title="<?= _h('settings.home_title') ?>">
                <h5><?= _h('settings.home_title') ?></h5>
                <p class="settings-hint mb-3"><?= __('settings.home_intro') ?></p>
                <div class="pc-card" data-page="home" data-setting="home_layout">
                    <div class="pc-head">
                        <span class="pc-title"><?= _h('settings.home_front_page') ?></span>
                        <?php if (function_exists('homeLayoutIsDefault') && homeLayoutIsDefault($cfg)): ?>
                            <span class="wl-badge wl-b-muted"><?= _h('settings.home_badge_builtin') ?></span>
                        <?php else: ?>
                            <span class="wl-badge wl-b-ok"><?= _h('settings.home_badge_rearranged') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="pc-meta">
                        <?php if (function_exists('homeLayout')):
                            $hl = homeLayout($cfg);
                            $hlBits = [];
                            if ($hl['hidden']) $hlBits[] = count($hl['hidden']) === 1 ? __('settings.home_meta_hidden_one') : __('settings.home_meta_hidden_many', ['n' => count($hl['hidden'])]);
                            if ($hl['headings']) $hlBits[] = count($hl['headings']) === 1 ? __('settings.home_meta_renamed_one') : __('settings.home_meta_renamed_many', ['n' => count($hl['headings'])]);
                            if ($hl['tagline'] !== null) $hlBits[] = __('settings.home_meta_tagline');
                            if ($hl['order'] !== homeSectionKeys()) array_unshift($hlBits, __('settings.home_meta_reordered'));
                            echo $hlBits ? sanitize(ucfirst(implode(', ', $hlBits))) . '.'
                                         : _h('settings.home_meta_default');
                        endif; ?>
                    </div>
                    <div class="pc-acts">
                        <button type="button" class="btn btn-sm btn-outline-info" id="hl-open">
                            <i class="bi bi-grid-1x2"></i> <?= _h('settings.home_arrange') ?>
                        </button>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= $baseUrl ?>">
                            <i class="bi bi-box-arrow-up-right"></i> <?= _h('settings.home_view') ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Site pages the operator can rewrite — includes/pagecontent.php -->
            <div class="settings-section" id="section-pages" data-group="general" data-title="<?= _h('settings.pages_title') ?>">
                <h5><?= _h('settings.pages_heading_2') ?></h5>
                <p class="settings-hint mb-2"><?= __('settings.pages_intro1') ?></p>
                <p class="settings-hint mb-2"><?= __('settings.pages_intro2') ?></p>
                <p class="settings-hint mb-3"><?= __('settings.pages_intro3') ?></p>
                <div class="row g-3" id="pc-rows"><?php
                $pcAll   = function_exists('pageContentAll') ? pageContentAll($db) : [];
                $pcLangs = function_exists('langAvailable') ? langAvailable() : ['en' => 'English'];
                foreach (pageContentCatalog() as $pcKey => $pcMeta):
                    $pcRows = $pcAll[$pcKey] ?? [];
                    $pcLive = count(array_filter($pcRows, fn($r) => $r['enabled']));
                ?>
                    <div class="col-md-6">
                        <div class="pc-card" data-page="<?= sanitize($pcKey) ?>">
                            <div class="pc-head">
                                <span class="pc-title"><?= sanitize($pcMeta['label']) ?></span>
                                <?php if ($pcLive): ?>
                                    <span class="wl-badge wl-b-ok"><?= _h('settings.pages_badge_live') ?></span>
                                <?php elseif ($pcRows): ?>
                                    <span class="wl-badge wl-b-warn"><?= _h('settings.pages_badge_draft') ?></span>
                                <?php else: ?>
                                    <span class="wl-badge wl-b-muted"><?= _h('settings.pages_badge_builtin') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="pc-meta">
                                <?php if ($pcRows): ?>
                                    <?= _h('settings.pages_meta_written', ['n' => count($pcRows), 'total' => count($pcLangs)]) ?>
                                <?php else: ?>
                                    <?= _h('settings.pages_meta_never') ?>
                                <?php endif; ?>
                            </div>
                            <?php // One chip per installed language: click it to edit that language directly, and
                                  // the dot says whether it is live, a draft, or not written yet. ?>
                            <div class="pc-langrow">
                                <?php foreach ($pcLangs as $pcCode => $pcName):
                                    $pcOne = $pcRows[$pcCode] ?? null;
                                    $pcSt  = $pcOne ? ($pcOne['enabled'] ? 'live' : 'draft') : 'none';
                                ?>
                                <button type="button" class="pc-lang pc-edit" data-page="<?= sanitize($pcKey) ?>"
                                        data-lang="<?= sanitize($pcCode) ?>"
                                        title="<?= sanitize($pcName) ?> &mdash; <?= $pcSt === 'live' ? _h('settings.pages_lang_live') : ($pcSt === 'draft' ? _h('settings.pages_lang_draft') : _h('settings.pages_lang_none')) ?>">
                                    <span class="pc-lang-code"><?= sanitize(strtoupper($pcCode)) ?></span>
                                    <span class="pc-lang-dot pc-dot-<?= $pcSt ?>"></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="pc-acts">
                                <button type="button" class="btn btn-sm btn-outline-info pc-edit" data-page="<?= sanitize($pcKey) ?>">
                                    <i class="bi bi-pencil-square"></i> <?= _h('settings.pages_edit') ?>
                                </button>
                                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                                   href="<?= $baseUrl ?>?action=<?= sanitize($pcMeta['route']) ?>">
                                    <i class="bi bi-box-arrow-up-right"></i> <?= _h('settings.pages_view') ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?></div>
            </div>

            <!-- Observed-hash Index -->
            <div class="settings-section" id="section-index" data-group="index" data-title="<?= _h('settings.index_title') ?>">
                <h5><?= _h('settings.index_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.index_intro_1') ?> <a href="<?= $baseUrl ?>?action=admin-index"><?= _h('settings.index_intro_link') ?></a>. <?= __('settings.index_intro_2') ?></small>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_enable') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_enabled">
                            <option value="1" <?= ($cfg['index_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.index_yes') ?></option>
                            <option value="0" <?= ($cfg['index_enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.index_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.index_source_url') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="index_source_url" value="<?= sanitize($cfg['index_source_url'] ?? 'http://127.0.0.1:6969/scrape') ?>" placeholder="http://127.0.0.1:6969/scrape">
                        <small class="settings-hint"><?= __('settings.index_source_url_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_poll_interval') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_poll_minutes" value="<?= sanitize($cfg['index_poll_minutes'] ?? '30') ?>" min="5" max="1440">
                        <small class="settings-hint"><?= __('settings.index_poll_interval_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_min_seeders') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_min_seeders" value="<?= sanitize($cfg['index_min_seeders'] ?? '1') ?>" min="0" max="100000">
                        <small class="settings-hint"><?= _h('settings.index_min_seeders_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_max_rows') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_max_rows" value="<?= sanitize($cfg['index_max_rows'] ?? '200000') ?>" min="1000" max="5000000">
                        <small class="settings-hint"><?= _h('settings.index_max_rows_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_poll_budget') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_poll_budget" value="<?= sanitize($cfg['index_poll_budget'] ?? '45') ?>" min="5" max="120">
                        <small class="settings-hint"><?= _h('settings.index_poll_budget_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_grace') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_grace_days" value="<?= sanitize($cfg['index_grace_days'] ?? '3') ?>" min="1" max="90">
                        <small class="settings-hint"><?= _h('settings.index_grace_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_protect') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_protect_days" value="<?= sanitize($cfg['index_protect_days'] ?? '10') ?>" min="1" max="365">
                        <small class="settings-hint"><?= _h('settings.index_protect_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_meta_budget') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_meta_daily_budget" value="<?= sanitize($cfg['index_meta_daily_budget'] ?? '500') ?>" min="0" max="1000000">
                        <small class="settings-hint"><?= __('settings.index_meta_budget_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_meta_auto') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_meta_auto_queue">
                            <option value="1" <?= ($cfg['index_meta_auto_queue'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.index_meta_auto_yes') ?></option>
                            <option value="0" <?= ($cfg['index_meta_auto_queue'] ?? '0') === '0' ? 'selected' : '' ?>><?= _h('settings.index_meta_auto_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.index_meta_auto_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_worker_conc') ?> <small class="settings-hint"><?= _h('settings.index_worker_conc_note') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="meta_worker_concurrency" value="<?= sanitize($cfg['meta_worker_concurrency'] ?? '') ?>" min="1" max="64" placeholder="<?= _h('settings.index_worker_conc_ph') ?>">
                        <small class="settings-hint"><?= __('settings.index_worker_conc_hint') ?>
                            <details class="settings-more"><summary><?= _h('settings.index_worker_conc_more') ?></summary><?= _h('settings.index_worker_conc_more_body') ?></details></small>
                    </div>
                </div>
            </div>

            <!-- Metadata fetch order -->
            <div class="settings-section" id="section-fetch-order" data-group="index" data-title="<?= _h('settings.fetch_order_title') ?>">
                <h5><?= _h('settings.fetch_order_title') ?></h5>
<?php
require_once __DIR__ . '/../../includes/meta_order.php';
$mixDefaults = metaOrderDefaultMix();
$mixLabels   = metaOrderShareLabels();
// Which orderings the database can serve. Guarded rather than trusted: metaOrderAvailable()
// already swallows a failed query, but getDb() itself can throw, and a settings page that fatals
// because information_schema was unreadable would be a worse bug than the one being reported.
$mixOk = array_fill_keys(metaOrderMixKeys(), true);
if (function_exists('getDb')) {
    try { $mixOk = metaOrderAvailable(getDb()); } catch (\Throwable $e) { /* keep the optimistic default */ }
}
$modeNow     = (string)($cfg['meta_order_mode'] ?? 'oldest');
?>
                <small class="settings-hint d-block mb-3"><?= __('settings.fetch_order_intro') ?>
                    <details class="settings-more"><summary><?= _h('settings.fetch_order_why') ?></summary><?= __('settings.fetch_order_why_1') ?><?php foreach (metaOrderRejected() as $rk => $rv): ?> <strong><?= sanitize($rk) ?></strong> &mdash; <?= $rv ?><?php endforeach; ?> <?= __('settings.fetch_order_why_2') ?></details>
                </small>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.fetch_order_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="meta_order_mode" id="meta-order-mode">
<?php foreach (metaOrderModeLabels() as $ov => $ol): ?>
                            <option value="<?= $ov ?>" <?= $modeNow === $ov ? 'selected' : '' ?><?= empty($mixOk[$ov]) ? ' disabled' : '' ?>><?= $ol ?><?= empty($mixOk[$ov]) ? ' — ' . _h('settings.fetch_order_still_building') : '' ?></option>
<?php endforeach; ?>
                        </select>
                        <small class="settings-hint"><?= __('settings.fetch_order_wl_hint') ?></small>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label"><?= _h('settings.fetch_order_live_label') ?></label>
                        <div class="meta-order-live" id="meta-order-live">
                            <span class="settings-hint"><?= _h('settings.fetch_order_live_hint') ?>
                                <code id="meta-order-index">idx_index_meta</code>.</span>
                        </div>
                    </div>
                    <div class="col-12 meta-order-mix" id="meta-order-mix">
                        <div class="p-3 rounded border border-secondary">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <strong class="me-auto"><?= _h('settings.fetch_order_mix_title') ?></strong>
                                <span class="settings-hint" id="meta-order-sum"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="meta-order-reset"><?= _h('settings.fetch_order_mix_reset') ?></button>
                            </div>
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label class="form-label"><?= $mixLabels['whitelist'] ?><?= empty($mixOk['whitelist']) ? ' <span class="badge bg-secondary" title="' . _h('settings.fetch_order_building_title') . '">' . _h('settings.fetch_order_building') . '</span>' : '' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control bg-dark text-light border-secondary meta-order-share" data-share="whitelist" name="meta_order_mix_whitelist" value="<?= sanitize((string)($cfg['meta_order_mix_whitelist'] ?? $mixDefaults['whitelist'])) ?>" min="0" max="100" step="1">
                                        <span class="input-group-text bg-dark text-light border-secondary">%</span>
                                    </div>
                                    <small class="settings-hint meta-order-note" data-note="whitelist"></small>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label"><?= $mixLabels['seeders'] ?><?= empty($mixOk['seeders']) ? ' <span class="badge bg-secondary" title="' . _h('settings.fetch_order_building_title') . '">' . _h('settings.fetch_order_building') . '</span>' : '' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control bg-dark text-light border-secondary meta-order-share" data-share="seeders" name="meta_order_mix_seeders" value="<?= sanitize((string)($cfg['meta_order_mix_seeders'] ?? $mixDefaults['seeders'])) ?>" min="0" max="100" step="1">
                                        <span class="input-group-text bg-dark text-light border-secondary">%</span>
                                    </div>
                                    <small class="settings-hint meta-order-note" data-note="seeders"></small>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label"><?= $mixLabels['newest'] ?><?= empty($mixOk['newest']) ? ' <span class="badge bg-secondary" title="' . _h('settings.fetch_order_building_title') . '">' . _h('settings.fetch_order_building') . '</span>' : '' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control bg-dark text-light border-secondary meta-order-share" data-share="newest" name="meta_order_mix_newest" value="<?= sanitize((string)($cfg['meta_order_mix_newest'] ?? $mixDefaults['newest'])) ?>" min="0" max="100" step="1">
                                        <span class="input-group-text bg-dark text-light border-secondary">%</span>
                                    </div>
                                    <small class="settings-hint meta-order-note" data-note="newest"></small>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label"><?= $mixLabels['seen'] ?><?= empty($mixOk['seen']) ? ' <span class="badge bg-secondary" title="' . _h('settings.fetch_order_building_title') . '">' . _h('settings.fetch_order_building') . '</span>' : '' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control bg-dark text-light border-secondary meta-order-share" data-share="seen" name="meta_order_mix_seen" value="<?= sanitize((string)($cfg['meta_order_mix_seen'] ?? $mixDefaults['seen'])) ?>" min="0" max="100" step="1">
                                        <span class="input-group-text bg-dark text-light border-secondary">%</span>
                                    </div>
                                    <small class="settings-hint meta-order-note" data-note="seen"></small>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label"><?= $mixLabels['completed'] ?><?= empty($mixOk['completed']) ? ' <span class="badge bg-secondary" title="' . _h('settings.fetch_order_building_title') . '">' . _h('settings.fetch_order_building') . '</span>' : '' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control bg-dark text-light border-secondary meta-order-share" data-share="completed" name="meta_order_mix_completed" value="<?= sanitize((string)($cfg['meta_order_mix_completed'] ?? $mixDefaults['completed'])) ?>" min="0" max="100" step="1">
                                        <span class="input-group-text bg-dark text-light border-secondary">%</span>
                                    </div>
                                    <small class="settings-hint meta-order-note" data-note="completed"></small>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label"><?= $mixLabels['random'] ?><?= empty($mixOk['random']) ? ' <span class="badge bg-secondary" title="' . _h('settings.fetch_order_building_title') . '">' . _h('settings.fetch_order_building') . '</span>' : '' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control bg-dark text-light border-secondary meta-order-share" data-share="random" name="meta_order_mix_random" value="<?= sanitize((string)($cfg['meta_order_mix_random'] ?? $mixDefaults['random'])) ?>" min="0" max="100" step="1">
                                        <span class="input-group-text bg-dark text-light border-secondary">%</span>
                                    </div>
                                    <small class="settings-hint meta-order-note" data-note="random"></small>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label"><?= $mixLabels['oldest'] ?><?= empty($mixOk['oldest']) ? ' <span class="badge bg-secondary" title="' . _h('settings.fetch_order_building_title') . '">' . _h('settings.fetch_order_building') . '</span>' : '' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control bg-dark text-light border-secondary meta-order-share" data-share="oldest" name="meta_order_mix_oldest" value="<?= sanitize((string)($cfg['meta_order_mix_oldest'] ?? $mixDefaults['oldest'])) ?>" min="0" max="100" step="1">
                                        <span class="input-group-text bg-dark text-light border-secondary">%</span>
                                    </div>
                                    <small class="settings-hint meta-order-note" data-note="oldest"></small>
                                </div>
                            </div>
                            <small class="settings-hint d-block mt-2"><?= __('settings.fetch_order_mix_hint') ?>
                                <details class="settings-more"><summary><?= _h('settings.fetch_order_mix_wl_more') ?></summary><?= __('settings.fetch_order_mix_wl_more_body') ?></details>
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Index, continued -->
            <div class="settings-section" id="section-index-files" data-group="index" data-title="<?= _h('settings.index_files_title') ?>">
                <h5><?= _h('settings.index_files_title') ?></h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_files_keep') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="index_keep_files">
                            <option value="1" <?= ($cfg['index_keep_files'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.index_yes') ?></option>
                            <option value="0" <?= ($cfg['index_keep_files'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.index_files_keep_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.index_files_keep_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.index_files_poll_keep') ?> <small class="settings-hint"><?= _h('settings.index_files_days') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="index_poll_keep_days"
                               value="<?= (int)($cfg['index_poll_keep_days'] ?? 90) ?>" min="1" max="3650">
                        <small class="settings-hint">
                            <?= __('settings.index_files_poll_keep_hint_1') ?>
                            <a href="<?= $baseUrl ?>?action=admin-index"><?= _h('settings.index_files_poll_keep_link') ?></a><?= __('settings.index_files_poll_keep_hint_2') ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- OpenTracker Service -->
            <div class="settings-section" id="section-service" data-group="opentracker" data-title="<?= _h('settings.ot_title') ?>">
                <h5><?= _h('settings.ot_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.ot_intro_1') ?> <span class="text-warning"><?= _h('settings.ot_intro_orange') ?></span> <?= _h('settings.ot_intro_or') ?> <span class="text-danger"><?= _h('settings.ot_intro_red') ?></span> <?= __('settings.ot_intro_2') ?></small>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.ot_service_name') ?> <small class="settings-hint"><?= _h('settings.ot_service_name_note') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="opentracker_service_name" value="<?= sanitize($cfg['opentracker_service_name'] ?? '') ?>" placeholder="opentracker" pattern="[A-Za-z0-9._@\-]+" maxlength="128">
                        <small class="settings-hint"><?= __('settings.ot_service_name_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_sudo') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="opentracker_restart_use_sudo">
                            <option value="1" <?= ($cfg['opentracker_restart_use_sudo'] ?? '1') === '1' ? 'selected' : '' ?>><?= __('settings.ot_sudo_yes') ?></option>
                            <option value="0" <?= ($cfg['opentracker_restart_use_sudo'] ?? '1') === '0' ? 'selected' : '' ?>><?= __('settings.ot_sudo_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.ot_sudo_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_auto_reload') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="opentracker_auto_reload">
                            <option value="1" <?= ($cfg['opentracker_auto_reload'] ?? '1') === '1' ? 'selected' : '' ?>><?= __('settings.ot_auto_reload_yes') ?></option>
                            <option value="0" <?= ($cfg['opentracker_auto_reload'] ?? '1') === '0' ? 'selected' : '' ?>><?= __('settings.ot_auto_reload_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= __('settings.ot_auto_reload_hint') ?></small>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12">
                        <label class="form-label d-block"><?= _h('settings.ot_perm_test') ?></label>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-restart"><i class="bi bi-shield-check"></i> <?= _h('settings.ot_perm_test_restart') ?></button>
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-reload"><i class="bi bi-shield-check"></i> <?= _h('settings.ot_perm_test_reload') ?></button>
                        </div>
                        <small class="settings-hint d-block mt-1"><?= __('settings.ot_perm_test_hint') ?></small>
                        <div id="tracker-perm-result" class="mt-1 blacklist-result"></div>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12"><small class="text-info"><?= _h('settings.ot_thresholds') ?></small></div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_bl_warn') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_blacklist_warn_count" value="<?= sanitize($cfg['tracker_blacklist_warn_count'] ?? '1') ?>" min="1" max="1000">
                        <small class="settings-hint"><?= _h('settings.ot_bl_warn_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_bl_danger') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_blacklist_danger_count" value="<?= sanitize($cfg['tracker_blacklist_danger_count'] ?? '5') ?>" min="1" max="1000">
                        <small class="settings-hint"><?= _h('settings.ot_bl_danger_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_uptime_warn') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_uptime_warn_days" value="<?= sanitize($cfg['tracker_uptime_warn_days'] ?? '14') ?>" min="1" max="3650">
                        <small class="settings-hint"><?= _h('settings.ot_uptime_warn_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.ot_uptime_danger') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="tracker_uptime_danger_days" value="<?= sanitize($cfg['tracker_uptime_danger_days'] ?? '30') ?>" min="1" max="3650">
                        <small class="settings-hint"><?= _h('settings.ot_uptime_danger_hint') ?></small>
                    </div>
                </div>
            </div>

            <!-- UDP traffic & rate limit — includes/netlimit.php + tools/opentracker/tracker-netlimit.sh -->
            <div class="settings-section" id="section-netlimit" data-group="network" data-title="<?= _h('settings.net_title') ?>">
                <h5><?= _h('settings.net_title') ?></h5>
                <p class="settings-hint mb-2">
                    <?= __('settings.net_intro_1') ?> <a href="<?= $baseUrl ?>?action=admin-traffic#net-card"><?= _h('settings.net_intro_link') ?></a>.
                    <br>
                    <?= __('settings.net_intro_2') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_monitor') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="net_monitor_enabled">
                            <option value="0" <?= ($cfg['net_monitor_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.net_monitor_off') ?></option>
                            <option value="1" <?= ($cfg['net_monitor_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= __('settings.net_monitor_on') ?></option>
                        </select>
                        <small class="settings-hint">
                            <?= __('settings.net_monitor_hint') ?>
                        </small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_sample') ?> <small class="settings-hint"><?= _h('settings.net_seconds') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_sample_seconds" value="<?= (int)netlimitSampleSeconds($cfg) ?>" min="<?= NET_SAMPLE_MIN ?>" max="<?= NET_SAMPLE_MAX ?>">
                        <small class="settings-hint"><?= _h('settings.net_sample_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_keep') ?> <small class="settings-hint"><?= _h('settings.index_files_days') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_keep_days" value="<?= (int)netlimitKeepDays($cfg) ?>" min="<?= NET_KEEP_MIN ?>" max="<?= NET_KEEP_MAX ?>">
                        <small class="settings-hint"><?= __('settings.net_keep_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_port') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_limit_port" value="<?= (int)netlimitPort($cfg) ?>" min="1" max="65535">
                        <small class="settings-hint"><?= __('settings.net_port_hint') ?></small>
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?= _h('settings.net_trusted') ?> <small class="settings-hint"><?= _h('settings.net_trusted_note') ?></small></label>
                        <textarea class="form-control bg-dark text-light border-secondary" name="net_limit_trusted" rows="2" placeholder="203.0.113.10, 198.51.100.0/24, 2001:db8::/32"><?= sanitize($cfg['net_limit_trusted'] ?? '') ?></textarea>
<?php $trOk = function_exists('netlimitTrusted') ? netlimitTrusted($cfg) : []; $trBad = function_exists('netlimitTrustedRejected') ? netlimitTrustedRejected($cfg) : []; ?>
                        <small class="settings-hint">
                            <?= __('settings.net_trusted_hint') ?>
                            <?php if ($trOk): ?><span class="text-info"><?= _h('settings.net_in_force', ['n' => count($trOk)]) ?> — <code><?= sanitize(implode(', ', array_slice($trOk, 0, 6))) ?><?= count($trOk) > 6 ? ' …' : '' ?></code>.</span><?php endif; ?>
                            <?php if ($trBad): ?><span class="text-warning"><?= _h('settings.net_not_address') ?> <code><?= sanitize(implode(', ', array_slice($trBad, 0, 4))) ?></code>.</span><?php endif; ?>
                            <details class="settings-more"><summary><?= _h('settings.net_trusted_more') ?></summary><?= __('settings.net_trusted_more_body', ['max' => NET_TRUSTED_MAX]) ?></details>
                        </small>
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?= _h('settings.net_blocked') ?> <small class="settings-hint"><?= _h('settings.net_blocked_note') ?></small></label>
                        <textarea class="form-control bg-dark text-light border-secondary" name="net_limit_blocked" rows="2" placeholder="5.188.1.7, 45.9.148.0/24, 2a02:c207::/32"><?= sanitize($cfg['net_limit_blocked'] ?? '') ?></textarea>
<?php $blOk = function_exists('netlimitBlocked') ? netlimitBlocked($cfg) : []; $blBad = function_exists('netlimitBlockedRejected') ? netlimitBlockedRejected($cfg) : []; ?>
                        <small class="settings-hint">
                            <?= __('settings.net_blocked_hint') ?>
                            <?php if ($blOk): ?><span class="text-info"><?= _h('settings.net_in_force', ['n' => count($blOk)]) ?> — <code><?= sanitize(implode(', ', array_slice($blOk, 0, 6))) ?><?= count($blOk) > 6 ? ' …' : '' ?></code>.</span><?php endif; ?>
                            <?php if ($blBad): ?><span class="text-warning"><?= _h('settings.net_not_address') ?> <code><?= sanitize(implode(', ', array_slice($blBad, 0, 4))) ?></code>.</span><?php endif; ?>
                            <details class="settings-more"><summary><?= _h('settings.net_blocked_more') ?></summary>
                                <?= __('settings.net_blocked_more_1') ?>
                                <a href="<?= $baseUrl ?>?action=admin-traffic#iplists-card"><?= _h('settings.net_intro_link') ?></a> <?= __('settings.net_blocked_more_2', ['max' => NET_TRUSTED_MAX]) ?>
                            </details>
                        </small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-netlimit-throttle"><?= _h('settings.net_throttle_heading') ?> <small class="settings-hint fw-normal"><?= _h('settings.net_throttle_heading_sub') ?></small></h6>
                <p class="settings-hint mb-2">
                    <?= __('settings.net_throttle_intro') ?> <a href="<?= $baseUrl ?>?action=admin-traffic#net-card"><?= _h('settings.net_throttle_intro_link') ?></a><?= _h('settings.net_throttle_intro_after') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_limit_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="net_limit_enabled">
                            <option value="0" <?= ($cfg['net_limit_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.net_limit_off') ?></option>
                            <option value="1" <?= ($cfg['net_limit_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.net_limit_on') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_pps_label') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_limit_pps" value="<?= (int)netlimitPps($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint"><?= _h('settings.net_pps_hint', ['min' => NET_PPS_MIN, 'max' => NET_PPS_MAX]) ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_burst_label') ?> <small class="settings-hint"><?= _h('settings.net_burst_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_limit_burst" value="<?= (int)netlimitBurst($cfg) ?>" min="<?= NET_BURST_MIN ?>" max="<?= NET_BURST_MAX ?>">
                        <small class="settings-hint"><?= _h('settings.net_burst_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label d-block"><?= _h('settings.net_avail_test_label') ?></label>
                        <button type="button" class="btn btn-outline-info btn-sm w-100" id="btn-test-netlimit"><i class="bi bi-shield-check"></i> <?= _h('settings.net_avail_test_btn') ?></button>
                        <small class="settings-hint d-block mt-1"><?= __('settings.net_avail_test_hint') ?></small>
                    </div>
                    <div class="col-12">
                        <div id="netlimit-result" class="blacklist-result"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.net_cmd_label') ?> <small class="settings-hint"><?= _h('settings.net_cmd_label_sub') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="net_limit_cmd" value="<?= sanitize($cfg['net_limit_cmd'] ?? NET_DEFAULT_CMD) ?>" placeholder="<?= _h('settings.net_cmd_ph', ['cmd' => NET_DEFAULT_CMD]) ?>" maxlength="255">
                        <small class="settings-hint"><?= __('settings.net_cmd_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.net_install_once') ?></label>
                        <pre class="settings-code mb-0"><code>sudo install -m 0755 tools/opentracker/tracker-netlimit.sh /usr/local/sbin/
echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-netlimit.sh' \
  | sudo tee /etc/sudoers.d/tracker-netlimit
sudo chmod 440 /etc/sudoers.d/tracker-netlimit</code></pre>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-netlimit-auto"><?= _h('settings.net_auto_heading') ?> <small class="settings-hint fw-normal"><?= _h('settings.net_auto_heading_sub') ?></small></h6>
                <p class="settings-hint mb-2">
                    <?= __('settings.net_auto_intro') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_auto_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="net_auto_enabled">
                            <option value="0" <?= ($cfg['net_auto_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.net_auto_off') ?></option>
                            <option value="1" <?= ($cfg['net_auto_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.net_auto_on') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_auto_target_label') ?> <small class="settings-hint"><?= _h('settings.net_auto_target_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_target" value="<?= (int)netlimitAutoTarget($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint"><?= _h('settings.net_auto_target_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_auto_min_label') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_min" value="<?= (int)netlimitAutoMin($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint"><?= _h('settings.net_auto_min_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_auto_max_label') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_max" value="<?= (int)netlimitAutoMax($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000">
                        <small class="settings-hint"><?= _h('settings.net_auto_max_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.net_auto_cpu_label') ?> <small class="settings-hint"><?= _h('settings.net_auto_cpu_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_auto_target_cpu" value="<?= (int)netlimitAutoTargetCpu($cfg) ?>" min="10" max="100">
                        <small class="settings-hint">
                            <?= _h('settings.net_auto_cpu_hint') ?>
                            <?php $nlCpus = netlimitCpuCount(); if ($nlCpus > 0): ?><?= __('settings.net_auto_cpu_cores_hint', ['cores' => (int)$nlCpus, 'pct' => (int)netlimitAutoTargetCpu($cfg), 'load' => number_format($nlCpus * netlimitAutoTargetCpu($cfg) / 100, 1)]) ?><?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Address lists — includes/iplist.php -->
            <div class="settings-section" id="section-iplists" data-group="tracker" data-title="<?= _h('settings.iplists_heading') ?>">
                <h5><?= _h('settings.iplists_heading') ?></h5>
                <p class="settings-hint mb-2">
                    <?= __('settings.iplists_intro') ?> <a href="<?= $baseUrl ?>?action=admin-traffic#section-iplists-card"><?= _h('settings.iplists_intro_link') ?></a> <?= _h('settings.iplists_intro_after') ?>
                </p>
                <p class="settings-hint mb-2">
                    <?= __('settings.iplists_kinds') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.iplists_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="net_lists_enabled">
                            <option value="0" <?= ($cfg['net_lists_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.iplists_off') ?></option>
                            <option value="1" <?= ($cfg['net_lists_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.iplists_on') ?></option>
                        </select>
                        <small class="settings-hint">
                            <?= _h('settings.iplists_enabled_hint') ?>
                        </small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.iplists_ttl_label') ?> <small class="settings-hint"><?= _h('settings.iplists_ttl_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="net_lists_ttl_default"
                               value="<?= (int)($cfg['net_lists_ttl_default'] ?? IPLIST_TTL_DEFAULT) ?>" min="<?= IPLIST_TTL_MIN ?>" max="<?= IPLIST_TTL_MAX ?>" step="15">
                        <small class="settings-hint">
                            <?= _h('settings.iplists_ttl_hint') ?>
                        </small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.iplists_capacity_label') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" value="<?= _h('settings.iplists_capacity_value', ['n' => number_format(IPLIST_MAX_TOTAL)]) ?>" readonly disabled>
                        <small class="settings-hint">
                            <?= __('settings.iplists_capacity_hint') ?>
                            <a href="<?= $baseUrl ?>?action=admin-traffic#iplists-card"><?= _h('settings.iplists_capacity_hint_link') ?></a>.
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
            <div class="settings-section" id="section-backups" data-group="maintenance" data-title="<?= _h('settings.backups_heading') ?>">
                <h5><?= _h('settings.backups_heading') ?></h5>
                <p class="settings-hint mb-2">
                    <?= __('settings.backups_intro') ?>
                    <a href="<?= $baseUrl ?>?action=admin-backups"><?= _h('settings.backups_intro_link') ?></a><?= __('settings.backups_intro_after') ?>
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.backups_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="backup_enabled">
                            <option value="0" <?= ($cfg['backup_enabled'] ?? '0') !== '1' ? 'selected' : '' ?>><?= _h('settings.backups_off') ?></option>
                            <option value="1" <?= ($cfg['backup_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= _h('settings.backups_on') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.backups_enabled_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.backups_dir_label') ?> <small class="settings-hint"><?= __('settings.backups_dir_label_sub') ?></small></label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_dir" value="<?= sanitize(backupDir($cfg)) ?>" placeholder="<?= BACKUP_DEFAULT_DIR ?>">
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-backup-dir"><?= _h('settings.backups_dir_test_btn') ?></button>
                        </div>
                        <small class="settings-hint"><?= _h('settings.backups_dir_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.backups_db_label') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_db_name" value="<?= sanitize(backupDbName($cfg)) ?>" placeholder="tracker" pattern="[A-Za-z0-9_]+" maxlength="64">
                        <small class="settings-hint"><?= __('settings.backups_db_hint') ?></small>
                    </div>
                    <div class="col-12">
                        <div id="backup-dir-result" class="blacklist-result"></div>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-what"><?= _h('settings.backups_what_heading') ?></h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.backups_profile_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="backup_profile" id="backup-profile">
                            <?php foreach (BACKUP_PROFILES as $p): ?>
                            <option value="<?= sanitize($p) ?>" <?= backupProfile($cfg) === $p ? 'selected' : '' ?>><?= sanitize(backupProfileLabel($p)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="settings-hint">
                            <?= __('settings.backups_profile_hint') ?>
                        </small>
                    </div>
                    <div class="col-md-8" data-setting="backup_items" id="backup-items-cell">
                        <label class="form-label"><?= _h('settings.backups_custom_label') ?> <small class="settings-hint"><?= _h('settings.backups_custom_label_sub') ?></small></label>
                        <div class="bk-item-grid">
                            <?php foreach (backupTrackerItems() as $it): ?>
                            <div class="form-check">
                                <input class="form-check-input bk-item-check" type="checkbox" id="bk-set-<?= sanitize($it) ?>" value="<?= sanitize($it) ?>" <?= in_array($it, $bkItemsSel, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bk-set-<?= sanitize($it) ?>"><code><?= sanitize($it) ?></code></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="backup_items" id="backup-items-json" value="<?= sanitize($bkItems) ?>">
                        <small class="settings-hint"><?= __('settings.backups_custom_hint') ?></small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-when"><?= _h('settings.backups_when_heading') ?></h6>
                <div class="row g-3">
                    <div class="col-12" data-setting="backup_schedule">
                        <label class="form-label"><?= _h('settings.backups_auto_label') ?></label>
                        <div class="bk-sched-row">
                            <?php foreach (BACKUP_DAY_LABELS as $d => $label): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input bk-day-check" type="checkbox" id="bk-day-<?= $d ?>" value="<?= $d ?>" <?= in_array($d, $bkDays, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bk-day-<?= $d ?>"><?= $label ?></label>
                            </div>
                            <?php endforeach; ?>
                            <span class="bk-sched-at"><?= _h('settings.backups_sched_at') ?></span>
                            <input type="time" class="form-control form-control-sm bg-dark text-light border-secondary bk-sched-time" id="bk-sched-time" value="<?= sanitize($bkTime) ?>" step="60">
                        </div>
                        <input type="hidden" name="backup_schedule" id="backup-schedule-json" value="<?= sanitize((string)($cfg['backup_schedule'] ?? '')) ?>">
                        <small class="settings-hint">
                            <?= _h('settings.backups_sched_hint') ?>
                            <span id="bk-sched-summary" class="d-block mt-1"><?= sanitize(backupScheduleDescribe((string)($cfg['backup_schedule'] ?? ''), $bkTz)) ?></span>
                        </small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.backups_tz_label') ?></label>
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
                        <label class="form-label"><?= _h('settings.backups_nice_label') ?> <small class="settings-hint"><?= _h('settings.backups_nice_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_nice" value="<?= (int)backupNice($cfg) ?>" min="0" max="19">
                        <small class="settings-hint"><?= _h('settings.backups_nice_hint') ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.backups_verify_label') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="backup_verify_after">
                            <option value="1" <?= ($cfg['backup_verify_after'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.backups_verify_yes') ?></option>
                            <option value="0" <?= ($cfg['backup_verify_after'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.backups_verify_no') ?></option>
                        </select>
                        <small class="settings-hint"><?= _h('settings.backups_verify_hint') ?></small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-keep"><?= _h('settings.backups_keep_heading') ?></h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.backups_keep_label') ?> <small class="settings-hint"><?= _h('settings.backups_keep_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_keep" value="<?= (int)backupKeep($cfg) ?>" min="0" max="<?= BACKUP_KEEP_MAX ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.backups_keep_days_label') ?> <small class="settings-hint"><?= _h('settings.backups_keep_days_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_keep_days" value="<?= (int)backupKeepDays($cfg) ?>" min="0" max="<?= BACKUP_DAYS_MAX ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.backups_max_gb_label') ?> <small class="settings-hint"><?= _h('settings.backups_max_gb_label_sub') ?></small></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="backup_max_size_gb" value="<?= (int)backupMaxGb($cfg) ?>" min="0" max="<?= BACKUP_GB_MAX ?>">
                        <small class="settings-hint"><?= _h('settings.backups_max_gb_hint') ?></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.backups_gpg_label') ?> <small class="settings-hint"><?= _h('settings.backups_gpg_label_sub') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_gpg_recipient" value="<?= sanitize(backupGpgRecipient($cfg)) ?>" placeholder="backup@example.org" maxlength="128">
                        <small class="settings-hint">
                            <?= __('settings.backups_gpg_hint') ?>
                        </small>
                    </div>
                </div>

                <h6 class="mt-4 mb-1" id="section-backups-tools"><?= _h('settings.backups_tools_heading') ?></h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.backups_cmd_label') ?> <small class="settings-hint"><?= _h('settings.backups_cmd_label_sub') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_cmd" value="<?= sanitize($cfg['backup_cmd'] ?? BACKUP_DEFAULT_CMD) ?>" placeholder="<?= _h('settings.backups_cmd_ph', ['cmd' => BACKUP_DEFAULT_CMD]) ?>" maxlength="255">
                        <small class="settings-hint"><?= __('settings.backups_cmd_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= __('settings.backups_script_label') ?> <small class="settings-hint"><?= _h('settings.backups_script_label_sub') ?></small></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="backup_script_path" value="<?= sanitize($cfg['backup_script_path'] ?? BACKUP_DEFAULT_SCRIPT) ?>" placeholder="<?= BACKUP_DEFAULT_SCRIPT ?>" maxlength="255">
                        <small class="settings-hint"><?= _h('settings.backups_script_hint') ?></small>
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?= _h('settings.net_install_once') ?></label>
                        <pre class="settings-code mb-0"><code>sudo install -m 0755 tools/opentracker/tracker-backup.sh /usr/local/sbin/
echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-backup.sh' \
  | sudo tee /etc/sudoers.d/tracker-backup
sudo chmod 440 /etc/sudoers.d/tracker-backup
sudo install -d -m 0700 <?= sanitize(backupDir($cfg)) ?></code></pre>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="settings-section" id="section-footer" data-group="general" data-title="<?= _h('settings.footer_heading') ?>">
                <h5><?= _h('settings.footer_heading') ?></h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.footer_start_year') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="footer_start_year" value="<?= sanitize($cfg['footer_start_year'] ?? date('Y')) ?>" min="2020" max="2099">
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12"><small class="text-info"><?= _h('settings.footer_el1') ?></small></div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.footer_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="footer_brand_enabled">
                            <option value="1" <?= ($cfg['footer_brand_enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.footer_yes') ?></option>
                            <option value="0" <?= ($cfg['footer_brand_enabled'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.footer_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><?= _h('settings.footer_name') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_brand_name" value="<?= sanitize($cfg['footer_brand_name'] ?? 'TryHackX') ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><?= _h('settings.footer_url') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_brand_url" value="<?= sanitize($cfg['footer_brand_url'] ?? '') ?>">
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12"><small class="text-info"><?= _h('settings.footer_el2') ?></small></div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.footer_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="footer_tracker_enabled">
                            <option value="1" <?= ($cfg['footer_tracker_enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.footer_yes') ?></option>
                            <option value="0" <?= ($cfg['footer_tracker_enabled'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.footer_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.footer_name') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_name" value="<?= sanitize($cfg['footer_tracker_name'] ?? 'OpenTracker') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.footer_url') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_url" value="<?= sanitize($cfg['footer_tracker_url'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.footer_author') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_author" value="<?= sanitize($cfg['footer_tracker_author'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.footer_author_url') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_tracker_author_url" value="<?= sanitize($cfg['footer_tracker_author_url'] ?? '') ?>">
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12"><small class="text-info"><?= _h('settings.footer_element3') ?></small></div>
                    <div class="col-md-2">
                        <label class="form-label"><?= _h('settings.footer_os_enabled') ?></label>
                        <select class="form-select bg-dark text-light border-secondary" name="footer_os_enabled">
                            <option value="1" <?= ($cfg['footer_os_enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('settings.footer_os_yes') ?></option>
                            <option value="0" <?= ($cfg['footer_os_enabled'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('settings.footer_os_no') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.footer_os_name') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_os_name" value="<?= sanitize($cfg['footer_os_name'] ?? 'Debian') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('settings.footer_os_url') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="footer_os_url" value="<?= sanitize($cfg['footer_os_url'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= _h('settings.footer_os_since_year') ?></label>
                        <input type="number" class="form-control bg-dark text-light border-secondary" name="footer_os_since_year" value="<?= sanitize($cfg['footer_os_since_year'] ?? date('Y')) ?>" min="2000" max="2099">
                    </div>
                </div>
            </div>

            <div id="settings-alert" class="mt-2 mb-2"></div>
            <div class="mt-3 mb-4" id="settings-save-row">
                <button type="submit" class="btn btn-primary"><?= _h('settings.save_settings') ?></button>
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
        <div class="settings-section" id="section-credentials" data-group="credentials" data-title="<?= _h('settings.account_title') ?>">
            <h5><?= _h('settings.account_title') ?></h5>
            <?php if ($adminEmailPending): ?>
            <div class="alert alert-info py-2 px-3" id="admin-email-pending">
                <i class="bi bi-hourglass-split"></i> <?= _h('settings.account_email_change_to') ?>
                <strong><?= $adminEmailPending['pending_email'] === '' ? _h('settings.account_email_removal') : sanitize($adminEmailPending['pending_email']) ?></strong>
                <?= __('settings.account_email_waiting', ['stage' => $adminEmailPending['stage'] === 'old' ? __('settings.account_email_stage_current') : __('settings.account_email_stage_new')]) ?>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="btn-admin-email-cancel"><?= _h('settings.account_email_cancel_change') ?></button>
            </div>
            <?php endif; ?>
            <form id="password-form">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.account_username') ?></label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" name="admin_username" autocomplete="username" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>">
                        <small class="settings-hint"><?= _h('settings.account_username_hint') ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.account_current_password') ?></label>
                        <input type="password" class="form-control bg-dark text-light border-secondary" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.account_email') ?>
                            <?php if ($adminAccount): ?><span class="badge <?= $adminEmailVerified ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $adminEmailVerified ? _h('settings.account_email_verified') : _h('settings.account_email_unverified') ?></span><?php endif; ?>
                        </label>
                        <input type="email" class="form-control bg-dark text-light border-secondary" name="admin_email" maxlength="190" autocomplete="off"
                               value="<?= sanitize($adminEmail) ?>" placeholder="<?= $adminAccount ? _h('settings.account_email_ph') : _h('settings.account_email_ph_none') ?>" <?= $adminAccount ? '' : 'disabled' ?>>
                        <small class="settings-hint">
                            <?php if ($adminAccount): ?>
                            <?= __('settings.account_email_hint1', ['username' => sanitize($adminAccount['username'])]) ?>
                            <?= __('settings.account_email_hint2') ?>
                            <?= __('settings.account_email_hint3') ?>
                            <?= $adminEmailCooldown > 0 ? _h('settings.account_email_cooldown', ['days' => (int)$adminEmailCooldown]) : '' ?>
                            <?php else: ?>
                            <?= __('settings.account_email_no_account') ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.account_new_password') ?> <small style="color: #a0a0b0;"><?= _h('settings.account_new_password_rules') ?></small></label>
                        <input type="password" class="form-control bg-dark text-light border-secondary" name="new_password" minlength="10" autocomplete="new-password" placeholder="<?= _h('settings.account_new_password_ph') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= _h('settings.account_confirm_password') ?></label>
                        <input type="password" class="form-control bg-dark text-light border-secondary" name="confirm_password" minlength="10" autocomplete="new-password">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-outline-warning"><?= _h('settings.account_save') ?></button>
                </div>
            </form>
            <div id="password-alert" class="mt-2"></div>
        </div>

            <!-- Two-factor authentication -->
            <div class="settings-section" id="section-2fa" data-group="credentials" data-title="<?= _h('settings.twofa_title') ?>">
                <h5><?= _h('settings.twofa_title') ?></h5>
                <small class="settings-hint d-block mb-3"><?= __('settings.twofa_hint') ?></small>
                <!-- data-setting marks a setting the search should find even though this block is
                     rendered by JavaScript rather than being a form field: admin_2fa_enabled is a
                     mirror of config/admin_2fa.json and no form post may change it. -->
                <div id="tf-panel" data-setting="admin_2fa_enabled">
                    <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= _h('settings.twofa_reading') ?></div>
                </div>
            </div>

        </div><!-- /.settings-narrow -->
    </div>

    <!-- Settings Save Confirmation Modal -->
    <div class="modal fade" id="settingsConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <?= _h('settings.confirm_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-3" style="font-size:0.9rem;" id="settings-confirm-body"><?= _h('settings.confirm_body') ?></p>
                    <form id="settings-confirm-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('settings.confirm_password') ?></label>
                            <?php // For the browser's password manager: this form has a password field, so without a named
                                  // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                            <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                            <input type="password" autocomplete="current-password" class="form-control bg-dark text-light border-secondary" id="settings-confirm-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('settings.confirm_cancel') ?></button>
                            <button type="submit" class="btn btn-warning btn-sm text-dark"><i class="bi bi-check-lg"></i> <?= _h('settings.confirm_submit') ?></button>
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
    <!-- Copy a language to a free code. The shipped two are never overwritten by an upload, so this
         is how a customised English or Polish wording is made. -->
    <div class="modal fade" id="langDupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-files text-info"></i> <?= _h('settings.langdup_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="wl-small text-muted"><?= _h('settings.langdup_intro_before') ?> <strong id="ld-source">—</strong> <?= __('settings.langdup_intro_after') ?></p>
                    <div class="mb-2">
                        <label class="form-label wl-small" for="ld-code"><?= _h('settings.langdup_code') ?></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                               id="ld-code" maxlength="3" autocomplete="off" spellcheck="false" placeholder="de">
                        <div class="settings-hint"><?= __('settings.langdup_code_hint') ?></div>
                    </div>
                    <div class="alert alert-danger py-2 wl-small d-none" id="ld-msg"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('settings.langdup_cancel') ?></button>
                    <button type="button" class="btn btn-sm btn-info" id="ld-submit"><?= _h('settings.langdup_submit') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Install from a JSON file. JSON and not PHP on purpose: a lang/*.php is `require`d on every
         request, so accepting one as an upload would be a way to put code on the include path. -->
    <div class="modal fade" id="langUploadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-translate text-info"></i> <?= _h('settings.langup_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="lang-step">
                        <span class="lang-step-num">1</span>
                        <div class="lang-step-body">
                            <h6><?= _h('settings.langup_step1') ?></h6>
                            <p class="wl-small text-muted mb-0"><?= _h('settings.langup_step1_text') ?></p>
                        </div>
                    </div>
                    <div class="lang-step">
                        <span class="lang-step-num">2</span>
                        <div class="lang-step-body">
                            <h6><?= _h('settings.langup_step2') ?></h6>
                            <?php // The panel's own dropdown skin (.wl-dd-menu), the one the whitelist and index
                                  // toolbars use. A scrollable list beats a wall of chips: it is ordered, it is
                                  // keyboard-navigable, and it does not grow the dialog as languages are added. ?>
                            <div class="lang-pick">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                            id="lu-pick" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                        <span id="lu-pick-label"><?= _h('settings.langup_pick') ?></span>
                                    </button>
                                    <ul class="dropdown-menu wl-dd-menu lang-dd-scroll" id="lu-menu" aria-labelledby="lu-pick"></ul>
                                </div>
                                <span class="wl-small text-muted"><?= _h('settings.langup_or_code') ?></span>
                                <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary lang-code-input"
                                       id="lu-code" maxlength="3" autocomplete="off" spellcheck="false" placeholder="de">
                            </div>
                            <div class="wl-small mt-1" id="lu-code-name"></div>
                        </div>
                    </div>
                    <div class="lang-step">
                        <span class="lang-step-num">3</span>
                        <div class="lang-step-body">
                            <h6><?= _h('settings.langup_step3') ?></h6>
                            <?php // The same drop zone the address-list import uses (.ipl-drop): the real
                                  // <input type=file> stays in the DOM, invisible, covering a label-shaped box
                                  // that also takes a dropped file. Not a second component. ?>
                            <div class="ipl-drop" id="lu-drop" tabindex="0" role="button" aria-label="<?= _h('settings.langup_drop_aria') ?>">
                                <i class="bi bi-file-earmark-arrow-up ipl-drop-icon"></i>
                                <span class="ipl-drop-main"><u><?= _h('settings.langup_drop_choose') ?></u> <?= _h('settings.langup_drop_or') ?></span>
                                <span class="ipl-drop-sub"><?= _h('settings.langup_drop_sub') ?></span>
                                <input type="file" id="lu-file" class="ipl-drop-input" accept=".json,application/json">
                            </div>
                            <div class="wl-small text-muted mt-1" id="lu-file-info"></div>
                        </div>
                    </div>
                    <div class="alert alert-danger py-2 wl-small d-none" id="lu-msg"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('settings.langup_cancel') ?></button>
                    <button type="button" class="btn btn-sm btn-info" id="lu-submit"><?= _h('settings.langup_submit') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- The home page arranger. Drag to reorder, and buttons that do the same thing: native HTML5
         drag-and-drop does not work on a touch screen at all and cannot be driven from a keyboard,
         so a list that ONLY drags is a list some people cannot use. -->
    <div class="modal fade" id="homeLayoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-grid-1x2 text-info"></i> <?= _h('settings.homelayout_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="pc-note wl-small" id="hl-note"></div>
                    <div class="hl-tagline">
                        <label class="form-label wl-small mb-1" for="hl-tagline"><?= _h('settings.homelayout_tagline') ?></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                               id="hl-tagline" maxlength="80" spellcheck="false">
                    </div>
                    <div class="hl-list" id="hl-list"></div>
                    <div class="alert alert-danger py-2 wl-small d-none mt-2" id="hl-error"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary me-auto" id="hl-reset">
                        <i class="bi bi-arrow-counterclockwise"></i> <?= _h('settings.homelayout_reset') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" id="hl-add">
                        <i class="bi bi-plus-lg"></i> <?= _h('settings.homelayout_add') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('settings.homelayout_close') ?></button>
                    <button type="button" class="btn btn-sm btn-info" id="hl-save"><?= _h('settings.homelayout_save') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- The page editor. A modal rather than an inline block: a Terms page is a page, and it needs
         the width and the live preview beside it that a settings row cannot give. -->
    <div class="modal fade" id="pageEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-text text-info"></i> <span id="pc-title"><?= _h('settings.pages_editor_title') ?></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="pc-langs" id="pc-langs"></div>
                    <div class="pc-serving wl-small" id="pc-serving"></div>
                    <div class="pc-bar">
                        <div class="pc-bar-left">
                            <label class="form-label wl-small mb-0" for="pc-format"><?= _h('settings.pages_format') ?></label>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary" id="pc-format">
                                <option value="markdown"><?= _h('settings.pages_format_markdown') ?></option>
                                <option value="bbcode"><?= _h('settings.pages_format_bbcode') ?></option>
                            </select>
                            <div class="form-check form-switch mb-0 ms-2">
                                <input class="form-check-input" type="checkbox" id="pc-enabled">
                                <label class="form-check-label wl-small" for="pc-enabled"><?= _h('settings.pages_use_mine') ?></label>
                            </div>
                        </div>
                        <div class="pc-bar-right">
                            <span class="wl-small text-muted" id="pc-count"></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="pc-restore">
                                <i class="bi bi-arrow-counterclockwise"></i> <?= _h('settings.pages_restore') ?>
                            </button>
                        </div>
                    </div>
                    <div class="pc-note wl-small" id="pc-note"></div>
                    <div class="pc-placeholders" id="pc-placeholders" hidden></div>
                    <div class="pc-split">
                        <div class="pc-pane">
                            <div class="pc-pane-head"><?= _h('settings.pages_your_text') ?></div>
                            <textarea class="form-control bg-dark text-light border-secondary pc-text" id="pc-body" spellcheck="false"></textarea>
                        </div>
                        <div class="pc-pane">
                            <div class="pc-pane-head"><?= _h('settings.pages_preview') ?></div>
                            <?php // An iframe, because the preview has to look like the PUBLIC page and the panel's stylesheet is
                                  // not the public one: the stats widget, the announce box and the type scale all live in
                                  // style.css. The frame loads that sheet and nothing else; the markup comes from the same
                                  // richtextRender() call the public page makes. ?>
                            <iframe class="pc-preview" id="pc-preview" title="<?= _h('settings.pages_preview_frame') ?>" sandbox="allow-same-origin"
                                    data-css="<?= $baseUrl ?>assets/css/style.css<?= assetVer('assets/css/style.css') ?>"
                                    data-base="<?= sanitize($baseUrl) ?>"></iframe>
                        </div>
                    </div>
                    <div class="alert alert-danger py-2 wl-small d-none mt-2" id="pc-error"></div>
                </div>
                <div class="modal-footer">
                    <span class="wl-small text-muted me-auto" id="pc-saved"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('settings.pages_close') ?></button>
                    <button type="button" class="btn btn-sm btn-info" id="pc-save"><?= _h('settings.pages_save') ?></button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= $baseUrl ?>assets/js/admin-settings.js<?= assetVer('assets/js/admin-settings.js') ?>"></script>
    <!-- admin-common.js only defines window.AdminCommon (apiCall / el / showToast) and adds no globals
         of its own, so it can join this page without colliding with the inline script above. Without
         it admin-twofa.js returns immediately and the section sits on "Reading…" for ever. -->
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-twofa.js<?= assetVer('assets/js/admin-twofa.js') ?>"></script>
    <!-- AFTER admin-common.js, which on this page is loaded below admin-settings.js: the editor
         needs window.AdminCommon and returned early without it, so the dialog never opened. -->
    <script src="<?= $baseUrl ?>assets/js/admin-languages.js<?= assetVer('assets/js/admin-languages.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-homelayout.js<?= assetVer('assets/js/admin-homelayout.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-pagecontent.js<?= assetVer('assets/js/admin-pagecontent.js') ?>"></script>
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
            } catch { return { error: t('js.settings.network_error') }; }
        };
        async function loadPeers() {
            const json = await call('admin/fetch_fed_peers');
            body.innerHTML = '';
            if (json.error) { body.appendChild(el('tr')).appendChild(el('td', json.error, 'text-danger')).colSpan = 8; return; }
            if (!json.peers.length) {
                const td = el('td', t('js.settings.no_peers'), 'text-muted');
                td.colSpan = 8;
                body.appendChild(el('tr')).appendChild(td);
            }
            json.peers.forEach(p => {
                const tr = el('tr');
                tr.appendChild(el('td', p.name));
                tr.appendChild(el('td', p.base_url, 'text-break'));
                tr.appendChild(el('td', p.pull_enabled ? (p.has_bearer ? t('js.settings.yes') : t('js.settings.yes_no_bearer')) : t('js.settings.no'), p.pull_enabled && !p.has_bearer ? 'text-warning' : ''));
                tr.appendChild(el('td', p.api_client_id ? ((p.client_key_id || '?') + (p.client_enabled === 0 ? t('js.settings.disabled_suffix') : '')) : '—', p.client_enabled === 0 ? 'text-warning' : ''));
                tr.appendChild(el('td', p.last_pull_at || t('js.settings.never'), 'text-muted'));
                tr.appendChild(el('td', String(p.rows_imported || 0)));
                tr.appendChild(el('td', p.last_status || '—', 'text-muted small'));
                const act = el('td');
                act.className = 'text-nowrap';
                const testBtn = el('button', t('js.settings.test'), 'btn btn-sm btn-outline-info me-1');
                testBtn.type = 'button';
                testBtn.addEventListener('click', async () => {
                    testBtn.disabled = true;
                    const r = await call('admin/fed_peer_test', { id: p.id });
                    testBtn.disabled = false;
                    if (r.success) note(t('js.settings.peer_test_ok', { peer: p.name, node: r.reply.node || '?', state: r.reply.export_enabled ? t('js.settings.on') : t('js.settings.off'), rows: (r.reply.exportable_rows || 0).toLocaleString() }), 'alert-success');
                    else note(t('js.settings.test_failed', { error: r.error || '?' }), 'alert-danger');
                });
                const togglePull = el('button', p.pull_enabled ? t('js.settings.pull_off') : t('js.settings.pull_on'), 'btn btn-sm btn-outline-secondary me-1');
                togglePull.type = 'button';
                togglePull.addEventListener('click', async () => {
                    const r = await call('admin/fed_peer_save', { id: p.id, name: p.name, base_url: p.base_url, pull_enabled: p.pull_enabled ? 0 : 1, pull_files: p.pull_files });
                    if (r.error) note(r.error, 'alert-danger'); else loadPeers();
                });
                const bearerBtn = el('button', t('js.settings.bearer_btn'), 'btn btn-sm btn-outline-secondary me-1');
                bearerBtn.type = 'button';
                bearerBtn.title = t('js.settings.bearer_title');
                bearerBtn.addEventListener('click', async () => {
                    const val = window.prompt(t('js.settings.bearer_prompt', { peer: p.name }));
                    if (val === null) return;
                    const r = await call('admin/fed_peer_save', { id: p.id, name: p.name, base_url: p.base_url, pull_enabled: p.pull_enabled, pull_files: p.pull_files, bearer: val.trim() === '' ? 'CLEAR' : val.trim() });
                    if (r.error) note(r.error, 'alert-danger'); else { note(t('js.settings.bearer_updated'), 'alert-success'); loadPeers(); }
                });
                const grantBtn = el('button', t('js.settings.grant_inbound'), 'btn btn-sm btn-outline-secondary me-1');
                grantBtn.type = 'button';
                grantBtn.title = t('js.settings.grant_inbound_title');
                if (p.api_client_id) grantBtn.disabled = true;
                grantBtn.addEventListener('click', async () => {
                    const r = await call('admin/fed_peer_save', { id: p.id, name: p.name, base_url: p.base_url, pull_enabled: p.pull_enabled, pull_files: p.pull_files, grant_inbound: 1 });
                    if (r.error) { note(r.error, 'alert-danger'); return; }
                    if (r.inbound) note(t('js.settings.inbound_bearer_for', { peer: p.name, bearer: r.inbound.bearer }), 'alert-warning');
                    loadPeers();
                });
                const delBtn = el('button', t('js.settings.delete'), 'btn btn-sm btn-outline-danger');
                delBtn.type = 'button';
                delBtn.addEventListener('click', async () => {
                    if (!window.confirm(t('js.settings.delete_peer_confirm', { peer: p.name }))) return;
                    const r = await call('admin/fed_peer_delete', { id: p.id });
                    if (r.error) note(r.error, 'alert-danger'); else loadPeers();
                });
                // Undo import: count first, then slice. A peer that has fed this node for a month
                // can own a million rows, so the browser walks it in bounded pieces instead of
                // asking MariaDB — shared with mail, the forum and the tracker — for one huge
                // statement. The endpoint offers the CLI equivalent for the genuinely large cases.
                const undoBtn = el('button', t('js.settings.undo_import'), 'btn btn-sm btn-outline-warning me-1');
                undoBtn.type = 'button';
                undoBtn.title = t('js.settings.undo_import_title');
                undoBtn.addEventListener('click', async () => {
                    const c = await call('admin/fed_purge', { op: 'count', peer: p.name });
                    if (c.error) { note(c.error, 'alert-danger'); return; }
                    if (!c.rows) { note(t('js.settings.nothing_from_peer', { peer: p.name }), 'alert-info'); return; }
                    if (!window.confirm(t('js.settings.undo_import_confirm', { peer: p.name, rows: c.rows.toLocaleString(), files: c.files.toLocaleString() }))) return;
                    const pw = window.prompt(t('js.settings.admin_password_prompt'));
                    if (!pw) return;
                    undoBtn.disabled = true;
                    let total = 0;
                    for (;;) {
                        const r = await call('admin/fed_purge', { op: 'run', peer: p.name, password: pw });
                        if (r.error) { note(r.error, 'alert-danger'); break; }
                        total += r.done;
                        note(t('js.settings.undo_progress', { peer: p.name, done: total.toLocaleString(), left: r.remaining.toLocaleString() }), 'alert-info');
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
                sel.appendChild(el('option', t('js.settings.all_peers'))).value = '';
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
            if (r.inbound) note(t('js.settings.peer_added_bearer', { bearer: r.inbound.bearer }), 'alert-warning');
            else note(t('js.settings.peer_added'), 'alert-success');
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
                const td = el('td', '', '');
                td.appendChild(AdminCommon.emptyState(state === 'rejected' ? t('js.common.nothing_rejected') : t('js.common.nothing_waiting'),
                                                      state === 'rejected' ? 'bi-x-circle' : 'bi-inbox'));
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
                const nameTd = el('td', row.name || t('js.settings.no_name'), 'text-break');
                nameTd.title = row.info_hash;
                tr.appendChild(nameTd);
                tr.appendChild(el('td', bytes(row.total_size), 'text-nowrap'));
                tr.appendChild(el('td', (row.files_count || 0).toLocaleString() + (row.files_truncated ? t('js.settings.list_capped') : ''), 'text-nowrap'));
                tr.appendChild(el('td', row.peer_name, 'text-muted'));
                tr.appendChild(el('td', row.origin_at || '—', 'text-muted small text-nowrap'));
                const act = el('td', undefined, 'text-nowrap');
                if (state === 'pending') {
                    const yes = el('button', t('js.settings.accept'), 'btn btn-sm btn-outline-success me-1');
                    yes.type = 'button';
                    yes.addEventListener('click', () => decide('accept', [row.id]));
                    const no = el('button', t('js.settings.reject'), 'btn btn-sm btn-outline-danger');
                    no.type = 'button';
                    no.addEventListener('click', () => decide('reject', [row.id]));
                    act.append(yes, no);
                } else {
                    const un = el('button', t('js.settings.allow_again'), 'btn btn-sm btn-outline-secondary');
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
            if (!window.confirm(t('js.settings.accept_all_confirm', { peer: peer }))) return;
            const pw = window.prompt(t('js.settings.admin_password_prompt'));
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

        el.innerHTML = '<span class="text-info">' + t('js.settings.testing') + '</span>';
        try {
            const res = await fetch(API_BASE + 'admin/check_blacklist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ blacklist_path: pathVal })
            });
            const json = await res.json();
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; ' + t('js.settings.path_ok') + '</span>' +
                    (json.suggestions.length ? '<br><small style="color: #a0a0b0;">' + json.suggestions.join('<br>') + '</small>' : '');
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + json.errors.join('<br>') + '</span>' +
                    (json.suggestions.length ? '<br><small class="text-warning">' + json.suggestions.join('<br>') + '</small>' : '') +
                    '<br><small style="color: #a0a0b0;">' + t('js.settings.os_php_user', { os: json.os, user: json.php_user }) + '</small>';
            }
        } catch {
            el.innerHTML = '<span class="text-danger">' + t('js.settings.network_error') + '</span>';
        }
    });

    document.getElementById('btn-test-whitelist').addEventListener('click', async () => {
        const el = document.getElementById('whitelist-result');
        const pathInput = document.querySelector('input[name="whitelist_path"]');
        const pathVal = pathInput ? pathInput.value.trim() : '';
        el.innerHTML = '<span class="text-info">' + t('js.settings.testing') + '</span>';
        try {
            const res = await fetch(API_BASE + 'admin/check_whitelist_path', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ whitelist_path: pathVal })
            });
            const json = await res.json();
            const sug = (json.suggestions || []).map(esc);
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; ' + t('js.settings.dir_ok') + '</span>' +
                    (sug.length ? '<br><small style="color: #a0a0b0;">' + sug.join('<br>') + '</small>' : '') +
                    (json.file ? '<br><small style="color:#a0a0b0;">' + (json.file.exists ? t('js.settings.file_exists', { lines: esc(String(json.file.lines)), size: esc(String(json.file.size)), mode: esc(json.file.mode || ''), owner: esc(json.file.owner || '') }) : t('js.settings.file_missing')) + '</small>' : '');
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || [t('js.settings.test_failed_short')]).map(esc).join('<br>') + '</span>' +
                    (sug.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') +
                    '<br><small style="color: #a0a0b0;">' + t('js.settings.os_php_user', { os: esc(json.os || ''), user: esc(json.php_user || '') }) + '</small>';
            }
        } catch {
            el.innerHTML = '<span class="text-danger">' + t('js.settings.network_error') + '</span>';
        }
    });

    // --- OpenTracker restart/reload permission test -------------------------
    // Calls the read-only `sudo -n -l` check on the server. It never restarts or reloads anything;
    // it only reports whether the web user is allowed to, plus copy-paste sudoers fixes on failure.

    /**
     * One renderer for every Test button.
     *
     * Two things were wrong before. A test whose only finding was "you have not configured this yet"
     * came back as a red "Something on the path is missing", which reads as a broken installation
     * rather than as an unused feature — and red that does not mean broken is red people stop
     * reading. And the Live peer sync button had no handler at all: it was wired in the markup,
     * bound to nothing, and pressing it did exactly nothing for as long as it has existed.
     */
    function renderTestResult(j, okText) {
        const esc2 = (t) => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        let html;
        if (j.ok) {
            html = '<span class="text-success">&#10003; ' + esc2(okText) + '</span>';
        } else if (j.configured === false) {
            html = '<span class="text-info">&#9432; ' + t('js.settings.test_not_set_up') + '</span>';
        } else {
            html = '<span class="text-danger">&#10007; ' + t('js.settings.test_path_missing') + '</span>';
        }
        html += '<ul style="margin:.4rem 0 0 1rem;padding:0;list-style:none;font-size:.85rem;">';
        (j.checks || []).forEach(c => {
            // A failed check on an unconfigured feature is a step still to take, not a fault.
            const bad = j.configured === false
                ? '<span class="text-info">&#9675;</span>'
                : '<span class="text-danger">&#10007;</span>';
            const mark = c.ok ? '<span class="text-success">&#10003;</span>'
                              : (c.info ? '<span style="color:#a0a0b0;">&#8226;</span>' : bad);
            html += '<li>' + mark + ' ' + esc2(c.name)
                 + (c.detail ? ' <small style="color:#a0a0b0;">&mdash; ' + esc2(c.detail) + '</small>' : '') + '</li>';
        });
        html += '</ul>';
        (j.errors || []).forEach(x => { html += '<div class="text-warning" style="font-size:.85rem;">' + esc2(x) + '</div>'; });
        if ((j.suggestions || []).length) {
            html += '<div class="settings-hint mt-2">' + t('js.settings.run_as_root') + '</div>';
            html += '<pre class="nl-preview" style="white-space:pre-wrap;">' + esc2(j.suggestions.join(String.fromCharCode(10))) + '</pre>';
        }
        return html;
    }

    // Every button that carries data-test, whether or not somebody remembered to wire it up.
    document.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('.settings-test-btn[data-test]');
        if (!btn) return;
        let box = btn.parentNode.querySelector('.settings-test-result');
        if (!box) {
            box = document.createElement('div');
            box.className = 'settings-test-result mt-2';
            btn.parentNode.appendChild(box);
        }
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.settings.testing');
        box.innerHTML = '<span class="text-info">' + t('js.settings.asking_helper') + '</span>';
        try {
            const res = await fetch(API_BASE + btn.dataset.test, { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const j = await res.json();
            box.innerHTML = renderTestResult(j, btn.dataset.okText || t('js.settings.test_all_in_place'));
        } catch {
            box.innerHTML = '<span class="text-danger">' + t('js.settings.network_error') + '</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    async function runTrackerPermTest(op, btn) {
        const el = document.getElementById('tracker-perm-result');
        const label = op === 'reload' ? t('js.settings.op_reload') : t('js.settings.op_restart');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.settings.testing');
        el.innerHTML = '<span class="text-info">' + t('js.settings.testing_perm', {op: label}) + '</span>';
        try {
            const res = await fetch(API_BASE + 'admin/test_tracker_permission&op=' + encodeURIComponent(op), {
                method: 'GET',
                headers: { 'X-CSRF-Token': CSRF },
            });
            const json = await res.json();
            const suggestions = (json.suggestions || []);
            const meta = '<br><small style="color:#a0a0b0;">' + t('js.settings.meta_command') + '<code>' + esc(json.command || '') + '</code>'
                + (json.output ? '<br>' + t('js.settings.meta_output') + '<code>' + esc(json.output) + '</code>' : '')
                + '<br>' + t('js.settings.meta_os') + esc(json.os || '') + ' | ' + t('js.settings.meta_php_user') + esc(json.php_user || '') + '</small>';
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; ' + t('js.settings.perm_ok', {op: label}) + '</span>'
                    + (suggestions.length ? '<br><small style="color:#a0a0b0;">' + suggestions.map(esc).join('<br>') + '</small>' : '')
                    + meta;
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || [t('js.settings.perm_test_failed')]).map(esc).join('<br>') + '</span>'
                    + (suggestions.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + suggestions.map(esc).join('<br>') + '</small>' : '')
                    + meta;
            }
        } catch {
            el.innerHTML = '<span class="text-danger">' + t('js.settings.network_error') + '</span>';
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
        const LABELS = { mon: t('js.settings.day_mon'), tue: t('js.settings.day_tue'), wed: t('js.settings.day_wed'), thu: t('js.settings.day_thu'), fri: t('js.settings.day_fri'), sat: t('js.settings.day_sat'), sun: t('js.settings.day_sun') };
        function sync() {
            const days = dayChecks.filter(c => c.checked).map(c => c.value);
            const time = /^\d{2}:\d{2}$/.test(timeEl.value) ? timeEl.value : '04:00';
            jsonEl.value = days.length ? JSON.stringify({ days: days, time: time }) : '';
            if (summary) {
                summary.textContent = days.length
                    ? t('js.settings.schedule_summary', {days: days.length === 7 ? t('js.settings.every_day') : days.map(d => LABELS[d]).join(', '), time: time, tz: (tzEl ? tzEl.value : '')})
                    : t('js.settings.no_auto_backups');
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.settings.testing');
        el.innerHTML = '<span class="text-info">' + t('js.settings.checking_backup_dir') + '</span>';
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
                facts.push(t('js.settings.fact_mode_owner', {mode: esc(json.mode || '?'), owner: esc(json.owner || '?')}));
                if (json.archives !== undefined) facts.push(t('js.settings.fact_archives', {n: esc(String(json.archives))}));
            } else { facts.push(t('js.settings.not_exist_yet')); }
            if (json.free_bytes) facts.push(t('js.settings.fact_free', {n: Math.round(json.free_bytes / 1073741824)}));
            if (c.mode) facts.push(t('js.settings.fact_backup_mode', {mode: esc(c.mode === 'script' ? t('js.settings.backup_mode_full') : t('js.settings.backup_mode_builtin'))}));
            const meta = '<br><small style="color:#a0a0b0;">' + facts.join(' | ')
                + '<br>' + t('js.settings.meta_os') + esc(json.os || '') + ' | ' + t('js.settings.meta_php_user') + esc(json.php_user || '') + '</small>';
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; ' + t('js.settings.backup_dir_ok') + '</span>'
                    + (sug.length ? '<br><small style="color:#a0a0b0;white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || [<?= json_encode(__('settings.js_test_failed')) ?>]).map(esc).join('<br>') + '</span>'
                    + (sug.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            }
        } catch {
            el.innerHTML = '<span class="text-danger">' + <?= json_encode(__('settings.js_network_error_short')) ?> + '</span>';
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + <?= json_encode(__('settings.js_testing')) ?>;
        el.innerHTML = '<span class="text-info">' + <?= json_encode(__('settings.js_checking_fw_helper')) ?> + '</span>';
        try {
            const res = await fetch(API_BASE + 'admin/net_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const json = await res.json();
            const sug = (json.suggestions || []).map(esc);
            const meta = '<br><small style="color:#a0a0b0;">'
                + (json.command ? <?= json_encode(__('settings.js_meta_command')) ?> + '<code>' + esc(json.command) + '</code><br>' : '')
                + (json.output ? <?= json_encode(__('settings.js_meta_output')) ?> + '<code>' + esc(json.output) + '</code><br>' : '')
                + <?= json_encode(__('settings.js_meta_os')) ?> + esc(json.os || '') + ' | ' + <?= json_encode(__('settings.js_meta_php_user')) ?> + esc(json.php_user || '')
                + (json.cpus ? ' | ' + <?= json_encode(__('settings.js_meta_cpu_cores')) ?> + esc(String(json.cpus)) : '') + '</small>';
            if (json.ok) {
                el.innerHTML = '<span class="text-success">&#10003; ' + <?= json_encode(__('settings.js_netlimit_ok')) ?> + '</span>'
                    + (sug.length ? '<br><small style="color:#a0a0b0;white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            } else {
                el.innerHTML = '<span class="text-danger">&#10007; ' + (json.errors || [<?= json_encode(__('settings.js_test_failed')) ?>]).map(esc).join('<br>') + '</span>'
                    + (sug.length ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + sug.join('<br>') + '</small>' : '') + meta;
            }
        } catch {
            el.innerHTML = '<span class="text-danger">' + <?= json_encode(__('settings.js_network_error_short')) ?> + '</span>';
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + <?= json_encode(__('settings.js_testing')) ?>;
        box.innerHTML = '<span class="text-info">' + <?= json_encode(__('settings.js_asking_helper')) ?> + '</span>';
        try {
            const res = await fetch(API_BASE + 'admin/ot_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const j = await res.json();
            const meta = '<br><small style="color:#a0a0b0;">'
                + (j.unit ? <?= json_encode(__('settings.js_meta_unit')) ?> + '<code>' + esc(j.unit) + '</code> | ' : '')
                + (j.cpus ? <?= json_encode(__('settings.js_meta_cores')) ?> + esc(String(j.cpus)) + ' | ' : '')
                + <?= json_encode(__('settings.js_meta_dropin_dir')) ?> + '<code>' + esc(j.dropin_dir || '?') + '</code>'
                + (j.dropin_writable === false ? ' ' + <?= json_encode(__('settings.js_dropin_readonly')) ?> : '')
                + '</small>';
            if (j.ok) {
                box.innerHTML = '<span class="text-success">&#10003; ' + <?= json_encode(__('settings.js_ot_test_ok')) ?> + '</span>'
                    + (j.hint ? '<br><small style="color:#a0a0b0;white-space:pre-wrap;">' + esc(j.hint) + '</small>' : '') + meta;
            } else {
                box.innerHTML = '<span class="text-danger">&#10007; ' + esc(j.error || <?= json_encode(__('settings.js_test_failed')) ?>) + '</span>'
                    + (j.hint ? '<br><small class="text-warning" style="white-space:pre-wrap;">' + esc(j.hint) + '</small>' : '') + meta;
            }
        } catch {
            box.innerHTML = '<span class="text-danger">' + <?= json_encode(__('settings.js_network_error_short')) ?> + '</span>';
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + <?= json_encode(__('settings.js_testing')) ?>;
        box.innerHTML = '<span class="text-info">' + <?= json_encode(__('settings.js_asking_helper')) ?> + '</span>';
        try {
            const res = await fetch(API_BASE + 'admin/ot_cluster_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const j = await res.json();
            box.innerHTML = renderTestResult(j, <?= json_encode(__('settings.js_cluster_test_ok')) ?>);
            let html = '';
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
            if (html) box.innerHTML = html;
        } catch {
            box.innerHTML = '<span class="text-danger">' + <?= json_encode(__('settings.js_network_error_short')) ?> + '</span>';
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + <?= json_encode(__('settings.js_testing')) ?>;
        box.innerHTML = '<span class="text-info">' + <?= json_encode(__('settings.js_asking_helper')) ?> + '</span>';
        try {
            const res = await fetch(API_BASE + 'admin/sysctl_test', { method: 'GET', headers: { 'X-CSRF-Token': CSRF } });
            const j = await res.json();
            box.innerHTML = renderTestResult(j, <?= json_encode(__('settings.js_sysctl_test_ok')) ?>);
            let html = '';
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
            if (html) box.innerHTML = html;
        } catch {
            box.innerHTML = '<span class="text-danger">' + <?= json_encode(__('settings.js_network_error_short')) ?> + '</span>';
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
            <div class="col-md-3"><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="<?= _h('settings.donation_field_label_ph') ?>" data-df="label"></div>
            <div class="col"><input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="<?= _h('settings.donation_field_value_ph') ?>" data-df="value"></div>
            <div class="col-auto"><button type="button" class="btn btn-sm btn-outline-danger donation-field-remove" title="<?= _h('settings.donation_field_remove_title') ?>"><i class="bi bi-x-lg"></i></button></div>
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
            note.textContent = to.value <= from.value ? <?= json_encode(__('settings.sched_ends_next_day_at')) ?> + to.value : <?= json_encode(__('settings.sched_same_day')) ?>;
        } else if (kind === 'window') {
            note.textContent = <?= json_encode(__('settings.sched_set_both_times')) ?>;
        } else {
            note.textContent = kind === 'all' ? <?= json_encode(__('settings.sched_all_day')) ?> : <?= json_encode(__('settings.sched_open_mode')) ?>;
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

    // The password modal, opened for one of two reasons. The deletion limits are known to this page
    // (it compares the three fields itself, below); the settings that decide what the server runs
    // or whom it trusts are NOT listed here on purpose -- the list lives in save_settings.php, the
    // page sends the save, and the reply's `reauth_required` says whether to ask. One list, one
    // place to extend it, and the page cannot be out of date about it.
    function settingsConfirmOpen(payload, reason, keys) {
        settingsPayloadToSubmit = payload;
        let body = reason === 'exec' ? <?= json_encode(__('settings.confirm_body_exec')) ?> : <?= json_encode(__('settings.confirm_body')) ?>;
        if (Array.isArray(keys) && keys.length) body += ' (' + keys.join(', ') + ')';
        document.getElementById('settings-confirm-body').textContent = body;
        document.getElementById('settings-confirm-password').value = '';
        document.getElementById('settings-confirm-alert').innerHTML = '';
        const modal = new bootstrap.Modal(document.getElementById('settingsConfirmModal'));
        modal.show();
    }

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
                else showToast('success', <?= json_encode(__('settings.js_saved_ok')) ?>);
                return true;
            } else if (json.reauth_required && !data.confirm_password) {
                // Not an error yet: the server wants the password for this particular save. Only when
                // none was sent -- a wrong one comes back WITHOUT this flag and is shown as the error
                // it is, inside the modal that is already open.
                settingsConfirmOpen(data, Array.isArray(json.reauth_keys) && json.reauth_keys.length ? 'exec' : 'limits', json.reauth_keys);
                return false;
            } else {
                const errMsg = json.error || <?= json_encode(__('settings.js_save_error')) ?>;
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
                confirmAlert.innerHTML = '<div class="alert alert-danger py-1 px-2 modal-alert-sm">' + <?= json_encode(__('settings.js_network_error')) ?> + '</div>';
                setTimeout(() => {
                    const alertDiv = confirmAlert.querySelector('.modal-alert-sm');
                    if (alertDiv) alertDiv.classList.add('alert-fade');
                }, 4500);
                setTimeout(() => confirmAlert.innerHTML = '', 5000);
            } else {
                showToast('error', <?= json_encode(__('settings.js_network_error')) ?>);
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
            settingsConfirmOpen(data, 'limits');
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + <?= json_encode(__('settings.js_saving')) ?>;

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
            showToast('error', <?= json_encode(__('settings.js_current_password_required')) ?>);
            return;
        }

        if (newPass && newPass !== confirm) {
            showToast('error', <?= json_encode(__('settings.js_passwords_mismatch')) ?>);
            return;
        }

        if (newPass) {
            if (newPass.length < 10) {
                showToast('error', <?= json_encode(__('settings.js_password_too_short')) ?>);
                return;
            }
            if (!/[a-z]/.test(newPass)) {
                showToast('error', <?= json_encode(__('settings.js_password_need_lower')) ?>);
                return;
            }
            if (!/[A-Z]/.test(newPass)) {
                showToast('error', <?= json_encode(__('settings.js_password_need_upper')) ?>);
                return;
            }
            if (!/[0-9]/.test(newPass)) {
                showToast('error', <?= json_encode(__('settings.js_password_need_digit')) ?>);
                return;
            }
            if (!/[^a-zA-Z0-9]/.test(newPass)) {
                showToast('error', <?= json_encode(__('settings.js_password_need_special')) ?>);
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
            showToast('error', <?= json_encode(__('settings.js_nothing_to_change')) ?>);
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
        if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + <?= json_encode(__('settings.js_saving_ellipsis')) ?>; }
        try {
            let ok = true;
            if (wantCreds) {
                const payload = { current_password: current, admin_username: username };
                if (newPass) payload.new_password = newPass;
                const json = await post('admin/change_password', payload);
                if (json.success) {
                    showToast('success', json.message || <?= json_encode(__('settings.js_saved_short')) ?>);
                    ADMIN_USERNAME_CURRENT = username;
                } else {
                    ok = false;
                    showToast('error', json.error || <?= json_encode(__('settings.js_error')) ?>);
                }
            }
            if (ok && wantEmail) {
                const json = await post('admin/account_email', { current_password: current, email: emailNow });
                if (json.success) {
                    showToast('success', json.message || <?= json_encode(__('settings.js_email_change_started')) ?>);
                    // the address only really moves once the mailboxes confirm it, so keep showing
                    // the stored one until then
                    if (json.stage === 'done_direct') ADMIN_EMAIL_CURRENT = emailNow;
                    else emailField.value = ADMIN_EMAIL_CURRENT;
                } else {
                    ok = false;
                    showToast('error', json.error || <?= json_encode(__('settings.js_error')) ?>);
                    emailField.value = ADMIN_EMAIL_CURRENT;
                }
            }
            if (ok) {
                form.current_password.value = '';
                form.new_password.value = '';
                form.confirm_password.value = '';
            }
        } catch {
            showToast('error', <?= json_encode(__('settings.js_network_error')) ?>);
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
                showToast('success', json.message || <?= json_encode(__('settings.js_cancelled')) ?>);
                document.getElementById('admin-email-pending')?.remove();
            } else {
                showToast('error', json.error || <?= json_encode(__('settings.js_error')) ?>);
                btn.disabled = false;
            }
        } catch {
            showToast('error', <?= json_encode(__('settings.js_network_error')) ?>);
            btn.disabled = false;
        }
    });
    </script>
</body>
</html>
