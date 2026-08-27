<?php
/**
 * Extra opentracker instances (schema v17) — the panel half of tools/opentracker/tracker-cluster.sh.
 *
 * ── what this is for, and what it is not ────────────────────────────────────
 *
 * A tracker whose UDP worker threads are genuinely saturated has run out of the cheap fixes: the
 * worker count, the scheduling priority, the socket buffers. A second instance on a second port is
 * the next step, and this is the machinery for it.
 *
 * It is measured before it is built, and on the reference deployment the measurement says no: one
 * instance uses about a sixth of the machine with its busiest worker at a quarter of a core. So this
 * ships OFF, inert, and with the Traffic card's own verdict telling an operator whether it would help
 * them. That is the honest shape for a feature whose whole justification is a number.
 *
 * ── three decisions that keep it cheap instead of dangerous ─────────────────
 *
 * 1. THE PANEL KEEPS NO ROSTER. There is no table and no per-instance settings row: systemd and the
 *    filesystem already hold the truth, and a second copy is a thing that drifts and survives
 *    teardown. Three settings rows is the entire database cost.
 * 2. ONE MODE, ONE BINARY. Every instance executes the same shared symlink and reads the same
 *    accesslist the janitor already generates, so there is still exactly one `tracker_mode` and
 *    instances cannot disagree about which build they are running.
 * 3. THE FAN-OUT NEVER RUNS IN A WEB REQUEST. whitelistJanitor() is called on every API request by
 *    design; putting a loop of `systemctl reload` there would let one visitor stall five php-fpm
 *    children. The reload round lives here, is called only from tools/janitor.php, and refuses to run
 *    under any SAPI but the CLI even if some future caller forgets.
 */

/** Deliberately narrower than isServiceNameValid(): this name is interpolated into paths written as root. */
function otClusterValidName(string $n): bool {
    return $n !== 'primary' && (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,15}$/', $n);
}
function otClusterCommand(array $cfg): string { return trim((string)($cfg['ot_cluster_cmd'] ?? '')); }
function otClusterEnabled(array $cfg): bool {
    return (($cfg['ot_cluster_enabled'] ?? '0') === '1') && otClusterCommand($cfg) !== '';
}
function otClusterValidCommand(string $cmd): bool {
    $cmd = trim($cmd);
    return $cmd === '' || (bool)preg_match('/^[A-Za-z0-9 _.\/-]{1,255}$/', $cmd);
}
/** Empty = derive from the primary's own announce port. A number pins the first extra port. */
function otClusterPortBase(array $cfg): int {
    $v = (int)($cfg['ot_cluster_port_base'] ?? 0);
    return ($v >= 1024 && $v <= 65500) ? $v : 0;
}

