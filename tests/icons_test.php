<?php
/**
 * The icon library (1.68.0):
 *   php tests/icons_test.php
 *
 * The owner can choose which library draws the site's icons — Bootstrap Icons, as always, or Font
 * Awesome — and the whole site follows: public pages and panel. Every icon stays written ONE way, in
 * Bootstrap's markup, and Font Awesome is laid over it through one name map (includes/icons.php).
 *
 * What can go wrong is quiet, which is why it is pinned here rather than trusted:
 *   * a `bi-*` name the map does not know is an icon that draws NOTHING once Font Awesome is chosen,
 *     and nobody notices until a visitor does;
 *   * a template that prints its own font link bypasses the choice;
 *   * with Bootstrap chosen the page must be exactly what the templates wrote — no filter, no script,
 *     not one byte added;
 *   * an emoji or a Unicode symbol standing in for an icon draws the same whichever library is chosen,
 *     which is the thing the owner asked never to see again.
 * The browser half — that every icon on every page actually renders, in both modes — is
 * scratchpad/shots/icons_check.js.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/settings_catalog.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
/** Every file under $dir (recursively) with one of the extensions, as root-relative paths. */
function iconsTestFiles(string $root, string $dir, array $ext): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), $ext, true)) continue;
        $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    }
    sort($out);
    return $out;
}

// ── 1. the setting, in its four places ──────────────────────────────────────
$defs = trackerSchemaDefaultSettings();
check('icon_library ships as bootstrap', ($defs['icon_library'] ?? null) === 'bootstrap', var_export($defs['icon_library'] ?? null, true));
check('the schema is at 73 or later', TRACKER_SCHEMA_VERSION >= 73, (string)TRACKER_SCHEMA_VERSION);
check('ICON_LIBRARIES is exactly the two choices', ICON_LIBRARIES === ['bootstrap', 'fontawesome']);
check('iconLibrary(): the word fontawesome switches',
      iconLibrary(['icon_library' => 'fontawesome']) === 'fontawesome' && iconLibrary(['icon_library' => 'bootstrap']) === 'bootstrap');
// A row can come from a restored backup or a MySQL client. Nothing but the exact word switches.
check('iconLibrary(): a missing row, a wrong case or junk all mean bootstrap',
      iconLibrary([]) === 'bootstrap' && iconLibrary(['icon_library' => 'FontAwesome']) === 'bootstrap'
      && iconLibrary(['icon_library' => 'fa']) === 'bootstrap' && iconLibrary(['icon_library' => '']) === 'bootstrap');
$saveSrc = (string)@file_get_contents($root . '/api/admin/save_settings.php');
check('it is in the save allow-list', str_contains($saveSrc, "'icon_library',"));
check('… and enum-validated there, coerced to bootstrap like csp_mode',
      (bool)preg_match("/isset\(\\\$data\['icon_library'\]\) && !in_array\(\\\$data\['icon_library'\], ICON_LIBRARIES, true\)\) \{\s*\\\$data\['icon_library'\] = 'bootstrap';/", $saveSrc));
check('it has search words in the catalogue', !empty(settingsCatalogKeywords()['icon_library']));
$setTpl = (string)@file_get_contents($root . '/templates/admin/settings.php');
$sitePos = strpos($setTpl, 'id="section-site"');
$nextPos = $sitePos === false ? false : strpos($setTpl, 'class="settings-section"', $sitePos + 20);
$siteSec = ($sitePos !== false && $nextPos !== false) ? substr($setTpl, $sitePos, $nextPos - $sitePos) : '';
check('it has a control in Settings → Site (#section-site)',
      str_contains($siteSec, 'name="icon_library"') && str_contains($siteSec, 'value="bootstrap"') && str_contains($siteSec, 'value="fontawesome"'));
check('… labelled and explained from the dictionary',
      str_contains($siteSec, "_h('settings.icon_library')") && str_contains($siteSec, "__('settings.icon_library_hint')"));
foreach (['en', 'pl'] as $lc) {
    $d = include $root . '/lang/' . $lc . '.php';
    $hint = (string)($d['settings.icon_library_hint'] ?? '');
    check("the $lc hint says the choice is the whole site, public pages and panel",
          $hint !== '' && ($lc === 'en' ? (str_contains($hint, 'public pages') && str_contains($hint, 'panel'))
                                        : (str_contains($hint, 'publicznych') && str_contains($hint, 'panelu'))), $hint);
}
// 1.69.0: WHICH Font Awesome — the four settings, each in its four places, beside the library choice.
check('the schema is at 76 or later', TRACKER_SCHEMA_VERSION >= 76, (string)TRACKER_SCHEMA_VERSION);
check('fa_source / fa_pack / fa_pack_styles / fa_style ship as 1.68 drew: cdn6, none, [], solid',
      ($defs['fa_source'] ?? null) === 'cdn6' && ($defs['fa_pack'] ?? null) === '' && ($defs['fa_pack_styles'] ?? null) === '[]' && ($defs['fa_style'] ?? null) === 'solid');
check('ICON_FA_SOURCES is exactly the three sources, and anything else reads as cdn6', ICON_FA_SOURCES === ['cdn6', 'cdn7', 'pack']
      && iconFaSource([]) === 'cdn6' && iconFaSource(['fa_source' => 'CDN7']) === 'cdn6' && iconFaSource(['fa_source' => 'cdn7']) === 'cdn7');
check('… all four in the save allow-list, checked there against what is installed', str_contains($saveSrc, "'fa_source', 'fa_pack', 'fa_pack_styles', 'fa_style',")
      && str_contains($saveSrc, '$faCheck = iconSettingsNormalise($data, $cfg);'));
$kwAll = settingsCatalogKeywords();
check('… all four with search words', !empty($kwAll['fa_source']) && !empty($kwAll['fa_pack']) && !empty($kwAll['fa_pack_styles']) && !empty($kwAll['fa_style']));
check('… and all four controls in Settings → Site, under the library, with their hints',
      str_contains($siteSec, 'name="fa_source"') && str_contains($siteSec, 'value="cdn6"') && str_contains($siteSec, 'value="cdn7"') && str_contains($siteSec, 'value="pack"')
      && str_contains($siteSec, 'name="fa_pack"') && str_contains($siteSec, 'name="fa_style"') && str_contains($siteSec, 'name="fa_pack_styles"')
      && strpos($siteSec, 'name="icon_library"') < strpos($siteSec, 'name="fa_source"') && str_contains($siteSec, "__('settings.fa_source_hint')")
      && str_contains($siteSec, 'id="admin-iconpacks"'));

// ── 2. one head helper, on every page ───────────────────────────────────────
check('bootstrap: the tag is exactly the one the templates carried until 1.68.0',
      iconFontTag(['icon_library' => 'bootstrap']) === '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">',
      iconFontTag(['icon_library' => 'bootstrap']));
check('no settings at all (the installer) is Bootstrap too', iconFontTag([]) === iconFontTag(['icon_library' => 'bootstrap']));
$faTag = iconFontTag(['icon_library' => 'fontawesome']);
check('fontawesome: Font Awesome Free 6.7.2, the CSS webfont build, from jsDelivr',
      str_contains($faTag, 'href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css"'), $faTag);
