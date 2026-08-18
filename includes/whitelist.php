<?php
/**
 * Whitelist service.
 *
 * Design (see README "Whitelist mode"):
 *  - The `whitelist` table is the source of truth. The OpenTracker accesslist file is a DERIVED
 *    artefact: regenerated atomically (write temp file in the same directory, chmod, rename) and
 *    appended for plain additions. PHP never parses the whole file on a request.
 *  - OpenTracker WHITE mode is fail-closed: an empty/unreadable file at start rejects every
 *    announce, so we refuse to write an empty file and surface the condition in the panel.
 *  - Reloads (SIGHUP via `systemctl reload`) are debounced: OpenTracker keeps superseded list
 *    generations for 5 minutes (20 B/entry each), so we coalesce. Additions are lazy (>= N seconds
 *    between reloads), removals/bans are urgent (short coalescing window + a cap per 5 minutes).
 *  - Appends and regenerations are serialised on one lock file (LOCK_SH for append, LOCK_EX for
 *    regen) so an append can never be lost under a concurrent regeneration.
 *  - All state lives in config/whitelist_state.json (web-denied dir), read-modify-written under
 *    LOCK_EX. No settings-table churn on every add.
 *
 * Canonical hash form everywhere: lowercase 40-hex. Base32 is decoded on input.
 */

const WL_REGEN_EVERY_APPENDS   = 2000;   // full regeneration after this many appends (bounds file drift)
const WL_RELOAD_URGENT_INTERVAL = 15;    // seconds between "urgent" reloads (removals/bans)
const WL_RELOAD_MAX_PER_5MIN   = 12;     // hard cap of SIGHUPs per 5-minute window (memory: old generations)
const WL_RELOAD_FAIL_BACKOFF   = [45, 90, 180, 300]; // seconds after 1st, 2nd, 3rd, 4th+ consecutive failure
const WL_SCRAPE_TTL            = 60;     // seconds to cache seeders/leechers from OpenTracker
const WL_TMP_MAX_AGE           = 3600;   // stale *.tmp.* files older than this are removed by the janitor
const WL_REGEN_LOCK_TIMEOUT    = 10;     // seconds to wait for the regen lock before reporting busy
const WL_META_AUTO_SOURCES     = ['api', 'forum', 'admin']; // sources whose hashes get metadata fetched automatically

// ─────────────────────────────────────────────────────────────────────────────
// Small helpers
// ─────────────────────────────────────────────────────────────────────────────

/** Rate-limit / ban key for an IP: IPv4 = exact address, IPv6 = its /64 prefix (SLAAC hosts rotate inside a /64). */
function ipBucket(string $ip): string {
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            $prefix = substr($bin, 0, 8) . str_repeat("\0", 8);
            $txt = @inet_ntop($prefix);
            if ($txt !== false) return $txt . '/64';
        }
    }
    return $ip;
}

function trackerMode(array $cfg): string {
    return (($cfg['tracker_mode'] ?? 'blacklist') === 'whitelist') ? 'whitelist' : 'blacklist';
}

function whitelistPath(array $cfg): string {
    return normalizeListPath((string)($cfg['whitelist_path'] ?? ''));
}

/** Strip NUL/control characters from a configured list-file path (shared by black/whitelist). */
function normalizeListPath(string $path): string {
    return preg_replace('/[\x00-\x1F\x7F]/', '', trim($path));
}

/**
 * The whitelist file is REPLACED by rename() as the web user, so unlike the append-only blacklist a
 * misconfigured path could clobber files the web user owns. Only accept an absolute path outside the
 * application/document root whose target is not a symlink and does not look like web-executable.
 * Returns ['ok'=>bool,'error'=>?string].
 */
function validateWhitelistPath(string $path): array {
    $path = normalizeListPath($path);
    if ($path === '') return ['ok' => false, 'error' => 'Whitelist path is empty.'];
    $isWindows = PHP_OS_FAMILY === 'Windows';
    if (!$isWindows && $path[0] !== '/') return ['ok' => false, 'error' => 'Whitelist path must be absolute.'];
    $base = strtolower(basename($path));
    if (preg_match('/\.(php\d*|phtml|phar|htaccess|htpasswd)$/i', $base) || $base === '.htaccess') {
        return ['ok' => false, 'error' => 'Refusing a whitelist path that looks like a web-executable or Apache config file.'];
    }
    $dir = dirname($path);
    $realDir = @realpath($dir);
    if ($realDir === false) return ['ok' => false, 'error' => "Directory does not exist: $dir"];
    $appRoot = @realpath(__DIR__ . '/..');
    $docRoot = @realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    foreach (array_filter([$appRoot, $docRoot]) as $root) {
        $root = rtrim($root, '/\\');
        if ($root !== '' && ($realDir === $root || str_starts_with($realDir . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR))) {
            return ['ok' => false, 'error' => 'Whitelist path must be outside the web application directory (' . $root . ').'];
        }
    }
    if (is_link($path)) return ['ok' => false, 'error' => 'Whitelist path must not be a symlink.'];
    return ['ok' => true, 'error' => null];
}

/**
 * Permission diagnostics for a list file. For the whitelist the DIRECTORY must be writable (temp
 * file + rename) and the file, if present, readable; the file itself need not be writable (a
 * non-writable file simply forces a regeneration, which takes ownership). For the blacklist the
 * legacy in-place semantics apply (file writable or dir writable when absent).
 * Returns ['ok','errors','suggestions'] — the same shape the JS already renders.
 */
