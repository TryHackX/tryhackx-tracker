<?php
/**
 * Comments on a torrent (1.71.0, includes/comments.php):
 *   php tests/comments_test.php
 *
 * What the owner asked for — "safely: emoji, simple BBCode without pictures, a link perhaps, a length the
 * operator sets, a new notification sound that somebody commented; permissions and the rest" — and what the
 * brief added (guests, the flood hook, the moderation hook), pinned:
 *
 *   1. the schema, on BOTH paths — a fresh install's CREATE and an upgrade's guarded statements — and the
 *      grant, once, on a scratch database built here and dropped again;
 *   2. the settings in their four places (defaults, the save's allow-list and clamps, the search catalogue,
 *      the Settings page) and what they clamp to; the two sound defaults; the group's new name;
 *   3. the permissions: registered, in the presets, NO with accounts off, nothing for guests;
 *   4. the renderer's allow-list against hostile input, read back through a DOM — only these elements and
 *      attributes, whatever went in: [img], [url=javascript:], nested quotes, entities, RTL and zero-width
 *      characters, emote codes, :fa-…:, links off;
 *   5. the limits: visible characters, the source, lines, links, the raw body;
 *   6. writing one (commentPostRequest(), the whole endpoint): every refusal in its order — the token, the
 *      switch, the hash, the reader's view, the permission, the mute, the hourly limit — and a success;
 *   7. guests: nothing without the grant, no CAPTCHA provider no guest, a CAPTCHA every time, held for a
 *      moderator, no links, no identity to edit or delete with, let through by a moderator;
 *   8. the thread (commentListRequest()): pages newest first read oldest first, "earlier", a moderator sees
 *      what is held, a blocked author folded away;
 *   9. correcting and taking back: the windows, the mute, a moderator with a reason — audited, the author
 *      told, or (part E's choice) not;
 *  10. who is told (commentNotifyNew()): the registrant, the description's author, the thread, the mentioned
 *      — once each, never the author, never across a block, each by their own switch, in their language,
 *      an unread thread not repeated; the pulse's counts; the two sounds;
 *  11. the account going: its comments with it, its moderation stamps forgotten;
 *  12. the endpoint FILES, run as requests in a child process with a session and its token;
 *  13. (1.72.0) replies: the tree and its depth — the server refusing past it, the permission, the guest rules on a
 *      reply, the setting lowered (old replies kept, new ones refused), tombstones, a page bounded however long a
 *      thread is, the reply's notification and blocks, the account going under other people's replies.
 *
 * Every switch a check leans on is in $cfgOn — nothing is inherited from this database's live settings.
 * Self-cleaning: the accounts it makes (and with them their comments and notifications), the two catalogue
 * rows, the temporary sticker, the guest group's JSON, the rate-limit keys and the audit lines are removed or
 * put back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/sounds.php', 'includes/shout.php',
          'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/icons.php', 'includes/settings_catalog.php',
          'includes/comments.php', 'includes/lang.php'] as $f) require_once $root . '/' . $f;

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
    'comments_enabled' => '1', 'comment_max_chars' => '500', 'comment_links' => '1', 'comment_edit_minutes' => '15',
    'comment_delete_own_minutes' => '60', 'comments_per_page' => '5', 'comment_rate_per_hour' => '1000',
    'captcha_pts_comment' => '1', 'comments_guest_review' => '1', 'link_trusted_domains' => 'example.org',
    'comments_position' => 'after_files', 'comments_expanded' => '0', 'comments_reply_depth' => '3',
    'recaptcha_enabled' => '0', 'default_language' => 'en',
    'shout_enabled' => '1', 'shout_emotes_enabled' => '1', 'shout_stickers_enabled' => '1', 'emotes_everywhere' => '1',
    // The site's anti-spam layer (1.71.0) is tests/antispam_test.php's: off here, so a comment's own rules are what
    // is measured — the guests' CAPTCHA is its own switch and still asks every time.
    'antispam_enabled' => '0', 'antispam_guest_captcha' => '1',
]);
$GLOBALS['cfg'] = $cfgOn;
$cfgCaptcha = array_merge($cfgOn, ['recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha',
                                   'recaptcha_site_key' => 'site-key-test', 'recaptcha_secret' => 'secret-test']);
$verify = ['verify' => fn(string $tok): bool => $tok === 'good-token'];

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
const CM_USERS = ['cmtest_alice', 'cmtest_bob', 'cmtest_carol', 'cmtest_dave', 'cmtest_erin', 'cmtest_mod', 'cmtest_mute', 'cmtest_rate', 'cmtest_http',
                  // 13. replies (1.72.0)
                  'cmtest_rp_a', 'cmtest_rp_b', 'cmtest_rp_c', 'cmtest_rp_x', 'cmtest_rp_mod', 'cmtest_rp_n',
                  // 10b. the copies follow the comment (1.74.0)
                  'cmtest_gone'];
const CM_H1 = 'c0c1c0c1c0c1c0c1c0c1c0c1c0c1c0c1c0c1c0c1';   // the thread's torrent: an index row + a whitelist row
const CM_H2 = 'c0c2c0c2c0c2c0c2c0c2c0c2c0c2c0c2c0c2c0c2';   // a hash nobody may see (no row at all)
const CM_H3 = 'c0c5c0c5c0c5c0c5c0c5c0c5c0c5c0c5c0c5c0c5';   // 13. the replies' torrent: an index row
const CM_H4 = 'c0c6c0c6c0c6c0c6c0c6c0c6c0c6c0c6c0c6c0c6';   // 13. another torrent (a parent from somewhere else)
const CM_GUEST_IP = '198.51.100.23';
$guestBefore = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn();
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$tmpFiles = [];
$stickerId = 0;
$cleanRate = function (): void {
    $file = dirname(__DIR__) . '/config/rate_limits.json';
    $lock = @fopen($file . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $raw = is_file($file) ? @file_get_contents($file) : '';
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    foreach (array_keys($data) as $k) {
        if (str_starts_with($k, 'comment|') || str_starts_with($k, 'rtpreview|')) unset($data[$k]);
    }
    rateLimitWrite($file, $data);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
};
register_shutdown_function(function () use ($db, $guestBefore, $auditFloor, &$tmpFiles, &$stickerId, $cleanRate) {
    foreach (CM_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) userDeleteCascade($db, $id);
    }
    foreach ([CM_H1, CM_H2, CM_H3, CM_H4] as $h) {
        $db->prepare("DELETE FROM hash_comments WHERE info_hash = ?")->execute([$h]);
        $db->prepare("DELETE FROM index_hashes WHERE info_hash = ?")->execute([$h]);
        $db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$h]);
    }
    $db->exec("DELETE m FROM user_group_members m JOIN user_groups g ON g.id = m.group_id WHERE g.slug = 'cmtest_noreply'");
    $db->exec("DELETE FROM user_groups WHERE slug = 'cmtest_noreply'");
    if ($stickerId > 0) $db->prepare("DELETE FROM shout_emotes WHERE id = ?")->execute([$stickerId]);
    // The guest's row in the anti-spam layer's table: a guest's CAPTCHA is asked through the layer (1.71.0), which
    // keeps a row per address group even while its pacing is off (the accounts' rows go with userDeleteCascade()).
    try { $db->prepare("DELETE FROM antispam_state WHERE subject = ?")->execute(['g:' . ipBucket(CM_GUEST_IP)]); } catch (\Throwable $e) { /* no table: nothing kept */ }
    $db->exec("DELETE m FROM user_group_members m JOIN user_groups g ON g.id = m.group_id WHERE g.slug = 'cmtest_reader'");
    $db->exec("DELETE FROM user_groups WHERE slug = 'cmtest_reader'");
    if ($guestBefore !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([$guestBefore]);
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
    $cleanRate();
    if (!(int)$db->query("SELECT COUNT(*) FROM hash_comments")->fetchColumn()) {
        try { $db->exec("ALTER TABLE hash_comments AUTO_INCREMENT = 1"); } catch (\Throwable $e) { /* not ours to insist */ }
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach ($tmpFiles as $f) @unlink($f);
});
$cleanRate();

/** A verified member, made fresh: userEffectivePermissions() memoizes per account for the whole process. */
function cmUser(PDO $db, array $cfg, string $name): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    userPermissionsForget($id);
    return $id;
}
$u = function (int $id) use ($db): array { return userFindById($db, $id) ?? []; };
$notes = function (int $uid, int $floor = 0) use ($db): array {
    $st = $db->prepare("SELECT type, title, body, link, read_at FROM user_notifications WHERE user_id = ? AND id > ? ORDER BY id");
    $st->execute([$uid, $floor]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$noteFloor = fn(): int => (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM user_notifications")->fetchColumn();

// A session of this process's own: the CSRF token and the CAPTCHA's points live in it.
session_id('cmtest' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['csrf_token'] = 'cm-test-token';
$T = 'cm-test-token';

/* ══ 1. the schema, on both paths ═════════════════════════════════════════ */
check('the schema is at 83 or later, and its line says what 83 is', TRACKER_SCHEMA_VERSION >= 83
      && str_contains($src('includes/schema.php'), '83 = comments on a torrent (includes/comments.php): `hash_comments`'));
check('1.72.0: the schema is at 86 or later, and its line says what 86 is — the two settings of where the section stands and how it opens',
      TRACKER_SCHEMA_VERSION >= 86 && str_contains($src('includes/schema.php'), "86 = where the Info panel's comments stand and whether they open folded (includes/comments.php): `comments_position`"));
check('1.72.0: the schema is at 88 or later, and its line says what 88 is — replies to comments, as a tree',
      TRACKER_SCHEMA_VERSION >= 88 && str_contains($src('includes/schema.php'), '88 = replies to comments, as a tree (includes/comments.php): `hash_comments`.parent_id'));
$create = '';
foreach (trackerSchemaStatements() as $sql) if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `hash_comments`')) $create = $sql;
check('a fresh install creates hash_comments with its keys: a thread by hash/status/id, a member\'s, the guest queue',
      $create !== '' && str_contains($create, 'KEY `idx_hc_hash` (`info_hash`, `status`, `id`)') && str_contains($create, 'KEY `idx_hc_user` (`user_id`, `created_at`)')
      && str_contains($create, 'KEY `idx_hc_status` (`status`, `created_at`)')
      && str_contains($create, "`status` ENUM('visible','pending','deleted') NOT NULL DEFAULT 'visible'")
      && str_contains($create, "`body_format` ENUM('bbcode','plain') NOT NULL DEFAULT 'bbcode'") && str_contains($create, '`delete_reason` VARCHAR(255)'));
check('1.72.0: … and the reply\'s place — parent, thread, depth — with the key a page reads a thread by (hash, root, id, status)',
      str_contains($create, '`parent_id` BIGINT UNSIGNED DEFAULT NULL') && str_contains($create, '`root_id` BIGINT UNSIGNED DEFAULT NULL')
      && str_contains($create, '`depth` TINYINT UNSIGNED NOT NULL DEFAULT 0') && str_contains($create, 'KEY `idx_hc_thread` (`info_hash`, `root_id`, `id`, `status`)'));
check('this database has the reply columns and their key', schemaColumnExists($db, 'hash_comments', 'parent_id') && schemaColumnExists($db, 'hash_comments', 'root_id')
      && schemaColumnExists($db, 'hash_comments', 'depth') && schemaIndexExists($db, 'hash_comments', 'idx_hc_thread')
      && schemaColumnDefault($db, 'users', 'comment_notify') === (string)COMMENT_NOTIFY_ALL);
check('this database has the table, users.comment_notify and user_notifications.link',
      schemaTableExists($db, 'hash_comments') && schemaColumnExists($db, 'users', 'comment_notify') && schemaColumnExists($db, 'user_notifications', 'link'));
$scratch = 'tracker_cm_' . bin2hex(random_bytes(3));
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
        $fresh = schemaTableExists($sdb, 'hash_comments') && schemaColumnExists($sdb, 'users', 'comment_notify') && schemaColumnExists($sdb, 'user_notifications', 'link');
        $guarded = trackerSchemaGuardedStatements($sdb);
        $flat = implode("\n", array_map(fn($s) => is_array($s) ? $s[0] : $s, $guarded));
        check('fresh: everything is there, and the guarded list adds no column that already is',
              $fresh && !str_contains($flat, 'ADD COLUMN `comment_notify`') && !str_contains($flat, 'ADD COLUMN `link`'));
        foreach ($guarded as $s) { foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); break; } catch (\Throwable $e) { /* next */ } } }
        // an UPGRADE: a v82 database has none of the three
        $sdb->exec("DROP TABLE hash_comments");
        $sdb->exec("ALTER TABLE users DROP COLUMN comment_notify");
        $sdb->exec("ALTER TABLE user_notifications DROP COLUMN link");
        $sdb->exec("INSERT INTO users (username, pass_hash) VALUES ('old_account', 'x')");
        $guarded = trackerSchemaGuardedStatements($sdb);
        $errs = [];
        foreach ($guarded as $s) {
            $last = null;
            foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); $last = null; break; } catch (\Throwable $e) { $last = $e; } }
            if ($last) $errs[] = $last->getMessage();
        }
        check('upgrade: the guarded statements bring the table and both columns back, without an error',
              !$errs && schemaTableExists($sdb, 'hash_comments') && schemaColumnExists($sdb, 'users', 'comment_notify')
              && schemaColumnExists($sdb, 'user_notifications', 'link'), implode(' | ', $errs));
        check('… an existing account is told of every kind (31 since 1.72.0: the reply\'s with the four), like a new one',
              (int)$sdb->query("SELECT comment_notify FROM users WHERE username = 'old_account'")->fetchColumn() === COMMENT_NOTIFY_ALL);
        $a = ''; foreach (trackerSchemaStatements() as $s) if (str_contains($s, 'CREATE TABLE IF NOT EXISTS `hash_comments`')) $a = $s;
        $b = ''; foreach ($guarded as $s) if (is_string($s) && str_contains($s, 'CREATE TABLE IF NOT EXISTS `hash_comments`')) $b = $s;
        $norm = fn($s) => preg_replace('/\s+/', ' ', preg_replace('/^\s*--.*$/m', '', $s));
        check('… from the SAME definition as a fresh install\'s (a split between the two lists is the old fresh-install bug)', $a !== '' && $norm($a) === $norm($b));
        // 1.72.0 (v88): a v87 database — hash_comments without the reply columns, users.comment_notify still DEFAULT 15.
        $shape = fn(): string => preg_replace('/ AUTO_INCREMENT=\d+/', '', (string)$sdb->query("SHOW CREATE TABLE hash_comments")->fetch(PDO::FETCH_NUM)[1]);
        $freshShape = $shape();
        $sdb->exec("ALTER TABLE hash_comments DROP KEY idx_hc_thread, DROP COLUMN parent_id, DROP COLUMN root_id, DROP COLUMN depth");
        $sdb->exec("ALTER TABLE users ALTER COLUMN comment_notify SET DEFAULT 15");
        $sdb->exec("INSERT INTO hash_comments (info_hash, user_id, body) VALUES (REPEAT('e', 40), 1, 'written at v87')");
        $sdb->exec("INSERT INTO users (username, pass_hash, comment_notify) VALUES ('v87_all', 'x', 15), ('v87_quiet', 'x', 7)");
        $errs = [];
        foreach (trackerSchemaGuardedStatements($sdb) as $s) {
            $last = null;
            foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); $last = null; break; } catch (\Throwable $e) { $last = $e; } }
            if ($last) $errs[] = $last->getMessage();
        }
        $old = $sdb->query("SELECT parent_id, root_id, depth FROM hash_comments WHERE body = 'written at v87'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $bits = $sdb->query("SELECT username, comment_notify FROM users WHERE username IN ('v87_all', 'v87_quiet') ORDER BY username")->fetchAll(PDO::FETCH_KEY_PAIR);
        check('1.72.0: an upgrade from 87 — the reply columns and key where a fresh table has them (one shape), a comment written before a top-level one',
              !$errs && $shape() === $freshShape && array_key_exists('parent_id', $old) && $old['parent_id'] === null
              && array_key_exists('root_id', $old) && $old['root_id'] === null && (int)($old['depth'] ?? 9) === 0,
              implode(' | ', $errs) . ' ' . json_encode($old));
        check('… every account is told of replies from now on (bit 16 ON, the rest as it was), and the column\'s default is 31 — once: a second run asks nothing',
              $bits === ['v87_all' => 31, 'v87_quiet' => 23] && schemaColumnDefault($sdb, 'users', 'comment_notify') === '31'
              && !preg_grep('/comment_notify` \| 16|ALTER TABLE `hash_comments` ADD/', array_map(fn($s) => is_array($s) ? $s[0] : $s, trackerSchemaGuardedStatements($sdb))),
              json_encode($bits));
        $sdb->exec("INSERT IGNORE INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('moderator', 'Moderator', 'm', 500, 0, 1, '{}')");
        $sdb->exec("UPDATE user_groups SET permissions = '{\"index.view\":true}' WHERE slug IN ('member','guest')");
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        $pm = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
        $pd = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'moderator'")->fetchColumn(), true) ?: [];
        $pg = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn(), true) ?: [];
        check('the grant: members read, write, correct and take back their own; moderators read, write and moderate; guests nothing',
              !empty($pm['comment.view']) && !empty($pm['comment.post']) && !empty($pm['comment.edit_own']) && !empty($pm['comment.delete_own'])
              && empty($pm['comment.moderate']) && !empty($pd['comment.view']) && !empty($pd['comment.post']) && !empty($pd['comment.moderate'])
              && !array_filter(array_keys($pg), fn($k) => str_starts_with($k, 'comment.')), json_encode([$pm, $pd, $pg]));
        check('1.72.0 (v88): … and both may reply — the guest group still nothing',
              !empty($pm['comment.reply']) && !empty($pd['comment.reply']) && empty($pg['comment.reply']));
        $sdb->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"comment.post\"') WHERE slug = 'member'");
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        $pm = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
        check('… ONCE: a permission the operator takes away afterwards is not given back by the next migration', empty($pm['comment.post']));
    } finally {
        $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
} catch (\Throwable $e) {
    check('the scratch database for the schema checks', false, $e->getMessage());
}

