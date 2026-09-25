<?php
/**
 * Favourites, public profiles and "my torrents" (needs the local test database):
 *   php tests/favourites_test.php
 *
 * The permission side is proved over HTTP with a real member session in deploy/smoke_users.py —
 * userCan() returns true for any panel session, so nothing in a CLI test could prove it. What is
 * proved here is everything that does not need a session: the schema, the toggle's idempotence, the
 * limit, the cascade, and the truth table the "who has this in favourites" query has to satisfy —
 * and (§12, 1.69.0) that a hash withheld from a reader cannot be searched by, on the three lists of a
 * profile, each endpoint FILE run as a request in a child process with a member's session (no panel).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/schedule.php';
require_once $root . '/includes/index.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/lists.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

/* ── 1. the schema, and no split between a fresh install and an upgraded one ── */
check('schema is at least 47', (int)($cfg['schema_version'] ?? 0) >= 47, (string)($cfg['schema_version'] ?? 'none'));
$tables = $db->query("SHOW TABLES LIKE 'user_favourites'")->fetchAll();
check('user_favourites exists', count($tables) === 1);
$idx = array_column($db->query("SHOW INDEX FROM user_favourites")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
foreach (['uq_fav_once', 'idx_fav_user', 'idx_fav_hash'] as $k) {
    check("index $k exists", in_array($k, $idx, true), implode(',', array_unique($idx)));
}
// install.php runs trackerSchemaStatements(); a table that lives only in the migration would never
// reach a fresh install. `users.bulk_optout` is the split this is guarding against repeating.
$stmts = implode("\n", trackerSchemaStatements($db));
check('the table is in the statement list, not only in the migration', str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `user_favourites`'));
check('users.fav_public is in the CREATE too', str_contains($stmts, '`fav_public`'));
check('users.fav_listed is in the CREATE too', str_contains($stmts, '`fav_listed`'));
check('whitelist.submitter_id is in the CREATE too', str_contains($stmts, '`submitter_id`'));
$schemaSrc = (string)@file_get_contents($root . '/includes/schema.php');
check('… and in the guarded ALTER list as well', substr_count($schemaSrc, "'users', 'fav_public'") === 1
    && substr_count($schemaSrc, "'whitelist', 'submitter_id'") === 1);

/* ── 2. the toggle ─────────────────────────────────────────────────────────── */
$db->exec("DELETE FROM user_favourites WHERE user_id > 900000");
$UID = 900001;
$H1 = str_repeat('11', 20);
$H2 = str_repeat('22', 20);
// TEN, not three: favMaxPerUser() clamps to [10, 5000], so a setting of 3 is a limit of 10 and a
// test built on 3 would be testing the clamp while claiming to test the limit. NOT `+` either —
// $cfg already carries the key, and `+` keeps the left operand's.
$favCfg = array_merge($cfg, ['fav_max_per_user' => '10']);
$favLimit = favMaxPerUser($favCfg);

check('nothing there to begin with', !favHas($db, $UID, $H1));
$r = favToggle($db, $favCfg, $UID, $H1);
check('a toggle adds it', $r['on'] === true && $r['changed'] === true && favHas($db, $UID, $H1));
$r = favToggle($db, $favCfg, $UID, $H1, true);
check('adding it again is a no-op, not an error', $r['on'] === true && $r['changed'] === false);
check('… and there is still exactly one row', favCountOf($db, $UID) === 1, (string)favCountOf($db, $UID));
// The unique key is what makes that true without a read-modify-write, so prove the key and not the
// PHP: a raw second INSERT must not produce a second row either.
$db->prepare("INSERT IGNORE INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$UID, $H1]);
check('uq_fav_once refuses the duplicate at the database level', favCountOf($db, $UID) === 1, (string)favCountOf($db, $UID));

$r = favToggle($db, $favCfg, $UID, $H1);
check('a second toggle removes it', $r['on'] === false && !favHas($db, $UID, $H1));
$r = favToggle($db, $favCfg, $UID, $H1, false);
check('removing what is not there is a no-op, not an error', $r['on'] === false && $r['changed'] === false);

/* ── 3. the limit is a limit, and it says why ──────────────────────────────── */
check('the limit under test is the clamped one', $favLimit === 10, (string)$favLimit);
for ($i = 0; $i < $favLimit; $i++) favToggle($db, $favCfg, $UID, str_pad(dechex($i + 1), 40, 'a'), true);
check('the limit fits exactly', favCountOf($db, $UID) === $favLimit, (string)favCountOf($db, $UID));
$r = favToggle($db, $favCfg, $UID, $H2, true);
check('the one after it is refused with a reason, not a 500',
    ($r['error'] ?? '') === 'fav_limit' && (int)($r['limit'] ?? 0) === $favLimit, json_encode($r));
check('… and nothing was written', favCountOf($db, $UID) === $favLimit && !favHas($db, $UID, $H2));
check('the clamp holds either end', favMaxPerUser(['fav_max_per_user' => '1']) === 10
    && favMaxPerUser(['fav_max_per_user' => '999999']) === 5000
    && favMaxPerUser([]) === 500);

/* ── 4. one query for a page of results, never one per row ─────────────────── */
$kept = str_pad(dechex(1), 40, 'a');
$mark = favMarkFor($db, $UID, [$kept, $H2, 'nonsense']);
check('favMarkFor answers for the whole page', isset($mark[$kept]) && !isset($mark[$H2]), json_encode(array_keys($mark)));
check('… and ignores anything that is not a hash', count($mark) === 1, json_encode($mark));

/* ── 5. an orphaned favourite still lists ─────────────────────────────────── */
// The janitor prunes index_hashes. Deleting favourites along with it would quietly empty people's
// lists, so a hash with no catalogue row has to come back with name === null and be renderable.
$orphan = str_repeat('cd', 20);
$db->prepare("INSERT IGNORE INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$UID, $orphan]);
$rows = favRowsFor($db, [$orphan]);
check('a favourite whose catalogue row is gone still lists', count($rows) === 1 && $rows[0]['name'] === null, json_encode($rows));
check('… and it keeps the hash, which is all a magnet needs', $rows[0]['info_hash'] === $orphan);

/* ── 6. deleting an account takes everything with it ──────────────────────── */
// THIS TEST FAILED ON THE CODE THAT SHIPPED BEFORE IT: api/admin/user_delete.php listed its tables
// inline and hash_votes was not among them, so a deleted account's votes stayed in the table and
// went on counting towards every score they had touched.
$db->exec("DELETE FROM users WHERE username = 'favtest'");
$db->prepare("INSERT INTO users (username, email, pass_hash, status, email_verified) VALUES ('favtest', 'favtest@example.org', 'x', 'active', 1)")->execute();
$victim = (int)$db->lastInsertId();
$db->prepare("INSERT IGNORE INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$victim, $H1]);
$db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote) VALUES (?, 'user', ?, 1)")->execute([$H1, (string)$victim]);
$db->prepare("INSERT IGNORE INTO whitelist (info_hash, name, source, created_at, submitter_id, submitter_public) VALUES (?, 'favtest row', 'web', NOW(), ?, 1)")
   ->execute([$H2, $victim]);
userDeleteCascade($db, $victim);
$left = static function (string $sql, array $a) use ($db): int {
    $st = $db->prepare($sql); $st->execute($a); return (int)$st->fetchColumn();
};
check('the account is gone', $left("SELECT COUNT(*) FROM users WHERE id = ?", [$victim]) === 0);
check('its favourites are gone', $left("SELECT COUNT(*) FROM user_favourites WHERE user_id = ?", [$victim]) === 0);
check('its VOTES are gone — the bug this test exists for',
    $left("SELECT COUNT(*) FROM hash_votes WHERE voter_type = 'user' AND voter_key = ?", [(string)$victim]) === 0);
check('its submissions survive but stop being attributed',
    $left("SELECT COUNT(*) FROM whitelist WHERE info_hash = ?", [$H2]) === 1
    && $left("SELECT COUNT(*) FROM whitelist WHERE info_hash = ? AND submitter_id IS NULL AND submitter_public = 0", [$H2]) === 1);
$db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$H2]);

/* ── 7. the gates, and the order they are in ──────────────────────────────── */
$on = ['users_enabled' => '1', 'fav_enabled' => '1', 'fav_public_enabled' => '1', 'fav_who_enabled' => '1', 'profiles_enabled' => '1'];
check('favEnabled needs the user system', !favEnabled(['fav_enabled' => '1']));
check('a public list needs favourites first', !favPublicEnabled(['users_enabled' => '1', 'fav_public_enabled' => '1']));
check('"who has this" needs public lists first', !favWhoEnabled(array_merge($on, ['fav_public_enabled' => '0'])));
check('all three on means all three on', favEnabled($on) && favPublicEnabled($on) && favWhoEnabled($on));
check('profiles are their own switch', profilesEnabled($on) && !profilesEnabled(['users_enabled' => '1']));
// The uploads half is hidden where a submission cannot happen — and that is NOT the same as
// "whitelist mode": a blacklist install with the schedule on still takes submissions.
check('uploads need an open submission path', uploadsPossible(['tracker_mode' => 'whitelist']));
check('… which the schedule also opens', uploadsPossible(['tracker_mode' => 'blacklist', 'tracker_schedule_enabled' => '1']));
check('… and blacklist with no schedule closes', !uploadsPossible(['tracker_mode' => 'blacklist']));

/* ── 7b. a public list is CONSENT, so the grant is what counts ─────────────── */
//
// userEffectivePermissions() hands every registered permission to the system `admin` group. That is
// a rule about power: an administrator has to be able to work the site. It is NOT a statement that
// their favourites may be shown to strangers, and reading it as one would publish an administrator's
// list because of a rule about what they are allowed to fix.
//
// So both halves of the decision — the SQL one behind "who has this in favourites" and the PHP one
// behind a public profile — read the GRANT on the group row, and an administrator who wants to
// appear grants it to their group like anybody else. These checks pin exactly that, because the two
// halves disagreeing is invisible from either side on its own.
$adminGrp = userGroupBySlug($db, 'admin');
check('the admin group exists', is_array($adminGrp));
$adminId = (int)($adminGrp['id'] ?? 0);
$savedPerms = (string)($adminGrp['permissions'] ?? '{}');
$setPerms = function (array $perms) use ($db, $adminId) {
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([json_encode($perms), $adminId]);
};

$setPerms(['panel.access' => true]);   // an admin group WITHOUT the visibility grant
check('without the grant the admin group is not in the SQL list',
    !in_array($adminId, array_map('intval', userGroupIdsWithPermission($db, 'favourites.public')), true));

// A real account whose only group is that one. userCan() would say yes to everything for them.
$db->prepare("DELETE FROM users WHERE username = 'favperm'")->execute();
$mk = userCreate($db, $cfg, 'favperm', 'favperm@example.org', 'FavPerm123!', '127.0.0.1');
$favUid = (int)($mk['user']['id'] ?? $mk['id'] ?? 0);
check('a test account was made for this', $favUid > 0, json_encode($mk));
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$favUid]);
// granted_at spelled out: the column defaults to the SERVER clock and this connection may be on
// another one, which would hold the membership in the future and give the account no groups at all.
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) VALUES (?, ?, '2000-01-01 00:00:00')")
   ->execute([$favUid, $adminId]);
