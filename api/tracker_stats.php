<?php
/**
 * TryHackX Tracker - Stats API Endpoint
 * Fetches and parses OpenTracker XML statistics, returns JSON data.
 *
 * Caching model:
 *   - stats_cache.json   : cached payload + fetched_at timestamp (written atomically via tmp+rename)
 *   - stats_fetch.lock   : exclusive lock for the in-flight upstream fetch (contains start timestamp)
 *
 * Request modes:
 *   - stale_ok=1 : caller is happy with stale data; never blocks. Returns cache (fresh or stale)
 *                  with sync_required / syncing_in_background flags so the client can poll.
 *   - stale_ok=0 : caller wants fresh data. Will attempt to acquire the lock and fetch upstream.
 *                  If lock is already held, returns stale cache with syncing_in_background=1.
 *
 * source=home|stats : determines the client-facing refresh interval that the API echoes back
 *                     in remaining_seconds. Cache TTL on the server uses the SHORTER of the two
 *                     configured intervals so neither page ever sees data older than it expects.
 */

// Keep PHP running even if the browser drops the connection mid-fetch.
ignore_user_abort(true);
clearstatcache();
// NOTE: the PHP execution limit is raised further down, once the configured upstream
// timeout is known — a hardcoded limit here could kill a slow fetch that is still within
// the admin-configured Request Timeout (e.g. timeout=90 but limit=60).

// Release the session early so concurrent requests aren't blocked on session lock.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

if (($cfg['tracker_stats_enabled'] ?? '0') !== '1') {
    jsonResponse(['error' => 'Tracker statistics are disabled'], 403);
}

$url = $cfg['tracker_stats_url'] ?? '';
if (empty($url)) {
    jsonResponse(['error' => 'Tracker stats URL is not configured'], 400);
}

$cacheFile = __DIR__ . '/../config/stats_cache.json';
$lockFile  = __DIR__ . '/../config/stats_fetch.lock';

// Configured CLIENT refresh intervals (sec). These only control how often each browser
// re-checks the cache — they do NOT decide when the upstream is re-fetched.
$homeInterval = max(2, (int)($cfg['tracker_stats_interval'] ?? 10));
$pageInterval = max(2, (int)($cfg['tracker_stats_page_interval'] ?? $homeInterval));

// Cache TTL — the shared server-side lifetime of the cached payload, decoupled from the
// client refresh intervals. While the cache is younger than this, EVERY visitor is served
// the same cached data and the upstream is NOT re-fetched, no matter how often pages reload.
// It should be >= the typical upstream fetch time so the "syncing" window is a small fraction
// of each cycle. Defaults to 60s when unset so existing installs are fixed without a re-save.
$cacheTtl = max(2, (int)($cfg['tracker_stats_cache_ttl'] ?? 60));

$timeout  = max(2, (int)($cfg['tracker_stats_timeout'] ?? 30));
// Lock is considered "dead" after this many seconds. Slightly larger than the upstream timeout
// so a slow fetch isn't mistakenly torn down by a parallel request.
$lockTtl  = max(60, $timeout + 15);

// Allow PHP to run long enough to complete the upstream fetch (bounded by $timeout) plus
// parsing/cache-write overhead. Must exceed the curl timeout or a slow-but-valid fetch would
// be killed mid-flight, the lock released with no cache written, and the next visitor would
// restart the whole fetch — the feedback loop that made stats "never load".
@set_time_limit($timeout + 30);

$minLoading   = (int)($cfg['tracker_stats_min_loading'] ?? 1000);
$maxLoading   = (int)($cfg['tracker_stats_max_loading'] ?? $minLoading);
$minLoadingMs = ($maxLoading > $minLoading) ? rand($minLoading, $maxLoading) : $minLoading;

// Live Syncs display mode. OpenTracker's own livesync counter is 0 on single-node setups, so
// 'local' repurposes that stat as a count of OUR successful cache refreshes since the tracker
// last (re)started. The counter auto-resets when a tracker restart is detected (uptime drops
// well below the previous reading). 'upstream' keeps the raw value from the tracker XML.
$livesyncMode = ($cfg['tracker_stats_livesync_mode'] ?? 'upstream') === 'local' ? 'local' : 'upstream';

