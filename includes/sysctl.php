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

/** The whole allow-list. Anything not in this table cannot be read, written or named. */
function sysctlKeys(): array {
    static $k = null;
    if ($k !== null) return $k;
    return $k = [
        'rmem_max' => [
            'sysctl' => 'net.core.rmem_max', 'unit' => 'bytes', 'group' => 'receive',
            'label' => 'Receive buffer ceiling',
            'what'  => 'The most a socket may be given, IF it asks. Raising this allocates nothing by '
                     . 'itself — only a program that calls setsockopt(SO_RCVBUF) ever sees the difference.',
            'min' => 4096, 'max' => 268435456,
        ],
        'rmem_default' => [
            'sysctl' => 'net.core.rmem_default', 'unit' => 'bytes', 'group' => 'receive',
            'label' => 'Receive buffer given to every socket',
            'what'  => 'What a socket gets without asking. This is the one that costs: it applies to '
                     . 'every socket created afterwards that does not set its own size. TCP is not '
                     . 'affected — it has its own net.ipv4.tcp_rmem — so in practice this is UDP.',
            'min' => 4096, 'max' => 268435456, 'ack' => true,
        ],
        'wmem_max' => [
            'sysctl' => 'net.core.wmem_max', 'unit' => 'bytes', 'group' => 'send',
            'label' => 'Send buffer ceiling',
            'what'  => 'The same as the receive ceiling, on the way out. Nothing measured here has '
                     . 'ever pointed at the send side; it is offered for symmetry, not as a claim.',
            'min' => 4096, 'max' => 268435456,
        ],
        'wmem_default' => [
            'sysctl' => 'net.core.wmem_default', 'unit' => 'bytes', 'group' => 'send',
            'label' => 'Send buffer given to every socket',
            'what'  => 'Same blast radius as the receive default, and the same absence of evidence. '
                     . 'If you are here to stop announces being dropped, this is not the knob.',
            'min' => 4096, 'max' => 268435456, 'ack' => true,
        ],
        'netdev_max_backlog' => [
            'sysctl' => 'net.core.netdev_max_backlog', 'unit' => 'packets', 'group' => 'queue',
            'label' => 'Packets queued before the kernel starts dropping',
            'what'  => 'PER CPU. Lengthening this under a flood does not save the packets — the '
                     . 'firewall drops them a few milliseconds later anyway — it just makes every '
                     . 'other packet on the machine wait behind them. This is the knob that makes an '
                     . 'interactive SSH session stutter.',
            'min' => 100, 'max' => 32768,
        ],
        'udp_mem' => [
            'sysctl' => 'net.ipv4.udp_mem', 'unit' => 'pages3', 'group' => 'global',
            'label' => 'Total memory UDP may use (min / pressure / max)',
            'what'  => 'A machine-wide ceiling for ALL UDP sockets together, counted in pages. Below '
                     . '"min" the kernel never reclaims, so min is memory promised away rather than a '
                     . 'limit; above "max" it refuses. Under a flood the kernel really will take what '
                     . 'max allows, and the database is on the same machine.',
            'min' => 4096, 'max' => 0,
        ],
        'udp_rmem_min' => [
            'sysctl' => 'net.ipv4.udp_rmem_min', 'unit' => 'bytes', 'group' => 'receive',
            'label' => 'Receive floor guaranteed to each UDP socket',
            'what'  => 'How much a UDP socket keeps even when the total above is under pressure. '
                     . 'Small and cheap; raising it protects the tracker from being squeezed when '
                     . 'something else on the box floods UDP.',
            'min' => 4096, 'max' => 16777216,
        ],
        'udp_wmem_min' => [
            'sysctl' => 'net.ipv4.udp_wmem_min', 'unit' => 'bytes', 'group' => 'send',
            'label' => 'Send floor guaranteed to each UDP socket',
            'what'  => 'The same on the way out.',
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
    if ($cmd === '') { $out['error'] = 'No kernel-buffer helper is configured (Settings → Kernel network buffers).'; return $out; }
    if (!sysctlValidCommand($cmd)) { $out['error'] = 'The helper command contains characters that are not allowed.'; return $out; }
    if (!trackerExecAvailable()) { $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the helper.'; return $out; }

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
            ? 'The helper did not answer with JSON: ' . mb_substr($out['output'], 0, 300)
            : 'The helper produced no output (exit ' . (int)$rc . '). Check the sudoers rule.';
        return $out;
    }
    $out['ok'] = !empty($out['json']['ok']) && $out['code'] === 0;
    if (!$out['ok'] && $out['error'] === null) $out['error'] = (string)($out['json']['error'] ?? ('Helper exited with code ' . (int)$rc));
    return $out;
}

/** Status is polled by a card; one helper call per request is enough. */
function sysctlStatus(array $cfg, int $port = 6969): array {
    $r = sysctlRun($cfg, ['status', (string)$port]);
    if (!$r['ok'] || !is_array($r['json'])) return ['ok' => false, 'error' => $r['error'] ?? 'unknown error', 'output' => $r['output']];
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
        if (count($parts) !== 3) return 'udp_mem needs exactly three numbers: min, pressure and max.';
        foreach ($parts as $p) if (!ctype_digit($p)) return 'udp_mem values must be whole page counts.';
        [$a, $b, $c] = array_map('intval', $parts);
        if (!($a < $b && $b < $c)) return 'udp_mem must be strictly increasing: min < pressure < max.';
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
                return 'udp_mem min is too high for this machine (' . number_format($a) . ' pages, '
                     . sysctlHumanBytes($a * $pageSize) . '). Below min the kernel never reclaims UDP '
                     . 'memory, so that is memory promised away permanently rather than a ceiling — the '
                     . 'most this panel will accept here is ' . number_format($capA) . ' pages.';
            }
            if ($b > $capB) return 'udp_mem pressure is too high for this machine (max ' . number_format($capB) . ' pages).';
            if ($c > $capC) {
                return 'udp_mem max is too high for this machine (' . number_format($c) . ' pages, '
                     . sysctlHumanBytes($c * $pageSize) . ' against ' . sysctlHumanBytes($memKb * 1024)
                     . ' of RAM). Under a flood the kernel really will take it, and the database is on '
                     . 'this same machine.';
            }
        }
        return '';
    }

    if (!ctype_digit(trim($value))) return $k['label'] . ': must be a whole number.';
    $v = (int)$value;
    if ($v < $k['min']) return $k['label'] . ': below ' . number_format($k['min']) . '.';
    if ($k['max'] > 0 && $v > $k['max']) {
        if ($key === 'netdev_max_backlog') {
            return 'The queue length is per CPU: ' . number_format($k['max']) . ' on ' . $cpus
                 . ' cores is already ' . number_format($k['max'] * $cpus) . ' packets buffered ahead '
                 . 'of the firewall. Anything larger is refused.';
        }
        return $k['label'] . ': above ' . number_format($k['max']) . '.';
    }
    if ($k['unit'] === 'bytes' && $memKb > 0 && $v > (int)($memKb * 1024 / 8)) {
        return $k['label'] . ': more than an eighth of this machine\'s RAM in one socket buffer — did you mean KiB?';
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
            'text' => 'This tracker never asks the kernel for a bigger receive buffer: its socket is '
                    . 'sitting at exactly the system default (' . sysctlHumanBytes($rb) . '), not at '
                    . 'twice the ceiling, which is where it would be if it had asked. So raising the '
                    . 'ceiling alone will change nothing at all here — the only knob that moves this '
                    . 'socket is the default, and that one applies to every socket on the machine.',
        ];
    }
    if ($rb >= 2 * $rmemMax) {
        return [
            'known' => true, 'asks' => true, 'rb' => $rb,
            'text' => 'This tracker does ask for a bigger receive buffer and is being clamped by the '
                    . 'ceiling (its socket is at ' . sysctlHumanBytes($rb) . ', exactly twice '
                    . 'net.core.rmem_max — the kernel doubles what a program requests). Raising the '
                    . 'ceiling is the cheap fix here: it allocates nothing by itself and touches no '
                    . 'other socket.',
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
            'text' => 'This socket is SMALLER than the current default (' . sysctlHumanBytes($rb)
                    . ' against ' . sysctlHumanBytes($rmemDef) . '), and no socket created now could '
                    . 'be. A receive buffer is fixed when the socket is opened, so the tracker is '
                    . 'still using the value that was in force when it last started — your change is '
                    . 'live in the kernel and has not reached this socket. '
                    . 'RESTART THE TRACKER and this socket becomes ' . sysctlHumanBytes($rmemDef)
                    . '; until then the discarded-packet counter will keep climbing for the old reason.',
        ];
    }

    return ['known' => true, 'asks' => true, 'rb' => $rb,
            'text' => 'This tracker asks for its own receive buffer size (' . sysctlHumanBytes($rb)
                    . ') and is not being clamped by the ceiling.'];
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
        $out[] = ['level' => 'bad', 'text' =>
            'This process is in a private network namespace, so writing any of these would change a '
            . 'copy of the network stack that nothing else on the machine can see. The panel refuses '
            . 'to arm from here rather than report a change that did not happen.'];
    }

    $verdict = sysctlSocketVerdict($st);
    if (!empty($verdict['known'])) {
        $out[] = ['level' => empty($verdict['asks']) ? 'warn' : 'info', 'text' => $verdict['text']];
    }

    $drops = (int)($st['socket']['drops'] ?? 0);
    if ($drops > 0) {
        $out[] = ['level' => 'warn', 'text' =>
            'The tracker socket has discarded ' . number_format($drops) . ' packets because its queue '
            . 'was full. That is the one loss on this page that the buffers below can actually fix — '
            . 'a packet dropped there cost the machine everything except the answer.'];
    }

    // netdev_max_backlog gets a suggestion only when the counter that justifies it has moved.
    $softDrop = (int)($st['softnet_dropped'] ?? 0);
    if ($softDrop === 0) {
        $out[] = ['level' => 'info', 'text' =>
            'The per-CPU packet queue has never overflowed on this machine (softnet dropped = 0 across '
            . 'all ' . $cpus . ' cores), so there is nothing to gain from lengthening it — and it is '
            . 'the change most likely to make an SSH session stutter, because every packet then waits '
            . 'behind a longer queue. Leave it alone.'];
    } else {
        $out[] = ['level' => 'warn', 'text' =>
            'The per-CPU packet queue has overflowed ' . number_format($softDrop) . ' times. This is '
            . 'the only measurement that justifies raising it — and remember the value is per CPU, so '
            . 'on ' . $cpus . ' cores it multiplies.'];
    }

    // udp_mem only matters when the global pool is actually being approached.
    $used = (int)($st['udp_pages_used'] ?? 0);
    $mem = preg_split('/\s+/', trim((string)($vals['udp_mem'] ?? '')));
    if (count($mem) === 3 && ctype_digit($mem[1])) {
        $pressure = (int)$mem[1];
        if ($pressure > 0 && $used < (int)($pressure / 10)) {
            $out[] = ['level' => 'info', 'text' =>
                'All UDP sockets on this machine together are using ' . number_format($used) . ' pages '
                . '(' . sysctlHumanBytes(sysctlPagesToBytes($used, $pageSize)) . ') against a pressure '
                . 'threshold of ' . number_format($pressure) . ' pages '
                . '(' . sysctlHumanBytes(sysctlPagesToBytes($pressure, $pageSize)) . '). Nothing is '
                . 'anywhere near it, so raising udp_mem would change nothing — the values circulated in '
                . 'tuning guides are usually a larger fraction of RAM than the machine has.'];
        } elseif ($pressure > 0) {
            $out[] = ['level' => 'warn', 'text' =>
                'UDP memory is within reach of the pressure threshold (' . number_format($used) . ' of '
                . number_format($pressure) . ' pages). This is the measurement that justifies raising udp_mem.'];
        }
    }

    if (!empty($st['conflicts'])) {
        $out[] = ['level' => 'warn', 'text' =>
            'These keys are also set in: ' . implode(', ', array_map('strval', (array)$st['conflicts']))
            . '. A file sorting after the panel\'s own wins at the next boot, so a change can look '
            . 'permanent for weeks and then evaporate at a reboot nobody connects to it.'];
    }

    if (empty($st['systemd_run'])) {
        $out[] = ['level' => 'warn', 'text' =>
            'systemd-run is not available, so an armed change could only be undone by the janitor '
            . 'timer. If that timer is not running, nothing will put the old values back for you.'];
    }

    if ($memKb > 0) {
        $out[] = ['level' => 'info', 'text' =>
            'This machine has ' . sysctlHumanBytes($memKb * 1024) . ' of memory and ' . $cpus
            . ' cores; the page size is ' . number_format($pageSize) . ' bytes, which is what udp_mem '
            . 'is counted in. Every limit below is checked against those numbers rather than against a '
            . 'recommended value from somewhere else.'];
    }

    if (netlimitAutoEnabled($cfg)) {
        $out[] = ['level' => 'bad', 'text' =>
            'The automatic inbound limiter is on. It tightens when the machine\'s load rises — and '
            . 'processing packets you previously dropped raises exactly that load, so it would read '
            . 'this change as distress and ratchet the tracker\'s own budget down. Turn it off before '
            . 'arming anything here.'];
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
            'why' => 'so nothing clamps a program that does ask, now or after an upgrade'];
        if (!empty($verdict['known']) && empty($verdict['asks'])) {
            $out['rmem_default'] = ['value' => (string)$target,
                'why' => 'this tracker never asks, so the default is the only knob that reaches its socket'];
        }
        $out['udp_rmem_min'] = ['value' => '16384',
            'why' => 'a floor each UDP socket keeps when the machine is under memory pressure'];
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
