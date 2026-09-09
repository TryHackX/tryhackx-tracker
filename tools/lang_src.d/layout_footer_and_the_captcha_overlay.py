# -*- coding: utf-8 -*-
"""Layout: footer and the captcha overlay

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── layout: footer and the CAPTCHA overlay ─────────────────────────────────
add('footer', {
    'version':     ('Version :v', 'Wersja :v'),
    'powered_by':  ('Powered by', 'Napędzane przez'),
    'by_author':   ('by', 'autorstwa'),
    'since':       ('since', 'od'),
    'cc0':         ('Content rights waived via CC0', 'Prawa do treści zrzeczone przez CC0'),
})
add('captcha', {
    'verify_human': ('Please verify you are human', 'Potwierdź, że jesteś człowiekiem'),
})
