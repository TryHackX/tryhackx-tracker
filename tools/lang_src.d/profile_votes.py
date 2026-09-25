# -*- coding: utf-8 -*-
"""A member's likes or ratings, on the account page and the profile (1.69.0, includes/profilevotes.php)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The words follow the rating system's mode: with thumbs the list is "Likes" / "Polubienia" and a vote
is a thumb up or down ("kciuk w górę / w dół"); with stars it is "Ratings" / "Oceny" and a vote is a
rating in half stars. The Polish for somebody else's vote or rating is "głos / ocena użytkownika" --
the plain way to say "this person's" in a column header, where "jego / jej" would have to guess.

Numbers sit where Polish does not have to agree with them ("Znaleziono: 12", "liczba ocen: 5",
"z 3"), never before a noun that changes with the number. The page's own tab and heading reuse
nothing from favourites: these are their own words.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── Settings -> Profiles -> Likes and ratings on profiles ────────────────────
add('settings', {
    'profile_votes_heading': ('Likes and ratings on profiles', 'Polubienia i oceny na profilach'),
    'profile_votes_intro': (
        'The torrents a member voted on, in whichever mode the rating system is in — their likes (thumbs up '
        'and down) or their ratings (stars) — on a tab of their account page right after Favourites and, if '
        'they allow it, in a section of their public profile right after Favourites. A table that sorts and '
        'filters: in star mode by a range of their own rating and of the average, in thumbs mode by which way '
        'they voted and by how many votes a torrent has. It works only while ratings are switched on (the '
        '<strong>Ratings</strong> section of these settings). Showing the list to other members takes the '
        '<code>rating.public</code> permission (Users → Groups; members have it) and the member’s own yes in '
        'their privacy settings, which starts as no. A score below the minimum number of votes is not shown '
        'here either, and votes cast without an account are never listed anywhere.',
        'Torrenty, na które członek głosował, w trybie, w jakim działa system ocen — jego polubienia (kciuk '
        'w górę i w dół) albo jego oceny (gwiazdki) — na karcie jego strony konta, zaraz po Ulubionych, a jeśli '
        'na to pozwoli, także w sekcji jego publicznego profilu, zaraz po Ulubionych. To tabela, którą można '
        'sortować i filtrować: w trybie gwiazdek po zakresie własnej oceny i średniej, w trybie kciuków po '
        'kierunku głosu i po liczbie głosów, jakie torrent ma. Działa tylko wtedy, gdy oceny są włączone '
        '(sekcja <strong>Oceny</strong> w tych ustawieniach). Pokazanie listy innym członkom wymaga uprawnienia '
        '<code>rating.public</code> (Użytkownicy → Grupy; członkowie je mają) oraz zgody samego członka '
        'w ustawieniach prywatności, która na początku jest wyłączona. Wynik poniżej minimalnej liczby głosów '
        'nie jest pokazywany także tutaj, a głosy oddane bez konta nigdy nie trafiają na żadną listę.'),
    'profile_votes_enabled': ('Likes and ratings on profiles', 'Polubienia i oceny na profilach'),
    'profile_votes_enabled_hint': (
        'Off hides the tab and the profile section everywhere. Nothing is deleted.',
        'Wyłączenie ukrywa kartę i sekcję profilu wszędzie. Nic nie jest usuwane.'),
    'profile_votes_rep_off': (
        'Ratings are switched off right now, so nothing of this is shown anywhere.',
        'Oceny są teraz wyłączone, więc nic z tego nie jest nigdzie pokazywane.'),
})

# ── the account page: the tab, and the privacy switch ────────────────────────
add('account', {
    'tab_votes_thumbs': ('Likes', 'Polubienia'),
    'tab_votes_stars': ('Ratings', 'Oceny'),
    'votes_public_label_thumbs': ('Show my likes on my profile', 'Pokazuj moje polubienia na moim profilu'),
    'votes_public_label_stars': ('Show my ratings on my profile', 'Pokazuj moje oceny na moim profilu'),
    'votes_public_hint_thumbs': (
        'Your thumbs down are shown too, not only the likes. Off, nobody but you sees the list.',
        'Widać też twoje kciuki w dół, nie tylko polubienia. Wyłączone — tej listy nie widzi nikt poza tobą.'),
    'votes_public_hint_stars': (
        'Your low ratings are shown too, not only the high ones. Off, nobody but you sees the list.',
        'Widać też twoje niskie oceny, nie tylko wysokie. Wyłączone — tej listy nie widzi nikt poza tobą.'),
})

# ── the profile page's section heading (the account tab's pane uses it too) ─────
add('profile', {
    'votes_thumbs': ('Likes', 'Polubienia'),
    'votes_stars': ('Ratings', 'Oceny'),
})

# ── the table's toolbar and header (templates/partials/votes_section.php) ────
add('votes', {
    'col_own_thumbs_self': ('Your vote', 'Twój głos'),
    'col_own_thumbs_other': ('Their vote', 'Głos użytkownika'),
    'col_own_stars_self': ('Your rating', 'Twoja ocena'),
    'col_own_stars_other': ('Their rating', 'Ocena użytkownika'),
    'col_score_thumbs': ('Score', 'Wynik'),
    'col_score_thumbs_title': (
        'The share of all votes on it that are thumbs up — shown once it has enough votes',
        'Jaka część wszystkich głosów to kciuki w górę — widać to, gdy głosów jest dość'),
    'col_score_stars': ('Average', 'Średnia'),
    'col_score_stars_title': (
        'The average of everybody’s ratings — shown once it has enough of them',
        'Średnia ocen wszystkich — widać ją, gdy ocen jest dość'),
    'col_votes': ('Votes', 'Głosy'),
    'col_votes_title': ('How many votes it has from everybody', 'Ile głosów oddali na niego wszyscy'),
    'col_date_thumbs': ('Voted on', 'Data głosu'),
    'col_date_stars': ('Rated on', 'Data oceny'),
    'col_date_title': (
        'When the vote was cast or last changed, in your time zone',
        'Kiedy głos oddano albo ostatnio zmieniono — w twojej strefie czasowej'),
    'col_actions': ('Actions', 'Akcje'),
    'range_from': ('From', 'Od'),
    'range_to': ('to', 'do'),
    'range_to_title': ('To', 'Do'),
    'avg': ('Average', 'Średnia'),
    'avg_title': (
        'Everybody’s average rating. A torrent with too few ratings has none, so a range here leaves it out.',
        'Średnia ocen wszystkich. Torrent ze zbyt małą liczbą ocen jej nie ma, więc zakres tutaj go pomija.'),
    'vote_all': ('Up and down', 'W górę i w dół'),
    'vote_up': ('Thumbs up', 'Kciuk w górę'),
    'vote_down': ('Thumbs down', 'Kciuk w dół'),
    'min_votes': ('Min. votes', 'Minimum głosów'),
    'min_votes_title': (
        'Only torrents with at least this many votes from everybody',
        'Tylko torrenty, które mają od wszystkich co najmniej tyle głosów'),
})

# ── strings the browser script needs (assets/js/favourites.js) ───────────────
add('js.votes', {
    'up': ('Thumbs up', 'Kciuk w górę'),
    'down': ('Thumbs down', 'Kciuk w dół'),
    'stars_aria': (':n of 5 stars', ':n na 5 gwiazdek'),
    'score_title': (':pct% thumbs up · up: :up, down: :down', ':pct% kciuków w górę · w górę: :up, w dół: :down'),
    'avg_title': ('Average :stars of 5 · ratings: :n', 'Średnia :stars na 5 · liczba ocen: :n'),
    'too_few_thumbs': ('Too few votes for a score: :n of :min', 'Za mało głosów na wynik: :n z :min'),
    'too_few_stars': ('Too few ratings for an average: :n of :min', 'Za mało ocen na średnią: :n z :min'),
    'total': ('Found: :n', 'Znaleziono: :n'),
    'none_match': ('Nothing matches these filters.', 'Nic nie pasuje do tych filtrów.'),
    'none_own_thumbs': (
        'Nothing here yet. A thumb in a torrent’s Info panel puts it here.',
        'Na razie pusto. Kciuk w panelu Info torrenta dodaje go tutaj.'),
    'none_own_stars': (
        'Nothing here yet. The stars in a torrent’s Info panel put it here.',
        'Na razie pusto. Gwiazdki w panelu Info torrenta dodają go tutaj.'),
})
