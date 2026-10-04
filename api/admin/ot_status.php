<?php
/**
 * GET — what OpenTracker is tuned to right now, what the panel would set, and the one measurement
 * that explains lost announces (the socket receive buffer).
 *
 * Auth is enforced by the router; GET, so CSRF-exempt like the other admin read endpoints. Polled
 * by a card, so the helper's answer is reused for OT_STATUS_TTL seconds.
 */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // polled: never hold the session lock across the read
$out = [
    'ok' => true,
    'server_time' => time(),
    'configured' => [
        'cmd'          => otPerfCommand($cfg),
        'cmd_set'      => otPerfCommand($cfg) !== '',
        'nice'         => otNice($cfg),
        'cpu_weight'   => otCpuWeight($cfg),
        'cpu_affinity' => otCpuAffinity($cfg),
        'limit_nofile' => otLimitNofile($cfg),
        'udp_workers'  => otUdpWorkers($cfg),
    ],
    'exec_available' => trackerExecAvailable(),
    'status'  => null,
    'advice'  => [],
    'error'   => null,
];
// In the reader's language (1.73.0: English on every page); the card finds them back by key (t.find(…, 'api.'): the
// traffic page's bundle carries api.ot.), so they follow the live language switch.
if (!$out['configured']['cmd_set']) {
    $out['error'] = __('api.ot.no_helper');
} elseif (!trackerExecAvailable()) {
    $out['error'] = __('api.ot.exec_disabled');
} else {
    $st = otStatus($cfg, !empty($_GET['fresh']));
    if (empty($st['ok'])) {
        $out['error'] = (string)($st['error'] ?? __('api.ot.no_answer'));
        $out['helper_output'] = mb_substr((string)($st['output'] ?? ''), 0, 1000);
    } else {
        $out['status'] = $st;
        $out['advice'] = otAdvice($st);
        // Does what is in force match what the panel would write? Saying "these are your settings"
        // while the unit runs something else is the kind of half-truth this panel keeps finding.
        $out['in_sync'] = ((int)$st['nice'] === otNice($cfg))
            && ((int)$st['cpu_weight'] === otCpuWeight($cfg) || (int)$st['cpu_weight'] === 0)
            && (trim((string)$st['cpu_affinity']) === otCpuAffinity($cfg))
            && ((int)$st['limit_nofile'] === otLimitNofile($cfg));
        $out['workers_in_sync'] = otUdpWorkers($cfg) === 0 || (int)$st['workers'] === otUdpWorkers($cfg);
    }
}
jsonResponse($out);