$db->prepare("UPDATE users SET status = 'active', email_verified = 1, fav_public = 1, fav_listed = 1 WHERE id = ?")
   ->execute([$favUid]);

// With the account system switched off both helpers answer from userLegacyDefault() and this
// section is about groups, so it asks with the switch on. The battery runs the user smoke first,
// and that suite deliberately leaves `users_enabled` at 0 on its way out.
$cfgU = array_merge($cfg, ['users_enabled' => '1']);
check('the ordinary check says yes to an administrator, as it always has',
    userIdHasPermission($db, $cfgU, $favUid, 'favourites.public'));
check('… and the visibility check says no, because nobody granted it',
    !userIdHasGrantedPermission($db, $cfgU, $favUid, 'favourites.public'));

$setPerms(['panel.access' => true, 'favourites.public' => true]);
check('granting it to the group is what changes the answer',
    userIdHasGrantedPermission($db, $cfgU, $favUid, 'favourites.public'));
check('… and the SQL list agrees, from the same stored JSON',
    in_array($adminId, array_map('intval', userGroupIdsWithPermission($db, 'favourites.public')), true));

// A group that holds nothing must still be excluded, or the helper has stopped deciding anything.
$guestGrp = userGroupBySlug($db, 'guest');
$guestPerms = json_decode((string)($guestGrp['permissions'] ?? '[]'), true);
if (is_array($guestPerms) && empty($guestPerms['favourites.public'])) {
    check('a group without the permission is still left out',
        !in_array((int)$guestGrp['id'], array_map('intval', userGroupIdsWithPermission($db, 'favourites.public')), true));
}
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([$savedPerms, $adminId]);
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$favUid]);

