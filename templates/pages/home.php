<?php
/**
 * The home page.
 *
 * Every section below still renders EXACTLY where it always did, with its own conditions intact —
 * the difference is that each one renders into a buffer, and includes/homelayout.php decides the
 * order the buffers are emitted in and which are dropped. Reordering by moving markup would mean
 * duplicating the conditions (tracker mode rewrites two of these sections, three more are gated by
 * their own settings), and a duplicated condition is one that goes stale.
 *
 * Capture order is source order, so a variable assigned in one block is still in scope for the
 * next — `$homeSched` is computed in "about" and used again in "features" whatever order they end
 * up being shown in.
 */
$homeBlocks = [];
?>
<?php ob_start(); /* ── header ─────────────────────────────────────────── */ ?>
<h1><?= sanitize($cfg['site_name'] ?? 'BitTorrent Tracker') ?></h1>
<p><?= sanitize(homeTagline($cfg)) ?></p>
<?php $homeBlocks['header'] = ob_get_clean(); ?>

<?php ob_start(); /* ── stats ─────────────────────────────────────────── */ ?>
<?php if (($cfg['tracker_stats_enabled'] ?? '0') === '1' && ($cfg['tracker_stats_show_home'] ?? '1') === '1' && userCan($db, $cfg, 'home.stats')): ?>
<?php
    $cacheFile = __DIR__ . '/../../config/stats_cache.json';
    $cacheData = null;
    if (file_exists($cacheFile)) {
        $cacheData = json_decode(@file_get_contents($cacheFile), true);
    }
    $hasCache = $cacheData && ($cacheData['success'] ?? false);
    
    // Calculate cache freshness and remaining time
    $interval = max(2, (int)($cfg['tracker_stats_interval'] ?? 10));
    // Shared server-side cache lifetime, decoupled from the refresh interval (see tracker_stats.php).
    $cacheTtl = max(2, (int)($cfg['tracker_stats_cache_ttl'] ?? 60));
    $cacheAge = 999999;
    if ($hasCache && isset($cacheData['fetched_at'])) {
        $cacheAge = time() - (int)$cacheData['fetched_at'];
    }
    // Fresh = still within the shared cache TTL; while fresh we serve cached data with no re-fetch.
    $isCacheFresh = $hasCache && ($cacheAge < $cacheTtl);
    $remainingSeconds = $isCacheFresh ? max(1, min($interval, $cacheTtl - $cacheAge)) : 0;
    $peerLabelStyle = $cfg['tracker_stats_peer_label_style'] ?? 'percent';
?>
<?php $homeRenderedAt = $hasCache ? (int)($cacheData['fetched_at'] ?? 0) : 0; ?>
<div id="home-stats-widget" class="home-stats-widget card pos-relative"
     data-interval="<?= $interval ?>"
     data-source="home"
     data-has-cache="<?= $hasCache ? '1' : '0' ?>"
     data-cache-fresh="<?= $isCacheFresh ? '1' : '0' ?>"
     data-remaining-seconds="<?= $remainingSeconds ?>"
     data-peer-label-style="<?= sanitize($peerLabelStyle) ?>"
     data-fetched-at="<?= $homeRenderedAt ?>">
    <div class="home-stats-skeleton <?= $hasCache ? 'hidden' : '' ?>">
        <span class="pulse-dot syncing"></span> <?= _h('home.stats_syncing') ?>
    </div>
    <div class="home-stats-content <?= $hasCache ? '' : 'hidden' ?>">
        <div class="home-stat-item">
            <span class="h-label"><?= _h('home.stat_torrents') ?></span>
            <strong class="h-value font-mono text-accent" id="home-val-torrents"><?= $hasCache ? number_format($cacheData['torrents']) : '—' ?></strong>
        </div>
        <div class="home-stat-divider"></div>
        <div class="home-stat-item">
            <span class="h-label"><?= _h('home.stat_seeds') ?></span>
            <strong class="h-value font-mono text-success" id="home-val-seeds"><?= $hasCache ? number_format($cacheData['seeds']) : '—' ?></strong>
        </div>
        <div class="home-stat-divider"></div>
        <?php if ($peerLabelStyle === 'peers_card'): ?>
        <div class="home-stat-item">
            <span class="h-label"><?= _h('home.stat_peers') ?></span>
            <strong class="h-value font-mono text-warning" id="home-val-peers"><?= $hasCache ? number_format($cacheData['peers']) : '—' ?></strong>
        </div>
        <?php else: ?>
        <div class="home-stat-item">
            <span class="h-label"><?= _h('home.stat_leechers') ?></span>
            <strong class="h-value font-mono text-warning" id="home-val-leechers"><?= $hasCache ? number_format($cacheData['leechers']) : '—' ?></strong>
        </div>
        <?php endif; ?>
        <div class="home-stat-divider"></div>
        <div class="home-stat-item">
            <span class="h-label"><?= _h('home.stat_completed') ?></span>
            <strong class="h-value font-mono text-info" id="home-val-completed"><?= $hasCache ? number_format($cacheData['completed']) : '—' ?></strong>
        </div>
        <div class="home-stat-divider"></div>
        <div class="home-stat-item">
            <span class="h-label"><?= _h('home.stat_uptime') ?></span>
            <strong class="h-value font-mono text-white" id="home-val-uptime"><?= $hasCache ? sanitize($cacheData['uptime_string']) : '—' ?></strong>
        </div>
        <?php
        $beaconClass = $isCacheFresh ? '' : 'syncing';
        $beaconTitle = $isCacheFresh ? 'Live Syncing' : 'Syncing Swarms...';
        ?>
        <div class="home-stat-beacon <?= $beaconClass ?>" title="<?= $beaconTitle ?>">
            <span class="pulse-dot"></span>
        </div>
    </div>
