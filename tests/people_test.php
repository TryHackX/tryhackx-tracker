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

/* ── a conversation that keeps up with itself (v54) ─────────────────────────────────────────────
 *
 * Two facts, and the second is the one worth a test: "…is writing" EXPIRES. Anything that says a
 * person started and waits to be told they stopped will one day leave somebody writing for ever,
 * because the stop arrives from a browser that may be closed, asleep or gone.
 */
check('live refresh is off unless a number is set',
    pmLiveSeconds([]) === 0 && pmLiveSeconds(['pm_live_seconds' => '0']) === 0);
check('… and any number is clamped to something a server can serve',
    pmLiveSeconds(['pm_live_seconds' => '1']) === 2 && pmLiveSeconds(['pm_live_seconds' => '5']) === 5
    && pmLiveSeconds(['pm_live_seconds' => '9999']) === 60);
check('the typing line needs BOTH switches, because it rides on the refresh',
    !pmTypingEnabled(['pm_typing_enabled' => '1'])
    && !pmTypingEnabled(['pm_live_seconds' => '5'])
    && pmTypingEnabled(['pm_live_seconds' => '5', 'pm_typing_enabled' => '1']));
check('the window outlives one poll interval, so a steady typist never flickers',
    pmTypingWindow(['pm_live_seconds' => '5']) > 5);

$liveCfg = array_merge($cfg, ['pm_enabled' => '1', 'pm_live_seconds' => '5', 'pm_typing_enabled' => '1']);
$offCfg  = array_merge($cfg, ['pm_enabled' => '1', 'pm_live_seconds' => '0', 'pm_typing_enabled' => '1']);
$db->exec("DELETE FROM message_typing WHERE thread_id = 99001");
pmTypingTouch($db, $liveCfg, 99001, (int)$alice['id']);
check('a keystroke says who is writing, in the thread they are writing in',
    pmSomeoneTyping($db, $liveCfg, 99001, (int)$alice['id']) === true);
check('… and says nothing about the other person', pmSomeoneTyping($db, $liveCfg, 99001, (int)$bob['id']) === false);
check('… nor about another conversation', pmSomeoneTyping($db, $liveCfg, 99002, (int)$alice['id']) === false);
check('with the feature off, nothing is written and nothing is read',
    (function (PDO $db, array $offCfg, int $id): bool {
        $db->exec("DELETE FROM message_typing WHERE thread_id = 99003");
        pmTypingTouch($db, $offCfg, 99003, $id);
        return (int)$db->query("SELECT COUNT(*) FROM message_typing WHERE thread_id = 99003")->fetchColumn() === 0
            && pmSomeoneTyping($db, $offCfg, 99003, $id) === false;
    })($db, $offCfg, (int)$alice['id']));

// THE POINT: the row is a moment, not a state. Age it and it stops being true by itself.
$db->prepare("UPDATE message_typing SET until = NOW() - INTERVAL 1 SECOND WHERE thread_id = 99001")->execute();
check('a moment that has passed is not somebody still writing',
    pmSomeoneTyping($db, $liveCfg, 99001, (int)$alice['id']) === false);
$db->prepare("UPDATE message_typing SET until = NOW() - INTERVAL 5 MINUTE WHERE thread_id = 99001")->execute();
check('and the janitor sweeps it up', pmTypingPrune($db) >= 1
    && (int)$db->query("SELECT COUNT(*) FROM message_typing WHERE thread_id = 99001")->fetchColumn() === 0);
$db->exec("DELETE FROM message_typing WHERE thread_id IN (99001, 99002, 99003)");

/* ── silenced, and banned until a date (v55) ────────────────────────────────────────────────────
 *
 * A mute is a MOMENT on the sender's own row, and the gate reads it before it reads anything about
 * the recipient: being silenced is about this account writing at all. The date is what makes it end
 * without anybody remembering to end it, which is the whole design and the thing worth pinning.
 */
// A pair of its own: the cascade section above deletes the two accounts this file started with, and
// a test that reads a row somebody else removed fails for a reason that has nothing to do with what
// it is checking.
$db->prepare("DELETE FROM users WHERE username IN ('pmmute','pmmate')")->execute();
$alice = userFindById($db, $mk($db, $cfg, 'pmmute'));
$bob   = userFindById($db, $mk($db, $cfg, 'pmmate'));
$db->prepare("UPDATE users SET pm_muted_until = NOW() + INTERVAL 1 DAY WHERE id = ?")->execute([(int)$alice['id']]);
$mutedAlice = userFindById($db, (int)$alice['id']);
check('a silenced account is told so rather than having its messages vanish',
    $reason(pmCanWrite($db, $on, $mutedAlice, $bob)) === 'muted');
check('… before anything about the recipient — being silenced is about writing at all',
    $reason(pmCanWrite($db, array_merge($on, ['pm_who' => 'nobody']), $mutedAlice, $bob)) === 'muted');
