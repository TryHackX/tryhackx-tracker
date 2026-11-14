<?php
/**
 * GET admin/livesync_test — the Settings Test button for live peer sync.
 *
 * Read-only in every branch. It checks the things the panel cannot see from PHP: whether the
 * installed helper exists and sudo grants it, whether THIS build of opentracker has livesync
 * compiled in at all (a build without it ignores `-s` silently, which would leave the panel
 * reporting a tunnel that carries nothing), and whether a tunnel interface exists to bind to.
 */
$cmd = livesyncCommand($cfg);
$out = ['ok' => false, 'checks' => [], 'errors' => [], 'suggestions' => []];
$add = function (string $name, bool $ok, string $detail = '') use (&$out) {
    $out['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$add('PHP can run a command', trackerExecAvailable(),
     trackerExecAvailable() ? '' : 'exec() is disabled in php.ini — no helper can be reached at all.');

if ($cmd === '') {
    $out['configured'] = false;
    $add('A helper command is configured', false,
         'Nothing is saved here, so live sync is off and no card is rendered. The grey text in the '
         . 'field is a suggestion, not a value — type it in and press Save to switch this on.');
    $out['suggestions'][] = 'sudo install -m 0755 ' . dirname(__DIR__, 2) . '/tools/opentracker/tracker-livesync.sh /usr/local/sbin/tracker-livesync.sh';
    $out['suggestions'][] = "echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-livesync.sh' | sudo tee /etc/sudoers.d/tracker-livesync";
    $out['suggestions'][] = 'sudo chmod 0440 /etc/sudoers.d/tracker-livesync && sudo visudo -c -f /etc/sudoers.d/tracker-livesync';
    jsonResponse($out);
}
$add('A helper command is configured', livesyncValidCommand($cmd), $cmd);

if (trackerExecAvailable() && livesyncValidCommand($cmd)) {
    $parts = preg_split('/\s+/', trim($cmd));
    $script = end($parts);
    $lines = []; $rc = null;
    @exec('sudo -n -l ' . escapeshellarg($script) . ' 2>&1', $lines, $rc);
    $add('sudo grants the live sync helper without a password', $rc === 0,
         $rc === 0 ? trim(implode(' ', $lines)) : 'sudo refused: ' . mb_substr(trim(implode(' ', $lines)), 0, 200));

    $r = livesyncRun($cfg, ['check']);
    if (!is_array($r['json'])) {
        $add('the helper answers', false, (string)($r['error'] ?? 'no answer'));
    } else {
        foreach ((array)($r['json']['checks'] ?? []) as $c) {
            $add((string)($c['name'] ?? '?'), !empty($c['ok']), (string)($c['detail'] ?? ''));
        }
    }
}

// The configuration itself, whether or not the machine is ready for it.
foreach (livesyncValidate($cfg) as $problem) {
    $add('the addresses make sense', false, $problem);
}
if (!livesyncValidate($cfg)) {
    $add('the addresses make sense', true,
         livesyncBindIp($cfg) . ' → ' . livesyncPeerIp($cfg) . ' on UDP ' . livesyncPort($cfg));
}

$out['ok'] = !in_array(false, array_column($out['checks'], 'ok'), true);
if (!$out['ok'] && !$out['suggestions']) {
    $out['suggestions'] = livesyncSetupHints($cfg);
}
jsonResponse($out);
