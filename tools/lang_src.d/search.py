# -*- coding: utf-8 -*-
"""Search

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
    'share':       ('Share', 'Udostępnij'),
    'share_view_title': ('Copy a link to exactly this view — the query, the filters, the sort and the page',
                         'Kopiuj link dokładnie do tego widoku — zapytanie, filtry, sortowanie i strona'),
    'share_one_title':  ('Copy a link to this torrent — it opens this panel for whoever follows it',
                         'Kopiuj link do tego torrenta — otworzy ten panel temu, kto w niego kliknie'),
    'files_head':  ('Files', 'Pliki'),
})
