# -*- coding: utf-8 -*-
"""Who sees a list: private, friends, public (1.72.0)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

A list had one switch, public or not, and on somebody else's profile a public list was tinted green and
badged "public" -- which told a visitor nothing (a visitor only ever sees the lists they may see) and turned
the page into a traffic light. The state is its owner's business now: shown on the owner's own cards only,
and a third answer beside the two, "friends" -- the owner and the members they are friends with.

Polish: the three answers are said of a list ("lista"), so the adjectives agree with it -- prywatna,
publiczna -- and the middle one is "dla znajomych" (for friends), which needs no agreement.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the Edit window's third question (templates/partials/list_edit.php) ──────
add('lists', {
    'vis_label': ('Who can see it', 'Kto ją widzi'),
    'vis_private': ('Private', 'Prywatna'),
    'vis_friends': ('Friends', 'Dla znajomych'),
    'vis_public': ('Public', 'Publiczna'),
    'vis_private_hint': ('Only you see it.', 'Widzisz ją tylko Ty.'),
    'vis_friends_hint': (
        'You and your friends — on your profile, through its link and in a torrent’s “Who has this”. '
        'Nobody else learns that it exists.',
        'Ty i Twoi znajomi — na Twoim profilu, pod jej linkiem i w „Kto ma to u siebie” torrenta. '
        'Nikt inny nie dowie się, że istnieje.'),
    'vis_public_hint': (
        'Everybody who may see profiles — on your profile, through its link and in a torrent’s “Who has this”.',
        'Każdy, kto może oglądać profile — na Twoim profilu, pod jej linkiem i w „Kto ma to u siebie” torrenta.'),
    'vis_why_sharing_off': (
        'This site keeps every list private: sharing lists is switched off.',
        'Ta strona trzyma każdą listę jako prywatną: udostępnianie list jest wyłączone.'),
    'vis_why_friends_off': (
        'For friends: the friends feature is switched off on this site.',
        'Dla znajomych: funkcja znajomych jest na tej stronie wyłączona.'),
    'vis_why_no_friends': (
        'For friends: none of your groups may use the friends feature (<code>friends.use</code>).',
        'Dla znajomych: żadna z Twoich grup nie ma dostępu do funkcji znajomych (<code>friends.use</code>).'),
    'vis_why_no_grant': (
        'Public: none of your groups grants <code>:perm</code>. An administrator grants it in the panel, '
        'under Users → Groups.',
        'Publiczna: żadna z Twoich grup nie ma uprawnienia <code>:perm</code>. Administrator nadaje je w panelu, '
        'w Użytkownicy → Grupy.'),
    'vis_section_hidden': (
        '“Show my lists on my profile” is off (Account → Privacy): nobody else sees any of your lists, '
        'your friends included.',
        '„Pokazuj moje listy na moim profilu” jest wyłączone (Konto → Prywatność): nikt inny nie widzi żadnej '
        'z Twoich list, także Twoi znajomi.'),
})

# ── the owner's cards and the list's window (assets/js/favourites.js) ─────────
add('js.lists', {
    'vis_private': ('Private', 'Prywatna'),
    'vis_friends': ('Friends', 'Dla znajomych'),
    'vis_public': ('Public', 'Publiczna'),
    # the state chip on the owner's card is a button: it opens Edit on this question
    'vis_change': ('Who can see this list — change it', 'Kto widzi tę listę — zmień to'),
    # what a screen reader hears for the chip: the question and its answer
    'vis_aria': ('Who can see it: :state', 'Kto ją widzi: :state'),
})

# ── what the save answers (api/user_lists.php, includes/lists.php) ───────────
add('api.lists', {
    'bad_visibility': (
        'That is not one of the three answers: private, friends or public.',
        'To nie jest żadna z trzech odpowiedzi: prywatna, dla znajomych albo publiczna.'),
    'no_permission': (
        'You cannot share a list that way on this site.',
        'Na tej stronie nie możesz udostępnić listy w ten sposób.'),
})

# ── the account's privacy card (templates/pages/account.php) ─────────────────
add('account', {
    # "Show my lists on my profile" without lists.public, while the lists for friends still work
    'lists_friends_only': (
        'None of your groups grants <code>:perm</code>, so no list of yours is public — this switch shows your '
        'friends the lists you share with them. An administrator grants it in the panel, under Users → Groups.',
        'Żadna z Twoich grup nie ma uprawnienia <code>:perm</code>, więc żadna Twoja lista nie jest publiczna — ten '
        'przełącznik pokazuje Twoim znajomym listy, które im udostępniasz. Administrator nadaje je w panelu, '
        'w Użytkownicy → Grupy.'),
})
