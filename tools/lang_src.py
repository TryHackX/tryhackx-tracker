# -*- coding: utf-8 -*-
"""
ONE source for the two shipped dictionaries.

    python tools/lang_src.py .

Every string is written here as (English, Polish) and BOTH `lang/en.php` and `lang/pl.php` are
generated from it. That is the whole point: a key added to one file and forgotten in the other is
the ordinary way a translation rots, and here there is nowhere to put an English string without its
Polish beside it. tests/lang_test.php checks the two files still agree -- same keys, same `:name`
placeholders, nothing blank -- so a hand-edit that breaks the pairing is caught rather than shipped.

This generates ONLY the two languages that ship. Anything an operator installs through
Settings -> Languages is written by includes/lang.php from an uploaded JSON file and is not touched
by this script; running it will not overwrite somebody's German.
"""
import io, os, sys

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

# ── navigation ──────────────────────────────────────────────────────────────
add('nav', {
    'home':         ('Home', 'Strona główna'),
    'info':         ('Info', 'Informacje'),
    'terms':        ('Terms', 'Regulamin'),
    'whitelist':    ('Whitelist', 'Whitelista'),
    'report':       ('Report', 'Zgłoszenie'),
    'status':       ('Status', 'Status'),
    'transparency': ('Transparency', 'Przejrzystość'),
    'stats':        ('Stats', 'Statystyki'),
    'search':       ('Search', 'Szukaj'),
    'account':      ('Account', 'Konto'),
})

# ── page titles (<title> and the browser tab) ───────────────────────────────
add('title', {
    'home':         ('Home', 'Strona główna'),
    'info':         ('Info', 'Informacje'),
    'tos':          ('Terms', 'Regulamin'),
    'report':       ('Report', 'Zgłoszenie'),
    'status':       ('Status', 'Status'),
    'transparency': ('Transparency', 'Przejrzystość'),
    'unsubscribe':  ('Unsubscribe', 'Wypisanie się'),
    'whitelist':    ('Whitelist', 'Whitelista'),
    'stats':        ('Stats', 'Statystyki'),
    'login':        ('Sign in', 'Logowanie'),
    'register':     ('Register', 'Rejestracja'),
    'account':      ('Account', 'Konto'),
    'reset':        ('Password reset', 'Reset hasła'),
    'verify':       ('Email verification', 'Weryfikacja adresu e-mail'),
    'emailchange':  ('Email change', 'Zmiana adresu e-mail'),
    'search':       ('Search', 'Szukaj'),
    'adminlogin':   ('Admin sign in', 'Logowanie administratora'),
    'notfound':     ('Not found', 'Nie znaleziono'),
})

# ── layout: footer and the CAPTCHA overlay ─────────────────────────────────
add('footer', {
    'powered_by':  ('Powered by', 'Napędzane przez'),
    'by_author':   ('by', 'autorstwa'),
    'since':       ('since', 'od'),
    'cc0':         ('Content rights waived via CC0', 'Prawa do treści zrzeczone przez CC0'),
})
add('captcha', {
    'verify_human': ('Please verify you are human', 'Potwierdź, że jesteś człowiekiem'),
})

