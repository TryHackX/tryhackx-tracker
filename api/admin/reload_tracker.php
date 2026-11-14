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
requireAdminReauth($password, $cfg);

$service = trim((string)($cfg['opentracker_service_name'] ?? ''));
if ($service === '') {
    jsonResponse(['error' => __('api.reload.no_service')], 400);
}
if (!isServiceNameValid($service)) {
    jsonResponse(['error' => __('api.reload.bad_service')], 400);
}

// exec() must exist and not be blacklisted in disable_functions.
if (!trackerExecAvailable()) {
    jsonResponse(['error' => __('api.reload.exec_disabled')], 500);
}

$useSudo = (($cfg['opentracker_restart_use_sudo'] ?? '1') === '1');
$res     = runTrackerServiceCommand('reload', $cfg);
$outStr  = $res['output'];
$ret     = $res['code'];

if ($res['ok']) {
    // The tracker has re-read the blacklist — pending changes are no longer pending.
    resetBlacklistChanges();
    whitelistNoteReloaded(true, $outStr ?? '');
    jsonResponse([
        'success' => true,
        'message' => __('api.reload.reloaded', ['service' => $service]),
        'output'  => $outStr,
    ]);
}

$hint = $useSudo
    ? __('api.reload.hint_sudo', ['service' => $service])
    : __('api.reload.hint_nosudo', ['service' => $service]);

jsonResponse([
    'error'  => __('api.reload.failed', ['code' => (int)$ret, 'detail' => ($outStr !== '' ? $outStr : $hint)]),
    'output' => $outStr,
    'hint'   => $hint,
], 500);
