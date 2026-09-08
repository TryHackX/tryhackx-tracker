<?php
/**
 * Observed-hash index.
 *
 * A catalogue of info hashes SEEN on the tracker (mostly during OPEN hours, when the whole swarm is
 * served) — NOT a whitelist: nothing here is ever served or written to the accesslist. It exists so an
 * admin can browse what people carry, look up metadata (via the existing worker) and, if wanted, promote
 * a hash into the real whitelist.
 *
 * Pipeline (all off unless index_enabled=1):
 *   - Poll  : GET index_source_url (OpenTracker full scrape, gzip). A streaming parser (bounded memory)
 *             keeps only complete >= index_min_seeders and upserts in batches under a wall-clock budget.
 *             Rows that are whitelisted or banned are dropped after each poll (they have their own tables).
 *   - Meta  : the janitor promotes up to index_meta_daily_budget rows/day from 'none' to 'pending' with a
 *             randomised meta_requested_at spread across the next 24 h, so the metadata worker (second
 *             queue, priority below the whitelist) drains them without flooding the DHT.
 *   - Life  : a new row lives until grace_until (index_grace_days) unless its metadata resolves; a 'done'
 *             row lives until protected_until (index_protect_days), extended on every poll where it still
 *             has >= 1 seeder. The hourly pruner drops expired rows and caps the table at index_max_rows.
 *
 * The metadata columns mirror `whitelist` so worker.py drains both queues with one code path; index_files
 * is keyed by info_hash (no numeric row id).
 */

// A transfer that dies part-way is still worth what arrived — but only if what arrived is a
// meaningful slice rather than a few kilobytes of a handshake. One MiB of gzipped scrape is roughly
// twenty thousand torrents; below that the resume cursor would inch forward for nothing.
const IDX_PARTIAL_MIN_BYTES = 1048576;
const IDX_BATCH            = 2000;    // rows per upsert (batch size barely affects throughput; ~18k rows/s)
const IDX_ENTRY_RE         = '/20:(.{20})d8:completei(\d+)e10:downloadedi(\d+)e10:incompletei(\d+)ee/s';
const IDX_PRUNE_EVERY      = 3600;    // seconds between prune runs
const IDX_FETCH_MAX_BYTES  = 268435456; // 256 MB hard cap on the downloaded scrape (safety)

function indexEnabled(array $cfg): bool { return (($cfg['index_enabled'] ?? '0') === '1'); }
function indexSourceUrl(array $cfg): string { return trim((string)($cfg['index_source_url'] ?? 'http://127.0.0.1:6969/scrape')); }
/**
 * COUNT(*) over the whole catalogue, remembered for thirty seconds — or for as long as the caller
 * asks, which one caller does: the statistics timeline passes ST_INDEX_ROWS_TTL (five minutes),
 * because its samples are a minute apart and a TTL under the interval expires before every one of
 * them. The stored entry carries its own timestamp and not a TTL, so a long-lived write cannot make
 * a short-lived reader stale: each caller judges the age it is willing to accept.
 *
 * This is the ONE query on the Index page worth caching, and it took measuring to know that. The
 * listing itself ran at 1 747 ms until v19 added the composite index; it is 0.8 ms now, and a cache
 * in front of THAT would have been pure liability — staleness bought with nothing. The count is a
 * different animal: InnoDB keeps no row counter, so an unfiltered COUNT(*) walks an index over the
 * whole table every time, and no index changes that. MEASURED on production 2026-09-08: EXPLAIN
 * says `index` over idx_index_seeders, 3 368 887 entries, "Using index", 939 ms per execution. The
 * 557 ms this comment used to quote was the same query on a 2.7 M-row table — the query did not get
 * slower, the catalogue got bigger, and it will keep doing that.
 *
 * What it draws is a pager. A pager does not need an exact number, it needs one that is right to the
 * page — and thirty seconds of drift on a table that gains rows in half-hourly batches cannot be
 * seen. Filtered counts deliberately do NOT come through here: the filters are indexed and cheap,
 * and there are enough distinct ones that a cache would miss more often than it hit.
 *
 * WHAT MUST NOT COME THROUGH HERE, and tests/index_test.php section 14 fails if it ever does: the
 * prune's own count (it sizes a DELETE), indexTick()'s over-the-cap gate (it stands one call away
 * from that DELETE, and it is cheap for a different reason — see there), and the `index_rows`
 * written into index_polls (that column is defined as the total AFTER the poll, and this cache is
 * warm from before it).
 */
function indexTotalCacheFile(): string { return __DIR__ . '/../config/index_count.json'; }

/**
 * Forget the cached total.
 *
 * Called wherever the row count really changes — a poll, a prune, a delete. Thirty seconds of drift
 * is invisible while nothing is happening and glaring the moment something does: a poll that has
 * just added six hundred thousand rows, followed by a page still showing the old number, reads as a
 * broken poll. The saving is for the quiet minutes in between, which is nearly all of them.
 */
function indexTotalCacheDrop(): void { @unlink(indexTotalCacheFile()); }

function indexTotalCached(PDO $db, int $ttl = 30): int {
    $file = indexTotalCacheFile();
    if (is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['at'], $c['total']) && (time() - (int)$c['at']) < $ttl) {
            return (int)$c['total'];
        }
    }
    try {
        $total = (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
    @file_put_contents($file, json_encode(['at' => time(), 'total' => $total]), LOCK_EX);
    return $total;
}

/**
 * The status card's counts, cached for a few seconds.
 *
 * MEASURED on production before this existed, one poll of the Index page:
 *
 *     in_grace      1 060 ms      by_status     1 287 ms
 *     protected        82 ms      files         9 593 ms   (COUNT(*) over 6.1 M rows)
 *     promoted          1 ms      expiring_24h  1 432 ms
 *                                 resolved_24h    468 ms
 *     ---------------------------------------------------
 *     TOTAL        13 921 ms, on a database shared with the mail, the forum and the file service
 *
 * Every one of these is a full-table aggregate on a 2 M-row table, and the admin page polls them.
 * This project's own rule is to measure before caching and never to cache a number that DECIDES
 * something — the prune's row count is deliberately left uncached for exactly that reason. These
 * are display: what the card shows a few seconds late is still true, and thirteen seconds of a
 * shared database is not.
 */
// KEYED, because there is more than one block cached here and a shared file would have the second
// caller read back the first one's array. That is the kind of bug a cache is supposed to not have.
function indexStatusCacheFile(string $key = 'counts'): string {
    return __DIR__ . '/../config/index_' . preg_replace('/[^a-z0-9_]/', '', $key) . '_cache.json';
}

function indexStatusCached(PDO $db, string $key, callable $compute, int $ttl = 30): array {
    $file = indexStatusCacheFile($key);
    if (is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['at'], $c['v']) && (time() - (int)$c['at']) < $ttl) return $c['v'];
    }
    $v = $compute();
    // A tmp+rename so a reader never sees half a file; a failed write just means the next caller
    // recomputes, which is the correct way for a cache to fail.
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode(['at' => time(), 'v' => $v])) !== false) @rename($tmp, $file);
    return $v;
}

/** Drop them when a poll or a prune has just changed the numbers. */
function indexStatusCacheDrop(): void {
    foreach (['counts', 'flow'] as $k) @unlink(indexStatusCacheFile($k));
}

function indexPollMinutes(array $cfg): int { return max(5, min(1440, (int)($cfg['index_poll_minutes'] ?? 30) ?: 30)); }
function indexMinSeeders(array $cfg): int { return max(0, min(100000, (int)($cfg['index_min_seeders'] ?? 1))); }
function indexMaxRows(array $cfg): int { return max(10, min(5000000, (int)($cfg['index_max_rows'] ?? 200000) ?: 200000)); }
function indexGraceDays(array $cfg): int { return max(1, min(90, (int)($cfg['index_grace_days'] ?? 3) ?: 3)); }
function indexProtectDays(array $cfg): int { return max(1, min(365, (int)($cfg['index_protect_days'] ?? 10) ?: 10)); }
function indexMetaDailyBudget(array $cfg): int { return max(0, min(1000000, (int)($cfg['index_meta_daily_budget'] ?? 500))); }
function indexMetaAutoQueue(array $cfg): bool { return (($cfg['index_meta_auto_queue'] ?? '0') === '1'); }
function indexKeepFiles(array $cfg): bool { return (($cfg['index_keep_files'] ?? '1') === '1'); }
function indexPollBudget(array $cfg): int { return max(5, min(120, (int)($cfg['index_poll_budget'] ?? 45) ?: 45)); }

/**
 * The stored-files-per-torrent ceiling, for everything on the PHP side: the save clamp, the field's
 * max=, the status card. It is written twice in this repository and cannot be written once — the
 * enforcing half is Python (MAX_FILES_MAX in worker/worker.py) and neither language can read the
 * other's constant. So: exactly two, both named, and tests/worker_settings_test.py fails if they
 * ever disagree. What must not happen again is the CONCURRENCY_MAX story, where the same number was
 * typed as a literal in four files and a panel offering 64 met a worker enforcing 16.
 */
const META_MAX_FILES_MAX = 50000;

/**
 * The panel's stored-files-per-torrent override, or null for "use the worker's own config file".
 *
 * Null is a real answer, not a missing one: empty is the default and means the worker keeps the
 * `max_files` in /etc/tracker-metadata.conf. Anything non-numeric reads as empty here for the same
 * reason the worker ignores it — a typo must not silently become a cap of 1.
 */
function indexMetaMaxFiles(array $cfg): ?int {
    $v = trim((string)($cfg['meta_max_files'] ?? ''));
    if ($v === '' || !ctype_digit($v)) return null;
    return max(1, min(META_MAX_FILES_MAX, (int)$v));
}

// ─────────────────────────────────────────────────────────────────────────────
// How a file list LOADS — the ceilings, then the six settings that live under them
// ─────────────────────────────────────────────────────────────────────────────
//
// Three questions, asked twice. How does the browser ask for the next slice (mode), how much does
// one slice carry (batch), how much may one page ever accumulate (max). Twice, because the public
// reader and the operator are not the same visitor: the reader arrives over the internet, anonymous
// or a member, and every page they ask for spends a token from the per-IP `idxsearch` bucket that
// api/index_search.php shares — a bucket whose accounting rewrites the whole of
// config/rate_limits.json under an exclusive lock on every call. The operator is past
// panel.whitelist.view on a page that is theirs to wait on and is not rate-limited at all. One
// number for both would mean an operator raising the panel's batch also raising it for every
// stranger at the same moment.
//
// The MODE is a client behaviour; the SIZE is a server rule. Mode only decides WHEN the browser
// asks again. A reply is bounded by the batch whatever the mode says, and the running total is
// enforced by the endpoint refusing to serve past the ceiling — never by the browser stopping
// politely.

/** The whole vocabulary, in one place: the two helpers, the save coercion and the two <select>s. */
const IDX_FILES_MODES = ['scroll', 'button', 'all'];

// A floor rather than a minimum worth having: below ~100 the per-request overhead (a session-less
// GET, a rate-limit token, a JSON envelope) costs more than the rows do.
const IDX_FILES_BATCH_MIN = 100;
// Unchanged from what api/index_files.php accepted as a hand-typed ?limit= before 1.38.0. 5 000
// paths and sizes is already close to a megabyte of JSON out of a database shared with the mail
// server, the forum and the file host.
const IDX_FILES_BATCH_MAX = 5000;
// The public total, and deliberately equal to its own default: this ceiling can be LOWERED from the
// panel and not raised. Paging is LIMIT/OFFSET, and it stays that way because both permission gates
// in api/index_files.php are expressed as tests on $offset — a keyset cursor would walk straight
// past index.files_all. That makes the last page of the deepest allowed list ~20 000 primary-key
// lookups for path/size (index_files carries idx_if_hash, which InnoDB extends with the PK, so the
// walk is an index scan and not a filesort — but it is still 20 000 rows fetched to answer one
// request). Raising it is a code change on purpose, so that the cost is measured and not typed.
const IDX_FILES_MAX_HARD = 20000;
// The panel's batch may be four times the public one: the operator is the person paying for the
// wait, and nobody else is queued behind them.
const IDX_FILES_ADMIN_BATCH_MAX = 20000;
// Unchanged from the literal at api/admin/index_item.php:30 before 1.38.0, and kept only because
// lowering a default silently shrinks what an operator's existing "Load the whole file list" button
// does. It is a fetchAll of up to a million rows followed by a json_encode, in php-fpm, on the same
// box as MariaDB — the field's hint says so and recommends lowering it.
const IDX_FILES_ADMIN_MAX_HARD = 1000000;

