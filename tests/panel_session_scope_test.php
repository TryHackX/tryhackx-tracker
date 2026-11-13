<?php
/**
 * A panel session opened THROUGH an account is that account on the site (1.74.0 — AUTH-1, PRIV-6, PANEL-3, PRIV-5):
 *   php tests/panel_session_scope_test.php
 *
 * Until 1.74.0 userCan() began `if (isLoggedIn()) return true;` — so ANY panel session passed every check on the
 * public pages. The owner's own session is meant to (there is one owner and the site is theirs), but a session
 * that userMaybeOpenPanelSession() opened for a moderator — `loggedin` + `admin_via_user` — passed them too: a
 * moderator whose group gave `panel.access` and one queue could delete anybody's emotes, read every profile, star,
 * make lists, stream the uncropped source of anybody's picture, while panelCan() in the panel refused them. Pinned:
 *
 *   1. userCan() for each kind of session — the owner's (everything), an account's through the panel (exactly what
 *      its groups give; the Admin group's blanket), and the session userMaybeOpenPanelSession() really writes;
 *   2. signing in as ANOTHER account in the same browser ends a panel session that rode in on the previous one;
 *   3. PANEL-3: the uncropped source of a picture — to its owner and the owner's / an administrator's panel
 *      session, never a moderator's (api/user_media.php, as a request);
 *   4. the [hide] tag's "signed in": the owner's panel session, or an account that is signed in;
 *   5. PRIV-6: the reader gates ask the READER's account — a moderator-only account is refused the profile (the
 *      same bytes as a name nobody has), somebody's favourites, lists and uploads, the directory and "who has
 *      this", as requests; a member is not;
 *   6. PRIV-5: a consent (uploads.public) is the account's GRANT — never userCan(), never the Admin blanket: no
 *      `userCan(…, <consent id>)` anywhere in the code, and the whitelist page offers the checkbox only to an
 *      account whose group grants it.
 *
 * Self-cleaning: its groups, accounts, picture row and favourites are removed at the end. Nothing in the settings
 * table is written — every switch a request needs is set in that request's memory.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
// A session of this process's own, opened before anything is written out: userSessionStart() regenerates its id.
ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_start();

// What api.php loads, in its order — the same functions every endpoint below finds.
const PSS_INCLUDES = ['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
    'includes/whitelist.php', 'includes/schedule.php', 'includes/stats_timeline.php', 'includes/index.php', 'includes/api_auth.php',
    'includes/auth.php', 'includes/twofa.php', 'includes/bulkmail.php', 'includes/richtext.php', 'includes/livesync.php',
    'includes/reputation.php', 'includes/wlmaint.php', 'includes/wlprobe.php', 'includes/mail.php', 'includes/users.php',
    'includes/favourites.php', 'includes/sounds.php', 'includes/shout.php', 'includes/usermedia.php', 'includes/profilebio.php',
    'includes/profilevotes.php', 'includes/profiledescs.php', 'includes/comments.php', 'includes/reports.php', 'includes/antispam.php',
    'includes/lists.php', 'includes/who.php', 'includes/people.php', 'includes/user2fa.php', 'includes/authbridge.php',
    'includes/audit.php', 'includes/lang.php'];
foreach (PSS_INCLUDES as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1',
    'fav_enabled' => '1', 'fav_public_enabled' => '1', 'fav_who_enabled' => '1', 'lists_enabled' => '1', 'lists_public_enabled' => '1',
    'directory_enabled' => '1', 'friends_enabled' => '1', 'pm_enabled' => '1', 'avatars_enabled' => '1',
    'wl_submitter_public' => '1', 'tracker_mode' => 'whitelist', 'whitelist_submit_mode' => 'users', 'whitelist_public_enabled' => '1',
]);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfgOn;
langInit([], 'en');
$H = str_repeat('e4e4', 10);

// ── fixtures ─────────────────────────────────────────────────────────────────────────────────────
$clean = function () use ($db, $H): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'psst\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'psst\\_%'");
    $db->prepare("DELETE FROM user_favourites WHERE info_hash = ?")->execute([$H]);
    $db->exec("DELETE FROM user_media WHERE sha1 LIKE 'e4e4e4e4%'");
};
$clean();
$tmp = [];
register_shutdown_function(function () use ($clean, &$tmp) { $clean(); foreach ($tmp as $f) @unlink($f); });

$group = function (string $slug, array $perms) use ($db): int {
    $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES (?, ?, '', '', 2, 0, 0, ?)")
       ->execute([$slug, $slug, json_encode(array_fill_keys($perms, true), JSON_UNESCAPED_SLASHES)]);
    return (int)$db->lastInsertId();
};
/** A verified, active account in exactly these groups, granted in the past. */
$account = function (string $name, array $gids) use ($db, $cfgOn): int {
    $r = userCreate($db, $cfgOn, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?")->execute([$id]);
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
    foreach ($gids as $g) userGrantGroup($db, $id, $g, null, 'test', 'panel_session_scope_test', false, '2000-01-01 00:00:00');
    return $id;
};
// A narrow panel group of the kind the presets invite (reviewer/curator/auditor): the panel and one queue, nothing public.
$gNarrow = $group('psst_narrow', ['panel.access', 'panel.reports.view']);
// What a member reads the site with — and nothing a moderator does.
$gMember = $group('psst_member', ['index.view', 'favourites.use', 'favourites.view_others', 'favourites.public', 'directory.view',
                                  'lists.use', 'lists.public', 'friends.use', 'whitelist.view', 'whitelist.add']);
$gUploads = $group('psst_uploads', ['uploads.public']);
$gAdmin = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$narrow = $account('psst_narrow', [$gNarrow]);               // a moderator who is nothing else
$both   = $account('psst_both', [$gNarrow, $gMember]);       // a moderator who is also a member
$admin  = $account('psst_admin', [$gAdmin]);                 // the Admin group alone
$owner  = $account('psst_owner', [$gMember, $gUploads]);     // whose profile everybody reads
$plain  = $account('psst_plain', [$gMember]);                // an ordinary member reader
check('fixtures: five accounts, three groups of this run and the Admin group',
      $narrow > 0 && $both > 0 && $admin > 0 && $owner > 0 && $plain > 0 && $gAdmin > 0 && $gNarrow > 0 && $gMember > 0);
$db->prepare("UPDATE users SET fav_public = 1, fav_listed = 1, profile_listed = 1 WHERE id = ?")->execute([$owner]);
$db->prepare("INSERT INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$owner, $H]);

$viaSession = fn(int $uid, bool $member = true): array => ['loggedin' => true, 'login_time' => time(), 'last_activity' => time(), 'admin_via_user' => $uid]
                                                            + ($member ? ['user_id' => $uid, 'user_login_time' => time()] : []);
$ownerSession = ['loggedin' => true, 'login_time' => time(), 'last_activity' => time()];
$as = function (array $s): void {
    foreach (array_keys($_SESSION) as $k) unset($_SESSION[$k]);
    foreach ($s as $k => $v) $_SESSION[$k] = $v;
    $GLOBALS['__current_user_cache'] = null; $GLOBALS['__current_user_loaded'] = false;
    userPermissionsForget();
};

/* ══ 1. userCan(), per kind of session (AUTH-1) ═════════════════════════ */
$as($viaSession($narrow));
check('a moderator\'s panel session (panel.access + one queue, nothing public): NOT shout.moderate on the site',
      userCan($db, $cfgOn, 'shout.moderate') === false);
check('… nor an id nobody holds, nor favourites.view_others, nor directory.view',
      !userCan($db, $cfgOn, 'no.such.permission') && !userCan($db, $cfgOn, 'favourites.view_others') && !userCan($db, $cfgOn, 'directory.view'));
check('… while the panel itself still gives it its queue, and nothing else', panelCan($db, $cfgOn, 'panel.reports.view') && !panelCan($db, $cfgOn, 'panel.users.edit'));
$as($viaSession($both));
check('a moderator who is also a member: what the member group gives (favourites.view_others, directory.view), still not shout.moderate',
      userCan($db, $cfgOn, 'favourites.view_others') && userCan($db, $cfgOn, 'directory.view') && !userCan($db, $cfgOn, 'shout.moderate'));
$as($viaSession($admin));
check('an Admin-group account through the panel: its blanket, as panelCan() reads it — every capability',
      userCan($db, $cfgOn, 'shout.moderate') && userCan($db, $cfgOn, 'favourites.view_others') && panelCan($db, $cfgOn, 'panel.users.edit'));
$as($ownerSession);
check('the owner\'s own panel session (no account behind it): every check, an id nobody holds included',
      userCan($db, $cfgOn, 'shout.moderate') && userCan($db, $cfgOn, 'no.such.permission') && panelCan($db, $cfgOn, 'panel.users.edit'));
// The session userMaybeOpenPanelSession() writes — whatever this install's second-factor rules say about it.
$as(['user_id' => $narrow, 'user_login_time' => time()]);
userMaybeOpenPanelSession($db, userFindById($db, $narrow));
if (!empty($_SESSION['loggedin'])) {
    check('userMaybeOpenPanelSession() opens one for the narrow group, through the account …', (int)($_SESSION['admin_via_user'] ?? 0) === $narrow);
    userPermissionsForget();
    check('… and that very session is refused shout.moderate and every public permission it was never given',
          !userCan($db, $cfgOn, 'shout.moderate') && !userCan($db, $cfgOn, 'favourites.use') && userCan($db, $cfgOn, 'panel.reports.view'));
} else {
    echo "SKIP userMaybeOpenPanelSession(): this install asks a second factor before a panel session ("
       . (!empty($_SESSION['panel_needs_2fa']) ? 'panel_needs_2fa' : 'no session') . ") — the session shape is checked above\n";
}

/* ══ 2. signing in as another account ends the previous account's panel session ══ */
$as($viaSession($narrow));
userSessionStart($db, userFindById($db, $plain), '127.0.0.1');
check('signing in as B in a browser holding A\'s panel session: A\'s panel session is gone, B is signed in',
      empty($_SESSION['loggedin']) && empty($_SESSION['admin_via_user']) && (int)($_SESSION['user_id'] ?? 0) === $plain);
$as($viaSession($narrow, false));
userSessionStart($db, userFindById($db, $narrow), '127.0.0.1');
check('… the same account signing in again keeps its own', !empty($_SESSION['loggedin']) && (int)($_SESSION['admin_via_user'] ?? 0) === $narrow);
$as($ownerSession);
userSessionStart($db, userFindById($db, $plain), '127.0.0.1');
check('… and the owner\'s own panel session (nobody\'s account) stays when a member signs in beside it',
      !empty($_SESSION['loggedin']) && empty($_SESSION['admin_via_user']) && (int)($_SESSION['user_id'] ?? 0) === $plain);

/* ══ 3–6 as requests: each endpoint FILE run in a child process with a session ══ */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pss_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmp[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.71";
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
if (!empty($a["page"])) {
    $baseUrl = "/"; $csrfToken = "pss-token"; $action = $a["action"];
    require $a["page"];
} else {
    require "api/" . $a["endpoint"] . ".php";
}
');
$ask = function (array $session, string $endpoint, array $get = [], string $page = '', string $action = '') use ($root, $runner, $cfgOn, &$tmp): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pss_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmp[] = $arg;
    $cfgX = array_intersect_key($cfgOn, array_flip(['users_enabled', 'users_require_email_verify', 'profiles_enabled', 'fav_enabled',
        'fav_public_enabled', 'fav_who_enabled', 'lists_enabled', 'lists_public_enabled', 'directory_enabled', 'friends_enabled', 'pm_enabled',
        'avatars_enabled', 'wl_submitter_public', 'tracker_mode', 'whitelist_submit_mode', 'whitelist_public_enabled']));
    file_put_contents($arg, json_encode(['root' => $root, 'endpoint' => $endpoint, 'method' => 'GET', 'get' => $get, 'post' => [],
        'includes' => PSS_INCLUDES, 'cfg' => $cfgX, 'session' => $session + ['csrf_token' => 'pss-token'],
        'page' => $page !== '' ? $root . '/' . $page : '', 'action' => $action, 'sid' => 'psst' . bin2hex(random_bytes(8))]));
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'xdebug.mode=off', $runner, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    foreach ($pipes as $h) fclose($h);
    proc_close($p);
    $j = json_decode(trim($out), true);
    // No code set by the file (a stream that just echoes) is the default 200; no STATUS line at all is a crash (0).
    return ['status' => preg_match('/STATUS:(\d+)/', $err, $m) ? ((int)$m[1] ?: 200) : 0, 'json' => is_array($j) ? $j : null, 'raw' => $out,
            'err' => substr($err, 0, 400)];
};

/* ══ 3. PANEL-3: the uncropped source of somebody's picture ══════════════ */
$srcSha = 'e4e4e4e4' . bin2hex(random_bytes(16));
$db->prepare("INSERT INTO user_media (user_id, kind, size, sha1, mime, bytes, width, height, data) VALUES (?, 'avatar_src', NULL, ?, 'image/webp', 4, 1, 1, 'pss!')")
   ->execute([$owner, $srcSha]);
$h16 = substr($srcSha, 0, 16);
$src = fn(array $s) => $ask($s, 'user_media', ['h' => $h16, 's' => '0']);
$r = $src($viaSession($narrow));
check('PANEL-3: a moderator\'s panel session asking for the uncropped source of somebody else\'s picture: 404', $r['status'] === 404, json_encode($r));
$r = $src($ownerSession);
check('… the owner\'s own panel session: 200, the bytes', $r['status'] === 200 && $r['raw'] === 'pss!', json_encode($r['status']));
$r = $src($viaSession($admin));
check('… an Admin-group account\'s panel session: 200', $r['status'] === 200 && $r['raw'] === 'pss!', json_encode($r['status']));
$r = $src(['user_id' => $owner, 'user_login_time' => time()]);
check('… and the picture\'s own owner, signed in: 200', $r['status'] === 200 && $r['raw'] === 'pss!', json_encode($r['status']));
$r = $src(['user_id' => $plain, 'user_login_time' => time()]);
check('… a member who is not its owner: 404', $r['status'] === 404, json_encode($r['status']));

/* ══ 4. [hide]: who is "signed in" ═══════════════════════════════════════ */
$as($ownerSession);
$hideOwner = richtextViewerSignedIn($db);
$as($viaSession($narrow, false));
$hideViaAlone = richtextViewerSignedIn($db);
$as($viaSession($narrow));
$hideViaMember = richtextViewerSignedIn($db);
$as([]);
$hideGuest = richtextViewerSignedIn($db);
check('[hide] opens for the owner\'s panel session and for a signed-in account — not for an account\'s panel session whose account is not signed in, nor a guest',
      $hideOwner && !$hideViaAlone && $hideViaMember && !$hideGuest, json_encode([$hideOwner, $hideViaAlone, $hideViaMember, $hideGuest]));

/* ══ 5. PRIV-6: the reader gates ask the reader's ACCOUNT ═══════════════ */
$mod = $viaSession($narrow);           // the moderator, signed in as itself
$member = ['user_id' => $plain, 'user_login_time' => time()];
$r = $ask($mod, 'user_favourites', ['user' => 'psst_owner', 'files' => '0']);
check('a moderator-only account through the panel: somebody\'s favourites 404', $r['status'] === 404 && ($r['json']['error'] ?? '') === 'not_found', json_encode($r));
$r = $ask($mod, 'user_lists', ['user' => 'psst_owner']);
check('… their lists 404', $r['status'] === 404, json_encode($r['status']));
$r = $ask($mod, 'user_uploads', ['user' => 'psst_owner']);
check('… their uploads 404', $r['status'] === 404, json_encode($r['status']));
$r = $ask($mod, 'user_directory');
check('… the member directory 403', $r['status'] === 403 && ($r['json']['error'] ?? '') === 'no_permission', json_encode($r));
$r = $ask($mod, 'hash_favourites', ['hash' => $H]);
check('… "who has this" (the 1.69.0 endpoint) 404', $r['status'] === 404, json_encode($r['status']));
$r = $ask($member, 'user_favourites', ['user' => 'psst_owner', 'files' => '0']);
check('a member: somebody\'s favourites 200, the row there', $r['status'] === 200 && (int)($r['json']['total'] ?? -1) === 1, json_encode($r['status']));
$r = $ask($member, 'user_directory');
check('… the directory 200', $r['status'] === 200, json_encode($r['status']));
$r = $ask($viaSession($both), 'user_favourites', ['user' => 'psst_owner', 'files' => '0']);
check('a moderator who is also a member: as a member — 200', $r['status'] === 200, json_encode($r['status']));
// The profile page: refused to the moderator exactly as a name nobody has is refused — byte for byte.
$page = fn(array $s, string $name) => $ask($s, '', ['action' => 'u', 'name' => $name], 'templates/pages/profile.php', 'u');
$pMod = $page($mod, 'psst_owner');
$pNobody = $page($member, 'psst_nobody_here');
$pMember = $page($member, 'psst_owner');
check('the profile page to a moderator-only account: the not-found page, the same bytes as for a name nobody has',
      $pMod['raw'] !== '' && $pMod['raw'] === $pNobody['raw'] && !str_contains($pMod['raw'], 'profile-name'), substr($pMod['raw'], 0, 200));
check('… while a member is shown the profile', str_contains($pMember['raw'], 'class="profile-name"') && str_contains($pMember['raw'], 'psst_owner'),
      substr($pMember['raw'], 0, 200));

/* ══ 6. PRIV-5: a consent is the account's grant ═════════════════════════ */
$as($viaSession($admin));
check('an Admin-group account: userCan(uploads.public) says yes (its blanket) — but its GRANT is no',
      userCan($db, $cfgOn, 'uploads.public') && !userIdHasGrantedPermission($db, $cfgOn, $admin, 'uploads.public'));
check('… the profile owner, whose group grants it: yes', userIdHasGrantedPermission($db, $cfgOn, $owner, 'uploads.public'));
$wlAdmin = $ask($viaSession($admin), '', ['action' => 'whitelist'], 'templates/pages/whitelist.php', 'whitelist');
$wlOwner = $ask(['user_id' => $owner, 'user_login_time' => time()], '', ['action' => 'whitelist'], 'templates/pages/whitelist.php', 'whitelist');
check('the whitelist page offers "show it on my profile" to the account whose group grants uploads.public …',
      str_contains($wlOwner['raw'], 'id="wl-public"'), substr($wlOwner['raw'], 0, 160));
check('… and NOT to an Admin-group account whose groups grant nothing, through its panel session', $wlAdmin['raw'] !== '' && !str_contains($wlAdmin['raw'], 'id="wl-public"'),
      substr($wlAdmin['raw'], 0, 160));
// No consent is read through userCan() anywhere: comments taken out by the tokeniser (a word in a comment is not a read).
$consent = userConsentPermissions();
$hits = [];
foreach (['api', 'templates', 'includes'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        $code = '';
        foreach (token_get_all((string)file_get_contents($f->getPathname())) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $code .= is_array($tk) ? $tk[1] : $tk;
        }
        if (preg_match_all("/userCan\s*\([^;]*?'([a-z_]+\.[a-z_.]+)'/", $code, $m)) {
            foreach ($m[1] as $id) if (in_array($id, $consent, true)) $hits[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1)) . ': ' . $id;
        }
    }
}
check('no userCan(…, <consent id>) anywhere in api/, templates/ or includes/ — a consent is asked with userIdHasGrantedPermission()',
      $consent !== [] && $hits === [], implode(' | ', $hits));

$as([]);
echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