# ── home ────────────────────────────────────────────────────────────────────
add('home', {
    'tagline':       ('Public BitTorrent tracker powered by OpenTracker',
                      'Publiczny tracker BitTorrent napędzany przez OpenTracker'),
    'stats_syncing': ('Synchronizing tracker telemetry...', 'Synchronizacja telemetrii trackera...'),
    'stat_torrents': ('Torrents', 'Torrenty'),
    'stat_seeds':    ('Seeds', 'Seedy'),
    'stat_peers':    ('Peers', 'Peery'),
    'stat_leechers': ('Leechers', 'Leecherzy'),
    'stat_completed': ('Completed', 'Ukończone'),
    'stat_uptime':   ('Uptime', 'Czas działania'),
    'announce_head': ('Announce URL', 'Adres announce'),
    'announce_copy': ('Copy announce URLs', 'Skopiuj adresy announce'),
    'announce_extra': ('This tracker runs on more than one port. Add <strong>all</strong> of them to '
                       'your client — each is answered by a different process, and using only the '
                       'first leaves the others idle.',
                       'Ten tracker działa na więcej niż jednym porcie. Dodaj do klienta '
                       '<strong>wszystkie</strong> — każdy obsługuje inny proces, a użycie tylko '
                       'pierwszego zostawia pozostałe bezczynne.'),
    'about_head':    ('About the Tracker', 'O trackerze'),
    'about_sched':   ('This is a free BitTorrent tracker running erdgeist OpenTracker software in '
                      '<strong>scheduled whitelist mode</strong>: during whitelist hours (:hours) it '
                      'serves <strong>registered torrents only</strong>; outside those hours it runs '
                      'as an open tracker. Right now it is in <strong>:mode mode</strong>. '
                      'Registration is free and anonymous:reg. It has been running on Linux Debian '
                      'since 2020 and supports both IPv4 and IPv6.',
                      'To darmowy tracker BitTorrent działający na oprogramowaniu OpenTracker '
                      'autorstwa erdgeista w <strong>trybie whitelisty według harmonogramu</strong>: '
                      'w godzinach whitelisty (:hours) obsługuje <strong>wyłącznie zarejestrowane '
                      'torrenty</strong>; poza nimi działa jako tracker otwarty. W tej chwili jest w '
                      '<strong>trybie :mode</strong>. Rejestracja jest darmowa i anonimowa:reg. '
                      'Działa na Linuksie Debian od 2020 roku i obsługuje zarówno IPv4, jak i IPv6.'),
    'about_wl':      ('This is a free BitTorrent tracker running erdgeist OpenTracker software in '
                      '<strong>whitelist mode</strong>: it serves <strong>registered torrents only'
                      '</strong>. Registration is free and anonymous:reg. It has been running on '
                      'Linux Debian since 2020 and supports both IPv4 and IPv6.',
                      'To darmowy tracker BitTorrent działający na oprogramowaniu OpenTracker '
                      'autorstwa erdgeista w <strong>trybie whitelisty</strong>: obsługuje '
                      '<strong>wyłącznie zarejestrowane torrenty</strong>. Rejestracja jest darmowa '
                      'i anonimowa:reg. Działa na Linuksie Debian od 2020 roku i obsługuje zarówno '
                      'IPv4, jak i IPv6.'),
    'about_open':    ('This is a free, public BitTorrent tracker running erdgeist OpenTracker '
                      'software. It has been running on Linux Debian since 2020 and supports both '
                      'IPv4 and IPv6.',
                      'To darmowy, publiczny tracker BitTorrent działający na oprogramowaniu '
                      'OpenTracker autorstwa erdgeista. Działa na Linuksie Debian od 2020 roku i '
                      'obsługuje zarówno IPv4, jak i IPv6.'),
    'mode_whitelist': ('whitelist', 'whitelisty'),
    'mode_open':      ('open', 'otwartym'),
    'reg_here':      (' — <a href=":url">register your torrent here</a>',
                      ' — <a href=":url">zarejestruj tu swój torrent</a>'),
    'register_btn':  ('Register your torrent', 'Zarejestruj swój torrent'),
    'features_head': ('Features', 'Cechy'),
    'feat_no_host':  ('We do not host or store any content or torrent files',
                      'Nie hostujemy ani nie przechowujemy żadnych treści ani plików .torrent'),
    'feat_sched':    ('Only registered info hashes are served during whitelist hours — open tracker otherwise',
                      'W godzinach whitelisty obsługiwane są wyłącznie zarejestrowane info hashe — poza nimi tracker jest otwarty'),
    'feat_wl':       ('Only registered info hashes are served — no unsolicited swarms',
                      'Obsługiwane są wyłącznie zarejestrowane info hashe — żadnych nieproszonych rojów'),
    'feat_meta':     ('We store torrent metadata (names / file lists) of registered hashes and '
                      'registrant IP addresses to fight abuse; no peer connection logs are kept',
                      'Przechowujemy metadane torrentów (nazwy / listy plików) zarejestrowanych '
                      'hashy oraz adresy IP zgłaszających, aby zwalczać nadużycia; nie prowadzimy '
                      'logów połączeń peerów'),
    'feat_no_logs':  ('No connection logs are kept', 'Nie prowadzimy logów połączeń'),
    'feat_random_ip': ('Random IPs inserted into peer lists (privacy)',
                       'Do list peerów wstrzykiwane są losowe adresy IP (prywatność)'),
    'feat_ipv6':     ('Full IPv4 and IPv6 support', 'Pełna obsługa IPv4 i IPv6'),
    'feat_free':     ('Completely free service', 'Usługa całkowicie darmowa'),
    'donate_head':   ('Support the Project', 'Wesprzyj projekt'),
    'donate_copy':   ('Copy :label', 'Skopiuj :label'),
    'contact_head':  ('Contact', 'Kontakt'),
    'contact_cta':   ('If you wish to report infringing content, please use our <a href=":url" '
                      'class="report-link">[ Submit a Report ]</a>',
                      'Jeśli chcesz zgłosić naruszające treści, skorzystaj z naszego '
                      '<a href=":url" class="report-link">[ Formularza zgłoszeń ]</a>'),
    'contact_note_a': ('Reports submitted through the form above are reviewed faster and receive '
                       'status updates. For business inquiries, partnership requests, or if you have '
                       'not received a response to your report, you may contact us via ',
                       'Zgłoszenia złożone przez powyższy formularz są rozpatrywane szybciej i '
                       'otrzymują aktualizacje statusu. W sprawach biznesowych, propozycji '
                       'współpracy lub gdy nie otrzymałeś odpowiedzi na zgłoszenie, możesz '
                       'skontaktować się z nami przez '),
    'contact_reveal': ('[click to reveal contact]', '[kliknij, aby pokazać kontakt]'),
})

