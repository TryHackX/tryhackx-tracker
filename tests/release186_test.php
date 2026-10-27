<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/users.php',
          'includes/reputation.php', 'includes/lang.php', 'includes/settings_catalog.php', 'includes/facts.php', 'includes/tags.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');

$st = $db->query("SELECT permissions FROM user_groups WHERE slug = 'member'");
$memberPerms = json_decode((string)$st->fetchColumn(), true) ?: [];
check('schema 110: the year key on the names, favourite tags, the permission for members, the lists setting', TRACKER_SCHEMA_VERSION >= 110
      && schemaIndexExists($db, 'name_facts', 'idx_nf_year') && schemaTableExists($db, 'tag_favourites') && !empty($memberPerms['tags.favourite'])
      && ($cfg['facts_chips_lists'] ?? null) === '1', json_encode(['perm' => $memberPerms['tags.favourite'] ?? null]));

$req = factsRequest(['duration' => '90..150', 'seasons' => '2..', 'episodes' => '..10', 'rating' => '70..', 'votes' => '5..', 'steam' => '150', 'seen' => '2026-01-31..2026-01-01',
                     'notags' => 'q:sd,x:zyx186-bad|v:h265', 'tags' => 'q:1080p']);
check('the new parameters: ranges, a percent kept to 100, dates in order, tags to leave out',
      $req['facts']['duration'] === [90, 150] && $req['facts']['seasons'] === [2, null] && $req['facts']['episodes'] === [null, 10]
      && $req['facts']['rating'] === [70, null] && $req['facts']['votes'] === [5, null] && $req['facts']['steam'] === [100, 100]
      && $req['seen'] === ['2026-01-01', '2026-01-31'] && $req['facts']['notags'] === [['q:sd'], ['x:zyx186-bad', 'v:h265']]
      && factsRange('2026-13-45', 'date') === null && factsRange('x..', 'pct') === null, json_encode($req));
$ef = factsEngineFilters($db, $cfg, factsRequest(['seen' => '2026-01-31..2026-01-01']), true, true);
check('… and "seen" reaches the search as two moments of the day', ($ef['seen_min'] ?? '') === '2026-01-01 00:00:00' && ($ef['seen_max'] ?? '') === '2026-01-31 23:59:59');

$H = fn(int $i): string => str_repeat('e186', 9) . sprintf('%04x', $i);
$names = [1 => 'Zyxsort.Alpha.2001.1080p.WEB-DL.x264', 2 => 'Zyxsort.Beta.2020.1080p.WEB-DL.x265', 3 => 'Zyxsort.Gamma.2010.720p.HDTV.x264', 4 => 'Zyxsort Delta no year 1080p'];
$tidy = function () use ($db, $H): void {
    foreach ([1, 2, 3, 4, 5] as $i) {
        foreach (['index_catalog', 'index_hashes', 'name_facts', 'hash_tags', 'hash_votes', 'whitelist', 'desc_facts'] as $tbl) {
            $db->prepare("DELETE FROM $tbl WHERE info_hash = ?")->execute([$H($i)]);
        }
    }
    $db->exec("DELETE FROM tags WHERE slug = 'zyx186'");
    $db->exec("DELETE FROM tag_favourites WHERE user_id IN (77186, 77187)");
};
$tidy();
register_shutdown_function($tidy);
foreach ($names as $i => $name) {
    $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seen, meta_status, total_size, files_count, votes_count, score_x100) VALUES (?, ?, ?, 'done', 1, 1, ?, ?)")
       ->execute([$H($i), $name, '2026-02-0' . $i . ' 12:00:00', $i === 2 ? 4 : 0, $i === 2 ? 7500 : 0]);
    $db->prepare("INSERT INTO index_catalog (info_hash, name, eff_seeders, eff_leechers, total_size, files_count, last_seen) VALUES (?, ?, 1, 0, 1, 1, ?)")
       ->execute([$H($i), $name, '2026-02-0' . $i . ' 12:00:00']);
}
factsNameStore($db, array_map(fn($i) => ['info_hash' => $H($i), 'name' => $names[$i]], array_keys($names)));
$db->prepare("INSERT INTO tags (slug, name, status, origin, created_at) VALUES ('zyx186', 'Zyx 186', 'approved', 'moderator', UTC_TIMESTAMP())")->execute();
foreach ([1, 2, 3, 4] as $i) tagsAttach($db, $cfg, $H($i), 'x:zyx186', 9186, TAG_ORIGIN_MODERATOR, true);
foreach ([1, 2, 3, 4] as $k => $v) $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, scale) VALUES (?, 'ip', ?, 1, 'thumbs')")->execute([$H(2), 'zyx' . $k]);

