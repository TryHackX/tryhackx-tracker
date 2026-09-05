<?php
/**
 * Tests for the home page layout:
 *   php tests/homelayout_test.php
 *
 * The properties that matter are about what happens when the stored layout and the code disagree.
 * A layout is data that outlives the version that wrote it, so the interesting cases are all
 * upgrades: a section added since it was saved, one removed, one listed twice, a hidden section
 * that no longer exists. In every one of those the page still has to render every section it has,
 * exactly once, and never fewer.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/lang.php';
require_once $root . '/includes/homelayout.php';
// The built-in headings are translation KEYS, so this needs a language loaded to render them.
// English, because that is what the assertions below are written in.
langInit(['default_language' => 'en']);

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
/** A $cfg carrying nothing but a stored layout. */
function cfgOf(array $stored): array { return ['home_layout' => json_encode($stored)]; }

$keys = homeSectionKeys();

// ── the shipped state ───────────────────────────────────────────────────────
check('the catalogue has the seven sections the page is built from', count($keys) === 7, implode(',', $keys));
check('no stored layout means the shipped order', homeLayoutOrder([]) === $keys);
check('… and nothing hidden', homeLayout([])['hidden'] === []);
check('… and it reports itself as the built-in layout', homeLayoutIsDefault([]));
check('unparseable JSON falls back to the shipped order rather than an empty page',
      homeLayoutOrder(['home_layout' => '{not json']) === $keys);
check('a layout that is valid JSON but the wrong shape does too',
      homeLayoutOrder(['home_layout' => '"a string"']) === $keys);
foreach (homeSectionCatalog() as $k => $m) {
    check("$k: the catalogue says what it is", ($m['about'] ?? '') !== '' && ($m['label'] ?? '') !== '');
}

// ── the headings ────────────────────────────────────────────────────────────
// The bug this pins down: the built-in wording used to be literal English, so a Polish visitor got
// "About the Tracker" over a Polish paragraph. It is a translation key now — "built-in" means
// "whatever this language calls it".
check('a heading defaults to the wording that ships', homeHeading([], 'about') === 'About the Tracker');
check('the tagline defaults to the wording that ships',
      homeTagline([]) === 'Public BitTorrent tracker powered by OpenTracker');
check('the catalogue stores heading KEYS, not English', homeSectionCatalog()['about']['heading'] === 'home.about_head');
check('every heading key resolves to something other than itself',
      count(array_filter(homeSectionCatalog(), fn($m) => $m['heading'] !== null && __($m['heading']) === $m['heading'])) === 0);
$c = cfgOf(['order' => $keys, 'headings' => ['about' => 'O trackerze'], 'tagline' => 'Trzymamy roje']);
check('a stored heading is used', homeHeading($c, 'about') === 'O trackerze');
check('a stored tagline is used', homeTagline($c) === 'Trzymamy roje');
check('a section with no stored heading keeps the built-in one', homeHeading($c, 'contact') === 'Contact');
check('sections that have no heading at all report an empty one',
      homeHeading([], 'header') === '' && homeHeading([], 'stats') === '');

// ── repair: the stored layout is from an older version ──────────────────────
$older = array_values(array_diff($keys, ['features', 'contact']));
$c = cfgOf(['order' => $older]);
$got = homeLayoutOrder($c);
check('a section the stored order never heard of is still rendered',
      count($got) === count($keys) && !array_diff($keys, $got), implode(',', $got));
check('… and it lands where the catalogue puts it, not at the end',
      array_search('features', $got, true) < array_search('contact', $got, true)
      && array_search('contact', $got, true) === count($got) - 1, implode(',', $got));

$c = cfgOf(['order' => array_merge($keys, ['ghost', 'phantom'])]);
check('keys that are not sections are dropped', homeLayoutOrder($c) === $keys);

$c = cfgOf(['order' => ['about', 'about', 'about']]);
$got = homeLayoutOrder($c);
check('a key listed three times appears once', count($got) === count($keys) && count(array_unique($got)) === count($got),
      implode(',', $got));
check('… and everything else is still there', !array_diff($keys, $got));

$c = cfgOf(['order' => $keys, 'hidden' => ['ghost']]);
check('hiding something that is not a section hides nothing', homeLayout($c)['hidden'] === []);

// ── the fixed section ───────────────────────────────────────────────────────
$c = cfgOf(['order' => $keys, 'hidden' => ['header']]);
check('a stored layout cannot hide the page title however it got that way',
      !homeSectionHidden($c, 'header'), json_encode(homeLayout($c)['hidden']));
