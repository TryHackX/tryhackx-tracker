<?php
/**
 * The language of the pages, 1.74.0 part D:
 *   php tests/lang_plural_test.php
 *
 *   1. THE POLISH "2–4" FORMS (QUAL-17, UX-24): a counted word's form chosen by the language's own rule where it is looked
 *      up — includes/lang.php langPluralKey() in __() and langFor(), plural(); assets/js/i18n.js say() and t.plural(),
 *      the REAL i18n.js run by node with a small stand-in for the DOM, a word written into the page said again in the
 *      other language by a live switch — and every Polish family that counts has its third form or reads right for every
 *      number as it stands.
 *   2. THE SCRIPTS' DICTIONARY AS A FILE (PERF-9): i18n.php's answer (langJsServeRequest()), the page's bridge naming it
 *      (no strings inline, the file's content hash in its address, immutable only for that hash), and the file in
 *      deploy/deploy.py's list of what is shipped.
 *   3. EVERY KEY THE SERVER ASKS FOR IS DEFINED (QUAL-10): every literal __('…'), _h('…'), langFor($x, '…') and
 *      plural($n, '…') in api/, includes/ and the root's PHP.
 *   4. ONE SET OF POLISH TERMS (QUAL-19, tools/lang_src.d/common.py's list).
 *   5. SIZES IN THE PAGE'S LANGUAGE (UX-25): t.bytes(), by node, in both languages.
 * No database, no session: the dictionaries, the functions and node.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/lang.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function relang(array $cfg, ?string $user = null): void {
    $GLOBALS['__lang']['current'] = null;
    langInvalidate();
    langInit($cfg, $user);
}
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$en = langLoad('en');
$pl = langLoad('pl');
$nbsp = "\u{00A0}";

/* ══ 1. the "2–4" forms ═════════════════════════════════════════════════════════════════════════════════════ */

// The categories: CLDR's, from PHP's intl when it is there (it is on every machine this runs on) or the table.
$nums = [0, 1, 2, 4, 5, 12, 14, 21, 22, 24, 25, 112];
$wantPl = ['many', 'one', 'few', 'few', 'many', 'many', 'many', 'many', 'few', 'few', 'many', 'many'];
$gotPl = array_map(fn($x) => langPluralCategory('pl', $x), $nums);
check('Polish: 1 one; 2–4, 22–24 few; 0, 5+, 12–14, 21, 25, 112 many', $gotPl === $wantPl, $J(array_combine($nums, $gotPl)));
check('English: 1 one, everything else other', array_map(fn($x) => langPluralCategory('en', $x), [0, 1, 2, 5, 21]) === ['other', 'one', 'other', 'other', 'other']);
check('… Russian (21 one, 22 few, 25 many) and Czech (2–4 few, 5 other), whose translations may write the forms',
      [langPluralCategory('ru', 21), langPluralCategory('ru', 22), langPluralCategory('ru', 25), langPluralCategory('cs', 3), langPluralCategory('cs', 5)]
      === ['one', 'few', 'many', 'few', 'other']);
check('a fraction is never one / few (the count is whole or it is "other")', langPluralCategory('pl', 2.5) === 'other');
check('a count read from what callers pass: a number, digits, digits grouped with a (no-break) space or a comma of thousands',
      langCountOf(3) === 3 && langCountOf('22') === 22 && langCountOf("2{$nbsp}251{$nbsp}367") === 2251367 && langCountOf('1,234') === 1234
      && langCountOf('1 234') === 1234 && langCountOf('1,5') === null && langCountOf('1000+') === null && langCountOf('x') === null);

