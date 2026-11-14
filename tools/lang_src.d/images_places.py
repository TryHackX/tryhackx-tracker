S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('perm', {
    'images.upload': ('Upload pictures for messages, the shoutbox, their profile and their lists, where Settings allows them there',
                      'Wgrywanie obrazów do wiadomości, shoutboksa, opisu w profilu i opisów list — tam, gdzie pozwalają ustawienia'),
    'images.link': ("Put a picture that is already public on this tracker (a description's poster) into a message, a shout, their profile or a list",
                    'Wstawianie obrazu, który jest już publiczny na tym trackerze (np. plakatu z opisu), do wiadomości, shoutboksa, profilu lub listy'),
})

add('api.images', {
    'place_off': ('Pictures are switched off here.', 'Tutaj obrazy są wyłączone.'),
    'place_too_many': ('Too many pictures: :n, and the limit here is :max.', 'Za dużo obrazów: :n, a limit tutaj to :max.'),
    'place_not_yours': ('That picture is not yours to put here.', 'Tego obrazu nie możesz tu wstawić.'),
    'place_gone': ('One of the pictures is no longer on the tracker.', 'Jednego z obrazów nie ma już na trackerze.'),
})

add('api.bio', {
    'images': (':why', ':why'),
})

add('api.lists', {
    'images': (':why', ':why'),
})

add('settings', {
    'images_places_h': ('Pictures in other places', 'Obrazy w innych miejscach'),
    'images_places_hint': ("How many pictures one entry may have (0 = none). They are uploaded with the picture button (or pasted) and count against the same daily limit. They are never public: only the people named in the last column see them, and the moderators. When the entry goes, its pictures go after the hours above.",
                           'Ile obrazów może mieć jeden wpis (0 = żadnego). Wgrywa się je przyciskiem obrazu (albo wkleja) i liczą się do tego samego limitu dziennego. Nigdy nie są publiczne: widzą je tylko osoby z ostatniej kolumny i moderatorzy. Gdy wpis znika, jego obrazy znikają po liczbie godzin ustawionej wyżej.'),
    'images_places_where': ('Place', 'Miejsce'),
    'images_places_max': ('Pictures per entry', 'Obrazów na wpis'),
    'images_places_who': ('Who sees them', 'Kto je widzi'),
    'images_in_pm': ('Messages', 'Wiadomości'),
    'images_in_pm_who': ('The two people in the conversation', 'Dwie osoby z rozmowy'),
    'images_in_shout': ('Shoutbox', 'Shoutboks'),
    'images_in_shout_who': ('Signed-in members', 'Zalogowani członkowie'),
    'images_in_bio': ('Profile description', 'Opis w profilu'),
    'images_in_bio_who': ('Signed-in members', 'Zalogowani członkowie'),
    'images_in_list': ('List descriptions', 'Opisy list'),
    'images_in_list_who': ('Signed-in members (a public list), the owner and their friends (a list for friends), the owner (a private list)',
                           'Zalogowani członkowie (lista publiczna), właściciel i jego znajomi (lista dla znajomych), właściciel (lista prywatna)'),
    'images_in_mod': ("Moderators' texts", 'Teksty moderatorów'),
    'images_in_mod_who': ('The member it is written to (a warning, the answer to their report). Pictures from this tracker by their address.',
                          'Osoba, do której jest napisany (ostrzeżenie, odpowiedź na jej zgłoszenie). Obrazy z tego trackera po adresie.'),
})

add('js.images', {
    'mini_add': ('Add a picture', 'Dodaj obraz'),
    'mini_sending': ('Sending the picture…', 'Wysyłanie obrazu…'),
    'mini_failed': ('The picture could not be sent.', 'Nie udało się wysłać obrazu.'),
    'ug_kind_pm': ('message', 'wiadomość'),
    'ug_kind_shout': ('shout', 'wpis w shoutboksie'),
    'ug_kind_bio': ('profile', 'profil'),
    'ug_kind_list': ('list', 'lista'),
    'ug_kind_warn': ('warning', 'ostrzeżenie'),
    'ug_kind_crep': ('answer to a report', 'odpowiedź na zgłoszenie'),
    'ug_kind_mrep': ('answer to a report of a message', 'odpowiedź na zgłoszenie wiadomości'),
})

add('js.aimg', {
    'kind_pm': ('Message', 'Wiadomość'),
    'kind_shout': ('Shout', 'Wpis w shoutboksie'),
    'kind_bio': ('Profile', 'Profil'),
    'kind_list': ('List', 'Lista'),
    'kind_warn': ('Warning', 'Ostrzeżenie'),
    'kind_crep': ('Answer to a report', 'Odpowiedź na zgłoszenie'),
    'kind_mrep': ('Answer to a report of a message', 'Odpowiedź na zgłoszenie wiadomości'),
    'st_members': ('signed-in members', 'zalogowani'),
    'st_private': ('private', 'prywatny'),
})
