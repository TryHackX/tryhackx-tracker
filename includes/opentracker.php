<?php
/**
 * OpenTracker performance knobs (schema v14) — the panel half of tools/opentracker/tracker-instance.sh.
 *
 * The knobs that already exist on any systemd box are worth nearly all of the available gain and
 * carry nearly none of the risk: how many UDP worker threads opentracker runs, what priority the
 * scheduler gives it, which cores it may use, and how many file descriptors it may hold. Extra
 * tracker instances are the expensive answer to the same question, and there is no point paying for
 * them until these have been tried and measured.
 *
 * Everything the panel writes goes into ONE file it owns — `90-tracker-panel.conf` in the unit's
 * drop-in directory. `override.conf` and `limits.conf` were put there by the installer or by hand
 * and are never touched. Undo is deleting that one file, which is exactly what Reset does.
 *
 * Nothing is applied by merely saving a setting: these values describe what the admin WANTS, and
 * the firewall-style Apply button (admin password) is what puts them in force. A fresh install
 * writes no drop-in at all and behaves exactly as it did before.
 */

const OT_NICE_MIN = -20;
const OT_NICE_MAX = 19;
const OT_WEIGHT_MIN = 1;
const OT_WEIGHT_MAX = 10000;
const OT_NOFILE_MIN = 1024;
const OT_NOFILE_MAX = 1048576;
const OT_WORKERS_MAX = 64;
/**
 * Status is polled by a card (every 15 s while it is visible); the helper's answer is reused rather than forked per
 * request. Kept in a FILE (1.74.0, PERF-13): the `static` it lived in before died with each php-fpm request, so the
 * cache never answered a single poll and every visible Traffic card ran the root helper (pgrep, several systemctl
 * reads, ss) every 15 s. What it reports changes when somebody presses a button, and every button drops it
 * (otStatusCacheDrop()), so half a minute of reuse — shared by every tab and every panel account — costs nothing.
 */
const OT_STATUS_TTL = 30;

function otPerfCommand(array $cfg): string { return trim((string)($cfg['ot_perf_cmd'] ?? '')); }
function otNice(array $cfg): int { return netlimitClampInt((int)($cfg['ot_nice'] ?? -2), OT_NICE_MIN, OT_NICE_MAX, -2); }
function otCpuWeight(array $cfg): int { return netlimitClampInt((int)($cfg['ot_cpu_weight'] ?? 100), OT_WEIGHT_MIN, OT_WEIGHT_MAX, 100); }
function otLimitNofile(array $cfg): int { return netlimitClampInt((int)($cfg['ot_limit_nofile'] ?? 65536), OT_NOFILE_MIN, OT_NOFILE_MAX, 65536); }
function otCpuAffinity(array $cfg): string { return trim((string)($cfg['ot_cpu_affinity'] ?? '')); }
/** Empty means "leave opentracker's own config alone" — the panel does not invent a worker count. */
function otUdpWorkers(array $cfg): int {
    $v = trim((string)($cfg['ot_udp_workers'] ?? ''));
    if ($v === '' || !ctype_digit($v)) return 0;
    return netlimitClampInt((int)$v, 1, OT_WORKERS_MAX, 0);
}

/** systemd takes "2-5" or "0 2 4"; anything else would make the unit refuse to start. */
function otValidAffinity(string $a): bool {
    $a = trim($a);
    if ($a === '') return true;
    if (!preg_match('/^[0-9]+(-[0-9]+)?([ ,][0-9]+(-[0-9]+)?)*$/', $a)) return false;
    foreach (preg_split('/[ ,]+/', $a) as $part) {
        if ($part === '') continue;
        $bits = explode('-', $part);
        $lo = (int)$bits[0];
        $hi = (int)($bits[1] ?? $bits[0]);
        if ($hi < $lo) return false;
    }
    return true;
}

/** Same shape as netlimitValidCommand: it is handed to a shell, so nothing exotic is allowed in. */
function otValidCommand(string $cmd): bool {
    $cmd = trim($cmd);
    return $cmd !== '' && (bool)preg_match('/^[A-Za-z0-9 _.\/-]{1,255}$/', $cmd);
}

