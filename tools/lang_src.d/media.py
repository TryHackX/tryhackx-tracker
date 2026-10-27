S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('settings', {
    'rt_youtube': ('YouTube links', 'Linki YouTube'),
    'rt_youtube_link': ('A link, as before', 'Link, jak dotąd'),
    'rt_youtube_click': ('A player that loads when clicked, no thumbnail', 'Player wczytywany po kliknięciu, bez miniatury'),
    'rt_youtube_thumb': ('A thumbnail, and a player that loads when clicked', 'Miniatura i player wczytywany po kliknięciu'),
    'rt_youtube_hint': ("Torrent descriptions, pages and private messages. Nothing comes from YouTube before the play button is pressed, "
                        "except the thumbnail of the last option, which comes from YouTube's image server. The player is YouTube's "
                        "privacy-enhanced one (youtube-nocookie.com). Everywhere else, and in mail, the link stays a link.",
                        'Opisy torrentów, strony i wiadomości prywatne. Przed kliknięciem odtwarzania nic nie jest pobierane z YouTube, '
                        'poza miniaturą w ostatniej opcji, która pochodzi z serwera obrazów YouTube. Player to wersja YouTube '
                        'o podwyższonej prywatności (youtube-nocookie.com). Wszędzie indziej, także w mailach, link zostaje linkiem.'),
})

add('js.video', {
    'play': ('Play the video', 'Odtwórz film'),
    'title': ('YouTube video', 'Film z YouTube'),
})

add('settings', {
    'images_title': ('Pictures', 'Obrazy'),
    'images_subtitle': ("Pictures members upload for their descriptions. They are kept in the database, turned the right way up, "
                        "stripped of their metadata (a phone's GPS position with it) and saved as WebP: about 960 pixels for the page "
                        "and full size for the viewer. An animated GIF stays animated while it fits. Until a description using a "
                        "picture is published, only its uploader and the moderators see it.",
                        'Obrazy, które członkowie wgrywają do swoich opisów. Są przechowywane w bazie, obracane we właściwą stronę, '
                        'pozbawiane metadanych (razem z pozycją GPS z telefonu) i zapisywane jako WebP: około 960 pikseli na stronę '
                        'i w pełnym rozmiarze do podglądu. Animowany GIF zostaje animowany, jeśli się mieści. Dopóki opis z obrazem '
                        'nie zostanie opublikowany, widzi go tylko osoba, która go wgrała, i moderatorzy.'),
    'images_max_mb': ('Largest file, MB', 'Największy plik, MB'),
    'images_max_mb_hint': ("PHP's upload limits on this server cap it as well.", 'Ogranicza go też limit wgrywania PHP na tym serwerze.'),
    'images_max_edge': ('Longest edge kept, px', 'Najdłuższa zachowana krawędź, px'),
    'images_max_edge_hint': ('A larger picture is scaled down to this.', 'Większy obraz jest do tego zmniejszany.'),
    'images_max_mpx': ('Most megapixels', 'Najwięcej megapikseli'),
    'images_max_mpx_hint': ('A larger picture is refused before it is opened.', 'Większy obraz jest odrzucany, zanim zostanie otwarty.'),
    'images_webp_quality': ('WebP quality', 'Jakość WebP'),
    'images_webp_quality_hint': ('50-95. 82 keeps photos sharp at a fraction of their size.', '50-95. 82 zachowuje ostrość zdjęć przy ułamku ich rozmiaru.'),
    'images_per_day': ('Uploads per account in 24 hours', 'Wgrań na konto w ciągu 24 godzin'),
    'images_per_day_hint': ('0 = no limit.', '0 = bez limitu.'),
    'images_per_day_mb': ('MB uploaded per account in 24 hours', 'MB wgranych na konto w ciągu 24 godzin'),
    'images_per_day_mb_hint': ('0 = no limit. Counts the files as they were sent, before they are made smaller.',
                               '0 = bez limitu. Liczy pliki tak, jak zostały wysłane, zanim zostaną zmniejszone.'),
    'images_quota_mb': ('Space per account, MB', 'Miejsce na konto, MB'),
    'images_quota_mb_hint': ('0 = no limit.', '0 = bez limitu.'),
    'images_unused_hours': ('Unused pictures removed after, hours', 'Nieużyte obrazy usuwane po, godzin'),
    'images_unused_hours_hint': ('A picture no description, proposal, page or comment refers to.', 'Obraz, do którego nie odwołuje się żaden opis, propozycja, strona ani komentarz.'),
})

add('api.images', {
    'not_allowed': ('Your account may not upload pictures.', 'Twoje konto nie może wgrywać obrazów.'),
    'daily_limit': ('You have uploaded as many pictures in the last 24 hours as this site takes (:n). Try again later.',
                    'W ciągu ostatnich 24 godzin wgrano już tyle obrazów, ile ta strona przyjmuje (:n). Spróbuj później.'),
    'recheck_queued': ('All :n copies will be compared with the partner\'s pictures over the next minutes.',
                       'Wszystkie kopie (:n) zostaną porównane z obrazami partnera w ciągu kilku minut.'),
    'daily_mb': ('This picture would take your uploads of the last 24 hours past :mb MB, as much as this site takes. Try again later.',
                 'Ten obraz przekroczyłby :mb MB wgranych w ciągu ostatnich 24 godzin — tyle ta strona przyjmuje. Spróbuj później.'),
    'quota': ('Your pictures already take :used MB of the :max MB your account may keep. Delete the ones you no longer need.',
              'Twoje obrazy zajmują już :used MB z :max MB, które może mieć Twoje konto. Usuń te, których już nie potrzebujesz.'),
    'in_use': ('That picture is used in a description, a proposal, a page or a comment, so it stays here. Delete it on the Images tab of your account to have it taken out of them.',
               'Ten obraz jest użyty w opisie, propozycji, na stronie albo w komentarzu, więc tu zostaje. Usuń go na karcie Obrazy w swoim koncie, aby został z nich usunięty.'),
    'not_found': ('There is no such picture among yours.', 'Nie ma takiego obrazu wśród Twoich.'),
    'unknown_op': ('Unknown operation.', 'Nieznana operacja.'),
})
