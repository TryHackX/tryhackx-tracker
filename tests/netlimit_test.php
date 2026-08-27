<?php
/**
 * Tests for the inbound UDP monitor / rate limit:
 *   php tests/netlimit_test.php
 *
 * Two halves:
 *   1. the pure PHP of includes/netlimit.php — clamps, command validation, counters → rates
 *      (including the counter-reset case), percentiles / recommendation, bucketing and the
 *      automatic mode's hysteresis. No database, no network, no root: safe anywhere.
 *   2. the root helper tools/opentracker/tracker-netlimit.sh, driven end to end against a stub
 *      `nft` (and a stub `id` so the root check passes) in a temporary directory — argument
 *      validation, the generated ruleset, apply / status / off / egress and the detection of a
 *      foreign rate-limit rule on the same port. Skipped with a visible SKIP line when the machine
 *      has no bash, so the suite still runs on a bare Windows checkout.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void {
    global $skips;
    $skips++;
    echo 'SKIP ' . $name . '  -> ' . $why . "\n";
}

// ── 1. settings clamps ───────────────────────────────────────────────────────
check('pps default', netlimitPps([]) === 30000);
check('pps clamped low', netlimitPps(['net_limit_pps' => '5']) === NET_PPS_MIN);
check('pps clamped high', netlimitPps(['net_limit_pps' => '99999999']) === NET_PPS_MAX);
check('pps garbage falls back', netlimitPps(['net_limit_pps' => 'abc']) === 30000);
check('burst default', netlimitBurst([]) === 100);
check('burst clamped', netlimitBurst(['net_limit_burst' => '0']) === NET_BURST_MIN && netlimitBurst(['net_limit_burst' => '999999']) === NET_BURST_MAX);
check('port default', netlimitPort([]) === 6969);
check('port clamped', netlimitPort(['net_limit_port' => '0']) === 1 && netlimitPort(['net_limit_port' => '70000']) === 65535);
check('sample seconds clamped', netlimitSampleSeconds(['net_sample_seconds' => '1']) === NET_SAMPLE_MIN && netlimitSampleSeconds(['net_sample_seconds' => '99999']) === NET_SAMPLE_MAX);
check('keep days clamped', netlimitKeepDays(['net_keep_days' => '0']) === NET_KEEP_MIN && netlimitKeepDays(['net_keep_days' => '9999']) === NET_KEEP_MAX);
check('everything off by default', !netlimitEnabled([]) && !netlimitMonitorEnabled([]) && !netlimitAutoEnabled([]));
check('enabled reads exactly "1"', netlimitEnabled(['net_limit_enabled' => '1']) && !netlimitEnabled(['net_limit_enabled' => 'yes']));
// an upside-down band must not be able to pin the automatic mode to one value
check('auto max never below auto min', netlimitAutoMax(['net_auto_min' => '50000', 'net_auto_max' => '10000']) === 50000);
check('auto band defaults', netlimitAutoMin([]) === 10000 && netlimitAutoMax([]) === 80000);
check('auto target defaults', netlimitAutoTarget([]) === 30000 && netlimitAutoTargetCpu([]) === 70);
check('cpu target clamped', netlimitAutoTargetCpu(['net_auto_target_cpu' => '5']) === 10 && netlimitAutoTargetCpu(['net_auto_target_cpu' => '500']) === 100);

// ── 2. helper command validation (it is handed to a shell) ───────────────────
check('default command valid', netlimitValidCommand(NET_DEFAULT_CMD));
check('empty command allowed (= feature off)', netlimitValidCommand(''));
foreach (['sudo -n /usr/local/sbin/x.sh; rm -rf /', 'x && y', 'x | y', 'x `id`', 'x $(id)', "x\ny", 'x > /etc/passwd', 'x & y'] as $bad) {
    check('metacharacters refused: ' . str_replace("\n", '\\n', $bad), !netlimitValidCommand($bad));
}
check('over-long command refused', !netlimitValidCommand(str_repeat('a', 256)));
check('command getter blanks an invalid value', netlimitCommand(['net_limit_cmd' => 'x; rm -rf /']) === '');
check('command getter keeps a valid value', netlimitCommand(['net_limit_cmd' => 'sudo -n /opt/t.sh']) === 'sudo -n /opt/t.sh');

// ── 3. counters → rates ──────────────────────────────────────────────────────
$prev = ['in_total' => 1000, 'in_passed' => 900, 'in_capped' => 100];
$cur  = ['in_total' => 7000, 'in_passed' => 6300, 'in_capped' => 700];
$r = netlimitRates($prev, $cur, 60);
check('rate: simple division', $r === ['in_total' => 100, 'in_passed' => 90, 'in_capped' => 10], json_encode($r));
check('rate: span below the floor is refused', netlimitRates($prev, $cur, 1) === null);
check('rate: span above the ceiling is refused', netlimitRates($prev, $cur, NET_LIVE_MAX_SPAN + 1) === null);
// a full reload (a new table, or a changed port) restarts the counters at zero — a difference
// against the old reading would be a huge negative number rendered as a spike
check('rate: counter reset detected', netlimitRates(['in_total' => 5000], ['in_total' => 10], 60) === null);
check('rate: zero traffic is a real answer, not a reset', netlimitRates($prev, $prev, 60) === ['in_total' => 0, 'in_passed' => 0, 'in_capped' => 0]);
check('rate: rounding', netlimitRates(['a' => 0], ['a' => 100], 60) === ['a' => 2]);
check('counter map: missing counters become 0', netlimitCounterPackets(['in_total' => ['packets' => 5]], NET_IN_COUNTERS) === ['in_total' => 5, 'in_passed' => 0, 'in_capped' => 0]);

// ── 4. percentile / recommendation ───────────────────────────────────────────
$vals = range(1, 100);
check('p50 of 1..100', netlimitPercentile($vals, 50) === 50);
check('p95 of 1..100', netlimitPercentile($vals, 95) === 95);
check('p100 of 1..100', netlimitPercentile($vals, 100) === 100);
check('percentile of an empty list', netlimitPercentile([], 95) === 0);
check('percentile of one value', netlimitPercentile([7], 50) === 7 && netlimitPercentile([7], 95) === 7);
check('percentile does not care about order', netlimitPercentile([9, 1, 5, 3, 7], 50) === 5);
check('round step below 100k', netlimitRoundStep(22345) === 22000);
check('round step above 100k', netlimitRoundStep(123456) === 125000);
check('round step never leaves the range', netlimitRoundStep(1) === NET_PPS_MIN && netlimitRoundStep(99999999) === NET_PPS_MAX);

$rec = netlimitRecommendFrom([]);
check('recommendation: no samples', $rec['samples'] === 0 && $rec['suggested'] === 0 && $rec['enough'] === false);
// the plan's worked example: median 22 000, P95 38 000, peak 61 000 → suggest P95 + 5 %
$sample = array_merge(array_fill(0, 90, 22000), array_fill(0, 8, 38000), array_fill(0, 2, 61000));
$rec = netlimitRecommendFrom($sample);
check('recommendation: median', $rec['median'] === 22000, (string)$rec['median']);
check('recommendation: p95', $rec['p95'] === 38000, (string)$rec['p95']);
check('recommendation: peak', $rec['peak'] === 61000, (string)$rec['peak']);
check('recommendation: suggested is P95 + 5 %', $rec['suggested'] === 40000, (string)$rec['suggested']);
check('recommendation: floor is median + 10 %', $rec['floor'] === 24000, (string)$rec['floor']);
check('recommendation: 100 samples is enough', $rec['enough'] === true);
check('recommendation: 59 samples is not enough', netlimitRecommendFrom(array_fill(0, 59, 1000))['enough'] === false);
$rec['days'] = 7;
$text = netlimitRecommendText($rec);
check('recommendation text names the numbers', str_contains($text, '40,000') && str_contains($text, 'never trigger'), $text);
check('recommendation text says what the floor costs', str_contains($text, '24,000') && str_contains($text, 'currently arriving'), $text);
check('recommendation text without samples explains why', str_contains(netlimitRecommendText(netlimitRecommendFrom([])), 'No traffic has been recorded'));
check('recommendation text warns when there is too little data', str_contains(netlimitRecommendText(netlimitRecommendFrom(array_fill(0, 10, 5000)) + ['days' => 7]), 'first impression'));
// On a tracker whose stale swarm keeps calling, P95 of ARRIVALS is the flood, not demand — telling
// the admin to match it would mean "no limit at all", so that case has to say so out loud.
$flood = netlimitRecommendText($rec, true);
check('flood mode warns that arrivals are not demand', str_contains($flood, 'ARRIVALS, not demand'), $flood);
check('flood mode reframes the decision', str_contains($flood, 'willing to hand OpenTracker'));
check('flood mode says why dropping is free', str_contains($flood, 'before the') && str_contains($flood, 'ever sees them'));
check('normal mode does not carry the flood warning', !str_contains($text, 'ARRIVALS, not demand'));

// ── 5. bucketing ─────────────────────────────────────────────────────────────
check('bucket: 24 h of 60 s samples stays raw', netlimitBucketFor(86400, 60) === 0);
check('bucket: 30 d of 60 s samples is bucketed', netlimitBucketFor(2592000, 60) > 0);
check('bucket: 30 d fits under the point cap', (int)ceil(2592000 / netlimitBucketFor(2592000, 60)) <= NET_MAX_POINTS);
check('bucket: 14 d fits under the point cap', netlimitBucketFor(1209600, 60) === 0 || (int)ceil(1209600 / netlimitBucketFor(1209600, 60)) <= NET_MAX_POINTS);
check('bucket: a zero sample interval does not divide by zero', netlimitBucketFor(86400, 0) >= 0);

// ── 6. automatic mode ────────────────────────────────────────────────────────
$cfgAuto = ['net_auto_min' => '10000', 'net_auto_max' => '80000', 'net_auto_target' => '30000', 'net_auto_target_cpu' => '70'];
$st = ['over' => 0, 'under' => 0, 'last_move_at' => 0];
$now = 1800000000;

// one spike must not move anything — the whole point of the hysteresis
$d = netlimitAutoDecide($st, 50000, 40000, $cfgAuto, null, $now);
check('auto: first sample over target holds', $d['action'] === 'hold' && $d['state']['over'] === 1, $d['reason']);
$d = netlimitAutoDecide($d['state'], 50000, 40000, $cfgAuto, null, $now);
check('auto: second sample over target still holds', $d['action'] === 'hold' && $d['state']['over'] === 2);
$d3 = netlimitAutoDecide($d['state'], 50000, 40000, $cfgAuto, null, $now);
check('auto: third sample tightens', $d3['action'] === 'down' && $d3['pps'] === 36000, $d3['action'] . '/' . $d3['pps']);
check('auto: the counters reset after a move', $d3['state']['over'] === 0 && $d3['state']['under'] === 0);
check('auto: the move is recorded', $d3['state']['last_move_at'] === $now && $d3['state']['last_move'] === 'down');

// a sample on the other side clears the streak
$d = netlimitAutoDecide(['over' => 2, 'under' => 0, 'last_move_at' => 0], 10000, 40000, $cfgAuto, null, $now);
check('auto: a sample under target clears the over-streak', $d['state']['over'] === 0 && $d['state']['under'] === 1);
// inside the dead band (0.8×target … target) nothing accumulates at all
$d = netlimitAutoDecide(['over' => 2, 'under' => 0, 'last_move_at' => 0], 27000, 40000, $cfgAuto, null, $now);
check('auto: the dead band clears both streaks', $d['action'] === 'hold' && $d['state']['over'] === 0 && $d['state']['under'] === 0, $d['reason']);

$st3 = ['over' => 0, 'under' => 2, 'last_move_at' => 0];
$d = netlimitAutoDecide($st3, 10000, 40000, $cfgAuto, null, $now);
check('auto: third sample under target loosens', $d['action'] === 'up' && $d['pps'] === 44000, $d['action'] . '/' . $d['pps']);

// the band is a hard stop in both directions
$d = netlimitAutoDecide(['over' => 2, 'under' => 0, 'last_move_at' => 0], 50000, 10000, $cfgAuto, null, $now);
check('auto: never tightens below the band floor', $d['action'] === 'hold' && str_contains($d['reason'], 'floor'), $d['reason']);
$d = netlimitAutoDecide(['over' => 0, 'under' => 2, 'last_move_at' => 0], 10000, 80000, $cfgAuto, null, $now);
check('auto: never loosens above the band ceiling', $d['action'] === 'hold' && str_contains($d['reason'], 'ceiling'), $d['reason']);

// the cool-down stops it walking the limit down once a minute
$d = netlimitAutoDecide(['over' => 3, 'under' => 0, 'last_move_at' => $now - 10], 50000, 40000, $cfgAuto, null, $now);
check('auto: cool-down blocks a second move', $d['action'] === 'hold' && str_contains($d['reason'], 'cool-down'), $d['reason']);
$d = netlimitAutoDecide(['over' => 3, 'under' => 0, 'last_move_at' => $now - NET_AUTO_MIN_INTERVAL - 1], 50000, 40000, $cfgAuto, null, $now);
check('auto: the move is allowed once the cool-down is over', $d['action'] === 'down');

// the CPU guard tightens even while the packet rate is under target
$st = ['over' => 0, 'under' => 0, 'last_move_at' => 0];
for ($i = 0; $i < 2; $i++) $st = netlimitAutoDecide($st, 5000, 40000, $cfgAuto, 0.95, $now)['state'];
$d = netlimitAutoDecide($st, 5000, 40000, $cfgAuto, 0.95, $now);
check('auto: an overloaded machine tightens despite a low packet rate', $d['action'] === 'down' && str_contains($d['reason'], 'load'), $d['reason']);
$st = ['over' => 0, 'under' => 0, 'last_move_at' => 0];
$d = netlimitAutoDecide($st, 5000, 40000, $cfgAuto, 0.30, $now);
check('auto: a quiet machine does not trigger the CPU guard', $d['state']['under'] === 1 && $d['state']['over'] === 0);

// ── 7. the root helper, end to end against a stub nft ─────────────────────────
$helper = $root . '/tools/opentracker/tracker-netlimit.sh';
check('helper script is in the repo', is_file($helper));

$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
    $probe = (str_contains($cand, ' ') ? '"' . $cand . '"' : $cand) . ' -c "echo ok" 2>&1';
    $out = []; $rc = null;
    @exec($probe, $out, $rc);
    if ($rc === 0 && trim(implode('', $out)) === 'ok') { $bash = $cand; break; }
}

if ($bash === null || !trackerExecAvailable()) {
    skip('helper: end-to-end against a stub nft', 'no usable bash (or exec() disabled) on this machine — run the suite on the server for this half');
} else {
    $tmp = sys_get_temp_dir() . '/netlimit_test_' . getmypid();
    @mkdir($tmp . '/bin', 0777, true);
    @mkdir($tmp . '/nftd', 0777, true);
    @mkdir($tmp . '/state', 0777, true);

    // stub nft: enough of the real command's surface for the helper's five actions
    file_put_contents($tmp . '/bin/nft', <<<'STUB'
#!/bin/bash
S="${STUB_STATE:?}"
# On request, write a line to stderr on every call. The helper is invoked with 2>&1, so this is how
# a stray shell/kernel message reaches the panel -- and it must never end up INSIDE the JSON.
[ -n "${STUB_NOISE:-}" ] && printf 'nft: warning: something on stderr\n' >&2
case "$1" in
  -c) shift; [ "$1" = "-f" ] && shift
      grep -q 'SYNTAX_ERROR' "$1" && { echo "syntax error" >&2; exit 1; }; exit 0 ;;
  -f) shift
      grep -q 'SYNTAX_ERROR' "$1" && { echo "load error" >&2; exit 1; }
      cp "$1" "$S/loaded"; touch "$S/t_in"; exit 0 ;;
  -a) shift
      if [ "$1$2" = "listchain" ] && [ "$4" = "ottrack" ]; then
        [ -f "$S/t_out" ] || exit 1
        printf '\t\tlimit rate over %s/second counter name capped drop # handle 12\n' "$(cat "$S/epps" 2>/dev/null || echo 50000)"; exit 0; fi
      if [ "$1$2" = "listchain" ] && [ "$4" = "ottrack_in" ]; then
        [ -f "$S/t_in" ] || exit 1
        sed -n '/chain input/,/^}/p' "$S/loaded" | sed 's/limit rate over.*drop$/& # handle 9/'; exit 0; fi
      if [ "$1$2" = "listtable" ] && [ "$3/$4" = "inet/filter" ] && [ -f "$S/manual" ]; then
        printf 'table inet filter {\n\tchain input {\n\t\tudp dport 6969 limit rate over 30000/second burst 5 packets counter packets 4360134031 bytes 248000000000 drop # handle 7\n\t}\n}\n'; exit 0; fi
      exit 0 ;;
  list)
      case "$2" in
        tables) [ -f "$S/t_in" ] && echo "table inet ottrack_in"
                [ -f "$S/t_out" ] && echo "table inet ottrack"
                [ -f "$S/manual" ] && echo "table inet filter"; exit 0 ;;
        counters)
                if [ "$5" = "ottrack_in" ]; then [ -f "$S/t_in" ] || exit 1
                  printf 'table inet ottrack_in {\n\tcounter in_total {\n\t\tpackets 1000 bytes 64000\n\t}\n\tcounter in_passed {\n\t\tpackets 900 bytes 57600\n\t}\n\tcounter in_capped {\n\t\tpackets 100 bytes 6400\n\t}\n}\n'; exit 0; fi
                if [ "$5" = "ottrack" ]; then [ -f "$S/t_out" ] || exit 1
                  printf 'table inet ottrack {\n\tcounter announce_ok {\n\t\tpackets 20 bytes 1\n\t}\n\tcounter passed_good {\n\t\tpackets 30 bytes 2\n\t}\n\tcounter capped {\n\t\tpackets 40 bytes 3\n\t}\n}\n'; exit 0; fi
                exit 1 ;;
        chain)  if [ "$4" = "ottrack_in" ]; then [ -f "$S/t_in" ] || exit 1; sed -n '/chain input/,/^}/p' "$S/loaded"; exit 0; fi
                if [ "$4" = "ottrack" ]; then [ -f "$S/t_out" ] || exit 1
                  printf '\t\tlimit rate over %s/second counter name capped drop\n' "$(cat "$S/epps" 2>/dev/null || echo 50000)"; exit 0; fi
                exit 1 ;;
        table)  case "$4" in ottrack_in) [ -f "$S/t_in" ] && exit 0 || exit 1 ;;
                             ottrack) [ -f "$S/t_out" ] && exit 0 || exit 1 ;;
                             filter) [ -f "$S/manual" ] && exit 0 || exit 1 ;; esac; exit 1 ;;
      esac; exit 1 ;;
  delete) [ "$2" = "table" ] && [ "$4" = "ottrack_in" ] && { rm -f "$S/t_in"; exit 0; }; exit 1 ;;
  replace)
      p=""; for a in "$@"; do case "$a" in */second) p="${a%/second}" ;; esac; done
      b=""; prev=""; for a in "$@"; do [ "$prev" = "burst" ] && b="$a"; prev="$a"; done
      if [ "$4" = "ottrack_in" ]; then
        sed -i -E "s#limit rate over [0-9]+/second burst [0-9]+ packets#limit rate over $p/second burst $b packets#" "$S/loaded"
        touch "$S/replaced"; exit 0
      fi
      echo "$p" >"$S/epps"; exit 0 ;;
