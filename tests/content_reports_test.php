<?php
/**
 * Reports of comments, descriptions and shouts; a warning, loud or silent (1.71.0, includes/reports.php):
 *   php tests/content_reports_test.php
 *
 * The owner: "add reporting comments, descriptions and even shouts — a tab for where each came from, a good
 * filter, permissions; the author told when an admin removes their comment; and a choice whether it is silent
 * or loud (as a warning)". Pinned here:
 *
 *   1. the schema, on BOTH paths — a fresh install's CREATE and an upgrade's guarded statements, from one
 *      definition — the one-open-report-per-member-per-target key, and the grant, once, on a scratch database;
 *   2. the permissions: registered, in the presets, granted by the migration (never the message queue), NO with
 *      accounts off, nothing for guests; the Reports page opening for any of its queues, each tab only its own;
 *   3. reporting (contentReportRequest(), the whole endpoint): every refusal in its order, one open report per
 *      member per target, never your own, the ONE limit call part F replaces, what is kept (the words as
 *      reported), the flags on the rows every page is drawn from;
 *   4. the queue: the kinds a session sees, the open targets per tab, GROUPING (a target once, all its reports),
 *      the five filters, the words through their own renderer (escaped), and as reported when they changed or went;
 *   5. every action on every kind, SILENT and LOUD: who is told what, in THEIR language — the reporters the
 *      outcome with the answer, the author nothing or one warning with the reason — the removal through each
 *      kind's own function, the audit lines; the guards (staff, yourself, a ban without a date, a reason for a
 *      warning, words that changed under the moderator, no account to act on);
 *   6. the message card: the same choice (and Warn) on its endpoint, and no mode = what it always did;
 *   7. warnings: counted, the latest first, on the card and in Users; the member's notification; nowhere public;
 *   8. an account going: its reports and warnings with it, a description's report kept without its author;
 *   9. the endpoint FILES, run as requests in a child process with a session and its token;
 *  10. the source: routes, the permission map, the one limit call, the page's tabs, the public script.
 *
 * Nobody learns who reported: checked on every notification the author gets. Every switch a check leans on is in
 * $cfgOn. Self-cleaning: its accounts (with their comments, shouts, reports, warnings, notifications, threads),
 * its catalogue rows, its temporary groups, its rate-limit keys and its audit lines are removed; the two new
 * tables' counters are put back when they are empty.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/sounds.php', 'includes/shout.php',
          'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/auth.php', 'includes/icons.php',
          'includes/comments.php', 'includes/reports.php', 'includes/lang.php'] as $f) require_once $root . '/' . $f;

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
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1',
    'index_enabled' => '1', 'index_search_enabled' => '1', 'index_search_include_whitelist' => '1',
    'comments_enabled' => '1', 'comment_rate_per_hour' => '1000', 'comment_links' => '1',
    'wl_allow_description' => '1', 'wl_allow_source_url' => '1',
    'shout_enabled' => '1', 'shout_format' => 'bbcode', 'pm_enabled' => '1',
    'recaptcha_enabled' => '0', 'default_language' => 'en',
    // The site's anti-spam layer (1.71.0 part F) is tests/antispam_test.php's: off here, so the reports' own rules
    // — and the hourly limit behind the one call — are what is measured.
    'antispam_enabled' => '0',
]);
$GLOBALS['cfg'] = $cfgOn;

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
const CR_USERS = ['crtest_alice', 'crtest_bob', 'crtest_carol', 'crtest_dave', 'crtest_erin', 'crtest_mod', 'crtest_rate', 'crtest_plain', 'crtest_http'];
const CR_H1 = 'c7c1c7c1c7c1c7c1c7c1c7c1c7c1c7c1c7c1c7c1';   // an index row, a description (hash_content), comments
const CR_H2 = 'c7c2c7c2c7c2c7c2c7c2c7c2c7c2c7c2c7c2c7c2';   // a hash nobody may see (no row at all)
const CR_H3 = 'c7c3c7c3c7c3c7c3c7c3c7c3c7c3c7c3c7c3c7c3';   // a second description, for the removal
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$tmpFiles = [];
$cleanRate = function (): void {
    $file = dirname(__DIR__) . '/config/rate_limits.json';
    if (!is_file($file)) return;
    $lock = @fopen($file . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $raw = @file_get_contents($file);
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    foreach (array_keys($data) as $k) if (str_starts_with($k, 'creport|') || str_starts_with($k, 'comment|')) unset($data[$k]);
    rateLimitWrite($file, $data);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
};
register_shutdown_function(function () use ($db, $auditFloor, &$tmpFiles, $cleanRate) {
    foreach (CR_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) userDeleteCascade($db, $id);
    }
    foreach ([CR_H1, CR_H2, CR_H3] as $h) {
        $db->prepare("DELETE FROM content_reports WHERE info_hash = ?")->execute([$h]);
        $db->prepare("DELETE FROM hash_comments WHERE info_hash = ?")->execute([$h]);
        $db->prepare("DELETE FROM hash_content WHERE info_hash = ?")->execute([$h]);
        $db->prepare("DELETE FROM index_hashes WHERE info_hash = ?")->execute([$h]);
    }
    $db->exec("DELETE FROM shouts WHERE body LIKE 'crtest %'");
    $db->exec("DELETE m FROM user_group_members m JOIN user_groups g ON g.id = m.group_id WHERE g.slug LIKE 'crtest\\_%'");
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'crtest\\_%'");
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
    $cleanRate();
    foreach (['content_reports', 'user_warnings', 'hash_comments'] as $t) {
        if (!(int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()) {
            try { $db->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1"); } catch (\Throwable $e) { /* not ours to insist */ }
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach ($tmpFiles as $f) @unlink($f);
});
$cleanRate();

