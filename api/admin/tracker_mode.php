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
if (!in_array($op, ['status', 'switch'], true)) jsonResponse(['error' => __('api.index.unknown_op')], 400);

if ($op === 'status') {
    // fresh: the whole point of pressing Test is not to be told what we already believed
    $agree = scheduleModeAgreement($cfg, true);
    $msg = $agree['known']
        ? ($agree['match']
            ? __('api.schedule.mode_matches', ['actual' => $agree['actual']])
            : __('api.schedule.mode_mismatch', ['panel' => $agree['panel'], 'actual' => $agree['actual']]))
        : __('api.schedule.mode_unreadable', ['error' => $agree['error'] ?? '']);
    jsonResponse(['success' => $agree['known'], 'message' => $msg] + $agree);
}

// ── switching ───────────────────────────────────────────────────────────────
$mode = (string)($input['mode'] ?? '');
if (!in_array($mode, ['whitelist', 'blacklist'], true)) {
    jsonResponse(['error' => __('api.schedule.mode_invalid')], 400);
}
requireAdminReauth((string)($input['password'] ?? ''), $cfg);

if (scheduleSwitchCommand($cfg) === '') {
    jsonResponse(['error' => __('api.schedule.no_switch_cmd_switch')], 409);
}

$out = ['ok' => true, 'changed' => false, 'from' => trackerMode($cfg), 'to' => $mode,
        'error' => null, 'output' => '', 'notes' => [], 'skipped' => null];
if (!scheduleSwitchTo($db, $cfg, $mode, $out)) {
    auditNote(['target_id' => $mode, 'summary' => 'switch to ' . $mode . ' failed']);
    // The setting was NOT flipped: scheduleSwitchTo only writes it after the service really changed.
    jsonResponse(['error' => $out['error'] ?? __('api.schedule.switch_failed_short'), 'output' => $out['output'],
                  'notes' => $out['notes']], 500);
}
scheduleRecordResult($out);
auditNote(['target_id' => $mode, 'summary' => 'tracker switched to ' . $mode,
           'detail' => ['from' => $out['from'], 'to' => $mode, 'notes' => $out['notes']]]);

$agree = scheduleModeAgreement($cfg, true);
jsonResponse([
    'success' => true,
    'message' => ($agree['known'] ? __('api.schedule.now_in_mode_confirmed', ['mode' => $mode]) : __('api.schedule.now_in_mode', ['mode' => $mode]))
               . ($out['notes'] ? ' ' . implode(' · ', $out['notes']) : ''),
    'mode' => $mode,
    'notes' => $out['notes'],
    'output' => $out['output'],
    'agreement' => $agree,
]);
