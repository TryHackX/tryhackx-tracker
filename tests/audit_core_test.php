<?php
/**
 * The core of the 1.56.x audit: who is still allowed in, and which session survives which stamp.
 *   php tests/audit_core_test.php          (needs the local test database)
 *
 * The HTTP half — the endpoints that apply these answers — is tests/audit_core_test.py.
 *
 * ── the five facts this file exists to hold down ───────────────────────────────────────────────
 *
 * 1. A BAN WITH A DATE ENDS ON THAT DATE. userIsActive() is the one reader of `status` +
 *    `banned_until`, and nothing waits for the janitor to tidy the columns. The dangerous direction
 *    is the other one: a ban with NO date must never read as "expired", because NULL is not a
 *    moment in the past — it is "until somebody lifts it".
 *
 * 2. "SIGN OUT EVERYWHERE" REACHES THE PANEL. A panel session opened through an account rides on
 *    that account; the stamp that ends the account's other sessions has to end it too, in
 *    adminSessionValid() (the panel's own request) and in currentUser() (the site's). A stamp that
 *    closed the public session and left the panel open would leave the one browser the person is
 *    worried about holding the panel until the idle limit.
 *
 * 3. THE BROWSER THAT ASKED KEEPS WALKING BACK IN. Signing the others out spends the cookie this
 *    browser holds (it was born before the stamp, so the remember path would refuse it) and issues
 *    a fresh one with the SAME deadline. Rotation, not renewal: "sign out everywhere else" is not a
 *    way to extend your own cookie by thirty days.
 *
 * 4. AN UNVERIFIED ACCOUNT IS A GUEST EVERYWHERE. Including in the panel — userHasPanelAccess()
 *    asks userEffectivePermissions() WITH the configuration, or an address nobody confirmed would
 *    be a guest on the site and a moderator in the panel.
 *
 * 5. THE PANEL'S OWN SECOND FACTOR IS NOT OPTIONAL FOR AN ACCOUNT SIGN-IN. With it on, an account
 *    that has not armed one of its own gets `panel_needs_2fa` and no panel — the sign-in itself
 *    still works, because refusing that is how a requirement gets switched off.
 *
 * A session is started before the first byte of output: several of the things below are decisions
 * made ABOUT a session, and currentUser() answers null out of hand when there is not one.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
@session_start();

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
require_once $root . '/includes/auth.php';
require_once $root . '/includes/twofa.php';
require_once $root . '/includes/user2fa.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/people.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

/**
 * Run something that issues a cookie. setcookie() is a header, this is a CLI that has already
 * printed, and the warning that follows says nothing about the code under test — the ROW the call
 * writes is what is being checked, and it is written either way.
 */
function quietly(callable $fn) {
    set_error_handler(static function (int $no, string $msg) {
        return str_contains($msg, 'headers already sent') || str_contains($msg, 'Cannot modify header information');
    }, E_WARNING);
    try { return $fn(); } finally { restore_error_handler(); }
}

/** currentUser() answers once per request and remembers; every question here is a new request. */
function forgetCurrentUser(): void {
    $GLOBALS['__current_user_loaded'] = false;
    $GLOBALS['__current_user_cache'] = null;
}

