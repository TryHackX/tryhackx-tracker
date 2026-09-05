<?php
/**
 * TryHackX Tracker - Stats Page Template
 * Displays dynamic tracker telemetry with modern dashboard layout.
 */
if (($cfg['tracker_stats_enabled'] ?? '0') !== '1' || !userCan($db, $cfg, 'stats.view')) {
    // Stats disabled, or this visitor's groups don't include stats access
    header('Location: ' . $baseUrl);
    exit;
}

// Load stats cache for server-side pre-rendering
$cacheFile = __DIR__ . '/../../config/stats_cache.json';
$cacheData = null;
if (file_exists($cacheFile)) {
    $cacheData = json_decode(@file_get_contents($cacheFile), true);
}
$hasCache = $cacheData && ($cacheData['success'] ?? false);

// Calculate cache freshness and remaining time.
// Stats page uses its OWN refresh interval (tracker_stats_page_interval),
// independent of the home page (tracker_stats_interval).
$homeInterval = max(2, (int)($cfg['tracker_stats_interval'] ?? 10));
$interval = max(2, (int)($cfg['tracker_stats_page_interval'] ?? $homeInterval));
// Shared server-side cache lifetime, decoupled from the refresh interval (see tracker_stats.php).
$cacheTtl = max(2, (int)($cfg['tracker_stats_cache_ttl'] ?? 60));
$cacheAge = 999999;
if ($hasCache && isset($cacheData['fetched_at'])) {
    $cacheAge = time() - (int)$cacheData['fetched_at'];
}
// "Fresh" for the page = the shared cache is still within its TTL. While it is, we serve the
// pre-rendered data and run only a lightweight background poll — no forced upstream re-fetch.
$isCacheFresh = $hasCache && ($cacheAge < $cacheTtl);
$remainingSeconds = $isCacheFresh ? max(1, min($interval, $cacheTtl - $cacheAge)) : 0;
// Only show the full-screen loader when there is genuinely no data. If we have any cached
// payload (even one past its TTL), show it immediately and refresh in the background instead
// of flashing the loader over an empty dashboard.
$showLoader = !$hasCache;

// Populate default statistics variables
$torrents = $hasCache ? (int)$cacheData['torrents'] : 0;
$seeds = $hasCache ? (int)$cacheData['seeds'] : 0;
$leechers = $hasCache ? (int)$cacheData['leechers'] : 0;
$completed = $hasCache ? (int)$cacheData['completed'] : 0;
$peers = $hasCache ? (int)$cacheData['peers'] : 0;

$totalPeers = $peers ?: 1;
$seedPct = round(($seeds / $totalPeers) * 100);
$leechPct = round(($leechers / $totalPeers) * 100);

// Peer-label display style (admin-configurable). Controls the subtitles under the
// Seeds/Leechers cards and whether the 3rd card shows Leechers or a combined Peers total.
//   percent    -> "44% of total peers" / "56% of total peers"
//   absolute   -> "of 3,629,991 peers" under both
//   peers_card -> 3rd card becomes "Peers (total)" with "leechers · seeds" subtitle
$peerLabelStyle = $cfg['tracker_stats_peer_label_style'] ?? 'percent';
if (!in_array($peerLabelStyle, ['percent', 'absolute', 'peers_card'], true)) {
    $peerLabelStyle = 'percent';
}
$peersFmt = number_format($peers);
$seedsFmt = number_format($seeds);
$leechFmt = number_format($leechers);
if ($peerLabelStyle === 'percent') {
    $subSeeds = _h('stats.sub_pct', ['pct' => $seedPct]);
    $subLeech = _h('stats.sub_pct', ['pct' => $leechPct]);
} else {
    $subSeeds = _h('stats.sub_abs', ['peers' => $peersFmt]);
    $subLeech = _h('stats.sub_abs', ['peers' => $peersFmt]);
}
// Holds a &middot; entity, so it goes out raw — __(), not _h().
$subPeers = __('stats.sub_peers', ['leechers' => $leechFmt, 'seeds' => $seedsFmt]);