# ── info ────────────────────────────────────────────────────────────────────
add('info', {
    'h1':        ('Tracker Information', 'Informacje o trackerze'),
    'q_what':    ('What is a BitTorrent tracker?', 'Czym jest tracker BitTorrent?'),
    'a_what':    ('A BitTorrent tracker is a server that helps BitTorrent clients communicate. It '
                  'coordinates file transfers between users (peers) by tracking who is sharing a '
                  'given torrent. The tracker does not store any files — only information about '
                  'active swarm participants.',
                  'Tracker BitTorrent to serwer, który pomaga klientom BitTorrent porozumiewać się '
                  'ze sobą. Koordynuje przesyłanie plików między użytkownikami (peerami), śledząc, '
                  'kto udostępnia dany torrent. Tracker nie przechowuje żadnych plików — wyłącznie '
                  'informacje o aktywnych uczestnikach roju.'),
    'q_ot':      ('What is OpenTracker?', 'Czym jest OpenTracker?'),
    'a_ot':      ('OpenTracker is a high-performance BitTorrent tracker software created by '
                  'erdgeist. It is open-source, extremely fast, and minimalist. It can handle '
                  'millions of connections with minimal resource usage.',
                  'OpenTracker to wydajne oprogramowanie trackera BitTorrent stworzone przez '
                  'erdgeista. Jest open source, wyjątkowo szybkie i minimalistyczne. Obsługuje '
                  'miliony połączeń przy minimalnym zużyciu zasobów.'),
    'q_how':     ('How does the tracker work?', 'Jak działa tracker?'),
    'a_how':     ('A BitTorrent client sends an "announce" request to the tracker with the torrent\'s '
                  'info_hash. The tracker responds with a list of peers currently sharing or '
                  'downloading the same torrent. The tracker only knows: the info_hash, the peer\'s '
                  'IP address, and port.',
                  'Klient BitTorrent wysyła do trackera żądanie „announce” z info_hashem torrenta. '
                  'Tracker odpowiada listą peerów, którzy właśnie udostępniają lub pobierają ten '
                  'sam torrent. Tracker zna wyłącznie: info_hash, adres IP peera i port.'),
    'q_wl':      ('Whitelist mode', 'Tryb whitelisty'),
    'a_wl':      ('This tracker runs OpenTracker in <strong>whitelist mode</strong>: announces are '
                  'only answered for info hashes that were <strong>registered</strong> beforehand. '
                  'Torrents posted on our community forum are registered automatically; anyone else '
                  'can register a magnet link or info hash for free on the <a href=":url">Whitelist '
                  'page</a> (CAPTCHA + rate limits apply, the registrant\'s IP is stored to fight '
                  'abuse). Registered hashes may be removed or banned after an abuse report. '
                  'Unregistered hashes receive an empty / "not authorized" answer.',
                  'Ten tracker uruchamia OpenTracker w <strong>trybie whitelisty</strong>: na '
                  'announce odpowiadamy wyłącznie dla info hashy, które zostały wcześniej '
                  '<strong>zarejestrowane</strong>. Torrenty publikowane na naszym forum '
                  'społecznościowym rejestrują się automatycznie; każdy inny może za darmo '
                  'zarejestrować link magnet lub info hash na <a href=":url">stronie whitelisty</a> '
                  '(obowiązuje CAPTCHA i limity, adres IP zgłaszającego jest zapisywany w celu '
                  'zwalczania nadużyć). Zarejestrowane hashe mogą zostać usunięte lub zbanowane po '
                  'zgłoszeniu nadużycia. Niezarejestrowane hashe dostają pustą odpowiedź / „brak '
                  'uprawnień”.'),
    'q_data':    ('What data does the tracker store?', 'Jakie dane przechowuje tracker?'),
    'a_data':    ('The tracker only stores active swarms — a list of info_hashes and their '
                  'associated peers (IP + port). This data is temporary and removed when a peer\'s '
                  'session expires. No files, torrent names, or content are stored.',
                  'Tracker przechowuje wyłącznie aktywne roje — listę info_hashy i powiązanych z '
                  'nimi peerów (IP + port). Te dane są tymczasowe i znikają, gdy sesja peera '
                  'wygaśnie. Nie przechowujemy żadnych plików, nazw torrentów ani treści.'),
    'faq_head':  ('Frequently Asked Questions', 'Najczęściej zadawane pytania'),
    'faq_q1':    ('Can you remove an info_hash?', 'Czy możecie usunąć info_hash?'),
    'faq_a1_wl': ('Yes — in whitelist mode a hash can be removed from (or banned on) the whitelist, '
                  'after which the tracker stops answering announces for it. Use the report form.',
                  'Tak — w trybie whitelisty hash można usunąć z whitelisty (lub go tam zbanować), '
                  'po czym tracker przestaje odpowiadać na announce dla niego. Skorzystaj z '
                  'formularza zgłoszeń.'),
    'faq_a1_open': ('The tracker automatically removes swarms when all peers\' sessions expire. We do '
                    'not control which torrents are tracked.',
                    'Tracker sam usuwa roje, gdy wygasną sesje wszystkich peerów. Nie decydujemy o '
                    'tym, które torrenty są śledzone.'),
    'faq_q2':    ('Can you see what content is behind a hash?', 'Czy widzicie, jaka treść kryje się za hashem?'),
    'faq_a2':    ('No. An info_hash is merely a SHA1 digest of the torrent\'s metadata. The tracker '
                  'has no information about file contents.',
                  'Nie. Info_hash to jedynie skrót SHA1 metadanych torrenta. Tracker nie ma żadnych '
                  'informacji o zawartości plików.'),
    'faq_q3':    ('Do you keep IP address logs?', 'Czy prowadzicie logi adresów IP?'),
    'faq_a3':    ('No. We do not keep persistent connection logs. Random IP addresses are inserted '
                  'into peer lists to protect privacy.',
                  'Nie. Nie prowadzimy trwałych logów połączeń. Do list peerów wstrzykiwane są '
                  'losowe adresy IP, aby chronić prywatność.'),
    'faq_q4':    ('What should copyright holders do?', 'Co powinni zrobić właściciele praw autorskich?'),
    'faq_a4':    ('Since the tracker does not store any files or content, copyright holders should '
                  'contact the indexing site (e.g., the torrent site), not the tracker. You may, '
                  'however, submit a report using our form.',
                  'Ponieważ tracker nie przechowuje żadnych plików ani treści, właściciele praw '
                  'powinni kontaktować się ze stroną indeksującą (np. serwisem z torrentami), a nie '
                  'z trackerem. Możesz jednak złożyć zgłoszenie przez nasz formularz.'),
    'faq_q5':    ('Do you have .torrent files?', 'Czy macie pliki .torrent?'),
    'faq_a5':    ('No. The tracker does not store .torrent files. It only tracks active peer '
                  'connections.',
                  'Nie. Tracker nie przechowuje plików .torrent. Śledzi wyłącznie aktywne '
                  'połączenia peerów.'),
})

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
})


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

