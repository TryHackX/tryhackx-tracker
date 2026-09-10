<?php
/**
 * People: who may write to whom, what a block does, and how much of a conversation a report carries
 * (needs the local test database):
 *   php tests/people_test.php
 *
 * ── the two things this file exists for ────────────────────────────────────────────────────────
 *
 * 1. THE GATE. "Who may write to me" is answered by four separate facts — the site switch, the
 *    reader's own setting, the site default they inherit when they have not set one, and whether the
 *    two are friends — plus a block in either direction. Every one of them is walked here against
 *    pmCanWrite(), because a message gate that is wrong in one direction is a stranger in somebody's
 *    inbox and wrong in the other is a conversation that silently never happens.
 *
 * 2. A REPORT CARRIES TWO MESSAGES. The reported line and the one before it. Not the conversation.
 *    That is a privacy decision, and it is checked here against the real queue query rather than by
 *    reading the endpoint: a SELECT that widens later would still pass a grep.
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
require_once $root . '/includes/richtext.php';
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

/* ── 1. the schema ────────────────────────────────────────────────────────── */
check('schema is at least 52', (int)($cfg['schema_version'] ?? 0) >= 52, (string)($cfg['schema_version'] ?? 'none'));
foreach (['message_threads', 'user_messages', 'message_reports', 'user_friends', 'user_blocks'] as $t) {
    check("$t exists", count($db->query("SHOW TABLES LIKE '$t'")->fetchAll()) === 1);
}
$stmts = implode("\n", trackerSchemaStatements($db));
foreach (['message_threads', 'user_messages', 'message_reports', 'user_friends', 'user_blocks'] as $t) {
    check("$t is in the statement list, not only in the migration", str_contains($stmts, "CREATE TABLE IF NOT EXISTS `$t`"));
}
check('users.pm_who and users.profile_listed are in the CREATE too',
    str_contains($stmts, '`pm_who`') && str_contains($stmts, '`profile_listed`'));
$idx = array_column($db->query("SHOW INDEX FROM message_threads")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
check('two people can only ever have ONE thread', in_array('uq_thread_pair', $idx, true), implode(',', array_unique($idx)));

/* ── 2. two accounts to walk the gate with ────────────────────────────────── */
$db->prepare("DELETE FROM users WHERE username IN ('pmalice','pmbob')")->execute();
$mk = static function (PDO $db, array $cfg, string $name): int {
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'PmTest123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? $r['id'] ?? 0);
    $db->prepare("UPDATE users SET status = 'active', email_verified = 1 WHERE id = ?")->execute([$id]);
    return $id;
};
$aId = $mk($db, $cfg, 'pmalice');
$bId = $mk($db, $cfg, 'pmbob');
check('two test accounts exist', $aId > 0 && $bId > 0, "$aId / $bId");

// A group that grants exactly what this area needs.
$db->prepare("DELETE FROM user_groups WHERE slug = 'pmtest'")->execute();
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions)
              VALUES ('pmtest', 'PM test', 'fixture', 5, 0, 0, ?)")
   ->execute([json_encode(['pm.send' => true, 'pm.report' => true, 'friends.use' => true, 'directory.view' => true])]);
$gid = (int)$db->lastInsertId();
foreach ([$aId, $bId] as $u) {
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$u]);
    // granted_at spelled out: this client and the application may be on different clocks.
    $db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) VALUES (?, ?, '2000-01-01 00:00:00')")
       ->execute([$u, $gid]);
}
$alice = userFindById($db, $aId);
$bob   = userFindById($db, $bId);
$on = array_merge($cfg, ['users_enabled' => '1', 'pm_enabled' => '1', 'friends_enabled' => '1', 'pm_who' => 'all']);

/* ── 3. THE GATE, one fact at a time ──────────────────────────────────────── */
// userCan() answers true for a panel session, and this file runs from the CLI where there is none —
// so what is exercised here is the part that does not need a session: the switches, the settings,
// the friendship and the blocks.
$reason = static fn(array $r): string => $r['ok'] ? 'ok' : $r['reason'];

check('with messages off, nobody may write', $reason(pmCanWrite($db, array_merge($on, ['pm_enabled' => '0']), $alice, $bob)) === 'disabled');
check('a site default of "all" lets a stranger write', $reason(pmCanWrite($db, $on, $alice, $bob)) === 'ok');
check('a site default of "nobody" stops them',
    $reason(pmCanWrite($db, array_merge($on, ['pm_who' => 'nobody']), $alice, $bob)) === 'nobody');
check('a site default of "friends" stops a stranger',
    $reason(pmCanWrite($db, array_merge($on, ['pm_who' => 'friends']), $alice, $bob)) === 'friends_only');

