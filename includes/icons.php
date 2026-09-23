<?php
/**
 * The site's icons: which library draws them, and the one place a page asks for its font (1.68.0).
 *
 * Every icon on the site is written ONE way, in Bootstrap Icons' markup — `<i class="bi bi-NAME">` in
 * a template, `el('i', { className: 'bi bi-NAME' })` in a script — and `icon_library` (Settings →
 * Site) decides what draws it:
 *
 *   * 'bootstrap' (the default): Bootstrap Icons 1.11.3, the font the site has always used. Nothing
 *     below touches a byte of the page.
 *   * 'fontawesome': Font Awesome Free. The `bi` / `bi-NAME` classes STAY — every stylesheet rule,
 *     every querySelector and every test that names `.bi` or `.bi-trash` keeps working — and the
 *     matching Font Awesome classes are ADDED beside them, through one name map.
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

/** The two answers `icon_library` may hold; anything else reads as the first. */
const ICON_LIBRARIES = ['bootstrap', 'fontawesome'];

// Both stylesheets from jsDelivr, pinned to a version and carrying Subresource Integrity, as every
// third-party file on this site does. The policy already allows jsDelivr for style-src and font-src
// (includes/csp.php and the .htaccess fallback), so neither choice needs the policy to move.
//
// Font Awesome is the CSS WEBFONT build (css/all.min.css, whose fonts load from ../webfonts/ on the
// same host) and never the JS/SVG build: that one would need jsDelivr in the PUBLIC script-src, which
// is kept out on purpose — nothing on a page every anonymous visitor sees needs a CDN to run code.
// The integrity value is jsDelivr's own SHA-256 of the file, read from its package metadata
// (data.jsdelivr.com/v1/packages/npm/@fortawesome/fontawesome-free@6.7.2?structure=flat).
const ICON_BOOTSTRAP_CSS = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';
const ICON_BOOTSTRAP_SRI = 'sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+';
const ICON_FONTAWESOME_CSS = 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css';
const ICON_FONTAWESOME_SRI = 'sha256-dABdfBfUoC8vJUBOwGVdm8L9qlMWaHTIfXt+7GnZCIo=';

/**
 * Which library draws the icons. Only the exact word 'fontawesome' switches: a settings row can come
 * from a restored backup or a MySQL client, and a value nobody recognises must mean the library the
 * site has always had, not a half-mapped page.
 */
function iconLibrary(array $cfg): string {
    return (string)($cfg['icon_library'] ?? '') === 'fontawesome' ? 'fontawesome' : 'bootstrap';
}

/**
 * The icon font for a page's <head> — the ONE place such a <link> is printed (tests/icons_test.php
 * holds every template to that). With Bootstrap chosen it is exactly the tag the templates carried
 * until 1.68.0.
 *
 * install.php calls it with no settings at all, which is Bootstrap: the installer runs before there
 * is a database to hold a choice.
 */
function iconFontTag(array $cfg): string {
    if (iconLibrary($cfg) === 'bootstrap') {
        return '<link href="' . ICON_BOOTSTRAP_CSS . '" rel="stylesheet" integrity="' . ICON_BOOTSTRAP_SRI . '" crossorigin="anonymous">';
    }
    // Font Awesome: the stylesheet, the map, and the observer that applies the map to whatever scripts
    // build — in the <head>, so it is listening before the first script of the page runs. The server
    // already rewrote the page itself (iconFilterHtml()); this is for the rows, dialogs and toolbars
    // that arrive later.
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '/';
    return '<link href="' . ICON_FONTAWESOME_CSS . '" rel="stylesheet" integrity="' . ICON_FONTAWESOME_SRI . '" crossorigin="anonymous">'
        . "\n    " . '<script type="application/json" id="icon-map"' . nonceAttr() . '>' . iconFaMapJson() . '</script>'
        . "\n    " . '<script src="' . $base . 'assets/js/icons.js' . assetVer('assets/js/icons.js') . '"></script>';
}

