<?php
/**
 * Database / configuration backups, driven from the admin panel.
 *
 * This backs up THE TRACKER, and by default that means its database. Where `Backup-serwera.sh` (the
 * server toolkit that lives outside this repo) is installed, this module *steers that* rather than
 * duplicating it — it already has granular items, a MANIFEST and SUMY.sha256, and it knows about
 * every service on the box — through one narrow root helper, `tools/opentracker/tracker-backup.sh`:
 *
 *     panel (PHP)  →  sudo -n /usr/local/sbin/tracker-backup.sh <action> …
 *                        →  Backup-serwera.sh --backup --items … --out …
 *                        →  /var/backups/tracker/backup-tryhackx-<stamp>.tar.gz  (+ .meta.json)
 *
 * Nothing heavy ever runs inside a web request: `run` starts the real work detached (systemd-run
 * when available, a background job otherwise) and returns immediately. Progress is a JSON state file
 * the helper writes and the panel polls, exactly like the whitelist status card.
 *
 * Where the toolkit is absent the module dumps the tracker database with mariadb-dump instead. That
 * is a legitimate scope, not a degraded one: backing up a whole machine is a different job for a
 * different tool. The UI states what an archive covers and does not nag about what it is not.
 *
 * Two guards worth knowing about, because they shape the UI:
 *   · Backup-serwera.sh refuses to overwrite a database unless somebody types its name at a
 *     terminal. That is right, and we do not fake a terminal for it: restoring the database is its
 *     own action, gated on the admin password AND on typing the exact database name in the panel,
 *     and the helper dumps the current database before importing anything.
 *   · its encryption is `gpg --symmetric` with an interactive passphrase, which cannot work without
 *     a terminal (the tool detects that and silently skips encrypting). So the panel always passes
 *     --no-gpg and, when `backup_gpg_recipient` is set, the helper encrypts with a public key
 *     instead — no passphrase, non-interactive by construction.
 *
 * Settings (schema v11, Settings → "Backups", group `maintenance`): backup_enabled, backup_dir,
 * backup_profile, backup_items, backup_schedule, backup_schedule_tz, backup_keep, backup_keep_days,
 * backup_max_size_gb, backup_gpg_recipient, backup_nice, backup_verify_after, backup_cmd,
 * backup_script_path, backup_db_name. Everything off by default.
 */

const BACKUP_DEFAULT_DIR   = '/var/backups/tracker';
const BACKUP_DEFAULT_CMD   = 'sudo -n /usr/local/sbin/tracker-backup.sh';
const BACKUP_DEFAULT_SCRIPT = '/usr/local/sbin/Backup-serwera.sh';
const BACKUP_KEEP_MAX      = 365;
const BACKUP_DAYS_MAX      = 3650;
const BACKUP_GB_MAX        = 10000;
const BACKUP_TOKEN_TTL     = 300;     // seconds a download link stays valid
const BACKUP_STATUS_TTL    = 2;       // seconds the helper's status output is reused while polling
const BACKUP_PROFILES      = ['tracker-lekki', 'tracker-pelny', 'tracker-baza', 'tracker-baza-lekka', 'custom'];
/**
 * The profiles the ROOT HELPER knows by name. Anything else the panel offers is sent as `custom`
 * with an explicit --items list, which the helper already supports — so a new profile is a change to
 * this file alone and never a reinstall of a root script.
 */
const BACKUP_HELPER_PROFILES = ['tracker-lekki', 'tracker-pelny', 'tracker-baza', 'custom'];

// ─────────────────────────────────────────────────────────────────────────────
// Settings (pure)
// ─────────────────────────────────────────────────────────────────────────────

function backupEnabled(array $cfg): bool { return (($cfg['backup_enabled'] ?? '0') === '1'); }
function backupVerifyAfter(array $cfg): bool { return (($cfg['backup_verify_after'] ?? '1') === '1'); }

function backupClampInt($v, int $min, int $max, int $default): int {
    $n = is_numeric($v) ? (int)$v : $default;
    return max($min, min($max, $n));
}

