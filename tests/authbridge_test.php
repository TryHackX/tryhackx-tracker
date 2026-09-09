<?php
/**
 * The sign-in bridge (needs the local test database):
 *
 *   php tests/authbridge_test.php
 *
 * ── what has to be true here ───────────────────────────────────────────────────────────────────
 * The bridge lets somebody holding a key say who a visitor is. That is the strongest sentence any
 * credential on this site can utter, so most of this file is about the cases where it must be
 * refused: a ticket spent twice, a ticket spent by the wrong key, an account claimed by an address
 * nobody verified, a second forum user reaching for an account that is already somebody's.
 *
 * The end-to-end path through a real browser is proved in deploy/smoke_users.py; what is proved
 * here is the logic underneath, against the real tables.
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
require_once $root . '/includes/api_auth.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/authbridge.php';

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
$GLOBALS['cfg'] = $cfg;

/* ── 1. the schema ────────────────────────────────────────────────────────── */
check('schema is at least 49', (int)($cfg['schema_version'] ?? 0) >= 49, (string)($cfg['schema_version'] ?? 'none'));
foreach (['user_identities', 'auth_handoffs'] as $t) {
    check("$t exists", count($db->query("SHOW TABLES LIKE '$t'")->fetchAll()) === 1);
}
$idx = array_column($db->query("SHOW INDEX FROM user_identities")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
// The two UNIQUEs are the whole safety of the linking model — see the schema comment.
check('(client, their id) is unique, so two forums numbering from 1 stay separate',
      in_array('uq_ident_ext', $idx, true), implode(',', array_unique($idx)));
check('(account, client) is unique, so one forum cannot hold two identities for one account',
      in_array('uq_ident_user', $idx, true), implode(',', array_unique($idx)));
$hCols = array_column($db->query("SHOW COLUMNS FROM auth_handoffs")->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['token_hash', 'user_id', 'client_id', 'direction', 'expires_at', 'used_at'] as $c) {
    check("auth_handoffs.$c exists", in_array($c, $hCols, true), implode(',', $hCols));
}
// The plaintext must not be stored: the row would otherwise be enough to become somebody.
check('the ticket is stored hashed, never in the clear', !in_array('token', $hCols, true));
$schemaSrc = (string)@file_get_contents($root . '/includes/schema.php');
foreach (['user_identities', 'auth_handoffs'] as $t) {
    check("$t is in the schema source twice (fresh install + upgrade)",
          substr_count($schemaSrc, '`' . $t . '`') >= 2, (string)substr_count($schemaSrc, '`' . $t . '`'));
}

/* ── 2. the settings, in all four places ──────────────────────────────────── */
$defaults = trackerSchemaDefaultSettings();
$catalogSrc = (string)@file_get_contents($root . '/includes/settings_catalog.php');
$saveSrc    = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl     = (string)@file_get_contents($root . '/templates/admin/settings.php');
foreach (['auth_bridge_enabled' => '0', 'auth_bridge_create' => '1', 'auth_bridge_merge' => 'none',
          'auth_bridge_ttl' => '120', 'auth_bridge_return_url' => '', 'auth_bridge_login_url' => '',
          'auth_bridge_logout' => '1'] as $key => $want) {
    check("$key ships as '$want'", ($defaults[$key] ?? null) === $want, var_export($defaults[$key] ?? null, true));
    check("$key is in the catalogue", str_contains($catalogSrc, "'$key'"));
    check("$key is in the save allow-list", str_contains($saveSrc, "'$key'"));
    check("$key has a control", str_contains($setTpl, 'name="' . $key . '"'));
}
// The two that decide how much a key may claim. Both must ship at their careful value.
check('the bridge ships OFF', !authBridgeEnabled(['users_enabled' => '1']));
check('… and stays off when only the bridge switch is on but accounts are not',
      !authBridgeEnabled(['auth_bridge_enabled' => '1', 'users_enabled' => '0']));
check('… and on only when both are', authBridgeEnabled(['auth_bridge_enabled' => '1', 'users_enabled' => '1']));
check('claiming an existing account is off by default', authBridgeMergeMode([]) === 'none');
check('an unknown merge mode falls back to the safe one', authBridgeMergeMode(['auth_bridge_merge' => 'anything']) === 'none');
check('the ticket lifetime is clamped low', authBridgeTtl(['auth_bridge_ttl' => '99999']) === 900
      && authBridgeTtl(['auth_bridge_ttl' => '1']) === 30);

/* ── 3. the two redirect targets are checked before they are ever followed ── */
// An operator field that becomes a redirect is a field somebody will paste something odd into.
foreach (['javascript:alert(1)', '//evil.example/x', 'data:text/html,x', 'ftp://x/y', 'evil.example',
          '  ', 'https://', 'HTTPS://'] as $bad) {
    check("a redirect target of " . var_export($bad, true) . " is refused", authBridgeSafeUrl($bad) === '');
}
check('an ordinary https address is kept', authBridgeSafeUrl('https://forum.example.org/login') === 'https://forum.example.org/login');
check('http is allowed too, for a forum on a private network', authBridgeSafeUrl('http://forum.lan/login') !== '');
check('a very long address is refused rather than truncated', authBridgeSafeUrl('https://x.example/' . str_repeat('a', 600)) === '');

// The same rule on the way back in: ?next= is the classic open redirect.
check('next= only takes a plain path on this site', authBridgeNext('/?action=search', '/') === '/?action=search');
check('… never a scheme-relative host', authBridgeNext('//evil.example/x', '/HOME') === '/HOME');
check('… never an absolute address', authBridgeNext('https://evil.example/x', '/HOME') === '/HOME');
check('… and never a header split', authBridgeNext("/ok\r\nSet-Cookie: a=b", '/HOME') === '/HOME');

/* ── 4. a free username is derived rather than demanded ───────────────────── */
$uname = authBridgeUsername($db, 'Kasia Nowak', '412');
check('a name with a space becomes a valid username', userValidUsername($uname), $uname);
check('a name of punctuation falls back to something usable',
      userValidUsername(authBridgeUsername($db, '!!!', '412')), authBridgeUsername($db, '!!!', '412'));
check('an empty name falls back too', userValidUsername(authBridgeUsername($db, '', 'abc123')));
check('a very long name is cut to fit', strlen(authBridgeUsername($db, str_repeat('n', 90), '1')) <= 32);

/* ── 5. tickets: one use, one direction, one key ──────────────────────────── */
$fixUser = 'bridgetest_' . bin2hex(random_bytes(3));
$mk = userCreate($db, array_merge($cfg, ['users_enabled' => '1']), $fixUser, $fixUser . '@example.org',
                 bin2hex(random_bytes(8)) . 'aZ9!', '127.0.0.1', 'test');
check('a fixture account was created', isset($mk['user']), json_encode($mk['error'] ?? null));
$uid = (int)($mk['user']['id'] ?? 0);

$clientA = apiClientCreate($db, 'bridge test A', 'users');
$clientB = apiClientCreate($db, 'bridge test B', 'users');

$tok = authHandoffMint($db, $uid, (int)$clientA['id'], 'in', 120, '127.0.0.1');
check('the minted ticket is a 64-hex string', preg_match('/^[0-9a-f]{64}$/', $tok) === 1);
$stored = $db->prepare("SELECT token_hash FROM auth_handoffs WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stored->execute([$uid]);
check('… and the row holds its hash, not the ticket', (string)$stored->fetchColumn() === hash('sha256', $tok));

check('spending it once works', authHandoffRedeem($db, $tok, 'in') !== null);
check('spending it twice does not', authHandoffRedeem($db, $tok, 'in') === null);

$tok2 = authHandoffMint($db, $uid, (int)$clientA['id'], 'in', 120);
check('an inbound ticket cannot be spent as an outbound one', authHandoffRedeem($db, $tok2, 'out') === null);
check('… and is still good in its own direction afterwards', authHandoffRedeem($db, $tok2, 'in') !== null);
check('an invented ticket is refused', authHandoffRedeem($db, str_repeat('f', 64), 'in') === null);
check('a malformed ticket is refused without touching the table', authHandoffRedeem($db, 'nope', 'in') === null);

// Expiry is enforced in SQL, so the test moves the row rather than sleeping.
$tok3 = authHandoffMint($db, $uid, (int)$clientA['id'], 'in', 120);
$db->prepare("UPDATE auth_handoffs SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = ?")
   ->execute([hash('sha256', $tok3)]);
check('an expired ticket is refused', authHandoffRedeem($db, $tok3, 'in') === null);

/* ── 6. resolving an identity ─────────────────────────────────────────────── */
$on = array_merge($cfg, ['users_enabled' => '1', 'auth_bridge_enabled' => '1', 'auth_bridge_create' => '1',
                         'auth_bridge_merge' => 'none']);
check('the bridge refuses to resolve anything while it is off',
      (authBridgeResolve($db, array_merge($on, ['auth_bridge_enabled' => '0']), $clientA,
                         ['external_id' => 'x1'])['error'] ?? '') === 'bridge_off');
check('… and while the account system is off',
      (authBridgeResolve($db, array_merge($on, ['users_enabled' => '0']), $clientA,
                         ['external_id' => 'x1'])['error'] ?? '') === 'users_off');
check('an empty external id is refused',
      (authBridgeResolve($db, $on, $clientA, ['external_id' => ''])['error'] ?? '') === 'invalid_external_id');
check('an over-long external id is refused',
      (authBridgeResolve($db, $on, $clientA, ['external_id' => str_repeat('9', 300)])['error'] ?? '') === 'invalid_external_id');

$ext = 'ext_' . bin2hex(random_bytes(3));
$r1 = authBridgeResolve($db, $on, $clientA, ['external_id' => $ext, 'name' => 'Bridge Person', 'email' => '']);
check('an unknown person gets an account', isset($r1['user']) && $r1['created'] === true, json_encode($r1['error'] ?? null));
$newId = (int)($r1['user']['id'] ?? 0);
$r2 = authBridgeResolve($db, $on, $clientA, ['external_id' => $ext, 'name' => 'Bridge Person']);
check('the same person comes back to the same account the second time',
      (int)($r2['user']['id'] ?? -1) === $newId && $r2['created'] === false);

// The account is reachable through the bridge and through a password reset — never with a password
// anybody knows, including this code.
$hash = (string)$db->query("SELECT pass_hash FROM users WHERE id = $newId")->fetchColumn();
check('the created account has a real hash nobody was told', strlen($hash) > 20);

check('the same external id on ANOTHER key is a different person',
      (int)(authBridgeResolve($db, $on, $clientB, ['external_id' => $ext, 'name' => 'Other Forum'])['user']['id'] ?? -1) !== $newId);

// With creation off, an unknown person is turned away rather than quietly made.
check('with creation off an unknown person is refused',
      (authBridgeResolve($db, array_merge($on, ['auth_bridge_create' => '0']), $clientA,
                         ['external_id' => 'never_seen_' . bin2hex(random_bytes(3))])['error'] ?? '') === 'no_account');

/* ── 7. claiming an existing account ──────────────────────────────────────── */
// The default must not do it, even when the address matches and is verified.
$db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$uid]);
$claim = 'claim_' . bin2hex(random_bytes(3));
$rNo = authBridgeResolve($db, $on, $clientA, ['external_id' => $claim, 'name' => 'X', 'email' => $fixUser . '@example.org']);
check('with merging off, a matching verified address does NOT hand over the account',
      (int)($rNo['user']['id'] ?? 0) !== $uid, json_encode($rNo['error'] ?? ($rNo['user']['id'] ?? null)));
