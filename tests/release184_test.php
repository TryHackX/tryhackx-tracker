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

$series = <<<'TXT'
[center]
## Outer Banks (2020 - 2026) [88.23 GiB]
[/center]
### Szybki podgląd:
[b]Rok premiery:[/b] 2020 - 2026, [b]Kategoria wiekowa:[/b] TV-MA, [b]Serial:[/b] TV Series
[b]Rozmiar:[/b] 88.23 GiB, [b]Czas trwania:[/b] \~51 min 50 sec, [b]Typ wydania:[/b] WEB-DL
[b]Informacje językowe:[/b] Lektor Polski, Napisy Polskie, Napisy Angielskie
[b]Gatunek:[/b] Dramat, Przygodowy
[b]Ocena:[/b] IMDB - 7.5/10 (100K), Filmweb - 7,1/10 (1 000)
[hide][b]Video:[/b] #9, [b]Format:[/b] HEVC, [b]Width:[/b] 3 840 pixels, [b]Height:[/b] 2 160 pixels[/hide]
[img]https://example.org/a.png[/img]
___
[b]Video:[/b] #5, [b]Format:[/b] AVC, [b]Bit rate:[/b] 4 137 kb/s, [b]Width:[/b] 1 920 pixels, [b]Height:[/b] 800 pixels
[b]HDR format:[/b] Dolby Vision, Version 1.0, dvhe.08.06, BL+RPU, HDR10 compatible / SMPTE ST 2086, HDR10 compatible
___
[b]Audio:[/b] #1, [b]Format:[/b] E-AC-3 JOC, [b]Commercial name:[/b] Dolby Digital Plus with Dolby Atmos, [b]Language:[/b] Polish, [b]Title:[/b] Lektor
___
[b]Audio:[/b] #2, [b]Format:[/b] E-AC-3, [b]Language:[/b] English
___
[b]Text:[/b] #1, [b]Format:[/b] UTF-8, [b]Language:[/b] Polish
TXT;
$f = factsFromDescription($series, 'Outer Banks (2020 - 2026)');
$want = ['q:1080p', 'v:h264', 'h:dv', 'h:hdr10', 'a:atmos', 'a:eac3', 's:webdl', 'l:lektor_pl', 'l:sub_pl', 'l:sub_en', 'l:audio_en', 't:series', 'g:dramat', 'g:przygodowy'];
check('a forum description: resolution from width and height (1920×800 is 1080p), codec, HDR, audio, source, languages, series, genres',
      !array_diff($want, $f['tags']) && $f['res'] === 1080, json_encode($f['tags']) . ' res ' . $f['res']);
check('… nothing from [hide] (the hidden HEVC 2160p is not read)', !in_array('v:h265', $f['tags'], true) && !in_array('q:2160p', $f['tags'], true));
check('… the numbers: years 2020–2026, 52 minutes, IMDb 7.5, Filmweb 7.1, video 4 137 kb/s, pictures',
      $f['year_from'] === 2020 && $f['year_to'] === 2026 && $f['duration_min'] === 52 && $f['imdb'] === 7.5 && $f['filmweb'] === 7.1
      && $f['vbitrate'] === 4137 && $f['pics'] === 1, json_encode($f));
check('… the groups come in their order: quality, video, HDR, audio, source, language, type, genre',
      array_search('q:1080p', $f['tags'], true) < array_search('v:h264', $f['tags'], true)
      && array_search('s:webdl', $f['tags'], true) < array_search('l:lektor_pl', $f['tags'], true)
      && array_search('t:series', $f['tags'], true) < array_search('g:dramat', $f['tags'], true));