# ── transparency ────────────────────────────────────────────────────────────
add('transparency', {
    'h1':      ('Transparency Report', 'Raport przejrzystości'),
    'intro':   ('This page shows all organizations that have submitted removal requests and their '
                'outcomes.',
                'Ta strona pokazuje wszystkie organizacje, które złożyły wnioski o usunięcie, oraz '
                'wyniki tych wniosków.'),
    'loading': ('Loading data...', 'Wczytywanie danych...'),
    'company': ('Company / Organization', 'Firma / Organizacja'),
    'represented': ('Represented Entity', 'Reprezentowany podmiot'),
    'total':   ('Total Requests', 'Wszystkich wniosków'),
    'reviewed': ('Reviewed', 'Rozpatrzonych'),
    'blocked': ('Blocked', 'Zablokowanych'),
    'pending': ('Awaiting Review', 'Oczekujących'),
})

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

# ── abuse report ────────────────────────────────────────────────────────────
add('report', {
    'h1':        ('Abuse Report Form', 'Formularz zgłoszenia nadużycia'),
    'intro':     ('Use this form to report a copyright infringement regarding a torrent tracked by '
                  'our tracker.',
                  'Użyj tego formularza, aby zgłosić naruszenie praw autorskich dotyczące torrenta '
                  'śledzonego przez nasz tracker.'),
    'name':      ('Full name', 'Imię i nazwisko'),
    'name_ph':   ('John Smith', 'Jan Kowalski'),
    'rep':       ('Represented entity', 'Reprezentowany podmiot'),
    'rep_ph':    ('Name of the entity whose rights were infringed',
                  'Nazwa podmiotu, którego prawa naruszono'),
    'company':   ('Company / Organization', 'Firma / Organizacja'),
    'company_ph': ('Your company name', 'Nazwa Twojej firmy'),
    'email':     ('Email address', 'Adres e-mail'),
    'email_err': ('Invalid email address', 'Nieprawidłowy adres e-mail'),
    'object':    ('Object title', 'Tytuł utworu'),
    'object_ph': ('Title of the infringing work', 'Tytuł naruszającego utworu'),
    'link':      ('Link to the torrent page', 'Link do strony torrenta'),
    'link_err':  ('Invalid URL', 'Nieprawidłowy adres URL'),
    'hash':      ('Info Hash (SHA1, 40 hex characters)', 'Info Hash (SHA1, 40 znaków szesnastkowych)'),
    'hash_err':  ('Info Hash must be exactly 40 hexadecimal characters (0-9, a-f)',
                  'Info Hash musi mieć dokładnie 40 znaków szesnastkowych (0-9, a-f)'),
    'magnet':    ('Magnet Link (optional)', 'Link magnet (opcjonalnie)'),
    'magnet_err': ('Invalid magnet link or hash mismatch with Info Hash field',
                   'Nieprawidłowy link magnet albo hash niezgodny z polem Info Hash'),
    'message':   ('Additional information (optional)', 'Dodatkowe informacje (opcjonalnie)'),
    'message_ph': ('Additional details about the report...', 'Dodatkowe szczegóły zgłoszenia...'),
    'message_err': ('Message exceeds the maximum allowed length',
                    'Wiadomość przekracza maksymalną dozwoloną długość'),
    'required':  ('This field is required', 'To pole jest wymagane'),
    'submit':    ('Submit Report', 'Wyślij zgłoszenie'),
})

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


