# -*- coding: utf-8 -*-
"""Terms of service

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

1.73.0: rewritten against the code of this version, like info.py beside it. The page is built from
pageContentSpec('tos') (includes/pagecontent.php), which decides which clause appears under which
condition. A clause promises only what the code does: what is stored and for how long is the Info page's
job (these point there), and what moderators can do is exactly what the Reports page lets them do
(includes/reports.php) — nothing here promises an appeal, a deletion or a notice the code does not have.
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
    'intro':   ('By using this tracker and this site, you agree to the following terms:',
                'Korzystając z tego trackera i tej strony, akceptujesz poniższy regulamin:'),
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
    'r7':      ('What this site stores, for how long and who else sees it is listed on the '
                '<a href=":url">Info page</a>; nothing is kept that is not listed there.',
                'Co ta strona przechowuje, jak długo i kto jeszcze to widzi, opisuje '
                '<a href=":url">strona Informacji</a>; nie przechowujemy niczego, czego tam nie wymieniono.'),
    'r_wl':    ('Registering a torrent is free. The address a registration was sent from is stored with it, to '
                'detect abuse. A registered info hash may be removed or banned at any time, and so may the '
                'registrations of somebody who abuses the form.',
                'Rejestracja torrenta jest darmowa. Razem z rejestracją zapisywany jest adres, z którego ją '
                'wysłano, w celu wykrywania nadużyć. Zarejestrowany info hash może zostać w każdej chwili usunięty '
                'albo zbanowany — tak samo jak rejestracje kogoś, kto nadużywa formularza.'),
    'r_index': ('The tracker keeps an <strong>index</strong> of the info hashes it observes, with names and file '
                'lists fetched from the swarm; nothing else is downloaded. It describes what is in the swarms, '
                'not what is hosted here — nothing is.',
                'Tracker prowadzi <strong>indeks</strong> obserwowanych info hashy wraz z nazwami i listami plików '
                'pobranymi z roju; nic więcej nie jest pobierane. Indeks opisuje to, co jest w rojach, a nie to, '
                'co jest tu hostowane — bo nic nie jest.'),
    'r_report': ('Torrents that infringe somebody\'s rights are reported through the <a href=":url">report '
                 'form</a>. A hash may be banned after a report, and the tracker then serves it to nobody; the '
                 'decision can be appealed from the status page.',
                 'Torrenty naruszające czyjeś prawa zgłasza się przez <a href=":url">formularz zgłoszeń</a>. Po '
                 'zgłoszeniu hash może zostać zbanowany i wtedy tracker nie obsługuje go dla nikogo; od tej decyzji '
                 'można się odwołać na stronie statusu.'),
    'r8':      ('Terms may change. Continued use of the service constitutes acceptance of changes.',
                'Regulamin może się zmienić. Dalsze korzystanie z usługi oznacza akceptację zmian.'),
    'r9':      ('<strong>Connecting to the tracker constitutes acceptance of these terms.</strong>',
                '<strong>Połączenie z trackerem oznacza akceptację niniejszego regulaminu.</strong>'),

    # ── accounts ────────────────────────────────────────────────────────────
    'acc_head': ('User accounts', 'Konta użytkowników'),
    'acc1':    ('Creating an account is free and optional — the tracker itself works without one. An '
                'account only unlocks member features (such as the catalogue search) according to '
                'the groups granted to it.',
                'Założenie konta jest darmowe i nieobowiązkowe — sam tracker działa bez niego. Konto '
                'odblokowuje jedynie funkcje dla zalogowanych (na przykład wyszukiwarkę katalogu), '
                'zgodnie z przyznanymi mu grupami.'),
    'acc_own': ('Keep your password to yourself: you are responsible for what is done with your account.',
                'Nie zdradzaj nikomu hasła: odpowiadasz za to, co dzieje się na Twoim koncie.'),
    'acc_twofa': ('A second factor — a code from an authenticator app — can be switched on on the account page, '
                  'and is worth switching on.',
                  'Drugi składnik — kod z aplikacji uwierzytelniającej — można włączyć na stronie konta i warto '
                  'to zrobić.'),
    'acc2':    ('What is stored for an account, and for how long, is listed on the <a href=":url">Info page</a>.',
                'Co przechowujemy dla konta i jak długo, opisuje <a href=":url">strona Informacji</a>.'),
    'acc3':    ('The e-mail address is used for the account itself — verification links, password resets, '
                'confirming a new address, notices about your groups and your security — and for the operator\'s '
                'announcements, which can be switched off on the account page.',
                'Adres e-mail służy samemu kontu — linkom weryfikacyjnym, resetom hasła, potwierdzeniu nowego '
                'adresu, powiadomieniom o Twoich grupach i bezpieczeństwie — oraz ogłoszeniom operatora, które '
                'można wyłączyć na stronie konta.'),
    'acc3_api': ('A partner site the operator connects to accounts through the API — a shop, a sign-in bridge — '
                 'can look an account up and receives its name, e-mail address, state, creation date and groups.',
                 'Serwis partnerski, który operator łączy z kontami przez API — sklep, mostek logowania — może '
                 'wyszukać konto i dostaje jego nazwę, adres e-mail, stan, datę założenia i grupy.'),
    'acc4':    ('Changing the account\'s e-mail address requires confirmation from the current address and then '
                'from the new one. This protects accounts from hijacking.',
                'Zmiana adresu e-mail konta wymaga potwierdzenia z obecnego adresu, a następnie z nowego. Chroni '
                'to konta przed przejęciem.'),
    # Only while users_email_change_cooldown_days > 0 (`email_cooldown`); :days carries its noun.
    'acc4_cooldown': ('Between two changes of the address at least :days must pass.',
                      'Między dwiema zmianami adresu musi minąć co najmniej :days.'),
    'acc5':    ('Cookies are used only to keep you signed in, to protect the forms and to remember the language '
                'you chose. How long a browser stays signed in is chosen when you sign in; tokens are stored only '
                'as hashes, and changing the password signs out every device.',
                'Ciasteczka służą wyłącznie do utrzymania zalogowania, ochrony formularzy i zapamiętania '
                'wybranego języka. Jak długo przeglądarka pozostaje zalogowana, wybierasz przy logowaniu; tokeny '
                'przechowujemy wyłącznie jako skróty, a zmiana hasła wylogowuje wszystkie urządzenia.'),
    'acc_groups': ('A group given for a time ends on its date, and what it unlocked stops with it; nothing you '
                   'made with it is deleted.',
                   'Grupa nadana na określony czas kończy się w swoim terminie, a z nią to, co odblokowywała; nic, '
                   'co z jej pomocą zrobiłeś, nie jest usuwane.'),
    'acc_bridge': ('Signing in through a partner site makes that site answer for who you are: it signs you in here '
                   'without a password or a second factor.',
                   'Logowanie przez serwis partnera sprawia, że to on poręcza za Twoją tożsamość: loguje Cię tutaj '
                   'bez hasła i bez drugiego składnika.'),
    'acc6':    ('An account used for abuse — spam, attacks on the service, deliberately registering infringing '
                'content after a warning — may be silenced, suspended or deleted, and the torrents it registered '
                'removed.',
                'Konto wykorzystywane do nadużyć — spam, ataki na usługę, celowe rejestrowanie naruszających treści '
                'mimo ostrzeżenia — może zostać wyciszone, zawieszone albo usunięte, a zarejestrowane przez nie '
                'torrenty usunięte.'),
    'acc7':    ('To have your account deleted, write to the operator from the account\'s e-mail address; the Info '
                'page says what deleting removes and what stays.',
                'Aby usunąć konto, napisz do operatora z adresu e-mail przypisanego do konta; strona Informacji '
                'mówi, co usunięcie usuwa, a co zostaje.'),
    'acc8':    ('Searching the index is a member feature. The results show what the tracker has observed in the '
                'swarms, not what is hosted here — nothing is.',
                'Wyszukiwarka indeksu jest funkcją dla zalogowanych. Wyniki pokazują to, co tracker zaobserwował '
                'w rojach, a nie to, co jest tu hostowane — bo nic nie jest.'),

    # ── what members write ──────────────────────────────────────────────────
    'w_head':  ('What you write', 'To, co piszesz'),
    'w1':      ('You are responsible for everything you write here. Write only what you may publish: nothing '
                'illegal, no links to infringing copies, nobody else\'s personal data, no harassment, threats or '
                'hate, no spam and no advertising.',
                'Odpowiadasz za wszystko, co tu piszesz. Pisz tylko to, co możesz publikować: nic nielegalnego, '
                'żadnych linków do naruszających kopii, żadnych cudzych danych osobowych, nękania, gróźb ani '
                'nienawiści, żadnego spamu ani reklam.'),
    'w_comments': ('Comments belong to the torrent they are under: stay on its subject. You can correct a comment '
                   'for a short while and take it back for a little longer.',
                   'Komentarze należą do torrenta, pod którym stoją: trzymaj się jego tematu. Komentarz możesz '
                   'przez krótką chwilę poprawić, a przez nieco dłuższą cofnąć.'),
    'w_guests': ('A visitor who comments without an account is bound by the same rules.',
                 'Gość, który komentuje bez konta, podlega tym samym zasadom.'),
    'w_descriptions': ('A description must describe the torrent it is attached to, and a source link must lead to '
                       'its source. What you submit may be reviewed, improved by others — who are credited too — '
                       'and removed; by submitting it you let the site publish it, credited to you as the Info '
                       'page describes.',
                       'Opis musi opisywać torrent, do którego jest dołączony, a link do źródła musi prowadzić do '
                       'jego źródła. To, co wyślesz, może zostać sprawdzone, poprawione przez innych — również '
                       'podpisanych — i usunięte; wysyłając to, zgadzasz się, by strona to opublikowała '
                       'z podpisem, tak jak opisuje to strona Informacji.'),
    'w_lists': ('The name and description of a list you share are read by other members: the same rules apply.',
                'Nazwę i opis listy, którą udostępniasz, czytają inni członkowie: obowiązują te same zasady.'),
    'w_messages': ('Private messages are private, but they are no place for abuse: the person who receives a '
                   'message can report it, and a moderator then reads that message and the one before it.',
                   'Prywatne wiadomości są prywatne, ale nie są miejscem na nadużycia: odbiorca może zgłosić '
                   'wiadomość, a moderator czyta wtedy tę wiadomość i poprzednią.'),
    'w_shout': ('The shoutbox is shared by everybody in it: keep lines short and do not flood it.',
                'Shoutbox dzielą wszyscy, którzy w nim są: pisz krótko i go nie zalewaj.'),
    'w_profile': ('Your picture, cover and profile description are seen by other members: nothing indecent, and '
                  'nobody else\'s photo or trademark without their permission.',
                  'Twoje zdjęcie profilowe, okładkę i opis profilu widzą inni członkowie: nic nieprzyzwoitego '
                  'i żadnych cudzych zdjęć ani znaków towarowych bez zgody ich właściciela.'),

    # ── reports and moderation ──────────────────────────────────────────────
    'm_head':  ('Reports and moderation', 'Zgłoszenia i moderacja'),
    'm1':      ('Report what breaks these terms with the flag beside it. Only moderators read reports; the author '
                'is never told who reported, and the reporter is told the outcome.',
                'To, co łamie ten regulamin, zgłaszaj flagą obok. Zgłoszenia czytają wyłącznie moderatorzy; autor '
                'nigdy się nie dowiaduje, kto zgłosił, a zgłaszający dostaje informację o wyniku.'),
    'm2':      ('For anything that breaks these terms a moderator may remove the words; warn the author; silence '
                'the author — no messages, no lines in the shoutbox, no comments, no profile or list description — '
                'for a day, a week, a month or until lifted; or suspend the account for a week, a month or until '
                'lifted.',
                'Za wszystko, co łamie ten regulamin, moderator może usunąć treść; upomnieć autora; wyciszyć go — '
                'bez wiadomości, bez wpisów w shoutboksie, bez komentarzy, bez opisu profilu i list — na dobę, '
                'tydzień, miesiąc albo do odwołania; albo zawiesić konto na tydzień, miesiąc albo do odwołania.'),
    'm3':      ('Each of these is done either silently or as a warning: a warning reaches the author among their '
                'notifications, with the reason, and stays with the account.',
                'Każde z tych działań odbywa się po cichu albo jako ostrzeżenie: ostrzeżenie trafia do autora '
                'wśród powiadomień, z podanym powodem, i zostaje przy koncie.'),
    'm4':      ('A silenced member can still read, report and delete their own words. A suspended account cannot '
                'sign in, and its profile is hidden. A silence or a suspension with a date ends by itself.',
                'Wyciszony członek nadal może czytać, zgłaszać i usuwać własne wpisy. Zawieszone konto nie może '
                'się zalogować, a jego profil jest ukryty. Wyciszenie albo zawieszenie z datą kończy się samo.'),
    'm5':      ('Moderators cannot act against other moderators or administrators, nor against themselves, and an '
                'account is suspended with no end date, or deleted, only by the site\'s administrators. There is no '
                'formal appeal against a moderator\'s decision, but you can always write to the operator.',
                'Moderatorzy nie mogą działać przeciw innym moderatorom ani administratorom, ani przeciw sobie, '
                'a zawiesić konto bez daty końca albo je usunąć mogą wyłącznie administratorzy strony. Od decyzji '
                'moderatora nie ma formalnego odwołania, ale zawsze możesz napisać do operatora.'),

    # ── anti-spam ───────────────────────────────────────────────────────────
    's_head':  ('Anti-spam', 'Ochrona przed spamem'),
    's1':      ('Writing is paced: a few writes are free, then the pauses grow; the same words sent twice in a short '
                'time are refused; pushing past the limits brings a CAPTCHA.',
                'Pisanie ma swoje tempo: kilka wpisów nie wymaga czekania, potem przerwy rosną; te same słowa '
                'wysłane dwa razy w krótkim czasie są odrzucane; naciskanie ponad limity kończy się CAPTCHĄ.'),
    # Only while antispam_new_days > 0 (`antispam_new`); :days carries its noun.
    's1_new':  ('New accounts are paced more slowly for :days after they are created.',
                'Nowe konta przez :days od założenia mają wolniejsze tempo.'),
    's2':      ('Getting around the limits — with more accounts, more addresses or a script — is abuse under these '
                'terms.',
                'Obchodzenie limitów — kolejnymi kontami, adresami albo skryptem — jest nadużyciem w rozumieniu '
                'tego regulaminu.'),

    # ── languages and cookies ───────────────────────────────────────────────
    'lang_head': ('Languages and cookies', 'Języki i ciasteczka'),
    'lang1':     ('Choosing a language with the switcher sets a cookie named <code>lang</code> for the browser '
                  'session only; it holds the language\'s code and nothing else. A signed-in account may save a '
                  'language preference, which is kept with the account.',
                  'Wybór języka przełącznikiem ustawia ciasteczko <code>lang</code> tylko na czas sesji '
                  'przeglądarki; zawiera kod języka i nic więcej. Zalogowane konto może zapisać preferowany '
                  'język, przechowywany razem z kontem.'),
})
