# -*- coding: utf-8 -*-
"""Reports of comments, descriptions and shouts; a warning, loud or silent (1.71.0, part E)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

includes/reports.php and its endpoints (api.creport.*, notify.creport_* / notify.warn_*), the member's
Report button and box (assets/js/reports.js, js.report.*; the shoutbox row's own twin, shout.report* and
js.shout.report*), the Reports page's three new tabs and their cards (templates/admin/dashboard.php a.creport.*,
assets/js/admin-contentreports.js js.creport.*, also the message card's silent/loud choice and Warn), and the
warnings in Users (js.users.warn*, a.users.warnings_*). The Polish uses the site's capitalised Twój / Twoja and
"wpis" for a shout, as the shoutbox's own strings do; every kind is masculine (komentarz, opis, wpis), which is
why one sentence with :what serves the three.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── api/content_report.php: the member's report, refused or taken (contentReportRequest()) ──────────────
add('api.creport', {
    'bad_kind':        ('Nothing of that kind can be reported.',
                        'Takich rzeczy nie można zgłaszać.'),
    'login':           ('Sign in to report something.',
                        'Zaloguj się, aby coś zgłosić.'),
    'disabled':        ('That part of the site is switched off.',
                        'Ta część serwisu jest wyłączona.'),
    'no_permission':   ('Your account may not send reports.',
                        'Twoje konto nie może wysyłać zgłoszeń.'),
    'not_found':       ('There is nothing like that to report — it may have been removed already.',
                        'Nie ma czego zgłosić — mogło to już zostać usunięte.'),
    'own':             ('Those are your own words: you cannot report them.',
                        'To Twoje własne słowa — nie możesz ich zgłosić.'),
    'rate_limit':      ('You have sent a lot of reports this hour. Try again later.',
                        'W tej godzinie wysłano już z Twojego konta wiele zgłoszeń. Spróbuj później.'),
    'reason_required': ('Say in a few words what is wrong with it.',
                        'Napisz w kilku słowach, co jest nie tak.'),
    'reason_too_long': ('The reason may be at most :max characters long.',
                        'Powód może mieć najwyżej :max znaków.'),
    'failed':          ('The report did not go through. Try again.',
                        'Zgłoszenie nie zostało wysłane. Spróbuj ponownie.'),
    'reported':        ('Reported — a moderator will look at it. Thank you.',
                        'Zgłoszono — moderator się temu przyjrzy. Dziękujemy.'),
    'already':         ('You have already reported this; a moderator will look at it.',
                        'To już zostało przez Ciebie zgłoszone; moderator się temu przyjrzy.'),
})

# ── the reporter hears what became of the report, in THEIR language (contentReportTellReporters()) ──────
# :what is a phrase of its own (notify.creport_what_*) — in Polish in the genitive, after "dotyczące".
add('notify', {
    'creport_closed':              ('Your report about :what was closed',
                                    'Twoje zgłoszenie dotyczące :what zostało zamknięte'),
    'creport_handled':             ('Your report about :what was handled',
                                    'Twoje zgłoszenie dotyczące :what zostało rozpatrzone'),
    'creport_removed_comment':     ('The comment you reported, on “:name”, was removed',
                                    'Zgłoszony przez Ciebie komentarz pod „:name” został usunięty'),
    'creport_removed_description': ('The description you reported, of “:name”, was removed',
                                    'Zgłoszony przez Ciebie opis „:name” został usunięty'),
    'creport_removed_shout':       ('The shout you reported, by :user, was removed',
                                    'Zgłoszony przez Ciebie wpis :user w shoutboksie został usunięty'),
    'creport_what_comment':        ('a comment on “:name”',
                                    'komentarza pod „:name”'),
    'creport_what_description':    ('the description of “:name”',
                                    'opisu „:name”'),
    'creport_what_shout':          ('a shout by :user',
                                    'wpisu :user w shoutboksie'),
    'creport_someone':             ('a member',
                                    'członka'),
    # ── the author, when the moderator chose to be LOUD: one warning, in the author's language (userWarn()) ──
    'warn_removed_comment':        ('Warning: your comment on “:name” was removed',
                                    'Ostrzeżenie: Twój komentarz pod „:name” został usunięty'),
    'warn_removed_description':    ('Warning: your description of “:name” was removed',
                                    'Ostrzeżenie: Twój opis „:name” został usunięty'),
    'warn_removed_shout':          ('Warning: your shout was removed',
                                    'Ostrzeżenie: Twój wpis w shoutboksie został usunięty'),
    'warn_removed_message':        ('Warning: one of your private messages was removed',
                                    'Ostrzeżenie: jedna z Twoich prywatnych wiadomości została usunięta'),
    'warn_about_comment':          ('Warning about your comment on “:name”',
                                    'Ostrzeżenie w sprawie Twojego komentarza pod „:name”'),
    'warn_about_description':      ('Warning about your description of “:name”',
                                    'Ostrzeżenie w sprawie Twojego opisu „:name”'),
    'warn_about_shout':            ('Warning about your shout',
                                    'Ostrzeżenie w sprawie Twojego wpisu w shoutboksie'),
    'warn_about_message':          ('Warning about a private message you sent',
                                    'Ostrzeżenie w sprawie wysłanej przez Ciebie prywatnej wiadomości'),
    'warn_muted':                  ('Warning: you are silenced until :date',
                                    'Ostrzeżenie: masz wyciszenie do :date'),
    'warn_muted_forever':          ('Warning: you are silenced until a moderator lifts it',
                                    'Ostrzeżenie: masz wyciszenie do odwołania przez moderatora'),
    'warn_banned':                 ('Warning: your account is banned until :date',
                                    'Ostrzeżenie: Twoje konto jest zablokowane do :date'),
    'warn_banned_forever':         ('Warning: your account is banned until a moderator lifts it',
                                    'Ostrzeżenie: Twoje konto jest zablokowane do odwołania przez moderatora'),
    'warn_reason':                 ('The moderator’s reason: :reason',
                                    'Powód podany przez moderatora: :reason'),
    'warn_count':                  ('This is warning number :n on your account.',
                                    'To ostrzeżenie numer :n na Twoim koncie.'),
    'warn_muted_what':             ('While you are silenced you cannot write messages, shouts or comments; you can still read them.',
                                    'Podczas wyciszenia nie możesz pisać wiadomości, wpisów w shoutboksie ani komentarzy; nadal możesz je czytać.'),
})

# ── the member's Report button and box (assets/js/reports.js) ──────────────────────────────────────────
add('js.report', {
    'report':          ('Report', 'Zgłoś'),
    'tip_comment':     ('Report this comment to the moderators',
                        'Zgłoś ten komentarz moderatorom'),
    'tip_description': ('Report this description to the moderators',
                        'Zgłoś ten opis moderatorom'),
    'tip_shout':       ('Report this shout to the moderators',
                        'Zgłoś ten wpis moderatorom'),
    'reported':        ('Reported', 'Zgłoszono'),
    'reported_tip':    ('You reported this — a moderator will look at it',
                        'Zgłoszono — moderator się temu przyjrzy'),
    'label':           ('What is wrong with it?', 'Co jest nie tak?'),
    'placeholder':     ('A short reason for the moderators…', 'Krótki powód dla moderatorów…'),
    'send':            ('Send the report', 'Wyślij zgłoszenie'),
    'private':         ('Only the moderators read it; the author is never told who reported.',
                        'Zgłoszenie czytają tylko moderatorzy; autor nigdy nie dowie się, kto je wysłał.'),
    'need_reason':     ('Say in a few words what is wrong with it.',
                        'Napisz w kilku słowach, co jest nie tak.'),
    'failed':          ('The report did not go through. Try again.',
                        'Zgłoszenie nie zostało wysłane. Spróbuj ponownie.'),
    'sent':            ('Thank you — a moderator will look at it.',
                        'Dziękujemy — moderator się temu przyjrzy.'),
})

# ── the shoutbox row's flag: drawn by the server (templates/partials/shoutbox_widget.php) and by the script ──
add('shout', {
    'report':         ('Report', 'Zgłoś'),
    'report_title':   ('Report this shout to the moderators', 'Zgłoś ten wpis moderatorom'),
    'reported':       ('Reported', 'Zgłoszono'),
    'reported_title': ('You reported this shout — a moderator will look at it', 'Zgłoszono ten wpis — moderator się temu przyjrzy'),
})
add('js.shout', {
    'report':         ('Report', 'Zgłoś'),
    'report_title':   ('Report this shout to the moderators', 'Zgłoś ten wpis moderatorom'),
    'reported':       ('Reported', 'Zgłoszono'),
    'reported_title': ('You reported this shout — a moderator will look at it', 'Zgłoszono ten wpis — moderator się temu przyjrzy'),
})

# ── the Reports page: three tabs and their toolbar (templates/admin/dashboard.php) ───────────────────────
add('a.reports', {
    'tab_comments':     ('Comments', 'Komentarze'),
    'tab_descriptions': ('Descriptions', 'Opisy'),
    'tab_shouts':       ('Shouts', 'Shoutbox'),
})
add('a.creport', {
    'search_ph':   ('Search the reasons, the words and the names…', 'Szukaj w powodach, treści i nazwach…'),
    'author_ph':   ('Reported member', 'Zgłoszony członek'),
    'reporter_ph': ('Reporter', 'Zgłaszający'),
    'from':        ('From', 'Od'),
    'to':          ('To', 'Do'),
    'clear':       ('Clear the filters', 'Wyczyść filtry'),
    'note':        ('One card for each reported thing: its words — as they are now, and as they were when reported if '
                    'they changed —, every report about it, and its author. The reporters are shown here and nowhere else: '
                    'the author is never told who reported.',
                    'Jedna karta na każdą zgłoszoną rzecz: jej treść — obecna, a jeśli się zmieniła, także ta z chwili '
                    'zgłoszenia —, wszystkie zgłoszenia jej dotyczące i jej autor. Zgłaszających widać tylko tutaj: '
                    'autor nigdy nie dowie się, kto zgłosił.'),
})

# ── the Reports page's cards (assets/js/admin-contentreports.js) and the message card's new controls ──────
add('js.creport', {
    'kind_comment':      ('Comment', 'Komentarz'),
    'kind_description':  ('Description', 'Opis'),
    'kind_shout':        ('Shout', 'Wpis'),
    'head_comment':      ('A comment by :user on “:name”', 'Komentarz :user pod „:name”'),
    'head_description':  ('The description of “:name”, by :user', 'Opis „:name”, autor: :user'),
    'head_shout':        ('A shout by :user', 'Wpis :user w shoutboksie'),
    'guest':             ('Guest #:tag', 'Gość #:tag'),
    'nobody':            ('an account that is gone', 'konto, którego już nie ma'),
    'no_author':         ('nobody', 'nikt'),
    'open_site':         ('Open where it is', 'Otwórz na stronie'),
    'state_pending':     ('waiting for a moderator (a guest’s comment)', 'czeka na moderatora (komentarz gościa)'),
    'state_deleted':     ('removed', 'usunięte'),
    'state_gone':        ('no longer there', 'już nie istnieje'),
    'state_none':        ('the torrent has no description any more', 'torrent nie ma już opisu'),
    'state_unpublished': ('not published now', 'obecnie nieopublikowany'),
    'changed':           ('The words changed after the report. As they were when reported:',
                          'Treść zmieniła się po zgłoszeniu. W chwili zgłoszenia brzmiała tak:'),
    'as_reported':       ('As they were when reported:', 'W chwili zgłoszenia:'),
    'reports_n':         ('Reports: :n', 'Zgłoszenia: :n'),
    'open_n':            ('open: :n', 'otwarte: :n'),
    'report_by':         (':user', ':user'),
    'report_other_words': ('(reported an earlier version)', '(zgłoszono wcześniejszą wersję)'),
    'outcome_closed':    ('closed without action', 'zamknięte bez działań'),
    'outcome_removed':   ('removed', 'usunięte'),
    'outcome_warned':    ('the author warned', 'autor ostrzeżony'),
    'outcome_muted':     ('the author silenced', 'autor wyciszony'),
    'outcome_banned':    ('the author banned', 'autor zablokowany'),
    'handled':           (':outcome — :user, :at', ':outcome — :user, :at'),
    'status_open':       ('open', 'otwarte'),
    'warned_n':          ('warned :n times', 'ostrzeżeń: :n'),
    'latest_warnings':   ('Latest warnings:', 'Ostatnie ostrzeżenia:'),
    'warning_line':      (':at — :reason', ':at — :reason'),
    'is_muted':          ('silenced until :date', 'wyciszony do :date'),
    'is_you':            ('this is your own account', 'to Twoje własne konto'),
    'reply_ph':          ('An answer to the reporters — each of them sees it (optional)…',
                          'Odpowiedź dla zgłaszających — zobaczy ją każdy z nich (opcjonalnie)…'),
    'reason_ph':         ('The reason, for the author — needed for a warning…',
                          'Powód dla autora — potrzebny przy ostrzeżeniu…'),
    'mode_label':        ('The author is told:', 'Autor dostanie:'),
    'mode_silent':       ('nothing (silently)', 'nic (po cichu)'),
    'mode_loud':         ('a warning, with the reason', 'ostrzeżenie z powodem'),
    'mode_hint':         ('Silently: the words are simply gone, a silence simply applies. As a warning: the author gets a '
                          'notification with your reason, and the warning is kept on the account.',
                          'Po cichu: treść po prostu znika, a wyciszenie po prostu obowiązuje. Jako ostrzeżenie: autor '
                          'dostaje powiadomienie z Twoim powodem, a ostrzeżenie zostaje zapisane na koncie.'),
    'remove':            ('Remove it', 'Usuń'),
    'remove_q':          ('Remove these words for every reader?', 'Usunąć tę treść dla wszystkich czytelników?'),
    'warn':              ('Warn the author', 'Ostrzeż autora'),
    'warn_q':            ('Warn :user?', 'Ostrzec :user?'),
    'mute_1':            ('Silence the author for 1 day', 'Wycisz autora na 1 dzień'),
    'mute_7':            ('Silence the author for 7 days', 'Wycisz autora na 7 dni'),
    'mute_30':           ('Silence the author for 30 days', 'Wycisz autora na 30 dni'),
    'mute_forever':      ('Silence the author until somebody lifts it', 'Wycisz autora do odwołania'),
    'ban_7':             ('Ban the author for 7 days', 'Zablokuj autora na 7 dni'),
    'ban_30':            ('Ban the author for 30 days', 'Zablokuj autora na 30 dni'),
    'ban_forever':       ('Ban the author until somebody lifts it', 'Zablokuj autora do odwołania'),
    'sure_mute':         ('Silence :user?', 'Wyciszyć :user?'),
    'sure_ban':          ('Ban :user?', 'Zablokować :user?'),
    'account_note':      ('Nothing here applies to this account.', 'Nic tutaj nie dotyczy tego konta.'),
    'err_reason':        ('A warning needs a reason — it is what the author reads.',
                          'Ostrzeżenie wymaga powodu — to właśnie przeczyta autor.'),
    'err_no_author':     ('There is no account to act on — a guest wrote it, or the account is gone.',
                          'Nie ma konta, którego to dotyczy — napisał to gość albo konto już nie istnieje.'),
    'err_changed':       ('The words changed since this page was drawn — look at them again before removing them.',
                          'Treść zmieniła się od wyświetlenia tej strony — przejrzyj ją ponownie przed usunięciem.'),
    'err_gone':          ('It is already gone.', 'Tego już nie ma.'),
    'err_permanent':     ('This account’s ban has no end date: it was set on the Users page and is lifted there.',
                          'Blokada tego konta nie ma daty końca: nałożono ją na stronie Użytkownicy i tam się ją zdejmuje.'),
    'done_close':        ('Closed. Reporters told: :n.', 'Zamknięto. Powiadomieni zgłaszający: :n.'),
    'done_reopen':       ('Reopened.', 'Otwarto ponownie.'),
    'done_remove':       ('Removed. Reporters told: :n.', 'Usunięto. Powiadomieni zgłaszający: :n.'),
    'done_warn':         ('The warning was sent.', 'Ostrzeżenie zostało wysłane.'),
    'done_mute':         ('Silenced.', 'Wyciszono.'),
    'done_ban':          ('Banned.', 'Zablokowano.'),
    'done_unmute':       ('The silence is lifted.', 'Wyciszenie zdjęte.'),
    'done_unban':        ('The ban is lifted.', 'Blokada zdjęta.'),
    'done_loud':         (' The author got a warning.', ' Autor dostał ostrzeżenie.'),
})

# ── Admin → Users: the warnings of a member (assets/js/admin-users.js, templates/admin/users.php) ─────────
add('js.users', {
    'warned_badge':      ('Warned :n times', 'Ostrzeżeń: :n'),
    'warnings_none':     ('No warnings.', 'Brak ostrzeżeń.'),
    'warnings_count':    ('Warnings: :n — the latest:', 'Ostrzeżenia: :n — ostatnie:'),
    'warning_line':      (':at — :action: :reason (:by)', ':at — :action: :reason (:by)'),
    'warn_action_warn':  ('a warning', 'ostrzeżenie'),
    'warn_action_remove': ('words removed', 'usunięta treść'),
    'warn_action_mute':  ('silenced', 'wyciszenie'),
    'warn_action_ban':   ('banned', 'blokada'),
})
add('a.users', {
    'warnings_head': ('Warnings', 'Ostrzeżenia'),
    'warnings_hint': ('What moderators warned this member about, the newest first. The member sees each warning in '
                      'their notifications, and nowhere else.',
                      'Za co moderatorzy ostrzegli tego członka, od najnowszych. Członek widzi każde ostrzeżenie w swoich '
                      'powiadomieniach i nigdzie indziej.'),
})
