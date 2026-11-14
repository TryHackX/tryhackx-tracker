<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/sounds.php',
          'includes/shout.php', 'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/icons.php',
          'includes/api_auth.php', 'includes/settings_catalog.php', 'includes/reputation.php', 'includes/lang.php',
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
$GLOBALS['cfg'] = $cfg;
langInit([], 'en');

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
check('schema 114: categories, suggestions, hidden names and whitelist requests', TRACKER_SCHEMA_VERSION >= 114
      && !array_diff(['tag_categories', 'tag_cat_suggestions', 'index_blocked', 'wl_requests', 'wl_request_votes'], $tables)
      && schemaColumnExists($db, 'tags', 'category_id'), implode(',', array_diff(['tag_categories', 'tag_cat_suggestions', 'index_blocked', 'wl_requests', 'wl_request_votes'], $tables)));
check('… with their switches and limits by default', ($cfg['tags_popular_max'] ?? '') === '100' && ($cfg['index_block_on'] ?? '') === '1'
      && ($cfg['wl_req_enabled'] ?? '') === '1' && ($cfg['wl_req_days'] ?? '') === '7' && ($cfg['wl_req_per_day'] ?? '') === '5' && ($cfg['wl_req_note'] ?? '') === '1');
check('… and the two new permissions for members and moderators', isset(userPermissionList()['tags.categorize'], userPermissionList()['whitelist.request'])
      && str_contains($src('includes/schema.php'), "schemaGrantOnce(\$db, 'v114_tag_categorize'") && str_contains($src('includes/schema.php'), "schemaGrantOnce(\$db, 'v114_wl_request'")
      && str_contains($src('tests/groups_matrix_test.php'), "'schema_grant_v114_wl_request'"));

$tr = implode('&', array_map(fn($i) => 'tr=' . rawurlencode('udp://tracker' . $i . '.example.org:6969/announce'), range(1, 120)));
$long = 'magnet:?xt=urn:btih:' . str_repeat('ab', 20) . '&dn=Long&' . $tr;
check('a magnet of ' . strlen($long) . ' characters is a link (the X-Files one was 4 030)', strlen($long) > 4000 && richtextMagnetParse($long) !== null);
check('… one past 12 000 is not', richtextMagnetParse($long . str_repeat('&tr=' . rawurlencode('udp://x.example.org:1/a'), 400)) === null);

$hash = str_repeat('d1', 20);
$hash2 = str_repeat('d2', 20);
$db->prepare("DELETE FROM hash_tags WHERE info_hash IN (?, ?)")->execute([$hash, $hash2]);
$db->prepare("DELETE FROM tags WHERE slug IN ('t190-count', 't190-mine', 't190-forum')")->execute();
$hash3 = str_repeat('d6', 20);
$db->prepare("DELETE FROM index_hashes WHERE info_hash IN (?, ?, ?)")->execute([$hash, $hash2, $hash3]);
$db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, last_seeders, meta_status) VALUES (?, 'Release190 Fixture 1080p WEB-DL', NOW(), NOW(), 3, 'done'),
              (?, 'Release190 Other 720p', NOW(), NOW(), 1, 'done'), (?, 'Release190 Third', NOW(), NOW(), 1, 'done')")->execute([$hash, $hash2, $hash3]);
$db->prepare("INSERT INTO tags (slug, name, status, origin, created_at) VALUES ('t190-count', 'T190 count', 'approved', 'member', UTC_TIMESTAMP())")->execute();
tagsAttach($db, $cfg, $hash, 'x:t190-count', null, TAG_ORIGIN_MEMBER, true);
tagsCount($db, 'x:t190-count');
$uses = fn() => (int)$db->query("SELECT uses FROM tags WHERE slug = 't190-count'")->fetchColumn();
$u1 = $uses();
tagsDetach($db, $hash, 'x:t190-count', null, true);
tagsCount($db, 'x:t190-count');
check('a tag\'s count changes the moment it is added or taken off', $u1 === 1 && $uses() === 0, $u1 . ' / ' . $uses());
check('… from both endpoints', str_contains($src('api/tag_add.php'), 'tagsCount($db, $id);') && str_contains($src('api/tag_remove.php'), 'tagsCount($db, $id);'));

tagsSeedBase($db);
tagsCatSeed($db);
$cats = array_column(tagsCatList($db, true), null, 'slug');
$catOf = fn(string $slug) => $db->query("SELECT c.slug FROM tags t JOIN tag_categories c ON c.id = t.category_id WHERE t.slug = " . $db->quote($slug))->fetchColumn();
check('built-in groups and starter categories, the base tags in them', isset($cats['language'], $cats['resolution'], $cats['release'], $cats['platform'])
      && $cats['language']['grp'] === 'l' && $catOf('lektor-ivo') === 'language' && $catOf('netflix') === 'platform' && $catOf('extended') === 'release',
      json_encode(array_keys($cats)));