function clearSession(): void {
    $_SESSION = [];
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
$GLOBALS['cfg'] = $cfg;      // userHasPanelAccess()/userMaybeOpenPanelSession() read the global

check('schema knows when the other sessions stopped counting', schemaColumnExists($db, 'users', 'sessions_valid_from'));
check('… and when a timed ban runs out', schemaColumnExists($db, 'users', 'banned_until'));

// ─────────────────────────────────────────────────────────────────────────────
// 1. userIsActive(): the one reader of status + banned_until
// ─────────────────────────────────────────────────────────────────────────────
$yesterday = date('Y-m-d H:i:s', time() - 86400);
$tomorrow  = date('Y-m-d H:i:s', time() + 86400);

check('an active account is active', userIsActive(['status' => 'active', 'banned_until' => null]));
check('… and stays active even with a stale date beside it — status decides first',
    userIsActive(['status' => 'active', 'banned_until' => $tomorrow]));
// THE one that must not read as "expired": NULL is not a moment in the past, it is "until somebody
// lifts it", and strtotime(null) would have been today at midnight.
check('a ban with NO date is a ban, not an expired one',
    !userIsActive(['status' => 'banned', 'banned_until' => null]));
check('… and an empty string is not a date either',
    !userIsActive(['status' => 'banned', 'banned_until' => '']));
check('a ban whose date has passed is over, without waiting for the janitor',
    userIsActive(['status' => 'banned', 'banned_until' => $yesterday]));
check('a ban whose date is still ahead holds',
    !userIsActive(['status' => 'banned', 'banned_until' => $tomorrow]));
check('anything that is neither active nor banned is not active',
    !userIsActive(['status' => 'pending']) && !userIsActive(['status' => 'deleted', 'banned_until' => $yesterday])
    && !userIsActive([]));

// ─────────────────────────────────────────────────────────────────────────────
// fixtures
// ─────────────────────────────────────────────────────────────────────────────
$names = ['audadmin', 'audsess', 'audmod', 'audalice', 'audbob'];
$in = "'" . implode("','", $names) . "'";
$db->exec("DELETE FROM user_twofa WHERE user_id IN (SELECT id FROM users WHERE username IN ($in))");
$db->exec("DELETE FROM user_tokens WHERE user_id IN (SELECT id FROM users WHERE username IN ($in))");
$db->exec("DELETE FROM users WHERE username IN ($in)");
$db->exec("DELETE FROM user_groups WHERE slug = 'audpanel'");

$twofaFile   = $root . '/config/admin_2fa.json';
$twofaBackup = is_file($twofaFile) ? (string)file_get_contents($twofaFile) : null;

$ids = [];
foreach ($names as $u) {
    $r = userCreate($db, $cfg, $u, $u . '@example.org', 'SmokePass123!', '127.0.0.1');
    $ids[$u] = isset($r['user']) ? (int)$r['user']['id'] : 0;
}
check('five accounts to test with', count(array_filter($ids)) === 5, json_encode($ids));
$db->exec("UPDATE users SET email_verified = 1 WHERE username IN ('audadmin','audsess','audalice','audbob')");

// A group that carries the panel, made here rather than borrowed: users_test resets the system
// groups to their seed, so a grant left on `member` or `moderator` would be somebody else's state.
$db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions)
              VALUES ('audpanel', 'Audit Panel', 'tests/audit_core_test.php', '', 400, 0, 0, ?)")
   ->execute([json_encode(['panel.access' => true, 'panel.users.edit' => true])]);
$panelGroup = userGroupBySlug($db, 'audpanel');
$adminGroup = userGroupBySlug($db, 'admin');
check('the fixture group and the admin group both exist', $panelGroup !== null && $adminGroup !== null);

