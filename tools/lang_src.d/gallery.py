S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('account', {
    'tab_images': ('Images', 'Obrazy'),
    'images_heading': ('Your pictures', 'Twoje obrazy'),
    'images_intro': ('Every picture uploaded from your account, newest first. A picture you delete leaves your gallery at once; '
                     'a description that shows it keeps it until a moderator approves taking it out, and then it is deleted for good.',
                     'Wszystkie obrazy wgrane z Twojego konta, od najnowszych. Usunięty obraz od razu znika z Twojej galerii; opis, '
                     'który go pokazuje, zachowa go, dopóki moderator nie zatwierdzi jego usunięcia — wtedy zniknie na dobre.'),
    'images_public_label': ('Show my pictures as a gallery on my profile', 'Pokazuj moje obrazy jako galerię na moim profilu'),
    'images_public_hint': ('Only pictures already shown in published texts appear there, and never one waiting to be removed.',
                           'Pojawiają się tam tylko obrazy pokazane już w opublikowanych tekstach i nigdy te, które czekają na usunięcie.'),
})

add('profile', {
    'images': ('Pictures', 'Obrazy'),
})

add('settings', {
    'images_gallery_enabled': ('Images tab and gallery', 'Karta Obrazy i galeria'),
    'images_gallery_enabled_hint': ('The Images tab of the account page, where members manage their pictures, and the gallery they may show on their profile.',
                                    'Karta Obrazy na stronie konta, gdzie użytkownicy zarządzają swoimi obrazami, oraz galeria, którą mogą pokazać na profilu.'),
})

add('api.images', {
    'removed_now': ('The picture is deleted.', 'Obraz usunięto.'),
    'removal_waits': ('The picture is gone from your gallery. The descriptions that show it keep it until a moderator approves taking it out; then it is deleted for good.',
                      'Obraz zniknął z Twojej galerii. Opisy, które go pokazują, zachowają go, dopóki moderator nie zatwierdzi jego usunięcia — wtedy zniknie na dobre.'),
    'removal_cancelled': ('The picture is back in your gallery, and the changes that would have taken it out are withdrawn.',
                          'Obraz wrócił do Twojej galerii, a zmiany, które miały go usunąć, wycofano.'),
    'removal_none': ('That picture is not waiting to be removed.', 'Ten obraz nie czeka na usunięcie.'),
})

add('js.images', {
    'ug_filter': ('Which pictures', 'Które obrazy'),
    'ug_filter_all': ('All pictures', 'Wszystkie obrazy'),
    'ug_filter_used': ('In use', 'Używane'),
    'ug_filter_unused': ('Not used', 'Nieużywane'),
    'ug_filter_removal': ('Waiting to be removed', 'Czekające na usunięcie'),
    'ug_total_one': ('1 picture', '1 obraz'),
    'ug_total_few': (':n pictures', ':n obrazy'),
    'ug_total_many': (':n pictures', ':n obrazów'),
    'ug_none': ('No pictures here yet. Pictures you upload in a description or a comment show up here.',
                'Nie ma tu jeszcze obrazów. Pojawią się tu obrazy wgrane w opisie albo komentarzu.'),
    'ug_none_filter': ('No pictures of this kind.', 'Brak takich obrazów.'),
    'ug_none_public': ('No pictures to show.', 'Brak obrazów do pokazania.'),
    'ug_used': ('Used: :n', 'Użyty: :n'),
    'ug_removal_badge': ('Deleted, still in a description', 'Usunięty, wciąż w opisie'),
    'ug_open': ('Open the picture', 'Otwórz obraz'),
    'ug_copy': ('Copy the link', 'Kopiuj link'),
    'ug_where': ('Where it is used', 'Gdzie jest użyty'),
    'ug_where_none': ('Not used anywhere right now.', 'Teraz nigdzie nie jest użyty.'),
    'ug_kind_description': ('description', 'opis'),
    'ug_kind_proposal': ('proposed change', 'propozycja zmiany'),
    'ug_kind_comment': ('comment', 'komentarz'),
    'ug_kind_page': ('page', 'strona'),
    'ug_delete': ('Delete the picture', 'Usuń obraz'),
    'ug_delete_q': ('Delete this picture?', 'Usunąć ten obraz?'),
    'ug_delete_used_q': ('It is used: it leaves your gallery now and the server once a moderator approves. Delete?',
                         'Jest używany: zniknie z galerii od razu, a z serwera po zatwierdzeniu przez moderatora. Usunąć?'),
    'ug_cancel': ('Keep it', 'Zostaw go'),
})

add('js.review', {
    'removal': ('The owner of a picture asked for it to be taken out of this description', 'Właściciel obrazu poprosił o usunięcie go z tego opisu'),
    'removal_author_stays': ('The author (:user) stays, and nobody is credited for taking a picture out.',
                             'Autor (:user) zostaje i nikt nie jest dopisywany za usunięcie obrazu.'),
    'removal_apply_body': ('The picture is taken out of the published description, which changes :pct% of it. Its author stays and nobody is credited; '
                           'once no description shows the picture, it is deleted for good.',
                           'Obraz zostaje usunięty z opublikowanego opisu, co zmienia :pct% tekstu. Autor zostaje i nikt nie jest dopisywany; '
                           'gdy żaden opis go już nie pokazuje, obraz znika na dobre.'),
})

add('api.content', {
    'removal_applied': ('Picture taken out: it changed :pct% of the text. The author stays; nobody is credited.',
                        'Obraz usunięty z opisu: zmienił :pct% tekstu. Autor zostaje; nikt nie jest dopisywany.'),
})

add('notify', {
    'images_removed': ('Your picture was taken out of ":name"', 'Twój obraz usunięto z opisu „:name”'),
    'images_removed_body': ('A moderator approved taking the picture you deleted out of this description. Once no description shows it, it is deleted for good.',
                            'Moderator zatwierdził usunięcie skasowanego przez Ciebie obrazu z tego opisu. Gdy żaden opis go nie pokazuje, znika na dobre.'),
    'images_removed_from': ('A picture was taken out of your description of ":name"', 'Z Twojego opisu „:name” usunięto obraz'),
    'images_removed_from_body': ('At its owner\'s request, a moderator took a picture out of your description.',
                                 'Na prośbę właściciela moderator usunął obraz z Twojego opisu.'),
})