check('… a built-in group is named like the group, a starter in the reader\'s language', $cats['language']['l'] === __('js.filters.t_l') && $cats['release']['l'] === 'Release');
$add = tagsCatAction($db, 'cat_add', ['name' => 'Kat 190', 'color' => '#ff0000'], null);
$dup = tagsCatAction($db, 'cat_add', ['name' => 'Kat 190'], null);
$kid = (int)($add['id'] ?? 0);
$db->prepare("INSERT INTO tags (slug, name, status, origin, created_at) VALUES ('t190-mine', 'T190 mine', 'approved', 'member', UTC_TIMESTAMP())")->execute();
$tid = (int)$db->query("SELECT id FROM tags WHERE slug = 't190-mine'")->fetchColumn();
tagsCatAction($db, 'cat_assign', ['ids' => [$tid], 'category' => $kid], null);
$before = array_column(tagsCatList($db, true), 'id');
tagsCatAction($db, 'cat_move', ['id' => $kid, 'dir' => -1], null);
$after = array_column(tagsCatList($db, true), 'id');
check('categories: added once, a tag moved into one, the order changed', !empty($add['ok']) && ($dup['error'] ?? '') === 'exists' && $catOf('t190-mine') === 'kat-190'
      && array_search($kid, $after, true) === array_search($kid, $before, true) - 1);
$grpId = (int)$cats['language']['id'];
check('… a built-in group cannot be deleted, a category can, its tags stay', (tagsCatAction($db, 'cat_delete', ['id' => $grpId], null)['error'] ?? '') === 'forbidden'
      && !empty(tagsCatAction($db, 'cat_delete', ['id' => $kid], null)['ok']) && $catOf('t190-mine') === false
      && (int)$db->query("SELECT COUNT(*) FROM tags WHERE slug = 't190-mine'")->fetchColumn() === 1);
$db->exec("DELETE FROM users WHERE username IN ('r190_a', 'r190_b')");
$db->exec("INSERT INTO users (username, pass_hash) VALUES ('r190_a', 'x'), ('r190_b', 'x')");
$ua = (int)$db->query("SELECT id FROM users WHERE username = 'r190_a'")->fetchColumn();
$ub = (int)$db->query("SELECT id FROM users WHERE username = 'r190_b'")->fetchColumn();
$s1 = tagsCatSuggest($db, 'x:t190-mine', '', 'Nowa 190', $ua, false);
$open = $db->query("SELECT id, new_name FROM tag_cat_suggestions WHERE tag_slug = 't190-mine' AND status = 'open'")->fetch(PDO::FETCH_ASSOC);
$acc = $open ? tagsCatAction($db, 'sugg_accept', ['id' => (int)$open['id']], null) : [];
check('a member\'s suggestion waits; accepting a new name makes the category and moves the tag', !empty($s1['ok']) && empty($s1['direct']) && ($open['new_name'] ?? '') === 'Nowa 190'
      && !empty($acc['ok']) && $catOf('t190-mine') === 'nowa-190');
$s2 = tagsCatSuggest($db, 'x:t190-mine', 'release', '', $ub, true);
check('… a moderator\'s choice applies at once', !empty($s2['direct']) && $catOf('t190-mine') === 'release');
$db->exec("UPDATE tags SET uses = 4 WHERE slug = 't190-mine'");
$x = array_values(array_filter(factsCatalog(['facts_counts' => json_encode(['n' => []])] + $cfg, $db), fn($c) => $c['id'] === 'x:t190-mine'));
check('the filters get people\'s tags with their category', ($x[0]['k'] ?? '') === 'release' && ($x[0]['kl'] ?? '') === 'Release' && ($x[0]['n'] ?? 0) === 4, json_encode($x));
check('… on the search, the Whitelist and the Index', substr_count($src('templates/pages/search.php') . $src('templates/admin/whitelist.php') . $src('templates/admin/index_page.php'), 'factsCatalog($cfg, $db)') === 3);
check('the most popular block: a setting from 0 to 500', tagsPopularMax(['tags_popular_max' => '900']) === 500 && tagsPopularMax(['tags_popular_max' => '0']) === 0 && tagsPopularMax([]) === 100);

