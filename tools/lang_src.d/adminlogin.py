# -*- coding: utf-8 -*-
"""Admin sign in

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


# ── admin sign in ───────────────────────────────────────────────────────────
add('adminlogin', {
    'h1':            ('Admin sign in', 'Logowanie administratora'),
    'staff_area':    ('Staff area of :site.', 'Strefa personelu :site.'),
    # Stands in for the site name when none is set, and sits in the genitive slot of the sentence
    # above -- hence 'tego trackera' rather than a bare 'ten tracker'.
    'this_tracker':  ('this tracker', 'tego trackera'),
    'code_label':    ('Authentication code', 'Kod uwierzytelniający'),
    'code_hint':     ('Six digits from your authenticator app. If you cannot reach it, enter one of '
                      'your recovery codes instead.',
                      'Sześć cyfr z Twojej aplikacji uwierzytelniającej. Jeśli nie masz do niej '
                      'dostępu, wpisz zamiast tego jeden ze swoich kodów odzyskiwania.'),
    'continue':      ('Continue', 'Dalej'),
    'back_tracker':  ('Back to the tracker', 'Powrót do trackera'),
    # ── messages the page's own script shows ────────────────────────────────
    # CAPTCHA is declined as a feminine noun throughout this dictionary (whitelist.py has
    # 'rozwiąż CAPTCHĘ'), so it takes case here too: genitive after the negated 'nie udało się
    # wczytać', accusative after 'anulowano'.
    'captcha_unavailable': ('CAPTCHA could not load — reload the page or try again later.',
                            'Nie udało się wczytać CAPTCHY — odśwież stronę albo spróbuj później.'),
    'captcha_cancelled':   ('CAPTCHA cancelled', 'Anulowano CAPTCHĘ'),
    'enter_code':    ('Enter the six-digit code from your authenticator app.',
                      'Wpisz sześciocyfrowy kod z aplikacji uwierzytelniającej.'),
    # :n is a bare numeral, and Polish makes the verb agree with it -- 'został' for 1, 'zostały'
    # for 2-4, 'zostało' for 0 and 5+. The script shows this hint when recovery_left <= 2, so both
    # live values would need different verbs and any single one of them is wrong half the time.
    # 'masz' does not inflect with the count, which sidesteps the agreement entirely; 'tych
    # ostatnich' carries the English 'of those', pointing at the recovery codes rather than at the
    # six digits offered beside them.
    'code_hint_low': ('Six digits from your authenticator app, or a recovery code — you have only '
                      ':n of those left.',
                      'Sześć cyfr z aplikacji uwierzytelniającej albo kod odzyskiwania — tych '
                      'ostatnich masz już tylko :n.'),
    # 'przeładować' is what you do to a cargo hold or a rifle; a page is 'odświeżana', which is also
    # the verb captcha_unavailable above already uses for the same act.
    'expired':       ('This login page had expired — reloading, please try again.',
                      'Ta strona logowania wygasła — odświeżam ją, spróbuj jeszcze raz.'),
    'bad_credentials': ('Invalid username or password',
                        'Nieprawidłowa nazwa użytkownika lub hasło'),
    'login_failed':  ('Login failed', 'Logowanie nie powiodło się'),
    'network_error': ('Network error', 'Błąd sieci'),
    'code_wrong':    ('That code is not right.', 'Ten kod się nie zgadza.'),
})
