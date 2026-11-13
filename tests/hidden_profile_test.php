<?php
/**
 * A profile hidden from somebody is not there for them — everywhere (1.74.0, PRIV-3):
 *   php tests/hidden_profile_test.php
 *
 * A block with `hide_profile` makes the account not there for the person it hides from. Since 1.45.0 the profile,
 * `with=` and `can=` have answered not-found; the rest still told: the open conversation's POLL handed over the whole
 * old conversation, the INBOX listed it with its last line, its COUNTS and its SEARCH found it, `typing` answered 403
 * where a name nobody has gets 404, `follow` said "blocked", and the profile's not-found page was written twice —
 * the hidden one differed from the real one in its bytes. Pinned here, each against a name nobody has and an
 * account that hides nothing:
 *
 *   1. the inbox and everything counted from it (pmListThreads, pmDeepSearchIds, pmBoxCounts, pmUnreadCount,
 *      pmUnreadCountFriends, pmInboxStamp) leave the conversation out — and a block that only stops messages
 *      does not;
 *   2. as requests: the poll, typing and follow answer 404 not_found for the hidden profile exactly as for a name
 *      nobody has (and `with=` still does);
 *   3. the profile page is the same bytes for the hidden profile and for a name nobody has.
 *
 * Self-cleaning: its accounts (their threads, messages, blocks and friendships go with them).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
const HPT_INCLUDES = ['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
    'includes/whitelist.php', 'includes/schedule.php', 'includes/stats_timeline.php', 'includes/index.php', 'includes/api_auth.php',
    'includes/auth.php', 'includes/twofa.php', 'includes/bulkmail.php', 'includes/richtext.php', 'includes/livesync.php',
    'includes/reputation.php', 'includes/wlmaint.php', 'includes/wlprobe.php', 'includes/mail.php', 'includes/users.php',
    'includes/favourites.php', 'includes/sounds.php', 'includes/shout.php', 'includes/usermedia.php', 'includes/profilebio.php',
    'includes/profilevotes.php', 'includes/profiledescs.php', 'includes/comments.php', 'includes/reports.php', 'includes/antispam.php',
    'includes/lists.php', 'includes/who.php', 'includes/people.php', 'includes/user2fa.php', 'includes/authbridge.php',
    'includes/audit.php', 'includes/lang.php'];
foreach (HPT_INCLUDES as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0', 'profiles_enabled' => '1', 'pm_enabled' => '1',
                            'friends_enabled' => '1', 'pm_who' => 'all', 'pm_live_seconds' => '5', 'pm_typing_enabled' => '1',
                            'antispam_enabled' => '0']);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfgOn;
langInit([], 'en');

$clean = function () use ($db): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'hpt\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'hpt\\_%'");
};
$clean();
$tmp = [];
register_shutdown_function(function () use ($clean, &$tmp) { $clean(); foreach ($tmp as $f) @unlink($f); });

$db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES ('hpt_member', 'hpt_member', '', '', 2, 0, 0, ?)")
   ->execute([json_encode(array_fill_keys(['pm.send', 'friends.use', 'favourites.view_others', 'index.view'], true))]);
$gid = (int)$db->lastInsertId();
$account = function (string $name) use ($db, $cfgOn, $gid): int {
    $r = userCreate($db, $cfgOn, $name, '', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
    userGrantGroup($db, $id, $gid, null, 'test', 'hidden_profile_test', false, '2000-01-01 00:00:00');
    return $id;
};
$X = $account('hpt_reader');   // the one hidden from
$Hd = $account('hpt_hider');   // hides their profile from X
$Y = $account('hpt_other');    // hides nothing
check('fixtures: three accounts', $X > 0 && $Hd > 0 && $Y > 0);
$say = function (int $from, int $to, string $body) use ($db): void {
    $t = pmThreadFor($db, $from, $to);
    $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, 'bbcode')")->execute([(int)$t['id'], $from, $body]);
    $db->prepare("UPDATE message_threads SET last_message_at = NOW() WHERE id = ?")->execute([(int)$t['id']]);
};
$say($X, $Hd, 'HPT hello from X');
$say($Hd, $X, 'HPT reply from H, my plans for Friday');
$say($Y, $X, 'HPT a line from Y, Friday too');
// Friends with both, so the friends' count has something to count.
foreach ([$Hd, $Y] as $f) $db->prepare("INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, 'accepted', NOW())")->execute([$X, $f]);
// H's newest line is the newest in X's inbox — the stamp would move for it.
$db->prepare("UPDATE message_threads SET last_message_at = NOW() + INTERVAL 1 HOUR WHERE id = ?")->execute([(int)pmThreadFor($db, $X, $Hd)['id']]);
$threadsOf = fn(string $view = 'inbox'): array => array_map(fn($r) => (int)$r['other_id'], pmListThreads($db, $X, $view));

/* ══ 1. before, and with a block that only stops messages ════════════════ */
check('before any block: both conversations in X\'s inbox, both unread lines counted, both friends\' lines',
      count($threadsOf()) === 2 && pmUnreadCount($db, $X) === 2 && pmUnreadCountFriends($db, $X) === 2 && count(pmDeepSearchIds($db, $X, 'inbox', 'Friday')) === 2);
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$Hd, $X]);
check('a block WITHOUT hide_profile hides nothing: the conversation is still X\'s', in_array($Hd, $threadsOf(), true) && pmUnreadCount($db, $X) === 2);
$stampBefore = pmInboxStamp($db, $X);
$db->prepare("UPDATE user_blocks SET hide_profile = 1 WHERE user_id = ? AND blocked_id = ?")->execute([$Hd, $X]);
// The block ends the friendship, as api/user_people.php's block does.
$db->prepare("DELETE FROM user_friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)")->execute([$X, $Hd, $Hd, $X]);
$db->prepare("INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, 'accepted', NOW())")->execute([$X, $Hd]);   // …and if it had not