# ── whitelist ───────────────────────────────────────────────────────────────
add('whitelist', {
    'h1':          ('Whitelist — register your torrent', 'Whitelista — zarejestruj swój torrent'),
    'need_account': ('Browsing the whitelist requires an account with whitelist access.',
                     'Przeglądanie whitelisty wymaga konta z dostępem do whitelisty.'),
    'open_mode':   ('This tracker currently runs in <strong>open (blacklist) mode</strong>: every torrent '
                    'is served unless it was blocked after an abuse report. There is nothing to register.',
                    'Ten tracker działa obecnie w <strong>trybie otwartym (blacklisty)</strong>: obsługiwany '
                    'jest każdy torrent, o ile nie został zablokowany po zgłoszeniu nadużycia. Nie ma tu '
                    'czego rejestrować.'),
    'open_see':    ('See <a href=":url">Info</a> for the announce URLs.',
                    'Adresy announce znajdziesz w <a href=":url">Informacjach</a>.'),
    'sched_notice': ('Whitelist hours: <strong>:hours</strong>. Right now the tracker is in '
                     '<strong>:mode mode</strong>:next:pending',
                     'Godziny whitelisty: <strong>:hours</strong>. W tej chwili tracker jest w '
                     '<strong>trybie :mode</strong>:next:pending'),
    'sched_next':  ('; next change at <strong>:at</strong> (:tz).',
                    '; najbliższa zmiana o <strong>:at</strong> (:tz).'),
    'sched_pending': (' Registrations made now become active at the start of the next whitelist hours.',
                      ' Rejestracje złożone teraz staną się aktywne wraz z początkiem najbliższych '
                      'godzin whitelisty.'),
    'only_hours':  (' during whitelist hours (open mode otherwise)',
                    ' w godzinach whitelisty (poza nimi tryb otwarty)'),
    'users_only':  ('This tracker serves <strong>registered torrents only</strong>:hours. Torrent '
                    'registration is limited to <strong>signed-in users</strong> with whitelist access.',
                    'Ten tracker obsługuje <strong>wyłącznie zarejestrowane torrenty</strong>:hours. '
                    'Rejestracja torrentów jest ograniczona do <strong>zalogowanych użytkowników</strong> '
                    'z dostępem do whitelisty.'),
    'no_access':   ('Your account does not have whitelist access. Check <a href=":url">your groups</a> '
                    'or contact the site admin.',
                    'Twoje konto nie ma dostępu do whitelisty. Sprawdź <a href=":url">swoje grupy</a> '
                    'albo skontaktuj się z administratorem serwisu.'),
    'closed':      ('This tracker serves <strong>registered torrents only</strong>:hours. Public '
                    'registration is currently <strong>unavailable</strong>:why. Torrents posted on the '
                    'community forum are registered automatically.',
                    'Ten tracker obsługuje <strong>wyłącznie zarejestrowane torrenty</strong>:hours. '
                    'Publiczna rejestracja jest obecnie <strong>niedostępna</strong>:why. Torrenty '
                    'publikowane na forum społecznościowym rejestrują się automatycznie.'),
    'no_captcha':  (' (CAPTCHA is not configured on this site)',
                    ' (CAPTCHA nie jest skonfigurowana na tej stronie)'),
    'signed_in_as': ('This tracker serves <strong>registered torrents only</strong>:hours. You are signed '
                     'in as <strong>:user</strong> — paste one or more magnet links (or plain '
                     '40-character info hashes) and they are added to the whitelist under your account '
                     '(no CAPTCHA needed). Torrents posted on the community forum are registered '
                     'automatically.',
                     'Ten tracker obsługuje <strong>wyłącznie zarejestrowane torrenty</strong>:hours. '
                     'Jesteś zalogowany jako <strong>:user</strong> — wklej jeden lub więcej linków '
                     'magnet (albo same 40-znakowe info hashe), a zostaną dodane do whitelisty na Twoim '
                     'koncie (bez CAPTCHY). Torrenty publikowane na forum społecznościowym rejestrują '
                     'się automatycznie.'),
    'anon':        ('This tracker serves <strong>registered torrents only</strong>:hours. Registration is '
                    '<strong>free and anonymous</strong> — paste one or more magnet links (or plain '
                    '40-character info hashes), :captcha and the hashes are added to the whitelist. '
                    'Torrents posted on the community forum are registered automatically.',
                    'Ten tracker obsługuje <strong>wyłącznie zarejestrowane torrenty</strong>:hours. '
                    'Rejestracja jest <strong>darmowa i anonimowa</strong> — wklej jeden lub więcej '
                    'linków magnet (albo same 40-znakowe info hashe), :captcha, a hashe zostaną dodane '
                    'do whitelisty. Torrenty publikowane na forum społecznościowym rejestrują się '
                    'automatycznie.'),
    'captcha_v3':  ('pass the (invisible) CAPTCHA check', 'przejdź (niewidoczną) weryfikację CAPTCHA'),
    'captcha_std': ('solve the CAPTCHA', 'rozwiąż CAPTCHĘ'),
    'count_one':   ('Currently <strong>:n</strong> torrent is registered on this tracker.',
                    'Obecnie na tym trackerze zarejestrowany jest <strong>:n</strong> torrent.'),
    'count_many':  ('Currently <strong>:n</strong> torrents are registered on this tracker.',
                    'Obecnie na tym trackerze zarejestrowanych jest torrentów: <strong>:n</strong>.'),
    'check_head':  ('Check a hash', 'Sprawdź hash'),
    'check_label': ('Magnet link or info hash', 'Link magnet lub info hash'),
    'check_ph':    ('magnet:?xt=urn:btih:… or 40 hex characters',
                    'magnet:?xt=urn:btih:… albo 40 znaków szesnastkowych'),
    'rule_tracker': ('<strong>Only magnet links that already announce to this tracker are accepted</strong> '
                     '— the magnet must contain <code>&amp;tr=:url</code>:alt. Plain hashes are refused.',
                     '<strong>Przyjmujemy wyłącznie linki magnet, które już wskazują na ten tracker'
                     '</strong> — magnet musi zawierać <code>&amp;tr=:url</code>:alt. Same hashe są '
                     'odrzucane.'),
    'rule_tracker_alt': (' (or the HTTP announce URL below)', ' (albo poniższy adres announce HTTP)'),
    'rule_max':    ('Up to <strong>:n</strong> hashes per submission, one per line.',
                    'Do <strong>:n</strong> hashy na zgłoszenie, po jednym w wierszu.'),
    'rule_ip':     ('Your IP address:who stored with each registration to detect abuse. Spam and abusive '
                    'submissions get the :what banned.',
                    'Twój adres IP:who zapisywany przy każdej rejestracji, aby wykrywać nadużycia. Spam '
                    'i zgłoszenia nadużyć skutkują zablokowaniem :what.'),
    'rule_ip_user': (' and account name are', ' oraz nazwa konta są'),
    'rule_ip_anon': (' is', ' jest'),
    'rule_ip_what_user': ('account / IP', 'konta / IP'),
    'rule_ip_what_anon': ('IP', 'IP'),
    'rule_remove': ('Registered hashes may be removed or banned at any time (e.g. after an abuse report). '
                    'Banned hashes cannot be re-registered.',
                    'Zarejestrowane hashe mogą zostać w każdej chwili usunięte lub zbanowane (np. po '
                    'zgłoszeniu nadużycia). Zbanowanych hashy nie można zarejestrować ponownie.'),
    'rule_serve':  ('Registration only tells the tracker to <em>serve</em> the swarm — we do not host, '
                    'index or download any content.',
                    'Rejestracja mówi trackerowi jedynie, by <em>obsługiwał</em> rój — nie hostujemy, nie '
                    'indeksujemy ani nie pobieramy żadnych treści.'),
    'input_label': ('Magnet links / info hashes', 'Linki magnet / info hashe'),
    'input_err':   ('Paste at least one valid magnet link or 40-hex info hash',
                    'Wklej przynajmniej jeden poprawny link magnet albo 40-znakowy info hash'),
    'extra_head':  ('Optional — only used when you register <strong>one</strong> torrent at a time.',
                    'Opcjonalne — używane tylko wtedy, gdy rejestrujesz <strong>jeden</strong> torrent naraz.'),
    'source':      ('Source link', 'Link źródłowy'),
    'source_hint': ('— the page this torrent came from', '— strona, z której pochodzi ten torrent'),
    'source_note': ('<strong>https only.</strong> Shown next to the torrent, behind a confirmation — '
                    'visitors are told we do not vouch for where it goes.',
                    '<strong>Tylko https.</strong> Pokazywany obok torrenta, za potwierdzeniem — '
                    'odwiedzający są uprzedzani, że nie ręczymy za to, dokąd prowadzi.'),
    'desc':        ('Description', 'Opis'),
    'desc_ph':     ('What is it? Technical details are welcome — use a code block for anything that must '
                    'keep its formatting.',
                    'Co to jest? Szczegóły techniczne mile widziane — na wszystko, co musi zachować '
                    'formatowanie, użyj bloku kodu.'),
    'write':       ('Write', 'Pisz'),
    'preview':     ('Preview', 'Podgląd'),
    'format_title': ('Which syntax you are writing in', 'W jakiej składni piszesz'),
    'review_note': ('<strong>The torrent registers immediately.</strong> The link and description are '
                    'shown to a moderator first and appear once approved.',
                    '<strong>Torrent rejestruje się od razu.</strong> Link i opis trafiają najpierw do '
                    'moderatora i pojawiają się po zatwierdzeniu.'),
    'submit':      ('Register', 'Zarejestruj'),
    'probe_head':  ('Checking your submission', 'Sprawdzamy Twoje zgłoszenie'),
    'announce_head': ('Announce URLs', 'Adresy announce'),
    'announce_note': ('Add these to your torrent / magnet (<code>&amp;tr=</code>) so peers find each '
                      'other through this tracker:',
                      'Dodaj je do swojego torrenta / magneta (<code>&amp;tr=</code>), aby peery '
                      'znajdowały się nawzajem przez ten tracker:'),
})

