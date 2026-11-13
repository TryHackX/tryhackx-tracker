<?php
/**
 * Test for tools/opentracker/tracker-mode.sh:
 *   php tests/mode_switch_test.php
 *
 * This script switches the live tracker's build every night through includes/schedule.php, and it had
 * no test at all until multi-instance support was added to it. The compatibility guarantee — "a box
 * with no instances behaves exactly as before" — is worth nothing unpinned, and the output contract
 * (schedule.php reads the LAST line and accepts only a bare "white" or "black") is the kind of thing
 * a well-meant extra echo breaks silently at two in the morning.
 *
 * Driven against a fake tracker home and a stub systemctl that records every call.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

$helper = $root . '/tools/opentracker/tracker-mode.sh';
$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
    $probe = []; $rc = null;
    @exec(escapeshellarg($cand) . ' -c "echo ok" 2>&1', $probe, $rc);
    if ($rc === 0 && trim(implode('', $probe)) === 'ok') { $bash = $cand; break; }
}
if ($bash === null || !trackerExecAvailable() || !is_file($helper)) {
    skip('tracker-mode.sh end to end', 'no usable bash (or exec() disabled)');
    echo "\n$n checks, $fails failed, $skips skipped\n";
    exit(0);
}

$posix = static function (string $p): string {
    $p = str_replace('\\', '/', $p);
    if (preg_match('#^([A-Za-z]):/(.*)$#', $p, $m)) return '/' . strtolower($m[1]) . '/' . $m[2];
    return $p;
};

$tmp = sys_get_temp_dir() . '/mode_test_' . getmypid();
$home = $tmp . '/home';
$bin  = $tmp . '/bin';
@mkdir($home . '/instances', 0777, true);
@mkdir($bin, 0777, true);

/**
 * Two stubs, because this git-bash cannot create symbolic links ("Operation not permitted") and the
 * script's entire job is to flip them. `ln -sfn` here copies instead, so the marker travels in the
 * FILE CONTENT and a stub `readlink` reports the link target the same way the real one would. That is
 * stubbing two external commands, not the logic under test — and it buys the one check that matters
 * most: that the fast path asks the running process rather than the symlink.
 */
$stubSystemctl = <<<'STUB'
#!/bin/bash
S="${MODE_STUB_STATE:?}"
H="${TRACKER_HOME:?}"
log() { printf '%s
' "$*" >>"$S/calls.log"; }
unit_file() { printf '%s/unit-%s' "$S" "$(printf '%s' "$1" | tr '/@.' '___')"; }
pid_of() { printf '%s' "$(( 1000 + $(printf '%s' "$1" | cksum | cut -d' ' -f1) % 8000 ))"; }
# what the SHARED binary symlink points at right now — which is what a restart would re-exec
shared_mode() { grep -o 'MODE=[a-z]*' "$H/opentracker" 2>/dev/null | head -1 | cut -d= -f2; }
case "$1" in
  show)
    f="$(unit_file "$2")"
    [ -f "$f.pid" ] && cat "$f.pid" || echo 0
    exit 0 ;;
  is-active)
    shift; [ "$1" = "--quiet" ] && shift
    f="$(unit_file "$1")"; log "is-active $1"
    [ -f "$f.active" ] && exit 0 || exit 3 ;;
  restart)
    u="$2"; f="$(unit_file "$u")"; log "restart $u"
    if [ -f "$S/fail-$u" ]; then rm -f "$f.active" "$f.pid"; exit 1; fi
    p="$(pid_of "$u")"
    : >"$f.active"; printf '%s' "$p" >"$f.pid"
    printf '%s' "$(shared_mode)" >"$S/pid-$p.mode"
    exit 0 ;;
  stop)
    u="$2"; f="$(unit_file "$u")"; log "stop $u"
    rm -f "$f.active"; printf '0' >"$f.pid"
    exit 0 ;;
  status) log "status $2"; exit 0 ;;
  *) log "$*"; exit 0 ;;
esac
STUB;

