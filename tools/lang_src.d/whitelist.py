# -*- coding: utf-8 -*-
"""Whitelist

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
