# -*- coding: utf-8 -*-
"""Shoutbox

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

These are the strings the SERVER renders: the widget on the front page, the page of its own, the
composer around it and the sentence that replaces the composer when the reader may not write. What
the browser script says for itself lives in tools/lang_src.d/js.py under `js.shout.`.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the shoutbox (1.58.0) ───────────────────────────────────────────────────
add('shout', {
    'h1':          ('Shoutbox', 'Shoutbox'),
    # The heading the home page draws over the block. Kept separate from `h1` so an operator who
    # renames one in Settings -> Home layout does not silently rename the page's title too.
    'heading':     ('Shoutbox', 'Shoutbox'),
    'not_found':   ('There is no shoutbox here.', 'Nie ma tu shoutboksa.'),
    'empty':       ('Nothing has been said yet.', 'Jeszcze nikt nic nie napisał.'),
    'older':       ('Older', 'Starsze'),
    'send':        ('Send', 'Wyślij'),
    'write_ph':    ('Say something…', 'Napisz coś…'),
    'enter_hint':  ('Enter sends it, Shift+Enter starts a new line.',
                    'Enter wysyła, Shift+Enter zaczyna nową linię.'),
    'delete':      ('Delete', 'Usuń'),
    'delete_title': ('Delete this shout', 'Usuń ten wpis'),
    'open_page':   ('Open the shoutbox', 'Otwórz shoutbox'),
    # Why the box below the list is not there. One sentence, in the place the box would have been --
    # an empty space teaches nobody why they cannot write.
    'closed_disabled': ('The shoutbox is switched off.', 'Shoutbox jest wyłączony.'),
    'closed_login':    ('Sign in to write here.', 'Zaloguj się, aby tu pisać.'),
    'closed_no_permission': ('You can read the shoutbox, but not write in it.',
                             'Możesz czytać shoutbox, ale nie możesz w nim pisać.'),
    'closed_muted':    ('You are muted until :until, so you can read here but not write.',
                        'Masz wyciszenie do :until, więc możesz tu czytać, ale nie pisać.'),
})

# The browser tab. The key is the action, exactly as templates/layout.php looks it up.
add('', {
    'title.shoutbox': ('Shoutbox', 'Shoutbox'),
})
