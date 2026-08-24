<?php
if (!userCan($db, $cfg, 'whitelist.view')) {
    echo '<h1>Whitelist</h1><p>Browsing the whitelist requires an account with whitelist access.</p>';
    echo '<p><a class="btn" href="' . $baseUrl . '?action=login">Sign in</a></p>';
    return;
}
$wlMode     = trackerMode($cfg) === 'whitelist';
$wlSched    = function_exists('scheduleEnabled') && scheduleEnabled($cfg);   // whitelist hours → registration always open
$wlReg      = $wlMode || $wlSched;   // registration UI shown (whitelist mode now, or scheduled whitelist hours)
$wlPublic   = ($cfg['whitelist_public_enabled'] ?? '1') === '1';
$wlCaptcha  = captchaConfigured($cfg);
// audience: 'users' = signed-in accounts with the whitelist.add permission (no CAPTCHA)
$wlUsersMode = ($cfg['whitelist_submit_mode'] ?? 'public') === 'users' && usersEnabled($cfg);
$wlMe        = $wlUsersMode ? currentUser($db) : null;
$wlUserOk    = $wlUsersMode && $wlMe !== null && userCan($db, $cfg, 'whitelist.add');
$wlMax      = max(1, (int)($cfg['whitelist_max_per_submission'] ?? 20));
$wlUdp      = trim((string)($cfg['announce_url'] ?? ''));
$wlHttp     = trim((string)($cfg['announce_url_https'] ?? ''));
$wlOpen     = $wlReg && $wlPublic && $wlCaptcha;
// Official number of registered (active, non-banned) hashes. Cheap path: the state file
// (config/whitelist_state.json) — `count` is the row count of the last regeneration plus every append since
// (bans/removals regenerate). Falls back to a COUNT(*) (small table) when the file was never generated
// (fresh install) or the state is flagged as out of date (regen pending / last regen failed).
$wlCount = null;
if ($wlReg) {
    $wlState = function_exists('whitelistStateRead') ? whitelistStateRead() : [];
    if (!empty($wlState['generated_at']) && empty($wlState['regen_needed'])) {
        $wlCount = max(0, (int)($wlState['count'] ?? 0));
    } elseif (isset($db)) {
        try { $wlCount = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 0")->fetchColumn(); } catch (Throwable $e) { $wlCount = null; }
    }
}
// Scheduled mode notice: hours + what the tracker does right now + next change (schedule timezone)
$wlSchedNotice = '';
if ($wlSched) {
    $wlNext = scheduleNextChange($cfg);
    $wlSchedNotice = 'Whitelist hours: <strong>' . sanitize(scheduleDescribe($cfg)) . '</strong>. Right now the tracker is in <strong>'
        . ($wlMode ? 'whitelist' : 'open') . ' mode</strong>'
        . ($wlNext ? '; next change at <strong>' . sanitize(scheduleFormatLocal($cfg, $wlNext)) . '</strong> (' . sanitize(scheduleTimezone($cfg)) . ').' : '.')
        . ($wlMode ? '' : ' Registrations made now become active at the start of the next whitelist hours.');
}
?>
<h1>Whitelist — register your torrent</h1>

<?php if (!$wlReg): ?>
<p>This tracker currently runs in <strong>open (blacklist) mode</strong>: every torrent is served unless it was blocked after an abuse report. There is nothing to register.</p>
<p>See <a href="<?= $baseUrl ?>?action=info">Info</a> for the announce URLs.</p>
<?php elseif ($wlPublic && $wlUsersMode && !$wlUserOk): ?>
<?php if ($wlSchedNotice !== ''): ?><div class="wl-schedule-notice"><?= $wlSchedNotice ?></div><?php endif; ?>
<p>This tracker serves <strong>registered torrents only</strong><?= $wlSched ? ' during whitelist hours (open mode otherwise)' : '' ?>. Torrent registration is limited to <strong>signed-in users</strong> with whitelist access.</p>
<?php if ($wlMe === null): ?>
<p><a class="btn" href="<?= $baseUrl ?>?action=login">Sign in</a>
<?php if (usersRegistrationEnabled($cfg)): ?> <a class="btn btn-secondary" href="<?= $baseUrl ?>?action=register">Create an account</a><?php endif; ?></p>
<?php else: ?>
<p>Your account does not have whitelist access. Check <a href="<?= $baseUrl ?>?action=account">your groups</a> or contact the site admin.</p>
<?php endif; ?>
<?php if ($wlCount !== null): ?>
<p class="wl-count">Currently <strong><?= number_format($wlCount) ?></strong> <?= $wlCount === 1 ? 'torrent is' : 'torrents are' ?> registered on this tracker.</p>
<?php endif; ?>
<div id="wl-check-block">
    <h2 class="section-heading-spaced">Check a hash</h2>
    <form id="wl-check-form" novalidate>
        <div class="form-group"><label for="wl-check-input">Magnet link or info hash</label>
        <input type="text" id="wl-check-input" name="hash" maxlength="2048" placeholder="magnet:?xt=urn:btih:… or 40 hex characters"></div>
        <div class="form-center"><button type="submit" class="btn" id="wl-check-submit">Check</button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php elseif (!$wlPublic || (!$wlUsersMode && !$wlCaptcha)): ?>
<?php if ($wlSchedNotice !== ''): ?><div class="wl-schedule-notice"><?= $wlSchedNotice ?></div><?php endif; ?>
<p>This tracker serves <strong>registered torrents only</strong><?= $wlSched ? ' during whitelist hours (open mode otherwise)' : '' ?>. Public registration is currently <strong>unavailable</strong><?= !$wlCaptcha ? ' (CAPTCHA is not configured on this site)' : '' ?>. Torrents posted on the community forum are registered automatically.</p>
<?php if ($wlCount !== null): ?>
<p class="wl-count">Currently <strong><?= number_format($wlCount) ?></strong> <?= $wlCount === 1 ? 'torrent is' : 'torrents are' ?> registered on this tracker.</p>
<?php endif; ?>
<div id="wl-check-block">
    <h2 class="section-heading-spaced">Check a hash</h2>
    <form id="wl-check-form" novalidate>
        <div class="form-group"><label for="wl-check-input">Magnet link or info hash</label>
        <input type="text" id="wl-check-input" name="hash" maxlength="2048" placeholder="magnet:?xt=urn:btih:… or 40 hex characters"></div>
        <div class="form-center"><button type="submit" class="btn" id="wl-check-submit">Check</button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php else: ?>
<?php if ($wlSchedNotice !== ''): ?><div class="wl-schedule-notice"><?= $wlSchedNotice ?></div><?php endif; ?>
<?php if ($wlUsersMode): ?>
<p>This tracker serves <strong>registered torrents only</strong><?= $wlSched ? ' during whitelist hours (open mode otherwise)' : '' ?>. You are signed in as <strong><?= sanitize($wlMe['username']) ?></strong> — paste one or more magnet links (or plain 40-character info hashes) and they are added to the whitelist under your account (no CAPTCHA needed). Torrents posted on the community forum are registered automatically.</p>
<?php else: ?>
<p>This tracker serves <strong>registered torrents only</strong><?= $wlSched ? ' during whitelist hours (open mode otherwise)' : '' ?>. Registration is <strong>free and anonymous</strong> — paste one or more magnet links (or plain 40-character info hashes), <?= captchaProvider($cfg) === 'recaptcha_v3' ? 'pass the (invisible) CAPTCHA check' : 'solve the CAPTCHA' ?> and the hashes are added to the whitelist. Torrents posted on the community forum are registered automatically.</p>
<?php endif; ?>
<?php if ($wlCount !== null): ?>
<p class="wl-count">Currently <strong><?= number_format($wlCount) ?></strong> <?= $wlCount === 1 ? 'torrent is' : 'torrents are' ?> registered on this tracker.</p>
<?php endif; ?>
<ul class="wl-rules">
    <?php if (($cfg['whitelist_require_tracker'] ?? '0') === '1'): ?>
    <li><strong>Only magnet links that already announce to this tracker are accepted</strong> — the magnet must contain <code>&amp;tr=<?= sanitize($wlUdp ?: $wlHttp) ?></code><?= ($wlUdp && $wlHttp) ? ' (or the HTTP announce URL below)' : '' ?>. Plain hashes are refused.</li>
    <?php endif; ?>
    <li>Up to <strong><?= $wlMax ?></strong> hashes per submission, one per line.</li>
    <li>Your IP address<?= $wlUsersMode ? ' and account name are' : ' is' ?> stored with each registration to detect abuse. Spam and abusive submissions get the <?= $wlUsersMode ? 'account / IP' : 'IP' ?> banned.</li>
    <li>Registered hashes may be removed or banned at any time (e.g. after an abuse report). Banned hashes cannot be re-registered.</li>
    <li>Registration only tells the tracker to <em>serve</em> the swarm — we do not host, index or download any content.</li>
</ul>

<div id="wl-alert" class="alert"></div>

<form id="wl-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="form-group">
        <label for="wl-input">Magnet links / info hashes <small class="form-hint">— <span id="wl-counter">0 valid</span></small></label>
        <textarea id="wl-input" name="input" rows="6" maxlength="<?= $wlMax * 2100 ?>" data-max="<?= $wlMax ?>" placeholder="magnet:?xt=urn:btih:a1b2c3d4e5f6…&#10;a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2"></textarea>
        <div class="error-msg">Paste at least one valid magnet link or 40-hex info hash</div>
    </div>
    <div class="form-center">
        <button type="submit" class="btn" id="wl-submit">Register</button>
    </div>
</form>

<div id="wl-results" class="wl-results" hidden>
    <h2>Result</h2>
    <p id="wl-results-summary" class="wl-summary"></p>
    <div id="wl-results-list"></div>
</div>

<?php if ($wlUdp !== '' || $wlHttp !== ''): ?>
<h2 class="section-heading-spaced">Announce URLs</h2>
<p>Add these to your torrent / magnet (<code>&amp;tr=</code>) so peers find each other through this tracker:</p>
<div class="code-block pos-relative">
    <button class="copy-btn" onclick="copyText(this, 'wl-announce-copy')" title="Copy announce URLs"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
    <?php if ($wlHttp !== ''): ?><div class="label"><?= stripos($wlHttp, 'https://') === 0 ? 'HTTPS' : 'HTTP' ?></div><code><?= sanitize($wlHttp) ?></code><?php endif; ?>
    <?php if ($wlUdp !== ''): ?><div class="label label-top">UDP</div><code><?= sanitize($wlUdp) ?></code><?php endif; ?>
    <span id="wl-announce-copy" class="announce-hidden"><?= trim(sanitize($wlHttp) . "\n" . sanitize($wlUdp)) ?></span>
</div>
<?php endif; ?>

<div id="wl-check-block">
    <h2 class="section-heading-spaced">Check a hash</h2>
    <form id="wl-check-form" novalidate>
        <div class="form-group"><label for="wl-check-input">Magnet link or info hash</label>
        <input type="text" id="wl-check-input" name="hash" maxlength="2048" placeholder="magnet:?xt=urn:btih:… or 40 hex characters"></div>
        <div class="form-center"><button type="submit" class="btn" id="wl-check-submit">Check</button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php endif; ?>
