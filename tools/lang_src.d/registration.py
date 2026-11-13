# -*- coding: utf-8 -*-
"""Registration

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


# ── registration ────────────────────────────────────────────────────────────
add('register', {
    'h1':          ('Create an account', 'Załóż konto'),
    'already':     ('You are already signed in as <strong>:user</strong>.',
                    'Jesteś już zalogowany jako <strong>:user</strong>.'),
    'closed':      ('Registration is currently <strong>closed</strong>:why.',
                    'Rejestracja jest obecnie <strong>zamknięta</strong>:why.'),
    'closed_captcha': (' (CAPTCHA is not configured on this site)',
                       ' (CAPTCHA nie jest skonfigurowana na tej stronie)'),
    'have_account': ('<a href=":url">Sign in</a> if you already have an account.',
                     '<a href=":url">Zaloguj się</a>, jeśli masz już konto.'),
    'intro':       ('An account gives you access to member features — what exactly depends on the '
                    'groups the admin grants.:verify Registration is free; :captcha.',
                    'Konto daje dostęp do funkcji dla zalogowanych — co dokładnie, zależy od grup '
                    'przyznanych przez administratora.:verify Rejestracja jest darmowa; :captcha.'),
    'intro_verify': (' Member access is activated by the <strong>confirmation link</strong> sent to '
                     'your email; until then the account works at guest level.',
                     ' Dostęp członkowski aktywuje <strong>link potwierdzający</strong> wysłany na '
                     'Twój e-mail; do tego czasu konto działa na poziomie gościa.'),
    'captcha_v3':  ('an invisible CAPTCHA check runs on submit',
                    'przy wysyłce uruchamia się niewidoczna weryfikacja CAPTCHA'),
    'captcha_std': ('a CAPTCHA is required', 'wymagana jest CAPTCHA'),
    'username':    ('Username', 'Nazwa użytkownika'),
    'username_hint': ('3–32 chars: letters, digits, _ . -', '3–32 znaki: litery, cyfry, _ . -'),
    'username_err': ('3–32 characters: letters, digits and _ . -',
                     '3–32 znaki: litery, cyfry oraz _ . -'),
    'email':       ('Email', 'E-mail'),
    'email_hint_req': ('required — the confirmation link activates member access',
                       'wymagany — link potwierdzający aktywuje dostęp członkowski'),
    'email_hint_opt': ('optional — used only for password resets and notifications',
                       'opcjonalny — używany tylko do resetu hasła i powiadomień'),
    'email_err_req': ('A valid email address is required', 'Wymagany jest poprawny adres e-mail'),
    'email_err_opt': ('That email address does not look valid', 'Ten adres e-mail wygląda na nieprawidłowy'),
    'password':    ('Password', 'Hasło'),
    'password_err': ('The password does not meet the requirements above',
                     'Hasło nie spełnia powyższych wymagań'),
    'password2':   ('Repeat password', 'Powtórz hasło'),
    'password2_err': ('Passwords do not match', 'Hasła nie są takie same'),
    'terms':       ('I accept the <a href=":url" id="reg-terms-link":target>terms of service</a>',
                    'Akceptuję <a href=":url" id="reg-terms-link":target>regulamin</a>'),
    'terms_err':   ('You must accept the terms to register',
                    'Aby się zarejestrować, musisz zaakceptować regulamin'),
    'submit':      ('Create account', 'Załóż konto'),
    'have':        ('Already have an account?', 'Masz już konto?'),
    'terms_title': ('Terms of service', 'Regulamin'),
})
