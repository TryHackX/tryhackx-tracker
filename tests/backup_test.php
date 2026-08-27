<?php
/**
 * Tests for the panel-driven backups:
 *   php tests/backup_test.php
 *
 * Two halves, like tests/netlimit_test.php:
 *   1. the pure PHP of includes/backup.php — settings clamps, profile/item validation, the schedule
 *      arithmetic that decides when a backup is due, size/retention maths and the download tokens.
 *      No database, no network, no root.
 *   2. the root helper tools/opentracker/tracker-backup.sh, driven end to end against a stub
 *      Backup-serwera.sh and stub MariaDB clients in a temporary directory: the run (detached, with
 *      a state file to poll), the built-in fallback, rotation by count / age / total size, verify,
 *      delete, download, restore of file items, and the database restore with its safety dump.
 *      Skipped with a visible SKIP line when the machine has no bash.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/backup.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void {
    global $skips;
    $skips++;
    echo 'SKIP ' . $name . '  -> ' . $why . "\n";
}

// ── 1. settings ──────────────────────────────────────────────────────────────
check('backups off by default', !backupEnabled([]));
check('enabled reads exactly "1"', backupEnabled(['backup_enabled' => '1']) && !backupEnabled(['backup_enabled' => 'yes']));
check('default directory', backupDir([]) === BACKUP_DEFAULT_DIR);
check('directory is trimmed and stripped of control characters', backupDir(['backup_dir' => "  /var/backups/x\n"]) === '/var/backups/x');
check('trailing slash removed', backupDir(['backup_dir' => '/var/backups/x/']) === '/var/backups/x');
check('root alone is not a directory we accept', backupDir(['backup_dir' => '/']) === '/');
check('default profile', backupProfile([]) === 'tracker-lekki');
check('unknown profile falls back', backupProfile(['backup_profile' => 'nope']) === 'tracker-lekki');
check('known profiles kept', backupProfile(['backup_profile' => 'tracker-pelny']) === 'tracker-pelny'
    && backupProfile(['backup_profile' => 'custom']) === 'custom');
check('keep clamped', backupKeep(['backup_keep' => '0']) === 0 && backupKeep(['backup_keep' => '99999']) === BACKUP_KEEP_MAX);
check('keep default', backupKeep([]) === 7);
check('keep days default + clamp', backupKeepDays([]) === 30 && backupKeepDays(['backup_keep_days' => '99999']) === BACKUP_DAYS_MAX);
check('max size default + clamp', backupMaxGb([]) === 20 && backupMaxGb(['backup_max_size_gb' => '99999']) === BACKUP_GB_MAX);
check('nice default + clamp', backupNice([]) === 15 && backupNice(['backup_nice' => '-5']) === 0 && backupNice(['backup_nice' => '99']) === 19);
check('verify-after defaults on', backupVerifyAfter([]) === true);
check('database name default', backupDbName([]) === 'tracker');
check('database name rejects junk', backupDbName(['backup_db_name' => 'drop; db']) === 'tracker');
check('database name accepts a real one', backupDbName(['backup_db_name' => 'tracker_2']) === 'tracker_2');

// ── 2. items and profiles ────────────────────────────────────────────────────
$items = backupProfileItems('tracker-lekki', []);
check('light profile skips the huge index tables', str_contains($items, 'tracker-db-lekka') && !str_contains($items, 'tracker-db,'), $items);
check('full profile takes the whole database', str_contains(backupProfileItems('tracker-pelny', []), 'tracker-db'));
check('both profiles include the config', str_contains($items, 'tracker-config'));
check('custom profile uses the configured items', backupProfileItems('custom', ['backup_items' => 'tracker-db,tracker-config']) === 'tracker-db,tracker-config');
check('custom profile with nothing configured falls back to the light profile',
      backupProfileItems('custom', []) === backupProfileItems('tracker-lekki', []));
check('item list is sanitised', backupSanitizeItems('tracker-db, tracker-config ;rm -rf /') === 'tracker-db,tracker-config',
      backupSanitizeItems('tracker-db, tracker-config ;rm -rf /'));
check('item list drops unknown names', backupSanitizeItems('tracker-db,flarum-db,nonsense') === 'tracker-db,flarum-db',
      backupSanitizeItems('tracker-db,flarum-db,nonsense'));
check('empty item list stays empty', backupSanitizeItems('') === '');
check('duplicates collapse', backupSanitizeItems('tracker-db,tracker-db') === 'tracker-db');
check('the known item list is not empty and is all lowercase',
      count(backupKnownItems()) > 5 && count(array_filter(backupKnownItems(), fn($i) => preg_match('/^[a-z0-9-]+$/', $i))) === count(backupKnownItems()));

// ── 3. path validation (the panel's own half; the helper repeats it) ─────────
check('empty path refused', backupValidateDir('')['ok'] === false);
check('relative path refused', backupValidateDir('relative/dir')['ok'] === false);
check('path with .. refused', backupValidateDir('/var/backups/../etc')['ok'] === false);
check('web root refused', backupValidateDir('/var/www/html/backups')['ok'] === false);
check('a system directory refused', backupValidateDir('/etc')['ok'] === false);
check('root refused', backupValidateDir('/')['ok'] === false);
check('a directory of its own accepted', backupValidateDir('/var/backups/tracker')['ok'] === true, backupValidateDir('/var/backups/tracker')['error'] ?? '');
// the archives hold database passwords, so anything reachable by the web server is refused outright
$inside = dirname(__DIR__) . '/config/backups';
check('inside the application directory refused', backupValidateDir($inside)['ok'] === false, $inside);

// ── 4. the schedule ──────────────────────────────────────────────────────────
// Reuses the weekly grid of the tracker-mode schedule, but a backup is a moment, not a window:
// it fires once per configured day/hour and never twice in the same slot.
check('empty schedule is never due', backupScheduleDue('', 'UTC', 0, 1800000000) === false);
$plan = json_encode(['days' => ['mon', 'wed', 'fri'], 'time' => '04:00']);
check('valid plan parses', backupParseSchedule($plan) !== null);
check('garbage plan is null', backupParseSchedule('{"days":["nope"]}') === null);
check('bad time is null', backupParseSchedule('{"days":["mon"],"time":"25:00"}') === null);
check('empty string is an empty plan, not an error', backupParseSchedule('') === null);
$normalised = backupParseSchedule($plan);
check('plan normalises to days + minutes', $normalised['minutes'] === 240 && $normalised['days'] === ['mon', 'wed', 'fri'], json_encode($normalised));

// Monday 2026-08-31 04:00:30 UTC
$mon0400 = (new DateTimeImmutable('2026-08-31 04:00:30', new DateTimeZone('UTC')))->getTimestamp();
$mon0359 = (new DateTimeImmutable('2026-08-31 03:59:00', new DateTimeZone('UTC')))->getTimestamp();
$tue0400 = (new DateTimeImmutable('2026-09-01 04:00:30', new DateTimeZone('UTC')))->getTimestamp();
$wed0400 = (new DateTimeImmutable('2026-09-02 04:00:30', new DateTimeZone('UTC')))->getTimestamp();
check('due at the configured moment', backupScheduleDue($plan, 'UTC', 0, $mon0400) === true);
check('not due a minute early', backupScheduleDue($plan, 'UTC', 0, $mon0359) === false);
check('not due on a day that is not in the plan', backupScheduleDue($plan, 'UTC', 0, $tue0400) === false);
// the janitor runs every minute: the same slot must fire exactly once
check('not due again after a run in the same slot', backupScheduleDue($plan, 'UTC', $mon0400, $mon0400 + 60) === false);
check('due again on the next configured day', backupScheduleDue($plan, 'UTC', $mon0400, $wed0400) === true);
// a machine that was off through the window still catches up, rather than silently skipping a week
check('a missed slot still fires later the same day',
      backupScheduleDue($plan, 'UTC', 0, $mon0400 + 3 * 3600) === true);
check('but not on the following day', backupScheduleDue($plan, 'UTC', 0, $mon0400 + 26 * 3600) === false);
check('timezone is honoured',
      backupScheduleDue($plan, 'Europe/Warsaw', 0, (new DateTimeImmutable('2026-08-31 04:00:30', new DateTimeZone('Europe/Warsaw')))->getTimestamp()) === true);
check('invalid timezone falls back instead of throwing', backupScheduleDue($plan, 'Nowhere/Atall', 0, $mon0400) === true);
$desc = backupScheduleDescribe($plan, 'Europe/Warsaw');
check('schedule describes itself in words', str_contains($desc, 'Mon') && str_contains($desc, '04:00') && str_contains($desc, 'Europe/Warsaw'), $desc);
check('an empty schedule says so', str_contains(backupScheduleDescribe('', 'UTC'), 'No automatic'));
$next = backupScheduleNext($plan, 'UTC', $mon0400);
check('the next run is the next configured day', $next !== null && $next === $wed0400 - 30, (string)$next);

// ── 5. download tokens ───────────────────────────────────────────────────────
// An archive holds every password on the box, so the download link is single-use, short-lived and
// bound to the id it was minted for.
$tok = backupMintToken('backup-tryhackx-20260827-010203', 'secret-hmac', 1800000000);
check('token is opaque and long enough', strlen($tok) >= 32 && !str_contains($tok, 'backup-tryhackx'));
check('token verifies for its own id', backupVerifyToken($tok, 'backup-tryhackx-20260827-010203', 'secret-hmac', 1800000000 + 10) === true);
check('token refused for another id', backupVerifyToken($tok, 'backup-tryhackx-20260101-000000', 'secret-hmac', 1800000000 + 10) === false);
check('token refused with another secret', backupVerifyToken($tok, 'backup-tryhackx-20260827-010203', 'other', 1800000000 + 10) === false);
check('token expires', backupVerifyToken($tok, 'backup-tryhackx-20260827-010203', 'secret-hmac', 1800000000 + BACKUP_TOKEN_TTL + 5) === false);
check('a mangled token is refused', backupVerifyToken(substr($tok, 0, -2) . 'xx', 'backup-tryhackx-20260827-010203', 'secret-hmac', 1800000000 + 10) === false);
check('an empty token is refused', backupVerifyToken('', 'backup-tryhackx-20260827-010203', 'secret-hmac', 1800000000) === false);

// ── 6. ids ───────────────────────────────────────────────────────────────────
check('a real id is accepted', backupValidId('backup-tryhackx-20260827-010203'));
check('the built-in id shape is accepted', backupValidId('tracker-db-20260827-010203'));
foreach (['../etc/passwd', 'backup-tryhackx-1/../x', '', '.', 'random-file', 'backup tryhackx', "backup-tryhackx-1\n"] as $bad) {
    check('id refused: ' . str_replace("\n", '\\n', $bad === '' ? '(empty)' : $bad), !backupValidId($bad));
}

// ── 7. formatting helpers ────────────────────────────────────────────────────
check('bytes render', backupFormatBytes(0) === '0 B' && backupFormatBytes(1536) === '1.5 KB' && backupFormatBytes(2147483648) === '2 GB',
      backupFormatBytes(1536) . ' / ' . backupFormatBytes(2147483648));

// ── 8. the helper, end to end ────────────────────────────────────────────────
$helper = $root . '/tools/opentracker/tracker-backup.sh';
check('helper script is in the repo', is_file($helper));

$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
    $probe = (str_contains($cand, ' ') ? '"' . $cand . '"' : $cand) . ' -c "echo ok" 2>&1';
    $out = []; $rc = null;
    @exec($probe, $out, $rc);
    if ($rc === 0 && trim(implode('', $out)) === 'ok') { $bash = $cand; break; }
}

if ($bash === null || !trackerExecAvailable()) {
    skip('helper: end-to-end against a stub Backup-serwera.sh', 'no usable bash (or exec() disabled) — run the suite on the server for this half');
} else {
    $tmp = sys_get_temp_dir() . '/backup_test_' . getmypid();
    // The helper insists on a POSIX-absolute directory, which a Windows temp path is not. Keep both
    // spellings: bash gets /c/Users/…, PHP keeps C:/Users/… for its own file checks.
    $posix = static function (string $p): string {
        $p = str_replace('\\', '/', $p);
        if (preg_match('#^([A-Za-z]):/#', $p, $m)) $p = '/' . strtolower($m[1]) . substr($p, 2);
        return $p;
    };
    $native = static function (string $p): string {
        if (DIRECTORY_SEPARATOR !== '\\') return $p;
        if (preg_match('#^/([A-Za-z])/#', $p, $m)) $p = strtoupper($m[1]) . ':/' . substr($p, 3);
        return str_replace('/', '\\', $p);
    };
    @mkdir($tmp . '/bin', 0777, true);
    @mkdir($tmp . '/dir', 0777, true);

    // Stub Backup-serwera.sh: produces an archive with the same layout the real one does
    // (./MANIFEST.txt, ./SUMY.sha256, ./db/<name>.sql, ./pozycje/<item>.baza) and refuses to touch
    // a database on --restore exactly like the original does without a terminal.
    file_put_contents($tmp . '/bin/Backup-serwera.sh', <<<'STUB'
#!/bin/bash
set -u
MODE=""; ITEMS=""; OUT=""; DRY=0; ARCH=""
while [ $# -gt 0 ]; do
  case "$1" in
    --backup) MODE=backup ;;
    --restore) MODE=restore; shift; ARCH="${1-}" ;;
    --items) shift; ITEMS="${1-}" ;;
    --out) shift; OUT="${1-}" ;;
    --dry-run) DRY=1 ;;
    --yes|--no-gpg|--gpg|-y) : ;;
  esac
  shift || true
done
[ -n "${STUB_FAIL:-}" ] && { echo "stub: deliberate failure" >&2; exit 7; }
if [ "$MODE" = backup ]; then
  [ -n "${STUB_SLOW:-}" ] && sleep "${STUB_SLOW}"
  W="$(mktemp -d)"; mkdir -p "$W/db" "$W/pozycje"
  echo "MANIFEST for: $ITEMS" > "$W/MANIFEST.txt"
  case "$ITEMS" in
    *tracker-db-lekka*) echo "-- dump of tracker without the index tables" > "$W/db/tracker-bez-index.sql"
                        echo "tracker|db/tracker-bez-index.sql" > "$W/pozycje/tracker-db-lekka.baza" ;;
    *tracker-db*)       printf -- '-- dump of tracker\nCREATE TABLE t (id INT);\n' > "$W/db/tracker.sql"
                        echo "tracker|db/tracker.sql" > "$W/pozycje/tracker-db.baza" ;;
  esac
  case "$ITEMS" in *tracker-config*) echo "app config" > "$W/pozycje/tracker-config.files" ;; esac
  ( cd "$W" && find . -type f ! -name SUMY.sha256 -exec sha256sum {} + > SUMY.sha256 )
  S="$(date '+%Y%m%d-%H%M%S')"
  install -m 0600 /dev/null "$OUT/backup-tryhackx-$S.tar.gz"
  tar -czf "$OUT/backup-tryhackx-$S.tar.gz" -C "$W" .
  rm -rf "$W"
  echo "archiwum: $OUT/backup-tryhackx-$S.tar.gz"
  exit 0
fi
if [ "$MODE" = restore ]; then
  [ -f "$ARCH" ] || { echo "no archive" >&2; exit 2; }
  tar -tzf "$ARCH" >/dev/null || exit 3
  if [ "$DRY" = "1" ]; then echo "[dry-run] would restore: $ITEMS"; else echo "restored: $ITEMS"; fi
  case "$ITEMS" in *tracker-db*) echo "Tryb nieinteraktywny — POMIJAM odtwarzanie bazy 'tracker'." ;; esac
  exit 0
fi
echo "stub: nothing to do" >&2; exit 1
STUB);
    // Stub MariaDB clients: the dump prints SQL, the client swallows it into a file we can inspect.
    // records the arguments it was handed, so a test can prove the profile reached the dump client
    file_put_contents($tmp . '/bin/mariadb-dump', "#!/bin/bash\nprintf '%s\\n' \"\$*\" > \"\$DUMPARGS_TO\"\nprintf -- '-- builtin dump of %s\\nCREATE TABLE x (id INT);\\n' \"\${!#}\"\nexit 0\n");
    file_put_contents($tmp . '/bin/mariadb', "#!/bin/bash\nfor a in \"\$@\"; do case \"\$a\" in -e) exit 0 ;; esac; done\ncat > \"\$IMPORTED_TO\"\nexit 0\n");
    // the helper refuses to touch anything unless it is root, which no test runner is
    file_put_contents($tmp . '/bin/id', "#!/bin/bash\n[ \"\$1\" = \"-u\" ] && { echo 0; exit 0; }\nexec /usr/bin/id \"\$@\"\n");
    foreach (['Backup-serwera.sh', 'mariadb-dump', 'mariadb', 'id'] as $f) @chmod($tmp . '/bin/' . $f, 0755);

    $dir = $posix($tmp . '/dir');
    putenv('BACKUP_ALLOW_ANY_DIR=1');           // the temp directory is not /var/backups/tracker
    putenv('BACKUP_SCRIPT=' . $posix($tmp . '/bin/Backup-serwera.sh'));
    putenv('MARIADB_DUMP_BIN=' . $posix($tmp . '/bin/mariadb-dump'));
    putenv('MARIADB_BIN=' . $posix($tmp . '/bin/mariadb'));
    putenv('IMPORTED_TO=' . $posix($tmp . '/imported.sql'));
    putenv('DUMPARGS_TO=' . $posix($tmp . '/dumpargs.txt'));
    $pathBefore = (string)getenv('PATH');
    putenv('PATH=' . $tmp . DIRECTORY_SEPARATOR . 'bin' . PATH_SEPARATOR . $pathBefore);

    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $run = static function (string $args) use ($bashCmd, $helper, $posix): array {
        $out = []; $rc = null;
        @exec($bashCmd . ' ' . escapeshellarg($posix($helper)) . ' ' . $args . ' 2>&1', $out, $rc);
        $json = null;
        foreach (array_reverse($out) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') { $json = json_decode($line, true); if (is_array($json)) break; $json = null; }
        }
        return ['rc' => (int)$rc, 'out' => implode("\n", $out), 'json' => $json];
    };
    /**
     * Poll `status` until THIS run is finished. "idle" means the detached worker has not written its
     * state yet, and a leftover "done" from the previous run would look finished — so the state has
     * to say done/failed AND have started at or after the moment we kicked it off.
     */
    $waitDone = static function (string $dir, int $since, int $seconds = 60) use ($run): array {
        $deadline = time() + $seconds;
        $s = ['json' => null];
        do {
            $s = $run('status ' . escapeshellarg($dir));
            $st = (string)($s['json']['state'] ?? '');
            $started = (int)($s['json']['started_at'] ?? 0);
            if (($st === 'done' || $st === 'failed') && $started >= $since) return $s['json'];
            usleep(300000);
        } while (time() < $deadline);
        return $s['json'] ?? ['state' => 'timeout'];
    };
    $q = static fn(string $s): string => escapeshellarg($s);

    // check / profiles
    $r = $run('check ' . $q($dir));
    check('helper: check finds the real tool and picks script mode',
          ($r['json']['script'] ?? null) === true && ($r['json']['mode'] ?? '') === 'script', $r['out']);
    check('helper: check finds a dump client', ($r['json']['mariadb_dump'] ?? null) === true);
    $r = $run('profiles');
    check('helper: profiles agree with the PHP side',
          ($r['json']['profiles']['tracker-lekki'] ?? '') === backupProfileItems('tracker-lekki', []),
          (string)($r['json']['profiles']['tracker-lekki'] ?? ''));

    // argument validation — nothing runs, nothing is created
    foreach ([['run ' . $q($dir) . ' nope', 'an unknown profile'],
              ['run ' . $q($dir) . ' custom --items "bad;rm"', 'shell characters in the item list'],
              ['run ' . $q($dir) . ' custom', 'a custom profile with no items'],
              ['verify ' . $q($dir) . ' ../../etc/passwd', 'a path-traversing id'],
              ['delete ' . $q($dir) . ' nonsense-id', 'an id we never wrote'],
              ['run ' . $q('relative/dir') . ' tracker-lekki', 'a relative directory']] as [$args, $what]) {
        $r = $run($args);
        check("helper: refuses $what", $r['rc'] !== 0 && ($r['json']['ok'] ?? null) === false, $r['out']);
    }

    // ── a real run through the stub tool ──
    $t0 = time();
    $r = $run('run ' . $q($dir) . ' tracker-lekki --nice 19 --verify --keep 0 --keep-days 0 --max-gb 0');
    check('helper: run starts and returns immediately', ($r['json']['started'] ?? null) === true, $r['out']);
    $st = $waitDone($dir, $t0);
    check('helper: the run finishes', ($st['state'] ?? '') === 'done', json_encode($st));
    check('helper: the state names the archive it made', !empty($st['archive']) && is_file($native((string)$st['archive'])), (string)($st['archive'] ?? ''));
    check('helper: the state carries a size', (int)($st['bytes'] ?? 0) > 0);
    check('helper: --verify ran and passed', ($st['verified'] ?? null) === true, json_encode($st['verified'] ?? null));
    check('helper: the log tail is handed back for the panel', !empty($st['log_tail']));
    $id1 = (string)($st['id'] ?? '');
    check('helper: the id looks like an archive id', backupValidId($id1), $id1);

    // list + sidecar
    $r = $run('list ' . $q($dir));
    $archives = $r['json']['archives'] ?? [];
    check('helper: list returns the archive', count($archives) === 1 && ($archives[0]['id'] ?? '') === $id1, json_encode($archives));
    check('helper: list knows the profile without opening the archive', ($archives[0]['profile'] ?? '') === 'tracker-lekki');
    check('helper: list records a checksum', strlen((string)($archives[0]['sha256'] ?? '')) === 64);
    check('helper: list marks it unencrypted', ($archives[0]['encrypted'] ?? null) === false);
    check('helper: list reports the verification result', ($archives[0]['verified'] ?? null) === true);
    check('helper: list totals the bytes', (int)($r['json']['total_bytes'] ?? 0) > 0);

    // verify: quick and deep
    $r = $run('verify ' . $q($dir) . ' ' . $q($id1));
    check('helper: quick verify passes', ($r['json']['ok'] ?? null) === true, $r['out']);
    $r = $run('verify ' . $q($dir) . ' ' . $q($id1) . ' --deep');
    check('helper: deep verify passes', ($r['json']['ok'] ?? null) === true && ($r['json']['deep'] ?? null) === true, $r['out']);
    // corrupt it and make sure the checksum catches it
    $arch = $native((string)($st['archive'] ?? ''));
    $orig = ($arch !== '' && is_file($arch)) ? (string)file_get_contents($arch) : '';
    check('helper: the archive is on disk before the corruption test', $orig !== '', $arch);
    if ($orig !== '') file_put_contents($arch, $orig . 'rot');
    $r = $run('verify ' . $q($dir) . ' ' . $q($id1));
    check('helper: verify catches a changed archive', ($r['json']['ok'] ?? null) === false && str_contains((string)($r['json']['message'] ?? ''), 'changed'), $r['out']);
    $r = $run('list ' . $q($dir));
    check('helper: a failed verification is remembered', (($r['json']['archives'][0]['verified'] ?? null) === false));
    if ($orig !== '') { file_put_contents($arch, $orig); $run('verify ' . $q($dir) . ' ' . $q($id1)); }

    // download
    $r = $run('cat ' . $q($dir) . ' ' . $q($id1));
    check('helper: cat streams the archive', strlen($r['out']) > 100 && $r['rc'] === 0);

    // ── concurrency: a second run must be refused, not queued ──
    putenv('STUB_SLOW=3');
    $t0 = time();
    $r = $run('run ' . $q($dir) . ' tracker-lekki');
    check('helper: the slow run starts', ($r['json']['started'] ?? null) === true, $r['out']);
    usleep(700000);
    $r2 = $run('run ' . $q($dir) . ' tracker-lekki');
    check('helper: a second run while one is in flight is refused', $r2['rc'] !== 0 && str_contains((string)($r2['json']['error'] ?? ''), 'already running'), $r2['out']);
    putenv('STUB_SLOW');
    $st2 = $waitDone($dir, $t0);
    check('helper: the slow run still finished', ($st2['state'] ?? '') === 'done', json_encode($st2));
    $id2 = (string)($st2['id'] ?? '');

    // ── a worker that dies without writing its final state must not read as "running" for ever ──
    // (OOM killer, a reboot mid-dump). status() notices the process is gone and says so.
    file_put_contents($tmp . '/dir/.tracker-backup-state.json',
        '{"state":"running","id":"backup-tryhackx-20200101-000000","mode":"script","profile":"tracker-lekki",'
        . '"items":"tracker-config","started_at":1,"pid":999999,"step":"running","bytes":0,"archive":"",'
        . '"error":"","encrypted":false,"verified":null,"log_tail":"","ts":1}');
    $r = $run('status ' . $q($dir));
    check('helper: a vanished worker is reported as failed, not running',
          ($r['json']['state'] ?? '') === 'failed' && ($r['json']['running'] ?? null) === false, $r['out']);
    check('helper: … and it says why', str_contains((string)($r['json']['error'] ?? ''), 'gone without finishing'), (string)($r['json']['error'] ?? ''));
    @unlink($tmp . '/dir/.tracker-backup-state.json');

    // ── a failing tool must leave a failed state, not a half-written archive ──
    putenv('STUB_FAIL=1');
    $t0 = time();
    $r = $run('run ' . $q($dir) . ' tracker-lekki');
    check('helper: a run that will fail still starts', ($r['json']['started'] ?? null) === true);
    $st3 = $waitDone($dir, $t0);
    check('helper: a failing tool is reported as failed', ($st3['state'] ?? '') === 'failed', json_encode($st3));
    check('helper: the failure says what happened', str_contains((string)($st3['error'] ?? ''), 'exited with code'), (string)($st3['error'] ?? ''));
    putenv('STUB_FAIL');
    $r = $run('list ' . $q($dir));
    check('helper: the failed run left no archive behind', count($r['json']['archives'] ?? []) === 2, json_encode(array_column($r['json']['archives'] ?? [], 'id')));

    // ── rotation ──
    // three more archives, backdated, so age/count/size rules have something to chew on
    for ($i = 0; $i < 3; $i++) {
        $fake = $tmp . '/dir/backup-tryhackx-2026010' . $i . '-000000.tar.gz';
        file_put_contents($fake, str_repeat('x', 4096));
        @touch($fake, time() - (60 - $i) * 86400);
    }
    $r = $run('prune ' . $q($dir) . ' 0 45 0 --dry-run');
    check('helper: prune --dry-run reports without deleting', ($r['json']['dry_run'] ?? null) === true && count($r['json']['removed'] ?? []) === 3, $r['out']);
    check('helper: --dry-run really deleted nothing', count($run('list ' . $q($dir))['json']['archives'] ?? []) === 5);
    $r = $run('prune ' . $q($dir) . ' 0 45 0');
    check('helper: prune by age removes the old ones', count($r['json']['removed'] ?? []) === 3, $r['out']);
    check('helper: and leaves the recent ones', count($run('list ' . $q($dir))['json']['archives'] ?? []) === 2);
    $r = $run('prune ' . $q($dir) . ' 1 0 0');
    check('helper: prune by count keeps exactly the newest', count($r['json']['removed'] ?? []) === 1
          && count($run('list ' . $q($dir))['json']['archives'] ?? []) === 1, $r['out']);
    // the last archive is never deleted by a size rule — an empty backup directory is worse than a full one
    $r = $run('prune ' . $q($dir) . ' 0 0 1');
    check('helper: prune never removes the last archive standing', count($run('list ' . $q($dir))['json']['archives'] ?? []) === 1, $r['out']);

    $remaining = $run('list ' . $q($dir))['json']['archives'][0]['id'];

    // ── restore ──
    $r = $run('restore ' . $q($dir) . ' ' . $q($remaining) . ' --items tracker-config --dry-run');
    check('helper: restore --dry-run works and changes nothing', ($r['json']['ok'] ?? null) === true
          && str_contains((string)($r['json']['output'] ?? ''), 'dry-run'), $r['out']);
    $r = $run('restore ' . $q($dir) . ' ' . $q($remaining) . ' --items tracker-config');
    check('helper: restore of file items runs', ($r['json']['ok'] ?? null) === true, $r['out']);
    $r = $run('restore ' . $q($dir) . ' ' . $q($remaining));
    check('helper: restore without an item list is refused', $r['rc'] !== 0 && str_contains((string)($r['json']['error'] ?? ''), 'explicit'), $r['out']);

    // ── database restore: the one action that overwrites live data ──
    $r = $run('restore-db ' . $q($dir) . ' ' . $q($remaining) . ' --db tracker --confirm wrong');
    check('helper: a mistyped database name stops everything', $r['rc'] !== 0
          && str_contains((string)($r['json']['error'] ?? ''), 'does not match'), $r['out']);
    check('helper: … and nothing was imported', !is_file($tmp . '/imported.sql'));
    $r = $run('restore-db ' . $q($dir) . ' ' . $q($remaining) . ' --db "bad;name" --confirm "bad;name"');
    check('helper: an invalid database name is refused', $r['rc'] !== 0, $r['out']);
    $r = $run('restore-db ' . $q($dir) . ' ' . $q($remaining) . ' --db tracker --confirm tracker --dry-run');
    check('helper: restore-db --dry-run reports the size and imports nothing',
          ($r['json']['dry_run'] ?? null) === true && (int)($r['json']['dump_bytes'] ?? 0) > 0 && !is_file($tmp . '/imported.sql'), $r['out']);
    $r = $run('restore-db ' . $q($dir) . ' ' . $q($remaining) . ' --db tracker --confirm tracker');
    check('helper: restore-db imports the dump', ($r['json']['ok'] ?? null) === true, $r['out']);
    // the light profile dumps `db/tracker-bez-index.sql`, so that is what must reach the client
    check('helper: … the dump really reached the client', is_file($tmp . '/imported.sql')
          && str_contains((string)@file_get_contents($tmp . '/imported.sql'), 'dump of tracker'),
          is_file($tmp . '/imported.sql') ? substr((string)@file_get_contents($tmp . '/imported.sql'), 0, 80) : 'not written');
    // the safety net: the state before the import must be on disk
    $safety = (string)($r['json']['safety_dump'] ?? '');
    check('helper: the current database was dumped BEFORE the import', $safety !== '' && is_file($native($safety)), $safety);
    check('helper: the safety dump is not empty', $safety !== '' && is_file($native($safety)) && filesize($native($safety)) > 0);

    // ── the built-in fallback (no Backup-serwera.sh at all) ──
    putenv('BACKUP_SCRIPT=' . $posix($tmp . '/bin/does-not-exist.sh'));
    $r = $run('check ' . $q($dir));
    check('helper: without the tool it falls back to built-in mode', ($r['json']['mode'] ?? '') === 'builtin'
          && ($r['json']['script'] ?? null) === false, $r['out']);
    $t0 = time();
    $r = $run('run ' . $q($dir) . ' tracker-baza --db tracker');
    check('helper: the built-in run starts', ($r['json']['started'] ?? null) === true && ($r['json']['mode'] ?? '') === 'builtin', $r['out']);
    $st4 = $waitDone($dir, $t0);
    check('helper: the built-in run finishes', ($st4['state'] ?? '') === 'done', json_encode($st4));
    check('helper: it produced a gzipped dump', str_ends_with((string)($st4['archive'] ?? ''), '.sql.gz'), (string)($st4['archive'] ?? ''));
    $r = $run('verify ' . $q($dir) . ' ' . $q((string)$st4['id']));
    check('helper: the built-in dump verifies as a gzip stream', ($r['json']['ok'] ?? null) === true
          && str_contains((string)($r['json']['message'] ?? ''), 'gzip'), $r['out']);
    $args = (string)@file_get_contents($tmp . '/dumpargs.txt');
    check('helper: the database-only profile dumps everything', !str_contains($args, '--ignore-table'), $args);

    // The profile has to mean the same thing without the toolkit as with it: "light" leaves out the
    // two index tables, and saying so in the UI while dumping them anyway would be a lie.
    @unlink($tmp . '/dumpargs.txt');
    $t0 = time();
    $r = $run('run ' . $q($dir) . ' tracker-lekki --db tracker');
    check('helper: the built-in light run starts', ($r['json']['started'] ?? null) === true, $r['out']);
    $st5 = $waitDone($dir, $t0);
    check('helper: the built-in light run finishes', ($st5['state'] ?? '') === 'done', json_encode($st5));
    $args = (string)@file_get_contents($tmp . '/dumpargs.txt');
    check('helper: the light profile skips index_hashes', str_contains($args, '--ignore-table=tracker.index_hashes'), $args);
    check('helper: the light profile skips index_files', str_contains($args, '--ignore-table=tracker.index_files'), $args);
    check('helper: … and says so in the log', str_contains((string)($st5['log_tail'] ?? ''), 'light profile: skipping'), (string)($st5['log_tail'] ?? ''));
    $run('delete ' . $q($dir) . ' ' . $q((string)$st5['id']));
    $r = $run('restore ' . $q($dir) . ' ' . $q((string)$st4['id']) . ' --items tracker-config');
    check('helper: restoring FILES from a plain dump is refused', $r['rc'] !== 0, $r['out']);

    // delete
    $r = $run('delete ' . $q($dir) . ' ' . $q((string)$st4['id']));
    check('helper: delete removes the archive', ($r['json']['deleted'] ?? '') === (string)$st4['id'], $r['out']);
    check('helper: … and its sidecar', !is_file($tmp . '/dir/' . $st4['id'] . '.meta.json'));

    // clean up
    foreach (glob($tmp . '/dir/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp . '/dir/' . '.tracker-backup.lock.d');
    foreach (glob($tmp . '/dir/.*') ?: [] as $f) { if (is_file($f)) @unlink($f); elseif (is_dir($f) && !in_array(basename($f), ['.', '..'], true)) { foreach (glob($f . '/*') ?: [] as $g) @unlink($g); @rmdir($f); } }
    foreach (glob($tmp . '/bin/*') ?: [] as $f) @unlink($f);
    @unlink($tmp . '/imported.sql');
    @unlink($tmp . '/dumpargs.txt');
    @rmdir($tmp . '/dir'); @rmdir($tmp . '/bin'); @rmdir($tmp);
    putenv('PATH=' . $pathBefore);
    foreach (['BACKUP_ALLOW_ANY_DIR', 'BACKUP_SCRIPT', 'MARIADB_DUMP_BIN', 'MARIADB_BIN', 'IMPORTED_TO', 'DUMPARGS_TO'] as $v) putenv($v);
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);
