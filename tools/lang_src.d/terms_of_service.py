# -*- coding: utf-8 -*-
"""Terms of service

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


# ── terms of service ────────────────────────────────────────────────────────
add('tos', {
    'h1':      ('Terms of Service', 'Regulamin'),
    'intro':   ('By using this tracker, you agree to the following terms:',
                'Korzystając z tego trackera, akceptujesz poniższy regulamin:'),
    'r1':      ('The service is free for personal use. Commercial organizations require written permission.',
                'Usługa jest darmowa do użytku osobistego. Organizacje komercyjne potrzebują pisemnej zgody.'),
    'r2':      ('User tracking, DoS/DDoS attacks, and any attempts to disrupt the service are prohibited.',
                'Śledzenie użytkowników, ataki DoS/DDoS i wszelkie próby zakłócenia usługi są zabronione.'),
    'r3':      ('We do not guarantee service uptime. Availability may be limited without prior notice.',
                'Nie gwarantujemy ciągłości działania usługi. Dostępność może zostać ograniczona bez uprzedzenia.'),
    'r4':      ('The user bears full responsibility for the legality of shared content in their jurisdiction.',
                'Użytkownik ponosi pełną odpowiedzialność za legalność udostępnianych treści w swojej jurysdykcji.'),
    'r5':      ('Commercial use policy violations are subject to a fee of EUR 5,000.',
                'Naruszenie zasad użytku komercyjnego podlega opłacie w wysokości 5000 EUR.'),
    'r6':      ('We reserve the right to publish information about policy violations.',
                'Zastrzegamy sobie prawo do publikowania informacji o naruszeniach regulaminu.'),
    'r7':      ('We respect user privacy. We do not store personal data beyond what is necessary for '
                'tracker operation.',
                'Szanujemy prywatność użytkowników. Nie przechowujemy danych osobowych ponad to, co '
                'jest niezbędne do działania trackera.'),
    'r_wl':    ('Whitelist registrations are free and anonymous; the registrant\'s IP address is '
                'stored to detect abuse. Registered info hashes may be removed or banned at any '
                'time, and abusive registrants may be banned.',
                'Rejestracje na whiteliście są darmowe i anonimowe; adres IP zgłaszającego jest '
                'zapisywany w celu wykrywania nadużyć. Zarejestrowane info hashe mogą zostać w '
                'każdej chwili usunięte lub zbanowane, a zgłaszający nadużycia — zablokowani.'),
    'r8':      ('Terms may change. Continued use of the service constitutes acceptance of changes.',
                'Regulamin może się zmienić. Dalsze korzystanie z usługi oznacza akceptację zmian.'),
    'r9':      ('<strong>Connecting to the tracker constitutes acceptance of these terms.</strong>',
                '<strong>Połączenie z trackerem oznacza akceptację niniejszego regulaminu.</strong>'),
    'acc_head': ('User accounts', 'Konta użytkowników'),
    'acc1':    ('Creating an account is free and optional — the tracker itself works without one. An '
                'account only unlocks member features (such as the catalogue search) according to '
                'the groups granted to it.',
                'Założenie konta jest darmowe i nieobowiązkowe — sam tracker działa bez niego. Konto '
                'odblokowuje jedynie funkcje dla zalogowanych (na przykład wyszukiwarkę katalogu), '
                'zgodnie z przyznanymi mu grupami.'),
    'acc2':    ('For an account we store: the username, the password (as a salted hash — never in '
                'plain text), the email address:email, the IP addresses used at registration and '
                'sign-in (abuse prevention), group memberships with their expiry dates, and in-app '
                'notifications.',
                'Dla konta przechowujemy: nazwę użytkownika, hasło (jako solony skrót — nigdy '
                'otwartym tekstem), adres e-mail:email, adresy IP użyte przy rejestracji i logowaniu '
                '(zapobieganie nadużyciom), przynależność do grup wraz z datami wygaśnięcia oraz '
                'powiadomienia w serwisie.'),
    'acc2_req': (' (required and confirmed by a verification link)',
                 ' (wymagany i potwierdzany linkiem weryfikacyjnym)'),
    'acc2_opt': (' (optional)', ' (opcjonalny)'),
    'acc3':    ('Emails are used solely for account operation: verification links, password resets, '
                'email-change confirmations and expiry/security notices. Account notices can be '
                'disabled on the account page; we never share addresses with third parties.',
                'Adresy e-mail służą wyłącznie obsłudze konta: linkom weryfikacyjnym, resetom hasła, '
                'potwierdzeniom zmiany adresu oraz powiadomieniom o wygaśnięciu i bezpieczeństwie. '
                'Powiadomienia o koncie można wyłączyć na stronie konta; nigdy nie udostępniamy '
                'adresów stronom trzecim.'),
    'acc4':    ('Changing the account email requires confirmation from the current address and then '
                'from the new one; a cool-down period applies between changes. This protects '
                'accounts from hijacking.',
                'Zmiana adresu e-mail konta wymaga potwierdzenia z obecnego adresu, a następnie z '
                'nowego; między zmianami obowiązuje okres karencji. Chroni to konta przed '
                'przejęciem.'),
    'acc5':    ('Session cookies (and the optional "stay signed in" token) are strictly functional. '
                'The sign-in duration is chosen at login; tokens are stored only as hashes and are '
                'invalidated by a password change or sign-out.',
                'Ciasteczka sesji (oraz opcjonalny token „pozostań zalogowany”) są ściśle '
                'funkcjonalne. Czas trwania sesji wybierasz przy logowaniu; tokeny przechowujemy '
                'wyłącznie jako skróty i unieważnia je zmiana hasła lub wylogowanie.'),
    'acc6':    ('Accounts used for abuse (spam, attacks on the service, deliberately registering '
                'infringing content after warnings) may be suspended or deleted, together with their '
                'whitelist registrations.',
                'Konta wykorzystywane do nadużyć (spam, ataki na usługę, celowe rejestrowanie '
                'naruszających treści mimo ostrzeżeń) mogą zostać zawieszone lub usunięte wraz z '
                'ich wpisami na whiteliście.'),
    'acc7':    ('To have your account and its data removed, contact the site email from your account '
                'address (or use the report form). Read notifications are pruned automatically after '
                '90 days.',
                'Aby usunąć konto i jego dane, napisz na adres serwisu z adresu przypisanego do '
                'konta (albo skorzystaj z formularza zgłoszeń). Przeczytane powiadomienia są '
                'automatycznie usuwane po 90 dniach.'),
    'r_index':   ('The tracker keeps an <strong>index</strong> of info hashes it has observed, with the torrent '
                  'name and file list fetched from the swarm, so that reports can be matched to content. No files '
                  'are downloaded or stored; entries expire when a swarm goes quiet.',
                  'Tracker prowadzi <strong>indeks</strong> zaobserwowanych info hashy wraz z nazwą torrenta i '
                  'listą plików pobraną z roju, aby zgłoszenia dało się dopasować do treści. Żadne pliki nie są '
                  'pobierane ani przechowywane; wpisy wygasają, gdy rój cichnie.'),
    'acc8':      ('Searching the index is a member feature. Search terms are not stored beyond the request; the '
                  'results reflect what the tracker has observed, not what we host — we host nothing.',
                  'Wyszukiwarka indeksu jest funkcją dla zalogowanych. Frazy nie są przechowywane dłużej niż '
                  'żądanie; wyniki odzwierciedlają to, co tracker zaobserwował, nie to, co hostujemy — nie '
                  'hostujemy niczego.'),
    'lang_head': ('Languages and cookies', 'Języki i ciasteczka'),
    'lang1':     ('Choosing a language with the switcher sets a cookie named <code>lang</code> for the browser '
                  'session only; it holds a two-letter code and nothing else. A signed-in account may save a '
                  'language preference, which is kept with the account.',
                  'Wybór języka przełącznikiem ustawia ciasteczko <code>lang</code> tylko na czas sesji '
                  'przeglądarki; zawiera dwuliterowy kod i nic więcej. Zalogowane konto może zapisać '
                  'preferowany język, przechowywany razem z kontem.'),
})
