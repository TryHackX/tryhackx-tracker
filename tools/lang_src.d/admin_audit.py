# -*- coding: utf-8 -*-
"""Admin — log (audit)

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


# ── admin — log (audit) ─────────────────────────────────────────────────────
add('a.audit', {
    'title':        ('Log', 'Log'),
    'subtitle':     ('who did what in this panel', 'kto co zrobił w tym panelu'),

    # toolbar
    'search_ph':    ('Action, summary, target, who, IP...', 'Akcja, opis, cel, kto, IP...'),
    'area':         ('Area', 'Obszar'),
    'area_all':     ('Every area', 'Każdy obszar'),
    'who':          ('Who', 'Kto'),
    'actor_any':    ('Anybody', 'Ktokolwiek'),
    'failed':       ('Only failures', 'Tylko nieudane'),
    'failed_title': ('Only the attempts that did not work — a failed sign-in, a refused action',
                     'Tylko próby, które się nie powiodły — nieudane logowanie, odrzucona akcja'),

    # table columns (narrow — keep the Polish just as short)
    'c_when':       ('When', 'Kiedy'),
    'c_what':       ('Action', 'Akcja'),
    'c_summary':    ('What happened', 'Co się stało'),
    'c_from':       ('From', 'Skąd'),
})
