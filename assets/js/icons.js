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
        var want = name ? MAP[name].split(' ') : [];
        var drop = [], i;
        for (i = 0; i < cl.length; i++) {
            if (cl[i].lastIndexOf('fa-', 0) === 0 && want.indexOf(cl[i]) < 0) drop.push(cl[i]);
        }
        for (i = 0; i < drop.length; i++) cl.remove(drop[i]);
        for (i = 0; i < want.length; i++) if (!cl.contains(want[i])) cl.add(want[i]);
    }

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
            for (var j = 0; j < rec.addedNodes.length; j++) scan(rec.addedNodes[j]);
        }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

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
