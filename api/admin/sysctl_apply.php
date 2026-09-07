<?php
/**
 * POST admin/sysctl_apply — the only endpoint that changes a kernel setting.
 *
 *   {"op":"preview","values":{...}}                    — render the file, touch nothing, no password
 *   {"op":"arm","values":{...},"password":"…","ack":[…]} — queue the change; the janitor applies it
 *   {"op":"confirm","password":"…"}                    — make the armed change survive a reboot
 *   {"op":"revert"}                                    — put the captured values back, no password
 *
 * Nothing here writes anything. php-fpm on this class of machine runs with ProtectKernelTunables=yes,
 * which makes /proc/sys read-only inside its mount namespace — for root as well, because it is a
 * namespace and not a permission bit. So the endpoint validates, records what was asked for, and the
 * janitor (an ordinary unit with no sandbox) performs it within a minute. That is not a workaround:
 * it means the process that will undo the change is the same one that made it.
 *
 * The password gates are where they are for a reason. `arm` is gated because it changes the machine.
 * `confirm` is gated too, even though it changes nothing that is not already running, because it
 * converts a change whose complete undo is a reboot into one that survives a reboot — it destroys
 * the escape hatch, and it sits next to Revert on a page the admin is reading while under pressure.
 * `revert` is deliberately NOT gated: restoring the state the machine had before the panel touched it
 * is always the least harmful thing available, and demanding a password over a session that is
 * already stuttering is the exact failure the whole armed protocol exists to prevent.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['preview', 'arm', 'confirm', 'revert'], true)) {
    jsonResponse(['error' => __('api.admin.unknown_op')], 400);
}
if (!sysctlEnabled($cfg)) {
    jsonResponse(['error' => __('api.sysctl.not_enabled')], 400);
}

/** key => value, in the kernel's own units. Anything not in the allow-list is dropped here. */
function sysctlPairsFromInput(array $input): array {
    $out = [];
    $keys = sysctlKeys();
    foreach ((array)($input['values'] ?? []) as $k => $v) {
        $k = (string)$k;
        if (!isset($keys[$k])) continue;
        $v = trim((string)$v);
        if ($v === '') continue;                       // absent = the panel does not manage this key
        $out[$k] = preg_replace('/\s+/', ' ', $v);
    }
    return $out;
}

/** The helper takes one argument per key; udp_mem's three numbers travel joined by underscores. */
function sysctlPairArgs(array $pairs): array {
    $args = [];
    foreach ($pairs as $k => $v) $args[] = $k . '=' . str_replace(' ', '_', $v);
    return $args;
}

$port = netlimitPort($cfg);

if ($op === 'revert') {
    // No password, on purpose. See the block comment above.
    sysctlRequest('revert');
    jsonResponse([
        'success' => true,
        'queued'  => true,
        'message' => __('api.sysctl.revert_queued'),
    ]);
}

$st = sysctlStatus($cfg, $port);
if (empty($st['ok'])) {
    jsonResponse(['error' => $st['error'] ?? __('api.sysctl.helper_no_answer'),
                  'output' => mb_substr((string)($st['output'] ?? ''), 0, 600)], 500);
}

if ($op === 'confirm') {
    $armed = sysctlState()['armed'] ?? null;
    if (!is_array($armed)) jsonResponse(['error' => __('api.sysctl.nothing_armed')], 400);
    $password = (string)($input['password'] ?? '');
    requireAdminReauth($password, $cfg);
    // A change that did not fully land must not be made permanent — the file would then describe a
    // machine state that never existed.
    if (empty($armed['all_landed'])) {
        jsonResponse(['error' => __('api.sysctl.not_all_landed')], 409);
    }
    sysctlRequest('confirm', ['nonce' => (string)($armed['nonce'] ?? '')]);
    jsonResponse([
        'success' => true, 'queued' => true,
        'message' => __('api.sysctl.confirm_queued'),
    ]);
}

/* ── preview and arm both need validated values ──────────────────────────── */

$pairs = sysctlPairsFromInput($input);
if (!$pairs) jsonResponse(['error' => __('api.sysctl.no_values')], 400);

$errors = [];
$warnings = [];
$needAck = [];
$keys = sysctlKeys();
$current = (array)($st['values'] ?? []);
foreach ($pairs as $k => $v) {
    $err = sysctlValidate($k, $v, $st);
    if ($err !== '') { $errors[] = $err; continue; }
    $cur = (string)($current[$k] ?? '');
    if ($cur !== '' && preg_replace('/\s+/', ' ', $cur) === $v) continue;  // unchanged
    if (!empty($keys[$k]['ack']) && (int)$v > (int)$cur) $needAck[] = $k;
    if (sysctlBigStep($k, $v, $cur)) {
        $warnings[] = __('api.sysctl.big_step', ['label' => $keys[$k]['label'], 'cur' => $cur, 'new' => $v]);
    }
}
if ($errors) jsonResponse(['error' => implode(' ', $errors), 'errors' => $errors], 400);

if ($op === 'preview') {
    $r = sysctlRun($cfg, array_merge(['preview'], sysctlPairArgs($pairs)));
    if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.sysctl.preview_failed'), 'output' => $r['output']], 500);
    jsonResponse(['success' => true, 'file' => $r['json']['file'] ?? '', 'content' => $r['json']['content'] ?? '',
                  'warnings' => $warnings, 'need_ack' => $needAck]);
}

/* ── arm ─────────────────────────────────────────────────────────────────── */

$password = (string)($input['password'] ?? '');
requireAdminReauth($password, $cfg);

// Refusals that are about the machine rather than the numbers.
if (empty($st['netns_shared'])) {
    jsonResponse(['error' => __('api.sysctl.private_netns')], 409);
}
if (netlimitAutoEnabled($cfg)) {
    // Processing packets that were previously dropped raises softirq load; the automatic limiter
    // reads load as distress and would ratchet the tracker's own budget down in response to an
    // improvement. Two feedback loops pulling opposite ways is not something to warn about.
    jsonResponse(['error' => __('api.sysctl.auto_limiter_on')], 409);
}
$ack = array_map('strval', (array)($input['ack'] ?? []));
foreach ($needAck as $k) {
    if (!in_array($k, $ack, true)) {
        jsonResponse(['error' => __('api.sysctl.need_ack', ['sysctl' => $keys[$k]['sysctl']]),
                      'need_ack' => $needAck], 409);
    }
}
if (empty($st['systemd_run']) && empty($input['ack_no_watchdog'])) {
    jsonResponse(['error' => __('api.sysctl.no_systemd_run'),
                  'need_ack_no_watchdog' => true], 409);
}

$seconds = sysctlConfirmSeconds($cfg);
$nonce = bin2hex(random_bytes(8));
sysctlRequest('arm', ['nonce' => $nonce, 'seconds' => $seconds, 'pairs' => sysctlPairArgs($pairs)]);

jsonResponse([
    'success' => true,
    'queued'  => true,
    'nonce'   => $nonce,
    'seconds' => $seconds,
    'warnings' => $warnings,
    'message' => __('api.sysctl.arm_queued', ['minutes' => floor($seconds / 60)]),
]);
