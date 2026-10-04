<?php
/**
 * One anti-spam layer for everything people write (1.71.0, includes/antispam.php):
 *   php tests/antispam_test.php
 *
 * What the owner asked for — "the first 2-3 messages without a limit, then 5 seconds, 15, 30, the whole
 * limit expiring after about 2 minutes, or a CAPTCHA; a guest could always have one; messages and the
 * shoutbox protected so nobody starts spam" — and what the brief added, pinned:
 *
 *   1. the schema on both paths (a fresh install's CREATE, an upgrade's guarded statement, the same
 *      definition) and the settings in their four places (defaults, the save's allow-list and clamps, the
 *      form, the search words), messages' own rate_limit_pm, the five recaptcha_on_* defaults at last;
 *   2. the ladder as numbers: the room exactly as sketched, the floor, a new account's factor, the clamps;
 *   3. the ladder live: the burst, each step, the top repeated, the quiet reset (time is moved by rewriting
 *      the row's instants, never slept), the layer off (the room's old wall and nothing else);
 *   4. CAPTCHA escalation: three hits at the top, refused attempts counted, a bad and a good token, the ease;
 *      guests every time; no provider — the pauses alone;
 *   5. new accounts: stricter pauses; links drawn as TEXT in the room, a comment, a list and the profile, by
 *      when the words were written (a link written while new stays text); staff never new;
 *   6. duplicates and the messages' spread; 7. the messages' new-conversation limits (hour, day, a reply never
 *      counted); 8. staff exempt (and not, when the site says so); 9. corrections; 10. a reservation handed
 *      back; 11. failing CLOSED; 12. the audit, once an hour; 13. two requests RACING (child processes);
 *   14. the endpoint files as requests; 15. every writer endpoint calls the layer (read from api/*.php);
 *   16. the leftovers: votes' CAPTCHA, "@name.", the sounds test's cascade; 17. the pages' half.
 *
 * Every switch a check leans on is set in $cfgT — nothing is inherited from this database's live settings.
 * Self-cleaning: its accounts go through the account-deletion cascade (and their anti-spam rows with them),
 * the guest subjects' rows, its hashes, its audit lines, the guest group's JSON, the rate-limit keys (and the
 * file itself when it was not there before) are put back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/sounds.php', 'includes/shout.php',
          'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/icons.php', 'includes/settings_catalog.php',
          'includes/comments.php', 'includes/reports.php', 'includes/lists.php', 'includes/profilebio.php', 'includes/reputation.php',
          'includes/lang.php', 'includes/antispam.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db;
langInit([], 'en');

// Every switch the layer reads, set here — the per-context ladders from the code's own defaults.
$cfgT = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '0', 'profiles_enabled' => '1', 'profile_bio_enabled' => '1',
    'antispam_enabled' => '1', 'antispam_captcha_after' => '3', 'antispam_guest_captcha' => '1', 'antispam_staff_exempt' => '1',
    'antispam_new_days' => '3', 'antispam_new_factor' => '2', 'antispam_new_links' => '1', 'antispam_dup_seconds' => '600',
    'antispam_pm_new_hour' => '8', 'antispam_pm_new_day' => '20', 'antispam_pm_new_hour_new' => '2', 'antispam_pm_new_day_new' => '4',
    'antispam_pm_spread' => '2', 'shout_flood_seconds' => '5', 'recaptcha_enabled' => '0', 'default_language' => 'en',
    'link_trusted_domains' => 'example.org', 'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1',
]);
foreach (ANTISPAM_DEFAULTS as $c => [$b, $st, $r]) {
    $cfgT['antispam_' . $c . '_burst'] = (string)$b;
    $cfgT['antispam_' . $c . '_steps'] = implode(',', $st);
    $cfgT['antispam_' . $c . '_reset'] = (string)$r;
}
$GLOBALS['cfg'] = $cfgT;
$cfgCap = array_merge($cfgT, ['recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha',
                              'recaptcha_site_key' => 'site-key-test', 'recaptcha_secret' => 'secret-test']);
$verify = fn(string $tok): bool => $tok === 'good-token';

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
const AS_USERS = ['astest_a', 'astest_b', 'astest_c', 'astest_d', 'astest_e', 'astest_f', 'astest_g', 'astest_h', 'astest_new',
                  'astest_old', 'astest_mod', 'astest_s', 'astest_r1', 'astest_r2', 'astest_r3', 'astest_r4', 'astest_http',
                  'astest_hr1', 'astest_hr2', 'astest_hr3', 'astest_rep', 'astest_auth', 'astest_vote', 'astest_race', 'astest_race2', 'astest_ed'];
const AS_HASH = 'a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5';
const AS_GUEST_IP = '198.51.100.77';
$rlFile = $root . '/config/rate_limits.json';
$rlExisted = is_file($rlFile);
$guestBefore = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn();
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$tmpFiles = [];
register_shutdown_function(function () use ($db, $rlFile, $rlExisted, $guestBefore, $auditFloor, &$tmpFiles) {
    foreach (AS_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) userDeleteCascade($db, $id);
    }
    $db->exec("DELETE FROM antispam_state WHERE subject LIKE 'g:198.51.100.%' OR subject LIKE 'g:127.0.0.%' OR subject LIKE 'u:9999%'");
    $db->prepare("DELETE FROM hash_comments WHERE info_hash = ?")->execute([AS_HASH]);
    $db->prepare("DELETE FROM index_hashes WHERE info_hash = ?")->execute([AS_HASH]);
    if ($guestBefore !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([$guestBefore]);
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
    // The file limiter's keys this run added — or, when there was no file before, the file itself.
    if (!$rlExisted) { @unlink($rlFile); @unlink($rlFile . '.lock'); }
    elseif (is_file($rlFile)) {
        $data = json_decode((string)@file_get_contents($rlFile), true) ?: [];
        foreach (array_keys($data) as $k) if (preg_match('/^(comment|creport|shoutpost|shoutedit|pmsend|profile_bio|listedit|emoteupload|rtpreview)\|/', $k)) unset($data[$k]);
        rateLimitWrite($rlFile, $data);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach ($tmpFiles as $f) @unlink($f);
});

/** A member, made fresh; `$daysOld` backdates the account (the app's own connection, so its clock). */
function asUser(PDO $db, array $cfg, string $name, int $daysOld = 30): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("UPDATE users SET email_verified = 1, pm_who = 'all', created_at = NOW() - INTERVAL ? DAY WHERE id = ?")->execute([$daysOld, $id]);
    }
    userPermissionsForget($id);
    return $id;
}
$u = function (int $id) use ($db): array { return userFindById($db, $id) ?? []; };
$subj = fn(int $id) => antispamSubject(userFindById($db, $id) ?? ['id' => $id], '127.0.0.9');
$ask = fn(string $ctx, array $s, ?string $text = null, array $opts = [], ?array $c = null): array => antispamCheck($db, $c ?? $cfgT, $ctx, $s, $text, $opts);
$row = function (string $ctx, string $key) use ($db): array {
    $st = $db->prepare("SELECT * FROM antispam_state WHERE context = ? AND subject = ?");
    $st->execute([$ctx, $key]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
// Time is moved by rewriting the row's instants (and its fingerprints' times), never by sleeping.
$travel = function (string $ctx, string $key, int $sec) use ($db): void {
    $st = $db->prepare("SELECT recent FROM antispam_state WHERE context = ? AND subject = ?");
    $st->execute([$ctx, $key]);
    $rec = json_decode((string)$st->fetchColumn(), true);
    $rec = is_array($rec) ? array_map(fn($e) => [$e[0], max(0, (int)$e[1] - $sec), $e[2] ?? ''], $rec) : [];
    $db->prepare("UPDATE antispam_state SET last_at = IF(last_at > ?, last_at - ?, 0), next_at = IF(next_at > ?, next_at - ?, 0),
                         edit_at = IF(edit_at > ?, edit_at - ?, 0), recent = ? WHERE context = ? AND subject = ?")
       ->execute([$sec, $sec, $sec, $sec, $sec, $sec, $rec ? json_encode($rec) : '', $ctx, $key]);
};
$okr = fn(array $r): bool => !empty($r['ok']);

// A session of this process's own: onCaptchaSolved() writes into it.
session_id('astest' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['csrf_token'] = 'as-test-token';

/* ══ 1. the schema and the settings ═══════════════════════════════════════════════════════════ */
check('the schema is at 85 or later, and its line says what 85 is', TRACKER_SCHEMA_VERSION >= 85
      && str_contains($src('includes/schema.php'), '85 = one anti-spam layer for everything people write (includes/antispam.php): `antispam_state`'));
$create = '';
foreach (trackerSchemaStatements() as $sql) if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `antispam_state`')) $create = $sql;
check('a fresh install creates antispam_state: one row per (context, subject), the instants as numbers, a key for the pruning',
      $create !== '' && str_contains($create, 'PRIMARY KEY (`context`, `subject`)') && str_contains($create, 'KEY `idx_as_updated` (`updated_at`)')
      && str_contains($create, '`last_at` INT UNSIGNED') && str_contains($create, '`next_at` INT UNSIGNED') && str_contains($create, '`recent` VARCHAR(1000)')
      && str_contains($create, '`rev` INT UNSIGNED'));
check('this database has it', schemaTableExists($db, 'antispam_state'));
$scratch = 'tracker_as_' . bin2hex(random_bytes(3));
$dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: '3306');
$base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
$noTable = null;
try {
    $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        // A database with no table at all first: the layer must REFUSE, not wave through (section 11).
        $noTable = antispamCheck($sdb, $cfgT, 'shout', ['key' => 'u:99990', 'uid' => 99990, 'guest' => false, 'user' => null, 'ip' => ''], 'x');
        $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT, `updated_at` DATETIME NULL)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (trackerSchemaStatements() as $sql) $sdb->exec($sql);
        $guarded = trackerSchemaGuardedStatements($sdb);
        foreach ($guarded as $s) { foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); break; } catch (\Throwable $e) { /* next */ } } }
        check('fresh: the table is there, and the guarded list runs over it cleanly', schemaTableExists($sdb, 'antispam_state'));
        $sdb->exec("DROP TABLE antispam_state");
        $guarded = trackerSchemaGuardedStatements($sdb);
        $gc = '';
        foreach ($guarded as $s) if (is_string($s) && str_contains($s, 'CREATE TABLE IF NOT EXISTS `antispam_state`')) $gc = $s;
        foreach ($guarded as $s) { foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); break; } catch (\Throwable $e) { /* next */ } } }
        $norm = fn($s) => preg_replace('/\s+/', ' ', preg_replace('/^\s*--.*$/m', '', $s));
        check('an UPGRADE: the guarded list brings the table, from the SAME definition as a fresh install',
              schemaTableExists($sdb, 'antispam_state') && $gc !== '' && $norm($gc) === $norm($create));
    } finally {
        $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
} catch (\Throwable $e) {
    check('the scratch database for the schema', false, $e->getMessage());
}

