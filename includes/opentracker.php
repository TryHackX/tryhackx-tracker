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
/** Status is polled by a card; reuse the helper's answer briefly rather than forking per request. */
const OT_STATUS_TTL = 5;

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
    if ($cmd === '') { $out['error'] = 'No OpenTracker helper command is configured (Settings → OpenTracker performance).'; return $out; }
    if (!otValidCommand($cmd)) { $out['error'] = 'The helper command contains characters that are not allowed.'; return $out; }
    if (!trackerExecAvailable()) { $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the helper.'; return $out; }

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
            ? 'The helper did not answer with JSON: ' . mb_substr($out['output'], 0, 300)
            : 'The helper produced no output (exit ' . (int)$rc . '). Check the sudoers rule.';
        return $out;
    }
    $out['ok'] = !empty($out['json']['ok']) && $out['code'] === 0;
    if (!$out['ok'] && $out['error'] === null) $out['error'] = (string)($out['json']['error'] ?? ('Helper exited with code ' . (int)$rc));
    return $out;
}

function otStatus(array $cfg, bool $fresh = false): array {
    static $cache = null, $cachedAt = 0;
    if (!$fresh && $cache !== null && (time() - $cachedAt) < OT_STATUS_TTL) return $cache + ['cached' => true];
    $r = otRun($cfg, ['status']);
    if (!$r['ok'] || !is_array($r['json'])) return ['ok' => false, 'error' => $r['error'] ?? 'unknown error', 'output' => $r['output']];
    $cache = $r['json'];
    $cachedAt = time();
    return $cache + ['cached' => false];
}

function otCheck(array $cfg): array {
    $r = otRun($cfg, ['check']);
    if (is_array($r['json'])) return $r['json'];
    return ['ok' => false, 'error' => $r['error'] ?? 'unknown error', 'output' => $r['output']];
}

function otApply(array $cfg, bool $dryRun = false): array {
    $args = ['apply', (string)otNice($cfg), (string)otCpuWeight($cfg), otCpuAffinity($cfg), (string)otLimitNofile($cfg)];
    if ($dryRun) $args[] = '--dry-run';
    return otRun($cfg, $args);
}

function otWorkers(array $cfg, int $n, bool $dryRun = false): array {
    $args = ['workers', (string)netlimitClampInt($n, 1, OT_WORKERS_MAX, 4)];
    if ($dryRun) $args[] = '--dry-run';
    return otRun($cfg, $args);
}

function otReset(array $cfg, bool $dryRun = false): array {
    $args = ['reset'];
    if ($dryRun) $args[] = '--dry-run';
    return otRun($cfg, $args);
}

function otRestart(array $cfg): array { return otRun($cfg, ['restart']); }

/**
 * The sentence the card puts under the numbers.
 *
 * The receive-buffer figure is the one that matters and the one nobody thinks to look at: opentracker
 * asks the kernel for a socket buffer, the kernel clamps it to net.core.rmem_max, and when that fills
 * the packet is thrown away AFTER the machine has already paid to receive it. That is the worst place
 * to lose an announce — worse than the firewall dropping it, which costs nothing.
 */
function otAdvice(array $st): array {
    $out = [];
    $cpus = max(1, (int)($st['cpus'] ?? 1));
    $workers = (int)($st['workers'] ?? 0);
    if ($workers > 0 && $workers < $cpus) {
        $out[] = ['level' => 'info', 'text' => 'opentracker runs ' . $workers . ' UDP worker threads on ' . $cpus
            . ' cores. More threads help only while packets are actually queueing — check the dropped count below before raising it.'];
    } elseif ($workers > $cpus) {
        $out[] = ['level' => 'warn', 'text' => 'There are more UDP workers (' . $workers . ') than cores (' . $cpus
            . '). Past one per core the threads mostly compete with each other.'];
    }
    if (empty($st['workers_consistent'])) {
        $out[] = ['level' => 'warn', 'text' => 'The whitelist and blacklist config files disagree about the worker count, '
            . 'so it would change when the tracker switches mode. Applying a value from here writes both.'];
    }
    $rmem = (int)($st['rmem_max'] ?? 0);
    $drops = (int)($st['socket_drops'] ?? 0);
    if ($rmem > 0 && $rmem < 1048576) {
        $out[] = ['level' => $drops > 0 ? 'warn' : 'info', 'text' =>
            'The kernel caps every socket buffer at ' . number_format($rmem) . ' bytes (net.core.rmem_max)'
            . ($drops > 0 ? ', and this socket has already discarded ' . number_format($drops) . ' packets because its queue was full. ' : '. ')
            . 'A packet dropped there cost the machine everything except the answer — unlike one the firewall drops, which costs nothing. '
            . 'Raising it is a system-wide sysctl, so the panel does not do it for you: sudo sysctl -w net.core.rmem_max=8388608'];
    }
    if (!empty($st['other_dropins'])) {
        $out[] = ['level' => 'info', 'text' => 'Other drop-ins are present and are never touched by the panel: '
            . implode(', ', array_map('strval', (array)$st['other_dropins'])) . '. systemd merges them, and the highest-numbered file wins a conflict.'];
    }
    return $out;
}
