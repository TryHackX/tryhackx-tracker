<?php
/**
 * A member's likes or ratings, on the account page and the profile (1.69.0, includes/profilevotes.php):
 *   php tests/profile_votes_test.php
 *
 * What the owner asked for, pinned: whichever rating mode is on, the torrents a member voted on, in a
 * table that sorts and pages; star mode filters by a range of their own rating and of the average,
 * thumbs mode by their own vote and by the number of votes; all of it behind the site's switch, a
 * group permission, and the member's own yes.
 *
 *   1. the schema, on BOTH paths — a fresh install's CREATE and an upgrade's guarded ALTER, each on a
 *      scratch database built here and dropped again;
 *   2. the setting in its four places;
 *   3. the permission: registered, in the member preset, granted ONCE by the migration, no with
 *      accounts off;
 *   4. the gate, one flag at a time — the switch, ratings, the owner's GRANT (an administrator-only
 *      owner must NOT pass), their own flag, self against other, a block, the reader's own right to
 *      open profiles — and the account page's context the privacy switch is drawn from;
 *   5. the list: anonymous votes never, only the current mode's values, every sort both ways (a score
 *      below the minimum after every shown one, in both directions), each filter, the whitelist arm,
 *      a banned row, the hash withheld, pagination, the reader's time zone, clamping;
 *   6. EXPLAIN and a stopwatch on three thousand votes;
 *   7. the endpoint FILE and the privacy save, each run as a request in a child process (a session and
 *      its token), and the pages that carry it all.
 *
 * Every switch a check leans on is set explicitly — nothing is inherited from this database's live
 * settings. Self-cleaning: the accounts, groups, catalogue rows and votes it makes, the member and
 * admin groups' JSON and the grant marker are put back as they were.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/reputation.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/lists.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/icons.php';
require_once $root . '/includes/settings_catalog.php';
require_once $root . '/includes/profilevotes.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$GLOBALS['db'] = $db;
langInit([], 'en');
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1',
    'profile_votes_enabled' => '1', 'rep_enabled' => '1', 'rep_mode' => 'thumbs', 'rep_min_votes' => '3',
    'fav_enabled' => '1', 'fav_max_per_user' => '500', 'index_search_include_whitelist' => '1',
]);
$cfgStars = array_merge($cfgOn, ['rep_mode' => 'stars']);
$GLOBALS['cfg'] = $cfgOn;

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
const PV_HASH_PREFIXES = ['e7e7', 'e8e8'];
$perms = fn(string $slug) => (string)$db->query("SELECT permissions FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn();
$memberBefore = $perms('member');
$adminBefore = $perms('admin');
$markerBefore = $db->query("SELECT `value` FROM settings WHERE `key` = 'schema_grant_v75_rating_public'")->fetchColumn();
$tmpFiles = [];
$pvClean = function () use ($db): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'pvtest\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'pvtest\\_%'");
    foreach (PV_HASH_PREFIXES as $pfx) {
        $like = $db->quote($pfx . '%');
        $db->exec("DELETE FROM hash_votes WHERE info_hash LIKE $like");
        $db->exec("DELETE FROM index_files WHERE info_hash LIKE $like");
        $db->exec("DELETE FROM index_hashes WHERE info_hash LIKE $like");
        $db->exec("DELETE FROM whitelist WHERE info_hash LIKE $like");
    }
};
$pvClean();
register_shutdown_function(function () use ($db, $pvClean, $memberBefore, $adminBefore, $markerBefore, &$tmpFiles) {
    $pvClean();
    if ($memberBefore !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([$memberBefore]);
    if ($adminBefore !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'admin'")->execute([$adminBefore]);
    if ($markerBefore === false) $db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v75_rating_public'");
    else $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('schema_grant_v75_rating_public', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([(string)$markerBefore]);
    foreach ($tmpFiles as $f) @unlink($f);
});

/** A verified member, made fresh: userEffectivePermissions() memoizes per account for the whole process. */
function pvUser(PDO $db, array $cfg, string $name, bool $verified = true): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0 && $verified) $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    return $id;
}
/** Only this group, nothing else: the member group the registration gave is taken off first. */
function pvOnlyIn(PDO $db, int $uid, string $slug): void {
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uid]);
    $gid = (int)$db->query("SELECT id FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn();
    userGrantGroup($db, $uid, $gid, null, 'test', 'profile_votes', false);
    userPermissionsForget($uid);
}
$row = function (int $id) use ($db): array {
    $st = $db->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};

/* ══ 1. the schema, on both paths ═════════════════════════════════════════ */
check('the schema is at 75 or later', TRACKER_SCHEMA_VERSION >= 75, (string)TRACKER_SCHEMA_VERSION);
$create = '';
foreach (trackerSchemaStatements() as $sql) if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `users`')) $create = $sql;
check('a fresh install\'s users table carries votes_public TINYINT(1) NOT NULL DEFAULT 0',
      str_contains($create, '`votes_public` TINYINT(1) NOT NULL DEFAULT 0'));
$schemaSrc = $src('includes/schema.php');
check('… and an upgraded one gets it from a guarded ALTER with the same type and default',
      str_contains($schemaSrc, "if (!schemaColumnExists(\$db, 'users', 'votes_public')) \$uparts[] = \"ADD COLUMN `votes_public` TINYINT(1) NOT NULL DEFAULT 0\";"));
check('this database has the column', schemaColumnExists($db, 'users', 'votes_public'));
$scratch = 'tracker_pv_' . bin2hex(random_bytes(3));
$dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: (defined('DB_PORT') ? DB_PORT : '3306'));
$base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
try {
    $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach (trackerSchemaStatements() as $sql) $sdb->exec($sql);
        $col = fn() => $sdb->query("SHOW COLUMNS FROM users LIKE 'votes_public'")->fetch() ?: [];
        $c = $col();
        check('fresh path: CREATE makes users.votes_public tinyint(1), NOT NULL, default 0',
              strtolower((string)($c['Type'] ?? '')) === 'tinyint(1)' && ($c['Null'] ?? '') === 'NO' && (string)($c['Default'] ?? '') === '0', json_encode($c));
        $sdb->exec("ALTER TABLE users DROP COLUMN votes_public");
        $alters = array_values(array_filter(trackerSchemaGuardedStatements($sdb), fn($s) => is_string($s) && str_contains($s, 'ALTER TABLE `users`') && str_contains($s, '`votes_public`')));
        foreach ($alters as $s) $sdb->exec($s);
        $c = $col();
        check('upgrade path: the guarded ALTER puts it back, the same shape, on a table that predates it',
              count($alters) === 1 && strtolower((string)($c['Type'] ?? '')) === 'tinyint(1)' && (string)($c['Default'] ?? '') === '0', json_encode($alters));
        check('… and asks for nothing once it is there', !array_filter(trackerSchemaGuardedStatements($sdb), fn($s) => is_string($s) && str_contains($s, '`votes_public`')));
    } finally {
        $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
} catch (\Throwable $e) {
    check('a scratch database could be built for the two schema paths', false, $e->getMessage());
}

/* ══ 2. the setting, in its four places ═══════════════════════════════════ */
check('profile_votes_enabled ships ON', (trackerSchemaDefaultSettings()['profile_votes_enabled'] ?? null) === '1');
$save = $src('api/admin/save_settings.php');
check('it is in the save allow-list', str_contains($save, "    'profile_votes_enabled',\n"));
check('… and coerced to 0/1 with the other switches', (bool)preg_match("/'shout_system_lines', 'profile_votes_enabled', 'avatars_enabled'/", $save));
$tpl = $src('templates/admin/settings.php');
$sp = strpos($tpl, 'id="section-profile-votes"');
$sec = $sp === false ? '' : substr($tpl, $sp, (int)strpos($tpl, 'class="settings-section"', $sp + 20) - $sp);
check('a section of its own in Settings → Profiles, right after the description\'s, with the control',
      str_contains($tpl, 'id="section-profile-votes" data-group="profiles"') && $sp > (int)strpos($tpl, 'id="section-profile-bio"')
      && str_contains($sec, 'name="profile_votes_enabled"') && str_contains($sec, 'data-setting="profile_votes_enabled"'));