function checkListFilePermissions(string $path, string $label = 'whitelist', bool $needDirWritable = true): array {
    $result = ['ok' => false, 'errors' => [], 'suggestions' => []];
    $path = normalizeListPath($path);
    $isWindows = PHP_OS_FAMILY === 'Windows';
    if ($path === '') {
        $result['errors'][] = ucfirst($label) . ' path is not configured.';
        $result['suggestions'][] = 'Set the ' . $label . ' file path in Admin > Settings.';
        return $result;
    }
    if ($label === 'whitelist') {
        $v = validateWhitelistPath($path);
        if (!$v['ok']) { $result['errors'][] = $v['error']; return $result; }
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        $result['errors'][] = "Directory does not exist: $dir";
        $result['suggestions'][] = $isWindows
            ? 'Create the directory manually or choose a path under your WAMP www folder.'
            : "Create it: sudo install -d -o tracker -g www-data -m 2770 $dir";
        return $result;
    }
    $phpUser = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
    if (file_exists($path)) {
        if (!is_readable($path)) {
            $result['errors'][] = ucfirst($label) . " file exists but is not readable: $path";
            $result['suggestions'][] = $isWindows ? 'Grant the Apache/WAMP user Read permission on the file.' : "Fix permissions: sudo chmod 644 $path";
        }
        if (!$needDirWritable && !is_writable($path)) {
            $result['errors'][] = ucfirst($label) . " file exists but is not writable: $path";
            $result['suggestions'][] = $isWindows ? 'Grant the Apache/WAMP user Write permission on the file.' : "Fix permissions: sudo chmod 664 $path && sudo chgrp $phpUser $path";
        }
        if (is_link($path)) {
            $result['errors'][] = "$path is a symlink — refusing to replace it.";
        }
    } elseif (!$needDirWritable && !is_writable($dir)) {
        $result['errors'][] = "Directory is not writable (cannot create $label file): $dir";
        $result['suggestions'][] = $isWindows ? 'Grant the Apache/WAMP user Write permission on the folder.' : "Fix directory permissions: sudo chown $phpUser:$phpUser $dir && sudo chmod 775 $dir";
    }
    if ($needDirWritable && !is_writable($dir)) {
        $result['errors'][] = "Directory is not writable by the web user ($phpUser) — the $label is written as a temp file and renamed, so the directory must be writable: $dir";
        $result['suggestions'][] = $isWindows
            ? 'Grant the Apache/WAMP user Write permission on the folder.'
            : "Recommended layout: sudo install -d -o tracker -g $phpUser -m 2770 $dir   (setgid dir; the tracker user reads, PHP writes)";
    }
    if (empty($result['errors'])) {
        $result['ok'] = true;
        if (!file_exists($path)) {
            $result['suggestions'][] = 'File does not exist yet — it will be created by "Regenerate file" (or on the first addition).';
        }
    }
    return $result;
}

/** Keep the old name working for the blacklist code paths. */
if (!function_exists('checkBlacklistPermissionsLegacy')) {
    function checkBlacklistPermissionsLegacy(string $path): array { return checkListFilePermissions($path, 'blacklist', false); }
}

// ─────────────────────────────────────────────────────────────────────────────
// State file (config/whitelist_state.json)
// ─────────────────────────────────────────────────────────────────────────────

function whitelistStateFile(): string { return __DIR__ . '/../config/whitelist_state.json'; }
function whitelistRegenLockFile(): string { return __DIR__ . '/../config/whitelist_regen.lock'; }
function whitelistStateLockFile(): string { return __DIR__ . '/../config/whitelist_state.lock'; }

function whitelistStateDefaults(): array {
    return [
        'generated_at' => 0, 'count' => 0, 'bytes' => 0, 'appended_since_regen' => 0,
        'regen_needed' => false, 'regen_needed_since' => 0, 'last_error' => null, 'last_error_at' => 0,
        'pending_reload' => false, 'urgent' => false, 'dirty_since' => 0,
        'last_reload_at' => 0, 'last_reload_ok' => null, 'last_reload_attempt_at' => 0,
        'last_reload_output' => '', 'fail_count' => 0, 'reloads' => [],
    ];
}

function whitelistStateRead(): array {
    $f = whitelistStateFile();
    $data = [];
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return array_merge(whitelistStateDefaults(), is_array($data) ? $data : []);
}

/**
 * Atomic read-modify-write of the state file. $fn receives the current state array by reference
 * and may return false to abort without writing. Returns the (possibly updated) state.
 */