$f = fn(string $name) => factsFromName($name);
$kinds = ['MIDA-587' => 't:adult', '【中文字幕】IPX-052 美少女' => 't:adult', 'AC-130 Gunship Simulator' => 't:game', 'Cyberpunk 2077 [FitGirl Repack]' => 't:game',
          'Adobe Photoshop v25.0.1 x64 Multilingual' => 't:software', 'MP3 Converter v1.2.3 Portable' => 't:software',
          'Pink Floyd - The Wall (1979) [FLAC 24-96]' => 't:music', 'Artist - Album (2020) MP3' => 't:music',
          'Wiedźmin (czyta Krzysztof Gosztyła) [M4B]' => 't:audiobook', 'Stephen King - It.epub' => 't:book',
          'The Matrix (1999)' => 't:movie', 'Random XXX Video 1080p' => 't:adult'];
$wrong = [];
foreach ($kinds as $name => $kind) if (!in_array($kind, $f($name)['tags'], true)) $wrong[] = $name . ' => ' . implode(' ', $f($name)['tags']);
check('names: the eight kinds', !$wrong && $f('Gra-2023 Collection')['tags'] === [] && $f('Something Random')['tags'] === [], implode(' | ', $wrong));
$anime = $f('[SubsPlease] Re Zero - 82 (1080p) [8F1D0883].mkv')['tags'];
$langs = $f('Movie.2020.ITA.FRENCH.German.1080p.BluRay.x264 10bit')['tags'];
check('… 10-bit, anime from the release groups, languages in capitals or full words', in_array('g:anime', $anime, true) && in_array('t:series', $anime, true)
      && !array_diff(['l:it', 'l:fr', 'l:de', 'v:10bit'], $langs) && !in_array('l:it', $f('Movie.2020.ita.1080p.x264')['tags'], true), json_encode([$anime, $langs]));
check('… the new bits after the old ones, all of them in 64', FACTS_NAME_VERSION === 3 && array_search('l:sub_en', FACTS_NAME_BITS, true) === 36
      && count(FACTS_NAME_BITS) <= 63 && array_search('v:10bit', FACTS_NAME_BITS, true) === 37);
check('… every kind a code, a filter and a label', factsNameCodes(['t:game'])[0] === 3 && factsNameCodes(['t:adult', 'q:1080p'])[0] === 8
      && (factsNamePredicate(['type' => 'music'])[1] ?? null) === [4] && factsTagLabel('t:audiobook') === 'Audiobook' && factsTagLabel('g:anime') === 'Anime'
      && factsTagLabel('l:ja') === 'Japanese');
check('… and the type buttons only for kinds that exist', factsKinds(['facts_counts' => json_encode(['n' => ['t:game' => 4, 't:music' => 0]])]) === ['movie', 'series', 'game']);

$bc = ['index_block_on' => '1'];
check('hidden names: alone, or a word about minors together with an adult one',
      indexBlockMatch($bc, 'pthc thing') === 'pthc' && indexBlockMatch($bc, 'girl 14yo nude') === '14yo' && indexBlockMatch($bc, 'Loli hentai pack') === 'loli'
      && indexBlockMatch($bc, 'Kids (1995) 1080p') === null && indexBlockMatch($bc, 'Sex Education S04') === null && indexBlockMatch($bc, 'Lolita (1997)') === null
      && indexBlockMatch(['index_block_on' => '0'], 'pthc') === null);
$bh = str_repeat('d3', 20);
$db->prepare("DELETE FROM index_blocked WHERE info_hash = ?")->execute([$bh]);
[$kept, $hid] = indexBlockSplit($db, [['info_hash' => $bh, 'name' => 'pthc sample'], ['info_hash' => $hash, 'name' => 'Fine name']]);
$db->prepare("UPDATE index_blocked SET allowed = 1 WHERE info_hash = ?")->execute([$bh]);
[$kept2, $hid2] = indexBlockSplit($db, [['info_hash' => $bh, 'name' => 'pthc sample']]);
check('… kept out of the catalogue and listed, until allowed back', count($kept) === 1 && $hid === [$bh] && count($kept2) === 1 && $hid2 === []
      && (int)$db->query("SELECT COUNT(*) FROM index_blocked WHERE info_hash = " . $db->quote($bh))->fetchColumn() === 1);
$db->prepare("DELETE FROM index_blocked WHERE info_hash = ?")->execute([$bh]);
check('… on every way into the catalogue', substr_count($src('includes/index.php'), 'indexBlockSplit($db, ') === 3);

