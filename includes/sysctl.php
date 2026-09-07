<?php
/**
 * Kernel network buffers from the panel (schema v16) — the PHP half of
 * tools/opentracker/tracker-sysctl.sh.
 *
 * ── why this exists ─────────────────────────────────────────────────────────
 *
 * The Traffic page can already show that announces are being thrown away because the UDP socket's
 * queue was full. It could not do anything about it, and the advice it printed — "run this sysctl
 * yourself" — is the worst kind: correct, unexplained, and handing an operator a system-wide change
 * with no measurement behind it and no way back.
 *
 * ── why it is shaped so defensively ─────────────────────────────────────────
 *
 * Everything else the panel touches belongs to the tracker: an nftables table, a systemd drop-in, an
 * accesslist file. These eight keys belong to the machine, and on this class of box that machine
 * also runs mail, a forum, a file service and the database all four depend on. So:
 *
 *   * The panel never writes anything. It records what was asked for; the janitor performs it. That
 *     is not a design preference — php-fpm here runs with ProtectKernelTunables=yes, which makes
 *     /proc/sys read-only inside its mount namespace, for root too, because it is a namespace and
 *     not a permission bit. A helper called from a web request cannot write a sysctl at all.
 *   * A change is ARMED, not applied. Nothing reaches /etc until a human confirms, so until then a
 *     reboot is a complete undo.
 *   * The revert is scheduled through systemd before the change is made, so it does not need PHP,
 *     the database, the janitor timer, or an administrator who can still open a session. That is the
 *     whole point: the failure this guards against is one where nobody can log in to fix it.
 *
 * Units live here and in the JavaScript, never in the operator's hands: the four buffers are bytes
 * entered as KiB/MiB, the backlog is packets, and udp_mem is three byte figures converted to PAGES
 * on the way down. Pages are shown everywhere and typed nowhere.
 */

// The labels, the advice and the validation messages are read by a person, so they go through the
// dictionary. Named here rather than assumed: the janitor and the tests load this file without
// api.php, and __() must exist there too (in CLI it returns English).
require_once __DIR__ . '/lang.php';

/** The whole allow-list. Anything not in this table cannot be read, written or named. */
function sysctlKeys(): array {
    static $k = null;
    if ($k !== null) return $k;
    return $k = [
        'rmem_max' => [
            'sysctl' => 'net.core.rmem_max', 'unit' => 'bytes', 'group' => 'receive',
            'label' => __('api.sysctl.key_rmem_max_label'),
            'what'  => __('api.sysctl.key_rmem_max_what'),
            'min' => 4096, 'max' => 268435456,
        ],
        'rmem_default' => [
            'sysctl' => 'net.core.rmem_default', 'unit' => 'bytes', 'group' => 'receive',
            'label' => __('api.sysctl.key_rmem_default_label'),
            'what'  => __('api.sysctl.key_rmem_default_what'),
            'min' => 4096, 'max' => 268435456, 'ack' => true,
        ],
        'wmem_max' => [
            'sysctl' => 'net.core.wmem_max', 'unit' => 'bytes', 'group' => 'send',
            'label' => __('api.sysctl.key_wmem_max_label'),
            'what'  => __('api.sysctl.key_wmem_max_what'),
            'min' => 4096, 'max' => 268435456,
        ],
        'wmem_default' => [
            'sysctl' => 'net.core.wmem_default', 'unit' => 'bytes', 'group' => 'send',
            'label' => __('api.sysctl.key_wmem_default_label'),
            'what'  => __('api.sysctl.key_wmem_default_what'),
            'min' => 4096, 'max' => 268435456, 'ack' => true,
        ],
        'netdev_max_backlog' => [
            'sysctl' => 'net.core.netdev_max_backlog', 'unit' => 'packets', 'group' => 'queue',
            'label' => __('api.sysctl.key_netdev_max_backlog_label'),
            'what'  => __('api.sysctl.key_netdev_max_backlog_what'),
            'min' => 100, 'max' => 32768,
        ],
        'udp_mem' => [
            'sysctl' => 'net.ipv4.udp_mem', 'unit' => 'pages3', 'group' => 'global',
            'label' => __('api.sysctl.key_udp_mem_label'),
            'what'  => __('api.sysctl.key_udp_mem_what'),
            'min' => 4096, 'max' => 0,
        ],
        'udp_rmem_min' => [
            'sysctl' => 'net.ipv4.udp_rmem_min', 'unit' => 'bytes', 'group' => 'receive',
            'label' => __('api.sysctl.key_udp_rmem_min_label'),
            'what'  => __('api.sysctl.key_udp_rmem_min_what'),
            'min' => 4096, 'max' => 16777216,
        ],
        'udp_wmem_min' => [
            'sysctl' => 'net.ipv4.udp_wmem_min', 'unit' => 'bytes', 'group' => 'send',
            'label' => __('api.sysctl.key_udp_wmem_min_label'),
            'what'  => __('api.sysctl.key_udp_wmem_min_what'),
            'min' => 4096, 'max' => 16777216,
        ],
    ];
}

