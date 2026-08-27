<?php
/**
 * Test for includes/cluster.php and tools/opentracker/tracker-cluster.sh:
 *   php tests/cluster_test.php
 *
 * The dangerous half of this feature is not creating an instance, it is everything that has to keep
 * being true afterwards: that the panel never forks a root helper from a poller, that the fan-out
 * never lands in a web request, that an instance the panel did not create is still in the roster, and
 * that an install which never enabled any of it is untouched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/opentracker.php';
require_once $root . '/includes/cluster.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

/* ── 1. names, because the name becomes a path written as root ────────────── */

check('a normal name is accepted', otClusterValidName('edge-a') && otClusterValidName('b2'));
check('"primary" is reserved for the installer\'s own unit', !otClusterValidName('primary'));
check('uppercase, dots, slashes and @ are refused',
      !otClusterValidName('Edge') && !otClusterValidName('a.b') && !otClusterValidName('a/b')
      && !otClusterValidName('a@b') && !otClusterValidName('../x'));
check('an empty name and a leading dash are refused', !otClusterValidName('') && !otClusterValidName('-a'));
check('seventeen characters are refused', !otClusterValidName(str_repeat('a', 17)));
check('sixteen are fine', otClusterValidName(str_repeat('a', 16)));

check('a shell metacharacter in the helper command is refused', !otClusterValidCommand('sudo -n /x.sh; id'));
check('an empty command is allowed — that is how the feature stays off', otClusterValidCommand(''));

/* ── 2. off by default, and off means it does nothing at all ──────────────── */

check('the feature is off with no command', !otClusterEnabled([]));
check('enabling without a command is still off', !otClusterEnabled(['ot_cluster_enabled' => '1']));
check('both are needed', otClusterEnabled(['ot_cluster_enabled' => '1', 'ot_cluster_cmd' => 'sudo -n /x.sh']));
check('the roster is empty and marked off when the feature is off',
      otClusterRoster([])['count'] === 0 && !empty(otClusterRoster([])['off']));
check('the units list is just the primary when the feature is off',
      otClusterUnits(['opentracker_service_name' => 'opentracker']) === ['opentracker']);

/* ── 3. announce URLs: nothing public changes on an install that never opted in ── */

$base = ['announce_url' => 'udp://tracker.example.org:6969/announce',
         'announce_url_https' => 'http://tracker.example.org:6969/announce'];
check('with the cluster off the announce URLs are exactly the two configured ones',
      otClusterAnnounceUrls($base) === array_values($base));

/* ── 4. port proposal ─────────────────────────────────────────────────────── */

$roster = ['primary' => ['udp_port' => 6969, 'tcp_port' => 6969], 'instances' => []];
$p = otClusterProposePorts([], $roster);
check('the first proposal is the port next to the primary\'s', $p['udp'] === 6970 && $p['tcp'] === 6970, json_encode($p));
$roster['instances'] = [['udp_port' => 6970, 'tcp_port' => 6970]];
check('an existing instance\'s port is skipped', otClusterProposePorts([], $roster)['udp'] === 6971);
check('a configured base is honoured', otClusterProposePorts(['ot_cluster_port_base' => '7100'], $roster)['udp'] === 7100);
check('a privileged base is ignored rather than obeyed',
      otClusterProposePorts(['ot_cluster_port_base' => '80'], $roster)['udp'] === 6971);
check('with no primary port nothing is proposed, and it says why',
      otClusterProposePorts([], ['primary' => [], 'instances' => []])['udp'] === 0);
$full = ['primary' => ['udp_port' => 6969], 'instances' => []];
for ($i = 6970; $i < 6986; $i++) $full['instances'][] = ['udp_port' => $i, 'tcp_port' => $i];
check('a full band refuses rather than wandering off into other services\' ports',
      otClusterProposePorts([], $full)['udp'] === 0, json_encode(otClusterProposePorts([], $full)));

/* ── 5. the two rules that keep this out of the web path ──────────────────── */

