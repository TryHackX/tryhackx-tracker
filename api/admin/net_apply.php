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
$known = ['apply', 'off', 'panic', 'restore', 'egress', 'preview'];
if (!in_array($op, $known, true)) {
    jsonResponse(['error' => 'Unknown operation. Use one of: ' . implode(', ', $known) . '.'], 400);
}

if (netlimitCommand($cfg) === '') {
    jsonResponse(['error' => 'No rate-limit helper command is configured. Set it in Settings → UDP traffic & rate limit first.'], 400);
}
if (!trackerExecAvailable()) {
    jsonResponse(['error' => 'PHP exec() is disabled on this server — the panel cannot reach the firewall helper.'], 500);
}

// Preview is read-only (the helper's --dry-run never touches nftables), so it stays password-free:
// an admin has to be able to look at the ruleset before deciding to type a password.
if ($op !== 'preview') {
    $password = (string)($input['password'] ?? '');
    if ($password === '' || ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        jsonResponse(['error' => 'Incorrect password.'], 403);
    }
}

$pps   = isset($input['pps'])   ? (int)$input['pps']   : netlimitPps($cfg);
$burst = isset($input['burst']) ? (int)$input['burst'] : netlimitBurst($cfg);
$port  = isset($input['port'])  ? (int)$input['port']  : netlimitPort($cfg);

switch ($op) {
    case 'preview':
        $r = netlimitApply($cfg, $pps, $burst, $port, true, 'preview');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Preview failed.', 'output' => $r['output']], 400);
        jsonResponse(['success' => true, 'dry_run' => true, 'ruleset' => (string)($r['json']['ruleset'] ?? ''),
                      'pps' => (int)($r['json']['pps'] ?? $pps), 'burst' => (int)($r['json']['burst'] ?? $burst),
                      'port' => (int)($r['json']['port'] ?? $port), 'file' => (string)($r['json']['file'] ?? '')]);

    case 'apply':
        $r = netlimitApply($cfg, $pps, $burst, $port, false, 'admin');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not apply the limit.', 'output' => $r['output']], 500);
        // remember what is in force so the janitor and the next page load agree with the firewall
        setSettings($db, ['net_limit_enabled' => '1', 'net_limit_pps' => (string)netlimitClampInt($pps, NET_PPS_MIN, NET_PPS_MAX, 30000),
                          'net_limit_burst' => (string)netlimitClampInt($burst, NET_BURST_MIN, NET_BURST_MAX, 100),
                          'net_limit_port' => (string)netlimitClampInt($port, 1, 65535, 6969)]);
        jsonResponse(['success' => true, 'message' => 'Inbound limit set to ' . number_format((int)($r['json']['pps'] ?? $pps)) . ' packets/second.',
                      'applied' => $r['json'], 'persistent' => !empty($r['json']['persistent'])]);

    case 'off':
        $r = netlimitOff($cfg, false, 'admin');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not remove the limit.', 'output' => $r['output']], 500);
        setSettings($db, ['net_limit_enabled' => '0', 'net_auto_enabled' => '0']);
        jsonResponse(['success' => true, 'message' => 'Inbound limit removed — the tracker port is no longer throttled by the panel.', 'applied' => $r['json']]);

    case 'panic':
        $minutes = isset($input['minutes']) ? (int)$input['minutes'] : NET_PANIC_MINUTES;
        $panicPps = isset($input['pps']) ? (int)$input['pps'] : NET_PANIC_PPS;
        $r = netlimitPanic($db, $cfg, $minutes, $panicPps);
        if (empty($r['ok'])) jsonResponse(['error' => $r['error'] ?? 'Could not apply the emergency limit.', 'output' => $r['output'] ?? ''], 500);
        jsonResponse(['success' => true, 'until' => (int)($r['until'] ?? 0), 'restore_pps' => (int)($r['restore_pps'] ?? 0),
                      'message' => 'Throttled hard to ' . number_format(netlimitClampInt($panicPps, NET_PPS_MIN, NET_PPS_MAX, NET_PANIC_PPS))
                                   . ' pps. The janitor puts the previous setting back in ' . max(1, min(240, $minutes)) . ' minutes.']);

    case 'restore':
        $r = netlimitPanicRestore($db, $cfg);
        if (empty($r['ok'])) jsonResponse(['error' => $r['error'] ?? 'Could not restore the previous limit.'], 500);
        if (!$r['restored']) jsonResponse(['success' => true, 'message' => 'There was no emergency limit to undo.']);
        jsonResponse(['success' => true, 'message' => $r['enabled']
            ? 'Previous limit restored (' . number_format((int)$r['pps']) . ' pps).'
            : 'Emergency limit lifted — the port is unthrottled again, as it was before.']);

    case 'egress':
        $r = netlimitEgress($cfg, $pps, false);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not change the egress budget.', 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'message' => 'Egress budget set to ' . number_format((int)($r['json']['pps'] ?? $pps)) . ' packets/second.',
                      'file_updated' => !empty($r['json']['file_updated']), 'applied' => $r['json']]);
}
