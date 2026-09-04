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
 *   1. `?lang=xx`          — an explicit choice, remembered in a cookie
 *   2. the signed-in user  — so the language follows the account to another browser
 *   3. the cookie          — the last explicit choice on this browser
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

/** Native names for the codes we know. Anything else shows as its uppercased code. */
const LANG_NAMES = [
    'en' => 'English', 'pl' => 'Polski', 'de' => 'Deutsch', 'fr' => 'Français',
    'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands', 'pt' => 'Português',
    'ru' => 'Русский', 'uk' => 'Українська', 'cs' => 'Čeština', 'sk' => 'Slovenčina',
    'ja' => '日本語', 'zh' => '中文', 'ko' => '한국어', 'tr' => 'Türkçe', 'ar' => 'العربية',
    'sv' => 'Svenska', 'da' => 'Dansk', 'fi' => 'Suomi', 'no' => 'Norsk', 'hu' => 'Magyar',
    'ro' => 'Română', 'bg' => 'Български', 'el' => 'Ελληνικά', 'he' => 'עברית',
    'hi' => 'हिन्दी', 'id' => 'Bahasa Indonesia', 'vi' => 'Tiếng Việt', 'th' => 'ไทย',
];

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
        if (preg_match('/^[a-z]{2,3}$/', $code)) $out[$code] = LANG_NAMES[$code] ?? strtoupper($code);
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
            setcookie(LANG_COOKIE, $lang, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => false,   // no secret in it, and the switcher reads it client-side
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[LANG_COOKIE] = $lang;
    }

    // A signed-in user's saved choice outranks the cookie: the language follows the account to a
    // new browser rather than being whatever that browser last happened to use.
    if (!$lang && $userLanguage && langSupported($cfg, $userLanguage)) $lang = $userLanguage;

    if (!$lang && isset($_COOKIE[LANG_COOKIE]) && is_string($_COOKIE[LANG_COOKIE])
        && langSupported($cfg, strtolower($_COOKIE[LANG_COOKIE]))) {
        $lang = strtolower($_COOKIE[LANG_COOKIE]);
    }
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
}

/** The active code. Falls back to English before langInit() has run. */
function langCurrent(): string { return $GLOBALS['__lang']['current'] ?? LANG_FALLBACK; }

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
function __(string $key, array $params = []): string {
    $s = $GLOBALS['__lang']['strings'][$key] ?? $GLOBALS['__lang']['fallback'][$key] ?? $key;
    if ($params) {
        $repl = [];
        foreach ($params as $k => $v) $repl[':' . $k] = (string)$v;
        $s = strtr($s, $repl);
    }
    return $s;
}

/** Translate and HTML-escape — the default for template output. */
function _h(string $key, array $params = []): string {
    return htmlspecialchars(__($key, $params), ENT_QUOTES, 'UTF-8');
}

/** Is this key known at all? For a key that came from outside, where a miss must show nothing. */
function langHas(string $key): bool {
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