/** The backup directory: absolute, no trailing slash, no control characters. */
function backupDir(array $cfg): string {
    $d = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string)($cfg['backup_dir'] ?? '')));
    if ($d === '') $d = BACKUP_DEFAULT_DIR;
    return $d === '/' ? '/' : rtrim($d, '/');
}

function backupProfile(array $cfg): string {
    $p = strtolower(trim((string)($cfg['backup_profile'] ?? '')));
    return in_array($p, BACKUP_PROFILES, true) ? $p : 'tracker-lekki';
}

function backupKeep(array $cfg): int      { return backupClampInt($cfg['backup_keep'] ?? null, 0, BACKUP_KEEP_MAX, 7); }
function backupKeepDays(array $cfg): int  { return backupClampInt($cfg['backup_keep_days'] ?? null, 0, BACKUP_DAYS_MAX, 30); }
function backupMaxGb(array $cfg): int     { return backupClampInt($cfg['backup_max_size_gb'] ?? null, 0, BACKUP_GB_MAX, 20); }
function backupNice(array $cfg): int      { return backupClampInt($cfg['backup_nice'] ?? null, 0, 19, 15); }
function backupTimezone(array $cfg): string {
    $tz = trim((string)($cfg['backup_schedule_tz'] ?? ''));
    return ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) ? $tz : 'Europe/Warsaw';
}

/** The database that gets dumped without the toolkit, and that a restore targets. */
function backupDbName(array $cfg): string {
    $db = trim((string)($cfg['backup_db_name'] ?? ''));
    return preg_match('/^[A-Za-z0-9_]{1,64}$/', $db) ? $db : 'tracker';
}

function backupGpgRecipient(array $cfg): string {
    $r = trim((string)($cfg['backup_gpg_recipient'] ?? ''));
    return preg_match('/^[A-Za-z0-9@._+-]{1,128}$/', $r) ? $r : '';
}

/** Same rule as the other root helpers: no shell metacharacters, arguments appended separately. */
function backupValidCommand(string $cmd): bool {
    return $cmd === '' || preg_match('#^[A-Za-z0-9 _./-]{1,255}$#', $cmd) === 1;
}
function backupCommand(array $cfg): string {
    $cmd = trim((string)($cfg['backup_cmd'] ?? BACKUP_DEFAULT_CMD));
    return backupValidCommand($cmd) ? $cmd : '';
}
function backupScriptPath(array $cfg): string {
    $p = trim((string)($cfg['backup_script_path'] ?? ''));
    if ($p === '') return '';
    return preg_match('#^/[A-Za-z0-9 _./-]{1,255}$#', $p) ? $p : '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Items and profiles (pure)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The item vocabulary of Backup-serwera.sh v1.0 (its POZYCJE table). Kept here as an allow-list so
 * a typo in the custom item box is dropped with a visible result instead of being handed to the
 * tool. A new item in the toolkit needs one line here — the panel also offers whatever the helper
 * reports at runtime, this list is what validation falls back to.
 */
function backupKnownItems(): array {
    return [
        'flarum-db', 'flarum-config', 'flarum-ext', 'flarum-lokalne', 'flarum-assets', 'flarum-storage', 'flarum-timer',
        'mail-db', 'mail-postfix', 'mail-dovecot', 'mail-dkim', 'mail-roundcube', 'mail-panel', 'mail-skrzynki',
        'mail-fail2ban', 'mail-logrotate',
        'files-commit', 'files-db', 'files-config', 'files-data', 'files-uploads', 'files-system',
        'tracker-db', 'tracker-db-lekka', 'tracker-config', 'tracker-listy', 'tracker-opentracker',
        'tracker-binarki', 'tracker-worker', 'tracker-janitor', 'tracker-siec', 'tracker-sudoers',
        'dayz-config', 'dayz-misja', 'dayz-skrypt', 'dayz-creds', 'dayz-profile',
        'soldat-config', 'soldat-mapy', 'soldat-watchdog',
        'qbit-config', 'qbit-torrenty', 'qbit-systemd',
        'tor-tozsamosc', 'tor-config',
        'www-vhosty', 'certy', 'php-config', 'phpmyadmin',
    ];
}

/** The tracker's own items, in the order the UI shows them. */
function backupTrackerItems(): array {
    return ['tracker-db', 'tracker-db-lekka', 'tracker-config', 'tracker-listy', 'tracker-opentracker',
            'tracker-binarki', 'tracker-worker', 'tracker-janitor', 'tracker-siec', 'tracker-sudoers'];
}

/** Free text → a clean, deduplicated, known-only comma list. Anything else is dropped. */
function backupSanitizeItems(string $raw): string {
    $known = backupKnownItems();
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [] as $tok) {
        if ($tok === '' || !in_array($tok, $known, true) || in_array($tok, $out, true)) continue;
        $out[] = $tok;
    }
    return implode(',', $out);
}

