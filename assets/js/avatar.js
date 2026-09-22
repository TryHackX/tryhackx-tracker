/**
 * The picture beside a name, built in the browser exactly as includes/usermedia.php builds it on the
 * server — for every row a script draws from JSON rather than the server from a template, so the
 * two kinds of row cannot draw two different pictures.
 *
 * Its own file (1.63.0 phase B; phase A had it at the top of assets/js/app.js) because the panel
 * draws pictures too and the panel does not load app.js. One copy, loaded by templates/layout.php
 * before app.js and by the panel pages that list people.
 *
 * ── what a row hands it ────────────────────────────────────────────────────────────────────────
 * `u` is either anything carrying `username` and `avatar_sha` (userAvatarUrl()'s input), or a row an
 * endpoint shaped for the browser, which carries the picture's ADDRESS as `avatar` — built on the
 * server by userAvatarField(), because most of those endpoints deliberately send a name and never the
 * account id behind it. An address is only ever read back if it is one of the three shapes the server
 * writes (an account's square or the site's default, the generated letter, the site's own mark);
 * anything else draws the letter. `avatar: ''` is the server saying there is nothing to draw.
 *
 * The square is twice the CSS size (sharp on a dense screen), and `srcset` names the one each density
 * should take — the same string userAvatarSrcset() writes. The letter's colour is FNV-1a over the
 * lower-cased name, modulo twelve: the same four lines as the PHP, so a person has the same colour
 * whichever side drew them.
 *
 * ── the two facts the page supplies ────────────────────────────────────────────────────────────
 * Whether pictures are on at all, and the site default's address prefix: APP_MEDIA on the public
 * pages (templates/layout.php), or this script tag's own data-avatars / data-def / data-base in the
 * panel, which has no APP_* globals and should not grow any for this.
 *
 * userAvatarImg() returns an <img> element, or null while pictures are switched off — nothing is
 * drawn rather than a letter nobody asked for, which is what userAvatarHtml() does too.
 */
