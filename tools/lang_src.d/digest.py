# -*- coding: utf-8 -*-
"""The operator digest mail

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
    'digest.intro': ('These things on :site are waiting for somebody to look at them:',
        'Te rzeczy na :site czekają, aż ktoś na nie spojrzy:'),
    'digest.line_abuse': (':n abuse reports nobody has opened',
        'zgłoszenia nadużyć, których nikt nie otworzył: :n'),
    'digest.line_descriptions': (':n descriptions waiting to be read',
        'opisy czekające na przeczytanie: :n'),
    'digest.line_messages': (':n reported messages still open',
        'zgłoszone wiadomości wciąż otwarte: :n'),
    'digest.line_partners': (':n partner submissions held for review',
        'zgłoszenia partnerów wstrzymane do przeglądu: :n'),
    'digest.open_panel': ('Open the panel',
        'Otwórz panel'),
    'digest.outro': ('Nothing here is urgent by itself — but somebody on the other end of each of these is waiting for an answer.',
        'Nic z tego samo w sobie nie jest pilne — ale po drugiej stronie każdej z tych pozycji ktoś czeka na odpowiedź.'),
    'digest.subject': ('[:site] :n things are waiting for you',
        '[:site] czeka na ciebie :n rzeczy'),
})