/**
 * The items a profile asks for. MIRRORS profile_items() in tools/opentracker/tracker-backup.sh —
 * tests/backup_test.php compares the two so they cannot drift apart.
 */
function backupProfileItems(string $profile, array $cfg): string {
    switch ($profile) {
        case 'tracker-pelny':
            return 'tracker-db,tracker-config,tracker-listy,tracker-opentracker,tracker-worker,tracker-janitor,tracker-siec,tracker-sudoers';
        case 'tracker-baza':
            return 'tracker-db';
        // The combination that was missing: the database on its own, WITHOUT the two tables that are
        // most of its size. The other three cover full+everything, full-db-only and light+everything.
        case 'tracker-baza-lekka':
            return 'tracker-db-lekka';
        case 'custom':
            $items = backupSanitizeItems((string)($cfg['backup_items'] ?? ''));
            if ($items !== '') return $items;
            // a custom profile with nothing selected would back up nothing at all
            return backupProfileItems('tracker-lekki', $cfg);
        case 'tracker-lekki':
        default:
            return 'tracker-db-lekka,tracker-config,tracker-listy,tracker-opentracker,tracker-worker,tracker-janitor,tracker-siec,tracker-sudoers';
    }
}

/** Human label for a profile, for the UI and the log. */
function backupProfileLabel(string $profile): string {
    // The old labels described the wrong axis. "Database only" was in fact the FULL database
    // including index_hashes and index_files — several GB — and nothing in its name said so, while
    // "Full" sounded like the bigger of the two when it is the same database plus some small files.
    // These say which database and what else, because those are the two questions.
    return [
        'tracker-lekki'      => 'Light — config and lists, database without the two huge index tables',
        'tracker-pelny'      => 'Everything — full database (several GB) plus config, lists and units',
        'tracker-baza'       => 'Full database only — every table, including the index (several GB)',
        'tracker-baza-lekka' => 'Light database only — without index_hashes and index_files',
        'custom'             => 'Custom selection',
    ][$profile] ?? $profile;
}

// ─────────────────────────────────────────────────────────────────────────────
// Paths and ids (pure)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The panel's own check on the backup directory; the helper repeats it independently. Archives hold
 * every database password on the box, so a path anywhere the web server could serve it is refused
 * outright, as is a system directory that is not a place for 0600 archives.
 * Returns ['ok'=>bool,'error'=>?string,'hint'=>?string].
 */