$src = file_get_contents($root . '/includes/cluster.php');
check('the reload fan-out refuses to run under anything but the CLI',
      str_contains($src, "if (PHP_SAPI !== 'cli') return \$out;"));
check('the roster is served from cache unless a caller explicitly asks for fresh',
      str_contains($src, 'function otClusterRoster(array $cfg, bool $fresh = false)')
      && str_contains($src, 'if (!$fresh) return $cached'));
check('the warnings collector never asks for a fresh roster — its callers are pollers',
      !preg_match('/otClusterWarnings.*?otClusterRoster\(\$cfg, true\)/s', $src));
$jan = file_get_contents($root . '/tools/janitor.php');
check('the janitor is the only thing that runs the fan-out', str_contains($jan, 'otClusterTick($cfg)'));
$wl = file_get_contents($root . '/includes/whitelist.php');
check('nothing was added to whitelistJanitor(), which runs on every API request',
      !str_contains($wl, 'otClusterTick') && !str_contains($wl, "otClusterRun"));
$fn = file_get_contents($root . '/includes/functions.php');
check('a manual reload reaches every instance', str_contains($fn, 'function runTrackerServiceCommandAll'));
check('… but its ok follows the PRIMARY, so one dead extra cannot strand the bookkeeping',
      str_contains($fn, "'ok' => \$primary['ok']"));
check('a reload that returned 0 is re-checked, because a SIGHUP can kill an unpatched build',
      str_contains($fn, 'is not active after the reload'));

/* ── 6. the guard against two feedback loops pulling opposite ways ────────── */

$apply = file_get_contents($root . '/api/admin/ot_cluster_apply.php');
check('creating an instance is refused while the automatic limiter is on',
      str_contains($apply, 'netlimitAutoEnabled($cfg)') && str_contains($apply, 'throttling the primary'));
check('create, remove and restart are password-gated', str_contains($apply, 'password_verify($password, ADMIN_PASSWORD_HASH)'));
check('plan and reload are not — neither changes the roster',
      strpos($apply, "if (\$op === 'plan')") < strpos($apply, '$password = (string)'));

$test = file_get_contents($root . '/api/admin/ot_cluster_test.php');
check('the Test button checks the INSTALLED mode switch understands --all',
      str_contains($test, 'scheduleSwitchCommand($cfg)') && str_contains($test, '--version'));

/* ── 7. registration ──────────────────────────────────────────────────────── */

require_once $root . '/includes/schema.php';
$defaults = trackerSchemaDefaultSettings();
check('schema defaults exist and the feature is off in them',
      ($defaults['ot_cluster_enabled'] ?? null) === '0' && ($defaults['ot_cluster_cmd'] ?? null) === '');
check('the schema version was bumped past the kernel-buffer release', TRACKER_SCHEMA_VERSION >= 17);
$sv = file_get_contents($root . '/api/admin/save_settings.php');
check('the three keys are in the save allow-list', str_contains($sv, "'ot_cluster_cmd', 'ot_cluster_enabled', 'ot_cluster_port_base'"));
check('the command is validated on save', str_contains($sv, 'otClusterValidCommand'));
check('a privileged port base is refused on save', str_contains($sv, 'below 1024 belongs to things that were here first'));
check('findable in the settings search', str_contains(file_get_contents($root . '/includes/settings_catalog.php'), "'ot_cluster_cmd'"));
$tpl = file_get_contents($root . '/templates/admin/settings.php');
check('the Settings section exists with all three controls',
      str_contains($tpl, 'id="section-cluster"') && str_contains($tpl, 'name="ot_cluster_cmd"')
      && str_contains($tpl, 'name="ot_cluster_enabled"') && str_contains($tpl, 'name="ot_cluster_port_base"'));
$api = file_get_contents($root . '/api.php');
foreach (['admin/ot_cluster_status', 'admin/ot_cluster_apply', 'admin/ot_cluster_test'] as $ep) {
    check('routing: ' . $ep, str_contains($api, "'" . $ep . "'"));
}
check('the polled endpoint skips the janitors and releases the session lock',
      substr_count($api, "'admin/ot_cluster_status'") >= 3, (string)substr_count($api, "'admin/ot_cluster_status'"));
