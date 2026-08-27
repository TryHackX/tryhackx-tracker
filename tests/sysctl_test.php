<?php
/**
 * Test for includes/sysctl.php and tools/opentracker/tracker-sysctl.sh:
 *   php tests/sysctl_test.php
 *
 * Two halves. The first is the arithmetic and the advice, which is where a wrong unit or an
 * unjustified suggestion would come from. The second drives the REAL helper under bash against a
 * fake /proc tree and stub binaries, because the interesting failures in this feature are the ones
 * where a write appears to have happened and did not.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/sysctl.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

/* ── 1. the allow-list is the security boundary, so it is asserted ─────────── */

$keys = sysctlKeys();
check('exactly the eight keys the operator asked for, and no others',
      count($keys) === 8 && array_diff(array_keys($keys), [
          'rmem_max', 'wmem_max', 'rmem_default', 'wmem_default',
          'netdev_max_backlog', 'udp_mem', 'udp_rmem_min', 'udp_wmem_min']) === [],
      implode(',', array_keys($keys)));
check('every key names a real sysctl and a unit', (function () use ($keys) {
    foreach ($keys as $k => $m) {
        if (!preg_match('/^net\.(core|ipv4)\.[a-z_]+$/', $m['sysctl'])) return false;
        if (!in_array($m['unit'], ['bytes', 'packets', 'pages3'], true)) return false;
        if ($m['what'] === '' || $m['label'] === '') return false;
    }
    return true;
})());
check('the two keys that touch every socket on the machine demand an acknowledgement',
      !empty($keys['rmem_default']['ack']) && !empty($keys['wmem_default']['ack']));
check('the ceilings do NOT, because raising a cap allocates nothing',
      empty($keys['rmem_max']['ack']) && empty($keys['wmem_max']['ack']));

/* ── 2. the settings, including the clamp that matches the watchdog ───────── */

check('the helper command is empty by default, so the feature does not exist until asked for',
      sysctlCommand([]) === '' && sysctlEnabled([]) === false);
check('enabling without a command still counts as off',
      sysctlEnabled(['sysctl_enabled' => '1']) === false);
check('enabled only with both', sysctlEnabled(['sysctl_enabled' => '1', 'sysctl_cmd' => 'sudo -n /x.sh']) === true);
check('a command with a shell metacharacter is refused', sysctlValidCommand('sudo -n /x.sh; rm -rf /') === false);
check('an empty command is allowed (that is how you turn it off)', sysctlValidCommand('') === true);
// The janitor is the coarsest watchdog and ticks once a minute, so a 30-second window would be a
// promise the machine cannot keep.
check('the confirmation window never goes below a minute', sysctlConfirmSeconds(['sysctl_confirm_seconds' => '30']) === 60);
check('… and is rounded to whole minutes', sysctlConfirmSeconds(['sysctl_confirm_seconds' => '100']) === 120);
check('… and is capped', sysctlConfirmSeconds(['sysctl_confirm_seconds' => '99999']) === 900);

/* ── 3. units. This is where a machine gets broken. ───────────────────────── */

// A real machine: 11.4 GiB, 4 KiB pages, 6 cores. The same numbers the reference deployment has.
$st = ['mem_total_kb' => 11963528, 'page_size' => 4096, 'cpus' => 6,
       'values' => ['rmem_max' => '212992', 'rmem_default' => '212992', 'udp_mem' => '277407 369877 554814'],
       'socket' => ['rb' => 212992, 'drops' => 714562]];

check('a sane buffer is accepted', sysctlValidate('rmem_max', '8388608', $st) === '');
check('a buffer below one page is refused', sysctlValidate('rmem_max', '100', $st) !== '');
// On a machine with plenty of memory the absolute ceiling bites first; the eighth-of-RAM question
// is for small boxes, where 95 MiB in one socket buffer really is a typo.
check('a buffer over the absolute ceiling is refused',
      str_contains(sysctlValidate('rmem_max', '4294967296', $st), 'above'));
$small = $st; $small['mem_total_kb'] = 524288;   // a 512 MiB VPS
check('… and on a small machine an eighth of RAM asks whether KiB was meant',
      str_contains(sysctlValidate('rmem_max', '100000000', $small), 'KiB'),
      sysctlValidate('rmem_max', '100000000', $small));
