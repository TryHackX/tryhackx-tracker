<?php
/**
 * Test for includes/users.php (needs the local test database — see deploy/local_bootstrap.php):
 *   php tests/users_test.php
 * Prints PASS/FAIL lines and exits non-zero on failure. No sessions/cookies — session-bound paths
 * (currentUser / remember-me) are covered indirectly via the pure helpers they build on.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/api_auth.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);

// ── 1. schema v7 ──────────────────────────────────────────────────────────────
check('schema version >= 7', (int)($cfg['schema_version'] ?? 0) >= 7, (string)($cfg['schema_version'] ?? 'none'));
foreach (['users', 'user_groups', 'user_group_members', 'user_notifications', 'user_tokens', 'fed_peers'] as $t) {
    $ok = true;
    try { $db->query("SELECT 1 FROM `$t` LIMIT 1"); } catch (\Throwable $e) { $ok = false; }
    check("table $t exists", $ok);
}
check('api_clients has scope column', schemaColumnExists($db, 'api_clients', 'scope'));
check('index_hashes has meta_source column', schemaColumnExists($db, 'index_hashes', 'meta_source'));
check('index_hashes has meta_fetched index', schemaIndexExists($db, 'index_hashes', 'idx_index_meta_fetched'));
$guest = userGroupBySlug($db, 'guest');
$member = userGroupBySlug($db, 'member');
check('guest group seeded (system)', $guest !== null && (int)$guest['is_system'] === 1);
check('member group seeded (default)', $member !== null && (int)$member['is_default'] === 1);
check('guest baseline keeps legacy public perms', ($p = userGroupPermissions($guest['permissions'])) && !empty($p['stats.view']) && !empty($p['whitelist.view']) && empty($p['index.view']), json_encode($p ?? null));

// clean slate for the rest
foreach (['users', 'user_group_members', 'user_notifications', 'user_tokens'] as $t) $db->exec("TRUNCATE TABLE `$t`");
$db->exec("DELETE FROM user_groups WHERE is_system = 0");
@unlink($root . '/config/users_state.json');

$cfgOn = $cfg; $cfgOn['users_enabled'] = '1';
$cfgOff = $cfg; $cfgOff['users_enabled'] = '0';

// ── 2. create / authenticate ─────────────────────────────────────────────────
$r = userCreate($db, $cfgOn, 'alice', 'alice@example.org', 'password123', '1.2.3.4');
check('create user ok', isset($r['user']) && $r['user']['username'] === 'alice', json_encode($r));
$alice = $r['user'];
check('new user got the default member group', count(userGroups($db, (int)$alice['id'])) === 1
    && userGroups($db, (int)$alice['id'])[0]['slug'] === 'member');
check('create rejects bad username', (userCreate($db, $cfgOn, 'a b', '', 'password123')['error'] ?? '') === 'invalid_username');
check('create rejects weak password', (userCreate($db, $cfgOn, 'bob', '', 'short')['error'] ?? '') === 'weak_password');
check('create rejects duplicate username', (userCreate($db, $cfgOn, 'alice', '', 'password123')['error'] ?? '') === 'username_taken');
check('create rejects duplicate email', (userCreate($db, $cfgOn, 'bob', 'alice@example.org', 'password123')['error'] ?? '') === 'email_taken');
check('authenticate ok (by username)', userAuthenticate($db, 'alice', 'password123') !== null);
check('authenticate ok (by email)', userAuthenticate($db, 'alice@example.org', 'password123') !== null);
check('authenticate rejects wrong password', userAuthenticate($db, 'alice', 'wrong') === null);
check('authenticate rejects unknown user', userAuthenticate($db, 'nobody', 'password123') === null);
$r2 = userCreate($db, $cfgOn, 'bob', '', 'password123');
$bob = $r2['user'];
check('email is optional', isset($r2['user']) && $r2['user']['email'] === null);

// ── 3. permissions ───────────────────────────────────────────────────────────
check('legacy default: index gated, stats open', !userLegacyDefault('index.view') && userLegacyDefault('stats.view'));
// userCan with users disabled = legacy behaviour (no session in CLI → anonymous)
check('userCan(off): stats.view true', userCan($db, $cfgOff, 'stats.view'));
check('userCan(off): index.view false', !userCan($db, $cfgOff, 'index.view'));
// enabled: anonymous gets the guest baseline
check('userCan(on, anon): stats.view true (guest)', userCan($db, $cfgOn, 'stats.view'));
check('userCan(on, anon): index.view false (guest)', !userCan($db, $cfgOn, 'index.view'));
// a VIP group grants index perms to alice
$db->prepare("INSERT INTO user_groups (slug, name, priority, permissions) VALUES ('vip', 'VIP', 10, ?)")
   ->execute([json_encode(['index.view' => true, 'index.magnet' => true])]);
$vip = userGroupBySlug($db, 'vip');
userGrantGroup($db, (int)$alice['id'], (int)$vip['id'], null, 'test', '');
$perms = userEffectivePermissions($db, (int)$alice['id']);
check('effective perms = guest + groups union', !empty($perms['stats.view']) && !empty($perms['index.view']) && !empty($perms['index.magnet']) && empty($perms['index.files']), json_encode($perms));
check('grant created a notification', userUnreadCount($db, (int)$alice['id']) >= 1);

// ── 4. timed memberships ─────────────────────────────────────────────────────
// expired membership → inactive
userGrantGroup($db, (int)$bob['id'], (int)$vip['id'], date('Y-m-d H:i:s', time() - 60), 'test', '', false);
check('expired membership is not active', count(userGroups($db, (int)$bob['id'])) === 1); // member only
check('userGroupsAll shows it inactive', count(array_filter(userGroupsAll($db, (int)$bob['id']), fn($g) => !$g['active'])) === 1);
// future granted_at → not active yet
userGrantGroup($db, (int)$bob['id'], (int)$vip['id'], null, 'test', '', false, date('Y-m-d H:i:s', time() + 3600));
check('future membership is not active yet', count(userGroups($db, (int)$bob['id'])) === 1);
// duration extension: base = max(now, current expiry)
$db->prepare("UPDATE user_group_members SET granted_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 10 DAY) WHERE user_id = ? AND group_id = ?")
   ->execute([(int)$bob['id'], (int)$vip['id']]);
$ext = userDurationExpiry($db, (int)$bob['id'], (int)$vip['id'], '7d');
$expectMin = time() + 16 * 86400; $expectMax = time() + 18 * 86400;
check('duration grant extends the current expiry', $ext !== null && $ext !== '' && strtotime($ext) > $expectMin && strtotime($ext) < $expectMax, (string)$ext);
check('permanent duration maps to null', userDurationExpiry($db, (int)$bob['id'], (int)$vip['id'], 'permanent') === null);
check('invalid duration maps to empty string', userDurationExpiry($db, (int)$bob['id'], (int)$vip['id'], '99x') === '');
check('revoke removes and notifies', userRevokeGroup($db, (int)$bob['id'], (int)$vip['id']) === true);

// ── 5. usersTick: expiry + warning ───────────────────────────────────────────
$db->exec("TRUNCATE TABLE user_notifications");
userGrantGroup($db, (int)$alice['id'], (int)$vip['id'], date('Y-m-d H:i:s', time() - 30), 'test', '', false);   // expired
userGrantGroup($db, (int)$bob['id'], (int)$vip['id'], date('Y-m-d H:i:s', time() + 86400), 'test', '', false); // expires in 1 d
$tick = usersTick($db, $cfgOn);
check('tick reaps the expired membership', $tick['expired'] === 1, json_encode($tick));
check('tick warns about the soon-expiring one', $tick['warned'] === 1, json_encode($tick));
check('expired membership row is gone', count(userGroupsAll($db, (int)$alice['id'])) === 1); // member only
$warned = $db->query("SELECT warned_at FROM user_group_members WHERE user_id = " . (int)$bob['id'] . " AND group_id = " . (int)$vip['id'])->fetchColumn();
check('warned_at recorded (no double warning)', $warned !== null && $warned !== false);
$tick2 = usersTick($db, $cfgOn);
check('second tick is quiet', $tick2['expired'] === 0 && $tick2['warned'] === 0, json_encode($tick2));
check('tick disabled → no-op', usersTick($db, $cfgOff)['enabled'] === false);
$types = $db->query("SELECT type FROM user_notifications ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('tick left expiry + warning notifications', in_array('group-expired', $types, true) && in_array('group-expiring', $types, true), json_encode($types));

// ── 6. reset tokens ──────────────────────────────────────────────────────────
$tok = userResetCreate($db, (int)$alice['id']);
check('reset token issued (64 hex)', (bool)preg_match('/^[a-f0-9]{64}$/', $tok));
check('reset consume (peek) finds the user', userResetConsume($db, $tok, false) === (int)$alice['id']);
check('reset consume (burn) works once', userResetConsume($db, $tok, true) === (int)$alice['id']);
check('burned token is dead', userResetConsume($db, $tok, false) === null);
check('garbage token rejected', userResetConsume($db, 'zz', false) === null);
$tokOld = userResetCreate($db, (int)$alice['id']);
$tokNew = userResetCreate($db, (int)$alice['id']);
check('a new reset invalidates the previous one', userResetConsume($db, $tokOld, false) === null && userResetConsume($db, $tokNew, false) === (int)$alice['id']);

// ── 7. api client scopes ─────────────────────────────────────────────────────
$c1 = apiClientCreate($db, 'shop', 'users');
$row = $db->query("SELECT scope FROM api_clients WHERE id = " . (int)$c1['id'])->fetchColumn();
check('apiClientCreate stores the scope', $row === 'users', (string)$row);
$c2 = apiClientCreate($db, 'legacy');
$row2 = $db->query("SELECT scope FROM api_clients WHERE id = " . (int)$c2['id'])->fetchColumn();
check('default scope is whitelist', $row2 === 'whitelist', (string)$row2);
$c3 = apiClientCreate($db, 'bad', 'nonsense');
$row3 = $db->query("SELECT scope FROM api_clients WHERE id = " . (int)$c3['id'])->fetchColumn();
check('unknown scope falls back to whitelist', $row3 === 'whitelist', (string)$row3);
$db->exec("DELETE FROM api_clients WHERE id IN (" . (int)$c1['id'] . ',' . (int)$c2['id'] . ',' . (int)$c3['id'] . ")");

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
