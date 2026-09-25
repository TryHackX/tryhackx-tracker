<?php
/**
 * The site's icons: which library draws them, and the one place a page asks for its font (1.68.0),
 * and since 1.69.0 WHICH Font Awesome — Free 6.7.2 or Free 7.3.1 from jsDelivr, or a package the
 * operator installed (includes/iconpack.php: Pro 6 or Pro 7, whatever styles they bought) — and which
 * of its styles the site's own icons use.
 *
 * Every icon on the site is written ONE way, in Bootstrap Icons' markup — `<i class="bi bi-NAME">` in
 * a template, `el('i', { className: 'bi bi-NAME' })` in a script — and `icon_library` (Settings →
 * Site) decides what draws it:
 *
 *   * 'bootstrap' (the default): Bootstrap Icons 1.11.3, the font the site has always used. Nothing
 *     below touches a byte of the page.
 *   * 'fontawesome': Font Awesome. The `bi` / `bi-NAME` classes STAY — every stylesheet rule, every
 *     querySelector and every test that names `.bi` or `.bi-trash` keeps working — and the matching
 *     Font Awesome classes are ADDED beside them, through one name map.
 *
 * Why one markup and a map, rather than two sets of markup: roughly 550 icons live in 38 files,
 * half of them built by scripts, several chosen by branches (`on ? 'bi-pin-angle-fill' : …`) and
 * swapped later by reassigning `className`. Writing every one of them twice would be two lists that
 * drift apart the first time somebody adds an icon; a map is one list, and tests/icons_test.php
 * fails the day a `bi-*` name appears that it does not know.
 *
 * The font is loaded on EVERY page, public and panel alike. Until 1.68.0 the public layout asked for
 * it on a handful of actions, and an icon drawn anywhere else (the inbox's bin with pictures and
 * covers switched off, for one) was an empty box.
 */

require_once __DIR__ . '/iconpack.php';

/** The two answers `icon_library` may hold; anything else reads as the first. */
const ICON_LIBRARIES = ['bootstrap', 'fontawesome'];

/**
 * Where Font Awesome comes from (1.69.0, `fa_source`): Free 6.7.2 or Free 7.3.1 from jsDelivr, or an
 * installed package (`fa_pack`). cdn6 is what 1.68 drew, and what anything unrecognised means.
 */
const ICON_FA_SOURCES = ['cdn6', 'cdn7', 'pack'];

// The stylesheets from jsDelivr, pinned to a version and carrying Subresource Integrity, as every
// third-party file on this site does. The policy already allows jsDelivr for style-src and font-src
// (includes/csp.php and the .htaccess fallback), so no choice here needs the policy to move — and a
// package is served by this site itself ('self').
//
// Font Awesome is the CSS WEBFONT build (css/all.min.css, whose fonts load from ../webfonts/ on the
// same host) and never the JS/SVG build: that one would need jsDelivr in the PUBLIC script-src, which
// is kept out on purpose — nothing on a page every anonymous visitor sees needs a CDN to run code.
// Each integrity value is jsDelivr's own SHA-256 of the file, read from its package metadata
// (data.jsdelivr.com/v1/packages/npm/@fortawesome/fontawesome-free@<version>?structure=flat).
const ICON_BOOTSTRAP_CSS = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';
const ICON_BOOTSTRAP_SRI = 'sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+';
const ICON_FONTAWESOME_CSS = 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css';
const ICON_FONTAWESOME_SRI = 'sha256-dABdfBfUoC8vJUBOwGVdm8L9qlMWaHTIfXt+7GnZCIo=';
const ICON_FONTAWESOME7_CSS = 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css';
const ICON_FONTAWESOME7_SRI = 'sha256-4Lad8m4ZWW1Lgb9+sMVLYEfnIh7BjV1NQMEe79Pviks=';

/**
 * Which library draws the icons. Only the exact word 'fontawesome' switches: a settings row can come
 * from a restored backup or a MySQL client, and a value nobody recognises must mean the library the
 * site has always had, not a half-mapped page.
 */
function iconLibrary(array $cfg): string {
    return (string)($cfg['icon_library'] ?? '') === 'fontawesome' ? 'fontawesome' : 'bootstrap';
}

/** Where Font Awesome comes from, read the same careful way: anything but the three words is cdn6. */
function iconFaSource(array $cfg): string {
    $s = (string)($cfg['fa_source'] ?? '');
    return in_array($s, ICON_FA_SOURCES, true) ? $s : 'cdn6';
}

/** The style files of the package the operator ticked (`fa_pack_styles`, a JSON list of style keys). */
function iconFaPackStyles(array $cfg): array {
    $j = json_decode((string)($cfg['fa_pack_styles'] ?? '[]'), true);
    if (!is_array($j)) return [];
    $out = [];
    foreach ($j as $k) if (is_string($k) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $k) && !in_array($k, $out, true)) $out[] = $k;
    return $out;
}

/**
 * The styles jsDelivr's Free builds offer the site's icons: solid, which has every Free icon, and
 * regular, which has 170 of them (41 of the names the map draws — iconFreeRegular()). Brands are
 * always there and are not a choice: a brand icon exists in no other style.
 */
function iconCdnStyles(int $major): array {
    $fam = 'Font Awesome ' . $major . ' Free';
    return [
        'solid'   => ['key' => 'solid', 'label' => 'Solid', 'family' => 'classic', 'style' => 'solid', 'weight' => 900, 'font_family' => $fam, 'classes' => 'fa-solid', 'in_all' => true, 'layers' => 1],
        'regular' => ['key' => 'regular', 'label' => 'Regular', 'family' => 'classic', 'style' => 'regular', 'weight' => 400, 'font_family' => $fam, 'classes' => 'fa-regular', 'in_all' => true, 'layers' => 1],
        'brands'  => ['key' => 'brands', 'label' => 'Brands', 'family' => 'brands', 'style' => 'regular', 'weight' => 400, 'font_family' => 'Font Awesome ' . $major . ' Brands', 'classes' => 'fa-brands', 'in_all' => true, 'layers' => 1],
    ];
}

/**
 * The names the map draws that Free has in its REGULAR style — checked against the Free metadata of
 * both 6.7.2 and 7.3.1 (fontawesome-free's metadata/icon-families.json, familyStylesByLicense.free),
 * where the forty-one are the same. Everything else exists in Free only in solid, so a Free regular site
 * draws those solid rather than draw nothing (tests/iconpack_test.php holds this list to that metadata
 * wherever a copy of it is at hand).
 */
function iconFreeRegular(): array {
    return ['bell', 'calendar-days', 'circle', 'circle-check', 'circle-play', 'circle-stop', 'circle-up', 'circle-user',
            'circle-xmark', 'clipboard', 'comment-dots', 'copy', 'envelope', 'eye', 'eye-slash', 'face-smile', 'file',
            'file-audio', 'file-code', 'file-image', 'file-lines', 'flag', 'folder', 'folder-open', 'hard-drive', 'heart',
            'hourglass-half', 'id-badge', 'image', 'lightbulb', 'message', 'paper-plane', 'pen-to-square', 'rectangle-list', 'square',
            'square-check', 'star', 'thumbs-down', 'thumbs-up', 'trash-can', 'user'];
}

