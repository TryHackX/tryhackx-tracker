S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('js.app', {
    'rt_menu_insert': ('Insert', 'Wstaw'),
    'rt_menu_para': ('Paragraph', 'Akapit'),
    'rt_magnet': ('Magnet link', 'Link magnet'),
    'rt_youtube': ('YouTube video', 'Film z YouTube'),
    'rt_hide': ('Only for signed-in members', 'Tylko dla zalogowanych'),
    'rt_h1': ('Large heading', 'Duży nagłówek'),
    'rt_h2': ('Medium heading', 'Średni nagłówek'),
    'rt_h3': ('Small heading', 'Mały nagłówek'),
    'rt_left': ('Align left', 'Do lewej'),
    'rt_right': ('Align right', 'Do prawej'),
    'rt_quote_author': ('Quote with its author', 'Cytat z autorem'),
    'rt_alist': ('List a, b, c', 'Lista a, b, c'),
    'rt_rlist': ('List i, ii, iii', 'Lista i, ii, iii'),
})
