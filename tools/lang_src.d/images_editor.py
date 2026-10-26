S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('rt', {
    'images':        ('Images', 'Obrazy'),
    'images_choose': ('Choose files', 'Wybierz pliki'),
    'images_mine':   ('My images', 'Moje obrazy'),
    'images_hint':   ('You can also drop or paste images into the text.',
                      'Możesz też upuścić lub wkleić obrazy w tekst.'),
})

add('js.images', {
    'placeholder':      ('[Uploading :name #:n…]', '[Wysyłanie :name #:n…]'),
    'uploading_one':    ('Uploading 1 image…', 'Wysyłam 1 obraz…'),
    'uploading_few':    ('Uploading :n images…', 'Wysyłam :n obrazy…'),
    'uploading_many':   ('Uploading :n images…', 'Wysyłam :n obrazów…'),
    'waiting':          ('Queued', 'W kolejce'),
    'percent':          (':n%', ':n%'),
    'processing':       ('Processing…', 'Przetwarzanie…'),
    'progress_label':   ('Uploading :name', 'Wysyłanie: :name'),
    'uploaded':         ('Uploaded: :name', 'Wysłano: :name'),
    'failed_named':     (':name: :error', ':name: :error'),
    'upload_failed':    ('The upload failed. Check your connection and try again.',
                         'Wysyłanie nie powiodło się. Sprawdź połączenie i spróbuj ponownie.'),
    'too_big_server':   ('The image is larger than the server accepts.',
                         'Obraz jest większy, niż serwer przyjmuje.'),
    'not_image':        (':name is not an image.', 'Plik :name nie jest obrazem.'),
    'too_big':          (':name is too large: an image may be at most :max.',
                         'Plik :name jest za duży: obraz może mieć najwyżej :max.'),
    'wait_uploads':     ('Wait until the images have finished uploading.',
                         'Poczekaj, aż obrazy skończą się wysyłać.'),
    'inserted':         ('Image inserted.', 'Wstawiono obraz.'),
    'mine_tip':         ('Choose an image to insert it at the cursor, or drag it into the text.',
                         'Wybierz obraz, aby wstawić go w miejscu kursora, albo przeciągnij go do tekstu.'),
    'mine_loading':     ('Loading your images…', 'Wczytywanie Twoich obrazów…'),
    'mine_failed':      ('Your images could not be loaded.', 'Nie udało się wczytać Twoich obrazów.'),
    'mine_empty':       ('You have not uploaded any images yet.', 'Nie masz jeszcze żadnych obrazów.'),
    'insert_one':       ('Insert :name', 'Wstaw: :name'),
    'thumb_tip':        (':name · :w × :h · :size', ':name · :w × :h · :size'),
    'pick':             ('Select :name for labels', 'Zaznacz do etykiet: :name'),
    'in_use':           ('In use', 'W użyciu'),
    'in_use_tip':       ('Shown in a published text.',
                         'Pokazany w opublikowanym tekście.'),
    'animated':         ('Animated', 'Animowany'),
    'copy_link':        ('Copy link', 'Kopiuj link'),
    'copy_bbcode':      ('Copy BBCode', 'Kopiuj BBCode'),
    'delete':           ('Delete', 'Usuń'),
    'delete_q':         ('Delete this image?', 'Usunąć ten obraz?'),
    'deleted':          ('Image deleted.', 'Obraz usunięty.'),
    'delete_failed':    ('The image could not be deleted.', 'Nie udało się usunąć obrazu.'),
    'labels':           ('Insert as labels', 'Wstaw jako etykiety'),
    'labels_n':         ('Insert as labels (:n)', 'Wstaw jako etykiety (:n)'),
    'labels_hint':      ('Tick the images in the order they should appear.',
                         'Zaznacz obrazy w kolejności, w jakiej mają się pojawić.'),
    'labels_inserted':  ('Labels inserted.', 'Wstawiono etykiety.'),
    'quota_today':      ('Uploaded today: :today of :limit', 'Przesłane dziś: :today z :limit'),
    'quota_today_free': ('Uploaded today: :today', 'Przesłane dziś: :today'),
    'quota_space':      ('Space used: :used of :quota', 'Zajęte miejsce: :used z :quota'),
    'quota_space_free': ('Space used: :used', 'Zajęte miejsce: :used'),
    'page':             ('Page :page of :pages', 'Strona :page z :pages'),
    'prev':             ('Previous page', 'Poprzednia strona'),
    'next':             ('Next page', 'Następna strona'),
})
