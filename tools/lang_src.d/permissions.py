# -*- coding: utf-8 -*-
"""What every permission allows, in words (1.73.0, part F)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The registry of permissions is userPermissionList() in includes/users.php: an id and an English sentence
saying what it allows. Until 1.73.0 that sentence was English on every page -- the Groups matrix's row
tooltips, the group editor's boxes, the Recommended window's ids and Settings' shoutbox / emote / comment
matrices read it from admin/fetch_groups. Now each id has `perm.<id>` here, and userPermissionList()
answers in the reader's language. The English is the registry's own, word for word
(tests/groups_matrix_test.php holds the two together: a sentence changed in one place and not the other
fails there), and it is what a shell tool prints. The panel's scripts say the words by the id
(t.key('perm.' + id)), so they follow the live language switch; the Users and Settings pages' bundles
carry `perm.`.

Polish: written for the operator ticking the boxes. Each line is the name of an activity ("Przeglądanie…",
"Usuwanie…"), as a permission list reads in Polish; "PANEL — " stays as the English has it, a mark the
matrix's two sections also make. Gendered forms are avoided as the rest of the site's Polish does: the
consent lines speak of "profil członka" rather than "his/her profile". Settings' names and the panel's
page names are the ones the panel shows (Zgłoszenia, Whitelista, Indeks, Ruch, Użytkownicy, Kopie
zapasowe, Log); a setting's id in brackets stays an id.

The group editor's "Start from" presets (userGroupPresets()) are named in the reader's language too:
a seeded group's preset by its recommended set's name and line (a.users.rec_name_* / rec_about_*, in
tools/lang_src.d/groups_recommended.py), and the three presets that are no seeded group's below, in the
same family -- so the users page's bundle (which carries a.users.rec_) finds them back by key.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the public site ─────────────────────────────────────────────────────────────────────────────────────────────
add('perm', {
    'index.view':       ('Search the observed-hash index (the public search page)',
                         'Przeszukiwanie indeksu zaobserwowanych hashy (publiczna wyszukiwarka)'),
    'index.files':      ('See file lists in index search results',
                         'Podgląd list plików w wynikach wyszukiwania w indeksie'),
    'index.files_all':  ('Load the whole file list in index search results (beyond the first page)',
                         'Wczytywanie całej listy plików w wynikach wyszukiwania w indeksie (nie tylko pierwszej strony)'),
    'index.magnet':     ('See info hashes / copy magnet links in index search results',
                         'Podgląd info hashy i kopiowanie linków magnet w wynikach wyszukiwania w indeksie'),
    'whitelist.view':   ('Browse the public whitelist page (whitelisted torrents also show up in search)',
                         'Przeglądanie publicznej strony whitelisty (torrenty z whitelisty widać też w wyszukiwarce)'),
    'whitelist.add':    ('Register hashes on the whitelist (used when registration is set to "registered users")',
                         'Rejestrowanie hashy na whiteliście (gdy rejestracja jest ustawiona na „Zarejestrowani użytkownicy”)'),
    'status.hash_check': ('Ask the status page what this tracker knows about a hash (registered, banned, seen in the swarm, metadata, files)',
                          'Pytanie strony statusu, co ten tracker wie o hashu (rejestracja, ban, obecność w swarmie, metadane, pliki)'),
    'sounds.use':       ('Pick a sound for what arrives (notifications, messages) on the Sounds tab of the account page',
                         'Wybór dźwięku dla tego, co przychodzi (powiadomienia, wiadomości), na karcie Dźwięki strony konta'),
    'stats.view':       ('View the tracker statistics page',
                         'Oglądanie strony statystyk trackera'),
    'stats.timeline':   ('See the statistics timeline chart',
                         'Podgląd wykresu osi czasu statystyk'),
    'home.stats':       ('See the live stats widget on the home page',
                         'Podgląd widżetu statystyk na żywo na stronie głównej'),
    'rating.vote':      ('Rate torrents up or down (needs ratings switched on in Settings)',
                         'Ocenianie torrentów w górę lub w dół (wymaga włączenia ocen w Ustawieniach)'),
    'rating.public':    ('Let their likes/ratings be shown on their public profile',
                         'Pokazywanie polubień/ocen na publicznym profilu członka'),
    'content.submit':   ('Attach a source link and a description when registering a torrent',
                         'Dołączanie linku źródłowego i opisu przy rejestracji torrenta'),
    'content.propose':  ('Propose a rewrite of a description somebody else wrote',
                         'Proponowanie zmiany opisu napisanego przez kogoś innego'),
    'content.view':     ('See published descriptions and source links in the Info panel',
                         'Podgląd opublikowanych opisów i linków źródłowych w panelu Info'),
    'content.delete_own': ('Delete a description they are the author of',
                           'Usuwanie opisu, którego jest się autorem'),
    'content.delete_any': ("Delete anybody's published description from the Info panel",
                           'Usuwanie czyjegokolwiek opublikowanego opisu z panelu Info'),
    'content.public':   ('Let the descriptions they wrote be listed on their public profile',
                         'Pokazywanie napisanych opisów na publicznym profilu członka'),
    'favourites.use':   ('Keep a list of favourite torrents',
                         'Prowadzenie listy ulubionych torrentów'),
    'favourites.public': ('Let their favourites list be shown on their public profile',
                          'Pokazywanie listy ulubionych na publicznym profilu członka'),
    'favourites.view_others': ("See other people's profiles and favourite lists",
                               'Podgląd profili i list ulubionych innych osób'),
    'uploads.public':   ('Let their registered torrents be shown on their public profile',
                         'Pokazywanie zarejestrowanych torrentów na publicznym profilu członka'),
    'lists.use':        ('Make lists of torrents and keep them',
                         'Tworzenie i prowadzenie list torrentów'),
    'lists.public':     ('Let their lists be shown on their public profile',
                         'Pokazywanie list na publicznym profilu członka'),
    'pm.send':          ('Send private messages to other members',
                         'Wysyłanie prywatnych wiadomości do innych członków'),
    'pm.report':        ('Report a message to the moderators',
                         'Zgłaszanie wiadomości moderatorom'),
    'friends.use':      ('Follow other members, accept friend requests and share lists with friends',
                         'Obserwowanie innych członków, przyjmowanie zaproszeń do znajomych i udostępnianie list znajomym'),
    'directory.view':   ('Browse the member directory',
                         'Przeglądanie katalogu członków'),
    'shout.view':       ('Read the shoutbox',
                         'Czytanie shoutboksa'),
    'shout.post':       ('Write in the shoutbox',
                         'Pisanie w shoutboksie'),
    'shout.delete_own': ('Delete their own shouts',
                         'Usuwanie własnych wpisów w shoutboksie'),
    'shout.moderate':   ("Delete anyone's shouts, and clear the shoutbox",
                         'Usuwanie czyichkolwiek wpisów i czyszczenie shoutboksa'),
    'shout.edit_own':   ('Correct their own shouts for a while',
                         'Poprawianie własnych wpisów przez pewien czas'),
    'shout.edit_any':   ("Edit anyone's shout",
                         'Edytowanie czyjegokolwiek wpisu'),
    'shout.upload_emote': ('Upload emotes and stickers for the shoutbox',
                           'Wgrywanie emote i naklejek do shoutboksa'),
    'shout.emote_auto': ('Add emotes that are visible at once, without a moderator',
                         'Dodawanie emote widocznych od razu, bez moderatora'),
    'profile.avatar':   ('Set their own picture',
                         'Ustawianie własnego zdjęcia profilowego'),
    'profile.cover':    ('Set their own profile cover (drawn only while the account holds this)',
                         'Ustawianie własnej okładki profilu (pokazywanej tylko, dopóki konto ma to uprawnienie)'),
    'profile.bio':      ('Write a description on their profile (shown only while the account holds this)',
                         'Pisanie opisu na własnym profilu (pokazywanego tylko, dopóki konto ma to uprawnienie)'),
    'comment.view':     ('Read the comments under a torrent',
                         'Czytanie komentarzy pod torrentem'),
    'comment.post':     ('Write comments under a torrent (a guest group holding this comments as "Guest", with a CAPTCHA every time)',
                         'Pisanie komentarzy pod torrentem (grupa gości z tym uprawnieniem komentuje jako „Gość”, za każdym razem z CAPTCHA)'),
    'comment.reply':    ('Reply to a comment (as deep as the thread may go — comments_reply_depth)',
                         'Odpowiadanie na komentarz (tak głęboko, jak pozwala wątek — comments_reply_depth)'),
    'comment.edit_own': ('Correct their own comments for a while (comment_edit_minutes)',
                         'Poprawianie własnych komentarzy przez pewien czas (comment_edit_minutes)'),
    'comment.delete_own': ('Delete their own comments for a while (comment_delete_own_minutes)',
                           'Usuwanie własnych komentarzy przez pewien czas (comment_delete_own_minutes)'),
    'comment.moderate': ("Delete or edit anybody's comment with a reason, and release a guest's held comment",
                         'Usuwanie lub edytowanie czyjegokolwiek komentarza z podaniem powodu i zwalnianie wstrzymanego komentarza gościa'),
    'content.report':   ('Report a comment, a description or a shout to the moderators',
                         'Zgłaszanie moderatorom komentarza, opisu lub wpisu w shoutboksie'),
})

# ── the admin panel ─────────────────────────────────────────────────────────────────────────────────────────────
add('perm.panel', {
    'access':           ('PANEL — open the admin panel at all (every other panel permission needs this)',
                         'PANEL — otwieranie panelu administracyjnego w ogóle (wymaga go każde inne uprawnienie panelu)'),
    'reports.view':     ('PANEL — see the Reports page and the appeals list',
                         'PANEL — podgląd strony Zgłoszenia i listy odwołań'),
    'reports.status':   ("PANEL — change a report's status and notes",
                         'PANEL — zmiana statusu i notatek zgłoszenia'),
    'reports.block':    ('PANEL — block and unblock reported hashes',
                         'PANEL — blokowanie i odblokowywanie zgłoszonych hashy'),
    'reports.email':    ('PANEL — email a reporter and send review notifications',
                         'PANEL — pisanie e-maili do zgłaszających i wysyłanie powiadomień o rozpatrzeniu'),
    'reports.archive':  ('PANEL — archive, restore and delete reports',
                         'PANEL — archiwizowanie, przywracanie i usuwanie zgłoszeń'),
    'messages.view':    ('PANEL — see reported private messages (only the reported line and the one before it)',
                         'PANEL — podgląd zgłoszonych prywatnych wiadomości (tylko zgłoszonej i tej przed nią)'),
    'messages.handle':  ('PANEL — close a message report, or delete the message it names',
                         'PANEL — zamykanie zgłoszenia wiadomości albo usuwanie wiadomości, której dotyczy'),
    'reports.comments.view':       ('PANEL — see reported comments',
                                    'PANEL — podgląd zgłoszonych komentarzy'),
    'reports.comments.handle':     ('PANEL — act on reported comments (close, remove, warn, silence, ban the author)',
                                    'PANEL — działania wobec zgłoszonych komentarzy (zamknięcie, usunięcie, ostrzeżenie, wyciszenie, ban autora)'),
    'reports.descriptions.view':   ('PANEL — see reported torrent descriptions',
                                    'PANEL — podgląd zgłoszonych opisów torrentów'),
    'reports.descriptions.handle': ('PANEL — act on reported descriptions (close, remove, warn, silence, ban the author)',
                                    'PANEL — działania wobec zgłoszonych opisów (zamknięcie, usunięcie, ostrzeżenie, wyciszenie, ban autora)'),
    'reports.shouts.view':         ('PANEL — see reported shouts',
                                    'PANEL — podgląd zgłoszonych wpisów w shoutboksie'),
    'reports.shouts.handle':       ('PANEL — act on reported shouts (close, remove, warn, silence, ban the author)',
                                    'PANEL — działania wobec zgłoszonych wpisów w shoutboksie (zamknięcie, usunięcie, ostrzeżenie, wyciszenie, ban autora)'),
    'appeals.resolve':  ('PANEL — resolve and restore appeals',
                         'PANEL — rozpatrywanie i przywracanie odwołań'),
    'whitelist.view':   ('PANEL — see the Whitelist and Index pages',
                         'PANEL — podgląd stron Whitelista i Indeks'),
    'whitelist.add':    ('PANEL — register hashes from the panel',
                         'PANEL — rejestrowanie hashy z panelu'),
    'whitelist.delete': ('PANEL — delete whitelist rows',
                         'PANEL — usuwanie wierszy whitelisty'),
    'whitelist.ban':    ('PANEL — ban and unban hashes',
                         'PANEL — banowanie i odbanowywanie hashy'),
    'whitelist.meta':   ('PANEL — queue metadata fetches and refresh seeders',
                         'PANEL — kolejkowanie pobierania metadanych i odświeżanie seederów'),
    'whitelist.content': ('PANEL — approve or reject submitted descriptions and rewrites',
                          'PANEL — zatwierdzanie lub odrzucanie przesłanych opisów i propozycji zmian'),
    'users.view':       ('PANEL — see the Users page',
                         'PANEL — podgląd strony Użytkownicy'),
    'users.edit':       ("PANEL — change a user's status and email verification",
                         'PANEL — zmiana statusu użytkownika i weryfikacji adresu e-mail'),
    'users.notify':     ('PANEL — send a user an in-app notification',
                         'PANEL — wysyłanie użytkownikowi powiadomienia w serwisie'),
    'users.groups':     ('PANEL — grant and revoke groups (never the admin group)',
                         'PANEL — nadawanie i odbieranie grup (nigdy grupy Admin)'),
    'backups.view':     ('PANEL — see the Backups page and the backup list (running, restoring and deleting stay with the owner)',
                         'PANEL — podgląd strony Kopie zapasowe i listy kopii (uruchamianie, przywracanie i usuwanie zostają przy właścicielu)'),
    'traffic.view':     ('PANEL — see the Traffic page (read-only; the controls stay with the owner)',
                         'PANEL — podgląd strony Ruch (tylko odczyt; sterowanie zostaje przy właścicielu)'),
    'audit.view':       ('PANEL — read the audit log (who did what in the panel)',
                         'PANEL — czytanie logu (kto co zrobił w panelu)'),
})

# ── the group editor's "Start from" presets that are no seeded group's (userGroupPresets()) ─────────────────────
# The family of the recommended sets' names and lines (a.users.rec_name_* / rec_about_*, groups_recommended.py): the
# English is the preset's own label and line, word for word (tests/groups_matrix_test.php).
add('a.users', {
    'rec_name_reviewer':  ('Content reviewer', 'Recenzent treści'),
    'rec_about_reviewer': ('Approves or rejects descriptions and rewrites; sees the whitelist, changes nothing else.',
                           'Zatwierdza lub odrzuca opisy i propozycje zmian; widzi whitelistę, niczego więcej nie zmienia.'),
    'rec_name_curator':   ('Whitelist curator', 'Opiekun whitelisty'),
    'rec_about_curator':  ('Registers, bans and refreshes hashes. Never touches reports or users.',
                           'Rejestruje, banuje i odświeża hashe. Nigdy nie dotyka zgłoszeń ani użytkowników.'),
    'rec_name_auditor':   ('Read-only auditor', 'Audytor (tylko odczyt)'),
    'rec_about_auditor':  ('Sees every page and the audit log, and can change nothing.',
                           'Widzi każdą stronę i log, ale niczego nie może zmienić.'),
})