function backupValidateDir(string $path): array {
    $p = preg_replace('/[\x00-\x1F\x7F]/', '', trim($path));
    $bad = fn(string $e, string $h = '') => ['ok' => false, 'error' => $e, 'hint' => $h];
    if ($p === '') return $bad('The backup directory is not set.', 'Something like ' . BACKUP_DEFAULT_DIR . ' — outside the web root, on a filesystem with room.');
    if ($p[0] !== '/') return $bad('The backup directory must be an absolute path.', 'It starts with a "/", e.g. ' . BACKUP_DEFAULT_DIR . '.');
    if (str_contains($p, '..')) return $bad('The backup directory must not contain "..".');
    $p = rtrim($p, '/');
    if ($p === '') return $bad('Refusing to use the filesystem root as the backup directory.', 'Give the archives a directory of their own, e.g. ' . BACKUP_DEFAULT_DIR . '.');

    $refused = ['/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib64', '/proc', '/root', '/run',
                '/sbin', '/srv', '/sys', '/usr', '/var', '/tmp'];
    if (in_array($p, $refused, true)) {
        return $bad('Refusing to use ' . $p . ' as the backup directory.', 'Give the archives a directory of their own, e.g. ' . BACKUP_DEFAULT_DIR . '.');
    }
    foreach (['/var/www/', '/srv/www/', '/usr/share/nginx/'] as $web) {
        if (str_starts_with($p . '/', $web)) {
            return $bad('The backup directory must be OUTSIDE the web root — an archive contains every database password on this machine.',
                        'Use something like ' . BACKUP_DEFAULT_DIR . '.');
        }
    }
    // …and outside this application, wherever it happens to be installed
    $appRoot = @realpath(__DIR__ . '/..');
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $docRoot = $docRoot !== '' ? (@realpath($docRoot) ?: '') : '';
    foreach (array_filter([$appRoot, $docRoot]) as $r) {
        $r = rtrim(str_replace('\\', '/', (string)$r), '/');
        $cmp = str_replace('\\', '/', $p);
        if ($r !== '' && ($cmp === $r || str_starts_with($cmp . '/', $r . '/'))) {
            return $bad('The backup directory must be outside the application directory (' . $r . ') — it is served over HTTP.',
                        'Use something like ' . BACKUP_DEFAULT_DIR . '.');
        }
    }
    return ['ok' => true, 'error' => null, 'hint' => null];
}

/** Archive ids are file basenames we produced — never a path, never someone else's file. */
function backupValidId(string $id): bool {
    if ($id === '' || strlen($id) > 128) return false;
    return (bool)preg_match('/^(backup-tryhackx|tracker-db)-\d{8}-\d{6}$/', $id);
}

function backupFormatBytes(int $b): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)max(0, $b);
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    $s = number_format($v, $i === 0 ? 0 : 1, '.', '');
    if (str_ends_with($s, '.0')) $s = substr($s, 0, -2);
    return $s . ' ' . $units[$i];
}

// ─────────────────────────────────────────────────────────────────────────────
// The schedule (pure)
// ─────────────────────────────────────────────────────────────────────────────
//
// A backup is a moment, not a window like the tracker-mode schedule: pick weekdays and one time of
// day. The janitor runs every minute, so "due" has to mean "this slot has not fired yet" rather
// than "it is exactly that minute" — otherwise a busy minute or a machine that was off would skip
// the whole week silently.

const BACKUP_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const BACKUP_DAY_LABELS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

/** {"days":["mon","wed"],"time":"04:00"} → ['days'=>[...], 'minutes'=>int], or null when unusable. */
function backupParseSchedule(?string $json): ?array {
    $raw = json_decode((string)$json, true);
    if (!is_array($raw)) return null;
    $days = $raw['days'] ?? null;
    if (!is_array($days) || !$days) return null;
    $out = [];
    foreach ($days as $d) {
        if (!is_string($d)) return null;
        $d = strtolower(trim($d));
        if (!in_array($d, BACKUP_DAYS, true)) return null;
        if (!in_array($d, $out, true)) $out[] = $d;
    }
    usort($out, fn($a, $b) => array_search($a, BACKUP_DAYS, true) <=> array_search($b, BACKUP_DAYS, true));
    $time = (string)($raw['time'] ?? '');
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) return null;
    $h = (int)$m[1]; $mi = (int)$m[2];
    if ($h > 23 || $mi > 59) return null;
    return ['days' => $out, 'minutes' => $h * 60 + $mi];
}

function backupTz(string $tz): DateTimeZone {
    try { return new DateTimeZone($tz); } catch (\Throwable $e) { return new DateTimeZone('UTC'); }
}