$uptimeString = $hasCache ? $cacheData['uptime_string'] : '0 sec';
$trackerId = $hasCache ? $cacheData['tracker_id'] : 'N/A';

$tcpAccept = $hasCache ? (int)$cacheData['connections']['tcp']['accept'] : 0;
$tcpAnnounce = $hasCache ? (int)$cacheData['connections']['tcp']['announce'] : 0;
$tcpScrape = $hasCache ? (int)$cacheData['connections']['tcp']['scrape'] : 0;
$livesyncCount = $hasCache ? (int)$cacheData['connections']['livesync'] : 0;

$udpConnect = $hasCache ? (int)$cacheData['connections']['udp']['connect'] : 0;
$udpAnnounce = $hasCache ? (int)$cacheData['connections']['udp']['announce'] : 0;
$udpScrape = $hasCache ? (int)$cacheData['connections']['udp']['scrape'] : 0;
$udpMismatch = $hasCache ? (int)$cacheData['connections']['udp']['mismatch'] : 0;

$udpCount = $udpConnect + $udpAnnounce + $udpScrape;
$tcpCount = $tcpAccept + $tcpAnnounce + $tcpScrape;
$totalConns = ($udpCount + $tcpCount) ?: 1;
$udpPct = round(($udpCount / $totalConns) * 100);
$tcpPct = 100 - $udpPct;
?>

