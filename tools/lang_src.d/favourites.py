# -*- coding: utf-8 -*-
"""Favourites, public profiles and "my uploads"

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


# ── the profile page ────────────────────────────────────────────────────────
add('profile', {
    'h1':             ('Profile', 'Profil'),
    # One wording for every "no" — a wrong name, a hidden profile, a missing permission. Saying
    # which would be a scriptable way to tell hidden from nonexistent.
    'not_found':      ('There is nothing here to see.', 'Nie ma tu nic do obejrzenia.'),
    'member_since':   ('member since :date', 'w serwisie od :date'),
    'this_is_you':    ('this is your profile, as others see it',
                       'to twój profil, tak jak widzą go inni'),
    'nothing_shared': ('This account does not share anything publicly.',
                       'To konto nie udostępnia niczego publicznie.'),
    'favourites':     ('Favourites', 'Ulubione'),
    'uploads':        ('Registered torrents', 'Zarejestrowane torrenty'),
    'search_ph':      ('Filter by name or hash…', 'Filtruj po nazwie albo hashu…'),
    'sort':           ('Sort', 'Sortowanie'),
    'sort_added':     ('Newest first', 'Najnowsze'),
    'sort_name':      ('By name', 'Po nazwie'),
    'sort_size':      ('Largest first', 'Największe'),
    'sort_seeders':   ('Most seeders', 'Najwięcej seederów'),
    'status':         ('Status', 'Status'),
    'status_any':     ('Any status', 'Dowolny status'),
    # NOT "approved": nobody approved it. Either the operator's policy demanded a probe and it
    # passed, or the policy never asked.
    'status_live':    ('Served', 'Serwowane'),
    'status_waiting': ('Being checked', 'Sprawdzane'),
    'status_refused': ('Did not pass the check', 'Nie przeszło sprawdzenia'),
    'status_blocked': ('Blocked', 'Zablokowane'),
})

# ── the account page's own tabs and controls ────────────────────────────────
add('account', {
    'tab_overview':   ('Overview', 'Przegląd'),
    'tab_favourites': ('Favourites', 'Ulubione'),
    'tab_uploads':    ('My torrents', 'Moje torrenty'),
    'fav_heading':    ('Favourites', 'Ulubione'),
    'fav_privacy':    ('Privacy', 'Prywatność'),
    'fav_public_label': ('Show my favourites on my profile',
                         'Pokazuj moje ulubione na moim profilu'),
    'fav_public_hint': ('Your profile is at <code>?action=u&amp;name=:name</code>. Off, it shows nothing but your name.',
                        'Twój profil jest pod <code>?action=u&amp;name=:name</code>. Wyłączone — widać na nim tylko twoją nazwę.'),
    'fav_listed_label': ('Let my name appear in “who has this in favourites”',
                         'Pozwól, by moja nazwa pojawiała się na liście „kto ma to w ulubionych”'),
    'fav_listed_hint': ('Off, you are not on that list and you are not in its count either — nobody can work out that somebody is missing.',
                        'Wyłączone — nie ma cię na tej liście ani w jej liczniku; nikt nie wywnioskuje, że kogoś brakuje.'),
    'fav_none':       ('Nothing here yet. The star beside a search result puts it here.',
                       'Na razie pusto. Gwiazdka przy wyniku wyszukiwania dodaje torrent tutaj.'),
    'fav_count':      (':n of :max kept', ':n z :max'),
    'uploads_none':   ('You have not registered anything while signed in.',
                       'Nie zarejestrowałeś niczego będąc zalogowanym.'),
    'uploads_public': ('On my profile', 'Na moim profilu'),
})

# ── strings the browser scripts need ────────────────────────────────────────
add('js.fav', {
    'add':            ('Add to favourites', 'Dodaj do ulubionych'),
    'remove':         ('Remove from favourites', 'Usuń z ulubionych'),
    'added':          ('Added to favourites', 'Dodano do ulubionych'),
    'removed':        ('Removed from favourites', 'Usunięto z ulubionych'),
    'limit':          ('Your favourites list is full (:n). Remove something first.',
                       'Lista ulubionych jest pełna (:n). Najpierw coś usuń.'),
    'failed':         ('That did not go through.', 'Nie udało się.'),
    'gone':           ('No longer in the catalogue', 'Już nie ma tego w katalogu'),
    'gone_title':     ('The tracker has not seen this hash for a while. The magnet still works.',
                       'Tracker nie widział tego hasha od dłuższego czasu. Magnet nadal działa.'),
    'blocked':        ('Blocked on this tracker', 'Zablokowane na tym trackerze'),
    'who':            ('Who has this', 'Kto ma to u siebie'),
    'who_title':      ('People who have this in their favourites and let their name be shown',
                       'Osoby, które mają to w ulubionych i zgodziły się pokazywać nazwę'),
    'who_none':       ('Nobody who shows their name has this.',
                       'Nikt, kto pokazuje swoją nazwę, nie ma tego u siebie.'),
    'who_count':      (':n people', ':n osób'),
    'who_one':        ('1 person', '1 osoba'),
    'nothing':        ('Nothing here.', 'Nic tu nie ma.'),
    'load_failed':    ('Could not load the list.', 'Nie udało się wczytać listy.'),
    'public_on':      ('Shown on your profile', 'Pokazywane na twoim profilu'),
    'public_off':     ('Not on your profile', 'Nie na twoim profilu'),
    'status_live':    ('Served', 'Serwowane'),
    'status_waiting': ('Being checked', 'Sprawdzane'),
    'status_refused': ('Did not pass the check', 'Nie przeszło sprawdzenia'),
    'status_blocked': ('Blocked', 'Zablokowane'),
    'content_none':   ('No description', 'Bez opisu'),
    'content_pending': ('Description waiting for review', 'Opis czeka na przegląd'),
    'content_approved': ('Description reviewed and published', 'Opis przejrzany i opublikowany'),
    'content_rejected': ('Description turned down', 'Opis odrzucony'),
})

# ── the settings the operator sees ──────────────────────────────────────────
add('settings', {
    'fav_heading':    ('Favourites and profiles', 'Ulubione i profile'),
    'fav_intro':      ('A member can keep a list of favourite torrents, and — if you allow it — show that list on a public profile at <code>?action=u&amp;name=…</code>. Everything here ships off; a list of what somebody likes is a list about them.',
                       'Członek może prowadzić listę ulubionych torrentów i — jeśli na to pozwolisz — pokazywać ją na publicznym profilu pod <code>?action=u&amp;name=…</code>. Wszystko tutaj jest domyślnie wyłączone; lista tego, co ktoś lubi, jest listą o nim.'),
    'fav_enabled':    ('Favourites', 'Ulubione'),
    'fav_enabled_hint': ('The master switch. Off, the star does not appear and every endpoint answers as though the feature did not exist.',
                         'Główny włącznik. Wyłączone — gwiazdka się nie pojawia, a każdy endpoint odpowiada tak, jakby funkcji nie było.'),
    'fav_max':        ('Limit per account', 'Limit na konto'),
    'fav_max_hint':   ('What makes the query strategy safe: one person’s list is always small enough to fetch whole. 10–5000.',
                       'To, co czyni strategię zapytań bezpieczną: lista jednej osoby zawsze mieści się w całości. 10–5000.'),
    'fav_public':     ('Public lists', 'Publiczne listy'),
    'fav_public_hint': ('Whether a member may show their favourites on their profile at all. Each member still chooses for themselves, and the group needs <code>favourites.public</code>.',
                        'Czy członek w ogóle może pokazać ulubione na swoim profilu. Każdy i tak decyduje sam, a grupa potrzebuje <code>favourites.public</code>.'),
    'fav_who':        ('“Who has this in favourites”', '„Kto ma to w ulubionych”'),
    'fav_who_hint':   ('A list on the Info panel of people who favourited a torrent. Only those who allowed it appear — and they are left out of the count as well, so the number cannot be differenced to find them.',
                       'Lista w panelu Info z osobami, które dodały torrent do ulubionych. Pojawiają się tylko ci, którzy na to pozwolili — i są też pomijani w liczniku, żeby nie dało się ich wyliczyć z różnicy.'),
    'profiles':       ('Public profiles', 'Publiczne profile'),
    'profiles_hint':  ('Whether <code>?action=u&amp;name=…</code> is reachable. Its own switch, because registered torrents use it too. Only signed-in readers ever see a profile.',
                       'Czy <code>?action=u&amp;name=…</code> jest osiągalne. Osobny włącznik, bo korzystają z niego też zarejestrowane torrenty. Profil widzi wyłącznie zalogowany czytelnik.'),
    'wl_submitter':   ('Show who registered a torrent', 'Pokazuj, kto zarejestrował torrent'),
    'wl_submitter_hint': ('Lets a member show their registered torrents on their profile, and pick per torrent. It decides whose profile a row appears on — never what the tracker serves and never what the search finds.',
                          'Pozwala członkowi pokazywać zarejestrowane torrenty na profilu i wybierać osobno dla każdego. Decyduje o tym, na czyim profilu wiersz się pojawia — nigdy o tym, co serwuje tracker ani co znajduje wyszukiwarka.'),
})

# ── the submission form ────────────────────────────────────────────────────
add('whitelist', {
    'public_label': ('Show this on my profile',
                     'Pokaż to na moim profilu'),
    'public_hint':  ('Your profile is at <code>?action=u&amp;name=:name</code>. Applies to torrents this submission registers — one somebody else registered first stays theirs.',
                     'Twój profil jest pod <code>?action=u&amp;name=:name</code>. Dotyczy torrentów, które to zgłoszenie rejestruje — te zarejestrowane wcześniej przez kogoś innego pozostają jego.'),
})

add('js.app', {
    'wl_exists_not_yours': ('already registered — those will not appear on your profile',
                            'już zarejestrowane — te nie pojawią się na twoim profilu'),
})

# ── the page title ──────────────────────────────────────────────────────────
add('title', {
    'u': ('Profile', 'Profil'),
})