$now      = time();
$staleOk  = isset($_GET['stale_ok']) && $_GET['stale_ok'] === '1';
$source   = ($_GET['source'] ?? '') === 'stats' ? 'stats' : 'home';
$clientInterval = ($source === 'stats') ? $pageInterval : $homeInterval;

// --- Helpers ---------------------------------------------------------------

/** Read & decode the cache file. Returns null on any failure. */
$readCache = function () use ($cacheFile): ?array {
    if (!file_exists($cacheFile)) return null;
    $raw = @file_get_contents($cacheFile);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['success'])) return null;
    return $data;
};

/** Atomic cache write via tmp + rename so concurrent readers never see a partial JSON. */
$writeCache = function (array $data) use ($cacheFile): bool {
    $dir = dirname($cacheFile);
    $tmp = $dir . '/.stats_cache.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($data), LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $cacheFile)) {
        @unlink($tmp);
        return false;
    }
    return true;
};

/** Check whether a lock currently held by some process is dead. Removes it if so. */
$cleanupDeadLock = function () use ($lockFile, $lockTtl, $now): bool {
    if (!file_exists($lockFile)) return false;
    // Prefer the timestamp written inside the lock (more reliable than filemtime on shared hosts).
    $content = @file_get_contents($lockFile);
    $lockStart = (int)trim((string)$content);
    if ($lockStart <= 0) {
        // Fall back to filemtime
        $lockStart = (int)@filemtime($lockFile);
    }
    $age = $now - $lockStart;
    if ($age >= $lockTtl) {
        @unlink($lockFile);
        return true;
    }
    return false;
};

/**
 * Read the lock's start timestamp (when the current in-flight sync began).
 * Returns [start_ts, age_sec] or [0, 0] if there is no lock.
 * Exposed in responses so admins can tell whether successive refreshes are restarting the
 * sync (start_ts changes) or just polling the same in-progress fetch (start_ts stays).
 */
$readLockMeta = function () use ($lockFile, $now): array {
    if (!file_exists($lockFile)) return [0, 0];
    $content = @file_get_contents($lockFile);
    $lockStart = (int)trim((string)$content);
    if ($lockStart <= 0) {
        $lockStart = (int)@filemtime($lockFile);
    }
    if ($lockStart <= 0) return [0, 0];
    return [$lockStart, max(0, $now - $lockStart)];
};

// --- 1. Read cache state ---------------------------------------------------

$cacheData = $readCache();
$cacheAge  = $cacheData ? max(0, $now - (int)($cacheData['fetched_at'] ?? 0)) : PHP_INT_MAX;
$cacheStale = !$cacheData || ($cacheAge >= $cacheTtl);

// Always try to clean up a dead lock, regardless of request mode.
$cleanupDeadLock();
$lockExists = file_exists($lockFile);

// --- 2. Cache is fresh: serve it -------------------------------------------

if ($cacheData && !$cacheStale) {
    $resp = $cacheData;
    $resp['cached']             = true;
    $resp['cache_age']          = $cacheAge;
    $resp['min_loading_ms']     = $minLoadingMs;
    // How long the client should wait before its next cache check. Now that the cache TTL is
    // decoupled from (and usually larger than) the client interval, this is the smaller of:
    //   - the client's own refresh interval, and
    //   - the time left until the cache actually expires.
    // Clamped to >= 1 so the countdown never hits 0 while the cache is still fresh (which would
    // make the client re-check instantly in a tight loop).
    $secsUntilExpiry = max(1, $cacheTtl - $cacheAge);
    $resp['remaining_seconds']  = max(1, min($clientInterval, $secsUntilExpiry));
    $resp['syncing_in_background'] = false;
    $resp['sync_required']      = false;
    jsonResponse($resp);
}

// --- 3. Cache is stale (or missing) ----------------------------------------

