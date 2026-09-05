# -*- coding: utf-8 -*-
"""Transparency

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


# ── transparency ────────────────────────────────────────────────────────────
add('transparency', {
    'h1':      ('Transparency Report', 'Raport przejrzystości'),
    'intro':   ('This page shows all organizations that have submitted removal requests and their '
                'outcomes.',
                'Ta strona pokazuje wszystkie organizacje, które złożyły wnioski o usunięcie, oraz '
                'wyniki tych wniosków.'),
    'loading': ('Loading data...', 'Wczytywanie danych...'),
    'company': ('Company / Organization', 'Firma / Organizacja'),
    'represented': ('Represented Entity', 'Reprezentowany podmiot'),
    'total':   ('Total Requests', 'Wszystkich wniosków'),
    'reviewed': ('Reviewed', 'Rozpatrzonych'),
    'blocked': ('Blocked', 'Zablokowanych'),
    'pending': ('Awaiting Review', 'Oczekujących'),
})