# The formatting toolbar. Titles only — the buttons themselves are symbols.
add('rt', {
    'bold':      ('Bold (Ctrl+B)', 'Pogrubienie (Ctrl+B)'),
    'italic':    ('Italic (Ctrl+I)', 'Kursywa (Ctrl+I)'),
    'underline': ('Underline', 'Podkreślenie'),
    'strike':    ('Strikethrough', 'Przekreślenie'),
    'color':     ('Colour', 'Kolor'),
    'size':      ('Font size', 'Rozmiar czcionki'),
    'highlight': ('Highlight', 'Wyróżnienie'),
    'sub':       ('Subscript', 'Indeks dolny'),
    'sup':       ('Superscript', 'Indeks górny'),
    'link':      ('Link (Ctrl+K)', 'Odnośnik (Ctrl+K)'),
    'image':     ('Image', 'Obraz'),
    'list':      ('Bulleted list', 'Lista wypunktowana'),
    'olist':     ('Numbered list', 'Lista numerowana'),
    'quote':     ('Quote', 'Cytat'),
    'code':      ('Code', 'Kod'),
    'table':     ('Table', 'Tabela'),
    'spoiler':   ('Spoiler', 'Spoiler'),
    'center':    ('Centre', 'Wyśrodkowanie'),
    'hr':        ('Horizontal rule', 'Linia pozioma'),
    'toolbar':   ('Formatting', 'Formatowanie'),
    'list_word': ('List', 'Lista'),
})


