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
          'includes/facts.php', 'includes/tags.php', 'includes/thumbs.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');

check('schema 113: the three new switches, on by default', TRACKER_SCHEMA_VERSION >= 113
      && ($cfg['rows_open_info'] ?? '') === '1' && ($cfg['rep_chip'] ?? '') === '1' && ($cfg['thumbs_panel'] ?? '') === '1');
check('… saved as switches, with search words and a row in Settings',
      str_contains($src('api/admin/save_settings.php'), "'info_resize', 'images_gallery_enabled', 'rows_open_info', 'rep_chip',")
      && str_contains($src('api/admin/save_settings.php'), "foreach (['thumbs_search', 'thumbs_lists', 'thumbs_info', 'thumbs_panel'] as \$tk)")
      && isset(settingsCatalogKeywords()['rows_open_info'], settingsCatalogKeywords()['rep_chip'], settingsCatalogKeywords()['thumbs_panel'])
      && str_contains($src('templates/admin/settings.php'), 'data-setting="rows_open_info"') && str_contains($src('templates/admin/settings.php'), 'data-setting="rep_chip"')
      && str_contains($src('templates/admin/settings.php'), 'data-setting="thumbs_panel"'));

$md = richtextRender("# One\n## Two\n### Three\n#### Four\n##### Five\n###### Six", 'markdown', $cfg);
check('markdown headings carry their level', str_contains($md, 'class="rt-h rt-h1">One') && str_contains($md, 'class="rt-h rt-h2">Two')
      && str_contains($md, 'class="rt-h rt-h3">Three') && str_contains($md, 'class="rt-h rt-h4">Four') && str_contains($md, 'class="rt-h rt-h5">Five')
      && str_contains($md, 'class="rt-h rt-h6">Six'), $md);
$fl = richtextRender("# One\n\n## Two\n\n#### Four\n\nSetext\n===\n\nLower\n---", 'flarum', $cfg);
check('… and so do the forum format\'s, setext ones too', str_contains($fl, 'class="rt-h rt-h1">One') && str_contains($fl, 'class="rt-h rt-h2">Two')
      && str_contains($fl, 'class="rt-h rt-h4">Four') && str_contains($fl, 'class="rt-h rt-h1">Setext') && str_contains($fl, 'class="rt-h rt-h2">Lower'), $fl);
check('… each level its own size, on the site, in the panel and in a mail',
      str_contains($src('assets/css/style.css'), '.rt-body .rt-h1 { font-size: 1.7em; }') && str_contains($src('assets/css/style.css'), '.rt-body .rt-h6 {')
      && str_contains($src('assets/css/admin.css'), '.rt-body .rt-h1 { font-size: 1.6em; }')
      && str_contains(richtextMailStyles('<h4 class="rt-h rt-h1">A</h4>'), 'font-size:24px'));

$cfgT = ['rep_enabled' => '1', 'rep_mode' => 'thumbs', 'rep_min_votes' => '3', 'rep_chip' => '1'];
$cfgS = ['rep_mode' => 'stars'] + $cfgT;
check('the rating chip: thumbs as a share, stars as a number, nothing under the threshold or with the switch off',
      repChipFrom($cfgT, 5, 8000) === ['mode' => 'thumbs', 'pct' => 80, 'total' => 5]
      && repChipFrom($cfgS, 4, 420) === ['mode' => 'stars', 'stars' => 4.2, 'total' => 4]
      && repChipFrom($cfgT, 2, 9000) === null && !repChipOn(['rep_chip' => '0'] + $cfgT) && !repChipOn(['rep_enabled' => '0'] + $cfgT));
$hash = str_repeat('c9', 20);
$db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$hash]);
$db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, votes_count, score_x100) VALUES (?, 'Rated', 'admin', NOW(), 6, 7500)")->execute([$hash]);
$chips = repChipsFor($db, $cfgT, [$hash, str_repeat('0', 40)]);
check('… read for a page of hashes at once', ($chips[$hash] ?? null) === ['mode' => 'thumbs', 'pct' => 75, 'total' => 6] && count($chips) === 1, json_encode($chips));
$rows = factsListAttach($db, $cfgT + $cfg, [['info_hash' => $hash, 'name' => 'Rated']]);
check('… and put on list rows (favourites, lists, uploads)', ($rows[0]['rep']['pct'] ?? null) === 75
      && str_contains($src('api/user_uploads.php'), "foreach (['facts', 'thumb', 'rep'] as \$chipKey)"), json_encode($rows));
