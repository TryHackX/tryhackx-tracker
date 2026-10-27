<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/users.php',
          'includes/lang.php', 'includes/facts.php', 'includes/tags.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfgT = ['tags_enabled' => '1', 'tags_review' => 'approve', 'tags_max_per_torrent' => '3', 'tags_name_max' => '32', 'tags_create_per_day' => '2',
         'facts_source' => 'desc_name'] + $cfg;
$GLOBALS['cfg'] = $cfgT;

check('names, addresses and colours of tags', tagsSlug('Zażółć gęślą Jaźń!') === 'zazolc-gesla-jazn' && tagsName("  Space   Opera ", 32) === 'Space Opera'
      && tagsName('<b>', 32) === 'b' && tagsName('<>', 32) === null && tagsName(str_repeat('a', 41), 40) === null && tagsColor('#ABC') === '#aabbcc' && tagsColor('#a1b2c3') === '#a1b2c3'
      && tagsColor('red') === null);
check('a name that is an automatic tag becomes that tag', tagsAutoMatch('HEVC') === 'v:h265' && tagsAutoMatch('1080p') === 'q:1080p'
      && tagsAutoMatch('web-dl') === 's:webdl' && tagsAutoMatch('Space Opera') === null);

$hh = fn(int $i): string => 'f1850' . str_repeat('0', 34) . $i;
$slugs = ['zyx-space-opera', 'zyx-noir', 'zyx-other', 'zyx-third', 'zyx-four', 'zyx-gone'];
$wipe = function () use ($db, $hh, $slugs): void {
    foreach ([1, 2, 3] as $i) {
        foreach (["DELETE FROM index_catalog WHERE info_hash = ?", "DELETE FROM index_hashes WHERE info_hash = ?", "DELETE FROM name_facts WHERE info_hash = ?",
                  "DELETE FROM hash_tags WHERE info_hash = ?", "DELETE FROM hash_tag_blocks WHERE info_hash = ?"] as $sql) $db->prepare($sql)->execute([$hh($i)]);
    }
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $db->prepare("DELETE FROM tags WHERE slug IN ($in)")->execute($slugs);
    $db->prepare("DELETE FROM users WHERE username IN ('tagtester', 'tagother')")->execute();
};
$wipe();
register_shutdown_function($wipe);
foreach ([[1, 'Zyxtg.One.2020.1080p.WEB-DL.x264'], [2, 'Zyxtg Two 2021 720p CAM x264'], [3, 'Zyxtg Three Holiday Album']] as [$i, $name]) {
    $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seen, meta_status, total_size, files_count) VALUES (?, ?, UTC_TIMESTAMP() - INTERVAL ? MINUTE, 'done', 1, 1)")->execute([$hh($i), $name, $i]);
    $db->prepare("INSERT INTO index_catalog (info_hash, name, eff_seeders, eff_leechers, total_size, files_count, last_seen) VALUES (?, ?, 1, 0, 1, 1, UTC_TIMESTAMP() - INTERVAL ? MINUTE)")->execute([$hh($i), $name, $i]);
}
factsNameStore($db, [['info_hash' => $hh(1), 'name' => 'Zyxtg.One.2020.1080p.WEB-DL.x264'], ['info_hash' => $hh(2), 'name' => 'Zyxtg Two 2021 720p CAM x264']]);

$c1 = tagsCreate($db, $cfgT, 'Zyx Space Opera', 9001, false);
$c2 = tagsCreate($db, $cfgT, 'Zyx Noir', 9001, false);
$c3 = tagsCreate($db, $cfgT, 'Zyx Other', 9001, false);
$cm = tagsCreate($db, $cfgT, 'Zyx Third', 9002, true, 'moderator');
$co = tagsCreate($db, ['tags_review' => 'open'] + $cfgT, 'Zyx Four', 9003, false);
check('a member\'s new tag waits, a moderator\'s or an open site\'s does not; two a day for this member, the third refused',
      $c1['status'] === 'pending' && $c2['status'] === 'pending' && $c3 === ['ok' => false, 'error' => 'limit'] && $cm['status'] === 'approved'
      && $co['status'] === 'approved' && tagsCreate($db, $cfgT, 'zyx space opera', 9004, false)['id'] === 'x:zyx-space-opera',
      json_encode([$c1, $c2, $c3, $cm, $co]));

