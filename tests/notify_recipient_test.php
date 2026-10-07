<?php
/**
 * What an account receives, in ITS language — and what a sender may make the site mail (1.74.0 — QUAL-18, QUAL-15,
 * PUB-5, PUB-1, PERF-10, the v93 notification senders):
 *   php tests/notify_recipient_test.php
 *
 *   1. recipientLang() — THE one rule for the language of a notification or a mail (comments.php and reports.php had a
 *      copy each until 1.74.0): the account's own language, the request's when the account is the one asking, the
 *      site's default, English;
 *   2. the notifications that were English literals or the SENDER's words, written to a Polish account in Polish:
 *      a group granted / taken away / run out / about to run out, a friend request and its acceptance (as requests),
 *      a description published (content.php), and no userNotify() anywhere with a literal title;
 *   3. PUB-5: following, unfollowing and following again announces ONE request while the first is unread;
 *   4. PERF-10: userGroups() is remembered for the request and forgotten with the permission memo;
 *   5. PUB-1: a report's and an appeal's confirmation carry nothing the sender typed — built, not sent;
 *   6. v93: the friend notifications written before `sender_id` get their sender from the title, in any installed
 *      language; those of an account already gone are deleted (schemaNotifySenders()).
 *
 * Self-cleaning: its accounts, groups, notifications, whitelist row and rate-limit hits are removed at the end; the
 * settings table is not written (every switch is set in memory). Its accounts have no e-mail address — nothing here
 * sends a mail (tests/mail_test.php is where mails are read).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
const NRT_INCLUDES = ['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
    'includes/whitelist.php', 'includes/schedule.php', 'includes/stats_timeline.php', 'includes/index.php', 'includes/api_auth.php',
    'includes/auth.php', 'includes/twofa.php', 'includes/bulkmail.php', 'includes/richtext.php', 'includes/livesync.php',
    'includes/reputation.php', 'includes/wlmaint.php', 'includes/wlprobe.php', 'includes/mail.php', 'includes/users.php',
    'includes/favourites.php', 'includes/sounds.php', 'includes/shout.php', 'includes/usermedia.php', 'includes/profilebio.php',
    'includes/profilevotes.php', 'includes/profiledescs.php', 'includes/comments.php', 'includes/reports.php', 'includes/antispam.php',
    'includes/lists.php', 'includes/who.php', 'includes/people.php', 'includes/user2fa.php', 'includes/authbridge.php',
    'includes/audit.php', 'includes/content.php', 'includes/lang.php'];
foreach (NRT_INCLUDES as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0', 'friends_enabled' => '1', 'default_language' => 'en',
                            'enabled_languages' => '', 'users_notify_expiry_days' => '3', 'antispam_enabled' => '0']);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfgOn;
langInit([], 'en');
$H = str_repeat('d3d3', 10);

$clean = function () use ($db, $H): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'nrt\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'nrt\\_%'");
    $db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$H]);
    $db->exec("DELETE FROM user_notifications WHERE title LIKE '%nrt\\_%'");
};
$clean();
$tmp = [];
register_shutdown_function(function () use ($clean, &$tmp) { $clean(); foreach ($tmp as $f) @unlink($f); });

$group = function (string $slug, array $perms) use ($db): int {
    $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES (?, ?, '', '', 2, 0, 0, ?)")
       ->execute([$slug, $slug, json_encode(array_fill_keys($perms, true), JSON_UNESCAPED_SLASHES)]);
    return (int)$db->lastInsertId();
};
/** An account with no e-mail address in this one group, in this language (NULL = none chosen). */
$account = function (string $name, int $gid, ?string $lang) use ($db, $cfgOn): int {
    $r = userCreate($db, $cfgOn, $name, '', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET status = 'active', language = ? WHERE id = ?")->execute([$lang, $id]);
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
    userGrantGroup($db, $id, $gid, null, 'test', 'notify_recipient_test', false, '2000-01-01 00:00:00');
    return $id;
};
$notes = function (int $uid, int $floor = 0) use ($db): array {
    $st = $db->prepare("SELECT type, title, body, sender_id, read_at FROM user_notifications WHERE user_id = ? AND id > ? ORDER BY id");
    $st->execute([$uid, $floor]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$floor = fn(): int => (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM user_notifications")->fetchColumn();

$gMember = $group('nrt_member', ['friends.use', 'index.view']);
$gPremium = $group('nrt_premium', ['index.view']);
$pl = $account('nrt_pl', $gMember, 'pl');      // reads Polish
$en = $account('nrt_en', $gMember, 'en');      // reads English
$none = $account('nrt_none', $gMember, null);  // never chose
check('fixtures: three accounts (Polish, English, none chosen) and two groups', $pl > 0 && $en > 0 && $none > 0 && $gMember > 0 && $gPremium > 0);

/* ══ 1. recipientLang(): the one rule ════════════════════════════════════ */
$row = fn(int $id): array => userFindById($db, $id) ?? [];
check('the account\'s own language first', recipientLang($cfgOn, $row($pl)) === 'pl' && recipientLang($cfgOn, $row($en)) === 'en');
check('none chosen: the site\'s default', recipientLang(array_merge($cfgOn, ['default_language' => 'pl']), $row($none)) === 'pl'
      && recipientLang($cfgOn, $row($none)) === 'en');
check('… "auto" is no language a mail can be written in (a browser that is not here cannot be asked): English',
      recipientLang(array_merge($cfgOn, ['default_language' => 'auto']), $row($none)) === LANG_FALLBACK);
check('a language the site no longer offers is no choice: the site\'s default',
      recipientLang(array_merge($cfgOn, ['default_language' => 'pl']), ['id' => $none, 'language' => 'xx']) === 'pl');
check('nobody in particular (a report\'s sender, no account): the site\'s', recipientLang(array_merge($cfgOn, ['default_language' => 'pl']), null) === 'pl');
// The account making this very request, with no language of its own: the language the request resolved to.
$hadSession = isset($_SESSION) ? $_SESSION : null;
$_SESSION = ['user_id' => $none];
$GLOBALS['__lang']['current'] = null; langInit([], 'pl');
check('the account asking itself (signing up, setting its address) and with no language: this request\'s',
      recipientLang($cfgOn, $row($none)) === 'pl' && recipientLang($cfgOn, $row($pl)) === 'pl' && recipientLang($cfgOn, $row($en)) === 'en');
$_SESSION = ['user_id' => $en];
check('… for any OTHER account the request\'s language says nothing: the site\'s default', recipientLang($cfgOn, $row($none)) === 'en');
$_SESSION = $hadSession ?? [];
$GLOBALS['__lang']['current'] = null; langInit([], 'en');
check('recipientLangOf(): the same, from an id', recipientLangOf($db, $cfgOn, $pl) === 'pl' && recipientLangOf($db, $cfgOn, $none) === 'en'
      && recipientLangOf($db, $cfgOn, 0) === 'en');
check('ONE helper: the two copies are gone (QUAL-15)', !function_exists('commentLangFor') && !function_exists('reportLangFor'));

/* ══ 2. what was English, or the sender's, written in the RECIPIENT's language ══ */
$f = $floor();
userGrantGroup($db, $pl, $gPremium, '2099-12-31 23:59:59', 'test', 'a note', true, '2000-01-01 00:00:00');
$n1 = $notes($pl, $f);
check('a group granted, written by an English request to a Polish account: in Polish, with the dates and the note',
      count($n1) === 1 && $n1[0]['title'] === langFor('pl', 'notify.group_granted', ['group' => 'nrt_premium'])
      && $n1[0]['body'] === langFor('pl', 'notify.group_granted_from_until', ['from' => '2000-01-01 00:00:00', 'until' => '2099-12-31 23:59:59'])
                           . ' ' . langFor('pl', 'notify.group_note', ['note' => 'a note']), json_encode($n1, JSON_UNESCAPED_UNICODE));
$f = $floor();
userGrantGroup($db, $en, $gPremium, null, 'test', '', true);
check('… to an English account in English ("permanently")', ($notes($en, $f)[0]['body'] ?? '') === 'Access granted permanently.'
      && ($notes($en, $f)[0]['title'] ?? '') === 'You are now in the "nrt_premium" group');
$f = $floor();
userRevokeGroup($db, $pl, $gPremium, true);
check('a group taken away: in Polish', ($notes($pl, $f)[0]['title'] ?? '') === langFor('pl', 'notify.group_revoked', ['group' => 'nrt_premium']));
// The janitor: a membership that ran out, one that runs out soon. (No address: nothing is mailed.)
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at, expires_at) VALUES (?, ?, '2000-01-01 00:00:00', NOW() - INTERVAL 1 HOUR)")->execute([$pl, $gPremium]);
$f = $floor();
usersTick($db, $cfgOn);
check('the janitor\'s "your access expired": in Polish', in_array(langFor('pl', 'notify.group_expired', ['group' => 'nrt_premium']), array_column($notes($pl, $f), 'title'), true),
      json_encode($notes($pl, $f), JSON_UNESCAPED_UNICODE));
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at, expires_at) VALUES (?, ?, '2000-01-01 00:00:00', NOW() + INTERVAL 1 DAY)")->execute([$pl, $gPremium]);
$f = $floor();
usersTick($db, $cfgOn);
$exp = array_values(array_filter($notes($pl, $f), fn($x) => $x['type'] === 'group-expiring'));
check('… and "it ends soon": in Polish', count($exp) === 1 && $exp[0]['title'] === langFor('pl', 'notify.group_expiring', ['group' => 'nrt_premium'])
      && str_starts_with((string)$exp[0]['body'], mb_substr(langFor('pl', 'notify.group_expiring_body', ['until' => '']), 0, 10)), json_encode($exp, JSON_UNESCAPED_UNICODE));
$db->prepare("DELETE FROM user_group_members WHERE user_id = ? AND group_id = ?")->execute([$pl, $gPremium]);
userPermissionsForget($pl);
// A description published: the moderator's request is English; its author reads Polish.
$db->prepare("INSERT INTO whitelist (info_hash, name, description, description_format, content_status, content_user_id) VALUES (?, 'nrt torrent', 'words', 'bbcode', 'pending', ?)")
   ->execute([$H, $pl]);
$wid = (int)$db->lastInsertId();
$f = $floor();
contentApprove($db, $cfgOn, 'wl', $wid);
$c1 = $notes($pl, $f);
check('a description published (includes/content.php): its author told in THEIR language, not the moderator\'s',
      count($c1) === 1 && $c1[0]['title'] === langFor('pl', 'notify.content_published', ['name' => 'nrt torrent'])
      && $c1[0]['body'] === langFor('pl', 'notify.content_published_body', ['name' => 'nrt torrent']), json_encode($c1, JSON_UNESCAPED_UNICODE));
// No userNotify() writes a literal or the request's language: its title is langFor() or a variable — never a quoted
// string, never __(). Read from the code with the comments taken out.
$bad = [];
foreach (['api', 'includes'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $tokens = token_get_all((string)file_get_contents($file->getPathname()));
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'userNotify') continue;
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (($tokens[$j] ?? null) !== '(') continue;
            // the definition itself is `function userNotify(` — skip it
            $k = $i - 1;
            while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k--;
            if (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_FUNCTION) continue;
            // the fourth argument, at depth 1
            $depth = 0; $arg = 0; $first = null;
            for ($j++; $j < $count; $j++) {
                $t = $tokens[$j];
                if ($t === '(' || $t === '[' || $t === '{') { $depth++; continue; }
                if ($t === ')' || $t === ']' || $t === '}') { if ($depth === 0) break; $depth--; continue; }
                if ($t === ',' && $depth === 0) { $arg++; continue; }
                if ($arg === 3 && $first === null && !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) $first = $t;
            }
            $literal = is_array($first) && in_array($first[0], [T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC], true);
            $request = is_array($first) && $first[0] === T_STRING && $first[1] === '__';
            if ($first === '"' || $literal || $request) {
                $bad[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)) . ':' . $tokens[$i][2];
            }
        }
    }
}
check('no userNotify() in api/ or includes/ writes a literal title or one in the request\'s language (__())', $bad === [], implode(' | ', $bad));

