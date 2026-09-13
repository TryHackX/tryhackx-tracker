# -*- coding: utf-8 -*-
"""Private messages

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
    'pm.new': ('New message',
        'Nowa wiadomość'),
    'pm.new_go': ('Open',
        'Otwórz'),
    'pm.new_ph': ('Who to?',
        'Do kogo?'),
    'pm.note': ('Messages use the same formatting as descriptions, and this tracker allows :n of them a day per account.',
        'Wiadomości używają tego samego formatowania co opisy, a ten tracker pozwala na :n dziennie na konto.'),
    'pm.report_head': ('Report this message',
        'Zgłoś tę wiadomość'),
    'pm.report_note': ('A moderator sees <strong>this message and the one before it</strong> — not the rest of the conversation. Say what is wrong with it; a line or two is enough.',
        'Moderator zobaczy <strong>tę wiadomość i jedną poprzedzającą</strong> — nie resztę rozmowy. Napisz, co jest z nią nie tak; wystarczy linijka albo dwie.'),
    'pm.report_ph': ('What is wrong with it?',
        'Co jest z nią nie tak?'),
    'pm.report_send': ('Send the report',
        'Wyślij zgłoszenie'),
    'pm.search_deep': ('Search inside messages',
        'Szukaj w treści wiadomości'),
    'pm.search_deep_title': ('Look through what was written, not only who wrote it',
        'Przeszukuj to, co napisano, nie tylko kto napisał'),
    'pm.search_ph': ('Filter conversations…',
        'Filtruj rozmowy…'),
})
