# -*- coding: utf-8 -*-
"""The torrent's "Who has this", in three sections -- favourites, likes / ratings, lists (1.70.0, includes/who.php)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The overlay's own name stays "Who has this" / "Kto ma to u siebie" (js.fav.who, since 1.46.0). The sections
take the words the rest of the site uses for them: Favourites / Ulubione, Likes / Polubienia or Ratings /
Oceny (by the rating mode, as the account tab and the profile section are named), Lists / Listy.

Numbers sit where Polish does not have to agree with them ("Liczba osób: 12", "Liczba list: 3", "Znaleziono: 5",
"Pokaż kolejne (20)") -- ":n osób" was wrong for 2-4 and 22-24, and the counts here run to any number.
Nothing guesses anybody's gender: a vote is "kciuk", a rating "ocena", and what somebody did is said of
the torrent ("nie ocenił tego" is avoided: "nikt ... nie ocenił" reads the same for everybody).
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the overlay's sections (templates/partials/info_overlay.php) ─────────────
add('who', {
    'fav': ('Favourites', 'Ulubione'),
    'votes_thumbs': ('Likes', 'Polubienia'),
    'votes_stars': ('Ratings', 'Oceny'),
    'lists': ('Lists', 'Listy'),
    'search_fav': ('Search by name', 'Szukaj po nazwie'),
    'search_votes': ('Search by name', 'Szukaj po nazwie'),
    'search_lists': ('Search a list or its owner', 'Szukaj listy lub właściciela'),
    'why': (
        'Only people who chose to be named here are listed — everybody decides for themselves, under Privacy '
        'on their account page — and a list only while its owner shows it. So a section can be empty even '
        'though many people have this.',
        'Wymienione są tylko osoby, które zgodziły się tu pojawiać — każdy decyduje o sobie w sekcji '
        'Prywatność na stronie konta — a lista tylko wtedy, gdy jej właściciel ją pokazuje. Dlatego sekcja '
        'może być pusta, choć wiele osób ma ten torrent.'),
})

# ── what the script writes into them (assets/js/favourites.js, initWho()) ─────
add('js.who', {
    'people_n': (':n people', 'Liczba osób: :n'),
    'people_one': ('1 person', 'Liczba osób: 1'),
    'lists_n': (':n lists', 'Liczba list: :n'),
    'lists_one': ('1 list', 'Liczba list: 1'),
    'found': ('Found: :n', 'Znaleziono: :n'),
    'more': ('Show :n more', 'Pokaż kolejne (:n)'),
    'no_match': ('Nothing matches that name.', 'Nic nie pasuje do tej nazwy.'),
    'fav_none': ('Nobody who shows their name has this in their favourites.',
                 'Nikt, kto pokazuje swoją nazwę, nie ma tego w ulubionych.'),
    'votes_none_thumbs': ('Nobody who shows their name has liked or disliked this.',
                          'Nikt, kto pokazuje swoją nazwę, nie dał temu kciuka w górę ani w dół.'),
    'votes_none_stars': ('Nobody who shows their name has rated this.',
                         'Nikt, kto pokazuje swoją nazwę, nie ocenił tego torrenta.'),
    'lists_none': ('It is not on any public list.', 'Nie ma tego na żadnej publicznej liście.'),
    'rate_limited': ('Too many look-ups for now — try again in a while.',
                     'Zbyt wiele zapytań naraz — spróbuj ponownie za jakiś czas.'),
})

# ── Settings → Profiles: the two new sections' switches ──────────────────────
add('settings', {
    'who_votes_enabled': ('Likes and ratings in “Who has this”', 'Polubienia i oceny w „Kto ma to u siebie”'),
    'who_votes_enabled_hint': (
        'A torrent’s “Who has this” lists the members who liked or rated it, each with their vote — only those '
        'who said yes twice in Privacy (their likes on their profile, and their name there) and whose group '
        'grants <code>rating.public</code>; votes cast without an account never. Off hides that section '
        'everywhere; nothing is deleted.',
        'Okno „Kto ma to u siebie” torrenta wymienia członków, którzy go polubili albo ocenili, każdego z jego '
        'głosem — tylko tych, którzy zgodzili się na to dwa razy w ustawieniach prywatności (polubienia na '
        'profilu i nazwa w tym oknie) i których grupa ma uprawnienie <code>rating.public</code>; głosy oddane '
        'bez konta nigdy. Wyłączenie ukrywa tę sekcję wszędzie; nic nie jest usuwane.'),
    'who_lists_enabled': ('Lists in “Who has this”', 'Listy w „Kto ma to u siebie”'),
    'who_lists_enabled_hint': (
        'A torrent’s “Who has this” shows the public lists it is on, with their owners’ names — exactly the '
        'lists their profiles show. Off hides that section; the lists stay public on the profiles.',
        'Okno „Kto ma to u siebie” torrenta pokazuje publiczne listy, na których jest, z nazwami ich '
        'właścicieli — dokładnie te listy, które pokazują ich profile. Wyłączenie ukrywa tę sekcję; listy '
        'nadal są publiczne na profilach.'),
})

# ── the account page: the new consent, right under the likes' own switch ─────
add('account', {
    'votes_listed_label_thumbs': (
        'Let my name, with my thumb, appear in a torrent’s “who has this”',
        'Pozwól, by moja nazwa z moim kciukiem pojawiała się w „kto ma to u siebie” torrenta'),
    'votes_listed_label_stars': (
        'Let my name, with my rating, appear in a torrent’s “who has this”',
        'Pozwól, by moja nazwa z moją oceną pojawiała się w „kto ma to u siebie” torrenta'),
    'votes_listed_hint_thumbs': (
        'Thumbs down too. It needs the switch above as well; off, you are not on that list and not in its '
        'count either.',
        'Także kciuki w dół. Potrzebny jest też przełącznik powyżej; wyłączone — nie ma cię na tej liście ani '
        'w jej liczniku.'),
    'votes_listed_hint_stars': (
        'Low ratings too. It needs the switch above as well; off, you are not on that list and not in its '
        'count either.',
        'Także niskie oceny. Potrzebny jest też przełącznik powyżej; wyłączone — nie ma cię na tej liście ani '
        'w jej liczniku.'),
})