function otRun(array $cfg, array $args): array {
    $out = ['ok' => false, 'json' => null, 'output' => '', 'code' => null, 'error' => null];
    $cmd = otPerfCommand($cfg);
    // the reader's language (1.73.0: these were English on every page — the netlimit helper's twins already were not)
    if ($cmd === '') { $out['error'] = __('api.ot.no_helper'); return $out; }
    if (!otValidCommand($cmd)) { $out['error'] = __('api.ot.bad_command'); return $out; }
    if (!trackerExecAvailable()) { $out['error'] = __('api.ot.exec_disabled'); return $out; }

    $full = $cmd;
    foreach ($args as $a) $full .= ' ' . escapeshellarg((string)$a);
    $full .= ' 2>&1';
    $lines = []; $rc = null;
    @exec($full, $lines, $rc);
    $out['code'] = $rc === null ? null : (int)$rc;
    $out['output'] = trim(implode("\n", $lines));
    // Same recovery as the firewall helper: the reply is the LAST single-line JSON object, so a
    // noisy sudo (or a stray warning) cannot make a working helper look broken.
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $l = trim((string)$lines[$i]);
        if ($l === '' || $l[0] !== '{') continue;
        $j = json_decode($l, true);
        if (is_array($j)) { $out['json'] = $j; break; }
    }
    if ($out['json'] === null) {
        $out['error'] = $out['output'] !== ''
            ? __('api.helper.no_json', ['out' => mb_substr($out['output'], 0, 300)])
            : __('api.helper.no_output', ['code' => (int)$rc]);
        return $out;
    }
    $out['ok'] = !empty($out['json']['ok']) && $out['code'] === 0;
    if (!$out['ok'] && $out['error'] === null) $out['error'] = (string)($out['json']['error'] ?? __('api.helper.exit_code', ['code' => (int)$rc]));
    return $out;
}

/** Where the helper's last status answer is kept between requests (OT_STATUS_TTL). */
function otStatusCacheFile(): string { return __DIR__ . '/../config/ot_status_cache.json'; }

/** Forget it: whatever a button just changed is read from the helper on the next poll. */
function otStatusCacheDrop(): void { @unlink(otStatusCacheFile()); }

function otStatus(array $cfg, bool $fresh = false, ?int $now = null): array {
    $now = $now ?? time();
    $file = otStatusCacheFile();
    $cmd = otPerfCommand($cfg);
    if (!$fresh && is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        // Keyed by the helper command, so changing it in Settings never shows the old helper's answer.
        if (is_array($c) && isset($c['at'], $c['v']) && is_array($c['v']) && ($c['cmd'] ?? null) === $cmd
            && ($now - (int)$c['at']) >= 0 && ($now - (int)$c['at']) < OT_STATUS_TTL) {
            return $c['v'] + ['cached' => true];
        }
    }
    $r = otRun($cfg, ['status']);
    if (!$r['ok'] || !is_array($r['json'])) return ['ok' => false, 'error' => $r['error'] ?? 'unknown error', 'output' => $r['output']];
    // A tmp+rename so a reader never sees half a file; a failed write only means the next poll asks the helper again.
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode(['at' => $now, 'cmd' => $cmd, 'v' => $r['json']])) !== false) @rename($tmp, $file);
    return $r['json'] + ['cached' => false];
}

function otCheck(array $cfg): array {
    $r = otRun($cfg, ['check']);
    if (is_array($r['json'])) return $r['json'];
    return ['ok' => false, 'error' => $r['error'] ?? 'unknown error', 'output' => $r['output']];
}

function otApply(array $cfg, bool $dryRun = false): array {
    $args = ['apply', (string)otNice($cfg), (string)otCpuWeight($cfg), otCpuAffinity($cfg), (string)otLimitNofile($cfg)];
    if ($dryRun) $args[] = '--dry-run';
    $r = otRun($cfg, $args);
    if (!$dryRun) otStatusCacheDrop();
    return $r;
}

