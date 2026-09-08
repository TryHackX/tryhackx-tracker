<?php
/**
 * The home page's built-in sections, as HTML bodies — and the placeholders a custom section can use.
 *
 * These blocks used to live inline in templates/pages/home.php. They are a FUNCTION now for one
 * reason: two callers need them. The page assembles them in layout order; the editor's preview for
 * a custom section needs the same bodies to paste in wherever the text says {{block:announce}}.
 * A preview built by different code from the page is a preview of a different page.
 *
 * Nothing about the blocks changed in the move. Each still carries its own conditions — the stats
 * widget needs its setting, its permission and a cache file; the "About" text is rewritten by the
 * tracker mode — and each renders into its own buffer exactly as before. What is gone is the <h2>
 * above each: the assembly draws the heading, and only over a body that is not empty, so a section
 * whose feature is off does not leave a heading over nothing.
 */

require_once __DIR__ . '/homelayout.php';

/** The built-in bodies, keyed by section. A gated section that is off comes back as ''. */
function homeBlocks(PDO $db, array $cfg, string $baseUrl): array {
    $homeBlocks = [];
    // The outer buffer swallows the whitespace between the blocks; each block captures itself.
    ob_start();
?>
<?php ob_start(); /* ── header ─────────────────────────────────────────── */ ?>
<h1><?= sanitize($cfg['site_name'] ?? 'BitTorrent Tracker') ?></h1>
<p><?= sanitize(homeTagline($cfg)) ?></p>
<?php $homeBlocks['header'] = ob_get_clean(); ?>

<?php ob_start(); /* ── stats ─────────────────────────────────────────── */ ?>
<?php if (($cfg['tracker_stats_enabled'] ?? '0') === '1' && ($cfg['tracker_stats_show_home'] ?? '1') === '1' && userCan($db, $cfg, 'home.stats')): ?>
<?php
    $cacheFile = __DIR__ . '/../config/stats_cache.json';
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
<div class="code-block pos-relative">
    <button class="copy-btn" data-copy="announce-copy" title="<?= _h('home.announce_copy') ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
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
<?php // The features that arrived later, each only while its setting is on. A bullet promising a
      // search that is switched off is a bullet that teaches visitors the list is decoration. ?>
<?php if (function_exists('indexEnabled') && indexEnabled($cfg)): ?>
    <li><?= _h('home.feat_index') ?></li>
<?php if (usersEnabled($cfg) && ($cfg['index_search_enabled'] ?? '1') === '1'): ?>
    <li><?= _h('home.feat_search') ?></li>
<?php endif; ?>
<?php endif; ?>
<?php if (usersEnabled($cfg)): ?>
    <li><?= _h('home.feat_accounts') ?></li>
<?php endif; ?>
<?php if (($cfg['transparency_enabled'] ?? '1') === '1'): ?>
    <li><?= _h('home.feat_transparency') ?></li>
<?php endif; ?>
<?php if (function_exists('langEnabled') && count(langEnabled($cfg)) > 1): ?>
    <li><?= _h('home.feat_languages') ?></li>
<?php endif; ?>
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
    <button class="copy-btn" data-copy="copy-df-<?= $i ?>" title="<?= _h('home.donate_copy', ['label' => $dfLabel]) ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
    <p><strong><?= $dfLabel ?>:</strong></p><span class="donate-addr" id="copy-df-<?= $i ?>"><?= sanitize($dfValue) ?></span>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
<?php $homeBlocks['donations'] = ob_get_clean(); ?>

<?php ob_start(); /* ── contact ─────────────────────────────────────────── */ ?>
<?php if (($cfg['contact_visible'] ?? '1') === '1'): ?>
<div class="contact-report-cta">
    <?= __('home.contact_cta', ['url' => sanitize($baseUrl . '?action=report')]) ?>
</div>
<p class="contact-disclaimer"><?= _h('home.contact_note_a') ?><?php if (!empty($cfg['site_email'])): ?><?php if (($cfg['contact_obfuscate'] ?? '0') === '1'): ?><a href="#" class="obf-email" data-reveal-email><?= _h('home.contact_reveal') ?></a><?php else: ?><a href="mailto:<?= sanitize($cfg['site_email']) ?>"><?= sanitize($cfg['site_email']) ?></a><?php endif; ?><?php endif; ?></p>
<?php endif; ?>
<?php $homeBlocks['contact'] = ob_get_clean(); ?>
<?php
    ob_end_clean();
    return $homeBlocks;
}

/**
 * What a custom section may paste in. Name => [description, html].
 *
 * The HTML here is TRUSTED — it is either a built-in body this file just rendered, or a scalar
 * escaped right here. It is substituted into the custom section AFTER the renderer has sanitised
 * the operator's text, which is what lets a block with a copy button survive a renderer that
 * strips scripts and inline handlers from author-written markup.
 */