check('… labelled and explained from the dictionary, and saying so while ratings are off',
      str_contains($sec, "_h('settings.profile_votes_heading')") && str_contains($sec, "__('settings.profile_votes_intro')")
      && str_contains($sec, "__('settings.profile_votes_enabled_hint')") && str_contains($sec, "__('settings.profile_votes_rep_off')")
      && str_contains($sec, 'repEnabled($cfg)'));
check('it has search words', !empty(settingsCatalogKeywords()['profile_votes_enabled']));
$en = include $root . '/lang/en.php';
$pl = include $root . '/lang/pl.php';
$helpOk = true;
foreach (['settings.profile_votes_intro', 'settings.profile_votes_enabled_hint', 'settings.profile_votes_rep_off',
          'account.votes_public_hint_thumbs', 'account.votes_public_hint_stars'] as $k) {
    if (trim((string)($en[$k] ?? '')) === '' || trim((string)($pl[$k] ?? '')) === '' || ($en[$k] ?? '') === ($pl[$k] ?? '')) $helpOk = false;
}
check('the help texts exist in both languages, and the Polish is Polish',
      $helpOk && str_contains((string)$pl['settings.profile_votes_intro'], 'rating.public') && str_contains((string)$pl['settings.profile_votes_intro'], 'głos'));
check('the help says ratings must be on, and the privacy help says the low votes show too',
      str_contains((string)$en['settings.profile_votes_intro'], 'only while ratings are switched on')
      && str_contains((string)$en['account.votes_public_hint_thumbs'], 'thumbs down') && str_contains((string)$en['account.votes_public_hint_stars'], 'low ratings'));
check('the titles follow the mode: Likes / Polubienia, Ratings / Oceny, on the tab and the section alike',
      $en['account.tab_votes_thumbs'] === 'Likes' && $pl['account.tab_votes_thumbs'] === 'Polubienia'
      && $en['account.tab_votes_stars'] === 'Ratings' && $pl['account.tab_votes_stars'] === 'Oceny'
      && $en['profile.votes_thumbs'] === 'Likes' && $pl['profile.votes_stars'] === 'Oceny');
check('the feature needs accounts, its switch AND ratings',
      profileVotesEnabled($cfgOn) && !profileVotesEnabled(array_merge($cfgOn, ['profile_votes_enabled' => '0']))
      && !profileVotesEnabled(array_merge($cfgOn, ['rep_enabled' => '0'])) && !profileVotesEnabled(array_merge($cfgOn, ['users_enabled' => '0'])));

/* ══ 3. the permission ════════════════════════════════════════════════════ */
check('rating.public is registered, with the brief\'s description', (userPermissionList()['rating.public'] ?? '') === 'Let their likes/ratings be shown on their public profile');
check('… in the member preset, and nowhere near the premium extras',
      in_array('rating.public', userGroupPresets()['member']['perms'], true) && !in_array('rating.public', userGroupPresets()['premium']['perms'], true));
check('… and with accounts switched off nobody has it (unlike rating.vote, which works without accounts)',
      userLegacyDefault('rating.public') === false && userLegacyDefault('rating.vote') === true);
$gperm = fn(string $slug): array => json_decode($perms($slug), true) ?: [];
$db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"rating.public\"') WHERE slug = 'member'");
$db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v75_rating_public'");
trackerSchemaDataMigrations($db, $cfgOn);
check('the v75 migration grants rating.public to members', !empty($gperm('member')['rating.public']), json_encode($gperm('member')));
check('… and not to guests', empty($gperm('guest')['rating.public']));
check('… and stamps its marker', (int)$db->query("SELECT COUNT(*) FROM settings WHERE `key` = 'schema_grant_v75_rating_public'")->fetchColumn() === 1);
$db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"rating.public\"') WHERE slug = 'member'");
trackerSchemaDataMigrations($db, $cfgOn);
check('ONCE: an operator who takes it away afterwards keeps it taken away', empty($gperm('member')['rating.public']));
$db->exec("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\"rating.public\":true}') WHERE slug = 'member'");
userPermissionsForget(0);

/* ══ 4. the gate, one flag at a time ══════════════════════════════════════ */
// Two scratch groups, each the member row less ONE id: the owner's grant, and the reader's right to
// open profiles at all.
$memberJson = $gperm('member');
foreach (['pvtest_nopub' => 'rating.public', 'pvtest_noview' => 'favourites.view_others'] as $slug => $without) {
    $j = $memberJson; unset($j[$without]);
    $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES (?, ?, '', '', 2, 0, 0, ?)")
       ->execute([$slug, $slug, json_encode($j, JSON_UNESCAPED_SLASHES)]);
}
$ownerId  = pvUser($db, $cfgOn, 'pvtest_owner');
$viewerId = pvUser($db, $cfgOn, 'pvtest_viewer');
$db->prepare("UPDATE users SET votes_public = 1 WHERE id = ?")->execute([$ownerId]);
$shown = fn(array $c, int $o, ?int $v): bool => profileVotesShownTo($db, $c, $row($o), $v === null ? null : $row($v));
check('the baseline: a member who said yes, whose group grants it, read by another member', $shown($cfgOn, $ownerId, $viewerId));
check('… and their own list, by themselves', $shown($cfgOn, $ownerId, $ownerId));
check('the switch off: nobody\'s, not even your own', !$shown(array_merge($cfgOn, ['profile_votes_enabled' => '0']), $ownerId, $viewerId)
      && !$shown(array_merge($cfgOn, ['profile_votes_enabled' => '0']), $ownerId, $ownerId));
check('ratings off: nobody\'s, not even your own', !$shown(array_merge($cfgOn, ['rep_enabled' => '0']), $ownerId, $viewerId)
      && !$shown(array_merge($cfgOn, ['rep_enabled' => '0']), $ownerId, $ownerId));
check('accounts off: nobody\'s', !$shown(array_merge($cfgOn, ['users_enabled' => '0']), $ownerId, $viewerId));
check('profiles off: not another\'s — your own still is (the account page\'s tab)',
      !$shown(array_merge($cfgOn, ['profiles_enabled' => '0']), $ownerId, $viewerId) && $shown(array_merge($cfgOn, ['profiles_enabled' => '0']), $ownerId, $ownerId));
$db->prepare("UPDATE users SET votes_public = 0 WHERE id = ?")->execute([$ownerId]);
check('their own flag off: not for another — for themselves, yes', !$shown($cfgOn, $ownerId, $viewerId) && $shown($cfgOn, $ownerId, $ownerId));
$db->prepare("UPDATE users SET votes_public = 1 WHERE id = ?")->execute([$ownerId]);
$noPubId = pvUser($db, $cfgOn, 'pvtest_nopub_owner');
pvOnlyIn($db, $noPubId, 'pvtest_nopub');
$db->prepare("UPDATE users SET votes_public = 1 WHERE id = ?")->execute([$noPubId]);
check('an owner whose groups do not grant rating.public: not for another, whatever their flag says',
      !$shown($cfgOn, $noPubId, $viewerId) && $shown($cfgOn, $noPubId, $noPubId));
$adminOwner = pvUser($db, $cfgOn, 'pvtest_admin_owner');
pvOnlyIn($db, $adminOwner, 'admin');
$db->prepare("UPDATE users SET votes_public = 1 WHERE id = ?")->execute([$adminOwner]);
check('an owner only in the admin group does NOT pass: the blanket is power, not consent',
      userIdHasPermission($db, $cfgOn, $adminOwner, 'rating.public') && empty($gperm('admin')['rating.public'])
      && !$shown($cfgOn, $adminOwner, $viewerId));
