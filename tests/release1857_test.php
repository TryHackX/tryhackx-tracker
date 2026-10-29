<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/users.php',
          'includes/lang.php', 'includes/settings_catalog.php', 'includes/facts.php', 'includes/tags.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
tagsUsesTick($db);

check('schema 109: a tag on a torrent can wait, and every change is logged', TRACKER_SCHEMA_VERSION >= 109 && schemaColumnExists($db, 'hash_tags', 'pending')
      && schemaTableExists($db, 'hash_tag_log') && tagsPendingCol($db) && tagsLogReady($db) && ($cfg['tags_add_review'] ?? null) === 'open');
$in = implode(',', array_fill(0, count(TAGS_BASE), '?'));
$st = $db->prepare("SELECT slug, name, color, status FROM tags WHERE slug IN ($in)");
$st->execute(array_keys(TAGS_BASE));
$base = $st->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
check('thirty-seven base tags, approved, each in the colour of its kind', count(TAGS_BASE) === 37 && count($base) === 37
      && ($base['napisy-wtopione']['name'] ?? '') === 'Napisy wtopione' && ($base['imax']['color'] ?? '') === '#ffd54f'
      && !array_filter($base, fn($b) => $b['status'] !== 'approved'), json_encode(array_diff(array_keys(TAGS_BASE), array_keys($base))));
check('… and every base slug is what its name makes', !array_filter(array_keys(TAGS_BASE), fn($s) => tagsSlug(TAGS_BASE[$s][0]) !== $s));

$H = fn(int $i): string => str_repeat('fe57', 9) . sprintf('%04x', $i);
$tidy = function () use ($db, $H): void {
    foreach ([1, 2, 3] as $i) {
        foreach (['hash_tags', 'hash_tag_blocks', 'hash_tag_log', 'whitelist'] as $tbl) $db->prepare("DELETE FROM $tbl WHERE info_hash = ?")->execute([$H($i)]);
    }
};
$tidy();
register_shutdown_function($tidy);
foreach ([1, 2] as $i) {
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, probe_status) VALUES (?, ?, 'forum', NOW(), 0, 'passed')")
       ->execute([$H($i), 'Zyx Tag Test ' . $i]);
}

$sug = tagsSuggest($db, $H(1), 'Zyx.Movie.2020.EXTENDED.IMAX.1080p.HardSub', 'Lektor: IVO. Produkcja: Polska. To nie jest live.', ['x:imax']);
$ids = array_column($sug, 'id');
check('suggestions: the base tags whose words are in the name first, then in the description; what the torrent has is left out',
      array_slice($ids, 0, 2) === ['x:extended', 'x:napisy-wtopione'] && in_array('x:lektor-ivo', $ids, true) && in_array('x:polska-produkcja', $ids, true)
      && !in_array('x:imax', $ids, true) && !in_array('x:koncert', $ids, true) && ($sug[0]['w'] ?? '') === 'extended', json_encode($sug));
$db->prepare("INSERT IGNORE INTO hash_tag_blocks (info_hash, tag, blocked_by, created_at) VALUES (?, 'x:extended', NULL, UTC_TIMESTAMP())")->execute([$H(1)]);
check('… a tag a moderator took off is not suggested again', !in_array('x:extended', array_column(tagsSuggest($db, $H(1), 'Zyx.EXTENDED', '', []), 'id'), true));
check('the tag search knows the base words', in_array('x:napisy-wtopione', array_column(tagsSearch($db, $cfg, 'hardsub'), 'id'), true)
      && in_array('x:3d', array_column(tagsSearch($db, $cfg, 'sbs'), 'id'), true));

$cfgA = ['tags_add_review' => 'approve', 'tags_max_per_torrent' => '40'] + $cfg;
$a1 = tagsAttach($db, $cfgA, $H(1), 'x:imax', 77101, TAG_ORIGIN_MEMBER, false, true);
$a2 = tagsAttach($db, $cfgA, $H(2), 'x:3d', 77102, TAG_ORIGIN_MEMBER, false, true);
tagsLog($db, $H(1), 'x:imax', 'add', 77101);
tagsLog($db, $H(2), 'x:3d', 'add', 77102);
$seen = fn(?int $viewer, bool $mod, int $i): array => array_combine(array_column($rows = tagsForHashes($db, [$H($i)], $viewer, $mod)[$H($i)]['add'] ?? [], 'id'),
                                                                   array_map(fn($c) => $c['p'] ?? 0, $rows));
check('a member\'s tag that waits: its author and the moderators see it dashed, nobody else, and no filter finds it',
      $a1['ok'] && $a1['pending'] === true && $seen(77101, false, 1) === ['x:imax' => 1] && $seen(77102, false, 1) === [] && $seen(null, false, 1) === []
      && $seen(5, true, 1) === ['x:imax' => 1] && !in_array($H(1), tagsFilterExplicit($db, ['x:imax'])['hashes'] ?? [], true), json_encode([$a1, $seen(77101, false, 1)]));
$adm = tagsChangesAdmin($db, $cfgA);
$mine = array_values(array_filter($adm['pending'], fn($r) => in_array($r['hash'], [$H(1), $H(2)], true)));
check('the panel lists what waits, with the torrent and the tag as a chip', count($mine) === 2 && $adm['review'] === 'approve' && $adm['n_pending'] >= 2
      && ($mine[0]['torrent'] ?? '') === 'Zyx Tag Test 1' && ($mine[0]['chip']['l'] ?? '') === 'IMAX', json_encode($mine));
