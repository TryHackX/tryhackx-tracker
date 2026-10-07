<?php
/**
 * 1.74.0 part E — quality (needs the local test database):
 *   php tests/quality_1740_test.php
 * The UX-11 half also needs the local site on http://127.0.0.1:8089/ (VERIFY_BASE to point elsewhere) and says SKIP
 * without it; the QUAL-24 half needs php-cgi beside PHP and says SKIP without it.
 *
 *   1. EVERY SETTING HAS A READER (QUAL-1): each key of api/admin/save_settings.php's $allowed is read by code that is
 *      not where settings are defined — by name, or through a family the code builds (`'recaptcha_on_' . $context`)
 *      whose member the code really asks for. `captcha_pts_login_fail` was a field, a seed and a catalogue entry
 *      that nothing read.
 *   2. ONE DEFAULT PER SWITCH (QUAL-2): transparencyEnabled() and langSwapEnabled() say the schema's '1' for a row
 *      nobody wrote, nothing reads the two switches around them, and every `?? '0'` / `?? '1'` of a switch in the
 *      code is the schema's own default.
 *   3. THE WORKER'S PARALLEL FETCHES (QUAL-3, the PHP side): the probe limit follows the setting, then a fresh
 *      heartbeat, then the worker's own default (3) — never an `?? 8` of its own.
 *   4. A PANEL SESSION WHOSE ACCOUNT CANNOT BE CHECKED (QUAL-22): the real adminSessionValid() with its collaborators
 *      stubbed — one failed lookup is asked again, two refuse THIS request (503 from requireAuth()), and the session
 *      stays for the next one.
 *   5. AN UPGRADE THAT KEEPS FAILING (QUAL-24): under php-cgi (the web's SAPI) the real ensureSchema() pauses a minute
 *      after a failed attempt instead of running the list on every request; the CLI never pauses.
 *   6. NOTHING SWALLOWED IN SILENCE (QUAL-26): no empty `catch` without a word in the code; a count that fails is not
 *      a zero — the Index card's counts are not cached, a timeline sample leaves a gap, the warnings card says so.
 *   7. ACCOUNTS OFF MEANS OFF (PUB-8): user_sessions and user_2fa answer `accounts_disabled` like the rest.
 *   8. AN APPEAL'S LATER MAILS IN ONE LANGUAGE (QUAL-18's last three): the decision, "reopened", "closed with another".
 *   9. EVERY FIELD OF SETTINGS HAS A NAME (UX-11): over HTTP, as an admin-group account.
 * Self-cleaning: its accounts, the settings rows it touches (value and time), its temporary files, its rate-limit keys.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
const QE_INCLUDES = ['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
    'includes/whitelist.php', 'includes/schedule.php', 'includes/stats_timeline.php', 'includes/index.php', 'includes/api_auth.php',
    'includes/auth.php', 'includes/twofa.php', 'includes/bulkmail.php', 'includes/richtext.php', 'includes/livesync.php',
    'includes/reputation.php', 'includes/wlmaint.php', 'includes/wlprobe.php', 'includes/mail.php', 'includes/users.php',
    'includes/favourites.php', 'includes/sounds.php', 'includes/shout.php', 'includes/usermedia.php', 'includes/profilebio.php',
    'includes/profilevotes.php', 'includes/profiledescs.php', 'includes/comments.php', 'includes/reports.php', 'includes/antispam.php',
    'includes/lists.php', 'includes/who.php', 'includes/people.php', 'includes/user2fa.php', 'includes/authbridge.php',
    'includes/audit.php', 'includes/lang.php'];
foreach (QE_INCLUDES as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;
langInit([], 'en');

// What the failure paths below log goes to a file of this run, not into the test's own output.
$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qe1740_' . getmypid() . '.log';
@unlink($logFile);
ini_set('error_log', $logFile);
$logged = function () use ($logFile): string { clearstatcache(); return is_file($logFile) ? (string)file_get_contents($logFile) : ''; };

$tmp = [$logFile];
$rows = [];   // settings rows to put back: key => ['value', 'updated_at'] | null
$keepRow = function (string $k) use ($db, &$rows): void {
    if (array_key_exists($k, $rows)) return;
    $st = $db->prepare("SELECT `value`, updated_at FROM settings WHERE `key` = ?");
    $st->execute([$k]);
    $rows[$k] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$setRow = function (string $k, string $v) use ($db, $keepRow): void {
    $keepRow($k);
    $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$k, $v]);
    if (function_exists('apcu_clear_cache')) @apcu_clear_cache();
};
register_shutdown_function(function () use ($db, &$rows, &$tmp): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'qe1740\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    foreach ($rows as $k => $row) {
        if ($row === null) $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$k]);
        else $db->prepare("INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)")
                ->execute([$k, $row['value'], $row['updated_at']]);
    }
    if (function_exists('apcu_clear_cache')) @apcu_clear_cache();
    foreach ($tmp as $f) @unlink($f);
    if (function_exists('rateLimitForget')) rateLimitForget('user_login', fn(string $s): bool => $s === '127.0.0.1' || $s === '::/64');
});

/** A database that answers nothing: every query, prepare and exec throws, as a lock timeout or a dropped server does. */
final class QeDeadPdo extends PDO {
    public int $calls = 0;
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $this->calls++; throw new PDOException('quality_1740_test: the database is not answering'); }
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->calls++; throw new PDOException('quality_1740_test: the database is not answering'); }
    public function exec(string $statement): int|false { $this->calls++; throw new PDOException('quality_1740_test: the database is not answering'); }
}

