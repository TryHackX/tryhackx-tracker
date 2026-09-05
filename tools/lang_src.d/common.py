# -*- coding: utf-8 -*-
"""Common

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


# ── common ──────────────────────────────────────────────────────────────────
add('common', {
    'cancel':        ('Cancel', 'Anuluj'),
    'close':         ('Close', 'Zamknij'),
    'save':          ('Save', 'Zapisz'),
    'delete':        ('Delete', 'Usuń'),
    'loading':       ('Loading…', 'Wczytywanie…'),
    'none':          ('none', 'brak'),
    'error':         ('Something went wrong.', 'Coś poszło nie tak.'),
    'connection_error': ('Connection error.', 'Błąd połączenia.'),
    'yes':           ('Yes', 'Tak'),
    'no':            ('No', 'Nie'),
    'back_home':     ('Back to the front page', 'Wróć na stronę główną'),
    'sign_in':       ('Sign in', 'Zaloguj się'),
    'sign_out':      ('Sign out', 'Wyloguj się'),
    'register':      ('Register', 'Zarejestruj się'),
    'create_account': ('Create an account', 'Załóż konto'),
    'go_account':    ('Go to your account', 'Przejdź do swojego konta'),
    'check':         ('Check', 'Sprawdź'),
    'result':        ('Result', 'Wynik'),
    'language':      ('Language', 'Język'),
})