$defs = trackerSchemaDefaultSettings();
$keys = ['antispam_enabled', 'antispam_captcha_after', 'antispam_guest_captcha', 'antispam_staff_exempt', 'antispam_new_days',
         'antispam_new_factor', 'antispam_new_links', 'antispam_dup_seconds', 'antispam_pm_new_hour', 'antispam_pm_new_day',
         'antispam_pm_new_hour_new', 'antispam_pm_new_day_new', 'antispam_pm_spread', 'rate_limit_pm'];
foreach (ANTISPAM_CONTEXTS as $c) foreach (['burst', 'steps', 'reset'] as $p) $keys[] = 'antispam_' . $c . '_' . $p;
$save = $src('api/admin/save_settings.php');
$tpl = $src('templates/admin/settings.php');
$kw = settingsCatalogKeywords();
$missing = [];
foreach ($keys as $k) {
    $where = [];
    if (!array_key_exists($k, $defs)) $where[] = 'default';
    if (!str_contains($save, "'" . $k . "'") && !preg_match('/_steps$/', $k)) $where[] = 'save';
    if (!isset($kw[$k])) $where[] = 'keywords';
    $inForm = str_contains($tpl, 'name="' . $k . '"')
           || (preg_match('/^antispam_(\w+)_(burst|steps|reset)$/', $k, $mm) && in_array($mm[1], ANTISPAM_CONTEXTS, true)
               && str_contains($tpl, 'data-setting="' . $k . '"><?= $asField(\'' . $mm[1] . '\', \'' . $mm[2] . '\') ?>'));
    if (!$inForm) $where[] = 'form';
    if ($where) $missing[] = $k . ':' . implode('/', $where);
}
check('every anti-spam setting (' . count($keys) . ', the 27 ladder keys among them) in its four places: default, save, form, search words',
      $missing === [], implode(', ', $missing));
check('the ladders\' steps are saved as their reader parses them, every context\'s', str_contains($save, "foreach (ANTISPAM_CONTEXTS as \$asCtx)")
      && str_contains($save, "implode(',', antispamParseSteps((string)\$data[\$k]))") && str_contains($save, "'antispam_shout_steps'"));
check('the clamps: the switches are 0/1, the numbers bounded (the burst by ANTISPAM_BURST_MAX, a reset 10 s .. a week)',
      str_contains($save, "'antispam_enabled', 'antispam_guest_captcha', 'antispam_staff_exempt', 'antispam_new_links',")
      && str_contains($save, "'antispam_shout_burst' => [0, ANTISPAM_BURST_MAX, 3], 'antispam_shout_reset' => [ANTISPAM_RESET_MIN, ANTISPAM_RESET_MAX, 120]")
      && str_contains($save, "'antispam_captcha_after' => [0, 50, 3]") && str_contains($save, "'rate_limit_pm' => [1, 100000, 240]"));
check('the defaults: the room exactly as sketched (3 free, 5, 15, 30, 60; two minutes), comments slower, descriptions and reports much slower',
      $defs['antispam_shout_burst'] === '3' && $defs['antispam_shout_steps'] === '5,15,30,60' && $defs['antispam_shout_reset'] === '120'
      && $defs['antispam_comment_steps'] === '15,30,60,120' && $defs['antispam_description_steps'] === '60,300,900,1800'
      && $defs['antispam_report_steps'] === '30,120,300,900' && $defs['antispam_enabled'] === '1' && $defs['antispam_guest_captcha'] === '1');
check('messages have their own address ceiling now (rate_limit_pm, 240), read by the send; the favourites\' phantom key is gone from it',
      ($defs['rate_limit_pm'] ?? '') === '240' && pmRatePerHour([]) === 240 && pmRatePerHour(['rate_limit_pm' => '0']) === 240
      && str_contains($src('api/user_messages.php'), "rateLimitAllow('pmsend', ipBucket(getClientIp(\$cfg)), pmRatePerHour(\$cfg), 3600)")
      && !str_contains($src('api/user_messages.php'), 'rate_limit_favourites'));
check('the five recaptcha_on_* switches are defaults at last, as the form shows a missing one (report yes, the rest no)',
      ($defs['recaptcha_on_report'] ?? '') === '1' && ($defs['recaptcha_on_login'] ?? '') === '0' && ($defs['recaptcha_on_status'] ?? '') === '0'
      && ($defs['recaptcha_on_appeal'] ?? '') === '0' && ($defs['recaptcha_on_block_check'] ?? '') === '0'
      && str_contains($tpl, "(\$cfg['recaptcha_on_report'] ?? '1') === '1'") && str_contains($tpl, "(\$cfg['recaptcha_on_login'] ?? '0') === '1'"));
check('the section is Security & CAPTCHA\'s, after the smart CAPTCHA, and says when no provider is set up',
      str_contains($tpl, 'id="section-antispam" data-group="security"')
      && strpos($tpl, 'id="section-captcha-smart"') < strpos($tpl, 'id="section-antispam"') && strpos($tpl, 'id="section-antispam"') < strpos($tpl, 'id="section-limits"')
      && str_contains($tpl, "if (!(function_exists('antispamCaptchaAvailable') && antispamCaptchaAvailable(\$cfg))): ?>")
      && str_contains($tpl, 'id="antispam-no-captcha"') && langHas('settings.antispam_no_captcha'));