/** The timestamp of the scheduled moment on the local day $now falls in, or null if not a plan day. */
function backupSlotFor(array $plan, string $tz, int $now): ?int {
    $zone = backupTz($tz);
    $local = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
    $day = strtolower($local->format('D'));
    $day = ['mon' => 'mon', 'tue' => 'tue', 'wed' => 'wed', 'thu' => 'thu', 'fri' => 'fri', 'sat' => 'sat', 'sun' => 'sun'][$day] ?? null;
    if ($day === null || !in_array($day, $plan['days'], true)) return null;
    return $local->setTime(intdiv($plan['minutes'], 60), $plan['minutes'] % 60, 0)->getTimestamp();
}

/**
 * Is a scheduled backup due? True once the slot has passed on a configured day and the last run was
 * before that slot. A run that was missed while the machine was off still fires later the same day,
 * but never bleeds into the next one.
 */
function backupScheduleDue(?string $json, string $tz, int $lastRunAt, ?int $now = null): bool {
    $now = $now ?? time();
    $plan = backupParseSchedule($json);
    if ($plan === null) return false;
    $slot = backupSlotFor($plan, $tz, $now);
    if ($slot === null) return false;
    return $now >= $slot && $lastRunAt < $slot;
}

/** The next scheduled moment strictly after $now, or null when nothing is scheduled. */
function backupScheduleNext(?string $json, string $tz, ?int $now = null): ?int {
    $now = $now ?? time();
    $plan = backupParseSchedule($json);
    if ($plan === null) return null;
    $zone = backupTz($tz);
    $local = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
    for ($i = 0; $i <= 7; $i++) {
        $day = $local->modify(sprintf('%+d days', $i));
        $name = strtolower($day->format('D'));
        if (!in_array($name, $plan['days'], true)) continue;
        $slot = $day->setTime(intdiv($plan['minutes'], 60), $plan['minutes'] % 60, 0)->getTimestamp();
        if ($slot > $now) return $slot;
    }
    return null;
}

/** "Mon, Wed, Fri at 04:00 (Europe/Warsaw)" — or a sentence saying nothing is scheduled. */
function backupScheduleDescribe(?string $json, string $tz): string {
    $plan = backupParseSchedule($json);
    if ($plan === null) return 'No automatic backups — the schedule is empty.';
    $days = implode(', ', array_map(fn($d) => BACKUP_DAY_LABELS[$d], $plan['days']));
    if (count($plan['days']) === 7) $days = 'Every day';
    return sprintf('%s at %02d:%02d (%s)', $days, intdiv($plan['minutes'], 60), $plan['minutes'] % 60, $tz);
}

// ─────────────────────────────────────────────────────────────────────────────
// Download tokens (pure)
// ─────────────────────────────────────────────────────────────────────────────
//
// An archive holds every password on the machine, so the download URL must not be a plain id: the
// token is bound to one archive, expires in BACKUP_TOKEN_TTL seconds and is signed with the site's
// HMAC secret. The endpoint additionally burns it in the session, so a link that leaks from a proxy
// log or a shoulder cannot be replayed even inside its lifetime.

function backupMintToken(string $id, string $secret, ?int $now = null): string {
    $now = $now ?? time();
    $exp = $now + BACKUP_TOKEN_TTL;
    return $exp . '.' . hash_hmac('sha256', $id . '|' . $exp, $secret);
}

function backupVerifyToken(string $token, string $id, string $secret, ?int $now = null): bool {
    $now = $now ?? time();
    if ($token === '' || !str_contains($token, '.')) return false;
    [$exp, $sig] = explode('.', $token, 2);
    if (!ctype_digit($exp) || (int)$exp <= $now) return false;
    return hash_equals(hash_hmac('sha256', $id . '|' . $exp, $secret), $sig);
}

