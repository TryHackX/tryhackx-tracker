<?php
/**
 * The emoji of the shoutbox picker (1.69.0): the ordinary ones, and Font Awesome's faces.
 *
 * ── the ordinary emoji are characters, and their data is a file ───────────────────────────────
 * Every emoji Unicode has, up to the version the reader's fonts draw (Emoji 16.0 — tools/emoji_data.php
 * says why), with its group, its skin tones and its CLDR name and keywords in English and Polish, is a
 * generated file under assets/emoji/. The picker asks for it the first time it opens and the browser
 * keeps it; no page carries it, and what goes into a shout is still the character itself.
 *
 * ── Font Awesome's faces are a token ────────────────────────────────────────────────────────────
 * With a Font Awesome PRO package as the site's icon source the operator may offer its emoji — the
 * `emoji` category of the package's own index: 113 faces in 7.3.1, 110 in 6.7.2 — instead of the
 * ordinary ones or beside them (`shout_emoji_fa`: off | fa | mixed). A face cannot travel as a
 * character: it is a glyph of a licensed font. What the picker puts in the box is a TOKEN,
 *
 *     :fa-face-grin-tears:                 the face, in the operator's default emoji style
 *     :fa-face-grin-tears/duotone-light:   the face in one family and style (the style key is the name
 *                                          of the package's style file: solid, duotone, sharp-light,
 *                                          jelly-regular …), chosen by holding the face down
 *
 * — never an emote code (`:code:` is [a-z0-9_], no hyphen) and never one of the `:shortcode:` emoji
 * (a fixed map, none of which starts with `fa-`; the `/` of the second form is outside their
 * characters too). The SERVER decides what it draws, on the text of finished HTML only (never inside
 * an attribute, a code block or a link's address): an <i> with the style's classes, role="img" and a
 * label in the reader's language when the package has the face and the style is loaded; otherwise the
 * ordinary emoji that face stands for (assets/emoji/fa-faces.json) — with Font Awesome switched off,
 * the package gone, the style no longer loaded, in an e-mail — so a token never draws nothing. A
 * token for a name that is no face is the text it was typed as.
 */
require_once __DIR__ . '/icons.php';

/** What `shout_emoji_fa` may say; anything else reads as off. */
const EMOJI_FA_MODES = ['off', 'fa', 'mixed'];

/**
 * The token, inside a line of text: `:fa-` NAME (Font Awesome's own grammar, at most eight parts) and
 * optionally `/` STYLE KEY, then `:`. Kept beside EMOJI_FA_MODES so the picker's twin
 * (assets/js/shoutbox.js, FA_TOKEN) is read against one line.
 */
const EMOJI_FA_TOKEN_RE = '/:fa-([a-z0-9]+(?:-[a-z0-9]+){0,7})(?:\/([a-z0-9]+(?:-[a-z0-9]+){0,4}))?:/';

/** The families every Pro icon is drawn in — what a package without an index is assumed to have. */
const EMOJI_FA_FULL_FAMILIES = ['classic', 'duotone', 'sharp', 'sharp-duotone'];

/** The generated emoji file for a language — its own when there is one, English otherwise. */
function emojiDataFile(string $lang): string {
    $lang = preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $lang) ? $lang : 'en';
    return is_file(dirname(__DIR__) . '/assets/emoji/emoji-' . $lang . '.json') ? 'assets/emoji/emoji-' . $lang . '.json' : 'assets/emoji/emoji-en.json';
}

/** Its address, with the file's own version so a regenerated file is a new URL. */
function emojiDataUrl(string $base, string $lang): string {
    $rel = emojiDataFile($lang);
    return $base . $rel . (function_exists('assetVer') ? assetVer($rel) : '');
}

/**
 * The committed data about the faces (assets/emoji/fa-faces.json): each face's page in the picker, the
 * ordinary emoji it falls back to, and this project's Polish label, Polish and English keywords.
 */
