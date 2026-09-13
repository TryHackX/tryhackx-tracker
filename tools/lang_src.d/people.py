# -*- coding: utf-8 -*-
"""People: messages, friends, blocks and the directory

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
    'people.block_body': ('They will not be able to write to you, and whatever the two of you were to each other ends. They are told they have been blocked if they try to write — a message nobody will ever read is a worse answer than the truth.',
        'Ta osoba nie będzie mogła do Ciebie napisać, a to, czym dla siebie byliście, się kończy. Przy próbie napisania dowie się, że jest zablokowana — wiadomość, której nikt nie przeczyta, jest gorszą odpowiedzią niż prawda.'),
    'people.block_go': ('Block them',
        'Zablokuj'),
    'people.block_hide_hint': ('Off, they can still read your profile and see what you have shared publicly — you have only stopped the messages.',
        'Wyłączone — nadal widzi Twój profil i to, co udostępniasz publicznie; zatrzymujesz tylko wiadomości.'),
    'people.block_hide_label': ('Also hide my profile from them',
        'Ukryj też przed nią mój profil'),
    'people.block_title': ('Block',
        'Blokada'),
    'people.blocks': ('Blocked',
        'Zablokowani'),
    'people.dir_h1': ('Members',
        'Użytkownicy'),
    'people.dir_intro': ('The people who asked to be findable. Everybody else is still here — they are simply not on this page.',
        'Osoby, które chcą być znajdowane. Reszta nadal tu jest — po prostu nie ma jej na tej stronie.'),
    'people.dir_off': ('The member directory is switched off on this tracker.',
        'Katalog użytkowników jest na tym trackerze wyłączony.'),
    'people.dir_search_ph': ('Find somebody by name…',
        'Znajdź kogoś po nazwie…'),
    'people.dir_sign_in': ('Sign in to browse the member directory.',
        'Zaloguj się, aby przeglądać katalog użytkowników.'),
    'people.dir_sort_name': ('Name A–Z',
        'Nazwa A–Z'),
    'people.dir_sort_name_desc': ('Name Z–A',
        'Nazwa Z–A'),
    'people.dir_sort_new': ('Newest members',
        'Najnowsi'),
    'people.dir_sort_old': ('Oldest members',
        'Najstarsi'),
    'people.friends': ('Friends',
        'Znajomi'),
    'people.incoming': ('Asked me',
        'Zaprosili mnie'),
    'people.pending': ('I asked',
        'Ja zaprosiłem'),
    'people.search_ph': ('Filter by name…',
        'Filtruj po nazwie…'),
})
