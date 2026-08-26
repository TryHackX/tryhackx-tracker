<?php
/**
 * Statistics timeline ("stock chart" of the swarm).
 *
 * Design:
 *  - One shared parser for OpenTracker's `stats?mode=everything` XML (parseTrackerStatsXml()) used by
 *    the public stats endpoint (api/tracker_stats.php) AND by the sampler here, so both read the same
 *    numbers.
 *  - Samples come from two sources that both respect the configured interval (no double work):
 *      1. the janitor timer (tools/janitor.php → statsTimelineTick()): when the shared stats cache
 *         (config/stats_cache.json) is fresh it is reused, otherwise the upstream is fetched;
 *      2. every successful upstream fetch made by the public stats endpoint (statsTimelineIngest()) —
 *         a busy site samples itself for free, the timer only fills the quiet hours.
 *  - Storage: `stats_samples` (raw, one row per sample, retention stats_timeline_raw_days),
 *    `stats_samples_5m` and `stats_samples_1h` (roll-ups: avg/min/max of the gauges, last value of the
 *    cumulative counters, share of whitelist-mode samples). Timestamps are UNIX seconds (INT) — no
 *    DATETIME/timezone ambiguity between PHP, MySQL and the browser.
 *  - Roll-ups/prune run from the tick; the state (last sample, roll-up cursors, last error) lives in
 *    config/stats_timeline_state.json under flock, like the whitelist state file.
 *
 * Settings (schema v5, Settings → Statistics Timeline): stats_timeline_enabled (0/1),
 * stats_timeline_interval (seconds, 30–600), stats_timeline_raw_days (1–30),
 * stats_timeline_keep_days (5-minute roll-ups, 7–3650; hourly roll-ups are kept forever),
 * stats_timeline_public (0/1: the public stats page shows the chart / the API answers anonymous calls).
 * Schema v10 added the chart's own controls: stats_timeline_ranges (which range buttons exist),
 * stats_timeline_default_range (which one opens) and stats_timeline_custom_range (0/1: a free span
 * slider; its spans are snapped to statsTimelineCustomStops() so each one caches like a named range).
 * The chart is mounted by statsTimelineMountAttrs() on the public stats page and on the admin Index
 * and Whitelist pages.
 */

const ST_INTERVAL_MIN   = 30;
const ST_INTERVAL_MAX   = 600;
const ST_RAW_TABLE      = 'stats_samples';
const ST_5M_TABLE       = 'stats_samples_5m';
const ST_1H_TABLE       = 'stats_samples_1h';
const ST_ROLLUP_MAX_BUCKETS = 2000;    // buckets rolled per tick per table (bounds one INSERT…SELECT)
const ST_PRUNE_EVERY    = 3600;        // seconds between prune runs
const ST_API_CACHE_TTL  = 30;          // seconds the stats_timeline JSON is cached per range
const ST_MAX_RAW_POINTS = 3000;        // above this the API switches raw → 5m
const ST_MAX_5M_POINTS  = 4500;        // above this the API switches 5m → 1h

/** Named ranges accepted by the API (aliases included) → seconds. 'all' = the whole recorded history. */
function statsTimelineRanges(): array {
    return ['24h' => 86400, '7d' => 7 * 86400, '14d' => 14 * 86400, '2w' => 14 * 86400,
            '30d' => 30 * 86400, '1m' => 30 * 86400, '60d' => 60 * 86400, '2m' => 60 * 86400,
            '90d' => 90 * 86400, '3m' => 90 * 86400, 'all' => 20 * 365 * 86400];
}

/**
 * Range buttons offered by the chart, in display order: key => button label. Mirrors the RANGES
 * table in assets/js/stats-timeline.js (the JS builds its buttons from data-ranges, this list is
 * what Settings shows and validates against).
 */
function statsTimelineRangeButtons(): array {
    return ['24h' => '24h', '7d' => '7d', '14d' => '2w', '30d' => '1m', '90d' => '3m', 'all' => 'All'];
}

/** Enabled range buttons (always at least one; unknown keys dropped, display order kept). */
function statsTimelineEnabledRanges(array $cfg): array {
    $all = array_keys(statsTimelineRangeButtons());
    $raw = trim((string)($cfg['stats_timeline_ranges'] ?? ''));
    if ($raw === '') return $all;
    $want = array_filter(array_map('trim', explode(',', strtolower($raw))));
    $keep = array_values(array_intersect($all, $want));   // intersect keeps $all's order
    return $keep ?: $all;
}

/** Range the chart opens on (falls back to the first enabled button when misconfigured). */
function statsTimelineDefaultRange(array $cfg): string {
    $enabled = statsTimelineEnabledRanges($cfg);
    $def = strtolower(trim((string)($cfg['stats_timeline_default_range'] ?? '24h')));
    return in_array($def, $enabled, true) ? $def : $enabled[0];
}