check('the backlog is refused above its ceiling, and the message shows the PER CPU multiplication',
      str_contains(sysctlValidate('netdev_max_backlog', '1000000', $st), 'per CPU'));
check('a sane backlog is accepted', sysctlValidate('netdev_max_backlog', '4000', $st) === '');

// udp_mem is in PAGES. The value the operator found in a guide is 12/16/24 GiB on a machine with
// 11.4 GiB of memory — the exact mistake this validator exists for.
check('udp_mem: the value from the tuning guide is refused on this machine',
      sysctlValidate('udp_mem', '3145728 4194304 6291456', $st) !== '',
      sysctlValidate('udp_mem', '3145728 4194304 6291456', $st));
check('udp_mem: values that do not increase are refused',
      str_contains(sysctlValidate('udp_mem', '500000 400000 600000', $st), 'increasing'));
check('udp_mem: two numbers instead of three are refused',
      str_contains(sysctlValidate('udp_mem', '100 200', $st), 'three'));
// The adversarial case: strictly increasing, every value a small share of RAM, and still refused —
// because below `min` the kernel never reclaims, so a large min is memory promised away for good.
// The bound is relative (twice what is in force, or a fraction of RAM, whichever is more generous),
// because the kernel's own defaults here are already 9% of memory: a flat 1% rule would have
// refused the factory setting, which is a rule nobody would trust twice.
check('udp_mem: doubling what the kernel itself chose is allowed',
      sysctlValidate('udp_mem', '554814 739754 1109628', $st) === '',
      sysctlValidate('udp_mem', '554814 739754 1109628', $st));
check('udp_mem: TRIPLING the min is refused, even though it is only 28% of RAM',
      str_contains(sysctlValidate('udp_mem', '832221 900000 1000000', $st), 'min'),
      sysctlValidate('udp_mem', '832221 900000 1000000', $st));
check('udp_mem: … and the refusal quotes the largest value it would accept',
      str_contains(sysctlValidate('udp_mem', '832221 900000 1000000', $st), 'pages'));
check('udp_mem: what this machine already has is acceptable',
      sysctlValidate('udp_mem', '277407 369877 554814', $st) === '');
check('an unknown key cannot be validated into existence',
      str_contains(sysctlValidate('kernel.panic', '1', $st), 'unknown'));

check('bytes are rendered the way a human checks them', sysctlHumanBytes(212992) === '208 KiB' && sysctlHumanBytes(8388608) === '8 MiB',
      sysctlHumanBytes(212992) . ' / ' . sysctlHumanBytes(8388608));
check('pages convert both ways against the live page size',
      sysctlPagesToBytes(554814, 4096) === 2272518144 && sysctlBytesToPages(2272518144, 4096) === 554814);
check('a fourfold step is flagged', sysctlBigStep('rmem_max', '8388608', '212992') === true);
check('… and a modest one is not', sysctlBigStep('rmem_max', '262144', '212992') === false);

/* ── 4. the verdict nobody works out by hand ──────────────────────────────── */
//
// The kernel stores sk_rcvbuf = 2 x min(request, rmem_max). A socket sitting at exactly rmem_default
// never asked; for that program raising the CEILING does nothing at all, however many guides say
// otherwise. Getting this backwards sends an operator to the one key that costs every socket.

$vNoAsk = sysctlSocketVerdict(['socket' => ['rb' => 212992], 'values' => ['rmem_max' => '212992', 'rmem_default' => '212992']]);
check('verdict: a socket at the default means the program never asked',
      $vNoAsk['known'] === true && $vNoAsk['asks'] === false);
check('verdict: … and it says raising the ceiling alone would change nothing',
      str_contains($vNoAsk['text'], 'change nothing'));

$vClamped = sysctlSocketVerdict(['socket' => ['rb' => 425984], 'values' => ['rmem_max' => '212992', 'rmem_default' => '212992']]);
check('verdict: a socket at twice the ceiling is being clamped',
      $vClamped['asks'] === true && str_contains($vClamped['text'], 'clamped'));

$vFine = sysctlSocketVerdict(['socket' => ['rb' => 1000000], 'values' => ['rmem_max' => '8388608', 'rmem_default' => '212992']]);
check('verdict: a socket that chose its own size is neither',
      $vFine['asks'] === true && str_contains($vFine['text'], 'not being clamped'));