function sysctlKeyNames(): array { return array_keys(sysctlKeys()); }

/* ── settings ────────────────────────────────────────────────────────────── */

/**
 * Empty means the feature does not exist: no card, no polling, no fork. Deliberately NOT defaulted
 * to a path the way ot_perf_cmd is — that turns every existing install into one that polls an
 * endpoint which shells out to a script nobody installed.
 */
function sysctlCommand(array $cfg): string { return trim((string)($cfg['sysctl_cmd'] ?? '')); }
function sysctlEnabled(array $cfg): bool { return (($cfg['sysctl_enabled'] ?? '0') === '1') && sysctlCommand($cfg) !== ''; }
function sysctlValidCommand(string $cmd): bool {
    $cmd = trim($cmd);
    return $cmd === '' || (bool)preg_match('/^[A-Za-z0-9 _.\/-]{1,255}$/', $cmd);
}
/**
 * The window is clamped to whole minutes with a 60-second floor because the janitor is the coarsest
 * watchdog and it ticks once a minute: promising a 30-second revert it cannot deliver would be
 * exactly the kind of number that reads as a guarantee.
 */
function sysctlConfirmSeconds(array $cfg): int {
    $v = (int)($cfg['sysctl_confirm_seconds'] ?? 120);
    if ($v < 60) $v = 60;
    if ($v > 900) $v = 900;
    return (int)(round($v / 60) * 60);
}

/* ── running the helper ──────────────────────────────────────────────────── */

function sysctlRun(array $cfg, array $args): array {
    $out = ['ok' => false, 'json' => null, 'output' => '', 'code' => null, 'error' => null];
    $cmd = sysctlCommand($cfg);
    if ($cmd === '') { $out['error'] = __('api.sysctl.run_no_helper'); return $out; }
    if (!sysctlValidCommand($cmd)) { $out['error'] = __('api.sysctl.run_bad_command'); return $out; }
    if (!trackerExecAvailable()) { $out['error'] = __('api.sysctl.run_exec_disabled'); return $out; }

    $full = $cmd;
    foreach ($args as $a) $full .= ' ' . escapeshellarg((string)$a);
    $full .= ' 2>&1';
    $lines = []; $rc = null;
    @exec($full, $lines, $rc);
    $out['code'] = $rc === null ? null : (int)$rc;
    $out['output'] = trim(implode("\n", $lines));
    // The reply is the LAST single-line JSON object, so a noisy sudo cannot make a working helper
    // look broken.
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $l = trim((string)$lines[$i]);
        if ($l === '' || $l[0] !== '{') continue;
        $j = json_decode($l, true);
        if (is_array($j)) { $out['json'] = $j; break; }
    }
    if ($out['json'] === null) {
        $out['error'] = $out['output'] !== ''
            ? __('api.sysctl.run_no_json', ['out' => mb_substr($out['output'], 0, 300)])
            : __('api.sysctl.run_no_output', ['code' => (int)$rc]);
        return $out;
    }
    $out['ok'] = !empty($out['json']['ok']) && $out['code'] === 0;
    if (!$out['ok'] && $out['error'] === null) $out['error'] = (string)($out['json']['error'] ?? __('api.sysctl.run_exit_code', ['code' => (int)$rc]));
    return $out;
}

/** Status is polled by a card; one helper call per request is enough. */
function sysctlStatus(array $cfg, int $port = 6969): array {
    $r = sysctlRun($cfg, ['status', (string)$port]);
    if (!$r['ok'] || !is_array($r['json'])) return ['ok' => false, 'error' => $r['error'] ?? __('api.sysctl.unknown_error'), 'output' => $r['output']];
    return $r['json'];
}