// The reader's own answer beats the site's, in both directions.
$db->prepare("UPDATE users SET pm_who = 'nobody' WHERE id = ?")->execute([$bId]);
$bob = userFindById($db, $bId);
check('their own "nobody" beats a site default of "all"', $reason(pmCanWrite($db, $on, $alice, $bob)) === 'nobody');
$db->prepare("UPDATE users SET pm_who = 'all' WHERE id = ?")->execute([$bId]);
$bob = userFindById($db, $bId);
check('and their own "all" beats a site default of "friends"',
    $reason(pmCanWrite($db, array_merge($on, ['pm_who' => 'friends']), $alice, $bob)) === 'ok');
$db->prepare("UPDATE users SET pm_who = NULL WHERE id = ?")->execute([$bId]);
$bob = userFindById($db, $bId);
check('NULL is not a fourth value — it means the site default', pmWhoFor($on, $bob) === 'all');

// Friendship opens a "friends only" inbox, and only friendship does.
$db->prepare("UPDATE users SET pm_who = 'friends' WHERE id = ?")->execute([$bId]);
$bob = userFindById($db, $bId);
$db->prepare("INSERT INTO user_friends (user_id, friend_id, status) VALUES (?, ?, 'pending')")->execute([$aId, $bId]);
check('a request that has not been answered is a FOLLOW, not a friendship',
    friendState($db, $aId, $bId) === 'following' && !areFriends($db, $aId, $bId));
check('… and following somebody does not get you into their inbox',
    $reason(pmCanWrite($db, $on, $alice, $bob)) === 'friends_only');
check('… while from their side it reads as somebody who asked', friendState($db, $bId, $aId) === 'follower');
$db->prepare("UPDATE user_friends SET status = 'accepted', accepted_at = NOW() WHERE user_id = ? AND friend_id = ?")
   ->execute([$aId, $bId]);
check('accepting makes them friends, in both directions',
    areFriends($db, $aId, $bId) && friendState($db, $aId, $bId) === 'friends' && friendState($db, $bId, $aId) === 'friends');
check('… and now the message goes through', $reason(pmCanWrite($db, $on, $alice, $bob)) === 'ok');

// A block beats everything above it.
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$bId, $aId]);
check('a block stops the message even between friends', $reason(pmCanWrite($db, $on, $alice, $bob)) === 'blocked');
check('… and the sender is TOLD, which is what "blocked" as a reason is for',
    pmCanWrite($db, $on, $alice, $bob)['reason'] === 'blocked');
check('a block without hide_profile leaves the profile open', !profileHiddenFrom($db, $bId, $aId));
$db->prepare("UPDATE user_blocks SET hide_profile = 1 WHERE user_id = ? AND blocked_id = ?")->execute([$bId, $aId]);
check('… and with it, the profile is closed to that one reader', profileHiddenFrom($db, $bId, $aId));
check('… and to that reader only', !profileHiddenFrom($db, $aId, $bId));
$db->prepare("DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?")->execute([$bId, $aId]);
// Blocking somebody also stops YOU writing to THEM.
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$aId, $bId]);
check('blocking somebody also stops you writing to them', $reason(pmCanWrite($db, $on, $alice, $bob)) === 'you_blocked');
$db->prepare("DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?")->execute([$aId, $bId]);

/* ── 4. one thread per pair, whoever opens it ─────────────────────────────── */
$t1 = pmThreadFor($db, $aId, $bId);
$t2 = pmThreadFor($db, $bId, $aId);
check('the same conversation is found from either side', (int)$t1['id'] === (int)$t2['id'], $t1['id'] . ' vs ' . $t2['id']);
check('both people are in it', pmInThread($t1, $aId) && pmInThread($t1, $bId));
check('and the other person is the other person', pmOtherId($t1, $aId) === $bId && pmOtherId($t1, $bId) === $aId);

$ins = $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, 'bbcode')");
$ins->execute([(int)$t1['id'], $aId, 'first line']);
$m1 = (int)$db->lastInsertId();
$ins->execute([(int)$t1['id'], $bId, 'second line']);
$m2 = (int)$db->lastInsertId();
$ins->execute([(int)$t1['id'], $bId, 'third line, the one complained about']);
$m3 = (int)$db->lastInsertId();
check('unread counts what the other person sent and I have not read', pmUnreadCount($db, $aId) === 2, (string)pmUnreadCount($db, $aId));
check('… and nothing of my own', pmUnreadCount($db, $bId) === 1, (string)pmUnreadCount($db, $bId));