</div>
<?php endif; ?>
<?php $homeBlocks['stats'] = ob_get_clean(); ?>

<?php ob_start(); /* ── announce ─────────────────────────────────────────── */ ?>
<?php if (!empty($cfg['announce_url']) || !empty($cfg['announce_url_https'])): ?>
<?php
    // Detect protocols for labels
    $udpUrl = $cfg['announce_url'] ?? '';
    $httpUrl = $cfg['announce_url_https'] ?? '';
    $httpLabel = 'HTTP';
    if ($httpUrl && stripos($httpUrl, 'https://') === 0) $httpLabel = 'HTTPS';
    $udpLabel = 'UDP';
    // Build copy text: HTTP/S first, then UDP
    $copyParts = [];
    if ($httpUrl) $copyParts[] = $httpUrl;
    if ($udpUrl) $copyParts[] = $udpUrl;
    // Ports served by extra opentracker instances. They listen on their own ports and share nothing,
    // so a visitor given only the first URL never reaches them and the extra process idles. Empty on
    // every install that has not enabled the cluster, which is nearly all of them.
    $extraUrls = array_values(array_diff(function_exists('announceUrls') ? announceUrls($cfg) : [],
                                         array_filter([$udpUrl, $httpUrl])));
    foreach ($extraUrls as $eu) $copyParts[] = $eu;
?>
<h2><?= sanitize(homeHeading($cfg, 'announce')) ?></h2>
<div class="code-block pos-relative">
    <button class="copy-btn" onclick="copyText(this, 'announce-copy')" title="<?= _h('home.announce_copy') ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
    <?php if (!empty($httpUrl)): ?>
    <div class="label"><?= $httpLabel ?></div><code><?= sanitize($httpUrl) ?></code>
    <?php endif; ?>
    <?php if (!empty($udpUrl)): ?>
    <div class="label label-top"><?= $udpLabel ?></div><code><?= sanitize($udpUrl) ?></code>
    <?php endif; ?>
    <?php foreach ($extraUrls as $eu): ?>
    <div class="label label-top">EXTRA</div><code><?= sanitize($eu) ?></code>
    <?php endforeach; ?>
    <?php if ($extraUrls): ?>
    <p class="announce-extra-note"><?= __('home.announce_extra') ?></p>
    <?php endif; ?>
    <span id="announce-copy" class="announce-hidden"><?= trim(implode("\n", $copyParts)) ?></span>
</div>
<?php endif; ?>
<?php $homeBlocks['announce'] = ob_get_clean(); ?>

<?php ob_start(); /* ── about ─────────────────────────────────────────── */ ?>
<?php
$homeWhitelist = trackerMode($cfg) === 'whitelist';
$homeSched     = function_exists('scheduleEnabled') && scheduleEnabled($cfg);   // whitelist hours: registration is always open
$homePublicReg = ($homeWhitelist || $homeSched) && ($cfg['whitelist_public_enabled'] ?? '1') === '1';
?>
<h2><?= sanitize(homeHeading($cfg, 'about')) ?></h2>
<?php if ($homeSched): ?>
<p><?= __('home.about_sched', [
        'hours' => sanitize(scheduleDescribe($cfg)),
        'mode'  => __($homeWhitelist ? 'home.mode_whitelist' : 'home.mode_open'),
        'reg'   => $homePublicReg ? __('home.reg_here', ['url' => sanitize($baseUrl . '?action=whitelist')]) : '',
     ]) ?></p>
<?php if ($homePublicReg): ?>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=whitelist"><?= _h('home.register_btn') ?></a></p>
<?php endif; ?>
<?php elseif ($homeWhitelist): ?>
<p><?= __('home.about_wl', [
        'reg' => $homePublicReg ? __('home.reg_here', ['url' => sanitize($baseUrl . '?action=whitelist')]) : '',
     ]) ?></p>
