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
 *   · A live child with no counterpart in the fetched document is SKIPPED, not deleted. That is
 *     what keeps nodes built by scripts — toasts, table rows, pagination, the toolbar's cloned
 *     switcher — from breaking the walk, with nothing to mark and nothing to maintain.
 *   · Never touch what somebody typed: no <textarea> contents and no <input> value, except the
 *     label on a button. Switching language must not eat an unsaved form.
 *   · Strings frozen into an inline <script> at render time (settings.php, adminlogin.php and
 *     unsubscribe.php do this with json_encode(__(...))) are NOT in the js.* bundle and are not
 *     swapped. They stay in the old language until the next full page load.
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
     * Put the reader back where they were, and KEEP putting them back while the page is still
     * changing height. One shot at DOMContentLoaded is what made the old version land beside the
     * mark: at that moment the settings search has not filtered yet and the panel's tables are empty.
     */
    function restorePlace(place) {
        var deadline = Date.now() + SETTLE_MS;
        var apply = function () {
            if (place.anchor) {
                var el = resolve(place.anchor);
                if (el) {
                    var delta = el.getBoundingClientRect().top - place.top;
                    if (Math.abs(delta) > 0.5) window.scrollBy(0, delta);
                    return;
                }
            }
            window.scrollTo(0, place.y || 0);   // the element is gone: the pixel is all we have left
        };
        apply();
        var ro = null;
        var stop = function () { if (ro) { ro.disconnect(); ro = null; } };
        if (window.ResizeObserver) {
            ro = new ResizeObserver(function () {
                if (Date.now() > deadline) { stop(); return; }
                apply();
            });
            ro.observe(document.body);
        }
        // Belt as well as braces: images and webfonts move things without resizing <body>.
        [60, 200, 500, 900, 1600, 2400].forEach(function (ms) {
            setTimeout(function () { if (Date.now() <= deadline + 200) apply(); }, ms);
        });
        setTimeout(stop, SETTLE_MS + 300);
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

    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest(LINKS) : null;
        if (!a) return;
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
    // one disagree about what language the page is in.
    var ATTRS = ['title', 'placeholder', 'aria-label', 'alt', 'data-title'];
    // Subtrees the walk does not enter. Scripts and styles because their text is not language;
    // <textarea> because its text is what somebody typed.
    var OPAQUE = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEMPLATE: 1, TEXTAREA: 1, SVG: 1, CANVAS: 1 };
    var BUTTONISH = { submit: 1, button: 1, reset: 1 };

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
            if (live.nodeValue !== fresh.nodeValue) out.push({ text: live, v: fresh.nodeValue });
            return;
        }
        if (live.nodeType !== 1) return;
        if (live.tagName !== fresh.tagName) throw new Error('tag mismatch');
        if (live.hasAttribute('data-lang-keep')) return;

        for (var i = 0; i < ATTRS.length; i++) {
            var a = ATTRS[i];
            var lv = live.getAttribute(a), fv = fresh.getAttribute(a);
            if (lv !== null && fv !== null && lv !== fv) out.push({ el: live, attr: a, v: fv });
        }
        // The label on a button is text; the value of a field is somebody's data.
        if (live.tagName === 'INPUT' && BUTTONISH[(live.getAttribute('type') || '').toLowerCase()]) {
            var bl = live.getAttribute('value'), bf = fresh.getAttribute('value');
            if (bl !== null && bf !== null && bl !== bf) out.push({ el: live, attr: 'value', v: bf });
        }
        // An <a> whose address differs between the two renders differs BECAUSE of the language.
        if (live.tagName === 'A') {
            var hl = live.getAttribute('href'), hf = fresh.getAttribute('href');
            if (hl !== null && hf !== null && hl !== hf) out.push({ el: live, attr: 'href', v: hf });
        }
        if (OPAQUE[live.tagName]) return;

        planChildren(live, fresh, out, depth);
    }

    function planChildren(live, fresh, out, depth) {
        var lk = [], fk = [], n, k;
        for (n = live.firstChild; n; n = n.nextSibling) if (meaningful(n)) lk.push(n);
        for (n = fresh.firstChild; n; n = n.nextSibling) if (meaningful(n)) fk.push(n);

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

        var href = a.href;
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
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
        }).then(function (html) {
            clearTimeout(timer);
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var dataNode = doc.getElementById('i18n-data');
            var bundle = dataNode ? JSON.parse(dataNode.textContent || '{}') : null;
            // Not the page we asked for — an expired session, a redirect to the sign-in form, an
            // error page. Let the browser go there properly instead of grafting it onto this one.
            if (!bundle || bundle.lang !== code || !doc.body) throw new Error('not the same page');

            var out = [];
            planChildren(document.body, doc.body, out, 0);
            if (!out.length) throw new Error('nothing to change');

            for (var i = 0; i < out.length; i++) {
                var c = out[i];
                if (c.text) c.text.nodeValue = c.v;
                else c.el.setAttribute(c.attr, c.v);
            }
            var liveData = document.getElementById('i18n-data');
            if (liveData) liveData.textContent = dataNode.textContent;
            if (window.t && window.t.reload) window.t.reload();
            document.documentElement.lang = code;
            if (doc.title) document.title = doc.title;
            markActive(code);
            try { history.replaceState(history.state, '', href); } catch (err) { /* opaque origin */ }

            busy = false;
            document.documentElement.removeAttribute('data-lang-swapping');
            // The page's own scripts hold strings they read once at startup (the settings search
            // indexes every label and hint); this is where they rebuild them.
            document.dispatchEvent(new CustomEvent('langswap', { detail: { lang: code, changed: out.length } }));
            document.dispatchEvent(new CustomEvent('langswap:end', { detail: { lang: code, ok: true } }));
            restorePlace(place);
        }).catch(function () {
            busy = false;
            giveUp();
        });
    });
})();
