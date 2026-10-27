<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/users.php',
          'includes/lang.php', 'includes/facts.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');

$truehd = "[b]Video:[/b] #1, [b]Format:[/b] HEVC, [b]Bit rate mode:[/b] Variable, [b]Bit rate:[/b] 21.5 Mb/s, [b]Maximum bit rate:[/b] 40.0 Mb/s, [b]Width:[/b] 3 840 pixels, [b]Height:[/b] 2 160 pixels\n___\n"
        . "[b]Audio:[/b] #1, [b]Format:[/b] MLP FBA 16-ch, [b]Commercial name:[/b] Dolby TrueHD with Dolby Atmos, [b]Bit rate mode:[/b] Variable, [b]Bit rate:[/b] 4 500 kb/s, [b]Maximum bit rate:[/b] 6 000 kb/s, [b]Channel(s):[/b] 8 channels, [b]Language:[/b] English\n___\n"
        . "[b]Audio:[/b] #2, [b]Format:[/b] AC-3, [b]Bit rate mode:[/b] Constant, [b]Bit rate:[/b] 640 kb/s, [b]Channel(s):[/b] 6 channels, [b]Language:[/b] Polish";
$t = factsFromDescription($truehd, 'X');
check('video bit rate: the average, the maximum and the mode; the best audio track by its bit rate, with its codec and channels',
      $t['vbitrate'] === 21500 && $t['vbitrate_max'] === 40000 && $t['vmode'] === 'V' && $t['abitrate'] === 4500 && $t['abitrate_max'] === 6000
      && $t['amode'] === 'V' && $t['acodec'] === 'TrueHD Atmos' && $t['achannels'] === '7.1', json_encode($t));

$raw = <<<'TXT'
[b]Video:[/b] #1, [b]Format:[/b] AVC, [b]Bit rate:[/b] 9 000 kb/s, [b]Width:[/b] 1 920 pixels, [b]Height:[/b] 1 080 pixels
___
[b]Audio:[/b] #1, [b]Format:[/b] AC-3, [b]Bit rate:[/b] 640 kb/s, [b]Language:[/b] Polish, [b]Channel(s):[/b] 6 channels
### Dane techniczne:
[code]
General
Format                                   : Matroska
Overall bit rate mode                    : Variable
Overall bit rate                         : 10.3 Mb/s

Video
Format                                   : AVC
Bit rate                                 : 9 000 kb/s

Audio #1
Format                                   : AC-3
Bit rate mode                            : Constant
Bit rate                                 : 640 kb/s
Channel(s)                               : 6 channels
[/code]
TXT;
$r = factsFromDescription($raw, 'X');
check('a raw MediaInfo block is read too: the overall bit rate and its mode, the track\'s mode — and the forum\'s own lines still give the tags',
      $r['obitrate'] === 10300 && $r['omode'] === 'V' && $r['vbitrate'] === 9000 && $r['abitrate'] === 640 && $r['amode'] === 'C'
      && $r['achannels'] === '5.1' && $r['acodec'] === 'AC-3' && !array_diff(['q:1080p', 'v:h264', 'a:ac3', 'l:audio_pl'], $r['tags']), json_encode($r));

$core = factsFromDescription("[b]Audio:[/b] #1, [b]Format:[/b] MLP FBA AC-3 16-ch, [b]Commercial name:[/b] Dolby TrueHD with Dolby Atmos, [b]Bit rate mode:[/b] Variable / Constant, [b]Bit rate:[/b] Unknown / 640 kb/s, [b]Maximum bit rate:[/b] 7 000 kb/s / 640 kb/s, [b]Channel(s):[/b] 8 channels / 6 channels", 'X');
check('… a TrueHD track with an AC-3 core: the first layer counts (no average, 7 Mb/s at most, variable, 7.1)',
      $core['abitrate'] === null && $core['abitrate_max'] === 7000 && $core['amode'] === 'V' && $core['achannels'] === '7.1', json_encode($core));

$row = ['tags' => ',q:1080p,v:h264,', 'genres' => '', 'year_from' => 2003, 'year_to' => 2003, 'vbitrate' => 9000, 'vbitrate_max' => null, 'vmode' => null,
        'obitrate' => 10300, 'obitrate_max' => null, 'omode' => 'V', 'abitrate' => 640, 'abitrate_max' => null, 'amode' => 'C', 'acodec' => 'AC-3',
        'achannels' => '5.1', 'seasons' => null, 'episodes' => null, 'duration_min' => null, 'imdb' => '8.1', 'filmweb' => '7.7'];
