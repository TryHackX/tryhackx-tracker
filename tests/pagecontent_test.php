<?php
/**
 * Tests for the editable Terms / Info pages:
 *   php tests/pagecontent_test.php
 *
 * The property that matters most here is not "can it save text" — it is that an override is only
 * ever an OVERRIDE: the shipped template stays the default, a draft cannot reach a visitor, and
 * restoring gives back a page written for how the tracker is configured at that moment rather than
 * a frozen copy from whenever the feature was built.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/lang.php';
require_once $root . '/includes/pagecontent.php';
// The default text is generated from the dictionary now, so a language has to be loaded.
// English, because the assertions below are written in it.
langInit(['default_language' => 'en']);

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);

// ── the catalogue is the allow-list ─────────────────────────────────────────
check('only the two pages it claims are editable', PAGECONTENT_PAGES === ['tos', 'info']);
check('the catalogue names a route and a template for each',
      count(array_filter(pageContentCatalog(), fn($m) => isset($m['route'], $m['template'], $m['label']))) === 2);
foreach (pageContentCatalog() as $k => $m) {
    check("$k: the template it claims to replace exists", is_file($root . '/' . $m['template']), $m['template']);
}
// 1.73.0: the panel named the pages in English on every page (Settings → Site pages, the editor's title, "… is published
// now"): each is its own heading in the reader's language now, a home section "Home page — <its name>" — and the audit
// log keeps English, whoever reads it.
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'pl']);
$plCat = pageContentCatalog();
$plHome = pageContentLabel('home:stats', $cfg);
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'en']);
check('1.73.0: the pages are named in the reader\'s language — by their own headings (tos.h1, info.h1) — and a home section as "Home page — …"',
      $plCat['tos']['label'] === langFor('pl', 'tos.h1') && $plCat['info']['label'] === langFor('pl', 'info.h1') && $plCat['tos']['label'] !== 'Terms of Service'
      && $plHome === langFor('pl', 'a.pages.home_section', ['section' => langFor('pl', 'a.home.sec_name_stats')]),
      json_encode([$plCat['tos']['label'], $plCat['info']['label'], $plHome], JSON_UNESCAPED_UNICODE));
$pcApi = (string)file_get_contents($root . '/api/admin/page_content.php');
check('… and in English for the audit log (pageContentLabel(…, true)), on both of its lines',
      pageContentLabel('tos', $cfg, true) === 'Terms of Service' && pageContentLabel('info', $cfg, true) === 'Tracker Information'
      && pageContentLabel('home:stats', $cfg, true) === 'Home page — Live tracker statistics'
      && substr_count($pcApi, 'pageContentLabel($page, $cfg, true)') === 2 && pageContentCatalog() == pageContentCatalog(true));

// ── the defaults are generated from the configuration, not frozen ───────────
$wlCfg   = array_merge($cfg, ['tracker_mode' => 'whitelist', 'users_enabled' => '1', 'index_enabled' => '0']);
$openCfg = array_merge($cfg, ['tracker_mode' => 'blacklist', 'users_enabled' => '0', 'index_enabled' => '0']);

// 1.34.0: the default text carries [[if:…]] MARKERS instead of deciding in PHP, so what a visitor
// reads is the default RESOLVED against the configuration. That is the whole point — a page the
// operator saved keeps following the settings the way the shipped one does — and it is what these
// checks have to look at. The raw text is the same whatever the mode; see the marker checks below.
$resolved = fn(array $c, string $page, string $fmt = 'markdown') => pageContentResolveMarkers(pageContentDefault($c, $page, $fmt, '', 'en'), $c);
$rawTos = pageContentDefault($wlCfg, 'tos', 'markdown', '', 'en');
check('the default carries conditional markers rather than deciding in PHP',
      substr_count($rawTos, '[[if:') >= 4 && substr_count($rawTos, '[[/if]]') >= 4, (string)substr_count($rawTos, '[[if:'));
check('the raw default is the same text whatever the mode — the markers do the deciding',
      $rawTos === pageContentDefault($openCfg, 'tos', 'markdown', '', 'en'));
check('resolving leaves no marker behind', !str_contains($resolved($openCfg, 'tos'), '[['));
check('an unknown condition hides its block and is reported',
      pageContentResolveMarkers('a [[if:bogus]]X[[/if]] b', $cfg, null, $unk) === 'a  b' && $unk === ['bogus']);
check('[[ifnot:]] is the complement', pageContentResolveMarkers('[[ifnot:users]]NO[[/if]]', $openCfg, null) === 'NO'
      && pageContentResolveMarkers('[[ifnot:users]]NO[[/if]]', $wlCfg, null) === '');
check('one level of nesting resolves innermost first',
      pageContentResolveMarkers('[[if:users]]U[[if:whitelist]]W[[/if]][[/if]]', $wlCfg, null) === 'UW');
$tosWl = $resolved($wlCfg, 'tos');
$tosOpen = $resolved($openCfg, 'tos');
check('the whitelist clause is in the terms when the tracker is in whitelist mode',
      str_contains($tosWl, 'Registering a torrent is free'));
check('… and is absent when it is not', !str_contains($tosOpen, 'Registering a torrent is free'));
check('the account terms appear only when accounts are on',
      str_contains($tosWl, 'User accounts') && !str_contains($tosOpen, 'User accounts'));

// 1.73.0: ONE description of each page (pageContentSpec()), rendered twice — as the template's HTML
// (pageContentHtml()) and as the editor's default text with markers. The two must say the same thing:
// as many lists, as many items, and every item where the other has it. The item count is not a literal
// any more — it is whatever the spec shows under this configuration, and both renderings must reach it.
$specItems = function (array $c, string $page): array {
    $conds = pageContentConditions($c, null);
    $shown = fn($x): bool => !is_array($x) || ((!isset($x['if']) || !empty($conds[$x['if']][0]))
                                             && (!isset($x['ifnot']) || empty($conds[$x['ifnot']][0])));
    $lists = 0; $items = 0;
    $walk = function (array $b) use (&$walk, $shown, &$lists, &$items): void {
        if (!$shown($b)) return;
        if ($b[0] === 'group') { foreach (pageContentItems($b) as $i) $walk($i); return; }
        if ($b[0] !== 'ol' && $b[0] !== 'ul') return;
        $n = count(array_filter(pageContentItems($b), $shown));
        if ($n) { $lists++; $items += $n; }
    };
    foreach (pageContentSpec($page) as $b) $walk($b);
    return [$lists, $items];
};
foreach (['whitelist' => $wlCfg, 'open' => $openCfg] as $label => $c) {
    foreach (['tos', 'info'] as $page) {
        [$lists, $items] = $specItems($c, $page);
        $md = richtextRender($resolved($c, $page), 'markdown', $cfg, true);
        $html = pageContentHtml(null, $c, $page, '/');
        $mdLists = substr_count($md, '<ol') + substr_count($md, '<ul');
        $htmlLists = substr_count($html, '<ol') + substr_count($html, '<ul');
        check("$label/$page: the default text has every item the spec shows, in as many lists (no list split in two)",
              substr_count($md, '<li') === $items && $mdLists === $lists, substr_count($md, '<li') . "/$items items, $mdLists/$lists lists");
        check("$label/$page: … and so has the page the template prints",
              substr_count($html, '<li') === $items && $htmlLists === $lists, substr_count($html, '<li') . "/$items items, $htmlLists/$lists lists");
        check("$label/$page: no empty item, no marker, no untranslated key",
              !preg_match('/<li[^>]*>\s*<\/li>/', $md . $html) && !str_contains($md . $html, '[[')
              && !preg_match('/\b(?:info|tos)\.[a-z0-9_]+\b/', strip_tags($html)));
    }
}
// The bug 1.73.0 fixed, on its own: a hidden item on a line of its own used to leave a blank line, and a
// blank line ends a Markdown list — the rest of the Terms came out as a second list numbered from 1.
$split = pageContentResolveMarkers("1. a\n[[if:bogus]]\n2. b\n[[/if]]\n3. c\n", $openCfg, null);
check('a hidden item takes its line break with it — the list stays one list', $split === "1. a\n3. c\n", json_encode($split));
check('… a shown one keeps its line', pageContentResolveMarkers("1. a\n[[ifnot:bogus]]\n2. b\n[[/if]]\n3. c\n", $openCfg, null) === "1. a\n2. b\n3. c\n");
check('… a marker inside a sentence still only removes itself',
      pageContentResolveMarkers("A[[if:bogus]] B[[/if]] C.\nD", $openCfg, null) === "A C.\nD");
check('… and a shown block with nothing left in it goes like a hidden one',
      pageContentResolveMarkers("x\n[[ifnot:bogus]]\n[[if:bogus]]\ny\n[[/if]]\n[[/if]]\nz", $openCfg, null) === "x\nz");
// Numbers that are settings: [[value:name]] (pageContentValues()), read when the page is shown.
// (email_change_days: its helper, userEmailChangeCooldownDays(), is in includes/users.php, which this test loads.)
check('a value marker reads the setting when the page is shown — a number of days with its noun',
      ($v12 = pageContentResolveMarkers('wait [[value:email_change_days]]', ['users_email_change_cooldown_days' => '12'] + $openCfg, null)) === 'wait 12 days', $v12);
check('… and the noun agrees with the number: one day is "1 day"',
      ($v1 = pageContentResolveMarkers('wait [[value:email_change_days]]', ['users_email_change_cooldown_days' => '1'] + $openCfg, null)) === 'wait 1 day', $v1);
// Polish has two forms where the texts use them (accusative: 1 dzień; 2, 5, 22 … dni) — "przez 1 dni" was
// what a sentence with the noun written after the number said the day an operator chose 1.
check('… in Polish: 1 dzień, 2 dni, 5 dni, 22 dni; in English 1 day, 30 days',
      pageContentDays(1, 'pl') === '1 dzień' && pageContentDays(2, 'pl') === '2 dni' && pageContentDays(5, 'pl') === '5 dni'
      && pageContentDays(22, 'pl') === '22 dni' && pageContentDays(1, 'en') === '1 day' && pageContentDays(30, 'en') === '30 days',
      implode('|', [pageContentDays(1, 'pl'), pageContentDays(2, 'pl'), pageContentDays(1, 'en'), pageContentDays(30, 'en')]));
check('… a count that is not days stays a bare number',
      pageContentResolveMarkers('[[value:shout_keep_rows]]', $openCfg, null) === (string)pageContentValues($openCfg, null)['shout_keep_rows'][0]);
// No sentence of the shipped pages writes a noun after a value that already carries one ("3 days days").
$dayVals = array_keys(array_filter(pageContentValues($wlCfg, null), fn($v) => ($v[2] ?? '') === 'days'));
$doubled = [];
foreach (['tos', 'info'] as $page) {
    $walkV = function ($x) use (&$walkV, &$doubled, $dayVals): void {
        if (!is_array($x)) return;
        if (isset($x[0]) && in_array($x[0], ['h1', 'h2', 'h3', 'p', 'ol', 'ul', 'faq', 'group'], true)) {
            foreach (pageContentItems($x) as $i) $walkV($i);
            return;
        }
        foreach (($x['vals'] ?? []) as $param => $name) {
            if (!in_array($name, $dayVals, true)) continue;
            foreach (['en', 'pl'] as $l) {
                $s = langFor($l, (string)$x[0]);
                if (preg_match('/:' . preg_quote((string)$param, '/') . '\s+(?:days?|dni|dnia|dniach|dzień)\b/u', $s)) $doubled[] = "$l:{$x[0]}";
            }
        }
    };
    foreach (pageContentSpec($page) as $b) $walkV($b);
}
check('no shipped sentence writes "days"/"dni" after a value that carries its noun', !$doubled, implode(',', $doubled));
check('… an unknown value is removed and reported',
      pageContentResolveMarkers('a [[value:bogus]] b', $openCfg, null, $unkV) === 'a  b' && $unkV === ['value:bogus']);
// The whitelist hours in the reader's language (pageContentScheduleText()), without parentheses of their
// own — the texts hold them in parentheses already. scheduleDescribe() stays English for the panel.
require_once $root . '/includes/schedule.php';
$schedCfg = ['tracker_schedule_enabled' => '1', 'tracker_schedule_tz' => 'Europe/Warsaw', 'tracker_mode' => 'whitelist',
             'tracker_schedule' => json_encode(['mon' => ['from' => '10:00', 'to' => '02:30'], 'tue' => ['from' => '10:00', 'to' => '02:30'],
                                                'wed' => ['from' => '10:00', 'to' => '02:30'], 'thu' => ['from' => '10:00', 'to' => '02:30'],
                                                'fri' => ['from' => '10:00', 'to' => '02:30'], 'sat' => 'all', 'sun' => 'all'])];
check('the whitelist hours in English, grouped as the panel groups them',
      ($sEn = pageContentScheduleText($schedCfg)) === 'Mon–Fri 10:00–02:30 the next day, Sat–Sun all day, Europe/Warsaw time', $sEn);
check('… and the value marker carries the same words', pageContentResolveMarkers('[[value:schedule_hours]]', $schedCfg, null) === $sEn);
check('… with no parenthesis of their own', !str_contains($sEn, '(') && !str_contains($sEn, ')'));
check('… while the panel\'s scheduleDescribe() is untouched',
      scheduleDescribe($schedCfg) === 'Mon–Fri 10:00–02:30 (next day), Sat–Sun all day (Europe/Warsaw)', scheduleDescribe($schedCfg));
check('… and in Polish the same week is Polish words',
      ($sPl = pageContentScheduleText($schedCfg, 'pl')) === 'pon–pt 10:00–02:30 następnego dnia, sob–niedz cały dzień, czas Europe/Warsaw', $sPl);
$sameDayCfg = ['tracker_schedule' => json_encode(['mon' => ['from' => '08:00', 'to' => '18:00'], 'tue' => 'none',
               'wed' => ['from' => '22:00', 'to' => '00:00'], 'thu' => 'none', 'fri' => 'none', 'sat' => 'none', 'sun' => 'none'])] + $schedCfg;
check('… single days, a same-day window and one past midnight',
      ($sOne = pageContentScheduleText($sameDayCfg)) === 'Mon 08:00–18:00, Wed 22:00–00:00 the next day, Europe/Warsaw time', $sOne);
check('… a week with no whitelist hours says so',
      str_starts_with(pageContentScheduleText(['tracker_schedule' => json_encode(array_fill_keys(SCHEDULE_DAYS, 'none'))] + $schedCfg), 'none'));
// Every condition, value and dictionary key the two specs name exists — an unknown condition is false,
// so a misspelt one is a paragraph that silently never appears.
foreach (['tos', 'info'] as $page) {
    $names = pageContentSpecNames($page);
    $condsAll = pageContentConditions($wlCfg, null);
    $valsAll = pageContentValues($wlCfg, null);
    $badC = array_values(array_diff($names['conds'], array_keys($condsAll)));
    $badV = array_values(array_diff($names['vals'], array_keys($valsAll)));
    $badK = array_values(array_filter($names['keys'], fn($k) => !langHas($k) || !langFor('pl', $k) || langFor('pl', $k) === $k));
    check("$page: every condition the spec names exists", !$badC, implode(',', $badC));
    check("$page: every value the spec names exists", !$badV, implode(',', $badV));
    check("$page: every key the spec names exists in English and Polish", !$badK, implode(',', $badK));
}

$infoWl = $resolved($wlCfg, 'info');
$infoOpen = $resolved($openCfg, 'info');
check('the info page gains its whitelist section in whitelist mode',
      str_contains($infoWl, '## Whitelist mode') && !str_contains($infoOpen, '## Whitelist mode'));
check('the FAQ answer changes with the mode',
      str_contains($infoWl, 'In whitelist mode a registration can also simply be removed')
      && !str_contains($infoOpen, 'In whitelist mode a registration')
      && str_contains($infoOpen, 'A hash can be banned'));

// ── both formats, and the difference between them stated honestly ───────────
$md = pageContentDefault($wlCfg, 'tos', 'markdown', '', 'en');
$bb = pageContentDefault($wlCfg, 'tos', 'bbcode', '', 'en');
check('markdown uses real headings', str_starts_with($md, '# Terms of Service'));
check('bbcode has no heading tag, so it uses a large bold line instead',
      str_starts_with($bb, '[size=24][b]Terms of Service[/b][/size]'), substr($bb, 0, 60));
check('markdown numbers its list', str_contains($md, '1. The service is free'));
check('bbcode uses [list=1]', str_contains($bb, '[list=1]') && str_contains($bb, '[*] The service is free'));
check('an unknown format is treated as markdown rather than producing nothing',
      pageContentDefault($wlCfg, 'tos', 'nonsense', '', 'en') === $md);

// The default text is the SAME wording the templates render — one source, no second English copy
// living in the generator. That is what makes "Restore" hand back what a visitor would have read.
$plMd = pageContentDefault($wlCfg, 'tos', 'markdown', '', 'pl');
check('the default is generated in the language asked for', str_starts_with($plMd, '# Regulamin'), substr($plMd, 0, 40));
check('… and is not the English one', $plMd !== $md);
check('the generator has no English text of its own left in it',
      !str_contains((string)file_get_contents($root . '/includes/pagecontent.php'),
                    'The service is free for personal use'));
// The dictionary strings carry HTML; the generator has to turn it into the requested markup.
check('HTML in a string becomes markdown', str_contains($md, '**Connecting to the tracker'), '');
check('… and bbcode', str_contains($bb, '[b]Connecting to the tracker'), '');
check('no HTML tag survives into the page source', !preg_match('/<(strong|em|a |code)/', $md . $bb));

// ── both defaults survive the renderer, and produce a real page ─────────────
// 1.73.0: the validator a page is saved through (pageContentValidate()) is richtextValidate() without
// its length — a description's 4 000 characters would refuse the shipped Terms (7 000) and Info (19 000)
// the moment the operator restored and saved them. The Info default is checked too: it is the long one.
$mdInfo = pageContentDefault($wlCfg, 'info', 'markdown', '', 'en');
check('the Info default passes the page validator too', pageContentValidate($mdInfo, 'markdown', $cfg) === null,
      (string)pageContentValidate($mdInfo, 'markdown', $cfg));
check('… and is longer than a description may be — the case this validator exists for', mb_strlen($mdInfo) > 4000);
check('a page still keeps the image limit author text has',
      pageContentValidate(str_repeat("[img]https://example.com/a.png[/img]\n", 200), 'bbcode', $cfg) !== null);
foreach (['markdown' => $md, 'bbcode' => $bb] as $fmt => $body) {
    check("$fmt: the default passes the validator a page is saved through",
          pageContentValidate($body, $fmt, $cfg) === null, (string)pageContentValidate($body, $fmt, $cfg));
    $html = richtextRender($body, $fmt, $cfg, true);
    check("$fmt: it renders to something with the terms in it",
          str_contains($html, 'Commercial organizations require written permission'));
    check("$fmt: and with a list", substr_count($html, '<li') >= 9, (string)substr_count($html, '<li'));
}
$mdHtml = richtextRender($md, 'markdown', $cfg, true);
check('markdown produces a heading element', (bool)preg_match('/<h[1-6]/', $mdHtml));
// The renderer drops a relative link as plain words, so the default links to the site's own pages
// through `site_url` (1.73.0) — before, "Restore built-in" put back a page whose own links were text.
$absCfg = ['site_url' => 'https://tracker.example.org'] + $wlCfg;
$absMd = pageContentDefault($absCfg, 'tos', 'markdown', '/', 'en');
check('the default links to the site\'s own pages with absolute addresses',
      str_contains($absMd, '](https://tracker.example.org/?action=info)'), substr($absMd, 0, 200));
check('… which the renderer keeps as links',
      str_contains(richtextRender(pageContentResolveMarkers($absMd, $absCfg), 'markdown', $absCfg, true), 'href="https://tracker.example.org/?action=info"'));

// ── storing: a draft is not a live page ─────────────────────────────────────
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");
check('nothing stored means nothing active', pageContentActive($db, 'tos', $cfg, 'en') === null);

$r = pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine\n\nJust this.", false, 'test');
check('a draft saves', empty($r['error']), json_encode($r));
check('… and a draft is NOT what visitors get', pageContentActive($db, 'tos', $cfg, 'en') === null);
check('… but the panel can see it', (pageContentGet($db, 'tos', 'en')['body'] ?? '') === "# Mine\n\nJust this.");

$r = pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine\n\nJust this.", true, 'test');
check('publishing makes it active', empty($r['error']) && pageContentActive($db, 'tos', $cfg, 'en') !== null);
check('… and the active row carries its format', (pageContentActive($db, 'tos', $cfg, 'en')['format'] ?? '') === 'markdown');

// ── one version per language, and the order a visitor falls back through ────
//
// The rule this pins down: once ANYTHING is written, a visitor never drops back to the built-in
// boilerplate. Terms somebody actually wrote must not be silently replaced by the shipped text
// because one translation is missing.
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");
pageContentSave($db, $cfg, 'tos', 'pl', 'markdown', "# Moj regulamin", true, 'test');
check('a visitor in that language gets that version', (pageContentActive($db, 'tos', $cfg, 'pl')['lang'] ?? '') === 'pl');
check('a visitor in ANOTHER language gets it too rather than the built-in page',
      (pageContentActive($db, 'tos', $cfg, 'en')['lang'] ?? '') === 'pl');
pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# My terms", true, 'test');
check('… until that language has its own', (pageContentActive($db, 'tos', $cfg, 'en')['lang'] ?? '') === 'en');
check('… and the first one is untouched', (pageContentActive($db, 'tos', $cfg, 'pl')['lang'] ?? '') === 'pl');
check('the site default outranks English in the chain',
      pageContentLangChain(['default_language' => 'pl'], 'de') === ['de', 'pl', 'en'],
      implode(',', pageContentLangChain(['default_language' => 'pl'], 'de')));
check('"auto" is not a language and never enters the chain',
      !in_array('auto', pageContentLangChain(['default_language' => 'auto'], 'pl'), true));
// Restore is per language: it must not take an afternoon's work in another one with it.
pageContentReset($db, 'tos', 'pl');
check('restoring one language leaves the others alone',
      pageContentGet($db, 'tos', 'pl') === null && pageContentGet($db, 'tos', 'en') !== null);
check('… and that language now falls back to the one that is left',
      (pageContentActive($db, 'tos', $cfg, 'pl')['lang'] ?? '') === 'en');
check('a language that is not installed cannot be saved for',
      isset(pageContentSave($db, $cfg, 'tos', 'zz', 'markdown', 'x', true, 'test')['error']));
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");
pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine\n\nJust this.", true, 'test');

// ── the guards ──────────────────────────────────────────────────────────────
check('an empty page cannot be published',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "   ", true, 'test')['error']));
check('an empty page CAN be saved as a draft',
      empty(pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "", false, 'test')['error']));
check('a page that is not in the catalogue is refused',
      isset(pageContentSave($db, $cfg, 'home', 'en', 'markdown', 'x', true, 'test')['error']));
check('an unknown format is refused rather than guessed at',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'html', 'x', true, 'test')['error']));
check('something longer than the cap is refused',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'markdown', str_repeat('a', PAGECONTENT_MAX + 1), true, 'test')['error']));
// The same validator as every other author-written text: a page is not a reason to skip it.
$bad = str_repeat("[img]https://example.com/a.png[/img]\n", 200);
check('text the renderer would refuse elsewhere is refused here too',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'bbcode', $bad, true, 'test')['error']),
      json_encode(pageContentSave($db, $cfg, 'tos', 'en', 'bbcode', $bad, true, 'test')));

// ── restoring is a delete, so the template comes back by itself ─────────────
pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine", true, 'test');
check('restore removes the row', pageContentReset($db, 'tos', 'en') && pageContentGet($db, 'tos', 'en') === null);
check('… and nothing is active afterwards', pageContentActive($db, 'tos', $cfg, 'en') === null);
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");

// ── registration ────────────────────────────────────────────────────────────
$schema = (string)file_get_contents($root . '/includes/schema.php');
check('the table is created', str_contains($schema, 'CREATE TABLE IF NOT EXISTS `page_content`'));
check('the table is keyed by page AND language', str_contains($schema, 'PRIMARY KEY (`page`, `lang`)'));
check('… and an older install is migrated to it',
      str_contains($schema, "schemaColumnExists(\$db, 'page_content', 'lang')"));
check('the schema version was bumped for it',
      (bool)preg_match('/TRACKER_SCHEMA_VERSION = (\d+)/', $schema, $m) && (int)$m[1] >= 41, $m[1] ?? '?');
$api = (string)file_get_contents($root . '/api.php');
check('the endpoint is routed', str_contains($api, "'admin/page_content'"));
check('changing a public page is owner-only (no permission entry)',
      !preg_match("/'admin\\/page_content'\\s*=>\\s*'panel\\./", $api));
$audit = (string)file_get_contents($root . '/includes/audit.php');
check('edits are audited', str_contains($audit, "'admin/page_content'          => 'page.edit'"));
// Matched loosely on purpose: the group keeps gaining members (page.layout, language.manage),
// and a test that pins the whole literal fails for every addition rather than for a regression.
check('… under the settings group',
      (bool)preg_match("/'settings' => \[[^\]]*'page\.edit'[^\]]*\]/", $audit));
$idx = (string)file_get_contents($root . '/index.php');
check('the router consults the override', str_contains($idx, 'pageContentActive($db, $action, $cfg)'));
check('… and only for the pages in the catalogue', str_contains($idx, 'in_array($action, PAGECONTENT_PAGES, true)'));
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('the settings section exists', str_contains($tpl, 'id="section-pages"'));
check('the editor modal exists', str_contains($tpl, 'id="pageEditModal"'));
check('the language rail exists', str_contains($tpl, 'id="pc-langs"'));
// THE BUG THIS CATCHES: apiCall() prepends `api.php?endpoint=`, so a second parameter joins
// with & — a `?` makes the whole string the endpoint name and every call 404s. It shipped.
$js = (string)file_get_contents($root . '/assets/js/admin-pagecontent.js');
check('the editor reaches its endpoint (& not ?, or the endpoint name is wrong)',
      str_contains($js, "admin/page_content&page=") && !str_contains($js, "admin/page_content?"));
check('no browser confirm() is used — the panel has its own dialog',
      !str_contains($js, 'window.confirm'));
// The bug this catches: admin-common.js is loaded AFTER admin-settings.js on this page, so an
// editor script placed with the others returns early and the dialog never opens.
check('the editor script is loaded after admin-common.js, which it needs',
      strpos($tpl, 'admin-pagecontent.js') > strpos($tpl, 'admin-common.js'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