$db->exec("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\"rating.public\":true}') WHERE slug = 'admin'");
check('… until the admin group is GRANTED it, like anybody else\'s', $shown($cfgOn, $adminOwner, $viewerId));
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'admin'")->execute([$adminBefore]);
$noViewId = pvUser($db, $cfgOn, 'pvtest_noview_reader');
pvOnlyIn($db, $noViewId, 'pvtest_noview');
check('a reader whose account may not open other profiles (favourites.view_others) sees nothing', !$shown($cfgOn, $ownerId, $noViewId));
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)")->execute([$ownerId, $viewerId]);
check('an owner who blocked this reader with "hide my profile": not for them', !$shown($cfgOn, $ownerId, $viewerId));
$db->prepare("UPDATE user_blocks SET hide_profile = 0 WHERE user_id = ? AND blocked_id = ?")->execute([$ownerId, $viewerId]);
check('… a block that does not hide the profile does not hide this either', $shown($cfgOn, $ownerId, $viewerId));
$db->prepare("DELETE FROM user_blocks WHERE user_id = ?")->execute([$ownerId]);
$db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$ownerId]);
check('a suspended owner: not for anybody else', !$shown($cfgOn, $ownerId, $viewerId));
$db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$ownerId]);
check('nobody signed in: nothing', !$shown($cfgOn, $ownerId, null));
$ctx = profileVotesContext($db, $cfgOn, $row($ownerId));
check('the account page\'s context: the tab, the mode, a public side to control, and the grant to publish',
      $ctx['enabled'] && $ctx['mode'] === 'thumbs' && $ctx['public_ok'] && $ctx['may_publish'] && !$ctx['publish_blocked'], json_encode($ctx));
$ctxNo = profileVotesContext($db, $cfgOn, $row($noPubId));
check('… without the grant the switch is still drawn, with the sentence that says why (publish_blocked)',
      $ctxNo['public_ok'] && !$ctxNo['may_publish'] && $ctxNo['publish_blocked'], json_encode($ctxNo));
$ctxOff = profileVotesContext($db, array_merge($cfgOn, ['profiles_enabled' => '0']), $row($ownerId));
check('… and with profiles off the switch is still drawn, as the favourites one is: an answer is kept while nothing reads it',
      $ctxOff['enabled'] && $ctxOff['public_ok'] && $ctxOff['may_publish'] && !$ctxOff['publish_blocked'], json_encode($ctxOff));
$ctxRep = profileVotesContext($db, array_merge($cfgOn, ['rep_enabled' => '0']), $row($ownerId));
check('… while with ratings off there is no tab and no switch', !$ctxRep['enabled'] && !$ctxRep['public_ok'] && !$ctxRep['may_publish'] && !$ctxRep['publish_blocked'], json_encode($ctxRep));
check('… and in star mode it says so', profileVotesContext($db, $cfgStars, $row($ownerId))['mode'] === 'stars');

/* ══ 5. the list ══════════════════════════════════════════════════════════ */
$H = fn(string $pfx, int $i): string => str_pad($pfx . sprintf('%04x', $i), 40, '0');
$idx = function (string $hash, ?string $name, int $size, int $seed, int $leech) use ($db): void {
    $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seeders, last_leechers, meta_status, total_size, files_count, first_seen, last_seen, seen_count)
                  VALUES (?, ?, ?, ?, 'done', ?, 1, '2026-08-01 00:00:00', '2026-09-01 00:00:00', 3)")
       ->execute([$hash, $name, $seed, $leech, $size]);
};
$wl = function (string $hash, string $name, int $size, int $seed, bool $banned = false) use ($db): void {
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, meta_status, total_size, files_count, scrape_seeders, scrape_leechers, banned)
                  VALUES (?, ?, 'admin', 'done', ?, 1, ?, 0, ?)")->execute([$hash, $name, $size, $seed, $banned ? 1 : 0]);
};
$vote = function (string $hash, string $type, string $key, int $value, string $at) use ($db): void {
    $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, weight, created_at, updated_at) VALUES (?, ?, ?, ?, 100, ?, ?)
                  ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)")->execute([$hash, $type, $key, $value, $at, $at]);
};
$others = 0;
$extra = function (string $hash, array $values) use ($vote, &$others): void {
    foreach ($values as $v) $vote($hash, 'user', (string)(9000000 + (++$others)), $v, '2026-08-15 00:00:00');
};

// THUMBS: twelve torrents the owner voted on, one hour apart, with everybody else's votes making the
// counts and the scores. T9 has no catalogue row, T10 is whitelist-only, T11 a banned whitelist row,
// T12 in both (the whitelist wins).
$ownT = pvUser($db, $cfgOn, 'pvtest_thumbs');
$T = [];
for ($i = 1; $i <= 13; $i++) $T[$i] = $H('e7e7', $i);
$tName = [1 => 'PV Alpha', 'PV Bravo', 'PV Charlie', 'PV Delta', 'PV Echo', 'PV Foxtrot', 'PV Golf', 'PV Hotel'];
foreach ($tName as $i => $nm) $idx($T[$i], $nm, $i * 1000, $i * 10, $i);
$wl($T[10], 'PV Juliet WL', 10000, 100);
$wl($T[11], 'PV Kilo Banned', 11000, 110, true);
$idx($T[12], 'PV Lima (index)', 1, 1, 1);
$wl($T[12], 'PV Lima', 12000, 120);
$idx($T[13], 'PV Mike Anon', 13000, 130, 13);
$tOwn = [1 => 1, 1, -1, -1, 1, 1, 1, -1, 1, 1, -1, 1];
foreach ($tOwn as $i => $v) $vote($T[$i], 'user', (string)$ownT, $v, sprintf('2026-09-01 %02d:00:00', $i));
$extra($T[1], [1, 1, 1, 1]);            // 5 votes, 100%
$extra($T[2], [-1, -1, 1]);             // 4, 50%
$extra($T[3], [1, 1, 1]);               // 4, 75%
                                        // T4: 1 vote, not shown
$extra($T[5], [1]);                     // 2, not shown
$extra($T[6], [-1, -1, -1, -1, -1]);    // 6, 17%
$extra($T[7], [1, 1, -1]);              // 4, 75%
$extra($T[8], array_fill(0, 9, -1));    // 10, 0%
$extra($T[9], [1, 1, 1]);               // no catalogue row: nothing to show a count on
$extra($T[10], [1, 1]);                 // 3, 100%
$extra($T[11], [-1, -1, -1]);           // 4, 0%
$extra($T[12], [1, 1]);                 // 3, 100%
// An ANONYMOUS vote whose key is the owner's id, as a string — the one shape that could be mistaken
// for theirs. It is an address bucket's, and nobody's list may show it.
$vote($T[13], 'ip', (string)$ownT, 1, '2026-09-01 13:00:00');
// …and a star value from the same owner, which only star mode may list.
$X1 = $H('e7e7', 0xa1);
$idx($X1, 'PV Star Cross', 500, 5, 5);
$vote($X1, 'user', (string)$ownT, 7, '2026-09-01 14:00:00');
foreach ($T as $h) repRecount($db, $h, $cfgOn);
repRecount($db, $X1, $cfgStars);

