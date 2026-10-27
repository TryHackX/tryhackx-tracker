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
    'facts_heading': ('Tags and filters', 'Tagi i filtry'),
    'facts_intro': ('Tags are read automatically: from every published description (from the forum or written here) and, if you choose, from torrent names. Here you decide where they show and what the filters offer.',
                    'Tagi powstają same: z każdego opublikowanego opisu (z forum albo napisanego tutaj) i, jeśli wybierzesz, z nazw torrentów. Tu decydujesz, gdzie je widać i co pokazują filtry.'),
    'facts_on': ('Show', 'Pokazuj'),
    'facts_off': ('Hide', 'Ukryj'),
    'facts_chips_search': ('Chips in the search results', 'Chipy w wynikach wyszukiwania'),
    'facts_chips_search_hint': ('Under each name in the search list.', 'Pod każdą nazwą na liście wyszukiwarki.'),
    'facts_chips_info': ('Chips in the Info panel', 'Chipy w oknie Info'),
    'facts_chips_info_hint': ('In the torrent\'s details window.', 'W oknie szczegółów torrenta.'),
    'facts_chips_panel': ('Chips in the panel\'s lists', 'Chipy na listach w panelu'),
    'facts_chips_panel_hint': ('Index and Whitelist in this panel.', 'Index i Whitelist w tym panelu.'),
    'facts_chips_max': ('Tags shown before "+N"', 'Tagi widoczne przed „+N”'),
    'facts_chips_max_hint': ('The rest wait behind "+N" until clicked (1–20).', 'Reszta czeka za „+N” do kliknięcia (1–20).'),
    'facts_chips_rates': ('Bit rate chips', 'Chipy bitrate'),
    'facts_chips_rates_hint': ('Overall, video and the best audio track: average, maximum where MediaInfo gives one, VBR or CBR.',
                               'Ogólny, wideo i najlepsza ścieżka audio: średni, maksymalny tam, gdzie MediaInfo go podaje, VBR albo CBR.'),
    'facts_chips_ratings': ('IMDb and Filmweb chips', 'Chipy IMDb i Filmweb'),
    'facts_chips_ratings_hint': ('The ratings as the description states them.', 'Oceny tak, jak podaje je opis.'),
    'facts_filter_counts': ('How many torrents each tag has, in the filters', 'Liczba torrentów przy tagach w filtrach'),
    'facts_filter_counts_hint': ('Counted every ten minutes; a tag nobody has is left out of the list.',
                                 'Liczone co dziesięć minut; tag, którego nikt nie ma, znika z listy.'),
})

add('api.index', {
    'filters_sort': ('The filters work with the sorts by last seen, size, files, name, seeders and leechers. Change the sort or clear the filters.',
                     'Filtry działają z sortowaniem po dacie, rozmiarze, plikach, nazwie, seedach i leechach. Zmień sortowanie albo wyczyść filtry.'),
    'filters_state': ('The filters cannot be combined with the metadata state (other than done) or the lifecycle filter. Clear one of them.',
                      'Filtrów nie da się łączyć z filtrem stanu metadanych (poza „gotowe”) ani cyklu życia. Wyczyść jeden z nich.'),
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
    'g_genre': ('Genre', 'Gatunek'),
    't_g': ('Genre', 'Gatunek'),
    'vbitrate': ('Video bit rate from (Mb/s)', 'Bitrate wideo od (Mb/s)'),
    'c_vbitrate': ('Video Mb/s', 'Wideo Mb/s'),
    'tag_search': ('Find a tag', 'Szukaj tagu'),
    'tag_search_ph': ('h264, lektor, 4k…', 'h264, lektor, 4k…'),
    'tag_none': ('No tag matches.', 'Żaden tag nie pasuje.'),
    'k_overall': ('Overall', 'Ogólny'),
    'k_video': ('Video', 'Wideo'),
    'k_audio': ('Audio', 'Audio'),
    'k_audio_x': ('Audio :codec', 'Audio :codec'),
    'chip_rate': (':kind :avg', ':kind :avg'),
    'chip_rate_mode': (':kind :avg · :mode', ':kind :avg · :mode'),
    'chip_rate_max': (':kind :avg (max :max)', ':kind :avg (maks. :max)'),
    'chip_rate_max_mode': (':kind :avg (max :max) · :mode', ':kind :avg (maks. :max) · :mode'),
    'chip_rate_only_max': (':kind max :max', ':kind maks. :max'),
    'rate_title_o': ('Bit rate of the whole file: the average, and the maximum where MediaInfo gives one', 'Bitrate całego pliku: średni, a maksymalny tam, gdzie MediaInfo go podaje'),
    'rate_title_v': ('Bit rate of the picture: the average, the maximum where given; VBR = variable, CBR = constant', 'Bitrate obrazu: średni, maksymalny tam, gdzie podany; VBR = zmienny, CBR = stały'),
    'rate_title_a': ('The best audio track: codec, channels and bit rate', 'Najlepsza ścieżka dźwięku: kodek, kanały i bitrate'),
    'count': (':n torrents', 'torrentów: :n'),
})