/* ══ 3. a friend request, as requests: their language, its sender, one per unread request (PUB-5) ══ */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nrt_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmp[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
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
langInit($cfg, $a["lang"]);
require "api/" . $a["endpoint"] . ".php";
');
$people = function (int $as, string $op, string $user, string $lang = 'en') use ($root, $runner, $cfgOn, &$tmp): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nrt_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmp[] = $arg;
    file_put_contents($arg, json_encode(['root' => $root, 'endpoint' => 'user_people', 'method' => 'POST', 'get' => [],
        'post' => ['csrf_token' => 'nrt-token', 'op' => $op, 'user' => $user], 'ip' => '127.0.0.73', 'lang' => $lang,
        'includes' => NRT_INCLUDES, 'cfg' => array_intersect_key($cfgOn, array_flip(['users_enabled', 'users_require_email_verify', 'friends_enabled', 'default_language'])),
        'session' => ['user_id' => $as, 'user_login_time' => time(), 'csrf_token' => 'nrt-token'], 'sid' => 'nrt' . bin2hex(random_bytes(8))]));
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'xdebug.mode=off', $runner, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    foreach ($pipes as $h) fclose($h);
    proc_close($p);
    $j = json_decode(trim($out), true);
    return (is_array($j) ? $j : ['__raw' => substr($out . ' ' . $err, 0, 300)]) + ['__status' => preg_match('/STATUS:(\d+)/', $err, $m) ? ((int)$m[1] ?: 200) : 0];
};
$f = $floor();
$r = $people($en, 'follow', 'nrt_pl', 'en');
$req = array_values(array_filter($notes($pl, $f), fn($x) => $x['type'] === 'friend_request'));
check('an English request asks a Polish account to be friends: the request is written in POLISH, and says whose it is',
      ($r['success'] ?? false) === true && count($req) === 1 && $req[0]['title'] === langFor('pl', 'notify.friend_request', ['user' => 'nrt_en'])
      && (int)$req[0]['sender_id'] === $en, json_encode([$r, $req], JSON_UNESCAPED_UNICODE));