check('verdict: no measurement, no verdict', sysctlSocketVerdict([])['known'] === false);

/* ── 5. advice is only ever made of measurements ──────────────────────────── */

$stQuiet = $st + ['softnet_dropped' => 0, 'udp_pages_used' => 374, 'netns_shared' => true, 'systemd_run' => true, 'conflicts' => []];
$adv = sysctlAdvice($stQuiet, []);
$text = implode(' ', array_column($adv, 'text'));
check('advice: a queue that never overflowed is left alone, in as many words',
      str_contains($text, 'never overflowed') && str_contains($text, 'Leave it alone'));
check('advice: it names the SSH symptom, because that is what the operator actually saw',
      str_contains($text, 'SSH'));
check('advice: udp_mem is dismissed when nothing is near the pressure threshold',
      str_contains($text, 'raising udp_mem would change nothing'));
check('advice: the socket drops are reported as the one loss these buffers can fix',
      str_contains($text, 'discarded') && str_contains($text, '714,562'));

$stBusy = $stQuiet; $stBusy['softnet_dropped'] = 4211;
check('advice: a queue that HAS overflowed gets the opposite advice, and the per-CPU warning',
      str_contains(implode(' ', array_column(sysctlAdvice($stBusy, []), 'text')), 'only measurement that justifies'));

$stNs = $stQuiet; $stNs['netns_shared'] = false;
check('advice: a private network namespace is called out as a refusal, not a warning',
      (function ($a) { foreach ($a as $x) if ($x['level'] === 'bad' && str_contains($x['text'], 'namespace')) return true; return false; })(sysctlAdvice($stNs, [])));

$autoOn = sysctlAdvice($stQuiet, ['net_auto_enabled' => '1']);
check('advice: the automatic limiter is called out, because it reads this change as distress',
      (function ($a) { foreach ($a as $x) if ($x['level'] === 'bad' && str_contains($x['text'], 'automatic')) return true; return false; })($autoOn));
check('advice: … and is silent when it is off',
      !str_contains(implode(' ', array_column(sysctlAdvice($stQuiet, ['net_auto_enabled' => '0']), 'text')), 'automatic inbound limiter'));

$stConf = $stQuiet; $stConf['conflicts'] = ['/etc/sysctl.d/98-other.conf'];
check('advice: another file setting the same keys is reported, because it wins at the next boot',
      str_contains(implode(' ', array_column(sysctlAdvice($stConf, []), 'text')), '98-other.conf'));

$stNoRun = $stQuiet; $stNoRun['systemd_run'] = false;
check('advice: a missing systemd-run is stated plainly — the watchdog would be the janitor alone',
      str_contains(implode(' ', array_column(sysctlAdvice($stNoRun, []), 'text')), 'janitor timer'));

/* ── 6. suggestions: never invented, never for the keys with no evidence ──── */

$sug = sysctlSuggest($stQuiet);
check('suggest: the ceiling is offered when the socket is losing packets', isset($sug['rmem_max']));
check('suggest: the default is offered ONLY because this socket never asks',
      isset($sug['rmem_default']) && str_contains($sug['rmem_default']['why'], 'never asks'));
$stAsks = $stQuiet; $stAsks['socket']['rb'] = 425984;
check('suggest: a tracker that DOES ask is not sent to the expensive key',
      !isset(sysctlSuggest($stAsks)['rmem_default']));
$stNoDrops = $stQuiet; $stNoDrops['socket']['drops'] = 0;
check('suggest: nothing at all is suggested when nothing is being lost', sysctlSuggest($stNoDrops) === []);
check('suggest: the send side is never suggested — there is no measurement behind it',
      !isset($sug['wmem_max']) && !isset($sug['wmem_default']));
check('suggest: the backlog is never suggested from here either', !isset($sug['netdev_max_backlog']));

/* ── 7. the state, and the removal that must not be sticky ────────────────── */

