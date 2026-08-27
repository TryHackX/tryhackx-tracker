<?php
/**
 * GET — what OpenTracker is tuned to right now, what the panel would set, and the one measurement
 * that explains lost announces (the socket receive buffer).
 *
 * Auth is enforced by the router; GET, so CSRF-exempt like the other admin read endpoints. Polled
 * by a card, so the helper's answer is reused for OT_STATUS_TTL seconds.
 */
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
if (!$out['configured']['cmd_set']) {
    $out['error'] = 'No OpenTracker helper command is configured.';
} elseif (!trackerExecAvailable()) {
    $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the helper.';
} else {
    $st = otStatus($cfg, !empty($_GET['fresh']));
    if (empty($st['ok'])) {
        $out['error'] = (string)($st['error'] ?? 'The helper did not answer.');
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