/**
 * The icon stylesheet alone, for a document the page builds itself: the page-content preview is an
 * iframe filled through srcdoc (assets/js/admin-pagecontent.js), and a description that draws a
 * YouTube mark or a task box needs the same font there as on the public page.
 *
 * @return array{href:string, integrity:string}
 */
function iconFontCss(array $cfg): array {
    return iconLibrary($cfg) === 'fontawesome'
        ? ['href' => ICON_FONTAWESOME_CSS, 'integrity' => ICON_FONTAWESOME_SRI]
        : ['href' => ICON_BOOTSTRAP_CSS, 'integrity' => ICON_BOOTSTRAP_SRI];
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
 * that from shipping.
 */
function iconFilterHtml(string $html): string {
    if (!str_contains($html, 'class="bi bi-')) return $html;
    static $map = null;
    if ($map === null) $map = iconFaMap();
    return (string)preg_replace_callback('/class="bi bi-([a-z0-9]+(?:-[a-z0-9]+)*)((?: [^"<>]*)?)"/',
        static function (array $m) use ($map): string {
            $fa = $map[$m[1]] ?? null;
            if ($fa === null) return $m[0];
            $have = preg_split('/\s+/', trim($m[2])) ?: [];
            $add = array_values(array_diff(explode(' ', $fa), $have));
            return $add ? 'class="bi bi-' . $m[1] . $m[2] . ' ' . implode(' ', $add) . '"' : $m[0];
        }, $html);
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
    return ob_start('iconOutputFilter');
}

/**
 * Bootstrap Icons name -> the Font Awesome Free 6.7.2 classes that draw the same thing.
 *
 * Every `bi-*` name used anywhere on the site has an entry: tests/icons_test.php reads every template,
 * include, script and dictionary source and fails on a name that is missing, because a missing name is
 * an icon that silently draws nothing once Font Awesome is chosen. Free only — no Pro icon would load.
 * Most icons exist only in `fa-solid`; `fa-regular` is used where Free has an outline to match
 * Bootstrap's outline, and wherever a pair means something (a star or a flag, empty and filled) the
 * two halves map to the two styles. Brands live in `fa-brands`. Every entry was drawn on the live
 * page against the font's own "missing glyph" box before it went in.
 *
 * Where Free has no direct twin the entry says so (~) and names the closest honest glyph.
 */
function iconFaMap(): array {
    return [
        'activity'                   => 'fa-solid fa-heart-pulse',                 // ~ no bare pulse line in Free: the same line drawn through a heart
        'archive'                    => 'fa-solid fa-box-archive',
        'arrow-clockwise'            => 'fa-solid fa-arrow-rotate-right',
        'arrow-counterclockwise'     => 'fa-solid fa-arrow-rotate-left',
        'arrow-down'                 => 'fa-solid fa-arrow-down',
        'arrow-down-up'              => 'fa-solid fa-arrows-up-down',              // ~ one double-headed arrow for Bootstrap's pair of arrows
        'arrow-left-right'           => 'fa-solid fa-arrow-right-arrow-left',
        'arrow-repeat'               => 'fa-solid fa-arrows-rotate',
        'arrow-right'                => 'fa-solid fa-arrow-right',
        'arrow-up'                   => 'fa-solid fa-arrow-up',
        'arrow-up-circle'            => 'fa-regular fa-circle-up',
        'arrows-move'                => 'fa-solid fa-arrows-up-down-left-right',
        'award'                      => 'fa-solid fa-award',
        'balloon'                    => 'fa-solid fa-gift',                        // ~ no balloon in Free: the party tab's other object
        'bar-chart-steps'            => 'fa-solid fa-chart-gantt',
        'bell'                       => 'fa-regular fa-bell',
        'book'                       => 'fa-solid fa-book-open',
        'bootstrap-reboot'           => 'fa-solid fa-power-off',                   // ~ Bootstrap's own "reboot" mark: the power symbol says restart
        'box-arrow-in-down'          => 'fa-solid fa-file-import',
        'box-arrow-right'            => 'fa-solid fa-right-from-bracket',
        'box-arrow-up-right'         => 'fa-solid fa-arrow-up-right-from-square',
        'bug'                        => 'fa-solid fa-bug',
        'calendar-event'             => 'fa-solid fa-calendar-day',
        'calendar-range'             => 'fa-solid fa-calendar-days',
        'calendar-week'              => 'fa-solid fa-calendar-week',
        'card-text'                  => 'fa-regular fa-rectangle-list',
        'chat-left-dots'             => 'fa-regular fa-comment-dots',
        'chat-left-text'             => 'fa-regular fa-message',
        'check-circle'               => 'fa-regular fa-circle-check',
        'check-circle-fill'          => 'fa-solid fa-circle-check',
        'check-lg'                   => 'fa-solid fa-check',
        'check-square'               => 'fa-regular fa-square-check',
        'check2'                     => 'fa-solid fa-check',
        'check2-all'                 => 'fa-solid fa-check-double',
        'check2-circle'              => 'fa-regular fa-circle-check',
        'chevron-double-left'        => 'fa-solid fa-angles-left',
        'chevron-double-right'       => 'fa-solid fa-angles-right',
        'chevron-down'               => 'fa-solid fa-chevron-down',
        'chevron-left'               => 'fa-solid fa-chevron-left',
        'chevron-right'              => 'fa-solid fa-chevron-right',
        'chevron-up'                 => 'fa-solid fa-chevron-up',
        'circle'                     => 'fa-regular fa-circle',
        'circle-fill'                => 'fa-solid fa-circle',
        'clipboard'                  => 'fa-regular fa-clipboard',
        'clipboard-check'            => 'fa-solid fa-clipboard-check',
        'clock-history'              => 'fa-solid fa-clock-rotate-left',
        'cloud-download'             => 'fa-solid fa-cloud-arrow-down',
        'code-slash'                 => 'fa-solid fa-code',
        'collection'                 => 'fa-solid fa-layer-group',                 // ~ a stack of cards: a stack of layers
        'cpu'                        => 'fa-solid fa-microchip',
        'crosshair'                  => 'fa-solid fa-crosshairs',
        'dash-circle'                => 'fa-solid fa-circle-minus',
        'dash-lg'                    => 'fa-solid fa-minus',
        'database-down'              => 'fa-solid fa-database',                    // ~ no database-with-arrow in Free
        'database-gear'              => 'fa-solid fa-database',                    // ~ no database-with-gear in Free
        'diagram-3'                  => 'fa-solid fa-sitemap',
        'display'                    => 'fa-solid fa-display',
        'dot'                        => 'fa-solid fa-circle',                      // ~ no dot glyph in Free: the circle, drawn at a third of its size (style.css / admin.css)
        'download'                   => 'fa-solid fa-download',
        'emoji-smile'                => 'fa-regular fa-face-smile',
        'envelope'                   => 'fa-regular fa-envelope',
        'envelope-paper'             => 'fa-solid fa-envelope-open-text',          // ~ an envelope with its letter showing: the open one
        'eraser'                     => 'fa-solid fa-eraser',
        'exclamation-circle-fill'    => 'fa-solid fa-circle-exclamation',
        'exclamation-octagon'        => 'fa-solid fa-circle-exclamation',          // ~ no octagon in Free
        'exclamation-octagon-fill'   => 'fa-solid fa-circle-exclamation',          // ~ no octagon in Free
        'exclamation-triangle'       => 'fa-solid fa-triangle-exclamation',
        'exclamation-triangle-fill'  => 'fa-solid fa-triangle-exclamation',
        'eye'                        => 'fa-regular fa-eye',
        'eye-slash'                  => 'fa-regular fa-eye-slash',
        'file-earmark'               => 'fa-regular fa-file',
        'file-earmark-arrow-down'    => 'fa-solid fa-file-arrow-down',
        'file-earmark-arrow-up'      => 'fa-solid fa-file-arrow-up',
        'file-earmark-code'          => 'fa-regular fa-file-code',
        'file-earmark-image'         => 'fa-regular fa-file-image',
        'file-earmark-music'         => 'fa-regular fa-file-audio',
        'file-earmark-text'          => 'fa-regular fa-file-lines',
        'files'                      => 'fa-regular fa-copy',
        'flag'                       => 'fa-regular fa-flag',
        'flag-fill'                  => 'fa-solid fa-flag',
        'folder2'                    => 'fa-regular fa-folder',
        'folder2-open'               => 'fa-regular fa-folder-open',
        'fonts'                      => 'fa-solid fa-font',
        'gear'                       => 'fa-solid fa-gear',
        'globe2'                     => 'fa-solid fa-globe',
        'graph-up'                   => 'fa-solid fa-chart-line',
        'grid-1x2'                   => 'fa-solid fa-table-columns',               // ~ one tall tile beside two small ones: two columns
        'grip-vertical'              => 'fa-solid fa-grip-vertical',
        'hand-thumbs-down'           => 'fa-regular fa-thumbs-down',
        'hand-thumbs-up'             => 'fa-regular fa-thumbs-up',
        'hdd'                        => 'fa-regular fa-hard-drive',
        'hdd-network'                => 'fa-solid fa-network-wired',               // ~ a drive on a network: the network
        'hdd-stack'                  => 'fa-solid fa-server',                      // ~ stacked drives: a server
        'heart'                      => 'fa-regular fa-heart',
        'highlighter'                => 'fa-solid fa-highlighter',
        'hourglass-split'            => 'fa-regular fa-hourglass-half',
        'image'                      => 'fa-regular fa-image',
        'inbox'                      => 'fa-solid fa-inbox',
        'info-circle'                => 'fa-solid fa-circle-info',
        'info-circle-fill'           => 'fa-solid fa-circle-info',
        'journal-text'               => 'fa-solid fa-book',
        'key'                        => 'fa-solid fa-key',
        'link-45deg'                 => 'fa-solid fa-link',
        'list-check'                 => 'fa-solid fa-list-check',
        'list-ol'                    => 'fa-solid fa-list-ol',
        'list-ul'                    => 'fa-solid fa-list-ul',
        'lock'                       => 'fa-solid fa-lock',
        'lock-fill'                  => 'fa-solid fa-lock',
        'magic'                      => 'fa-solid fa-wand-magic-sparkles',
        'magnet'                     => 'fa-solid fa-magnet',
        'megaphone'                  => 'fa-solid fa-bullhorn',
        'palette'                    => 'fa-solid fa-palette',
        'patch-check'                => 'fa-solid fa-certificate',                 // ~ the badge, without Bootstrap's tick inside it
        'patch-check-fill'           => 'fa-solid fa-certificate',                 // ~ the badge, without Bootstrap's tick inside it
        'pencil'                     => 'fa-solid fa-pencil',
        'pencil-square'              => 'fa-regular fa-pen-to-square',
        'people'                     => 'fa-solid fa-user-group',
        'people-fill'                => 'fa-solid fa-users',
        'person'                     => 'fa-regular fa-user',
        'person-badge'               => 'fa-regular fa-id-badge',
        'person-gear'                => 'fa-solid fa-user-gear',
        'person-plus'                => 'fa-solid fa-user-plus',
        'person-square'              => 'fa-regular fa-circle-user',               // ~ a person in a square: in a circle
        'person-x'                   => 'fa-solid fa-user-xmark',
        'phone'                      => 'fa-solid fa-mobile-screen-button',
        'pin-angle'                  => 'fa-solid fa-thumbtack',                   // ~ Free has one pushpin, so pinned or not is said by the button's colour and title
        'pin-angle-fill'             => 'fa-solid fa-thumbtack',
        'play-circle'                => 'fa-regular fa-circle-play',
        'play-fill'                  => 'fa-solid fa-play',
        'plug'                       => 'fa-solid fa-plug',
        'plus-circle'                => 'fa-solid fa-circle-plus',
        'plus-lg'                    => 'fa-solid fa-plus',
        'plus-slash-minus'           => 'fa-solid fa-plus-minus',
        'quote'                      => 'fa-solid fa-quote-left',
        'reply'                      => 'fa-solid fa-reply',
        'scissors'                   => 'fa-solid fa-scissors',
        'search'                     => 'fa-solid fa-magnifying-glass',
        'send'                       => 'fa-regular fa-paper-plane',
        'send-check'                 => 'fa-solid fa-envelope-circle-check',       // ~ a sent letter with a tick
        'shield-check'               => 'fa-solid fa-shield-halved',               // ~ no shield with a tick in Free
        'shield-fill'                => 'fa-solid fa-shield',
        'shield-lock'                => 'fa-solid fa-shield-halved',               // ~ no shield with a lock in Free
        'shield-lock-fill'           => 'fa-solid fa-user-shield',                 // ~ it marks the protected owner: a person behind a shield
        'shield-plus'                => 'fa-solid fa-shield-halved',               // ~ no shield with a plus in Free
        'shield-slash'               => 'fa-solid fa-ban',                         // ~ no crossed-out shield in Free: it heads the block lists
        'shield-x'                   => 'fa-solid fa-ban',                         // ~ no shield with a cross in Free: everywhere it is used it means a ban
        'shuffle'                    => 'fa-solid fa-shuffle',
        'slash-circle'               => 'fa-solid fa-ban',
        'sliders'                    => 'fa-solid fa-sliders',
        'sliders2'                   => 'fa-solid fa-sliders',
        'speedometer2'               => 'fa-solid fa-gauge-high',
        'square'                     => 'fa-regular fa-square',
        'star'                       => 'fa-regular fa-star',
        'star-fill'                  => 'fa-solid fa-star',
        'stop-circle'                => 'fa-regular fa-circle-stop',
        'stopwatch'                  => 'fa-solid fa-stopwatch',
        'subscript'                  => 'fa-solid fa-subscript',
        'superscript'                => 'fa-solid fa-superscript',
        'table'                      => 'fa-solid fa-table',
        'text-center'                => 'fa-solid fa-align-center',
        'three-dots'                 => 'fa-solid fa-ellipsis',
        'translate'                  => 'fa-solid fa-language',
        'trash'                      => 'fa-regular fa-trash-can',
        'type-bold'                  => 'fa-solid fa-bold',
        'type-italic'                => 'fa-solid fa-italic',
        'type-strikethrough'         => 'fa-solid fa-strikethrough',
        'type-underline'             => 'fa-solid fa-underline',
        'ui-checks-grid'             => 'fa-solid fa-table-cells-large',           // ~ a grid of ticked boxes: the grid
        'unlock'                     => 'fa-solid fa-lock-open',
        'upload'                     => 'fa-solid fa-upload',
        'volume-mute'                => 'fa-solid fa-volume-xmark',
        'volume-up'                  => 'fa-solid fa-volume-high',
        'x'                          => 'fa-solid fa-xmark',
        'x-circle'                   => 'fa-regular fa-circle-xmark',
        'x-lg'                       => 'fa-solid fa-xmark',
        'x-octagon'                  => 'fa-solid fa-circle-xmark',                // ~ no octagon in Free
        'youtube'                    => 'fa-brands fa-youtube',
    ];
}

/**
 * The same map for the browser, as JSON: scripts build icons too (tables, dialogs, the room's rows),
 * and assets/js/icons.js adds the Font Awesome classes to those with this very data, so the page and
 * the scripts cannot disagree about what a name is drawn with. Safe inside a <script> element: every
 * character that could close one is escaped.
 */
function iconFaMapJson(): string {
    return (string)json_encode(iconFaMap(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
}
