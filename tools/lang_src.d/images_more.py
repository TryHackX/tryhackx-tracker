S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('comment', {
    'image_word': ('[picture]', '[obraz]'),
    'image_odd': ('a picture of :w×:h px', 'obraz :w×:h px'),
})

add('api.comment', {
    'no_images': ('Pictures are not allowed in your comments.', 'W Twoich komentarzach nie można wstawiać obrazów.'),
    'bad_image': ('A picture must be a full http(s) address, with no spaces, between [img] and [/img].',
                  'Obraz musi być pełnym adresem http(s), bez spacji, między [img] a [/img].'),
    'no_image_links': ('Pictures from links are switched off in comments. Upload the picture instead.',
                       'Obrazy z linków są w komentarzach wyłączone. Wgraj obraz.'),
    'foreign_image': ('A picture from another site shows as a link, and links are switched off in comments.',
                      'Obraz z innej strony pokazuje się jako link, a linki w komentarzach są wyłączone.'),
    'too_many_images': ('A comment may have at most :max pictures.', 'Liczba obrazów w komentarzu: najwyżej :max.'),
})

add('api.images', {
    'shape': ('That picture is too narrow or too tall for a comment (more than 8 to 1).',
              'Ten obraz jest za wąski albo za wysoki do komentarza (proporcje ponad 8 do 1).'),
    'panel_deleted': ('Picture deleted.', 'Obraz usunięty.'),
    'retry_queued': ('The copy will be tried again within a few minutes.', 'Kopia zostanie ponowiona w ciągu kilku minut.'),
    'forgotten': ('Removed from the queue.', 'Usunięto z kolejki.'),
})

add('settings', {
    'cimg_title': ('Pictures in comments', 'Obrazy w komentarzach'),
    'cimg_subtitle': ("Who may put pictures into comments is the comment.images permission (members; never guests). A picture "
                      "in a comment is a thumbnail that loads after the thread is drawn and opens full size on a click.",
                      'O tym, kto może wstawiać obrazy do komentarzy, decyduje uprawnienie comment.images (członkowie, nigdy goście). '
                      'Obraz w komentarzu to miniatura, która ładuje się po narysowaniu wątku i po kliknięciu otwiera się w pełnym rozmiarze.'),
    'comment_images_upload': ('Uploading in comments', 'Wgrywanie w komentarzach'),
    'comment_images_upload_hint': ('Off: pictures come only from links. On: the comment box gets a button, and a pasted or '
                                   'dropped file is uploaded.',
                                   'Wyłączone: obrazy tylko z linków. Włączone: pole komentarza ma przycisk, a wklejony lub '
                                   'upuszczony plik jest wgrywany.'),
    'comment_images_links': ('Pictures from links', 'Obrazy z linków'),
    'comment_images_links_hint': ("An address of this site's picture, or of an API key's image domain (copied here in the "
                                  "background), shows as a picture. Any other address shows as a link.",
                                  'Adres obrazu z tej strony albo z domeny obrazów klucza API (kopiowany tu w tle) pokazuje '
                                  'się jako obraz. Każdy inny adres pokazuje się jako link.'),
    'comment_images_max': ('Pictures per comment', 'Obrazów na komentarz'),
    'comment_images_max_hint': ('0-50. 0 = no pictures in comments.', '0-50. 0 = bez obrazów w komentarzach.'),
    'comment_image_max_mb': ('Largest uploaded file, MB', 'Największy wgrywany plik, MB'),
    'comment_image_max_mb_hint': ('Never more than the limit in Pictures above.', 'Nigdy więcej niż limit w sekcji Obrazy powyżej.'),
    'comment_thumb_h': ('Thumbnail height, px', 'Wysokość miniatury, px'),
    'comment_thumb_h_hint': ('80-600.', '80-600.'),
    'comment_thumb_w': ('Thumbnail width, px', 'Szerokość miniatury, px'),
    'comment_thumb_w_hint': ('80-800. With the height it keeps a 1×10000 px picture from stretching the thread.',
                             '80-800. Razem z wysokością nie pozwala obrazowi 1×10000 px rozciągnąć wątku.'),
})

