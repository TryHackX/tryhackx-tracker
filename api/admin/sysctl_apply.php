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
    jsonResponse(['error' => 'Unknown operation'], 400);
}
if (!sysctlEnabled($cfg)) {
    jsonResponse(['error' => 'The kernel-buffer helper is not configured or not enabled (Settings → Kernel network buffers).'], 400);
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
        'message' => 'Queued. The janitor puts the captured values back within a minute — and if an '
                   . 'automatic revert was scheduled at arm time, systemd may get there first.',
    ]);
}

$st = sysctlStatus($cfg, $port);
if (empty($st['ok'])) {
    jsonResponse(['error' => $st['error'] ?? 'The helper did not answer.',
                  'output' => mb_substr((string)($st['output'] ?? ''), 0, 600)], 500);
}

if ($op === 'confirm') {
    $armed = sysctlState()['armed'] ?? null;
    if (!is_array($armed)) jsonResponse(['error' => 'Nothing is armed.'], 400);
    $password = (string)($input['password'] ?? '');
    if ($password === '' || ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        jsonResponse(['error' => 'Wrong admin password'], 403);
    }
    // A change that did not fully land must not be made permanent — the file would then describe a
    // machine state that never existed.
    if (empty($armed['all_landed'])) {
        jsonResponse(['error' => 'Not every value actually took effect when this was armed, so making '
                              . 'it permanent would write down something that is not true. Revert and '
                              . 'look at the per-key result first.'], 409);
    }
    sysctlRequest('confirm', ['nonce' => (string)($armed['nonce'] ?? '')]);
    jsonResponse([
        'success' => true, 'queued' => true,
        'message' => 'Queued. The janitor writes /etc/sysctl.d/99-tracker-panel.conf within a minute '
                   . 'and cancels the scheduled undo.',
    ]);
}

/* ── preview and arm both need validated values ──────────────────────────── */

$pairs = sysctlPairsFromInput($input);
if (!$pairs) jsonResponse(['error' => 'No values to apply.'], 400);

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
        $warnings[] = $keys[$k]['label'] . ' is going up more than fourfold in one step (' . $cur
                    . ' to ' . $v . '). Move one thing, watch it, then move the next — a machine that '
                    . 'chokes after several of these changed at once tells you nothing about which.';
    }
}
if ($errors) jsonResponse(['error' => implode(' ', $errors), 'errors' => $errors], 400);

if ($op === 'preview') {
    $r = sysctlRun($cfg, array_merge(['preview'], sysctlPairArgs($pairs)));
    if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'preview failed', 'output' => $r['output']], 500);
    jsonResponse(['success' => true, 'file' => $r['json']['file'] ?? '', 'content' => $r['json']['content'] ?? '',
                  'warnings' => $warnings, 'need_ack' => $needAck]);
}

/* ── arm ─────────────────────────────────────────────────────────────────── */

$password = (string)($input['password'] ?? '');
if ($password === '' || ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
    jsonResponse(['error' => 'Wrong admin password'], 403);
}

// Refusals that are about the machine rather than the numbers.
if (empty($st['netns_shared'])) {
    jsonResponse(['error' => 'This panel is running in a private network namespace, so the change '
                          . 'would apply to a copy of the network stack nothing else can see. '
                          . 'Refusing rather than reporting a success that means nothing.'], 409);
}
if (netlimitAutoEnabled($cfg)) {
    // Processing packets that were previously dropped raises softirq load; the automatic limiter
    // reads load as distress and would ratchet the tracker's own budget down in response to an
    // improvement. Two feedback loops pulling opposite ways is not something to warn about.
    jsonResponse(['error' => 'The automatic inbound limiter is on. It tightens when the machine\'s '
                          . 'load rises, and handling packets you were previously dropping raises '
                          . 'exactly that — it would read this change as distress and cut the '
                          . 'tracker\'s own budget. Turn it off in Settings first.'], 409);
}
$ack = array_map('strval', (array)($input['ack'] ?? []));
foreach ($needAck as $k) {
    if (!in_array($k, $ack, true)) {
        jsonResponse(['error' => 'Raising ' . $keys[$k]['sysctl'] . ' changes the buffer given to every '
                              . 'socket created afterwards, not just the tracker\'s. Tick the '
                              . 'acknowledgement to confirm you meant that one.',
                      'need_ack' => $needAck], 409);
    }
}
if (empty($st['systemd_run']) && empty($input['ack_no_watchdog'])) {
    jsonResponse(['error' => 'systemd-run is not available on this machine, so an automatic undo can '
                          . 'only come from the janitor timer. If that timer is not running, nothing '
                          . 'will put the old values back for you. Confirm you accept that.',
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
    'message' => 'Queued. The janitor applies it within a minute, and schedules an automatic undo '
               . floor($seconds / 60) . ' minute(s) later unless you confirm. Nothing is written to '
               . '/etc until you do, so until then a reboot also undoes it.',
]);
