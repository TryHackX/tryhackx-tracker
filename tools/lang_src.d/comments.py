# -*- coding: utf-8 -*-
"""Comments on a torrent (1.71.0, part D)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The Info panel's "Comments" section and its composer (assets/js/comments.js), what the endpoints answer
(includes/comments.php, api/comment_*.php), the notifications a comment leaves -- written in the
RECIPIENT's language --, the account page's four switches and the two sounds, and Settings ->
Descriptions, comments & ratings -> Comments. The Polish avoids the forms that name a gender ("a moderator
removed" is said in the passive), as the rest of the site's Polish does where it can.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the renderer: a spoiler written without a title ─────────────────────────
add('comment', {
    'spoiler': ('Spoiler', 'Spoiler'),
})


# ── the Info panel's section (assets/js/comments.js) ───────────────────────
add('js.comments', {
    'heading':          ('Comments (:n)', 'Komentarze (:n)'),
    'heading_none':     ('Comments', 'Komentarze'),
    'none_yet':         ('No comments yet.', 'Nie ma jeszcze komentarzy.'),
    'earlier':          ('Show earlier comments', 'Pokaż wcześniejsze komentarze'),
    'load_failed':      ('The comments could not be loaded.', 'Nie udało się wczytać komentarzy.'),
    'members_only':     ('There are comments here that only members can read.',
                         'Są tu komentarze, które mogą czytać tylko członkowie.'),
    'pending_note':     ('Guests\' comments waiting for a moderator in this thread: :n',
                         'Komentarze gości czekające w tym wątku na moderatora: :n'),
    # a row
    'guest':            ('Guest', 'Gość'),
    'guest_title':      ('A comment by a guest, without an account. The tag only tells two guests apart on the same day.',
                         'Komentarz gościa, bez konta. Znacznik tylko odróżnia dwóch gości tego samego dnia.'),
    'deleted_account':  ('a deleted account', 'usunięte konto'),
    'edited':           ('edited', 'edytowano'),
    'edited_mod':       ('edited by a moderator', 'edytowane przez moderatora'),
    'edited_title':     ('Edited :at', 'Edytowano :at'),
    'held':             ('Waiting for a moderator', 'Czeka na moderatora'),
    'blocked':          ('Hidden — this member is blocked by you.', 'Ukryte — ten członek jest przez Ciebie zablokowany.'),
    'show':             ('Show', 'Pokaż'),
    'hide':             ('Hide', 'Ukryj'),
    # its actions (icon buttons: the name, then the tooltip)
    'approve':          ('Let it through', 'Dopuść'),
    'approve_tip':      ('Let this guest\'s comment through: everybody will see it, and the people it is news to are told now',
                         'Dopuść komentarz gościa: zobaczą go wszyscy, a osoby, których dotyczy, dostaną teraz powiadomienie'),
    'edit':             ('Edit comment', 'Edytuj komentarz'),
    'edit_tip_own':     ('Correct your comment', 'Popraw swój komentarz'),
    'edit_tip_any':     ('Edit this comment — it will say it was edited by a moderator, and its author is told',
                         'Edytuj ten komentarz — będzie oznaczony jako edytowany przez moderatora, a autor dostanie powiadomienie'),
    'delete':           ('Delete comment', 'Usuń komentarz'),
    'delete_tip_own':   ('Delete your comment', 'Usuń swój komentarz'),
    'delete_tip_any':   ('Remove this comment — its author is told why', 'Usuń ten komentarz — autor dostanie powód'),
    'delete_sure':      ('Click again to delete', 'Kliknij ponownie, aby usunąć'),
    'reason_label':     ('Why is it removed? Its author is shown this.', 'Dlaczego jest usuwany? Autor zobaczy ten powód.'),
    'reason_ph':        ('The reason', 'Powód'),
    'reason_go':        ('Remove', 'Usuń'),
    'reason_needed':    ('Say why first: its author is told.', 'Najpierw podaj powód: autor go zobaczy.'),
    'edit_reason_ph':   ('Why is it changed? (optional — its author is told)', 'Dlaczego jest zmieniany? (opcjonalnie — autor zobaczy)'),
    'placeholder_edit': ('The comment', 'Treść komentarza'),
    'save':             ('Save', 'Zapisz'),
    'failed':           ('That did not work. Try again in a moment.', 'Nie udało się. Spróbuj za chwilę.'),
    # the composer
    'write':            ('Write', 'Pisz'),
    'preview':          ('Preview', 'Podgląd'),
    'toolbar':          ('Formatting', 'Formatowanie'),
    'tb_bold':          ('Bold (Ctrl+B)', 'Pogrubienie (Ctrl+B)'),
    'tb_italic':        ('Italic (Ctrl+I)', 'Kursywa (Ctrl+I)'),
    'tb_underline':     ('Underline', 'Podkreślenie'),
    'tb_strike':        ('Strikethrough', 'Przekreślenie'),
    'tb_link':          ('Link (Ctrl+K)', 'Odnośnik (Ctrl+K)'),
    'tb_quote':         ('Quote', 'Cytat'),
    'tb_spoiler':       ('Spoiler', 'Spoiler'),
    'tb_code':          ('Code', 'Kod'),
    'emoji':            ('Emoji', 'Emotki'),
    'emoji_emotes':     ('Emoji and emotes', 'Emotki i emote'),
    'placeholder':      ('Write a comment… (@name mentions somebody)', 'Napisz komentarz… (@nazwa wspomina o kimś)'),
    'placeholder_plain': ('Write a comment…', 'Napisz komentarz…'),
    'hint':             ('[b] [i] [u] [s] [url] [quote] [spoiler] [code] — emoji and emotes, no pictures. Ctrl+Enter sends.',
                         '[b] [i] [u] [s] [url] [quote] [spoiler] [code] — emotki i emote, bez obrazków. Ctrl+Enter wysyła.'),
    'hint_nolinks':     ('[b] [i] [u] [s] [quote] [spoiler] [code] — emoji and emotes, no pictures and no links. Ctrl+Enter sends.',
                         '[b] [i] [u] [s] [quote] [spoiler] [code] — emotki i emote, bez obrazków i bez linków. Ctrl+Enter wysyła.'),
    'as_member':        ('Commenting as :name', 'Komentujesz jako :name'),
    'as_guest':         ('Commenting as a guest: a CAPTCHA every time, and no links.',
                         'Komentujesz jako gość: za każdym razem CAPTCHA i bez linków.'),
    'as_guest_review':  ('Commenting as a guest: a CAPTCHA every time, no links, and a moderator lets it through first.',
                         'Komentujesz jako gość: za każdym razem CAPTCHA, bez linków, a najpierw komentarz dopuszcza moderator.'),
    'send':             ('Comment', 'Skomentuj'),
    'sending':          ('Sending…', 'Wysyłanie…'),
    'empty':            ('Write something first.', 'Najpierw coś napisz.'),
    'mention_list':     ('Members to mention', 'Członkowie do wspomnienia'),
    # why the composer is not there
    'why_login':        ('Sign in to comment.', 'Zaloguj się, aby komentować.'),
    'sign_in':          ('Sign in', 'Zaloguj się'),
    'why_no_permission': ('Your account cannot comment here.', 'Twoje konto nie może tu komentować.'),
    'why_muted':        ('This account is silenced by a moderator until :until: you can read the comments, but not write one.',
                         'To konto jest wyciszone przez moderatora do :until: możesz czytać komentarze, ale nie pisać.'),
    'why_guest_captcha': ('Guests can comment only where the site has a CAPTCHA set up. Sign in to comment.',
                          'Goście mogą komentować tylko wtedy, gdy strona ma ustawioną CAPTCHA. Zaloguj się, aby komentować.'),
    # the account page's switches
    'prefs_saved':      ('Saved', 'Zapisano'),
})

# the account page's notifications: a notification that says where it happened gets a button to go there
add('js.app', {
    'notif_go_link':    ('Show', 'Pokaż'),
})


# ── what the endpoints answer (includes/comments.php) ──────────────────────
add('api.comment', {
    'disabled':         ('Comments are switched off on this site.', 'Komentarze są na tej stronie wyłączone.'),
    'bad_hash':         ('That is not an info hash.', 'To nie jest info hash.'),
    'not_found':        ('There is no such torrent or comment here.', 'Nie ma tu takiego torrenta ani komentarza.'),
    'login':            ('Sign in to comment.', 'Zaloguj się, aby komentować.'),
    'no_permission':    ('Your account may not do that here.', 'Twoje konto nie może tego tu zrobić.'),
    'no_view':          ('Your account may not read the comments.', 'Twoje konto nie może czytać komentarzy.'),
    'muted':            ('This account is silenced by a moderator until :until.', 'To konto jest wyciszone przez moderatora do :until.'),
    'guest_captcha':    ('Guests can comment only where the site has a CAPTCHA set up.', 'Goście mogą komentować tylko wtedy, gdy strona ma ustawioną CAPTCHA.'),
    'invalid':          ('That comment could not be read.', 'Nie udało się odczytać komentarza.'),
    'bad_encoding':     ('That text is not valid UTF-8.', 'Ten tekst nie jest poprawnym UTF-8.'),
    'empty':            ('The comment is empty.', 'Komentarz jest pusty.'),
    'too_long':         ('That comment shows :n characters; the limit is :max.', 'Ten komentarz ma :n widocznych znaków; limit to :max.'),
    'too_long_source':  ('That is too much text, tags included (at most :max characters).', 'To za dużo tekstu razem ze znacznikami (najwyżej :max znaków).'),
    'too_many_lines':   ('A comment may have at most :max lines.', 'Komentarz może mieć najwyżej :max wierszy.'),
    'no_links':         ('Links are not allowed here — write the address as plain text instead.',
                         'Linki nie są tu dozwolone — wpisz adres jako zwykły tekst.'),
    'bad_link':         ('A link must be a full http(s) address, with no spaces.', 'Link musi być pełnym adresem http(s), bez spacji.'),
    'too_many_links':   ('A comment may have at most :max links.', 'Liczba linków w komentarzu: najwyżej :max.'),
    'captcha_required': ('Please solve the CAPTCHA to send this comment.', 'Rozwiąż CAPTCHA, aby wysłać komentarz.'),
    'captcha_failed':   ('The CAPTCHA was not accepted. Please try again.', 'CAPTCHA nie została przyjęta. Spróbuj ponownie.'),
    'rate_limit':       ('Too many comments in the last hour. Please wait a while.', 'Za dużo komentarzy w ciągu ostatniej godziny. Odczekaj chwilę.'),
    'failed':           ('The comment could not be saved.', 'Nie udało się zapisać komentarza.'),
    'too_late':         ('The time for changing this comment has passed.', 'Czas na zmianę tego komentarza minął.'),
    'reason_required':  ('Say why it is removed: its author is told.', 'Podaj powód usunięcia: autor go zobaczy.'),
    'not_pending':      ('That comment is not waiting for a moderator.', 'Ten komentarz nie czeka na moderatora.'),
    'nothing_to_change': ('Nothing to change.', 'Nie ma nic do zmiany.'),
    'posted':           ('Your comment is up.', 'Komentarz dodany.'),
    'posted_pending':   ('Thank you — your comment will appear once a moderator lets it through.',
                         'Dziękujemy — komentarz pojawi się, gdy dopuści go moderator.'),
    'edited':           ('Saved.', 'Zapisano.'),
    'unchanged':        ('Nothing changed.', 'Nic się nie zmieniło.'),
    'deleted':          ('Your comment is deleted.', 'Komentarz usunięty.'),
    'removed':          ('The comment is removed, and its author is told why.', 'Komentarz usunięty, a autor dostanie powód.'),
    'approved':         ('Let through: everybody sees it now.', 'Dopuszczono: widzą go teraz wszyscy.'),
})


# ── the notifications a comment leaves, in the recipient's language ──────────
add('notify', {
    'comment_mine':     (':user commented on your torrent ":name"', 'Nowy komentarz od :user pod Twoim torrentem „:name”'),
    'comment_desc':     (':user commented on ":name", whose description you wrote', 'Nowy komentarz od :user pod „:name” (Twój opis)'),
    'comment_thread':   (':user also commented on ":name"', 'Nowy komentarz od :user w wątku „:name”'),
    'comment_mention':  (':user mentioned you in a comment on ":name"', ':user wspomina o Tobie w komentarzu pod „:name”'),
    'comment_body':     ('“:text”', '„:text”'),
    'comment_guest':    ('A guest', 'Gość'),
    'comment_removed':  ('Your comment on ":name" was removed by a moderator', 'Twój komentarz pod „:name” został usunięty przez moderatora'),
    'comment_edited':   ('Your comment on ":name" was edited by a moderator', 'Twój komentarz pod „:name” został edytowany przez moderatora'),
    'comment_reason':   ('The reason given: :reason', 'Podany powód: :reason'),
})


# ── the account page: the two sounds, and which comments I am told about ─────
add('account', {
    'snd_ev_comment':          ('Somebody comments where I am told of it', 'Ktoś komentuje tam, gdzie dostaję powiadomienia'),
    'snd_ev_comment_mention':  ('A comment mentions me (@name)', 'Komentarz wspomina o mnie (@nazwa)'),
    'comment_prefs_head':      ('Comments', 'Komentarze'),
    'comment_prefs_note':      ('Tell me in my notifications about a new comment…', 'Powiadamiaj mnie o nowym komentarzu…'),
    'comment_pref_mine':       ('…on a torrent I registered', '…pod torrentem zarejestrowanym przeze mnie'),
    'comment_pref_desc':       ('…on a torrent whose description I wrote', '…pod torrentem z moim opisem'),
    'comment_pref_thread':     ('…in a thread I commented in', '…w wątku, w którym komentuję'),
    'comment_pref_mention':    ('…that mentions me (@name)', '…który wspomina o mnie (@nazwa)'),
})


# ── Settings → Descriptions, comments & ratings → Comments; → Sounds ────────
add('settings', {
    'comments_heading':        ('Comments', 'Komentarze'),
    'comments_intro':          ('What members say under a torrent, in its Info panel — safe by construction: the words, emoji, '
                                'Font Awesome\'s icons and the site\'s emotes (drawn at the size of the words), and a short list of '
                                'BBCode: <code>[b] [i] [u] [s] [quote] [spoiler] [code]</code> and, while links are allowed, '
                                '<code>[url]</code>. Nothing else: no pictures, no tables, no sizes or colours — any other tag is '
                                'shown as it was typed. Who may read, write, correct and moderate is the permissions '
                                '(<code>comment.view</code>, <code>comment.post</code>, <code>comment.edit_own</code>, '
                                '<code>comment.delete_own</code>, <code>comment.moderate</code>): members hold all but the last, '
                                'moderators read, write and moderate — taking down or editing anybody\'s comment with a reason its '
                                'author is shown. A new comment tells the member who registered the torrent, the author of its '
                                'description, the members who commented before and anybody it @-mentions — each can switch any of '
                                'these off on their account page — with a sound of its own (Sounds).',
                                'To, co członkowie piszą pod torrentem, w jego panelu Info — bezpieczne z założenia: słowa, emotki, '
                                'ikony Font Awesome i emote strony (w rozmiarze tekstu) oraz krótka lista BBCode: '
                                '<code>[b] [i] [u] [s] [quote] [spoiler] [code]</code> i, gdy linki są dozwolone, <code>[url]</code>. '
                                'Nic więcej: bez obrazków, tabel, rozmiarów i kolorów — każdy inny znacznik jest pokazywany tak, jak '
                                'go wpisano. Kto może czytać, pisać, poprawiać i moderować, decydują uprawnienia '
                                '(<code>comment.view</code>, <code>comment.post</code>, <code>comment.edit_own</code>, '
                                '<code>comment.delete_own</code>, <code>comment.moderate</code>): członkowie mają wszystkie poza '
                                'ostatnim, moderatorzy czytają, piszą i moderują — usuwają lub edytują każdy komentarz z powodem, '
                                'który zobaczy autor. Nowy komentarz powiadamia członka, który zarejestrował torrent, autora jego '
                                'opisu, członków, którzy komentowali wcześniej, i każdego, o kim wspomina przez @ — każdy może to '
                                'wyłączyć na stronie konta — z własnym dźwiękiem (Dźwięki).'),
    'comments_guests_intro':   ('<strong>Guests</strong> can comment only if you grant <code>comment.view</code> and '
                                '<code>comment.post</code> to the Guest group (Users → Groups; nothing is granted to guests by '
                                'default). A guest\'s comment is signed <em>Guest #4f2a</em> — a tag that tells two guests apart on '
                                'one day and says nothing about them, never their address — with no link and no picture. A guest '
                                'solves a CAPTCHA <strong>every time</strong> (with no CAPTCHA provider set up in '
                                '<a href=":captcha">Security &amp; CAPTCHA</a>, guests cannot comment at all), can never post a '
                                'link, cannot correct or delete the comment, is told nothing and cannot be @-mentioned. While '
                                '"Guests\' comments" says so, a moderator lets each one through first — from the thread, where it '
                                'waits marked for them.',
                                '<strong>Goście</strong> mogą komentować tylko, jeśli przyznasz grupie Gość uprawnienia '
                                '<code>comment.view</code> i <code>comment.post</code> (Użytkownicy → Grupy; domyślnie goście nie '
                                'mają żadnego). Komentarz gościa jest podpisany <em>Gość #4f2a</em> — znacznikiem, który odróżnia '
                                'dwóch gości tego samego dnia i nic o nich nie mówi, nigdy nie zdradza adresu — bez linku i bez '
                                'zdjęcia. Gość rozwiązuje CAPTCHA <strong>za każdym razem</strong> (bez dostawcy CAPTCHA ustawionego '
                                'w <a href=":captcha">Bezpieczeństwo i CAPTCHA</a> goście w ogóle nie mogą komentować), nigdy nie '
                                'może dodać linku, nie może poprawić ani usunąć komentarza, nie dostaje powiadomień i nie można o '
                                'nim wspomnieć przez @. Gdy tak mówi ustawienie „Komentarze gości”, każdy najpierw dopuszcza '
                                'moderator — z wątku, gdzie czeka oznaczony dla niego.'),
    'comments_enabled':        ('Comments', 'Komentarze'),
    'comments_enabled_hint':   ('Off: no thread in any Info panel, nothing written and nobody told. The comments already '
                                'written are kept for when they are switched on again.',
                                'Wyłączone: żadnego wątku w panelu Info, nic nie jest zapisywane i nikt nie dostaje powiadomień. '
                                'Napisane komentarze zostają na czas ponownego włączenia.'),
    'comment_max_chars':       ('Longest comment', 'Najdłuższy komentarz'),
    'comment_max_chars_hint':  ('In characters a reader sees — the words, not the tags round them (:min–:max). The text as '
                                'typed may be four times that; at most :lines lines.',
                                'W znakach, które widzi czytelnik — słowa, nie znaczniki wokół nich (:min–:max). Tekst ze '
                                'znacznikami może być cztery razy dłuższy; najwyżej :lines wierszy.'),
    'comment_links':           ('Links in comments', 'Linki w komentarzach'),
    'comment_links_on':        ('Allowed (members)', 'Dozwolone (członkowie)'),
    'comment_links_off':       ('Not allowed', 'Niedozwolone'),
    'comment_links_hint':      ('A member\'s <code>[url]</code> becomes a link — at most :max in a comment, marked nofollow for '
                                'search engines, and with the "you are leaving" question for any other site. Off — and always '
                                'for guests — <code>[url]</code> is refused, an address stays text, and links already written '
                                'are shown as text too.',
                                '<code>[url]</code> od członka staje się linkiem — najwyżej :max w komentarzu, z atrybutem '
                                'nofollow dla wyszukiwarek i z pytaniem „opuszczasz stronę” przy każdej innej witrynie. Wyłączone — '
                                'i zawsze dla gości — <code>[url]</code> jest odrzucany, adres zostaje tekstem, a linki już '
                                'napisane też są pokazywane jako tekst.'),
    'comments_per_page':       ('Comments on a page', 'Komentarzy na stronie'),
    'comments_per_page_hint':  ('The newest page opens first, and "Show earlier comments" loads the one before it.',
                                'Najpierw otwiera się najnowsza strona, a „Pokaż wcześniejsze komentarze” wczytuje poprzednią.'),
    'comment_edit_minutes':    ('Correcting one\'s own', 'Poprawianie własnego'),
    'comment_edit_minutes_hint': ('Minutes after writing it that a member may correct their comment '
                                  '(<code>comment.edit_own</code>); 0 = never. A moderator is held by no window. An edit is marked '
                                  'on the comment, and a moderator\'s says so.',
                                  'Przez tyle minut od napisania członek może poprawić swój komentarz '
                                  '(<code>comment.edit_own</code>); 0 = nigdy. Moderatora nie ogranicza żadne okno. Edycja jest '
                                  'oznaczona przy komentarzu, a edycja moderatora mówi o tym wprost.'),
    'comment_delete_own_minutes': ('Deleting one\'s own', 'Usuwanie własnego'),
    'comment_delete_own_minutes_hint': ('Minutes after writing it that a member may delete their comment '
                                        '(<code>comment.delete_own</code>); 0 = no limit. A mute does not stop it: taking your '
                                        'own words back is not something to be allowed to do.',
                                        'Przez tyle minut od napisania członek może usunąć swój komentarz '
                                        '(<code>comment.delete_own</code>); 0 = bez limitu. Wyciszenie tego nie blokuje: '
                                        'wycofanie własnych słów nie wymaga pozwolenia.'),
    'comment_rate_per_hour':   ('Comments per hour', 'Komentarzy na godzinę'),
    'comment_rate_per_hour_hint': ('For one account — for guests, one address group. A correction counts too.',
                                   'Na jedno konto — dla gości na jedną grupę adresów. Poprawka też się liczy.'),
    'captcha_pts_comment':     ('CAPTCHA points per comment', 'Punkty CAPTCHA za komentarz'),
    'captcha_pts_comment_hint': ('A member\'s comment adds this to the <a href=":url">smart CAPTCHA\'s</a> score that the site\'s '
                                 'forms read. A comment\'s own CAPTCHA is the anti-spam layer\'s (Security → Anti-spam): a member '
                                 'meets one only by commenting at the top of its ladder, and a guest solves one every time.',
                                 'Komentarz członka dodaje tyle do wyniku <a href=":url">inteligentnej CAPTCHA</a>, który czytają '
                                 'formularze serwisu. Własną CAPTCHA komentarza daje ochrona przed spamem (Bezpieczeństwo → Ochrona '
                                 'przed spamem): członek trafia na nią tylko, komentując na szczycie drabinki, a gość rozwiązuje ją '
                                 'za każdym razem.'),
    'comments_guest_review':   ('Guests\' comments', 'Komentarze gości'),
    'comments_guest_review_on': ('Wait for a moderator', 'Czekają na moderatora'),
    'comments_guest_review_off': ('Shown at once', 'Widoczne od razu'),
    'comments_guest_review_hint': ('A held comment waits in its thread, marked, for a moderator\'s "Let it through" or '
                                   'removal; nobody is told of it until it is let through. Only guests\' comments ever wait.',
                                   'Wstrzymany komentarz czeka w swoim wątku, oznaczony, aż moderator go dopuści albo usunie; nikt '
                                   'nie dostaje o nim powiadomienia przed dopuszczeniem. Czekają tylko komentarze gości.'),
    'comment_matrix_title':    ('Who may read, write and moderate comments', 'Kto może czytać, pisać i moderować komentarze'),
    'comment_matrix_hint':     ('Every group\'s comment permissions, read-only — change them in <strong>Users → Groups</strong>. '
                                'The Guest group holds none unless you grant them.',
                                'Uprawnienia komentarzy każdej grupy, tylko do odczytu — zmienia się je w '
                                '<strong>Użytkownicy → Grupy</strong>. Grupa Gość nie ma żadnego, dopóki go nie przyznasz.'),
    # Sounds
    'sounds_default_comment':  ('Default for a comment', 'Domyślny dla komentarza'),
    'sounds_default_comment_hint': ('A new comment on a torrent the member registered, on a description they wrote, or in '
                                    'a thread they commented in — each as their account page lets it through.',
                                    'Nowy komentarz pod torrentem zarejestrowanym przez członka, pod jego opisem albo w wątku, '
                                    'w którym bierze udział — tak, jak pozwalają ustawienia jego konta.'),
    'sounds_default_comment_mention': ('Default for an @-mention in a comment', 'Domyślny dla wzmianki @ w komentarzu'),
    'sounds_ev_comment':       ('Comment', 'Komentarz'),
    'sounds_ev_comment_mention': ('Comment mentions me', 'Wzmianka w komentarzu'),
})