/* ══ 2. the ladder, as numbers ════════════════════════════════════════════════════════════════ */
$lad = antispamLadder($cfgT, 'shout');
$seq = [];
for ($k = 1; $k <= 8; $k++) $seq[] = antispamPauseBefore($lad, $k);
check('the room: the pause before each line — 0 0 0 5 15 30 60 60', $seq === [0, 0, 0, 5, 15, 30, 60, 60], json_encode($seq));
check('… the top is the fourth pause on (the seventh line and after)', !antispamAtTop($lad, 6) && antispamAtTop($lad, 7) && antispamAtTop($lad, 9));
$ladF = antispamLadder(array_merge($cfgT, ['shout_flood_seconds' => '10']), 'shout');
check('shout_flood_seconds is the floor under the steps once any pause applies — never inside the free burst',
      antispamPauseBefore($ladF, 3) === 0 && antispamPauseBefore($ladF, 4) === 10 && antispamPauseBefore($ladF, 5) === 15);
$ladN = antispamLadder($cfgT, 'shout', true);
check('a new account: every pause and the reset twice as long, the burst the same',
      $ladN['burst'] === 3 && $ladN['steps'] === [10, 30, 60, 120] && $ladN['reset'] === 240);
check('the steps as typed: whole seconds, 1 s .. a day, at most eight, the order kept',
      antispamParseSteps(' 5, 15;30 x 60 ') === [5, 15, 30, 60] && antispamParseSteps('0,999999') === [1, 86400]
      && count(antispamParseSteps('1,2,3,4,5,6,7,8,9,10')) === 8 && antispamParseSteps('') === []);
check('clamped on read: burst, reset, the escalation, the days, the factor, the window, the spread',
      antispamLadder(['antispam_vote_burst' => '999', 'antispam_vote_reset' => '1'], 'vote')['burst'] === ANTISPAM_BURST_MAX
      && antispamLadder(['antispam_vote_reset' => '1'], 'vote')['reset'] === ANTISPAM_RESET_MIN
      && antispamCaptchaAfter(['antispam_captcha_after' => '-4']) === 0 && antispamNewDays(['antispam_new_days' => '500']) === 90
      && antispamNewFactor(['antispam_new_factor' => '0']) === 2 && antispamDupSeconds(['antispam_dup_seconds' => '999999']) === 86400
      && antispamPmSpread(['antispam_pm_spread' => '99']) === 50);
check('a time in words, the server\'s way', antispamTimeText(12) === '12 s' && antispamTimeText(125) === '2 min 5 s' && antispamTimeText(3900) === '1 h 5 min');

/* ══ 3. the ladder, live ══════════════════════════════════════════════════════════════════════ */
$A = asUser($db, $cfgT, 'astest_a');
$sA = $subj($A);
$r = [];
for ($i = 1; $i <= 3; $i++) $r[] = $ask('shout', $sA, "line number $i of the burst");
check('three lines free', $okr($r[0]) && $okr($r[1]) && $okr($r[2]) && (int)$row('shout', 'u:' . $A)['level'] === 3);
$r4 = $ask('shout', $sA, 'the fourth line, too soon');
check('the fourth waits 5 s: 429, antispam_wait, the seconds, the sentence with the time, the template',
      !$okr($r4) && $r4['kind'] === 'wait' && $r4['status'] === 429 && ($r4['body']['error'] ?? '') === 'antispam_wait'
      && in_array((int)$r4['body']['retry_after'], [4, 5], true) && str_contains((string)$r4['body']['message'], 's.')
      && str_contains((string)$r4['body']['antispam']['tpl'], '{time}') && ($r4['body']['antispam']['context'] ?? '') === 'shout', json_encode($r4['body']));
$travel('shout', 'u:' . $A, 5);
check('… and passes once they have', $okr($ask('shout', $sA, 'the fourth line, in time')));
$w = $ask('shout', $sA, 'the fifth line, too soon');
check('then 15', $w['kind'] === 'wait' && $w['seconds'] > 5 && $w['seconds'] <= 15, json_encode($w['seconds']));
$travel('shout', 'u:' . $A, 15);
$ask('shout', $sA, 'the fifth line');
$w = $ask('shout', $sA, 'the sixth line, too soon');
check('then 30', $w['kind'] === 'wait' && $w['seconds'] > 15 && $w['seconds'] <= 30, json_encode($w['seconds']));
$travel('shout', 'u:' . $A, 30);
$ask('shout', $sA, 'the sixth line');
$w = $ask('shout', $sA, 'the seventh line, too soon');
check('then 60', $w['kind'] === 'wait' && $w['seconds'] > 30 && $w['seconds'] <= 60, json_encode($w['seconds']));
$travel('shout', 'u:' . $A, 60);
$ask('shout', $sA, 'the seventh line');
$w = $ask('shout', $sA, 'the eighth line, too soon');
check('… and 60 again: the top repeats', $w['kind'] === 'wait' && $w['seconds'] > 30 && $w['seconds'] <= 60);
$travel('shout', 'u:' . $A, 120);
$r = [$ask('shout', $sA, 'after the quiet, one'), $ask('shout', $sA, 'after the quiet, two'), $ask('shout', $sA, 'after the quiet, three')];
check('two quiet minutes and it starts over: three free again', $okr($r[0]) && $okr($r[1]) && $okr($r[2]) && (int)$row('shout', 'u:' . $A)['level'] === 3
      && $ask('shout', $sA, 'after the quiet, four')['kind'] === 'wait');
$st0 = $row('shout', 'u:' . $A);
$db->prepare("UPDATE antispam_state SET last_at = last_at - 3 WHERE context = 'shout' AND subject = ?")->execute(['u:' . $A]);
$cfg2 = array_merge($cfgT, ['antispam_shout_steps' => '2,15,30,60', 'shout_flood_seconds' => '0']);
check('a pause shortened in Settings applies at once (measured from the last line with the ladder as it is NOW)',
      $okr($ask('shout', $sA, 'shortened pause, fourth', [], $cfg2)) && (int)$st0['next_at'] > 0);
$cfgOff = array_merge($cfgT, ['antispam_enabled' => '0']);
$B0 = asUser($db, $cfgT, 'astest_b');
$sB0 = $subj($B0);
$w1 = $ask('shout', $sB0, 'the layer off, one', [], $cfgOff);
$w2 = $ask('shout', $sB0, 'the layer off, two', [], $cfgOff);
check('the layer OFF: the room keeps its old wall — shout_flood_seconds between every two lines', $okr($w1) && $w2['kind'] === 'wait' && $w2['seconds'] <= 5);
$travel('shout', 'u:' . $B0, 5);
$allOk = true;
for ($i = 0; $i < 8; $i++) $allOk = $allOk && $okr($ask('comment', $sB0, 'the same words, the layer off', [], $cfgOff));
check('… and nothing else: eight comments at once, the same words each time', $allOk && $okr($ask('shout', $sB0, 'the layer off, after the wall', [], $cfgOff)));

/* ══ 4. CAPTCHA ═══════════════════════════════════════════════════════════════════════════════ */
$B = asUser($db, $cfgT, 'astest_c');
$sB = $subj($B);
$kB = 'u:' . $B;
$ok4 = true;
foreach ([0, 0, 15, 30, 60, 120, 120, 120] as $i => $gap) {
    if ($gap) $travel('comment', $kB, $gap);
    $ok4 = $ok4 && $okr($ask('comment', $sB, "comment $i climbing the ladder", ['verify' => $verify], $cfgCap));
}
$st = $row('comment', $kB);
check('eight comments up the ladder: three of them at its top, and a CAPTCHA is now due', $ok4 && (int)$st['top_hits'] === 3 && (int)$st['captcha'] === 1, json_encode($st));
$travel('comment', $kB, 120);
$c1 = $ask('comment', $sB, 'the ninth, no token', ['verify' => $verify], $cfgCap);
check('the next one, once its pause is over, asks for a CAPTCHA: 428, captcha_required, the provider and its public key',
      !$okr($c1) && $c1['kind'] === 'captcha' && $c1['status'] === 428 && ($c1['body']['error'] ?? '') === 'captcha_required'
      && !empty($c1['body']['captcha_required']) && ($c1['body']['captcha'] ?? []) === ['provider' => 'recaptcha', 'site_key' => 'site-key-test'],
      json_encode($c1['body']));
