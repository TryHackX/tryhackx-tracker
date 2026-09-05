# -*- coding: utf-8 -*-
"""Page titles (<title> and the browser tab)

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


# ── page titles (<title> and the browser tab) ───────────────────────────────
add('title', {
    'home':         ('Home', 'Strona główna'),
    'info':         ('Info', 'Informacje'),
    'tos':          ('Terms', 'Regulamin'),
    'report':       ('Report', 'Zgłoszenie'),
    'status':       ('Status', 'Status'),
    'transparency': ('Transparency', 'Przejrzystość'),
    'unsubscribe':  ('Unsubscribe', 'Wypisanie się'),
    'whitelist':    ('Whitelist', 'Whitelista'),
    'stats':        ('Stats', 'Statystyki'),
    'login':        ('Sign in', 'Logowanie'),
    'register':     ('Register', 'Rejestracja'),
    'account':      ('Account', 'Konto'),
    'reset':        ('Password reset', 'Reset hasła'),
    'verify':       ('Email verification', 'Weryfikacja adresu e-mail'),
    'emailchange':  ('Email change', 'Zmiana adresu e-mail'),
    'search':       ('Search', 'Szukaj'),
    'adminlogin':   ('Admin sign in', 'Logowanie administratora'),
    'notfound':     ('Not found', 'Nie znaleziono'),
})
