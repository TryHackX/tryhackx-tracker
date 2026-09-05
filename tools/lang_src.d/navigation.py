# -*- coding: utf-8 -*-
"""Navigation

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


# ── navigation ──────────────────────────────────────────────────────────────
add('nav', {
    'home':         ('Home', 'Strona główna'),
    'info':         ('Info', 'Informacje'),
    'terms':        ('Terms', 'Regulamin'),
    'whitelist':    ('Whitelist', 'Whitelista'),
    'report':       ('Report', 'Zgłoszenie'),
    'status':       ('Status', 'Status'),
    'transparency': ('Transparency', 'Przejrzystość'),
    'stats':        ('Stats', 'Statystyki'),
    'search':       ('Search', 'Szukaj'),
    'account':      ('Account', 'Konto'),
})