/* ══ 2. the settings, in their four places ════════════════════════════════ */
$defaults = trackerSchemaDefaultSettings();
$want = ['comments_enabled' => '1', 'comment_max_chars' => '500', 'comment_links' => '1', 'comment_edit_minutes' => '15',
         'comment_delete_own_minutes' => '60', 'comments_per_page' => '20', 'comment_rate_per_hour' => '30',
         'captcha_pts_comment' => '1', 'comments_guest_review' => '1', 'sound_default_comment' => '', 'sound_default_comment_mention' => '',
         // 1.72.0: where the Info panel's section stands (its very end) and whether it opens unfolded (no); how deep a
         // thread of replies may go (3), and the reply's sound (none chosen — the project's rule)
         'comments_position' => 'after_files', 'comments_expanded' => '0', 'comments_reply_depth' => '3', 'sound_default_comment_reply' => ''];
$save = $src('api/admin/save_settings.php');
$tpl = $src('templates/admin/settings.php');
$kw = settingsCatalogKeywords();
$missing = [];
foreach ($want as $k => $v) {
    if (($defaults[$k] ?? null) !== $v) $missing[] = "$k default";
    if (!str_contains($save, "'$k'")) $missing[] = "$k save";
    if (!isset($kw[$k])) $missing[] = "$k catalogue";
    if (!str_contains($tpl, 'name="' . $k . '"')) $missing[] = "$k page";
}
check('every comment setting and both sound defaults: shipped with its default, saved, found by the search, on the page', $missing === [], implode(', ', $missing));
check('the numbers are clamped on save, the four switches coerced to 0/1 (1.72.0: whether the section opens unfolded, the fourth)',
      str_contains($save, "'comment_max_chars' => [COMMENT_MAX_MIN, COMMENT_MAX_MAX, COMMENT_MAX_DEFAULT]")
      && str_contains($save, "'comment_edit_minutes' => [0, 1440, 15], 'comment_delete_own_minutes' => [0, 1440, 60]")
      && str_contains($save, "'comments_per_page' => [COMMENT_PAGE_MIN, COMMENT_PAGE_MAX, COMMENT_PAGE_DEFAULT]")
      && str_contains($save, "'comment_rate_per_hour' => [1, 1000, 30], 'captcha_pts_comment' => [0, 100, 1]")
      && (bool)preg_match("/'comments_enabled', 'comment_links', 'comments_guest_review', 'comments_expanded',\s*\n\s*'profile_descriptions_enabled'/", $save)
      && (bool)preg_match("/'covers_enabled', 'profile_bio_enabled'\] as \\\$k\)/", $save));
// 1.72.0: where the section stands is a closed set — saved as one of the three or as the default, read the same way.
check('the section\'s place is one of three (after the rating, before the files, after them — the default), coerced on save and on read; unfolded only when said',
      COMMENT_POSITIONS === ['after_rating', 'before_files', 'after_files'] && COMMENT_POSITION_DEFAULT === 'after_files'
      && str_contains($save, "if (isset(\$data['comments_position']) && !in_array(\$data['comments_position'], COMMENT_POSITIONS, true)) {\n    \$data['comments_position'] = COMMENT_POSITION_DEFAULT;")
      && commentsPosition([]) === 'after_files' && commentsPosition(['comments_position' => 'below']) === 'after_files'
      && commentsPosition(['comments_position' => 'before_files']) === 'before_files' && commentsPosition(['comments_position' => 'after_rating']) === 'after_rating'
      && commentsExpanded([]) === false && commentsExpanded(['comments_expanded' => '1']) === true && commentsExpanded(['comments_expanded' => 'yes']) === false);
check('… and the comments\' sound defaults (1.72.0: the reply\'s with them) are judged against the library whether comments are on or not',
      str_contains($save, "foreach (array_unique(array_merge(soundEventKinds(), ['comment', 'comment_mention', 'comment_reply'])) as \$k) {"));
check('1.72.0: the reply depth is clamped on save 0..8 (0 a real answer, no replies) and on read — anything but a number is the shipped 3',
      str_contains($save, "'comments_reply_depth' => [0, COMMENT_REPLY_DEPTH_MAX, COMMENT_REPLY_DEPTH_DEFAULT],")
      && COMMENT_REPLY_DEPTH_MAX === 8 && COMMENT_REPLY_DEPTH_DEFAULT === 3
      && commentsReplyDepth([]) === 3 && commentsReplyDepth(['comments_reply_depth' => '0']) === 0 && commentsReplyDepth(['comments_reply_depth' => '99']) === 8
      && commentsReplyDepth(['comments_reply_depth' => '-2']) === 0 && commentsReplyDepth(['comments_reply_depth' => 'deep']) === 3
      && commentsReplyDepth(['comments_reply_depth' => '5']) === 5);
check('… and the Settings page says under the field what happens at the limit, and when it is lowered',
      str_contains($tpl, 'data-setting="comments_reply_depth"') && str_contains($tpl, "__('settings.comments_reply_depth_hint', ['max' => COMMENT_REPLY_DEPTH_MAX])")
      && str_contains(langFor('en', 'settings.comments_reply_depth_hint'), 'At the limit') && str_contains(langFor('en', 'settings.comments_reply_depth_hint'), 'Lowered later')
      && str_contains(langFor('pl', 'settings.comments_reply_depth_hint'), 'Na granicy'));
check('the readers clamp: the length 20..5000, the page 5..100, the windows 0..1440, the hour 1..1000',
      commentMax(['comment_max_chars' => '3']) === 20 && commentMax(['comment_max_chars' => '99999']) === 5000 && commentMax([]) === 500
      && commentsPerPage(['comments_per_page' => '1']) === 5 && commentsPerPage(['comments_per_page' => '999']) === 100
      && commentEditMinutes(['comment_edit_minutes' => '-3']) === 0 && commentDeleteOwnMinutes(['comment_delete_own_minutes' => '99999']) === 1440
      && commentRatePerHour(['comment_rate_per_hour' => '0']) === 30 && commentSourceCap(['comment_max_chars' => '500']) === 2000
      && commentSourceCap(['comment_max_chars' => '5000']) === COMMENT_SOURCE_CHARS);
$sec = (int)strpos($tpl, 'id="section-comments"');
$secEnd = (int)strpos($tpl, 'class="settings-section"', $sec + 30);
$secTxt = $sec > 0 ? substr($tpl, $sec, $secEnd - $sec) : '';
check('Settings: a Comments section in the content group, between the descriptions and the ratings, with the permissions\' fold (and, 1.72.0, the place and the opening)',
      $sec > (int)strpos($tpl, 'id="section-content"') && $sec < (int)strpos($tpl, 'id="section-reputation"')
      && str_contains($secTxt, 'data-group="content"') && str_contains($secTxt, 'id="comment-matrix-wrap"')
      && str_contains($secTxt, 'data-setting="comments_position"') && str_contains($secTxt, 'data-setting="comments_expanded"')
      && str_contains($secTxt, "<?php foreach (COMMENT_POSITIONS as \$cmPos): ?>")
      && str_contains($src('assets/js/admin-shout.js'), "{ wrap: 'comment-matrix-wrap', table: 'comment-matrix',")
      && str_contains($secTxt, "__('settings.comments_guests_intro', ['captcha' => '#section-captcha'])"));
$groupTitles = array_column(settingsCatalogGroups(), 'title', 'id');
check('… whose group says so now: "Descriptions, comments & ratings" / "Opisy, komentarze i oceny"',
      ($groupTitles['content'] ?? '') === 'Descriptions, comments & ratings' && langFor('pl', 'settings.group_content') === 'Opisy, komentarze i oceny');
$guestHelp = langFor('en', 'settings.comments_guests_intro');
check('the settings help says the guests\' rules: the grant, "Guest #tag", a CAPTCHA every time, no provider no guests, no links, no edit, not told, not @-mentioned, held',
      str_contains($guestHelp, 'comment.post') && str_contains($guestHelp, 'Guest #4f2a') && str_contains($guestHelp, 'every time')
      && str_contains($guestHelp, 'cannot comment at all') && str_contains($guestHelp, 'never post a') && str_contains($guestHelp, 'cannot correct or delete')
      && str_contains($guestHelp, 'is told nothing') && str_contains($guestHelp, '@-mentioned') && str_contains($guestHelp, 'lets each one through first'));
check('the Sounds section offers the two only while comments are on',
      (bool)preg_match("/if \(function_exists\('commentsEnabled'\) && commentsEnabled\(\\\$cfg\)\): \?>\s*<div class=\"col-md-3\" data-setting=\"sound_default_comment\">/", $tpl));

/* ══ 3. the permissions ════════════════════════════════════════════════════ */
$reg = userPermissionList();
$presets = userGroupPresets();
check('five ids are registered', isset($reg['comment.view'], $reg['comment.post'], $reg['comment.edit_own'], $reg['comment.delete_own'], $reg['comment.moderate']));
check('the member preset reads, writes, corrects and takes back its own — and does not moderate',
      !array_diff(['comment.view', 'comment.post', 'comment.edit_own', 'comment.delete_own'], $presets['member']['perms'])
      && !in_array('comment.moderate', $presets['member']['perms'], true));
check('the moderator preset reads, writes and moderates', !array_diff(['comment.view', 'comment.post', 'comment.moderate'], $presets['moderator']['perms']));
check('with accounts off: no comments at all — the feature is off and every comment.* id answers no',
      !commentsEnabled(['users_enabled' => '0', 'comments_enabled' => '1']) && !userLegacyDefault('comment.view') && !userLegacyDefault('comment.post')
      && !userLegacyDefault('comment.moderate') && commentsEnabled(['users_enabled' => '1']) && !commentsEnabled(['users_enabled' => '1', 'comments_enabled' => '0']));
check('the migration grants once (v83_comments) to member and moderator, and nothing to guest',
      str_contains($src('includes/schema.php'), "schemaGrantOnce(\$db, 'v83_comments', [\n        'member'    => ['comment.view', 'comment.post', 'comment.edit_own', 'comment.delete_own'],\n        'moderator' => ['comment.view', 'comment.post', 'comment.moderate'],\n    ]);"));
check('1.72.0: comment.reply — registered, in the member and moderator presets, NO with accounts off, granted once (v88_replies) to member and moderator and not to guest',
      isset($reg['comment.reply']) && in_array('comment.reply', $presets['member']['perms'], true) && in_array('comment.reply', $presets['moderator']['perms'], true)
      && !userLegacyDefault('comment.reply')
      && str_contains($src('includes/schema.php'), "schemaGrantOnce(\$db, 'v88_replies', [\n        'member'    => ['comment.reply'],\n        'moderator' => ['comment.reply'],\n    ]);")
      && str_contains($src('assets/js/admin-shout.js'), "ids: ['comment.view', 'comment.post', 'comment.reply', 'comment.edit_own', 'comment.delete_own', 'comment.moderate'] },"));

