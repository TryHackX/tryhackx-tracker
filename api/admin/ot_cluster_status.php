<?php
/**
 * GET admin/ot_cluster_status — the roster, the ports the panel would propose, and the two facts an
 * operator needs before deciding this is worth doing at all. Forks nothing when the feature is off.
 */
if (!otClusterEnabled($cfg)) {
    jsonResponse(['ok' => true, 'enabled' => false, 'configured' => ['cmd_set' => otClusterCommand($cfg) !== '']]);
}

$roster = otClusterRoster($cfg, true);          // the ONE place a web request may go and look
jsonResponse([
    'ok'          => empty($roster['error']),
    'enabled'     => true,
    'error'       => $roster['error'] ?? null,
    'server_time' => time(),
    'roster'      => $roster,
    'propose'     => otClusterProposePorts($cfg, $roster),
    'announce'    => otClusterAnnounceUrls($cfg),
    'warnings'    => otClusterWarnings($cfg),
    // The conflict that would quietly throttle the primary if both were on, and the limit of what the
    // performance card above is actually measuring.
    'auto_limiter_on' => netlimitAutoEnabled($cfg),
    'perf_scope_note' => 'The performance card above reads the INSTALLER\'s unit only. Nice, CPU weight, '
                       . 'affinity and the worker count set there do not reach these extra instances.',
]);
