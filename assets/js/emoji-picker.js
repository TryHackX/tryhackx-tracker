/**
 * The emoji picker — the shoutbox's, and from 1.70.0 every editor's: window.EmojiPicker.
 *
 * Written for the shoutbox (1.59.0, rebuilt in 1.69.0, Font Awesome's scopes in 1.70.0) and moved here
 * out of assets/js/shoutbox.js in 1.70.0, when the owner asked for the emoji, the emotes and the stickers
 * in private messages, in torrent descriptions and the proposals that rewrite them, and in the profile's
 * description as well. One picker, one set of pages, one search, one Recent: a second copy would be a
 * second answer to "what does the picker offer", and the two would drift apart the first time either
 * changed. The room uses it exactly as before — same markup, same ids, same requests — and every other
 * editor mounts the same thing on the button of its own toolbar.
 *
 * ── two ways in ────────────────────────────────────────────────────────────────────────────────
 *   EmojiPicker.mount(opts)   the picker itself on any button — the room's call, and the general one:
 *     { button, host?, insert(text) → bool, sticker(code)?, emotes, stickers, emotesPage?, files, fa,
 *       faVer, for?, id? } — see mountPicker() below. Returns the handle the checks drive (state(),
 *       search(), show(), …) with close(), say(text) and destroy().
 *   EmojiPicker.attach({ textarea, button, data? })   what an editor calls: the picker for one textarea,
 *     its settings read from the button's data-* (emojiPickerButton() / emojiPickerAttrs() in
 *     includes/emoji.php draw them, the server's answer to what THIS reader may use HERE), whatever it
 *     picks inserted at the caret. window.RichText.mount() (assets/js/app.js) calls it for a
 *     `<id>-emoji` button in the editor's toolbar, so the whitelist form, the Info panel's description
 *     editor and the message composer need nothing more; the profile's description editor
 *     (assets/js/profile-bio.js) calls it for the button it builds itself; a new editor does either.
 *   EmojiPicker.isToken(text)   whether a text is a token (`:code:`, `:fa-NAME:`) — the spacing rule.
 *   EmojiPicker.forgetEmotes()  the emote lists asked for again the next time a picker opens (after an
 *     upload, so the next opening shows the new emote).
 *
 * ── what goes in is TEXT ─────────────────────────────────────────────────────────────────────────
 * The character itself, `:code:` for an emote or a sticker, `:fa-NAME:` for a Font Awesome icon: what is
 * written travels as text and the pictures are the server's rendering of it (shoutRenderEmotes() in the
 * room, emoteRenderHtml() everywhere else, includes/emoji.php). In the room a sticker is not inserted —
 * it is SENT, on its own, the moment it is picked (opts.sticker); in an editor it goes in as its code
 * like an emote, and the page that shows the text draws it at that page's sticker size.
 *
 * ── where it opens ───────────────────────────────────────────────────────────────────────────────
 * In the room the panel is absolute inside the text field's wrapper, as it always was (place()). An
 * editor's box clips what is inside it (`.rt-editor` has overflow: hidden for its rounded corners, the
 * Info panel's body scrolls), so there the panel FLOATS: position: fixed, put against the button in the
 * window's own coordinates, inside the dialog the button is in (so a modal stays one piece) or on the
 * page, placed again as the page or the dialog scrolls, and closed when its button leaves the screen.
 *
 * ── what it asks the server, by context ─────────────────────────────────────────────────────────
 * The emoji themselves are a static file per language (assets/emoji/). Font Awesome's faces and the
 * emote list come from api/shout_emoji.php and api/shout_emotes.php with `for` = the context — message,
 * description, bio, list — each gated by its own permission (emojiPickerGate()); the room asks without
 * `for`, as it always has, and is gated as it always was.
 *
 * And none of it before a picker OPENS. A page carries editors nobody may use — the whitelist form, a
 * list's Edit window, the Info panel's editor drawn for a torrent and taken away again — and until
 * 1.70.0 G each one asked for its context's emote list the moment it was mounted. Now the list of a
 * context is asked for once per page, by whichever of its pickers opens first, and every other picker
 * of that context takes the same answer (emotesLoad()); one that failed is asked for again at the next
 * opening.
 */