/** The code of the site, as text: PHP's string and HTML tokens (comments out), JS / Python / shell lines that are not comments. */
$codeFiles = [];
foreach (['api', 'includes', 'templates', 'tools', 'worker', 'assets/js'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        if (preg_match('~^tools/lang_src\.d/|__pycache__|node_modules~', $rel)) continue;
        if (preg_match('~\.(php|js|py|sh)$~', $rel)) $codeFiles[$rel] = $f->getPathname();
    }
}
foreach (glob($root . '/*.php') as $f) $codeFiles[basename($f)] = $f;
$raw = []; $text = [];
foreach ($codeFiles as $rel => $path) {
    $src = (string)file_get_contents($path);
    $raw[$rel] = $src;
    if (str_ends_with($rel, '.php')) {
        $buf = [];
        foreach (@token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) $buf[] = $t[1];
        }
        $text[$rel] = implode("\n", $buf);
    } else {
        $keep = [];
        foreach (preg_split('/\R/', $src) as $ln) {
            $tl = ltrim($ln);
            if (str_ends_with($rel, '.js') && (str_starts_with($tl, '//') || str_starts_with($tl, '*') || str_starts_with($tl, '/*'))) continue;
            if ((str_ends_with($rel, '.py') || str_ends_with($rel, '.sh')) && str_starts_with($tl, '#')) continue;
            $keep[] = $ln;
        }
        $text[$rel] = implode("\n", $keep);
    }
}
$allCode = implode("\n", $raw);

/* ══ 1. every setting has a reader (QUAL-1) ═══════════════════════════════════════════════════════════════════ */
$saveSrc = $raw['api/admin/save_settings.php'] ?? '';
$allowed = [];
if (preg_match('~\$allowed\s*=\s*\[(.*?)\n\];~s', $saveSrc, $m)) {
    foreach (token_get_all('<?php ' . $m[1]) as $t) if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) $allowed[] = trim($t[1], "'\"");
}
$allowed = array_values(array_unique($allowed));
check('the save allow-list was read (' . count($allowed) . ' keys)', count($allowed) > 300, (string)count($allowed));
check('captcha_pts_login_fail is gone from the allow-list, the catalogue, the installer, the page and the dictionary',
      !in_array('captcha_pts_login_fail', $allowed, true) && !isset(settingsCatalogKeywordsSafe()['captcha_pts_login_fail'])
      && !str_contains($raw['install.php'] ?? '', 'captcha_pts_login_fail') && !str_contains($raw['templates/admin/settings.php'] ?? '', 'captcha_pts_login_fail')
      && !langHas('settings.captcha_smart_pts_login_fail'));
