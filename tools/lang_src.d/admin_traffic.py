# -*- coding: utf-8 -*-
"""Admin — traffic

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


# ── admin — traffic ─────────────────────────────────────────────────────────
# The page header, plus the handful of labels every card on it repeats. Everything here sits in a
# wrapping toolbar or on a button, so the Polish has to stay about as short as the English.
add('a.traffic', {
    'title':          ('Traffic', 'Ruch'),
    'subtitle':       ('what the swarm sends and what the firewall lets through',
                       'co wysyła swarm i co przepuszcza firewall'),
    'collapse':       ('Collapse', 'Zwiń'),
    'confirm':        ('Confirm', 'Potwierdź'),
    'admin_password': ('Admin Password *', 'Hasło administratora *'),
    'name':           ('Name', 'Nazwa'),
    'suggest':        ('Use suggested', 'Użyj sugestii'),
})

# ── swarm timeline card ─────────────────────────────────────────────────────
add('a.traffic', {
    'tl_head':           ('Swarm timeline', 'Oś czasu swarmu'),
    # s / d / 5-min are units and stay as they are; only the words around them move.
    'tl_meta':           ('one sample / :sec s · raw :raw d · 5-min :keep d · :vis',
                          'jedna próbka / :sec s · surowe :raw d · 5-min :keep d · :vis'),
    'tl_public':         ('public', 'publiczna'),
    'tl_admins':         ('admins only', 'tylko dla adminów'),
    'tl_settings_title': ('Timeline settings', 'Ustawienia osi czasu'),
})

# ── inbound UDP traffic and the throttle ────────────────────────────────────
add('a.traffic', {
    'net_head':            ('UDP traffic', 'Ruch UDP'),
    'net_meta':            ('port :port · sample :sec s · keep :keep d',
                            'port :port · próbka :sec s · trzymaj :keep d'),
    'net_settings_title':  ('UDP traffic settings', 'Ustawienia ruchu UDP'),
    'panic_title':         ('Clamp the port to 10 000 packets/second for 15 minutes; the janitor '
                            'puts the previous setting back automatically',
                            'Zaciska port do 10 000 pakietów/sekundę na 15 minut; janitor sam '
                            'przywraca poprzednie ustawienie'),
    'panic':               ('Throttle hard&hellip;', 'Mocno zdław&hellip;'),
    'net_reading':         ('Reading the firewall&hellip;', 'Odczytywanie firewalla&hellip;'),
    'in_limit':            ('Inbound limit', 'Limit wejściowy'),
    'pps_aria':            ('Packets per second', 'Pakiety na sekundę'),
    'in_slider_aria':      ('Inbound packets/second limit', 'Limit pakietów/sekundę na wejściu'),
    'suggest_title':       ('Set the slider to the suggested value',
                            'Ustaw suwak na sugerowaną wartość'),
    'preview_rules_title': ('Render and syntax-check the ruleset without touching the firewall',
                            'Wygeneruj ruleset i sprawdź jego składnię bez ruszania firewalla'),
    'preview_rules':       ('Preview ruleset', 'Podgląd rulesetu'),
    'apply_limit':         ('Apply limit&hellip;', 'Zastosuj limit&hellip;'),
    'remove_limit':        ('Remove limit&hellip;', 'Usuń limit&hellip;'),
})

# ── outbound budget (the other half of the same decision) ───────────────────
add('a.traffic', {
    'eg_title':         ('Outbound budget <span class="nl-unit">(replies the tracker sends)</span>',
                         'Budżet wyjściowy <span class="nl-unit">(odpowiedzi, które wysyła '
                         'tracker)</span>'),
    'eg_pps_aria':      ('Outbound packets per second', 'Pakiety wyjściowe na sekundę'),
    'eg_slider_aria':   ('Outbound packets/second budget', 'Budżet pakietów/sekundę na wyjściu'),
    'eg_waiting':       ('Reading the outbound rule from the firewall&hellip;',
                         'Odczytywanie reguły wyjściowej z firewalla&hellip;'),
    'eg_suggest_title': ('Set the slider to a value with headroom over what was measured',
                         'Ustaw suwak na wartość z zapasem ponad to, co zmierzono'),
    'apply_budget':     ('Apply budget&hellip;', 'Zastosuj budżet&hellip;'),
    'chart_title':      ('Packets / second', 'Pakiety / sekundę'),
    'chart_range_aria': ('Chart range', 'Zakres wykresu'),
})

# ── the firewall's two modals ───────────────────────────────────────────────
add('a.traffic', {
    'net_modal_title':    ('Change the inbound limit', 'Zmiana limitu wejściowego'),
    'rules_preview':      ('Ruleset preview', 'Podgląd rulesetu'),
    # :file is the span the JS fills with the path that would be written.
    'rules_preview_note': ('Syntax-checked by <code>nft -c</code> on the server. Nothing has been '
                           'loaded &mdash; this is exactly what :file would contain.',
                           'Składnię sprawdza <code>nft -c</code> na serwerze. Nic nie zostało '
                           'załadowane &mdash; to dokładnie to, co znalazłoby się w :file.'),
})

# ── address lists ───────────────────────────────────────────────────────────
# Allow / Block / Under pressure below are the same three words as the select options further down;
# they have to read the same in Polish or the paragraph stops describing the form.
add('a.traffic', {
    'ipl_head':           ('Address lists', 'Listy adresów'),
    'ipl_settings_title': ('Address list settings', 'Ustawienia list adresów'),
    'ipl_add':            ('Add a list', 'Dodaj listę'),
    'ipl_push_title':     ('Load what is below into the firewall',
                           'Załaduj to, co poniżej, do firewalla'),
    'ipl_push':           ('Push to firewall&hellip;', 'Wyślij do firewalla&hellip;'),
    'ipl_intro':          ('Whole networks and whole countries, from a file you paste or upload or '
                           'from a URL that is re-downloaded on a timer. '
                           '<strong class="text-light">Allow</strong> is never dropped &middot; '
                           '<strong class="text-light">Block</strong> is always dropped &middot; '
                           '<strong class="text-light">Under pressure</strong> is dropped only when '
                           'the machine is busy. Your <a href=":url">trusted addresses</a> beat '
                           'every list here. Changes are saved as you make them and reach the '
                           'firewall when you press <em>Push</em>.',
                           'Całe sieci i całe kraje — z pliku, który wklejasz albo wysyłasz, albo z '
                           'adresu URL pobieranego ponownie co jakiś czas. '
                           '<strong class="text-light">Przepuszczaj</strong> nigdy nie jest '
                           'odrzucane &middot; <strong class="text-light">Blokuj</strong> jest '
                           'odrzucane zawsze &middot; <strong class="text-light">Pod '
                           'obciążeniem</strong> jest odrzucane tylko wtedy, gdy maszyna jest '
                           'zajęta. Twoje <a href=":url">zaufane adresy</a> wygrywają z każdą listą '
                           'tutaj. Zmiany zapisują się na bieżąco, a do firewalla trafiają, gdy '
                           'naciśniesz <em>Wyślij</em>.'),
    'ipl_reading':        ('Reading the lists&hellip;', 'Odczytywanie list&hellip;'),
})

# ── add / edit an address list ──────────────────────────────────────────────
add('a.traffic', {
    'ipl_modal_title': ('Add an address list', 'Dodaj listę adresów'),
    'ipl_name_ph':     ('China (ipdeny)', 'Chiny (ipdeny)'),
    'ipl_kind':        ('What it does', 'Co robi'),
    'ipl_block':       ('Block', 'Blokuj'),
    'ipl_allow':       ('Allow &mdash; never rate-limited',
                        'Przepuszczaj &mdash; nigdy nie limitowane'),
    'ipl_when':        ('When', 'Kiedy'),
    'ipl_always':      ('Always', 'Zawsze'),
    'ipl_soft':        ('Only under pressure', 'Tylko pod obciążeniem'),
    'ipl_source':      ('Where the addresses come from', 'Skąd biorą się adresy'),
    'ipl_src_url':     ('A URL, re-downloaded on a timer',
                        'Adres URL, pobierany ponownie co jakiś czas'),
    'ipl_src_manual':  ('A file or pasted text', 'Plik albo wklejony tekst'),
    'ipl_url':         ('URL', 'URL'),
    'ipl_ttl':         ('Re-download every (minutes)', 'Pobieraj ponownie co (minuty)'),
    'ipl_upload':      ('Upload a file&hellip;', 'Wyślij plik&hellip;'),
    'ipl_drop_aria':   ('Choose a file, or drop one here', 'Wybierz plik albo upuść go tutaj'),
    'ipl_drop_main':   ('Drop a list here, or <u>choose a file</u>',
                        'Upuść tu listę albo <u>wybierz plik</u>'),
    'ipl_drop_sub':    ('.txt .zone .list .cidr &middot; up to 8 MiB &middot; one address or CIDR '
                        'per line',
                        '.txt .zone .list .cidr &middot; do 8 MiB &middot; jeden adres albo CIDR w '
                        'linii'),
    'ipl_paste':       ('&hellip; or paste addresses, one per line',
                        '&hellip; albo wklej adresy, po jednym w linii'),
    'ipl_text_ph':     ('# lines starting with # or ; are ignored',
                        '# linie zaczynające się od # albo ; są pomijane'),
    'ipl_save':        ('Add the list', 'Dodaj listę'),
})

# ── opentracker performance ─────────────────────────────────────────────────
# "drop-in" is the systemd term the whole feature is built around and stays as it is.
add('a.traffic', {
    'ot_head':           ('OpenTracker &mdash; performance', 'OpenTracker &mdash; wydajność'),
    'ot_settings_title': ('Performance settings', 'Ustawienia wydajności'),
    'ot_preview_title':  ('Render the drop-in without writing it',
                          'Wygeneruj drop-in bez zapisywania go'),
    'ot_preview':        ('Preview drop-in', 'Podgląd drop-inu'),
    'ot_apply':          ('Apply&hellip;', 'Zastosuj&hellip;'),
    'ot_workers':        ('Set workers&hellip;', 'Ustaw workery&hellip;'),
    'ot_restart_title':  ('Restart the tracker service', 'Zrestartuj usługę trackera'),
    'ot_restart':        ('Restart&hellip;', 'Restart&hellip;'),
    'ot_reset_title':    ("Delete the panel's drop-in", 'Usuń drop-in panelu'),
    'ot_reset':          ('Reset&hellip;', 'Reset&hellip;'),
    'ot_reading':        ('Reading the service&hellip;', 'Odczytywanie usługi&hellip;'),
    'ot_modal_title':    ('Change how the tracker runs', 'Zmiana sposobu działania trackera'),
    'ot_workers_label':  ('UDP worker threads', 'Wątki robocze UDP'),
    'ot_dropin_preview': ('Drop-in preview', 'Podgląd drop-inu'),
})

# ── nothing is switched on ──────────────────────────────────────────────────
add('a.traffic', {
    'nothing_head': ('Nothing is being measured', 'Nic nie jest mierzone'),
    'off_tl':       ('off &mdash; <a href=":url">Settings &rarr; Swarm timeline</a>',
                     'wyłączone &mdash; <a href=":url">Ustawienia &rarr; Oś czasu swarmu</a>'),
    'off_net':      ('off &mdash; <a href=":url">Settings &rarr; UDP traffic &amp; rate limit</a>',
                     'wyłączone &mdash; <a href=":url">Ustawienia &rarr; Ruch UDP i limit</a>'),
})

# ── kernel network buffers ──────────────────────────────────────────────────
add('a.traffic', {
    'sy_head':           ('Kernel network buffers', 'Bufory sieciowe jądra'),
    'sy_settings_title': ('Helper and confirmation window', 'Helper i okno potwierdzenia'),
    'sy_suggest_title':  ('Fill in what the measurements above actually support',
                          'Wpisz to, na co naprawdę pozwalają pomiary powyżej'),
    'sy_preview_title':  ('Render the file without writing it', 'Wygeneruj plik bez zapisywania go'),
    'sy_preview':        ('Preview file', 'Podgląd pliku'),
    'sy_arm':            ('Apply for a while&hellip;', 'Zastosuj na chwilę&hellip;'),
    'sy_restore':        ('Restore defaults', 'Przywróć domyślne'),
    'sy_armed_text':     ('A change is in force and will undo itself.',
                          'Zmiana obowiązuje i sama się cofnie.'),
    'sy_revert':         ('Put it back now', 'Cofnij teraz'),
    'sy_keep':           ('Keep it (survives a reboot)&hellip;',
                          'Zostaw ją (przetrwa reboot)&hellip;'),
    'sy_reading':        ('Reading the kernel&hellip;', 'Odczytywanie jądra&hellip;'),
    'sy_modal_title':    ('Apply the kernel buffers', 'Zastosowanie buforów jądra'),
    'sy_file_preview':   ('File preview', 'Podgląd pliku'),
})

# ── extra opentracker instances ─────────────────────────────────────────────
add('a.traffic', {
    'cl_head':         ('OpenTracker instances', 'Instancje OpenTrackera'),
    'cl_reload_title': ('SIGHUP every instance so it re-reads the shared accesslist',
                        'Wyślij SIGHUP do każdej instancji, żeby ponownie wczytała wspólną '
                        'accesslistę'),
    'cl_reload':       ('Reload all', 'Przeładuj wszystkie'),
    'cl_add':          ('Add instance&hellip;', 'Dodaj instancję&hellip;'),
    'cl_reading':      ('Reading the roster&hellip;', 'Odczytywanie listy instancji&hellip;'),
    'cl_modal_title':  ('Add a tracker instance', 'Dodaj instancję trackera'),
    'cl_note':         ('A new instance starts answering announces as soon as it exists. It shares '
                        'this tracker&rsquo;s accesslist and its white/black mode, and runs the '
                        'same binary &mdash; only the ports differ. Clients reach it only if you '
                        'publish the extra announce URL below.',
                        'Nowa instancja zaczyna odpowiadać na announce, gdy tylko powstanie. Dzieli '
                        'accesslistę tego trackera i jego tryb white/black i uruchamia ten sam plik '
                        'binarny &mdash; różnią się tylko porty. Klienci trafią do niej tylko '
                        'wtedy, gdy opublikujesz dodatkowy adres announce poniżej.'),
    'cl_name_hint':    ('a&ndash;z, 0&ndash;9 and -, up to 16.',
                        'a&ndash;z, 0&ndash;9 i -, maksymalnie 16 znaków.'),
    'cl_udp':          ('UDP port', 'Port UDP'),
    'cl_tcp':          ('TCP port', 'Port TCP'),
    'cl_affinity':     ('CPU affinity <small class="settings-hint">(optional)</small>',
                        'Przypisanie do CPU <small class="settings-hint">(opcjonalnie)</small>'),
    'cl_affinity_ph':  ('e.g. 2-3', 'np. 2-3'),
    'cl_workers':      ('UDP workers <small class="settings-hint">(0 = copy the '
                        'primary&rsquo;s)</small>',
                        'Workery UDP <small class="settings-hint">(0 = tyle, co w '
                        'głównej)</small>'),
    'cl_check':        ('Check the ports', 'Sprawdź porty'),
    'cl_create':       ('Create', 'Utwórz'),
})

# ── live peer sync ──────────────────────────────────────────────────────────
add('a.traffic', {
    'ls_head':    ('Live peer sync', 'Synchronizacja peerów na żywo'),
    'ls_on':      ('Turn on&hellip;', 'Włącz&hellip;'),
    'ls_off':     ('Turn off&hellip;', 'Wyłącz&hellip;'),
    'ls_reading': ('Reading the tunnel&hellip;', 'Odczytywanie tunelu&hellip;'),
})

# ── stability probe ─────────────────────────────────────────────────────────
add('a.traffic', {
    'tn_head':       ('Stability probe', 'Test stabilności'),
    'tn_what_title': ('Which limit the run moves. Kernel buffers are never ramped — a socket\'s '
                      'buffer is fixed when it is created, so testing one means restarting the '
                      'tracker at every step.',
                      'Który limit przesuwa przebieg. Bufory jądra nigdy nie są podnoszone — bufor '
                      'gniazda ustala się przy jego tworzeniu, więc testowanie go oznaczałoby '
                      'restart trackera na każdym kroku.'),
    'tn_inbound':    ('Receive limit', 'Limit odbioru'),
    'tn_outbound':   ('Reply budget', 'Budżet odpowiedzi'),
    'tn_both':       ('Both together', 'Oba naraz'),
    'tn_dry_title':  ('Walk the same plan without touching the firewall &mdash; proves the plumbing '
                      'works before a real run',
                      'Przejdź ten sam plan bez ruszania firewalla &mdash; sprawdza, czy mechanika '
                      'działa, zanim ruszy prawdziwy przebieg'),
    'tn_dry':        ('Test', 'Test'),
    'tn_start':      ('Run it&hellip;', 'Uruchom&hellip;'),
    'tn_cancel':     ('Stop', 'Zatrzymaj'),
})
