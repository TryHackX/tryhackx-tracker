S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('settings', {
    'rt_magnet_self_show': ('The torrent\'s own magnet in its description', 'Magnet tego samego torrentu w jego opisie'),
    'rt_magnet_self_hide': ('Hidden — Info has its own Magnet button', 'Ukryty — Info ma własny przycisk Magnet'),
    'rt_magnet_self_keep': ('Shown like any other magnet', 'Pokazany jak każdy inny magnet'),
    'rt_magnet_self_show_hint': ('A description often holds the magnet of its own torrent. Hidden, it shows only in the editor\'s preview.',
                                 'Opis często zawiera magnet własnego torrentu. Ukryty pokazuje się tylko w podglądzie edytora.'),
    'tags_prune_unused': ('Delete tags nobody uses', 'Usuwaj tagi, których nikt nie używa'),
    'tags_prune_unused_hint': ('A tag people made goes when no torrent has it any more. Base tags, tags in a category, rejected or hidden names and the forum\'s mapped tags stay.',
                               'Tag zrobiony przez ludzi znika, gdy nie ma go już żaden torrent. Zostają tagi bazowe, tagi w kategorii, odrzucone lub ukryte nazwy i tagi z mapy forum.'),
    'torrent_reports_enabled': ('Reporting a torrent from Info', 'Zgłaszanie torrentu z Info'),
    'torrent_reports_enabled_hint': ('Members get a flag in Info that sends a short report to Panel → Reports → Torrents. The Report page\'s form works either way.',
                                     'Członkowie dostają w Info flagę, która wysyła krótkie zgłoszenie do Panel → Zgłoszenia → Torrenty. Formularz ze strony Zgłoś działa niezależnie.'),
})

add('a.tags', {
    'edit_title': ('Edit the tag', 'Edytuj tag'),
    'edit_name': ('Name', 'Nazwa'),
    'edit_cat': ('Category', 'Kategoria'),
    'edit_color': ('Colour', 'Kolor'),
    'edit_desc': ('Description', 'Opis'),
    'edit_desc_ph': ('What the tag means (optional)', 'Co oznacza tag (opcjonalnie)'),
    'edit_cancel': ('Cancel', 'Anuluj'),
    'edit_save': ('Save', 'Zapisz'),
})

add('js.tags', {
    'g_title': ('Groups', 'Grupy'),
    'g_intro': ('A group gathers categories. A group with its own card shows in “More filters” as a card of its own; the categories of the others stay in the Tags card.',
                'Grupa zbiera kategorie. Grupa z własną kartą pokazuje się w „Więcej filtrów” jako osobna karta; kategorie pozostałych zostają w karcie Tagi.'),
    'g_new_ph': ('Name of a new group', 'Nazwa nowej grupy'),
    'g_pinned': ('Own card in the filters', 'Własna karta w filtrach'),
    'g_add': ('Add a group', 'Dodaj grupę'),
    'g_none': ('No groups yet.', 'Nie ma jeszcze grup.'),
    'g_rename': ('Rename the group', 'Zmień nazwę grupy'),
    'g_delete_confirm': ('Delete the group “:name”? Its categories (:n) stay, without a group.', 'Usunąć grupę „:name”? Jej kategorie (:n) zostaną, bez grupy.'),
    'g_col_group': ('Group', 'Grupa'),
    'g_col_pinned': ('Own card', 'Własna karta'),
    'g_col_n': ('Categories', 'Kategorie'),
    'g_no_group': ('No group', 'Bez grupy'),
    'k_list_title': ('Category list', 'Lista kategorii'),
})

add('perm.panel', {
    'reports.torrents.view': ('PANEL — see torrents reported from Info', 'PANEL — podgląd torrentów zgłoszonych z Info'),
    'reports.torrents.handle': ('PANEL — act on torrents reported from Info (close, ban the hash, warn the one who registered it)',
                                'PANEL — działania na torrentach zgłoszonych z Info (zamknięcie, blokada hasha, ostrzeżenie rejestrującego)'),
})

add('js.report', {
    'tip_torrent': ('Report this torrent to the moderators', 'Zgłoś ten torrent moderatorom'),
})

add('a.reports', {
    'tab_torrents': ('Torrents', 'Torrenty'),
})

add('js.creport', {
    'kind_torrent': ('Torrent', 'Torrent'),
    'head_torrent': ('The torrent “:name”, registered by :user', 'Torrent „:name”, zarejestrował: :user'),
    'head_torrent_index': ('The torrent “:name”, from the index (not registered here)', 'Torrent „:name” z indeksu (niezarejestrowany tutaj)'),
    'remove_torrent': ('Ban the hash', 'Zablokuj hash'),
    'remove_torrent_q': ('Ban this hash? The tracker stops serving it and it leaves the search.', 'Zablokować ten hash? Tracker przestanie go obsługiwać i zniknie z wyszukiwarki.'),
    'state_torrent_banned': ('banned', 'zablokowany'),
    'no_owner_torrent': ('Nobody registered this torrent here, so there is no account to act on.', 'Nikt nie zarejestrował tu tego torrentu, więc nie ma konta, wobec którego można działać.'),
})

add('notify', {
    'creport_removed_torrent': ('The torrent you reported, “:name”, was banned', 'Zgłoszony przez Ciebie torrent „:name” został zablokowany'),
    'creport_what_torrent': ('the torrent “:name”', 'torrentu „:name”'),
    'warn_removed_torrent': ('Warning: a torrent you registered was banned', 'Ostrzeżenie: zarejestrowany przez Ciebie torrent został zablokowany'),
    'warn_about_torrent': ('Warning about a torrent you registered', 'Ostrzeżenie w sprawie zarejestrowanego przez Ciebie torrentu'),
})