$prevCfg = $GLOBALS['cfg'] ?? null;
$GLOBALS['cfg'] = $cfg;
$c = factsChips($row);
check('chips: the three bit rates labelled by their kind, the ratings, how many tags show',
      ($c['rates']['o'] ?? null) === ['r' => 10300, 'm' => 'V'] && ($c['rates']['v'] ?? null) === ['r' => 9000]
      && ($c['rates']['a'] ?? null) === ['r' => 640, 'm' => 'C', 'c' => 'AC-3', 'ch' => '5.1'] && $c['imdb'] === 8.1 && $c['max'] === 6, json_encode($c));
$GLOBALS['cfg'] = ['facts_chips_rates' => '0', 'facts_chips_ratings' => '0', 'facts_chips_max' => '3'] + $cfg;
$c = factsChips($row);
check('… the settings: no bit rates, no ratings, three tags before "+N"', !isset($c['rates']) && !isset($c['imdb']) && !isset($c['filmweb']) && $c['max'] === 3, json_encode($c));
$GLOBALS['cfg'] = $prevCfg;
$descChips = ['tags' => [['id' => 'q:720p', 'g' => 'q', 'l' => '720p']]];
check('… and where they show: search, Info and the panel each on its own switch',
      factsRowChips($descChips, 'X', ['facts_chips_info' => '0'] + $cfg, 'info') === null && factsRowChips($descChips, 'X', $cfg, 'info') === $descChips
      && factsRowChips($descChips, 'X', ['facts_chips_panel' => '0'] + $cfg, 'search') === $descChips);

[$kind, $res, $bits] = factsNameCodes(['q:2160p', 'v:h265', 'h:dv', 't:series']);
$bit = fn(string $id): int => 1 << array_search($id, FACTS_NAME_BITS, true);
check('a name\'s facts as numbers: series, 2160p, a bit for each other tag', $kind === 2 && $res === 5 && $bits === ($bit('v:h265') | $bit('h:dv')));
check('the name filter on those numbers: a group\'s tags as one mask, the year bounded, a genre only a description has',
      factsNamePredicate(['tags' => [['l:lektor_pl', 'l:dub_pl']], 'year' => [2019, 2021]])
          === ['(nf.nf_bits & ?) <> 0 AND nf.nf_year > 0 AND nf.nf_year >= ? AND nf.nf_year <= ?', [$bit('l:lektor_pl') | $bit('l:dub_pl'), 2019, 2021]]
      && factsNamePredicate(['tags' => [['g:dramat']]]) === null && factsNamePredicate(['vbitrate' => [5000, null]]) === null);

$hh = fn(int $i): string => 'f1842' . str_repeat('0', 34) . $i;
$wipe = function () use ($db, $hh): void {
    foreach ([1, 2, 3, 4, 5] as $i) {
        foreach (["DELETE FROM index_catalog WHERE info_hash = ?", "DELETE FROM index_hashes WHERE info_hash = ?", "DELETE FROM name_facts WHERE info_hash = ?",
                  "DELETE FROM desc_facts WHERE info_hash = ?", "DELETE FROM whitelist WHERE info_hash = ?"] as $sql) $db->prepare($sql)->execute([$hh($i)]);
    }
};
$wipe();
register_shutdown_function($wipe);
$ih = $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seen, last_seeders, meta_status, total_size, files_count) VALUES (?, ?, UTC_TIMESTAMP() - INTERVAL ? MINUTE, ?, 'done', ?, 3)");
$ic = $db->prepare("INSERT INTO index_catalog (info_hash, name, eff_seeders, eff_leechers, total_size, files_count, last_seen) VALUES (?, ?, ?, 0, ?, 3, UTC_TIMESTAMP() - INTERVAL ? MINUTE)");
foreach ([[1, 'Zyxqyr.One.2019.1080p.WEB-DL.x264', 12, 10, 3], [2, 'Zyxqyr Two S01E02 2160p WEB-DL H 265', 40, 60, 2], [3, 'Zyxqyr Three 2020 1080p BluRay x265', 8, 30, 1]] as [$i, $name, $seed, $gib, $age]) {
    $ih->execute([$hh($i), $name, $age, $seed, $gib * 1024 ** 3]);
    $ic->execute([$hh($i), $name, $seed, $gib * 1024 ** 3, $age]);
}
$db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, meta_status, content_status, description, description_format)
              VALUES (?, ?, ?, 2, 'admin', NOW(), 0, 'done', 'approved', ?, 'bbcode')")->execute([$hh(4), 'Zyxqyr Four 2021 1080p HDTV x264', 2 * 1024 ** 3, $raw]);