function emojiFaFaces(): array {
    static $d = null;
    if ($d !== null) return $d;
    $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/assets/emoji/fa-faces.json'), true);
    $d = ['pages' => [], 'tabs' => [], 'faces' => []];
    if (!is_array($j)) return $d;
    $d['pages'] = array_values(array_filter((array)($j['pages'] ?? []), 'is_string'));
    $d['tabs'] = array_filter((array)($j['tabs'] ?? []), 'is_string');
    foreach ((array)($j['faces'] ?? []) as $n => $f) {
        if (is_string($n) && is_array($f) && count($f) >= 5) $d['faces'][$n] = array_map('strval', array_slice($f, 0, 5));
    }
    return $d;
}

/** The setting as stored, read the careful way: anything but the three words is off. */
function emojiFaSetting(array $cfg): string {
    $v = (string)($cfg['shout_emoji_fa'] ?? 'off');
    return in_array($v, EMOJI_FA_MODES, true) ? $v : 'off';
}

/** A face's name read as Font Awesome's label would put it: "face-grin-tears" → "Face grin tears". */
function emojiFaNameLabel(string $name): string {
    return ucfirst(str_replace('-', ' ', $name));
}

/**
 * Everything the token and the picker need about Font Awesome's faces for this request — asked once:
 *
 *   available  the site draws with a Font Awesome Pro package (icon_library fontawesome, the package the
 *              active source): the only time the operator is offered the setting at all
 *   mode       what the picker shows: the setting, or 'off' whenever `available` is not
 *   setup      iconSetup(), when available
 *   style      the default emoji style: `shout_emoji_fa_style` when it loads, else the site's fa_style
 *   faces      name => [label (English, the index's), terms (the index's), variants (loaded style keys
 *              that draw the face, the default first)] for every face the package has and can draw
 *   known      whether the variants come from the package's own index (else: the loaded full families)
 */
function emojiFaContext(array $cfg): array {
    static $memo = [];
    $key = implode("\0", [emojiFaSetting($cfg), (string)($cfg['shout_emoji_fa_style'] ?? ''), iconLibrary($cfg), iconFaSource($cfg),
                          (string)($cfg['fa_pack'] ?? ''), (string)($cfg['fa_pack_styles'] ?? ''), (string)($cfg['fa_style'] ?? '')]);
    if (isset($memo[$key])) return $memo[$key];
    $ctx = ['available' => false, 'mode' => 'off', 'setup' => null, 'style' => null, 'faces' => [], 'known' => false, 'id' => null];
    if (iconLibrary($cfg) !== 'fontawesome') return $memo[$key] = $ctx;
    $setup = iconSetup($cfg);
    $m = $setup['pack'] ?? null;
    if ($setup['source'] !== 'pack' || !is_array($m) || $setup['edition'] !== 'pro') return $memo[$key] = $ctx;
    $ctx['available'] = true;
    $ctx['setup'] = $setup;
    $ctx['id'] = (string)$m['id'];
    $ctx['mode'] = emojiFaSetting($cfg);
    // The styles a face may be drawn in: the ones that load, brands aside, in the package's order.
    $loaded = array_values(array_filter(array_keys($setup['styles']), fn($k) => $k !== 'brands'));
    $want = (string)($cfg['shout_emoji_fa_style'] ?? '');
    $ctx['style'] = in_array($want, $loaded, true) ? $want : (string)$setup['style'];

    $idx = iconpackIndexTrusted((string)$m['edition'], $m['metadata'] ?? null) ? iconpackEmojiOf((string)$m['id']) : null;
    $mine = emojiFaFaces()['faces'];
    $faces = [];
    if ($idx !== null) {
        $ctx['known'] = true;
        foreach ($idx['faces'] as $n => $f) {
            if (!is_string($n) || !is_array($f)) continue;
            $has = (array)($idx['sets'][(int)($f[2] ?? -1)] ?? []);
            $faces[$n] = [(string)($f[0] ?? emojiFaNameLabel($n)), array_values(array_filter((array)($f[1] ?? []), 'is_string')),
                          array_values(array_intersect($loaded, $has))];
        }
    } else {
        // No index this site can trust: the faces this project knows, where the package's CSS declares
        // them, in the families every Pro icon is drawn in (Pro's four full ones) among those loaded.
        $names = iconpackNamesOf((string)$m['id']);
        $full = array_values(array_filter($loaded, fn($k) => in_array((string)($setup['styles'][$k]['family'] ?? ''), EMOJI_FA_FULL_FAMILIES, true)));
        foreach ($mine as $n => $f) if (isset($names[$n])) $faces[$n] = [emojiFaNameLabel($n), [], $full];
    }
    // The default first: the chosen style when the face has it, else the classic family at that weight,
    // else whatever loaded style has it. A face no loaded style draws is left out — its token falls back.
    $defStyle = (string)($setup['styles'][$ctx['style']]['style'] ?? 'solid');
    foreach ($faces as $n => $f) {
        $v = $f[2];
        if (!$v) { unset($faces[$n]); continue; }
        $first = in_array($ctx['style'], $v, true) ? $ctx['style'] : (in_array(iconpackStyleKey('classic', $defStyle), $v, true) ? iconpackStyleKey('classic', $defStyle) : $v[0]);
        $faces[$n][2] = array_values(array_unique(array_merge([$first], $v)));
    }
    $ctx['faces'] = $faces;
    return $memo[$key] = $ctx;
}