$c2 = $ask('comment', $sB, 'the ninth, a bad token', ['verify' => $verify, 'input' => ['captcha_token' => 'bad-token']], $cfgCap);
check('a token the provider refuses: captcha_failed, asked again', $c2['kind'] === 'captcha' && ($c2['body']['error'] ?? '') === 'captcha_failed');
$c3 = $ask('comment', $sB, 'the ninth, solved', ['verify' => $verify, 'input' => ['captcha_token' => 'good-token']], $cfgCap);
$st = $row('comment', $kB);
check('solved: written, and the ladder eases — the next pause is the FIRST step again, the hits and the demand gone',
      $okr($c3) && (int)$st['captcha'] === 0 && (int)$st['top_hits'] === 0 && (int)$st['level'] === 2
      && antispamPauseBefore(antispamLadder($cfgCap, 'comment'), (int)$st['level'] + 1) === 15, json_encode($st));
$C = asUser($db, $cfgT, 'astest_d');
$sC = $subj($C);
$kC = 'u:' . $C;
foreach ([0, 0, 15, 30, 60, 120] as $i => $gap) { if ($gap) $travel('comment', $kC, $gap); $ask('comment', $sC, "climbing $i to the top", [], $cfgCap); }
$h1 = $ask('comment', $sC, 'hammering the top, one', [], $cfgCap);
$h2 = $ask('comment', $sC, 'hammering the top, two', [], $cfgCap);
$st = $row('comment', $kC);
check('attempts refused while waiting at the top count as hits too (a script hammering meets the CAPTCHA sooner)',
      $h1['kind'] === 'wait' && $h2['kind'] === 'wait' && (int)$st['top_hits'] === 3 && (int)$st['captcha'] === 1, json_encode($st));
$gS = antispamSubject(null, AS_GUEST_IP);
$g1 = $ask('comment', $gS, 'a guest writes a comment', ['verify' => $verify], $cfgCap);
check('a GUEST: a CAPTCHA before the first comment — in words for a guest', $g1['kind'] === 'captcha' && ($g1['body']['message'] ?? '') === __('api.antispam.captcha_guest'));
$g2 = $ask('comment', $gS, 'a guest writes a comment', ['verify' => $verify, 'input' => ['captcha_token' => 'good-token']], $cfgCap);
$g3 = $ask('comment', $gS, 'a guest writes another comment', ['verify' => $verify], $cfgCap);
check('… solved, written; the next one asks AGAIN: every time', $okr($g2) && $g3['kind'] === 'captcha');
$g4 = $ask('description', $gS, "a guest's description of a torrent", ['verify' => $verify], $cfgCap);
check('… a description too', $g4['kind'] === 'captcha');
check('… not a vote: a guest\'s vote meets the escalation like anybody\'s, never "every time"', $okr($ask('vote', $gS, null, [], $cfgCap)));
check('… and with the guests\' switch off, not at all', $okr($ask('comment', antispamSubject(null, '198.51.100.78'), 'a guest, the switch off', [], array_merge($cfgCap, ['antispam_guest_captcha' => '0']))));
$D0 = asUser($db, $cfgT, 'astest_e');
$sD0 = $subj($D0);
foreach ([0, 0, 15, 30, 60, 120, 120, 120, 120, 120] as $i => $gap) { if ($gap) $travel('comment', 'u:' . $D0, $gap); $last = $ask('comment', $sD0, "no provider $i", []); }
check('NO PROVIDER: the pauses alone — ten comments up and at the top, never a CAPTCHA',
      $okr($last) && (int)$row('comment', 'u:' . $D0)['captcha'] === 0 && $ask('comment', $sD0, 'no provider, too soon')['kind'] === 'wait');
check('… and a guest\'s words wait on the pauses only', $okr($ask('description', antispamSubject(null, '198.51.100.79'), 'a guest, no provider at all')));

/* ══ 5. new accounts ══════════════════════════════════════════════════════════════════════════ */
$N = asUser($db, $cfgT, 'astest_new', 0);
$sN = $subj($N);
check('an account younger than antispam_new_days is new', antispamIsNew($db, $cfgT, $sN) && !antispamIsNew($db, $cfgT, $sA)
      && !antispamIsNew($db, array_merge($cfgT, ['antispam_new_days' => '0']), $sN));
for ($i = 1; $i <= 3; $i++) $ask('shout', $sN, "a new account's line $i");
$wN = $ask('shout', $sN, "a new account's fourth line");
check('… its pauses are twice as long (the room\'s first step: 10 s)', $wN['kind'] === 'wait' && $wN['seconds'] > 5 && $wN['seconds'] <= 10, json_encode($wN['seconds']));
$O = asUser($db, $cfgT, 'astest_old', 10);
$shoutAt = function (int $uid, string $body, int $daysAgo) use ($db): int {
    $db->prepare("INSERT INTO shouts (user_id, body, body_format, created_at) VALUES (?, ?, 'bbcode', NOW() - INTERVAL ? DAY)")->execute([$uid, $body, $daysAgo]);
    return (int)$db->lastInsertId();
};
$s1 = $shoutAt($O, 'written on day one: https://example.org/one and [url=https://example.org/two]two[/url]', 9);
$s2 = $shoutAt($O, 'written today: https://example.org/three', 0);
$rows = [];
foreach (shoutRows($db, $cfgT, [], 5, $s1 - 1) as $rr) $rows[(int)$rr['id']] = (string)$rr['html'];
check('a line written while the account was NEW: its links are words — the address, the words of a [url], no <a href>',
      isset($rows[$s1]) && !str_contains($rows[$s1], '<a ') && str_contains($rows[$s1], 'https://example.org/one') && str_contains($rows[$s1], 'two'),
      $rows[$s1] ?? '(none)');
check('… and one the same account wrote once trusted is a link (a link written on day one stays text on day ten)',
      isset($rows[$s2]) && str_contains($rows[$s2], 'href="https://example.org/three"'), $rows[$s2] ?? '(none)');
$db->prepare("UPDATE shouts SET edited_at = NOW(), edited_by = ? WHERE id = ?")->execute([$O, $s1]);
$re = shoutRows($db, $cfgT, [], 1, $s1 - 1)[0] ?? [];
check('… corrected by the (trusted) author since: re-dated, its links live', str_contains((string)($re['html'] ?? ''), 'href="https://example.org/one"'));
$s3 = $shoutAt($O, 'also written on day one: https://example.org/four', 9);
check('the rule off: a day-one line is a link too', str_contains((string)(shoutRows($db, array_merge($cfgT, ['antispam_new_links' => '0']), [], 1, $s3 - 1)[0]['html'] ?? ''), 'href="https://example.org/four"')
      && !str_contains((string)(shoutRows($db, $cfgT, [], 1, $s3 - 1)[0]['html'] ?? ''), 'href='));
$db->prepare("INSERT IGNORE INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Anti-spam test', 'done')")->execute([AS_HASH]);
$db->prepare("INSERT INTO hash_comments (info_hash, user_id, body, body_format, status, created_at) VALUES (?, ?, ?, 'bbcode', 'visible', NOW() - INTERVAL 9 DAY)")
   ->execute([AS_HASH, $O, 'a comment from day one [url=https://example.org/c]here[/url]']);
$cmOld = (int)$db->lastInsertId();
$db->prepare("INSERT INTO hash_comments (info_hash, user_id, body, body_format, status) VALUES (?, ?, ?, 'bbcode', 'visible')")
   ->execute([AS_HASH, $O, 'a comment from today [url=https://example.org/d]there[/url]']);
$cmNew = (int)$db->lastInsertId();
$shaped = [];
foreach (commentShape($db, $cfgT, null, [commentRow($db, $cmOld), commentRow($db, $cmNew)]) as $cr) $shaped[(int)$cr['id']] = (string)$cr['html'];
check('a comment written while new: its [url] is the text it was typed as; one written later is a link',
      !str_contains($shaped[$cmOld] ?? '', '<a ') && str_contains($shaped[$cmOld] ?? '', '[url=https://example.org/c]')
      && str_contains($shaped[$cmNew] ?? '', 'href="https://example.org/d"'), json_encode($shaped));
