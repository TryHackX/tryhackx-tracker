<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/lang.php';

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

check('version is 1.96.0 or later', version_compare(TRACKER_VERSION, '1.96.0', '>='), TRACKER_VERSION);
check('the changelog has the 1.96.0 entry', str_contains($src('CHANGELOG.md'), '## [1.96.0] — 2026-11-14'));
check('schema 117 or later', TRACKER_SCHEMA_VERSION >= 117, (string)TRACKER_SCHEMA_VERSION);

$ids = array_keys(userPermissionList(true));
$iv = array_search('index.view', $ids, true);
check('index.browse is registered between index.view and index.search',
      $iv !== false && ($ids[$iv + 1] ?? '') === 'index.browse' && ($ids[$iv + 2] ?? '') === 'index.search', implode(',', array_slice($ids, 0, 4)));
$pre = userGroupPresets();
check('Member only looks at the page: no index.browse, no index.search',
      in_array('index.view', $pre['member']['perms'], true) && !in_array('index.browse', $pre['member']['perms'], true)
      && !in_array('index.search', $pre['member']['perms'], true));
check('Member verified and the moderator browse and search',
      !array_diff(['index.view', 'index.browse', 'index.search'], $pre['verified']['perms'])
      && !array_diff(['index.view', 'index.browse', 'index.search'], $pre['moderator']['perms']));
check('both languages name it', langFor('en', 'perm.index.browse') !== 'perm.index.browse' && langFor('pl', 'perm.index.browse') !== 'perm.index.browse'
      && str_contains(langFor('pl', 'perm.index.browse'), 'Przeglądanie'));
$schema = $src('includes/schema.php');
check('the seeds of the admin and moderator groups carry it', substr_count($schema, '\\"index.view\\":true,\\"index.browse\\":true,\\"index.search\\":true') === 3);

$gate = fn(string $f, string $needle): bool => str_contains($src($f), $needle);
check('the Info panel, the file lists and the magnet counts need index.browse',
      $gate('api/index_info.php', "if (!userCan(\$db, \$cfg, 'index.browse')) {")
      && $gate('api/index_files.php', "if (!userCan(\$db, \$cfg, 'index.browse') || !userCan(\$db, \$cfg, 'index.files')) {")
      && $gate('api/magnet_stats.php', "if (!userCan(\$db, \$cfg, 'index.browse') || !userCan(\$db, \$cfg, 'index.magnet')) {"));
check('the list asks index.view and index.browse, a typed term index.search as well',
      $gate('api/index_search.php', "if (!userCan(\$db, \$cfg, 'index.view') || !userCan(\$db, \$cfg, 'index.browse')) {")
      && $gate('api/index_search.php', "if (\$search !== '' && !userCan(\$db, \$cfg, 'index.search')) {")
      && $gate('api/index_search.php', "'code' => 'search_not_allowed'], 403);"));
check('favourites, lists, "who has this", comments, reports and the hash check ask index.browse',
      $gate('api/user_favourites.php', "userCan(\$db, \$cfg, 'index.browse')")
      && substr_count($src('api/user_list_items.php'), "userCan(\$db, \$cfg, 'index.browse')") === 2
      && $gate('api/hash_favourites.php', "'index.browse')) jsonResponse(['error' => 'not_found'], 404);")
      && $gate('includes/who.php', "userIdHasPermission(\$db, \$cfg, \$vid, 'index.browse')")
      && $gate('includes/comments.php', "commentReaderCan(\$db, \$cfg, \$me, 'index.browse')")
      && $gate('includes/reports.php', "userIdHasPermission(\$db, \$cfg, \$uid, 'index.browse')")
      && $gate('includes/hashcheck.php', "userCan(\$db, \$cfg, 'index.browse'),")
      && $gate('includes/favourites.php', "userCan(\$db, \$cfg, 'favourites.use') && userCan(\$db, \$cfg, 'index.browse');"));
$noView = [];
foreach (['api/index_info.php', 'api/index_files.php', 'api/magnet_stats.php', 'api/user_favourites.php', 'api/user_list_items.php',
          'api/hash_favourites.php', 'includes/who.php', 'includes/comments.php', 'includes/reports.php', 'includes/hashcheck.php', 'includes/favourites.php'] as $f) {
    if (preg_match("/(userCan|userIdHasPermission|commentReaderCan)\([^;]*'index\.view'/", $src($f))) $noView[] = $f;
}
check('… and none of them asks index.view for it any more', $noView === [], implode(', ', $noView));
$page = $src('templates/pages/search.php');
check('the search page: the list with index.browse, the box only with index.search',
      str_contains($page, "\$canBrowse = \$canView && userCan(\$db, \$cfg, 'index.browse');")
      && str_contains($page, "\$canSearch = \$canBrowse && userCan(\$db, \$cfg, 'index.search');")
      && str_contains($page, "autocomplete=\"off\"<?= \$canSearch ? '' : ' disabled' ?>>")
      && str_contains($page, "_h('search.browse_only')") && str_contains($page, '<?php elseif (!$canBrowse): ?>'));