/* ══ 4. the renderer: the allow-list against hostile input ═════════════════ */
/** Every element and attribute of an HTML fragment, read back through a DOM. */
function cmDom(string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $out = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        if ($el->getAttribute('id') === 'root' && $el->tagName === 'div' && $el->parentNode instanceof DOMDocument) continue;
        $attrs = [];
        foreach ($el->attributes as $a) $attrs[$a->name] = $a->value;
        $out[] = ['tag' => $el->tagName, 'attrs' => $attrs];
    }
    return $out;
}
/** Only what a comment may draw: the tags this renderer writes, each with only its own attributes. */
function cmAllowed(array $els): array {
    $bad = [];
    foreach ($els as $e) {
        $t = $e['tag']; $a = $e['attrs'];
        $ok = match ($t) {
            'strong', 'em', 'u', 's', 'br', 'summary' => $a === [],
            'blockquote' => $a === ['class' => 'rt-quote'],
            'cite' => $a === ['class' => 'rt-cite'],
            'details' => $a === ['class' => 'rt-spoiler'],
            'div' => $a === ['class' => 'rt-spoiler-body'],
            'i' => ($a['class'] ?? '') === 'bi bi-chevron-right disc-chev' && ($a['aria-hidden'] ?? '') === 'true' && count($a) === 2,
            'pre' => $a === ['class' => 'rt-code'],
            'code' => $a === [] || $a === ['class' => 'rt-inline'],
            'a' => (isset($a['href']) && ((str_starts_with($a['href'], 'https://') || str_starts_with($a['href'], 'http://'))
                        && ($a['rel'] ?? '') === 'nofollow noopener noreferrer ugc' && ($a['target'] ?? '') === '_blank'
                        && !array_diff(array_keys($a), ['href', 'rel', 'target', 'data-external'])
                     || (str_contains($a['href'], '?action=u&name=') && str_starts_with($a['class'] ?? '', 'shout-mention')
                        && !array_diff(array_keys($a), ['href', 'class'])))),
            'img' => ($a['class'] ?? '') === 'rt-emote' && emoteSafeUrl((string)($a['src'] ?? ''))
                     && !array_diff(array_keys($a), ['class', 'src', 'alt', 'title', 'loading', 'decoding', 'width', 'height']),
            default => false,
        };
        if (!$ok) $bad[] = $t . json_encode($a);
    }
    return $bad;
}
$R = fn(string $s, bool $links = true): string => commentParse(commentClean($s), $cfgOn, $links)['html'];
$hostile = [
    '<script>alert(1)</script> <img src=x onerror=alert(1)> <a href="javascript:x">x</a>',
    '[img]https://x.example/a.png[/img] [IMG=1]https://x.example/b.png[/IMG] [table][tr][td]x[/td][/tr][/table]',
    '[size=40]big[/size] [color=red]r[/color] [font=Arial]f[/font] [center]c[/center] [hr] [list][*]a[/list] [youtube]abc[/youtube]',
    '[url=javascript:alert(1)]x[/url] [url]javascript:alert(1)[/url] [url=data:text/html;base64,PHNjcmlwdD4=]d[/url] [url=vbscript:x]v[/url]',
    "[url=\"https://a.example\" onmouseover=\"x\"]q[/url] [url=https://a.example onmouseover=x]r[/url] [url=java\tscript:alert(1)]t[/url]",
    '[url=https://ok.example/?a=1&b="2"]fine[/url] [url="https://quoted.example"]quoted[/url] [url]https://example.org/x[/url]',
    '[quote]outer [quote]inner[/quote] tail[/quote] [quote=<b>bob</b>]x[/quote] [quote=" onclick="x]y[/quote]',
    '[spoiler=<img src=x onerror=1>]s[/spoiler] [spoiler]a [spoiler]b[/spoiler] c[/spoiler]',
    '[code]<script>x</script> [b]not bold[/b] [url=https://a.example]not a link[/url] :fa-rocket: @smokeuser[/code]',
    "&lt;script&gt; &amp;amp; &#60;b&#62; \u{202E}reversed\u{202C} zero\u{200B}width\u{2060}joiner \u{FEFF}bom",
    '[b][i]crossed[/b][/i] [/u] stray [b]unclosed [u]deeper',
    str_repeat('[b]', 20) . 'deep' . str_repeat('[/b]', 20),
];
$bad = [];
foreach ($hostile as $i => $h) foreach (cmAllowed(cmDom($R($h))) as $b) $bad[] = "#$i $b";
check('twelve hostile texts: the output holds only the comment\'s own elements, each with only its own attributes', $bad === [], implode(' | ', $bad));
check('no script, no event handler inside a tag, no javascript:/data:/vbscript: address anywhere in any of them (the text may SAY those words)',
      !array_filter(array_map($R, $hostile), fn($h) => str_contains(strtolower($h), '<script') || preg_match('/<[^>]+\son[a-z]+\s*=/i', $h)
                                                        || preg_match('/href="\s*(?:javascript|data|vbscript):/i', $h)));
check('[b] [i] [u] [s] become strong, em, u, s', $R('[b]b[/b] [I]i[/I] [u]u[/u] [s]s[/s]') === '<strong>b</strong> <em>i</em> <u>u</u> <s>s</s>');
check('a picture, a table, a size, a colour are the text that was typed',
      $R('[img]https://x.example/a.png[/img]') === '[img]https://x.example/a.png[/img]' && $R('[color=red]r[/color]') === '[color=red]r[/color]');
$qq = $R('[quote]outer [quote]inner[/quote] tail[/quote] after');
check('ONE level of quote: an inner [quote] and its closer are text, and the outer quote ends where the writer ended it',
      $qq === '<blockquote class="rt-quote">outer [quote]inner[/quote] tail</blockquote> after', $qq);
check('a quote\'s name and a spoiler\'s title are escaped text', str_contains($R('[quote=<b>bob</b>]x[/quote]'), '<cite class="rt-cite">&lt;b&gt;bob&lt;/b&gt;</cite>')
      && str_contains($R('[spoiler=<img src=x>]s[/spoiler]'), '&lt;img src=x&gt;</summary>'));
$code = $R("[code]<b> [b]x[/b] @smokeuser :fa-rocket:[/code] and [code]a\n  b[/code]");
check('[code] keeps everything literal, one line inline and several a <pre> with its indentation',
      str_contains($code, '<code class="rt-inline">&lt;b&gt; [b]x[/b] @smokeuser :fa-rocket:</code>') && str_contains($code, "<pre class=\"rt-code\"><code>a\n  b</code></pre>"), $code);
$ul = $R('[url=https://evil.example]go[/url] [url]https://example.org/x[/url]');
check('a link: nofollow noopener noreferrer ugc, a new tab, and the "you are leaving" question for another site — not for a trusted one',
      str_contains($ul, '<a href="https://evil.example" rel="nofollow noopener noreferrer ugc" target="_blank" data-external="1">go</a>')
      && str_contains($ul, '<a href="https://example.org/x" rel="nofollow noopener noreferrer ugc" target="_blank">https://example.org/x</a>'), $ul);
$off = commentParse(commentClean('[url=https://example.org]x[/url] and [url]https://example.org[/url]'), $cfgOn, false);
check('links OFF: a [url] is the text it is — and counted, so the save can refuse it', !str_contains($off['html'], '<a') && $off['url_tags'] === 2);
check('HTML and entities are text: "&lt;" arrives as "&amp;lt;", a tag as its escaped self', $R('&lt;x&gt; <b>') === '&amp;lt;x&amp;gt; &lt;b&gt;');
$clean = commentClean("a\u{202E}b\u{202C}c\u{2066}d\u{2069} z\u{200B}w\u{2060}j\u{FEFF}q \u{200D}keep\u{200C} \x01\x7F");
check('bidi overrides and isolates, zero-width and invisible characters, controls: out; the joiners kept',
      $clean === "abcd zwjq \u{200D}keep\u{200C}", json_encode($clean));
check('a line break is kept, at most two in a row; a tab is four spaces; the end of a line loses its spaces',
      commentClean("a  \n\n\n\nb\tc  ") === "a\n\nb    c" && $R("a\nb") === 'a<br>b');
$deep = $R(str_repeat('[b]', 20) . 'deep' . str_repeat('[/b]', 20));
check('at most eight tags open at once: the ninth opener is text', substr_count($deep, '<strong>') === COMMENT_MAX_DEPTH && str_contains($deep, '[b]deep'), $deep);
check('a closer that crosses another tag closes both and reopens the inline one; a stray closer is text; an unclosed tag is closed',
      $R('[b][i]x[/b]y[/i]') === '<strong><em>x</em></strong><em>y</em>' && $R('a[/u]b') === 'a[/u]b' && $R('[s]open') === '<s>open</s>');
