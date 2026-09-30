# -*- coding: utf-8 -*-
"""A recommended permission set for every seeded group, and a way to apply it (1.72.0, part D)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The owner: "prepare a basic default set of permissions, and set it on my production too -- there are new ones and
the admin still does not have everything". Admin -> Users -> Groups: each seeded group's "Recommended" window (what is
missing, what a reset would take away, "Add what is missing", "Reset to recommended"), the Admin group's boxes in the
group editor (every capability held by its blanket: ticked, disabled; the consent boxes a real choice), the matrix's
legend and its marks, and what api/admin/group_recommended.php answers. tools/groups.php speaks English, as the other
shell tools do.

Polish: "blanket" -- the rule that an administrator passes every check -- is "z urzędu" (by virtue of the office),
and the two kinds of permission are "uprawnienia do działania" (capabilities: what an account may do) and "zgody"
(consent: what others may see of it). Gendered forms are avoided as the rest of the site's Polish does; counts stand
in brackets or after a colon, so no noun has to agree with a number.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the page (templates/admin/users.php): the window's frame and the matrix's legend ──────────────────────────
add('a.users', {
    'rec_title':      ('Recommended permissions', 'Zalecane uprawnienia'),
    'rec_add':        ('Add what is missing', 'Dodaj brakujące'),
    'rec_reset':      ('Reset to recommended', 'Przywróć zalecane'),
    'legend_granted': ('granted to the group', 'nadane grupie'),
    'legend_blanket': ('held by the Admin group\'s blanket: its members pass every check, whatever is stored',
                       'z urzędu: członkowie grupy Admin przechodzą każde sprawdzenie, niezależnie od zapisu'),
    'legend_consent': ('consent: what others may see of a member. The blanket does not count for it; a group has to grant it',
                       'zgoda: co inni mogą zobaczyć u członka. Uprawnienie z urzędu się tu nie liczy, grupa musi ją nadać'),
    # What each seeded group's recommended set is called in the window (api/admin/group_recommended.php), in the
    # reader's language. The English is the preset's own label and line (userGroupPresets(), includes/users.php) — the
    # same words, checked by tests/groups_matrix_test.php; tools/groups.php prints the preset's.
    'rec_name_guest':       ('Guest (anonymous visitors)', 'Gość (anonimowi odwiedzający)'),
    'rec_about_guest':      ('Reads the public statistics. The whitelist page, the search, the descriptions, the comments and anything that writes are the operator\'s to open.',
                             'Czyta publiczne statystyki. Stronę whitelisty, wyszukiwarkę, opisy, komentarze i wszystko, co coś zapisuje, otwiera operator.'),
    'rec_name_member':      ('Site member', 'Członek serwisu'),
    'rec_about_member':     ('The public-site features, no panel at all.', 'Funkcje strony publicznej, bez panelu.'),
    'rec_name_premium':     ('Premium', 'Premium'),
    'rec_about_premium':    ('The paid extras on top of an ordinary membership: a profile cover and emotes of their own.',
                             'Płatne dodatki do zwykłego członkostwa: okładka profilu i własne emotki.'),
    'rec_name_moderator':   ('Moderator', 'Moderator'),
    'rec_about_moderator':  ('Works the report queue and the whitelist. No settings, no backups, no log, no message queue.',
                             'Obsługuje kolejkę zgłoszeń i whitelistę. Bez ustawień, kopii zapasowych, logu i kolejki wiadomości.'),
    'rec_name_admin':       ('Every registered permission', 'Każde zarejestrowane uprawnienie'),
    'rec_about_admin':      ('Every capability, which the Admin group holds by its blanket anyway, and the consent a person has to give.',
                             'Każde uprawnienie do działania, które grupa Admin i tak ma z urzędu, oraz zgody, które trzeba dać.'),
})

# ── assets/js/admin-users.js ─────────────────────────────────────────────────────────────────────────────────
add('js.users', {
    # the row's button, and the window
    'rec_btn':           ('Recommended permissions: what is missing, and what a reset would take away',
                          'Zalecane uprawnienia: czego brakuje i co zabrałoby przywrócenie'),
    'rec_title':         ('Recommended — :name', 'Zalecane — :name'),
    'rec_set':           ('Recommended for this group: :label (:n).', 'Zalecane dla tej grupy: :label (:n).'),
    'rec_add_head':      ('Add what is missing (:n)', 'Brakujące do dodania (:n)'),
    'rec_remove_head':   ('A reset would also remove (:n)', 'Przywrócenie usunęłoby też (:n)'),
    'rec_none':          ('nothing', 'nic'),
    'rec_exact':         ('The group holds exactly the recommended set.', 'Grupa ma dokładnie zalecany zestaw.'),
    'rec_blanket':       ('The Admin group never loses a capability: its members pass every check whatever is stored, '
                          'so adding one only makes the stored list — and the matrix — say so.',
                          'Grupa Admin nigdy nie traci uprawnień do działania: jej członkowie przechodzą każde sprawdzenie '
                          'niezależnie od zapisu, więc dodanie ich sprawia tylko, że zapisana lista — i macierz — to pokazują.'),
    'rec_consent_label': ('Also give the consent: :ids', 'Nadaj też zgody: :ids'),
    'rec_consent_why':   ('Consent, not power: these say what others may see of an administrator — their favourites, '
                          'lists, likes, descriptions and registered torrents on their profile. The blanket does not '
                          'count for them, and each administrator\'s own switches still decide.',
                          'Zgoda, nie władza: te uprawnienia mówią, co inni mogą zobaczyć u administratora — ulubione, listy, polubienia, '
                          'opisy i zarejestrowane torrenty na profilu. Uprawnienie z urzędu się tu nie liczy, a ostatnie '
                          'słowo i tak mają własne przełączniki każdego administratora.'),
    'rec_consent_held':  ('Consent already given: :ids', 'Zgody już nadane: :ids'),
    'rec_changed':       ('The group changed since this was shown. Nothing was written — here is the difference now.',
                          'Grupa zmieniła się od chwili podglądu. Nic nie zostało zapisane — oto różnica teraz.'),
    'rec_failed':        ('The recommended set could not be applied: :error',
                          'Nie udało się zastosować zalecanego zestawu: :error'),
    # what the toast says afterwards
    'rec_added':         ('Group :name: added :n', 'Grupa :name: dodano :n'),
    'rec_reset_done':    ('Group :name: added :added, removed :removed', 'Grupa :name: dodano :added, usunięto :removed'),
    'rec_nothing':       ('Group :name: nothing to change', 'Grupa :name: nie ma nic do zmiany'),
    # the second question a reset asks
    'rec_confirm_title': ('Reset to the recommended set?', 'Przywrócić zalecany zestaw?'),
    'rec_confirm_body':  ('This takes :n away from the group :name:', 'To zabierze grupie :name (:n):'),
    'rec_confirm_after': ('Its members (:m) lose them at once. Missing ones added with it: :missing.',
                          'Jej członkowie (:m) tracą je od razu. Brakujące dodane przy okazji: :missing.'),
    'rec_confirm_ok':    ('Reset', 'Przywróć'),
    # the group editor on the Admin group, and the marks in the editor and the matrix
    'blanket_box':       ('Held by the Admin group\'s blanket: its members pass this check whatever is ticked, so the box cannot be cleared.',
                          'Z urzędu: członkowie grupy Admin przechodzą to sprawdzenie niezależnie od zaznaczenia, więc tego pola nie da się odznaczyć.'),
    'blanket_cell':      (':group — :key: held by the blanket (its members pass every check)',
                          ':group — :key: z urzędu (członkowie przechodzą każde sprawdzenie)'),
    'consent_badge':     ('consent', 'zgoda'),
    'consent_title':     ('Consent: what others may see of a member. The Admin group\'s blanket does not count for it — a group has to grant it.',
                          'Zgoda: co inni mogą zobaczyć u członka. Uprawnienie z urzędu grupy Admin się tu nie liczy — grupa musi ją nadać.'),
    'consent_admin_line': ('Every capability of the Admin group is held by its blanket, so those boxes are ticked and cannot be cleared. '
                           'The ones left open are consent — what others may see of an administrator — and are yours to give.',
                           'Każde uprawnienie do działania grupa Admin ma z urzędu, więc te pola są zaznaczone i nie da się ich odznaczyć. '
                           'Otwarte zostały zgody — co inni mogą zobaczyć u administratora — i to ty je dajesz.'),
    'perms_admin':       ('every capability (the blanket) · consent: :list', 'każde uprawnienie do działania (z urzędu) · zgody: :list'),
    'perms_admin_none':  ('every capability (the blanket) · no consent given', 'każde uprawnienie do działania (z urzędu) · bez zgód'),
})

# ── api/admin/group_recommended.php ──────────────────────────────────────────────────────────────────────────
add('api.groups', {
    'no_recommended':       ('This group has no recommended set: only the seeded groups (guest, member, premium, moderator, admin) have one.',
                             'Ta grupa nie ma zalecanego zestawu: mają go tylko grupy wbudowane (guest, member, premium, moderator, admin).'),
    'rec_bad_mode':         ('The mode is "add" or "reset".', 'Tryb to „add” albo „reset”.'),
    'rec_expect':           ('Send what the preview showed: expect.add, and for a reset expect.remove as well.',
                             'Wyślij to, co pokazał podgląd: expect.add, a przy przywróceniu także expect.remove.'),
    'rec_changed':          ('The group changed since the preview; nothing was written.',
                             'Grupa zmieniła się od podglądu; nic nie zostało zapisane.'),
    'rec_admin_capability': ('The Admin group cannot lose a capability.', 'Grupa Admin nie może stracić uprawnienia do działania.'),
})
