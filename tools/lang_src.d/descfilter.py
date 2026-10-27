S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('search', {
    'desc_only': ('Only with a description', 'Tylko z opisem'),
    'desc_only_title': ('Show only torrents that have a published description', 'Pokaż tylko torrenty z opublikowanym opisem'),
    'in_desc': ('Also search descriptions', 'Szukaj też w opisach'),
    'in_desc_title': ('Match the words in the text of published descriptions as well as in names', 'Szukaj słów także w treści opublikowanych opisów, nie tylko w nazwach'),
})

add('js.app', {
    'desc_has_text': ('Has a description', 'Ma opis'),
    'desc_has_pics': ('The description has pictures', 'Opis ma obrazy'),
    'desc_has_video': ('The description has a video', 'Opis ma wideo'),
})

add('js.index', {
    'desc_text': ('Has a published description', 'Ma opublikowany opis'),
    'desc_pics': ('The description has pictures', 'Opis ma obrazy'),
    'desc_video': ('The description has a video', 'Opis ma wideo'),
})

add('a.index', {
    'f_desc_title': ('Description', 'Opis'),
    'd_all': ('With or without a description', 'Z opisem i bez'),
    'd_yes': ('With a description', 'Z opisem'),
})

add('a.wl', {
    'content_filter': ('Description', 'Opis'),
    'content_any': ('Any description state', 'Każdy stan opisu'),
    'content_approved': ('Description published', 'Opis opublikowany'),
    'content_pending': ('Description waiting', 'Opis czeka'),
    'content_rejected': ('Description rejected', 'Opis odrzucony'),
    'content_none': ('No description', 'Bez opisu'),
})
