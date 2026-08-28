<?php
/**
 * Test for includes/livesync.php and tools/opentracker/tracker-livesync.sh:
 *   php tests/livesync_test.php
 *
 * Live peer sync is the one feature in this project that opens an UNAUTHENTICATED port. There is no
 * password on the protocol, no encryption, and no way to tell a peer from anybody else who can reach
 * the address: whatever gets there can inject peers into every swarm this tracker serves.
 *
 * So most of what is checked here is refusal. The helper must say no to a public bind address, no to
 * a public peer, no to an interface that is not a tunnel, and no to a state where the port ended up
 * listening on everything. Each of those is a way the feature could quietly become an open door, and
 * "the operator was warned" is not a defence for a door.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/livesync.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

/* ── 1. off is off, and off costs nothing ─────────────────────────────────── */

check('the feature is off with no command', !livesyncEnabled([]));
check('enabling without a command is still off', !livesyncEnabled(['livesync_enabled' => '1']));
check('both are needed', livesyncEnabled(['livesync_enabled' => '1', 'livesync_cmd' => 'sudo -n /x.sh']));
check('the status is marked off and reads nothing when the feature is off',
      !empty(livesyncStatus([])['off']));
check('a shell metacharacter in the helper command is refused',
      !livesyncValidCommand('sudo -n /x.sh; id'));
check('an empty command is allowed — that is how the feature stays off', livesyncValidCommand(''));

/* ── 2. what counts as a tunnel address ───────────────────────────────────── */

foreach (['10.9.0.1', '172.16.4.2', '192.168.1.5', '100.71.3.9', '127.0.0.1'] as $ip) {
    check('a private address is accepted as a tunnel address: ' . $ip, livesyncPrivateV4($ip));
}
foreach (['135.125.236.64', '8.8.8.8', '1.1.1.1', '172.32.0.1', '100.128.0.1'] as $ip) {
    check('a public address is NOT a tunnel address: ' . $ip, !livesyncPrivateV4($ip));
}
check('an IPv6 address is not accepted here (the helper is IPv4-only and says so)',
      !livesyncPrivateV4('fd00::1'));
check('nonsense is not an address', !livesyncPrivateV4('10.9.0') && !livesyncPrivateV4('x'));

/* ── 3. the configuration checks, in the operator's terms ─────────────────── */

$base = ['livesync_enabled' => '1', 'livesync_cmd' => 'sudo -n /x.sh', 'livesync_port' => '9696'];

$p = livesyncValidate($base + ['livesync_bind_ip' => '', 'livesync_peer_ip' => '']);
check('empty addresses are refused, and the message says what the field is for',
      count($p) >= 2 && str_contains($p[0], 'THIS machine'), implode(' | ', $p));

$p = livesyncValidate($base + ['livesync_bind_ip' => '135.125.236.64', 'livesync_peer_ip' => '10.9.0.2']);
check('a PUBLIC bind address is refused, and the reason is the missing authentication',
      count($p) === 1 && str_contains($p[0], 'no authentication'), implode(' | ', $p));

$p = livesyncValidate($base + ['livesync_bind_ip' => '10.9.0.1', 'livesync_peer_ip' => '8.8.8.8']);
check('a PUBLIC peer address is refused too', count($p) === 1 && str_contains($p[0], 'public'), implode(' | ', $p));

$p = livesyncValidate($base + ['livesync_bind_ip' => '10.9.0.1', 'livesync_peer_ip' => '10.9.0.1']);
check('the peer being this machine is refused', count($p) === 1, implode(' | ', $p));

$p = livesyncValidate(['livesync_enabled' => '1', 'livesync_cmd' => 'sudo -n /x.sh',
                       'livesync_bind_ip' => '10.9.0.1', 'livesync_peer_ip' => '10.9.0.2',
                       'livesync_port' => '6969']);
check('the tracker\'s own announce port is refused for sync',
      count($p) === 1 && str_contains($p[0], '6969'), implode(' | ', $p));

$p = livesyncValidate($base + ['livesync_bind_ip' => '10.9.0.1', 'livesync_peer_ip' => '10.9.0.2']);
check('a sane tunnel configuration passes', $p === [], implode(' | ', $p));

/* ── 4. nothing forks a root helper from a page view ──────────────────────── */

$src = file_get_contents($root . '/includes/livesync.php');
check('the status is served from cache unless a caller explicitly asks for fresh',
      str_contains($src, 'function livesyncStatus(array $cfg, bool $fresh = false)')
      && str_contains($src, 'if (!$fresh) return $cached'));
