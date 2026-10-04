<?php
/**
 * The shipped permission matrix, the premium group, and what happens when premium lapses
 * (needs the local test database — it builds a scratch one of its own):
 *
 *   php tests/groups_matrix_test.php
 *
 * ── what this file is for ──────────────────────────────────────────────────────────────────────
 * Until 1.65.0 nothing stated what a group gets by default. The matrix lived in four places that
 * could drift apart — the seed rows in includes/schema.php, the grants beside them, the presets in
 * includes/users.php, and whatever an operator had done by hand — and they HAD drifted: a fresh
 * install's `member` could page past the first batch of a file list (`index.files_all`) without
 * being allowed to open the list at all, and the seeded `moderator` could approve descriptions it
 * was not allowed to read. The moderator's preset and its seed said two different things, so
 * applying the preset rewrote a seeded moderator into a different job.
 *
 * So the matrix is written down HERE, as a table, and asked of a database built from nothing. Three
 * independent copies of it now have to agree: this table, the migration in includes/schema.php, and
 * userGroupPresets(). Two of them agreeing proves nothing; three of them written by hand in three
 * places is the point.
 *
 * It also asks the two questions the release turns on: does the migration onto an EXISTING install
 * leave alone what the operator added (it removes exactly one thing, `profile.cover` from member),
 * and is a cover that stops being allowed still THERE afterwards.
 *
 * Self-cleaning: the scratch database is dropped, and every row and setting it touches on the live
 * test database is put back.
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
require_once $root . '/includes/usermedia.php';
require_once $root . '/includes/api_auth.php';
require_once $root . '/includes/audit.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
/** Set comparison that says what is missing and what is extra, because "false" is not a diagnosis. */
function setDiff(array $want, array $have): string {
    sort($want); sort($have);
    return 'missing: ' . (implode(',', array_diff($want, $have)) ?: '—')
         . ' | extra: ' . (implode(',', array_diff($have, $want)) ?: '—');
}
/** Every permission id quoted inside a chunk of schema.php source (a seed row, a grant call). */
function idsIn(string $src): array {
    preg_match_all('/[\'"\\\\]([a-z][a-z_]*\.[a-z_.]+)[\'"\\\\]/', $src, $m);
    $known = userPermissionList();
    return array_values(array_unique(array_filter($m[1] ?? [], fn($k) => isset($known[$k]))));
}

// ─────────────────────────────────────────────────────────────────────────────
// THE TABLE. The brief's matrix, written out once, by hand.
// ─────────────────────────────────────────────────────────────────────────────
// Signed-in accounts hold the UNION of their groups and guest is NOT inherited, so each row is the
// whole of what that group carries — not a difference from the row above it.
$GUEST = ['whitelist.view', 'stats.view', 'stats.timeline', 'home.stats',
          'rating.vote', 'content.submit', 'content.propose'];
$MEMBER = array_merge($GUEST, [
    'index.view', 'index.files', 'index.files_all', 'index.magnet', 'whitelist.add',
    'content.view', 'status.hash_check', 'sounds.use',
    'favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public',
    'lists.use', 'lists.public',
    'pm.send', 'pm.report', 'friends.use', 'directory.view',
    'shout.view', 'shout.post', 'shout.delete_own',
    // v72 (1.66.0): correcting your own line for a while, beside the delete it mirrors.
    'shout.edit_own',
    'profile.avatar',
    // v74 (1.69.0): a description on their own profile.
    'profile.bio',
    // v75 (1.69.0): their likes or ratings may be listed on their profile — with their own yes.
    'rating.public',
    // v81 (1.70.0): deleting a description they are the author of, and the list of the descriptions they
    // wrote on their profile — with their own yes.
    'content.delete_own', 'content.public',
    // v83 (1.71.0): comments on a torrent — read, write, and their own corrected or taken back for a while.
    'comment.view', 'comment.post', 'comment.edit_own', 'comment.delete_own',
    // v84 (1.71.0): report a comment, a description or a shout to the moderators.
    'content.report',
    // v88 (1.72.0): reply to a comment.
    'comment.reply',
]);
// ONLY the extras. A premium account is a member as well, so repeating the member row here would
// mean a membership that lapses takes the whole site with it.
$PREMIUM = ['profile.cover', 'shout.upload_emote'];
// The v25 seed, plus what v63 and v71 added. (Until 1.72.0 this row also carried index.files_all and the four
// favourites / uploads ids, with the claim that they "have been in it since v25". They had not: the seed's TEXT gained
// them at v42 and v47, which reaches only a NEW install, and those releases granted them to member alone — so no
// upgraded install's moderator ever had them, the owner's included. The row is the moderator every install has now;
// a moderator is a member too, which is where the five come from.)
$MODERATOR = ['panel.access', 'panel.reports.view', 'panel.reports.status', 'panel.reports.block',
              'panel.reports.email', 'panel.reports.archive', 'panel.appeals.resolve',
              'panel.whitelist.view', 'panel.whitelist.add', 'panel.whitelist.ban',
              'panel.whitelist.meta', 'panel.whitelist.content',
              'panel.users.view', 'panel.users.notify',
              'index.view', 'index.files', 'index.magnet',
              'whitelist.view', 'whitelist.add', 'stats.view', 'stats.timeline', 'home.stats',
              'rating.vote', 'content.submit', 'content.propose', 'content.view',
              'shout.view', 'shout.post', 'shout.delete_own', 'shout.moderate',
              // v72 (1.66.0): editing anybody's line, to this group ONLY and never with shout.moderate.
              'shout.edit_any',
              // v81 (1.70.0): taking down anybody's published description from the Info panel.
              'content.delete_any',
              // v83 (1.71.0): comments — read, write, and take down or edit anybody's (with a reason).
              'comment.view', 'comment.post', 'comment.moderate',
              // v84 (1.71.0): the Reports page's queues of reported comments, descriptions and shouts — never the
              // message queue (panel.messages.*), which stays with nobody.
              'panel.reports.comments.view', 'panel.reports.comments.handle',
              'panel.reports.descriptions.view', 'panel.reports.descriptions.handle',
              'panel.reports.shouts.view', 'panel.reports.shouts.handle',
              // v88 (1.72.0): reply to a comment.
              'comment.reply'];