$me = ['is_owner' => true, 'can_wl' => true, 'can_hash' => true, 'file_hits' => null];
$stranger = ['is_owner' => false, 'can_wl' => true, 'can_hash' => true, 'file_hits' => null];
$list = function (array $c, int $owner, array $q, array $reader, ?DateTimeZone $tz = null) use ($db): array {
    return profileVotesList($db, $c, $owner, profileVotesParams($q + ['per_page' => '100']), $reader, $tz);
};
$order = function (array $res) use ($T): string {
    $flip = array_flip($T);
    return implode(',', array_map(fn($r) => 'T' . ($flip[$r['info_hash']] ?? '?'), $res['rows']));
};
$all = $list($cfgOn, $ownT, [], $me);
check('thumbs: the owner\'s twelve votes, and only them (±1; not the star value, not the anonymous vote)',
      $all['total'] === 12 && count($all['rows']) === 12 && !in_array($X1, array_column($all['rows'], 'info_hash'), true)
      && !in_array($T[13], array_column($all['rows'], 'info_hash'), true), $all['total'] . ' ' . $order($all));
$stAll = $list($cfgStars, $ownT, [], $me);
check('stars: the same owner lists the 1..10 values — the star rating and the thumbs up (a 1 is half a star), never a -1',
      in_array($X1, array_column($stAll['rows'], 'info_hash'), true) && $stAll['total'] === 9
      && !array_filter($stAll['rows'], fn($r) => $r['own_vote'] < 1), $stAll['total'] . ' rows');
check('an anonymous vote is never on anybody\'s list (not the owner\'s, whose id it carries as its key)',
      !array_filter(array_merge($all['rows'], $stAll['rows']), fn($r) => $r['info_hash'] === $T[13]));
$r12 = array_values(array_filter($all['rows'], fn($r) => $r['info_hash'] === $T[12]))[0] ?? [];
check('the whitelist row wins where both exist, as in favRowsFor()', ($r12['name'] ?? '') === 'PV Lima' && ($r12['src'] ?? '') === 'whitelist'
      && ($r12['total_size'] ?? 0) === 12000 && ($r12['seeders'] ?? 0) === 120, json_encode($r12));
// NULL asked with array_key_exists(): `($r['x'] ?? 'y') === null` can never pass — `??` answers its
// fallback for a key that is there and null, which is exactly the case being asked about.
$isNull = fn(array $r, string $k): bool => array_key_exists($k, $r) && $r[$k] === null;
$r9 = array_values(array_filter($all['rows'], fn($r) => $r['info_hash'] === $T[9]))[0] ?? [];
check('a hash the catalogue let go of is still the owner\'s vote: no name, no count, no score',
      $isNull($r9, 'name') && $isNull($r9, 'src') && ($r9['votes_count'] ?? -1) === 0 && ($r9['score_shown'] ?? true) === false, json_encode($r9));
$r1 = array_values(array_filter($all['rows'], fn($r) => $r['info_hash'] === $T[1]))[0] ?? [];
$r4 = array_values(array_filter($all['rows'], fn($r) => $r['info_hash'] === $T[4]))[0] ?? [];
check('a row carries the favourites fields and the vote\'s own: 100% from five votes, all up',
      ($r1['name'] ?? '') === 'PV Alpha' && ($r1['total_size'] ?? 0) === 1000 && ($r1['seeders'] ?? 0) === 10 && ($r1['leechers'] ?? 0) === 1
      && ($r1['own_vote'] ?? 0) === 1 && ($r1['votes_count'] ?? 0) === 5 && ($r1['score_shown'] ?? false) === true
      && ($r1['score_x100'] ?? 0) === 10000 && ($r1['votes_up'] ?? 0) === 5 && ($r1['votes_down'] ?? -1) === 0
      && ($r1['voted_at'] ?? '') !== '' && array_key_exists('last_seen', $r1) && array_key_exists('files_count', $r1), json_encode($r1));
check('below the minimum: null, not zero — the score and the up/down split that IS the score',
      ($r4['votes_count'] ?? 0) === 1 && ($r4['score_shown'] ?? true) === false && array_key_exists('score_x100', $r4) && $r4['score_x100'] === null
      && $r4['votes_up'] === null && $r4['votes_down'] === null, json_encode($r4));
$sortOf = fn(array $c, int $o, string $key, string $d) => $order($list($c, $o, ['sort' => $key, 'dir' => $d], $me));
check('sort by the date of the vote, newest first (the default)', $order($all) === 'T12,T11,T10,T9,T8,T7,T6,T5,T4,T3,T2,T1', $order($all));
check('… and oldest first', $sortOf($cfgOn, $ownT, 'date', 'asc') === 'T1,T2,T3,T4,T5,T6,T7,T8,T9,T10,T11,T12', $sortOf($cfgOn, $ownT, 'date', 'asc'));
check('sort by the own vote, up first then down (each newest first)', $sortOf($cfgOn, $ownT, 'own', 'desc') === 'T12,T10,T9,T7,T6,T5,T2,T1,T11,T8,T4,T3', $sortOf($cfgOn, $ownT, 'own', 'desc'));
check('… and down first', $sortOf($cfgOn, $ownT, 'own', 'asc') === 'T11,T8,T4,T3,T12,T10,T9,T7,T6,T5,T2,T1', $sortOf($cfgOn, $ownT, 'own', 'asc'));
check('sort by score, best first — and the three without a score AFTER every shown one',
      $sortOf($cfgOn, $ownT, 'score', 'desc') === 'T1,T12,T10,T7,T3,T2,T6,T8,T11,T5,T4,T9', $sortOf($cfgOn, $ownT, 'score', 'desc'));
check('… worst first — and the three without a score still after every shown one',
      $sortOf($cfgOn, $ownT, 'score', 'asc') === 'T8,T11,T6,T2,T7,T3,T1,T12,T10,T5,T4,T9', $sortOf($cfgOn, $ownT, 'score', 'asc'));
check('sort by the number of votes, most first', $sortOf($cfgOn, $ownT, 'votes', 'desc') === 'T8,T6,T1,T11,T7,T3,T2,T12,T10,T5,T4,T9', $sortOf($cfgOn, $ownT, 'votes', 'desc'));
check('… fewest first', $sortOf($cfgOn, $ownT, 'votes', 'asc') === 'T9,T4,T5,T12,T10,T11,T7,T3,T2,T1,T6,T8', $sortOf($cfgOn, $ownT, 'votes', 'asc'));
check('sort by name A–Z, the nameless last', $sortOf($cfgOn, $ownT, 'name', 'asc') === 'T1,T2,T3,T4,T5,T6,T7,T8,T10,T11,T12,T9', $sortOf($cfgOn, $ownT, 'name', 'asc'));
check('… Z–A, the nameless still last', $sortOf($cfgOn, $ownT, 'name', 'desc') === 'T12,T11,T10,T8,T7,T6,T5,T4,T3,T2,T1,T9', $sortOf($cfgOn, $ownT, 'name', 'desc'));
check('sort by size, largest first (unknown last)', $sortOf($cfgOn, $ownT, 'size', 'desc') === 'T12,T11,T10,T8,T7,T6,T5,T4,T3,T2,T1,T9', $sortOf($cfgOn, $ownT, 'size', 'desc'));
check('… smallest first (unknown still last)', $sortOf($cfgOn, $ownT, 'size', 'asc') === 'T1,T2,T3,T4,T5,T6,T7,T8,T10,T11,T12,T9', $sortOf($cfgOn, $ownT, 'size', 'asc'));
check('sort by seeders, most first (unknown last)', $sortOf($cfgOn, $ownT, 'seeders', 'desc') === 'T12,T11,T10,T8,T7,T6,T5,T4,T3,T2,T1,T9', $sortOf($cfgOn, $ownT, 'seeders', 'desc'));
check('… fewest first', $sortOf($cfgOn, $ownT, 'seeders', 'asc') === 'T1,T2,T3,T4,T5,T6,T7,T8,T10,T11,T12,T9', $sortOf($cfgOn, $ownT, 'seeders', 'asc'));
check('thumbs filter: their vote up', $order($list($cfgOn, $ownT, ['vote' => 'up'], $me)) === 'T12,T10,T9,T7,T6,T5,T2,T1');
check('… down', $order($list($cfgOn, $ownT, ['vote' => 'down'], $me)) === 'T11,T8,T4,T3');
check('… at least four votes', $order($list($cfgOn, $ownT, ['min_votes' => '4'], $me)) === 'T11,T8,T7,T6,T3,T2,T1');
check('… both at once', $order($list($cfgOn, $ownT, ['min_votes' => '4', 'vote' => 'up'], $me)) === 'T7,T6,T2,T1');
check('… the star filters mean nothing in thumbs mode', $list($cfgOn, $ownT, ['own_min' => '8', 'avg_min' => '400'], $me)['total'] === 12);
check('search: a name, in any case', $order($list($cfgOn, $ownT, ['search' => 'bRaVo'], $me)) === 'T2');
check('… every name that has "pv" (the nameless one has none)', $list($cfgOn, $ownT, ['search' => 'pv'], $me)['total'] === 11);
check('… a hash prefix', $order($list($cfgOn, $ownT, ['search' => substr($T[5], 0, 12)], $me)) === 'T5');
check('… LIKE\'s own characters are text, not wildcards', $list($cfgOn, $ownT, ['search' => '%'], $me)['total'] === 0
      && $list($cfgOn, $ownT, ['search' => '_'], $me)['total'] === 0 && $list($cfgOn, $ownT, ['search' => '\\'], $me)['total'] === 0);
