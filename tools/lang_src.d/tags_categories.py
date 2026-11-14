S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('perm', {
    'tags.categorize': ('Suggest a category for a tag, or a new category', 'Proponowanie kategorii dla tagu albo nowej kategorii'),
})

add('settings', {
    'tags_popular_max': ('Most popular tags shown', 'Najpopularniejsze tagi — ile pokazać'),
    'tags_popular_max_hint': ('The “Most popular” block in the search filters and on the Tags page: 18 at first, “More” up to this many. 0 = no block.',
                              'Blok „Najpopularniejsze” w filtrach wyszukiwarki i na stronie Tagi: na start 18, „Więcej” do tej liczby. 0 = bez bloku.'),
})

add('a.tags', {
    'tab_list': ('Tags', 'Tagi'),
    'tab_cats': ('Categories', 'Kategorie'),
    'tab_changes': ('Changes on torrents', 'Zmiany na torrentach'),
    'tab_forum': ('From the forum', 'Z forum'),
    'f_cat': ('Category', 'Kategoria'),
    'f_cat_all': ('All categories', 'Wszystkie kategorie'),
    'f_cat_none': ('No category', 'Bez kategorii'),
    'bulk_cat': ('Category for the selected tags', 'Kategoria dla zaznaczonych tagów'),
    'bulk_move': ('Move to category', 'Przenieś do kategorii'),
    'bulk_clear': ('Clear the selection', 'Odznacz'),
    'bulk_delete': ('Delete selected', 'Usuń zaznaczone'),
    'f_unused': ('Unused (0 torrents)', 'Nieużywane (0 torrentów)'),
    'check_all': ('Select every tag on this page', 'Zaznacz wszystkie tagi na tej stronie'),
    'col_cat': ('Category', 'Kategoria'),
    'cats_title': ('Categories', 'Kategorie'),
    'cats_intro': ('A category groups hand-made tags on the Tags page and in the search filters. A tag in a built-in group (Resolution, Language…) stands beside the automatic tags of that group. Members suggest categories; a suggestion waits here until you accept or reject it.',
                   'Kategoria grupuje tagi ręczne na stronie Tagi i w filtrach wyszukiwarki. Tag w grupie wbudowanej (Rozdzielczość, Język…) stoi obok tagów automatycznych tej grupy. Członkowie proponują kategorie; propozycja czeka tutaj, aż ją przyjmiesz albo odrzucisz.'),
})

add('js.tags', {
    'n_selected': ('Selected: :n', 'Zaznaczono: :n'),
    'bulk_delete_confirm': ('Delete :n selected tags? They are taken off every torrent.', 'Usunąć zaznaczone tagi (:n)? Znikną ze wszystkich torrentów.'),
    'select': ('Select “:name”', 'Zaznacz „:name”'),
    'k_none': ('No category', 'Bez kategorii'),
    'k_all': ('All categories', 'Wszystkie kategorie'),
    'k_new_ph': ('Name of a new category', 'Nazwa nowej kategorii'),
    'k_add': ('Add a category', 'Dodaj kategorię'),
    'k_up': ('Move up', 'W górę'),
    'k_down': ('Move down', 'W dół'),
    'k_delete_confirm': ('Delete the category “:name”? Its tags (:n) stay, without a category.', 'Usunąć kategorię „:name”? Jej tagi (:n) zostaną, bez kategorii.'),
    'k_builtin': ('Built-in group', 'Grupa wbudowana'),
    'k_starter': ('Starter', 'Startowa'),
    'k_from_member': ('From a member', 'Od członka'),
    'k_from_mod': ('Added in the panel', 'Dodana w panelu'),
    'k_show': ('Show these tags', 'Pokaż te tagi'),
    'k_col_cat': ('Category', 'Kategoria'),
    'k_col_kind': ('Kind', 'Rodzaj'),
    'k_col_n': ('Tags', 'Tagi'),
    'k_col_proposed': ('Proposed category', 'Proponowana kategoria'),
    'k_col_now': ('Now', 'Obecnie'),
    'k_sugg_title': ('Suggestions from members', 'Propozycje od członków'),
    'k_sugg_none': ('No suggestions are waiting.', 'Żadna propozycja nie czeka.'),
    'k_new_badge': ('new', 'nowa'),
    'cat_title': ('Category for “:name”', 'Kategoria dla „:name”'),
    'cat_hint': ('A moderator decides; a moderator’s own choice applies at once.', 'Decyduje moderator; wybór moderatora działa od razu.'),
    'cat_pick': ('Choose a category…', 'Wybierz kategorię…'),
    'cat_new': ('A new category…', 'Nowa kategoria…'),
    'cat_new_ph': ('Name of the new category', 'Nazwa nowej kategorii'),
    'cat_send': ('Send', 'Wyślij'),
    'cat_need': ('Choose a category or type a name.', 'Wybierz kategorię albo wpisz nazwę.'),
    'cat_now': ('Now: :name', 'Obecnie: :name'),
})

add('facts.tag', {
    't_game': ('Game', 'Gra'),
    't_music': ('Music', 'Muzyka'),
    't_software': ('Software', 'Program'),
    't_book': ('Book or comic', 'Książka lub komiks'),
    't_audiobook': ('Audiobook', 'Audiobook'),
    't_adult': ('18+', '18+'),
    'l_fr': ('French', 'Francuski'),
    'l_de': ('German', 'Niemiecki'),
    'l_es': ('Spanish', 'Hiszpański'),
    'l_it': ('Italian', 'Włoski'),
    'l_ru': ('Russian', 'Rosyjski'),
    'l_ja': ('Japanese', 'Japoński'),
    'l_ko': ('Korean', 'Koreański'),
    'l_zh': ('Chinese', 'Chiński'),
    'l_hi': ('Hindi', 'Hindi'),
})

