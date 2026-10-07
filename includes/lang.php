<?php
/**
 * The interface language.
 *
 * Ported from TryHackX-Files, which has been running this design for a while, and kept
 * behaviourally identical: strings are a flat map of dotted keys in `lang/<code>.php`, a lookup
 * falls back from the active language to the built-in default and finally to the key itself, and
 * any file dropped into `lang/` is a language without a code change. What is different is the
 * shape — that project has a `Lang` class, this one has procedural includes, and a lone class in
 * the middle of them would be the odd file out.
 *
 * RESOLUTION ORDER, highest first:
 *   1. `?lang=xx`          — an explicit choice, remembered in a SESSION cookie
 *   2. the cookie          — that choice, for as long as the browser session lasts
 *   3. the signed-in user  — the account's saved language: the default that comes back
 *   4. `default_language`  — what the operator set for the site
 *   5. Accept-Language     — only when `language_auto` is on, and only within the offerable set
 *   6. English
 *
 * THREE LISTS, not one, and the difference matters:
 *   `enabled_languages`   which languages exist for visitors at all
 *   `switcher_languages`  which the header control offers
 *   `user_languages`      which a user may pick for their account, and which Accept-Language may
 *                         resolve to
 * A language kept out of the last two is still reachable by an explicit `?lang=` link: hiding a
 * control is not the same as withdrawing a translation. An empty list means "no restriction", which
 * is the behaviour from before any of this was configurable — so a list is only ever stored as a
 * positive allow-list, and never allowed to end up empty.
 */

/**
 * Native names for the codes the DROPDOWN offers. Curated on purpose: this feeds a list a person
 * scrolls, and two hundred entries is not a list, it is a search problem. A code that is not here
 * is still fine — langName() asks PHP's intl extension, and the install dialog asks the browser's
 * own ISO table, so a typed code is recognised either way.
 */
const LANG_NAMES = [
    'en' => 'English', 'pl' => 'Polski', 'de' => 'Deutsch', 'fr' => 'Français',
    'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands', 'pt' => 'Português',
    'ru' => 'Русский', 'uk' => 'Українська', 'cs' => 'Čeština', 'sk' => 'Slovenčina',
    'ja' => '日本語', 'zh' => '中文', 'ko' => '한국어', 'tr' => 'Türkçe', 'ar' => 'العربية',
    'sv' => 'Svenska', 'da' => 'Dansk', 'fi' => 'Suomi', 'no' => 'Norsk', 'hu' => 'Magyar',
    'ro' => 'Română', 'bg' => 'Български', 'el' => 'Ελληνικά', 'he' => 'עברית',
    'hi' => 'हिन्दी', 'id' => 'Bahasa Indonesia', 'vi' => 'Tiếng Việt', 'th' => 'ไทย',
    'hr' => 'Hrvatski', 'sr' => 'Српски', 'sl' => 'Slovenščina', 'bs' => 'Bosanski',
    'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'et' => 'Eesti', 'be' => 'Беларуская',
    'ca' => 'Català', 'eu' => 'Euskara', 'gl' => 'Galego', 'ga' => 'Gaeilge', 'cy' => 'Cymraeg',
    'is' => 'Íslenska', 'mk' => 'Македонски', 'sq' => 'Shqip', 'mt' => 'Malti', 'lb' => 'Lëtzebuergesch',
    'fa' => 'فارسی', 'ur' => 'اردو', 'bn' => 'বাংলা', 'ta' => 'தமிழ்', 'te' => 'తెలుగు',
    'ml' => 'മലയാളം', 'mr' => 'मराठी', 'pa' => 'ਪੰਜਾਬੀ', 'gu' => 'ગુજરાતી', 'ne' => 'नेपाली',
    'si' => 'සිංහල', 'my' => 'မြန်မာ', 'km' => 'ខ្មែរ', 'lo' => 'ລາວ', 'ms' => 'Bahasa Melayu',
    'tl' => 'Filipino', 'ka' => 'ქართული', 'hy' => 'Հայերեն', 'az' => 'Azərbaycan',
    'kk' => 'Қазақ', 'uz' => 'Oʻzbek', 'mn' => 'Монгол', 'sw' => 'Kiswahili', 'am' => 'አማርኛ',
    'af' => 'Afrikaans', 'zu' => 'isiZulu', 'eo' => 'Esperanto', 'la' => 'Latina',
];

/**
 * The display name of ANY language code — the table first, then PHP's intl extension, which
 * carries the whole ISO list. Native form (`de` → "Deutsch"), because a switcher is read by the
 * person who speaks the language, not by the operator. Falls back to the uppercased code, which
 * is honest: it says "no name known" rather than pretending.
 */
function langName(string $code): string {
    $code = strtolower($code);
    if (isset(LANG_NAMES[$code])) return LANG_NAMES[$code];
    if (class_exists('Locale') && preg_match('/^[a-z]{2,3}$/', $code)) {
        try {
            $n = (string)\Locale::getDisplayLanguage($code, $code);
            // intl answers with the code itself when it has no name; that is not a name.
            if ($n !== '' && strtolower($n) !== $code) return $n;
        } catch (\Throwable $e) { /* fall through */ }
    }
    return strtoupper($code);
}

/**
 * The languages that ship with the panel. They are always offered and can never be switched off or
 * deleted: every other translation falls back to English, and a tracker with no language left has
 * nothing to render.
 */