$db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$hash]);
check('the search sends the chip only while the rating has no column of its own',
      str_contains($src('api/index_search.php'), '$repChip = !$repInResults && repChipOn($cfg);') && str_contains($src('api/index_search.php'), "'rep_chip' => \$repChip,")
      && str_contains($src('assets/js/app.js'), 'if (json.rep_chip && r.rep && window.RepChip) {') && str_contains($src('assets/js/favourites.js'), 'window.RepChip(r.rep)'));

$name = fn(string $n): array => factsFromName($n)['tags'];
check('names: ENG SUB is English subtitles, not English audio', in_array('l:sub_en', $name('Show.1x05.720p.ENG.SUB'), true) && !in_array('l:audio_en', $name('Show.1x05.720p.ENG.SUB'), true)
      && in_array('l:audio_en', $name('Movie.2020.1080p.ENGLISH.x264'), true));
check('names: 1x05, "odcinek" and "serial" are a series', in_array('t:series', $name('Show.1x05.720p.WEB'), true)
      && in_array('t:series', $name('Serial XYZ odcinek 12 720p'), true) && in_array('t:movie', $name('Movie 2020 1080p WEB-DL'), true));
check('names: FHD / Full HD / QHD, a streaming service as WEB-DL, HDTC as CAM, dual audio as MULTi, PLSUBS',
      in_array('q:1080p', $name('Film 2023 Full HD Lektor PL'), true) && in_array('q:1440p', $name('Film 2023 QHD x265'), true)
      && in_array('s:webdl', $name('Show.S01E01.1080p.AMZN.DDP5.1.H.264'), true) && in_array('s:cam', $name('Movie 2021 HDTC x264'), true)
      && in_array('l:multi', $name('Movie 2019 1080p Dual Audio'), true) && in_array('l:sub_pl', $name('Film.2020.PLSUBS.1080p.WEB'), true)
      && !in_array('s:webdl', $name('NF - The Search (2019) [MP3]'), true));
$db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('name_facts_ver', '1'), ('name_facts_cursor', 'done')")->execute();
factsNameTick($db, ['facts_source' => 'desc_name', 'name_facts_ver' => '1', 'name_facts_cursor' => 'done'] + $cfg, 0.5);
$ver = (string)$db->query("SELECT `value` FROM settings WHERE `key` = 'name_facts_ver'")->fetchColumn();
check('new name rules make the janitor read every name again, once', FACTS_NAME_VERSION >= 2 && $ver === (string)FACTS_NAME_VERSION
      && str_contains($src('tools/janitor.php'), "|| (\$cfg['name_facts_ver'] ?? '1') !== (string)FACTS_NAME_VERSION) {"), $ver);

$bad = array_filter(array_keys(TAGS_BASE), fn($s) => tagsSlug(TAGS_BASE[$s][0]) !== $s);
check('base tags: 37, each slug what its name makes, the streaming services among them, seeded again once',
      count(TAGS_BASE) === 37 && !$bad && isset(TAGS_BASE['netflix'], TAGS_BASE['disney'], TAGS_BASE['hbo-max'], TAGS_BASE['canal'])
      && str_contains($src('includes/tags.php'), "schemaOnce(\$db, 'v113_base_tags')"), json_encode($bad));
check('a base tag is suggested by its new words', (function () use ($db) {
    tagsSeedBase($db);
    $s = tagsSuggest($db, str_repeat('d1', 20), 'Show.S01.1080p.AMZN.WEB-DL', '', []);
    return in_array('x:amazon-prime', array_column($s, 'id'), true);
})());
check('the forum map can be refreshed: proposals matched again, the approved put back', (function () use ($db, $cfg) {
    if (!tagsForumReady($db)) return true;
    $r = tagsForumDecide($db, $cfg, 'forum_refresh', []);
    return !empty($r['ok']) && isset($r['n'], $r['added'], $r['removed']);
})() && str_contains($src('api/admin/tag_action.php'), "'forum_all', 'forum_refresh'"));

check('the tag editor gets the reader\'s own tags to pick from, never a genre and never one the torrent has',
      str_contains($src('api/index_info.php'), "\$tagEdit['mine'] = array_values(array_filter(tagsFavChips(\$db, tagsFavList(\$db, (int)\$me['id'])),")
      && str_contains($src('api/index_info.php'), "fn(\$c) => \$c['g'] !== 'g' && !isset(\$tagHave[\$c['id']])")
      && str_contains($src('assets/js/filters.js'), "const mineTags = (edit.mine || []).filter(s => !have.has(s.id));"));
check('the Tags page keeps each star with its tag and moves a starred tag to My tags at once',
      str_contains($src('templates/pages/tags.php'), '<span class="tag-pair">') && str_contains($src('assets/css/detail-panel.css'), '.tag-pair { display: inline-flex;')
      && str_contains($src('assets/js/filters.js'), "c.dataset.id = b.dataset.id;") && str_contains($src('assets/js/filters.js'), "const PAGE = 60;"));
