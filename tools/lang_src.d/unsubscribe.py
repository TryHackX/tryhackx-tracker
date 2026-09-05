# -*- coding: utf-8 -*-
"""Unsubscribe

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


# ── unsubscribe ─────────────────────────────────────────────────────────────
add('unsub', {
    'h1':        ('Notification Preferences', 'Ustawienia powiadomień'),
    'bad_link':  ('Invalid or expired unsubscribe link.',
                  'Link do wypisania jest nieprawidłowy albo wygasł.'),
    'desc':      ('Manage which email notifications you receive for <strong>:email</strong>',
                  'Zarządzaj powiadomieniami e-mail, które otrzymujesz na adres '
                  '<strong>:email</strong>'),

    'all':       ('All Notifications', 'Wszystkie powiadomienia'),
    'all_hint':  ('Master switch — turns all notifications on or off',
                  'Przełącznik główny — włącza albo wyłącza wszystkie powiadomienia'),

    'submission': ('Submission Confirmations', 'Potwierdzenia zgłoszeń'),
    'submission_hint': ('Confirmation email after submitting a new report',
                        'E-mail z potwierdzeniem po wysłaniu nowego zgłoszenia'),

    'review':    ('Under Review', 'W trakcie rozpatrywania'),
    'review_hint': ('Notification when an admin starts reviewing your report',
                    'Powiadomienie, gdy administrator zacznie rozpatrywać Twoje zgłoszenie'),

    'status':    ('Status Updates', 'Zmiany statusu'),
    'status_hint': ('Notifications when your report status changes (reviewed, blocked, archived)',
                    'Powiadomienia o zmianie statusu Twojego zgłoszenia (rozpatrzone, zablokowane, '
                    'zarchiwizowane)'),

    'custom':    ('Admin Messages', 'Wiadomości od administratora'),
    'custom_hint': ('Custom messages sent by the admin regarding your report',
                    'Wiadomości wysyłane przez administratora w sprawie Twojego zgłoszenia'),

    'appeal':    ('Appeal Notifications', 'Powiadomienia o odwołaniach'),
    'appeal_hint': ('Confirmation and decision emails for appeals you submit',
                    'E-maile z potwierdzeniem i decyzją w sprawie odwołań, które składasz'),

    # The save button and the four outcomes a save can report. All of these reach the page through
    # its inline script as json_encode(__(...)) and land in textContent, so they stay plain text --
    # no markup, no entities. The button label is also rendered in the markup itself.
    'save':      ('Save Preferences', 'Zapisz ustawienia'),
    'saving':    ('Saving...', 'Zapisywanie...'),
    'saved':     ('Preferences saved successfully.', 'Ustawienia zostały zapisane.'),
    'save_failed': ('Failed to save preferences.', 'Nie udało się zapisać ustawień.'),
    'net_error': ('Network error. Please try again.', 'Błąd sieci. Spróbuj ponownie.'),
})