$people($en, 'unfollow', 'nrt_pl'); $people($en, 'follow', 'nrt_pl');
$people($en, 'unfollow', 'nrt_pl'); $people($en, 'follow', 'nrt_pl');
$req = array_values(array_filter($notes($pl, $f), fn($x) => $x['type'] === 'friend_request'));
check('PUB-5: follow, unfollow, follow, unfollow, follow — still ONE request, while the first is unread',
      count($req) === 1 && (int)$db->query("SELECT COUNT(*) FROM user_friends WHERE user_id = $en AND friend_id = $pl AND status = 'pending'")->fetchColumn() === 1,
      json_encode($req, JSON_UNESCAPED_UNICODE));
$db->exec("UPDATE user_notifications SET read_at = NOW() WHERE user_id = $pl AND type = 'friend_request'");
$people($en, 'unfollow', 'nrt_pl'); $people($en, 'follow', 'nrt_pl');
check('… once it is read, a new request is news again', count(array_filter($notes($pl, $f), fn($x) => $x['type'] === 'friend_request')) === 2);
$f2 = $floor();
$r = $people($pl, 'accept', 'nrt_en', 'pl');
$acc = $notes($en, $f2);
check('the Polish account accepts, from a Polish page: the English account is told in ENGLISH, by whom',
      ($r['state'] ?? '') === 'friends' && count($acc) === 1 && $acc[0]['type'] === 'friend_accepted'
      && $acc[0]['title'] === 'nrt_pl accepted your friend request' && (int)$acc[0]['sender_id'] === $pl, json_encode([$r, $acc], JSON_UNESCAPED_UNICODE));

