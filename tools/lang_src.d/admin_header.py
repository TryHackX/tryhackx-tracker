# -*- coding: utf-8 -*-
"""Admin header

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


# ── admin header / navigation bar ───────────────────────────────────────────
# Buttons in a wrapping toolbar: the Polish has to stay about as short as the English.
add('a.head', {
    'settings': ('Settings', 'Ustawienia'),
    'logout':   ('Logout', 'Wyloguj'),
})

# One key per nav item, keyed by its ?action= with hyphens turned into underscores -- see
# adminNavItems() in includes/auth.php and templates/admin/_header_actions.php.
add('a.head.nav', {
    'admin':           ('Reports', 'Zgłoszenia'),
    'admin_whitelist': ('Whitelist', 'Whitelista'),
    'admin_index':     ('Index', 'Indeks'),
    'admin_traffic':   ('Traffic', 'Ruch'),
    'admin_users':     ('Users', 'Użytkownicy'),
    'admin_backups':   ('Backups', 'Kopie zapasowe'),
    'admin_audit':     ('Log', 'Log'),
})