/**
 * Bootstrap Icons name → what Font Awesome draws for it.
 *
 * [0] the Free icon name (6.7.2; every one of them is also a Free 7.3.1 name — `v7` is where they
 *     would part), [1] the ROLE, which is what keeps a pair a pair whichever style is chosen:
 *       s  follows the chosen style                     (a gear is a solid gear, a light gear, …)
 *       r  an OUTLINE: Bootstrap's outline of a pair     (the empty star beside the filled one)
 *       f  FILLED: Bootstrap's `-fill` half of a pair    (the lit star, the reported flag, a pinned pin)
 *       b  a brand, drawn only by the brands font
 *    With the solid style — which is what 1.68 drew — s and f are solid and r is regular: the 1.68 map
 *    exactly.
 * approx: Free has no direct twin, and the name is the closest honest glyph (the comment says why).
 * pro: the exact twin a Pro package has — only twins that exist in the owner's Pro 6.7.2 and 7.3.1
 *      (each was drawn beside Bootstrap's glyph from those packages before it went in), pro6/pro7
 *      where the two versions differ (7 has the angled pushpin and a dot, 6 has neither). prorole is
 *      the role in Pro when it is not the entry's: an outline Free does not have (the badge, the
 *      shields, the pushpin — Free has one, filled, so pinned or not is a colour there; Pro draws the
 *      pair). The twin may be the Free name itself (hdd-stack: Pro's server IS Bootstrap's drawing, in
 *      outline). A package that lacks the twin draws the Free name — a fallback, listed in the panel.
 * proapprox: the Pro choice is CLOSER than Free's and still not the very icon (1.69.0, searched in the
 *      owner's Pro 6 and 7 indexes and drawn beside Bootstrap's glyph: the All settings chip's grid
 *      without its tick, the home layout's tiles mirrored) — drawn, and still counted an approximation.
 *
 * Every `bi-*` name used anywhere on the site has an entry: tests/icons_test.php reads every template,
 * include, script and dictionary source and fails on a name that is missing, because a missing name is
 * an icon that silently draws nothing once Font Awesome is chosen. Every entry was drawn on a live page
 * against the font's own "missing glyph" box before it went in.
 */
