<?php
/**
 * POST — reload the configured OpenTracker service's white/blacklist (SIGHUP, no downtime).
 *
 * Auth + CSRF are enforced by the router (admin/*, non-GET). Like restart_tracker, this endpoint
 * additionally requires the admin password because it runs a real system command. It runs
 * `systemctl reload <service>`, which for a standard OpenTracker unit executes
 * `ExecReload=/bin/kill -HUP $MAINPID` — the SIGHUP that makes OpenTracker re-read its blacklist
 * WITHOUT restarting (no dropped connections). The service name is validated against a strict
 * systemd-unit whitelist AND passed through escapeshellarg, so it can never inject a second command.
 * On success the blacklist change log is cleared (the tracker has now reloaded it).
 *
 * The web/PHP user must be permitted to run the command. Typical Debian setup (php-fpm as www-data):
 *   sudoers:  www-data ALL=(root) NOPASSWD: /bin/systemctl reload <service>
 * then keep "Run via sudo" = Yes in Settings. The unit must define ExecReload for reload to work —
 * `systemctl reload` fails otherwise (use Restart, or add ExecReload to the unit; see the README).
 */

requirePost();

$input    = readJsonBody();
$password = (string)($input['password'] ?? '');

// Confirm the admin password (mirrors restart_tracker / deletion-limit changes).
if ($password === '' || ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
    jsonResponse(['error' => 'Incorrect password.'], 403);
}

$service = trim((string)($cfg['opentracker_service_name'] ?? ''));
if ($service === '') {
    jsonResponse(['error' => 'No tracker service name is configured. Set it in Settings first.'], 400);
}
if (!isServiceNameValid($service)) {
    jsonResponse(['error' => 'The configured service name is invalid. Fix it in Settings.'], 400);
}

// exec() must exist and not be blacklisted in disable_functions.
if (!trackerExecAvailable()) {
    jsonResponse(['error' => 'PHP exec() is disabled on this server — the service cannot be reloaded from the panel.'], 500);
}

$useSudo = (($cfg['opentracker_restart_use_sudo'] ?? '1') === '1');
$res     = runTrackerServiceCommand('reload', $cfg);
$outStr  = $res['output'];
$ret     = $res['code'];

if ($res['ok']) {
    // The tracker has re-read the blacklist — pending changes are no longer pending.
    resetBlacklistChanges();
    jsonResponse([
        'success' => true,
        'message' => 'Tracker service "' . $service . '" reloaded its blacklist (SIGHUP).',
        'output'  => $outStr,
    ]);
}

$hint = $useSudo
    ? 'Grant the web user permission, e.g. sudoers line: "www-data ALL=(root) NOPASSWD: /bin/systemctl reload ' . $service . '". Also make sure the unit defines ExecReload (e.g. ExecReload=/bin/kill -HUP $MAINPID).'
    : 'The web user must be allowed to run "systemctl reload ' . $service . '" (consider enabling "Run via sudo"). The unit must also define ExecReload.';

jsonResponse([
    'error'  => 'Reload failed (exit ' . (int)$ret . '). ' . ($outStr !== '' ? $outStr : $hint),
    'output' => $outStr,
    'hint'   => $hint,
], 500);