check('… with Subresource Integrity and crossorigin',
      (bool)preg_match('#all\.min\.css" rel="stylesheet" integrity="sha(256|384|512)-[A-Za-z0-9+/]{43,}={0,2}" crossorigin="anonymous"#', $faTag), $faTag);
// The JS/SVG build would need jsDelivr in the PUBLIC script-src, which is kept out on purpose.
check('… and never the JS/SVG build', !preg_match('#fontawesome-free@[^"]*/js/#', $faTag) && !str_contains($faTag, '<script src="https://'));
check('the public policy still does not let jsDelivr run scripts', !str_contains(
      (string)preg_replace('/.*script-src([^;]*);.*/', '$1', cspPolicy(['csp_mode' => 'enforce'], 'public')), 'cdn.jsdelivr.net'));
check('fontawesome with no source chosen is Free 6.7.2, exactly the three lines 1.68 printed',
      iconFontTag(['icon_library' => 'fontawesome', 'fa_source' => 'cdn6', 'fa_style' => 'solid']) === $faTag && substr_count($faTag, '<link ') === 1);
$fa7Tag = iconFontTag(['icon_library' => 'fontawesome', 'fa_source' => 'cdn7']);
check('cdn7: Font Awesome Free 7.3.1 from jsDelivr, with jsDelivr\'s SHA-256, never the JS build',
      str_contains($fa7Tag, 'href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css" rel="stylesheet" integrity="sha256-4Lad8m4ZWW1Lgb9+sMVLYEfnIh7BjV1NQMEe79Pviks=" crossorigin="anonymous"')
      && !preg_match('#fontawesome-free@[^"]*/js/#', $fa7Tag) && str_contains($fa7Tag, 'id="icon-map"'), $fa7Tag);
$goneTag = iconFontTag(['icon_library' => 'fontawesome', 'fa_source' => 'pack', 'fa_pack' => 'fa-pro-9.9.9-deadbeef']);
check('a package that is not installed: the head loads Free 6.7.2 instead of nothing', str_contains($goneTag, 'fontawesome-free@6.7.2/css/all.min.css') && !str_contains($goneTag, 'iconpack.php'));
check('the content preview gets the same stylesheets: one jsDelivr sheet with its SRI, or a package\'s several without',
      iconFontCss(['icon_library' => 'fontawesome', 'fa_source' => 'cdn7']) === ['href' => ICON_FONTAWESOME7_CSS, 'integrity' => ICON_FONTAWESOME7_SRI]
      && iconFontCss(['icon_library' => 'bootstrap', 'fa_source' => 'cdn7']) === ['href' => ICON_BOOTSTRAP_CSS, 'integrity' => ICON_BOOTSTRAP_SRI]
      && str_contains((string)file_get_contents($root . '/assets/js/admin-pagecontent.js'), "split(/\\s+/)"));
$heads = ['templates/layout.php', 'install.php'];
foreach (glob($root . '/templates/admin/*.php') as $f) {
    if (str_contains((string)file_get_contents($f), '</html>')) $heads[] = 'templates/admin/' . basename($f);
}
check('there are nine templates with a <head> plus the installer', count($heads) === 10, implode(', ', $heads));
$noHelper = [];
foreach ($heads as $h) {
    $src = (string)file_get_contents($root . '/' . $h);
    // The LAST <head>: install.php dies early with a one-line "already installed" document of its own,
    // which draws no icon and is not the page anybody installs from.
    $hs = preg_match_all('/^[ \t]*<head>[ \t]*$/m', $src, $hm, PREG_OFFSET_CAPTURE) ? (int)end($hm[0])[1] : 0;
    $head = substr($src, $hs, (int)strpos($src, '</head>', $hs) - $hs);
    $call = $h === 'install.php' ? '<?= iconFontTag([]) ?>' : '<?= iconFontTag($cfg) ?>';
    if (!str_contains($head, $call)) $noHelper[] = $h;
}
check('every one of them prints its font through iconFontTag(), inside <head>', $noHelper === [], implode(', ', $noHelper));
$layout = (string)file_get_contents($root . '/templates/layout.php');
check('the public layout loads it on EVERY page — no condition around it any more',
      !str_contains($layout, 'iconsNeeded') && !preg_match('/<\?php if \([^)]*\): \?>\s*<\?= iconFontTag/', $layout));
// The helper is the only place a font <link> is printed: no template, include or script names either
// stylesheet itself, so nothing can load a library the owner did not choose.
$printers = [];
foreach (array_merge(iconsTestFiles($root, 'templates', ['php']), iconsTestFiles($root, 'includes', ['php']),
                     iconsTestFiles($root, 'assets/js', ['js']), iconsTestFiles($root, 'api', ['php']),
                     ['index.php', 'install.php', 'api.php']) as $rel) {
    if ($rel === 'includes/icons.php') continue;
    $src = (string)@file_get_contents($root . '/' . $rel);
    if (preg_match('#bootstrap-icons@|fontawesome-free@|font-awesome@|bootstrap-icons\.(min\.)?css#i', $src)) $printers[] = $rel;
}
check('the helper is the only place an icon font is named', $printers === [], implode(', ', $printers));

// ── 3. the map ───────────────────────────────────────────────────────────────
$map = iconFaMap();
check('the map has entries', count($map) > 100, (string)count($map));
$badVal = [];
foreach ($map as $k => $v) {
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string)$k)
        || !preg_match('/^fa-(solid|regular|brands) fa-[a-z0-9]+(-[a-z0-9]+)*$/', (string)$v)) $badVal[] = "$k => $v";
}
check('every entry is one style class and one icon class, Free styles only', $badVal === [], implode(' | ', $badVal));
// Brands only for brands: a brand glyph in fa-solid draws nothing, and vice versa.
check('the one brand (YouTube) is in fa-brands, and nothing else is', ($map['youtube'] ?? '') === 'fa-brands fa-youtube'
      && count(array_filter($map, fn($v) => str_starts_with($v, 'fa-brands'))) === 1);