// stale_ok mode: never block. Return whatever cache we have plus sync flags.
if ($staleOk) {
    [$lockStart, $lockAge] = $readLockMeta();
    if ($cacheData) {
        $resp = $cacheData;
        $resp['cached']                = true;
        $resp['cache_age']             = $cacheAge;
        $resp['min_loading_ms']        = $minLoadingMs;
        $resp['remaining_seconds']     = 0;
        $resp['sync_required']         = true;
        $resp['syncing_in_background'] = $lockExists;
        if ($lockExists) {
            $resp['sync_started_at']   = $lockStart;
            $resp['lock_age']          = $lockAge;
        }
        jsonResponse($resp);
    }
    // No cache at all + stale_ok=1: client must wait. Tell them to retry.
    $resp = [
        'success'              => false,
        'error'                => 'No cached statistics yet. A refresh is required.',
        'sync_required'        => true,
        'syncing_in_background'=> $lockExists,
        'min_loading_ms'       => $minLoadingMs,
    ];
    if ($lockExists) {
        $resp['sync_started_at'] = $lockStart;
        $resp['lock_age']        = $lockAge;
    }
    jsonResponse($resp, 503);
}

// Blocking mode (stale_ok=0): we will attempt to acquire the lock and fetch upstream.

// Someone else already holds the lock — return stale cache and tell the client to poll.
if ($lockExists) {
    [$lockStart, $lockAge] = $readLockMeta();
    if ($cacheData) {
        $resp = $cacheData;
        $resp['cached']                = true;
        $resp['cache_age']             = $cacheAge;
        $resp['syncing_in_background'] = true;
        $resp['sync_started_at']       = $lockStart;
        $resp['lock_age']              = $lockAge;
        $resp['min_loading_ms']        = $minLoadingMs;
        $resp['remaining_seconds']     = 0;
        jsonResponse($resp);
    }
    jsonResponse([
        'success'              => false,
        'error'                => 'Tracker statistics are being updated. Please retry.',
        'syncing_in_background'=> true,
        'sync_started_at'      => $lockStart,
        'lock_age'             => $lockAge,
        'min_loading_ms'       => $minLoadingMs,
    ], 503);
}

// --- 4. Try to acquire the lock atomically (O_CREAT|O_EXCL) ----------------

$lockHandle = @fopen($lockFile, 'x');
if ($lockHandle === false) {
    // Race: another process grabbed the lock between our check and fopen.
    [$lockStart, $lockAge] = $readLockMeta();
    if ($cacheData) {
        $resp = $cacheData;
        $resp['cached']                = true;
        $resp['cache_age']             = $cacheAge;
        $resp['syncing_in_background'] = true;
        $resp['sync_started_at']       = $lockStart;
        $resp['lock_age']              = $lockAge;
        $resp['min_loading_ms']        = $minLoadingMs;
        $resp['remaining_seconds']     = 0;
        jsonResponse($resp);
    }
    jsonResponse([
        'success'              => false,
        'error'                => 'Tracker statistics are being updated. Please retry.',
        'syncing_in_background'=> true,
        'sync_started_at'      => $lockStart,
        'lock_age'             => $lockAge,
        'min_loading_ms'       => $minLoadingMs,
    ], 503);
}
// Write the start timestamp INTO the lock so dead-lock detection is reliable.
@fwrite($lockHandle, (string)$now);
@fclose($lockHandle);

// Guarantee the lock is removed even if PHP fatals or hits set_time_limit.
$lockReleased = false;
$releaseLock = function () use (&$lockReleased, $lockFile) {
    if ($lockReleased) return;
    $lockReleased = true;
    if (file_exists($lockFile)) @unlink($lockFile);
};
register_shutdown_function($releaseLock);

// --- 5. Fetch upstream XML -------------------------------------------------

$xmlContent = null;
$errorMsg   = null;
$fetchStart = microtime(true);