function iconFaEntries(): array {
    return [
        'activity'                   => ['heart-pulse', 's', 'approx' => 1, 'pro' => 'wave-pulse'],   // ~ no bare pulse line in Free: the same line drawn through a heart
        'archive'                    => ['box-archive', 's'],
        'arrow-clockwise'            => ['arrow-rotate-right', 's'],
        'arrow-counterclockwise'     => ['arrow-rotate-left', 's'],
        'arrow-down'                 => ['arrow-down', 's'],
        'arrow-down-up'              => ['arrows-up-down', 's', 'approx' => 1, 'pro' => 'arrow-down-arrow-up'],   // ~ one double-headed arrow for Bootstrap's pair of arrows
        'arrow-left-right'           => ['arrow-right-arrow-left', 's'],
        'arrow-repeat'               => ['arrows-rotate', 's'],
        'arrow-right'                => ['arrow-right', 's'],
        'arrow-up'                   => ['arrow-up', 's'],
        'arrow-up-circle'            => ['circle-up', 'r'],
        'arrows-move'                => ['arrows-up-down-left-right', 's'],
        'award'                      => ['award', 's'],
        'bar-chart-steps'            => ['chart-gantt', 's'],
        'bell'                       => ['bell', 'r'],
        'book'                       => ['book-open', 's'],
        'bootstrap-reboot'           => ['power-off', 's', 'approx' => 1],   // ~ Bootstrap's own "reboot" mark: the power symbol says restart (Pro has no power-and-arrow either; its circular arrows are the site's refresh)
        'box-arrow-in-down'          => ['file-import', 's'],
        'box-arrow-right'            => ['right-from-bracket', 's'],
        'box-arrow-up-right'         => ['arrow-up-right-from-square', 's'],
        'bug'                        => ['bug', 's'],
        'calendar-event'             => ['calendar-day', 's'],
        'calendar-range'             => ['calendar-days', 's', 'pro' => 'calendar-range'],   // the menus' "last 14 days": Pro draws the range itself
        'calendar-week'              => ['calendar-week', 's'],
        'car-front'                  => ['car', 's'],   // the emoji picker's Travel & places tab (1.69.0)
        'card-text'                  => ['rectangle-list', 'r'],
        'chat-left-dots'             => ['comment-dots', 'r', 'pro' => 'message-dots'],   // Pro: Bootstrap's square bubble, not the round one
        'chat-left-text'             => ['message', 'r', 'pro' => 'message-lines'],   // Pro: the bubble with its lines of text, as Bootstrap's
        'check-circle'               => ['circle-check', 'r'],
        'check-circle-fill'          => ['circle-check', 'f'],
        'check-lg'                   => ['check', 's'],
        'check-square'               => ['square-check', 'r'],
        'check2'                     => ['check', 's'],
        'check2-all'                 => ['check-double', 's'],
        'check2-circle'              => ['circle-check', 'r'],
        'chevron-double-left'        => ['angles-left', 's'],
        'chevron-double-right'       => ['angles-right', 's'],
        'chevron-down'               => ['chevron-down', 's'],
        'chevron-left'               => ['chevron-left', 's'],
        'chevron-right'              => ['chevron-right', 's'],
        'chevron-up'                 => ['chevron-up', 's'],
        'circle'                     => ['circle', 'r'],
        'circle-fill'                => ['circle', 'f'],
        'clipboard'                  => ['clipboard', 'r'],
        'clipboard-check'            => ['clipboard-check', 's'],
        'clock-history'              => ['clock-rotate-left', 's'],
        'cloud-download'             => ['cloud-arrow-down', 's'],
        'code-slash'                 => ['code', 's'],
        'collection'                 => ['layer-group', 's', 'approx' => 1, 'pro' => 'rectangle-history', 'prorole' => 'r'],   // ~ a stack of cards: a stack of layers
        'cpu'                        => ['microchip', 's'],
        'crosshair'                  => ['crosshairs', 's'],
        'cup-hot'                    => ['mug-hot', 's'],   // the emoji picker's Food & drink tab (1.69.0)
        'dash-circle'                => ['circle-minus', 's'],
        'dash-lg'                    => ['minus', 's'],
        'database-down'              => ['database', 's', 'approx' => 1],   // ~ no database-with-arrow in Free or Pro (6 or 7: their indexes searched)
        'database-gear'              => ['database', 's', 'approx' => 1],   // ~ no database-with-gear in Free or Pro (6 or 7: their indexes searched)
        'diagram-3'                  => ['sitemap', 's'],
        'display'                    => ['display', 's'],
        // ~ no dot glyph in Free or Pro 6: the circle, drawn at 3/8 of its size (style.css / admin.css scale
        //   `.bi-dot.fa-circle` only, so Pro 7's own dot — Bootstrap's size already — is drawn as it is)
        'dot'                        => ['circle', 'f', 'approx' => 1, 'pro7' => 'dot'],
        'download'                   => ['download', 's'],
        'emoji-smile'                => ['face-smile', 'r'],
        'envelope'                   => ['envelope', 'r'],
        'envelope-paper'             => ['envelope-open-text', 's', 'approx' => 1, 'prorole' => 'r'],   // ~ an envelope with its letter showing: the open one (in Pro, its outline)
        'eraser'                     => ['eraser', 's'],
        'exclamation-circle-fill'    => ['circle-exclamation', 'f'],
        'exclamation-octagon'        => ['circle-exclamation', 's', 'approx' => 1, 'pro' => 'octagon-exclamation', 'prorole' => 'r'],   // ~ no octagon in Free
        'exclamation-octagon-fill'   => ['circle-exclamation', 'f', 'approx' => 1, 'pro' => 'octagon-exclamation'],   // ~ no octagon in Free
        'exclamation-triangle'       => ['triangle-exclamation', 's'],
        'exclamation-triangle-fill'  => ['triangle-exclamation', 'f'],
        'eye'                        => ['eye', 'r'],
        'eye-slash'                  => ['eye-slash', 'r'],
        'file-earmark'               => ['file', 'r'],
        'file-earmark-arrow-down'    => ['file-arrow-down', 's'],
        'file-earmark-arrow-up'      => ['file-arrow-up', 's'],
        'file-earmark-code'          => ['file-code', 'r'],
        'file-earmark-image'         => ['file-image', 'r'],
        'file-earmark-music'         => ['file-audio', 'r'],
        'file-earmark-text'          => ['file-lines', 'r'],
        'files'                      => ['copy', 'r'],
        'flag'                       => ['flag', 'r'],
        'flag-fill'                  => ['flag', 'f'],
        'folder2'                    => ['folder', 'r'],
        'folder2-open'               => ['folder-open', 'r'],
        'fonts'                      => ['font', 's'],
        'gear'                       => ['gear', 's'],
        'globe2'                     => ['globe', 's'],
        'graph-up'                   => ['chart-line', 's'],
        'grid-1x2'                   => ['table-columns', 's', 'approx' => 1, 'pro' => 'rectangles-mixed', 'prorole' => 'r', 'proapprox' => 1],   // ~ one tall tile beside two small ones: two columns; Pro has the very tiles, mirrored
        'grip-vertical'              => ['grip-vertical', 's'],
        'hand-thumbs-down'           => ['thumbs-down', 'r'],
        'hand-thumbs-down-fill'      => ['thumbs-down', 'f'],
        'hand-thumbs-up'             => ['thumbs-up', 'r'],
        'hand-thumbs-up-fill'        => ['thumbs-up', 'f'],
        'hdd'                        => ['hard-drive', 'r'],
        'hdd-network'                => ['network-wired', 's', 'approx' => 1],   // ~ a drive on a network: the network (Pro 7's nas is a drive box without one)
        'hdd-stack'                  => ['server', 's', 'approx' => 1, 'pro' => 'server', 'prorole' => 'r'],   // ~ stacked drives: a server — which Pro draws as Bootstrap does, in outline
        'heart'                      => ['heart', 'r'],
        'highlighter'                => ['highlighter', 's'],
        'hourglass-split'            => ['hourglass-half', 'r'],
        'image'                      => ['image', 'r'],
        'inbox'                      => ['inbox', 's'],
        'info-circle'                => ['circle-info', 's'],
        'info-circle-fill'           => ['circle-info', 'f'],
        'journal-text'               => ['book', 's'],
        'key'                        => ['key', 's'],
        'lightbulb'                  => ['lightbulb', 'r'],   // the emoji picker's Objects tab (1.69.0); an outline, as Bootstrap's
        'link-45deg'                 => ['link', 's'],
        'list-check'                 => ['list-check', 's'],
        'list-ol'                    => ['list-ol', 's'],
        'list-ul'                    => ['list-ul', 's'],
        'lock'                       => ['lock', 's'],
        'lock-fill'                  => ['lock', 'f'],
        'magic'                      => ['wand-magic-sparkles', 's'],
        'magnet'                     => ['magnet', 's'],
        'megaphone'                  => ['bullhorn', 's', 'pro' => 'megaphone', 'prorole' => 'r'],   // the Appeals tab: Pro has Bootstrap's cone, in outline
        'palette'                    => ['palette', 's'],
        // ~ Free has no badge with a tick (badge-check is Pro's): the tick in a circle, which reads as
        //   "verified" (1.69.0 — `certificate`, the badge WITHOUT the tick, read as a starburst). Outline
        //   and filled as Bootstrap's pair are; Pro draws the badge itself.
        'patch-check'                => ['circle-check', 'r', 'approx' => 1, 'pro' => 'badge-check'],   // ~ the verified tick, in a circle
        'patch-check-fill'           => ['circle-check', 'f', 'approx' => 1, 'pro' => 'badge-check'],   // ~ the verified tick, in a filled circle
        'pencil'                     => ['pencil', 's'],
        'pencil-square'              => ['pen-to-square', 'r'],
        'people'                     => ['user-group', 's'],
        'people-fill'                => ['users', 'f'],
        'person'                     => ['user', 'r'],
        'person-badge'               => ['id-badge', 'r'],
        'person-gear'                => ['user-gear', 's'],
        'person-plus'                => ['user-plus', 's'],
        'person-square'              => ['circle-user', 'r', 'approx' => 1, 'pro' => 'square-user'],   // ~ a person in a square: in a circle
        'person-x'                   => ['user-xmark', 's'],
        'phone'                      => ['mobile-screen-button', 's'],
        // ~ Free has one pushpin, so pinned or not is said by the button's colour and title; Pro has its
        //   outline (6 an upright one, still an approximation), and 7 the angled pin Bootstrap draws.
        'pin-angle'                  => ['thumbtack', 's', 'approx' => 1, 'pro7' => 'thumbtack-angle', 'prorole' => 'r'],
        'pin-angle-fill'             => ['thumbtack', 'f', 'pro7' => 'thumbtack-angle'],
        'play-circle'                => ['circle-play', 'r'],
        'play-fill'                  => ['play', 'f'],
        'plug'                       => ['plug', 's'],
        'plus-circle'                => ['circle-plus', 's'],
        'plus-lg'                    => ['plus', 's'],
        'plus-slash-minus'           => ['plus-minus', 's'],
        'quote'                      => ['quote-left', 's'],
        'reply'                      => ['reply', 's'],
        'scissors'                   => ['scissors', 's'],
        'search'                     => ['magnifying-glass', 's'],
        'send'                       => ['paper-plane', 'r'],
        'send-check'                 => ['envelope-circle-check', 's', 'approx' => 1],   // ~ a sent letter with a tick (no paper plane with one in Pro)
        'shield-check'               => ['shield-halved', 's', 'approx' => 1, 'pro' => 'shield-check', 'prorole' => 'r'],   // ~ no shield with a tick in Free
        'shield-fill'                => ['shield', 'f'],
        'shield-lock'                => ['shield-halved', 's', 'approx' => 1, 'pro' => 'shield-keyhole', 'prorole' => 'r'],   // ~ no shield with a lock in Free
        'shield-lock-fill'           => ['user-shield', 'f', 'approx' => 1, 'pro' => 'shield-keyhole'],   // ~ it marks the protected owner: a person behind a shield
        'shield-plus'                => ['shield-halved', 's', 'approx' => 1, 'pro' => 'shield-plus', 'prorole' => 'r'],   // ~ no shield with a plus in Free
        'shield-slash'               => ['ban', 's', 'approx' => 1, 'pro' => 'shield-slash', 'prorole' => 'r'],   // ~ no crossed-out shield in Free: it heads the block lists
        'shield-x'                   => ['ban', 's', 'approx' => 1, 'pro' => 'shield-xmark', 'prorole' => 'r'],   // ~ no shield with a cross in Free: everywhere it is used it means a ban
        'shuffle'                    => ['shuffle', 's'],
        'slash-circle'               => ['ban', 's'],
        'sliders'                    => ['sliders', 's'],
        'sliders2'                   => ['sliders', 's'],
        'speedometer2'               => ['gauge-high', 's'],
        'square'                     => ['square', 'r'],
        'star'                       => ['star', 'r'],
        'star-fill'                  => ['star', 'f'],
        'stop-circle'                => ['circle-stop', 'r'],
        'stopwatch'                  => ['stopwatch', 's'],
        'subscript'                  => ['subscript', 's'],
        'superscript'                => ['superscript', 's'],
        'table'                      => ['table', 's'],
        'text-center'                => ['align-center', 's'],
        'three-dots'                 => ['ellipsis', 's'],
        'translate'                  => ['language', 's'],
        'trash'                      => ['trash-can', 'r'],
        'tree'                       => ['tree', 's'],   // the emoji picker's Animals & nature tab (1.69.0)
        'trophy'                     => ['trophy', 's'],   // the emoji picker's Activities tab (1.69.0)
        'type-bold'                  => ['bold', 's'],
        'type-italic'                => ['italic', 's'],
        'type-strikethrough'         => ['strikethrough', 's'],
        'type-underline'             => ['underline', 's'],
        'ui-checks-grid'             => ['table-cells-large', 's', 'approx' => 1, 'pro' => 'grid-2', 'prorole' => 'r', 'proapprox' => 1],   // ~ a grid of ticked boxes: the grid; Pro has its four boxes, without the tick
        'unlock'                     => ['lock-open', 's'],
        'upload'                     => ['upload', 's'],
        'volume-mute'                => ['volume-xmark', 's'],
        'volume-up'                  => ['volume-high', 's'],
        'x'                          => ['xmark', 's'],
        'x-circle'                   => ['circle-xmark', 'r'],
        'x-lg'                       => ['xmark', 's'],
        'x-octagon'                  => ['circle-xmark', 's', 'approx' => 1, 'pro' => 'octagon-xmark', 'prorole' => 'r'],   // ~ no octagon in Free
        'youtube'                    => ['youtube', 'b'],
    ];
}