/** A verified member in their language, made fresh: userEffectivePermissions() memoizes per account for the process. */
function crUser(PDO $db, array $cfg, string $name, string $lang = 'en'): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1, language = ? WHERE id = ?")->execute([$lang, $id]);
    userPermissionsForget($id);
    return $id;
}
$u = function (int $id) use ($db): array { return userFindById($db, $id) ?? []; };
$notes = function (int $uid, int $floor = 0) use ($db): array {
    $st = $db->prepare("SELECT type, title, body, link FROM user_notifications WHERE user_id = ? AND id > ? ORDER BY id");
    $st->execute([$uid, $floor]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$noteFloor = fn(): int => (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM user_notifications")->fetchColumn();
$reports = function (string $kind, int $target, string $hash) use ($db): array {
    $st = $db->prepare("SELECT * FROM content_reports WHERE kind = ? AND target_id = ? AND info_hash = ? ORDER BY id");
    $st->execute([$kind, $target, $hash]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$lastOf = fn(array $a) => $a ? $a[count($a) - 1] : null;
$auditSince = function (string $action) use ($db, $auditFloor): array {
    $st = $db->prepare("SELECT action, action_group, summary, detail FROM audit_log WHERE id > ? AND action = ? ORDER BY id");
    $st->execute([$auditFloor, $action]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

// A session of this process's own: the CSRF token lives in it, and the panel questions ask it.
session_id('crtest' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['csrf_token'] = 'cr-test-token';
$T = 'cr-test-token';
$asOwner = function (): void { $_SESSION['loggedin'] = true; $_SESSION['admin_via_user'] = 0; };
$asPanelUser = function (int $uid): void { $_SESSION['loggedin'] = true; $_SESSION['admin_via_user'] = $uid; userPermissionsForget($uid); };
$asNobody = function (): void { unset($_SESSION['loggedin'], $_SESSION['admin_via_user']); };

/* ══ 1. the schema, on both paths ═════════════════════════════════════════ */
check('the schema is at 84 or later, and its line says what 84 is', TRACKER_SCHEMA_VERSION >= 84
      && str_contains($src('includes/schema.php'), '84 = reports of what people write in public, and warnings (includes/reports.php): `content_reports`'));
$create = ['content_reports' => '', 'user_warnings' => ''];
foreach (trackerSchemaStatements() as $sql) foreach ($create as $t => $_) if (str_contains($sql, "CREATE TABLE IF NOT EXISTS `$t`")) $create[$t] = $sql;
check('a fresh install creates content_reports: kind, target, torrent, author, reporter, the words as reported, open_slot and its key',
      str_contains($create['content_reports'], "`kind` ENUM('comment','description','shout') NOT NULL")
      && str_contains($create['content_reports'], '`snapshot` TEXT NOT NULL') && str_contains($create['content_reports'], '`open_slot` TINYINT UNSIGNED DEFAULT 1')
      && str_contains($create['content_reports'], 'UNIQUE KEY `uq_crep_open` (`kind`, `target_id`, `info_hash`, `reporter_id`, `open_slot`)')
      && str_contains($create['content_reports'], 'KEY `idx_crep_queue` (`kind`, `status`, `created_at`)'));
check('… and user_warnings: the member, the reason, what it was about, what came with it, who gave it',
      str_contains($create['user_warnings'], '`source_kind` VARCHAR(16)') && str_contains($create['user_warnings'], '`action` VARCHAR(16) NOT NULL DEFAULT \'warn\'')
      && str_contains($create['user_warnings'], '`by_id` INT UNSIGNED DEFAULT NULL') && str_contains($create['user_warnings'], 'KEY `idx_warn_user` (`user_id`, `created_at`)'));
check('this database has both tables', schemaTableExists($db, 'content_reports') && schemaTableExists($db, 'user_warnings'));
$scratch = 'tracker_cr_' . bin2hex(random_bytes(3));
$dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: '3306');
$base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
try {
    $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT, `updated_at` DATETIME NULL)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (trackerSchemaStatements() as $sql) $sdb->exec($sql);
        check('fresh: both tables and their keys', schemaTableExists($sdb, 'content_reports') && schemaTableExists($sdb, 'user_warnings')
              && schemaIndexExists($sdb, 'content_reports', 'uq_crep_open') && schemaIndexExists($sdb, 'user_warnings', 'idx_warn_user'));
        $sdb->exec("DROP TABLE content_reports");
        $sdb->exec("DROP TABLE user_warnings");
        $guarded = trackerSchemaGuardedStatements($sdb);
        $errs = [];
        foreach ($guarded as $s) {
            $last = null;
            foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); $last = null; break; } catch (\Throwable $e) { $last = $e; } }
            if ($last) $errs[] = $last->getMessage();
        }
        check('upgrade (a v83 database): the guarded statements bring both back, without an error',
              !$errs && schemaTableExists($sdb, 'content_reports') && schemaTableExists($sdb, 'user_warnings'), implode(' | ', $errs));
        $norm = fn($s) => preg_replace('/\s+/', ' ', preg_replace('/^\s*--.*$/m', '', $s));
        $same = true;
        foreach (['content_reports', 'user_warnings'] as $t) {
            $b = ''; foreach ($guarded as $s) if (is_string($s) && str_contains($s, "CREATE TABLE IF NOT EXISTS `$t`")) $b = $s;
            if ($create[$t] === '' || $norm($create[$t]) !== $norm($b)) $same = false;
        }
        check('… from the SAME definitions as a fresh install\'s', $same);
        // One OPEN report per member per target, by the key itself.
        $ins = $sdb->prepare("INSERT INTO content_reports (kind, target_id, info_hash, reporter_id, reason, snapshot) VALUES (?, ?, ?, ?, 'r', 's')");
        $ins->execute(['comment', 7, CR_H1, 5]);
        $dup = false;
        try { $ins->execute(['comment', 7, CR_H1, 5]); } catch (\PDOException $e) { $dup = $e->getCode() === '23000'; }
        $sdb->exec("UPDATE content_reports SET status = 'closed', open_slot = NULL");
        $again = true;
        try { $ins->execute(['comment', 7, CR_H1, 5]); } catch (\PDOException $e) { $again = false; }
        check('the key: a second OPEN report by one member on one target is refused, and after the first is closed it is not', $dup && $again);
        $sdb->exec("INSERT IGNORE INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('moderator', 'Moderator', 'm', 500, 0, 1, '{}')");
        $sdb->exec("UPDATE user_groups SET permissions = '{\"index.view\":true}' WHERE slug IN ('member','guest')");
        $sdb->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v84_reports'");
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        $pm = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
        $pd = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'moderator'")->fetchColumn(), true) ?: [];
        $pg = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn(), true) ?: [];
        $six = ['panel.reports.comments.view', 'panel.reports.comments.handle', 'panel.reports.descriptions.view',
                'panel.reports.descriptions.handle', 'panel.reports.shouts.view', 'panel.reports.shouts.handle'];
        check('the grant: members report; moderators read and work the three queues; never the message queue; guests nothing',
              !empty($pm['content.report']) && !array_filter($six, fn($p) => !empty($pm[$p]))
              && !array_filter($six, fn($p) => empty($pd[$p])) && empty($pd['panel.messages.view']) && empty($pd['panel.messages.handle'])
              && empty($pg['content.report']) && !array_filter(array_keys($pg), fn($k) => str_starts_with($k, 'panel.')), json_encode([$pm, $pd]));
        $sdb->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"content.report\"') WHERE slug = 'member'");
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        $pm = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
        check('… ONCE: taken away afterwards, it is not given back by the next migration', empty($pm['content.report']));
    } finally {
        $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
} catch (\Throwable $e) {
    check('the scratch database for the schema checks', false, $e->getMessage());
}

/* ══ 2. the permissions ═══════════════════════════════════════════════════ */
$perms = userPermissionList();
$six = ['panel.reports.comments.view', 'panel.reports.comments.handle', 'panel.reports.descriptions.view',
        'panel.reports.descriptions.handle', 'panel.reports.shouts.view', 'panel.reports.shouts.handle'];
check('content.report and the six queue permissions are registered', isset($perms['content.report']) && !array_filter($six, fn($p) => !isset($perms[$p])));
$presets = userGroupPresets();
check('the member preset reports; the moderator preset works the three queues and never the message queue',
      in_array('content.report', $presets['member']['perms'], true) && !array_diff($six, $presets['moderator']['perms'])
      && !in_array('panel.messages.view', $presets['moderator']['perms'], true) && !in_array('panel.messages.handle', $presets['moderator']['perms'], true));
check('accounts off: nobody reports (the legacy default says no)', userLegacyDefault('content.report') === false && userLegacyDefault('panel.reports.comments.view') === false);
$guestP = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn(), true) ?: [];
check('this database\'s guest group holds none of it', empty($guestP['content.report']) && !array_filter($six, fn($p) => !empty($guestP[$p])));
$repItem = null;
foreach (adminNavItems() as $i) if ($i['action'] === 'admin') $repItem = $i;
check('the Reports page opens for ANY of its queues: the torrent reports, the messages, the three new kinds',
      $repItem && $repItem['perm'] === 'panel.reports.view'
      && !array_diff(['panel.messages.view', 'panel.reports.comments.view', 'panel.reports.descriptions.view', 'panel.reports.shouts.view'], (array)($repItem['any'] ?? [])));
check('the queue endpoints map to panel.access and ask the kind\'s own permission themselves',
      str_contains($src('api.php'), "'admin/content_reports'       => 'panel.access'") && str_contains($src('api.php'), "'admin/content_report_action' => 'panel.access'")
      && str_contains($src('api/admin/content_reports.php'), 'contentReportPanelKinds($db, $cfg)')
      && str_contains($src('includes/reports.php'), "if (!panelCan(\$db, \$cfg, contentReportPerm(\$kind, 'handle'))) return contentReportPanelFail(403, 'no_permission');"));

/* ══ 3. reporting ═════════════════════════════════════════════════════════ */
$alice = crUser($db, $cfgOn, 'crtest_alice', 'pl');   // a reporter who reads Polish
$bob   = crUser($db, $cfgOn, 'crtest_bob', 'en');     // a second reporter
$carol = crUser($db, $cfgOn, 'crtest_carol', 'en');   // an author
$dave  = crUser($db, $cfgOn, 'crtest_dave', 'pl');    // an author who reads Polish
$erin  = crUser($db, $cfgOn, 'crtest_erin', 'en');    // a member without content.report
$modId = crUser($db, $cfgOn, 'crtest_mod', 'en');     // staff: the moderator group
$rate  = crUser($db, $cfgOn, 'crtest_rate', 'en');
$modGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'moderator'")->fetchColumn();
userGrantGroup($db, $modId, $modGid, null, 'test', 'content_reports_test', false);
// erin: in a group of her own that reads but may not report
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('crtest_noreport', 'crtest no report', 'content_reports_test', 2, 0, 0, ?)")
   ->execute([json_encode(['index.view' => true, 'content.view' => true, 'comment.view' => true, 'shout.view' => true])]);
$noRepGid = (int)$db->lastInsertId();
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$erin]);
userGrantGroup($db, $erin, $noRepGid, null, 'test', 'content_reports_test', false);
foreach ([$alice, $bob, $carol, $dave, $erin, $modId, $rate] as $x) userPermissionsForget($x);