/* ══ 1. the presets ════════════════════════════════════════════════════════ */
// includes/users.php has claimed since 1.21.0 that "users_test.php checks every id here is real".
// It did not. A preset naming an id that does not exist is silently dropped by the group editor, so
// the operator ticks a box, saves, and the permission is simply not there.
$known = userPermissionList();
$ghosts = [];
foreach (userGroupPresets() as $slug => $preset) {
    foreach ($preset['perms'] as $p) if (!isset($known[$p])) $ghosts[] = "$slug:$p";
}
check('every id named by a preset is a registered permission', $ghosts === [], implode(' ', $ghosts));
check('… and every preset has a label, an about line and at least one id',
      count(array_filter(userGroupPresets(), fn($p) => ($p['label'] ?? '') !== '' && ($p['about'] ?? '') !== '' && count($p['perms'] ?? []) > 0))
      === count(userGroupPresets()));

$presets = userGroupPresets();
check('the member preset is the member row of the matrix',
      array_values(array_diff($MEMBER, $presets['member']['perms'])) === []
      && array_values(array_diff($presets['member']['perms'], $MEMBER)) === [],
      setDiff($MEMBER, $presets['member']['perms']));
check('the premium preset is exactly the two paid extras',
      array_values(array_diff($PREMIUM, $presets['premium']['perms'])) === []
      && array_values(array_diff($presets['premium']['perms'], $PREMIUM)) === [],
      setDiff($PREMIUM, $presets['premium']['perms']));
check('the moderator preset is the moderator row of the matrix',
      array_values(array_diff($MODERATOR, $presets['moderator']['perms'])) === []
      && array_values(array_diff($presets['moderator']['perms'], $MODERATOR)) === [],
      setDiff($MODERATOR, $presets['moderator']['perms']));
// The premium group is grantable by a key only while it carries no panel id — api/v1/users_grant.php
// refuses a group that does. A preset that quietly grew one would make the group unsellable.
check('no panel id is anywhere near the premium preset',
      array_filter($presets['premium']['perms'], 'userIsPanelPermission') === []);
check('and the member preset keeps neither paid extra',
      !in_array('profile.cover', $presets['member']['perms'], true)
      && !in_array('shout.upload_emote', $presets['member']['perms'], true));

// The preset and the SEED have to say the same thing, because "apply the moderator preset" to a
// seeded moderator must not change what that group is. The seed is read out of the source rather
// than out of this database: what is being compared is the two shipped lists, not what an operator
// has since done to their own row.
$schemaSrc = (string)file_get_contents($root . '/includes/schema.php');
preg_match_all('/INSERT IGNORE INTO `user_groups`.*?VALUES(.*?)"\s*\)/s', $schemaSrc, $seedM);
$seedFor = [];
foreach ($seedM[1] ?? [] as $stmt) {
    foreach (['guest', 'member', 'admin', 'moderator', 'premium'] as $slug) {
        if (str_contains($stmt, "('" . $slug . "'")) $seedFor[$slug] = array_merge($seedFor[$slug] ?? [], idsIn($stmt));
    }
}
check('the seed statements were found at all', isset($seedFor['moderator'], $seedFor['premium'], $seedFor['member']),
      implode(',', array_keys($seedFor)));
check('the premium SEED carries exactly the paid extras',
      array_values(array_diff($PREMIUM, $seedFor['premium'] ?? [])) === []
      && array_values(array_diff($seedFor['premium'] ?? [], $PREMIUM)) === [],
      setDiff($PREMIUM, $seedFor['premium'] ?? []));
// What the moderator ends up with is its seed plus three later grants; that union is what the preset
// must equal. Naming the grants here rather than grepping for them keeps the sum honest.
$modSeedPlus = array_unique(array_merge($seedFor['moderator'] ?? [],
                                        ['shout.view', 'shout.post', 'shout.delete_own', 'shout.moderate'],
                                        ['content.view'],
                                        ['shout.edit_any'],
                                        ['content.delete_any'],
                                        ['comment.view', 'comment.post', 'comment.moderate'],
                                        ['panel.reports.comments.view', 'panel.reports.comments.handle',
                                         'panel.reports.descriptions.view', 'panel.reports.descriptions.handle',
                                         'panel.reports.shouts.view', 'panel.reports.shouts.handle'],
                                        ['comment.reply']));
check('the moderator preset and the moderator seed agree',
      array_values(array_diff($modSeedPlus, $presets['moderator']['perms'])) === []
      && array_values(array_diff($presets['moderator']['perms'], $modSeedPlus)) === [],
      setDiff($modSeedPlus, $presets['moderator']['perms']));

