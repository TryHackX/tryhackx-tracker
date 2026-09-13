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


# ── recovered 2026-09-13 ────────────────────────────────────────────────────
# These strings were added straight to lang/en.php and lang/pl.php between 1.43 and 1.50 and never to
# these sources, so the first regeneration since then (1.50.1) dropped all of them. They are written
# back here, where the generator reads from. tests/lang_test.php now checks that the generated files
# match the sources, so a string added to the generated file alone fails the battery on the spot.
add('', {
    'account.lists_public_hint': ('Each list still carries its own switch — this one decides whether the section exists for a stranger at all. Off, nobody sees that you have lists.',
        'Każda lista ma nadal własny przełącznik — ten decyduje, czy sekcja w ogóle istnieje dla obcej osoby. Wyłączone — nikt nie widzi, że masz listy.'),
    'account.lists_public_label': ('Show my lists on my profile',
        'Pokazuj moje listy na moim profilu'),
    'account.needs_grant': ('This switch is saved, but nothing acts on it yet: none of your groups grants <code>:perm</code>, and that permission is what every page checks before showing this to anybody else. An administrator grants it in the panel, under Users → Groups.',
        'Ten przełącznik jest zapisany, ale nic go jeszcze nie honoruje: żadna z Twoich grup nie ma uprawnienia <code>:perm</code>, a właśnie ono jest sprawdzane, zanim ktokolwiek inny to zobaczy. Administrator nadaje je w panelu, w Użytkownicy → Grupy.'),
    'account.open_profile': ('Open my profile',
        'Otwórz mój profil'),
    'account.pm_who_all': ('Anybody signed in',
        'Każdy zalogowany'),
    'account.pm_who_default': ('Follow the site default (:what)',
        'Zgodnie z ustawieniem serwisu (:what)'),
    'account.pm_who_friends': ('Only my friends',
        'Tylko znajomi'),
    'account.pm_who_hint': ('Somebody who may not write to you is told so when they try, rather than having their message quietly swallowed.',
        'Kto nie może do Ciebie napisać, dowiaduje się o tym przy próbie — zamiast wysyłać wiadomość, której nikt nigdy nie przeczyta.'),
    'account.pm_who_label': ('Who may send me messages',
        'Kto może pisać do mnie'),
    'account.pm_who_nobody': ('Nobody',
        'Nikt'),
    'account.profile_listed_hint': ('The directory lists only the people who asked to be on it. Your profile stays reachable by its address either way — this is about being FOUND by somebody browsing.',
        'Katalog wymienia tylko osoby, które o to poprosiły. Twój profil i tak jest dostępny pod swoim adresem — tu chodzi o to, czy ktoś ma Cię ZNALEŹĆ, przeglądając.'),
    'account.profile_listed_label': ('List me in the member directory',
        'Umieść mnie w katalogu użytkowników'),
    'account.security': ('Account security',
        'Bezpieczeństwo konta'),
    'account.sessions_clear': ('Sign out everywhere else',
        'Wyloguj wszędzie indziej'),
    'account.sessions_note': ('This lists the browsers that asked to be remembered — a plain sign-in leaves no record anywhere, so the number is what this site can prove rather than every tab in the world. Signing out everywhere else reaches those too: every other session stops being honoured on its next click, and only the browser you are using now stays signed in.',
        'To lista przeglądarek, które poprosiły o zapamiętanie — zwykłe logowanie nie zostawia nigdzie śladu, więc ta liczba to tyle, ile ta strona potrafi udowodnić, a nie każda otwarta karta na świecie. Wylogowanie wszędzie indziej sięga jednak także tam: każda inna sesja przestaje być honorowana przy następnym kliknięciu, a zalogowana zostaje tylko przeglądarka, w której teraz jesteś.'),
    'account.tab_lists': ('Lists',
        'Listy'),
    'account.tab_messages': ('Messages',
        'Wiadomości'),
    'account.tab_people': ('People',
        'Ludzie'),
    'account.twofa': ('Two-factor authentication',
        'Uwierzytelnianie dwuskładnikowe'),
    'account.twofa_left': (':n recovery codes left.',
        'Pozostało kodów zapasowych: :n.'),
    'account.twofa_newcodes': ('New codes',
        'Nowe kody'),
    'account.twofa_note': ('A six-digit code from your phone, on top of your password. Set it up with any authenticator app — the secret never leaves this page and the square is drawn by this server.',
        'Sześciocyfrowy kod z telefonu obok hasła. Skonfigurujesz to dowolną aplikacją uwierzytelniającą — sekret nie opuszcza tej strony, a kwadrat rysuje ten serwer.'),
    'account.twofa_off': ('off',
        'wyłączone'),
    'account.twofa_on': ('on',
        'włączone'),
    'account.twofa_panel_note': ('This is your <strong>account</strong>&rsquo;s second factor. The admin panel has its own, set up inside the panel — they are separate secrets with separate recovery codes, and turning one on does not turn the other on.',
        'To drugi składnik <strong>twojego konta</strong>. Panel administracyjny ma własny, ustawiany w panelu — to osobne sekrety z osobnymi kodami zapasowymi, a włączenie jednego nie włącza drugiego.'),
    'account.twofa_required_all': ('This site asks every account for a second factor. Yours does not have one yet.',
        'Ta strona prosi o drugi składnik każde konto. Twoje jeszcze go nie ma.'),
    'account.twofa_required_panel': ('Your account can open the admin panel, and this site asks those accounts for a second factor. <strong>Until you set one up the panel will not open</strong> — everything else about your account works as usual.',
        'Twoje konto może otworzyć panel administracyjny, a takie konta ta strona prosi o drugi składnik. <strong>Dopóki go nie ustawisz, panel się nie otworzy</strong> — cała reszta konta działa normalnie.'),
})