$db->prepare("UPDATE users SET bio = ?, bio_updated_at = NOW() - INTERVAL 9 DAY WHERE id = ?")->execute(['About me [url=https://example.org/b]site[/url]', $O]);
$bioOld = profileBioFor($db, $cfgT, $u($O));
$db->prepare("UPDATE users SET bio_updated_at = NOW() WHERE id = ?")->execute([$O]);
$bioNew = profileBioFor($db, $cfgT, $u($O));
check('the profile\'s description the same way: words while new, a link once written trusted',
      $bioOld !== '' && !str_contains($bioOld, '<a') && str_contains($bioOld, 'site') && str_contains($bioNew, 'href="https://example.org/b"'), json_encode([$bioOld, $bioNew]));
$ld = listDescRender($db, $cfgT, 'See [url=https://example.org/l]this[/url] and https://example.org/m', 'bbcode', true);
check('a list\'s description written while new: no link at all', $ld !== '' && !str_contains($ld, '<a') && str_contains($ld, 'https://example.org/m'), $ld);
check('… decided in the list window\'s query from the list\'s own time', str_contains($src('api/user_list_items.php'), 'TIMESTAMPDIFF(SECOND, u.created_at, l.updated_at) AS author_age_s')
      && str_contains($src('api/user_list_items.php'), "antispamWrittenNew(\$db, \$cfg, (int)\$list['user_id'], \$list['author_age_s'] ?? null)"));
check('the renderer\'s one door: every rich-text link goes through richtextLinkAttrs(), which gives none under rt_links_text; [email] asks too',
      richtextLinkAttrs('https://example.org/x', ['rt_links_text' => '1']) === '' && richtextLinkAttrs('https://example.org/x', []) !== ''
      && !str_contains(richtextRender('[email]a@example.org[/email]', 'bbcode', ['rt_links_text' => '1'] + $cfgT), 'mailto:'));
$M = asUser($db, $cfgT, 'astest_mod', 0);
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) SELECT ?, id, '2000-01-01 00:00:00' FROM user_groups WHERE slug = 'moderator'")->execute([$M]);
userPermissionsForget($M);
check('staff are never new — while the exemption is on', !antispamIsNew($db, $cfgT, $subj($M)) && !antispamWrittenNew($db, $cfgT, $M, 10)
      && antispamIsNew($db, array_merge($cfgT, ['antispam_staff_exempt' => '0']), $subj($M)));
check('the Preview asks the same: a new account\'s links would be words, an old one\'s not', antispamLinksTextNow($db, $cfgT, $u($N)) && !antispamLinksTextNow($db, $cfgT, $u($O)));

/* ══ 6. duplicates ════════════════════════════════════════════════════════════════════════════ */
$D = asUser($db, $cfgT, 'astest_f');
$sD = $subj($D);
$cfgNoPace = array_merge($cfgT, ['antispam_shout_steps' => '', 'antispam_comment_steps' => '', 'antispam_message_steps' => '']);
$d1 = $ask('shout', $sD, 'hello everybody in the room', [], $cfgNoPace);
$d2 = $ask('shout', $sD, '  HELLO   everybody in the room ', [], $cfgNoPace);
check('the same words again (case and spacing aside): 409, antispam_duplicate', $okr($d1) && $d2['kind'] === 'duplicate' && $d2['status'] === 409
      && ($d2['body']['error'] ?? '') === 'antispam_duplicate');
check('… in another context they are new words', $okr($ask('comment', $sD, 'hello everybody in the room', [], $cfgNoPace)));
$travel('shout', 'u:' . $D, 600);
check('… and after the window, allowed again', $okr($ask('shout', $sD, 'hello everybody in the room', [], $cfgNoPace)));
check('fewer than eight characters are never a duplicate ("hi", "+1")', $okr($ask('shout', $sD, 'hi hi', [], $cfgNoPace)) && $okr($ask('shout', $sD, 'hi hi', [], $cfgNoPace)));
$m1 = $ask('message', $sD, 'buy my stuff at a great price', ['target' => 'u:1'], $cfgNoPace);
$m2 = $ask('message', $sD, 'buy my stuff at a great price', ['target' => 'u:1'], $cfgNoPace);
$m3 = $ask('message', $sD, 'buy my stuff at a great price', ['target' => 'u:4'], $cfgNoPace);
$m4 = $ask('message', $sD, 'buy my stuff at a great price', ['target' => 'u:6'], $cfgNoPace);
check('a message: the same words to the same person twice is a duplicate; to a second person fine; to a THIRD, spam',
      $okr($m1) && $m2['kind'] === 'duplicate' && $okr($m3) && $m4['kind'] === 'spread' && ($m4['body']['error'] ?? '') === 'antispam_spread',
      json_encode([$m1['ok'] ?? null, $m2['kind'] ?? null, $m3['ok'] ?? null, $m4['kind'] ?? null]));

/* ══ 7. the messages' new conversations ═══════════════════════════════════════════════════════ */
$S = asUser($db, $cfgT, 'astest_s');
$R = [];
foreach (['astest_r1', 'astest_r2', 'astest_r3', 'astest_r4'] as $nm) $R[] = asUser($db, $cfgT, $nm);
$startWith = function (int $from, int $to, string $body) use ($db): void {
    $t = pmThreadFor($db, $from, $to);
    $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, 'bbcode')")->execute([(int)$t['id'], $from, $body]);
};
$startWith($S, $R[0], 'hello one');
$startWith($S, $R[1], 'hello two');
$startWith($R[3], $S, 'I wrote first');
$startWith($S, $R[3], 'and this is a reply');
check('the conversations an account STARTED — a reply to somebody who wrote first is never one', count(antispamConversationsStarted($db, $S, 3600)) === 2);
$cfgPm = array_merge($cfgT, ['antispam_pm_new_hour' => '2', 'antispam_message_steps' => '']);
$cv = $ask('message', $subj($S), 'hello three, a new person', ['target' => 'u:' . $R[2], 'new' => true], $cfgPm);
check('a third NEW conversation this hour with a limit of two: refused, with how long until one frees',
      $cv['kind'] === 'conversations' && ($cv['body']['error'] ?? '') === 'antispam_conversations' && $cv['seconds'] >= 3500 && $cv['seconds'] <= 3600
      && str_contains((string)$cv['body']['message'], '(2)'), json_encode($cv['body'] ?? $cv));
check('… a reply in a conversation that exists is not a new one, whatever the count', $okr($ask('message', $subj($S), 'another reply here', ['target' => 'u:' . $R[3], 'new' => false], $cfgPm)));
$cvD = $ask('message', $subj($S), 'hello three, a new person', ['target' => 'u:' . $R[2], 'new' => true],
            array_merge($cfgT, ['antispam_pm_new_hour' => '0', 'antispam_pm_new_day' => '2', 'antispam_message_steps' => '']));
check('… the day\'s limit the same way (and an hour\'s 0 is no hour\'s limit)', $cvD['kind'] === 'conversations' && str_contains((string)$cvD['body']['message'], '24'));
$NN = asUser($db, $cfgT, 'astest_g', 0);
$startWith($NN, $R[0], 'a new account says hello');
$startWith($NN, $R[1], 'and again to someone else');
$cvN = $ask('message', $subj($NN), 'a third one', ['target' => 'u:' . $R[2], 'new' => true], array_merge($cfgT, ['antispam_message_steps' => '']));
check('a NEW account: two new conversations an hour (antispam_pm_new_hour_new), said as a new account\'s limit',
      $cvN['kind'] === 'conversations' && str_contains((string)$cvN['body']['message'], 'new account'), json_encode($cvN['body'] ?? $cvN));
$lp = antispamLadder($cfgT, 'message');
check('the message ladder counts conversations started: three, then 30 s, 1 min, 2 min, 5 min — lines in a conversation are free',
      $lp['burst'] === 3 && $lp['steps'] === [30, 60, 120, 300] && $okr($ask('message', $subj($S), 'reply one', ['target' => 'u:' . $R[3]]))
      && $okr($ask('message', $subj($S), 'reply two', ['target' => 'u:' . $R[3]])) && $okr($ask('message', $subj($S), 'reply three', ['target' => 'u:' . $R[3]]))
      && $okr($ask('message', $subj($S), 'reply four', ['target' => 'u:' . $R[3]])));

