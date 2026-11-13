# -*- coding: utf-8 -*-
"""The limits, the panel's secrets and the sign-in bridge's preconditions (1.74.0)

What the security release added to say: the limiter's storage on the dashboard's warning card and in the health
endpoint (includes/functions.php rateLimitHealth()), the backup download token that has to have been issued
(includes/backup.php), the footer's addresses (api/admin/save_settings.php), the Settings fields that never print
a secret, the daily cap on report and appeal confirmation mails, and the bridge's need for a site URL.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the limiter's storage (warning card + ?action=health; plain text, the card escapes it) ──────────
add('api.ratelimit', {
    'health_dir': ('The rate-limit state cannot be written in :path — every per-address limit and the panel\'s sign-in lockout let everything through until it can. Make it writable for the web server\'s user.',
        'Stanu limitów nie da się zapisać w :path — każdy limit na adres i blokada logowania do panelu przepuszczają wszystko, dopóki to się nie uda. Daj użytkownikowi serwera WWW prawo zapisu.'),
    'health_file': ('The rate-limit file :path cannot be read or written (or is not a file) — its limit lets everything through until it can.',
        'Pliku limitów :path nie da się odczytać albo zapisać (albo nie jest plikiem) — jego limit przepuszcza wszystko, dopóki to się nie zmieni.'),
    'health_bad': ('The rate-limit file :path could not be read and was set aside — its count started over. Delete it once you have looked at it; it goes by itself after a week.',
        'Pliku limitów :path nie dało się odczytać i został odłożony na bok — jego liczenie zaczęło się od nowa. Usuń go, gdy już go obejrzysz; sam zniknie po tygodniu.'),
})

# ── backups: a download link has to have been issued here ────────────────────────────────────────────
add('api.backup', {
    'dl_not_issued': ('This download link was not issued by this panel. Ask for a new one on the Backups page.',
        'Ten link do pobrania nie został wydany przez ten panel. Poproś o nowy na stronie Kopii zapasowych.'),
    'state_unwritable': ('The backups\' bookkeeping (config/backup_state.json) cannot be written, so a download link can be neither issued nor used. Check that the web server\'s user can write to config/.',
        'Nie da się zapisać ewidencji kopii zapasowych (config/backup_state.json), więc linku do pobrania nie można ani wydać, ani użyć. Sprawdź, czy użytkownik serwera WWW może pisać w config/.'),
})

# ── Settings: the footer's addresses ─────────────────────────────────────────────────────────────────
add('api.settings', {
    'footer_url_invalid': ('A footer address has to be a full http:// or https:// address — not saved: :entry',
        'Adres w stopce musi być pełnym adresem http:// albo https:// — nie zapisano: :entry'),
})

# ── Settings: the secrets that are never printed, the daily cap, the bridge ──────────────────────────
add('settings', {
    'secret_set': ('Set — leave empty to keep it, type a new one to replace it',
        'Ustawiony — zostaw puste, aby go zachować; wpisz nowy, aby go zastąpić'),
    'secret_unset': ('Not set — type one to set it',
        'Nieustawiony — wpisz, aby go ustawić'),
    'secret_hint': ('Never shown again once saved, not even here. An empty field keeps what is stored; a new value asks for the owner password.',
        'Po zapisaniu nie jest już nigdzie pokazywany, także tutaj. Puste pole zachowuje zapisaną wartość; nowa wartość wymaga hasła właściciela.'),
    # 1.74.1: the button beside the HMAC key, and the note that appears once the field holds a new one
    'hmac_generate': ('Generate new',
        'Wygeneruj nowy'),
    'hmac_generate_note': ('A new key is in the field. It takes effect when you save the settings (the owner password is asked), and backup download links and unsubscribe links signed with the old key stop working.',
        'Nowy klucz jest w polu. Zacznie działać po zapisaniu ustawień (zapytamy o hasło właściciela), a linki do pobrania kopii zapasowych i linki wypisania podpisane starym kluczem przestaną działać.'),
    'health_token_clear': ('Switch the endpoint off (remove the token)',
        'Wyłącz endpoint (usuń token)'),
    'health_token_in_url': ('YOUR_TOKEN',
        'TWOJ_TOKEN'),
    'health_url_token_note': ('The token itself is not shown: put the one you saved in place of YOUR_TOKEN — or, better, send it as the <code>X-Health-Token</code> header and leave it out of the address.',
        'Sam token nie jest pokazywany: wstaw zapisany token w miejsce TWOJ_TOKEN — albo, lepiej, wysyłaj go w nagłówku <code>X-Health-Token</code> i pomiń go w adresie.'),
    'confirm_mail_cap': ('Report and appeal confirmations per day',
        'Potwierdzenia zgłoszeń i odwołań na dzień'),
    'confirm_mail_cap_hint': ('The most confirmation mails the whole site sends in a day — each goes to an address the sender typed and nobody verified. Past it, reports and appeals are still taken, without the mail. 0 = none at all. Sent today: :n.',
        'Najwięcej maili z potwierdzeniem, ile cała strona wyśle w ciągu dnia — każdy idzie na adres wpisany przez nadawcę, którego nikt nie sprawdził. Po przekroczeniu zgłoszenia i odwołania są nadal przyjmowane, tylko bez maila. 0 = żadnych. Dziś wysłano: :n.'),
    'bridge_needs_site_url': ('The site URL (Site Configuration) is empty or not an http(s) address. The bridge builds its sign-in link from it and refuses every sign-in until it is set.',
        'Adres strony (Konfiguracja strony) jest pusty albo nie jest adresem http(s). Most buduje z niego link logowania i odmawia każdego logowania, dopóki go nie ustawisz.'),
})