$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Reports test torrent', 'done')")->execute([CR_H1]);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Second test torrent', 'done')")->execute([CR_H3]);
$db->prepare("INSERT INTO hash_content (info_hash, description, description_format, content_status, content_user_id, content_credits) VALUES (?, ?, 'bbcode', 'approved', ?, ?)")
   ->execute([CR_H1, 'crtest [b]a description[/b] <script>x</script>', $carol, contentCreditsStart($carol)]);
$db->prepare("INSERT INTO hash_content (info_hash, description, description_format, content_status, content_user_id, content_credits) VALUES (?, ?, 'bbcode', 'approved', ?, ?)")
   ->execute([CR_H3, 'crtest the second description', $dave, contentCreditsStart($dave)]);
$cIns = $db->prepare("INSERT INTO hash_comments (info_hash, user_id, guest_tag, body, status) VALUES (?, ?, ?, ?, ?)");
$cIns->execute([CR_H1, $carol, null, 'crtest [b]rude[/b] words <img src=x onerror=alert(1)>', 'visible']); $c1 = (int)$db->lastInsertId();
$cIns->execute([CR_H1, $dave, null, 'crtest another rude one', 'visible']); $c2 = (int)$db->lastInsertId();
$cIns->execute([CR_H1, null, 'ab12', 'crtest a guest said this', 'visible']); $cGuest = (int)$db->lastInsertId();
$cIns->execute([CR_H1, $carol, null, 'crtest gone already', 'deleted']); $cGone = (int)$db->lastInsertId();
$cIns->execute([CR_H1, $modId, null, 'crtest a moderator\'s own words', 'visible']); $cMod = (int)$db->lastInsertId();
$cIns->execute([CR_H2, $carol, null, 'crtest on a hash nobody may see', 'visible']); $cHidden = (int)$db->lastInsertId();
$sIns = $db->prepare("INSERT INTO shouts (user_id, body, body_format, is_system) VALUES (?, ?, 'bbcode', ?)");
$sIns->execute([$carol, 'crtest a shout by carol', 0]); $s1 = (int)$db->lastInsertId();
$sIns->execute([null, 'crtest the site said this', 1]); $sSys = (int)$db->lastInsertId();

$me = fn(int $id): array => $u($id);
$rq = fn(int $who, array $in) => contentReportRequest($db, $cfgOn, $who > 0 ? $me($who) : null, $in + ['csrf_token' => $T], '127.0.0.1');
// Every refusal, in the order the gates are asked.
$r = contentReportRequest($db, $cfgOn, $me($alice), ['kind' => 'comment', 'id' => $c1, 'reason' => 'x'], '127.0.0.1');
check('no token: 403 csrf, before anything else', $r['status'] === 403 && $r['body']['error'] === 'csrf');
check('a kind that is not one: 400 bad_kind', ($rq($alice, ['kind' => 'message', 'id' => 1, 'reason' => 'x'])['body']['error'] ?? '') === 'bad_kind');
check('signed out: 401 login', ($r = $rq(0, ['kind' => 'comment', 'id' => $c1, 'reason' => 'x']))['status'] === 401 && $r['body']['error'] === 'login');
$r = contentReportRequest($db, array_merge($cfgOn, ['comments_enabled' => '0']), $me($alice), ['csrf_token' => $T, 'kind' => 'comment', 'id' => $c1, 'reason' => 'x'], '');
check('the feature off: 404 disabled', $r['status'] === 404 && $r['body']['error'] === 'disabled');
check('an account without content.report: 403 no_permission', ($r = $rq($erin, ['kind' => 'comment', 'id' => $c1, 'reason' => 'x']))['status'] === 403 && $r['body']['error'] === 'no_permission');
$nf = fn(array $in) => ($r = $rq($alice, $in + ['reason' => 'x']))['status'] === 404 && $r['body']['error'] === 'not_found';
check('nothing to report — 404 not_found: a comment on a hash the reader may not open, one already removed, an id that is none',
      $nf(['kind' => 'comment', 'id' => $cHidden]) && $nf(['kind' => 'comment', 'id' => $cGone]) && $nf(['kind' => 'comment', 'id' => 999999999]));
check('… a line the SITE said, and a description that is not there (or not published)',
      $nf(['kind' => 'shout', 'id' => $sSys]) && $nf(['kind' => 'description', 'hash' => CR_H2]) && $nf(['kind' => 'description', 'hash' => 'zz']));
check('your own words: 400 own', ($r = $rq($carol, ['kind' => 'comment', 'id' => $c1, 'reason' => 'x']))['status'] === 400 && $r['body']['error'] === 'own'
      && ($rq($carol, ['kind' => 'description', 'hash' => CR_H1, 'reason' => 'x'])['body']['error'] ?? '') === 'own'
      && ($rq($carol, ['kind' => 'shout', 'id' => $s1, 'reason' => 'x'])['body']['error'] ?? '') === 'own');
check('no reason: 400 reason_required (and spaces or control characters are none)',
      ($rq($alice, ['kind' => 'comment', 'id' => $c1, 'reason' => "  \t\n "])['body']['error'] ?? '') === 'reason_required'
      && ($rq($alice, ['kind' => 'comment', 'id' => $c1])['body']['error'] ?? '') === 'reason_required');
check('a reason past the limit: 400 reason_too_long', ($rq($alice, ['kind' => 'comment', 'id' => $c1, 'reason' => str_repeat('a', CONTENT_REPORT_REASON_MAX + 1)])['body']['error'] ?? '') === 'reason_too_long');
$r = $rq($alice, ['kind' => 'comment', 'id' => $c1, 'reason' => 'Insults in the thread']);
$rows = $reports('comment', $c1, CR_H1);
check('a report: taken, answered in the reader\'s language', $r['status'] === 200 && $r['body']['success'] && !$r['body']['already'] && count($rows) === 1, json_encode($r));
check('… with the words AS REPORTED, their format, the author found, the reporter and the reason kept',
      $rows && $rows[0]['snapshot'] === 'crtest [b]rude[/b] words <img src=x onerror=alert(1)>' && $rows[0]['snapshot_format'] === 'bbcode'
      && (int)$rows[0]['author_id'] === $carol && (int)$rows[0]['reporter_id'] === $alice && $rows[0]['reason'] === 'Insults in the thread'
      && $rows[0]['status'] === 'open' && (int)$rows[0]['open_slot'] === 1);
$r = $rq($alice, ['kind' => 'comment', 'id' => $c1, 'reason' => 'again']);
check('reported again while open: "already" — no second row', $r['status'] === 200 && $r['body']['already'] === true && count($reports('comment', $c1, CR_H1)) === 1);
check('a second member reports it too: a second report of the same target', $rq($bob, ['kind' => 'comment', 'id' => $c1, 'reason' => 'Rude'])['body']['success'] && count($reports('comment', $c1, CR_H1)) === 2);
check('a description (the words published now) and a shout are reported the same way',
      $rq($alice, ['kind' => 'description', 'hash' => CR_H1, 'reason' => 'Spam links'])['body']['success']
      && $rq($bob, ['kind' => 'shout', 'id' => $s1, 'reason' => 'Shouting'])['body']['success']
      && ($reports('description', 0, CR_H1)[0]['snapshot'] ?? '') === 'crtest [b]a description[/b] <script>x</script>'
      && ($reports('shout', $s1, '')[0]['snapshot'] ?? '') === 'crtest a shout by carol');
$okGuest = $rq($bob, ['kind' => 'comment', 'id' => $cGuest, 'reason' => 'Guest spam'])['body']['success'];
$gRows = $reports('comment', $cGuest, CR_H1);
check('… and a guest\'s comment (no account behind it)', $okGuest && count($gRows) === 1 && $gRows[0]['author_id'] === null);
check('the member\'s report is audited, in the Reports group of the log, with nothing but the facts',
      count($a = $auditSince('creport.file')) >= 5 && ($a[0]['action_group'] ?? '') === 'reports' && str_contains($a[0]['summary'], 'crtest_alice'));
// The ONE limit call: a member at the hourly limit is refused — and a report they had already made is not spent.
for ($i = 0; $i < CONTENT_REPORT_RATE_PER_HOUR; $i++) rateLimitAllow('creport', 'u' . $rate, CONTENT_REPORT_RATE_PER_HOUR, 3600);
$r = $rq($rate, ['kind' => 'comment', 'id' => $c2, 'reason' => 'x']);
check('at the hourly limit: 429 rate_limit (contentReportFloodCheck, the one call part F replaces)', $r['status'] === 429 && $r['body']['error'] === 'rate_limit', json_encode($r));
$cleanRate();
$rq($rate, ['kind' => 'comment', 'id' => $c2, 'reason' => 'first']);
$before = rateLimitPeek('creport', 'u' . $rate, 2, 3600);
$rq($rate, ['kind' => 'comment', 'id' => $c2, 'reason' => 'already']);
check('… and "already reported" spends nothing (asked before the limit)', $before === rateLimitPeek('creport', 'u' . $rate, 2, 3600) && $before === true);
// What every page is drawn from carries the flag.
$shape = commentShape($db, $cfgOn, $me($alice), [commentRow($db, $c1), commentRow($db, $c2)]);
$shapeOwn = commentShape($db, $cfgOn, $me($carol), [commentRow($db, $c1)]);
check('a comment\'s row: can_report for somebody else\'s, reported once this reader did, nothing on your own',
      $shape[0]['can_report'] && $shape[0]['reported'] && $shape[1]['can_report'] && !$shape[1]['reported'] && !$shapeOwn[0]['can_report'] && !$shapeOwn[0]['reported']);