/* ══ 8. staff ═════════════════════════════════════════════════════════════════════════════════ */
$sM = $subj($M);
$allOk = true;
for ($i = 1; $i <= 8; $i++) $allOk = $allOk && $okr($ask('shout', $sM, "a moderator's line number $i"));
check('staff (panel.access): eight lines at once, no pause', $allOk && antispamIsStaff($db, $cfgT, $M));
check('… the same words twice are still refused, staff or not', $ask('shout', $sM, "a moderator's line number 8")['kind'] === 'duplicate');
$cfgNoEx = array_merge($cfgT, ['antispam_staff_exempt' => '0']);
$db->prepare("DELETE FROM antispam_state WHERE subject = ?")->execute(['u:' . $M]);
for ($i = 1; $i <= 3; $i++) $ask('shout', $sM, "paced like anybody, $i", [], $cfgNoEx);
check('… and with the exemption off, paced like anybody (and new like anybody: 10 s)', $ask('shout', $sM, 'paced like anybody, 4', [], $cfgNoEx)['kind'] === 'wait');

/* ══ 9. corrections ═══════════════════════════════════════════════════════════════════════════ */
$E = asUser($db, $cfgT, 'astest_ed');
$sE = $subj($E);
$e1 = $ask('shout', $sE, null, ['mode' => 'edit', 'edit_gap' => 5]);
$e2 = $ask('shout', $sE, null, ['mode' => 'edit', 'edit_gap' => 5]);
check('a correction: a gap between two (the room\'s shout_flood_seconds), in the correction\'s own words',
      $okr($e1) && $e2['kind'] === 'wait' && $e2['seconds'] <= 5 && ($e2['body']['message'] ?? '') !== '' && str_contains((string)$e2['body']['message'], __('api.antispam.wait_edit', ['time' => antispamTimeText($e2['seconds'])])));
$travel('shout', 'u:' . $E, 5);
check('… not laddered: the same gap every time, and posting is untouched by corrections', $okr($ask('shout', $sE, null, ['mode' => 'edit', 'edit_gap' => 5]))
      && (int)$row('shout', 'u:' . $E)['level'] === 0 && $okr($ask('shout', $sE, 'a line after corrections')));
check('… never a CAPTCHA, never a duplicate', $okr($ask('comment', $sE, null, ['mode' => 'edit'], $cfgCap)));
$db->prepare("DELETE FROM antispam_state WHERE subject = ?")->execute(['u:' . $E]);
$z = $ask('shout', $sE, null, ['mode' => 'edit', 'edit_gap' => 0]);
$zAt = (int)($row('shout', 'u:' . $E)['edit_at'] ?? 0);
$w = $ask('shout', $sE, null, ['mode' => 'edit', 'edit_gap' => 60]);
check('… the room remembers a correction made with no gap (the wall at 0, or staff): a wall set afterwards counts from it',
      $okr($z) && $zAt > 0 && ($w['kind'] ?? '') === 'wait' && ($w['seconds'] ?? 0) > 50 && ($w['seconds'] ?? 0) <= 60, json_encode([$zAt, $w['kind'] ?? 'ok', $w['seconds'] ?? null]));

/* ══ 10. a reservation handed back ═════════════════════════════════════════════════════════════ */
$F = asUser($db, $cfgT, 'astest_h');
$sF = $subj($F);
$ask('comment', $sF, 'the first comment, kept');
$before = $row('comment', 'u:' . $F);
$t2 = $ask('comment', $sF, 'a comment whose words are refused');
antispamRelease($db, $t2['ticket']);
$after = $row('comment', 'u:' . $F);
check('words refused after the check: the reservation is handed back (the level, the last write, the fingerprints as they were)',
      $okr($t2) && (int)$after['level'] === (int)$before['level'] && (int)$after['last_at'] === (int)$before['last_at'] && $after['recent'] === $before['recent']);
check('… so the same words may be sent at once, not "a duplicate"', $okr($ask('comment', $sF, 'a comment whose words are refused')));
$cfgNP = array_merge($cfgT, ['antispam_comment_steps' => '']);
$ta = $ask('comment', $sF, 'reservation A, handed back late', [], $cfgNP);
$tb = $ask('comment', $sF, 'reservation B, written meanwhile', [], $cfgNP);
antispamRelease($db, $ta['ticket'] ?? null);
check('… but never over a write somebody made since (the revision moved): nothing is undone then',
      $okr($ta) && $okr($tb) && (int)$row('comment', 'u:' . $F)['level'] === 4, json_encode($row('comment', 'u:' . $F)));

/* ══ 11. failing CLOSED ═══════════════════════════════════════════════════════════════════════ */
check('a database that cannot answer (no table): REFUSED — 503 antispam_unavailable, never waved through',
      is_array($noTable) && empty($noTable['ok']) && ($noTable['kind'] ?? '') === 'unavailable' && ($noTable['status'] ?? 0) === 503
      && ($noTable['body']['error'] ?? '') === 'antispam_unavailable', json_encode($noTable));
check('an unknown context is refused too', ($ask('nowhere', $sF, 'x')['kind'] ?? '') === 'unavailable');

/* ══ 12. the audit, once an hour ═══════════════════════════════════════════════════════════════ */
$G = asUser($db, $cfgT, 'astest_auth');
$sG = $subj($G);
for ($i = 1; $i <= 3; $i++) $ask('shout', $sG, "auditing line $i");
$floorA = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
for ($i = 0; $i < 6; $i++) $ask('shout', $sG, 'hammering');
$lines = $db->query("SELECT action, action_group, target_type, target_id FROM audit_log WHERE id > $floorA AND action = 'antispam.refuse'")->fetchAll(PDO::FETCH_ASSOC);
check('refused three times in a row: ONE audit line (antispam.refuse, the Reports group, the subject), however many more follow',
      count($lines) === 1 && $lines[0]['action_group'] === 'reports' && $lines[0]['target_type'] === 'antispam' && $lines[0]['target_id'] === 'u:' . $G,
      json_encode($lines));
check('… the throttle is the subject\'s own "*" row', (int)($row('*', 'u:' . $G)['last_at'] ?? 0) > 0);

/* ══ 13. two requests RACING ═══════════════════════════════════════════════════════════════════ */
$racer = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'as_racer_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $racer;
file_put_contents($racer, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/users.php", "includes/audit.php", "includes/lang.php", "includes/antispam.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
langInit($cfg, "en");
$me = userFindById($db, (int)$a["uid"]);
while (microtime(true) < (float)$a["at"]) usleep(2000);
$r = antispamCheck($db, $cfg, $a["ctx"], antispamSubject($me, "127.0.0.9"), $a["text"]);
echo json_encode(["ok" => !empty($r["ok"]), "kind" => $r["kind"] ?? ""]);
');
$race = function (int $uid, string $ctx, string $text, array $cfgX) use ($root, &$tmpFiles): array {
    $at = microtime(true) + 1.5;
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'as_race_' . bin2hex(random_bytes(4)) . '.json';
        $tmpFiles[] = $arg;
        file_put_contents($arg, json_encode(['root' => $root, 'uid' => $uid, 'ctx' => $ctx, 'text' => $text, 'cfg' => $cfgX, 'at' => $at]));
        $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', $GLOBALS['racer'], $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $o = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        $out[] = json_decode(trim((string)$o), true) ?: ['raw' => substr((string)$o, 0, 300)];
    }
    return $out;
};
$GLOBALS['racer'] = $racer;
$RA = asUser($db, $cfgT, 'astest_race');
$cfgRace = ['antispam_enabled' => '1', 'antispam_comment_burst' => '1', 'antispam_comment_steps' => '60', 'antispam_comment_reset' => '300',
            'antispam_new_days' => '0', 'antispam_dup_seconds' => '600', 'recaptcha_enabled' => '0', 'users_enabled' => '1'];
$res = $race($RA, 'comment', 'racing for the one free comment', $cfgRace);
$oks = count(array_filter($res, fn($x) => !empty($x['ok'])));
$kinds = array_map(fn($x) => $x['kind'] ?? '', $res);
check('two requests at the same instant for the ONE free comment: exactly one written, the other told to wait (no deadlock, no double)',
      $oks === 1 && in_array('wait', $kinds, true) && !in_array('unavailable', $kinds, true) && (int)$row('comment', 'u:' . $RA)['level'] === 1, json_encode($res));