// Where a setting is DEFINED, not read: the allow-list, the catalogue, the defaults, the installer's seed, the field.
$definers = ['api/admin/save_settings.php', 'includes/settings_catalog.php', 'includes/schema.php', 'install.php', 'templates/admin/settings.php'];
$readByName = function (string $key) use ($text, $definers): bool {
    $re = '~(?<![A-Za-z0-9_])' . preg_quote($key, '~') . '(?![A-Za-z0-9_])~';
    foreach ($text as $rel => $t) if (!in_array($rel, $definers, true) && preg_match($re, $t)) return true;
    return false;
};
$asked = fn(string $fn, string $arg): bool => (bool)preg_match('~\b' . $fn . '\(\s*\$cfg\s*,\s*\'' . preg_quote($arg, '~') . '\'\s*[,)]~', $allCode);
$literalIn = fn(string $rel, string $s): bool => str_contains($raw[$rel] ?? '', "'" . $s . "'") || str_contains($raw[$rel] ?? '', '"' . $s . '"');
// The families the code builds a key for — each with the expression that builds it and where its members come from.
$families = [
    '~^recaptcha_on_([a-z_]+)$~' => fn($m) => str_contains($raw['includes/functions.php'] ?? '', "'recaptcha_on_' . \$context") && $asked('isCaptchaEnabled', $m[1]),
    '~^captcha_pts_([a-z_]+)$~'  => fn($m) => str_contains($raw['includes/functions.php'] ?? '', "'captcha_pts_' . \$context") && $asked('addCaptchaPoints', $m[1]),
    '~^antispam_pm_new_(hour|day)_new$~' => fn($m) => str_contains($raw['includes/antispam.php'] ?? '', "'antispam_pm_new_" . $m[1] . "' . \$sfx") && $literalIn('includes/antispam.php', '_new'),
    '~^antispam_([a-z]+)_(burst|steps|reset)$~' => fn($m) => str_contains($raw['includes/antispam.php'] ?? '', "'antispam_' . \$ctx . '_" . $m[2] . "'")
                                                          && defined('ANTISPAM_CONTEXTS') && in_array($m[1], ANTISPAM_CONTEXTS, true),
    '~^sound_default_([a-z_]+)$~' => fn($m) => str_contains($raw['includes/sounds.php'] ?? '', "'sound_default_' . \$k") && $literalIn('includes/sounds.php', $m[1]),
    '~^meta_order_mix_([a-z_]+)$~' => fn($m) => str_contains($raw['worker/worker.py'] ?? '', '"meta_order_mix_" + n') && $literalIn('worker/worker.py', $m[1]),
    '~^([a-z0-9_]+)_site_key$~' => fn($m) => str_contains($raw['includes/functions.php'] ?? '', "captchaProvider(\$cfg) . '_site_key'") && in_array($m[1], captchaProviders(), true),
];
$unread = []; $byFamily = 0;
foreach ($allowed as $key) {
    if ($readByName($key)) continue;
    $claimed = false;
    foreach ($families as $rx => $ok) {
        if (preg_match($rx, $key, $mm)) { $claimed = (bool)$ok($mm); break; }
    }
    if ($claimed) { $byFamily++; continue; }
    $unread[] = $key;
}
check('every key the panel can save is read by the code — by name, or as a member of a family the code really asks for (' . $byFamily . ' by family)',
      $unread === [] && $byFamily > 30, implode(', ', $unread));

/* ══ 2. one default per switch (QUAL-2) ═══════════════════════════════════════════════════════════════════════ */
$sd = trackerSchemaDefaultSettings();
check('transparencyEnabled(): a row nobody wrote reads as the schema\'s default (on); "0" is off',
      ($sd['transparency_enabled'] ?? null) === '1' && transparencyEnabled([]) === true && transparencyEnabled(['transparency_enabled' => '0']) === false
      && transparencyEnabled(['transparency_enabled' => '1']) === true);
check('langSwapEnabled(): the same for the in-place language switch',
      ($sd['lang_swap_enabled'] ?? null) === '1' && langSwapEnabled([]) === true && langSwapEnabled(['lang_swap_enabled' => '0']) === false);
$around = [];
foreach ($raw as $rel => $src) {
    if (!str_ends_with($rel, '.php')) continue;
    foreach (['transparency_enabled' => 'includes/functions.php', 'lang_swap_enabled' => 'includes/lang.php'] as $k => $home) {
        if (preg_match_all('~\[\s*\'' . $k . '\'\s*\]~', $src, $mm) && $rel !== $home) $around[] = $rel . ': ' . $k;
        if ($rel === $home && preg_match_all('~\[\s*\'' . $k . '\'\s*\]~', $src) > 1) $around[] = $rel . ': ' . $k . ' read twice';
    }
}
check('… and nothing reads either switch around its function (the menu, the endpoint, the page conditions, the Settings field)',
      $around === [], implode('; ', $around));