/**
 * Every Font Awesome name the map can ask for — the Free names, their 7.x spellings and every Pro twin —
 * which is the question an installed package answers once, at import (iconpackPresence()).
 */
function iconFaCandidateNames(): array {
    $out = [];
    foreach (iconFaEntries() as $e) {
        foreach ([$e[0], $e['v7'] ?? null, $e['pro'] ?? null, $e['pro6'] ?? null, $e['pro7'] ?? null] as $n) if ($n !== null) $out[$n] = true;
    }
    $out = array_keys($out);
    sort($out, SORT_STRING);
    return $out;
}

/* ── the setup: what loads, and what the site's icons ask for ─────────────────── */

/**
 * The icon setup this request draws with — one answer for the page head, the output filter, the
 * observer's map, the content preview and the panel:
 *
 *   library   'bootstrap' | 'fontawesome'
 *   source    'cdn6' | 'cdn7' | 'pack' — what is actually drawn: a package that is gone, broken, or
 *             was never named falls back to cdn6 (`fallback` says why), because a site with no icon
 *             font draws empty boxes everywhere
 *   major, edition ('free' | 'pro'), version, pack (the manifest or null), id
 *   styles    style key => description, every style that LOADS (a package's all.css ones and the ones
 *             ticked; for jsDelivr, solid and regular), brands included
 *   style     the style the site's own icons use (`fa_style`), coerced to solid when it does not load
 *   css       [['href' => …, 'integrity' => …|''], …], in load order
 */
function iconSetup(array $cfg): array {
    static $memo = [];
    $key = implode("\0", [iconLibrary($cfg), iconFaSource($cfg), (string)($cfg['fa_pack'] ?? ''),
                          (string)($cfg['fa_pack_styles'] ?? ''), (string)($cfg['fa_style'] ?? '')]);
    if (isset($memo[$key])) return $memo[$key];
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '/';
    $source = iconFaSource($cfg);
    $fallback = null;
    $m = null;
    if ($source === 'pack') {
        $id = (string)($cfg['fa_pack'] ?? '');
        $m = iconpackValidId($id) ? iconpackManifest($id) : null;
        if ($m === null) { $fallback = $id === '' ? 'pack_unset' : 'pack_missing'; $source = 'cdn6'; }
    }
    $s = ['library' => iconLibrary($cfg), 'source' => $source, 'fallback' => $fallback, 'pack' => null, 'id' => null];
    if ($source === 'pack') {
        $s += ['major' => (int)$m['major'], 'edition' => (string)$m['edition'], 'version' => (string)$m['version']];
        $s['pack'] = $m;
        $s['id'] = (string)$m['id'];
        $want = iconFaPackStyles($cfg);
        $styles = []; $css = [];
        $coreKind = (string)($m['core']['kind'] ?? 'all');
        $css[] = ['href' => iconpackAssetUrl($base, $m, (string)$m['core']['file']), 'integrity' => ''];
        foreach ($m['styles'] as $st) {
            $k = (string)$st['key'];
            // With all.css as the core its styles are there whatever is ticked; with fontawesome.css
            // (a full download) Solid, Regular and Brands are always linked (iconpackForcedStyles())
            // and the rest when ticked.
            $inCore = $coreKind === 'all' && !empty($st['in_all']);
            $forced = !$inCore && iconpackStyleFixed($m, $st);
            if (!$inCore && !$forced && !in_array($k, $want, true)) continue;
            if (!$inCore && $st['file'] === null) continue;
            $styles[$k] = $st;
            if (!$inCore) $css[] = ['href' => iconpackAssetUrl($base, $m, (string)$st['file']), 'integrity' => ''];
        }
        $s['styles'] = $styles;
        $s['css'] = $css;
    } else {
        $major = $source === 'cdn7' ? 7 : 6;
        $s += ['major' => $major, 'edition' => 'free', 'version' => $major === 7 ? '7.3.1' : '6.7.2'];
        $s['styles'] = iconCdnStyles($major);
        $s['css'] = [['href' => $major === 7 ? ICON_FONTAWESOME7_CSS : ICON_FONTAWESOME_CSS,
                      'integrity' => $major === 7 ? ICON_FONTAWESOME7_SRI : ICON_FONTAWESOME_SRI]];
    }
    $want = (string)($cfg['fa_style'] ?? 'solid');
    $s['style'] = (isset($s['styles'][$want]) && $want !== 'brands') ? $want : 'solid';
    return $memo[$key] = $s;
}

