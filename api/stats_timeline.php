<?php
/**
 * GET stats_timeline&range=24h|7d|14d|30d|60d|90d   (aliases 2w / 1m / 2m / 3m)
 *
 * Series for the swarm timeline chart (includes/stats_timeline.php). Public when
 * stats_timeline_public=1, otherwise admins only (full session check, like the other admin pollers).
 * The payload per range is cached for ST_API_CACHE_TTL seconds in config/ (atomic write, single-flight
 * regeneration under flock) so pollers never hit the database; a cache hit is served straight from the
 * file (gzip via ob_gzhandler when the client accepts it) without decoding. Optional `series=a,b,c`
 * trims the payload to the named series (t/mode always included). 60 requests / minute / IP.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['error' => 'Method not allowed'], 405);
if (!statsTimelineEnabled($cfg)) jsonResponse(['error' => 'Statistics timeline is disabled'], 403);
// Evaluate while the session is still open: adminSessionValid() enforces the idle/absolute limits and
// refreshes last_activity. Public mode short-circuits so anonymous pollers never touch the session.
$public = statsTimelinePublic($cfg) && userCan($db, $cfg, 'stats.timeline');
$allowed = $public || adminSessionValid($cfg);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (!$allowed) jsonResponse(['error' => 'Statistics timeline is not public'], 403);
if (!rateLimitAllow('timeline', ipBucket(getClientIp($cfg)), 60, 60)) jsonResponse(['error' => 'Too many requests'], 429);

$range = (string)($_GET['range'] ?? '24h');
$rangeSec = statsTimelineRangeSeconds($range);
if ($rangeSec === null) jsonResponse(['error' => 'Unknown range. Use 24h, 7d, 14d, 30d, 60d or 90d.'], 400);
$rangeKey = statsTimelineRangeKey($range);

$wanted = null;
if (isset($_GET['series']) && trim((string)$_GET['series']) !== '') {
    $wanted = array_values(array_filter(array_map('trim', explode(',', strtolower((string)$_GET['series']))), fn($s) => preg_match('/^[a-z_]{1,32}$/', $s)));
}

// Optional zoom window: &from=<unix>&to=<unix> refetches just that span at the finest resolution
// that covers it (raw → 5m → 1h). Windowed replies are small, skip the file cache entirely and
// ride the same 60 req/min rate limit as everything else.
$winFrom = isset($_GET['from']) && is_numeric($_GET['from']) ? (int)$_GET['from'] : null;
$winTo = isset($_GET['to']) && is_numeric($_GET['to']) ? (int)$_GET['to'] : null;
if ($winFrom !== null && $winTo !== null && $winTo > $winFrom && ($winTo - $winFrom) >= 60) {
    try {
        $payload = statsTimelineSeries($db, $cfg, $rangeSec, time(), $winFrom, $winTo);
    } catch (\Throwable $e) {
        error_log('[stats timeline] window series: ' . $e->getMessage());
        jsonResponse(['error' => 'Timeline query failed'], 500);
    }
    $payload['range'] = $rangeKey;
    $payload['cache_age'] = 0;
    if ($wanted !== null) {
        $keep = array_flip(array_merge(['success', 'range', 'range_seconds', 'from', 'to', 'step', 'table', 'windowed', 'points', 'interval', 'generated_at', 'cache_age', 't', 'mode'], $wanted));
        $payload = array_intersect_key($payload, $keep);
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $json = json_encode($payload);
    if (strlen($json) > 4096 && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
    echo $json;
    exit;
}

/** Emit a JSON string with the right headers (gzip when accepted); bypasses jsonResponse() so ob_gzhandler survives. */
$emit = function (string $json, int $cacheAge) use ($public): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: ' . ($public ? 'public, max-age=' . ST_API_CACHE_TTL : 'private, no-store'));
    header('X-Cache-Age: ' . $cacheAge);
    if (strlen($json) > 4096 && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
    echo $json;
    exit;
};

$cacheFile = __DIR__ . '/../config/stats_timeline_' . $rangeKey . '.json';
$now = time();
clearstatcache(true, $cacheFile);
$age = is_file($cacheFile) ? max(0, $now - (int)@filemtime($cacheFile)) : PHP_INT_MAX;

// fast path: fresh cache, no series filter → stream the file as is
if ($wanted === null && $age < ST_API_CACHE_TTL) {
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false && $raw !== '' && $raw[0] === '{') $emit($raw, $age);
}

$payload = null;
if ($age < ST_API_CACHE_TTL) {
    $c = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($c) && !empty($c['success'])) $payload = $c;
}
if ($payload === null) {
    // single-flight regeneration: one request rebuilds, concurrent ones get the stale copy (or wait when none)
    $lh = @fopen($cacheFile . '.lock', 'c');
    $locked = $lh && @flock($lh, LOCK_EX | LOCK_NB);
    if (!$locked && $lh && is_file($cacheFile)) {
        $c = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($c) && !empty($c['success'])) { $payload = $c; $age = max(0, $now - (int)@filemtime($cacheFile)); }
    }
    if ($payload === null) {
        if (!$locked && $lh) { @flock($lh, LOCK_EX); $locked = true; clearstatcache(true, $cacheFile);
            if (is_file($cacheFile) && ($now - (int)@filemtime($cacheFile)) < ST_API_CACHE_TTL) {
                $c = json_decode((string)@file_get_contents($cacheFile), true);
                if (is_array($c) && !empty($c['success'])) $payload = $c;
            }
        }
        if ($payload === null) {
            try {
                $payload = statsTimelineSeries($db, $cfg, $rangeSec, $now);
            } catch (\Throwable $e) {
                if ($lh) { @flock($lh, LOCK_UN); @fclose($lh); }
                error_log('[stats timeline] series: ' . $e->getMessage());
                jsonResponse(['error' => 'Timeline query failed'], 500);
            }
            $payload['range'] = $rangeKey;
            $tmp = $cacheFile . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, json_encode($payload), LOCK_EX) !== false) @rename($tmp, $cacheFile); else @unlink($tmp);
            $age = 0;
        }
    }
    if ($lh) { @flock($lh, LOCK_UN); @fclose($lh); }
}
$payload['cache_age'] = $age === PHP_INT_MAX ? 0 : $age;
$payload['range'] = $rangeKey;
if ($wanted !== null) {
    $keep = array_flip(array_merge(['success', 'range', 'range_seconds', 'from', 'to', 'step', 'table', 'points', 'interval', 'generated_at', 'cache_age', 't', 'mode'], $wanted));
    $payload = array_intersect_key($payload, $keep);
}
$emit(json_encode($payload), $payload['cache_age']);