esac
exit 1
STUB);
    // stub id: the helper refuses to touch the firewall unless it is root, which no test runner is
    file_put_contents($tmp . '/bin/id', "#!/bin/bash\n[ \"\$1\" = \"-u\" ] && { echo 0; exit 0; }\nexec /usr/bin/id \"\$@\"\n");
    @chmod($tmp . '/bin/nft', 0755);
    @chmod($tmp . '/bin/id', 0755);
    // the include line must carry the same spelling of the directory the helper is given (the helper
    // greps for it literally), so use the forward-slash form on every platform
    $nftDirPosix = str_replace('\\', '/', $tmp) . '/nftd';
    file_put_contents($tmp . '/nftables.conf', "flush ruleset\ninclude \"$nftDirPosix/*.nft\"\n");

    // The helper's test hooks travel through the environment rather than the command line: quoting a
    // `VAR=x bash -c "…"` prologue portably across cmd.exe and sh is not worth the bugs.
    $posix = static function (string $p): string { return str_replace('\\', '/', $p); };
    $pathBefore = (string)getenv('PATH');
    putenv('STUB_STATE=' . $posix($tmp . '/state'));
    putenv('NFT_BIN=' . $posix($tmp . '/bin/nft'));
    putenv('NFT_DIR=' . $posix($tmp . '/nftd'));
    putenv('NFT_CONF=' . $posix($tmp . '/nftables.conf'));
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

    $r = $run('-h');
    check('helper: bash can run it', $r['rc'] === 0, $r['out']);

    // argument validation — nothing is created and the exit code is non-zero
    foreach ([['set 10', 'rate below the floor'], ['set 99999999', 'rate above the ceiling'], ['set abc', 'non-numeric rate'],
              ['set 30000 0', 'burst below the floor'], ['set 30000 100 70000', 'port out of range'], ['bogus', 'unknown action']] as [$args, $what]) {
        $r = $run($args);
        check("helper: refuses $what", $r['rc'] !== 0 && is_array($r['json']) && $r['json']['ok'] === false, $r['out']);
    }
    check('helper: nothing was written while validating', !is_file($tmp . '/nftd/ottrack-in.nft'));

    // dry-run: valid JSON with the ruleset inside, still nothing on disk
    $r = $run('set 30000 100 6969 --dry-run');
    check('helper: dry-run succeeds', $r['rc'] === 0 && is_array($r['json']) && !empty($r['json']['dry_run']), $r['out']);
    $ruleset = (string)($r['json']['ruleset'] ?? '');
    check('helper: dry-run output is valid JSON with a multi-line ruleset', str_contains($ruleset, "\n") && str_contains($ruleset, 'table inet ottrack_in'));
    check('helper: the ruleset carries the requested rate', str_contains($ruleset, 'limit rate over 30000/second burst 100 packets'));
    check('helper: the ruleset only touches the configured port', str_contains($ruleset, 'udp dport != 6969 accept'));
    // without this line a non-UDP packet would fall past the port match into the drop budget
    check('helper: the ruleset lets non-UDP traffic out of the chain first', str_contains($ruleset, 'meta l4proto != udp accept'));
    check('helper: the chain accepts by default', str_contains($ruleset, 'policy accept'));
    check('helper: the chain runs before the default filter hook', str_contains($ruleset, 'priority filter - 5'));
    // create-if-missing → delete → recreate in ONE nft -f transaction: no unprotected gap
    check('helper: the ruleset replaces itself atomically', str_contains($ruleset, "table inet ottrack_in {}\ndelete table inet ottrack_in"));
    check('helper: it says how to undo itself', str_contains($ruleset, 'nft delete table inet ottrack_in'));
    check('helper: dry-run wrote nothing', !is_file($tmp . '/nftd/ottrack-in.nft'));

    // apply
    touch($tmp . '/state/t_out');     // the egress budget exists on this machine
    touch($tmp . '/state/manual');    // ... and so does a hand-made rule on the same port
    $r = $run('set 40000 200 6969');
    check('helper: apply succeeds', $r['rc'] === 0 && !empty($r['json']['applied']), $r['out']);
    check('helper: the file was persisted', is_file($tmp . '/nftd/ottrack-in.nft'));
    check('helper: it reports persistence correctly', ($r['json']['persistent'] ?? null) === true);

    check('helper: a fresh table is loaded, not replaced', ($r['json']['mode'] ?? '') === 'reload' && !is_file($tmp . '/state/replaced'), (string)($r['json']['mode'] ?? ''));

    $r = $run('status');
    $s = $r['json'];
    check('helper: status is valid JSON', is_array($s), $r['out']);
    check('helper: status sees the table', ($s['table'] ?? null) === true);
    check('helper: status reads the rate back', (int)($s['pps'] ?? 0) === 40000, json_encode($s['pps'] ?? null));
    check('helper: status reads the burst back', (int)($s['burst'] ?? 0) === 200);
    check('helper: status reads the port back', (int)($s['port'] ?? 0) === 6969);
    check('helper: status returns the three counters', isset($s['counters']['in_total']['packets'], $s['counters']['in_passed']['packets'], $s['counters']['in_capped']['packets']));
    check('helper: counters parse as numbers', (int)$s['counters']['in_total']['packets'] === 1000 && (int)$s['counters']['in_capped']['packets'] === 100);
    check('helper: the egress budget is reported', ($s['egress']['table'] ?? null) === true && (int)($s['egress']['pps'] ?? 0) === 50000);
    check('helper: egress counters are reported', (int)($s['egress']['counters']['capped']['packets'] ?? -1) === 40);
    // the admin's own rule in inet filter: reported, never touched
    check('helper: a foreign rule on the same port is reported', count($s['manual_rules'] ?? []) === 1, json_encode($s['manual_rules'] ?? []));
    check('helper: the foreign rule names its table', ($s['manual_rules'][0]['table'] ?? '') === 'filter' && ($s['manual_rules'][0]['family'] ?? '') === 'inet');
    check('helper: the foreign rule comes with a copy-paste undo', ($s['manual_rules'][0]['undo'] ?? '') === 'nft delete rule inet filter input handle 7', $s['manual_rules'][0]['undo'] ?? '');
    check('helper: a full status says so', ($s['brief'] ?? null) === false);

    // The foreign-rule scan dumps every table in the ruleset — on a box with fail2ban that is
    // thousands of lines — so the panel polls with --brief and only rescans every couple of minutes.
    $r = $run('status --brief');
    $b = $r['json'];
    check('helper: --brief still answers with the essentials', is_array($b) && ($b['table'] ?? null) === true && (int)($b['pps'] ?? 0) === 40000, $r['out']);
    check('helper: --brief keeps the counters', (int)($b['counters']['in_total']['packets'] ?? -1) === 1000);
    check('helper: --brief keeps the egress budget', (int)($b['egress']['pps'] ?? 0) === 50000);
    check('helper: --brief skips the expensive scan', ($b['brief'] ?? null) === true && !array_key_exists('manual_rules', $b), json_encode(array_keys($b ?: [])));

    // ── counting-only mode ──
    // The counters live in the firewall, so "measure first, then pick a threshold" needs a table
    // with no drop rule in it at all. Anything else would record zeros and make the suggestion a lie.
    $r = $run('monitor 6969 --dry-run');
    check('helper: monitor --dry-run renders a ruleset', ($r['json']['dry_run'] ?? null) === true && ($r['json']['mode'] ?? '') === 'count', $r['out']);
    $mon = (string)($r['json']['ruleset'] ?? '');
    // the header comment explains the intent in prose, so assert against the RULES only
    $monRules = implode("
", array_filter(explode("
", $mon), fn($l) => !str_starts_with(ltrim($l), '#')));
    check('helper: the counting ruleset has the three counters', str_contains($monRules, 'counter name in_total') && str_contains($monRules, 'counter name in_passed'));
    check('helper: the counting ruleset contains NO drop at all', !str_contains($monRules, 'drop'), $monRules);
    check('helper: … and no rate limit', !str_contains($monRules, 'limit rate'));
    check('helper: it still only looks at the tracker port', str_contains($monRules, 'udp dport != 6969 accept') && str_contains($monRules, 'meta l4proto != udp accept'));
    check('helper: it still accepts by default', str_contains($monRules, 'policy accept'));
    check('helper: its header says it is a counter', str_contains($mon, 'mode=count') && str_contains($mon, 'pps=0'));

    $r = $run('monitor 6969');
    check('helper: monitor applies', ($r['json']['applied'] ?? null) === true && ($r['json']['mode'] ?? '') === 'count', $r['out']);
    $r = $run('status');
    check('helper: status reports the counting mode', ($r['json']['mode'] ?? '') === 'count', json_encode($r['json']['mode'] ?? null));
    check('helper: counting mode reports no rate', (int)($r['json']['pps'] ?? -1) === 0, json_encode($r['json']['pps'] ?? null));
    check('helper: counting mode still returns the counters', isset($r['json']['counters']['in_total']['packets']));
    check('helper: an invalid port is refused for monitor too', $run('monitor 99999')['rc'] !== 0);

    // back to a real limit, and the mode flips
    $r = $run('set 40000 200 6969');
    check('helper: going from counting to limiting reloads the table', ($r['json']['mode'] ?? '') === 'reload', (string)($r['json']['mode'] ?? ''));
    $r = $run('status');
    check('helper: status reports the limiting mode', ($r['json']['mode'] ?? '') === 'limit' && (int)($r['json']['pps'] ?? 0) === 40000, json_encode([$r['json']['mode'] ?? null, $r['json']['pps'] ?? null]));

    // Changing only the rate on a table that is already there must NOT rebuild it: the three counters
    // would restart and the monitor, which reads rates as differences, would lose a chart sample on
    // every automatic ±10 % move.
    $r = $run('set 50000 200 6969');
    check('helper: an existing table is edited in place', ($r['json']['mode'] ?? '') === 'replace' && is_file($tmp . '/state/replaced'), (string)($r['json']['mode'] ?? '') . ' ' . $r['out']);
    $r = $run('status');
    check('helper: the in-place edit took effect', (int)($r['json']['pps'] ?? 0) === 50000, json_encode($r['json']['pps'] ?? null));
    check('helper: the in-place edit is persisted to the file too', str_contains((string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'), 'limit rate over 50000/second'));
    // a different port is a different rule, so that one does go through a full reload
    @unlink($tmp . '/state/replaced');
    $r = $run('set 50000 200 6970');
    check('helper: changing the port reloads the table', ($r['json']['mode'] ?? '') === 'reload', (string)($r['json']['mode'] ?? ''));
    $r = $run('set 40000 200 6969');   // back to the values the rest of the test expects
    check('helper: back on the original port', ($r['json']['mode'] ?? '') === 'reload' && (int)($r['json']['pps'] ?? 0) === 40000);

    // nft omits `burst N packets` from its output when the burst is its own default, so the values
    // are also read back from the generated file's header. Simulate a flushed ruleset: file kept,
    // table gone.
    @unlink($tmp . '/state/t_in');
    $r = $run('status');
    check('helper: values survive a flushed ruleset via the file header',
          ($r['json']['table'] ?? null) === false && (int)($r['json']['pps'] ?? 0) === 40000
          && (int)($r['json']['burst'] ?? 0) === 200 && (int)($r['json']['port'] ?? 0) === 6969,
          json_encode([$r['json']['table'] ?? null, $r['json']['pps'] ?? null, $r['json']['burst'] ?? null]));
    touch($tmp . '/state/t_in');
    $r = $run('status');
    $s = $r['json'];

    // PHP reads exactly what the helper wrote
    $in = netlimitCounterPackets((array)$s['counters'], NET_IN_COUNTERS);
    check('PHP reads the helper counters', $in === ['in_total' => 1000, 'in_passed' => 900, 'in_capped' => 100], json_encode($in));

    // egress rate change (handle-targeted, so the dynamic client sets survive)
    $r = $run('egress 60000');
    check('helper: egress rate applied', $r['rc'] === 0 && (int)($r['json']['pps'] ?? 0) === 60000, $r['out']);
    $r = $run('status');
    check('helper: the new egress rate reads back', (int)($r['json']['egress']['pps'] ?? 0) === 60000);
    $r = $run('egress 10');
    check('helper: egress rate is validated too', $r['rc'] !== 0);

    // ── stray stderr must never land inside the JSON ─────────────────────────
    // This is what broke a healthy firewall in production: dir_writable() probed the directory with
    // `: >"$probe" 2>/dev/null`, bash applies redirections left to right, so the "Read-only file
    // system" message went to the REAL stderr before 2>/dev/null existed -- from inside a command
    // substitution in the middle of building the reply. The line was spliced into the JSON, PHP
    // could not parse it, and the card reported the firewall as unavailable while it was fine.
    putenv('STUB_NOISE=1');
    $r = $run('status');
    check('helper: a line on stderr does not corrupt the status JSON',
          is_array($r['json']) && ($r['json']['ok'] ?? null) === true, $r['out']);
    // The helper silences nft's own stderr per command, so it never reaches the reply in the first
    // place. Worth pinning down: that is a deliberate property, not an accident.
    check('helper: … and nft stderr never reaches the reply', !str_contains($r['out'], 'something on stderr'), $r['out']);
    check('helper: … and the JSON itself is one unbroken line',
          count(array_filter(preg_split('/\R/', $r['out']), static fn($l) => str_starts_with(trim($l), '{'))) === 1, $r['out']);
    check('helper: check survives stderr noise too', is_array($r['json']) && isset($r['json']['nft']), $r['out']);
    putenv('STUB_NOISE');
    $r = $run('status');
    check('helper: back to a clean run', is_array($r['json']) && !str_contains($r['out'], 'something on stderr'), $r['out']);

    // ── does the file on disk actually describe what is loaded? ──────────────
    // This is the production failure that motivated the check: the rule reached the kernel, the
    // file never got written, and `persistent` still said true because a file happened to exist.
    // A reboot would then have quietly restored a completely different ruleset.
    $r = $run('status');
    check('helper: a file matching what is loaded counts as persistent',
          ($r['json']['persistent'] ?? null) === true && ($r['json']['file_matches'] ?? null) === true, $r['out']);

    $goodFile = (string)@file_get_contents($tmp . '/nftd/ottrack-in.nft');
    file_put_contents($tmp . '/nftd/ottrack-in.nft',
        "#!/usr/sbin/nft -f
# tracker-netlimit: pps=0 burst=0 port=6969 mode=count generated=x
table inet ottrack_in {}
");
    $r = $run('status');
    check('helper: a saved ruleset that differs from the loaded one is NOT persistent',
          ($r['json']['persistent'] ?? null) === false, json_encode($r['json']['persistent'] ?? null));
    check('helper: … the file is still reported as present', ($r['json']['file_present'] ?? null) === true);
    check('helper: … and the mismatch is named', ($r['json']['file_matches'] ?? null) === false && ($r['json']['file_mode'] ?? '') === 'count',
          json_encode([$r['json']['file_matches'] ?? null, $r['json']['file_mode'] ?? null]));

    $r = $run('persist');
    check('helper: persist rewrites the file from what is loaded', $r['rc'] === 0 && ($r['json']['saved'] ?? null) === true, $r['out']);
    check('helper: … with the rate that is actually in force',
          str_contains((string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'), 'limit rate over 40000/second burst 200 packets'),
          (string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'));
    $r = $run('status');
    check('helper: persistence reads true again', ($r['json']['persistent'] ?? null) === true, $r['out']);
    $r = $run('persist');
    check('helper: persist is a no-op when the file already matches',
          $r['rc'] === 0 && ($r['json']['saved'] ?? null) === false && ($r['json']['in_sync'] ?? null) === true, $r['out']);

    // a different RATE on disk is a mismatch too, not just a different mode
    file_put_contents($tmp . '/nftd/ottrack-in.nft',
        str_replace('pps=40000', 'pps=12345', (string)@file_get_contents($tmp . '/nftd/ottrack-in.nft')));
    $r = $run('status');
    check('helper: a saved rate that differs from the loaded one is a mismatch',
          ($r['json']['file_matches'] ?? null) === false && (int)($r['json']['file_pps'] ?? 0) === 12345, $r['out']);
    $run('persist');

    // a save that fails for a REAL reason is still an error — the deferred path below is only for a
    // directory this process cannot write at all
    file_put_contents($tmp . '/bin/install', "#!/bin/bash
exit 1
");
    @chmod($tmp . '/bin/install', 0755);
    $r = $run('set 41000 200 6969');
    check('helper: a save that fails for a real reason is reported as an error',
          $r['rc'] !== 0 && str_contains((string)($r['json']['error'] ?? ''), 'could not be saved'), $r['out']);
    @unlink($tmp . '/bin/install');

    // The panel's PHP runs under systemd ProtectSystem on a hardened box: /etc is read-only inside
    // that mount namespace, root included. That is not a failed apply and must not be reported as one.
    @chmod($tmp . '/nftd', 0555);
    clearstatcache();
    $writable = @file_put_contents($tmp . '/nftd/.probe', 'x');
    if ($writable === false) {
        $r = $run('set 42000 200 6969');
        check('helper: a read-only directory defers the save instead of failing the apply',
              $r['rc'] === 0 && ($r['json']['applied'] ?? null) === true && ($r['json']['persist_deferred'] ?? null) === true, $r['out']);
        check('helper: … and says plainly that it is not saved yet',
              ($r['json']['saved'] ?? null) === false && ($r['json']['persistent'] ?? null) === false
              && str_contains((string)($r['json']['persist_hint'] ?? ''), 'janitor'), $r['out']);
        $c = $run('check');
        check('helper: check flags the directory before anyone applies anything',
              ($c['json']['dir_writable'] ?? null) === false && str_contains((string)($c['json']['hint'] ?? ''), 'read-only'), $c['out']);
        check('helper: … but does not call it a failure', ($c['json']['ok'] ?? null) === true, $c['out']);
        // THE production failure: probing an unwritable directory made the SHELL print "Read-only
        // file system", from inside a command substitution in the middle of building the reply. The
        // line was spliced into the JSON and the card reported the firewall as unavailable.
        $st = $run('status');
        check('helper: probing an unwritable directory does not corrupt the status JSON',
              is_array($st['json']) && ($st['json']['ok'] ?? null) === true, $st['out']);
        check('helper: … the reply is still exactly one JSON line',
              count(array_filter(preg_split('/\R/', $st['out']), static fn($l) => str_starts_with(trim($l), '{'))) === 1, $st['out']);
        check('helper: … and nothing leaked about the write probe',
              !str_contains($st['out'], '.wtest') && !str_contains($st['out'], 'Read-only file system'), $st['out']);
        @chmod($tmp . '/nftd', 0777);
        $r = $run('persist');
        check('helper: the janitor finishes the save afterwards', $r['rc'] === 0 && ($r['json']['saved'] ?? null) === true, $r['out']);
        check('helper: … with the rate that was actually applied',
              str_contains((string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'), 'limit rate over 42000/second'));
    } else {
        @unlink($tmp . '/nftd/.probe');
        @chmod($tmp . '/nftd', 0777);
        skip('helper: a read-only directory defers the save',
             'this filesystem ignores chmod on directories (Windows, or running as root) — run the suite on the server for this half');
    }
    $run('set 40000 200 6969');
    if (!is_file($tmp . '/nftd/ottrack-in.nft')) file_put_contents($tmp . '/nftd/ottrack-in.nft', $goodFile);

    // off — table and file both gone, nothing else disturbed
    $r = $run('off --dry-run');
    check('helper: off --dry-run changes nothing', $r['rc'] === 0 && is_file($tmp . '/nftd/ottrack-in.nft') && is_file($tmp . '/state/t_in'));
    $r = $run('off');
    check('helper: off succeeds', $r['rc'] === 0 && ($r['json']['table_deleted'] ?? null) === true && ($r['json']['file_removed'] ?? null) === true, $r['out']);
    check('helper: the file is gone', !is_file($tmp . '/nftd/ottrack-in.nft'));
    check('helper: the table is gone', !is_file($tmp . '/state/t_in'));
    check('helper: the egress budget survived the undo', is_file($tmp . '/state/t_out'));
    check('helper: the admin\'s own rule survived the undo', is_file($tmp . '/state/manual'));

    // nft rejecting the ruleset must not leave anything applied
    $r = $run('check');
    check('helper: check answers with JSON', is_array($r['json']) && isset($r['json']['nft']), $r['out']);
    check('helper: check finds the include line', ($r['json']['include_ok'] ?? null) === true);

    // and with a nftables.conf that does NOT include the drop-in dir, persistence is reported false
    file_put_contents($tmp . '/nftables.conf', "flush ruleset\n");
    $r = $run('check');
    check('helper: a missing include is reported', ($r['json']['include_ok'] ?? null) === false && ($r['json']['ok'] ?? null) === false);
    check('helper: and it says what to add', str_contains((string)($r['json']['hint'] ?? ''), 'include'));

    // clean up
    foreach (['/bin/nft', '/bin/id', '/nftables.conf', '/nftd/ottrack-in.nft', '/state/loaded', '/state/t_in', '/state/t_out', '/state/manual', '/state/epps'] as $f) @unlink($tmp . $f);
    foreach (['/bin', '/nftd', '/state', ''] as $d) @rmdir($tmp . $d);
    putenv('PATH=' . $pathBefore);
    foreach (['STUB_STATE', 'STUB_NOISE', 'NFT_BIN', 'NFT_DIR', 'NFT_CONF'] as $v) putenv($v);
}

// ── 8. storage: sampling, retention and the series the chart reads ───────────
// Needs the local test database (deploy/local_bootstrap.php); skipped with a visible line otherwise,
// so the pure half above still runs on a bare checkout.
$db = null;
if (is_file($root . '/config/database.php')) {
    require_once $root . '/config/database.php';
    require_once $root . '/includes/settings.php';
    require_once $root . '/includes/schema.php';
    try {
        $db = getDb();
        $cfgDb = getSettings($db);
        ensureSchema($db, $cfgDb);
    } catch (\Throwable $e) {
        $db = null;
        skip('storage: net_samples round-trip', 'no test database: ' . $e->getMessage());
    }
} else {
    skip('storage: net_samples round-trip', 'config/database.php missing — run deploy/local_bootstrap.php first');
}

if ($db !== null) {
    check('schema version >= 11', (int)($cfgDb['schema_version'] ?? 0) >= 11, (string)($cfgDb['schema_version'] ?? 'none'));
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $stateFile = netlimitStateFile();
    $stateBackup = is_file($stateFile) ? file_get_contents($stateFile) : null;
    @unlink($stateFile);

    $cfgS = ['net_sample_seconds' => '60', 'net_keep_days' => '14'];
    $t0 = 1800000000;
    $mkStatus = static function (int $total, int $passed, int $capped, int $limit = 30000, int $eOk = 0, int $eGood = 0, int $eCap = 0): array {
        return [
            'ok' => true, 'pps' => $limit, 'table' => true,
            'counters' => ['in_total' => ['packets' => $total], 'in_passed' => ['packets' => $passed], 'in_capped' => ['packets' => $capped]],
            'egress' => ['counters' => ['announce_ok' => ['packets' => $eOk], 'passed_good' => ['packets' => $eGood], 'capped' => ['packets' => $eCap]]],
        ];
    };

    // the first reading has nothing to subtract from: remembered, but never stored as a data point
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(0, 0, 0), $t0);
    check('storage: the first reading stores no row', $r['stored'] === false && $r['reason'] === 'first reading', $r['reason']);
    check('storage: … and the table is still empty', (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn() === 0);

    // 60 s later: 1 200 000 total / 1 080 000 served / 120 000 dropped → 20 000 / 18 000 / 2 000 pps
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(1200000, 1080000, 120000, 40000, 300000, 300000, 60000), $t0 + 60);
    check('storage: the second reading stores a row', $r['stored'] === true, $r['reason']);
    $row = $db->query('SELECT * FROM `' . NET_SAMPLE_TABLE . '` ORDER BY ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check('storage: rate arithmetic', (int)$row['pps_total'] === 20000 && (int)$row['pps_passed'] === 18000 && (int)$row['pps_capped'] === 2000,
          json_encode([$row['pps_total'], $row['pps_passed'], $row['pps_capped']]));
    check('storage: the span is recorded', (int)$row['span'] === 60);
    check('storage: the cumulative counters are kept too', (int)$row['in_total'] === 1200000 && (int)$row['in_capped'] === 120000);
    check('storage: the limit in force is recorded', (int)$row['limit_pps'] === 40000);
    check('storage: the egress rates are derived as well', (int)$row['epps_ok'] === 10000 && (int)$row['epps_capped'] === 1000,
          json_encode([$row['epps_ok'], $row['epps_capped']]));

    // an "Apply" recreates the table, so the counters restart at zero — that must never become a row
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(5, 4, 1), $t0 + 120);
    check('storage: a counter reset stores nothing', $r['stored'] === false && $r['reason'] === 'counters restarted', $r['reason']);
    check('storage: … and leaves the earlier row alone', (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn() === 1);
    // …but the reading after the reset measures normally again
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(600005, 600004, 1), $t0 + 180);
    check('storage: measuring resumes after a reset', $r['stored'] === true && (int)$r['pps']['in_total'] === 10000, json_encode($r['pps'] ?? null));

    // Nothing loaded means nothing is being measured. Storing the zeros it would otherwise read is
    // how a week of "median 0 pps" happens, and the suggestion built on it is worthless. It also
    // drops the cursor, because a table that comes back has counters that restarted.
    $noTable = ['ok' => true, 'pps' => 0, 'table' => false, 'counters' => [], 'egress' => ['counters' => []]];
    $before = (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn();
    $r = netlimitStoreSample($db, $cfgS, $noTable, $t0 + 240);
    check('storage: no table loaded means no sample at all', $r['stored'] === false && str_contains($r['reason'], 'nothing is counting'), $r['reason']);
    check('storage: … and no zero row was written', (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn() === $before
          && (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '` WHERE pps_total = 0')->fetchColumn() === 0);
    check('storage: … and the next reading starts from scratch',
          netlimitStoreSample($db, $cfgS, $mkStatus(10, 10, 0), $t0 + 300)['reason'] === 'first reading');

    // the series the chart reads
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $ins = $db->prepare('INSERT INTO `' . NET_SAMPLE_TABLE . '` (ts, span, pps_total, pps_passed, pps_capped, limit_pps) VALUES (?,60,?,?,?,30000)');
    for ($i = 0; $i < 240; $i++) $ins->execute([$t0 + $i * 60, 20000 + $i, 19000 + $i, $i]);
    $s = netlimitSeries($db, $cfgS, $t0, $t0 + 240 * 60);
    check('series: every raw point is returned', $s['points'] === 240 && $s['bucket'] === 0, json_encode([$s['points'], $s['bucket']]));
    check('series: the values come back in order', $s['series']['pps_total'][0] === 20000 && $s['series']['pps_total'][239] === 20239);
    check('series: a window returns only what it covers', netlimitSeries($db, $cfgS, $t0 + 60 * 100, $t0 + 60 * 110)['points'] === 11);

    // a wide range is bucketed; a burst that got clipped has to stay visible after bucketing
    $wide = netlimitSeries($db, $cfgS, $t0 - 2592000, $t0 + 240 * 60);
    check('series: a 30-day window is bucketed', $wide['bucket'] > 0);
    check('series: bucketing keeps the point count sane', $wide['points'] <= NET_MAX_POINTS);
    check('series: capped is bucketed by MAX, not by average', max($wide['series']['pps_capped']) === 239, (string)max($wide['series']['pps_capped']));

    // recommendation over the stored rows
    $rec = netlimitRecommend($db, $cfgS, 7, $t0 + 240 * 60);
    check('recommend: reads the samples back', $rec['samples'] === 240 && $rec['peak'] === 20239, json_encode([$rec['samples'], $rec['peak']]));
    check('recommend: names the window it used', $rec['days'] === 7);
    check('recommend: a window longer than the retention is clamped', netlimitRecommend($db, ['net_keep_days' => '3'], 30, $t0)['days'] === 3);

    // retention
    $db->prepare('INSERT INTO `' . NET_SAMPLE_TABLE . '` (ts, span, pps_total) VALUES (?,60,1)')->execute([$t0 - 30 * 86400]);
    $before = (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn();
    $pruned = netlimitPrune($db, $cfgS, $t0 + 240 * 60);
    $after = (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn();
    check('prune: only the row past the retention is dropped', $pruned === 1 && $after === $before - 1, "$pruned / $before → $after");

    // the janitor tick must not fork anything at all while everything is off
    $offCfg = ['net_monitor_enabled' => '0', 'net_auto_enabled' => '0', 'net_limit_cmd' => '/nonexistent/should-never-run.sh'];
    $tick = netlimitTick($db, $offCfg, $t0);
    check('tick: inert while the feature is off', $tick['enabled'] === false && $tick['sampled'] === false && $tick['error'] === null, json_encode($tick));

    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    @unlink($stateFile);
    if ($stateBackup !== null) file_put_contents($stateFile, $stateBackup);
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);