$add = fn(int $i, string $id, int $uid, bool $mod = false) => tagsAttach($db, $cfgT, $hh($i), $id, $uid, $mod ? TAG_ORIGIN_MODERATOR : TAG_ORIGIN_MEMBER, $mod);
$a1 = $add(1, 'x:zyx-space-opera', 9001);
$a2 = $add(1, 'l:lektor_pl', 9001);
$a3 = $add(1, 'x:zyx-third', 9005);
$a4 = $add(1, 'x:zyx-four', 9005);
check('adding: a tag of its own and an automatic one by hand; at most three a torrent; no genre by hand',
      $a1['ok'] && $a2['ok'] && $a3['ok'] && $a4 === ['ok' => false, 'error' => 'too_many'] && $add(2, 'g:dramat', 9001) === ['ok' => false, 'error' => 'invalid'],
      json_encode([$a1, $a2, $a3, $a4]));
$seen = fn(?int $viewer, bool $mod) => array_column(tagsForHashes($db, [$hh(1)], $viewer, $mod)[$hh(1)]['add'] ?? [], 'id');
check('a waiting tag is seen by its author and the moderators only', in_array('x:zyx-space-opera', $seen(9001, false), true)
      && !in_array('x:zyx-space-opera', $seen(9005, false), true) && in_array('x:zyx-space-opera', $seen(null, true), true)
      && in_array('x:zyx-third', $seen(9005, false), true));

check('taking back: one\'s own yes, somebody else\'s no, a moderator\'s yes',
      tagsDetach($db, $hh(1), 'x:zyx-third', 9001, false) === ['ok' => false, 'error' => 'forbidden']
      && tagsDetach($db, $hh(1), 'l:lektor_pl', 9001, false)['ok'] && tagsDetach($db, $hh(1), 'x:zyx-third', 9002, true)['others'] === true);
$blockMember = tagsDetach($db, $hh(2), 's:cam', 9001, false);
$block = tagsDetach($db, $hh(2), 's:cam', 9002, true);
$bits = (int)$db->query("SELECT nf_bits FROM name_facts WHERE info_hash = '" . $hh(2) . "'")->fetchColumn();
check('a wrong automatic tag: a member may not remove it, a moderator blocks it — gone from the stored facts too',
      $blockMember === ['ok' => false, 'error' => 'forbidden'] && $block === ['ok' => true, 'removed' => 'blocked']
      && ($bits & (1 << array_search('s:cam', FACTS_NAME_BITS, true))) === 0 && in_array('s:cam', tagsBlocked($db, [$hh(2)])[$hh(2)] ?? [], true));
$merged = tagsMergeChips(factsNameChips('Zyxtg Two 2021 720p CAM x264'), tagsForHashes($db, [$hh(2)], null, false)[$hh(2)] ?? null, $cfgT);
check('… and from the chips; a member cannot put it back, a moderator can (which lifts the block)',
      !in_array('s:cam', array_column($merged['tags'] ?? [], 'id'), true) && $add(2, 's:cam', 9001) === ['ok' => false, 'error' => 'blocked']
      && $add(2, 's:cam', 9002, true) === ['ok' => true, 'unblocked' => true] && !(tagsBlocked($db, [$hh(2)])[$hh(2)] ?? []));

