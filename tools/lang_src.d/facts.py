# -*- coding: utf-8 -*-
"""Facts read from descriptions, their tags and chips, and the search filters (1.84.0)

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


add('facts.tag', {
    'l_lektor_pl': ('PL voice-over', 'Lektor PL'),
    'l_dub_pl': ('PL dub', 'Dubbing PL'),
    'l_sub_pl': ('PL subtitles', 'Napisy PL'),
    'l_audio_pl': ('PL audio', 'Audio PL'),
    'l_audio_en': ('EN audio', 'Audio EN'),
    'l_sub_en': ('EN subtitles', 'Napisy EN'),
    't_movie': ('Movie', 'Film'),
    't_series': ('Series', 'Serial'),
})

add('settings', {
    'facts_source': ('Tags and filters from', 'Tagi i filtry z'),
    'facts_source_desc': ('Descriptions only', 'Tylko z opisów'),
    'facts_source_desc_name': ('Descriptions and torrent names', 'Z opisów i nazw torrentów'),
    'facts_source_hint': ('With names, a video torrent without a description gets its chips from tokens in its name (1080p, x265, WEB-DL, Lektor…) and the filters cover the whole catalogue. A name can be wrong; a description wins where there is one.',
                          'Z nazwami torrent wideo bez opisu dostaje chipy z tokenów w nazwie (1080p, x265, WEB-DL, Lektor…), a filtry obejmują cały katalog. Nazwa może kłamać; tam, gdzie jest opis, wygrywa opis.'),
    'facts_source_reading': ('The names are being read in the background — the filters reach the whole catalogue within minutes.',
                             'Nazwy są wczytywane w tle — filtry obejmą cały katalog w ciągu kilku minut.'),
})

add('js.filters', {
    'clear': ('Clear', 'Wyczyść'),
    'type': ('Kind', 'Rodzaj'),
    'all': ('All', 'Wszystko'),
    'movies': ('Movies', 'Filmy'),
    'series': ('Series', 'Seriale'),
    'more': ('More filters (:n)', 'Więcej filtrów (:n)'),
    'g_release': ('Release', 'Wydanie'),
    'g_quality': ('Quality', 'Jakość'),
    'g_language': ('Language', 'Język'),
    'g_ratings': ('Ratings', 'Oceny'),
    'g_popularity': ('Popularity', 'Popularność'),
    'g_description': ('Description', 'Opis'),
    'size': ('Size', 'Rozmiar'),
    'files': ('Files', 'Pliki'),
    'year': ('Year', 'Rok'),
    'seeders': ('Seeders, at least', 'Seedy, co najmniej'),
    'imdb': ('IMDb, at least', 'IMDb, co najmniej'),
    'filmweb': ('Filmweb, at least', 'Filmweb, co najmniej'),
    'min': ('from', 'od'),
    'max': ('to', 'do'),
    'size_hint': ('For example 4GB or 700MB; a number alone is GB.', 'Na przykład 4GB albo 700MB; sama liczba to GB.'),
    't_q': ('Resolution', 'Rozdzielczość'),
    't_v': ('Video', 'Wideo'),
    't_h': ('HDR', 'HDR'),
    't_a': ('Audio', 'Dźwięk'),
    't_s': ('Source', 'Źródło'),
    't_l': ('Language', 'Język'),
    'pics': ('With pictures', 'Z obrazami'),
    'remove': ('Remove the filter: :name', 'Usuń filtr: :name'),
    'add': ('Show only: :name', 'Pokaż tylko: :name'),
    'show_more': ('Show :n more', 'Pokaż jeszcze :n'),
    'bitrate': ('Video bit rate', 'Bitrate wideo'),
    'input_label': (':name, :end', ':name, :end'),
    'chip_range': (':name :a–:b', ':name :a–:b'),
    'chip_min': (':name ≥ :a', ':name ≥ :a'),
    'chip_max': (':name ≤ :b', ':name ≤ :b'),
    'c_size': ('Size', 'Rozmiar'),
    'c_files': ('Files', 'Pliki'),
    'c_year': ('Year', 'Rok'),
    'c_seeders': ('Seeders', 'Seedy'),
    'c_imdb': ('IMDb', 'IMDb'),
    'c_filmweb': ('Filmweb', 'Filmweb'),
})
