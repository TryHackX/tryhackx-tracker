<?php
/**
 * GET admin/dbmem_status — what the database engine runs with, what the drop-in says, and what the
 * counters say about whether any of it is worth touching.
 *
 * Returns enabled:false and forks nothing when the feature is off, so an install that never turned
 * it on never pays for a card it does not have.
 */
if (!dbmemEnabled($cfg)) {
    jsonResponse(['ok' => true, 'enabled' => false, 'configured' => ['cmd_set' => dbmemCommand($cfg) !== '']]);
}
// Read-only from here on: let the session go so a slow helper does not block the admin's other tabs.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$st = dbmemStatus($cfg);
if (empty($st['ok'])) {
    jsonResponse(['ok' => false, 'enabled' => true, 'error' => $st['error'] ?? __('api.dbmem.helper_no_answer'),
                  'output' => mb_substr((string)($st['output'] ?? ''), 0, 600)]);
}
$state = dbmemStateRead();
jsonResponse([
    'ok'          => true,
    'enabled'     => true,
    'server_time' => time(),
    'status'      => $st,
    'pending'     => is_array($state['pending'] ?? null) ? $state['pending'] : null,
    'pending_at'  => (int)($state['pending_at'] ?? 0),
    'last_apply'  => $state['last_apply'] ?? null,
    'last_restart' => $state['last_restart'] ?? null,
    'last_error'  => $state['last_error'] ?? null,
    'restart_pending' => dbmemRestartPending($st),
    'advice'      => dbmemAdvice($st),
    'key_names'   => dbmemKeyNames(),
]);