$movie = factsFromDescription("[b]Rok premiery:[/b] 2003, [b]Typ wydania:[/b] Bluray\n[b]Czas trwania:[/b] 2 h 26 min\n___\n[b]Video:[/b] #1, [b]Format:[/b] HEVC, [b]Width:[/b] 3 840 pixels, [b]Height:[/b] 1 600 pixels\n___\n[b]Audio:[/b] #1, [b]Format:[/b] MLP FBA 16-ch, [b]Commercial name:[/b] Dolby TrueHD with Dolby Atmos, [b]Language:[/b] English", 'X');
check('a movie: 2160p from 3840 wide, H.265, TrueHD with Atmos, BluRay, a movie of 146 minutes',
      !array_diff(['q:2160p', 'v:h265', 'a:atmos', 'a:truehd', 's:bluray', 'l:audio_en', 't:movie'], $movie['tags']) && $movie['duration_min'] === 146,
      json_encode($movie));

check('ranges: units, open ends, a swapped pair, nonsense refused', factsRange('10GB..', 'size') === [10 * 1024 ** 3, null]
      && factsRange('10G..', 'size') === [10 * 1024 ** 3, null] && factsRange('..50', 'count') === [null, 50]
      && factsRange('20..10', 'count') === [10, 20] && factsRange('abc', 'count') === null && factsRange('7..', 'imdb') === [7.0, null]);
check('tags from the address: | is either, a comma is and; unknown ones dropped', factsTagsParam('q:1080p|q:2160p,a:atmos,x:nope') === [['q:1080p', 'q:2160p'], ['a:atmos']]);
$cat = factsCatalog();
check('the filter panel\'s catalogue has every group with its words', count($cat) > 35 && in_array(['id' => 'l:lektor_pl', 'g' => 'l', 'l' => 'PL voice-over'], $cat, true)
      && !array_filter($cat, fn($c) => $c['g'] === 't'));

$h = fn(int $i): string => 'f184' . str_repeat('0', 35) . $i;
$wipe = function () use ($db, $h): void {
    foreach ([1, 2, 3] as $i) {
        $db->prepare("DELETE FROM desc_facts WHERE info_hash = ?")->execute([$h($i)]);
        $db->prepare("DELETE FROM forum_descs WHERE info_hash = ?")->execute([$h($i)]);
        $db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$h($i)]);
    }
};
$wipe();
register_shutdown_function($wipe);
$ins = $db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, content_status, meta_status, description, description_format)
                     VALUES (?, ?, ?, ?, 'admin', NOW(), 0, ?, 'done', ?, 'bbcode')");
$ins->execute([$h(1), 'Zyxqar Outer One', 95 * 1024 ** 3, 51, 'approved', $series]);
$ins->execute([$h(2), 'Zyxqar Movie Two', 40 * 1024 ** 3, 2, 'approved', "[b]Rok premiery:[/b] 2003, [b]Typ wydania:[/b] Bluray\n___\n[b]Video:[/b] #1, [b]Format:[/b] AVC, [b]Width:[/b] 1 280 pixels, [b]Height:[/b] 534 pixels"]);
$ins->execute([$h(3), 'Zyxqar Turned Down', 1024 ** 3, 1, 'rejected', $series]);
foreach ([1, 2, 3] as $i) {
    $id = (int)$db->query("SELECT id FROM whitelist WHERE info_hash = '" . $h($i) . "'")->fetchColumn();
    factsTouch($db, 'wl', (string)$id);
}
$stored = (int)$db->query("SELECT COUNT(*) FROM desc_facts WHERE info_hash LIKE 'f184%'")->fetchColumn();
check('a description written is read into facts at once (the touch every description write already makes)', $stored === 3
      && str_contains($src('includes/imagerefs.php'), "if (function_exists('factsTouch')) factsTouch(\$db, \$src, \$key);"), (string)$stored);
$chips = factsChipsFor($db, [$h(1), $h(2), $h(3)], true);
check('chips only for a published description, never a turned-down one', isset($chips[$h(1)], $chips[$h(2)]) && !isset($chips[$h(3)])
      && $chips[$h(1)]['year'] === '2020–2026' && $chips[$h(1)]['tags'][0] === ['id' => 'q:1080p', 'g' => 'q', 'l' => '1080p'], json_encode($chips));
