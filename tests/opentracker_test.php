<?php
/**
 * Test for includes/opentracker.php and tools/opentracker/tracker-instance.sh:
 *   php tests/opentracker_test.php
 *
 * Two halves, like the netlimit suite: the pure settings/validation logic (no database, no root),
 * and the root helper driven end to end against a stub systemctl and throwaway config files.
 *
 * What is being defended here is not the tuning — it is the promise that the panel writes ONE file
 * and never anybody else's, and that a value systemd cannot parse is refused HERE rather than at the
 * next restart, when it would take the tracker down instead of showing an error.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/opentracker.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

// ── 1. settings accessors ────────────────────────────────────────────────────
check('nice: default is -2', otNice([]) === -2);
check('nice: clamped to systemd\'s range', otNice(['ot_nice' => '-99']) === OT_NICE_MIN && otNice(['ot_nice' => '99']) === OT_NICE_MAX);
check('cpu weight: default 100', otCpuWeight([]) === 100);
check('cpu weight: clamped', otCpuWeight(['ot_cpu_weight' => '0']) === OT_WEIGHT_MIN && otCpuWeight(['ot_cpu_weight' => '999999']) === OT_WEIGHT_MAX);
check('nofile: default 65536', otLimitNofile([]) === 65536);
check('nofile: clamped', otLimitNofile(['ot_limit_nofile' => '1']) === OT_NOFILE_MIN);
// Empty is a real answer and must survive as one: it means "do not touch opentracker's own config".
check('workers: empty means leave alone', otUdpWorkers([]) === 0 && otUdpWorkers(['ot_udp_workers' => '']) === 0);
check('workers: a number is kept', otUdpWorkers(['ot_udp_workers' => '6']) === 6);
check('workers: rubbish is not a number', otUdpWorkers(['ot_udp_workers' => 'four']) === 0);

// ── 2. what systemd will and will not accept ────────────────────────────────
// A CPUAffinity systemd cannot parse makes the unit refuse to START — so the mistake must be caught
// when it is typed, not at the next restart.
check('affinity: empty is fine', otValidAffinity(''));
check('affinity: a range', otValidAffinity('2-5'));
check('affinity: a list', otValidAffinity('0 2 4'));
check('affinity: a comma list', otValidAffinity('0,2,4'));
check('affinity: mixed', otValidAffinity('0,2-5'));
check('affinity: letters are refused', !otValidAffinity('two'));
check('affinity: a shell metacharacter is refused', !otValidAffinity('2-5; rm -rf /'));
check('affinity: a backwards range is refused', !otValidAffinity('5-2'));
check('affinity: a semicolon is refused', !otValidAffinity('0;1'));

check('command: the default is accepted', otValidCommand('sudo -n /usr/local/sbin/tracker-instance.sh'));
check('command: empty is refused', !otValidCommand(''));
check('command: a pipe is refused', !otValidCommand('sudo -n /bin/x | tee /etc/passwd'));
check('command: a semicolon is refused', !otValidCommand('sudo -n /bin/x; id'));
check('command: backticks are refused', !otValidCommand('sudo -n `id`'));

// ── 3. the advice the card prints ───────────────────────────────────────────
$a = otAdvice(['cpus' => 6, 'workers' => 4, 'workers_consistent' => true, 'rmem_max' => 212992, 'socket_drops' => 555378]);
$txt = implode(' ', array_column($a, 'text'));
check('advice: names the worker/core ratio', str_contains($txt, '4 UDP worker threads on 6 cores'), $txt);
check('advice: reports the receive-buffer cap', str_contains($txt, '212,992'), $txt);
check('advice: says packets were actually lost there', str_contains($txt, '555,378'), $txt);
check('advice: gives the command instead of doing it', str_contains($txt, 'sysctl -w net.core.rmem_max'), $txt);
check('advice: a full buffer is a warning, not a note',
      in_array('warn', array_column($a, 'level'), true));

$b = otAdvice(['cpus' => 6, 'workers' => 4, 'workers_consistent' => false, 'rmem_max' => 8388608, 'socket_drops' => 0]);
$btxt = implode(' ', array_column($b, 'text'));
check('advice: disagreeing config files are called out', str_contains($btxt, 'disagree about the worker count'), $btxt);
check('advice: a big enough buffer is not mentioned at all', !str_contains($btxt, 'rmem_max'), $btxt);
$c = otAdvice(['cpus' => 2, 'workers' => 8, 'workers_consistent' => true, 'rmem_max' => 8388608, 'socket_drops' => 0]);
check('advice: more workers than cores is a warning',
      str_contains(implode(' ', array_column($c, 'text')), 'compete with each other'));
$d = otAdvice(['cpus' => 6, 'workers' => 4, 'workers_consistent' => true, 'rmem_max' => 8388608,
               'socket_drops' => 0, 'other_dropins' => ['override.conf', 'limits.conf']]);
check('advice: other drop-ins are named and declared untouched',
      str_contains(implode(' ', array_column($d, 'text')), 'never touched by the panel'));

// ── 4. a deferred apply must survive the sandbox ────────────────────────────
// The panel's PHP cannot write /etc when php-fpm runs with ProtectSystem, which is exactly the kind
// of machine most likely to want these knobs. An Apply pressed in the browser therefore records what
// was wanted and the janitor writes it; without that the button would simply not work there.
if (is_file($root . '/config/database.php')) {
    require_once $root . '/config/database.php';
    require_once $root . '/includes/settings.php';
    otMarkPending(false);
    check('deferred apply: nothing pending to begin with', otPending() === false);
    $t = otTick(['ot_perf_cmd' => 'sudo -n /usr/local/sbin/tracker-instance.sh']);
    check('deferred apply: the tick does nothing when nothing is pending',
          $t['pending'] === false && $t['applied'] === false, json_encode($t));
    otMarkPending(true);
    check('deferred apply: the flag survives a round trip through the state file', otPending() === true);
    $t = otTick(['ot_perf_cmd' => '']);
    check('deferred apply: with no helper configured the tick stays quiet', $t['pending'] === false, json_encode($t));
    $t = otTick(['ot_perf_cmd' => '/nonexistent/nope.sh']);
    check('deferred apply: a broken helper is reported, not swallowed',
          $t['pending'] === true && $t['applied'] === false && $t['error'] !== null, json_encode($t));
    check('deferred apply: … and it stays pending so the next tick retries', otPending() === true);
    otMarkPending(false);
} else {
    skip('deferred apply', 'no local database configured — that half needs the state file');
}

// ── 5. the root helper, end to end against stubs ────────────────────────────
$bash = null;
foreach (['bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe', '/bin/bash'] as $cand) {
    $out = []; $rc = null;
    @exec(escapeshellarg($cand) . ' -c "echo ok" 2>&1', $out, $rc);
    if ($rc === 0 && trim(implode('', $out)) === 'ok') { $bash = $cand; break; }
}
$helper = $root . '/tools/opentracker/tracker-instance.sh';
check('helper script is in the repo', is_file($helper));

if ($bash === null || !trackerExecAvailable()) {
    skip('helper: end to end against a stub systemctl', 'no usable bash (or exec() disabled) — run this suite on the server for that half');
} else {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ot_test_' . getmypid();
    @mkdir($tmp . '/bin', 0777, true);
    @mkdir($tmp . '/dropin', 0777, true);
    $posix = static fn(string $p): string => str_replace('\\', '/', $p);

    // stub systemctl: enough of the surface for status/apply/reset/restart
    file_put_contents($tmp . '/bin/systemctl', <<<'STUB'
#!/bin/bash
S="${OT_STUB_STATE:?}"
case "$1" in
  show) for a in "$@"; do case "$a" in -p) shift; k="$2";; esac; done
        k=""; for i in $(seq 1 $#); do :; done
        # -p NAME --value
        prev=""; for a in "$@"; do [ "$prev" = "-p" ] && k="$a"; prev="$a"; done
        grep -E "^$k=" "$S/props" 2>/dev/null | head -1 | cut -d= -f2- ; exit 0 ;;
  is-active) cat "$S/active" 2>/dev/null || echo inactive; exit 0 ;;
  cat) [ -f "$S/unit" ] && cat "$S/unit" && exit 0; exit 1 ;;
  daemon-reload) touch "$S/reloaded"; exit 0 ;;
  restart) echo active > "$S/active"; touch "$S/restarted"; exit 0 ;;
esac
exit 0
STUB);
    @chmod($tmp . '/bin/systemctl', 0755);
    file_put_contents($tmp . '/state_unit', '');
    @mkdir($tmp . '/state', 0777, true);
    file_put_contents($tmp . '/state/unit', "[Service]\n");
    file_put_contents($tmp . '/state/active', "active\n");
    file_put_contents($tmp . '/state/props', "Nice=-2\nCPUWeight=100\nCPUAffinity=\nLimitNOFILE=65536\n");
    // two mode config files that agree, as the panel keeps them
    file_put_contents($tmp . '/opentracker.conf.white', "listen.udp.workers 4\naccess.whitelist /x\n");
    file_put_contents($tmp . '/opentracker.conf.black', "listen.udp.workers 4\naccess.blacklist /y\n");

    putenv('OT_STUB_STATE=' . $posix($tmp . '/state'));
    putenv('OT_UNIT=opentracker');
    putenv('OT_DROPIN_DIR=' . $posix($tmp . '/dropin'));
    putenv('OT_CONFS=' . $posix($tmp . '/opentracker.conf.white') . ' ' . $posix($tmp . '/opentracker.conf.black'));
    putenv('SYSTEMCTL_BIN=' . $posix($tmp . '/bin/systemctl'));
    // the helper refuses to write anything unless it is root, which no test runner is
    file_put_contents($tmp . '/bin/id', "#!/bin/bash\n[ \"\$1\" = \"-u\" ] && { echo 0; exit 0; }\nexec /usr/bin/id \"\$@\"\n");
    @chmod($tmp . '/bin/id', 0755);
    $pathBefore = (string)getenv('PATH');
    putenv('PATH=' . $tmp . DIRECTORY_SEPARATOR . 'bin' . PATH_SEPARATOR . $pathBefore);

    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $run = static function (string $args) use ($bashCmd, $helper, $posix): array {
        $cmd = $bashCmd . ' ' . escapeshellarg($posix($helper)) . ' ' . $args . ' 2>&1';
        $out = []; $rc = null;
        @exec($cmd, $out, $rc);
        $txt = implode("\n", $out);
        $json = null;
        foreach (array_reverse($out) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') { $json = json_decode($line, true); if (is_array($json)) break; $json = null; }
        }
        return ['rc' => (int)$rc, 'out' => $txt, 'json' => $json];
    };

    $r = $run('status');
    check('helper: status answers with JSON', is_array($r['json']) && ($r['json']['ok'] ?? null) === true, $r['out']);
    check('helper: reads the worker count out of the config', (int)($r['json']['workers'] ?? 0) === 4, $r['out']);
    check('helper: notices the two config files agree', ($r['json']['workers_consistent'] ?? null) === true);
    check('helper: no drop-in of ours yet', ($r['json']['dropin_present'] ?? null) === false);
    check('helper: every numeric field really is a number',
          is_int($r['json']['rmem_max'] ?? null) && is_int($r['json']['socket_drops'] ?? null)
          && is_int($r['json']['udp_rcv_errors'] ?? null), json_encode($r['json']));

    // ── the measurement that decides whether a second instance is worth building ──
    //
    // The plan gates extra tracker instances on evidence, so the evidence has to be trustworthy: raw
    // counters, one entry per thread, from the same clock as the machine-wide figures. The panel
    // subtracts consecutive polls to get a rate — which means a missing or mistyped field here does
    // not fail loudly, it silently produces a plausible wrong percentage.
    check('helper: reports the clock ticks a second, or the panel cannot convert anything',
          is_int($r['json']['clk_tck'] ?? null), json_encode($r['json']['clk_tck'] ?? null));
    check('helper: reports machine-wide busy and idle ticks from the same clock',
          is_int($r['json']['cpu_busy_ticks'] ?? null) && is_int($r['json']['cpu_idle_ticks'] ?? null),
          json_encode([$r['json']['cpu_busy_ticks'] ?? null, $r['json']['cpu_idle_ticks'] ?? null]));
    check('helper: threads is always a list, even where there is no tracker to look at',
          is_array($r['json']['threads'] ?? null), json_encode($r['json']['threads'] ?? null));
    foreach ((array)($r['json']['threads'] ?? []) as $t) {
        check('helper: a thread entry carries tid, name and ticks, all of the right type',
              is_int($t['tid'] ?? null) && is_string($t['name'] ?? null) && is_int($t['ticks'] ?? null),
              json_encode($t));
        break;   // one is enough — they come from the same loop
    }

    // The per-socket drop counter is buried in ss's skmem blob as `,d<N>`. It used to be pulled out
    // with a sed backreference that had been mangled into a stray control byte: the pattern still
    // matched, the substitution produced rubbish, the uint guard rejected it and the helper reported
    // a confident zero for ever. A measurement that fails by reading "healthy" is worse than one
    // that fails loudly, so this drives the real helper against a stub `ss` on PATH.
    $ssStub = "#!/bin/bash
cat <<'OUT'
UNCONN 1280   5376   *:6969   *:*
	 skmem:(r1280,rb212992,t5376,tb212992,f2816,w0,o0,bl0,d618712)
OUT
";
    file_put_contents($tmp . '/bin/ss', $ssStub);
    @chmod($tmp . '/bin/ss', 0755);
    $r2 = $run('status');
    check('helper: the socket drop counter is really parsed out of ss output',
          (int)($r2['json']['socket_drops'] ?? -1) === 618712, json_encode($r2['json']['socket_drops'] ?? null));

    file_put_contents($tmp . '/bin/ss', "#!/bin/bash
echo 'UNCONN 0 0 *:6969 *:*'
");
    @chmod($tmp . '/bin/ss', 0755);
    $r3 = $run('status');
    check('helper: a socket with no drop counter reads 0 rather than breaking the JSON',
          ($r3['json']['ok'] ?? null) === true && (int)($r3['json']['socket_drops'] ?? -1) === 0,
          json_encode($r3['json']['socket_drops'] ?? null));

    // validation happens before anything is written
    foreach (['apply 99 100 "" 65536' => 'nice out of range',
              'apply -2 0 "" 65536' => 'weight out of range',
              'apply -2 100 "nonsense" 65536' => 'affinity that systemd cannot parse',
              'apply -2 100 "" 10' => 'file limit too small',
              'workers 0' => 'zero workers',
              'workers 9999' => 'absurd worker count'] as $args => $what) {
        $bad = $run($args);
        check("helper: refuses $what", $bad['rc'] !== 0 && ($bad['json']['ok'] ?? null) === false, $bad['out']);
    }
    check('helper: nothing was written while validating', !is_file($tmp . '/dropin/90-tracker-panel.conf'));

    $r = $run('apply -5 200 "0-3" 131072 --dry-run');
    check('helper: dry-run renders without writing', ($r['json']['dry_run'] ?? null) === true && !is_file($tmp . '/dropin/90-tracker-panel.conf'));
    $content = (string)($r['json']['content'] ?? '');
    check('helper: the drop-in carries every value', str_contains($content, 'Nice=-5') && str_contains($content, 'CPUWeight=200')
          && str_contains($content, 'LimitNOFILE=131072') && str_contains($content, 'CPUAffinity=0-3'), $content);
    check('helper: it says whose file it is', str_contains($content, '[Service]') && str_contains($content, 'do not edit by hand'), $content);

    // an affinity nobody set must be ABSENT, not blank: `CPUAffinity=` is how you reset it, which
    // reads like a decision the admin never made
    $r = $run('apply -2 100 "" 65536 --dry-run');
    check('helper: an unset affinity is omitted entirely', !str_contains((string)($r['json']['content'] ?? ''), 'CPUAffinity'), (string)($r['json']['content'] ?? ''));

    $r = $run('apply -5 200 "0-3" 131072');
    check('helper: apply writes the drop-in', $r['rc'] === 0 && is_file($tmp . '/dropin/90-tracker-panel.conf'), $r['out']);
    check('helper: … and reloads systemd', is_file($tmp . '/state/reloaded'));
    check('helper: … and says a restart is still needed for some of it',
          ($r['json']['restart_needed'] ?? null) === true && str_contains((string)($r['json']['why'] ?? ''), 'CPUAffinity'), $r['out']);

    // THE promise: only our file, ever
    file_put_contents($tmp . '/dropin/override.conf', "[Service]\nNice=-2\n");
    file_put_contents($tmp . '/dropin/limits.conf', "[Service]\nLimitNOFILE=65536\n");
    $r = $run('apply -1 300 "" 200000');
    check('helper: a second apply still only touches our file', $r['rc'] === 0
          && file_get_contents($tmp . '/dropin/override.conf') === "[Service]\nNice=-2\n"
          && file_get_contents($tmp . '/dropin/limits.conf') === "[Service]\nLimitNOFILE=65536\n");
    $r = $run('status');
    check('helper: status lists the files it does not own',
          in_array('override.conf', (array)($r['json']['other_dropins'] ?? []), true)
          && in_array('limits.conf', (array)($r['json']['other_dropins'] ?? []), true), json_encode($r['json']['other_dropins'] ?? null));

    // workers: both files, always
    $r = $run('workers 6');
    check('helper: workers written', $r['rc'] === 0 && (int)($r['json']['workers'] ?? 0) === 6, $r['out']);
    check('helper: … to BOTH mode files',
          str_contains((string)@file_get_contents($tmp . '/opentracker.conf.white'), 'listen.udp.workers 6')
          && str_contains((string)@file_get_contents($tmp . '/opentracker.conf.black'), 'listen.udp.workers 6'));
    check('helper: … without disturbing the rest of the config',
          str_contains((string)@file_get_contents($tmp . '/opentracker.conf.white'), 'access.whitelist /x')
          && str_contains((string)@file_get_contents($tmp . '/opentracker.conf.black'), 'access.blacklist /y'));
    check('helper: … and says opentracker will not notice until a restart',
          ($r['json']['restart_needed'] ?? null) === true, $r['out']);

    // a config with no such line gets one rather than being ignored
    file_put_contents($tmp . '/opentracker.conf.white', "access.whitelist /x\n");
    $r = $run('workers 3');
    check('helper: a config without the line gets it added',
          str_contains((string)@file_get_contents($tmp . '/opentracker.conf.white'), 'listen.udp.workers 3'), $r['out']);

    // reset removes ours and nothing else — and deliberately leaves the worker count alone
    $r = $run('reset --dry-run');
    check('helper: reset --dry-run changes nothing', ($r['json']['dry_run'] ?? null) === true && is_file($tmp . '/dropin/90-tracker-panel.conf'));
    $r = $run('reset');
    check('helper: reset removes our drop-in', $r['rc'] === 0 && !is_file($tmp . '/dropin/90-tracker-panel.conf'), $r['out']);
    check('helper: reset leaves everybody else\'s files alone',
          is_file($tmp . '/dropin/override.conf') && is_file($tmp . '/dropin/limits.conf'));
    // `reset` means "forget what the panel did to the UNIT". listen.udp.workers is opentracker's
    // own setting and may well be what the installer chose, so it is deliberately left as it is.
    // (The last `workers` call above set 3 in both files, which is what should still be there.)
    check('helper: reset does NOT undo the worker count',
          str_contains((string)@file_get_contents($tmp . '/opentracker.conf.black'), 'listen.udp.workers 3'),
          (string)@file_get_contents($tmp . '/opentracker.conf.black'));
    check('helper: … and says why', str_contains((string)($r['json']['note'] ?? ''), 'opentracker'), (string)($r['json']['note'] ?? ''));

    $r = $run('restart');
    check('helper: restart reports the service came back', $r['rc'] === 0 && ($r['json']['active'] ?? null) === true, $r['out']);

    $r = $run('check');
    check('helper: check answers with JSON', is_array($r['json']) && isset($r['json']['systemctl']), $r['out']);

    foreach (['/bin/systemctl', '/bin/id', '/dropin/override.conf', '/dropin/limits.conf', '/dropin/90-tracker-panel.conf',
              '/opentracker.conf.white', '/opentracker.conf.black', '/state/unit', '/state/active', '/state/props',
              '/state/reloaded', '/state/restarted', '/state_unit'] as $f) @unlink($tmp . $f);
    foreach (['/bin', '/dropin', '/state', ''] as $d) @rmdir($tmp . $d);
    putenv('PATH=' . $pathBefore);
    foreach (['OT_STUB_STATE', 'OT_UNIT', 'OT_DROPIN_DIR', 'OT_CONFS', 'SYSTEMCTL_BIN'] as $v) putenv($v);
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
