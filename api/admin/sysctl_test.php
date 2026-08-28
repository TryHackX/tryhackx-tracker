<?php
/**
 * GET admin/sysctl_test — the Settings Test button. Read-only in every branch: it establishes
 * whether the path from the panel to the kernel exists, and never uses it.
 */
$cmd = sysctlCommand($cfg);
$out = ['ok' => false, 'checks' => [], 'errors' => [], 'suggestions' => []];
$add = function (string $name, bool $ok, string $detail = '') use (&$out) {
    $out['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$add('PHP can run a command', trackerExecAvailable(),
     trackerExecAvailable() ? '' : 'exec() is disabled in php.ini — no helper can be reached at all.');

if ($cmd === '') {
    $add('A helper command is configured', false, 'Nothing is saved here, so the feature is off and the card is not rendered. The grey text in '
         . 'the field is a suggestion, not a value — type it in and press Save to switch this on.');
    $out['suggestions'][] = 'sudo install -m 0755 /var/www/tracker.tryhackx.org/tools/opentracker/tracker-sysctl.sh /usr/local/sbin/tracker-sysctl.sh';
    $out['suggestions'][] = "echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-sysctl.sh' | sudo tee /etc/sudoers.d/tracker-sysctl";
    $out['suggestions'][] = 'sudo chmod 0440 /etc/sudoers.d/tracker-sysctl && sudo visudo -c -f /etc/sudoers.d/tracker-sysctl';
    $out['suggestions'][] = 'Then put "sudo -n /usr/local/sbin/tracker-sysctl.sh" in the field above and enable the feature.';
    jsonResponse($out);
}
$add('A helper command is configured', sysctlValidCommand($cmd), sysctlValidCommand($cmd) ? $cmd : 'Contains characters that are not allowed.');

// `sudo -n -l <script>` asks whether the permission exists. It never runs the script.
if (trackerExecAvailable() && sysctlValidCommand($cmd)) {
    $parts = preg_split('/\s+/', trim($cmd));
    $script = end($parts);
    $lines = []; $rc = null;
    @exec('sudo -n -l ' . escapeshellarg($script) . ' 2>&1', $lines, $rc);
    $granted = $rc === 0;
    $add('sudo grants this script without a password', $granted,
         $granted ? trim(implode(' ', $lines)) : 'sudo refused: ' . mb_substr(trim(implode(' ', $lines)), 0, 200));
    if (!$granted) {
        $out['errors'][] = 'The sudoers rule is missing or does not match the script path.';
        $out['suggestions'][] = "echo 'www-data ALL=(root) NOPASSWD: " . $script . "' | sudo tee /etc/sudoers.d/tracker-sysctl";
        $out['suggestions'][] = 'sudo chmod 0440 /etc/sudoers.d/tracker-sysctl && sudo visudo -c -f /etc/sudoers.d/tracker-sysctl';
    }
}

$r = sysctlRun($cfg, ['check']);
$j = is_array($r['json']) ? $r['json'] : [];
$add('The helper answers', $r['json'] !== null, $r['json'] === null ? (string)$r['error'] : ('version ' . (int)($j['version'] ?? 0)));
if ($r['json'] !== null) {
    $add('It is running as root', !empty($j['root']));
    $add('It sees the machine\'s own network stack', !empty($j['netns_shared']),
         empty($j['netns_shared'])
            ? 'A private network namespace — a change made here would be invisible to everything else, so arming is refused.'
            : '');
    $add('An automatic undo can be scheduled', !empty($j['systemd_run']),
         empty($j['systemd_run']) ? 'systemd-run is missing; the only watchdog left is the janitor timer.' : '');

    // These two are expected to be false from a web request on a hardened box, and that is fine:
    // the janitor does the writing. Reported as information, not as failure.
    $out['checks'][] = ['name' => '/proc/sys is writable from here', 'ok' => !empty($j['proc_writable']), 'info' => true,
        'detail' => empty($j['proc_writable'])
            ? 'No — php-fpm runs with ProtectKernelTunables=yes, so the janitor performs every write. Expected.'
            : 'Yes.'];
    $out['checks'][] = ['name' => '/etc/sysctl.d is writable from here', 'ok' => !empty($j['dir_writable']), 'info' => true,
        'detail' => empty($j['dir_writable'])
            ? 'No — ProtectSystem makes /etc read-only for the panel, so confirming is finished by the janitor. Expected.'
            : 'Yes.'];
    if (!empty($j['notes'])) $out['errors'][] = (string)$j['notes'];
}

$blocking = array_filter($out['checks'], fn($c) => empty($c['ok']) && empty($c['info']));
$out['ok'] = count($blocking) === 0;
jsonResponse($out);
