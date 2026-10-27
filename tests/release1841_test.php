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

$tags = fn(string $name): string => implode(' ', factsFromName($name)['tags']);
$year = fn(string $name) => factsFromName($name)['year'];
$outer = 'www.UIndex.org    -    Outer Banks S04E06 2020 2160p NF WEB-DL DDP5 1 Atmos DV H 265-HHWEB';
check('a scene name: resolution, codec, Dolby Vision, Atmos, E-AC-3, WEB-DL, a series, the year; the site in front is not read',
      $tags($outer) === 'q:2160p v:h265 h:dv a:atmos a:eac3 s:webdl t:series' && $year($outer) === 2020, $tags($outer));
check('Polish names: PL is Polish audio, Lektor and MULTi are their own tags',
      $tags('Film.2019.PL.1080p.BluRay.x264.AC3-KiT') === 'q:1080p v:h264 a:ac3 s:bluray l:audio_pl t:movie'
      && $tags('Seriale.S01.MULTi.720p.HDTV.x264.Lektor.PL') === 'q:720p v:h264 s:hdtv l:lektor_pl l:multi t:series');
check('an anime episode is a series: " - 04 ", "[112]"; a year after a dash is not',
      $tags('[SubsPlease] Kyouran Reijou Nia Liston - 04 (1080p) [1A604A90].mkv') === 'q:1080p t:series'
      && str_ends_with($tags('[GM-Team][国漫][仙逆][Renegade Immortal][2023][112][AVC][GB][1080P].mp4'), 't:series')
      && str_ends_with($tags('Avatar - 2009 1080p BluRay'), 't:movie'));
check('no tags without a video token: a title word, a program, a music album',
      $tags('Sitter Cam (2014)') === '' && $tags('4K Video Downloader Plus v26.0.8 - Portable.rar') === '' && $tags('Artist - Album (2024) [WEB FLAC]') === '');
check('… and no false tag inside a video name: a site\'s .PL, Multi-Subs, a film called Opus',
      !str_contains($tags('[DEVIL-TORRENTS.PL] Trouble.Man.2025.MULTI.HDR.2160p.BluRay'), 'l:audio_pl')
      && str_contains($tags('[DEVIL-TORRENTS.PL] Trouble.Man.2025.MULTI.HDR.2160p.BluRay'), 'l:multi')
      && !str_contains($tags('[Judas] Plastic Memories (Season 1) [BD 1080p][HEVC x265 10bit][Multi-Subs]'), 'l:multi')
      && !str_contains($tags('Mr Hollands Opus 1995 1080p BluRay x264'), 'a:opus')
      && str_contains($tags('Resident.Evil.Apocalypse.2004.1080p.Bluray.AV1.OPUS.5.1-DECK.mkv'), 'a:opus'));
check('the year: one in brackets first, else the last one — a title that is a number is not the year; UHD with 1080p is 1080p',
      $year('2001.A.Space.Odyssey.1968.1080p.BluRay.x264') === 1968 && $year('Blade Runner 2049 (2017) 2160p') === 2017
      && $year('Wonder.Woman.1984.2020.1080p.WEB-DL') === 2020 && str_starts_with($tags('The Equalizer 2014 UHD BluRay 1080p x265'), 'q:1080p')
      && $tags('New.Movie.2025.HDCAM.x264') === 'v:h264 s:cam t:movie');

$desc = ['tags' => [['id' => 'q:720p', 'g' => 'q', 'l' => '720p']]];
$byName = ['facts_source' => 'desc_name'];
$fromName = factsRowChips(null, 'X 1080p x264', $byName);
check('chips: a description wins; a name gives chips only with "descriptions and names", marked as from the name',
      factsRowChips($desc, 'X 1080p', $byName) === $desc && factsRowChips(null, 'X 1080p x264', ['facts_source' => 'desc']) === null
      && ($fromName['from'] ?? '') === 'name' && array_column($fromName['tags'] ?? [], 'id') === ['q:1080p', 'v:h264', 't:movie']
      && factsMode([]) === 'desc' && factsMode(['facts_source' => 'bogus']) === 'desc', json_encode($fromName));

