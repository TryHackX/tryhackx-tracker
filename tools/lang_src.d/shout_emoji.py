# -*- coding: utf-8 -*-
"""The shoutbox's emoji picker (1.69.0): every emoji, its pages, a search, variants, Font Awesome's faces

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The emoji's own names and keywords are NOT here: they come with the data file the picker loads
(assets/emoji/emoji-<lang>.json, from Unicode's CLDR), and the Polish words for Font Awesome's faces
with assets/emoji/fa-faces.json. These are the page labels, the search box and what the settings say.

The page labels are Unicode's own group names, in the words the phones use ("Buźki i emocje"). "Buźka"
is what a Polish reader calls a smiley, and the Font Awesome faces are "buźki Font Awesome" for that
reason; the picker itself is "wybierak", the word the emotes' settings already use for it.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── Settings -> Shoutbox -> Emoji in the picker ──────────────────────────────
add('settings', {
    'shout_emoji_heading': ('Emoji in the picker', 'Emoji w wybieraku'),
    'shout_emoji_sub': (
        'Every emoji Unicode has, up to Emoji 16.0 — the newest set Windows 11, Android and iOS all draw — on '
        'Unicode\'s own pages, with a search in the reader\'s language (and, after what that finds, in English), '
        'the ones a reader used last on a page of their own, and the five skin tones on an emoji held down, as '
        'on a phone. They are ordinary characters drawn by the reader\'s own device, so there is nothing about '
        'them to switch; what can be chosen is Font Awesome — its faces, and how much more of the package the '
        'picker offers.',
        'Wszystkie emoji, jakie ma Unicode, do wersji Emoji 16.0 — najnowszej, którą rysują Windows 11, Android '
        'i iOS — na kartach samego Unicode, z wyszukiwarką w języku czytelnika (a po tym, co w nim znajdzie, '
        'także po angielsku), z ostatnio używanymi na osobnej karcie i z pięcioma odcieniami skóry po '
        'przytrzymaniu emoji, jak w telefonie. To zwykłe znaki rysowane przez urządzenie czytelnika, więc nie '
        'ma w nich czego przełączać; do wyboru jest Font Awesome — jego buźki i to, ile jeszcze z paczki '
        'pokazuje wybierak.'),
    'shout_emoji_fa': ('Font Awesome faces', 'Buźki Font Awesome'),
    'shout_emoji_fa_off': ('Off — the ordinary emoji', 'Wyłączone — zwykłe emoji'),
    'shout_emoji_fa_fa': ('Instead of the ordinary emoji', 'Zamiast zwykłych emoji'),
    'shout_emoji_fa_mixed': ('Mixed — both', 'Mieszane — jedne i drugie'),
    'shout_emoji_fa_hint': (
        'The faces of the Font Awesome Pro package in use (:n), on pages of their own in the picker; held down, '
        'a face offers every style it is drawn in among those the site loads. In a shout it is written '
        '<code>:fa-name:</code> and drawn as the face while this package and that style load — anywhere else '
        '(this switched off, another icon source, an e-mail) it is the ordinary emoji it stands for. Offered '
        'only while a Font Awesome Pro package is the site\'s icon source.',
        'Buźki paczki Font Awesome Pro, której używa strona (:n), na osobnych kartach wybieraka; przytrzymana '
        'buźka pokazuje każdy styl, w którym jest rysowana, spośród wczytywanych przez stronę. W wypowiedzi '
        'zapisuje się jako <code>:fa-nazwa:</code> i jest rysowana jako buźka, dopóki ta paczka i ten styl są '
        'wczytywane — gdzie indziej (po wyłączeniu tej opcji, przy innym źródle ikon, w e-mailu) jest zwykłym '
        'emoji, któremu odpowiada. Dostępne tylko wtedy, gdy źródłem ikon strony jest paczka Font Awesome Pro.'),
    'shout_emoji_fa_style': ('Style of the faces', 'Styl buziek'),
    'shout_emoji_fa_style_site': ('The site\'s own style (:style)', 'Styl strony (:style)'),
    'shout_emoji_fa_style_hint': (
        'What a click on a face puts in the box; the other loaded styles are one hold away. A face this style '
        'has no drawing of is drawn in the classic family at the same weight.',
        'To, co kliknięcie buźki wstawia do pola; pozostałe wczytane style są o jedno przytrzymanie dalej. '
        'Buźkę, której ten styl nie rysuje, rysuje rodzina klasyczna o tej samej grubości.'),
    'shout_emoji_fa_unavailable': (
        'Font Awesome\'s faces can be offered in the picker once a Font Awesome Pro package is the site\'s icon '
        'source (Settings → Site → Font Awesome, then save).',
        'Buźki Font Awesome można udostępnić w wybieraku, gdy źródłem ikon strony jest paczka Font Awesome Pro '
        '(Ustawienia → Strona → Font Awesome, potem zapisz).'),
    # 1.70.0: how much more of the package the picker offers (`shout_emoji_fa_scope`), shown only while the
    # faces are on. :n is how many icons the package's catalogue holds, :c its categories.
    'shout_emoji_fa_scope': ('Font Awesome in the picker', 'Font Awesome w wybieraku'),
    'shout_emoji_fa_scope_faces': ('The faces only', 'Tylko buźki'),
    'shout_emoji_fa_scope_search': ('The faces, and the search finds every icon', 'Buźki, a wyszukiwarka znajduje każdą ikonę'),
    'shout_emoji_fa_scope_all': ('Every icon, by category', 'Każda ikona, według kategorii'),
    'shout_emoji_fa_scope_hint': (
        'How much of the package the picker offers besides the faces: nothing more; any of its :n icons through '
        'the search box, in a “Font Awesome” group after the emoji; or every icon, on the pages of Font Awesome’s '
        ':c categories as well. Held down, every icon offers the styles it is drawn in. The search finds a face by '
        'its words in the reader’s language and any other icon by its English name and words — or by the name of '
        'its category, in either language. The picker fetches the list of icons only when this needs it, once per '
        'browser. This is how the picker offers icons, not what a message may show: an icon already posted is drawn '
        'whatever this says, and where Font Awesome cannot draw it, it is its name in brackets.',
        'Ile z paczki wybierak pokazuje poza buźkami: nic więcej; każdą z :n ikon przez wyszukiwarkę, w grupie '
        '„Font Awesome” po emoji; albo każdą ikonę także na kartach :c kategorii Font Awesome. Przytrzymana ikona '
        'pokazuje style, w których jest rysowana. Wyszukiwarka znajduje buźkę po słowach w języku czytelnika, a każdą '
        'inną ikonę po angielskiej nazwie i słowach — albo po nazwie jej kategorii, w obu językach. Wybierak pobiera '
        'listę ikon tylko wtedy, gdy to ustawienie jej wymaga, raz na przeglądarkę. Ono mówi, co wybierak proponuje, '
        'a nie co może pokazać wiadomość: wysłana już ikona jest rysowana niezależnie od niego, a tam, gdzie Font '
        'Awesome nie może jej narysować, jest jej nazwą w nawiasach.'),
    'shout_emoji_fa_scope_noindex': (
        'This package came without an index to read (Font Awesome’s metadata/ folder), so the picker can offer its '
        'faces only; the two wider choices need a package that has one.',
        'Ta paczka nie ma indeksu do odczytania (folderu metadata/ Font Awesome), więc wybierak może pokazać tylko jej '
        'buźki; dwie szersze opcje wymagają paczki z indeksem.'),
})

# ── Font Awesome's categories (1.70.0), named in both languages ───────────────
# The ids are the owner's indexes' (the same 68 in 6.7.2 and 7.3.1), with a hyphen written as `_` in the key;
# the words are this project's own. `brands` and `other` are the catalogue's two made-up pages: the brand
# logos, which Font Awesome files under no category, and whatever else has none. A category a later version
# adds is named by its id until it gets words here (emojiFaCategoryLabel()).
add('emoji.facat', {
    'accessibility': ('Accessibility', 'Dostępność'),
    'alert': ('Alerts', 'Alerty'),
    'alphabet': ('Alphabet', 'Alfabet'),
    'animals': ('Animals', 'Zwierzęta'),
    'arrows': ('Arrows', 'Strzałki'),
    'astronomy': ('Astronomy', 'Astronomia'),
    'automotive': ('Cars & vehicles', 'Motoryzacja'),
    'buildings': ('Buildings', 'Budynki'),
    'business': ('Business', 'Biznes'),
    'camping': ('Camping', 'Biwakowanie'),
    'charity': ('Charity', 'Dobroczynność'),
    'charts_diagrams': ('Charts & diagrams', 'Wykresy i diagramy'),
    'childhood': ('Childhood', 'Dzieciństwo'),
    'clothing_fashion': ('Clothing & fashion', 'Ubrania i moda'),
    'coding': ('Coding', 'Programowanie'),
    'communication': ('Communication', 'Komunikacja'),
    'connectivity': ('Connectivity', 'Łączność'),
    'construction': ('Construction', 'Budowa'),
    'design': ('Design', 'Projektowanie'),
    'devices_hardware': ('Devices & hardware', 'Urządzenia i sprzęt'),
    'disaster': ('Disasters & crises', 'Katastrofy i kryzysy'),
    'editing': ('Editing', 'Edycja'),
    'education': ('Education', 'Edukacja'),
    'emoji': ('Emoji', 'Emoji'),
    'energy': ('Energy', 'Energia'),
    'files': ('Files', 'Pliki'),
    'film_video': ('Film & video', 'Film i wideo'),
    'food_beverage': ('Food & drink', 'Jedzenie i napoje'),
    'fruits_vegetables': ('Fruit & vegetables', 'Owoce i warzywa'),
    'gaming': ('Gaming', 'Gry'),
    'gender': ('Gender', 'Płeć'),
    'halloween': ('Halloween', 'Halloween'),
    'hands': ('Hands', 'Dłonie'),
    'holidays': ('Holidays', 'Święta'),
    'household': ('Household', 'Dom'),
    'humanitarian': ('Humanitarian aid', 'Pomoc humanitarna'),
    'logistics': ('Logistics', 'Logistyka'),
    'maps': ('Maps', 'Mapy'),
    'maritime': ('Sea & sailing', 'Morze i żegluga'),
    'marketing': ('Marketing', 'Marketing'),
    'mathematics': ('Mathematics', 'Matematyka'),
    'media_playback': ('Media playback', 'Odtwarzanie multimediów'),
    'medical_health': ('Medicine & health', 'Medycyna i zdrowie'),
    'money': ('Money', 'Pieniądze'),
    'moving': ('Moving house', 'Przeprowadzka'),
    'music_audio': ('Music & audio', 'Muzyka i dźwięk'),
    'nature': ('Nature', 'Przyroda'),
    'numbers': ('Numbers', 'Liczby'),
    'photos_images': ('Photos & images', 'Zdjęcia i obrazy'),
    'political': ('Politics', 'Polityka'),
    'punctuation_symbols': ('Punctuation & symbols', 'Interpunkcja i symbole'),
    'religion': ('Religion', 'Religia'),
    'science': ('Science', 'Nauka'),
    'science_fiction': ('Science fiction', 'Fantastyka naukowa'),
    'security': ('Security', 'Bezpieczeństwo'),
    'shapes': ('Shapes', 'Kształty'),
    'shopping': ('Shopping', 'Zakupy'),
    'social': ('Social', 'Społeczność'),
    'spinners': ('Spinners', 'Wskaźniki ładowania'),
    'sports_fitness': ('Sports & fitness', 'Sport i fitness'),
    'text_formatting': ('Text formatting', 'Formatowanie tekstu'),
    'time': ('Time', 'Czas'),
    'toggle': ('Toggles', 'Przełączniki'),
    'transportation': ('Transport', 'Transport'),
    'travel_hotel': ('Travel & hotels', 'Podróże i hotele'),
    'users_people': ('Users & people', 'Użytkownicy i ludzie'),
    'weather': ('Weather', 'Pogoda'),
    'writing': ('Writing', 'Pisanie'),
    'brands': ('Brands', 'Marki'),
    'other': ('Other', 'Pozostałe'),
})

# ── the picker (assets/js/shoutbox.js) ───────────────────────────────────────
add('js.shout', {
    'tab_recent': ('Recently used', 'Ostatnio używane'),
    'tab_smileys': ('Smileys & emotion', 'Buźki i emocje'),
    'tab_people': ('People & body', 'Ludzie i ciało'),
    'tab_animals': ('Animals & nature', 'Zwierzęta i przyroda'),
    'tab_food': ('Food & drink', 'Jedzenie i picie'),
    'tab_travel': ('Travel & places', 'Podróże i miejsca'),
    'tab_activities': ('Activities', 'Aktywności'),
    'tab_objects': ('Objects', 'Przedmioty'),
    'tab_symbols': ('Symbols', 'Symbole'),
    'tab_flags': ('Flags', 'Flagi'),
    'tab_fa_happy': ('Font Awesome — smiles and laughter', 'Font Awesome — uśmiech i śmiech'),
    'tab_fa_calm': ('Font Awesome — calm and thoughtful', 'Font Awesome — spokojne i zamyślone'),
    'tab_fa_sad': ('Font Awesome — worried and sad', 'Font Awesome — zmartwione i smutne'),
    'tab_fa_cross': ('Font Awesome — angry and unwell', 'Font Awesome — złe i chore'),
    'search': ('Search emoji', 'Szukaj emoji'),
    'search_clear': ('Clear the search', 'Wyczyść wyszukiwanie'),
    'search_none': ('Nothing matches “:q”.', 'Nic nie pasuje do „:q”.'),
    'search_found': ('Found: :n', 'Znaleziono: :n'),
    'recent_empty': ('The emoji you pick will wait for you here.', 'Tu będą czekać emoji, które wybierzesz.'),
    'emoji_failed': ('The emoji did not load — try again in a moment.', 'Nie udało się wczytać emoji — spróbuj za chwilę.'),
    # The variants of one emoji, the way a phone offers them: the small bubble over it, and the words a
    # cell with variants adds to its name for whoever hovers or listens.
    'variants': ('Variants of :name', 'Warianty: :name'),
    'variants_hint': ('hold, right-click or Shift+Enter for variants', 'przytrzymaj, kliknij prawym albo Shift+Enter — warianty'),
    'tone_0': ('no skin tone', 'bez odcienia skóry'),
    'tone_1': ('light skin tone', 'jasny odcień skóry'),
    'tone_2': ('medium-light skin tone', 'średnio jasny odcień skóry'),
    'tone_3': ('medium skin tone', 'średni odcień skóry'),
    'tone_4': ('medium-dark skin tone', 'średnio ciemny odcień skóry'),
    'tone_5': ('dark skin tone', 'ciemny odcień skóry'),
    # 1.70.0: the rest of Font Awesome — the "every icon" page with its category chips, and the search's
    # group of icons after the emoji (the category names themselves are `emoji.facat.*`, sent with the faces).
    'tab_fa_all': ('Font Awesome — every icon', 'Font Awesome — wszystkie ikony'),
    'search_icons': ('Search emoji and icons', 'Szukaj emoji i ikon'),
    'fa_cats': ('Font Awesome’s categories', 'Kategorie Font Awesome'),
    'fa_cat_title': (':name — :n icons', ':name — ikon: :n'),
    'fa_cat_status': (':name — :n icons', ':name — ikon: :n'),
    'fa_group': ('Font Awesome: :n', 'Font Awesome: :n'),
    'search_found_fa': ('Found: :n · Font Awesome: :m', 'Znaleziono: :n · Font Awesome: :m'),
    'fa_loading': ('Loading Font Awesome’s icons…', 'Wczytywanie ikon Font Awesome…'),
    'fa_failed': ('Font Awesome’s icons did not load — try again in a moment.', 'Nie udało się wczytać ikon Font Awesome — spróbuj za chwilę.'),
})

# ── the importer's report (tools/iconpack.php, the panel's install log) ──────
add('api.iconpack', {
    'index_line': ('index: :file — :icons icons, :families families, :emoji emoji, :cats categories',
                   'indeks: :file — ikon: :icons, rodzin: :families, emoji: :emoji, kategorii: :cats'),
    # 1.70.0: the catalogue read again from a package's own metadata (tools/iconpack.php reindex, the
    # panel's Rebuild button, or by itself the first time something needs it).
    'reindexed': ('index read again: :id — a catalogue of :icons icons in :cats categories (:kb KB)',
                  'indeks odczytany ponownie: :id — katalog :icons ikon w :cats kategoriach (:kb KB)'),
    'err_no_index': ('This package came without an index to read (Font Awesome’s metadata/ folder): there is nothing to rebuild.',
                     'Ta paczka nie ma indeksu do odczytania (folderu metadata/ Font Awesome): nie ma czego odbudować.'),
})

# ── the panel's package table (assets/js/admin-iconpacks.js) ──────────────────
add('js.iconpack', {
    'reindex': ('Read the index again', 'Odczytaj indeks ponownie'),
    'catalog': ('catalogue: :n icons, :c categories', 'katalog: ikon :n, kategorii :c'),
    'catalog_none': ('catalogue: not built yet — the picker builds it when it first needs it', 'katalog: jeszcze niezbudowany — wybierak zbuduje go, gdy pierwszy raz będzie potrzebny'),
})
