# -*- coding: utf-8 -*-
"""Report status / block check

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


# ── report status / block check ─────────────────────────────────────────────
add('status', {
    'h1':          ('Check Report Status', 'Sprawdź status zgłoszenia'),
    'intro':       ('Enter your report number, info hash, or magnet link to check the current status.',
                    'Podaj numer zgłoszenia, info hash albo link magnet, aby sprawdzić bieżący status.'),
    'query':       ('Report Number, Info Hash or Magnet Link',
                    'Numer zgłoszenia, info hash albo link magnet'),
    'query_ph':    ('e.g. 42, a1b2c3d4e5f6... or magnet:?xt=urn:btih:...',
                    'np. 42, a1b2c3d4e5f6... albo magnet:?xt=urn:btih:...'),
    'query_err':   ('Enter a report number, 40-character info hash, or magnet link',
                    'Podaj numer zgłoszenia, 40-znakowy info hash albo link magnet'),
    'email':       ('Email address', 'Adres e-mail'),
    'email_ph':    ('Email used in the report', 'Adres e-mail użyty w zgłoszeniu'),
    'email_err':   ('Valid email address is required', 'Wymagany jest poprawny adres e-mail'),
    'submit':      ('Check Status', 'Sprawdź status'),
    'result_head': ('Your Report Status', 'Status Twojego zgłoszenia'),
    'f_number':    ('Number', 'Numer'),
    'f_reporter':  ('Reporter', 'Zgłaszający'),
    'f_email':     ('Email', 'E-mail'),
    'f_company':   ('Company', 'Firma'),
    'f_rep':       ('Representative', 'Przedstawiciel'),
    'f_object':    ('Object', 'Utwór'),
    'f_link':      ('Link', 'Odnośnik'),
    'f_hash':      ('Info Hash', 'Info Hash'),
    'f_magnet':    ('Magnet Link', 'Link magnet'),
    'f_status':    ('Status', 'Status'),
    'f_date':      ('Submission date', 'Data zgłoszenia'),
    'guide':       ('Status Guide:', 'Objaśnienie statusów:'),
    'b_pending':   ('Awaiting Review', 'Oczekuje na rozpatrzenie'),
    'b_pending_note': ('Your report has been received and is waiting for an administrator to review it.',
                       'Twoje zgłoszenie wpłynęło i czeka na rozpatrzenie przez administratora.'),
    'b_checked':   ('Reviewed', 'Rozpatrzone'),
    'b_checked_note': ('An administrator has reviewed your report.',
                       'Administrator rozpatrzył Twoje zgłoszenie.'),
    'b_blocked':   ('Blocked', 'Zablokowane'),
    'b_blocked_wl': ('The reported info hash has been banned on the tracker (removed from the '
                     'whitelist; it cannot be registered again).',
                     'Zgłoszony info hash został zbanowany na trackerze (usunięty z whitelisty; nie '
                     'można go zarejestrować ponownie).'),
    'b_blocked_bl': ('The reported info hash has been permanently added to the tracker blacklist.',
                     'Zgłoszony info hash został trwale dodany do blacklisty trackera.'),
    'b_archived':  ('Archived / Closed', 'Zarchiwizowane / zamknięte'),
    'b_archived_note': ('The report has been processed and archived. No further action will be taken '
                        'unless a new report is submitted.',
                        'Zgłoszenie zostało rozpatrzone i zarchiwizowane. Nie podejmiemy dalszych '
                        'działań, chyba że wpłynie nowe zgłoszenie.'),
    'appeal_head': ('Submit an Appeal', 'Złóż odwołanie'),
    'appeal_desc': ('If you believe this hash was blocked in error, you can submit an appeal for review.',
                    'Jeśli uważasz, że ten hash zablokowano przez pomyłkę, możesz złożyć odwołanie do '
                    'ponownego rozpatrzenia.'),
    'a_name':      ('Full Name', 'Imię i nazwisko'),
    'a_name_ph':   ('Your full name', 'Twoje imię i nazwisko'),
    'a_email':     ('Email Address', 'Adres e-mail'),
    'a_email_err': ('Invalid email address', 'Nieprawidłowy adres e-mail'),
    'a_reason':    ('Reason', 'Powód'),
    'a_reason_block_ph': ('Explain why you believe this hash should be blocked / re-examined...',
                          'Wyjaśnij, dlaczego uważasz, że ten hash powinien zostać zablokowany / '
                          'ponownie rozpatrzony...'),
    'a_reason_appeal': ('Reason for Appeal', 'Powód odwołania'),
    'a_reason_appeal_ph': ('Explain why you believe this block should be reconsidered...',
                           'Wyjaśnij, dlaczego uważasz, że tę blokadę należy ponownie rozważyć...'),
    'a_submit':    ('Submit Appeal', 'Wyślij odwołanie'),
    'required':    ('This field is required', 'To pole jest wymagane'),
    'bc_head':     ('Block Check', 'Sprawdzenie blokady'),
    'bc_intro':    ('Check if an info hash or magnet link is currently blocked on our tracker.',
                    'Sprawdź, czy info hash albo link magnet jest obecnie zablokowany na naszym trackerze.'),
    'bc_query':    ('Info Hash or Magnet Link', 'Info hash albo link magnet'),
    'bc_query_ph': ('40-char hex hash or magnet:?xt=urn:btih:...',
                    '40-znakowy hash szesnastkowy albo magnet:?xt=urn:btih:...'),
    'bc_query_err': ('Enter a valid 40-character hex hash or a magnet link',
                     'Podaj poprawny 40-znakowy hash szesnastkowy albo link magnet'),
    'bc_submit':   ('Check Block Status', 'Sprawdź status blokady'),
    'bc_result':   ('Block Check Result', 'Wynik sprawdzenia blokady'),
    'bc_whitelist': ('Whitelist', 'Whitelista'),
    'bc_company':  ('Company / Organization', 'Firma / Organizacja'),
    'bc_entity':   ('Represented Entity', 'Reprezentowany podmiot'),
})
