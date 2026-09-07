# -*- coding: utf-8 -*-
"""Admin — whitelist

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


# ── admin — whitelist (templates/admin/whitelist.php) ───────────────────────
# The metadata states (none / pending / fetching / done / failed) and the source values
# (web / api / admin / forum) are rendered as raw badges by assets/js/admin-whitelist.js, so the
# filter labels here stay close to those words rather than inventing new ones.
add('a.wl', {
    'title':            ('Whitelist', 'Whitelista'),

    # ── status card ─────────────────────────────────────────────────────────
    'status_head':      ('Tracker whitelist status', 'Status whitelisty trackera'),
    'status_loading':   ('Loading status&hellip;', 'Wczytywanie statusu&hellip;'),
    'regen':            ('Regenerate file', 'Wygeneruj plik'),
    'regen_title':      ('Rewrite the whitelist file from the database and ask the tracker to reload it',
                         'Zapisz plik whitelisty na nowo z bazy i poproś tracker o przeładowanie'),
    'import':           ('Import blacklist &rarr; bans', 'Import blacklisty &rarr; bany'),
    'import_title':     ('Import every hash from the legacy blacklist file into the banned hashes',
                         'Zaimportuj wszystkie hashe ze starego pliku blacklisty do zbanowanych hashy'),
    'reload':           ('Reload tracker now', 'Przeładuj tracker teraz'),
    'reload_title':     ('Send SIGHUP to the tracker service so it re-reads the whitelist (requires the admin password)',
                         'Wyślij SIGHUP do usługi trackera, żeby wczytał whitelistę na nowo (wymaga hasła administratora)'),
    'restart':          ('Restart (on the dashboard)', 'Restart (na pulpicie)'),
    'restart_title':    ('The tracker restart button lives on the dashboard',
                         'Przycisk restartu trackera znajdziesz na pulpicie'),

    # ── sub-view tabs (narrow — keep the Polish just as short) ──────────────
    'tab_banned':       ('Banned hashes', 'Zbanowane hashe'),
    'tab_clients':      ('API clients', 'Klienci API'),
    'tab_bans':         ('API bans', 'Bany API'),
    'tab_review':       ('To review', 'Do sprawdzenia'),

    # ── review queue ────────────────────────────────────────────────────────
    'rv_submissions':   ('Submissions', 'Zgłoszenia'),
    'rv_rewrites':      ('Rewrites', 'Poprawki'),
    'rv_search_ph':     ('Hash, name, link or a word from the text...',
                         'Hash, nazwa, link albo słowo z tekstu...'),
    'rv_status_title':  ('Which items to show', 'Które pozycje pokazać'),
    'rv_st_pending':    ('Waiting for a decision', 'Czekają na decyzję'),
    'rv_st_approved':   ('Published', 'Opublikowane'),
    'rv_st_rejected':   ('Rejected', 'Odrzucone'),
    'rv_st_all':        ('Everything decided or waiting', 'Wszystko: rozstrzygnięte i czekające'),
    'rv_explain':       ('Source links and descriptions written by submitters. <strong>The torrents are '
                         'already registered</strong> &mdash; only the words are waiting. Everything below is '
                         'rendered exactly as a visitor would see it, not as the source, because an image or a '
                         'broken tag only shows itself after rendering.',
                         'Linki źródłowe i opisy napisane przez zgłaszających. <strong>Torrenty są już '
                         'zarejestrowane</strong> &mdash; czeka sam tekst. Wszystko poniżej jest wyrenderowane '
                         'dokładnie tak, jak zobaczy to odwiedzający, a nie jako źródło, bo obrazek albo zepsuty '
                         'tag pokazuje się dopiero po wyrenderowaniu.'),
    'rv_edits_head':    ('Proposed rewrites', 'Proponowane poprawki'),
    'rv_edits_note':    ('Someone has suggested different wording for a description that is already published. '
                         '<strong>Nothing changes until you apply one</strong> &mdash; and applying keeps the '
                         'version it replaces, so an accepted rewrite can be undone by accepting the old text back.',
                         'Ktoś zaproponował inne brzmienie opisu, który jest już opublikowany. <strong>Nic się nie '
                         'zmienia, dopóki którejś nie zatwierdzisz</strong> &mdash; a zatwierdzenie zachowuje '
                         'zastąpioną wersję, więc przyjętą poprawkę można cofnąć, przyjmując z powrotem stary tekst.'),

    # ── whitelist toolbar ───────────────────────────────────────────────────
    'wl_search_ph':     ('Search hash prefix, IP, name...', 'Szukaj prefiksu hasha, IP, nazwy...'),
    'clear_search':     ('Clear search', 'Wyczyść wyszukiwanie'),
    'src_all':          ('All sources', 'Wszystkie źródła'),
    'f_meta_title':     ('Metadata status', 'Status metadanych'),
    'meta_all':         ('All meta', 'Wszystkie meta'),
    'meta_none':        ('No metadata', 'Bez metadanych'),
    'meta_pending':     ('Pending', 'Oczekujące'),
    'meta_fetching':    ('Fetching', 'Pobierane'),
    'meta_done':        ('Done', 'Gotowe'),
    'meta_failed':      ('Failed', 'Nieudane'),
    'f_perpage_title':  ('Rows per page', 'Wierszy na stronę'),
    'per_page':         (':n / page', ':n / stronę'),
    'f_banned_title':   ('Banned state', 'Stan bana'),
    'st_active':        ('Active', 'Aktywne'),
    'st_banned':        ('Banned', 'Zbanowane'),
    'st_both':          ('Active + banned', 'Aktywne + zbanowane'),
    'search_files_title': ('Also match torrent file names (full-text)',
                           'Przeszukuj też nazwy plików w torrentach (pełnotekstowo)'),
    'search_files':     ('Also search file names', 'Szukaj też w nazwach plików'),
    'group_ip_title':   ('Sort by IP and colour rows per submitter',
                         'Sortuj po IP i koloruj wiersze według zgłaszającego'),
    'group_ip':         ('Group by IP', 'Grupuj po IP'),

    # ── bulk metadata dropdown ──────────────────────────────────────────────
    'fetch_meta':       ('Fetch metadata', 'Pobierz metadane'),
    'meta_bulk_title':  ('Queue metadata fetching for many rows at once. The metadata worker then fetches them '
                         'in the background, one hash after another.',
                         'Zakolejkuj pobranie metadanych dla wielu wierszy naraz. Worker metadanych pobiera je '
                         'potem w tle, hash po hashu.'),
    'meta_dd_head':     ('Queue metadata for&hellip;', 'Zakolejkuj metadane dla&hellip;'),
    'meta_missing':     ('Missing (never fetched)', 'Brakujące (nigdy nie pobrane)'),
    'meta_missing_failed': ('Missing + failed', 'Brakujące + nieudane'),
    'meta_page':        ('This page (missing + failed)', 'Ta strona (brakujące + nieudane)'),
    'meta_near':        ('Near pages &plusmn;:n (missing + failed)',
                         'Sąsiednie strony &plusmn;:n (brakujące + nieudane)'),
    'meta_all_refetch': ('All active hashes (re-fetch)', 'Wszystkie aktywne hashe (pobierz ponownie)'),
    'meta_within_head': ('Added within&hellip; (missing + failed)',
                         'Dodane w ciągu&hellip; (brakujące + nieudane)'),
    'last_24h':         ('Last 24 hours', 'Ostatnie 24 godziny'),
    'last_7d':          ('Last 7 days', 'Ostatnie 7 dni'),
    'last_14d':         ('Last 14 days', 'Ostatnie 14 dni'),
    'custom_range':     ('Custom range&hellip;', 'Własny zakres&hellip;'),
    'meta_cancel':      ('Cancel queued (pending &rarr; none)', 'Anuluj zakolejkowane (pending &rarr; none)'),
    'meta_restore':     ('Rebuild done (restore resolved rows)', 'Odbuduj done (przywróć rozpoznane wiersze)'),
    'meta_restore_title': ('Every row that already has a name/size but lost its done status (bulk re-fetch, '
                           'cancel) goes straight back to done — nothing is fetched or deleted',
                           'Każdy wiersz, który ma już nazwę/rozmiar, ale stracił status done (masowe pobranie, '
                           'anulowanie), wraca od razu do done — nic nie jest pobierane ani usuwane'),
    'meta_dd_note':     ('Only queues the rows — the metadata worker fetches them in the background, one hash '
                         'after another. Large queues take a while; <em>Cancel queued</em> stops the backlog '
                         '(rows being fetched right now still finish).',
                         'Tylko kolejkuje wiersze — worker metadanych pobiera je w tle, hash po hashu. Duże '
                         'kolejki chwilę trwają; <em>Anuluj zakolejkowane</em> zatrzymuje zaległości (wiersze '
                         'pobierane właśnie teraz i tak się dokończą).'),

    # ── bulk scrape (split control) ─────────────────────────────────────────
    'scrape':           ('Refresh S/L', 'Odśwież S/L'),
    'scrape_title':     ('Refresh seeders / leechers of the rows on this page from the tracker (50 hashes per '
                         'scrape request)',
                         'Odśwież seedery / leechery wierszy na tej stronie prosto z trackera (50 hashy na jedno '
                         'żądanie scrape)'),
    'scrape_more_title': ('More scrape options', 'Więcej opcji scrape'),
    'scrape_toggle':    ('Toggle scrape options', 'Przełącz opcje scrape'),
    'scrape_dd_head':   ('Scrape seeders / leechers for&hellip;', 'Scrape seederów / leecherów dla&hellip;'),
    'scrape_page':      ('This page', 'Ta strona'),
    'scrape_near':      ('Near pages &plusmn;:n', 'Sąsiednie strony &plusmn;:n'),
    'scrape_stale':     ('Stale (not scraped in 10 min)', 'Nieświeże (bez scrape od 10 min)'),
    'scrape_all':       ('All active hashes', 'Wszystkie aktywne hashe'),
    'added_within':     ('Added within&hellip;', 'Dodane w ciągu&hellip;'),
    'scrape_dd_note':   ('Batches of 50 hashes per tracker request; long runs continue automatically until '
                         'everything is scraped.',
                         'Po 50 hashy na jedno żądanie do trackera; długie przebiegi lecą dalej automatycznie, aż '
                         'wszystko zostanie zescrapowane.'),
    'add_hashes':       ('Add hashes', 'Dodaj hashe'),

    # ── selection bar ───────────────────────────────────────────────────────
    'n_selected':       (':n selected', 'zaznaczono: :n'),
    'ban':              ('Ban', 'Zbanuj'),
    'bulk_meta_title':  ('Queue metadata fetch for the selected rows (max 500 per request)',
                         'Zakolejkuj pobranie metadanych dla zaznaczonych wierszy (maks. 500 na żądanie)'),
    'clear':            ('Clear', 'Wyczyść'),

    # ── table columns (narrow — keep the Polish just as short) ──────────────
    'select_all_title': ('Select all on this page', 'Zaznacz wszystko na tej stronie'),
    'col_id':           ('ID', 'ID'),
    'col_hash':         ('Info Hash', 'Info Hash'),
    'col_name':         ('Name', 'Nazwa'),
    'col_size':         ('Size', 'Rozmiar'),
    'col_files':        ('Files', 'Pliki'),
    'col_files_title':  ('Resolved file count', 'Ustalona liczba plików'),
    'col_source':       ('Source', 'Źródło'),
    'col_ip':           ('IP', 'IP'),
    'col_meta':         ('Meta', 'Meta'),
    'col_sl':           ('S / L', 'S / L'),
    'col_sl_title':     ('Seeders / leechers (last scrape)', 'Seedery / leechery (ostatni scrape)'),
    'col_date':         ('Date', 'Data'),
    'col_actions':      ('Actions', 'Akcje'),
    'col_reason':       ('Reason', 'Powód'),
    'col_label':        ('Label', 'Etykieta'),
    'col_scope':        ('Scope', 'Zakres'),
    'col_scope_title':  ('Which v1 endpoints this key may call', 'Które endpointy v1 może wywoływać ten klucz'),
    'col_keyid':        ('Key ID', 'Key ID'),
    'col_secret':       ('Secret', 'Sekret'),
    'col_enabled':      ('Enabled', 'Włączony'),
    'col_created':      ('Created', 'Utworzono'),
    'col_last_used':    ('Last used', 'Ostatnio użyty'),
    'col_last_ip':      ('Last IP', 'Ostatnie IP'),
    'col_requests':     ('Requests', 'Żądania'),
    'col_bucket':       ('Bucket', 'Bucket'),
    'col_endpoint':     ('Endpoint', 'Endpoint'),
    'col_expires':      ('Expires', 'Wygasa'),
    'col_lifted':       ('Lifted', 'Zdjęty'),

    # ── banned hashes view ──────────────────────────────────────────────────
    'bn_search_ph':     ('Search hash prefix or reason...', 'Szukaj prefiksu hasha albo powodu...'),
    'ban_hashes':       ('Ban hashes', 'Zbanuj hashe'),

    # ── API clients view ────────────────────────────────────────────────────
    'cl_note':          ('Server-to-server clients authenticate with <code>Authorization: Bearer '
                         'key_id.secret</code>. The secret is shown once, at creation.',
                         'Klienci server-to-server uwierzytelniają się nagłówkiem <code>Authorization: Bearer '
                         'key_id.secret</code>. Sekret pokazujemy raz, przy tworzeniu.'),
    'cl_create':        ('Create client', 'Utwórz klienta'),

    # ── API bans view ───────────────────────────────────────────────────────
    'ab_search_ph':     ('Search IP prefix, key id or reason...', 'Szukaj prefiksu IP, key id albo powodu...'),
    'ab_active':        ('Active bans', 'Aktywne bany'),
    'ab_all':           ('All bans', 'Wszystkie bany'),
    'ban_ip':           ('Ban IP', 'Zbanuj IP'),

    # ── add / ban hashes modals ─────────────────────────────────────────────
    'add_modal_title':  ('Add hashes to the whitelist', 'Dodaj hashe do whitelisty'),
    'add_modal_note':   ('One magnet link or 40-character info hash per line (max 500). Added hashes get source '
                         '<code>admin</code> and metadata is fetched automatically by the worker.',
                         'Jeden link magnet albo 40-znakowy info hash w wierszu (maks. 500). Dodane hashe dostają '
                         'źródło <code>admin</code>, a metadane pobiera automatycznie worker.'),
    'add':              ('Add', 'Dodaj'),
    'bn_modal_note':    ('One magnet link or info hash per line (max 500). Banned hashes are removed from the '
                         'whitelist file and can never be registered again until unbanned.',
                         'Jeden link magnet albo info hash w wierszu (maks. 500). Zbanowane hashe znikają z pliku '
                         'whitelisty i nie da się ich zarejestrować ponownie, dopóki nie zdejmiesz bana.'),
    'optional':         ('(optional)', '(opcjonalnie)'),
    'bn_reason_ph':     ('e.g. DMCA notice #1234', 'np. zgłoszenie DMCA #1234'),
    'details_title':    ('Whitelist entry', 'Wpis whitelisty'),

    # ── reload tracker modal ────────────────────────────────────────────────
    'reload_modal_title': ('Reload Tracker Whitelist', 'Przeładuj whitelistę trackera'),
    'reload_modal_note': ('This runs <code>systemctl reload :svc</code> on the server, sending it a '
                          '<strong>SIGHUP</strong> so it re-reads its white/blacklist <strong>without '
                          'downtime</strong>. Enter your admin password to confirm.',
                          'To uruchamia na serwerze <code>systemctl reload :svc</code> i wysyła '
                          '<strong>SIGHUP</strong>, żeby usługa wczytała swoją white/blacklistę na nowo '
                          '<strong>bez przerwy w działaniu</strong>. Potwierdź hasłem administratora.'),
    'admin_password':   ('Admin Password *', 'Hasło administratora *'),
    'reload_now':       ('Reload now', 'Przeładuj teraz'),

    # ── API client token modal (shown once) ─────────────────────────────────
    'token_title':      ('API client created', 'Klient API utworzony'),
    'token_warn':       ('Copy the bearer token now &mdash; it is <strong>shown only once</strong> and cannot be '
                         'recovered. Only a hash of the secret is stored.',
                         'Skopiuj teraz token bearer &mdash; pokazujemy go <strong>tylko raz</strong> i nie da się '
                         'go odzyskać. Przechowujemy wyłącznie hash sekretu.'),
    'copy_token':       ('Copy token', 'Kopiuj token'),
    'token_done':       ('Done', 'Gotowe'),

    # ── API ban snapshot modal ──────────────────────────────────────────────
    'snapshot_title':   ('API ban', 'Ban API'),
    'snapshot_label':   ('Request snapshot', 'Zrzut żądania'),
    'copy_json':        ('Copy JSON', 'Kopiuj JSON'),
    'lift_ban':         ('Lift ban', 'Zdejmij ban'),

    # ── API ban add modal ───────────────────────────────────────────────────
    'ab_modal_title':   ('Ban an IP from the API', 'Zbanuj IP w API'),
    'ab_ip':            ('IP address *', 'Adres IP *'),
    'ab_ip_ph':         ('203.0.113.7 or 2001:db8::1', '203.0.113.7 albo 2001:db8::1'),
    'ab_days':          ('Days', 'Dni'),
})