// Bounded to the function's own body. An unbounded .*? walks straight into livesyncTick(), which
// asks for a fresh status on purpose, and the check would fail for the wrong reason.
$warnBody = '';
if (preg_match('/function livesyncWarnings\(.*?\n\}/s', $src, $mm)) $warnBody = $mm[0];
check('the warnings collector never asks for a fresh status — its callers are pollers',
      $warnBody !== '' && !str_contains($warnBody, 'livesyncStatus($cfg, true)'));
check('the tick refuses to run under anything but the CLI',
      preg_match('/function livesyncTick.*?PHP_SAPI !== .cli.*?return \$out;/s', $src) === 1);
$jan = file_get_contents($root . '/tools/janitor.php');
check('the janitor is what refreshes it', str_contains($jan, 'livesyncTick($cfg)'));

$apply = file_get_contents($root . '/api/admin/livesync_apply.php');
check('arming and disarming are password-gated', substr_count($apply, 'requireAdminReauth') >= 1);
check('… and status/plan are not, because they change nothing',
      strpos($apply, 'requireAdminReauth') > strpos($apply, "op === 'plan'"));

/* ── 5. the helper itself, against stubs ──────────────────────────────────── */

$helper = $root . '/tools/opentracker/tracker-livesync.sh';
$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash'] as $cand) {
    $o = []; $rc = null;
    @exec(escapeshellarg($cand) . ' -c "echo ok" 2>&1', $o, $rc);
    if ($rc === 0) { $bash = $cand; break; }
}