$db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, 'pv/secret-track-name.flac', 1)")->execute([$T[4]]);
$hits = profileVotesFileHits($db, $cfgOn, $ownT, 'secret-track');
check('… inside file names, through the favourites helper over the owner\'s own votes', $hits === [$T[4]], json_encode($hits));
check('… and the list takes those hits', $order($list($cfgOn, $ownT, ['search' => 'secret-track'], ['file_hits' => $hits] + $me)) === 'T4');
check('… a file-name search needs two characters, like the favourites one', profileVotesFileHits($db, $cfgOn, $ownT, 's') === []);
$str = $list($cfgOn, $ownT, [], $stranger);
check('a stranger\'s copy has no banned row — in the rows AND in the count', $str['total'] === 11 && !in_array($T[11], array_column($str['rows'], 'info_hash'), true));
$own11 = array_values(array_filter($all['rows'], fn($r) => $r['info_hash'] === $T[11]))[0] ?? [];
check('… while the owner sees it, marked banned', ($own11['banned'] ?? false) === true, json_encode($own11));
$noWl = $list($cfgOn, $ownT, [], ['can_wl' => false] + $me);
$nw10 = array_values(array_filter($noWl['rows'], fn($r) => $r['info_hash'] === $T[10]))[0] ?? [];
$nw12 = array_values(array_filter($noWl['rows'], fn($r) => $r['info_hash'] === $T[12]))[0] ?? [];
check('without whitelist.view nothing of a whitelist row reaches the reader: no name, no count, the index row where there is one',
      $noWl['total'] === 12 && $isNull($nw10, 'name') && ($nw10['votes_count'] ?? -1) === 0 && ($nw12['name'] ?? '') === 'PV Lima (index)', json_encode([$nw10, $nw12]));
$noHash = $list($cfgOn, $ownT, [], ['can_hash' => false] + $stranger);
check('a stranger without index.magnet gets no hashes (a hash is a magnet)', $noHash['total'] === 11 && !array_filter(array_column($noHash['rows'], 'info_hash')));
check('… and cannot find one by prefix either', $list($cfgOn, $ownT, ['search' => substr($T[5], 0, 12)], ['can_hash' => false] + $stranger)['total'] === 0);
check('the owner keeps the hashes without index.magnet (it opens the Info panel they voted in)',
      count(array_filter(array_column($list($cfgOn, $ownT, [], ['can_hash' => false] + $me)['rows'], 'info_hash'))) === 12);
$paged = [];
$pageSizes = [];
for ($p = 1; $p <= 3; $p++) {
    $res = profileVotesList($db, $cfgOn, $ownT, profileVotesParams(['per_page' => '5', 'page' => (string)$p]), $me);
    $pageSizes[] = count($res['rows']) . '/' . $res['total'] . '/' . $res['pages'] . '/' . $res['page'];
    foreach ($res['rows'] as $r) $paged[] = $r['info_hash'];
}
check('pagination: five, five and two, one total, three pages', implode(' ', $pageSizes) === '5/12/3/1 5/12/3/2 2/12/3/3', implode(' ', $pageSizes));
check('… and the pages put together are the whole list in order, nothing twice', $paged === array_column($all['rows'], 'info_hash'));
$past = profileVotesList($db, $cfgOn, $ownT, profileVotesParams(['per_page' => '5', 'page' => '99']), $me);
check('a page past the end is the last page', $past['page'] === 3 && count($past['rows']) === 2);
$filteredPages = profileVotesList($db, $cfgOn, $ownT, profileVotesParams(['per_page' => '3', 'vote' => 'up', 'sort' => 'name', 'dir' => 'asc', 'page' => '2']), $me);
check('filters and the sort hold on the second page (up only, by name: Foxtrot, Golf, Juliet)',
      $filteredPages['total'] === 8 && implode(',', array_column($filteredPages['rows'], 'name')) === 'PV Foxtrot,PV Golf,PV Juliet WL', json_encode(array_column($filteredPages['rows'], 'name')));
$tokyo = new DateTimeZone('Asia/Tokyo');
$unix = (int)$db->query("SELECT UNIX_TIMESTAMP('2026-09-01 12:00:00')")->fetchColumn();
$tk = $list($cfgOn, $ownT, [], $me, $tokyo)['rows'][0] ?? [];
check('the date of the vote is written in the READER\'s zone, read as an instant',
      ($tk['voted_at'] ?? '') === (new DateTimeImmutable('@' . $unix))->setTimezone($tokyo)->format('Y-m-d H:i')
      && ($tk['voted_full'] ?? '') === (new DateTimeImmutable('@' . $unix))->setTimezone($tokyo)->format('Y-m-d H:i:s P')
      && str_ends_with((string)($tk['voted_full'] ?? ''), '+09:00') && ($tk['voted_at'] ?? '') !== '2026-09-01 12:00', json_encode($tk));

// STARS: eight torrents, averages 0.5 to 5; two below the minimum.
$ownS = pvUser($db, $cfgStars, 'pvtest_stars');
$S = [];
for ($i = 1; $i <= 9; $i++) { $S[$i] = $H('e7e7', 0x100 + $i); $idx($S[$i], 'PV Star ' . $i, $i * 100, $i, 0); }
$sOwn = [1 => 10, 8, 6, 4, 2, 1, 7, 5];
foreach ($sOwn as $i => $v) $vote($S[$i], 'user', (string)$ownS, $v, sprintf('2026-09-02 %02d:00:00', $i));
$extra($S[1], [10, 10]);     // 500
$extra($S[2], [8, 8]);       // 400
$extra($S[3], [6, 6, 6]);    // 300
$extra($S[4], [4, 4]);       // 200
$extra($S[5], [2, 2]);       // 100
                             // S6: one rating of 1 — not shown (stored 50)
$extra($S[7], [9]);          // two ratings — not shown (stored 400)
$extra($S[8], [5, 5, 5, 5]); // 250
$vote($S[9], 'user', (string)$ownS, -1, '2026-09-02 09:00:00');   // a thumb down: thumbs mode only
foreach ($S as $h) repRecount($db, $h, $cfgStars);
$sorder = function (array $res) use ($S): string {
    $flip = array_flip($S);
    return implode(',', array_map(fn($r) => 'S' . ($flip[$r['info_hash']] ?? '?'), $res['rows']));
};
$sAll = $list($cfgStars, $ownS, [], $me);
check('stars: the eight ratings, 1..10, and not the thumb down', $sAll['total'] === 8 && $sorder($sAll) === 'S8,S7,S6,S5,S4,S3,S2,S1', $sorder($sAll));
// The one value both modes share: their half-star rating (1) reads as a thumb up in thumbs mode — the
// table does not say which mode a vote was cast in — and nothing else of theirs is a thumb.
$sThumbs = $list($cfgOn, $ownS, [], $me);
check('thumbs: the same owner lists the thumb down, and the one rating that IS a thumb up (1), nothing else',
      $sThumbs['total'] === 2 && $sorder($sThumbs) === 'S9,S6', $sorder($sThumbs));
