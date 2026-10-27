# -*- coding: utf-8 -*-
"""Tags people add by hand, their permissions, the Tags pages (1.85.0)

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


add('a.head.nav', {
    'admin_tags': ('Tags', 'Tagi'),
})

add('perm', {
    'tags.add': ('Add tags to a torrent, and take back the ones they added',
                 'Dodawanie tagów do torrentu i zdejmowanie tych, które się dodało'),
    'tags.create': ('Create a new tag (a moderator approves it unless Settings says otherwise)',
                    'Tworzenie nowego tagu (zatwierdza go moderator, chyba że Ustawienia mówią inaczej)'),
    'tags.moderate': ("Remove anybody's tag or a wrong automatic one, and approve or reject new tags",
                      'Usuwanie czyjegokolwiek tagu lub błędnego tagu automatycznego oraz zatwierdzanie i odrzucanie nowych tagów'),
})

add('perm.panel', {
    'tags.view': ('PANEL — see the Tags page and approve or reject new tags',
                  'PANEL — podgląd strony Tagi oraz zatwierdzanie i odrzucanie nowych tagów'),
    'tags.manage': ('PANEL — rename, recolour, hide, merge and delete tags',
                    'PANEL — zmiana nazwy i koloru, ukrywanie, scalanie i usuwanie tagów'),
})

add('a.tags', {
    'title': ('Tags', 'Tagi'),
    'subtitle': ('the ones people add — the automatic ones come from names and descriptions', 'te dodawane ręcznie — automatyczne biorą się z nazw i opisów'),
    'f_q': ('Find a tag', 'Szukaj tagu'),
    'f_status': ('State', 'Stan'),
    'f_all': ('Every state', 'Każdy stan'),
    'f_pending': ('Waiting for approval', 'Czekające na zatwierdzenie'),
    'f_approved': ('Approved', 'Zatwierdzone'),
    'f_rejected': ('Rejected', 'Odrzucone'),
    'f_hidden': ('Hidden', 'Ukryte'),
    'f_sort': ('Order', 'Kolejność'),
    'f_sort_uses': ('Most used first', 'Najczęściej używane'),
    'f_sort_name': ('By name', 'Według nazwy'),
    'f_sort_new': ('Newest first', 'Najnowsze'),
    'pending_title': ('New tags waiting for a moderator', 'Nowe tagi czekające na moderatora'),
    'intro': ('A new tag from a member waits here until approved; until then only its author and the moderators see it. Rejecting takes it off every torrent. Merging moves its torrents to the other tag.',
              'Nowy tag od członka czeka tu na zatwierdzenie; do tego czasu widzą go tylko autor i moderatorzy. Odrzucenie zdejmuje go ze wszystkich torrentów. Scalenie przenosi jego torrenty do drugiego tagu.'),
    'col_tag': ('Tag', 'Tag'),
    'col_status': ('State', 'Stan'),
    'col_uses': ('Torrents', 'Torrenty'),
    'col_created': ('Created', 'Utworzony'),
    'col_actions': ('Actions', 'Akcje'),
})

add('js.tags', {
    'failed': ('That did not work — try again.', 'Nie udało się — spróbuj jeszcze raz.'),
    'n_tags': (':n tags', 'tagów: :n'),
    'n_pending': (':n waiting', 'czeka: :n'),
    'done': ('Done.', 'Gotowe.'),
    'name': ('Name', 'Nazwa'),
    'color': ('Colour', 'Kolor'),
    'no_color': ('no colour of its own', 'bez własnego koloru'),
    'description': ('What the tag means (optional)', 'Co znaczy tag (opcjonalnie)'),
    'save': ('Save', 'Zapisz'),
    'cancel': ('Cancel', 'Anuluj'),
    'merge': ('Merge into another tag', 'Scal z innym tagiem'),
    'merge_title': ('Merge ":name"', 'Scal „:name”'),
    'merge_label': ('Into which tag (its name or address)', 'Z którym tagiem (nazwa albo adres)'),
    'merge_ph': ('e.g. anime', 'np. anime'),
    'merge_none': ('No such tag.', 'Nie ma takiego tagu.'),
    'merge_confirm': ('Every torrent tagged ":from" gets ":into" instead, and ":from" is deleted.', 'Każdy torrent z tagiem „:from” dostanie „:into”, a „:from” zniknie.'),
    'empty': ('No tags here.', 'Brak tagów.'),
    'st_approved': ('approved', 'zatwierdzony'),
    'st_pending': ('waiting', 'czeka'),
    'st_rejected': ('rejected', 'odrzucony'),
    'st_hidden': ('hidden', 'ukryty'),
    'by_member': ('a member', 'członek'),
    'by_moderator': ('a moderator', 'moderator'),
    'by_forum': ('the forum', 'forum'),
    'approve': ('Approve', 'Zatwierdź'),
    'reject': ('Reject', 'Odrzuć'),
    'reject_confirm': ('Reject ":name"? It comes off :n torrents.', 'Odrzucić „:name”? Zniknie z torrentów: :n.'),
    'edit': ('Edit', 'Edytuj'),
    'hide': ('Hide from lists and filters', 'Ukryj na listach i w filtrach'),
    'unhide': ('Show again', 'Pokaż znowu'),
    'delete': ('Delete', 'Usuń'),
    'delete_confirm': ('Delete ":name"? It comes off :n torrents.', 'Usunąć „:name”? Zniknie z torrentów: :n.'),
    'pending_title': ('Waiting for a moderator — only you and the moderators see it', 'Czeka na moderatora — widzisz go tylko Ty i moderatorzy'),
    'remove': ('Remove the tag :name', 'Usuń tag :name'),
    'add_ph': ('Find a tag to add…', 'Znajdź tag do dodania…'),
    'add_or_create_ph': ('Find a tag or type a new one…', 'Znajdź tag albo wpisz nowy…'),
    'add_label': ('Add a tag', 'Dodaj tag'),
    'create': ('Create the tag ":name"', 'Utwórz tag „:name”'),
    'edit_tags': ('Edit tags', 'Edytuj tagi'),
    'done_editing': ('Done', 'Gotowe'),
})

add('js.filters', {
    'all_tags': ('All tags →', 'Wszystkie tagi →'),
    'g_tags': ('Tags', 'Tagi'),
    't_x': ('Tags from people', 'Tagi od ludzi'),
})

add('title', {
    'tags': ('Tags', 'Tagi'),
})

add('tags', {
    'h1': ('Tags', 'Tagi'),
    'intro': ('Every tag on the tracker: the ones people add, and the automatic ones read from names and descriptions — with how many torrents have each. Click one to see them.',
              'Wszystkie tagi na trackerze: dodane przez ludzi i automatyczne, z nazw i opisów — z liczbą torrentów przy każdym. Kliknij, żeby je zobaczyć.'),
    'search_ph': ('Find a tag (h264, lektor, anime…)', 'Szukaj tagu (h264, lektor, anime…)'),
    'search_label': ('Find a tag', 'Szukaj tagu'),
})

add('api.tags', {
    'forbidden': ('You may not change the tags here.', 'Nie możesz tu zmieniać tagów.'),
    'rate': ('Too many tag changes for now — try again later.', 'Za dużo zmian tagów naraz — spróbuj później.'),
    'not_found': ('No such torrent.', 'Nie ma takiego torrentu.'),
    'name': ('A tag needs a name of 1 to 40 letters or digits.', 'Tag potrzebuje nazwy z liter lub cyfr (do 40 znaków).'),
    'rejected': ('That tag was rejected by a moderator.', 'Ten tag odrzucił moderator.'),
    'limit': ('You have created as many tags as you may today.', 'Utworzono już dziś tyle tagów, ile wolno.'),
    'unavailable': ('Tags are not available right now.', 'Tagi są teraz niedostępne.'),
    'unknown': ('No such tag.', 'Nie ma takiego tagu.'),
    'blocked': ('A moderator took this tag off this torrent.', 'Moderator zdjął ten tag z tego torrentu.'),
    'too_many': ('This torrent has as many tags as it may.', 'Ten torrent ma już tyle tagów, ile wolno.'),
    'target': ('Choose another tag to merge into.', 'Wybierz inny tag do scalenia.'),
    'invalid': ('That is not a tag.', 'To nie jest tag.'),
    'pending': ('Added. A moderator approves the new tag before others see it.', 'Dodano. Nowy tag zobaczą inni po zatwierdzeniu przez moderatora.'),
    'added': ('Tag added.', 'Tag dodany.'),
    'removed': ('Tag removed.', 'Tag usunięty.'),
    'blocked_done': ('The automatic tag is off this torrent and stays off.', 'Tag automatyczny zdjęty z tego torrentu i nie wróci.'),
})

add('settings', {
    'tags_enabled': ('Tags added by people', 'Tagi dodawane przez ludzi'),
    'tags_enabled_hint': ('Who may add, create and moderate them is in the groups (tags.add, tags.create, tags.moderate).',
                          'Kto może je dodawać, tworzyć i moderować — w grupach (tags.add, tags.create, tags.moderate).'),
    'tags_review': ('A new tag from a member', 'Nowy tag od członka'),
    'tags_review_approve': ('waits for a moderator', 'czeka na moderatora'),
    'tags_review_open': ('is visible at once', 'jest widoczny od razu'),
    'tags_review_hint': ('A moderator\'s own new tags never wait.', 'Nowe tagi moderatora nigdy nie czekają.'),
    'tags_max_per_torrent': ('Tags per torrent, at most', 'Tagów na torrent, najwyżej'),
    'tags_max_per_torrent_hint': ('Added by hand (1–100); the automatic ones do not count.', 'Dodanych ręcznie (1–100); automatyczne się nie liczą.'),
    'tags_name_max': ('A tag\'s name, at most (characters)', 'Nazwa tagu, najwyżej (znaków)'),
    'tags_name_max_hint': ('2–40.', '2–40.'),
    'tags_create_per_day': ('New tags a member may create a day', 'Nowych tagów na dzień od członka'),
    'tags_create_per_day_hint': ('0 = none (they can still add existing ones); moderators have no limit.',
                                 '0 = żadnych (nadal mogą dodawać istniejące); moderatorzy nie mają limitu.'),
})