const LANG_BUILT_IN = ['en', 'pl'];

/** The end of every fallback chain. */
const LANG_FALLBACK = 'en';

const LANG_COOKIE = 'lang';
const LANG_DIR = __DIR__ . '/../lang';

/** Per-request state. A language file is a `require`, so each is read at most once. */
$GLOBALS['__lang'] = ['current' => null, 'strings' => [], 'fallback' => [],
                      'available' => null, 'lists' => [], 'loaded' => []];

/** Every installed language as [code => display name], English first. */
function langAvailable(): array {
    if ($GLOBALS['__lang']['available'] !== null) return $GLOBALS['__lang']['available'];
    $out = [];
    foreach (glob(LANG_DIR . '/*.php') ?: [] as $file) {
        $code = strtolower(basename($file, '.php'));
        if (preg_match('/^[a-z]{2,3}$/', $code)) $out[$code] = langName($code);
    }
    // Even with the directory missing or unreadable the fallback has to exist, or every lookup
    // below turns into "the key itself" and the site renders as a list of dotted identifiers.
    if (!isset($out[LANG_FALLBACK])) $out[LANG_FALLBACK] = LANG_NAMES[LANG_FALLBACK];
    $ordered = [LANG_FALLBACK => $out[LANG_FALLBACK]];
    foreach ($out as $k => $v) if ($k !== LANG_FALLBACK) $ordered[$k] = $v;
    return $GLOBALS['__lang']['available'] = $ordered;
}

/** Is this code installed at all? (Ignores the enabled flag — for the management screen.) */
function langInstalled(string $code): bool {
    return isset(langAvailable()[$code]);
}

/**
 * One of the three allow-lists, resolved against what is installed.
 *
 * An empty setting means "everything", and a setting whose every entry has since been deleted means
 * the same — a surface with no language to offer is never a useful answer.
 */
function langList(array $cfg, string $settingKey): array {
    $cache =& $GLOBALS['__lang']['lists'];
    if (isset($cache[$settingKey])) return $cache[$settingKey];

    $all = $settingKey === 'enabled_languages' ? langAvailable() : langEnabled($cfg);
    $raw = trim((string)($cfg[$settingKey] ?? ''));
    if ($raw === '') return $cache[$settingKey] = $all;

    $allow = array_filter(array_map('trim', explode(',', $raw)));
    if ($settingKey === 'enabled_languages') $allow = array_merge($allow, LANG_BUILT_IN);

    $out = [];
    foreach ($all as $code => $name) if (in_array($code, $allow, true)) $out[$code] = $name;
    return $cache[$settingKey] = ($out ?: $all);
}

/** Languages offered to visitors at all. */
function langEnabled(array $cfg): array { return langList($cfg, 'enabled_languages'); }
/** Languages the header switcher shows. */
function langForSwitcher(array $cfg): array { return langList($cfg, 'switcher_languages'); }
/** Languages a user may set on their account, and that Accept-Language may resolve to. */
function langForUsers(array $cfg): array { return langList($cfg, 'user_languages'); }

/** A language a visitor may actually select. */
function langSupported(array $cfg, string $code): bool { return isset(langEnabled($cfg)[$code]); }

/** Forget the cached lists — after the admin edits one, or installs a language. */
function langInvalidate(): void {
    $GLOBALS['__lang']['available'] = null;
    $GLOBALS['__lang']['lists'] = [];
}

/**
 * Resolve and load the active language. Safe to call repeatedly; only the first call does the work.
 * Must run before output, because it may set the language cookie.
 */
function langInit(array $cfg, ?string $userLanguage = null): void {
    if ($GLOBALS['__lang']['current'] !== null) return;

    $lang = null;

    // An explicit ?lang= wins and is remembered, so a link into the site in one language keeps it.
    if (isset($_GET['lang']) && is_string($_GET['lang']) && langSupported($cfg, strtolower($_GET['lang']))) {
        $lang = strtolower($_GET['lang']);
        if (!headers_sent()) {
            // A SESSION cookie, on purpose: the switcher is a temporary choice for this visit. It
            // dies with the browser, and the account's saved language (or the site default) is
            // what comes back. No `expires` is what makes it a session cookie.
            // One Secure policy for every cookie the site sets — cookieBaseParams() lives in
            // includes/functions.php, which requires THIS file, so anything that calls langInit()
            // must load functions.php (every entry point does; the two suites that exercise
            // langInit() were given the require when this landed). No `expires` key is what keeps
            // this a session cookie.
            setcookie(LANG_COOKIE, $lang, cookieBaseParams($cfg, [
                'httponly' => false,   // no secret in it, and the switcher reads it client-side
            ]));
        }
        $_COOKIE[LANG_COOKIE] = $lang;
    }

    // The cookie — an explicit click on the switcher, for this browser session — outranks the
    // account's saved language while it exists: somebody who just chose Polish on this page meant
    // this page in Polish, whatever their account says. The account setting is the DEFAULT that
    // comes back when the browser closes and the session cookie is gone.
    if (!$lang && isset($_COOKIE[LANG_COOKIE]) && is_string($_COOKIE[LANG_COOKIE])
        && langSupported($cfg, strtolower($_COOKIE[LANG_COOKIE]))) {
        $lang = strtolower($_COOKIE[LANG_COOKIE]);
    }
    if (!$lang && $userLanguage && langSupported($cfg, $userLanguage)) $lang = $userLanguage;
    if (!$lang) {
        $default = strtolower(trim((string)($cfg['default_language'] ?? LANG_FALLBACK)));
        if ($default !== '' && $default !== 'auto' && langSupported($cfg, $default)) $lang = $default;
        // `auto` is the operator saying "let the browser decide", so it skips straight past the
        // site default to Accept-Language rather than needing a second setting to mean the same.
        if ($default === 'auto' || ($cfg['language_auto'] ?? '0') === '1') {
            $lang = $lang ?: langFromAcceptLanguage($cfg);
        }
    }

    $current = $lang ?: LANG_FALLBACK;
    $GLOBALS['__lang']['current']  = $current;
    $GLOBALS['__lang']['strings']  = langLoad($current);
    $GLOBALS['__lang']['fallback'] = $current === LANG_FALLBACK
        ? $GLOBALS['__lang']['strings'] : langLoad(LANG_FALLBACK);
    // Recorded here, and nowhere else, because this is the one function every entry point calls
    // with $cfg in hand before it renders. langJsBridge() has no $cfg of its own, and threading one
    // through nine call sites to carry a single boolean would be nine chances to forget.
    $GLOBALS['__lang']['swap'] = langSwapEnabled($cfg);
}

