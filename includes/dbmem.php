<?php
/**
 * Database memory — the MariaDB / MySQL knobs the panel manages (schema v42).
 *
 * The buffer pool and the handful of other settings that decide how much RAM the database engine
 * takes were being set in two places by hand: `SET GLOBAL` on the running server and a drop-in
 * under /etc/mysql for the next restart, and the two drifted. This module puts both behind one
 * card on the Traffic page and one root helper, tools/opentracker/tracker-dbmem.sh:
 *
 *   status   what runs, what the drop-in says, the counters that say whether it matters
 *   apply    SET GLOBAL where the engine allows it (and read back), then the drop-in
 *   persist  the drop-in only — what the janitor runs when php-fpm could not write /etc
 *   restart  systemctl restart of the service — its own action, never a side effect
 *
 * php-fpm on this class of machine runs with ProtectSystem=full, so /etc is read-only inside its
 * mount namespace — for root as well. The helper says `deferred` in that case, the pending pairs
 * are recorded in config/dbmem_state.json, and the janitor (an ordinary unit, no sandbox) finishes
 * the write within a minute. Same protocol as the kernel buffers.
 *
 * MariaDB and MySQL differ in what is dynamic; the HELPER decides that per engine and version and
 * reports it, this file only validates ranges and carries the answer to the page.
 */

const DBMEM_KEY_NAMES = ['innodb_buffer_pool_size', 'innodb_buffer_pool_size_max', 'innodb_log_file_size',
                         'max_connections', 'tmp_table_size', 'max_heap_table_size', 'table_open_cache'];

function dbmemKeyNames(): array { return DBMEM_KEY_NAMES; }
function dbmemCommand(array $cfg): string { return trim((string)($cfg['dbmem_cmd'] ?? '')); }
function dbmemEnabled(array $cfg): bool { return (($cfg['dbmem_enabled'] ?? '0') === '1') && dbmemCommand($cfg) !== ''; }

/** Same rule as every other helper command: a path and options, no shell metacharacters. */
function dbmemValidCommand(string $cmd): bool {
    return $cmd === '' || preg_match('#^[A-Za-z0-9 _./-]{1,255}$#', $cmd) === 1;
}

/** Run the helper; the reply is the LAST single-line JSON object, so a noisy sudo cannot hide a good answer. */
function dbmemRun(array $cfg, array $args): array {
    $out = ['ok' => false, 'json' => null, 'output' => '', 'code' => null, 'error' => null];
    $cmd = dbmemCommand($cfg);
    if ($cmd === '') { $out['error'] = __('api.dbmem.run_no_helper'); return $out; }
    if (!dbmemValidCommand($cmd)) { $out['error'] = __('api.dbmem.run_bad_command'); return $out; }
    if (!trackerExecAvailable()) { $out['error'] = __('api.dbmem.run_exec_disabled'); return $out; }
    $full = $cmd;
    foreach ($args as $a) $full .= ' ' . escapeshellarg((string)$a);
    $full .= ' 2>&1';
    $lines = []; $rc = null;
    @exec($full, $lines, $rc);
    $out['code'] = $rc === null ? null : (int)$rc;
    $out['output'] = trim(implode("\n", $lines));
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $l = trim((string)$lines[$i]);
        if ($l === '' || $l[0] !== '{') continue;
        // a refusal echoes the caller's argument; a byte that is not UTF-8 must not turn the
        // refusal into "no JSON"
        $j = json_decode($l, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($j)) { $out['json'] = $j; break; }
    }
    if ($out['json'] === null) {
        $out['error'] = $out['output'] !== ''
            ? __('api.dbmem.run_no_json', ['out' => mb_substr($out['output'], 0, 300)])
            : __('api.dbmem.run_no_output', ['code' => (int)$rc]);
        return $out;
    }
    $out['ok'] = !empty($out['json']['ok']) && $out['code'] === 0;
    if (!$out['ok'] && $out['error'] === null) $out['error'] = (string)($out['json']['error'] ?? __('api.dbmem.run_exit_code', ['code' => (int)$rc]));
    return $out;
}

function dbmemStatus(array $cfg): array {
    $r = dbmemRun($cfg, ['status']);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'output' => $r['output']];
    return $r['json'];
}

/* ── the state file: what is still owed to the janitor, and what happened last ─────────────── */

function dbmemStateFile(): string { return __DIR__ . '/../config/dbmem_state.json'; }
function dbmemStateLock(): string { return __DIR__ . '/../config/dbmem_state.lock'; }

function dbmemStateRead(): array {
    $f = dbmemStateFile();
    $d = [];
    if (is_file($f)) { $raw = @file_get_contents($f); $d = $raw ? (json_decode($raw, true) ?: []) : []; }
    return array_merge(['pending' => null, 'pending_at' => 0, 'last_apply' => null, 'last_restart' => null, 'last_error' => null],
                       is_array($d) ? $d : []);
}

