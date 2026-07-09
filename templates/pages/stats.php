<?php
/**
 * TryHackX Tracker - Stats Page Template
 * Displays dynamic tracker telemetry with modern dashboard layout.
 */
if (($cfg['tracker_stats_enabled'] ?? '0') !== '1') {
    // If stats are disabled, redirect to home page
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
    $subSeeds = $seedPct . '% of total peers';
    $subLeech = $leechPct . '% of total peers';
} else {
    $subSeeds = 'of ' . $peersFmt . ' peers';
    $subLeech = 'of ' . $peersFmt . ' peers';
}
$subPeers = $leechFmt . ' leechers &middot; ' . $seedsFmt . ' seeds';

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
        <h1>Tracker Telemetry</h1>
        <p class="stats-subtitle">Live health diagnostics and swarm activity of our public OpenTracker engine.</p>
        
        <?php
        $badgeClass = $hasCache ? ($isCacheFresh ? '' : 'syncing') : 'hidden';
        $beaconText = $hasCache ? ($isCacheFresh ? 'Live Syncing' : 'Syncing Swarms...') : 'Live Syncing';
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
            <h3 id="stats-loader-title">Establishing Connection</h3>
            <p id="stats-loader-subtitle">Querying tracker stats interface...</p>
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
        <h3>Telemetry Fetch Failed</h3>
        <p id="stats-error-msg">The statistics server is currently busy or unreachable. Swarm updates are unaffected.</p>
        <button id="btn-stats-retry" class="btn btn-secondary mt-3">Reconnect Telemetry</button>
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
                    <span class="metric-label">Active Torrents</span>
                    <h2 class="metric-value font-mono" id="val-torrents"><?= number_format($torrents) ?></h2>
                    <span class="metric-sub">Swarm indices monitored</span>
                </div>
            </div>

            <!-- Seeds -->
            <div class="metric-card card-glow-success">
                <div class="metric-icon success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5l-5-5-5 5M2 17h20"/></svg>
                </div>
                <div class="metric-info">
                    <span class="metric-label">Seeds (Uploaders)</span>
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
                    <span class="metric-label">Peers (total)</span>
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
                    <span class="metric-label">Leechers (Swarm)</span>
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
                    <span class="metric-label">Completed Transfers</span>
                    <h2 class="metric-value font-mono text-info" id="val-completed"><?= number_format($completed) ?></h2>
                    <span class="metric-sub">Successful index downloads</span>
                </div>
            </div>
        </div>

        <!-- Details & Protocols Row -->
        <div class="dashboard-row">
            <!-- Telemetry Details -->
            <div class="card flex-1">
                <h3 class="card-title font-mono"><i class="bi bi-cpu"></i> System Status</h3>
                
                <table class="stats-table">
                    <tr>
                        <td>Uptime</td>
                        <td id="val-uptime" class="font-mono text-white"><?= sanitize($uptimeString) ?></td>
                    </tr>
                    <tr>
                        <td>Tracker ID</td>
                        <td id="val-tracker-id" class="font-mono"><?= sanitize($trackerId) ?></td>
                    </tr>
                    <tr>
                        <td>Version Check</td>
                        <td id="val-version">
                            <?php if ($hasCache): ?>
                                <?php if (str_starts_with($cacheData['version'], 'http')): ?>
                                    <a href="<?= sanitize($cacheData['version']) ?>" target="_blank" class="status-link font-mono" style="font-size:0.75rem;">Git Commit <i class="bi bi-box-arrow-up-right"></i></a>
                                <?php else: ?>
                                    <?= sanitize($cacheData['version']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Analyzing...</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Swarm Integrity</td>
                        <td class="text-success"><i class="bi bi-shield-check"></i> Active / Secure</td>
                    </tr>
                    <tr>
                        <td>Sync Loop</td>
                        <td class="pos-relative">
                            <div class="countdown-bar-wrap">
                                <?php
                                $barClass = $isCacheFresh ? '' : 'syncing';
                                $barWidth = $isCacheFresh ? (($remainingSeconds / $interval) * 100) : 100;
                                $countdownTextStr = $isCacheFresh ? "Next update in {$remainingSeconds}s" : "Syncing Swarms...";
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
                <h3 class="card-title font-mono"><i class="bi bi-hdd-network"></i> Protocol Distribution</h3>
                <p class="text-muted" style="font-size:0.8rem;margin:-0.25rem 0 0.75rem;">Share of all announce/scrape/connect requests handled since the tracker started (cumulative).</p>
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
                        <span class="protocol-title text-info">UDP Sockets</span>
                        <div class="split-item">
                            <span>Connects</span>
                            <span class="font-mono text-white" id="val-udp-connect"><?= number_format($udpConnect) ?></span>
                        </div>
                        <div class="split-item">
                            <span>Announces</span>
                            <span class="font-mono text-white" id="val-udp-announce"><?= number_format($udpAnnounce) ?></span>
                        </div>
                        <div class="split-item">
                            <span>Scrapes</span>
                            <span class="font-mono text-white" id="val-udp-scrape"><?= number_format($udpScrape) ?></span>
                        </div>
                        <div class="split-item">
                            <span>Mismatches</span>
                            <span class="font-mono text-warning" id="val-udp-mismatch"><?= number_format($udpMismatch) ?></span>
                        </div>
                    </div>
                    
                    <div class="split-col">
                        <span class="protocol-title text-accent">TCP Sockets</span>
                        <div class="split-item">
                            <span>Accepts</span>
                            <span class="font-mono text-white" id="val-tcp-accept"><?= number_format($tcpAccept) ?></span>
                        </div>
                        <div class="split-item">
                            <span>Announces</span>
                            <span class="font-mono text-white" id="val-tcp-announce"><?= number_format($tcpAnnounce) ?></span>
                        </div>
                        <div class="split-item">
                            <span>Scrapes</span>
                            <span class="font-mono text-white" id="val-tcp-scrape"><?= number_format($tcpScrape) ?></span>
                        </div>
                        <div class="split-item">
                            <span>Live Syncs</span>
                            <span class="font-mono text-success" id="val-tcp-sync"><?= number_format($livesyncCount) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Swarm Activity Heatmap -->
        <div class="card mt-4">
            <h3 class="card-title font-mono"><i class="bi bi-bar-chart-steps"></i> Announce Renewal Intervals</h3>
            <p class="text-muted" style="font-size:0.8rem;margin-bottom:1rem;">Cumulative count of peer announce renewals grouped by their interval bucket (00 - 44 min), totalled since the tracker started. Buckets are lifetime totals, not the current swarm.</p>
            
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
                            $label = sanitize($item['interval']);
                            $countFormatted = number_format($item['count']);
                            $tooltipText = "Interval {$label}m: {$countFormatted} renews";
                    ?>
                        <div class="heat-block level-<?= $level ?>" data-tooltip="<?= $tooltipText ?>">
                            <span><?= $label ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (!$hasCache): ?>
                    <!-- Loaded dynamically via JS -->
                <?php else: ?>
                    <div class="text-center text-muted w-100 py-3">No activity heat profile available.</div>
                <?php endif; ?>
            </div>
            
            <div class="heatmap-legend">
                <span>Low Announce rate</span>
                <div class="legend-scale">
                    <span class="level-0"></span>
                    <span class="level-1"></span>
                    <span class="level-2"></span>
                    <span class="level-3"></span>
                    <span class="level-4"></span>
                </div>
                <span>High Announce rate</span>
            </div>
        </div>

        <!-- Debug Diagnostics -->
        <div id="debug-diagnostics-panel" class="card mt-4 <?= ($hasCache && !empty($cacheData['http_errors'])) ? '' : 'hidden' ?>">
            <h3 class="card-title font-mono text-warning"><i class="bi bi-bug"></i> HTTP Engine Diagnostic Logs</h3>
            <div class="transparency-table-wrap">
                <table class="transparency-table">
                    <thead>
                        <tr>
                            <th>HTTP Response Status</th>
                            <th>Incident Frequency</th>
                            <th>Swarm Severity</th>
                        </tr>
                    </thead>
                    <tbody id="http-errors-body">
                        <?php if ($hasCache && !empty($cacheData['http_errors'])): ?>
                            <?php foreach ($cacheData['http_errors'] as $err): 
                                $code = sanitize($err['code']);
                                $count = number_format($err['count']);
                                $badgeClass = 'status-badge-sm status-badge ';
                                $severity = 'Low';
                                if (str_starts_with($code, '5')) {
                                    $badgeClass .= 'blocked';
                                    $severity = 'Critical';
                                } elseif (str_starts_with($code, '400')) {
                                    $badgeClass .= 'pending';
                                    $severity = 'Moderate';
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