/* ══ 2. the matrix, on a database built from nothing ═══════════════════════ */
// A scratch database on the test connection's own server, built the way install.php builds one.
// Group permissions are written by MIGRATIONS, so this is the only way to see what an install
// actually ends up holding rather than what the source says it should.
$live = getDb();
$dsnHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dsnPort = (string)($live->query('SELECT @@port')->fetchColumn() ?: (defined('DB_PORT') ? DB_PORT : '3306'));
$dsnUser = defined('DB_USER') ? DB_USER : 'root';
$dsnPass = defined('DB_PASS') ? DB_PASS : '';
$base = "mysql:host=$dsnHost;port=$dsnPort;charset=utf8mb4";
try {
    $adm = new PDO($base, $dsnUser, $dsnPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (\Throwable $e) {
    fwrite(STDERR, "cannot reach the database server to build a scratch schema: " . $e->getMessage() . "\n");
    exit(2);
}
$scratch = 'tracker_gm_' . bin2hex(random_bytes(3));
$perms = function (PDO $db, string $slug): array {
    $j = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn(), true);
    $k = array_keys(array_filter(is_array($j) ? $j : []));
    sort($k);
    return $k;
};
try {
    $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $sdb = new PDO("$base;dbname=$scratch", $dsnUser, $dsnPass,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT)
                ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (trackerSchemaStatements() as $sql) $sdb->exec($sql);
    $sdb->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','0')");
    $scfg = [];
    foreach ($sdb->query("SELECT `key`,`value` FROM settings")->fetchAll() as $r) $scfg[$r['key']] = $r['value'];
    ensureSchema($sdb, $scfg);

    foreach (['guest' => $GUEST, 'member' => $MEMBER, 'premium' => $PREMIUM, 'moderator' => $MODERATOR] as $slug => $want) {
        $have = $perms($sdb, $slug);
        check("a new install's `$slug` group is the matrix row, exactly",
              array_values(array_diff($want, $have)) === [] && array_values(array_diff($have, $want)) === [],
              setDiff($want, $have));
    }
    // 1.72.0: the Admin group's STORED list is every capability and no consent id (schemaAdminGrant(), the v89 step):
    // the matrix draws what is true, and what others may see of an administrator stays a grant somebody gives.
    $caps = userCapabilityPermissions();
    sort($caps);
    $freshAdmin = $perms($sdb, 'admin');
    check("a new install's admin group stores every capability and no consent id",
          $freshAdmin === $caps, setDiff($caps, $freshAdmin));
    // What a new install ships, kept for §8: every recommended set must be contained in it.
    $freshHas = [];
    foreach (USER_RECOMMENDED_SLUGS as $slug) $freshHas[$slug] = $perms($sdb, $slug);
    $prem = $sdb->query("SELECT * FROM user_groups WHERE slug = 'premium'")->fetch(PDO::FETCH_ASSOC);
    check('premium is a system group, is never the default, and sits between member and moderator',
          $prem && (int)$prem['is_system'] === 1 && (int)$prem['is_default'] === 0
          && (int)$prem['priority'] > 1 && (int)$prem['priority'] < 500, json_encode($prem ? array_diff_key($prem, ['permissions' => 1]) : null));
    check('… has a colour of its own and says that uploads still wait for a moderator',
          $prem && trim((string)$prem['color']) !== '' && $prem['color'] !== ($sdb->query("SELECT color FROM user_groups WHERE slug='moderator'")->fetchColumn())
          && stripos((string)$prem['description'], 'moderator') !== false, (string)($prem['description'] ?? ''));
    check('… and the default group for a new account is still `member`',
          (string)($scfg['users_default_group'] ?? '') === 'member'
          && (int)$sdb->query("SELECT is_default FROM user_groups WHERE slug = 'member'")->fetchColumn() === 1);
    check('the order book exists on a fresh install, with the key that makes a retry harmless',
          schemaTableExists($sdb, 'user_group_orders')
          && schemaIndexExists($sdb, 'user_group_orders', 'uq_ugo_client_order'));

    /* ══ 3. the same migration onto an EXISTING install ════════════════════ */
    // The owner's server, imitated: a `member` group from before this release (it has the cover, it
    // is missing the four index ids) with one permission the OPERATOR added by hand. The migration
    // must add the matrix, take the cover away, and leave the operator's own decision alone.
    $sdb->exec("UPDATE user_groups SET permissions = " . $sdb->quote(json_encode([
        'whitelist.view' => true, 'stats.view' => true, 'stats.timeline' => true, 'home.stats' => true,
        'rating.vote' => true, 'content.submit' => true, 'content.propose' => true, 'content.view' => true,
        'index.files_all' => true, 'status.hash_check' => true, 'sounds.use' => true,
        'favourites.use' => true, 'favourites.public' => true, 'favourites.view_others' => true, 'uploads.public' => true,
        'lists.use' => true, 'lists.public' => true,
        'pm.send' => true, 'pm.report' => true, 'friends.use' => true, 'directory.view' => true,
        'shout.view' => true, 'shout.post' => true, 'shout.delete_own' => true,
        'profile.avatar' => true, 'profile.cover' => true,
        // the operator's own: a permission this release neither adds nor removes
        'shout.moderate' => true,
    ])) . " WHERE slug = 'member'");
    $sdb->exec("DELETE FROM user_groups WHERE slug = 'premium'");
    // …and from before 1.66.0 as well: the v72 grant (shout.edit_own) has not happened on it yet, and
    // neither have 1.69.0's v74 and v75 ones (profile.bio, rating.public) nor 1.70.0's v81 one
    // (content.delete_own, content.public) nor 1.71.0's v83 one (the comments) and v84 one (reporting), nor 1.72.0's
    // v88 one (replying).
    $sdb->exec("DELETE FROM settings WHERE `key` IN ('schema_once_v71_group_matrix', 'schema_grant_v72_shout_edit', 'schema_grant_v74_profile_bio', 'schema_grant_v75_rating_public', 'schema_grant_v81_content', 'schema_grant_v83_comments', 'schema_grant_v84_reports', 'schema_grant_v88_replies')");
    // 1.72.0: the owner's Admin group, imitated — what production's held before this release (read 2026-09-29): every id
    // but the fifteen registered since v81 and v88's comment.reply, four of the five consent ids among them (the owner had
    // ticked them himself; content.public came later and he never had it).
    $prodAdminLacks = ['content.delete_own', 'content.delete_any', 'content.public', 'comment.view', 'comment.post',
                       'comment.edit_own', 'comment.delete_own', 'comment.moderate', 'content.report',
                       'panel.reports.comments.view', 'panel.reports.comments.handle', 'panel.reports.descriptions.view',
                       'panel.reports.descriptions.handle', 'panel.reports.shouts.view', 'panel.reports.shouts.handle', 'comment.reply'];
    $prodAdmin = array_values(array_diff(array_keys(userPermissionList()), $prodAdminLacks));
    $sdb->exec("UPDATE user_groups SET permissions = " . $sdb->quote(json_encode(array_fill_keys($prodAdmin, true))) . " WHERE slug = 'admin'");
    trackerSchemaDataMigrations($sdb, $scfg);
    $after = $perms($sdb, 'member');
    check('the migration puts the missing matrix ids on an existing member group',
          array_values(array_diff($MEMBER, $after)) === [], setDiff($MEMBER, $after));
    check('… removes profile.cover, which is the ONE thing it removes',
          !in_array('profile.cover', $after, true));
    check('… and leaves what the operator added by hand exactly where it was',
          in_array('shout.moderate', $after, true), implode(',', $after));
    check('… creating the premium group if it is not there', $perms($sdb, 'premium') === ['profile.cover', 'shout.upload_emote'],
          implode(',', $perms($sdb, 'premium')));
    $adminAfter = $perms($sdb, 'admin');
    $prodConsent = array_values(array_intersect($prodAdmin, userConsentPermissions()));
    $wantAdmin = array_values(array_unique(array_merge(userCapabilityPermissions(), $prodConsent)));
    sort($wantAdmin);
    check('the migration gives the owner\'s admin group every capability it lacked (the matrix\'s empty boxes), and keeps what it had',
          $adminAfter === $wantAdmin, setDiff($wantAdmin, $adminAfter));
    check('… but NOT the consent it never gave (content.public): what others may see of an administrator is a grant somebody gives',
          !in_array('content.public', $adminAfter, true) && count($prodConsent) === 4);
    // Twice is the same as once: the local bootstrap wipes schema_once_* markers, so this step meets
    // the same database again on every release and must not undo anything on the second pass.
    $sdb->exec("DELETE FROM settings WHERE `key` = 'schema_once_v71_group_matrix'");
    trackerSchemaDataMigrations($sdb, $scfg);
    check('running it a second time changes nothing', $perms($sdb, 'member') === $after, setDiff($after, $perms($sdb, 'member')));
    // And a permission the operator takes back AFTER the migration stays taken back: the marker is
    // stamped, so a later release does not quietly restore it.
    $sdb->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"index.magnet\"') WHERE slug = 'member'");
    trackerSchemaDataMigrations($sdb, $scfg);
    check('a permission the operator removes afterwards is not resurrected',
          !in_array('index.magnet', $perms($sdb, 'member'), true));
    // The one exception to "once", and the reason for it (schemaAdminGrant()): on the Admin group a capability is never
    // a choice — the blanket holds it whatever is stored — so a capability missing from its stored list comes back at
    // the next pass, and an id a later release registers and grants to nobody reaches it too. Its consent is a choice,
    // and is never written back.
    $sdb->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"panel.audit.view\"', '$.\"shout.emote_auto\"', '$.\"favourites.public\"') WHERE slug = 'admin'");
    trackerSchemaDataMigrations($sdb, $scfg);
    $again = $perms($sdb, 'admin');
    check('a capability missing from the admin group\'s stored list comes back at the next pass (an id granted to nobody included)',
          in_array('panel.audit.view', $again, true) && in_array('shout.emote_auto', $again, true), implode(',', $again));
    check('… and a consent id taken off it is not given back', !in_array('favourites.public', $again, true));
    // Every grant a migration makes is the Admin group's too — its capabilities, never its consent — and the answer
    // still counts the groups the call NAMES (tests/users_test.php holds the grant probe to 1).
    $sdb->exec("UPDATE user_groups SET permissions = '{}' WHERE slug = 'admin'");
    $sdb->exec("UPDATE user_groups SET permissions = '{}' WHERE slug = 'premium'");
    $named = schemaGrantOnce($sdb, 'gm_admin_copy_probe', ['premium' => ['shout.emote_auto', 'lists.public']]);
    check('a grant gives the admin group the capability it grants, never the consent id beside it, and counts only the group it names',
          $named === 1 && $perms($sdb, 'admin') === ['shout.emote_auto'] && $perms($sdb, 'premium') === ['lists.public', 'shout.emote_auto'],
          $named . ' | admin ' . implode(',', $perms($sdb, 'admin')) . ' | premium ' . implode(',', $perms($sdb, 'premium')));
} finally {
    try { $adm->exec("DROP DATABASE IF EXISTS `$scratch`"); } catch (\Throwable $e) { /* leave it for a person */ }
}

