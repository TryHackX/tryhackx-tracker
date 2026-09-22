/**
 * The shoutbox, keeping up with itself — and the picker people say it with.
 *
 * One script for both places the box appears — the block on the front page and ?action=shoutbox —
 * because they are the same markup with a different row count, and two scripts for one set of ids
 * would be two answers to every question about them. ?action=emotes rides along at the bottom of
 * this file for the same reason: it is the other half of one feature, and the browser already has
 * this file on every page the shoutbox is switched on for.
 *
 * ── it never redraws ───────────────────────────────────────────────────────────────────────────
 * The starting point comes WITH the first render (`data-newest` on #shoutbox), so the first tick
 * asks for rows newer than what is already on the screen rather than for the list again. New rows
 * are appended; nothing that is already there is touched. That is not a refinement — it is the
 * whole reason somebody can be halfway through a sentence when a shout lands and still have their
 * sentence. Dedup is by the ids in the DOM, which is the only copy that matters after a retry, a
 * slow connection or a send that raced its own poll.
 *
 * ── it asks nothing when nobody is looking ─────────────────────────────────────────────────────
 * A background tab (`document.hidden`), a box inside a hidden ancestor (`offsetParent === null`,
 * which is how the account page's tabs hide a pane) and a flight already in the air all mean the
 * same thing: skip this tick. The counter on the badge is somebody else's job and comes from the
 * pulse; this only fetches text for a box that is on the screen.
 *
 * ── the emoji are characters, the emotes are images ────────────────────────────────────────────
 * The four emoji pages are a fixed list of Unicode characters written into this file, drawn by the
 * font the device already has — Segoe UI Emoji on Windows, Noto on Android, Apple's on iOS. No
 * image pack is shipped and none is downloaded, which is why the list is here rather than in the
 * database: it never changes and it costs nothing. What the OPERATOR adds — the `:code:` emotes and
 * the stickers — is a short list from `shout_emotes`, asked for once per page, and what lands in
 * the box is the token, never the image: the server decides what a shout renders as.
 *
 * Row bodies arrive as HTML the SERVER rendered, through the same richtextRender() every
 * description and message goes through — <img class="shout-emote"> and <img class="shout-sticker">
 * included, and from 1.62.0 every picture wrapped in a link to itself, which is what the lightbox
 * below opens. There is exactly one place in this codebase that decides what may be displayed, and
 * it is not here. The same goes for the time on a row: the server sends it in the reader's zone.
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

    function csrf() {
        var i = document.getElementById('shout-csrf')
             || document.getElementById('account-csrf')
             || document.getElementById('search-csrf')
             || document.querySelector('input[name="csrf_token"]');
        return i ? i.value : '';
    }
    async function get(qs) {
        try {
            var r = await fetch(API + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return await r.json();
        } catch (e) { return null; }
    }
    async function post(endpoint, body) {
        try {
            body = body || {};
            body.csrf_token = csrf();
            var r = await fetch(API + endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify(body),
            });
            return await r.json();
        } catch (e) { return null; }
    }

    /**
     * What went wrong, in this language.
     *
     * A code the endpoint documents becomes a sentence from the dictionary. Anything else is either
     * a sentence the server already translated (the CSRF answer is one) or nothing we can name —
     * and "something went wrong" is a better answer than an error code nobody can act on.
     */
    var CODES = { flood: 1, too_long: 1, muted: 1, empty: 1, invalid_body: 1, disabled: 1,
                  no_permission: 1, login_required: 1, not_found: 1, rate_limit: 1,
                  too_large: 1, bad_code: 1 };
    function errText(r, maxChars) {
        var code = (r && r.error) || 'failed';
        if (typeof code !== 'string') code = 'failed';
        if (code === 'flood') return t('js.shout.err_flood', { seconds: Number(r.retry_after || 0) });
        if (code === 'too_long') return t('js.shout.err_too_long', { limit: Number(r.limit || maxChars || 0) });
        if (code === 'muted') return t('js.shout.err_muted', { until: String(r.until || '') });
        if (code === 'too_large') return t('js.shout.err_too_large', { kb: Number(r.limit || r.kb || 0) });
        if (CODES[code]) return t('js.shout.err_' + code);
        // Already a sentence rather than a code — show it rather than swallowing it.
        if (/\s/.test(code)) return code;
        return t('js.shout.err_failed');
    }

    /**
     * The upload's refusals come from the server with the sentence already written — in this
     * reader's language, naming the limit it was judged against. There are eleven of them and they
     * all know something this side does not (which code was taken, what the file turned out to be),
     * so the message wins wherever there is one and the dictionary is the fallback.
     */
    function emoteErr(r) {
        var m = r && typeof r.message === 'string' ? r.message.trim() : '';
        return m || errText(r);
    }

    /**
     * The site's own little tooltip, where the page has it.
     *
     * assets/js/app.js exports pubTip() and is loaded before this file on every public page, so the
     * fallback is for the one case that is left: a page carrying the shoutbox and not app.js. It
     * hands the sentence to the caller's own status line rather than inventing a second tooltip.
     */
    function tip(target, text, fallback) {
        if (typeof window.pubTip === 'function') { window.pubTip(target, text); return; }
        if (typeof fallback === 'function') fallback(text);
    }

    /**
     * "Are you sure?", asked in the place the button was.
     *
     * The same shape for a shout and for an uploaded emote — a question with a yes and a no, in the
     * row it is about. window.confirm() would ask from outside the page, about a row it cannot show.
     */
    /**
     * "Are you sure?", asked in the place the button was.
     *
     * Written here in 1.58.0 and MOVED to assets/js/app.js in 1.64.0, when the inbox wanted the
     * same question: three copies of a dialog would be three answers to "how does this site ask".
     * app.js is on every public page this file is on (templates/layout.php loads it first and
     * without a condition), and the whole of what it does — the row's other controls hidden while
     * it is open, the five-second life, Esc, a press outside, standing down when the language
     * starts changing — is written over there, once.
     */
    var askInPlace = window.askInPlace;

    /* ─────────────────────────── the emoji, and the images beside them ─────────────────────────── */

    /**
     * Four pages of characters, split on the space between them.
     *
     * Split on spaces rather than by character, because half of these are more than one code point:
     * the variation selector that turns ✌ into the emoji ✌️ is its own character and belongs to the
     * one before it. Array.from() would tear those in half and put a lone modifier in the grid.
     */
    var EMOJI = [
        { id: 'smileys', tab: '😀', chars:
            '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😋 😛 😜 '
          + '🤪 🤗 🤭 🤔 🤨 😐 😑 😶 😏 😒 🙄 😬 😮 😯 😴 😪 😌 😔 🤤 😷 '
          + '🤒 🤢 🤮 🥵 🥶 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 🙁 😲 🥺 😢 😭 😱 😤 '
          + '😡 🤬 😈 💀 💩 🤡 👻 👽 🤖' },
        { id: 'gestures', tab: '👍', chars:
            '👋 🤚 ✋ 🖖 👌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ 👍 👎 ✊ 👊 '
          + '🤛 🤜 👏 🙌 👐 🤝 🙏 💪 👀 🧠 🤷 🤦' },
        { id: 'hearts', tab: '❤️', chars:
            '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 💕 💞 💖 💘 💝 💯 🔥 ✨ 🌟 ⭐ 💫 '
          + '⚡ 💥 💦 💨 💤 🎵 🎶 ✅ ❌ ❗ ❓ ⚠️' },
        { id: 'objects', tab: '🎉', chars:
            '🎉 🎊 🎁 🎂 🍕 🍔 🍿 🍎 🍓 🍺 🍻 🍷 ☕ 🌍 🌙 ☀️ 🌈 🌊 🌵 🌲 '
          + '🍀 🌸 🐶 🐱 🐻 🦊 🐸 🐵 🦄 🐝 🐢 🐙 💻 📱 🎮 🚀' },
    ];

    var emotesPromise = null;
    /**
     * The uploaded images, asked for ONCE per page and shared by the picker and the emotes page.
     *
     * A failure is an empty list rather than an error: the emoji above do not depend on the server,
     * and a picker that refuses to open because one request did not come back is a picker that
     * punishes the reader for the operator's bad afternoon.
     */
    function emotesLoad() {
        if (emotesPromise) return emotesPromise;
        emotesPromise = get('shout_emotes').then(function (j) {
            var rows = (j && (j.emotes || j.rows || j.list)) || [];
            if (!Array.isArray(rows)) rows = [];
            return rows.filter(function (r) { return r && r.code && r.url; });
        });
        return emotesPromise;
    }

    /** One image in a grid, at the size the grid wants rather than the size it was uploaded at. */
    function emoteImg(row, cls) {
        return el('img', { className: cls, src: row.url, alt: ':' + row.code + ':',
                           title: row.name || row.code, loading: 'lazy' });
    }

    /**
     * The picker: a popover over the composer with four pages of characters and, when the tracker
     * has any, the emotes and the stickers.
     *
     * What it puts in the box is always TEXT — the character itself, or `:code:` for an emote —
     * because the shout that travels is text and the images are the server's rendering of it.
     * Somebody who types `:fire:` by hand gets exactly what this button gives them.
     *
     * opts: { button, host, insert(text), sticker(code)|null, emotes:bool, stickers:bool }
     */
    function mountPicker(opts) {
        var btn = opts.button, host = opts.host;
        if (!btn || !host) return null;

        var panel = el('div', { className: 'shout-picker', id: 'shout-picker', hidden: true,
                                role: 'dialog', 'aria-label': t('js.shout.emoji') });
        // Where it opens is worked out from the BUTTON each time it opens (1.62.0) — see place().
        // The stylesheet only says it is absolute inside the field's wrapper.
        var tabsEl = el('div', { className: 'shout-picker-tabs', role: 'tablist' });
        var gridEl = el('div', { className: 'shout-picker-grid', id: 'shout-picker-grid' });
        panel.appendChild(tabsEl);
        panel.appendChild(gridEl);
        // The way to the page that lists the codes — and only where there is such a page: with
        // emotes switched off ?action=emotes answers "no such thing", and a link into that is worse
        // than no link.
        if (opts.emotes) {
            panel.appendChild(el('div', { className: 'shout-picker-foot' }, [
                el('a', { className: 'shout-picker-all', href: BASE + '?action=emotes', text: t('js.shout.all_emotes') }),
            ]));
        }
        host.appendChild(panel);

        var pages = [];                     // {id, label, tab, fill(grid)}
        var current = '';

        function tabFor(page) {
            var b = el('button', { type: 'button', className: 'shout-picker-tab', role: 'tab',
                                   title: page.label, 'aria-label': page.label, dataset: { g: page.id } });
            if (page.img) b.appendChild(emoteImg(page.img, 'shout-emote'));
            else b.appendChild(document.createTextNode(page.tab));
            b.addEventListener('click', function () { show(page.id); });
            return b;
        }

        function show(id) {
            var page = pages.filter(function (p) { return p.id === id; })[0];
            if (!page) return;
            current = id;
            Array.prototype.forEach.call(tabsEl.children, function (b) {
                var on = b.dataset.g === id;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            gridEl.textContent = '';
            gridEl.className = 'shout-picker-grid' + (page.wide ? ' shout-picker-grid-wide' : '');
            page.fill(gridEl);
        }

        function cell(content, title, onPick) {
            var b = el('button', { type: 'button', className: 'shout-picker-cell', title: title, 'aria-label': title });
            b.appendChild(typeof content === 'string' ? document.createTextNode(content) : content);
            b.addEventListener('click', function () { onPick(); });
            return b;
        }

        EMOJI.forEach(function (g) {
            pages.push({
                id: g.id, tab: g.tab, label: t('js.shout.tab_' + g.id),
                fill: function (grid) {
                    g.chars.split(' ').forEach(function (ch) {
                        if (!ch) return;
                        grid.appendChild(cell(ch, ch, function () { opts.insert(ch); }));
                    });
                },
            });
        });
        pages.forEach(function (p) { tabsEl.appendChild(tabFor(p)); });

        /**
         * The images arrive after the panel does, and that is on purpose: the emoji are here the
         * moment the button is pressed, and the two image tabs appear beside them when the list
         * comes back. Nothing is asked for at all when the operator has the feature switched off.
         */
        if (opts.emotes) {
            emotesLoad().then(function (rows) {
                var plain = rows.filter(function (r) { return !r.sticker; });
                var stick = opts.stickers && opts.sticker ? rows.filter(function (r) { return !!r.sticker; }) : [];
                if (plain.length) {
                    pages.push({
                        id: 'emotes', tab: ':)', label: t('js.shout.tab_emotes'), img: plain[0],
                        fill: function (grid) {
                            plain.forEach(function (r) {
                                grid.appendChild(cell(emoteImg(r, 'shout-emote'), ':' + r.code + ':',
                                    function () { opts.insert(':' + r.code + ':'); }));
                            });
                        },
                    });
                    tabsEl.appendChild(tabFor(pages[pages.length - 1]));
                }
                if (stick.length) {
                    pages.push({
                        id: 'stickers', tab: '⭐', label: t('js.shout.tab_stickers'), img: stick[0], wide: true,
                        fill: function (grid) {
                            stick.forEach(function (r) {
                                grid.appendChild(cell(emoteImg(r, 'shout-picker-sticker'), ':' + r.code + ':',
                                    function () { close(); opts.sticker(r.code); }));
                            });
                            grid.appendChild(el('p', { className: 'shout-picker-note', text: t('js.shout.sticker_hint') }));
                        },
                    });
                    tabsEl.appendChild(tabFor(pages[pages.length - 1]));
                }
                // Two more tabs can change how tall the panel is, and it may already be open.
                onMove();
            });
        }

        function cells() { return Array.prototype.slice.call(gridEl.querySelectorAll('.shout-picker-cell')); }

        /** Arrows walk the grid; the row width is measured rather than assumed, because it wraps. */
        function move(from, dx, dy) {
            var all = cells();
            var i = all.indexOf(from);
            if (i < 0) return;
            var per = 1;
            for (var k = 1; k < all.length; k++) { if (all[k].offsetTop !== all[0].offsetTop) { per = k; break; } }
            var j = i + dx + dy * per;
            if (j < 0) j = 0;
            if (j >= all.length) j = all.length - 1;
            all[j].focus();
        }

        function onKey(e) {
            if (panel.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); close(); btn.focus(); return; }
            if (!panel.contains(e.target)) return;
            var d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
            if (!d || !e.target.classList.contains('shout-picker-cell')) return;
            e.preventDefault();
            move(e.target, d[0], d[1]);
        }
        function onOutside(e) {
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
         */
        var EDGE = 8, GAP = 6;
        function place() {
            if (panel.hidden) return;
            panel.style.left = '0px'; panel.style.top = '0px'; panel.style.width = '';
            var vw = document.documentElement.clientWidth || window.innerWidth;
            var vh = document.documentElement.clientHeight || window.innerHeight;
            var pw = panel.offsetWidth;
            if (pw > vw - 2 * EDGE) { pw = vw - 2 * EDGE; panel.style.width = pw + 'px'; }
            var ph = panel.offsetHeight;
            var b = btn.getBoundingClientRect();
            var h = host.getBoundingClientRect();
            var left = Math.max(EDGE, Math.min(b.right - pw, vw - EDGE - pw));
            var roomAbove = b.top - GAP - EDGE, roomBelow = vh - b.bottom - GAP - EDGE;
            var above = ph <= roomAbove || roomAbove >= roomBelow;
            var top = above ? b.top - GAP - ph : b.bottom + GAP;
            // Neither side has room for all of it: keep the top inside the window, so the tabs
            // and the first row are what is visible and the grid scrolls inside itself.
            if (top < EDGE) top = EDGE;
            panel.style.left = Math.round(left - h.left - host.clientLeft) + 'px';
            panel.style.top = Math.round(top - h.top - host.clientTop) + 'px';
            panel.classList.toggle('shout-picker-below', !above);
        }
        function onMove() { if (!panel.hidden) requestAnimationFrame(place); }

        function open() {
            if (!current) show(pages[0].id);
            panel.hidden = false;
            place();
            btn.setAttribute('aria-expanded', 'true');
            document.addEventListener('click', onOutside, true);
            document.addEventListener('keydown', onKey, true);
            window.addEventListener('resize', onMove);
            var first = gridEl.querySelector('.shout-picker-cell');
            if (first) first.focus({ preventScroll: true });
        }
        function close() {
            if (panel.hidden) return;
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', onOutside, true);
            document.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', onMove);
        }
        btn.addEventListener('click', function () { panel.hidden ? open() : close(); });
        return { open: open, close: close, panel: panel, place: place };
    }

    /* ─────────────────────────── a picture, full size ─────────────────────────── */

    /**
     * The lightbox a picture in a shout opens into (1.62.0).
     *
     * The picture fitted to the window on a dark backdrop, a close button, and a link to the
     * original. Esc, the button and a click on the backdrop all close it; the focus goes into it
     * when it opens and back to the picture that opened it when it closes, and Tab stays inside it
     * while it is open — a dialog the keyboard can wander out of is a dialog still covering the page
     * behind the person who wandered.
     *
     * It is opened only by the unmodified primary click on a picture's link (see mountShoutbox).
     * The address is the link's own href, which the server built out of the URL its renderer had
     * already validated (shoutLinkImages() in includes/shout.php); nothing here parses, decodes or
     * rebuilds it — it is handed on as it came.
     *
     * One element for the whole page, built the first time it is needed and hidden afterwards
     * rather than thrown away. It sits on <body>, outside the box: inside it, the list's scroll and
     * the block's own edges would be the frame of a picture meant to fill the window.
     */
    var lb = null;
    function lightboxBuild() {
        var img = el('img', { className: 'shout-lb-img', alt: '', referrerpolicy: 'no-referrer' });
        // Bootstrap Icons, the font every page that draws the room already carries (templates/
        // layout.php), and the words beside or behind them for whoever cannot see a glyph.
        var orig = el('a', { className: 'shout-lb-orig', target: '_blank', rel: 'noopener noreferrer' }, [
            el('i', { className: 'bi bi-box-arrow-up-right', 'aria-hidden': 'true' }),
            ' ' + t('js.shout.lb_original'),
        ]);
        var shut = el('button', { type: 'button', className: 'shout-lb-close', title: t('js.shout.lb_close'),
                                  'aria-label': t('js.shout.lb_close') },
                      el('i', { className: 'bi bi-x-lg', 'aria-hidden': 'true' }));
        // 1.64.0: the two controls are INSIDE the picture, not on a bar under it — the close as an
        // icon in the top right corner, the link to the original centred along the bottom edge,
        // both on a dark pill and both invisible until the picture is hovered or something inside
        // it has the focus. A bar under the picture is a strip of furniture in the way of the one
        // thing the window is for, and it took 3.5rem off the height the picture could use. Where
        // there is no hover to have — a phone — the stylesheet leaves them on.
        var root = el('div', { className: 'shout-lightbox', hidden: true, role: 'dialog', 'aria-modal': 'true',
                               'aria-label': t('js.shout.lb_label') }, [
            el('div', { className: 'shout-lb-frame' }, [img, shut, orig]),
        ]);
        var from = null;              // the link that opened it: the focus goes back there
        function close() {
            if (root.hidden) return;
            root.hidden = true;
            img.removeAttribute('src');
            document.removeEventListener('keydown', onKey, true);
            var back = from;
            from = null;
            if (back && back.isConnected) back.focus({ preventScroll: true });
        }
        function onKey(e) {
            if (root.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
            if (e.key !== 'Tab') return;
            // Two stops, and the keyboard goes round them rather than out of the dialog.
            var stops = [orig, shut];
            var i = stops.indexOf(document.activeElement);
            e.preventDefault();
            stops[(i + (e.shiftKey ? stops.length - 1 : 1) + stops.length) % stops.length].focus();
        }
        // The backdrop is everything that is not the picture or the controls over it — and it
        // closes only when the press STARTED there (1.64.0). A `click` goes to the common ancestor
        // of the press and the release, so somebody who presses on the picture and lets go beside
        // it was shutting the lightbox they were looking at. This file also runs on pages that do
        // not carry app.js, so it keeps its own copy of the two lines rather than that one's.
        var lbFromBackdrop = false;
        var onBackdrop = function (n) { return n === root || (n.classList && n.classList.contains('shout-lb-frame')); };
        root.addEventListener('pointerdown', function (e) { lbFromBackdrop = onBackdrop(e.target); });
        root.addEventListener('click', function (e) {
            if (onBackdrop(e.target) && lbFromBackdrop) close();
        });
        shut.addEventListener('click', close);
        document.body.appendChild(root);
        return {
            root: root,
            open: function (link) {
                var href = link.getAttribute('href') || '';
                if (!href) return false;
                var small = link.querySelector('img');
                from = link;
                img.alt = small ? (small.getAttribute('alt') || '') : '';
                img.src = href;
                orig.href = href;
                root.hidden = false;
                document.addEventListener('keydown', onKey, true);
                shut.focus({ preventScroll: true });
                return true;
            },
            close: close,
            isOpen: function () { return !root.hidden; },
        };
    }
    function lightbox() { return lb || (lb = lightboxBuild()); }

    /* ─────────────────────────── the box itself ─────────────────────────── */

    function mountShoutbox() {
        var box = document.getElementById('shoutbox');
        if (!box) return;

        var listEl = document.getElementById('shout-list');
        var olderBtn = document.getElementById('shout-older');
        var ta = document.getElementById('shout-body');
        var sendBtn = document.getElementById('shout-send');
        var countEl = document.getElementById('shout-count');
        var noteEl = document.getElementById('shout-note');
        var fmtEl = document.getElementById('shout-body-format');
        var emojiBtn = document.getElementById('shout-emoji');
        var refreshBtn = document.getElementById('shout-refresh');
        var pinnedEl = document.getElementById('shout-pinned');
        if (!listEl) return;

        // Which end the newest line is at (1.64.0). The template has already drawn the room this
        // way round and moved the composer and "Older" with it; what is left for this side is where
        // a line that lands goes, and which way "keep the reader's place" points.
        var newestTop = box.dataset.order === 'top';
        var newest = Number(box.dataset.newest || 0);
        var oldest = Number(box.dataset.oldest || 0);
        var live = Number(box.dataset.live || 0);
        var maxChars = Number(box.dataset.max || 500) || 500;
        var format = box.dataset.format || 'bbcode';
        var meId = Number(box.dataset.me || 0);
        var mayModerate = box.dataset.mayModerate === '1';
        var seen = 0;                 // the highest id this reader has been told about
        var polling = false;
        var timer = 0;

        /** Is this box on the screen of a tab somebody is looking at? */
        function visible() { return !document.hidden && box.offsetParent !== null; }

        function note(text) { if (noteEl) noteEl.textContent = text || ''; }

        /**
         * The name a line is signed with — a link to the profile, or plain text when nobody wrote
         * it. A line the SITE said has no profile to open, and neither has a row whose account has
         * since gone; `user_id` 0 is how the server says so, for both.
         *
         * `title` because the column is a fixed width: a name longer than it is cut with an
         * ellipsis so it cannot push the words out of line, and a cut name with no way to read the
         * whole of it is a worse trade than the ragged column it replaced.
         *
         * The picture (1.63.0) is the first thing inside it, from the row's `avatar` — the address
         * the server built — through window.userAvatarImg() (assets/js/avatar.js), which draws the
         * element userAvatarHtml() draws in the template. Null while pictures are off: the name alone.
         */
        function who(r) {
            var name = String(r.user || '');
            var pic = typeof window.userAvatarImg === 'function'
                ? window.userAvatarImg({ username: name, avatar: String(r.avatar || '') }, 20, 'avatar shout-av') : null;
            var kids = pic ? [pic, name] : [name];
            return (Number(r.user_id) || 0) > 0
                ? el('a', { className: 'shout-who', title: name, href: BASE + '?action=u&name=' + encodeURIComponent(name) }, kids)
                : el('span', { className: 'shout-who', title: name }, kids);
        }

        /**
         * One shout, exactly as templates/partials/shoutbox_widget.php draws it.
         *
         * The time is the SERVER's (1.62.0): `time` is the hour in this reader's zone and `at` the
         * full date with its offset. Nothing here slices a database string any more — that string
         * is in the database session's zone, which is nobody's, and slicing it showed every reader
         * PHP's hour.
         */
        function renderRow(r) {
            var name = String(r.user || '');
            var row = el('div', { className: 'shout-row' + (r.own ? ' shout-row-own' : '') + (r.mentions_me ? ' shout-row-mention' : '') + (r.system ? ' shout-row-system' : '') });
            // The same id templates/partials/shoutbox_widget.php writes, and for the same reason
            // (1.64.0): the live language switch pairs a live node with the freshly rendered one by
            // `id` first and by POSITION second, and a room with polled or "older" rows in it is
            // not the room the server would draw right now. Without this, a row was handed another
            // row's words while keeping its own data-id — and the cross beside it deletes by
            // data-id, so the line that went was not the line on the screen.
            row.id = 'shout-' + (Number(r.id) || 0);
            row.dataset.id = String(Number(r.id) || 0);
            row.dataset.user = name;
            row.appendChild(who(r));
            row.appendChild(el('span', { className: 'shout-time', title: String(r.at || ''), text: String(r.time || '') }));
            row.appendChild(el('span', { className: 'shout-body rt-body', html: r.html || '' }));
            // From the box's own permission rather than from anything per row: pinning is
            // `shout.moderate` and that answer is the same for every line on the page.
            if (mayModerate) {
                row.appendChild(el('button', { type: 'button', className: 'shout-pin',
                                               title: t('js.shout.pin_title'), 'aria-label': t('js.shout.pin'), text: '📌' }));
            }
            if (r.deletable) {
                row.appendChild(el('button', { type: 'button', className: 'shout-del',
                                               title: t('js.shout.delete_title'), 'aria-label': t('js.shout.delete'), text: '×' }));
            }
            return row;
        }

        /**
         * The pinned strip, redrawn from what the server just said — the same node the first render
         * filled, and the same shape, so there is one description of a pinned line rather than two.
         *
         * `null` empties it and hides it again: unpinning has to leave the room looking like a room
         * with nothing pinned, not like one with an empty box at the top.
         */
        function renderPinned(row) {
            if (!pinnedEl) return;
            pinnedEl.textContent = '';
            if (!row) { pinnedEl.hidden = true; delete pinnedEl.dataset.id; return; }
            pinnedEl.dataset.id = String(Number(row.id) || 0);
            pinnedEl.hidden = false;
            pinnedEl.appendChild(el('span', { className: 'shout-pin-icon', 'aria-hidden': 'true', text: '📌' }));
            pinnedEl.appendChild(who(row));
            pinnedEl.appendChild(el('span', { className: 'shout-body rt-body', html: row.html || '' }));
            if (mayModerate) {
                pinnedEl.appendChild(el('button', { type: 'button', className: 'shout-unpin',
                                                    title: t('js.shout.unpin_title'), 'aria-label': t('js.shout.unpin'), text: '×' }));
            }
        }

        /**
         * Pin a line, or take the pinned one down.
         *
         * The answer carries the pinned row as the server would hand it to anybody else, so the
         * strip is drawn from that rather than from whatever this browser happened to have — which
         * is also how pinning a second line makes the first one disappear from it without this
         * side having to know that rule.
         */
        async function pin(id, on) {
            if (!id) return;
            var r = await post('shout_pin', { id: id, pin: !!on });
            if (!r || !r.success) { note(errText(r, maxChars)); return; }
            note('');
            renderPinned(r.pinned || null);
        }

        /**
         * Append the rows that are not already there, and follow the list down only if the reader was
         * already at the bottom of it. Scrolling somebody away from the line they are reading is worse
         * than a row they have to scroll to.
         */
        function appendRows(rows) {
            // "Am I standing where the new lines arrive?" — the bottom of the list with the newest
            // at the bottom, the top of it with the newest at the top (1.64.0). Either way: follow
            // only if they were already there, and otherwise give back exactly the height that was
            // added so the line being read does not move under them.
            var wasHeight = listEl.scrollHeight, wasTop = listEl.scrollTop;
            var atEnd = newestTop
                ? wasTop < 40
                : wasHeight - wasTop - listEl.clientHeight < 40;
            var added = [];
            (rows || []).forEach(function (r) {
                var id = Number(r && r.id) || 0;
                if (!id || listEl.querySelector('.shout-row[data-id="' + id + '"]')) return;
                var row = renderRow(r);
                // Rows arrive newest LAST, so with the newest at the top each one goes in front of
                // the one before it and the batch keeps its order.
                if (newestTop) listEl.insertBefore(row, listEl.firstChild);
                else listEl.appendChild(row);
                if (id > newest) newest = id;
                if (!oldest || id < oldest) oldest = id;
                added.push(r);
            });
            if (added.length) {
                var empty = listEl.querySelector('.shout-empty');
                if (empty) empty.remove();
                if (atEnd) listEl.scrollTop = newestTop ? 0 : listEl.scrollHeight;
                else if (newestTop) listEl.scrollTop = wasTop + (listEl.scrollHeight - wasHeight);
            }
            return added;
        }

        /** Where the newest line is: the top of the list, or the bottom of it. */
        function toEnd() { listEl.scrollTop = newestTop ? 0 : listEl.scrollHeight; }
        function bottom() { toEnd(); }

        /**
         * "I have seen up to here."
         *
         * One column on the account (`users.shout_seen_id`), so this is the whole of what makes the
         * badge stop counting. Only while the box is actually on the screen, and never twice for the
         * same id — a marker that goes backwards or repeats is a write for nothing.
         */
        function markSeen(id) {
            if (!meId || !id || id <= seen || !visible()) return;
            seen = id;
            post('shout_seen', { id: id });
        }

        function stop() { if (timer) { clearInterval(timer); timer = 0; } }

        /**
         * Ask for what is newer than `newest`. ONE fetch, used by the tick and by the button.
         *
         * `force` is the difference between them and it is the whole point of the button: the tick
         * refuses when the feature is off, when the tab is behind another one or when the box is not
         * on the screen — which is right for something that runs by itself, and is exactly why
         * somebody coming back to the window has no way to ask. Pressed, it asks anyway. A flight
         * already in the air still wins: two of these racing would append the same rows twice.
         *
         * Returns how many rows landed, so the button can say "nothing new" and the tick can stay
         * silent about it.
         */
        async function fetchNew(force) {
            if (polling) return 0;
            if (!force && (!live || !visible())) return 0;
            polling = true;
            var j = null;
            try { j = await get('shout_list&after=' + newest); } finally { polling = false; }
            if (!j) return 0;
            if (!j.success) {
                // Switched off, signed out, or the permission taken away mid-session: stop asking. The
                // box stays on the screen with what it had, which is honest — those rows were real.
                if (j.error === 'disabled' || j.error === 'no_permission' || j.error === 'login_required') stop();
                if (force) note(errText(j, maxChars));
                return 0;
            }
            var added = appendRows(j.rows);
            if (Number(j.newest) > newest) newest = Number(j.newest);
            if (!added.length) return 0;
            markSeen(newest);
            // The hook the sound player listens on (and F3's nav counter will). The counts themselves
            // come from the pulse — this only says that rows landed while somebody was looking.
            window.dispatchEvent(new CustomEvent('shout:new', { detail: { rows: added } }));
            return added.length;
        }

        function poll() { return fetchNew(false); }

        /**
         * The button in the head: the same fetch, asked for on purpose.
         *
         * It spins while it waits and stays dead for two seconds afterwards, which is not politeness
         * to the server so much as an answer to the button itself — a control that does nothing
         * visible when there is nothing new invites being pressed again, and again.
         */
        var refreshCool = 0;
        async function refresh() {
            if (!refreshBtn || refreshBtn.disabled) return;
            refreshBtn.disabled = true;
            refreshBtn.classList.add('is-spinning');
            note('');
            var n = 0;
            try { n = await fetchNew(true); } finally { refreshBtn.classList.remove('is-spinning'); }
            // ON THE BUTTON, not on a line under the box (1.61.0). "Nothing new" is an answer to the
            // press — true for about a second and then merely a sentence sitting under the composer
            // reading like a state the room is in. pubTip() is the tooltip the rest of the site
            // already uses (assets/js/app.js) and it takes itself away after a moment.
            if (!n) tip(refreshBtn, t('js.shout.nothing_new'), note);
            clearTimeout(refreshCool);
            refreshCool = setTimeout(function () { refreshBtn.disabled = false; }, 2000);
        }

        /** Older rows, pasted in ABOVE without moving what is being read. */
        async function older() {
            if (!olderBtn || olderBtn.disabled || !oldest) return;
            olderBtn.disabled = true;
            var j = await get('shout_list&before=' + oldest);
            olderBtn.disabled = false;
            if (!j || !j.success) { note(errText(j, maxChars)); return; }
            var rows = j.rows || [];
            if (!rows.length) { olderBtn.hidden = true; note(t('js.shout.no_more')); return; }
            // Measured before the insert and restored after it: the browser keeps scrollTop where it
            // was, which means the content under it has moved down by exactly the height added.
            // Only when the older rows go in ABOVE what is on the screen — with the newest at the
            // top they go on the END of the list, where nothing above them moves and there is
            // nothing to give back (1.64.0).
            var wasHeight = listEl.scrollHeight, wasTop = listEl.scrollTop;
            var frag = document.createDocumentFragment();
            // Rows arrive newest LAST. With the newest at the top the whole batch is turned round,
            // so the row after the last one on the screen is the one that follows it.
            (newestTop ? rows.slice().reverse() : rows).forEach(function (r) {
                var id = Number(r && r.id) || 0;
                if (!id || listEl.querySelector('.shout-row[data-id="' + id + '"]')) return;
                frag.appendChild(renderRow(r));
                if (!oldest || id < oldest) oldest = id;
            });
            var empty = listEl.querySelector('.shout-empty');
            if (empty) empty.remove();
            if (newestTop) {
                listEl.appendChild(frag);
            } else {
                listEl.insertBefore(frag, listEl.firstChild);
                listEl.scrollTop = wasTop + (listEl.scrollHeight - wasHeight);
            }
            olderBtn.hidden = !j.has_more;
            // This answer carries the pinned line and the poll's does not (the poll appends, and a
            // pinned row handed to it would be appended for ever), so a strip that was pinned or
            // unpinned by somebody else since the page loaded catches up here.
            if (Object.prototype.hasOwnProperty.call(j, 'pinned')) renderPinned(j.pinned || null);
            note('');
        }

        /** Deleting a line: the question waits in the place the button was. */
        function askDelete(btn) {
            var row = btn.closest('.shout-row');
            if (!row) return;
            var id = Number(row.dataset.id) || 0;
            askInPlace(btn, t('js.shout.delete_q'), async function () {
                var r = await post('shout_delete', { id: id });
                if (r && r.success) { row.remove(); note(''); return true; }
                // A row somebody else already deleted is gone either way: take it off the screen.
                if (r && r.error === 'not_found') { row.remove(); return true; }
                note(errText(r, maxChars));
                return false;
            }, {
                // The pin button is absolutely positioned over the end of the row and shown on
                // hover — which puts it exactly on "No" (1.64.0). It goes away while the question
                // is up and comes back on every way out of it.
                host: row,
                relabel: function (b) {
                    b.title = t('js.shout.delete_title');
                    b.setAttribute('aria-label', t('js.shout.delete'));
                },
            });
        }

        /**
         * Keep the field's right padding equal to what the two controls on it actually occupy.
         *
         * MEASURED, not guessed. The pair is the picker handle and Send, and Send is a word: "Send"
         * and "Wyślij" are different widths, an operator's language pack could be wider than either,
         * and a phone's font metrics are not a desktop's. A number written into the stylesheet is
         * right in one language on one machine and wrong everywhere else — and being wrong here
         * means somebody's sentence disappearing underneath a button as they type it.
         *
         * The stylesheet still carries a sensible fallback for the frames before this runs.
         */
        function fitComposer() {
            if (!ta) return;
            var acts = box.querySelector('.shout-in-acts');
            if (!acts) return;
            var w = acts.getBoundingClientRect().width;
            // 0 while the box is in a hidden ancestor (the account page's tabs); leave the fallback.
            if (w > 0) ta.style.paddingRight = Math.ceil(w + 14) + 'px';
        }

        function countUpdate() {
            if (!countEl || !ta) return;
            countEl.textContent = t('js.shout.chars', { n: ta.value.length, max: maxChars });
        }

        /** The one road out: whatever is said, said the same way. */
        async function postShout(body, after) {
            var r = await post('shout_post', {
                body: body,
                // Whichever syntax they picked; the server validates it either way, and where the
                // tracker's format is 'plain' there is no select and nothing to pick.
                format: fmtEl && fmtEl.value ? fmtEl.value : format,
            });
            if (!r || !r.success) { note(errText(r, maxChars)); return false; }
            note('');
            if (typeof after === 'function') after();
            if (r.row) {
                // Straight from the answer, without waiting for a tick: the line somebody just wrote
                // appearing a few seconds later reads as a send that did not work.
                appendRows([r.row]);
                bottom();
                markSeen(newest);
            }
            return true;
        }

        async function send() {
            if (!ta || !sendBtn) return;
            var body = ta.value.trim();
            if (!body) { ta.focus(); return; }
            sendBtn.disabled = true;
            await postShout(body, function () { ta.value = ''; countUpdate(); });
            sendBtn.disabled = false;
        }

        /**
         * A sticker is not text somebody is writing — it IS the shout.
         *
         * So it goes as it is clicked, rather than dropping `:wave:` into a half-written sentence
         * where the server would render it small and inline. The composer is left alone: whatever
         * was being typed is still being typed.
         */
        async function sendSticker(code) {
            if (!sendBtn || sendBtn.disabled) return;
            sendBtn.disabled = true;
            await postShout(':' + code + ':');
            sendBtn.disabled = false;
        }

        /**
         * The characters land where the caret is, and the box stays exactly as usable as it was:
         * the counter and the preview both hang off `input`, so the event is dispatched rather than
         * the two of them called by hand from here.
         */
        function insert(text) {
            if (!ta) return;
            var s = ta.selectionStart, e = ta.selectionEnd;
            if (typeof s !== 'number') { s = e = ta.value.length; }
            var before = ta.value.slice(0, s), after = ta.value.slice(e);
            // `:code:` glued to a word is not a token any more, so it gets the space it needs — and
            // only the space it needs. A bare emoji character needs none.
            var pad = /^:[a-z0-9_]+:$/.test(text);
            var lead = pad && before !== '' && !/\s$/.test(before) ? ' ' : '';
            var tail = pad && !/^\s/.test(after) ? ' ' : '';
            var add = lead + text + tail;
            if (before.length + after.length + add.length > maxChars) { note(t('js.shout.err_too_long', { limit: maxChars })); return; }
            ta.value = before + add + after;
            var pos = s + add.length;
            try { ta.setSelectionRange(pos, pos); } catch (err) { /* a box that will not be told */ }
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            note('');
        }

        /* ─────────────────────────── `@` suggests names ───────────────────────────
         *
         * Typing `@ab` offers the accounts whose names start that way. The guards are the feature
         * rather than a refinement of it: `users` is the one table on a tracker that grows without
         * anybody deciding it should, and a suggestion box is a thing that fires on every keystroke.
         *
         *   · nothing at all before two characters — "@a" is not somebody looking for a name
         *   · 250 ms of quiet before a request goes, so a word typed straight through costs one
         *   · ONE flight at a time
         *   · at most eight names, which is what the endpoint returns and what this shows
         *   · every prefix already asked about is kept for the life of the page, so backspacing
         *     through a name re-asks nothing — including the empty answers, because "nobody is
         *     called that" is an answer
         *   · an answer that arrives after the caret has left the token is filed and shown to nobody
         *
         * api/shout_mentions.php enforces the floor and the limit again at its end. A guard that
         * lives only in a browser is not a guard.
         */
        var MENTION_MIN = 2;
        var MENTION_WAIT = 250;
        var mentionPop = null, mentionNames = [], mentionAt = -1, mentionQ = '', mentionSel = 0;
        var mentionTimer = 0, mentionFlight = false;
        var mentionCache = {};

        /**
         * The token the caret is sitting in, or null.
         *
         * The same shape includes/shout.php calls a mention (shoutMentionTokens): an `@` not preceded
         * by a word character — so `bob@example` is not one here either — and the name characters
         * after it up to the caret. A selection is not a caret, so it offers nothing.
         */
        function mentionToken() {
            if (!ta || typeof ta.selectionStart !== 'number' || ta.selectionStart !== ta.selectionEnd) return null;
            var m = /(?:^|[^\w@])@([A-Za-z0-9_.-]{0,32})$/.exec(ta.value.slice(0, ta.selectionStart));
            return m ? { q: m[1], at: ta.selectionStart - m[1].length } : null;
        }

        function mentionClose() {
            clearTimeout(mentionTimer);
            if (mentionPop) { mentionPop.remove(); mentionPop = null; }
            mentionNames = [];
            mentionAt = -1;
        }

        function mentionMark() {
            if (!mentionPop) return;
            Array.prototype.forEach.call(mentionPop.children, function (b, i) {
                var on = i === mentionSel;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        /** The chosen name replaces the token, and the list closes. */
        function mentionPick(name) {
            if (!ta || mentionAt < 0) return;
            var at = mentionAt, len = mentionQ.length;
            var before = ta.value.slice(0, at), after = ta.value.slice(at + len);
            // The space a name needs after it, and only when there is not one already.
            var add = String(name) + (/^\s/.test(after) ? '' : ' ');
            mentionClose();
            if (before.length + add.length + after.length > maxChars) return;
            ta.value = before + add + after;
            var pos = at + add.length;
            try { ta.setSelectionRange(pos, pos); } catch (err) { /* a box that will not be told */ }
            ta.focus();
            // The counter and the preview hang off `input`, and the caret is past a space now, so
            // this is also what closes the list for good rather than a second call to do it.
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function mentionShow(names, tok) {
            if (mentionPop) { mentionPop.remove(); mentionPop = null; }
            mentionNames = names || [];
            if (!mentionNames.length) { mentionAt = -1; return; }
            mentionAt = tok.at;
            mentionQ = tok.q;
            if (mentionSel >= mentionNames.length) mentionSel = 0;
            mentionPop = el('div', { className: 'shout-mention-pop', id: 'shout-mention-pop',
                                     role: 'listbox', 'aria-label': t('js.shout.mention_list') });
            mentionNames.forEach(function (n) {
                var b = el('button', { type: 'button', className: 'shout-mention-item', role: 'option', text: n });
                // mousedown rather than click: the textarea losing focus is what closes this list,
                // and a click would arrive after the thing it was aimed at had already gone.
                b.addEventListener('mousedown', function (ev) { ev.preventDefault(); mentionPick(n); });
                mentionPop.appendChild(b);
            });
            (ta.closest('.shout-input-wrap') || ta.parentNode).appendChild(mentionPop);
            mentionMark();
        }

        async function mentionFetch(tok) {
            var key = tok.q.toLowerCase();
            if (Object.prototype.hasOwnProperty.call(mentionCache, key)) { mentionShow(mentionCache[key], tok); return; }
            if (mentionFlight) return;
            mentionFlight = true;
            var j = null;
            try { j = await get('shout_mentions&q=' + encodeURIComponent(tok.q)); } finally { mentionFlight = false; }
            var names = (j && j.success && Array.isArray(j.names)) ? j.names.slice(0, 8) : [];
            mentionCache[key] = names;
            var now = mentionToken();
            if (!now || now.q !== tok.q || now.at !== tok.at) return;   // the caret moved on
            mentionShow(names, tok);
        }

        function mentionInput() {
            clearTimeout(mentionTimer);
            var tok = mentionToken();
            if (!tok || tok.q.length < MENTION_MIN) { mentionClose(); return; }
            mentionSel = 0;
            mentionTimer = setTimeout(function () { mentionFetch(tok); }, MENTION_WAIT);
        }

        /** The keys the list owns while it is open. Returns true when it has dealt with one. */
        function mentionKey(e) {
            if (!mentionPop || !mentionNames.length) return false;
            if (e.key === 'Escape') { e.preventDefault(); mentionClose(); return true; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                mentionSel = (mentionSel + (e.key === 'ArrowDown' ? 1 : mentionNames.length - 1)) % mentionNames.length;
                mentionMark();
                return true;
            }
            // Enter picks a name here instead of sending: somebody halfway through writing somebody
            // else's name has not finished their sentence.
            if ((e.key === 'Enter' || e.key === 'Tab') && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey && !e.isComposing) {
                e.preventDefault();
                mentionPick(mentionNames[mentionSel]);
                return true;
            }
            return false;
        }

        /* ─────────────────────────── wiring ─────────────────────────── */

        // Delegated, so a row drawn by the server and a row drawn above by this script behave the same.
        listEl.addEventListener('click', function (e) {
            if (!e.target.closest) return;
            var del = e.target.closest('.shout-del');
            if (del && listEl.contains(del)) { askDelete(del); return; }
            // Pinning asks nothing first: it is one line moving to the top of a room, and the strip
            // it lands in has an unpin beside it. Deleting is the one that cannot be taken back.
            var p = e.target.closest('.shout-pin');
            if (p && listEl.contains(p)) {
                var row = p.closest('.shout-row');
                if (row) pin(Number(row.dataset.id) || 0, true);
            }
        });
        if (pinnedEl) {
            pinnedEl.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.shout-unpin') : null;
                if (b) pin(Number(pinnedEl.dataset.id) || 0, false);
            });
        }
        // A picture opens in the lightbox on a PLAIN primary click, and on nothing else (1.62.0).
        // The picture is a real link (target=_blank), so Ctrl/Cmd+click, Shift+click, a middle click
        // (which never raises `click` at all) and the context menu are left entirely to the browser —
        // they open the original in a tab, the way a link does everywhere else, and none of that had
        // to be written here. On the whole box, delegated, so a line in the pinned strip and a line
        // the poll has just appended behave like the lines the server drew.
        box.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a.shout-img-link') : null;
            if (!a || !box.contains(a)) return;
            if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            lightbox().open(a);
        });
        if (olderBtn) olderBtn.addEventListener('click', older);
        if (refreshBtn) refreshBtn.addEventListener('click', refresh);
        if (sendBtn) sendBtn.addEventListener('click', send);
        if (ta) {
            ta.addEventListener('input', countUpdate);
            ta.addEventListener('input', mentionInput);
            // A click elsewhere takes the focus and the list with it. Deferred, because the pick
            // itself runs on mousedown and would otherwise be cancelled by its own blur.
            ta.addEventListener('blur', function () { setTimeout(mentionClose, 150); });
            ta.addEventListener('keydown', function (e) {
                // While the `@` list is open it owns the arrows, Enter, Tab and Escape.
                if (mentionKey(e)) return;
                // Enter sends, Shift+Enter starts a line. A shoutbox is a conversation, and reaching for
                // a button after every sentence is what makes one feel like a form.
                if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey || e.isComposing) return;
                e.preventDefault();
                send();
            });
            countUpdate();
            fitComposer();
            // The width of "Send" changes with the language, and the language can change without a
            // reload (assets/js/lang-swap.js dispatches `langswap` on the document once it has
            // rewritten the page); the width of the box changes with the window.
            window.addEventListener('resize', fitComposer);
            document.addEventListener('langswap', fitComposer);
            // The write/preview tabs, the syntax help and the counter under them, from the editor the
            // rest of the site writes in. Never in 'plain': there is no syntax to preview.
            if (format !== 'plain' && window.RichText && typeof window.RichText.mount === 'function') {
                window.RichText.mount('shout-body', { previewFor: 'shout' });
            }
        }
        var picker = null;
        if (emojiBtn && ta) {
            picker = mountPicker({
                button: emojiBtn,
                // The box the button now sits in (1.61.0), not the whole composer: anchored to the
                // composer the panel opened from the TOP of it and covered the heading above the box.
                host: emojiBtn.closest('.shout-input-wrap') || emojiBtn.closest('.shout-acts')
                      || emojiBtn.closest('.shout-compose') || box,
                insert: insert,
                sticker: sendSticker,
                emotes: box.dataset.emotes === '1',
                stickers: box.dataset.stickers === '1',
            });
        }

        /**
         * Start the reader at the end the newest line is at (1.64.0).
         *
         * With the newest at the TOP that is where a list starts anyway, so this is about the other
         * arm: chat order puts the newest line at the bottom of a box nothing ever scrolled, and
         * the reader opened the room looking at its oldest lines. One line of scroll fixes it —
         * except that a box inside a hidden ancestor has height 0, and scrolling something with no
         * height does nothing at all. That is every account-page tab that is not the open one, and
         * a background tab restored on startup. So it is tried once now, and again the first time
         * the box actually has a height.
         */
        toEnd();
        if (listEl.clientHeight <= 0) {
            if (window.ResizeObserver) {
                // A pane that is unhidden takes the list from a height of nothing to a real one,
                // which is a resize — so this fires exactly once, when there is finally something
                // to scroll.
                var firstPaint = new ResizeObserver(function () {
                    if (listEl.clientHeight <= 0) return;
                    firstPaint.disconnect();
                    toEnd();
                });
                firstPaint.observe(listEl);
            }
            // A tab restored in the background is laid out but never painted; this is the moment
            // somebody looks at it.
            var onShown = function () { if (listEl.clientHeight > 0) { toEnd(); document.removeEventListener('visibilitychange', onShown); } };
            document.addEventListener('visibilitychange', onShown);
        }

        // Everything already on the screen has been seen by whoever is looking at it.
        markSeen(newest);
        if (live > 0) timer = setInterval(poll, live * 1000);
        // One tick on demand, for the browser checks and for anything that wants to catch up now.
        window.ShoutTick = poll;
        window.Shout = { tick: poll, refresh: refresh, older: older, pin: pin,
                         newest: function () { return newest; }, insert: insert, fit: fitComposer,
                         // Which end the newest line is at, and a way to go there (1.64.0).
                         order: function () { return newestTop ? 'top' : 'bottom'; }, toEnd: toEnd,
                         // The lightbox, for the browser check: open or not, and a way to shut it.
                         lightbox: { open: function () { return !!lb && lb.isOpen(); },
                                     close: function () { if (lb) lb.close(); } },
                         picker: picker,
                         // The `@` list, for the browser check: whether it is open, what is in it,
                         // and the two steps that would otherwise need a real keyboard.
                         mention: { token: mentionToken, fetch: mentionFetch, pick: mentionPick,
                                    close: mentionClose, names: function () { return mentionNames.slice(); },
                                    open: function () { return !!mentionPop; },
                                    asked: function () { return Object.keys(mentionCache); } } };
    }

    /* ─────────────────────────── ?action=emotes ─────────────────────────── */

    /**
     * The emotes page: what everybody may write, and — for the few who may — one more.
     *
     * The LISTS are drawn by the server: they are public, they are the same for every reader, and a
     * page that paints itself from an endpoint is a page a reader with a slow line sees empty.
     * This adds only the two things that cannot be server-rendered — the file somebody is about to
     * upload and the row they are about to take away.
     */
    function mountEmotesPage() {
        var root = document.getElementById('emotes-page');
        if (!root) return;
        var noteEl = document.getElementById('emote-note');
        function note(text, bad) {
            if (!noteEl) return;
            noteEl.textContent = text || '';
            noteEl.classList.toggle('emote-note-bad', !!bad);
        }

        /* ── the code, into the clipboard ──
           The whole reason somebody opens this page is to get a `:code:` into a shout, and selecting
           eight characters by hand on a phone is not a way to do that. The same swap-the-label
           feedback the short hash on a profile's torrent list uses (assets/js/favourites.js): the
           chip says "Copied" for a moment and then says the code again.

           Delegated from the page rather than bound per chip, because the reader's own list grows a
           card when they upload one — a handler per chip would miss it. */
        root.addEventListener('click', function (e) {
            var chip = e.target.closest ? e.target.closest('[data-emote-copy]') : null;
            if (!chip || !root.contains(chip)) return;
            e.preventDefault();
            var token = chip.dataset.emoteCopy || chip.textContent;
            if (!navigator.clipboard || !navigator.clipboard.writeText) return;
            navigator.clipboard.writeText(token).then(function () {
                if (chip.dataset.was === undefined) chip.dataset.was = chip.textContent;
                chip.textContent = t('js.common.copied');
                chip.classList.add('is-copied');
                setTimeout(function () {
                    chip.textContent = chip.dataset.was;
                    chip.classList.remove('is-copied');
                }, 1500);
            }, function () { /* the browser refused the clipboard; nothing to say about it */ });
        });

        /* ── taking one away ── */
        var mine = document.getElementById('emote-mine');
        if (mine) {
            mine.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.emote-del') : null;
                if (!btn || !mine.contains(btn)) return;
                var card = btn.closest('.emote-card');
                var id = Number(btn.dataset.id || (card && card.dataset.id) || 0);
                askInPlace(btn, t('js.shout.emote_delete_q'), async function () {
                    var r = await post('shout_emote_delete', { id: id });
                    if (r && r.success) {
                        if (card) card.remove();
                        // The same emote in the list above is gone too — it is the same row.
                        var twin = document.querySelector('.emote-grid .emote-card[data-id="' + id + '"]');
                        if (twin) twin.remove();
                        emotesPromise = null;          // the picker's copy is one emote out of date
                        note(t('js.shout.emote_deleted'));
                        return true;
                    }
                    if (r && r.error === 'not_found') { if (card) card.remove(); return true; }
                    note(emoteErr(r), true);
                    return false;
                }, {
                    host: card,
                    relabel: function (b) { b.textContent = t('js.shout.delete'); },
                });
            });
        }

        /* ── adding one ── */
        var form = document.getElementById('emote-upload');
        if (!form) return;
        var drop = document.getElementById('emote-drop');
        var fileIn = document.getElementById('emote-file');
        var codeIn = document.getElementById('emote-code');
        var nameIn = document.getElementById('emote-name');
        var stickIn = document.getElementById('emote-sticker');
        var btn = document.getElementById('emote-send');
        var maxKb = Number(form.dataset.maxKb) || 64;

        var dropped = null;              // a file that came by drag rather than through the dialog
        function chosen() { return dropped || (fileIn && fileIn.files && fileIn.files[0]) || null; }

        /** The box says which file it holds — the same zone the panel's uploads use. */
        function markFile(file) {
            if (!drop) return;
            drop.classList.toggle('has-file', !!file);
            var main = drop.querySelector('.emote-drop-main');
            if (!main) return;
            main.textContent = '';
            if (file) main.appendChild(document.createTextNode(file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB'));
            else {
                main.appendChild(el('u', { text: t('js.shout.drop_choose') }));
                main.appendChild(document.createTextNode(' ' + t('js.shout.drop_or')));
            }
            // A code nobody typed yet: the file's own name is very nearly always the right guess.
            if (file && codeIn && !codeIn.value.trim()) {
                codeIn.value = file.name.replace(/\.[a-z0-9]+$/i, '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 32);
            }
        }
        if (drop && fileIn) {
            // dragover must be cancelled or the browser navigates to the file, losing the page.
            ['dragenter', 'dragover'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragging'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                drop.addEventListener(ev, function () { drop.classList.remove('dragging'); });
            });
            drop.addEventListener('drop', function (e) {
                e.preventDefault();
                var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (f) { dropped = f; markFile(f); }
            });
            drop.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                fileIn.click();
            });
            fileIn.addEventListener('change', function () { dropped = null; markFile(chosen()); });
        }

        /**
         * A card in the reader's own list, built from what the endpoint just accepted.
         *
         * `pending` is the approval gate seen from here: the row is stored and it is theirs, but
         * nobody else can see it yet. Saying so on the card is the difference between somebody
         * waiting and somebody uploading the same picture a second time because the first one
         * "did not work".
         */
        function myCard(row, pending) {
            var card = el('div', { className: 'emote-card', dataset: { id: String(row.id || 0), code: String(row.code || '') } });
            card.appendChild(el('div', { className: 'emote-shot' },
                [emoteImg(row, row.sticker ? 'shout-sticker' : 'shout-emote')]));
            card.appendChild(el('button', { type: 'button', className: 'emote-copy', title: t('js.shout.emote_copy_title'),
                                            dataset: { emoteCopy: ':' + row.code + ':' }, text: ':' + row.code + ':' }));
            card.appendChild(el('span', { className: 'emote-name', text: row.name || row.code }));
            if (pending) {
                card.appendChild(el('span', { className: 'emote-wait', title: t('js.shout.emote_waiting_hint'),
                                              text: t('js.shout.emote_waiting') }));
            }
            card.appendChild(el('button', { type: 'button', className: 'emote-del', dataset: { id: String(row.id || 0) },
                                            text: t('js.shout.delete') }));
            return card;
        }

        if (!btn) return;
        btn.addEventListener('click', function () {
            var f = chosen();
            if (!f) { note(t('js.shout.pick_file'), true); return; }
            if (f.size > maxKb * 1024) { note(t('js.shout.err_too_large', { kb: maxKb }), true); return; }
            var code = (codeIn ? codeIn.value : '').trim().toLowerCase();
            if (!/^[a-z0-9_]{2,32}$/.test(code)) { note(t('js.shout.err_bad_code'), true); return; }
            var reader = new FileReader();
            reader.onerror = function () { note(t('js.shout.err_failed'), true); };
            reader.onload = async function () {
                btn.disabled = true;
                note(t('js.shout.uploading'));
                var r = await post('shout_emote_upload', {
                    code: code,
                    // Empty stays EMPTY. The server falls back to the prettified code
                    // (shoutEmotePrettyName: "thumbs_up" -> "Thumbs up") and checks that fallback for
                    // a collision like any other name; sending the bare code from here produced a
                    // second, lower-case fallback that the server never applies.
                    name: (nameIn ? nameIn.value : '').trim(),
                    sticker: !!(stickIn && stickIn.checked),
                    data: String(reader.result),
                });
                btn.disabled = false;
                if (!r || !r.success) { note(emoteErr(r), true); return; }
                var row = r.emote || r.added || r.row || null;
                if (row && row.code && row.url && mine) {
                    var empty = mine.querySelector('.emote-empty');
                    if (empty) empty.remove();
                    mine.insertBefore(myCard(row, !!r.pending), mine.firstChild);
                } else {
                    // The endpoint accepted it but did not hand back a row to draw: the server's own
                    // rendering of the page is the honest answer, so ask for it again.
                    location.reload();
                    return;
                }
                if (fileIn) fileIn.value = '';
                if (codeIn) codeIn.value = '';
                if (nameIn) nameIn.value = '';
                if (stickIn) stickIn.checked = false;
                dropped = null;
                markFile(null);
                emotesPromise = null;                  // the picker's copy is one emote out of date
                // The whole token, not the bare code: `:code` is what t() replaces, so a dictionary
                // string written as `:code:` would have eaten its own opening colon.
                //
                // Two different answers, because they are two different situations: a picture that
                // works now, and one that is stored and waiting for somebody. Telling the second one
                // "write :code: to use it" would be a straight lie — the token does nothing yet.
                note(r.pending ? t('js.shout.emote_pending') : t('js.shout.emote_added', { token: ':' + row.code + ':' }));
            };
            reader.readAsDataURL(f);
        });
    }

    mountShoutbox();
    mountEmotesPage();
})();
