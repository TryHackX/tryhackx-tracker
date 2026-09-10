<?php
/**
 * Lists — the schema, the five gates, the slug and the cascade (needs the local test database):
 *   php tests/lists_test.php
 *
 * ── the one thing this file exists for ─────────────────────────────────────────────────────────
 * A list is visible to a stranger only when every one of five separate answers says yes, and four
 * of them belong to different people: the operator's master switch, the operator's public switch,
 * the owner's group, the owner's section flag, and the owner's per-list flag. Any single no has to
 * take the list off every page — the profile AND the "who has this" overlay, which decide it in two
 * different languages (PHP and SQL). So the table below is walked one flag at a time against the
 * real query, not against a grep.
 *
 * The HTTP side (creating, adding by magnet, the picker) is driven by a real browser in
 * scratchpad/shots/lists_check.js — userCan() answers true for any panel session, so nothing in a
 * CLI test could prove the permission half of it.
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

/* ── 1. the schema, in both the fresh path and the upgrade path ───────────── */
check('schema is at least 51', (int)($cfg['schema_version'] ?? 0) >= 51, (string)($cfg['schema_version'] ?? 'none'));
foreach (['user_lists', 'user_list_items'] as $t) {
    check("$t exists", count($db->query("SHOW TABLES LIKE '$t'")->fetchAll()) === 1);
}
$stmts = implode("\n", trackerSchemaStatements($db));
check('both tables are in the statement list, not only in the migration',
    str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `user_lists`')
    && str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `user_list_items`'));
check('users.lists_public is in the CREATE too', str_contains($stmts, '`lists_public`'));
$idx = array_column($db->query("SHOW INDEX FROM user_list_items")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
check('adding the same hash twice cannot make a longer list', in_array('uq_list_item', $idx, true), implode(',', array_unique($idx)));
check('the "which lists is this hash on" index exists', in_array('idx_item_hash', $idx, true), implode(',', array_unique($idx)));

/* ── 2. the gates, and the order they are in ──────────────────────────────── */
$on = ['users_enabled' => '1', 'lists_enabled' => '1', 'lists_public_enabled' => '1'];
check('listsEnabled needs the user system', !listsEnabled(['lists_enabled' => '1']));
check('a public list needs lists first', !listsPublicEnabled(['users_enabled' => '1', 'lists_public_enabled' => '1']));
check('both on means both on', listsEnabled($on) && listsPublicEnabled($on));
check('the per-user ceiling is clamped, never trusted',
    listsMaxPerUser(['lists_max_per_user' => '99999']) === 200 && listsMaxPerUser(['lists_max_per_user' => '0']) === 20);
check('and so is the per-list one',
    listsMaxItems(['lists_max_items' => '99999']) === 5000 && listsMaxItems(['lists_max_items' => '1']) === 10);

/* ── 3. the slug is derived once and then left alone ──────────────────────── */
check('a name becomes an address', listSlugify('My Films 2026!') === 'my-films-2026', listSlugify('My Films 2026!'));
check('… and a name with nothing usable in it still becomes one', listSlugify('!!!') === 'list', listSlugify('!!!'));
$listSrc = (string)@file_get_contents($root . '/api/user_lists.php');
$renamePos = strpos($listSrc, '$op === ' . chr(39) . 'rename' . chr(39));
$renameSeg = $renamePos === false ? '' : substr($listSrc, $renamePos, 700);
check('renaming does NOT rebuild the slug — a link somebody was sent keeps working',
    $renameSeg !== '' && str_contains($renameSeg, 'UPDATE user_lists SET name = ?')
    && !str_contains($renameSeg, 'listUniqueSlug'), substr($renameSeg, 0, 120));

/* ── 4. THE TRUTH TABLE: five answers, and any single no hides the list ───── */
$db->prepare("DELETE FROM users WHERE username IN ('listowner')")->execute();
$mk = userCreate($db, $cfg, 'listowner', 'listowner@example.org', 'ListOwner123!', '127.0.0.1');
$uid = (int)($mk['user']['id'] ?? $mk['id'] ?? 0);
check('a test account was made for this', $uid > 0, json_encode($mk));
$db->prepare("UPDATE users SET status = 'active', email_verified = 1, lists_public = 1 WHERE id = ?")->execute([$uid]);

// A group that grants exactly what a list needs, and nothing else.
$db->prepare("DELETE FROM user_groups WHERE slug = 'listtest'")->execute();
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions)
              VALUES ('listtest', 'List test', 'fixture', 5, 0, 0, ?)")
   ->execute([json_encode(['lists.use' => true, 'lists.public' => true, 'favourites.view_others' => true])]);
$gid = (int)$db->lastInsertId();
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uid]);
// granted_at spelled out: this client and the application may be on different clocks, and a row
// stamped into the future gives the account no groups at all.
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) VALUES (?, ?, '2000-01-01 00:00:00')")
   ->execute([$uid, $gid]);

$HASH = str_repeat('5c', 20);
$db->prepare("DELETE FROM user_lists WHERE user_id = ?")->execute([$uid]);
$db->prepare("INSERT INTO user_lists (user_id, name, slug, is_public) VALUES (?, 'Public pack', 'public-pack', 1)")->execute([$uid]);
$lid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, 'Fixture')")->execute([$lid, $HASH]);