// __() on a Polish page: the family's form by the number, whoever asks.
relang(['default_language' => 'pl']);
$files = [];
foreach ([1, 2, 4, 5, 12, 14, 21, 22, 24, 25] as $x) $files[$x] = __('js.app.files_many', ['n' => $x]);
check('__() on a Polish page: "1 plik", "2 pliki", "4 pliki", "5 plików", "12 plików", "14 plików", "21 plików", "22 pliki", "24 pliki", "25 plików"',
      $files === [1 => '1 plik', 2 => '2 pliki', 4 => '4 pliki', 5 => '5 plików', 12 => '12 plików', 14 => '14 plików', 21 => '21 plików',
                  22 => '22 pliki', 24 => '24 pliki', 25 => '25 plików'], $J($files));
check('… a count written by langNumber() too ("2 251 367 plików"; "1234 pliki" — it ends in 4, not in 14) and a floor ("1000+ plików")',
      __('js.app.files_many', ['n' => langNumber(2251367)]) === "2{$nbsp}251{$nbsp}367 plików"
      && __('js.app.files_many', ['n' => langNumber(1234)]) === '1234 pliki' && __('js.app.files_many', ['n' => '1000+']) === '1000+ plików',
      $J([__('js.app.files_many', ['n' => langNumber(2251367)]), __('js.app.files_many', ['n' => langNumber(1234)])]));
check('… the server\'s own sentences too: "Zapisano 3 wpisy", "Pobrano 5 wpisów.", ", pominięto 2 linie"',
      __('api.iplist.stored_many', ['n' => 3]) === 'Zapisano 3 wpisy' && __('api.iplist.downloaded_many', ['n' => 5]) === 'Pobrano 5 wpisów.'
      && __('api.iplist.ignored_many', ['n' => 2]) === ', pominięto 2 linie');
check('… a word that is not a counted one is left as asked ("too_many", a family without its one-form)',
      __('js.app.wl_too_many', ['n' => 2]) === str_replace(':n', '2', $pl['js.app.wl_too_many'])
      && __('api.ot.adv_workers_many', ['workers' => 2, 'cpus' => 1, 'n' => 2]) === strtr($pl['api.ot.adv_workers_many'], [':workers' => '2', ':cpus' => '1']));
check('plural(): "3 pliki", "1 plik", "5 plików" on the Polish page; plural(…, lang: en) "5 files"',
      plural(3, 'js.app.files') === '3 pliki' && plural(1, 'js.app.files') === '1 plik' && plural(5, 'js.app.files') === '5 plików'
      && plural(5, 'js.app.files', [], 'en') === '5 files');
relang([]);
check('__() on an English page: "2 files", "1 file" (asked as _many with 1), "21 files"',
      __('js.app.files_many', ['n' => 2]) === '2 files' && __('js.app.files_many', ['n' => 1]) === '1 file' && __('js.app.files_many', ['n' => 21]) === '21 files');
check('langFor() in the RECIPIENT\'s language: a Polish mail says "Zapisano 22 wpisy" whatever the page is',
      langFor('pl', 'api.iplist.stored_many', ['n' => 22]) === 'Zapisano 22 wpisy' && langFor('en', 'api.iplist.stored_many', ['n' => 22]) === '22 entries stored');
// A language that never wrote a third form keeps its many-form and never borrows the fallback's.
$own = ['x.files_one' => '1 файл', 'x.files_many' => ':n файлов'];
check('a language without its own third form keeps the many-form (the English fallback\'s "_few" is never borrowed)',
      langPluralKey('x.files_many', ['n' => 3], 'ru', $own) === 'x.files_many'
      && langPluralKey('x.files_many', ['n' => 3], 'ru', $own + ['x.files_few' => ':n файла']) === 'x.files_few'
      && langPluralKey('x.files_many', ['n' => 21], 'ru', $own) === 'x.files_many'
      && langPluralKey('x.files_many', ['n' => 21], 'ru', ['x.files_one' => ':n файл', 'x.files_many' => ':n файлов']) === 'x.files_one');

