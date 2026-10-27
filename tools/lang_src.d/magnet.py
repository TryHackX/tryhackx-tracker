S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('rt.magnet', {
    'unnamed': ('Magnet link', 'Link magnet'),
    'open': ('Open in your torrent client', 'Otwórz w kliencie torrent'),
    'copy': ('Copy the magnet link', 'Kopiuj link magnet'),
    'info': ('Details on this tracker', 'Szczegóły na tym trackerze'),
    'locked': ('The link itself is shown to members who may see magnet links.',
               'Sam link widzą członkowie, którzy mogą oglądać linki magnet.'),
})

add('js.magnet', {
    'seeders': ('Seeders', 'Seedy'),
    'leechers': ('Leechers', 'Leechy'),
    'completed': ('Completed downloads', 'Ukończone pobrania'),
    'copied': ('Copied', 'Skopiowano'),
    'copy_failed': ('Could not copy', 'Nie udało się skopiować'),
    'copy_box': ('The magnet link, selected for copying', 'Link magnet, zaznaczony do skopiowania'),
})