/* ══ 4. when premium lapses: the cover is not drawn, and is not gone ═══════ */
$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$GLOBALS['db'] = $db;
// Every switch this section leans on, stated: nothing is inherited from whatever this database has.
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '1',
                            'avatars_enabled' => '1', 'covers_enabled' => '1',
                            'avatar_default' => 'generated', 'avatar_default_sha' => '', 'cover_default_sha' => '']);
$GLOBALS['cfg'] = $cfgOn;

$gmUser = function (string $name) use ($db, $cfgOn): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfgOn, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    return $id;
};
$gmUid = $gmUser('gmtest_cover');
$premGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'premium'")->fetchColumn();
$coverSha = str_repeat('ab', 20);
$db->prepare("UPDATE users SET cover_sha = ?, cover_x = 30, cover_y = 70, cover_zoom = 1.5 WHERE id = ?")
   ->execute([$coverSha, $gmUid]);
$row = fn() => $db->query("SELECT * FROM users WHERE id = " . (int)$gmUid)->fetch(PDO::FETCH_ASSOC);

check('the premium group is on this database too', $premGid > 0);
check('a member without profile.cover has their cover LEFT ALONE in the database',
      (string)$row()['cover_sha'] === $coverSha);
check('… and it is not painted', userCoverFor($row(), '/', $cfgOn, $db) === null);
// The site's own default cover still paints behind everybody: it is the site's, not theirs.
$cfgDef = array_merge($cfgOn, ['cover_default_sha' => str_repeat('cd', 20)]);
$drawnDefault = userCoverFor($row(), '/', $cfgDef, $db);
check('… while the site default cover still paints', is_array($drawnDefault) && $drawnDefault['default'] === true);

userGrantGroup($db, $gmUid, $premGid, null, 'test', 'groups_matrix', false);
$drawn = userCoverFor($row(), '/', $cfgOn, $db);
check('the moment the group arrives the cover is painted again, with its framing intact',
      is_array($drawn) && $drawn['default'] === false && (float)$drawn['x'] === 30.0 && (float)$drawn['zoom'] === 1.5,
      json_encode($drawn));

userRevokeGroup($db, $gmUid, $premGid, false);
check('and when it lapses it goes back to not being painted', userCoverFor($row(), '/', $cfgOn, $db) === null);
check('… with the row still saying exactly what it said before any of this',
      (string)$row()['cover_sha'] === $coverSha && (float)$row()['cover_zoom'] === 1.5);
// A row with no account id (a JSON list row carrying a name and a hash) cannot be asked the
// question, and must be drawn as it always was rather than silently blanked.
check('a row with no account id is drawn as before',
      is_array(userCoverFor(['username' => 'x', 'cover_sha' => $coverSha], '/', $cfgOn, $db)));
// The owner's own profile, imitated: an account in the ADMIN group and nothing else. That group
// passes every check by its blanket, and until 1.72.0 stored no `profile.cover` — so a display rule
// asking for a STORED grant instead of the effective permission would blank the owner's cover the
// moment it reached the live site, where the owner is exactly this account. Since 1.72.0 the group
// STORES every capability (schemaAdminGrant()), which would let such a rule pass by accident; so the
// check takes the id off the stored list for its own moment, and puts the list back exactly.
$gmAdmin = $gmUser('gmtest_admincover');
$adminGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$gmAdmin]);
userGrantGroup($db, $gmAdmin, $adminGid, null, 'test', 'groups_matrix', false);
$db->prepare("UPDATE users SET cover_sha = ? WHERE id = ?")->execute([$coverSha, $gmAdmin]);
$adminRow = $db->query("SELECT * FROM users WHERE id = " . (int)$gmAdmin)->fetch(PDO::FETCH_ASSOC);
$adminJsonWas = $db->query("SELECT permissions FROM user_groups WHERE slug = 'admin'")->fetchColumn();
try {
    $db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"profile.cover\"') WHERE slug = 'admin'");
    userPermissionsForget();
    $adminStored = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'admin'")->fetchColumn(), true) ?: [];
    check('an account only in the admin group keeps its cover painted, even while that group stores no profile.cover',
          $adminGid > 0 && empty($adminStored['profile.cover']) && is_array(userCoverFor($adminRow, '/', $cfgOn, $db)));
} finally {
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'admin'")->execute([$adminJsonWas]);
    userPermissionsForget();
}
userDeleteCascade($db, $gmAdmin);
// …and a member without it is told where a cover comes from, not warned about a missing grant.
$acctSrc = (string)file_get_contents($root . '/templates/pages/account.php');
check('a member without profile.cover is told which group gives one, not warned',
      str_contains($acctSrc, "account.media_cover_with") && !str_contains($acctSrc, "'perm' => 'profile.cover'"));

