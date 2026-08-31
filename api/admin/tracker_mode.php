<?php
/**
 * POST admin/tracker_mode — what mode the tracker is REALLY in, and switching it on purpose.
 *
 *   {"op":"status"}                                  — ask the helper, bypassing the cache
 *   {"op":"switch","mode":"whitelist","password":"…"} — prepare the list, switch the service, flip the setting
 *
 * This endpoint exists because the panel used to have no way to do either. `tracker_mode` was an
 * ordinary settings row: changing it in Settings told the panel which file to generate and what the
 * public pages should promise, and told the TRACKER nothing at all. The only code that ever ran the
 * mode helper was the schedule, and only while the schedule was switched on. So an operator could
 * select "whitelist", watch a whitelist file appear, and be served by the blacklist build the whole
 * time — with every status card agreeing with them, because every status card was reading the same
 * row they had just written.
 *
 * Switching restarts the tracker, so it is a risky action and takes the admin password like the rest
 * of them. Reading the mode is free and takes none.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? 'status');
if (!in_array($op, ['status', 'switch'], true)) jsonResponse(['error' => 'Unknown operation'], 400);

if ($op === 'status') {
    // fresh: the whole point of pressing Test is not to be told what we already believed
    $agree = scheduleModeAgreement($cfg, true);
    $msg = $agree['known']
        ? ($agree['match']
            ? 'The tracker is running ' . $agree['actual'] . ' mode, which is what the panel says.'
            : 'MISMATCH — the panel says ' . $agree['panel'] . ' but the tracker is running '
              . $agree['actual'] . '. Press “Switch the tracker now”, or change the panel back.')
        : 'Could not read the running mode. ' . ($agree['error'] ?? '');
    jsonResponse(['success' => $agree['known'], 'message' => $msg] + $agree);
}

// ── switching ───────────────────────────────────────────────────────────────
$mode = (string)($input['mode'] ?? '');
if (!in_array($mode, ['whitelist', 'blacklist'], true)) {
    jsonResponse(['error' => 'mode must be "whitelist" or "blacklist"'], 400);
}
requireAdminReauth((string)($input['password'] ?? ''), $cfg);

if (scheduleSwitchCommand($cfg) === '') {
    jsonResponse(['error' => 'No mode switch command is configured, so the panel cannot switch the '
                           . 'tracker. Set it under Tracker mode & whitelist, or switch by hand on the server.'], 409);
}

$out = ['ok' => true, 'changed' => false, 'from' => trackerMode($cfg), 'to' => $mode,
        'error' => null, 'output' => '', 'notes' => [], 'skipped' => null];
if (!scheduleSwitchTo($db, $cfg, $mode, $out)) {
    auditNote(['target_id' => $mode, 'summary' => 'switch to ' . $mode . ' failed']);
    // The setting was NOT flipped: scheduleSwitchTo only writes it after the service really changed.
    jsonResponse(['error' => $out['error'] ?? 'The switch failed.', 'output' => $out['output'],
                  'notes' => $out['notes']], 500);
}
scheduleRecordResult($out);
auditNote(['target_id' => $mode, 'summary' => 'tracker switched to ' . $mode,
           'detail' => ['from' => $out['from'], 'to' => $mode, 'notes' => $out['notes']]]);

$agree = scheduleModeAgreement($cfg, true);
jsonResponse([
    'success' => true,
    'message' => 'The tracker is now in ' . $mode . ' mode'
               . ($agree['known'] ? ' (confirmed with the helper).' : '.')
               . ($out['notes'] ? ' ' . implode(' · ', $out['notes']) : ''),
    'mode' => $mode,
    'notes' => $out['notes'],
    'output' => $out['output'],
    'agreement' => $agree,
]);
