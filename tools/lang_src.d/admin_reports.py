# -*- coding: utf-8 -*-
"""Admin reports dashboard

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


# ── admin — reports dashboard ───────────────────────────────────────────────
# The table BODY is rendered by assets/js/admin.js, not by the template, so only the chrome around
# it lives here. The three status filter options reuse status.b_pending / b_checked / b_blocked so
# an admin and a reporter read the same word for the same state.
add('a.reports', {
    'title':        ('Reports', 'Zgłoszenia'),
    'subtitle':     ('abuse reports, appeals &amp; tracker status',
                     'zgłoszenia nadużyć, odwołania i status trackera'),

    # source tabs — narrow, keep the Polish short
    'tab_active':          ('Active Reports', 'Aktywne zgłoszenia'),
    'tab_archives':        ('Archives', 'Archiwum'),
    'tab_appeals':         ('Appeals', 'Odwołania'),
    'tab_appeal_archives': ('Appeal Archives', 'Archiwum odwołań'),

    # toolbar
    'search_ph':       ('Search name, company, entity, email, hash, link...',
                        'Szukaj po nazwisku, firmie, podmiocie, e-mailu, hashu, linku...'),
    'f_all':           ('All statuses', 'Wszystkie statusy'),
    'archive_reviewed': ('Archive reviewed', 'Archiwizuj rozpatrzone'),

    # table columns (narrow — keep the Polish just as short)
    'c_id':      ('ID', 'ID'),
    'c_name':    ('Name', 'Zgłaszający'),
    'c_email':   ('Email', 'E-mail'),
    'c_company': ('Company', 'Firma'),
    'c_entity':  ('Entity', 'Podmiot'),
    'c_object':  ('Object', 'Utwór'),
    'c_ip':      ('IP', 'IP'),
    'c_date':    ('Date', 'Data'),
    'c_actions': ('Actions', 'Akcje'),

    # report detail modal
    'm_title':       ('Report Details', 'Szczegóły zgłoszenia'),
    'm_block':       ('Block hash', 'Zablokuj hash'),
    'm_unblock':     ('Unblock hash', 'Odblokuj hash'),
    'm_archive':     ('Archive (no action)', 'Archiwizuj (bez działania)'),
    'm_restore':     ('Restore to active', 'Przywróć do aktywnych'),
    'm_delete_perm': ('Delete permanently', 'Usuń trwale'),
    'm_email_head':  ('Send custom message to reporter',
                      'Wyślij własną wiadomość do zgłaszającego'),
    'm_email_hint':  ('Your message will be sent in a professional email template along with the '
                      'full report details.',
                      'Twoja wiadomość zostanie wysłana w profesjonalnym szablonie e-maila razem '
                      'z pełnymi szczegółami zgłoszenia.'),
    'm_email_ph':    ('e.g. We have reviewed your report and would like to request additional '
                      'documentation...',
                      'np. Rozpatrzyliśmy Twoje zgłoszenie i prosimy o dodatkową '
                      'dokumentację...'),
    'm_send':        ('Send Message', 'Wyślij wiadomość'),

    # appeal modal
    'ap_title':     ('Appeal Details', 'Szczegóły odwołania'),
    'ap_accept':    ('Accept Appeal', 'Przyjmij odwołanie'),
    'ap_reject':    ('Reject Appeal', 'Odrzuć odwołanie'),
    # Same words as m_restore, but the English here is title-cased and translating one key for both
    # would quietly change what one of the two buttons says.
    'ap_restore':   ('Restore to Active', 'Przywróć do aktywnych'),
    'ap_resp_head': ('Admin Response (sent to appellant)',
                     'Odpowiedź administratora (wysyłana do odwołującego się)'),
    'ap_resp_ph':   ('Optional response message to the appellant...',
                     'Opcjonalna wiadomość zwrotna do odwołującego się...'),

    # confirm dialog
    'confirm': ('Confirm', 'Potwierdź'),

    # permanent deletion
    'admin_pass':     ('Admin Password *', 'Hasło administratora *'),
    'del_title':      ('Permanent Deletion', 'Trwałe usunięcie'),
    'del_warn':       ('<strong>Warning:</strong> This will permanently delete the report and all '
                       'its dependencies (sent emails, appeals) from the database. It will not be '
                       'archived and will not appear in the transparency report.',
                       '<strong>Uwaga:</strong> To trwale usunie z bazy danych zgłoszenie i '
                       'wszystko, co od niego zależy (wysłane e-maile, odwołania). Nie zostanie '
                       'zarchiwizowane i nie pojawi się w raporcie przejrzystości.'),
    'del_reason':     ('Reason for deletion (Sent to reporter) '
                       '<small style="color: #a0a0b0;">(Optional)</small>',
                       'Powód usunięcia (wysyłany do zgłaszającego) '
                       '<small style="color: #a0a0b0;">(opcjonalnie)</small>'),
    'del_reason_ph':  ('e.g. This was a duplicate test report.',
                       'np. To było zduplikowane zgłoszenie testowe.'),
    'del_confirm':    ('Confirm Deletion', 'Potwierdź usunięcie'),

    # restart / reload the tracker service
    'restart_title': ('Restart Tracker Service', 'Restart usługi trackera'),
    'restart_body':  ('This runs <code>systemctl restart :svc</code> on the server. The tracker '
                      'will be briefly unavailable while it reloads (picking up the latest '
                      'blacklist). Enter your admin password to confirm.',
                      'To uruchamia na serwerze <code>systemctl restart :svc</code>. Tracker '
                      'będzie przez chwilę niedostępny, zanim wstanie z powrotem (wczytując '
                      'najnowszą blacklistę). Podaj hasło administratora, aby potwierdzić.'),
    'restart_now':   ('Restart now', 'Zrestartuj teraz'),
    'reload_title':  ('Reload Tracker Blacklist', 'Przeładuj blacklistę trackera'),
    'reload_body':   ('This runs <code>systemctl reload :svc</code> on the server, sending it a '
                      '<strong>SIGHUP</strong> so it re-reads its white/blacklist '
                      '<strong>without downtime</strong> (no dropped connections). Enter your '
                      'admin password to confirm.',
                      'To uruchamia na serwerze <code>systemctl reload :svc</code>, wysyłając mu '
                      '<strong>SIGHUP</strong>, żeby ponownie wczytał swoją white/blacklistę '
                      '<strong>bez przerwy w działaniu</strong> (żadne połączenie nie zostaje '
                      'zerwane). Podaj hasło administratora, aby potwierdzić.'),
    'reload_now':    ('Reload now', 'Przeładuj teraz'),
})