// Every Polish family that counts has its third form — or reads right for every number as it stands: the number a
// label's value ("torrentów: :n", "(:n)"), or a construction whose noun has one form after every number (listed).
$neutral = [
    'api.iplist.created_with' => '"z :n wpisami" — the instrumental is the same for 2 and 5',
    'info.days'               => '"dni" is the same for 2 and 5',
    'js.app.rep_stars_title'  => '"z :n ocen" — the genitive after "z" is right for every number',
    'js.wl.rating'            => '"z :n ocen" — the same',
    'settings.wlupkeep_match' => '"do :n wierszy" — the genitive after "do" is right for every number',
];
$missingFew = [];
foreach ($pl as $k => $v) {
    if (!str_ends_with($k, '_many')) continue;
    $base = substr($k, 0, -5);
    if (!isset($pl[$base . '_one']) || !preg_match('~:n\b~', $v)) continue;
    if (isset($pl[$base . '_few']) || isset($neutral[$base])) continue;
    if (preg_match('~:\s*(<strong>)?:n\b|\(:n\)~u', $v)) continue;                 // the count as a label's value
    $missingFew[] = $base;
}
check('every Polish counted family has its "2–4" form, or is a label / listed as right for every number',
      $missingFew === [], implode(', ', $missingFew));
$fewBad = [];
foreach ($pl as $k => $v) {
    if (!str_ends_with($k, '_few')) continue;
    $base = substr($k, 0, -4);
    if ($base === 'api.ot.adv_workers') continue;                                   // not a count: "few workers" vs "many"
    // A key that only ENDS in "few" ("too few readings to judge"): no one- or many-form beside it, so no count ever
    // chooses it (langPluralKey() starts from an asked `_many` and needs the family's `_one`).
    if (!isset($pl[$base . '_one']) && !isset($pl[$base . '_many'])) continue;
    if (!isset($pl[$base . '_one'], $pl[$base . '_many'], $en[$k])) $fewBad[] = $k;
    elseif ($en[$k] !== $en[$base . '_many']) $fewBad[] = $k . ' (English differs from its many-form)';
}
check('… and every "_few" key is a whole family (one, few, many), its English the many-form', $fewBad === [], implode(', ', $fewBad));
$labelPl = ['stats.sub_peers' => 'leecherów: :leechers &middot; seedów: :seeds', 'js.app.leech_seed_summary' => 'leecherów: :leechers · seedów: :seeds'];
check('the Stats page\'s leechers and seeds are labels in Polish ("2 leecherów" was wrong)',
      ($pl['stats.sub_peers'] ?? '') === $labelPl['stats.sub_peers'] && ($pl['js.app.leech_seed_summary'] ?? '') === $labelPl['js.app.leech_seed_summary']);
check('the Info panel\'s word under the file count is a family of three ("2 PLIKÓW" stood there)',
      ($pl['js.app.stat_files_few'] ?? '') === 'pliki' && ($pl['js.app.stat_files_many'] ?? '') === 'plików' && !isset($pl['js.app.stat_file'], $pl['js.app.stat_files']));

