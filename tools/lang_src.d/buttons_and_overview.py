# -*- coding: utf-8 -*-
"""Icons where words stood, and the account page's Overview in cards (1.71.0, part A)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

An icon button carries two strings: its NAME, which a screen reader says (aria-label -- for most of them
the word the button used to show, already in the dictionary), and its EXPLANATION, which the site's
tooltip shows when the button is pointed at or focused (data-tip). Only the explanations that did not
exist yet are here. They say what the press does, in the imperative the site's other tooltips use
("Copy the magnet link", "Skopiuj link magnet").

The Overview's cards each get a heading and a line under it that says what the card is for. The Polish
lines avoid the forms that have to guess somebody's gender, as the rest of the account page does: they
speak of the account and of what others see ("widzą inni"), not of what "you did".
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the Info panel's description actions (assets/js/app.js, renderContentExtras()) ─────────────────
add('js.app', {
    # the first words of a description; the other three actions had their explanations already
    'desc_add_title': ('Write the first description of this torrent: what it is, and where it came from',
                       'Napisz pierwszy opis tego torrenta: czym jest i skąd pochodzi'),
})

# ── the account page's Overview: the line under each card's heading (templates/pages/account.php) ───
# The capitalised Ty / Twój of the rest of the account page's Polish.
add('account', {
    'card_profile_desc': ('What the site keeps about this account.', 'Co serwis przechowuje o tym koncie.'),
    # the first sentence the Picture and Cover notes used to open with (profile_media.py)
    'card_avatar_desc': ('Shown beside your name across the site.', 'Widoczne obok Twojej nazwy w całym serwisie.'),
    'card_cover_desc': ('The wide image across the top of your profile.', 'Szeroki obraz u góry Twojego profilu.'),
    'card_mail_desc': ('The e-mails this site may send to your address.', 'Wiadomości e-mail, które serwis może wysyłać na Twój adres.'),
    'card_groups_desc': ('What your account may do here comes from these groups.', 'Od tych grup zależy, co Twoje konto może tutaj robić.'),
    'card_privacy_desc': ('What other members see of you, and where your name appears.', 'Co widzą o Tobie inni i gdzie pojawia się Twoja nazwa.'),
    'card_bridge_desc': ('The accounts on other sites this one is linked to.', 'Konta w innych serwisach, z którymi to konto jest połączone.'),
    # the bottom card: with the second factor offered, and without it (only the signed-in browsers)
    'card_security_desc': (
        'Who else could sign in as you: a second step at sign-in, and the browsers that stay signed in.',
        'Kto jeszcze mógłby zalogować się jako Ty: drugi krok przy logowaniu i przeglądarki, które pozostają zalogowane.'),
    'card_security_desc_sessions': (
        'Who else could sign in as you: the browsers that stay signed in.',
        'Kto jeszcze mógłby zalogować się jako Ty: przeglądarki, które pozostają zalogowane.'),
})

# ── a conversation's head (assets/js/people.js) ──────────────────────────────────────────────────────
add('js.pm', {
    'back_title': ('Back to your messages', 'Wróć do wiadomości'),
    # op 'hide': the thread leaves the inbox until somebody writes in it again; nothing is deleted
})
