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
function indexPollMinutes(array $cfg): int { return max(5, min(1440, (int)($cfg['index_poll_minutes'] ?? 30) ?: 30)); }
function indexMinSeeders(array $cfg): int { return max(0, min(100000, (int)($cfg['index_min_seeders'] ?? 1))); }
function indexMaxRows(array $cfg): int { return max(10, min(5000000, (int)($cfg['index_max_rows'] ?? 200000) ?: 200000)); }
function indexGraceDays(array $cfg): int { return max(1, min(90, (int)($cfg['index_grace_days'] ?? 3) ?: 3)); }
function indexProtectDays(array $cfg): int { return max(1, min(365, (int)($cfg['index_protect_days'] ?? 10) ?: 10)); }
function indexMetaDailyBudget(array $cfg): int { return max(0, min(1000000, (int)($cfg['index_meta_daily_budget'] ?? 500))); }
function indexKeepFiles(array $cfg): bool { return (($cfg['index_keep_files'] ?? '1') === '1'); }
function indexPollBudget(array $cfg): int { return max(5, min(120, (int)($cfg['index_poll_budget'] ?? 45) ?: 45)); }

function indexRowsCount(PDO $db): int {
    try { return (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn(); } catch (\Throwable $e) { return 0; }
}

// ─────────────────────────────────────────────────────────────────────────────
// State file (config/index_state.json)
// ─────────────────────────────────────────────────────────────────────────────

function indexStateFile(): string { return __DIR__ . '/../config/index_state.json'; }
function indexStateLockFile(): string { return __DIR__ . '/../config/index_state.lock'; }

function indexStateDefaults(): array {
    return [
        'last_poll_at' => 0, 'last_poll' => null, 'last_error' => null, 'last_error_at' => 0,
        'meta_budget_day' => '', 'meta_budget_used' => 0,
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
    if ($code !== 200) { @unlink($tmp); $out['error'] = 'HTTP ' . $code; return $out; }
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
 * Stops early once $deadline (microtime) passes. Returns ['entries'=>int,'kept'=>int,'truncated'=>bool].
 */
function indexParseScrapeFile(string $file, bool $gzip, int $minSeeders, callable $onBatch, float $deadline): array {
    $out = ['entries' => 0, 'kept' => 0, 'truncated' => false];
    $fh = $gzip ? @gzopen($file, 'rb') : @fopen($file, 'rb');
    if (!$fh) { $out['error'] = 'cannot open scrape file'; return $out; }
    $read = $gzip ? 'gzread' : 'fread';
    $eof = $gzip ? 'gzeof' : 'feof';
    $close = $gzip ? 'gzclose' : 'fclose';
    $carry = '';
    $batch = [];
    try {
        while (!$eof($fh)) {
            $chunk = $read($fh, 1 << 20);
            if ($chunk === false || $chunk === '') break;
            $buf = $carry . $chunk;
            $last = 0;
            if (preg_match_all(IDX_ENTRY_RE, $buf, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($mm as $e) {
                    $out['entries']++;
                    $c = (int)$e[2][0];
                    if ($c >= $minSeeders) {
                        $batch[] = [bin2hex($e[1][0]), $c, (int)$e[4][0], (int)$e[3][0]];
                        if (count($batch) >= IDX_BATCH) { $onBatch($batch); $out['kept'] += count($batch); $batch = []; }
                    }
                    $last = $e[0][1] + strlen($e[0][0]);
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
    $tmpDir = $tmpDir ?? sys_get_temp_dir();
    $graceDays = indexGraceDays($cfg); $protectDays = indexProtectDays($cfg); $minSeeders = indexMinSeeders($cfg);
    $ownFile = false;
    if ($fetcher !== null) {
        $f = $fetcher();
        $file = $f['file'] ?? null; $gzip = (bool)($f['gzip'] ?? false); $out['bytes'] = (int)($f['bytes'] ?? (($file && is_file($file)) ? filesize($file) : 0));
        if (!$file || !is_file($file)) { $out['error'] = $f['error'] ?? 'fetch failed'; }
    } else {
        $fetched = indexFetchFullScrape(indexSourceUrl($cfg), min(90, max(5, indexPollBudget($cfg))), $tmpDir);
        $file = $fetched['file']; $gzip = $fetched['gzip']; $out['bytes'] = $fetched['bytes']; $out['ms'] = $fetched['ms'];
        $ownFile = $file !== null;
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
        $p = indexParseScrapeFile($file, $gzip, $minSeeders, $onBatch, $deadline);
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
    indexStateUpdate(function (array &$s) use ($out, $now) {
        $s['last_poll_at'] = $now;
        $s['last_poll'] = ['at' => $now, 'entries' => $out['entries'], 'kept' => $out['kept'], 'truncated' => $out['truncated'],
                           'removed_wl' => $out['removed_wl'], 'removed_ban' => $out['removed_ban'], 'bytes' => $out['bytes'], 'ms' => $out['ms']];
        if ($out['error'] !== null) { $s['last_error'] = $out['error']; $s['last_error_at'] = $now; }
        elseif ($out['ok']) { $s['last_error'] = null; }
        return true;
    });
    return $out;
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
 * Hourly pruner: drop expired rows (grace elapsed without metadata, or protection elapsed for done rows),
 * cap the table at index_max_rows (oldest last_seen without protection), and clean orphaned index_files.
 * Returns ['expired'=>int,'capped'=>int,'orphan_files'=>int] or null when throttled.
 */
function indexPrune(PDO $db, array $cfg, ?int $now = null, bool $force = false): ?array {
    $now = $now ?? time();
    $st = indexStateRead();
    if (!$force && $now - (int)$st['last_prune_at'] < IDX_PRUNE_EVERY) return null;
    $res = ['expired' => 0, 'capped' => 0, 'orphan_files' => 0];
    // expired: never-resolved past grace, or done past protection
    $res['expired'] = (int)$db->exec(
        "DELETE FROM index_hashes WHERE
            (meta_status <> 'done' AND grace_until IS NOT NULL AND grace_until < NOW())
         OR (meta_status  = 'done' AND protected_until IS NOT NULL AND protected_until < NOW())");
    // cap: delete oldest unprotected rows over the limit
    $max = indexMaxRows($cfg);
    $total = (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn();
    if ($total > $max) {
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
    return $res;
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
        $out['meta_queued'] = indexQueueMetaBudget($db, $cfg, $now);
        $out['prune'] = indexPrune($db, $cfg, $now);
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

/** Row counts + state for the admin status card / CLI. */
function indexStatus(PDO $db, array $cfg): array {
    $counts = ['total' => 0, 'in_grace' => 0, 'protected' => 0, 'promoted' => 0, 'meta_none' => 0, 'meta_pending' => 0, 'meta_fetching' => 0, 'meta_done' => 0, 'meta_failed' => 0, 'files' => 0];
    try {
        $counts['total'] = (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn();
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
        'protect_days' => indexProtectDays($cfg), 'meta_daily_budget' => indexMetaDailyBudget($cfg), 'poll_budget' => indexPollBudget($cfg),
        'counts' => $counts, 'state' => indexStateRead(),
    ];
}
