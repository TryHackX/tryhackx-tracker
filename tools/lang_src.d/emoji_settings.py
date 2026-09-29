# -*- coding: utf-8 -*-
"""Settings -> Emoji & emotes (1.71.0): the emoji picker and the emotes, a group of their own

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

Until 1.71.0 the picker's Font Awesome settings and the emote manager were two blocks of Settings ->
Shoutbox, where they grew up; since 1.70.0 every text with the picker takes them, and the owner asked for
a section of their own. The setting keys and most of the words are what they were (shout_emoji.py,
emotes_everywhere.py, admin_settings.py); these are the new ones: what the group is for, the way on from
Shoutbox, and the emote permissions' own fold. The group's title is with the others (settings.group_emoji,
admin_settings.py).

Polish: the group is "Emoji i emotki" -- "emotki" being what this card has always called the uploaded
pictures ("Własne emotki", "Emotki i naklejki").
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('settings', {
    # The group's line, first in its first section: what "the whole site" means here, and the one thing the
    # pictures need that the emoji do not. :url is the Shoutbox section.
    'emoji_intro': (
        'For the whole site, not only the shoutbox: every text that has the emoji picker — the shoutbox, private '
        'messages, torrent descriptions and the proposals that rewrite them, list descriptions and the profile\'s '
        'description — offers the same emoji, the same Font Awesome icons and the same emotes and stickers. The '
        'emoji and the icons work on their own; the emotes and the stickers are pictures the shoutbox keeps, so '
        'they need it switched on (<a href=":url">Shoutbox</a>).',
        'Dla całej strony, nie tylko dla shoutboxa: każdy tekst, który ma wybierak emoji — shoutbox, prywatne '
        'wiadomości, opisy torrentów i propozycje, które je zmieniają, opisy list i opis profilu — pokazuje te '
        'same emoji, te same ikony Font Awesome i te same emotki i naklejki. Emoji i ikony działają same; emotki '
        'i naklejki to obrazki, które przechowuje shoutbox, więc potrzebują go włączonego '
        '(<a href=":url">Shoutbox</a>).'),
    # Under Shoutbox's own line: where the two blocks went. :url is the new group's first section.
    'shout_emoji_moved': (
        'The emoji picker, Font Awesome\'s icons and the emotes and stickers have a group of their own, '
        '<a href=":url">Emoji & emotes</a>: the whole site uses them, not only this room.',
        'Wybierak emoji, ikony Font Awesome oraz emotki i naklejki mają własną grupę, '
        '<a href=":url">Emoji i emotki</a>: używa ich cała strona, nie tylko ten pokój.'),
    # The emote permissions' fold, under the manager (Shoutbox's matrix showed them among the room's).
    'emote_matrix_title': ('Who may upload emotes', 'Kto może wgrywać emotki'),
    'emote_matrix_hint': (
        'The two emote permissions across your groups, as they stand right now: <code>shout.upload_emote</code> '
        'adds a picture from the emotes page, <code>shout.emote_auto</code> skips the queue above. Read-only '
        'here: grants are made in Users → Groups, where every other permission is.',
        'Dwa uprawnienia emotek we wszystkich grupach, tak jak wyglądają w tej chwili: '
        '<code>shout.upload_emote</code> pozwala dodać obrazek ze strony emotek, <code>shout.emote_auto</code> '
        'omija kolejkę powyżej. Tutaj tylko do odczytu: nadaje się je w Użytkownicy → Grupy, tam gdzie wszystkie '
        'pozostałe.'),
})
