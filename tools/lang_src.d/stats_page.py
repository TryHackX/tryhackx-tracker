# -*- coding: utf-8 -*-
"""Stats page

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


# ── stats ───────────────────────────────────────────────────────────────────
# The public telemetry page: templates/pages/stats.php.
#
# TWO STRINGS GLUE A UNIT ONTO THEIR PLACEHOLDER, on purpose:
#   'next_update'  ':secs'   -> ":sec" is the placeholder, the trailing "s" is the unit  ("12s")
#   'heat_tip'     ':minm:'  -> ":min" is the placeholder, the "m" is the unit           ("05m:")
# assets/js/app.js re-renders both of those on every poll and writes exactly that form, so the
# server-rendered text has to match it. A translation must keep the unit glued the same way; the
# placeholder check in tests/lang_test.php compares the two languages token for token.
add('stats', {
    # ── header ──
    'h1':            ('Tracker Telemetry', 'Telemetria trackera'),
    'subtitle':      ('Live health diagnostics and swarm activity of our public OpenTracker engine.',
                      'Diagnostyka kondycji na żywo i aktywność rojów naszego publicznego silnika '
                      'OpenTracker.'),
    'beacon_live':   ('Live Syncing', 'Synchronizacja na żywo'),
    'beacon_sync':   ('Syncing Swarms...', 'Synchronizuję roje...'),

    # ── loader / error ──
    'loader_title':  ('Establishing Connection', 'Nawiązywanie połączenia'),
    'loader_sub':    ('Querying tracker stats interface...',
                      'Odpytuję interfejs statystyk trackera...'),
    'error_title':   ('Telemetry Fetch Failed', 'Nie udało się pobrać telemetrii'),
    'error_msg':     ('The statistics server is currently busy or unreachable. Swarm updates are '
                      'unaffected.',
                      'Serwer statystyk jest w tej chwili zajęty lub nieosiągalny. Nie ma to wpływu '
                      'na aktualizacje rojów.'),
    'retry':         ('Reconnect Telemetry', 'Ponów połączenie'),

    # ── counter tiles ──
    'm_torrents':     ('Active Torrents', 'Aktywne torrenty'),
    'm_torrents_sub': ('Swarm indices monitored', 'Monitorowane indeksy rojów'),
    'm_seeds':        ('Seeds (Uploaders)', 'Seedy (wysyłający)'),
    'm_peers':        ('Peers (total)', 'Peery (łącznie)'),
    'm_leechers':     ('Leechers (Swarm)', 'Leecherzy (rój)'),
    'm_completed':    ('Completed Transfers', 'Ukończone transfery'),
    'm_completed_sub': ('Successful index downloads', 'Udane pobrania z indeksu'),
    # The three subtitle styles under the Seeds / Leechers / Peers tiles
    # (tracker_stats_peer_label_style).
    'sub_pct':       (':pct% of total peers', ':pct% wszystkich peerów'),
    'sub_abs':       ('of :peers peers', 'z :peers peerów'),
    'sub_peers':     (':leechers leechers &middot; :seeds seeds',
                      ':leechers leecherów &middot; :seeds seedów'),

    # ── swarm timeline ──
    'tl_title':      ('Swarm Timeline', 'Oś czasu roju'),
    'tl_note':       ('Seeds, leechers, peers and tracked torrents over time (one sample every '
                      ':sec s, averaged for longer ranges). Shaded spans are hours in OPEN mode '
                      '(every torrent served); unshaded = whitelist-only hours.',
                      'Seedy, leecherzy, peery i śledzone torrenty w czasie (jedna próbka co :sec s, '
                      'uśredniana dla dłuższych zakresów). Zacieniowane pasy to godziny w trybie '
                      'OPEN (obsługiwany każdy torrent); bez cienia = godziny tylko dla whitelisty.'),

    # ── system status ──
    'sys_title':     ('System Status', 'Stan systemu'),
    'uptime':        ('Uptime', 'Czas działania'),
    'tracker_id':    ('Tracker ID', 'ID trackera'),
    'version_check': ('Version Check', 'Sprawdzenie wersji'),
    'git_commit':    ('Git Commit', 'Commit Git'),
    'analyzing':     ('Analyzing...', 'Analizuję...'),
    'integrity':     ('Swarm Integrity', 'Integralność roju'),
    'integrity_ok':  ('Active / Secure', 'Aktywna / bezpieczna'),
    'sync_loop':     ('Sync Loop', 'Pętla synchronizacji'),
    'next_update':   ('Next update in :secs', 'Następna aktualizacja za :secs'),

    # ── protocol distribution ──
    'proto_title':   ('Protocol Distribution', 'Rozkład protokołów'),
    'proto_note':    ('Share of all announce/scrape/connect requests handled since the tracker '
                      'started (cumulative).',
                      'Udział we wszystkich żądaniach announce/scrape/connect obsłużonych od startu '
                      'trackera (narastająco).'),
    'udp_sockets':   ('UDP Sockets', 'Gniazda UDP'),
    'tcp_sockets':   ('TCP Sockets', 'Gniazda TCP'),
    'connects':      ('Connects', 'Połączenia'),
    'announces':     ('Announces', 'Announce'),
    'scrapes':       ('Scrapes', 'Scrape'),
    'mismatches':    ('Mismatches', 'Niezgodności'),
    'accepts':       ('Accepts', 'Akceptacje'),
    'live_syncs':    ('Live Syncs', 'Live sync'),

    # ── announce renewal heatmap ──
    'heat_title':    ('Announce Renewal Intervals', 'Interwały odnawiania announce'),
    'heat_note':     ('Cumulative count of peer announce renewals grouped by their interval bucket '
                      '(00 - 44 min), totalled since the tracker started. Buckets are lifetime '
                      'totals, not the current swarm.',
                      'Łączna liczba odnowień announce od peerów, pogrupowana według przedziału '
                      'interwału (00 - 44 min) i sumowana od startu trackera. Przedziały to sumy z '
                      'całego czasu działania, a nie bieżący rój.'),
    'heat_tip':      ('Interval :minm: :count renews', 'Interwał :minm: :count odnowień'),
    'heat_empty':    ('No activity heat profile available.',
                      'Brak profilu aktywności do pokazania.'),
    'legend_low':    ('Low Announce rate', 'Niska częstość announce'),
    'legend_high':   ('High Announce rate', 'Wysoka częstość announce'),

    # ── HTTP diagnostics ──
    'http_title':    ('HTTP Engine Diagnostic Logs', 'Logi diagnostyczne silnika HTTP'),
    'http_status':   ('HTTP Response Status', 'Status odpowiedzi HTTP'),
    'http_count':    ('Incident Frequency', 'Liczba wystąpień'),
    'http_severity': ('Swarm Severity', 'Waga dla roju'),
    'sev_low':       ('Low', 'Niska'),
    'sev_moderate':  ('Moderate', 'Umiarkowana'),
    'sev_critical':  ('Critical', 'Krytyczna'),
})