/** The setup 1.68 drew with Font Awesome chosen: Free 6.7.2, solid. */
function iconSetupDefault(): array {
    return iconSetup(['icon_library' => 'fontawesome', 'fa_source' => 'cdn6', 'fa_style' => 'solid']);
}

/** Does this setup's icon set declare the name? jsDelivr's Free builds carry every Free name. */
function iconSetupHas(array $setup, string $name): bool {
    if ($setup['source'] !== 'pack') {
        static $free = null;
        if ($free === null) {
            $free = [];
            foreach (iconFaEntries() as $e) { $free[$e[0]] = true; if (isset($e['v7'])) $free[$e['v7']] = true; }
        }
        return isset($free[$name]);
    }
    $m = $setup['pack'];
    $p = $m['present'] ?? null;
    if (is_array($p) && ($p['for'] ?? '') === hash('sha256', implode(',', iconFaCandidateNames()))) {
        static $sets = [];
        $sets[$m['id']] ??= array_flip((array)$p['names']);
        return isset($sets[$m['id']][$name]);
    }
    return isset(iconpackNamesOf((string)$m['id'])[$name]);
}

/**
 * Does a style of this setup have a glyph for the name? Free regular lacks most names (iconFreeRegular()).
 * A package whose metadata the importer could read (1.69.0, iconpackIndexBuild()) answers for every
 * style it has: Pro 7's Jelly, Slab, Notdog and the rest draw a few hundred icons each, and an icon the
 * chosen family lacks now falls back BY KNOWLEDGE to the next candidate style — the classic family at the
 * same weight — where it used to be left to the browser's font chain (which drew the same classic glyph,
 * but a two-layer family drew it twice, and nothing on the server knew). Without an index every style is
 * taken to have every name, as before.
 */
function iconStyleHas(array $setup, string $styleKey, string $name): bool {
    if ($styleKey === 'regular' && $setup['edition'] === 'free') return in_array($name, iconFreeRegular(), true);
    $k = $setup['pack'] !== null ? iconPackStyleKnowledge($setup['pack']) : null;
    if ($k === null || !isset($k['styles'][$name])) return true;
    return in_array($styleKey, (array)($k['sets'][(int)$k['styles'][$name]] ?? []), true);
}

/**
 * What a package knows about the styles of the map's names: the manifest's own answer, worked out at
 * import for exactly the names the map asks about (iconpackPresence()), or — once a later release asks
 * about other names — the package's index.json. Null when the package came without readable metadata.
 */
function iconPackStyleKnowledge(array $m): ?array {
    static $memo = [];
    $id = (string)($m['id'] ?? '');
    if (array_key_exists($id, $memo)) return $memo[$id];
    if (!iconpackIndexTrusted((string)($m['edition'] ?? ''), $m['metadata'] ?? null)) return $memo[$id] = null;
    $p = $m['present'] ?? null;
    if (is_array($p) && isset($p['sets'], $p['styles']) && ($p['for'] ?? '') === hash('sha256', implode(',', iconFaCandidateNames()))) {
        return $memo[$id] = ['sets' => (array)$p['sets'], 'styles' => (array)$p['styles']];
    }
    $ix = iconpackIndexOf($id);
    return $memo[$id] = $ix !== null ? ['sets' => (array)$ix['sets'], 'styles' => (array)$ix['styles']] : null;
}

/**
 * The style keys an entry's role asks for, best first, given the chosen style. The first that loads
 * and has the glyph (iconStyleHas()) draws it; solid, which always loads, is the last resort.
 *
 *   s → the chosen style, else the classic family at the same weight (what a partial family's font
 *       chain drew anyway: Jelly's missing gear is classic regular's gear), else solid.
 *   r → the chosen style when it is lighter than solid (it is an outline already), else classic at that
 *       weight, else classic regular; with a solid-weight style, the same family's regular, else classic
 *       regular.
 *   f → the chosen style when it is solid-weight, else the family's "fill" variant (7.x Jelly Fill,
 *       Utility Fill), else the family's solid, else classic solid.
 */
function iconRoleCandidates(string $role, array $chosen): array {
    $fam = (string)$chosen['family'];
    $w = (int)$chosen['weight'];
    $style = (string)$chosen['style'];
    $key = (string)$chosen['key'];
    $same = iconpackStyleKey('classic', $style);
    if ($role === 'r') $c = $w < 900 ? [$key, $same, 'regular'] : [iconpackStyleKey($fam, 'regular'), 'regular'];
    elseif ($role === 'f') $c = $w >= 900 ? [$key, 'solid'] : [iconpackStyleKey($fam . '-fill', $style), iconpackStyleKey($fam, 'solid'), 'solid'];
    else $c = [$key, $same, 'solid'];
    return array_values(array_unique($c));
}

/**
 * The map for one setup: Bootstrap name → the Font Awesome classes, style first ("fa-solid fa-gear",
 * "fa-sharp fa-light fa-gear"), plus — with $explain — why each entry is drawn the way it is.
 * With no setup it is 1.68's: Free 6.7.2 and solid, byte for byte.
 */