add('js.filters', {
    'duration': ('Running time (minutes)', 'Czas trwania (minuty)'),
    'c_duration': ('Minutes', 'Minuty'),
    'seasons': ('Seasons', 'Sezony'),
    'c_seasons': ('Seasons', 'Sezony'),
    'episodes': ('Episodes', 'Odcinki'),
    'c_episodes': ('Episodes', 'Odcinki'),
    'rating': ('Site rating from (%)', 'Ocena strony od (%)'),
    'c_rating': ('Rating %', 'Ocena %'),
    'votes': ('Votes, at least', 'Głosów, co najmniej'),
    'c_votes': ('Votes', 'Głosy'),
    'steam': ('SteamDB score from (%)', 'Wynik SteamDB od (%)'),
    'c_steam': ('SteamDB %', 'SteamDB %'),
    'seen': ('Seen between', 'Widziany między'),
    'c_seen': ('Seen', 'Widziany'),
    'g_dates': ('Dates', 'Daty'),
    't_mine': ('My tags', 'Moje tagi'),
    'tags_hint': ('Click a tag once for "with it", again for "without it", a third time to let it go.',
                  'Kliknij tag raz: „z tym tagiem”, drugi raz: „bez tego tagu”, trzeci raz: wyłącz.'),
    'chip_not': ('without :name', 'bez :name'),
    'tag_not_title': ('Without :name — click to let it go', 'Bez :name — kliknij, by wyłączyć'),
})


add('settings', {
    'thumbs_search': ('Covers in the search', 'Okładki w wyszukiwarce'),
    'thumbs_search_hint': ("A small cover beside each result with pictures in its description; it changes by itself while the row is in view.",
                           'Mała okładka przy każdym wyniku, który ma obrazy w opisie; zmienia się sama, gdy wiersz jest widoczny.'),
    'thumbs_lists': ('Covers on profile lists', 'Okładki na listach profilu'),
    'thumbs_lists_hint': ('Favourites, uploads and lists.', 'Ulubione, wysłane i listy.'),
    'thumbs_info': ('Pictures at the top of Info', 'Obrazy na górze okna Info'),
    'thumbs_info_hint': ("A strip of the description's pictures under the name, without opening the description; arrows or a swipe move it, a click opens the pictures.",
                         'Pasek obrazów z opisu pod nazwą, bez rozwijania opisu; strzałki albo przesunięcie palcem go przewijają, klik otwiera obrazy.'),
    'thumbs_width': ('Cover width (px)', 'Szerokość okładki (px)'),
    'thumbs_width_hint': ('40–160; on a phone always 56.', '40–160; na telefonie zawsze 56.'),
    'thumbs_speed': ('Seconds per picture', 'Sekundy na obraz'),
    'thumbs_speed_hint': ('2–15. Nothing moves for a reader who asked their system for less motion.', '2–15. U osoby, która wyłączyła animacje w systemie, nic się nie przesuwa.'),
    'thumbs_max': ('Pictures per torrent, at most', 'Obrazów na torrent, najwyżej'),
    'thumbs_max_hint': ('1–10; posters from the labels first.', '1–10; najpierw plakaty z etykiet.'),
})
