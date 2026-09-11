<?php
/**
 * Tests for the interface language:
 *   php tests/lang_test.php
 *
 * Two things are worth pinning down here and neither is "does t() return a string".
 *
 * The first is the FALLBACK: a translation is always partial at some point in its life, and the
 * property that makes that survivable is that a missing key degrades to readable English rather
 * than to a blank or to a dotted identifier on a button.
 *
 * The second is that the three visibility lists cannot be emptied. Each is stored as a positive
 * allow-list, and an EMPTY list means "no restriction" — so a bug that empties one does not show as
 * a site with no languages, it shows as a site that quietly offers all of them. Every guard below
 * exists because that failure is invisible.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
// functions.php first: langInit() builds the language cookie with cookieBaseParams(),
// which lives there. functions.php requires lang.php itself, so this is the whole dependency.
require_once $root . '/includes/functions.php';
require_once $root . '/includes/lang.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
/**
 * Re-resolve as a fresh request would.
 *
 * langInit() runs once per process and the three list lookups are cached per request, so a test
 * that changed the config without dropping those caches would be answered from the PREVIOUS
 * config — which is how this file briefly "proved" that automatic matching ignored the account
 * allow-list when in fact it was reading a stale copy of that list.
 */
function relang(array $cfg, ?string $user = null): void {
    $GLOBALS['__lang']['current'] = null;
    langInvalidate();
    langInit($cfg, $user);
}

// ── what ships ──────────────────────────────────────────────────────────────
$avail = langAvailable();
check('both shipped languages are installed', isset($avail['en'], $avail['pl']), implode(',', array_keys($avail)));
check('English is first, because it is what everything falls back to', array_key_first($avail) === 'en');
check('the built-in list names exactly those two', LANG_BUILT_IN === ['en', 'pl']);
check('the fallback is one of them', in_array(LANG_FALLBACK, LANG_BUILT_IN, true));
foreach (LANG_BUILT_IN as $code) {
    check("$code: the file exists", is_file($root . '/lang/' . $code . '.php'));
    check("$code: it returns a non-empty flat map", count(langLoad($code)) > 50, (string)count(langLoad($code)));
}

// ── the two dictionaries hold the same keys ─────────────────────────────────
// Generated from one source for exactly this reason: a key present in one language and missing from
// the other is the normal way a translation rots.
$en = langLoad('en');
$pl = langLoad('pl');
check('English and Polish have the same keys',
      array_keys($en) === array_keys($pl),
      implode(',', array_slice(array_merge(array_diff(array_keys($en), array_keys($pl)),
                                           array_diff(array_keys($pl), array_keys($en))), 0, 6)));
$empty = array_filter($en, fn($v) => trim((string)$v) === '') + array_filter($pl, fn($v) => trim((string)$v) === '');
check('no string is blank in either language', $empty === [], implode(',', array_slice(array_keys($empty), 0, 5)));
$badKey = array_filter(array_keys($en), fn($k) => !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $k));
check('every key is a flat dotted identifier', $badKey === [], implode(',', array_slice($badKey, 0, 5)));
// A placeholder that exists in one language and not the other silently drops a value from a
// sentence — the sentence still reads, which is what makes it easy to miss.
$mismatch = [];
foreach ($en as $k => $v) {
    preg_match_all('/:[a-z_]+/', $v, $a);
    preg_match_all('/:[a-z_]+/', $pl[$k] ?? '', $b);
    sort($a[0]); sort($b[0]);
    if ($a[0] !== $b[0]) $mismatch[] = $k;
}
check('placeholders match between the two languages', $mismatch === [], implode(',', array_slice($mismatch, 0, 5)));

// ── lookup and fallback ─────────────────────────────────────────────────────
relang(['default_language' => 'en']);
check('a key resolves in English', __('nav.home') === 'Home', __('nav.home'));
relang(['default_language' => 'pl']);
check('… and in Polish', __('nav.home') === 'Strona główna', __('nav.home'));
check('a key nobody translated comes back as the key, not as nothing',
      __('no.such.key.at.all') === 'no.such.key.at.all');
check('langHas() tells them apart', langHas('nav.home') && !langHas('no.such.key.at.all'));
check('placeholders are substituted', __('notfound.body', ['site' => 'X']) === 'Ta strona nie istnieje na X.',
      __('notfound.body', ['site' => 'X']));
check('_h() escapes what __() does not',
      _h('notfound.body', ['site' => '<b>']) === 'Ta strona nie istnieje na &lt;b&gt;.',
      _h('notfound.body', ['site' => '<b>']));