$wlId = (int)$db->query("SELECT id FROM whitelist WHERE info_hash = '" . $hh(4) . "'")->fetchColumn();
factsTouch($db, 'wl', (string)$wlId);
factsNameStore($db, [['info_hash' => $hh(1), 'name' => 'Zyxqyr.One.2019.1080p.WEB-DL.x264'], ['info_hash' => $hh(2), 'name' => 'Zyxqyr Two S01E02 2160p WEB-DL H 265'],
                     ['info_hash' => $hh(3), 'name' => 'Zyxqyr Three 2020 1080p BluRay x265'], ['info_hash' => $hh(4), 'name' => 'Zyxqyr Four 2021 1080p HDTV x264']]);
$stored = $db->query("SELECT vbitrate, obitrate, omode, abitrate, amode, acodec, achannels, ver FROM desc_facts WHERE info_hash = '" . $hh(4) . "'")->fetch(PDO::FETCH_ASSOC);
check('the new facts are stored with the description, at parser version 2 (every description is read again)',
      $stored && (int)$stored['obitrate'] === 10300 && $stored['omode'] === 'V' && (int)$stored['abitrate'] === 640 && $stored['achannels'] === '5.1'
      && (int)$stored['ver'] === 2 && FACTS_VERSION === 2, json_encode($stored));

$f1080 = ['tags' => [['q:1080p']]];
$sparse = factsNameFilter($db, $f1080);
$dense = factsNameFilter($db, $f1080, 1);
check('few matches come back as a list of hashes; more than the cap as a check per row by the key',
      isset($sparse['hashes']) && in_array($hh(1), $sparse['hashes'], true) && in_array($hh(3), $sparse['hashes'], true) && !in_array($hh(2), $sparse['hashes'], true)
      && isset($dense['tpl']) && str_contains($dense['tpl'], 'nf.info_hash = {T}.info_hash') && $dense['args'] === [3], json_encode([$sparse, $dense]));
$cfgName = ['facts_source' => 'desc_name'] + $cfg;
$hits = function (array $flt, string $search = 'Zyxqyr', bool $wl = true) use ($db, $cfgName): array {
    $r = indexSearchCatalogue($db, $cfgName, ['search' => $search, 'include_whitelist' => $wl, 'catalog' => false, 'per_page' => 50, 'page' => 1] + $flt);
    $h = array_values(array_filter(array_column($r['rows'], 'info_hash'), fn($x) => str_starts_with((string)$x, 'f1842')));
    sort($h);
    return $h;
};
check('the search with the check per row: the catalogue\'s rows and the whitelist\'s, each against its own table',
      $hits(['only_hashes' => [], 'name_clause' => [$dense['tpl'], $dense['args']]]) === [$hh(1), $hh(3), $hh(4)]
      && $hits(factsEngineFilters($db, $cfgName, factsRequest(['tags' => 'q:2160p']), true, true)) === [$hh(2)], json_encode($hits(['only_hashes' => [], 'name_clause' => [$dense['tpl'], $dense['args']]])));
check('… a description\'s video bit rate as a filter (Mb/s), only where a description says it',
      factsRange('4.5..', 'rate') === [4500, null] && $hits(factsEngineFilters($db, $cfg, factsRequest(['vbitrate' => '8..']), true, true)) === [$hh(4)]
      && $hits(factsEngineFilters($db, $cfg, factsRequest(['vbitrate' => '10..']), true, true)) === []);

$panel = function (array $q, array $get) use ($db, $cfgName): array {
    $r = indexListByEngine($db, $cfgName, $q + ['search' => 'f18420', 'per_page' => 50], factsEngineFilters($db, $cfgName, factsRequest($get), true, true));
    return array_column($r['rows'], 'info_hash');
};
$refused = function (array $q) use ($panel): string {
    try { $panel($q, ['size' => '1G..']); return ''; } catch (IndexFiltersUnsupported $e) { return $e->getMessage(); }
};
check('the panel Index with filters goes through the search\'s engine: its sorts, its rows in the panel\'s shape',
      $panel(['sort' => 'size:desc'], ['size' => '5G..']) === [$hh(2), $hh(3), $hh(1)] && $panel(['sort' => 'last:desc'], ['tags' => 'q:1080p']) === [$hh(3), $hh(1)]
      && array_key_exists('meta_status', indexListByEngine($db, $cfgName, ['search' => 'f18420'], ['size_min' => 1])['rows'][0] ?? []),
      json_encode([$panel(['sort' => 'size:desc'], ['size' => '5G..']), $panel(['sort' => 'last:desc'], ['tags' => 'q:1080p'])]));
check('… and says so when a sort or a state filter cannot go with the filters', $refused(['sort' => 'first:desc']) === 'sort' && $refused(['meta' => 'pending']) === 'state'
      && $refused(['life' => 'protected']) === 'state' && $refused(['meta' => 'done']) === ''
      && str_contains($src('api/admin/fetch_index.php'), "'code' => 'filters_unsupported'], 422);"));