/** A face's label in the reader's language: this project's Polish, the index's English, else the name. */
function emojiFaLabel(string $name, string $lang, array $ctx): string {
    $mine = emojiFaFaces()['faces'][$name] ?? null;
    if ($lang === 'pl' && $mine !== null && $mine[2] !== '') return $mine[2];
    return isset($ctx['faces'][$name]) ? (string)$ctx['faces'][$name][0] : emojiFaNameLabel($name);
}

/**
 * One token, as HTML. $name and $style have already matched EMOJI_FA_TOKEN_RE, so they are made of
 * lower-case letters, digits and hyphens; everything that goes into the <i> is read from the package's
 * manifest, not from the token.
 */
function emojiFaTokenHtml(string $token, string $name, ?string $style, array $ctx, string $lang): string {
    $mine = emojiFaFaces()['faces'][$name] ?? null;
    $face = $ctx['mode'] !== 'off' ? ($ctx['faces'][$name] ?? null) : null;
    if ($mine === null && !isset($ctx['faces'][$name])) return $token;           // not a face: the words typed
    if ($face !== null) {
        $use = ($style === null || $style === '') ? $face[2][0] : $style;
        if (in_array($use, $face[2], true)) {
            $cls = (string)($ctx['setup']['styles'][$use]['classes'] ?? '');
            if ($cls !== '' && preg_match('/^fa-[a-z0-9-]+( fa-[a-z0-9-]+)*$/', $cls)) {
                $label = htmlspecialchars(emojiFaLabel($name, $lang, $ctx), ENT_QUOTES, 'UTF-8');
                return '<i class="fae ' . $cls . ' fa-' . $name . '" role="img" aria-label="' . $label . '" title="' . $label . '"></i>';
            }
        }
    }
    // Font Awesome off, the package gone, the style not loaded, the face not in this package: the ordinary
    // emoji it stands for — or, for a face this project has no emoji for, its label. Never nothing.
    if ($mine !== null && $mine[1] !== '') return htmlspecialchars($mine[1], ENT_QUOTES, 'UTF-8');
    return htmlspecialchars('[' . emojiFaLabel($name, $lang, $ctx) . ']', ENT_QUOTES, 'UTF-8');
}

/**
 * Draw the tokens in the TEXT of an HTML fragment — split on tags, the way shoutRenderEmotes() and
 * shoutLinkMentions() walk a line — so an address or any attribute keeps its bytes. Text inside
 * <code>, <pre>, <kbd>, <textarea>, <script> or <style> stays as typed. Cheap when there is no token.
 */
