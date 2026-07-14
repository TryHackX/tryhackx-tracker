<?php
/**
 * POST — restart the configured OpenTracker systemd service.
 *
 * Auth + CSRF are enforced by the router (admin/*, non-GET). This endpoint additionally requires the
 * admin password, because it runs a real system command. The service name is validated against a
 * strict systemd-unit whitelist AND passed through escapeshellarg, so it can never be used to inject
 * a second command. On success the blacklist change log is cleared (the tracker has now reloaded it).
 *
 * The web/PHP user must be permitted to run the command. Typical Debian setup (php-fpm as www-data):
 *   sudoers:  www-data ALL=(root) NOPASSWD: /bin/systemctl restart <service>
 * then keep "Run via sudo" = Yes in Settings.
 */

requirePost();

$input    = readJsonBody();
$password = (string)($input['password'] ?? '');

// Confirm the admin password (mirrors delete_permanently / deletion-limit changes).
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
    jsonResponse(['error' => 'PHP exec() is disabled on this server — the service cannot be restarted from the panel.'], 500);
}

$useSudo = (($cfg['opentracker_restart_use_sudo'] ?? '1') === '1');
// runTrackerServiceCommand whitelists the unit name and escapeshellarg's it — defence in depth
// against shell metacharacters — before running `systemctl restart <service>`.
$res    = runTrackerServiceCommand('restart', $cfg);
$outStr = $res['output'];
$ret    = $res['code'];

if ($res['ok']) {
    // The tracker has restarted and re-read the blacklist — pending changes are no longer pending.
    resetBlacklistChanges();
    jsonResponse([
        'success' => true,
        'message' => 'Tracker service "' . $service . '" restarted.',
        'output'  => $outStr,
    ]);
}

$hint = $useSudo
    ? 'Grant the web user permission, e.g. sudoers line: "www-data ALL=(root) NOPASSWD: /bin/systemctl restart ' . $service . '".'
    : 'The web user must be allowed to run "systemctl restart ' . $service . '" (consider enabling "Run via sudo").';

jsonResponse([
    'error'  => 'Restart failed (exit ' . (int)$ret . '). ' . ($outStr !== '' ? $outStr : $hint),
    'output' => $outStr,
    'hint'   => $hint,
], 500);
