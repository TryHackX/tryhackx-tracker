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
check('the html lang attribute follows the language', str_contains($layout, '<html lang="<?= sanitize(langCurrent()) ?>"'));
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
    if (!preg_match('/<html[^>]*\blang="([^"]*)"/i', $src, $m)) continue;
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
    // 1.73.0: a key is asked for through t() and through the ways that keep it on the node too — t.key('…'),
    // t.node('…'), t.html('…'), t.text(node, '…'), t.attr(node, 'name', '…'), t.ah('name', '…') (assets/js/i18n.js).
    preg_match_all("/\bt(?:\.key|\.node|\.html)?\(\s*'([A-Za-z0-9_.]+)'|\bt\.text\([^,()]+,\s*'([A-Za-z0-9_.]+)'|\bt\.attr\([^,()]+,\s*'[a-z-]+',\s*'([A-Za-z0-9_.]+)'|\bt\.ah\(\s*'[a-z-]+',\s*'([A-Za-z0-9_.]+)'/", $src, $mk);
    $mk[1] = array_values(array_filter(array_merge($mk[1], $mk[2], $mk[3], $mk[4])));
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

/* ── 1.73.0: the live switch reaches EVERYTHING ──────────────────────────────
   The owner: a tooltip in the messages stayed English after a switch to Polish ("after a reload it was fine,
   only the live swap was not"). scratchpad/shots/langswap_all_check.js walks every page type and state in a real
   browser and reads every word back; what is pinned HERE is the wiring that rests on. */
$i18nJs = (string)@file_get_contents($root . '/assets/js/i18n.js');
$swapJs = (string)@file_get_contents($root . '/assets/js/lang-swap.js');
// t() stays a primitive string — for a comparison, a switch, a Map key, a payload, a test's own t() call (a t() that
// answered an object broke `===` in panel_fixes_check) — and the word a script WRITES into the page is t.key(): a
// String that says itself in the language loaded now and leaves its key on the element it is written into.
check('i18n.js: t() is a plain string; t.key() is the word written into the page, which keeps its key there',
      str_contains($i18nJs, 'function t(key, params) { return words(key, params); }')
      && str_contains($i18nJs, 'class Keyed extends String') && str_contains($i18nJs, 'toString() { return words(this.key, this.params); }')
      && str_contains($i18nJs, 'toJSON() { return words(this.key, this.params); }')
      && str_contains($i18nJs, 't.key = function (key, params) { return new Keyed(key, params); };') && str_contains($i18nJs, 't.words = words;'));
// … and a t.key() word goes INTO the page: never into a comparison (an object there) or a glued string (its key lost).
$keyedData = [];
foreach (glob($root . '/assets/js/*.js') as $f) {
    $src = (string)@file_get_contents($f);
    // `+ t.key(`, `=== t.key(`, `t.key(…) +` / `===`, and a t.key() word inside a parenthesis that is glued:
    // `x + (c ? t.key(…) : '')` (the parenthesis holds no call of its own before the word)
    if (preg_match_all('~(\+\s*t\.key\(|(?:===|!==|==|!=)\s*t\.key\(|\+\s*\((?:[^()]|\([^()]*\))*?(?<![\w$.])t\.key\(|t\.key\((?:\'[^\']*\'|[^()\'])*(?:\((?:\'[^\']*\'|[^()\'])*\)(?:\'[^\']*\'|[^()\'])*)*\)\s*(?:\+|===|!==))~', $src, $mm)) {
        $keyedData[] = basename($f) . ' (' . count($mm[0]) . ')';
    }
}
check('… and no script compares a t.key() word or glues it into a longer string', $keyedData === [], implode(', ', $keyedData));
check('… with what markup and a server\'s answers need: t.html / t.esc (text), t.ah (attributes), t.find (a dictionary sentence by key)',
      str_contains($i18nJs, 't.html = function (key, params)') && str_contains($i18nJs, 't.esc = function (v)')
      && str_contains($i18nJs, 't.ah = function (name, key, params)') && str_contains($i18nJs, 't.find = function (text, prefix)'));