$RB = asUser($db, $cfgT, 'astest_race2');
$res2 = $race($RB, 'comment', 'the same words from two tabs at once', array_merge($cfgRace, ['antispam_comment_burst' => '5']));
$oks2 = count(array_filter($res2, fn($x) => !empty($x['ok'])));
check('… and the same words sent twice at once (a double click): one written, the other a duplicate',
      $oks2 === 1 && in_array('duplicate', array_map(fn($x) => $x['kind'] ?? '', $res2), true), json_encode($res2));

/* ══ 14. the endpoint files, as requests ════════════════════════════════════════════════════════ */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'as_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/index.php", "includes/richtext.php", "includes/content.php", "includes/mail.php",
          "includes/users.php", "includes/favourites.php", "includes/usermedia.php", "includes/sounds.php", "includes/shout.php",
          "includes/emoji.php", "includes/people.php", "includes/audit.php", "includes/auth.php", "includes/comments.php",
          "includes/reports.php", "includes/lists.php", "includes/profilebio.php", "includes/reputation.php", "includes/lang.php",
          "includes/antispam.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, "en");
$GLOBALS["__audit_endpoint"] = $a["endpoint"];
require $a["file"];
');
$GLOBALS['runner'] = $runner;
$call = function (string $endpoint, array $post, array $session, array $cfgX, string $ip = '127.0.0.9') use ($root, &$tmpFiles): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'as_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'file' => $root . '/api/' . $endpoint . '.php', 'method' => 'POST',
        'post' => $post, 'get' => [], 'session' => $session, 'cfg' => $cfgX, 'ip' => $ip, 'sid' => 'astest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($GLOBALS['runner']) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 600)];
};
$cfgHttp = [];
foreach ($cfgT as $k => $v) if (str_starts_with($k, 'antispam_')) $cfgHttp[$k] = $v;
$cfgHttp += ['users_enabled' => '1', 'users_require_email_verify' => '0', 'shout_enabled' => '1', 'shout_flood_seconds' => '5',
             'recaptcha_enabled' => '0', 'pm_enabled' => '1', 'pm_who' => 'all', 'pm_max_per_day' => '50', 'rate_limit_pm' => '240',
             'comments_enabled' => '1', 'index_enabled' => '1', 'index_search_enabled' => '1', 'index_search_include_whitelist' => '1'];
$H = asUser($db, $cfgT, 'astest_http');
$sess = ['user_id' => $H, 'user_login_time' => time(), 'csrf_token' => 'as-child-token'];
$sh = [];
for ($i = 1; $i <= 4; $i++) $sh[] = $call('shout_post', ['csrf_token' => 'as-child-token', 'body' => "line $i over http", 'format' => 'bbcode'], $sess, $cfgHttp);
check('shout_post as a request: three lines, the fourth answered by the layer — the room\'s code (flood), the seconds, the sentence, the countdown\'s template',
      !empty($sh[0]['success']) && !empty($sh[2]['success']) && ($sh[3]['error'] ?? '') === 'flood' && (int)($sh[3]['retry_after'] ?? 0) > 0
      && ($sh[3]['antispam']['kind'] ?? '') === 'wait' && str_contains((string)($sh[3]['message'] ?? ''), 'room')
      && str_contains((string)($sh[3]['antispam']['tpl'] ?? ''), '{time}'), json_encode($sh[3]));
$HR = [asUser($db, $cfgT, 'astest_hr1'), asUser($db, $cfgT, 'astest_hr2'), asUser($db, $cfgT, 'astest_hr3')];
$pm = [];
foreach (['astest_hr1', 'astest_hr2', 'astest_hr3'] as $i => $to) {
    $pm[] = $call('user_messages', ['csrf_token' => 'as-child-token', 'op' => 'send', 'to' => $to, 'body' => "Hello number $i, nice to meet you", 'format' => 'bbcode'],
                  $sess, array_merge($cfgHttp, ['antispam_pm_new_hour' => '2', 'antispam_message_steps' => '']));
}
check('user_messages send as a request: two new conversations, the third refused by the hour\'s limit (429 antispam_conversations)',
      !empty($pm[0]['success']) && !empty($pm[1]['success']) && ($pm[2]['error'] ?? '') === 'antispam_conversations'
      && (int)($pm[2]['retry_after'] ?? 0) > 3000, json_encode($pm));
check('… and the refused one was not written (the send\'s own transaction held the count and the insert)',
      (int)$db->query("SELECT COUNT(*) FROM user_messages WHERE sender_id = $H")->fetchColumn() === 2);
$db->exec("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\"comment.view\":true,\"comment.post\":true,\"index.view\":true}') WHERE slug = 'guest'");
$gc = $call('comment_post', ['csrf_token' => 'as-guest-token', 'hash' => AS_HASH, 'body' => 'A guest would like to comment here.'],
            ['csrf_token' => 'as-guest-token'], array_merge($cfgHttp, ['recaptcha_enabled' => '1', 'captcha_provider' => 'hcaptcha',
            'hcaptcha_site_key' => '10000000-ffff-ffff-ffff-000000000001', 'hcaptcha_secret' => 'unused-here']), '198.51.100.80');
check('comment_post as a GUEST\'s request: 428 captcha_required, with the provider and the public key the page draws its box from',
      ($gc['error'] ?? '') === 'captcha_required' && !empty($gc['captcha_required'])
      && ($gc['captcha'] ?? []) === ['provider' => 'hcaptcha', 'site_key' => '10000000-ffff-ffff-ffff-000000000001'], json_encode($gc));
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([$guestBefore]);
$RP = asUser($db, $cfgT, 'astest_rep');
$sh2 = [];
for ($i = 1; $i <= 4; $i++) {
    $db->prepare("INSERT INTO shouts (user_id, body, body_format) VALUES (?, ?, 'bbcode')")->execute([$H, "reportable line $i"]);
    $sid = (int)$db->lastInsertId();
    $sh2[] = $call('content_report', ['csrf_token' => 'as-child-token', 'kind' => 'shout', 'id' => $sid, 'reason' => "spam number $i"],
                   ['user_id' => $RP, 'user_login_time' => time(), 'csrf_token' => 'as-child-token'], $cfgHttp);
}
check('content_report as requests: three reports, the fourth paced by the layer (429 antispam_wait)',
      !empty($sh2[0]['success']) && !empty($sh2[2]['success']) && ($sh2[3]['error'] ?? '') === 'antispam_wait', json_encode($sh2[3]));