$r = homeLayoutValidate($keys, ['header'], [], '');
check('… and saving one is refused with a reason', isset($r['error']), json_encode($r));

// ── validation ──────────────────────────────────────────────────────────────
$r = homeLayoutValidate($keys, [], [], '');
check('the shipped order validates', isset($r['ok']), json_encode($r));
check('… and stores as the built-in layout', homeLayoutIsDefault(['home_layout' => $r['json']]), $r['json']);

check('an order missing a section is refused',
      isset(homeLayoutValidate(array_slice($keys, 1), [], [], '')['error']));
check('an order with a duplicate is refused',
      isset(homeLayoutValidate(array_merge(array_slice($keys, 1), ['about']), [], [], '')['error']));
check('an order with an unknown key is refused',
      isset(homeLayoutValidate(array_merge(array_slice($keys, 1), ['ghost']), [], [], '')['error']));
check('a heading longer than the cap is refused',
      isset(homeLayoutValidate($keys, [], ['about' => str_repeat('x', HOME_HEADING_MAX + 1)], '')['error']));
check('a tagline longer than the cap is refused',
      isset(homeLayoutValidate($keys, [], [], str_repeat('x', HOME_HEADING_MAX + 1))['error']));

// A heading typed back to the shipped wording is not a custom heading — otherwise the card outside
// would claim "1 heading renamed" for a heading that says exactly what it always said.
$r = homeLayoutValidate($keys, [], ['about' => 'About the Tracker'], 'Public BitTorrent tracker powered by OpenTracker');
check('re-typing the built-in wording stores nothing', homeLayoutIsDefault(['home_layout' => $r['json']]), $r['json']);
$r = homeLayoutValidate($keys, [], ['about' => '   '], '   ');
check('a blank heading means "use the built-in one"', homeLayoutIsDefault(['home_layout' => $r['json']]), $r['json']);
$r = homeLayoutValidate($keys, [], ['about' => "Two   \n  words"], '');
check('whitespace in a heading is collapsed',
      json_decode($r['json'], true)['headings']['about'] === 'Two words', $r['json']);
$r = homeLayoutValidate($keys, [], ['header' => 'nope', 'stats' => 'nope'], '');
check('a section with no heading cannot be given one',
      json_decode($r['json'], true)['headings'] === [], $r['json']);

// ── round trip ──────────────────────────────────────────────────────────────
$order = ['header', 'announce', 'stats', 'features', 'about', 'contact', 'donations'];
$r = homeLayoutValidate($order, ['donations'], ['features' => 'What you get'], 'A tracker');
check('a rearranged layout validates', isset($r['ok']), json_encode($r));
$c = ['home_layout' => $r['json']];
check('… and reads back in the order it was saved', homeLayoutOrder($c) === $order, implode(',', homeLayoutOrder($c)));
check('… with the hidden section hidden', homeSectionHidden($c, 'donations') && !homeSectionHidden($c, 'about'));
check('… the renamed heading renamed', homeHeading($c, 'features') === 'What you get');
check('… the others untouched', homeHeading($c, 'about') === 'About the Tracker');
// An operator's own wording is NOT translated: they said what it should say.
check('a saved heading is shown verbatim whatever the language',
      homeHeading(['home_layout' => json_encode(['order' => $keys, 'headings' => ['about' => 'Mine']])], 'about') === 'Mine');
check('… the tagline changed', homeTagline($c) === 'A tracker');
check('… and it no longer claims to be the built-in layout', !homeLayoutIsDefault($c));

// ── the template renders from the layout, and nothing else does ─────────────
// 1.34.0: the bodies moved to includes/homeblocks.php (a function, so the editor's preview can build
// the same bodies to paste into a custom section); the template only assembles.
$tpl = (string)file_get_contents($root . '/templates/pages/home.php');
$blk = (string)file_get_contents($root . '/includes/homeblocks.php');
check('the template emits its sections through the layout', str_contains($tpl, 'homeLayoutOrder($cfg)'));
check('… skips the hidden ones', str_contains($tpl, 'homeSectionHidden($cfg, $homeKey)'));
check('… takes the bodies from homeBlocks()', str_contains($tpl, 'homeBlocks($db, $cfg, $baseUrl)'));
check("… and consults the operator's own text per section", str_contains($tpl, "pageContentActive(\$db, 'home:' . \$homeKey, \$cfg)"));
foreach ($keys as $k) {
    check("$k: homeblocks captures a buffer for it", str_contains($blk, "\$homeBlocks['$k'] = ob_get_clean();"));
}
check('every buffer that is opened is closed (plus the outer one that swallows whitespace)',
      substr_count($blk, 'ob_start()') === substr_count($blk, 'ob_get_clean()') + 1,
      substr_count($blk, 'ob_start()') . ' vs ' . substr_count($blk, 'ob_get_clean()'));