function otWorkers(array $cfg, int $n, bool $dryRun = false): array {
    $args = ['workers', (string)netlimitClampInt($n, 1, OT_WORKERS_MAX, 4)];
    if ($dryRun) $args[] = '--dry-run';
    $r = otRun($cfg, $args);
    if (!$dryRun) otStatusCacheDrop();
    return $r;
}

function otReset(array $cfg, bool $dryRun = false): array {
    $args = ['reset'];
    if ($dryRun) $args[] = '--dry-run';
    $r = otRun($cfg, $args);
    if (!$dryRun) otStatusCacheDrop();
    return $r;
}

function otRestart(array $cfg): array {
    $r = otRun($cfg, ['restart']);
    otStatusCacheDrop();
    return $r;
}

/**
 * A deferred apply, remembered in the same state file the firewall uses.
 *
 * The panel's PHP cannot write /etc (systemd ProtectSystem on php-fpm), so an Apply pressed in the
 * browser records what was wanted and the janitor — an ordinary unit with no such sandbox — writes
 * the drop-in on its next visit. Without this the button would simply fail on any hardened box,
 * which is exactly the class of machine most likely to want the knobs.
 */
function otMarkPending(bool $pending): void {
    netlimitStateUpdate(function (array &$s) use ($pending) { $s['ot_apply_pending'] = $pending; return true; });
}
function otPending(): bool { return !empty(netlimitStateRead()['ot_apply_pending']); }

/** Called from the janitor. Forks nothing unless there is genuinely something waiting. */
function otTick(array $cfg): array {
    $out = ['pending' => false, 'applied' => false, 'error' => null];
    if (!otPending() || otPerfCommand($cfg) === '') return $out;
    $out['pending'] = true;
    $r = otApply($cfg, false);
    if ($r['ok'] && empty($r['json']['deferred'])) {
        $out['applied'] = true;
        otMarkPending(false);
    } elseif (!$r['ok']) {
        $out['error'] = $r['error'] ?? 'could not write the drop-in';
    }
    return $out;
}

/**
 * The sentence the card puts under the numbers.
 *
 * The receive-buffer figure is the one that matters and the one nobody thinks to look at: opentracker
 * asks the kernel for a socket buffer, the kernel clamps it to net.core.rmem_max, and when that fills
 * the packet is thrown away AFTER the machine has already paid to receive it. That is the worst place
 * to lose an announce — worse than the firewall dropping it, which costs nothing.
 */
function otAdvice(array $st): array {
    // In the reader's language (1.73.0: English on every page, while the DB memory and sysctl cards' advice beside it
    // was not) — api.ot.adv_*, with the numbers written the reader's way (langNumber(), 1.73.1: Intl's grouping).
    $num = static fn(int $n): string => function_exists('langNumber') ? langNumber($n) : number_format($n);
    $out = [];
    $cpus = max(1, (int)($st['cpus'] ?? 1));
    $workers = (int)($st['workers'] ?? 0);
    if ($workers > 0 && $workers < $cpus) {
        $out[] = ['level' => 'info', 'text' => __('api.ot.adv_workers_few', ['workers' => $workers, 'cpus' => $cpus])];
    } elseif ($workers > $cpus) {
        $out[] = ['level' => 'warn', 'text' => __('api.ot.adv_workers_many', ['workers' => $workers, 'cpus' => $cpus])];
    }
    if (empty($st['workers_consistent'])) {
        $out[] = ['level' => 'warn', 'text' => __('api.ot.adv_workers_disagree')];
    }
    $rmem = (int)($st['rmem_max'] ?? 0);
    $drops = (int)($st['socket_drops'] ?? 0);
    if ($rmem > 0 && $rmem < 1048576) {
        $out[] = ['level' => $drops > 0 ? 'warn' : 'info', 'text' => $drops > 0
            ? __('api.ot.adv_rmem_drops', ['bytes' => $num($rmem), 'drops' => $num($drops)])
            : __('api.ot.adv_rmem', ['bytes' => $num($rmem)])];
    }
    if (!empty($st['other_dropins'])) {
        $out[] = ['level' => 'info', 'text' => __('api.ot.adv_dropins', ['files' => implode(', ', array_map('strval', (array)$st['other_dropins']))])];
    }
    return $out;
}