/**
 * The in-place language switch is on (`lang_swap_enabled`). ONE reader with the schema's default, ON since 1.41.0
 * (1.74.0, QUAL-2): this file said '0' for a missing row and the Settings field said '0' too, while the schema seeds
 * '1' — a row deleted by hand turned the feature off and showed it off, against what a fresh install does.
 */
function langSwapEnabled(array $cfg): bool { return (string)($cfg['lang_swap_enabled'] ?? '1') === '1'; }

/** The active code. Falls back to English before langInit() has run. */
function langCurrent(): string { return $GLOBALS['__lang']['current'] ?? LANG_FALLBACK; }

/**
 * The languages whose numbers leave four digits whole — "1234", but "12 345" (CLDR's minimumGroupingDigits 2, the data
 * the browser's Intl.NumberFormat carries; read from it, 2026-10). PHP's NumberFormatter does not apply that rule, so
 * langNumber() does.
 */
const LANG_NUMBER_MIN2 = ['pl', 'es', 'it', 'hu', 'bg', 'sl', 'lv', 'et', 'be', 'sq', 'ka', 'hy', 'la'];

/**
 * A COUNT AS THE PAGE'S LANGUAGE WRITES IT (1.73.1): "2,251,367" on an English page, "2 251 367" on a Polish one —
 * character for character what the browser's Intl.NumberFormat gives for that language, which is what the scripts
 * write when they refresh the same number (t.num(), assets/js/i18n.js): the server's render and the script's refresh
 * never differ. Until 1.73.1 the server grouped every page the English way (number_format()) and the scripts by the
 * BROWSER's language (toLocaleString() with no locale) — a Polish browser showed "2 251 367" on the English page, and
 * the Polish page kept the English commas.
 *
 * Intl's rules, not number_format()'s: English groups with a comma from four digits on; Polish with a NO-BREAK space
 * (U+00A0) and leaves four digits whole ("1234", "12 345"). A count is a whole number — anything else is rounded
 * (half away from zero, as round() does). Another installed language asks ICU (PHP's intl, the data a browser carries
 * too) with the same four-digit rule where CLDR gives it; without intl, the English rule. Only counts: a date, a size
 * with decimals ("13.3 GiB") and the units are not this function's ("54 sec" stays — the owner: "w Polsce też używa
 * się s / sec").
 */
function langNumber(int|float|string $n, ?string $code = null): string {
    $v = (int)round((float)$n);
    $code = strtolower($code ?? langCurrent());
    if ($code === 'en') return number_format($v);
    $whole = abs($v) < 10000 && in_array($code, LANG_NUMBER_MIN2, true);
    if ($code === 'pl') return $whole ? (string)$v : number_format($v, 0, '', "\u{00A0}");
    static $icu = [];
    if (!array_key_exists($code, $icu)) {
        $icu[$code] = null;
        if (class_exists('NumberFormatter') && preg_match('/^[a-z]{2,3}$/', $code)) {
            try {
                $f = \NumberFormatter::create($code, \NumberFormatter::DECIMAL);
                if ($f) { $f->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0); $icu[$code] = $f; }
            } catch (\Throwable $e) { /* the English rule below */ }
        }
    }
    $f = $icu[$code];
    if (!$f) return number_format($v);
    if (!$whole) return (string)$f->format($v);
    $f->setAttribute(\NumberFormatter::GROUPING_USED, 0);
    $s = (string)$f->format($v);
    $f->setAttribute(\NumberFormatter::GROUPING_USED, 1);
    return $s;
}

/** Read one language file, once. */
function langLoad(string $code): array {
    if (isset($GLOBALS['__lang']['loaded'][$code])) return $GLOBALS['__lang']['loaded'][$code];
    $out = [];
    if (preg_match('/^[a-z]{2,3}$/', $code)) {
        $file = LANG_DIR . '/' . $code . '.php';
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) $out = $data;
        }
    }
    return $GLOBALS['__lang']['loaded'][$code] = $out;
}

