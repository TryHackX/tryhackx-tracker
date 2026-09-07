<?php
/**
 * POST — everything that changes something: start a backup, cancel one, verify, prune, delete,
 * restore, restore the database, and mint a download link.
 *
 * Auth + CSRF are enforced by the router (admin/*, non-GET). Every operation here additionally
 * requires the admin password, for one reason each:
 *   · run / prune / delete  — they consume or destroy backups;
 *   · restore / restore-db  — they overwrite live files and live data;
 *   · token                 — an archive contains every database password on this machine, so
 *                             handing out a link to one is as sensitive as any of the above;
 *   · verify                — read-only, but it is a minute of disk I/O on a live box.
 *
 * Restoring the DATABASE additionally requires typing the exact database name (`confirm`). That is
 * the same guard Backup-serwera.sh enforces at a terminal, and the helper repeats it: it never
 * imports anything without first dumping the database it is about to overwrite.
 *
 * Body: {"op": "...", "id": "...", "items": "...", "db": "...", "confirm": "...",
 *        "profile": "...", "deep": bool, "dry_run": bool, "password": "..."}
 */

requirePost();

$input = readJsonBody();
$op    = strtolower(trim((string)($input['op'] ?? '')));
$known = ['run', 'cancel', 'verify', 'prune', 'delete', 'restore', 'restore-db', 'token'];
if (!in_array($op, $known, true)) {
    jsonResponse(['error' => __('api.admin.unknown_op_list', ['ops' => implode(', ', $known)])], 400);
}

if (backupCommand($cfg) === '') {
    jsonResponse(['error' => __('api.backup.no_helper')], 400);
}
if (!trackerExecAvailable()) {
    jsonResponse(['error' => __('api.backup.exec_disabled')], 500);
}

$password = (string)($input['password'] ?? '');
requireAdminReauth($password, $cfg);

$id     = trim((string)($input['id'] ?? ''));
$dryRun = !empty($input['dry_run']);
if (in_array($op, ['verify', 'delete', 'restore', 'restore-db', 'token'], true)) {
    if (!backupValidId($id)) jsonResponse(['error' => __('api.backup.not_our_archive')], 400);
}

switch ($op) {
    case 'run':
        $profile = trim((string)($input['profile'] ?? ''));
        if ($profile !== '' && !in_array($profile, BACKUP_PROFILES, true)) {
            jsonResponse(['error' => __('api.backup.unknown_profile')], 400);
        }
        $r = backupStart($cfg, $profile, 'admin');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.backup.start_failed'), 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'id' => (string)($r['json']['id'] ?? ''), 'mode' => (string)($r['json']['mode'] ?? ''),
                      'profile' => $r['profile'], 'items' => (string)($r['json']['items'] ?? ''),
                      'message' => __('api.backup.started')]);

    case 'cancel':
        $r = backupCancel($cfg);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.backup.cancel_failed'), 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'message' => __('api.backup.cancelled')]);

    case 'verify':
        $r = backupVerify($cfg, $id, !empty($input['deep']));
        // a failed verification is a real answer, not a server error — hand back what it said
        jsonResponse(['success' => (bool)$r['ok'], 'id' => $id, 'deep' => !empty($input['deep']),
                      'message' => (string)($r['json']['message'] ?? ($r['error'] ?? __('api.backup.no_answer'))),
                      'error' => $r['ok'] ? null : (string)($r['json']['message'] ?? $r['error'])]);

    case 'prune':
        $r = backupPrune($cfg, $dryRun);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.backup.rotation_failed'), 'output' => $r['output']], 500);
        $removed = (array)($r['json']['removed'] ?? []);
        jsonResponse(['success' => true, 'dry_run' => $dryRun, 'removed' => $removed,
                      'message' => $dryRun
                          ? ($removed ? __('api.backup.prune_dry_some', ['n' => count($removed)]) : __('api.backup.prune_dry_none'))
                          : ($removed ? __('api.backup.prune_some', ['n' => count($removed)]) : __('api.backup.prune_none'))]);

    case 'delete':
        $r = backupDelete($cfg, $id);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.backup.delete_failed'), 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'deleted' => $id, 'message' => __('api.backup.deleted')]);

    case 'restore':
        $items = backupSanitizeItems((string)($input['items'] ?? ''));
        if ($items === '') jsonResponse(['error' => __('api.backup.pick_item')], 400);
        // the database is not restored here; that is its own action with its own confirmation
        $fileItems = implode(',', array_filter(explode(',', $items), fn($i) => !str_ends_with($i, '-db') && !str_ends_with($i, '-db-lekka')));
        if ($fileItems === '') {
            jsonResponse(['error' => __('api.backup.db_only')], 400);
        }
        $r = backupRestore($cfg, $id, $fileItems, $dryRun);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.backup.restore_failed'), 'output' => (string)($r['json']['output'] ?? $r['output'])], 500);
        jsonResponse(['success' => true, 'id' => $id, 'items' => $fileItems, 'dry_run' => $dryRun,
                      'output' => (string)($r['json']['output'] ?? ''),
                      'message' => $dryRun ? __('api.backup.dry_run_done')
                                           : __('api.backup.restored', ['items' => $fileItems])]);

    case 'restore-db':
        // $dbName, NOT $db: this file runs at include scope, where $db IS the router's PDO handle.
        // Assigning the database name to it meant auditFinish()'s `$db instanceof PDO` guard
        // failed — and the panel's most destructive operation was the one never written to the
        // audit log.
        $dbName  = trim((string)($input['db'] ?? ''));
        $confirm = trim((string)($input['confirm'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) jsonResponse(['error' => __('api.backup.invalid_db_name')], 400);
        if ($dbName !== $confirm) {
            jsonResponse(['error' => __('api.backup.confirm_mismatch', ['db' => $dbName])], 400);
        }
        $r = backupRestoreDb($cfg, $id, $dbName, $confirm, $dryRun);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? __('api.backup.db_restore_failed'), 'output' => $r['output']], 500);
        if ($dryRun) {
            jsonResponse(['success' => true, 'dry_run' => true, 'db' => $db,
                          'dump_bytes' => (int)($r['json']['dump_bytes'] ?? 0),
                          'message' => (string)($r['json']['message'] ?? __('api.backup.db_dry_run_done'))]);
        }
        jsonResponse(['success' => true, 'db' => $db, 'safety_dump' => (string)($r['json']['safety_dump'] ?? ''),
                      'message' => __('api.backup.db_restored', ['db' => $dbName])]);

    case 'token':
        // Single use, five minutes, bound to this one archive. The GET endpoint burns it.
        $secret = (string)($cfg['hmac_secret'] ?? '');
        if ($secret === '') jsonResponse(['error' => __('api.backup.no_hmac')], 500);
        $token = backupMintToken($id, $secret);
        jsonResponse(['success' => true, 'id' => $id, 'token' => $token, 'expires_in' => BACKUP_TOKEN_TTL,
                      'url' => getBaseUrl() . 'api.php?endpoint=admin/backup_download&id=' . rawurlencode($id) . '&token=' . rawurlencode($token)]);
}
