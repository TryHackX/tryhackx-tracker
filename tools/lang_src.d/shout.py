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
    #
    # `flame` and not `fire`: `fire` is one of richtextEmoji()'s built-in shortcodes, so it is
    # refused as an emote code (api.emote.code_reserved) and there is no `:fire:` emote to write.
    # An example nobody can follow is worse than no example; `flame` is one the tracker ships.
    'emotes_intro':  ('Write the code between colons in a shout — <code>:flame:</code> — and it becomes the picture. '
                      'The button beside the send button inserts them for you, and a code below copies itself when you click it.',
                      'Wpisz kod między dwukropkami — <code>:flame:</code> — a zamieni się w obrazek. '
                      'Przycisk obok „Wyślij” wstawia je za ciebie, a kod poniżej kopiuje się po kliknięciu.'),
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

# ── 1.59.1 ──────────────────────────────────────────────────────────────────
# The button in the widget's head, the click-to-copy chip under every picture, and the two sentences
# the approval gate needs: what a card that is waiting says, and what the form says before anybody
# uploads anything into a queue they did not know was there.
add('shout', {
    'refresh':        ('Check for new lines', 'Sprawdź, czy są nowe wpisy'),
    'emote_copy_title': ('Click to copy the code', 'Kliknij, by skopiować kod'),
    'emote_waiting':  ('waiting', 'czeka'),
    'emote_waiting_hint': ('Nobody else can see it until a moderator lets it through.',
                           'Nikt inny jej nie widzi, dopóki moderator jej nie przepuści.'),
    'emote_approval_note': ('What you add waits for a moderator before anybody else can see it.',
                            'To, co dodasz, czeka na moderatora, zanim zobaczy to ktokolwiek inny.'),
})

# ── 1.60.0: the pinned line, and the lines the site says itself ─────────────
add('shout', {
    'pin':         ('Pin', 'Przypnij'),
    'pin_title':   ('Pin this line at the top of the room', 'Przypnij ten wpis na górze pokoju'),
    'unpin':       ('Unpin', 'Odepnij'),
    'unpin_title': ('Take the pinned line down', 'Zdejmij przypięty wpis'),
    # Who a line with no author is signed with, and only where the operator has left the site name
    # empty -- normally it is the site's own name, which is what a room would show.
    'system_who':  ('the site', 'strona'),
    # What the tracker itself says in the room. The submitter's name is the row's AUTHOR rather than
    # a word inside the sentence, which is why the named pair begins in lower case: it is read after
    # "name:", exactly like a line somebody wrote. It also dodges a real problem -- a name dropped
    # into a Polish sentence has to agree with the verb after it, and "zarejestrował(a)" in the
    # middle of a room is a form nobody says out loud. The anonymous pair uses the impersonal
    # "zarejestrowano", which needs no gender and no number.
    'sys_wl_named_one':  ('a new torrent on the whitelist.', 'nowy torrent na whiteliście.'),
    'sys_wl_named_many': ('new torrents on the whitelist: :n.', 'nowe torrenty na whiteliście: :n.'),
    'sys_wl_anon_one':   ('A new torrent has been registered.', 'Zarejestrowano nowy torrent.'),
    'sys_wl_anon_many':  ('New torrents registered: :n.', 'Zarejestrowano nowe torrenty: :n.'),
})

# ── 1.61.0: the composer rearranged, and the upload that does not wait ─────
add('shout', {
    # The fold's own label carries the sentence that used to sit under the box on a line of its own.
    # In brackets after it, because it is a second and smaller fact about the same box -- and the
    # standalone line it replaces was one more thing between the composer and the edge of the block.
    # A key of the shoutbox's own rather than a change to `rt.syntax_help`: that one is the label on
    # the description and message editors too, and neither of those sends on Enter.
    'syntax_help': ('Formatting help (Enter sends it, Shift+Enter starts a new line.)',
                    'Pomoc do formatowania (Enter wysyła, Shift+Enter zaczyna nową linię.)'),
    # What the upload box says to somebody the queue does not apply to (`shout.emote_auto`). Drawn
    # INSTEAD of `emote_approval_note`, never beside it: telling a person their picture waits for a
    # moderator when it does not is the one thing that sentence must not do.
    'emote_approval_skip': ('What you add is visible at once — you do not wait for a moderator.',
                            'To, co dodasz, widać od razu — nie czekasz na moderatora.'),
})

# The browser tab. The key is the action, exactly as templates/layout.php looks it up.
add('', {
    'title.shoutbox': ('Shoutbox', 'Shoutbox'),
    'title.emotes':   ('Emotes', 'Emote'),
})

# ── 1.62.0: a picture in a shout is a link ─────────────────────────────────
# The title on the link round a picture. It names the one thing a pointer cannot discover on its own:
# that the same picture also opens in a tab of its own.
add('shout', {
    'img_open': ('Click to enlarge — Ctrl+click opens the original in a new tab',
                 'Kliknij, aby powiększyć — Ctrl+klik otwiera oryginał w nowej karcie'),
})

# ── 1.66.0: correcting a line, the mark it leaves, and the way back down ────
# The pencil beside the pin (or in its place), and what a corrected line says beside its time. A line
# somebody ELSE changed says who in general terms, because a reader must be able to tell a typo the
# author fixed from words a moderator put under their name. The Polish avoids agreeing a participle
# with anything: "edytowano" is impersonal, and "edytował moderator" names the one who did it.
# `:at` is the exact moment, in the reader's zone with its offset. The browser draws the same strings
# for a row it appends (`js.shout.*` in tools/lang_src.d/js.py) and they must stay identical.
add('shout', {
    'edit':             ('Edit', 'Edytuj'),
    'edit_title':       ('Correct this line', 'Popraw ten wpis'),
    'edited':           ('(edited)', '(edytowano)'),
    'edited_mod':       ('(edited by a moderator)', '(edytował moderator)'),
    'edited_title':     ('Edited :at', 'Edytowano :at'),
    'edited_mod_title': ('Edited by a moderator, :at', 'Edytował moderator, :at'),
    # The button pinned to the end of the list that new lines arrive at, shown only while the reader
    # has scrolled away from it. The count sits beside the words as a number of its own, so neither
    # language has to agree a noun with it.
    'new_lines':        ('New lines', 'Nowe wpisy'),
    'new_lines_title':  ('Jump to the newest lines', 'Przejdź do najnowszych wpisów'),
})