/**
 * The first browser-requested language the site auto-switches to, honouring q weights.
 *
 * Limited to langForUsers(): this is a choice made FOR a visitor, so it has to stay inside the set
 * the operator marked as offerable, not merely inside what happens to be installed.
 */
function langFromAcceptLanguage(array $cfg): ?string {
    $offerable = langForUsers($cfg);
    $header = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $candidates = [];
    foreach (explode(',', $header) as $position => $part) {
        $segments = array_map('trim', explode(';', $part));
        $tag = strtolower((string)array_shift($segments));
        $quality = 1.0;
        foreach ($segments as $parameter) {
            if (preg_match('/\Aq\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/iD', $parameter, $m)) {
                $quality = (float)$m[1];
                break;
            }
        }
        if ($quality <= 0.0) continue;
        $code = $tag === '*' ? '' : substr($tag, 0, 2);
        if ($code !== '' && isset($offerable[$code])) {
            $candidates[] = ['code' => $code, 'q' => $quality, 'pos' => $position];
        }
    }
    usort($candidates, function (array $a, array $b): int {
        $q = $b['q'] <=> $a['q'];
        return $q !== 0 ? $q : $a['pos'] <=> $b['pos'];
    });
    return $candidates ? $candidates[0]['code'] : null;
}

/**
 * Translate $key, replacing `:name` placeholders. Returns the RAW string — use _h() for HTML.
 *
 * A miss returns the key. That is deliberate: a blank page tells nobody which string is missing,
 * and `whitelist.form.submit` on a button says exactly where to look.
 */
/**
 * English when nobody chose a language: the CLI (janitor, worker, the tests) never runs langInit(),
 * and a string built there — an API message a test compares, a line the janitor logs — must still
 * be the English text, not the key. Loads the fallback once and only when needed.
 */
function langEnsure(): void {
    // `strings` starts as [] (not null), and `current` is what langInit() sets — so "nobody chose a
    // language yet" is: no current AND nothing loaded. `current` is left alone on purpose: setting
    // it here would make a later langInit() return early and skip the cookie and the account.
    if ($GLOBALS['__lang']['current'] !== null || !empty($GLOBALS['__lang']['strings'])) return;
    $GLOBALS['__lang']['strings']  = langLoad(LANG_FALLBACK);
    $GLOBALS['__lang']['fallback'] = $GLOBALS['__lang']['strings'];
}

/**
 * THE FORM OF A WORD AFTER A NUMBER, BY THE LANGUAGE'S OWN RULE (1.74.0).
 *
 * The dictionary had two forms of a counted word — `X_one` ("1 plik") and `X_many` (":n plików") — and the code chose
 * between them by n === 1. Polish needs three: 1 plik, 2–4 pliki (and 22–24, 32–34…, but 12–14 plików), 5+ plików;
 * "2 plików" stood in the search results' chips, the Info panel and the panel's lists. So a family may have a third
 * form, `X_few`, and the choice is made HERE, where the word is looked up, by the rule of the language it is said in
 * — every caller that asks for `X_many` with a count in `n` gets the right one, in every file, without being changed:
 *
 *   · the CATEGORY is CLDR's (one / two / few / many / other) for that language and that number — PHP's intl
 *     (MessageFormatter, the data the browser's Intl.PluralRules carries too) when it is there, else a table of the
 *     languages that need more than two forms (langPluralCategory());
 *   · `few` (or `two`) takes `X_few` — only where the language's OWN dictionary has the whole family (`X_one`, `X_few`,
 *     `X_many`): a language that never wrote a third form keeps its `X_many`, and never borrows the English fallback's;
 *   · `one` takes `X_one` when the count is 1 or the one-form says the count (":n"): Russian's 21 is "one", but a
 *     one-form that reads "1 file" must not be said for 21;
 *   · anything else keeps what was asked. A key that is not a counted word (`too_many`, a family without `X_one`) is
 *     never touched, and nor is a count that is not a whole number written as one (n = "1,000+", "1.5").
 * The client does the same in assets/js/i18n.js (say()), so a word a script writes and its refresh agree.
 */
function langPluralCategory(string $code, int|float $n): string {
    $code = strtolower($code);
    if (is_int($n) || (is_float($n) && floor($n) === $n && abs($n) < 1e15)) {
        $i = abs((int)$n);
        static $fmt = [];
        if (class_exists('MessageFormatter') && preg_match('/^[a-z]{2,3}$/', $code)) {
            if (!array_key_exists($code, $fmt)) {
                try {
                    $fmt[$code] = \MessageFormatter::create($code, '{0,plural,zero{zero} one{one} two{two} few{few} many{many} other{other}}') ?: null;
                } catch (\Throwable $e) { $fmt[$code] = null; }
            }
            if ($fmt[$code]) {
                $c = $fmt[$code]->format([$i]);
                if (is_string($c) && in_array($c, ['zero', 'one', 'two', 'few', 'many', 'other'], true)) return $c;
            }
        }
        $m10 = $i % 10; $m100 = $i % 100;
        $fewSlav = $m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14);
        switch ($code) {
            case 'pl': return $i === 1 ? 'one' : ($fewSlav ? 'few' : 'many');
            case 'ru': case 'uk': case 'be':
                return ($m10 === 1 && $m100 !== 11) ? 'one' : ($fewSlav ? 'few' : 'many');
            case 'hr': case 'sr': case 'bs':
                return ($m10 === 1 && $m100 !== 11) ? 'one' : ($fewSlav ? 'few' : 'other');
            case 'cs': case 'sk': return $i === 1 ? 'one' : (($i >= 2 && $i <= 4) ? 'few' : 'other');
            default: return $i === 1 ? 'one' : 'other';
        }
    }
    return 'other';
}