$cfgN = ['facts_source' => 'desc_name', 'rep_enabled' => '1', 'rep_mode' => 'thumbs'] + $cfg;
$GLOBALS['cfg'] = $cfgN;
$base = ['search' => '', 'include_whitelist' => true, 'per_page' => 10, 'page' => 1, 'sort' => 'last:desc', 'catalog' => true];
$find = function (array $get, ?array $sort = null, array $over = []) use ($db, $cfgN, $base): array {
    $q = $over + $base + factsEngineFilters($db, $cfgN, factsRequest($get + ['tags' => 'x:zyx186']), true, true);
    $r = $sort ? factsSortedSearch($db, $cfgN, $q, $sort[0], $sort[1], true, true) : indexSearchCatalogue($db, $cfgN, $q);
    return array_map(fn($x) => (int)hexdec(substr((string)$x['info_hash'], -4)), $r['rows']);
};
$all = $find([]);
check('the four test torrents are found by their own tag', count($all) === 4, json_encode($all));
check('leaving a tag out: without 1080p, without HEVC, without both', $find(['notags' => 'q:1080p']) === [3]
      && !in_array(2, $find(['notags' => 'v:h265']), true) && $find(['notags' => 'q:1080p,q:720p']) === [], json_encode([$find(['notags' => 'q:1080p']), $find(['notags' => 'v:h265'])]));
check('a tag both wanted and left out leaves nothing', $find(['tags' => 'x:zyx186', 'notags' => 'x:zyx186']) === []);
check('seen between two dates', $find(['seen' => '2026-02-02..2026-02-03']) === [3, 2], json_encode($find(['seen' => '2026-02-02..2026-02-03'])));
$v = factsRepValues(['rep_mode' => 'thumbs'], 4, 7500);
check('the site\'s rating: 75 % of four votes is a SteamDB score pulled toward the middle', $v['rating'] === 75.0 && $v['votes'] === 4 && $v['steam'] > 50 && $v['steam'] < 75
      && factsRepValues(['rep_mode' => 'thumbs'], 0, 9000) === null && factsRepValues(['rep_mode' => 'stars'], 10, 500)['rating'] === 100.0, json_encode($v));
check('… and its filters: votes, rating, SteamDB', $find(['votes' => '3..']) === [2] && $find(['rating' => '80..']) === [] && $find(['steam' => '50..']) === [2]);

$byYear = $find([], ['year', false]);
$byYearAsc = $find([], ['year', true]);
$stream = [];
foreach ([1, 2] as $p) array_push($stream, ...$find([], ['year', false], ['page' => $p, 'per_page' => 2, 'sort_all' => 0]));
check('sort by year: newest first, oldest first, unknown last — and the long way gives the same order page by page',
      $byYear === [2, 3, 1, 4] && $byYearAsc === [1, 3, 2, 4] && $stream === [2, 3, 1, 4], json_encode([$byYear, $byYearAsc, $stream]));
check('sort by votes: the rated one first', $find([], ['votes', false])[0] === 2 && $find([], ['steam', false], ['sort_all' => 0])[0] === 2);
$api = $src('api/index_search.php');
check('the search takes the new sorts (ratings only with ratings on, descriptions only for who may read them) and says when it stops',
      str_contains($api, "preg_match('/^(year|imdb|filmweb|rating|votes|steam|desc):(asc|desc)$/', \$sort, \$sm)")
      && str_contains($api, 'factsSortedSearch($db, $cfg, $searchArgs, $factSort[0], $factSort[1], $canContent, $signedIn)')
      && str_contains($api, "'sort_limited' => !empty(\$res['sort_limited'])"));

check('my tags: on, listed with names, off, and nothing that is not a tag', tagsFavSet($db, 77186, 'q:1080p', true) === ['ok' => true, 'on' => true]
      && tagsFavSet($db, 77186, 'x:zyx186', true)['ok'] && tagsFavList($db, 77186) === ['q:1080p', 'x:zyx186']
      && array_column(tagsFavChips($db, tagsFavList($db, 77186)), 'l') === ['1080p', 'Zyx 186']
      && tagsFavSet($db, 77186, 'q:1080p', false) === ['ok' => true, 'on' => false] && tagsFavList($db, 77186) === ['x:zyx186']
      && tagsFavSet($db, 77186, 'x:nope-nothing', true) === ['ok' => false, 'error' => 'unknown']);
