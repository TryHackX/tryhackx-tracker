# -*- coding: utf-8 -*-
"""Info

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
