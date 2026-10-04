/**
 * The client-side half of the dictionary.
 *
 * The page carries a JSON bundle of every `js.*` string for the active language (see
 * langJsBridge() in includes/lang.php); t() reads it. A miss returns the KEY, exactly like the
 * PHP side: a blank label tells nobody what is missing, `js.wl.nothing_waiting` on the screen says
 * where to look.
 *
 * Placeholders are `:name`, replaced from the second argument — the same convention as __().
 * Loaded before every other script, so t() is a plain global.
 *
 * t() IS A PLAIN STRING; WHAT IS WRITTEN INTO THE PAGE CARRIES ITS KEY THERE (1.73.0). The live language switch
 * (assets/js/lang-swap.js) rewrites what the server wrote from a fresh render of the page in the other language — and
 * a node a script made has no counterpart in that render, so it kept the language it was made in: a conversation's
 * tooltips (the owner's example), a password checklist, a table's labels, a toast. So where a script WRITES a word into
 * the page it asks for t.key(key, params) instead of t(): the word that knows its key, which the writer stores ON THE
 * ELEMENT — data-i18n (its text), data-i18n-a ("title=js.x aria-label=js.y"), data-i18n-p (the placeholders, JSON) —
 * through textContent, title, placeholder, setAttribute() (data-tip, aria-label, …), or handed to append() /
 * prepend() / replaceChildren() / before() / after() (as a <span> that says it), or put in markup by the templates'
 * escapers (esc() in admin.js and admin-common.js, escHtml() in app.js: t.html()). A t.key() word goes INTO THE PAGE —
 * directly, or through a helper that writes it (el(), showToast(), confirmAction() …) — and nowhere else: it is an
 * object (a String that says itself in the language loaded now), so a comparison, a `typeof`, a `switch`, a Map key
 * asks t(), which stays a primitive string for everything that is not the page. What does NOT keep a key: words glued
 * into a longer string (`t('js.common.copied') + ': ' + n`, a template literal) — such a line hands the pieces over
 * separately (`el.replaceChildren(t.key('js.common.copied'), ': ' + n)`) or, in markup, says t.html(key, params).
 *
 *   t(key, params)                   the words, a plain string (a miss gives the key)
 *   t.key(key, params)               the word to WRITE into the page: it leaves its key on the element
 *   t.words(key, params)             the same as t()
 *   t.text(node, key, params)        the node's own words — beside an icon, the icon stays
 *   t.attr(node, name, key, params)  one attribute
 *   t.node(key, params) / t.html(key, params)   a <span> that says a key, as a node / as markup for a string template
 *   t.child(x)                       what append() would make of x: a t.key() word a <span>, a string a text node
 *
 * The keys live on the node, so a node that is cloned, moved, or taken out and put back keeps them, and nothing has to
 * be registered or forgotten. A plain word written later where a key was drops that key: the node no longer says it.
 * A swap asks t.ours() — with the old bundle still loaded — which keyed words still say what the old dictionary says
 * (a node whose script has written something else since is left alone), loads the new bundle, and t.say()s them.
 * t.relabel(node) does the same later for a node that was out of the page while the language changed.
 */
