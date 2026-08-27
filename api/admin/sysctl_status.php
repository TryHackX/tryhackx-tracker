<?php
/**
 * GET admin/sysctl_status — what the kernel network buffers are, what the panel wants, and what the
 * machine's own counters say about which of them is worth touching.
 *
 * Returns enabled:false and forks nothing when the feature is off, so an install that never turned
 * it on never pays for a card it does not have.
 */
if (!sysctlEnabled($cfg)) {
    jsonResponse(['ok' => true, 'enabled' => false, 'configured' => ['cmd_set' => sysctlCommand($cfg) !== '']]);
}

$port = netlimitPort($cfg);
$st = sysctlStatus($cfg, $port);
if (empty($st['ok'])) {
    jsonResponse(['ok' => false, 'enabled' => true, 'error' => $st['error'] ?? 'The helper did not answer.',
                  'output' => mb_substr((string)($st['output'] ?? ''), 0, 600)]);
}

$state = sysctlState();
$armed = is_array($state['armed'] ?? null) ? $state['armed'] : null;
if ($armed) {
    $armed['seconds_left'] = max(0, (int)($armed['deadline'] ?? 0) - time());
    // The nominal window is not the promise. systemd reverts at the deadline; if it could not be
    // scheduled, the janitor does it on its next minute tick, so the honest worst case is a minute
    // longer. Showing the optimistic number is how someone decides not to power-cycle in time.
    $armed['worst_case_seconds'] = $armed['seconds_left'] + (($armed['watchdog'] ?? '') === 'systemd' ? 5 : 60);
}

jsonResponse([
    'ok'        => true,
    'enabled'   => true,
    'server_time' => time(),
    'status'    => $st,
    'armed'     => $armed,
    'request'   => is_array($state['request'] ?? null) ? $state['request'] : null,
    'last_error' => $state['last_error'] ?? null,
    'last_revert_at' => $state['last_revert_at'] ?? null,
    'last_revert_reason' => $state['last_revert_reason'] ?? null,
    'keys'      => sysctlKeys(),
    'verdict'   => sysctlSocketVerdict($st),
    'suggest'   => sysctlSuggest($st),
    'advice'    => sysctlAdvice($st, $cfg),
    'confirm_seconds' => sysctlConfirmSeconds($cfg),
    'auto_limiter_on' => netlimitAutoEnabled($cfg),
]);