check('… nothing for a member who may not report', !commentShape($db, $cfgOn, $me($erin), [commentRow($db, $c1)])[0]['can_report']);
$sRows = function (int $who) use ($db, $cfgOn, $me, $s1, $sSys): array {
    $st = $db->prepare(SHOUT_ROW_SELECT . " WHERE s.id IN (?, ?) ORDER BY s.id");
    $st->execute([$s1, $sSys]);
    return shoutShape($db, $cfgOn, $who > 0 ? $me($who) : [], $st->fetchAll(PDO::FETCH_ASSOC));
};
$sb = $sRows($bob);
check('a shout\'s row: somebody else\'s line flagged and reported; never the site\'s line; nothing for a guest',
      $sb[0]['can_report'] && $sb[0]['reported'] && !$sb[1]['can_report'] && !$sRows($carol)[0]['can_report'] && !$sRows(0)[0]['can_report']);
$recH1 = contentRecordFor($db, CR_H1);
check('the description: can + reported for its reporter, can for another reader, nothing for its author',
      contentReportDescFlags($db, $cfgOn, $me($alice), CR_H1, $recH1) === ['can' => true, 'reported' => true]
      && contentReportDescFlags($db, $cfgOn, $me($bob), CR_H1, $recH1) === ['can' => true, 'reported' => false]
      && contentReportDescFlags($db, $cfgOn, $me($carol), CR_H1, $recH1)['can'] === false);
check('the Info panel\'s answer carries the description\'s two flags', str_contains($src('api/index_info.php'), "'can_content_report'  => (bool)\$reportFlags['can'],")
      && str_contains($src('api/index_info.php'), "'content_reported'    => (bool)\$reportFlags['reported'],"));

/* ══ 4. the queue ═════════════════════════════════════════════════════════ */
$asOwner();
$kinds = contentReportPanelKinds($db, $cfgOn);
check('the owner\'s session sees and works all three kinds', array_keys($kinds) === ['comment', 'description', 'shout'] && !array_filter($kinds, fn($k) => !$k['handle'] || !$k['on']));
$asPanelUser($modId);
$kinds = contentReportPanelKinds($db, $cfgOn);
check('a moderator sees and works the three kinds — and not the message queue',
      count($kinds) === 3 && !array_filter($kinds, fn($k) => !$k['handle']) && !panelCan($db, $cfgOn, 'panel.messages.view'));
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('crtest_readonly', 'crtest read only', 'content_reports_test', 3, 0, 0, ?)")
   ->execute([json_encode(['panel.access' => true, 'panel.reports.comments.view' => true])]);
$roGid = (int)$db->lastInsertId();
$ro = crUser($db, $cfgOn, 'crtest_plain', 'en');
userGrantGroup($db, $ro, $roGid, null, 'test', 'content_reports_test', false);
$asPanelUser($ro);
$kinds = contentReportPanelKinds($db, $cfgOn);
check('a session holding only the comments\' view: that one tab, not handled; the page opens for it; the torrent tabs do not',
      array_keys($kinds) === ['comment'] && $kinds['comment']['handle'] === false && adminPageAllowed($db, $cfgOn, 'admin') && !panelCan($db, $cfgOn, 'panel.reports.view'));
$r = contentReportActionRequest($db, $cfgOn, ['kind' => 'comment', 'target_id' => $c1, 'hash' => CR_H1, 'action' => 'close']);
check('… and acting there is refused: 403 no_permission', $r['status'] === 403 && $r['body']['error'] === 'no_permission');
$asNobody();
check('no panel session: no tab at all, and the page closed', contentReportPanelKinds($db, $cfgOn) === [] && !adminPageAllowed($db, $cfgOn, 'admin'));
$asOwner();
check('the badges count open TARGETS (comment 1 reported twice is one)', contentReportOpenCounts($db, ['comment', 'description', 'shout']) === ['comment' => 3, 'description' => 1, 'shout' => 1],
      json_encode(contentReportOpenCounts($db, ['comment', 'description', 'shout'])));
$list = fn(array $f) => contentReportList($db, $cfgOn, 'comment', contentReportFilters($f), 1, 25);
$L = $list(['status' => 'open']);
$g1 = null; foreach ($L['groups'] as $g) if ($g['target_id'] === $c1) $g1 = $g;
check('grouped: comment 1 is ONE card with its two reports, newest first', $L['total'] === 3 && $g1 !== null && count($g1['reports']) === 2
      && $g1['reports'][0]['reporter'] === 'crtest_bob' && $g1['reports'][1]['reporter'] === 'crtest_alice' && $g1['open'] === 2, json_encode(array_map(fn($g) => [$g['target_id'], count($g['reports'])], $L['groups'])));
check('… its words through the comment\'s own renderer — tags it allows drawn, the rest escaped',
      $g1 && str_contains($g1['html'], '<strong>rude</strong>') && !str_contains($g1['html'], '<img') && str_contains($g1['html'], '&lt;img'));
check('… where it lives, its author and what the account is', $g1 && $g1['where'] === '?action=search&hash=' . CR_H1 . '#comment-' . $c1
      && $g1['author'] === 'crtest_carol' && is_array($g1['author_state']) && $g1['author_state']['reports'] >= 4 && $g1['author_state']['staff'] === false);
check('the filters: the reported member (a prefix), the reporter, the text of a reason',
      $list(['status' => 'all', 'author' => 'crtest_car'])['total'] === 1 && $list(['status' => 'all', 'reporter' => 'crtest_alice'])['total'] === 1
      && $list(['status' => 'all', 'reporter' => 'crtest_bob'])['total'] === 2 && $list(['status' => 'all', 'q' => 'Guest spam'])['total'] === 1
      && $list(['status' => 'all', 'q' => 'nothing like this'])['total'] === 0);
$today = $db->query("SELECT DATE(NOW())")->fetchColumn();
$tomorrow = $db->query("SELECT DATE(NOW() + INTERVAL 1 DAY)")->fetchColumn();
check('… the date range (by the database\'s clock, the one the rows were written by)',
      $list(['status' => 'all', 'from' => $today, 'to' => $today])['total'] === 3 && $list(['status' => 'all', 'from' => $tomorrow])['total'] === 0
      && $list(['status' => 'all', 'to' => $db->query("SELECT DATE(NOW() - INTERVAL 1 DAY)")->fetchColumn()])['total'] === 0);
check('… and a filter that is not a date is no filter', contentReportFilters(['from' => '2026-02-31', 'to' => "x' OR 1=1"])['from'] === '' && contentReportFilters(['to' => 'x'])['to'] === '');
// Words changed after the report: shown now AND as reported.
$db->prepare("UPDATE hash_comments SET body = 'crtest softened now' WHERE id = ?")->execute([$c1]);
$g1 = null; foreach ($list(['status' => 'open'])['groups'] as $g) if ($g['target_id'] === $c1) $g1 = $g;
check('changed after the report: the card shows the words now, and the words as reported beside them',
      $g1 && $g1['changed'] && str_contains($g1['html'], 'softened now') && str_contains($g1['reported_html'], '<strong>rude</strong>'));
$db->prepare("UPDATE hash_comments SET body = 'crtest [b]rude[/b] words <img src=x onerror=alert(1)>' WHERE id = ?")->execute([$c1]);
$D = contentReportList($db, $cfgOn, 'description', contentReportFilters(['status' => 'open']), 1, 25);
check('a description\'s card: its renderer (a moderator sees hidden parts), escaped, the torrent\'s name, where it lives',
      $D['total'] === 1 && str_contains($D['groups'][0]['html'], '<strong>a description</strong>') && !str_contains($D['groups'][0]['html'], '<script')
      && $D['groups'][0]['name'] === 'Reports test torrent' && $D['groups'][0]['where'] === '?action=search&hash=' . CR_H1);