$wh = str_repeat('d4', 20);
$db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$wh]);
$db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, meta_status) VALUES (?, 'Release190 Fixture listed', 'admin', NOW(), 'done')")->execute([$wh]);
$sc = ['index_search_include_whitelist' => '1'] + $cfg;
$all = indexSearchCatalogue($db, $sc, ['search' => 'Release190', 'include_whitelist' => true, 'catalog' => false, 'per_page' => 25, 'page' => 1]);
$only = indexSearchCatalogue($db, $sc, ['search' => 'Release190', 'include_whitelist' => true, 'wl_only' => true, 'catalog' => false, 'per_page' => 25, 'page' => 1]);
$names = fn($r) => array_column($r['rows'] ?? [], 'name');
check('search: only whitelisted torrents when asked', in_array('Release190 Fixture 1080p WEB-DL', $names($all), true) && in_array('Release190 Fixture listed', $names($all), true)
      && $names($only) === ['Release190 Fixture listed'] && (int)$only['total'] === 1, json_encode([$names($all), $names($only)]));
check('… a filter in the panel for readers who see the whitelist', str_contains($src('api/index_search.php'), "'wl_only'           => \$canWl && (\$_GET['wl'] ?? '') === '1'")
      && str_contains($src('assets/js/filters.js'), "if (S.wl) p.wl = '1';") && str_contains($src('templates/pages/search.php'), 'data-wl="'));
$db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$wh]);

$db->exec("DELETE v FROM wl_request_votes v JOIN wl_requests r ON r.id = v.request_id WHERE r.info_hash IN ('$hash', '$hash2')");
$db->exec("DELETE FROM wl_requests WHERE info_hash IN ('$hash', '$hash2')");
$db->prepare("DELETE FROM whitelist WHERE info_hash IN (?, ?)")->execute([$hash, $hash2]);
$wc = ['wl_req_enabled' => '1', 'wl_req_days' => '7', 'wl_req_per_day' => '3', 'wl_req_note' => '1'] + $cfg;
$r1 = wlReqSubmit($db, $wc, $hash, $ua, 'please <b>keep</b>');
$r1b = wlReqSubmit($db, $wc, $hash, $ua, '');
$r2 = wlReqSubmit($db, $wc, $hash, $ub, 'me too');
$req = $db->query("SELECT * FROM wl_requests WHERE info_hash = '$hash' AND open_slot = 1")->fetch(PDO::FETCH_ASSOC);
check('a whitelist request: one per torrent, others support it, a note without markup', !empty($r1['ok']) && !empty($r1b['already']) && !empty($r2['ok'])
      && $req && (int)$req['supporters'] === 2 && !str_contains((string)$req['note'], '<') && str_contains((string)$req['note'], 'keep'), json_encode($req));
$r3 = wlReqSubmit($db, $wc, $hash2, $ua, '');
$lim = wlReqSubmit($db, ['wl_req_per_day' => '2'] + $wc, $hash3, $ua, '');
check('… a daily limit per person, and only for torrents in the index', ($lim['error'] ?? '') === 'limit'
      && (wlReqSubmit($db, $wc, str_repeat('d5', 20), $ub, '')['error'] ?? '') === 'not_found'
      && (wlReqSubmit($db, ['wl_req_per_day' => '2'] + $wc, $hash2, $ub, '')['ok'] ?? false) === true
      && (wlReqSubmit($db, ['wl_req_per_day' => '2'] + $wc, $hash2, $ua, '')['already'] ?? false) === true);
$rej = wlReqDecide($db, $wc, (int)$db->query("SELECT id FROM wl_requests WHERE info_hash = '$hash2' AND open_slot = 1")->fetchColumn(), 'reject', 'no seeders', null);
$again = wlReqSubmit($db, $wc, $hash2, $ub, '');
check('… after a rejection the torrent waits the set days', !empty($rej['ok']) && ($again['error'] ?? '') === 'cooldown'
      && (wlReqSubmit($db, ['wl_req_days' => '0'] + $wc, $hash2, $ub, '')['ok'] ?? false) === true);