// Clamp-on-read, the same shape as indexPollMinutes() above and for the same reason, only more so:
// these six rows can also arrive from install.php, a restored backup, or somebody with a MySQL
// client on a database that three other applications also use. This is the clamp that actually
// holds — the one in save_settings.php only governs what the form is allowed to write.
function indexFilesMode(array $cfg): string {
    $m = (string)($cfg['index_files_mode'] ?? 'scroll');
    return in_array($m, IDX_FILES_MODES, true) ? $m : 'scroll';
}
function indexFilesBatch(array $cfg): int { return max(IDX_FILES_BATCH_MIN, min(IDX_FILES_BATCH_MAX, (int)($cfg['index_files_batch'] ?? 2000) ?: 2000)); }
function indexFilesAdminMode(array $cfg): string {
    // Falls back to 'button', not to 'scroll': 'button' is what the panel has always done, and a
    // garbage value must not flip the modals into fetching whole file lists unasked.
    $m = (string)($cfg['index_files_admin_mode'] ?? 'button');
    return in_array($m, IDX_FILES_MODES, true) ? $m : 'button';
}
function indexFilesAdminBatch(array $cfg): int { return max(IDX_FILES_BATCH_MIN, min(IDX_FILES_ADMIN_BATCH_MAX, (int)($cfg['index_files_admin_batch'] ?? 5000) ?: 5000)); }

// Floored at one batch, so the first page can never already be over the ceiling — a max below a
// batch would otherwise answer the very first request with "capped, nothing here". The repair is
// silent and on read rather than a refusal on save, because the panel prints the helper's output
// as the field's value: what the operator sees in the box is what is in force.
function indexFilesMax(array $cfg): int { return max(indexFilesBatch($cfg), min(IDX_FILES_MAX_HARD, (int)($cfg['index_files_max'] ?? 20000) ?: 20000)); }
function indexFilesAdminMax(array $cfg): int { return max(indexFilesAdminBatch($cfg), min(IDX_FILES_ADMIN_MAX_HARD, (int)($cfg['index_files_admin_max'] ?? 1000000) ?: 1000000)); }

/**
 * The exact number of catalogue rows.
 *
 * Deliberately NOT indexTotalCached(): the caller at the prune site uses this to decide whether to
 * start deleting, and the same rule applies here as there — a pager may be approximate, a delete may
 * not. Cards and status panels use the cached one; anything that acts on the number uses this.
 */