// The escapers of the templates keep a t() word's key; the el() helpers hand it over as a keyed <span>.
$escKeyed = [];
foreach (['assets/js/admin.js' => 'function esc(str) {' . "\n" . '    if (t.isKey(str)) return t.html(str);',
          'assets/js/admin-common.js' => "    function esc(str) {\n        if (t.isKey(str)) return t.html(str);",
          'assets/js/app.js' => "function escHtml(str) {\n    if (t.isKey(str)) return t.html(str);"] as $rel => $needle) {
    if (!str_contains(str_replace("\r\n", "\n", (string)@file_get_contents($root . '/' . $rel)), $needle)) $escKeyed[] = $rel;
}
check('… the templates\' escapers say a t() word as keyed markup', $escKeyed === [], implode(', ', $escKeyed));
$elKeyed = [];
foreach (['account-security', 'admin-common', 'admin-otperf', 'emoji-picker', 'favourites', 'media-editor', 'people', 'shoutbox'] as $m) {
    if (!str_contains((string)@file_get_contents($root . '/assets/js/' . $m . '.js'), 'appendChild(t.child(c))')) $elKeyed[] = $m;
}
check('… and every module\'s el() helper hands a t() word over as a keyed <span> (t.child)', $elKeyed === [], implode(', ', $elKeyed));
check('… a sentence with people in it keeps its key round them (avatar.js\'s phrase → t.phraseMark, the people in their places)',
      str_contains($i18nJs, 't.phraseMark = function (node, word)') && str_contains($i18nJs, 'function sayPhrase(node, sentence)')
      && str_contains((string)@file_get_contents($root . '/assets/js/avatar.js'), "if (keyed) t.phraseMark(host, text);")
      && str_contains((string)@file_get_contents($root . '/assets/js/avatar.js'), "who.setAttribute('data-slot', part);"));
check('… a server\'s own sentences ride along where a script shows them (the anti-spam layer by key, ratings and sources by t.find)',
      in_array('api.antispam.', LANG_JS_PUBLIC, true) && in_array('api.rep.', LANG_JS_PUBLIC, true) && in_array('api.index.source_auto_', LANG_JS_PUBLIC, true)
      && str_contains((string)@file_get_contents($root . '/includes/antispam.php'), "'key' => \$key, 'vars' => (object)\$plain")
      && str_contains((string)@file_get_contents($root . '/assets/js/antispam.js'), 'function sentence(r, left)'));
check('… the browser\'s writers taught to keep it: textContent, title, placeholder, setAttribute, append and its kin',
      str_contains($i18nJs, "teachSetter(Node.prototype, 'textContent', 'text');") && str_contains($i18nJs, "teachSetter(HTMLElement.prototype, 'title', 'title');")
      && str_contains($i18nJs, 'Element.prototype.setAttribute = function (name, value)')
      && str_contains($i18nJs, "['append', 'prepend', 'replaceChildren', 'before', 'after']"));
check('… and the swap\'s two halves: which keyed words are still ours (t.ours) and saying them again (t.say)',
      str_contains($i18nJs, 't.ours = function (root)') && str_contains($i18nJs, 't.say = sayAll;') && str_contains($i18nJs, 't.relabel = function (root)'));
check('lang-swap.js walks only what the server wrote (marks), templates and their adopted copies, and says the keyed words',
      str_contains($swapJs, 'window.LangSwap = { mark: mark, adopt: adopt, isSwitch: isSwitch };') && str_contains($swapJs, 'isServer(n)) lk.push(n);')
      && str_contains($swapJs, "if (live.tagName === 'TEMPLATE') {") && str_contains($swapJs, 'window.t.ours(document.body)')
      && str_contains($swapJs, 'window.t.say(ours);'));
check('… a server button put back from a copy is the server\'s again; a sentence whose markup sits in another order is swapped whole',
      str_contains($swapJs, 'function regainButtons()') && str_contains($swapJs, 'if (marked) regainButtons();')
      && str_contains($swapJs, 'function planPhrase(live, fresh, out)') && str_contains($swapJs, 'if (planPhrase(live, fresh, out)) return;'));
// The bulk scrape's label ("Stop — 12 scraped (40 left)") is put back from a copy BEHIND the button's server icon:
// the whole markup decides (not the first child), a node left keyless keeps no placeholders (or the copy never
// matches), and a button lent to a script while the language changes is given back in the new one.
check('… whatever node of it a script borrowed; one lent while the language changes is given back in the new language',
      str_contains($swapJs, 'if (html === undefined || b.innerHTML !== html || wholeServer(b)) return;')
      && str_contains($swapJs, 'function giveBack()') && str_contains($swapJs, 'counterpart.set(live, fresh);')
      && str_contains($i18nJs, 'function bare(node)') && str_contains($i18nJs, "this.removeAttribute(TEXT); bare(this);"));