/* ── validation, mirrored from the helper so nothing invalid reaches root ─── */

/**
 * Returns '' when the value is acceptable, or the reason it is not.
 *
 * Mirrored rather than delegated on purpose: the helper is the security boundary and validates
 * again, but an endpoint that only finds out a value is nonsense by running a root script has
 * already made the decision it should have refused.
 */
function sysctlValidate(string $key, string $value, array $st): string {
    $keys = sysctlKeys();
    if (!isset($keys[$key])) return 'unknown key: ' . $key;
    $k = $keys[$key];
    $memKb = (int)($st['mem_total_kb'] ?? 0);
    $pageSize = max(1, (int)($st['page_size'] ?? 4096));
    $cpus = max(1, (int)($st['cpus'] ?? 1));

    if ($k['unit'] === 'pages3') {
        $parts = preg_split('/\s+/', trim($value));
        if (count($parts) !== 3) return __('api.sysctl.udp_mem_three');
        foreach ($parts as $p) if (!ctype_digit($p)) return __('api.sysctl.udp_mem_digits');
        [$a, $b, $c] = array_map('intval', $parts);
        if (!($a < $b && $b < $c)) return __('api.sysctl.udp_mem_increasing');
        if ($memKb > 0) {
            $totalPages = (int)($memKb * 1024 / $pageSize);
            // The ceiling cannot be a flat fraction of RAM, because the kernel's OWN defaults are a
            // large one: on the reference machine it chose min 9.3%, pressure 12.4%, max 18.6% of
            // memory at boot. A rule that refused those would refuse the factory setting and read as
            // a bug. So each limit is "twice what is already in force, or this fraction of RAM,
            // whichever is more generous" — that allows a considered doubling and still refuses the
            // value people copy from tuning guides, which on this machine is more than its total RAM.
            $ref = preg_split('/\s+/', trim((string)(($st['baseline']['values']['udp_mem'] ?? '')
                                                     ?: ($st['values']['udp_mem'] ?? ''))));
            $refA = count($ref) === 3 && ctype_digit($ref[0]) ? (int)$ref[0] : 0;
            $refB = count($ref) === 3 && ctype_digit($ref[1]) ? (int)$ref[1] : 0;
            $refC = count($ref) === 3 && ctype_digit($ref[2]) ? (int)$ref[2] : 0;
            $capA = max((int)($totalPages / 100), $refA * 2);
            $capB = max((int)($totalPages / 10),  $refB * 2);
            $capC = max((int)($totalPages / 4),   $refC * 2);
            if ($a > $capA) {
                return __('api.sysctl.udp_mem_min_high', ['pages' => number_format($a), 'bytes' => sysctlHumanBytes($a * $pageSize), 'cap' => number_format($capA)]);
            }
            if ($b > $capB) return __('api.sysctl.udp_mem_pressure_high', ['cap' => number_format($capB)]);
            if ($c > $capC) {
                return __('api.sysctl.udp_mem_max_high', ['pages' => number_format($c), 'bytes' => sysctlHumanBytes($c * $pageSize), 'ram' => sysctlHumanBytes($memKb * 1024)]);
            }
        }
        return '';
    }

    if (!ctype_digit(trim($value))) return __('api.sysctl.not_integer', ['label' => $k['label']]);
    $v = (int)$value;
    if ($v < $k['min']) return __('api.sysctl.below_min', ['label' => $k['label'], 'min' => number_format($k['min'])]);
    if ($k['max'] > 0 && $v > $k['max']) {
        if ($key === 'netdev_max_backlog') {
            return __('api.sysctl.backlog_too_high', ['max' => number_format($k['max']), 'cpus' => $cpus, 'total' => number_format($k['max'] * $cpus)]);
        }
        return __('api.sysctl.above_max', ['label' => $k['label'], 'max' => number_format($k['max'])]);
    }
    if ($k['unit'] === 'bytes' && $memKb > 0 && $v > (int)($memKb * 1024 / 8)) {
        return __('api.sysctl.eighth_of_ram', ['label' => $k['label']]);
    }
    return '';
}

/** A step this large is almost always a unit mistake or a copied recipe, so it is called out. */
function sysctlBigStep(string $key, string $wanted, string $current): bool {
    if (sysctlKeys()[$key]['unit'] === 'pages3') return false;
    $a = (int)$current; $b = (int)$wanted;
    return $a > 0 && $b > $a * 4;
}