// ─────────────────────────────────────────────────────────────────────────────
// Panel-side state (config/backup_state.json) — bookkeeping only
// ─────────────────────────────────────────────────────────────────────────────
//
// The authoritative run state is the helper's own file inside the backup directory (root, 0600);
// this one records what the PANEL knows: when the schedule last fired, the last error we showed and
// which download tokens have been burned.

function backupStateFile(): string     { return __DIR__ . '/../config/backup_state.json'; }
function backupStateLockFile(): string { return __DIR__ . '/../config/backup_state.lock'; }

function backupStateDefaults(): array {
    return [
        'last_run_at' => 0, 'last_run_id' => '', 'last_run_source' => '', 'last_run_result' => null,
        'last_schedule_at' => 0, 'last_error' => null, 'last_error_at' => 0,
        'status' => null, 'status_at' => 0, 'used_tokens' => [],
    ];
}

function backupStateRead(): array {
    $f = backupStateFile();
    $data = [];
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return array_replace(backupStateDefaults(), is_array($data) ? $data : []);
}

function backupStateUpdate(callable $fn): array {
    $lockH = @fopen(backupStateLockFile(), 'c');
    if ($lockH) @flock($lockH, LOCK_EX);
    try {
        $state = backupStateRead();
        $r = $fn($state);
        if ($r !== false) {
            $tmp = backupStateFile() . '.tmp.' . getmypid();
            @file_put_contents($tmp, json_encode($state), LOCK_EX);
            @rename($tmp, backupStateFile());
        }
        return $state;
    } finally {
        if ($lockH) { @flock($lockH, LOCK_UN); @fclose($lockH); }
    }
}

/** Burn a download token so the same link cannot be used twice. Returns false when already used. */
function backupBurnToken(string $token, ?int $now = null): bool {
    $now = $now ?? time();
    $ok = false;
    backupStateUpdate(function (array &$s) use ($token, $now, &$ok) {
        $used = [];
        foreach ((array)($s['used_tokens'] ?? []) as $t => $at) {
            if ((int)$at > $now - BACKUP_TOKEN_TTL * 2) $used[$t] = (int)$at;   // prune the expired ones
        }
        if (isset($used[$token])) { $s['used_tokens'] = $used; $ok = false; return true; }
        $used[$token] = $now;
        $s['used_tokens'] = $used;
        $ok = true;
        return true;
    });
    return $ok;
}

// ─────────────────────────────────────────────────────────────────────────────
// Talking to the helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run one helper action. Same shape and the same JSON-recovery trick as netlimitRun():
 * stderr is merged in (a sudoers failure prints nothing else) and the reply is the last single-line
 * JSON object in the output.
 */
function backupRun(array $cfg, array $args, bool $raw = false): array {
    $out = ['ok' => false, 'json' => null, 'output' => '', 'code' => null, 'error' => null];
    $cmd = backupCommand($cfg);
    if ($cmd === '') { $out['error'] = 'No backup helper command is configured (Settings → Backups).'; return $out; }
    if (!trackerExecAvailable()) { $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the backup helper.'; return $out; }

    $script = backupScriptPath($cfg);
    $full = $cmd;
    if ($script !== '') $full .= ' --script ' . escapeshellarg($script);
    foreach ($args as $a) $full .= ' ' . escapeshellarg((string)$a);
    $full .= ' 2>&1';

    $lines = []; $rc = null;
    @exec($full, $lines, $rc);
    $out['code'] = $rc === null ? null : (int)$rc;
    $out['output'] = trim(implode("\n", $lines));
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $l = trim((string)$lines[$i]);
        if ($l === '' || $l[0] !== '{') continue;
        $j = json_decode($l, true);
        if (is_array($j)) { $out['json'] = $j; break; }
    }
    if ($out['json'] === null) {
        $out['error'] = $out['output'] !== ''
            ? 'The helper did not answer with JSON: ' . mb_substr($out['output'], 0, 300)
            : 'The helper produced no output (exit ' . (int)$rc . '). Check the sudoers rule.';
        return $out;
    }
    $out['ok'] = !empty($out['json']['ok']) && $out['code'] === 0;
    if (!$out['ok'] && $out['error'] === null) {
        $out['error'] = (string)($out['json']['error'] ?? ('Helper exited with code ' . (int)$rc));
    }
    return $out;
}