$coll = $db->query("SELECT table_name, collation_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'info_hash'
                    AND table_name IN ('name_facts', 'index_hashes', 'index_catalog', 'whitelist')")->fetchAll(PDO::FETCH_KEY_PAIR);
$coll = array_change_key_case($coll);
check('name_facts\' hash has the collation of the tables it is matched against (a mismatch ran the subquery once per row)',
      isset($coll['name_facts']) && count(array_unique($coll)) === 1, json_encode($coll));

$hh = fn(int $i): string => 'f1841' . str_repeat('0', 34) . $i;
$settingsBefore = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('name_facts_cursor', 'name_facts_wl_at')")->fetchAll(PDO::FETCH_KEY_PAIR);
$wipe = function () use ($db, $hh): void {
    foreach ([1, 2, 3, 4] as $i) {
        foreach (["DELETE FROM index_catalog WHERE info_hash = ?", "DELETE FROM index_hashes WHERE info_hash = ?",
                  "DELETE FROM name_facts WHERE info_hash = ?", "DELETE FROM whitelist WHERE info_hash = ?"] as $sql) $db->prepare($sql)->execute([$hh($i)]);
    }
};
$restore = function () use ($db, $settingsBefore): void {
    foreach (['name_facts_cursor', 'name_facts_wl_at'] as $k) {
        if (array_key_exists($k, $settingsBefore)) $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES (?, ?)")->execute([$k, $settingsBefore[$k]]);
        else $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$k]);
    }
};
$wipe();
register_shutdown_function($wipe);
register_shutdown_function($restore);
$ih = $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seen, last_seeders, meta_status, total_size, files_count) VALUES (?, ?, UTC_TIMESTAMP(), ?, 'done', ?, 3)");
$ic = $db->prepare("INSERT INTO index_catalog (info_hash, name, eff_seeders, eff_leechers, total_size, files_count, last_seen) VALUES (?, ?, ?, 0, ?, 3, UTC_TIMESTAMP())");
foreach ([[1, 'Zyxqor.Alpha.2019.PL.1080p.WEB-DL.x264', 12, 10], [2, 'Zyxqor Beta S02E03 2160p WEB-DL H 265', 40, 60], [3, 'Zyxqor Gamma Holiday Pictures', 5, 1]] as [$i, $name, $seed, $gib]) {
    $ih->execute([$hh($i), $name, $seed, $gib * 1024 ** 3]);
    $ic->execute([$hh($i), $name, $seed, $gib * 1024 ** 3]);
}
$db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, meta_status, scrape_seeders) VALUES (?, ?, ?, 2, 'admin', NOW(), 0, 'done', 3)")
   ->execute([$hh(4), 'Zyxqor Delta 2021 720p HDTV x264', 2 * 1024 ** 3]);

$cfgName = ['facts_source' => 'desc_name', 'name_facts_cursor' => 'f18409' . str_repeat('f', 34)] + $cfg;
$tick = factsNameTick($db, $cfgName, 5.0);
$stored = $db->query("SELECT info_hash, nf_kind, nf_res, nf_year, nf_bits FROM name_facts WHERE info_hash LIKE 'f18410%' ORDER BY info_hash")->fetchAll(PDO::FETCH_ASSOC);
$byHash = array_column($stored, null, 'info_hash');
$alpha = factsNameCodes(['q:1080p', 'v:h264', 's:webdl', 'l:audio_pl', 't:movie']);
check('the background read: from the cursor to the end of the catalogue, then the whitelist, then "done"',
      $tick['done'] === true && $db->query("SELECT `value` FROM settings WHERE `key` = 'name_facts_cursor'")->fetchColumn() === 'done'
      && isset($byHash[$hh(1)], $byHash[$hh(2)], $byHash[$hh(4)]) && !isset($byHash[$hh(3)])
      && (int)$byHash[$hh(1)]['nf_kind'] === 1 && (int)$byHash[$hh(1)]['nf_res'] === 3 && (int)$byHash[$hh(1)]['nf_year'] === 2019
      && (int)$byHash[$hh(1)]['nf_bits'] === $alpha[2], json_encode($stored));
