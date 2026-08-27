<?php
/**
 * GET admin/ot_cluster_test — the Settings Test button. Read-only, and it tests the path that can
 * silently become a no-op: whether the INSTALLED mode switch understands --all.
 */
$cmd = otClusterCommand($cfg);
$out = ['ok' => false, 'checks' => [], 'errors' => [], 'suggestions' => []];
$add = function (string $name, bool $ok, string $detail = '', bool $info = false) use (&$out) {
    $out['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'info' => $info];
};

$add('PHP can run a command', trackerExecAvailable(),
     trackerExecAvailable() ? '' : 'exec() is disabled in php.ini.');
if ($cmd === '') {
    $add('A helper command is configured', false, 'Empty, so the feature is off and no card is rendered.');
    $out['suggestions'][] = 'sudo install -m 0755 /var/www/tracker.tryhackx.org/tools/opentracker/tracker-cluster.sh /usr/local/sbin/tracker-cluster.sh';
    $out['suggestions'][] = "echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-cluster.sh' | sudo tee /etc/sudoers.d/tracker-cluster";
    $out['suggestions'][] = 'sudo chmod 0440 /etc/sudoers.d/tracker-cluster && sudo visudo -c -f /etc/sudoers.d/tracker-cluster';
    jsonResponse($out);
}
$add('A helper command is configured', otClusterValidCommand($cmd), $cmd);

if (trackerExecAvailable() && otClusterValidCommand($cmd)) {
    $parts = preg_split('/\s+/', trim($cmd));
    $script = end($parts);
    $lines = []; $rc = null;
    @exec('sudo -n -l ' . escapeshellarg($script) . ' 2>&1', $lines, $rc);
    $add('sudo grants the cluster helper without a password', $rc === 0,
         $rc === 0 ? trim(implode(' ', $lines)) : 'sudo refused: ' . mb_substr(trim(implode(' ', $lines)), 0, 200));
}

$r = otClusterRun($cfg, ['check']);
$j = is_array($r['json']) ? $r['json'] : [];
$add('The cluster helper answers', $r['json'] !== null,
     $r['json'] === null ? (string)$r['error'] : ('version ' . (int)($j['version'] ?? 0)));
if ($r['json'] !== null) {
    $add('It runs as root', !empty($j['root']));
    $add('The shared binary symlink exists', !empty($j['shared_binary']),
         empty($j['shared_binary']) ? 'Every instance executes /home/tracker/opentracker; nothing can be created without it.' : '');
    $add('The primary\'s ports could be read', (int)($j['primary_udp'] ?? 0) > 0,
         (int)($j['primary_udp'] ?? 0) > 0
            ? ('UDP ' . (int)$j['primary_udp'] . ', TCP ' . (int)($j['primary_tcp'] ?? 0))
            : 'Ports cannot be proposed without them.');
    if (!empty($j['notes'])) $out['errors'][] = (string)$j['notes'];
}

/**
 * The check that matters most, because failing it is silent and nocturnal.
 *
 * The mode switch is a SEPARATE script at /usr/local/sbin/tracker-mode.sh, which no deploy updates.
 * If the panel is told to append --all before that installed copy understands the flag, the script
 * falls through to its usage message and exits 1, scheduleApply() aborts before flipping tracker_mode,
 * and the scheduled whitelist hours simply never begin - every night, with nothing to see except a
 * card nobody has open at two in the morning.
 */
$switchCmd = scheduleSwitchCommand($cfg);
if ($switchCmd !== '' && trackerExecAvailable()) {
    $lines = []; $rc = null;
    @exec($switchCmd . ' --version 2>&1', $lines, $rc);
    $ver = trim(implode(' ', $lines));
    $understands = $rc === 0 && str_contains($ver, 'tracker-mode.sh');
    $add('The INSTALLED mode switch understands --all', $understands,
         $understands ? $ver
            : 'The copy at ' . $switchCmd . ' is older than this release. Reinstall it before switching '
            . 'several instances, or the nightly schedule will fail silently.');
    if (!$understands) {
        $out['suggestions'][] = 'sudo install -m 0755 /var/www/tracker.tryhackx.org/tools/opentracker/tracker-mode.sh /usr/local/sbin/tracker-mode.sh';
    }
}

if (netlimitAutoEnabled($cfg)) {
    $out['errors'][] = 'The automatic inbound limiter is on. It only counts the primary\'s port, so extra '
                     . 'instances would hide traffic from it while leaving the load - and it would answer by '
                     . 'throttling the primary. Creating an instance is refused while it is on.';
}

$blocking = array_filter($out['checks'], fn($c) => empty($c['ok']) && empty($c['info']));
$out['ok'] = count($blocking) === 0;
jsonResponse($out);
