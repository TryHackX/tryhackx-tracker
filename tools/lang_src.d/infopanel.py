S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('settings', {
    'info_resize': ('Resizable Info panel', 'Zmiana rozmiaru panelu Info'),
    'info_resize_hint': ('People holding info.resize can drag the Info panel\'s edges. The size stays in their browser; a button in the panel puts it back.',
                         'Osoby z uprawnieniem info.resize mogą przeciągać krawędzie panelu Info. Rozmiar zostaje w ich przeglądarce, a przycisk w panelu przywraca domyślny.'),
    'info_desc_fold': ('Fold descriptions taller than (px)', 'Zwijaj opisy wyższe niż (px)'),
    'info_desc_fold_hint': ('Such a description opens folded in the Info panel, with a button that shows all of it. 0 = never fold.',
                            'Taki opis otwiera się w panelu Info zwinięty, z przyciskiem pokazującym całość. 0 = nigdy nie zwijaj.'),
})

add('js.app', {
    'desc_unfold': ('Show the whole description', 'Pokaż cały opis'),
    'desc_fold': ('Fold the description', 'Zwiń opis'),
    'info_size_reset': ('Default size', 'Domyślny rozmiar'),
    'info_size_reset_tip': ('Put the panel back to its default size', 'Przywróć domyślny rozmiar panelu'),
})
