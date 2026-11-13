<?php
/**
 * POST — the "Test" button for the backup directory, in the same shape as
 * admin/check_whitelist_path: ok / errors / suggestions, plus what the directory actually looks
 * like on disk (mode, owner, free space, how many archives are already there).
 *
 * Auth + CSRF are enforced by the router. Read-only: it creates nothing and changes nothing.
 *
 * Two opinions are combined, because they know different things: the panel knows where this
 * application is installed (so it can refuse a directory the web server would serve), the helper
 * knows what root can see (so it can report the mode, the owner and the free space).
 */

requirePost();

$input = readJsonBody();
$dir = trim((string)($input['backup_dir'] ?? ''));
if ($dir === '') $dir = backupDir($cfg);
$dir = str_replace(["\r", "\n", "\0"], '', $dir);

$result = backupTestPath($cfg, $dir);
$result['path'] = $result['path'] ?? $dir;
$result['os'] = PHP_OS_FAMILY;
$result['php_user'] = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
    : get_current_user();

// The tooling verdict belongs next to the path verdict: a writable directory is no use without a
// dump client, and the admin should learn both from one click.
if (empty($result['local'])) {
    $check = backupCheck($cfg);
    $result['check'] = $check;
    if (empty($check['script']) && empty($check['mariadb_dump'])) {
        $result['ok'] = false;
        $result['errors'][] = 'Neither Backup-serwera.sh nor a MariaDB dump client was found — there is nothing here that could make a backup.';
        $result['suggestions'][] = 'sudo apt install mariadb-client   (built-in mode: the tracker database only)';
        $result['suggestions'][] = 'or install the server toolkit: sudo install -m 0755 Backup-serwera.sh /usr/local/sbin/';
    } elseif (empty($check['script'])) {
        // Whole-server backups are a different job for a different tool. State the scope; do not nag.
        $result['suggestions'][] = 'This will back up the tracker database. Backup-serwera.sh is not installed here, so the configuration files, lists and units are not included — if you put the toolkit at ' . (string)($check['script_path'] ?? '') . ', this page will use it and offer its items too.';
    }
    if (empty($check['gpg']) && backupGpgRecipient($cfg) !== '') {
        $result['suggestions'][] = 'A GPG recipient is configured but gpg is not installed — archives would be written unencrypted.';
    }
}

jsonResponse($result);