function emojiFaRenderHtml(string $html, array $cfg, ?string $lang = null): string {
    if ($html === '' || !str_contains($html, ':fa-')) return $html;
    $ctx = emojiFaContext($cfg);
    $lang ??= function_exists('langCurrent') ? langCurrent() : 'en';
    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;
    $literal = 0;
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            if (preg_match('#^<(/?)(code|pre|kbd|textarea|script|style)\b#i', $part, $t)) $literal = max(0, $literal + ($t[1] === '/' ? -1 : 1));
            continue;
        }
        if ($literal > 0 || !str_contains($part, ':fa-')) continue;
        $parts[$i] = preg_replace_callback(EMOJI_FA_TOKEN_RE,
            fn($m) => emojiFaTokenHtml($m[0], $m[1], isset($m[2]) && $m[2] !== '' ? $m[2] : null, $ctx, $lang), $part) ?? $part;
    }
    return implode('', $parts);
}

/**
 * What the picker is told about Font Awesome's faces (api/shout_emoji.php): the mode, the default style,
 * the styles with their classes, the pages, and every face — its label and keywords in the reader's
 * language with the English ones after them (the search's fallback), its variants, and the ordinary
 * emoji it falls back to.
 */
function emojiFaClientData(array $cfg, string $lang): array {
    $ctx = emojiFaContext($cfg);
    $out = ['mode' => $ctx['mode'], 'style' => $ctx['style'], 'known' => $ctx['known'], 'styles' => [], 'pages' => [], 'faces' => []];
    if ($ctx['mode'] === 'off' || !$ctx['faces']) { $out['mode'] = 'off'; return $out; }
    $data = emojiFaFaces();
    foreach ($ctx['setup']['styles'] as $k => $st) {
        if ($k === 'brands') continue;
        $out['styles'][$k] = ['label' => (string)$st['label'], 'classes' => (string)$st['classes'], 'layers' => (int)($st['layers'] ?? 1)];
    }
    $pageOf = [];
    foreach ($ctx['faces'] as $n => $f) {
        $mine = $data['faces'][$n] ?? null;
        $page = ($mine !== null && in_array($mine[0], $data['pages'], true)) ? $mine[0] : ($data['pages'][count($data['pages']) - 1] ?? 'fa-more');
        $pageOf[$page] = true;
        $en = array_merge([(string)$f[0]], $f[1], $mine !== null ? explode('|', $mine[4]) : []);
        $kw = $lang === 'pl' && $mine !== null ? array_merge([$mine[2]], explode('|', $mine[3]), $en) : $en;
        $kw = array_values(array_unique(array_filter(array_map(fn($w) => trim(mb_strtolower((string)$w, 'UTF-8')), $kw), fn($w) => $w !== '')));
        $out['faces'][] = ['n' => $n, 'p' => $page, 'l' => emojiFaLabel($n, $lang, $ctx), 'k' => implode('|', $kw),
                           'v' => $f[2], 'e' => $mine[1] ?? ''];
    }
    // The committed order of the faces (Unicode's own order of the faces they stand for), then the rest.
    $order = array_flip(array_keys($data['faces']));
    usort($out['faces'], fn($a, $b) => ($order[$a['n']] ?? PHP_INT_MAX) <=> ($order[$b['n']] ?? PHP_INT_MAX) ?: strcmp($a['n'], $b['n']));
    foreach ($data['pages'] as $p) {
        if (!isset($pageOf[$p])) continue;
        $tab = (string)($data['tabs'][$p] ?? '');
        $out['pages'][] = ['id' => $p, 'tab' => isset($ctx['faces'][$tab]) ? $tab : (string)array_key_first(array_filter($out['faces'], fn($x) => $x['p'] === $p))];
    }
    return $out;
}

/**
 * A short fingerprint of what the picker would be told, for its URL (templates/partials/
 * shoutbox_widget.php): a new package, style or mode is a new address, so the answer may be cached.
 */
function emojiFaVersion(array $cfg): string {
    $ctx = emojiFaContext($cfg);
    if ($ctx['mode'] === 'off') return 'off';
    $st = array_keys((array)($ctx['setup']['styles'] ?? []));
    return substr(hash('sha256', implode('|', [$ctx['mode'], $ctx['style'], $ctx['id'], implode(',', $st), (string)@filemtime(dirname(__DIR__) . '/assets/emoji/fa-faces.json')])), 0, 12);
}
