<?php
/**
 * The stability probe, from the panel's side.
 *
 * The work is tools/tuner.py; this file is only the contract between the panel and it: one state
 * file, written atomically by whichever of them is acting. The panel never starts a long process from
 * a web request — it records that one was ASKED FOR, and the janitor starts it on its next tick. That
 * is the same shape the deferred sysctl writes use, and it means the tuner needs no new root path:
 * it drives the netlimit helper through the sudoers entry the panel already has.
 *
 * HOW IT IS STARTED, AND WHY THAT IS NOT `exec("… &")`.
 *
 * The janitor is a oneshot systemd service on every machine that follows INSTALL.md, and a oneshot
 * service kills every process left in its control group the moment ExecStart returns. A probe put in
 * the background with `&` was therefore killed one second after it had written `running:true` — every
 * time — and the card then said "a run stopped without finishing" for as long as anybody looked. So
 * the janitor asks the netlimit helper (root, already in sudoers) to start the probe as a transient
 * unit of its own, `tracker-probe.service`, running as the web user. Only where that is impossible
 * AND the janitor is not itself under systemd (cron) does the plain background job remain.
 *
 * Two facts about the state file that cost a real incident:
 *   - `updated_at` is the PROCESS's heartbeat. The panel writes `panel_at` for its own touches, so a
 *     request or a Stop cannot make a dead run look alive for TUNER_STALE_S more seconds.
 *   - a dead run is CLOSED (running=false, phase=aborted) by whoever notices it — the reap, a new
 *     request, a Stop — never left as a flag that outlives the process it describes.
 */

/** The state file. Overridable for tests only, so a test never writes the install's own file. */
function tunerStateFile(): string {
    $env = getenv('TRACKER_TUNER_STATE');
    return is_string($env) && $env !== '' ? $env : __DIR__ . '/../config/tuner_state.json';
}
/** A run whose process has not written for this long is treated as gone, and its settings restored. */
const TUNER_STALE_S = 300;
/** The transient unit the helper starts the probe as. Named here so the card can say it. */
const TUNER_UNIT = 'tracker-probe';

function tunerEnabled(array $cfg): bool { return (($cfg['tuner_enabled'] ?? '0') === '1'); }

/** The interpreter, validated the same way the helper validates it. */
function tunerPython(array $cfg): string {
    $python = trim((string)($cfg['tuner_python'] ?? 'python3'));
    return preg_match('#^[A-Za-z0-9 _./-]{1,120}$#', $python) ? $python : 'python3';
}

