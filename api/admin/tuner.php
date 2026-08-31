<?php
/**
 * POST admin/tuner — the stability probe.
 *
 *   {"op":"status"}
 *   {"op":"start","steps":6,"dwell":180,"dry_run":bool,"password":"…"}
 *   {"op":"cancel","password":"…"}
 *   {"op":"apply","pps":120000,"password":"…"}
 *
 * Starting a run moves the firewall limit on a live machine, so it takes the admin password like
 * every other action that changes the machine — and so does applying anything the run suggests. The
 * suggestion is never applied automatically: the run ends with the settings exactly as it found
 * them, and what to do about the report is a decision, not a result.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? 'status');
if (!in_array($op, ['status', 'start', 'cancel', 'apply'], true)) {
    jsonResponse(['error' => 'Unknown operation'], 400);
}

if ($op === 'status') {
    auditSuppress();   // a poll is not an event
    jsonResponse(['success' => true] + tunerStatus($cfg));
}

requireAdminReauth((string)($input['password'] ?? ''), $cfg);

if ($op === 'cancel') {
    $r = tunerCancel();
    auditNote(['summary' => 'stability probe cancelled']);
    jsonResponse(['success' => true, 'message' => 'Asked the run to stop. It restores on its way out; '
                . 'if it cannot, the janitor puts the settings back within a minute.'] + tunerStatus($cfg));
}

if ($op === 'apply') {
    $pps = (int)($input['pps'] ?? 0);
    if ($pps < 1000 || $pps > 5000000) jsonResponse(['error' => 'That is not a usable limit.'], 400);
    $st = tunerStatus($cfg);
    // Only a value the run actually reached. Applying a number nobody measured would make the report
    // decorative — the whole point is that this figure was held and watched.
    $tried = array_map(fn($s) => (int)($s['limit_pps'] ?? 0), (array)($st['steps'] ?? []));
    if (!in_array($pps, $tried, true)) {
        jsonResponse(['error' => 'That limit was not one of the steps this run measured.'], 400);
    }
    $r = netlimitApply($cfg, $pps, (int)($cfg['net_limit_burst'] ?? 100), (int)($cfg['tracker_port'] ?? 6969));
    auditNote(['target_id' => (string)$pps, 'summary' => 'applied ' . $pps . ' pps from a stability probe']);
    jsonResponse(['success' => !empty($r['ok']), 'message' => !empty($r['ok'])
        ? 'Inbound limit set to ' . number_format($pps) . ' pps.'
        : ($r['error'] ?? 'The helper refused it.')] + tunerStatus($cfg));
}

// ── start ───────────────────────────────────────────────────────────────────
if (!tunerEnabled($cfg)) {
    jsonResponse(['error' => 'The stability probe is switched off in Settings.'], 409);
}
$r = tunerRequest([
    'steps'   => (int)($input['steps'] ?? 6),
    'dwell'   => (int)($input['dwell'] ?? 180),
    'dry_run' => !empty($input['dry_run']),
]);
if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 409);

auditNote(['summary' => 'stability probe requested (' . (int)($input['steps'] ?? 6) . ' steps, '
                      . (int)($input['dwell'] ?? 180) . 's each)']);
jsonResponse(['success' => true,
    'message' => 'Requested. The janitor starts it within a minute; the settings are recorded first, '
               . 'so they go back even if the run is interrupted.'] + tunerStatus($cfg));
