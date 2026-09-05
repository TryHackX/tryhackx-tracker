# -*- coding: utf-8 -*-
"""Admin: the observed-hash index page

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


# ── admin: index ────────────────────────────────────────────────────────────
add('a.index', {
    # header
    'title':       ('Index', 'Indeks'),
    'subtitle':    ('observed hashes &mdash; not a whitelist',
                    'zaobserwowane hashe &mdash; to nie whitelista'),

    # status card
    'status_head': ('Observed-hash index', 'Indeks zaobserwowanych hashy'),
    'poll_title':  ('Fetch a full scrape from the tracker now and refresh the index (bounded by the '
                    'poll budget)',
                    'Pobierz teraz pełny scrape z trackera i odśwież indeks (w granicach budżetu '
                    'odpytywania)'),
    'poll_now':    ('Poll now', 'Odpytaj teraz'),
    'loading_status': ('Loading status&hellip;', 'Ładowanie statusu&hellip;'),
    'disabled_note': ('The index is <strong>disabled</strong>. Enable it in '
                      '<a href=":url">Settings &rarr; Index</a> (measure the full-scrape cost during '
                      'OPEN hours first).',
                      'Indeks jest <strong>wyłączony</strong>. Włącz go w '
                      '<a href=":url">Ustawieniach &rarr; Indeks</a> (najpierw zmierz koszt pełnego '
                      'scrape w godzinach trybu OPEN).'),

    # scrape coverage card
    'cov_head':    ('Scrape coverage', 'Pokrycie scrape'),
    'range':       ('Range', 'Zakres'),
    'r_6h':        ('6h', '6h'),
    'r_24h':       ('24h', '24h'),
    'r_7d':        ('7d', '7d'),
    'r_2w':        ('2w', '2 tyg.'),
    'r_1m':        ('1m', '1 mies.'),
    'r_all':       ('All', 'Całość'),
    'cov_intro':   ('What each poll actually delivered, against how many torrents the tracker said it '
                    'had. <strong class="text-light">Delivered</strong> is entries past the resume '
                    'cursor &mdash; a poll that resumes walks past everything an earlier one already '
                    'handled, and counting those again would read as coverage it did not achieve. A gap '
                    'in the coverage line means the tracker&rsquo;s own count was not available then; a '
                    'marked point is a poll that arrived <strong class="text-light">truncated</strong>.',
                    'Co naprawdę dostarczył każdy poll, w zestawieniu z liczbą torrentów, którą podał '
                    'sam tracker. <strong class="text-light">Dostarczone</strong> to wpisy za kursorem '
                    'wznowienia &mdash; poll, który wznawia pracę, przechodzi obok wszystkiego, co '
                    'obsłużył już wcześniejszy, a liczenie tego drugi raz wyglądałoby na pokrycie, '
                    'którego wcale nie osiągnął. Przerwa w linii pokrycia znaczy, że własna liczba '
                    'trackera nie była wtedy dostępna; oznaczony punkt to poll, który przyszedł '
                    '<strong class="text-light">ucięty</strong>.'),

    # toolbar: search and filters
    'search_ph':   ('Search hash prefix or name...', 'Szukaj prefiksu hasha lub nazwy...'),
    'f_meta_title': ('Metadata status', 'Status metadanych'),
    'm_all':       ('All meta', 'Wszystkie meta'),
    'm_none':      ('No metadata', 'Brak metadanych'),
    'm_pending':   ('Pending', 'Oczekuje'),
    'm_fetching':  ('Fetching', 'Pobieranie'),
    'm_done':      ('Done', 'Gotowe'),
    'm_failed':    ('Failed', 'Nieudane'),
    'f_life_title': ('Lifecycle', 'Cykl życia'),
    'l_all':       ('All', 'Wszystkie'),
    'l_grace':     ('In grace', 'W karencji'),
    'l_protected': ('Protected', 'Chronione'),
    'l_promoted':  ('Promoted', 'Na whiteliście'),
    'perpage_title': ('Rows per page', 'Wierszy na stronę'),
    'files_title': ('Also match torrent file names (full-text)',
                    'Dopasuj też nazwy plików w torrencie (pełnotekstowo)'),

    # toolbar: metadata dropdown
    'meta_bulk_title': ('Queue metadata for many index rows; the worker fetches them after the '
                        'whitelist queue is empty',
                        'Zakolejkuj metadane dla wielu wierszy indeksu; worker pobierze je, gdy kolejka '
                        'whitelisty będzie pusta'),
    'fetch_meta':  ('Fetch metadata', 'Pobierz metadane'),
    'meta_hdr':    ('Queue metadata for&hellip;', 'Zakolejkuj metadane dla&hellip;'),
    'meta_missing': ('Missing (never fetched)', 'Brakujące (nigdy nie pobrane)'),
    'meta_missing_failed': ('Missing + failed', 'Brakujące + nieudane'),
    'meta_page':   ('This page (missing + failed)', 'Ta strona (brakujące + nieudane)'),
    'meta_near':   ('Near pages &plusmn;:n (missing + failed)',
                    'Sąsiednie strony &plusmn;:n (brakujące + nieudane)'),
    'meta_all':    ('All rows (re-fetch)', 'Wszystkie wiersze (pobierz ponownie)'),
    'meta_first_seen': ('First seen within&hellip; (missing + failed)',
                        'Pierwszy raz widziane w ciągu&hellip; (brakujące + nieudane)'),
    'last_24h':    ('Last 24 hours', 'Ostatnie 24 godziny'),
    'last_7d':     ('Last 7 days', 'Ostatnie 7 dni'),
    'last_14d':    ('Last 14 days', 'Ostatnie 14 dni'),
    'custom_range': ('Custom range&hellip;', 'Własny zakres&hellip;'),
    'meta_cancel': ('Cancel queued (resolved &rarr; done)',
                    'Anuluj zakolejkowane (rozwiązane &rarr; gotowe)'),
    'meta_restore_title': ('Every row that already has a name/size but lost its done status (bulk '
                           're-fetch, cancel) goes straight back to done — nothing is fetched or '
                           'deleted',
                           'Każdy wiersz, który ma już nazwę i rozmiar, ale stracił status gotowego '
                           '(masowe ponowne pobranie, anulowanie), wraca prosto do gotowego — nic nie '
                           'jest pobierane ani usuwane'),
    'meta_restore': ('Rebuild done (restore resolved rows)',
                     'Odbuduj gotowe (przywróć rozwiązane wiersze)'),

    # toolbar: scrape dropdown
    'scrape_title': ('Refresh seeders / leechers of the rows on this page (50 hashes per scrape '
                     'request)',
                     'Odśwież seederów / leecherów w wierszach na tej stronie (50 hashy na jedno '
                     'żądanie scrape)'),
    'refresh_sl':  ('Refresh S/L', 'Odśwież S/L'),
    'more_scrape': ('More scrape options', 'Więcej opcji scrape'),
    'scrape_hdr':  ('Scrape seeders / leechers for&hellip;', 'Scrape seederów / leecherów dla&hellip;'),
    'this_page':   ('This page', 'Ta strona'),
    'near_pages':  ('Near pages &plusmn;:n', 'Sąsiednie strony &plusmn;:n'),
    'scrape_stale': ('Stale (not scraped in 10 min)', 'Nieświeże (bez scrape od 10 min)'),
    'all_rows':    ('All rows', 'Wszystkie wiersze'),
    'first_seen':  ('First seen within&hellip;', 'Pierwszy raz widziane w ciągu&hellip;'),

    # selection bar
    'sel_count':   (':n selected', 'Zaznaczono: :n'),
    'promote':     ('Promote &rarr; whitelist', 'Dodaj &rarr; whitelista'),
    'clear_sel':   ('Clear', 'Wyczyść'),

    # table
    'check_all':   ('Select all on this page', 'Zaznacz wszystko na tej stronie'),
    'col_hash':    ('Info hash', 'Info hash'),
    'col_files_title': ('Resolved file count', 'Liczba rozwiązanych plików'),
    'col_seen':    ('Seen', 'Widziane'),
    'col_first_last': ('First / last', 'Pierwsze / ostatnie'),
    'col_meta':    ('Meta', 'Meta'),
    'col_actions': ('Actions', 'Akcje'),

    # details modal
    'modal_title': ('Index entry', 'Wpis indeksu'),
})