$ok = tagsChangesAction($db, 'change_approve', [['hash' => $H(1), 'tag' => 'x:imax']], null);
$no = tagsChangesAction($db, 'change_reject', [['hash' => $H(2), 'tag' => 'x:3d']], null);
check('approving shows it to everyone and filters find it; rejecting takes it off',
      $ok === ['ok' => true, 'n' => 1] && $no === ['ok' => true, 'n' => 1] && $seen(null, false, 1) === ['x:imax' => 0]
      && in_array($H(1), tagsFilterExplicit($db, ['x:imax'])['hashes'] ?? [], true) && $seen(5, true, 2) === [], json_encode([$ok, $no, $seen(null, false, 1)]));
$again = tagsAttach($db, $cfgA, $H(1), 'x:imax', 77102, TAG_ORIGIN_MEMBER, false, true);
$mod = tagsAttach($db, $cfgA, $H(2), 'x:kolekcja', 77102, TAG_ORIGIN_MEMBER, false, true);
$modOn = tagsAttach($db, $cfgA, $H(2), 'x:kolekcja', 5, TAG_ORIGIN_MODERATOR, true, false);
check('a tag already on the torrent does not wait again, and a moderator adding a waiting one makes it visible',
      $again['pending'] === false && $mod['pending'] === true && $modOn['pending'] === false && $seen(null, false, 2) === ['x:kolekcja' => 0]);
$rm = tagsChangesAction($db, 'change_remove', [['hash' => $H(2), 'tag' => 'x:kolekcja']], null);
$recent = array_values(array_filter(tagsChangesAdmin($db, $cfgA)['recent'], fn($r) => in_array($r['hash'], [$H(1), $H(2)], true)));
check('a moderator can take a tag off from the list; the log says who did what, newest first',
      $rm === ['ok' => true, 'n' => 1] && $seen(null, false, 2) === [] && array_column($recent, 'action') === ['remove', 'reject', 'approve', 'add', 'add']
      && $recent[2]['still'] === 0 && $recent[0]['still'] === null && ($recent[3]['chip']['l'] ?? '') === '3D', json_encode($recent));
check('bad items are skipped', tagsChangesAction($db, 'change_approve', [['hash' => 'nope', 'tag' => 'x:imax'], 'x', ['hash' => $H(1), 'tag' => 'bad tag']], null) === ['ok' => true, 'n' => 0]);

$api = $src('api.php');
check('the route admin/tag_changes and who may use it; the actions are in tag_action', str_contains($api, "'admin/tag_changes'           => 'api/admin/tag_changes.php'")
      && str_contains($api, "'admin/tag_changes'           => 'panel.tags.view'") && str_contains($src('api/admin/tag_changes.php'), 'tagsChangesAdmin($db, $cfg)')
      && str_contains($src('api/admin/tag_action.php'), "\$changeOps = ['change_approve', 'change_reject', 'change_remove'];"));
check('adding waits by the setting for a member only, and both endpoints log', str_contains($src('api/tag_add.php'), "!\$moderate && (\$cfg['tags_add_review'] ?? 'open') === 'approve'")
      && str_contains($src('api/tag_add.php'), "tagsLog(\$db, \$hash, \$id, 'add', (int)\$me['id']);")
      && str_contains($src('api/tag_remove.php'), "tagsLog(\$db, \$hash, \$id, \$r['removed'] === 'blocked' ? 'block' : 'remove', (int)\$me['id']);")
      && str_contains($src('api/tag_add.php'), "'api.tags.add_pending'"));
check('Info sends the suggestions with the editor, and the editor shows them under the field',
      str_contains($src('api/index_info.php'), "\$tagEdit['suggest'] = tagsSuggest(\$db, \$hash, \$infoName,")
      && str_contains($src('assets/js/filters.js'), "const hints = (edit.suggest || []).filter(s => !have.has(s.id));"));
check('the forum and the log keep to the rules: a forum tag never waits, the log keeps 90 days',
      str_contains($src('includes/tags.php'), "\$clause = tagsPendingCol(\$db) ? ', pending = 0' : '';")
      && str_contains($src('includes/tags.php'), 'DELETE FROM hash_tag_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY'));
check('the Tags page has the changes section and its script', str_contains($src('templates/admin/tags.php'), 'id="tags-changes-body"')
      && str_contains($src('assets/js/admin-tags.js'), "apiCall('admin/tag_changes')") && str_contains($src('assets/js/admin-tags.js'), "changeAct('change_approve', [], true)"));
check('the setting: on the page, saved and checked, with search words', str_contains($src('templates/admin/settings.php'), 'data-setting="tags_add_review"')
      && str_contains($src('api/admin/save_settings.php'), "\$data['tags_add_review'] === 'approve' ? 'approve' : 'open'")
      && isset(settingsCatalogKeywords()['tags_add_review']));
$plL = include $root . '/lang/pl.php';
$enL = include $root . '/lang/en.php';
$words = ['settings.tags_add_review', 'settings.tags_add_review_approve', 'a.tags.changes_title', 'js.tags.c_waiting', 'js.tags.c_take_off', 'js.tags.suggested',
          'js.tags.suggested_why', 'api.tags.add_pending'];
check('the words in both languages', !array_diff($words, array_keys($plL)) && !array_diff($words, array_keys($enL)) && $plL['js.tags.suggested'] === 'Podpowiedzi:');
check('a deleted account is no longer named in the tag log or on the tags it added', str_contains($src('includes/users.php'), "\$del(\"UPDATE hash_tag_log SET user_id = NULL WHERE user_id = ?\", [\$userId], 'tag log');")
      && str_contains($src('includes/users.php'), "\$del(\"UPDATE hash_tags SET added_by = NULL WHERE added_by = ?\", [\$userId], 'tags added');"));
check('version and changelog', version_compare(TRACKER_VERSION, '1.85.7', '>=') && str_contains($src('CHANGELOG.md'), '## [1.85.7]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