$counts = factsCounts($db, $cfgName);
check('the counts: from the published descriptions and the names, for the filters', ($counts['n']['q:1080p'] ?? 0) >= 3 && ($counts['n']['q:2160p'] ?? 0) >= 1
      && ($counts['n']['t:series'] ?? 0) >= 1 && ($counts['n']['a:ac3'] ?? 0) >= 1 && is_array($counts['g']), json_encode(array_slice($counts['n'], 0, 8)));
$cat = factsCatalog(['facts_counts' => json_encode(['at' => 1, 'n' => ['q:1080p' => 10, 'q:2160p' => 50, 'g:dramat' => 3], 'g' => ['dramat' => 'Dramat']])] + $cfg);
$byId = array_column($cat, null, 'id');
$q = array_values(array_filter($cat, fn($c) => $c['g'] === 'q'));
check('the filter panel\'s catalogue: counts, the most common first, aliases to search by, genres from the data',
      ($byId['q:2160p']['n'] ?? null) === 50 && $q[0]['id'] === 'q:2160p' && $q[1]['id'] === 'q:1080p' && ($byId['q:sd']['n'] ?? null) === 0
      && str_contains($byId['v:h265']['a'] ?? '', 'x265') && ($byId['g:dramat']['l'] ?? '') === 'Dramat' && ($byId['g:dramat']['n'] ?? 0) === 3
      && !isset(factsCatalog(['facts_filter_counts' => '0', 'facts_counts' => json_encode(['n' => ['q:1080p' => 10]])] + $cfg)[0]['n']));

$cols = $db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'name_facts'")->fetchAll(PDO::FETCH_COLUMN);
check('schema 106: name_facts in numbers with one covering key, desc_facts with the bit rates',
      TRACKER_SCHEMA_VERSION >= 106 && in_array('nf_bits', $cols, true) && !in_array('tags', $cols, true)
      && (bool)$db->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'name_facts' AND index_name = 'idx_nf'")->fetchColumn()
      && schemaColumnExists($db, 'desc_facts', 'achannels'));
$def = trackerSchemaDefaultSettings();
$save = $src('api/admin/save_settings.php');
$tpl = $src('templates/admin/settings.php');
check('the settings "Tags and filters": defaults, saving, the section and its search words',
      $def['facts_chips_search'] === '1' && $def['facts_chips_info'] === '1' && $def['facts_chips_panel'] === '1' && $def['facts_chips_max'] === '6'
      && $def['facts_chips_rates'] === '1' && $def['facts_chips_ratings'] === '1' && $def['facts_filter_counts'] === '1'
      && str_contains($save, "(string)max(1, min((int)\$data['facts_chips_max'] ?: 6, 20))") && str_contains($tpl, 'id="section-facts"')
      && str_contains($tpl, 'name="facts_chips_max"') && str_contains($tpl, 'name="facts_filter_counts"')
      && str_contains($src('includes/settings_catalog.php'), "// #section-facts"));
check('the janitor and the command keep the counts', str_contains($src('tools/janitor.php'), 'factsCountsTick($db, $cfg);')
      && str_contains($src('tools/facts.php'), 'factsCountsTick($db, $cfg);'));
$js = $src('assets/js/filters.js');
check('the page: a tag search, counts on the tags and empty ones hidden, genres, the video bit rate filter, labelled bit rate chips',
      str_contains($js, "el('div', 'sf-tagsearch')") && str_contains($js, "b.dataset.n === '0'") && str_contains($js, "['g', 'genre']")
      && str_contains($js, "{ key: 'vbitrate', label: 'vbitrate', group: 'quality', facts: true, desc: true, minOnly: true, mbps: true }")
      && str_contains($js, 'function rateText(k, r)') && str_contains($js, 'const lim = o.all ? tags.length : (f.max > 0 ? f.max : 6);')
      && str_contains($src('assets/js/app.js'), "'imdb', 'filmweb', 'vbitrate'];") && str_contains($src('assets/css/admin.css'), '.admin-body .sf-n {')
      && str_contains($src('assets/css/style.css'), '.sf-n {'));
$plL = include $root . '/lang/pl.php';
check('the words, in both languages', __('settings.facts_heading') === 'Tags and filters' && ($plL['settings.facts_heading'] ?? '') === 'Tagi i filtry'
      && __('js.filters.chip_rate_max_mode', ['kind' => 'Video', 'avg' => '4.1 Mb/s', 'max' => '9 Mb/s', 'mode' => 'VBR']) === 'Video 4.1 Mb/s (max 9 Mb/s) · VBR'
      && ($plL['js.filters.chip_rate_max'] ?? '') === ':kind :avg (maks. :max)' && __('api.index.filters_sort') !== 'api.index.filters_sort');

$wipe();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