$db->exec("UPDATE tags SET status = 'approved' WHERE slug = 'zyx-space-opera'");
$hits = function (array $get) use ($db, $cfgT): array {
    $r = indexSearchCatalogue($db, $cfgT, ['search' => 'Zyxtg', 'include_whitelist' => false, 'catalog' => false, 'per_page' => 20, 'page' => 1]
                                         + factsEngineFilters($db, $cfgT, factsRequest($get), true, true));
    $h = array_values(array_filter(array_column($r['rows'], 'info_hash'), fn($x) => str_starts_with((string)$x, 'f1850')));
    sort($h);
    return $h;
};
check('filters: a tag people added, one from a name and one added by hand together, each set on its own',
      $hits(['tags' => 'x:zyx-space-opera']) === [$hh(1)] && $hits(['tags' => 'q:1080p,x:zyx-space-opera']) === [$hh(1)]
      && $hits(['tags' => 'q:720p,x:zyx-space-opera']) === [] && $hits(['tags' => 's:cam']) === [$hh(2)]
      && $hits(['tags' => 'x:zyx-noir']) === [], json_encode([$hits(['tags' => 'x:zyx-space-opera']), $hits(['tags' => 's:cam'])]));
[$w, $p] = factsListFilters($db, $cfgT, factsRequest(['tags' => 'x:zyx-space-opera']), 'COALESCE(scrape_seeders, 0)', 'whitelist');
check('… the panel\'s whitelist list gets the same condition', count($w) === 1 && str_contains($w[0], 'info_hash IN') && in_array($hh(1), $p, true));

tagsUsesTick($db);
$list = tagsAdminList($db, ['q' => 'zyx', 'status' => 'pending']);
check('the panel\'s list: what waits, with its uses', array_column($list['rows'], 'slug') === ['zyx-noir'] && $list['pending'] >= 1
      && (int)$db->query("SELECT uses FROM tags WHERE slug = 'zyx-space-opera'")->fetchColumn() === 1);
$idOf = fn(string $slug): int => (int)$db->query("SELECT id FROM tags WHERE slug = " . $db->quote($slug))->fetchColumn();
$add(3, 'x:zyx-four', 9005);
check('the panel\'s actions: renaming, a colour and hiding need "manage"; approving does not',
      tagsAdminAction($db, $cfgT, 'save', ['id' => $idOf('zyx-four'), 'name' => 'Zyx Four!', 'color' => '#f80'], false) === ['ok' => false, 'error' => 'forbidden']
      && tagsAdminAction($db, $cfgT, 'save', ['id' => $idOf('zyx-four'), 'name' => 'Zyx Four!', 'color' => '#f80'], true)['ok']
      && $db->query("SELECT CONCAT(name, ' ', color) FROM tags WHERE slug = 'zyx-four'")->fetchColumn() === 'Zyx Four! #ff8800'
      && tagsAdminAction($db, $cfgT, 'approve', ['ids' => [$idOf('zyx-noir')]], false)['ok']
      && tagsAdminAction($db, $cfgT, 'hide', ['id' => $idOf('zyx-noir')], true)['ok'] && $hits(['tags' => 'x:zyx-noir']) === []);
$merge = tagsAdminAction($db, $cfgT, 'merge', ['id' => $idOf('zyx-four'), 'into' => $idOf('zyx-space-opera')], true);
check('… merging moves the torrents to the other tag and deletes the first', $merge['ok'] && $idOf('zyx-four') === 0 && $hits(['tags' => 'x:zyx-space-opera']) === [$hh(1), $hh(3)]);
$add(3, 'x:zyx-third', 9002, true);
$rej = tagsAdminAction($db, $cfgT, 'reject', ['id' => $idOf('zyx-third')], false);
check('… rejecting takes the tag off every torrent and it cannot be made again', $rej['ok'] && $hits(['tags' => 'x:zyx-third']) === []
      && tagsCreate($db, $cfgT, 'Zyx Third', 9002, true) === ['ok' => false, 'error' => 'rejected']);