check('… in the "descriptions only" mode it reads nothing', factsNameTick($db, ['facts_source' => 'desc'] + $cfg) === ['read' => 0, 'done' => true]);
factsNameStore($db, [['info_hash' => $hh(1), 'name' => 'Zyxqor Alpha holiday']]);
check('a name that lost its tokens loses its row', !$db->query("SELECT 1 FROM name_facts WHERE info_hash = '" . $hh(1) . "'")->fetchColumn());
factsNameStore($db, [['info_hash' => $hh(1), 'name' => 'Zyxqor.Alpha.2019.PL.1080p.WEB-DL.x264']]);
indexCatalogForget($db, [$hh(2)]);
$gone = !$db->query("SELECT 1 FROM name_facts WHERE info_hash = '" . $hh(2) . "'")->fetchColumn();
$prevCfg = $GLOBALS['cfg'] ?? null;
$GLOBALS['cfg'] = $cfgName;
indexCatalogUpsertRows($db, [array_combine(IDX_CATALOG_COLS, [$hh(2), 'Zyxqor Beta S02E03 2160p WEB-DL H 265', 40, 0, 60 * 1024 ** 3, 3, gmdate('Y-m-d H:i:s')])], true);
$GLOBALS['cfg'] = $prevCfg;
$idx = $src('includes/index.php');
check('a hash leaving the catalogue leaves name_facts; one written to it with new metadata is read at once in that mode, not on a poll\'s numbers',
      $gone && $db->query("SELECT CONCAT(nf_kind, '/', nf_res) FROM name_facts WHERE info_hash = '" . $hh(2) . "'")->fetchColumn() === '2/5'
      && str_contains($idx, 'if ($rows) $n = indexCatalogUpsertRows($db, $rows, true);') && str_contains($idx, 'if ($rows) indexCatalogUpsertRows($db, $rows);')
      && str_contains($idx, 'indexCatalogUpsertRows($db, $members, $filling)'));

check('the name filter: typed columns, none when a filter only a description can answer is on',
      factsNamePredicate(['tags' => [['q:1080p', 'q:2160p']], 'type' => 'movie']) === ['nf.nf_res IN (?,?) AND nf.nf_kind = ?', [3, 5, 1]]
      && factsNamePredicate(['tags' => [['q:1080p']], 'pics' => true]) === null && factsNamePredicate(['tags' => [['q:1080p']], 'imdb' => [7.0, null]]) === null
      && factsNamePredicate(['tags' => []]) === null);

$req = factsRequest(['tags' => 'q:1080p', 'size' => '5G..20G', 'filecount' => '..10', 'seeders' => '10..99']);
[$fw, $fp] = factsListFilters($db, $cfgName, $req, 'SEEDS', 'whitelist');
check('the panel\'s filters: the ranges (seeders only from below) and the facts as one condition',
      $fw[0] === 'total_size >= ?' && $fw[1] === 'total_size <= ?' && $fw[2] === 'files_count <= ?' && $fw[3] === 'SEEDS >= ?' && count($fw) === 5
      && str_contains($fw[4], 'info_hash IN') && array_slice($fp, 0, 4) === [5 * 1024 ** 3, 20 * 1024 ** 3, 10, 10], json_encode([$fw, $fp]));

$list = function (array $get) use ($db, $cfgName): array {
    $flt = factsEngineFilters($db, $cfgName, factsRequest($get), true, true);
    $r = $flt ? indexListByEngine($db, $cfgName, ['search' => 'f18410', 'per_page' => 50], $flt) : indexListSelect($db, $cfgName, ['search' => 'f18410', 'per_page' => 50]);
    $h = array_column($r['rows'], 'info_hash');
    sort($h);
    return $h;
};
check('the panel Index list: a tag, the kind, a year, size and seeders ranges',
      $list(['tags' => 'q:2160p']) === [$hh(2)] && $list(['type' => 'movie']) === [$hh(1)] && $list(['year' => '2019..2019']) === [$hh(1)]
      && $list(['size' => '50G..']) === [$hh(2)] && $list(['size' => '..20G']) === [$hh(1), $hh(3)] && $list(['seeders' => '10..']) === [$hh(1), $hh(2)]
      && $list([]) === [$hh(1), $hh(2), $hh(3)], json_encode([$list(['tags' => 'q:2160p']), $list(['type' => 'movie'])]));
[$w, $p] = factsListFilters($db, $cfgName, factsRequest(['tags' => 'q:720p']), 'COALESCE(scrape_seeders, 0)', 'whitelist');
$sql = "SELECT info_hash FROM whitelist WHERE info_hash LIKE 'f18410%' AND " . implode(' AND ', $w);
$st = $db->prepare($sql);
$st->execute($p);
check('… and the panel Whitelist list', $st->fetchAll(PDO::FETCH_COLUMN) === [$hh(4)]);
$pub = function (array $get) use ($db, $cfgName): array {
    $r = indexSearchCatalogue($db, $cfgName, ['search' => 'Zyxqor', 'include_whitelist' => true, 'catalog' => false, 'per_page' => 50, 'page' => 1]
                                              + factsEngineFilters($db, $cfgName, factsRequest($get), true, true));
    $h = array_values(array_filter(array_column($r['rows'], 'info_hash'), fn($x) => str_starts_with((string)$x, 'f18410')));
    sort($h);
    return $h;
};
check('the public search filters by name too, the whitelist\'s rows included', $pub(['tags' => 'q:720p|q:1080p']) === [$hh(1), $hh(4)]
      && $pub(['type' => 'series']) === [$hh(2)], json_encode($pub(['tags' => 'q:720p|q:1080p'])));