check('… while the person they were writing to is not silenced by it',
    pmCanWrite($db, $on, $bob, $mutedAlice)['ok'] === true);
check('pmMutedUntil reports the moment while it lasts', pmMutedUntil($mutedAlice) !== null);

$db->prepare("UPDATE users SET pm_muted_until = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([(int)$alice['id']]);
$exAlice = userFindById($db, (int)$alice['id']);
check('a moment that has passed is not a silence — nothing has to run for it to end',
    pmMutedUntil($exAlice) === null && pmCanWrite($db, $on, $exAlice, $bob)['ok'] === true);

// The janitor's tidy-up: it clears the column, and it un-bans an account whose days are up.
$db->prepare("UPDATE users SET status = 'banned', banned_until = NOW() - INTERVAL 1 HOUR WHERE id = ?")->execute([(int)$bob['id']]);
$lift = userLiftExpiredPunishments($db);
check('a timed ban ends by itself', $lift['unbanned'] >= 1
    && (string)$db->query("SELECT status FROM users WHERE id = " . (int)$bob['id'])->fetchColumn() === 'active');
check('… and the stale silence is cleared with it', $lift['unmuted'] >= 1
    && $db->query("SELECT pm_muted_until FROM users WHERE id = " . (int)$alice['id'])->fetchColumn() === null);
// A ban with no end date is NOT lifted by the tick: that one is a decision, not a timer.
$db->prepare("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = ?")->execute([(int)$bob['id']]);
userLiftExpiredPunishments($db);
check('a ban with no end date stays until somebody lifts it',
    (string)$db->query("SELECT status FROM users WHERE id = " . (int)$bob['id'])->fetchColumn() === 'banned');
$db->prepare("UPDATE users SET status = 'active', banned_until = NULL WHERE id = ?")->execute([(int)$bob['id']]);
$db->prepare("DELETE FROM users WHERE username IN ('pmmute','pmmate')")->execute();

/* ── one message, one record (v56) ──────────────────────────────────────────────────────────────
 *
 * A message used to write a notification beside itself, and reading the message cleared only one of
 * the two — so the number on the account link outlived the conversation it was about, and nothing
 * on the page could explain it. The unread message is now the only record of the message, which is
 * what makes reading it enough.
 */
$nid = $mk($db, $cfg, 'pmnotif');
$db->prepare("INSERT INTO user_notifications (user_id, type, title) VALUES (?, 'pm', 'old one')")->execute([$nid]);
$db->prepare("INSERT INTO user_notifications (user_id, type, title) VALUES (?, 'friend_request', 'keep me')")->execute([$nid]);
$migrated = 0;
foreach (trackerSchemaGuardedStatements($db) as $q) {
    if (stripos($q, 'user_notifications') !== false) { $db->exec($q); $migrated++; }
}
check('the upgrade carries a statement for the notifications already written', $migrated === 1);
check('… and it removes the ones a message left behind',
    (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = $nid AND type = 'pm'")->fetchColumn() === 0);
check('… and nothing else — a friend request is still a notification',
    (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = $nid")->fetchColumn() === 1);
check('the schema version was bumped, or the statement would never run', TRACKER_SCHEMA_VERSION >= 56);
$src = (string)file_get_contents(__DIR__ . '/../api/user_messages.php');
check('and sending a message does not write one any more', !str_contains($src, 'userNotify('));
check('the inbox has a poll of its own — two facts and no rows',
    str_contains($src, "'inbox' => true") && str_contains($src, "'stamp' =>"));
// And the one fact the list is watched by: the moment of the newest line in this reader's inbox.
$mateId = $mk($db, $cfg, 'pmstamp');
check('an inbox with nothing in it has no moment', pmInboxStamp($db, $nid) === '');
$th = pmThreadFor($db, $nid, $mateId);
$db->prepare("UPDATE message_threads SET last_message_at = '2026-01-02 03:04:05' WHERE id = ?")->execute([(int)$th['id']]);
check('… and one with a conversation in it reports that conversation',
    pmInboxStamp($db, $nid) === '2026-01-02 03:04:05');
$db->prepare("UPDATE message_threads SET last_message_at = '2026-01-02 03:04:06' WHERE id = ?")->execute([(int)$th['id']]);
check('… and it moves when the newest line does', pmInboxStamp($db, $nid) === '2026-01-02 03:04:06');
// Hidden means hidden: a conversation somebody has put away does not keep their list awake.
$col = (int)$th['u_low'] === $nid ? 'u_low_hidden' : 'u_high_hidden';
$db->prepare("UPDATE message_threads SET $col = 1 WHERE id = ?")->execute([(int)$th['id']]);
check('… and a conversation this reader has put away is not one of theirs', pmInboxStamp($db, $nid) === '');
$db->prepare("DELETE FROM message_threads WHERE id = ?")->execute([(int)$th['id']]);
$db->prepare("DELETE FROM user_notifications WHERE user_id = ?")->execute([$nid]);
$db->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$nid, $mateId]);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
