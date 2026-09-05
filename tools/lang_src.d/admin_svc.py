# -*- coding: utf-8 -*-
"""Admin tracker-service card

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


# ── admin tracker-service card ──────────────────────────────────────────────
add('a.svc', {
    'warn_aria':     ('Tracker restart recommendations',
                      'Zalecenia restartu trackera'),
    'reload':        ('Reload', 'Przeładuj'),
    'reload_title':  ('Reload the tracker blacklist (SIGHUP, no downtime) — :svc',
                      'Przeładuj blacklistę trackera (SIGHUP, bez przerwy w działaniu) — :svc'),
    'restart':       ('Restart tracker', 'Zrestartuj tracker'),
    'restart_title': ('Restart the tracker service (:svc)',
                      'Zrestartuj usługę trackera (:svc)'),
})