/* ══ 4. PERF-10: memberships remembered for the request ══════════════════ */
userPermissionsForget();
$g1 = array_column(userGroups($db, $none), 'slug');
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) VALUES (?, ?, '2000-01-01 00:00:00')")->execute([$none, $gPremium]);
$g2 = array_column(userGroups($db, $none), 'slug');
userPermissionsForget($none);
$g3 = array_column(userGroups($db, $none), 'slug');
check('userGroups() is remembered for the request (a row written behind its back is not seen) — and forgotten with the permission memo',
      $g1 === ['nrt_member'] && $g2 === $g1 && in_array('nrt_premium', $g3, true) && count($g3) === 2, json_encode([$g1, $g2, $g3]));
$cols = array_keys(userGroups($db, $none)[0] ?? []);
check('… the columns its callers read and no others, the highest priority first then by name',
      $cols === ['id', 'slug', 'name', 'description', 'color', 'priority', 'is_default', 'is_system', 'permissions', 'granted_at', 'expires_at', 'granted_by', 'note'],
      json_encode($cols));
userRevokeGroup($db, $none, $gPremium, false);
check('… and a revoke forgets it by itself', array_column(userGroups($db, $none), 'slug') === ['nrt_member']);

/* ══ 5. PUB-1: what a confirmation carries ═══════════════════════════════ */
$free = ['FREEname Mallory', 'FREErep Somebody', 'FREEcompany Ltd', 'FREEtitle Buy pills', 'FREEmessage visit evil.example now',
         'magnet:?xt=urn:btih:' . $H . '&dn=FREEmagnet', '203.0.113.250'];
