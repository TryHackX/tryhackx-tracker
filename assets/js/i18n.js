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
    var strings = {}, lang = 'en';
    try {
        var node = document.getElementById('i18n-data');
        if (node) { var data = JSON.parse(node.textContent || '{}'); strings = data.strings || {}; lang = data.lang || 'en'; }
    } catch (e) { strings = {}; }
    function t(key, params) {
        var s = Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key;
        if (params) {
            Object.keys(params).forEach(function (k) {
                s = s.split(':' + k).join(String(params[k]));
            });
        }
        return s;
    }
    t.lang = lang;
    t.has = function (key) { return Object.prototype.hasOwnProperty.call(strings, key); };
    window.t = t;
})();
