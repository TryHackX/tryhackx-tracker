# -*- coding: utf-8 -*-
"""Notifications in the RECIPIENT's language (1.74.0, QUAL-18)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

Until 1.74.0 these notifications were English literals in the code (the groups, the e-mail address, the
password, the welcome), whatever the language of the account they were written to. They are written now
with langFor(recipientLang(...), ...) -- includes/users.php says which language that is and why. A
notification is stored as words, so the language is decided when it is written, by whom it is FOR.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the groups: granted, taken away, run out, about to run out ──────────────
# :group is the group's name as the operator wrote it; :from / :until the moments as the database holds them.
add('notify', {
    'group_granted':                ('You are now in the ":group" group',
                                     'Jesteś teraz w grupie „:group”'),
    'group_granted_permanent':      ('Access granted permanently.',
                                     'Dostęp przyznany na stałe.'),
    'group_granted_until':          ('Access granted until :until.',
                                     'Dostęp przyznany do :until.'),
    'group_granted_from_permanent': ('Access granted from :from, permanently.',
                                     'Dostęp przyznany od :from, na stałe.'),
    'group_granted_from_until':     ('Access granted from :from until :until.',
                                     'Dostęp przyznany od :from do :until.'),
    'group_note':                   ('Note: :note',
                                     'Notatka: :note'),
    'group_revoked':                ('Your ":group" group access was removed',
                                     'Odebrano Ci dostęp do grupy „:group”'),
    'group_expired':                ('Your ":group" group access expired',
                                     'Wygasł Twój dostęp do grupy „:group”'),
    'group_expiring':               ('Your ":group" group access expires soon',
                                     'Twój dostęp do grupy „:group” wkrótce wygaśnie'),
    'group_expiring_body':          ('Access ends :until.',
                                     'Dostęp kończy się :until.'),
})

# ── the account itself: its address, its password, its first day ───────────
add('notify', {
    'email_removed':          ('Your email address was removed',
                               'Usunięto Twój adres e-mail'),
    'email_removed_body':     ('Confirmed from your previous address.',
                               'Potwierdzono z poprzedniego adresu.'),
    'email_changed':          ('Your email address was changed',
                               'Zmieniono Twój adres e-mail'),
    'email_changed_body':     ('New address: :email (verified).',
                               'Nowy adres: :email (potwierdzony).'),
    'email_set':              ('Your email was set',
                               'Ustawiono Twój adres e-mail'),
    'email_set_body':         ('A verification link was sent to the new address.',
                               'Na nowy adres wysłano link potwierdzający.'),
    'email_set_admin_body':   ('Set from the admin panel.',
                               'Ustawiony w panelu administracyjnym.'),
    'password_reset':         ('Your password was reset',
                               'Zresetowano Twoje hasło'),
    'password_reset_body':    ('If this was not you, contact the site admin.',
                               'Jeśli to nie Ty, skontaktuj się z administratorem strony.'),
    'password_changed':       ('Your password was changed',
                               'Zmieniono Twoje hasło'),
    'password_changed_body':  ('If this was not you, reset it immediately and contact the site admin.',
                               'Jeśli to nie Ty, natychmiast je zresetuj i skontaktuj się z administratorem strony.'),
    'welcome':                ('Welcome to :site!',
                               'Witaj w :site!'),
    'welcome_body':           ('Your account is ready. Your groups and their expiry dates are listed on this page.',
                               'Twoje konto jest gotowe. Twoje grupy i daty ich wygaśnięcia są wymienione na tej stronie.'),
    'welcome_api_body':       ('Your account was created.',
                               'Twoje konto zostało utworzone.'),
    'welcome_api_generated_body': ('Your account was created — please change the generated password after your first sign-in.',
                                   'Twoje konto zostało utworzone — po pierwszym zalogowaniu zmień wygenerowane hasło.'),
})

# ── a comment that is gone (PRIV-1): the copies in other people's notifications follow it ──
# A notification about a comment quotes its first words (notify.comment_body). When the comment is deleted —
# by its author or a moderator — the quote in every copy becomes this; when its author's account is deleted,
# the title that named them becomes notify.comment_gone as well. :name is the torrent's name.
add('notify', {
    'comment_body_gone': ('[deleted]',
                          '[usunięto]'),
    'comment_gone':      ('A comment on ":name" — its author\'s account was deleted',
                          'Komentarz pod „:name” — konto autora zostało usunięte'),
})
