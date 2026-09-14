<?php
/**
 * The list/favourites audit fixes:
 *   php tests/audit_lists_test.php
 *
 * Runs against the local test database (deploy/local_bootstrap.php first). Everything it needs it
 * makes: four accounts, one list, two whitelist rows, one block — and it puts the settings and the
 * member group's permissions back on its way out, because this database is shared with every other
 * suite.
 *
 * ── why some checks run the endpoint in a child process ────────────────────────────────────────
 *
 * jsonResponse() ends with exit(). A test that called an endpoint in its own process would end with
 * it, so the endpoint runs in a process of its own and its JSON comes back over stdout. That is not
 * a mock: it is the same file api.php includes, with the same session, reading the same database.
 * What it cannot see is the HTTP layer — status codes, the rate limiter's headers — and that half is
 * tests/audit_lists_test.py.
 *
 * Covered here: a hidden profile closes the endpoints behind it and not only the page; publishing
 * asks the GRANT and never the administrator's blanket; a reader without `index.magnet` gets rows
 * with no hash and a row id they can still remove by; favRowsFor() obeys `whitelist.view` and hides
 * banned rows from strangers; a membership that starts tomorrow does not publish today; and lists
 * answer 404 while profiles are off.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/lists.php';
require_once $root . '/includes/people.php';

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

/* ── the child that runs one endpoint ──────────────────────────────────────── */

$epFile = sys_get_temp_dir() . '/audit_lists_ep_' . getmypid() . '.php';
$argsFile = sys_get_temp_dir() . '/audit_lists_args_' . getmypid() . '.json';
file_put_contents($epFile, <<<'PHP'
<?php
// One endpoint, as one signed-in account, with its reply on stdout. Written by
// tests/audit_lists_test.php and deleted by it.
$root = $argv[1];
$in = json_decode((string)file_get_contents($argv[2]), true);
foreach (['/config/app.php', '/config/database.php', '/includes/settings.php', '/includes/functions.php',
          '/includes/schema.php', '/includes/whitelist.php', '/includes/lang.php', '/includes/index.php',
          '/includes/mail.php', '/includes/users.php', '/includes/favourites.php', '/includes/lists.php',
          '/includes/people.php'] as $f) {
    require_once $root . $f;
}
session_start();
// The session the endpoint will read. `user_login_time` is in the future so the
// sessions_valid_from comparison in currentUser() can never end this one mid-test.
$_SESSION['user_id'] = (int)$in['uid'];
$_SESSION['user_login_time'] = time() + 60;
$_SESSION['csrf_token'] = 'audit-token';
$_SERVER['REQUEST_METHOD'] = $in['method'] ?? 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = $in['get'] ?? [];
$_POST = $in['post'] ?? [];       // readJsonBody() falls back to $_POST when php://input is empty
$db = getDb();
$cfg = getSettings($db, true);
if (function_exists('langInit')) langInit($cfg, null);
include $root . '/api/' . $in['ep'] . '.php';
PHP);

/** Run one endpoint as $uid and decode its answer. */
function endpoint(string $ep, int $uid, array $get = [], array $post = [], string $method = 'GET'): array {
    global $root, $epFile, $argsFile;
    if ($method === 'POST') $post['csrf_token'] = 'audit-token';
    file_put_contents($argsFile, json_encode(['uid' => $uid, 'ep' => $ep, 'get' => $get,
                                              'post' => $post, 'method' => $method]));
    $cmd = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=24575',
            $epFile, $root, $argsFile];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return ['_raw' => 'could not start php'];
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    foreach ($pipes as $h) fclose($h);
    proc_close($p);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => trim($out . ' ' . $err)];
}

/* ── the fixtures ──────────────────────────────────────────────────────────── */

$SETTINGS = ['users_enabled' => '1', 'profiles_enabled' => '1', 'index_enabled' => '1',
             'fav_enabled' => '1', 'fav_public_enabled' => '1', 'fav_who_enabled' => '1',
             'lists_enabled' => '1', 'lists_public_enabled' => '1',
             'index_search_include_whitelist' => '1'];
$wasSettings = [];
foreach ($SETTINGS as $k => $v) $wasSettings[$k] = (string)($cfg[$k] ?? '');
setSettings($db, $SETTINGS);
$cfg = getSettings($db, true);

