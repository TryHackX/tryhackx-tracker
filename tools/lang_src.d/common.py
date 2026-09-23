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

# ── 1.66.0: the short name of a weekday ─────────────────────────────────────
# Beside the hour in the shoutbox ("pon 21:43"), read by userDisplayWeekday() in includes/db_clock.php.
# Numbered the way ISO-8601 and PHP's date('N') number them: 1 = Monday … 7 = Sunday. From the
# dictionary rather than a system locale on purpose: the site has never depended on which locales a
# server happens to have installed. The Polish is the ordinary dictionary abbreviation without its
# full stop — the owner's own example is "pon 21:43" — and lower case, as Polish writes day names.
# The browser has the same seven under `js.common.dow_*` (tools/lang_src.d/js.py).
add('common', {
    'dow_1': ('Mon', 'pon'),
    'dow_2': ('Tue', 'wt'),
    'dow_3': ('Wed', 'śr'),
    'dow_4': ('Thu', 'czw'),
    'dow_5': ('Fri', 'pt'),
    'dow_6': ('Sat', 'sob'),
    'dow_7': ('Sun', 'niedz'),
})
