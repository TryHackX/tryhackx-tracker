# -*- coding: utf-8 -*-
"""The Moderation page, a member's standing and the automatic penalties (1.75.0, parts C and D)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

includes/moderation.php (userModerate(), the automatic rules, accountStandingContext(), the notices
notify.auto_* / notify.warn_revoked*), its three endpoints (api/admin/fetch_moderation.php,
moderation_action.php, moderation_rules.php: api.moderation.*), the page templates/admin/moderation.php
(a.moderation.*) and assets/js/admin-moderation.js (js.moderation.*), the Moderation block of the Users
page's edit window (a.users.mod_*, js.users.*), the account page's "Account standing" card
(account.standing*) and the Settings switch (settings.auto_penalties_enabled). The permission words
(perm.panel.moderation.*) are in permissions.py and the header button's in admin_header.py.

A counted word has its three forms in Polish (X_one, X_few, X_many -- includes/lang.php langPluralKey()); a
number elsewhere is a label's value ("ostrzeżeń: 3"), as tests/lang_plural_test.php wants.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the member is told: an automatic penalty, a withdrawn warning, a warning given by hand ─────────────────────
# :warnings and :window are phrases of their own ("3 warnings", "30 days") in the member's language, by its plural rule
# (moderationCounted() -> plural()); the Polish "window" is in the genitive, after "w ciągu".
add('notify', {
    'warn_about_manual':        ('Warning from a moderator',
                                 'Ostrzeżenie od moderatora'),
    'auto_warnings_one':        ('1 warning', '1 ostrzeżenie'),
    'auto_warnings_few':        (':n warnings', ':n ostrzeżenia'),
    'auto_warnings_many':       (':n warnings', ':n ostrzeżeń'),
    'auto_window_one':          ('1 day', '1 dnia'),
    'auto_window_few':          (':n days', ':n dni'),
    'auto_window_many':         (':n days', ':n dni'),
    'auto_muted':               ('Automatic penalty: :warnings within :window — you are silenced until :date',
                                 'Kara automatyczna: :warnings w ciągu :window — jesteś wyciszony do :date'),
    'auto_muted_forever':       ('Automatic penalty: :warnings within :window — you are silenced until a moderator lifts it',
                                 'Kara automatyczna: :warnings w ciągu :window — jesteś wyciszony do odwołania przez moderatora'),
    'auto_banned':              ('Automatic penalty: :warnings within :window — your account is banned until :date',
                                 'Kara automatyczna: :warnings w ciągu :window — Twoje konto jest zablokowane do :date'),
    'auto_banned_forever':      ('Automatic penalty: :warnings within :window — your account is banned until a moderator lifts it',
                                 'Kara automatyczna: :warnings w ciągu :window — Twoje konto jest zablokowane do odwołania przez moderatora'),
    'auto_body':                ('This was applied by the site itself, because of the warnings on your account. You can see the ones that count under “Account standing” on your account page.',
                                 'Zastosował to sam serwis, z powodu ostrzeżeń na Twoim koncie. Te, które się liczą, zobaczysz w „Stanie konta” na stronie swojego konta.'),
    'warn_revoked':             ('A warning on your account was withdrawn',
                                 'Ostrzeżenie na Twoim koncie zostało wycofane'),
    'warn_revoked_body':        ('The warning of :date no longer counts.',
                                 'Ostrzeżenie z dnia :date już się nie liczy.'),
})

# ── the account page: Account standing ─────────────────────────────────────────────────────────────────────────
add('account', {
    'standing':               ('Account standing', 'Stan konta'),
    'standing_desc':          ('What moderators have done to this account and what still counts against it. Only you see this.',
                               'Co moderatorzy zrobili z tym kontem i co jeszcze się na nim liczy. Widzisz to tylko Ty.'),
    'standing_muted':         ('You are silenced until :date: you cannot write messages, shouts or comments, and you can still read.',
                               'Jesteś wyciszony do :date: nie możesz pisać wiadomości, wpisów w shoutboksie ani komentarzy, nadal możesz czytać.'),
    'standing_muted_forever': ('You are silenced until a moderator lifts it: you cannot write messages, shouts or comments, and you can still read.',
                               'Jesteś wyciszony do odwołania przez moderatora: nie możesz pisać wiadomości, wpisów w shoutboksie ani komentarzy, nadal możesz czytać.'),
    'standing_muted_nodate':   ('You are silenced until a moderator lifts it.',
                                'Jesteś wyciszony do odwołania przez moderatora.'),
    'standing_banned':        ('Your account is banned until :date.',
                               'Twoje konto jest zablokowane do :date.'),
    'standing_banned_forever': ('Your account is banned until a moderator lifts it.',
                                'Twoje konto jest zablokowane do odwołania przez moderatora.'),
    'standing_banned_nodate':  ('Your account is banned until the site’s administrator lifts it.',
                                'Twoje konto jest zablokowane do odwołania przez administratora serwisu.'),
    'standing_count':         ('Warnings that count: :n', 'Ostrzeżenia, które się liczą: :n'),
    'standing_act_warn':      ('Warning', 'Ostrzeżenie'),
    'standing_act_remove':    ('Words removed', 'Usunięta treść'),
    'standing_act_mute':      ('Silenced', 'Wyciszenie'),
    'standing_act_ban':       ('Banned', 'Blokada'),
    'standing_act_auto_mute': ('Automatic penalty: silenced', 'Kara automatyczna: wyciszenie'),
    'standing_act_auto_ban':  ('Automatic penalty: banned', 'Kara automatyczna: blokada'),
    'standing_auto':          ('Applied by the site: :warnings within :window.',
                               'Zastosowane przez serwis: :warnings w ciągu :window.'),
})

# ── Settings: the switch ───────────────────────────────────────────────────────────────────────────────────────
add('settings', {
    'auto_penalties_enabled':      ('Automatic penalties',
                                    'Kary automatyczne'),
    'auto_penalties_enabled_hint': ('When a member’s counted warnings reach a rule’s number inside its window, they are silenced or banned by themselves. The rules are on the <a href=":url">Moderation page</a>. <strong>Off</strong> until you switch it on; staff are never touched.',
                                    'Gdy liczone ostrzeżenia członka osiągną liczbę z reguły w jej okresie, zostaje on sam wyciszony albo zablokowany. Reguły są na <a href=":url">stronie Moderacja</a>. <strong>Wyłączone</strong>, dopóki ich nie włączysz; obsługa nigdy nie jest ruszana.'),
})

# ── api/admin/moderation_rules.php: a rule that is refused ─────────────────────────────────────────────────────
add('api.moderation', {
    'rules_invalid':  ('The rules could not be read.', 'Nie udało się odczytać reguł.'),
    'rules_too_many': ('At most :max rules.', 'Najwyżej reguł: :max.'),
    'rule_warnings':  ('Rule :n: the number of warnings must be 1–100.', 'Reguła :n: liczba ostrzeżeń musi mieścić się w przedziale 1–100.'),
    'rule_window':    ('Rule :n: the window must be 1–365 days.', 'Reguła :n: okres musi trwać od 1 do 365 dni.'),
    'rule_action':    ('Rule :n: choose silence or ban.', 'Reguła :n: wybierz wyciszenie albo blokadę.'),
    'rule_duration':  ('Rule :n: the duration must be 0–365 days (0 = until somebody lifts it).', 'Reguła :n: czas trwania musi wynosić 0–365 dni (0 = do odwołania).'),
})

# ── the page: templates/admin/moderation.php ───────────────────────────────────────────────────────────────────
add('a.moderation', {
    'title':            ('Moderation', 'Moderacja'),
    'subtitle':         ('who is silenced or banned, every warning, the automatic penalties',
                         'kto jest wyciszony lub zablokowany, każde ostrzeżenie, kary automatyczne'),
    'tab_restricted':   ('Restricted now', 'Obecnie ograniczeni'),
    'tab_warnings':     ('Warnings', 'Ostrzeżenia'),
    'tab_auto':         ('Automatic penalties', 'Kary automatyczne'),
    'restricted_note':  ('Members silenced or banned at this moment, the newest penalty first. A silence or a ban with a date ends by itself; here you can end it sooner.',
                         'Członkowie wyciszeni lub zablokowani w tej chwili, od najnowszej kary. Wyciszenie lub blokada z datą kończy się samo; tutaj możesz zakończyć je wcześniej.'),
    'f_member':         ('Member', 'Członek'),
    'f_moderator':      ('Moderator', 'Moderator'),
    'f_action':         ('What was done', 'Co zrobiono'),
    'f_action_all':     ('Everything', 'Wszystko'),
    'f_action_auto':    ('Automatic penalties', 'Kary automatyczne'),
    'f_state':          ('State', 'Stan'),
    'f_state_all':      ('Active and revoked', 'Aktywne i odwołane'),
    'f_state_active':   ('Active', 'Aktywne'),
    'f_state_revoked':  ('Revoked', 'Odwołane'),
    'warnings_note':    ('Every warning ever given — and every silent action and automatic penalty. A revoked warning stays here, struck through, and counts nowhere. A warning <strong>counts</strong> (toward the number the member is told, the Users list and the automatic penalties) when it was <strong>loud</strong>, is <strong>not revoked</strong> and is not an automatic penalty itself; removed words, silences and bans count too, when loud.',
                         'Każde kiedykolwiek wystawione ostrzeżenie — oraz każde ciche działanie i kara automatyczna. Odwołane ostrzeżenie zostaje tutaj, przekreślone, i nigdzie się nie liczy. Ostrzeżenie <strong>się liczy</strong> (do liczby, którą widzi członek, do listy Użytkowników i do kar automatycznych), gdy było <strong>głośne</strong>, <strong>nie jest odwołane</strong> i nie jest samo karą automatyczną; usunięta treść, wyciszenia i blokady też się liczą, gdy są głośne.'),
    'more':             ('Show more', 'Pokaż więcej'),
    'auto_head':        ('The ladder', 'Drabina kar'),
    'auto_intro':       ('When a member’s <strong>counted warnings</strong> reach a rule’s number inside its window, the strongest matching rule silences or bans them by itself. It runs right after a loud warning is given, only while it is switched on, and a member who keeps being warned keeps being silenced: a rule that fires again extends the penalty from that moment.',
                         'Gdy <strong>liczone ostrzeżenia</strong> członka osiągną liczbę z reguły w jej okresie, najsilniejsza pasująca reguła sama go wycisza lub blokuje. Działa zaraz po wystawieniu głośnego ostrzeżenia i tylko wtedy, gdy jest włączona; członek, który wciąż dostaje ostrzeżenia, jest wciąż wyciszany: reguła, która zadziała ponownie, przedłuża karę od tej chwili.'),
    'auto_counts':      ('What counts: a warning, removed words, a silence or a ban — when it was loud (the member was told), not revoked, and not itself an automatic penalty.',
                         'Co się liczy: ostrzeżenie, usunięta treść, wyciszenie lub blokada — gdy było głośne (członek został poinformowany), nie jest odwołane i samo nie jest karą automatyczną.'),
    'auto_never':       ('What never counts: a report (a crowd of reports cannot silence anybody), a silent action, a revoked warning, an automatic penalty.',
                         'Co nigdy się nie liczy: zgłoszenie (tłum zgłoszeń nikogo nie wycisza), ciche działanie, odwołane ostrzeżenie, kara automatyczna.'),
    'auto_strongest':   ('The strongest rule wins (a ban over a silence, then the one that ends later) and is applied only if it ends later than what the member already has: it never shortens, never turns a ban into a silence, and never touches a ban that has no end date.',
                         'Wygrywa najsilniejsza reguła (blokada przed wyciszeniem, potem ta, która kończy się później) i jest stosowana tylko wtedy, gdy kończy się później niż to, co członek już ma: nigdy nie skraca, nie zamienia blokady w wyciszenie i nie rusza blokady bez daty końca.'),
    'auto_staff':       ('Staff are never touched — whoever can open the panel, an unverified moderator included.',
                         'Obsługa nigdy nie jest ruszana — każdy, kto może otworzyć panel, także moderator z niepotwierdzonym adresem.'),
    'auto_on':          ('The automatic penalties are switched on', 'Kary automatyczne są włączone'),
    'r_enabled':        ('On', 'Wł.'),
    'r_warnings':       ('Counted warnings', 'Liczone ostrzeżenia'),
    'r_window':         ('Within (days)', 'W ciągu (dni)'),
    'r_action':         ('Then', 'Wtedy'),
    'r_duration':       ('For (days; 0 = until lifted)', 'Na (dni; 0 = do odwołania)'),
    'r_add':            ('Add a rule', 'Dodaj regułę'),
    'r_save':           ('Save the rules', 'Zapisz reguły'),
    'r_readonly':       ('You may read the rules; changing them and the switch needs the “edit the automatic penalties” permission.',
                         'Możesz czytać reguły; ich zmiana i przełącznik wymagają uprawnienia „edycja kar automatycznych”.'),
    'auto_log':         ('What the rules did', 'Co zrobiły reguły'),
    'lift_tell':        ('Tell the member', 'Powiadom członka'),
    'lift_note':        ('A note for the member', 'Notatka dla członka'),
    'lift_go':          ('Lift it', 'Zdejmij'),
    'revoke_title':     ('Revoke a warning of', 'Odwołanie ostrzeżenia dla'),
    'revoke_lead':      ('The warning stays in the log, struck through, and stops counting — toward the number the member is told, the Users list and the automatic penalties. It does not lift a silence or a ban by itself.',
                         'Ostrzeżenie zostaje w logu, przekreślone, i przestaje się liczyć — do liczby, którą widzi członek, do listy Użytkowników i do kar automatycznych. Samo nie zdejmuje wyciszenia ani blokady.'),
    'revoke_note':      ('Why it is revoked', 'Dlaczego jest odwołane'),
    'revoke_tell':      ('Tell the member it was withdrawn (with the note)', 'Powiadom członka, że ostrzeżenie wycofano (wraz z notatką)'),
    'revoke_lift':      ('Also lift what is running now', 'Zdejmij też to, co obowiązuje teraz'),
    'revoke_go':        ('Revoke it', 'Odwołaj'),
})

# ── the page's script: assets/js/admin-moderation.js ───────────────────────────────────────────────────────────
add('js.moderation', {
    'act_warn':       ('Warning', 'Ostrzeżenie'),
    'act_remove':     ('Words removed', 'Usunięta treść'),
    'act_mute':       ('Silenced', 'Wyciszenie'),
    'act_ban':        ('Banned', 'Blokada'),
    'mark_loud':      ('told', 'z powiadomieniem'),
    'mark_silent':    ('silent', 'po cichu'),
    'mark_auto':      ('automatic', 'automatyczna'),
    'mark_revoked':   ('revoked', 'odwołane'),
    'kind_comment':   ('about a comment', 'dotyczy komentarza'),
    'kind_description': ('about a description', 'dotyczy opisu'),
    'kind_shout':     ('about a shout', 'dotyczy wpisu w shoutboksie'),
    'kind_message':   ('about a private message', 'dotyczy prywatnej wiadomości'),
    'kind_manual':    ('given by hand', 'nadane ręcznie'),
    'kind_auto':      ('applied by the rules', 'nałożona przez reguły'),
    'by':             ('by :name', 'przez :name'),
    'by_auto':        ('automatic', 'automatycznie'),
    'auto_reason':    ('warnings: :n (window in days: :days)', 'ostrzeżenia: :n (okres w dniach: :days)'),
    'no_reason':      ('(no reason written)', '(nie podano powodu)'),
    'no_record':      ('(the log has no entry for it)', '(w logu nie ma na to wpisu)'),
    'dateless_note':  ('Set from the Users page with no end date: it is lifted there, or here with the Users-page permission.',
                       'Nałożona na stronie Użytkownicy bez daty końca: zdejmuje się ją tam albo tutaj, z uprawnieniem do strony Użytkownicy.'),
    'staff':          ('staff', 'obsługa'),
    'warned_n':       ('warnings that count: :n', 'ostrzeżeń, które się liczą: :n'),
    'told':           ('the member was told', 'członek został poinformowany'),
    'silent_mark':    ('given silently', 'nałożona po cichu'),
    'chip_banned':    ('Banned', 'Zablokowany'),
    'chip_muted':     ('Silenced', 'Wyciszony'),
    'until':          ('until :date', 'do :date'),
    'until_lifted':   ('until somebody lifts it', 'do odwołania'),
    'until_lifted_short': ('until lifted', 'do odwołania'),
    'until_dateless': ('no end date', 'bez daty końca'),
    'none_restricted': ('Nobody is silenced or banned right now.', 'Nikt nie jest teraz wyciszony ani zablokowany.'),
    'restricted_cut': ('Only the newest 1 000 are listed.', 'Wyświetlono tylko 1 000 najnowszych.'),
    'none_warnings':  ('Nothing in the log matches.', 'Nic w logu nie pasuje do filtrów.'),
    'none_auto':      ('The rules have not done anything yet.', 'Reguły jeszcze niczego nie zrobiły.'),
    'n_rows':         ('rows: :n', 'wpisów: :n'),
    'all_warnings':   ('All warnings of this member', 'Wszystkie ostrzeżenia tego członka'),
    'open_user':      ('Open the user', 'Otwórz użytkownika'),
    'lift_mute':      ('Lift the silence', 'Zdejmij wyciszenie'),
    'lift_ban':       ('Lift the ban', 'Zdejmij blokadę'),
    'lift_title_mute': ('Lift the silence of', 'Zdjęcie wyciszenia z'),
    'lift_title_ban': ('Lift the ban of', 'Zdjęcie blokady z'),
    'lift_lead_mute': ('The member can write again at once.', 'Członek może od razu znowu pisać.'),
    'lift_lead_ban':  ('The member can sign in again at once.', 'Członek może od razu znowu się zalogować.'),
    'lift_note_ph':   ('A note for the member (optional)…', 'Notatka dla członka (opcjonalnie)…'),
    'lifted_mute':    ('The silence of :user is lifted.', 'Wyciszenie :user zdjęte.'),
    'lifted_ban':     ('The ban of :user is lifted.', 'Blokada :user zdjęta.'),
    'lift_noop':      ('There was nothing to lift — it had already ended.', 'Nie było czego zdejmować — już się skończyło.'),
    'revoke':         ('Revoke', 'Odwołaj'),
    'revoke_note_ph': ('Why it is revoked (optional)…', 'Dlaczego jest odwołane (opcjonalnie)…'),
    'revoke_lift':    ('Also lift what is running now', 'Zdejmij też to, co obowiązuje teraz'),
    'revoke_running_mute': ('silenced until :date', 'wyciszenie do :date'),
    'revoke_running_ban':  ('banned until :date', 'blokada do :date'),
    'revoke_running_ban_dateless': ('banned with no end date', 'blokada bez daty końca'),
    'revoked_by':     ('Revoked by :name on :at.', 'Odwołane przez :name, :at.'),
    'revoked_done':   ('The warning is revoked.', 'Ostrzeżenie odwołano.'),
    'revoked_skipped': ('Revoked — but the ban has no end date, and lifting it needs the Users-page permission.',
                        'Odwołano — ale blokada nie ma daty końca, a jej zdjęcie wymaga uprawnienia do strony Użytkownicy.'),
    'r_enabled':      ('On', 'Wł.'),
    'r_warnings':     ('Counted warnings', 'Liczone ostrzeżenia'),
    'r_window':       ('Window in days', 'Okres w dniach'),
    'r_action':       ('Action', 'Działanie'),
    'r_duration':     ('Duration in days', 'Czas trwania w dniach'),
    'r_mute':         ('silence', 'wyciszenie'),
    'r_ban':          ('ban', 'blokada'),
    'r_remove':       ('Remove this rule', 'Usuń tę regułę'),
    'no_rules':       ('There are no rules: nobody is ever penalised automatically.', 'Nie ma reguł: nikt nie jest karany automatycznie.'),
    'rules_saved':    ('The rules are saved.', 'Reguły zapisano.'),
    'auto_state_on':  ('The ladder is ON.', 'Drabina jest WŁĄCZONA.'),
    'auto_state_off': ('The ladder is OFF: nothing is applied automatically.', 'Drabina jest WYŁĄCZONA: nic nie jest nakładane automatycznie.'),
    # the Users page's edit window and its confirmations
    'reason_ph':      ('The reason the member reads (needed for a warning)…', 'Powód, który przeczyta członek (wymagany przy ostrzeżeniu)…'),
    'confirm_title':  ('Please confirm', 'Potwierdź'),
    'confirm_ok':     ('Do it', 'Wykonaj'),
    'sure_warn':      ('Warn :user?', 'Ostrzec :user?'),
    'sure_mute':      ('Silence :user?', 'Wyciszyć :user?'),
    'sure_ban':       ('Ban :user?', 'Zablokować :user?'),
    'done_warn':      (':user was warned.', ':user otrzymał ostrzeżenie.'),
    'done_mute':      (':user is silenced.', ':user jest wyciszony.'),
    'done_ban':       (':user is banned.', ':user jest zablokowany.'),
    'done_auto':      ('The automatic penalties applied a penalty as well.', 'Kary automatyczne nałożyły dodatkowo karę.'),
    'err_failed':     ('It did not go through. Try again.', 'Nie udało się. Spróbuj ponownie.'),
    'err_unknown_action': ('Nothing like that can be done.', 'Nie można tego zrobić.'),
    'err_no_author':  ('There is no such account.', 'Nie ma takiego konta.'),
    'err_target_is_staff': ('Staff cannot be warned, silenced or banned from here.', 'Obsługi nie można ostrzegać, wyciszać ani blokować stąd.'),
    'err_target_is_you': ('That is your own account.', 'To Twoje własne konto.'),
    'err_reason_required': ('A warning needs a reason — it is what the member reads.', 'Ostrzeżenie wymaga powodu — to właśnie przeczyta członek.'),
    'err_ban_is_permanent': ('This ban has no end date: it was set on the Users page and is lifted there (or here with that page’s permission).',
                             'Ta blokada nie ma daty końca: nałożono ją na stronie Użytkownicy i tam się ją zdejmuje (albo tutaj, z uprawnieniem do tamtej strony).'),
    'err_not_found':  ('That is gone already.', 'Tego już nie ma.'),
    'err_already_revoked': ('That warning is already revoked.', 'To ostrzeżenie jest już odwołane.'),
})

# ── the Users page: the status filter, the until-dates, the edit window's Moderation block ────────────────────
add('a.users', {
    'muted':          ('Silenced now', 'Wyciszeni teraz'),
    'warned':         ('With warnings that count', 'Z liczonymi ostrzeżeniami'),
    'mod_head':       ('Moderation', 'Moderacja'),
    'mod_warn':       ('Warn', 'Ostrzeż'),
    'mod_mute_group': ('Silence', 'Wyciszenie'),
    'mod_ban_group':  ('Ban', 'Blokada'),
    'mod_apply':      ('Apply', 'Zastosuj'),
    'mod_hint':       ('Acts at once (not part of Save). Silently the member is told nothing; as a warning they read your reason, and it counts toward the automatic penalties. A ban with no end date is lifted by the status box above.',
                       'Działa od razu (nie jest częścią zapisu). Po cichu członek nic nie dostaje; jako ostrzeżenie czyta Twój powód, a ostrzeżenie liczy się do kar automatycznych. Blokadę bez daty końca zdejmuje pole statusu powyżej.'),
})
add('js.users', {
    'ban_no_end':       ('banned, no end date', 'blokada bez daty końca'),
    'until_lifted':     ('banned until lifted', 'blokada do odwołania'),
    'ban_until':        ('banned until :date', 'blokada do :date'),
    'mute_lifted':      ('silenced until lifted', 'wyciszenie do odwołania'),
    'mute_until':       ('silenced until :date', 'wyciszenie do :date'),
    'ban_no_end_long':  ('Banned with no end date — lifted with the status box above.', 'Zablokowany bez daty końca — zdejmuje się to polem statusu powyżej.'),
    'until_lifted_long': ('Banned until a moderator lifts it.', 'Zablokowany do odwołania przez moderatora.'),
    'ban_until_long':   ('Banned until :date.', 'Zablokowany do :date.'),
    'mute_lifted_long': ('Silenced until a moderator lifts it.', 'Wyciszony do odwołania przez moderatora.'),
    'mute_until_long':  ('Silenced until :date.', 'Wyciszony do :date.'),
    'mod_clean':        ('Not silenced, not banned.', 'Niewyciszony, niezablokowany.'),
    'nothing_changed':  ('Nothing was changed.', 'Nic nie zmieniono.'),
})