if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TryHackX Tracker Status Bot/1.0');
    // Verify TLS certificates when the stats URL is https (harmless for http URLs).
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $xmlContent = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    if ($xmlContent === false) {
        $errorMsg   = 'cURL error: ' . $curlErr;
        $xmlContent = null;
    } elseif ($httpCode !== 200) {
        $errorMsg   = 'HTTP error code: ' . $httpCode;
        $xmlContent = null;
    }
}

if ($xmlContent === null && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header'  => "User-Agent: TryHackX Tracker Status Bot/1.0\r\n",
        ],
    ]);
    $fetched = @file_get_contents($url, false, $ctx);
    if ($fetched !== false) {
        $xmlContent = $fetched;
        $errorMsg   = null;
    } elseif ($errorMsg === null) {
        $errorMsg = 'file_get_contents failed to fetch URL.';
    }
}

$fetchDurationMs = (int)round((microtime(true) - $fetchStart) * 1000);

if (empty($xmlContent)) {
    // Persist diagnostic info into the cache itself so admins can inspect why the upstream
    // is failing without needing access to PHP error logs. The cache write happens BEFORE
    // releasing the lock so concurrent writers can't clobber each other.
    if ($cacheData) {
        $cacheData['last_fetch_error']        = $errorMsg;
        // Use wall-clock time() not the $now captured at request start — the curl call
        // may have taken 20+ seconds, so the actual moment of failure is much later.
        $cacheData['last_fetch_error_at']     = time();
        $cacheData['last_fetch_duration_ms']  = $fetchDurationMs;
        $writeCache($cacheData);
    }
    $releaseLock();
    if ($cacheData) {
        $resp = $cacheData;
        $resp['cached']            = true;
        $resp['fetch_error']       = 'Unable to refresh stats: ' . $errorMsg;
        $resp['min_loading_ms']    = $minLoadingMs;
        $resp['remaining_seconds'] = $clientInterval;
        jsonResponse($resp);
    }
    jsonResponse([
        'success'        => false,
        'error'          => 'Unable to fetch tracker statistics. Connection timed out or server unreachable.',
        'details'        => $errorMsg,
        'min_loading_ms' => $minLoadingMs,
    ], 502);
}

// --- 6. Parse XML ----------------------------------------------------------

libxml_use_internal_errors(true);
$xml = simplexml_load_string(trim($xmlContent));
if ($xml === false) {
    libxml_clear_errors();
    if ($cacheData) {
        $cacheData['last_fetch_error']        = 'Invalid XML response from tracker.';
        $cacheData['last_fetch_error_at']     = time();
        $cacheData['last_fetch_duration_ms']  = $fetchDurationMs;
        $writeCache($cacheData);
    }
    $releaseLock();
    if ($cacheData) {
        $resp = $cacheData;
        $resp['cached']            = true;
        $resp['fetch_error']       = 'Invalid XML response from tracker.';
        $resp['min_loading_ms']    = $minLoadingMs;
        $resp['remaining_seconds'] = $clientInterval;
        jsonResponse($resp);
    }
    jsonResponse([
        'success'        => false,
        'error'          => 'Failed to parse XML response from tracker.',
        'min_loading_ms' => $minLoadingMs,
    ], 502);
}

$trackerId     = (string)($xml->tracker_id ?? '');
$version       = trim((string)($xml->version ?? ''));
$uptimeSeconds = (int)($xml->uptime ?? 0);

// Human-readable uptime (shared, unit-tested helper — see includes/functions.php).
$uptimeString = formatUptime($uptimeSeconds);

$torrentsMutex    = (int)($xml->torrents->count_mutex ?? 0);
$torrentsIterator = (int)($xml->torrents->count_iterator ?? 0);
$torrents         = max($torrentsMutex, $torrentsIterator);

$peers     = (int)($xml->peers->count ?? 0);
$seeds     = (int)($xml->seeds->count ?? 0);
$leechers  = max(0, $peers - $seeds);
$completed = (int)($xml->completed->count ?? 0);

$tcpAccept   = (int)($xml->connections->tcp->accept ?? 0);
$tcpAnnounce = (int)($xml->connections->tcp->announce ?? 0);
$tcpScrape   = (int)($xml->connections->tcp->scrape ?? 0);