/** A count as a number, from what a caller passes as `n`: a number, digits, or digits grouped ("2 251 367", "1,234"). */
function langCountOf(mixed $v): int|float|null {
    if (is_int($v) || is_float($v)) return $v;
    if (!is_string($v)) return null;
    // Group separators only: a space of any width, and a comma before a group of three ("1,5" is not 1 234's kind).
    $s = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($v));
    $s = (string)preg_replace('/(?<=\d),(?=\d{3}(?:,|$))/', '', $s);
    return preg_match('/^-?\d{1,15}$/', $s) ? (int)$s : null;
}

/**
 * The key a counted word is said by (see langPluralCategory()): `$key` as asked, or its family's `_few` / `_one` by the
 * language's rule. `$own` is the language's own dictionary (never merged with the fallback).
 */
function langPluralKey(string $key, array $params, string $code, array $own): string {
    if (!isset($params['n']) || !str_ends_with($key, '_many')) return $key;
    $n = langCountOf($params['n']);
    if ($n === null) return $key;
    $base = substr($key, 0, -5);
    if (!isset($own[$base . '_one'])) return $key;              // not a counted word's family in this language
    $cat = langPluralCategory($code, $n);
    if (($cat === 'few' || $cat === 'two') && isset($own[$base . '_few'])) return $base . '_few';
    if ($cat === 'one' && ($n == 1 || str_contains((string)$own[$base . '_one'], ':n'))) return $base . '_one';
    return $key;
}

function __(string $key, array $params = []): string {
    langEnsure();
    if ($params) $key = langPluralKey($key, $params, langCurrent(), $GLOBALS['__lang']['strings'] ?? []);
    $s = $GLOBALS['__lang']['strings'][$key] ?? $GLOBALS['__lang']['fallback'][$key] ?? $key;
    if ($params) {
        $repl = [];
        foreach ($params as $k => $v) $repl[':' . $k] = (string)$v;
        $s = strtr($s, $repl);
    }
    return $s;
}

/**
 * A counted word, its form chosen by the language (1.74.0): `plural(3, 'js.app.files')` is "3 pliki" on a Polish page,
 * "3 files" on an English one — `$base . '_one'` for 1, else `$base . '_many'`, which the lookup refines to `_few` /
 * `_one` by the language's rule (langPluralKey()). `n` is the count unless the caller gives it written its own way
 * (langNumber()). `$lang`: say it in that language (a mail to a member) instead of the page's.
 */
function plural(int|float $n, string $base, array $params = [], ?string $lang = null): string {
    if (!array_key_exists('n', $params)) $params['n'] = $n;
    $key = $base . ($n == 1 ? '_one' : '_many');
    return $lang === null ? __($key, $params) : langFor($lang, $key, $params);
}

/** Translate and HTML-escape — the default for template output. */
function _h(string $key, array $params = []): string {
    return htmlspecialchars(__($key, $params), ENT_QUOTES, 'UTF-8');
}

/** Is this key known at all? For a key that came from outside, where a miss must show nothing. */
function langHas(string $key): bool {
    langEnsure();
    return isset($GLOBALS['__lang']['strings'][$key]) || isset($GLOBALS['__lang']['fallback'][$key]);
}

/**
 * Translate for an explicit language without touching the request's own.
 *
 * Mail sent to a user has to speak that user's language, not whichever one the process that
 * happened to send it was rendering.
 */
function langFor(?string $code, string $key, array $params = []): string {
    $code = strtolower(trim((string)$code));
    if (!preg_match('/^[a-z]{2,3}$/', $code) || !langInstalled($code)) $code = LANG_FALLBACK;
    // A counted word in THAT language's form (1.74.0, langPluralKey()): a mail's "2 pliki" for a Polish member.
    if ($params) $key = langPluralKey($key, $params, $code, langLoad($code));
    $s = langLoad($code)[$key] ?? langLoad(LANG_FALLBACK)[$key] ?? $key;
    if ($params) {
        $repl = [];
        foreach ($params as $k => $v) $repl[':' . $k] = (string)$v;
        $s = strtr($s, $repl);
    }
    return $s;
}

/** The active strings with the fallback filled in — for the client-side bridge. */
function langAll(): array {
    return ($GLOBALS['__lang']['strings'] ?? []) + ($GLOBALS['__lang']['fallback'] ?? []);
}

/**
 * The strings the browser scripts need — every key under `js.` — with the fallback filled in.
 *
 * Only that prefix: the whole dictionary is 1100+ strings and the scripts use a few dozen, so
 * sending everything would put the size of the templates' text on every page for nothing. A script
 * string lives under `js.` BY DEFINITION; a template string the script also needs is duplicated
 * under `js.` rather than widening the bundle.
 */