if (is_dir($root . '/config')) {
    sysctlStateSet(['wanted' => ['a' => 1, 'b' => 2], 'armed' => ['nonce' => 'x']]);
    check('state: what was written comes back', (sysctlState()['wanted']['b'] ?? null) === 2);
    // netlimitStateRead merges recursively over defaults, so a sub-array mutated key by key makes
    // removals sticky: a value the operator stopped managing would quietly reappear.
    sysctlStateSet(['wanted' => ['a' => 1]]);
    $after = sysctlState();
    check('state: a key that was removed stays removed', !isset($after['wanted']['b']), json_encode($after));
    check('state: and the rest of the sub-array went with it', !isset($after['armed']));
    sysctlRequest('arm', ['nonce' => 'deadbeef', 'seconds' => 120, 'pairs' => ['rmem_max=8388608']]);
    check('state: a request records the operation and its arguments',
          (sysctlState()['request']['op'] ?? '') === 'arm' && (sysctlState()['request']['nonce'] ?? '') === 'deadbeef');
    sysctlStateSet([]);
} else {
    skip('state file', 'no config/ directory in this checkout');
}

// The tick is the only writer on the machine, and it must never be reachable from a web request even
// if some future caller forgets. PHP_SAPI is 'cli' here, so the negative case is asserted by reading
// the guard rather than by faking the SAPI.
check('the janitor tick refuses to run under anything but the CLI',
      str_contains(file_get_contents($root . '/includes/sysctl.php'), "if (PHP_SAPI !== 'cli') return \$out;"));
check('a pending revert is honoured even with the feature switched off',
      str_contains(file_get_contents($root . '/includes/sysctl.php'),
                   "if (\$cmdSet && \$req && (\$req['op'] ?? '') === 'revert')"));

/* ── 8. registration, in all four places plus the router ──────────────────── */

$sv = file_get_contents($root . '/api/admin/save_settings.php');
check('registration: the three plumbing keys are in the save allow-list',
      str_contains($sv, "'sysctl_cmd', 'sysctl_enabled', 'sysctl_confirm_seconds'"));
check('registration: the eight VALUES are deliberately not, so a form post cannot change the kernel',
      !str_contains($sv, "'rmem_default'") && !str_contains($sv, "'udp_mem'"));
check('registration: the command is validated on save', str_contains($sv, 'sysctlValidCommand'));
check('registration: the confirmation window is clamped', str_contains($sv, "'sysctl_confirm_seconds' => [60, 900, 120]"));
require_once $root . '/includes/schema.php';
$defaults = trackerSchemaDefaultSettings();
check('registration: schema defaults exist and the feature is off in them',
      ($defaults['sysctl_cmd'] ?? null) === '' && ($defaults['sysctl_enabled'] ?? null) === '0');
check('registration: the schema version was bumped past federation P1', TRACKER_SCHEMA_VERSION >= 16);
check('registration: findable in the settings search',
      str_contains(file_get_contents($root . '/includes/settings_catalog.php'), "'sysctl_cmd'"));
$tpl = file_get_contents($root . '/templates/admin/settings.php');
check('registration: the Settings section exists', str_contains($tpl, 'id="section-sysctl"'));
check('registration: all three controls are on it',
      str_contains($tpl, 'name="sysctl_cmd"') && str_contains($tpl, 'name="sysctl_enabled"')
      && str_contains($tpl, 'name="sysctl_confirm_seconds"'));
$api = file_get_contents($root . '/api.php');
foreach (['admin/sysctl_status', 'admin/sysctl_apply', 'admin/sysctl_test'] as $ep) {
    check('routing: ' . $ep, str_contains($api, "'" . $ep . "'"));
}
// Two hand-maintained arrays that are not derived from the route table: a poller must not drag the
// report janitors along, nor hold the exclusive session lock while a helper runs.
check('routing: the status poll skips the janitors and releases the session',
      substr_count($api, "'admin/sysctl_status'") >= 3, (string)substr_count($api, "'admin/sysctl_status'"));
$traffic = file_get_contents($root . '/templates/admin/traffic.php');
check('the card is gated on the feature being on, so an install that never enabled it never polls',
      str_contains($traffic, 'sysctlEnabled($cfg)') && str_contains($traffic, 'id="sysctl-card"'));
check('the janitor performs the writes', str_contains(file_get_contents($root . '/tools/janitor.php'), 'sysctlTick($cfg)'));

/* ── 9. the real helper, end to end, against a fake kernel ────────────────── */