// the stage after the walk: emotes as the words' size, a sticker drawn as one, Font Awesome's tokens, @-names
$db->prepare("INSERT INTO shout_emotes (code, name, mime, bytes, width, height, is_sticker, enabled, approved_at, uploaded_by, sha1, data)
              VALUES ('cmtest_sticker', 'Test sticker', 'image/svg+xml', 60, 64, 64, 1, 1, NOW(), NULL, ?, ?)")
   ->execute([sha1('cmtest' . microtime()), '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"></svg>']);
$stickerId = (int)$db->lastInsertId();
shoutEmotesInvalidate();
check('the comment context draws a sticker as an emote (no big pictures), and its picker offers no sticker',
      emoteStickerMode('comment', $cfgOn) === 'emote' && (emoteMapFor($db, $cfgOn, 'comment', '/')['cmtest_sticker']['draw'] ?? '') === 'emote'
      && !array_filter(emotePickerRows($db, $cfgOn, 'comment', '/'), fn($r) => !empty($r['sticker'])) && in_array('comment', EMOJI_PICKER_CONTEXTS, true));
$ctx = commentRenderContext($db, $cfgOn, null, ['x :cmtest_sticker: @smokeuser']) + ['links' => true];
$full = commentRenderHtml('x :cmtest_sticker: @smokeuser [code]:cmtest_sticker: @smokeuser[/code]', 'bbcode', $cfgOn, $ctx);
check('the rendered comment: the sticker an emote image, @smokeuser a link to the profile — and neither inside [code]',
      preg_match('/<img class="rt-emote" src="[^"]*endpoint=shout_emote&amp;id=' . $stickerId . '&amp;v=[0-9a-f]+"/', $full) === 1
      && str_contains($full, 'class="shout-mention" href="') && str_contains($full, '<code class="rt-inline">:cmtest_sticker: @smokeuser</code>')
      && cmAllowed(cmDom($full)) === [], $full);
$fa = commentRenderHtml(':fa-rocket: [code]:fa-rocket:[/code]', 'bbcode', $cfgOn, ['links' => true]);
check(':fa-NAME: goes through Font Awesome\'s stage outside code (as it would anywhere on the site) and stays typed inside it',
      str_starts_with($fa, emojiFaRenderHtml(':fa-rocket:', $cfgOn)) && str_ends_with($fa, '<code class="rt-inline">:fa-rocket:</code>'), $fa);
check('a format this code does not know is text: escaped, its line breaks kept, no tag',
      commentRenderHtml("[b]x[/b]\n<i>", 'plain', $cfgOn, []) === '[b]x[/b]<br>&lt;i&gt;');

/* ══ 5. the limits ════════════════════════════════════════════════════════ */
$P = fn(string $s, bool $links = true, array $c = []) => commentProblem(commentClean($s), array_merge($cfgOn, $c), $links);
check('the limit counts what a reader sees: 500 visible characters wrapped in tags pass, 501 do not',
      $P('[b]' . str_repeat('x', 500) . '[/b]') === null && ($P(str_repeat('x', 501))['code'] ?? '') === 'too_long'
      && ($P(str_repeat('x', 501))['vars'] ?? []) === ['n' => 501, 'max' => 500]);
check('a character is a code point: 500 emoji are 500', $P(str_repeat("\u{1F600}", 500)) === null);
check('the source has a ceiling of its own (four times the limit): tags cannot store a novel',
      ($P(str_repeat('[b][/b]', 300))['code'] ?? '') === 'too_long_source');
check('at most twenty lines', $P(implode("\n", array_fill(0, 20, 'l'))) === null && ($P(implode("\n", array_fill(0, 21, 'l')))['code'] ?? '') === 'too_many_lines');
check('at most three links; a fourth is refused', $P(str_repeat('[url]https://a.example[/url] ', 3)) === null
      && ($P(str_repeat('[url]https://a.example[/url] ', 4))['code'] ?? '') === 'too_many_links');
check('an address that is not one is refused as such ("must be a full http(s) address")', ($P('[url=nope]x[/url]')['code'] ?? '') === 'bad_link'
      && ($P('[url]https://exa mple.org[/url]')['code'] ?? '') === 'bad_link');
check('links off: any [url] is refused with its own reason', ($P('[url]https://a.example[/url]', false)['code'] ?? '') === 'no_links');
check('empty is empty, whitespace and invisible characters included', ($P(" \n\u{200B} ")['code'] ?? '') === 'empty');

/* ══ fixtures ══════════════════════════════════════════════════════════════ */
$aliceId = cmUser($db, $cfgOn, 'cmtest_alice');   // writes
$bobId   = cmUser($db, $cfgOn, 'cmtest_bob');     // registered the torrent
$carolId = cmUser($db, $cfgOn, 'cmtest_carol');   // wrote its description
$daveId  = cmUser($db, $cfgOn, 'cmtest_dave');    // commented before
$erinId  = cmUser($db, $cfgOn, 'cmtest_erin');    // gets mentioned
$modId   = cmUser($db, $cfgOn, 'cmtest_mod');     // a moderator
$muteId  = cmUser($db, $cfgOn, 'cmtest_mute');
$rateId  = cmUser($db, $cfgOn, 'cmtest_rate');
$modGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'moderator'")->fetchColumn();
userGrantGroup($db, $modId, $modGid, null, 'comments_test', '', false);
userPermissionsForget($modId);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Comments test torrent', 'done')")->execute([CM_H1]);
$db->prepare("INSERT INTO whitelist (info_hash, name, submitter_id, description, description_format, content_status, content_user_id)
              VALUES (?, 'Comments test torrent', ?, 'The words.', 'bbcode', 'approved', ?)")->execute([CM_H1, $bobId, $carolId]);
$post = function (?array $me, string $body, ?array $cfgX = null, array $opts = [], string $hash = CM_H1, string $ip = '127.0.0.44', array $extra = []) use ($db, $cfgOn, $T): array {
    return commentPostRequest($db, $cfgX ?? $cfgOn, $me, ['csrf_token' => $T, 'hash' => $hash, 'body' => $body] + $extra, $ip, $opts);
};

/* ══ 6. writing one: every refusal, in its order ══════════════════════════ */
$r = commentPostRequest($db, $cfgOn, $u($aliceId), ['csrf_token' => 'wrong', 'hash' => CM_H1, 'body' => 'x'], '127.0.0.44');
check('a wrong token first: 403 csrf', $r['status'] === 403 && ($r['body']['error'] ?? '') === 'csrf');
$r = $post($u($aliceId), 'x', array_merge($cfgOn, ['comments_enabled' => '0']));
check('comments off: 404 disabled', $r['status'] === 404 && ($r['body']['error'] ?? '') === 'disabled');
$r = $post($u($aliceId), 'x', null, [], 'nothex');
check('a hash that is not one: 400', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'bad_hash');
$r = $post($u($aliceId), 'x', null, [], CM_H2);
check('a hash nobody may see (no catalogue row): 404, as the Info panel answers', $r['status'] === 404 && ($r['body']['error'] ?? '') === 'not_found');
$r = $post($u($aliceId), 'x', array_merge($cfgOn, ['index_search_enabled' => '0']));
check('… and none while the search is off: the thread is exactly as visible as its panel', $r['status'] === 404);
$r = $post(null, 'x');
check('signed out where guests may not even search: 404 — the torrent is not there for them, as its panel is not',
      $r['status'] === 404 && ($r['body']['error'] ?? '') === 'not_found');
$memberGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'member'")->fetchColumn();
// A group of the run's own that may search and read the comments but not write one.
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('cmtest_reader', 'cmtest reader', 'comments_test', 2, 0, 0, ?)")
   ->execute([json_encode(['index.view' => true, 'whitelist.view' => true, 'comment.view' => true])]);
$readerGid = (int)$db->lastInsertId();
userRevokeGroup($db, $rateId, $memberGid, false);
userGrantGroup($db, $rateId, $readerGid, null, 'comments_test', '', false);
userPermissionsForget($rateId);
$r = $post($u($rateId), 'x');
check('an account that may read but not write (no comment.post): 403 no_permission', $r['status'] === 403 && ($r['body']['error'] ?? '') === 'no_permission');
$rm = commentListRequest($db, $cfgOn, $u($rateId), ['hash' => CM_H1])['body']['me'] ?? [];
check('… and its composer says so', ($rm['can_post'] ?? true) === false && ($rm['why'] ?? '') === 'no_permission');
userRevokeGroup($db, $rateId, $readerGid, false);
userGrantGroup($db, $rateId, $memberGid, null, 'comments_test', '', false);
userPermissionsForget($rateId);
$db->prepare("UPDATE users SET pm_muted_until = NOW() + INTERVAL 2 DAY WHERE id = ?")->execute([$muteId]);
$r = $post($u($muteId), 'x');
check('silenced by a moderator: 403 muted, with the date — the mute messages and shouts obey', $r['status'] === 403 && ($r['body']['error'] ?? '') === 'muted'
      && !empty($r['body']['until']) && str_contains((string)$r['body']['message'], (string)$r['body']['until']));
$r = $post($u($aliceId), "  \n ");
check('an empty comment: 400 empty', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'empty');
$r = commentPostRequest($db, $cfgOn, $u($aliceId), ['csrf_token' => $T, 'hash' => CM_H1, 'body' => str_repeat('x', COMMENT_RAW_BYTES + 1)], '127.0.0.44');
check('a body of more than 256 KB is refused for its size before anything is read', $r['status'] === 413);
$r = $post($u($aliceId), '[img]https://x.example/a.png[/img] ' . str_repeat('y', 480));
check('a text over the limit: 400 too_long, with the numbers', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'too_long');
$cfgLinksOff = array_merge($cfgOn, ['comment_links' => '0']);
$r = $post($u($aliceId), '[url]https://example.org[/url]', $cfgLinksOff);
check('links off: a member\'s [url] is refused ("write the address as plain text")', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'no_links');
$floor = $noteFloor();
$r = $post($u($daveId), 'An earlier comment by dave.');
$daveComment = (int)($r['body']['comment']['id'] ?? 0);
check('a comment: 200, drawn as the thread will draw it, own, editable for its window, deletable for its own',
      $r['status'] === 200 && $daveComment > 0 && ($r['body']['comment']['html'] ?? '') === 'An earlier comment by dave.'
      && $r['body']['comment']['own'] === true && $r['body']['comment']['can_edit'] === true && $r['body']['comment']['can_delete'] === 'own'
      && $r['body']['comment']['edit_left'] > 890 && $r['body']['comment']['del_left'] > 3590 && ($r['body']['count'] ?? 0) === 1, json_encode($r['body']));
$row = commentRow($db, $daveComment);
check('… stored as typed, a member\'s: no guest tag, no address', $row && $row['body'] === 'An earlier comment by dave.' && $row['guest_tag'] === null
      && $row['ip_bucket'] === null && $row['status'] === 'visible' && (int)$row['user_id'] === $daveId);
$cfgRate = array_merge($cfgOn, ['comment_rate_per_hour' => '2']);
$r1 = $post($u($rateId), 'one', $cfgRate); $r2 = $post($u($rateId), 'two', $cfgRate); $r3 = $post($u($rateId), 'three', $cfgRate);
check('the hourly limit, per account: the third of two is 429 rate_limit', $r1['status'] === 200 && $r2['status'] === 200 && $r3['status'] === 429
      && ($r3['body']['error'] ?? '') === 'rate_limit');
check('… and the flood check is ONE function every write calls — the site\'s anti-spam layer\'s since part F',
      substr_count($src('includes/comments.php'), 'commentFloodCheck($db, $cfg, $me, $ip, $input, ') === 2
      && !str_contains(preg_replace('/function commentFloodCheck.*?\n}\n/s', '', $src('includes/comments.php')), "rateLimitAllow('comment'")
      && str_contains($src('includes/comments.php'), "\$t = antispamCheck(\$db, \$cfg, 'comment', antispamSubject(\$me, \$ip),"));
// A member's CAPTCHA is the anti-spam layer's (1.71.0 part F: at the top of the comment ladder — tests/antispam_test.php).
// The smart CAPTCHA's session points no longer ask for one here; a comment still adds its point to them, for the forms.
$_SESSION['captcha_points'] = 99;
unset($_SESSION['captcha_solved_at']);
$r = $post($u($aliceId), 'points', $cfgCaptcha, $verify);
check('a member past the smart CAPTCHA\'s threshold is NOT asked here — the member\'s CAPTCHA is the anti-spam layer\'s — and the comment adds its point (captcha_pts_comment)',
      $r['status'] === 200 && (int)($_SESSION['captcha_points'] ?? 0) === 100, json_encode([$r['status'], $_SESSION['captcha_points'] ?? null]));
$_SESSION['captcha_points'] = 0;
$db->prepare("DELETE FROM hash_comments WHERE info_hash = ? AND body LIKE 'points%'")->execute([CM_H1]);
$db->prepare("DELETE FROM hash_comments WHERE user_id = ?")->execute([$rateId]);

/* ══ 7. guests ═════════════════════════════════════════════════════════════ */
$gp = json_decode($guestBefore, true) ?: [];
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([json_encode($gp + ['index.view' => true], JSON_UNESCAPED_SLASHES)]);
userPermissionsForget(0);
$r = $post(null, 'as a guest', $cfgCaptcha, $verify, CM_H1, CM_GUEST_IP, ['captcha_token' => 'good-token']);
check('a guest who may search but was granted nothing of the comments: 401 login — sign in to comment', $r['status'] === 401 && ($r['body']['error'] ?? '') === 'login');
check('… and may not read the thread either: 401 no_view', ($lv = commentListRequest($db, $cfgOn, null, ['hash' => CM_H1]))['status'] === 401
      && ($lv['body']['error'] ?? '') === 'no_view');
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")
   ->execute([json_encode($gp + ['index.view' => true, 'comment.view' => true, 'comment.post' => true], JSON_UNESCAPED_SLASHES)]);
userPermissionsForget(0);
$r = $post(null, 'as a guest');
check('guests granted, but no CAPTCHA provider set up: no guest comment (403 guest_captcha)', $r['status'] === 403 && ($r['body']['error'] ?? '') === 'guest_captcha');
$r = $post(null, 'as a guest', $cfgCaptcha, $verify, CM_H1, CM_GUEST_IP);
check('with a provider: the first ask is 428 captcha_required, before anything is written', $r['status'] === 428 && ($r['body']['error'] ?? '') === 'captcha_required'
      && !empty($r['body']['captcha_required']));
$r = $post(null, 'as a guest', $cfgCaptcha, $verify, CM_H1, CM_GUEST_IP, ['captcha_token' => 'bad-token']);
check('a CAPTCHA that fails: 428 captcha_failed', $r['status'] === 428 && ($r['body']['error'] ?? '') === 'captcha_failed');
$floorG = $noteFloor();
$r = $post(null, 'A guest says hello, and @cmtest_erin', $cfgCaptcha, $verify, CM_H1, CM_GUEST_IP, ['captcha_token' => 'good-token']);
$guestComment = (int)($r['body']['comment']['id'] ?? 0);
$grow = commentRow($db, $guestComment);
check('solved: written, and HELD for a moderator — the answer says so', $r['status'] === 200 && !empty($r['body']['pending']) && $grow && $grow['status'] === 'pending'
      && ($r['body']['message'] ?? '') === __('api.comment.posted_pending'));
check('signed "Guest #tag": four hex characters from the day and the address group, never the address; the address group kept for the operator only',
      preg_match('/^[0-9a-f]{4}$/', (string)$grow['guest_tag']) === 1 && $grow['guest_tag'] === commentGuestTag(CM_GUEST_IP, $cfgCaptcha)
      && $grow['user_id'] === null && $grow['ip_bucket'] === ipBucket(CM_GUEST_IP) && !str_contains(json_encode($r['body']), CM_GUEST_IP),
      json_encode(['row' => array_intersect_key((array)$grow, array_flip(['guest_tag', 'user_id', 'ip_bucket', 'status'])), 'body' => $r['body']]));
check('the tag tells two addresses apart and changes with the day; one IPv6 /64 is one guest',
      commentGuestTag('198.51.100.23', []) !== commentGuestTag('198.51.100.24', [])
      && commentGuestTag('198.51.100.23', [], 1790000000) !== commentGuestTag('198.51.100.23', [], 1790000000 + 86400)
      && commentGuestTag('2001:db8::1', []) === commentGuestTag('2001:db8::ffff', []));
check('… nobody is told of a held comment', $notes($bobId, $floorG) === [] && $notes($erinId, $floorG) === []);
$r = $post(null, 'again, without a CAPTCHA', $cfgCaptcha, $verify, CM_H1, CM_GUEST_IP);
check('a guest solves one EVERY time, a moment after the last one too', $r['status'] === 428);
$r = $post(null, '[url]https://example.org[/url]', $cfgCaptcha, $verify, CM_H1, CM_GUEST_IP, ['captcha_token' => 'good-token']);
check('a guest can never post a link, whatever the setting says', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'no_links');
check('a guest owns nothing: no correcting, no taking back', commentEditRight($db, $cfgOn, null, $grow) === 'no_permission'
      && commentDeleteRight($db, $cfgOn, null, $grow)['right'] === '');
$l = commentListRequest($db, $cfgOn, $u($aliceId), ['hash' => CM_H1]);
check('a held comment is not in a member\'s thread', !in_array($guestComment, array_column($l['body']['rows'] ?? [], 'id'), true));
$l = commentListRequest($db, $cfgOn, $u($modId), ['hash' => CM_H1]);
$held = array_values(array_filter($l['body']['rows'] ?? [], fn($x) => $x['id'] === $guestComment))[0] ?? null;
check('… a moderator\'s thread carries it in its place, marked, with "Let it through", and the count of held ones',
      $held && $held['status'] === 'pending' && $held['can_approve'] === true && $held['guest'] === true && $held['guest_tag'] === $grow['guest_tag']
      && $held['user'] === '' && $held['avatar'] === '' && $held['profile'] === false && ($l['body']['pending'] ?? 0) === 1, json_encode($held));
check('… and only a moderator may let it through', commentApprove($db, $cfgOn, $u($aliceId), $grow)['error'] === 'no_permission');
// (bob, carol and dave have an unread notification about this thread already, from dave's comment above — and an
// unread thread is not announced twice, see 10; read, so the release is news again)
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id IN ($bobId, $carolId, $daveId, $erinId) AND read_at IS NULL");
$floorA = $noteFloor();
$ap = commentApproveRequest($db, $cfgOn, $u($modId), ['csrf_token' => $T, 'id' => $guestComment]);
check('let through: visible, stamped, one audit line', $ap['status'] === 200 && commentRow($db, $guestComment)['status'] === 'visible'
      && (int)commentRow($db, $guestComment)['approved_by'] === $modId
      && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'comment.approve' AND target_id = '" . $guestComment . "' AND id > " . $auditFloor)->fetchColumn() === 1,
      json_encode(['ap' => $ap, 'row' => commentRow($db, $guestComment)]));
check('… and only NOW the people it is news to are told — the registrant, not the name a guest wrote (a guest reaches nobody)',
      count(array_filter($notes($bobId, $floorA), fn($x) => $x['type'] === 'comment')) === 1 && $notes($erinId, $floorA) === []);
check('… told as "A guest"', str_starts_with((string)($notes($bobId, $floorA)[0]['title'] ?? ''), 'A guest commented on your torrent'));
$r = $post(null, 'shown at once', array_merge($cfgCaptcha, ['comments_guest_review' => '0']), $verify, CM_H1, CM_GUEST_IP, ['captcha_token' => 'good-token']);
check('with "Guests\' comments: shown at once", a guest\'s comment is visible straight away', $r['status'] === 200 && empty($r['body']['pending'])
      && commentRow($db, (int)$r['body']['comment']['id'])['status'] === 'visible');
$db->prepare("DELETE FROM hash_comments WHERE info_hash = ? AND user_id IS NULL")->execute([CM_H1]);
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([$guestBefore]);
userPermissionsForget(0);
check('a guest cannot use the @ list: the endpoint asks for an account first in the comment branch',
      (bool)preg_match("/if \(\(string\)\(\\\$_GET\['for'\] \?\? ''\) === 'comment'\) \{\s*if \(!function_exists\('commentsEnabled'\) \|\| !commentsEnabled\(\\\$cfg\)\) jsonResponse\(\['error' => 'disabled'\], 403\);\s*if \(!\\\$me\) jsonResponse\(\['error' => 'login_required'\], 401\);/",
                   $src('api/shout_mentions.php')));

/* ══ 8. the thread ════════════════════════════════════════════════════════ */
$ids = [];
for ($i = 1; $i <= 7; $i++) $ids[] = (int)($post($u($aliceId), "Comment number $i")['body']['comment']['id'] ?? 0);
$l = commentListRequest($db, $cfgOn, $u($erinId), ['hash' => CM_H1]);
$page = array_column($l['body']['rows'] ?? [], 'id');
check('the thread opens on its NEWEST page, read oldest first: five to a page (comments_per_page), "earlier" there is',
      $l['status'] === 200 && $page === array_slice($ids, 2) && $l['body']['earlier'] === true && $l['body']['count'] === 8 && $l['body']['per_page'] === 5, json_encode($page));
$l2 = commentListRequest($db, $cfgOn, $u($erinId), ['hash' => CM_H1, 'before' => $page[0]]);
check('… and the page before it is the three earlier ones, nothing earlier than that',
      array_column($l2['body']['rows'] ?? [], 'id') === [$daveComment, $ids[0], $ids[1]] && $l2['body']['earlier'] === false);
check('a reader sees the time in THEIR zone, with the offset in the title', ($l['body']['rows'][0]['at'] ?? '') !== ''
      && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d [+-]\d\d:\d\d$/', (string)$l['body']['rows'][0]['at']) === 1
      && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', (string)$l['body']['rows'][0]['time']) === 1);
$db->prepare("UPDATE users SET timezone = 'Asia/Tokyo' WHERE id = ?")->execute([$erinId]);
$lt = commentListRequest($db, $cfgOn, $u($erinId), ['hash' => CM_H1]);
check('… Tokyo reads it at +09:00', str_ends_with((string)($lt['body']['rows'][0]['at'] ?? ''), '+09:00'));
check('another member\'s comment: not own, no pencil, no bin; the author\'s name, picture and profile link',
      ($l['body']['rows'][0]['own'] ?? true) === false && $l['body']['rows'][0]['can_edit'] === false && $l['body']['rows'][0]['can_delete'] === null
      && $l['body']['rows'][0]['user'] === 'cmtest_alice' && $l['body']['rows'][0]['profile'] === true && $l['body']['rows'][0]['blocked'] === false);
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)")->execute([$erinId, $aliceId]);
$lb = commentListRequest($db, $cfgOn, $u($erinId), ['hash' => CM_H1]);
check('a member the reader BLOCKED: folded away (blocked), the words still there to open', ($lb['body']['rows'][0]['blocked'] ?? false) === true
      && ($lb['body']['rows'][0]['html'] ?? '') !== '');
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)")->execute([$aliceId, $daveId]);
$ld = commentListRequest($db, $cfgOn, $u($daveId), ['hash' => CM_H1]);
check('an author who hid their profile from this reader: named, not linked', ($ld['body']['rows'][0]['user'] ?? '') === 'cmtest_alice'
      && ($ld['body']['rows'][0]['profile'] ?? true) === false);
$db->prepare("DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?")->execute([$aliceId, $daveId]);
check('a reader who may not read comments is told only that there are some (the Info panel\'s answer), a reader who may, the count',
      commentPanelInfo($db, $cfgOn, null, CM_H1) === ['view' => false, 'count' => 8, 'post' => false, 'signed_in' => false, 'position' => 'after_files', 'expanded' => false]
      && commentPanelInfo($db, $cfgOn, $u($erinId), CM_H1)['view'] === true);
$pOther = commentPanelInfo($db, array_merge($cfgOn, ['comments_position' => 'after_rating', 'comments_expanded' => '1']), $u($erinId), CM_H1);
check('… and (1.72.0) where Settings puts the section and whether it opens unfolded — the panel places it (app.js), the section opens so (comments.js)',
      ($pOther['position'] ?? '') === 'after_rating' && ($pOther['expanded'] ?? null) === true
      && str_contains($src('assets/js/app.js'), "if (['after_rating', 'before_files', 'after_files'].includes(json.comments.position)) csAt = json.comments.position;")
      && str_contains($src('assets/js/comments.js'), 'sec.open = !!info.expanded || target > 0 || !!(opts && opts.open);'));
check('the Info panel carries the count only (api/index_info.php), the thread is its own request',
      str_contains($src('api/index_info.php'), "'comments'    => function_exists('commentPanelInfo') ? commentPanelInfo(\$db, \$cfg, \$me, \$hash) : null,"));
$me = commentListRequest($db, $cfgOn, $u($aliceId), ['hash' => CM_H1])['body']['me'] ?? [];
check('the composer is told who it writes as and what it may hold: links, the limits, the @ list, the picker for "comment" (no stickers)',
      ($me['can_post'] ?? false) === true && $me['name'] === 'cmtest_alice' && $me['links'] === true && $me['max'] === 500 && $me['source_max'] === 2000
      && $me['mentions'] === true && ($me['picker']['for'] ?? '') === 'comment' && ($me['picker']['stickers'] ?? true) === false);
$mm = commentListRequest($db, $cfgOn, $u($muteId), ['hash' => CM_H1])['body']['me'] ?? [];
check('… and a silenced one why it is closed, with the date', ($mm['can_post'] ?? true) === false && $mm['why'] === 'muted' && !empty($mm['until']));

/* ══ 9. correcting and taking back ════════════════════════════════════════ */
$last = end($ids);
$e = commentEditRequest($db, $cfgOn, $u($aliceId), ['csrf_token' => $T, 'id' => $last, 'body' => 'Corrected [i]words[/i]'], '127.0.0.44');
check('the author corrects their own inside the window: saved, marked "edited", no audit line',
      $e['status'] === 200 && ($e['body']['comment']['html'] ?? '') === 'Corrected <em>words</em>' && $e['body']['comment']['edited'] === true
      && $e['body']['comment']['edited_mod'] === false
      && !(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'comment.edit' AND id > $auditFloor")->fetchColumn());
check('words that did not change change nothing', ($r = commentEditRequest($db, $cfgOn, $u($aliceId), ['csrf_token' => $T, 'id' => $last, 'body' => 'Corrected [i]words[/i]'], '127.0.0.44'))['status'] === 200
      && $r['body']['changed'] === false);
check('somebody else\'s: 403', commentEditRequest($db, $cfgOn, $u($erinId), ['csrf_token' => $T, 'id' => $last, 'body' => 'x'], '127.0.0.44')['status'] === 403);
check('the stored words for the editor, with the seconds left', ($s = commentEditSourceRequest($db, $cfgOn, $u($aliceId), $last))['status'] === 200
      && $s['body']['body'] === 'Corrected [i]words[/i]' && $s['body']['left'] > 800);
$db->prepare("UPDATE hash_comments SET created_at = NOW() - INTERVAL 20 MINUTE WHERE id = ?")->execute([$last]);
check('after comment_edit_minutes: too_late — measured by the database\'s clock',
      (commentEditRequest($db, $cfgOn, $u($aliceId), ['csrf_token' => $T, 'id' => $last, 'body' => 'late'], '127.0.0.44')['body']['error'] ?? '') === 'too_late'
      && (commentEditSourceRequest($db, $cfgOn, $u($aliceId), $last)['body']['error'] ?? '') === 'too_late');
check('comment_edit_minutes 0: nobody corrects their own at all',
      commentEditRight($db, array_merge($cfgOn, ['comment_edit_minutes' => '0']), $u($aliceId), commentRow($db, $ids[5])) === 'no_permission');
check('a mute stops a correction too (an edit is writing)', ($mc = $post($u($daveId), 'dave again')) && commentEditRight($db, $cfgOn,
      array_merge($u($daveId), ['pm_muted_until' => date('Y-m-d H:i:s', time() + 86400)]), commentRow($db, (int)$mc['body']['comment']['id'])) === 'muted');
check('taking your own back: inside comment_delete_own_minutes yes — a mute does not stop it',
      commentDeleteRight($db, $cfgOn, array_merge($u($aliceId), ['pm_muted_until' => date('Y-m-d H:i:s', time() + 86400)]), commentRow($db, $last))['right'] === 'own');
$db->prepare("UPDATE hash_comments SET created_at = NOW() - INTERVAL 2 HOUR WHERE id = ?")->execute([$ids[5]]);
check('… after it: too_late; with 0 (no limit) still yes', commentDeleteRight($db, $cfgOn, $u($aliceId), commentRow($db, $ids[5]))['error'] === 'too_late'
      && commentDeleteRight($db, array_merge($cfgOn, ['comment_delete_own_minutes' => '0']), $u($aliceId), commentRow($db, $ids[5]))['right'] === 'own');
$floorD = $noteFloor();
$d = commentDeleteRequest($db, $cfgOn, $u($aliceId), ['csrf_token' => $T, 'id' => $last]);
$drow = commentRow($db, $last);
check('your own taken back: soft (the row keeps who and when), no reason asked, no audit line, nobody told',
      $d['status'] === 200 && $d['body']['right'] === 'own' && $drow['status'] === 'deleted' && (int)$drow['deleted_by'] === $aliceId && $drow['delete_reason'] === null
      && !(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'comment.delete' AND id > $auditFloor")->fetchColumn() && $notes($aliceId, $floorD) === []);
check('… gone from the thread and its count', !in_array($last, array_column(commentListRequest($db, $cfgOn, $u($erinId), ['hash' => CM_H1])['body']['rows'] ?? [], 'id'), true)
      && commentCount($db, CM_H1) === 8);
check('a deleted comment is nobody\'s to edit or delete again', commentEditRight($db, $cfgOn, $u($modId), $drow) === 'no_permission'
      && commentDeleteRequest($db, $cfgOn, $u($modId), ['csrf_token' => $T, 'id' => $last, 'reason' => 'x'])['status'] === 404);
// the moderator
$victim = $ids[4];
check('a moderator may edit and take down anybody\'s, at any time', commentEditRight($db, $cfgOn, $u($modId), commentRow($db, $victim)) === ''
      && commentDeleteRight($db, $cfgOn, $u($modId), commentRow($db, $victim))['right'] === 'any');
$floorM = $noteFloor();
$e = commentEditRequest($db, $cfgOn, $u($modId), ['csrf_token' => $T, 'id' => $victim, 'body' => 'Words a moderator changed', 'reason' => 'no swearing'], '127.0.0.44');
check('a moderator\'s edit: marked "edited by a moderator", one audit line with the reason, the author told with it',
      $e['status'] === 200 && $e['body']['comment']['edited_mod'] === true
      && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'comment.edit' AND target_id = '$victim' AND id > $auditFloor AND detail LIKE '%no swearing%'")->fetchColumn() === 1
      && count($an = $notes($aliceId, $floorM)) === 1 && $an[0]['type'] === 'comment_mod' && str_contains((string)$an[0]['body'], 'no swearing'));
$floorM = $noteFloor();
$d = commentDeleteRequest($db, $cfgOn, $u($modId), ['csrf_token' => $T, 'id' => $victim]);
check('a moderator taking somebody else\'s down must say why: 400 reason_required', $d['status'] === 400 && ($d['body']['error'] ?? '') === 'reason_required');
$d = commentDeleteRequest($db, $cfgOn, $u($modId), ['csrf_token' => $T, 'id' => $victim, 'reason' => 'Off topic']);
$vrow = commentRow($db, $victim);
$al = $db->query("SELECT action, target_type, target_id, detail FROM audit_log WHERE action = 'comment.delete' AND id > $auditFloor")->fetchAll(PDO::FETCH_ASSOC);
check('… with it: soft-deleted with who and why, one audit line (group content), the author told in their language with the reason',
      $d['status'] === 200 && $d['body']['right'] === 'any' && $vrow['status'] === 'deleted' && (int)$vrow['deleted_by'] === $modId && $vrow['delete_reason'] === 'Off topic'
      && count($al) === 1 && $al[0]['target_type'] === 'comment' && $al[0]['target_id'] === (string)$victim && auditGroupOf('comment.delete') === 'content'
      && count($an = $notes($aliceId, $floorM)) === 1 && $an[0]['type'] === 'comment_mod' && $an[0]['body'] === 'The reason given: Off topic'
      && str_starts_with((string)$an[0]['title'], 'Your comment on "Comments test torrent" was removed by a moderator'), json_encode([$al, $notes($aliceId, $floorM)]));
$floorM = $noteFloor();
$quiet = commentDelete($db, $cfgOn, $u($modId), commentRow($db, $ids[3]), 'Spam', ['notify' => false]);
check('part E\'s SILENT removal: the same act, audited as silent, and the author is not told',
      $quiet['ok'] === true && $notes($aliceId, $floorM) === []
      && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'comment.delete' AND target_id = '{$ids[3]}' AND detail LIKE '%\"silent\":true%'")->fetchColumn() === 1);

/* ══ 10. who is told ═════════════════════════════════════════════════════ */
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id IN ($aliceId, $bobId, $carolId, $daveId, $erinId) AND read_at IS NULL");
$db->prepare("DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?")->execute([$erinId, $aliceId]);
$floorN = $noteFloor();
$r = $post($u($aliceId), 'Fan-out: hello @cmtest_erin and @cmtest_bob and @cmtest_alice');
$cid = (int)($r['body']['comment']['id'] ?? 0);
// By REFERENCE: the floor moves before each step below, and an arrow function would keep the first one.
$byUser = function (int $uid) use (&$floorN, $notes): array { return $notes($uid, $floorN); };
check('the registrant mentioned as well is told ONCE, by the stronger reason (the mention)', count($byUser($bobId)) === 1 && $byUser($bobId)[0]['type'] === 'comment_mention');
check('the description\'s author of record is told ("whose description you wrote")', count($byUser($carolId)) === 1 && $byUser($carolId)[0]['type'] === 'comment'
      && str_contains((string)$byUser($carolId)[0]['title'], 'whose description you wrote'));
check('a member who commented before is told ("also commented")', count($byUser($daveId)) === 1 && str_contains((string)$byUser($daveId)[0]['title'], 'also commented'));
check('the mentioned are told as mentioned, with the words and the link to the comment',
      count($byUser($erinId)) === 1 && $byUser($erinId)[0]['type'] === 'comment_mention'
      && $byUser($erinId)[0]['link'] === '?action=search&hash=' . CM_H1 . '#comment-' . $cid && str_contains((string)$byUser($erinId)[0]['body'], 'Fan-out: hello'));
check('never the author, whatever they wrote', $byUser($aliceId) === []);
$floorN = $noteFloor();
$post($u($aliceId), 'A second one, no names.');
check('a thread already unread in somebody\'s notifications is not announced to them again', $byUser($daveId) === [] && $byUser($carolId) === []);
$post($u($aliceId), 'Again @cmtest_dave');
check('… a mention always is', count($byUser($daveId)) === 1 && $byUser($daveId)[0]['type'] === 'comment_mention');
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id IN ($bobId, $carolId, $daveId, $erinId) AND read_at IS NULL");
// preferences
$pr = commentPrefsRequest($db, $cfgOn, $u($erinId), ['csrf_token' => $T, 'mention' => 0], true);
check('the account\'s switches: saved one at a time, the rest left as they were (1.72.0: five — the reply\'s the fifth)',
      $pr['status'] === 200 && $pr['body']['prefs'] === ['mine' => true, 'desc' => true, 'thread' => true, 'mention' => false, 'reply' => true]
      && (int)$u($erinId)['comment_notify'] === (COMMENT_NOTIFY_ALL & ~COMMENT_NOTIFY_MENTION));
check('… need the token and an account', commentPrefsRequest($db, $cfgOn, $u($erinId), ['csrf_token' => 'x', 'mention' => 1], true)['status'] === 403
      && commentPrefsRequest($db, $cfgOn, null, [], false)['status'] === 401
      && commentPrefsRequest($db, $cfgOn, $u($erinId), ['csrf_token' => $T], true)['status'] === 400);
commentPrefsRequest($db, $cfgOn, $u($bobId), ['csrf_token' => $T, 'mine' => 0], true);
$floorN = $noteFloor();
$post($u($aliceId), 'Prefs: @cmtest_erin');
check('a member who switched a kind off is not told of it — neither the mention (erin) nor "my torrent" (bob)', $byUser($erinId) === [] && $byUser($bobId) === []);
commentPrefsRequest($db, $cfgOn, $u($erinId), ['csrf_token' => $T, 'mention' => 1], true);
commentPrefsRequest($db, $cfgOn, $u($bobId), ['csrf_token' => $T, 'mine' => 1], true);
// blocks
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id IN ($bobId, $carolId, $daveId, $erinId) AND read_at IS NULL");
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$erinId, $aliceId]);
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$aliceId, $daveId]);
$floorN = $noteFloor();
$post($u($aliceId), 'Across blocks: @cmtest_erin @cmtest_dave');
check('never across a block, either way: erin blocked the author, the author blocked dave — neither is told', $byUser($erinId) === [] && $byUser($daveId) === []);
$db->prepare("DELETE FROM user_blocks WHERE user_id IN (?, ?)")->execute([$erinId, $aliceId]);
// language
$db->prepare("UPDATE users SET language = 'pl' WHERE id = ?")->execute([$erinId]);
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id = $erinId AND read_at IS NULL");
$floorN = $noteFloor();
$post($u($aliceId), 'Po polsku, @cmtest_erin');
check('in the RECIPIENT\'s language: erin reads Polish, whatever the writer\'s request spoke',
      ($byUser($erinId)[0]['title'] ?? '') === 'cmtest_alice wspomina o Tobie w komentarzu pod „Comments test torrent”', json_encode($byUser($erinId)));
// somebody who may not read comments is not told
userRevokeGroup($db, $erinId, $memberGid, false);
userPermissionsForget($erinId);
$floorN = $noteFloor();
$post($u($aliceId), 'Not for erin: @cmtest_erin');
check('somebody who may not read comments is not told of one', $byUser($erinId) === []);
userGrantGroup($db, $erinId, $memberGid, null, 'comments_test', '', false);
userPermissionsForget($erinId);
// the pulse and the sounds
$post($u($erinId), 'Erin joins the thread.');
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id = $erinId AND read_at IS NULL");
$post($u($aliceId), 'Pulse: @cmtest_erin');
$db->exec("UPDATE users SET language = NULL WHERE id = $erinId");
$post($u($daveId), 'Pulse, a thread comment.');
$c = commentUnreadCounts($db, $erinId);
check('the pulse\'s counts: the comment notifications waiting, and of them the mentions (and, 1.72.0, the replies: none here)',
      $c === ['comment' => 2, 'comment_mention' => 1, 'comment_reply' => 0], json_encode($c));
// A kind switched off silences THAT kind only: erin, in the thread now, switches mentions off and is named —
// told as somebody in the thread, not as mentioned, and not left out.
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id = $erinId AND read_at IS NULL");
commentPrefsRequest($db, $cfgOn, $u($erinId), ['csrf_token' => $T, 'mention' => 0], true);
$floorN = $noteFloor();
$post($u($aliceId), 'Mentions off, still in the thread: @cmtest_erin');
check('mentions switched off, yet in the thread: told as a participant ("also commented"), not as mentioned',
      count($byUser($erinId)) === 1 && $byUser($erinId)[0]['type'] === 'comment' && str_contains((string)$byUser($erinId)[0]['title'], 'also commented'),
      json_encode($byUser($erinId)));
commentPrefsRequest($db, $cfgOn, $u($erinId), ['csrf_token' => $T, 'mention' => 1], true);
check('api/user_pulse.php and api/user_me.php carry them while comments are on',
      str_contains($src('api/user_pulse.php'), "\$out['unread_comment'] = \$cc['comment'];") && str_contains($src('api/user_pulse.php'), "\$out['unread_comment_mention'] = \$cc['comment_mention'];")
      && str_contains($src('api/user_me.php'), "\$out['unread_comment'] = \$cc['comment'];"));
$kinds = soundEventKinds($cfgOn);
check('the comments\' sound kinds — comment and comment_mention, and (1.72.0) comment_reply — only while comments are on',
      array_slice($kinds, -3) === ['comment', 'comment_mention', 'comment_reply']
      && !array_intersect(['comment', 'comment_mention', 'comment_reply'], soundEventKinds(array_merge($cfgOn, ['comments_enabled' => '0']))));
$sjs = $src('assets/js/sounds.js');
check('sounds.js maps them, and takes the comment notifications out of the plain notification\'s count (one comment, one sound) — the replies out of the comment\'s',
      str_contains($sjs, "unread_comment_other: 'comment', unread_comment_mention: 'comment_mention', unread_comment_reply: 'comment_reply' };")
      && str_contains($sjs, 'c.unread = Math.max(0, (Number(c.unread) || 0) - total);')
      && str_contains($sjs, 'c.unread_comment_other = Math.max(0, total - named - answered);'));
check('the account\'s Sounds tab and Settings → Sounds have words for all three', langHas('account.snd_ev_comment') && langHas('account.snd_ev_comment_mention')
      && langHas('settings.sounds_default_comment') && langHas('settings.sounds_ev_comment_mention')
      && langHas('account.snd_ev_comment_reply') && langHas('settings.sounds_default_comment_reply') && langHas('settings.sounds_ev_comment_reply')
      && str_contains($tpl, 'name="sound_default_comment_reply"')
      && str_contains($src('api/user_pulse.php'), "\$out['unread_comment_reply'] = \$cc['comment_reply'];")
      && str_contains($src('api/user_me.php'), "\$out['unread_comment_reply'] = \$cc['comment_reply'];"));
check('a notification\'s link is only ever a site-relative address — a comment\'s is one', (bool)preg_match('/^\?action=/', commentLink(CM_H1, 5)));
$lf = $noteFloor();
userNotify($db, $erinId, 'comment', 't', 'b', 'https://evil.example/');
userNotify($db, $erinId, 'comment', 't', 'b', '?action=search&hash=' . CM_H1 . '#comment-1');
$ln = $db->query("SELECT link FROM user_notifications WHERE id > $lf ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('… stored: an off-site address never, the site\'s own yes', $ln === [null, '?action=search&hash=' . CM_H1 . '#comment-1'], json_encode($ln));
// 1.74.0 (v93): a notification may say whose it is — a friend request does, so it goes with that account.
$lf = $noteFloor();
userNotify($db, $erinId, 'friend_request', 'from dave', '', null, $daveId);
userNotify($db, $erinId, 'comment', 'from nobody in particular', 'b');
$sn = $db->query("SELECT sender_id FROM user_notifications WHERE id > $lf ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('… and the account it is from, when there is one (sender_id, v93); none otherwise', array_map(fn($v) => $v === null ? null : (int)$v, $sn) === [$daveId, null], json_encode($sn));
$db->exec("DELETE FROM user_notifications WHERE id > $lf");

/* ══ 10b. the copies of the words follow the comment (1.74.0, PRIV-1) ═════ */
// A notification about a comment quotes its first words. Until 1.74.0 the quote outlived everything that happened
// to the comment: its author deleting it, a moderator taking it down for the personal data in it, a correction, the
// author's account being deleted — the words stayed in up to fifty-odd other people's notifications for a year.
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id IN ($aliceId, $bobId, $carolId, $daveId, $erinId) AND read_at IS NULL");
$db->prepare("UPDATE users SET language = 'pl' WHERE id = ?")->execute([$erinId]);
$floorP = $noteFloor();
$quoted = fn(string $needle): int => (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE id > $floorP AND body LIKE "
                                                     . $db->quote('%' . $needle . '%'))->fetchColumn();
$cOf = fn(array $r): int => (int)($r['body']['comment']['id'] ?? 0);
$cSelf = $cOf($post($u($daveId), 'PRIVONE self 555-0142 @cmtest_erin'));
$cMod  = $cOf($post($u($daveId), 'PRIVONE mod 12 Example Street @cmtest_erin'));
$cEdit = $cOf($post($u($daveId), 'PRIVONE edit d.private@example.net @cmtest_erin'));
check('before: each comment\'s words are quoted in other people\'s notifications — erin, named, has all three',
      $cSelf > 0 && $cMod > 0 && $cEdit > 0 && $quoted('555-0142') >= 2 && $quoted('Example Street') >= 1 && $quoted('d.private') >= 1,
      json_encode([$quoted('555-0142'), $quoted('Example Street'), $quoted('d.private')]));
$erinNote = function (int $cid) use ($db, $erinId, $floorP): ?array {
    $st = $db->prepare("SELECT type, title, body FROM user_notifications WHERE user_id = ? AND id > ? AND link = ?");
    $st->execute([$erinId, $floorP, commentLink(CM_H1, $cid)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$d = commentDeleteRequest($db, $cfgOn, $u($daveId), ['csrf_token' => $T, 'id' => $cSelf]);
check('its author deletes it: no notification quotes it any more — erin\'s copy says "[deleted]" in HER language, the notification itself stays',
      $d['status'] === 200 && $quoted('555-0142') === 0 && ($erinNote($cSelf)['body'] ?? '') === langFor('pl', 'notify.comment_body_gone')
      && ($erinNote($cSelf)['type'] ?? '') === 'comment_mention', json_encode($erinNote($cSelf)));
$floorMod = $noteFloor();
$d = commentDeleteRequest($db, $cfgOn, $u($modId), ['csrf_token' => $T, 'id' => $cMod, 'reason' => 'personal data (address)']);
$daveMod = $notes($daveId, $floorMod);
check('a moderator takes one down: its words go from every copy — and the author\'s own notice of the decision (comment_mod) is untouched',
      $d['status'] === 200 && $quoted('Example Street') === 0 && count($daveMod) === 1 && $daveMod[0]['type'] === 'comment_mod'
      && str_contains((string)$daveMod[0]['body'], 'personal data (address)'), json_encode($daveMod));
$e = commentEditRequest($db, $cfgOn, $u($daveId), ['csrf_token' => $T, 'id' => $cEdit, 'body' => 'PRIVONE corrected, nothing private'], '127.0.0.44');
check('its author corrects it: the copies quote the NEW words — none the old ones',
      $e['status'] === 200 && $quoted('d.private') === 0 && $quoted('nothing private') >= 1
      && ($erinNote($cEdit)['body'] ?? '') === langFor('pl', 'notify.comment_body', ['text' => 'PRIVONE corrected, nothing private']), json_encode($erinNote($cEdit)));
$cQuiet = $cOf($post($u($daveId), 'PRIVONE quiet 0048-111 @cmtest_erin'));
commentDelete($db, $cfgOn, $u($modId), commentRow($db, $cQuiet), 'Spam', ['notify' => false]);
check('… and a SILENT removal takes the words out of the copies all the same', $cQuiet > 0 && $quoted('0048-111') === 0);
// An account deleted: its comments' words AND its name leave other people's notifications, and so does its friend request.
$goneId = cmUser($db, $cfgOn, 'cmtest_gone');
userPermissionsForget($goneId);
$cGone = $cOf($post($u($goneId), 'PRIVONE my real name is Jan Testowy @cmtest_erin'));
userNotify($db, $erinId, 'friend_request', langFor('pl', 'notify.friend_request', ['user' => 'cmtest_gone']), '', null, $goneId);
check('before the account goes: erin has its words, its name and its friend request',
      $cGone > 0 && $quoted('Jan Testowy') >= 1 && str_starts_with((string)($erinNote($cGone)['title'] ?? ''), 'cmtest_gone')
      && (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = $erinId AND sender_id = $goneId")->fetchColumn() === 1);
$gone = userDeleteCascade($db, $goneId, $cfgOn);
check('the account deleted: no notification quotes its words or names it — the title is "a comment on …, its author\'s account was deleted"',
      $quoted('Jan Testowy') === 0 && ($gone['comment_quotes'] ?? 0) >= 1
      && ($erinNote($cGone)['title'] ?? '') === langFor('pl', 'notify.comment_gone', ['name' => 'Comments test torrent'])
      && ($erinNote($cGone)['body'] ?? '') === langFor('pl', 'notify.comment_body_gone')
      && !(int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE title LIKE 'cmtest_gone %'")->fetchColumn(), json_encode([$gone, $erinNote($cGone)]));
check('… and the friend request it sent went with it (sender_id)', !(int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE sender_id = $goneId")->fetchColumn()
      && ($gone['notifications_sent'] ?? 0) === 1, json_encode($gone));
$db->exec("UPDATE users SET language = NULL WHERE id = $erinId");

/* ══ 11. the account going ═══════════════════════════════════════════════ */
$modStamped = (int)$db->query("SELECT COUNT(*) FROM hash_comments WHERE deleted_by = $modId OR edited_by = $modId")->fetchColumn();
$gone = userDeleteCascade($db, $modId);
check('a moderator\'s account going: its moderation stamps on other people\'s comments forgotten (the audit log keeps the name)',
      $modStamped >= 2 && ($gone['comment_stamps'] ?? 0) >= 2 && !(int)$db->query("SELECT COUNT(*) FROM hash_comments WHERE deleted_by = $modId OR edited_by = $modId OR approved_by = $modId")->fetchColumn());
$aliceCount = (int)$db->query("SELECT COUNT(*) FROM hash_comments WHERE user_id = $aliceId")->fetchColumn();
$gone = userDeleteCascade($db, $aliceId);
check('an author\'s account going: its comments go with it (their own words, like shouts and messages)',
      $aliceCount > 5 && ($gone['comments'] ?? 0) === $aliceCount && !(int)$db->query("SELECT COUNT(*) FROM hash_comments WHERE user_id = $aliceId")->fetchColumn());
// 1.74.0 (PRIV-1): and what other people's notifications quoted of them — counted beside the comments.
check('… and the words of them other people\'s notifications quoted go too, counted in the cascade\'s answer',
      ($gone['comment_quotes'] ?? 0) >= 1 && !(int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE body LIKE '%Fan-out: hello%'")->fetchColumn(),
      json_encode($gone));

/* ══ 12. the endpoints, as requests ══════════════════════════════════════ */
$api = $src('api.php');
$routes = ['comment_list', 'comment_post', 'comment_edit', 'comment_delete', 'comment_approve', 'comment_prefs'];
check('six routes, each to its own file', !array_filter($routes, fn($r) => !str_contains($api, "'$r'") || !str_contains($api, "'api/$r.php'")));
check('each file hands the request to its function and sends back what it answers',
      str_contains($src('api/comment_post.php'), "requirePost();\n\$r = commentPostRequest(\$db, \$cfg, currentUser(\$db), readJsonBody(), getClientIp(\$cfg));\njsonResponse(\$r['body'], (int)\$r['status']);")
      && str_contains($src('api/comment_list.php'), "\$r = commentListRequest(\$db, \$cfg, \$me, \$_GET);")
      && str_contains($src('api/comment_delete.php'), "\$r = commentDeleteRequest(\$db, \$cfg, currentUser(\$db), readJsonBody());")
      && str_contains($src('api/comment_approve.php'), "\$r = commentApproveRequest(\$db, \$cfg, currentUser(\$db), readJsonBody());")
      && str_contains($src('api/comment_edit.php'), "\$r = commentEditRequest(\$db, \$cfg, \$me, readJsonBody(), getClientIp(\$cfg));"));
check('the preview previews a comment through the comment\'s own renderer and rules', str_contains($src('api/richtext_preview.php'), "\$for === 'comment'")
      && str_contains($src('api/richtext_preview.php'), "'html'    => commentRenderHtml(\$clean, 'bbcode', \$cfg, \$ctx),"));
// The endpoint FILES, run as requests in a child process: a started session holding the account and the token,
// the same includes api.php loads — and the answer read off what they print. readJsonBody() reads php://input,
// which a CLI child does not have, so the body goes in $_POST, which it falls back to.
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.45";
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/index.php", "includes/richtext.php", "includes/content.php", "includes/mail.php",
          "includes/users.php", "includes/favourites.php", "includes/usermedia.php", "includes/sounds.php", "includes/shout.php",
          "includes/emoji.php", "includes/people.php", "includes/audit.php", "includes/auth.php", "includes/comments.php",
          "includes/lang.php"] as $f) require_once $f;
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
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    $cfgX = array_intersect_key($cfgOn, array_flip(['users_enabled', 'profiles_enabled', 'index_enabled', 'index_search_enabled', 'comments_enabled',
        'comment_max_chars', 'comment_links', 'comments_per_page', 'comment_rate_per_hour', 'recaptcha_enabled', 'antispam_enabled']));
    file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'file' => $root . '/api/' . $endpoint . '.php', 'method' => $method,
        'post' => $post, 'get' => $get, 'session' => $session, 'cfg' => $cfgX, 'sid' => 'cmtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($GLOBALS['runner']) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 600)];
};
$GLOBALS['runner'] = $runner;
$httpId = cmUser($db, $cfgOn, 'cmtest_http');
$sess = ['user_id' => $httpId, 'user_login_time' => time(), 'csrf_token' => 'cm-child-token'];
$runPost = fn(string $endpoint, array $body, array $session): array => $run($endpoint, 'POST', $body, [], $session);
$j = $runPost('comment_post', ['csrf_token' => 'cm-child-token', 'hash' => CM_H1, 'body' => '[b]From the endpoint[/b] [img]x[/img]'], $sess);
check('the endpoint file, as a request: signed in by the session, written, and answered with the server\'s HTML',
      !empty($j['success']) && ($j['comment']['html'] ?? '') === '<strong>From the endpoint</strong> [img]x[/img]' && ($j['comment']['user'] ?? '') === 'cmtest_http', json_encode($j));
$j2 = $runPost('comment_post', ['csrf_token' => 'another', 'hash' => CM_H1, 'body' => 'x'], $sess);
check('… refused without the session\'s token', ($j2['error'] ?? '') === 'csrf' && empty($j2['success']), json_encode($j2));
$j3 = $run('comment_list', 'GET', [], ['hash' => CM_H1], $sess);
check('comment_list as a request: the newest page with that comment last, and the composer\'s answer',
      !empty($j3['success']) && (end($j3['rows'])['html'] ?? '') === '<strong>From the endpoint</strong> [img]x[/img]' && ($j3['me']['can_post'] ?? false) === true, json_encode(array_keys($j3)));
$j4 = $runPost('comment_delete', ['csrf_token' => 'cm-child-token', 'id' => (int)($j['comment']['id'] ?? 0)], $sess);
check('comment_delete as a request: your own, taken back', !empty($j4['success']) && ($j4['right'] ?? '') === 'own', json_encode($j4));

/* ══ 13. replies (1.72.0) ═════════════════════════════════════════════════ */
// A torrent and people of their own: nothing above is touched.
$rpA = cmUser($db, $cfgOn, 'cmtest_rp_a');     // writes the thread's comment
$rpB = cmUser($db, $cfgOn, 'cmtest_rp_b');     // replies
$rpC = cmUser($db, $cfgOn, 'cmtest_rp_c');     // replies to the reply
$rpX = cmUser($db, $cfgOn, 'cmtest_rp_x');     // leaves the site
$rpM = cmUser($db, $cfgOn, 'cmtest_rp_mod');   // a moderator
userGrantGroup($db, $rpM, $modGid, null, 'comments_test', '', false);
userPermissionsForget($rpM);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Replies test torrent', 'done')")->execute([CM_H3]);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Another test torrent', 'done')")->execute([CM_H4]);
$rp = function (?array $me, string $body, $parent, ?array $cfgX = null, string $hash = CM_H3, array $opts = [], string $ip = '127.0.0.46', array $extra = []) use ($post): array {
    return $post($me, $body, $cfgX, $opts, $hash, $ip, ['parent' => $parent] + $extra);
};
$hc = fn(string $h): int => (int)$db->query("SELECT COUNT(*) FROM hash_comments WHERE info_hash = '" . $h . "'")->fetchColumn();
$threadOf = function (array $l, int $top): array {
    foreach ($l['body']['rows'] ?? [] as $x) if ((int)$x['id'] === $top) return $x['thread'] ?? [];
    return [];
};
$idsOf = fn(array $th): array => array_map('intval', array_column($th['rows'] ?? [], 'id'));
$rowIn = function (array $th, int $id): ?array { foreach ($th['rows'] ?? [] as $x) if ((int)$x['id'] === $id) return $x; return null; };
$markRead = function () use ($db, $rpA, $rpB, $rpC, $rpX, $rpM): void {
    $db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id IN ($rpA, $rpB, $rpC, $rpX, $rpM) AND read_at IS NULL");
};

// ── the tree, to the limit ──
$floorR = $noteFloor();
$r = $post($u($rpA), 'The thread starts here.', null, [], CM_H3);
$top = (int)($r['body']['comment']['id'] ?? 0);
check('replies: a top-level comment has no parent, no thread, depth 0 — and may be answered',
      $r['status'] === 200 && $top > 0 && $r['body']['comment']['parent'] === null && $r['body']['comment']['root'] === null
      && $r['body']['comment']['depth'] === 0 && $r['body']['comment']['can_reply'] === true && $r['body']['comment']['tomb'] === '', json_encode($r['body']));
$r1 = $rp($u($rpB), 'A reply, one level down.', $top);
$id1 = (int)($r1['body']['comment']['id'] ?? 0);
$row1 = commentRow($db, $id1);
check('a reply: 200, stored under its parent, in its thread, one level down — and answered as the thread will draw it',
      $r1['status'] === 200 && $id1 > 0 && (int)$row1['parent_id'] === $top && (int)$row1['root_id'] === $top && (int)$row1['depth'] === 1
      && $r1['body']['comment']['parent'] === $top && $r1['body']['comment']['root'] === $top && $r1['body']['comment']['depth'] === 1
      && $r1['body']['message'] === __('api.comment.posted') && $r1['body']['count'] === 2, json_encode($r1['body']));
$r2 = $rp($u($rpC), 'A reply to the reply.', $id1);
$id2 = (int)($r2['body']['comment']['id'] ?? 0);
$r3 = $rp($u($rpA), 'And one more: level three, as deep as the shipped setting goes.', (string)$id2);
$id3 = (int)($r3['body']['comment']['id'] ?? 0);
check('… to a reply: level 2, the same thread; and level 3 (the parent given as text, as a form sends it) — the deepest the shipped setting allows',
      $r2['status'] === 200 && $r3['status'] === 200 && (int)commentRow($db, $id2)['depth'] === 2 && (int)commentRow($db, $id2)['root_id'] === $top
      && (int)commentRow($db, $id3)['depth'] === 3 && (int)commentRow($db, $id3)['parent_id'] === $id2 && (int)commentRow($db, $id3)['root_id'] === $top);
$n0 = $hc(CM_H3);
$r4 = $rp($u($rpB), 'Deeper than the setting.', $id3);
check('the SERVER refuses a reply past the setting — 409 too_deep, saying the limit; nothing written (the Reply button is not the gate)',
      $r4['status'] === 409 && ($r4['body']['error'] ?? '') === 'too_deep' && str_contains((string)$r4['body']['message'], '3') && $hc(CM_H3) === $n0, json_encode($r4['body']));
$l = commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3]);
$th = $threadOf($l, $top);
check('the page: the top-level comment, its replies WITH it — oldest first, each with its parent, thread and depth; the count is every comment, replies included',
      $l['status'] === 200 && array_map('intval', array_column($l['body']['rows'], 'id')) === [$top] && $idsOf($th) === [$id1, $id2, $id3]
      && ($th['more'] ?? -1) === 0 && ($th['last'] ?? 0) === $id3 && $l['body']['count'] === 4 && $l['body']['reply_depth'] === 3
      && ($rowIn($th, $id2)['parent'] ?? 0) === $id1 && ($rowIn($th, $id2)['depth'] ?? 0) === 2, json_encode($l['body']['rows']));
check('… no Reply on the level-3 reply (the limit), Reply on every other; the composer is told replies are on, and how deep',
      ($rowIn($th, $id3)['can_reply'] ?? true) === false && ($rowIn($th, $id2)['can_reply'] ?? false) === true && ($rowIn($th, $id1)['can_reply'] ?? false) === true
      && ($l['body']['rows'][0]['can_reply'] ?? false) === true && ($l['body']['me']['reply'] ?? null) === true && ($l['body']['me']['reply_depth'] ?? null) === 3);
// who was told
$nA = $notes($rpA, $floorR); $nB = $notes($rpB, $floorR); $nC = $notes($rpC, $floorR);
$replyNotes = fn(array $ns): array => array_values(array_filter($ns, fn($x) => $x['type'] === 'comment_reply'));
check('a reply tells the author of the comment it answers: comment_reply, who and where, the words, the link to the REPLY',
      count($replyNotes($nA)) === 1 && $replyNotes($nA)[0]['link'] === commentLink(CM_H3, $id1)
      && $replyNotes($nA)[0]['title'] === 'cmtest_rp_b replied to your comment on "Replies test torrent"' && $replyNotes($nA)[0]['body'] === '“A reply, one level down.”'
      && count($replyNotes($nB)) === 1 && $replyNotes($nB)[0]['link'] === commentLink(CM_H3, $id2)
      && count($replyNotes($nC)) === 1 && $replyNotes($nC)[0]['link'] === commentLink(CM_H3, $id3), json_encode([$nA, $nB, $nC]));
check('… and never the replier: A answered C — A is told nothing of their own reply', !array_filter($nA, fn($x) => str_ends_with((string)$x['link'], '#comment-' . $id3)));
check('the pulse counts the replies apart (of the comment notifications): a sound of their own', commentUnreadCounts($db, $rpA)['comment_reply'] === 1
      && commentUnreadCounts($db, $rpA)['comment'] === count($nA));
$markRead();
$f = $noteFloor();
$rp($u($rpA), 'Answering my own comment.', $top);
check('replying to your own comment tells you nothing', $notes($rpA, $f) === []);
$f = $noteFloor();
$rp($u($rpB), 'Hello @cmtest_rp_a, a reply that also names you.', $top);
check('a reply that also @-names its parent\'s author is ONE notification to them — the reply\'s', count($notes($rpA, $f)) === 1 && $notes($rpA, $f)[0]['type'] === 'comment_reply',
      json_encode($notes($rpA, $f)));
$markRead();
// blocks, either way
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$rpA, $rpC]);
$f = $noteFloor();
$rp($u($rpC), 'From somebody A blocked.', $top);
check('a reply from somebody the parent\'s author BLOCKED tells them nothing — not as a reply, not as their thread', $notes($rpA, $f) === []);
$db->prepare("DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?")->execute([$rpA, $rpC]);
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$rpC, $rpA]);
$markRead();
$f = $noteFloor();
$rp($u($rpC), 'From somebody who blocked A.', $top);
check('… nor one from somebody who blocked THEM', $notes($rpA, $f) === []);
$db->prepare("DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?")->execute([$rpC, $rpA]);
// the fifth switch
$markRead();
$pr = commentPrefsRequest($db, $cfgOn, $u($rpA), ['csrf_token' => $T, 'reply' => 0], true);
$f = $noteFloor();
$rp($u($rpB), 'Replies switched off by A.', $top);
check('"…that replies to one of my comments" switched off (bit 16): not told as a reply — as the thread they are in, as the other switches allow',
      $pr['status'] === 200 && ($pr['body']['prefs']['reply'] ?? true) === false && (int)$u($rpA)['comment_notify'] === (COMMENT_NOTIFY_ALL & ~COMMENT_NOTIFY_REPLY)
      && count($notes($rpA, $f)) === 1 && $notes($rpA, $f)[0]['type'] === 'comment', json_encode($notes($rpA, $f)));
commentPrefsRequest($db, $cfgOn, $u($rpA), ['csrf_token' => $T, 'reply' => 1], true);
$markRead();

// ── the refusals, in their order ──
$r = $rp($u($rpB), 'x', $top, null, CM_H4);
check('a parent from another torrent: 404 parent_gone', $r['status'] === 404 && ($r['body']['error'] ?? '') === 'parent_gone');
$r = $rp($u($rpB), 'x', 999999999);
$r2x = $rp($u($rpB), 'x', 'abc');
check('a parent that is no comment: 404 parent_gone; one that is not a number: 400', $r['status'] === 404 && ($r['body']['error'] ?? '') === 'parent_gone'
      && $r2x['status'] === 400 && ($r2x['body']['error'] ?? '') === 'parent_gone');
$r = $rp($u($rpB), 'x', $top, array_merge($cfgOn, ['comments_reply_depth' => '0']));
check('replies switched off (depth 0): 403 replies_off', $r['status'] === 403 && ($r['body']['error'] ?? '') === 'replies_off');
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('cmtest_noreply', 'cmtest noreply', 'comments_test', 2, 0, 0, ?)")
   ->execute([json_encode(['index.view' => true, 'whitelist.view' => true, 'comment.view' => true, 'comment.post' => true])]);
$noReplyGid = (int)$db->lastInsertId();
$rpN = cmUser($db, $cfgOn, 'cmtest_rp_n');
userRevokeGroup($db, $rpN, $memberGid, false);
userGrantGroup($db, $rpN, $noReplyGid, null, 'comments_test', '', false);
userPermissionsForget($rpN);
$r = $rp($u($rpN), 'x', $top);
$ln = commentListRequest($db, $cfgOn, $u($rpN), ['hash' => CM_H3]);
check('an account that may comment but not reply (no comment.reply): 403 no_reply — a comment of its own still goes; no Reply button anywhere for it',
      $r['status'] === 403 && ($r['body']['error'] ?? '') === 'no_reply' && $post($u($rpN), 'A comment is fine.', null, [], CM_H3)['status'] === 200
      && ($ln['body']['me']['reply'] ?? true) === false && !array_filter($ln['body']['rows'] ?? [], fn($x) => $x['can_reply'])
      && !array_filter($threadOf($ln, $top)['rows'] ?? [], fn($x) => $x['can_reply']), json_encode($r['body']));
$db->prepare("DELETE FROM hash_comments WHERE user_id = ?")->execute([$rpN]);
$db->prepare("UPDATE users SET pm_muted_until = NOW() + INTERVAL 1 DAY WHERE id = ?")->execute([$rpC]);
$r = $rp($u($rpC), 'x', $top);
check('silenced: 403 muted — what writing asks comes before what a reply asks', $r['status'] === 403 && ($r['body']['error'] ?? '') === 'muted');
$db->prepare("UPDATE users SET pm_muted_until = NULL WHERE id = ?")->execute([$rpC]);

// ── a guest's reply: every guest rule ──
$gp = json_decode($guestBefore, true) ?: [];
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")
   ->execute([json_encode($gp + ['index.view' => true, 'comment.view' => true, 'comment.post' => true], JSON_UNESCAPED_SLASHES)]);
userPermissionsForget(0);
$r = $rp(null, 'A guest answers.', $top, $cfgCaptcha, CM_H3, $verify, CM_GUEST_IP, ['captcha_token' => 'good-token']);
check('a guest group that may comment but was not granted comment.reply: 403 no_reply', $r['status'] === 403 && ($r['body']['error'] ?? '') === 'no_reply', json_encode($r['body']));
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")
   ->execute([json_encode($gp + ['index.view' => true, 'comment.view' => true, 'comment.post' => true, 'comment.reply' => true], JSON_UNESCAPED_SLASHES)]);
userPermissionsForget(0);
$r = $rp(null, 'A guest answers.', $top, $cfgCaptcha, CM_H3, $verify, CM_GUEST_IP);
check('granted: a guest\'s reply asks for a CAPTCHA first (428), every time', $r['status'] === 428 && ($r['body']['error'] ?? '') === 'captcha_required');
$r = $rp(null, 'See [url]https://example.org[/url]', $top, $cfgCaptcha, CM_H3, $verify, CM_GUEST_IP, ['captcha_token' => 'good-token']);
check('… no links, whatever the setting', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'no_links');
$f = $noteFloor();
$r = $rp(null, 'A guest answers.', $top, $cfgCaptcha, CM_H3, $verify, CM_GUEST_IP, ['captcha_token' => 'good-token']);
$gid = (int)($r['body']['comment']['id'] ?? 0);
$grow = commentRow($db, $gid);
check('… solved: signed "Guest #tag", no account, one level under what it answers, HELD for a moderator — and nobody told yet',
      $r['status'] === 200 && !empty($r['body']['pending']) && $grow && $grow['status'] === 'pending' && $grow['user_id'] === null
      && $grow['guest_tag'] === commentGuestTag(CM_GUEST_IP, $cfgCaptcha) && (int)$grow['parent_id'] === $top && (int)$grow['depth'] === 1 && $notes($rpA, $f) === [],
      json_encode($r['body']));
$r = $rp($u($rpB), 'Answering a held one.', $gid);
check('a held guest comment cannot be answered until it is let through: 409 parent_pending', $r['status'] === 409 && ($r['body']['error'] ?? '') === 'parent_pending');
$lm = commentListRequest($db, $cfgOn, $u($rpM), ['hash' => CM_H3]);
$lb = commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3]);
check('… a moderator\'s thread shows it in its place, with no Reply; a member\'s does not show it at all',
      ($rowIn($threadOf($lm, $top), $gid)['status'] ?? '') === 'pending' && ($rowIn($threadOf($lm, $top), $gid)['can_reply'] ?? true) === false
      && $rowIn($threadOf($lb, $top), $gid) === null);
$ap = commentApproveRequest($db, $cfgOn, $u($rpM), ['csrf_token' => $T, 'id' => $gid]);
$told = $notes($rpA, $f);
check('let through: now the parent\'s author is told, as "A guest"', $ap['status'] === 200 && count($replyNotes($told)) === 1
      && str_starts_with((string)$replyNotes($told)[0]['title'], 'A guest replied to your comment on') && $replyNotes($told)[0]['link'] === commentLink(CM_H3, $gid), json_encode($told));
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([$guestBefore]);
userPermissionsForget(0);
$markRead();

// ── the depth lowered: old replies stay, new ones are refused ──
$cfg1 = array_merge($cfgOn, ['comments_reply_depth' => '1']);
$l1 = commentListRequest($db, $cfg1, $u($rpB), ['hash' => CM_H3]);
$th1 = $threadOf($l1, $top);
check('lowered to 1: the replies written deeper STAY on the page (the page draws them at the deepest level allowed) — none can be answered now, the comment still can',
      in_array($id2, $idsOf($th1), true) && in_array($id3, $idsOf($th1), true) && $l1['body']['reply_depth'] === 1
      && ($rowIn($th1, $id1)['can_reply'] ?? true) === false && ($rowIn($th1, $id2)['can_reply'] ?? true) === false && ($l1['body']['rows'][0]['can_reply'] ?? false) === true);
$r = $rp($u($rpC), 'Under a level-1 reply, with the depth at 1.', $id1, $cfg1);
$rOk = $rp($u($rpC), 'Under the comment itself, with the depth at 1.', $top, $cfg1);
check('… only NEW ones are refused: a reply to a level-1 one is too deep now (409), a reply to the comment is not',
      $r['status'] === 409 && ($r['body']['error'] ?? '') === 'too_deep' && $rOk['status'] === 200 && (int)commentRow($db, (int)$rOk['body']['comment']['id'])['depth'] === 1);
$cfg0 = array_merge($cfgOn, ['comments_reply_depth' => '0']);
$l0 = commentListRequest($db, $cfg0, $u($rpB), ['hash' => CM_H3]);
check('… at 0 (replies off) the thread still shows every reply written (flat, the page says whom each answers), and nothing can be answered',
      in_array($id3, $idsOf($threadOf($l0, $top)), true) && $l0['body']['reply_depth'] === 0 && ($l0['body']['me']['reply'] ?? true) === false
      && !array_filter($l0['body']['rows'], fn($x) => $x['can_reply']) && !array_filter($threadOf($l0, $top)['rows'], fn($x) => $x['can_reply']));

// ── tombstones ──
$cBefore = commentCount($db, CM_H3);
$d = commentDeleteRequest($db, $cfgOn, $u($rpB), ['csrf_token' => $T, 'id' => $id1]);
$lt = commentListRequest($db, $cfgOn, $u($rpC), ['hash' => CM_H3]);
$tomb = $rowIn($threadOf($lt, $top), $id1);
check('a comment WITH replies taken back by its author keeps its place: "[deleted]" (tomb deleted) — no author, no words, no time, nothing to do; its replies under it',
      $d['status'] === 200 && ($d['body']['tomb'] ?? '') === 'deleted' && $tomb !== null && $tomb['tomb'] === 'deleted' && $tomb['user'] === '' && $tomb['html'] === ''
      && $tomb['time'] === '' && $tomb['guest'] === false && $tomb['can_reply'] === false && $tomb['can_report'] === false && $tomb['can_delete'] === null
      && in_array($id2, $idsOf($threadOf($lt, $top)), true) && in_array($id3, $idsOf($threadOf($lt, $top)), true), json_encode([$d['body'], $tomb]));
check('… it is counted no more (the count is the visible comments), and a reply to it is refused: 409 parent_gone',
      $d['body']['count'] === $cBefore - 1 && $lt['body']['count'] === $cBefore - 1
      && ($rp($u($rpC), 'To a tombstone.', $id1)['body']['error'] ?? '') === 'parent_gone', json_encode([$cBefore, $d['body']['count'], $lt['body']['count']]));
$d2 = commentDeleteRequest($db, $cfgOn, $u($rpC), ['csrf_token' => $T, 'id' => $id2]);
$d3 = commentDeleteRequest($db, $cfgOn, $u($rpA), ['csrf_token' => $T, 'id' => $id3]);
$lt = commentListRequest($db, $cfgOn, $u($rpC), ['hash' => CM_H3]);
check('the replies under it taken back too: the last one simply goes (tomb \'\'), and so do the tombstones that had nothing else under them',
      ($d2['body']['tomb'] ?? '') === 'deleted' && ($d3['body']['tomb'] ?? 'x') === '' && !array_intersect([$id1, $id2, $id3], $idsOf($threadOf($lt, $top))),
      json_encode([$d2['body'], $d3['body'], $idsOf($threadOf($lt, $top))]));
$rT = $post($u($rpC), 'A comment a moderator will remove.', null, [], CM_H3);
$t2 = (int)$rT['body']['comment']['id'];
$t2r = (int)($rp($u($rpB), 'A reply under it.', $t2)['body']['comment']['id'] ?? 0);
$dm = commentDeleteRequest($db, $cfgOn, $u($rpM), ['csrf_token' => $T, 'id' => $t2, 'reason' => 'Off topic']);
$lt = commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3]);
$tops = []; foreach ($lt['body']['rows'] as $x) $tops[(int)$x['id']] = $x;
check('a TOP-LEVEL comment removed by a moderator (with a reason) while answered: "[removed by a moderator]" (tomb removed) in its place on the page, its thread under it',
      ($dm['body']['tomb'] ?? '') === 'removed' && ($tops[$t2]['tomb'] ?? '') === 'removed' && ($tops[$t2]['user'] ?? 'x') === '' && ($tops[$t2]['html'] ?? 'x') === ''
      && $idsOf($tops[$t2]['thread'] ?? []) === [$t2r], json_encode([$dm['body'], $tops[$t2] ?? null]));