function langJsBundle(array $prefixes = ['js.']): array {
    $out = [];
    foreach (langAll() as $k => $v) {
        foreach ($prefixes as $p) { if (str_starts_with($k, $p)) { $out[$k] = (string)$v; break; } }
    }
    // `swap` travels with the bundle rather than on <body>, so the page ALREADY answers "which
    // language is this and may it be swapped" in one place that lang-swap.js re-reads after a swap.
    $b = ['lang' => langCurrent(), 'strings' => $out, 'swap' => (bool)($GLOBALS['__lang']['swap'] ?? false)];
    // The third forms this language did not write itself (1.74.0): in the bundle they are the English fallback's,
    // and a counted word must never take one of those for its "2–4" form (assets/js/i18n.js, langPluralKey()).
    $own = $GLOBALS['__lang']['strings'] ?? [];
    $few = [];
    foreach ($out as $k => $_) if (str_ends_with((string)$k, '_few') && !isset($own[$k])) $few[] = (string)$k;
    if ($few) $b['few'] = $few;
    return $b;
}

/**
 * THE SCRIPTS' DICTIONARY AS A FILE (1.74.0). Every page carried its whole `js.*` bundle inline — 52 KB of a 75 KB home
 * page (15 KB of 20 compressed), on every view and never cached (the HTML is no-store). It is a file now, one per
 * language and set of prefixes, named by its content: `i18n.php?l=pl&p=<prefixes>&v=<hash>` answers
 * `(window.I18N_DICT=window.I18N_DICT||{})["pl.<hash>"]=<the strings>;`, cached for a year and immutable while `v` is
 * that content's hash (a new dictionary is a new address), not cached at all when it is not. The page keeps only a
 * few words about it inline (#i18n-data: the language, whether the swap is on, the file's id and address) and loads
 * the file before i18n.js reads it; the live language switch loads the other language's file the same way — the same
 * address a page in that language asks for, so it is cached across them.
 */
const LANG_JS_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/** Every string under the prefixes in a language, the fallback filled in — what one page's scripts read. */
function langJsStrings(string $code, array $prefixes): array {
    $out = [];
    foreach (langLoad($code) + langLoad(LANG_FALLBACK) as $k => $v) {
        foreach ($prefixes as $p) { if (str_starts_with((string)$k, $p)) { $out[$k] = (string)$v; break; } }
    }
    return $out;
}

/** The content's name: 16 hex of its SHA-256, as the file writes it. */
function langJsHash(array $strings): string {
    return substr(hash('sha256', (string)json_encode($strings, LANG_JS_JSON)), 0, 16);
}

/** The prefixes of an address (`p=js.common.,js.app.`), or null when they are not dictionary prefixes. */
function langJsPrefixes(mixed $p): ?array {
    if (!is_string($p) || $p === '' || strlen($p) > 1000) return null;
    $list = explode(',', $p);
    if (count($list) > 40) return null;
    foreach ($list as $one) if (!preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)*\.?$/', $one)) return null;
    return $list;
}

/**
 * What `i18n.php` answers (the file only sends it): status, headers and body. GET or HEAD; an installed language;
 * well-formed prefixes. Nothing here reads a session, a cookie or the database — a dictionary is the same for everyone.
 */