check('the account page tells them the picture is still there',
      str_contains((string)file_get_contents($root . '/templates/pages/account.php'), "account.media_cover_kept")
      && str_contains(__('account.media_cover_kept'), 'not being shown'));

/* ══ 5. the order book: one payment, one month ═════════════════════════════ */
// The endpoint's own behaviour over HTTP is tests/shop_api_test.py; what is proved here is the
// test-and-set it is built on, because that is the part that must hold when two retries arrive at
// the same moment and a SELECT-then-INSERT would let both through.
$clientId = 0;
try {
    $db->prepare("INSERT INTO api_clients (label, key_id, secret_hash, secret_hint, scope) VALUES ('groups-matrix-test', ?, ?, 'xxxx', 'shop')")
       ->execute([bin2hex(random_bytes(8)), hash('sha256', 'not-a-real-secret')]);
    $clientId = (int)$db->lastInsertId();
} catch (\Throwable $e) { check('a scratch api client could be made', false, $e->getMessage()); }

$order = 'GM-' . bin2hex(random_bytes(4));
$mkRow = fn(string $id) => ['client_id' => $clientId, 'order_id' => $id, 'user_id' => $gmUid,
                            'group_id' => $premGid, 'action' => 'grant', 'duration' => '1m',
                            'until' => null, 'prev_member' => false, 'prev_expires_at' => null,
                            'new_expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400)];
check('the first claim of an order id wins', userOrderClaim($db, $mkRow($order)) === true);
check('… and the second does not', userOrderClaim($db, $mkRow($order)) === false);
$stored = userOrderFind($db, $clientId, $order);
check('… leaving one row, complete, for the retry to be answered from',
      $stored !== null && (int)$stored['user_id'] === $gmUid && $stored['new_expires_at'] !== null
      && (int)$db->query("SELECT COUNT(*) FROM user_group_orders WHERE order_id = " . $db->quote($order))->fetchColumn() === 1);
check('another key sending the same order id is a different order',
      userOrderFind($db, $clientId + 100000, $order) === null);
check('an order id is printable text, at most 64 characters',
      userValidOrderId('SHOP-2026-000412') && userValidOrderId('a') && !userValidOrderId('')
      && !userValidOrderId(str_repeat('a', 65)) && !userValidOrderId(" leading")
      && !userValidOrderId("new\nline"));

/* ══ 6. who may call what ══════════════════════════════════════════════════ */
check('`shop` is a scope a key can be given', in_array('shop', apiClientScopes(), true));
check('… and `auth` never was, which the guide used to say it was', !in_array('auth', apiClientScopes(), true));
$src = fn(string $f) => (string)file_get_contents($root . '/' . $f);
foreach (['api/v1/users_lookup.php', 'api/v1/users_grant.php', 'api/v1/users_revoke.php'] as $f) {
    check("$f is open to a shop key", str_contains($src($f), "apiRequireScope(\$client, 'users', 'shop')"));
}
// The two things `users` opens that a payment webhook has no business with. Each is checked by the
// CALL it makes — asking for the wider scope alone, so a shop key gets a 403 out of apiRequireScope().
// The word 'shop' appears in the comments of some of these files; the call is the thing that decides.
$onlyUsersScope = function (string $f) use ($src): bool {
    $s = $src($f);
    return str_contains($s, "apiRequireScope(\$client, 'users');")
        && !str_contains($s, "apiRequireScope(\$client, 'users', 'shop')");
};
check('v1/users/provision is NOT open to a shop key: it creates accounts',
      $onlyUsersScope('api/v1/users_provision.php'));
foreach (['login', 'logout', 'verify', 'merge', 'status'] as $b) {
    check("v1/auth/$b is NOT open to a shop key either", $onlyUsersScope("api/v1/auth_$b.php"));
}
check('a key with the shop scope may be told to demand no fields', apiScopeFields('shop') === []);
check('the key dialog offers the scope and says what it withholds',
      str_contains($src('templates/admin/whitelist.php'), 'value="shop"')
      && str_contains($src('assets/js/admin-whitelist.js'), "shop: ['v1/users/lookup', 'v1/users/grant', 'v1/users/revoke']"));
check('the guide has a chapter on selling a group', str_contains($src('templates/pages/apidocs.php'), 'apidocs.h_shop'));
check('the three v1 endpoints write a named audit action, not the fallback',
      auditEndpointAction('v1/users/grant') === 'user.grant'
      && auditEndpointAction('v1/users/revoke') === 'user.revoke'
      && auditEndpointAction('v1/users/provision') === 'user.create');
check('… and those actions are in a group the panel can filter by',
      auditGroupOf('user.grant') === 'users' && auditGroupOf('user.create') === 'users');

/* ══ 7. effectiveness: a grant that does nothing yet, and says so ══════════ */
$unverUid = (function (PDO $db, array $cfg): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute(['gmtest_unver']);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, 'gmtest_unver', 'gmtest_unver@example.org', 'Password123!', '127.0.0.1');
    return (int)($r['user']['id'] ?? 0);
})($db, $cfgOn);
check('an unverified account is told why its new group does nothing yet',
      userGrantEffective($db, $cfgOn, userFindById($db, $unverUid)) === ['effective' => false, 'reason' => 'email_unverified']);
check('… and a verified one that nothing else is wrong with is simply effective',
      userGrantEffective($db, $cfgOn, userFindById($db, $gmUid)) === ['effective' => true, 'reason' => '']);
$db->prepare("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = ?")->execute([$gmUid]);
check('… a banned one is told that instead',
      (userGrantEffective($db, $cfgOn, userFindById($db, $gmUid))['reason'] ?? '') === 'banned');
$db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$gmUid]);
// With verification NOT required the address stops being a reason at all.
check('… and with the verification gate off, an unconfirmed address is not a reason',
      userGrantEffective($db, array_merge($cfgOn, ['users_require_email_verify' => '0']), userFindById($db, $unverUid))['effective'] === true);

/* ══ 8. the recommended sets, consent and the Admin row (1.72.0) ═══════════ */
// The owner: "prepare a basic default set of permissions, and set it on my server too — there are new ones and the
// admin still does not have everything". One definition per seeded group, beside the presets (includes/users.php).
$registry = array_keys(userPermissionList());
$sorted = function (array $a): array { $a = array_values(array_unique($a)); sort($a); return $a; };
foreach (USER_RECOMMENDED_SLUGS as $slug) {
    $rec = userGroupRecommended($slug);
    check("`$slug` has a recommended set, and every id in it is registered",
          is_array($rec) && $rec !== [] && array_values(array_diff($rec, $registry)) === [], json_encode($rec));
}
check('the admin group\'s recommended set is every registered id — computed from the registry, in its order',
      userGroupRecommended('admin') === $registry, count(userGroupRecommended('admin') ?? []) . ' of ' . count($registry));
