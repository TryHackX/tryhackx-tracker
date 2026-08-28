<?php
/**
 * POST admin/livesync_apply — arm or disarm live peer sync.
 *
 *   {"op":"status"} | {"op":"plan"} | {"op":"apply","password":"…"} | {"op":"revert","password":"…"}
 *
 * Arming restarts the tracker, so it asks for the password. Disarming restarts it too and asks for
 * the same reason — but note the asymmetry that matters: if you are ever unsure, disarm. An
 * unauthenticated port left open is worse than a minute of no peer sync.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['status', 'plan', 'apply', 'revert'], true)) {
    jsonResponse(['error' => 'Unknown operation'], 400);
}
if (!livesyncEnabled($cfg)) {
    jsonResponse(['error' => 'Live sync is off. Turn it on in Settings first.'], 409);
}

if ($op === 'status') {
    jsonResponse(['success' => true, 'status' => livesyncStatus($cfg, true),
                  'warnings' => livesyncWarnings($cfg)]);
}

$problems = livesyncValidate($cfg);
if ($problems && $op !== 'revert') {
    jsonResponse(['error' => $problems[0], 'problems' => $problems], 400);
}

if ($op === 'plan') {
    $r = livesyncRun($cfg, ['plan', livesyncBindIp($cfg), (string)livesyncPort($cfg), livesyncPeerIp($cfg)]);
    if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'the helper refused'], 400);
    jsonResponse(['success' => true] + (array)$r['json']);
}

requireAdminReauth((string)($input['password'] ?? ''), $cfg);

if ($op === 'revert') {
    $r = livesyncRun($cfg, ['revert'], 60);
    if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'the helper refused'], 500);
    livesyncStatus($cfg, true);
    jsonResponse(['success' => true, 'message' => 'Live sync is off and opentracker has been restarted '
                                                . 'with its own command line.'] + (array)$r['json']);
}

// apply — the helper verifies the port is actually listening, and on the tunnel address only. If it
// is not, the helper undoes its own change before answering, so a failure here leaves nothing armed.
$r = livesyncRun($cfg, ['apply', livesyncBindIp($cfg), (string)livesyncPort($cfg), livesyncPeerIp($cfg)], 90);
if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'the helper refused'], 500);
livesyncStatus($cfg, true);
jsonResponse(['success' => true,
              'message' => 'Live sync is on: opentracker is listening on the tunnel address and will '
                         . 'exchange live peers with the peer.'] + (array)$r['json']);