add('js.comments', {
    'hint_img': ('[b] [i] [u] [s] [url] [img] [quote] [spoiler] [code] — emoji, emotes and pictures. Ctrl+Enter sends.',
                 '[b] [i] [u] [s] [url] [img] [quote] [spoiler] [code] — emotki, emote i obrazy. Ctrl+Enter wysyła.'),
    'hint_img_nolinks': ('[b] [i] [u] [s] [img] [quote] [spoiler] [code] — emoji, emotes and pictures, no links. Ctrl+Enter sends.',
                         '[b] [i] [u] [s] [img] [quote] [spoiler] [code] — emotki, emote i obrazy, bez linków. Ctrl+Enter wysyła.'),
    'img_tip': ('Paste the address of a picture to add it.', 'Wklej adres obrazu, aby go dodać.'),
    'img_tip_upload': ('Paste or drop a picture, or its address, straight into the text.',
                       'Wklej lub upuść obraz albo jego adres prosto do tekstu.'),
    'img_head': ('Pictures', 'Obrazy'),
    'img_mine': ('My pictures', 'Moje obrazy'),
    'img_hint': ('Up to :mb MB a file.', 'Do :mb MB na plik.'),
    'img_here': ('this site', 'ta strona'),
    'img_kind_own': ('picture', 'obraz'),
    'img_kind_copy': ('copied here soon', 'wkrótce skopiowany tutaj'),
    'img_kind_link': ('shown as a link', 'pokaże się jako link'),
    'img_remove': ('Remove this picture', 'Usuń ten obraz'),
    'img_count': ('Pictures: :n of :max', 'Obrazy: :n z :max'),
})

add('js.wl', {
    'cl_imgdomains_badge': ('pictures: :list', 'obrazy: :list'),
})

add('a.wl', {
    'cl_imgdomains': ('Image domains', 'Domeny obrazów'),
    'cl_imgdomains_ph': ('e.g. tryhackx.org', 'np. tryhackx.org'),
    'cl_imgdomains_hint': ('Pictures from these domains (and their subdomains) in descriptions and comments are copied to this '
                           'site in the background. Pictures from other sites are never downloaded: a description keeps their '
                           'address, a comment shows them as a link. Space or one per line; only while the key is enabled and '
                           'the partner allows it: it sends copy_images (in flarum-homepage-blocks: “Let the tracker copy '
                           'pictures from posts”).',
                           'Obrazy z tych domen (i ich poddomen) w opisach i komentarzach tracker kopiuje w tle do siebie. '
                           'Obrazów z innych stron nigdy nie pobiera: opis zachowuje ich adres, komentarz pokazuje je jako link. '
                           'Spacja albo jedna na linię; działa tylko, gdy klucz jest włączony, a partner się na to zgadza: '
                           'wysyła copy_images (w flarum-homepage-blocks: „Pozwól trackerowi kopiować obrazy z postów”).'),
})

add('a.img', {
    'title': ('Images', 'Obrazy'),
    'subtitle': ('every picture on the site: who added it and where it is used', 'wszystkie obrazy na stronie: kto je dodał i gdzie są użyte'),
    'tab_list': ('Pictures', 'Obrazy'),
    'tab_queue': ('Copying', 'Kopiowanie'),
    'f_q': ('Member, file name, address or #id', 'Członek, nazwa pliku, adres lub #id'),
    'f_state': ('Visibility', 'Widoczność'),
    'f_state_all': ('All', 'Wszystkie'),
    'f_state_public': ('Public', 'Publiczne'),
    'f_state_private': ('Not published yet', 'Jeszcze nieopublikowane'),
    'f_state_copied': ('Copied from API key domains', 'Skopiowane z domen kluczy API'),
    'f_sort': ('Order', 'Kolejność'),
    'f_sort_new': ('Newest first', 'Najnowsze'),
    'f_sort_old': ('Oldest first', 'Najstarsze'),
    'f_sort_big': ('Largest first', 'Największe'),
    'note': ("A picture becomes public when a published description, a page or a visible comment shows it; until then only "
             "its owner and moderators can open it. Pictures nothing uses are removed by the janitor after the time set in "
             "Settings → Pictures. Deleting a picture that is used leaves an empty space where it was.",
             'Obraz staje się publiczny, gdy pokazuje go opublikowany opis, strona albo widoczny komentarz; do tego czasu '
             'otworzy go tylko właściciel i moderatorzy. Obrazy, których nic nie używa, janitor usuwa po czasie ustawionym '
             'w Ustawieniach → Obrazy. Usunięcie używanego obrazu zostawia puste miejsce tam, gdzie był.'),
    'queue_note': ("Pictures in descriptions and comments that come from the image domains of an API key are copied here in the "
                   "background, one download per address, and their addresses are changed to this site's. Pictures from any "
                   "other site are never downloaded.",
                   'Obrazy z domen obrazów klucza API, które są w opisach i komentarzach, tracker kopiuje w tle do siebie, '
                   'raz na adres, i podmienia ich adresy na swoje. Obrazów z innych stron nigdy nie pobiera.'),
    'q_status': ('State', 'Stan'),
    'q_pending': ('Waiting', 'Czekają'),
    'q_failed': ('Failed', 'Nieudane'),
    'q_done': ('Copied', 'Skopiowane'),
    'q_ignored': ('Dropped', 'Pominięte'),
    'q_url': ('Address', 'Adres'),
    'q_state': ('State', 'Stan'),
    'q_tries': ('Tries', 'Próby'),
    'q_when': ('When', 'Kiedy'),
})