function dbmemStateUpdate(callable $fn): array {
    $lh = @fopen(dbmemStateLock(), 'c');
    if ($lh) @flock($lh, LOCK_EX);
    try {
        $s = dbmemStateRead();
        if ($fn($s) !== false) {
            $tmp = dbmemStateFile() . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, json_encode($s), LOCK_EX) !== false) @rename($tmp, dbmemStateFile());
        }
        return $s;
    } finally {
        if ($lh) { @flock($lh, LOCK_UN); @fclose($lh); }
    }
}

/* ── validation, against what the helper reported for THIS engine ─────────────────────────── */

/**
 * '' when the pair is acceptable, else the sentence to show. The helper enforces the same floors
 * and ceilings; this is the earlier, friendlier refusal.
 */
function dbmemValidate(string $key, $value, array $st): string {
    if (!in_array($key, DBMEM_KEY_NAMES, true)) return __('api.dbmem.unknown_key', ['key' => $key]);
    $meta = $st['keys'][$key] ?? null;
    if (is_array($meta) && empty($meta['supported'])) return __('api.dbmem.unsupported', ['key' => $key]);
    if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,19}$/', $value))) return __('api.dbmem.not_a_number', ['key' => $key]);
    $v = (int)$value;
    $min = (int)($meta['min'] ?? 1); $max = (int)($meta['max'] ?? PHP_INT_MAX);
    if ($v < $min) return __('api.dbmem.below_min', ['key' => $key, 'min' => dbmemHumanValue($key, $min)]);
    if ($max > 0 && $v > $max) return __('api.dbmem.above_max', ['key' => $key, 'max' => dbmemHumanValue($key, $max)]);
    return '';
}

function dbmemUnit(string $key): string {
    return in_array($key, ['max_connections', 'table_open_cache'], true) ? 'count' : 'bytes';
}

function dbmemHumanBytes($bytes): string {
    $b = (float)$bytes;
    if ($b <= 0) return '0 B';
    $u = ['B', 'KiB', 'MiB', 'GiB', 'TiB']; $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$b : rtrim(rtrim(number_format($b, 2, '.', ''), '0'), '.')) . ' ' . $u[$i];
}

function dbmemHumanValue(string $key, $v): string {
    return dbmemUnit($key) === 'bytes' ? dbmemHumanBytes($v) : number_format((int)$v);
}

/* ── apply / restart / the janitor's share ────────────────────────────────────────────────── */

/**
 * key => int pairs, already validated. Live where the engine allows, the drop-in always; when the
 * drop-in could not be written from here the pairs are owed to the janitor.
 */
function dbmemApply(array $cfg, array $pairs): array {
    $args = ['apply'];
    foreach ($pairs as $k => $v) $args[] = $k . '=' . (int)$v;
    $r = dbmemRun($cfg, $args);
    $j = is_array($r['json']) ? $r['json'] : [];
    $deferred = !empty($j['deferred']);
    dbmemStateUpdate(function (array &$s) use ($r, $j, $pairs, $deferred) {
        $s['last_apply'] = ['at' => time(), 'ok' => $r['ok'], 'deferred' => $deferred,
                            'applied' => is_array($j['applied'] ?? null) ? $j['applied'] : [], 'error' => $r['ok'] ? null : $r['error']];
        if ($r['ok'] && $deferred) { $s['pending'] = $pairs; $s['pending_at'] = time(); }
        elseif ($r['ok']) { $s['pending'] = null; $s['pending_at'] = 0; }
        $s['last_error'] = $r['ok'] ? null : $r['error'];
        return true;
    });
    return $r;
}

function dbmemRestart(array $cfg): array {
    $r = dbmemRun($cfg, ['restart']);
    $j = is_array($r['json']) ? $r['json'] : [];
    dbmemStateUpdate(function (array &$s) use ($r, $j) {
        $s['last_restart'] = ['at' => time(), 'ok' => $r['ok'], 'seconds' => (int)($j['seconds'] ?? 0), 'error' => $r['ok'] ? null : $r['error']];
        return true;
    });
    return $r;
}

/** The janitor's minute: finish a deferred drop-in write. CLI only, on purpose. */
function dbmemTick(array $cfg): array {
    $out = ['did' => null, 'ok' => null, 'error' => null];
    if (PHP_SAPI !== 'cli') return $out;
    if (!dbmemEnabled($cfg)) return $out;
    $s = dbmemStateRead();
    $pending = is_array($s['pending'] ?? null) ? $s['pending'] : null;
    if (!$pending) return $out;
    $args = ['persist'];
    foreach ($pending as $k => $v) {
        if (!in_array((string)$k, DBMEM_KEY_NAMES, true)) continue;
        $args[] = $k . '=' . (int)$v;
    }
    if (count($args) === 1) { dbmemStateUpdate(function (array &$st) { $st['pending'] = null; return true; }); return $out; }
    $r = dbmemRun($cfg, $args);
    $persisted = $r['ok'] && !empty($r['json']['persisted']);
    dbmemStateUpdate(function (array &$st) use ($r, $persisted) {
        if ($persisted) { $st['pending'] = null; $st['pending_at'] = 0; $st['last_error'] = null; }
        else $st['last_error'] = $r['error'] ?? 'the drop-in could not be written';
        return true;
    });
    return ['did' => 'persist', 'ok' => $persisted, 'error' => $persisted ? null : ($r['error'] ?? 'not written')];
}