/* ══ 1. the inbox, its counts and its search ═════════════════════════════ */
check('hidden: the conversation is not in X\'s inbox; the other one is', $threadsOf() === [$Y], json_encode($threadsOf()));
check('… nor in its search ("Friday" is in both)', count($ids = pmDeepSearchIds($db, $X, 'inbox', 'Friday')) === 1
      && $ids === [(int)pmThreadFor($db, $X, $Y)['id']], json_encode($ids));
check('… nor in the unread count, nor the friends\' count', pmUnreadCount($db, $X) === 1 && pmUnreadCountFriends($db, $X) === 1,
      json_encode([pmUnreadCount($db, $X), pmUnreadCountFriends($db, $X)]));
$box = pmBoxCounts($db, $X);
check('… nor in the tabs\' numbers (inbox 1, its unread 1 — the badge, explained)', $box['inbox'] === 1 && $box['unread_inbox'] === 1, json_encode($box));
check('… and its newest line is no reason to redraw the list (the stamp is the other conversation\'s)', pmInboxStamp($db, $X) < $stampBefore,
      pmInboxStamp($db, $X) . ' vs ' . $stampBefore);
check('… while H, who hid, still has the conversation', in_array($X, array_map(fn($r) => (int)$r['other_id'], pmListThreads($db, $Hd, 'inbox')), true));
$db->prepare("DELETE FROM user_friends WHERE user_id = ? AND friend_id = ?")->execute([$X, $Hd]);

