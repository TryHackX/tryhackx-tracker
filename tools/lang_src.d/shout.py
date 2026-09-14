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

# ── emoji, emotes and stickers (1.59.0) ─────────────────────────────────────
# The picker's own contents are characters, not words, so almost nothing here is about the emoji:
# it is about the CODES -- the page that lists them, and the form the few who may upload one use.
add('shout', {
    'emoji_title':   ('Emoji, emotes and stickers', 'Emotki, emote i naklejki'),
    'emotes_link':   ('Emotes', 'Emote'),
    'emotes_h1':     ('Emotes', 'Emote'),
    # The example is the same token in both languages on purpose: a `:word` in one and not in the
    # other is what the dictionary test reads as a placeholder somebody forgot to translate.
    'emotes_intro':  ('Write the code between colons in a shout — <code>:fire:</code> — and it becomes the picture. '
                      'The button beside the send button inserts them for you.',
                      'Wpisz kod między dwukropkami — <code>:fire:</code> — a zamieni się w obrazek. '
                      'Przycisk obok „Wyślij” wstawia je za ciebie.'),
    'emotes_head':   ('Emotes', 'Emote'),
    'emotes_none':   ('Nothing here yet.', 'Na razie nic tu nie ma.'),
    'stickers_head': ('Stickers', 'Naklejki'),
    'stickers_hint': ('A shout that is nothing but one sticker code is drawn large.',
                      'Wpis złożony wyłącznie z kodu naklejki rysuje się duży.'),
    'emote_by':      ('by :name', 'od :name'),
    'emote_shipped': ('shipped with the tracker', 'dostarczona z trackerem'),
    'emote_disabled': ('switched off', 'wyłączona'),
    'emote_add_head': ('Add one', 'Dodaj własną'),
    'emote_drop_aria': ('Choose an image, or drop one here', 'Wybierz obrazek albo upuść go tutaj'),
    'emote_drop_choose': ('Choose a file', 'Wybierz plik'),
    'emote_drop_or': ('or drop it here', 'albo upuść go tutaj'),
    'emote_drop_sub': ('SVG, PNG, GIF or WebP · up to :kb KB · at most :px×:px',
                       'SVG, PNG, GIF lub WebP · do :kb KB · najwyżej :px×:px'),
    'emote_code':    ('Code', 'Kod'),
    'emote_code_hint': ('Lowercase letters, digits and underscores, 2 to 32 of them. It is what people type between colons.',
                        'Małe litery, cyfry i podkreślenia, od 2 do 32 znaków. To właśnie wpisuje się między dwukropkami.'),
    'emote_name':    ('Name', 'Nazwa'),
    'emote_name_ph': ('Fire', 'Ogień'),
    'emote_name_hint': ('Shown when somebody points at it.', 'Widoczna, gdy ktoś na nią najedzie.'),
    'emote_is_sticker': ('This is a sticker', 'To naklejka'),
    'emote_is_sticker_hint': ('Stickers are sent on their own and drawn large; emotes sit inside a sentence.',
                              'Naklejki wysyła się same i rysują się duże; emote siedzą w zdaniu.'),
    'emote_upload':  ('Upload', 'Wyślij'),
    'emote_limits':  ('Up to :kb KB and :px×:px, and :n of them per person.',
                      'Do :kb KB i :px×:px, najwyżej :n na osobę.'),
    'emote_mine_head': ('Yours', 'Twoje'),
    'emote_mine_none': ('You have not uploaded any yet.', 'Nie wysłałeś jeszcze żadnej.'),
})

# The browser tab. The key is the action, exactly as templates/layout.php looks it up.
add('', {
    'title.shoutbox': ('Shoutbox', 'Shoutbox'),
    'title.emotes':   ('Emotes', 'Emote'),
})
