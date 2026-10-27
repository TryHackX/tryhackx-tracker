S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('a.img', {
    'help': ('When a picture is public, and when it is removed', 'Kiedy obraz jest publiczny i kiedy znika'),
})

add('js.aimg', {
    'stat_pictures': ('Pictures', 'Obrazy'),
    'stat_space': ('Space', 'Miejsce'),
    'stat_public': ('Public', 'Publiczne'),
    'stat_private': ('Not public yet', 'Jeszcze niepubliczne'),
    'stat_copied': ('Copied', 'Skopiowane'),
    'stat_queue': ('Waiting to be copied', 'Czeka na skopiowanie'),
    'where_short': ('Where used', 'Gdzie użyty'),
    'err_http': ('the server answered :code', 'serwer odpowiedział :code'),
    'err_own_site': ('an address of this site', 'adres tej strony'),
})

add('js.comments', {
    'img_drop': ('Drop pictures here, or choose files', 'Upuść tu obrazy albo wybierz pliki'),
})
