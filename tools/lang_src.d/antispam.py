# -*- coding: utf-8 -*-
"""One anti-spam layer for everything people write (1.71.0, part F)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

includes/antispam.php's refusals (api.antispam.* -- the time is ":time", written with the unit words
below, so no plural can come out wrong), the Settings page's Anti-spam section and messages' own address
ceiling (settings.antispam_*, settings.rate_limit_pm*), and what the pages say while they count down or
ask for a CAPTCHA (js.antispam.*, the lazily drawn CAPTCHA box js.captcha.*, the comment composer's note
about a new account's links). The Polish avoids the gendered past tense the way the site does ("zaczęto z
Twojego konta", "zostały wysłane"), and a count is put where its noun needs no declension ("do :n nowych
rozmów", "(:n)").
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the layer's answers (antispamRefusal()) ─────────────────────────────────────────────────────────────
add('api.antispam', {
    # the unit words antispamTimeText() writes a time with: "12 s", "2 min 5 s", "1 h 5 min"
    'u_s':   ('s', 's'),
    'u_min': ('min', 'min'),
    'u_h':   ('h', 'godz.'),
    'wait_shout':       ('Too fast for the room — you can write again in :time.',
                         'Za szybko jak na ten pokój — znów możesz pisać za :time.'),
    'wait_message':     ('You are starting new conversations too fast — you can start another in :time.',
                         'Za szybko zaczynasz nowe rozmowy — kolejną możesz zacząć za :time.'),
    'wait_comment':     ('You are commenting too fast — you can comment again in :time.',
                         'Za szybko dodajesz komentarze — kolejny możesz dodać za :time.'),
    'wait_description': ('You are sending descriptions too fast — you can send another in :time.',
                         'Za szybko wysyłasz opisy — kolejny możesz wysłać za :time.'),
    'wait_report':      ('You are sending reports too fast — you can send another in :time.',
                         'Za szybko wysyłasz zgłoszenia — kolejne możesz wysłać za :time.'),
    'wait_list':        ('You are changing your lists too fast — try again in :time.',
                         'Za szybko zmieniasz swoje listy — spróbuj ponownie za :time.'),
    'wait_bio':         ('You are saving your description too often — try again in :time.',
                         'Za często zapisujesz swój opis — spróbuj ponownie za :time.'),
    'wait_emote':       ('You are uploading too fast — you can upload again in :time.',
                         'Za szybko wysyłasz obrazki — kolejny możesz wysłać za :time.'),
    'wait_vote':        ('You are voting too fast — you can vote again in :time.',
                         'Za szybko oceniasz — znów możesz ocenić za :time.'),
    'wait_edit':        ('You are correcting too fast — try again in :time.',
                         'Za szybko poprawiasz — spróbuj ponownie za :time.'),
    'captcha':          ('You have been going very fast for a while — please confirm you are a person to go on.',
                         'Od dłuższej chwili działasz bardzo szybko — potwierdź, że jesteś człowiekiem, aby kontynuować.'),
    'captcha_guest':    ('Guests confirm they are a person every time they write something.',
                         'Goście potwierdzają, że są ludźmi, za każdym razem, gdy coś piszą.'),
    'captcha_failed':   ('The CAPTCHA was not accepted — please try again.',
                         'CAPTCHA nie została przyjęta — spróbuj ponownie.'),
    'duplicate':        ('Exactly these words were just sent here — write something new, or wait a few minutes.',
                         'Dokładnie te słowa zostały tu przed chwilą wysłane — napisz coś nowego albo odczekaj kilka minut.'),
    'spread':           ('The same words have just gone to several people — that is how spam is sent, so this message was not sent.',
                         'Te same słowa trafiły przed chwilą do kilku osób — tak rozsyła się spam, więc ta wiadomość nie została wysłana.'),
    'conversations_hour': ('In the last hour your account has started as many new conversations as the site allows (:n) — you can start another in :time.',
                           'W ciągu ostatniej godziny zaczęto z Twojego konta tyle nowych rozmów, ile pozwala serwis (:n) — kolejną możesz zacząć za :time.'),
    'conversations_day':  ('In the last 24 hours your account has started as many new conversations as the site allows (:n) — you can start another in :time.',
                           'W ciągu ostatniej doby zaczęto z Twojego konta tyle nowych rozmów, ile pozwala serwis (:n) — kolejną możesz zacząć za :time.'),
    'conversations_hour_new': ('For its first :days days a new account may start up to :n new conversations an hour — you can start another in :time.',
                               'Nowe konto może w pierwszych dniach (:days) zaczynać do :n nowych rozmów na godzinę — kolejną możesz zacząć za :time.'),
    'conversations_day_new':  ('For its first :days days a new account may start up to :n new conversations a day — you can start another in :time.',
                               'Nowe konto może w pierwszych dniach (:days) zaczynać do :n nowych rozmów na dobę — kolejną możesz zacząć za :time.'),
    'unavailable':      ('The anti-spam check could not be made just now — please try again in a moment.',
                         'Nie udało się teraz sprawdzić zabezpieczenia przed spamem — spróbuj ponownie za chwilę.'),
})

# ── Settings → Security & CAPTCHA → Anti-spam (templates/admin/settings.php #section-antispam) ─────────────
add('settings', {
    'antispam_heading': ('Anti-spam', 'Ochrona przed spamem'),
    'antispam_intro': ('One layer for everything people write — a line in the shoutbox, a message, a comment, a torrent\'s '
                       'description, a report, a list, the profile\'s description, an emote upload, a vote. In each place a '
                       'few writes are free, then the pauses grow, and a quiet spell forgets it all; whoever keeps going at the '
                       'top of a ladder meets a CAPTCHA, and a guest solves one every time they write. The same words twice '
                       'in a row are refused, and a new account is held to stricter rules. The page counts the wait down on '
                       'its Send button.',
                       'Jedna warstwa dla wszystkiego, co ludzie piszą — wpisu w shoutboxie, wiadomości, komentarza, opisu '
                       'torrenta, zgłoszenia, listy, opisu profilu, wysyłanej emotki, głosu. W każdym z tych miejsc kilka '
                       'pierwszych razy jest bez ograniczeń, potem przerwy rosną, a chwila ciszy wszystko zeruje; kto dalej '
                       'pędzi na szczycie drabinki, dostaje CAPTCHA, a gość rozwiązuje ją przy każdym wpisie. Dwa razy z rzędu '
                       'te same słowa są odrzucane, a nowe konto ma surowsze zasady. Strona odlicza czas oczekiwania na '
                       'przycisku Wyślij.'),
    'antispam_no_captcha': ('No CAPTCHA is set up (<a href=":url">Security → CAPTCHA</a>), so nobody can be asked to prove '
                            'they are a person: the layer uses its pauses alone. Guests cannot comment at all without one, and a '
                            'guest\'s description waits on the pauses only.',
                            'Nie skonfigurowano CAPTCHA (<a href=":url">Bezpieczeństwo → CAPTCHA</a>), więc nie można nikogo '
                            'poprosić o dowód, że jest człowiekiem: warstwa korzysta wyłącznie z przerw. Bez CAPTCHA goście w '
                            'ogóle nie mogą komentować, a opis od gościa podlega tylko przerwom.'),
    'antispam_enabled': ('Anti-spam layer', 'Ochrona przed spamem'),
    'antispam_enabled_hint': ('Off: nobody is paced and no rule below applies — the old walls stay (the room\'s '
                              '<a href=":url">minimum pause</a>, the hourly limits). The guests\' CAPTCHA has its own switch.',
                              'Wyłączona: nikt nie jest spowalniany i żadna z reguł poniżej nie działa — zostają dawne '
                              'ograniczenia (<a href=":url">minimalna przerwa</a> w pokoju, limity godzinowe). CAPTCHA dla '
                              'gości ma własny przełącznik.'),
    'antispam_captcha_after': ('CAPTCHA after this many hits at the top', 'CAPTCHA po tylu trafieniach w szczyt'),
    'antispam_captcha_after_hint': ('A write made at a ladder\'s last pause, or a try while waiting there, is a hit. Solving '
                                    'the CAPTCHA eases the ladder back to its first pause. 0 = never ask.',
                                    'Trafienie to wpis przy ostatniej przerwie drabinki albo próba w czasie czekania na '
                                    'niej. Rozwiązanie CAPTCHA cofa drabinkę do pierwszej przerwy. 0 = nigdy nie pytaj.'),
    'antispam_guest_captcha': ('Guests: a CAPTCHA every time', 'Goście: CAPTCHA za każdym razem'),
    'antispam_guest_captcha_hint': ('A comment or a description by somebody who is not signed in waits for a solved CAPTCHA '
                                    'every time (while a provider is set up). Works whether or not the layer is on.',
                                    'Komentarz albo opis od kogoś niezalogowanego wymaga za każdym razem rozwiązanej CAPTCHA '
                                    '(o ile skonfigurowano dostawcę). Działa niezależnie od tego, czy warstwa jest włączona.'),
    'antispam_staff_exempt': ('Staff are exempt', 'Administracja zwolniona z limitów'),
    'antispam_staff_exempt_hint': ('Accounts that can open the panel skip the pauses, the CAPTCHA they lead to and the '
                                   'new-account rules. No: they are paced like everybody else, CAPTCHA included. The same '
                                   'words twice are refused for everybody.',
                                   'Konta z dostępem do panelu pomijają przerwy, CAPTCHA, do której prowadzą, i zasady dla '
                                   'nowych kont. Nie: administracja jest spowalniana jak wszyscy, łącznie z CAPTCHA. Dwa razy '
                                   'te same słowa są odrzucane u każdego.'),
    'antispam_ladders_heading': ('Ladders', 'Drabinki'),
    'antispam_ladders_sub': ('per place: free writes, then pauses in seconds (the last one repeats), all forgotten after a quiet spell',
                             'dla każdego miejsca: wpisy bez ograniczeń, potem przerwy w sekundach (ostatnia się powtarza), a po chwili ciszy wszystko od nowa'),
    'antispam_col_where': ('Where', 'Gdzie'),
    'antispam_col_burst': ('Free', 'Bez przerw'),
    'antispam_col_steps': ('Then pauses (s)', 'Potem przerwy (s)'),
    'antispam_col_reset': ('Forgotten after (s)', 'Zeruje się po (s)'),
    'antispam_ctx_shout': ('Shoutbox', 'Shoutbox'),
    'antispam_ctx_shout_hint': ('the owner\'s sketch: three lines free, then 5, 15, 30, 60 s; two quiet minutes reset it. '
                                'The room\'s minimum pause is the floor under these.',
                                'szkic właściciela: trzy wpisy bez przerw, potem 5, 15, 30, 60 s; dwie minuty ciszy zerują. '
                                'Minimalna przerwa pokoju jest dolną granicą tych przerw.'),
    'antispam_ctx_message': ('New conversations', 'Nowe rozmowy'),
    'antispam_ctx_message_hint': ('counted by the conversations somebody starts, not by lines — a conversation both people '
                                  'are in is a chat, and either can block',
                                  'liczone po rozpoczętych rozmowach, nie po wiadomościach — rozmowa, w której są obie osoby, '
                                  'to czat, a każda z nich może zablokować drugą'),
    'antispam_ctx_comment': ('Comments', 'Komentarze'),
    'antispam_ctx_comment_hint': ('slower than the room: a comment is a considered text, and a thread is read later',
                                  'wolniej niż w pokoju: komentarz to przemyślany tekst, a wątek czyta się później'),
    'antispam_ctx_description': ('Descriptions', 'Opisy'),
    'antispam_ctx_description_hint': ('much slower: a moderator reads every submission, proposal and edit',
                                      'dużo wolniej: moderator czyta każde zgłoszenie opisu, propozycję i poprawkę'),
    'antispam_ctx_report': ('Reports', 'Zgłoszenia'),
    'antispam_ctx_report_hint': ('much slower: a moderator reads every report — a few at once stay free for a spam wave',
                                 'dużo wolniej: moderator czyta każde zgłoszenie — kilka naraz jest wolnych na wypadek fali spamu'),
    'antispam_ctx_list': ('Lists', 'Listy'),
    'antispam_ctx_list_hint': ('a list\'s name and description — one person\'s shelf, a few saves free',
                               'nazwa i opis listy — czyjaś własna półka, kilka zapisów bez przerw'),
    'antispam_ctx_bio': ('Profile description', 'Opis profilu'),
    'antispam_ctx_bio_hint': ('a few saves free while somebody polishes their words',
                              'kilka zapisów bez przerw, kiedy ktoś dopracowuje swój tekst'),
    'antispam_ctx_emote': ('Emote uploads', 'Wysyłanie emotek'),
    'antispam_ctx_emote_hint': ('pictures are heavy and wait for approval anyway',
                                'obrazki są ciężkie i i tak czekają na zatwierdzenie'),
    'antispam_ctx_vote': ('Votes', 'Głosy'),
    'antispam_ctx_vote_hint': ('ten free — somebody rating as they browse — then short pauses; a script meets the CAPTCHA',
                               'dziesięć bez przerw — ktoś ocenia, przeglądając — potem krótkie przerwy; skrypt trafia na CAPTCHA'),
    'antispam_new_heading': ('New accounts', 'Nowe konta'),
    'antispam_new_sub': ('while an account is younger than the days below', 'dopóki konto jest młodsze niż liczba dni poniżej'),
    'antispam_new_days': ('Days an account counts as new', 'Przez ile dni konto jest nowe'),
    'antispam_new_days_hint': ('0 = no new-account rules at all.', '0 = żadnych zasad dla nowych kont.'),
    'antispam_new_factor': ('Pauses × for a new account', 'Przerwy × dla nowego konta'),
    'antispam_new_factor_hint': ('Every pause, and the quiet spell that forgets them, is this many times longer.',
                                 'Każda przerwa i czas ciszy, po którym się zerują, jest tyle razy dłuższy.'),
    'antispam_new_links': ('A new account\'s links are text', 'Linki nowego konta jako tekst'),
    'antispam_new_links_hint': ('In the shoutbox, comments, a list\'s description and the profile\'s description the address '
                                'stays readable and nothing is clickable. Decided by when the words were written: a link '
                                'written on the first day stays text.',
                                'W shoutboxie, komentarzach, opisie listy i opisie profilu adres zostaje czytelny, ale nic '
                                'nie jest klikalne. Decyduje chwila napisania: link z pierwszego dnia zostaje tekstem.'),
    'antispam_pm_heading': ('Messages', 'Wiadomości'),
    'antispam_pm_sub': ('new conversations — the send\'s other limits are with the <a href=":url">messages</a>',
                        'nowe rozmowy — pozostałe limity wysyłania są przy <a href=":url">wiadomościach</a>'),
    'antispam_pm_new_hour': ('New conversations an hour', 'Nowe rozmowy na godzinę'),
    'antispam_pm_new_day': ('New conversations a day', 'Nowe rozmowy na dobę'),
    'antispam_pm_new_hour_new': ('A new account: an hour', 'Nowe konto: na godzinę'),
    'antispam_pm_new_day_new': ('A new account: a day', 'Nowe konto: na dobę'),
    'antispam_pm_spread': ('The same words to at most this many people', 'Te same słowa do najwyżej tylu osób'),
    'antispam_pm_spread_hint': ('within the duplicate window; one more is refused as spam. 0 = no such rule.',
                                'w oknie duplikatów; kolejna osoba jest odrzucana jako spam. 0 = bez tej reguły.'),
    'antispam_pm_limits_hint': ('A conversation starts with the first message between two people. Replies are never counted. '
                                '0 = no limit. "A day" is the last 24 hours.',
                                'Rozmowa zaczyna się od pierwszej wiadomości między dwiema osobami. Odpowiedzi nigdy się nie '
                                'liczą. 0 = bez limitu. „Doba” to ostatnie 24 godziny.'),
    'antispam_dup_heading': ('Duplicates', 'Duplikaty'),
    'antispam_dup_seconds': ('The same words again within (s)', 'Te same słowa ponownie w ciągu (s)'),
    'antispam_dup_hint': ('In the shoutbox, a message (to the same person), a comment or a description, the same words (case '
                          'and spacing aside) sent again this soon are refused; fewer than 8 characters never count. 0 = no '
                          'duplicate rule.',
                          'W shoutboxie, wiadomości (do tej samej osoby), komentarzu lub opisie te same słowa (bez względu '
                          'na wielkość liter i odstępy) wysłane ponownie tak szybko są odrzucane; krótsze niż 8 znaków '
                          'nigdy się nie liczą. 0 = bez reguły duplikatów.'),
    # Settings → User accounts → Messages: the send's own address ceiling
    'rate_limit_pm': ('Messages an hour from one address', 'Wiadomości na godzinę z jednego adresu'),
    'rate_limit_pm_hint': ('Against a script with a list of accounts. One account\'s pace, the conversations it starts and '
                           'the same words to many people are the <a href=":url">anti-spam layer\'s</a>.',
                           'Przeciw skryptowi z listą kont. Tempo jednego konta, rozmowy, które zaczyna, i te same słowa do '
                           'wielu osób należą do <a href=":url">ochrony przed spamem</a>.'),
})

# ── the pages: the countdown on a Send button, the CAPTCHA box drawn on demand ──────────────────────────
add('js.antispam', {
    # the time in a countdown's sentence (the server's template, "{time}"), written like the server writes it
    'u_s':   ('s', 's'),
    'u_min': ('min', 'min'),
    'u_h':   ('h', 'godz.'),
    'again': ('You can send again.', 'Możesz wysłać ponownie.'),
    'solving': ('Sending again…', 'Wysyłam ponownie…'),
})
add('js.captcha', {
    'verify_human': ('Please verify you are human', 'Potwierdź, że jesteś człowiekiem'),
    'cancel':       ('Cancel', 'Anuluj'),
})
add('js.comments', {
    'links_text': ('For your account\'s first days (:days) your links are shown as text.',
                   'W pierwszych dniach konta (:days) Twoje linki są pokazywane jako tekst.'),
})
