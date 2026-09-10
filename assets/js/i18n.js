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
 */
(function () {
    'use strict';
    var strings = {}, lang = 'en', swap = false;
    // Read (or re-read) the bundle the page carries. assets/js/lang-swap.js replaces the contents
    // of #i18n-data with the other language's bundle and calls this, so a string asked for after
    // an in-place switch is answered in the language now on the screen.
    function load() {
        try {
            var node = document.getElementById('i18n-data');
            if (!node) return;
            var data = JSON.parse(node.textContent || '{}');
            strings = data.strings || {};
            lang = data.lang || 'en';
            swap = data.swap === true;
        } catch (e) { strings = {}; }
    }
    load();
    function t(key, params) {
        var s = Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key;
        if (params) {
            // Longest name first. ":page" is a prefix of ":pages", so replacing in the order the
            // caller happened to write them turned "Page :page of :pages" into "Page 1 of 1s" — the
            // pager on every list said "of 1s" whatever the count was. PHP's strtr() has always
            // matched longest-first; this is the same rule, spelled out.
            Object.keys(params).sort(function (a, b) { return b.length - a.length; }).forEach(function (k) {
                s = s.split(':' + k).join(String(params[k]));
            });
        }
        return s;
    }
    t.lang = lang;
    t.swap = swap;      // the operator has switched the in-place language swap on
    t.has = function (key) { return Object.prototype.hasOwnProperty.call(strings, key); };
    t.reload = function () { load(); t.lang = lang; t.swap = swap; };
    window.t = t;
})();
