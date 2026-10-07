# -*- coding: utf-8 -*-
"""The mails this site sends (1.74.0, QUAL-18): includes/mail.php and the account mails in includes/users.php

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

Until 1.74.0 every mail was English, whatever the language of the person it went to. Each is written now in
the RECIPIENT's language: an account's mail in the account's (recipientLang(), includes/users.php), a report's
or an appeal's confirmation in the language of the page the form was sent from, and what the panel sends a
reporter later in the site's default language (a reporter has no account to ask).

Strings marked HTML carry <strong>/<br> and are put into the mail's HTML as they are, with every value already
escaped by the code; the plain-text part is made from the same string with the tags taken out. Every other
string is plain text, escaped where the HTML part shows it.

Subjects keep the site's name and the report's number where they were; the reset's English subject still says
"password reset" (tests/mail_test.php).
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── every mail: the greeting, the button, the footer ────────────────────────
add('mail', {
    'hello':           ('Hello :name,',
                        'Witaj :name,'),
    'hello_plain':     ('Hello,',
                        'Dzień dobry,'),
    'dear':            ('Dear :name,',
                        'Dzień dobry :name,'),
    'manage_prefs':    ('Manage notification preferences',
                        'Zarządzaj ustawieniami powiadomień'),
    'button_fallback': ('If the button does not work, copy this link into your browser:',
                        'Jeśli przycisk nie działa, skopiuj ten link do przeglądarki:'),
    'open_link':       ('Open link',
                        'Otwórz link'),
    'team_message':    ('Message from our team',
                        'Wiadomość od naszego zespołu'),
    'unsubscribe_plain': ('Unsubscribe: :url',
                          'Wypisz się: :url'),
})

# ── the account: password reset, address verification, the three steps of an address change, the groups ──
# :site is the site's name; :minutes / :hours how long a link stays valid; :email an address; :group a group's name.
add('mail', {
    'reset_subject':  (':site — password reset',
                       ':site — reset hasła'),
    'reset_title':    ('Password reset',
                       'Reset hasła'),
    'reset_body':     ('A password reset was requested for your account. The link below sets a new password and is valid for :minutes minutes.',
                       'Poproszono o reset hasła do Twojego konta. Link poniżej ustawia nowe hasło i jest ważny przez :minutes min.'),
    'reset_ignore':   ('If this was not you, ignore this message — your password stays unchanged.',
                       'Jeśli to nie Ty, zignoruj tę wiadomość — Twoje hasło się nie zmieni.'),
    'reset_button':   ('Set a new password',
                       'Ustaw nowe hasło'),
    'verify_subject': (':site — confirm your email address',
                       ':site — potwierdź swój adres e-mail'),
    'verify_title':   ('Confirm your email address',
                       'Potwierdź swój adres e-mail'),
    'verify_body':    ('Confirm that this address belongs to your :site account. The link is valid for :hours hours. If you did not request this, simply ignore this message.',
                       'Potwierdź, że ten adres należy do Twojego konta w :site. Link jest ważny przez :hours godz. Jeśli to nie Ty, po prostu zignoruj tę wiadomość.'),
    'verify_button':  ('Confirm email address',
                       'Potwierdź adres e-mail'),
    'echange_subject':      (':site — confirm your email change',
                             ':site — potwierdź zmianę adresu e-mail'),
    'echange_title':        ('Confirm your email change',
                             'Potwierdź zmianę adresu e-mail'),
    'echange_body_to':      ('A change of your account email address was requested, to: :email.',
                             'Poproszono o zmianę adresu e-mail Twojego konta na: :email.'),
    'echange_body_removal': ('A change of your account email address was requested: its REMOVAL.',
                             'Poproszono o zmianę adresu e-mail Twojego konta: jego USUNIĘCIE.'),
    'echange_step1':        ('Step 1 of 2: confirm from THIS (current) address. The link is valid for :hours hours.',
                             'Krok 1 z 2: potwierdź z TEGO (obecnego) adresu. Link jest ważny przez :hours godz.'),
    'echange_not_you':      ('If this was not you, change your password immediately.',
                             'Jeśli to nie Ty, natychmiast zmień hasło.'),
    'echange_button':       ('Yes, continue the change',
                             'Tak, kontynuuj zmianę'),
    'echange_new_subject':  (':site — confirm your new email address',
                             ':site — potwierdź nowy adres e-mail'),
    'echange_new_title':    ('Confirm your new address',
                             'Potwierdź nowy adres'),
    'echange_new_body':     ('The change was approved from the previous address.',
                             'Zmianę zatwierdzono z poprzedniego adresu.'),
    'echange_step2':        ('Step 2 of 2: confirm that THIS new address is yours. The link is valid for :hours hours.',
                             'Krok 2 z 2: potwierdź, że TEN nowy adres należy do Ciebie. Link jest ważny przez :hours godz.'),
    'echange_new_button':   ('Confirm new address',
                             'Potwierdź nowy adres'),
    'echange_done_old_subject': (':site — your email address was changed',
                                 ':site — Twój adres e-mail został zmieniony'),
    'echange_done_old_body':    ('The email on your account is now: :email',
                                 'Adres e-mail Twojego konta to teraz: :email'),
    'echange_done_old_not_you': ('If this was not you, reset your password immediately.',
                                 'Jeśli to nie Ty, natychmiast zresetuj hasło.'),
    'echange_done_new_subject': (':site — email change confirmed',
                                 ':site — zmiana adresu e-mail potwierdzona'),
    'echange_done_new_body':    ('Done! This address is now active and verified on your account. Account notices and password resets arrive here from now on.',
                                 'Gotowe! Ten adres jest teraz aktywny i potwierdzony na Twoim koncie. Powiadomienia o koncie i resety hasła przychodzą odtąd tutaj.'),
    'group_granted_subject':    (':site — you are now in the ":group" group',
                                 ':site — jesteś teraz w grupie „:group”'),
    'group_expiring_subject':   (':site — your ":group" access expires soon',
                                 ':site — Twój dostęp do grupy „:group” wkrótce wygaśnie'),
    'group_expiring_body':      ('Your ":group" group access ends :until.',
                                 'Twój dostęp do grupy „:group” kończy się :until.'),
})

# ── a report's and an appeal's mails: the labels of the table, the states ──
# The states are named by the code ('mail.st_' . $state, getStatusHtml()); so are a new state's subject and words
# below ('mail.stn_subject_' . $kind, 'mail.stn_' . $kind, sendStatusNotification()).
add('mail', {
    'r_report_id':      ('Report ID', 'Numer zgłoszenia'),
    'r_reporter':       ('Reporter', 'Zgłaszający'),
    'r_representative': ('Representative', 'Przedstawiciel'),
    'r_company':        ('Company', 'Firma'),
    'r_object':         ('Object', 'Tytuł'),
    'r_ip':             ('IP Address', 'Adres IP'),
    'r_date_filed':     ('Date Filed', 'Data zgłoszenia'),
    'r_date':           ('Date', 'Data'),
    'r_info_hash':      ('Info Hash', 'Info hash'),
    'r_magnet':         ('Magnet Link', 'Link magnet'),
    'r_message':        ('Message', 'Wiadomość'),
    'r_status':         ('Status', 'Stan'),
    'r_request_type':   ('Request Type', 'Rodzaj prośby'),
    'st_blocked':        ('Blocked', 'Zablokowano'),
    'st_blocked_action': ('Blocked — Action Taken', 'Zablokowano — podjęto działanie'),
    'st_reviewed':       ('Reviewed', 'Rozpatrzono'),
    'st_awaiting':       ('Awaiting Review', 'Czeka na rozpatrzenie'),
    'st_closed':         ('Archived / Closed', 'Zarchiwizowano / zamknięto'),
    'st_reopened':       ('Reopened', 'Wznowiono'),
    'st_deleted':        ('Permanently Deleted', 'Trwale usunięto'),
})

# ── the confirmation of a report (PUB-1: nothing the sender typed — the number, the hash, the date, the state) ──
add('mail', {
    'sub_subject':       ('Report #:id — Submission Confirmed',
                          'Zgłoszenie nr :id — potwierdzenie przyjęcia'),
    'sub_title':         ('Report Submission Confirmed',
                          'Zgłoszenie przyjęte'),
    'sub_body':          ('We have received a report sent with this email address. Its reference number is #:id — please keep it: with it and this address you can check the report\'s status at any time.',
                          'Otrzymaliśmy zgłoszenie wysłane z tym adresem e-mail. Jego numer to :id — zachowaj go: z nim i tym adresem możesz w każdej chwili sprawdzić stan zgłoszenia.'),
    'check_status':      ('You can check its status at :url with the report number and this email address.',
                          'Stan sprawdzisz pod adresem :url, podając numer zgłoszenia i ten adres e-mail.'),
    'check_status_nourl': ('You can check its status on our site with the report number and this email address.',
                           'Stan sprawdzisz na naszej stronie, podając numer zgłoszenia i ten adres e-mail.'),
    'not_you':           ('If you did not send it, ignore this message — the link at the bottom stops any further mail about it.',
                          'Jeśli tego nie wysyłałeś(-aś), zignoruj tę wiadomość — link na dole wstrzymuje kolejne wiadomości w tej sprawie.'),
    'sub_footer':        ('Our team will review the report and take appropriate action. You will receive email notifications as its status changes.',
                          'Nasz zespół rozpatrzy zgłoszenie i podejmie odpowiednie działania. O zmianach jego stanu poinformujemy Cię e-mailem.'),
})

# ── a report under review, its state changed, a message from the team, the report deleted ──
# HTML: stn_* and del_body (the title arrives escaped). :id is the report's number, :title what it is about.
add('mail', {
    'rev_subject':  ('Report #:id — Under Review',
                     'Zgłoszenie nr :id — w trakcie rozpatrywania'),
    'rev_title':    ('Report Under Review',
                     'Zgłoszenie w trakcie rozpatrywania'),
    'rev_thanks':   ('Thank you for submitting your report regarding ":title".',
                     'Dziękujemy za zgłoszenie dotyczące „:title”.'),
    'rev_body':     ('Your report has been received and is currently under review by our team. If any action is taken as a result of your report, you will receive a follow-up notification.',
                     'Zgłoszenie dotarło i nasz zespół właśnie je rozpatruje. Jeśli w jego wyniku zostaną podjęte jakieś działania, otrzymasz kolejną wiadomość.'),
    'rev_footer':   ('Please do not reply to this email. If you have additional information to provide, you may submit a new report.',
                     'Prosimy nie odpowiadać na tę wiadomość. Jeśli masz dodatkowe informacje, możesz wysłać nowe zgłoszenie.'),
    'stn_subject_blocked':  ('Report #:id — Action Taken', 'Zgłoszenie nr :id — podjęto działanie'),
    'stn_subject_checked':  ('Report #:id — Reviewed', 'Zgłoszenie nr :id — rozpatrzone'),
    'stn_subject_pending':  ('Report #:id — Status Updated', 'Zgłoszenie nr :id — zmiana stanu'),
    'stn_subject_archived': ('Report #:id — Closed', 'Zgłoszenie nr :id — zamknięte'),
    'stn_subject_restored': ('Report #:id — Reopened', 'Zgłoszenie nr :id — wznowione'),
    'stn_subject_other':    ('Report #:id — Status Changed', 'Zgłoszenie nr :id — zmiana stanu'),
    'stn_blocked':  ('Following a review of your report regarding <strong>:title</strong>, we have determined that the reported content violates our policies.<br><br>The associated info hash has been <strong>blocked</strong> on our tracker. The content will no longer be trackable through our services.',
                     'Po rozpatrzeniu Twojego zgłoszenia dotyczącego <strong>:title</strong> stwierdziliśmy, że zgłoszona treść narusza nasze zasady.<br><br>Powiązany info hash został <strong>zablokowany</strong> na naszym trackerze. Treść nie będzie już śledzona przez nasze usługi.'),
    'stn_checked':  ('Your report regarding <strong>:title</strong> has been reviewed by our team.<br><br>The report has been marked as reviewed. If further action is required, you will be notified separately.',
                     'Nasz zespół rozpatrzył Twoje zgłoszenie dotyczące <strong>:title</strong>.<br><br>Zgłoszenie oznaczono jako rozpatrzone. Jeśli potrzebne będą dalsze działania, poinformujemy Cię osobno.'),
    'stn_pending':  ('The status of your report regarding <strong>:title</strong> has been updated to <strong>:status</strong>.<br><br>Our team will review your report and take appropriate action if necessary.',
                     'Stan Twojego zgłoszenia dotyczącego <strong>:title</strong> zmienił się na <strong>:status</strong>.<br><br>Nasz zespół rozpatrzy zgłoszenie i w razie potrzeby podejmie odpowiednie działania.'),
    'stn_archived': ('Your report regarding <strong>:title</strong> has been processed and archived.<br><br>This report is now closed. No further action will be taken unless a new report is submitted.',
                     'Twoje zgłoszenie dotyczące <strong>:title</strong> zostało rozpatrzone i zarchiwizowane.<br><br>Zgłoszenie jest teraz zamknięte. Dalsze działania podejmiemy tylko po nowym zgłoszeniu.'),
    'stn_restored': ('Your report regarding <strong>:title</strong> has been restored to active status.<br><br>Our team will continue reviewing your report. You will receive further notifications as the status changes.',
                     'Twoje zgłoszenie dotyczące <strong>:title</strong> zostało przywrócone do aktywnych.<br><br>Nasz zespół będzie je dalej rozpatrywał. O kolejnych zmianach stanu poinformujemy Cię e-mailem.'),
    'stn_other':    ('The status of your report has been updated to <strong>:status</strong>.',
                     'Stan Twojego zgłoszenia zmienił się na <strong>:status</strong>.'),
    'cus_subject':  ('Report #:id — Message From Our Team',
                     'Zgłoszenie nr :id — wiadomość od naszego zespołu'),
    'cus_title':    ('Message Regarding Report #:id',
                     'Wiadomość w sprawie zgłoszenia nr :id'),
    'cus_intro':    ('You are receiving this message regarding your report #:id.',
                     'Otrzymujesz tę wiadomość w sprawie Twojego zgłoszenia nr :id.'),
    'cus_body':     ('You are receiving this message regarding your report. Our team has the following update for you:',
                     'Otrzymujesz tę wiadomość w sprawie Twojego zgłoszenia. Nasz zespół przekazuje następującą informację:'),
    'cus_details':  ('Report Details',
                     'Szczegóły zgłoszenia'),
    'cus_footer':   ('If you have any questions, please submit a new report or contact us via the provided channels.',
                     'Jeśli masz pytania, wyślij nowe zgłoszenie albo skontaktuj się z nami podanymi kanałami.'),
    'del_subject':  ('Report #:id — Permanently Deleted',
                     'Zgłoszenie nr :id — trwale usunięte'),
    'del_body':     ('We are writing to inform you that your report #:id regarding <strong>:title</strong> has been permanently deleted from our system.<br><br>This report, along with all associated database records (appeals, email history), has been removed. It will not be archived and will not appear in the transparency report.',
                     'Informujemy, że Twoje zgłoszenie nr :id dotyczące <strong>:title</strong> zostało trwale usunięte z naszego systemu.<br><br>Usunięto je razem ze wszystkimi powiązanymi zapisami (odwołaniami, historią wiadomości). Nie trafi do archiwum ani do raportu przejrzystości.'),
    'del_reason':   ('Reason for deletion:',
                     'Powód usunięcia:'),
    'del_footer':   ('Please do not reply to this email. For any further inquiries, please submit a new report.',
                     'Prosimy nie odpowiadać na tę wiadomość. W dalszych sprawach wyślij nowe zgłoszenie.'),
})

# ── the confirmation of an appeal or a block request (PUB-1: no name, no reason, no address) ──
# :type is app_type_block or app_type_unblock.
add('mail', {
    'app_type_block':   ('Block Request', 'Prośba o blokadę'),
    'app_type_unblock': ('Unblock Appeal', 'Odwołanie od blokady'),
    'app_subject':      (':type Received — :site',
                         ':type — przyjęto (:site)'),
    'app_title':        (':type Received',
                         ':type — przyjęto'),
    'app_body_block':   ('A block request for the info hash below was sent with this email address and has been received. Our team will review it and decide whether the content meets the criteria for blocking; you will be notified by email once a decision has been made.',
                         'Prośba o zablokowanie poniższego info hasha, wysłana z tym adresem e-mail, dotarła do nas. Nasz zespół ją rozpatrzy i oceni, czy treść spełnia kryteria blokady; o decyzji poinformujemy Cię e-mailem.'),
    'app_body_unblock': ('An unblock appeal for the info hash below was sent with this email address and has been received; it is pending review by our team. You will be notified by email once a decision has been made.',
                         'Odwołanie od blokady poniższego info hasha, wysłane z tym adresem e-mail, dotarło do nas i czeka na rozpatrzenie przez nasz zespół. O decyzji poinformujemy Cię e-mailem.'),
    'app_footer':       ('Please do not reply to this email. If you have additional information to provide, you may submit a new appeal.',
                         'Prosimy nie odpowiadać na tę wiadomość. Jeśli masz dodatkowe informacje, możesz wysłać nowe odwołanie.'),
})

# ── an appeal's later mails: the decision, an appeal reopened, an appeal closed with another (1.74.0, part E) ──
# Sent by the panel after a moderator acted (mailAppealDecisionParts(), includes/mail.php), in the site's default
# language: an appeal has no account and stores no language. :type is app_type_block or app_type_unblock; :site the
# site's name; :decision one of the apd_dec_* words (HTML strings: the code puts it in, coloured and escaped).
add('mail', {
    'apd_dec_accepted':        ('Accepted', 'Uwzględniono'),
    'apd_dec_rejected':        ('Rejected', 'Odrzucono'),
    'apd_dec_auto_closed':     ('Automatically Closed', 'Zamknięto automatycznie'),
    'apd_dec_reopened':        ('Reopened for Review', 'Ponownie do rozpatrzenia'),
    'r_decision':              ('Decision', 'Decyzja'),
    'apd_subject_decided':     (':type :decision — :site',
                                ':type — :decision (:site)'),
    'apd_subject_reopened':    (':type Reopened for Review — :site',
                                ':type — ponownie do rozpatrzenia (:site)'),
    'apd_subject_auto_closed': (':type Closed — :site',
                                ':type — zamknięto (:site)'),
    'apd_body_block':          ('Your request to block the info hash below has been <strong>:decision</strong>.',
                                'Decyzja w sprawie Twojej prośby o zablokowanie poniższego info hasha: <strong>:decision</strong>.'),
    'apd_body_unblock':        ('Your appeal to unblock the info hash below has been <strong>:decision</strong>.',
                                'Decyzja w sprawie Twojego odwołania od blokady poniższego info hasha: <strong>:decision</strong>.'),
    'apd_listed_block':        ('The hash has been added to the tracker blacklist.',
                                'Hash został dodany do blacklisty trackera.'),
    'apd_listed_unblock':      ('The hash has been removed from the tracker blacklist.',
                                'Hash został usunięty z blacklisty trackera.'),
    'apd_reopened_block':      ('Your block request for the info hash below has been reopened and will be reviewed again.',
                                'Twoja prośba o zablokowanie poniższego info hasha została otwarta ponownie i zostanie rozpatrzona jeszcze raz.'),
    'apd_reopened_unblock':    ('Your unblock appeal for the info hash below has been reopened and will be reviewed again.',
                                'Twoje odwołanie od blokady poniższego info hasha zostało otwarte ponownie i zostanie rozpatrzone jeszcze raz.'),
    'apd_auto_closed_block':   ('Your block request for the info hash below has been automatically closed because another appeal for the same hash has been resolved.',
                                'Twoja prośba o zablokowanie poniższego info hasha została zamknięta automatycznie, bo rozpatrzono inną sprawę dotyczącą tego samego hasha.'),
    'apd_auto_closed_unblock': ('Your unblock appeal for the info hash below has been automatically closed because another appeal for the same hash has been resolved.',
                                'Twoje odwołanie od blokady poniższego info hasha zostało zamknięte automatycznie, bo rozpatrzono inną sprawę dotyczącą tego samego hasha.'),
})