$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
    $probe = [];
    @exec(escapeshellarg($cand) . ' -c "echo ok" 2>&1', $probe, $rc);
    if ($rc === 0 && trim(implode('', $probe)) === 'ok') { $bash = $cand; break; }
}
$helper = $root . '/tools/opentracker/tracker-sysctl.sh';
if ($bash === null || !trackerExecAvailable() || !is_file($helper)) {
    skip('helper end to end', 'no usable bash (or exec() disabled) — run this suite on the server for that half');
} else {
    $tmp = sys_get_temp_dir() . '/sysctl_test_' . getmypid();
    @mkdir($tmp . '/bin', 0777, true);
    @mkdir($tmp . '/proc/net/core', 0777, true);
    @mkdir($tmp . '/proc/net/ipv4', 0777, true);
    @mkdir($tmp . '/sysctl.d', 0777, true);
    @mkdir($tmp . '/state', 0777, true);
    $posix = static function (string $p): string {
        $p = str_replace('\\', '/', $p);
        if (preg_match('#^([A-Za-z]):/(.*)$#', $p, $m)) return '/' . strtolower($m[1]) . '/' . $m[2];
        return $p;
    };
    // a fake /proc/sys tree the helper can actually write
    file_put_contents($tmp . '/proc/net/core/rmem_max', "212992\n");
    file_put_contents($tmp . '/proc/net/core/wmem_max', "212992\n");
    file_put_contents($tmp . '/proc/net/core/rmem_default', "212992\n");
    file_put_contents($tmp . '/proc/net/core/wmem_default', "212992\n");
    file_put_contents($tmp . '/proc/net/core/netdev_max_backlog', "1000\n");
    file_put_contents($tmp . '/proc/net/ipv4/udp_mem', "277407\t369877\t554814\n");
    file_put_contents($tmp . '/proc/net/ipv4/udp_rmem_min', "4096\n");
    file_put_contents($tmp . '/proc/net/ipv4/udp_wmem_min', "4096\n");

    // the helper refuses to write unless it is root, which no test runner is
    file_put_contents($tmp . '/bin/id', "#!/bin/bash\n[ \"\$1\" = \"-u\" ] && { echo 0; exit 0; }\nexec /usr/bin/id \"\$@\"\n");
    // no systemd in a test; the helper must cope and say so rather than fail
    file_put_contents($tmp . '/bin/systemctl', "#!/bin/bash\nexit 0\n");
    @chmod($tmp . '/bin/id', 0755);
    @chmod($tmp . '/bin/systemctl', 0755);
    $pathBefore = (string)getenv('PATH');
    putenv('PATH=' . $tmp . DIRECTORY_SEPARATOR . 'bin' . PATH_SEPARATOR . $pathBefore);

    // Environment through putenv, not a `VAR=x cmd` prologue: PHP's exec goes through cmd.exe on
    // Windows, which has no such syntax and simply reports the assignment as an unknown command.
    foreach ([
        'SYSCTL_D_DIR'  => $posix($tmp . '/sysctl.d'),
        'CONF_FILE'     => $posix($tmp . '/sysctl.d/99-tracker-panel.conf'),
        'STATE_DIR'     => $posix($tmp . '/state'),
        'BASELINE_FILE' => $posix($tmp . '/state/sysctl-baseline.json'),
        'ARM_FILE'      => $posix($tmp . '/state/sysctl-armed.json'),
        'PROC_SYS'      => $posix($tmp . '/proc'),
        'SYSTEMD_RUN'   => '/nonexistent-systemd-run',
    ] as $ev => $val) putenv($ev . '=' . $val);
    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $run = static function (string $args) use ($bashCmd, $helper, $posix): array {
        $cmd = $bashCmd . ' ' . escapeshellarg($posix($helper)) . ' ' . $args . ' 2>&1';
        $out = []; $rc = null;
        @exec($cmd, $out, $rc);
        $json = null;
        foreach (array_reverse($out) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') { $json = json_decode($line, true); if (is_array($json)) break; $json = null; }
        }
        return ['rc' => (int)$rc, 'out' => implode("\n", $out), 'json' => $json];
    };

    $r = $run('status');
    check('helper: status answers with JSON', is_array($r['json']) && ($r['json']['ok'] ?? null) === true, $r['out']);
    check('helper: it reads all eight values out of the tree',
          count((array)($r['json']['values'] ?? [])) === 8, json_encode($r['json']['values'] ?? null));
    check('helper: udp_mem comes back as three numbers on one line',
          ($r['json']['values']['udp_mem'] ?? '') === '277407 369877 554814', $r['json']['values']['udp_mem'] ?? '');
    check('helper: no file of ours yet', ($r['json']['file_present'] ?? null) === false);
    check('helper: it reports the page size rather than assuming one', is_int($r['json']['page_size'] ?? null));

    // Validation happens before anything is touched.
    foreach (['preview kernel.panic=1' => 'unknown key',
              'preview rmem_max=10' => 'below',
              'preview udp_mem=600000_500000_700000' => 'increasing',
              'preview netdev_max_backlog=999999' => 'PER CPU'] as $args => $why) {
        $bad = $run($args);
        check('helper: refuses ' . $args, ($bad['json']['ok'] ?? true) === false, $bad['out']);
    }
    check('helper: nothing was written by a refused preview', !is_file($tmp . '/sysctl.d/99-tracker-panel.conf'));

    $pv = $run('preview rmem_max=8388608 udp_rmem_min=16384');
    check('helper: preview renders the file it would write',
          ($pv['json']['ok'] ?? false) && str_contains((string)($pv['json']['content'] ?? ''), 'net.core.rmem_max = 8388608'),
          $pv['out']);
    check('helper: preview mentions only the keys asked for',
          !str_contains((string)($pv['json']['content'] ?? ''), 'rmem_default'));
    check('helper: preview writes nothing', !is_file($tmp . '/sysctl.d/99-tracker-panel.conf'));

    // arm: capture the baseline, change the running values, verify each one landed
    $arm = $run('arm 120 abc123 rmem_max=8388608 udp_rmem_min=16384');
    check('helper: arm succeeds against a writable tree', ($arm['json']['ok'] ?? false) === true, $arm['out']);
    check('helper: it reports per-key whether the write actually landed',
          ($arm['json']['keys']['rmem_max']['landed'] ?? null) === true
          && ($arm['json']['all_landed'] ?? null) === true, json_encode($arm['json']['keys'] ?? null));
    check('helper: the running value really changed', trim((string)@file_get_contents($tmp . '/proc/net/core/rmem_max')) === '8388608');
    check('helper: the baseline was captured BEFORE the change', is_file($tmp . '/state/sysctl-baseline.json'));
    $base = json_decode((string)@file_get_contents($tmp . '/state/sysctl-baseline.json'), true);
    check('helper: … and it records what the machine had, not what it has now',
          ($base['values']['rmem_max'] ?? '') === '212992', json_encode($base['values'] ?? null));
    check('helper: with no systemd-run, it says the watchdog could not be scheduled rather than pretending',
          ($arm['json']['watchdog'] ?? '') === 'none', (string)($arm['json']['watchdog'] ?? ''));
    check('helper: still nothing in /etc — a reboot is a complete undo until confirm',
          !is_file($tmp . '/sysctl.d/99-tracker-panel.conf'));

    // A second arm must not overwrite the record of what the machine originally looked like.
    $run('arm 120 def456 rmem_max=4194304');
    $base2 = json_decode((string)@file_get_contents($tmp . '/state/sysctl-baseline.json'), true);
    check('helper: a second arm does NOT overwrite the original baseline',
          ($base2['values']['rmem_max'] ?? '') === '212992', json_encode($base2['values'] ?? null));

    // confirm is refused for the wrong change
    $bad = $run('confirm abc123');
    check('helper: confirm refuses a nonce that is not the armed one', ($bad['json']['ok'] ?? true) === false, $bad['out']);

    $ok = $run('confirm def456');
    check('helper: confirm writes the file', ($ok['json']['ok'] ?? false) === true && is_file($tmp . '/sysctl.d/99-tracker-panel.conf'), $ok['out']);
    $conf = (string)@file_get_contents($tmp . '/sysctl.d/99-tracker-panel.conf');
    check('helper: the file says how to undo it without the panel', str_contains($conf, 'revert'));
    check('helper: the file records the page size it was written with', str_contains($conf, 'page size at write time'));
    check('helper: the file contains only the confirmed key', str_contains($conf, 'net.core.rmem_max = 4194304') && !str_contains($conf, 'udp_rmem_min'));

    // revert: the captured values come back, the file goes, and nothing else is touched
    $rev = $run('revert');
    check('helper: revert reports what it restored', ($rev['json']['ok'] ?? false) === true && (int)($rev['json']['restored'] ?? 0) >= 8, $rev['out']);
    check('helper: the running value is back to the captured one',
          trim((string)@file_get_contents($tmp . '/proc/net/core/rmem_max')) === '212992');
    check('helper: udp_rmem_min is back too', trim((string)@file_get_contents($tmp . '/proc/net/ipv4/udp_rmem_min')) === '4096');
    check('helper: the file is gone', !is_file($tmp . '/sysctl.d/99-tracker-panel.conf') && ($rev['json']['file_removed'] ?? null) === true);
    check('helper: revert is idempotent', ($run('revert')['json']['ok'] ?? false) === true);

    // A baseline holding a value the allow-list would reject must not be replayed back into the
    // kernel: revert re-validates every key rather than applying a file wholesale as root.
    file_put_contents($tmp . '/state/sysctl-baseline.json',
        '{"captured_at":1,"page_size":4096,"values":{"rmem_max":"999999999999","udp_rmem_min":"8192"}}');
    $run('revert');
    check('helper: revert re-validates the baseline instead of replaying it',
          trim((string)@file_get_contents($tmp . '/proc/net/core/rmem_max')) === '212992',
          trim((string)@file_get_contents($tmp . '/proc/net/core/rmem_max')));
    check('helper: … while still restoring the values that are sane',
          trim((string)@file_get_contents($tmp . '/proc/net/ipv4/udp_rmem_min')) === '8192');

    // The watchdog revert must not cancel the unit it is running inside. A transient unit that stops
    // itself never reaches its next command, so the machine keeps the change and the journal shows a
    // watchdog that "ran" -- found on the live server, not in review.
    $sh = file_get_contents($root . '/tools/opentracker/tracker-sysctl.sh');
    check('helper: the scheduled revert is invoked with --watchdog',
          str_contains($sh, '"$SELF" revert --watchdog'));
    check('helper: … and that path skips cancelling units, because it is one of them',
          str_contains($sh, '[ "$from_watchdog" = 1 ] || cancel_reverts'));
    file_put_contents($tmp . '/proc/net/ipv4/udp_rmem_min', "16384
");
    file_put_contents($tmp . '/state/sysctl-baseline.json',
        '{"captured_at":1,"page_size":4096,"values":{"udp_rmem_min":"4096"}}');
    $wd = $run('revert --watchdog');
    check('helper: a watchdog revert still restores the captured value',
          ($wd['json']['ok'] ?? false) === true
          && trim((string)@file_get_contents($tmp . '/proc/net/ipv4/udp_rmem_min')) === '4096', $wd['out']);
    check('helper: … and says which kind of revert it was', ($wd['json']['watchdog'] ?? null) === true);

    $chk = $run('check');
    check('helper: check answers with JSON and reports its version',
          is_array($chk['json']) && is_int($chk['json']['version'] ?? null), $chk['out']);
    check('helper: check notices the missing systemd-run', ($chk['json']['systemd_run'] ?? null) === false);

    putenv('PATH=' . $pathBefore);
    foreach (['SYSCTL_D_DIR', 'CONF_FILE', 'STATE_DIR', 'BASELINE_FILE', 'ARM_FILE', 'PROC_SYS', 'SYSTEMD_RUN'] as $ev) putenv($ev);
    array_map('unlink', glob($tmp . '/proc/net/core/*') ?: []);
    array_map('unlink', glob($tmp . '/proc/net/ipv4/*') ?: []);
    array_map('unlink', glob($tmp . '/sysctl.d/*') ?: []);
    array_map('unlink', glob($tmp . '/state/*') ?: []);
    array_map('unlink', glob($tmp . '/bin/*') ?: []);
    foreach (['/proc/net/core', '/proc/net/ipv4', '/proc/net', '/proc', '/sysctl.d', '/state', '/bin', ''] as $d) @rmdir($tmp . $d);
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
