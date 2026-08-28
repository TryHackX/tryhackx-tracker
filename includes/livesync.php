<?php
/**
 * Live peer sync between two trackers (E7), from the panel's side.
 *
 * The tracker half is `tools/opentracker/tracker-livesync.sh`; this is the part that decides when to
 * call it and what to believe about the answer.
 *
 * ── the one thing to understand ─────────────────────────────────────────────
 *
 * Livesync moves LIVE PEERS — who is in which swarm right now — between opentrackers. Federation
 * (includes/federation.php) moves METADATA between panels. They are different jobs and neither
 * replaces the other: federation tells your catalogue what a torrent is called, livesync tells your
 * tracker who is downloading it.
 *
 * The protocol has no authentication and no encryption. Whatever can reach the port can inject peers
 * into every swarm this tracker serves. That is why every path here ends up asking the same
 * question — is this address inside a tunnel — and why the helper refuses rather than warns.
 *
 * Everything is off by default and nothing here forks a helper from a page view: the panel reads a
 * cached status the janitor refreshes, exactly like the cluster roster.
 *
 * ── what running it revealed, and what is still unproven ────────────────────
 *
 * Two things were learned by building a livesync-enabled opentracker and running two of them in
 * separate network namespaces, and both change how this should be read:
 *
 * 1. **The binary in service here has no livesync at all.** It rejects `-s` outright. The help text
 *    advertises `-s livesyncport` and `/stats` carries a `<livesync>` section REGARDLESS of how the
 *    binary was built — both were believed, and both were wrong. The panel's Test now probes the
 *    flag instead of reading either. Fixing it means rebuilding opentracker with `-DWANT_SYNC_LIVE`.
 *
 * 2. **Livesync is MULTICAST, not a link to the peer.** A livesync-enabled opentracker joins the
 *    group 224.0.23.5 and binds two sockets on the sync port. The `-A <peer>/32` this code passes is
 *    an ADMIN blessing — access control — not a destination. So on a WireGuard tunnel the multicast
 *    route has to exist on both ends, which is a step this panel does not perform and cannot verify
 *    from one side.
 *
 * What is still unproven: whether peers actually propagate. In the namespace rig a peer announced to
 * one tracker never reached the other, and the sending side emitted zero packets in twenty-five
 * seconds — consistent with opentracker's own batching, or with multicast in that rig, and not
 * distinguished between. **Do not read the panel's "on" state as proof that peers are flowing.**
 * The counter in the card (`<livesync><count>`) is the thing to watch: it must climb.
 */

function livesyncEnabled(array $cfg): bool {
    return (($cfg['livesync_enabled'] ?? '0') === '1') && livesyncCommand($cfg) !== '';
}
function livesyncCommand(array $cfg): string { return trim((string)($cfg['livesync_cmd'] ?? '')); }

function livesyncValidCommand(string $cmd): bool {
    $cmd = trim($cmd);
    return $cmd === '' || (bool)preg_match('/^[A-Za-z0-9 _.\/-]{1,255}$/', $cmd);
}

function livesyncBindIp(array $cfg): string { return trim((string)($cfg['livesync_bind_ip'] ?? '')); }
function livesyncPeerIp(array $cfg): string { return trim((string)($cfg['livesync_peer_ip'] ?? '')); }
function livesyncPort(array $cfg): int {
    $p = (int)($cfg['livesync_port'] ?? 9696);
    return ($p >= 1024 && $p <= 65535) ? $p : 9696;
}

/**
 * Is this address one a tunnel actually uses?
 *
 * The helper checks this too, on the machine, where it can also see which interface owns it. This
 * copy exists so the panel can say no before it forks anything — and so the refusal reads as an
 * explanation rather than as a shell error.
 */
