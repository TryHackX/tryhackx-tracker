<?php
/**
 * Inbound UDP rate limit ("throttle") + packets-per-second monitor.
 *
 * Two levers exist on a busy tracker and they are independent:
 *   · INGRESS — packets dropped by the firewall never reach opentracker, so they cost no CPU.
 *     That is what this module drives, through the root helper tools/opentracker/tracker-netlimit.sh
 *     (own nftables table `inet ottrack_in`, own file in /etc/nftables.d/ — the distribution's
 *     `inet filter` table is never read or written, so an admin's hand-made rule keeps working and
 *     undoing everything is one click).
 *   · EGRESS — tools/opentracker/egress-budget/ottrack.nft caps what the tracker *sends*, which is
 *     what keeps the rest of the machine reachable. We do not install it, but we show its counters
 *     next to ours and can change its rate (handle-targeted, so its dynamic client sets survive).
 *
 * Nothing here runs as root itself: every privileged step is one call to the helper, whose arguments
 * are validated on both sides. `net_limit_cmd` follows the pattern of `tracker_mode_switch_cmd`
 * (letters, digits and space _ . / - only, action arguments appended through escapeshellarg).
 *
 * The monitor turns nftables' cumulative counters into rates the same way the swarm timeline turns
 * OpenTracker's counters into requests/second: remember the previous reading, divide the difference
 * by the elapsed time. Samples land in `net_samples` (schema v11), written by the janitor tick, and
 * the recommendation ("median / P95 / peak over the last 7 days → suggested limit") is computed
 * from them, so the admin sets a threshold from measurements instead of guessing.
 *
 * Settings (schema v11, Settings → "UDP traffic & rate limit", group `tracker`):
 *   net_monitor_enabled  0/1   record the PPS series (the counters need a table loaded — either the
 *                              limit itself, or the counting-only ruleset the panel can load instead)
 *   net_sample_seconds   60    seconds between samples (30–600)
 *   net_keep_days        14    retention of net_samples
 *   net_limit_enabled    0/1   is the ingress limit supposed to be loaded
 *   net_limit_pps        30000 packets/second budget (1000–1000000)
 *   net_limit_burst      100   burst allowance in packets (1–65535)
 *   net_limit_port       6969  the tracker's UDP port
 *   net_limit_cmd              root helper command prefix
 *   net_auto_enabled     0/1   move the limit automatically (needs the limit AND the monitor on)
 *   net_auto_min/max     10000/80000  the band the automatic mode may move inside
 *   net_auto_target      30000 packets/second we are willing to hand to the tracker
 *   net_auto_target_cpu  70    load-per-core percentage above which auto always tightens
 *
 * Everything is OFF by default: a fresh install never calls the helper and never writes a rule.
 */

const NET_PPS_MIN        = 1000;
const NET_PPS_MAX        = 1000000;
const NET_BURST_MIN      = 1;
const NET_BURST_MAX      = 65535;
const NET_SAMPLE_MIN     = 30;
const NET_SAMPLE_MAX     = 600;
const NET_KEEP_MIN       = 1;
const NET_KEEP_MAX       = 365;
const NET_LIVE_MIN_SPAN  = 2;      // seconds between two live readings before a rate is recomputed
const NET_LIVE_MAX_SPAN  = 900;    // older than this and the previous reading is too stale to subtract
const NET_STATUS_TTL     = 3;      // seconds the helper's status output is reused (the card polls every 5 s)
const NET_MANUAL_SCAN_TTL = 120;   // seconds between full scans for foreign rate-limit rules on the port
const NET_AUTO_HYSTERESIS = 3;     // consecutive samples on the same side before the limit moves
const NET_AUTO_STEP      = 0.10;   // ±10 % per move
const NET_AUTO_MIN_INTERVAL = 120; // seconds between two automatic moves
const NET_PANIC_PPS      = 10000;  // the "throttle hard" button
const NET_PANIC_MINUTES  = 15;
const NET_SAMPLE_TABLE   = 'net_samples';
const NET_PRUNE_EVERY    = 3600;
const NET_MAX_POINTS     = 3000;   // points a series reply may contain before it is bucketed
const NET_DEFAULT_CMD    = 'sudo -n /usr/local/sbin/tracker-netlimit.sh';

// ─────────────────────────────────────────────────────────────────────────────
// Settings accessors (all pure)
// ─────────────────────────────────────────────────────────────────────────────

function netlimitMonitorEnabled(array $cfg): bool { return (($cfg['net_monitor_enabled'] ?? '0') === '1'); }
function netlimitEnabled(array $cfg): bool        { return (($cfg['net_limit_enabled'] ?? '0') === '1'); }
function netlimitAutoEnabled(array $cfg): bool    { return (($cfg['net_auto_enabled'] ?? '0') === '1'); }

function netlimitClampInt($v, int $min, int $max, int $default): int {
    $n = is_numeric($v) ? (int)$v : $default;
    return max($min, min($max, $n));
}

function netlimitPps(array $cfg): int    { return netlimitClampInt($cfg['net_limit_pps']   ?? null, NET_PPS_MIN, NET_PPS_MAX, 30000); }
function netlimitBurst(array $cfg): int  { return netlimitClampInt($cfg['net_limit_burst'] ?? null, NET_BURST_MIN, NET_BURST_MAX, 100); }
function netlimitPort(array $cfg): int   { return netlimitClampInt($cfg['net_limit_port']  ?? null, 1, 65535, 6969); }
function netlimitSampleSeconds(array $cfg): int { return netlimitClampInt($cfg['net_sample_seconds'] ?? null, NET_SAMPLE_MIN, NET_SAMPLE_MAX, 60); }
function netlimitKeepDays(array $cfg): int      { return netlimitClampInt($cfg['net_keep_days'] ?? null, NET_KEEP_MIN, NET_KEEP_MAX, 14); }
function netlimitAutoMin(array $cfg): int       { return netlimitClampInt($cfg['net_auto_min'] ?? null, NET_PPS_MIN, NET_PPS_MAX, 10000); }
function netlimitAutoTarget(array $cfg): int    { return netlimitClampInt($cfg['net_auto_target'] ?? null, NET_PPS_MIN, NET_PPS_MAX, 30000); }
function netlimitAutoTargetCpu(array $cfg): int { return netlimitClampInt($cfg['net_auto_target_cpu'] ?? null, 10, 100, 70); }