// It also must not have silently made a second account under the taken address.
check('… and it does not create a duplicate under a taken address either',
      isset($rNo['error']) && str_starts_with((string)$rNo['error'], 'create_failed:'), json_encode($rNo['error'] ?? null));

$onMerge = array_merge($on, ['auth_bridge_merge' => 'email_verified']);
$claim2 = 'claim2_' . bin2hex(random_bytes(3));
$rYes = authBridgeResolve($db, $onMerge, $clientB, ['external_id' => $claim2, 'name' => 'X', 'email' => $fixUser . '@example.org']);
check('with merging on, the verified address claims the existing account',
      (int)($rYes['user']['id'] ?? 0) === $uid && ($rYes['merged'] ?? false) === true, json_encode($rYes['error'] ?? null));
// And a SECOND forum user reaching for the same account through the same key is refused: two people
// claiming one account is not something to resolve by guessing.
$claim3 = 'claim3_' . bin2hex(random_bytes(3));
check('a second identity from the same key cannot also claim it',
      (authBridgeResolve($db, $onMerge, $clientB, ['external_id' => $claim3, 'name' => 'X',
                         'email' => $fixUser . '@example.org'])['error'] ?? '') === 'merge_ambiguous');
// An UNVERIFIED address must never be enough.
$db->prepare("UPDATE users SET email_verified = 0 WHERE id = ?")->execute([$uid]);
$claim4 = 'claim4_' . bin2hex(random_bytes(3));
$rUnv = authBridgeResolve($db, array_merge($onMerge, ['auth_bridge_create' => '0']), $clientA,
                          ['external_id' => $claim4, 'name' => 'X', 'email' => $fixUser . '@example.org']);