check('… an error page swaps like any other page (its own status comes back)',
      str_contains($swapJs, "document.documentElement.getAttribute('data-status')") && str_contains($layout, "data-status=\""));
check('the public layout marks the page before its first script',
      ($mk = strpos($layout, 'LangSwap.mark()')) !== false && $mk < (int)strpos($layout, '<script src="<?= $baseUrl ?>assets/js/captcha.js'));
$unmarked = [];
foreach (['audit', 'backups', 'dashboard', 'index_page', 'settings', 'traffic', 'users', 'whitelist'] as $pg) {
    $src = (string)@file_get_contents($root . '/templates/admin/' . $pg . '.php');
    // the first <script src=…assets/js/admin….js> (a comment naming a module is not its script)
    $firstModule = preg_match('~<script src="[^"]*assets/js/admin[a-z-]*\.js~', $src, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : -1;
    $at = strpos($src, 'LangSwap.mark()');
    if ($at === false || $firstModule < 0 || $at > $firstModule) $unmarked[] = $pg;
}
check('every panel page marks itself before its own scripts', $unmarked === [], implode(', ', $unmarked));
// No word is frozen into an inline script any more: the swap reloads the js.* bundle, and nothing else.
$frozen = [];
foreach (array_merge(glob($root . '/templates/*.php'), glob($root . '/templates/*/*.php')) as $f) {
    $src = (string)@file_get_contents($f);
    if (!preg_match_all('~<script(?![^>]*application/json)[^>]*>(.*?)</script>~s', $src, $blocks)) continue;
    foreach ($blocks[1] as $b) if (preg_match('~(json_encode\(\s*__\(|_h\(|\b__\()~', $b)) { $frozen[] = basename(dirname($f)) . '/' . basename($f); break; }
}
check('no template freezes a translated word into an inline script', $frozen === [], implode(', ', $frozen));
$setTplSrc = (string)@file_get_contents($root . '/templates/admin/settings.php');
check('… the Settings page\'s own script reads its words from its bundle (the page\'s bridge carries them)',
      str_contains($setTplSrc, "langJsBridge(\$baseUrl, ['js.', 'settings.js_'"));
check('… and the admin sign-in and unsubscribe pages\' scripts read theirs from theirs',
      str_contains($layout, "'adminlogin.'") && str_contains($layout, "'unsub.'"));

// ── 1.73.0: the time-zone regions ───────────────────────────────────────────
// The four zone selects (account, Settings → Site, the schedule's and the backups' zone) group every zone under its
// region — "Europe", "America"… and "Other" for UTC — and those group labels were English on a Polish page. The zone
// names stay IANA's ids; the regions are words (tz.region_*, includes/db_clock.php tzRegionLabel()).
$tzRegions = ['Other'];
foreach (DateTimeZone::listIdentifiers() as $tzId) if (($tzCut = strpos($tzId, '/')) !== false) $tzRegions[] = substr($tzId, 0, $tzCut);
$tzRegions = array_values(array_unique($tzRegions));
$tzMiss = array_values(array_filter($tzRegions, fn($r) => ($en['tz.region_' . strtolower($r)] ?? null) !== $r || !isset($pl['tz.region_' . strtolower($r)])));
check('every time-zone region PHP knows has its words in both languages (the English IANA\'s own region word)',
      $tzMiss === [] && count($tzRegions) >= 10, implode(',', $tzMiss));
relang(['default_language' => 'pl']);
$tzPl = [tzRegionLabel('Europe'), tzRegionLabel('Other'), tzRegionLabel('Mars'), tzRegionLabel('<b>')];
relang([]);
check('… tzRegionLabel() says them in the reader\'s language; a region it does not know as it is',
      $tzPl === [$pl['tz.region_europe'], $pl['tz.region_other'], 'Mars', '<b>'] && tzRegionLabel('Europe') === 'Europe', json_encode($tzPl, JSON_UNESCAPED_UNICODE));
check('… and the four zone selects print it on their groups',
      substr_count($setTplSrc, '<optgroup label="<?= sanitize(tzRegionLabel(') === 3
      && str_contains($acc, '<optgroup label="<?= sanitize(tzRegionLabel($accTzGrp)) ?>">'));

// ── 1.73.1: a count in the PAGE's language, the same characters on both sides ──
// The home page's strip and the Stats page: the server wrote English grouping on both languages ("2,251,367"), and
// app.js refreshed them with toLocaleString() and no locale — the BROWSER's grouping: a Polish browser showed
// "2 251 367" on the English page, the Polish page kept the English commas (production, 2026-10-05). One rule now:
// langNumber() (includes/lang.php) on the server, t.num() (assets/js/i18n.js, Intl.NumberFormat of the page's
// language) in the scripts — so a refresh never changes how a number looks. The expected strings below are what
// Intl.NumberFormat gives (node / Chrome, 2026-10): Polish leaves four digits whole and groups with U+00A0.
$nbsp = "\u{00A0}";
$numCases = [0, 7, 999, 1000, 1234, 9999, 10000, 12345, 2251367, -1234, -12345, 1234.5, '2251367'];
$wantEn = ['0', '7', '999', '1,000', '1,234', '9,999', '10,000', '12,345', '2,251,367', '-1,234', '-12,345', '1,235', '2,251,367'];
$wantPl = ['0', '7', '999', '1000', '1234', '9999', "10{$nbsp}000", "12{$nbsp}345", "2{$nbsp}251{$nbsp}367", '-1234', "-12{$nbsp}345", '1235', "2{$nbsp}251{$nbsp}367"];
$gotEn = array_map(fn($v) => langNumber($v, 'en'), $numCases);
$gotPl = array_map(fn($v) => langNumber($v, 'pl'), $numCases);
check('langNumber(): English groups with a comma from four digits on — Intl.NumberFormat("en")', $gotEn === $wantEn, json_encode($gotEn));
check('langNumber(): Polish leaves four digits whole and groups with a NO-BREAK space — Intl.NumberFormat("pl")',
      $gotPl === $wantPl, json_encode($gotPl, JSON_UNESCAPED_UNICODE));
relang(['default_language' => 'pl']);
$numPagePl = langNumber(2251367);
relang([]);
check('… and with no language named it writes the page\'s (langCurrent())',
      $numPagePl === "2{$nbsp}251{$nbsp}367" && langNumber(2251367) === '2,251,367', json_encode([$numPagePl, langNumber(2251367)], JSON_UNESCAPED_UNICODE));
// Another installed language: ICU (PHP's intl), the same data a browser carries, with Intl's four-digit rule where
// CLDR gives it (Spanish); without intl, the English rule.
$icuOk = class_exists('NumberFormatter')
    ? (langNumber(1234, 'de') === '1.234' && langNumber(2251367, 'de') === '2.251.367' && langNumber(1234, 'es') === '1234' && langNumber(12345, 'es') === '12.345')
    : (langNumber(1234, 'de') === '1,234');
check('… another language through ICU, with the four-digit rule CLDR gives it (es) — the English rule without intl', $icuOk,
      json_encode([langNumber(1234, 'de'), langNumber(1234, 'es'), langNumber(12345, 'es')], JSON_UNESCAPED_UNICODE));
// The client's half: one helper, from the page's language, Intl's grouping — and a number a script writes keeps it on
// the element, so the live language switch writes it again in the new language's grouping (lang-swap.js t.ours/t.say).
$i18nJs = str_replace("\r\n", "\n", (string)@file_get_contents($root . '/assets/js/i18n.js'));
check('i18n.js: t.num() — Intl.NumberFormat of the page\'s language, whole numbers, a keyed number the live switch says again',
      str_contains($i18nJs, 't.num = function (n, style) {') && str_contains($i18nJs, "{ maximumFractionDigits: 0 }")
      && str_contains($i18nJs, "if (key === NUM) return fmtNum(params ? params['#'] : null, from === prev ? prevLang : lang, !!(params && params.c));")
      && str_contains($i18nJs, 'return new Keyed(NUM, p);'));
// … and compact from a million up where a place's width is fixed (the owner's item 4: a row's swarm ran over its hash
// chip): Intl's compact notation, two decimals ("100.82M", "100,82 mln"), never below a million (Polish "23,46 tys."
// is no shorter than "23 456"); the row's swarm writes it so, with the exact pair in its title.
$favJs = str_replace("\r\n", "\n", (string)@file_get_contents($root . '/assets/js/favourites.js'));
check('… t.num(n, \'compact\'): a million or more in Intl\'s compact notation, two decimals; a row\'s swarm uses it, its exact pair in the title',
      str_contains($i18nJs, "var big = compact && Math.abs(v) >= 1e6;") && str_contains($i18nJs, "{ notation: 'compact', maximumFractionDigits: 2 }")
      && str_contains($favJs, "t.num(r.seeders, 'compact')") && str_contains($favJs, "t.key('js.fav.sl_exact'"));
// The Stats page's countdown and heat-map tooltip are app.js's sentences on the server too (the owner's item 5: the
// first refresh changed "12s" into "12 s" and "Interval 05m" into "Interval 05 min").
$statsTpl = (string)@file_get_contents($root . '/templates/pages/stats.php');
check('the Stats page renders the countdown and the heat map\'s tooltip with app.js\'s own sentences (one wording each)',
      str_contains($statsTpl, "_h('js.app.next_update_in', ['n' => \$remainingSeconds])")
      && str_contains($statsTpl, "_h('js.app.interval_tooltip', ['interval' => \$rawLabel, 'count' => \$countFormatted])")
      && !isset($en['stats.next_update']) && !isset($en['stats.heat_tip']));
// No script writes a number with toLocaleString(): every one left is a DATE (outside this rule).
$numLeft = [];
$numFiles = array_merge(glob($root . '/assets/js/*.js') ?: [], glob($root . '/templates/*.php') ?: [], glob($root . '/templates/*/*.php') ?: []);
foreach ($numFiles as $f) {
    $src = (string)@file_get_contents($f);
    $all = substr_count($src, '.toLocaleString(');
    $dates = preg_match_all('~new Date\([^;]*?\)\.toLocaleString\(|\bd\.toLocaleString\(undefined, \{ year~', $src);
    if ($all > $dates) $numLeft[] = basename($f) . ' (' . ($all - $dates) . ')';
}
check('… and no script groups a number with toLocaleString() any more (the browser\'s language) — only dates use it',
      $numLeft === [], implode(', ', $numLeft));
// No page or answer writes a count with number_format()'s English default: a one-argument call. Left alone on purpose:
// the fallback where the dictionary is not loaded, and the auto-tuner's English note (includes/netlimit.php).
$numPhp = [];
$numPhpFiles = array_merge(glob($root . '/templates/*.php') ?: [], glob($root . '/templates/*/*.php') ?: [],
                           glob($root . '/includes/*.php') ?: [], glob($root . '/api/*.php') ?: [], glob($root . '/api/*/*.php') ?: []);
foreach ($numPhpFiles as $f) {
    if (basename($f) === 'lang.php') continue;          // the helper itself
    foreach (preg_split('/\R/', (string)@file_get_contents($f)) as $ln => $line) {
        $at = 0;
        if (preg_match('~^\s*(//|\*|/\*|#)~', $line)) continue;   // a comment naming it
        while (($p = strpos($line, 'number_format(', $at)) !== false) {
            $at = $p + 14;
            if (str_contains($line, "function_exists('langNumber')") || preg_match('/pps (reaching|is comfortably)|already at the (floor|ceiling)/', $line)) continue;
            $depth = 1; $comma = false;
            for ($i = $at, $len = strlen($line); $i < $len && $depth > 0; $i++) {
                $c = $line[$i];
                if ($c === '(') $depth++;
                elseif ($c === ')') $depth--;
                elseif ($c === ',' && $depth === 1) $comma = true;
            }
            if (!$comma) $numPhp[] = basename($f) . ':' . ($ln + 1);
        }
    }
}
check('… and no page or answer writes a count with number_format()\'s English default — langNumber() does',
      $numPhp === [], implode(', ', array_slice($numPhp, 0, 12)));

// ── the generated files are what the sources make ───────────────────────────
// lang/en.php and lang/pl.php are GENERATED from tools/lang_src.d/. Between 1.43 and 1.50, 369
// strings were added to the generated files by hand and never to the sources, and the first
// regeneration after that dropped every one of them. This check makes that a same-day failure.
$py = '';
foreach (['python3', 'python'] as $cand) {
    if (preg_match('/^Python 3/', trim((string)@shell_exec($cand . ' --version 2>&1')))) { $py = $cand; break; }
}
if ($py === '') {
    echo "SKIP the generated dictionaries match their sources  -> no python 3 on PATH\n";
} else {
    $out = []; $rc = 1;
    @exec($py . ' ' . escapeshellarg($root . '/tools/lang_src.py') . ' --check ' . escapeshellarg($root) . ' 2>&1', $out, $rc);
    check('the generated dictionaries match their sources (tools/lang_src.py --check)', $rc === 0,
          implode(' | ', array_slice($out, 0, 4)));
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