// The pairs whose two halves MEAN something must stay two different glyphs: an empty star beside a
// filled one is a favourite or not; a flag, reported or not.
foreach ([['star', 'star-fill'], ['flag', 'flag-fill'], ['check-circle', 'check-circle-fill'], ['circle', 'circle-fill'],
          ['square', 'check-square'], ['hand-thumbs-up', 'hand-thumbs-down'], ['eye', 'eye-slash'], ['lock', 'unlock'],
          ['arrow-up', 'arrow-down'], ['chevron-left', 'chevron-right'], ['volume-up', 'volume-mute']] as [$a, $b]) {
    check("$a and $b stay two different glyphs", isset($map[$a], $map[$b]) && $map[$a] !== $map[$b], ($map[$a] ?? '?') . ' / ' . ($map[$b] ?? '?'));
}
$json = json_decode(iconFaMapJson(), true);
check('the JSON the browser gets is the same map', $json === $map);
check('… and cannot close the <script> it rides in', !preg_match('#[<>&\']#', iconFaMapJson()));
// 1.69.0: the map is NAMES and ROLES (iconFaEntries()), resolved per setup; iconFaMap() without one is
// the 1.68 map above, byte for byte, and every setup's map has the same keys.
$entries = iconFaEntries();
$badE = [];
$nameRe = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
foreach ($entries as $bi => $e) {
    if (!preg_match($nameRe, (string)($e[0] ?? '')) || !in_array($e[1] ?? '', ['s', 'r', 'f', 'b'], true)) $badE[] = "$bi: shape";
    foreach (['v7', 'pro', 'pro6', 'pro7'] as $k) if (isset($e[$k]) && !preg_match($nameRe, (string)$e[$k])) $badE[] = "$bi: $k";
    if (isset($e['prorole']) && !in_array($e['prorole'], ['s', 'r', 'f'], true)) $badE[] = "$bi: prorole";
    foreach (array_keys($e) as $k) if (!in_array($k, [0, 1, 'approx', 'v7', 'pro', 'pro6', 'pro7', 'prorole', 'proapprox'], true)) $badE[] = "$bi: key $k";
    // A Pro choice marked as still approximate is a Pro choice of an approximation (1.69.0).
    if (!empty($e['proapprox']) && (empty($e['approx']) || !isset($e['pro']))) $badE[] = "$bi: proapprox without approx and pro";
    // A -fill half is FILLED whichever style is chosen, or a pair stops being a pair.
    if (str_ends_with((string)$bi, '-fill') && $e[1] !== 'f') $badE[] = "$bi: a -fill that is not role f";
}
check('every entry is a Free name and a role (s follows the style, r outline, f filled, b brand), twins and version names well formed', $badE === [], implode('; ', $badE));
// 27 in 1.68.x; 1.69.0's picker took away the one entry only the old picker used (its balloon tab).
check('the map has the same names as the entries, and 26 approximations are marked',
      array_keys($map) === array_keys($entries) && count(array_filter($entries, fn($e) => !empty($e['approx']))) === 26);
// With Pro (1.69.0): the eight that stay approximate even with Pro were searched for in the owner's Pro 6
// and 7 indexes (labels and search terms) and drawn beside Bootstrap's glyph. Two have a closer Pro glyph
// — the All settings chip's four boxes without the tick, the home layout's tiles mirrored — drawn and
// still counted as approximations; the other six have none, and their reasons say so. Four exact entries
// have Pro's own twin of Bootstrap's drawing where the site uses them.
$eight = ['bootstrap-reboot', 'database-down', 'database-gear', 'envelope-paper', 'grid-1x2', 'hdd-network', 'send-check', 'ui-checks-grid'];
check('the eight approximations Pro has no twin for are still approximations, two of them with a closer Pro glyph marked as such',
      !array_filter($eight, fn($b) => empty($entries[$b]['approx']))
      && ($entries['ui-checks-grid']['pro'] ?? '') === 'grid-2' && !empty($entries['ui-checks-grid']['proapprox'])
      && ($entries['grid-1x2']['pro'] ?? '') === 'rectangles-mixed' && !empty($entries['grid-1x2']['proapprox'])
      && !array_filter(['bootstrap-reboot', 'database-down', 'database-gear', 'hdd-network', 'send-check'], fn($b) => isset($entries[$b]['pro'])));
check('… and the Pro twins added where Pro has Bootstrap\'s very drawing: the megaphone, the two speech bubbles, the date range',
      ($entries['megaphone']['pro'] ?? '') === 'megaphone' && ($entries['chat-left-text']['pro'] ?? '') === 'message-lines'
      && ($entries['chat-left-dots']['pro'] ?? '') === 'message-dots' && ($entries['calendar-range']['pro'] ?? '') === 'calendar-range'
      && !array_filter(['megaphone', 'chat-left-text', 'chat-left-dots', 'calendar-range'], fn($b) => !empty($entries[$b]['approx'])));
// Each approximation says why, in a `~` comment on its line or in the comment lines just above it.
$iconsLines = preg_split('/\R/', (string)file_get_contents($root . '/includes/icons.php'));
$unexplained = [];
$approxSeen = 0;
foreach ($iconsLines as $i => $line) {
    if (!preg_match("/^\s*'([a-z0-9-]+)'\s*=>\s*\[/", $line, $lm) || !str_contains($line, "'approx' => 1")) continue;
    $approxSeen++;
    $said = str_contains($line, '// ~');
    for ($j = $i - 1; !$said && $j >= 0 && preg_match('#^\s*//#', $iconsLines[$j]); $j--) if (str_contains($iconsLines[$j], '// ~')) $said = true;
    if (!$said) $unexplained[] = $lm[1];
}
check('… each marked approximation says, in the source, why its glyph is the closest honest one', $approxSeen === 26 && $unexplained === [], implode(', ', $unexplained));
$setups = [
    'cdn7 solid'   => ['icon_library' => 'fontawesome', 'fa_source' => 'cdn7', 'fa_style' => 'solid'],
    'cdn7 regular' => ['icon_library' => 'fontawesome', 'fa_source' => 'cdn7', 'fa_style' => 'regular'],
    'cdn6 regular' => ['icon_library' => 'fontawesome', 'fa_source' => 'cdn6', 'fa_style' => 'regular'],
];
foreach ($setups as $label => $c) {
    $mx = iconFaMap(iconSetup($c));
    $pairsOk = true;
    foreach ([['star', 'star-fill'], ['flag', 'flag-fill'], ['check-circle', 'check-circle-fill'], ['hand-thumbs-up', 'hand-thumbs-up-fill']] as [$a, $b]) {
        if ($mx[$a] === $mx[$b]) $pairsOk = false;
    }
    check("$label: every name mapped, Free styles only, one brand, and the pairs still two glyphs",
          array_keys($mx) === array_keys($map) && !array_filter($mx, fn($v) => !preg_match('/^fa-(solid|regular|brands) fa-[a-z0-9-]+$/', $v))
          && $mx['youtube'] === 'fa-brands fa-youtube' && $pairsOk);
}
check('cdn7 with solid chosen draws what cdn6 does (every name the map uses is the same in Free 7.3.1)',
      iconFaMap(iconSetup(['icon_library' => 'fontawesome', 'fa_source' => 'cdn7'])) === $map);
check('regular chosen on a Free build: the forty names Free has in regular, and nothing else, turn regular',
      count(array_filter(iconFaMap(iconSetup($setups['cdn7 regular'])), fn($v) => str_starts_with($v, 'fa-regular')))
      === count(array_filter($entries, fn($e) => $e[1] !== 'f' && $e[1] !== 'b' && in_array($e[0], iconFreeRegular(), true))));

// Every bi-* name the site uses has an entry. Collected the way the browser will meet them: from the
// templates, the includes (the settings catalogue's group icons, the panel's navigation), every script
// and the dictionary sources — anything that ends up in a class attribute.
$used = [];
$scan = array_merge(iconsTestFiles($root, 'templates', ['php']), iconsTestFiles($root, 'includes', ['php']),
                    iconsTestFiles($root, 'assets/js', ['js']), iconsTestFiles($root, 'tools/lang_src.d', ['py']),
                    iconsTestFiles($root, 'api', ['php']), ['index.php', 'api.php', 'install.php']);