$s1 = array_values(array_filter($sAll['rows'], fn($r) => $r['info_hash'] === $S[1]))[0] ?? [];
$s7 = array_values(array_filter($sAll['rows'], fn($r) => $r['info_hash'] === $S[7]))[0] ?? [];
check('a star row: their 10 (five stars), the average in hundredths (500), no up and down',
      ($s1['own_vote'] ?? 0) === 10 && ($s1['score_x100'] ?? 0) === 500 && ($s1['votes_count'] ?? 0) === 3 && $s1['votes_up'] === null && $s1['votes_down'] === null, json_encode($s1));
check('… below the minimum the stored average (400 here) does not leave the server', ($s7['score_shown'] ?? true) === false && $s7['score_x100'] === null, json_encode($s7));
check('stars: sort by the average, best first, the unrated after', $sorder($list($cfgStars, $ownS, ['sort' => 'score', 'dir' => 'desc'], $me)) === 'S1,S2,S3,S8,S4,S5,S7,S6');
check('… worst first, the unrated still after', $sorder($list($cfgStars, $ownS, ['sort' => 'score', 'dir' => 'asc'], $me)) === 'S5,S4,S8,S3,S2,S1,S7,S6');
check('… by their own rating, highest first', $sorder($list($cfgStars, $ownS, ['sort' => 'own', 'dir' => 'desc'], $me)) === 'S1,S2,S7,S3,S8,S4,S5,S6');
check('… lowest first', $sorder($list($cfgStars, $ownS, ['sort' => 'own', 'dir' => 'asc'], $me)) === 'S6,S5,S4,S8,S3,S7,S2,S1');
check('stars filter: their rating from 3 to 5 stars', $sorder($list($cfgStars, $ownS, ['own_min' => '6', 'own_max' => '10'], $me)) === 'S7,S3,S2,S1');
check('… from ½ to 1 star', $sorder($list($cfgStars, $ownS, ['own_min' => '1', 'own_max' => '2'], $me)) === 'S6,S5');
check('… the average from 2.5 to 5 — and a torrent below the minimum is not in it, whatever it stores',
      $sorder($list($cfgStars, $ownS, ['avg_min' => '250', 'avg_max' => '500'], $me)) === 'S8,S3,S2,S1');
check('… the average from 0 to 1 (S6 stores 0.5 and has no average to be in a range)', $sorder($list($cfgStars, $ownS, ['avg_min' => '0', 'avg_max' => '100'], $me)) === 'S5');
check('… both ranges at once', $sorder($list($cfgStars, $ownS, ['own_min' => '8', 'avg_min' => '300'], $me)) === 'S2,S1');
check('… a range given backwards is the same range', $sorder($list($cfgStars, $ownS, ['own_min' => '10', 'own_max' => '6'], $me)) === 'S7,S3,S2,S1');
check('… the thumbs filters mean nothing in star mode', $list($cfgStars, $ownS, ['vote' => 'down', 'min_votes' => '99'], $me)['total'] === 8);

// The parameters, one bad value at a time.
$pp = profileVotesParams([]);
check('parameters: the defaults — page 1 of 25, newest vote first, full ranges, files on',
      $pp['page'] === 1 && $pp['per_page'] === 25 && $pp['sort'] === 'date' && $pp['dir'] === 'desc' && $pp['own_min'] === 1 && $pp['own_max'] === 10
      && $pp['avg_min'] === 0 && $pp['avg_max'] === 500 && $pp['vote'] === 'all' && $pp['min_votes'] === 0 && $pp['files'] === true && $pp['search'] === '', json_encode($pp));
$pb = profileVotesParams(['page' => '-3', 'per_page' => '500', 'sort' => 'rank; DROP', 'dir' => 'sideways', 'own_min' => '0', 'own_max' => '99',
                          'avg_min' => '-1', 'avg_max' => '9999', 'vote' => 'maybe', 'min_votes' => '-7', 'files' => '0']);
check('… numbers are clamped to their ranges, unknown words are the default',
      $pb['page'] === 1 && $pb['per_page'] === 100 && $pb['sort'] === 'date' && $pb['dir'] === 'desc' && $pb['own_min'] === 1 && $pb['own_max'] === 10
      && $pb['avg_min'] === 0 && $pb['avg_max'] === 500 && $pb['vote'] === 'all' && $pb['min_votes'] === 0 && $pb['files'] === false, json_encode($pb));
$pw = profileVotesParams(['page' => '2abc', 'per_page' => 'lots', 'own_min' => '1.5', 'min_votes' => ['x'], 'sort' => ['name'], 'search' => ['a']]);
check('… and a value that is not a number at all is the default, not a zero', $pw['page'] === 1 && $pw['per_page'] === 25 && $pw['own_min'] === 1
      && $pw['min_votes'] === 0 && $pw['sort'] === 'date' && $pw['search'] === '', json_encode($pw));
$ps = profileVotesParams(['search' => "  " . str_repeat('x', 200) . "  ", 'own_min' => '9', 'own_max' => '2', 'avg_min' => '400', 'avg_max' => '100']);
check('… a search is trimmed to 120 characters, and two ranges given backwards are swapped',
      mb_strlen($ps['search']) === 120 && $ps['own_min'] === 2 && $ps['own_max'] === 9 && $ps['avg_min'] === 100 && $ps['avg_max'] === 400, json_encode($ps));
check('… text that is not UTF-8 is no search, and control characters are spaces',
      profileVotesParams(['search' => "\xC3\x28bad"])['search'] === '' && profileVotesParams(['search' => "a\x01b"])['search'] === 'a b');
foreach (PROFILE_VOTES_SORTS as $key) {
    foreach (['asc', 'desc'] as $d) {
        $okSort = true;
        try { profileVotesList($db, $cfgOn, $ownT, profileVotesParams(['sort' => $key, 'dir' => $d, 'search' => 'pv', 'min_votes' => '1']), $stranger); }
        catch (\Throwable $e) { $okSort = false; }
        if (!$okSort) check("sort $key $d runs with a search and a filter, as a stranger", false);
    }
}
check('every sort key runs both ways beside a search and a filter, as a stranger (no SQL error)', true);
check('the half-star steps the selects offer: ½ … 5, and 0 … 5 for an average',
      implode(' ', profileVotesStarSteps(1)) === "\u{00BD} 1 1\u{00BD} 2 2\u{00BD} 3 3\u{00BD} 4 4\u{00BD} 5" && count(profileVotesStarSteps(0)) === 11 && profileVotesStarSteps(0)[0] === '0');

