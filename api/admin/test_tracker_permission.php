<?php
/**
 * GET — test whether the web/PHP user is actually allowed to restart / reload the tracker service,
 * WITHOUT performing the action. Auth is enforced by the router (admin/*). GET, so CSRF-exempt like
 * the other admin read endpoints.
 *
 * With "Run via sudo" = Yes we ask sudo itself: `sudo -n -l systemctl <verb> <service>` lists whether
 * that exact command may run non-interactively (NOPASSWD). Exit 0 = permitted; anything else means the
 * sudoers rule is missing/incomplete and we return copy-paste fix instructions. `-l` only *lists*
 * permissions — it never runs systemctl — so this is safe to call from a Settings button.
 *
 * With "Run via sudo" = No there is nothing to dry-run safely, so we report whether PHP is running as
 * root (the only way a direct `systemctl <verb>` could work) and otherwise recommend enabling sudo.
 *
 * Query: ?endpoint=admin/test_tracker_permission&op=restart|reload
 */

$op   = strtolower(trim((string)($_GET['op'] ?? 'restart')));
$verb = $op === 'reload' ? 'reload' : 'restart';

$phpUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
    : get_current_user();

$service = trim((string)($cfg['opentracker_service_name'] ?? ''));
$useSudo = (($cfg['opentracker_restart_use_sudo'] ?? '1') === '1');

$result = [
    'ok'          => false,
    'op'          => $verb,
    'service'     => $service,
    'use_sudo'    => $useSudo,
    'php_user'    => $phpUser,
    'os'          => PHP_OS_FAMILY,
    'command'     => '',
    'output'      => '',
    'return_code' => null,
    'errors'      => [],
    'suggestions' => [],
];

// The sudoers line the admin most likely needs (used in several suggestions below).
$sudoersLine = $phpUser . ' ALL=(root) NOPASSWD: /bin/systemctl ' . $verb . ' ' . $service;
$sudoersFix  = [
    "Add a sudoers rule (adjust the binary path if systemctl lives elsewhere — check with `command -v systemctl`):",
    "echo '" . $sudoersLine . "' | sudo tee /etc/sudoers.d/tracker-restart",
    "sudo chmod 440 /etc/sudoers.d/tracker-restart",
];
if ($verb === 'reload') {
    $sudoersFix[] = "Also make sure the systemd unit defines ExecReload, e.g. ExecReload=/bin/kill -HUP \$MAINPID — 'systemctl reload' fails without it.";
}

if ($service === '') {
    $result['errors'][]      = 'No tracker service name is configured.';
    $result['suggestions'][] = 'Set the service name above (e.g. "opentracker") and save, then test again.';
    jsonResponse($result);
}
if (!isServiceNameValid($service)) {
    $result['errors'][]      = 'The configured service name is invalid.';
    $result['suggestions'][] = 'Use only letters, digits and . _ @ - (e.g. "opentracker" or "opentracker.service").';
    jsonResponse($result);
}
if (!trackerExecAvailable()) {
    $result['errors'][]      = 'PHP exec() is disabled on this server — the panel cannot run systemctl at all.';
    $result['suggestions'][] = 'Remove exec from disable_functions in php.ini (or run the command manually from a shell).';
    jsonResponse($result);
}

if ($useSudo) {
    $cmd = 'sudo -n -l systemctl ' . $verb . ' ' . escapeshellarg($service) . ' 2>&1';
    $output = [];
    $ret    = null;
    @exec($cmd, $output, $ret);
    $outStr = trim(implode("\n", $output));

    $result['command']     = $cmd;
    $result['output']      = $outStr;
    $result['return_code'] = (int)$ret;

    if ($ret === 0) {
        $result['ok']            = true;
        $result['suggestions'][] = 'The web user "' . $phpUser . '" may run "systemctl ' . $verb . ' ' . $service . '" via sudo without a password. You are good to go.';
    } else {
        $result['errors'][] = 'The web user "' . $phpUser . '" is NOT allowed to run "sudo systemctl ' . $verb . ' ' . $service . '" non-interactively (exit ' . (int)$ret . ').';
        if ($outStr !== '') $result['errors'][] = $outStr;
        $result['suggestions'] = array_merge($result['suggestions'], $sudoersFix);
    }
} else {
    // No sudo: a direct `systemctl <verb>` only works when PHP itself runs privileged.
    $isRoot = function_exists('posix_geteuid') ? (posix_geteuid() === 0) : false;
    $result['command'] = 'systemctl ' . $verb . ' ' . escapeshellarg($service) . '  (direct, no sudo)';
    if ($isRoot) {
        $result['ok']            = true;
        $result['suggestions'][] = 'PHP runs as root, so a direct "systemctl ' . $verb . '" should succeed. (Running the web server as root is unusual — consider "Run via sudo" instead.)';
    } else {
        $result['errors'][]      = 'PHP runs as "' . $phpUser . '" (not root) and "Run via sudo" is off, so it cannot run systemctl directly.';
        $result['suggestions'][] = 'Set "Run via sudo" = Yes above, then add the sudoers rule below:';
        $result['suggestions']   = array_merge($result['suggestions'], $sudoersFix);
    }
}

jsonResponse($result);