/** Upper bound of the automatic band — never below the lower bound, whatever was typed. */
function netlimitAutoMax(array $cfg): int {
    return max(netlimitAutoMin($cfg), netlimitClampInt($cfg['net_auto_max'] ?? null, NET_PPS_MIN, NET_PPS_MAX, 80000));
}

/**
 * The root helper command. Same rule as the mode switch command: no shell metacharacters, the
 * action arguments are appended through escapeshellarg(). Empty = the feature is switched off.
 */
function netlimitValidCommand(string $cmd): bool {
    return $cmd === '' || preg_match('#^[A-Za-z0-9 _./-]{1,255}$#', $cmd) === 1;
}

function netlimitCommand(array $cfg): string {
    $cmd = trim((string)($cfg['net_limit_cmd'] ?? NET_DEFAULT_CMD));
    return netlimitValidCommand($cmd) ? $cmd : '';
}

/** Number of CPUs, for the load-per-core guard and for UI hints. 0 = unknown. */
function netlimitCpuCount(): int {
    if (is_readable('/proc/cpuinfo')) {
        $n = substr_count((string)@file_get_contents('/proc/cpuinfo'), 'processor');
        if ($n > 0) return $n;
    }
    return 0;
}

/** 1-minute load average divided by the core count, or null when it cannot be measured. */
function netlimitLoadPerCore(): ?float {
    if (!function_exists('sys_getloadavg')) return null;
    $la = @sys_getloadavg();
    if (!is_array($la) || !isset($la[0])) return null;
    $cpus = netlimitCpuCount();
    if ($cpus < 1) return null;
    return round(((float)$la[0]) / $cpus, 3);
}

// ─────────────────────────────────────────────────────────────────────────────
// State file (config/net_state.json) — same locking discipline as the whitelist state
// ─────────────────────────────────────────────────────────────────────────────

function netlimitStateFile(): string     { return __DIR__ . '/../config/net_state.json'; }
function netlimitStateLockFile(): string { return __DIR__ . '/../config/net_state.lock'; }

function netlimitStateDefaults(): array {
    return [
        'live'          => ['ts' => 0, 'counters' => [], 'egress' => [], 'pps' => [], 'epps' => []],
        'sample'        => ['ts' => 0, 'counters' => []],
        'auto'          => ['over' => 0, 'under' => 0, 'last_move_at' => 0, 'last_move' => null, 'note' => ''],
        'panic'         => ['until' => 0, 'restore_pps' => 0, 'restore_enabled' => 0],
        'status'        => null, 'status_at' => 0,
        'manual_rules'  => null, 'manual_at' => 0,   // foreign rate-limit rules on the port (scanned rarely)
        'last_error'    => null, 'last_error_at' => 0, 'last_ok_at' => 0,
        'last_apply_at' => 0, 'last_apply_pps' => 0, 'last_apply_source' => '',
        'last_prune_at' => 0, 'last_tick_at' => 0,
    ];
}

function netlimitStateRead(): array {
    $f = netlimitStateFile();
    $data = [];
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return array_replace_recursive(netlimitStateDefaults(), is_array($data) ? $data : []);
}