$stubReadlink = <<<'STUB'
#!/bin/bash
# readlink -f <path>. Reports what the "symlink" points at: from the pid map for /proc/<pid>/exe, and
# from the MODE marker inside the copied file everywhere else.
S="${MODE_STUB_STATE:-}"
p="${2:-}"
case "$p" in
  */proc/*/exe)
    pid="$(printf '%s' "$p" | sed -e 's|/exe$||' -e 's|.*/||')"
    m="$(cat "$S/pid-$pid.mode" 2>/dev/null)"
    [ -n "$m" ] && { printf '/fake/opentracker.%s
' "$m"; exit 0; }
    printf '%s
' "$p"; exit 0 ;;
esac
if [ -f "$p" ]; then
  m="$(grep -o 'MODE=[a-z]*' "$p" 2>/dev/null | head -1 | cut -d= -f2)"
  [ -n "$m" ] && { printf '%s/opentracker.%s
' "$(dirname "$p")" "$m"; exit 0; }
fi
exec /usr/bin/readlink "$@"
STUB;

file_put_contents($bin . '/systemctl', $stubSystemctl);
file_put_contents($bin . '/readlink', $stubReadlink);
@chmod($bin . '/systemctl', 0755);
@chmod($bin . '/readlink', 0755);

$state = $tmp . '/state';
@mkdir($state, 0777, true);

/** Lay out a tracker home in $mode, with the primary running that same build. */
$setup = static function (string $mode) use ($home, $state) {
    foreach (glob($state . '/*') ?: [] as $f) @unlink($f);
    foreach (['white', 'black'] as $m) {
        file_put_contents($home . '/opentracker.' . $m, "#!/bin/sh
# MODE=$m
");
        file_put_contents($home . '/opentracker.conf.' . $m, "# MODE=$m
listen.udp 0.0.0.0:6969
listen.tcp 0.0.0.0:6969
access.whitelist /var/lib/tracker/whitelist
");
    }
    @copy($home . '/opentracker.' . $mode, $home . '/opentracker');
    @copy($home . '/opentracker.conf.' . $mode, $home . '/opentracker.conf');
    file_put_contents($state . '/unit-opentracker.active', '');
};

$run = static function (string $args) use ($bash, $helper, $posix, $home, $bin, $state): array {
    $pathBefore = (string)getenv('PATH');
    putenv('PATH=' . $bin . PATH_SEPARATOR . $pathBefore);
    putenv('TRACKER_HOME=' . $posix($home));
    putenv('INSTANCES_DIR=' . $posix($home . '/instances'));
    putenv('MODE_STUB_STATE=' . $posix($state));
    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $out = []; $rc = null;
    @exec($bashCmd . ' ' . escapeshellarg($posix($helper)) . ' ' . $args . ' 2>&1', $out, $rc);
    putenv('PATH=' . $pathBefore);
    foreach (['TRACKER_HOME', 'INSTANCES_DIR', 'MODE_STUB_STATE'] as $e) putenv($e);
    $lines = array_values(array_filter(array_map('trim', $out), fn($l) => $l !== ''));
    return ['rc' => (int)$rc, 'lines' => $lines, 'last' => $lines ? end($lines) : '', 'out' => implode("\n", $out)];
};
$calls = static function () use ($state): array {
    $f = $state . '/calls.log';
    return is_file($f) ? array_values(array_filter(array_map('trim', file($f)))) : [];
};

/* ── 1. the version, so a stale copy on the server can be detected ────────── */
$v = $run('--version');
check('a version is reported, so the panel can tell a stale installed copy from the repo one',
      $v['rc'] === 0 && str_contains($v['last'], 'tracker-mode.sh'), $v['out']);

/* ── 2. backward compatibility: a box with no instances ───────────────────── */

$setup('black');
$r = $run('status');
check('status prints the current build and nothing else', $r['rc'] === 0 && $r['lines'] === ['black'], $r['out']);

$setup('black');
$r = $run('white');
check('a plain switch still succeeds', $r['rc'] === 0, $r['out']);
check('… and its LAST line is a bare mode word — the contract schedule.php reads',
      in_array($r['last'], ['white', 'black'], true), $r['last']);
check('… reporting the mode it switched to', $r['last'] === 'white', $r['out']);
check('… by restarting exactly one unit',
      count(array_filter($calls(), fn($c) => str_starts_with($c, 'restart '))) === 1, implode(' | ', $calls()));
check('… the primary, by name',
      in_array('restart opentracker', $calls(), true), implode(' | ', $calls()));

/* ── 3. the pre-flight refuses without touching anything ──────────────────── */

$setup('black');
@unlink($home . '/opentracker.white');
$r = $run('white');
check('a missing build is refused', $r['rc'] === 2 && str_contains($r['out'], 'missing'), $r['out']);
check('… having restarted nothing at all', $calls() === [], implode(' | ', $calls()));
check('… and having left the active build alone',
      file_get_contents($home . '/opentracker') === file_get_contents($home . '/opentracker.black'));

/* ── 4. instances ─────────────────────────────────────────────────────────── */

$mkInstance = static function (string $name) use ($home) {
    @mkdir($home . '/instances/' . $name, 0777, true);
    foreach (['white', 'black'] as $m) {
        file_put_contents($home . '/instances/' . $name . '/opentracker.conf.' . $m, "listen.udp 0.0.0.0:6970\n");
    }
    @copy($home . '/instances/' . $name . '/opentracker.conf.black', $home . '/instances/' . $name . '/opentracker.conf');
};

$setup('black');
$mkInstance('edge-a');
$mkInstance('edge-b');

// Without --all, the extras must be untouched. An operator who has not opted in gets exactly the old
// behaviour, which is the whole compatibility promise.
$r = $run('white');
check('without --all the extras are not touched',
      count(array_filter($calls(), fn($c) => str_contains($c, '@'))) === 0, implode(' | ', $calls()));

$setup('black');
$r = $run('--all white');
$c = $calls();
$restarts = array_values(array_filter($c, fn($x) => str_starts_with($x, 'restart ')));
check('--all restarts every instance and the primary', count($restarts) === 3, implode(' | ', $restarts));
check('… with the PRIMARY LAST, because it is the port every client announces to',
      end($restarts) === 'restart opentracker', implode(' | ', $restarts));
check('… printing one detail line per instance', str_contains($r['out'], 'edge-a: white') && str_contains($r['out'], 'edge-b: white'), $r['out']);
check('… and STILL ending with a bare mode word', in_array($r['last'], ['white', 'black'], true), $r['last']);
check('… which is the aggregate the schedule will store', $r['last'] === 'white', $r['out']);
check('… and each instance config now points at the new build',
      file_get_contents($home . '/instances/edge-a/opentracker.conf')
      === file_get_contents($home . '/instances/edge-a/opentracker.conf.white'));

/* ── 5. an instance that cannot switch is STOPPED, not left serving ───────── */

$setup('black');
touch($state . '/fail-opentracker@edge-b.service');
$r = $run('--all white');
$c = $calls();
check('a failed instance is stopped rather than left running the old build',
      in_array('stop opentracker@edge-b.service', $c, true), implode(' | ', $c));
check('… and says so in the output', str_contains($r['out'], 'warn: edge-b'), $r['out']);
check('… while the healthy one still switched', str_contains($r['out'], 'edge-a: white'), $r['out']);
check('… the primary was still switched, so tracker_mode can follow it',
      in_array('restart opentracker', $c, true), implode(' | ', $c));
// This is the decision the whole design turns on: the database's single tracker_mode row gates
// whitelist regeneration for EVERYONE. Refusing to flip it because a secondary failed would stop the
// whitelist being regenerated at all, which is far worse than the failure that caused it.
check('… and the exit code is still success, so the schedule records the primary\'s mode',
      $r['rc'] === 0, 'rc=' . $r['rc']);
check('… with the last line still a bare word', in_array($r['last'], ['white', 'black'], true), $r['last']);

/* ── 6. --instance touches one, and never the primary ─────────────────────── */

$setup('black');
$r = $run('--instance edge-a white');
$c = $calls();
check('--instance restarts only that instance',
      in_array('restart opentracker@edge-a.service', $c, true)
      && !in_array('restart opentracker', $c, true), implode(' | ', $c));
check('--instance refuses a name that does not exist',
      $run('--instance nope white')['rc'] === 2);
check('--instance still ends with a bare mode word', in_array($r['last'], ['white', 'black'], true), $r['last']);

/* ── 7. the fast path asks the PROCESS, not the symlink ───────────────────── */
//
// The old code skipped the restart when the symlink already pointed the right way. After an
// interrupted switch that link can be right while every process still executes the other build from
// its open inode — so the next run printed success, restarted nothing, and the panel recorded a mode
// the tracker was not serving. Reading /proc/<pid>/exe needs real symlinks and a real /proc, so the
// behaviour is asserted on the server; here the code path is pinned so it cannot be quietly dropped.
$src = file_get_contents($helper);
check('the fast path reads what the process is executing', str_contains($src, '$PROC_DIR/$pid/exe'));
check('… and not the symlink', !preg_match('/if \[ "\$\(current\)" = "\$want" \]/', $src),
      'the old symlink-based fast path is still there');

// Behavioural: switch to white so the process is genuinely running white, then ask for white again.
$setup('black');
$run('white');
$before = count($calls());
$r = $run('white');
check('asking for the build that is already RUNNING restarts nothing',
      count($calls()) === $before + 1, implode(' | ', array_slice($calls(), $before)));   // one is-active
check('… and still answers with the mode', $r['last'] === 'white', $r['out']);

// Now the case the old code got wrong: the symlink says white, but the process is executing black.
// The old fast path took the symlink's word for it, printed success and restarted nothing, and the
// panel recorded a mode the tracker was not serving — permanently.
$setup('black');
$run('white');                                   // now: link white, process white
copy($home . '/opentracker.black', $home . '/opentracker');   // pretend an interrupted switch left it
file_put_contents($state . '/pid-' . trim((string)@file_get_contents($state . '/unit-opentracker.pid')) . '.mode', 'black');
copy($home . '/opentracker.white', $home . '/opentracker');   // link says white again
$before = count($calls());
$r = $run('white');
check('a symlink that says white over a process running black is NOT treated as done',
      count(array_filter(array_slice($calls(), $before), fn($c) => str_starts_with($c, 'restart '))) === 1,
      implode(' | ', array_slice($calls(), $before)));

/* ── 8. usage ─────────────────────────────────────────────────────────────── */
check('an unknown flag is refused', $run('--bogus white')['rc'] === 1);
check('an unknown action is refused', $run('sideways')['rc'] === 1);

// teardown
$rm = static function (string $d) use (&$rm) {
    foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $rm($f) : @unlink($f); }
    @rmdir($d);
};
$rm($tmp);

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
