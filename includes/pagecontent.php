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

/**
 * The human label of any page the editor may hold — in the reader's language (1.73.0: "Terms of Service", "Home page —
 * …" were English on every page), or in English with `$english`: the audit log is written in English whoever reads it.
 */
function pageContentLabel(string $page, array $cfg = [], bool $english = false): string {
    $cat = pageContentCatalog($english);
    if (isset($cat[$page])) return $cat[$page]['label'];
    if (str_starts_with($page, 'home:')) {
        $section = homeSectionLabel($cfg, substr($page, 5), $english);
        return $english ? 'Home page — ' . $section : __('a.pages.home_section', ['section' => $section]);
    }
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

/**
 * The pages this can edit, with the labels the panel shows and the route each one serves. A label is the page's own
 * heading (tos.h1, info.h1) in the reader's language — what the page calls itself is what the panel calls it (1.73.0:
 * the labels were English on every page); `$english` gives the English, for the audit log.
 */
function pageContentCatalog(bool $english = false): array {
    $say = static fn(string $key, string $en): string => $english ? $en : __($key);
    return [
        'tos'  => ['label' => $say('tos.h1', 'Terms of Service'), 'route' => 'tos',  'template' => 'templates/pages/tos.php'],
        'info' => ['label' => $say('info.h1', 'Tracker Information'), 'route' => 'info', 'template' => 'templates/pages/info.php'],
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
    if (function_exists('richtextCount') && trim($body) !== '') {
        $err = pageContentValidate($body, $format, $cfg);
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
 * What richtextValidate() asks of a description, asked of a page — except its LENGTH (1.73.0).
 *
 * The renderer, its link rules and its sanitising are the same for a page as for everything else
 * (richtextCount() renders the text to count what a reader would get). What is not the same is how
 * long it may be: `desc_max_chars` is a description's limit (4 000 as shipped, 20 000 at most), and a
 * page is not a description — its own cap is PAGECONTENT_MAX, checked by the caller. With the
 * description's limit the shipped Terms (7 000 characters) and Info (19 000) could be restored in the
 * editor and never saved again. The formats are the page editor's two whatever descriptions allow, and
 * a page may link as often as the renderer lets anything link; images keep the operator's limit.
 */
function pageContentValidate(string $body, string $format, array $cfg): ?string {
    if (!in_array($format, ['bbcode', 'markdown'], true)) return __('api.pages.unknown_format');
    $pageCfg = array_merge($cfg, ['desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1']);
    $c = richtextCount($body, $format, $pageCfg);
    $maxImg = function_exists('richtextMaxImages') ? richtextMaxImages($cfg) : 3;
    if ($c['images'] > $maxImg) {
        return $maxImg === 0 ? 'Images are not allowed here (the image limit is 0).'
                             : 'That page has ' . $c['images'] . ' images; the limit is ' . $maxImg . '.';
    }
    if ($c['links'] > 100) return 'That page has ' . $c['links'] . ' links; the limit is 100.';
    return null;
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
 *
 * 1.73.0: one for every optional feature the shipped Info and Terms describe, so an install with a
 * feature off never promises it. Each asks the feature's OWN gate — commentsEnabled(), shoutEnabled()
 * and the rest — rather than reading a setting here: the gate is what the feature's code obeys, and a
 * second reading of its switch is a second answer that can disagree (`ratings` read `rating_enabled`,
 * a setting that has never existed — the switch is `rep_enabled` — so its paragraph was never shown).
 * They are probed for, like the three below: both entry points that render a page (index.php, and
 * api.php for the editor's preview) load every one of those files.
 */
function pageContentConditions(array $cfg, ?PDO $db = null): array {
    $wl = trackerMode($cfg) === 'whitelist';
    $sched = function_exists('scheduleEnabled') && scheduleEnabled($cfg);
    $users = usersEnabled($cfg);
    $index = function_exists('indexEnabled') && indexEnabled($cfg);
    $langs = function_exists('langEnabled') ? count(langEnabled($cfg)) : 1;
    $on = fn(string $gate): bool => function_exists($gate) && (bool)$gate($cfg);
    $comments = $on('commentsEnabled');
    $content  = $on('contentEnabled');
    $shout    = $on('shoutEnabled');
    $pm       = $on('pmEnabled');
    $lists    = $on('listsEnabled');
    $bio      = $on('profileBioEnabled');
    $friends  = $on('friendsEnabled');
    $favs     = $on('favEnabled');
    $ratings  = $on('repEnabled');
    $backups  = $on('backupEnabled');
    $regOpen  = ($wl || $sched) && ($cfg['whitelist_public_enabled'] ?? '1') === '1';
    $api      = ($cfg['api_enabled'] ?? '0') === '1';
    // Anything members write that somebody else reads: what the rules of Terms and the anti-spam
    // layer are about. A site with none of these has nobody's words to moderate.
    $community = $comments || $content || $shout || $pm || $lists || $bio;
    // What carries a report flag (includes/reports.php contentReportKindOn(): the three kinds of public
    // words), and — with messages — whether anything can be reported at all.
    $reportable = $comments || $content || $shout;
    // The guest group's own grant: a site may let a passer-by comment (under every guest rule).
    $guestComments = false;
    if ($comments && $db && function_exists('userEffectivePermissions')) {
        try { $guestComments = !empty(userEffectivePermissions($db, null, $cfg)['comment.post']); } catch (\Throwable $e) { /* no groups table yet */ }
    }
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
        'ratings'      => [$ratings, __('api.pages.cond_ratings')],
        'descriptions' => [$content, __('api.pages.cond_descriptions')],
        // ── 1.73.0 ──
        'whitelist_or_schedule' => [$wl || $sched, __('api.pages.cond_whitelist_or_schedule')],
        'registration_members'  => [$regOpen && $users && ($cfg['whitelist_submit_mode'] ?? 'public') === 'users',
                                    __('api.pages.cond_registration_members')],
        // A registration proves itself first, and is served while it does (1.73.0 part E, includes/wlprobe.php).
        'probe'        => [$regOpen && $on('wlProbeEnabled'), __('api.pages.cond_probe')],
        'index_kept'   => [$index && function_exists('indexKeepSavedMode') && indexKeepSavedMode($cfg) !== 'off',
                           __('api.pages.cond_index_kept')],
        'twofa'        => [$users && $on('user2faFeatureEnabled'), __('api.pages.cond_twofa')],
        // A wait between two changes of an account's e-mail (`users_email_change_cooldown_days`, 30; 0 = none).
        'email_cooldown' => [$users && function_exists('userEmailChangeCooldownDays') && userEmailChangeCooldownDays($cfg) > 0,
                             __('api.pages.cond_email_cooldown')],
        'profiles'     => [$on('profilesEnabled'), __('api.pages.cond_profiles')],
        'pictures'     => [$on('userAvatarsEnabled') || $on('userCoversEnabled'), __('api.pages.cond_pictures')],
        'bio'          => [$bio, __('api.pages.cond_bio')],
        'favourites'   => [$favs, __('api.pages.cond_favourites')],
        'lists'        => [$lists, __('api.pages.cond_lists')],
        'lists_public' => [$on('listsPublicEnabled'), __('api.pages.cond_lists_public')],
        'lists_friends' => [$on('listsFriendsEnabled'), __('api.pages.cond_lists_friends')],
        'saved'        => [$favs || $lists, __('api.pages.cond_saved')],
        'friends'      => [$friends, __('api.pages.cond_friends')],
        'directory'    => [$on('directoryEnabled'), __('api.pages.cond_directory')],
        'messages'     => [$pm, __('api.pages.cond_messages')],
        // A Trash at all (part A of 1.73.0: `pm_trash_days`, 0 = Delete deletes at once).
        'trash'        => [$pm && function_exists('pmTrashDays') && pmTrashDays($cfg) > 0, __('api.pages.cond_trash')],
        // …and whether a new message brings an archived conversation back (`pm_archive_returns`, on as shipped).
        'archive_returns' => [$pm && (!function_exists('pmArchiveReturns') || pmArchiveReturns($cfg)), __('api.pages.cond_archive_returns')],
        'people'       => [$friends || $pm || $on('directoryEnabled'), __('api.pages.cond_people')],
        'comments'     => [$comments, __('api.pages.cond_comments')],
        'guest_comments' => [$guestComments, __('api.pages.cond_guest_comments')],
        'writing'      => [$comments || $content || $ratings, __('api.pages.cond_writing')],
        'shoutbox'     => [$shout, __('api.pages.cond_shoutbox')],
        'sounds'       => [$on('soundsEnabled'), __('api.pages.cond_sounds')],
        'community'    => [$community, __('api.pages.cond_community')],
        'reportable'   => [$reportable, __('api.pages.cond_reportable')],
        'report_words' => [$reportable || $pm, __('api.pages.cond_report_words')],
        'antispam'     => [$community && $on('antispamOn'), __('api.pages.cond_antispam')],
        'antispam_new' => [$community && $on('antispamOn') && function_exists('antispamNewDays') && antispamNewDays($cfg) > 0,
                           __('api.pages.cond_antispam_new')],
        'api'          => [$api, __('api.pages.cond_api')],
        'bridge'       => [$on('authBridgeEnabled'), __('api.pages.cond_bridge')],
        'federation'   => [$on('fedEnabled'), __('api.pages.cond_federation')],
        'audit'        => [($cfg['audit_enabled'] ?? '1') === '1', __('api.pages.cond_audit')],
        // The log records members' own actions too (reports, sessions, bridged sign-ins, anti-spam refusals).
        'audit_members' => [$users && ($cfg['audit_enabled'] ?? '1') === '1', __('api.pages.cond_audit_members')],
        'backups'      => [$backups, __('api.pages.cond_backups')],
        'backup_days'  => [$backups && function_exists('backupKeepDays') && backupKeepDays($cfg) > 0, __('api.pages.cond_backup_days')],
        'csp_reports'  => [$on('cspReportingOn'), __('api.pages.cond_csp_reports')],
        'captcha'      => [$on('captchaConfigured'), __('api.pages.cond_captcha')],
        // The icon font from jsDelivr: Bootstrap Icons always, Font Awesome unless it is an installed
        // package, which the site serves itself (includes/icons.php iconFontTag()).
        'icons_cdn'    => [!function_exists('iconLibrary') || iconLibrary($cfg) === 'bootstrap'
                           || (function_exists('iconFaSource') && iconFaSource($cfg) !== 'pack'), __('api.pages.cond_icons_cdn')],
        // Pictures hot-linked from anywhere: a description's and a shout's markup may carry an image.
        'images'       => [$content || $shout, __('api.pages.cond_images')],
    ];
}

/**
 * The numbers a page may quote, as they are RIGHT NOW: [[value:name]] in a page's text (1.73.0).
 *
 * The same idea as the conditions, for a number: "kept for 30 days" written into a saved page would
 * go on saying 30 after the operator changed the setting, so the shipped text writes the setting's
 * NAME and the page reads the number when it is shown. Each is read through the helper its feature
 * clamps with, so the page quotes the number the code obeys, not the raw row.
 *
 * Each entry: [value, what it is, unit]. A number of DAYS is written with its noun ("30 days", "1 day";
 * "30 dni", "1 dzień" — pageContentDays()), because the noun has to agree with the number and only the
 * code knows the number: "przez :days dni" said "przez 1 dni" the day an operator chose 1. A count with
 * no unit is the bare number; the whitelist hours are words in the reader's language.
 */
function pageContentValues(array $cfg, ?PDO $db = null): array {
    $int = fn(string $fn, int $fallback): int => function_exists($fn) ? (int)$fn($cfg) : $fallback;
    return [
        'index_grace_days'   => [$int('indexGraceDays', 3), 'days an index entry whose name never arrived is kept', 'days'],
        'index_protect_days' => [$int('indexProtectDays', 10), 'days an index entry is kept after the last scrape with a seeder', 'days'],
        'shout_keep_days'    => [$int('shoutKeepDays', 30), 'days a line stays in the shoutbox', 'days'],
        'shout_keep_rows'    => [$int('shoutKeepRows', 2000), 'how many lines the shoutbox keeps', ''],
        'pm_trash_days'      => [$int('pmTrashDays', 30), 'days a deleted conversation waits in the Trash', 'days'],
        'antispam_new_days'  => [$int('antispamNewDays', 3), 'days an account counts as new to the anti-spam check', 'days'],
        'audit_keep_days'    => [$int('auditKeepDays', 180), 'days the operator\'s log is kept', 'days'],
        'backup_keep_days'   => [$int('backupKeepDays', 30), 'days a backup is kept', 'days'],
        'email_change_days'  => [userEmailChangeCooldownDays($cfg), 'days between two changes of an account\'s e-mail', 'days'],
        // The one value that is words: the whitelist hours, in the reader's language.
        'schedule_hours'     => [pageContentScheduleText($cfg), 'the whitelist hours', ''],
    ];
}

/**
 * A value as the text a page carries. A number is itself — a number of days with its noun —; words lose
 * every character a page's markup could read as markup ([ ] < > * _ ` \) — they go on to the renderer
 * (Markdown, BBCode) or into HTML, and a value must never be able to open a tag in either. Nothing is
 * escaped here: the renderer escapes the text once, and an entity written here would come out as
 * "&amp;amp;".
 */
function pageContentValueText($v, string $unit = ''): string {
    if (is_int($v)) return $unit === 'days' ? pageContentDays($v) : (string)$v;
    return trim((string)preg_replace('/[\[\]<>*_`\\\\]/u', '', (string)$v));
}

/**
 * A number of days with its noun, in the current language or $lang: "1 day" / "30 days", "1 dzień" /
 * "30 dni". Two forms are all either language needs where the texts use them — after "for", "after",
 * "older than", "at least" (Polish: the accusative, where every count but one takes "dni").
 */
function pageContentDays(int $n, ?string $lang = null): string {
    $key = $n === 1 ? 'info.days_one' : 'info.days_many';
    return $lang !== null ? langFor($lang, $key, ['n' => $n]) : __($key, ['n' => $n]);
}

/**
 * The whitelist hours in the reader's language (1.73.0): scheduleDescribe()'s grouping of the week —
 * consecutive days with the same window joined, open days left out — with the dictionary's day names,
 * and no parentheses of its own, since the texts put the hours in parentheses. scheduleDescribe()
 * stays as it is: the panel and tests/schedule_test.php read its English.
 * "Mon–Fri 22:00–06:00 the next day, Sat–Sun all day, Europe/Warsaw time" /
 * "pon–pt 22:00–06:00 następnego dnia, sob–niedz cały dzień, czas Europe/Warsaw".
 */
function pageContentScheduleText(array $cfg, ?string $lang = null): string {
    // The words live in includes/schedule.php since 1.73.0 (scheduleDescribeText()): the whitelist page and the
    // panel print the same hours as these texts, from one function.
    if (!function_exists('scheduleDescribeText')) return '';
    return scheduleDescribeText($cfg, $lang);
}

/**
 * Resolve the markers against the current settings. Innermost blocks first, so one level of
 * nesting works; anything left over ([[/if]] without an opener) is removed rather than shown.
 *
 * An unknown condition is FALSE. A block that names a condition nobody has heard of is more likely
 * a typo than a request to show something to everyone — and the editor's preview says which.
 *
 * A HIDDEN BLOCK ON LINES OF ITS OWN TAKES ITS LINE BREAK WITH IT (1.73.0). It used to leave the
 * line break behind, i.e. a blank line — harmless between paragraphs, and fatal inside a list: a blank
 * line ends a Markdown list, so the shipped Terms with the whitelist clause hidden came out as two
 * lists, the second one numbered from 1 again. A block "on lines of its own" is one whose opener
 * starts a line and whose closer ends one; a marker in the middle of a sentence is left as it was.
 *
 * Then [[value:name]] becomes what pageContentValues() gives for it — a number, a number of days with
 * its noun, the whitelist hours in words (an unknown name, nothing, reported like an unknown condition
 * as "value:name").
 */
function pageContentResolveMarkers(string $text, array $cfg, ?PDO $db = null, ?array &$unknown = null): string {
    if (strpos($text, '[[') === false) return $text;
    $conds = pageContentConditions($cfg, $db);
    $unknown = [];
    $re = '/\[\[(if|ifnot):([a-z_]+)\]\]((?:(?!\[\[(?:if|ifnot):)[\s\S])*?)\[\[\/if\]\](\r?\n)?/';
    for ($pass = 0; $pass < 24; $pass++) {
        $n = 0;
        $subject = $text;
        $text = preg_replace_callback($re, function ($m) use ($conds, &$unknown, $subject) {
            $name = $m[2][0];
            if (!isset($conds[$name])) { $unknown[] = $name; $on = false; }
            else $on = (bool)$conds[$name][0];
            if ($m[1][0] === 'ifnot') $on = !$on;
            // The line break after the closer is part of the match (so a hidden block can take it);
            // an optional group that did not take part is absent from $m.
            $after = isset($m[4]) && $m[4][1] >= 0 ? $m[4][0] : '';
            $at = $m[0][1];
            $ownLines = $at === 0 || $subject[$at - 1] === "\n";
            // Shown: trim the newline that follows the opener and precedes the closer, so a block on
            // lines of its own does not gain two. A shown block with nothing left in it (every line
            // inside was a block that is hidden) goes the way a hidden one does.
            if ($on) {
                $inner = preg_replace('/^\r?\n|\r?\n$/', '', $m[3][0]) ?? $m[3][0];
                if ($inner === '' && $ownLines) return '';
                return $inner . $after;
            }
            return $ownLines ? '' : $after;
        }, $text, -1, $n, PREG_OFFSET_CAPTURE) ?? $text;
        if (!$n) break;
    }
    // Stray closers or openers with no partner: removed, never printed.
    $text = preg_replace('/\[\[(?:if|ifnot):[a-z_]+\]\]|\[\[\/if\]\]/', '', $text) ?? $text;
    if (strpos($text, '[[value:') !== false) {
        $vals = pageContentValues($cfg, $db);
        $text = preg_replace_callback('/\[\[value:([a-z_]+)\]\]/', function ($m) use ($vals, &$unknown) {
            if (!isset($vals[$m[1]])) { $unknown[] = 'value:' . $m[1]; return ''; }
            return pageContentValueText($vals[$m[1]][0], $vals[$m[1]][2] ?? '');
        }, $text) ?? $text;
    }
    $unknown = array_values(array_unique($unknown));
    return $text;
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
 * Built from pageContentSpec() — the very list templates/pages/info.php and tos.php render — so
 * "Restore" hands back exactly what the visitor would otherwise be reading: the same wording, the same
 * language, and the same conditions. Those are written INTO the text as [[if:…]] markers rather than
 * decided here, so the page an operator restores and edits keeps following the settings the way the
 * shipped one does (pageContentResolveMarkers() decides at render time, for both); a number that is a
 * setting is written as [[value:…]] for the same reason.
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
    $spec = pageContentSpec($page);
    if (!$spec) return '';
    $lang  = $lang && langInstalled($lang) ? $lang : langCurrent();
    // The links in the text must be ABSOLUTE: the renderer takes only http(s) addresses and drops a
    // relative one as plain words (richtextSafeUrl()), so a default built on the request path ("/") put
    // back a page whose every link to the site itself was text. `site_url` is the operator's own
    // statement of where the site lives, as for every address that leaves the page (apiAbsoluteBase()).
    if (!preg_match('#^https?://#i', $baseUrl) && trim((string)($cfg['site_url'] ?? '')) !== '') {
        $baseUrl = rtrim(trim((string)$cfg['site_url']), '/') . '/';
    }

    /** One part — a dictionary string with its :url and :value parameters — as Markdown or BBCode. */
    $part = function ($p) use ($lang, $md, $baseUrl): string {
        [$key, $params] = pageContentPartArgs($p, $baseUrl, fn(string $name): string => '[[value:' . $name . ']]', false);
        return pageContentFromHtml(langFor($lang, $key, $params), $md);
    };
    // A condition wraps what it governs. A whole block or a list item gets the markers on lines of their
    // own, which is what lets the resolver take the line break with a hidden one — a hidden item leaves no
    // blank line, so a list stays one list; a part of a sentence gets them in the line, with the space that
    // joins it to the sentence inside, so a hidden part leaves no double space. 'if' and 'ifnot' on one
    // block are both true or it is hidden: nested, 'if' outside.
    $wrap = function ($x, string $body, bool $inline = false): string {
        if (!is_array($x)) return $body;
        foreach (['ifnot', 'if'] as $m) {
            if (!isset($x[$m])) continue;
            $open = '[[' . $m . ':' . $x[$m] . ']]';
            $body = $inline ? $open . $body . '[[/if]]' : $open . "\n" . $body . "\n[[/if]]";
        }
        return $body;
    };
    $sentence = function (array $parts) use ($part, $wrap): string {
        $out = '';
        foreach (array_values($parts) as $i => $p) $out .= $wrap($p, ($i ? ' ' : '') . $part($p), true);
        return $out;
    };
    // Headings: Markdown has them, BBCode does not — see the file header. `[size]` is the closest
    // BBCode gets. Concatenated, never interpolated: inside a double-quoted string PHP reads `$t[`
    // as the start of an array index, so "[b]$t[/b]" is a parse error rather than the BBCode it
    // looks like.
    $heading = fn(int $level, string $x): string => $md
        ? str_repeat('#', $level) . ' ' . $x
        : '[size=' . [1 => 24, 2 => 19, 3 => 16][$level] . '][b]' . $x . '[/b][/size]';
    $block = function (array $b) use (&$block, $md, $part, $wrap, $sentence, $heading): string {
        $items = pageContentItems($b);
        switch ($b[0]) {
            case 'h1': case 'h2': case 'h3':
                $s = $heading((int)substr($b[0], 1), $part($items[0]));
                break;
            case 'p':
                $s = $sentence($items);
                break;
            case 'ol': case 'ul':
                // Every item on a line of its own, a marked one wrapped whole. The literal numbers go
                // non-sequential when one is hidden, and that is fine — Markdown and [list=1] both
                // renumber from the first item.
                $lines = [];
                foreach (array_values($items) as $n => $p) {
                    $bullet = $md ? ($b[0] === 'ol' ? ($n + 1) . '. ' : '- ') : '[*] ';
                    $lines[] = $wrap($p, $bullet . $part($p));
                }
                $s = implode("\n", $lines);
                if (!$md) $s = ($b[0] === 'ol' ? "[list=1]\n" : "[list]\n") . $s . "\n[/list]";
                break;
            case 'faq':
                $q = array_shift($items);
                $s = ($md ? '**' . $part($q) . '**' : '[b]' . $part($q) . '[/b]') . "\n\n" . $sentence($items);
                break;
            case 'group':
                $s = implode("\n\n", array_map($block, $items));
                break;
            default:
                $s = '';
        }
        return $wrap($b, $s);
    };
    return rtrim(implode("\n\n", array_map($block, $spec))) . "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// The shipped pages, as data (1.73.0): one description, two renderings
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The built-in Terms and Info, as blocks — the ONE description of both pages.
 *
 * Until 1.73.0 every condition on these pages was written twice: as PHP in templates/pages/*.php and
 * as a marker in pageContentDefault(). Two copies of a condition is one that goes stale, and with a
 * paragraph for every optional feature there would have been forty of them. Now the templates render
 * this list (pageContentHtml()) and "Restore built-in" writes the same list out as text with the
 * markers (pageContentDefault()); tests/pagecontent_test.php renders both and holds them together.
 *
 * A block is an array whose first member is its type:
 *   ['h1'|'h2'|'h3', key]            a heading
 *   ['p', part, part, …]             one paragraph: its parts joined by a space
 *   ['ol'|'ul', part, part, …]       a numbered / bulleted list: one part per item
 *   ['faq', question-key, part, …]   a question and its answer
 *   ['group', block, block, …]       several blocks under one condition
 * and any block may carry 'if' => name and/or 'ifnot' => name (pageContentConditions()). A part is a
 * dictionary key, or [key, 'if' => …, 'ifnot' => …, 'url' => route, 'urls' => [param => route],
 * 'vals' => [param => pageContentValues() name]].
 *
 * A GROUP'S CONDITION MUST GUARANTEE SOMETHING UNDER ITS HEADING: a heading is drawn whenever its group
 * is, so a group whose every paragraph is conditional needs a condition that implies one of them
 * (`saved` for favourites-or-lists, `people`, `writing`) — scratchpad/shots/texts_check.js renders every
 * combination it can reach and fails on a heading with nothing under it.
 *
 * What every sentence claims, and where the code makes it true, is in the 1.73.0 claims table
 * (scratchpad/progress_1730c.md); the words are in tools/lang_src.d/info.py and terms_of_service.py.
 */
function pageContentSpec(string $page): array {
    if ($page === 'tos') {
        return [
            ['h1', 'tos.h1'],
            ['p', 'tos.intro'],
            ['ol', 'tos.r1', 'tos.r2', 'tos.r3', 'tos.r4', 'tos.r5', 'tos.r6', ['tos.r7', 'url' => 'info'],
                   ['tos.r_wl', 'if' => 'whitelist_or_schedule'], ['tos.r_index', 'if' => 'index'],
                   ['tos.r_report', 'url' => 'report'], 'tos.r8', 'tos.r9'],
            ['group', ['h2', 'tos.acc_head'],
                      ['ol', 'tos.acc1', 'tos.acc_own', ['tos.acc_twofa', 'if' => 'twofa'], ['tos.acc2', 'url' => 'info'],
                             'tos.acc3', ['tos.acc3_api', 'if' => 'api'], 'tos.acc4',
                             ['tos.acc4_cooldown', 'if' => 'email_cooldown', 'vals' => ['days' => 'email_change_days']],
                             'tos.acc5', 'tos.acc_groups',
                             ['tos.acc_bridge', 'if' => 'bridge'], 'tos.acc6', 'tos.acc7', ['tos.acc8', 'if' => 'search']],
                      'if' => 'users'],
            ['group', ['h2', 'tos.w_head'],
                      ['ol', 'tos.w1', ['tos.w_comments', 'if' => 'comments'], ['tos.w_guests', 'if' => 'guest_comments'],
                             ['tos.w_descriptions', 'if' => 'descriptions'], ['tos.w_lists', 'if' => 'lists'],
                             ['tos.w_messages', 'if' => 'messages'], ['tos.w_shout', 'if' => 'shoutbox'],
                             ['tos.w_profile', 'if' => 'pictures']],
                      'if' => 'community'],
            ['group', ['h2', 'tos.m_head'],
                      ['ol', ['tos.m1', 'if' => 'report_words'], 'tos.m2', 'tos.m3', 'tos.m4', 'tos.m5'],
                      'if' => 'community'],
            ['group', ['h2', 'tos.s_head'],
                      ['ol', 'tos.s1', ['tos.s1_new', 'if' => 'antispam_new', 'vals' => ['days' => 'antispam_new_days']], 'tos.s2'],
                      'if' => 'antispam'],
            ['group', ['h2', 'tos.lang_head'], ['p', 'tos.lang1'], 'if' => 'languages'],
        ];
    }
    if ($page !== 'info') return [];
    return [
        ['h1', 'info.h1'],
        ['h2', 'info.q_what'], ['p', 'info.a_what'],
        ['h2', 'info.q_ot'], ['p', 'info.a_ot'],
        ['h2', 'info.q_how'], ['p', 'info.a_how'], ['p', 'info.a_memory'],
        ['group', ['h2', 'info.q_wl'], ['p', 'info.a_wl'], 'if' => 'whitelist'],
        ['group', ['h2', 'info.q_sched'],
                  ['p', ['info.a_sched', 'vals' => ['hours' => 'schedule_hours']],
                        ['info.a_sched_wl', 'if' => 'whitelist'], ['info.a_sched_open', 'ifnot' => 'whitelist']],
                  'if' => 'schedule'],
        ['group', ['h2', 'info.q_reg'],
                  ['p', ['info.a_reg_public', 'url' => 'whitelist', 'ifnot' => 'registration_members'],
                        ['info.a_reg_members', 'url' => 'whitelist', 'if' => 'registration_members'],
                        ['info.a_reg_probe', 'if' => 'probe']],
                  'if' => 'registration'],
        ['p', 'info.a_wl_api', 'if' => 'whitelist_or_schedule', 'ifnot' => 'registration'],
        ['group', ['h2', 'info.q_index'],
                  ['p', 'info.a_index', ['info.a_index_fed', 'if' => 'federation']],
                  ['p', ['info.a_index_life', 'vals' => ['grace' => 'index_grace_days', 'protect' => 'index_protect_days']],
                        ['info.a_index_kept', 'if' => 'index_kept']],
                  ['p', ['info.a_index_search', 'url' => 'search'], 'if' => 'search'],
                  'if' => 'index'],
        ['group', ['h2', 'info.q_accounts'],
                  ['p', 'info.a_accounts', ['info.a_accounts_verify', 'if' => 'email_verify'],
                        ['info.a_accounts_email', 'ifnot' => 'email_verify'], ['info.a_accounts_twofa', 'if' => 'twofa']],
                  ['p', 'info.a_devices'],
                  ['p', 'info.a_groups', ['info.a_groups_shop', 'if' => 'api']],
                  ['p', 'info.a_notify', ['info.a_sounds', 'if' => 'sounds']],
                  ['p', 'info.a_pictures', 'if' => 'pictures'],
                  ['p', 'info.a_bridge', 'if' => 'bridge'],
                  'if' => 'users'],
        ['group', ['h2', 'info.q_profiles'],
                  ['p', 'info.a_profiles', ['info.a_profiles_bio', 'if' => 'bio'], 'info.a_profiles_choice'],
                  'if' => 'profiles'],
        ['group', ['h2', 'info.q_saved'],
                  ['p', ['info.a_favourites', 'if' => 'favourites'], ['info.a_lists', 'if' => 'lists'],
                        ['info.a_lists_public', 'if' => 'lists_public'], ['info.a_lists_friends', 'if' => 'lists_friends']],
                  'if' => 'saved'],
        ['group', ['h2', 'info.q_people'],
                  ['p', 'info.a_friends', 'if' => 'friends'],
                  ['p', 'info.a_directory', 'if' => 'directory'],
                  ['p', 'info.a_messages', 'info.a_messages_archive',
                        ['info.a_messages_returns', 'if' => 'archive_returns'], ['info.a_messages_stays', 'ifnot' => 'archive_returns'],
                        ['info.a_messages_trash', 'if' => 'trash', 'vals' => ['days' => 'pm_trash_days']],
                        ['info.a_messages_now', 'ifnot' => 'trash'], 'info.a_messages_keep',
                        ['info.a_messages_private', 'if' => 'trash'], ['info.a_messages_private_archive', 'ifnot' => 'trash'],
                        'if' => 'messages'],
                  'if' => 'people'],
        ['group', ['h2', 'info.q_words'],
                  ['p', 'info.a_comments', ['info.a_comments_guest', 'if' => 'guest_comments'], 'if' => 'comments'],
                  ['p', 'info.a_descriptions', 'if' => 'descriptions'],
                  ['p', 'info.a_ratings', 'if' => 'ratings'],
                  'if' => 'writing'],
        ['group', ['h2', 'info.q_shout'],
                  ['p', ['info.a_shout', 'vals' => ['days' => 'shout_keep_days', 'rows' => 'shout_keep_rows']]],
                  'if' => 'shoutbox'],
        ['h2', 'info.q_reports'],
        ['p', ['info.a_report', 'urls' => ['url' => 'report', 'status' => 'status']],
              ['info.a_report_transparency', 'if' => 'transparency', 'url' => 'transparency']],
        ['p', ['info.a_moderation', 'if' => 'reportable'], ['info.a_moderation_pm', 'if' => 'messages'],
              ['info.a_moderation_what', 'url' => 'tos'], 'if' => 'community'],
        ['group', ['h2', 'info.q_antispam'],
                  ['p', 'info.a_antispam', ['info.a_antispam_new', 'if' => 'antispam_new', 'vals' => ['days' => 'antispam_new_days']],
                        'info.a_antispam_keep'],
                  'if' => 'antispam'],
        ['h2', 'info.q_data'],
        ['p', 'info.a_data'],
        ['ul', 'info.d_tracker',
               ['info.d_stats', 'if' => 'stats'],
               ['info.d_index', 'if' => 'index'],
               ['info.d_registrations', 'if' => 'whitelist_or_schedule'],
               'info.d_reports',
               ['info.d_account', 'if' => 'users'],
               ['info.d_twofa', 'if' => 'twofa'],
               ['info.d_devices', 'if' => 'users'],
               ['info.d_messages', 'if' => 'messages'],
               ['info.d_comments', 'if' => 'comments'],
               ['info.d_descriptions', 'if' => 'descriptions'],
               ['info.d_votes', 'if' => 'ratings'],
               ['info.d_shouts', 'if' => 'shoutbox', 'vals' => ['days' => 'shout_keep_days']],
               ['info.d_moderation', 'if' => 'community'],
               ['info.d_antispam', 'if' => 'antispam'],
               'info.d_limits',
               ['info.d_audit', 'if' => 'audit', 'ifnot' => 'users', 'vals' => ['days' => 'audit_keep_days']],
               ['info.d_audit_members', 'if' => 'audit_members', 'vals' => ['days' => 'audit_keep_days']],
               ['info.d_partners', 'if' => 'api'],
               ['info.d_backups', 'if' => 'backup_days', 'vals' => ['days' => 'backup_keep_days']],
               ['info.d_backups_any', 'if' => 'backups', 'ifnot' => 'backup_days'],
               ['info.d_csp', 'if' => 'csp_reports'],
               'info.d_weblog'],
        ['p', 'info.a_delete', 'if' => 'users'],
        ['h2', 'info.q_cookies'],
        ['ul', 'info.c_session',
               ['info.c_remember', 'if' => 'users'],
               ['info.c_lang', 'if' => 'languages'],
               'info.c_storage',
               ['info.c_cdn', 'if' => 'icons_cdn'],
               ['info.c_captcha', 'if' => 'captcha'],
               ['info.c_images', 'if' => 'images'],
               'info.c_none'],
        ['h2', 'info.faq_head'],
        ['faq', 'info.faq_q1', 'info.faq_a1', ['info.faq_a1_wl', 'if' => 'whitelist']],
        ['faq', 'info.faq_q2', 'info.faq_a2', ['info.faq_a2_index', 'if' => 'index']],
        ['faq', 'info.faq_q3', 'info.faq_a3'],
        ['faq', 'info.faq_q4', 'info.faq_a4'],
        ['faq', 'info.faq_q5', 'info.faq_a5'],
        ['faq', 'info.faq_q6', 'info.faq_a6', 'if' => 'languages'],
        ['faq', 'info.faq_q7', 'info.faq_a7', 'if' => 'users'],
    ];
}

/** The members of a block after its type — its parts, items or inner blocks — in order. */
function pageContentItems(array $b): array {
    $out = [];
    foreach ($b as $k => $v) if (is_int($k) && $k > 0) $out[] = $v;
    return $out;
}

/**
 * Every condition and value the spec of a page names — for the test that holds the spec to
 * pageContentConditions() / pageContentValues(): a name the spec uses and the list does not know is
 * a paragraph that is never shown (an unknown condition is false), and a value it does not know is a
 * sentence with a hole in it.
 *
 * @return array{conds: string[], vals: string[], keys: string[]}
 */
function pageContentSpecNames(string $page): array {
    $conds = []; $vals = []; $keys = [];
    $walk = function ($x) use (&$walk, &$conds, &$vals, &$keys): void {
        if (!is_array($x)) { $keys[] = (string)$x; return; }
        foreach (['if', 'ifnot'] as $m) if (isset($x[$m])) $conds[] = (string)$x[$m];
        foreach (($x['vals'] ?? []) as $name) $vals[] = (string)$name;
        if (isset($x[0]) && in_array($x[0], ['h1', 'h2', 'h3', 'p', 'ol', 'ul', 'faq', 'group'], true)) {
            foreach (pageContentItems($x) as $i) $walk($i);
        } else {
            $keys[] = (string)$x[0];
        }
    };
    foreach (pageContentSpec($page) as $b) $walk($b);
    return ['conds' => array_values(array_unique($conds)), 'vals' => array_values(array_unique($vals)),
            'keys' => array_values(array_unique($keys))];
}

/**
 * A part's dictionary key and its parameters. `url` / `urls` name ROUTES (the page builds the address
 * from the site's own base), `vals` name pageContentValues() entries; $value says how a value is
 * written — the number itself on the page, a [[value:…]] marker in the editor's default text.
 */
function pageContentPartArgs($p, string $baseUrl, callable $value, bool $html): array {
    if (!is_array($p)) return [(string)$p, []];
    $params = [];
    $routes = $p['urls'] ?? [];
    if (isset($p['url'])) $routes['url'] = $p['url'];
    foreach ($routes as $param => $route) {
        $u = $baseUrl . '?action=' . $route;
        $params[$param] = $html ? htmlspecialchars($u, ENT_QUOTES, 'UTF-8') : $u;
    }
    foreach (($p['vals'] ?? []) as $param => $name) $params[$param] = $value((string)$name);
    return [(string)$p[0], $params];
}

/**
 * The shipped page as HTML — what templates/pages/info.php and tos.php print.
 *
 * The same blocks as pageContentDefault(), decided here with the same conditions: a block or a part
 * whose condition is off is simply not written, and a paragraph or a list left with nothing in it is
 * not written either, so no feature that is off leaves a heading, a bullet or an empty line behind.
 * The dictionary's strings may carry the few tags a translation may (LANG_SAFE_TAGS) — the ones that
 * do are echoed as they are, as the templates always echoed them; every other one is escaped.
 */
function pageContentHtml(?PDO $db, array $cfg, string $page, string $baseUrl): string {
    $spec = pageContentSpec($page);
    if (!$spec) return '';
    $conds = pageContentConditions($cfg, $db);
    $vals  = pageContentValues($cfg, $db);
    $shown = fn($x): bool => !is_array($x)
        || ((!isset($x['if']) || !empty($conds[$x['if']][0])) && (!isset($x['ifnot']) || empty($conds[$x['ifnot']][0])));
    $part = function ($p) use ($baseUrl, $vals): string {
        [$key, $params] = pageContentPartArgs($p, $baseUrl,
            fn(string $name): string => htmlspecialchars(pageContentValueText($vals[$name][0] ?? '', $vals[$name][2] ?? ''), ENT_QUOTES, 'UTF-8'), true);
        $s = __($key, $params);
        return preg_match('/<(?:a|strong|em|b|i|code|kbd|br|span|small|sup)\b/i', $s) ? $s : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    $sentence = function (array $parts) use ($part, $shown): string {
        $out = [];
        foreach ($parts as $p) if ($shown($p)) $out[] = $part($p);
        return implode(' ', $out);
    };
    $block = function (array $b) use (&$block, $part, $shown, $sentence): string {
        if (!$shown($b)) return '';
        $items = pageContentItems($b);
        switch ($b[0]) {
            case 'h1': case 'h2': case 'h3':
                return '<' . $b[0] . '>' . $part($items[0]) . '</' . $b[0] . ">\n";
            case 'p':
                $s = $sentence($items);
                return $s === '' ? '' : '<p>' . $s . "</p>\n";
            case 'ol': case 'ul':
                $li = '';
                foreach ($items as $p) if ($shown($p)) $li .= '    <li>' . $part($p) . "</li>\n";
                if ($li === '') return '';
                return $b[0] === 'ol' ? "<ol class=\"tos-list\">\n" . $li . "</ol>\n" : "<ul class=\"feature-list\">\n" . $li . "</ul>\n";
            case 'faq':
                $q = array_shift($items);
                $a = $sentence($items);
                if ($a === '') return '';
                return '<div class="faq-item"><div class="faq-q">' . $part($q) . '</div><div class="faq-a">' . $a . "</div></div>\n";
            case 'group':
                return implode('', array_map($block, $items));
        }
        return '';
    };
    return implode('', array_map($block, $spec));
}