foreach ($scan as $rel) {
    if ($rel === 'includes/icons.php') continue;
    $src = (string)@file_get_contents($root . '/' . $rel);
    if (preg_match_all('/(?<![A-Za-z0-9_-])bi-([a-z0-9]+(?:-[a-z0-9]+)*)/', $src, $m)) {
        foreach ($m[1] as $name) $used[$name][$rel] = true;
    }
}
check('the scan found the icons (sanity: more than a hundred names)', count($used) > 100, (string)count($used));
$missing = [];
foreach ($used as $name => $where) if (!isset($map[$name])) $missing[] = $name . ' (' . implode(', ', array_keys($where)) . ')';
check('every bi-* name used anywhere has a Font Awesome entry', $missing === [], implode('; ', $missing));
$unused = array_diff(array_keys($map), array_keys($used));
check('and the map carries no name nothing uses', $unused === [], implode(', ', $unused));

// ── 4. the page: rewritten with Font Awesome, untouched with Bootstrap ──────
// A real page's worth of markup to push through the machinery: every template, concatenated. It holds
// every shape an icon is written in on the server, plus the <i> that is a progress bar.
$sample = '';
foreach (iconsTestFiles($root, 'templates', ['php']) as $rel) $sample .= (string)file_get_contents($root . '/' . $rel);
$sample .= '<span class="lang-cov"><i style="width:40%"></i></span>';
check('the sample carries icons to rewrite', substr_count($sample, 'class="bi bi-') > 200, (string)substr_count($sample, 'class="bi bi-'));

// BOOTSTRAP: nothing is installed, so nothing can change a byte. Asked the way index.php asks.
$lvl = ob_get_level(); $handlers = ob_list_handlers();
$installed = iconOutputFilterStart(['icon_library' => 'bootstrap']);
check('bootstrap: iconOutputFilterStart() installs nothing', $installed === false && ob_get_level() === $lvl && ob_list_handlers() === $handlers,
      var_export($installed, true) . ' / ' . ob_get_level() . ' vs ' . $lvl);
ob_start();
iconOutputFilterStart(['icon_library' => 'bootstrap']);
echo $sample;
$outBi = (string)ob_get_clean();
check('bootstrap: a page goes out byte for byte as written', $outBi === $sample && ob_get_level() === $lvl);
foreach ([[], ['icon_library' => ''], ['icon_library' => 'FontAwesome'], ['icon_library' => 'bogus']] as $cfgX) {
    $l0 = ob_get_level();
    $got = iconOutputFilterStart($cfgX);
    if ($got) ob_end_clean();
    check('… and so does anything that is not exactly "fontawesome": ' . json_encode($cfgX), $got === false && ob_get_level() === $l0);
}
// index.php installs the filter through this one call and buffers the page in no other way, so the
// helper's answer IS the page's answer.
$indexSrc = (string)file_get_contents($root . '/index.php');
check('index.php starts the filter once, through the helper, and has no other output buffer',
      substr_count($indexSrc, 'iconOutputFilterStart($cfg);') === 1 && !preg_match('/\bob_start\s*\(/', $indexSrc));
$posFilter = strpos($indexSrc, 'iconOutputFilterStart($cfg);');
check('… after the JSON and redirect answers, before any template is included',
      $posFilter > strpos($indexSrc, "if (\$action === 'health')") && $posFilter > strpos($indexSrc, 'authBridgeHandleRoute(')
      && $posFilter < strpos($indexSrc, "include __DIR__ . '/templates/admin/settings.php'")
      && $posFilter < strpos($indexSrc, "include __DIR__ . '/templates/layout.php'"));

// FONT AWESOME: the same page, with the mapped classes added and the Bootstrap ones kept.
$lvl = ob_get_level();
ob_start();
$installed = iconOutputFilterStart(['icon_library' => 'fontawesome']);
echo $sample;
ob_end_flush();
$outFa = (string)ob_get_clean();
check('fontawesome: the filter is installed', $installed === true && ob_get_level() === $lvl);
check('… it adds the mapped classes and keeps bi + bi-NAME',
      (bool)preg_match('#<i class="bi bi-trash fa-regular fa-trash-can" aria-hidden="true"( data-label)?></i>#', $outFa)
      && str_contains($outFa, 'class="bi bi-x-lg fa-solid fa-xmark"') && !str_contains($outFa, 'class="bi bi-trash"'));
preg_match_all('/class="bi bi-([a-z0-9]+(?:-[a-z0-9]+)*)((?: [^"<>]*)?)"/', $outFa, $mm, PREG_SET_ORDER);
$notMapped = [];
foreach ($mm as $one) {
    $want = explode(' ', $map[$one[1]] ?? 'MISSING');
    if (array_diff($want, preg_split('/\s+/', trim($one[2])) ?: [])) $notMapped[] = $one[0];
}
check('… to every icon attribute in the page', $mm && $notMapped === [], implode(' | ', array_slice($notMapped, 0, 5)));
// Take the additions away again and the Bootstrap page is what is left, exactly: the filter adds and
// never alters, moves or drops anything. Since 1.69.0 it also marks an icon that has words beside it
// (data-label: Font Awesome's fixed box is for an icon-only button, and :only-child cannot see text).
$stripped = (string)preg_replace(['/ fa-(?:solid|regular|brands) fa-[a-z0-9]+(?:-[a-z0-9]+)*(?=")/', '/ data-label(?=><\/i>)/'], '', $outFa);
check('… and adds ONLY that: strip the added classes and marks and the Bootstrap page is left, byte for byte', $stripped === $sample);
$lm = ['x-lg' => 'fa-solid fa-xmark', 'chevron-right' => 'fa-solid fa-chevron-right'];
check('an icon with words beside it is marked data-label, before or after them, and nothing else is',
      iconFilterHtml('<button><i class="bi bi-x-lg"></i> Close</button>', $lm) === '<button><i class="bi bi-x-lg fa-solid fa-xmark" data-label></i> Close</button>'
      && iconFilterHtml('<a class="btn">Next <i class="bi bi-chevron-right" aria-hidden="true"></i></a>', $lm) === '<a class="btn">Next <i class="bi bi-chevron-right fa-solid fa-chevron-right" aria-hidden="true" data-label></i></a>'
      && iconFilterHtml("<button title=\"Close\">\n  <i class=\"bi bi-x-lg\"></i>\n</button>", $lm) === "<button title=\"Close\">\n  <i class=\"bi bi-x-lg fa-solid fa-xmark\"></i>\n</button>"
      && iconFilterHtml('<button><i class="bi bi-x-lg"></i> <span>Close</span></button>', $lm) === '<button><i class="bi bi-x-lg fa-solid fa-xmark"></i> <span>Close</span></button>');