$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'lists_enabled' => '1', 'lists_public_enabled' => '1']);
$owner = userFindById($db, $uid);
$named = static fn(array $rows) => implode(',', array_column($rows, 'name'));

check('with every answer yes, the list is on the hash', $named(listsContainingHash($db, $cfgOn, $HASH)) === 'Public pack',
    $named(listsContainingHash($db, $cfgOn, $HASH)));
check('… and the profile would show the section', listsVisibleFor($db, $cfgOn, $owner));

// (1) the master switch
check('lists off → the hash is on nothing',
    listsContainingHash($db, array_merge($cfgOn, ['lists_enabled' => '0']), $HASH) === []);
// (2) the site-wide public switch
check('public lists off → the hash is on nothing',
    listsContainingHash($db, array_merge($cfgOn, ['lists_public_enabled' => '0']), $HASH) === []);
check('… and the profile section goes with it',
    !listsVisibleFor($db, array_merge($cfgOn, ['lists_public_enabled' => '0']), $owner));
// (3) the owner's group
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")
   ->execute([json_encode(['lists.use' => true]), $gid]);
check('the group losing lists.public takes the list off the hash', listsContainingHash($db, $cfgOn, $HASH) === []);
check('… and off the profile', !listsVisibleFor($db, $cfgOn, $owner));
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")
   ->execute([json_encode(['lists.use' => true, 'lists.public' => true, 'favourites.view_others' => true]), $gid]);
// (4) the owner's section flag
$db->prepare("UPDATE users SET lists_public = 0 WHERE id = ?")->execute([$uid]);
check('the owner hiding the section takes the list off the hash', listsContainingHash($db, $cfgOn, $HASH) === []);
$db->prepare("UPDATE users SET lists_public = 1 WHERE id = ?")->execute([$uid]);
// (5) the list's own flag
$db->prepare("UPDATE user_lists SET is_public = 0 WHERE id = ?")->execute([$lid]);
check('a private list is not on the hash either', listsContainingHash($db, $cfgOn, $HASH) === []);
$db->prepare("UPDATE user_lists SET is_public = 1 WHERE id = ?")->execute([$lid]);
check('and putting the last answer back puts it there again',
    $named(listsContainingHash($db, $cfgOn, $HASH)) === 'Public pack');
// a suspended account is nobody's public list
$db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$uid]);
check('a suspended account publishes nothing', listsContainingHash($db, $cfgOn, $HASH) === []);
$db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$uid]);

/* ── 5. a name that outlives the catalogue ────────────────────────────────── */
$rows = [['info_hash' => $HASH, 'name' => 'Recorded when added', 'added_at' => '2026-01-01 00:00:00']];
$meta = listItemsWithMeta($db, $rows);
check('a hash the catalogue never knew keeps the name it was added with',
    ($meta[0]['name'] ?? '') === 'Recorded when added' && ($meta[0]['gone'] ?? false) === true, json_encode($meta[0] ?? []));

/* ── 6. deleting the account takes the lists AND their rows ───────────────── */
$gone = userDeleteCascade($db, $uid);
$left = function (string $sql, array $args) use ($db): int {
    $st = $db->prepare($sql); $st->execute($args); return (int)$st->fetchColumn();
};
check('the account is gone', $left("SELECT COUNT(*) FROM users WHERE id = ?", [$uid]) === 0);
check('its lists are gone', $left("SELECT COUNT(*) FROM user_lists WHERE user_id = ?", [$uid]) === 0);
check('and the rows inside them, which are keyed by list and not by user',
    $left("SELECT COUNT(*) FROM user_list_items WHERE list_id = ?", [$lid]) === 0, json_encode($gone));
$db->prepare("DELETE FROM user_groups WHERE id = ?")->execute([$gid]);

/* ── 7. the settings exist in every place a setting has to exist ──────────── */
$defaults = trackerSchemaDefaultSettings();
foreach (['lists_enabled' => '0', 'lists_public_enabled' => '0'] as $k => $v) {
    check("$k ships as '$v'", ($defaults[$k] ?? null) === $v, var_export($defaults[$k] ?? null, true));
}
$catalogue = function_exists('settingsSearchWords') ? settingsSearchWords() : [];
$saveSrc = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl  = (string)@file_get_contents($root . '/templates/admin/settings.php');
foreach (['lists_enabled', 'lists_public_enabled', 'lists_max_per_user', 'lists_max_items'] as $k) {
    check("$k is in the save allow-list", str_contains($saveSrc, "'$k'"));
    check("$k has a control on the Settings page", str_contains($setTpl, "name=\"$k\""));
}

/* ── 8. the endpoints refuse everything while the feature is off ──────────── */
foreach (['api/user_lists.php', 'api/user_list_items.php'] as $f) {
    $src = (string)@file_get_contents($root . '/' . $f);
    check(basename($f) . ' answers 404 while lists are off', str_contains($src, "if (!listsEnabled(\$cfg)) jsonResponse"));
    check(basename($f) . ' checks the CSRF token before it writes', str_contains($src, 'verifyCsrfToken'));
    check(basename($f) . ' scopes every write to the owner', str_contains($src, 'user_id = ?'));
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