function langJsServeRequest(array $get, array $server): array {
    $js = ['Content-Type: text/javascript; charset=utf-8', 'X-Content-Type-Options: nosniff'];
    $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') return ['status' => 405, 'headers' => array_merge($js, ['Allow: GET, HEAD', 'Cache-Control: no-store']), 'body' => ''];
    $code = is_string($get['l'] ?? null) ? strtolower($get['l']) : '';
    $prefixes = langJsPrefixes($get['p'] ?? null);
    if (!preg_match('/^[a-z]{2,3}$/', $code) || !langInstalled($code) || $prefixes === null) {
        return ['status' => 404, 'headers' => array_merge($js, ['Cache-Control: no-store']), 'body' => "/* no such dictionary */\n"];
    }
    $strings = langJsStrings($code, $prefixes);
    $json = (string)json_encode($strings, LANG_JS_JSON);
    $hash = substr(hash('sha256', $json), 0, 16);
    $fresh = is_string($get['v'] ?? null) && hash_equals($hash, (string)$get['v']);
    $headers = array_merge($js, [$fresh ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: no-store']);
    $body = '(window.I18N_DICT=window.I18N_DICT||{})[' . json_encode($code . '.' . $hash) . ']=' . $json . ";\n";
    return ['status' => 200, 'headers' => $headers, 'body' => $body];
}

/**
 * The prefixes the PUBLIC scripts use (app.js, captcha.js, stats-timeline.js) — see langJsBridge().
 * `js.media.` is the picture and cover editor (assets/js/media-editor.js, 1.63.0); `js.bio.` the
 * profile description's editor (assets/js/profile-bio.js, 1.69.0); `js.votes.` the likes / ratings
 * table on the account page and the profile (assets/js/favourites.js, 1.69.0); `js.descs.` the table of
 * descriptions a member wrote beside it (assets/js/favourites.js, 1.70.0); `js.report.` the Report button and its
 * box on a comment, a description and a shout (assets/js/reports.js, 1.71.0).
 * Three of the SERVER's own sentences ride along (1.73.0), so what a script shows from an answer follows the live
 * language switch like its own words: `api.antispam.` the anti-spam layer's waits (assets/js/antispam.js counts down
 * in the dictionary's sentence, by the key its answer names), `api.rep.` why a reader may not rate and
 * `api.index.source_auto_` where an importer's link came from (the Info panel finds them back with t.find()).
 */
const LANG_JS_PUBLIC = ['js.common.', 'js.app.', 'js.captcha.', 'js.timeline.', 'js.fav.', 'js.lists.', 'js.pm.', 'js.people.', 'js.sec.', 'js.sounds.', 'js.shout.', 'js.media.', 'js.bio.', 'js.votes.', 'js.descs.', 'js.who.', 'js.comments.', 'js.report.', 'js.antispam.',
    'api.antispam.', 'api.rep.', 'api.index.source_auto_'];

/**
 * The `<script>` pair that puts the bundle and the t() helper on a page — before any other script.
 *
 * $prefixes narrows the bundle: the panel's scripts need every `js.*` string (about 1 900 of them),
 * a public page needs the four areas its own scripts read, and shipping the panel's dictionary to
 * every visitor would have cost more than the page. Default is everything, for the panel.
 */
function langJsBridge(string $baseUrl, ?array $prefixes = null): string {
    $prefixes = array_values($prefixes ?? ['js.']);
    $bundle = langJsBundle($prefixes);
    // The dictionary itself is a file (1.74.0, see langJsServeRequest()): inline only what names it — the language,
    // the swap flag, the file's id (language and content hash, the key it registers itself under) and its address.
    $hash = langJsHash($bundle['strings']);
    $src = $baseUrl . 'i18n.php?' . http_build_query(['l' => $bundle['lang'], 'p' => implode(',', $prefixes), 'v' => $hash]);
    $meta = ['lang' => $bundle['lang'], 'swap' => $bundle['swap'], 'id' => $bundle['lang'] . '.' . $hash, 'src' => $src];
    if (!empty($bundle['few'])) $meta['few'] = $bundle['few'];
    $json = json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ver = function_exists('assetVer') ? assetVer('assets/js/i18n.js') : '';
    $swapVer = function_exists('assetVer') ? assetVer('assets/js/lang-swap.js') : '';
    $base = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
    // lang-swap.js rides along with the bridge because it needs exactly what the bridge provides —
    // the bundle node and t() — and because every page that has a switcher has a bridge. It keeps
    // the reader's place on a full reload even when the in-place swap is off, so it is not
    // conditional on the setting. The dictionary's file comes first: i18n.js reads it as it loads.
    return '<script' . nonceAttr() . ' id="i18n-data" type="application/json">' . $json . '</script>' . "
"
         . '    <script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>' . "
"
         . '    <script src="' . $base . 'assets/js/i18n.js' . $ver . '"></script>' . "
"
         . '    <script src="' . $base . 'assets/js/lang-swap.js' . $swapVer . '"></script>';
}

/**
 * How complete a translation is, measured against English.
 *
 * Against English and not against the largest file: "coverage" has to mean "how much of what the
 * app asks for is answered", and English is what the app falls back to for everything else.
 */
function langCoverage(string $code): array {
    $base = langLoad(LANG_FALLBACK);
    $mine = langLoad($code);
    $total = count($base);
    $have = 0;
    foreach ($base as $k => $_) if (isset($mine[$k]) && trim((string)$mine[$k]) !== '') $have++;
    return ['strings' => count($mine), 'total' => $total,
            'percent' => $total > 0 ? (int)round($have / $total * 100) : 0,
            'missing' => max(0, $total - $have)];
}

/**
 * The tags a CONTRIBUTED translation may carry. Nothing else, and no attribute but an <a>'s href.
 *
 * Some three hundred templates print __() unescaped, on purpose: the shipped strings carry <strong>,
 * <code>, <a href> and <br>, and a hint that rendered as a line of angle brackets would be no hint.
 * That makes a language file the one place where a stranger's text reaches the page as HTML — an
 * <img onerror> in an uploaded JSON runs in the owner's session the moment the panel renders that
 * key, and in every visitor's browser once the language is switched on. langWriteFile() keeps PHP
 * out of the file; this keeps script out of the strings.
 *
 * An allow-list rather than a blacklist, for the reason richtext.php gives at length: what a browser
 * will execute is an open set, what a translation needs is not. The shipped dictionaries are not
 * measured against this list (they carry a `class=` here and there, and they are vetted with the
 * code); it is the rule for what arrives from outside.
 */
const LANG_SAFE_TAGS = ['a', 'strong', 'em', 'b', 'i', 'code', 'kbd', 'br', 'span', 'small', 'sup'];

/**
 * A contributed value as it may be written to a language file, or null when it must not be.
 *
 * Refused WHOLE, never cleaned: a translator who pasted markup by accident wants to know which key
 * to fix, and a silently stripped tag hides exactly that; one who did it on purpose gets nothing.
 * The reply names the dropped keys for the same reason.
 *
 * Two layers, because they catch different things. The first is a plain scan of the raw text for
 * the shapes nobody needs in a translation — <script, <svg, `javascript:` and friends — cheap, and
 * a reader can see at a glance what it refuses. The second walks every tag: the name must be in
 * LANG_SAFE_TAGS, a closing tag carries nothing, an opening tag carries nothing unless it is an <a>
 * with a lone href — and that href, after the entities a browser would decode, is http, https or a
 * relative path. The href is decoded before it is judged because `&#106;avascript:` never contains
 * the letters the first layer looks for, and it is checked for whitespace and control characters
 * because a browser strips those from a scheme before reading it, so `java\tscript:` runs. A `<`
 * that is not a tag ("a < b") is text and stays; a `<` that starts something the tag walk did not
 * recognise — a comment, an unterminated tag, `<?` — is refused, because whatever a browser makes
 * of it, this code did not check it.
 */
function langSanitizeValue(string $v): ?string {
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) return null;
    // The lookbehind keeps "metadata: …" as prose: a scheme can only ever start where a URL starts,
    // never in the middle of a word.
    if (preg_match('/<(?:script|iframe|object|embed|svg|style)|(?<![a-z0-9])(?:javascript|vbscript|data):/i', $v)) {
        return null;
    }
    if (strpos($v, '<') === false) return $v;

    $bad = false;
    $rest = preg_replace_callback('~<(/?)([a-zA-Z][a-zA-Z0-9]*)([^<>]*)>~', function (array $m) use (&$bad): string {
        $tag = strtolower($m[2]);
        $attrs = trim($m[3]);
        if (!in_array($tag, LANG_SAFE_TAGS, true) || preg_match('/on\w+\s*=/i', $attrs)) { $bad = true; return ''; }
        if ($m[1] === '/') { if ($attrs !== '') $bad = true; return ''; }
        $attrs = rtrim(preg_replace('~/\z~', '', $attrs));      // <br/> is still a bare <br>
        if ($tag !== 'a') { if ($attrs !== '') $bad = true; return ''; }
        if ($attrs === '') return '';                             // a bare <a> is an anchor, not a link
        if (!preg_match('~\Ahref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))\z~i', $attrs, $h)) { $bad = true; return ''; }
        $href = $h[1] !== '' ? $h[1] : (($h[2] ?? '') !== '' ? $h[2] : ($h[3] ?? ''));
        // A browser also decodes a numeric reference that has NO semicolon — to its tokenizer
        // `&#106avascript:` is `javascript:`, the digits consumed greedily up to the first character
        // that is not one (a parse error, but the code point is still emitted). PHP decodes only the
        // terminated form, so the semicolon is put back first, consuming the digits the way the
        // tokenizer does; possessive, so `&#106;` is left exactly as it is.
        $href = preg_replace('/&#(x[0-9a-f]++|[0-9]++)(?!;)/i', '$0;', $href) ?? $href;
        $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[\x00-\x20\x7F]/', $url)) { $bad = true; return ''; }
        if (preg_match('~\A[a-z][a-z0-9+.\-]*:~i', $url)) {
            if (!preg_match('~\Ahttps?://~i', $url)) $bad = true;
        } elseif (preg_match('~\A(?:/[/\\\\]|\\\\)~', $url)) {
            // Not a relative path: `//host` follows the page's scheme to whichever host it names, and
            // a browser reads `/\host` the same way — in an http URL a backslash is a slash.
            $bad = true;
        }
        return '';
    }, $v);
    if ($bad || $rest === null || preg_match('~<[a-zA-Z/!?]~', $rest)) return null;
    return $v;
}

/**
 * Write `lang/<code>.php` from a validated string map.
 *
 * ALWAYS through var_export() of data this code has already checked. A language file is `require`d
 * on every request, so nothing an uploader wrote may reach it verbatim — the upload path parses
 * JSON and this writes a literal array the app generated. That is the whole reason uploads are
 * JSON and not PHP.
 */
function langWriteFile(string $code, array $strings, string $note): bool {
    if (!preg_match('/^[a-z]{2,3}$/', $code) || count($strings) > 10000) return false;
    foreach ($strings as $k => $v) {
        if (!is_string($k) || !is_string($v)) return false;
    }
    $body = "<?php\n"
        . "/**\n"
        . " * " . strtoupper($code) . " strings — written by the panel on " . date('Y-m-d H:i') . ".\n"
        . " *\n"
        . " * " . str_replace('*/', '', $note) . "\n"
        . " *\n"
        . " * Generated from vetted data: the array below is written by the app, never supplied\n"
        . " * verbatim. Keys missing here fall back to English (see includes/lang.php).\n"
        . " */\n"
        . "return " . var_export($strings, true) . ";\n";
    if (strlen($body) > 4 * 1024 * 1024) return false;

    if (!is_dir(LANG_DIR)) return false;
    $target = LANG_DIR . '/' . $code . '.php';
    $temp = $target . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(8));
    try {
        if (@file_put_contents($temp, $body, LOCK_EX) !== strlen($body)) return false;
        // Read it back through the same `require` the app will use. A file that parses to something
        // other than what was meant is caught here rather than on the next visitor's page load.
        $verified = require $temp;
        if (!is_array($verified) || $verified !== $strings) return false;
        @chmod($temp, 0644);
        if (!@rename($temp, $target)) return false;
        // A file replaced under an opcode cache is still the old one until the cache is told.
        if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
        return true;
    } catch (\Throwable $e) {
        return false;
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
}

/** Remove an installed language. Built-ins are not removable. */
function langDeleteFile(string $code): bool {
    if (!preg_match('/^[a-z]{2,3}$/', $code) || in_array($code, LANG_BUILT_IN, true)) return false;
    $target = LANG_DIR . '/' . $code . '.php';
    if (!is_file($target)) return false;
    $ok = @unlink($target);
    if ($ok && function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
    return $ok;
}
