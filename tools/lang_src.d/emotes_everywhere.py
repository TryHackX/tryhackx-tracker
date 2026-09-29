# -*- coding: utf-8 -*-
"""The emoji picker in every editor, and the room's emotes beyond the room (1.70.0)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The words are the picker's own (js.shout.emoji, "Emotki, emote i naklejki"): "emotki" are the emoji,
"emote" the pictures somebody uploaded, "naklejki" the stickers. The toolbar button's title says only
what it offers in that editor -- a profile's description has no stickers, and a reader whose emotes are
kept to the room is offered the emoji alone. The titles are under rt.* for the templates and under js.bio
for the profile's editor, which builds its toolbar in the browser (the public pages carry js.* only).
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── Settings -> Emoji & emotes -> Emotes and stickers: the switch (Shoutbox's until 1.71.0) ──
add('settings', {
    'emotes_everywhere': ('Beyond the shoutbox', 'Poza shoutboxem'),
    'emotes_everywhere_hint': (
        'The same emotes and stickers in private messages, torrent descriptions (and the proposals that '
        'rewrite them), list descriptions and the profile\'s description: every editor\'s emoji picker offers '
        'them, and the page that shows the text draws them — a sticker at the shoutbox\'s size in a message, '
        'smaller in a description, as an emote in a profile. Approved emotes that are switched on, and only '
        'while the emotes above are on. Off: there, <code>:code:</code> is the text it was typed as; the '
        'shoutbox is not affected. In an e-mail an emote is always its code.',
        'Te same emote i naklejki w prywatnych wiadomościach, opisach torrentów (i propozycjach, które je '
        'zmieniają), opisach list i opisie profilu: wybierak emotek w każdym edytorze je pokazuje, a strona, '
        'która wyświetla tekst, je rysuje — naklejkę w wiadomości w rozmiarze z shoutboxa, w opisie mniejszą, '
        'w profilu jako emote. Tylko zatwierdzone i włączone emote i tylko wtedy, gdy emote powyżej są '
        'włączone. Wyłączone: tam <code>:code:</code> jest tekstem, jak go wpisano; shoutboxa to nie dotyczy. '
        'W e-mailu emote jest zawsze swoim kodem.'),
})

# ── the picker's button on a toolbar (templates: emojiPickerButton()) ─────────
add('rt', {
    'emoji':          ('Emoji', 'Emotki'),
    'emoji_emotes':   ('Emoji and emotes', 'Emotki i emote'),
    'emoji_stickers': ('Emoji, emotes and stickers', 'Emotki, emote i naklejki'),
})

# ── the profile's editor builds its own toolbar (assets/js/profile-bio.js) ───
add('js.bio', {
    'emoji':        ('Emoji', 'Emotki'),
    'emoji_emotes': ('Emoji and emotes', 'Emotki i emote'),
})

# ── the picker (assets/js/emoji-picker.js): a sticker in an editor ───────────
add('js.shout', {
    'sticker_insert_hint': ('A sticker goes in as its code, and where the text is read it is drawn as a sticker.',
                            'Naklejka trafia do tekstu jako kod, a tam, gdzie tekst się czyta, jest rysowana jako naklejka.'),
})