function homePlaceholders(PDO $db, array $cfg, string $baseUrl, ?array $blocks = null): array {
    $blocks = $blocks ?? homeBlocks($db, $cfg, $baseUrl);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $n = fn($v) => $v === null ? '—' : number_format((int)$v);

    $cache = null;
    $cacheFile = __DIR__ . '/../config/stats_cache.json';
    if (is_file($cacheFile)) $cache = json_decode((string)@file_get_contents($cacheFile), true);
    $live = is_array($cache) && !empty($cache['success']);

    $wlCount = null;
    if (function_exists('whitelistStateRead')) {
        $st = whitelistStateRead();
        if (!empty($st['generated_at'])) $wlCount = (int)($st['count'] ?? 0);
    }
    $wlMode = trackerMode($cfg) === 'whitelist';
    $sched = function_exists('scheduleEnabled') && scheduleEnabled($cfg);
    $regOpen = ($wlMode || $sched) && ($cfg['whitelist_public_enabled'] ?? '1') === '1';

    $out = [];
    foreach (homeSectionCatalog() as $key => $meta) {
        $out['block:' . $key] = ['The built-in "' . $meta['label'] . '" section, exactly as the page renders it', $blocks[$key] ?? ''];
    }
    $out['site_name']       = ['The site name', $e($cfg['site_name'] ?? '')];
    $out['site_url']        = ['The site URL', $e($cfg['site_url'] ?? '')];
    $out['announce_http']   = ['The HTTP(S) announce URL', $e($cfg['announce_url_https'] ?? '')];
    $out['announce_udp']    = ['The UDP announce URL', $e($cfg['announce_url'] ?? '')];
    $out['torrent_count']   = ['Torrents tracked right now (from the stats cache)', $live ? $n($cache['torrents'] ?? null) : '—'];
    $out['peer_count']      = ['Peers right now', $live ? $n($cache['peers'] ?? null) : '—'];
    $out['seed_count']      = ['Seeds right now', $live ? $n($cache['seeds'] ?? null) : '—'];
    $out['whitelist_count'] = ['Registered torrents on the whitelist', $n($wlCount)];
    $out['year']            = ['The current year', date('Y')];
    $out['register_button'] = ['The "Register your torrent" button — empty while registration is closed',
        $regOpen ? '<p class="form-center"><a class="btn" href="' . $e($baseUrl) . '?action=whitelist">' . (function_exists('_h') ? _h('home.register_btn') : 'Register your torrent') . '</a></p>' : ''];
    $out['report_link']     = ['A link to the report form',
        '<a href="' . $e($baseUrl) . '?action=report" class="report-link">' . (function_exists('_h') ? _h('nav.report') : 'Report') . '</a>'];
    $out['contact_email']   = ['The site email as a mailto link (empty when none is set)',
        !empty($cfg['site_email']) ? '<a href="mailto:' . $e($cfg['site_email']) . '">' . $e($cfg['site_email']) . '</a>' : ''];
    return $out;
}

/** The names and descriptions only — for the editor's list. */
function homePlaceholderList(): array {
    $out = [];
    foreach (homeSectionCatalog() as $key => $meta) $out['block:' . $key] = 'The built-in "' . $meta['label'] . '" section';
    foreach (['site_name' => 'The site name', 'site_url' => 'The site URL', 'announce_http' => 'The HTTP(S) announce URL',
              'announce_udp' => 'The UDP announce URL', 'torrent_count' => 'Torrents tracked right now',
              'peer_count' => 'Peers right now', 'seed_count' => 'Seeds right now',
              'whitelist_count' => 'Registered torrents', 'year' => 'The current year',
              'register_button' => 'The register button (only while registration is open)',
              'report_link' => 'A link to the report form', 'contact_email' => 'The site email, as a link'] as $k => $v) {
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Paste the placeholders into rendered HTML. Unknown names are removed and reported, never printed.
 *
 * `<p>{{block:x}}</p>` is unwrapped first: Markdown puts a lone token in a paragraph, and a block of
 * <div>s inside a <p> is markup browsers repair by guessing.
 */
function homeApplyPlaceholders(string $html, array $map, ?array &$unknown = null): string {
    $unknown = [];
    $html = preg_replace_callback('/<p>\s*\{\{(block:[a-z_]+)\}\}\s*<\/p>/', function ($m) use ($map, &$unknown) {
        if (!isset($map[$m[1]])) { $unknown[] = $m[1]; return ''; }
        return $map[$m[1]][1];
    }, $html) ?? $html;
    $html = preg_replace_callback('/\{\{([a-z_]+(?::[a-z_]+)?)\}\}/', function ($m) use ($map, &$unknown) {
        if (!isset($map[$m[1]])) { $unknown[] = $m[1]; return ''; }
        return $map[$m[1]][1];
    }, $html) ?? $html;
    $unknown = array_values(array_unique($unknown));
    return $html;
}