check('the route tag_fav: GET lists, POST changes with a token; a deleted account takes its favourites with it',
      str_contains($src('api.php'), "'tag_fav'                     => 'api/tag_fav.php'") && str_contains($src('api/tag_fav.php'), "userCan(\$db, \$cfg, 'tags.favourite')")
      && str_contains($src('api/tag_fav.php'), 'verifyCsrfToken') && str_contains($src('includes/users.php'), "\$del(\"DELETE FROM tag_favourites WHERE user_id = ?\", [\$userId], 'favourite tags');"));
$withChips = factsListAttach($db, $cfgN, [['info_hash' => $H(1), 'name' => $names[1]], ['info_hash' => null, 'name' => 'x']]);
check('profile lists get the chips (and a row without its hash none)', !empty($withChips[0]['facts']['tags']) && !isset($withChips[1]['facts'])
      && !isset(factsListAttach($db, ['facts_chips_lists' => '0'] + $cfgN, [['info_hash' => $H(1), 'name' => $names[1]]])[0]['facts']));
check('… in the favourites, the uploads and the lists, drawn by the shared chips', str_contains($src('api/user_favourites.php'), 'factsListAttach($db, $cfg,')
      && str_contains($src('api/user_uploads.php'), 'factsListAttach($db, $cfg,') && str_contains($src('api/user_list_items.php'), 'factsListAttach($db, $cfg,')
      && str_contains($src('assets/js/favourites.js'), 'window.FactChips.render(r.facts, null, null)'));

$js = $src('assets/js/filters.js');
check('the filter panel: a third state for a tag, the new fields, My tags first', str_contains($js, "if (S.sel.has(id)) { S.sel.delete(id); S.not.add(id); }")
      && str_contains($js, "if (S.not.size) p.notags = [...S.not].join(',');") && str_contains($js, "{ key: 'seen', label: 'seen', group: 'dates', date: true }")
      && str_contains($js, "{ key: 'steam', label: 'steam', group: 'ratings', rep: true, minOnly: true, pct: true }") && str_contains($js, "wrap.appendChild(el('div', 'sf-label', t.key('js.filters.t_mine')));"));
check('the search page: a Sort by list, the address keeps it, the panel gets My tags and the rating switch',
      str_contains($src('templates/pages/search.php'), 'id="search-sortby"') && str_contains($src('templates/pages/search.php'), 'data-favs="')
      && str_contains($src('assets/js/app.js'), "const FACT_SORT = /^(year|imdb|filmweb|rating|votes|steam|desc):(asc|desc)$/;")
      && str_contains($src('assets/js/app.js'), "'duration', 'seasons', 'episodes', 'rating', 'votes', 'steam', 'seen'];"));
check('the Tags page: a star by each tag and My tags at the top', str_contains($src('templates/pages/tags.php'), 'class="tag-fav"')
      && str_contains($src('templates/pages/tags.php'), "_h('tags.mine_h2')") && str_contains($js, "postJ('tag_fav', { tag: b.dataset.id, on: on })"));
check('the lists setting: on the page, saved, with search words', str_contains($src('templates/admin/settings.php'), 'data-setting="facts_chips_lists"')
      && str_contains($src('api/admin/save_settings.php'), "'facts_chips_lists', 'facts_chips_max',") && isset(settingsCatalogKeywords()['facts_chips_lists']));
$plL = include $root . '/lang/pl.php';
$enL = include $root . '/lang/en.php';
$words = ['js.filters.duration', 'js.filters.c_seen', 'js.filters.g_dates', 'js.filters.t_mine', 'js.filters.chip_not', 'js.filters.tags_hint', 'search.sortby_year_desc',
          'search.sortby_steam', 'js.app.sort_limited', 'perm.tags.favourite', 'tags.mine_h2', 'js.tags.fav_on', 'api.tags.fav_limit', 'settings.facts_chips_lists'];
check('the words in both languages', !array_diff($words, array_keys($plL)) && !array_diff($words, array_keys($enL)) && $plL['js.filters.chip_not'] === 'bez :name');
check('version and changelog', version_compare(TRACKER_VERSION, '1.86.0', '>=') && str_contains($src('CHANGELOG.md'), '## [1.86.0]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
