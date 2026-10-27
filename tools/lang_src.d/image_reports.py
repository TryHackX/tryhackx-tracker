# -*- coding: utf-8 -*-
"""Reports of pictures (1.83.0)

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


add('api.creport', {
    'own_image': ('That is your own picture: you cannot report it.',
                  'To Twój własny obraz — nie możesz go zgłosić.'),
})

add('notify', {
    'creport_removed_image': ('The picture you reported was removed',
                              'Zgłoszony przez Ciebie obraz został usunięty'),
    'creport_what_image':    ('a picture',
                              'obrazu'),
    'warn_removed_image':    ('Warning: your picture was removed',
                              'Ostrzeżenie: Twój obraz został usunięty'),
    'warn_about_image':      ('Warning about your picture',
                              'Ostrzeżenie w sprawie Twojego obrazu'),
})

add('js.report', {
    'tip_image': ('Report this picture to the moderators',
                  'Zgłoś ten obraz moderatorom'),
})

add('a.reports', {
    'tab_images': ('Pictures', 'Obrazy'),
})

add('js.creport', {
    'kind_image':          ('Picture', 'Obraz'),
    'head_image':          ('A picture uploaded by :user', 'Obraz wgrany przez :user'),
    'head_image_copy':     ('A picture copied from the forum', 'Obraz skopiowany z forum'),
    'remove_image':        ('Remove the picture', 'Usuń obraz'),
    'remove_image_q':      ('Remove this picture from the site? Where it was shown, readers see that moderation removed it.',
                            'Usunąć ten obraz z serwisu? Tam, gdzie był pokazany, czytelnicy zobaczą, że usunęła go moderacja.'),
    'block_image':         ('and block this file: it cannot be uploaded or copied from the forum again, and its other copies here go too',
                            'i zablokuj ten plik: nie da się go ponownie wgrać ani skopiować z forum, a jego inne kopie tutaj też znikną'),
    'used_in':             ('Used in:', 'Użyty w:'),
    'not_used':            ('Not used anywhere now.', 'Obecnie nigdzie nieużywany.'),
    'place_description':   ('a description', 'opisie'),
    'place_comment':       ('a comment', 'komentarzu'),
    'place_proposal':      ('a proposed edit', 'proponowanej zmianie'),
    'place_page':          ('the page :name', 'stronie :name'),
    'state_image_removed': ('removed by moderation', 'usunięty przez moderację'),
    'state_image_blocked': ('removed and blocked by moderation', 'usunięty i zablokowany przez moderację'),
})

add('images', {
    'removed_placeholder': ('Picture removed by moderation', 'Obraz usunięty przez moderację'),
})

add('api.images', {
    'blocked': ('This picture was removed by moderation and cannot be uploaded again.',
                'Ten obraz został usunięty przez moderację i nie można go wgrać ponownie.'),
})