add('js.filters', {
    'kind_game': ('Games', 'Gry'),
    'kind_music': ('Music', 'Muzyka'),
    'kind_software': ('Software', 'Programy'),
    'kind_book': ('Books', 'Książki'),
    'kind_audiobook': ('Audiobooks', 'Audiobooki'),
    'kind_adult': ('18+', '18+'),
    't_popular': ('Most popular', 'Najpopularniejsze'),
    'g_whitelist': ('Whitelist', 'Whitelista'),
    'wl_only': ('Only whitelisted torrents', 'Tylko torrenty z whitelisty'),
    'wl_only_hint': ('Torrents registered on this tracker; the rest of the search is what the swarm was seen with.',
                     'Torrenty zarejestrowane na tym trackerze; reszta wyszukiwarki to to, co widać w roju.'),
})

add('tags', {
    'popular_h2': ('Most popular', 'Najpopularniejsze'),
    'cat_suggest': ('Suggest a category', 'Zaproponuj kategorię'),
    'cat_suggest_for': ('Suggest a category for “:name”', 'Zaproponuj kategorię dla „:name”'),
    'cat_forum': ('From the forum', 'Z forum'),
    'cat_release': ('Release', 'Wydanie'),
    'cat_picture': ('Picture', 'Obraz'),
    'cat_kind': ('Kind', 'Rodzaj'),
    'cat_platform': ('Platform', 'Platforma'),
    'cat_other': ('Other', 'Inne'),
})

add('settings', {
    'index_block_title': ('Names hidden from the search', 'Nazwy ukryte w wyszukiwarce'),
    'index_block_intro': ('A torrent whose name has one of these words does not reach the search catalogue; it waits on the Index page under “Hidden names”, where you ban it or say it is a mistake. Nothing is banned by itself, and the Whitelist is not touched.',
                          'Torrent, którego nazwa ma jedno z tych słów, nie trafia do katalogu wyszukiwarki; czeka na stronie Index w „Ukrytych nazwach”, gdzie go blokujesz albo uznajesz za pomyłkę. Nic nie jest blokowane samo, a Whitelista zostaje bez zmian.'),
    'index_block_on': ('Hide such names', 'Ukrywaj takie nazwy'),
    'index_block_off': ('No', 'Nie'),
    'index_block_words': ('Words that hide a name on their own', 'Słowa, które ukrywają nazwę same'),
    'index_block_words_hint': ('One per line; letters in any case; a space also matches a dot, a dash or an underscore.', 'Jedno w wierszu; wielkość liter bez znaczenia; spacja pasuje też do kropki, myślnika i podkreślenia.'),
    'index_block_minor': ('Words about minors — they hide a name only together with a word for adult content', 'Słowa o nieletnich — ukrywają nazwę tylko razem ze słowem 18+'),
    'index_block_minor_hint': ('An age under 18 written as “14yo” or “14 years old” counts as well.', 'Liczy się też wiek poniżej 18 zapisany jako „14yo” albo „14 years old”.'),
})

add('a.index', {
    'blk_head': ('Hidden names', 'Ukryte nazwy'),
    'blk_hidden': ('Hidden', 'Ukryte'),
    'blk_allowed': ('Allowed back', 'Przywrócone'),
    'blk_show': ('Show', 'Pokaż'),
    'blk_intro': ('Names the search does not show (Settings → Names hidden from the search). Ban the hash, or allow it back if the name was hidden by mistake.',
                  'Nazwy, których wyszukiwarka nie pokazuje (Ustawienia → Nazwy ukryte w wyszukiwarce). Zablokuj hash albo przywróć go, jeśli nazwa została ukryta przez pomyłkę.'),
})

add('js.index', {
    'blk_show': ('Show', 'Pokaż'),
    'blk_hide': ('Hide', 'Zwiń'),
    'blk_none': ('No name is hidden.', 'Żadna nazwa nie jest ukryta.'),
    'blk_none_allowed': ('Nothing has been allowed back.', 'Nic nie zostało przywrócone.'),
    'blk_allow': ('A mistake — show it in the search', 'Pomyłka — pokaż w wyszukiwarce'),
    'blk_hide_again': ('Hide it again', 'Ukryj ponownie'),
    'blk_ban': ('Ban the hash', 'Zablokuj hash'),
    'blk_ban_confirm': ('Ban “:name”? The tracker stops serving it.', 'Zablokować „:name”? Tracker przestanie go obsługiwać.'),
    'blk_done': ('Done.', 'Gotowe.'),
    'blk_failed': ('That did not work.', 'Nie udało się.'),
    'blk_col_name': ('Name', 'Nazwa'),
    'blk_col_word': ('Word', 'Słowo'),
    'blk_col_when': ('Hidden', 'Ukryto'),
    'blk_col_acts': ('Actions', 'Akcje'),
})

add('api.tags', {
    'cat_exists': ('That category already exists.', 'Taka kategoria już istnieje.'),
    'cat_set': ('Category set.', 'Kategoria ustawiona.'),
    'cat_sent': ('Thank you — a moderator will look at it.', 'Dziękujemy — moderator to sprawdzi.'),
})
