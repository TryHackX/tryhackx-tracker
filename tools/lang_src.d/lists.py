# -*- coding: utf-8 -*-
"""Lists: collections somebody makes on purpose

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


# ── recovered 2026-09-13 ────────────────────────────────────────────────────
# These strings were added straight to lang/en.php and lang/pl.php between 1.43 and 1.50 and never to
# these sources, so the first regeneration since then (1.50.1) dropped all of them. They are written
# back here, where the generator reads from. tests/lang_test.php now checks that the generated files
# match the sources, so a string added to the generated file alone fails the battery on the spot.
add('', {
    'lists.find_items': ('Also search what is on them',
        'Szukaj też w zawartości'),
    'lists.find_items_title': ('Match the torrents on a list, not only the name of the list',
        'Dopasowuj torrenty na liście, nie tylko nazwę listy'),
    'lists.find_or_new_ph': ('Find a list, or name a new one…',
        'Znajdź listę albo nazwij nową…'),
    'lists.intro': ('A list is a collection you make on purpose — a pack of files, your own uploads gathered in one place, anything you want to keep together or hand to somebody. Add torrents from the star menu on a search result, or paste an info hash or magnet link straight into a list.',
        'Lista to zbiór, który tworzysz świadomie — paczka plików, własne wrzutki zebrane w jednym miejscu, cokolwiek chcesz trzymać razem albo komuś podać. Dodawaj torrenty z panelu przy wyniku wyszukiwania albo wklej info hash lub link magnet prosto do listy.'),
    'lists.new': ('New list',
        'Nowa lista'),
    'lists.new_ph': ('Name this list…',
        'Nazwij tę listę…'),
    'lists.pick_title': ('Put this in a list',
        'Dodaj to do listy'),
    'lists.search_ph': ('Filter lists by name…',
        'Filtruj listy po nazwie…'),
})
