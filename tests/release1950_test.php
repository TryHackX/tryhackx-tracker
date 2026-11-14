<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/schema.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void {
    global $skips; $skips++;
    echo 'SKIP ' . $name . '  -> ' . $why . "\n";
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

check('version is 1.95.0 or later', version_compare(TRACKER_VERSION, '1.95.0', '>='), TRACKER_VERSION);
check('the changelog has the 1.95.0 entry', str_contains($src('CHANGELOG.md'), '## [1.95.0] — 2026-11-14'));
check('schema 116 or later', TRACKER_SCHEMA_VERSION >= 116, (string)TRACKER_SCHEMA_VERSION);

$ids = array_keys(userPermissionList(true));
$iv = array_search('index.view', $ids, true);
check('index.search is registered after index.view', $iv !== false && in_array('index.search', array_slice($ids, $iv + 1, 2), true));
$pre = userGroupPresets();
check('the member preset is the guest\'s and the search page to look at',
      $pre['member']['perms'] === ['stats.view', 'stats.timeline', 'home.stats', 'index.view'], implode(',', $pre['member']['perms']));
check('the verified preset carries the search and the features',
      in_array('index.search', $pre['verified']['perms'], true) && in_array('comment.post', $pre['verified']['perms'], true)
      && !in_array('profile.cover', $pre['verified']['perms'], true));
check('Member verified has a recommended set', in_array('verified', USER_RECOMMENDED_SLUGS, true) && userGroupRecommended('verified') !== null);
check('the moderator preset can search', in_array('index.search', $pre['moderator']['perms'], true));

check('domain lists: lower case, no @ or *., no junk, no duplicates',
      userEmailDomainList(" Mailinator.com, @yopmail.com\n*.temp-mail.org  not_a_domain x mailinator.com .grr.la. ")
      === ['mailinator.com', 'yopmail.com', 'temp-mail.org', 'grr.la']);
$deny = ['users_email_deny_enabled' => '1', 'users_email_deny' => "mailinator.com\nyopmail.com"];
check('a blocked domain is refused', userEmailDomainCheck($deny, 'someone@Mailinator.com') === 'email_domain_blocked');
check('… and its subdomains', userEmailDomainCheck($deny, 'someone@mx.mailinator.com') === 'email_domain_blocked');
check('… but not a domain that only ends the same way', userEmailDomainCheck($deny, 'someone@notmailinator.com') === null);
check('a blocked list that is switched off blocks nothing', userEmailDomainCheck(['users_email_deny' => 'mailinator.com'], 'a@mailinator.com') === null);
$allow = ['users_email_allow_enabled' => '1', 'users_email_allow' => "gmail.com\nwp.pl"];
check('an allowed provider passes', userEmailDomainCheck($allow, 'a@gmail.com') === null && userEmailDomainCheck($allow, 'a@wp.pl') === null);
check('any other provider is refused while the allowed list is on', userEmailDomainCheck($allow, 'a@example.org') === 'email_domain_not_allowed');
check('an empty allowed list refuses nobody', userEmailDomainCheck(['users_email_allow_enabled' => '1', 'users_email_allow' => ''], 'a@example.org') === null);
check('a blocked domain loses even when it is also allowed',
      userEmailDomainCheck($allow + ['users_email_deny_enabled' => '1', 'users_email_deny' => 'gmail.com'], 'a@gmail.com') === 'email_domain_blocked');

$def = trackerSchemaDefaultSettings();
check('both filters ship switched off', ($def['users_email_allow_enabled'] ?? '') === '0' && ($def['users_email_deny_enabled'] ?? '') === '0');
check('the suggested lists are valid domains, every one of them',
      count(userEmailDomainList($def['users_email_deny'] ?? '')) === count(explode("\n", $def['users_email_deny'] ?? ''))
      && count(userEmailDomainList($def['users_email_allow'] ?? '')) === count(explode("\n", $def['users_email_allow'] ?? ''))
      && count(explode("\n", $def['users_email_deny'] ?? '')) >= 30 && in_array('gmail.com', explode("\n", $def['users_email_allow'] ?? ''), true));
$save = $src('api/admin/save_settings.php');
check('the four settings are saved, the lists normalised',
      substr_count($save, "'users_email_allow_enabled'") >= 2 && substr_count($save, "'users_email_deny_enabled'") >= 2
      && str_contains($save, "'users_email_allow', 'users_email_deny'") && str_contains($save, 'userEmailDomainList((string)$data[$k])'));
check('switching the e-mail check puts Member verified in step', str_contains($save, 'userSyncVerifiedGroupAll($db, $data + $cfg);')
      && str_contains($src('includes/users.php'), "    userSyncVerifiedGroup(\$db, \$id, \$cfg);\n    return ['user' => userFindById(\$db, \$id)];"));
check('registration and an address change ask the filter',
      str_contains($src('api/user_register.php'), 'userEmailDomainCheck(') && str_contains($src('api/user_update.php'), 'userEmailDomainCheck('));
check('the settings page has both lists', str_contains($src('templates/admin/settings.php'), 'name="users_email_deny"')
      && str_contains($src('templates/admin/settings.php'), 'name="users_email_allow"'));

check('the search API asks for index.search', str_contains($src('api/index_search.php'), "userCan(\$db, \$cfg, 'index.search')"));
check('the search page shows a locked search with a note', str_contains($src('templates/pages/search.php'), "'search.need_verify'")
      && str_contains($src('templates/pages/search.php'), 'search-locked'));
$yt = $src('assets/js/ytplayer.js');
check('a player that stops being visible goes back to its preview',
      str_contains($yt, 'getClientRects().length') && str_contains($yt, 'new MutationObserver(queue)') && str_contains($yt, 'box.rtFacade'));

$db = null;
if (is_file($root . '/config/database.php')) {
    require_once $root . '/config/database.php';
    try { $db = getDb(); } catch (\Throwable $e) { $db = null; }
}
if (!$db) {
    skip('Member verified on a real account', 'no test database');
} else {
    $cfg = getSettings($db, true);
    ensureSchema($db, $cfg);
    $cfg = getSettings($db, true);
    $cfgGate = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '1']);
    $vg = userGroupBySlug($db, 'verified');
    check('the upgrade made Member verified', $vg !== null && (int)$vg['is_system'] === 1 && (int)$vg['priority'] === 2);
    $name = 'rel1950_' . bin2hex(random_bytes(3));
    $r = userCreate($db, $cfgGate, $name, $name . '@example.org', 'Password123!');
    $uid = (int)($r['user']['id'] ?? 0);
    if ($uid > 0) {
        $member = userGroupPermissions(userGroupBySlug($db, 'member')['permissions'] ?? null);
        $p0 = userEffectivePermissions($db, $uid, $cfgGate);
        ksort($p0); ksort($member);
        check('an account that has not confirmed its address holds exactly the member group', $p0 === $member, json_encode($p0));
        $tok = userVerifyCreate($db, $uid);
        userVerifyConsume($db, $tok);
        $slugs = array_column(userGroups($db, $uid), 'granted_by', 'slug');
        check('confirming the address puts it in Member verified', ($slugs['verified'] ?? '') === 'system', json_encode($slugs));
        $p1 = userEffectivePermissions($db, $uid, $cfgGate);
        $want = $member + userGroupPermissions($vg['permissions']);
        check('… and it then holds the member and the verified group', array_diff_key($want, $p1) === [], json_encode(array_keys(array_diff_key($want, $p1))));
        $db->prepare("UPDATE users SET email_verified = 0 WHERE id = ?")->execute([$uid]);
        userSyncVerifiedGroup($db, $uid);
        check('an address that is no longer confirmed takes it out again', !in_array('verified', array_column(userGroups($db, $uid), 'slug'), true));
        $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$uid]);
        userSyncVerifiedGroup($db, $uid);
        userRevokeGroup($db, $uid, (int)$vg['id'], false);
        userPermissionsForget($uid);
        $p2 = userEffectivePermissions($db, $uid, $cfgGate);
        ksort($p2);
        check('an admin who takes Member verified from a confirmed account takes its features with it', $p2 === $member, json_encode($p2));
        $db->prepare("UPDATE users SET email_verified = 0 WHERE id = ?")->execute([$uid]);
        $db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_by, note) VALUES (?, ?, 'admin', 'by hand')")->execute([$uid, (int)$vg['id']]);
        userPermissionsForget($uid);
        $p3 = userEffectivePermissions($db, $uid, $cfgGate);
        ksort($p3);
        check('a hand grant to an address that is not confirmed waits for the confirmation', $p3 === $member, json_encode($p3));
        userSyncVerifiedGroup($db, $uid);
        check('… and the e-mail sync leaves a hand grant alone', in_array('verified', array_column(userGroups($db, $uid), 'slug'), true));
        userDeleteCascade($db, $uid, $cfg);
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        $cfgOpen = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0']);
        $name2 = 'rel1950o_' . bin2hex(random_bytes(3));
        $r2 = userCreate($db, $cfgOpen, $name2, $name2 . '@example.org', 'Password123!');
        $uid2 = (int)($r2['user']['id'] ?? 0);
        $notes = array_column(userGroups($db, $uid2), 'note', 'slug');
        check('with the e-mail check off a new account is in Member verified at once', ($notes['verified'] ?? '') === 'e-mail check off', json_encode($notes));
        userSyncVerifiedGroupAll($db, $cfgGate);
        check('switching the check on takes the accounts without a confirmed address out', !in_array('verified', array_column(userGroups($db, $uid2), 'slug'), true));
        userSyncVerifiedGroupAll($db, $cfgOpen);
        check('… and switching it off puts them back', in_array('verified', array_column(userGroups($db, $uid2), 'slug'), true));
        userSyncVerifiedGroupAll($db, $cfgGate);
        if ($uid2 > 0) {
            userDeleteCascade($db, $uid2, $cfg);
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid2]);
        }
        $snap = $db->query("SELECT id, permissions FROM user_groups")->fetchAll(PDO::FETCH_KEY_PAIR);
        $perm = fn(string $slug): array => json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn(), true) ?: [];
        try {
            $db->exec("UPDATE user_groups SET permissions = '{\"index.view\":true,\"stats.view\":true}' WHERE slug = 'guest'");
            $db->exec("UPDATE user_groups SET permissions = '{\"index.view\":true,\"comment.post\":true}' WHERE slug = 'member'");
            schemaVerifiedGroup($db, $cfgGate);
            check('the upgrade where guests may search: guests keep searching, so does Member, and its features go to Member verified',
                  !empty($perm('guest')['index.search']) && !empty($perm('member')['index.search']) && empty($perm('member')['comment.post']),
                  json_encode([$perm('guest'), $perm('member')]));
        } finally {
            $up = $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?");
            foreach ($snap as $gid => $pj) $up->execute([$pj, $gid]);
            userPermissionsForget();
        }
    } else {
        check('a test account could be made', false, json_encode($r));
    }
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
