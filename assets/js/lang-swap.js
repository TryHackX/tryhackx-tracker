/**
 * The language switcher, without the page reload — and without the jump when a reload still happens.
 *
 * Two independent halves, in this order on purpose:
 *
 * 1. KEEPING YOUR PLACE (always on, even with the swap disabled). A full navigation is still what
 *    happens with JavaScript off, on a network error, and when the session has expired, so the
 *    floor has to hold on its own. The old version saved window.scrollY and put it back on a timer;
 *    the page is still growing at that moment (fonts, the settings search, tables the panel fills
 *    from the API), so the pixel it restored was no longer the pixel you were looking at. This
 *    version remembers the ELEMENT nearest the top of the window and how far below the top edge it
 *    sat, then keeps nudging the scroll until that element is back there and the page has stopped
 *    changing height.
 *
 * 2. SWAPPING IN PLACE (setting `lang_swap_enabled`, off by default for one release). Clicking the
 *    switcher fetches THE SAME PAGE in the other language and rewrites the text of the living DOM.
 *    One request buys three things: the language cookie langInit() sets, the new `js.*` bundle for
 *    t(), and a render we would have paid for anyway.
 *
 * Rules the swap lives by — each one is a bug that happened or would have:
 *   · Plan first, touch nothing until the whole plan is built. A plan that could not be built is a
 *     plan that was not applied: fall back to a plain navigation, which always works.
 *   · Match children by `id` BEFORE matching them by order. The settings search physically moves
 *     sections around (insertBefore in admin-settings.js), so while a search is active the live
 *     order is a permutation of the render's.
 *   · THE WALK REWRITES ONLY WHAT THE SERVER WROTE, AND ONLY WHILE IT STILL SAYS SO (1.73.0). The page
 *     marks what the server sent — LangSwap.mark(), called before the first script of <body> runs
 *     (templates/layout.php, every panel page): its elements, its text, the words in its attributes.
 *     A node a script made takes no part in the walk, not even in the order pass: that pass used to
 *     line a script's row up with the server's placeholder and write "Loading…" over it, a script's
 *     heading with the server's generic one (the Info panel's title — a torrent's name — became
 *     "Szczegóły"), the appeals' headers with the reports'. And a server node a script has since
 *     written something else into is left as the script left it. (A page that never marked keeps
 *     the old rule: everything on it is the server's.) A server button put back from a copy of its
 *     markup after a script's "Working…" is the server's again — and one lent to a script while the
 *     language changed is given back in the new language the moment the script puts that copy back.
 *   · A sentence whose inline markup comes in another order in the other language (a <code> first in
 *     Polish, after a word in English) is planned WHOLE: an element of nothing but text and inline
 *     tags, untouched by scripts, takes the fetched pieces (1.73.0) — pairing them one by one left a
 *     word of the old language standing.
 *   · A live child with no counterpart in the fetched document is SKIPPED, not deleted. That is
 *     what keeps nodes built by scripts — toasts, table rows, pagination, the toolbar's cloned
 *     switcher — from breaking the walk.
 *   · WHAT A SCRIPT WROTE KEEPS ITS KEY (1.73.0). t() stays a plain string; where a script writes a word
 *     into the page it writes t.key() — the word that knows its key (assets/js/i18n.js) — and written into
 *     a node — its text, title, placeholder, any attribute through setAttribute(), a child through append()
 *     and its kin, markup through the templates' escapers — it leaves its key there, on the element. The
 *     swap asks t.ours() for every keyed word that still says what the old
 *     dictionary says, loads the new bundle, and t.say()s them again — in place: nothing is redrawn,
 *     nothing open closes, nothing typed is touched. A part a script DRAWS from an answer it holds (a
 *     list, a table) may instead draw itself again on `langswap` — from that answer, never by asking
 *     the server again: a swap is one request (the one exception: an open emoji picker asks for its
 *     names in the new language — they exist only in a file per language).
 *   · A <template> is language too (1.73.0): what a script clones from one after the swap has to be in
 *     the new language, so the walk goes into its content like into any element — and a copy already
 *     on the page follows its template (LangSwap.adopt(): the message composer, the description editor).
 *   · Never touch what somebody typed: no <textarea> contents and no <input> value, except the
 *     label on a button. Switching language must not eat an unsaved form.
 *   · No inline <script> freezes a translated string any more (1.73.0): settings.php, adminlogin.php
 *     and unsubscribe.php read theirs from the bundle, which the swap reloads.
 *   · An error page is a page too (1.73.0): the fetched copy of a page that was itself served with an
 *     error status (<html data-status>, the panel's hidden 404) answers with the same status, and is
 *     swapped like any other.
 */