function otClusterRun(array $cfg, array $args): array {
    $out = ['ok' => false, 'json' => null, 'output' => '', 'code' => null, 'error' => null];
    $cmd = otClusterCommand($cfg);
    if ($cmd === '') { $out['error'] = 'No cluster helper is configured (Settings → OpenTracker instances).'; return $out; }
    if (!otClusterValidCommand($cmd)) { $out['error'] = 'The helper command contains characters that are not allowed.'; return $out; }
    if (!trackerExecAvailable()) { $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the helper.'; return $out; }
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
    if (!$out['ok'] && $out['error'] === null) $out['error'] = (string)($out['json']['error'] ?? ('Helper exited with code ' . (int)$rc));
    return $out;
}

/* ── the roster, and the rule about who may go and fetch it ──────────────── */

function otClusterState(): array {
    $s = netlimitStateRead();
    return is_array($s['cluster'] ?? null) ? $s['cluster'] : [];
}
/** Replaced whole, never merged: netlimitStateRead() merges recursively, which makes removals sticky. */
function otClusterStateSet(array $sub): void {
    netlimitStateUpdate(function (array &$s) use ($sub) { $s['cluster'] = $sub; return true; });
}

/**
 * The roster, from cache unless the caller explicitly asks for a fresh one.
 *
 * getTrackerServiceWarnings() and whitelistStatus() are called from high-frequency dashboard pollers.
 * If any of them could reach the helper, every poll would fork a root script that runs systemctl
 * several times — so the default is the cache, and a web request can only get fresh data by asking
 * for it, which exactly one endpoint does.
 */
function otClusterRoster(array $cfg, bool $fresh = false): array {
    $state = otClusterState();
    $cached = is_array($state['roster'] ?? null) ? $state['roster'] : ['instances' => [], 'count' => 0, 'at' => 0];
    if (!otClusterEnabled($cfg)) return ['instances' => [], 'count' => 0, 'at' => (int)($cached['at'] ?? 0), 'cached' => true, 'off' => true];
    if (!$fresh) return $cached + ['cached' => true];

    $r = otClusterRun($cfg, ['status']);
    if (!$r['ok'] || !is_array($r['json'])) {
        return $cached + ['cached' => true, 'error' => $r['error'] ?? 'the helper did not answer'];
    }
    $roster = [
        'instances' => array_values((array)($r['json']['instances'] ?? [])),
        'count'     => (int)($r['json']['count'] ?? 0),
        'primary'   => (array)($r['json']['primary'] ?? []),
        'template_present' => !empty($r['json']['template_present']),
        'at'        => time(),
    ];
    $state['roster'] = $roster;
    otClusterStateSet($state);
    return $roster + ['cached' => false];
}

/** Every systemd unit that serves announces: the installer's, then the extras. */
function otClusterUnits(array $cfg): array {
    $units = [];
    $primary = trim((string)($cfg['opentracker_service_name'] ?? ''));
    if ($primary !== '') $units[] = $primary;
    foreach ((array)(otClusterRoster($cfg)['instances'] ?? []) as $i) {
        $n = (string)($i['name'] ?? '');
        if (otClusterValidName($n)) $units[] = 'opentracker@' . $n . '.service';
    }
    return $units;
}

/**
 * The next free-looking pair of ports, proposed from the primary's own.
 *
 * A narrow band next to the tracker's own port rather than a free-form number: a port chosen at
 * random is a port some other service on this machine may be about to want back, and the helper's
 * two checks cannot see a daemon that happens to be stopped right now.
 */
function otClusterProposePorts(array $cfg, array $roster): array {
    $base = otClusterPortBase($cfg);
    $primaryUdp = (int)($roster['primary']['udp_port'] ?? 0);
    $primaryTcp = (int)($roster['primary']['tcp_port'] ?? 0);
    if ($base <= 0) $base = $primaryUdp > 0 ? $primaryUdp + 1 : 0;
    if ($base <= 0) return ['udp' => 0, 'tcp' => 0, 'why' => 'the primary\'s port could not be read, so nothing can be proposed'];

    $taken = [];
    if ($primaryUdp) $taken[$primaryUdp] = true;
    if ($primaryTcp) $taken[$primaryTcp] = true;
    foreach ((array)($roster['instances'] ?? []) as $i) {
        if (!empty($i['udp_port'])) $taken[(int)$i['udp_port']] = true;
        if (!empty($i['tcp_port'])) $taken[(int)$i['tcp_port']] = true;
    }
    for ($p = $base; $p < $base + 16 && $p <= 65534; $p++) {
        if (isset($taken[$p])) continue;
        return ['udp' => $p, 'tcp' => $p, 'why' => 'the first free port next to the primary\'s'];
    }
    return ['udp' => 0, 'tcp' => 0, 'why' => 'no free port in the band next to the primary\'s — choose one by hand'];
}

/**
 * Every announce URL a client should be given.
 *
 * With the cluster off this returns exactly the two URLs the panel has always rendered, so nothing
 * public changes on an install that never enabled it.
 */
function otClusterAnnounceUrls(array $cfg): array {
    $urls = [];
    foreach (['announce_url', 'announce_url_https'] as $k) {
        $u = trim((string)($cfg[$k] ?? ''));
        if ($u !== '') $urls[] = $u;
    }
    if (!otClusterEnabled($cfg)) return $urls;
    $roster = otClusterRoster($cfg);
    $primaryUdp = (int)($roster['primary']['udp_port'] ?? 0);
    if ($primaryUdp <= 0 || !$urls) return $urls;
    // Derived from the URL the operator already wrote, with only the port changed — so the scheme,
    // the host and the path stay whatever they configured.
    $extra = [];
    foreach ((array)($roster['instances'] ?? []) as $i) {
        $port = (int)($i['udp_port'] ?? 0);
        if ($port <= 0 || $port === $primaryUdp) continue;
        if (($i['state'] ?? '') !== 'active') continue;      // never advertise a port nothing is listening on
        foreach ($urls as $u) {
            $swapped = preg_replace('#:' . $primaryUdp . '(/|$)#', ':' . $port . '$1', $u, 1, $n);
            if ($n === 1 && $swapped !== null && !in_array($swapped, $extra, true)) $extra[] = $swapped;
        }
    }
    return array_merge($urls, $extra);
}

/* ── the janitor's half ──────────────────────────────────────────────────── */

/**
 * Reload the extras when the accesslist has changed under them.
 *
 * Driven by the FILE'S modification time rather than by hooking into whitelistJanitor(). That keeps
 * the fan-out entirely out of the web path, and it works in blacklist mode too — where
 * whitelistJanitor() returns immediately, so an extra would otherwise keep serving a hash that was
 * banned an hour ago. On a takedown tracker that is the failure with legal weight.
 *
 * The bookkeeping is per instance and never touches the primary's. An extra that is permanently
 * broken must not make the panel believe the primary's reloads are failing too.
 */
function otClusterTick(array $cfg): array {
    $out = ['did' => null, 'reloaded' => 0, 'failed' => 0, 'error' => null];
    if (PHP_SAPI !== 'cli') return $out;               // never from a web request, whatever calls this
    if (!otClusterEnabled($cfg)) return $out;

    $state = otClusterState();
    $roster = otClusterRoster($cfg, true);
    if (empty($roster['instances'])) return $out;

    $newest = 0;
    foreach ([whitelistPath($cfg), normalizeListPath((string)($cfg['blacklist_path'] ?? ''))] as $p) {
        if ($p === '' || !is_file($p)) continue;
        $newest = max($newest, (int)@filemtime($p));
    }
    $last = (int)($state['last_reload_at'] ?? 0);
    if ($newest <= 0 || $newest <= $last) return $out;

    $r = otClusterRun($cfg, ['reload', '--all']);
    $results = (array)($r['json']['reloaded'] ?? []);
    $failed = (int)($r['json']['failed'] ?? 0);
    $units = [];
    foreach ($results as $x) {
        $units[(string)($x['name'] ?? '?')] = [
            'ok' => !empty($x['ok']), 'state' => (string)($x['state'] ?? '?'), 'at' => time(),
        ];
    }
    $state['last_reload_at'] = $newest;
    $state['last_reload_units'] = $units;
    $state['last_reload_failed'] = $failed;
    otClusterStateSet($state);
    return ['did' => 'reload', 'reloaded' => count($results) - $failed, 'failed' => $failed,
            'error' => $r['ok'] ? null : $r['error']];
}

/**
 * Warnings the dashboard shows. Reads the cache only — never forks — because the callers are pollers.
 */
function otClusterWarnings(array $cfg): array {
    if (!otClusterEnabled($cfg)) return [];
    $out = [];
    $state = otClusterState();
    $roster = otClusterRoster($cfg);
    foreach ((array)($roster['instances'] ?? []) as $i) {
        $n = (string)($i['name'] ?? '?');
        if (($i['state'] ?? '') !== 'active') {
            $out[] = ['level' => 'danger', 'text' => 'Tracker instance "' . $n . '" is not running (' . ($i['state'] ?? 'unknown') . ').'];
            continue;
        }
        // The one thing the shared binary symlink cannot prevent: a config symlink that drifted.
        $rb = (string)($i['running_build'] ?? '');
        $cm = (string)($i['conf_mode'] ?? '');
        if ($rb !== '' && $cm !== '' && $rb !== 'unknown' && $cm !== 'unknown' && $rb !== $cm) {
            $out[] = ['level' => 'danger', 'text' => 'Tracker instance "' . $n . '" is running the ' . $rb
                . ' build while its config says ' . $cm . '. It needs a restart, or it is serving the wrong list.'];
        }
    }
    foreach ((array)($state['last_reload_units'] ?? []) as $n => $u) {
        if (empty($u['ok'])) {
            $out[] = ['level' => 'warning', 'text' => 'Tracker instance "' . $n . '" did not reload its accesslist ('
                . ($u['state'] ?? '?') . '), so it is still serving the previous one.'];
        }
    }
    return $out;
}