/* ── 8. the display status, in the order it must resolve ──────────────────── */
check('banned wins over everything', whitelistDisplayStatus(['banned' => 1, 'probe_status' => 'probing'], $cfg) === 'blocked');
check('a failed probe is refused', whitelistDisplayStatus(['banned' => 0, 'probe_status' => 'failed'], $cfg) === 'refused');
check('a running probe is waiting', whitelistDisplayStatus(['banned' => 0, 'probe_status' => 'probing'], $cfg) === 'waiting');
check('everything else is what the tracker serves', whitelistDisplayStatus(['banned' => 0], $cfg) === 'live');
// NOT "approved". Nobody approved it, and teaching a member that a person looked is a promise the
// code cannot keep.
$favSrc = (string)@file_get_contents($root . '/includes/favourites.php');
check('the code says out loud that `live` is not `approved`', str_contains($favSrc, 'IS NOT "approved"') || str_contains($favSrc, 'NOT "approved"'));

/* ── 9. the accesslist generator must never learn about visibility ────────── */
// The one rule that would turn a profile setting into a change in what the tracker serves.
$wlSrc = (string)@file_get_contents($root . '/includes/whitelist.php');
// Anchored on the query itself, so it cannot go green by matching nothing: rename or remove that
// SELECT and the first check fails, which is when somebody has to look at this rule again.
$gen = '';
if (preg_match('/SELECT info_hash FROM whitelist\s+WHERE banned = 0.*?LIMIT \d+/s', $wlSrc, $m)) $gen = $m[0];
check('the accesslist query is still there to be checked', $gen !== '');
check('… and it mentions neither submitter_public nor submitter_id',
    $gen !== '' && !str_contains($gen, 'submitter_public') && !str_contains($gen, 'submitter_id'));