$S = contentReportList($db, $cfgOn, 'shout', contentReportFilters(['status' => 'open']), 1, 25);
check('a shout\'s card: the room\'s renderer, and where the room is', $S['total'] === 1 && str_contains($S['groups'][0]['where'], '#shout-' . $s1)
      && str_contains($S['groups'][0]['html'], 'a shout by carol'));

/* ══ 5. the actions, silent and loud ══════════════════════════════════════ */
$act = fn(array $in) => contentReportActionRequest($db, $cfgOn, $in);
$shaOf = function (int $cid) use ($db): string { $st = $db->prepare("SELECT body FROM hash_comments WHERE id = ?"); $st->execute([$cid]); return sha1((string)$st->fetchColumn()); };
$reporterNames = ['crtest_alice', 'crtest_bob', 'crtest_rate'];
$noReporter = function (array $ns) use ($reporterNames): bool {
    foreach ($ns as $x) foreach ($reporterNames as $rn) if (str_contains($x['title'] . ' ' . $x['body'], $rn)) return false;
    return true;
};
// close: the reporters, in their languages, with the answer; the author nothing
$f = $noteFloor();
$r = $act(['kind' => 'comment', 'target_id' => $c1, 'hash' => CR_H1, 'action' => 'close', 'note' => 'looked, fine', 'reply' => 'We looked at it.']);
$na = $notes($alice, $f); $nb = $notes($bob, $f); $nc = $notes($carol, $f);
check('close: both reports closed, "closed without action"', $r['status'] === 200 && $r['body']['closed'] === 2
      && !array_filter($reports('comment', $c1, CR_H1), fn($x) => $x['status'] !== 'closed' || $x['outcome'] !== 'closed' || $x['open_slot'] !== null || $x['note'] !== 'looked, fine'));
check('… each reporter told once, in THEIR language, with the answer', count($na) === 1 && count($nb) === 1 && $na[0]['type'] === 'report'
      && str_contains($na[0]['title'], 'zostało zamknięte') && str_contains($na[0]['title'], 'Reports test torrent') && str_contains($na[0]['body'], 'We looked at it.')
      && str_contains($nb[0]['title'], 'was closed') && str_contains($nb[0]['body'], 'We looked at it.'), json_encode([$na, $nb], JSON_UNESCAPED_UNICODE));
check('… the author is told nothing about a report closed', $nc === []);
$r = $act(['kind' => 'comment', 'target_id' => $c1, 'hash' => CR_H1, 'action' => 'reopen']);
check('reopen: open again, the notes kept, nobody told', $r['body']['reopened'] === 2 && ($reports('comment', $c1, CR_H1)[0]['note'] ?? '') === 'looked, fine'
      && count($notes($alice, $f)) === 1 && count($notes($carol, $f)) === 0);
check('… after reopening, the same member reporting again is still "already"', $rq($alice, ['kind' => 'comment', 'id' => $c1, 'reason' => 'x'])['body']['already'] === true);
// remove, SILENT
$f = $noteFloor();
$r = $act(['kind' => 'comment', 'target_id' => $c1, 'hash' => CR_H1, 'action' => 'remove', 'mode' => 'silent', 'seen' => $shaOf($c1), 'reply' => 'Removed.']);
$row = commentRow($db, $c1);
check('remove, silently: the comment taken down through commentDelete() — soft, stamped as the owner\'s session, with why',
      $r['status'] === 200 && $row['status'] === 'deleted' && $row['deleted_by'] === null && str_starts_with((string)$row['delete_reason'], 'after a report (#'), json_encode([$r, $row]));
check('… the author told NOTHING — no notice, no warning, no warning kept', $notes($carol, $f) === [] && !(int)$db->query("SELECT COUNT(*) FROM user_warnings WHERE user_id = $carol")->fetchColumn());
check('… the reporters told it was removed, in their languages', str_contains($notes($alice, $f)[0]['title'] ?? '', 'został usunięty') && str_contains($notes($bob, $f)[0]['title'] ?? '', 'was removed'));
$del = $auditSince('comment.delete');
check('… audited twice: the removal (comment.delete, marked silent) and the report\'s action (creport.remove)',
      $del && str_contains((string)$lastOf($del)['detail'], '"silent":true') && count($auditSince('creport.remove')) === 1 && ($auditSince('creport.remove')[0]['action_group'] ?? '') === 'reports');
// remove, LOUD — the reason first
$rq($bob, ['kind' => 'comment', 'id' => $c2, 'reason' => 'Rude to people']);
$f = $noteFloor();
$r = $act(['kind' => 'comment', 'target_id' => $c2, 'hash' => CR_H1, 'action' => 'remove', 'mode' => 'loud', 'seen' => $shaOf($c2)]);
check('remove as a warning without a reason: 400 reason_required, and nothing done', $r['status'] === 400 && $r['body']['error'] === 'reason_required'
      && commentRow($db, $c2)['status'] === 'visible');
$r = $act(['kind' => 'comment', 'target_id' => $c2, 'hash' => CR_H1, 'action' => 'remove', 'mode' => 'loud', 'seen' => 'not-what-is-there', 'reason' => 'x']);
check('remove with words that changed under the moderator (seen ≠ now): 409 changed, and nothing done', $r['status'] === 409 && $r['body']['error'] === 'changed' && commentRow($db, $c2)['status'] === 'visible');
$r = $act(['kind' => 'comment', 'target_id' => $c2, 'hash' => CR_H1, 'action' => 'remove', 'mode' => 'loud', 'seen' => $shaOf($c2), 'reason' => 'Nie obrażaj ludzi']);
$nd = $notes($dave, $f);
check('remove, loud: the author gets ONE notification — a WARNING, in THEIR language, with the reason and the count',
      $r['status'] === 200 && count($nd) === 1 && $nd[0]['type'] === 'warning' && str_starts_with($nd[0]['title'], 'Ostrzeżenie: Twój komentarz pod „Reports test torrent”')
      && str_contains($nd[0]['body'], 'Nie obrażaj ludzi') && str_contains($nd[0]['body'], 'numer 1') && $nd[0]['link'] === '?action=search&hash=' . CR_H1, json_encode($nd, JSON_UNESCAPED_UNICODE));
check('… nobody who reported is named to the author', $noReporter($nd));
$w = $db->query("SELECT * FROM user_warnings WHERE user_id = $dave")->fetchAll(PDO::FETCH_ASSOC);
check('… and a warning kept: the reason, what it was about, what came with it, who gave it',
      count($w) === 1 && $w[0]['reason'] === 'Nie obrażaj ludzi' && $w[0]['source_kind'] === 'comment' && (int)$w[0]['source_id'] === $c2
      && $w[0]['info_hash'] === CR_H1 && $w[0]['action'] === 'remove' && $w[0]['by_id'] === null && $w[0]['by_name'] === 'panel');
check('… and audited as user.warn (Users group)', ($auditSince('user.warn')[0]['action_group'] ?? '') === 'users');
// warn: always loud, a description; the count grows
$f = $noteFloor();
$r = $act(['kind' => 'description', 'hash' => CR_H1, 'action' => 'warn', 'mode' => 'silent', 'reason' => 'No spam links in descriptions', 'reply' => 'Handled.']);
$ncw = $notes($carol, $f);
check('warn is loud whatever the mode says: the author warned about the description, in English', $r['status'] === 200 && $r['body']['mode'] === 'loud'
      && count($ncw) === 1 && $ncw[0]['type'] === 'warning' && $ncw[0]['title'] === 'Warning about your description of “Reports test torrent”' && str_contains($ncw[0]['body'], 'number 1'), json_encode($ncw));
check('… the description stays; its report closed as "the author warned"; its reporter told it was handled',
      contentOccupied(contentRecordFor($db, CR_H1)) && ($reports('description', 0, CR_H1)[0]['outcome'] ?? '') === 'warned'
      && str_contains($notes($alice, $f)[0]['title'] ?? '', 'rozpatrzone') && str_contains($notes($alice, $f)[0]['body'] ?? '', 'Handled.'));
$r = $act(['kind' => 'description', 'hash' => CR_H1, 'action' => 'warn', 'reason' => 'Second time']);
check('… a second warning says it is the second', str_contains($notes($carol, $f)[1]['body'] ?? '', 'number 2') && userWarningsSummary($db, [$carol])[$carol]['count'] === 2);
// mute, SILENT and LOUD; unmute loud (plain); ban loud; unban silent; a ban without a date
$f = $noteFloor();
$r = $act(['kind' => 'shout', 'target_id' => $s1, 'action' => 'mute', 'mode' => 'silent', 'days' => 7]);
$mut = $db->query("SELECT pm_muted_until, TIMESTAMPDIFF(HOUR, NOW(), pm_muted_until) AS h FROM users WHERE id = $carol")->fetch(PDO::FETCH_ASSOC);
check('mute, silently: seven days by the database\'s clock, the author told nothing', $r['status'] === 200 && (int)$mut['h'] >= 167 && (int)$mut['h'] <= 168
      && $notes($carol, $f) === [] && pmMutedUntil($me($carol)) !== null, json_encode([$r, $mut]));
