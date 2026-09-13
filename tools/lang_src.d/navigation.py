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


# ── recovered 2026-09-13 ────────────────────────────────────────────────────
# These strings were added straight to lang/en.php and lang/pl.php between 1.43 and 1.50 and never to
# these sources, so the first regeneration since then (1.50.1) dropped all of them. They are written
# back here, where the generator reads from. tests/lang_test.php now checks that the generated files
# match the sources, so a string added to the generated file alone fails the battery on the spot.
add('', {
    'nav.members': ('Members',
        'Użytkownicy'),
})