$report = ['id' => 4242, 'name' => $free[0], 'representative' => $free[1], 'company' => $free[2], 'objectTitle' => $free[3], 'add_message' => $free[4],
           'magnet_link' => $free[5], 'ip' => $free[6], 'email' => 'victim@example.org', 'infoHash' => $H, 'timestamp' => '2026-10-06 12:00:00',
           'checked' => 0, 'blocked' => 0];
$cfgMail = array_merge($cfgOn, ['site_url' => 'https://tracker.example.org/', 'site_name' => 'Tracker', 'hmac_secret' => 'nrt-secret']);
foreach (['en', 'pl'] as $lang) {
    $m = mailSubmissionConfirmationParts($report, $cfgMail, $lang);
    $all = $m['subject'] . "\n" . $m['plain'] . "\n" . $m['html'];
    $leaks = array_values(array_filter(['FREEname', 'FREErep', 'FREEcompany', 'FREEtitle', 'FREEmessage', 'FREEmagnet', '203.0.113.250'], fn($w) => stripos($all, $w) !== false));
    check("PUB-1 ($lang): a report's confirmation carries NOTHING the sender typed — no name, representative, company, title, message, magnet, address",
          $leaks === [], implode(',', $leaks));
    check("… ($lang) only that it was received: its number, the info hash, the state, where to check it — and \"not you? ignore it\"",
          str_contains($m['subject'], '4242') && str_contains($m['plain'], $H) && str_contains($m['html'], $H)
          && str_contains($m['plain'], 'https://tracker.example.org/?action=status') && str_contains($m['html'], 'href="https://tracker.example.org/?action=status"')
          && str_contains($m['plain'], langFor($lang, 'mail.not_you')) && str_contains($m['plain'], langFor($lang, 'mail.st_awaiting'))
          && $m['subject'] === langFor($lang, 'mail.sub_subject', ['id' => 4242]), $m['subject']);
}
$appeal = ['id' => 77, 'name' => 'FREEappellant', 'email' => 'FREEmailbox@example.org', 'message' => 'FREEreason a long story', 'appeal_type' => 'unblock',
           'infoHash' => $H, 'timestamp' => '2026-10-06 12:00:00'];
