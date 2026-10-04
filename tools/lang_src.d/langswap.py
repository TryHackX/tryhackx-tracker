# -*- coding: utf-8 -*-
"""The live language switch (1.73.0, part B)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

A script's words follow the live switch only as whole t() words: a sentence glued together from a word and a
value ("Restart the tracker service" + " (opentracker)") keeps no key. These are such sentences said whole, the
value a placeholder.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the panel header's tracker buttons, with the service they act on ───────
add('js.reports', {
    # a report sent through the partner API: which partner, and what that means (two t() words in one title)
    'partner_title': (':partner — :why', ':partner — :why'),
    'restart_tracker_title_svc': ('Restart the tracker service (:service)',
        'Zrestartuj usługę trackera (:service)'),
    'reload_tracker_title_svc': ('Reload the tracker blacklist (SIGHUP, no downtime) — :service',
        'Przeładuj czarną listę trackera (SIGHUP, bez przestoju) — :service'),
})
# ── a sign-in bridge on the panel's Users table: the sentence and the account it names ──
add('js.users', {
    'bridge_title_full': ('Where this account can sign in from: :provider #:id',
        'Skąd to konto może się logować: :provider #:id'),
    # an inactive group grant on the Users table: its state ("expired", "starts …") and its term ("until …")
    'badge_title_pair': (':state · :term', ':state · :term'),
    # a system group's name on the Groups tab, with its " (system)" suffix (js.users.system_suffix)
    'system_title': (':name:suffix', ':name:suffix'),
    # an account's status on the Users table's badge (set in capitals by the badge's style) — it showed the stored
    # value, "active" / "banned", in English on every page
    'status_active': ('active', 'aktywne'),
    'status_banned': ('banned', 'zbanowane'),
})
# ── where a whitelisted torrent came from (whitelist.source: web | api | admin | forum): the source filter's options
#    and the table's badges said the stored value, in English on every page ──
SOURCES = {
    'src_web': ('Web', 'Strona'),
    'src_api': ('API', 'API'),
    'src_admin': ('Admin', 'Administrator'),
    'src_forum': ('Forum', 'Forum'),
}
add('a.wl', dict(SOURCES))
# ── the whitelist page's words that were the stored values: an added hash's outcome, where a ban came from ──
add('js.wl', dict(SOURCES))
add('js.wl', {
    'add_st_added': ('added', 'dodany'),
    'add_st_exists': ('exists', 'już jest'),
    'add_st_banned': ('banned', 'zbanowany'),
    'add_st_invalid': ('invalid', 'nieprawidłowy'),
    'ban_src_report': ('report', 'zgłoszenie'),
    'ban_src_appeal': ('appeal', 'odwołanie'),
    'ban_src_admin': ('admin', 'administrator'),
    'ban_src_import': ('import', 'import'),
})
# ── sentences said whole, each value a placeholder (a word glued to a value kept no key for the live switch) ──
add('js.backups', {
    # the backup profile select's option for the profile now configured (an <option> holds text only: one word)
    'profile_configured': (':label — configured', ':label — skonfigurowany'),
})
add('js.iplists', {
    # an IP list's counts in its title: ":n IPv4 addresses, :n IPv6 ranges" (two words of their own as the values)
    'title_pair': (':a, :b', ':a, :b'),
    # the file named in "replace the list from a file?" with the lines it skips, one word inside the question
    'file_lines_ignored': (':name (:n lines ignored)', ':name (:n linii pominięto)'),
})
add('js.pagecontent', {
    # a language button's title on the page-content editor: the language and the state of its version
    'lang_title': (':name — :state', ':name — :state'),
})
# ── the network limit card's last failure: "(5 min ago)" said whole, so it keeps its key inside the sentence ──
add('js.net', {
    'ago_paren': (' (:t ago)', ' (:t temu)'),
})
# ── Settings → Languages: a switch's name for a screen reader, its scope and the language ──
add('js.languages', {
    'scope_aria': (':scope — :name', ':scope — :name'),
})
# ── a guest's name in a comment, with the tag that tells two guests apart ──
add('js.comments', {
    'guest_named': ('Guest #:tag', 'Gość #:tag'),
})
# ── Settings → the metadata fetch order (includes/meta_order.php): its modes, its shares and the orderings left out,
#    English on every page ──
add('settings', {
    'meta_mode_oldest': ('Queue order — as added to pending (default)', 'Kolejność kolejki — jak dodano do oczekujących (domyślnie)'),
    'meta_mode_newest': ('Newest first', 'Najnowsze najpierw'),
    'meta_mode_seeders': ('Most seeders first', 'Najwięcej seederów najpierw'),
    'meta_mode_seen': ('Seen most often first', 'Najczęściej widziane najpierw'),
    'meta_mode_completed': ('Most completed downloads first', 'Najwięcej ukończonych pobrań najpierw'),
    'meta_mode_random': ('Random', 'Losowo'),
    'meta_mode_mix': ('Balanced mix (shares below)', 'Zrównoważona mieszanka (udziały poniżej)'),
    'meta_share_whitelist': ('Whitelist (registered)', 'Biała lista (zarejestrowane)'),
    'meta_share_seeders': ('Most seeders', 'Najwięcej seederów'),
    'meta_share_newest': ('Newest', 'Najnowsze'),
    'meta_share_seen': ('Seen most often', 'Najczęściej widziane'),
    'meta_share_completed': ('Most completed', 'Najwięcej ukończonych'),
    'meta_share_random': ('Random', 'Losowo'),
    'meta_share_oldest': ('Queue order', 'Kolejność kolejki'),
    'meta_rej_last_seen': ('Last seen', 'Ostatnio widziane'),
    'meta_rej_last_seen_why': ('every hash in a poll is stamped with the same time, so "most recently seen" sorts three '
                               'million rows that are all equal — it would order by nothing.',
                               'każdy hash z jednego odpytania dostaje ten sam czas, więc „ostatnio widziane” sortuje '
                               'trzy miliony równych wierszy — nie porządkowałoby niczego.'),
    'meta_rej_peak_seeders': ('Peak seeders', 'Szczyt seederów'),
    'meta_rej_peak_seeders_why': ('nearly the same ranking as "most seeders" on this data, for the cost of another index '
                                  'on a table that is rewritten on every poll.',
                                  'na tych danych prawie ta sama kolejność co „najwięcej seederów”, kosztem kolejnego '
                                  'indeksu na tabeli przepisywanej przy każdym odpytaniu.'),
    'meta_rej_name_size': ('Name / size / file count', 'Nazwa / rozmiar / liczba plików'),
    'meta_rej_name_size_why': ('not known until the metadata has been fetched, which is the thing being ordered. Sorting '
                               'the queue by the answer needs the answer.',
                               'nieznane, dopóki metadane nie zostaną pobrane — a to ich pobieranie jest kolejkowane. '
                               'Sortowanie kolejki według odpowiedzi wymaga odpowiedzi.'),
})
# ── a backup profile's name (includes/backup.php backupProfileLabel()): Settings' select and the backups page's ──
add('api.backup', {
    'profile_light': ('Light — config and lists, database without the two huge index tables',
                      'Lekka — konfiguracja i listy, baza bez dwóch ogromnych tabel indeksu'),
    'profile_everything': ('Everything — full database (several GB) plus config, lists and units',
                           'Wszystko — pełna baza (kilka GB) oraz konfiguracja, listy i jednostki usług'),
    'profile_db_full': ('Full database only — every table, including the index (several GB)',
                        'Tylko pełna baza — każda tabela, razem z indeksem (kilka GB)'),
    'profile_db_light': ('Light database only — without index_hashes and index_files',
                         'Tylko lekka baza — bez index_hashes i index_files'),
    'profile_custom': ('Custom selection', 'Własny wybór'),
})
# ── the sign-in form's "Stay signed in for" choices (includes/users.php userSessionChoices()): English on every page ──
add('login', {
    'session_forever': ('Forever (until you sign out)', 'Na zawsze (do wylogowania)'),
    'session_1h': ('1 hour', '1 godzina'),
    'session_1d': ('1 day', '1 dzień'),
    'session_30d': ('30 days', '30 dni'),
})
# ── reCAPTCHA v3's terms notice (includes/functions.php captchaNoticeHtml()): it was English on every page ──
add('captcha', {
    'v3_notice': ('This site is protected by reCAPTCHA and the Google :privacy and :terms apply.',
        'Ta strona jest chroniona przez reCAPTCHA; obowiązują :privacy i :terms Google.'),
    'v3_privacy': ('Privacy Policy', 'Polityka prywatności'),
    'v3_terms': ('Terms of Service', 'Warunki korzystania z usług'),
})