function backupCheck(array $cfg): array {
    $r = backupRun($cfg, ['check', backupDir($cfg)]);
    return is_array($r['json']) ? $r['json'] + ['output' => $r['output']] : ['ok' => false, 'error' => $r['error'], 'output' => $r['output']];
}

function backupTestPath(array $cfg, string $dir): array {
    // the panel's own opinion first — it knows where the application lives, the helper does not
    $own = backupValidateDir($dir);
    if (!$own['ok']) {
        return ['ok' => false, 'path' => $dir, 'errors' => [$own['error']], 'suggestions' => array_filter([$own['hint']]), 'local' => true];
    }
    $r = backupRun($cfg, ['test-path', $dir]);
    if (!is_array($r['json'])) return ['ok' => false, 'path' => $dir, 'errors' => [$r['error'] ?? 'The helper did not answer.'], 'suggestions' => [], 'output' => $r['output']];
    return $r['json'];
}

function backupList(array $cfg): array {
    $r = backupRun($cfg, ['list', backupDir($cfg)]);
    return is_array($r['json']) ? $r['json'] : ['ok' => false, 'error' => $r['error'], 'archives' => [], 'output' => $r['output']];
}

/** The run state. Cached for a couple of seconds so a polling card does not fork per request. */
function backupStatus(array $cfg, bool $fresh = false, ?int $now = null): array {
    $now = $now ?? time();
    $state = backupStateRead();
    if (!$fresh && is_array($state['status']) && ($now - (int)$state['status_at']) < BACKUP_STATUS_TTL) {
        return $state['status'] + ['cached' => true];
    }
    $r = backupRun($cfg, ['status', backupDir($cfg)]);
    if (!is_array($r['json'])) {
        return ['state' => 'unknown', 'running' => false, 'error' => $r['error'], 'output' => $r['output'], 'cached' => false];
    }
    $st = $r['json'];
    backupStateUpdate(function (array &$s) use ($st, $now) { $s['status'] = $st; $s['status_at'] = $now; return true; });
    return $st + ['cached' => false];
}

/** Start a backup. Returns the helper's reply; the work continues after this call returns. */
function backupStart(array $cfg, string $profile = '', string $source = 'admin'): array {
    $profile = $profile !== '' ? $profile : backupProfile($cfg);
    if (!in_array($profile, BACKUP_PROFILES, true)) $profile = backupProfile($cfg);
    $dir = backupDir($cfg);
    $args = ['run', $dir, $profile,
             '--nice', (string)backupNice($cfg),
             '--keep', (string)backupKeep($cfg),
             '--keep-days', (string)backupKeepDays($cfg),
             '--max-gb', (string)backupMaxGb($cfg),
             '--db', backupDbName($cfg)];
    // A profile the helper does not know is sent as `custom` plus the items it stands for. The helper
    // validates its own profile names and rejects anything else, so translating here is what keeps a
    // new profile from needing a root script reinstalled on every machine.
    if (!in_array($profile, BACKUP_HELPER_PROFILES, true)) {
        $args[2] = 'custom';
        $args[] = '--items';
        $args[] = backupProfileItems($profile, $cfg);
    } elseif ($profile === 'custom') {
        $args[] = '--items';
        $args[] = backupProfileItems('custom', $cfg);
    }
    if (backupVerifyAfter($cfg)) $args[] = '--verify';
    $rec = backupGpgRecipient($cfg);
    if ($rec !== '') { $args[] = '--gpg-recipient'; $args[] = $rec; }

    $r = backupRun($cfg, $args);
    backupStateUpdate(function (array &$s) use ($r, $source, $profile) {
        $s['status'] = null; $s['status_at'] = 0;
        if ($r['ok']) {
            $s['last_run_at'] = time();
            $s['last_run_id'] = (string)($r['json']['id'] ?? '');
            $s['last_run_source'] = $source;
            $s['last_run_result'] = 'started';
            $s['last_error'] = null;
        } else {
            $s['last_error'] = $r['error'] ?? 'start failed';
            $s['last_error_at'] = time();
        }
        return true;
    });
    $r['profile'] = $profile;
    return $r;
}