check('… the shout\'s report closed as "the author silenced", its reporter told it was handled — not what was done',
      ($reports('shout', $s1, '')[0]['outcome'] ?? '') === 'muted' && str_contains($notes($bob, $f)[0]['title'] ?? '', 'was handled')
      && !str_contains(strtolower(($notes($bob, $f)[0]['title'] ?? '') . ($notes($bob, $f)[0]['body'] ?? '')), 'silenc'));
$r = $act(['kind' => 'shout', 'target_id' => $s1, 'action' => 'mute', 'mode' => 'loud', 'days' => 0, 'reason' => 'Calm down']);
$nm = $notes($carol, $f);
check('mute, loud, until lifted: a warning saying so, with what a silence stops', $r['status'] === 200 && count($nm) === 1 && $nm[0]['type'] === 'warning'
      && $nm[0]['title'] === 'Warning: you are silenced until a moderator lifts it' && str_contains($nm[0]['body'], 'Calm down') && str_contains($nm[0]['body'], 'you can still read')
      && str_starts_with((string)$db->query("SELECT pm_muted_until FROM users WHERE id = $carol")->fetchColumn(), '2099-12-31'));
$r = $act(['kind' => 'shout', 'target_id' => $s1, 'action' => 'unmute', 'mode' => 'loud']);
$nu = array_slice($notes($carol, $f), 1);
check('unmute, loud: lifted and said plainly — no warning, none kept', $r['status'] === 200 && count($nu) === 1 && $nu[0]['type'] === 'account'
      && $db->query("SELECT pm_muted_until FROM users WHERE id = $carol")->fetchColumn() === null && userWarningsSummary($db, [$carol])[$carol]['count'] === 3);
$f = $noteFloor();
$r = $act(['kind' => 'shout', 'target_id' => $s1, 'action' => 'ban', 'mode' => 'loud', 'days' => 7, 'reason' => 'Enough']);
$nbn = $notes($carol, $f);
$ban = $db->query("SELECT status, banned_until FROM users WHERE id = $carol")->fetch(PDO::FETCH_ASSOC);
check('ban, loud, seven days: suspended with a date, a warning with the date in the member\'s zone', $r['status'] === 200 && $ban['status'] === 'banned' && $ban['banned_until'] !== null
      && count($nbn) === 1 && $nbn[0]['type'] === 'warning' && str_starts_with($nbn[0]['title'], 'Warning: your account is banned until 20'), json_encode([$ban, $nbn]));
$r = $act(['kind' => 'shout', 'target_id' => $s1, 'action' => 'unban', 'mode' => 'silent']);
check('unban, silently: active again, nothing said', $r['status'] === 200 && $db->query("SELECT status FROM users WHERE id = $carol")->fetchColumn() === 'active'
      && count($notes($carol, $f)) === 1);
$db->prepare("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = ?")->execute([$carol]);
$r1 = $act(['kind' => 'shout', 'target_id' => $s1, 'action' => 'ban', 'days' => 7]);
$r2 = $act(['kind' => 'shout', 'target_id' => $s1, 'action' => 'unban']);
check('a ban without a date (the owner\'s, from Users) is neither shortened (409) nor lifted (403) here',
      $r1['status'] === 409 && $r1['body']['error'] === 'ban_is_permanent' && $r2['status'] === 403 && $r2['body']['error'] === 'ban_is_permanent'
      && $db->query("SELECT banned_until FROM users WHERE id = $carol")->fetchColumn() === null);
$db->prepare("UPDATE users SET status = 'active', banned_until = NULL WHERE id = ?")->execute([$carol]);
// staff, yourself, no account
$rq($alice, ['kind' => 'comment', 'id' => $cMod, 'reason' => 'A moderator was rude']);
$r1 = $act(['kind' => 'comment', 'target_id' => $cMod, 'hash' => CR_H1, 'action' => 'warn', 'reason' => 'x']);
$r2 = $act(['kind' => 'comment', 'target_id' => $cMod, 'hash' => CR_H1, 'action' => 'mute', 'days' => 1]);
$r3 = $act(['kind' => 'comment', 'target_id' => $cMod, 'hash' => CR_H1, 'action' => 'remove', 'mode' => 'loud', 'reason' => 'x', 'seen' => $shaOf($cMod)]);
check('an account that can open the panel: no warning, no silence, no loud removal — 403 target_is_staff, nothing done',
      $r1['status'] === 403 && $r1['body']['error'] === 'target_is_staff' && $r2['body']['error'] === 'target_is_staff' && $r3['body']['error'] === 'target_is_staff'
      && commentRow($db, $cMod)['status'] === 'visible' && pmMutedUntil($me($modId)) === null);
$r4 = $act(['kind' => 'comment', 'target_id' => $cMod, 'hash' => CR_H1, 'action' => 'remove', 'mode' => 'silent', 'seen' => $shaOf($cMod)]);
check('… its words may still be removed silently (the words are the report\'s business, the account is Users\')', $r4['status'] === 200 && commentRow($db, $cMod)['status'] === 'deleted');
$_SESSION['admin_via_user'] = $carol;
check('your own account: refused (reportAccountGuard — the message card\'s rule)', reportAccountGuard($db, $cfgOn, $carol) === 'target_is_you');
$asOwner();
check('… a guest, the site or a deleted account: no account to act on', reportAccountGuard($db, $cfgOn, null) === 'no_author' && reportAccountGuard($db, $cfgOn, 999999999) === 'no_author');
$r1 = $act(['kind' => 'comment', 'target_id' => $cGuest, 'hash' => CR_H1, 'action' => 'warn', 'reason' => 'x']);
$r2 = $act(['kind' => 'comment', 'target_id' => $cGuest, 'hash' => CR_H1, 'action' => 'remove', 'mode' => 'silent', 'seen' => $shaOf($cGuest)]);
check('a guest\'s comment: a warning has nobody to go to (400 no_author); the removal goes ahead', $r1['status'] === 400 && $r1['body']['error'] === 'no_author'
      && $r2['status'] === 200 && commentRow($db, $cGuest)['status'] === 'deleted');
$r = $act(['kind' => 'comment', 'target_id' => $cGuest, 'hash' => CR_H1, 'action' => 'remove']);
check('removing what is already gone: 409 gone', $r['status'] === 409 && $r['body']['error'] === 'gone');
check('an action that is not one, a target that is not one', $act(['kind' => 'comment', 'target_id' => $c1, 'hash' => CR_H1, 'action' => 'explode'])['body']['error'] === 'unknown_action'
      && $act(['kind' => 'comment', 'target_id' => 999999, 'hash' => CR_H1, 'action' => 'close'])['status'] === 404);
// a description removed LOUDLY: contentDelete() says nothing itself, the author gets the one warning
$rq($bob, ['kind' => 'description', 'hash' => CR_H3, 'reason' => 'Copied from elsewhere']);
$f = $noteFloor();
$D3 = contentReportList($db, $cfgOn, 'description', contentReportFilters(['status' => 'open']), 1, 25);
$sha3 = ''; foreach ($D3['groups'] as $g) if ($g['hash'] === CR_H3) $sha3 = $g['sha'];
$r = $act(['kind' => 'description', 'hash' => CR_H3, 'action' => 'remove', 'mode' => 'loud', 'reason' => 'Skopiowane', 'seen' => $sha3]);
$ndd = $notes($dave, $f);
check('a description removed as a warning: gone through contentDelete(), its author told ONCE (the warning, not also the plain notice)',
      $r['status'] === 200 && !contentOccupied(contentRecordFor($db, CR_H3) ?? ['description' => null, 'source_url' => null])
      && count($ndd) === 1 && $ndd[0]['type'] === 'warning' && str_starts_with($ndd[0]['title'], 'Ostrzeżenie: Twój opis „Second test torrent”'), json_encode($ndd, JSON_UNESCAPED_UNICODE));
check('… audited as a silent contentDelete (it told nobody itself) and as creport.remove', str_contains((string)($lastOf($auditSince('content.delete'))['detail'] ?? ''), '"silent":true')
      && str_contains((string)($lastOf($auditSince('creport.remove'))['summary'] ?? ''), 'removed a description on Second test torrent by crtest_dave (loud)'),
      (string)($lastOf($auditSince('creport.remove'))['summary'] ?? ''));