add('js.aimg', {
    'failed': ('That did not work. Try again.', 'Nie udało się. Spróbuj ponownie.'),
    'summary': (':n pictures, :size · public: :pub · copied: :copies', 'Obrazów: :n, :size · publicznych: :pub · skopiowanych: :copies'),
    'hosts': ('Copied from: :list', 'Kopiowane z: :list'),
    'hosts_none': ('No API key has image domains, so nothing is copied.', 'Żaden klucz API nie ma domen obrazów, więc nic nie jest kopiowane.'),
    'unused': ('Not used anywhere.', 'Nigdzie nieużyty.'),
    'kind_description': ('Description', 'Opis'),
    'kind_proposal': ('Proposal', 'Propozycja'),
    'kind_comment': ('Comment', 'Komentarz'),
    'kind_page': ('Page', 'Strona'),
    'delete_title': ('Delete the picture', 'Usuń obraz'),
    'delete_q': ('Delete picture #:id for good?', 'Usunąć na stałe obraz #:id?'),
    'delete_used_one': ('Picture #:id is used in one place, which will show an empty space instead. Delete it for good?',
                        'Obraz #:id jest użyty w jednym miejscu, gdzie zostanie puste miejsce. Usunąć go na stałe?'),
    'delete_used_few': ('Picture #:id is used in :n places, which will show an empty space instead. Delete it for good?',
                        'Obraz #:id jest użyty w :n miejscach, gdzie zostanie puste miejsce. Usunąć go na stałe?'),
    'delete_used_many': ('Picture #:id is used in :n places, which will show an empty space instead. Delete it for good?',
                         'Obraz #:id jest użyty w :n miejscach, gdzie zostanie puste miejsce. Usunąć go na stałe?'),
    'delete': ('Delete', 'Usuń'),
    'deleted': ('Picture deleted.', 'Obraz usunięty.'),
    'open_full': ('Open full size', 'Otwórz w pełnym rozmiarze'),
    'copied_from': ('copied from :host', 'skopiowany z :host'),
    'by': ('by :name', 'od :name'),
    'no_owner': ('account deleted', 'konto usunięte'),
    'public': ('public', 'publiczny'),
    'private': ('not public yet', 'jeszcze niepubliczny'),
    'where': ('Where is it used', 'Gdzie jest użyty'),
    'total_one': ('1 picture', '1 obraz'),
    'total_few': (':n pictures', ':n obrazy'),
    'total_many': (':n pictures', ':n obrazów'),
    'none': ('No pictures here.', 'Brak obrazów.'),
    'done': ('Done.', 'Gotowe.'),
    'copies_one': ('1 address', '1 adres'),
    'copies_few': (':n addresses', ':n adresy'),
    'copies_many': (':n addresses', ':n adresów'),
    'q_none': ('Nothing here.', 'Nic tu nie ma.'),
    'next': ('next try :at', 'kolejna próba :at'),
    'show_image': ('Show the picture', 'Pokaż obraz'),
    'retry': ('Try again', 'Spróbuj ponownie'),
    'forget': ('Remove from the queue', 'Usuń z kolejki'),
    'st_approved': ('published', 'opublikowany'),
    'st_pending': ('waiting', 'czeka'),
    'st_rejected': ('rejected', 'odrzucony'),
    'st_none': ('no description', 'bez opisu'),
    'st_visible': ('visible', 'widoczny'),
    'st_deleted': ('deleted', 'usunięty'),
    'st_enabled': ('on', 'włączona'),
    'st_disabled': ('off', 'wyłączona'),
    'st_done': ('copied', 'skopiowany'),
    'st_failed': ('failed', 'nieudany'),
    'st_ignored': ('dropped', 'pominięty'),
    'st_applied': ('applied', 'zastosowana'),
    'err_private_address': ('the address leads to a private network', 'adres prowadzi do sieci prywatnej'),
    'err_not_allowed': ('the domain is no longer on any key', 'domena nie jest już przy żadnym kluczu'),
    'err_bad_url': ('not a usable address', 'adres nie nadaje się do pobrania'),
    'err_bad_port': ('a port other than 80 or 443', 'port inny niż 80 lub 443'),
    'err_redirect': ('the server answered with a redirect', 'serwer odpowiedział przekierowaniem'),
    'err_timeout': ('no answer in time', 'brak odpowiedzi na czas'),
    'err_network': ('could not connect', 'nie udało się połączyć'),
    'err_too_big': ('the file is too big', 'plik jest za duży'),
    'err_too_many_pixels': ('too many pixels', 'za dużo pikseli'),
    'err_bad_type': ('not a picture', 'to nie jest obraz'),
    'err_upload_failed': ('the picture could not be stored', 'nie udało się zapisać obrazu'),
    'err_no_curl': ('PHP has no cURL on this server', 'PHP na tym serwerze nie ma cURL'),
    'err_empty': ('the server sent nothing', 'serwer nic nie wysłał'),
    'err_removed': ('the copy was deleted in the panel', 'kopię usunięto w panelu'),
})
