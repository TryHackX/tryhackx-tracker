<?php
/**
 * POST — change how OpenTracker is run. Every operation needs the admin password, because the
 * cheapest of them still restarts a service that thousands of peers are talking to.
 *
 *   {"op":"apply"}     write the panel's drop-in (Nice / CPUWeight / CPUAffinity / LimitNOFILE)
 *   {"op":"workers"}   write listen.udp.workers into BOTH mode config files
 *   {"op":"reset"}     delete the panel's drop-in; opentracker's own settings are left alone
 *   {"op":"restart"}   restart the unit
 *   {"op":"preview"}   render the drop-in without writing it (no password — it changes nothing)
 */
requirePost();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$op = (string)($input['op'] ?? '');

if ($op === 'preview') {
    $r = otApply($cfg, true);
    if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not render the drop-in.', 'output' => $r['output']], 500);
    jsonResponse(['success' => true, 'file' => $r['json']['file'] ?? '', 'content' => $r['json']['content'] ?? '']);
}
if (!in_array($op, ['apply', 'workers', 'reset', 'restart'], true)) {
    jsonResponse(['error' => 'Unknown operation.'], 400);
}
// Same gate as the firewall's Apply: the constant, not a helper, because that is what the rest of
// the panel uses and a second way of checking a password is a second way of getting it wrong.
$password = (string)($input['password'] ?? '');
if ($password === '' || ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

switch ($op) {
    case 'apply':
        $r = otApply($cfg, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not write the drop-in.', 'output' => $r['output']], 500);
        if (!empty($r['json']['deferred'])) {
            // Not a failure: /etc is read-only inside php-fpm's mount namespace. Record it and let
            // the janitor finish, rather than telling the admin their button does not work.
            otMarkPending(true);
            jsonResponse(['success' => true, 'applied' => $r['json'], 'deferred' => true,
                          'message' => 'Queued: the panel’s PHP cannot write ' . ($r['json']['file'] ?? 'the drop-in')
                                       . ' (systemd ProtectSystem), so the janitor writes it within a minute.']);
        }
        otMarkPending(false);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => 'Written to ' . ($r['json']['file'] ?? 'the drop-in')
                                   . '. Nice and CPUWeight are live; CPUAffinity and the file limit need a restart.']);

    case 'workers':
        $n = (int)($input['workers'] ?? 0);
        if ($n < 1 || $n > OT_WORKERS_MAX) jsonResponse(['error' => 'Worker count must be between 1 and ' . OT_WORKERS_MAX . '.'], 400);
        $r = otWorkers($cfg, $n, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not change the worker count.', 'output' => $r['output']], 500);
        // Remember what was asked for, so the card can say when the file and the setting disagree.
        setSettings($db, ['ot_udp_workers' => (string)$n]);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => 'listen.udp.workers set to ' . $n . ' in ' . (int)($r['json']['files_changed'] ?? 0)
                                   . ' file(s). opentracker reads this only at start-up — restart to use it.']);

    case 'reset':
        $r = otReset($cfg, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not remove the drop-in.', 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => empty($r['json']['removed'])
                          ? 'There was no panel drop-in to remove — nothing changed.'
                          : 'The panel drop-in is gone. Anything the installer set is untouched; restart to drop what it had applied.']);

    case 'restart':
        $r = otRestart($cfg);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Restart failed.', 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => empty($r['json']['active'])
                          ? 'The restart ran but the service is NOT active — check journalctl.'
                          : 'Restarted, and the service is running.']);
}