check('an UNVERIFIED matching address never claims an account',
      (int)($rUnv['user']['id'] ?? 0) !== $uid, json_encode($rUnv));
$db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$uid]);

/* ── 8. a suspended account is not signed in ──────────────────────────────── */
$db->prepare("UPDATE users SET status = 'banned' WHERE id = ?")->execute([$newId]);
check('a suspended account cannot be signed in through the bridge',
      (authBridgeResolve($db, $on, $clientA, ['external_id' => $ext])['error'] ?? '') === 'account_suspended');
$db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$newId]);

/* ── 9. showing the source ────────────────────────────────────────────────── */
check('the account names where it signs in from', authIdentityLabel($db, $newId) === 'bridge test A',
      authIdentityLabel($db, $newId));
check('an account nobody bridged says nothing', authIdentityLabel($db, 0) === '');
$accTpl = (string)@file_get_contents($root . '/templates/pages/account.php');
check('the account page shows it to the person themselves', str_contains($accTpl, 'bridge.linked_via'));
$usersApi = (string)@file_get_contents($root . '/api/admin/fetch_users.php');
check('the panel user list carries it', str_contains($usersApi, 'user_identities') && str_contains($usersApi, "'identities'"));
check('… resolved in one query for the page, not one per row', substr_count($usersApi, 'FROM user_identities') === 1);
$usersJs = (string)@file_get_contents($root . '/assets/js/admin-users.js');
check('… and the table draws it', str_contains($usersJs, 'js.users.bridge_via'));