// And the whole function around it, because a WHERE two lines further down would be just as bad.
$regen = '';
if (preg_match('/function whitelistRegenerate\(.*?\n\}/s', $wlSrc, $m)) $regen = $m[0];
check('whitelistRegenerate() is findable', $regen !== '');
check('… and nothing in it reads the visibility flag',
    $regen !== '' && !str_contains($regen, 'submitter_public'));

/* ── 10. the four permissions, granted and defaulted ──────────────────────── */
$perms = userPermissionList();
foreach (['favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public'] as $p) {
    check("$p is registered", isset($perms[$p]));
    // The opposite of rating.* and content.*: those work without accounts, so false would switch
    // them off; these do not exist without an account at all.
    check("$p is denied when the user system is off", userLegacyDefault($p) === false);
}
$preset = userGroupPresets()['member']['perms'] ?? [];
foreach (['favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public'] as $p) {
    check("the member preset offers $p", in_array($p, $preset, true));
}
// The moderator group computes every public permission; without this it would silently hold fewer
// than an ordinary member, which is the exact mistake the v24 comment records.
check('the seeded moderator group holds them too', str_contains($schemaSrc, '\\"favourites.use\\":true')
    && str_contains($schemaSrc, '\\"uploads.public\\":true'));

/* ── 11. the six settings, in all four places ─────────────────────────────── */
$catalogSrc = (string)@file_get_contents($root . '/includes/settings_catalog.php');
$saveSrc    = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl     = (string)@file_get_contents($root . '/templates/admin/settings.php');
$defaults   = trackerSchemaDefaultSettings();
foreach (['fav_enabled' => '0', 'fav_max_per_user' => '500', 'fav_public_enabled' => '0',
          'fav_who_enabled' => '0', 'profiles_enabled' => '0', 'wl_submitter_public' => '0'] as $key => $want) {
    check("$key ships as '$want'", ($defaults[$key] ?? null) === $want, var_export($defaults[$key] ?? null, true));
    check("$key is in the catalogue", str_contains($catalogSrc, "'$key'"));
    check("$key is in the save allow-list", str_contains($saveSrc, "'$key'"));
    check("$key has a control", str_contains($setTpl, 'name="' . $key . '"'));
}

