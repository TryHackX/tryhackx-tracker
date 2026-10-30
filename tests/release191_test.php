<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/schedule.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/sounds.php',
          'includes/shout.php', 'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/auth.php', 'includes/icons.php',
          'includes/api_auth.php', 'includes/settings_catalog.php', 'includes/reputation.php', 'includes/lang.php', 'includes/comments.php',
          'includes/lists.php', 'includes/profilebio.php', 'includes/reports.php', 'includes/moderation.php',
          'includes/facts.php', 'includes/tags.php', 'includes/thumbs.php', 'includes/wlrequests.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db;
$GLOBALS['cfg'] = $cfg;
langInit([], 'en');
session_start();

$h1 = str_repeat('f1', 20);
$h2 = str_repeat('f2', 20);
$h4 = str_repeat('f4', 20);
$h5 = str_repeat('f5', 20);
$slugs = "'t191-gone', 't191-cat', 't191-hid', 't191-rej', 't191-new', 't191-old', 't191-sa', 't191-sb', 't191-sc', 't191-g'";
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$wipe = function () use ($db, $slugs, $h1, $h2, $h4, $h5, $auditFloor): void {
    $db->prepare("DELETE FROM hash_tags WHERE info_hash IN (?, ?, ?)")->execute([$h1, $h2, $h4]);
    $db->exec("DELETE FROM tags WHERE slug IN ($slugs)");
    if (tagsGroupReady($db)) $db->exec("DELETE FROM tag_cat_groups WHERE slug IN ('grupa-191', 'grupa-191-b')");
    $db->exec("DELETE FROM tag_categories WHERE slug = 'kat-191'");
    if (function_exists('indexCatalogForget')) indexCatalogForget($db, [$h1, $h2]);
    $db->prepare("DELETE FROM index_hashes WHERE info_hash IN (?, ?)")->execute([$h1, $h2]);
    $db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$h4]);
    $db->prepare("DELETE FROM banned_hashes WHERE info_hash IN (?, ?)")->execute([$h1, $h4]);
    $db->prepare("DELETE FROM content_reports WHERE kind = 'torrent' AND info_hash IN (?, ?, ?)")->execute([$h1, $h4, $h5]);
    foreach (['r191_rep', 'r191_own'] as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    }
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
};
$wipe();
register_shutdown_function(function () use ($wipe) {
    $wipe();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});

check('schema 115: groups of categories, and a torrent is a kind of report', TRACKER_SCHEMA_VERSION >= 115 && schemaTableExists($db, 'tag_cat_groups')
      && schemaColumnExists($db, 'tag_categories', 'group_id') && str_contains((string)schemaColumnType($db, 'content_reports', 'kind'), "'torrent'"));
check('… the new switches by default: a torrent\'s own magnet hidden, unused tags deleted, reporting a torrent off',
      ($cfg['rt_magnet_self_show'] ?? '') === '0' && ($cfg['tags_prune_unused'] ?? '') === '1' && ($cfg['torrent_reports_enabled'] ?? '') === '0');
$modPerms = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'moderator'")->fetchColumn();
check('… and the torrents\' queue with its own pair of panel permissions, given to the moderators once',
      contentReportPerm('torrent', 'view') === 'panel.reports.torrents.view' && contentReportPerm('torrent', 'handle') === 'panel.reports.torrents.handle'
      && str_contains($src('includes/schema.php'), "schemaGrantOnce(\$db, 'v115_torrent_reports'")
      && str_contains($modPerms, 'panel.reports.torrents.view') && str_contains($modPerms, 'panel.reports.torrents.handle'));
check('… the three switches in Settings', str_contains($src('templates/admin/settings.php'), 'rt_magnet_self_show') && str_contains($src('templates/admin/settings.php'), 'tags_prune_unused')
      && str_contains($src('templates/admin/settings.php'), 'torrent_reports_enabled') && str_contains($src('api/admin/save_settings.php'), "'torrent_reports_enabled'"));

