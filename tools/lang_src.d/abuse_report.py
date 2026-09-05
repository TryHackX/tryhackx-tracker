# -*- coding: utf-8 -*-
"""Abuse report

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


# ── abuse report ────────────────────────────────────────────────────────────
add('report', {
    'h1':        ('Abuse Report Form', 'Formularz zgłoszenia nadużycia'),
    'intro':     ('Use this form to report a copyright infringement regarding a torrent tracked by '
                  'our tracker.',
                  'Użyj tego formularza, aby zgłosić naruszenie praw autorskich dotyczące torrenta '
                  'śledzonego przez nasz tracker.'),
    'name':      ('Full name', 'Imię i nazwisko'),
    'name_ph':   ('John Smith', 'Jan Kowalski'),
    'rep':       ('Represented entity', 'Reprezentowany podmiot'),
    'rep_ph':    ('Name of the entity whose rights were infringed',
                  'Nazwa podmiotu, którego prawa naruszono'),
    'company':   ('Company / Organization', 'Firma / Organizacja'),
    'company_ph': ('Your company name', 'Nazwa Twojej firmy'),
    'email':     ('Email address', 'Adres e-mail'),
    'email_err': ('Invalid email address', 'Nieprawidłowy adres e-mail'),
    'object':    ('Object title', 'Tytuł utworu'),
    'object_ph': ('Title of the infringing work', 'Tytuł naruszającego utworu'),
    'link':      ('Link to the torrent page', 'Link do strony torrenta'),
    'link_err':  ('Invalid URL', 'Nieprawidłowy adres URL'),
    'hash':      ('Info Hash (SHA1, 40 hex characters)', 'Info Hash (SHA1, 40 znaków szesnastkowych)'),
    'hash_err':  ('Info Hash must be exactly 40 hexadecimal characters (0-9, a-f)',
                  'Info Hash musi mieć dokładnie 40 znaków szesnastkowych (0-9, a-f)'),
    'magnet':    ('Magnet Link (optional)', 'Link magnet (opcjonalnie)'),
    'magnet_err': ('Invalid magnet link or hash mismatch with Info Hash field',
                   'Nieprawidłowy link magnet albo hash niezgodny z polem Info Hash'),
    'message':   ('Additional information (optional)', 'Dodatkowe informacje (opcjonalnie)'),
    'message_ph': ('Additional details about the report...', 'Dodatkowe szczegóły zgłoszenia...'),
    'message_err': ('Message exceeds the maximum allowed length',
                    'Wiadomość przekracza maksymalną dozwoloną długość'),
    'required':  ('This field is required', 'To pole jest wymagane'),
    'submit':    ('Submit Report', 'Wyślij zgłoszenie'),
})
