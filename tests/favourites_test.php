<?php
/**
 * Favourites, public profiles and "my torrents" (needs the local test database):
 *   php tests/favourites_test.php
 *
 * The permission side is proved over HTTP with a real member session in deploy/smoke_users.py —
 * userCan() returns true for any panel session, so nothing in a CLI test could prove it. What is
 * proved here is everything that does not need a session: the schema, the toggle's idempotence, the
 * limit, the cascade, and the truth table the "who has this in favourites" query has to satisfy.
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

$db->exec("DELETE FROM user_favourites WHERE user_id > 900000");
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
