<?php
/**
 * POST admin/dbmem_apply — the only endpoint that changes a database-engine setting.
 *
 *   {"op":"apply","values":{"innodb_buffer_pool_size":1610612736,...},"password":"…"}
 *   {"op":"restart","password":"…","ack":true}
 *
 * Both are gated by the owner's password (no permission id: an endpoint without a map entry in
 * api.php is the owner's), because both touch a database that is shared with every other service
 * on the machine. `apply` changes what the engine can change live and writes the drop-in; it never
 * restarts anything. `restart` is its own request with its own acknowledgement, so a change that
 * needs one is a decision the operator makes twice, in words.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['apply', 'restart'], true)) jsonResponse(['error' => __('api.admin.unknown_op')], 400);
if (!dbmemEnabled($cfg)) jsonResponse(['error' => __('api.dbmem.not_enabled')], 400);
requireAdminReauth((string)($input['password'] ?? ''), $cfg);

if ($op === 'restart') {
    if (empty($input['ack'])) jsonResponse(['error' => __('api.dbmem.restart_ack')], 400);
    // the helper bounds systemctl at 300 s and its own wait at 90 s; this request may outlive a
    // default max_execution_time on a platform that counts wall time
    @set_time_limit(420);
    $r = dbmemRestart($cfg);
    if (function_exists('auditNote')) auditNote('database service restarted from the panel');
    if (!$r['ok']) jsonResponse(['error' => $r['error'], 'output' => mb_substr((string)$r['output'], 0, 600)], 500);
    jsonResponse(['ok' => true, 'result' => $r['json']]);
}

// apply: validate against what the helper reports for THIS engine, then hand it the pairs
$st = dbmemStatus($cfg);
if (empty($st['ok'])) jsonResponse(['error' => $st['error'] ?? __('api.dbmem.helper_no_answer')], 500);
$pairs = [];
foreach ((array)($input['values'] ?? []) as $k => $v) {
    $k = (string)$k;
    if (!in_array($k, dbmemKeyNames(), true)) continue;
    if ($v === '' || $v === null) continue;                    // absent = the panel does not manage this key
    $err = dbmemValidate($k, is_int($v) ? $v : (string)$v, $st);
    if ($err !== '') jsonResponse(['error' => $err, 'key' => $k], 400);
    $pairs[$k] = (int)$v;
}
if (!$pairs) jsonResponse(['error' => __('api.dbmem.nothing_to_apply')], 400);

$r = dbmemApply($cfg, $pairs);
$summary = [];
foreach ($pairs as $k => $v) $summary[] = $k . '=' . $v;
if (function_exists('auditNote')) auditNote('database memory: ' . implode(', ', $summary) . (!empty($r['json']['deferred']) ? ' (drop-in deferred to the janitor)' : ''));
if (!$r['ok']) jsonResponse(['error' => $r['error'], 'output' => mb_substr((string)$r['output'], 0, 600)], 500);
jsonResponse(['ok' => true, 'result' => $r['json'], 'restart_pending' => dbmemRestartPending(array_merge($st, ['vars' => $r['json']['vars'] ?? $st['vars'] ?? [], 'file_values' => $pairs]))]);
