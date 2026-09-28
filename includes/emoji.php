<?php
/**
 * The emoji of the shoutbox picker (1.69.0): the ordinary ones, and Font Awesome's faces — and, from
 * 1.70.0, any icon of the owner's Font Awesome Pro package.
 *
 * ── the ordinary emoji are characters, and their data is a file ───────────────────────────────
 * Every emoji Unicode has, up to the version the reader's fonts draw (Emoji 16.0 — tools/emoji_data.php
 * says why), with its group, its skin tones and its CLDR name and keywords in English and Polish, is a
 * generated file under assets/emoji/. The picker asks for it the first time it opens and the browser
 * keeps it; no page carries it, and what goes into a shout is still the character itself.
 *
 * ── Font Awesome's icons are a token ────────────────────────────────────────────────────────────
 * With a Font Awesome PRO package as the site's icon source the operator may offer its emoji — the
 * `emoji` category of the package's own index: 113 faces in 7.3.1, 110 in 6.7.2 — instead of the
 * ordinary ones or beside them (`shout_emoji_fa`: off | fa | mixed), and (1.70.0, `shout_emoji_fa_scope`)
 * decide how much more of the package the picker offers: the faces only, the faces plus a search that
 * finds every icon, or every icon on the pages of Font Awesome's own categories. An icon cannot travel
 * as a character: it is a glyph of a licensed font. What the picker puts in the box is a TOKEN,
 *
 *     :fa-face-grin-tears:                 the icon, in the operator's default emoji style
 *     :fa-face-grin-tears/duotone-light:   the icon in one family and style (the style key is the name
 *                                          of the package's style file: solid, duotone, sharp-light,
 *                                          jelly-regular …), chosen by holding it down in the picker
 *
 * — never an emote code (`:code:` is [a-z0-9_], no hyphen) and never one of the `:shortcode:` emoji
 * (a fixed map, none of which starts with `fa-`; the `/` of the second form is outside their
 * characters too). The SERVER decides what it draws, on the text of finished HTML only (never inside
 * an attribute, a code block or a link's address): an <i> with the style's classes, role="img" and a
 * label in the reader's language when the package has the icon and the style is loaded. The scope is
 * how the picker OFFERS icons, not what a stored message may show: a token for any icon of the package
 * is drawn whatever the scope, and a token for a name the package does not have is the text it was
 * typed as. Where Font Awesome cannot draw it — the faces switched off, another icon source, the package
 * gone, the style no longer loaded, an e-mail — a face is the ordinary emoji it stands for
 * (assets/emoji/fa-faces.json) and any other icon its label in brackets, `[Rocket]`: never nothing.
 *
 * ── the picker in every editor, and the emotes outside the room (1.70.0) ─────────────────────────
 * The picker is every editor's now (assets/js/emoji-picker.js): the message composer, the torrent
 * description's editor — a first description and a proposed rewrite — the whitelist form, the profile's
 * description, and a list's (D's). Each asks for its data as its own context (EMOJI_PICKER_CONTEXTS,
 * emojiPickerGate(): the permission that writes THAT text, not the room's), and a template draws its
 * toolbar button with what the reader may use there (emojiPickerButton()). And the room's `:code:`
 * emotes and stickers are drawn where those texts are read (emoteRenderHtml(), fed by emoteMapFor() in
 * includes/shout.php): the same third token, on the same text-only walk, into the same image endpoint.
 */
require_once __DIR__ . '/icons.php';

/** What `shout_emoji_fa` may say; anything else reads as off. */
const EMOJI_FA_MODES = ['off', 'fa', 'mixed'];

/**
 * What `shout_emoji_fa_scope` may say (1.70.0) — how much of the package the picker offers besides the
 * faces: `faces` nothing more (1.69.0's picker), `search` any icon through the search box, `all` every
 * icon on the pages of Font Awesome's categories as well. Anything else reads as faces.
 */
const EMOJI_FA_SCOPES = ['faces', 'search', 'all'];