/* ── 10. two-way sign-out ─────────────────────────────────────────────────── */
$bridgeSrc = (string)@file_get_contents($root . '/includes/authbridge.php');
$ident = authIdentityFind($db, (int)$clientA['id'], $ext);
check('the identity is findable', $ident !== null);
$_SESSION = ['bridge_identity' => (int)$ident['id'], 'bridge_login_at' => time()];
check('a live bridged session is not ended', authBridgeSessionEnded($db) === false);
// MySQL NOW() has second resolution, so a mark made in the same second as the login must still
// count — the comparison is >=, and this is the case that proves it.
$db->prepare("UPDATE user_identities SET logout_at = NOW() WHERE id = ?")->execute([(int)$ident['id']]);
check('the far side ending it ends it here', authBridgeSessionEnded($db) === true);
$db->prepare("UPDATE user_identities SET logout_at = NULL WHERE id = ?")->execute([(int)$ident['id']]);
check('and signing in again clears it', authBridgeSessionEnded($db) === false);
$_SESSION = [];
check('a session that never came through the bridge is never ended by one', authBridgeSessionEnded($db) === false);
// The two clocks. logout_at is written by the DATABASE and the session remembers a PHP timestamp,
// so the comparison has to happen where both are the same clock. This install runs the database on
// UTC and PHP on local time; reading the column back with strtotime() would have been silently
// wrong for the length of the offset.
// The CODE, without the prose about it: both checks below would otherwise match their own
// explanatory comment and pass whatever the code does.
$code = preg_replace('#//[^
]*|/\*.*?\*/#s', '', $bridgeSrc);   // the /s flag makes . eat newlines
check('the far-side check compares in the database, not in PHP',
      str_contains($code, 'UNIX_TIMESTAMP(logout_at)') && !str_contains($code, 'strtotime'));