check('… and marking twice changes nothing', iconFilterHtml(iconFilterHtml('<button><i class="bi bi-x-lg"></i> Close</button>', $lm), $lm)
      === '<button><i class="bi bi-x-lg fa-solid fa-xmark" data-label></i> Close</button>');
$ojs2 = (string)file_get_contents($root . '/assets/js/icons.js');
check('… and the observer marks what scripts build the same way, and follows text that comes or goes',
      str_contains($ojs2, "el.setAttribute('data-label', '')") && str_contains($ojs2, 'characterData: true')
      && str_contains($ojs2, "if (rec.type === 'characterData') {") && str_contains($ojs2, 'relabel(rec.target);'));
// Font Awesome's glyphs whose drawing is off their em's middle, or leaves room at a side, are measured in
// the face they are drawn in, once it has loaded — any version, family or style — and the icon carries
// its own middle, its row the room on the words' side (Bootstrap's pinned face has them in the CSS).
check('… and measures each glyph beside words in its own face: its middle on the icon, its room on the row',
      str_contains($ojs2, "el.style.setProperty('--bi-mid'") && str_contains($ojs2, "p.style.setProperty('--bi-sb'")
      && str_contains($ojs2, 'document.fonts.check(font, ch)') && str_contains($ojs2, "addEventListener('loadingdone'"));
check('… the progress bar in the Languages table is not an icon and is left alone',
      str_contains($outFa, '<span class="lang-cov"><i style="width:40%"></i></span>'));
check('… a second pass changes nothing (idempotent)', iconFilterHtml($outFa) === $outFa);
check('… a name the map does not know is left alone', iconFilterHtml('<i class="bi bi-no-such-icon"></i>') === '<i class="bi bi-no-such-icon"></i>');
// The CLI has no response to declare a type on, so the callback is handed the headers it would see.
$notHtml = '{"x":"<i class="bi bi-x-lg"></i>"}';
check('… a response that declared another type passes through untouched',
      iconOutputFilter($notHtml, 0, ['Content-Type: application/json']) === $notHtml
      && iconOutputFilter($notHtml, 0, ['Content-Type: text/plain; charset=UTF-8']) === $notHtml);
check('… while an HTML one, declared or not, is rewritten',
      iconOutputFilter($notHtml, 0, ['Content-Type: text/html; charset=UTF-8']) !== $notHtml
      && iconOutputFilter($notHtml, 0, ['X-Frame-Options: DENY']) === iconFilterHtml($notHtml));
// Every icon on the server side is written so the filter can see it: an attribute that holds a
// `bi-NAME` BEGINS with `bi bi-` (the observer in the browser would catch another order, but the page
// would flash an empty box first).
$odd = [];
foreach (array_merge(iconsTestFiles($root, 'templates', ['php']), iconsTestFiles($root, 'includes', ['php']),
                     iconsTestFiles($root, 'api', ['php'])) as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    if (preg_match_all('/class="([^"]*(?<![A-Za-z0-9_-])bi-[a-z0-9][^"]*)"/', $src, $cm)) {
        foreach ($cm[1] as $cls) {
            if (!str_starts_with($cls, 'bi bi-') && !str_starts_with($cls, 'bi <?=')) $odd[] = $rel . ': ' . $cls;
        }
    }
}
check('every server-written icon attribute begins `bi bi-`', $odd === [], implode(' | ', array_slice($odd, 0, 6)));

// ── 5. the browser half ─────────────────────────────────────────────────────
$js = (string)file_get_contents($root . '/assets/js/icons.js');
check('the observer reads the map the page carries (#icon-map), not a copy of its own', str_contains($js, "getElementById('icon-map')")
      && !preg_match("/'x-lg'\s*:/", $js));
check('… keys on the bi- CLASS, never on the <i> element', str_contains($js, '[class*="bi-"]') && !preg_match("/querySelectorAll\('i[\.\[' ]/", $js));
check('… watches added nodes AND class changes', str_contains($js, 'childList: true') && str_contains($js, "attributeFilter: ['class']"));
check('… and is printed only with Font Awesome chosen', !str_contains(iconFontTag(['icon_library' => 'bootstrap']), 'icons.js')
      && str_contains($faTag, 'assets/js/icons.js') && str_contains($faTag, 'id="icon-map"'));