check('langFor() reads another language without changing this one',
      langFor('en', 'nav.home') === 'Home' && __('nav.home') === 'Strona główna');
check('langFor() with an unknown language falls back to English',
      langFor('zz', 'nav.home') === 'Home');
check('langAll() carries the English fallback under the active language',
      count(langAll()) >= count($en));

// The fallback that matters: a language file with two strings in it.
$GLOBALS['__lang']['loaded']['xx'] = ['nav.home' => 'Startseite'];
$GLOBALS['__lang']['current'] = 'xx';
$GLOBALS['__lang']['strings'] = $GLOBALS['__lang']['loaded']['xx'];
$GLOBALS['__lang']['fallback'] = $en;
check('a translated key uses the translation', __('nav.home') === 'Startseite');
check('an untranslated key falls back to English rather than to a blank',
      __('nav.info') === 'Info' && __('report.h1') === 'Abuse Report Form', __('report.h1'));

// ── resolution order ────────────────────────────────────────────────────────
unset($_GET['lang'], $_COOKIE['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
relang([]);
check('with nothing configured the site is English', langCurrent() === 'en');
relang(['default_language' => 'pl']);
check('the site default is used', langCurrent() === 'pl');
relang(['default_language' => 'pl'], 'en');
check('a signed-in user outranks the site default', langCurrent() === 'en');
$_COOKIE['lang'] = 'pl';
relang(['default_language' => 'en']);
check('the cookie outranks the site default', langCurrent() === 'pl');
relang(['default_language' => 'en'], 'en');
// Changed in 1.34.0, on purpose: the cookie is an explicit click on the switcher, scoped to the
// browser session, and somebody who just chose a language meant it — whatever their account
// says. The account setting is the default that comes back once the session cookie is gone.
check('… and the cookie (an explicit choice for this session) outranks the account', langCurrent() === 'pl');
$_GET['lang'] = 'pl';
relang(['default_language' => 'en'], 'en');
check('an explicit ?lang= outranks everything', langCurrent() === 'pl');
// The cookie from the previous case has to go first, or "ignored" cannot be told apart from
// "fell back to what the cookie said".
unset($_COOKIE['lang']);
$_GET['lang'] = 'zz';
relang(['default_language' => 'en'], null);
check('?lang= for a language that is not installed is ignored', langCurrent() === 'en');
unset($_GET['lang']);

// ── Accept-Language ─────────────────────────────────────────────────────────
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pl-PL,pl;q=0.9,en;q=0.8';
relang(['default_language' => 'en']);
check('the browser is ignored while automatic matching is off', langCurrent() === 'en');
relang(['default_language' => 'en', 'language_auto' => '1']);
check('… and honoured when it is on — but only after the site default',
      langCurrent() === 'en', langCurrent());
relang(['default_language' => 'auto']);
check('"auto" as the site default hands the choice to the browser', langCurrent() === 'pl');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en;q=0.4,pl;q=0.9';
relang(['default_language' => 'auto']);
check('q weights are honoured, not header order', langCurrent() === 'pl');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pl;q=0';
relang(['default_language' => 'auto']);
check('q=0 means "not this one"', langCurrent() === 'en');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,fr;q=0.9';
relang(['default_language' => 'auto']);
check('a browser asking for nothing we have gets English', langCurrent() === 'en');
// Automatic matching stays inside what the admin offers to accounts — it is a choice made FOR a
// visitor, so it must not reach a language deliberately kept out of that set.
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pl';
relang(['default_language' => 'auto', 'user_languages' => 'en']);
check('automatic matching cannot pick a language kept out of "for accounts"', langCurrent() === 'en');
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

// ── the three lists ─────────────────────────────────────────────────────────
langInvalidate();
check('an empty setting means every installed language', array_keys(langEnabled([])) === array_keys($avail));
langInvalidate();
check('a list narrows what is offered', array_keys(langEnabled(['enabled_languages' => 'en'])) === ['en', 'pl'],
      implode(',', array_keys(langEnabled(['enabled_languages' => 'en']))));
langInvalidate();
// The built-ins are re-added to `enabled` whatever the setting says: they are the end of every
// fallback chain, and a site with neither has nothing to render.
check('the shipped languages are always in the enabled list',
      isset(langEnabled(['enabled_languages' => 'xx'])['en'], langEnabled(['enabled_languages' => 'xx'])['pl']));
langInvalidate();
check('a list of nothing but stale codes falls back to everything, never to nothing',
      langForSwitcher(['switcher_languages' => 'zz,yy']) === langEnabled([]));
langInvalidate();
check('the switcher list can hide a language the site still offers',
      array_keys(langForSwitcher(['switcher_languages' => 'en'])) === ['en']);
langInvalidate();
check('… and the account list is separate from it',
      array_keys(langForUsers(['switcher_languages' => 'en'])) === ['en', 'pl']);
langInvalidate();
check('a language hidden from the switcher is still supported by ?lang=',
      langSupported(['switcher_languages' => 'en'], 'pl'));
langInvalidate();

// ── coverage ────────────────────────────────────────────────────────────────
$cov = langCoverage('pl');
check('a complete translation measures 100 %', $cov['percent'] === 100, json_encode($cov));
check('… with nothing missing', $cov['missing'] === 0);
$cov = langCoverage('en');
check('English measures itself at 100 %', $cov['percent'] === 100);
check('coverage of something that is not installed is 0, not an error', langCoverage('zz')['percent'] === 0);

// ── sanitising contributed values ───────────────────────────────────────────
// The templates print __() unescaped by design, so a language file is the one place a stranger's
// text reaches the page as HTML. The fixture is the JSON a hostile contributor would send: the two
// payloads have to go, and the two honest strings have to survive UNCHANGED — a sanitiser that
// "cleaned" <strong> into text would break every hint that uses it and nobody would notice until
// the language was live.
$hostile = json_decode('{"h.img": "<img src=x onerror=alert(1)>",'
                     . ' "h.js": "<a href=\"javascript:alert(1)\">x</a>",'
                     . ' "h.ok": "<a href=\"https://ok\">x</a>",'
                     . ' "h.strong": "<strong>bold</strong>"}', true);
check('the hostile fixture parsed', is_array($hostile) && count($hostile) === 4);
$kept = []; $dropped = [];
foreach ($hostile as $k => $v) { if (langSanitizeValue($v) === null) $dropped[] = $k; else $kept[$k] = $v; }
check('<img onerror> is dropped', in_array('h.img', $dropped, true));
check('<a href="javascript:…"> is dropped', in_array('h.js', $dropped, true));
check('<a href="https://…"> is kept verbatim', ($kept['h.ok'] ?? null) === $hostile['h.ok']);
check('<strong> is kept verbatim', ($kept['h.strong'] ?? null) === $hostile['h.strong']);
check('… and nothing else was touched', $dropped === ['h.img', 'h.js'], implode(',', $dropped));
// The shapes the rule has to refuse beyond the obvious ones. Each of these is a way the first
// version of a filter like this gets past: a scheme hidden behind entities or a tab (a browser
// decodes the one and strips the other before it reads the scheme), a payload on the CLOSING tag,
// a tag left open, a protocol-relative URL, an event handler on an allowed tag.
foreach ([
    'an event handler on an allowed tag'   => '<strong onclick=alert(1)>x</strong>',
    'a payload on a closing tag'           => '<a href="https://x">x</a onclick=alert(1)>',
    'an entity-encoded javascript: href'   => '<a href="&#106;avascript:alert(1)">x</a>',
    'a tab inside the scheme'              => "<a href=\"java\tscript:alert(1)\">x</a>",
    'a leading space before the scheme'    => '<a href=" javascript:alert(1)">x</a>',
    'a data: href'                         => '<a href="data:text/html,x">x</a>',
    'a protocol-relative href'             => '<a href="//evil.example">x</a>',
    'a backslash where the second slash goes' => '<a href="/\\evil.example">x</a>',
    'a numeric entity with no semicolon'   => '<a href="&#106avascript:alert(1)">x</a>',
    'a hex entity with no semicolon'       => '<a href="j&#x61vascript:alert(1)">x</a>',
    'any attribute but href on an <a>'     => '<a href="https://x" target="_blank">x</a>',
    'an unterminated tag'                  => '<a href="https://x',
    'a comment'                            => '<!-- x -->',
    'a tag not in the list'                => '<u>x</u>',
    '<svg>'                                => '<svg onload=alert(1)>',
    '<script> in any case'                 => '<SCRIPT>1</SCRIPT>',
    'a control character'                  => "x\0y",
] as $what => $v) {
    check("refused: $what", langSanitizeValue($v) === null, $v);
}
foreach ([
    'plain text with a placeholder'        => 'Ta strona nie istnieje na :site.',
    'a bare < in prose'                    => 'a < b and 3 <4',
    'every allowed tag'                    => 'Use <code>?lang=</code>, <kbd>Ctrl</kbd><sup>1</sup> <small>x</small> <span>y</span> <em>z</em> <b>1</b> <i>2</i>',
    '<br> in both spellings'               => 'one<br>two<br/>three<br />four',
    'a relative href'                      => '<a href="/terms">x</a>',
    'a placeholder href, as the shipped strings use' => '<a href=":url">x</a>',
    'a terminated entity in an href'       => '<a href="https://x/?a=1&amp;b=&#50;">x</a>',
    'an upper-case tag and scheme'         => '<A HREF="HTTPS://X">x</A>',
    'the word "metadata:" in prose'        => 'the metadata: name and size',
] as $what => $v) {
    check("kept verbatim: $what", langSanitizeValue($v) === $v, $v);
}
// The endpoint has to actually call it, on both paths that write a file from foreign strings.
$ep = (string)file_get_contents($root . '/api/admin/languages.php');
check('the upload path runs every value through it',
      preg_match('/op === \'upload\'.*langSanitizeValue\(\$v\)/s', $ep) === 1);
check('… and so does the duplicate path',
      preg_match('/op === \'duplicate\'.*langSanitizeValue\(\$v\).*op === \'upload\'/s', $ep) === 1);
check('a dropped key is named in the reply, not just counted', str_contains($ep, "'dropped' => \$dropped"));
check('the sentence that names them is translated', langHas('settings.lang_upload_dropped'));

// ── writing a language file ─────────────────────────────────────────────────
check('a bad code is refused before anything is written', !langWriteFile('BAD!', ['a.b' => 'c'], 'x'));
check('a non-string value is refused', !langWriteFile('zz', ['a.b' => ['nested']], 'x'));
check('a built-in cannot be deleted', !langDeleteFile('en') && !langDeleteFile('pl'));
check('… and the file is still there', is_file($root . '/lang/en.php'));
if (is_writable($root . '/lang')) {
    check('a language can be written', langWriteFile('zz', ['nav.home' => 'Home-zz'], 'test'));
    langInvalidate();
    $GLOBALS['__lang']['loaded'] = [];
    check('… and read back', langLoad('zz') === ['nav.home' => 'Home-zz'], json_encode(langLoad('zz')));
    check('… and shows as installed', langInstalled('zz'));
    // The file is written by var_export() of vetted data, so nothing an uploader typed is executed.
    $raw = (string)file_get_contents($root . '/lang/zz.php');
    check('… as a literal array, with no code from the caller in it',
          str_starts_with($raw, '<?php') && str_contains($raw, "return array (") && !str_contains($raw, '$'));
    check('a non-built-in can be deleted', langDeleteFile('zz'));
    langInvalidate();
    check('… and is gone', !is_file($root . '/lang/zz.php'));
} else {
    echo "SKIP lang/ is not writable here — the write path is untested\n";
}

// ── registration ────────────────────────────────────────────────────────────
$api = (string)file_get_contents($root . '/api.php');
check('the admin endpoint is routed', str_contains($api, "'admin/languages'"));
check('the user endpoint is routed', str_contains($api, "'user_language'"));
check('installing a language is owner-only (no permission entry)',
      !preg_match("/'admin\\/languages'\\s*=>\\s*'panel\\./", $api));
check('the API resolves a language too', str_contains($api, 'langInit($cfg,'));
$idx = (string)file_get_contents($root . '/index.php');
check('the page resolves it before anything is rendered', str_contains($idx, 'langInit($cfg,'));
$nav = (string)file_get_contents($root . '/templates/nav.php');
check('the switcher is in the nav', str_contains($nav, 'lang-switch'));
check('… and only when there is more than one language', str_contains($nav, 'count($langOpts) > 1'));
// A switcher that sends you to the front page is one people stop pressing.
check('… and it keeps you on the page you were reading', str_contains($nav, '$langQuery = $_GET;'));
$layout = (string)file_get_contents($root . '/templates/layout.php');
check('the html lang attribute follows the language', str_contains($layout, '<html lang="<?= sanitize(langCurrent()) ?>">'));
$acc = (string)file_get_contents($root . '/templates/pages/account.php');
check('an account can pin its own language', str_contains($acc, "id=\"acc-language\""));
$css = (string)file_get_contents($root . '/assets/css/style.css');
check('the switcher has styling', str_contains($css, '.lang-opt'));
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('the settings section exists', str_contains($tpl, 'id="section-languages"'));
check('both modals exist', str_contains($tpl, 'id="langUploadModal"') && str_contains($tpl, 'id="langDupModal"'));
check('the script is loaded after admin-common.js, which it needs',
      strpos($tpl, 'admin-languages.js') > strpos($tpl, 'admin-common.js'));
$cat = (string)file_get_contents($root . '/includes/settings_catalog.php');
check('Languages is its own settings group', str_contains($cat, "'id' => 'languages'"));
$audit = (string)file_get_contents($root . '/includes/audit.php');
check('language changes are audited', str_contains($audit, "'admin/languages'             => 'language.manage'"));
$schema = (string)file_get_contents($root . '/includes/schema.php');
check('users.language exists in the schema', str_contains($schema, "`language` VARCHAR(3) DEFAULT NULL"));
check('… and is added to older installs too', str_contains($schema, "schemaColumnExists(\$db, 'users', 'language')"));
check('the schema version was bumped for it',
      (bool)preg_match('/TRACKER_SCHEMA_VERSION = (\d+)/', $schema, $m) && (int)$m[1] >= 40, $m[1] ?? '?');
// lang/ is written by its own endpoint; a settings save must never be able to touch these.
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
foreach (['enabled_languages', 'switcher_languages', 'user_languages'] as $k) {
    check("$k is not in the settings allow-list", !str_contains($save, "'$k'"));
}


/* ── every rendered page declares the language it is actually in ─────────────
   The settings page shipped `<html lang="en">` while its content was Polish: the switcher worked,
   the document lied about it, and a screen reader read Polish with an English voice. */
$badLang = [];
foreach (glob($root . '/templates/*.php') + glob($root . '/templates/admin/*.php') + glob($root . '/templates/pages/*.php') as $f) {
    $src = (string)@file_get_contents($f);
    if (!preg_match('/<html[^>]*lang="([^"]*)"/i', $src, $m)) continue;
    if (str_contains($m[1], 'langCurrent()')) continue;
    if (basename($f) === 'maintenance.php') continue;   // deliberate: it prints both languages, English first
    $badLang[] = basename($f) . ' -> ' . $m[1];
}
check('every template takes <html lang> from langCurrent()', $badLang === [], implode(', ', $badLang));

/* ── a label printed through _h() may not be written in HTML ─────────────────
   `_h()` escapes, which is the whole point of it: these strings reach the page as text and a
   stranger's translation must not be able to open a tag. So a dictionary entry that reaches a
   template that way and contains `&ldquo;` prints the six characters, not the quote — which is
   what the Settings page did with the "…is writing" switch for a release. Write the character. */
$entities = [];
$dict = require $root . '/lang/en.php';
foreach (glob($root . '/templates/*.php') + glob($root . '/templates/admin/*.php') + glob($root . '/templates/pages/*.php') as $f) {
    $src = (string)@file_get_contents($f);
    if (!preg_match_all("/_h\(\s*'([A-Za-z0-9_.]+)'/", $src, $mk)) continue;
    foreach (array_unique($mk[1]) as $k) {
        $v = (string)($dict[$k] ?? '');
        if ($v !== '' && preg_match('/&(?:[a-zA-Z][a-zA-Z0-9]{1,10}|#\d{2,5}|#x[0-9a-fA-F]{2,6});/', $v)) {
            $entities[] = basename($f) . ' -> ' . $k;
        }
    }
}
check('no escaped label is written as HTML entities', $entities === [], implode(', ', $entities));


/* ── the in-place language switch (assets/js/lang-swap.js) ───────────────────
   The browser half is driven by a real browser in scratchpad/shots/langswap_check.js. What is
   pinned HERE is the wiring it depends on: the page has to tell the script whether the operator
   turned the feature on, and it has to load the script whether they did or not — because the same
   file is what keeps the reader's place across the ordinary reload the feature falls back to. */
relang(['lang_swap_enabled' => '1']);
$bundleOn = langJsBundle(['js.common.']);
check('the bundle carries the swap flag when it is on', ($bundleOn['swap'] ?? null) === true, var_export($bundleOn['swap'] ?? null, true));
relang(['lang_swap_enabled' => '0']);
$bundleOff = langJsBundle(['js.common.']);
check('… and false when it is off', ($bundleOff['swap'] ?? null) === false, var_export($bundleOff['swap'] ?? null, true));
relang([]);
check('… and false when the setting has never been written', (langJsBundle(['js.common.'])['swap'] ?? null) === false);
check('the flag is a real boolean, so JSON.parse hands the script a boolean',
    json_decode(json_encode(langJsBundle(['js.common.'])), true)['swap'] === false);

// The script has to be on the page with the swap OFF as well: it is the only thing that keeps the
// reader's place across the full reload the switcher still does in that case.
$bridgeOff = langJsBridge('/');
check('the bridge loads lang-swap.js with the swap off', str_contains($bridgeOff, 'assets/js/lang-swap.js'), $bridgeOff);
relang(['lang_swap_enabled' => '1']);
check('… and with it on', str_contains(langJsBridge('/'), 'assets/js/lang-swap.js'));
check('lang-swap.js comes after i18n.js, which defines the t() it calls',
    strpos($bridgeOff, 'i18n.js') < strpos($bridgeOff, 'lang-swap.js'));
check('the file it names exists', is_file($root . '/assets/js/lang-swap.js'));
// The old copy lived in admin-common.js, which public pages never load — and a second copy
// listening for the same click would store the place twice and restore it twice.
// There were TWO of them — one in admin-common.js keyed 'thx_lang_place', one at the end of app.js
// keyed 'thx_lang_place_pub'. Different keys and different audiences (no public page loads
// admin-common.js), so they never actually fought; what they were was two implementations of one
// rule, and the second one restored by scroll pixel. Neither may survive.
foreach (['admin-common.js', 'app.js'] as $jsFile) {
    $js = (string)@file_get_contents($root . '/assets/js/' . $jsFile);
    check("the place-keeper is not still duplicated in $jsFile", !str_contains($js, 'thx_lang_place'));
}

// Four places or it is not a setting: default, catalogue keywords, save allow-list, a named control.
$schemaSrc  = (string)@file_get_contents($root . '/includes/schema.php');
$catalogSrc = (string)@file_get_contents($root . '/includes/settings_catalog.php');
$saveSrc    = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl     = (string)@file_get_contents($root . '/templates/admin/settings.php');
check('lang_swap_enabled has a schema default', str_contains($schemaSrc, "'lang_swap_enabled'"));
check('lang_swap_enabled is in the search catalogue', str_contains($catalogSrc, "'lang_swap_enabled'"));
check('lang_swap_enabled is in the save allow-list', str_contains($saveSrc, "'lang_swap_enabled'"));
check('lang_swap_enabled has a control on the Settings page', str_contains($setTpl, 'name="lang_swap_enabled"'));
// It ships OFF for one release: the thing it replaces — a plain navigation — is what every failure
// path falls back to anyway, so there is nothing to lose by letting operators opt in.
require_once $root . '/includes/schema.php';
check('… and it ships on from 1.41.0', (trackerSchemaDefaultSettings()['lang_swap_enabled'] ?? null) === '1', var_export(trackerSchemaDefaultSettings()['lang_swap_enabled'] ?? null, true));

/* -- EVERY KEY A SCRIPT ASKS FOR MUST BE IN THE BUNDLE ---------------------
 * langJsBundle() ships `js.` and nothing else, so t('a.wl.something') in a script renders the
 * literal string "a.wl.something" on the page: no error, no warning, just a dictionary key where a
 * sentence should be. It happened to eight keys at once in 1.42.0 (the API key editor), and the
 * only thing that catches it is looking at what the scripts actually ask for.
 */
$enAll = langLoad('en');
foreach (glob($root . '/assets/js/*.js') as $jsPath) {
    $src = (string)@file_get_contents($jsPath);
    $jsName = basename($jsPath);
    preg_match_all("/\bt\(\s*'([A-Za-z0-9_.]+)'/", $src, $mk);
    $bad = [];
    $missing = [];
    foreach (array_unique($mk[1]) as $key) {
        // A key built at runtime ('js.wl.review_' . status) is not a literal and cannot be checked
        // here; those end with the separator and are skipped rather than reported as broken.
        if (str_ends_with($key, '.') || str_ends_with($key, '_')) continue;
        if (!str_starts_with($key, 'js.')) { $bad[] = $key; continue; }
        if (!isset($enAll[$key])) $missing[] = $key;
    }
    check("$jsName asks only for js.* keys, the only ones in the bundle",
          $bad === [], implode(', ', array_slice($bad, 0, 6)));
    check("... and every one of them is in the dictionary",
          $missing === [], implode(', ', array_slice($missing, 0, 6)));
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
