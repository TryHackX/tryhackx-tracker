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
    if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.ot.render_failed'), 'output' => $r['output']], 500);
    jsonResponse(['success' => true, 'file' => $r['json']['file'] ?? '', 'content' => $r['json']['content'] ?? '']);
}
if (!in_array($op, ['apply', 'workers', 'reset', 'restart'], true)) {
    jsonResponse(['error' => __('api.ot.unknown_op')], 400);
}
// Same gate as the firewall's Apply: the constant, not a helper, because that is what the rest of
// the panel uses and a second way of checking a password is a second way of getting it wrong.
$password = (string)($input['password'] ?? '');
requireAdminReauth($password, $cfg);

switch ($op) {
    case 'apply':
        $r = otApply($cfg, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.ot.write_failed'), 'output' => $r['output']], 500);
        if (!empty($r['json']['deferred'])) {
            // Not a failure: /etc is read-only inside php-fpm's mount namespace. Record it and let
            // the janitor finish, rather than telling the admin their button does not work.
            otMarkPending(true);
            jsonResponse(['success' => true, 'applied' => $r['json'], 'deferred' => true,
                          'message' => __('api.ot.queued', ['file' => $r['json']['file'] ?? __('api.ot.the_dropin')])]);
        }
        otMarkPending(false);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => __('api.ot.written', ['file' => $r['json']['file'] ?? __('api.ot.the_dropin')])]);

    case 'workers':
        $n = (int)($input['workers'] ?? 0);
        if ($n < 1 || $n > OT_WORKERS_MAX) jsonResponse(['error' => __('api.ot.workers_range', ['max' => OT_WORKERS_MAX])], 400);
        $r = otWorkers($cfg, $n, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.ot.workers_failed'), 'output' => $r['output']], 500);
        // Remember what was asked for, so the card can say when the file and the setting disagree.
        setSettings($db, ['ot_udp_workers' => (string)$n]);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => __('api.ot.workers_set', ['n' => $n, 'files' => (int)($r['json']['files_changed'] ?? 0)])]);

    case 'reset':
        $r = otReset($cfg, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.ot.remove_failed'), 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => empty($r['json']['removed'])
                          ? __('api.ot.nothing_to_remove')
                          : __('api.ot.removed')]);

    case 'restart':
        $r = otRestart($cfg);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.ot.restart_failed'), 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'applied' => $r['json'],
                      'message' => empty($r['json']['active'])
                          ? __('api.ot.restart_not_active')
                          : __('api.ot.restarted')]);
}