(function () {
    'use strict';
    var strings = {}, prev = {}, lang = 'en', swap = false;
    // Read (or re-read) the bundle the page carries. assets/js/lang-swap.js replaces the contents
    // of #i18n-data with the other language's bundle and calls this, so a string asked for after
    // an in-place switch is answered in the language now on the screen. The bundle it replaces is
    // kept as `prev`: t.relabel() recognises a word of the language the page has just left by it.
    function load() {
        try {
            var node = document.getElementById('i18n-data');
            if (!node) return;
            var data = JSON.parse(node.textContent || '{}');
            prev = strings;
            strings = data.strings || {};
            lang = data.lang || 'en';
            swap = data.swap === true;
        } catch (e) { strings = {}; }
    }
    load();
    prev = {};
    function say(from, key, params) {
        var s = Object.prototype.hasOwnProperty.call(from, key) ? from[key] : key;
        if (params && typeof params === 'object') {
            // Longest name first. ":page" is a prefix of ":pages", so replacing in the order the
            // caller happened to write them turned "Page :page of :pages" into "Page 1 of 1s" — the
            // pager on every list said "of 1s" whatever the count was. PHP's strtr() has always
            // matched longest-first; this is the same rule, spelled out.
            Object.keys(params).sort(function (a, b) { return b.length - a.length; }).forEach(function (k) {
                var v = params[k];
                // A placeholder that is itself a word with a key — a t() word (it says itself) or, read back
                // from a node, its {$t, $p} (see enc() below) — is said from the same dictionary.
                if (v && typeof v === 'object' && !(v instanceof String) && typeof v.$t === 'string') v = say(from, v.$t, v.$p);
                s = s.split(':' + k).join(String(v));
            });
        }
        return s;
    }
    function words(key, params) { return say(strings, key, params); }

    var TEXT = 'data-i18n', ATTRS = 'data-i18n-a', ARGS = 'data-i18n-p', PHRASE = 'data-i18n-ph';
    // A word that knows its key (t.key()): a String (an object one) that says itself in the language loaded NOW
    // whenever it becomes text, and whose key (key, params) goes with it into the element it is written into, see
    // below. One may be another's placeholder (`t.key('js.app.stars_mine_title', {stars: t.key('js.app.stars_one')})`):
    // stored on a node, the placeholders are JSON, and there a t.key() word is its key, {$t, $p}.
    class Keyed extends String {
        constructor(key, params) { super(words(key, params)); this.key = key; this.params = params; }
        toString() { return words(this.key, this.params); }
        valueOf() { return words(this.key, this.params); }
        toJSON() { return words(this.key, this.params); }
    }
    // The words: a primitive string, for everything (a comparison, a switch, a Map key, a payload).
    function t(key, params) { return words(key, params); }
    // The word to write into the page: it leaves its key on the element (see WHERE A SCRIPT WRITES WORDS below).
    t.key = function (key, params) { return new Keyed(key, params); };
    t.words = words;
    t.isKey = function (v) { return v instanceof Keyed; };
    /**
     * A sentence a server answered with (an API's `error`, `message`), as the t() word of the entry under `prefix`
     * that says exactly that — so it keeps its key and follows a language switch like a word the script wrote. The
     * page's bundle must carry that prefix (langJsBridge()); anything else comes back as it was. Only for a
     * server's answers, never for what people wrote.
     */
    t.find = function (text, prefix) {
        if (text == null || text instanceof Keyed || typeof text !== 'string' || text === '') return text;
        var found = null;
        for (var k in strings) {
            if (!Object.prototype.hasOwnProperty.call(strings, k) || (prefix && k.indexOf(prefix) !== 0)) continue;
            var v = strings[k];
            if (v === text) return new Keyed(k);
            // A sentence with placeholders (":error", ":n"): its words as a pattern, each placeholder a group — the
            // server's values come back as the word's placeholders. At least a few letters of its own, so a value
            // that is a placeholder and little else does not claim every sentence.
            if (found || v.indexOf(':') === -1 || v.replace(/:[a-z][a-z0-9_]*/gi, '').replace(/[^A-Za-z\u00C0-\u024F]/g, '').length < 4) continue;
            var names = [];
            var src = v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/:([a-z][a-z0-9_]*)/gi, function (m, n) { names.push(n); return '([\\s\\S]*?)'; });
            var m = new RegExp('^' + src + '$').exec(text);
            if (m) { var p = {}; names.forEach(function (n, i) { p[n] = m[i + 1]; }); found = new Keyed(k, p); }
        }
        return found || text;
    };
    t.lang = lang;
    t.swap = swap;      // the operator has switched the in-place language swap on
    t.has = function (key) { return Object.prototype.hasOwnProperty.call(strings, key); };
    t.reload = function () { load(); t.lang = lang; t.swap = swap; };

    /* ── words a script writes, kept with their keys (1.73.0) ── */

    // The browser's own writers, kept before they are taught about keys below: the helpers here write
    // through these, so writing a key's words never drops the key it is writing.
    var nativeText = Object.getOwnPropertyDescriptor(Node.prototype, 'textContent').set;
    var nativeSetAttr = Element.prototype.setAttribute;
    // Placeholders as JSON, a t() word among them as its key ({$t, $p}) — never as its words.
    function enc(params) {
        return JSON.stringify(params, function (k, v) {
            var o = this[k];
            return o instanceof Keyed ? { $t: o.key, $p: o.params } : v;
        });
    }
    function argsOf(node) {
        var s = node.getAttribute(ARGS);
        if (!s) return undefined;
        try { return JSON.parse(s); } catch (e) { return undefined; }
    }
    // One set of placeholders per node, shared by its text and its attributes: a second key's placeholders are
    // MERGED in (a button's text and its tooltip usually name the same thing), never written over the first's.
    function setArgs(node, params) {
        if (!params || typeof params !== 'object' || !Object.keys(params).length) return;
        try { nativeSetAttr.call(node, ARGS, enc(Object.assign(argsOf(node) || {}, params))); } catch (e) { /* not JSON: kept as words */ }
    }
    function pairsOf(node) {
        var s = node.getAttribute(ATTRS);
        if (!s) return [];
        return s.split(' ').map(function (p) { var at = p.indexOf('='); return at > 0 ? [p.slice(0, at), p.slice(at + 1)] : null; })
            .filter(Boolean);
    }
    function setPair(node, name, key) {
        var list = pairsOf(node).filter(function (p) { return p[0] !== name; });
        if (key) list.push([name, key]);
        if (list.length) nativeSetAttr.call(node, ATTRS, list.map(function (p) { return p[0] + '=' + p[1]; }).join(' '));
        else node.removeAttribute(ATTRS);
    }
    /** A node's own words: all of its text when it holds nothing else, else its first text beside its icon. */
    function ownTextOf(node) {
        if (!node.firstElementChild) return node.textContent;
        for (var c = node.firstChild; c; c = c.nextSibling) if (c.nodeType === 3 && c.nodeValue.trim()) return c.nodeValue;
        return '';
    }
    function setOwnText(node, s) {
        if (!node.firstElementChild) { if (node.textContent !== s) nativeText.call(node, s); return; }
        var done = false;
        for (var c = node.firstChild; c; c = c.nextSibling) {
            if (c.nodeType !== 3 || !c.nodeValue.trim()) continue;
            if (done) { c.nodeValue = ''; continue; }
            var v = c.nodeValue, lead = v.match(/^\s*/)[0], trail = v.match(/\s*$/)[0];
            c.nodeValue = lead + s + trail;
            done = true;
        }
        if (!done) node.appendChild(document.createTextNode((node.lastChild && node.lastChild.nodeType === 1 ? ' ' : '') + s));
    }
    function setAttr(node, name, s) {
        if (name === 'value' && 'value' in node) node.value = s;
        nativeSetAttr.call(node, name, s);
    }
    var norm = function (s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); };

    t.text = function (node, key, params) {
        if (!node) return node;
        nativeSetAttr.call(node, TEXT, key);
        setArgs(node, params);
        setOwnText(node, words(key, argsOf(node)));
        return node;
    };
    t.attr = function (node, name, key, params) {
        if (!node) return node;
        if (name && typeof name === 'object') {          // t.attr(node, { title: 'js.x', 'aria-label': 'js.y' }, params)
            Object.keys(name).forEach(function (n) { t.attr(node, n, name[n], key); });
            return node;
        }
        setPair(node, name, key);
        if (params !== undefined) setArgs(node, params);
        setAttr(node, name, words(key, argsOf(node)));
        return node;
    };
    /** A <span> that says a key — what append() and its kin put in for a t() word. */
    t.node = function (key, params) {
        if (key instanceof Keyed) { params = key.params; key = key.key; }
        return t.text(document.createElement('span'), key, params);
    };
    /** A child for appendChild(): a t() word becomes t.node(), any other string or number a text node, a node stays a node. */
    t.child = function (c) {
        if (c instanceof Keyed) return t.node(c);
        return (typeof c === 'string' || typeof c === 'number' || c instanceof String) ? document.createTextNode(String(c)) : c;
    };
    var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); };
    /** Markup for a string template: a <span> that says the key (a t() word may stand for key + params). */
    t.html = function (key, params) {
        if (key instanceof Keyed) { params = key.params; key = key.key; }
        var p = params && typeof params === 'object' && Object.keys(params).length ? params : null;
        var s;
        try { s = p ? enc(p) : ''; } catch (e) { s = ''; p = null; }
        return '<span ' + TEXT + '="' + esc(key) + '"' + (p && s ? ' ' + ARGS + '="' + esc(s) + '"' : '') + '>'
            + esc(words(key, params)) + '</span>';
    };
    /*
     * A SENTENCE WITH PEOPLE IN IT (assets/js/avatar.js's userAvatarPhrase(): ":reporter reported :reported", "by
     * :user" — a picture and a name in each person's place, wherever the language puts it). The words between the
     * people are pieces of one sentence, not keys of their own, so the sentence's key goes on the element that holds
     * it (data-i18n-ph, its placeholders in data-i18n-p — a person's place is \u0001<n>\u0001) and each person on
     * its child (data-slot="<n>"): a swap writes the new sentence's pieces round the same people, in the new order.
     */
    var SLOT = /\u0001(\d+)\u0001/;
    t.phraseMark = function (node, word) {
        if (!node || !(word instanceof Keyed)) return node;
        nativeSetAttr.call(node, PHRASE, word.key);
        setArgs(node, word.params);
        return node;
    };
    function phraseWords(node) {
        var s = '';
        for (var c = node.firstChild; c; c = c.nextSibling) if (c.nodeType === 3) s += c.nodeValue;
        return s;
    }
    function sayPhrase(node, sentence) {
        var who = {};
        for (var c = node.firstElementChild; c; c = c.nextElementSibling) if (c.hasAttribute('data-slot')) who[c.getAttribute('data-slot')] = c;
        var kids = [];
        String(sentence).split(SLOT).forEach(function (part, i) {
            if (i % 2 === 0) { if (part) kids.push(document.createTextNode(part)); } else if (who[part]) kids.push(who[part]);
        });
        while (node.firstChild) node.removeChild(node.firstChild);
        kids.forEach(function (k) { node.appendChild(k); });
    }

    /** A template's text escaper that keeps a t() word's key (t.html()); anything else escaped as words. */
    t.esc = function (v) { return v instanceof Keyed ? t.html(v) : esc(v == null ? '' : v); };
    /**
     * Keyed ATTRIBUTES for a string template: ` title="…" data-i18n-a="title=js.x"` (+ data-i18n-p), from
     * t.ah('title', 'js.common.copied', params) or t.ah({ title: …, 'aria-label': … }, params) — one element's
     * attributes in one call, since an element can carry each attribute once.
     */
    t.ah = function (name, key, params) {
        var map = {};
        if (name && typeof name === 'object') { map = name; params = key; } else map[name] = key;
        var names = Object.keys(map), out = '';
        names.forEach(function (n) { out += ' ' + n + '="' + esc(words(map[n], params)) + '"'; });
        out += ' ' + ATTRS + '="' + esc(names.map(function (n) { return n + '=' + map[n]; }).join(' ')) + '"';
        if (params && typeof params === 'object' && Object.keys(params).length) {
            try { out += ' ' + ARGS + '="' + esc(enc(params)) + '"'; } catch (e) { /* words only */ }
        }
        return out;
    };

    /*
     * WHERE A SCRIPT WRITES WORDS, taught to keep a t() word's key: a node's text (textContent), its title
     * and placeholder, setAttribute() of any attribute, and the strings handed to append(), prepend(),
     * replaceChildren(), before() and after() — a t() word among those becomes a <span> that says it
     * (t.node()). Any other value is written exactly as the browser writes it — and a plain word written
     * where a key was drops that key: the node no longer says it.
     */
    function teachSetter(proto, prop, slot) {
        var d = proto && Object.getOwnPropertyDescriptor(proto, prop);
        if (!d || !d.set || !d.configurable) return;
        Object.defineProperty(proto, prop, {
            configurable: true, enumerable: d.enumerable, get: d.get,
            set: function (v) {
                var keyed = v instanceof Keyed;
                d.set.call(this, keyed ? String(v) : v);
                if (this.nodeType !== 1) return;
                if (slot === 'text') {
                    if (keyed) { nativeSetAttr.call(this, TEXT, v.key); setArgs(this, v.params); }
                    else if (this.hasAttribute(TEXT)) { this.removeAttribute(TEXT); bare(this); }
                } else if (keyed) { setPair(this, slot, v.key); setArgs(this, v.params); }
                else if (this.hasAttribute(ATTRS)) { setPair(this, slot, null); bare(this); }
            },
        });
    }
    // A node left with no key keeps no placeholders either: a label put back from a copy after a script's
    // "Working… 12 left" is exactly that copy again (lang-swap.js knows a server button by its markup).
    function bare(node) {
        if (node.hasAttribute(ARGS) && !node.hasAttribute(TEXT) && !node.hasAttribute(ATTRS) && !node.hasAttribute(PHRASE)) node.removeAttribute(ARGS);
    }
    teachSetter(Node.prototype, 'textContent', 'text');
    teachSetter(HTMLElement.prototype, 'innerText', 'text');
    teachSetter(HTMLElement.prototype, 'title', 'title');
    teachSetter(HTMLInputElement.prototype, 'placeholder', 'placeholder');
    teachSetter(HTMLTextAreaElement.prototype, 'placeholder', 'placeholder');
    Element.prototype.setAttribute = function (name, value) {
        if (value instanceof Keyed) {
            nativeSetAttr.call(this, name, String(value));
            setPair(this, String(name).toLowerCase(), value.key);
            setArgs(this, value.params);
            return;
        }
        nativeSetAttr.call(this, name, value);
        if (name !== ATTRS && name !== TEXT && name !== ARGS && this.hasAttribute(ATTRS)) { setPair(this, String(name).toLowerCase(), null); bare(this); }
    };
    ['append', 'prepend', 'replaceChildren', 'before', 'after'].forEach(function (m) {
        [Element.prototype, DocumentFragment.prototype].forEach(function (proto) {
            var orig = proto[m];
            if (typeof orig !== 'function') return;
            proto[m] = function () {
                var args = Array.prototype.map.call(arguments, function (a) { return a instanceof Keyed ? t.node(a) : a; });
                return orig.apply(this, args);
            };
        });
    });

    function keyed(root) {
        var out = [];
        if (!root) return out;
        if (root.nodeType === 1 && (root.hasAttribute(TEXT) || root.hasAttribute(ATTRS) || root.hasAttribute(PHRASE))) out.push(root);
        if (root.querySelectorAll) out.push.apply(out, root.querySelectorAll('[' + TEXT + '],[' + ATTRS + '],[' + PHRASE + ']'));
        return out;
    }
    /**
     * Which keyed words under `root` still say what `dict` (the language on the page) says — the ones a script wrote and
     * has not written over since. Asked BEFORE a swap's walk, with the old bundle still loaded.
     */
    function mine(root, dict) {
        var list = [];
        keyed(root || document.body).forEach(function (n) {
            var p = argsOf(n), e = { n: n, text: null, attrs: [], ph: null };
            var k = n.getAttribute(TEXT);
            if (k && norm(ownTextOf(n)) === norm(say(dict, k, p))) e.text = k;
            pairsOf(n).forEach(function (pr) { if (n.getAttribute(pr[0]) === say(dict, pr[1], p)) e.attrs.push(pr); });
            var ph = n.getAttribute(PHRASE);
            if (ph && norm(phraseWords(n)) === norm(say(dict, ph, p).split(SLOT).filter(function (x, i) { return i % 2 === 0; }).join(''))) e.ph = ph;
            if (e.text || e.attrs.length || e.ph) list.push(e);
        });
        return list;
    }
    function sayAll(list) {
        (list || []).forEach(function (e) {
            var p = argsOf(e.n);
            if (e.text) setOwnText(e.n, words(e.text, p));
            e.attrs.forEach(function (pr) { setAttr(e.n, pr[0], words(pr[1], p)); });
            if (e.ph) sayPhrase(e.n, words(e.ph, p));
        });
    }
    t.ours = function (root) { return mine(root, strings); };
    t.say = sayAll;
    /** A node (and what is in it) that was out of the page while the language changed: said again if it was ours. */
    t.relabel = function (root) {
        sayAll(mine(root, prev).concat(mine(root, strings)));
        return root;
    };
    window.t = t;
})();