(function () {
    'use strict';
    var SIZES = [64, 128, 256];
    // Read while this file is executing: afterwards document.currentScript is somebody else's.
    var tag = document.currentScript;
    function media() {
        if (typeof APP_MEDIA === 'object' && APP_MEDIA) return APP_MEDIA;
        var d = tag && tag.dataset;
        return d ? { avatars: d.avatars === '1', def: d.def || '' } : { avatars: false, def: '' };
    }
    function base() {
        if (typeof APP_BASE === 'string') return APP_BASE;
        return (tag && tag.dataset && typeof tag.dataset.base === 'string') ? tag.dataset.base : '/';
    }
    function squareAtLeast(px) {
        for (var i = 0; i < SIZES.length; i++) if (SIZES[i] >= px) return SIZES[i];
        return SIZES[SIZES.length - 1];
    }
    function variantFor(css) { return squareAtLeast(css * 2); }
    function letter(name) { var m = String(name || '').match(/[A-Za-z0-9]/); return m ? m[0].toUpperCase() : 'U'; }
    function colour(name) {
        // Usernames are ASCII, so a character code is the byte PHP's ord() reads.
        var s = String(name || '').toLowerCase(), h = 2166136261;
        for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i) & 0xFF; h = Math.imul(h, 16777619) >>> 0; }
        return (h >>> 0) % 12;
    }
    function generated(name) { return base() + 'api.php?endpoint=user_avatar_default&l=' + letter(name) + '&c=' + colour(name); }
    function siteUrl() { return base() + 'assets/img/favicon.svg'; }
    function quote(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    /** userAvatarUrlKind(): which of the server's three shapes an address is, or null. */
    function kind(u) {
        u = String(u || '');
        var b = quote(base());
        var m = new RegExp('^(' + b + 'api\\.php\\?endpoint=user_media&h=[0-9a-f]{16}&s=)[0-9]{1,3}$').exec(u);
        if (m) return { kind: 'media', prefix: m[1] };
        if (new RegExp('^' + b + 'api\\.php\\?endpoint=user_avatar_default&l=[A-Z0-9]&c=(?:[0-9]|1[01])$').test(u)) return { kind: 'letter' };
        return u === siteUrl() ? { kind: 'site' } : null;
    }
    /** userAvatarSized(): the same picture at the size it is drawn at, or null for a foreign address. */
    function sized(u, css) {
        var k = kind(u);
        if (!k) return null;
        return k.kind === 'media' ? k.prefix + variantFor(css) : String(u);
    }
    /** userAvatarSrcset(): each density's square, at the highest density it serves; '' for one file. */
    function srcset(u, css) {
        var k = kind(u);
        if (!k || k.kind !== 'media') return '';
        var pairs = [];
        [1, 2, 3].forEach(function (d) {
            var v = squareAtLeast(css * d);
            if (pairs.length && pairs[pairs.length - 1][0] === v) pairs[pairs.length - 1][1] = d;
            else pairs.push([v, d]);
        });
        return pairs.length < 2 ? '' : pairs.map(function (p) { return k.prefix + p[0] + ' ' + p[1] + 'x'; }).join(', ');
    }
    function url(u, css) {
        u = u || {};
        var size = Number(css) || 32;
        // A row shaped by the server: its address, redrawn at this size.
        if (typeof u.avatar === 'string') return (u.avatar !== '' && sized(u.avatar, size)) || generated(u.username);
        var m = media();
        if (!m.avatars) return generated(u.username);
        var v = variantFor(size);
        var own = String(u.avatar_sha || '').toLowerCase();
        if (/^[0-9a-f]{40}$/.test(own)) return base() + 'api.php?endpoint=user_media&h=' + own.slice(0, 16) + '&s=' + v;
        if (/^[0-9a-f]{16}$/.test(String(m.def || ''))) return base() + 'api.php?endpoint=user_media&h=' + m.def + '&s=' + v;
        return generated(u.username);
    }
    function img(u, css, cls) {
        u = u || {};
        if (!media().avatars) return null;
        if (typeof u.avatar === 'string' && u.avatar === '') return null;
        var size = Math.max(8, Math.min(512, Number(css) || 32));
        // The attributes in the order userAvatarHtml() writes them, so a row drawn here and the
        // same row drawn by the server serialise to the same markup.
        var el = document.createElement('img');
        el.className = ((cls || 'avatar') + ' js-avatar').trim();
        var src = url(u, size);
        el.setAttribute('src', src);
        var ss = srcset(src, size);
        if (ss) el.setAttribute('srcset', ss);
        var fb = generated(u.username);
        if (fb !== src) el.dataset.fallback = fb;
        el.width = size;
        el.height = size;
        el.alt = '';
        el.loading = 'lazy';
        el.decoding = 'async';
        return el;
    }
    /**
     * Point a picture this file (or the server) drew at a new address — the owner has just replaced
     * or removed theirs — keeping its size, its class and its letter. `src` and `srcset` together:
     * change only the first and a dense screen goes on showing the old picture from the second.
     */
    function set(el, u) {
        if (!el || typeof u !== 'string' || u === '') return;
        var size = Number(el.getAttribute('width')) || 32;
        var src = sized(u, size);
        if (!src) return;
        var was = el.getAttribute('src') || '';
        // The letter it falls back to: the one it carries or, when it WAS the letter, that one.
        var fb = el.getAttribute('data-fallback') || ((kind(was) || {}).kind === 'letter' ? was : '');
        el.setAttribute('src', src);
        var ss = srcset(src, size);
        if (ss) el.setAttribute('srcset', ss); else el.removeAttribute('srcset');
        if (fb && fb !== src) el.setAttribute('data-fallback', fb); else el.removeAttribute('data-fallback');
    }
    /**
     * A translated sentence with people in it, as nodes — so the picture lands right before each
     * name wherever the language put the name (":reporter reported :reported", "autor: :user").
     * The sentence is asked for with userAvatarSlot(i) in each person's place and `people[i]` is what
     * goes there: the picture and the name, as nodes or strings. Nothing is parsed as HTML.
     */
    function slot(i) { return '\u0001' + i + '\u0001'; }
    function phrase(text, people) {
        var frag = document.createDocumentFragment();
        String(text || '').split(/\u0001(\d+)\u0001/).forEach(function (part, i) {
            if (i % 2 === 0) { if (part) frag.appendChild(document.createTextNode(part)); return; }
            // One person is one unit: `.av-who` does not wrap, so a narrow line can never leave a
            // picture at the end of one line and its name at the start of the next.
            var who = document.createElement('span');
            who.className = 'av-who';
            var p = (people || [])[Number(part)];
            (Array.isArray(p) ? p : [p]).forEach(function (n) {
                if (n === null || n === undefined || n === '') return;
                who.appendChild(typeof n === 'string' ? document.createTextNode(n) : n);
            });
            frag.appendChild(who);
        });
        return frag;
    }
    window.userAvatarUrl = url;
    window.userAvatarImg = img;
    window.userAvatarSet = set;
    window.userAvatarSlot = slot;
    window.userAvatarPhrase = phrase;
    // A picture that cannot be loaded becomes its letter, once. Captured on the document because an
    // image's error event does not bubble — and delegated, because an inline onerror= is exactly what
    // this site's policy forbids. The server-drawn element carries the same data-fallback. The srcset
    // goes first: while it is there the browser keeps choosing from it and never looks at src.
    document.addEventListener('error', function (e) {
        var t = e.target;
        if (!t || t.tagName !== 'IMG' || !t.classList || !t.classList.contains('js-avatar')) return;
        var fb = t.getAttribute('data-fallback');
        if (fb && t.getAttribute('src') !== fb) {
            t.removeAttribute('data-fallback');
            t.removeAttribute('srcset');
            t.setAttribute('src', fb);
        }
    }, true);
})();
