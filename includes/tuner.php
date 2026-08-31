<?php
/**
 * The stability probe, from the panel's side.
 *
 * The work is worker/tuner.py; this file is only the contract between the panel and it: one state
 * file, written atomically by whichever of them is acting. The panel never starts a long process from
 * a web request — it records that one was ASKED FOR, and the janitor starts it on its next tick. That
 * is the same shape the deferred sysctl writes use, and it means the tuner needs no new root path:
 * it drives the netlimit helper through the sudoers entry the panel already has.
 */

const TUNER_STATE_FILE = __DIR__ . '/../config/tuner_state.json';
/** A run whose process has not written for this long is treated as gone, and its settings restored. */
const TUNER_STALE_S = 300;

function tunerEnabled(array $cfg): bool { return (($cfg['tuner_enabled'] ?? '0') === '1'); }

function tunerStateRead(): array {
    $raw = @file_get_contents(TUNER_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function tunerStateWrite(array $st): bool {
    $tmp = TUNER_STATE_FILE . '.tmp';
    $json = json_encode($st, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, TUNER_STATE_FILE);
}

function tunerStateUpdate(callable $fn): array {
    $st = tunerStateRead();
    $fn($st);
    $st['updated_at'] = time();
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

/** What the card shows. Safe to call constantly: it reads one small file. */
function tunerStatus(array $cfg): array {
    $st = tunerStateRead();
    $alive = tunerIsAlive($st);
    return [
        'enabled'    => tunerEnabled($cfg),
        'available'  => is_file(__DIR__ . '/../worker/tuner.py'),
        'running'    => $alive,
        'requested'  => !empty($st['requested']) && !$alive,
        'stale'      => !empty($st['running']) && !$alive,
        'phase'      => (string)($st['phase'] ?? ''),
        'dry_run'    => !empty($st['dry_run']),
        'started_at' => (int)($st['started_at'] ?? 0),
        'updated_at' => (int)($st['updated_at'] ?? 0),
        'eta_s'      => (int)($st['eta_s'] ?? 0),
        'plan'       => $st['plan'] ?? [],
        'current_step' => (int)($st['current_step'] ?? 0),
        'steps'      => $st['steps'] ?? [],
        'baseline'   => $st['baseline'] ?? null,
        'report'     => $st['report'] ?? null,
        'error'      => $st['error'] ?? null,
        'has_restore' => !empty($st['restore']),
        'restore_result' => $st['restore_result'] ?? null,
        'server_time' => time(),
    ];
}

/** Ask for a run. The janitor picks it up within a minute. */
function tunerRequest(array $opts): array {
    $st = tunerStateRead();
    if (tunerIsAlive($st)) return ['error' => 'A run is already going.'];
    tunerStateUpdate(function (array &$s) use ($opts) {
        $s['requested'] = [
            'at'      => time(),
            'steps'   => max(2, min(12, (int)($opts['steps'] ?? 6))),
            'dwell'   => max(30, min(1800, (int)($opts['dwell'] ?? 180))),
            'dry_run' => !empty($opts['dry_run']),
        ];
        $s['phase'] = 'requested';
        unset($s['error'], $s['report']);
    });
    return ['ok' => true];
}

/** Stop a run. The tuner restores on its way out; the janitor cleans up if it cannot. */
function tunerCancel(): array {
    tunerStateUpdate(function (array &$s) {
        $s['cancel'] = time();
        unset($s['requested']);
    });
    return ['ok' => true];
}

/**
 * Start the process, from the janitor and nowhere else.
 *
 * Detached on purpose: the janitor must not wait an hour for it. The tuner writes its own progress,
 * so nothing is lost by not holding the handle.
 */
function tunerSpawn(array $cfg): array {
    $st = tunerStateRead();
    $req = $st['requested'] ?? null;
    if (!$req || tunerIsAlive($st)) return ['started' => false];
    if (!tunerEnabled($cfg)) return ['started' => false, 'why' => 'disabled'];
    if (!function_exists('trackerExecAvailable') || !trackerExecAvailable()) {
        return ['started' => false, 'why' => 'exec() is disabled'];
    }
    $python = trim((string)($cfg['tuner_python'] ?? 'python3'));
    if (!preg_match('#^[A-Za-z0-9 _./-]{1,120}$#', $python)) $python = 'python3';

    $script = escapeshellarg(__DIR__ . '/../worker/tuner.py');
    $args = ' --run --steps ' . (int)$req['steps'] . ' --dwell ' . (int)$req['dwell']
          . (!empty($req['dry_run']) ? ' --dry-run' : '');
    $cmd = $python . ' ' . $script . $args . ' > /dev/null 2>&1 &';

    tunerStateUpdate(function (array &$s) { unset($s['requested']); $s['phase'] = 'starting'; });
    @exec($cmd);
    return ['started' => true];
}

/**
 * The janitor's safety net.
 *
 * A run whose process is gone leaves the machine on whatever limit it was testing, which is the one
 * outcome this whole feature must never have. If a restore marker is present and nothing is alive to
 * use it, the settings go back here.
 */
function tunerReap(array $cfg): array {
    $st = tunerStateRead();
    if (empty($st['restore']) || tunerIsAlive($st)) return ['reaped' => false];
    if (!function_exists('trackerExecAvailable') || !trackerExecAvailable()) return ['reaped' => false];
    $python = trim((string)($cfg['tuner_python'] ?? 'python3'));
    if (!preg_match('#^[A-Za-z0-9 _./-]{1,120}$#', $python)) $python = 'python3';
    @exec($python . ' ' . escapeshellarg(__DIR__ . '/../worker/tuner.py') . ' --restore 2>&1', $out, $rc);
    return ['reaped' => true, 'rc' => $rc, 'out' => implode(' ', array_slice($out, 0, 3))];
}