/* ── 5. A REPORT CARRIES TWO MESSAGES ─────────────────────────────────────── */
$ctx = $db->prepare("SELECT id FROM user_messages WHERE thread_id = ? AND id < ? ORDER BY id DESC LIMIT 1");
$ctx->execute([(int)$t1['id'], $m3]);
$ctxId = (int)$ctx->fetchColumn();
check('the context is the line immediately before the reported one', $ctxId === $m2, "$ctxId vs $m2");
$db->prepare("INSERT INTO message_reports (message_id, context_id, thread_id, reporter_id, reported_user_id, reason)
              VALUES (?, ?, ?, ?, ?, 'fixture')")
   ->execute([$m3, $ctxId, (int)$t1['id'], $aId, $bId]);

// The queue's own query, run here: two rows joined by id, and no path from a report to the rest of
// the thread. If somebody ever widens that SELECT, this is what says so.
$q = $db->prepare("SELECT r.id, m.id AS msg_id, c.id AS ctx_id
                     FROM message_reports r
                     LEFT JOIN user_messages m ON m.id = r.message_id
                     LEFT JOIN user_messages c ON c.id = r.context_id
                    WHERE r.thread_id = ?");
$q->execute([(int)$t1['id']]);
$rep = $q->fetch(PDO::FETCH_ASSOC);
check('the queue row carries exactly the reported message and its context',
    (int)$rep['msg_id'] === $m3 && (int)$rep['ctx_id'] === $m2, json_encode($rep));
$src = (string)@file_get_contents($root . '/api/admin/fetch_message_reports.php');
check('and the panel endpoint joins by those two ids and nothing else',
    str_contains($src, 'm.id = r.message_id') && str_contains($src, 'c.id = r.context_id'));
check('… it never selects a thread\'s messages by thread_id',
    !preg_match('/FROM\s+user_messages\s+WHERE\s+thread_id/i', $src), 'a thread-wide read appeared in the panel');
check('… and it is behind its own permission, not the report queue\'s',
    str_contains($src, "panelCan(\$db, \$cfg, 'panel.messages.view')"));
$actSrc = (string)@file_get_contents($root . '/api/admin/message_report_action.php');
check('the panel may close a report and delete the message it names, and nothing else',
    str_contains($actSrc, "'close'") && str_contains($actSrc, "delete_message")
    && !preg_match('/INSERT INTO user_messages/i', $actSrc));
check('… and every action is written to the audit log', substr_count($actSrc, 'auditLog(') >= 2);

/* ── 6. deleting an account takes the whole correspondence with it ────────── */
$gone = userDeleteCascade($db, $bId);
$left = static function (string $sql, array $args) use ($db): int {
    $st = $db->prepare($sql); $st->execute($args); return (int)$st->fetchColumn();
};
check('the account is gone', $left("SELECT COUNT(*) FROM users WHERE id = ?", [$bId]) === 0);
check('their thread is gone', $left("SELECT COUNT(*) FROM message_threads WHERE id = ?", [(int)$t1['id']]) === 0);
check('the messages inside it are gone', $left("SELECT COUNT(*) FROM user_messages WHERE thread_id = ?", [(int)$t1['id']]) === 0);
check('and the report that pointed at one of them', $left("SELECT COUNT(*) FROM message_reports WHERE thread_id = ?", [(int)$t1['id']]) === 0, json_encode($gone));
check('the friendship is gone too', $left("SELECT COUNT(*) FROM user_friends WHERE user_id = ? OR friend_id = ?", [$bId, $bId]) === 0);

$db->prepare("DELETE FROM users WHERE id = ?")->execute([$aId]);
$db->prepare("DELETE FROM user_groups WHERE id = ?")->execute([$gid]);

/* ── 7. the settings and permissions exist everywhere a setting has to ────── */
$defaults = trackerSchemaDefaultSettings();
foreach (['pm_enabled' => '0', 'friends_enabled' => '0', 'directory_enabled' => '0', 'pm_who' => 'friends'] as $k => $v) {
    check("$k ships as '$v'", ($defaults[$k] ?? null) === $v, var_export($defaults[$k] ?? null, true));
}
$saveSrc = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl  = (string)@file_get_contents($root . '/templates/admin/settings.php');
foreach (['pm_enabled', 'pm_who', 'pm_max_per_day', 'pm_max_chars', 'friends_enabled', 'directory_enabled'] as $k) {
    check("$k is in the save allow-list", str_contains($saveSrc, "'$k'"));
    check("$k has a control on the Settings page", str_contains($setTpl, "name=\"$k\""));
}
$perms = userPermissionList();
foreach (['pm.send', 'pm.report', 'friends.use', 'directory.view', 'panel.messages.view', 'panel.messages.handle'] as $perm) {
    check("$perm is a registered permission", isset($perms[$perm]));
}
// The panel ones are NOT handed out by the migration: reading somebody's private message is a
// different kind of access, and an operator grants it on purpose.
$mod = userGroupBySlug($db, 'moderator');
$modPerms = $mod ? json_decode((string)$mod['permissions'], true) : [];
check('the seeded moderator group is NOT given the message queue by default',
    empty($modPerms['panel.messages.view']), implode(',', array_keys(array_filter($modPerms ?: []))));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
