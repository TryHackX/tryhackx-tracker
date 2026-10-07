<?php
/**
 * The shoutbox's unread counts and its newest id (1.74.0, PERF-1 / PERF-15):
 *   php tests/shout_unread_test.php           (needs the local test database)
 *
 *   1. shoutUnreadCounts() answers what the query it replaced answered — the total, the friends' share, the mentions
 *      (an edit's late mention below the mark included) — for readers with and without friends, a friendship asked
 *      from either side, a pending one, at several marks;
 *   2. its cost no longer multiplies by the reader's friendships: 30 000 unread lines and 400 friendships in
 *      well under a second (the correlated EXISTS took ~15 s there);
 *   3. an account that has never looked (mark 0) is caught up at its first count to the lines said BEFORE it
 *      existed — and only those: what came after is still new to it, which is what tests/shout_test.php relies on;
 *   4. shoutNewestId() is the newest line that is not deleted, read from the end of the primary key.
 *
 * Accounts and lines of its own (prefixed), removed at the end; it never empties the room — tests/shout_test.php
 * is the file that does, and it runs only in the battery.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/shout.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . mb_substr($info, 0, 500)) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'shout_enabled' => '1']);
const SU = 'shun7_';   // every account of this file is named so

$cleanup = static function () use ($db): void {
    $ids = $db->query("SELECT id FROM users WHERE username LIKE '" . SU . "%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $db->exec("DELETE m FROM shout_mentions m JOIN shouts s ON s.id = m.shout_id WHERE s.user_id IN ($in)");
        $db->exec("DELETE FROM shout_mentions WHERE user_id IN ($in)");
        $db->exec("DELETE FROM shouts WHERE user_id IN ($in)");
        $db->exec("DELETE FROM user_friends WHERE user_id IN ($in) OR friend_id IN ($in)");
        $db->exec("DELETE FROM user_group_members WHERE user_id IN ($in)");
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
};
$cleanup();
$mkUser = static function (string $name) use ($db): int {
    $db->prepare("INSERT INTO users (username, email, pass_hash, created_ip) VALUES (?, ?, 'x', '127.0.0.1')")
       ->execute([SU . $name, SU . $name . '@example.org']);
    return (int)$db->lastInsertId();
};
$say = static function (int $userId, string $body, ?string $at = null) use ($db): int {
    if ($at === null) $db->prepare("INSERT INTO shouts (user_id, body, body_format) VALUES (?, ?, 'plain')")->execute([$userId, $body]);
    else $db->prepare("INSERT INTO shouts (user_id, body, body_format, created_at) VALUES (?, ?, 'plain', ?)")->execute([$userId, $body, $at]);
    return (int)$db->lastInsertId();
};
$befriend = static function (int $a, int $b, string $status = 'accepted') use ($db): void {
    $db->prepare("INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, ?, IF(? = 'accepted', NOW(), NULL))")->execute([$a, $b, $status, $status]);
};
/** The query shoutUnreadCounts() ran until 1.74.0, verbatim — the reference the new one must agree with. */
$oldCounts = static function (int $meId, int $seen) use ($db): array {
    $st = $db->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(EXISTS(SELECT 1 FROM user_friends f WHERE f.status = 'accepted'
                                      AND ((f.user_id = ? AND f.friend_id = s.user_id)
                                        OR (f.user_id = s.user_id AND f.friend_id = ?)))), 0) AS friends,
                COALESCE(SUM(EXISTS(SELECT 1 FROM shout_mentions m WHERE m.shout_id = s.id AND m.user_id = ?)), 0) AS mentions
           FROM shouts s
          WHERE s.id > ? AND s.deleted_at IS NULL AND s.is_system = 0 AND s.user_id <> ?");
    $st->execute([$meId, $meId, $meId, $seen, $meId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $out = ['shout' => (int)$r['total'], 'shout_friend' => (int)$r['friends'], 'mention' => (int)$r['mentions']];
    $st = $db->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(EXISTS(SELECT 1 FROM user_friends f WHERE f.status = 'accepted'
                                      AND ((f.user_id = ? AND f.friend_id = s.user_id)
                                        OR (f.user_id = s.user_id AND f.friend_id = ?)))), 0) AS friends
           FROM shout_mentions m JOIN shouts s ON s.id = m.shout_id
          WHERE m.user_id = ? AND m.late = 1 AND m.shout_id <= ?
            AND s.deleted_at IS NULL AND s.is_system = 0 AND s.user_id <> ?");
    $st->execute([$meId, $meId, $meId, $seen, $meId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    $out['shout'] += (int)$l['total']; $out['mention'] += (int)$l['total']; $out['shout_friend'] += (int)$l['friends'];
    return $out;
};
$seenOf = static function (int $id) use ($db): int {
    return (int)$db->query("SELECT shout_seen_id FROM users WHERE id = $id")->fetchColumn();
};

try {
/* ── 1. the same three numbers ────────────────────────────────────────────── */
$me = $mkUser('me'); $pal = $mkUser('pal'); $askedMe = $mkUser('asked'); $stranger = $mkUser('stranger'); $pending = $mkUser('pending');
$befriend($me, $pal);                 // I asked
$befriend($askedMe, $me);             // they asked — the same friendship, the other row order
$befriend($me, $pending, 'pending');  // not a friend yet
$lines = [];
foreach ([$pal, $askedMe, $stranger, $pending, $me, $pal, $stranger] as $i => $who) $lines[] = $say($who, 'line ' . $i);
$db->prepare("INSERT INTO shout_mentions (shout_id, user_id) VALUES (?, ?), (?, ?)")->execute([$lines[2], $me, $lines[5], $me]);
$db->exec("UPDATE shouts SET deleted_at = NOW() WHERE id = " . $lines[6]);            // deleted: new to nobody
$sys = $say($stranger, 'the site said something'); $db->exec("UPDATE shouts SET is_system = 1 WHERE id = $sys");
// an edit that named me in a line I had already read past (v72, `late`)
$db->prepare("INSERT INTO shout_mentions (shout_id, user_id, late) VALUES (?, ?, 1)")->execute([$lines[0], $askedMe]);
$cases = [];
foreach ([$me, $pal, $askedMe, $stranger, $pending] as $reader) {
    foreach ([0, $lines[0], $lines[1], $lines[3], $lines[5]] as $mark) {
        if ($mark === 0) continue;   // 0 is section 3's: it now means "has never looked"
        $new = shoutUnreadCounts($db, $cfgOn, ['id' => $reader, 'shout_seen_id' => $mark]);
        $old = $oldCounts($reader, $mark);
        if ($new !== $old) $cases[] = "reader $reader mark $mark: new " . json_encode($new) . ' old ' . json_encode($old);
    }
}
check('the three numbers are the old query\'s, for every reader (friends either way round, a pending one, none) at every mark',
      $cases === [], implode(' | ', $cases));
$c = shoutUnreadCounts($db, $cfgOn, ['id' => $me, 'shout_seen_id' => $lines[0] - 1]);
check('… e.g. the reader with two friends: 5 lines new (not my own, not the deleted one, not the site\'s), 3 of them friends\', 2 naming me',
      $c === ['shout' => 5, 'shout_friend' => 3, 'mention' => 2], json_encode($c));
check('… the friends are fetched once, either side of the friendship, accepted only',
      shoutFriendIds($db, $me) === [min($pal, $askedMe), max($pal, $askedMe)] && shoutFriendIds($db, $pending) === [] && shoutFriendIds($db, 0) === [],
      json_encode(shoutFriendIds($db, $me)));
check('… and with the feature off there is nothing to count', shoutUnreadCounts($db, array_merge($cfgOn, ['shout_enabled' => '0']), ['id' => $me, 'shout_seen_id' => 1])
      === ['shout' => 0, 'shout_friend' => 0, 'mention' => 0]);

/* ── 3. never looked: caught up to the lines before the account ───────────── */
// Everything said so far is put two hours back, so "before the newcomer" is unambiguous to the second.
$db->exec("UPDATE shouts s JOIN users u ON u.id = s.user_id SET s.created_at = NOW() - INTERVAL 2 HOUR WHERE u.username LIKE '" . SU . "%'");
$before = $say($stranger, 'said an hour before the newcomer', date('Y-m-d H:i:s', time() - 3600));
$newcomer = $mkUser('newcomer');
$after = $say($stranger, 'said after the newcomer arrived');
$c = shoutUnreadCounts($db, $cfgOn, ['id' => $newcomer, 'shout_seen_id' => 0]);
check('an account at mark 0 does not inherit the room: what was said before it existed is not new to it, what came after is',
      $c['shout'] === 1, json_encode($c));
check('… and its mark is moved for good, to the newest line older than the account (once: no work on later pulses)',
      $seenOf($newcomer) >= $before && $seenOf($newcomer) < $after, $seenOf($newcomer) . " before=$before after=$after");
$oldTimer = $mkUser('oldtimer');
$db->exec("UPDATE users SET created_at = '2000-01-01 00:00:00' WHERE id = $oldTimer");
$c2 = shoutUnreadCounts($db, $cfgOn, ['id' => $oldTimer, 'shout_seen_id' => 0]);
check('an account older than every line keeps mark 0 and counts the whole room, as before (what tests/shout_test.php sets up)',
      $seenOf($oldTimer) === 0 && $c2['shout'] === $oldCounts($oldTimer, 0)['shout'] && $c2['shout'] > 0, json_encode($c2));
// The reader's own line dated before the account (tests/shout_test.py ages one to close an edit window, then counts the
// room from 0): it must not carry the mark past what others said afterwards — own lines are never counted anyway.
$selfie = $mkUser('selfie');
$db->exec("UPDATE users SET created_at = NOW() - INTERVAL 30 MINUTE WHERE id = $selfie");
$o1 = $say($stranger, 'said after the account was made');
$aged = $say($selfie, 'my own line, aged to before the account', date('Y-m-d H:i:s', time() - 3600));   // a higher id than $o1
$c3 = shoutUnreadCounts($db, $cfgOn, ['id' => $selfie, 'shout_seen_id' => 0]);
check('… the reader\'s own line dated before the account does not carry the mark past others\' later words',
      $seenOf($selfie) < $o1 && $aged > $o1 && $c3['shout'] === $oldCounts($selfie, $seenOf($selfie))['shout'] && $c3['shout'] >= 1,
      json_encode([$seenOf($selfie), $o1, $aged, $c3]));
$late = $db->prepare("UPDATE users SET shout_seen_id = ? WHERE id = ?");
$late->execute([$after + 1000, $newcomer]);
shoutCatchUpNew($db, $newcomer);
check('… a mark a widget moved further meanwhile is never pulled back (GREATEST)', $seenOf($newcomer) === $after + 1000, (string)$seenOf($newcomer));

/* ── 4. the newest id ─────────────────────────────────────────────────────── */
$top = $say($pal, 'the newest line, then deleted');
$db->exec("UPDATE shouts SET deleted_at = NOW() WHERE id = $top");
$max = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM shouts WHERE deleted_at IS NULL")->fetchColumn();
check('shoutNewestId() is the newest line that is not deleted', shoutNewestId($db) === $max && $max < $top, shoutNewestId($db) . " vs $max");
$plan = $db->query("EXPLAIN SELECT id FROM shouts WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('… read backwards along the primary key, never a scan of the table with a sort',
      in_array($plan['type'] ?? '', ['index', 'range'], true) && ($plan['key'] ?? '') === 'PRIMARY' && !str_contains((string)($plan['Extra'] ?? ''), 'filesort'),
      json_encode($plan));

/* ── 2. the cost: 30 000 unread lines, 400 friendships ───────────────────── */
$db->exec("INSERT INTO users (username, email, pass_hash, created_ip)
           SELECT CONCAT('" . SU . "f', seq), CONCAT('" . SU . "f', seq, '@example.org'), 'x', '127.0.0.1' FROM seq_1_to_400");
$heavy = $mkUser('heavy');
$db->exec("UPDATE users SET created_at = NOW() - INTERVAL 1 DAY WHERE id = $heavy");
$db->exec("INSERT INTO user_friends (user_id, friend_id, status, accepted_at)
           SELECT $heavy, id, 'accepted', NOW() FROM users WHERE username LIKE '" . SU . "f%'");
$ids = $db->query("SELECT id FROM users WHERE username LIKE '" . SU . "f%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$first = (int)$ids[0];
// 30 000 lines, three in four by a friend, the rest by the stranger
$db->exec("INSERT INTO shouts (user_id, body, body_format)
           SELECT IF(seq % 4 = 0, $stranger, $first + (seq % 400)), CONCAT('bulk ', seq), 'plain' FROM seq_1_to_30000");
$floor = (int)$db->query("SELECT MIN(id) - 1 FROM shouts WHERE body = 'bulk 1' AND user_id IN ($stranger, " . implode(',', $ids) . ")")->fetchColumn();
$t0 = microtime(true);
$c = shoutUnreadCounts($db, $cfgOn, ['id' => $heavy, 'shout_seen_id' => $floor]);
$ms = (microtime(true) - $t0) * 1000;
check(sprintf('30 000 unread lines, 400 friendships: %.0f ms (the correlated EXISTS: ~15 s) — and the numbers add up', $ms),
      $ms < 1000 && $c['shout'] >= 30000 && $c['shout_friend'] === 22500, json_encode($c));
} finally {
    $cleanup();
}
check('cleanup: no account of this run is left', (int)$db->query("SELECT COUNT(*) FROM users WHERE username LIKE '" . SU . "%'")->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
