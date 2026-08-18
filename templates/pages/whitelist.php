<?php
$wlMode     = trackerMode($cfg) === 'whitelist';
$wlPublic   = ($cfg['whitelist_public_enabled'] ?? '1') === '1';
$wlCaptcha  = captchaConfigured($cfg);
$wlMax      = max(1, (int)($cfg['whitelist_max_per_submission'] ?? 20));
$wlUdp      = trim((string)($cfg['announce_url'] ?? ''));
$wlHttp     = trim((string)($cfg['announce_url_https'] ?? ''));
$wlOpen     = $wlMode && $wlPublic && $wlCaptcha;
?>
<h1>Whitelist — register your torrent</h1>

<?php if (!$wlMode): ?>
<p>This tracker currently runs in <strong>open (blacklist) mode</strong>: every torrent is served unless it was blocked after an abuse report. There is nothing to register.</p>
<p>See <a href="<?= $baseUrl ?>?action=info">Info</a> for the announce URLs.</p>
<?php elseif (!$wlPublic || !$wlCaptcha): ?>
<p>This tracker serves <strong>registered torrents only</strong>. Public registration is currently <strong>unavailable</strong><?= !$wlCaptcha ? ' (CAPTCHA is not configured on this site)' : '' ?>. Torrents posted on the community forum are registered automatically.</p>
<div id="wl-check-block">
    <h2 class="section-heading-spaced">Check a hash</h2>
    <form id="wl-check-form" novalidate>
        <div class="form-group"><label for="wl-check-input">Magnet link or info hash</label>
        <input type="text" id="wl-check-input" name="hash" maxlength="2048" placeholder="magnet:?xt=urn:btih:… or 40 hex characters"></div>
        <div class="form-center"><button type="submit" class="btn btn-secondary" id="wl-check-submit">Check</button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php else: ?>
<p>This tracker serves <strong>registered torrents only</strong>. Registration is <strong>free and anonymous</strong> — paste one or more magnet links (or plain 40-character info hashes), solve the CAPTCHA and the hashes are added to the whitelist. Torrents posted on the community forum are registered automatically.</p>
<ul class="wl-rules">
    <?php if (($cfg['whitelist_require_tracker'] ?? '0') === '1'): ?>
    <li><strong>Only magnet links that already announce to this tracker are accepted</strong> — the magnet must contain <code>&amp;tr=<?= sanitize($wlUdp ?: $wlHttp) ?></code><?= ($wlUdp && $wlHttp) ? ' (or the HTTP announce URL below)' : '' ?>. Plain hashes are refused.</li>
    <?php endif; ?>
    <li>Up to <strong><?= $wlMax ?></strong> hashes per submission, one per line.</li>
    <li>Your IP address is stored with each registration to detect abuse. Spam and abusive submissions get the IP banned.</li>
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
        <div class="form-center"><button type="submit" class="btn btn-secondary" id="wl-check-submit">Check</button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php endif; ?>
