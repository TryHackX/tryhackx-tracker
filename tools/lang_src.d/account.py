# -*- coding: utf-8 -*-
"""Account

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


# ── account ─────────────────────────────────────────────────────────────────
add('account', {
    'h1':          ('Account', 'Konto'),
    'not_signed':  ('You are not signed in.', 'Nie jesteś zalogowany.'),
    'h1_named':    ('Account — :user', 'Konto — :user'),
    'restricted':  ('Your email address is <strong>not verified</strong> — until you open the '
                    'confirmation link, this account works at <strong>guest level</strong> (group '
                    'permissions are paused).:hint',
                    'Twój adres e-mail jest <strong>niezweryfikowany</strong> — dopóki nie otworzysz '
                    'linku potwierdzającego, konto działa na <strong>poziomie gościa</strong> '
                    '(uprawnienia grup są wstrzymane).:hint'),
    'restricted_has': (' Check your inbox or use &ldquo;Resend link&rdquo; below.',
                       ' Sprawdź skrzynkę albo użyj przycisku &bdquo;Wyślij link ponownie&rdquo; poniżej.'),
    'restricted_none': (' Add an email address below to receive the link.',
                        ' Dodaj poniżej adres e-mail, aby otrzymać link.'),
    'pending':     ('Email change to <strong>:email</strong> is waiting for confirmation from the '
                    '<strong>:stage</strong> address.',
                    'Zmiana adresu na <strong>:email</strong> czeka na potwierdzenie z '
                    '<strong>:stage</strong> adresu.'),
    'pending_removal': ('(removal)', '(usunięcie)'),
    'stage_current': ('current', 'obecnego'),
    'stage_new':   ('new', 'nowego'),
    'cancel_change': ('Cancel the change', 'Anuluj zmianę'),
    'profile':     ('Profile', 'Profil'),
    'username':    ('Username', 'Nazwa użytkownika'),
    'email':       ('Email', 'E-mail'),
    'verified':    ('verified', 'zweryfikowany'),
    'unverified':  ('unverified', 'niezweryfikowany'),
    'resend':      ('Resend link', 'Wyślij link ponownie'),
    'resend_title': ('Send the confirmation link again', 'Wyślij link potwierdzający jeszcze raz'),
    'member_since': ('Member since', 'Konto od'),
    'last_login':  ('Last sign-in', 'Ostatnie logowanie'),
    'verify_note': ('A confirmation link was sent to this address — open it to verify. Unverified '
                    'addresses still receive password resets.',
                    'Na ten adres wysłano link potwierdzający — otwórz go, aby zweryfikować. Na '
                    'niezweryfikowane adresy nadal wysyłamy resety hasła.'),
    'mail_prefs':  ('What we may send you', 'Co możemy do Ciebie wysyłać'),
    'pref_account': ('Account mail', 'Poczta o koncie'),
    'pref_account_note': ('Expiry warnings, security notices and anything else about this account.',
                          'Ostrzeżenia o wygaśnięciu, powiadomienia bezpieczeństwa i wszystko inne '
                          'dotyczące tego konta.'),
    'pref_bulk':   ('Announcements', 'Ogłoszenia'),
    'pref_bulk_note': ('Occasional messages sent to everyone. Turning this off stops those only — '
                       'password resets and security notices still reach you.',
                       'Sporadyczne wiadomości do wszystkich. Wyłączenie zatrzymuje tylko je — '
                       'resety hasła i powiadomienia bezpieczeństwa nadal do Ciebie dotrą.'),
    'groups':      ('Your groups', 'Twoje grupy'),
    'notifications': ('Notifications', 'Powiadomienia'),
    'mark_all':    ('Mark all read', 'Oznacz wszystkie jako przeczytane'),
    'delete_read': ('Delete read', 'Usuń przeczytane'),
    'delete_read_title': ('Remove every notification you have already read',
                          'Usuń wszystkie powiadomienia, które już przeczytałeś'),
    'notif_note':  ('Read notifications are removed automatically after 90 days (365 days for '
                    'unread ones).',
                    'Przeczytane powiadomienia są usuwane automatycznie po 90 dniach '
                    '(nieprzeczytane po 365).'),
    'change_head': ('Change email / password', 'Zmiana e-maila / hasła'),
    'cur_pass':    ('Current password', 'Obecne hasło'),
    'cur_pass_hint': ('required for any change', 'wymagane przy każdej zmianie'),
    'email_hint':  ('edit to change &middot; clear the box to remove your address',
                    'edytuj, aby zmienić &middot; wyczyść pole, aby usunąć adres'),
    'email_hint_confirm': (' &middot; a change is confirmed from the current address first, then from the new one',
                           ' &middot; zmiana jest potwierdzana najpierw z obecnego adresu, potem z nowego'),
    'email_hint_cooldown': ('; next change possible :days days after the previous one',
                            '; kolejna zmiana możliwa :days dni po poprzedniej'),
    'email_err':   ('That email address does not look valid', 'Ten adres e-mail wygląda na nieprawidłowy'),
    'email2':      ('Repeat new email', 'Powtórz nowy adres e-mail'),
    'email2_err':  ('Email addresses do not match', 'Adresy e-mail nie są takie same'),
    'new_pass':    ('New password', 'Nowe hasło'),
    'new_pass_hint': ('leave empty to keep the current one', 'zostaw puste, aby zachować obecne'),
    'new_pass_err': ('The password does not meet the requirements above',
                     'Hasło nie spełnia powyższych wymagań'),
    'new_pass2':   ('Repeat new password', 'Powtórz nowe hasło'),
    'new_pass2_err': ('Passwords do not match', 'Hasła nie są takie same'),
    'save':        ('Save changes', 'Zapisz zmiany'),
    'lang_head':   ('Interface language', 'Język interfejsu'),
    'lang_note':   ('Your choice follows the account, so the site opens in this language on any '
                    'browser you sign in from.',
                    'Twój wybór jest zapisany przy koncie, więc serwis otworzy się w tym języku w '
                    'każdej przeglądarce, z której się zalogujesz.'),
    'lang_site':   ('Follow the site default', 'Zgodnie z domyślnym językiem serwisu'),
    'lang_saved':  ('Language saved.', 'Język zapisany.'),
})
