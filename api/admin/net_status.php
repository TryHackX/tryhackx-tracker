<?php
/**
 * GET — everything the "UDP traffic" card shows: whether the feature can run at all, what the
 * firewall currently enforces, the live packets/second, the measured recommendation and the state
 * of the automatic mode / panic window.
 *
 * Auth is enforced by the router (admin/*); GET, so CSRF-exempt like the other admin read
 * endpoints. Polled every few seconds, so the helper's output is reused for NET_STATUS_TTL seconds
 * (includes/netlimit.php) instead of forking a process per request — the router already skips the
 * heavy janitors for this endpoint.
 *
 * Nothing here changes the firewall. Applying a limit is admin/net_apply (POST + admin password).
 */

$now = time();
$cmdSet = netlimitCommand($cfg) !== '';
$out = [
    'ok'            => true,
    'server_time'   => $now,
    'configured'    => [
        'monitor'    => netlimitMonitorEnabled($cfg),
        'limit'      => netlimitEnabled($cfg),
        'auto'       => netlimitAutoEnabled($cfg),
        'pps'        => netlimitPps($cfg),
        'burst'      => netlimitBurst($cfg),
        'port'       => netlimitPort($cfg),
        'sample_seconds' => netlimitSampleSeconds($cfg),
        'keep_days'  => netlimitKeepDays($cfg),
        'auto_min'   => netlimitAutoMin($cfg),
        'auto_max'   => netlimitAutoMax($cfg),
        'auto_target' => netlimitAutoTarget($cfg),
        'auto_target_cpu' => netlimitAutoTargetCpu($cfg),
        'cmd'        => netlimitCommand($cfg),
        'cmd_set'    => $cmdSet,
    ],
    'exec_available' => trackerExecAvailable(),
    'cpus'          => netlimitCpuCount(),
    'load_per_core' => netlimitLoadPerCore(),
    'firewall'      => null,
    'live'          => null,
    'panic'         => null,
    'auto_state'    => null,
    'last_apply'    => null,
    'error'         => null,
];

if (!$cmdSet) {
    $out['error'] = 'No rate-limit helper command is configured.';
} elseif (!trackerExecAvailable()) {
    $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the firewall helper.';
} else {
    $status = netlimitStatus($cfg);
    if (empty($status['ok'])) {
        $out['error'] = (string)($status['error'] ?? 'The firewall helper did not answer.');
        $out['helper_output'] = mb_substr((string)($status['output'] ?? ''), 0, 1000);
    } else {
        $out['firewall'] = $status;
        // A cached status carries stale counters; recomputing a rate from them would divide a zero
        // difference by a real span and show a dip that never happened.
        if (empty($status['cached'])) $out['live'] = netlimitLive($status, $now);
    }
}

$state = netlimitStateRead();
if ($out['live'] === null) {
    $live = (array)($state['live'] ?? []);
    if (!empty($live['ts'])) $out['live'] = ['ts' => (int)$live['ts'], 'span' => (int)($live['span'] ?? 0),
                                             'pps' => (array)($live['pps'] ?? []), 'epps' => (array)($live['epps'] ?? []), 'stale' => true];
}
$panicUntil = (int)($state['panic']['until'] ?? 0);
if ($panicUntil > 0) {
    $out['panic'] = ['until' => $panicUntil, 'seconds_left' => max(0, $panicUntil - $now),
                     'restore_pps' => (int)($state['panic']['restore_pps'] ?? 0),
                     'restore_enabled' => (int)($state['panic']['restore_enabled'] ?? 0) === 1];
}
$out['auto_state'] = ['over' => (int)($state['auto']['over'] ?? 0), 'under' => (int)($state['auto']['under'] ?? 0),
                      'last_move_at' => (int)($state['auto']['last_move_at'] ?? 0),
                      'last_move' => $state['auto']['last_move'] ?? null,
                      'note' => (string)($state['auto']['note'] ?? ''),
                      'hysteresis' => NET_AUTO_HYSTERESIS];
if ((int)($state['last_apply_at'] ?? 0) > 0) {
    $out['last_apply'] = ['at' => (int)$state['last_apply_at'], 'pps' => (int)($state['last_apply_pps'] ?? 0),
                          'source' => (string)($state['last_apply_source'] ?? '')];
}
// Applied but not yet written to /etc: php-fpm's mount namespace makes /etc read-only, so the
// janitor saves it on its next tick. The card has to say so — until then a reboot undoes the
// admin's decision without a word.
$out['persist_deferred'] = !empty($state['persist_deferred']);
$out['last_persist_at'] = (int)($state['last_persist_at'] ?? 0);
$out['last_error'] = $state['last_error'] ?? null;
$out['last_error_at'] = (int)($state['last_error_at'] ?? 0);
$out['last_tick_at'] = (int)($state['last_tick_at'] ?? 0);

// The measured advice. Cheap (one indexed range scan over at most a few 10 000 rows) and it is what
// turns "pick a number of packets per second" into a decision an admin can actually make.
try {
    $days = max(1, min(30, (int)($_GET['days'] ?? 7)));
    $rec = netlimitRecommend($db, $cfg, $days, $now);
    // "Flood" = the arrivals are far above what anything is letting through, either because the
    // counting mode drops nothing or because somebody else's rule is doing the dropping downstream.
    // In that state the measured peak is the swarm, not demand, and the wording has to say so.
    $fw = $out['firewall'] ?? [];
    $flood = is_array($fw) && (($fw['mode'] ?? '') === 'count' || !empty($fw['manual_rules']));
    $out['recommend_days'] = $days;
    $rec['text'] = netlimitRecommendText($rec, $flood);
    $rec['flood'] = $flood;
    $out['recommend'] = $rec;
} catch (\Throwable $e) {
    $out['recommend'] = null;
    $out['recommend_error'] = $e->getMessage();
}

jsonResponse($out);
