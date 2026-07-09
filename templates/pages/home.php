<h1><?= sanitize($cfg['site_name'] ?? 'BitTorrent Tracker') ?></h1>
<p>Public BitTorrent tracker powered by OpenTracker</p>

<?php if (($cfg['tracker_stats_enabled'] ?? '0') === '1' && ($cfg['tracker_stats_show_home'] ?? '1') === '1'): ?>
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
        <span class="pulse-dot syncing"></span> Synchronizing tracker telemetry...
    </div>
    <div class="home-stats-content <?= $hasCache ? '' : 'hidden' ?>">
        <div class="home-stat-item">
            <span class="h-label">Torrents</span>
            <strong class="h-value font-mono text-accent" id="home-val-torrents"><?= $hasCache ? number_format($cacheData['torrents']) : '—' ?></strong>
        </div>
        <div class="home-stat-divider"></div>
        <div class="home-stat-item">
            <span class="h-label">Seeds</span>
            <strong class="h-value font-mono text-success" id="home-val-seeds"><?= $hasCache ? number_format($cacheData['seeds']) : '—' ?></strong>
        </div>
        <div class="home-stat-divider"></div>
        <?php if ($peerLabelStyle === 'peers_card'): ?>
        <div class="home-stat-item">
            <span class="h-label">Peers</span>
            <strong class="h-value font-mono text-warning" id="home-val-peers"><?= $hasCache ? number_format($cacheData['peers']) : '—' ?></strong>
        </div>
        <?php else: ?>
        <div class="home-stat-item">
            <span class="h-label">Leechers</span>
            <strong class="h-value font-mono text-warning" id="home-val-leechers"><?= $hasCache ? number_format($cacheData['leechers']) : '—' ?></strong>
        </div>
        <?php endif; ?>
        <div class="home-stat-divider"></div>
        <div class="home-stat-item">
            <span class="h-label">Completed</span>
            <strong class="h-value font-mono text-info" id="home-val-completed"><?= $hasCache ? number_format($cacheData['completed']) : '—' ?></strong>
        </div>
        <div class="home-stat-divider"></div>
        <div class="home-stat-item">
            <span class="h-label">Uptime</span>
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
?>
<h2>Announce URL</h2>
<div class="code-block pos-relative">
    <button class="copy-btn" onclick="copyText(this, 'announce-copy')" title="Copy announce URLs"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
    <?php if (!empty($httpUrl)): ?>
    <div class="label"><?= $httpLabel ?></div><code><?= sanitize($httpUrl) ?></code>
    <?php endif; ?>
    <?php if (!empty($udpUrl)): ?>
    <div class="label label-top"><?= $udpLabel ?></div><code><?= sanitize($udpUrl) ?></code>
    <?php endif; ?>
    <span id="announce-copy" class="announce-hidden"><?= trim(implode("\n", $copyParts)) ?></span>
</div>
<?php endif; ?>

<h2>About the Tracker</h2>
<p>This is a free, public BitTorrent tracker running erdgeist OpenTracker software. It has been running on Linux Debian since 2020 and supports both IPv4 and IPv6.</p>

<h2>Features</h2>
<ul class="feature-list">
    <li>We do not store any content or torrent files</li>
    <li>No connection logs are kept</li>
    <li>Random IPs inserted into peer lists (privacy)</li>
    <li>Full IPv4 and IPv6 support</li>
    <li>Completely free service</li>
</ul>

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
<h2>Support the Project</h2>
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
    <button class="copy-btn" onclick="copyText(this, 'copy-df-<?= $i ?>')" title="Copy <?= $dfLabel ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
    <p><strong><?= $dfLabel ?>:</strong></p><span class="donate-addr" id="copy-df-<?= $i ?>"><?= sanitize($dfValue) ?></span>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php if (($cfg['contact_visible'] ?? '1') === '1'): ?>
<h2>Contact</h2>
<div class="contact-report-cta">
    If you wish to report infringing content, please use our <a href="<?= $baseUrl ?>?action=report" class="report-link">[ Submit a Report ]</a>
</div>
<p class="contact-disclaimer">Reports submitted through the form above are reviewed faster and receive status updates. For business inquiries, partnership requests, or if you have not received a response to your report, you may contact us via <?php if (!empty($cfg['site_email'])): ?><?php if (($cfg['contact_obfuscate'] ?? '0') === '1'): ?><a href="#" class="obf-email" onclick="revealEmail(this);return false;">[click to reveal contact]</a><?php else: ?><a href="mailto:<?= sanitize($cfg['site_email']) ?>"><?= sanitize($cfg['site_email']) ?></a><?php endif; ?><?php endif; ?></p>
<?php endif; ?>