$db->prepare("INSERT INTO forum_descs (info_hash, client_id, discussion_id, post_id, state, audience, import_sha) VALUES (?, 1, 184, 184, 'imported', 'members', 'x')")->execute([$h(1)]);
check('… and for a guest, not from a description the forum sent for members', !isset(factsChipsFor($db, [$h(1)], false)[$h(1)]) && isset(factsChipsFor($db, [$h(1)], true)[$h(1)]));

$hits = fn(array $q) => array_values(array_filter(factsFilterHashes($db, $q, true) ?? [], fn($x) => str_starts_with($x, 'f184')));
check('filters: a resolution, either of two, a source and a kind', $hits(['tags' => [['q:1080p']]]) === [$h(1)]
      && count($hits(['tags' => [['q:1080p', 'q:720p']]])) === 2 && $hits(['tags' => [['s:bluray']], 'type' => 'movie']) === [$h(2)]
      && $hits(['type' => 'series']) === [$h(1)]);
check('… years that overlap, a rating floor, with pictures', $hits(['year' => [2024, null]]) === [$h(1)] && $hits(['year' => [null, 2005]]) === [$h(2)]
      && $hits(['imdb' => [7.0, null]]) === [$h(1)] && $hits(['pics' => true]) === [$h(1)]);
check('… no facts filter at all is null — the search is not narrowed', factsFilterHashes($db, ['tags' => [], 'type' => ''], true) === null);

$rows = fn(array $q) => array_values(array_filter(array_column(indexSearchCatalogue($db, $cfg, $q + ['search' => 'Zyxqar', 'include_whitelist' => true,
    'catalog' => false, 'per_page' => 50, 'page' => 1])['rows'], 'info_hash'), fn($x) => str_starts_with((string)$x, 'f184')));
$all = $rows([]);
sort($all);
check('the search with the filters of the page: size, files, and the facts\' hashes', $all === [$h(1), $h(2), $h(3)]
      && $rows(['size_min' => 50 * 1024 ** 3]) === [$h(1)] && $rows(['files_max' => 2]) !== [] && !in_array($h(1), $rows(['files_max' => 2]), true)
      && $rows(['only_hashes' => [$h(2)]]) === [$h(2)] && $rows(['only_hashes' => []]) === [], json_encode($all));

$api = $src('api/index_search.php');
check('the endpoint: facts on the rows for a reader of descriptions, filters parsed, a filter change counted on its own limit',
      str_contains($api, "factsChipsFor(\$db, array_column(\$res['rows'], 'info_hash'), \$signedIn)")
      && str_contains($api, "\$refine ? 'idxfilter' : 'idxsearch'") && str_contains($api, "'only_hashes'       => \$onlyHashes,"));
$app = $src('assets/js/app.js');
check('the page: the filter bar keeps its state in the address, the chips filter, the count and Share sit on its right',
      str_contains($app, "moveRight: [\$id('search-total'), \$id('search-share')]") && str_contains($app, "if (filters) Object.assign(st, filters.params());")
      && str_contains($app, "window.FactChips.render(r.facts, filters ? (id) => filters.addTag(id) : null)")
      && str_contains($src('templates/layout.php'), 'assets/js/filters.js') && str_contains($src('templates/pages/search.php'), 'id="search-filters"'));
check('the janitor reads what changed, ten minutes apart; a command rebuilds all', str_contains($src('tools/janitor.php'), "time() - (int)(\$cfg['facts_ticked_at'] ?? 0) >= 600")
      && is_file($root . '/tools/facts.php'));
check('the words, in both languages', __('facts.tag.l_lektor_pl') === 'PL voice-over' && __('js.filters.more', ['n' => 2]) === 'More filters (2)');

$wipe();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