$memberGroup = userGroupBySlug($db, 'member');
$adminGroup = userGroupBySlug($db, 'admin');
$wasMemberPerms = (string)($memberGroup['permissions'] ?? '{}');
// Everything this suite reads is granted here, and the group's own JSON goes back at the end.
$db->prepare("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, ?) WHERE id = ?")
   ->execute([json_encode(['lists.use' => true, 'lists.public' => true, 'favourites.use' => true,
                           'favourites.public' => true, 'favourites.view_others' => true,
                           'index.view' => true, 'index.files' => true, 'index.magnet' => true,
                           'whitelist.view' => true]), (int)$memberGroup['id']]);

$NAMES = ['auditowner', 'auditpeer', 'auditblock', 'auditadmin'];
$uids = [];
$WL_OK = str_repeat('a1', 20);
$WL_BAN = str_repeat('b2', 20);
$listId = 0;

/** Everything this suite wrote, in the order the keys allow. */
$cleanup = function () use ($db, $NAMES, $WL_OK, $WL_BAN, &$uids, $epFile, $argsFile,
                            $wasSettings, $wasMemberPerms, $memberGroup) {
    foreach ($uids as $id) {
        $db->prepare("DELETE i FROM user_list_items i JOIN user_lists l ON l.id = i.list_id WHERE l.user_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_lists WHERE user_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_favourites WHERE user_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_blocks WHERE user_id = ? OR blocked_id = ?")->execute([$id, $id]);
        $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_notifications WHERE user_id = ?")->execute([$id]);
    }
    foreach ($NAMES as $u) $db->prepare("DELETE FROM users WHERE username = ?")->execute([$u]);
    $db->prepare("DELETE FROM whitelist WHERE info_hash IN (?, ?)")->execute([$WL_OK, $WL_BAN]);
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([$wasMemberPerms, (int)$memberGroup['id']]);
    foreach ($wasSettings as $k => $v) { if ($v !== '') setSetting($db, $k, $v); }
    @unlink($epFile);
    @unlink($argsFile);
};