/** Free "Custom" span control (slider) next to the range buttons. */
function statsTimelineCustomRange(array $cfg): bool { return (($cfg['stats_timeline_custom_range'] ?? '0') === '1'); }

/**
 * Spans the "Custom" slider can produce, in seconds (1 h … 5 years). MIRRORS CUSTOM_STOPS in
 * assets/js/stats-timeline.js — keep the two lists identical. Server-side the list matters for more
 * than validation: a custom reply is cached per span like a named range, so a bounded list means a
 * bounded number of cache files in config/ no matter what a crafted URL asks for.
 */
function statsTimelineCustomStops(): array {
    return [3600, 7200, 10800, 21600, 43200, 86400, 172800, 259200, 432000, 604800,
            864000, 1209600, 1814400, 2592000, 3888000, 5184000, 7776000, 10368000, 15552000, 23328000,
            31536000, 47304000, 63072000, 94608000, 157680000];
}

/** Snap any requested custom span to the nearest slider stop (bounds the cache key space). */
function statsTimelineSnapSpan(int $seconds): int {
    $best = statsTimelineCustomStops()[0];
    foreach (statsTimelineCustomStops() as $stop) {
        if (abs($stop - $seconds) < abs($best - $seconds)) $best = $stop;
    }
    return $best;
}

/**
 * data-* attributes for a [data-timeline] mount point, so every page (public stats, admin Index,
 * admin Whitelist) offers exactly the ranges the admin configured. $compact adds data-compact="1".
 */
function statsTimelineMountAttrs(array $cfg, bool $compact = false): string {
    $attrs = ' data-timeline'
        . ' data-range="' . htmlspecialchars(statsTimelineDefaultRange($cfg), ENT_QUOTES, 'UTF-8') . '"'
        . ' data-ranges="' . htmlspecialchars(implode(',', statsTimelineEnabledRanges($cfg)), ENT_QUOTES, 'UTF-8') . '"'
        . ' data-custom="' . (statsTimelineCustomRange($cfg) ? '1' : '0') . '"';
    if ($compact) $attrs .= ' data-compact="1"';
    return $attrs;
}

function statsTimelineEnabled(array $cfg): bool { return (($cfg['stats_timeline_enabled'] ?? '0') === '1'); }
function statsTimelinePublic(array $cfg): bool  { return (($cfg['stats_timeline_public'] ?? '1') === '1'); }
function statsTimelineInterval(array $cfg): int {
    $n = (int)($cfg['stats_timeline_interval'] ?? 60);
    return max(ST_INTERVAL_MIN, min(ST_INTERVAL_MAX, $n > 0 ? $n : 60));
}
function statsTimelineRawDays(array $cfg): int  { return max(1, min(30, (int)($cfg['stats_timeline_raw_days'] ?? 7) ?: 7)); }
function statsTimelineKeepDays(array $cfg): int { return max(7, min(3650, (int)($cfg['stats_timeline_keep_days'] ?? 60) ?: 60)); }

// ─────────────────────────────────────────────────────────────────────────────
// Shared OpenTracker stats XML fetch + parse
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET the stats XML. Returns ['body'=>?string,'error'=>?string,'ms'=>int,'http_code'=>int].
 * curl when available (TLS verified for https URLs), streams otherwise.
 */
function fetchTrackerStatsXml(string $url, int $timeout = 30): array {
    $t0 = microtime(true);
    $out = ['body' => null, 'error' => null, 'ms' => 0, 'http_code' => 0];
    $timeout = max(2, $timeout);
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'TryHackX Tracker Status Bot/1.0',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $out['http_code'] = $code;
        if ($body === false) $out['error'] = 'cURL error: ' . $err;
        elseif ($code !== 200) $out['error'] = 'HTTP error code: ' . $code;
        else $out['body'] = $body;
    }
    if ($out['body'] === null && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: TryHackX Tracker Status Bot/1.0\r\n"]]);
        $fetched = @file_get_contents($url, false, $ctx);
        if ($fetched !== false && $fetched !== '') { $out['body'] = $fetched; $out['error'] = null; }
        elseif ($out['error'] === null) $out['error'] = 'file_get_contents failed to fetch URL.';
    }
    if ($out['body'] === null && $out['error'] === null) $out['error'] = 'No HTTP client available (curl / allow_url_fopen).';
    if (is_string($out['body']) && trim($out['body']) === '') { $out['body'] = null; $out['error'] = 'Empty response from tracker.'; }
    $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
    return $out;
}