function iconFaMap(?array $setup = null, bool $explain = false): array {
    $setup ??= iconSetupDefault();
    $chosenKey = $setup['style'];
    $chosen = $setup['styles'][$chosenKey] ?? $setup['styles']['solid'];
    $pro = $setup['edition'] === 'pro';
    $v = (int)$setup['major'];
    $out = []; $why = [];
    foreach (iconFaEntries() as $bi => $e) {
        $free = ($v === 7 && isset($e['v7'])) ? $e['v7'] : $e[0];
        $name = $free; $role = $e[1]; $kind = !empty($e['approx']) ? 'approx' : 'exact'; $note = null;
        $twin = $pro ? ($e['pro' . $v] ?? $e['pro'] ?? null) : null;
        if ($twin !== null) {
            // A Pro choice that is closer and still not the very icon (1.69.0, `proapprox`) is drawn, and
            // stays counted as the approximation it is.
            if (iconSetupHas($setup, $twin)) { $name = $twin; $role = $e['prorole'] ?? $role; $kind = !empty($e['proapprox']) ? 'approx' : 'twin'; }
            else $note = ['why' => 'twin_missing', 'wanted' => $twin];
        } elseif ($pro && isset($e['prorole'])) {
            // No twin in this version, but Pro has the outline Free lacks (6's upright pushpin).
            $role = $e['prorole'];
        }
        if (!iconSetupHas($setup, $name)) $note = ['why' => 'name_missing', 'wanted' => $name];
        if ($role === 'b') { $cls = 'fa-brands'; $drawn = 'brands'; }
        else {
            $cands = iconRoleCandidates($role, $chosen);
            $drawn = null;
            foreach ($cands as $c) {
                if (isset($setup['styles'][$c]) && iconStyleHas($setup, $c, $name)) { $drawn = $c; break; }
            }
            $drawn ??= 'solid';
            // A fallback worth a line: an entry that follows the chosen style and is drawn in another
            // (Free regular has 40 of the map's names), or an outline / a filled half whose own
            // family's style is in the package but not ticked. An outline drawn in regular while solid
            // is chosen, or in classic regular because the family has no regular at all, is the design.
            if ($note === null && $drawn !== $cands[0]) {
                if ($role === 's') {
                    $note = ['why' => 'style_lacks', 'wanted' => $cands[0], 'drawn' => $drawn];
                } elseif (!isset($setup['styles'][$cands[0]]) && $setup['pack'] !== null
                          && in_array($cands[0], array_column($setup['pack']['styles'], 'key'), true)) {
                    $note = ['why' => 'style_not_loaded', 'wanted' => $cands[0], 'drawn' => $drawn];
                } elseif ($setup['pack'] !== null && isset($setup['styles'][$cands[0]])) {
                    // Loaded, and the package's index says that family has no such glyph (1.69.0).
                    $note = ['why' => 'style_lacks', 'wanted' => $cands[0], 'drawn' => $drawn];
                }
            }
            $cls = (string)($setup['styles'][$drawn]['classes'] ?? 'fa-solid');
        }
        $out[$bi] = $cls . ' fa-' . $name;
        if ($explain) $why[$bi] = ['name' => $name, 'free' => $free, 'kind' => $kind, 'role' => $role, 'style' => $drawn, 'note' => $note];
    }
    return $explain ? ['map' => $out, 'why' => $why] : $out;
}

/**
 * For the panel: how many of the map's entries this setup draws with their exact twin (Free's own or
 * a Pro twin), how many with an approximation, and every fallback — a Pro twin the package lacks, a
 * name it lacks, an entry drawn in another style than the one chosen — each with its reason.
 */
function iconMapCoverage(array $setup): array {
    $x = iconFaMap($setup, true);
    $c = ['total' => count($x['map']), 'exact' => 0, 'twins' => 0, 'approx' => 0, 'fallbacks' => []];
    foreach ($x['why'] as $bi => $w) {
        if ($w['kind'] === 'twin') { $c['twins']++; $c['exact']++; }
        elseif ($w['kind'] === 'exact') $c['exact']++;
        else $c['approx']++;
        // name: the icon drawn; style: the style it is drawn in; why / wanted: what it would have been.
        if ($w['note'] !== null) $c['fallbacks'][] = ['bi' => $bi, 'name' => $w['name'], 'style' => $w['style'], 'why' => $w['note']['why'], 'wanted' => $w['note']['wanted']];
    }
    return $c;
}

/** The styles the site's icons may be drawn in, for a setup: what loads, brands aside. */
function iconFaStyleChoices(array $setup): array {
    $out = [];
    foreach ($setup['styles'] as $k => $st) if ($k !== 'brands') $out[$k] = (string)$st['label'];
    return $out;
}

/**
 * The four Font Awesome settings, checked against what is installed — for the settings save and the
 * CLI alike. $data holds what is being saved, $cfg what is stored; each key is judged on the values the
 * save would leave behind.
 *
 *   fa_source       coerced to cdn6 when it is not one of the three words, like csp_mode;
 *   fa_pack         '' or an installed package whose manifest reads — REFUSED when the source is
 *                   'pack' and the package is not there (a real error: the site would draw nothing
 *                   from it), and cleared when it is not installed and not in use;
 *   fa_pack_styles  a JSON list of that package's style keys that have a file of their own and do not
 *                   load with its core anyway (in its all.css, or — with fontawesome.css as the core —
 *                   Solid, Regular and Brands, iconpackForcedStyles()) — anything else is dropped;
 *   fa_style        a style that loads with all of the above (brands never), else solid.
 *
 * Returns ['data' => the normalised keys, 'error' => null|lang key, 'vars' => []].
 */
function iconSettingsNormalise(array $data, array $cfg): array {
    $keys = ['fa_source', 'fa_pack', 'fa_pack_styles', 'fa_style'];
    if (!array_intersect($keys, array_keys($data))) return ['data' => $data, 'error' => null, 'vars' => []];
    $eff = $data + $cfg;
    $source = in_array((string)($eff['fa_source'] ?? 'cdn6'), ICON_FA_SOURCES, true) ? (string)$eff['fa_source'] : 'cdn6';
    $pack = (string)($eff['fa_pack'] ?? '');
    $m = ($pack !== '' && iconpackValidId($pack)) ? iconpackManifest($pack) : null;
    if ($source === 'pack' && $m === null) {
        // Refused only when THIS save asks for it. A package that went missing from the disk under a
        // stored choice must not make every other setting on the page unsavable: the site already
        // draws Free 6.7.2 in its place (iconSetup()) and the panel says so; the stored words stay.
        if ($source === (string)($cfg['fa_source'] ?? '') && $pack === (string)($cfg['fa_pack'] ?? '')) {
            return ['data' => $data, 'error' => null, 'vars' => []];
        }
        return ['data' => $data, 'error' => $pack === '' ? 'api.settings.fa_pack_none' : 'api.settings.fa_pack_missing', 'vars' => ['id' => $pack]];
    }
    if ($m === null) $pack = '';
    $styles = [];
    if ($m !== null) {
        $allowed = [];
        foreach ($m['styles'] as $st) {
            if (!iconpackStyleFixed($m, $st) && $st['file'] !== null) $allowed[] = (string)$st['key'];
        }
        $asked = iconFaPackStyles(['fa_pack_styles' => (string)($eff['fa_pack_styles'] ?? '[]')]);
        foreach ($allowed as $k) if (in_array($k, $asked, true)) $styles[] = $k;   // the package's order
    }
    $out = $data;
    $out['fa_source'] = $source;
    $out['fa_pack'] = $pack;
    $out['fa_pack_styles'] = json_encode($styles);
    $setup = iconSetup(['icon_library' => 'fontawesome', 'fa_source' => $source, 'fa_pack' => $pack,
                        'fa_pack_styles' => $out['fa_pack_styles'], 'fa_style' => (string)($eff['fa_style'] ?? 'solid')]);
    $out['fa_style'] = $setup['style'];
    return ['data' => $out, 'error' => null, 'vars' => []];
}

/* ── the page ─────────────────────────────────────────────────────────────────── */

/**
 * The icon font for a page's <head> — the ONE place such a <link> is printed (tests/icons_test.php
 * holds every template to that). With Bootstrap chosen it is exactly the tag the templates carried
 * until 1.68.0; with Free 6.7.2 exactly 1.68's three lines.
 *
 * install.php calls it with no settings at all, which is Bootstrap: the installer runs before there
 * is a database to hold a choice.
 */