(function () {
    'use strict';

    var API = (typeof APP_API === 'string') ? APP_API : 'api.php?endpoint=';
    var BASE = (typeof APP_BASE === 'string') ? APP_BASE : '';

    /* ─────────────────────────── the small shared tools ─────────────────────────── */

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'className') n.className = v;
            else if (k === 'text') n.textContent = v;
            else if (k === 'html') n.innerHTML = v;          // server-rendered, already sanitized
            else if (k === 'dataset') Object.keys(v).forEach(function (d) { n.dataset[d] = v[d]; });
            else n.setAttribute(k, v === true ? '' : v);
        });
        (Array.isArray(kids) ? kids : kids ? [kids] : []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
        });
        return n;
    }

    async function get(qs) {
        try {
            var r = await fetch(API + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return await r.json();
        } catch (e) { return null; }
    }

    /* ─────────────────────────── the emoji, and the images beside them ─────────────────────────── */

    /**
     * Unicode's pages (1.69.0). The emoji themselves are DATA, not code: every one Unicode has up to what
     * the readers' fonts draw (Emoji 16.0), with its CLDR name and keywords in the reader's language and
     * its skin tones, in a generated file per language (assets/emoji/emoji-<lang>.json, tools/
     * emoji_data.php) that the picker asks for the first time it opens and the browser keeps. Until
     * 1.68 it was 170 characters written into this file. The characters are CONTENT — what goes into the
     * box — and each page's TAB is a control: an icon that follows the site's icon library (1.68.0).
     * The ids are the data file's `groups`, in Unicode's order.
     */
    var GROUPS = [
        { id: 'smileys',    icon: 'bi bi-emoji-smile' },
        { id: 'people',     icon: 'bi bi-person' },
        { id: 'animals',    icon: 'bi bi-tree' },
        { id: 'food',       icon: 'bi bi-cup-hot' },
        { id: 'travel',     icon: 'bi bi-car-front' },
        { id: 'activities', icon: 'bi bi-trophy' },
        { id: 'objects',    icon: 'bi bi-lightbulb' },
        { id: 'symbols',    icon: 'bi bi-heart' },
        { id: 'flags',      icon: 'bi bi-flag' },
    ];
    /**
     * What this browser remembers (per browser, not per account): the emoji used last, the skin tone, and
     * (1.70.0) the Font Awesome category looked at last. An entry of Recent is at most RECENT_LEN long —
     * the longest token is `:fa-` + a 46-letter name + `/` + a 22-letter style + `:`.
     */
    var RECENT_KEY = 'thx_emoji_recent', TONE_KEY = 'thx_emoji_tone', FACAT_KEY = 'thx_emoji_facat', RECENT_MAX = 36, RECENT_LEN = 96;
    /** Held this long, an emoji with variants opens them — about what a phone waits. */
    var HOLD_MS = 450;
    /**
     * The grid is drawn a page at a time, and a long page in pieces: the first fills the view at once. A
     * search shows up to SEARCH_MAX emoji, faces and emotes, and (1.70.0) up to FA_SEARCH_MAX more of Font
     * Awesome's other icons in a group of their own after them.
     */
    var FIRST_CELLS = 120, MORE_CELLS = 200, SEARCH_MAX = 300, FA_SEARCH_MAX = 300;
    /** The most icons one category's chip shows (1.71.0: the alphabet as A B C) — EMOJI_FA_CAT_RUN_MAX's twin. */
    var CAT_RUN_MAX = 3;
    /** Between a cell's name and how to reach its variants, in its title. */
    var DASH = ' ' + String.fromCharCode(0x2014) + ' ';
    /**
     * A Font Awesome icon written as a token — the twin of EMOJI_FA_TOKEN_RE in includes/emoji.php, held
     * to the whole string: `:fa-NAME:` (the operator's default style) or `:fa-NAME/STYLE:`. A face (1.69.0)
     * or, from 1.70.0, any other icon of the package; up to ten parts, Font Awesome's longest having nine.
     */
    var FA_TOKEN = /^:fa-([a-z0-9]+(?:-[a-z0-9]+){0,9})(?:\/([a-z0-9]+(?:-[a-z0-9]+){0,4}))?:$/;

    function storeGet(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
    function storeSet(key, v) { try { window.localStorage.setItem(key, v); } catch (e) { /* a private window: forgotten, harmlessly */ } }

    /**
     * Words folded the way a person types them: lower case, accents off (ą → a, and ł, which has no
     * accent to take off, → l), anything that is not a letter or a digit a space. The classes are built
     * from code points and \p escapes because a literal combining mark in this file is an invisible one.
     */
    var MARKS = (function () { try { return new RegExp('\\p{M}+', 'gu'); } catch (e) { return new RegExp('[' + String.fromCharCode(0x300) + '-' + String.fromCharCode(0x36f) + ']+', 'g'); } })();
    var NON_WORD = (function () { try { return new RegExp('[^\\p{L}\\p{N}]+', 'gu'); } catch (e) { return /[^a-z0-9]+/g; } })();
    var L_STROKE = new RegExp('[' + String.fromCharCode(0x141, 0x142) + ']', 'g');
    function fold(s) {
        s = String(s || '').toLowerCase();
        try { s = s.normalize('NFD'); } catch (e) { /* an engine without normalize() keeps its accents */ }
        return s.replace(MARKS, '').replace(L_STROKE, 'l').replace(NON_WORD, ' ').trim();
    }

    /** One JSON file or answer, asked for once per page; a failure is forgotten so the next open asks again. */
    var jsonCache = {};
    function fetchJson(url) {
        if (!jsonCache[url]) {
            jsonCache[url] = fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; })
                .then(function (j) { if (!j) delete jsonCache[url]; return j; });
        }
        return jsonCache[url];
    }

    /**
     * The emoji file, ready for the grid and the search: rows {c, n, k, t, g, i} (the character, its
     * name, its keywords, its five tones or null, its group, its row), the rows of each group, and every
     * character and tone found back by its text (Recent keeps the exact variant somebody chose).
     */
    function prepUnicode(j) {
        if (!j || !Array.isArray(j.e) || !Array.isArray(j.groups)) return null;
        var out = { rows: [], groups: {}, byChar: {} };
        j.groups.forEach(function (g) { out.groups[g] = []; });
        j.e.forEach(function (r, i) {
            if (!Array.isArray(r) || typeof r[1] !== 'string') return;
            var row = { c: r[1], n: String(r[2] || ''), k: String(r[3] || ''), t: Array.isArray(r[4]) && r[4].length === 5 ? r[4] : null,
                        g: j.groups[Number(r[0]) || 0], i: i };
            out.rows.push(row);
            if (out.groups[row.g]) out.groups[row.g].push(row);
            out.byChar[row.c] = { row: row, tone: 0 };
            if (row.t) row.t.forEach(function (v, k) { out.byChar[v] = { row: row, tone: k + 1 }; });
        });
        return out.rows.length ? out : null;
    }

    /**
     * Font Awesome as api/shout_emoji.php describes it, or null when the site offers none: the faces, the
     * styles that load (in the package's order, with their classes), the default style and its classic
     * fallback, and (1.70.0) the scope — `faces`, `search` or `all` — with, for the two wider ones, where
     * the catalogue of every icon is and its categories named in the reader's language; (1.71.0) with the
     * icons each category's chip shows (catalog.icons) and the glyph of one without (catalog.fallback).
     */
    function prepFa(j) {
        if (!j || !j.success || j.mode === 'off' || !Array.isArray(j.faces) || !j.faces.length || !j.styles) return null;
        var cg = j.catalog && typeof j.catalog.v === 'string' && Array.isArray(j.catalog.cats) ? j.catalog : null;
        var out = { styles: j.styles, pages: Array.isArray(j.pages) ? j.pages : [], faces: [], byName: {},
                    style: String(j.style || ''), classic: String(j.classic || ''),
                    scope: cg && (j.scope === 'search' || j.scope === 'all') ? j.scope : 'faces',
                    catalog: cg ? { v: cg.v, n: Number(cg.n) || 0, names: {}, icons: {}, fallback: typeof cg.fallback === 'string' ? cg.fallback : '' } : null };
        if (cg) cg.cats.forEach(function (c) {
            if (!Array.isArray(c) || typeof c[0] !== 'string') return;
            out.catalog.names[c[0]] = String(c[1] || c[0]);
            out.catalog.icons[c[0]] = Array.isArray(c[2]) ? c[2].filter(function (n) { return typeof n === 'string' && n !== ''; }).slice(0, CAT_RUN_MAX) : [];
        });
        j.faces.forEach(function (f) {
            if (!f || typeof f.n !== 'string' || !Array.isArray(f.v) || !f.v.length) return;
            var face = { n: f.n, p: String(f.p || ''), l: String(f.l || f.n), k: String(f.k || ''), v: f.v.filter(function (s) { return !!j.styles[s]; }), e: String(f.e || '') };
            if (!face.v.length) return;
            out.faces.push(face);
            out.byName[face.n] = face;
        });
        return out.faces.length ? out : null;
    }

    /**
     * The loaded styles an icon is drawn in, given the style keys it has — the default first: the chosen
     * style when the icon has it, else the classic family at that weight, else the first loaded style
     * that has it. The twin of emojiFaVariants() in includes/emoji.php: what a click inserts as
     * `:fa-NAME:` is exactly what the server then draws.
     */
    function faVariants(fa, has) {
        var v = Object.keys(fa.styles).filter(function (k) { return has.indexOf(k) >= 0; });
        if (!v.length) return v;
        var first = v.indexOf(fa.style) >= 0 ? fa.style : (v.indexOf(fa.classic) >= 0 ? fa.classic : v[0]);
        return [first].concat(v.filter(function (k) { return k !== first; }));
    }

    /** An icon's name read as its label, as the catalogue leaves it to be read: "arrow-up" → "Arrow Up". */
    function faLabel(name) {
        return String(name).split('-').map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join(' ');
    }

    /**
     * The catalogue of every icon (1.70.0; catalog.json, which includes/iconpack.php's
     * iconpackCatalogBuild() describes) made ready for the pages and the search: each icon {n, l, w, c, v,
     * icon} — name, label, English words, category indexes, variants — and the icons of each category.
     * An icon no loaded style draws is left out: the page would show an empty cell for it.
     */
    function prepCatalog(j, fa) {
        if (!j || !Array.isArray(j.icons) || !Array.isArray(j.sets) || !Array.isArray(j.cats)) return null;
        var setVars = j.sets.map(function (s) { return faVariants(fa, Array.isArray(s) ? s : []); });
        var out = { v: fa.catalog ? fa.catalog.v : '', icons: [], byName: {}, cats: j.cats.map(String), byCat: [] };
        out.cats.forEach(function () { out.byCat.push([]); });
        j.icons.forEach(function (r) {
            if (!Array.isArray(r) || typeof r[0] !== 'string') return;
            var v = setVars[Number(r[4])] || [];
            if (!v.length) return;
            var icon = { n: r[0], l: typeof r[1] === 'string' && r[1] !== '' ? r[1] : faLabel(r[0]), w: String(r[2] || ''),
                         c: Array.isArray(r[3]) ? r[3] : [], v: v, icon: true };
            out.icons.push(icon);
            out.byName[icon.n] = icon;
            icon.c.forEach(function (ci) { if (out.byCat[ci]) out.byCat[ci].push(icon); });
        });
        return out.icons.length ? out : null;
    }

    /**
     * An icon's glyph: Font Awesome's classes for the style, and nothing of the site's `bi` machinery. A
     * face is `fae` (an emoji's yellow), any other icon (1.70.0) `fai`, in the colour of the text.
     */
    function faGlyph(fa, name, style, icon) {
        var st = fa.styles[style];
        return el('i', { className: (icon ? 'fai ' : 'fae ') + (st ? st.classes : 'fa-solid') + ' fa-' + name, 'aria-hidden': 'true' });
    }

    var emotesPromises = {};
    /**
     * The uploaded images, asked for ONCE per page and context and shared by every picker of that context
     * (and, in the room, by the emotes page, which forgets them after an upload: EmojiPicker.forgetEmotes()).
     * The room asks `shout_emotes` as it always has; another context says which it is (1.70.0), because what
     * it may offer is its own: no stickers in a profile's description, nothing at all where emotes are
     * kept to the room (`emotes_everywhere` off).
     *
     * Asked for by a picker as it OPENS (emotesWant() in mountPicker(), 1.70.0 G), never as it is mounted:
     * the first picker of a context to open asks, and every other one of that context — another editor on
     * the page, the Info panel's editor drawn again for the next torrent — is handed the same promise.
     *
     * The answer is the rows, or null when none came (no answer, or not a list): a picker that fails shows
     * the emoji without the images rather than an error — the emoji above do not depend on the server, and
     * a picker that refuses to open because one request did not come back is a picker that punishes the
     * reader for the operator's bad afternoon — and the failure is forgotten, so the next opening asks again.
     */
    function emotesLoad(ctx) {
        ctx = ctx || 'shout';
        if (emotesPromises[ctx]) return emotesPromises[ctx];
        var p = emotesPromises[ctx] = get('shout_emotes' + (ctx === 'shout' ? '' : '&for=' + encodeURIComponent(ctx))).then(function (j) {
            var rows = j ? (j.emotes || j.rows || j.list) : null;
            if (!Array.isArray(rows)) {
                if (emotesPromises[ctx] === p) delete emotesPromises[ctx];
                return null;
            }
            return rows.filter(function (r) { return r && r.code && r.url; });
        });
        return p;
    }

    /** One image in a grid, at the size the grid wants rather than the size it was uploaded at. */
    function emoteImg(row, cls) {
        return el('img', { className: cls, src: row.url, alt: ':' + row.code + ':',
                           title: row.name || row.code, loading: 'lazy' });
    }

    /**
     * The picker (rebuilt in 1.69.0): a popover over the composer with a search box, the page of what this
     * browser used last, Unicode's nine pages — every emoji up to what the readers' fonts draw — and, when
     * the operator offers them, Font Awesome's faces on four pages of their own (instead of the ordinary
     * pages or after them), then the emotes and the stickers as before.
     *
     * What it puts in the box is always TEXT — the character itself, `:code:` for an emote, `:fa-NAME:`
     * for a Font Awesome face — because the shout that travels is text and the pictures are the server's
     * rendering of it. Somebody who types a token by hand gets exactly what this button gives them.
     *
     * Held down (touch or mouse, HOLD_MS), right-clicked, or given Shift+Enter or the context-menu key, an
     * emoji with variants opens them in a small bubble over it, the way a phone does: the five skin tones
     * (the one chosen last is remembered in this browser and drawn in the grid from then on), or the
     * styles a Font Awesome face is drawn in among those the site loads. A plain click inserts at once.
     * The corner mark says which cells have variants.
     *
     * Nothing is fetched until the picker first opens: the emoji file of the reader's language (the
     * browser keeps it), the faces when they are on, and (1.70.0 G) the emotes too, which until then came
     * as the picker was mounted — see emotesWant(). The grid is drawn one page at a time and a long page
     * in slices, so opening it on a phone never builds two thousand buttons.
     *
     * ── Font Awesome beyond the faces (1.70.0) ──
     * The operator's `shout_emoji_fa_scope` decides how much of the package the picker offers, and the
     * faces' answer says which (fa.scope): `faces`, the four pages above and nothing more; `search`, the
     * same pages, and the search also finds any icon of the package — a "Font Awesome" group after the
     * emoji; `all`, one more tab, "every icon", whose page is navigated by Font Awesome's own categories
     * (seventy with the brands, too many for a row of tabs) on a strip of chips above the grid — from
     * 1.71.0 each chip the category's own icon, its name in the tooltip (catGlyphs()) — each category
     * drawn in slices like any long page. Every icon has variants: held down, it offers the
     * styles it is drawn in among those the site loads, the default first. All of it comes from the
     * package's CATALOGUE — every icon's name, label, English words, categories and styles, one file per
     * package (catalog.json) — which is fetched only when the scope needs it: with `all` once the picker
     * has opened and drawn its first page (so it opens as fast as before), with `search` the first time
     * somebody searches; at an address carrying the package's hash, so the browser keeps it. Everything
     * that touches it is between prepCatalog() above and "the catalogue" below: catalogLoad(), the chips
     * (drawCats(), chooseCat()), the page (catalogItems()) and the search's group (faSearch()).
     *
     * opts: { button, host?, insert(text) → bool, sticker(code)?, emotes:bool, stickers:bool, emotesPage?,
     *         files: {lang: url}, fa: 'off'|'fa'|'mixed', faVer, for?, id? }
     *
     * ── the same picker for every editor (1.70.0) ──
     *   · `for` — shout (the room, the default) | message | description | bio | list: the context the data is
     *     asked for (api/shout_emoji.php and api/shout_emotes.php answer by it). The room's requests are the
     *     ones it has always made, without `for`.
     *   · `id` — the panel's id and the prefix of its parts' ids (`<id>-search`, `-grid`, `-cats`, …):
     *     'shout-picker' for the room as before, `<textarea id>-picker` for an editor, so two pickers on
     *     one page never share an id.
     *   · `host` — the room's: the panel is absolute inside it. Without one the panel FLOATS (see place()).
     *   · `sticker` — the room's: a function that sends the sticker at once. Without one a sticker goes in as
     *     its code like an emote (not into Recent, which is the room's emoji row too).
     *   · `emotesPage` — the link to ?action=emotes under the grid (the room: whenever it has emotes;
     *     elsewhere only for a reader that page exists for).
     */
    function mountPicker(opts) {
        var btn = opts.button;
        if (!btn) return null;
        var CONTEXTS = ['shout', 'message', 'description', 'bio', 'list'];
        var ctx = CONTEXTS.indexOf(opts.for) >= 0 ? opts.for : 'shout';
        var forQ = ctx === 'shout' ? '' : '&for=' + ctx;
        var pid = /^[a-z][a-z0-9-]{0,48}$/.test(String(opts.id || '')) ? String(opts.id) : 'shout-picker';
        // No host: the panel floats — position: fixed, against the button in the window's own coordinates —
        // inside the dialog the button is in (so a modal keeps its picker, and its Esc is the picker's first)
        // or on the page. An editor's box clips what is inside it (`.rt-editor` rounds its corners with
        // overflow: hidden; the Info panel's body scrolls), and a panel inside it would open cut in half.
        var floating = !opts.host;
        var host = opts.host || (btn.closest && btn.closest('[role="dialog"]')) || document.body;
        var files = opts.files || {};
        var faMode = opts.fa === 'fa' || opts.fa === 'mixed' ? opts.fa : 'off';

        var panel = el('div', { className: 'shout-picker' + (floating ? ' shout-picker-float' : ''), id: pid, hidden: true, role: 'dialog' });
        // Where it opens is worked out from the BUTTON each time it opens (1.62.0) — see place().
        // The stylesheet only says it is absolute inside the field's wrapper (fixed, when it floats).
        var searchIn = el('input', { type: 'search', className: 'shout-picker-search', id: pid + '-search',
                                     autocomplete: 'off', spellcheck: 'false', enterkeyhint: 'done', 'aria-controls': pid + '-grid' });
        var clearBtn = el('button', { type: 'button', className: 'shout-picker-clear', id: pid + '-clear', hidden: true },
                          [el('i', { className: 'bi bi-x-lg', 'aria-hidden': 'true' })]);
        var head = el('div', { className: 'shout-picker-head' },
                      [el('i', { className: 'bi bi-search shout-picker-search-ic', 'aria-hidden': 'true' }), searchIn, clearBtn]);
        var tabsEl = el('div', { className: 'shout-picker-tabs', role: 'tablist' });
        // Font Awesome's categories (1.70.0): a strip of chips over the grid, shown on the "every icon" page.
        var catsEl = el('div', { className: 'shout-picker-cats', id: pid + '-cats', role: 'toolbar', hidden: true });
        var gridEl = el('div', { className: 'shout-picker-grid', id: pid + '-grid', role: 'group' });
        var statusEl = el('div', { className: 'shout-picker-status', id: pid + '-status', 'aria-live': 'polite' });
        var varEl = el('div', { className: 'shout-picker-var', id: pid + '-var', role: 'listbox', hidden: true });
        [head, tabsEl, catsEl, gridEl, statusEl, varEl].forEach(function (n) { panel.appendChild(n); });
        // The way to the page that lists the codes — and only where there is such a page: with
        // emotes switched off ?action=emotes answers "no such thing", and a link into that is worse
        // than no link. (1.70.0: nor for a reader the room's page is closed to — the editors say.)
        var allLink = null;
        if (opts.emotesPage !== undefined ? !!opts.emotesPage && !!opts.emotes : !!opts.emotes) {
            allLink = el('a', { className: 'shout-picker-all', href: BASE + '?action=emotes' });
            panel.appendChild(el('div', { className: 'shout-picker-foot' }, [allLink]));
        }
        host.appendChild(panel);

        /** The words the panel says itself, from the dictionary — again after a live language switch. */
        function relabel() {
            panel.setAttribute('aria-label', t('js.shout.emoji'));
            // With a wider scope the search finds Font Awesome's icons too, and the box says so.
            var sq = fa && fa.scope !== 'faces' ? t('js.shout.search_icons') : t('js.shout.search');
            searchIn.setAttribute('placeholder', sq);
            searchIn.setAttribute('aria-label', sq);
            catsEl.setAttribute('aria-label', t('js.shout.fa_cats'));
            clearBtn.title = t('js.shout.search_clear');
            clearBtn.setAttribute('aria-label', t('js.shout.search_clear'));
            if (allLink) allLink.textContent = t('js.shout.all_emotes');
            pages.forEach(function (p) { p.label = p.labelOf(); });
            Array.prototype.forEach.call(tabsEl.children, function (b) {
                var p = pageById(b.dataset.g);
                if (p) { b.title = p.label; b.setAttribute('aria-label', p.label); }
            });
        }

        /* ── what it knows ── */
        var lang = '', uni = null, fa = null, en = null, enAsked = false, loading = null;
        // The emotes and the stickers of this context once they came (emotesIn), the request they come from
        // (asked for as the picker opens, emotesWant()) while it is on its way (emotesWait), what waits for
        // them to be put in (emotesDone, for ready()), and whether Recent kept an emote's place for them.
        var emotePlain = [], emoteStick = [], emotesIn = false, emotesFrom = null, emotesWait = false, emotesDone = null, recentWaitsEm = false;
        var pages = [];                     // {id, icon|faTab|img, label, labelOf(), fill() → items, wide?, note?}
        var current = '', lastPage = '';
        var items = [];                     // what the grid holds now, one per cell (data-k)
        var job = 0;                        // the drawing of a long page, in slices; a newer page stops it
        // 1.70.0 — the catalogue of every icon once it came (prepCatalog()), the promise of it while it is
        // on its way, the category shown on the "every icon" page, and whether Recent drew an icon from its
        // token alone because the catalogue was not there yet (it is drawn again when it comes).
        var cat = null, catAsked = null, catFailed = false, faCat = storeGet(FACAT_KEY) || '', recentWaits = false;

        function pageById(id) { return pages.filter(function (p) { return p.id === id; })[0] || null; }
        function toneNow() { var n = Number(storeGet(TONE_KEY)); return n >= 0 && n <= 5 ? Math.floor(n) : 0; }
        function recentGet() {
            var r = [];
            try { r = JSON.parse(storeGet(RECENT_KEY) || '[]'); } catch (e) { r = []; }
            return Array.isArray(r) ? r.filter(function (x) { return typeof x === 'string' && x !== '' && x.length <= RECENT_LEN; }).slice(0, RECENT_MAX) : [];
        }
        function recentAdd(text) {
            var r = recentGet().filter(function (x) { return x !== text; });
            r.unshift(text);
            storeSet(RECENT_KEY, JSON.stringify(r.slice(0, RECENT_MAX)));
        }
        function currentLang() {
            var l = String(document.documentElement.lang || 'en').toLowerCase();
            return files[l] ? l : (files[l.split('-')[0]] ? l.split('-')[0] : 'en');
        }

        /* ── the items a cell stands for ── */

        function toneLabel(n) { return t('js.shout.tone_' + n); }
        /** An ordinary emoji: in the remembered tone unless `exact` (Recent keeps what was chosen). */
        function uniItem(row, exact, toneOf) {
            var tn = toneOf !== undefined ? toneOf : (row.t ? toneNow() : 0);
            var text = exact || (tn > 0 && row.t ? row.t[tn - 1] : row.c);
            return { kind: 'u', text: text, row: row, tone: tn, label: row.n + (row.t && tn > 0 ? ', ' + toneLabel(tn) : ''), vars: !!row.t };
        }
        function faItem(face, style) {
            var def = face.v[0];
            var st = style && face.v.indexOf(style) >= 0 ? style : def;
            var stl = fa.styles[st] ? fa.styles[st].label : st;
            return { kind: 'f', face: face, style: st, text: ':fa-' + face.n + (st === def ? '' : '/' + st) + ':',
                     label: face.l + (st === def ? '' : ' (' + stl + ')'), vars: face.v.length > 1 };
        }
        function emoteItem(r, sticker) {
            return { kind: sticker ? 's' : 'e', r: r, code: r.code, text: ':' + r.code + ':', label: ':' + r.code + ':', vars: false };
        }
        /**
         * What a remembered text is now: an emoji, a face or (1.70.0) another icon of the package (if the
         * site still offers it), an emote (if there still is one). An emote while this context's list is
         * still on its way (1.70.0 G — it is asked for as the picker opens) keeps its place: an empty cell
         * that says its code and inserts it, drawn as the emote when the list comes (emotesArrived()).
         */
        function itemForText(text) {
            var m = FA_TOKEN.exec(text);
            if (m) {
                if (!fa) return null;
                var f = fa.byName[m[1]] || (cat && cat.byName[m[1]]) || null;
                if (f) return m[2] && f.v.indexOf(m[2]) < 0 ? null : faItem(f, m[2] || '');
                // Not a face. With the faces alone offered, or with the catalogue here and not naming it, it is
                // nothing this picker offers now. Otherwise the catalogue is on its way (catalogLoad()): the
                // icon is drawn from its token until it comes — the style it names, else the default style —
                // and the page is drawn again when it does.
                if (fa.scope === 'faces' || cat) return null;
                var st = m[2] || fa.style;
                if (!fa.styles[st]) return null;
                recentWaits = true;
                return { kind: 'f', face: { n: m[1], l: faLabel(m[1]), v: [st], icon: true }, style: st, text: text, label: faLabel(m[1]), vars: false };
            }
            var e = /^:([a-z0-9_]{2,32}):$/.exec(text);
            if (e) {
                if (!emotesIn) {
                    if (!emotesWait) return null;
                    recentWaitsEm = true;
                    return { kind: 'e', r: null, code: e[1], text: text, label: text, vars: false };
                }
                var r = emotePlain.filter(function (x) { return x.code === e[1]; })[0];
                return r ? emoteItem(r, false) : null;
            }
            var hit = uni ? uni.byChar[text] : null;
            if (hit) return uniItem(hit.row, text, hit.tone);
            return { kind: 'u', text: text, row: null, tone: 0, label: text, vars: false };
        }

        /* ── the grid ── */

        function cellFor(it, k) {
            // A group's heading in a search (1.70.0: "Font Awesome", before its icons): a line across the
            // grid, not a cell — the arrows and Enter only ever reach cells.
            if (it.kind === 'h') return el('div', { className: 'shout-picker-group', dataset: { k: String(k) } }, it.label);
            var b = document.createElement('button');
            b.type = 'button';
            b.tabIndex = -1;
            b.className = 'shout-picker-cell' + (it.kind === 'f' ? ' shout-picker-fa' : '') + (it.vars ? ' has-var' : '');
            b.setAttribute('data-k', String(k));
            var title = it.label + (it.vars ? DASH + t('js.shout.variants_hint') : '');
            b.title = title;
            b.setAttribute('aria-label', title);
            if (it.kind === 'u') b.textContent = it.text;
            else if (it.kind === 'f') b.appendChild(faGlyph(fa, it.face.n, it.style, it.face.icon));
            else if (it.r) b.appendChild(emoteImg(it.r, it.kind === 's' ? 'shout-picker-sticker' : 'shout-emote'));
            // (no row: Recent's emote whose list is on its way — its place, its title, nothing drawn yet)
            return b;
        }

        /** Draw a list into the grid: the first slice at once, the rest on the frames after it. */
        function render(list, wide) {
            job++;
            var mine = job, i = 0;
            items = list;
            closeVariants(false);
            gridEl.textContent = '';
            gridEl.scrollTop = 0;
            gridEl.className = 'shout-picker-grid' + (wide ? ' shout-picker-grid-wide' : '');
            function slice(n) {
                var frag = document.createDocumentFragment();
                for (var end = Math.min(list.length, i + n); i < end; i++) frag.appendChild(cellFor(list[i], i));
                gridEl.appendChild(frag);
            }
            slice(FIRST_CELLS);
            var c0 = gridEl.querySelector('.shout-picker-cell');
            if (c0) c0.tabIndex = 0;
            (function more() {
                if (mine !== job || i >= list.length) return;
                requestAnimationFrame(function () { if (mine !== job) return; slice(MORE_CELLS); more(); });
            })();
        }
        function status(text) { statusEl.textContent = text || ''; statusEl.hidden = !text; }

        function show(id) {
            var page = pageById(id);
            if (!page) return;
            current = id;
            lastPage = id;
            Array.prototype.forEach.call(tabsEl.children, function (b) {
                var on = b.dataset.g === id;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) {
                    // The row of tabs scrolls on its own; only it moves (never scrollIntoView, which would
                    // scroll the page under the picker as well).
                    var l = b.offsetLeft, r = l + b.offsetWidth;
                    if (l < tabsEl.scrollLeft) tabsEl.scrollLeft = l - 4;
                    else if (r > tabsEl.scrollLeft + tabsEl.clientWidth) tabsEl.scrollLeft = r - tabsEl.clientWidth + 4;
                }
            });
            // The "every icon" page (1.70.0) has its categories over the grid, once the catalogue is here.
            if (page.cats && !cat) {
                catsEl.hidden = true;
                render([], false);
                status(t('js.shout.fa_loading'));
                catalogLoad().then(function (c) { if (!c && current === id && !panel.hidden) status(t('js.shout.fa_failed')); });
                onMove();
                return;
            }
            catsEl.hidden = !page.cats;
            if (page.cats) drawCats();
            var list = page.fill();
            render(list, page.wide);
            status(page.note ? page.note() : (!list.length && page.empty ? page.empty() : ''));
            onMove();
        }

        function tabFor(page) {
            var b = el('button', { type: 'button', className: 'shout-picker-tab', role: 'tab', title: page.label,
                                   'aria-label': page.label, 'aria-selected': 'false', dataset: { g: page.id } });
            if (page.img) b.appendChild(emoteImg(page.img, 'shout-emote'));
            else if (page.faTab) b.appendChild(faGlyph(fa, page.faTab, fa.byName[page.faTab] ? fa.byName[page.faTab].v[0] : 'solid'));
            // "Every icon" (1.70.0): Font Awesome's own `icons` glyph, in solid — the one style every Pro
            // package loads and draws it in, and the tab is drawn before the catalogue could say more.
            else if (page.faIcon) b.appendChild(faGlyph(fa, page.faIcon, 'solid', true));
            else b.appendChild(el('i', { className: page.icon, 'aria-hidden': 'true' }));
            b.addEventListener('click', function () { if (searchIn.value) { searchIn.value = ''; clearBtn.hidden = true; } show(page.id); });
            return b;
        }

        /** The pages, from what arrived: Recent, then Unicode's or Font Awesome's or both, then the pictures. */
        function buildPages() {
            pages = [{ id: 'recent', icon: 'bi bi-clock-history', labelOf: function () { return t('js.shout.tab_recent'); },
                       fill: function () { return recentGet().map(itemForText).filter(Boolean); },
                       empty: function () { return t('js.shout.recent_empty'); } }];
            if (uni) GROUPS.forEach(function (g) {
                if (!uni.groups[g.id] || !uni.groups[g.id].length) return;
                pages.push({ id: g.id, icon: g.icon, labelOf: function () { return t('js.shout.tab_' + g.id); },
                             fill: function () { return uni.groups[g.id].map(function (row) { return uniItem(row); }); } });
            });
            if (fa) fa.pages.forEach(function (p) {
                pages.push({ id: p.id, faTab: p.tab, labelOf: function () { return t('js.shout.tab_' + String(p.id).replace(/-/g, '_')); },
                             fill: function () { return fa.faces.filter(function (f) { return f.p === p.id; }).map(function (f) { return faItem(f, ''); }); } });
            });
            // 1.70.0: with the scope at `all`, every icon of the package — one page, its categories on chips.
            if (fa && fa.scope === 'all') {
                pages.push({ id: 'fa-all', faIcon: 'icons', cats: true, labelOf: function () { return t('js.shout.tab_fa_all'); },
                             fill: catalogItems, note: catalogNote });
            }
            addEmotePages();
            pages.forEach(function (p) { p.label = p.labelOf(); });
            tabsEl.textContent = '';
            pages.forEach(function (p) { tabsEl.appendChild(tabFor(p)); });
            relabel();
        }
        function addEmotePages() {
            if (!emotesIn) return;
            if (emotePlain.length && !pageById('emotes')) {
                pages.push({ id: 'emotes', img: emotePlain[0], labelOf: function () { return t('js.shout.tab_emotes'); },
                             fill: function () { return emotePlain.map(function (r) { return emoteItem(r, false); }); } });
            }
            if (emoteStick.length && !pageById('stickers')) {
                pages.push({ id: 'stickers', img: emoteStick[0], wide: true, labelOf: function () { return t('js.shout.tab_stickers'); },
                             fill: function () { return emoteStick.map(function (r) { return emoteItem(r, true); }); },
                             // The room sends a sticker the moment it is picked; an editor puts in its code.
                             note: function () { return t(typeof opts.sticker === 'function' ? 'js.shout.sticker_hint' : 'js.shout.sticker_insert_hint'); } });
            }
        }
        function firstPage() {
            if (recentGet().some(function (x) { return !!itemForText(x); })) return 'recent';
            return pages[1] ? pages[1].id : 'recent';
        }

        /**
         * The images arrive after the panel does, and that is on purpose: the emoji do not wait for them,
         * and the two image tabs appear beside the others when the list comes back. Nothing is asked for
         * at all when the operator has the feature switched off.
         *
         * And nothing before the picker OPENS (1.70.0 G). Until then the list was asked for the moment the
         * picker was mounted — on page load for every editor on the page, and again for an editor drawn
         * later — whether anybody opened it or not. Now open() asks, right after the emoji: the first
         * picker of this context to open sends the one request of the page (emotesLoad()), any other one
         * takes its answer, an opening after a failure asks again, and after forgetEmotes() (an upload on
         * the emotes page) the next opening brings the new list.
         */
        function emotesWant() {
            if (!opts.emotes) return;
            var p = emotesLoad(ctx);
            if (p === emotesFrom) return;                   // asked for already, or here already
            emotesFrom = p;
            emotesWait = true;
            emotesDone = p.then(function (rows) {
                if (emotesFrom !== p) return;               // a newer list was asked for since
                emotesWait = false;
                if (!rows) { emotesFrom = null; emotesArrived(false); return; }
                emotePlain = rows.filter(function (r) { return !r.sticker; });
                // The stickers' page where this context offers stickers at all (the room: always with a
                // sender for them; an editor: where its text draws them — the server's answer says).
                emoteStick = opts.stickers ? rows.filter(function (r) { return !!r.sticker; }) : [];
                emotesIn = true;
                emotesArrived(true);
            });
        }
        /**
         * The list came (or, `ok` false, did not). Its tabs join the others — the pages are built later and
         * take them then, if they are not built yet; a list asked for again replaces the pages of the one
         * before. What was drawn without it is drawn again: Recent, where its emotes kept their places, a
         * search, an emote page on show; the cell that had the focus keeps it.
         */
        function emotesArrived(ok) {
            if (ok && pages.length) {
                pages = pages.filter(function (p) { return p.id !== 'emotes' && p.id !== 'stickers'; });
                Array.prototype.slice.call(tabsEl.children).forEach(function (b) {
                    if (b.dataset.g === 'emotes' || b.dataset.g === 'stickers') tabsEl.removeChild(b);
                });
                var before = pages.length;
                addEmotePages();
                pages.slice(before).forEach(function (p) { p.label = p.labelOf(); tabsEl.appendChild(tabFor(p)); });
                if (lastPage && !pageById(lastPage)) lastPage = '';     // its page went with the old list
            }
            if (panel.hidden || !pages.length) return;
            if (current === 'search') {
                if (searchIn.value.trim()) runSearch();
            } else if ((current === 'recent' && recentWaitsEm) || current === 'emotes' || current === 'stickers') {
                recentWaitsEm = false;
                var a = document.activeElement, k = a && a !== gridEl && gridEl.contains(a) ? a.getAttribute('data-k') : null;
                show(pageById(current) ? current : firstPage());
                var c = k !== null ? gridEl.querySelector('.shout-picker-cell[data-k="' + k + '"]') : null;
                if (c) c.focus({ preventScroll: true });
            }
            // Two more tabs can change how tall the panel is.
            onMove();
        }

        /**
         * Everything this language needs, asked once: the ordinary emoji unless Font Awesome replaces them,
         * the faces unless they are off. Font Awesome that was to replace the ordinary emoji and did not come
         * (the package went, the setting changed) gives the ordinary ones rather than an empty picker.
         */
        function ensureData() {
            var want = currentLang();
            if (loading && lang === want) return loading;
            lang = want; uni = null; fa = null; en = null; enAsked = false; pages = []; tabsEl.textContent = '';
            var uniUrl = files[want] || files.en || '';
            var faUrl = faMode !== 'off' ? API + 'shout_emoji&v=' + encodeURIComponent(opts.faVer || '') + '&lang=' + encodeURIComponent(want) + forQ : '';
            var mine = loading = Promise.all([
                faMode !== 'fa' && uniUrl ? fetchJson(uniUrl).then(function (j) { if (lang === want) uni = prepUnicode(j); }) : null,
                faUrl ? fetchJson(faUrl).then(function (j) { if (lang === want) fa = prepFa(j); }) : null,
            ]).then(function () {
                if (lang === want && faMode === 'fa' && !fa && uniUrl) return fetchJson(uniUrl).then(function (j) { if (lang === want) uni = prepUnicode(j); });
            }).then(function () {
                if (lang !== want || loading !== mine) return;
                if (!uni && !fa) { loading = null; return; }    // nothing came: the next open asks again
                // The catalogue is the same in every language; it is kept unless this answer names another.
                if (cat && (!fa || !fa.catalog || fa.catalog.v !== cat.v)) { cat = null; catAsked = null; }
                buildPages();
            });
            return mine;
        }

        /* ── the catalogue: every icon of the package (1.70.0) ──
         *
         * Asked for once, and only when the scope needs it (see the head of mountPicker()); the answer is
         * the package's catalog.json as stored, and prepCatalog() turns it into icons with their variants.
         * While it is on its way the "every icon" page says so, a search shows what it found without it,
         * and Recent draws a remembered icon from its token; each is drawn again when it comes. A failure
         * is forgotten, so the next need asks again. */
        function catalogLoad() {
            if (!fa || !fa.catalog || fa.scope === 'faces') return Promise.resolve(null);
            if (cat) return Promise.resolve(cat);
            if (catAsked) return catAsked;
            var v = fa.catalog.v, url = API + 'shout_emoji&part=catalog&v=' + encodeURIComponent(v) + forQ;
            var p = catAsked = fetchJson(url).then(function (j) {
                if (catAsked !== p) return cat;
                // Kept as prepared icons only: the answer as parsed would be a second copy of all of it.
                delete jsonCache[url];
                // A language switch in between brought a new answer about the faces: the catalogue is the
                // same while it names the same one, and is prepared with the answer that is here now.
                cat = fa && fa.catalog && fa.catalog.v === v ? prepCatalog(j, fa) : null;
                if (!cat) { catAsked = null; return null; }
                catalogArrived();
                return cat;
            });
            return p;
        }
        /** What waited for the catalogue is drawn again: the page of every icon, a search, Recent. */
        function catalogArrived() {
            if (panel.hidden) return;
            if (current === 'search') { if (searchIn.value.trim()) runSearch(); }
            else if (current === 'fa-all' || (current === 'recent' && recentWaits)) show(current);
        }
        /** The categories that have icons here, in the reader's alphabet — the brands and the rest last. */
        function catOrder() {
            // Between a language switch and the new answer there is a catalogue and no answer to name it with.
            if (!cat || !fa || !fa.catalog) return [];
            var names = fa.catalog.names, out = [];
            cat.cats.forEach(function (id, i) {
                if (cat.byCat[i].length) out.push({ id: id, i: i, l: names[id] || faLabel(id), n: cat.byCat[i].length, last: id === 'brands' || id === 'other' });
            });
            return out.sort(function (a, b) {
                if (a.last !== b.last) return a.last ? 1 : -1;
                if (a.last) return a.id === 'brands' ? -1 : 1;
                return a.l.localeCompare(b.l, lang || undefined, { sensitivity: 'base' });
            });
        }
        /** The category on show: the one looked at last, while it is still here, else the first. */
        function catNow() {
            var order = catOrder();
            return order.filter(function (c) { return c.id === faCat; })[0] || order[0] || null;
        }
        /**
         * What a category's chip shows (1.71.0): Font Awesome's own icon for it — or the short run the
         * committed list names (A B C, 1 2 3) — each in its default style, the site's emoji style where the
         * icon has it, else the classic family at that weight (faVariants()). Only names the catalogue
         * here holds: a category whose icon this package lacks, or that the list does not name (one a later
         * version adds), shows the generic glyph, else its own first icon — and with none of those, its
         * name, as every chip did until now.
         */
        function catGlyphs(c) {
            var want = fa.catalog.icons[c.id] || [], fb = fa.catalog.fallback;
            var names = want.length && want.every(function (n) { return !!cat.byName[n]; }) ? want
                      : fb && cat.byName[fb] ? [fb]
                      : cat.byCat[c.i][0] ? [cat.byCat[c.i][0].n] : [];
            return names.map(function (n) { return faGlyph(fa, n, cat.byName[n].v[0], true); });
        }
        /**
         * The chips: one per category, the chosen one pressed; one stop for the Tab key, the arrows walk them.
         * From 1.71.0 each is its icon, not its name — seventy words were a strip to read, not to scan — and
         * the name is what a screen reader says (aria-label) and what the site's tooltip shows under a pointer
         * or the keyboard's focus (data-tip, assets/js/app.js tipOnHover(); no `title`, whose box the browser
         * would draw a second later). The line under the grid names the category on show, in words.
         */
        function drawCats() {
            var now = catNow();
            catsEl.textContent = '';
            catOrder().forEach(function (c) {
                var on = !!now && c.id === now.id;
                var g = catGlyphs(c);
                var b = el('button', { type: 'button', className: 'shout-picker-cat' + (g.length > 1 ? ' shout-picker-cat-run' : g.length ? '' : ' shout-picker-cat-text') + (on ? ' active' : ''),
                                       'aria-pressed': on ? 'true' : 'false', 'aria-label': c.l, tabindex: on ? '0' : '-1',
                                       dataset: { c: c.id, tip: t('js.shout.fa_cat_title', { name: c.l, n: c.n }) } }, g.length ? g : c.l);
                b.addEventListener('click', function () { chooseCat(c.id); });
                catsEl.appendChild(b);
            });
            keepChipInView();
        }
        function keepChipInView() {
            var b = catsEl.querySelector('.shout-picker-cat.active');
            if (!b) return;
            // Like the tabs: only the strip scrolls, never the page under the picker.
            var l = b.offsetLeft, r = l + b.offsetWidth;
            if (l < catsEl.scrollLeft) catsEl.scrollLeft = l - 4;
            else if (r > catsEl.scrollLeft + catsEl.clientWidth) catsEl.scrollLeft = r - catsEl.clientWidth + 4;
        }
        /** Show a category: remembered by this browser, its chip pressed, its icons drawn in slices. */
        function chooseCat(id) {
            if (!cat) return;
            faCat = id;
            storeSet(FACAT_KEY, id);
            if (current !== 'fa-all') { show('fa-all'); return; }
            Array.prototype.forEach.call(catsEl.children, function (b) {
                var on = b.dataset.c === id;
                b.classList.toggle('active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
                b.tabIndex = on ? 0 : -1;
            });
            keepChipInView();
            render(catalogItems(), false);
            status(catalogNote());
            onMove();
        }
        /** The icons of the category on show — a face as the face it is, with its words in the reader's language. */
        function catalogItems() {
            var c = catNow();
            if (!c) return [];
            return cat.byCat[c.i].map(function (ic) { return fa.byName[ic.n] ? faItem(fa.byName[ic.n], '') : faItem(ic, ''); });
        }
        function catalogNote() {
            var c = catNow();
            return c ? t('js.shout.fa_cat_status', { name: c.l, n: c.n }) : '';
        }

        /* ── the search ── */

        var searchTimer = 0;
        /** What a search reads of one entry, folded once and kept on it: its name, and name + keywords. */
        function hay(s, name, kw) {
            if (s.h === undefined) {
                s.nf = fold(name);
                s.h = ' ' + s.nf + ' ' + fold(String(kw || '').replace(/\|/g, ' ')) + ' ';
            }
            return s;
        }
        /** -1, or how well: 0 the name starts with the query, 1 a word of the name does, 2 a keyword does. */
        function rank(h, nf, q, words) {
            for (var i = 0; i < words.length; i++) if (h.indexOf(' ' + words[i]) < 0) return -1;
            if (nf.indexOf(q) === 0) return 0;
            return (' ' + nf).indexOf(' ' + words[0]) >= 0 ? 1 : 2;
        }
        function runSearch() {
            var raw = searchIn.value.trim();
            var q = fold(raw);
            if (!q) { if (current === 'search') show(lastPage || firstPage()); return; }
            if (!pages.length) return;                                    // the data is still on its way
            // English is the fallback of every other language: its file, asked for the first time somebody
            // searches, row for row the same emoji as the reader's own.
            if (uni && lang !== 'en' && files.en && !enAsked) {
                enAsked = true;
                fetchJson(files.en).then(function (j) { en = prepUnicode(j); if (current === 'search' && searchIn.value.trim()) runSearch(); });
            }
            var words = q.split(' '), buckets = [[], [], []], late = [], seen = {};
            if (uni) uni.rows.forEach(function (row) {
                var s = hay(row, row.n, row.k), r = rank(s.h, s.nf, q, words);
                if (r >= 0) { buckets[r].push(uniItem(row)); seen[row.i] = true; }
            });
            // A face by its label and words in the reader's language (English after them), and its own name.
            if (fa) fa.faces.forEach(function (f) {
                var s = hay(f, f.l, f.k + '|' + f.n), r = rank(s.h, s.nf, q, words);
                if (r >= 0) buckets[r].push(faItem(f, ''));
            });
            // An emote by its code and its name.
            emotePlain.forEach(function (r0) {
                var s = hay(r0._s || (r0._s = {}), String(r0.code).replace(/_/g, ' '), String(r0.name || ''));
                var r = rank(s.h, s.nf, q, words);
                if (r >= 0) buckets[r].push(emoteItem(r0, false));
            });
            // What only the English words find, after everything the reader's own language found.
            if (uni && en && en.rows.length === uni.rows.length) en.rows.forEach(function (row) {
                if (seen[row.i]) return;
                var s = hay(row, row.n, row.k);
                if (rank(s.h, s.nf, q, words) >= 0) late.push(uniItem(uni.rows[row.i]));
            });
            var list = buckets[0].concat(buckets[1], buckets[2], late).slice(0, SEARCH_MAX);
            // 1.70.0: with a wider scope, every other icon of the package, in a group of its own after them —
            // once the catalogue is here; asked for by this very search the first time (a failure says so
            // once, and the next opening of the picker asks again).
            var faList = [], faPending = false;
            if (fa && fa.scope !== 'faces') {
                if (cat) faList = faSearch(q, words, raw);
                else if (!catFailed) {
                    faPending = true;
                    catalogLoad().then(function (c) { if (!c) { catFailed = true; if (current === 'search' && searchIn.value.trim()) runSearch(); } });
                }
            }
            current = 'search';
            catsEl.hidden = true;
            Array.prototype.forEach.call(tabsEl.children, function (b) { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
            render(faList.length ? list.concat([{ kind: 'h', label: t('js.shout.fa_group', { n: faList.length }) }], faList) : list, false);
            status(faList.length ? t('js.shout.search_found_fa', { n: list.length, m: faList.length })
                   : list.length ? t('js.shout.search_found', { n: list.length })
                   : faPending ? t('js.shout.fa_loading') : t('js.shout.search_none', { q: raw }));
            onMove();
        }
        /**
         * The search's Font Awesome group (1.70.0): every icon of the catalogue that every word typed begins
         * a word of — the exact name first, then the label (the name read as words, or the label the index
         * gives it) beginning with what was typed, then the label's words, then Font Awesome's English terms,
         * then the name of a category the icon is in, in the reader's language or Font Awesome's own id, which
         * is how a Polish reader finds the icons beyond the faces (their terms are English). Shorter labels
         * first within a rank. The faces are the search's above, found by their words in the reader's
         * language; they are not repeated here.
         */
        function faSearch(q, words, raw) {
            var exact = raw.toLowerCase().replace(/\s+/g, '-'), hitCat = {}, anyCat = false;
            var has = function (h) { for (var i = 0; i < words.length; i++) if (h.indexOf(' ' + words[i]) < 0) return false; return true; };
            cat.cats.forEach(function (id, i) {
                if (has(' ' + fold((fa.catalog.names[id] || '') + ' ' + id) + ' ')) { hitCat[i] = true; anyCat = true; }
            });
            var buckets = [[], [], [], [], []];
            cat.icons.forEach(function (ic) {
                if (fa.byName[ic.n]) return;
                if (ic.h === undefined) {                   // folded once, the first time a search reads it
                    ic.nf = fold(ic.n);
                    ic.lf = fold(ic.l);
                    ic.lh = ' ' + ic.lf + ' ' + ic.nf + ' ';
                    ic.h = ic.lh + fold(ic.w) + ' ';
                }
                var r = ic.n === exact || ic.nf === q ? 0 : ic.lf.indexOf(q) === 0 ? 1 : has(ic.lh) ? 2 : has(ic.h) ? 3
                      : anyCat && ic.c.some(function (ci) { return hitCat[ci]; }) ? 4 : -1;
                if (r >= 0) buckets[r].push(ic);
            });
            var out = [];
            buckets.forEach(function (b, r) {
                if (r > 0 && r < 4) b.sort(function (x, y) { return x.l.length - y.l.length; });
                out = out.concat(b);
            });
            return out.slice(0, FA_SEARCH_MAX).map(function (ic) { return faItem(ic, ''); });
        }
        searchIn.addEventListener('input', function () {
            clearBtn.hidden = searchIn.value === '';
            clearTimeout(searchTimer);
            searchTimer = setTimeout(runSearch, 60);
        });
        clearBtn.addEventListener('click', function () {
            searchIn.value = '';
            clearBtn.hidden = true;
            runSearch();
            searchIn.focus();
        });

        /* ── picking ── */

        function itemOf(cell) { return cell ? items[Number(cell.getAttribute('data-k'))] || null : null; }
        function cellOf(target) {
            var c = target && target.closest ? target.closest('.shout-picker-cell') : null;
            return c && gridEl.contains(c) ? c : null;
        }
        function pick(it) {
            if (!it) return;
            // The room SENDS a sticker (opts.sticker); an editor puts its code in like an emote's (1.70.0) —
            // and leaves Recent alone, which never held a sticker.
            if (it.kind === 's' && typeof opts.sticker === 'function') { close(); opts.sticker(it.code); return; }
            if (opts.insert(it.text) !== false && it.kind !== 's') recentAdd(it.text);
        }

        /* ── the variants, as a phone offers them ── */

        var varFor = null, hold = null, suppress = false;
        function variantsOf(it) {
            if (it.kind === 'u' && it.row && it.row.t) {
                var base = it.row;
                return [0, 1, 2, 3, 4, 5].map(function (n) {
                    return { text: n ? base.t[n - 1] : base.c, label: base.n + ', ' + toneLabel(n), tone: n, current: n === it.tone };
                });
            }
            if (it.kind === 'f') {
                return it.face.v.map(function (s) {
                    var f = faItem(it.face, s);
                    return { text: f.text, label: it.face.l + ' (' + (fa.styles[s] ? fa.styles[s].label : s) + ')', face: it.face, style: s, current: s === it.style };
                });
            }
            return [];
        }
        function openVariants(cell) {
            var it = itemOf(cell);
            if (!it || !it.vars) return;
            if (!varEl.hidden && varFor === cell) return;
            closeVariants(false);
            varFor = cell;
            varEl.textContent = '';
            varEl.setAttribute('aria-label', t('js.shout.variants', { name: it.kind === 'f' ? it.face.l : it.row.n }));
            variantsOf(it).forEach(function (o, i) {
                var b = el('button', { type: 'button', className: 'shout-picker-vopt' + (o.current ? ' current' : ''), role: 'option',
                                       title: o.label, 'aria-label': o.label, 'aria-selected': o.current ? 'true' : 'false', dataset: { i: String(i) } });
                if (o.face) b.appendChild(faGlyph(fa, o.face.n, o.style, o.face.icon)); else b.textContent = o.text;
                b.addEventListener('click', function () {
                    if (o.tone !== undefined) storeSet(TONE_KEY, String(o.tone));
                    pick({ kind: it.kind, text: o.text });
                    closeVariants(true);
                    // The tone chosen is the grid's from now on: its cells redrawn in it, where they are.
                    if (o.tone !== undefined) retone();
                });
                varEl.appendChild(b);
            });
            varEl.hidden = false;
            cell.setAttribute('aria-expanded', 'true');
            placeVariants(cell);
            var first = varEl.querySelector('.current') || varEl.firstElementChild;
            if (first) first.focus({ preventScroll: true });
        }
        function closeVariants(focusBack) {
            if (varEl.hidden) return;
            varEl.hidden = true;
            varEl.textContent = '';
            if (varFor) {
                varFor.removeAttribute('aria-expanded');
                if (focusBack && gridEl.contains(varFor)) varFor.focus({ preventScroll: true });
            }
            varFor = null;
        }
        /** Over the cell, centred on it, inside the panel; under it when the first row leaves no room above. */
        function placeVariants(cell) {
            varEl.style.left = '0px'; varEl.style.top = '0px';
            var pr = panel.getBoundingClientRect(), cr = cell.getBoundingClientRect();
            var w = varEl.offsetWidth, h = varEl.offsetHeight;
            var left = cr.left + cr.width / 2 - w / 2 - pr.left - panel.clientLeft;
            left = Math.max(4, Math.min(left, panel.clientWidth - w - 4));
            var top = cr.top - pr.top - panel.clientTop - h - 4;
            if (top < 4) top = cr.bottom - pr.top - panel.clientTop + 4;
            varEl.style.left = Math.round(left) + 'px';
            varEl.style.top = Math.round(top) + 'px';
        }
        /** The remembered tone drawn in the cells already on the screen. */
        function retone() {
            Array.prototype.forEach.call(gridEl.children, function (cell) {
                var it = itemOf(cell);
                if (!it || it.kind !== 'u' || !it.row || !it.row.t || current === 'recent') return;
                var fresh = uniItem(it.row);
                items[Number(cell.getAttribute('data-k'))] = fresh;
                cell.textContent = fresh.text;
                var title = fresh.label + DASH + t('js.shout.variants_hint');
                cell.title = title;
                cell.setAttribute('aria-label', title);
            });
        }
        function cancelHold() { if (hold) { clearTimeout(hold.timer); hold = null; } }

        gridEl.addEventListener('pointerdown', function (e) {
            cancelHold();
            suppress = false;                               // a new press: whatever the last one did is over
            var cell = cellOf(e.target);
            if (!cell || !cell.classList.contains('has-var') || (e.pointerType === 'mouse' && e.button !== 0)) return;
            hold = { x: e.clientX, y: e.clientY, timer: setTimeout(function () { hold = null; suppress = true; openVariants(cell); }, HOLD_MS) };
        });
        gridEl.addEventListener('pointermove', function (e) {
            if (hold && (Math.abs(e.clientX - hold.x) > 8 || Math.abs(e.clientY - hold.y) > 8)) cancelHold();
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { gridEl.addEventListener(ev, cancelHold); });
        gridEl.addEventListener('scroll', function () { cancelHold(); closeVariants(false); }, { passive: true });
        gridEl.addEventListener('click', function (e) {
            var cell = cellOf(e.target);
            if (!cell) return;
            // The press that opened the variants ends in a click on the same cell: that one inserts nothing.
            if (suppress) { suppress = false; return; }
            pick(itemOf(cell));
        });
        // A right click, a long press that the browser reports as one, the context-menu key, Shift+F10.
        gridEl.addEventListener('contextmenu', function (e) {
            var cell = cellOf(e.target);
            if (!cell || !cell.classList.contains('has-var')) return;
            e.preventDefault();
            cancelHold();
            openVariants(cell);
        });
        // One stop for the Tab key: the cell last focused keeps tabindex 0, the rest -1 (arrows walk them).
        gridEl.addEventListener('focusin', function (e) {
            var cell = cellOf(e.target);
            if (!cell) return;
            Array.prototype.forEach.call(gridEl.querySelectorAll('.shout-picker-cell[tabindex="0"]'), function (c) { if (c !== cell) c.tabIndex = -1; });
            cell.tabIndex = 0;
        });

        function cells() { return Array.prototype.slice.call(gridEl.querySelectorAll('.shout-picker-cell')); }

        /** Where the arrows go above the grid: the category chips when they are shown (1.70.0), else the search box. */
        function focusAbove() {
            var chip = catsEl.hidden ? null : catsEl.querySelector('.shout-picker-cat[tabindex="0"]');
            if (chip) chip.focus({ preventScroll: true }); else searchIn.focus();
        }
        /**
         * Arrows walk the grid. Left and right go to the cell before and after; up and down to the nearest
         * cell of the row above or below, measured where the cells are rather than counted, because rows
         * wrap and (1.70.0) a search's group starts a row of its own after a heading.
         */
        function move(from, dx, dy) {
            var all = cells();
            var i = all.indexOf(from);
            if (i < 0) return;
            if (dx) { all[Math.max(0, Math.min(all.length - 1, i + dx))].focus(); return; }
            var top = from.offsetTop, left = from.offsetLeft, rowTop = null, best = null;
            all.forEach(function (c) {
                var ct = c.offsetTop;
                if (dy > 0 ? ct <= top : ct >= top) return;
                if (rowTop === null || (dy > 0 ? ct < rowTop : ct > rowTop)) { rowTop = ct; best = c; }
                else if (ct === rowTop && Math.abs(c.offsetLeft - left) < Math.abs(best.offsetLeft - left)) best = c;
            });
            if (best) best.focus();
            else if (dy < 0) focusAbove();                                  // above the first row
        }

        function onKey(e) {
            if (panel.hidden) return;
            if (e.key === 'Escape') {
                // One thing at a time, the innermost first: the variants, then the search, then the picker.
                // And nothing further out (1.70.0): the Info panel, the dialog an editor sits in, closes on
                // an Esc it hears on the document — the picker hears it first (capture) and keeps it.
                e.preventDefault();
                e.stopPropagation();
                if (!varEl.hidden) { closeVariants(true); return; }
                if (searchIn.value !== '') { searchIn.value = ''; clearBtn.hidden = true; runSearch(); searchIn.focus(); return; }
                close(); btn.focus(); return;
            }
            if (!panel.contains(e.target)) return;
            if (e.target === searchIn) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    var chip = catsEl.hidden ? null : catsEl.querySelector('.shout-picker-cat[tabindex="0"]');
                    var c0 = chip || gridEl.querySelector('.shout-picker-cell');
                    if (c0) c0.focus({ preventScroll: true });
                } else if (e.key === 'Enter' && !e.isComposing) {
                    e.preventDefault();
                    pick(itemOf(gridEl.querySelector('.shout-picker-cell')));
                }
                return;
            }
            // The category chips (1.70.0): left and right choose the one beside, Home and End the first and
            // the last; down goes into the grid, up to the search box. Enter and Space are a chip's own click.
            if (catsEl.contains(e.target)) {
                var chips = Array.prototype.slice.call(catsEl.children), at0 = chips.indexOf(e.target);
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') {
                    e.preventDefault();
                    var to = e.key === 'Home' ? 0 : e.key === 'End' ? chips.length - 1 : at0 + (e.key === 'ArrowRight' ? 1 : -1);
                    var nb = chips[Math.max(0, Math.min(chips.length - 1, to))];
                    if (nb && nb !== e.target) { chooseCat(nb.dataset.c); nb.focus({ preventScroll: true }); }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    var g0 = gridEl.querySelector('.shout-picker-cell');
                    if (g0) g0.focus({ preventScroll: true });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    searchIn.focus();
                }
                return;
            }
            var d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
            if (varEl.contains(e.target)) {
                if (!d) return;
                e.preventDefault();
                var opts2 = Array.prototype.slice.call(varEl.children), at = opts2.indexOf(e.target);
                var nx = opts2[Math.max(0, Math.min(opts2.length - 1, at + (d[0] || d[1])))];
                if (nx) nx.focus();
                return;
            }
            var cell = cellOf(e.target);
            if (!cell) return;
            if ((e.key === 'Enter' && e.shiftKey) || e.key === 'ContextMenu') {
                e.preventDefault();
                openVariants(cell);
                return;
            }
            if (!d) return;
            e.preventDefault();
            move(cell, d[0], d[1]);
        }
        function onOutside(e) {
            // The click that ends a long press lands on the held cell — on the face's <i> inside it, for a
            // Font Awesome face — and must not close the bubble it has just opened.
            if (!varEl.hidden && !varEl.contains(e.target) && !(varFor && varFor.contains(e.target))) closeVariants(false);
            if (panel.contains(e.target) || btn.contains(e.target)) return;
            close();
        }

        /**
         * Put the panel against the button that opened it (1.62.0).
         *
         * The button moved into the text field's top right in 1.61.0 and the panel kept opening from
         * the field's LEFT edge, a whole field away from what was pressed. The rules now:
         *
         *   · its right edge on the button's right edge;
         *   · ABOVE the button when the panel fits between it and the top of the window, below when
         *     it does not — above first, because below it covers the text being written;
         *   · clamped so no part of it leaves the window, and never wider than the window less a
         *     margin, which on a phone is most of the screen.
         *
         * Measured in the viewport and written as offsets inside the wrapper (which is what the
         * panel is absolute to), so it scrolls with the page like anything else in the box.
         *
         * A FLOATING panel (1.70.0, every editor but the room's) is fixed to the window: the same rules,
         * written as window coordinates, and done again whenever anything under it scrolls — the page, the
         * Info panel's body — so it stays on its button. It closes when the button is gone (its editor
         * was taken away or drawn again), is not drawn (the editor switched to Preview) or has left the
         * window altogether: a picker hanging over nothing belongs to nothing.
         */
        var EDGE = 8, GAP = 6;
        function place() {
            if (panel.hidden) return;
            if (floating && (!btn.isConnected || !btn.getClientRects().length)) { close(); return; }
            panel.style.left = '0px'; panel.style.top = '0px'; panel.style.width = '';
            var vw = document.documentElement.clientWidth || window.innerWidth;
            var vh = document.documentElement.clientHeight || window.innerHeight;
            var pw = panel.offsetWidth;
            if (pw > vw - 2 * EDGE) { pw = vw - 2 * EDGE; panel.style.width = pw + 'px'; }
            var ph = panel.offsetHeight;
            var b = btn.getBoundingClientRect();
            if (floating && (b.bottom < 0 || b.top > vh || b.right < 0 || b.left > vw)) { close(); return; }
            var left = Math.max(EDGE, Math.min(b.right - pw, vw - EDGE - pw));
            var roomAbove = b.top - GAP - EDGE, roomBelow = vh - b.bottom - GAP - EDGE;
            var above = ph <= roomAbove || roomAbove >= roomBelow;
            var top = above ? b.top - GAP - ph : b.bottom + GAP;
            // Neither side has room for all of it: keep the top inside the window, so the tabs
            // and the first row are what is visible and the grid scrolls inside itself.
            if (top < EDGE) top = EDGE;
            if (floating) {
                panel.style.left = Math.round(left) + 'px';
                panel.style.top = Math.round(top) + 'px';
            } else {
                var h = host.getBoundingClientRect();
                panel.style.left = Math.round(left - h.left - host.clientLeft) + 'px';
                panel.style.top = Math.round(top - h.top - host.clientTop) + 'px';
            }
            panel.classList.toggle('shout-picker-below', !above);
        }
        function onMove() { if (!panel.hidden) requestAnimationFrame(place); }
        /** Something scrolled (a floating panel only): the page or a box the button is in — not the grid. */
        function onScroll(e) {
            var tg = e && e.target;
            if (tg && tg.nodeType === 1 && panel.contains(tg)) return;
            onMove();
        }

        // The tabs are one row that scrolls sideways; a mouse wheel over them scrolls it too — and over the
        // category chips (1.70.0), which are one such row as well.
        [tabsEl, catsEl].forEach(function (row) {
            row.addEventListener('wheel', function (e) {
                if (row.scrollWidth <= row.clientWidth || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
                row.scrollLeft += e.deltaY;
                e.preventDefault();
            }, { passive: false });
        });

        var viaTouch = false;
        btn.addEventListener('pointerdown', function (e) { viaTouch = e.pointerType === 'touch' || e.pointerType === 'pen'; });

        function open() {
            relabel();
            panel.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            document.addEventListener('click', onOutside, true);
            document.addEventListener('keydown', onKey, true);
            window.addEventListener('resize', onMove);
            if (floating) window.addEventListener('scroll', onScroll, true);
            var ready = !!pages.length && lang === currentLang();
            if (!ready) { gridEl.textContent = ''; items = []; status(t('js.common.loading')); }
            place();
            // The search box takes the typing at once — but not on a touch screen, where a focused box is
            // a keyboard over half the picker; there the first emoji takes the focus, as before.
            if (!viaTouch) searchIn.focus({ preventScroll: true });
            catFailed = false;                              // a catalogue that failed is asked for again
            var data = ensureData();
            // The emotes of this context (1.70.0 G): asked for now, the first time a picker of it opens —
            // after the emoji, which do not wait for them — and not again while the page has them.
            emotesWant();
            data.then(function () {
                if (panel.hidden) return;
                if (!pages.length) { status(t('js.shout.emoji_failed')); return; }
                // Every time it opens: what this browser used last, when there is any (as a phone's
                // picker opens), else the first page of emoji — or the results of a search still typed.
                recentWaits = false;
                recentWaitsEm = false;
                if (searchIn.value.trim()) runSearch();
                else show(firstPage());
                if (viaTouch || document.activeElement === document.body) {
                    var first = gridEl.querySelector('.shout-picker-cell');
                    if (first) first.focus({ preventScroll: true });
                }
                onMove();
                // 1.70.0: with every icon on offer, the catalogue comes once the first page is on the
                // screen — not before, so the picker opens as fast as it did — and at once when Recent has
                // drawn an icon from its token alone.
                if (fa && !cat && (recentWaits || fa.scope === 'all')) {
                    if (recentWaits) catalogLoad();
                    else if (window.requestIdleCallback) window.requestIdleCallback(function () { if (!panel.hidden) catalogLoad(); }, { timeout: 400 });
                    else setTimeout(function () { if (!panel.hidden) catalogLoad(); }, 50);
                }
            });
        }
        function close() {
            if (panel.hidden) return;
            cancelHold();
            closeVariants(false);
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', onOutside, true);
            document.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', onMove);
            if (floating) window.removeEventListener('scroll', onScroll, true);
        }
        function toggle() { if (panel.hidden) open(); else close(); }
        btn.addEventListener('click', toggle);
        // A live language switch (assets/js/lang-swap.js): the picker closes, its words are the new
        // language's at once, and its emoji are the new language's file the next time it opens.
        function onLangSwap() {
            close();
            loading = null;
            pages = [];
            current = '';
            relabel();
        }
        document.addEventListener('langswap', onLangSwap);
        /** Taken down for good (1.70.0): an editor drawn again replaces its picker rather than adding one. */
        function destroy() {
            close();
            btn.removeEventListener('click', toggle);
            document.removeEventListener('langswap', onLangSwap);
            if (panel.parentNode) panel.parentNode.removeChild(panel);
            if (btn.__emojiPicker) delete btn.__emojiPicker;
        }
        relabel();
        return {
            open: open, close: close, panel: panel, place: place, destroy: destroy,
            // A sentence in the line under the grid (1.70.0: an editor says there why a pick did not fit).
            say: function (text) { status(text); },
            // For the browser check: what it holds and a way to drive what needs a finger or a long press.
            // ready(): what the last opening asked for is here — the emoji and (1.70.0 G) the emote list.
            ready: function () { return Promise.all([loading || null, emotesDone]); },
            state: function () {
                return { page: current, lang: lang, mode: faMode, unicode: !!uni, fa: !!fa, faces: fa ? fa.faces.length : 0,
                         rows: uni ? uni.rows.length : 0, cells: gridEl.children.length, items: items.length, tone: toneNow(),
                         recent: recentGet(), variants: varEl.hidden ? null : Array.prototype.map.call(varEl.children, function (b) { return b.title; }),
                         pages: pages.map(function (p) { return p.id; }),
                         // 1.70.0: the scope, the catalogue (asked for / here, how many icons), the chips and the one chosen.
                         scope: fa ? fa.scope : 'off', catAsked: !!catAsked, catalog: cat ? cat.icons.length : 0,
                         cats: catsEl.hidden ? [] : Array.prototype.map.call(catsEl.children, function (b) { return b.dataset.c; }),
                         cat: catsEl.hidden ? '' : ((catNow() || {}).id || ''),
                         // 1.70.0: whose picker this is, whether it floats, what it offers of the emotes.
                         for: ctx, id: pid, floating: floating, open: !panel.hidden,
                         emotes: emotePlain.length, stickers: emoteStick.length };
            },
            show: show,
            search: function (q) { searchIn.value = q; clearBtn.hidden = q === ''; runSearch(); },
            variants: function (k) { var c = gridEl.querySelector('.shout-picker-cell[data-k="' + Number(k) + '"]'); if (c) openVariants(c); return !varEl.hidden; },
            category: function (id) { chooseCat(id); },
            catalog: function () { return catalogLoad().then(function (c) { return c ? c.icons.length : 0; }); },
        };
    }

    /* ─────────────────────────── the picker on a textarea (1.70.0) ─────────────────────────── */

    /** The pickers an editor attached, by the id of their panel: an editor drawn again replaces its own. */
    var attached = {};

    /**
     * Is this text a token — an emote's or a sticker's `:code:`, a Font Awesome icon's `:fa-NAME:`? A token
     * glued to a word is not a token any more, so it gets the spaces it needs round it; a character needs
     * none. The room's insert() and every editor's use this one rule.
     */
    function isToken(text) {
        return /^:[a-z0-9_]+:$/.test(text) || FA_TOKEN.test(text);
    }

    /**
     * Put a picked text at the caret of a textarea, the way the room's insert() does: a token spaced from
     * the words round it, nothing past the box's own maxlength (the limit the server judges it by, and the
     * picker says so instead of cutting the text), the caret after what went in, and an `input` event —
     * the editor's counter, its preview and anything else that listens to typing hang off that, so they
     * follow a pick exactly as they follow a keystroke.
     */
    function insertInto(ta, text, handle) {
        if (!ta || ta.disabled || ta.readOnly) return false;
        var s = ta.selectionStart, e = ta.selectionEnd;
        if (typeof s !== 'number' || typeof e !== 'number') { s = e = ta.value.length; }
        var before = ta.value.slice(0, s), after = ta.value.slice(e);
        var pad = isToken(text);
        var lead = pad && before !== '' && !/\s$/.test(before) ? ' ' : '';
        var tail = pad && !/^\s/.test(after) ? ' ' : '';
        var add = lead + text + tail;
        var max = ta.maxLength > 0 ? ta.maxLength : 0;
        if (max && before.length + add.length + after.length > max) {
            if (handle) handle.say(t('js.shout.err_too_long', { limit: max }));
            return false;
        }
        ta.value = before + add + after;
        var pos = s + add.length;
        try { ta.setSelectionRange(pos, pos); } catch (err) { /* a box that will not be told */ }
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
    }

    /**
     * The picker for one textarea, its settings read from the button's data-* (or `o.data`, for an editor
     * that keeps them elsewhere): data-emoji-for (the context), data-emoji-files, data-emoji-fa,
     * data-emoji-fa-v, data-emotes, data-stickers, data-emotes-page — what includes/emoji.php's
     * emojiPickerData() decided for this reader in this context. A sticker goes in as its code (there is
     * no "send" in an editor). The panel's id is the textarea's id + `-picker`; an editor drawn again
     * (the Info panel's, a conversation's composer) takes the place of the picker it had before.
     */
    function attach(o) {
        o = o || {};
        var ta = o.textarea, btn = o.button;
        if (!ta || !btn) return null;
        if (btn.__emojiPicker) return btn.__emojiPicker;
        var d = o.data || btn.dataset || {};
        var pid = String(ta.id || 'emoji') + '-picker';
        if (attached[pid]) { attached[pid].destroy(); delete attached[pid]; }
        var files = {};
        try { files = JSON.parse(d.emojiFiles || '{}') || {}; } catch (err) { files = {}; }
        var handle = null;
        handle = mountPicker({
            button: btn,
            id: pid,
            for: String(d.emojiFor || 'description'),
            insert: function (text) { return insertInto(ta, text, handle); },
            sticker: null,
            emotes: d.emotes === '1',
            stickers: d.stickers === '1',
            emotesPage: d.emotesPage === '1',
            files: files,
            fa: d.emojiFa || 'off',
            faVer: d.emojiFaV || '',
        });
        if (!handle) return null;
        btn.__emojiPicker = handle;
        attached[pid] = handle;
        return handle;
    }

    window.EmojiPicker = {
        mount: mountPicker,
        attach: attach,
        isToken: isToken,
        forgetEmotes: function () { emotesPromises = {}; },
        // For the browser checks: the pickers the editors on this page attached, by panel id.
        attached: function () { return attached; },
    };
})();