/* ── what the counters say ─────────────────────────────────────────────────────────────────── */

/** Keys whose drop-in value differs from what runs: a restart would change them. */
function dbmemRestartPending(array $st): array {
    $out = [];
    $file = is_array($st['file_values'] ?? null) ? $st['file_values'] : [];
    $vars = is_array($st['vars'] ?? null) ? $st['vars'] : [];
    foreach ($file as $k => $v) {
        if (!in_array((string)$k, DBMEM_KEY_NAMES, true)) continue;
        if (!array_key_exists($k, $vars)) continue;
        if ((int)$vars[$k] !== (int)$v) $out[] = (string)$k;
    }
    return $out;
}

/**
 * Advice, only where a counter supports it: a number with nothing behind it is the failure the
 * kernel-buffers card was built to avoid, and the same rule holds here.
 */
function dbmemAdvice(array $st): array {
    $adv = [];
    $s = is_array($st['status'] ?? null) ? $st['status'] : [];
    $v = is_array($st['vars'] ?? null) ? $st['vars'] : [];
    $total = (int)($s['Innodb_buffer_pool_pages_total'] ?? 0);
    $free  = (int)($s['Innodb_buffer_pool_pages_free'] ?? 0);
    $reads = (float)($s['Innodb_buffer_pool_reads'] ?? 0);
    $reqs  = (float)($s['Innodb_buffer_pool_read_requests'] ?? 0);
    $uptime = (int)($s['Uptime'] ?? 0);
    if ($total > 0 && $uptime > 3600) {
        $usedPct = (int)round(100 * ($total - $free) / $total);
        $diskPct = $reqs > 0 ? 100 * $reads / $reqs : 0.0;
        if ($usedPct >= 97 && $diskPct >= 1.0) {
            $adv[] = ['level' => 'warn', 'text' => __('api.dbmem.adv_pool_full', ['pct' => $usedPct, 'disk' => number_format($diskPct, 2)])];
        } elseif ($usedPct < 60) {
            $adv[] = ['level' => 'info', 'text' => __('api.dbmem.adv_pool_spare', ['pct' => $usedPct])];
        } else {
            $adv[] = ['level' => 'info', 'text' => __('api.dbmem.adv_pool_ok', ['pct' => $usedPct, 'disk' => number_format($diskPct, 2)])];
        }
    }
    $tmpAll = (int)($s['Created_tmp_tables'] ?? 0); $tmpDisk = (int)($s['Created_tmp_disk_tables'] ?? 0);
    if ($tmpAll > 1000) {
        $pct = (int)round(100 * $tmpDisk / $tmpAll);
        if ($pct >= 25) $adv[] = ['level' => 'warn', 'text' => __('api.dbmem.adv_tmp_disk', ['pct' => $pct])];
    }
    $limit = (int)($v['max_connections'] ?? 0); $peak = (int)($s['Max_used_connections'] ?? 0);
    if ($limit > 0 && $peak >= (int)($limit * 0.8)) {
        $adv[] = ['level' => 'warn', 'text' => __('api.dbmem.adv_conn_high', ['peak' => $peak, 'limit' => $limit])];
    }
    $availKb = (int)($st['mem_available_kb'] ?? 0); $totalKb = (int)($st['mem_total_kb'] ?? 0);
    if ($totalKb > 0 && $availKb > 0 && $availKb < (int)($totalKb * 0.12)) {
        $adv[] = ['level' => 'warn', 'text' => __('api.dbmem.adv_ram_low', ['avail' => dbmemHumanBytes($availKb * 1024)])];
    }
    // MySQL and MariaDB < 11 resize the pool in the background; the variable shows the target at once
    $rs = trim((string)($s['Innodb_buffer_pool_resize_status'] ?? ''));
    if ($rs !== '' && !preg_match('/completed|not started|^$/i', $rs)) {
        $adv[] = ['level' => 'info', 'text' => __('api.dbmem.adv_resizing', ['status' => $rs])];
    }
    $pending = dbmemRestartPending($st);
    if ($pending) $adv[] = ['level' => 'warn', 'text' => __('api.dbmem.adv_restart_pending', ['keys' => implode(', ', $pending)])];
    if (!empty($st['conflicts']) && is_array($st['conflicts'])) {
        $names = [];
        foreach ($st['conflicts'] as $c) $names[] = basename((string)($c['file'] ?? '?')) . ':' . (string)($c['key'] ?? '?');
        $adv[] = ['level' => 'warn', 'text' => __('api.dbmem.adv_conflicts', ['list' => implode(', ', array_unique($names))])];
    }
    return $adv;
}