function indexRowsCount(PDO $db): int {
    try { return (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn(); } catch (\Throwable $e) { return 0; }
}

// ─────────────────────────────────────────────────────────────────────────────
// State file (config/index_state.json)
// ─────────────────────────────────────────────────────────────────────────────

function indexStateFile(): string { return __DIR__ . '/../config/index_state.json'; }
function indexStateLockFile(): string { return __DIR__ . '/../config/index_state.lock'; }
function indexPollLockFile(): string { return __DIR__ . '/../config/index_poll.lock'; }
function indexPruneLockFile(): string { return __DIR__ . '/../config/index_prune.lock'; }

function indexStateDefaults(): array {
    return [
        'last_poll_at' => 0, 'last_poll' => null, 'last_error' => null, 'last_error_at' => 0,
        // set when a transfer ended early but enough arrived to parse; cleared by the next clean poll
        'last_partial' => null,
        'meta_budget_day' => '', 'meta_budget_used' => 0, 'poll_skip' => 0,
        'last_prune_at' => 0, 'last_prune' => null, 'last_tick_at' => 0,
        // The cap as the last tick saw it. Zero on a fresh state file, which differs from any legal
        // index_max_rows and therefore makes the first tick count once — the safe direction.
        'max_rows_seen' => 0,
    ];
}

function indexStateRead(): array {
    $f = indexStateFile();
    $data = [];
    if (is_file($f)) { $raw = @file_get_contents($f); $data = $raw ? (json_decode($raw, true) ?: []) : []; }
    return array_merge(indexStateDefaults(), is_array($data) ? $data : []);
}

function indexStateUpdate(callable $fn): array {
    $lockH = @fopen(indexStateLockFile(), 'c');
    if ($lockH) @flock($lockH, LOCK_EX);
    try {
        $state = indexStateRead();
        $r = $fn($state);
        if ($r !== false) {
            $tmp = indexStateFile() . '.tmp.' . getmypid();
            @file_put_contents($tmp, json_encode($state), LOCK_EX);
            @rename($tmp, indexStateFile());
        }
        return $state;
    } finally {
        if ($lockH) { @flock($lockH, LOCK_UN); @fclose($lockH); }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Full-scrape download + streaming parse
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Download the full scrape to a temp file (gzip kept on disk — smaller). Returns
 * ['file'=>?path,'gzip'=>bool,'bytes'=>int,'ms'=>int,'error'=>?string]. Caller unlinks the file.
 */
function indexFetchFullScrape(string $url, int $timeout, string $tmpDir): array {
    $t0 = microtime(true);
    $out = ['file' => null, 'gzip' => false, 'bytes' => 0, 'ms' => 0, 'error' => null, 'partial' => null];
    if ($url === '' || !preg_match('#^https?://#i', $url)) { $out['error'] = __('api.index.invalid_source_url'); return $out; }
    if (!function_exists('curl_init')) { $out['error'] = __('api.index.curl_required'); return $out; }
    $tmp = rtrim($tmpDir, '/\\') . '/index_scrape_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.bin';
    $fh = @fopen($tmp, 'wb');
    if (!$fh) { $out['error'] = __('api.index.temp_file'); return $out; }
    $ch = curl_init();
    $tooBig = false;
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => max(5, $timeout), CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'tryhackx-tracker/1.5 index', CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept-Encoding: gzip'],   // keep gzip on the wire; do NOT auto-decode
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dltotal, $dlnow) use (&$tooBig) {
            if ($dlnow > IDX_FETCH_MAX_BYTES) { $tooBig = true; return 1; }
            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    fclose($fh);
    $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
    if ($tooBig) { @unlink($tmp); $out['error'] = __('api.index.scrape_too_big', ['n' => IDX_FETCH_MAX_BYTES]); return $out; }
    if ($ok === false) {
        // KEEP WHAT ARRIVED. A full scrape that dies at 90 % is 90 % of the catalogue, and this
        // parser is built for partial passes already — the poll-time budget stops it mid-file every
        // time the scrape is big, records how far it got, and resumes there on the next poll. A
        // transfer that ends early is the same situation arriving by a different route, so throwing
        // the file away would discard good data for no reason other than how the reading stopped.
        //
        // The floor matters: this only applies when enough arrived to be worth a pass. Anything
        // smaller, or a body that does not start like a scrape, is a failure and is treated as one.
        $have = (int)@filesize($tmp);
        $head = '';
        if ($have > 0 && ($hf = @fopen($tmp, 'rb'))) { $head = (string)fread($hf, 8); fclose($hf); }
        $looksReal = (strncmp($head, "\x1f\x8b", 2) === 0) || (strncmp($head, 'd5:files', 8) === 0);
        if ($have >= IDX_PARTIAL_MIN_BYTES && $looksReal && $code === 200) {
            $out['bytes'] = $have;
            $out['gzip'] = strncmp($head, "\x1f\x8b", 2) === 0;
            $out['file'] = $tmp;
            $out['partial'] = $err !== '' ? $err : __('api.index.transfer_ended_early');
            return $out;
        }
        @unlink($tmp);
        // "chunk hex-length char not a hex digit" reads like a broken panel and is not one. The full
        // scrape is tens of megabytes of gzip that opentracker sends with Transfer-Encoding: chunked,
        // announcing each chunk's length before writing it; if the tracker gets out of step with its
        // own framing mid-transfer — which is what a busy tracker rewriting its torrent list while
        // serving a 30 MB snapshot can do — the client lands mid-body and reads a data byte where a
        // length should be. Nothing was written, and the source is not corrupt.
        //
        // An immediate retry is worse than useless here: opentracker rate-limits FULL scrapes and
        // answers the next one with 402 (see below), so it would spend the allowance and report a
        // second, different-looking failure. The next poll gets a clean snapshot.
        $out['error'] = ($errno === 56 && stripos($err, 'chunk') !== false)
            ? __('api.index.chunked_framing_lost', ['err' => $err])
            : __('api.index.curl_error', ['err' => $err]);
        return $out;
    }
    if ($code !== 200) {
        @unlink($tmp);
        // 402 is not a payment and not our firewall: it is opentracker's own refusal of a FULL
        // scrape asked for too soon after the last one. It clears itself on the next poll, and a
        // bare "HTTP 402" sends whoever reads it hunting through rate limits that have nothing to
        // do with it -- the throttle on this machine is UDP-only and this request is HTTP.
        $out['error'] = $code === 402
            ? __('api.index.http_402')
            : __('api.index.http_code', ['code' => $code]);
        return $out;
    }
    $out['bytes'] = (int)@filesize($tmp);
    if ($out['bytes'] < 9) { @unlink($tmp); $out['error'] = __('api.index.empty_reply'); return $out; }
    // gzip magic
    $magic = '';
    if ($m = @fopen($tmp, 'rb')) { $magic = fread($m, 2); fclose($m); }
    $out['gzip'] = (substr($magic, 0, 2) === "\x1f\x8b");
    $out['file'] = $tmp;
    return $out;
}

/**
 * Stream-parse a scrape file (gzip or plain), keeping entries with complete >= $minSeeders. Calls
 * $onBatch(array $rows) every IDX_BATCH kept rows ($rows = [[hash_hex, seeders, leechers, completed], ...]).
 * Skips the first $skip entries (resume cursor — see indexPoll), stops early once $deadline (microtime)
 * passes. Returns ['entries'=>int (total seen incl. skipped),'kept'=>int,'truncated'=>bool].
 * Rejects a body that does not begin like a bencoded scrape reply (`d5:files…`).
 */
function indexParseScrapeFile(string $file, bool $gzip, int $minSeeders, callable $onBatch, float $deadline, int $skip = 0): array {
    $out = ['entries' => 0, 'kept' => 0, 'truncated' => false];
    $fh = $gzip ? @gzopen($file, 'rb') : @fopen($file, 'rb');
    if (!$fh) { $out['error'] = __('api.index.open_scrape_file'); return $out; }
    $read = $gzip ? 'gzread' : 'fread';
    $eof = $gzip ? 'gzeof' : 'feof';
    $close = $gzip ? 'gzclose' : 'fclose';
    $carry = '';
    $batch = [];
    $first = true;
    try {
        while (!$eof($fh)) {
            // @: a gzip stream that ends mid-member is now an ordinary case (a transfer kept after
            // it was cut short), and PHP's warning about it says nothing the return value does not.
            $chunk = @$read($fh, 1 << 20);
            if ($chunk === false || $chunk === '') break;
            $buf = $carry . $chunk;
            if ($first) {
                $first = false;
                // a real scrape reply starts with d5:filesd… — reject an HTML error page / wrong endpoint
                if (strncmp($buf, 'd5:files', 8) !== 0) { $out['error'] = __('api.index.not_scrape_reply'); break; }
            }
            $last = 0;
            if (preg_match_all(IDX_ENTRY_RE, $buf, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($mm as $e) {
                    $out['entries']++;
                    $last = $e[0][1] + strlen($e[0][0]);
                    if ($out['entries'] <= $skip) continue;   // resume cursor: already processed in a prior pass
                    $c = (int)$e[2][0];
                    if ($c >= $minSeeders) {
                        $batch[] = [bin2hex($e[1][0]), $c, (int)$e[4][0], (int)$e[3][0]];
                        if (count($batch) >= IDX_BATCH) { $onBatch($batch); $out['kept'] += count($batch); $batch = []; }
                    }
                }
            }
            $carry = substr($buf, $last);
            // keep the carry bounded: an entry is < 100 bytes, so a buffer with no complete entry can only
            // hold a partial one — never let it grow without bound if the source is malformed
            if (strlen($carry) > 4096) $carry = substr($carry, -128);
            if (microtime(true) >= $deadline) { $out['truncated'] = true; break; }
        }
        if ($batch) { $onBatch($batch); $out['kept'] += count($batch); }
    } finally {
        $close($fh);
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Poll
// ─────────────────────────────────────────────────────────────────────────────

/** Is a poll due? */
function indexPollDue(array $state, array $cfg, int $now): bool {
    return $now - (int)$state['last_poll_at'] >= indexPollMinutes($cfg) * 60;
}

/**
 * A row is stamped "seen" at most once per this many seconds. See indexUpsertBatch().
 *
 * Six hours against a 30-minute poll: eleven polls in twelve leave an unchanged row untouched, and
 * `last_seen` on the catalogue page is at most six hours behind the truth for a row nobody joined
 * or left — which is also what "last seen" means to the person reading it.
 */
const IDX_SEEN_WINDOW_SEC = 21600;

/**
 * Upsert one batch of scrape rows into index_hashes. A 'done' row that still has >= 1 seeder gets its
 * protection extended. $rows = [[hash, seeders, leechers, completed], ...].
 *
 * A ROW THAT DID NOT CHANGE IS NOT WRITTEN. Measured on production (1.5 M rows, 621 k kept per
 * poll): the download takes 6 s and the parse 3 s, and the upsert took 82 s — 96 % of the poll,
 * and the reason every other poll ran out of its time budget. Of those rows 99 % already existed
 * and 78 % carried exactly the same seeders/leechers/completed as the poll before; the old
 * statement still rewrote every one of them (last_seen = NOW(), seen_count + 1), and with it four
 * secondary indexes per row. InnoDB skips a row whose assignments all resolve to its current
 * values, so the statement is arranged to make that the common case:
 *   - the counters only change when they changed on the tracker;
 *   - last_seen and seen_count move at most once per IDX_SEEN_WINDOW_SEC (assignment order matters:
 *     MySQL evaluates ON DUPLICATE KEY UPDATE left to right and later expressions see the new
 *     values, so the seen_count test runs before last_seen is rewritten);
 *   - the protection window is pushed forward at most once a day, not on every poll.
 * Same reproducer, conditional statement: 43 s for the full pass — inside the budget with room.
 * seen_count therefore counts the six-hour windows a hash was seen in, not the polls; the
 * catalogue sorts by it the same way and the number stays monotonic.
 */
function indexUpsertBatch(PDO $db, array $rows, int $graceDays, int $protectDays): void {
    if (!$rows) return;
    $w = (int)IDX_SEEN_WINDOW_SEC;
    $ph = rtrim(str_repeat('(?, NOW(), NOW(), 1, ?, ?, ?, ?, NOW() + INTERVAL ' . $graceDays . ' DAY),', count($rows)), ',');
    $sql = "INSERT INTO index_hashes (info_hash, first_seen, last_seen, seen_count, last_seeders, last_leechers, last_completed, peak_seeders, grace_until)
            VALUES $ph
            ON DUPLICATE KEY UPDATE
                seen_count = IF(last_seen <= NOW() - INTERVAL $w SECOND, seen_count + 1, seen_count),
                last_seen  = IF(last_seen <= NOW() - INTERVAL $w SECOND, NOW(), last_seen),
                protected_until = IF(meta_status = 'done' AND VALUES(last_seeders) >= 1
                                     AND (protected_until IS NULL OR protected_until < NOW() + INTERVAL " . max(0, $protectDays - 1) . " DAY),
                                     GREATEST(COALESCE(protected_until, NOW()), NOW() + INTERVAL " . $protectDays . " DAY),
                                     protected_until),
                last_seeders = VALUES(last_seeders), last_leechers = VALUES(last_leechers), last_completed = VALUES(last_completed),
                peak_seeders = GREATEST(peak_seeders, VALUES(last_seeders))";
    $args = [];
    foreach ($rows as $r) { $args[] = $r[0]; $args[] = $r[1]; $args[] = $r[2]; $args[] = $r[3]; $args[] = $r[1]; }
    $db->prepare($sql)->execute($args);
}

/**
 * One poll: fetch the full scrape, stream-parse it, upsert kept rows, then drop rows that are whitelisted
 * or banned. $fetcher (tests): fn(): array{file:string, gzip:bool} replaces the HTTP download.
 * Returns a summary array; never throws (errors recorded in state).
 */
function indexPoll(PDO $db, array $cfg, ?callable $fetcher = null, ?int $now = null, ?string $tmpDir = null): array {
    $now = $now ?? time();
    $out = ['ok' => false, 'entries' => 0, 'kept' => 0, 'truncated' => false, 'removed_wl' => 0,
            'removed_ban' => 0, 'bytes' => 0, 'ms' => 0, 'error' => null, 'partial' => null];
    // one poll at a time across processes (janitor CLI + web "Poll now" + a double click). Non-blocking:
    // a second caller returns immediately instead of starting a duplicate full scrape.
    $lockH = @fopen(indexPollLockFile(), 'c');
    if (!$lockH || !@flock($lockH, LOCK_EX | LOCK_NB)) {
        if ($lockH) @fclose($lockH);
        $out['error'] = 'already polling';
        return $out;
    }
    try {
        $tmpDir = $tmpDir ?? sys_get_temp_dir();
        $graceDays = indexGraceDays($cfg); $protectDays = indexProtectDays($cfg); $minSeeders = indexMinSeeders($cfg);
        $stateSkip = max(0, (int)indexStateRead()['poll_skip']);  // resume cursor from the previous truncated pass
        $skip = $stateSkip;
        $ownFile = false;
        if ($fetcher !== null) {
            $f = $fetcher();
            $file = $f['file'] ?? null; $gzip = (bool)($f['gzip'] ?? false); $out['bytes'] = (int)($f['bytes'] ?? (($file && is_file($file)) ? filesize($file) : 0));
            if (!$file || !is_file($file)) { $out['error'] = $f['error'] ?? 'fetch failed'; }
            // the stub speaks the same language as the real fetch, or the tests cannot exercise the
            // path that matters most here
            $out['partial'] = $f['partial'] ?? null;
        } else {
            $fetched = indexFetchFullScrape(indexSourceUrl($cfg), min(90, max(5, indexPollBudget($cfg))), $tmpDir);
            $file = $fetched['file']; $gzip = $fetched['gzip']; $out['bytes'] = $fetched['bytes']; $out['ms'] = $fetched['ms'];
            $ownFile = $file !== null;
            $out['partial'] = $fetched['partial'] ?? null;
            // a fatal (execution-time limit, OOM) skips finally blocks — make sure the temp scrape
            // is removed at shutdown regardless (unlink of an already-removed file is a no-op)
            if ($ownFile) register_shutdown_function(static function () use ($file) { @unlink($file); });
            if ($fetched['error']) $out['error'] = $fetched['error'];
        }
        if ($out['partial'] !== null) {
            // A SHORT FILE IS NOT THE SAME FILE, so the resume cursor does not apply to it.
            //
            // The cursor counts entries into the COMPLETE scrape, and a truncated download cannot
            // contain anything past the point where it stopped. Carrying the cursor over means every
            // entry in the short file falls below it and the poll keeps nothing. That is not
            // hypothetical: production did exactly this twice in a row — 386 870 entries seen,
            // 0 kept, and not one row in the index refreshed for two hours.
            //
            // So read all of what arrived. Coverage is reconciled below, where the cursor moves to
            // the FURTHER of the two: it must never walk backwards onto ground already covered.
            $skip = 0;
        }
        if ($out['error'] !== null || !$file) {
            indexStateUpdate(function (array &$s) use ($out, $now) { $s['last_error'] = $out['error']; $s['last_error_at'] = $now; return true; });
            return $out;
        }
        $t0 = microtime(true);
        $deadline = $t0 + indexPollBudget($cfg);
        try {
            $onBatch = function (array $rows) use ($db, $graceDays, $protectDays) {
                $db->beginTransaction();
                try { indexUpsertBatch($db, $rows, $graceDays, $protectDays); $db->commit(); }
                catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            };
            $p = indexParseScrapeFile($file, $gzip, $minSeeders, $onBatch, $deadline, $skip);
            $out['entries'] = $p['entries']; $out['kept'] = $p['kept'];
            // A file that arrived incomplete is a truncated pass by definition, whatever the parser
            // thought: it ran out of file rather than out of time, and the tail is still unread.
            $out['truncated'] = $p['truncated'] || $out['partial'] !== null;
            if (isset($p['error'])) $out['error'] = $p['error'];
            // drop anything that lives in the whitelist or the ban list — those have their own tables
            $out['removed_wl'] = (int)$db->exec("DELETE i FROM index_hashes i JOIN whitelist w ON w.info_hash = i.info_hash");
            $out['removed_ban'] = (int)$db->exec("DELETE i FROM index_hashes i JOIN banned_hashes b ON b.info_hash = i.info_hash");
            $out['ok'] = $out['error'] === null;
        } catch (\Throwable $e) {
            $out['error'] = __('api.index.poll_exception', ['msg' => $e->getMessage()]);
            error_log('[index poll] ' . $e->getMessage());
        } finally {
            if ($ownFile) @unlink($file);
        }
        $out['ms'] = $out['ms'] + (int)round((microtime(true) - $t0) * 1000);
        // Resume at the tail next time; reset once the whole file was covered. After a truncated
        // DOWNLOAD the cursor takes the further of the two, because this pass restarted at zero and
        // an earlier pass may well have reached further into the scrape than this short file goes.
        $skipNext = 0;
        if ($out['ok'] && $out['truncated']) {
            $skipNext = $out['partial'] !== null ? max($stateSkip, $out['entries']) : $out['entries'];
        }
        // ONE ROW PER POLL, kept.
        //
        // `skip_from` is the cursor this pass began at, and it is the difference between "this poll
        // delivered 386 870 entries" and the truth, which is that it walked past 386 870 and only the
        // ones beyond the cursor were new. Written before the state file so a crash between the two
        // leaves a recorded poll rather than a silent one.
        try {
            // The tracker's own torrent count, from the same reading the swarm timeline took. Not
            // fetched here: a poll must not depend on a second network call, and a number from a few
            // minutes ago is the right denominator for a scrape that took minutes to walk.
            $rowsTotal = null;
            if (function_exists('statsTimelineStateRead')) {
                $ls = statsTimelineStateRead()['last_sample'] ?? null;
                if (is_array($ls) && !empty($ls['torrents'])) $rowsTotal = (int)$ls['torrents'];
            }
            $db->prepare("INSERT INTO index_polls
                          (ts, entries, skip_from, kept, bytes, ms, truncated, partial,
                           removed_wl, removed_ban, rows_total, index_rows, error)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE entries = VALUES(entries), kept = VALUES(kept)")
               // THE CURSOR THAT WAS ACTUALLY APPLIED, not the one that was stored.
               //
               // A truncated download resets $skip to 0 above and reads the short file from the
               // start, while the stored cursor stays where a longer earlier pass left it. Recording
               // the stored one made the first two live rows read "delivered 0" beside "kept
               // 189 434" — a poll that plainly delivered something, reported as having delivered
               // nothing, and a coverage of 0 % on the chart to go with it.
               // index_rows is the EXACT count, deliberately, and the only exact one on this path.
               //
               // The column is defined as what the catalogue held AFTERWARDS (includes/schema.php,
               // index_polls) and the coverage page divides by it. The 30 s cache is warm here
               // almost by construction: the statistics timeline fills it a second or two earlier in
               // the same janitor tick (tools/janitor.php samples before it polls), so a cached read
               // would record the PRE-poll total as the post-poll figure — every poll, for ever,
               // with nothing in the history to show it was wrong. One 939 ms count per poll (every
               // 30 minutes by default) buys a number that is true.
               ->execute([$now, $out['entries'], $skip, $out['kept'], $out['bytes'], $out['ms'],
                          $out['truncated'] ? 1 : 0, $out['partial'] !== null ? mb_substr((string)$out['partial'], 0, 64) : null,
                          $out['removed_wl'], $out['removed_ban'], $rowsTotal, indexRowsCount($db),
                          $out['error'] !== null ? mb_substr((string)$out['error'], 0, 190) : null]);
            // Pruned here rather than on a timer: this is the only thing that writes the table, so it
            // is the only place that can leave it too big.
            $keep = max(1, min(3650, (int)($cfg['index_poll_keep_days'] ?? 90)));
            $db->prepare("DELETE FROM index_polls WHERE ts < ?")->execute([$now - $keep * 86400]);
        } catch (\Throwable $e) {
            // A poll that ran must not fail because its bookkeeping did.
            error_log('[index poll history] ' . $e->getMessage());
        }

        indexStateUpdate(function (array &$s) use ($out, $now, $skipNext) {
            $s['last_poll_at'] = $now;
            $s['poll_skip'] = $skipNext;
            $s['last_poll'] = ['at' => $now, 'entries' => $out['entries'], 'kept' => $out['kept'], 'truncated' => $out['truncated'],
                               'removed_wl' => $out['removed_wl'], 'removed_ban' => $out['removed_ban'], 'bytes' => $out['bytes'], 'ms' => $out['ms']];
            $s['last_partial'] = $out['partial'] === null ? null
                : ['at' => $now, 'bytes' => $out['bytes'], 'entries' => $out['entries'], 'reason' => $out['partial']];
            if ($out['error'] !== null) { $s['last_error'] = $out['error']; $s['last_error_at'] = $now; }
            elseif ($out['ok']) { $s['last_error'] = null; }
            return true;
        });
        // The row count has just changed, probably by a lot. Anything that shows a total must ask
        // again rather than answering from a cache filled before the poll ran.
        indexTotalCacheDrop();
        indexStatusCacheDrop();
        return $out;
    } finally {
        @flock($lockH, LOCK_UN); @fclose($lockH);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Metadata budget + prune (janitor)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Promote up to the remaining daily budget of 'none' rows to 'pending', highest last_seeders first, with a
 * randomised meta_requested_at spread across the next 24 h so the worker (second queue) doesn't flood the
 * DHT. The daily counter resets on date change. Returns rows queued.
 */
function indexQueueMetaBudget(PDO $db, array $cfg, ?int $now = null): int {
    $budget = indexMetaDailyBudget($cfg);
    if ($budget <= 0) return 0;
    $now = $now ?? time();
    $today = date('Y-m-d', $now);
    $queued = 0;
    indexStateUpdate(function (array &$s) use ($db, $budget, $today, &$queued) {
        if (($s['meta_budget_day'] ?? '') !== $today) { $s['meta_budget_day'] = $today; $s['meta_budget_used'] = 0; }
        $remaining = $budget - (int)$s['meta_budget_used'];
        if ($remaining <= 0) return false;
        $remaining = min($remaining, 5000);   // never queue more than 5000 in one tick
        $st = $db->prepare("UPDATE index_hashes SET meta_status = 'pending', meta_priority = -1,
                                meta_requested_at = NOW() + INTERVAL FLOOR(RAND() * 86400) SECOND, meta_error = NULL, meta_claim = NULL
                            WHERE meta_status = 'none' ORDER BY last_seeders DESC, last_seen DESC LIMIT " . (int)$remaining);
        $st->execute();
        $queued = $st->rowCount();
        if ($queued > 0) $s['meta_budget_used'] = (int)$s['meta_budget_used'] + $queued;
        return $queued > 0;
    });
    return $queued;
}

/**
 * Auto-queue mode (index_meta_auto_queue=1): EVERY 'none' row goes to 'pending' — no daily budget.
 * Bounded per tick and spread over the next hour so a huge poll doesn't hand the worker (and the DHT)
 * everything at once; the janitor tick keeps draining until no 'none' rows remain. Returns rows queued.
 */
function indexQueueMetaAuto(PDO $db): int {
    $st = $db->prepare("UPDATE index_hashes SET meta_status = 'pending', meta_priority = -1,
                            meta_requested_at = NOW() + INTERVAL FLOOR(RAND() * 3600) SECOND, meta_error = NULL, meta_claim = NULL
                        WHERE meta_status = 'none' ORDER BY last_seeders DESC, last_seen DESC LIMIT 5000");
    $st->execute();
    return $st->rowCount();
}

/**
 * Hourly pruner: drop expired rows (grace elapsed without metadata, or protection elapsed for done rows),
 * cap the table at index_max_rows (oldest last_seen without protection), and clean orphaned index_files.
 * Returns ['expired'=>int,'capped'=>int,'orphan_files'=>int] or null when throttled.
 */
function indexPrune(PDO $db, array $cfg, ?int $now = null, bool $force = false): ?array {
    $now = $now ?? time();
    $st = indexStateRead();
    if (!$force && $now - (int)$st['last_prune_at'] < IDX_PRUNE_EVERY) return null;
    // one prune at a time across processes: a janitor tick and a manual/forced prune racing each other
    // both compute "excess over the cap" from the same snapshot and together over-delete
    $lockH = @fopen(indexPruneLockFile(), 'c');
    if (!$lockH || !@flock($lockH, LOCK_EX | LOCK_NB)) { if ($lockH) @fclose($lockH); return null; }
    try {
    $res = ['expired' => 0, 'capped' => 0, 'orphan_files' => 0, 'protected_backfill' => 0];
    // a row whose metadata resolved since the last poll has protected_until NULL until the next poll
    // touches it — grant the protection window here FIRST so the cap-prune below can never eat a row
    // the worker just resolved
    $bf = $db->prepare("UPDATE index_hashes SET protected_until = DATE_ADD(NOW(), INTERVAL " . indexProtectDays($cfg) . " DAY)
                        WHERE meta_status = 'done' AND protected_until IS NULL");
    $bf->execute();
    $res['protected_backfill'] = $bf->rowCount();
    // expired: never-resolved past grace, or done past protection. Batched with LIMIT so one prune never
    // takes a huge row-lock set on a 200k table (each chunk autocommits — prune runs outside a transaction).
    do {
        $nd = (int)$db->exec(
            "DELETE FROM index_hashes WHERE
                ((meta_status <> 'done' AND grace_until IS NOT NULL AND grace_until < NOW())
              OR (meta_status  = 'done' AND protected_until IS NOT NULL AND protected_until < NOW()))
             LIMIT 5000");
        $res['expired'] += $nd;
    } while ($nd === 5000);
    // cap: delete oldest unprotected rows over the limit — but not while a truncated poll awaits its
    // resume: the un-reached tail still carries stale last_seen and would be evicted first, only to be
    // re-inserted as brand-new rows (history reset) by the resume pass. poll_skip is non-zero only
    // between a truncated pass and the pass that finishes the file, so the cap defers by one cycle at most.
    $max = indexMaxRows($cfg);
    // Deliberately NOT the cached count: this number decides how many rows get deleted, and pruning
    // against a figure that is thirty seconds stale would delete thirty seconds' worth of the wrong
    // rows. A pager can be approximate; a delete cannot.
    $total = (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn();
    if ($total > $max && (int)indexStateRead()['poll_skip'] === 0) {
        $excess = $total - $max;
        // Deleted in bounded passes rather than by materialising the whole excess first.
        //
        // The cap is 3 500 000 and the table has run at 2.9 M; a poll that overshoots by a wide
        // margin — or an operator lowering the cap — makes `$excess` arbitrarily large, and the old
        // shape pulled every one of those hashes into a single PHP array before deleting any of
        // them. A million 40-character hashes is ~100 MB of PHP memory inside php-fpm, whose
        // memory_limit is the thing that decides whether the prune finishes or the request dies
        // half-way through. The work is identical; only the peak is bounded.
        $left = $excess;
        while ($left > 0) {
            $batch = min(5000, $left);
            $d = $db->prepare("DELETE FROM index_hashes
                                WHERE protected_until IS NULL OR protected_until < NOW()
                                ORDER BY last_seen ASC LIMIT " . (int)$batch);
            $d->execute();
            $n = $d->rowCount();
            $res['capped'] += $n;
            $left -= $batch;
            // Nothing matched: everything left over the cap is protected, and looping would spin.
            if ($n < $batch) break;
        }
    }
    // orphaned files (index_files has no FK cascade)
    //
    // Only when THIS prune deleted something: a prune that removed no rows created no orphans, and
    // `|| $force` used to run an unbounded join-delete over 6.1 M index_files rows every 60 s for
    // as long as the table sat over its cap. In batches, because a multi-table DELETE takes no
    // LIMIT: pick up to 2 000 orphaned hashes, delete their rows, repeat while the batch was full.
    if ($res['expired'] > 0 || $res['capped'] > 0) {
        do {
            $orphans = $db->query("SELECT f.info_hash FROM index_files f LEFT JOIN index_hashes h ON h.info_hash = f.info_hash
                                    WHERE h.info_hash IS NULL LIMIT 2000")->fetchAll(PDO::FETCH_COLUMN);
            if (!$orphans) break;
            $ph = implode(',', array_fill(0, count($orphans), '?'));
            $del = $db->prepare("DELETE FROM index_files WHERE info_hash IN ($ph)");
            $del->execute($orphans);
            $res['orphan_files'] += (int)$del->rowCount();
        } while (count($orphans) === 2000);
    }
    // #11 (b): a FORCED prune that could not trim anything must not be forced again a minute
    // later — everything over the cap is protected and will stay so until the next poll changes
    // something. Stand down for the ordinary interval.
    $idle = ($force && $res['capped'] === 0 && $res['expired'] === 0) ? $now + IDX_PRUNE_EVERY : 0;
    indexStateUpdate(function (array &$s) use ($now, $res, $idle) {
        $s['last_prune_at'] = $now; $s['last_prune'] = $res;
        if ($idle) $s['force_idle_until'] = $idle;
        return true;
    });
    if ($res['expired'] > 0 || $res['capped'] > 0) { indexTotalCacheDrop(); indexStatusCacheDrop(); }
    return $res;
    } finally {
        @flock($lockH, LOCK_UN); @fclose($lockH);
    }
}

/**
 * One janitor tick: poll if due, run the metadata budget, prune (hourly). $fetcher/$tmpDir for tests.
 * Never throws. Returns a summary.
 */
function indexTick(PDO $db, array $cfg, ?callable $fetcher = null, ?int $now = null, ?string $tmpDir = null): array {
    $now = $now ?? time();
    $out = ['enabled' => indexEnabled($cfg), 'polled' => false, 'poll' => null, 'meta_queued' => 0, 'prune' => null, 'error' => null];
    if (!$out['enabled']) return $out;
    try {
        $state = indexStateRead();
        if (indexPollDue($state, $cfg, $now)) {
            $out['poll'] = indexPoll($db, $cfg, $fetcher, $now, $tmpDir);
            $out['polled'] = $out['poll']['ok'];
            if ($out['poll']['error'] !== null) $out['error'] = $out['poll']['error'];
        }
        $out['meta_queued'] = indexMetaAutoQueue($cfg) ? indexQueueMetaAuto($db) : indexQueueMetaBudget($db, $cfg, $now);
        // a big OPEN-hours poll can overshoot the cap by tens of thousands — don't wait for the hourly
        // prune, trim right away when we're more than 5 % over
        // … unless the last forced prune found nothing it could trim, in which case forcing again
        // every minute only repeats the scan (see indexPrune()).
        //
        // THE CHEAP TESTS COME FIRST, AND THAT IS THE WHOLE POINT. PHP's && runs left to right, and
        // the count on the right is an index scan of the entire catalogue: 939 ms over 3 368 887
        // rows, measured on production 2026-09-08. The old shape paid it on every tick before
        // anything had asked whether the answer could matter — including for the whole hour of the
        // stand-down this very comment describes, where the answer is thrown away by construction.
        // information_schema.INDEX_STATISTICS measured 6 737 774 rows read on idx_index_seeders in a
        // clean 60 s window: exactly two full scans a minute, all day, on a database shared with the
        // mail, the forum and the file service.
        //
        // Only two things can put this table over its cap, and both leave a mark that costs one
        // already-open file to read: a poll (the only inserter on this side) and an operator lowering
        // index_max_rows in Settings. So the count runs on the tick after one of those and not
        // otherwise — roughly twice an hour instead of sixty times.
        //
        // It stays the EXACT count. The cached total is a pager's number and this one decides whether
        // to start a prune; the rule that keeps includes/index.php honest is that nothing near a
        // DELETE reads a cache, and it is cheaper to skip the count than to approximate it.
        //
        // Federation writes rows from Python (worker/federation.py) and cannot leave a mark here. It
        // bounds its own batches, and the hourly prune enforces the cap regardless of this gate —
        // what is lost in that case is the early trim, not the trim.
        // Re-read, deliberately: $state above predates the poll that may just have run, and its
        // last_poll_at is exactly the mark being tested here.
        $post = indexStateRead();
        $maxRows = indexMaxRows($cfg);
        $mayHaveGrown = (int)$post['last_poll_at'] >= (int)$post['last_tick_at']
                     || (int)($post['max_rows_seen'] ?? 0) !== $maxRows;
        $force = $now >= (int)($post['force_idle_until'] ?? 0)
              && $mayHaveGrown
              && indexRowsCount($db) > (int)($maxRows * 1.05);
        $out['prune'] = indexPrune($db, $cfg, $now, $force);
        // max_rows_seen is remembered here and nowhere else: the tick is the only reader of the cap
        // that runs on a timer, so this is the only place that can notice it moved.
        indexStateUpdate(function (array &$s) use ($now, $maxRows) {
            $s['last_tick_at'] = $now; $s['max_rows_seen'] = $maxRows; return true;
        });
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        error_log('[index tick] ' . $e->getMessage());
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Promote / delete / status
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Promote index rows into the real whitelist (source 'admin'), carrying the name so the entry is
 * recognisable, and mark them promoted. Returns ['promoted'=>int,'summary'=>array|null,'error'=>?string].
 * The next poll drops the now-whitelisted rows from the index automatically.
 */
function indexPromote(PDO $db, array $cfg, array $hashes): array {
    $out = ['promoted' => 0, 'summary' => null, 'error' => null];
    $clean = [];
    foreach ($hashes as $h) { $h = strtolower(trim((string)$h)); if (isValidInfoHash($h)) $clean[$h] = true; }
    $clean = array_keys($clean);
    if (!$clean) { $out['error'] = __('api.index.no_valid_hashes'); return $out; }
    $in = implode(',', array_fill(0, count($clean), '?'));
    $rows = $db->prepare("SELECT info_hash, name FROM index_hashes WHERE info_hash IN ($in)");
    $rows->execute($clean);
    $items = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = ['hash' => $r['info_hash'], 'name' => $r['name'], 'input' => $r['info_hash']];
    if (!$items) { $out['error'] = __('api.index.no_matching_rows'); return $out; }
    $res = whitelistAddHashes($db, $cfg, $items, ['source' => 'admin', 'ip' => '', 'auto_meta' => true]);
    $out['summary'] = $res['summary'] ?? null;
    $upd = $db->prepare("UPDATE index_hashes SET promoted_at = NOW() WHERE info_hash IN ($in)");
    $upd->execute($clean);
    $out['promoted'] = $upd->rowCount();
    return $out;
}

/** Delete index rows (and their files) by hash. Returns rows deleted. */
function indexDelete(PDO $db, array $hashes): int {
    $clean = [];
    foreach ($hashes as $h) { $h = strtolower(trim((string)$h)); if (isValidInfoHash($h)) $clean[$h] = true; }
    $clean = array_keys($clean);
    if (!$clean) return 0;
    $n = 0;
    foreach (array_chunk($clean, 5000) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $db->prepare("DELETE FROM index_files WHERE info_hash IN ($in)")->execute($chunk);
        $d = $db->prepare("DELETE FROM index_hashes WHERE info_hash IN ($in)");
        $d->execute($chunk);
        $n += $d->rowCount();
    }
    if ($n > 0) indexTotalCacheDrop();
    return $n;
}

/** Queue metadata for specific index rows (admin "Fetch metadata" on selected/one). Returns rows queued. */
function indexRequestMeta(PDO $db, array $hashes, int $priority = 0): int {
    $clean = [];
    foreach ($hashes as $h) { $h = strtolower(trim((string)$h)); if (isValidInfoHash($h)) $clean[$h] = true; }
    $clean = array_keys($clean);
    if (!$clean) return 0;
    $in = implode(',', array_fill(0, count($clean), '?'));
    $st = $db->prepare("UPDATE index_hashes SET meta_status = 'pending', meta_priority = ?, meta_requested_at = NOW(), meta_error = NULL, meta_claim = NULL
                        WHERE info_hash IN ($in) AND meta_status NOT IN ('fetching')");
    $st->execute(array_merge([$priority], $clean));
    return $st->rowCount();
}

/**
 * Queue metadata for rows FIRST SEEN within [$from, $to] (Y-m-d H:i:s). Only never-fetched / failed rows.
 * Manual date-scoped queueing uses priority 0 (above the janitor's budget rows at -1). Returns rows queued.
 */
function indexQueueMetaByDate(PDO $db, string $from, string $to): int {
    $st = $db->prepare("UPDATE index_hashes SET meta_status = 'pending', meta_priority = 0, meta_requested_at = NOW(), meta_error = NULL, meta_claim = NULL
                        WHERE meta_status IN ('none','failed') AND first_seen >= ? AND first_seen <= ?");
    $st->execute([$from, $to]);
    return $st->rowCount();
}

/**
 * Cancel the index metadata queue ('fetching' rows finish on their own). A queued row that ALREADY
 * carries resolved metadata (a re-fetch victim — name+size present) is RESTORED to 'done' so it
 * reappears in search immediately; rows that never resolved go back to 'none'. The daily-budget
 * counter is NOT refunded. Returns ['cancelled'=>total, 'restored'=>how many went back to done].
 */
function indexMetaCancel(PDO $db): array {
    $restore = $db->prepare("UPDATE index_hashes SET meta_status = 'done', meta_requested_at = NULL, meta_priority = -1
                             WHERE meta_status = 'pending' AND name IS NOT NULL AND total_size IS NOT NULL");
    $restore->execute();
    $restored = $restore->rowCount();
    $st = $db->prepare("UPDATE index_hashes SET meta_status = 'none', meta_requested_at = NULL, meta_priority = -1 WHERE meta_status = 'pending'");
    $st->execute();
    return ['cancelled' => $restored + $st->rowCount(), 'restored' => $restored];
}

/**
 * Rebuild button: every row that HAS resolved metadata (name+size) but lost its 'done' status to a
 * bulk re-fetch / cancel goes straight back to 'done' — nothing is fetched, nothing is deleted.
 * Returns rows restored.
 */
function indexMetaRestore(PDO $db): int {
    $st = $db->prepare("UPDATE index_hashes SET meta_status = 'done', meta_requested_at = NULL, meta_priority = -1, meta_error = NULL
                        WHERE meta_status IN ('none', 'pending', 'failed') AND name IS NOT NULL AND total_size IS NOT NULL");
    $st->execute();
    return $st->rowCount();
}

/** Bulk (re)queue metadata by scope: 'missing' | 'failed' | 'missing_failed' | 'all'. Returns rows queued or null. */
function indexQueueMetaByScope(PDO $db, string $scope): ?int {
    $conds = [
        'missing'        => "meta_status = 'none'",
        'failed'         => "meta_status = 'failed'",
        'missing_failed' => "meta_status IN ('none','failed')",
        'all'            => "meta_status NOT IN ('pending','fetching')",
    ];
    if (!isset($conds[$scope])) return null;
    $st = $db->prepare("UPDATE index_hashes SET meta_status = 'pending', meta_priority = 0, meta_requested_at = NOW(), meta_error = NULL, meta_claim = NULL WHERE " . $conds[$scope]);
    $st->execute();
    return $st->rowCount();
}

// ─────────────────────────────────────────────────────────────────────────────
// On-demand seeders/leechers scrape (reuses whitelist.php's HTTP + bencode helpers)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Scrape seeders/leechers for many index rows with as few tracker requests as possible (WL_SCRAPE_BATCH
 * hashes per /scrape), writing scrape_* + scraped_at by info_hash. $rows = [['info_hash'=>hex], ...].
 * Mirrors scrapeOpenTrackerMany() but targets index_hashes. Returns the same shape.
 */
function indexScrapeMany(PDO $db, array $cfg, array $rows, float $budget = WL_SCRAPE_BULK_BUDGET): array {
    $out = ['scraped' => 0, 'requests' => 0, 'failed' => 0, 'processed' => 0, 'truncated' => false, 'last_id' => null, 'error' => null];
    $base = trim((string)($cfg['whitelist_scrape_url'] ?? ''));
    if ($base === '' || !preg_match('#^https?://#i', $base)) { $out['error'] = __('api.index.scrape_url_missing'); return $out; }
    $items = [];
    foreach ($rows as $r) { $h = strtolower((string)($r['info_hash'] ?? '')); if (isValidInfoHash($h)) $items[] = $h; }
    if (!$items) return $out;
    $deadline = microtime(true) + max(1.0, $budget);
    $sep = str_contains($base, '?') ? '&' : '?';
    $upd = $db->prepare("UPDATE index_hashes SET scrape_seeders = ?, scrape_leechers = ?, scrape_completed = ?, scraped_at = NOW() WHERE info_hash = ?");
    foreach (array_chunk($items, WL_SCRAPE_BATCH) as $batch) {
        if (microtime(true) >= $deadline) { $out['truncated'] = true; break; }
        $qs = [];
        foreach ($batch as $h) $qs[] = 'info_hash=' . rawurlencode(hex2bin($h));
        $out['requests']++; $out['processed'] += count($batch); $out['last_id'] = $batch[count($batch) - 1];
        $body = whitelistHttpGet($base . $sep . implode('&', $qs), 4);
        $files = $body !== null ? parseScrapeReply($body) : null;
        if ($files === null) {
            $out['failed']++;
            if ($out['scraped'] === 0 && $out['failed'] >= 2) { $out['error'] = __('api.index.tracker_no_answer'); break; }
            continue;
        }
        $db->beginTransaction();
        try {
            foreach ($batch as $h) {
                $f = $files[$h] ?? ['seeders' => 0, 'leechers' => 0, 'completed' => 0];
                $upd->execute([$f['seeders'], $f['leechers'], $f['completed'], $h]);
                $out['scraped']++;
            }
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    if ($out['error'] === null && $out['requests'] > 0 && $out['scraped'] === 0 && $out['failed'] === $out['requests']) $out['error'] = __('api.index.tracker_no_answer');
    return $out;
}

/** Scrape one index hash now (force). Returns ['seeders','leechers','completed','scraped_at'] or null. */
function indexScrapeOne(PDO $db, array $cfg, string $hash): ?array {
    $hash = strtolower(trim($hash));
    if (!isValidInfoHash($hash)) return null;
    $base = trim((string)($cfg['whitelist_scrape_url'] ?? ''));
    if ($base === '' || !preg_match('#^https?://#i', $base)) return null;
    $url = $base . (str_contains($base, '?') ? '&' : '?') . 'info_hash=' . rawurlencode(hex2bin($hash));
    $body = whitelistHttpGet($url, 3);
    $files = $body !== null ? parseScrapeReply($body) : null;
    if ($files === null) return null;
    $f = $files[$hash] ?? ['seeders' => 0, 'leechers' => 0, 'completed' => 0];
    $db->prepare("UPDATE index_hashes SET scrape_seeders = ?, scrape_leechers = ?, scrape_completed = ?, scraped_at = NOW() WHERE info_hash = ?")
       ->execute([$f['seeders'], $f['leechers'], $f['completed'], $hash]);
    return ['seeders' => $f['seeders'], 'leechers' => $f['leechers'], 'completed' => $f['completed'], 'scraped_at' => date('Y-m-d H:i:s')];
}

// ─────────────────────────────────────────────────────────────────────────────
// File lists — what a reply is allowed to claim about one
// ─────────────────────────────────────────────────────────────────────────────

/**
 * What the three file-list endpoints (api/index_files.php and the two admin item endpoints) may
 * tell a reader about the slice they are handing over.
 *
 * $fetched is the raw row count of a `LIMIT $limit + 1` query, $before how many rows of the same
 * list the caller already holds (the public list is paged, so it is the offset; the admin modals
 * ask once, so it is 0), and $filesCount the torrent's OWN file count as the worker recorded it.
 *
 * Two different shortfalls come back separately because they need different words and different
 * buttons:
 *   'truncated' — the probe row came back, so more rows are waiting in the table and asking again
 *                 gets them.
 *   'short'     — the stored list ENDS here and is shorter than what the torrent itself claims.
 *                 The metadata worker only ever writes the first max_files paths (worker.py:645,
 *                 5 000 on this install) while files_count keeps libtorrent's real count
 *                 (worker.py:669). Asking again changes nothing; the rest was never written.
 *
 * 'short' is precisely the case the +1 probe cannot see, and it is the reported bug: at exactly
 * max_files stored rows a `LIMIT max_files + 1` comes back full but not over, which read as
 * "nothing more to fetch", so the panel printed "Files (5000)" two lines under a heading saying
 * 27 260 files and said nothing about the difference. 620 catalogue rows on production are in that
 * state, every one of them with exactly 5 000 stored paths.
 */
function indexFilesShortfall(?int $filesCount, int $fetched, int $limit, int $before = 0): array {
    $truncated = $fetched > $limit;
    // `$before` is the caller's offset, and nothing bounds what a caller may ask for: a hand-made
    // offset past the end of a 5 000-row list would otherwise report a stored total of 6 000 for
    // rows that do not exist. The torrent's own count is the only honest ceiling on a number that
    // claims to say how much was stored.
    $stored = $before + min($fetched, $limit);
    if ($filesCount !== null && $stored > $filesCount) $stored = $filesCount;
    return [
        'truncated' => $truncated,
        'stored'    => $stored,
        'short'     => !$truncated && $filesCount !== null && $filesCount > $stored,
    ];
}

/**
 * Whether a reply must say that THIS SITE ended the list — as opposed to the reader's permission
 * (`truncated` without `can_more`) or the worker never having stored more (`short`). Called by all
 * three file-list endpoints on the page they are about to answer with, `$rows` being the page as
 * it will be sent and `$offset` how far in it starts (0 for the one-shot admin replies).
 *
 * The rule the callers must not lose is that this and `truncated` are never both true. The public
 * page's loop condition is `truncated && can_more` and its IntersectionObserver re-fires whenever
 * the sentinel is on screen, so a reply claiming both leaves an open tab asking for the same empty
 * page for ever — against a rate-limit bucket keyed by IP address, whose accounting rewrites the
 * whole of config/rate_limits.json under an exclusive lock on every single call. In the panel the
 * same pair puts a Load-all button on a list that no request can extend.
 */
function indexFilesCapped(bool $truncated, int $offset, int $rows, int $max): bool {
    return $truncated && ($offset + $rows) >= $max;
}

// ─────────────────────────────────────────────────────────────────────────────
// List query — shared by the admin list (api/admin/fetch_index.php) and the public
// search endpoint (api/index_search.php)
// ─────────────────────────────────────────────────────────────────────────────

/** Sanitise a term for BOOLEAN MODE fulltext: strip operators, require 2+ char words, suffix *. */
function indexFulltextTerm(string $term): string {
    $clean = preg_replace('/[+\-><()~"@*]/u', ' ', $term) ?? '';
    $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($words as $w) { if (mb_strlen($w) >= 2) $out[] = $w . '*'; }
    return implode(' ', $out);
}

/**
 * Filtered/sorted/paginated SELECT over index_hashes. $q keys (all optional): page, per_page,
 * sort ('col:dir,col:dir' — keys of the map below), search, search_files (bool-ish), meta, life,
 * min_seeders (int — public search may hide the dead tail). Fulltext is tried first for name
 * searches with a LIKE fallback. Returns ['rows','total','page','pages','per_page']; rows carry
 * int-cast numerics plus derived booleans `protected` and `promoted`.
 */
function indexListSelect(PDO $db, array $cfg, array $q): array {
    $page = max(1, (int)($q['page'] ?? 1));
    $perPage = max(1, min(200, (int)($q['per_page'] ?? ($cfg['items_per_page'] ?? 25))));
    $offset = ($page - 1) * $perPage;

    $allowedSorts = [
        'hash'      => 'info_hash', 'name' => 'name', 'size' => 'total_size',
        'seeders'   => 'last_seeders', 'leechers' => 'last_leechers', 'seen' => 'seen_count',
        'first'     => 'first_seen', 'last' => 'last_seen', 'meta' => 'meta_status',
        'sseeders'  => 'scrape_seeders', 'peak' => 'peak_seeders', 'files' => 'files_count',
    ];
    $orderParts = [];
    foreach (explode(',', trim((string)($q['sort'] ?? 'last:desc'))) as $part) {
        $pieces = explode(':', trim($part));
        $col = $allowedSorts[$pieces[0] ?? ''] ?? null;
        if (!$col) continue;
        $dir = (strtolower($pieces[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
        $orderParts[] = "$col $dir";
    }
    if (!$orderParts) $orderParts[] = 'last_seen DESC';
    // The tie-break takes the DIRECTION of the sort it is breaking ties for.
    //
    // InnoDB carries the primary key inside every secondary index, so `idx_index_last_seen` is really
    // (last_seen, info_hash): ordering by both in the SAME direction is a plain backward scan, and
    // mixing them is a sort no index can serve. Measured on the 2 M-row table:
    //
    //     last_seen DESC, info_hash ASC    2 327 ms   filesort
    //     last_seen DESC, info_hash DESC     112 ms   no filesort
    //
    // Determinism is what the tie-break is for and it is unchanged — the order is still total.
    $lastDir = str_ends_with(end($orderParts), 'DESC') ? 'DESC' : 'ASC';
    $orderParts[] = 'info_hash ' . $lastDir;

    $where = [];
    $params = [];
    $search = trim((string)($q['search'] ?? ''));
    $searchFiles = !empty($q['search_files']) && $q['search_files'] !== '0';
    $fulltextClause = null; $likeClause = null;
    if ($search !== '') {
        if (preg_match(INDEX_HASH_PREFIX_RE, $search)) {
            $where[] = "info_hash LIKE ?";
            $params[] = strtolower($search) . '%';
        } else {
            $likeClause = ['sql' => "name LIKE ?", 'params' => ['%' . $search . '%']];
            $ft = mb_strlen($search) >= 3 ? indexFulltextTerm($search) : '';
            // Resolve the file half into a bounded list of hashes first, exactly as the public
            // catalogue search does, and for the same reason: `name … OR info_hash IN (SELECT …)`
            // cannot be served from indexes, so MariaDB scans index_hashes end to end. That is what
            // ran for twenty-four minutes and took every php-fpm child with it.
            $fileHashes = [];
            if ($searchFiles) {
                try {
                    if ($ft !== '') {
                        $fs = $db->prepare("SELECT DISTINCT info_hash FROM index_files WHERE MATCH(path) AGAINST(? IN BOOLEAN MODE) LIMIT " . INDEX_FILE_MATCH_CAP);
                        $fs->execute([$ft]);
                    } else {
                        $fs = $db->prepare("SELECT DISTINCT info_hash FROM index_files WHERE path LIKE ? LIMIT " . INDEX_FILE_MATCH_CAP);
                        $fs->execute(['%' . $search . '%']);
                    }
                    $fileHashes = $fs->fetchAll(PDO::FETCH_COLUMN);
                    $fs->closeCursor();
                } catch (\Throwable $e) { $fileHashes = []; }
            }
            $inFiles = $fileHashes ? ('info_hash IN (' . implode(',', array_fill(0, count($fileHashes), '?')) . ')') : '';
            if ($inFiles) $likeClause = ['sql' => "(name LIKE ? OR $inFiles)", 'params' => array_merge(['%' . $search . '%'], $fileHashes)];
            if ($ft !== '') {
                $fulltextClause = ['sql' => "MATCH(name) AGAINST(? IN BOOLEAN MODE)", 'params' => [$ft]];
                if ($inFiles) $fulltextClause = ['sql' => "(MATCH(name) AGAINST(? IN BOOLEAN MODE) OR $inFiles)", 'params' => array_merge([$ft], $fileHashes)];
            }
        }
    }
    $metaFilter = (string)($q['meta'] ?? '');
    if (in_array($metaFilter, ['none', 'pending', 'fetching', 'done', 'failed'], true)) { $where[] = "meta_status = ?"; $params[] = $metaFilter; }
    $lifeFilter = (string)($q['life'] ?? '');
    if ($lifeFilter === 'protected') $where[] = "protected_until IS NOT NULL AND protected_until >= NOW()";
    elseif ($lifeFilter === 'grace')  $where[] = "meta_status <> 'done' AND grace_until >= NOW()";
    elseif ($lifeFilter === 'promoted') $where[] = "promoted_at IS NOT NULL";
    $minSeeders = (int)($q['min_seeders'] ?? 0);
    if ($minSeeders > 0) $where[] = "GREATEST(COALESCE(scrape_seeders, 0), last_seeders) >= " . $minSeeders;

    $columns = "info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers, last_completed, peak_seeders,
                grace_until, protected_until, promoted_at, meta_status, meta_error, total_size, files_count,
                scrape_seeders, scrape_leechers, scrape_completed, scraped_at";
    $orderClause = implode(', ', $orderParts);

    $runQuery = function (?array $extra) use ($db, $where, $params, $columns, $orderClause, $perPage, $offset): array {
        $w = $where; $p = $params;
        if ($extra) { $w[] = $extra['sql']; $p = array_merge($p, $extra['params']); }
        $whereClause = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        // A filtered count. The life and meta filters are a fixed vocabulary (three and five
        // values), so their counts are cached per combination for 20 s — the page re-polls every
        // 5 s while anything is pending and each of those counts is measured at over a second.
        // Free-text search is not cached: its counts are already short-circuited further down and
        // its key space is unbounded.
        $liveCount = function () use ($db, $whereClause, $p): int {
            $st = $db->prepare("SELECT COUNT(*) FROM index_hashes $whereClause");
            $st->execute($p);
            return (int)$st->fetchColumn();
        };
        if ($whereClause === '') {
            $total = indexTotalCached($db);
        } elseif (stripos($whereClause, 'LIKE') === false && stripos($whereClause, 'MATCH') === false) {
            $ck = 'listcount_' . md5($whereClause . '|' . json_encode($p));
            $total = (int)(indexStatusCached($db, $ck, fn() => ['n' => $liveCount()], 20)['n'] ?? 0);
        } else {
            $total = $liveCount();
        }
        $stmt = $db->prepare("SELECT $columns FROM index_hashes $whereClause ORDER BY $orderClause LIMIT ? OFFSET ?");
        $i = 1;
        foreach ($p as $v) $stmt->bindValue($i++, $v, PDO::PARAM_STR);
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return [$total, $stmt->fetchAll()];
    };

    $total = 0; $rows = [];
    if ($fulltextClause) {
        try { [$total, $rows] = $runQuery($fulltextClause); }
        catch (\Throwable $e) { [$total, $rows] = $runQuery($likeClause); }
    } else {
        [$total, $rows] = $runQuery($likeClause);
    }

    $now = time();
    foreach ($rows as &$row) {
        foreach (['seen_count', 'last_seeders', 'last_leechers', 'last_completed', 'peak_seeders', 'total_size', 'files_count', 'scrape_seeders', 'scrape_leechers', 'scrape_completed'] as $k) {
            $row[$k] = $row[$k] !== null ? (int)$row[$k] : null;
        }
        $row['protected'] = ($row['protected_until'] !== null && strtotime($row['protected_until']) >= $now);
        $row['promoted'] = $row['promoted_at'] !== null;
    }
    unset($row);

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)), 'per_page' => $perPage];
}

/**
 * Member-facing catalogue search (?action=search): resolved index rows, optionally UNIONed with the
 * live whitelist (whitelist.view permission — whitelisted hashes are removed from the index, so
 * without this arm they would be unfindable). Supports a real relevance sort: the fulltext BOOLEAN
 * MODE score of the name (InnoDB weighs rare/longer words higher and ignores stopwords, so a row
 * matching "2008" AND "of" ranks above one matching only "2008", which ranks above only "of").
 *
 * $q: page, per_page, sort ('relevance:desc'|'seeders:desc'|…), search, search_files (bool),
 *     include_whitelist (bool). Returns ['rows','total','page','pages','per_page']; each row:
 *     info_hash, name, total_size, files_count, seeders, leechers, last_seen, src ('index'|'whitelist').
 */
/**
 * How many hashes a file-list search may pull out of the file index before it stops looking.
 * The point is not the exact number, it is that there IS one: an unbounded file match on a
 * million-row table is what took this server off the air for twenty-four minutes.
 */
const INDEX_FILE_MATCH_CAP = 5000;

/**
 * What a search term has to look like to be treated as the START of an info hash. Six hex digits at
 * least: fewer would match too much of the table to be a lookup, and a two-letter English word
 * like "ad" or "be" is hex too. Named once so the admin listing, the catalogue and the too-short
 * guard below cannot drift apart on what counts as a hash.
 */
const INDEX_HASH_PREFIX_RE = '/^[a-f0-9]{6,40}$/i';

/**
 * Is $search too short to be searched at all?
 *
 * The catalogue uses the fulltext index only from three characters up and falls back to
 * `name LIKE '%x%'` for anything shorter — a full scan of the table, repeated by the COUNT arm.
 * One visitor typing "a" is the shape of the twenty-four-minute outage the file cap above is
 * named after, and the debounce in the browser sends exactly that on the first keystroke.
 * Empty is NOT too short: it is browsing, and the empty listing is served from an index.
 * Counted in characters, not bytes — "ąę" is two characters, whatever UTF-8 makes of it. The hash
 * clause can never be true below three characters today; it is written out so the rule stays
 * "a hash prefix is always allowed" even if the prefix floor ever moves.
 */
function indexSearchTooShort(string $search): bool {
    $search = trim($search);
    return $search !== '' && mb_strlen($search) < 3 && !preg_match(INDEX_HASH_PREFIX_RE, $search);
}

function indexSearchCatalogue(PDO $db, array $cfg, array $q): array {
    $page = max(1, (int)($q['page'] ?? 1));
    $perPage = max(1, min(100, (int)($q['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;
    $withWl = !empty($q['include_whitelist']);
    $searchFiles = !empty($q['search_files']);
    $search = trim((string)($q['search'] ?? ''));

    /**
     * Which reviewed states to show. Only the whitelist arm has a state at all — an index row is a
     * hash somebody's tracker saw, with nobody's words attached to it.
     *
     * The default hides REJECTED and nothing else. A description a moderator turned down should not
     * be the first thing a visitor reads, but the torrent behind it is still a torrent and hiding it
     * would be using a judgement about words as a judgement about a swarm.
     */
    $contentFilter = (string)($q['content'] ?? 'not_rejected');
    if (!in_array($contentFilter, ['not_rejected', 'rejected', 'approved', 'approved_or_none', 'none', 'pending'], true)) {
        $contentFilter = 'not_rejected';
    }

    // multi-column sort stack "col:dir,col:dir" (same idea as the admin tables); 'relevance'
    // ignores its direction (best first is the only sensible order)
    $sortCols = ['relevance' => 'score', 'seeders' => 'seeders', 'leechers' => 'leechers',
                 'size' => 'total_size', 'last' => 'last_seen', 'name' => 'name', 'files' => 'files_count'];
    $orderParts = [];
    foreach (explode(',', trim((string)($q['sort'] ?? 'relevance:desc'))) as $part) {
        $pieces = explode(':', trim($part));
        $col = $sortCols[$pieces[0] ?? ''] ?? null;
        if ($col === null) continue;
        if ($col === 'score') { $orderParts['score'] = 'score DESC'; continue; }
        $orderParts[$col] = $col . ((strtolower($pieces[1] ?? 'desc') === 'asc') ? ' ASC' : ' DESC');
    }
    if (!$orderParts) $orderParts = ['score' => 'score DESC', 'seeders' => 'seeders DESC'];
    elseif (count($orderParts) === 1 && isset($orderParts['score'])) $orderParts['seeders'] = 'seeders DESC';

    $isHash = $search !== '' && preg_match(INDEX_HASH_PREFIX_RE, $search);
    $ft = ($search !== '' && !$isHash && mb_strlen($search) >= 3) ? indexFulltextTerm($search) : '';

    /**
     * The ORDER BY, written so a query plan can actually serve it.
     *
     * TWO THINGS HAD TO CHANGE, and both were only visible on the real table.
     *
     * 1. `seeders` is the alias of COALESCE(scrape_seeders, last_seeders). No index can serve an
     *    ORDER BY over an expression, so the catalogue's own default first page — no search typed,
     *    the state a visitor arrives in — was a full scan and a filesort of 1.9 M rows: 2 890 ms
     *    measured on production. index_hashes now carries `eff_seeders`, a VIRTUAL column holding
     *    exactly that expression, with an index on it. Naming the column instead of the expression
     *    is what lets the optimiser walk the index and stop after a page: the same query is 1 ms.
     *    The whitelist arm keeps the alias — it is a few thousand rows and has no such column.
     *
     * 2. With no search, `score` is the literal 0. Leading an ORDER BY with a constant sorts nothing
     *    and stops the optimiser recognising the rest as an index order, so it is left out entirely
     *    rather than carried along as decoration.
     *
     * The trailing tie-break runs in the same direction as the sort for the reason the admin listing
     * documents: InnoDB carries the primary key inside every secondary index, so a tie-break the
     * other way round turns an index walk back into a filesort over the whole catalogue.
     */
    $orderFor = static function (bool $wl, bool $scored) use ($orderParts): string {
        $parts = [];
        foreach ($orderParts as $key => $frag) {
            if ($key === 'score') { if ($scored) $parts[] = $frag; continue; }
            if ($key === 'seeders' && !$wl) $frag = 'eff_' . $frag;      // eff_seeders, the indexed one
            $parts[] = $frag;
        }
        if (!$parts) $parts[] = $wl ? 'seeders DESC' : 'eff_seeders DESC';
        return implode(', ', $parts) . ', info_hash '
             . (str_ends_with((string)end($parts), 'DESC') ? 'DESC' : 'ASC');
    };
    // What the outer merge orders by when there are two arms: the aliases, which both arms expose.
    $orderMerged = static function (bool $scored) use ($orderParts): string {
        $parts = [];
        foreach ($orderParts as $key => $frag) {
            if ($key === 'score' && !$scored) continue;
            $parts[] = $frag;
        }
        if (!$parts) $parts[] = 'seeders DESC';
        return implode(', ', $parts) . ', info_hash '
             . (str_ends_with((string)end($parts), 'DESC') ? 'DESC' : 'ASC');
    };

    // ── "search inside file lists": resolve the file half FIRST, and bound it ────────────────
    //
    // This used to be one clause: `MATCH(name) AGAINST(?) OR info_hash IN (SELECT … FROM index_files
    // WHERE MATCH(path) AGAINST(?))`. MariaDB cannot serve an OR of a fulltext match and a subquery
    // from indexes — it falls back to scanning `index_hashes` end to end (2.5 million rows here) and
    // evaluating the subquery as it goes. On this server one such search ran for 24 MINUTES at 100 %
    // CPU, and because every request holds a php-fpm child and the pool has five, the whole site
    // stopped answering. Each retry started another one.
    //
    // Two cheap indexed queries instead of one impossible plan: pull the matching hashes out of the
    // file index first (its own FULLTEXT, ordered by nothing, hard LIMIT), then hand the main query a
    // literal list. The cap is the point — a search for "a" must not be allowed to drag a million
    // rows into an IN list, and a bounded answer beats an unbounded wait.
    $fileHashes = [];
    $fileIds = [];
    if ($searchFiles && $search !== '' && !$isHash) {
        $capped = false;
        try {
            if ($ft !== '') {
                $st = $db->prepare("SELECT DISTINCT info_hash FROM index_files WHERE MATCH(path) AGAINST(? IN BOOLEAN MODE) LIMIT " . (INDEX_FILE_MATCH_CAP + 1));
                $st->execute([$ft]);
            } else {
                // A LIKE with a leading wildcard cannot use an index either, so it is capped harder:
                // this branch only runs for a search too short for fulltext.
                $st = $db->prepare("SELECT DISTINCT info_hash FROM index_files WHERE path LIKE ? LIMIT " . (INDEX_FILE_MATCH_CAP + 1));
                $st->execute(['%' . $search . '%']);
            }
            $fileHashes = $st->fetchAll(PDO::FETCH_COLUMN);
            $st->closeCursor();
            if (count($fileHashes) > INDEX_FILE_MATCH_CAP) { array_pop($fileHashes); $capped = true; }

            if ($withWl) {
                if ($ft !== '') {
                    $st = $db->prepare("SELECT DISTINCT whitelist_id FROM whitelist_files WHERE MATCH(path) AGAINST(? IN BOOLEAN MODE) LIMIT " . (INDEX_FILE_MATCH_CAP + 1));
                    $st->execute([$ft]);
                } else {
                    $st = $db->prepare("SELECT DISTINCT whitelist_id FROM whitelist_files WHERE path LIKE ? LIMIT " . (INDEX_FILE_MATCH_CAP + 1));
                    $st->execute(['%' . $search . '%']);
                }
                $fileIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $st->closeCursor();
                if (count($fileIds) > INDEX_FILE_MATCH_CAP) { array_pop($fileIds); $capped = true; }
            }
        } catch (\Throwable $e) {
            // A file index that is missing or mid-rebuild must degrade to a name search, not a 500.
            $fileHashes = []; $fileIds = [];
        }
        $q['__files_capped'] = $capped;
    }

    // one arm = [select SQL, params, count SQL, params]; built for fulltext first, LIKE fallback
    $buildArm = function (bool $wl, bool $useFt) use ($search, $isHash, $ft, $searchFiles, $fileHashes, $fileIds, $contentFilter): array {
        $tbl = $wl ? 'whitelist' : 'index_hashes';
        // votes_up/votes_down/score_x100 are kept ON the row by repRecount(). Aggregating them per
        // result would be fifty extra queries for one page, which is the shape of mistake this file
        // has already made once with a listing over a large table.
        $cols = $wl
            ? "info_hash, name, total_size, files_count, COALESCE(scrape_seeders, 0) AS seeders, COALESCE(scrape_leechers, 0) AS leechers,
               COALESCE(scraped_at, updated_at, created_at) AS last_seen, votes_up, votes_down, votes_count, score_x100,
               content_status, 'whitelist' AS src"
            : "info_hash, name, total_size, files_count, COALESCE(scrape_seeders, last_seeders) AS seeders, COALESCE(scrape_leechers, last_leechers) AS leechers,
               last_seen, votes_up, votes_down, votes_count, score_x100, 'none' AS content_status, 'index' AS src";
        // a NAMED row is searchable regardless of the queue state — a bulk re-fetch flips done →
        // pending without touching the stored metadata, and thousands of rows must not vanish from
        // the search until the worker gets around to re-resolving them
        $where = $wl
            ? ["banned = 0", "(meta_status = 'done' OR (name IS NOT NULL AND name <> ''))"]
            : ["(meta_status = 'done' OR (name IS NOT NULL AND name <> ''))"];
        if ($wl) {
            // Literal fragments chosen by a key; nothing from the request reaches the SQL.
            $contentSql = [
                'not_rejected'     => "content_status <> 'rejected'",
                'rejected'         => "content_status = 'rejected'",
                'approved'         => "content_status = 'approved'",
                'approved_or_none' => "content_status IN ('approved','none')",
                // 'pending' and 'none' used to be one bucket called "Unreviewed only", which made the
                // review queue invisible from search: a moderator could not ask "what is waiting?"
                // and got rows nobody had written about mixed in with rows waiting for them.
                'pending'          => "content_status = 'pending'",
                'none'             => "content_status = 'none'",
            ];
            $where[] = $contentSql[$contentFilter];
        } elseif ($contentFilter === 'rejected' || $contentFilter === 'approved' || $contentFilter === 'pending') {
            // An index row has no reviewed state, so a filter asking for one must exclude the whole
            // arm rather than quietly returning rows that cannot match.
            $where[] = '1 = 0';
        }
        $params = [];
        $scoreSql = '0';
        $scoreParams = [];
        if ($search !== '') {
            if ($isHash) {
                $where[] = "info_hash LIKE ?";
                $params[] = strtolower($search) . '%';
            } elseif ($useFt && $ft !== '') {
                $scoreSql = "MATCH(name) AGAINST(? IN BOOLEAN MODE)";
                $scoreParams = [$ft];
                $match = "MATCH(name) AGAINST(? IN BOOLEAN MODE)";
                // The file half was already resolved to a bounded list of keys above, so this is a
                // primary-key lookup rather than a correlated subquery the optimiser cannot index.
                $keys = $wl ? $fileIds : $fileHashes;
                if ($searchFiles && $keys) {
                    $in = implode(',', array_fill(0, count($keys), '?'));
                    $col = $wl ? 'id' : 'info_hash';
                    $where[] = "($match OR $col IN ($in))";
                    $params[] = $ft;
                    foreach ($keys as $k) $params[] = $k;
                } else {
                    $where[] = $match;
                    $params[] = $ft;
                }
            } else {
                $like = "name LIKE ?";
                $keys = $wl ? $fileIds : $fileHashes;
                if ($searchFiles && $keys) {
                    $in = implode(',', array_fill(0, count($keys), '?'));
                    $col = $wl ? 'id' : 'info_hash';
                    $where[] = "($like OR $col IN ($in))";
                    $params[] = '%' . $search . '%';
                    foreach ($keys as $k) $params[] = $k;
                } else {
                    $where[] = $like;
                    $params[] = '%' . $search . '%';
                }
            }
        }
        $w = 'WHERE ' . implode(' AND ', $where);
        return [
            "SELECT $cols, $scoreSql AS score FROM `$tbl` $w", array_merge($scoreParams, $params),
            "SELECT COUNT(*) FROM `$tbl` $w", $params,
        ];
    };

    $run = function (bool $useFt) use ($db, $buildArm, $withWl, $orderFor, $orderMerged, $perPage, $offset, $search, $isHash, $ft, $searchFiles, $contentFilter): array {
        // Whether the relevance column is a real score or the literal 0 — see $orderFor.
        $scored = $search !== '' && !$isHash && ($useFt && $ft !== '');
        $arms = [$buildArm(false, $useFt)];
        if ($withWl) {
            // a hash can sit in BOTH tables between polls — prefer the whitelist row
            $arms[0][0] .= " AND info_hash NOT IN (SELECT info_hash FROM whitelist WHERE banned = 0)";
            $arms[0][2] .= " AND info_hash NOT IN (SELECT info_hash FROM whitelist WHERE banned = 0)";
            $arms[] = $buildArm(true, $useFt);
        }
        $countArms = static function () use ($db, $arms, $withWl, $search, $searchFiles, $contentFilter): int {
        $total = 0;
        foreach ($arms as $k => $a) {
            // The unfiltered count is "how many rows are listable at all" — a number that moves with
            // the index poll, not with the reader. Counting it on every page view cost 1 102 ms of
            // full scan for an answer that was the same as a minute ago.
            $count = static function () use ($db, $a): array {
                $c = $db->prepare($a[2]); $c->execute($a[3]);
                return ['n' => (int)$c->fetchColumn()];
            };
            if ($search === '' && !$searchFiles) {
                // The key has to carry everything that changes the answer, or one reader's filter
                // becomes another reader's total.
                $key = 'cat_total_' . $k . ($withWl ? '_wl' : '') . '_' . $contentFilter;
                $total += (int)(indexStatusCached($db, $key, $count, 120)['n'] ?? 0);
            } else {
                $total += (int)($count()['n'] ?? 0);
            }
        }
        return $total;
        };

        if (count($arms) === 1) {
            // ONE arm: no derived table. Wrapping a single SELECT in `SELECT * FROM (…) cat` forces
            // the whole result into a temporary table before the ORDER BY can look at it, which on
            // the empty search meant materialising 184 000 rows to return 25 of them.
            $sql = $arms[0][0] . ' ORDER BY ' . $orderFor(false, $scored) . ' LIMIT ? OFFSET ?';
            $params = $arms[0][1];
        } else {
            // TWO arms: the merge needs a derived table, but each arm can be ordered and cut short
            // FIRST — no arm can contribute a row past position offset+perPage to the merged page,
            // so nothing beyond that has to be materialised or sorted.
            $cut = $offset + $perPage;
            $sql = '(' . $arms[0][0] . ' ORDER BY ' . $orderFor(false, $scored) . ' LIMIT ' . (int)$cut . ')'
                 . ' UNION ALL '
                 . '(' . $arms[1][0] . ' ORDER BY ' . $orderFor(true, $scored) . ' LIMIT ' . (int)$cut . ')';
            $sql = "SELECT * FROM ($sql) cat ORDER BY " . $orderMerged($scored) . ' LIMIT ? OFFSET ?';
            $params = array_merge($arms[0][1], $arms[1][1]);
        }
        $st = $db->prepare($sql);
        $i = 1;
        foreach ($params as $v) $st->bindValue($i++, $v, PDO::PARAM_STR);
        $st->bindValue($i++, $perPage, PDO::PARAM_INT);
        $st->bindValue($i, $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // COUNT LAST, AND ONLY WHEN THE ANSWER IS NOT ALREADY IN FRONT OF US.
        //
        // A first page that came back short means every arm was exhausted, so the number of rows IS
        // the total — there is nothing left to count. That is the shape of most searches, and on this
        // table the COUNT(*) it replaces is a second full-text pass costing about as much as the
        // search itself (1 951 ms measured for a query that matched seventy rows).
        $total = ($offset === 0 && count($rows) < $perPage) ? count($rows) : $countArms();
        return [$total, $rows];
    };

    try { [$total, $rows] = $run(true); }
    catch (\Throwable $e) { [$total, $rows] = $run(false); }   // fulltext index missing → LIKE

    foreach ($rows as &$r) {
        foreach (['total_size', 'files_count', 'seeders', 'leechers'] as $k) $r[$k] = $r[$k] !== null ? (int)$r[$k] : null;
        unset($r['score']);
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)), 'per_page' => $perPage];
}

/** Row counts + state for the admin status card / CLI. */
function indexStatus(PDO $db, array $cfg): array {
    $counts = indexStatusCached($db, 'counts', function () use ($db) {
        $c = ['total' => 0, 'in_grace' => 0, 'protected' => 0, 'promoted' => 0, 'meta_none' => 0,
              'meta_pending' => 0, 'meta_fetching' => 0, 'meta_done' => 0, 'meta_failed' => 0, 'files' => 0];
        try {
            $c['total'] = indexTotalCached($db);
            $c['in_grace'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status <> 'done' AND grace_until >= NOW()")->fetchColumn();
            $c['protected'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE protected_until IS NOT NULL AND protected_until >= NOW()")->fetchColumn();
            $c['promoted'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE promoted_at IS NOT NULL")->fetchColumn();
            foreach ($db->query("SELECT meta_status, COUNT(*) c FROM index_hashes GROUP BY meta_status") as $r) {
                $k = 'meta_' . $r['meta_status']; if (isset($c[$k])) $c[$k] = (int)$r['c'];
            }
            $c['files'] = (int)$db->query("SELECT COUNT(*) FROM index_files")->fetchColumn();
        } catch (\Throwable $e) {}
        return $c;
    });
    // WHY THE TABLE SHRINKS, answered on the page instead of left to be inferred.
    //
    // grace_until is set when a row is first inserted and is never extended: a hash that does not get
    // its metadata resolved within index_grace_days is dropped. That is the designed lifecycle, but
    // with a queue of millions and a worker resolving tens of thousands a day, the two numbers can be
    // wildly mismatched — and the only visible symptom is a total that falls for days. So put both
    // rates next to each other: what is about to expire, and what is actually being resolved.
    $flow = indexStatusCached($db, 'flow', function () use ($db, $counts) {
      $flow = ['expiring_24h' => 0, 'resolved_24h' => 0, 'days_to_cover' => null];
      try {
        $flow['expiring_24h'] = (int)$db->query(
            "SELECT COUNT(*) FROM index_hashes
              WHERE meta_status <> 'done' AND grace_until IS NOT NULL
                AND grace_until < NOW() + INTERVAL 1 DAY")->fetchColumn();
        $flow['resolved_24h'] = (int)$db->query(
            "SELECT COUNT(*) FROM index_hashes
              WHERE meta_status = 'done' AND meta_fetched_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
        // At the current rate, how long a full pass over the queue would take. This is the number
        // that has to be compared against the grace window, and nothing else on the page shows it.
        $queued = $counts['meta_pending'] + $counts['meta_fetching'];
        if ($flow['resolved_24h'] > 0 && $queued > 0) {
            $flow['days_to_cover'] = (int)ceil($queued / $flow['resolved_24h']);
        }
      } catch (\Throwable $e) {}
      return $flow;
    });

    // WHAT THE WORKER IS RUNNING, which is a different fact from what Settings was told to ask for.
    //
    // The panel already learned this once, for the parallel-fetch setting: without asking the
    // worker, a page can only read the operator's own number back at them, which is how "set 32,
    // gets 4" survived for a day. The concurrency and fetch-order versions of that check live in
    // whitelistStatus() — INSIDE its `if ($mode === 'whitelist')` branch, so on an open-mode
    // tracker, the only mode where index_files exists at all, none of them render. So the file cap
    // answers for itself on the page it belongs to.
    //
    // A heartbeat that carries no max_files key is a worker started from a worker.py older than
    // 1.38.0: it ignores this setting completely and nothing else anywhere would say so.
    $askFiles = indexMetaMaxFiles($cfg);
    $worker = ['heartbeat_age' => null, 'max_files' => null, 'max_files_config' => null,
               'max_files_max' => null, 'max_files_asked' => $askFiles, 'max_files_state' => 'ok'];
    if (function_exists('whitelistWorkerHeartbeat')) {
        $hbw = whitelistWorkerHeartbeat($cfg);
        $worker['heartbeat_age'] = $hbw['age'];
        $wi = is_array($hbw['info']) ? $hbw['info'] : null;
        if ($wi !== null) {
            foreach (['max_files', 'max_files_config', 'max_files_max'] as $k) {
                if (isset($wi[$k]) && is_numeric($wi[$k])) $worker[$k] = (int)$wi[$k];
            }
            if ($askFiles !== null) {
                if ($worker['max_files'] === null) {
                    $worker['max_files_state'] = 'unsupported';
                } elseif ($worker['max_files'] !== $askFiles) {
                    // Over this build's ceiling is a different sentence from plain disagreement: the
                    // worker clamped on purpose and the operator is owed the reason, not a warning
                    // that reads as a fault.
                    $worker['max_files_state'] = ($worker['max_files_max'] !== null && $askFiles > $worker['max_files_max'])
                        ? 'over_max' : 'mismatch';
                }
            }
        }
    }

    return [
        'flow' => $flow, 'worker' => $worker,
        'enabled' => indexEnabled($cfg), 'source_url' => indexSourceUrl($cfg), 'poll_minutes' => indexPollMinutes($cfg),
        'min_seeders' => indexMinSeeders($cfg), 'max_rows' => indexMaxRows($cfg), 'grace_days' => indexGraceDays($cfg),
        'protect_days' => indexProtectDays($cfg), 'meta_daily_budget' => indexMetaDailyBudget($cfg), 'meta_auto_queue' => indexMetaAutoQueue($cfg), 'poll_budget' => indexPollBudget($cfg),
        'counts' => $counts, 'state' => indexStateRead(),
    ];
}
