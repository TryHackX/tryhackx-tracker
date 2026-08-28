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

const IDX_BATCH            = 2000;    // rows per upsert (batch size barely affects throughput; ~18k rows/s)
const IDX_ENTRY_RE         = '/20:(.{20})d8:completei(\d+)e10:downloadedi(\d+)e10:incompletei(\d+)ee/s';
const IDX_PRUNE_EVERY      = 3600;    // seconds between prune runs
const IDX_FETCH_MAX_BYTES  = 268435456; // 256 MB hard cap on the downloaded scrape (safety)

function indexEnabled(array $cfg): bool { return (($cfg['index_enabled'] ?? '0') === '1'); }
function indexSourceUrl(array $cfg): string { return trim((string)($cfg['index_source_url'] ?? 'http://127.0.0.1:6969/scrape')); }
/**
 * COUNT(*) over the whole catalogue, remembered for thirty seconds.
 *
 * This is the ONE query on the Index page worth caching, and it took measuring to know that. The
 * listing itself ran at 1 747 ms until v19 added the composite index; it is 0.8 ms now, and a cache
 * in front of THAT would have been pure liability — staleness bought with nothing. The count is a
 * different animal: InnoDB keeps no row counter, so an unfiltered COUNT(*) walks an index over 2.7
 * million rows every time, 557 ms measured, and no index changes that.
 *
 * What it draws is a pager. A pager does not need an exact number, it needs one that is right to the
 * page — and thirty seconds of drift on a table that gains rows in half-hourly batches cannot be
 * seen. Filtered counts deliberately do NOT come through here: the filters are indexed and cheap,
 * and there are enough distinct ones that a cache would miss more often than it hit.
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
        'meta_budget_day' => '', 'meta_budget_used' => 0, 'poll_skip' => 0,
        'last_prune_at' => 0, 'last_prune' => null, 'last_tick_at' => 0,
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
    $out = ['file' => null, 'gzip' => false, 'bytes' => 0, 'ms' => 0, 'error' => null];
    if ($url === '' || !preg_match('#^https?://#i', $url)) { $out['error'] = 'Invalid source URL'; return $out; }
    if (!function_exists('curl_init')) { $out['error'] = 'curl is required for the full scrape'; return $out; }
    $tmp = rtrim($tmpDir, '/\\') . '/index_scrape_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.bin';
    $fh = @fopen($tmp, 'wb');
    if (!$fh) { $out['error'] = 'Cannot open temp file'; return $out; }
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
    curl_close($ch);
    fclose($fh);
    $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
    if ($tooBig) { @unlink($tmp); $out['error'] = 'Full scrape exceeds ' . IDX_FETCH_MAX_BYTES . ' bytes'; return $out; }
    if ($ok === false) { @unlink($tmp); $out['error'] = 'cURL error: ' . $err; return $out; }
    if ($code !== 200) {
        @unlink($tmp);
        // 402 is not a payment and not our firewall: it is opentracker's own refusal of a FULL
        // scrape asked for too soon after the last one. It clears itself on the next poll, and a
        // bare "HTTP 402" sends whoever reads it hunting through rate limits that have nothing to
        // do with it -- the throttle on this machine is UDP-only and this request is HTTP.
        $out['error'] = $code === 402
            ? 'HTTP 402 — the tracker refused a full scrape, which is how it rate-limits them. '
            . 'Nothing is wrong: the next poll picks it up.'
            : 'HTTP ' . $code;
        return $out;
    }
    $out['bytes'] = (int)@filesize($tmp);
    if ($out['bytes'] < 9) { @unlink($tmp); $out['error'] = 'Empty scrape reply'; return $out; }
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
    if (!$fh) { $out['error'] = 'cannot open scrape file'; return $out; }
    $read = $gzip ? 'gzread' : 'fread';
    $eof = $gzip ? 'gzeof' : 'feof';
    $close = $gzip ? 'gzclose' : 'fclose';
    $carry = '';
    $batch = [];
    $first = true;
    try {
        while (!$eof($fh)) {
            $chunk = $read($fh, 1 << 20);
            if ($chunk === false || $chunk === '') break;
            $buf = $carry . $chunk;
            if ($first) {
                $first = false;
                // a real scrape reply starts with d5:filesd… — reject an HTML error page / wrong endpoint
                if (strncmp($buf, 'd5:files', 8) !== 0) { $out['error'] = 'source did not look like a scrape reply'; break; }
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
 * Upsert one batch of scrape rows into index_hashes. A 'done' row that still has >= 1 seeder gets its
 * protection extended. $rows = [[hash, seeders, leechers, completed], ...].
 */