function livesyncPrivateV4(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $p = array_map('intval', explode('.', $ip));
    if ($p[0] === 10 || $p[0] === 127) return true;
    if ($p[0] === 172 && $p[1] >= 16 && $p[1] <= 31) return true;
    if ($p[0] === 192 && $p[1] === 168) return true;
    if ($p[0] === 100 && $p[1] >= 64 && $p[1] <= 127) return true;   // CGNAT range, e.g. Tailscale
    return false;
}

/** Everything wrong with a proposed configuration, in the operator's terms. Empty = fine. */
function livesyncValidate(array $cfg): array {
    $out = [];
    $bind = livesyncBindIp($cfg);
    $peer = livesyncPeerIp($cfg);
    $port = livesyncPort($cfg);

    if ($bind === '') {
        $out[] = 'No bind address. This is the address of THIS machine inside the tunnel.';
    } elseif (!filter_var($bind, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $out[] = 'The bind address is not an IPv4 address.';
    } elseif (!livesyncPrivateV4($bind)) {
        $out[] = 'The bind address ' . $bind . ' is public. Livesync has no authentication: on a '
               . 'public address, anything on the internet could inject peers into every swarm this '
               . 'tracker serves. It has to be the tunnel address.';
    }

    if ($peer === '') {
        $out[] = 'No peer address. This is the other tracker\'s address inside the tunnel.';
    } elseif (!filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $out[] = 'The peer address is not an IPv4 address.';
    } elseif (!livesyncPrivateV4($peer)) {
        $out[] = 'The peer address ' . $peer . ' is public. The other tracker has to be reachable '
               . 'through the tunnel, not across the internet.';
    }

    if ($bind !== '' && $bind === $peer) {
        $out[] = 'The peer address is this machine\'s own address.';
    }
    if ($port < 1024 || $port > 65535) {
        $out[] = 'The port must be between 1024 and 65535.';
    }
    if ($port === 6969) {
        $out[] = 'Port 6969 is the tracker\'s own announce port. Livesync needs its own.';
    }
    return $out;
}

/* ── talking to the helper ─────────────────────────────────────────────────── */

function livesyncRun(array $cfg, array $args, int $timeout = 25): array {
    $cmd = livesyncCommand($cfg);
    if ($cmd === '' || !livesyncValidCommand($cmd)) {
        return ['ok' => false, 'error' => 'no helper command is configured', 'json' => null];
    }
    if (!function_exists('trackerExecAvailable') || !trackerExecAvailable()) {
        return ['ok' => false, 'error' => 'exec() is disabled in php.ini', 'json' => null];
    }
    $full = $cmd;
    foreach ($args as $a) $full .= ' ' . escapeshellarg((string)$a);
    $lines = []; $rc = null;
    @exec($full . ' 2>&1', $lines, $rc);
    $raw = trim(implode("\n", $lines));
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'the helper did not answer with JSON: ' . mb_substr($raw, 0, 200),
                'json' => null, 'raw' => $raw];
    }
    return ['ok' => !empty($json['ok']), 'error' => $json['error'] ?? null, 'json' => $json];
}

/* ── cached status, so no page view ever forks a root script ───────────────── */

function livesyncState(): array {
    $s = netlimitStateRead();
    return is_array($s['livesync'] ?? null) ? $s['livesync'] : [];
}
function livesyncStateSet(array $sub): void {
    netlimitStateUpdate(function (array &$s) use ($sub) { $s['livesync'] = $sub; return true; });
}

/**
 * The helper's view of the world, from cache unless a caller explicitly asks for fresh.
 *
 * Same rule as the cluster roster and for the same reason: the Traffic page polls this every couple
 * of seconds, and a poll that forks a root script is a poll that eventually takes the server with it.
 */
function livesyncStatus(array $cfg, bool $fresh = false): array {
    $state = livesyncState();
    $cached = is_array($state['status'] ?? null) ? $state['status'] : [];
    if (!livesyncEnabled($cfg)) {
        return ['armed' => false, 'off' => true, 'cached' => true, 'at' => (int)($cached['at'] ?? 0)];
    }
    if (!$fresh) return $cached + ['cached' => true];

    $r = livesyncRun($cfg, ['status']);
    if (!$r['ok'] || !is_array($r['json'])) {
        return $cached + ['cached' => true, 'error' => $r['error'] ?? 'the helper did not answer'];
    }
    $status = $r['json'];
    $status['at'] = time();
    $state['status'] = $status;
    livesyncStateSet($state);
    return $status + ['cached' => false];
}