<?php if ($homePublicReg): ?>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=whitelist"><?= _h('home.register_btn') ?></a></p>
<?php endif; ?>
<?php else: ?>
<p><?= __('home.about_open') ?></p>
<?php endif; ?>
<?php $homeBlocks['about'] = ob_get_clean(); ?>

<?php ob_start(); /* ── features ─────────────────────────────────────────── */ ?>
<h2><?= sanitize(homeHeading($cfg, 'features')) ?></h2>
<ul class="feature-list">
    <li><?= _h('home.feat_no_host') ?></li>
<?php if ($homeSched): ?>
    <li><?= _h('home.feat_sched') ?></li>
    <li><?= _h('home.feat_meta') ?></li>
<?php elseif ($homeWhitelist): ?>
    <li><?= _h('home.feat_wl') ?></li>
    <li><?= _h('home.feat_meta') ?></li>
<?php else: ?>
    <li><?= _h('home.feat_no_logs') ?></li>
<?php endif; ?>
    <li><?= _h('home.feat_random_ip') ?></li>
    <li><?= _h('home.feat_ipv6') ?></li>
    <li><?= _h('home.feat_free') ?></li>
</ul>
<?php $homeBlocks['features'] = ob_get_clean(); ?>

<?php ob_start(); /* ── donations ─────────────────────────────────────────── */ ?>
<?php if (($cfg['donations_enabled'] ?? '0') === '1'): ?>
<?php
    // Build donation fields: prefer new JSON format, fall back to legacy wallet keys
    $donationFields = json_decode($cfg['donation_fields'] ?? '[]', true);
    if (!is_array($donationFields)) $donationFields = [];
    if (empty($donationFields)) {
        // Backward compatibility: migrate from old fixed wallet keys
        if (!empty($cfg['wallet_xmr'])) $donationFields[] = ['label' => 'Monero (XMR)', 'value' => $cfg['wallet_xmr']];
        if (!empty($cfg['wallet_btc'])) $donationFields[] = ['label' => 'Bitcoin (BTC)', 'value' => $cfg['wallet_btc']];
        if (!empty($cfg['wallet_eth'])) $donationFields[] = ['label' => 'Ethereum (ETH)', 'value' => $cfg['wallet_eth']];
    }
?>
<?php if (!empty($donationFields)): ?>
<h2><?= sanitize(homeHeading($cfg, 'donations')) ?></h2>
<?php foreach ($donationFields as $i => $df):
    $dfLabel = sanitize($df['label'] ?? '');
    $dfValue = $df['value'] ?? '';
    $isUrl = preg_match('#^https?://#i', $dfValue);
?>
<?php if ($isUrl): ?>
<div class="card">
    <p><strong><?= $dfLabel ?>:</strong></p>
    <a href="<?= sanitize($dfValue) ?>" target="_blank" rel="noopener noreferrer" class="donate-link"><?= sanitize($dfValue) ?></a>
</div>
<?php else: ?>
<div class="card pos-relative">
    <button class="copy-btn" onclick="copyText(this, 'copy-df-<?= $i ?>')" title="<?= _h('home.donate_copy', ['label' => $dfLabel]) ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
    <p><strong><?= $dfLabel ?>:</strong></p><span class="donate-addr" id="copy-df-<?= $i ?>"><?= sanitize($dfValue) ?></span>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
<?php $homeBlocks['donations'] = ob_get_clean(); ?>

<?php ob_start(); /* ── contact ─────────────────────────────────────────── */ ?>
<?php if (($cfg['contact_visible'] ?? '1') === '1'): ?>
<h2><?= sanitize(homeHeading($cfg, 'contact')) ?></h2>
<div class="contact-report-cta">
    <?= __('home.contact_cta', ['url' => sanitize($baseUrl . '?action=report')]) ?>
</div>
<p class="contact-disclaimer"><?= _h('home.contact_note_a') ?><?php if (!empty($cfg['site_email'])): ?><?php if (($cfg['contact_obfuscate'] ?? '0') === '1'): ?><a href="#" class="obf-email" onclick="revealEmail(this);return false;"><?= _h('home.contact_reveal') ?></a><?php else: ?><a href="mailto:<?= sanitize($cfg['site_email']) ?>"><?= sanitize($cfg['site_email']) ?></a><?php endif; ?><?php endif; ?></p>
<?php endif; ?>
<?php $homeBlocks['contact'] = ob_get_clean(); ?>

<?php
// The one place the page is assembled. A hidden section is not rendered at all rather than being
// rendered and then styled away — a display:none block is still in the source, still costs the
// queries its conditions ran, and still turns up in a text-mode browser.
foreach (homeLayoutOrder($cfg) as $homeKey) {
    if (homeSectionHidden($cfg, $homeKey)) continue;
    echo $homeBlocks[$homeKey] ?? '';
}
?>
