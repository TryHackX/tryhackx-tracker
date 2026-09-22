# -*- coding: utf-8 -*-
"""Pictures and profile covers (1.63.0, includes/usermedia.php)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The Polish follows the words a Polish reader already knows from the big sites: "zdjęcie profilowe"
for the picture beside a name and "okładka" for the wide image across the top of a profile. The
owner's own Flarum extension said "cover" and "awatar"; "okładka" is the plain word for the same
thing, and "awatar" stays where it reads naturally.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── Settings -> Profiles ─────────────────────────────────────────────────────
add('settings', {
    'group_profiles': ('Profiles', 'Profile'),
    'profiles_heading': ('Pictures and profile covers', 'Zdjęcia profilowe i okładki'),
    'profiles_intro': (
        'Each member may give their account a picture, shown beside their name, and a cover, the wide image across '
        'the top of their profile. Both go through one pipeline: the size and the pixel count are checked before '
        'anything is decoded, then every image is turned upright, stripped of its EXIF and GPS data and saved again '
        'as WebP. Who may set them is decided by the <code>profile.avatar</code> and <code>profile.cover</code> '
        'permissions (Users &rarr; Groups); members have both.',
        'Każdy członek może dać swojemu kontu zdjęcie profilowe, widoczne obok jego nazwy, i okładkę, czyli szeroki '
        'obraz u góry profilu. Oba przechodzą tę samą drogę: rozmiar i liczba pikseli są sprawdzane, zanim cokolwiek '
        'zostanie zdekodowane, potem każdy obraz jest obracany do pionu, pozbawiany danych EXIF i GPS i zapisywany '
        'na nowo jako WebP. Kto może je ustawiać, decydują uprawnienia <code>profile.avatar</code> i '
        '<code>profile.cover</code> (Użytkownicy &rarr; Grupy); członkowie mają oba.'),
    'profiles_avatars': ('Pictures', 'Zdjęcia profilowe'),
    'profiles_avatars_hint': (
        'Off hides every uploaded picture and the editor on the account page. Nothing is deleted.',
        'Wyłączenie ukrywa wszystkie wgrane zdjęcia i edytor na stronie konta. Nic nie jest usuwane.'),
    'profiles_covers': ('Profile covers', 'Okładki profili'),
    'profiles_covers_hint': (
        'Off hides every cover, the site default included. Nothing is deleted.',
        'Wyłączenie ukrywa wszystkie okładki, razem z domyślną. Nic nie jest usuwane.'),
    'profiles_max_kb': ('Largest upload (KB)', 'Największy plik (KB)'),
    'profiles_max_kb_hint': (
        'For pictures and covers alike, :min–:max KB, checked before the file is decoded. PHP on this server takes '
        'files up to :php, so the forms offer at most :eff KB.',
        'Dla zdjęć i okładek tak samo, od :min do :max KB, sprawdzane przed dekodowaniem pliku. PHP na tym serwerze '
        'przyjmuje pliki do :php, więc formularze pozwalają najwyżej na :eff KB.'),
    'profiles_max_mp': ('Largest image (megapixels)', 'Największy obraz (megapiksele)'),
    'profiles_max_mp_hint': (
        'Read from the file header before anything is decoded, :min–:max. 24 megapixels take about 96 MB of '
        'memory while the image is processed.',
        'Odczytywane z nagłówka pliku, zanim cokolwiek zostanie zdekodowane, od :min do :max. 24 megapiksele zajmują '
        'podczas obróbki około 96 MB pamięci.'),
    'profiles_cover_height': ('Cover height (px)', 'Wysokość okładki (px)'),
    'profiles_cover_height_hint': (
        'The band across the top of a profile on a wide screen.',
        'Pas u góry profilu na szerokim ekranie.'),
    'profiles_cover_height_mobile': ('Cover height on phones (px)', 'Wysokość okładki na telefonie (px)'),
    'profiles_cover_height_mobile_hint': (
        'The same band on a screen 640 px wide or narrower.',
        'Ten sam pas na ekranie o szerokości do 640 px.'),
    'profiles_overlay': ('Readability overlay', 'Warstwa czytelności'),
    'profiles_overlay_gradient': ('Gradient (recommended)', 'Gradient (zalecany)'),
    'profiles_overlay_darken': ('Even darkening', 'Równe przyciemnienie'),
    'profiles_overlay_none': ('None', 'Brak'),
    'profiles_overlay_hint': (
        'A dark layer over the cover, so the name and the buttons stay legible on a bright photo.',
        'Ciemna warstwa na okładce, żeby nazwa i przyciski były czytelne na jasnym zdjęciu.'),
    'profiles_default_mode': ('Without a picture of their own', 'Bez własnego zdjęcia'),
    'profiles_default_generated': ('Their initial', 'Inicjał z nazwy'),
    'profiles_default_image': ('The site default picture', 'Domyślne zdjęcie serwisu'),
    'profiles_default_mode_hint': (
        'Their initial is the first letter of their name on one of twelve colours, chosen from the name, so '
        'everybody keeps theirs. The default picture below is used only once it is set; until then everybody '
        'gets their initial.',
        'Inicjał to pierwsza litera nazwy na jednym z dwunastu kolorów, wybranym na podstawie nazwy, więc każdy '
        'zachowuje swój. Domyślne zdjęcie poniżej jest używane dopiero wtedy, gdy jest ustawione; do tego czasu '
        'każdy dostaje swój inicjał.'),
    # 1.64.0: which card of the account page holds Picture and Cover.
    'account_media_side': ('Picture and cover on the account page', 'Zdjęcie i okładka na stronie konta'),
    'account_media_side_right': ('In the right-hand card', 'W prawej karcie'),
    'account_media_side_left': ('In the left-hand card', 'W lewej karcie'),
    'account_media_side_hint': (
        'The right-hand card holds what a member can change about themselves; the left one holds the facts about '
        'their account. Nothing else moves, and neither does anything on the profile page.',
        'Prawa karta trzyma to, co członek może o sobie zmienić; lewa — fakty o koncie. Nic poza tym się nie '
        'przesuwa i nic nie zmienia się na stronie profilu.'),
    'profiles_default_avatar': ('Default picture', 'Domyślne zdjęcie profilowe'),
    'profiles_default_avatar_hint': (
        'Shown for everybody without a picture of their own when the setting above says so. Framed with the same '
        'editor members use; uploading, framing and removing it take effect at once, without the Save button.',
        'Pokazywane każdemu bez własnego zdjęcia, gdy tak mówi ustawienie powyżej. Kadrowane tym samym edytorem, '
        'którego używają członkowie; wgranie, kadrowanie i usunięcie działają od razu, bez przycisku Zapisz.'),
    'profiles_default_cover': ('Default cover', 'Domyślna okładka'),
    'profiles_default_cover_hint': (
        'Shown on every profile that has no cover of its own. Without one, those profiles keep the plain header.',
        'Pokazywana na każdym profilu bez własnej okładki. Bez niej takie profile mają zwykły nagłówek.'),
    'profiles_drop_aria': ('Choose an image, or drop one here', 'Wybierz obraz albo upuść go tutaj'),
    'profiles_drop_choose': ('Choose an image', 'Wybierz obraz'),
    'profiles_drop_or': ('or drop it here', 'albo upuść go tutaj'),
    'profiles_drop_sub': (
        'JPEG, PNG, WebP or GIF. You frame it first; nothing is sent before Save.',
        'JPEG, PNG, WebP albo GIF. Najpierw go kadrujesz; nic nie jest wysyłane przed zapisaniem.'),
    'profiles_adjust': ('Adjust position', 'Dostosuj pozycję'),
    'profiles_remove': ('Remove', 'Usuń'),
})


# ── what the endpoints answer (api/user_avatar.php, api/user_cover.php, api/admin/user_media.php) ──
# Several of these are the extension's own pl.yml wording, which was already plain Polish.
add('api.media', {
    'disabled_avatar': ('Pictures are switched off on this site.', 'Zdjęcia profilowe są na tej stronie wyłączone.'),
    'disabled_cover': ('Profile covers are switched off on this site.', 'Okładki profili są na tej stronie wyłączone.'),
    'no_permission_avatar': ('Your account may not set a picture.', 'Twoje konto nie może ustawić zdjęcia profilowego.'),
    'no_permission_cover': ('Your account may not set a profile cover.', 'Twoje konto nie może ustawić okładki profilu.'),
    'no_file': ('No image was uploaded.', 'Nie przesłano żadnego obrazu.'),
    'upload_failed': ('The upload did not arrive whole. Please try again.', 'Plik nie dotarł w całości. Spróbuj ponownie.'),
    'too_large': ('That file is larger than :kb KB, the most this site takes.',
                  'Ten plik ma więcej niż :kb KB, a więcej ta strona nie przyjmuje.'),
    'too_small': ('That file is too small to be a picture.', 'Ten plik jest za mały, żeby był obrazem.'),
    'not_image': ('That file is not a picture this site can read. Use a JPEG, PNG, WebP or GIF.',
                  'Tego pliku nie da się odczytać jako obrazu. Użyj pliku JPEG, PNG, WebP albo GIF.'),
    'unsupported': (':kind files are not accepted. Use a JPEG, PNG, WebP or GIF.',
                    'Pliki :kind nie są przyjmowane. Użyj pliku JPEG, PNG, WebP albo GIF.'),
    'mismatch': ('That file says it is one kind of picture and is another.',
                 'Ten plik podaje się za jeden rodzaj obrazu, a jest innym.'),
    'too_many_px': ('That picture is :px pixels, more than :mp megapixels, and this site opens nothing larger.',
                    'Ten obraz ma :px pikseli, czyli ponad :mp megapikseli, a większych ta strona nie otwiera.'),
    'too_big_to_process': ('That picture (:px pixels) is too large for this server to process. Try a smaller one.',
                           'Ten obraz (:px pikseli) jest za duży, żeby ten serwer mógł go przetworzyć. Spróbuj mniejszego.'),
    'unreadable': ('The picture could not be read. It may be damaged, or an animated WebP, which is not supported.',
                   'Nie udało się odczytać obrazu. Może być uszkodzony albo być animowanym plikiem WebP, którego strona nie obsługuje.'),
    'store_failed': ('The picture could not be saved. Please try again.', 'Nie udało się zapisać obrazu. Spróbuj ponownie.'),
    'bad_focus': ('The focal point must be a number between 0 and 100.', 'Punkt centralny musi być liczbą od 0 do 100.'),
    'bad_zoom': ('The zoom must be a number between 0.5 and 4.', 'Powiększenie musi być liczbą od 0,5 do 4.'),
    'no_picture': ('There is no picture to reposition.', 'Nie ma zdjęcia, które można przesunąć.'),
    'no_cover': ('There is no cover to reposition.', 'Nie ma okładki, którą można przesunąć.'),
    'rate_limit': ('You are uploading too quickly. Wait a minute and try again.',
                   'Wgrywasz pliki zbyt szybko. Odczekaj minutę i spróbuj ponownie.'),
    'saved': ('Saved.', 'Zapisano.'),
    'removed_avatar': ('The picture was removed.', 'Zdjęcie profilowe zostało usunięte.'),
    'removed_cover': ('The cover was removed.', 'Okładka została usunięta.'),
    'unknown_op': ('Unknown action.', 'Nieznana operacja.'),
})

# ── the note a member gets when a moderator takes theirs down ────────────────
add('notify', {
    'media_removed_avatar': ('A moderator removed your profile picture', 'Moderator usunął Twoje zdjęcie profilowe'),
    'media_removed_cover': ('A moderator removed your profile cover', 'Moderator usunął okładkę Twojego profilu'),
    'media_removed_body': ('The image was deleted from the site. You can set a new one on your account page.',
                           'Obraz został usunięty ze strony. Nowy możesz ustawić na stronie konta.'),
})


# ── the account page: the Picture and Cover sub-sections of the Profile card ─
add('account', {
    'media_avatar_head': ('Picture', 'Zdjęcie profilowe'),
    'media_cover_head': ('Profile cover', 'Okładka profilu'),
    'media_drop_aria_avatar': ('Choose a picture, or drop one here', 'Wybierz zdjęcie albo upuść je tutaj'),
    'media_drop_aria_cover': ('Choose a cover image, or drop one here', 'Wybierz obraz na okładkę albo upuść go tutaj'),
    'media_drop_choose': ('Choose an image', 'Wybierz obraz'),
    'media_drop_or': ('or drop it here', 'albo upuść go tutaj'),
    'media_drop_sub': ('JPEG, PNG, WebP or GIF, up to :kb KB. You frame it before anything is sent.',
                       'JPEG, PNG, WebP albo GIF, do :kb KB. Najpierw go kadrujesz, dopiero potem cokolwiek jest wysyłane.'),
    'media_adjust': ('Adjust position', 'Dostosuj pozycję'),
    'media_remove': ('Remove', 'Usuń'),
    'media_avatar_note': (
        'Shown beside your name across the site. Location data and every other hidden detail are stripped from the '
        'file, and an animated GIF keeps only its first frame. Removing it deletes it for good.',
        'Widoczne obok Twojej nazwy w całym serwisie. Z pliku znikają dane o miejscu i wszystkie inne ukryte '
        'informacje, a animowany GIF zachowuje tylko pierwszą klatkę. Usunięcie kasuje je na zawsze.'),
    'media_cover_note': (
        'The wide image across the top of your profile. The whole image is kept and you choose the part that shows; '
        'the same point stays in view on a phone. Removing it deletes it for good.',
        'Szeroki obraz u góry Twojego profilu. Cały obraz zostaje zachowany, a Ty wybierasz, która część jest widoczna; '
        'ten sam punkt widać także na telefonie. Usunięcie kasuje ją na zawsze.'),
    'media_cover_none': ('No cover yet', 'Brak okładki'),
    'media_cover_default': ('The site default', 'Domyślna okładka serwisu'),
    'media_no_grant': (
        'None of your groups grants <code>:perm</code>, so you cannot change this yet. An administrator grants it in '
        'the panel, under Users → Groups.',
        'Żadna z Twoich grup nie ma uprawnienia <code>:perm</code>, więc na razie nie możesz tego zmienić. '
        'Administrator nadaje je w panelu, w Użytkownicy → Grupy.'),
})

# ── the editor (assets/js/media-editor.js); `js.media.` is in LANG_JS_PUBLIC ──
# The aria label, the hint and the zoom wording are the extension's own pl.yml, which was plain Polish
# already — except that the hint now has a touch version, because "the mouse wheel zooms" told a
# phone something it cannot do.
add('js.media', {
    'title_avatar': ('Profile picture', 'Zdjęcie profilowe'),
    'title_cover': ('Profile cover', 'Okładka profilu'),
    'aria': ('Image position editor. Use the arrow keys to move the focal point, plus and minus to zoom.',
             'Edytor pozycji obrazu. Strzałki przesuwają punkt centralny, plus i minus przybliżają.'),
    'hint_mouse': ('Drag to reposition, scroll to zoom', 'Przeciągnij, aby zmienić pozycję; kółko myszy przybliża'),
    'hint_touch': ('Drag to reposition, pinch to zoom', 'Przeciągnij, aby zmienić pozycję; rozsuń palce, aby przybliżyć'),
    'loading': ('Loading…', 'Wczytywanie…'),
    'load_error': ('The image could not be loaded. Choose it again, or another one.',
                   'Nie udało się wczytać obrazu. Wybierz go jeszcze raz albo inny.'),
    'zoom': ('Zoom', 'Powiększenie'),
    'zoom_reset': ('Reset zoom to 1×', 'Resetuj powiększenie do 1×'),
    'recentre': ('Re-centre', 'Wyśrodkuj'),
    'cancel': ('Cancel', 'Anuluj'),
    'save': ('Save', 'Zapisz'),
    'saving': ('Saving…', 'Zapisywanie…'),
    'close': ('Close', 'Zamknij'),
    # 1.64.0: the cross keeps meaning "gone, now" and asks for a second press instead of opening
    # the footer's question. Said beside the cross, and taken back after a moment.
    'close_again': ('Click again to close without saving',
                    'Kliknij jeszcze raz, aby zamknąć bez zapisania'),
    'discard_q': ('Discard the changes?', 'Porzucić zmiany?'),
    'discard': ('Discard', 'Porzuć'),
    'keep': ('Keep editing', 'Edytuj dalej'),
    'shape_label': ('Header shape', 'Kształt nagłówka'),
    'shape_desktop': ('Desktop', 'Komputer'),
    'shape_phone': ('Phone', 'Telefon'),
    'shape_desktop_desc': ('The profile header on a wide screen: :w × :h px.', 'Nagłówek profilu na szerokim ekranie: :w × :h px.'),
    'shape_phone_desc': ('The profile header on a phone: :w × :h px.', 'Nagłówek profilu na telefonie: :w × :h px.'),
    'sizes': ('As it will look:', 'Tak będzie wyglądać:'),
    'note_avatar': ('Only the circle shows. Your picture is cut on the server from the full image, exactly as framed here.',
                    'Widać tylko okrąg. Zdjęcie jest przycinane na serwerze z pełnego obrazu, dokładnie tak, jak je tu kadrujesz.'),
    'note_cover': ('The whole image is kept; you choose what the header shows. The crosshair marks the point every screen keeps in view.',
                   'Cały obraz zostaje zachowany, a Ty wybierasz, co pokazuje nagłówek. Celownik wskazuje punkt, który widać na każdym ekranie.'),
    'not_image': ('That file is not a JPEG, PNG, WebP or GIF.', 'Ten plik nie jest obrazem JPEG, PNG, WebP ani GIF.'),
    'too_large': ('That file is larger than :kb KB, the most this site takes.', 'Ten plik ma więcej niż :kb KB, a więcej ta strona nie przyjmuje.'),
    'failed': ('Something went wrong. Please try again.', 'Coś poszło nie tak. Spróbuj ponownie.'),
    'saved': ('Saved.', 'Zapisano.'),
    'removed': ('Removed.', 'Usunięto.'),
    'first_frame': ('Saved. It was an animated GIF, so its first frame is what everybody sees.',
                    'Zapisano. To był animowany GIF, więc wszyscy zobaczą jego pierwszą klatkę.'),
    'remove_q_avatar': ('Remove your picture? It is deleted for good.', 'Usunąć zdjęcie profilowe? Zostanie skasowane na zawsze.'),
    'remove_q_cover': ('Remove your cover? It is deleted for good.', 'Usunąć okładkę? Zostanie skasowana na zawsze.'),
    'yes_remove': ('Remove', 'Usuń'),
    'no': ('No', 'Nie'),
})


# ── the panel: Settings -> Profiles (assets/js/admin-profiles.js) and the user edit modal ─────
# `js.mediaadmin.` is NOT in LANG_JS_PUBLIC: nothing here is read outside the panel.
add('js.mediaadmin', {
    'not_set': ('Not set', 'Nie ustawiono'),
    'title_avatar': ('Default picture', 'Domyślne zdjęcie profilowe'),
    'title_cover': ('Default cover', 'Domyślna okładka'),
    'note_avatar': ('Shown for everybody without a picture of their own, when Settings says so. Saving takes effect at once.',
                    'Pokazywane każdemu bez własnego zdjęcia, gdy tak mówią ustawienia. Zapisanie działa od razu.'),
    'note_cover': ('Shown on every profile without a cover of its own. Saving takes effect at once.',
                   'Pokazywana na każdym profilu bez własnej okładki. Zapisanie działa od razu.'),
    'remove_title': ('Remove', 'Usuń'),
    'remove_q_avatar': ('Remove the site default picture? It is deleted for good, and everybody without a picture gets their letter again.',
                        'Usunąć domyślne zdjęcie serwisu? Zostanie skasowane na zawsze, a każdy bez zdjęcia znów dostanie swoją literę.'),
    'remove_q_cover': ('Remove the default cover? It is deleted for good, and profiles without a cover go back to the plain header.',
                       'Usunąć domyślną okładkę? Zostanie skasowana na zawsze, a profile bez okładki wrócą do zwykłego nagłówka.'),
    'user_remove_avatar_q': ('Remove the picture of :user? It is deleted for good, and they are told in a notification.',
                             'Usunąć zdjęcie profilowe użytkownika :user? Zostanie skasowane na zawsze, a użytkownik dostanie powiadomienie.'),
    'user_remove_cover_q': ('Remove the profile cover of :user? It is deleted for good, and they are told in a notification.',
                            'Usunąć okładkę profilu użytkownika :user? Zostanie skasowana na zawsze, a użytkownik dostanie powiadomienie.'),
    'removed': ('Removed.', 'Usunięto.'),
    'failed': ('Something went wrong. Please try again.', 'Coś poszło nie tak. Spróbuj ponownie.'),
})
add('a.users', {
    'media_label': ('Picture and cover', 'Zdjęcie i okładka'),
    'media_remove_avatar': ('Remove picture', 'Usuń zdjęcie'),
    'media_remove_cover': ('Remove cover', 'Usuń okładkę'),
    'media_note': ('Taken down at once, not on Save: deleted for good, and the member is told in a notification.',
                   'Usuwane od razu, bez czekania na Zapisz: kasowane na zawsze, a członek dostaje o tym powiadomienie.'),
})