$traffic = file_get_contents($root . '/templates/admin/traffic.php');
check('the card is gated on the feature being on', str_contains($traffic, 'otClusterEnabled($cfg)') && str_contains($traffic, 'id="cluster-card"'));

/* ── 8. the helper, end to end against a fake tracker home ────────────────── */

$helper = $root . '/tools/opentracker/tracker-cluster.sh';
$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe'] as $cand) {
    $probe = []; $rc = null;
    @exec(escapeshellarg($cand) . ' -c "echo ok" 2>&1', $probe, $rc);
    if ($rc === 0 && trim(implode('', $probe)) === 'ok') { $bash = $cand; break; }
}
if ($bash === null || !trackerExecAvailable() || !is_file($helper)) {
    skip('helper end to end', 'no usable bash (or exec() disabled)');
} else {
    $posix = static function (string $p): string {
        $p = str_replace('\\', '/', $p);
        if (preg_match('#^([A-Za-z]):/(.*)$#', $p, $m)) return '/' . strtolower($m[1]) . '/' . $m[2];
        return $p;
    };
    $tmp = sys_get_temp_dir() . '/cluster_test_' . getmypid();
    $home = $tmp . '/home';
    $bin = $tmp . '/bin';
    @mkdir($home . '/instances', 0777, true);
    @mkdir($bin, 0777, true);
    @mkdir($tmp . '/systemd', 0777, true);
    file_put_contents($home . '/opentracker', "#!/bin/sh\n");
    foreach (['white', 'black'] as $m) {
        file_put_contents($home . '/opentracker.' . $m, "#!/bin/sh\n");
        file_put_contents($home . '/opentracker.conf.' . $m,
            "listen.udp 0.0.0.0:6969\nlisten.tcp 0.0.0.0:6969\nlisten.udp.workers 4\naccess.whitelist /var/lib/tracker/whitelist\n");
    }
    file_put_contents($bin . '/id', "#!/bin/bash\n[ \"\$1\" = \"-u\" ] && { echo 0; exit 0; }\nexec /usr/bin/id \"\$@\"\n");
    file_put_contents($bin . '/systemctl', "#!/bin/bash\ncase \"\$1\" in is-active) echo active; exit 0 ;; show) echo 0 ;; esac\nexit 0\n");
    file_put_contents($bin . '/ss', "#!/bin/bash\nexit 0\n");
    @chmod($bin . '/id', 0755); @chmod($bin . '/systemctl', 0755); @chmod($bin . '/ss', 0755);

    $pathBefore = (string)getenv('PATH');
    putenv('PATH=' . $bin . PATH_SEPARATOR . $pathBefore);
    foreach ([
        'TRACKER_HOME'  => $posix($home),
        'INSTANCES_DIR' => $posix($home . '/instances'),
        'TEMPLATE_UNIT' => $posix($tmp . '/systemd/opentracker@.service'),
        'TRACKER_USER'  => 'nobody',
    ] as $k => $v) putenv($k . '=' . $v);

    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $run = static function (string $args) use ($bashCmd, $helper, $posix): array {
        $out = []; $rc = null;
        @exec($bashCmd . ' ' . escapeshellarg($posix($helper)) . ' ' . $args . ' 2>&1', $out, $rc);
        $json = null;
        foreach (array_reverse($out) as $l) {
            $l = trim($l);
            if ($l !== '' && $l[0] === '{') { $json = json_decode($l, true); if (is_array($json)) break; $json = null; }
        }
        return ['rc' => (int)$rc, 'out' => implode("\n", $out), 'json' => $json];
    };

    $r = $run('status');
    check('helper: status answers with JSON', is_array($r['json']) && ($r['json']['ok'] ?? null) === true, $r['out']);
    check('helper: an empty roster is an empty list, not an error', ($r['json']['count'] ?? -1) === 0);
    check('helper: it reads the primary\'s ports out of its config',
          (int)($r['json']['primary']['udp_port'] ?? 0) === 6969, json_encode($r['json']['primary'] ?? null));

    check('helper: plan refuses a privileged port', ($run('plan edge-a 80 80')['json']['ok'] ?? true) === false);
    check('helper: plan refuses the primary\'s own port',
          ($run('plan edge-a 6969 6969')['json']['ok'] ?? true) === false);
    check('helper: plan refuses the reserved name', ($run('plan primary 7000 7000')['json']['ok'] ?? true) === false);
    $ok = $run('plan edge-a 6970 6970');
    check('helper: a free port passes', ($ok['json']['ok'] ?? false) === true, $ok['out']);
    check('helper: … and it says what its checks cannot see',
          str_contains((string)($ok['json']['warnings'] ?? ''), 'stopped at this moment'));

    $c = $run('create edge-a 6970 6970 "" 2');
    check('helper: create makes the instance', ($c['json']['ok'] ?? false) === true, $c['out']);
    check('helper: … copying the primary\'s config rather than inventing one',
          str_contains((string)@file_get_contents($home . '/instances/edge-a/opentracker.conf.white'), 'access.whitelist /var/lib/tracker/whitelist'));
    check('helper: … with only the ports changed',
          str_contains((string)@file_get_contents($home . '/instances/edge-a/opentracker.conf.white'), 'listen.udp 0.0.0.0:6970'));
    check('helper: … and the worker count it was given',
          str_contains((string)@file_get_contents($home . '/instances/edge-a/opentracker.conf.white'), 'listen.udp.workers 2'));
    check('helper: the systemd template was written', is_file($tmp . '/systemd/opentracker@.service'));
    check('helper: … deriving the binary from the primary\'s unit, not from a guess',
          str_contains((string)@file_get_contents($tmp . '/systemd/opentracker@.service'), 'ExecStart='));
    check('helper: … and it says the installer\'s unit is not touched',
          str_contains((string)@file_get_contents($tmp . '/systemd/opentracker@.service'), 'not touched'));

    $r = $run('status');
    check('helper: the new instance is in the roster', ($r['json']['count'] ?? 0) === 1, $r['out']);
    check('helper: with its ports', (int)($r['json']['instances'][0]['udp_port'] ?? 0) === 6970);

    // The roster must come from the filesystem: an instance systemd has forgotten still exists.
    @mkdir($home . '/instances/edge-b', 0777, true);
    file_put_contents($home . '/instances/edge-b/opentracker.conf.white', "listen.udp 0.0.0.0:6971\n");
    $r = $run('status');
    check('helper: an instance systemd does not know about is still in the roster',
          ($r['json']['count'] ?? 0) === 2, json_encode(array_column((array)($r['json']['instances'] ?? []), 'name')));

    check('helper: a duplicate name is refused', ($run('plan edge-a 6980 6980')['json']['ok'] ?? true) === false);
    check('helper: a port already named in a config is refused',
          ($run('plan edge-c 6970 6970')['json']['ok'] ?? true) === false);

    $rm = $run('remove edge-a');
    check('helper: remove takes the directory with it',
          ($rm['json']['ok'] ?? false) === true && !is_dir($home . '/instances/edge-a'), $rm['out']);
    check('helper: the template survives while another instance still uses it',
          is_file($tmp . '/systemd/opentracker@.service') && ($rm['json']['template_removed'] ?? true) === false);
    $rm2 = $run('remove edge-b');
    check('helper: removing the last one takes the template too — "remove every trace" means it',
          ($rm2['json']['template_removed'] ?? false) === true && !is_file($tmp . '/systemd/opentracker@.service'));
    check('helper: and the primary\'s own files were never touched',
          is_file($home . '/opentracker.conf.white') && is_file($home . '/opentracker.white'));

    putenv('PATH=' . $pathBefore);
    foreach (['TRACKER_HOME', 'INSTANCES_DIR', 'TEMPLATE_UNIT', 'TRACKER_USER'] as $k) putenv($k);
    $rmrf = static function (string $d) use (&$rmrf) {
        foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $rmrf($f) : @unlink($f); }
        @rmdir($d);
    };
    $rmrf($tmp);
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