$db->exec("DELETE FROM user_notifications WHERE type = 'wl_request' AND user_id IN ($ua, $ub)");
$ok = wlReqDecide($db, $wc, (int)$req['id'], 'approve', '', null);
$listed = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE info_hash = '$hash' AND banned = 0")->fetchColumn();
$notes = (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE type = 'wl_request' AND user_id IN ($ua, $ub)")->fetchColumn();
check('… approving puts it on the whitelist and tells everybody who asked', !empty($ok['ok']) && $listed === 1 && $notes === 2
      && (wlReqDecide($db, $wc, (int)$req['id'], 'approve', '', null)['error'] ?? '') === 'not_found', json_encode([$ok, $listed, $notes]));
check('… nothing to ask for once it is listed, and nothing for a guest', (wlReqSubmit($db, $wc, $hash, $ub, '')['error'] ?? '') === 'listed' && wlReqState($db, $wc, $hash2, null) === null);
$db->prepare("DELETE FROM whitelist WHERE info_hash IN (?, ?)")->execute([$hash, $hash2]);
$db->exec("DELETE v FROM wl_request_votes v JOIN wl_requests r ON r.id = v.request_id WHERE r.info_hash IN ('$hash', '$hash2')");
$db->exec("DELETE FROM wl_requests WHERE info_hash IN ('$hash', '$hash2')");
$db->exec("DELETE FROM user_notifications WHERE type = 'wl_request' AND user_id IN ($ua, $ub)");

check('forum tags PL and EN become the language tags', tagsForumAuto($db, 'PL', 'pl') === 'l:audio_pl' && tagsForumAuto($db, 'Angielski', 'angielski') === 'l:audio_en'
      && str_contains($src('includes/schema.php'), "schemaOnce(\$db, 'v114_forum_lang')"));

check('the buttons in Info: one height, the tags\' Done as tall as the field beside it',
      str_contains($src('assets/css/detail-panel.css'), '.tag-edit-bar .tag-edit-toggle { flex: 0 0 auto; }')
      && str_contains($src('assets/css/detail-panel.css'), '.tag-edit-picker + .tag-edit-toggle { padding-block: calc((2.1rem - 1rem - 2px) / 2); }'));
check('no gap between a name and its tags', str_contains($src('assets/css/style.css'), 'align-items: center; gap: 0 0.4rem; }'));
check('"More filters" opens the same way everywhere: a height animation, no CSS transition', str_contains($src('assets/js/filters.js'), "panel.classList.add('sf-moving');")
      && !str_contains($src('assets/css/style.css'), 'transition: grid-template-rows') && !str_contains($src('assets/css/admin.css'), 'transition: grid-template-rows'));
check('the review tabs: a late answer does not paint another tab, Published is left properly', str_contains($src('assets/js/admin-review.js'), 'if (seq !== S.seq) return;')
      && str_contains($src('assets/js/admin-review.js'), "if (pane === 'queue' && (sub === 'published') !== S.pub) {"));
check('icon-only buttons in the panel\'s tables follow their box to the whole pixel', str_contains($src('assets/js/admin-common.js'), 'function snapIcons(root)')
      && str_contains($src('assets/css/admin.css'), ':is(.wl-actions, .th-actions) .btn > .bi:only-child { left: var(--bi-dx, 0px); }'));
check('the index may hold up to 10 million rows', indexMaxRows(['index_max_rows' => '6000000']) === 6000000
      && str_contains($src('api/admin/save_settings.php'), "'index_max_rows' => [1000, 10000000, 200000]") && str_contains($src('templates/admin/settings.php'), 'max="10000000"'));

$db->prepare("DELETE FROM hash_tags WHERE info_hash IN (?, ?)")->execute([$hash, $hash2]);
$db->prepare("DELETE FROM index_hashes WHERE info_hash IN (?, ?, ?)")->execute([$hash, $hash2, $hash3]);
$db->exec("DELETE FROM tag_cat_suggestions WHERE tag_slug = 't190-mine'");
$db->exec("DELETE FROM tags WHERE slug IN ('t190-count', 't190-mine')");
$db->exec("DELETE FROM tag_categories WHERE slug IN ('kat-190', 'nowa-190')");
$db->exec("DELETE FROM users WHERE username IN ('r190_a', 'r190_b')");
$routes190 = ['tag_cat_suggest' => null, 'wl_request' => null, 'admin/tag_cats' => 'panel.tags.view', 'admin/index_blocked' => 'panel.whitelist.view',
              'admin/index_blocked_act' => 'panel.whitelist.ban', 'admin/wl_requests' => 'panel.whitelist.view', 'admin/wl_request_act' => 'panel.whitelist.add'];
$apiSrc = $src('api.php');
$missing = [];
foreach ($routes190 as $route => $perm) {
    if (!preg_match("~'" . preg_quote($route, '~') . "'\\s*=>\\s*'api/~", $apiSrc) || ($perm !== null && !preg_match("~'" . preg_quote($route, '~') . "'\\s*=>\\s*'" . preg_quote($perm, '~') . "'~", $apiSrc))) $missing[] = $route;
}
check('the new routes, each panel one with its permission', $missing === [], implode(', ', $missing));
check('version and changelog', version_compare(TRACKER_VERSION, '1.90.0', '>=') && str_contains($src('CHANGELOG.md'), '## [1.90.0]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