$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg185_runner_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php", "includes/whitelist.php",
          "includes/index.php", "includes/richtext.php", "includes/content.php", "includes/mail.php", "includes/users.php", "includes/audit.php", "includes/auth.php",
          "includes/lang.php", "includes/facts.php", "includes/tags.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () {
    fwrite(STDERR, "STATUS:" . (int)http_response_code() . "\n");
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
langInit($cfg, "en");
require "api/" . $a["endpoint"] . ".php";
');
$ask = static function (string $endpoint, string $method, array $session, array $get = [], array $post = []) use ($root, $runner, $cfgT): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg185_args_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($arg, json_encode(['root' => $root, 'endpoint' => $endpoint, 'method' => $method, 'get' => $get, 'post' => $post,
                                         'cfg' => ['users_enabled' => '1', 'antispam_enabled' => '0', 'user_require_email_verify' => '0'] + array_intersect_key($cfgT, array_flip(['tags_enabled', 'tags_review', 'tags_max_per_torrent', 'tags_name_max', 'tags_create_per_day', 'facts_source'])),
                                         'session' => $session, 'ip' => '203.0.113.' . random_int(10, 250), 'sid' => 'tg185' . bin2hex(random_bytes(8))]));
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'xdebug.mode=off', $runner, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    $errOut = (string)stream_get_contents($pipes[2]);
    foreach ($pipes as $h) fclose($h);
    proc_close($p);
    @unlink($arg);
    $j = json_decode(trim($out), true);
    $j = is_array($j) ? $j : ['__raw' => substr($out . ' ' . $errOut, 0, 300)];
    $j['__status'] = preg_match('/STATUS:(\d+)/', $errOut, $sm) ? (int)$sm[1] : 0;
    return $j;
};
$mk = userCreate($db, $cfg, 'tagtester', 'tagtester@example.org', 'TagTester123!', '127.0.0.1');
$uid = (int)($mk['user']['id'] ?? 0);
$db->prepare("UPDATE users SET status = 'active', email_verified = 1 WHERE id = ?")->execute([$uid]);
$sess = ['user_id' => $uid, 'user_login_time' => time() + 60, 'csrf_token' => 'tg185-token'];
$r1 = $ask('tag_add', 'POST', $sess, [], ['csrf_token' => 'tg185-token', 'hash' => $hh(3), 'name' => 'Zyx Gone']);
$r2 = $ask('tag_add', 'POST', $sess, [], ['csrf_token' => 'tg185-token', 'hash' => $hh(3), 'tag' => 'q:2160p']);
$r3 = $ask('tag_remove', 'POST', $sess, [], ['csrf_token' => 'tg185-token', 'hash' => $hh(2), 'tag' => 'v:h264']);
$r4 = $ask('tag_remove', 'POST', $sess, [], ['csrf_token' => 'tg185-token', 'hash' => $hh(3), 'tag' => 'q:2160p']);
$r5 = $ask('tag_add', 'POST', $sess, [], ['csrf_token' => 'wrong', 'hash' => $hh(3), 'tag' => 'q:2160p']);
$r6 = $ask('tag_add', 'POST', ['csrf_token' => 'tg185-guest'], [], ['csrf_token' => 'tg185-guest', 'hash' => $hh(3), 'tag' => 'q:2160p']);
$ids = fn(array $r) => array_column($r['facts']['tags'] ?? [], 'id');
check('the endpoints as requests: a member makes a tag (it waits, marked as theirs) and adds an automatic one by hand',
      ($r1['success'] ?? false) && ($r1['pending'] ?? false) === true && in_array('x:zyx-gone', $ids($r1), true)
      && ($r2['success'] ?? false) && in_array('q:2160p', $ids($r2), true), json_encode([$r1, $r2]));
check('… may not take an automatic tag off, takes back their own; a wrong token or no account is refused',
      ($r3['__status'] ?? 0) === 400 && ($r3['error'] ?? '') === 'forbidden' && ($r4['success'] ?? false) && !in_array('q:2160p', $ids($r4), true)
      && ($r5['__status'] ?? 0) === 403 && ($r6['__status'] ?? 0) === 401, json_encode([$r3, $r4, $r5, $r6]));