/* ── 12. a hash withheld from a reader is not a hash they can search by (1.69.0) ─────────────── */
//
// Each list of a profile blanks a row's hash for a reader who may not have it (no `index.magnet`;
// on the uploads and a list, no banned row's either) — and each one used to MATCH the search against
// that hash first. Searching somebody's public favourites for "a", "b", … "f0f0a" and reading the count
// spelt a hidden hash out sixteen answers at a time. The rule the likes / ratings list was built with
// (includes/profilevotes.php): the hash half of a search only where the hash is shown. The name half
// stays for everybody. Run as requests, because the answer is the endpoint's, not a helper's.
$fpHashLive = 'f0f0a1' . str_repeat('0', 33) . '1';
$fpHashBan  = 'f0f0b2' . str_repeat('0', 33) . '2';
$fpTmp = [];
$fpClean = function () use ($db): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'favprobe\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'favprobe\\_%'");
    $db->exec("DELETE FROM whitelist WHERE info_hash LIKE 'f0f0%'");
};
$fpClean();
register_shutdown_function(function () use ($fpClean, &$fpTmp) { $fpClean(); foreach ($fpTmp as $f) @unlink($f); });
$fpGroup = function (string $slug, array $perms) use ($db): int {
    $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES (?, ?, '', '', 2, 0, 0, ?)")
       ->execute([$slug, $slug, json_encode(array_fill_keys($perms, true), JSON_UNESCAPED_SLASHES)]);
    return (int)$db->lastInsertId();
};
// A verified account in ONE group: the member group registration hands out carries index.magnet.
$fpUser = function (string $name, int $gid) use ($db, $cfg): int {
    $r = userCreate($db, array_merge($cfg, ['users_enabled' => '1']), $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?")->execute([$id]);
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
    // granted in the past, spelled out: see 7b on why NOW() of one connection is not the other's.
    userGrantGroup($db, $id, $gid, null, 'test', 'favourites_test', false, '2000-01-01 00:00:00');
    return $id;
};
$gOwner = $fpGroup('favprobe_owner', ['favourites.use', 'favourites.public', 'uploads.public', 'lists.use', 'lists.public',
                                      'favourites.view_others', 'index.view', 'whitelist.view']);
$gNoMag = $fpGroup('favprobe_nomag', ['favourites.view_others', 'index.view', 'whitelist.view']);
$gMag   = $fpGroup('favprobe_mag',   ['favourites.view_others', 'index.view', 'whitelist.view', 'index.magnet']);
$fpOwner = $fpUser('favprobe_owner', $gOwner);
$fpNoMag = $fpUser('favprobe_nomag', $gNoMag);
$fpMag   = $fpUser('favprobe_mag', $gMag);
check('§12: three accounts, each in one scratch group', $fpOwner > 0 && $fpNoMag > 0 && $fpMag > 0, "$fpOwner/$fpNoMag/$fpMag");
$db->prepare("UPDATE users SET fav_public = 1, lists_public = 1 WHERE id = ?")->execute([$fpOwner]);
$wlIns = $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, submitter_id, submitter_public, banned) VALUES (?, ?, 'web', NOW(), ?, 1, ?)");
$wlIns->execute([$fpHashLive, 'favprobe alpha', $fpOwner, 0]);
$wlIns->execute([$fpHashBan, 'favprobe beta', $fpOwner, 1]);
foreach ([$fpHashLive, $fpHashBan] as $h) $db->prepare("INSERT INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$fpOwner, $h]);
$db->prepare("INSERT INTO user_lists (user_id, name, slug, is_public) VALUES (?, 'probe list', 'probe-list', 1)")->execute([$fpOwner]);
$fpList = (int)$db->lastInsertId();
foreach ([$fpHashLive, $fpHashBan] as $h) $db->prepare("INSERT INTO user_list_items (list_id, info_hash) VALUES (?, ?)")->execute([$fpList, $h]);

$fpRunner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fp_runner_' . bin2hex(random_bytes(4)) . '.php';
$fpTmp[] = $fpRunner;
file_put_contents($fpRunner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_GET = $a["get"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/richtext.php", "includes/reputation.php", "includes/schedule.php", "includes/index.php",
          "includes/auth.php", "includes/mail.php", "includes/users.php", "includes/favourites.php", "includes/usermedia.php",
          "includes/profilebio.php", "includes/profilevotes.php", "includes/lists.php", "includes/people.php",
          "includes/audit.php", "includes/lang.php"] as $f) if (is_file($f)) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
$_SESSION["user_id"] = $a["uid"]; $_SESSION["user_login_time"] = time(); $_SESSION["csrf_token"] = "fp-child-token";
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
require $a["file"];
');
$fpCfg = ['users_enabled' => '1', 'fav_enabled' => '1', 'fav_public_enabled' => '1', 'profiles_enabled' => '1', 'lists_enabled' => '1',
          'lists_public_enabled' => '1', 'wl_submitter_public' => '1', 'tracker_mode' => 'whitelist', 'index_search_include_whitelist' => '1',
          'users_require_email_verify' => '1'];
// $cfg: settings for THIS request on top of $fpCfg, in the child's memory only — the table is never
// written. An argument, not a variable the closure reads: `use ($fpCfg)` takes a COPY when the closure is
// made, so keys added to $fpCfg further down never reached a request, and the catalogue cases ran on
// whatever the table held (the battery's bootstrap leaves index_enabled at 0: "search_disabled").
$fpGet = function (string $file, int $uid, array $get, array $cfg = []) use ($root, $fpRunner, $fpCfg, &$fpTmp): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fp_args_' . bin2hex(random_bytes(4)) . '.json';
    $fpTmp[] = $arg;
    // files=0: the file-name half is its own feature (and charged to a rate bucket); this is about the hash.
    file_put_contents($arg, json_encode(['root' => $root, 'file' => $root . '/' . $file, 'get' => $get + ['files' => '0'], 'uid' => $uid,
        'cfg' => array_merge($fpCfg, $cfg), 'sid' => 'fptest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($fpRunner) . ' ' . escapeshellarg($arg) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 300)];
};
$hashes = fn(array $j): array => array_map(fn($r) => $r['info_hash'] ?? null, $j['rows'] ?? []);
$pfxLive = substr($fpHashLive, 0, 8);
$pfxBan  = substr($fpHashBan, 0, 8);

// Favourites: the hash is shown on your own list, and on somebody else's with index.magnet.
$j = $fpGet('api/user_favourites.php', $fpNoMag, ['user' => 'favprobe_owner', 'search' => $pfxLive]);
check('favourites, a stranger WITHOUT index.magnet, searching a hash prefix: nothing matches — the bug this is for',
      !empty($j['success']) && ($j['total'] ?? -1) === 0, json_encode($j));
$j = $fpGet('api/user_favourites.php', $fpNoMag, ['user' => 'favprobe_owner', 'search' => 'probe alpha']);
check('… the same stranger searching the NAME finds the row, without its hash',
      ($j['total'] ?? -1) === 1 && $hashes($j) === [null], json_encode($j));
$j = $fpGet('api/user_favourites.php', $fpMag, ['user' => 'favprobe_owner', 'search' => $pfxLive]);
check('… a stranger WITH index.magnet finds it by the prefix, hash and all',
      ($j['total'] ?? -1) === 1 && $hashes($j) === [$fpHashLive], json_encode($j));
$j = $fpGet('api/user_favourites.php', $fpOwner, ['search' => $pfxLive]);
check('… and so does the owner on their own list, where the hash is always shown (no index.magnet in their group)',
      ($j['total'] ?? -1) === 1 && $hashes($j) === [$fpHashLive], json_encode($j));

// Uploads: the hash goes out with index.magnet and never for a banned row.
$j = $fpGet('api/user_uploads.php', $fpNoMag, ['user' => 'favprobe_owner', 'search' => $pfxLive]);
check('uploads, a stranger without index.magnet, a hash prefix: nothing matches',
      !empty($j['success']) && ($j['total'] ?? -1) === 0, json_encode($j));
$j = $fpGet('api/user_uploads.php', $fpNoMag, ['user' => 'favprobe_owner', 'search' => 'favprobe']);
check('… by name: both rows, neither with a hash', ($j['total'] ?? -1) === 2 && $hashes($j) === [null, null], json_encode($j));
$j = $fpGet('api/user_uploads.php', $fpMag, ['user' => 'favprobe_owner', 'search' => $pfxLive]);
check('… with index.magnet the prefix finds the live row, hash and all', ($j['total'] ?? -1) === 1 && $hashes($j) === [$fpHashLive], json_encode($j));
$j = $fpGet('api/user_uploads.php', $fpMag, ['user' => 'favprobe_owner', 'search' => $pfxBan]);
check('… but not the BANNED row, whose hash nobody is shown', ($j['total'] ?? -1) === 0, json_encode($j));
$j = $fpGet('api/user_uploads.php', $fpMag, ['user' => 'favprobe_owner', 'search' => '%']);
check('… and a "%" is not a wildcard in the hash half (it matched every row)', ($j['total'] ?? -1) === 0, json_encode($j));

// A list: the hash goes out with index.magnet and never for a banned row (a stranger gets no banned row).
$mid = substr($fpHashLive, 2, 8);
$j = $fpGet('api/user_list_items.php', $fpNoMag, ['list' => (string)$fpList, 'search' => $mid]);
check('a list, a stranger without index.magnet, a piece of the hash: nothing matches',
      !empty($j['success']) && ($j['total'] ?? -1) === 0, json_encode($j));
$j = $fpGet('api/user_list_items.php', $fpNoMag, ['list' => (string)$fpList, 'search' => 'probe alpha']);
check('… by name: the row, without its hash', ($j['total'] ?? -1) === 1 && $hashes($j) === [null], json_encode($j));
$j = $fpGet('api/user_list_items.php', $fpMag, ['list' => (string)$fpList, 'search' => $mid]);
check('… with index.magnet the piece of the hash finds it', ($j['total'] ?? -1) === 1 && $hashes($j) === [$fpHashLive], json_encode($j));
$j = $fpGet('api/user_list_items.php', $fpOwner, ['list' => (string)$fpList, 'search' => substr($fpHashBan, 2, 8)]);
check('… and the owner without index.magnet does not find their own banned row by its (withheld) hash',
      !empty($j['success']) && ($j['total'] ?? -1) === 0, json_encode($j));

// The catalogue search, which also leaves the hash out of its rows without index.magnet — and matched a
// hash prefix first all the same. Everything api/index_search.php reads before it answers is set here, for
// these two requests: the accounts switch, the index and the search switches in front of it, the address
// verification gate on the reader's groups (both readers are verified), the whitelist arm the registered
// row lives in, and the hourly search limit — a FILE (config/rate_limits.json) the running site and every
// browser check's search from 127.0.0.1 charge too; 0 = no limit, and nothing is charged. The permissions
// are the scratch groups': index.view (the gate), whitelist.view (the arm), index.magnet (the case), no
// index.files. Nothing to restore: none of it is written to the table.
$fpSearchCfg = ['users_enabled' => '1', 'index_enabled' => '1', 'index_search_enabled' => '1', 'users_require_email_verify' => '1',
                'index_search_include_whitelist' => '1', 'rate_limit_index_search' => '0'];
$j = $fpGet('api/index_search.php', $fpNoMag, ['search' => substr($fpHashLive, 0, 6)], $fpSearchCfg);
check('the catalogue search, a reader without index.magnet, a hash prefix: searched as a name, nothing matches',
      !empty($j['success']) && ($j['total'] ?? -1) === 0, json_encode($j));
$j = $fpGet('api/index_search.php', $fpMag, ['search' => substr($fpHashLive, 0, 6)], $fpSearchCfg);
check('… with index.magnet the prefix still finds the registered row, hash and all',
      ($j['total'] ?? -1) === 1 && ($j['rows'][0]['info_hash'] ?? '') === $fpHashLive, json_encode($j));

$db->exec("DELETE FROM user_favourites WHERE user_id > 900000");
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