/* ══ 2. as requests ══════════════════════════════════════════════════════ */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hpt_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmp[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.74";
$_GET = $a["get"];
$_POST = $a["post"];
foreach ($a["includes"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () {
    fwrite(STDERR, "STATUS:" . (int)http_response_code() . "\n");
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
langInit($cfg, "en");
if (!empty($a["page"])) { $baseUrl = "/"; $csrfToken = "hpt-token"; $action = "u"; require $a["page"]; }
else require "api/" . $a["endpoint"] . ".php";
');
$ask = function (string $method, string $endpoint, array $get = [], array $post = [], string $page = '') use ($root, $runner, $cfgOn, $X, &$tmp): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hpt_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmp[] = $arg;
    file_put_contents($arg, json_encode(['root' => $root, 'endpoint' => $endpoint, 'method' => $method, 'get' => $get, 'post' => $post,
        'includes' => HPT_INCLUDES, 'page' => $page !== '' ? $root . '/' . $page : '',
        'cfg' => array_intersect_key($cfgOn, array_flip(['users_enabled', 'users_require_email_verify', 'profiles_enabled', 'pm_enabled', 'friends_enabled',
                                                          'pm_who', 'pm_live_seconds', 'pm_typing_enabled', 'antispam_enabled'])),
        'session' => ['user_id' => $X, 'user_login_time' => time(), 'csrf_token' => 'hpt-token'], 'sid' => 'hpt' . bin2hex(random_bytes(8))]));
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'xdebug.mode=off', $runner, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    foreach ($pipes as $h) fclose($h);
    proc_close($p);
    $j = json_decode(trim($out), true);
    // No code set by the file is the default 200; no STATUS line at all is a crash (0).
    return ['status' => preg_match('/STATUS:(\d+)/', $err, $m) ? ((int)$m[1] ?: 200) : 0, 'json' => is_array($j) ? $j : null, 'raw' => $out,
            'err' => substr($err, 0, 400)];
};
$three = function (callable $askOne): array {
    return ['hidden' => $askOne('hpt_hider'), 'nobody' => $askOne('hpt_nobody_here'), 'other' => $askOne('hpt_other')];
};
$r = $three(fn($w) => $ask('GET', 'user_messages', ['with' => $w]));
check('`with=`: 404 for the hidden profile and for a name nobody has, 200 for the other (as since 1.45.0)',
      $r['hidden']['status'] === 404 && $r['nobody']['status'] === 404 && $r['other']['status'] === 200, json_encode(array_map(fn($x) => $x['status'], $r)));
$r = $three(fn($w) => $ask('GET', 'user_messages', ['poll' => '1', 'with' => $w, 'after' => '0']));
check('the poll: 404 not_found for the hidden profile — not the old conversation — exactly as for a name nobody has',
      $r['hidden']['status'] === 404 && ($r['hidden']['json']['error'] ?? '') === 'not_found' && !str_contains($r['hidden']['raw'], 'Friday')
      && $r['nobody']['status'] === 404 && $r['other']['status'] === 200, json_encode(array_map(fn($x) => [$x['status'], substr($x['raw'], 0, 80)], $r)));
$r = $three(fn($w) => $ask('POST', 'user_messages', [], ['csrf_token' => 'hpt-token', 'op' => 'typing', 'with' => $w]));
check('typing: 404 not_found for both (it was 403 for the hidden one), 200 for the other',
      $r['hidden']['status'] === 404 && ($r['hidden']['json']['error'] ?? '') === 'not_found' && $r['nobody']['status'] === 404 && $r['other']['status'] === 200,
      json_encode(array_map(fn($x) => [$x['status'], $x['json']], $r)));
$r = $three(fn($w) => $ask('POST', 'user_people', [], ['csrf_token' => 'hpt-token', 'op' => 'follow', 'user' => $w]));
check('follow: 404 not_found for both (it said "blocked" for the hidden one), 200 for the other',
      $r['hidden']['status'] === 404 && ($r['hidden']['json']['error'] ?? '') === 'not_found' && $r['nobody']['status'] === 404 && $r['other']['status'] === 200,
      json_encode(array_map(fn($x) => [$x['status'], $x['json']], $r)));
check('… and no request reached the one who hid', !(int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = $Hd AND type = 'friend_request'")->fetchColumn());

/* ══ 3. the profile page: one not-found ══════════════════════════════════ */
$pHidden = $ask('GET', '', ['action' => 'u', 'name' => 'hpt_hider'], [], 'templates/pages/profile.php');
$pNobody = $ask('GET', '', ['action' => 'u', 'name' => 'hpt_nobody_here'], [], 'templates/pages/profile.php');
$pOther = $ask('GET', '', ['action' => 'u', 'name' => 'hpt_other'], [], 'templates/pages/profile.php');
check('the profile hidden from X and the profile of a name nobody has: the SAME bytes', $pHidden['raw'] !== '' && $pHidden['raw'] === $pNobody['raw'],
      json_encode([substr($pHidden['raw'], 0, 160), substr($pNobody['raw'], 0, 160)]));
check('… and a profile that hides nothing is shown', str_contains($pOther['raw'], 'class="profile-name"'), substr($pOther['raw'], 0, 160));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