$switchReads = 0; $disagree = [];
foreach ($raw as $rel => $src) {
    if (!str_ends_with($rel, '.php')) continue;
    if (!preg_match_all("~\[\s*'([a-z0-9_]+)'\s*\]\s*\?\?\s*'([01])'~", $src, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
    foreach ($mm as $x) {
        $k = $x[1][0];
        if (!array_key_exists($k, $sd) || !in_array((string)$sd[$k], ['0', '1'], true)) continue;
        $switchReads++;
        if ((string)$sd[$k] !== $x[2][0]) $disagree[] = $rel . ':' . (substr_count($src, "\n", 0, $x[0][1]) + 1) . ' ' . $k . " ?? '" . $x[2][0] . "' (schema '" . $sd[$k] . "')";
    }
}
check('every `?? \'0\'` / `?? \'1\'` of a switch in the code is the schema\'s own default (' . $switchReads . ' read)',
      $switchReads > 100 && $disagree === [], implode('; ', array_slice($disagree, 0, 10)));

/* ══ 3. the worker's parallel fetches (QUAL-3) ════════════════════════════════════════════════════════════════ */
$hb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qe1740_hb_' . getmypid();
$tmp[] = $hb;
file_put_contents($hb, json_encode(['concurrency' => 5, 'concurrency_config' => 4]) . "\n");
check('the probe limit follows a fresh heartbeat\'s concurrency (5)', wlProbeWorkerConcurrency(['worker_heartbeat_file' => $hb]) === 5
      && wlProbeMaxPerSubmit(['worker_heartbeat_file' => $hb]) === 5);
check('… the panel\'s setting first, as the worker takes it', wlProbeWorkerConcurrency(['worker_heartbeat_file' => $hb, 'meta_worker_concurrency' => '12']) === 12);
touch($hb, time() - 3600);
clearstatcache();
check('… an hour-old heartbeat is not believed: the worker\'s own default, 3 (it was `?? 8`)',
      wlProbeWorkerConcurrency(['worker_heartbeat_file' => $hb]) === WL_PROBE_WORKER_DEFAULT_CONCURRENCY && WL_PROBE_WORKER_DEFAULT_CONCURRENCY === 3);
check('… and no heartbeat at all: 3', wlProbeWorkerConcurrency(['worker_heartbeat_file' => $hb . '.none']) === 3
      && wlProbeMaxPerSubmit(['worker_heartbeat_file' => $hb . '.none', 'wl_probe_max_batch' => '64']) === 3);

/* ══ 4. a panel session whose account cannot be checked (QUAL-22) ═════════════════════════════════════════════ */
$h22 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qe1740_q22_' . getmypid() . '.php';
$tmp[] = $h22;
file_put_contents($h22, <<<'PHP'
<?php
// The real adminSessionValid() / requireAuth() with their collaborators stubbed: a lookup that throws on demand.
$GLOBALS['calls'] = 0;
$GLOBALS['mode'] = $argv[2];
function getDb() { return new stdClass(); }
function userFindById($db, int $id) {
    $GLOBALS['calls']++;
    $m = $GLOBALS['mode'];
    if ($m === 'throws' || $m === 'require' || ($m === 'once' && $GLOBALS['calls'] === 1)) throw new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
    return ['id' => $id, 'status' => $m === 'banned' ? 'banned' : 'active', 'sessions_valid_from' => 0];
}
function userIsActive(array $u): bool { return ($u['status'] ?? '') === 'active'; }
function userIsAdminGroup($db, int $id): bool { return true; }
function userHasPanelAccess($db, int $id): bool { return true; }
function __($k, array $p = []) { return $k; }
function jsonResponse(array $d, int $code = 200): void { echo json_encode(['code' => $code, 'body' => $d]); exit; }
ini_set('error_log', $argv[3]);
require $argv[1] . '/includes/auth.php';
$_SESSION = ['loggedin' => true, 'login_time' => time() - 600, 'last_activity' => time() - 30, 'admin_via_user' => 105];
$cfg = ['admin_session_idle_minutes' => '30', 'admin_session_absolute_hours' => '12'];
if ($GLOBALS['mode'] === 'require') { requireAuth($cfg); echo json_encode(['code' => 200]); exit; }
$ok = adminSessionValid($cfg);
echo json_encode(['ok' => $ok, 'calls' => $GLOBALS['calls'], 'loggedin' => !empty($_SESSION['loggedin']),
                  'unchecked' => !empty($GLOBALS['__admin_session_unchecked'])]);
PHP);
$q22 = function (string $mode) use ($h22, $root, $logFile): ?array {
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($h22) . ' ' . escapeshellarg($root) . ' ' . $mode . ' '
                              . escapeshellarg($logFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['raw' => substr($out, 0, 300)];
};
$r = $q22('banned');
check('QUAL-22: a healthy database and an account banned meanwhile — the panel session ends (as before)',
      ($r['ok'] ?? null) === false && ($r['loggedin'] ?? null) === false && ($r['calls'] ?? 0) === 1, $J($r));
$r = $q22('once');
check('… one lookup that fails is asked again: the second answer decides (still allowed, session kept)',
      ($r['ok'] ?? null) === true && ($r['calls'] ?? 0) === 2 && ($r['loggedin'] ?? null) === true, $J($r));
$r = $q22('throws');
check('… two that fail refuse THIS request — the old code let it through — but keep the session for the next one',
      ($r['ok'] ?? null) === false && ($r['calls'] ?? 0) === 2 && ($r['loggedin'] ?? null) === true && ($r['unchecked'] ?? null) === true, $J($r));
$r = $q22('require');
check('… and requireAuth() answers 503 "try again", not 401 "signed out"',
      ($r['code'] ?? 0) === 503 && ($r['body']['error'] ?? '') === 'api.auth.recheck_failed' && ($r['body']['code'] ?? '') === 'session_unchecked', $J($r));
check('… with a line in the log that names the account', str_contains($logged(), '[auth] the panel session of account #105 could not be re-checked'));

/* ══ 5. an upgrade that keeps failing (QUAL-24) ═══════════════════════════════════════════════════════════════ */
$marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qe1740_schema_retry_' . getmypid() . '.marker';
$tmp[] = $marker;
check('the pause\'s helpers: none without a marker; a minute after a failure; gone when cleared', (function () use ($marker): bool {
    @unlink($marker);
    if (!defined('TRACKER_SCHEMA_RETRY_MARKER')) define('TRACKER_SCHEMA_RETRY_MARKER', $marker);
    $t = time();
    $a = schemaRetryPaused($t);
    schemaRetryPause($t);
    $b = schemaRetryPaused($t + 30) && !schemaRetryPaused($t + SCHEMA_WEB_RETRY_SECONDS + 1);
    schemaRetryClear();
    return !$a && $b && !schemaRetryPaused($t) && SCHEMA_WEB_RETRY_SECONDS === 60;
})());
$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
if (!is_file($cgi)) {
    skip('QUAL-24 under the web\'s SAPI', 'no php-cgi beside ' . PHP_BINARY);
} else {
    $h24 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qe1740_q24_' . getmypid() . '.php';
    $tmp[] = $h24;
    file_put_contents($h24, <<<'PHP'
<?php
// The real ensureSchema() under php-cgi, against a database that throws: does a second request run the list again?
define('TRACKER_SCHEMA_RETRY_MARKER', getenv('QE_MARKER'));
ini_set('error_log', getenv('QE_LOG'));
require getenv('QE_ROOT') . '/includes/schema.php';
final class DeadPdo extends PDO {
    public int $calls = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->calls++; throw new PDOException('the lock query failed'); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $this->calls++; throw new PDOException('the query failed'); }
    public function exec(string $statement): int|false { $this->calls++; throw new PDOException('the statement failed'); }
}
$db = new DeadPdo();
$cfg = ['schema_version' => '1'];
@unlink(getenv('QE_MARKER'));
ensureSchema($db, $cfg);
$first = $db->calls;
$paused = schemaRetryPaused();
ensureSchema($db, $cfg);
echo json_encode(['sapi' => PHP_SAPI, 'first' => $first, 'paused' => $paused, 'second' => $db->calls - $first]);
PHP);
    $p = proc_open([$cgi, '-q', '-d', 'display_errors=0', '-f', $h24], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                   array_merge(getenv(), ['QE_MARKER' => $marker, 'QE_LOG' => $logFile, 'QE_ROOT' => $root]));
    $out = is_resource($p) ? (string)stream_get_contents($pipes[1]) : '';
    if (is_resource($p)) { foreach ($pipes as $hh) fclose($hh); proc_close($p); }
    $r = json_decode(trim($out), true);
    check('under the web\'s SAPI a failed upgrade asks the database once and then pauses: the next request does not ask at all',
          is_array($r) && $r['sapi'] !== 'cli' && $r['first'] >= 1 && $r['paused'] === true && $r['second'] === 0, substr($out, 0, 300));
    check('… and says in the log when the web will try again', str_contains($logged(), 'the web retries in 60 s'));
    // The CLI (the janitor, a test) is not held by the web's pause.
    $dead = new QeDeadPdo();
    $c2 = ['schema_version' => '1'];
    ensureSchema($dead, $c2);
    check('… while the CLI is never held by it (the janitor finishes what the web defers)', $dead->calls >= 1 && is_file($marker), (string)$dead->calls);
}

/* ══ 6. nothing swallowed in silence (QUAL-26) ════════════════════════════════════════════════════════════════ */
$silent = []; $catches = 0;
foreach ($raw as $rel => $src) {
    if (!str_ends_with($rel, '.php')) continue;
    $tok = @token_get_all($src);
    $cnt = count($tok);
    for ($i = 0; $i < $cnt; $i++) {
        if (!(is_array($tok[$i]) && $tok[$i][0] === T_CATCH)) continue;
        $catches++;
        $line = $tok[$i][2];
        $j = $i + 1;
        while ($j < $cnt && $tok[$j] !== '{') $j++;
        $depth = 0; $code = false; $comment = false;
        for ($k = $j; $k < $cnt; $k++) {
            $t = $tok[$k];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; if ($depth === 1) continue; }
            elseif ($t === '}') { $depth--; if ($depth === 0) break; }
            if (is_array($t) && $t[0] === T_WHITESPACE) continue;
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { $comment = true; continue; }
            $code = true;
        }
        if (!$code && !$comment) $silent[] = $rel . ':' . $line;
    }
}
check('every catch in the code either does something or says in a comment why it does nothing (' . $catches . ' catches)',
      $catches > 200 && $silent === [], implode(', ', $silent));
$noPrefix = [];
foreach ($raw as $rel => $src) {
    if (!str_ends_with($rel, '.php') || !preg_match_all("~\berror_log\(\s*(['\"])(.)~", $src, $mm, PREG_SET_ORDER)) continue;
    foreach ($mm as $x) if ($x[2] !== '[') $noPrefix[] = $rel;
}
check('… and every error_log() line of the site starts with its [area]', array_values(array_unique($noPrefix)) === [], implode(', ', array_unique($noPrefix)));
// The Index card's counts: a count that fails is thrown on and NOT cached as an empty index for five minutes.
indexStatusCacheDrop();
$threw = false;
try { indexStatus(new QeDeadPdo(), $cfg); } catch (\Throwable $e) { $threw = true; }
check('a status count that fails is not an empty index: indexStatus() throws, nothing is cached, the log says why',
      $threw && !is_file(indexStatusCacheFile('counts')) && str_contains($logged(), '[index] the status counts failed'));
indexStatusCacheDrop();
// A timeline sample: the counts it could not read are a gap where the column may be empty, and a logged line.
$sample = statsTimelineRowFromParsed(new QeDeadPdo(), $cfg, ['torrents' => 3, 'peers' => 2, 'seeds' => 1, 'connections' => []], time());
check('a timeline sample whose counts fail: the resolved count is NULL (a gap on the chart), not 0; both failures logged',
      array_key_exists('index_fetched', $sample) && $sample['index_fetched'] === null && $sample['whitelist_count'] === 0
      && str_contains($logged(), '[timeline] the resolved-index count of a sample failed') && str_contains($logged(), '[timeline] the whitelist count of a sample failed'),
      $J($sample));
$st = statsTimelineStatus(new QeDeadPdo(), $cfg);
check('… and the timeline\'s status counts say null, not an empty history', $st['counts'] === ['raw' => null, '5m' => null, '1h' => null], $J($st['counts']));
// The warnings card in whitelist mode, with the database gone: it SAYS the whitelist could not be read.
$dbWas = $GLOBALS['db'];
$GLOBALS['db'] = new QeDeadPdo();
$w = getTrackerServiceWarnings(array_merge($cfg, ['tracker_mode' => 'whitelist']));
$GLOBALS['db'] = $dbWas;
$texts = array_column((array)($w['items'] ?? []), 'text');
check('the warnings card in whitelist mode says the whitelist\'s state or counts could not be read — it no longer reads green',
      (in_array(__('api.wl.warn_status_failed'), $texts, true) || in_array(__('api.wl.warn_counts_failed'), $texts, true)) && ($w['level'] ?? 'none') !== 'none',
      $J($w));

/* ══ 7. accounts off means off (PUB-8) ════════════════════════════════════════════════════════════════════════ */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qe1740_runner_' . getmypid() . '.php';
$tmp[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.73";
$_GET = $a["get"];
$_POST = $a["post"];
foreach ($a["includes"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () {
    fwrite(STDERR, "STATUS:" . (int)http_response_code() . "\n");
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
langInit($cfg, "en");
require "api/" . $a["endpoint"] . ".php";
');
$ask = function (string $method, string $endpoint, array $cfgOver, array $session, array $get = [], array $post = []) use ($root, $runner, &$tmp): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qe1740_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmp[] = $arg;
    file_put_contents($arg, json_encode(['root' => $root, 'endpoint' => $endpoint, 'method' => $method, 'get' => $get, 'post' => $post,
        'includes' => QE_INCLUDES, 'cfg' => $cfgOver, 'session' => $session, 'sid' => 'qe' . bin2hex(random_bytes(8))]));
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', $runner, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    foreach ($pipes as $hh) fclose($hh);
    proc_close($p);
    $j = json_decode(trim($out), true);
    return ['status' => preg_match('/STATUS:(\d+)/', $err, $mm) ? ((int)$mm[1] ?: 200) : 0, 'json' => is_array($j) ? $j : null, 'raw' => substr($out, 0, 300)];
};
$db->exec("DELETE FROM users WHERE username LIKE 'qe1740\\_%'");
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0']);
$made = userCreate($db, $cfgOn, 'qe1740_member', '', 'Qe1740pass!x', '127.0.0.1');
$uid = (int)($made['user']['id'] ?? 0);
$db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$uid]);
check('fixture: an account with a session', $uid > 0, $J($made['error'] ?? null));
$sess = ['user_id' => $uid, 'user_login_time' => time(), 'csrf_token' => 'qe-token'];
foreach (['user_sessions', 'user_2fa'] as $ep) {
    $off = $ask('GET', $ep, ['users_enabled' => '0', 'user_2fa_enabled' => '1'], $sess);
    $on = $ask('GET', $ep, ['users_enabled' => '1', 'user_2fa_enabled' => '1'], $sess);
    check("$ep: with accounts switched off it answers 400 accounts_disabled to an existing session (it answered 200)",
          $off['status'] === 400 && ($off['json']['error'] ?? '') === 'accounts_disabled', $J([$off['status'], $off['json'] ?? $off['raw']]));
    check("… and with accounts on it still answers that session (200)", $on['status'] === 200 && !empty($on['json']['success']), $J([$on['status'], $on['json'] ?? $on['raw']]));
}
$offPost = $ask('POST', 'user_sessions', ['users_enabled' => '0'], $sess, [], ['csrf_token' => 'qe-token', 'op' => 'others', 'current_password' => 'Qe1740pass!x']);
check('… and a POST too, before anything else is read', $offPost['status'] === 400 && ($offPost['json']['error'] ?? '') === 'accounts_disabled', $J($offPost));

/* ══ 8. an appeal's later mails in one language (QUAL-18) ═════════════════════════════════════════════════════ */
$appeal = ['id' => 7, 'infoHash' => str_repeat('ab', 20), 'appeal_type' => 'unblock', 'name' => 'Ann <b>O\'Brien</b>', 'email' => 'ann@example.org'];
$cfgM = array_merge($cfg, ['site_name' => 'QE Tracker', 'site_url' => 'https://tracker.example.org', 'hmac_secret' => 'qe-secret']);
$pl = mailAppealDecisionParts($appeal, 'accepted', $cfgM, 'pl', ['listed' => true, 'title' => 'Some <i>film</i>', 'response' => 'Odblokowano.']);
$en = mailAppealDecisionParts($appeal, 'accepted', $cfgM, 'en', ['listed' => true, 'title' => 'Some <i>film</i>', 'response' => 'Unblocked.']);
check('the decision in Polish: subject, sentence, the list\'s change and the decision word — in Polish',
      $pl['subject'] === 'Odwołanie od blokady — Uwzględniono (QE Tracker)' && str_contains($pl['plain'], 'Decyzja w sprawie Twojego odwołania od blokady')
      && str_contains($pl['plain'], 'Hash został usunięty z blacklisty trackera.') && str_contains($pl['html'], 'Uwzględniono')
      && str_contains($pl['html'], 'lang="pl"'), $pl['subject'] . ' | ' . substr($pl['plain'], 0, 300));
check('… with no English template words left in it', !preg_match('/\b(Your|has been|Info Hash|Request Type|Decision|Accepted|Hello)\b/', $pl['plain'] . strip_tags($pl['html'])),
      substr($pl['plain'], 0, 400));
check('… the English one as it was (subject, sentence, the blacklist sentence)',
      $en['subject'] === 'Unblock Appeal Accepted — QE Tracker' && str_contains($en['plain'], 'Your appeal to unblock the info hash below has been Accepted.')
      && str_contains($en['plain'], 'The hash has been removed from the tracker blacklist.'), $en['subject'] . ' | ' . substr($en['plain'], 0, 300));
check('… what people typed is escaped in the HTML: the appellant\'s name, the reported title, the moderator\'s words',
      !str_contains($pl['html'], '<b>O\'Brien</b>') && str_contains($pl['html'], '&lt;b&gt;') && !str_contains($pl['html'], '<i>film</i>')
      && str_contains($pl['html'], 'Odblokowano.'));
$rej = mailAppealDecisionParts(array_merge($appeal, ['appeal_type' => 'block']), 'rejected', $cfgM, 'pl', ['listed' => false]);
check('a rejected block request: "Odrzucono", and no sentence about the list when it did not change',
      str_contains($rej['subject'], 'Prośba o blokadę — Odrzucono') && !str_contains($rej['plain'], 'blacklisty'), $rej['subject'] . ' | ' . $rej['plain']);
$re = mailAppealDecisionParts($appeal, 'reopened', $cfgM, 'pl');
$ac = mailAppealDecisionParts(array_merge($appeal, ['appeal_type' => 'block']), 'auto_closed', $cfgM, 'pl');
check('"reopened" and "closed with another" in Polish too, each with its own sentence',
      str_contains($re['subject'], 'ponownie do rozpatrzenia') && str_contains($re['plain'], 'zostało otwarte ponownie')
      && str_contains($ac['subject'], 'zamknięto') && str_contains($ac['plain'], 'zamknięta automatycznie'), $re['subject'] . ' | ' . $ac['subject']);
$apdSrc = ($raw['api/admin/resolve_appeal.php'] ?? '') . ($raw['api/admin/restore_appeal.php'] ?? '');
check('the three places that sent English send through it now', str_contains($apdSrc, "sendAppealDecision(\$db, \$appeal, \$newStatus")
      && str_contains($apdSrc, "sendAppealDecision(\$db, \$appeal, 'reopened'") && str_contains($raw['includes/functions.php'] ?? '', "sendAppealDecision(\$db, \$rel, 'auto_closed'")
      && !str_contains($apdSrc . ($raw['includes/functions.php'] ?? ''), "'Hello ' . sanitize("));

/* ══ 9. every field of Settings has a name (UX-11) ════════════════════════════════════════════════════════════ */
$site = rtrim(getenv('VERIFY_BASE') ?: 'http://127.0.0.1:8089/', '/') . '/';
$probe = @file_get_contents($site . '?action=tos', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
if ($probe === false || !function_exists('curl_init') || !class_exists('DOMDocument')) {
    skip('UX-11 over HTTP', 'the local site does not answer at ' . $site . ' (or no curl / DOM)');
} else {
    $setRow('users_enabled', '1');
    $setRow('users_require_email_verify', '0');
    $made = userCreate($db, $cfgOn, 'qe1740_adm', 'qe1740_adm@example.org', 'Qe1740adm!pass', '127.0.0.1');
    $aid = (int)($made['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?")->execute([$aid]);
    $ag = userGroupBySlug($db, 'admin');
    if ($aid > 0 && $ag) userGrantGroup($db, $aid, (int)$ag['id'], null, 'quality_1740_test', '', false);
    rateLimitForget('user_login', fn(string $s): bool => $s === '127.0.0.1' || $s === '::/64');
    $jar = tempnam(sys_get_temp_dir(), 'qe1740');
    $tmp[] = $jar;
    $req = function (string $path, ?array $json = null) use ($site, $jar): array {
        $c = curl_init($site . $path);
        $h = ['Accept-Language: en'];
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 60,
                               CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
        if ($json !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($json));
            $h[] = 'Content-Type: application/json';
        }
        curl_setopt($c, CURLOPT_HTTPHEADER, $h);
        $body = (string)curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    };
    $lt = preg_match('/<meta name="csrf-token" content="([^"]*)">/', $req('?action=login&lang=en')['body'], $mm) ? $mm[1] : '';
    $in = $req('api.php?endpoint=user_login', ['csrf_token' => $lt, 'login' => 'qe1740_adm', 'password' => 'Qe1740adm!pass', 'session' => '1h']);
    $page = $req('?action=settings&lang=en');
    check('an admin-group account signs in and opens Settings', !empty($in['json']['success']) && $page['code'] === 200 && str_contains($page['body'], 'id="settings-form"'),
          $page['code'] . ' ' . substr((string)($in['body'] ?? ''), 0, 200));
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $page['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($dom);
    $labelled = [];
    foreach ($xp->query('//label[@for]') as $l) if (trim($l->textContent) !== '') $labelled[$l->getAttribute('for')] = true;
    $nameless = []; $fields = 0;
    foreach ($xp->query('//input | //select | //textarea') as $el) {
        /** @var DOMElement $el */
        $type = strtolower($el->getAttribute('type'));
        if ($el->nodeName === 'input' && in_array($type, ['hidden', 'submit', 'button', 'image', 'reset'], true)) continue;
        if ($el->getAttribute('aria-hidden') === 'true') continue;          // the password manager's username, out of reach
        $fields++;
        if (trim($el->getAttribute('aria-label')) !== '' || $el->getAttribute('aria-labelledby') !== '' || trim($el->getAttribute('title')) !== '') continue;
        if ($el->getAttribute('id') !== '' && isset($labelled[$el->getAttribute('id')])) continue;
        for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) if ($p->nodeName === 'label') continue 2;
        if ($el->nodeName !== 'select' && trim($el->getAttribute('placeholder')) !== '') continue;
        $nameless[] = $el->nodeName . ($el->getAttribute('name') !== '' ? '[name=' . $el->getAttribute('name') . ']' : '')
                    . ($el->getAttribute('id') !== '' ? '#' . $el->getAttribute('id') : '') . ($el->getAttribute('class') !== '' ? '.' . strtok($el->getAttribute('class'), ' ') : '');
    }
    check('every field on the Settings page has a name a screen reader can say (' . $fields . ' fields; 311 had none)',
          $fields > 400 && $nameless === [], count($nameless) . ': ' . implode(', ', array_slice($nameless, 0, 25)));
    // 1.73.3's page had 49 labels tied to a field; the rest were captions beside it.
    check('… most of them through their own visible label (<label for>), so a click on the words reaches the field too',
          count($labelled) > 300 && count($labelled) * 10 >= $fields * 6, count($labelled) . ' of ' . $fields);
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);

/** The catalogue's keywords, or [] when the catalogue file is not loaded. */
function settingsCatalogKeywordsSafe(): array {
    if (!function_exists('settingsCatalogKeywords')) require_once dirname(__DIR__) . '/includes/settings_catalog.php';
    return function_exists('settingsCatalogKeywords') ? settingsCatalogKeywords() : [];
}