check('the map rides in the head BEFORE the observer that reads it', strpos($faTag, 'id="icon-map"') < strpos($faTag, 'icons.js'));
foreach (['assets/css/style.css', 'assets/css/admin.css'] as $css) {
    $c = (string)file_get_contents($root . '/' . $css);
    $p = strpos($c, '/* === Icons, in either library');
    // 1.69.0: Font Awesome's glyph box at its own baseline, where its em already starts 0.125em below —
    // 1.68.0 lowered it 0.125em more, and every Font Awesome icon hung under its Bootstrap twin.
    check("$css: the icon block exists; Font Awesome's glyph box stands on its own baseline (both layers since 1.69.0)",
          $p !== false && str_contains($c, '.bi[class*=" fa-"]::before, .bi[class*=" fa-"]::after { display: inline-block; vertical-align: 0; line-height: 1; }'));
    // Near the top: a later rule of equal weight that sizes an icon must still win, as it does over
    // Bootstrap's own stylesheet.
    $firstBi = preg_match('/(^|[\s,}])\.bi[ ,{:.\[]/', (string)preg_replace('#/\*.*?\*/#s', '', substr($c, 0, (int)$p))) === 1;
    check("$css: … placed before every other rule that styles an icon", $p !== false && !$firstBi);
    // The placement (1.69.0, part D2): the glyph painted a WHOLE number of pixels from the text's
    // baseline — a fractional offset came out a pixel one way or the other with where the control sat —
    // chosen to put its middle nearest the cap middle of the text beside it, with `cap` read on the
    // icon's parent (a sort arrow is smaller than its header) and Bootstrap's own box kept to 1/64px,
    // the unit a box is laid out in, so box and move add up to exactly a whole pixel.
    check("$css: … the glyph placed on the text's cap middle, a whole pixel from its baseline, the cap read on the parent",
          str_contains($c, '@property --bi-cap { syntax: "<length>"; inherits: true; initial-value: 0px; }')
          && str_contains($c, ':where(:has(> .bi)) { --bi-cap: 1cap; }')
          && str_contains($c, '@supports (top: round(1cap, 1px)) {')
          && str_contains($c, '.bi { --bi-va: round(-0.125em, 0.015625px); position: relative; top: calc(round(var(--bi-mid, 0.5em) - var(--bi-cap) / 2, 1px) + var(--bi-va)); }')
          && str_contains($c, '.bi::before, .bi::after { vertical-align: var(--bi-va); }'));
    $btn = ":is(button, .btn, [role=\"button\"]) > .bi[class*=\" fa-\"]:only-child:not([data-label])::before,\n:is(button, .btn, [role=\"button\"]) > .bi[class*=\" fa-\"]:only-child:not([data-label])::after { width: 1.25em;";
    // …and only for an icon with no words beside it: `:only-child` counts elements, so it boxed every
    // icon before bare text too, and the space to the word changed with the glyph (1.69.0).
    check("$css: … fixed width for icon-only buttons — not for an icon beside words (data-label)", str_contains($c, $btn));
    // 1.68.1: that box takes 1em of the line, the width every Bootstrap glyph takes, so a button is the
    // same size in either library — the whole 1.25em made the Whitelist's actions cell clip its delete —
    // and the <i> keeps the text's family, whose line box Font Awesome's own family made a pixel taller.
    check("$css: … taking 1em of the line, as Bootstrap's glyphs do", str_contains($c, $btn . ' margin-inline: -0.125em;'));
    // …and no fixed width on the element: Font Awesome 7's `width: var(--fa-width, 1.25em)` is inert on an
    // inline <i> and not on an item of a button's row, where every glyph stood in a 1.25em box (1.69.0).
    check("$css: … and the icon element keeps the text's font family, with Font Awesome's middle and box for the placement, and no fixed width",
          str_contains($c, '.bi[class*=" fa-"] { --fa-display: inline; line-height: inherit; font-family: inherit; --bi-mid: 0.375em; --bi-va: 0px; width: auto; }'));
    // One layout for an icon and its words in a control (1.69.0): a baseline-aligned row with one gap,
    // so neither a space, a margin nor a <span> in the markup decides the spacing — and not an icon-only
    // button (with Font Awesome an only child without data-label): a two-layer icon's grid left such a
    // row without its line's height. The row's DISPLAY weighs one class (:is(…):where(…)) and comes
    // before `.d-hidden`, so a hidden control stays hidden: at the layout's weight it showed Backups'
    // "Cancel run" and, on a phone, the pagers' First and Last.
    $onlyIcon = ':has(> .bi[class*=" fa-"]:only-child:not([data-label]))';
    $dispAt = max((int)strpos($c, '.btn:where(:has(> .bi):not(' . $onlyIcon . ')) { display: inline-flex; }'),
                  (int)strpos($c, ':is(.btn, .dropdown-item, .source-tab, .source-tab-link, .settings-group-btn):where(:has(> .bi):not(' . $onlyIcon . ')),'));
    check("$css: … a control holding an icon and words is a row with one gap, the icon's margin gone; an icon-only one is not",
          (bool)preg_match('/:is\(\.btn, [^)]*\):has\(> \.bi\):not\(:where\(:has\(> \.bi\[class\*=" fa-"\]:only-child:not\(\[data-label\]\)\)\)\) \{\s*align-items: baseline; justify-content: center; text-align: start;\s*column-gap: max\(0px, var\(--bi-gap, [0-9.]+em\) - var\(--bi-sb\)\);/', $c)
          && (bool)preg_match('/:is\(\.btn, [^)]*\):has\(> \.bi\) > \.bi \{ margin-inline: 0; \}/', $c)
          && !preg_match('/\.btn i\s*\{\s*margin-right/', $c));
    check("$css: … its display at one class's weight, before .d-hidden, so a hidden control stays hidden",
          $dispAt > 0 && ($dh = strpos($c, "\n.d-hidden {")) !== false && $dispAt < $dh
          && str_contains($c, ' :where(button:has(> .bi):not(' . $onlyIcon . ')) { display: inline-flex; }')
          && !preg_match('/:has\(> \.bi\)[^{]*\{[^}]*display: inline-flex; align-items/', $c));
    // Font Awesome's faces live in one family per family: a site rule that sets a weight on an icon would
    // otherwise pick another face for a solid-only glyph and draw a box. Pinned on the glyph, as
    // Bootstrap Icons pins its own — since 1.69.0 for every version and family, from Font Awesome's own
    // variables (7.x's --_fa-family on the icon, 6.x's --fa-style-family-* on :root), falling back to
    // exactly the two names 1.68 wrote, the classic family after the chosen one, the weight per class.
    check("$css: … the face pinned on the glyph from Font Awesome's own variables, 1.68's families as the fallback",
          str_contains($c, 'font-family: var(--_fa-family, var(--fa-style-family-classic, "Font Awesome 6 Free")), var(--fa-style-family-classic, "Font Awesome 6 Free") !important;')
          && str_contains($c, '.bi.fa-brands::before { font-family: var(--_fa-family, var(--fa-style-family-brands, "Font Awesome 6 Brands")) !important; }')
          && str_contains($c, '.bi.fa-sharp::before { font-family: var(--_fa-family, var(--fa-style-family-sharp, "Font Awesome 6 Sharp"))'));
    check("$css: … every style class's weight, on both layers",
          str_contains($c, '.bi.fa-solid::before, .bi.fa-solid::after { font-weight: 900 !important; }')
          && str_contains($c, '.bi.fa-regular::before, .bi.fa-regular::after, .bi.fa-brands::before { font-weight: 400 !important; }')
          && str_contains($c, '.bi.fa-light::before, .bi.fa-light::after { font-weight: 300 !important; }')
          && str_contains($c, '.bi.fa-thin::before, .bi.fa-thin::after { font-weight: 100 !important; }')
          && str_contains($c, '.bi.fa-semibold::before, .bi.fa-semibold::after { font-weight: 600 !important; }'));
    // …as wide as its glyph: Font Awesome 7's `width: var(--fa-width, 1.25em)` is live on a grid, and made
    // every two-layer icon-only button 0.25em wider than its Bootstrap twin.
    check("$css: … the two layers of a duotone icon in one grid cell as wide as the glyph, and the dot scaled only when it is Free's circle",
          str_contains($c, '.bi:is([class*=" fa-"][class*="-duo"], .fa-thumbprint, .fa-vellum) { display: inline-grid; width: auto;')
          && str_contains($c, '.bi-dot.fa-circle::before { transform: scale(0.375); }') && !str_contains($c, '.bi-dot[class*=" fa-"]::before'));
}
// The filter and the observer apply the setup the page is drawn with: the map the head carries is the
// setup's, and so is the one the output filter was started with.
$l1 = ob_get_level();
ob_start();
iconOutputFilterStart(['icon_library' => 'fontawesome', 'fa_source' => 'cdn7', 'fa_style' => 'regular']);
echo '<i class="bi bi-gear"></i><i class="bi bi-bell"></i><i class="bi bi-calendar-range"></i>';
ob_end_flush();
$out7 = (string)ob_get_clean();
check('the output filter applies the page\'s own setup (Free 7.3.1 regular here: a gear stays solid, a calendar turns regular)',
      $out7 === '<i class="bi bi-gear fa-solid fa-gear"></i><i class="bi bi-bell fa-regular fa-bell"></i><i class="bi bi-calendar-range fa-regular fa-calendar-days"></i>'
      && ob_get_level() === $l1, $out7);
iconActiveMap(iconFaMap());   // back to 1.68's for anything after this line
check('… and the head\'s map is that setup\'s too', str_contains(iconFontTag(['icon_library' => 'fontawesome', 'fa_source' => 'cdn7', 'fa_style' => 'regular']), '"calendar-range":"fa-regular fa-calendar-days"'));
check('iconFilterHtml() takes a map, and without one uses the filter\'s',
      iconFilterHtml('<i class="bi bi-gear"></i>', ['gear' => 'fa-sharp fa-solid fa-gear']) === '<i class="bi bi-gear fa-sharp fa-solid fa-gear"></i>'
      && iconFilterHtml('<i class="bi bi-gear"></i>') === '<i class="bi bi-gear fa-solid fa-gear"></i>');