/* ── state: what the panel wants, and what it has asked the janitor to do ─── */

function sysctlState(): array {
    $s = netlimitStateRead();
    return is_array($s['sysctl'] ?? null) ? $s['sysctl'] : [];
}

/**
 * The whole sub-array is replaced, never merged. netlimitStateRead() merges recursively over its
 * defaults, so mutating one key at a time makes removals sticky: a value the operator stopped
 * managing would quietly come back on the next read.
 */
function sysctlStateSet(array $sub): void {
    netlimitStateUpdate(function (array &$s) use ($sub) { $s['sysctl'] = $sub; return true; });
}

function sysctlRequest(string $op, array $extra = []): void {
    $s = sysctlState();
    $s['request'] = array_merge(['op' => $op, 'at' => time()], $extra);
    sysctlStateSet($s);
}

/* ── unit conversion, in one place ───────────────────────────────────────── */

function sysctlPagesToBytes(int $pages, int $pageSize): int { return $pages * max(1, $pageSize); }
function sysctlBytesToPages(int $bytes, int $pageSize): int { return (int)floor($bytes / max(1, $pageSize)); }

/** "8 MiB", "1.06 GiB", "208 KiB" — the form a human can check against what they meant to type. */
function sysctlHumanBytes($bytes): string {
    $b = (float)$bytes;
    if ($b <= 0) return '0 B';
    $u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    if ($b >= 100 || $i === 0) return number_format($b, 0) . ' ' . $u[$i];
    // Trailing zeros make two identical numbers look different: "8 MiB" is what the operator typed,
    // "8.00 MiB" is what a formatter produced.
    return rtrim(rtrim(number_format($b, 2, '.', ''), '0'), '.') . ' ' . $u[$i];
}

/* ── the part that earns the word "intelligent" ──────────────────────────── */

/**
 * Does this tracker ever ask the kernel for a bigger receive buffer?
 *
 * Nobody checks this by hand, and getting it wrong sends an operator down the expensive path for
 * nothing. The kernel stores sk_rcvbuf = 2 x min(request, rmem_max), so a socket sitting at exactly
 * rmem_default never called setsockopt at all — and for that program, raising the CEILING changes
 * precisely nothing, however many guides say otherwise. The only lever left is the default, which is
 * the one that applies to every socket on the machine.
 *
 * Measured on the reference deployment: rb = 212992 = rmem_default, while 2 x rmem_max would have
 * been 425984. opentracker does not ask.
 */
function sysctlSocketVerdict(array $st): array {
    $rb = (int)($st['socket']['rb'] ?? 0);
    $vals = (array)($st['values'] ?? []);
    $rmemMax = (int)($vals['rmem_max'] ?? 0);
    $rmemDef = (int)($vals['rmem_default'] ?? 0);
    if ($rb <= 0 || $rmemMax <= 0) return ['known' => false];
    if ($rb === $rmemDef && $rb !== 2 * $rmemMax) {
        return [
            'known' => true, 'asks' => false, 'rb' => $rb,
            'text' => __('api.sysctl.verdict_no_ask', ['rb' => sysctlHumanBytes($rb)]),
        ];
    }
    if ($rb >= 2 * $rmemMax) {
        return [
            'known' => true, 'asks' => true, 'rb' => $rb,
            'text' => __('api.sysctl.verdict_clamped', ['rb' => sysctlHumanBytes($rb)]),
        ];
    }
    // The socket is SMALLER than the current default, so no socket created now could look like this.
    //
    // This is the case the verdict used to miss, and it mattered: it fell through to "asks for its
    // own size", which tells the operator the buffers are none of their business — the exact opposite
    // of the truth. A socket's receive buffer is fixed when the socket is CREATED. Change
    // rmem_default afterwards and every socket already open keeps the size it was born with, so the
    // setting is live in the kernel and invisible to the tracker until the service restarts.
    //
    // Seen on this machine: rmem_default raised to 8 MiB, the tracker socket still at 208 KiB two
    // days later, and 43.6 million packets discarded by that undersized queue in the meantime.
    if ($rmemDef > 0 && $rb < $rmemDef) {
        return [
            'known' => true, 'asks' => false, 'stale' => true, 'rb' => $rb,
            'text' => __('api.sysctl.verdict_stale', ['rb' => sysctlHumanBytes($rb), 'def' => sysctlHumanBytes($rmemDef)]),
        ];
    }

    return ['known' => true, 'asks' => true, 'rb' => $rb,
            'text' => __('api.sysctl.verdict_fine', ['rb' => sysctlHumanBytes($rb)])];
}

