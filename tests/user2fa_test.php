<?php
/**
 * A second factor on a member account, and the sessions it protects (needs the local test database):
 *   php tests/user2fa_test.php
 *
 * ── the three facts this file exists to hold down ──────────────────────────────────────────────
 *
 * 1. A CODE WORKS ONCE. TOTP digits are valid for a 30-second step plus one either side, so the same
 *    six digits are good for up to 90 seconds unless something refuses the second use. That
 *    something is `last_step`, it is one comparison, and it is the kind of line that gets removed by
 *    somebody tidying up a query.
 *
 * 2. A RECOVERY CODE WORKS ONCE, and the list shrinks by exactly one when it does.
 *
 * 3. "REQUIRED" NEVER MEANS "REFUSED". The requirement is enforced by keeping the PANEL shut, never
 *    by turning a sign-in away — an account that cannot get into its own account is how an operator
 *    ends up switching the whole feature off.
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
require_once $root . '/includes/twofa.php';
require_once $root . '/includes/user2fa.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
check('schema knows about the second factor', schemaTableExists($db, 'user_twofa'));
check('… and about when the other sessions stopped counting', schemaColumnExists($db, 'users', 'sessions_valid_from'));
check('… and about what a remember token was handed to',
    schemaColumnExists($db, 'user_tokens', 'ip') && schemaColumnExists($db, 'user_tokens', 'ua'));

// ── the feature switch ───────────────────────────────────────────────────────
check('off unless the operator switched it on',
    !user2faFeatureEnabled([]) && !user2faFeatureEnabled(['user_2fa_enabled' => '0'])
    && user2faFeatureEnabled(['user_2fa_enabled' => '1']));
// A requirement while the feature is off would be a rule nobody can satisfy.
check('and nothing is required while it is off',
    user2faRequirement(['user_2fa_enabled' => '0', 'user_2fa_required' => 'all']) === 'off');
check('the requirement reads the three answers and nothing else',
    user2faRequirement(['user_2fa_enabled' => '1', 'user_2fa_required' => 'panel']) === 'panel'
    && user2faRequirement(['user_2fa_enabled' => '1', 'user_2fa_required' => 'all']) === 'all'
    && user2faRequirement(['user_2fa_enabled' => '1', 'user_2fa_required' => 'nonsense']) === 'off');

// ── a real account ───────────────────────────────────────────────────────────
$db->exec("DELETE FROM user_twofa WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'tfa%')");
$db->exec("DELETE FROM user_tokens WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'tfa%')");
$db->exec("DELETE FROM users WHERE username LIKE 'tfa%'");
$r = userCreate($db, $cfg, 'tfauser', 'tfa@example.org', 'TfaPass123!', '127.0.0.1');
$user = $r['user'] ?? null;
check('a member to test with', $user !== null, json_encode($r['errors'] ?? $r));
$uid = (int)$user['id'];
// Verified, because on a site that requires verification an unverified account holds GUEST
// permissions until it is — which would make every permission question below answer about a guest.
$db->exec("UPDATE users SET email_verified = 1 WHERE id = $uid");
$user = userFindById($db, $uid);

check('an account with no row has no second factor', !user2faEnabled($db, $uid));
$setup = user2faBeginSetup($db, array_merge($cfg, ['site_name' => 'Test Tracker']), $user);
check('beginning a setup returns a secret and a URI a phone can read',
    strlen((string)$setup['secret']) >= 16 && str_starts_with((string)$setup['uri'], 'otpauth://totp/'), (string)$setup['uri']);
check('the URI names the site and the account, so two of them can be told apart',
    str_contains((string)$setup['uri'], rawurlencode('Test Tracker')) && str_contains((string)$setup['uri'], 'tfauser'), (string)$setup['uri']);
check('an unconfirmed setup protects nothing', !user2faEnabled($db, $uid));

check('a wrong code does not confirm it', user2faConfirmSetup($db, $uid, '000000')['ok'] === false);
check('… and it is still not on', !user2faEnabled($db, $uid));

$now = time();
$code = twofaCodeAt((string)$setup['secret'], $now);
$conf = user2faConfirmSetup($db, $uid, $code);
check('the right code turns it on', $conf['ok'] === true && user2faEnabled($db, $uid), json_encode($conf['error'] ?? ''));
check('and hands over a set of recovery codes, once',
    is_array($conf['recovery']) && count($conf['recovery']) === TWOFA_RECOVERY_COUNT, (string)count($conf['recovery'] ?? []));
check('which are stored hashed, never in the clear',
    !str_contains((string)$db->query("SELECT recovery FROM user_twofa WHERE user_id = $uid")->fetchColumn(),
                  (string)($conf['recovery'][0] ?? 'x')));

// ── 1. a code works once ─────────────────────────────────────────────────────
check('the code that confirmed the setup cannot then be used to sign in',
    !user2faVerify($db, $uid, $code));
$next = twofaCodeAt((string)$setup['secret'], $now + 30);
check('the next step is accepted', user2faVerify($db, $uid, $next));
check('… and refused the second time', !user2faVerify($db, $uid, $next));
// Going BACKWARDS is the same attack from the other end: a code from the previous step is still
// arithmetically valid inside the window.
$prev = twofaCodeAt((string)$setup['secret'], $now);
check('a code from an earlier step is refused after a later one has been used', !user2faVerify($db, $uid, $prev));
check('and a code from another secret entirely is refused',
    !user2faVerify($db, $uid, twofaCodeAt(twofaBase32Encode(random_bytes(20)), time() + 60)));

// ── 2. a recovery code works once ────────────────────────────────────────────
$left0 = user2faRecoveryLeft($db, $uid);
$rec = (string)$conf['recovery'][0];
check('a recovery code signs in', user2faVerify($db, $uid, $rec));
check('… exactly once', !user2faVerify($db, $uid, $rec));
check('… and the list is one shorter, not merely marked', user2faRecoveryLeft($db, $uid) === $left0 - 1,
    $left0 . ' -> ' . user2faRecoveryLeft($db, $uid));
// Typed the way somebody would read it off paper.
$rec2 = strtolower(str_replace('-', ' ', (string)$conf['recovery'][1]));
check('a recovery code typed loosely still works — case, spaces and dashes do not matter',
    user2faVerify($db, $uid, $rec2));

$fresh = user2faRegenerateRecovery($db, $uid);
check('new codes replace whatever was left', count($fresh) === TWOFA_RECOVERY_COUNT
    && user2faRecoveryLeft($db, $uid) === TWOFA_RECOVERY_COUNT, (string)user2faRecoveryLeft($db, $uid));
check('and an old code stops working the moment they are made', !user2faVerify($db, $uid, (string)$conf['recovery'][2]));

// ── 3. "required" is about the panel, never about the sign-in ────────────────
// array_merge, not `+`: the settings table already HAS these keys, and `+` keeps the left side's.
// users_enabled too — every permission question answers a legacy default while accounts are off.
$base2fa  = array_merge($cfg, ['users_enabled' => '1', 'user_2fa_enabled' => '1']);
$cfgAll   = array_merge($base2fa, ['user_2fa_required' => 'all']);
$cfgPanel = array_merge($base2fa, ['user_2fa_required' => 'panel']);
$cfgOff   = array_merge($base2fa, ['user_2fa_required' => 'off']);
check('required-from-nobody asks nothing of anybody', user2faRequiredFor($db, $cfgOff, $user)['required'] === false);
check('required-from-everybody asks it of an ordinary member', user2faRequiredFor($db, $cfgAll, $user)['required'] === true);
$needPanel = user2faRequiredFor($db, $cfgPanel, $user);
check('required-from-panel-accounts asks nothing of a member who cannot open the panel',
    $needPanel['required'] === false, json_encode($needPanel));
// …and does ask it of one who can. A SECOND account, created after the grant: effective permissions
// are cached per user for the life of a request (which is right — a request is one answer), so
// asking about the same account again would read the answer from before the grant.
$g = userGroupBySlug($db, 'member');
$perms = json_decode((string)$g['permissions'], true) ?: [];
$perms['panel.access'] = true;
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([json_encode($perms), (int)$g['id']]);
$db->exec("DELETE FROM users WHERE username = 'tfamod'");
$r2 = userCreate($db, $cfg, 'tfamod', 'tfamod@example.org', 'TfaPass123!', '127.0.0.1');
$mod = $r2['user'] ?? null;
if ($mod) {
    $db->exec("UPDATE users SET email_verified = 1 WHERE id = " . (int)$mod['id']);
    $mod = userFindById($db, (int)$mod['id']);
}
$needPanel2 = $mod ? user2faRequiredFor($db, $cfgPanel, $mod) : ['required' => null, 'why' => null];
check('… and does ask it of one who can', $needPanel2['required'] === true && $needPanel2['why'] === 'panel', json_encode($needPanel2));
unset($perms['panel.access']);
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([json_encode($perms), (int)$g['id']]);
if ($mod) $db->exec("DELETE FROM users WHERE id = " . (int)$mod['id']);

check('turning it off removes the secret rather than parking it',
    user2faDisable($db, $uid) && user2faRow($db, $uid) === null && !user2faEnabled($db, $uid));

// ── the sessions ─────────────────────────────────────────────────────────────
$db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at, ip, ua)
              VALUES (?, 'remember', ?, NOW() + INTERVAL 30 DAY, '203.0.113.7', 'Mozilla/5.0 (Windows NT 10.0) Firefox/141.0')")
   ->execute([$uid, hash('sha256', 'aaa')]);
$db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at, ip, ua)
              VALUES (?, 'remember', ?, NOW() + INTERVAL 30 DAY, '198.51.100.4', 'Mozilla/5.0 (Android 14) Chrome/140.0')")
   ->execute([$uid, hash('sha256', 'bbb')]);
// Expired and spent tokens are not devices anybody is signed in on.
$db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, 'remember', ?, NOW() - INTERVAL 1 DAY)")
   ->execute([$uid, hash('sha256', 'ccc')]);
$db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at, used_at) VALUES (?, 'remember', ?, NOW() + INTERVAL 30 DAY, NOW())")
   ->execute([$uid, hash('sha256', 'ddd')]);

$list = userSessionList($db, $uid);
check('the device list counts the live tokens and nothing else', count($list) === 2, json_encode(array_column($list, 'ip')));
check('… and says where each one is, so an unfamiliar row is recognisable as one',
    ($list[0]['ip'] ?? '') !== '' && ($list[0]['ua'] ?? '') !== '', json_encode($list[0] ?? []));
check('… and marks none of them as this browser, because this is a CLI with no cookie',
    !array_filter($list, static fn($r) => !empty($r['current'])));
// The token hash must not leave the server: a caller that could name a token could end somebody
// else's session with it.
check('the list never carries the token hash itself',
    !array_filter($list, static fn($r) => isset($r['token_hash'])), json_encode(array_keys($list[0] ?? [])));

$before = (int)$db->query("SELECT sessions_valid_from FROM users WHERE id = $uid")->fetchColumn();
$gone = userSignOutOthers($db, $uid, false);
check('signing out everywhere destroys the remember tokens', $gone >= 2 && userSessionList($db, $uid) === [], (string)$gone);
$after = (int)$db->query("SELECT sessions_valid_from FROM users WHERE id = $uid")->fetchColumn();
check('… and stamps the account, which is what reaches the sessions with no cookie behind them',
    $after > $before && abs($after - time()) < 5, $before . ' -> ' . $after);

// ── tidy up ──────────────────────────────────────────────────────────────────
$db->exec("DELETE FROM user_twofa WHERE user_id = $uid");
$db->exec("DELETE FROM user_tokens WHERE user_id = $uid");
$db->exec("DELETE FROM users WHERE id = $uid");

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