/**
 * Parse OpenTracker's `stats?mode=everything` XML into the flat structure used by the stats page cache
 * and by the timeline sampler. Returns null when the document is not valid XML.
 */
function parseTrackerStatsXml(string $xmlContent): ?array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string(trim($xmlContent));
    if ($xml === false) { libxml_clear_errors(); return null; }

    $uptimeSeconds = (int)($xml->uptime ?? 0);
    $torrentsMutex    = (int)($xml->torrents->count_mutex ?? 0);
    $torrentsIterator = (int)($xml->torrents->count_iterator ?? 0);
    $peers = (int)($xml->peers->count ?? 0);
    $seeds = (int)($xml->seeds->count ?? 0);

    $httpErrors = [];
    if (isset($xml->debug->http_error->count)) {
        foreach ($xml->debug->http_error->count as $err) {
            $code  = (string)($err['code'] ?? 'Unknown');
            $count = (int)$err;
            if ($count > 0 || $code !== 'Unknown') $httpErrors[] = ['code' => $code, 'count' => $count];
        }
    }
    $renewIntervals = [];
    if (isset($xml->debug->renew->count)) {
        foreach ($xml->debug->renew->count as $renew) {
            $renewIntervals[] = ['interval' => (string)($renew['interval'] ?? ''), 'count' => (int)$renew];
        }
    }
    return [
        'tracker_id'     => (string)($xml->tracker_id ?? ''),
        'version'        => trim((string)($xml->version ?? '')),
        'uptime_seconds' => $uptimeSeconds,
        'uptime_string'  => formatUptime($uptimeSeconds),
        'torrents'       => max($torrentsMutex, $torrentsIterator),
        'peers'          => $peers,
        'seeds'          => $seeds,
        'leechers'       => max(0, $peers - $seeds),
        'completed'      => (int)($xml->completed->count ?? 0),
        'connections'    => [
            'tcp' => [
                'accept'   => (int)($xml->connections->tcp->accept ?? 0),
                'announce' => (int)($xml->connections->tcp->announce ?? 0),
                'scrape'   => (int)($xml->connections->tcp->scrape ?? 0),
            ],
            'udp' => [
                'overall'  => (int)($xml->connections->udp->overall ?? 0),
                'connect'  => (int)($xml->connections->udp->connect ?? 0),
                'announce' => (int)($xml->connections->udp->announce ?? 0),
                'scrape'   => (int)($xml->connections->udp->scrape ?? 0),
                'mismatch' => (int)($xml->connections->udp->missmatch ?? 0),
            ],
            'livesync'    => (int)($xml->connections->livesync->count ?? 0),
        ],
        'http_errors'    => $httpErrors,
        'renew_intervals'=> $renewIntervals,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// State file
// ─────────────────────────────────────────────────────────────────────────────

function statsTimelineStateFile(): string { return __DIR__ . '/../config/stats_timeline_state.json'; }
function statsTimelineStateLockFile(): string { return __DIR__ . '/../config/stats_timeline_state.lock'; }

function statsTimelineStateDefaults(): array {
    return [
        'last_sample_at' => 0, 'last_sample_source' => '', 'last_sample' => null, 'samples_total' => 0,
        'last_error' => null, 'last_error_at' => 0, 'last_tick_at' => 0,
        'rollup_5m_until' => 0, 'rollup_1h_until' => 0, 'last_rollup_at' => 0,
        'last_prune_at' => 0, 'last_prune' => null,
    ];
}

function statsTimelineStateRead(): array {
    $f = statsTimelineStateFile();
    $data = [];
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return array_merge(statsTimelineStateDefaults(), is_array($data) ? $data : []);
}

/** Atomic read-modify-write under flock. $fn(&$state) may return false to skip the write. */
function statsTimelineStateUpdate(callable $fn): array {
    $lockH = @fopen(statsTimelineStateLockFile(), 'c');
    if ($lockH) @flock($lockH, LOCK_EX);
    try {
        $state = statsTimelineStateRead();
        $r = $fn($state);
        if ($r !== false) {
            $tmp = statsTimelineStateFile() . '.tmp.' . getmypid();
            @file_put_contents($tmp, json_encode($state), LOCK_EX);
            @rename($tmp, statsTimelineStateFile());
        }
        return $state;
    } finally {
        if ($lockH) { @flock($lockH, LOCK_UN); @fclose($lockH); }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Sampling
// ─────────────────────────────────────────────────────────────────────────────

/** Is a new sample due? Small slack so a 60 s timer with a 60 s interval never skips every other tick. */
function statsTimelineSampleDue(array $state, array $cfg, int $now): bool {
    $interval = statsTimelineInterval($cfg);
    $slack = min(5, intdiv($interval, 4));
    return $now >= (int)$state['last_sample_at'] + $interval - $slack;
}

/** Build the row to store from a parsed stats array (see parseTrackerStatsXml()). */
function statsTimelineRowFromParsed(PDO $db, array $cfg, array $p, int $now): array {
    $udp = $p['connections']['udp'] ?? [];
    $tcp = $p['connections']['tcp'] ?? [];
    $wl = 0;
    try { $wl = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 0")->fetchColumn(); } catch (\Throwable $e) {}
    $idx = 0;
    if (function_exists('indexRowsCount')) { try { $idx = (int)indexRowsCount($db); } catch (\Throwable $e) {} }
    $peers = max(0, (int)($p['peers'] ?? 0));
    $seeds = max(0, (int)($p['seeds'] ?? 0));
    return [
        'ts' => $now,
        'torrents' => max(0, (int)($p['torrents'] ?? 0)),
        'peers' => $peers, 'seeds' => $seeds,
        'leechers' => max(0, (int)($p['leechers'] ?? ($peers - $seeds))),
        'completed' => max(0, (int)($p['completed'] ?? 0)),
        'udp_announces' => max(0, (int)($udp['announce'] ?? 0)),
        'tcp_announces' => max(0, (int)($tcp['announce'] ?? 0)),
        'connects' => max(0, (int)($udp['connect'] ?? 0)),
        'scrapes' => max(0, (int)($udp['scrape'] ?? 0)) + max(0, (int)($tcp['scrape'] ?? 0)),
        'uptime' => max(0, (int)($p['uptime_seconds'] ?? 0)),
        'mode' => trackerMode($cfg),
        'whitelist_count' => $wl,
        'index_rows' => $idx,
    ];
}

/**
 * Store one sample if the interval has elapsed (or $force). Safe to call from any code path that has a
 * freshly parsed stats document. Returns ['stored'=>bool,'reason'=>string].
 */
function statsTimelineIngest(PDO $db, array $cfg, array $parsed, ?int $now = null, string $source = 'web', bool $force = false): array {
    if (!statsTimelineEnabled($cfg)) return ['stored' => false, 'reason' => 'disabled'];
    $now = $now ?? time();
    $result = ['stored' => false, 'reason' => 'not_due'];
    statsTimelineStateUpdate(function (array &$st) use ($db, $cfg, $parsed, $now, $source, $force, &$result) {
        if (!$force && !statsTimelineSampleDue($st, $cfg, $now)) return false;
        if (!$force && $now <= (int)$st['last_sample_at']) { $result['reason'] = 'duplicate_ts'; return false; }
        try {
            $row = statsTimelineRowFromParsed($db, $cfg, $parsed, $now);
            $cols = array_keys($row);
            $sql = "INSERT IGNORE INTO `" . ST_RAW_TABLE . "` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
            $db->prepare($sql)->execute(array_values($row));
            $st['last_sample_at'] = max((int)$st['last_sample_at'], $now);
            $st['last_sample_source'] = $source;
            $st['last_sample'] = ['torrents' => $row['torrents'], 'peers' => $row['peers'], 'seeds' => $row['seeds'], 'leechers' => $row['leechers'], 'mode' => $row['mode']];
            $st['samples_total'] = (int)$st['samples_total'] + 1;
            $st['last_error'] = null;
            $result = ['stored' => true, 'reason' => 'ok'];
            return true;
        } catch (\Throwable $e) {
            $st['last_error'] = 'insert: ' . $e->getMessage();
            $st['last_error_at'] = $now;
            $result = ['stored' => false, 'reason' => 'error: ' . $e->getMessage()];
            return true;
        }
    });
    return $result;
}

/** Read the shared stats cache (config/stats_cache.json) if it is a successful payload; null otherwise. */
function statsTimelineReadStatsCache(): ?array {
    $f = __DIR__ . '/../config/stats_cache.json';
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if (!$raw) return null;
    $d = json_decode($raw, true);
    return (is_array($d) && !empty($d['success'])) ? $d : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Roll-ups and retention
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Roll complete raw buckets of $bucket seconds into $table. $state[$key] is the exclusive cursor (bucket
 * start) up to which roll-ups are complete. Bounded to ST_ROLLUP_MAX_BUCKETS per call. Returns buckets rolled.
 */
function statsTimelineRollupTable(PDO $db, int $bucket, string $table, array &$state, string $key, int $now): int {
    $end = intdiv($now, $bucket) * $bucket;            // start of the current (incomplete) bucket
    $until = (int)($state[$key] ?? 0);
    $min = $db->query("SELECT MIN(ts) FROM `" . ST_RAW_TABLE . "`")->fetchColumn();
    if ($min === null || $min === false) return 0;
    $min = (int)$min;
    if ($until === 0) {
        // (re)initialised cursor: start at the first bucket that begins at/after the oldest row — the bucket
        // containing MIN(ts) may be partially pruned and must not overwrite a complete roll-up
        $until = intdiv($min + $bucket - 1, $bucket) * $bucket;
    } elseif ($until < intdiv($min, $bucket) * $bucket) {
        // nothing exists below the oldest row — never crawl through empty (pruned) history
        $until = intdiv($min, $bucket) * $bucket;
    }
    if ($until >= $end) { $state[$key] = $until; return 0; }
    $to = min($end, $until + $bucket * ST_ROLLUP_MAX_BUCKETS);
    $sql = "INSERT INTO `$table` (ts, samples, torrents_avg, torrents_min, torrents_max, peers_avg, peers_min, peers_max,
                seeds_avg, seeds_min, seeds_max, leechers_avg, leechers_min, leechers_max, completed, udp_announces, tcp_announces,
                connects, scrapes, uptime, wl_share, whitelist_count, index_rows)
            SELECT FLOOR(ts / $bucket) * $bucket AS b, COUNT(*), ROUND(AVG(torrents)), MIN(torrents), MAX(torrents),
                ROUND(AVG(peers)), MIN(peers), MAX(peers), ROUND(AVG(seeds)), MIN(seeds), MAX(seeds),
                ROUND(AVG(leechers)), MIN(leechers), MAX(leechers), MAX(completed), MAX(udp_announces), MAX(tcp_announces),
                MAX(connects), MAX(scrapes), MAX(uptime), ROUND(AVG(mode = 'whitelist') * 100), MAX(whitelist_count), MAX(index_rows)
            FROM `" . ST_RAW_TABLE . "` WHERE ts >= ? AND ts < ? GROUP BY b
            ON DUPLICATE KEY UPDATE samples = VALUES(samples), torrents_avg = VALUES(torrents_avg), torrents_min = VALUES(torrents_min),
                torrents_max = VALUES(torrents_max), peers_avg = VALUES(peers_avg), peers_min = VALUES(peers_min), peers_max = VALUES(peers_max),
                seeds_avg = VALUES(seeds_avg), seeds_min = VALUES(seeds_min), seeds_max = VALUES(seeds_max), leechers_avg = VALUES(leechers_avg),
                leechers_min = VALUES(leechers_min), leechers_max = VALUES(leechers_max), completed = VALUES(completed),
                udp_announces = VALUES(udp_announces), tcp_announces = VALUES(tcp_announces), connects = VALUES(connects),
                scrapes = VALUES(scrapes), uptime = VALUES(uptime), wl_share = VALUES(wl_share), whitelist_count = VALUES(whitelist_count),
                index_rows = VALUES(index_rows)";
    $st = $db->prepare($sql);
    $st->execute([$until, $to]);
    $n = intdiv($to - $until, $bucket);
    $state[$key] = $to;
    return $n;
}

/** Roll both tables (cheap no-op when nothing is due). Returns ['5m'=>buckets,'1h'=>buckets]. */
function statsTimelineRollup(PDO $db, array $cfg, ?int $now = null): array {
    $now = $now ?? time();
    $out = ['5m' => 0, '1h' => 0];
    statsTimelineStateUpdate(function (array &$st) use ($db, $now, &$out) {
        $out['5m'] = statsTimelineRollupTable($db, 300, ST_5M_TABLE, $st, 'rollup_5m_until', $now);
        $out['1h'] = statsTimelineRollupTable($db, 3600, ST_1H_TABLE, $st, 'rollup_1h_until', $now);
        $st['last_rollup_at'] = $now;
        return true;
    });
    return $out;
}

/** Delete raw samples older than raw_days and 5-minute roll-ups older than keep_days. Hourly roll-ups are kept. */
function statsTimelinePrune(PDO $db, array $cfg, ?int $now = null, bool $force = false): ?array {
    $now = $now ?? time();
    $st = statsTimelineStateRead();
    if (!$force && $now - (int)$st['last_prune_at'] < ST_PRUNE_EVERY) return null;
    $res = ['raw' => 0, '5m' => 0];
    // never delete raw rows the roll-ups have not consumed yet (janitor outage longer than the retention)
    $cut = $now - statsTimelineRawDays($cfg) * 86400;
    foreach (['rollup_5m_until', 'rollup_1h_until'] as $k) if ((int)$st[$k] > 0) $cut = min($cut, (int)$st[$k]);
    $d = $db->prepare("DELETE FROM `" . ST_RAW_TABLE . "` WHERE ts < ?");
    $d->execute([$cut]);
    $res['raw'] = $d->rowCount();
    $d = $db->prepare("DELETE FROM `" . ST_5M_TABLE . "` WHERE ts < ?");
    $d->execute([$now - statsTimelineKeepDays($cfg) * 86400]);
    $res['5m'] = $d->rowCount();
    statsTimelineStateUpdate(function (array &$s) use ($now, $res) { $s['last_prune_at'] = $now; $s['last_prune'] = $res; return true; });
    return $res;
}

/**
 * One janitor tick: sample if due (stats cache when fresh, upstream otherwise), roll up, prune.
 * $fetcher (tests): fn(): array{body:?string,error:?string} replaces the upstream HTTP fetch.
 * Returns a summary array; never throws.
 */
function statsTimelineTick(PDO $db, array $cfg, ?callable $fetcher = null, ?int $now = null): array {
    $clockGiven = $now !== null;     // tests pass a fixed clock; live ticks stamp samples when the data arrived
    $now = $now ?? time();
    $out = ['enabled' => statsTimelineEnabled($cfg), 'sampled' => false, 'source' => null, 'error' => null, 'rollup' => null, 'prune' => null];
    if (!$out['enabled']) return $out;
    try {
        $state = statsTimelineStateRead();
        if (statsTimelineSampleDue($state, $cfg, $now)) {
            $parsed = null;
            $sampleTs = $now;
            $interval = statsTimelineInterval($cfg);
            $cache = $fetcher === null ? statsTimelineReadStatsCache() : null;
            $fa = $cache ? (int)($cache['fetched_at'] ?? 0) : 0;
            // a web fetch in flight (config/stats_fetch.lock) will ingest its own sample — do not double-scrape
            $webLock = __DIR__ . '/../config/stats_fetch.lock';
            $webBusy = $fetcher === null && is_file($webLock) && ($now - (int)@filemtime($webLock)) < max(60, (int)($cfg['tracker_stats_timeout'] ?? 30) + 15);
            if ($cache && statsTimelineSampleDue($state, $cfg, $fa) && ($now - $fa) < $interval) {
                // the shared stats cache is fresh and due by its own fetch time: reuse it, stamped when it was fetched
                $parsed = $cache; $out['source'] = 'cache'; $sampleTs = $fa;
            } elseif ($webBusy) {
                $out['source'] = 'web-inflight';
            } else {
                $url = trim((string)($cfg['tracker_stats_url'] ?? ''));
                if ($url === '') {
                    $out['error'] = 'tracker_stats_url is not configured';
                } else {
                    // janitor runs are oneshot and never overlap, so the admin timeout (capped at 45 s) is safe here
                    $r = $fetcher ? $fetcher() : fetchTrackerStatsXml($url, min(45, max(2, (int)($cfg['tracker_stats_timeout'] ?? 30))));
                    if (!$clockGiven) $sampleTs = time();
                    if (empty($r['body'])) $out['error'] = $r['error'] ?? 'fetch failed';
                    else {
                        $parsed = parseTrackerStatsXml($r['body']);
                        if ($parsed === null) $out['error'] = 'Invalid XML response from tracker.';
                        else $out['source'] = 'upstream';
                    }
                }
            }
            if ($parsed !== null) {
                $ing = statsTimelineIngest($db, $cfg, $parsed, $sampleTs, $out['source'] ?? 'janitor');
                $out['sampled'] = $ing['stored'];
                if (!$ing['stored'] && str_starts_with($ing['reason'], 'error')) $out['error'] = $ing['reason'];
            }
            if ($out['error'] !== null) {
                $err = $out['error'];
                statsTimelineStateUpdate(function (array &$s) use ($err, $now) { $s['last_error'] = $err; $s['last_error_at'] = $now; return true; });
            }
        }
        $out['rollup'] = statsTimelineRollup($db, $cfg, $now);
        $out['prune'] = statsTimelinePrune($db, $cfg, $now);
        statsTimelineStateUpdate(function (array &$s) use ($now) { $s['last_tick_at'] = $now; return true; });
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        error_log('[stats timeline] ' . $e->getMessage());
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Series for the API / chart
// ─────────────────────────────────────────────────────────────────────────────

/** Normalise a range name; null when unknown. */
function statsTimelineRangeSeconds(string $range): ?int {
    $r = statsTimelineRanges();
    return $r[strtolower(trim($range))] ?? null;
}

/** Canonical cache key for a range (aliases map to the day form: 2w→14d, 1m→30d, 2m→60d, 3m→90d). */
function statsTimelineRangeKey(string $range): string {
    $k = preg_replace('/[^a-z0-9]/', '', strtolower($range));
    $canon = ['2w' => '14d', '1m' => '30d', '2m' => '60d', '3m' => '90d'];
    return $canon[$k] ?? $k;
}

/**
 * Pick the source table + step for a range: raw when it fits, else 5m, else 1h. The raw choice is
 * confirmed against the ACTUAL row count of the window (ts is the PK, a cheap range count) — the
 * configured interval may have been raised after the rows were sampled.
 */
function statsTimelineChooseTable(array $cfg, int $rangeSec, ?PDO $db = null, ?int $now = null): array {
    $interval = statsTimelineInterval($cfg);
    $rawDays = statsTimelineRawDays($cfg); $keepDays = statsTimelineKeepDays($cfg);
    if ($rangeSec <= $rawDays * 86400 && intdiv($rangeSec, $interval) <= ST_MAX_RAW_POINTS) {
        $ok = true;
        if ($db !== null) {
            $st = $db->prepare("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "` WHERE ts >= ?");
            $st->execute([($now ?? time()) - $rangeSec]);
            $ok = (int)$st->fetchColumn() <= ST_MAX_RAW_POINTS;
        }
        if ($ok) return [ST_RAW_TABLE, $interval, 'raw'];
    }
    if ($rangeSec <= $keepDays * 86400 && intdiv($rangeSec, 300) <= ST_MAX_5M_POINTS) return [ST_5M_TABLE, 300, '5m'];
    return [ST_1H_TABLE, 3600, '1h'];
}

/**
 * Build the chart payload for a range. Gauges from raw rows / bucket averages; `mode` is 1 for whitelist
 * (raw) or for buckets where ≥ 50 % of samples were in whitelist mode; `udp_rps`/`tcp_rps` are announce
 * rates derived from the cumulative counters (null across a tracker restart or a gap). Gaps larger than
 * 3 steps get an explicit null point so the chart breaks the line instead of bridging the outage.
 */
function statsTimelineSeries(PDO $db, array $cfg, int $rangeSec, ?int $now = null, ?int $winFrom = null, ?int $winTo = null): array {
    $now = $now ?? time();
    $from = $now - $rangeSec;
    $to = $now;
    $windowed = false;
    // Optional zoom window [from,to]: the chart refetches the visible span at the finest table that
    // still covers it, so zooming into an "all"/90d chart yields raw/5-min resolution instead of the
    // hourly points the full range was decimated to.
    if ($winFrom !== null && $winTo !== null && $winTo > $winFrom) {
        $winTo = min($winTo, $now);
        $winFrom = max($winFrom, $now - 20 * 365 * 86400);
        if ($winTo > $winFrom) { $from = $winFrom; $to = $winTo; $windowed = true; }
    }
    if ($windowed) {
        $span = $to - $from;
        $interval = max(1, statsTimelineInterval($cfg));
        $rawOk = false;
        if ($from >= $now - statsTimelineRawDays($cfg) * 86400 && intdiv($span, $interval) <= ST_MAX_RAW_POINTS) {
            // Same confirmation statsTimelineChooseTable() makes: the configured interval may have
            // been raised long after these rows were sampled, so the estimate above can be several
            // times short. Count what the window really holds before promising raw resolution.
            $cs = $db->prepare("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "` WHERE ts >= ? AND ts <= ?");
            $cs->execute([$from, $to]);
            $rawOk = (int)$cs->fetchColumn() <= ST_MAX_RAW_POINTS;
        }
        if ($rawOk) {
            [$table, $step, $kind] = [ST_RAW_TABLE, $interval, 'raw'];
        } elseif ($from >= $now - statsTimelineKeepDays($cfg) * 86400 && intdiv($span, 300) <= ST_MAX_5M_POINTS) {
            [$table, $step, $kind] = [ST_5M_TABLE, 300, '5m'];
        } else {
            [$table, $step, $kind] = [ST_1H_TABLE, 3600, '1h'];
        }
    } else {
        [$table, $step, $kind] = statsTimelineChooseTable($cfg, $rangeSec, $db, $now);
    }
    $decimate = '';
    if ($kind === '1h') {
        // 'all' after years of history: thin the hourly rows so the payload stays ≤ ~5000 points
        $cs = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE ts >= ? AND ts <= ?");
        $cs->execute([$from, $to]);
        $cnt = (int)$cs->fetchColumn();
        if ($cnt > 5000) {
            $k = (int)ceil($cnt / 5000);
            $step = 3600 * $k;
            $decimate = " AND ts % " . (3600 * $k) . " = 0";
        }
    }
    if ($kind === 'raw') {
        $sql = "SELECT ts, torrents, peers, seeds, leechers, completed, udp_announces, tcp_announces, connects, scrapes, uptime,
                       (mode = 'whitelist') AS wl, whitelist_count, index_rows FROM `$table` WHERE ts >= ? AND ts <= ?$decimate ORDER BY ts";
    } else {
        $sql = "SELECT ts, torrents_avg AS torrents, peers_avg AS peers, seeds_avg AS seeds, leechers_avg AS leechers, completed,
                       udp_announces, tcp_announces, connects, scrapes, uptime, (wl_share >= 50) AS wl, whitelist_count, index_rows
                FROM `$table` WHERE ts >= ? AND ts <= ?$decimate ORDER BY ts";
    }
    $st = $db->prepare($sql);
    $st->execute([$from, $to]);
    $keys = ['t', 'torrents', 'peers', 'seeds', 'leechers', 'completed', 'whitelist_count', 'index_rows', 'mode', 'udp_rps', 'tcp_rps', 'scrape_rps', 'connect_rps'];
    $out = array_fill_keys($keys, []);
    $prev = null;
    $rows = 0;
    $typicalDt = $step;   // adaptive: raw rows sampled under an older (different) interval keep their own spacing
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $ts = (int)$r['ts'];
        $gapLimit = 3 * max($step, $typicalDt);
        if ($prev !== null && $ts - $prev['ts'] > $gapLimit) {
            // gap: break the line
            $out['t'][] = $prev['ts'] + $step;
            foreach ($keys as $k) if ($k !== 't') $out[$k][] = null;
        } elseif ($prev !== null) {
            $typicalDt = $ts - $prev['ts'];
        }
        $out['t'][] = $ts;
        $out['torrents'][] = (int)$r['torrents'];
        $out['peers'][] = (int)$r['peers'];
        $out['seeds'][] = (int)$r['seeds'];
        $out['leechers'][] = (int)$r['leechers'];
        $out['completed'][] = (int)$r['completed'];
        $out['whitelist_count'][] = (int)$r['whitelist_count'];
        $out['index_rows'][] = (int)$r['index_rows'];
        $out['mode'][] = (int)$r['wl'] ? 1 : 0;
        $rates = [null, null, null, null];
        if ($prev !== null) {
            $dt = $ts - $prev['ts'];
            $restart = (int)$r['uptime'] < (int)$prev['uptime'];
            if ($dt > 0 && $dt <= $gapLimit && !$restart) {
                foreach (['udp_announces', 'tcp_announces', 'scrapes', 'connects'] as $i => $c) {
                    $d = (int)$r[$c] - (int)$prev[$c];
                    $rates[$i] = $d >= 0 ? round($d / $dt, 2) : null;
                }
            }
        }
        $out['udp_rps'][] = $rates[0]; $out['tcp_rps'][] = $rates[1]; $out['scrape_rps'][] = $rates[2]; $out['connect_rps'][] = $rates[3];
        $prev = ['ts' => $ts, 'uptime' => (int)$r['uptime'], 'udp_announces' => (int)$r['udp_announces'], 'tcp_announces' => (int)$r['tcp_announces'],
                 'scrapes' => (int)$r['scrapes'], 'connects' => (int)$r['connects']];
        $rows++;
    }
    // raw_days / keep_days travel with the payload so the chart can mirror the server's table choice
    // (assets/js/stats-timeline.js candidateStep()) instead of guessing from the point count alone.
    return ['success' => true, 'range_seconds' => $rangeSec, 'from' => $from, 'to' => $to, 'step' => $step, 'table' => $kind,
            'windowed' => $windowed, 'points' => $rows, 'interval' => statsTimelineInterval($cfg),
            'raw_days' => statsTimelineRawDays($cfg), 'keep_days' => statsTimelineKeepDays($cfg),
            'generated_at' => $now] + $out;
}

/** Row counts + state for the admin status card / CLI. */
function statsTimelineStatus(PDO $db, array $cfg): array {
    $counts = ['raw' => 0, '5m' => 0, '1h' => 0];
    try {
        $counts['raw'] = (int)$db->query("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "`")->fetchColumn();
        $counts['5m'] = (int)$db->query("SELECT COUNT(*) FROM `" . ST_5M_TABLE . "`")->fetchColumn();
        $counts['1h'] = (int)$db->query("SELECT COUNT(*) FROM `" . ST_1H_TABLE . "`")->fetchColumn();
    } catch (\Throwable $e) {}
    return ['enabled' => statsTimelineEnabled($cfg), 'public' => statsTimelinePublic($cfg), 'interval' => statsTimelineInterval($cfg),
            'raw_days' => statsTimelineRawDays($cfg), 'keep_days' => statsTimelineKeepDays($cfg), 'counts' => $counts, 'state' => statsTimelineStateRead()];
}