/**
 * Is every inbound packet being processed by ONE core?
 *
 * A virtio NIC on a VPS usually has a single RX queue, and a single queue means a single interrupt,
 * which means one core does all the softirq work no matter how many cores the machine has. Receive
 * Packet Steering spreads that work in software; with `rps_cpus` at 0 it is off.
 *
 * This matters here in a way the other numbers do not show: the tracker's own load looks tiny (one
 * instance at ~92% of 600%), the per-CPU queue never overflows, and yet raising the inbound limit
 * makes OTHER services on the box stutter. That is what a saturated single core looks like from the
 * outside — it is not bandwidth, and it is not the tracker's threads.
 *
 * Read straight from /proc and /sys, both world-readable, with every step allowed to fail: a panel
 * that cannot read them says so rather than guessing. Returns null when nothing could be measured.
 */
/**
 * The exact command that would spread receive processing, for this machine.
 *
 * The mask is built from the real core count rather than printed as a placeholder: an operator who
 * has to work out that six cores means 3f is an operator who will get it wrong once. Every interface
 * with a receive queue is listed, because a machine with two would otherwise have half the fix.
 */
function sysctlRpsCommand(int $cpus): string {
    $mask = dechex((1 << max(1, min(32, $cpus))) - 1);
    $lines = [];
    foreach ((@glob('/sys/class/net/*/queues/rx-*/rps_cpus') ?: []) as $f) {
        if (strpos($f, '/lo/') !== false) continue;      // loopback needs no steering
        $lines[] = 'echo ' . $mask . ' > ' . $f;
    }
    if (!$lines) $lines[] = 'echo ' . $mask . ' > /sys/class/net/<iface>/queues/rx-0/rps_cpus';
    return implode("
", $lines);
}

function sysctlPacketSpread(): ?array {
    $raw = @file_get_contents('/proc/net/softnet_stat');
    if (!is_string($raw) || trim($raw) === '') return null;

    $perCpu = [];
    foreach (preg_split('/\R/', trim($raw)) as $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (!$cols || !isset($cols[0])) continue;
        $perCpu[] = (int)hexdec($cols[0]);       // column 1 = packets processed
    }
    if (count($perCpu) < 2) return null;

    $total = array_sum($perCpu);
    if ($total <= 0) return null;
    $top = max($perCpu);
    $share = $top / $total;

    // Is RPS switched on for any receive queue? An all-zero mask means no.
    $rpsOn = false;
    $queues = @glob('/sys/class/net/*/queues/rx-*/rps_cpus') ?: [];
    foreach ($queues as $f) {
        $mask = trim((string)@file_get_contents($f));
        if ($mask !== '' && preg_replace('/[0,\s]/', '', $mask) !== '') { $rpsOn = true; break; }
    }

    return [
        'cpus'      => count($perCpu),
        'busiest'   => (int)array_search($top, $perCpu, true),
        'share'     => $share,
        'rps_on'    => $rpsOn,
        'queues'    => count($queues),
        'concentrated' => $share >= 0.9 && count($perCpu) >= 2,
    ];
}

/**
 * Advice, and the measurement that would falsify each piece of it. Nothing here is folklore: every
 * suggestion names the counter it came from, and a suggestion whose counter is flat is not made.
 */
function sysctlAdvice(array $st, array $cfg): array {
    $out = [];
    $vals = (array)($st['values'] ?? []);
    $pageSize = max(1, (int)($st['page_size'] ?? 4096));
    $memKb = (int)($st['mem_total_kb'] ?? 0);
    $cpus = max(1, (int)($st['cpus'] ?? 1));

    if (empty($st['netns_shared'])) {
        $out[] = ['level' => 'bad', 'text' => __('api.sysctl.adv_netns')];
    }

    $verdict = sysctlSocketVerdict($st);
    if (!empty($verdict['known'])) {
        $out[] = ['level' => empty($verdict['asks']) ? 'warn' : 'info', 'text' => $verdict['text']];
    }

    $drops = (int)($st['socket']['drops'] ?? 0);
    if ($drops > 0) {
        $out[] = ['level' => 'warn', 'text' => __('api.sysctl.adv_socket_drops', ['n' => number_format($drops)])];
    }

    // netdev_max_backlog gets a suggestion only when the counter that justifies it has moved.
    $softDrop = (int)($st['softnet_dropped'] ?? 0);
    if ($softDrop === 0) {
        $out[] = ['level' => 'info', 'text' => __('api.sysctl.adv_softnet_zero', ['cpus' => $cpus])];
    } else {
        $out[] = ['level' => 'warn', 'text' => __('api.sysctl.adv_softnet_over', ['n' => number_format($softDrop), 'cpus' => $cpus])];
    }

    // One core doing all the receive work is invisible in every other number on this page.
    $spread = sysctlPacketSpread();
    if ($spread !== null && $spread['concentrated']) {
        $pct = round($spread['share'] * 100);
        $out[] = ['level' => 'warn', 'text' => __('api.sysctl.adv_one_core', [
                'busiest' => $spread['busiest'], 'pct' => $pct, 'cpus' => $spread['cpus'],
                'rps'  => $spread['rps_on'] ? __('api.sysctl.adv_rps_on') : __('api.sysctl.adv_rps_off'),
                'hint' => $spread['rps_on'] ? '' : __('api.sysctl.adv_rps_hint')]),
            'command' => $spread['rps_on'] ? null : sysctlRpsCommand($spread['cpus'])];
    } elseif ($spread !== null && !$spread['concentrated']) {
        $out[] = ['level' => 'info', 'text' => __('api.sysctl.adv_spread_ok', ['cpus' => $spread['cpus'], 'pct' => round($spread['share'] * 100)])];
    }

    // udp_mem only matters when the global pool is actually being approached.
    $used = (int)($st['udp_pages_used'] ?? 0);
    $mem = preg_split('/\s+/', trim((string)($vals['udp_mem'] ?? '')));
    if (count($mem) === 3 && ctype_digit($mem[1])) {
        $pressure = (int)$mem[1];
        if ($pressure > 0 && $used < (int)($pressure / 10)) {
            $out[] = ['level' => 'info', 'text' => __('api.sysctl.adv_udp_mem_fine', [
                'used' => number_format($used), 'used_bytes' => sysctlHumanBytes(sysctlPagesToBytes($used, $pageSize)),
                'pressure' => number_format($pressure), 'pressure_bytes' => sysctlHumanBytes(sysctlPagesToBytes($pressure, $pageSize))])];
        } elseif ($pressure > 0) {
            $out[] = ['level' => 'warn', 'text' => __('api.sysctl.adv_udp_mem_near', ['used' => number_format($used), 'pressure' => number_format($pressure)])];
        }
    }

    if (!empty($st['conflicts'])) {
        $out[] = ['level' => 'warn', 'text' => __('api.sysctl.adv_conflicts', ['files' => implode(', ', array_map('strval', (array)$st['conflicts']))])];
    }

    if (empty($st['systemd_run'])) {
        $out[] = ['level' => 'warn', 'text' => __('api.sysctl.adv_no_systemd_run')];
    }

    if ($memKb > 0) {
        $out[] = ['level' => 'info', 'text' => __('api.sysctl.adv_machine', ['ram' => sysctlHumanBytes($memKb * 1024), 'cpus' => $cpus, 'page' => number_format($pageSize)])];
    }

    if (netlimitAutoEnabled($cfg)) {
        $out[] = ['level' => 'bad', 'text' => __('api.sysctl.adv_auto_limiter')];
    }

    return $out;
}

/**
 * What the panel would set, given what it has measured. Only ever a suggestion, and only where a
 * counter supports it — the keys with no local evidence get no number.
 */
function sysctlSuggest(array $st): array {
    $out = [];
    $vals = (array)($st['values'] ?? []);
    $drops = (int)($st['socket']['drops'] ?? 0);
    $verdict = sysctlSocketVerdict($st);
    $memKb = (int)($st['mem_total_kb'] ?? 0);

    if ($drops > 0) {
        // 8 MiB is roughly a second of the traffic this class of tracker sees, which is the useful
        // size for a burst: long enough to ride out a scheduling delay, short enough that a queue
        // that deep means something else is wrong.
        $target = 8 * 1024 * 1024;
        if ($memKb > 0 && $target > (int)($memKb * 1024 / 32)) $target = (int)($memKb * 1024 / 32);
        $out['rmem_max'] = ['value' => (string)$target,
            'why' => __('api.sysctl.why_rmem_max')];
        if (!empty($verdict['known']) && empty($verdict['asks'])) {
            $out['rmem_default'] = ['value' => (string)$target,
                'why' => __('api.sysctl.why_rmem_default')];
        }
        $out['udp_rmem_min'] = ['value' => '16384',
            'why' => __('api.sysctl.why_udp_rmem_min')];
    }
    return $out;
}

/* ── the janitor's half ──────────────────────────────────────────────────── */

/**
 * Performs whatever the panel asked for, and puts the old values back when an armed change was not
 * confirmed in time.
 *
 * Runs only from tools/janitor.php. It is the ONLY place a value is written, because it is the only
 * context on this machine that can write one: php-fpm's ProtectKernelTunables makes /proc/sys
 * read-only for the web path, sudo included.
 */
function sysctlTick(array $cfg): array {
    $out = ['did' => null, 'ok' => null, 'error' => null, 'reverted' => false];
    if (PHP_SAPI !== 'cli') return $out;          // never from a web request, whatever calls this
    $s = sysctlState();
    $req = is_array($s['request'] ?? null) ? $s['request'] : null;
    $armed = is_array($s['armed'] ?? null) ? $s['armed'] : null;
    $cmdSet = sysctlCommand($cfg) !== '';

    // A pending revert is honoured even with the feature switched off: turning a setting off must
    // never strand a change that is waiting to be undone.
    if ($cmdSet && $req && ($req['op'] ?? '') === 'revert') {
        $r = sysctlRun($cfg, ['revert']);
        $s = sysctlState();
        unset($s['request'], $s['armed']);
        if (!$r['ok'] && !empty($r['json']['unpersist_deferred'])) $s['unpersist_deferred'] = true;
        sysctlStateSet($s);
        return ['did' => 'revert', 'ok' => $r['ok'], 'error' => $r['error'], 'reverted' => true];
    }

    if (!$cmdSet) return $out;

    if ($req && ($req['op'] ?? '') === 'arm') {
        $args = array_merge(['arm', (string)($req['seconds'] ?? 120), (string)($req['nonce'] ?? '')],
                            (array)($req['pairs'] ?? []));
        $r = sysctlRun($cfg, $args);
        $s = sysctlState();
        unset($s['request']);
        if ($r['ok']) {
            $s['armed'] = [
                'nonce'    => (string)($req['nonce'] ?? ''),
                'deadline' => (int)($r['json']['deadline'] ?? (time() + 120)),
                'watchdog' => (string)($r['json']['watchdog'] ?? 'none'),
                'keys'     => (array)($r['json']['keys'] ?? []),
                'all_landed' => !empty($r['json']['all_landed']),
            ];
            $s['last_error'] = null;
        } else {
            $s['last_error'] = $r['error'];
        }
        sysctlStateSet($s);
        return ['did' => 'arm', 'ok' => $r['ok'], 'error' => $r['error'], 'reverted' => false];
    }

    if ($req && ($req['op'] ?? '') === 'confirm') {
        $r = sysctlRun($cfg, ['confirm', (string)($req['nonce'] ?? '')]);
        $s = sysctlState();
        unset($s['request']);
        if ($r['ok'] && empty($r['json']['deferred'])) {
            unset($s['armed']);
            $s['persisted_at'] = time();
            $s['last_error'] = null;
        } elseif ($r['ok']) {
            $s['request'] = ['op' => 'confirm', 'nonce' => (string)($req['nonce'] ?? ''), 'at' => time()];
        } else {
            $s['last_error'] = $r['error'];
        }
        sysctlStateSet($s);
        return ['did' => 'confirm', 'ok' => $r['ok'], 'error' => $r['error'], 'reverted' => false];
    }

    // The backstop. systemd should have fired its own revert already; this catches the case where it
    // could not be scheduled, and it is why the card shows the janitor's granularity in the countdown
    // rather than the nominal window.
    if ($armed && time() > (int)($armed['deadline'] ?? 0)) {
        $r = sysctlRun($cfg, ['revert']);
        $s = sysctlState();
        unset($s['armed'], $s['request']);
        $s['last_revert_at'] = time();
        $s['last_revert_reason'] = 'not confirmed in time';
        sysctlStateSet($s);
        return ['did' => 'watchdog', 'ok' => $r['ok'], 'error' => $r['error'], 'reverted' => true];
    }

    return $out;
}
