<?php
/**
 * POST — the only endpoint that changes the firewall.
 *
 * Auth + CSRF are enforced by the router (admin/*, non-GET). Like admin/restart_tracker this
 * additionally requires the admin password, because it runs a privileged system command; a
 * `--dry-run` preview is the one action that does not (it renders and syntax-checks a ruleset and
 * touches nothing).
 *
 * Body: {"op": "apply"|"off"|"panic"|"restore"|"egress"|"preview",
 *        "pps": int, "burst": int, "port": int, "minutes": int, "password": "..."}
 *
 * Every numeric argument is clamped in includes/netlimit.php and validated again by the helper, and
 * the helper never accepts a free-form command — see tools/opentracker/tracker-netlimit.sh.
 */

requirePost();

$input = readJsonBody();
$op    = strtolower(trim((string)($input['op'] ?? 'apply')));
$known = ['apply', 'monitor', 'off', 'panic', 'restore', 'egress', 'preview'];
if (!in_array($op, $known, true)) {
    jsonResponse(['error' => __('api.admin.unknown_op_list', ['ops' => implode(', ', $known)])], 400);
}

if (netlimitCommand($cfg) === '') {
    jsonResponse(['error' => __('api.net.no_helper')], 400);
}
if (!trackerExecAvailable()) {
    jsonResponse(['error' => __('api.net.exec_disabled')], 500);
}

// Preview is read-only (the helper's --dry-run never touches nftables), so it stays password-free:
// an admin has to be able to look at the ruleset before deciding to type a password.
if ($op !== 'preview') {
    $password = (string)($input['password'] ?? '');
    requireAdminReauth($password, $cfg);
}

$pps   = isset($input['pps'])   ? (int)$input['pps']   : netlimitPps($cfg);
$burst = isset($input['burst']) ? (int)$input['burst'] : netlimitBurst($cfg);
$port  = isset($input['port'])  ? (int)$input['port']  : netlimitPort($cfg);

switch ($op) {
    case 'preview':
        $r = netlimitApply($cfg, $pps, $burst, $port, true, 'preview');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.net.preview_failed'), 'output' => $r['output']], 400);
        jsonResponse(['success' => true, 'dry_run' => true, 'ruleset' => (string)($r['json']['ruleset'] ?? ''),
                      'pps' => (int)($r['json']['pps'] ?? $pps), 'burst' => (int)($r['json']['burst'] ?? $burst),
                      'port' => (int)($r['json']['port'] ?? $port), 'file' => (string)($r['json']['file'] ?? '')]);

    case 'monitor':
        // Counters with no drop rule: the chain accepts by default and contains no `drop`, so it
        // cannot discard a packet. It is what makes "measure first" possible at all.
        $r = netlimitApplyMonitor($cfg, $port, false, 'admin');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.net.monitor_failed'), 'output' => $r['output']], 500);
        setSettings($db, ['net_monitor_enabled' => '1', 'net_limit_enabled' => '0',
                          'net_limit_port' => (string)netlimitClampInt($port, 1, 65535, 6969)]);
        jsonResponse(['success' => true, 'mode' => 'count', 'applied' => $r['json'],
                      'persistent' => !empty($r['json']['persistent']),
                      'message' => __('api.net.counting_started', ['port' => (int)($r['json']['port'] ?? $port)])]);

    case 'apply':
        $r = netlimitApply($cfg, $pps, $burst, $port, false, 'admin');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.net.apply_failed'), 'output' => $r['output']], 500);
        // remember what is in force so the janitor and the next page load agree with the firewall
        setSettings($db, ['net_limit_enabled' => '1', 'net_limit_pps' => (string)netlimitClampInt($pps, NET_PPS_MIN, NET_PPS_MAX, 30000),
                          'net_limit_burst' => (string)netlimitClampInt($burst, NET_BURST_MIN, NET_BURST_MAX, 100),
                          'net_limit_port' => (string)netlimitClampInt($port, 1, 65535, 6969)]);
        jsonResponse(['success' => true, 'message' => __('api.net.inbound_set', ['pps' => number_format((int)($r['json']['pps'] ?? $pps))]),
                      'applied' => $r['json'], 'persistent' => !empty($r['json']['persistent'])]);

    case 'off':
        $r = netlimitOff($cfg, false, 'admin');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.net.off_failed'), 'output' => $r['output']], 500);
        setSettings($db, ['net_limit_enabled' => '0', 'net_auto_enabled' => '0']);
        jsonResponse(['success' => true, 'message' => __('api.net.inbound_removed'), 'applied' => $r['json']]);

    case 'panic':
        $minutes = isset($input['minutes']) ? (int)$input['minutes'] : NET_PANIC_MINUTES;
        $panicPps = isset($input['pps']) ? (int)$input['pps'] : NET_PANIC_PPS;
        $r = netlimitPanic($db, $cfg, $minutes, $panicPps);
        if (empty($r['ok'])) jsonResponse(['error' => $r['error'] ?? __('api.net.panic_failed'), 'output' => $r['output'] ?? ''], 500);
        jsonResponse(['success' => true, 'until' => (int)($r['until'] ?? 0), 'restore_pps' => (int)($r['restore_pps'] ?? 0),
                      'message' => __('api.net.panic_set', ['pps' => number_format(netlimitClampInt($panicPps, NET_PPS_MIN, NET_PPS_MAX, NET_PANIC_PPS)),
                                                              'minutes' => max(1, min(240, $minutes))])]);

    case 'restore':
        $r = netlimitPanicRestore($db, $cfg);
        if (empty($r['ok'])) jsonResponse(['error' => $r['error'] ?? __('api.net.restore_failed')], 500);
        if (!$r['restored']) jsonResponse(['success' => true, 'message' => __('api.net.no_panic')]);
        jsonResponse(['success' => true, 'message' => $r['enabled']
            ? __('api.net.previous_restored', ['pps' => number_format((int)$r['pps'])])
            : __('api.net.panic_lifted')]);

    case 'egress':
        $r = netlimitEgress($cfg, $pps, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.net.egress_failed'), 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'message' => __('api.net.egress_set', ['pps' => number_format((int)($r['json']['pps'] ?? $pps))]),
                      'file_updated' => !empty($r['json']['file_updated']), 'applied' => $r['json']]);
}
