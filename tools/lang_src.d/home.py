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
