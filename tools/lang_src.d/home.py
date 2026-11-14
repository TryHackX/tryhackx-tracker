# -*- coding: utf-8 -*-
"""Home

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


# ── home ────────────────────────────────────────────────────────────────────
add('home', {
    'tagline':       ('Public BitTorrent tracker powered by OpenTracker',
                      'Publiczny tracker BitTorrent napędzany przez OpenTracker'),
    'stats_syncing': ('Synchronizing tracker telemetry...', 'Synchronizacja telemetrii trackera…'),
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
    # 1.73.0: "free and anonymous" became "free" — the address a registration was sent from is kept with
    # it (Info says so), and in members-only mode so is the account. And the machine the tracker runs on,
    # and since when, is no longer a sentence every install shipped with ("Linux Debian since 2020" was
    # the owner's own server): it is :since, the footer's own two settings (footer_os_name,
    # footer_os_since_year — includes/homeblocks.php), or nothing when the footer does not show them.
    'about_sched':   ('This is a free BitTorrent tracker running erdgeist OpenTracker software in '
                      '<strong>scheduled whitelist mode</strong>: during whitelist hours (:hours) it '
                      'serves <strong>registered torrents only</strong>; outside those hours it runs '
                      'as an open tracker. Right now it is in <strong>:mode mode</strong>. '
                      'Registration is free:reg.:since It supports both IPv4 and IPv6.',
                      'To darmowy tracker BitTorrent działający na oprogramowaniu OpenTracker '
                      'autorstwa erdgeista w <strong>trybie whitelisty według harmonogramu</strong>: '
                      'w godzinach whitelisty (:hours) obsługuje <strong>wyłącznie zarejestrowane '
                      'torrenty</strong>; poza nimi działa jako tracker otwarty. W tej chwili jest w '
                      '<strong>trybie :mode</strong>. Rejestracja jest darmowa:reg.:since Obsługuje '
                      'zarówno IPv4, jak i IPv6.'),
    'about_wl':      ('This is a free BitTorrent tracker running erdgeist OpenTracker software in '
                      '<strong>whitelist mode</strong>: it serves <strong>registered torrents only'
                      '</strong>. Registration is free:reg.:since It supports both IPv4 and IPv6.',
                      'To darmowy tracker BitTorrent działający na oprogramowaniu OpenTracker '
                      'autorstwa erdgeista w <strong>trybie whitelisty</strong>: obsługuje '
                      '<strong>wyłącznie zarejestrowane torrenty</strong>. Rejestracja jest darmowa:reg.'
                      ':since Obsługuje zarówno IPv4, jak i IPv6.'),
    'about_open':    ('This is a free, public BitTorrent tracker running erdgeist OpenTracker '
                      'software.:since It supports both IPv4 and IPv6.',
                      'To darmowy, publiczny tracker BitTorrent działający na oprogramowaniu '
                      'OpenTracker autorstwa erdgeista.:since Obsługuje zarówno IPv4, jak i IPv6.'),
    'about_since':   (' It has been running on :os since :year.', ' Działa od :year roku, na systemie :os.'),
    'mode_whitelist': ('whitelist', 'whitelisty'),
    'mode_open':      ('open', 'otwartym'),
    'reg_here':      (' — <a href=":url">register your torrent here</a>',
                      ' — <a href=":url">zarejestruj tu swój torrent</a>'),
    'register_btn':  ('Register your torrent', 'Zarejestruj swój torrent'),
    'features_head': ('Features', 'Cechy'),
    # ── the Features list (1.73.0) ──────────────────────────────────────────
    # Checked against the code of this version (scratchpad/progress_1730c.md, claims table): the bullet that
    # promised random addresses in the peer lists went — nothing in the code or the tracker's build does
    # that — and so did "no connection logs" as it stood (the tracker keeps no log of announces, but the
    # site keeps a registrant's address, and said so in the same list). Each bullet stands under the
    # condition of the feature it names (includes/homeblocks.php, pageContentConditions()).
    'feat_no_host':  ('No files are hosted here — no content and no .torrent files',
                      'Niczego tu nie hostujemy — ani treści, ani plików .torrent'),
    'feat_sched':    ('Only registered info hashes are served during whitelist hours — open tracker otherwise',
                      'W godzinach whitelisty obsługiwane są wyłącznie zarejestrowane info hashe — poza nimi tracker jest otwarty'),
    'feat_wl':       ('Only registered info hashes are served — no unsolicited swarms',
                      'Obsługiwane są wyłącznie zarejestrowane info hashe — żadnych nieproszonych rojów'),
    'feat_memory':   ('Peers are kept in the tracker\'s memory only — announces are never logged',
                      'Peery są trzymane wyłącznie w pamięci trackera — announce nigdy nie są zapisywane'),
    'feat_ipv6':     ('IPv4 and IPv6', 'IPv4 i IPv6'),
    'feat_free':     ('The tracker is free to use', 'Tracker jest darmowy'),
    'feat_twofa':    ('A second factor to protect an account', 'Drugi składnik do ochrony konta'),
    'feat_profiles': ('Member profiles, seen by signed-in members only', 'Profile członków, widoczne tylko dla zalogowanych'),
    'feat_saved':    ('Favourites and lists of torrents', 'Ulubione i listy torrentów'),
    'feat_friends':  ('Friends and blocks', 'Znajomi i blokady'),
    'feat_messages': ('Private messages', 'Prywatne wiadomości'),
    'feat_comments': ('Comments and replies under torrents', 'Komentarze i odpowiedzi pod torrentami'),
    'feat_descriptions': ('Descriptions and source links, credited to the people who wrote them',
                          'Opisy i linki do źródeł, podpisane przez osoby, które je napisały'),
    'feat_ratings':  ('Ratings of torrents', 'Oceny torrentów'),
    'feat_shoutbox': ('A shoutbox', 'Shoutbox'),
    'feat_antispam': ('One anti-spam check for everything people write', 'Jedna ochrona przed spamem dla wszystkiego, co się tu pisze'),
    'feat_privacy':  ('What is stored, and for how long, is on the <a href=":url">Info page</a>',
                      'Co jest przechowywane i jak długo, opisuje <a href=":url">strona Informacji</a>'),
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
    'feat_index':     ('A catalogue of the torrents seen in the swarms, with their names and file lists',
                       'Katalog torrentów widzianych w rojach, z ich nazwami i listami plików'),
    'feat_search':    ('Members can search that index', 'Zalogowani mogą przeszukiwać ten indeks'),
    'feat_accounts':  ('Optional accounts — the tracker works without one',
                       'Opcjonalne konta — tracker działa bez nich'),
    'feat_languages': ('Available in more than one language', 'Dostępny w więcej niż jednym języku'),
    # "of every removal request" said more than the page shows: it counts the reports on file per
    # organisation (api/transparency.php), not every request that ever arrived.
    'feat_transparency': ('A public count of the removal requests received, per organisation',
                          'Publiczne zestawienie otrzymanych żądań usunięcia, według organizacji'),
})