/* ══ the client's half: the real i18n.js, by node ═══════════════════════════════════════════════════════════ */
$harness = <<<'JS'
const fs = require('fs'), vm = require('vm');
const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
// A stand-in for the few DOM parts i18n.js teaches (accessors on the prototypes it reads) and reads.
class Node { get textContent() { return this._t === undefined ? '' : this._t; } set textContent(v) { this._t = String(v); } }
class Element extends Node {
    constructor() { super(); this.attrs = {}; this.kids = []; }
    get nodeType() { return 1; }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    getAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null; }
    hasAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n); }
    removeAttribute(n) { delete this.attrs[n]; }
    get firstElementChild() { return null; }
    querySelectorAll() { const out = []; const walk = (e) => e.kids.forEach((k) => { if (k.hasAttribute('data-i18n') || k.hasAttribute('data-i18n-a') || k.hasAttribute('data-i18n-ph')) out.push(k); walk(k); }); walk(this); return out; }
}
class HTMLElement extends Element { get title() { return this.getAttribute('title') || ''; } set title(v) { this.setAttribute('title', v); } }
class HTMLInputElement extends HTMLElement { get placeholder() { return this.getAttribute('placeholder') || ''; } set placeholder(v) { this.setAttribute('placeholder', v); } }
class HTMLTextAreaElement extends HTMLInputElement {}
class DocumentFragment extends Node {}
const data = { textContent: '' };
const body = new HTMLElement();
global.window = global;
Object.assign(global, { Node, Element, HTMLElement, HTMLInputElement, HTMLTextAreaElement, DocumentFragment });
global.document = { getElementById: (id) => (id === 'i18n-data' ? data : null), documentElement: { lang: 'pl' }, body };
global.I18N_DICT = { 'pl.t1': input.pl, 'en.t1': input.en, 'ru.t1': input.ru };
data.textContent = JSON.stringify({ lang: 'pl', swap: true, id: 'pl.t1', src: '' });
vm.runInThisContext(fs.readFileSync(input.i18n, 'utf8'), { filename: 'i18n.js' });
const t = global.t, out = {};
out.pl = [1, 2, 4, 5, 12, 14, 21, 22, 24, 25].map((n) => t('js.app.files_many', { n }));
out.plNum = [String(t.key('js.app.files_many', { n: t.num(22) })), String(t.key('js.app.files_many', { n: t.num(2251367) })),
             String(t.plural(3, 'js.app.files')), String(t.plural(1, 'js.app.files')), String(t.plural(5, 'js.app.files')),
             t('js.app.results_many', { n: '1000+' }), String(t.plural(3, 'js.app.stat_files')), t('js.app.wl_too_many', { n: 3 })];