function backupCancel(array $cfg): array   { return backupRun($cfg, ['cancel', backupDir($cfg)]); }
function backupDelete(array $cfg, string $id): array { return backupRun($cfg, ['delete', backupDir($cfg), $id]); }
function backupVerify(array $cfg, string $id, bool $deep = false): array {
    $args = ['verify', backupDir($cfg), $id];
    if ($deep) $args[] = '--deep';
    return backupRun($cfg, $args);
}
function backupPrune(array $cfg, bool $dryRun = false): array {
    $args = ['prune', backupDir($cfg), (string)backupKeep($cfg), (string)backupKeepDays($cfg), (string)backupMaxGb($cfg)];
    if ($dryRun) $args[] = '--dry-run';
    return backupRun($cfg, $args);
}
function backupRestore(array $cfg, string $id, string $items, bool $dryRun = false): array {
    $args = ['restore', backupDir($cfg), $id, '--items', $items];
    if ($dryRun) $args[] = '--dry-run';
    return backupRun($cfg, $args);
}
function backupRestoreDb(array $cfg, string $id, string $db, string $confirm, bool $dryRun = false): array {
    $args = ['restore-db', backupDir($cfg), $id, '--db', $db, '--confirm', $confirm];
    if ($dryRun) $args[] = '--dry-run';
    return backupRun($cfg, $args);
}

/**
 * Stream one archive to the browser. The bytes never pass through a PHP variable: the helper writes
 * them to a pipe and we copy the pipe to the output in 256 KB chunks, so a 4 GB archive costs the
 * same memory as a 4 KB one. Returns false when the process could not be started.
 */
function backupStream(array $cfg, string $id): bool {
    $cmd = backupCommand($cfg);
    if ($cmd === '' || !function_exists('proc_open')) return false;
    $script = backupScriptPath($cfg);
    $full = $cmd . ($script !== '' ? ' --script ' . escapeshellarg($script) : '')
          . ' cat ' . escapeshellarg(backupDir($cfg)) . ' ' . escapeshellarg($id);
    $pipes = [];
    $proc = @proc_open($full, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return false;
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 262144);
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        flush();
    }
    fclose($pipes[1]);
    @stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// The janitor tick
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fire a scheduled backup when one is due. Completely inert (no fork at all) while backups are off
 * or nothing is scheduled — the same rule as every other optional subsystem here. Never throws.
 */
function backupTick(PDO $db, array $cfg, ?int $now = null): array {
    $now = $now ?? time();
    $out = ['enabled' => false, 'started' => false, 'id' => null, 'error' => null, 'skipped' => null];
    if (!backupEnabled($cfg)) return $out;
    $plan = (string)($cfg['backup_schedule'] ?? '');
    if (backupParseSchedule($plan) === null) { $out['skipped'] = 'no schedule'; return $out; }
    $out['enabled'] = true;
    try {
        $state = backupStateRead();
        if (!backupScheduleDue($plan, backupTimezone($cfg), (int)$state['last_schedule_at'], $now)) {
            $out['skipped'] = 'not due';
            return $out;
        }
        // Claim the slot BEFORE starting: the janitor runs every minute and a slow start would
        // otherwise let the next tick fire a second backup into the same slot.
        backupStateUpdate(function (array &$s) use ($now) { $s['last_schedule_at'] = $now; return true; });
        $r = backupStart($cfg, backupProfile($cfg), 'schedule');
        $out['started'] = (bool)$r['ok'];
        $out['id'] = $r['json']['id'] ?? null;
        if (!$r['ok']) $out['error'] = $r['error'];
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        error_log('[backup] ' . $e->getMessage());
    }
    return $out;
}