foreach (['en', 'pl'] as $lang) {
    $m = mailAppealConfirmationParts($appeal, $cfgMail, $lang);
    // The one place the address may be: the unsubscribe link, which is the address holder's own way out.
    $all = str_replace([$m['unsubscribe'], htmlspecialchars($m['unsubscribe'], ENT_QUOTES, 'UTF-8')], '', $m['subject'] . "\n" . $m['plain'] . "\n" . $m['html']);
    check("PUB-1 ($lang): an appeal's confirmation carries no name, no reason, no address — the hash, the kind, the state",
          stripos($all, 'FREEappellant') === false && stripos($all, 'FREEreason') === false && stripos($all, 'FREEmailbox') === false
          && str_contains($m['plain'], $H) && str_contains($m['plain'], langFor($lang, 'mail.app_type_unblock'))
          && str_contains($m['subject'], langFor($lang, 'mail.app_type_unblock')), $m['subject']);
}
$mPl = mailSubmissionConfirmationParts($report, $cfgMail, 'pl');
check('… and its frame — the button line, the preferences link, the language of the page — in the mail\'s language',
      str_contains($mPl['html'], htmlspecialchars(langFor('pl', 'mail.manage_prefs'), ENT_QUOTES, 'UTF-8')) && str_contains($mPl['html'], '<html lang="pl">')
      && !str_contains($mPl['html'], 'Manage notification preferences'));

/* ══ 6. v93: the friend notifications written before their sender was recorded ══ */
$f = $floor();
$ins = $db->prepare("INSERT INTO user_notifications (user_id, type, title) VALUES (?, ?, ?)");
$ins->execute([$pl, 'friend_request', langFor('en', 'notify.friend_request', ['user' => 'nrt_en'])]);        // English, sender here
$ins->execute([$pl, 'friend_accepted', langFor('pl', 'notify.friend_accepted', ['user' => 'nrt_none'])]);   // Polish, sender here
$ins->execute([$pl, 'friend_request', langFor('pl', 'notify.friend_request', ['user' => 'nrt_long_gone'])]); // nobody has that name
$ins->execute([$pl, 'friend_request', 'Ein Satz in einer Sprache, die es hier nicht gibt']);                   // unreadable
$res = schemaNotifySenders($db, $cfgOn);
$after = $db->query("SELECT title, sender_id FROM user_notifications WHERE user_id = $pl AND id > $f ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('v93: a request written in English and an acceptance written in Polish get their senders from their titles',
      count($after) === 3 && (int)$after[0]['sender_id'] === $en && (int)$after[1]['sender_id'] === $none, json_encode($after, JSON_UNESCAPED_UNICODE));
check('… one from an account no longer here is deleted; a title in no installed language is left alone',
      $res['linked'] >= 2 && $res['deleted'] >= 1 && $res['unread'] >= 1 && $after[2]['sender_id'] === null
      && !(int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = $pl AND title LIKE '%nrt_long_gone%'")->fetchColumn(), json_encode($res));
$again = schemaNotifySenders($db, $cfgOn);
check('… and a second run changes nothing it already did (only rows still without a sender)', $again['linked'] === 0 && $again['deleted'] === 0, json_encode($again));
check('the column and both keys exist on this database (the migration ran)', schemaColumnExists($db, 'user_notifications', 'sender_id')
      && schemaIndexExists($db, 'user_notifications', 'idx_un_sender') && schemaIndexExists($db, 'user_notifications', 'idx_un_link'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