$dense = factsNameFilter($db, ['tags' => [['q:1080p']]], 0);
$sql = "EXPLAIN SELECT COUNT(*) FROM index_hashes WHERE (info_hash IN ('" . $hh(3) . "') OR " . str_replace('{T}', '`index_hashes`', (string)($dense['tpl'] ?? '1 = 0')) . ")";
$st = $db->prepare($sql);
$st->execute($dense['args'] ?? []);
$perRowScan = array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($x) => $x['select_type'] === 'DEPENDENT SUBQUERY' && $x['type'] === 'ALL');
check('… and the plan never scans name_facts once per row', !$perRowScan, json_encode($perRowScan));

$save = $src('api/admin/save_settings.php');
check('the setting: two values, anything else is "descriptions only"; turning names on starts the read from the beginning',
      str_contains($save, "!in_array(\$data['facts_source'], ['desc', 'desc_name'], true)")
      && str_contains($save, "REPLACE INTO settings (`key`, `value`) VALUES ('name_facts_cursor', '')")
      && str_contains($src('templates/admin/settings.php'), 'name="facts_source"') && ($cfg['facts_source'] ?? 'desc') !== ''
      && trackerSchemaDefaultSettings()['facts_source'] === 'desc');
$jan = $src('tools/janitor.php');
check('the janitor reads names in the background and the whitelist\'s again every ten minutes',
      str_contains($jan, "factsNameTick(\$db, \$cfg)") && str_contains($jan, "time() - (int)(\$cfg['name_facts_wl_at'] ?? 0) >= 600"));
check('the panel lists take the filters and the chips filter',
      str_contains($src('api/admin/fetch_index.php'), "factsEngineFilters(\$db, \$cfg, factsRequest(\$_GET), true, true)")
      && str_contains($src('api/admin/fetch_index.php'), "\$res = \$flt ? indexListByEngine(\$db, \$cfg, \$q, \$flt) : indexListSelect(\$db, \$cfg, \$q);")
      && str_contains($src('api/admin/fetch_whitelist.php'), "factsListFilters(\$db, \$cfg, factsRequest(\$_GET), 'COALESCE(scrape_seeders, 0)', 'whitelist')")
      && str_contains($src('templates/admin/index_page.php'), 'id="idx-filters"') && str_contains($src('templates/admin/whitelist.php'), 'id="wl-filters"')
      && str_contains($src('assets/js/admin-index.js'), "window.FactChips.render(r.facts, filters ? (id) => filters.addTag(id) : null)")
      && str_contains($src('assets/js/admin-whitelist.js'), "window.FactChips.render(row.facts, state.wl.filters ? (id) => state.wl.filters.addTag(id) : null)")
      && str_contains($src('assets/js/filters.js'), "const panelUi = opts.ui === 'panel';")
      && str_contains($src('assets/css/admin.css'), '.admin-body .sf-host {') && str_contains($src('assets/css/admin.css'), '.wl-bulkbar:not(.d-hidden) {'));
$plL = include $root . '/lang/pl.php';
$css = $src('assets/css/admin.css');
$rev = $src('assets/js/admin-review.js');
check('the owner\'s notes: chips not cut to two rows, the Actions line only over scrolled columns, centred review icons, the author toggles',
      !str_contains($css, '.wl-name-cell .fchips { max-height') && str_contains($css, '.wl-page .tbl-overflow .wl-table :is(th.th-actions')
      && str_contains($src('assets/js/admin-common.js'), "w.classList.toggle('tbl-overflow', w.scrollWidth > w.clientWidth + 1);")
      && str_contains($css, '.btn.rq-ic:has(> .bi) { align-items: center; }')
      && str_contains($rev, "const off = authorOn(name);") && str_contains($rev, "t.key('js.review.author_shown', { user: name, n: num(S.total) })"));
check('AVC and HEVC, as MediaInfo names them; ratings with one decimal', factsTagLabel('v:h264') === 'AVC' && factsTagLabel('v:h265') === 'HEVC'
      && str_contains($src('assets/js/filters.js'), "'Filmweb ' + Number(f.filmweb).toFixed(1)"));
check('the words, in both languages', __('settings.facts_source_desc_name') === 'Descriptions and torrent names'
      && ($plL['settings.facts_source_desc_name'] ?? '') === 'Z opisów i nazw torrentów');

$wipe();
$restore();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