$mh = str_repeat('f7', 20);
$m = richtextMagnetParse('magnet:?xt=urn:btih:' . $mh . '&dn=Release191');
check('a torrent\'s own magnet in its description: hidden by default, shown when the setting says so, any other one always',
      $m !== null && richtextMagnetCard($m, ['rt_magnet_self' => $mh] + $cfg) === ''
      && richtextMagnetCard($m, ['rt_magnet_self' => $mh, 'rt_magnet_self_show' => '1'] + $cfg) !== ''
      && richtextMagnetCard($m, ['rt_magnet_self' => str_repeat('f6', 20)] + $cfg) !== '');

$db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, last_seeders, meta_status) VALUES (?, 'Release191 Index Only 1080p', NOW(), NOW(), 3, 'done'),
              (?, 'Release191 Second', NOW(), NOW(), 1, 'done')")->execute([$h1, $h2]);
$mk = $db->prepare("INSERT INTO tags (slug, name, status, origin, hidden, created_at) VALUES (?, ?, ?, 'member', ?, UTC_TIMESTAMP() - INTERVAL ? MINUTE)");
foreach ([['t191-gone', 'T191 gone', 'approved', 0, 60], ['t191-cat', 'T191 cat', 'approved', 0, 60], ['t191-hid', 'T191 hid', 'approved', 1, 60],
          ['t191-rej', 'T191 rej', 'rejected', 0, 60], ['t191-new', 'T191 new', 'pending', 0, 0], ['t191-old', 'T191 old', 'pending', 0, 60]] as $t) $mk->execute($t);
tagsSeedBase($db);
tagsCatSeed($db);
$catId = fn(string $slug) => (int)$db->query("SELECT id FROM tag_categories WHERE slug = " . $db->quote($slug))->fetchColumn();
$db->prepare("UPDATE tags SET category_id = ? WHERE slug = 't191-cat'")->execute([$catId('release')]);
$have = fn(string $s) => (int)$db->query("SELECT COUNT(*) FROM tags WHERE slug = " . $db->quote($s))->fetchColumn() === 1;
tagsAttach($db, $cfg, $h1, 'x:t191-gone', null, TAG_ORIGIN_MEMBER, true);
tagsCount($db, 'x:t191-gone');
$kept = tagsPrune($db, $cfg, 'x:t191-gone');
tagsDetach($db, $h1, 'x:t191-gone', null, true);
tagsCount($db, 'x:t191-gone');
$off = tagsPrune($db, ['tags_prune_unused' => '0'] + $cfg, 'x:t191-gone');
$gone = tagsPrune($db, $cfg, 'x:t191-gone');
check('a tag nobody uses any more is deleted when it is taken off — not while a torrent has it, not with the setting off',
      $kept === 0 && $off === 0 && $gone === 1 && !$have('t191-gone'), json_encode([$kept, $off, $gone]));
$base = (string)array_key_first(TAGS_BASE);
$baseCat = $db->query("SELECT category_id FROM tags WHERE slug = " . $db->quote($base))->fetchColumn();
$db->prepare("UPDATE tags SET category_id = NULL WHERE slug = ?")->execute([$base]);
$sweep = tagsPrune($db, $cfg);
$db->prepare("UPDATE tags SET category_id = ? WHERE slug = ?")->execute([$baseCat !== false ? $baseCat : null, $base]);
check('… and by the janitor: an old unused one goes; a fresh one, one in a category, a hidden or rejected one and a base tag stay',
      $sweep >= 1 && !$have('t191-old') && $have('t191-new') && $have('t191-cat') && $have('t191-hid') && $have('t191-rej') && $have($base),
      json_encode([$sweep, $have('t191-old'), $have('t191-new'), $have('t191-cat'), $have('t191-hid'), $have('t191-rej'), $have($base)]));
