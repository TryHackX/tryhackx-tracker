# -*- coding: utf-8 -*-
"""Admin — backups (schedule, archives, restore)

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


# ── admin — backups (schedule, archives, restore) ───────────────────────────
add('a.backups', {
    # page chrome
    'h1':          ('Backups', 'Kopie zapasowe'),
    'subtitle':    ('database &amp; configuration archives',
                    'archiwa bazy danych i konfiguracji'),

    # status card
    'status_head': ('Backup status', 'Stan kopii zapasowych'),
    'run_title':   ('Make a backup now, with the profile from Settings',
                    'Zrób kopię zapasową teraz, z profilem z Ustawień'),
    'run_btn':     ('Back up now', 'Zrób kopię teraz'),
    'cancel_title': ('Stop the backup that is running',
                     'Zatrzymaj trwające tworzenie kopii'),
    'cancel_btn':  ('Cancel run', 'Przerwij'),
    'prune_title': ('Apply the retention rules from Settings right now',
                    'Zastosuj teraz reguły retencji z Ustawień'),
    'prune_btn':   ('Rotate now', 'Rotuj teraz'),
    'asking':      ('Asking the server&hellip;', 'Pytam serwer&hellip;'),
    'working':     ('Working…', 'Pracuję…'),

    # the archive list
    'archives':      ('Archives', 'Archiwa'),
    'col_when':      ('When', 'Kiedy'),
    'col_profile':   ('Profile', 'Profil'),
    'col_size':      ('Size', 'Rozmiar'),
    'col_contents':  ('Contents', 'Zawartość'),
    'col_integrity': ('Integrity', 'Integralność'),
    'col_actions':   ('Actions', 'Akcje'),

    # the note under the table
    'help_secret': ('An archive holds every database password on this machine.',
                    'Archiwum zawiera każde hasło do bazy danych na tej maszynie.'),
    'help_where':  ('They live in a directory only <code>root</code> can enter, the panel reads '
                    'them through the root helper, and a download link is single-use and expires '
                    'after :seconds seconds.',
                    'Archiwa leżą w katalogu, do którego dostęp ma tylko <code>root</code>, panel '
                    'czyta je przez helper roota, a link do pobrania jest jednorazowy i wygasa po '
                    ':seconds sekundach.'),
    'help_offsite': ('Keep a copy <strong>off this server</strong> — a backup on the same disk as '
                     'the thing it protects is not a backup.',
                     'Trzymaj kopię <strong>poza tym serwerem</strong> — kopia zapasowa na tym '
                     'samym dysku co dane, które chroni, nie jest kopią zapasową.'),

    # the password modal every action goes through
    'modal_title':     ('Confirm', 'Potwierdzenie'),
    'what_to_back_up': ('What to back up', 'Co zarchiwizować'),
    'type_db_name':    ('Type the database name to confirm',
                        'Wpisz nazwę bazy danych, aby potwierdzić'),
    'admin_password':  ('Admin Password', 'Hasło administratora'),
    'confirm_btn':     ('Confirm', 'Potwierdź'),

    # restore
    'restore_from':      ('Restore from', 'Przywróć z'),
    'restore_pick':      ('Pick exactly what should come back.',
                          'Wybierz dokładnie to, co ma wrócić.'),
    'restore_bak':       ('Every file that gets overwritten keeps a <code>.bak-&lt;stamp&gt;</code> '
                          'copy next to it, so a restore is itself reversible.',
                          'Każdy nadpisywany plik zostawia obok siebie kopię '
                          '<code>.bak-&lt;stamp&gt;</code>, więc przywracanie samo w sobie jest '
                          'odwracalne.'),
    'restore_dry_first': ('Start with <strong>Dry run</strong> — it lists what would happen and '
                          'changes nothing.',
                          'Zacznij od <strong>Symulacji</strong> — wypisze, co by się stało, '
                          'i niczego nie zmieni.'),
    'db_separate':       ('The database is separate.', 'Baza danych to osobna sprawa.'),
    'db_separate_note':  ('Restoring it overwrites live data, so it is its own button: it asks you '
                          'to type the database name, and the server dumps the database as it is '
                          'right now <em>before</em> importing anything.',
                          'Jej przywrócenie nadpisuje bieżące dane, więc ma własny przycisk: poprosi '
                          'Cię o wpisanie nazwy bazy, a serwer zrzuci bazę w stanie z tej chwili '
                          '<em>zanim</em> cokolwiek zaimportuje.'),
    'restore_db_btn':    ('Restore the database', 'Przywróć bazę danych'),
    'dry_run':           ('Dry run', 'Symulacja'),
    'restore_files_btn': ('Restore files', 'Przywróć pliki'),
})