function iconFontTag(array $cfg): string {
    if (iconLibrary($cfg) === 'bootstrap') {
        return '<link href="' . ICON_BOOTSTRAP_CSS . '" rel="stylesheet" integrity="' . ICON_BOOTSTRAP_SRI . '" crossorigin="anonymous">';
    }
    // Font Awesome: the stylesheets, the map, and the observer that applies the map to whatever scripts
    // build — in the <head>, so it is listening before the first script of the page runs. The server
    // already rewrote the page itself (iconFilterHtml()); this is for the rows, dialogs and toolbars
    // that arrive later.
    $setup = iconSetup($cfg);
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '/';
    $links = [];
    foreach ($setup['css'] as $c) {
        $links[] = '<link href="' . htmlspecialchars($c['href'], ENT_QUOTES, 'UTF-8') . '" rel="stylesheet"'
            . ($c['integrity'] !== '' ? ' integrity="' . $c['integrity'] . '" crossorigin="anonymous"' : '') . '>';
    }
    return implode("\n    ", $links)
        . "\n    " . '<script type="application/json" id="icon-map"' . nonceAttr() . '>' . iconFaMapJson($setup) . '</script>'
        . "\n    " . '<script src="' . $base . 'assets/js/icons.js' . assetVer('assets/js/icons.js') . '"></script>';
}

/**
 * The icon stylesheets alone, for a document the page builds itself: the page-content preview is an
 * iframe filled through srcdoc (assets/js/admin-pagecontent.js), and a description that draws a
 * YouTube mark or a task box needs the same font there as on the public page. A package has more than
 * one sheet, so `href` may hold several URLs separated by spaces (a URL has none); `integrity` is set
 * only for the one jsDelivr sheet.
 *
 * @return array{href:string, integrity:string}
 */
function iconFontCss(array $cfg): array {
    if (iconLibrary($cfg) !== 'fontawesome') return ['href' => ICON_BOOTSTRAP_CSS, 'integrity' => ICON_BOOTSTRAP_SRI];
    $css = iconSetup($cfg)['css'];
    if (count($css) === 1) return ['href' => $css[0]['href'], 'integrity' => $css[0]['integrity']];
    return ['href' => implode(' ', array_column($css, 'href')), 'integrity' => ''];
}

/**
 * Add the Font Awesome classes to every `class="bi bi-NAME…"` attribute in an HTML document.
 *
 * Only that shape is touched — an attribute that BEGINS with `bi bi-` — which is how every icon on the
 * site is written (tests/icons_test.php holds the templates to it). The `bi` and `bi-NAME` classes stay
 * and the mapped ones are appended, so every rule and selector that names `.bi` or `.bi-trash` keeps
 * working. Keyed on the class, never on the <i> element: an <i> is also the progress bar in the
 * Languages table (.lang-cov i) and italic text in a description.
 *
 * Idempotent: an attribute that already carries its Font Awesome classes is left as it is. A name the
 * map does not know is left alone too — it would draw nothing either way, and the test is what stops
 * that from shipping. $map is the setup's (iconFaMap()); without one it is the map the output filter
 * was started with, or 1.68's.
 */
function iconFilterHtml(string $html, ?array $map = null): string {
    if (!str_contains($html, 'class="bi bi-')) return $html;
    $map ??= iconActiveMap();
    $html = (string)preg_replace_callback('/class="bi bi-([a-z0-9]+(?:-[a-z0-9]+)*)((?: [^"<>]*)?)"/',
        static function (array $m) use ($map): string {
            $fa = $map[$m[1]] ?? null;
            if ($fa === null) return $m[0];
            $have = preg_split('/\s+/', trim($m[2])) ?: [];
            $add = array_values(array_diff(explode(' ', $fa), $have));
            return $add ? 'class="bi bi-' . $m[1] . $m[2] . ' ' . implode(' ', $add) . '"' : $m[0];
        }, $html);
    return iconMarkLabels($html, $map);
}

/**
 * `data-label` on every mapped icon that has words of its own beside it (1.69.0): text right before or
 * after its element, in the same parent. The stylesheets give an icon that is the only ELEMENT in a
 * button Font Awesome's fixed box, so an icon-only button is one size whatever glyph it shows — and
 * `:only-child` cannot see text, so it also took every icon that stood before bare text ("Fetch
 * metadata"), centring the glyph in a slot one em wide: a wide glyph's ink reached 0.125em into the
 * space before the word and a narrow one stood 0.19em back, so the space changed with the glyph. The
 * marked icon keeps the glyph's own width. assets/js/icons.js marks what scripts build the same way.
 *
 * Only the text next to the <i> in the page's own markup is read — up to the tag before it and the tag
 * after it — so an icon followed by a <span> label is not marked (it is not an only child either).
 * Idempotent: an icon already marked is left alone.
 */
function iconMarkLabels(string $html, array $map): string {
    return (string)preg_replace_callback('~<i class="bi bi-([a-z0-9]+(?:-[a-z0-9]+)*)[^"]*"([^>]*)></i>~',
        static function (array $m) use ($html, $map): string {
            [$tag, $at] = $m[0];
            if (!isset($map[$m[1][0]]) || str_contains($m[2][0], 'data-label')) return $tag;
            // The text between the tag before the icon and the icon, and between the icon and the next tag.
            $win = substr($html, max(0, $at - 400), $at - max(0, $at - 400));
            $gt = strrpos($win, '>');
            $before = $gt === false ? $win : substr($win, $gt + 1);
            $end = $at + strlen($tag);
            $lt = strpos($html, '<', $end);
            $after = substr($html, $end, ($lt === false ? strlen($html) : $lt) - $end);
            if (trim($before) === '' && trim($after) === '') return $tag;
            return substr($tag, 0, -5) . ' data-label></i>';          // before the start tag's ">"
        }, $html, -1, $count, PREG_OFFSET_CAPTURE);
}

/** The map the output filter applies: set once by iconOutputFilterStart(), 1.68's until then. */
function iconActiveMap(?array $set = null): array {
    static $active = null;
    if ($set !== null) $active = $set;
    return $active ??= iconFaMap();
}

/**
 * The output buffer's callback. HTML only: a page that declared another type (a download, a JSON
 * answer) passes through untouched. JSON from index.php leaves before the buffer starts anyway.
 */
function iconOutputFilter(string $buffer, int $phase = 0, ?array $headers = null): string {
    // $headers exists for the test, which has no response to declare a type on; ob_start() passes two.
    foreach ($headers ?? headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $buffer;
    }
    return iconFilterHtml($buffer);
}

/**
 * Start the filter for this response — with Font Awesome chosen, and ONLY then. With Bootstrap Icons
 * nothing is installed at all: no buffer, no callback, and the page is exactly the bytes the templates
 * wrote. Returns whether a filter was installed.
 */
function iconOutputFilterStart(array $cfg): bool {
    if (iconLibrary($cfg) !== 'fontawesome') return false;
    iconActiveMap(iconFaMap(iconSetup($cfg)));
    return ob_start('iconOutputFilter');
}