check('an author\'s own deletion keeps no reason, even one an API caller sent (what makes a tombstone say "removed by a moderator")',
      ($own = $post($u($rpB), 'Mine, to delete with a reason.', null, [], CM_H3)) && commentDeleteRequest($db, $cfgOn, $u($rpB), ['csrf_token' => $T, 'id' => (int)$own['body']['comment']['id'], 'reason' => 'mine'])['status'] === 200
      && commentRow($db, (int)$own['body']['comment']['id'])['delete_reason'] === null);

// ── a page stays bounded, however long a thread is ──
$t3 = (int)($post($u($rpA), 'A long thread.', null, [], CM_H3)['body']['comment']['id'] ?? 0);
$long = [];
for ($i = 1; $i <= 15; $i++) $long[] = (int)($rp($u($i % 2 ? $rpB : $rpC), "Long thread, reply $i", $t3)['body']['comment']['id'] ?? 0);
$lp = commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3]);
$tl = $threadOf($lp, $t3);
check('a thread of fifteen replies brings its first ten with the page (COMMENT_REPLIES_FIRST), and says how many more there are',
      COMMENT_REPLIES_FIRST === 10 && $idsOf($tl) === array_slice($long, 0, 10) && ($tl['more'] ?? 0) === 5 && ($tl['last'] ?? 0) === $long[9], json_encode([$idsOf($tl), $tl['more'] ?? null]));
