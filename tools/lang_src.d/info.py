# -*- coding: utf-8 -*-
"""Info

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

1.73.0: rewritten against the code of this version. The page is built from pageContentSpec('info')
(includes/pagecontent.php), which says which of these strings appear and under which condition; the
facts behind every sentence -- what is kept, where, for how long -- are listed with their file and line
in scratchpad/progress_1730c.md ("Claims table"). A sentence here that the code stops making true is a
bug in this file, not in the code.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── info ────────────────────────────────────────────────────────────────────
add('info', {
    'h1':        ('Tracker Information', 'Informacje o trackerze'),

    # ── the tracker ─────────────────────────────────────────────────────────
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
    'a_how':     ('A BitTorrent client asks the tracker for the other peers of a torrent with an "announce": '
                  'the torrent\'s info hash, an ID the client makes up for itself, the port it listens on, '
                  'how much it has uploaded and downloaded and how much is left, and whether it is starting, '
                  'finishing or stopping. The tracker also sees the IP address the request comes from. It '
                  'answers with the addresses and ports of other peers of the same torrent — that is how '
                  'clients find each other.',
                  'Klient BitTorrent prosi tracker o pozostałe peery torrenta żądaniem „announce”: podaje '
                  'info hash torrenta, identyfikator, który sam sobie nadaje, port, na którym nasłuchuje, ile '
                  'wysłał i pobrał, ile mu jeszcze zostało oraz czy właśnie zaczyna, kończy pobieranie, czy się '
                  'zatrzymuje. '
                  'Tracker widzi też adres IP, z którego przyszło żądanie. Odpowiada adresami i portami innych '
                  'peerów tego samego torrenta — tak klienci się odnajdują.'),
    'a_memory':  ('OpenTracker keeps every peer in memory only — its address, its port and whether it is '
                  'seeding — and forgets it 45 minutes after its last announce (clients are asked to announce '
                  'every half an hour); a restart forgets everybody at once. Announces are not logged, and they '
                  'never reach this website or its database. The only request the tracker writes down, in the '
                  'server\'s system log, is a full scrape — a request for the whole list of torrents at once — '
                  'with the address that asked. If the operator runs the tracker\'s traffic prioritisation, the '
                  'server\'s firewall also holds, in memory only, the addresses the tracker answered in the '
                  'last three hours.',
                  'OpenTracker trzyma każdego peera wyłącznie w pamięci — jego adres, port i to, czy seeduje — '
                  'i zapomina o nim 45 minut po jego ostatnim announce (klienci są proszeni o announce co pół '
                  'godziny); restart zapomina wszystkich naraz. Announce nie są zapisywane w żadnym logu i nigdy nie '
                  'trafiają do tej strony ani do jej bazy danych. Jedyne żądanie, które tracker zapisuje, w logu '
                  'systemowym serwera, to pełny scrape — prośba o całą listę torrentów naraz — razem z adresem, '
                  'z którego o nią poproszono. Jeśli operator używa priorytetyzacji ruchu '
                  'trackera, zapora serwera trzyma też, wyłącznie w pamięci, adresy, którym tracker '
                  'odpowiedział w ciągu ostatnich trzech godzin.'),

    # ── whitelist, its hours, registering ───────────────────────────────────
    'q_wl':      ('Whitelist mode', 'Tryb whitelisty'),
    'a_wl':      ('This tracker runs OpenTracker in <strong>whitelist mode</strong>: it answers announces only '
                  'for info hashes that were <strong>registered</strong> beforehand, and refuses every other '
                  'hash. A registered hash may be removed, or banned, for example after a report.',
                  'Ten tracker uruchamia OpenTracker w <strong>trybie whitelisty</strong>: odpowiada na '
                  'announce wyłącznie dla info hashy, które zostały wcześniej <strong>zarejestrowane</strong>, '
                  'a każdy inny hash odrzuca. Zarejestrowany hash może zostać usunięty albo zbanowany, na '
                  'przykład po zgłoszeniu.'),
    'a_wl_api':  ('Partner sites connected through the tracker\'s API can register their torrents automatically.',
                  'Serwisy partnerskie połączone przez API trackera mogą rejestrować swoje torrenty automatycznie.'),
    'q_sched':   ('Whitelist hours', 'Godziny whitelisty'),
    'a_sched':   ('The tracker changes mode on a schedule: during whitelist hours (:hours) it serves registered '
                  'torrents only, and outside them it runs as an open tracker that serves every torrent.',
                  'Tracker zmienia tryb według harmonogramu: w godzinach whitelisty (:hours) obsługuje wyłącznie '
                  'zarejestrowane torrenty, a poza nimi działa jako tracker otwarty, który obsługuje każdy torrent.'),
    'a_sched_wl':   ('Right now it serves registered torrents only.',
                     'W tej chwili obsługuje wyłącznie zarejestrowane torrenty.'),
    'a_sched_open': ('Right now it is open.', 'W tej chwili jest otwarty.'),
    'q_reg':     ('Registering a torrent', 'Rejestracja torrenta'),
    'a_reg_public': ('Anyone can register a magnet link or an info hash, free of charge, on the '
                     '<a href=":url">Whitelist page</a>. The form asks for a CAPTCHA every time, limits how many '
                     'torrents one address may send in an hour and in a day, and stores the address a '
                     'registration was sent from — to fight abuse — for as long as the registration exists.',
                     'Każdy może bezpłatnie zarejestrować link magnet albo info hash na '
                     '<a href=":url">stronie whitelisty</a>. Formularz za każdym razem prosi o CAPTCHĘ, ogranicza, '
                     'ile torrentów jeden adres może wysłać w ciągu godziny i doby, i przechowuje adres, z którego '
                     'wysłano rejestrację — w celu zwalczania nadużyć — tak długo, jak ta rejestracja istnieje.'),
    'a_reg_members': ('Signed-in members whose group allows it register magnet links or info hashes on the '
                      '<a href=":url">Whitelist page</a>, free of charge and without a CAPTCHA. A registration '
                      'keeps the account that made it and the address it was sent from, for as long as it exists.',
                      'Zalogowani członkowie, którym pozwala na to ich grupa, rejestrują linki magnet albo info '
                      'hashe na <a href=":url">stronie whitelisty</a>, bezpłatnie i bez CAPTCHY. Rejestracja '
                      'zachowuje konto, które jej dokonało, i adres, z którego ją wysłano, tak długo, jak istnieje.'),
    # 1.73.0 (part E): with the probe on (includes/wlprobe.php) a registration is served WHILE it proves itself —
    # its proof is a peer announcing here, which a whitelist-mode tracker refuses for a hash it does not serve.
    'a_reg_probe':  ('A new registration has to prove itself — its torrent\'s metadata found and at least one peer '
                     'announcing here — and it is served while it tries; one that has not proved itself in the time '
                     'the operator set stops being served.',
                     'Nowa rejestracja musi się wykazać — metadane jej torrenta muszą się znaleźć, a co najmniej '
                     'jeden peer musi tutaj wysyłać announce — i na czas próby jest obsługiwana; jeśli nie wykaże '
                     'się w czasie wyznaczonym przez operatora, przestaje być obsługiwana.'),

    # ── the index ───────────────────────────────────────────────────────────
    'q_index':   ('The observed-hash index', 'Indeks zaobserwowanych hashy'),
    'a_index':   ('Besides serving swarms, this tracker keeps a catalogue of the info hashes it sees in its own '
                  'full scrapes: for each, the numbers of seeders, leechers and completed downloads and when it '
                  'was first and last seen — never who was in the swarm. For a limited number of them a day, '
                  'the metadata worker fetches the torrent\'s name, size and file list from the swarm itself, '
                  'sharing nothing back; no file content is ever downloaded.',
                  'Poza obsługą rojów tracker prowadzi katalog info hashy, które widzi we własnych pełnych '
                  'scrape’ach: dla każdego liczbę seedów, leecherów i ukończonych pobrań oraz kiedy był widziany '
                  'pierwszy i ostatni raz — nigdy to, kto był w roju. Dla ograniczonej liczby z nich dziennie '
                  'worker metadanych pobiera z samego roju nazwę torrenta, jego rozmiar i listę plików, niczego '
                  'nie udostępniając w zamian; zawartość plików nigdy nie jest pobierana.'),
    'a_index_fed': ('Some names and file lists come from another tracker this one exchanges metadata with.',
                    'Część nazw i list plików pochodzi z innego trackera, z którym ten wymienia metadane.'),
    # :grace, :protect, :days — a number of days WITH its noun ("3 days", "1 dzień"): pageContentDays().
    'a_index_life': ('An entry whose name never arrives is removed :grace after it was first seen; one with '
                     'a name stays for :protect after the last scrape that still showed a seeder. Registered '
                     'and banned hashes are not in the catalogue — they have their own records.',
                     'Wpis, do którego nazwa nigdy nie dotrze, jest usuwany :grace po pierwszym zauważeniu; '
                     'wpis z nazwą zostaje przez :protect od ostatniego scrape’a, który pokazał jeszcze seeda. '
                     'Zarejestrowanych i zbanowanych hashy nie ma w katalogu — mają własne rekordy.'),
    'a_index_kept': ('Torrents somebody keeps among their favourites or on a list are kept longer.',
                     'Torrenty, które ktoś trzyma w ulubionych albo na liście, są przechowywane dłużej.'),
    'a_index_search': ('Members can <a href=":url">search it</a>. What you type is not stored by this site; it '
                       'travels in the page\'s address, so the web server\'s access log may record it.',
                       'Zalogowani mogą go <a href=":url">przeszukiwać</a>. Ta strona nie zapisuje tego, co '
                       'wpisujesz; fraza jedzie w adresie strony, więc może ją odnotować log dostępu serwera WWW.'),

    # ── accounts ────────────────────────────────────────────────────────────
    'q_accounts': ('Accounts', 'Konta'),
    'a_accounts': ('An account is optional — the tracker works without one. It unlocks the member features the '
                   'groups it holds allow.',
                   'Konto jest opcjonalne — tracker działa bez niego. Odblokowuje funkcje dla zalogowanych, na '
                   'które pozwalają jego grupy.'),
    'a_accounts_verify': ('It needs a confirmed e-mail address: until the link sent to it is opened, the account '
                          'has a guest\'s rights.',
                          'Wymaga potwierdzonego adresu e-mail: dopóki link wysłany na ten adres nie zostanie '
                          'otwarty, konto ma uprawnienia gościa.'),
    'a_accounts_email': ('An e-mail address is optional; without one, a forgotten password cannot be reset.',
                         'Adres e-mail jest opcjonalny; bez niego nie da się zresetować zapomnianego hasła.'),
    'a_accounts_twofa': ('The account can be protected with a second factor — a code from an authenticator app, '
                         'asked after the password.',
                         'Konto można zabezpieczyć drugim składnikiem — kodem z aplikacji uwierzytelniającej, '
                         'o który strona prosi po haśle.'),
    'a_devices': ('When you sign in you choose how long this browser stays signed in: until you sign out (the '
                  'default), 30 days, a day or an hour. The account page lists the devices that stay signed in, '
                  'with their address and browser, and can sign out every other one; changing the password signs '
                  'out all of them, and signing out ends only the browser you sign out in.',
                  'Logując się, wybierasz, jak długo ta przeglądarka pozostanie zalogowana: do wylogowania '
                  '(domyślnie), 30 dni, dobę albo godzinę. Strona konta pokazuje urządzenia, które pozostają '
                  'zalogowane, z ich adresem i przeglądarką, i potrafi wylogować wszystkie pozostałe; zmiana '
                  'hasła wylogowuje je wszystkie, a wylogowanie kończy tylko sesję w przeglądarce, w której się '
                  'wylogowujesz.'),
    'a_groups':  ('A group can be given for a time — a Premium membership, for example. When it ends, what it '
                  'unlocked stops working, and nothing you made with it is deleted.',
                  'Grupę można dostać na określony czas — na przykład członkostwo Premium. Gdy się skończy, to, '
                  'co odblokowywała, przestaje działać, a nic, co z jej pomocą zrobiłeś, nie jest usuwane.'),
    'a_groups_shop': ('It is granted by the operator, or bought through a partner shop.',
                      'Nadaje ją operator albo można ją kupić w sklepie partnera.'),
    'a_notify':  ('Notifications tell you about replies, friend requests, changes to your groups and moderators\' '
                  'decisions; a read one is deleted 90 days after it arrived, any one after a year.',
                  'Powiadomienia mówią o odpowiedziach, zaproszeniach do znajomych, zmianach w twoich grupach '
                  'i decyzjach moderatorów; przeczytane powiadomienie jest usuwane 90 dni po nadejściu, każde inne '
                  'po roku.'),
    'a_sounds':  ('A sound for what arrives can be chosen on the account page; none plays until you choose one.',
                  'Dźwięk dla tego, co przychodzi, można wybrać na stronie konta; żaden nie gra, dopóki go nie '
                  'wybierzesz.'),
    'a_bridge':  ('You can also sign in through a partner site (the sign-in bridge). That site vouches for you, so '
                  'no password and no second factor are asked here, and it receives your account name, e-mail '
                  'address and groups.',
                  'Można też zalogować się przez serwis partnera (mostek logowania). Ten serwis za ciebie '
                  'poręcza, więc nie jesteś tu pytany ani o hasło, ani o drugi składnik, a on sam dostaje nazwę '
                  'twojego konta, adres e-mail i grupy.'),

    # ── profiles, favourites, lists ─────────────────────────────────────────
    'q_profiles': ('Profiles', 'Profile'),
    'a_profiles': ('Profiles are shown to signed-in members only — a visitor who is not signed in sees none. A '
                   'profile shows the member\'s name and since when they have been a member, never their e-mail '
                   'address, groups or last sign-in.',
                   'Profile widzą wyłącznie zalogowani — niezalogowany gość nie zobaczy żadnego. Profil pokazuje '
                   'nazwę członka i to, od kiedy nim jest, nigdy jego adresu e-mail, grup ani ostatniego '
                   'logowania.'),
    'a_profiles_bio': ('A few words of your own can stand under your name.',
                       'Pod nazwą może stać kilka twoich słów.'),
    'a_profiles_choice': ('Your favourites, lists, likes or ratings, the descriptions you wrote and the torrents '
                          'you registered appear on it only if you switch them on on the account page and your '
                          'group allows it; every one of them starts switched off.',
                          'Twoje ulubione, listy, polubienia albo oceny, napisane przez ciebie opisy i '
                          'zarejestrowane torrenty pojawiają się na nim tylko wtedy, gdy włączysz je na stronie '
                          'konta i pozwala na to twoja grupa; każde z nich jest na początku wyłączone.'),
    'a_pictures': ('A picture or a cover you upload is re-encoded by the site, which strips its metadata — a '
                   'location included — and is kept in the site\'s database. Changing or removing it deletes the '
                   'old one at once, though a browser or a cache that already has it may show it for a while.',
                   'Wgrane zdjęcie profilowe albo okładka są przekodowywane przez stronę (co usuwa ich metadane, '
                   'łącznie z lokalizacją) i przechowywane w bazie danych strony. Zmiana albo usunięcie od razu '
                   'kasuje poprzedni plik, choć przeglądarka albo pamięć podręczna, która już go ma, może go '
                   'jeszcze przez chwilę pokazywać.'),
    'q_saved':   ('Favourites and lists', 'Ulubione i listy'),
    'a_favourites': ('The star keeps a torrent among your favourites.',
                     'Gwiazdka zapisuje torrent w twoich ulubionych.'),
    'a_lists':   ('A list is a collection you make yourself; each one is private until you share it.',
                  'Lista to zbiór, który tworzysz sam; każda jest prywatna, dopóki jej nie udostępnisz.'),
    'a_lists_public': ('A public list can be read by signed-in members, once you allow public lists on the '
                       'account page and your group allows it.',
                       'Publiczną listę mogą czytać zalogowani, gdy tylko pozwolisz na publiczne listy na '
                       'stronie konta i pozwala na to twoja grupa.'),
    'a_lists_friends': ('A list for friends is read by your friends only.',
                        'Listę dla znajomych czytają wyłącznie twoi znajomi.'),

    # ── people ──────────────────────────────────────────────────────────────
    'q_people':  ('Friends, blocks and private messages', 'Znajomi, blokady i prywatne wiadomości'),
    'a_friends': ('Following somebody sends them a friend request; when they accept it, or follow you back, you '
                  'are friends. A block ends a friendship, stops messages both ways and folds that member\'s '
                  'comments away for you; it can also hide your profile from them. It does not reach the '
                  'shoutbox.',
                  'Obserwowanie kogoś wysyła mu zaproszenie do znajomych; gdy je przyjmie albo zacznie '
                  'obserwować ciebie, jesteście znajomymi. Blokada kończy znajomość, zatrzymuje wiadomości '
                  'w obie strony i zwija dla ciebie komentarze tej osoby; może też ukryć przed nią twój profil. '
                  'Nie sięga do shoutboksa.'),
    'a_directory': ('The member directory lists only the members who asked to be in it, with their name and '
                    'picture.',
                    'Katalog członków pokazuje wyłącznie tych, którzy poprosili, by się w nim znaleźć — z nazwą '
                    'i zdjęciem profilowym.'),
    'a_messages': ('Private messages are between two people. Nobody else reads them: a moderator sees a message '
                   'only when one of the two reports it, and then only that message and the one before it. The '
                   'sender sees when you have read their message.',
                   'Prywatne wiadomości są między dwiema osobami. Nikt inny ich nie czyta: moderator widzi '
                   'wiadomość tylko wtedy, gdy któraś z tych osób ją zgłosi, i wtedy wyłącznie tę wiadomość oraz '
                   'poprzednią. Nadawca widzi, kiedy przeczytałeś jego wiadomość.'),
    'a_messages_archive': ('Archive takes a conversation out of your inbox without deleting anything, and one click '
                           'brings it back.',
                           'Archiwizacja zabiera rozmowę ze skrzynki odbiorczej, niczego nie usuwając, a jedno '
                           'kliknięcie ją przywraca.'),
    'a_messages_returns': ('A new message in it brings it back to the inbox.',
                           'Nowa wiadomość w takiej rozmowie przywraca ją do skrzynki odbiorczej.'),
    'a_messages_stays': ('A new message in it leaves it in the Archive, marked unread and counted on the badge.',
                         'Nowa wiadomość w takiej rozmowie zostawia ją w Archiwum, oznaczoną jako nieprzeczytaną '
                         'i liczoną na plakietce.'),
    'a_messages_trash': ('Delete moves it to the Trash, where it can be restored for :days before it is '
                         'deleted for you; Delete forever and Empty the Trash do that at once.',
                         'Usunięcie przenosi ją do Kosza, skąd można ją przywrócić przez :days, zanim '
                         'zostanie usunięta dla ciebie; „Usuń na zawsze” i opróżnienie Kosza robią to od razu.'),
    'a_messages_now': ('Delete deletes it for you at once.', 'Usunięcie od razu usuwa ją dla ciebie.'),
    'a_messages_keep': ('Deleting only ever removes a conversation for you — the other person keeps it — and the '
                        'messages themselves stay in the database until one of the two accounts is deleted, which '
                        'deletes the conversation for both; a moderator may delete a single message that was '
                        'reported.',
                        'Usuwanie zawsze usuwa rozmowę wyłącznie dla ciebie — druga osoba ją zachowuje — a same '
                        'wiadomości zostają w bazie danych do czasu usunięcia jednego z dwóch kont, co usuwa rozmowę '
                        'dla obu stron; moderator może usunąć pojedynczą zgłoszoną wiadomość.'),
    'a_messages_private': ('Nobody else sees your Archive or your Trash.',
                           'Nikt inny nie widzi twojego Archiwum ani Kosza.'),
    'a_messages_private_archive': ('Nobody else sees your Archive.', 'Nikt inny nie widzi twojego Archiwum.'),

    # ── comments, descriptions, ratings, the shoutbox ───────────────────────
    'q_words':   ('Comments, descriptions and ratings', 'Komentarze, opisy i oceny'),
    'a_comments': ('Signed-in members can comment on a torrent in its Info panel and reply to a comment. You can '
                   'correct your comment for a short while and take it back for a little longer. A comment you '
                   'delete, or a moderator removes, disappears for everybody but is kept with who removed it and '
                   'when — and, for a moderator, why.',
                   'Zalogowani mogą komentować torrent w jego panelu Info i odpowiadać na komentarze. Swój '
                   'komentarz możesz przez krótką chwilę poprawić, a przez nieco dłuższą cofnąć. Komentarz usunięty '
                   'przez ciebie albo przez moderatora znika dla wszystkich, ale zostaje zachowany razem '
                   'z informacją, kto go usunął i kiedy — a w przypadku moderatora także dlaczego.'),
    'a_comments_guest': ('Visitors who are not signed in may comment too, signed as "Guest #" and four characters '
                         'that change every day. A guest solves a CAPTCHA every time, cannot post links, and their '
                         'comment may wait for a moderator; the address group it came from (the address itself, '
                         'for IPv6 its /64) is stored with it and never shown.',
                         'Niezalogowani goście też mogą komentować, jako „Gość #” i cztery znaki, które zmieniają '
                         'się każdego dnia. Gość za każdym razem rozwiązuje CAPTCHĘ, nie może wstawiać linków, '
                         'a jego komentarz może czekać na moderatora; grupa adresów, z której przyszedł (sam adres, '
                         'przy IPv6 jego /64), jest zapisywana razem z nim i nigdy nie jest pokazywana.'),
    'a_descriptions': ('Members can describe a torrent and add a link to its source. A description may be '
                       'reviewed before it appears, others may propose changes to it, and the people who wrote or '
                       'edited it are credited under it by name unless they chose not to be.',
                       'Członkowie mogą opisać torrent i dodać link do jego źródła. Opis może zostać sprawdzony, '
                       'zanim się pojawi, inni mogą proponować w nim zmiany, a osoby, które go napisały albo '
                       'poprawiły, są wymienione pod nim z nazwy, chyba że same tego nie chcą.'),
    'a_ratings': ('Torrents can be rated, one vote each; a vote can be changed or taken back.',
                  'Torrenty można oceniać, jednym głosem na osobę; głos można zmienić albo cofnąć.'),
    'q_shout':   ('The shoutbox', 'Shoutbox'),
    'a_shout':   ('The shoutbox is a room for short lines, written by members. Lines older than :days, and '
                  'any beyond the newest :rows, are deleted for good — deleted and pinned ones included.',
                  'Shoutbox to pokój na krótkie wpisy, pisane przez członków. Wpisy starsze niż :days, a także '
                  'wszystkie poza :rows najnowszymi, są usuwane na dobre — również te usunięte i przypięte.'),

    # ── reports and moderation, anti-spam ───────────────────────────────────
    'q_reports': ('Reports and moderation', 'Zgłoszenia i moderacja'),
    'a_report':  ('Anyone who believes a torrent infringes their rights can use the <a href=":url">report form</a>. '
                  'It asks who is reporting and for whom, an e-mail address, the title, a link and the torrent; '
                  'the address the report was sent from is stored with it, and the reporter hears back by e-mail. '
                  'The <a href=":status">status page</a> shows where a report stands, and a blocked hash can be '
                  'appealed from there — an appeal keeps its sender\'s name, e-mail address and address too.',
                  'Każdy, kto uważa, że torrent narusza jego prawa, może skorzystać z '
                  '<a href=":url">formularza zgłoszeń</a>. Formularz pyta, kto zgłasza i w czyim imieniu, o adres '
                  'e-mail, tytuł, link i torrent; razem ze zgłoszeniem zapisywany jest adres, z którego je '
                  'wysłano, a zgłaszający dostaje odpowiedź e-mailem. <a href=":status">Strona statusu</a> pokazuje, '
                  'na jakim etapie jest zgłoszenie, i można tam odwołać się od blokady hasha — odwołanie także '
                  'zachowuje imię i nazwisko, e-mail i adres nadawcy.'),
    'a_report_transparency': ('How many reports each organisation has sent is public in the '
                              '<a href=":url">transparency report</a>.',
                              'Ile zgłoszeń wysłała każda organizacja, jest jawne w '
                              '<a href=":url">raporcie przejrzystości</a>.'),
    'a_moderation': ('Members can report a comment, a description or a line in the shoutbox — whichever of them '
                     'this site has.',
                     'Członkowie mogą zgłosić komentarz, opis albo wpis w shoutboksie — w zależności od tego, które '
                     'z nich są na tej stronie.'),
    'a_moderation_pm': ('A message can be reported by the person who received it.',
                        'Wiadomość może zgłosić osoba, która ją otrzymała.'),
    'a_moderation_what': ('Moderators may remove the words, warn the author, silence them for a while or suspend '
                          'the account, either silently or with a warning the author receives among their '
                          'notifications; the author is never told who reported. What is allowed is in the '
                          '<a href=":url">Terms</a>.',
                          'Moderatorzy mogą usunąć treść, upomnieć autora, wyciszyć go na jakiś czas albo zawiesić '
                          'konto, po cichu albo z ostrzeżeniem, które autor dostaje wśród powiadomień; autor nigdy '
                          'się nie dowiaduje, kto zgłosił. Co jest dozwolone, mówi <a href=":url">Regulamin</a>.'),
    'q_antispam': ('Anti-spam', 'Ochrona przed spamem'),
    'a_antispam': ('Everything people write here passes one anti-spam check: a few quick writes are free, then the '
                   'pauses between them grow, and a quiet while forgets them; the same words sent twice in a short '
                   'time are refused, and somebody who keeps pushing is asked for a CAPTCHA.',
                   'Wszystko, co się tu pisze, przechodzi jedną kontrolę antyspamową: kilka szybkich wpisów nie '
                   'wymaga czekania, potem przerwy między nimi rosną, a chwila ciszy je zeruje; te same słowa '
                   'wysłane dwa razy w krótkim czasie są odrzucane, a kto dalej naciska, jest proszony o CAPTCHĘ.'),
    'a_antispam_new': ('New accounts wait longer for :days after they are created, and their links may show as '
                       'plain text.',
                       'Nowe konta przez :days od założenia czekają dłużej, a ich linki mogą być pokazywane jako '
                       'zwykły tekst.'),
    'a_antispam_keep': ('To do this the site keeps, per account — for a visitor, per address group — when you last '
                        'wrote and short fingerprints of your last words, never the words themselves, and forgets '
                        'them after two days without writing.',
                        'Aby to działało, strona przechowuje dla konta — a dla gościa dla grupy adresów — kiedy '
                        'ostatnio pisałeś i krótkie odciski twoich ostatnich słów, nigdy same słowa, i zapomina '
                        'o nich po dwóch dniach bez pisania.'),

    # ── what is kept ────────────────────────────────────────────────────────
    'q_data':    ('What does this site keep, and for how long?', 'Co ta strona przechowuje i jak długo?'),
    'a_data':    ('Everything this site keeps is listed here, with how long it stays.',
                  'Wszystko, co ta strona przechowuje, jest wymienione tutaj, razem z tym, jak długo to zostaje.'),
    'd_tracker': ('<strong>The tracker:</strong> the peers of each torrent, in memory only, until 45 minutes after '
                  'their last announce.',
                  '<strong>Tracker:</strong> peery każdego torrenta, wyłącznie w pamięci, do 45 minut po ich '
                  'ostatnim announce.'),
    'd_stats':   ('<strong>Statistics:</strong> counts only — torrents, seeders, leechers, completed downloads — '
                  'never an address.',
                  '<strong>Statystyki:</strong> wyłącznie liczby — torrenty, seedy, leecherzy, ukończone pobrania — '
                  'nigdy adresy.'),
    'd_index':   ('<strong>The index:</strong> per torrent its numbers, when it was seen and — once fetched — its '
                  'name, size and file list, for as long as the entry lives (see above).',
                  '<strong>Indeks:</strong> dla każdego torrenta jego liczby, kiedy był widziany i — gdy zostaną '
                  'pobrane — jego nazwa, rozmiar i lista plików, tak długo, jak żyje wpis (patrz wyżej).'),
    'd_registrations': ('<strong>A registered torrent:</strong> its hash and name, where it came from, the address '
                        'it was sent from and, when a member registered it, their account — for as long as the '
                        'registration exists, which may be longer than the account.',
                        '<strong>Zarejestrowany torrent:</strong> jego hash i nazwa, skąd przyszedł, adres, z '
                        'którego go wysłano, a jeśli zarejestrował go członek — jego konto; tak długo, jak istnieje '
                        'rejestracja, co może trwać dłużej niż konto.'),
    'd_reports': ('<strong>A report or an appeal:</strong> what the form asked for and the sender\'s address, '
                  'until the operator deletes it.',
                  '<strong>Zgłoszenie albo odwołanie:</strong> to, o co pytał formularz, i adres nadawcy — do '
                  'czasu, aż operator je usunie.'),
    'd_account': ('<strong>An account:</strong> its name; the password as a salted hash, never the password; the '
                  'e-mail address; the address it was created from and the one it last signed in from; its groups '
                  'with their end dates; its language, time zone and your choices on the account page — until the '
                  'account is deleted.',
                  '<strong>Konto:</strong> jego nazwa; hasło jako solony skrót, nigdy samo hasło; adres e-mail; '
                  'adres, z którego je założono, i ten, z którego ostatnio się logowało; jego grupy z datami '
                  'końca; jego język, strefa czasowa i twoje wybory na stronie konta — do czasu usunięcia konta.'),
    'd_twofa':   ('<strong>A second factor:</strong> the authenticator\'s secret and the recovery codes, those as '
                  'hashes, until you switch it off.',
                  '<strong>Drugi składnik:</strong> sekret aplikacji uwierzytelniającej i kody odzyskiwania, te '
                  'jako skróty — do czasu, aż go wyłączysz.'),
    'd_devices': ('<strong>A device that stays signed in:</strong> a hashed token with the device\'s address and '
                  'browser, until it expires or you sign out there.',
                  '<strong>Urządzenie, które pozostaje zalogowane:</strong> token jako skrót razem z adresem '
                  'urządzenia i przeglądarką — do czasu wygaśnięcia albo wylogowania na nim.'),
    'd_messages': ('<strong>Messages:</strong> until one of the two accounts is deleted — deleting a conversation '
                   'hides it from you, it does not remove the messages.',
                   '<strong>Wiadomości:</strong> do czasu usunięcia jednego z dwóch kont — usunięcie rozmowy ukrywa ją '
                   'przed tobą, ale nie kasuje wiadomości.'),
    'd_comments': ('<strong>Comments:</strong> the ones taken back or removed included, until their author\'s '
                   'account is deleted; a guest\'s comment is kept with the address group it came from.',
                   '<strong>Komentarze:</strong> także te cofnięte albo usunięte, do czasu usunięcia konta autora; '
                   'komentarz gościa jest przechowywany razem z grupą adresów, z której przyszedł.'),
    'd_descriptions': ('<strong>Descriptions:</strong> with the torrent they describe, credited as they were '
                       'written; the ten newest replaced versions are kept, and a proposed change keeps the '
                       'address it was sent from.',
                       '<strong>Opisy:</strong> razem z torrentem, który opisują, z podpisami takimi, jak zostały '
                       'napisane; dziesięć najnowszych zastąpionych wersji jest przechowywanych, a proponowana '
                       'zmiana zachowuje adres, z którego ją wysłano.'),
    'd_votes':   ('<strong>Votes:</strong> with the account that cast them — for a visitor, with the address group '
                  'the vote came from — until the torrent is banned or the account deleted.',
                  '<strong>Głosy:</strong> razem z kontem, które je oddało — u gościa z grupą adresów, z której '
                  'przyszedł głos — do zbanowania torrenta albo usunięcia konta.'),
    'd_shouts':  ('<strong>The shoutbox:</strong> each line with the address group it came from, for :days at '
                  'most.',
                  '<strong>Shoutbox:</strong> każdy wpis z grupą adresów, z której przyszedł, najwyżej przez '
                  ':days.'),
    'd_moderation': ('<strong>Reports of words and warnings:</strong> a report keeps a copy of what was reported, '
                     'the reason and the outcome; a warning stays with the account.',
                     '<strong>Zgłoszenia treści i ostrzeżenia:</strong> zgłoszenie zachowuje kopię tego, co '
                     'zgłoszono, powód i wynik; ostrzeżenie zostaje przy koncie.'),
    'd_antispam': ('<strong>Anti-spam:</strong> the times and fingerprints described above, for two days after the '
                   'last write.',
                   '<strong>Ochrona przed spamem:</strong> opisane wyżej czasy i odciski, przez dwa dni od '
                   'ostatniego wpisu.'),
    'd_limits':  ('<strong>Limits on the forms:</strong> the address (for IPv6, its /64) with the times a form was '
                  'used, for as long as the limit lasts — an hour, for some forms a day.',
                  '<strong>Limity formularzy:</strong> adres (przy IPv6 jego /64) z czasami użycia formularza, tak '
                  'długo, jak trwa limit — godzinę, przy niektórych formularzach dobę.'),
    'd_audit':   ('<strong>The operator\'s log:</strong> actions taken in the panel and failed sign-ins to it, with '
                  'the address, for :days.',
                  '<strong>Dziennik operatora:</strong> działania wykonane w panelu i nieudane logowania do niego, '
                  'z adresem, przez :days.'),
    'd_audit_members': ('<strong>The operator\'s log:</strong> actions taken in the panel, failed sign-ins to it and '
                        'a few members\' actions — reports, signing out other devices, sign-ins through a partner '
                        'site, refusals by the anti-spam check — with the address, for :days.',
                        '<strong>Dziennik operatora:</strong> działania wykonane w panelu, nieudane logowania do '
                        'niego i niektóre działania członków — zgłoszenia, wylogowanie innych urządzeń, logowania '
                        'przez serwis partnera, odmowy kontroli antyspamowej — z adresem, przez :days.'),
    'd_partners': ('<strong>Partner sites:</strong> a site the operator connects to accounts through the API — a '
                   'shop, a sign-in bridge — can look an account up by its name or e-mail address and receives its '
                   'name, e-mail address, state, the date it was created and its groups.',
                   '<strong>Serwisy partnerskie:</strong> serwis, który operator łączy z kontami przez API — sklep, '
                   'mostek logowania — może wyszukać konto po nazwie albo adresie e-mail i dostaje jego nazwę, adres '
                   'e-mail, stan, datę założenia i grupy.'),
    'd_backups': ('<strong>Backups:</strong> copies of the database — accounts, messages and addresses included — '
                  'which the operator keeps for :days; the newest one is always kept.',
                  '<strong>Kopie zapasowe:</strong> kopie bazy danych — łącznie z kontami, wiadomościami '
                  'i adresami — które operator trzyma przez :days; najnowsza jest zachowywana zawsze.'),
    'd_backups_any': ('<strong>Backups:</strong> copies of the database — accounts, messages and addresses '
                      'included — for as long as the operator keeps them.',
                      '<strong>Kopie zapasowe:</strong> kopie bazy danych — łącznie z kontami, wiadomościami '
                      'i adresami — tak długo, jak operator je trzyma.'),
    'd_csp':     ('<strong>Security reports:</strong> when your browser blocks something on a page, it may tell the '
                  'site which rule and which page — never your address.',
                  '<strong>Raporty bezpieczeństwa:</strong> gdy twoja przeglądarka zablokuje coś na stronie, może '
                  'powiadomić o tym stronę — którą regułę i na której stronie — nigdy z twoim adresem.'),
    'd_weblog':  ('<strong>The web server:</strong> its own access log of the pages requested, with the address, '
                  'kept as the server is configured — that is not up to this software.',
                  '<strong>Serwer WWW:</strong> jego własny log dostępu do żądanych stron, z adresem, przechowywany '
                  'tak, jak serwer jest skonfigurowany — to już nie zależy od tego oprogramowania.'),
    'a_delete':  ('To have an account and its data deleted, write to the operator from the account\'s e-mail '
                  'address — there is no button for it. Deleting an account deletes its messages (for both sides), '
                  'favourites, lists, notifications, votes and comments (where somebody replied, an empty placeholder '
                  'stays); the torrents it registered and the descriptions it wrote stay, without its name.',
                  'Aby usunąć konto i jego dane, napisz do operatora z adresu e-mail przypisanego do konta — nie '
                  'ma na to przycisku. Usunięcie konta usuwa jego wiadomości (po obu stronach), ulubione, listy, '
                  'powiadomienia, głosy i komentarze (tam, gdzie ktoś odpowiedział, zostaje puste miejsce); '
                  'zarejestrowane przez nie torrenty i napisane opisy zostają, bez jego nazwy.'),

    # ── cookies and the browser ─────────────────────────────────────────────
    'q_cookies': ('Cookies and your browser', 'Ciasteczka i twoja przeglądarka'),
    'c_session': ('<code>PHPSESSID</code> is set on every visit. It holds a random identifier and nothing else; '
                  'what it points to — the forms\' security token, whether you are signed in — stays on the server. '
                  'It normally ends when you close the browser.',
                  '<code>PHPSESSID</code> jest ustawiane przy każdej wizycie. Zawiera losowy identyfikator i nic '
                  'więcej; to, na co wskazuje — token bezpieczeństwa formularzy, to, czy jesteś zalogowany — zostaje '
                  'na serwerze. Zwykle wygasa po zamknięciu przeglądarki.'),
    'c_remember': ('<code>thx_remember</code> is set only when you choose to stay signed in: your account\'s number '
                   'and a random token, until the time you chose.',
                   '<code>thx_remember</code> jest ustawiane tylko wtedy, gdy zdecydujesz się pozostać zalogowanym: '
                   'numer twojego konta i losowy token, do wybranego przez ciebie czasu.'),
    'c_lang':    ('<code>lang</code> is set only when you pick a language with the switcher: the language\'s code, '
                  'until the browser closes.',
                  '<code>lang</code> jest ustawiane tylko wtedy, gdy wybierzesz język przełącznikiem: kod języka, '
                  'do zamknięcia przeglądarki.'),
    'c_storage': ('Your browser\'s own storage keeps a few conveniences on your device and nowhere else: the emoji '
                  'you used recently, how many results a page shows, a chart\'s range and — signed in — the last '
                  'counts of what is unread.',
                  'Pamięć twojej przeglądarki trzyma kilka ułatwień na twoim urządzeniu i nigdzie indziej: ostatnio '
                  'używane emoji, ile wyników pokazuje strona, zakres wykresu i — po zalogowaniu — ostatnie liczby '
                  'nieprzeczytanych.'),
    'c_cdn':     ('The icon font is loaded from cdn.jsdelivr.net, which therefore sees your address.',
                  'Czcionka ikon jest ładowana z cdn.jsdelivr.net, który przez to widzi twój adres.'),
    'c_captcha': ('A page that asks you to prove you are human loads the CAPTCHA provider\'s script, and checking '
                  'the answer sends the provider your address; the provider may set cookies of its own.',
                  'Strona, która prosi o udowodnienie, że jesteś człowiekiem, ładuje skrypt dostawcy CAPTCHY, '
                  'a sprawdzenie odpowiedzi wysyła temu dostawcy twój adres; dostawca może ustawić własne '
                  'ciasteczka.'),
    'c_images':  ('A picture in a description or in the shoutbox is loaded from wherever its author put it, so that '
                  'site sees your address.',
                  'Obrazek w opisie albo w shoutboksie jest ładowany stamtąd, gdzie umieścił go autor, więc tamta '
                  'strona widzi twój adres.'),
    'c_none':    ('None of the site\'s own cookies follows you to other sites, and the site shows no advertising.',
                  'Żadne z ciasteczek samej strony nie podąża za tobą na inne strony, a strona nie wyświetla '
                  'reklam.'),

    # ── the questions ───────────────────────────────────────────────────────
    'faq_head':  ('Frequently Asked Questions', 'Najczęściej zadawane pytania'),
    'faq_q1':    ('Can you remove an info_hash?', 'Czy możecie usunąć info_hash?'),
    'faq_a1':    ('Yes. A hash can be banned, after which the tracker no longer answers announces for it. Use the '
                  'report form.',
                  'Tak. Hash można zbanować, po czym tracker przestaje odpowiadać na announce dla niego. Skorzystaj '
                  'z formularza zgłoszeń.'),
    'faq_a1_wl': ('In whitelist mode a registration can also simply be removed.',
                  'W trybie whitelisty rejestrację można też po prostu usunąć.'),
    'faq_q2':    ('Can you see what content is behind a hash?', 'Czy widzicie, jaka treść kryje się za hashem?'),
    'faq_a2':    ('The tracker itself sees only the hash — a SHA-1 digest of the torrent\'s metadata — and never '
                  'the files.',
                  'Sam tracker widzi tylko hash — skrót SHA-1 metadanych torrenta — i nigdy nie widzi plików.'),
    'faq_a2_index': ('The site\'s index may know the torrent\'s name and file list, fetched from the swarm: the '
                     'names of the files, never their content.',
                     'Indeks strony może znać nazwę torrenta i listę plików, pobrane z roju: nazwy plików, nigdy '
                     'ich zawartość.'),
    'faq_q3':    ('Do you keep IP address logs?', 'Czy prowadzicie logi adresów IP?'),
    'faq_a3':    ('The tracker keeps no log of announces, and the addresses of peers never leave its memory. The '
                  'website does store addresses in a few places — each is listed above, with how long it stays.',
                  'Tracker nie prowadzi logu announce, a adresy peerów nigdy nie opuszczają jego pamięci. Sama strona '
                  'przechowuje adresy w kilku miejscach — każde jest wymienione wyżej, razem z tym, jak długo '
                  'zostaje.'),
    'faq_q4':    ('What should copyright holders do?', 'Co powinni zrobić właściciele praw autorskich?'),
    'faq_a4':    ('Nothing is hosted here — no files and no .torrent files — so the content itself is wherever the '
                  'peers are. Use the report form: a hash can be banned here, and the reporter hears back.',
                  'Nic nie jest tu hostowane — ani pliki, ani pliki .torrent — więc sama treść jest tam, gdzie '
                  'peery. Skorzystaj z formularza zgłoszeń: hash można tu zbanować, a zgłaszający dostaje '
                  'odpowiedź.'),
    'faq_q5':    ('Do you have .torrent files?', 'Czy macie pliki .torrent?'),
    'faq_a5':    ('No. The site stores no .torrent files, and none of the files a torrent describes.',
                  'Nie. Strona nie przechowuje plików .torrent ani żadnego z plików, które torrent opisuje.'),
    'faq_q6':    ('Can I read this site in another language?', 'Czy mogę czytać tę stronę w innym języku?'),
    'faq_a6':    ('Yes — the switcher in the navigation changes the language for this browser session, and a '
                  'signed-in account can save a preferred language on its account page.',
                  'Tak — przełącznik w nawigacji zmienia język na czas sesji przeglądarki, a zalogowane konto może '
                  'zapisać preferowany język na stronie konta.'),
    'faq_q7':    ('Can I delete my account?', 'Czy mogę usunąć swoje konto?'),
    'faq_a7':    ('Yes — write to the operator from the account\'s e-mail address. What deleting removes, and what '
                  'stays, is listed above.',
                  'Tak — napisz do operatora z adresu e-mail przypisanego do konta. Co usunięcie kasuje, a co '
                  'zostaje, jest opisane wyżej.'),

    # ── a number of days, the whitelist hours (includes/pagecontent.php) ────
    # pageContentDays(): the noun has to agree with a number only the code knows. Every sentence that takes
    # one uses it after "for", "after", "older than", "at least" — in Polish the accusative, where every
    # count but one takes "dni" (2 dni, 5 dni, 22 dni) and one takes "dzień".
    'days_one':  ('1 day', '1 dzień'),
    'days_many': (':n days', ':n dni'),
    # pageContentScheduleText(): the hours inside a sentence that already holds them in parentheses, so
    # none of their own. Day names are common.dow_*.
    'sched_window':      (':from–:to', ':from–:to'),
    'sched_window_next': (':from–:to the next day', ':from–:to następnego dnia'),
    'sched_all_day':     ('all day', 'cały dzień'),
    'sched_list':        (':list, :tz time', ':list, czas :tz'),
    'sched_none':        ('none — the tracker is open all week, :tz time',
                          'brak — tracker jest otwarty przez cały tydzień, czas :tz'),
})

# ── the markers the editor lists (Settings → Site pages) ─────────────────────
# The 1.73.0 conditions of pageContentConditions() (includes/pagecontent.php). The first sixteen live in
# api.py; these are written here, beside the page that needed them.
add('api.pages', {
    'cond_twofa':          ('members may protect their account with a second factor',
                            'członkowie mogą zabezpieczyć konto drugim składnikiem'),
    'cond_profiles':       ('member profiles are on', 'profile członków są włączone'),
    'cond_pictures':       ('members may set a picture or a profile cover',
                            'członkowie mogą ustawić zdjęcie profilowe albo okładkę profilu'),
    'cond_bio':            ('members may write a description on their profile',
                            'członkowie mogą napisać opis na swoim profilu'),
    'cond_favourites':     ('members may keep favourites', 'członkowie mogą mieć ulubione'),
    'cond_lists':          ('members may make lists', 'członkowie mogą tworzyć listy'),
    'cond_lists_public':   ('a list may be public', 'lista może być publiczna'),
    'cond_lists_friends':  ('a list may be shared with friends', 'listę można udostępnić znajomym'),
    'cond_saved':          ('favourites or lists are on', 'ulubione albo listy są włączone'),
    'cond_friends':        ('friends and blocks are on', 'znajomi i blokady są włączone'),
    'cond_directory':      ('the member directory is on', 'katalog członków jest włączony'),
    'cond_messages':       ('private messages are on', 'prywatne wiadomości są włączone'),
    'cond_trash':          ('deleted messages wait in a Trash first', 'usunięte wiadomości najpierw czekają w Koszu'),
    'cond_archive_returns': ('a new message brings an archived conversation back to the inbox',
                             'nowa wiadomość przywraca zarchiwizowaną rozmowę do skrzynki odbiorczej'),
    'cond_email_cooldown': ('a wait applies between two changes of an account\'s e-mail address',
                            'między dwiema zmianami adresu e-mail konta obowiązuje okres karencji'),
    'cond_people':         ('friends, the directory or messages are on',
                            'znajomi, katalog członków albo wiadomości są włączone'),
    'cond_comments':       ('comments on torrents are on', 'komentarze pod torrentami są włączone'),
    'cond_reportable':     ('comments, descriptions or shouts can be reported',
                            'komentarze, opisy albo wpisy w shoutboksie można zgłaszać'),
    'cond_report_words':   ('something members write can be reported (words in public, or a message)',
                            'coś, co piszą członkowie, można zgłosić (treść publiczną albo wiadomość)'),
    'cond_antispam_new':   ('the anti-spam check treats new accounts more strictly',
                            'ochrona przed spamem traktuje nowe konta surowiej'),
    'cond_audit':          ('the operator\'s log is kept', 'dziennik operatora jest prowadzony'),
    'cond_audit_members':  ('the operator\'s log is kept and records members\' actions too',
                            'dziennik operatora jest prowadzony i odnotowuje też działania członków'),
    'cond_guest_comments': ('visitors who are not signed in may comment',
                            'niezalogowani goście mogą komentować'),
    'cond_writing':        ('comments, descriptions or ratings are on', 'komentarze, opisy albo oceny są włączone'),
    'cond_shoutbox':       ('the shoutbox is on', 'shoutbox jest włączony'),
    'cond_sounds':         ('members may choose sounds', 'członkowie mogą wybierać dźwięki'),
    'cond_community':      ('members write something others read (comments, descriptions, the shoutbox, messages, '
                            'lists or profile descriptions)',
                            'członkowie piszą coś, co czytają inni (komentarze, opisy, shoutbox, wiadomości, listy '
                            'albo opisy profili)'),
    'cond_antispam':       ('the anti-spam layer is on', 'ochrona przed spamem jest włączona'),
    'cond_api':            ('the partner API is on', 'API dla partnerów jest włączone'),
    'cond_bridge':         ('the sign-in bridge is on', 'mostek logowania jest włączony'),
    'cond_federation':     ('federation (sharing metadata with other trackers) is on',
                            'federacja (wymiana metadanych z innymi trackerami) jest włączona'),
    'cond_backups':        ('backups are on', 'kopie zapasowe są włączone'),
    'cond_backup_days':    ('backups are deleted after a number of days', 'kopie zapasowe są usuwane po określonej liczbie dni'),
    'cond_csp_reports':    ('the browser security reports are collected', 'raporty bezpieczeństwa przeglądarek są zbierane'),
    'cond_captcha':        ('a CAPTCHA provider is set up', 'dostawca CAPTCHY jest skonfigurowany'),
    'cond_icons_cdn':      ('the icon font comes from jsDelivr', 'czcionka ikon pochodzi z jsDelivr'),
    'cond_images':         ('descriptions or the shoutbox may show pictures from other sites',
                            'opisy albo shoutbox mogą pokazywać obrazki z innych stron'),
    'cond_index_kept':     ('the index keeps torrents somebody saved for longer',
                            'indeks dłużej trzyma torrenty, które ktoś zapisał'),
    'cond_registration_members': ('only signed-in members may register torrents',
                                  'rejestrować torrenty mogą wyłącznie zalogowani członkowie'),
    'cond_whitelist_or_schedule': ('the tracker serves registered torrents only, all the time or during whitelist '
                                   'hours',
                                   'tracker obsługuje wyłącznie zarejestrowane torrenty, stale albo w godzinach '
                                   'whitelisty'),
    'cond_probe':          ('a new registration has to prove itself (metadata and a peer)',
                            'nowa rejestracja musi się wykazać (metadane i peer)'),
})
