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
    jsonResponse(['error' => 'Unknown operation. Use one of: ' . implode(', ', $known) . '.'], 400);
}

if (backupCommand($cfg) === '') {
    jsonResponse(['error' => 'No backup helper command is configured. Set it in Settings → Backups first.'], 400);
}
if (!trackerExecAvailable()) {
    jsonResponse(['error' => 'PHP exec() is disabled on this server — the panel cannot reach the backup helper.'], 500);
}

$password = (string)($input['password'] ?? '');
requireAdminReauth($password, $cfg);

$id     = trim((string)($input['id'] ?? ''));
$dryRun = !empty($input['dry_run']);
if (in_array($op, ['verify', 'delete', 'restore', 'restore-db', 'token'], true)) {
    if (!backupValidId($id)) jsonResponse(['error' => 'That is not an archive this panel made.'], 400);
}

switch ($op) {
    case 'run':
        $profile = trim((string)($input['profile'] ?? ''));
        if ($profile !== '' && !in_array($profile, BACKUP_PROFILES, true)) {
            jsonResponse(['error' => 'Unknown backup profile.'], 400);
        }
        $r = backupStart($cfg, $profile, 'admin');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not start the backup.', 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'id' => (string)($r['json']['id'] ?? ''), 'mode' => (string)($r['json']['mode'] ?? ''),
                      'profile' => $r['profile'], 'items' => (string)($r['json']['items'] ?? ''),
                      'message' => 'Backup started — it runs on the server, so you can leave this page.']);

    case 'cancel':
        $r = backupCancel($cfg);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not cancel the backup.', 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'message' => 'The running backup was stopped.']);

    case 'verify':
        $r = backupVerify($cfg, $id, !empty($input['deep']));
        // a failed verification is a real answer, not a server error — hand back what it said
        jsonResponse(['success' => (bool)$r['ok'], 'id' => $id, 'deep' => !empty($input['deep']),
                      'message' => (string)($r['json']['message'] ?? ($r['error'] ?? 'The check did not answer.')),
                      'error' => $r['ok'] ? null : (string)($r['json']['message'] ?? $r['error'])]);

    case 'prune':
        $r = backupPrune($cfg, $dryRun);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Rotation failed.', 'output' => $r['output']], 500);
        $removed = (array)($r['json']['removed'] ?? []);
        jsonResponse(['success' => true, 'dry_run' => $dryRun, 'removed' => $removed,
                      'message' => $dryRun
                          ? ($removed ? 'Rotation would remove ' . count($removed) . ' archive(s).' : 'Rotation would remove nothing — everything is within the limits.')
                          : ($removed ? 'Removed ' . count($removed) . ' archive(s).' : 'Nothing to remove — everything is within the limits.')]);

    case 'delete':
        $r = backupDelete($cfg, $id);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not delete the archive.', 'output' => $r['output']], 500);
        jsonResponse(['success' => true, 'deleted' => $id, 'message' => 'Archive deleted.']);

    case 'restore':
        $items = backupSanitizeItems((string)($input['items'] ?? ''));
        if ($items === '') jsonResponse(['error' => 'Pick at least one item to restore — this never restores everything by accident.'], 400);
        // the database is not restored here; that is its own action with its own confirmation
        $fileItems = implode(',', array_filter(explode(',', $items), fn($i) => !str_ends_with($i, '-db') && !str_ends_with($i, '-db-lekka')));
        if ($fileItems === '') {
            jsonResponse(['error' => 'Only database items were selected. Restoring a database is a separate action — it asks you to type the database name and dumps the current one first.'], 400);
        }
        $r = backupRestore($cfg, $id, $fileItems, $dryRun);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Restore failed.', 'output' => (string)($r['json']['output'] ?? $r['output'])], 500);
        jsonResponse(['success' => true, 'id' => $id, 'items' => $fileItems, 'dry_run' => $dryRun,
                      'output' => (string)($r['json']['output'] ?? ''),
                      'message' => $dryRun ? 'Dry run finished — nothing on this server was changed.'
                                           : 'Restored: ' . $fileItems . '. Every file that was overwritten has a .bak-<stamp> copy next to it.']);

    case 'restore-db':
        $db      = trim((string)($input['db'] ?? ''));
        $confirm = trim((string)($input['confirm'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) jsonResponse(['error' => 'Invalid database name.'], 400);
        if ($db !== $confirm) {
            jsonResponse(['error' => 'The name you typed does not match "' . $db . '". Nothing was touched.'], 400);
        }
        $r = backupRestoreDb($cfg, $id, $db, $confirm, $dryRun);
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'The database restore failed.', 'output' => $r['output']], 500);
        if ($dryRun) {
            jsonResponse(['success' => true, 'dry_run' => true, 'db' => $db,
                          'dump_bytes' => (int)($r['json']['dump_bytes'] ?? 0),
                          'message' => (string)($r['json']['message'] ?? 'Dry run finished — nothing was changed.')]);
        }
        jsonResponse(['success' => true, 'db' => $db, 'safety_dump' => (string)($r['json']['safety_dump'] ?? ''),
                      'message' => 'Database "' . $db . '" restored. The database as it was a minute ago is saved next to the archives.']);

    case 'token':
        // Single use, five minutes, bound to this one archive. The GET endpoint burns it.
        $secret = (string)($cfg['hmac_secret'] ?? '');
        if ($secret === '') jsonResponse(['error' => 'The site has no HMAC secret configured, so a download link cannot be signed. Set one in Settings → Contact & Email.'], 500);
        $token = backupMintToken($id, $secret);
        jsonResponse(['success' => true, 'id' => $id, 'token' => $token, 'expires_in' => BACKUP_TOKEN_TTL,
                      'url' => getBaseUrl() . 'api.php?endpoint=admin/backup_download&id=' . rawurlencode($id) . '&token=' . rawurlencode($token)]);
}
