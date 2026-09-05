# -*- coding: utf-8 -*-
"""
Settings page.

PARTIAL: only the section that survived a translation run is keyed. Strings that were never
touched are still literal English in templates/admin/settings.php -- which renders correctly,
because an untouched string is not a missing key. Finishing this file means keying more of it,
not repairing anything.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('', {
    'settings.donation_field_label_ph': ('Label', 'Etykieta'),
    'settings.donation_field_remove_title': ('Remove', 'Usuń'),
    'settings.donation_field_value_ph': ('Address, hash, or URL', 'Adres, hash lub URL'),
    'settings.js_asking_helper': ('Asking the helper&hellip;', 'Pytam pomocnika&hellip;'),
    'settings.js_cancelled': ('Cancelled.', 'Anulowano.'),
    'settings.js_checking_fw_helper': ('Checking the firewall helper&hellip;', 'Sprawdzam pomocnika firewalla&hellip;'),
    'settings.js_cluster_test_ok': ('The panel can manage extra instances from here.', 'Panel może stąd zarządzać dodatkowymi instancjami.'),
    'settings.js_current_password_required': ('Current password is required.', 'Podaj aktualne hasło.'),
    'settings.js_dropin_readonly': ('<span class="text-warning">(read-only for this process)</span>', '<span class="text-warning">(tylko do odczytu dla tego procesu)</span>'),
    'settings.js_email_change_started': ('Email change started.', 'Rozpoczęto zmianę adresu e-mail.'),
    'settings.js_error': ('Error', 'Błąd'),
    'settings.js_meta_command': ('Command: ', 'Polecenie: '),
    'settings.js_meta_cores': ('cores: ', 'rdzenie: '),
    'settings.js_meta_cpu_cores': ('CPU cores: ', 'rdzenie CPU: '),
    'settings.js_meta_dropin_dir': ('drop-in dir: ', 'katalog drop-in: '),
    'settings.js_meta_os': ('OS: ', 'OS: '),
    'settings.js_meta_output': ('Output: ', 'Wynik: '),
    'settings.js_meta_php_user': ('PHP user: ', 'użytkownik PHP: '),
    'settings.js_meta_unit': ('Unit: ', 'Unit: '),
    'settings.js_netlimit_ok': ('The panel can load and remove the inbound limit.', 'Panel może załadować i usunąć limit ruchu przychodzącego.'),
    'settings.js_network_error': ('Network error.', 'Błąd sieci.'),
    'settings.js_network_error_short': ('Network error', 'Błąd sieci'),
    'settings.js_nothing_to_change': ('Nothing to change.', 'Nie ma czego zmieniać.'),
    'settings.js_ot_test_ok': ('The panel can read the unit and write its own drop-in.', 'Panel może odczytać unit i zapisać własny drop-in.'),
    'settings.js_password_need_digit': ('New password must contain at least one digit.', 'Nowe hasło musi zawierać co najmniej jedną cyfrę.'),
    'settings.js_password_need_lower': ('New password must contain at least one lowercase letter.', 'Nowe hasło musi zawierać co najmniej jedną małą literę.'),
    'settings.js_password_need_special': ('New password must contain at least one special character.', 'Nowe hasło musi zawierać co najmniej jeden znak specjalny.'),
    'settings.js_password_need_upper': ('New password must contain at least one uppercase letter.', 'Nowe hasło musi zawierać co najmniej jedną wielką literę.'),
    'settings.js_password_too_short': ('New password must be at least 10 characters long.', 'Nowe hasło musi mieć co najmniej 10 znaków.'),
    'settings.js_passwords_mismatch': ('New passwords do not match.', 'Nowe hasła nie są takie same.'),
    'settings.js_save_error': ('Error saving settings', 'Błąd zapisu ustawień'),
    'settings.js_saved_ok': ('Settings saved successfully.', 'Ustawienia zapisane.'),
    'settings.js_saved_short': ('Saved successfully.', 'Zapisano.'),
    'settings.js_saving': ('Saving...', 'Zapisuję...'),
    'settings.js_saving_ellipsis': ('Saving…', 'Zapisuję…'),
    'settings.js_sysctl_test_ok': ('The panel can reach the kernel-buffer helper.', 'Panel ma dostęp do pomocnika bufora jądra.'),
    'settings.js_test_failed': ('Test failed', 'Test nie powiódł się'),
    'settings.js_testing': ('Testing...', 'Testuję...'),
    'settings.sched_all_day': ('whitelist 00:00–24:00', 'whitelist 00:00–24:00'),
    'settings.sched_ends_next_day_at': ('ends next day at ', 'kończy się następnego dnia o '),
    'settings.sched_open_mode': ('open (blacklist) mode', 'tryb otwarty (blacklist)'),
    'settings.sched_same_day': ('same day', 'ten sam dzień'),
    'settings.sched_set_both_times': ('set both times', 'ustaw obie godziny'),
})