out.bytesPl = [t.bytes(1.27 * 1073741824), t.bytes(1000 * 1048576), t.bytes(512), t.bytes(13.3 * 1073741824), t.bytes(0)].map(String);
// a word written into the page, then the live switch: pl -> en -> pl
const el = new HTMLElement(); body.kids.push(el);
el.textContent = t.key('js.app.files_many', { n: t.num(2) });
const sizeEl = new HTMLElement(); body.kids.push(sizeEl);
sizeEl.textContent = t.bytes(1.27 * 1073741824);
out.keyed = { text: el.textContent, key: el.getAttribute('data-i18n'), args: el.getAttribute('data-i18n-p') };
const swapTo = (lang) => { const ours = t.ours(body); data.textContent = JSON.stringify({ lang, swap: true, id: lang + '.t1', src: '' }); global.document.documentElement.lang = lang; t.reload(); t.say(ours); return [el.textContent, sizeEl.textContent]; };
out.swap = [swapTo('en'), swapTo('pl')];
// a language that never wrote its third form: the bundle carries the fallback's, named in `few` — never taken
data.textContent = JSON.stringify({ lang: 'ru', swap: true, id: 'ru.t1', src: '', few: ['js.app.files_few'] });
t.reload();
out.ru = [t('js.app.files_many', { n: 3 }), t('js.app.files_many', { n: 21 }), t('js.app.files_many', { n: 5 })];
data.textContent = JSON.stringify({ lang: 'en', swap: true, id: 'en.t1', src: '' });
t.reload();
out.en = [t('js.app.files_many', { n: 2 }), t('js.app.files_many', { n: 1 }), String(t.plural(1, 'js.app.files')), t.bytes(1.27 * 1073741824).toString()];
// the dictionary straight from i18n.php's answer: a script that registers itself under its id
vm.runInThisContext(input.served);
out.served = !!(global.I18N_DICT && global.I18N_DICT[input.servedId] && global.I18N_DICT[input.servedId]['js.app.files_many']);
process.stdout.write(JSON.stringify(out));
JS;
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lpt_' . bin2hex(random_bytes(4));
$plStrings = langJsStrings('pl', ['js.']);
$enStrings = langJsStrings('en', ['js.']);
$ruStrings = ['js.app.files_one' => ':n файл', 'js.app.files_many' => ':n файлов', 'js.app.files_few' => $en['js.app.files_few'] ?? ':n files'];
$servedReq = langJsServeRequest(['l' => 'pl', 'p' => 'js.app.', 'v' => langJsHash(langJsStrings('pl', ['js.app.']))], ['REQUEST_METHOD' => 'GET']);
file_put_contents($tmp . '.js', $harness);
file_put_contents($tmp . '.json', json_encode(['i18n' => $root . '/assets/js/i18n.js', 'pl' => $plStrings, 'en' => $enStrings, 'ru' => $ruStrings,
    'served' => $servedReq['body'], 'servedId' => 'pl.' . langJsHash(langJsStrings('pl', ['js.app.']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$raw = (string)@shell_exec('node ' . escapeshellarg($tmp . '.js') . ' ' . escapeshellarg($tmp . '.json') . ' 2>&1');
@unlink($tmp . '.js'); @unlink($tmp . '.json');
$js = json_decode($raw, true);
if (!is_array($js)) {
    check('node ran the real i18n.js', false, substr($raw, 0, 600));
} else {
    check('i18n.js on a Polish page: 1 plik, 2 pliki, 4 pliki, 5/12/14/21 plików, 22/24 pliki, 25 plików — the server\'s answers',
          $js['pl'] === array_values($files), $J($js['pl']));
    check('… a t.num() count, a count in Polish grouping, t.plural(), "1000+", the Info strip\'s word, a word that is not a count',
          $js['plNum'] === ['22 pliki', "2{$nbsp}251{$nbsp}367 plików", '3 pliki', '1 plik', '5 plików', '1000+ wyników', 'pliki',
                            str_replace(':n', '3', $pl['js.app.wl_too_many'])], $J($js['plNum']));
    check('… a word written into the page keeps the asked key and the count (said again by the switch)',
          ($js['keyed']['text'] ?? '') === '2 pliki' && ($js['keyed']['key'] ?? '') === 'js.app.files_many'
          && str_contains((string)($js['keyed']['args'] ?? ''), '"#n"'), $J($js['keyed']));
    check('… the live switch says it in English ("2 files", "1.27 GiB") and back in Polish ("2 pliki", "1,27 GiB")',
          ($js['swap'] ?? null) === [['2 files', '1.27 GiB'], ['2 pliki', '1,27 GiB']], $J($js['swap'] ?? null));
    check('… a language whose third form is the fallback\'s keeps its many-form ("3 файлов", 21 its one-form)',
          ($js['ru'] ?? null) === ['3 файлов', '21 файл', '5 файлов'], $J($js['ru'] ?? null));
    check('… an English page: "2 files", "1 file", t.plural(1) "1 file", "1.27 GiB"',
          ($js['en'] ?? null) === ['2 files', '1 file', '1 file', '1.27 GiB'], $J($js['en'] ?? null));
    // 5. sizes (UX-25)
    check('t.bytes() on a Polish page: "1,27 GiB", "1000 MiB", "512 B", "13,3 GiB", "—" — the page\'s decimal separator, no grouping',
          ($js['bytesPl'] ?? null) === ['1,27 GiB', '1000 MiB', '512 B', '13,3 GiB', '—'], $J($js['bytesPl'] ?? null));
    check('i18n.php\'s answer is a script that registers the dictionary under its id', ($js['served'] ?? false) === true);
}
// The three size formatters are the one rule now.
$jsSrc = fn(string $f): string => str_replace("\r\n", "\n", (string)@file_get_contents($root . '/assets/js/' . $f));
check('app.js, favourites.js and admin-common.js write sizes through t.bytes() — no toFixed() of their own',
      str_contains($jsSrc('app.js'), "function fmtBytesPub(n) {\n        return t.bytes(n);")
      && str_contains($jsSrc('favourites.js'), "function fmtBytes(n) {\n        return t.bytes(n);")
      && str_contains($jsSrc('admin-common.js'), "function fmtBytes(n) {\n        return t.bytes(n);"));

/* ══ 2. the dictionary as a file (PERF-9) ═══════════════════════════════════════════════════════════════════ */
$pre = ['js.common.', 'js.app.'];
$h = langJsHash(langJsStrings('pl', $pre));
$ok = langJsServeRequest(['l' => 'pl', 'p' => 'js.common.,js.app.', 'v' => $h], ['REQUEST_METHOD' => 'GET']);
$hdr = implode("\n", $ok['headers']);
$prefix = '(window.I18N_DICT=window.I18N_DICT||{})["pl.' . $h . '"]=';
$bodyJson = str_starts_with($ok['body'], $prefix) ? json_decode(rtrim(substr($ok['body'], strlen($prefix)), ";\n"), true) : null;
check('i18n.php answers the dictionary as a script: its id, the strings, a JavaScript type, nosniff',
      $ok['status'] === 200 && $bodyJson === langJsStrings('pl', $pre) && str_contains($hdr, 'Content-Type: text/javascript')
      && str_contains($hdr, 'X-Content-Type-Options: nosniff'), substr($ok['body'], 0, 120));
check('… cached for a year and immutable while v is the content\'s hash', str_contains($hdr, 'Cache-Control: public, max-age=31536000, immutable'));
$stale = langJsServeRequest(['l' => 'pl', 'p' => 'js.common.,js.app.', 'v' => '0000000000000000'], ['REQUEST_METHOD' => 'GET']);
check('… not cached at all when v is not (an old page\'s address): the same, current, words',
      $stale['status'] === 200 && in_array('Cache-Control: no-store', $stale['headers'], true) && $stale['body'] === $ok['body']);
$bad = [
    'an unknown language' => langJsServeRequest(['l' => 'xx', 'p' => 'js.'], ['REQUEST_METHOD' => 'GET'])['status'],
    'a language that is not a code' => langJsServeRequest(['l' => '../en', 'p' => 'js.'], ['REQUEST_METHOD' => 'GET'])['status'],
    'a prefix with markup' => langJsServeRequest(['l' => 'pl', 'p' => 'js.<b>'], ['REQUEST_METHOD' => 'GET'])['status'],
    'no prefix' => langJsServeRequest(['l' => 'pl'], ['REQUEST_METHOD' => 'GET'])['status'],
    'an array for a prefix' => langJsServeRequest(['l' => 'pl', 'p' => ['js.']], ['REQUEST_METHOD' => 'GET'])['status'],
    '41 prefixes' => langJsServeRequest(['l' => 'pl', 'p' => implode(',', array_fill(0, 41, 'js.'))], ['REQUEST_METHOD' => 'GET'])['status'],
    'a POST' => langJsServeRequest(['l' => 'pl', 'p' => 'js.'], ['REQUEST_METHOD' => 'POST'])['status'],
];
check('… and refuses the rest (404, 405 for a POST); a HEAD is a GET without the body',
      $bad === ['an unknown language' => 404, 'a language that is not a code' => 404, 'a prefix with markup' => 404, 'no prefix' => 404,
                'an array for a prefix' => 404, '41 prefixes' => 404, 'a POST' => 405]
      && langJsServeRequest(['l' => 'pl', 'p' => 'js.'], ['REQUEST_METHOD' => 'HEAD'])['status'] === 200, $J($bad));
// The page's bridge: no strings inline, the file named by its content.
relang(['default_language' => 'pl']);
$bridge = langJsBridge('/', LANG_JS_PUBLIC);
$metaJson = preg_match('~<script[^>]*id="i18n-data" type="application/json">(.*?)</script>~s', $bridge, $mm) ? json_decode($mm[1], true) : null;
$wantH = langJsHash(langJsBundle(LANG_JS_PUBLIC)['strings']);
check('the page carries only what names its dictionary: the language, the swap flag, the file\'s id and address — no strings',
      is_array($metaJson) && !isset($metaJson['strings']) && ($metaJson['lang'] ?? '') === 'pl' && ($metaJson['id'] ?? '') === 'pl.' . $wantH
      && str_starts_with((string)($metaJson['src'] ?? ''), '/i18n.php?') && str_contains((string)$metaJson['src'], 'v=' . $wantH), $J($metaJson));
parse_str((string)parse_url((string)($metaJson['src'] ?? ''), PHP_URL_QUERY), $q);
$served = langJsServeRequest($q, ['REQUEST_METHOD' => 'GET']);
check('… and the address it names is answered fresh (immutable) with the very strings the page would have carried',
      in_array('Cache-Control: public, max-age=31536000, immutable', $served['headers'], true)
      && str_contains($served['body'], (string)json_encode(langJsBundle(LANG_JS_PUBLIC)['strings'], LANG_JS_JSON)));
$at = strpos($bridge, 'i18n.php?');
check('… the file is loaded before i18n.js, which reads it; the bridge is small (it was 52 KB on the home page)',
      $at !== false && $at < strpos($bridge, 'assets/js/i18n.js') && strlen($bridge) < 2000, (string)strlen($bridge));
relang([]);
$rootSrc = (string)@file_get_contents($root . '/i18n.php');
check('i18n.php exists at the root, starts no session and opens no database (only includes/lang.php)',
      $rootSrc !== '' && !str_contains($rootSrc, 'session_start') && !str_contains($rootSrc, 'getDb')
      && !str_contains($rootSrc, "includes/functions.php") && str_contains($rootSrc, "require_once __DIR__ . '/includes/lang.php';"));
$i18nJs = str_replace("\r\n", "\n", (string)@file_get_contents($root . '/assets/js/i18n.js'));
$swapJs = str_replace("\r\n", "\n", (string)@file_get_contents($root . '/assets/js/lang-swap.js'));
check('i18n.js reads the file\'s dictionary (I18N_DICT[id]); lang-swap.js loads the other language\'s before it swaps',
      str_contains($i18nJs, 'if (data.id && all[data.id]) return all[data.id];') && str_contains($swapJs, 'function dictReady(meta) {')
      && str_contains($swapJs, 'return dictReady(bundle).then('));
$deployPy = (string)@file_get_contents(dirname($root) . '/deploy/deploy.py');
if ($deployPy !== '') {
    check('i18n.php is in deploy/deploy.py\'s list of what is shipped (a page would name a file the server does not have)',
          str_contains($deployPy, '"i18n.php"'));
} else {
    echo "SKIP i18n.php in deploy/deploy.py  -> no deploy/deploy.py beside the repository\n";
}

/* ══ 3. every key the server asks for is defined (QUAL-10) ══════════════════════════════════════════════════ */
$phpFiles = array_merge(glob($root . '/*.php') ?: [], glob($root . '/api/*.php') ?: [], glob($root . '/api/*/*.php') ?: [], glob($root . '/includes/*.php') ?: []);
$undef = [];
$asked = 0;
foreach ($phpFiles as $f) {
    $src = (string)@file_get_contents($f);
    // a literal key that is the whole argument (a key built with `.` is not a literal and cannot be checked here)
    preg_match_all("~\b(?:__|_h)\(\s*'([A-Za-z0-9_.]+)'\s*[,)]~", $src, $m1);
    preg_match_all("~\blangFor\(\s*[^,()]+(?:\([^()]*\))?\s*,\s*'([A-Za-z0-9_.]+)'\s*[,)]~", $src, $m2);
    preg_match_all("~\bplural\(\s*[^,()]+(?:\([^()]*\))?\s*,\s*'([A-Za-z0-9_.]+)'\s*[,)]~", $src, $m3);
    foreach (array_merge($m1[1], $m2[1]) as $key) {
        $asked++;
        if (!isset($en[$key])) $undef[] = basename($f) . ': ' . $key;
    }
    foreach ($m3[1] as $base) {
        $asked++;
        if (!isset($en[$base . '_one'], $en[$base . '_many'])) $undef[] = basename($f) . ': ' . $base . '_one/_many';
    }
}
$undef = array_values(array_unique($undef));
check('every literal __() / _h() / langFor() / plural() key in api/, includes/ and the root\'s PHP is in the dictionary (' . $asked . ' asked)',
      $undef === [] && $asked > 1000, implode(', ', array_slice($undef, 0, 10)));
check('… the two that were missing say their sentence: the deletion limits\' password question, the review queue\'s refusal',
      isset($en['api.settings.reauth_required_limits'], $pl['api.settings.reauth_required_limits'], $en['api.common.forbidden'], $pl['api.common.forbidden']));

/* ══ 4. one set of Polish terms (QUAL-19) ═══════════════════════════════════════════════════════════════════ */
// Every module, the Info page's (tools/lang_src.d/info.py) included since part E brought it in line.
$terms = ['admin as a person (administrator)' => '~\badmin(a|owi|em|ie|ów|i)\b~u', 'czarna lista (blacklista)' => '~\bczarn\w* list\w*~u',
          'wybierak (selektor)' => '~\bwybierak\w*~u', 'three full stops (…)' => '~\.\.\.~u'];
$termBad = [];
foreach ($pl as $k => $v) {
    foreach ($terms as $what => $rx) if (preg_match($rx, $v)) $termBad[] = $what . ': ' . $k;
    if (preg_match('~\bs?kas(uj|ow)\w*~u', $v) && preg_match('~\busu[nw]\w*~ui', $v)) $termBad[] = 'kasować beside usuwać: ' . $k;
}
check('Polish: administrator, blacklista, selektor, one-character ellipsis, one verb for deleting — in every module',
      $termBad === [], implode(' | ', array_slice($termBad, 0, 8)));
$youBad = [];
$youRx = '~(?<![\w\-/.])(ty|twój|twoja|twoje|twojego|twojej|twoim|twoich|twoimi|twoją|twoi|ciebie|cię|ci|tobie|tobą)(?![\w\-/])~u';
foreach ($pl as $k => $v) {
    if (!preg_match_all($youRx, $v, $mm, PREG_OFFSET_CAPTURE)) continue;
    foreach ($mm[1] as [$w, $at]) {
        if ($w === 'ci' && preg_match('~^\s*,?\s*(któr|co\b|sami\b|wszyscy\b)~u', substr($v, $at + 2))) continue;   // "those", not "you"
        $upTo = substr($v, 0, $at);
        $before = (string)preg_replace('~[ \x{00A0}]+$~u', '', $upTo);
        $b2 = trim((string)preg_replace('~(<[^>]+>|[„"\'(])+$~u', '', $before));
        $start = $before === '' || $b2 === '' || (preg_match('~[.!?…\n]$~u', $b2) && strlen($before) < strlen($upTo));
        if (!$start) { $youBad[] = $k . ' "' . $w . '"'; break; }
    }
}
check('Polish: the reader with a capital in the middle of a sentence too — Twój, Ciebie, Cię, Tobie (the mails\' rule)',
      $youBad === [], implode(', ', array_slice($youBad, 0, 10)));
$termsDoc = (string)@file_get_contents($root . '/tools/lang_src.d/common.py');
check('… the terms are written down where the words are (tools/lang_src.d/common.py)',
      str_contains($termsDoc, 'THE POLISH TERMS (1.74.0)') && str_contains($termsDoc, 'blacklista / whitelista') && str_contains($termsDoc, 'selektor'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
