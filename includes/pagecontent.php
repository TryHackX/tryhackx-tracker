<?php
/**
 * Editable Terms and Info pages.
 *
 * Both pages ship as PHP templates and both contain CONDITIONALS — `trackerMode($cfg)` decides
 * whether the whitelist paragraphs appear, `usersEnabled($cfg)` decides whether the account terms
 * do. That is the whole difficulty here, and it is why this file generates the default text from the
 * current configuration rather than storing a fixed copy:
 *
 *   - "Restore default" always hands back a page that matches how the tracker is configured RIGHT
 *     NOW, not how it was configured when somebody first opened the editor.
 *   - An operator who saves a custom page is told, in as many words, that switching tracker mode
 *     will no longer change it. That is a real consequence of editing and it is better said out loud
 *     than discovered when the whitelist paragraphs stop matching reality.
 *
 * The stored text goes through the same renderer as every other piece of author-written content
 * (includes/richtext.php), so the sanitising, the link rules and the limits are the ones already in
 * use — there is no second, weaker path into a public page.
 *
 * MARKDOWN IS THE DEFAULT FORMAT FOR THESE TWO PAGES, and not by taste: the renderer's BBCode branch
 * has no heading tag at all (grep `[h1]` — it does not exist), while its Markdown branch maps
 * `#`..`######` onto real headings. A Terms page is mostly headings and numbered lists. BBCode is
 * still offered, with sizes standing in for headings, because the operator may prefer the toolbar.
 */

// REQUIRED, not probed for.
//
// The default text is assembled from `trackerMode()` and `usersEnabled()`. Guarding those with
// function_exists() meant that a caller which had not loaded them got a page with the whitelist and
// account sections silently missing — a wrong default that looks like a correct one, which is the
// worst kind. Naming the dependency makes a missing include an error instead.
require_once __DIR__ . '/whitelist.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/homelayout.php';

const PAGECONTENT_PAGES = ['tos', 'info'];

/**
 * Is this a page the editor may hold text for?
 *
 * The two written pages, and every section of the home page as 'home:<key>' — built-in sections
 * (an override for the shipped body) and the operator's own custom_N ones. A key that is not in the
 * current layout is refused, so a section removed from the layout cannot keep collecting text.
 */
function pageContentPageKnown(string $page, array $cfg = []): bool {
    if (in_array($page, PAGECONTENT_PAGES, true)) return true;
    if (!str_starts_with($page, 'home:')) return false;
    $key = substr($page, 5);
    return preg_match('/^[a-z_0-9]{1,24}$/', $key) === 1 && in_array($key, homeLayoutOrder($cfg), true);
}

/** The human label of any page the editor may hold. */
function pageContentLabel(string $page, array $cfg = []): string {
    $cat = pageContentCatalog();
    if (isset($cat[$page])) return $cat[$page]['label'];
    if (str_starts_with($page, 'home:')) return 'Home page — ' . homeSectionLabel($cfg, substr($page, 5));
    return $page;
}
const PAGECONTENT_MAX = 60000;

/**
 * Which stored version a visitor gets, in order.
 *
 * A page is stored PER LANGUAGE, and a translation is normally incomplete for a while. The
 * fallback therefore never drops back to the built-in text once anything has been written: an
 * operator who wrote real terms in one language must not have a visitor in another quietly served
 * the generic boilerplate instead. Their words, in the nearest language available, or nothing.
 *
 *   1. the visitor's language
 *   2. the site's default language
 *   3. English
 *   4. any other stored version at all
 *   5. only then the built-in template (which is itself translated)
 */
function pageContentLangChain(array $cfg, ?string $lang = null): array {
    $chain = [];
    foreach ([$lang ?: langCurrent(), (string)($cfg['default_language'] ?? ''), LANG_FALLBACK] as $c) {
        $c = strtolower(trim((string)$c));
        if ($c !== '' && $c !== 'auto' && !in_array($c, $chain, true)) $chain[] = $c;
    }
    return $chain;
}

/** The pages this can edit, with the labels the panel shows and the route each one serves. */
function pageContentCatalog(): array {
    return [
        'tos'  => ['label' => 'Terms of Service', 'route' => 'tos',  'template' => 'templates/pages/tos.php'],
        'info' => ['label' => 'Tracker Information', 'route' => 'info', 'template' => 'templates/pages/info.php'],
    ];
}