(function () {
    'use strict';

    var LINKS = 'a.lang-opt, a.admin-lang-opt';
    var PLACE_KEY = 'thx_lang_place';
    var PLACE_MAX_AGE = 15000;      // a stored place older than this belongs to some other visit
    var SWAP_DEADLINE = 4000;       // the panel render holds the session lock; do not wait for ever
    var SETTLE_MS = 2500;           // how long we keep correcting the scroll while the page grows

    /* ─────────────────────────── 1. keeping your place ─────────────────────────── */

    /**
     * A description of an element that can be resolved again in a freshly rendered page:
     * the nearest ancestor carrying an id, plus the tag/index steps down to the element.
     * Falls back to steps from <body>, which is still better than a pixel.
     */
    function describe(el) {
        var steps = [], node = el, guard = 0;
        while (node && node.nodeType === 1 && node !== document.body && node.parentNode && guard++ < 60) {
            if (node.id) return { id: node.id, steps: steps };
            var i = 0;
            for (var c = node.parentNode.firstElementChild; c && c !== node; c = c.nextElementSibling) {
                if (c.tagName === node.tagName) i++;
            }
            steps.unshift(node.tagName + ':' + i);
            node = node.parentNode;
        }
        return { id: null, steps: steps };
    }

    function resolve(desc) {
        var node = desc.id ? document.getElementById(desc.id) : document.body;
        if (!node) return null;
        for (var s = 0; s < desc.steps.length; s++) {
            var bits = desc.steps[s].split(':'), tag = bits[0], want = parseInt(bits[1], 10), i = 0, found = null;
            for (var c = node.firstElementChild; c; c = c.nextElementSibling) {
                if (c.tagName === tag) { if (i === want) { found = c; break; } i++; }
            }
            if (!found) return null;
            node = found;
        }
        return node;
    }

    /**
     * Is this element pinned to the window rather than to the page?
     *
     * A sticky or fixed element does not move when you scroll, so it can never say where you were:
     * the Settings toolbar is sticky and sits at the very top, which made it the "nearest the top"
     * candidate on every single capture — and putting a pinned element back where it already is
     * scrolls nowhere. That is why the first version of this looked like it did nothing at all.
     */
    function pinned(el) {
        for (var n = el, guard = 0; n && n !== document.body && guard++ < 12; n = n.parentElement) {
            var pos = getComputedStyle(n).position;
            if (pos === 'fixed' || pos === 'sticky') return true;
        }
        return false;
    }

    /** The element nearest the top of the window: the thing the reader is actually looking at. */
    function anchorAtTop() {
        var short = [];      // the few best candidates, cheapest first; only these get a style check
        var cands = document.querySelectorAll(
            '[id], h1, h2, h3, h4, h5, h6, .settings-section, .row > div, .card, section, article, tr, p, li');
        for (var i = 0; i < cands.length; i++) {
            var el = cands[i], r = el.getBoundingClientRect();
            if (!r.height || !r.width) continue;                 // not rendered — no place to keep
            if (r.bottom < 0 || r.top > window.innerHeight) continue;
            var d = Math.abs(r.top);
            if (short.length === 8 && d >= short[7].d) continue;
            var at = 0;
            while (at < short.length && short[at].d <= d) at++;
            short.splice(at, 0, { el: el, d: d });
            if (short.length > 8) short.pop();
        }
        for (i = 0; i < short.length; i++) if (!pinned(short[i].el)) return short[i].el;
        return null;
    }

    function bareUrl(href) {
        try {
            var u = new URL(href, location.href);
            u.searchParams.delete('lang');
            var q = u.searchParams.toString();
            return u.pathname + (q ? '?' + q : '');
        } catch (e) { return href; }
    }

    function capturePlace() {
        var el = anchorAtTop();
        var search = document.getElementById('settings-search');
        var group = document.querySelector('.settings-group-btn.active');
        return {
            // the address WITHOUT ?lang=, so the same page in either language is one place
            path: bareUrl(location.href),
            y: window.scrollY,
            at: Date.now(),
            anchor: el ? describe(el) : null,
            top: el ? el.getBoundingClientRect().top : 0,
            search: search ? search.value : '',
            group: group ? group.dataset.group : '',
        };
    }

    /**
     * Move the window NOW, whatever the stylesheet says about scrolling.
     *
     * Bootstrap's reboot gives the panel `scroll-behavior: smooth` on :root, which turns every bare
     * scrollTo() / scrollBy() into an animation — and the restore below scrolls up to the whole
     * length of Settings and then corrects itself on every change of height, so after a reload the
     * reader watched the page glide towards their place for about 1.8 s (1.70.0). A place kept is a
     * place you are already at. `behavior: 'instant'` says so for these calls alone, so smooth
     * scrolling everywhere else is left as it is. A browser that does not know the word throws on the
     * options object; for that one the rule is lifted from <html> for the length of the call.
     */
    function jump(method, top) {        // method: 'scrollBy' or 'scrollTo'
        try {
            window[method]({ top: top, left: 0, behavior: 'instant' });
            return;
        } catch (e) { /* an older ScrollBehavior without 'instant': below */ }
        var root = document.documentElement, was = root.style.scrollBehavior;
        root.style.scrollBehavior = 'auto';
        window[method](0, top);
        root.style.scrollBehavior = was;
    }

    /**
     * Put the reader back where they were, and KEEP putting them back while the page is still
     * changing height. One shot at DOMContentLoaded is what made the old version land beside the
     * mark: at that moment the settings search has not filtered yet and the panel's tables are empty.
     * Every move is a jump (see jump()): the loop corrects a page that is still growing, and a
     * correction that animated was a page that never stood still.
     */
    function restorePlace(place, settleMs) {
        var settle = settleMs || SETTLE_MS;
        var deadline = Date.now() + settle;
        var done = false;
        var ro = null;
        var timers = [];

        // THE READER WINS, IMMEDIATELY AND FOR GOOD.
        //
        // The settle loop keeps correcting while the page is still growing, which is the whole point
        // of it — but somebody who reaches for the wheel a second after switching language is no
        // longer being helped by it, they are being fought by it: they scroll, and a moment later
        // something drags them back to where they were standing when they clicked. So the first
        // sign of a person moving the page themselves ends the loop.
        //
        // Listening for `scroll` would not do: our own scrollBy fires one, and telling the two apart
        // by a flag is a race against how the browser batches them. These four events only ever come
        // from a person.
        var stop = function () {
            if (done) return;
            done = true;
            if (ro) { ro.disconnect(); ro = null; }
            timers.forEach(clearTimeout);
            ['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(function (ev) {
                window.removeEventListener(ev, stop, true);
            });
        };
        ['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(function (ev) {
            window.addEventListener(ev, stop, true);
        });

        var apply = function () {
            if (done) return;
            if (place.anchor) {
                var el = resolve(place.anchor);
                if (el) {
                    var delta = el.getBoundingClientRect().top - place.top;
                    if (Math.abs(delta) > 0.5) jump('scrollBy', delta);
                    return;
                }
            }
            jump('scrollTo', place.y || 0);   // the element is gone: the pixel is all we have left
        };
        apply();
        if (window.ResizeObserver) {
            ro = new ResizeObserver(function () {
                if (Date.now() > deadline) { stop(); return; }
                apply();
            });
            ro.observe(document.body);
        }
        // Belt as well as braces: images and webfonts move things without resizing <body>.
        [60, 200, 500, 900, 1600, 2400].forEach(function (ms) {
            if (ms > settle) return;
            timers.push(setTimeout(function () { if (Date.now() <= deadline + 200) apply(); }, ms));
        });
        timers.push(setTimeout(stop, settle + 300));
    }

    /** Re-apply the settings page's own view state (filter text, active group) before correcting. */
    function restoreViewState(place) {
        var search = document.getElementById('settings-search');
        if (search && place.search && search.value !== place.search) {
            search.removeAttribute('readonly');
            search.value = place.search;
            search.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (place.group && place.group !== 'all') {
            var b = document.querySelector('.settings-group-btn[data-group="' + place.group + '"]');
            if (b && !b.classList.contains('active')) b.click();
        }
    }

    /**
     * The address this switcher link should really point at, worked out AT CLICK TIME.
     *
     * Both switchers are rendered from $_GET (templates/nav.php, templates/admin/_header_actions.php)
     * — which is the address as it was when the page was BUILT. Since the search page started
     * keeping its state in the address, and the panel started putting ?hash= there, that is no
     * longer the address the reader is on: somebody who typed a query, sorted it and turned to page
     * three would click PL and land on a bare, empty search. The link is rewritten in the capture
     * phase, so the browser follows the new value on a plain navigation and the swap below reads it
     * too.
     */
    function currentHrefFor(a) {
        var code = (a.getAttribute('hreflang') || '').toLowerCase();
        if (!code) return a.href;
        try {
            var u = new URL(location.href);
            u.searchParams.set('lang', code);
            return u.href;
        } catch (e) { return a.href; }
    }

    function refreshSwitcherHref(a) {
        if (!a) return;
        var href = currentHrefFor(a);
        if (href !== a.href) a.href = href;
    }
    // Not only `click`: a middle click, "Open link in a new tab" from the context menu, and Enter on
    // a focused link all follow the href without ever firing one. Each of those is preceded by one of
    // these, and all four are cheap — the work is one URL parse on a link the pointer is already on.
    ['pointerdown', 'auxclick', 'contextmenu', 'keydown'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            refreshSwitcherHref(e.target && e.target.closest ? e.target.closest(LINKS) : null);
        }, true);
    });
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest(LINKS) : null;
        if (!a) return;
        refreshSwitcherHref(a);
        try { sessionStorage.setItem(PLACE_KEY, JSON.stringify(capturePlace())); }
        catch (err) { /* storage unavailable: there is nothing to keep and nothing to clean up */ }
    }, true);

    function onReady(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    onReady(function () {
        var place = null;
        try { place = JSON.parse(sessionStorage.getItem(PLACE_KEY) || 'null'); sessionStorage.removeItem(PLACE_KEY); }
        catch (err) { place = null; }
        if (!place || Date.now() - (place.at || 0) > PLACE_MAX_AGE) return;
        if (place.path !== bareUrl(location.href)) return;      // a different page: not our place
        restoreViewState(place);
        restorePlace(place);
    });

    /* ─────────────────────────── 2. swapping in place ─────────────────────────── */

    // The attributes a translation reaches. Everything else is left exactly as rendered.
    // `data-title` is here for one reason: the Settings page puts each section's heading there and
    // its search reads it, so leaving it behind would let the visible heading and the searchable
    // one disagree about what language the page is in. `data-tip` (1.71.0) is an icon button's
    // explanation, shown in the site's tooltip where a word used to say what the button does.
    // 1.73.0: every other attribute the templates write words into — an <optgroup>/<option>'s label,
    // the account lists' `data-empty-text`, the shoutbox's `data-closed`, the stats heat map's
    // `data-tooltip`, Settings' `data-snd-label` / `data-ok-text` — and the ARIA attributes that are
    // read aloud. A script that reads one of them reads it when it needs it, not once at start.
    var ATTRS = ['title', 'placeholder', 'aria-label', 'alt', 'data-title', 'data-tip', 'label', 'aria-description',
                 'aria-roledescription', 'aria-valuetext', 'aria-placeholder', 'data-label', 'data-empty-text', 'data-closed',
                 'data-tooltip', 'data-snd-label', 'data-ok-text'];
    // Subtrees the walk does not enter. Scripts and styles because their text is not language;
    // <textarea> because its text is what somebody typed. (A <template> it DOES enter, through its
    // content: see planNode.)
    var OPAQUE = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEXTAREA: 1, SVG: 1, CANVAS: 1 };
    var BUTTONISH = { submit: 1, button: 1, reset: 1 };

    /*
     * WHAT THE SERVER WROTE (1.73.0). mark() is called once, before the first script of the body runs,
     * and remembers every element, every text and every translatable attribute the server sent — the
     * walk below takes only those, and only while they still say what the server said. After a swap
     * the new words are what the server says now. Weak maps: a node a script throws away goes with it.
     */
    var marked = false;
    var serverEl = new WeakSet(), serverText = new WeakMap(), serverAttr = new WeakMap();
    /*
     * A BUTTON PUT BACK FROM A COPY is the server's again (1.73.0). The panel's buttons say "Working…" with a
     * spinner while they act, and are then put back from a copy of their markup (`btn.innerHTML = orig`, or
     * just the label inside: `label.textContent = orig` — a bulk scrape's progress) — the same words, but new
     * nodes, which the walk would take for a script's and leave in the language they were copied in, for good.
     * So each server button's markup is remembered (as the server sent it, and as every swap leaves it), and a
     * button that holds exactly that markup again counts as the server's again.
     * A button LENT to a script at the moment of a swap (its words are the script's just then) is given back in
     * the new language: the walk keeps its counterpart in the fetched page, and when the script puts back the
     * markup it copied — the old language's — that markup counts as the server's and is planned against the
     * counterpart at once (a MutationObserver on the lent buttons only), instead of waiting for the next swap.
     */
    var serverButtons = [], buttonHtml = new WeakMap(), counterpart = new WeakMap(), lent = [], watcher = null;
    function noteButton(el) {
        if (el.tagName !== 'BUTTON') return;
        if (!buttonHtml.has(el)) serverButtons.push(el);
        buttonHtml.set(el, el.innerHTML);
    }
    // Every node in it is one the server wrote, still saying what it wrote (blank text aside).
    function wholeServer(node) {
        for (var c = node.firstChild; c; c = c.nextSibling) {
            if (c.nodeType === 3) { if (c.nodeValue.trim() !== '' && serverText.get(c) !== c.nodeValue) return false; continue; }
            if (c.nodeType !== 1) continue;
            if (!serverEl.has(c) || (!OPAQUE[c.tagName] && !wholeServer(c))) return false;
        }
        return true;
    }
    function regainButtons() {
        serverButtons.forEach(function (b) {
            var html = buttonHtml.get(b);
            if (html === undefined || b.innerHTML !== html || wholeServer(b)) return;
            markTree(b);
        });
    }
    function rememberButtons() {
        serverButtons = serverButtons.filter(function (b) { return b.isConnected; });   // one a script took away goes
        lent = [];
        serverButtons.forEach(function (b) {
            if (wholeServer(b)) { buttonHtml.set(b, b.innerHTML); counterpart.delete(b); }
            else if (counterpart.has(b)) lent.push(b);        // keeps the markup the script will put back
        });
        if (watcher) watcher.disconnect();
        watcher = null;
        if (!lent.length || !window.MutationObserver) return;
        watcher = new MutationObserver(giveBack);
        lent.forEach(function (b) { watcher.observe(b, { childList: true, subtree: true, characterData: true, attributes: true }); });
    }
    function giveBack() {
        lent = lent.filter(function (b) {
            if (!b.isConnected) return false;
            if (b.innerHTML !== buttonHtml.get(b)) return true;             // still the script's
            var out = [], fresh = counterpart.get(b);
            counterpart.delete(b);
            markTree(b);
            try { planNode(b, fresh, out, 0); } catch (e) { out = []; }
            applyPlan(out);
            buttonHtml.set(b, b.innerHTML);
            return false;
        });
        if (!lent.length && watcher) { watcher.disconnect(); watcher = null; }
    }
    function attrsOf(el) {
        var o = null;
        for (var i = 0; i < ATTRS.length; i++) {
            var v = el.getAttribute(ATTRS[i]);
            if (v !== null) (o || (o = {}))[ATTRS[i]] = v;
        }
        if (el.tagName === 'A' && el.hasAttribute('href')) (o || (o = {})).href = el.getAttribute('href');
        if (el.tagName === 'INPUT' && BUTTONISH[(el.getAttribute('type') || '').toLowerCase()] && el.hasAttribute('value')) (o || (o = {})).value = el.getAttribute('value');
        return o;
    }
    function markTree(node) {
        for (var c = node.firstChild; c; c = c.nextSibling) {
            if (c.nodeType === 3) { serverText.set(c, c.nodeValue); continue; }
            if (c.nodeType !== 1) continue;
            serverEl.add(c);
            var a = attrsOf(c);
            if (a) serverAttr.set(c, a);
            if (c.tagName === 'TEMPLATE' && c.content) markTree(c.content);
            else if (!OPAQUE[c.tagName]) { markTree(c); if (c.isConnected) noteButton(c); }
        }
    }
    function mark() {
        if (marked || !document.body) return;
        markTree(document.body);
        marked = true;
    }
    /*
     * A COPY OF A <template> IS THE SERVER'S WORDS TOO (1.73.0): the message composer and the Info panel's
     * description editor are cloned from one, and a clone is a node a script made — so the walk would never
     * reach it, and an editor open while the language changes kept its Write / Preview / Formatting help in
     * the old one. adopt(nodes, templateId) — the copy's top-level nodes, taken before the copy is put in the
     * page — says "these were cloned from that template": they count as the server's from that moment, and
     * every swap walks them against the template's new content. What the script then writes into the copy is
     * its own, as anywhere else.
     */
    var adopted = [];
    function markNode(n) {
        if (n.nodeType === 3) { serverText.set(n, n.nodeValue); return; }
        if (n.nodeType !== 1) return;
        serverEl.add(n);
        var a = attrsOf(n);
        if (a) serverAttr.set(n, a);
        if (!OPAQUE[n.tagName]) markTree(n);
    }
    function adopt(nodes, templateId) {
        nodes = Array.prototype.slice.call(nodes || []).filter(function (n) { return meaningful(n); });
        if (!nodes.length || !templateId) return;
        nodes.forEach(markNode);
        adopted = adopted.filter(function (a) { return a.nodes.some(function (n) { return n.isConnected; }); });
        adopted.push({ nodes: nodes, id: templateId });
    }
    // Is this press the language switcher's? A popover that closes on a press outside it lets this one through
    // (1.73.0): the switch translates what is open in place, and pressing it must not close the thing to translate.
    function isSwitch(el) { return !!(el && el.closest && el.closest(LINKS)); }
    window.LangSwap = { mark: mark, adopt: adopt, isSwitch: isSwitch };
    // A page that did not mark itself early is marked as it finishes loading — this listener was the first one
    // added, so it runs before every other script's. (What a script built BEFORE that moment counts as the
    // server's there, which is no worse than the rule before marks existed.)
    onReady(mark);
    // A child the walk may take: anything on a page that never marked; on a marked page, only the server's.
    function isServer(n) { return !marked || (n.nodeType === 3 ? serverText.has(n) : serverEl.has(n)); }
    function stillServer(el, name, value) {
        if (!marked) return true;
        var a = serverAttr.get(el);
        return !!a && a[name] === value;
    }

    function swapEnabled() {
        return !!(window.t && window.t.swap) && document.querySelectorAll(LINKS).length > 1;
    }

    function meaningful(n) {
        return n.nodeType === 1 || (n.nodeType === 3 && n.nodeValue.trim() !== '');
    }

    /**
     * What makes two siblings "the same kind of thing" for the order pass. Tag, plus the field name
     * when there is one — classes are deliberately NOT in here, because the page's own scripts add
     * and remove them (d-hidden, active, settings-hit) and a signature that moves is no signature.
     */
    function sigOf(n) {
        if (n.nodeType !== 1) return 'T';
        var name = n.getAttribute('name');
        return n.tagName + (name ? '@' + name : '');
    }

    /**
     * Line two child lists up by their longest common subsequence.
     *
     * The first version walked forward and took the next free node of the same tag. One inserted
     * node broke everything after it: the Settings page's search puts a breadcrumb <div> at the top
     * of each section, that <div> claimed the first <div> of the render, and every following cell
     * took its neighbour's text — a field ended up labelled with the label of the field below it.
     * Wrong text under the right control is worse than no swap at all, so the order pass now has to
     * be able to say "this live node has no counterpart", and a subsequence match is what says it.
     */
    function alignByOrder(a, b) {
        var n = a.length, m = b.length, i, j, pairs = [];
        if (!n || !m) return pairs;
        var sa = new Array(n), sb = new Array(m);
        for (i = 0; i < n; i++) sa[i] = sigOf(a[i]);
        for (j = 0; j < m; j++) sb[j] = sigOf(b[j]);
        if (n * m > 40000) {                    // a list this long is generated, not written
            var cursor = 0;
            for (i = 0; i < n; i++) {
                for (j = cursor; j < m; j++) if (sa[i] === sb[j]) { pairs.push([i, j]); cursor = j + 1; break; }
            }
            return pairs;
        }
        var dp = new Array(n + 1);
        for (i = 0; i <= n; i++) dp[i] = new Int32Array(m + 1);
        for (i = n - 1; i >= 0; i--) {
            for (j = m - 1; j >= 0; j--) {
                dp[i][j] = sa[i] === sb[j] ? dp[i + 1][j + 1] + 1
                                           : (dp[i + 1][j] >= dp[i][j + 1] ? dp[i + 1][j] : dp[i][j + 1]);
            }
        }
        i = 0; j = 0;
        while (i < n && j < m) {
            if (sa[i] === sb[j]) { pairs.push([i, j]); i++; j++; }
            else if (dp[i + 1][j] >= dp[i][j + 1]) i++;
            else j++;
        }
        return pairs;
    }

    /** Collect the changes as {text,v} / {el,attr,v}. Throws when the two shapes disagree. */
    function planNode(live, fresh, out, depth) {
        if (depth > 60) throw new Error('too deep');
        if (live.nodeType === 3) {
            // Only while it still says what the server wrote: a count a script keeps in a server text is the script's.
            if (live.nodeValue !== fresh.nodeValue && (!marked || serverText.get(live) === live.nodeValue)) out.push({ text: live, v: fresh.nodeValue });
            return;
        }
        if (live.nodeType !== 1) return;
        if (live.tagName !== fresh.tagName) throw new Error('tag mismatch');
        if (live.hasAttribute('data-lang-keep')) return;
        // A server button lent to a script just now: what it says once given back (see giveBack()).
        if (marked && live.tagName === 'BUTTON' && buttonHtml.has(live) && !wholeServer(live)) counterpart.set(live, fresh);

        for (var i = 0; i < ATTRS.length; i++) {
            var a = ATTRS[i];
            var lv = live.getAttribute(a), fv = fresh.getAttribute(a);
            if (lv !== null && fv !== null && lv !== fv && stillServer(live, a, lv)) out.push({ el: live, attr: a, v: fv });
        }
        // The label on a button is text; the value of a field is somebody's data.
        if (live.tagName === 'INPUT' && BUTTONISH[(live.getAttribute('type') || '').toLowerCase()]) {
            var bl = live.getAttribute('value'), bf = fresh.getAttribute('value');
            if (bl !== null && bf !== null && bl !== bf && stillServer(live, 'value', bl)) out.push({ el: live, attr: 'value', v: bf });
        }
        // An <a> whose address differs between the two renders differs BECAUSE of the language.
        if (live.tagName === 'A') {
            var hl = live.getAttribute('href'), hf = fresh.getAttribute('href');
            if (hl !== null && hf !== null && hl !== hf && stillServer(live, 'href', hl)) out.push({ el: live, attr: 'href', v: hf });
        }
        // What a script will clone from a template later must be the new language's (1.73.0).
        if (live.tagName === 'TEMPLATE') {
            if (live.content && fresh.content) planChildren(live.content, fresh.content, out, depth);
            return;
        }
        if (OPAQUE[live.tagName]) return;
        if (planPhrase(live, fresh, out)) return;

        planChildren(live, fresh, out, depth);
    }

    /*
     * A SENTENCE WHOSE MARKUP SITS IN ANOTHER ORDER (1.73.0). "A member's <code>[url]</code> becomes a link" is, in
     * Polish, "<code>[url]</code> od członka staje się linkiem": the same words and tags, in another order — so pairing
     * the pieces one by one leaves "A member's" standing. An element that holds nothing but text and inline markup
     * (no ids, nothing a script touched) and whose pieces come in another order in the fetched page is planned whole:
     * its pieces are replaced by the fetched ones, which count as the server's from then on.
     */
    var INLINE = { CODE: 1, B: 1, I: 1, EM: 1, STRONG: 1, SMALL: 1, KBD: 1, MARK: 1, SUP: 1, SUB: 1, BR: 1, SPAN: 1, A: 1, ABBR: 1,
                   U: 1, S: 1, Q: 1, CITE: 1, DFN: 1, VAR: 1, SAMP: 1, TIME: 1 };
    function phraseOnly(el, live) {
        for (var c = el.firstChild; c; c = c.nextSibling) {
            if (c.nodeType === 3) { if (live && marked && serverText.get(c) !== c.nodeValue) return false; continue; }
            if (c.nodeType !== 1) continue;
            if (!INLINE[c.tagName] || c.id || (live && marked && !serverEl.has(c)) || !phraseOnly(c, live)) return false;
        }
        return true;
    }
    function shapeOf(el) {
        var s = [];
        for (var c = el.firstChild; c; c = c.nextSibling) if (meaningful(c)) s.push(c.nodeType === 3 ? 'T' : c.tagName);
        return s.join(',');
    }
    function planPhrase(live, fresh, out) {
        if (!live.firstChild || shapeOf(live) === shapeOf(fresh)) return false;
        if (!phraseOnly(live, true) || !phraseOnly(fresh, false)) return false;
        if (live.textContent.replace(/\s+/g, ' ').trim() === fresh.textContent.replace(/\s+/g, ' ').trim()) return false;
        out.push({ phrase: live, from: fresh });
        return true;
    }

    function planChildren(live, fresh, out, depth) {
        var lk = [], fk = [], n;
        // A node a script made is not the server's to pair (1.73.0): it takes no part in either pass.
        for (n = live.firstChild; n; n = n.nextSibling) if (meaningful(n) && isServer(n)) lk.push(n);
        for (n = fresh.firstChild; n; n = n.nextSibling) if (meaningful(n)) fk.push(n);
        planList(lk, fk, out, depth);
    }

    /** Two lists of siblings — a live element's server children, the render's — lined up and planned. */
    function planList(lk, fk, out, depth) {
        var k;
        // Pass 1 — BY ID, and without regard to position. This is the pass that matters: the
        // Settings search physically moves sections around with insertBefore, so while a query is
        // active the live order is a permutation of the render's and position means nothing.
        var byId = {}, freshIdx = [], liveRest = [], freshRest = [];
        for (k = 0; k < fk.length; k++) {
            if (fk[k].nodeType === 1 && fk[k].id) byId[fk[k].id] = fk[k];
            else freshRest.push(fk[k]);
        }
        for (k = 0; k < lk.length; k++) {
            var id = lk[k].nodeType === 1 ? lk[k].id : '';
            if (!id) { liveRest.push(lk[k]); continue; }
            // An identified node matches an identified node or nothing. Falling back to position
            // for it would let a node the scripts added claim a section of the render.
            if (Object.prototype.hasOwnProperty.call(byId, id)) planNode(lk[k], byId[id], out, depth + 1);
        }
        // Pass 2 — the rest, lined up by longest common subsequence, so a live child that the
        // fetched document does not have (a toast, a table row, the search's breadcrumb) is left
        // out of the alignment instead of pushing everything after it one place along.
        var pairs = alignByOrder(liveRest, freshRest);
        for (k = 0; k < pairs.length; k++) planNode(liveRest[pairs[k][0]], freshRest[pairs[k][1]], out, depth + 1);
    }

    /** Carry out a plan: texts and attributes rewritten in place, a sentence planned whole given the fetched pieces. */
    function applyPlan(out) {
        for (var i = 0; i < out.length; i++) {
            var c = out[i];
            if (c.phrase) {                       // a sentence planned whole (planPhrase): the fetched pieces, marked
                var kids = [];
                for (var f = c.from.firstChild; f; f = f.nextSibling) kids.push(document.importNode(f, true));
                while (c.phrase.firstChild) c.phrase.removeChild(c.phrase.firstChild);
                kids.forEach(function (k) { c.phrase.appendChild(k); });
                if (marked) markTree(c.phrase);
                continue;
            }
            if (c.text) {
                c.text.nodeValue = c.v;
                if (marked) serverText.set(c.text, c.v);
            } else {
                c.el.setAttribute(c.attr, c.v);
                var sa = marked ? serverAttr.get(c.el) : null;
                if (sa) sa[c.attr] = c.v;
            }
        }
    }

    function markActive(code) {
        var all = document.querySelectorAll(LINKS);
        for (var i = 0; i < all.length; i++) {
            var on = (all[i].getAttribute('hreflang') || '').toLowerCase() === code;
            all[i].classList.toggle('active', on);
            if (on) all[i].setAttribute('aria-current', 'true');
            else all[i].removeAttribute('aria-current');
        }
    }

    var busy = false;

    /*
     * THE OTHER LANGUAGE'S DICTIONARY IS A FILE (1.74.0, includes/lang.php langJsBridge()): the fetched page names it
     * (#i18n-data: `id`, `src`) instead of carrying it, and it is loaded before anything is rewritten — the very
     * address a page in that language names, so the browser has it cached once either page was opened. A page that
     * still carries its strings inline (an older render) needs nothing loaded. A file that does not come in time is a
     * swap that cannot be made: the plain navigation, as for any other failure.
     */
    function dictReady(meta) {
        if (meta.strings || !meta.id || (window.I18N_DICT && window.I18N_DICT[meta.id])) return Promise.resolve();
        if (!meta.src) return Promise.reject(new Error('no dictionary'));
        return new Promise(function (ok, no) {
            var s = document.createElement('script'), done = false;
            var finish = function (good) {
                if (done) return;
                done = true;
                clearTimeout(late);
                if (good && window.I18N_DICT && window.I18N_DICT[meta.id]) ok(); else no(new Error('no dictionary'));
            };
            var late = setTimeout(function () { finish(false); }, SWAP_DEADLINE);
            s.addEventListener('load', function () { finish(true); });
            s.addEventListener('error', function () { finish(false); });
            s.src = meta.src;
            document.head.appendChild(s);
        });
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest ? e.target.closest(LINKS) : null;
        if (!a || !a.href) return;
        if (!swapEnabled()) return;                       // full navigation, place kept by part 1
        var code = (a.getAttribute('hreflang') || '').toLowerCase();
        if (!code) return;
        e.preventDefault();
        if (code === (window.t && window.t.lang) || busy) return;
        busy = true;

        var href = currentHrefFor(a);   // the capture listener above has already written it back
        var place = capturePlace();
        document.documentElement.setAttribute('data-lang-swapping', '');

        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, SWAP_DEADLINE);
        // Pollers that hold the session lock make this request wait behind them; a page that runs
        // one can listen for this and stand down until langswap:end.
        document.dispatchEvent(new CustomEvent('langswap:begin', { detail: { lang: code } }));

        var giveUp = function () {
            clearTimeout(timer);
            document.dispatchEvent(new CustomEvent('langswap:end', { detail: { lang: code, ok: false } }));
            try { sessionStorage.setItem(PLACE_KEY, JSON.stringify(place)); } catch (err) { /* nothing to keep */ }
            location.assign(href);
        };

        fetch(href, {
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html', 'X-Lang-Swap': '1' },
            signal: ctrl ? ctrl.signal : undefined,
        }).then(function (res) {
            // An error page asked for again answers with its own error (1.73.0): the panel's hidden 404
            // in the other language is still the page the reader is on. Any OTHER status is not.
            var was = Number(document.documentElement.getAttribute('data-status') || 0);
            if (!res.ok && !(was && res.status === was)) throw new Error('HTTP ' + res.status);
            return res.text();
        }).then(function (html) {
            clearTimeout(timer);
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var dataNode = doc.getElementById('i18n-data');
            var bundle = dataNode ? JSON.parse(dataNode.textContent || '{}') : null;
            // Not the page we asked for — an expired session, a redirect to the sign-in form, an
            // error page. Let the browser go there properly instead of grafting it onto this one.
            if (!bundle || bundle.lang !== code || !doc.body) throw new Error('not the same page');
            // Its dictionary first (1.74.0, dictReady()): nothing is touched until the words are here.
            return dictReady(bundle).then(function () { return { doc: doc, dataNode: dataNode }; });
        }).then(function (got) {
            var doc = got.doc, dataNode = got.dataNode;

            var out = [];
            if (marked) regainButtons();
            planChildren(document.body, doc.body, out, 0);
            // The copies of templates still on the page, against the template's new content (see adopt()).
            adopted = adopted.filter(function (a) { return a.nodes.some(function (n) { return n.isConnected; }); });
            adopted.forEach(function (a) {
                var tpl = doc.getElementById(a.id);
                if (!tpl || !tpl.content) return;
                var fresh = [];
                for (var f = tpl.content.firstChild; f; f = f.nextSibling) if (meaningful(f)) fresh.push(f);
                planList(a.nodes.filter(function (n) { return n.isConnected; }), fresh, out, 0);
            });
            // The words scripts wrote and keep the keys of, while the old dictionary is still the one
            // loaded: those that still say what it says are said again once the new one is (1.73.0).
            var ours = (window.t && window.t.ours) ? window.t.ours(document.body) : [];
            if (!out.length && !ours.length) throw new Error('nothing to change');

            applyPlan(out);
            var liveData = document.getElementById('i18n-data');
            if (liveData) liveData.textContent = dataNode.textContent;
            if (window.t && window.t.reload) window.t.reload();
            if (window.t && window.t.say) window.t.say(ours);
            if (marked) rememberButtons();
            document.documentElement.lang = code;
            if (doc.title) document.title = doc.title;
            markActive(code);
            try { history.replaceState(history.state, '', href); } catch (err) { /* opaque origin */ }
            // The place was stored by the capture listener in case this turned into a navigation. It
            // did not, and restorePlace() below handles it in-process — so drop it, or an unrelated
            // reload within the next fifteen seconds would restore a scroll position and a settings
            // filter from before the swap.
            try { sessionStorage.removeItem(PLACE_KEY); } catch (err) { /* nothing stored */ }

            busy = false;
            document.documentElement.removeAttribute('data-lang-swapping');
            // The page's own scripts hold strings they read once at startup (the settings search
            // indexes every label and hint); this is where they rebuild them.
            document.dispatchEvent(new CustomEvent('langswap', { detail: { lang: code, changed: out.length } }));
            document.dispatchEvent(new CustomEvent('langswap:end', { detail: { lang: code, ok: true } }));
            // A short settle: nothing was reloaded, so the only movement is the new text being a
            // little taller or shorter. The long one belongs to the reload path, where the page is
            // still filling in from the API for a second or more.
            restorePlace(place, 700);
        }).catch(function () {
            busy = false;
            giveUp();
        });
    });
})();