$s1 = $ask('tags_search', 'GET', [], ['q' => 'zyx']);
$s2 = $ask('tags_search', 'GET', [], ['q' => 'hevc']);
check('the search for the picker: approved tags only, and the automatic ones by name',
      in_array('x:zyx-space-opera', array_column($s1['tags'] ?? [], 'id'), true) && !in_array('x:zyx-gone', array_column($s1['tags'] ?? [], 'id'), true)
      && in_array('v:h265', array_column($s2['tags'] ?? [], 'id'), true), json_encode([$s1, $s2]));
@unlink($runner);

$api = $src('api.php');
check('the routes and who may use them', str_contains($api, "'tags_search'                => 'api/tags_search.php'") && str_contains($api, "'tag_add'                    => 'api/tag_add.php'")
      && str_contains($api, "'tag_remove'                 => 'api/tag_remove.php'") && str_contains($api, "'admin/tags'                  => 'panel.tags.view'")
      && str_contains($api, "'admin/tag_action'            => 'panel.tags.view'") && str_contains($src('api/admin/tag_action.php'), "panelCan(\$db, \$cfg, 'panel.tags.manage')")
      && str_contains($src('api/admin/tags.php'), 'tagsAdminList($db, ['));
$perms = userPermissionList();
check('the permissions: members add and create, moderators moderate and see the panel page; nobody with accounts off',
      isset($perms['tags.add'], $perms['tags.create'], $perms['tags.moderate'], $perms['panel.tags.view'], $perms['panel.tags.manage'])
      && in_array('tags.add', userGroupPresets()['member']['perms'], true) && in_array('tags.moderate', userGroupPresets()['moderator']['perms'], true)
      && !userLegacyDefault('tags.add') && !userLegacyDefault('tags.moderate'));
$ixSrc = $src('index.php');
check('the pages: the panel\'s Tags page in the nav, the public Tags page, the Info panel\'s editor',
      str_contains($src('includes/auth.php'), "['action' => 'admin-tags',      'label' => 'Tags'") && str_contains($ixSrc, "include __DIR__ . '/templates/admin/tags.php';")
      && str_contains($src('includes/functions.php'), "'tags'         => 'templates/pages/tags.php'") && str_contains($src('assets/js/app.js'), 'window.TagEditor.mount(tagBox, json, pick)')
      && str_contains($src('assets/js/filters.js'), 'window.TagEditor = {') && is_file($root . '/assets/js/admin-tags.js'));
$def = trackerSchemaDefaultSettings();
check('the settings: on, new tags wait, 40 a torrent, 32 characters, 20 a day', $def['tags_enabled'] === '1' && $def['tags_review'] === 'approve'
      && $def['tags_max_per_torrent'] === '40' && $def['tags_name_max'] === '32' && $def['tags_create_per_day'] === '20'
      && str_contains($src('templates/admin/settings.php'), 'name="tags_review"') && TRACKER_SCHEMA_VERSION >= 107);
$conds = factsFilterConds($db, $cfgT, factsRequest(['tags' => 'q:1080p,x:zyx-space-opera', 'type' => 'movie'])['facts'], true, true);
check('several sets make ONE condition: the name checked once for all of them, the torrents with a description or a hand-added tag set by set',
      count($conds) === 1 && substr_count((string)$conds[0][1], 'FROM name_facts nf') <= 4 && str_contains((string)$conds[0][1], 'info_hash IN'), json_encode($conds));
$plL = include $root . '/lang/pl.php';
check('the words, in both languages', __('js.tags.edit_tags') === 'Edit tags' && ($plL['js.tags.edit_tags'] ?? '') === 'Edytuj tagi'
      && ($plL['perm.panel.tags.manage'] ?? '') !== '' && __('a.head.nav.admin_tags') === 'Tags');

$wipe();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
