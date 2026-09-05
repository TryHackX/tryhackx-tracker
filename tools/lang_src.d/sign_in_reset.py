# -*- coding: utf-8 -*-
"""Sign in / reset

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


# ── sign in / reset ─────────────────────────────────────────────────────────
add('login', {
    'h1':          ('Sign in', 'Logowanie'),
    'already':     ('You are signed in as <strong>:user</strong>.',
                    'Jesteś zalogowany jako <strong>:user</strong>.'),
    'login_label': ('Username or email', 'Nazwa użytkownika lub e-mail'),
    'login_err':   ('Enter your username or email address',
                    'Podaj nazwę użytkownika albo adres e-mail'),
    'password':    ('Password', 'Hasło'),
    'password_err': ('Enter your password', 'Podaj hasło'),
    'stay':        ('Stay signed in for', 'Pozostań zalogowany na'),
    'no_account':  ('No account yet?', 'Nie masz jeszcze konta?'),
    'free':        ('— it is free.', '— to nic nie kosztuje.'),
    'forgot':      ('Forgot your password?', 'Nie pamiętasz hasła?'),
    'reset_it':    ('Reset it', 'Zresetuj je'),
})
add('reset', {
    'h1':        ('Password reset', 'Reset hasła'),
    'set_new':   ('Set a new password for your account.', 'Ustaw nowe hasło do swojego konta.'),
    'new_pass':  ('New password', 'Nowe hasło'),
    'repeat':    ('Repeat password', 'Powtórz hasło'),
    'submit_new': ('Set new password', 'Ustaw nowe hasło'),
    'no_captcha': ('Password reset is unavailable (CAPTCHA is not configured on this site). Contact '
                   'the site admin.',
                   'Reset hasła jest niedostępny (CAPTCHA nie jest skonfigurowana na tej stronie). '
                   'Skontaktuj się z administratorem serwisu.'),
    'intro':     ('Enter your username or email. If the account exists and has an email address, a '
                  'reset link is sent to it (valid for 2 hours).',
                  'Podaj nazwę użytkownika albo adres e-mail. Jeśli konto istnieje i ma przypisany '
                  'adres, wyślemy na niego link resetujący (ważny 2 godziny).'),
    'send':      ('Send reset link', 'Wyślij link resetujący'),
    'back':      ('Back to sign in', 'Powrót do logowania'),
})