$ojs = (string)file_get_contents($root . '/assets/js/icons.js');
check('the observer drops every fa- class the map no longer wants, so a class list of three (fa-sharp fa-solid fa-gear) is managed whole',
      str_contains($ojs, "MAP[name].split(' ')") && str_contains($ojs, "cl[i].lastIndexOf('fa-', 0) === 0 && want.indexOf(cl[i]) < 0"));
// The public layout loads the font BEFORE its own stylesheets, as the panel does, so a site rule of
// equal weight that places an icon wins over Font Awesome's rules for the icon element.
$lh = substr($layout, 0, (int)strpos($layout, '</head>'));
check('the public layout prints the icon font before style.css', strpos($lh, 'iconFontTag($cfg)') < strpos($lh, 'assets/css/style.css'));

// ── 6. no emoji or symbol doing an icon's job ───────────────────────────────
// The owner: "never use standard emoji or those standard symbol characters anywhere — only Font
// Awesome or Bootstrap." A character standing in for an icon draws the same whichever library is
// chosen, so it is exactly the thing the choice cannot reach. Read the way the browser meets it — code
// only, comments out — across every template, include, script, stylesheet and dictionary source.
//
// What is CONTENT and stays: the `:shortcode:` emoji map the renderer substitutes, and an audit log's
// one-line summaries. The emoji the picker offers were a list in assets/js/shoutbox.js until 1.69.0 and
// are DATA now (assets/emoji/*.json, generated), so that script is read whole like any other. Prose stays
// too, and is simply not in the forbidden set: an arrow between two values or in "Users → Groups", a
// middle dot between facts, a multiplication sign in "3×" or "600×800", an ellipsis, a dash.
function iconsTestForbidden(int $cp): bool {
    if ($cp >= 0x1F000 && $cp <= 0x1FAFF) return true;                        // emoji and pictographs
    if ($cp >= 0x2600 && $cp <= 0x27BF) return true;                          // ☀ ★ ✓ ✗ ⚑ ⚠ ❤ ✕ …
    if ($cp >= 0x2B00 && $cp <= 0x2BFF) return true;                          // ⭐ …
    if ($cp >= 0x2300 && $cp <= 0x23FF) return true;                          // ⏱ …
    if ($cp >= 0x25A0 && $cp <= 0x25FF) return true;                          // ▶ ▲ ▼ ▸ ▾ ● ○ ▣ ▤ …
    if ($cp >= 0x2190 && $cp <= 0x21FF && $cp !== 0x2190 && $cp !== 0x2192) return true; // ↕ ↑ ⇄ (not the prose → ←)
    if ($cp >= 0x2460 && $cp <= 0x24FF) return true;                          // circled letters: ⓘ
    if ($cp >= 0x2800 && $cp <= 0x28FF) return true;                          // braille: the ⠿ grip
    if (in_array($cp, [0x2022, 0x2039, 0x203A, 0x00AB, 0x00BB, 0x2261, 0xFE0F], true)) return true; // • ‹ › « » ≡, emoji presentation
    return false;
}
$purgeFiles = array_merge(iconsTestFiles($root, 'templates', ['php']), iconsTestFiles($root, 'includes', ['php']),
                          iconsTestFiles($root, 'assets/js', ['js']), iconsTestFiles($root, 'assets/css', ['css']),
                          iconsTestFiles($root, 'tools/lang_src.d', ['py']), iconsTestFiles($root, 'api', ['php']),
                          ['index.php', 'install.php', 'api.php', 'csp-report.php']);
$found = [];
foreach ($purgeFiles as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    // Content, cut out before the scan (the regions are named so a new one has to be argued for).
    if ($rel === 'includes/richtext.php') {                                   // the :shortcode: emoji map
        $src = (string)preg_replace('/function richtextEmoji\(\): array \{.*?\n\}/s', '', $src);
    }
    // Comments out: block comments, HTML comments, and line comments that start a line or follow
    // whitespace (never `//` inside a URL, which follows a colon).
    // (Replaced by their own line breaks, so a line number below is the line in the file.)
    $code = (string)preg_replace_callback(['#/\*.*?\*/#s', '#<!--.*?-->#s'], fn($m) => str_repeat("\n", substr_count($m[0], "\n")), $src);
    $code = (string)preg_replace(str_ends_with($rel, '.py') ? '/(^|\s)#[^\n]*/' : '#(^|[\s;{(,])//[^\n]*#', '$1', $code);
    foreach (explode("\n", $code) as $i => $line) {
        // An audit log line says what happened in one line of text; a symbol there is part of a sentence.
        if (str_contains($line, "'summary' =>")) continue;
        $bad = [];
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (iconsTestForbidden(mb_ord($ch, 'UTF-8'))) $bad[] = $ch;
        }
        if (preg_match_all('/&#(x[0-9a-fA-F]+|[0-9]+);/', $line, $em)) {
            foreach ($em[1] as $v) {
                $cp = $v[0] === 'x' ? hexdec(substr($v, 1)) : (int)$v;
                if (iconsTestForbidden((int)$cp)) $bad[] = '&#' . $v . ';';
            }
        }
        if (preg_match('/&(check|cross|star|starf|hearts|diams|spades|clubs|loz|rarrw|uarr|darr|harr|lsaquo|rsaquo|laquo|raquo);/', $line, $nm)) $bad[] = $nm[0];
        // The multiplication sign as a FACE: alone in a button or a string, not in "3×" or "600×800".
        if (preg_match('/>\s*(&times;|×)\s*</u', $line) || preg_match("/(text|textContent)\s*[:=]\s*'×'\s*[,;})\]]/u", $line)) $bad[] = 'x-as-a-face';
        if ($bad) $found[] = $rel . ':' . ($i + 1) . ' ' . implode(' ', array_unique($bad));
    }
}
check('no emoji and no symbol character stands in for an icon anywhere (content and prose excepted)', $found === [],
      count($found) . ': ' . implode(' | ', array_slice($found, 0, 12)));
// The content region the scan steps around is really there — if it moved, the scan above would have
// read it as code and failed, but say it plainly. The picker's emoji are data now, outside every file
// the scan reads (1.69.0): the two generated files carry them, and the script carries none.
$emojiFiles = [];
foreach (['en', 'pl'] as $lc) {
    $ej = json_decode((string)@file_get_contents($root . '/assets/emoji/emoji-' . $lc . '.json'), true);
    $emojiFiles[$lc] = is_array($ej) ? count((array)($ej['e'] ?? [])) : 0;
}
check('… and the content it steps around is still content: the :shortcode: map; the picker\'s emoji are data (assets/emoji/), not code',
      str_contains((string)file_get_contents($root . '/includes/richtext.php'), 'function richtextEmoji(): array {')
      && !str_contains((string)file_get_contents($root . '/assets/js/shoutbox.js'), 'var EMOJI = [')
      && $emojiFiles['en'] > 1800 && $emojiFiles['en'] === $emojiFiles['pl'], json_encode($emojiFiles));