$usersSrc = (string)file_get_contents($root . '/includes/users.php');
check('… never typed out (the function asks userPermissionList())',
      (bool)preg_match("/if \(\\\$slug === 'admin'\) return array_keys\(userPermissionList\(\)\);/", $usersSrc));
foreach (['guest', 'member', 'premium', 'moderator'] as $slug) {
    check("`$slug`'s recommended set IS its preset, so the editor's \"Start from\" and \"Recommended\" cannot say two things",
          $sorted(userGroupRecommended($slug) ?? []) === $sorted($presets[$slug]['perms'] ?? ['?']),
          setDiff($presets[$slug]['perms'] ?? [], userGroupRecommended($slug) ?? []));
}
// The window names each set in the reader's language (a.users.rec_name_* / rec_about_*); its English is the preset's
// own label and line, word for word, so the two cannot drift (the editor's "Start from" names the presets by the same
// words since 1.73.0 — below).
$enDict = include $root . '/lang/en.php';
$plDict = include $root . '/lang/pl.php';
$labelDrift = [];
foreach (USER_RECOMMENDED_SLUGS as $slug) {
    $l = userGroupRecommendedLabel($slug);
    if (($enDict['a.users.rec_name_' . $slug] ?? null) !== $l['label'] || ($enDict['a.users.rec_about_' . $slug] ?? null) !== $l['about']
        || trim((string)($plDict['a.users.rec_name_' . $slug] ?? '')) === '' || trim((string)($plDict['a.users.rec_about_' . $slug] ?? '')) === '') {
        $labelDrift[] = $slug;
    }
}
check('every recommended set has its name and line in both languages, the English word for word the preset\'s', $labelDrift === [], implode(',', $labelDrift));
// 1.73.0: … and so has every preset of the group editor's "Start from" (the three that are no seeded group's too):
// admin/fetch_groups answers them in the reader's language through the same family.
$presetDrift = [];
foreach (userGroupPresets() as $slug => $p) {
    if (($enDict['a.users.rec_name_' . $slug] ?? null) !== ($p['label'] ?? '') || ($enDict['a.users.rec_about_' . $slug] ?? null) !== ($p['about'] ?? '')
        || trim((string)($plDict['a.users.rec_name_' . $slug] ?? '')) === '' || trim((string)($plDict['a.users.rec_about_' . $slug] ?? '')) === '') {
        $presetDrift[] = $slug;
    }
}
$fgSrc = (string)file_get_contents($root . '/api/admin/fetch_groups.php');
check('1.73.0: every preset has its name and line in both languages, the English word for word the preset\'s — and the groups endpoint answers them so',
      $presetDrift === [] && count(userGroupPresets()) >= 7 && str_contains($fgSrc, "['label' => 'a.users.rec_name_', 'about' => 'a.users.rec_about_']")
      && str_contains($fgSrc, "'presets' => \$presets"), implode(',', $presetDrift));

// What every permission allows was English on every page (the matrix's tooltips, the editor's boxes, the Recommended
// window, Settings' matrices). Each id has perm.<id> in both languages, the English word for word the registry's — so
// a sentence changed in includes/users.php and not in tools/lang_src.d/permissions.py (or back) fails here — and the
// dictionary has no perm.* words for an id the registry does not know.
$regEn = userPermissionList(true);
$permDrift = []; $permOrphan = [];
foreach ($regEn as $id => $words) {
    if (($enDict['perm.' . $id] ?? null) !== $words || trim((string)($plDict['perm.' . $id] ?? '')) === '' || ($plDict['perm.' . $id] ?? '') === $words) {
        $permDrift[] = $id;
    }
}
foreach (array_keys($enDict) as $k) if (str_starts_with($k, 'perm.') && !isset($regEn[substr($k, 5)])) $permOrphan[] = $k;
check('1.73.0: every registered permission has its words in both languages (perm.<id>), the English the registry\'s own, the Polish its own',
      $permDrift === [] && count($regEn) === count($registry), implode(',', $permDrift));
check('… and no perm.* words for an id the registry does not know', $permOrphan === [], implode(',', $permOrphan));
$plWords = array_map(fn($id) => (string)($plDict['perm.' . $id] ?? ''), array_keys($regEn));
check('… no two permissions read the same in Polish (or in English)', count(array_unique($plWords)) === count($plWords) && count(array_unique($regEn)) === count($regEn));
// userPermissionList() answers in the reader's language: the same ids in the same order, only the words change.
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'pl'], null);
$regPl = userPermissionList();
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'en'], null);
check('… userPermissionList() says them in the reader\'s language — the same ids, in the same order (Polish here), and the English on request',
      array_keys($regPl) === array_keys($regEn) && ($regPl['panel.audit.view'] ?? '') === ($plDict['perm.panel.audit.view'] ?? '?')
      && ($regPl['index.view'] ?? '') === ($plDict['perm.index.view'] ?? '?') && userPermissionList() === $regEn,
      ($regPl['index.view'] ?? '') . ' | ' . (userPermissionList()['index.view'] ?? ''));
// The panel's scripts say the words by the id, so they follow the live language switch: the pages that show them carry
// perm. in their bundle.
$auJs = (string)file_get_contents($root . '/assets/js/admin-users.js');
$asJs = (string)file_get_contents($root . '/assets/js/admin-shout.js');
check('… and the panel says them by the id (t.key(\'perm.\' + id)) — the Users and Settings pages\' bundles carry perm.',
      str_contains($auJs, "const permWords = (id) => (t.has('perm.' + id) ? t.key('perm.' + id) : (state.permList[id] || ''));")
      && substr_count($auJs, 'permWords(') >= 3 && !str_contains($auJs, "' — ' + desc")   // the matrix, the editor, the Recommended window
      && str_contains($asJs, "t.has('perm.' + key) ? t.key('perm.' + key) : (permList[key] || '')")
      && str_contains((string)file_get_contents($root . '/templates/admin/users.php'), "langJsBridge(\$baseUrl, ['js.', 'a.users.rec_', 'perm.'])")
      && (bool)preg_match("/langJsBridge\(\\\$baseUrl, \['js\.', 'settings\.js_'[^\]]*'perm\.'/", (string)file_get_contents($root . '/templates/admin/settings.php')));
check('a group without a seed has no recommended set (a group the operator made has nothing to go back to)',
      userGroupRecommended('vip') === null && userGroupRecommended('grantprobe') === null && userGroupRecommended('') === null);
