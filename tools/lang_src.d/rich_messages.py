# -*- coding: utf-8 -*-
"""Rich messages: HTML campaigns, formatted notifications, BBCode / Markdown in what a moderator writes (1.75.0, part B)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

includes/htmlsafe.php, includes/bulkmail.php and api/admin/bulk_send.php (api.bulk.* beyond the ones in
api.py), includes/reports.php reportTextValidate() (api.report_text.*), the notifications that carry a
formatted reason or answer (notify.*_lead), the bulk composer's frame switch (a.users.frame_*), the two
settings of the release (settings.bulk_mail_daily_cap, settings.mail_sender_address) and the panel's one
box for words with a format -- adminRichField() in assets/js/admin-common.js (js.rich.*), with the few
words the windows that use it say around it (js.creport.*, js.users.*, js.msgrep.*, js.reports.*) and the
account page's frame for an HTML notification (js.app.notif_rich_frame).
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── bulk messages: what the server refuses (api/admin/bulk_send.php, includes/bulkmail.php bulkBodyProblem()) ──
add('api.bulk', {
    'too_long':         ('The message is longer than :max bytes — shorten it. (A copy is kept for every recipient, so the limit is in bytes, not characters.)',
                         'Wiadomość jest dłuższa niż :max bajtów — skróć ją. (Dla każdego odbiorcy zapisywana jest osobna kopia, dlatego limit jest w bajtach, a nie w znakach.)'),
    'html_unavailable': ('This server cannot read HTML (the PHP “dom” extension is missing), so a message written as HTML cannot be sent. Install php-xml, or choose another format.',
                         'Ten serwer nie potrafi czytać HTML (brakuje rozszerzenia PHP „dom”), więc wiadomość napisana w HTML nie może zostać wysłana. Zainstaluj php-xml albo wybierz inną składnię.'),
    'html_empty':       ('There is nothing in this HTML that can be sent: scripts, styles, forms, frames and comments are removed, and nothing else was left.',
                         'W tym HTML nie ma nic, co można wysłać: skrypty, style, formularze, ramki i komentarze są usuwane, a nic innego nie zostało.'),
    'doc_too_big':      ('As an e-mail this message comes to :kb KB, and the limit is :max KB. Gmail shows no more than about 102 KB and hides the rest — the unsubscribe link with it. Shorten the text or simplify the markup (every inline style and table cell adds to it).',
                         'Jako e-mail ta wiadomość ma :kb KB, a limit to :max KB. Gmail pokazuje najwyżej ok. 102 KB, a resztę ukrywa — razem z linkiem do wypisania się. Skróć tekst albo uprość znaczniki (dodają się do niego wszystkie style i komórki tabel).'),
    'html_flattened':   ('Part of this HTML (:n place(s)) is nested more than :max levels deep. Its words are in the message, but its layout is not — flatten the design to keep it.',
                         'Część tego HTML (miejsc: :n) jest zagnieżdżona głębiej niż :max poziomów. Jej słowa są w wiadomości, ale układ już nie — spłaszcz projekt, aby go zachować.'),
})

# ── the words a moderator writes (reportTextValidate(), includes/reports.php) ──────────────────────────────
add('api.report_text', {
    'too_long':       ('That text is :n characters long; the limit is :max.',
                       'Ten tekst ma :n znaków; limit to :max.'),
    'bad_format':     ('That format is not accepted here.',
                       'Ta składnia nie jest tu akceptowana.'),
    'no_images':      ('Pictures are not allowed in this text.',
                       'Obrazy nie są dozwolone w tym tekście.'),
    'too_many_links': ('That text has :n links; the limit is :max.',
                       'Ten tekst ma :n odnośników; limit to :max.'),
    'refused':        ('That text is not accepted: :why',
                       'Ten tekst nie został przyjęty: :why'),
    'required':       ('Write the message first.',
                       'Najpierw napisz wiadomość.'),
})

# ── a notification with a formatted reason or answer: the plain line that stands above it (moderationNoticeWarning(), the reporters' notices) ──
add('notify', {
    'warn_reason_lead':   ('The moderator’s reason:',
                           'Powód podany przez moderatora:'),
    'report_reply_lead':  ('The moderator wrote:',
                           'Moderator napisał(a):'),
})

# ── Admin → Users → Write to members: the frame switch ────────────────────────────────────────────────────
add('a.users', {
    'frame_label': ('Site frame',
                    'Ramka serwisu'),
    'frame_title': ('Wrap the message in the site’s dark frame (header and colours). Untick it to send your own design as it is — the footer with the preferences and unsubscribe links is always added.',
                    'Umieść wiadomość w ciemnej ramce serwisu (nagłówek i kolory). Odznacz, aby wysłać własny projekt bez zmian — stopka z linkiem do ustawień i wypisania się jest dodawana zawsze.'),
})

# ── Settings ─────────────────────────────────────────────────────────────────────────────────────────────
add('settings', {
    'bulk_mail_daily_cap':       ('Bulk mail per day (cap)',
                                  'Maili masowych na dobę (limit)'),
    'bulk_mail_daily_cap_hint':  ('The most messages the janitor sends in a day, counted from the database’s midnight. <strong>0 = no cap</strong>, the default. What is over waits for tomorrow.',
                                  'Najwięcej wiadomości, jakie janitor wyśle w ciągu doby, liczonej od północy bazy danych. <strong>0 = bez limitu</strong>, wartość domyślna. Co zostanie ponad limit, czeka do jutra.'),
    'mail_sender_address':       ('Sender identity (footer of bulk mail)',
                                  'Dane nadawcy (stopka maili masowych)'),
    'mail_sender_address_hint':  ('Your name and postal address, shown in the footer of every bulk message beside the preferences link — the rules for commercial mail in most countries ask for it. Optional; empty shows nothing.',
                                  'Twoja nazwa i adres pocztowy, wyświetlane w stopce każdej wiadomości masowej obok linku do ustawień — przepisy o poczcie handlowej w większości krajów tego wymagają. Opcjonalne; puste nic nie pokazuje.'),
})

# ── adminRichField(): the panel's one box for words with a format (assets/js/admin-common.js) ─────────────────
add('js.rich', {
    'toolbar':      ('Formatting', 'Formatowanie'),
    'bold':         ('Bold (Ctrl+B)', 'Pogrubienie (Ctrl+B)'),
    'italic':       ('Italic (Ctrl+I)', 'Kursywa (Ctrl+I)'),
    'underline':    ('Underline', 'Podkreślenie'),
    'strike':       ('Strikethrough', 'Przekreślenie'),
    'color':        ('Colour', 'Kolor'),
    'size':         ('Font size', 'Rozmiar czcionki'),
    'highlight':    ('Highlight', 'Wyróżnienie'),
    'link':         ('Link (Ctrl+K)', 'Odnośnik (Ctrl+K)'),
    'image':        ('Image', 'Obraz'),
    'list':         ('Bulleted list', 'Lista wypunktowana'),
    'olist':        ('Numbered list', 'Lista numerowana'),
    'quote':        ('Quote', 'Cytat'),
    'code':         ('Code', 'Kod'),
    'table':        ('Table', 'Tabela'),
    'center':       ('Centre', 'Wyśrodkowanie'),
    'hr':           ('Horizontal rule', 'Linia pozioma'),
    'format_title': ('How the text is written', 'W jakiej składni jest napisany tekst'),
    'preview':      ('Preview', 'Podgląd'),
    'preview_mail': ('Preview of the message as it will be sent', 'Podgląd wiadomości w takiej postaci, w jakiej zostanie wysłana'),
    'hint_html':    ('HTML: tables, inline styles, links and https images are kept. <style> blocks, class attributes, scripts, forms and frames are dropped — put your CSS inline (style="…").',
                     'HTML: tabele, style w atrybutach, odnośniki i obrazy po https są zachowywane. Bloki <style>, atrybuty class, skrypty, formularze i ramki są usuwane — wstaw CSS bezpośrednio w znaczniki (style="…").'),
    'count':        (':n / :max characters', ':n / :max znaków'),
    'count_bytes':  (':n / :max bytes', ':n / :max bajtów'),
    'bulk_ph':      ('Line breaks are kept.', 'Podziały wierszy są zachowywane.'),
})

# ── the words the windows that use it say around it ───────────────────────────────────────────────────────
add('js.creport', {
    'warning_head': (':at —', ':at —'),
})
add('js.users', {
    'warning_head': (':at — :action (:by):', ':at — :action (:by):'),
})
add('js.msgrep', {
    'answered_lead': ('Answered:', 'Odpowiedziano:'),
})
add('js.reports', {
    'email_ph':           ('e.g. We have reviewed your report and would like to request additional documentation…',
                           'np. Rozpatrzyliśmy Twoje zgłoszenie i prosimy o dodatkową dokumentację…'),
    'appeal_response_ph': ('Optional response message to the appellant…',
                           'Opcjonalna wiadomość zwrotna do odwołującego się…'),
})

# ── the account page: the frame an HTML notification is shown in ─────────────────────────────────────────
add('js.app', {
    'notif_rich_frame': ('The message', 'Wiadomość'),
})