# ── search ──────────────────────────────────────────────────────────────────
add('search', {
    'h1':          ('Search', 'Szukaj'),
    'disabled':    ('The search index is currently <strong>disabled</strong> on this tracker.',
                    'Indeks wyszukiwania jest obecnie <strong>wyłączony</strong> na tym trackerze.'),
    'need_account': ('Searching the tracker index requires an account with search access.',
                     'Przeszukiwanie indeksu trackera wymaga konta z dostępem do wyszukiwarki.'),
    'no_access':   ('Your account does not have search access. Check <a href=":url">your groups</a> or '
                    'contact the site admin.',
                    'Twoje konto nie ma dostępu do wyszukiwarki. Sprawdź <a href=":url">swoje grupy</a> '
                    'albo skontaktuj się z administratorem serwisu.'),
    'intro':       ('Search everything this tracker has <em>seen</em> (resolved metadata only:wl). This '
                    'is a catalogue of hashes observed in the swarm — nothing is hosted here.',
                    'Przeszukaj wszystko, co ten tracker <em>widział</em> (tylko rozwiązane metadane:wl). '
                    'To katalog hashy zaobserwowanych w roju — nic tu nie jest hostowane.'),
    'intro_wl':    (', registered torrents included', ', wliczając zarejestrowane torrenty'),
    'placeholder': ('Name…', 'Nazwa…'),
    'placeholder_files': ('Name or file name…', 'Nazwa albo nazwa pliku…'),
    'clear':       ('Clear search', 'Wyczyść wyszukiwanie'),
    'best':        ('Best match first', 'Najlepiej pasujące najpierw'),
    'best_title':  ('Order by how well the name matches your query (rarer and longer words weigh more); '
                    'column sorts break ties',
                    'Sortuj według dopasowania nazwy do zapytania (rzadsze i dłuższe słowa ważą więcej); '
                    'sortowanie kolumn rozstrzyga remisy'),
    'files':       ('Also search file names', 'Szukaj też w nazwach plików'),
    'files_title': ('Also match torrent file names', 'Dopasowuj także nazwy plików w torrencie'),
    'content_title': ('Which descriptions to include, by review state. The default hides rejected ones — '
                      'a description a moderator turned down should not be the first thing you read, but '
                      'the torrent behind it is still a torrent.',
                      'Które opisy uwzględnić, według stanu moderacji. Domyślnie odrzucone są ukryte — '
                      'opis odrzucony przez moderatora nie powinien być pierwszą rzeczą, jaką czytasz, '
                      'ale torrent za nim to nadal torrent.'),
    'c_not_rejected': ('Hide rejected', 'Ukryj odrzucone'),
    'c_approved':  ('Approved only', 'Tylko zatwierdzone'),
    'c_approved_or_none': ('Approved & unreviewed', 'Zatwierdzone i nierozpatrzone'),
    'c_pending':   ('Waiting for review', 'Czekające na moderację'),
    'c_none':      ('Nothing written yet', 'Jeszcze nic nie napisano'),
    'c_rejected':  ('Rejected only', 'Tylko odrzucone'),
    'perpage_title': ('Results per page', 'Wyników na stronę'),
    'perpage':     (':n / page', ':n / stronę'),
    'col_name':    ('Name', 'Nazwa'),
    'col_size':    ('Size', 'Rozmiar'),
    'col_sl':      ('S / L', 'S / L'),
    'col_sl_title': ('Seeders / leechers', 'Seedery / leechery'),
    'col_rating':  ('Rating', 'Ocena'),
    'col_rating_title': ('How visitors rated it. The number of ratings is in the tooltip — a percentage '
                         'on its own cannot tell one vote from four hundred.',
                         'Jak ocenili to odwiedzający. Liczba ocen jest w dymku — sam procent nie odróżni '
                         'jednego głosu od czterystu.'),
    'col_last':    ('Last seen', 'Ostatnio widziany'),
    'details':     ('Details', 'Szczegóły'),
    'files_head':  ('Files', 'Pliki'),
})