check('… from the remove endpoint and the uses tick, keeping the forum\'s mapped tags', str_contains($src('api/tag_remove.php'), 'tagsPrune($db, $cfg, $id);')
      && str_contains($src('includes/tags.php'), "tagsPrune(\$db, \$GLOBALS['cfg'] ?? []);")
      && str_contains($src('includes/tags.php'), "NOT EXISTS (SELECT 1 FROM tag_forum_map m WHERE m.target = CONCAT('x:', t.slug))"));

foreach ([['t191-sa', 'T191 S b', 'approved', 0, 60], ['t191-sb', 'T191 S a', 'pending', 0, 60], ['t191-sc', 'T191 S c', 'rejected', 0, 60]] as $t) $mk->execute($t);
$db->prepare("UPDATE tags SET category_id = ? WHERE slug = 't191-sa'")->execute([$catId('language')]);
$db->prepare("UPDATE tags SET category_id = ? WHERE slug = 't191-sc'")->execute([$catId('release')]);
$order = fn(string $sort) => array_column(tagsAdminList($db, ['q' => 'T191 S', 'sort' => $sort, 'per_page' => 50])['rows'], 'slug');
check('the tags\' table sorts by any column, by several at once, and ignores what it does not know',
      $order('name:asc') === ['t191-sb', 't191-sa', 't191-sc'] && $order('name:desc') === ['t191-sc', 't191-sa', 't191-sb']
      && $order('status:desc,name:asc') === ['t191-sc', 't191-sb', 't191-sa'] && $order('cat:asc') === ['t191-sa', 't191-sc', 't191-sb']
      && $order("bogus:asc,name';--") === $order('uses:desc'),
      json_encode([$order('name:asc'), $order('status:desc,name:asc'), $order('cat:asc')]));
check('… from its headers; a tag is edited in a window of its own', str_contains($src('templates/admin/tags.php'), 'data-sort="status"')
      && str_contains($src('assets/js/admin-tags.js'), 'A.makeSortStack(') && str_contains($src('templates/admin/tags.php'), 'id="tagEditModal"')
      && !str_contains($src('assets/js/admin-tags.js'), 'function editRow('));

$g1 = tagsGroupAction($db, 'grp_add', ['name' => 'Grupa 191', 'pinned' => 1]);
$dupG = tagsGroupAction($db, 'grp_add', ['name' => 'Grupa 191']);
$k = tagsCatAction($db, 'cat_add', ['name' => 'Kat 191'], null);
$kid = (int)($k['id'] ?? 0);
$gid = (int)($g1['id'] ?? 0);
$in1 = tagsGroupAction($db, 'cat_group', ['id' => $kid, 'group' => $gid]);
$inSys = tagsGroupAction($db, 'cat_group', ['id' => $catId('language'), 'group' => $gid]);
check('groups of categories: added once, a category put in one, a built-in group cannot be', !empty($g1['ok']) && ($dupG['error'] ?? '') === 'exists'
      && ($in1['n'] ?? 0) === 1 && ($inSys['n'] ?? 1) === 0, json_encode([$g1, $dupG, $in1, $inSys]));
$mk->execute(['t191-g', 'T191 grouped', 'approved', 0, 60]);
$db->prepare("UPDATE tags SET category_id = ?, uses = 3 WHERE slug = 't191-g'")->execute([$kid]);
$entry = fn() => array_values(array_filter(tagsCatalog($db, 5000), fn($c) => $c['id'] === 'x:t191-g'))[0] ?? [];
$e1 = $entry();
tagsGroupAction($db, 'grp_save', ['id' => $gid, 'pinned' => 0]);
$e2 = $entry();
check('… the filters get a tag\'s group with its category, and whether the group has a card of its own', ($e1['k'] ?? '') === 'kat-191' && ($e1['p'] ?? '') === 'grupa-191'
      && ($e1['pl'] ?? '') === 'Grupa 191' && ($e1['pp'] ?? null) === 1 && ($e2['pp'] ?? null) === 0, json_encode([$e1, $e2]));