/* ══ 15. every writer endpoint calls the layer ═══════════════════════════════════════════════ */
$strip = function (string $php): string {
    $out = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$fnBody = function (string $file, string $name) use ($src, $strip): string {
    $s = $strip($src($file));
    $at = strpos($s, 'function ' . $name . '(');
    if ($at === false) return '';
    $end = strpos($s, "\n}\n", $at);
    return substr($s, $at, ($end === false ? strlen($s) : $end) - $at);
};
// endpoint => [the door it calls ('' = the endpoint itself asks), the file of that door]
$writers = [
    'api/shout_post.php'         => ['shoutPost', 'includes/shout.php'],
    'api/shout_edit.php'         => ['shoutEdit', 'includes/shout.php'],
    'api/user_messages.php'      => ['', ''],
    'api/comment_post.php'       => ['commentPostRequest', 'includes/comments.php'],
    'api/comment_edit.php'       => ['commentEditRequest', 'includes/comments.php'],
    'api/content_submit.php'     => ['', ''],
    'api/whitelist_submit.php'   => ['', ''],
    'api/user_lists.php'         => ['', ''],
    'api/profile_bio.php'        => ['profileBioSaveRequest', 'includes/profilebio.php'],
    'api/content_report.php'     => ['contentReportRequest', 'includes/reports.php'],
    'api/shout_emote_upload.php' => ['', ''],
    'api/rate_hash.php'          => ['', ''],
];
$gaps = [];
foreach ($writers as $ep => [$door, $doorFile]) {
    $code = $strip($src($ep));
    if ($door === '') {
        if (!str_contains($code, 'antispamCheck(') && !str_contains($code, 'contentReportFloodCheck(')) $gaps[] = "$ep: no antispamCheck";
        if (!str_contains($code, 'antispamRecord(')) $gaps[] = "$ep: no antispamRecord";
        continue;
    }
    if (!str_contains($code, $door . '(')) { $gaps[] = "$ep: does not call $door()"; continue; }
    $body = $fnBody($doorFile, $door);
    $flood = $door === 'commentPostRequest' || $door === 'commentEditRequest' ? $fnBody('includes/comments.php', 'commentFloodCheck')
           : ($door === 'contentReportRequest' ? $fnBody('includes/reports.php', 'contentReportFloodCheck') : '');
    if (!str_contains($body . $flood, 'antispamCheck(')) $gaps[] = "$door: no antispamCheck";
    if (!str_contains($body, 'antispamRecord(') || !str_contains($body . $flood, 'antispamRelease(')) $gaps[] = "$door: record/release";
}
check('every endpoint where people write asks the layer — directly or through its one door — and records the write (and hands back a refused one)',
      $gaps === [], implode('; ', $gaps));
$userMsgs = $strip($src('api/user_messages.php'));
check('… the message send inside its own transaction, committing a refusal\'s bookkeeping; a message report through the reports\' one call',
      str_contains($userMsgs, "antispamCheck(\$db, \$cfg, 'message'") && str_contains($userMsgs, "if (\$db->inTransaction()) \$db->commit();")
      && str_contains($userMsgs, 'contentReportFloodCheck($db, $cfg, $me, getClientIp($cfg), $input, $rticket)'));
// Discovered, not listed: any public endpoint that writes what people wrote must be one of the above.
$markers = '/INSERT INTO (shouts|user_messages|hash_comments|wl_content_edits|user_lists|content_reports|message_reports|shout_emotes)\b'
         . '|UPDATE (shouts|hash_comments|user_lists) SET (body|name|description)'
         . '|\b(shoutPost|shoutEdit|contentAttach|commentPostRequest|commentEditRequest|contentReportRequest|profileBioSaveRequest|listEditRequest|shoutEmoteStore|repCastVote|repRemoveVote)\(/';
$found = [];
foreach (glob($root . '/api/*.php') as $f) {
    $rel = 'api/' . basename($f);
    if (preg_match($markers, $strip((string)file_get_contents($f)))) $found[] = $rel;
}
$unlisted = array_values(array_diff($found, array_keys($writers)));
check('read from api/*.php: every public endpoint that writes people\'s words is one of the ' . count($writers) . ' (' . count($found) . ' found)',
      $unlisted === [] && count($found) === count($writers), implode(', ', $unlisted) . ' | found ' . implode(', ', $found));

/* ══ 16. the leftovers ═══════════════════════════════════════════════════════════════════════ */
$rh = $strip($src('api/rate_hash.php'));
check('votes: the dead gate (a recaptcha_on_vote no setting defines) is gone; a vote asks the layer, after the refusal is peeked',
      !str_contains($rh, "isCaptchaRequired(\$cfg, 'vote')") && str_contains($rh, "antispamCheck(\$db, \$cfg, 'vote'")
      && strpos($rh, 'repVoteRefusal($db, $cfg)) !== null') < strpos($rh, "antispamCheck(\$db, \$cfg, 'vote'"));
$VV = asUser($db, $cfgT, 'astest_vote');
$sV = $subj($VV);
foreach (array_merge(array_fill(0, 10, 0), [2, 5, 10, 30, 30, 30]) as $i => $gap) { if ($gap) $travel('vote', 'u:' . $VV, $gap); $ask('vote', $sV, null, [], $cfgCap); }
$travel('vote', 'u:' . $VV, 30);
check('… and a vote at the top of its ladder meets a CAPTCHA at last', $ask('vote', $sV, null, [], $cfgCap)['kind'] === 'captcha');
check('"@name." is a mention of name — the full stop, a comma, a colon, ! and ? are not part of it; a dot inside a name is',
      shoutMentionCandidates('bob..') === ['bob..', 'bob.', 'bob'] && shoutMentionCandidates('john.doe') === ['john.doe']
      && str_contains(shoutLinkMentions('hi @astest_a.', ['astest_a' => 'astest_a'], '/'), '>@astest_a</a>.')
      && shoutParseMentions($db, 'thanks @astest_a. and @astest_a, and @astest_a!', $B) === [$A]);
check('… both suggestion lists stop at a full stop after a name',
      str_contains($src('assets/js/shoutbox.js'), '/(?:^|[^\w@])@((?:[A-Za-z0-9_.-]{0,31}[A-Za-z0-9_])?)$/')
      && str_contains($src('assets/js/comments.js'), '/(?:^|[^\w@])@((?:[A-Za-z0-9_.-]{0,31}[A-Za-z0-9_])?)$/'));
check('tests/sounds_test.php deletes its accounts through the cascade, never a bare DELETE FROM users (its code, not its comment)',
      !str_contains($strip($src('tests/sounds_test.php')), 'DELETE FROM users') && str_contains($src('tests/sounds_test.php'), 'userDeleteCascade($db, $id)'));
check('an account going takes its anti-spam rows with it', str_contains($fnBody('includes/users.php', 'userDeleteCascade'), "DELETE FROM antispam_state WHERE subject = ?"));

/* ══ 17. the pages' half ═════════════════════════════════════════════════════════════════════ */
$js = $src('assets/js/antispam.js');
$layout = $src('templates/layout.php');
check('assets/js/antispam.js: send (a CAPTCHA, the same request again; a wait counted down), countdown, solve, waiting',
      // 1.73.0: `sentence` — the refusal's words by the key its answer names (they follow the live language switch)
      str_contains($js, 'window.Antispam = { send, countdown, solve, isCaptcha, waitSeconds, waiting, stop, timeText, clock, sentence };')
      && str_contains($js, "button.setAttribute('data-as-wait', clock(left));") && !preg_match('/button\.(textContent|innerHTML|appendChild)/', $js));
$tagAt = fn(string $f): int => (int)strpos($layout, '<script src="<?= $baseUrl ?>assets/js/' . $f . '<?=');
check('… on every public page, after captcha.js and before app.js', $tagAt('captcha.js') > 0 && $tagAt('captcha.js') < $tagAt('antispam.js')
      && $tagAt('antispam.js') < $tagAt('app.js'));
check('the countdown is drawn by CSS, the button keeping its width', str_contains($src('assets/css/style.css'), 'button.as-waiting[data-as-wait]::after {')
      && str_contains($src('assets/css/style.css'), 'content: attr(data-as-wait);'));
check('captcha.js draws the box and loads the provider on demand, from the answer', str_contains($src('assets/js/captcha.js'), 'window.captchaEnsure = function (info)')
      && str_contains($src('assets/js/captcha.js'), 'https://js.hcaptcha.com/1/api.js?onload=onCaptchaApiLoad&render=explicit'));
$composers = ['assets/js/shoutbox.js' => 3, 'assets/js/people.js' => 2, 'assets/js/comments.js' => 2, 'assets/js/app.js' => 2,
              'assets/js/profile-bio.js' => 1, 'assets/js/reports.js' => 1];
$short = [];
foreach ($composers as $f => $min) if (substr_count($src($f), 'Antispam.send(') + substr_count($src($f), 'anti.send(') < $min) $short[] = $f;
check('every composer sends through it: the room (line, sticker, correction, emote), messages (and their report), comments (and corrections), a description, a vote, the profile, a report, a list',
      $short === [] && str_contains($src('assets/js/favourites.js'), "window.Antispam.send(doPost, { button: button, note: note, action: 'user_lists' })")
      && substr_count($src('assets/js/favourites.js'), 'viaLayer(') >= 4, implode(', ', $short));
check('the guest\'s comment solves first; the CAPTCHA preloads only for a guest who may comment',
      str_contains($src('assets/js/comments.js'), 'solveFirst: !!me.guest && !!me.captcha')
      && str_contains($layout, "\$navUser === null && captchaConfigured(\$cfg)") && str_contains($layout, 'antispamGuestCaptcha($cfg)'));
check('the dictionary: the layer\'s sentences and the pages\' units in both languages', langHas('api.antispam.wait_shout') && langHas('api.antispam.conversations_hour_new')
      && langHas('js.antispam.u_s') && in_array('js.antispam.', LANG_JS_PUBLIC, true));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