// a shout removed silently: shoutDelete() with the panel's authority
$sIns->execute([$dave, 'crtest a shout by dave', 0]); $s2 = (int)$db->lastInsertId();
$rq($alice, ['kind' => 'shout', 'id' => $s2, 'reason' => 'Loud']);
$f = $noteFloor();
$st = $db->prepare("SELECT body FROM shouts WHERE id = ?"); $st->execute([$s2]);
$r = $act(['kind' => 'shout', 'target_id' => $s2, 'action' => 'remove', 'seen' => sha1((string)$st->fetchColumn())]);
$srow = $db->query("SELECT deleted_at, deleted_by FROM shouts WHERE id = $s2")->fetch(PDO::FETCH_ASSOC);
check('a shout removed silently: soft (shoutDelete()), stamped by nobody for the owner\'s session, its author told nothing',
      $r['status'] === 200 && $srow['deleted_at'] !== null && $srow['deleted_by'] === null && $notes($dave, $f) === []);
check('every action went to the audit log under Reports', count($auditSince('creport.close')) >= 1 && count($auditSince('creport.reopen')) === 1
      && count($auditSince('creport.warn')) === 2 && count($auditSince('creport.mute')) === 2 && count($auditSince('creport.ban')) === 1
      && count($auditSince('creport.unmute')) === 1 && count($auditSince('creport.unban')) === 1 && auditGroupOf('creport.mute') === 'reports');
// the author never learns who reported — across everything they were sent
check('nobody who reported is named in anything the authors were sent', $noReporter($notes($carol)) && $noReporter($notes($dave)));
$asOwner();

/* ══ 6. the message card ══════════════════════════════════════════════════ */
// run as requests below (§9) — the endpoint answers through jsonResponse(), which ends the process.

/* ══ 7. warnings ══════════════════════════════════════════════════════════ */
$ws = userWarningsSummary($db, [$carol, $dave, $bob]);
check('the warnings, counted, the latest first, with what came with each', $ws[$carol]['count'] === 4 && $ws[$dave]['count'] === 2 && $ws[$bob]['count'] === 0
      && ($ws[$carol]['latest'][0]['action'] ?? '') === 'ban' && count($ws[$carol]['latest']) === CONTENT_REPORT_WARNINGS_SHOWN, json_encode($ws));
$states = reportAuthorStates($db, $cfgOn, [$carol]);
check('an author\'s card: the warnings and the latest ones, the reports of both queues counted', $states[$carol]['warnings'] === 4 && count($states[$carol]['latest_warnings']) === 3
      && $states[$carol]['reports'] >= 4);
$gw = 0; foreach (contentReportList($db, $cfgOn, 'shout', contentReportFilters(['status' => 'all']), 1, 25)['groups'] as $g) if ($g['author'] === 'crtest_carol') $gw = $g['author_state']['warnings'];
check('… on the queue\'s card as the page gets it (and no picture hash leaks into it)', $gw === 4
      && !str_contains(json_encode(contentReportList($db, $cfgOn, 'shout', contentReportFilters(['status' => 'all']), 1, 25)), 'avatar_sha'));
check('the member\'s warnings are notifications of type "warning" — shown marked on the account page',
      count(array_filter($notes($carol), fn($x) => $x['type'] === 'warning')) === 4
      && str_contains($src('assets/js/app.js'), "const warning = n.type === 'warning';") && str_contains($src('assets/css/style.css'), '.acc-notif-warning'));
$pub = [];
foreach (array_merge(glob($root . '/api/*.php'), glob($root . '/templates/pages/*.php'), glob($root . '/templates/partials/*.php')) as $fp) {
    if (str_contains((string)file_get_contents($fp), 'user_warnings') || str_contains((string)file_get_contents($fp), 'userWarningsSummary')) $pub[] = basename($fp);
}
check('… and nowhere public: no public endpoint or page reads the warnings', $pub === [], implode(',', $pub));
check('Users: each row carries the count and the latest five; the edit window lists them',
      str_contains($src('api/admin/fetch_users.php'), "userWarningsSummary(\$db, \$ids, 5)") && str_contains($src('api/admin/fetch_users.php'), "\$r['latest_warnings'] = \$warned[\$r['id']]['latest'] ?? [];")
      && str_contains($src('templates/admin/users.php'), 'id="ue-warnings"') && str_contains($src('assets/js/admin-users.js'), 'function ueWarnings(u)'));
check('the message card\'s account facts carry the warnings too', str_contains($src('api/admin/fetch_message_reports.php'), "'warnings'        => (int)(\$warned[(int)\$u['id']]['count'] ?? 0),"));

/* ══ 8. an account going ══════════════════════════════════════════════════ */
$rq($dave, ['kind' => 'description', 'hash' => CR_H1, 'reason' => 'dave reports carol\'s words']);
$daveFiled = (int)$db->query("SELECT COUNT(*) FROM content_reports WHERE reporter_id = $dave")->fetchColumn();
$gone = userDeleteCascade($db, $dave);
check('a reporter leaving: their reports go, their warnings go', $daveFiled === 1 && !(int)$db->query("SELECT COUNT(*) FROM content_reports WHERE reporter_id = $dave")->fetchColumn()
      && !(int)$db->query("SELECT COUNT(*) FROM user_warnings WHERE user_id = $dave")->fetchColumn() && ($gone['warnings'] ?? 0) === 2, json_encode($gone));
$descBefore = (int)$db->query("SELECT COUNT(*) FROM content_reports WHERE kind = 'description' AND author_id = $carol")->fetchColumn();
$gone = userDeleteCascade($db, $carol);
check('an author leaving: the reports about their comments and shouts go with them; a description\'s stay, without an author',
      !(int)$db->query("SELECT COUNT(*) FROM content_reports WHERE author_id = $carol")->fetchColumn()
      && $descBefore >= 1 && (int)$db->query("SELECT COUNT(*) FROM content_reports WHERE kind = 'description' AND info_hash = '" . CR_H1 . "' AND author_id IS NULL")->fetchColumn() >= 1, json_encode($gone));
$db->exec("INSERT INTO user_warnings (user_id, reason, by_id, by_name) VALUES ($bob, 'from a moderator who leaves', $modId, 'crtest_mod')");
userDeleteCascade($db, $modId);
check('a moderator leaving: the warnings they gave keep the name and forget the account',
      $db->query("SELECT by_id FROM user_warnings WHERE user_id = $bob")->fetchColumn() === null
      && $db->query("SELECT by_name FROM user_warnings WHERE user_id = $bob")->fetchColumn() === 'crtest_mod');

/* ══ 9. the endpoints, as requests ══════════════════════════════════════ */
// The endpoint FILES, run as requests in a child process: a started session holding the account (or the panel)
// and the token, the same includes api.php loads — the answer read off what they print. readJsonBody() reads
// php://input, which a CLI child does not have, so the body goes in $_POST, which it falls back to.
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cr_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.46";
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/index.php", "includes/richtext.php", "includes/content.php", "includes/mail.php",
          "includes/users.php", "includes/favourites.php", "includes/usermedia.php", "includes/sounds.php", "includes/shout.php",
          "includes/emoji.php", "includes/people.php", "includes/audit.php", "includes/auth.php", "includes/comments.php",
          "includes/reports.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = $a["endpoint"];