function indexUpsertBatch(PDO $db, array $rows, int $graceDays, int $protectDays): void {
    if (!$rows) return;
    $ph = rtrim(str_repeat('(?, NOW(), NOW(), 1, ?, ?, ?, ?, NOW() + INTERVAL ' . $graceDays . ' DAY),', count($rows)), ',');
    $sql = "INSERT INTO index_hashes (info_hash, first_seen, last_seen, seen_count, last_seeders, last_leechers, last_completed, peak_seeders, grace_until)
            VALUES $ph
            ON DUPLICATE KEY UPDATE
                last_seen = NOW(), seen_count = seen_count + 1,
                last_seeders = VALUES(last_seeders), last_leechers = VALUES(last_leechers), last_completed = VALUES(last_completed),
                peak_seeders = GREATEST(peak_seeders, VALUES(last_seeders)),
                protected_until = IF(meta_status = 'done' AND VALUES(last_seeders) >= 1,
                                     GREATEST(COALESCE(protected_until, NOW()), NOW() + INTERVAL " . $protectDays . " DAY),
                                     protected_until)";
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
    $out = ['ok' => false, 'entries' => 0, 'kept' => 0, 'truncated' => false, 'removed_wl' => 0, 'removed_ban' => 0, 'bytes' => 0, 'ms' => 0, 'error' => null];
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
        $skip = max(0, (int)indexStateRead()['poll_skip']);   // resume cursor from the previous truncated pass
        $ownFile = false;
        if ($fetcher !== null) {
            $f = $fetcher();
            $file = $f['file'] ?? null; $gzip = (bool)($f['gzip'] ?? false); $out['bytes'] = (int)($f['bytes'] ?? (($file && is_file($file)) ? filesize($file) : 0));
            if (!$file || !is_file($file)) { $out['error'] = $f['error'] ?? 'fetch failed'; }
        } else {
            $fetched = indexFetchFullScrape(indexSourceUrl($cfg), min(90, max(5, indexPollBudget($cfg))), $tmpDir);
            $file = $fetched['file']; $gzip = $fetched['gzip']; $out['bytes'] = $fetched['bytes']; $out['ms'] = $fetched['ms'];
            $ownFile = $file !== null;
            // a fatal (execution-time limit, OOM) skips finally blocks — make sure the temp scrape
            // is removed at shutdown regardless (unlink of an already-removed file is a no-op)
            if ($ownFile) register_shutdown_function(static function () use ($file) { @unlink($file); });
            if ($fetched['error']) $out['error'] = $fetched['error'];
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
            $out['entries'] = $p['entries']; $out['kept'] = $p['kept']; $out['truncated'] = $p['truncated'];
            if (isset($p['error'])) $out['error'] = $p['error'];
            // drop anything that lives in the whitelist or the ban list — those have their own tables
            $out['removed_wl'] = (int)$db->exec("DELETE i FROM index_hashes i JOIN whitelist w ON w.info_hash = i.info_hash");
            $out['removed_ban'] = (int)$db->exec("DELETE i FROM index_hashes i JOIN banned_hashes b ON b.info_hash = i.info_hash");
            $out['ok'] = $out['error'] === null;
        } catch (\Throwable $e) {
            $out['error'] = 'poll: ' . $e->getMessage();
            error_log('[index poll] ' . $e->getMessage());
        } finally {
            if ($ownFile) @unlink($file);
        }
        $out['ms'] = $out['ms'] + (int)round((microtime(true) - $t0) * 1000);
        $skipNext = ($out['ok'] && $out['truncated']) ? $out['entries'] : 0;   // resume at the tail next time; reset once the whole file was covered
        indexStateUpdate(function (array &$s) use ($out, $now, $skipNext) {
            $s['last_poll_at'] = $now;
            $s['poll_skip'] = $skipNext;
            $s['last_poll'] = ['at' => $now, 'entries' => $out['entries'], 'kept' => $out['kept'], 'truncated' => $out['truncated'],
                               'removed_wl' => $out['removed_wl'], 'removed_ban' => $out['removed_ban'], 'bytes' => $out['bytes'], 'ms' => $out['ms']];
            if ($out['error'] !== null) { $s['last_error'] = $out['error']; $s['last_error_at'] = $now; }
            elseif ($out['ok']) { $s['last_error'] = null; }
            return true;
        });
        // The row count has just changed, probably by a lot. Anything that shows a total must ask
        // again rather than answering from a cache filled before the poll ran.
        indexTotalCacheDrop();
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
        $ids = $db->query("SELECT info_hash FROM index_hashes WHERE protected_until IS NULL OR protected_until < NOW()
                           ORDER BY last_seen ASC LIMIT " . (int)$excess)->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($ids, 5000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $d = $db->prepare("DELETE FROM index_hashes WHERE info_hash IN ($in)");
            $d->execute($chunk);
            $res['capped'] += $d->rowCount();
        }
    }
    // orphaned files (index_files has no FK cascade)
    if (($res['expired'] > 0 || $res['capped'] > 0) || $force) {
        $res['orphan_files'] = (int)$db->exec("DELETE f FROM index_files f LEFT JOIN index_hashes h ON h.info_hash = f.info_hash WHERE h.info_hash IS NULL");
    }
    indexStateUpdate(function (array &$s) use ($now, $res) { $s['last_prune_at'] = $now; $s['last_prune'] = $res; return true; });
    if ($res['expired'] > 0 || $res['capped'] > 0) indexTotalCacheDrop();
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
        $force = indexRowsCount($db) > (int)(indexMaxRows($cfg) * 1.05);
        $out['prune'] = indexPrune($db, $cfg, $now, $force);
        indexStateUpdate(function (array &$s) use ($now) { $s['last_tick_at'] = $now; return true; });
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
    if (!$clean) { $out['error'] = 'No valid hashes'; return $out; }
    $in = implode(',', array_fill(0, count($clean), '?'));
    $rows = $db->prepare("SELECT info_hash, name FROM index_hashes WHERE info_hash IN ($in)");
    $rows->execute($clean);
    $items = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = ['hash' => $r['info_hash'], 'name' => $r['name'], 'input' => $r['info_hash']];
    if (!$items) { $out['error'] = 'No matching index rows'; return $out; }
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
    if ($base === '' || !preg_match('#^https?://#i', $base)) { $out['error'] = 'Scrape URL is not configured'; return $out; }
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
            if ($out['scraped'] === 0 && $out['failed'] >= 2) { $out['error'] = 'Tracker did not answer'; break; }
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
    if ($out['error'] === null && $out['requests'] > 0 && $out['scraped'] === 0 && $out['failed'] === $out['requests']) $out['error'] = 'Tracker did not answer';
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
    $orderParts[] = 'info_hash ASC'; // deterministic tie-break

    $where = [];
    $params = [];
    $search = trim((string)($q['search'] ?? ''));
    $searchFiles = !empty($q['search_files']) && $q['search_files'] !== '0';
    $fulltextClause = null; $likeClause = null;
    if ($search !== '') {
        if (preg_match('/^[a-f0-9]{6,40}$/i', $search)) {
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
        $total = $whereClause === '' ? indexTotalCached($db)
                                     : (function () use ($db, $whereClause, $p): int {
                                           $st = $db->prepare("SELECT COUNT(*) FROM index_hashes $whereClause");
                                           $st->execute($p);
                                           return (int)$st->fetchColumn();
                                       })();
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
    if (!in_array($contentFilter, ['not_rejected', 'rejected', 'approved', 'approved_or_none', 'none'], true)) {
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
    $order = implode(', ', $orderParts) . ", info_hash ASC";

    $isHash = $search !== '' && preg_match('/^[a-f0-9]{6,40}$/i', $search);
    $ft = ($search !== '' && !$isHash && mb_strlen($search) >= 3) ? indexFulltextTerm($search) : '';

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
                'none'             => "content_status IN ('none','pending')",
            ];
            $where[] = $contentSql[$contentFilter];
        } elseif ($contentFilter === 'rejected' || $contentFilter === 'approved') {
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

    $run = function (bool $useFt) use ($db, $buildArm, $withWl, $order, $perPage, $offset): array {
        $arms = [$buildArm(false, $useFt)];
        if ($withWl) {
            // a hash can sit in BOTH tables between polls — prefer the whitelist row
            $arms[0][0] .= " AND info_hash NOT IN (SELECT info_hash FROM whitelist WHERE banned = 0)";
            $arms[0][2] .= " AND info_hash NOT IN (SELECT info_hash FROM whitelist WHERE banned = 0)";
            $arms[] = $buildArm(true, $useFt);
        }
        $total = 0;
        foreach ($arms as $a) {
            $c = $db->prepare($a[2]);
            $c->execute($a[3]);
            $total += (int)$c->fetchColumn();
        }
        $sql = count($arms) === 1 ? $arms[0][0] : '(' . $arms[0][0] . ') UNION ALL (' . $arms[1][0] . ')';
        $sql = "SELECT * FROM ($sql) cat ORDER BY $order LIMIT ? OFFSET ?";
        $params = $arms[0][1];
        if (count($arms) === 2) $params = array_merge($params, $arms[1][1]);
        $st = $db->prepare($sql);
        $i = 1;
        foreach ($params as $v) $st->bindValue($i++, $v, PDO::PARAM_STR);
        $st->bindValue($i++, $perPage, PDO::PARAM_INT);
        $st->bindValue($i, $offset, PDO::PARAM_INT);
        $st->execute();
        return [$total, $st->fetchAll(PDO::FETCH_ASSOC)];
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
    $counts = ['total' => 0, 'in_grace' => 0, 'protected' => 0, 'promoted' => 0, 'meta_none' => 0, 'meta_pending' => 0, 'meta_fetching' => 0, 'meta_done' => 0, 'meta_failed' => 0, 'files' => 0];
    try {
        $counts['total'] = indexTotalCached($db);
        $counts['in_grace'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status <> 'done' AND grace_until >= NOW()")->fetchColumn();
        $counts['protected'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE protected_until IS NOT NULL AND protected_until >= NOW()")->fetchColumn();
        $counts['promoted'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE promoted_at IS NOT NULL")->fetchColumn();
        foreach ($db->query("SELECT meta_status, COUNT(*) c FROM index_hashes GROUP BY meta_status") as $r) {
            $k = 'meta_' . $r['meta_status']; if (isset($counts[$k])) $counts[$k] = (int)$r['c'];
        }
        $counts['files'] = (int)$db->query("SELECT COUNT(*) FROM index_files")->fetchColumn();
    } catch (\Throwable $e) {}
    return [
        'enabled' => indexEnabled($cfg), 'source_url' => indexSourceUrl($cfg), 'poll_minutes' => indexPollMinutes($cfg),
        'min_seeders' => indexMinSeeders($cfg), 'max_rows' => indexMaxRows($cfg), 'grace_days' => indexGraceDays($cfg),
        'protect_days' => indexProtectDays($cfg), 'meta_daily_budget' => indexMetaDailyBudget($cfg), 'meta_auto_queue' => indexMetaAutoQueue($cfg), 'poll_budget' => indexPollBudget($cfg),
        'counts' => $counts, 'state' => indexStateRead(),
    ];
}
