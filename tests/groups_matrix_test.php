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
]);
// ONLY the extras. A premium account is a member as well, so repeating the member row here would
// mean a membership that lapses takes the whole site with it.
$PREMIUM = ['profile.cover', 'shout.upload_emote'];
// The v25 seed, plus what v63 and v71 added. favourites/uploads are in it because they have been
// since v25 — this release adds to groups, and removes exactly one thing from exactly one group.
$MODERATOR = ['panel.access', 'panel.reports.view', 'panel.reports.status', 'panel.reports.block',
              'panel.reports.email', 'panel.reports.archive', 'panel.appeals.resolve',
              'panel.whitelist.view', 'panel.whitelist.add', 'panel.whitelist.ban',
              'panel.whitelist.meta', 'panel.whitelist.content',
              'panel.users.view', 'panel.users.notify',
              'index.view', 'index.files', 'index.files_all', 'index.magnet',
              'whitelist.view', 'whitelist.add', 'stats.view', 'stats.timeline', 'home.stats',
              'rating.vote', 'content.submit', 'content.propose', 'content.view',
              'favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public',
              'shout.view', 'shout.post', 'shout.delete_own', 'shout.moderate',
              // v72 (1.66.0): editing anybody's line, to this group ONLY and never with shout.moderate.
              'shout.edit_any'];

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
                                        ['shout.edit_any']));
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
    // neither have 1.69.0's v74 and v75 ones (profile.bio, rating.public).
    $sdb->exec("DELETE FROM settings WHERE `key` IN ('schema_once_v71_group_matrix', 'schema_grant_v72_shout_edit', 'schema_grant_v74_profile_bio', 'schema_grant_v75_rating_public')");
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
// stores no `profile.cover` — it passes every check by its blanket — so a display rule asking for a
// STORED grant instead of the effective permission would blank the owner's cover the moment this
// release reached the live site, where the owner is exactly this account. Pin it.
$gmAdmin = $gmUser('gmtest_admincover');
$adminGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$gmAdmin]);
userGrantGroup($db, $gmAdmin, $adminGid, null, 'test', 'groups_matrix', false);
$db->prepare("UPDATE users SET cover_sha = ? WHERE id = ?")->execute([$coverSha, $gmAdmin]);
$adminRow = $db->query("SELECT * FROM users WHERE id = " . (int)$gmAdmin)->fetch(PDO::FETCH_ASSOC);
$adminStored = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'admin'")->fetchColumn(), true) ?: [];
check('an account only in the admin group keeps its cover painted, though that group stores no profile.cover',
      $adminGid > 0 && empty($adminStored['profile.cover']) && is_array(userCoverFor($adminRow, '/', $cfgOn, $db)));
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
