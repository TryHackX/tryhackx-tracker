# -*- coding: utf-8 -*-
"""404 / verify / email change

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


# ── 404 / verify / email change ─────────────────────────────────────────────
add('notfound', {
    'h1':   ('404 — Not found', '404 — Nie znaleziono'),
    'body': ('This page does not exist on :site.', 'Ta strona nie istnieje na :site.'),
})
add('verify', {
    'h1':      ('Email verification', 'Weryfikacja adresu e-mail'),
    'ok':      ('Your email address is now <strong>verified</strong>. Thank you!',
                'Twój adres e-mail jest już <strong>zweryfikowany</strong>. Dziękujemy!'),
    'bad':     ('This verification link is invalid, expired or was already used.',
                'Ten link weryfikacyjny jest nieprawidłowy, wygasł albo został już użyty.'),
    'bad_hint': ('You can request a fresh link from your <a href=":url">account page</a> (links are '
                 'valid for 72 hours).',
                 'Nowy link możesz zamówić na <a href=":url">stronie swojego konta</a> (linki są '
                 'ważne 72 godziny).'),
})
add('emailchange', {
    'h1':       ('Email change', 'Zmiana adresu e-mail'),
    'step1':    ('Step 1 of 2 confirmed.', 'Krok 1 z 2 potwierdzony.'),
    'step1_note': ('A confirmation link was just sent to the <strong>new</strong> address '
                   '(<strong>:email</strong>). Open it there to finish the change.',
                   'Link potwierdzający został właśnie wysłany na <strong>nowy</strong> adres '
                   '(<strong>:email</strong>). Otwórz go tam, aby dokończyć zmianę.'),
    'done':     ('Done — your account email is now <strong>:email</strong> (verified).',
                 'Gotowe — adres e-mail konta to teraz <strong>:email</strong> (zweryfikowany).'),
    'removed':  ('Confirmed — the email address was <strong>removed</strong> from your account.',
                 'Potwierdzono — adres e-mail został <strong>usunięty</strong> z Twojego konta.'),
    'taken':    ('Another account claimed this address in the meantime — the change was not applied.',
                 'W międzyczasie inne konto zajęło ten adres — zmiana nie została wprowadzona.'),
    'bad':      ('This confirmation link is invalid, expired or was already used.',
                 'Ten link potwierdzający jest nieprawidłowy, wygasł albo został już użyty.'),
    'bad_hint': ('You can restart the change from your <a href=":url">account page</a> (each step '
                 'link is valid for 24 hours).',
                 'Zmianę możesz zacząć od nowa na <a href=":url">stronie swojego konta</a> (link '
                 'każdego kroku jest ważny 24 godziny).'),
})
