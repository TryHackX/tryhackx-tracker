S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('settings', {
    'rows_open_info': ('A click on a row opens Info', 'Klik w wiersz otwiera Info'),
    'rows_open_info_hint': ('In the search results and on lists, favourites and likes: a click on an empty part of a row opens the torrent\'s Info. Clicks on the name, the tags and the buttons work as before.',
                            'W wynikach wyszukiwania oraz na listach, w ulubionych i w polubieniach: klik w puste miejsce wiersza otwiera Info torrenta. Klik w nazwę, tagi i przyciski działa jak wcześniej.'),
    'rep_chip': ('Rating beside the name', 'Ocena przy nazwie'),
    'rep_chip_hint': ('A small rating chip beside the files count in the search results, and beside the name on favourites and lists. Not drawn while the rating has its own column.',
                      'Mały znaczek oceny obok liczby plików w wynikach wyszukiwania oraz obok nazwy w ulubionych i na listach. Nie pokazuje się, gdy ocena ma własną kolumnę.'),
})

add('js.app', {
    'rep_thumbs_title_one': (':pct% positive, from 1 rating', ':pct% pozytywnych, z 1 oceny'),
    'rep_thumbs_title_many': (':pct% positive, from :n ratings', ':pct% pozytywnych, z :n ocen'),
})

add('js.filters', {
    'more_tags': ('More (:n)', 'Więcej (:n)'),
    'less_tags': ('Fewer', 'Mniej'),
})

add('js.tags', {
    'page_more': ('Show more (:n)', 'Pokaż więcej (:n)'),
    'page_less': ('Show fewer', 'Pokaż mniej'),
    'mine_quick': ('My tags:', 'Moje tagi:'),
    'mine_quick_title': ('Add “:name” — one of your tags', 'Dodaj „:name” — jeden z Twoich tagów'),
})

add('a.users', {
    'perm_filter_ph': ('Find a permission…', 'Szukaj uprawnienia…'),
})

add('a.tags', {
    'f_search_ph': ('Find a forum tag…', 'Szukaj tagu z forum…'),
    'f_refresh': ('Refresh', 'Odśwież'),
    'f_refresh_hint': ('The forum sends a topic\'s tags with its description, so a topic whose tags change on the forum is updated here by itself. Refresh matches the waiting tags again and puts the approved ones back on the torrents. After renaming or recolouring a tag on the forum, press "Send / refresh all topic descriptions" in the forum\'s settings.',
                       'Forum wysyła tagi tematu razem z opisem, więc temat, któremu zmienią się tagi na forum, aktualizuje się tutaj sam. Odśwież dopasowuje od nowa tagi czekające na decyzję i nakłada zatwierdzone na torrenty. Po zmianie nazwy lub koloru tagu na forum użyj w ustawieniach forum przycisku „Wyślij / odśwież opisy wszystkich tematów”.'),
})

add('js.tags', {
    'color_custom': ('Your own colour…', 'Własny kolor…'),
    'preview': ('Preview', 'Podgląd'),
    'f_none_match': ('No forum tag matches.', 'Żaden tag z forum nie pasuje.'),
    'f_refreshed': ('Refreshed: :n matches changed, :added tags put on, :removed taken off.', 'Odświeżono: zmienione dopasowania :n, nałożone tagi :added, zdjęte :removed.'),
})

add('settings', {
    'thumbs_panel': ('Covers in the panel', 'Okładki w panelu'),
    'thumbs_panel_hint': ('Covers beside the names on the panel\'s Whitelist and Index, and the description\'s pictures at the top of their details.',
                          'Okładki przy nazwach na Whitelist i Index w panelu oraz obrazy z opisu na górze ich szczegółów.'),
})
