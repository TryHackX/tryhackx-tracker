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
        'them to switch; what can be chosen is Font Awesome\'s faces.',
        'Wszystkie emoji, jakie ma Unicode, do wersji Emoji 16.0 — najnowszej, którą rysują Windows 11, Android '
        'i iOS — na kartach samego Unicode, z wyszukiwarką w języku czytelnika (a po tym, co w nim znajdzie, '
        'także po angielsku), z ostatnio używanymi na osobnej karcie i z pięcioma odcieniami skóry po '
        'przytrzymaniu emoji, jak w telefonie. To zwykłe znaki rysowane przez urządzenie czytelnika, więc nie '
        'ma w nich czego przełączać; do wyboru są buźki Font Awesome.'),
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
})

# ── the importer's report (tools/iconpack.php, the panel's install log) ──────
add('api.iconpack', {
    'index_line': ('index: :file — :icons icons, :families families, :emoji emoji',
                   'indeks: :file — ikon: :icons, rodzin: :families, emoji: :emoji'),
})