function whitelistStateUpdate(callable $fn): array {
    $lockH = @fopen(whitelistStateLockFile(), 'c');
    if ($lockH) @flock($lockH, LOCK_EX);
    try {
        $state = whitelistStateRead();
        $r = $fn($state);
        if ($r !== false) {
            $tmp = whitelistStateFile() . '.tmp.' . getmypid();
            @file_put_contents($tmp, json_encode($state), LOCK_EX);
            @rename($tmp, whitelistStateFile());
        }
        return $state;
    } finally {
        if ($lockH) { @flock($lockH, LOCK_UN); @fclose($lockH); }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// File generation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Acquire the regeneration lock. Exclusive for a regen, shared for an append. Waits up to
 * $timeout seconds (0 = non-blocking). Returns the handle (keep it until done) or null.
 */
function whitelistRegenLock(bool $exclusive, int $timeout = WL_REGEN_LOCK_TIMEOUT) {
    $h = @fopen(whitelistRegenLockFile(), 'c');
    if (!$h) return null;
    $flag = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;
    $deadline = microtime(true) + $timeout;
    while (true) {
        if (@flock($h, $flag)) return $h;
        if (microtime(true) >= $deadline) { @fclose($h); return null; }
        usleep(100000);
    }
}

function whitelistRegenUnlock($h): void {
    if ($h) { @flock($h, LOCK_UN); @fclose($h); }
}

/**
 * Rewrite the whitelist file from the database, atomically. Refuses to install an EMPTY file
 * (OpenTracker WHITE mode would then reject everything). Returns
 * ['ok'=>bool,'count'=>int,'bytes'=>int,'ms'=>int,'error'=>?string,'busy'=>bool].
 */
function whitelistRegenerate(PDO $db, array $cfg): array {
    $t0 = microtime(true);
    $path = whitelistPath($cfg);
    $perm = checkListFilePermissions($path, 'whitelist', true);
    if (!$perm['ok']) {
        $err = implode(' ', $perm['errors']);
        whitelistStateUpdate(function (&$s) use ($err) { $s['regen_needed'] = true; if (!$s['regen_needed_since']) $s['regen_needed_since'] = time(); $s['last_error'] = $err; $s['last_error_at'] = time(); });
        return ['ok' => false, 'count' => 0, 'bytes' => 0, 'ms' => 0, 'error' => $err, 'busy' => false, 'errors' => $perm['errors'], 'suggestions' => $perm['suggestions']];
    }
    $lock = whitelistRegenLock(true);
    if (!$lock) {
        whitelistStateUpdate(function (&$s) { $s['regen_needed'] = true; if (!$s['regen_needed_since']) $s['regen_needed_since'] = time(); });
        return ['ok' => false, 'count' => 0, 'bytes' => 0, 'ms' => 0, 'error' => 'busy', 'busy' => true];
    }
    $tmp = $path . '.tmp.' . getmypid();
    $fh = null;
    try {
        $fh = @fopen($tmp, 'wb');
        if (!$fh) {
            $err = 'Cannot create temp file in ' . dirname($path);
            whitelistStateUpdate(function (&$s) use ($err) { $s['regen_needed'] = true; $s['last_error'] = $err; $s['last_error_at'] = time(); });
            return ['ok' => false, 'count' => 0, 'bytes' => 0, 'ms' => 0, 'error' => $err, 'busy' => false];
        }
        // Keyset pagination keeps memory flat and avoids unbuffered-query pitfalls on the shared PDO.
        $count = 0; $bytes = 0; $last = '';
        $stmt = $db->prepare("SELECT info_hash FROM whitelist WHERE banned = 0 AND info_hash > ? ORDER BY info_hash LIMIT 50000");
        while (true) {
            $stmt->execute([$last]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$rows) break;
            $buf = '';
            foreach ($rows as $h) {
                $h = strtolower($h);
                if (strlen($h) !== 40) continue;
                $buf .= $h . "\n";
                $count++;
                if (strlen($buf) >= 65536) { $bytes += fwrite($fh, $buf); $buf = ''; }
            }
            if ($buf !== '') $bytes += fwrite($fh, $buf);
            $last = end($rows);
            if (count($rows) < 50000) break;
        }
        fflush($fh);
        fclose($fh); $fh = null;
        if ($count === 0) {
            @unlink($tmp);
            $err = 'Refusing to write an EMPTY whitelist — OpenTracker in whitelist mode would reject every announce. Add at least one hash first.';
            whitelistStateUpdate(function (&$s) use ($err) { $s['regen_needed'] = true; if (!$s['regen_needed_since']) $s['regen_needed_since'] = time(); $s['last_error'] = $err; $s['last_error_at'] = time(); });
            return ['ok' => false, 'count' => 0, 'bytes' => 0, 'ms' => (int)((microtime(true) - $t0) * 1000), 'error' => $err, 'busy' => false];
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $err = 'rename() to ' . $path . ' failed (directory not writable or target is protected).';
            whitelistStateUpdate(function (&$s) use ($err) { $s['regen_needed'] = true; if (!$s['regen_needed_since']) $s['regen_needed_since'] = time(); $s['last_error'] = $err; $s['last_error_at'] = time(); });
            return ['ok' => false, 'count' => $count, 'bytes' => $bytes, 'ms' => (int)((microtime(true) - $t0) * 1000), 'error' => $err, 'busy' => false];
        }
        $ms = (int)((microtime(true) - $t0) * 1000);
        whitelistStateUpdate(function (&$s) use ($count, $bytes) {
            $s['generated_at'] = time(); $s['count'] = $count; $s['bytes'] = $bytes;
            $s['appended_since_regen'] = 0; $s['regen_needed'] = false; $s['regen_needed_since'] = 0;
            $s['last_error'] = null;
        });
        return ['ok' => true, 'count' => $count, 'bytes' => $bytes, 'ms' => $ms, 'error' => null, 'busy' => false];
    } finally {
        if ($fh) { @fclose($fh); @unlink($tmp); }
        whitelistRegenUnlock($lock);
    }
}

/**
 * Fast path for additions: append lines under a SHARED regen lock (so a concurrent regeneration
 * cannot swallow them). Falls back to a full regeneration when the file is missing, empty or not
 * writable by the web user. Returns ['ok'=>bool,'mode'=>'append'|'regen'|'skipped','error'=>?string].
 */
function whitelistAppendHashes(PDO $db, array $cfg, array $hashes): array {
    $hashes = array_values(array_unique(array_filter(array_map('strtolower', $hashes), 'isValidInfoHash')));
    if (!$hashes) return ['ok' => true, 'mode' => 'skipped', 'error' => null];
    $path = whitelistPath($cfg);
    if ($path === '') return ['ok' => false, 'mode' => 'skipped', 'error' => 'Whitelist path is not configured.'];
    $needRegen = !is_file($path) || @filesize($path) === 0 || !is_writable($path);
    if (!$needRegen) {
        $lock = whitelistRegenLock(false, 5);
        if ($lock) {
            try {
                $fh = @fopen($path, 'ab');
                if ($fh) {
                    @flock($fh, LOCK_EX);
                    $ok = fwrite($fh, implode("\n", $hashes) . "\n") !== false;
                    fflush($fh); @flock($fh, LOCK_UN); fclose($fh);
                    if ($ok) {
                        $n = count($hashes);
                        $state = whitelistStateUpdate(function (&$s) use ($n) {
                            $s['appended_since_regen'] = (int)$s['appended_since_regen'] + $n;
                            $s['count'] = (int)$s['count'] + $n;
                            if ($s['appended_since_regen'] >= WL_REGEN_EVERY_APPENDS) { $s['regen_needed'] = true; if (!$s['regen_needed_since']) $s['regen_needed_since'] = time(); }
                        });
                        whitelistRegenUnlock($lock); $lock = null;
                        if (!empty($state['regen_needed'])) whitelistRegenerate($db, $cfg);
                        return ['ok' => true, 'mode' => 'append', 'error' => null];
                    }
                }
            } finally {
                if ($lock) whitelistRegenUnlock($lock);
            }
        }
    }
    $r = whitelistRegenerate($db, $cfg);
    return ['ok' => $r['ok'], 'mode' => 'regen', 'error' => $r['error']];
}

// ─────────────────────────────────────────────────────────────────────────────
// Reload (SIGHUP) policy
// ─────────────────────────────────────────────────────────────────────────────

/** Mark the tracker's in-memory list as stale. Urgent = a hash was REMOVED/banned (still served until reload). */
function whitelistMarkDirty(bool $urgent = false): void {
    whitelistStateUpdate(function (&$s) use ($urgent) {
        $s['pending_reload'] = true;
        if ($urgent) $s['urgent'] = true;
        if (!$s['dirty_since']) $s['dirty_since'] = time();
    });
}

/** Seconds until the next lazy reload may fire (0 = now). Used to tell public submitters when they become active. */
function whitelistSecondsToNextReload(array $cfg): int {
    $s = whitelistStateRead();
    $min = max(10, (int)($cfg['whitelist_reload_min_interval'] ?? 45));
    $next = (int)$s['last_reload_at'] + $min;
    return max(0, $next - time());
}

/**
 * Fire `systemctl reload` if policy allows. Returns null when nothing was attempted, otherwise
 * ['attempted'=>true,'ok'=>bool,'output'=>string]. Never throws.
 */
function whitelistMaybeReload(array $cfg, bool $force = false): ?array {
    if (($cfg['opentracker_auto_reload'] ?? '1') !== '1' && !$force) return null;
    $service = trim((string)($cfg['opentracker_service_name'] ?? ''));
    if ($service === '' || !isServiceNameValid($service) || !trackerExecAvailable()) return null;

    $now = time();
    $min = max(10, (int)($cfg['whitelist_reload_min_interval'] ?? 45));
    $go = false;
    whitelistStateUpdate(function (&$s) use (&$go, $now, $min, $force) {
        if (!$force && !$s['pending_reload']) return false;
        // prune reload history to the last 5 minutes
        $s['reloads'] = array_values(array_filter((array)$s['reloads'], fn($t) => ($now - (int)$t) < 300));
        if (!$force) {
            // failure backoff
            if ($s['fail_count'] > 0) {
                $idx = min((int)$s['fail_count'], count(WL_RELOAD_FAIL_BACKOFF)) - 1;
                if (($now - (int)$s['last_reload_attempt_at']) < WL_RELOAD_FAIL_BACKOFF[$idx]) return false;
            }
            $since = $now - (int)$s['last_reload_at'];
            if ($s['urgent']) {
                if ($since < WL_RELOAD_URGENT_INTERVAL) return false;
                if (count($s['reloads']) >= WL_RELOAD_MAX_PER_5MIN) return false;
            } else {
                if ($since < $min) return false;
            }
        }
        $go = true;
        $s['last_reload_attempt_at'] = $now;
        return true;
    });
    if (!$go) return null;

    $res = runTrackerServiceCommand('reload', $cfg);
    whitelistNoteReloaded($res['ok'], $res['output']);
    if ($res['ok'] && function_exists('resetBlacklistChanges')) resetBlacklistChanges();
    return ['attempted' => true, 'ok' => $res['ok'], 'output' => $res['output']];
}

/** Record the outcome of a reload/restart (called by the panel endpoints too). */
function whitelistNoteReloaded(bool $ok, string $output = ''): void {
    whitelistStateUpdate(function (&$s) use ($ok, $output) {
        $now = time();
        $s['last_reload_attempt_at'] = $now;
        $s['last_reload_ok'] = $ok;
        $s['last_reload_output'] = mb_substr($output, 0, 500);
        if ($ok) {
            $s['last_reload_at'] = $now;
            $s['pending_reload'] = false; $s['urgent'] = false; $s['dirty_since'] = 0; $s['fail_count'] = 0;
            $r = array_values(array_filter((array)$s['reloads'], fn($t) => ($now - (int)$t) < 300));
            $r[] = $now; $s['reloads'] = $r;
        } else {
            $s['fail_count'] = (int)$s['fail_count'] + 1;
        }
    });
}

/**
 * Per-request janitor: fire due reloads/regens, clean stale temp files. Cheap when idle (one small
 * JSON read). Safe to call on every request including pollers. Also called by tools/janitor.php
 * from a systemd timer so pending work completes even without web traffic.
 */
function whitelistJanitor(PDO $db, array $cfg): void {
    if (trackerMode($cfg) !== 'whitelist') return;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $s = whitelistStateRead();
        if (!empty($s['regen_needed'])) {
            $r = whitelistRegenerate($db, $cfg);
            if ($r['ok']) whitelistMarkDirty(true);
            $s = whitelistStateRead();
        }
        if (!empty($s['pending_reload'])) whitelistMaybeReload($cfg);
        // stale temp files (a fatal between fopen and rename)
        $path = whitelistPath($cfg);
        if ($path !== '' && is_dir(dirname($path)) && mt_rand(1, 20) === 1) {
            foreach ((array)@glob($path . '.tmp.*') as $tmp) {
                if (@filemtime($tmp) < time() - WL_TMP_MAX_AGE) @unlink($tmp);
            }
        }
        // expired API bans retention (rarely)
        if (mt_rand(1, 50) === 1) {
            try { $db->exec("DELETE FROM api_bans WHERE expires_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 500"); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        error_log('[whitelist janitor] ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Parsing
// ─────────────────────────────────────────────────────────────────────────────

/** Clean a display name (from dn= or metadata): decode, strip control chars, collapse whitespace, cap length. */
function cleanTorrentName(?string $name): ?string {
    if ($name === null) return null;
    $name = str_replace('+', ' ', $name);
    $name = rawurldecode($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') return null;
    if (!mb_check_encoding($name, 'UTF-8')) $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
    return mb_substr($name, 0, 255);
}

/**
 * Parse one token: a magnet link (hex or base32 btih) or a bare 40-hex / 32-base32 hash.
 * Returns ['input','hash'=>?string,'name'=>?string,'magnet'=>?string,'error'=>?string].
 */
function parseMagnetOrHash(string $token): array {
    $token = trim($token);
    $out = ['input' => mb_substr($token, 0, 2048), 'hash' => null, 'name' => null, 'magnet' => null, 'error' => null];
    if ($token === '') { $out['error'] = 'empty'; return $out; }
    if (preg_match('/^magnet:\?/i', $token)) {
        if (!preg_match('/[?&]xt=urn:btih:([a-fA-F0-9]{40}|[a-zA-Z2-7]{32})(?![a-zA-Z0-9])/i', $token, $m)) {
            $out['error'] = 'Magnet link has no valid btih info hash';
            return $out;
        }
        $raw = $m[1];
        $hash = strlen($raw) === 40 ? strtolower($raw) : (base32ToHex(strtoupper($raw)) ?? null);
        if (!$hash || !isValidInfoHash($hash)) { $out['error'] = 'Invalid base32 info hash'; return $out; }
        $out['hash'] = strtolower($hash);
        if (preg_match('/[?&]dn=([^&]+)/i', $token, $dn)) $out['name'] = cleanTorrentName($dn[1]);
        $out['magnet'] = mb_substr($token, 0, 2048);
        return $out;
    }
    if (preg_match('/^(?:urn:btih:)?([a-fA-F0-9]{40})$/i', $token, $m)) { $out['hash'] = strtolower($m[1]); return $out; }
    if (preg_match('/^(?:urn:btih:)?([a-zA-Z2-7]{32})$/i', $token, $m)) {
        $hash = base32ToHex(strtoupper($m[1]));
        if ($hash && isValidInfoHash($hash)) { $out['hash'] = strtolower($hash); return $out; }
        $out['error'] = 'Invalid base32 info hash'; return $out;
    }
    $out['error'] = 'Not a magnet link or 40-hex info hash';
    return $out;
}

/**
 * Parse free text (one magnet/hash per line, commas/whitespace also accepted between magnets).
 * Deduplicates by hash (first occurrence wins). Returns ['items'=>[...], 'too_many'=>bool].
 */
function parseHashInput(string $text, int $max): array {
    $text = str_replace(["\r\n", "\r", "\xEF\xBB\xBF"], ["\n", "\n", ''], $text); // normalise EOL, drop UTF-8 BOMs
    // split on newlines and on whitespace/commas that separate tokens; magnets contain no whitespace
    $tokens = preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $items = []; $seen = []; $tooMany = false;
    foreach ($tokens as $tok) {
        if (count($items) >= $max) { $tooMany = true; break; }
        $p = parseMagnetOrHash($tok);
        if ($p['hash'] !== null) {
            if (isset($seen[$p['hash']])) continue;
            $seen[$p['hash']] = true;
        }
        $items[] = $p;
    }
    return ['items' => $items, 'too_many' => $tooMany];
}

/** Build a magnet link for a hash using this tracker's configured announce URLs. */
function buildMagnet(string $hash, ?string $name, array $cfg): string {
    $m = 'magnet:?xt=urn:btih:' . strtolower($hash);
    if ($name !== null && $name !== '') $m .= '&dn=' . rawurlencode($name);
    foreach (['announce_url', 'announce_url_https'] as $k) {
        $u = trim((string)($cfg[$k] ?? ''));
        if ($u !== '' && preg_match('#^(udp|https?|wss?)://#i', $u)) $m .= '&tr=' . rawurlencode($u);
    }
    return $m;
}

// ─────────────────────────────────────────────────────────────────────────────
// Database operations
// ─────────────────────────────────────────────────────────────────────────────

function isHashBanned(PDO $db, string $hash): bool {
    $st = $db->prepare("SELECT 1 FROM banned_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([strtolower($hash)]);
    return (bool)$st->fetchColumn();
}

function isHashWhitelisted(PDO $db, string $hash): ?array {
    $st = $db->prepare("SELECT id, info_hash, name, source, banned, created_at FROM whitelist WHERE info_hash = ? LIMIT 1");
    $st->execute([strtolower($hash)]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * Shared "add hashes" for the public form, the S2S API and the admin panel.
 * $items = parseHashInput()['items']; $ctx = ['source'=>web|api|admin|forum, 'ip'=>string,
 * 'api_client_id'=>?int, 'ref'=>?array, 'auto_meta'=>?bool].
 * Returns ['results'=>[{input,hash,status,error}], 'summary'=>{added,exists,banned,invalid},
 *          'added_hashes'=>[], 'file'=>{ok,mode,error}, 'reload'=>?array, 'active_in_seconds'=>int].
 */
function whitelistAddHashes(PDO $db, array $cfg, array $items, array $ctx): array {
    $source = in_array($ctx['source'] ?? '', ['web', 'api', 'admin', 'forum'], true) ? $ctx['source'] : 'web';
    $ip = (string)($ctx['ip'] ?? '');
    $bucket = $ip !== '' ? ipBucket($ip) : '';
    $apiClientId = isset($ctx['api_client_id']) ? (int)$ctx['api_client_id'] : null;
    $ref = isset($ctx['ref']) && is_array($ctx['ref']) ? json_encode($ctx['ref'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
    if ($ref !== null && strlen($ref) > 512) $ref = mb_strcut($ref, 0, 512);
    $autoMeta = $ctx['auto_meta'] ?? in_array($source, WL_META_AUTO_SOURCES, true);

    $results = []; $summary = ['added' => 0, 'exists' => 0, 'banned' => 0, 'invalid' => 0];
    $valid = [];
    foreach ($items as $i => $it) {
        if (empty($it['hash'])) {
            $results[$i] = ['index' => $i, 'input' => $it['input'] ?? '', 'hash' => null, 'status' => 'invalid', 'error' => $it['error'] ?? 'invalid'];
            $summary['invalid']++;
        } else {
            $valid[$i] = $it;
        }
    }
    $addedHashes = [];
    if ($valid) {
        $hashes = array_values(array_unique(array_map(fn($it) => $it['hash'], $valid)));
        $ph = implode(',', array_fill(0, count($hashes), '?'));
        $banned = [];
        $st = $db->prepare("SELECT info_hash FROM banned_hashes WHERE info_hash IN ($ph)");
        $st->execute($hashes);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) $banned[$h] = true;
        $existing = [];
        $st = $db->prepare("SELECT info_hash, banned FROM whitelist WHERE info_hash IN ($ph)");
        $st->execute($hashes);
        foreach ($st->fetchAll() as $row) $existing[$row['info_hash']] = (int)$row['banned'];

        $ins = $db->prepare("INSERT IGNORE INTO whitelist (info_hash, name, magnet_link, source, source_ref, api_client_id, ip, ip_bucket, meta_status, meta_requested_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($valid as $i => $it) {
            $h = $it['hash'];
            if (isset($banned[$h]) || (isset($existing[$h]) && $existing[$h] === 1)) {
                $results[$i] = ['index' => $i, 'input' => $it['input'], 'hash' => $h, 'status' => 'banned', 'error' => 'This info hash is banned on this tracker'];
                $summary['banned']++;
                continue;
            }
            if (isset($existing[$h])) {
                $results[$i] = ['index' => $i, 'input' => $it['input'], 'hash' => $h, 'status' => 'exists', 'error' => null];
                $summary['exists']++;
                continue;
            }
            $ins->execute([$h, $it['name'] ?? null, $it['magnet'] ?? null, $source, $ref, $apiClientId, $ip, $bucket,
                           $autoMeta ? 'pending' : 'none', $autoMeta ? date('Y-m-d H:i:s') : null]);
            if ($ins->rowCount() > 0) {
                $results[$i] = ['index' => $i, 'input' => $it['input'], 'hash' => $h, 'status' => 'added', 'error' => null];
                $summary['added']++;
                $addedHashes[] = $h;
                $existing[$h] = 0; // duplicates later in the same batch → exists
            } else {
                $results[$i] = ['index' => $i, 'input' => $it['input'], 'hash' => $h, 'status' => 'exists', 'error' => null];
                $summary['exists']++;
            }
        }
    }
    ksort($results);
    $file = ['ok' => true, 'mode' => 'skipped', 'error' => null];
    $reload = null;
    if ($addedHashes && trackerMode($cfg) === 'whitelist') {
        $file = whitelistAppendHashes($db, $cfg, $addedHashes);
        whitelistMarkDirty(false);
        $reload = whitelistMaybeReload($cfg);
    }
    return [
        'results' => array_values($results),
        'summary' => $summary,
        'added_hashes' => $addedHashes,
        'file' => $file,
        'reload' => $reload,
        'active_in_seconds' => whitelistSecondsToNextReload($cfg),
    ];
}

/** Hard-delete rows (by id). Regenerates the file and requests an urgent reload. Returns rows removed. */
function whitelistRemove(PDO $db, array $cfg, array $ids): int {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM whitelist_files WHERE whitelist_id IN ($ph)")->execute($ids);
    $st = $db->prepare("DELETE FROM whitelist WHERE id IN ($ph)");
    $st->execute($ids);
    $n = $st->rowCount();
    if ($n > 0 && trackerMode($cfg) === 'whitelist') {
        $r = whitelistRegenerate($db, $cfg);
        whitelistMarkDirty(true);
        whitelistMaybeReload($cfg);
    }
    return $n;
}

/**
 * Ban hashes: upsert banned_hashes + flag whitelist rows. Regenerates + urgent reload when an active
 * row was affected. $ctx = ['reason','source'=>report|appeal|admin|import,'source_id'].
 * Returns ['banned'=>int (new ban rows), 'affected'=>int (active whitelist rows flagged)].
 */
function whitelistBan(PDO $db, array $cfg, array $hashes, array $ctx = []): array {
    $hashes = array_values(array_unique(array_filter(array_map('strtolower', $hashes), 'isValidInfoHash')));
    if (!$hashes) return ['banned' => 0, 'affected' => 0];
    $reason = mb_substr((string)($ctx['reason'] ?? ''), 0, 255) ?: null;
    $src = in_array($ctx['source'] ?? '', ['report', 'appeal', 'admin', 'import'], true) ? $ctx['source'] : 'admin';
    $srcId = isset($ctx['source_id']) ? (int)$ctx['source_id'] : null;
    $ins = $db->prepare("INSERT INTO banned_hashes (info_hash, reason, source, source_id) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE reason = COALESCE(VALUES(reason), reason), source = VALUES(source), source_id = VALUES(source_id)");
    $newBans = 0;
    foreach ($hashes as $h) { $ins->execute([$h, $reason, $src, $srcId]); if ($ins->rowCount() === 1) $newBans++; }
    $ph = implode(',', array_fill(0, count($hashes), '?'));
    $st = $db->prepare("UPDATE whitelist SET banned = 1 WHERE banned = 0 AND info_hash IN ($ph)");
    $st->execute($hashes);
    $affected = $st->rowCount();
    if ($affected > 0 && trackerMode($cfg) === 'whitelist') {
        whitelistRegenerate($db, $cfg);
        whitelistMarkDirty(true);
        whitelistMaybeReload($cfg);
    }
    return ['banned' => $newBans, 'affected' => $affected];
}

/** Lift a ban. If a whitelist row exists it becomes active again (append + lazy reload). */
function whitelistUnban(PDO $db, array $cfg, string $hash): bool {
    $hash = strtolower($hash);
    if (!isValidInfoHash($hash)) return false;
    $db->prepare("DELETE FROM banned_hashes WHERE info_hash = ?")->execute([$hash]);
    $st = $db->prepare("UPDATE whitelist SET banned = 0 WHERE banned = 1 AND info_hash = ?");
    $st->execute([$hash]);
    if ($st->rowCount() > 0 && trackerMode($cfg) === 'whitelist') {
        whitelistAppendHashes($db, $cfg, [$hash]);
        whitelistMarkDirty(false);
        whitelistMaybeReload($cfg);
    }
    return true;
}

/** Queue metadata fetching for rows (ignored when already pending/fetching). Returns rows queued. */
function whitelistRequestMeta(PDO $db, array $ids, int $priority = 5, bool $refresh = false): int {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $cond = $refresh ? "meta_status NOT IN ('pending','fetching')" : "meta_status IN ('none','failed')";
    $st = $db->prepare("UPDATE whitelist SET meta_status = 'pending', meta_priority = ?, meta_requested_at = NOW(), meta_error = NULL WHERE id IN ($ph) AND $cond");
    $st->execute(array_merge([$priority], $ids));
    return $st->rowCount();
}

/**
 * Ask OpenTracker for seeders/leechers/completed of one hash (HTTP scrape, 2 s timeout), cached in
 * the row for WL_SCRAPE_TTL seconds. Returns ['seeders','leechers','completed','scraped_at','cached'] or null.
 */
function scrapeOpenTracker(PDO $db, array $cfg, array $row, bool $force = false): ?array {
    $hash = strtolower((string)($row['info_hash'] ?? ''));
    if (!isValidInfoHash($hash)) return null;
    if (!$force && !empty($row['scraped_at']) && (time() - strtotime($row['scraped_at'])) < WL_SCRAPE_TTL) {
        return ['seeders' => (int)$row['scrape_seeders'], 'leechers' => (int)$row['scrape_leechers'], 'completed' => (int)$row['scrape_completed'], 'scraped_at' => $row['scraped_at'], 'cached' => true];
    }
    $base = trim((string)($cfg['whitelist_scrape_url'] ?? ''));
    if ($base === '' || !preg_match('#^https?://#i', $base)) return null;
    $bin = hex2bin($hash);
    $url = $base . (str_contains($base, '?') ? '&' : '?') . 'info_hash=' . rawurlencode($bin);
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'tryhackx-tracker/1.2 scrape']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) $body = null;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx) ?: null;
    }
    if ($body === null) return null;
    // d5:filesd20:<hash>d8:completei12e10:downloadedi30e10:incompletei4eeee
    if (!preg_match('/8:completei(\d+)e10:downloadedi(\d+)e10:incompletei(\d+)e/', $body, $m)) return null;
    $res = ['seeders' => (int)$m[1], 'leechers' => (int)$m[3], 'completed' => (int)$m[2], 'scraped_at' => date('Y-m-d H:i:s'), 'cached' => false];
    if (!empty($row['id'])) {
        $db->prepare("UPDATE whitelist SET scrape_seeders = ?, scrape_leechers = ?, scrape_completed = ?, scraped_at = NOW() WHERE id = ?")
           ->execute([$res['seeders'], $res['leechers'], $res['completed'], (int)$row['id']]);
    }
    return $res;
}

// ─────────────────────────────────────────────────────────────────────────────
// Mode-aware block / unblock (used by the report / appeal / archive endpoints)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Block a hash on the tracker. Whitelist mode: ban it (banned_hashes + remove from the served
 * file). Blacklist mode: append to the blacklist file (legacy). $ctx = ['source','source_id','reason'].
 * Returns ['file_ok'=>bool,'errors'=>[],'suggestions'=>[],'reload'=>?array,'mode'=>string].
 */
function trackerBlockHash(PDO $db, array $cfg, string $hash, array $ctx = []): array {
    $hash = strtolower(trim($hash));
    $out = ['file_ok' => false, 'errors' => [], 'suggestions' => [], 'reload' => null, 'mode' => trackerMode($cfg)];
    if (!isValidInfoHash($hash)) { $out['errors'][] = 'Invalid info hash.'; return $out; }
    if ($out['mode'] === 'whitelist') {
        $r = whitelistBan($db, $cfg, [$hash], $ctx);
        $s = whitelistStateRead();
        $out['file_ok'] = empty($s['last_error']) || $r['affected'] === 0;
        if (!$out['file_ok']) $out['errors'][] = (string)$s['last_error'];
        $out['reload'] = $r['affected'] > 0 ? ['attempted' => true, 'ok' => (bool)($s['last_reload_ok'] ?? false)] : null;
        return $out;
    }
    $blacklistPath = normalizeListPath((string)($cfg['blacklist_path'] ?? ''));
    if ($blacklistPath === '') return $out; // not configured — same as before (DB-only block)
    $perm = checkBlacklistPermissions($blacklistPath);
    if (!$perm['ok']) { $out['errors'] = $perm['errors']; $out['suggestions'] = $perm['suggestions']; return $out; }
    $written = addHashToBlacklist($hash, $blacklistPath);
    $out['file_ok'] = $written;
    if ($written) $out['reload'] = autoReloadTrackerBlacklist($cfg);
    return $out;
}

/** Unblock a hash: whitelist mode lifts the ban; blacklist mode removes it from the blacklist file. */
function trackerUnblockHash(PDO $db, array $cfg, string $hash, array $ctx = []): array {
    $hash = strtolower(trim($hash));
    $out = ['file_ok' => false, 'errors' => [], 'suggestions' => [], 'reload' => null, 'mode' => trackerMode($cfg)];
    if (!isValidInfoHash($hash)) { $out['errors'][] = 'Invalid info hash.'; return $out; }
    if ($out['mode'] === 'whitelist') {
        whitelistUnban($db, $cfg, $hash);
        $out['file_ok'] = true;
        return $out;
    }
    $blacklistPath = normalizeListPath((string)($cfg['blacklist_path'] ?? ''));
    if ($blacklistPath === '') return $out;
    $out['file_ok'] = removeHashFromBlacklist($hash, $blacklistPath);
    if ($out['file_ok']) $out['reload'] = autoReloadTrackerBlacklist($cfg);
    return $out;
}

/** Is this hash currently blocked on the tracker (banned in whitelist mode / listed in blacklist mode)? */
function isHashBlocked(PDO $db, array $cfg, string $hash): bool {
    if (trackerMode($cfg) === 'whitelist') return isHashBanned($db, $hash);
    return isHashInBlacklist($hash, (string)($cfg['blacklist_path'] ?? ''));
}

// ─────────────────────────────────────────────────────────────────────────────
// Status / diagnostics
// ─────────────────────────────────────────────────────────────────────────────

/** Age in seconds of the metadata worker heartbeat file, or null when absent. */
function whitelistWorkerHeartbeatAge(array $cfg): ?int {
    $candidates = [(string)($cfg['worker_heartbeat_file'] ?? ''), '/home/tracker/metadata_worker/heartbeat'];
    foreach ($candidates as $f) {
        if ($f !== '' && is_file($f)) { $m = @filemtime($f); if ($m) return max(0, time() - $m); }
    }
    return null;
}

/** Everything the admin status card needs, plus a list of warnings for the dashboard. */
function whitelistStatus(PDO $db, array $cfg): array {
    $mode = trackerMode($cfg);
    $path = whitelistPath($cfg);
    $state = whitelistStateRead();
    $perm = $path !== '' ? checkListFilePermissions($path, 'whitelist', true) : ['ok' => false, 'errors' => ['Whitelist path is not configured.'], 'suggestions' => []];
    $file = ['exists' => false, 'size' => 0, 'mtime' => null, 'readable' => false, 'lines_estimate' => (int)$state['count']];
    if ($path !== '' && is_file($path)) {
        $file['exists'] = true; $file['size'] = (int)@filesize($path); $file['mtime'] = @filemtime($path) ?: null; $file['readable'] = is_readable($path);
        $file['lines_estimate'] = (int)floor($file['size'] / 41);
    }
    $counts = ['active' => 0, 'banned_rows' => 0, 'banned_hashes' => 0, 'pending_meta' => 0, 'fetching_meta' => 0];
    try {
        $counts['active'] = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 0")->fetchColumn();
        $counts['banned_rows'] = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 1")->fetchColumn();
        $counts['banned_hashes'] = (int)$db->query("SELECT COUNT(*) FROM banned_hashes")->fetchColumn();
        $counts['pending_meta'] = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE meta_status = 'pending'")->fetchColumn();
        $counts['fetching_meta'] = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE meta_status = 'fetching'")->fetchColumn();
    } catch (\Throwable $e) {}
    $hb = whitelistWorkerHeartbeatAge($cfg);
    $warnings = [];
    if ($mode === 'whitelist') {
        if (!$perm['ok']) $warnings[] = ['level' => 'danger', 'text' => 'Whitelist file problem: ' . implode(' ', $perm['errors'])];
        elseif (!$file['exists'] || $file['size'] === 0) $warnings[] = ['level' => 'danger', 'text' => 'Whitelist file is missing or EMPTY — OpenTracker in whitelist mode rejects every announce. Use "Regenerate file".'];
        if (!empty($state['last_error'])) $warnings[] = ['level' => 'danger', 'text' => 'Last whitelist file write failed: ' . $state['last_error']];
        if (!empty($state['regen_needed'])) $warnings[] = ['level' => 'warn', 'text' => 'Whitelist file regeneration pending (a removal/ban is not yet reflected in the file).'];
        $min = max(10, (int)($cfg['whitelist_reload_min_interval'] ?? 45));
        if (!empty($state['pending_reload']) && $state['dirty_since'] && (time() - (int)$state['dirty_since']) > 2 * $min) {
            $warnings[] = ['level' => 'warn', 'text' => 'Tracker reload pending for ' . formatUptime(time() - (int)$state['dirty_since']) . ' — the tracker still serves the previous whitelist.'];
        }
        if ((int)$state['fail_count'] >= 2) $warnings[] = ['level' => 'danger', 'text' => 'The last ' . (int)$state['fail_count'] . ' tracker reloads failed: ' . ($state['last_reload_output'] ?: 'unknown error') . ' — is the service running?'];
        if ($counts['pending_meta'] > 0 && ($hb === null || $hb > 300)) $warnings[] = ['level' => 'warn', 'text' => 'Metadata worker heartbeat ' . ($hb === null ? 'missing' : formatUptime($hb) . ' old') . ' — ' . $counts['pending_meta'] . ' hashes wait for metadata.'];
    }
    return [
        'mode' => $mode, 'path' => $path, 'permissions' => $perm, 'file' => $file, 'state' => $state, 'counts' => $counts,
        'worker_heartbeat_age' => $hb, 'reload_min_interval' => max(10, (int)($cfg['whitelist_reload_min_interval'] ?? 45)),
        'next_reload_in' => whitelistSecondsToNextReload($cfg), 'warnings' => $warnings,
        'service' => ['name' => (string)($cfg['opentracker_service_name'] ?? ''), 'auto_reload' => (($cfg['opentracker_auto_reload'] ?? '1') === '1'), 'exec' => trackerExecAvailable()],
    ];
}

/** Import the legacy blacklist file into banned_hashes (one-off at mode switch). Returns ['imported','skipped','invalid']. */
function whitelistImportBlacklist(PDO $db, array $cfg): array {
    $out = ['imported' => 0, 'skipped' => 0, 'invalid' => 0, 'error' => null];
    $path = normalizeListPath((string)($cfg['blacklist_path'] ?? ''));
    if ($path === '' || !is_file($path) || !is_readable($path)) { $out['error'] = 'Blacklist file not configured or not readable.'; return $out; }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $hashes = [];
    foreach ($lines as $l) {
        $l = strtolower(trim($l));
        if (isValidInfoHash($l)) $hashes[$l] = true; else $out['invalid']++;
    }
    if ($hashes) {
        $r = whitelistBan($db, $cfg, array_keys($hashes), ['source' => 'import', 'reason' => 'Imported from blacklist file']);
        $out['imported'] = $r['banned'];
        $out['skipped'] = count($hashes) - $r['banned'];
    }
    return $out;
}
