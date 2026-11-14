# -*- coding: utf-8 -*-
"""A vote taken back with a second press (1.71.0, part C)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The Info panel's thumbs and stars (assets/js/app.js, buildStars() and the thumbs in openInfo()): the one
already cast is pressed, and its tooltip says what a second press does. The rest of the vote's words were
there already (js.app.vote_good_title, js.app.stars_many, ...). The capitalised Twoja / Twój of the rest of
the site's Polish.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('js.app', {
    # the pressed thumb (Good or Bad, the button's own word beside it)
    'vote_mine_title': ('Your vote — press again to take it back',
                        'Twój głos — naciśnij ponownie, aby go wycofać'),
    # the pressed half star; :stars is the half star's own words ("3.5 stars", "1 star")
    'stars_mine_title': (':stars — your rating; press again to take it back',
                         ':stars — Twoja ocena; naciśnij ponownie, aby ją wycofać'),
})

# api/rate_hash.php: an `op` that is neither a vote nor taking one back
add('api.rep', {
    'unknown_op': ('Unknown operation: a vote is cast or taken back.',
                   'Nieznana operacja: głos można oddać albo wycofać.'),
})