$lm2 = commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3, 'thread' => $t3, 'after' => $long[9]]);
check('"Show 5 more replies": the thread\'s next ones (at most COMMENT_REPLIES_MORE), none after them',
      $lm2['status'] === 200 && ($lm2['body']['thread'] ?? 0) === $t3 && array_map('intval', array_column($lm2['body']['rows'], 'id')) === array_slice($long, 10)
      && $lm2['body']['more'] === 0 && $lm2['body']['last'] === $long[14] && COMMENT_REPLIES_MORE === 25);
$lf = commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3, 'find' => $long[13]]);
check('a notification\'s link into the thread (find): the thread brings its replies as far as that one, the rest still "more"',
      $idsOf($threadOf($lf, $t3)) === array_slice($long, 0, 14) && ($threadOf($lf, $t3)['more'] ?? 0) === 1);
check('… and a thread that is not a thread (a reply\'s id, another torrent\'s comment): 404',
      commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3, 'thread' => $long[0]])['status'] === 404
      && commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H4, 'thread' => $t3])['status'] === 404);

// ── the account going, under other people's replies ──
$xTop = (int)($post($u($rpX), 'X starts a thread B answers.', null, [], CM_H3)['body']['comment']['id'] ?? 0);
$xR1 = (int)($rp($u($rpB), 'B answers X.', $xTop)['body']['comment']['id'] ?? 0);
$xLone = (int)($post($u($rpX), 'X alone.', null, [], CM_H3)['body']['comment']['id'] ?? 0);
$bTop = (int)($post($u($rpB), 'B starts one X answers.', null, [], CM_H3)['body']['comment']['id'] ?? 0);
$xRep = (int)($rp($u($rpX), 'X answers B.', $bTop)['body']['comment']['id'] ?? 0);
$cR = (int)($rp($u($rpC), 'C answers X.', $xRep)['body']['comment']['id'] ?? 0);
$xTop2 = (int)($post($u($rpX), 'X talks to themself.', null, [], CM_H3)['body']['comment']['id'] ?? 0);
$xR2 = (int)($rp($u($rpX), 'X again.', $xTop2)['body']['comment']['id'] ?? 0);
$gone = userDeleteCascade($db, $rpX);
$xt = commentRow($db, $xTop);
$xr = commentRow($db, $xRep);
check('an account going: its comments that OTHER people answered stay as tombstones — deleted, no words, no author, no tag, no address',
      ($gone['comment_tombstones'] ?? 0) === 2 && $xt && $xt['status'] === 'deleted' && $xt['body'] === '' && $xt['user_id'] === null && $xt['guest_tag'] === null
      && $xt['ip_bucket'] === null && $xr && $xr['status'] === 'deleted' && $xr['body'] === '' && $xr['user_id'] === null && $xr['delete_reason'] === null, json_encode([$gone, $xt, $xr]));