check('the menu item still needs only index.view', str_contains($src('templates/nav.php'), "userCan(\$db, \$cfg, 'index.view')"));
check('a link with a term does not fill a box that is switched off', str_contains($src('assets/js/app.js'), "input.value = input.disabled ? '' : st.search;"));

$js = $src('assets/js/app.js');
check('one button handler for every "send the link again"', str_contains($js, "const btn = e.target.closest('[data-verify-send]');")
      && str_contains($js, "postJson('user_verify_send', { csrf_token: csrfToken(btn) })") && !str_contains($js, "\$id('acc-verify-send')"));
check('the note sits on every page but the account and the link pages', str_contains($src('templates/layout.php'),
      "<?php if (!in_array(\$action, ['account', 'verify'], true)) include __DIR__ . '/partials/verify_nudge.php'; ?>"));
check('the account page, the note and a spent link all have the button',
      str_contains($src('templates/pages/account.php'), 'id="acc-verify-send" data-verify-send')
      && str_contains($src('templates/partials/verify_nudge.php'), 'data-verify-send')
      && str_contains($src('templates/pages/verify.php'), 'data-verify-send'));
check('asking too often is said in words', str_contains($src('api/user_verify_send.php'), "'message' => __('api.users.verify_rate_limit')"));
check('the account page no longer says guest level', !str_contains(langFor('en', 'account.restricted'), 'guest level')
      && !str_contains(langFor('pl', 'account.restricted'), 'gościa'));

$db = null;
if (is_file($root . '/config/database.php')) {
    require_once $root . '/config/database.php';
    try { $db = getDb(); } catch (\Throwable $e) { $db = null; }
}
if (!$db) {
    skip('index.browse on a real database', 'no test database');
} else {
    $cfg = getSettings($db, true);
    ensureSchema($db, $cfg);
    $cfg = getSettings($db, true);
    $perm = fn(string $slug): array => json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn(), true) ?: [];
    $without = [];
    foreach ($db->query("SELECT slug, permissions FROM user_groups")->fetchAll(PDO::FETCH_KEY_PAIR) as $slug => $pj) {
        $p = json_decode((string)$pj, true) ?: [];
        if (!empty($p['index.search']) && empty($p['index.browse'])) $without[] = $slug;
    }
    check('the upgrade gave index.browse to every group that searches', $without === [], implode(', ', $without));
    $snap = $db->query("SELECT id, permissions FROM user_groups")->fetchAll(PDO::FETCH_KEY_PAIR);
    try {
        $db->exec("UPDATE user_groups SET permissions = '{\"index.view\":true,\"index.search\":true}' WHERE slug = 'verified'");
        $db->exec("UPDATE user_groups SET permissions = '{\"index.view\":true,\"stats.view\":true}' WHERE slug = 'member'");
        schemaGrantBrowse($db);
        check('the migration: a group that searches browses, one that only looks does not',
              !empty($perm('verified')['index.browse']) && empty($perm('member')['index.browse']), json_encode([$perm('verified'), $perm('member')]));
    } finally {
        $up = $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?");
        foreach ($snap as $gid => $pj) $up->execute([$pj, $gid]);
        userPermissionsForget();
    }
    $cfgGate = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '1', 'index_enabled' => '1', 'index_search_enabled' => '1']);
    $name = 'rel1960_' . bin2hex(random_bytes(3));
    $r = userCreate($db, $cfgGate, $name, $name . '@example.org', 'Password123!');
    $uid = (int)($r['user']['id'] ?? 0);
    if ($uid > 0) {
        $p0 = userEffectivePermissions($db, $uid, $cfgGate);
        check('an account that has not confirmed its address sees the page and nothing of the index',
              !empty($p0['index.view']) && empty($p0['index.browse']) && empty($p0['index.search']), json_encode($p0));
        langInit([], 'en');
        $navUser = userFindById($db, $uid);
        $baseUrl = '/';
        $render = function () use ($db, $cfgGate, &$navUser, $baseUrl, $root): string {
            $cfg = $cfgGate;
            ob_start();
            include $root . '/templates/partials/verify_nudge.php';
            return (string)ob_get_clean();
        };
        $h0 = $render();
        check('… and every page shows it the note with the button', str_contains($h0, 'data-verify-send') && str_contains($h0, 'verify-nudge'), $h0);
        $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$uid]);
        userSyncVerifiedGroup($db, $uid);
        $p1 = userEffectivePermissions($db, $uid, $cfgGate);
        check('confirmed: it browses and searches', !empty($p1['index.browse']) && !empty($p1['index.search']), json_encode(array_keys($p1)));
        $navUser = userFindById($db, $uid);
        check('… and the note is gone', trim($render()) === '');
        $db->prepare("UPDATE users SET email_verified = 0, email = NULL WHERE id = ?")->execute([$uid]);
        userSyncVerifiedGroup($db, $uid);
        $navUser = userFindById($db, $uid);
        $h2 = $render();
        check('an account without an address is sent to add one, with no button', !str_contains($h2, 'data-verify-send') && str_contains($h2, '?action=account'), $h2);
        userDeleteCascade($db, $uid, $cfg);
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    } else {
        check('a test account could be made', false, json_encode($r));
    }
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