function tunerStateRead(): array {
    $raw = @file_get_contents(tunerStateFile());
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function tunerStateWrite(array $st): bool {
    $file = tunerStateFile();
    $tmp = $file . '.tmp';
    $json = json_encode($st, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $file);
}

/**
 * A change made by the PANEL. It stamps `panel_at`, not `updated_at`: the latter is the heartbeat of
 * the process, and a request written on top of a dead run used to refresh it — which made the run
 * look alive for another five minutes and kept the janitor from starting the one just asked for.
 */
function tunerStateUpdate(callable $fn): array {
    $st = tunerStateRead();
    $fn($st);
    $st['panel_at'] = time();
    tunerStateWrite($st);
    return $st;
}

/**
 * Is a run actually alive?
 *
 * `running` in the file is a claim by a process that may since have died. A run that has not written
 * for TUNER_STALE_S is not running, whatever the flag says — and that is the case the janitor exists
 * to clean up, because a dead tuner leaves the machine on whatever limit it was testing.
 */
function tunerIsAlive(array $st): bool {
    if (empty($st['running'])) return false;
    return (time() - (int)($st['updated_at'] ?? 0)) < TUNER_STALE_S;
}

/** Close a run whose process is gone. Idempotent; touches nothing when the run is not marked running. */
function tunerMarkDead(array &$s, string $why): void {
    if (empty($s['running'])) return;
    $s['running'] = false;
    $s['phase'] = 'aborted';
    $s['finished_at'] = time();
    if (empty($s['error'])) $s['error'] = $why;
    unset($s['cancel']);
}

/** Does the netlimit helper exist for this install? The probe measures through it, so without it there is nothing to run. */
function tunerHelperConfigured(array $cfg): bool {
    return function_exists('netlimitCommand') && netlimitCommand($cfg) !== '';
}

/** Is this process a systemd service? (The janitor is, on every machine that follows INSTALL.md.) */
function tunerUnderSystemd(): bool {
    $id = getenv('INVOCATION_ID');
    return is_string($id) && $id !== '';
}

/** What the card shows. Safe to call constantly: it reads one small file. */
function tunerStatus(array $cfg): array {
    $st = tunerStateRead();
    $alive = tunerIsAlive($st);
    return [
        'enabled'    => tunerEnabled($cfg),
        'available'  => is_file(__DIR__ . '/../tools/tuner.py'),
        'helper'     => tunerHelperConfigured($cfg),
        'running'    => $alive,
        'requested'  => !empty($st['requested']) && !$alive,
        'stale'      => !empty($st['running']) && !$alive,
        'phase'      => (string)($st['phase'] ?? ''),
        'dry_run'    => !empty($st['dry_run']),
        'started_at' => (int)($st['started_at'] ?? 0),
        // the newer of the two stamps: what the card calls "updated" is the last time anything wrote
        'updated_at' => max((int)($st['updated_at'] ?? 0), (int)($st['panel_at'] ?? 0)),
        'eta_s'      => (int)($st['eta_s'] ?? 0),
        'what'       => (string)($st['what'] ?? 'inbound'),
        'plan'       => $st['plan'] ?? [],
        'current_step' => (int)($st['current_step'] ?? 0),
        'steps'      => $st['steps'] ?? [],
        'baseline'   => $st['baseline'] ?? null,
        'report'     => $st['report'] ?? null,
        'error'      => $st['error'] ?? null,
        'has_restore' => !empty($st['restore']),
        'restore_result' => $st['restore_result'] ?? null,
        'launch'     => is_array($st['launch'] ?? null) ? $st['launch'] : null,
        'unit'       => TUNER_UNIT,
        'server_time' => time(),
    ];
}

/** Ask for a run. The janitor picks it up within a minute. */
function tunerRequest(array $opts): array {
    $st = tunerStateRead();
    if (tunerIsAlive($st)) return ['error' => __('api.tuner.already_running')];
    tunerStateUpdate(function (array &$s) use ($opts) {
        // Whatever the last run left behind is not this run's: a Stop flag would end the new run at
        // its first sample, and a stale `running` would keep the janitor from starting it at all.
        tunerMarkDead($s, __('api.tuner.reaped'));
        unset($s['cancel']);
        $s['requested'] = [
            'at'      => time(),
            'steps'   => max(2, min(12, (int)($opts['steps'] ?? 6))),
            'dwell'   => max(30, min(1800, (int)($opts['dwell'] ?? 180))),
            'dry_run' => !empty($opts['dry_run']),
            // Buffers are deliberately not an option: a socket's receive buffer is fixed when the
            // socket is created, so ramping it means restarting the tracker at every step.
            'what'    => in_array($opts['what'] ?? '', ['inbound', 'outbound', 'both'], true)
                         ? $opts['what'] : 'inbound',
        ];
        $s['phase'] = 'requested';
        unset($s['error'], $s['report']);
    });
    return ['ok' => true];
}

/**
 * Stop a run. A live one reads the flag between samples and restores on its way out; the janitor
 * cleans up if it cannot. A request not yet started is simply withdrawn, and a run that is already
 * dead is closed here rather than flagged — nothing is alive to read a flag, and a flag left behind
 * used to stop the NEXT run at its first sample.
 */
function tunerCancel(): array {
    $st = tunerStateRead();
    $alive = tunerIsAlive($st);
    tunerStateUpdate(function (array &$s) use ($alive) {
        unset($s['requested']);
        if ($alive) { $s['cancel'] = time(); return; }
        tunerMarkDead($s, __('api.tuner.reaped'));
        unset($s['cancel']);
    });
    return ['ok' => true];
}

/**
 * Start the process, from the janitor and nowhere else.
 *
 * Detached on purpose: the janitor must not wait an hour for it. The tuner writes its own progress,
 * so nothing is lost by not holding the handle. The request is consumed BEFORE the attempt, so a
 * start that fails is reported once rather than retried every minute.
 */
function tunerSpawn(array $cfg): array {
    $st = tunerStateRead();
    $req = $st['requested'] ?? null;
    if (!$req || tunerIsAlive($st)) return ['started' => false];
    if (!tunerEnabled($cfg)) return ['started' => false, 'why' => 'disabled'];
    if (!function_exists('trackerExecAvailable') || !trackerExecAvailable()) {
        return ['started' => false, 'why' => 'exec() is disabled'];
    }
    $python = tunerPython($cfg);
    $scriptPath = realpath(__DIR__ . '/../tools/tuner.py');
    if ($scriptPath === false) $scriptPath = __DIR__ . '/../tools/tuner.py';
    $what = in_array($req['what'] ?? '', ['inbound', 'outbound', 'both'], true) ? $req['what'] : 'inbound';
    $mode = !empty($req['dry_run']) ? '--dry-run' : '--run';
    $steps = (int)$req['steps'];
    $dwell = (int)$req['dwell'];

    tunerStateUpdate(function (array &$s) {
        unset($s['requested'], $s['cancel'], $s['launch']);
        $s['phase'] = 'starting';
    });

    $via = null; $error = null; $detail = '';
    // The helper first: it can start the probe as a unit of its own, outside this process's cgroup.
    if (tunerHelperConfigured($cfg) && function_exists('netlimitRun')) {
        $r = netlimitRun($cfg, ['probe-start', $scriptPath, '--python', $python, $mode,
                                '--steps', $steps, '--dwell', $dwell, '--what', $what]);
        if (!empty($r['ok'])) {
            $via = 'unit';
        } else {
            $detail = (string)($r['error'] ?? '');
            // A helper from before this verb existed answers "unknown action"; a machine without
            // systemd-run says so by name. Both are a reason to fall back, and nothing else is.
            $fallback = !empty($r['json']['no_systemd']) || stripos($detail, 'unknown action') !== false;
            if (!$fallback) $error = __('api.tuner.spawn_failed', ['error' => $detail]);
        }
    }
    if ($via === null && $error === null) {
        if (tunerUnderSystemd()) {
            // A background child of a oneshot service is killed when the service ends. Saying so is
            // the whole difference between this failure and the one that took a week to find.
            $error = __('api.tuner.spawn_systemd') . ($detail !== '' ? ' (' . $detail . ')' : '');
        } else {
            $cmd = $python . ' ' . escapeshellarg($scriptPath) . ' ' . $mode
                 . ' --steps ' . $steps . ' --dwell ' . $dwell . ' --what ' . escapeshellarg($what)
                 . ' > /dev/null 2>&1 &';
            @exec($cmd);
            $via = 'background';
        }
    }
    if ($error !== null) {
        tunerStateUpdate(function (array &$s) use ($error) {
            $s['phase'] = 'failed';
            $s['running'] = false;
            $s['error'] = $error;
        });
        return ['started' => false, 'why' => $error];
    }
    tunerStateUpdate(function (array &$s) use ($via) {
        $s['launch'] = ['via' => $via, 'at' => time()];
    });
    return ['started' => true, 'via' => $via];
}

/**
 * The janitor's safety net.
 *
 * A run whose process is gone leaves the machine on whatever limit it was testing, which is the one
 * outcome this whole feature must never have. If a restore marker is present and nothing is alive to
 * use it, the settings go back here — and the run is CLOSED, so the card stops saying "stopped
 * without finishing" a week later and a new request is not mistaken for the dead one.
 */
function tunerReap(array $cfg): array {
    $st = tunerStateRead();
    if (tunerIsAlive($st)) return ['reaped' => false];
    $stale = !empty($st['running']);
    $marker = !empty($st['restore']);
    if (!$stale && !$marker) return ['reaped' => false];
    $r = ['reaped' => true, 'restored' => false, 'rc' => null, 'out' => ''];
    if ($marker) {
        if (!function_exists('trackerExecAvailable') || !trackerExecAvailable()) return ['reaped' => false];
        $python = tunerPython($cfg);
        @exec($python . ' ' . escapeshellarg(__DIR__ . '/../tools/tuner.py') . ' --restore 2>&1', $out, $rc);
        $r['restored'] = true;
        $r['rc'] = $rc;
        $r['out'] = implode(' ', array_slice((array)$out, 0, 3));
    }
    tunerStateUpdate(function (array &$s) {
        tunerMarkDead($s, __('api.tuner.reaped'));
    });
    return $r;
}
