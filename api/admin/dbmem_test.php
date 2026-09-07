<?php
/**
 * GET admin/dbmem_test — the Settings Test button. Read-only in every branch: it establishes
 * whether the path from the panel to the database engine exists, and never uses it to change anything.
 */
$cmd = dbmemCommand($cfg);
$out = ['ok' => false, 'checks' => [], 'errors' => [], 'suggestions' => []];
$add = function (string $name, bool $ok, string $detail = '') use (&$out) {
    $out['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$add('PHP can run a command', trackerExecAvailable(),
     trackerExecAvailable() ? '' : 'exec() is disabled in php.ini — no helper can be reached at all.');

if ($cmd === '') {
    $out['configured'] = false;
    $add('A helper command is configured', false, 'Nothing is saved here, so the feature is off and the card is not rendered. '
         . 'Type the command in and press Save to switch this on.');
    $out['suggestions'][] = 'sudo install -m 0755 /var/www/tracker.tryhackx.org/tools/opentracker/tracker-dbmem.sh /usr/local/sbin/tracker-dbmem.sh';
    $out['suggestions'][] = "echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-dbmem.sh' | sudo tee /etc/sudoers.d/tracker-dbmem";
    $out['suggestions'][] = 'sudo chmod 0440 /etc/sudoers.d/tracker-dbmem && sudo visudo -c -f /etc/sudoers.d/tracker-dbmem';
    $out['suggestions'][] = 'Then put "sudo -n /usr/local/sbin/tracker-dbmem.sh" in the field above and enable the feature.';
    jsonResponse($out);
}
$add('A helper command is configured', dbmemValidCommand($cmd), dbmemValidCommand($cmd) ? $cmd : 'Contains characters that are not allowed.');

if (trackerExecAvailable() && dbmemValidCommand($cmd)) {
    $parts = preg_split('/\s+/', trim($cmd));
    $script = end($parts);
    $lines = []; $rc = null;
    @exec('sudo -n -l ' . escapeshellarg($script) . ' 2>&1', $lines, $rc);
    $granted = $rc === 0;
    $add('sudo grants this script without a password', $granted,
         $granted ? trim(implode(' ', $lines)) : 'sudo refused: ' . mb_substr(trim(implode(' ', $lines)), 0, 200));
    if (!$granted) {
        $out['errors'][] = 'The sudoers rule is missing or does not match the script path.';
        $out['suggestions'][] = "echo 'www-data ALL=(root) NOPASSWD: " . $script . "' | sudo tee /etc/sudoers.d/tracker-dbmem";
        $out['suggestions'][] = 'sudo chmod 0440 /etc/sudoers.d/tracker-dbmem && sudo visudo -c -f /etc/sudoers.d/tracker-dbmem';
    }
}

$r = dbmemRun($cfg, ['check']);
$j = is_array($r['json']) ? $r['json'] : [];
$add('The helper answers', $r['json'] !== null, $r['json'] === null ? (string)$r['error'] : (string)($j['client'] ?? ''));
if ($r['json'] !== null) {
    $add('Root can query the engine over the socket', !empty($j['ok']),
         !empty($j['ok']) ? trim((string)($j['engine'] ?? '') . ' ' . (string)($j['server_version'] ?? '')) : trim((string)($j['notes'] ?? '')));
    if (!empty($j['ok']) && trim((string)($j['notes'] ?? '')) !== '') {
        // "the drop-in directory is read-only here" is expected on a hardened box: the janitor writes it.
        $out['checks'][] = ['name' => 'The drop-in directory is writable from here', 'ok' => false, 'info' => true,
                            'detail' => trim((string)$j['notes']) . ' Expected under ProtectSystem=full; the janitor performs the write.'];
    }
    $out['file'] = $j['file'] ?? null;
}
$out['ok'] = $r['json'] !== null && !empty($j['ok']);
if (!$out['ok'] && $r['json'] === null) $out['errors'][] = (string)$r['error'];
jsonResponse($out);
