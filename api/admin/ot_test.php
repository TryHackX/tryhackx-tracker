<?php
/**
 * GET — the Test button for the OpenTracker performance section: can this machine do any of it,
 * and if not, what exactly is missing. Same pattern as check_whitelist_path.php.
 */
$out = ['ok' => false, 'cmd' => otPerfCommand($cfg), 'exec_available' => trackerExecAvailable()];
if ($out['cmd'] === '') {
    $out['error'] = 'No helper command is set. Without it the panel can only read, never change.';
} elseif (!otValidCommand($out['cmd'])) {
    $out['error'] = 'The command contains characters that are not allowed (letters, digits, space, dot, slash, dash and underscore only).';
} elseif (!trackerExecAvailable()) {
    $out['error'] = 'PHP exec() is disabled on this server.';
} else {
    $chk = otCheck($cfg);
    $out = array_merge($out, $chk);
    $out['ok'] = !empty($chk['ok']);
}
jsonResponse($out);