/**
 * The token, inside a line of text: `:fa-` NAME (Font Awesome's own grammar, at most ten parts — its
 * longest names have nine) and optionally `/` STYLE KEY, then `:`. Kept beside EMOJI_FA_MODES so the
 * picker's twin (assets/js/emoji-picker.js, FA_TOKEN — shoutbox.js until 1.70.0) is read against one line.
 */
const EMOJI_FA_TOKEN_RE = '/:fa-([a-z0-9]+(?:-[a-z0-9]+){0,9})(?:\/([a-z0-9]+(?:-[a-z0-9]+){0,4}))?:/';

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
 * Where the emoji of every language are, as {lang: address} — what a picker is told (the shoutbox
 * widget's data-emoji-files, every editor's button's since 1.70.0): every language that has a file,
 * since the language can change in place (assets/js/lang-swap.js) and the picker then takes the new one's.
 */
function emojiDataFiles(string $base): array {
    $out = [];
    foreach (glob(dirname(__DIR__) . '/assets/emoji/emoji-*.json') ?: [] as $ef) {
        if (preg_match('/emoji-([a-z]{2,3}(?:-[a-z0-9]{2,8})?)\.json$/', $ef, $em)) $out[$em[1]] = emojiDataUrl($base, $em[1]);
    }
    return $out;
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

/** The scope as stored (1.70.0), read the same way: anything but the three words is faces. */
function emojiFaScope(array $cfg): string {
    $v = (string)($cfg['shout_emoji_fa_scope'] ?? 'faces');
    return in_array($v, EMOJI_FA_SCOPES, true) ? $v : 'faces';
}

/** A face's name read as Font Awesome's label would put it: "face-grin-tears" → "Face grin tears". */
function emojiFaNameLabel(string $name): string {
    return ucfirst(str_replace('-', ' ', $name));
}

/**
 * Everything the token and the picker need about Font Awesome for this request — asked once:
 *
 *   available  the site draws with a Font Awesome Pro package (icon_library fontawesome, the package the
 *              active source): the only time the operator is offered the settings at all
 *   mode       what the picker shows: the setting, or 'off' whenever `available` is not
 *   scope      (1.70.0) how much of the package the picker offers: the setting when the package's index
 *              can be trusted (its catalogue is built from it), else 'faces'
 *   setup      iconSetup(), when available
 *   loaded     (1.70.0) the style keys that load, in the package's order — brands included, a brand
 *              icon's only style
 *   style      the default emoji style: `shout_emoji_fa_style` when it loads, else the site's fa_style
 *   classic    (1.70.0) the classic family at that style's weight: an icon's default when it lacks it
 *   faces      name => [label (English, the index's), terms (the index's), variants (loaded style keys
 *              that draw the face, the default first)] for every face the package has and can draw
 *   known      whether the variants come from the package's own index (else: the loaded full families)
 *   catalog    (1.70.0) whether the package has an index to build its catalogue from
 *   names      (1.70.0) the package whose catalogue says whether a token names an icon and what it is
 *              called: the one Settings names (fa_pack) while it is installed, Pro and indexed — the
 *              active one, or the one the site will draw with again once Font Awesome is back
 */
function emojiFaContext(array $cfg): array {
    static $memo = [];
    $key = implode("\0", [emojiFaSetting($cfg), emojiFaScope($cfg), (string)($cfg['shout_emoji_fa_style'] ?? ''), iconLibrary($cfg), iconFaSource($cfg),
                          (string)($cfg['fa_pack'] ?? ''), (string)($cfg['fa_pack_styles'] ?? ''), (string)($cfg['fa_style'] ?? '')]);
    if (isset($memo[$key])) return $memo[$key];
    $ctx = ['available' => false, 'mode' => 'off', 'scope' => 'faces', 'setup' => null, 'loaded' => [], 'style' => null, 'classic' => null,
            'faces' => [], 'known' => false, 'catalog' => false, 'id' => null, 'names' => emojiFaNamesPack($cfg)];
    if (iconLibrary($cfg) !== 'fontawesome') return $memo[$key] = $ctx;
    $setup = iconSetup($cfg);
    $m = $setup['pack'] ?? null;
    if ($setup['source'] !== 'pack' || !is_array($m) || $setup['edition'] !== 'pro') return $memo[$key] = $ctx;
    $ctx['available'] = true;
    $ctx['setup'] = $setup;
    $ctx['id'] = (string)$m['id'];
    $ctx['mode'] = emojiFaSetting($cfg);
    // The styles an icon may be drawn in: the ones that load, in the package's order. Brands is one of
    // them for a brand logo (1.70.0) and for nothing else: no face, and no other icon, is drawn in it.
    $loaded = array_values(array_map('strval', array_keys($setup['styles'])));
    $ctx['loaded'] = $loaded;
    $want = (string)($cfg['shout_emoji_fa_style'] ?? '');
    $ctx['style'] = ($want !== 'brands' && in_array($want, $loaded, true)) ? $want : (string)$setup['style'];
    $defStyle = (string)($setup['styles'][$ctx['style']]['style'] ?? 'solid');
    $ctx['classic'] = iconpackStyleKey('classic', $defStyle);

    $trusted = iconpackIndexTrusted((string)$m['edition'], $m['metadata'] ?? null);
    $ctx['catalog'] = $trusted && !empty($m['metadata']['index']);
    $ctx['scope'] = $ctx['catalog'] ? emojiFaScope($cfg) : 'faces';
    $idx = $trusted ? iconpackEmojiOf((string)$m['id']) : null;
    $mine = emojiFaFaces()['faces'];
    $faces = [];
    if ($idx !== null) {
        $ctx['known'] = true;
        foreach ($idx['faces'] as $n => $f) {
            if (!is_string($n) || !is_array($f)) continue;
            $has = (array)($idx['sets'][(int)($f[2] ?? -1)] ?? []);
            $faces[$n] = [(string)($f[0] ?? emojiFaNameLabel($n)), array_values(array_filter((array)($f[1] ?? []), 'is_string')),
                          emojiFaVariants($has, $ctx)];
        }
    } else {
        // No index this site can trust: the faces this project knows, where the package's CSS declares
        // them, in the families every Pro icon is drawn in (Pro's four full ones) among those loaded.
        $names = iconpackNamesOf((string)$m['id']);
        $full = array_values(array_filter($loaded, fn($k) => in_array((string)($setup['styles'][$k]['family'] ?? ''), EMOJI_FA_FULL_FAMILIES, true)));
        foreach ($mine as $n => $f) if (isset($names[$n])) $faces[$n] = [emojiFaNameLabel($n), [], emojiFaVariants($full, $ctx)];
    }
    // A face no loaded style draws is left out — its token falls back.
    $ctx['faces'] = array_filter($faces, fn($f) => (bool)$f[2]);
    return $memo[$key] = $ctx;
}

/**
 * The loaded styles an icon is drawn in, given the style keys it has (its families and styles), the
 * default first: the chosen style when the icon has it, else the classic family at that weight, else
 * whatever loaded style has it. Empty when no loaded style draws it. The picker computes the same list
 * from the same facts (assets/js/shoutbox.js, faVariants()), so what a click inserts is what is drawn.
 */
function emojiFaVariants(array $has, array $ctx): array {
    $v = array_values(array_intersect((array)$ctx['loaded'], $has));
    if (!$v) return [];
    $first = in_array($ctx['style'], $v, true) ? $ctx['style'] : (in_array($ctx['classic'], $v, true) ? $ctx['classic'] : $v[0]);
    return array_values(array_unique(array_merge([$first], $v)));
}

/**
 * The package whose catalogue names the icons (1.70.0): the one Settings names, while it is installed, a
 * Pro package, and indexed by metadata that knows Pro. Asked whether or not the site draws with it now:
 * a token keeps its label in brackets while the site draws Bootstrap Icons for a while.
 */
function emojiFaNamesPack(array $cfg): ?string {
    $id = (string)($cfg['fa_pack'] ?? '');
    if ($id === '' || !iconpackValidId($id)) return null;
    $m = iconpackManifest($id);
    if ($m === null || (string)$m['edition'] !== 'pro' || empty($m['metadata']['index']) || !iconpackIndexTrusted('pro', $m['metadata'] ?? null)) return null;
    return $id;
}

/**
 * A package's catalogue as the token needs it (1.70.0): name => [label, set index], and the sets. Read
 * once per request, and only when a token names something that is not a face.
 */
function emojiFaCatalogMap(?string $id): array {
    static $memo = [];
    if ($id === null) return ['icons' => [], 'sets' => []];
    if (isset($memo[$id])) return $memo[$id];
    $c = iconpackCatalogOf($id);
    $out = ['icons' => [], 'sets' => (array)($c['sets'] ?? [])];
    foreach ((array)($c['icons'] ?? []) as $r) {
        if (!is_array($r) || !isset($r[0], $r[4])) continue;
        $n = (string)$r[0];
        $out['icons'][$n] = [is_string($r[1]) && $r[1] !== '' ? $r[1] : iconpackCatalogLabel($n), (int)$r[4]];
    }
    return $memo[$id] = $out;
}

/**
 * An icon of the package that names the icons, which is not one of the faces (1.70.0): its label, and
 * the loaded styles it is drawn in (the default first) — none unless the site draws with that very
 * package. Null when the package has no icon of that name, or there is no such package.
 */
function emojiFaIconOf(string $name, array $ctx): ?array {
    $map = emojiFaCatalogMap($ctx['names'] ?? null);
    if (!isset($map['icons'][$name])) return null;
    [$label, $si] = $map['icons'][$name];
    $drawn = $ctx['available'] && $ctx['id'] === $ctx['names'];
    return ['l' => (string)$label, 'v' => $drawn ? emojiFaVariants((array)($map['sets'][$si] ?? []), $ctx) : []];
}

/** A face's label in the reader's language: this project's Polish, the index's English, else the name. */
function emojiFaLabel(string $name, string $lang, array $ctx): string {
    $mine = emojiFaFaces()['faces'][$name] ?? null;
    if ($lang === 'pl' && $mine !== null && $mine[2] !== '') return $mine[2];
    return isset($ctx['faces'][$name]) ? (string)$ctx['faces'][$name][0] : emojiFaNameLabel($name);
}

/** The classes of a loaded style, when they are the kind of classes a style has — else ''. */
function emojiFaClasses(array $ctx, string $style): string {
    $cls = (string)($ctx['setup']['styles'][$style]['classes'] ?? '');
    return ($cls !== '' && preg_match('/^fa-[a-z0-9-]+( fa-[a-z0-9-]+)*$/', $cls)) ? $cls : '';
}

/**
 * One token, as HTML. $name and $style have already matched EMOJI_FA_TOKEN_RE, so they are made of
 * lower-case letters, digits and hyphens; everything that goes into the <i> is read from the package's
 * manifest, not from the token. A face is drawn `fae` (an emoji's yellow), any other icon `fai` (the
 * colour of the words round it).
 */
function emojiFaTokenHtml(string $token, string $name, ?string $style, array $ctx, string $lang): string {
    $mine = emojiFaFaces()['faces'][$name] ?? null;
    if ($mine !== null || isset($ctx['faces'][$name])) {
        $face = $ctx['mode'] !== 'off' ? ($ctx['faces'][$name] ?? null) : null;
        if ($face !== null) {
            $use = ($style === null || $style === '') ? $face[2][0] : $style;
            $cls = in_array($use, $face[2], true) ? emojiFaClasses($ctx, $use) : '';
            if ($cls !== '') {
                $label = htmlspecialchars(emojiFaLabel($name, $lang, $ctx), ENT_QUOTES, 'UTF-8');
                return '<i class="fae ' . $cls . ' fa-' . $name . '" role="img" aria-label="' . $label . '" title="' . $label . '"></i>';
            }
        }
        // Font Awesome off, the package gone, the style not loaded, the face not in this package: the
        // ordinary emoji it stands for — or, for a face this project has no emoji for, its label.
        if ($mine !== null && $mine[1] !== '') return htmlspecialchars($mine[1], ENT_QUOTES, 'UTF-8');
        return htmlspecialchars('[' . emojiFaLabel($name, $lang, $ctx) . ']', ENT_QUOTES, 'UTF-8');
    }
    // Any other icon (1.70.0) — only a name the package has: its catalogue is the allow-list.
    $icon = emojiFaIconOf($name, $ctx);
    if ($icon === null) return $token;                                          // no such icon: the words typed
    if ($ctx['mode'] !== 'off' && $icon['v']) {
        $use = ($style === null || $style === '') ? $icon['v'][0] : $style;
        $cls = in_array($use, $icon['v'], true) ? emojiFaClasses($ctx, $use) : '';
        if ($cls !== '') {
            $label = htmlspecialchars($icon['l'], ENT_QUOTES, 'UTF-8');
            return '<i class="fai ' . $cls . ' fa-' . $name . '" role="img" aria-label="' . $label . '" title="' . $label . '"></i>';
        }
    }
    // Not drawn here: its name in brackets (Font Awesome has no ordinary emoji for a rocket or a gear).
    return htmlspecialchars('[' . $icon['l'] . ']', ENT_QUOTES, 'UTF-8');
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
 * What the picker is told about Font Awesome (api/shout_emoji.php): the mode and the scope, the default
 * style and its classic fallback, the styles with their classes, the pages, and every face — its label
 * and keywords in the reader's language with the English ones after them (the search's fallback), its
 * variants, and the ordinary emoji it falls back to. With a wider scope (1.70.0) also `catalog`: where
 * the catalogue of every icon is (its version), how many icons it holds, and its categories named in
 * the reader's language — the catalogue itself is fetched only when the picker needs it.
 */
function emojiFaClientData(array $cfg, string $lang): array {
    $ctx = emojiFaContext($cfg);
    $out = ['mode' => $ctx['mode'], 'scope' => $ctx['scope'], 'style' => $ctx['style'], 'classic' => $ctx['classic'], 'known' => $ctx['known'],
            'styles' => [], 'pages' => [], 'faces' => []];
    if ($ctx['mode'] === 'off' || !$ctx['faces']) { $out['mode'] = 'off'; return $out; }
    $data = emojiFaFaces();
    foreach ($ctx['setup']['styles'] as $k => $st) {
        // Brands only for the catalogue's brand logos: no face is drawn in it.
        if ($k === 'brands' && $ctx['scope'] === 'faces') continue;
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
    if ($ctx['scope'] !== 'faces') {
        $cat = emojiFaCatalogInfo($ctx);
        if ($cat === null) $out['scope'] = 'faces';        // no catalogue after all: the faces, as before
        else $out['catalog'] = ['v' => $cat['v'], 'n' => $cat['icons'],
                                'cats' => array_map(fn($id) => [$id, emojiFaCategoryLabel($id, $lang)], $cat['ids'])];
    }
    return $out;
}

/**
 * The active package's catalogue, described (1.70.0): its version (part of its URL), its icons, its
 * category ids. Built first when the package has none yet (iconpackCatalogOf()). Null: no catalogue.
 */
function emojiFaCatalogInfo(array $ctx): ?array {
    if (!$ctx['available'] || !$ctx['catalog'] || $ctx['id'] === null) return null;
    $m = $ctx['setup']['pack'];
    $sum = iconpackCatalogSummary($ctx['id']);
    if ($sum === null) {
        $c = iconpackCatalogOf($ctx['id']);
        if ($c === null) return null;
        $sum = iconpackCatalogSummary($ctx['id']) ?? ['icons' => count($c['icons']), 'categories' => count($c['cats']), 'ids' => $c['cats'], 'bytes' => 0];
    }
    return $sum + ['v' => iconpackCatalogVersion($m)];
}

/**
 * A Font Awesome category in the reader's language (1.70.0): this project's words for the ids of the
 * owner's indexes (lang/*.php, `emoji.facat.*`) — and for a category a later version adds, its id read
 * as words, so a new page is never nameless.
 */
function emojiFaCategoryLabel(string $id, string $lang): string {
    $key = 'emoji.facat.' . str_replace('-', '_', $id);
    $s = function_exists('langFor') ? langFor($lang, $key) : $key;
    return $s !== $key ? $s : ucfirst(str_replace('-', ' ', $id));
}

/**
 * A short fingerprint of what the picker would be told, for its URL (templates/partials/
 * shoutbox_widget.php): a new package, style, mode or scope is a new address, so the answer may be cached.
 */
function emojiFaVersion(array $cfg): string {
    $ctx = emojiFaContext($cfg);
    if ($ctx['mode'] === 'off') return 'off';
    $st = array_keys((array)($ctx['setup']['styles'] ?? []));
    return substr(hash('sha256', implode('|', [$ctx['mode'], $ctx['scope'], $ctx['style'], $ctx['id'], implode(',', $st), ICONPACK_CATALOG_FORMAT,
                                             (string)@filemtime(dirname(__DIR__) . '/assets/emoji/fa-faces.json')])), 0, 12);
}

/* ══ the emotes outside the room, and the picker in every editor (1.70.0) ══════════════════════════ */

/**
 * The emote's token inside a line of text — the twin of SHOUT_EMOTE_TOKEN_RE (includes/shout.php, whose
 * code rule it is: lower case, digits and `_`, two to thirty-two of them). Here as well because this file
 * draws it outside the room and must not need the room's file to do so.
 */
const EMOTE_TOKEN_RE = '/:([a-z0-9_]{2,32}):/';

/**
 * Where the picker can be opened, each the name of the text it writes — `shout` the room (as it always
 * was), `message` a private message, `description` a torrent's description and a proposed rewrite of
 * one, `bio` the profile's description, `list` a list's description. The `for` of api/shout_emoji.php and
 * api/shout_emotes.php, and of emojiPickerButton().
 */
const EMOJI_PICKER_CONTEXTS = ['shout', 'message', 'description', 'bio', 'list'];

/**
 * The `for` a request names: absent or empty is the room (what every request before 1.70.0 meant), one of
 * the contexts is itself, anything else is null — refused, never read as some other context.
 */
function emojiPickerFor($raw): ?string {
    if ($raw === null || $raw === '') return 'shout';
    return is_string($raw) && in_array($raw, EMOJI_PICKER_CONTEXTS, true) ? $raw : null;
}

/**
 * May the reader of THIS request use the picker's data in this context — null when they may, else
 * ['status' => int, 'error' => code] for the endpoint to answer with. The permission that writes the text
 * the context is about, asked the way the endpoint that saves it asks: the room's view (as before),
 * `pm.send`, `content.submit` or `content.propose` (a guest may hold them — the whitelist form is
 * public), `profile.bio`, `lists.use`. Each feature switched off is `disabled`; somebody signed out where
 * an account is needed is `login_required`.
 */
function emojiPickerGate(PDO $db, array $cfg, string $for): ?array {
    $on = static fn(string $fn): bool => function_exists($fn) && $fn($cfg);
    $me = $on('usersEnabled') && function_exists('currentUser') ? currentUser($db) : null;
    $can = static fn(string $p): bool => function_exists('userCan') && userCan($db, $cfg, $p);
    $who = $me ? ['status' => 403, 'error' => 'no_permission'] : ['status' => 401, 'error' => 'login_required'];
    $off = ['status' => 403, 'error' => 'disabled'];
    switch ($for) {
        case 'shout':
            if (!$on('shoutEnabled')) return $off;
            return function_exists('shoutMayView') && shoutMayView($db, $cfg) ? null : $who;
        case 'message':
            if (!$on('pmEnabled')) return $off;
            return $me && $can('pm.send') ? null : $who;
        case 'description':
            if (($cfg['wl_allow_description'] ?? '0') !== '1') return $off;
            return $can('content.submit') || $can('content.propose') ? null : $who;
        case 'bio':
            if (!$on('profileBioEnabled')) return $off;
            return $me && $can('profile.bio') ? null : $who;
        case 'list':
            if (!$on('listsEnabled')) return $off;
            return $me && $can('lists.use') ? null : $who;
    }
    return ['status' => 400, 'error' => 'bad_for'];
}

/**
 * What a picker in this context is told, for this reader — the server's whole answer to "what may I use
 * here", drawn onto the button (emojiPickerAttrs()): the emoji files; Font Awesome's mode and the
 * fingerprint of its answer; whether the emotes and the stickers are offered; whether the room's page of
 * codes exists for this reader. A reader the context's gate refuses gets the ordinary emoji alone — a
 * static file the picker needs nobody's permission for — and the picker then asks the server nothing.
 */
function emojiPickerData(PDO $db, array $cfg, string $base, string $for): array {
    $ok = emojiPickerGate($db, $cfg, $for) === null;
    $emotes = $ok && function_exists('emoteContextOn') && emoteContextOn($for, $cfg);
    $mode = $emotes ? emoteStickerMode($for, $cfg) : 'emote';
    $fa = $ok ? emojiFaContext($cfg)['mode'] : 'off';
    return ['for' => $for, 'files' => emojiDataFiles($base), 'fa' => $fa, 'fa_v' => $fa !== 'off' ? emojiFaVersion($cfg) : '',
            'emotes' => $emotes, 'stickers' => $emotes && $mode !== 'emote',
            'emotes_page' => $emotes && function_exists('shoutMayView') && shoutEmotesEnabled($cfg) && shoutMayView($db, $cfg)];
}

/** emojiPickerData() as the data-* attributes assets/js/emoji-picker.js's attach() reads, escaped. */
function emojiPickerAttrs(array $d): string {
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return ' data-emoji-for="' . $h($d['for'] ?? 'description') . '"'
         . ' data-emoji-files="' . $h((string)json_encode((array)($d['files'] ?? []), JSON_UNESCAPED_SLASHES)) . '"'
         . ' data-emoji-fa="' . $h($d['fa'] ?? 'off') . '" data-emoji-fa-v="' . $h($d['fa_v'] ?? '') . '"'
         . ' data-emotes="' . (!empty($d['emotes']) ? '1' : '0') . '" data-stickers="' . (!empty($d['stickers']) ? '1' : '0') . '"'
         . ' data-emotes-page="' . (!empty($d['emotes_page']) ? '1' : '0') . '"';
}

/**
 * The picker's button for a rich-text toolbar (`<id>-tools`): its own group at the end of the rail, the
 * site's smiling-face icon, a title that says what it offers here, and the data above. window.RichText
 * .mount() (assets/js/app.js) finds it as `<textarea id>-emoji` and attaches the picker to it.
 */
function emojiPickerButton(PDO $db, array $cfg, string $base, string $for, string $id): string {
    $d = emojiPickerData($db, $cfg, $base, $for);
    $title = htmlspecialchars(__($d['stickers'] ? 'rt.emoji_stickers' : ($d['emotes'] ? 'rt.emoji_emotes' : 'rt.emoji')), ENT_QUOTES, 'UTF-8');
    return '<span class="rt-tool-group rt-tool-emoji"><button type="button" class="rt-emoji-btn" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"'
         . ' title="' . $title . '" aria-label="' . $title . '" aria-haspopup="dialog" aria-expanded="false"' . emojiPickerAttrs($d) . '>'
         . '<i class="bi bi-emoji-smile" aria-hidden="true"></i></button></span>';
}

/**
 * An emote's address is the room's image endpoint and nothing else: whatever base the site is at, then
 * `api.php?endpoint=shout_emote&id=N&v=HEX` (shoutEmoteUrl()), with nothing in it that could leave an
 * attribute. A map that says otherwise draws its token as the text it is.
 */
function emoteSafeUrl(string $u): bool {
    return !preg_match('/[\s"\'<>`\\\\]/', $u) && (bool)preg_match('#(?:^|/)api\.php\?endpoint=shout_emote&id=[1-9][0-9]{0,9}&v=[0-9a-f]{0,40}$#', $u);
}

/**
 * One emote or sticker as an image, from a row of emoteMapFor(): `draw` says at which size — `emote`
 * inline with the words (.rt-emote), `sticker` at the room's sticker size (.rt-sticker), `small` a
 * sticker bounded for a page of text (.rt-sticker .rt-sticker-small). Every attribute escaped, the
 * address held to the image endpoint (emoteSafeUrl()); '' when the row cannot be drawn.
 */
function emoteImgHtml(array $e): string {
    $url = (string)($e['url'] ?? '');
    $code = (string)($e['code'] ?? '');
    if ($url === '' || !emoteSafeUrl($url) || !preg_match('/^[a-z0-9_]{2,32}$/', $code)) return '';
    $draw = (string)($e['draw'] ?? 'emote');
    $cls = $draw === 'sticker' ? 'rt-sticker' : ($draw === 'small' ? 'rt-sticker rt-sticker-small' : 'rt-emote');
    $w = (int)($e['w'] ?? 0);
    $h = (int)($e['h'] ?? 0);
    $out = '<img class="' . $cls . '" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
         . ' alt="' . htmlspecialchars(':' . $code . ':', ENT_QUOTES, 'UTF-8') . '"'
         . ' title="' . htmlspecialchars((string)($e['name'] ?? $code), ENT_QUOTES, 'UTF-8') . '"'
         . ' loading="lazy" decoding="async"';
    if ($w > 0 && $h > 0 && $w <= 4096 && $h <= 4096) $out .= ' width="' . $w . '" height="' . $h . '"';
    return $out . '>';
}

/**
 * Draw the `:code:` tokens in the TEXT of a finished fragment of HTML — the walk emojiFaRenderHtml() and
 * shoutRenderEmotes() take: split on tags, so an address, a class or any other attribute keeps its bytes
 * (a `:code:` inside an href is part of the address), and text inside <code>, <pre>, <kbd>, <textarea>,
 * <script> or <style> stays as typed. Only a code the map names becomes a picture — the map is the store's
 * approved, switched-on rows for this context (emoteMapFor()) — so every other `:word:` is left alone,
 * and the fixed `:shortcode:` emoji the renderer turned into characters long before this never reach it.
 * Cheap when there is no colon. Pure: the map is handed in.
 */
function emoteRenderHtml(string $html, array $map): string {
    if ($html === '' || !$map || !str_contains($html, ':')) return $html;
    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;
    $literal = 0;
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            if (preg_match('#^<(/?)(code|pre|kbd|textarea|script|style)\b#i', $part, $t)) $literal = max(0, $literal + ($t[1] === '/' ? -1 : 1));
            continue;
        }
        if ($literal > 0 || $part === '' || !str_contains($part, ':')) continue;
        $parts[$i] = preg_replace_callback(EMOTE_TOKEN_RE, static function (array $m) use ($map): string {
            $e = $map[$m[1]] ?? null;
            if (!is_array($e)) return $m[0];
            $img = emoteImgHtml($e);
            return $img !== '' ? $img : $m[0];
        }, $part) ?? $part;
    }
    return implode('', $parts);
}