check('filters as blocks: spans by how many tags, packed, "More" past 18, the dates stack when narrow',
      str_contains($src('assets/js/filters.js'), "const want = n > 40 ? 3 : (n > 16 ? 2 : 1);") && str_contains($src('assets/js/filters.js'), 'const TAG_PAGE = 18;')
      && str_contains($src('assets/css/style.css'), 'grid-auto-rows: 4px; grid-auto-flow: row dense;') && str_contains($src('assets/css/admin.css'), 'grid-auto-rows: 4px; grid-auto-flow: row dense;')
      && str_contains($src('assets/css/detail-panel.css'), '@container (max-width: 250px) {'));
check('a click on an empty part of a row opens Info, behind a setting',
      str_contains($src('templates/partials/info_overlay.php'), "data-rows=\"<?= (\$cfg['rows_open_info'] ?? '1') === '1' ? '1' : '0' ?>\"")
      && str_contains($src('assets/js/app.js'), "if (d.rows === '1') initRowOpen();") && str_contains($src('assets/js/app.js'), 'function initRowOpen() {'));
check('the list window resizes like Info and keeps its own size', str_contains($src('assets/js/app.js'), "initInfoResize(listBox, 'thx-list-size');")
      && str_contains($src('assets/css/style.css'), ':is(.info-box, .lo-box).info-sized { max-width: none; width: var(--info-w); }'));
check('the panel: covers, the pictures, the tags and the folded description in the details',
      str_contains($src('api/admin/fetch_whitelist.php'), "thumbsOn(\$cfg, 'panel')") && str_contains($src('api/admin/fetch_index.php'), "thumbsOn(\$cfg, 'panel')")
      && str_contains($src('api/admin/whitelist_item.php'), "'facts' => function_exists('tagsHashChips')") && str_contains($src('api/admin/index_item.php'), "'gallery' => function_exists('thumbsGallery') && thumbsOn(\$cfg, 'panel')")
      && str_contains($src('assets/js/admin-whitelist.js'), 'window.DescFold(body, 420,') && str_contains($src('assets/js/admin-index.js'), 'window.InfoGallery(d.gallery)')
      && str_contains($src('templates/admin/whitelist.php'), 'assets/js/lightbox.js') && str_contains($src('assets/js/filters.js'), 'window.InfoGallery = function (list) {'));
check('the panel\'s windows: the member and group editors wider, the permissions grouped and searchable, a tag\'s colour from swatches',
      str_contains($src('templates/admin/users.php'), '<div class="modal-dialog modal-xl modal-dialog-scrollable">') && str_contains($src('templates/admin/users.php'), 'id="ge-filter"')
      && str_contains($src('assets/js/admin-users.js'), 'function geCount() {') && str_contains($src('assets/js/admin-tags.js'), 'const PALETTE = [')
      && str_contains($src('assets/js/admin-tags.js'), "renderPagination(pager, { total: rows.length, page, pages,"));
check('the emoji button keeps the toolbar\'s top right corner', str_contains($src('assets/css/style.css'), '.rt-tools > .rt-tool-emoji { position: absolute; top: 0.3rem; right: 0.4rem; margin: 0; }'));

$plL = include $root . '/lang/pl.php';
$enL = include $root . '/lang/en.php';
$words = ['settings.rows_open_info', 'settings.rows_open_info_hint', 'settings.rep_chip', 'settings.rep_chip_hint', 'settings.thumbs_panel', 'settings.thumbs_panel_hint',
          'js.app.rep_thumbs_title_one', 'js.app.rep_thumbs_title_many', 'js.filters.more_tags', 'js.filters.less_tags', 'js.tags.page_more', 'js.tags.page_less',
          'js.tags.mine_quick', 'js.tags.mine_quick_title', 'a.users.perm_filter_ph', 'a.tags.f_search_ph', 'a.tags.f_refresh', 'a.tags.f_refresh_hint',
          'js.tags.color_custom', 'js.tags.preview', 'js.tags.f_none_match', 'js.tags.f_refreshed'];
check('the words in both languages', !array_diff($words, array_keys($plL)) && !array_diff($words, array_keys($enL)) && $plL['js.tags.mine_quick'] === 'Moje tagi:',
      json_encode(array_values(array_diff($words, array_keys($plL)))));
check('version and changelog', version_compare(TRACKER_VERSION, '1.89.0', '>=') && str_contains($src('CHANGELOG.md'), '## [1.89.0]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
