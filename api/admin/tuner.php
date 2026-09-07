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
    jsonResponse(['error' => __('api.admin.unknown_op')], 400);
}

if ($op === 'status') {
    auditSuppress();   // a poll is not an event
    jsonResponse(['success' => true] + tunerStatus($cfg));
}

requireAdminReauth((string)($input['password'] ?? ''), $cfg);

if ($op === 'cancel') {
    $r = tunerCancel();
    auditNote(['summary' => 'stability probe cancelled']);
    jsonResponse(['success' => true, 'message' => __('api.tuner.cancel_requested')] + tunerStatus($cfg));
}

if ($op === 'apply') {
    $pps = (int)($input['pps'] ?? 0);
    if ($pps < 1000 || $pps > 5000000) jsonResponse(['error' => __('api.tuner.unusable_limit')], 400);
    $st = tunerStatus($cfg);
    // Only a value the run actually reached. Applying a number nobody measured would make the report
    // decorative — the whole point is that this figure was held and watched.
    $tried = array_map(fn($s) => (int)($s['limit_pps'] ?? 0), (array)($st['steps'] ?? []));
    if (!in_array($pps, $tried, true)) {
        jsonResponse(['error' => __('api.tuner.not_a_step')], 400);
    }
    // Through the same accessors the Traffic page uses, so the clamps apply and the port is the one
    // actually configured. `tracker_port` was never a setting: this always applied to 6969, and on a
    // tracker running elsewhere the report's "apply" button would have moved the wrong port's limit
    // while answering that it had set the limit.
    // A run that could not tell its own steps apart measured nothing, so there is no measured value
    // to apply — the report withholds the suggestion and this refuses the number even if it is asked
    // for directly.
    if (!empty($st['report']['inconclusive'])) {
        jsonResponse(['error' => __('api.tuner.inconclusive')], 409);
    }

    // WHICH LIMIT THE RUN WAS MOVING.
    //
    // An outbound run's steps are anchored on the REPLY BUDGET, not on the receive limit — so writing
    // one of its values through netlimitApply() would take a number measured about what the tracker
    // sends and impose it on what it is allowed to receive, while the button says "the inbound
    // firewall limit". `both` moves two limits at once and there is no single honest thing to apply.
    $what = (string)($st['what'] ?? 'inbound');
    if ($what === 'both') {
        jsonResponse(['error' => __('api.tuner.both_limits')], 400);
    }
    if ($what === 'outbound') {
        $r = netlimitEgress($cfg, $pps, false);
        auditNote(['target_id' => (string)$pps, 'summary' => 'applied ' . $pps . ' pps egress from a stability probe']);
        jsonResponse(['success' => !empty($r['ok']), 'message' => !empty($r['ok'])
            ? __('api.tuner.reply_budget_set', ['pps' => number_format($pps)])
            : ($r['error'] ?? __('api.tuner.helper_refused'))] + tunerStatus($cfg));
    }
    $r = netlimitApply($cfg, $pps, netlimitBurst($cfg), netlimitPort($cfg));
    auditNote(['target_id' => (string)$pps, 'summary' => 'applied ' . $pps . ' pps from a stability probe']);
    jsonResponse(['success' => !empty($r['ok']), 'message' => !empty($r['ok'])
        ? __('api.tuner.inbound_set', ['pps' => number_format($pps)])
        : ($r['error'] ?? __('api.tuner.helper_refused'))] + tunerStatus($cfg));
}

// ── start ───────────────────────────────────────────────────────────────────
if (!tunerEnabled($cfg)) {
    jsonResponse(['error' => __('api.tuner.disabled')], 409);
}
$r = tunerRequest([
    'steps'   => (int)($input['steps'] ?? 6),
    'dwell'   => (int)($input['dwell'] ?? 180),
    'dry_run' => !empty($input['dry_run']),
    'what'    => (string)($input['what'] ?? 'inbound'),
]);
if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 409);

auditNote(['summary' => 'stability probe requested (' . (int)($input['steps'] ?? 6) . ' steps, '
                      . (int)($input['dwell'] ?? 180) . 's each)']);
jsonResponse(['success' => true,
    'message' => __('api.tuner.requested')] + tunerStatus($cfg));
