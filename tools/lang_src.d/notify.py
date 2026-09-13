# -*- coding: utf-8 -*-
"""Notifications written to an account

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
    'notify.friend_accepted': (':user accepted your friend request',
        ':user przyjął(-ęła) Twoje zaproszenie'),
    'notify.friend_request': (':user would like to be your friend',
        ':user chce być Twoim znajomym'),
    'notify.muted': ('Your messages have been silenced',
        'Twoje wiadomości zostały wyciszone'),
    'notify.muted_forever': ('A moderator has stopped this account sending private messages. You can still read the ones you have. If you think this is a mistake, use the contact address on the site.',
        'Moderator zablokował temu kontu wysyłanie wiadomości prywatnych. Nadal możesz czytać te, które masz. Jeśli uważasz, że to pomyłka, napisz na adres kontaktowy strony.'),
    'notify.muted_until': ('A moderator has stopped this account sending private messages until :date. You can still read the ones you have.',
        'Moderator zablokował temu kontu wysyłanie wiadomości prywatnych do :date. Nadal możesz czytać te, które masz.'),
    'notify.pm_new': (':user sent you a message',
        ':user wysłał(-a) Ci wiadomość'),
    'notify.sessions_ended': ('Signed out everywhere else',
        'Wylogowano wszędzie indziej'),
    'notify.sessions_ended_body': (':n remembered devices were signed out, and every other open session stopped counting. If this was not you, change your password now.',
        'Wylogowano zapamiętanych urządzeń: :n, a każda inna otwarta sesja przestała się liczyć. Jeśli to nie ty, zmień teraz hasło.'),
    'notify.twofa_off': ('Two-factor authentication turned off',
        'Wyłączono uwierzytelnianie dwuskładnikowe'),
    'notify.twofa_off_body': ('Your account is protected by its password alone again. If this was not you, change that password now.',
        'Twoje konto znów chroni samo hasło. Jeśli to nie ty, zmień je teraz.'),
    'notify.twofa_on': ('Two-factor authentication turned on',
        'Włączono uwierzytelnianie dwuskładnikowe'),
    'notify.twofa_on_body': ('Signing in now asks for a code from your phone. Keep your recovery codes somewhere that is not the phone.',
        'Logowanie prosi teraz o kod z telefonu. Trzymaj kody zapasowe gdzieś poza telefonem.'),
    'notify.unbanned': ('Your account is active again',
        'Twoje konto znów jest aktywne'),
    'notify.unbanned_body': ('The ban on this account has been lifted.',
        'Ban na tym koncie został zdjęty.'),
    'notify.unmuted': ('Your messages are no longer silenced',
        'Twoje wiadomości nie są już wyciszone'),
    'notify.unmuted_body': ('You can send private messages again.',
        'Możesz znowu wysyłać wiadomości prywatne.'),
})