/* ══ 6. three thousand votes: the plan and the clock ══════════════════════ */
$ownP = pvUser($db, $cfgOn, 'pvtest_perf');
$N = 3000;
for ($c = 0; $c < $N; $c += 500) {
    $iv = []; $vv = []; $ia = []; $va = [];
    for ($i = $c; $i < min($N, $c + 500); $i++) {
        $h = $H('e8e8', $i);
        $iv[] = "(?, ?, ?, 0, 'done', ?, 1, '2026-08-01 00:00:00', '2026-09-01 00:00:00', 1, ?, ?)";
        array_push($ia, $h, 'PV Perf ' . sprintf('%04d', $i), $i % 97, $i * 1024, 1 + ($i % 9), ($i * 37) % 10001);
        $vv[] = "(?, 'user', ?, ?, 100, ?, ?)";
        $at = date('Y-m-d H:i:s', strtotime('2026-06-01 00:00:00') + $i * 600);
        array_push($va, $h, (string)$ownP, $i % 3 === 0 ? -1 : 1, $at, $at);
    }
    $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seeders, last_leechers, meta_status, total_size, files_count, first_seen, last_seen, seen_count, votes_count, score_x100) VALUES " . implode(',', $iv))->execute($ia);
    $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, weight, created_at, updated_at) VALUES " . implode(',', $vv))->execute($va);
}
$db->query("ANALYZE TABLE hash_votes, index_hashes")->fetchAll();
check('the fixture: three thousand votes of one member', (int)$db->query("SELECT COUNT(*) FROM hash_votes WHERE voter_type = 'user' AND voter_key = " . $db->quote((string)$ownP))->fetchColumn() === $N);
$plans = [];
foreach ([['sort' => 'date'], ['sort' => 'score', 'dir' => 'asc'], ['sort' => 'name', 'search' => 'Perf 01'], ['sort' => 'votes', 'vote' => 'up', 'min_votes' => '5']] as $q) {
    $pq = profileVotesParams($q);
    $sq = profileVotesSql($cfgOn, $ownP, $pq, $stranger);
    $st = $db->prepare("EXPLAIN SELECT " . $sq['cols'] . " FROM hash_votes v " . $sq['join'] . " WHERE " . $sq['where'] . " ORDER BY " . $sq['order'] . " LIMIT 25 OFFSET 0");
    $st->execute($sq['args']);
    $byTable = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $byTable[$e['table']] = $e;
    // `ref` or `range` over the one (voter_type, voter_key) prefix — MariaDB picks either for two
    // equalities on an index's leading parts and both read the member's rows only; `ALL` (a scan of
    // the table) or another index is the failure this is here to catch.
    $okPlan = ($byTable['v']['key'] ?? '') === 'idx_votes_voter' && in_array($byTable['v']['type'] ?? '', ['ref', 'range'], true)
        && ($byTable['h']['type'] ?? '') === 'eq_ref' && ($byTable['h']['key'] ?? '') === 'PRIMARY'
        && ($byTable['w']['type'] ?? '') === 'eq_ref' && ($byTable['w']['key'] ?? '') === 'uq_whitelist_hash';
    $plans[] = json_encode($q) . ' => v:' . ($byTable['v']['type'] ?? '?') . '/' . ($byTable['v']['key'] ?? '?') . ' h:' . ($byTable['h']['type'] ?? '?') . '/' . ($byTable['h']['key'] ?? '?')
             . ' w:' . ($byTable['w']['type'] ?? '?') . '/' . ($byTable['w']['key'] ?? '?') . ' (' . ($byTable['v']['Extra'] ?? '') . ')';
    check('EXPLAIN ' . json_encode($q) . ': the member\'s votes by idx_votes_voter, each catalogue row by its own unique key (eq_ref)', $okPlan, end($plans));
}
echo "     plans: " . implode(' | ', $plans) . "\n";
$times = [];
foreach ([['sort' => 'date'], ['sort' => 'score'], ['sort' => 'name', 'dir' => 'asc'], ['search' => 'Perf 02', 'sort' => 'seeders'], ['page' => '60']] as $q) {
    $t0 = microtime(true);
    $res = profileVotesList($db, $cfgOn, $ownP, profileVotesParams($q), $stranger);
    $times[] = json_encode($q) . ' ' . round((microtime(true) - $t0) * 1000, 1) . ' ms (' . $res['total'] . ' rows, page ' . $res['page'] . ')';
    check('a page of three thousand in well under half a second: ' . json_encode($q), (microtime(true) - $t0) < 0.5, end($times));
}
echo "     timings: " . implode(' | ', $times) . "\n";

/* ══ 7. the endpoint and the privacy save, as requests; the pages ═════════ */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pv_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/richtext.php", "includes/reputation.php", "includes/mail.php", "includes/users.php",
          "includes/favourites.php", "includes/usermedia.php", "includes/people.php", "includes/lists.php", "includes/profilevotes.php",
          "includes/audit.php", "includes/auth.php", "includes/lang.php"] as $f) require_once $f;
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
$run = function (string $endpoint, string $file, string $method, array $get, array $post, array $session, array $cfgExtra) use ($root, $runner, &$tmpFiles): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pv_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'file' => $root . '/' . $file, 'method' => $method,
        'get' => $get, 'post' => $post, 'session' => $session, 'cfg' => $cfgExtra, 'sid' => 'pvtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 400)];
};
$sess = fn(int $uid) => ['user_id' => $uid, 'user_login_time' => time(), 'csrf_token' => 'pv-child-token'];
$pvCfg = ['users_enabled' => '1', 'profiles_enabled' => '1', 'profile_votes_enabled' => '1', 'rep_enabled' => '1', 'rep_mode' => 'thumbs',
          'rep_min_votes' => '3', 'users_require_email_verify' => '1', 'index_search_include_whitelist' => '1'];
$db->prepare("UPDATE users SET votes_public = 0 WHERE id = ?")->execute([$ownT]);
$j = $run('user_votes', 'api/user_votes.php', 'GET', ['sort' => 'score', 'dir' => 'desc', 'per_page' => '5'], [], $sess($ownT), $pvCfg);
check('the endpoint file, as a request: your own list, sorted, a page of five of twelve',
      !empty($j['success']) && ($j['own'] ?? false) === true && ($j['mode'] ?? '') === 'thumbs' && ($j['total'] ?? 0) === 12 && count($j['rows'] ?? []) === 5
      && ($j['rows'][0]['info_hash'] ?? '') === $T[1] && ($j['params']['sort'] ?? '') === 'score' && ($j['min_votes'] ?? 0) === 3, json_encode($j));
$j = $run('user_votes', 'api/user_votes.php', 'GET', ['user' => 'pvtest_thumbs'], [], $sess($viewerId), $pvCfg);
check('… another member asking for it while it is not public: the same 404 as a name nobody has', ($j['error'] ?? '') === 'not_found' && empty($j['success']), json_encode($j));
$j2 = $run('user_votes', 'api/user_votes.php', 'GET', ['user' => 'pvtest_nobody_at_all'], [], $sess($viewerId), $pvCfg);
check('… (a name nobody has, for comparison)', ($j2['error'] ?? '') === 'not_found' && json_encode($j) === json_encode($j2), json_encode($j2));
$db->prepare("UPDATE users SET votes_public = 1 WHERE id = ?")->execute([$ownT]);
$j = $run('user_votes', 'api/user_votes.php', 'GET', ['user' => 'pvtest_thumbs', 'vote' => 'down'], [], $sess($viewerId), $pvCfg);
check('… once they say yes: their list, as a stranger sees it (the banned row gone, the filter applied)',
      !empty($j['success']) && ($j['own'] ?? true) === false && ($j['total'] ?? 0) === 3 && ($j['params']['vote'] ?? '') === 'down', json_encode($j));
