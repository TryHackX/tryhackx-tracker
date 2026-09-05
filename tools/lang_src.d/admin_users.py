# -*- coding: utf-8 -*-
"""Admin — users and groups

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


# ── admin: users and groups ─────────────────────────────────────────────────
add('a.users', {
    # page chrome
    'title':          ('Users', 'Użytkownicy'),
    'subtitle':       ('accounts, groups &amp; permissions', 'konta, grupy i uprawnienia'),
    'accounts_head':  ('User accounts', 'Konta użytkowników'),
    'disabled_note':  ('The user system is <strong>disabled</strong> — accounts exist but nobody can '
                       'sign in. Enable it in <a href=":url">Settings &rarr; User Accounts</a>.',
                       'System użytkowników jest <strong>wyłączony</strong> — konta istnieją, ale nikt '
                       'nie może się zalogować. Włącz go w <a href=":url">Ustawieniach &rarr; Konta '
                       'użytkowników</a>.'),

    # toolbar / filters
    'search_ph':      ('Search username or email...', 'Szukaj nazwy lub e-maila...'),
    'search_clear':   ('Clear search', 'Wyczyść wyszukiwanie'),
    'status':         ('Status', 'Status'),
    'all':            ('All', 'Wszystkie'),
    'active':         ('Active', 'Aktywne'),
    'banned':         ('Banned', 'Zbanowane'),
    'group':          ('Group', 'Grupa'),
    'all_groups':     ('All groups', 'Wszystkie grupy'),
    'add':            ('Add user', 'Dodaj użytkownika'),

    # user table
    'pick_all_title': ('Select every user on this page',
                       'Zaznacz wszystkich użytkowników na tej stronie'),
    'username':       ('Username', 'Nazwa użytkownika'),
    'email':          ('Email', 'E-mail'),
    'groups':         ('Groups', 'Grupy'),
    'sort_group_title': ('Sorts by the highest-priority active group',
                         'Sortuje po aktywnej grupie o najwyższym priorytecie'),
    'created':        ('Created', 'Utworzono'),
    'last_login':     ('Last sign-in', 'Ostatnie logowanie'),
    'actions':        ('Actions', 'Akcje'),

    # write to members
    'write_head':     ('Write to members', 'Napisz do użytkowników'),
    'write_intro':    ('Two separate things, and you can send either or both. An <strong>in-app '
                       'notification</strong> appears the next time somebody opens the site and costs '
                       'nothing to send. An <strong>email</strong> leaves this machine, so it is queued '
                       'and sent a few a minute &mdash; this server has no relay in front of '
                       '<code>mail()</code>, and a burst from a domain that normally sends a handful a '
                       'day is what gets password-reset mail filed as spam.',
                       'To dwie osobne rzeczy i możesz wysłać jedną albo obie. <strong>Powiadomienie w '
                       'serwisie</strong> pojawi się, gdy ktoś następnym razem otworzy stronę, i nic nie '
                       'kosztuje. <strong>E-mail</strong> opuszcza tę maszynę, więc trafia do kolejki i '
                       'wychodzi po kilka na minutę &mdash; ten serwer nie ma żadnego relaya przed '
                       '<code>mail()</code>, a nagły wysyp z domeny, która normalnie wysyła kilka listów '
                       'dziennie, to dokładnie to, przez co maile z resetem hasła lądują w spamie.'),
    'email_off':      ('Email is <strong>off</strong>. Turn on <em>Write to everyone</em> in '
                       '<a href=":url">Settings &rarr; User Accounts</a>, or send the notification only.',
                       'Wysyłka e-maili jest <strong>wyłączona</strong>. Włącz <em>Pisanie do '
                       'wszystkich</em> w <a href=":url">Ustawieniach &rarr; Konta użytkowników</a> albo '
                       'wyślij samo powiadomienie.'),
    'who':            ('Who', 'Do kogo'),
    'who_selected':   ('The users I ticked', 'Zaznaczeni użytkownicy'),
    'who_group':      ('A group', 'Grupa'),
    'who_all':        ('Everyone', 'Wszyscy'),
    'send_as':        ('Send as', 'Wyślij jako'),
    'notification':   ('Notification', 'Powiadomienie'),
    'subject':        ('Subject', 'Temat'),
    'subject_ph':     ('e.g. Scheduled maintenance on Sunday',
                       'np. Planowana przerwa techniczna w niedzielę'),
    'message':        ('Message', 'Wiadomość'),
    'fmt_title':      ('How the message is written', 'W jakiej składni piszesz wiadomość'),
    'fmt_plain':      ('Plain text', 'Zwykły tekst'),
    'body_ph':        ('Line breaks are kept.', 'Podziały wierszy są zachowywane.'),
    'preview_head':   ('Preview of the email', 'Podgląd e-maila'),
    'fmt_hint_plain': ('Plain text: line breaks are kept and nothing else is interpreted.',
                       'Zwykły tekst: podziały wierszy są zachowywane, nic więcej nie jest '
                       'interpretowane.'),
    'audience_hint':  ('Choose an audience to see who would receive this.',
                       'Wybierz odbiorców, aby zobaczyć, kto to dostanie.'),
    'recount':        ('Recount', 'Przelicz'),
    'send_test':      ('Send one to me', 'Wyślij do mnie'),
    'send_dots':      ('Send&hellip;', 'Wyślij&hellip;'),

    # recent sends
    'recent_head':    ('Recent sends', 'Ostatnie wysyłki'),
    'col_started':    ('Started', 'Rozpoczęto'),
    'col_total':      ('Total', 'Razem'),
    'col_sent':       ('Sent', 'Wysłane'),
    'col_failed':     ('Failed', 'Nieudane'),
    'col_waiting':    ('Waiting', 'Czekają'),

    # groups view
    'groups_note':    ('<strong>guest</strong> = permissions of <em>anonymous</em> visitors only. A '
                       'signed-in user has exactly the <em>union</em> of their own groups (guest is NOT '
                       'inherited — a member can see less than a guest). <strong>admin</strong> members '
                       'pass every check. Priority only orders badges, it never overrides permissions.',
                       '<strong>guest</strong> = uprawnienia wyłącznie <em>anonimowych</em> '
                       'odwiedzających. Zalogowany użytkownik ma dokładnie <em>sumę</em> swoich grup '
                       '(guest NIE jest dziedziczony — członek może widzieć mniej niż gość). Członkowie '
                       'grupy <strong>admin</strong> przechodzą każde sprawdzenie. Priorytet ustala '
                       'tylko kolejność odznak, nigdy nie nadpisuje uprawnień.'),
    'new_group':      ('New group', 'Nowa grupa'),
    'name':           ('Name', 'Nazwa'),
    'slug':           ('Slug', 'Slug'),
    'priority':       ('Priority', 'Priorytet'),
    'default':        ('Default', 'Domyślna'),
    'members':        ('Members', 'Członkowie'),
    'permissions':    ('Permissions', 'Uprawnienia'),

    # add user modal
    'username_hint':  ('3&ndash;32 characters: letters, digits, dot, dash or underscore.',
                       '3&ndash;32 znaki: litery, cyfry, kropka, myślnik lub podkreślenie.'),
    'required_paren': ('(required)', '(wymagany)'),
    'password':       ('Password', 'Hasło'),
    'gen_title':      ('Generate a strong one', 'Wygeneruj mocne hasło'),
    'generate':       ('Generate', 'Wygeneruj'),
    'pw_clear_note':  ('Shown in clear on purpose &mdash; you have to be able to pass it on.',
                       'Pokazywane jawnie i celowo &mdash; musisz móc je komuś przekazać.'),
    'verify_label':   ('Email verification', 'Weryfikacja adresu e-mail'),
    'verify_auto':    ('Already verified &mdash; no email sent, can sign in now',
                       'Już zweryfikowany &mdash; żaden e-mail nie wychodzi, można się od razu zalogować'),
    'verify_send':    ('Send a verification link &mdash; acts as a guest until clicked',
                       'Wyślij link weryfikacyjny &mdash; do kliknięcia konto działa jak gość'),
    'verify_none':    ('No email at all &mdash; unverified, verify later',
                       'Bez żadnego e-maila &mdash; niezweryfikowany, weryfikacja później'),
    'banned_created': ('Banned (created, but cannot sign in)',
                       'Zbanowane (konto powstanie, ale nie da się zalogować)'),
    'create':         ('Create account', 'Utwórz konto'),

    # edit user modal
    'edit_title':     ('Edit user', 'Edytuj użytkownika'),
    'banned_nologin': ('Banned (cannot sign in)', 'Zbanowane (nie da się zalogować)'),
    'empty_none':     ('(empty = none)', '(puste = brak)'),
    'email_invalid':  ('That email address does not look valid.',
                       'Ten adres e-mail nie wygląda poprawnie.'),
    'email2':         ('Repeat new email', 'Powtórz nowy e-mail'),
    'email2_err':     ('Email addresses do not match.', 'Adresy e-mail nie są takie same.'),
    'new_pass':       ('New password', 'Nowe hasło'),
    'new_pass_note':  ('(empty = unchanged; signs the user out of remembered devices)',
                       '(puste = bez zmiany; wylogowuje użytkownika z zapamiętanych urządzeń)'),
    'pw_rule':        ('Min 8 characters with a lowercase, an uppercase, a digit and a special '
                       'character.',
                       'Minimum 8 znaków, w tym mała litera, wielka litera, cyfra i znak specjalny.'),
    'new_pass2':      ('Repeat new password', 'Powtórz nowe hasło'),
    'pass2_err':      ('Passwords do not match.', 'Hasła nie są takie same.'),

    # grant group modal
    'grant_title':    ('Grant group to', 'Przyznaj grupę użytkownikowi'),
    'duration':       ('Duration', 'Czas trwania'),
    'duration_note':  ('(durations extend an existing membership)',
                       '(czas trwania przedłuża istniejące członkostwo)'),
    'd_1d':           ('1 day', '1 dzień'),
    'd_7d':           ('1 week', '1 tydzień'),
    'd_14d':          ('2 weeks', '2 tygodnie'),
    'd_1m':           ('1 month', '1 miesiąc'),
    'd_3m':           ('3 months', '3 miesiące'),
    'd_6m':           ('6 months', '6 miesięcy'),
    'd_1y':           ('1 year', '1 rok'),
    'd_permanent':    ('Permanent', 'Na stałe'),
    'd_custom':       ('Custom from&ndash;to&hellip;', 'Własny zakres od&ndash;do&hellip;'),
    'from':           ('From', 'Od'),
    'from_note':      ('(empty = now)', '(puste = teraz)'),
    'to':             ('To', 'Do'),
    'to_note':        ('(empty = permanent)', '(puste = na stałe)'),
    'note':           ('Note', 'Notatka'),
    'note_hint':      ('(shown to the user in the notification)',
                       '(pokazywana użytkownikowi w powiadomieniu)'),
    'note_ph':        ('e.g. order #123', 'np. zamówienie #123'),
    'also_email':     ('Also send an email (if the user has an address)',
                       'Wyślij też e-mail (jeśli użytkownik ma adres)'),
    'grant':          ('Grant', 'Przyznaj'),

    # notify modal
    'notify_title':   ('Message', 'Wiadomość do'),
    'n_title':        ('Title', 'Tytuł'),
    'optional':       ('(optional)', '(opcjonalnie)'),
    'also_email_as':  ('Also send as an email (if the user has an address)',
                       'Wyślij to też e-mailem (jeśli użytkownik ma adres)'),
    'send_btn':       ('Send', 'Wyślij'),

    # group editor modal
    'color':          ('Color', 'Kolor'),
    'description':    ('Description', 'Opis'),
    'desc_note':      ('(shown to members on their account page)',
                       '(pokazywany członkom na stronie ich konta)'),
    'default_group':  ('Default group — granted automatically to every new account',
                       'Grupa domyślna — przyznawana automatycznie każdemu nowemu kontu'),
    'save_group':     ('Save group', 'Zapisz grupę'),
})