# ── report status / block check ─────────────────────────────────────────────
add('status', {
    'h1':          ('Check Report Status', 'Sprawdź status zgłoszenia'),
    'intro':       ('Enter your report number, info hash, or magnet link to check the current status.',
                    'Podaj numer zgłoszenia, info hash albo link magnet, aby sprawdzić bieżący status.'),
    'query':       ('Report Number, Info Hash or Magnet Link',
                    'Numer zgłoszenia, info hash albo link magnet'),
    'query_ph':    ('e.g. 42, a1b2c3d4e5f6... or magnet:?xt=urn:btih:...',
                    'np. 42, a1b2c3d4e5f6... albo magnet:?xt=urn:btih:...'),
    'query_err':   ('Enter a report number, 40-character info hash, or magnet link',
                    'Podaj numer zgłoszenia, 40-znakowy info hash albo link magnet'),
    'email':       ('Email address', 'Adres e-mail'),
    'email_ph':    ('Email used in the report', 'Adres e-mail użyty w zgłoszeniu'),
    'email_err':   ('Valid email address is required', 'Wymagany jest poprawny adres e-mail'),
    'submit':      ('Check Status', 'Sprawdź status'),
    'result_head': ('Your Report Status', 'Status Twojego zgłoszenia'),
    'f_number':    ('Number', 'Numer'),
    'f_reporter':  ('Reporter', 'Zgłaszający'),
    'f_email':     ('Email', 'E-mail'),
    'f_company':   ('Company', 'Firma'),
    'f_rep':       ('Representative', 'Przedstawiciel'),
    'f_object':    ('Object', 'Utwór'),
    'f_link':      ('Link', 'Odnośnik'),
    'f_hash':      ('Info Hash', 'Info Hash'),
    'f_magnet':    ('Magnet Link', 'Link magnet'),
    'f_status':    ('Status', 'Status'),
    'f_date':      ('Submission date', 'Data zgłoszenia'),
    'guide':       ('Status Guide:', 'Objaśnienie statusów:'),
    'b_pending':   ('Awaiting Review', 'Oczekuje na rozpatrzenie'),
    'b_pending_note': ('Your report has been received and is waiting for an administrator to review it.',
                       'Twoje zgłoszenie wpłynęło i czeka na rozpatrzenie przez administratora.'),
    'b_checked':   ('Reviewed', 'Rozpatrzone'),
    'b_checked_note': ('An administrator has reviewed your report.',
                       'Administrator rozpatrzył Twoje zgłoszenie.'),
    'b_blocked':   ('Blocked', 'Zablokowane'),
    'b_blocked_wl': ('The reported info hash has been banned on the tracker (removed from the '
                     'whitelist; it cannot be registered again).',
                     'Zgłoszony info hash został zbanowany na trackerze (usunięty z whitelisty; nie '
                     'można go zarejestrować ponownie).'),
    'b_blocked_bl': ('The reported info hash has been permanently added to the tracker blacklist.',
                     'Zgłoszony info hash został trwale dodany do blacklisty trackera.'),
    'b_archived':  ('Archived / Closed', 'Zarchiwizowane / zamknięte'),
    'b_archived_note': ('The report has been processed and archived. No further action will be taken '
                        'unless a new report is submitted.',
                        'Zgłoszenie zostało rozpatrzone i zarchiwizowane. Nie podejmiemy dalszych '
                        'działań, chyba że wpłynie nowe zgłoszenie.'),
    'appeal_head': ('Submit an Appeal', 'Złóż odwołanie'),
    'appeal_desc': ('If you believe this hash was blocked in error, you can submit an appeal for review.',
                    'Jeśli uważasz, że ten hash zablokowano przez pomyłkę, możesz złożyć odwołanie do '
                    'ponownego rozpatrzenia.'),
    'a_name':      ('Full Name', 'Imię i nazwisko'),
    'a_name_ph':   ('Your full name', 'Twoje imię i nazwisko'),
    'a_email':     ('Email Address', 'Adres e-mail'),
    'a_email_err': ('Invalid email address', 'Nieprawidłowy adres e-mail'),
    'a_reason':    ('Reason', 'Powód'),
    'a_reason_block_ph': ('Explain why you believe this hash should be blocked / re-examined...',
                          'Wyjaśnij, dlaczego uważasz, że ten hash powinien zostać zablokowany / '
                          'ponownie rozpatrzony...'),
    'a_reason_appeal': ('Reason for Appeal', 'Powód odwołania'),
    'a_reason_appeal_ph': ('Explain why you believe this block should be reconsidered...',
                           'Wyjaśnij, dlaczego uważasz, że tę blokadę należy ponownie rozważyć...'),
    'a_submit':    ('Submit Appeal', 'Wyślij odwołanie'),
    'required':    ('This field is required', 'To pole jest wymagane'),
    'bc_head':     ('Block Check', 'Sprawdzenie blokady'),
    'bc_intro':    ('Check if an info hash or magnet link is currently blocked on our tracker.',
                    'Sprawdź, czy info hash albo link magnet jest obecnie zablokowany na naszym trackerze.'),
    'bc_query':    ('Info Hash or Magnet Link', 'Info hash albo link magnet'),
    'bc_query_ph': ('40-char hex hash or magnet:?xt=urn:btih:...',
                    '40-znakowy hash szesnastkowy albo magnet:?xt=urn:btih:...'),
    'bc_query_err': ('Enter a valid 40-character hex hash or a magnet link',
                     'Podaj poprawny 40-znakowy hash szesnastkowy albo link magnet'),
    'bc_submit':   ('Check Block Status', 'Sprawdź status blokady'),
    'bc_result':   ('Block Check Result', 'Wynik sprawdzenia blokady'),
    'bc_whitelist': ('Whitelist', 'Whitelista'),
    'bc_company':  ('Company / Organization', 'Firma / Organizacja'),
    'bc_entity':   ('Represented Entity', 'Reprezentowany podmiot'),
})


def emit(root):
    for code, idx in (('en', 0), ('pl', 1)):
        lines = ["<?php", "/**",
                 " * %s strings for the tracker's public pages." % code.upper(),
                 " *",
                 " * Generated from one (English, Polish) source, so a key can never exist in one",
                 " * language and be missing from the other. Keys are flat and dotted; a miss falls",
                 " * back to English and then to the key itself (includes/lang.php).",
                 " */", "return ["]
        for k in sorted(S):
            v = S[k][idx].replace('\\', '\\\\').replace("'", "\\'")
            lines.append("    '%s' => '%s'," % (k, v))
        lines.append("];")
        path = os.path.join(root, 'lang', code + '.php')
        io.open(path, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines) + '\n')
        print('%s  %d strings' % (path, len(S)))


if __name__ == '__main__':
    emit(sys.argv[1] if len(sys.argv) > 1 else '.')