/** One stored override for one language, or null when that language has none. */
function pageContentGet(PDO $db, string $page, string $lang): ?array {
    if (!in_array($page, PAGECONTENT_PAGES, true) && !str_starts_with($page, 'home:')) return null;
    if (!preg_match('/^[a-z]{2,3}$/', $lang)) return null;
    try {
        $st = $db->prepare("SELECT page, lang, format, body, enabled, updated_at, updated_by
                            FROM page_content WHERE page = ? AND lang = ?");
        $st->execute([$page, $lang]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$r) return null;
    $r['enabled'] = (int)$r['enabled'] === 1;
    return $r;
}

/** Every stored version of every page, as [page][lang] => row — for the settings screen. */
function pageContentAll(PDO $db): array {
    $out = [];
    try {
        $st = $db->query("SELECT page, lang, format, enabled, updated_at, updated_by FROM page_content");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!in_array($r['page'], PAGECONTENT_PAGES, true) && !str_starts_with((string)$r['page'], 'home:')) continue;
            $r['enabled'] = (int)$r['enabled'] === 1;
            $out[$r['page']][$r['lang']] = $r;
        }
    } catch (\Throwable $e) {
        return [];
    }
    return $out;
}

/** Store (or replace) a page. Returns ['error' => …] or ['ok' => true]. */
function pageContentSave(PDO $db, array $cfg, string $page, string $lang, string $format, string $body, bool $enabled, string $who): array {
    if (!pageContentPageKnown($page, $cfg)) return ['error' => __('api.pages.unknown_page')];
    // Any INSTALLED language, not merely an enabled one: a translation is written before it is
    // switched on, and refusing to store the page for it would make that impossible.
    if (!langInstalled($lang)) return ['error' => __('api.pages.lang_not_installed')];
    if (!in_array($format, ['bbcode', 'markdown'], true)) return ['error' => __('api.pages.unknown_format')];
    if (strlen($body) > PAGECONTENT_MAX) {
        return ['error' => __('api.pages.body_too_long', ['max' => number_format(PAGECONTENT_MAX)])];
    }
    if (trim($body) === '' && $enabled) {
        return ['error' => __('api.pages.empty_page_enabled')];
    }
    // The same validator every other author-written text goes through — link rules, image counts and
    // the rest. A page written by the owner is not a reason to skip it: the owner can still paste
    // something that the renderer would refuse elsewhere, and two behaviours for one syntax is how a
    // renderer stops being predictable.
    if (function_exists('richtextValidate') && trim($body) !== '') {
        $err = richtextValidate($body, $format, $cfg);
        if ($err !== null) return ['error' => $err];
    }
    try {
        $db->prepare("INSERT INTO page_content (page, lang, format, body, enabled, updated_at, updated_by)
                      VALUES (?, ?, ?, ?, ?, NOW(), ?)
                      ON DUPLICATE KEY UPDATE format = VALUES(format), body = VALUES(body),
                                              enabled = VALUES(enabled), updated_at = NOW(),
                                              updated_by = VALUES(updated_by)")
           ->execute([$page, $lang, $format, $body, $enabled ? 1 : 0, mb_substr($who, 0, 64)]);
    } catch (\Throwable $e) {
        return ['error' => __('api.pages.store_failed')];
    }
    return ['ok' => true];
}

/**
 * Throw one language's version away. With $lang null, every language of that page goes.
 *
 * Per language by default, because "restore" while editing Polish should not silently delete an
 * English page the operator spent an afternoon on.
 */
function pageContentReset(PDO $db, string $page, ?string $lang = null): bool {
    if (!in_array($page, PAGECONTENT_PAGES, true) && !str_starts_with($page, 'home:')) return false;
    if ($lang !== null && !preg_match('/^[a-z]{2,3}$/', $lang)) return false;
    try {
        if ($lang === null) {
            $db->prepare("DELETE FROM page_content WHERE page = ?")->execute([$page]);
        } else {
            $db->prepare("DELETE FROM page_content WHERE page = ? AND lang = ?")->execute([$page, $lang]);
        }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Should the router use an override for this page, and which one?
 *
 * Enabled AND non-empty, in the order pageContentLangChain() gives — plus, as a last resort, any
 * other stored version. A stored-but-disabled page is a draft and never reaches a visitor, and a
 * draft must not be able to blank a public page by being empty.
 */
function pageContentActive(PDO $db, string $page, array $cfg = [], ?string $lang = null): ?array {
    foreach (pageContentLangChain($cfg, $lang) as $code) {
        $r = pageContentGet($db, $page, $code);
        if ($r && $r['enabled'] && trim((string)$r['body']) !== '') return $r;
    }
    // Anything the operator wrote beats the built-in boilerplate — see pageContentLangChain().
    try {
        $st = $db->prepare("SELECT page, lang, format, body, enabled, updated_at, updated_by
                            FROM page_content WHERE page = ? AND enabled = 1 AND TRIM(body) <> ''
                            ORDER BY lang = 'en' DESC, lang ASC LIMIT 1");
        $st->execute([$page]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$r) return null;
    $r['enabled'] = true;
    return $r;
}

// ─────────────────────────────────────────────────────────────────────────────
// Conditional markers: [[if:name]] … [[/if]] and [[ifnot:name]] … [[/if]]
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every condition a page may test, with what it is RIGHT NOW and a line for the editor.
 *
 * Curated, not derived from the settings table: a marker is part of a page's wording, and the
 * names have to stay stable across versions and readable to somebody writing Terms, not to the
 * code. Adding one is adding a line here; the editor lists them from this.
 */
function pageContentConditions(array $cfg, ?PDO $db = null): array {
    $wl = trackerMode($cfg) === 'whitelist';
    $sched = function_exists('scheduleEnabled') && scheduleEnabled($cfg);
    $users = usersEnabled($cfg);
    $index = function_exists('indexEnabled') && indexEnabled($cfg);
    $langs = function_exists('langEnabled') ? count(langEnabled($cfg)) : 1;
    return [
        'whitelist'    => [$wl,    __('api.pages.cond_whitelist')],
        'open'         => [!$wl,   __('api.pages.cond_open')],
        'schedule'     => [$sched, __('api.pages.cond_schedule')],
        'registration' => [($wl || $sched) && ($cfg['whitelist_public_enabled'] ?? '1') === '1',
                                   __('api.pages.cond_registration')],
        'users'        => [$users, __('api.pages.cond_users')],
        'signup'       => [$users && usersRegistrationEnabled($cfg), __('api.pages.cond_signup')],
        'email_verify' => [$users && userEmailVerifyRequired($cfg), __('api.pages.cond_email_verify')],
        'index'        => [$index, __('api.pages.cond_index')],
        'search'       => [$index && $users && ($cfg['index_search_enabled'] ?? '1') === '1',
                                   __('api.pages.cond_search')],
        'stats'        => [($cfg['tracker_stats_enabled'] ?? '0') === '1', __('api.pages.cond_stats')],
        'donations'    => [($cfg['donations_enabled'] ?? '0') === '1', __('api.pages.cond_donations')],
        'contact'      => [($cfg['contact_visible'] ?? '1') === '1', __('api.pages.cond_contact')],
        'transparency' => [($cfg['transparency_enabled'] ?? '1') === '1', __('api.pages.cond_transparency')],
        'languages'    => [$langs > 1, __('api.pages.cond_languages')],
        'ratings'      => [($cfg['rating_enabled'] ?? '0') === '1', __('api.pages.cond_ratings')],
        'descriptions' => [($cfg['wl_allow_description'] ?? '0') === '1' || ($cfg['wl_allow_source_url'] ?? '0') === '1',
                                   __('api.pages.cond_descriptions')],
    ];
}

/**
 * Resolve the markers against the current settings. Innermost blocks first, so one level of
 * nesting works; anything left over ([[/if]] without an opener) is removed rather than shown.
 *
 * An unknown condition is FALSE. A block that names a condition nobody has heard of is more likely
 * a typo than a request to show something to everyone — and the editor's preview says which.
 */
function pageContentResolveMarkers(string $text, array $cfg, ?PDO $db = null, ?array &$unknown = null): string {
    if (strpos($text, '[[') === false) return $text;
    $conds = pageContentConditions($cfg, $db);
    $unknown = [];
    $re = '/\[\[(if|ifnot):([a-z_]+)\]\]((?:(?!\[\[(?:if|ifnot):)[\s\S])*?)\[\[\/if\]\]/';
    for ($pass = 0; $pass < 24; $pass++) {
        $n = 0;
        $text = preg_replace_callback($re, function ($m) use ($conds, &$unknown) {
            $name = $m[2];
            if (!isset($conds[$name])) { $unknown[] = $name; $on = false; }
            else $on = (bool)$conds[$name][0];
            if ($m[1] === 'ifnot') $on = !$on;
            // Trim the newline that follows the opener and precedes the closer, so a hidden block
            // does not leave a blank line and a shown one does not gain two.
            return $on ? preg_replace('/^\r?\n|\r?\n$/', '', $m[3]) : '';
        }, $text, -1, $n) ?? $text;
        if (!$n) break;
    }
    $unknown = array_values(array_unique($unknown));
    // Stray closers or openers with no partner: removed, never printed.
    return preg_replace('/\[\[(?:if|ifnot):[a-z_]+\]\]|\[\[\/if\]\]/', '', $text) ?? $text;
}

// ─────────────────────────────────────────────────────────────────────────────
// The defaults — the SAME words the templates render, in the requested language
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Turn the little HTML the dictionary carries into Markdown or BBCode.
 *
 * The strings in lang/*.php are written for a template, so they contain <strong>, <em>, <a href>
 * and <code>. This is what lets the default page be generated from those exact strings instead of
 * from a second English copy kept beside them — which is what this file used to do, and which was
 * a guarantee of drift the moment there was more than one language.
 *
 * Deliberately not a general HTML parser: it handles the four tags the dictionary actually uses and
 * strips anything else, because a general parser here would be a second renderer to keep correct.
 */
function pageContentFromHtml(string $html, bool $md): string {
    $out = $html;
    $out = preg_replace_callback('#<a\s+href="([^"]*)"[^>]*>(.*?)</a>#is',
        fn($m) => $md ? '[' . $m[2] . '](' . $m[1] . ')' : '[url=' . $m[1] . ']' . $m[2] . '[/url]',
        $out);
    $out = preg_replace('#<strong>(.*?)</strong>#is', $md ? '**$1**' : '[b]$1[/b]', $out);
    $out = preg_replace('#<b>(.*?)</b>#is',           $md ? '**$1**' : '[b]$1[/b]', $out);
    $out = preg_replace('#<em>(.*?)</em>#is',         $md ? '*$1*'   : '[i]$1[/i]', $out);
    $out = preg_replace('#<i>(.*?)</i>#is',           $md ? '*$1*'   : '[i]$1[/i]', $out);
    $out = preg_replace('#<code>(.*?)</code>#is',     $md ? '`$1`'   : '[code]$1[/code]', $out);
    $out = strip_tags($out);
    // The dictionary is HTML, so &amp; and friends are entities there and text here.
    return html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * The shipped page, in the requested language and format.
 *
 * Built from the dictionary keys the templates use, so "Restore" hands back exactly what the
 * visitor would otherwise be reading — same wording, same language, and the same conditionals
 * (`trackerMode()` and `usersEnabled()` still decide which clauses exist).
 */
function pageContentDefault(array $cfg, string $page, string $format, string $baseUrl = '', ?string $lang = null): string {
    $md    = $format !== 'bbcode';
    if (str_starts_with($page, 'home:')) {
        $key = substr($page, 5);
        if (homeSectionIsCustom($key)) return '';
        // The built-in body, pasted by name. Restore gives this back, and it renders exactly what
        // the page renders without an override — so "start from the built-in" is one line.
        return '{{block:' . $key . '}}' . "\n";
    }
    $lang  = $lang && langInstalled($lang) ? $lang : langCurrent();
    // The conditionals are written INTO the text as [[if:…]] markers rather than decided here, so
    // the page an operator restores and edits keeps following the settings the way the shipped one
    // does — pageContentResolveMarkers() decides at render time, for both.
    $IF = fn(string $c): string => '[[if:' . $c . ']]';
    $FI = '[[/if]]';

    /** One dictionary string, already converted to the requested markup. */
    $t = fn(string $key, array $params = []): string => pageContentFromHtml(langFor($lang, $key, $params), $md);

    // Headings: Markdown has them, BBCode does not — see the file header. `[size]` is the closest
    // BBCode gets. Concatenated, never interpolated: inside a double-quoted string PHP reads `$t[`
    // as the start of an array index, so "[b]$t[/b]" is a parse error rather than the BBCode it
    // looks like.
    $h1 = fn(string $x): string => $md ? '# ' . $x : '[size=24][b]' . $x . '[/b][/size]';
    $h2 = fn(string $x): string => $md ? '## ' . $x : '[size=19][b]' . $x . '[/b][/size]';
    $b  = fn(string $x): string => $md ? '**' . $x . '**' : '[b]' . $x . '[/b]';
    // An item may be [condition, text]: the marker then wraps the WHOLE numbered line, so a clause
    // that is switched off leaves no empty "8." behind. The literal numbers go non-sequential when
    // one is hidden, and that is fine — Markdown and [list=1] both renumber from the first item.
    $ol = function (array $items) use ($md): string {
        $lines = [];
        $n = 0;
        foreach ($items as $i) {
            $cond = is_array($i) ? $i[0] : null;
            $text = is_array($i) ? $i[1] : $i;
            $n++;
            $line = $md ? ($n . '. ' . $text) : ('[*] ' . $text);
            $lines[] = $cond ? '[[if:' . $cond . ']]' . $line . "
[[/if]]" : $line;
        }
        $body = implode("
", $lines);
        return $md ? $body : "[list=1]
" . $body . "
[/list]";
    };

    $L = [];
    if ($page === 'tos') {
        $L[] = $h1($t('tos.h1'));
        $L[] = '';
        $L[] = $t('tos.intro');
        $L[] = '';
        // A numbered list cannot hold a marker between its items without breaking the numbering
        // in Markdown, so the whitelist clause is emitted as its own marked item: the renderer sees
        // either a continuous list or one with that item gone, never a gap.
        $terms = [];
        foreach (['tos.r1', 'tos.r2', 'tos.r3', 'tos.r4', 'tos.r5', 'tos.r6', 'tos.r7'] as $k) $terms[] = $t($k);
        $terms[] = ['whitelist', $t('tos.r_wl')];
        $terms[] = ['index', $t('tos.r_index')];
        $terms[] = $t('tos.r8');
        $terms[] = $t('tos.r9');
        $L[] = $ol($terms);

        $L[] = '';
        $L[] = $IF('users');
        $L[] = $h2($t('tos.acc_head'));
        $L[] = '';
        $L[] = $ol([
            $t('tos.acc1'),
            $t('tos.acc2', ['email' => $IF('email_verify') . langFor($lang, 'tos.acc2_req') . $FI
                                     . '[[ifnot:email_verify]]' . langFor($lang, 'tos.acc2_opt') . $FI]),
            $t('tos.acc3'), $t('tos.acc4'), $t('tos.acc5'), $t('tos.acc6'), $t('tos.acc7'),
            ['search', $t('tos.acc8')],
        ]);
        $L[] = $FI;
        $L[] = '';
        $L[] = $IF('languages');
        $L[] = $h2($t('tos.lang_head'));
        $L[] = '';
        $L[] = $t('tos.lang1');
        $L[] = $FI;
        return implode("\n", $L) . "\n";
    }

    // ── info ────────────────────────────────────────────────────────────────
    $L[] = $h1($t('info.h1'));
    foreach ([['info.q_what', 'info.a_what'], ['info.q_ot', 'info.a_ot'], ['info.q_how', 'info.a_how']] as [$q, $a]) {
        $L[] = '';
        $L[] = $h2($t($q));
        $L[] = '';
        $L[] = $t($a);
    }
    $L[] = '';
    $L[] = $IF('whitelist');
    $L[] = $h2($t('info.q_wl'));
    $L[] = '';
    $L[] = $t('info.a_wl', ['url' => $baseUrl . '?action=whitelist']);
    $L[] = $FI;
    $L[] = '';
    $L[] = $IF('index');
    $L[] = $h2($t('info.q_index'));
    $L[] = '';
    $L[] = $t('info.a_index');
    $L[] = $IF('search') . ' ' . $t('info.a_index_search', ['url' => $baseUrl . '?action=search']) . $FI;
    $L[] = $FI;
    $L[] = '';
    $L[] = $IF('users');
    $L[] = $h2($t('info.q_accounts'));
    $L[] = '';
    $L[] = $t('info.a_accounts');
    $L[] = $FI;
    $L[] = '';
    $L[] = $h2($t('info.q_data'));
    $L[] = '';
    $L[] = $t('info.a_data');
    $L[] = $IF('index') . ' ' . $t('info.a_data_index') . $FI;
    $L[] = '';
    $L[] = $h2($t('info.faq_head'));
    $L[] = '';
    $faq = [
        ['info.faq_q1', null],
        ['info.faq_q2', 'info.faq_a2'],
        ['info.faq_q3', 'info.faq_a3'],
        ['info.faq_q4', 'info.faq_a4'],
        ['info.faq_q5', 'info.faq_a5'],
    ];
    foreach ($faq as [$q, $a]) {
        $L[] = $b($t($q));
        $L[] = '';
        // The first answer depends on the mode; both versions are in the text, one marked each way.
        $L[] = $a === null
            ? $IF('whitelist') . $t('info.faq_a1_wl') . $FI . '[[ifnot:whitelist]]' . $t('info.faq_a1_open') . $FI
            : $t($a);
        $L[] = '';
    }
    $L[] = $IF('languages');
    $L[] = $b($t('info.faq_q6'));
    $L[] = '';
    $L[] = $t('info.faq_a6');
    $L[] = $FI;
    return rtrim(implode("\n", $L)) . "\n";
}