require $a["file"];
');
$run = function (string $endpoint, string $method, array $post, array $get, array $session) use ($root, &$tmpFiles, $cfgOn): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cr_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    $cfgX = array_intersect_key($cfgOn, array_flip(['users_enabled', 'profiles_enabled', 'index_enabled', 'index_search_enabled', 'comments_enabled',
        'wl_allow_description', 'wl_allow_source_url', 'shout_enabled', 'pm_enabled', 'recaptcha_enabled', 'default_language', 'users_require_email_verify',
        'antispam_enabled']));
    file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'file' => $root . '/api/' . $endpoint . '.php', 'method' => $method,
        'post' => $post, 'get' => $get, 'session' => $session, 'cfg' => $cfgX, 'sid' => 'crtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($GLOBALS['runner']) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 600)];
};
$GLOBALS['runner'] = $runner;
$httpId = crUser($db, $cfgOn, 'crtest_http', 'en');
$cIns->execute([CR_H1, $bob, null, 'crtest bob wrote this', 'visible']); $cBob = (int)$db->lastInsertId();
$member = ['user_id' => $httpId, 'user_login_time' => time(), 'csrf_token' => 'cr-child-token'];
$j = $run('content_report', 'POST', ['csrf_token' => 'cr-child-token', 'kind' => 'comment', 'id' => $cBob, 'reason' => 'From the endpoint'], [], $member);
check('content_report as a request: signed in by the session, taken', !empty($j['success']) && ($j['already'] ?? null) === false, json_encode($j));
$j = $run('content_report', 'POST', ['csrf_token' => 'another', 'kind' => 'comment', 'id' => $cBob, 'reason' => 'x'], [], $member);
check('… refused without the session\'s token', ($j['error'] ?? '') === 'csrf' && empty($j['success']), json_encode($j));
$panel = ['loggedin' => true, 'admin_via_user' => 0, 'csrf_token' => 'cr-child-token'];
$j = $run('admin/content_reports', 'GET', [], ['counts' => '1'], $panel);
check('admin/content_reports?counts as a request: the three tabs\' open targets', isset($j['kinds']['comment']['open'], $j['kinds']['description'], $j['kinds']['shout']) && $j['kinds']['comment']['open'] >= 1, json_encode($j));
$j = $run('admin/content_reports', 'GET', [], ['kind' => 'comment', 'status' => 'open', 'reporter' => 'crtest_http'], $panel);
check('… and a filtered list: one card, the reporter, may_handle', ($j['total'] ?? 0) === 1 && ($j['groups'][0]['reports'][0]['reporter'] ?? '') === 'crtest_http' && $j['may_handle'] === true, json_encode($j));
$j = $run('admin/content_reports', 'GET', [], ['kind' => 'comment'], ['csrf_token' => 'x']);
check('… with no panel session: refused', ($j['error'] ?? '') === 'no_permission', json_encode($j));
$j = $run('admin/content_report_action', 'POST', ['kind' => 'comment', 'target_id' => $cBob, 'hash' => CR_H1, 'action' => 'close', 'reply' => 'Seen'], [], $panel);
check('admin/content_report_action as a request: closed, its reporter told', !empty($j['success']) && ($j['closed'] ?? 0) === 1 && ($j['told'] ?? 0) === 1, json_encode($j));
// the message card: the same choice, and the old answer without one
$t = pmThreadFor($db, $alice, $bob);
$mIns = $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, 'bbcode')");
$mIns->execute([(int)$t['id'], $bob, 'crtest line one']); $m1 = (int)$db->lastInsertId();
$mIns->execute([(int)$t['id'], $bob, 'crtest line two']); $m2 = (int)$db->lastInsertId();
$mIns->execute([(int)$t['id'], $bob, 'crtest line three']); $m3 = (int)$db->lastInsertId();
$rIns = $db->prepare("INSERT INTO message_reports (message_id, context_id, thread_id, reporter_id, reported_user_id, reason) VALUES (?, NULL, ?, ?, ?, 'rude')");
$rIns->execute([$m1, (int)$t['id'], $alice, $bob]); $mr1 = (int)$db->lastInsertId();
$rIns->execute([$m2, (int)$t['id'], $alice, $bob]); $mr2 = (int)$db->lastInsertId();
$rIns->execute([$m3, (int)$t['id'], $alice, $bob]); $mr3 = (int)$db->lastInsertId();
$f = $noteFloor();
$j = $run('admin/message_report_action', 'POST', ['id' => $mr1, 'action' => 'delete_message', 'mode' => 'silent'], [], $panel);
check('the message card, silently: the line deleted, its author told nothing', !empty($j['success']) && $notes($bob, $f) === []
      && !(int)$db->query("SELECT COUNT(*) FROM user_messages WHERE id = $m1")->fetchColumn(), json_encode($j));
$j = $run('admin/message_report_action', 'POST', ['id' => $mr2, 'action' => 'delete_message', 'mode' => 'loud'], [], $panel);
check('… as a warning without a reason: 400, and the line is still there', ($j['error'] ?? '') === 'reason_required' && (int)$db->query("SELECT COUNT(*) FROM user_messages WHERE id = $m2")->fetchColumn() === 1);
$j = $run('admin/message_report_action', 'POST', ['id' => $mr2, 'action' => 'delete_message', 'mode' => 'loud', 'reason' => 'Mind your words'], [], $panel);
$nbm = $notes($bob, $f);
check('… as a warning: ONE warning in the author\'s language, kept on the account as a message\'s', !empty($j['success']) && count($nbm) === 1 && $nbm[0]['type'] === 'warning'
      && $nbm[0]['title'] === 'Warning: one of your private messages was removed' && str_contains($nbm[0]['body'], 'Mind your words')
      && ($db->query("SELECT source_kind FROM user_warnings WHERE user_id = $bob ORDER BY id DESC LIMIT 1")->fetchColumn()) === 'message', json_encode([$j, $nbm]));
$j = $run('admin/message_report_action', 'POST', ['id' => $mr3, 'action' => 'warn', 'reason' => 'Last warning'], [], $panel);
// (The message card writes to the reporter in the language of the request, as it did before 1.71.0.)
check('… Warn: a warning, the report answered (not closed), the reporter told it was handled', !empty($j['success']) && ($notes($bob, $f)[1]['type'] ?? '') === 'warning'
      && $db->query("SELECT status FROM message_reports WHERE id = $mr3")->fetchColumn() === 'open' && str_contains((string)($lastOf($notes($alice, $f))['title'] ?? ''), 'was handled'), json_encode($j));
$j = $run('admin/message_report_action', 'POST', ['id' => $mr3, 'action' => 'mute', 'days' => 1], [], $panel);
$nlast = $lastOf($notes($bob, $f));
check('… and WITHOUT a mode, what it always did: the silenced account told the plain fact ("account")', !empty($j['success']) && ($nlast['type'] ?? '') === 'account'
      && str_contains((string)$nlast['title'], 'silenced'), json_encode($nlast));
check('… the card\'s endpoint names the choice and keeps its pre-1.71 path', str_contains($src('api/admin/message_report_action.php'), "\$mode   = in_array(\$input['mode'] ?? null, ['silent', 'loud'], true) ? (string)\$input['mode'] : '';")
      && str_contains($src('api/admin/message_report_action.php'), "if (\$mode === '') {\n        userNotify(\$db, (int)\$rep['reported_user_id'], 'account', __('notify.message_removed'), __('notify.message_removed_body'));"));
$db->prepare("DELETE FROM message_reports WHERE thread_id = ?")->execute([(int)$t['id']]);

/* ══ 10. the source ═══════════════════════════════════════════════════════ */
$lib = $src('includes/reports.php');
check('the ONE limit call: contentReportFloodCheck() in the member\'s request — the anti-spam layer and then the hour — and rateLimitAllow() nowhere else in the file',
      substr_count($lib, 'contentReportFloodCheck($db, $cfg, $me, $ip, $input, $ticket)') === 1 && substr_count($lib, 'rateLimitAllow(') === 1
      && (bool)preg_match('/function contentReportFloodCheck\(.*?\{.*?antispamCheck\(\$db, \$cfg, \'report\'.*?rateLimitAllow\(\'creport\'/s', $lib));
check('routes: content_report, admin/content_reports, admin/content_report_action — each to its file, each handing the request to its function',
      str_contains($src('api.php'), "'content_report'             => 'api/content_report.php',")
      && str_contains($src('api/content_report.php'), '$r = contentReportRequest($db, $cfg, usersEnabled($cfg) ? currentUser($db) : null, readJsonBody(), getClientIp($cfg));')
      && str_contains($src('api/admin/content_report_action.php'), '$r = contentReportActionRequest($db, $cfg, readJsonBody());'));
$dash = $src('templates/admin/dashboard.php');
check('the Reports page: a tab per kind, only while it is on and viewable, the torrent tabs only with their own permission',
      str_contains($dash, "\$torrentTabs = panelCan(\$db, \$cfg, 'panel.reports.view');") && str_contains($dash, "contentReportPanelKinds(\$db, \$cfg), fn(\$k) => \$k['on']")
      && str_contains($dash, 'data-crep-kind="<?= $k ?>"') && str_contains($dash, 'id="crep-badge-<?= $k ?>"') && str_contains($dash, 'id="crep-view"'));
check('the public script: loaded after comments.js for a reader who may report, its strings public, its token the page\'s',
      ($p1 = strpos($src('templates/layout.php'), 'assets/js/comments.js')) !== false && strpos($src('templates/layout.php'), 'assets/js/reports.js') > $p1
      && in_array('js.report.', LANG_JS_PUBLIC, true) && str_contains($src('assets/js/reports.js'), 'csrf_token: csrfToken(box)')
      && str_contains($src('assets/js/reports.js'), 'window.Comments.onActions('));
check('the author\'s warning is written in THEIR language and names nobody who reported', str_contains($lib, '$lang = reportLangFor($cfg, $user);')
      && !preg_match('/userWarn\([^;]*reporter/', $lib));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