$g2 = tagsGroupAction($db, 'grp_add', ['name' => 'Grupa 191 b']);
$before = array_column(tagsGroupList($db), 'slug');
tagsGroupAction($db, 'grp_move', ['id' => (int)($g2['id'] ?? 0), 'dir' => -1]);
$after = array_column(tagsGroupList($db), 'slug');
$ren = tagsGroupAction($db, 'grp_save', ['id' => $gid, 'name' => 'Grupa 191 nowa']);
check('… moved, renamed', array_search('grupa-191-b', $after, true) === array_search('grupa-191-b', $before, true) - 1 && !empty($ren['ok'])
      && (string)$db->query("SELECT name FROM tag_cat_groups WHERE id = $gid")->fetchColumn() === 'Grupa 191 nowa', json_encode([$before, $after]));
$del = tagsGroupAction($db, 'grp_delete', ['id' => $gid]);
check('… deleted, its categories stay without a group', !empty($del['ok']) && isset(tagsCatAdmin($db)['groups'])
      && (int)$db->query("SELECT COUNT(*) FROM tag_categories WHERE id = $kid AND group_id IS NULL")->fetchColumn() === 1);
check('a group with a card of its own is a section of "More filters"; the Tags page heads its categories with it',
      str_contains($src('assets/js/filters.js'), "pinned.set('grp-' + c.p") && str_contains($src('templates/pages/tags.php'), 'tags-grp-h'));

$_SESSION['csrf_token'] = 'r191-token';
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '1', 'antispam_enabled' => '0', 'recaptcha_enabled' => '0',
                            'index_enabled' => '1', 'torrent_reports_enabled' => '1', 'tracker_mode' => 'blacklist', 'blacklist_path' => '',
                            'tracker_schedule_enabled' => '0', 'default_language' => 'en']);
$GLOBALS['cfg'] = $cfgOn;
$mkUser = function (string $name) use ($db, $cfgOn): int {
    $r = userCreate($db, $cfgOn, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1, language = 'en' WHERE id = ?")->execute([$id]);
    userPermissionsForget($id);
    return $id;
};
$rep = $mkUser('r191_rep');
$own = $mkUser('r191_own');
$me = fn(int $id): array => userFindById($db, $id) ?? [];
$rq = fn(int $who, string $hash, string $reason = 'fake') => contentReportRequest($db, $cfgOn, $me($who),
    ['csrf_token' => 'r191-token', 'kind' => 'torrent', 'hash' => $hash, 'reason' => $reason], '127.0.0.1');
$db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, meta_status, submitter_id) VALUES (?, 'Release191 Listed', 'admin', NOW(), 'done', ?)")
   ->execute([$h4, $own]);

check('reporting a torrent from Info: only with the switch on and the index on', contentReportKindOn($cfgOn, 'torrent')
      && !contentReportKindOn(['torrent_reports_enabled' => '0'] + $cfgOn, 'torrent') && !contentReportKindOn(['index_enabled' => '0'] + $cfgOn, 'torrent'));
$r1 = $rq($rep, $h1);
$r1b = $rq($rep, $h1);
$r4 = $rq($rep, $h4, 'stolen');
$own4 = $rq($own, $h4);
$none = $rq($rep, $h5);
check('… a member reports one from the index or the whitelist, once; not their own, not one that is nowhere',
      ($r1['body']['success'] ?? false) === true && ($r1['body']['already'] ?? null) === false && ($r1b['body']['already'] ?? false) === true
      && ($r4['body']['success'] ?? false) === true && ($own4['body']['error'] ?? '') === 'own' && ($none['body']['error'] ?? '') === 'not_found',
      json_encode([$r1, $r1b, $r4, $own4, $none]));