$j = $run('user_votes', 'api/user_votes.php', 'GET', [], [], ['csrf_token' => 'pv-child-token'], $pvCfg);
check('… nobody signed in: 401 for "my" list', ($j['error'] ?? '') === 'login_required', json_encode($j));
$j = $run('user_votes', 'api/user_votes.php', 'GET', ['user' => 'pvtest_thumbs'], [], ['csrf_token' => 'pv-child-token'], $pvCfg);
check('… and the 404 for somebody else\'s', ($j['error'] ?? '') === 'not_found', json_encode($j));
$j = $run('user_votes', 'api/user_votes.php', 'GET', [], [], $sess($ownT), ['rep_enabled' => '0'] + $pvCfg);
check('… ratings off: 404, even for your own', ($j['error'] ?? '') === 'not_found', json_encode($j));
$j = $run('user_votes', 'api/user_votes.php', 'GET', [], [], $sess($ownT), ['profile_votes_enabled' => '0'] + $pvCfg);
check('… the switch off: 404', ($j['error'] ?? '') === 'not_found', json_encode($j));
$j = $run('user_votes', 'api/user_votes.php', 'POST', [], ['csrf_token' => 'pv-child-token'], $sess($ownT), $pvCfg);
check('… it answers GET only', ($j['error'] ?? '') === 'method_not_allowed', json_encode($j));
$j = $run('user_votes', 'api/user_votes.php', 'GET', ['sort' => 'nonsense', 'per_page' => '9999', 'own_min' => 'x'], [], $sess($ownS), ['rep_mode' => 'stars'] + $pvCfg);
check('… star mode, and the parameters it actually used', !empty($j['success']) && ($j['mode'] ?? '') === 'stars' && ($j['total'] ?? 0) === 8
      && ($j['params']['sort'] ?? '') === 'date' && ($j['per_page'] ?? 0) === 100 && ($j['params']['own_min'] ?? 0) === 1, json_encode($j['params'] ?? $j));
$db->prepare("UPDATE users SET votes_public = 0 WHERE id = ?")->execute([$viewerId]);
$j = $run('user_privacy', 'api/user_privacy.php', 'POST', [], ['csrf_token' => 'pv-child-token', 'votes_public' => 1], $sess($viewerId), $pvCfg);
check('the privacy save: votes_public goes on, and the answer says so', !empty($j['success']) && ($j['votes_public'] ?? false) === true
      && (int)$row($viewerId)['votes_public'] === 1 && ($j['votes_may_publish'] ?? false) === true, json_encode($j));
$j = $run('user_privacy', 'api/user_privacy.php', 'POST', [], ['csrf_token' => 'pv-child-token', 'votes_public' => 0], $sess($viewerId), $pvCfg);
check('… and off again', !empty($j['success']) && ($j['votes_public'] ?? true) === false && (int)$row($viewerId)['votes_public'] === 0, json_encode($j));
$j = $run('user_privacy', 'api/user_privacy.php', 'POST', [], ['csrf_token' => 'not-it', 'votes_public' => 1], $sess($viewerId), $pvCfg);
check('… never without the session\'s token', !empty($j['error']) && (int)$row($viewerId)['votes_public'] === 0, json_encode($j));
$j = $run('user_privacy', 'api/user_privacy.php', 'POST', [], ['csrf_token' => 'pv-child-token', 'votes_public' => 1], $sess($noPubId), $pvCfg);
check('… saved without the grant too (the answer is kept), and the answer says nothing will show it',
      !empty($j['success']) && ($j['votes_public'] ?? false) === true && ($j['votes_may_publish'] ?? true) === false, json_encode($j));

$api = $src('api.php');
check('the endpoint is routed, and api.php and index.php load the library',
      str_contains($api, "'user_votes'                 => 'api/user_votes.php'") && str_contains($api, "require_once __DIR__ . '/includes/profilevotes.php';")
      && str_contains($src('index.php'), "require_once __DIR__ . '/includes/profilevotes.php';"));
$prof = $src('templates/pages/profile.php');
$pFav = strpos($prof, 'id="profile-fav"'); $pVot = strpos($prof, 'id="profile-votes"'); $pUp = strpos($prof, 'id="profile-uploads"'); $pLi = strpos($prof, 'id="profile-lists"');
check('the profile: the section right after Favourites, before Uploads and Lists, behind the one gate',
      $pFav !== false && $pVot > $pFav && $pUp > $pVot && $pLi > $pUp
      && str_contains($prof, '$showVotes = function_exists(\'profileVotesShownTo\') && profileVotesShownTo($db, $cfg, $profile, $viewer);')
      && str_contains($prof, "_h('profile.votes_' . repMode(\$cfg))") && str_contains($prof, '!$showFav && !$showVotes'));
$acc = $src('templates/pages/account.php');
$aFav = strpos($acc, 'data-pane="favourites"'); $aVot = strpos($acc, 'data-pane="votes"'); $aUp = strpos($acc, 'data-pane="uploads"');
check('the account page: the tab right after Favourites, named by the mode, and its pane',
      $aFav !== false && $aVot > $aFav && $aUp > $aVot && str_contains($acc, "_h('account.tab_votes_' . \$accVotes['mode'])")
      && str_contains($acc, 'id="acc-pane-votes"') && str_contains($acc, "include __DIR__ . '/../partials/votes_section.php'"));
$aFp = strpos($acc, 'id="acc-fav-public"'); $aVp = strpos($acc, 'id="acc-votes-public"'); $aLp = strpos($acc, 'id="acc-lists-public"');
check('… the privacy switch after the favourites one, drawn the way it is, labelled by the mode, with the grant warning',
      $aFp !== false && $aVp > $aFp && $aLp > $aVp && str_contains($acc, "\$accVotes['may_publish'] || \$accVotes['publish_blocked']")
      && str_contains($acc, "_h('account.votes_public_label_' . \$accVotes['mode'])") && str_contains($acc, "['perm' => 'rating.public']"));
$part = $src('templates/partials/votes_section.php');
check('the partial: thumbs filters by the vote and the count, stars by two half-star ranges; sortable header buttons',
      str_contains($part, 'id="pv-vote"') && str_contains($part, 'id="pv-min-votes"') && str_contains($part, 'id="pv-own-min"') && str_contains($part, 'id="pv-avg-max"')
      && substr_count($part, "\$pvHead('pv-h-") === 7 && str_contains($part, '<button type="button" class="pv-sort" data-sort="')
      && str_contains($part, '<i class="bi bi-arrow-down-up search-sort-icon" aria-hidden="true"></i>'));
$fj = $src('assets/js/favourites.js');
check('the tab router knows the pane, right after favourites (a pane it does not list falls back to Overview)',
      str_contains($fj, "var panes = ['overview', 'favourites', 'votes', 'uploads',"));
check('… and a pane it knows but this page does not carry (the feature switched off since a link was kept) falls back too',
      str_contains($fj, "if (panes.indexOf(name) === -1 || !document.getElementById('acc-pane-' + name)) name = 'overview';"));
check('… the privacy switch posts votes_public', str_contains($fj, "['acc-votes-public', 'votes_public']"));
$vjFrom = (int)strpos($fj, 'function starsReadOnly(');
$vj = $vjFrom > 0 ? substr($fj, $vjFrom, (int)strpos($fj, '"who has this in favourites"', $vjFrom) - $vjFrom) : '';
check('the component builds with textContent only, redraws on a live language switch, and reloads when a vote changes',
      $vj !== '' && !str_contains($vj, 'innerHTML') && !str_contains($vj, 'insertAdjacentHTML') && str_contains($vj, "document.addEventListener('langswap'")
      && str_contains($vj, "document.addEventListener('rating:changed'") && str_contains($src('assets/js/app.js'), "new CustomEvent('rating:changed'"));
check('its strings reach the public pages (js.votes. in LANG_JS_PUBLIC)', in_array('js.votes.', LANG_JS_PUBLIC, true));
$map = iconFaMap();
check('the two new icons are in the Font Awesome map, as solid thumbs', ($map['hand-thumbs-up-fill'] ?? '') === 'fa-solid fa-thumbs-up'
      && ($map['hand-thumbs-down-fill'] ?? '') === 'fa-solid fa-thumbs-down' && str_contains($fj, "'bi bi-hand-thumbs-up-fill'"));
check('the layout loads the script (and the tab router in it) while the feature is on',
      str_contains($src('templates/layout.php'), "|| (function_exists('profileVotesEnabled') && profileVotesEnabled(\$cfg))): ?>"));
check('the privacy endpoint saves the flag with the others', str_contains($src('api/user_privacy.php'), "'profile_listed', 'votes_public'] as \$flag"));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
