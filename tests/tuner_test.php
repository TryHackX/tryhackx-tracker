<?php
/**
 * The stability probe's contract with the panel (includes/tuner.php):
 *   php tests/tuner_test.php
 *
 * Pure state-file behaviour, against a temporary file (TRACKER_TUNER_STATE) — never the install's
 * own. What is checked is the incident of 2026-09-10: a run that died a second after it started was
 * (1) never closed, so the card said "stopped without finishing" for a week, (2) revived for five
 * minutes by the panel's own writes, so the next request waited, and (3) would have inherited a Stop
 * flag from any earlier Stop. And the start itself: the janitor asks the netlimit helper to run the
 * probe as a unit of its own, because a background child of a oneshot service is killed with it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/tuner.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tuner_test_' . getmypid();
@mkdir($tmp . '/bin', 0777, true);
$stateFile = $tmp . DIRECTORY_SEPARATOR . 'tuner_state.json';
putenv('TRACKER_TUNER_STATE=' . $stateFile);
$posix = static fn(string $p): string => str_replace('\\', '/', $p);
$write = static function (array $st) use ($stateFile): void { file_put_contents($stateFile, json_encode($st)); };
$read  = static function () use ($stateFile): array { return json_decode((string)@file_get_contents($stateFile), true) ?: []; };

check('the state file honours the override', tunerStateFile() === $stateFile, tunerStateFile());
$write([]);

// ── liveness is the PROCESS heartbeat ───────────────────────────────────────
check('a run that wrote recently is alive', tunerIsAlive(['running' => true, 'updated_at' => time() - (TUNER_STALE_S - 5)]));
check('a run that stopped writing is not, whatever the flag says', !tunerIsAlive(['running' => true, 'updated_at' => time() - (TUNER_STALE_S + 1)]));
check('a panel touch does not count as a heartbeat',
      !tunerIsAlive(['running' => true, 'updated_at' => time() - 3600, 'panel_at' => time()]));

// ── a request ───────────────────────────────────────────────────────────────
$r = tunerRequest(['steps' => 3, 'dwell' => 45, 'dry_run' => true, 'what' => 'outbound']);
$st = $read();
check('a request on an idle state is recorded', !empty($r['ok']) && ($st['requested']['steps'] ?? 0) === 3 && ($st['phase'] ?? '') === 'requested', json_encode($st));
check('… clamped', ($st['requested']['dwell'] ?? 0) === 45 && ($st['requested']['what'] ?? '') === 'outbound');
check('… and stamps panel_at, not the heartbeat', isset($st['panel_at']) && !isset($st['updated_at']), json_encode($st));

// The incident: a dead run (running:true, heartbeat old, a Stop flag from before) and a new request.
$write(['running' => true, 'phase' => 'baseline', 'updated_at' => time() - 3600, 'started_at' => time() - 3600, 'cancel' => time() - 1800]);
$r = tunerRequest(['steps' => 6, 'dwell' => 180]);
$st = $read();
check('a request on top of a dead run is accepted', !empty($r['ok']), json_encode($r));
check('… closes the dead run instead of leaving its flag', ($st['running'] ?? null) === false, json_encode($st));
check('… does not revive its heartbeat', (int)($st['updated_at'] ?? 0) <= time() - 3500, json_encode($st));
check('… and drops the old Stop flag, which would have ended the new run at its first sample', !isset($st['cancel']), json_encode($st));
check('… so the janitor may start it', !tunerIsAlive($st) && !empty($st['requested']));

$write(['running' => true, 'phase' => 'running', 'updated_at' => time() - 10]);
$r = tunerRequest(['steps' => 6, 'dwell' => 180]);
check('a request while a run is alive is refused', !empty($r['error']) && empty($read()['requested']), json_encode($r));

// ── stop ────────────────────────────────────────────────────────────────────
$write(['running' => true, 'phase' => 'running', 'updated_at' => time() - 10, 'requested' => ['steps' => 6]]);
tunerCancel();
$st = $read();
check('a Stop on a live run writes the flag the run reads between samples', !empty($st['cancel']) && ($st['running'] ?? null) === true, json_encode($st));
check('… and withdraws any pending request', !isset($st['requested']));

$write(['running' => true, 'phase' => 'baseline', 'updated_at' => time() - 3600]);
tunerCancel();
$st = $read();
check('a Stop on a dead run closes it rather than flagging it', ($st['running'] ?? null) === false && ($st['phase'] ?? '') === 'aborted' && !isset($st['cancel']), json_encode($st));
check('… and says what happened', !empty($st['error']), json_encode($st));

$write(['requested' => ['steps' => 6], 'phase' => 'requested']);
tunerCancel();
check('a Stop before the start simply withdraws the request', !isset($read()['requested']) && !isset($read()['cancel']));

// ── the reap ────────────────────────────────────────────────────────────────
$cfg = ['tuner_enabled' => '1'];
$write([]);
check('nothing to reap on an idle state', empty(tunerReap($cfg)['reaped']));
$write(['running' => true, 'updated_at' => time() - 5, 'restore' => ['mode' => 'limit', 'pps' => 1000]]);
check('a live run with a marker is left alone — the marker is normal while it runs', empty(tunerReap($cfg)['reaped']));
check('… and untouched', !empty($read()['restore']) && ($read()['running'] ?? null) === true);

$write(['running' => true, 'phase' => 'baseline', 'updated_at' => time() - 3600, 'started_at' => time() - 3600]);
$r = tunerReap($cfg);
$st = $read();
check('a dead run with nothing to restore is still closed', !empty($r['reaped']) && empty($r['restored']), json_encode($r));
check('… running=false, phase=aborted, and a reason', ($st['running'] ?? null) === false && ($st['phase'] ?? '') === 'aborted' && !empty($st['error']), json_encode($st));
check('… which stops the card from saying "stopped without finishing" for a week', !tunerStatus($cfg)['stale']);
check('a second reap does nothing', empty(tunerReap($cfg)['reaped']));

// With a marker, the reap runs `tuner.py --restore`, which pops it. tuner.py reads the same
// TRACKER_TUNER_STATE, so this exercises the real script against the temporary file.
$py = '';
foreach (['python3', 'python'] as $cand) {
    $v = (string)@shell_exec($cand . ' --version 2>&1');
    if (preg_match('/^Python 3/', trim($v))) { $py = $cand; break; }
}
if ($py === '') {
    skip('reap with a marker', 'no python 3 on PATH');
} else {
    $write(['running' => true, 'phase' => 'running', 'updated_at' => time() - 3600,
            'restore' => ['mode' => 'limit', 'pps' => 80000, 'burst' => 100, 'port' => 6969, 'egress_pps' => 0]]);
    $r = tunerReap($cfg + ['tuner_python' => $py]);
    $st = $read();
    check('a dead run with a marker is restored through tuner.py --restore', !empty($r['restored']), json_encode($r));
    check('… the marker is consumed', !isset($st['restore']), json_encode($st));
    check('… the result is recorded', isset($st['restore_result']), json_encode($st));
    check('… and the run is closed', ($st['running'] ?? null) === false && ($st['phase'] ?? '') === 'aborted', json_encode($st));
}

// ── the start ───────────────────────────────────────────────────────────────
// A stub helper on PATH: it records what it was asked and answers with whatever the test put in
// STUB_REPLY. Two spellings, because PHP's exec() goes through cmd.exe on Windows and sh elsewhere.
// native separators: cmd.exe's `type` does not read a path with forward slashes in it
$stubOut = $tmp . DIRECTORY_SEPARATOR . 'helper.args'; $stubReply = $tmp . DIRECTORY_SEPARATOR . 'helper.reply';
file_put_contents($tmp . '/bin/probe-helper-stub', "#!/bin/sh\necho \"\$@\" >> \"\$STUB_OUT\"\ncat \"\$STUB_REPLY\"\n");
file_put_contents($tmp . '/bin/probe-helper-stub.cmd', "@echo off\r\necho %* >> \"%STUB_OUT%\"\r\ntype \"%STUB_REPLY%\"\r\n");
@chmod($tmp . '/bin/probe-helper-stub', 0755);
$pathBefore = (string)getenv('PATH');
putenv('PATH=' . $tmp . DIRECTORY_SEPARATOR . 'bin' . PATH_SEPARATOR . $pathBefore);
putenv('STUB_OUT=' . $stubOut);
putenv('STUB_REPLY=' . $stubReply);
putenv('INVOCATION_ID');
$cfgRun = ['tuner_enabled' => '1', 'net_limit_cmd' => 'probe-helper-stub', 'tuner_python' => 'python3'];

$write(['requested' => ['steps' => 4, 'dwell' => 60, 'dry_run' => true, 'what' => 'both', 'at' => time()], 'phase' => 'requested',
        'cancel' => time() - 100]);
file_put_contents($stubReply, "{\"ok\":true,\"started\":true,\"via\":\"unit\",\"unit\":\"tracker-probe.service\"}\n");
@unlink($stubOut);
$r = tunerSpawn($cfgRun);
$st = $read();
$asked = (string)@file_get_contents($stubOut);
check('the janitor asks the helper to start the probe as a unit', !empty($r['started']) && ($r['via'] ?? '') === 'unit', json_encode($r));
check('… with probe-start, the script, the mode and the request\'s numbers',
      str_contains($asked, 'probe-start') && str_contains($asked, 'tuner.py') && str_contains($asked, '--dry-run')
      && str_contains($asked, '--steps') && str_contains($asked, '4') && str_contains($asked, '--dwell') && str_contains($asked, '60')
      && str_contains($asked, '--what') && str_contains($asked, 'both'), $asked);
check('… the request is consumed and the launch recorded', !isset($st['requested']) && ($st['launch']['via'] ?? '') === 'unit' && ($st['phase'] ?? '') === 'starting', json_encode($st));
check('… and a stale Stop flag does not travel into the new run', !isset($st['cancel']), json_encode($st));

// An older helper that does not know the verb, under systemd: an honest failure, not a corpse.
$write(['requested' => ['steps' => 6, 'dwell' => 180, 'dry_run' => false, 'what' => 'inbound', 'at' => time()], 'phase' => 'requested']);
file_put_contents($stubReply, "{\"ok\":false,\"error\":\"unknown action 'probe-start' — use: status | check\"}\n");
putenv('INVOCATION_ID=abcdef0123456789');
$r = tunerSpawn($cfgRun);
$st = $read();
check('an old helper under a systemd janitor is a recorded failure, not a background child that dies',
      empty($r['started']) && ($st['phase'] ?? '') === 'failed' && !empty($st['error']) && !isset($st['requested']), json_encode($st));
check('… naming what to do about it', str_contains((string)($st['error'] ?? ''), 'tracker-netlimit.sh'), (string)($st['error'] ?? ''));
check('… and the card is not "stale" over it', !tunerStatus($cfgRun)['stale'] && !tunerStatus($cfgRun)['running']);

// The helper refusing for a real reason (already running, systemd-run refused): the same, with its words.
$write(['requested' => ['steps' => 6, 'dwell' => 180, 'dry_run' => false, 'what' => 'inbound', 'at' => time()], 'phase' => 'requested']);
file_put_contents($stubReply, "{\"ok\":false,\"error\":\"a probe is already running as tracker-probe.service\"}\n");
$r = tunerSpawn($cfgRun);
$st = $read();
check('a refusal from the helper is carried to the card', ($st['phase'] ?? '') === 'failed' && str_contains((string)($st['error'] ?? ''), 'already running'), json_encode($st));

// No systemd-run and no systemd janitor (cron): the background job is the right answer.
putenv('INVOCATION_ID');
if (PHP_OS_FAMILY === 'Windows') {
    skip('background fallback under cron', 'exec("… &") is a POSIX shape; not started from cmd.exe');
} else {
    $write(['requested' => ['steps' => 2, 'dwell' => 30, 'dry_run' => true, 'what' => 'inbound', 'at' => time()], 'phase' => 'requested']);
    file_put_contents($stubReply, "{\"ok\":false,\"no_systemd\":true,\"error\":\"systemd-run is not available here\"}\n");
    // an interpreter that exits at once, so nothing actually runs
    file_put_contents($tmp . '/bin/pystub', "#!/bin/sh\nexit 0\n"); @chmod($tmp . '/bin/pystub', 0755);
    $r = tunerSpawn($cfgRun + ['tuner_python' => 'pystub']);
    $st = $read();
    check('without systemd-run and outside systemd the probe is a background job', ($r['via'] ?? '') === 'background' && ($st['launch']['via'] ?? '') === 'background', json_encode($st));
}

// A disabled probe or a live run: nothing is started.
$write(['requested' => ['steps' => 6, 'dwell' => 180, 'at' => time()], 'phase' => 'requested']);
check('a disabled probe is not started', empty(tunerSpawn(['tuner_enabled' => '0'])['started']) && !empty($read()['requested']));
$write(['requested' => ['steps' => 6, 'dwell' => 180, 'at' => time()], 'running' => true, 'updated_at' => time() - 5]);
check('nothing is started over a live run', empty(tunerSpawn($cfgRun)['started']));

// ── the status the card reads ───────────────────────────────────────────────
$write(['running' => true, 'phase' => 'running', 'updated_at' => time() - 3600, 'panel_at' => time() - 10, 'launch' => ['via' => 'unit', 'at' => 1]]);
$s = tunerStatus($cfgRun);
check('stale = flagged running but silent', $s['stale'] && !$s['running']);
check('"updated" on the card is the newer of the two stamps', $s['updated_at'] >= time() - 15, (string)$s['updated_at']);
check('the card learns how the process was started, and the unit\'s name', ($s['launch']['via'] ?? '') === 'unit' && $s['unit'] === 'tracker-probe');
check('the card learns whether the helper is configured', $s['helper'] === true && tunerStatus(['tuner_enabled' => '1', 'net_limit_cmd' => ''])['helper'] === false);

// clean up
putenv('PATH=' . $pathBefore);
foreach (['TRACKER_TUNER_STATE', 'STUB_OUT', 'STUB_REPLY', 'INVOCATION_ID'] as $v) putenv($v);
foreach (glob($tmp . '/bin/*') ?: [] as $f) @unlink($f);
foreach ([$stateFile, $stateFile . '.tmp', $stubOut, $stubReply] as $f) @unlink($f);
@rmdir($tmp . '/bin'); @rmdir($tmp);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