check('… with a flag in Info next to the request button, and a Torrents tab on the Reports page',
      str_contains($src('assets/js/app.js'), "window.Reports.button({ kind: 'torrent', hash }")
      && str_contains($src('templates/admin/dashboard.php'), "'torrent' => ['torrents', 'bi-flag', 'a.reports.tab_torrents']")
      && str_contains($src('assets/js/admin-contentreports.js'), 'remove_torrent'));

$_SESSION['loggedin'] = true;
$_SESSION['admin_via_user'] = 0;
$kinds = contentReportPanelKinds($db, $cfgOn);
$list = contentReportList($db, $cfgOn, 'torrent', contentReportFilters([]), 1, 50);
$byHash = array_column($list['groups'] ?? [], null, 'hash');
check('the panel: a card per torrent with its name and the one who registered it', !empty($kinds['torrent']['on']) && !empty($kinds['torrent']['handle'])
      && ($byHash[$h1]['name'] ?? '') === 'Release191 Index Only 1080p' && ($byHash[$h1]['author'] ?? 'x') === ''
      && ($byHash[$h4]['author'] ?? '') === 'r191_own' && ($byHash[$h4]['state'] ?? '') === 'visible'
      && (panelReportsWaiting($db, $cfgOn)['parts']['torrent'] ?? 0) >= 2, json_encode([$kinds['torrent'] ?? null, $byHash[$h1] ?? null, $byHash[$h4] ?? null]));

indexCatalogSync($db, [$h1]);
$inCat = fn(string $h) => (int)$db->query("SELECT COUNT(*) FROM index_catalog WHERE info_hash = " . $db->quote($h))->fetchColumn() === 1;
$isBanned = fn(string $h) => (int)$db->query("SELECT COUNT(*) FROM banned_hashes WHERE info_hash = " . $db->quote($h))->fetchColumn() === 1;
$wasIn = $inCat($h1);
$x1 = contentReportActionRequest($db, $cfgOn, ['kind' => 'torrent', 'hash' => $h1, 'action' => 'remove', 'mode' => 'silent']);
$open1 = (int)$db->query("SELECT COUNT(*) FROM content_reports WHERE kind = 'torrent' AND info_hash = '$h1' AND status = 'open'")->fetchColumn();
check('"Ban the hash" in blacklist mode: on the ban list, out of the search at once, the reports closed, the card says banned',
      ($x1['body']['success'] ?? false) === true && ($x1['body']['banned'] ?? false) === true && $isBanned($h1) && $wasIn && !$inCat($h1) && $open1 === 0
      && contentReportCurrent($db, $cfgOn, 'torrent', 0, $h1)['state'] === 'banned', json_encode([$x1, $wasIn, $open1]));
$x4 = contentReportActionRequest($db, $cfgOn, ['kind' => 'torrent', 'hash' => $h4, 'action' => 'remove', 'mode' => 'silent']);
check('… a whitelisted one is flagged banned as well, and nobody can report it again', ($x4['body']['success'] ?? false) === true
      && (int)$db->query("SELECT banned FROM whitelist WHERE info_hash = '$h4'")->fetchColumn() === 1
      && contentReportTargetFor($db, $cfgOn, $me($rep), 'torrent', 0, $h4) === null
      && str_contains($src('api/index_info.php'), "!\$reportable['banned']"), json_encode($x4));

check('the whitelist request in Info: one line with its buttons, not a big box', str_contains($src('assets/js/app.js'), "note.className = 'profile-search info-wlreq-note';")
      && str_contains($src('assets/css/detail-panel.css'), '.info-wlreq-form .info-wlreq-note { flex: 0 1 22rem;'));
check('"More filters" over a long table fades in instead of growing', str_contains($src('assets/js/filters.js'), "if (document.querySelectorAll('tbody > tr').length > 100) {"));
check('version and changelog', version_compare(TRACKER_VERSION, '1.91.0', '>=') && str_contains($src('CHANGELOG.md'), '## [1.91.0]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