check('… the rest go as before (the lone one, and a thread only its own replies answered)', ($gone['comments'] ?? 0) === 3
      && commentRow($db, $xLone) === null && commentRow($db, $xTop2) === null && commentRow($db, $xR2) === null);
$lx = commentListRequest($db, $cfgOn, $u($rpB), ['hash' => CM_H3]);
$txs = []; foreach ($lx['body']['rows'] as $x) $txs[(int)$x['id']] = $x;
check('… and the replies of others survive in their place: B\'s under X\'s tombstone ("[deleted]"), C\'s under X\'s tombstoned reply',
      ($txs[$xTop]['tomb'] ?? '') === 'deleted' && $idsOf($txs[$xTop]['thread'] ?? []) === [$xR1] && (int)commentRow($db, $xR1)['parent_id'] === $xTop
      && ($rowIn($txs[$bTop]['thread'] ?? [], $xRep)['tomb'] ?? '') === 'deleted' && $rowIn($txs[$bTop]['thread'] ?? [], $cR) !== null,
      json_encode([$txs[$xTop] ?? null, $txs[$bTop] ?? null]));

// ── the endpoint, as a request: a reply through the file, its parent as a form sends it ──
$jr = $runPost('comment_post', ['csrf_token' => 'cm-child-token', 'hash' => CM_H3, 'body' => 'A reply through the endpoint', 'parent' => (string)$t3], $sess);
check('comment_post as a request, with a parent: a reply, one level down', !empty($jr['success']) && ($jr['comment']['parent'] ?? 0) === $t3 && ($jr['comment']['depth'] ?? 0) === 1,
      json_encode($jr));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
