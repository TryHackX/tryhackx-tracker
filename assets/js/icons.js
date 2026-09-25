/**
 * Font Awesome over Bootstrap's markup, for everything a script builds (1.68.0).
 *
 * Loaded ONLY when Settings → Site chooses Font Awesome: includes/icons.php prints it into the <head>
 * together with the name map (#icon-map), and with Bootstrap Icons chosen the page carries neither.
 *
 * Every icon on the site is written in Bootstrap's markup — `bi bi-NAME`. The server adds the mapped
 * Font Awesome classes to the page it sends (iconFilterHtml()); this adds them to what arrives later:
 * rows and dialogs built by scripts, HTML fetched and inserted, and icons whose `className` a script
 * reassigns (a sort arrow turning, a pin becoming the filled pin). The `bi` classes always stay, so
 * every stylesheet rule and selector that names `.bi` or `.bi-trash` keeps working.
 *
 * Keyed on the `bi-NAME` CLASS, never on the <i> element: an <i> is also the progress bar in the
 * Languages table and italic text in a description.
 *
 * Cheap on the big panel tables by construction: while the page is still being parsed the server's
 * rewrite already covers what the parser adds, so added nodes are ignored until DOMContentLoaded, when
 * the whole document is walked ONCE (which also catches anything an inline script built meanwhile).
 * From then on only the subtrees that are added are looked at. Class changes are handled throughout.
 * Idempotent: an element that already carries its classes is left untouched, so the observer's own
 * change comes back to it once and stops there.
 *
 * Since 1.69.0 it also looks at what stands BESIDE each icon: an icon with words next to it is marked
 * `data-label` (the fixed box of an icon-only button is not for it), and its glyph is measured in the
 * face it is drawn in — its own middle, the room at its side — so the stylesheets can put it level with
 * its words and at the row's one distance from them (label(), fit()).
 */