/**
 * The same map for the browser, as JSON: scripts build icons too (tables, dialogs, the room's rows),
 * and assets/js/icons.js adds the Font Awesome classes to those with this very data, so the page and
 * the scripts cannot disagree about what a name is drawn with. Safe inside a <script> element: every
 * character that could close one is escaped.
 */
function iconFaMapJson(?array $setup = null): string {
    return (string)json_encode(iconFaMap($setup), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
}

/* ── the package manager's words and writes (the panel, api/admin/iconpacks.php, and the CLI) ── */

/**
 * The sentence for one of includes/iconpack.php's codes, in the reader's language. The pipeline has no
 * dictionary of its own (the serving endpoint loads nothing but that file), so the words live here.
 */
function iconpackMessage(string $code, string $detail = ''): string {
    // PowerShell 5.1's Compress-Archive writes backslashes: the one refusal that needs its own advice.
    if ($code === 'unsafe_name' && str_starts_with($detail, 'backslash')) $code = 'backslash';
    $key = 'api.iconpack.err_' . $code;
    if (function_exists('langHas') && langHas($key)) return __($key, ['detail' => $detail]);
    return $code . ($detail !== '' ? ': ' . $detail : '');
}

/** An import's report as lines of text — what the CLI prints and what the panel's log reads like. */
function iconpackReportLines(array $r): array {
    $mb = fn(int $n) => $n >= 1048576 ? sprintf('%.1f MB', $n / 1048576) : sprintf('%.1f KB', $n / 1024);
    if (!$r['ok']) {
        $lines = [__('api.iconpack.refused') . ' ' . iconpackMessage((string)$r['error'], (string)$r['detail'])];
        if ($r['root'] !== null) $lines[] = '  ' . __('api.iconpack.found_in') . ' ' . ($r['root'] === '' ? '/' : $r['root']);
        return $lines;
    }
    $lines = [($r['already'] ? __('api.iconpack.already') : __('api.iconpack.installed')) . ' ' . $r['id']
              . ' — Font Awesome ' . ucfirst((string)$r['edition']) . ' ' . $r['version']
              . ', ' . __('api.iconpack.n_styles', ['n' => $r['styles']]) . ', ' . __('api.iconpack.n_files', ['n' => count($r['kept'])]) . ' (' . $mb((int)$r['bytes']) . ')'];
    $lines[] = '  ' . __('api.iconpack.found_in') . ' ' . ($r['root'] === '' ? '/' : $r['root']);
    if ($r['skipped']) {
        $parts = [];
        foreach ($r['skipped'] as $k => $n) $parts[] = $k . ' ' . $n;
        $lines[] = '  ' . __('api.iconpack.skipped') . ' ' . implode(', ', $parts);
    }
    foreach ($r['skipped_notes'] as $nt) $lines[] = '  ' . __('api.iconpack.note') . ' ' . $nt['path'] . ' — ' . iconpackMessage('note_' . $nt['why']);
    // What the package's own index says (1.69.0, iconpackIndexBuild()): the site-icon map's per-style
    // knowledge and the shoutbox's Font Awesome faces come from it.
    $md = $r['metadata'] ?? null;
    if (is_array($md) && !empty($md['index'])) {
        $lines[] = '  ' . __('api.iconpack.index_line', ['file' => (string)$md['index'], 'icons' => (int)$md['icons'],
                                                         'families' => (int)$md['families'], 'emoji' => (int)$md['emoji']])
                 . ' (' . (string)$md['describes'] . ')';
    }
    return $lines;
}

/**
 * Write the Font Awesome settings after iconSettingsNormalise() — the CLI's activate and styles and the
 * panel's Activate button. Returns ['error' => null|key, 'vars' => [], 'diff' => what changed].
 */
function iconSettingsApply(PDO $db, array $cfg, array $data): array {
    $n = iconSettingsNormalise($data, $cfg);
    if ($n['error'] !== null) return ['error' => $n['error'], 'vars' => $n['vars'], 'diff' => []];
    $keep = array_intersect_key($n['data'], array_flip(['fa_source', 'fa_pack', 'fa_pack_styles', 'fa_style']));
    $diff = function_exists('auditSettingsDiff') ? auditSettingsDiff($cfg, $keep) : [];
    setSettings($db, $keep);
    return ['error' => null, 'vars' => [], 'diff' => $diff];
}

/** The icons the panel's preview strip draws: the ones the site shows most, and every kind of pair. */
function iconPreviewNames(): array {
    return ['search', 'magnet', 'star', 'star-fill', 'heart', 'bell', 'envelope', 'person', 'people', 'gear',
            'trash', 'pencil', 'eye', 'eye-slash', 'lock', 'unlock', 'flag', 'flag-fill', 'pin-angle', 'pin-angle-fill',
            'patch-check', 'patch-check-fill', 'shield-check', 'check-circle', 'x-circle', 'exclamation-triangle',
            'info-circle', 'hand-thumbs-up', 'hand-thumbs-down-fill', 'download', 'upload', 'clipboard', 'link-45deg',
            'chat-left-text', 'calendar-event', 'youtube'];
}

/**
 * What a setup is, for the panel: where it comes from, the styles it loads and offers, the map's
 * coverage, the stylesheets, and — for a package — every style with whether it loads, is in all.css,
 * or can be ticked.
 */
function iconSetupSummary(array $setup): array {
    $packStyles = [];
    if ($setup['pack'] !== null) {
        foreach ($setup['pack']['styles'] as $st) {
            $fixed = iconpackStyleFixed($setup['pack'], $st);
            $packStyles[] = ['key' => $st['key'], 'label' => $st['label'], 'family' => $st['family'], 'weight' => (int)$st['weight'],
                             'file' => $st['file'], 'css_bytes' => (int)$st['css_bytes'], 'font_bytes' => (int)$st['font_bytes'],
                             'in_all' => !empty($st['in_all']), 'layers' => (int)$st['layers'], 'fixed' => $fixed,
                             'choosable' => !$fixed && $st['file'] !== null, 'loaded' => isset($setup['styles'][$st['key']])];
        }
    }
    $styles = [];
    foreach ($setup['styles'] as $k => $st) $styles[$k] = ['label' => (string)$st['label'], 'family' => (string)$st['family'], 'weight' => (int)$st['weight'],
                                                           'font_family' => (string)$st['font_family'], 'layers' => (int)($st['layers'] ?? 1)];
    return [
        'library' => $setup['library'], 'source' => $setup['source'], 'fallback' => $setup['fallback'], 'id' => $setup['id'],
        'major' => $setup['major'], 'edition' => $setup['edition'], 'version' => $setup['version'],
        'core' => $setup['pack'] !== null ? (string)($setup['pack']['core']['kind'] ?? 'all') : null,
        'style' => $setup['style'], 'styles' => $styles, 'choices' => iconFaStyleChoices($setup),
        'pack_styles' => $packStyles, 'css' => $setup['css'], 'coverage' => iconMapCoverage($setup),
    ];
}