/** Atomic read-modify-write; $fn may return false to abort the write. Returns the state. */
function netlimitStateUpdate(callable $fn): array {
    $lockH = @fopen(netlimitStateLockFile(), 'c');
    if ($lockH) @flock($lockH, LOCK_EX);
    try {
        $state = netlimitStateRead();
        $r = $fn($state);
        if ($r !== false) {
            $tmp = netlimitStateFile() . '.tmp.' . getmypid();
            @file_put_contents($tmp, json_encode($state), LOCK_EX);
            @rename($tmp, netlimitStateFile());
        }
        return $state;
    } finally {
        if ($lockH) { @flock($lockH, LOCK_UN); @fclose($lockH); }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Talking to the helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run one helper action. Returns ['ok','json','output','code','error'].
 * stderr is merged into the output on purpose (a sudoers failure prints nothing else), and the
 * reply is recovered as the LAST single-line JSON object in it — the helper never prints anything
 * else on stdout, so this is exact even when sudo has been noisy first.
 */
function netlimitRun(array $cfg, array $args): array {
    $out = ['ok' => false, 'json' => null, 'output' => '', 'code' => null, 'error' => null];
    $cmd = netlimitCommand($cfg);
    if ($cmd === '') { $out['error'] = 'No rate-limit helper command is configured (Settings → UDP traffic & rate limit).'; return $out; }
    if (!trackerExecAvailable()) { $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the firewall helper.'; return $out; }

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
    if (!$out['ok'] && $out['error'] === null) {
        $out['error'] = (string)($out['json']['error'] ?? ('Helper exited with code ' . (int)$rc));
    }
    return $out;
}

/**
 * Firewall status (table present, current rate, counters, egress budget, foreign rules on the same
 * port). Cached for NET_STATUS_TTL seconds in the state file so a polling card does not fork a
 * process per request. $fresh forces a new call.
 */
function netlimitStatus(array $cfg, bool $fresh = false, ?int $now = null): array {
    $now = $now ?? time();
    $state = netlimitStateRead();
    // A cached entry from before the last apply describes a firewall that no longer exists — the
    // admin would press "Apply", see "not loaded" and press it again.
    $applyAt = (int)($state['last_apply_at'] ?? 0);
    if (!$fresh && is_array($state['status']) && ($now - (int)$state['status_at']) < NET_STATUS_TTL && (int)$state['status_at'] > $applyAt) {
        return $state['status'] + ['cached' => true];
    }
    $startedAt = $now;
    // Looking for foreign rules means dumping every table in the ruleset, which on a box with
    // fail2ban is thousands of lines. It changes about as often as an admin edits their firewall by
    // hand, so the poll asks for it only every NET_MANUAL_SCAN_TTL seconds and reuses the last
    // answer in between.
    $lastFull = (int)($state['manual_at'] ?? 0);
    $brief = ($now - $lastFull) < NET_MANUAL_SCAN_TTL && isset($state['manual_rules']);
    $r = netlimitRun($cfg, $brief ? ['status', '--brief'] : ['status']);
    if (!$r['ok'] || !is_array($r['json'])) {
        $err = $r['error'] ?? 'unknown error';
        netlimitStateUpdate(function (array &$s) use ($err, $now) { $s['last_error'] = $err; $s['last_error_at'] = $now; return true; });
        return ['ok' => false, 'error' => $err, 'output' => $r['output'], 'cached' => false];
    }
    $status = $r['json'];
    if (!array_key_exists('manual_rules', $status)) $status['manual_rules'] = (array)($state['manual_rules'] ?? []);
    netlimitStateUpdate(function (array &$s) use ($status, $now, $startedAt, $brief) {
        // When the helper answers cleanly, an older failure describes a condition that no longer
        // holds. Keeping the record but stamping the success lets the card stop shouting about it
        // without pretending it never happened — an intermittent fault still shows, because its
        // timestamp would be the newer one.
        $s['last_ok_at'] = $now;
        if (!$brief) { $s['manual_rules'] = $status['manual_rules']; $s['manual_at'] = $now; }
        // A poll that started before an apply must not park its stale answer in the cache with a
        // fresh timestamp: the write happens after the apply cleared it, so it would win. The
        // foreign-rule scan is unaffected by our own apply, so that part is still worth keeping.
        if ((int)($s['last_apply_at'] ?? 0) >= $startedAt) return !$brief;
        $s['status'] = $status; $s['status_at'] = $now;
        return true;
    });
    return $status + ['cached' => false];
}

// ─────────────────────────────────────────────────────────────────────────────
// Counters → rates (pure)
// ─────────────────────────────────────────────────────────────────────────────

/** Packet totals out of the helper's counter map, in a fixed shape. Unknown counters → 0. */
function netlimitCounterPackets(array $counters, array $keys): array {
    $out = [];
    foreach ($keys as $k) $out[$k] = isset($counters[$k]['packets']) ? (int)$counters[$k]['packets'] : 0;
    return $out;
}

/**
 * Rates between two cumulative readings. Returns [key => packets/second] rounded to whole packets,
 * or null when the pair cannot produce a rate:
 *   · the span is shorter than NET_LIVE_MIN_SPAN (division noise) or longer than NET_LIVE_MAX_SPAN,
 *   · a counter went backwards — the table was reloaded and the counters restarted at zero, so the
 *     difference would be meaningless. The helper avoids that where it can: changing only the rate
 *     on an existing table swaps that one rule by handle and the counters keep running; a fresh
 *     table or a changed port is a real reload.
 */
function netlimitRates(array $prev, array $cur, int $span, int $minSpan = NET_LIVE_MIN_SPAN, int $maxSpan = NET_LIVE_MAX_SPAN): ?array {
    if ($span < $minSpan || $span > $maxSpan) return null;
    $out = [];
    foreach ($cur as $k => $v) {
        $p = (int)($prev[$k] ?? 0);
        $v = (int)$v;
        if ($v < $p) return null;                 // counter reset
        $out[$k] = (int)round(($v - $p) / $span);
    }
    return $out;
}

/** Percentile (0–100) of a numeric list, nearest-rank. 0 for an empty list. */
function netlimitPercentile(array $values, float $pct): int {
    $values = array_values(array_map('intval', $values));
    if (!$values) return 0;
    sort($values, SORT_NUMERIC);
    $rank = (int)ceil(($pct / 100) * count($values));
    $rank = max(1, min(count($values), $rank));
    return (int)$values[$rank - 1];
}

/** Round a rate to a readable step (1 000 below 100 k, 5 000 above) — suggestions are advice, not maths. */
function netlimitRoundStep(int $pps): int {
    $step = $pps >= 100000 ? 5000 : 1000;
    return (int)max(NET_PPS_MIN, min(NET_PPS_MAX, (int)round($pps / $step) * $step));
}

/**
 * Turn a list of observed packets/second into the numbers the slider is annotated with:
 * median, P95, peak, a suggested limit (P95 + 5 %) and the point below which normal traffic
 * starts being dropped (median + 10 %). Pure — tests/netlimit_test.php drives it directly.
 */
function netlimitRecommendFrom(array $pps): array {
    $pps = array_values(array_filter(array_map('intval', $pps), fn($v) => $v >= 0));
    $n = count($pps);
    if ($n === 0) {
        return ['samples' => 0, 'median' => 0, 'p95' => 0, 'peak' => 0, 'suggested' => 0, 'floor' => 0, 'enough' => false];
    }
    $median = netlimitPercentile($pps, 50);
    $p95    = netlimitPercentile($pps, 95);
    $peak   = max($pps);
    return [
        'samples'   => $n,
        'median'    => $median,
        'p95'       => $p95,
        'peak'      => $peak,
        'suggested' => netlimitRoundStep((int)round($p95 * 1.05)),
        'floor'     => netlimitRoundStep((int)round($median * 1.10)),
        // one hour of minute samples is the least that says anything about a daily pattern
        'enough'    => $n >= 60,
    ];
}

/**
 * One automatic-mode decision. Pure: it gets the observed rate, the limit in force, the settings
 * and (optionally) the load per core, and answers what to do — including the updated hysteresis
 * counters, so the caller only has to persist them.
 *
 * The rule: more traffic is reaching the tracker than we want to hand it (or the machine is over
 * its load target) → tighten by 10 %. Comfortably below the target → loosen by 10 %. Either way it
 * takes NET_AUTO_HYSTERESIS consecutive samples on the same side, so a single spike moves nothing,
 * and the result never leaves [net_auto_min, net_auto_max].
 */
function netlimitAutoDecide(array $autoState, int $observedPps, int $currentPps, array $cfg, ?float $loadPerCore = null, ?int $now = null): array {
    $now = $now ?? time();
    $min = netlimitAutoMin($cfg);
    $max = netlimitAutoMax($cfg);
    $target = netlimitAutoTarget($cfg);
    $cpuTarget = netlimitAutoTargetCpu($cfg) / 100;
    $over  = (int)($autoState['over'] ?? 0);
    $under = (int)($autoState['under'] ?? 0);
    $lastMove = (int)($autoState['last_move_at'] ?? 0);

    $cpuHot = $loadPerCore !== null && $loadPerCore > $cpuTarget;
    if ($observedPps > $target || $cpuHot) { $over++; $under = 0; }
    elseif ($observedPps < (int)round($target * 0.8)) { $under++; $over = 0; }
    else { $over = 0; $under = 0; }

    $out = [
        'action' => 'hold', 'pps' => $currentPps, 'reason' => '',
        'state'  => ['over' => $over, 'under' => $under, 'last_move_at' => $lastMove,
                     'last_move' => $autoState['last_move'] ?? null, 'note' => (string)($autoState['note'] ?? '')],
    ];

    $ready = ($over >= NET_AUTO_HYSTERESIS) || ($under >= NET_AUTO_HYSTERESIS);
    if (!$ready) {
        $out['reason'] = $over || $under
            ? sprintf('%s for %d of %d samples', $over ? 'above target' : 'below target', max($over, $under), NET_AUTO_HYSTERESIS)
            : 'within the target band';
        return $out;
    }
    if ($now - $lastMove < NET_AUTO_MIN_INTERVAL) {
        $out['reason'] = 'waiting out the ' . NET_AUTO_MIN_INTERVAL . ' s cool-down after the last move';
        return $out;
    }

    if ($over >= NET_AUTO_HYSTERESIS) {
        $want = max($min, (int)round($currentPps * (1 - NET_AUTO_STEP)));
        $why = $cpuHot
            ? sprintf('load %.2f per core is over the %d %% target', $loadPerCore, netlimitAutoTargetCpu($cfg))
            : sprintf('%s pps reaching the tracker is over the %s pps target', number_format($observedPps), number_format($target));
        if ($want >= $currentPps) { $out['reason'] = $why . ', but the limit is already at the floor (' . number_format($min) . ' pps)'; return $out; }
        $out['action'] = 'down'; $out['pps'] = $want;
        $out['reason'] = $why;
    } else {
        $want = min($max, (int)round($currentPps * (1 + NET_AUTO_STEP)));
        $why = sprintf('%s pps is comfortably under the %s pps target', number_format($observedPps), number_format($target));
        if ($want <= $currentPps) { $out['reason'] = $why . ', but the limit is already at the ceiling (' . number_format($max) . ' pps)'; return $out; }
        $out['action'] = 'up'; $out['pps'] = $want;
        $out['reason'] = $why;
    }
    $out['state']['over'] = 0;
    $out['state']['under'] = 0;
    $out['state']['last_move_at'] = $now;
    $out['state']['last_move'] = $out['action'];
    $out['state']['note'] = $out['reason'];
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Applying
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Load (or reload) the ingress limit. $source is recorded in the state file so the card can say
 * whether the value in force came from the admin, the automatic mode or the panic button.
 * $dryRun asks the helper to render and syntax-check the ruleset without touching the firewall.
 */
function netlimitApply(array $cfg, int $pps, int $burst, int $port, bool $dryRun = false, string $source = 'admin'): array {
    $pps   = netlimitClampInt($pps, NET_PPS_MIN, NET_PPS_MAX, 30000);
    $burst = netlimitClampInt($burst, NET_BURST_MIN, NET_BURST_MAX, 100);
    $port  = netlimitClampInt($port, 1, 65535, 6969);
    $args  = ['set', (string)$pps, (string)$burst, (string)$port];
    if ($dryRun) $args[] = '--dry-run';
    $r = netlimitRun($cfg, $args);
    if ($r['ok'] && !$dryRun) {
        // The helper swaps the single rate rule by handle when it can ("replace"), which keeps the
        // counters running; only a full reload restarts them, and only then is the previous reading
        // worthless. Throwing the cursor away needlessly would cost a chart sample per change.
        $reloaded = (string)($r['json']['mode'] ?? 'reload') !== 'replace';
        netlimitStateUpdate(function (array &$s) use ($pps, $source, $reloaded) {
            $s['last_apply_at'] = time();
            $s['last_apply_pps'] = $pps;
            $s['last_apply_source'] = $source;
            $s['status'] = null; $s['status_at'] = 0;            // force a fresh read on the next poll
            if ($reloaded) {
                $s['live'] = ['ts' => 0, 'counters' => [], 'egress' => [], 'pps' => [], 'epps' => []];
                $s['sample'] = ['ts' => 0, 'counters' => []];
            }
            $s['last_error'] = null;
            // The rule is live but the file could not be written from inside php-fpm's mount
            // namespace. Not an error — the janitor saves it — but the card has to say so, because
            // until then a reboot would silently undo what the admin just decided.
            $s['persist_deferred'] = !empty($r['json']['persist_deferred']);
            return true;
        });
    } elseif (!$r['ok']) {
        $err = $r['error'] ?? 'apply failed';
        netlimitStateUpdate(function (array &$s) use ($err) { $s['last_error'] = $err; $s['last_error_at'] = time(); return true; });
    }
    return $r;
}

/**
 * Save the loaded ruleset to /etc/nftables.d so that it comes back after a reboot.
 *
 * An apply from the panel runs inside php-fpm, and this class of box runs php-fpm with systemd's
 * ProtectSystem=full: /etc is mounted read-only for the service and for every process it starts,
 * root included — it is a mount namespace, not a permission bit. So the rule reaches the kernel
 * (a syscall, unaffected) while the file that would restore it never gets written, and the panel
 * used to report that as a hard failure while the limit was in fact running.
 *
 * The janitor is an ordinary systemd unit with no such sandbox, so it can finish the job. This is
 * a no-op when the file already describes what is loaded.
 */
function netlimitPersist(array $cfg): array {
    $r = netlimitRun($cfg, ['persist']);
    netlimitStateUpdate(function (array &$s) use ($r) {
        if ($r['ok']) {
            $s['persist_deferred'] = false;
            if (!empty($r['json']['saved'])) {
                $s['last_persist_at'] = time();
                $s['status'] = null; $s['status_at'] = 0;   // `persistent` just changed
            }
        } else {
            $s['last_error'] = $r['error'] ?? 'could not save the ruleset';
            $s['last_error_at'] = time();
        }
        return true;
    });
    return $r;
}

/**
 * Load the COUNTING-ONLY ruleset: the same table and counters, no drop rule at all.
 *
 * This exists because the feature's whole promise — measure first, pick a threshold from what was
 * measured — needs numbers before there is a limit, and the counters live in the firewall. With no
 * table there is nothing to count, so switching the monitor on without this would record zeros and
 * quietly make the recommendation meaningless.
 */
function netlimitApplyMonitor(array $cfg, ?int $port = null, bool $dryRun = false, string $source = 'admin'): array {
    $port = netlimitClampInt($port ?? netlimitPort($cfg), 1, 65535, 6969);
    $args = ['monitor', (string)$port];
    if ($dryRun) $args[] = '--dry-run';
    $r = netlimitRun($cfg, $args);
    if ($r['ok'] && !$dryRun) {
        netlimitStateUpdate(function (array &$s) use ($source) {
            $s['last_apply_at'] = time();
            $s['last_apply_pps'] = 0;
            $s['last_apply_source'] = $source . ':count';
            $s['status'] = null; $s['status_at'] = 0;
            $s['live'] = ['ts' => 0, 'counters' => [], 'egress' => [], 'pps' => [], 'epps' => []];
            $s['sample'] = ['ts' => 0, 'counters' => []];
            $s['last_error'] = null;
            return true;
        });
    } elseif (!$r['ok']) {
        $err = $r['error'] ?? 'could not load the counters';
        netlimitStateUpdate(function (array &$s) use ($err) { $s['last_error'] = $err; $s['last_error_at'] = time(); return true; });
    }
    return $r;
}

/** Remove the table and the file. The port goes back to being unthrottled by us. */
function netlimitOff(array $cfg, bool $dryRun = false, string $source = 'admin'): array {
    $r = netlimitRun($cfg, $dryRun ? ['off', '--dry-run'] : ['off']);
    if ($r['ok'] && !$dryRun) {
        netlimitStateUpdate(function (array &$s) use ($source) {
            $s['last_apply_at'] = time(); $s['last_apply_pps'] = 0; $s['last_apply_source'] = $source . ':off';
            $s['status'] = null; $s['status_at'] = 0;
            $s['live'] = ['ts' => 0, 'counters' => [], 'egress' => [], 'pps' => [], 'epps' => []];
            $s['sample'] = ['ts' => 0, 'counters' => []];
            return true;
        });
    }
    return $r;
}

/** Change the rate of the EXISTING egress budget (table inet ottrack). */
function netlimitEgress(array $cfg, int $pps, bool $dryRun = false): array {
    $pps = netlimitClampInt($pps, NET_PPS_MIN, NET_PPS_MAX, 50000);
    $args = ['egress', (string)$pps];
    if ($dryRun) $args[] = '--dry-run';
    return netlimitRun($cfg, $args);
}

/**
 * "Throttle hard for N minutes": clamp to NET_PANIC_PPS and remember what to restore. The janitor
 * puts the previous limit back when the window expires — including turning the limit off again if
 * it was off before, so the button can never leave the tracker throttled by accident.
 */
function netlimitPanic(PDO $db, array $cfg, int $minutes = NET_PANIC_MINUTES, int $pps = NET_PANIC_PPS): array {
    $minutes = max(1, min(240, $minutes));
    $pps = netlimitClampInt($pps, NET_PPS_MIN, NET_PPS_MAX, NET_PANIC_PPS);
    $state = netlimitStateRead();
    $wasOn = netlimitEnabled($cfg);
    $wasPps = netlimitPps($cfg);
    // a panic on top of a panic must not overwrite the ORIGINAL values we have to restore
    if ((int)($state['panic']['until'] ?? 0) > time()) {
        $wasOn  = (int)($state['panic']['restore_enabled'] ?? 0) === 1;
        $wasPps = (int)($state['panic']['restore_pps'] ?? $wasPps);
    }
    $r = netlimitApply($cfg, $pps, netlimitBurst($cfg), netlimitPort($cfg), false, 'panic');
    if (!$r['ok']) return $r;
    $until = time() + $minutes * 60;
    netlimitStateUpdate(function (array &$s) use ($until, $wasPps, $wasOn) {
        $s['panic'] = ['until' => $until, 'restore_pps' => $wasPps, 'restore_enabled' => $wasOn ? 1 : 0];
        return true;
    });
    setSettings($db, ['net_limit_enabled' => '1', 'net_limit_pps' => (string)$pps]);
    $r['until'] = $until;
    $r['restore_pps'] = $wasPps;
    return $r;
}

/** Undo an active panic window immediately (also called by the janitor when it expires). */
function netlimitPanicRestore(PDO $db, array $cfg): array {
    $state = netlimitStateRead();
    $panic = $state['panic'] ?? [];
    if ((int)($panic['until'] ?? 0) === 0) return ['ok' => true, 'restored' => false, 'error' => null];
    $pps = netlimitClampInt($panic['restore_pps'] ?? null, NET_PPS_MIN, NET_PPS_MAX, 30000);
    $wasOn = (int)($panic['restore_enabled'] ?? 0) === 1;
    $r = $wasOn
        ? netlimitApply($cfg, $pps, netlimitBurst($cfg), netlimitPort($cfg), false, 'panic-restore')
        : netlimitOff($cfg, false, 'panic-restore');
    netlimitStateUpdate(function (array &$s) { $s['panic'] = ['until' => 0, 'restore_pps' => 0, 'restore_enabled' => 0]; return true; });
    if ($r['ok']) setSettings($db, ['net_limit_enabled' => $wasOn ? '1' : '0', 'net_limit_pps' => (string)$pps]);
    return ['ok' => (bool)$r['ok'], 'restored' => true, 'enabled' => $wasOn, 'pps' => $pps, 'error' => $r['error'] ?? null];
}

// ─────────────────────────────────────────────────────────────────────────────
// Live rates (for the card) and sampling (for the chart)
// ─────────────────────────────────────────────────────────────────────────────

const NET_IN_COUNTERS  = ['in_total', 'in_passed', 'in_capped'];
const NET_OUT_COUNTERS = ['announce_ok', 'passed_good', 'capped'];

/**
 * Rates since the previous call, computed from a status reply. Stores the new reading so the next
 * call has something to subtract from. Returns ['pps'=>[...], 'epps'=>[...], 'span'=>int, 'ts'=>int]
 * where the rate maps are empty until two readings far enough apart exist.
 */
function netlimitLive(array $status, ?int $now = null): array {
    $now = $now ?? time();
    $in  = netlimitCounterPackets((array)($status['counters'] ?? []), NET_IN_COUNTERS);
    $out = netlimitCounterPackets((array)($status['egress']['counters'] ?? []), NET_OUT_COUNTERS);
    $result = ['ts' => $now, 'span' => 0, 'pps' => [], 'epps' => []];

    netlimitStateUpdate(function (array &$s) use ($in, $out, $now, &$result) {
        $prev = $s['live'] ?? [];
        $prevTs = (int)($prev['ts'] ?? 0);
        if ($prevTs === 0) {
            // nothing to subtract from yet — keep this reading so the NEXT call can produce a rate
            $s['live'] = ['ts' => $now, 'counters' => $in, 'egress' => $out, 'pps' => [], 'epps' => [], 'span' => 0];
            return true;
        }
        $span = $now - $prevTs;
        if ($span < NET_LIVE_MIN_SPAN) {
            // too soon to divide: hand back what the previous call computed, keep the reading
            $result['pps']  = (array)($prev['pps'] ?? []);
            $result['epps'] = (array)($prev['epps'] ?? []);
            $result['span'] = (int)($prev['span'] ?? 0);
            $result['ts']   = $prevTs ?: $now;
            return false;
        }
        $pps  = netlimitRates((array)($prev['counters'] ?? []), $in, $span) ?? [];
        $epps = netlimitRates((array)($prev['egress'] ?? []), $out, $span) ?? [];
        $result['pps'] = $pps; $result['epps'] = $epps; $result['span'] = $span;
        $s['live'] = ['ts' => $now, 'counters' => $in, 'egress' => $out, 'pps' => $pps, 'epps' => $epps, 'span' => $span];
        return true;
    });
    return $result;
}

/** Is a chart sample due? */
function netlimitSampleDue(array $state, array $cfg, int $now): bool {
    $last = (int)($state['sample']['ts'] ?? 0);
    return ($now - $last) >= netlimitSampleSeconds($cfg);
}

/**
 * Store one sample. Rates are the difference against the previous SAMPLE (not the live reading), so
 * the chart is independent of how often somebody has the panel open.
 */
function netlimitStoreSample(PDO $db, array $cfg, array $status, int $now): array {
    // With no table of ours loaded there are no counters, so every field would be a legitimate-looking
    // zero — and a run of zeros drags the median to nothing and makes the recommendation a lie. A
    // missing measurement has to stay missing.
    if (empty($status['table'])) {
        netlimitStateUpdate(function (array &$s) { $s['sample'] = ['ts' => 0, 'counters' => []]; return true; });
        return ['stored' => false, 'reason' => 'nothing is counting — no rules of ours are loaded', 'pps' => null];
    }
    $in  = netlimitCounterPackets((array)($status['counters'] ?? []), NET_IN_COUNTERS);
    $out = netlimitCounterPackets((array)($status['egress']['counters'] ?? []), NET_OUT_COUNTERS);
    $state = netlimitStateRead();
    $prevTs = (int)($state['sample']['ts'] ?? 0);
    $span = $prevTs > 0 ? ($now - $prevTs) : 0;
    $maxSpan = max(NET_LIVE_MAX_SPAN, netlimitSampleSeconds($cfg) * 5);
    $rates  = netlimitRates((array)($state['sample']['counters'] ?? []), $in, $span, NET_LIVE_MIN_SPAN, $maxSpan);
    $erates = netlimitRates((array)($state['sample']['egress'] ?? []), $out, $span, NET_LIVE_MIN_SPAN, $maxSpan);

    netlimitStateUpdate(function (array &$s) use ($in, $out, $now) {
        $s['sample'] = ['ts' => $now, 'counters' => $in, 'egress' => $out];
        return true;
    });
    // first reading after a start/reload has nothing to subtract from — remember it, store nothing
    if ($rates === null) return ['stored' => false, 'reason' => $prevTs === 0 ? 'first reading' : 'counters restarted', 'pps' => null];

    $sql = "INSERT INTO `" . NET_SAMPLE_TABLE . "`
                (ts, span, in_total, in_passed, in_capped, out_ok, out_capped,
                 pps_total, pps_passed, pps_capped, epps_ok, epps_capped, limit_pps)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE span = VALUES(span), in_total = VALUES(in_total), in_passed = VALUES(in_passed),
                 in_capped = VALUES(in_capped), out_ok = VALUES(out_ok), out_capped = VALUES(out_capped),
                 pps_total = VALUES(pps_total), pps_passed = VALUES(pps_passed), pps_capped = VALUES(pps_capped),
                 epps_ok = VALUES(epps_ok), epps_capped = VALUES(epps_capped), limit_pps = VALUES(limit_pps)";
    $db->prepare($sql)->execute([
        $now, $span,
        $in['in_total'], $in['in_passed'], $in['in_capped'],
        $out['announce_ok'] + $out['passed_good'], $out['capped'],
        $rates['in_total'], $rates['in_passed'], $rates['in_capped'],
        $erates === null ? 0 : ($erates['announce_ok'] + $erates['passed_good']),
        $erates === null ? 0 : $erates['capped'],
        (int)($status['pps'] ?? 0),
    ]);
    return ['stored' => true, 'reason' => '', 'pps' => $rates];
}

/** Drop samples older than the retention window. */
function netlimitPrune(PDO $db, array $cfg, int $now): int {
    $cutoff = $now - netlimitKeepDays($cfg) * 86400;
    $st = $db->prepare("DELETE FROM `" . NET_SAMPLE_TABLE . "` WHERE ts < ? LIMIT 20000");
    $st->execute([$cutoff]);
    return $st->rowCount();
}

/**
 * Janitor tick: read the firewall once, sample, expire a panic window, prune, and let the automatic
 * mode move the limit. Completely inert (no exec at all) while everything is off. Never throws.
 */
function netlimitTick(PDO $db, array &$cfg, ?int $now = null): array {
    $now = $now ?? time();
    $state = netlimitStateRead();
    $panicPending = (int)($state['panic']['until'] ?? 0) > 0;
    $out = ['enabled' => false, 'sampled' => false, 'auto' => null, 'panic' => null, 'pruned' => 0, 'persisted' => false, 'error' => null];
    // A save the panel could not perform must be finished even with the monitor and the automatic
    // mode both off — otherwise applying a limit on a monitor-less install leaves the rule live and
    // the file stale for ever, which is the exact failure the deferred save exists to close. The
    // flag is set by netlimitApply(), so this costs one already-loaded state read and forks nothing
    // until there is genuinely something to save.
    if (!empty($state['persist_deferred'])) {
        $pr = netlimitPersist($cfg);
        $out['enabled'] = true;
        $out['persisted'] = $pr['ok'] && !empty($pr['json']['saved']);
        if (!$pr['ok']) $out['error'] = $pr['error'] ?? 'could not save the ruleset';
    }
    if (!netlimitMonitorEnabled($cfg) && !netlimitAutoEnabled($cfg) && !$panicPending) return $out;
    $out['enabled'] = true;

    try {
        // 1. panic window over → restore first, so the sample below already reflects the real limit
        if ($panicPending && (int)$state['panic']['until'] <= $now) {
            $res = netlimitPanicRestore($db, $cfg);
            $cfg = getSettings($db, true);
            $out['panic'] = $res;
            // the restore reloaded the firewall: the counters restarted and the sample cursor was
            // cleared, so the snapshot read above no longer describes anything
            $state = netlimitStateRead();
        }

        if (!netlimitMonitorEnabled($cfg) && !netlimitAutoEnabled($cfg)) return $out;

        $status = netlimitStatus($cfg, true, $now);
        if (empty($status['ok'])) {
            $out['error'] = (string)($status['error'] ?? 'firewall status unavailable');
            return $out;
        }

        // 2. sample
        $rates = null;
        if (netlimitMonitorEnabled($cfg) && netlimitSampleDue($state, $cfg, $now)) {
            $s = netlimitStoreSample($db, $cfg, $status, $now);
            $out['sampled'] = $s['stored'];
            $rates = $s['pps'];
        }

        // 2b. finish a save the panel could not do itself. `file_matches` is absent on an older
        // helper, and then this does nothing at all.
        if (!$out['persisted'] && !empty($status['table'])
            && array_key_exists('file_matches', $status) && !$status['file_matches']) {
            $pr = netlimitPersist($cfg);
            $out['persisted'] = $pr['ok'] && !empty($pr['json']['saved']);
            if (!$pr['ok']) $out['error'] = $pr['error'] ?? 'could not save the ruleset';
        }

        // 3. retention
        if (($now - (int)($state['last_prune_at'] ?? 0)) >= NET_PRUNE_EVERY) {
            $out['pruned'] = netlimitPrune($db, $cfg, $now);
            netlimitStateUpdate(function (array &$s) use ($now) { $s['last_prune_at'] = $now; return true; });
        }

        // 4. automatic mode — only with a limit actually loaded and a fresh rate to judge by
        if (netlimitAutoEnabled($cfg) && netlimitEnabled($cfg) && $rates !== null && !empty($status['table'])) {
            $current = (int)($status['pps'] ?? netlimitPps($cfg));
            $d = netlimitAutoDecide((array)$state['auto'], (int)$rates['in_passed'], $current, $cfg, netlimitLoadPerCore(), $now);
            $applied = false;
            if ($d['action'] !== 'hold') {
                $r = netlimitApply($cfg, $d['pps'], netlimitBurst($cfg), netlimitPort($cfg), false, 'auto');
                $applied = (bool)$r['ok'];
                if ($applied) { setSettings($db, ['net_limit_pps' => (string)$d['pps']]); $cfg['net_limit_pps'] = (string)$d['pps']; }
                else { $d['reason'] .= ' — apply failed: ' . (string)($r['error'] ?? ''); $out['error'] = $r['error'] ?? null; }
            }
            $newAuto = $d['state'];
            if (!$applied && $d['action'] !== 'hold') { $newAuto['last_move_at'] = (int)($state['auto']['last_move_at'] ?? 0); }
            netlimitStateUpdate(function (array &$s) use ($newAuto) { $s['auto'] = $newAuto; return true; });
            $out['auto'] = ['action' => $applied ? $d['action'] : 'hold', 'pps' => $d['pps'], 'reason' => $d['reason']];
        }

        netlimitStateUpdate(function (array &$s) use ($now) { $s['last_tick_at'] = $now; return true; });
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        error_log('[netlimit] ' . $e->getMessage());
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading the series back
// ─────────────────────────────────────────────────────────────────────────────

/** Bucket size (seconds) that keeps a range under NET_MAX_POINTS points. */
function netlimitBucketFor(int $span, int $sampleSeconds, int $maxPoints = NET_MAX_POINTS): int {
    $sampleSeconds = max(1, $sampleSeconds);
    $need = (int)ceil($span / $sampleSeconds);
    if ($need <= $maxPoints) return 0;                     // raw
    $bucket = (int)ceil($span / $maxPoints);
    foreach ([60, 300, 900, 1800, 3600, 10800, 21600, 86400] as $step) {
        if ($bucket <= $step) return $step;
    }
    return 86400;
}

/**
 * Chart series for [$from, $to]. Averages inside a bucket for the rates and keeps the maximum for
 * the capped series (a short burst that got clipped must stay visible after bucketing).
 */
function netlimitSeries(PDO $db, array $cfg, int $from, int $to): array {
    $from = max(0, $from); $to = max($from + 1, $to);
    $bucket = netlimitBucketFor($to - $from, netlimitSampleSeconds($cfg));
    if ($bucket > 0) {
        $sql = "SELECT (ts - ts % :b) AS t,
                       ROUND(AVG(pps_total)) AS pps_total, ROUND(AVG(pps_passed)) AS pps_passed,
                       MAX(pps_capped) AS pps_capped, ROUND(AVG(epps_ok)) AS epps_ok, MAX(epps_capped) AS epps_capped,
                       MAX(limit_pps) AS limit_pps, COUNT(*) AS n
                FROM `" . NET_SAMPLE_TABLE . "` WHERE ts BETWEEN :f AND :t GROUP BY t ORDER BY t";
        $st = $db->prepare($sql);
        $st->bindValue(':b', $bucket, PDO::PARAM_INT);
    } else {
        $sql = "SELECT ts AS t, pps_total, pps_passed, pps_capped, epps_ok, epps_capped, limit_pps, 1 AS n
                FROM `" . NET_SAMPLE_TABLE . "` WHERE ts BETWEEN :f AND :t ORDER BY t";
        $st = $db->prepare($sql);
    }
    $st->bindValue(':f', $from, PDO::PARAM_INT);
    $st->bindValue(':t', $to, PDO::PARAM_INT);
    $st->execute();
    $series = ['t' => [], 'pps_total' => [], 'pps_passed' => [], 'pps_capped' => [], 'epps_ok' => [], 'epps_capped' => [], 'limit_pps' => []];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $series['t'][]           = (int)$row['t'];
        $series['pps_total'][]   = (int)$row['pps_total'];
        $series['pps_passed'][]  = (int)$row['pps_passed'];
        $series['pps_capped'][]  = (int)$row['pps_capped'];
        $series['epps_ok'][]     = (int)$row['epps_ok'];
        $series['epps_capped'][] = (int)$row['epps_capped'];
        $series['limit_pps'][]   = (int)$row['limit_pps'];
    }
    return ['from' => $from, 'to' => $to, 'bucket' => $bucket, 'points' => count($series['t']), 'series' => $series];
}

/** Median / P95 / peak / suggestion over the last $days of samples. */
function netlimitRecommend(PDO $db, array $cfg, int $days = 7, ?int $now = null): array {
    $now = $now ?? time();
    $days = max(1, min(netlimitKeepDays($cfg), $days));
    $st = $db->prepare("SELECT pps_total FROM `" . NET_SAMPLE_TABLE . "` WHERE ts >= ?");
    $st->execute([$now - $days * 86400]);
    $rec = netlimitRecommendFrom($st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $rec['days'] = $days;
    return $rec;
}

/**
 * Human sentence under the slider — the "explain PPS in plain words" requirement.
 *
 * One thing this must NOT do is pretend the measured arrivals are demand. On a tracker whose stale
 * swarm keeps hammering the port, P95 of arrivals is the flood, and "suggested limit = P95 + 5 %"
 * would mean "a limit that never triggers". The numbers still answer a real question — where a limit
 * stops mattering, and where it starts biting — but the decision the admin is actually making is how
 * much they are willing to hand OpenTracker, so say that instead of implying the peak is a target.
 */
function netlimitRecommendText(array $rec, bool $flood = false, int $passed = 0): string {
    if (!$rec['samples']) {
        return 'No traffic has been recorded yet. Start counting and come back in an hour — the suggestion needs measurements, not guesses.';
    }
    $s = sprintf('Last %d day%s: median %s pps, P95 %s pps, peak %s pps.',
        (int)($rec['days'] ?? 7), ((int)($rec['days'] ?? 7)) === 1 ? '' : 's',
        number_format($rec['median']), number_format($rec['p95']), number_format($rec['peak']));
    if (!$rec['enough']) return $s . ' That is fewer than 60 samples — treat the numbers as a first impression, not a recommendation.';

    // Which sentence comes FIRST decides what the admin reads. When arrivals are not demand — the
    // counting mode drops nothing, or somebody else's rule drops it further down — leading with
    // "a limit at P95 + 5 % would never trigger" hands them a number that means NO LIMIT, and the
    // caveat afterwards arrives too late to stop them using it. So in that state the caveat leads,
    // and the P95 figure is demoted to what it actually is: a description of the flood.
    if ($flood) {
        $s .= ' Those are ARRIVALS, not demand — a tracker whose old swarm keeps calling receives far more than'
            . ' it serves, so a limit anywhere near them would never fire.';
        if ($passed > 0) {
            $s .= sprintf(' What is actually getting through right now is %s pps, and THAT is the number to pick from:'
                . ' choose what you are willing to hand OpenTracker, with some headroom. Packets above it cost you'
                . ' nothing, because the firewall drops them before the tracker ever sees them.',
                number_format($passed));
        } else {
            $s .= ' Pick the number you are willing to hand OpenTracker, not one taken from the arrivals above.'
                . ' Packets over it cost you nothing, because the firewall drops them before the tracker ever sees them.';
        }
        $s .= sprintf(' (For reference, a limit above the arrivals would be around %s pps.)', number_format($rec['suggested']));
        return $s;
    }
    $s .= sprintf(' A limit at %s pps (P95 + 5 %%) would essentially never trigger; below roughly %s pps you start dropping packets that are currently arriving.',
        number_format($rec['suggested']), number_format($rec['floor']));
    return $s;
}