<?php $renderedAt = $hasCache ? (int)($cacheData['fetched_at'] ?? 0) : 0; ?>
<div id="stats-page-container" class="stats-page-container"
     data-interval="<?= $interval ?>"
     data-source="stats"
     data-peer-label-style="<?= sanitize($peerLabelStyle) ?>"
     data-has-cache="<?= $hasCache ? '1' : '0' ?>"
     data-cache-fresh="<?= $isCacheFresh ? '1' : '0' ?>"
     data-remaining-seconds="<?= $remainingSeconds ?>"
     data-fetched-at="<?= $renderedAt ?>">
    <div class="stats-header">
        <h1><?= _h('stats.h1') ?></h1>
        <p class="stats-subtitle"><?= _h('stats.subtitle') ?></p>

        <?php
        $badgeClass = $hasCache ? ($isCacheFresh ? '' : 'syncing') : 'hidden';
        $beaconText = $hasCache ? ($isCacheFresh ? _h('stats.beacon_live') : _h('stats.beacon_sync')) : _h('stats.beacon_live');
        ?>
        <!-- Live status badge -->
        <div id="stats-live-badge" class="stats-live-badge <?= $badgeClass ?>">
            <span class="pulse-dot"></span>
            <span id="stats-beacon-text"><?= $beaconText ?></span>
        </div>
    </div>

    <!-- === Loader View === -->
    <div id="stats-loader" class="stats-loader-container <?= $showLoader ? '' : 'hidden' ?>">
        <div class="loader-graphic">
            <div class="spinner-ring"></div>
            <div class="spinner-ring-inner"></div>
            <div class="loader-core"></div>
        </div>
        <div class="loader-text-wrap">
            <h3 id="stats-loader-title"><?= _h('stats.loader_title') ?></h3>
            <p id="stats-loader-subtitle"><?= _h('stats.loader_sub') ?></p>
        </div>
        
        <!-- Skeleton grid -->
        <div class="skeleton-grid">
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
        </div>
    </div>

    <!-- === Error View === -->
    <div id="stats-error" class="stats-error-container hidden">
        <div class="error-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--error)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h3><?= _h('stats.error_title') ?></h3>
        <p id="stats-error-msg"><?= _h('stats.error_msg') ?></p>
        <button id="btn-stats-retry" class="btn btn-secondary mt-3"><?= _h('stats.retry') ?></button>
    </div>

    <!-- === Main Dashboard === -->
    <div id="stats-dashboard" class="stats-dashboard-content <?= $showLoader ? 'hidden' : '' ?>">
        
        <!-- Counters row -->
        <div class="metrics-grid">
            <!-- Torrents -->
            <div class="metric-card card-glow-accent">
                <div class="metric-icon accent-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 6v6l4 2"/></svg>
                </div>
                <div class="metric-info">
                    <span class="metric-label"><?= _h('stats.m_torrents') ?></span>
                    <h2 class="metric-value font-mono" id="val-torrents"><?= number_format($torrents) ?></h2>
                    <span class="metric-sub"><?= _h('stats.m_torrents_sub') ?></span>
                </div>
            </div>

            <!-- Seeds -->
            <div class="metric-card card-glow-success">
                <div class="metric-icon success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5l-5-5-5 5M2 17h20"/></svg>
                </div>
                <div class="metric-info">
                    <span class="metric-label"><?= _h('stats.m_seeds') ?></span>
                    <h2 class="metric-value font-mono text-success" id="val-seeds"><?= number_format($seeds) ?></h2>
                    <span class="metric-sub" id="sub-seeds"><?= $subSeeds ?></span>
                </div>
            </div>

            <?php if ($peerLabelStyle === 'peers_card'): ?>
            <!-- Peers (total) -->
            <div class="metric-card card-glow-warning">
                <div class="metric-icon warning-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="metric-info">
                    <span class="metric-label"><?= _h('stats.m_peers') ?></span>
                    <h2 class="metric-value font-mono text-warning" id="val-peers"><?= number_format($peers) ?></h2>
                    <span class="metric-sub" id="sub-peers"><?= $subPeers ?></span>
                </div>
            </div>
            <?php else: ?>
            <!-- Leechers -->
            <div class="metric-card card-glow-warning">
                <div class="metric-icon warning-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V2M17 19l-5 5-5-5M2 7h20"/></svg>
                </div>
                <div class="metric-info">
                    <span class="metric-label"><?= _h('stats.m_leechers') ?></span>
                    <h2 class="metric-value font-mono text-warning" id="val-leechers"><?= number_format($leechers) ?></h2>
                    <span class="metric-sub" id="sub-leechers"><?= $subLeech ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Completed -->
            <div class="metric-card card-glow-link">
                <div class="metric-icon link-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="metric-info">
                    <span class="metric-label"><?= _h('stats.m_completed') ?></span>
                    <h2 class="metric-value font-mono text-info" id="val-completed"><?= number_format($completed) ?></h2>
                    <span class="metric-sub"><?= _h('stats.m_completed_sub') ?></span>
                </div>
            </div>
        </div>

        <?php if (statsTimelineEnabled($cfg) && statsTimelinePublic($cfg)): ?>
        <!-- Swarm timeline (stock-style chart; assets/js/stats-timeline.js + vendored uPlot) -->
        <div class="card tl-card mt-4">
            <h3 class="card-title font-mono"><i class="bi bi-graph-up"></i> <?= _h('stats.tl_title') ?></h3>
            <p class="text-muted" style="font-size:0.8rem;margin:-0.25rem 0 0.75rem;"><?= _h('stats.tl_note', ['sec' => (int)statsTimelineInterval($cfg)]) ?></p>
            <div id="stats-timeline"<?= statsTimelineMountAttrs($cfg) ?>></div>
        </div>
        <?php endif; ?>

        <!-- Details & Protocols Row -->
        <div class="dashboard-row">
            <!-- Telemetry Details -->
            <div class="card flex-1">
                <h3 class="card-title font-mono"><i class="bi bi-cpu"></i> <?= _h('stats.sys_title') ?></h3>
                
                <table class="stats-table">
                    <tr>
                        <td><?= _h('stats.uptime') ?></td>
                        <td id="val-uptime" class="font-mono text-white"><?= sanitize($uptimeString) ?></td>
                    </tr>
                    <tr>
                        <td><?= _h('stats.tracker_id') ?></td>
                        <td id="val-tracker-id" class="font-mono"><?= sanitize($trackerId) ?></td>
                    </tr>
                    <tr>
                        <td><?= _h('stats.version_check') ?></td>
                        <td id="val-version">
                            <?php if ($hasCache): ?>
                                <?php if (str_starts_with($cacheData['version'], 'http')): ?>
                                    <a href="<?= sanitize($cacheData['version']) ?>" target="_blank" class="status-link font-mono" style="font-size:0.75rem;"><?= _h('stats.git_commit') ?> <i class="bi bi-box-arrow-up-right"></i></a>
                                <?php else: ?>
                                    <?= sanitize($cacheData['version']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted"><?= _h('stats.analyzing') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?= _h('stats.integrity') ?></td>
                        <td class="text-success"><i class="bi bi-shield-check"></i> <?= _h('stats.integrity_ok') ?></td>
                    </tr>
                    <tr>
                        <td><?= _h('stats.sync_loop') ?></td>
                        <td class="pos-relative">
                            <div class="countdown-bar-wrap">
                                <?php
                                $barClass = $isCacheFresh ? '' : 'syncing';
                                $barWidth = $isCacheFresh ? (($remainingSeconds / $interval) * 100) : 100;
                                $countdownTextStr = $isCacheFresh
                                    ? _h('stats.next_update', ['sec' => $remainingSeconds])
                                    : _h('stats.beacon_sync');
                                ?>
                                <div id="countdown-bar" class="countdown-bar <?= $barClass ?>" style="width: <?= $barWidth ?>%"></div>
                            </div>
                            <small class="text-muted" id="countdown-text"><?= $countdownTextStr ?></small>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Swarm Protocol Distribution -->
            <div class="card flex-1">
                <h3 class="card-title font-mono"><i class="bi bi-hdd-network"></i> <?= _h('stats.proto_title') ?></h3>
                <p class="text-muted" style="font-size:0.8rem;margin:-0.25rem 0 0.75rem;"><?= _h('stats.proto_note') ?></p>
                <div class="swarm-bar-chart">
                    <div class="swarm-bar-labels">
                        <span>UDP (<strong id="val-udp-pct"><?= $udpPct ?>%</strong>)</span>
                        <span>TCP (<strong id="val-tcp-pct"><?= $tcpPct ?>%</strong>)</span>
                    </div>
                    <div class="swarm-bar-visual">
                        <div id="bar-udp" class="bar-segment bar-udp" style="width: <?= $udpPct ?>%"></div>
                        <div id="bar-tcp" class="bar-segment bar-tcp" style="width: <?= $tcpPct ?>%"></div>
                    </div>
                </div>

                <div class="protocol-splits">
                    <div class="split-col">
                        <span class="protocol-title text-info"><?= _h('stats.udp_sockets') ?></span>
                        <div class="split-item">
                            <span><?= _h('stats.connects') ?></span>
                            <span class="font-mono text-white" id="val-udp-connect"><?= number_format($udpConnect) ?></span>
                        </div>
                        <div class="split-item">
                            <span><?= _h('stats.announces') ?></span>
                            <span class="font-mono text-white" id="val-udp-announce"><?= number_format($udpAnnounce) ?></span>
                        </div>
                        <div class="split-item">
                            <span><?= _h('stats.scrapes') ?></span>
                            <span class="font-mono text-white" id="val-udp-scrape"><?= number_format($udpScrape) ?></span>
                        </div>
                        <div class="split-item">
                            <span><?= _h('stats.mismatches') ?></span>
                            <span class="font-mono text-warning" id="val-udp-mismatch"><?= number_format($udpMismatch) ?></span>
                        </div>
                    </div>
                    
                    <div class="split-col">
                        <span class="protocol-title text-accent"><?= _h('stats.tcp_sockets') ?></span>
                        <div class="split-item">
                            <span><?= _h('stats.accepts') ?></span>
                            <span class="font-mono text-white" id="val-tcp-accept"><?= number_format($tcpAccept) ?></span>
                        </div>
                        <div class="split-item">
                            <span><?= _h('stats.announces') ?></span>
                            <span class="font-mono text-white" id="val-tcp-announce"><?= number_format($tcpAnnounce) ?></span>
                        </div>
                        <div class="split-item">
                            <span><?= _h('stats.scrapes') ?></span>
                            <span class="font-mono text-white" id="val-tcp-scrape"><?= number_format($tcpScrape) ?></span>
                        </div>
                        <div class="split-item">
                            <span><?= _h('stats.live_syncs') ?></span>
                            <span class="font-mono text-success" id="val-tcp-sync"><?= number_format($livesyncCount) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Swarm Activity Heatmap -->
        <div class="card mt-4">
            <h3 class="card-title font-mono"><i class="bi bi-bar-chart-steps"></i> <?= _h('stats.heat_title') ?></h3>
            <p class="text-muted" style="font-size:0.8rem;margin-bottom:1rem;"><?= _h('stats.heat_note') ?></p>
            
            <div id="renew-heatmap" class="renew-heatmap">
                <?php if ($hasCache && !empty($cacheData['renew_intervals'])): ?>
                    <?php
                        $intervals = $cacheData['renew_intervals'];
                        $maxCount = max(array_column($intervals, 'count')) ?: 1;
                        foreach ($intervals as $item):
                            $level = 0;
                            $ratio = $item['count'] / $maxCount;
                            if ($item['count'] > 0) {
                                if ($ratio < 0.1) $level = 1;
                                elseif ($ratio < 0.4) $level = 2;
                                elseif ($ratio < 0.75) $level = 3;
                                else $level = 4;
                            }
                            // The bucket label comes from the tracker's own XML, so it gets escaped
                            // exactly once on each way out — by sanitize() for the block, by _h()
                            // for the tooltip. Handing the sanitized copy to _h() would escape it
                            // twice, so both start from the raw value.
                            $rawLabel = trim((string)$item['interval']);
                            $label = sanitize($rawLabel);
                            $countFormatted = number_format($item['count']);
                            $tooltipText = _h('stats.heat_tip', ['min' => $rawLabel, 'count' => $countFormatted]);
                    ?>
                        <div class="heat-block level-<?= $level ?>" data-tooltip="<?= $tooltipText ?>">
                            <span><?= $label ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (!$hasCache): ?>
                    <!-- Loaded dynamically via JS -->
                <?php else: ?>
                    <div class="text-center text-muted w-100 py-3"><?= _h('stats.heat_empty') ?></div>
                <?php endif; ?>
            </div>
            
            <div class="heatmap-legend">
                <span><?= _h('stats.legend_low') ?></span>
                <div class="legend-scale">
                    <span class="level-0"></span>
                    <span class="level-1"></span>
                    <span class="level-2"></span>
                    <span class="level-3"></span>
                    <span class="level-4"></span>
                </div>
                <span><?= _h('stats.legend_high') ?></span>
            </div>
        </div>

        <!-- Debug Diagnostics -->
        <div id="debug-diagnostics-panel" class="card mt-4 <?= ($hasCache && !empty($cacheData['http_errors'])) ? '' : 'hidden' ?>">
            <h3 class="card-title font-mono text-warning"><i class="bi bi-bug"></i> <?= _h('stats.http_title') ?></h3>
            <div class="transparency-table-wrap">
                <table class="transparency-table">
                    <thead>
                        <tr>
                            <th><?= _h('stats.http_status') ?></th>
                            <th><?= _h('stats.http_count') ?></th>
                            <th><?= _h('stats.http_severity') ?></th>
                        </tr>
                    </thead>
                    <tbody id="http-errors-body">
                        <?php if ($hasCache && !empty($cacheData['http_errors'])): ?>
                            <?php foreach ($cacheData['http_errors'] as $err): 
                                $code = sanitize($err['code']);
                                $count = number_format($err['count']);
                                $badgeClass = 'status-badge-sm status-badge ';
                                $severity = _h('stats.sev_low');
                                if (str_starts_with($code, '5')) {
                                    $badgeClass .= 'blocked';
                                    $severity = _h('stats.sev_critical');
                                } elseif (str_starts_with($code, '400')) {
                                    $badgeClass .= 'pending';
                                    $severity = _h('stats.sev_moderate');
                                } else {
                                    $badgeClass .= 'archived';
                                }
                            ?>
                                <tr>
                                    <td class="font-mono text-white"><?= $code ?></td>
                                    <td class="font-mono"><?= $count ?></td>
                                    <td><span class="<?= $badgeClass ?>"><?= $severity ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</div>