check('no heading is hard-coded inside a body any more — the assembly draws them',
      !preg_match('/<h2>.*homeHeading/', $blk));
// The bug this catches: a heading left as literal markup silently ignores the operator's wording.
foreach (['Announce URL', 'About the Tracker', 'Features', 'Support the Project', 'Contact'] as $h) {
    check("the \"$h\" heading is not hard-coded any more", !str_contains($tpl, '<h2>' . $h . '</h2>'));
}
check('the tagline is not hard-coded any more',
      !str_contains($tpl, '<p>Public BitTorrent tracker powered by OpenTracker</p>'));

// ── custom sections (1.34.0) ────────────────────────────────────────────────
$c = cfgOf(['order' => $keys, 'custom' => [['key' => 'custom_2', 'label' => 'How to connect']]]);
check('a custom section is part of the order', in_array('custom_2', homeLayoutOrder($c), true));
$ord = homeLayoutOrder($c);
check('… lands last when the order forgot it', end($ord) === 'custom_2');
check('… its label is its default heading', homeHeading($c, 'custom_2') === 'How to connect');
check('… and it is recognised as custom', homeSectionIsCustom('custom_2') && !homeSectionIsCustom('about'));
check('a custom section with a bad key is dropped', homeLayout(cfgOf(['custom' => [['key' => 'evil; drop', 'label' => 'x']]]))['custom'] === []);
$r = homeLayoutValidate(array_merge($keys, ['custom_1']), [], [], '', [['key' => 'custom_1', 'label' => 'Mine']]);
check('a layout with a custom section validates', isset($r['ok']), json_encode($r));
check('… and stores it', (json_decode($r['json'], true)['custom'][0]['label'] ?? '') === 'Mine');
check('… and is no longer the built-in layout', !homeLayoutIsDefault(['home_layout' => $r['json']]));
check('an order that forgets a custom section is refused',
      isset(homeLayoutValidate($keys, [], [], '', [['key' => 'custom_1', 'label' => 'Mine']])['error']));
check('a custom section without a label is refused',
      isset(homeLayoutValidate(array_merge($keys, ['custom_1']), [], [], '', [['key' => 'custom_1', 'label' => '  ']])['error']));
check('more than the cap is refused',
      isset(homeLayoutValidate(array_merge($keys, array_map(fn($i) => "custom_$i", range(1, 7))), [], [], '',
            array_map(fn($i) => ['key' => "custom_$i", 'label' => "s$i"], range(1, 7)))['error']));

// ── registration ────────────────────────────────────────────────────────────
$api = (string)file_get_contents($root . '/api.php');
check('the endpoint is routed', str_contains($api, "'admin/home_layout'"));
check('rearranging the public page is owner-only (no permission entry)',
      !preg_match("/'admin\\/home_layout'\\s*=>\\s*'panel\\./", $api));
$audit = (string)file_get_contents($root . '/includes/audit.php');
check('changes are audited', str_contains($audit, "'admin/home_layout'           => 'page.layout'"));
check('… under the settings group', str_contains($audit, "'page.layout'"));
$idx = (string)file_get_contents($root . '/index.php');
check('the model is loaded for the public page', str_contains($idx, "require_once __DIR__ . '/includes/homelayout.php';"));
// home_layout is written by its own endpoint. If it were also in the settings allow-list, an
// unrelated settings save that posted no value for it would blank the layout.
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('home_layout is NOT in the settings allow-list, so a settings save cannot blank it',
      !str_contains($save, "'home_layout'"));
$tplA = (string)file_get_contents($root . '/templates/admin/settings.php');
check('the settings section exists', str_contains($tplA, 'id="section-home-layout"'));
check('the arranger modal exists', str_contains($tplA, 'id="homeLayoutModal"'));
check('the card is indexed by the settings search', str_contains($tplA, 'data-setting="home_layout"'));
check('the script is loaded after admin-common.js, which it needs',
      strpos($tplA, 'admin-homelayout.js') > strpos($tplA, 'admin-common.js'));
$cat = (string)file_get_contents($root . '/includes/settings_catalog.php');
check('the search knows the words for it', str_contains($cat, "'home_layout'"));
$js = (string)file_get_contents($root . '/assets/js/admin-homelayout.js');
// Native HTML5 drag fires nothing on touch and cannot be driven from a keyboard.
check('reordering is possible without dragging', str_contains($js, 'hl-up') && str_contains($js, 'hl-down'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
