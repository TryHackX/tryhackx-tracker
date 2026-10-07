# -*- coding: utf-8 -*-
"""Common

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

THE POLISH TERMS (1.74.0) -- one word for one thing, in every module. The panel's Settings are written
over many releases, and they said "czarna lista" and "blacklista", "admin" and "administrator", "twój" and
"Twój" side by side. tests/lang_plural_test.php holds the Polish half of every module to these:

  * the reader is addressed with a capital -- Ty, Twój (Twoja, Twoje, Twojego…), Ciebie, Cię, Ci, Tobie,
    Tobą -- in the middle of a sentence too, as in a letter (the mails always did); "ci, którzy" ("those")
    is not the reader and stays small
  * administrator -- never "admin" for a person ("Hasło administratora", "dla administratorów"); "Admin"
    stays only as the NAME of the panel's group and of the default account
  * blacklista / whitelista -- the tracker's two modes and their files -- never "czarna lista"
  * selektor (emoji, emotek) -- never "wybierak"
  * usuwać / usunąć -- "kasować" is not a second verb for the same thing in one sentence
  * the ellipsis is one character: … -- never three full stops
  * a count stands where its form is right for every number: a counted word has its three forms
    (X_one, X_few, X_many — includes/lang.php langPluralKey()), or the number is a label's value
    ("torrentów: 5", "(5)")
tools: scratchpad/tools_1740d/pl_terms_fix.py brings a module in line (dry run without --write).
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

# ── 1.74.0: the panel says a session that has ended — once, on every page ────
# assets/js/admin-common.js apiCall(): a 401 from a panel endpoint puts ONE bar at the top of the page — the sentence
# and a button that reloads it (a panel page asked for without a session shows the sign-in form in its place). The
# Reports page used to read that answer as an empty queue. `close` names the × of a panel toast for a screen reader.
add('js.common', {
    'close':           ('Close', 'Zamknij'),
    'session_expired': ('Your panel session has expired — sign in again to carry on.',
                        'Sesja panelu wygasła — zaloguj się ponownie, aby kontynuować.'),
    'sign_in_again':   ('Sign in again', 'Zaloguj się ponownie'),
})
# The Reports table's own error row (assets/js/admin.js): a list that could not be loaded says so — never "No
# reports found. Total: 0" — and the "…" that opens a row has a name a screen reader can say.
add('js.reports', {
    'load_failed': ('Could not load the list.', 'Nie udało się wczytać listy.'),
    'open_report': ('Open report #:id', 'Otwórz zgłoszenie nr :id'),
    'open_appeal': ('Open appeal #:id', 'Otwórz odwołanie nr :id'),
})

# ── 1.74.0: the pages, for the keyboard and a screen reader ────────────────────
# templates/layout.php: the first stop of the Tab key on every public page, past the menu to the content.
add('common', {
    'skip_to_content': ('Skip to the content', 'Przejdź do treści'),
})
# assets/js/people.js: what the other person would have to undo is asked in the row first (askInPlace), and an
# action that did not go through says so — the limit in its own words.
# Two keys the server asked for and the dictionary never had (1.74.0, QUAL-10) — a direct call of the API showed the
# key itself: the deletion limits' password question (api/admin/save_settings.php) and the review queue's refusal
# (api/admin/whitelist_review.php). tests/lang_plural_test.php now reads every __() / _h() / langFor() of api/
# and includes/ and fails on a key that is not defined.
add('api.settings', {
    'reauth_required_limits': ('Changing the deletion limits asks for the owner password. Enter it to confirm the change.',
                               'Zmiana limitów usuwania wymaga hasła właściciela. Podaj je, aby potwierdzić zmianę.'),
})
add('api.common', {
    'forbidden': ('Forbidden', 'Brak uprawnień'),
})
# assets/js/people.js pmWhy(): the one refusal of a message that had no sentence (the others map to theirs).
add('js.pm', {
    'why_login_required': ('Your session has ended — sign in again, then send it.',
                           'Sesja wygasła — zaloguj się ponownie, a potem wyślij wiadomość.'),
})
add('js.people', {
    'unfriend_q':   ('Remove from your friends?', 'Usunąć ze znajomych?'),
    'decline_q':    ('Decline the request?', 'Odrzucić zaproszenie?'),
    'rate_limited': ('Too many changes in a short time — try again in a while.',
                     'Za dużo zmian w krótkim czasie — spróbuj ponownie za chwilę.'),
})