/**
 * Anything the operator should be told without asking, from the cache only.
 *
 * Never forks. Callers are dashboard pollers.
 */
function livesyncWarnings(array $cfg): array {
    if (!livesyncEnabled($cfg)) return [];
    $s = livesyncStatus($cfg);
    $out = [];
    if (!empty($s['armed']) && empty($s['listening'])) {
        $out[] = 'Live sync is armed but nothing is listening on the sync port — the peers are not '
               . 'being exchanged.';
    }
    if (!empty($s['base_drifted'])) {
        // The single most important thing this feature can tell anybody. Overriding somebody else's
        // ExecStart means carrying a copy of it, and a copy that has gone stale runs the OLD command
        // line for ever while looking perfectly healthy.
        $out[] = 'The opentracker service\'s own start command has changed since live sync was armed, '
               . 'and the panel is still starting it with the old one. Re-apply live sync (or turn it '
               . 'off) so the tracker runs with its current settings.';
    }
    if (!empty($s['armed']) && isset($s['iface_is_tunnel']) && $s['iface_is_tunnel'] === false) {
        $out[] = 'The live sync port is bound to an interface that is not a tunnel. Turn it off now: '
               . 'the protocol has no authentication.';
    }
    return $out;
}

/**
 * The janitor's half: refresh the cached status, and nothing else.
 *
 * CLI only. There is nothing to reconcile here — the drop-in either exists or it does not — so this
 * only keeps the panel's picture current.
 */
function livesyncTick(array $cfg): array {
    $out = ['did' => 'nothing', 'armed' => false, 'error' => null];
    if (PHP_SAPI !== 'cli') return $out;
    if (!livesyncEnabled($cfg)) return $out;
    $s = livesyncStatus($cfg, true);
    $out['did'] = 'refreshed';
    $out['armed'] = !empty($s['armed']);
    $out['error'] = $s['error'] ?? null;
    return $out;
}

/**
 * The commands an operator needs to run by hand, because the panel deliberately does not.
 *
 * Setting up WireGuard means generating a private key and writing it into /etc. A web panel doing
 * that on its own is a larger claim on the machine than anything else in this project makes, and it
 * would be doing it half-blind — it cannot see the other end of a tunnel. So the panel says what to
 * run, the way it does for sudoers and for installing these helpers.
 */
function livesyncSetupHints(array $cfg): array {
    $bind = livesyncBindIp($cfg) ?: '10.9.0.1';
    $peer = livesyncPeerIp($cfg) ?: '10.9.0.2';
    return [
        'sudo apt install wireguard',
        'wg genkey | sudo tee /etc/wireguard/private.key | wg pubkey | sudo tee /etc/wireguard/public.key',
        'sudo nano /etc/wireguard/wg-tracker.conf   # [Interface] Address = ' . $bind . '/24 · [Peer] AllowedIPs = ' . $peer . '/32',
        'sudo systemctl enable --now wg-quick@wg-tracker',
        'ping -c1 ' . $peer . '   # the tunnel has to work before the panel will arm anything',
        // Livesync gossips to a multicast group rather than to the peer address, so the tunnel needs
        // a route for it. Without this the port binds, the panel says "on", and nothing ever moves.
        'sudo ip route add 224.0.0.0/4 dev wg-tracker   # livesync is multicast (224.0.23.5)',
        // And the binary has to have been built for it. The help text and /stats both mention
        // livesync on builds that do not have it, so check by running it, not by reading it:
        'opentracker -i 127.0.0.1 -p 65533 -P 65533 -s 65532 -u nobody   # "Usage:" = rebuild needed',
    ];
}