check('the guest\'s set is the public statistics and nothing else (whitelist page, search, descriptions, comments, writing: the operator\'s to open)',
      $sorted(userGroupRecommended('guest') ?? []) === $sorted(['stats.view', 'stats.timeline', 'home.stats']),
      implode(',', userGroupRecommended('guest') ?? []));
check('the member\'s is the member row of the matrix, the premium\'s the two extras, the moderator\'s the moderator every install has',
      $sorted(userGroupRecommended('member') ?? []) === $sorted($MEMBER) && $sorted(userGroupRecommended('premium') ?? []) === $sorted($PREMIUM)
      && $sorted(userGroupRecommended('moderator') ?? []) === $sorted($MODERATOR),
      setDiff($MODERATOR, userGroupRecommended('moderator') ?? []));
// Every set is contained in what a new install ships (§2), so "add what is missing" changes nothing on one — the
// Admin group's consent aside, which only a deliberate tick (or --consent) writes.
$exceed = [];
foreach (USER_RECOMMENDED_SLUGS as $slug) {
    $want = $slug === 'admin' ? userCapabilityPermissions() : (userGroupRecommended($slug) ?? []);
    $over = array_values(array_diff($want, $freshHas[$slug] ?? []));
    if ($over) $exceed[] = $slug . ': ' . implode(',', $over);
}
check('every recommended set is contained in what a new install ships ("add" changes nothing there)', isset($freshHas) && $exceed === [],
      implode(' | ', $exceed));

// Consent and capabilities: two kinds, every registered id exactly one of them.
$consentIds = userConsentPermissions();
check('the consent ids are content.public, favourites.public, lists.public, rating.public and uploads.public',
      $sorted($consentIds) === ['content.public', 'favourites.public', 'lists.public', 'rating.public', 'uploads.public'], implode(',', $consentIds));
check('… every one registered, and consent + capabilities = the registry, with nothing in both',
      array_values(array_diff($consentIds, $registry)) === [] && array_intersect($consentIds, userCapabilityPermissions()) === []
      && $sorted(array_merge($consentIds, userCapabilityPermissions())) === $sorted($registry));
// …and the list is the CODE's: every id read through the consent check — userIdHasGrantedPermission(), and
// userGroupIdsWithPermission() where a group's grant is turned into SQL — and nothing else. Read from the code with
// its comments taken out by the tokeniser (a word in a comment is not a read), so a new consent read that is not in
// the list, or an id in the list that nothing reads that way any more, fails here.
$codeFiles = [];
foreach (['includes', 'api', 'templates', 'tools'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $codeFiles[] = $f->getPathname();
    }
}
foreach (['index.php', 'api.php'] as $f) $codeFiles[] = $root . '/' . $f;
$noComments = function (string $src): string {
    $o = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $o .= is_array($tk) ? $tk[1] : $tk;
    }
    return $o;
};
$grantReads = []; $groupReads = []; $powerReads = []; $readFiles = [];
foreach ($codeFiles as $path) {
    $s = $noComments((string)file_get_contents($path));
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (preg_match_all("/userIdHasGrantedPermission\s*\([^;]*?'([a-z_]+\.[a-z_.]+)'/", $s, $m)) {
        foreach ($m[1] as $id) { $grantReads[$id][] = $rel; $readFiles[$rel] = true; }
    }
    if (preg_match_all("/userGroupIdsWithPermission\s*\(\s*\\\$db\s*,\s*'([a-z_]+\.[a-z_.]+)'/", $s, $m)) {
        foreach ($m[1] as $id) { $groupReads[$id][] = $rel; $readFiles[$rel] = true; }
    }
    if (preg_match_all("/whoPermittedSql\s*\(\s*\\\$db\s*,\s*'([a-z_]+\.[a-z_.]+)'/", $s, $m)) {
        foreach ($m[1] as $id) $powerReads[$id][] = $rel;
    }
}
// who.php turns a section into its grant through a map, and asks userGroupIdsWithPermission() with the variable.
$whoSrc = $noComments((string)file_get_contents($root . '/includes/who.php'));
$whoMap = preg_match("/\\\$perm\s*=\s*\[\s*'fav'\s*=>\s*'([a-z_.]+)'\s*,\s*'votes'\s*=>\s*'([a-z_.]+)'\s*,\s*'lists'\s*=>\s*'([a-z_.]+)'\s*\]/", $whoSrc, $wm)
    && str_contains($whoSrc, 'userGroupIdsWithPermission($db, $perm)') ? [$wm[1], $wm[2], $wm[3]] : [];
foreach ($whoMap as $id) $groupReads[$id][] = 'includes/who.php (the sections\' map)';
check('the consent reads were found at all (not an empty haystack)', count($readFiles) >= 6 && count($whoMap) === 3,
      count($readFiles) . ' files; who.php map ' . json_encode($whoMap));
check('every id the code reads through userIdHasGrantedPermission() is a consent id, and every consent id is read that way',
      $sorted(array_keys($grantReads)) === $sorted($consentIds),
      'read: ' . implode(',', array_keys($grantReads)) . ' | list: ' . implode(',', $consentIds));
// profile.cover is the one other id asked of the stored grants — "which groups give a cover" (1.65.0) — and it is a paid
// extra, not consent: named here, read in the one place, with the admin and guest groups left out by name.
$acctSrc = (string)file_get_contents($root . '/templates/pages/account.php');
$otherGroupReads = array_values(array_diff(array_keys($groupReads), $consentIds));
check('every other id turned into SQL from the stored grants is profile.cover alone — "which groups give a cover", not consent',
      $otherGroupReads === ['profile.cover'] && ($groupReads['profile.cover'] ?? []) === ['templates/pages/account.php']
      && str_contains($acctSrc, "!in_array((string)\$accG['slug'], ['admin', 'guest'], true)"),
      json_encode($groupReads));
check('… and the who-has-this sections ask a consent grant each (favourites.public, rating.public, lists.public)',
      array_values(array_diff($whoMap, $consentIds)) === [] && $sorted($whoMap) === ['favourites.public', 'lists.public', 'rating.public'],
      implode(',', $whoMap));
check('the one power read that also counts the admin group (whoPermittedSql()) is never asked a consent id',
      $powerReads !== [] && array_intersect(array_keys($powerReads), $consentIds) === [], json_encode($powerReads));