try {
    foreach ($NAMES as $u) $db->prepare("DELETE FROM users WHERE username = ?")->execute([$u]);
    foreach ($NAMES as $u) {
        $r = userCreate($db, $cfg, $u, $u . '@example.org', 'SmokePass123!', '127.0.0.1');
        $uids[$u] = (int)($r['user']['id'] ?? 0);
    }
    $db->prepare("UPDATE users SET email_verified = 1, fav_public = 1, fav_listed = 1, lists_public = 1
                   WHERE username IN ('auditowner', 'auditpeer', 'auditblock', 'auditadmin')")->execute();
    check('the four accounts exist', count(array_filter($uids)) === 4, json_encode($uids));

    // `auditadmin` is in the system admin group and NOTHING else: the blanket, without a single
    // group that GRANTS what the blanket covers. That is the account the L2 checks are about.
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uids['auditadmin']]);
    userGrantGroup($db, $uids['auditadmin'], (int)$adminGroup['id'], null, 'audit test', '', false);

    // Two whitelist rows: one the tracker serves, one it refuses.
    $db->prepare("DELETE FROM whitelist WHERE info_hash IN (?, ?)")->execute([$WL_OK, $WL_BAN]);
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned) VALUES (?, 'Audit fixture OK', 'web', NOW(), 0)")->execute([$WL_OK]);
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned) VALUES (?, 'Audit fixture BANNED', 'web', NOW(), 1)")->execute([$WL_BAN]);

    // One public list with both rows in it.
    $db->prepare("INSERT INTO user_lists (user_id, name, slug, description, is_public) VALUES (?, 'Audit pack', 'audit-pack', '', 1)")
       ->execute([$uids['auditowner']]);
    $listId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, 'Audit fixture OK')")->execute([$listId, $WL_OK]);
    $db->prepare("INSERT IGNORE INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$uids['auditowner'], $WL_OK]);

    /* ── 1. a hidden profile closes what the page reads, not only the page ──── */
    // `auditowner` blocks `auditblock` and asks for the profile to be hidden from them.
    $db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)
                  ON DUPLICATE KEY UPDATE hide_profile = 1")->execute([$uids['auditowner'], $uids['auditblock']]);
    check('the block hides the profile from that one reader',
        profileHiddenFrom($db, $uids['auditowner'], $uids['auditblock']));
    check('… and from nobody else', !profileHiddenFrom($db, $uids['auditowner'], $uids['auditpeer']));

    $j = endpoint('user_favourites', $uids['auditblock'], ['user' => 'auditowner']);
    check('favourites: the blocked reader gets the same not-found as a name nobody has',
        ($j['error'] ?? '') === 'not_found', json_encode($j));
    $j = endpoint('user_favourites', $uids['auditpeer'], ['user' => 'auditowner']);
    check('… and a stranger still reads the list', !empty($j['success']), json_encode($j));

    $j = endpoint('user_lists', $uids['auditblock'], ['user' => 'auditowner']);
    check('lists: the blocked reader gets not-found', ($j['error'] ?? '') === 'not_found', json_encode($j));
    $j = endpoint('user_lists', $uids['auditpeer'], ['user' => 'auditowner']);
    check('… and a stranger sees the public one', !empty($j['success']) && count($j['lists'] ?? []) === 1, json_encode($j));

    $j = endpoint('user_list_items', $uids['auditblock'], ['list' => $listId]);
    check('the rows of that list: not-found for the blocked reader', ($j['error'] ?? '') === 'not_found', json_encode($j));
    $j = endpoint('user_list_items', $uids['auditpeer'], ['list' => $listId]);
    check('… and readable by a stranger', !empty($j['success']), json_encode($j));

    check('"who has this" drops the list for the reader it is hidden from',
        listsContainingHash($db, $cfg, $WL_OK, 20, $uids['auditblock']) === []);
    $rows = listsContainingHash($db, $cfg, $WL_OK, 20, $uids['auditpeer']);
    check('… and keeps it for everybody else', count($rows) === 1 && ($rows[0]['name'] ?? '') === 'Audit pack', json_encode($rows));

    /* ── 2. publishing asks the grant, never the blanket ────────────────────── */
    check('the administrator holds the permission through the blanket',
        userIdHasPermission($db, $cfg, $uids['auditadmin'], 'lists.public'));
    check('… and does not hold the GRANT that publishing asks for',
        !userIdHasGrantedPermission($db, $cfg, $uids['auditadmin'], 'lists.public'));
    $adminRow = ['id' => $uids['auditadmin'], 'lists_public' => 1];
    check('… so their list would not be visible on their profile either',
        !listsVisibleFor($db, $cfg, $adminRow));

    $db->prepare("INSERT INTO user_lists (user_id, name, slug, description, is_public) VALUES (?, 'Blanket pack', 'blanket-pack', '', 0)")
       ->execute([$uids['auditadmin']]);
    $adminList = (int)$db->lastInsertId();
    $j = endpoint('user_lists', $uids['auditadmin'], [], ['op' => 'visibility', 'id' => $adminList, 'value' => 1], 'POST');
    check('publishing a list on the blanket alone is refused', ($j['error'] ?? '') === 'no_permission', json_encode($j));
    $st = $db->prepare("SELECT is_public FROM user_lists WHERE id = ?");
    $st->execute([$adminList]);
    check('… and nothing was written', (int)$st->fetchColumn() === 0);
    $j = endpoint('user_lists', $uids['auditadmin']);
    check('… and the shelf does not offer the control', ($j['may_publish'] ?? null) === false, json_encode($j['may_publish'] ?? null));
    $j = endpoint('user_privacy', $uids['auditadmin']);
    check('the privacy page says the same about both features',
        ($j['may_publish'] ?? null) === false && ($j['lists_may_publish'] ?? null) === false, json_encode($j));
    $j = endpoint('user_privacy', $uids['auditowner']);
    check('… and says yes to the account whose group really grants it',
        ($j['may_publish'] ?? null) === true && ($j['lists_may_publish'] ?? null) === true, json_encode($j));

    /* ── 3. a reader without index.magnet: no hash, but a row id ────────────── */
    $j = endpoint('user_list_items', $uids['auditowner'], ['list' => $listId]);
    check('with the permission the row carries its hash',
        ($j['rows'][0]['info_hash'] ?? null) === $WL_OK, json_encode($j['rows'][0] ?? []));
    $itemId = (int)($j['rows'][0]['id'] ?? 0);
    check('… and its own id, which is what names it without one', $itemId > 0, (string)$itemId);

    $db->prepare("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, ?) WHERE id = ?")
       ->execute([json_encode(['index.magnet' => false]), (int)$memberGroup['id']]);
    $j = endpoint('user_list_items', $uids['auditowner'], ['list' => $listId]);
    $row0 = $j['rows'][0] ?? [];
    check('without it the hash is withheld',
        array_key_exists('info_hash', $row0) && $row0['info_hash'] === null, json_encode($row0));
    check('… and the id is still there', (int)($j['rows'][0]['id'] ?? 0) === $itemId, json_encode($j['rows'][0] ?? []));
    $j = endpoint('user_list_items', $uids['auditowner'], [], ['op' => 'remove', 'list' => $listId, 'id' => $itemId], 'POST');
    check('… so the owner can still take the row off their list', !empty($j['success']) && (int)($j['items'] ?? -1) === 0, json_encode($j));
    $st = $db->prepare("SELECT COUNT(*) FROM user_list_items WHERE id = ?");
    $st->execute([$itemId]);
    check('… and the row really is gone', (int)$st->fetchColumn() === 0);
    $db->prepare("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, ?) WHERE id = ?")
       ->execute([json_encode(['index.magnet' => true]), (int)$memberGroup['id']]);
    $db->prepare("INSERT INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, 'Audit fixture OK')")->execute([$listId, $WL_OK]);

    /* ── 4. the whitelist arm and banned rows ───────────────────────────────── */
    $r = favRowsFor($db, [$WL_OK], true, true);
    check('with whitelist.view the whitelist describes the row',
        ($r[0]['src'] ?? '') === 'whitelist' && ($r[0]['name'] ?? '') === 'Audit fixture OK', json_encode($r));
    $r = favRowsFor($db, [$WL_OK], false, true);
    check('without it the arm is skipped and the row says nothing',
        count($r) === 1 && $r[0]['name'] === null && $r[0]['src'] === null, json_encode($r));
    $r = favRowsFor($db, [$WL_BAN], true, true);
    check('the owner keeps a banned row, so they can remove it knowingly',
        count($r) === 1 && ($r[0]['banned'] ?? null) === true, json_encode($r));
    $r = favRowsFor($db, [$WL_BAN], true, false);
    check('a stranger does not get it at all — the count and the rows agree', $r === [], json_encode($r));

    check('listHashKnown answers for a whitelist row with the permission',
        listHashKnown($db, $WL_OK, true)['known'] === true);
    check('… and does not without it', listHashKnown($db, $WL_OK, false)['known'] === false);

    $meta = listItemsWithMeta($db, [['id' => 7, 'info_hash' => $WL_OK, 'name' => 'stored', 'added_at' => '2026-01-01 00:00:00']], true, true);
    check('a list row carries the id it can be removed by', (int)($meta[0]['id'] ?? 0) === 7, json_encode($meta));
    $meta = listItemsWithMeta($db, [['id' => 8, 'info_hash' => $WL_BAN, 'name' => 'stored', 'added_at' => '2026-01-01 00:00:00']], true, false);
    check('a banned row is not on a stranger\'s copy of the list', $meta === [], json_encode($meta));
    $meta = listItemsWithMeta($db, [['id' => 8, 'info_hash' => $WL_BAN, 'name' => 'stored', 'added_at' => '2026-01-01 00:00:00']], true, true);
    check('… and is on the owner\'s, with the flag on it', ($meta[0]['banned'] ?? null) === true, json_encode($meta));

    /* ── 5. a membership that has not started yet is not a membership ───────── */
    $tomorrow = date('Y-m-d H:i:s', time() + 86400);
    userGrantGroup($db, $uids['auditowner'], (int)$memberGroup['id'], null, 'audit test', '', false, $tomorrow);
    check('a grant that starts tomorrow does not publish today',
        listsContainingHash($db, $cfg, $WL_OK, 20, $uids['auditpeer']) === []);
    check('… and the grant itself reads as absent',
        !userIdHasGrantedPermission($db, $cfg, $uids['auditowner'], 'lists.public'));
    userGrantGroup($db, $uids['auditowner'], (int)$memberGroup['id'], null, 'audit test', '', false,
                   date('Y-m-d H:i:s', time() - 60));
    check('… and putting the date back puts the list back',
        count(listsContainingHash($db, $cfg, $WL_OK, 20, $uids['auditpeer'])) === 1);

    /* ── 6. with profiles off there is no page for a list to be on ──────────── */
    setSetting($db, 'profiles_enabled', '0');
    $j = endpoint('user_lists', $uids['auditpeer'], ['user' => 'auditowner']);
    check('profiles off: somebody else\'s shelf answers not-found', ($j['error'] ?? '') === 'not_found', json_encode($j));
    $j = endpoint('user_list_items', $uids['auditpeer'], ['list' => $listId]);
    check('… and so do the rows inside one', ($j['error'] ?? '') === 'not_found', json_encode($j));
    $j = endpoint('user_list_items', $uids['auditowner'], ['list' => $listId]);
    check('… while the owner still reads their own', !empty($j['success']), json_encode($j));
    setSetting($db, 'profiles_enabled', '1');
} finally {
    $cleanup();
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
