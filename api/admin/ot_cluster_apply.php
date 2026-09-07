<?php
/**
 * POST admin/ot_cluster_apply — the one endpoint that changes the roster.
 *
 *   {"op":"plan","name":"…","udp":N,"tcp":N}                  — what create would refuse, no password
 *   {"op":"create","name":"…","udp":N,"tcp":N,"affinity":"…","workers":N,"password":"…"}
 *   {"op":"remove","name":"…","password":"…"}
 *   {"op":"restart","name":"…","password":"…"}
 *   {"op":"reload"}                                            — SIGHUP every instance
 *
 * A new instance starts serving announces the moment it exists, so create is password-gated like any
 * other outward-facing change. Removing one is gated too: it is a capacity change, and an operator
 * who meant to remove "edge-b" and typed "edge-a" should be asked once.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['plan', 'create', 'remove', 'restart', 'reload'], true)) {
    jsonResponse(['error' => __('api.admin.unknown_op')], 400);
}
if (!otClusterEnabled($cfg)) {
    jsonResponse(['error' => __('api.cluster.not_enabled')], 400);
}

$name = trim((string)($input['name'] ?? ''));
$udp  = (int)($input['udp'] ?? 0);
$tcp  = (int)($input['tcp'] ?? 0);

if (in_array($op, ['plan', 'create', 'remove', 'restart'], true) && !otClusterValidName($name)) {
    jsonResponse(['error' => __('api.cluster.bad_name')], 400);
}

if ($op === 'plan') {
    $r = otClusterRun($cfg, ['plan', $name, (string)$udp, (string)$tcp]);
    jsonResponse(['success' => $r['ok'], 'result' => $r['json'], 'error' => $r['ok'] ? null : $r['error']]);
}

if ($op === 'reload') {
    $r = otClusterRun($cfg, ['reload', '--all']);
    jsonResponse(['success' => $r['ok'], 'result' => $r['json'],
                  'message' => $r['ok'] ? __('api.cluster.reloaded') : null,
                  'error' => $r['ok'] ? null : $r['error']]);
}

$password = (string)($input['password'] ?? '');
requireAdminReauth($password, $cfg);

if ($op === 'create') {
    // The nftables counters and the automatic limiter only ever see the PRIMARY's port. Add a second
    // instance and most of the packets stop being counted while the load they cause stays -- so the
    // automatic mode reads "over the CPU target" and ratchets the primary's own budget down, again and
    // again, while the traffic chart shows a packet rate saying it should not be. Two feedback loops
    // pulling opposite ways is not something to warn about.
    if (netlimitAutoEnabled($cfg)) {
        jsonResponse(['error' => __('api.cluster.auto_limiter_on')], 409);
    }
    $affinity = trim((string)($input['affinity'] ?? ''));
    if ($affinity !== '' && !otValidAffinity($affinity)) {
        jsonResponse(['error' => __('api.cluster.bad_affinity')], 400);
    }
    $workers = max(0, min(64, (int)($input['workers'] ?? 0)));
    $r = otClusterRun($cfg, ['create', $name, (string)$udp, (string)$tcp, $affinity, (string)$workers]);
    otClusterRoster($cfg, true);
    jsonResponse(['success' => $r['ok'], 'result' => $r['json'], 'error' => $r['ok'] ? null : $r['error'],
                  'output' => $r['ok'] ? null : mb_substr($r['output'], 0, 600),
                  'message' => $r['ok'] ? __('api.cluster.created', ['name' => $name, 'udp' => $udp]) : null]);
}

if ($op === 'remove') {
    $r = otClusterRun($cfg, ['remove', $name]);
    otClusterRoster($cfg, true);
    jsonResponse(['success' => $r['ok'], 'result' => $r['json'], 'error' => $r['ok'] ? null : $r['error'],
                  'message' => $r['ok'] ? __('api.cluster.removed', ['name' => $name]) : null]);
}

$r = otClusterRun($cfg, ['restart', $name]);
otClusterRoster($cfg, true);
jsonResponse(['success' => $r['ok'], 'result' => $r['json'], 'error' => $r['ok'] ? null : $r['error'],
              'message' => $r['ok'] ? __('api.cluster.restarted', ['name' => $name]) : null]);