// The plans, on rows as the database holds them (no database needed).
$row = fn(string $slug, array $ids, array $extra = []) => ['id' => 0, 'slug' => $slug, 'permissions' => json_encode(array_fill_keys($ids, true) + $extra)];
$pm = userGroupRecommendedPlan($row('member', array_merge(array_diff($MEMBER, ['comment.reply', 'content.report']), ['shout.moderate']), ['no.such.id' => true, 'index.view' => false]));
check('a member group two ids short with one extra: add = the two, a reset would remove the extra — an unregistered key is nobody\'s and is left alone',
      $pm['add'] === userPermissionsOrdered(['comment.reply', 'content.report']) && $pm['remove'] === ['shout.moderate'],
      json_encode(['add' => $pm['add'], 'remove' => $pm['remove']]));
$pmIdx = userGroupRecommendedPlan($row('member', array_diff($MEMBER, ['index.view']), ['index.view' => false]));
check('… a key stored as false is not held, so it is added', $pmIdx['add'] === ['index.view'], json_encode($pmIdx['add']));
$pg = userGroupRecommendedPlan($row('guest', ['stats.view', 'stats.timeline', 'home.stats', 'rating.vote', 'content.submit', 'content.propose']));
check('the owner\'s guest group (the legacy v24 grants): nothing to add; a reset would remove the three writing ids — "add" keeps them',
      $pg['add'] === [] && $sorted($pg['remove']) === $sorted(['rating.vote', 'content.submit', 'content.propose']), json_encode($pg));
$pmod = userGroupRecommendedPlan($row('moderator', $MODERATOR));
$pmodFresh = userGroupRecommendedPlan($row('moderator', array_merge($MODERATOR, ['index.files_all', 'favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public'])));
check('the moderator every install has: nothing either way; one a pre-1.72.0 NEW install made: "add" leaves its five, a reset would take them',
      $pmod['add'] === [] && $pmod['remove'] === [] && $pmodFresh['add'] === []
      && $sorted($pmodFresh['remove']) === $sorted(['index.files_all', 'favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public']));
$pa = userGroupRecommendedPlan($row('admin', $prodAdmin ?? []));
$pac = userGroupRecommendedPlan($row('admin', $prodAdmin ?? []), true);
check('the owner\'s admin group: add = the capabilities it lacks — its missing consent listed apart, and added only with $consent',
      $sorted($pa['add']) === $sorted(array_diff($prodAdminLacks ?? ['?'], ['content.public'])) && $pa['consent_add'] === ['content.public']
      && $sorted($pac['add']) === $sorted($prodAdminLacks ?? ['?']) && $pac['consent'] === true && $pa['consent'] === false,
      json_encode(['add' => $pa['add'], 'consent_add' => $pa['consent_add']]));
check('… and a reset of the admin group would remove nothing: its set is every id, and never a capability',
      $pa['remove'] === [] && $pac['remove'] === []);
check('a group without a recommended set has no plan', userGroupRecommendedPlan($row('vip', ['index.view'])) === null);

// The audit line is one shape for the panel and the shell.
$au = userGroupRecommendedAudit(['id' => 7, 'slug' => 'member'], 'reset', false, ['comment.reply'], ['shout.moderate']);
check('the audit line: group.recommend under Users, the group as its target, what was added and removed',
      $au['action'] === 'group.recommend' && $au['target_type'] === 'group' && $au['target_id'] === 'member'
      && $au['detail'] === ['group' => 'member', 'id' => 7, 'mode' => 'reset', 'consent' => false, 'added' => ['comment.reply'], 'removed' => ['shout.moderate']]
      && auditGroupOf('group.recommend') === 'users' && auditEndpointAction('admin/group_recommended') === 'group.recommend', json_encode($au));

// The code the release rests on, by its shape.
$schemaSrc2 = (string)file_get_contents($root . '/includes/schema.php');
check('schemaGrantOnce() gives the admin group the capabilities each grant introduces (schemaAdminGrant())',
      (bool)preg_match('/function schemaGrantOnce\(.*?schemaAdminGrant\(\$db, array_merge\(.*?return \$changed;/s', $schemaSrc2));
check('… and every data-migration pass writes the whole registry\'s capabilities into it (not behind a once-marker)',
      (bool)preg_match("/schemaGrantOnce\(\\\$db, 'v24_content_rating'.*?try \{\s*schemaAdminGrant\(\\\$db\);/s", $schemaSrc2)
      && !preg_match("/schemaOnce\(\\\$db, 'v89/", $schemaSrc2));
$agFrom = (int)strpos($schemaSrc2, 'function schemaAdminGrant(');
$agBody = $agFrom > 0 ? substr($schemaSrc2, $agFrom, max(0, (int)strpos($schemaSrc2, 'function trackerSchemaDataMigrations(') - $agFrom)) : '';
check('schemaAdminGrant() adds capabilities and only those: never a consent id, never a removal',
      (bool)preg_match('/\$caps = userCapabilityPermissions\(\);.*?if \(empty\(\$cur\[\$p\]\)\) \{ \$cur\[\$p\] = true;/s', $agBody)
      && strlen($agBody) > 300 && !str_contains($agBody, 'unset('));
$saveSrc = (string)file_get_contents($root . '/api/admin/group_save.php');
check('the group editor\'s save keeps every capability on the admin group (its boxes are drawn ticked and disabled)',
      str_contains($saveSrc, "if (\$slug !== 'admin') return \$perms;") && str_contains($saveSrc, 'foreach (userCapabilityPermissions() as $p) $perms[$p] = true;'));
$apiSrc = (string)file_get_contents($root . '/api.php');
check('the Recommended endpoint is routed and, like group editing, owner-only (absent from the endpoint map)',
      (bool)preg_match("/'admin\/group_recommended'\s*=>\s*'api\/admin\/group_recommended\.php'/", $apiSrc)
      && !preg_match("/'admin\/group_recommended'\s*=>\s*'panel\./", $apiSrc));

/* ── put the database back ─────────────────────────────────────────────── */
// By LABEL, not only by the id this run made: a run that died half way through (this file has an
// exit(2) path of its own) would otherwise leave a key behind for the next one to trip over.
$db->prepare("DELETE o FROM user_group_orders o JOIN api_clients c ON c.id = o.client_id WHERE c.label = 'groups-matrix-test'")->execute();
$db->prepare("DELETE FROM user_group_orders WHERE client_id = ?")->execute([$clientId]);
$db->prepare("DELETE FROM api_clients WHERE label = 'groups-matrix-test'")->execute();
foreach ([$gmUid, $unverUid] as $id) if ($id > 0) userDeleteCascade($db, $id);
check('the scratch rows are gone',
      (int)$db->query("SELECT COUNT(*) FROM users WHERE username LIKE 'gmtest_%'")->fetchColumn() === 0
      && (int)$db->query("SELECT COUNT(*) FROM api_clients WHERE label = 'groups-matrix-test'")->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