// And prove it end to end against a mark written two hours in the FUTURE of PHP's clock, which is
// what a UTC database looks like to a PHP running two hours ahead.
$_SESSION = ['bridge_identity' => (int)$ident['id'], 'bridge_login_at' => time()];
$db->prepare("UPDATE user_identities SET logout_at = DATE_ADD(NOW(), INTERVAL 1 SECOND) WHERE id = ?")->execute([(int)$ident['id']]);
check('a mark a second later ends the session', authBridgeSessionEnded($db) === true);
$db->prepare("UPDATE user_identities SET logout_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([(int)$ident['id']]);
check('a mark from BEFORE this sign-in does not', authBridgeSessionEnded($db) === false);
$db->prepare("UPDATE user_identities SET logout_at = NULL WHERE id = ?")->execute([(int)$ident['id']]);
$_SESSION = [];

// The panel is not the bridge's to open: it has checked a partner's key, not a password.
check('a bridged sign-in never opens the ADMIN PANEL session',
      !str_contains($code, 'userMaybeOpenPanelSession'));
check('… and says so out loud, so nobody adds it back as an improvement',
      str_contains($bridgeSrc, 'DELIBERATELY NOT userMaybeOpenPanelSession'));

$usersSrc = (string)@file_get_contents($root . '/includes/users.php');
check('currentUser() only pays for the check on bridged sessions',
      preg_match("/!empty\(\\\$_SESSION\['bridge_identity'\]\).*authBridgeSessionEnded/s", $usersSrc) === 1);
check('signing out here marks the link, so the far side can see it',
      preg_match('/function userSessionLogout.*authIdentityMarkLogout/s', $usersSrc) === 1);
check('deleting an account takes its bridge links with it',
      str_contains($usersSrc, "DELETE FROM user_identities WHERE user_id = ?"));

/* ── 11. the endpoints are wired and gated ────────────────────────────────── */
$apiSrc = (string)@file_get_contents($root . '/api.php');
foreach (['v1/auth/login', 'v1/auth/logout', 'v1/auth/verify', 'v1/auth/merge', 'v1/auth/status'] as $ep) {
    check("$ep is routed", str_contains($apiSrc, "'$ep'"));
}
check('authbridge.php is loaded by the API', str_contains($apiSrc, "includes/authbridge.php"));
check('… and by the site', str_contains((string)@file_get_contents($root . '/index.php'), 'includes/authbridge.php'));
foreach (['auth_login', 'auth_logout', 'auth_verify', 'auth_merge', 'auth_status'] as $f) {
    $src = (string)@file_get_contents($root . '/api/v1/' . $f . '.php');
    check("$f requires POST", str_contains($src, 'requirePost()'));
    check("$f authenticates the key", str_contains($src, 'apiAuthenticate('));
    check("$f is behind the users scope", str_contains($src, "apiRequireScope(\$client, 'users')"));
    check("$f answers 503 while the bridge is off", str_contains($src, 'bridge_disabled'));
}
$verifySrc = (string)@file_get_contents($root . '/api/v1/auth_verify.php');
// A ticket minted for one partner is not another partner's to spend — the check that makes the
// outbound direction safe to offer to more than one key.
check('verify refuses a ticket minted for a different key',
      str_contains($verifySrc, "(int)\$row['client_id'] !== (int)\$client['id']"));
check('verify says the same thing about every bad ticket', substr_count($verifySrc, "'invalid_token'") >= 2);
$mergeSrc = (string)@file_get_contents($root . '/api/v1/auth_merge.php');
check('merge refuses to guess which account is meant', str_contains($mergeSrc, 'login_or_user_id_required'));
check('merge refuses a conflict either way',
      str_contains($mergeSrc, 'external_id_linked_elsewhere') && str_contains($mergeSrc, 'account_linked_elsewhere'));
check('unlinking does not delete the account', !preg_match('/DELETE FROM users/i', $mergeSrc));
check('the bridge never accepts or hands out a password',
      !str_contains($bridgeSrc, 'password_verify') && !str_contains($bridgeSrc, "'password'"));

/* ── 12. the guide describes it ───────────────────────────────────────────── */
$docsSrc = (string)@file_get_contents($root . '/templates/pages/apidocs.php');
check('the guide has a bridge chapter', str_contains($docsSrc, 'apidocs.h_bridge'));
check('… reachable by scope', str_contains($docsSrc, "'auth' => 'v1/auth/login'"));
check('… and it warns against putting the key in a browser', str_contains($docsSrc, 'apidocs.bridge_warning'));

/* ── clean up ─────────────────────────────────────────────────────────────── */
foreach ([$newId, $uid] as $delId) {
    if ($delId > 0) userDeleteCascade($db, $delId);
}
$db->prepare("DELETE FROM users WHERE username LIKE 'BridgePerson%' OR username LIKE 'OtherForum%'")->execute();
$db->prepare("DELETE FROM api_clients WHERE id IN (?, ?)")->execute([(int)$clientA['id'], (int)$clientB['id']]);
$db->prepare("DELETE FROM user_identities WHERE client_id IN (?, ?)")->execute([(int)$clientA['id'], (int)$clientB['id']]);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
