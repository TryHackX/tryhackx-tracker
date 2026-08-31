<?php
/**
 * Test for includes/stats_timeline.php (needs the local test database — see deploy/local_bootstrap.php
 * in the workspace; the checkout's config/database.php must point at a DISPOSABLE database, the
 * stats_samples* tables are truncated):
 *   php tests/stats_timeline_test.php
 * Prints PASS/FAIL lines and exits non-zero on failure.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/stats_timeline.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
check('schema version >= 5', (int)($cfg['schema_version'] ?? 0) >= 5, (string)($cfg['schema_version'] ?? 'none'));
foreach ([ST_RAW_TABLE, ST_5M_TABLE, ST_1H_TABLE] as $t) $db->exec("TRUNCATE TABLE `$t`");
@unlink(statsTimelineStateFile());

// ── 1. parser ────────────────────────────────────────────────────────────────
function fakeXml(int $uptime, int $torrents, int $peers, int $seeds, int $completed, int $udpAnn, int $tcpAnn, int $connect, int $udpScr, int $tcpScr): string {
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><stats><tracker_id>1234</tracker_id><version>https://example.org/ot</version>"
        . "<uptime>$uptime</uptime><torrents><count_mutex>$torrents</count_mutex><count_iterator>" . ($torrents - 1) . "</count_iterator></torrents>"
        . "<peers><count>$peers</count></peers><seeds><count>$seeds</count></seeds><completed><count>$completed</count></completed>"
        . "<connections><tcp><accept>10</accept><announce>$tcpAnn</announce><scrape>$tcpScr</scrape></tcp>"
        . "<udp><overall>1</overall><connect>$connect</connect><announce>$udpAnn</announce><scrape>$udpScr</scrape><missmatch>3</missmatch></udp>"
        . "<livesync><count>0</count></livesync></connections>"
        . "<debug><renew><count interval=\"00\">5</count><count interval=\"01\">7</count></renew><http_error><count code=\"302 Redirect\">0</count><count code=\"400 Parse Error\">12</count></http_error><mutex_stall><count>0</count></mutex_stall></debug></stats>";
}
$p = parseTrackerStatsXml(fakeXml(3600, 1000, 500, 300, 42, 100000, 2000, 50000, 700, 30));
check('parse: torrents uses max(mutex, iterator)', $p['torrents'] === 1000);
check('parse: peers/seeds/leechers', $p['peers'] === 500 && $p['seeds'] === 300 && $p['leechers'] === 200);
check('parse: counters', $p['connections']['udp']['announce'] === 100000 && $p['connections']['tcp']['announce'] === 2000 && $p['connections']['udp']['connect'] === 50000 && $p['connections']['udp']['mismatch'] === 3);
check('parse: renew intervals + http errors', count($p['renew_intervals']) === 2 && $p['http_errors'][1]['count'] === 12);
check('parse: uptime string', $p['uptime_string'] === formatUptime(3600));
check('parse: invalid XML → null', parseTrackerStatsXml('<not xml') === null);
check('parse: leechers never negative', parseTrackerStatsXml(fakeXml(1, 1, 5, 9, 0, 0, 0, 0, 0, 0))['leechers'] === 0);

// ── 2. settings helpers ──────────────────────────────────────────────────────
$tcfg = ['stats_timeline_enabled' => '1', 'stats_timeline_interval' => '60', 'stats_timeline_raw_days' => '7', 'stats_timeline_keep_days' => '60',
         'stats_timeline_public' => '1', 'tracker_mode' => 'blacklist', 'tracker_stats_url' => 'http://127.0.0.1:1/stats'];
check('interval clamp low', statsTimelineInterval(['stats_timeline_interval' => '5']) === ST_INTERVAL_MIN);
check('interval clamp high', statsTimelineInterval(['stats_timeline_interval' => '99999']) === ST_INTERVAL_MAX);
check('interval garbage → 60', statsTimelineInterval(['stats_timeline_interval' => 'abc']) === 60);
check('ranges: aliases', statsTimelineRangeSeconds('2w') === 14 * 86400 && statsTimelineRangeSeconds('1m') === 30 * 86400 && statsTimelineRangeSeconds('nope') === null);
check('disabled → ingest refuses', statsTimelineIngest($db, ['stats_timeline_enabled' => '0'], $p, 1000)['reason'] === 'disabled');

// ── 3. sampling via tick with a fake fetcher, 3 h at 60 s ─────────────────────
$base = 1800000000 - (1800000000 % 3600);          // bucket-aligned start (2027-01-15 08:00 UTC)
$i = 0;
$fetcher = function () use (&$i) {
    // counters grow 600 udp announces / 60 tcp announces per minute; restart at minute 100 (uptime drops, counters reset)
    $restartAt = 100;
    $up = $i < $restartAt ? 1000 + $i * 60 : ($i - $restartAt) * 60 + 5;
    $k = $i < $restartAt ? $i : ($i - $restartAt);
    return ['body' => fakeXml($up, 1000 + $i, 500 + $i, 300 + $i, 42 + $i, 600 * $k, 60 * $k, 50 * $k, 7 * $k, $k), 'error' => null];
};
$modeCfg = $tcfg;
for ($i = 0; $i < 180; $i++) {
    $modeCfg['tracker_mode'] = ($i >= 60 && $i < 120) ? 'whitelist' : 'blacklist';   // hour 2 in whitelist mode
    $r = statsTimelineTick($db, $modeCfg, $fetcher, $base + $i * 60);
    if ($i === 0) check('tick 0 sampled from upstream', $r['sampled'] && $r['source'] === 'upstream', json_encode($r));
}
$rawCount = (int)$db->query("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "`")->fetchColumn();
check('180 ticks → 180 raw samples', $rawCount === 180, (string)$rawCount);
$st = statsTimelineStateRead();
check('state: last_sample_at = last tick', (int)$st['last_sample_at'] === $base + 179 * 60);
check('state: samples_total = 180', (int)$st['samples_total'] === 180);
check('state: no error', $st['last_error'] === null, (string)$st['last_error']);

// a second tick inside the same minute must not sample again
$r = statsTimelineTick($db, $modeCfg, $fetcher, $base + 179 * 60 + 20);
check('tick within interval → not sampled', !$r['sampled'] && $r['source'] === null);
// 60 s timer jitter: 55 s after the last sample counts as due (slack)
$r = statsTimelineTick($db, $modeCfg, $fetcher, $base + 180 * 60 - 4);
check('tick 56 s after last sample (timer jitter) → sampled', $r['sampled']);
$i = 181;
$r = statsTimelineTick($db, $modeCfg, $fetcher, $base + 181 * 60);
check('next minute → sampled', $r['sampled']);

// fetch failure is recorded, not thrown
$bad = function () { return ['body' => null, 'error' => 'cURL error: connection refused']; };
$r = statsTimelineTick($db, $modeCfg, $bad, $base + 182 * 60);
check('fetch failure → error recorded, no sample', !$r['sampled'] && str_contains((string)$r['error'], 'refused'));
check('state: last_error set', str_contains((string)statsTimelineStateRead()['last_error'], 'refused'));
$r = statsTimelineTick($db, $modeCfg, function () { return ['body' => '<garbage', 'error' => null]; }, $base + 183 * 60);
check('invalid XML → error recorded', !$r['sampled'] && str_contains((string)$r['error'], 'Invalid XML'));
// recovered
$i = 184;
$r = statsTimelineTick($db, $modeCfg, $fetcher, $base + 184 * 60);
check('recovery → sampled, error cleared', $r['sampled'] && statsTimelineStateRead()['last_error'] === null);

// ── 4. roll-ups ──────────────────────────────────────────────────────────────
$c5 = (int)$db->query("SELECT COUNT(*) FROM `" . ST_5M_TABLE . "`")->fetchColumn();
$c1 = (int)$db->query("SELECT COUNT(*) FROM `" . ST_1H_TABLE . "`")->fetchColumn();
// last tick at base+184 min → complete 5m buckets: 0..36 (37 buckets: minutes 0–184 cover buckets up to 180–184 incomplete) ; 1h: 3 complete (0,1,2)
check('5m buckets rolled = 36 (complete ones only)', $c5 === 36, (string)$c5);
check('1h buckets rolled = 3', $c1 === 3, (string)$c1);
$b = $db->query("SELECT * FROM `" . ST_5M_TABLE . "` WHERE ts = $base")->fetch(PDO::FETCH_ASSOC);
check('5m bucket 0: 5 samples, seeds avg 302 min 300 max 304', (int)$b['samples'] === 5 && (int)$b['seeds_avg'] === 302 && (int)$b['seeds_min'] === 300 && (int)$b['seeds_max'] === 304, json_encode($b));
check('5m bucket 0: counters = last value (udp 2400)', (int)$b['udp_announces'] === 2400 && (int)$b['tcp_announces'] === 240, json_encode($b));
$h = $db->query("SELECT ts, samples, wl_share, torrents_avg FROM `" . ST_1H_TABLE . "` ORDER BY ts")->fetchAll(PDO::FETCH_ASSOC);
check('1h bucket 0: 60 samples, wl_share 0', (int)$h[0]['samples'] === 60 && (int)$h[0]['wl_share'] === 0, json_encode($h[0]));
check('1h bucket 1: wl_share 100 (whitelist hour)', (int)$h[1]['wl_share'] === 100, json_encode($h[1]));
check('1h bucket 2: wl_share 0', (int)$h[2]['wl_share'] === 0);
check('1h torrents avg bucket 1 = 1000+89.5→1090 (ROUND)', (int)$h[1]['torrents_avg'] === 1090, (string)$h[1]['torrents_avg']);
// roll-up is idempotent / resumable: re-running with the same now changes nothing
$before = $db->query("SELECT COUNT(*), SUM(samples) FROM `" . ST_5M_TABLE . "`")->fetch(PDO::FETCH_NUM);
statsTimelineRollup($db, $modeCfg, $base + 184 * 60);
$after = $db->query("SELECT COUNT(*), SUM(samples) FROM `" . ST_5M_TABLE . "`")->fetch(PDO::FETCH_NUM);
check('roll-up idempotent', $before == $after);
// cursor moved forward
check('rollup cursor 5m = start of current bucket', (int)statsTimelineStateRead()['rollup_5m_until'] === $base + 180 * 60);

// ── 5. series ────────────────────────────────────────────────────────────────
$nowS = $base + 184 * 60;
$s = statsTimelineSeries($db, $modeCfg, 86400, $nowS);
check('series 24h: raw table', $s['table'] === 'raw' && $s['step'] === 60, json_encode([$s['table'], $s['step']]));
// 180 + 3 (min 180, 181, 184) samples within 24 h; one gap (182,183 missing → 184-181 = 180 s = 3 steps → no null); so points = 183
check('series 24h: points = 183', $s['points'] === 183, (string)$s['points']);
check('series: t strictly increasing', $s['t'] === array_values(array_unique($s['t'])) && $s['t'][0] === $base);
check('series: mode flags (minute 0 = 0, minute 60 = 1, minute 120 = 0)', $s['mode'][0] === 0 && $s['mode'][60] === 1 && $s['mode'][120] === 0);
check('series: udp_rps first point null, then 10/s', $s['udp_rps'][0] === null && abs($s['udp_rps'][1] - 10.0) < 0.01 && abs($s['tcp_rps'][5] - 1.0) < 0.01, json_encode([$s['udp_rps'][0], $s['udp_rps'][1], $s['tcp_rps'][5]]));
check('series: restart at minute 100 → null rate, then 10/s again', $s['udp_rps'][100] === null && abs($s['udp_rps'][101] - 10.0) < 0.01, json_encode([$s['udp_rps'][100], $s['udp_rps'][101]]));
check('series: scrape/connect rates present', abs($s['connect_rps'][2] - (50 / 60)) < 0.01 && abs($s['scrape_rps'][2] - (8 / 60)) < 0.01, json_encode([$s['connect_rps'][2], $s['scrape_rps'][2]]));
check('series: seeds values', $s['seeds'][0] === 300 && $s['seeds'][179] === 479);
$s7 = statsTimelineSeries($db, $modeCfg, 7 * 86400, $nowS);
check('series 7d: 5m table (10080 raw points > cap)', $s7['table'] === '5m' && $s7['step'] === 300);
check('series 7d: 36 bucket points, avg values, mode from wl_share', $s7['points'] === 36 && $s7['seeds'][0] === 302 && $s7['mode'][12] === 1 && $s7['mode'][0] === 0, json_encode([$s7['points'], $s7['seeds'][0] ?? null]));
$s14 = statsTimelineSeries($db, $modeCfg, 14 * 86400, $nowS);
check('series 14d: 5m table (4032 ≤ cap)', $s14['table'] === '5m');
$s30 = statsTimelineSeries($db, $modeCfg, 30 * 86400, $nowS);
check('series 30d: 1h table, 3 points', $s30['table'] === '1h' && $s30['points'] === 3);
$s60 = statsTimelineSeries($db, $modeCfg, 60 * 86400, $nowS);
check('series 60d: 1h table', $s60['table'] === '1h');
// interval 30 s → 24 h = 2880 raw points (≤ 3000) still raw; raw_days 1 with 7 d range → 5m
check('choose: 24h @30s → raw', statsTimelineChooseTable(['stats_timeline_interval' => '30'] + $modeCfg, 86400)[2] === 'raw');
check('choose: 7d @ raw_days=1 → 5m', statsTimelineChooseTable(['stats_timeline_raw_days' => '1'] + $modeCfg, 7 * 86400)[2] === '5m');
check('choose: 30d @ keep_days=7 → 1h', statsTimelineChooseTable(['stats_timeline_keep_days' => '7'] + $modeCfg, 30 * 86400)[2] === '1h');

// gap handling: a hole of > 3 steps gets a null point
$db->exec("DELETE FROM `" . ST_RAW_TABLE . "` WHERE ts BETWEEN " . ($base + 30 * 60) . " AND " . ($base + 40 * 60));
$sg = statsTimelineSeries($db, $modeCfg, 86400, $nowS);
$gapIdx = array_search($base + 30 * 60, $sg['t'], true);
check('series: gap → explicit null point at prev+step', $gapIdx !== false && $sg['seeds'][$gapIdx] === null && $sg['t'][$gapIdx + 1] === $base + 41 * 60, json_encode([$gapIdx, $sg['seeds'][$gapIdx] ?? 'x']));
check('series: rate after a gap is null', $sg['udp_rps'][$gapIdx + 1] === null);

// ── 6. ingest from a parsed document (web path) respects the interval ─────────
$r = statsTimelineIngest($db, $modeCfg, $p, $nowS + 10, 'web');
check('web ingest 10 s after the last sample → not due', !$r['stored'] && $r['reason'] === 'not_due');
$r = statsTimelineIngest($db, $modeCfg, $p, $nowS + 60, 'web');
check('web ingest 60 s later → stored', $r['stored']);
check('state: source = web', statsTimelineStateRead()['last_sample_source'] === 'web');
// tick reuses a fresh stats cache instead of fetching upstream
$cacheFile = $root . '/config/stats_cache.json';
$hadCache = is_file($cacheFile) ? file_get_contents($cacheFile) : null;
file_put_contents($cacheFile, json_encode(['success' => true, 'fetched_at' => $nowS + 120] + $p));
$r = statsTimelineTick($db, $modeCfg, null, $nowS + 125);
check('tick with a fresh stats cache → sampled from cache (no upstream fetch)', $r['sampled'] && $r['source'] === 'cache', json_encode($r));
check('cache sample is stamped with fetched_at, not the tick time', (int)statsTimelineStateRead()['last_sample_at'] === $nowS + 120
    && (int)$db->query("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "` WHERE ts = " . ($nowS + 120))->fetchColumn() === 1);
// a cache that is fresh but NOT yet due by its own fetch time must not be stored (nor block the tick): falls through to upstream
file_put_contents($cacheFile, json_encode(['success' => true, 'fetched_at' => $nowS + 150] + $p));
$i = 200;
$r = statsTimelineTick($db, $modeCfg, null, $nowS + 200);
check('fresh-but-not-due cache → upstream fetch instead (no stats server here → error, nothing stored)', !$r['sampled'] && $r['source'] !== 'cache', json_encode($r));
check('state: last_sample_at unchanged', (int)statsTimelineStateRead()['last_sample_at'] === $nowS + 120);
// a web fetch in flight (stats_fetch.lock) → janitor skips its own upstream fetch
$lockFile = $root . '/config/stats_fetch.lock';
file_put_contents($lockFile, (string)($nowS + 200));
@touch($lockFile, $nowS + 200);
$r = statsTimelineTick($db, $modeCfg, null, $nowS + 205);
check('web fetch in flight → janitor does not double-scrape', !$r['sampled'] && $r['source'] === 'web-inflight' && $r['error'] === null, json_encode($r));
@unlink($lockFile);
if ($hadCache !== null) file_put_contents($cacheFile, $hadCache); else @unlink($cacheFile);

// ── 7. prune ─────────────────────────────────────────────────────────────────
$far = $nowS + 8 * 86400;       // 8 days later: raw (7 d) gone, 5m (60 d) kept
// raw rows the roll-ups have not consumed yet survive a prune (janitor outage longer than the retention).
// A raw row is safe to delete only once BOTH roll-up cursors have passed it.
$st0 = statsTimelineStateRead();
$safeCut = min((int)$st0['rollup_5m_until'], (int)$st0['rollup_1h_until']);
$unrolled = (int)$db->query("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "` WHERE ts >= $safeCut")->fetchColumn();
$pr0 = statsTimelinePrune($db, $modeCfg, $far, true);
check('prune keeps raw rows the roll-ups have not consumed', $unrolled > 0 && (int)$db->query("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "`")->fetchColumn() === $unrolled, json_encode([$unrolled, $pr0]));
statsTimelineRollup($db, $modeCfg, $far);   // roll-ups catch up → the remaining raw rows may go
$c5 = (int)$db->query("SELECT COUNT(*) FROM `" . ST_5M_TABLE . "`")->fetchColumn();
$c1 = (int)$db->query("SELECT COUNT(*) FROM `" . ST_1H_TABLE . "`")->fetchColumn();
$pr = statsTimelinePrune($db, $modeCfg, $far, true);
$rawLeft = (int)$db->query("SELECT COUNT(*) FROM `" . ST_RAW_TABLE . "`")->fetchColumn();
$c5b = (int)$db->query("SELECT COUNT(*) FROM `" . ST_5M_TABLE . "`")->fetchColumn();
check('prune after 8 days: raw rows deleted', $rawLeft === 0 && $pr['raw'] > 0, json_encode([$rawLeft, $pr]));
check('prune after 8 days: 5m roll-ups kept', $c5b === $c5, (string)$c5b);
$pr2 = statsTimelinePrune($db, $modeCfg, $far + 10);
check('prune throttled to once per hour', $pr2 === null);
$pr3 = statsTimelinePrune($db, $modeCfg, $far + 61 * 86400, true);
check('prune after 61 more days: 5m roll-ups gone, 1h kept', (int)$db->query("SELECT COUNT(*) FROM `" . ST_5M_TABLE . "`")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM `" . ST_1H_TABLE . "`")->fetchColumn() === $c1 && $c1 >= 3);
// roll-up after a long quiet period must not crawl through the pruned history (cursor jumps forward)
$i = 300;
statsTimelineTick($db, $modeCfg, $fetcher, $far + 61 * 86400 + 7200);
$cur = (int)statsTimelineStateRead()['rollup_5m_until'];
check('rollup cursor jumped past the pruned history', $cur >= $far + 61 * 86400 - 8 * 86400, date('Y-m-d H:i', $cur));

// ── 8. status ────────────────────────────────────────────────────────────────
$status = statsTimelineStatus($db, $modeCfg);
check('status: counts + state', isset($status['counts']['raw'], $status['state']['last_sample_at']) && $status['enabled']);

// ── 9. chart range controls (schema v10) ─────────────────────────────────────
$buttons = statsTimelineRangeButtons();
check('range buttons: known set', array_keys($buttons) === ['24h', '7d', '14d', '30d', '90d', 'all']);
// every button must be a range the API accepts, or the chart would ask for something that 400s
foreach (array_keys($buttons) as $key) {
    check("range button '$key' is a valid API range", statsTimelineRangeSeconds($key) !== null);
}
check('enabled ranges: empty setting = all buttons', statsTimelineEnabledRanges([]) === array_keys($buttons));
check('enabled ranges: subset kept in display order', statsTimelineEnabledRanges(['stats_timeline_ranges' => 'all,24h']) === ['24h', 'all']);
check('enabled ranges: unknown keys dropped', statsTimelineEnabledRanges(['stats_timeline_ranges' => '7d,bogus']) === ['7d']);
check('enabled ranges: nothing valid falls back to all', statsTimelineEnabledRanges(['stats_timeline_ranges' => 'bogus,nope']) === array_keys($buttons));
check('default range: 24h out of the box', statsTimelineDefaultRange([]) === '24h');
check('default range: honoured when enabled', statsTimelineDefaultRange(['stats_timeline_ranges' => '24h,7d,all', 'stats_timeline_default_range' => '7d']) === '7d');
check('default range: falls back to the first enabled button when switched off',
    statsTimelineDefaultRange(['stats_timeline_ranges' => '7d,all', 'stats_timeline_default_range' => '24h']) === '7d');
check('custom slider off by default', statsTimelineCustomRange([]) === false);
check('custom slider on', statsTimelineCustomRange(['stats_timeline_custom_range' => '1']) === true);

// custom spans are snapped to a fixed list: the API caches one file per stop, so an arbitrary
// ?span= must never create a new cache key
$stops = statsTimelineCustomStops();
check('custom stops: sorted, 1 h … 5 years', $stops[0] === 3600 && $stops === array_values(array_unique($stops)) && $stops[count($stops) - 1] === 157680000);
check('snap: exact stop kept', statsTimelineSnapSpan(86400) === 86400);
check('snap: nearest stop wins', statsTimelineSnapSpan(80000) === 86400, (string)statsTimelineSnapSpan(80000));
check('snap: below the floor', statsTimelineSnapSpan(1) === 3600);
check('snap: above the ceiling', statsTimelineSnapSpan(PHP_INT_MAX >> 4) === 157680000);
check('snap: negative', statsTimelineSnapSpan(-99) === 3600);

// mount attributes drive all three chart pages
$attrs = statsTimelineMountAttrs(['stats_timeline_ranges' => '24h,all', 'stats_timeline_default_range' => 'all', 'stats_timeline_custom_range' => '1'], true);
check('mount attrs: range + ranges + custom + compact',
    str_contains($attrs, 'data-timeline') && str_contains($attrs, 'data-range="all"')
    && str_contains($attrs, 'data-ranges="24h,all"') && str_contains($attrs, 'data-custom="1"')
    && str_contains($attrs, 'data-compact="1"'), $attrs);
check('mount attrs: not compact by default', !str_contains(statsTimelineMountAttrs([]), 'data-compact'));
check('mount attrs: html-escaped', !str_contains(statsTimelineMountAttrs(['stats_timeline_default_range' => '"><b>']), '<b>'));

// the JS mirrors both tables — a drift here is a silent feature break
$js = (string)@file_get_contents(dirname(__DIR__) . '/assets/js/stats-timeline.js');
check('JS RANGES mirrors the PHP button list', (bool)preg_match('/const RANGES = \[(.+?)\];/s', $js, $mm)
    && count(array_intersect(array_keys($buttons), preg_split('/\W+/', $mm[1], -1, PREG_SPLIT_NO_EMPTY))) === count($buttons));
check('JS CUSTOM_STOPS mirrors the PHP stop list', (bool)preg_match('/const CUSTOM_STOPS = \[(.+?)\];/s', $js, $ms2)
    && array_map('intval', preg_split('/[^0-9]+/', $ms2[1], -1, PREG_SPLIT_NO_EMPTY)) === $stops);

// cleanup
foreach ([ST_RAW_TABLE, ST_5M_TABLE, ST_1H_TABLE] as $t) $db->exec("TRUNCATE TABLE `$t`");
@unlink(statsTimelineStateFile());

/* == "fetched hashes" travels the whole way, not just into the chart file == */
//
// A series is four things that have to agree: a column on three tables, a value in the sampler, a
// key in the payload, and an entry in the chart. Checking only the last one is how a chart ends up
// drawing a flat zero and nobody notices for a month.

foreach (['stats_samples', 'stats_samples_5m', 'stats_samples_1h'] as $tbl) {
    check("$tbl has index_fetched", schemaColumnExists($db, $tbl, 'index_fetched'));
}

$row = statsTimelineRowFromParsed($db, $cfg, ['torrents' => 5, 'peers' => 9, 'seeds' => 4,
    'completed' => 1, 'uptime_seconds' => 60, 'connections' => []], time());
check('the sampler produces index_fetched', array_key_exists('index_fetched', $row));
check('and it is a count, not a flag', is_int($row['index_fetched']) && $row['index_fetched'] >= 0);

$payload = statsTimelineSeries($db, $cfg, 3600, time());
check('the payload carries the index_fetched series', array_key_exists('index_fetched', $payload));
check('and it is the same length as every other series',
      count($payload['index_fetched'] ?? []) === count($payload['t'] ?? []));

$js = (string)file_get_contents($root . '/assets/js/stats-timeline.js');
check('the chart declares the series and leaves it off by default',
      preg_match("/key: 'index_fetched'.*?on: false/s", $js) === 1);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