if ($bash === null) {
    skip('the helper, driven against stubs', 'no bash on this machine');
} else {
    $o = []; $rc = null;
    @exec(escapeshellarg($bash) . ' -n ' . escapeshellarg($helper) . ' 2>&1', $o, $rc);
    check('the helper is valid bash', $rc === 0, implode(' ', $o));

    // Control characters in a shell script have bitten this project before: an edit turned a sed
    // backreference into a literal 0x01 and a measurement silently returned zero for weeks.
    $hsrc = file_get_contents($helper);
    check('the helper contains no stray control bytes',
          !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $hsrc));

    check('it writes exactly one file, and that file is its own',
          substr_count($hsrc, '91-tracker-livesync.conf') >= 1
          && preg_match_all('/> ?"\$DROPIN"/', $hsrc) >= 1
          && !preg_match('/(>|rm -f|tee)[^\n]*90-tracker-panel/', $hsrc));
    check('undoing it is deleting that one file',
          preg_match('/action_revert.*?rm -f "\$DROPIN"/s', $hsrc) === 1);
    check('it records the ExecStart it copied, so drift can be reported rather than discovered',
          str_contains($hsrc, 'base-execstart:') && str_contains($hsrc, 'base_drifted'));
    check('it re-reads the base with its own drop-in removed, so re-applying cannot double the flags',
          preg_match('/action_apply.*?rm -f "\$DROPIN".*?base="\$\(current_execstart\)"/s', $hsrc) === 1);
    check('it verifies the port is actually listening instead of trusting the exit code',
          preg_match('/action_apply.*?bound_to "\$port"/s', $hsrc) === 1);
    check('… and undoes itself when it is not', preg_match('/nothing is listening on UDP.*?undone/s', $hsrc) === 1);
    check('a port bound to every interface is treated as a failure, not a warning',
          str_contains($hsrc, 'every interface instead of'));
    check('a public bind address is refused by the helper too, not only by the panel',
          preg_match('/refusing: .*is a public address/', $hsrc) === 1);
    check('there is no flag to override the tunnel requirement',
          !preg_match('/--force|--i-know|--allow-public/i', $hsrc));

    $out = [];
    @exec(escapeshellarg($bash) . ' ' . escapeshellarg($helper) . ' --version 2>&1', $out, $rc);
    $j = json_decode(trim(implode('', $out)), true);
    check('it answers --version with JSON', is_array($j) && !empty($j['ok']), implode('', $out));

    // Refusals, driven for real. A stub `ip` reports one tunnel and one ordinary interface, so the
    // helper's own address checks run rather than being read.
    $tmp = sys_get_temp_dir() . '/ls_stub_' . getmypid();
    @mkdir($tmp, 0700, true);
    file_put_contents($tmp . '/ip', "#!/bin/sh\n"
        . "case \"$*\" in\n"
        . "  *'-o -4 addr show'*) printf '2: wg0    inet 10.9.0.1/24 scope global wg0\\n3: eth0    inet 135.125.236.64/24 scope global eth0\\n' ;;\n"
        . "  *'link show type wireguard'*) printf '2: wg0: <POINTOPOINT,NOARP,UP,LOWER_UP> mtu 1420\\n' ;;\n"
        . "  *'link show wg0'*) printf '2: wg0: <POINTOPOINT,NOARP,UP,LOWER_UP> mtu 1420\\n' ;;\n"
        . "  *'link show eth0'*) printf '3: eth0: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500\\n' ;;\n"
        . "esac\nexit 0\n");
    file_put_contents($tmp . '/ss', "#!/bin/sh\nexit 0\n");
    file_put_contents($tmp . '/systemctl', "#!/bin/sh\n"
        . "[ \"\$1\" = show ] && { echo 'argv[]=/home/tracker/opentracker -f /home/tracker/opentracker.conf;'; exit 0; }\n"
        . "exit 0\n");
    foreach (['ip', 'ss', 'systemctl'] as $f) @chmod($tmp . '/' . $f, 0755);

    // putenv(), not a `VAR=x cmd` prefix: that prefix is shell syntax the Windows command
    // interpreter does not have, and this suite runs on both. The child process inherits these.
    foreach ([
        'IP_BIN' => $tmp . '/ip',
        'SS_BIN' => $tmp . '/ss',
        'SYSTEMCTL_BIN' => $tmp . '/systemctl',
        'OT_DROPIN_DIR' => $tmp . '/dropin',
    ] as $k => $v) putenv($k . '=' . $v);

    $run = function (string $args) use ($bash, $helper): array {
        $o = []; $rc = null;
        @exec(escapeshellarg($bash) . ' ' . escapeshellarg($helper) . ' ' . $args . ' 2>&1', $o, $rc);
        return [json_decode(trim(implode('', $o)), true), trim(implode('', $o))];
    };

    [$j, $raw] = $run('plan 135.125.236.64 9696 10.9.0.2');
    check('helper: a public bind address is refused, naming the reason',
          is_array($j) && empty($j['ok']) && str_contains((string)$j['error'], 'no authentication'), $raw);

    [$j, $raw] = $run('plan 10.9.0.1 9696 8.8.8.8');
    check('helper: a public peer is refused', is_array($j) && empty($j['ok'])
          && str_contains((string)$j['error'], 'public address'), $raw);

    [$j, $raw] = $run('plan 192.168.44.44 9696 10.9.0.2');
    check('helper: an address no interface has is refused',
          is_array($j) && empty($j['ok']) && str_contains((string)$j['error'], 'no interface'), $raw);

    [$j, $raw] = $run('plan 10.9.0.1 6969 10.9.0.2');
    check('helper: a plan on the tunnel address is accepted and shows the command it would run',
          is_array($j) && !empty($j['ok']) && str_contains((string)($j['execstart'] ?? ''), '-s 6969')
          && str_contains((string)($j['execstart'] ?? ''), '-A 10.9.0.2/32'), $raw);

    [$j, $raw] = $run('plan 10.9.0.1 80 10.9.0.2');
    check('helper: a privileged port is refused', is_array($j) && empty($j['ok']), $raw);

    [$j, $raw] = $run('status');
    check('helper: status answers with JSON and reports nothing armed',
          is_array($j) && !empty($j['ok']) && empty($j['armed']), $raw);
    check('… and lists the tunnel interfaces it can see',
          is_array($j) && in_array('wg0', (array)($j['wg_ifaces'] ?? []), true), $raw);

    foreach (['IP_BIN', 'SS_BIN', 'SYSTEMCTL_BIN', 'OT_DROPIN_DIR'] as $k) putenv($k);
    array_map('unlink', glob($tmp . '/*') ?: []);
    array_map('unlink', glob($tmp . '/dropin/*') ?: []);
    @rmdir($tmp . '/dropin');
    @rmdir($tmp);
}

/* ── 6. the panel tells the operator what to do rather than doing it ──────── */

$hints = livesyncSetupHints(['livesync_bind_ip' => '10.9.0.1', 'livesync_peer_ip' => '10.9.0.2']);
check('the setup hints name WireGuard and the addresses in play',
      count($hints) >= 4 && str_contains(implode(' ', $hints), 'wireguard')
      && str_contains(implode(' ', $hints), '10.9.0.2'));
check('the panel does NOT generate keys or write into /etc/wireguard itself',
      !str_contains(file_get_contents($root . '/includes/livesync.php'), 'file_put_contents(\'/etc/wireguard')
      && !str_contains(file_get_contents($root . '/tools/opentracker/tracker-livesync.sh'), '/etc/wireguard/private'));

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
