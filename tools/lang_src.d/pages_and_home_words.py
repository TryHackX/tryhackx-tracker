# -*- coding: utf-8 -*-
"""The site's pages and the home page's sections, by name (1.73.0, part F)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

Words the server wrote in English on every page, around the pages an operator can rewrite:
  * Settings -> Site pages and the page editor named the two written pages "Terms of Service" and
    "Tracker Information" (includes/pagecontent.php pageContentCatalog()): now each page's own heading,
    tos.h1 / info.h1, in the reader's language -- and a home section "Home page -- <section>" below;
  * Settings -> Home page layout and the page editor named every built-in section and said what it is
    (includes/homelayout.php homeSectionCatalog(): label / about) -- a.home.sec_name_* / sec_line_*,
    whose English is the catalogue's own (tests/homelayout_test.php holds them together);
  * the page editor's placeholder buttons said what each {{placeholder}} is (includes/homeblocks.php
    homePlaceholderList()) -- a.home.ph_*; the names in braces are what an operator types, never words;
  * the home page's live-sync beacon (the pulsing dot beside the statistics) had an English title
    (includes/homeblocks.php) -- home.beacon_*, the same words app.js writes when it takes the title over
    (js.app.live_syncing / js.app.syncing_swarms), so the tooltip does not change language when it does.
The audit log keeps English (a log line is written once, whoever reads it later).

Polish: the section names are the headings the home page itself prints where it has them (O trackerze,
Cechy, Wesprzyj projekt, Kontakt, Adres announce).
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the page editor's title for a home section (pageContentLabel()) ────────────────────────────────────────────
add('a.pages', {
    'home_section': ('Home page — :section', 'Strona główna — :section'),
})

# ── the home page's sections: what Settings -> Home page layout calls them, and what each one is ───────────────
add('a.home', {
    'sec_name_header':    ('Title and tagline', 'Tytuł i hasło'),
    'sec_line_header':    ('The site name as an <h1>, and the line under it.', 'Nazwa strony jako <h1> i wiersz pod nią.'),
    'sec_name_stats':     ('Live tracker statistics', 'Statystyki trackera na żywo'),
    'sec_line_stats':     ('Torrents, seeds, peers, leechers, completed and uptime, refreshed in place.',
                           'Torrenty, seedy, peery, leecherzy, ukończone i czas działania, odświeżane na miejscu.'),
    'sec_name_shoutbox':  ('Shoutbox', 'Shoutbox'),
    'sec_line_shoutbox':  ('The last lines people said, and the box to say another one in.',
                           'Ostatnie wpisy i pole do napisania kolejnego.'),
    'sec_name_announce':  ('Announce URL', 'Adres announce'),
    'sec_line_announce':  ('The URLs a client needs, with the copy button and the extra-ports note.',
                           'Adresy potrzebne klientowi, z przyciskiem kopiowania i uwagą o dodatkowych portach.'),
    'sec_name_about':     ('About the tracker', 'O trackerze'),
    'sec_line_about':     ('The paragraph that rewrites itself for whitelist, scheduled or open mode.',
                           'Akapit, który sam się przepisuje dla trybu whitelisty, harmonogramu lub otwartego.'),
    'sec_name_features':  ('Features', 'Cechy'),
    'sec_line_features':  ('The bullet list, which also changes with the tracker mode.',
                           'Lista punktów, która też zmienia się wraz z trybem trackera.'),
    'sec_name_donations': ('Support the project', 'Wesprzyj projekt'),
    'sec_line_donations': ('Wallets and links from the donation fields.', 'Portfele i linki z pól darowizn.'),
    'sec_name_contact':   ('Contact', 'Kontakt'),
    'sec_line_contact':   ('The report call-to-action and the contact address.', 'Zachęta do zgłoszeń i adres kontaktowy.'),
})

# ── the page editor's placeholders (homePlaceholderList()): what each {{name}} puts on the page ────────────────
add('a.home', {
    'ph_block':           ('The built-in ":section" section', 'Wbudowana sekcja „:section”'),
    'ph_site_name':       ('The site name', 'Nazwa strony'),
    'ph_site_url':        ('The site URL', 'Adres strony'),
    'ph_announce_http':   ('The HTTP(S) announce URL', 'Adres announce HTTP(S)'),
    'ph_announce_udp':    ('The UDP announce URL', 'Adres announce UDP'),
    'ph_torrent_count':   ('Torrents tracked right now', 'Torrenty śledzone w tej chwili'),
    'ph_peer_count':      ('Peers right now', 'Peery w tej chwili'),
    'ph_seed_count':      ('Seeds right now', 'Seedy w tej chwili'),
    'ph_whitelist_count': ('Registered torrents', 'Zarejestrowane torrenty'),
    'ph_year':            ('The current year', 'Bieżący rok'),
    'ph_register_button': ('The register button (only while registration is open)',
                           'Przycisk rejestracji (tylko gdy rejestracja jest otwarta)'),
    'ph_report_link':     ('A link to the report form', 'Link do formularza zgłoszeń'),
    'ph_contact_email':   ('The site email, as a link', 'E-mail strony jako link'),
})

# ── the home page's live-sync beacon (includes/homeblocks.php) ─────────────────────────────────────────────────
add('home', {
    'beacon_live':    ('Live Syncing', 'Synchronizacja na żywo'),
    'beacon_syncing': ('Syncing Swarms...', 'Synchronizacja rojów…'),
})
