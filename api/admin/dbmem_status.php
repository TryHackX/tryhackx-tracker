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
/**
 * How much there is to keep in memory in the first place.
 *
 * The card was all about the SIZE OF THE POOL and said nothing about the size of what the pool is
 * for, so "is 1.5 GiB enough?" had no answer on the screen — the operator had to open a MySQL client
 * to find out that the catalogue alone is bigger than the pool. Three numbers: the whole database,
 * and the two tables that dominate it. `index_hashes` and `index_files` are named literally rather
 * than listed, because those two are the reason this card exists.
 *
 * information_schema is an estimate for InnoDB (it reads the tablespace, not a row count), and it is
 * the same estimate the backup card already shows, so the two agree. Wrapped in its own try: a
 * database that will not answer a size question is not a reason to lose the whole card.
 */
$sizes = ['db' => null, 'tables' => []];
try {
    $sizes['db'] = (int)$db->query("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES
                                     WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
    $st2 = $db->query("SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, TABLE_ROWS
                         FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('index_hashes', 'index_files')");
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sizes['tables'][(string)$r['TABLE_NAME']] = [
            'data'  => (int)$r['DATA_LENGTH'],
            'index' => (int)$r['INDEX_LENGTH'],
            'total' => (int)$r['DATA_LENGTH'] + (int)$r['INDEX_LENGTH'],
            'rows'  => $r['TABLE_ROWS'] === null ? null : (int)$r['TABLE_ROWS'],
        ];
    }
} catch (\Throwable $e) { $sizes = ['db' => null, 'tables' => []]; }

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
    'sizes'       => $sizes,
]);