$udpOverall  = (int)($xml->connections->udp->overall ?? 0);
$udpConnect  = (int)($xml->connections->udp->connect ?? 0);
$udpAnnounce = (int)($xml->connections->udp->announce ?? 0);
$udpScrape   = (int)($xml->connections->udp->scrape ?? 0);
$udpMismatch = (int)($xml->connections->udp->missmatch ?? 0);

$livesyncCount = (int)($xml->connections->livesync->count ?? 0);

$httpErrors = [];
if (isset($xml->debug->http_error->count)) {
    foreach ($xml->debug->http_error->count as $err) {
        $code  = (string)($err['code'] ?? 'Unknown');
        $count = (int)$err;
        if ($count > 0 || $code !== 'Unknown') {
            $httpErrors[] = ['code' => $code, 'count' => $count];
        }
    }
}

$renewIntervals = [];
if (isset($xml->debug->renew->count)) {
    foreach ($xml->debug->renew->count as $renew) {
        $renewIntervals[] = [
            'interval' => (string)($renew['interval'] ?? ''),
            'count'    => (int)$renew,
        ];
    }
}

$data = [
    'success'        => true,
    'tracker_id'     => $trackerId,
    'version'        => $version,
    'uptime_seconds' => $uptimeSeconds,
    'uptime_string'  => $uptimeString,
    'torrents'       => $torrents,
    'peers'          => $peers,
    'seeds'          => $seeds,
    'leechers'       => $leechers,
    'completed'      => $completed,
    'connections'    => [
        'tcp' => [
            'accept'   => $tcpAccept,
            'announce' => $tcpAnnounce,
            'scrape'   => $tcpScrape,
        ],
        'udp' => [
            'overall'  => $udpOverall,
            'connect'  => $udpConnect,
            'announce' => $udpAnnounce,
            'scrape'   => $udpScrape,
            'mismatch' => $udpMismatch,
        ],
        'livesync'    => $livesyncCount,
    ],
    'http_errors'    => $httpErrors,
    'renew_intervals'=> $renewIntervals,
    // CRITICAL: fetched_at must be set to the moment the cache is being WRITTEN, not when
    // the request started — otherwise a slow upstream (20+ sec) makes the cache look already
    // half-stale the instant it's saved, which causes immediate re-fetches and a feedback
    // loop where every page refresh sees stale cache and triggers another sync.
    'fetched_at'     => time(),
    // Diagnostic — keep visible even on success so admins can spot if upstream is slow.
    'last_fetch_duration_ms' => $fetchDurationMs,
];

// --- 6b. Live Syncs local counter ------------------------------------------
// In 'local' mode we ignore the tracker's own (always-0) livesync value and instead count how
// many times WE have refreshed the cache since the tracker last started. Re-read the cache under
// the lock so we pick up the freshest counter even if another request wrote between our initial
// read and now, then reset when the tracker's uptime has dropped (a restart).
if ($livesyncMode === 'local') {
    $prev       = $readCache() ?: $cacheData;
    $prevUptime = (int)($prev['uptime_seconds'] ?? 0);
    $prevCount  = (int)($prev['livesync_local_count'] ?? 0);
    // uptime is monotonic while the tracker runs, so a drop of more than a small slack means it
    // restarted — start counting again from this refresh.
    $restarted  = ($prevUptime > 0) && ($uptimeSeconds < $prevUptime - 30);
    $count      = $restarted ? 1 : $prevCount + 1;
    $data['livesync_local_count']    = $count;
    $data['connections']['livesync'] = $count;
}

// --- 7. Write cache atomically and release lock ----------------------------

$writeCache($data);
$releaseLock();

// Echo client-specific fields in the response (NOT persisted in cache).
$data['cache_age']         = 0;
$data['cached']            = false;
$data['remaining_seconds'] = $clientInterval;
$data['min_loading_ms']    = $minLoadingMs;

jsonResponse($data);