(function () {
    'use strict';
    var src = document.getElementById('icon-map');
    var MAP = null;
    try { MAP = src ? JSON.parse(src.textContent || 'null') : null; } catch (e) { MAP = null; }
    if (!MAP || typeof MAP !== 'object' || !window.MutationObserver) return;

    var has = Object.prototype.hasOwnProperty;

    /** The first `bi-NAME` class the map knows, or null. */
    function nameOf(cl) {
        for (var i = 0; i < cl.length; i++) {
            var c = cl[i];
            if (c.length > 3 && c.lastIndexOf('bi-', 0) === 0 && has.call(MAP, c.slice(3))) return c.slice(3);
        }
        return null;
    }

    /**
     * Make one element's Font Awesome classes agree with its `bi-NAME`: add the mapped ones, drop any
     * left over from a name it no longer has. `fa-*` classes are ours alone — nothing else on the site
     * uses the prefix — so a stale one can only have come from here.
     */
    function apply(el) {
        var cl = el.classList;
        if (!cl) return;
        var name = nameOf(cl);
        // Only the site's own icons (`bi`) are this map's. A Font Awesome face in a shout or in the emoji
        // picker (1.69.0, `fae`) is written with Font Awesome's classes directly, and taking away every
        // fa- class the map does not want took the face away the moment one was added to the page.
        if (!name && !cl.contains('bi')) return;
        var want = name ? MAP[name].split(' ') : [];
        var drop = [], i;
        for (i = 0; i < cl.length; i++) {
            if (cl[i].lastIndexOf('fa-', 0) === 0 && want.indexOf(cl[i]) < 0) drop.push(cl[i]);
        }
        for (i = 0; i < drop.length; i++) cl.remove(drop[i]);
        for (i = 0; i < want.length; i++) if (!cl.contains(want[i])) cl.add(want[i]);
        if (name) label(el);
    }

    /**
     * An icon with words of its own beside it — text, or an element that holds text (a <span> label, a
     * count), next to it in the same element — carries `data-label` (1.69.0). The stylesheets give an
     * icon that is the only element of a button Font Awesome's fixed box, so an icon-only button is one
     * size whichever glyph it shows; but text nodes are not elements, and `:only-child` also picked every
     * icon that stood before bare text ("Fetch
     * metadata"): the glyph centred in a slot one em wide, its ink reaching 0.125em into the space
     * before the word when it is wide (the cloud) and standing 0.19em back when it is narrow (the bin),
     * so the space to the word changed with the glyph. Beside words a glyph keeps its own width; the
     * server marks what it sends (iconFilterHtml()), this marks what scripts build, and follows text
     * that comes or goes later.
     */
    function label(el) {
        var p = el.parentNode, before = false, after = false, past = false;
        // Words: a text node, or an element that holds some (a <span> label, a count) — not another icon.
        if (p) for (var n = p.firstChild; n; n = n.nextSibling) {
            if (n === el) { past = true; continue; }
            var said = n.nodeType === 3 ? /\S/.test(n.nodeValue)
                : n.nodeType === 1 && !(n.classList && n.classList.contains('bi')) && /\S/.test(n.textContent || '');
            if (said) { if (past) after = true; else before = true; }
        }
        var words = before || after;
        if (words !== el.hasAttribute('data-label')) {
            if (words) el.setAttribute('data-label', ''); else el.removeAttribute('data-label');
        }
        if (words) fit(el, !after);
        else if (el.style && el.style.getPropertyValue('--bi-mid')) {
            el.style.removeProperty('--bi-mid');
            if (p && p.style) p.style.removeProperty('--bi-sb');
        }
    }

    /**
     * The glyph's own middle, and the room beside it, as the face it is drawn in says (1.69.0). The
     * stylesheets put an icon's middle on its words' cap middle taking a Font Awesome glyph to be centred
     * 0.375em up and its ink to reach its sides — true of most of it, not of all: a chevron is drawn
     * 0.06em off its em's middle, 7's star 0.05em high, a wand's sparkles leave room on its right, and Pro
     * 7's families (Jelly, Slab, …) draw the same names their own way. So a glyph that stands beside words
     * is measured once per face, on a canvas, when that face has loaded: the icon carries its own middle
     * (--bi-mid) and its row the room on the words' side (--bi-sb, which the row takes off its gap).
     * Bootstrap's single pinned face has the same numbers written into the stylesheets instead.
     */
    var metrics = {}, pending = [], ctx = null;
    function glyphOf(c) {
        var m = /^"((?:[^"\\]|\\.)*)"/.exec(String(c || ''));
        return m ? m[1].replace(/\\(.)/g, '$1') : '';
    }
    function fit(el, wordsBefore) {
        if (!el.style || !document.fonts) return;
        var cs = getComputedStyle(el, '::before');
        var ch = glyphOf(cs.content);
        if (!ch) return;
        var font = cs.fontStyle + ' ' + cs.fontWeight + ' 100px ' + cs.fontFamily;
        var key = font + '\n' + ch;
        var g = metrics[key];
        if (!g) {
            if (!document.fonts.check(font, ch)) { pending.push(el); return; }   // measured once it loads
            ctx = ctx || document.createElement('canvas').getContext('2d');
            ctx.font = font;
            var t = ctx.measureText(ch);
            g = metrics[key] = { mid: (t.actualBoundingBoxAscent - t.actualBoundingBoxDescent) / 200,
                                 l: Math.max(0, -t.actualBoundingBoxLeft / 100),
                                 r: Math.max(0, (t.width - t.actualBoundingBoxRight) / 100) };
        }
        el.style.setProperty('--bi-mid', g.mid.toFixed(3) + 'em');
        var p = el.parentNode;
        if (p && p.style) p.style.setProperty('--bi-sb', (wordsBefore ? g.l : g.r).toFixed(3) + 'em');
    }
    if (document.fonts && document.fonts.addEventListener) {
        document.fonts.addEventListener('loadingdone', function () {
            var list = pending;
            pending = [];
            for (var i = 0; i < list.length; i++) if (list[i].isConnected && list[i].hasAttribute('data-label')) label(list[i]);
        });
    }
    /** The icons among one element's children, after its text changed. */
    function relabel(p) {
        if (!p || p.nodeType !== 1) return;
        for (var c = p.firstElementChild; c; c = c.nextElementSibling) {
            if (c.classList && c.classList.length && nameOf(c.classList)) label(c);
        }
    }
    // An open <details> draws the chevron that points down (the stylesheets swap the glyph, .disc-chev),
    // which is another glyph with another middle: measured again when a <details> opens or closes.
    // `toggle` does not bubble, so it is caught on its way down.
    document.addEventListener('toggle', function (e) {
        var d = e.target, s = d && d.firstElementChild;
        if (d && d.tagName === 'DETAILS' && s && s.tagName === 'SUMMARY') relabel(s);
    }, true);

    /** One element and everything under it that carries a `bi-` class. */
    function scan(root) {
        if (!root || root.nodeType !== 1) return;
        if (root.classList && root.classList.length) apply(root);
        var list = root.querySelectorAll('[class*="bi-"]');
        for (var i = 0; i < list.length; i++) apply(list[i]);
    }

    var parsed = document.readyState !== 'loading';
    new MutationObserver(function (records) {
        for (var r = 0; r < records.length; r++) {
            var rec = records[r];
            if (rec.type === 'attributes') {
                // Only an element that is, or was, an icon: an unrelated class change costs a glance.
                var t = rec.target, cls = t.getAttribute('class') || '';
                if (cls.indexOf('bi-') >= 0 || cls.indexOf('fa-') >= 0) apply(t);
                continue;
            }
            if (!parsed) continue;
            // A label's words set or cleared beside an icon (a text node edited, added or taken away) —
            // beside it, or in a <span> beside it, so the element above is asked as well.
            if (rec.type === 'characterData') { var tp = rec.target.parentNode; relabel(tp); if (tp) relabel(tp.parentNode); continue; }
            for (var j = 0; j < rec.addedNodes.length; j++) scan(rec.addedNodes[j]);
            relabel(rec.target);
            relabel(rec.target.parentNode);
        }
    }).observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class'] });

    function whole() { parsed = true; scan(document.documentElement); }
    if (parsed) whole();
    else document.addEventListener('DOMContentLoaded', whole);

    // For documents the page builds itself (the page-content preview is an iframe filled through
    // srcdoc): the same rewrite, applied to a string of HTML.
    window.IconLibrary = {
        name: 'fontawesome',
        html: function (html) {
            var t = document.createElement('template');
            t.innerHTML = String(html || '');
            var list = t.content.querySelectorAll('[class*="bi-"]');
            for (var i = 0; i < list.length; i++) apply(list[i]);
            return t.innerHTML;
        },
    };
})();