// The places the owner's list named, each looked at directly as well.
$spot = [
    'templates/nav.php'                     => ['bi bi-volume-mute'],
    'templates/partials/info_overlay.php'   => ['bi bi-x-lg', 'bi bi-palette', 'bi bi-fonts', 'bi bi-highlighter', 'bi bi-subscript', 'bi bi-superscript',
                                                'bi bi-link-45deg', 'bi bi-image', 'bi bi-list-ul', 'bi bi-list-ol', 'bi bi-quote', 'bi bi-code-slash',
                                                'bi bi-table', 'bi bi-eye-slash', 'bi bi-text-center', 'bi bi-dash-lg', 'bi bi-type-bold'],
    'templates/partials/shoutbox_widget.php'=> ['bi bi-type-bold', 'bi bi-code-slash', 'bi bi-link-45deg', 'bi bi-quote', 'bi bi-eye-slash', 'bi bi-emoji-smile'],
    'templates/pages/account.php'           => ['bi bi-x-lg', 'bi bi-play-fill', 'bi bi-palette', 'bi bi-subscript'],
    'templates/pages/whitelist.php'         => ['bi bi-palette', 'bi bi-subscript', 'bi bi-superscript', 'bi bi-eye-slash'],
    'templates/pages/register.php'          => ['bi bi-x-lg'],
    'templates/pages/search.php'            => ['bi bi-x-lg'],
    'includes/richtext.php'                 => ['bi bi-youtube', 'bi bi-check-square', 'bi bi-square', 'disc-chev'],
    // 1.69.0: the picker's tabs are Unicode's nine pages, Recent, and the search box's two controls.
    'assets/js/shoutbox.js'                 => ['bi bi-emoji-smile', 'bi bi-person', 'bi bi-tree', 'bi bi-cup-hot', 'bi bi-car-front', 'bi bi-trophy',
                                                'bi bi-lightbulb', 'bi bi-heart', 'bi bi-flag', 'bi bi-clock-history', 'bi bi-search', 'bi bi-x-lg'],
    'assets/js/favourites.js'               => ['bi bi-star-fill', 'bi bi-star', 'bi bi-x-lg', 'bi bi-plus-lg'],
    'assets/js/people.js'                   => ['bi bi-flag-fill', 'bi bi-flag'],
    'assets/js/app.js'                      => ['bi bi-x-lg pw-req-ic', 'bi bi-check-lg pw-req-ic', 'bi bi-hand-thumbs-up', 'bi bi-hand-thumbs-down',
                                                'bi bi-arrow-down-up search-sort-icon', 'bi bi-star-fill', 'bi bi-chevron-double-left'],
    'assets/js/media-editor.js'             => ['bi bi-x-lg'],
    'assets/js/admin-users.js'              => ['bi bi-hourglass-split', 'bi bi-dot gr-matrix-off', 'bi bi-check-lg pw-req-ic'],
    'assets/js/admin-shout.js'              => ['bi bi-dot gr-matrix-off'],
    'assets/js/admin-index.js'              => ['bi bi-shield-fill', 'bi bi-star-fill'],
    'assets/js/admin-iplists.js'            => ['bi bi-exclamation-triangle'],
    'assets/js/admin-sysctl.js'             => ["'bi bi-check-lg' : 'bi bi-x-lg'"],
    'assets/js/admin-messages.js'           => ['bi bi-chevron-left', 'bi bi-chevron-right'],
    'assets/js/admin-homelayout.js'         => ['bi bi-grip-vertical'],
    'assets/js/admin-languages.js'          => ['bi bi-arrow-right'],
    'assets/js/admin-settings.js'           => ['bi bi-chevron-right settings-where-sep'],
    'templates/admin/settings.php'          => ['bi bi-check-lg', 'bi bi-x-lg', 'bi bi-info-circle', 'bi bi-circle', 'bi bi-dot'],
    'install.php'                           => ['bi bi-check-lg', 'bi bi-x-lg', 'bi bi-circle-fill', 'bi bi-exclamation-triangle', 'bi bi-trash'],
];
$spotMissing = [];
foreach ($spot as $rel => $wants) {
    $src = (string)file_get_contents($root . '/' . $rel);
    foreach ($wants as $w) if (!str_contains($src, $w)) $spotMissing[] = "$rel: $w";
}
check('… and every place on the owner\'s list draws its icon now', $spotMissing === [], implode(' | ', $spotMissing));
$js = include $root . '/lang/en.php';
check('the dictionary: the pager words are words, "Sent" carries no tick, the sound note no emoji',
      $js['js.app.pg_first'] === 'First' && $js['js.app.pg_last'] === 'Last' && $js['js.app.pg_prev'] === 'Prev'
      && $js['js.app.pg_next'] === 'Next' && $js['js.app.verify_sent'] === 'Sent'
      && str_contains($js['account.snd_autoplay'], '<i class="bi bi-volume-mute" aria-hidden="true"></i>'));
// The disclosure markers: a chevron icon in the markup — no ▸/▾ character in `content:` anywhere. An
// open <details> draws the chevron that points down (1.69.0): a TURNED glyph is drawn where its box's
// geometry says, not on the whole pixel text is drawn on, and an open folder's chevron stood 1–2.5px
// below its name. The glyph is the library's own (Bootstrap Icons \f282, Font Awesome \f078, from the
// variables Font Awesome draws both layers from), nothing turns, and the observer measures it again when
// a <details> opens or shuts.
$cssAll = '';
foreach (iconsTestFiles($root, 'assets/css', ['css']) as $rel) $cssAll .= (string)file_get_contents($root . '/' . $rel);
check('no <details> marker is a character in the stylesheet any more; an open one draws the chevron that points down, turning nothing',
      !preg_match('/summary::(before|after)\s*\{[^}]*content:\s*[\'"][^\'"]/u', $cssAll)
      && substr_count($cssAll, 'details[open] > summary > .disc-chev:not([class*=" fa-"])::before { content: "\f282"; }') === 2
      && substr_count($cssAll, 'details[open] > summary > .disc-chev[class*=" fa-"] { --fa: "\f078"; --fa--fa: "\f078\f078"; }') === 2
      && str_contains($cssAll, '.gr-matrix-wrap[open] > summary .gr-matrix-chev:not([class*=" fa-"])::before { content: "\f282"; }')
      && !preg_match('/(disc-chev|gr-matrix-chev)[^{]*\{[^}]*rotate\(/', $cssAll)
      && str_contains($ojs2, "document.addEventListener('toggle', function (e) {"));
check('the star rating is built from icons, half stars kept (clipped box, not a character)',
      !preg_match('/\.star::(before|after)/', $cssAll) && str_contains($cssAll, '.star-half .star-front { width: 50%; }')
      && str_contains((string)file_get_contents($root . '/assets/js/app.js'), "back.appendChild(iconEl('bi bi-star-fill'));"));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