try {
    userGrantGroup($db, $ids['audadmin'], (int)$adminGroup['id'], null, 'audit test', '', false);
    userGrantGroup($db, $ids['audmod'], (int)$panelGroup['id'], null, 'audit test', '', false);

    // ─────────────────────────────────────────────────────────────────────────
    // 2. adminSessionValid(): the stamp reaches a panel session opened through an account
    // ─────────────────────────────────────────────────────────────────────────
    $uidA = $ids['audadmin'];
    $openPanel = static function (int $uid, int $t): void {
        $_SESSION = ['loggedin' => true, 'admin_via_user' => $uid, 'login_time' => $t, 'last_activity' => $t];
    };
    $t0 = time();
    $db->prepare("UPDATE users SET sessions_valid_from = 0 WHERE id = ?")->execute([$uidA]);
    $openPanel($uidA, $t0);
    check('a panel session opened through an admin-group account is valid', adminSessionValid($cfg) === true);
    check('… and is still holding its keys afterwards',
        !empty($_SESSION['loggedin']) && (int)$_SESSION['admin_via_user'] === $uidA);

    // The boundary itself: "everything OLDER than this instant is over" must not include the
    // session stamped AT that instant — userSignOutOthers() writes the same number into both.
    $db->prepare("UPDATE users SET sessions_valid_from = ? WHERE id = ?")->execute([$t0, $uidA]);
    $openPanel($uidA, $t0);
    check('a stamp equal to the panel login instant keeps it — that session IS the survivor',
        adminSessionValid($cfg) === true);

    $db->prepare("UPDATE users SET sessions_valid_from = ? WHERE id = ?")->execute([$t0 + 10, $uidA]);
    $openPanel($uidA, $t0);
    check('a stamp NEWER than the panel login closes the panel', adminSessionValid($cfg) === false);
    check('… and the panel keys are gone, not merely reported as invalid',
        !isset($_SESSION['admin_via_user']) && !isset($_SESSION['loggedin'])
        && !isset($_SESSION['login_time']) && !isset($_SESSION['last_activity']),
        json_encode(array_keys($_SESSION)));

    // A banned account loses the panel on its next request too — the same block, the other clause.
    $db->prepare("UPDATE users SET sessions_valid_from = 0, status = 'banned', banned_until = NULL WHERE id = ?")->execute([$uidA]);
    $openPanel($uidA, $t0);
    check('a ban closes it on the next panel request', adminSessionValid($cfg) === false);
    // …and the reason that clause asks userIsActive() rather than reading `status` itself: a ban
    // whose date has passed is over, and the janitor that tidies the column runs on its own clock.
    $db->prepare("UPDATE users SET banned_until = NOW() - INTERVAL 1 DAY WHERE id = ?")->execute([$uidA]);
    $openPanel($uidA, $t0);
    check('a ban that ran out does not close it, whatever the column still says',
        adminSessionValid($cfg) === true,
        (string)$db->query("SELECT CONCAT(status, ' / ', IFNULL(banned_until, 'NULL')) FROM users WHERE id = $uidA")->fetchColumn());
    $db->prepare("UPDATE users SET status = 'active', banned_until = NULL WHERE id = ?")->execute([$uidA]);

    // ─────────────────────────────────────────────────────────────────────────
    // 3. currentUser(): the same stamp, from the site's side, takes the panel with it
    // ─────────────────────────────────────────────────────────────────────────
    $uidS = $ids['audsess'];
    unset($_COOKIE[USER_REMEMBER_COOKIE]);   // nothing to fall back to, or the sweep is invisible
    $db->prepare("UPDATE users SET sessions_valid_from = ? WHERE id = ?")->execute([$t0 + 10, $uidS]);
    $_SESSION = ['user_id' => $uidS, 'user_login_time' => $t0,
                 'loggedin' => true, 'admin_via_user' => $uidS, 'login_time' => $t0, 'last_activity' => $t0];
    forgetCurrentUser();
    check('a session older than the stamp is over', currentUser($db) === null);
    check('… the account keys are gone',
        !isset($_SESSION['user_id']) && !isset($_SESSION['user_login_time']));
    check('… and so is the panel session that rode in on that very account',
        !isset($_SESSION['admin_via_user']) && !isset($_SESSION['loggedin'])
        && !isset($_SESSION['login_time']) && !isset($_SESSION['last_activity']),
        json_encode(array_keys($_SESSION)));

    // The site's side asks userIsActive() too, and for the same reason: the sign-in form lets an
    // expired ban back in (tests/audit_core_test.py), so the session it opens must not be thrown
    // away on the very next page for a column nobody has tidied yet.
    $db->prepare("UPDATE users SET sessions_valid_from = 0, status = 'banned', banned_until = NOW() - INTERVAL 1 DAY WHERE id = ?")->execute([$uidS]);
    $_SESSION = ['user_id' => $uidS, 'user_login_time' => $t0];
    forgetCurrentUser();
    $still = currentUser($db);
    check('a member whose ban ran out keeps the session they just opened',
        $still !== null && (int)$still['id'] === $uidS,
        (string)$db->query("SELECT CONCAT(status, ' / ', IFNULL(banned_until, 'NULL')) FROM users WHERE id = $uidS")->fetchColumn());
    $db->prepare("UPDATE users SET status = 'active', banned_until = NULL WHERE id = ?")->execute([$uidS]);

    // The other direction, so the unset above is aimed rather than indiscriminate: a panel session
    // belonging to somebody ELSE is not this account's to close. The stamp goes back on, or there
    // would be nothing for the sweep to do and the check would pass by accident.
    $db->prepare("UPDATE users SET sessions_valid_from = ? WHERE id = ?")->execute([$t0 + 10, $uidS]);
    $_SESSION = ['user_id' => $uidS, 'user_login_time' => $t0,
                 'loggedin' => true, 'admin_via_user' => $ids['audadmin'], 'login_time' => $t0, 'last_activity' => $t0];
    forgetCurrentUser();
    check('a panel session belonging to another account is left alone',
        currentUser($db) === null && !empty($_SESSION['loggedin'])
        && (int)$_SESSION['admin_via_user'] === $ids['audadmin'], json_encode(array_keys($_SESSION)));

    // ─────────────────────────────────────────────────────────────────────────
    // 4. userSignOutOthers(): the kept cookie is spent and reissued with its own deadline
    // ─────────────────────────────────────────────────────────────────────────
    $db->prepare("UPDATE users SET sessions_valid_from = 0 WHERE id = ?")->execute([$uidS]);
    $db->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$uidS]);
    $mine  = bin2hex(random_bytes(32));
    $keep  = hash('sha256', $mine);
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at, ip, ua)
                  VALUES (?, 'remember', ?, NOW() + INTERVAL 30 DAY, '203.0.113.9', 'this browser')")
       ->execute([$uidS, $keep]);
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at, ip, ua)
                  VALUES (?, 'remember', ?, NOW() + INTERVAL 30 DAY, '198.51.100.9', 'the other one')")
       ->execute([$uidS, hash('sha256', bin2hex(random_bytes(32)))]);
    $deadline = (int)$db->query("SELECT UNIX_TIMESTAMP(expires_at) FROM user_tokens WHERE token_hash = '$keep'")->fetchColumn();

    $_COOKIE[USER_REMEMBER_COOKIE] = $uidS . '.' . $mine;
    $_SESSION = ['user_id' => $uidS, 'user_login_time' => $t0 - 600,
                 'loggedin' => true, 'admin_via_user' => $uidS, 'login_time' => $t0 - 600, 'last_activity' => $t0];
    $gone = quietly(static fn() => userSignOutOthers($db, $uidS, true));
    check('the other device is signed out', $gone === 1, (string)$gone);

    $rows = $db->query("SELECT token_hash, used_at, UNIX_TIMESTAMP(expires_at) AS exp
                          FROM user_tokens WHERE user_id = $uidS AND type = 'remember' ORDER BY id")
               ->fetchAll(PDO::FETCH_ASSOC);
    $old  = array_values(array_filter($rows, static fn($r) => $r['token_hash'] === $keep));
    $new  = array_values(array_filter($rows, static fn($r) => $r['token_hash'] !== $keep));
    check('the cookie this browser holds is spent, not left live',
        count($old) === 1 && $old[0]['used_at'] !== null, json_encode($rows));
    check('… and a fresh one was issued in its place', count($new) === 1 && $new[0]['used_at'] === null,
        json_encode(array_column($rows, 'token_hash')));
    // Rotation, not renewal: the replacement inherits the ORIGINAL deadline. Otherwise the button
    // that ends everybody else's sessions would silently extend your own cookie by thirty days.
    check('… carrying the same deadline the original had',
        count($new) === 1 && (int)$new[0]['exp'] === $deadline,
        json_encode([(int)($new[0]['exp'] ?? 0), $deadline]));
    check('this session survives its own sweep',
        (int)$_SESSION['user_login_time'] >= $t0 && abs((int)$_SESSION['user_login_time'] - time()) < 5,
        (string)($_SESSION['user_login_time'] ?? 0));
    check('… and so does the panel session riding on the same account',
        (int)$_SESSION['login_time'] === (int)$_SESSION['user_login_time'],
        json_encode([$_SESSION['login_time'] ?? 0, $_SESSION['user_login_time'] ?? 0]));
    // The stamp the sweep wrote and the login time it re-stamped are the same instant, which is the
    // only reason the two checks above and adminSessionValid()'s `<=` agree with each other.
    $stamp = (int)$db->query("SELECT sessions_valid_from FROM users WHERE id = $uidS")->fetchColumn();
    check('the account carries that instant too', $stamp === (int)$_SESSION['user_login_time'],
        json_encode([$stamp, $_SESSION['user_login_time'] ?? 0]));

    unset($_COOKIE[USER_REMEMBER_COOKIE]);
    clearSession();

    // ─────────────────────────────────────────────────────────────────────────
    // 5. userHasPanelAccess(): the e-mail verification gate applies to the panel too
    // ─────────────────────────────────────────────────────────────────────────
    $uidM = $ids['audmod'];
    $GLOBALS['cfg'] = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '1']);
    check('an UNVERIFIED account in a panel group holds no panel access',
        userHasPanelAccess($db, $uidM) === false);
    $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$uidM]);
    check('… and holds it once the address is confirmed', userHasPanelAccess($db, $uidM) === true);
    // Not a test of the group: an install that does not ask for verification never applied the gate,
    // and this must not have quietly become "verified or nothing".
    $db->prepare("UPDATE users SET email_verified = 0 WHERE id = ?")->execute([$uidM]);
    $GLOBALS['cfg'] = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0']);
    check('… and on a site that does not ask for verification, the group alone is enough',
        userHasPanelAccess($db, $uidM) === true);
    $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$uidM]);
    $GLOBALS['cfg'] = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '1']);

    // ─────────────────────────────────────────────────────────────────────────
    // 6. userMaybeOpenPanelSession(): the PANEL's own TOTP is not a thing an account sign-in skips
    // ─────────────────────────────────────────────────────────────────────────
    @unlink($twofaFile);
    $modUser = userFindById($db, $uidM);
    check('the panel has no second factor of its own to begin with', twofaEnabled() === false);

    clearSession();
    userMaybeOpenPanelSession($db, $modUser);
    check('with the panel TOTP off, an account that may open the panel gets a panel session',
        !empty($_SESSION['loggedin']) && (int)($_SESSION['admin_via_user'] ?? 0) === $uidM
        && !isset($_SESSION['panel_needs_2fa']), json_encode(array_keys($_SESSION)));

    file_put_contents($twofaFile, json_encode([
        'enabled' => true, 'secret' => twofaBase32Encode(random_bytes(20)), 'recovery' => [],
        'last_step' => 0, 'confirmed_at' => time(), 'pending' => null,
    ]));
    check('the panel TOTP is on for the next two checks', twofaEnabled() === true);

    clearSession();
    userMaybeOpenPanelSession($db, $modUser);
    check('with it on, an account with no factor of its own is told to arm one — and gets no panel',
        !empty($_SESSION['panel_needs_2fa']) && empty($_SESSION['loggedin'])
        && !isset($_SESSION['admin_via_user']), json_encode(array_keys($_SESSION)));

    // …and an account that DID show a factor in this very sign-in is not asked for the owner's.
    $setup = user2faBeginSetup($db, array_merge($cfg, ['site_name' => 'Test Tracker']), $modUser);
    $armed = user2faConfirmSetup($db, $uidM, twofaCodeAt((string)$setup['secret'], time()));
    check('the account arms a factor of its own', ($armed['ok'] ?? false) === true && user2faEnabled($db, $uidM),
        json_encode($armed['error'] ?? ''));
    clearSession();
    userMaybeOpenPanelSession($db, $modUser);
    check('… and an armed member factor opens the panel with the panel TOTP still on',
        !empty($_SESSION['loggedin']) && (int)($_SESSION['admin_via_user'] ?? 0) === $uidM
        && !isset($_SESSION['panel_needs_2fa']), json_encode(array_keys($_SESSION)));
    user2faDisable($db, $uidM);
    @unlink($twofaFile);
    clearSession();

    // ─────────────────────────────────────────────────────────────────────────
    // 7. pmUnreadCount() / pmInboxStamp(): a counterpart nobody can open is not a number
    // ─────────────────────────────────────────────────────────────────────────
    $a = $ids['audalice']; $b = $ids['audbob'];
    $thread = pmThreadFor($db, $a, $b);
    check('the two have a thread', $thread !== null);
    $tid = (int)$thread['id'];
    $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, 'one line', 'bbcode')")
       ->execute([$tid, $b]);
    $db->prepare("UPDATE message_threads SET last_message_at = NOW() WHERE id = ?")->execute([$tid]);

    check('a waiting message is counted', pmUnreadCount($db, $a) === 1, (string)pmUnreadCount($db, $a));
    check('… and the inbox has a moment to compare against', pmInboxStamp($db, $a) !== '');
    $db->prepare("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = ?")->execute([$b]);
    // A badge that nothing on the page can clear is the bug this rule was written to remove: the
    // listing hides the row, so the number beside it must go with it.
    check('a banned counterpart stops being a number', pmUnreadCount($db, $a) === 0, (string)pmUnreadCount($db, $a));
    check('… and stops moving the inbox stamp', pmInboxStamp($db, $a) === '', pmInboxStamp($db, $a));
    $db->prepare("UPDATE users SET status = 'active', banned_until = NULL WHERE id = ?")->execute([$b]);
    check('and it all comes back when the account does',
        pmUnreadCount($db, $a) === 1 && pmInboxStamp($db, $a) !== '');

} finally {
    // ── tidy up ──────────────────────────────────────────────────────────────
    clearSession();
    unset($_COOKIE[USER_REMEMBER_COOKIE]);
    $idList = implode(',', array_map('intval', array_filter($ids))) ?: '0';
    $db->exec("DELETE FROM user_messages WHERE thread_id IN (SELECT id FROM message_threads WHERE u_low IN ($idList) OR u_high IN ($idList))");
    $db->exec("DELETE FROM message_threads WHERE u_low IN ($idList) OR u_high IN ($idList)");
    $db->exec("DELETE FROM user_twofa WHERE user_id IN ($idList)");
    $db->exec("DELETE FROM user_tokens WHERE user_id IN ($idList)");
    $db->exec("DELETE FROM user_notifications WHERE user_id IN ($idList)");
    $db->exec("DELETE FROM user_group_members WHERE user_id IN ($idList)");
    $db->exec("DELETE FROM users WHERE id IN ($idList)");
    $db->exec("DELETE FROM user_groups WHERE slug = 'audpanel'");
    if ($twofaBackup !== null) file_put_contents($twofaFile, $twofaBackup); else @unlink($twofaFile);
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. the source itself, where the behaviour lives in a browser or in a header
// ─────────────────────────────────────────────────────────────────────────────
$peopleJs = (string)file_get_contents($root . '/assets/js/people.js');
// A poll that keeps asking after the session ended is a request per tick, for ever, from every tab
// somebody left open — and every one of them answers 401.
check('the inbox poll stops itself when the session has gone',
    str_contains($peopleJs, "if (j.error === 'login_required') stopInbox();"));
check('… and so does the open conversation',
    str_contains($peopleJs, "if (j.error === 'login_required') stopPoll();"));
// The list that comes back belongs to the view that asked for it: switching tabs mid-request used
// to draw the answer to the previous question over the new one.
check('a people listing knows which view asked for it',
    str_contains($peopleJs, 'var loadSeq = 0;') && str_contains($peopleJs, 'var forView = view, my = ++loadSeq;')
    && str_contains($peopleJs, 'if (my !== loadSeq || forView !== view) return;'));
// An empty inbox has an empty stamp, and that IS a baseline.
check('the inbox baseline is a string, so "nothing yet" is still an answer',
    str_contains($peopleJs, "inboxStamp = String(j.stamp || '');"));

$appJs = (string)file_get_contents($root . '/assets/js/app.js');
check('the pulse lease is kept per account', str_contains($appJs, "const KEY = 'thx_pulse:'"));
$logoutAt = strpos($appJs, "\$id('account-logout')");
$leaseAt  = strpos($appJs, "startsWith('thx_pulse')");
check('… and dropped on sign-out, in the logout handler itself',
    $logoutAt !== false && $leaseAt !== false && $leaseAt > $logoutAt && ($leaseAt - $logoutAt) < 600,
    json_encode([$logoutAt, $leaseAt]));
$closeInfoAt = strpos($appJs, 'function closeInfo()');
$disconnectAt = $closeInfoAt !== false ? strpos($appJs, 'infoFilesObserver.disconnect()', $closeInfoAt) : false;
check('closing the Info panel drops its file observer',
    $closeInfoAt !== false && $disconnectAt !== false && ($disconnectAt - $closeInfoAt) < 800,
    json_encode([$closeInfoAt, $disconnectAt]));
check('a probe that ran out of time says so', str_contains($appJs, 'probe_timed_out'));

$nav = (string)file_get_contents($root . '/templates/nav.php');
check('the badge carries the account id the lease is keyed on', str_contains($nav, 'data-uid="'));

// The panel polls these while a helper forks; PHP serialises one browser's requests on the session
// file, so a status card that holds the lock stalls every other request the page makes.
foreach (['whitelist_status', 'index_status', 'net_status', 'backup_status',
          'ot_status', 'tracker_service_status', 'sysctl_status', 'ot_cluster_status'] as $ep) {
    $src = @file_get_contents($root . "/api/admin/$ep.php");
    check("admin/$ep releases the session lock", is_string($src) && str_contains($src, 'session_write_close()'));
}

$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('the two ceilings this audit asked about are clamped, not refused',
    str_contains($save, "'fav_max_per_user' => [10, 5000, 500]")
    && str_contains($save, "'auth_bridge_ttl' => [30, 900, 120]"));

// The session this file opened was never a browser's: take the file on disk with it.
quietly(static fn() => session_destroy());

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
