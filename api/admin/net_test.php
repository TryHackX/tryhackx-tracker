<?php
/**
 * GET — the "Test" button for the UDP rate limit: can this machine run the feature at all, and is
 * the web user allowed to? Read-only in every branch — it never loads, changes or deletes a rule.
 *
 * Auth is enforced by the router (admin/*). Same shape as admin/test_tracker_permission
 * (ok / errors / suggestions / command / output), so the panel renders it with the same code.
 *
 * Three questions, answered in order, because each one makes the next meaningless:
 *   1. can PHP run commands at all (exec, and a helper command that is configured and sane);
 *   2. may the web user run THIS helper through sudo without a password (`sudo -n -l <script>` —
 *      it only lists the permission, it never runs the script);
 *   3. does the machine have what the helper needs (nft, /etc/nftables.d, the include line that
 *      makes the rule survive a reboot) — that part is the helper's own `check` action.
 */

$phpUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
    : get_current_user();

$cmd = netlimitCommand($cfg);
$raw = trim((string)($cfg['net_limit_cmd'] ?? ''));

$result = [
    'ok' => false, 'php_user' => $phpUser, 'os' => PHP_OS_FAMILY, 'command' => '', 'output' => '',
    'errors' => [], 'suggestions' => [], 'check' => null, 'cpus' => netlimitCpuCount(),
];

// ── 1. can we run anything ──
if ($raw !== '' && $cmd === '') {
    $result['errors'][]      = 'The helper command contains characters that are not allowed (only letters, digits, space and _ . / - are).';
    $result['suggestions'][] = 'Set it back to the default: sudo -n /usr/local/sbin/tracker-netlimit.sh';
    jsonResponse($result);
}
if ($cmd === '') {
    $result['errors'][]      = 'No helper command is configured, so the panel cannot touch the firewall.';
    $result['suggestions'][] = 'Use the default: sudo -n /usr/local/sbin/tracker-netlimit.sh';
    jsonResponse($result);
}
if (!trackerExecAvailable()) {
    $result['errors'][]      = 'PHP exec() is disabled on this server — the panel cannot run the helper at all.';
    $result['suggestions'][] = 'Remove exec from disable_functions in php.ini, or drive the helper from a shell instead.';
    jsonResponse($result);
}

// ── 2. is the web user allowed to run it (without running it) ──
// The command prefix may or may not start with sudo; only the sudo case can be dry-run safely.
$parts  = preg_split('/\s+/', $cmd) ?: [];
$script = end($parts) ?: $cmd;
$usesSudo = ($parts[0] ?? '') === 'sudo';
$sudoersLine = $phpUser . ' ALL=(root) NOPASSWD: ' . $script;
$sudoersFix  = [
    'Install the helper and allow exactly it — nothing else:',
    'sudo install -m 0755 tools/opentracker/tracker-netlimit.sh ' . $script,
    "echo '" . $sudoersLine . "' | sudo tee /etc/sudoers.d/tracker-netlimit",
    'sudo chmod 440 /etc/sudoers.d/tracker-netlimit',
];

if (!@is_file($script)) {
    $result['errors'][]    = 'The helper script was not found at ' . $script . '.';
    $result['suggestions'] = array_merge($result['suggestions'], $sudoersFix);
    jsonResponse($result);
}

if ($usesSudo) {
    $probe = 'sudo -n -l ' . escapeshellarg($script) . ' 2>&1';
    $lines = []; $rc = null;
    @exec($probe, $lines, $rc);
    $result['command'] = $probe;
    $result['output']  = trim(implode("\n", $lines));
    if ((int)$rc !== 0) {
        $result['errors'][] = 'The web user "' . $phpUser . '" may NOT run "' . $script . '" through sudo without a password (exit ' . (int)$rc . ').';
        if ($result['output'] !== '') $result['errors'][] = $result['output'];
        $result['suggestions'] = array_merge($result['suggestions'], $sudoersFix);
        jsonResponse($result);
    }
} else {
    $isRoot = function_exists('posix_geteuid') ? (posix_geteuid() === 0) : false;
    $result['command'] = $script . '  (direct, no sudo)';
    if (!$isRoot) {
        $result['errors'][]      = 'PHP runs as "' . $phpUser . '" (not root) and the command does not start with sudo, so nft will refuse.';
        $result['suggestions'][] = 'Use "sudo -n ' . $script . '" as the helper command, then add the sudoers rule:';
        $result['suggestions']   = array_merge($result['suggestions'], $sudoersFix);
        jsonResponse($result);
    }
}

// ── 3. ask the helper what the machine can actually do ──
$r = netlimitRun($cfg, ['check']);
$result['output'] = $r['output'];
if (!is_array($r['json'])) {
    $result['errors'][]      = $r['error'] ?? 'The helper did not answer.';
    $result['suggestions'][] = 'Run it by hand to see what it says: sudo ' . $script . ' check';
    jsonResponse($result);
}
$check = $r['json'];
$result['check'] = $check;

if (empty($check['nft'])) {
    $result['errors'][]      = 'nftables (nft) is not installed on this machine — inbound rate limiting is unavailable.';
    $result['suggestions'][] = 'sudo apt install nftables && sudo systemctl enable --now nftables';
    jsonResponse($result);
}
if (empty($check['dir'])) {
    $result['errors'][]      = ($check['conf'] ?? '/etc/nftables.conf') . "'s drop-in directory does not exist.";
    $result['suggestions'][] = 'sudo install -d -m 0755 /etc/nftables.d';
}
if (empty($check['include_ok'])) {
    // Not fatal: the rule would load and work, it just would not survive a reboot. Say exactly that.
    $result['errors'][]      = 'The rule would work now but would be LOST on the next reboot: ' . ($check['conf'] ?? '/etc/nftables.conf') . ' does not include the drop-in directory.';
    $result['suggestions'][] = 'Append one line to ' . ($check['conf'] ?? '/etc/nftables.conf') . ':';
    $result['suggestions'][] = 'include "/etc/nftables.d/*.nft"';
    $result['suggestions'][] = 'then reload it: sudo nft -f ' . ($check['conf'] ?? '/etc/nftables.conf');
}

if (!$result['errors']) {
    $result['ok'] = true;
    $result['suggestions'][] = 'The web user "' . $phpUser . '" can load and remove the inbound limit, and the rule will survive a reboot.';
    $result['suggestions'][] = 'Undo at any time: the "Remove limit" button, or by hand — sudo nft delete table inet ottrack_in && sudo rm /etc/nftables.d/ottrack-in.nft';
}
jsonResponse($result);
