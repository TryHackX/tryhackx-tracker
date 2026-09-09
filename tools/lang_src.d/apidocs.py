# -*- coding: utf-8 -*-
"""The partner integration guide, and the review queue that goes with it

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


add('apidocs', {
    'h1':        ('Integration guide', 'Instrukcja integracji'),
    'api_off':   ('The API is switched off on this tracker right now. Requests will be refused until the operator turns it back on.',
                  'API jest w tej chwili wyłączone na tym trackerze. Żądania będą odrzucane, dopóki operator go nie włączy.'),
    'intro':     ('This page describes <strong>your</strong> key. The address you were sent carries the answers the operator chose for it, so what is written below is what your key will actually do — not one page covering every possibility. Your key itself is not on this page; it came in the same message, from a person.',
                  'Ta strona opisuje <strong>twój</strong> klucz. Adres, który dostałeś, niesie odpowiedzi wybrane dla niego przez operatora, więc to, co jest niżej, jest tym, co twój klucz naprawdę zrobi — a nie jedną stroną o wszystkich możliwościach. Samego klucza tu nie ma; przyszedł w tej samej wiadomości, od człowieka.'),
    'k_endpoint': ('Endpoint', 'Endpoint'),
    'k_approval': ('Approval', 'Zatwierdzanie'),
    'k_required': ('Required with every item', 'Wymagane przy każdej pozycji'),
    'approve_auto':   ('Automatic — accepted hashes are served immediately',
                       'Automatyczne — przyjęte hashe są serwowane od razu'),
    'approve_review': ('Reviewed — a person looks at every submission before the tracker serves it',
                       'Z przeglądem — człowiek ogląda każde zgłoszenie, zanim tracker zacznie je serwować'),
    'required_none':  ('nothing beyond the hash', 'nic poza hashem'),
    'h_auth':    ('Authentication', 'Uwierzytelnianie'),
    'auth_body': ('Two headers on every request. The bearer is your key id and your secret joined by a dot. Send it over HTTPS and store it the way you store a password — anybody holding it can register hashes as you.',
                  'Dwa nagłówki przy każdym żądaniu. Bearer to identyfikator klucza i sekret połączone kropką. Wysyłaj po HTTPS i przechowuj jak hasło — kto go ma, rejestruje hashe jako ty.'),
    'h_request': ('The request', 'Żądanie'),
    'request_body': ('One POST, one JSON body, any number of items up to the batch limit. An item may be a magnet link or a bare 40-character info hash.',
                     'Jeden POST, jedno ciało JSON, dowolnie wiele pozycji do limitu paczki. Pozycja może być linkiem magnet albo samym 40-znakowym info hashem.'),
    'required_note': ('Your key requires <code>:fields</code> on every item. An item without them is refused as <code>invalid</code> and named in the reply — it is not registered and then hidden, it is not registered at all.',
                      'Twój klucz wymaga <code>:fields</code> przy każdej pozycji. Pozycja bez nich jest odrzucana jako <code>invalid</code> i nazwana w odpowiedzi — nie jest rejestrowana i ukrywana, tylko w ogóle nie jest rejestrowana.'),
    'h_reply':   ('The reply', 'Odpowiedź'),
    'reply_auto': ('One result per item, in the order you sent them, plus a summary. <code>added</code> means the tracker is serving it.',
                   'Jeden wynik na pozycję, w kolejności wysyłki, plus podsumowanie. <code>added</code> znaczy, że tracker już to serwuje.'),
    'reply_review': ('One result per item, in the order you sent them, plus a summary. Your key goes through review, so a new hash comes back as <code>pending</code>: the row exists, and the tracker will not serve it until somebody here approves it. Do not show it to your users as live.',
                     'Jeden wynik na pozycję, w kolejności wysyłki, plus podsumowanie. Twój klucz przechodzi przez przegląd, więc nowy hash wraca jako <code>pending</code>: wiersz istnieje, ale tracker nie będzie go serwował, dopóki ktoś tutaj go nie zatwierdzi. Nie pokazuj tego swoim użytkownikom jako działającego.'),
    'h_status':  ('What each status means', 'Co znaczy każdy status'),
    'col_status': ('Status', 'Status'),
    'col_means': ('Meaning', 'Znaczenie'),
    'st_added':  ('Registered and being served.', 'Zarejestrowane i serwowane.'),
    'st_pending': ('Registered and waiting for a person. Not served yet.', 'Zarejestrowane i czeka na człowieka. Jeszcze nie serwowane.'),
    'st_exists': ('Somebody had already registered this hash. Nothing changed, and this is not an error.',
                  'Ktoś już zarejestrował ten hash. Nic się nie zmieniło i to nie jest błąd.'),
    'st_banned': ('Blocked on this tracker. It will not be served whoever sends it.',
                  'Zablokowane na tym trackerze. Nie będzie serwowane, kto by go nie wysłał.'),
    'st_invalid': ('Not a usable magnet or hash, or missing a field your key requires. The reason is in <code>error</code>.',
                   'To nie jest użyteczny magnet ani hash, albo brakuje pola wymaganego przez twój klucz. Powód jest w <code>error</code>.'),
    'h_rules':   ('Rules worth knowing before you write the client', 'Zasady, które warto znać przed napisaniem klienta'),
    'rule_idempotent': ('<strong>Idempotent.</strong> Sending the same hash again is safe and answers <code>exists</code>. You do not need to remember what you have already sent.',
                        '<strong>Idempotentne.</strong> Ponowne wysłanie tego samego hasha jest bezpieczne i daje <code>exists</code>. Nie musisz pamiętać, co już wysłałeś.'),
    'rule_additive':   ('<strong>Additive only.</strong> There is deliberately no remove endpoint — a key that can register cannot unregister, so a compromised key cannot empty the tracker.',
                        '<strong>Tylko dodawanie.</strong> Celowo nie ma endpointu usuwania — klucz, który rejestruje, nie może wyrejestrować, więc przejęty klucz nie opróżni trackera.'),
    'rule_batch':      ('<strong>Batch.</strong> Up to :n items per request. A larger batch is refused whole rather than truncated, so you never have to guess which half arrived.',
                        '<strong>Paczka.</strong> Do :n pozycji na żądanie. Większa jest odrzucana w całości, a nie ucinana — nigdy nie musisz zgadywać, która połowa doszła.'),
    'rule_limits':     ('<strong>Rate limits.</strong> Per minute and per day, per key. Over the limit you get <code>429</code> with a <code>retry_after</code>; keep sending anyway and the key is banned for a while.',
                        '<strong>Limity tempa.</strong> Na minutę i na dobę, per klucz. Po przekroczeniu dostajesz <code>429</code> z <code>retry_after</code>; jeśli mimo to wysyłasz dalej, klucz zostaje na jakiś czas zablokowany.'),
    'rule_errors':     ('<strong>Errors.</strong> <code>401</code> no bearer, <code>403</code> bad or banned key, <code>413</code> body too large, <code>422</code> the JSON is not what this endpoint takes. A per-item problem is never a 4xx — it is a <code>status</code> on that item.',
                        '<strong>Błędy.</strong> <code>401</code> brak bearera, <code>403</code> zły albo zablokowany klucz, <code>413</code> za duże ciało, <code>422</code> JSON nie w tym kształcie. Problem pojedynczej pozycji nigdy nie jest 4xx — jest statusem przy tej pozycji.'),
    'col_endpoint': ('Endpoint', 'Endpoint'),

    # ── the sign-in bridge ───────────────────────────────────────────────────
    'h_bridge':  ('Signing your members in here', 'Logowanie tutaj waszych użytkowników'),
    'bridge_intro': ('Your key can sign your people in on this tracker without them registering a second time. '
                     'Two of the four steps happen in the browser, so build it in this order:',
                     'Wasz klucz może zalogować waszych ludzi na tym trackerze bez zakładania konta drugi raz. '
                     'Dwa z czterech kroków dzieją się w przeglądarce, więc budujcie to w tej kolejności:'),
    'bridge_step1': ('Somebody signed in on your side clicks through to the tracker. Your <em>server</em> posts '
                     'their id to <code>v1/auth/login</code>.',
                     'Ktoś zalogowany u was klika w stronę trackera. Wasz <em>serwer</em> wysyła jego id na '
                     '<code>v1/auth/login</code>.'),
    'bridge_step2': ('The reply carries a one-time <code>handoff.url</code>. Redirect the browser to it — that '
                     'is the only way a session cookie for this site can be created.',
                     'Odpowiedź niesie jednorazowy <code>handoff.url</code>. Przekierujcie tam przeglądarkę — '
                     'tylko tak może powstać ciasteczko sesji dla tej strony.'),
    'bridge_step3': ('The ticket is spent on arrival, lives about two minutes, and works exactly once. Mint it '
                     'when the person clicks, never in advance.',
                     'Bilet zużywa się przy wejściu, żyje około dwóch minut i działa dokładnie raz. Twórzcie go '
                     'w chwili kliknięcia, nigdy wcześniej.'),
    'bridge_step4': ('When they sign out on your side, post the same id to <code>v1/auth/logout</code>. Their '
                     'bridged session here ends on the next page they open.',
                     'Gdy wylogują się u was, wyślijcie to samo id na <code>v1/auth/logout</code>. Ich '
                     'mostkowana sesja tutaj skończy się przy następnej otwartej stronie.'),
    'bridge_reverse': ('It works the other way too. Somebody signed in <em>here</em> can be sent to you already '
                       'signed in: the tracker redirects them to the address the operator configured with '
                       '<code>?thx_token=…</code>, and your server posts that token to <code>v1/auth/verify</code> '
                       'to learn who they are. The token crosses in a redirect; only your key can spend it.',
                       'Działa też w drugą stronę. Osoba zalogowana <em>tutaj</em> może trafić do was już '
                       'zalogowana: tracker przekierowuje ją pod adres ustawiony przez operatora z '
                       '<code>?thx_token=…</code>, a wasz serwer wysyła ten token na <code>v1/auth/verify</code>, '
                       'żeby dowiedzieć się, kto to jest. Token jedzie w przekierowaniu; wydać go może tylko '
                       'wasz klucz.'),
    'bridge_warning': ('<strong>Never put the bearer key in a browser.</strong> Every call here is made by your '
                       'server. What reaches the browser is a ticket that is worthless a minute later and '
                       'worthless twice — that difference is the whole security of this design.',
                       '<strong>Nigdy nie umieszczajcie klucza bearer w przeglądarce.</strong> Każde wywołanie '
                       'tutaj robi wasz serwer. Do przeglądarki trafia bilet bezwartościowy minutę później i '
                       'bezwartościowy drugi raz — na tej różnicy stoi całe bezpieczeństwo tego rozwiązania.'),
    'ep_auth_login':  ('Find, link or create the account behind one of your users, and mint the ticket that '
                       'signs them in.',
                       'Znajdź, powiąż albo utwórz konto stojące za waszym użytkownikiem i wydaj bilet, który go '
                       'zaloguje.'),
    'ep_auth_logout': ('They signed out on your side. Ends their bridged session here.',
                       'Wylogował się u was. Kończy jego mostkowaną sesję tutaj.'),
    'ep_auth_verify': ('Redeem a <code>thx_token</code> the tracker sent you, and learn whose it is.',
                       'Wykorzystaj <code>thx_token</code>, który przysłał tracker, i dowiedz się, czyj jest.'),
    'ep_auth_merge':  ('Attach your user to an account that already exists here, or detach them. Never guesses '
                       '— it takes an exact account.',
                       'Przypnij waszego użytkownika do konta, które już tu istnieje, albo odepnij. Nigdy nie '
                       'zgaduje — bierze konkretne konto.'),
    'ep_auth_status': ('What the tracker currently thinks: linked or not, when they last came through, whether '
                       'they signed out here.',
                       'Co tracker sądzi w tej chwili: powiązany czy nie, kiedy ostatnio przyszedł, czy wylogował '
                       'się tutaj.'),

    # ── the other two scopes: a map, not a manual ────────────────────────────
    'h_users':   ('Accounts', 'Konta'),
    'users_intro': ('Your key can also work with accounts directly. Every one of these is a POST with a JSON '
                    'body; the reply always carries <code>ok</code>.',
                    'Wasz klucz może też pracować bezpośrednio na kontach. Każde z tych wywołań to POST z ciałem '
                    'JSON; odpowiedź zawsze niesie <code>ok</code>.'),
    'ep_users_lookup':    ('Is there an account with this username or address?',
                           'Czy istnieje konto o tej nazwie albo adresie?'),
    'ep_users_provision': ('Create one. The password is returned once when you do not supply one.',
                           'Utwórz je. Hasło wraca raz, jeśli sami go nie podacie.'),
    'ep_users_grant':     ('Put an account in a group, permanently or until a date.',
                           'Wstaw konto do grupy, na stałe albo do daty.'),
    'ep_users_revoke':    ('Take it back out.', 'Wyjmij je z powrotem.'),
    'h_fed':     ('Federation', 'Federacja'),
    'fed_intro': ('Your key exchanges hash lists with this tracker rather than submitting to it.',
                  'Wasz klucz wymienia z tym trackerem listy hashy, zamiast do niego zgłaszać.'),
    'ep_fed_ping':   ('Prove the two sides can reach each other and agree on who is who.',
                      'Sprawdźcie, że obie strony się widzą i zgadzają co do tego, kto jest kim.'),
    'ep_fed_export': ('Take the list this tracker is willing to share with you.',
                      'Weźcie listę, którą ten tracker chce się z wami dzielić.'),

    'h_curl':    ('One command to check it works', 'Jedno polecenie, żeby sprawdzić, że działa'),
    'foot':      ('If something here does not match what you get back, trust the reply and tell the operator — this page describes the configuration, and the reply is the configuration doing its job.',
                  'Jeśli coś tutaj nie zgadza się z tym, co dostajesz, wierz odpowiedzi i powiedz operatorowi — ta strona opisuje konfigurację, a odpowiedź jest tą konfiguracją w działaniu.'),
})

# ── the review queue in the panel ───────────────────────────────────────────
add('a.wl', {
    'review_head':     ('Waiting for review', 'Czeka na przegląd'),
    'review_filter':   ('Review', 'Przegląd'),
    'review_any':      ('Any', 'Dowolny'),
    'review_pending':  ('Waiting', 'Czeka'),
    'review_approved': ('Approved', 'Zatwierdzone'),
    'review_rejected': ('Turned down', 'Odrzucone'),
    'review_none':     ('Not reviewed (published directly)', 'Bez przeglądu (opublikowane wprost)'),
    'review_by':       ('Submitted by', 'Zgłoszone przez'),
    'approve':         ('Approve', 'Zatwierdź'),
    'reject':          ('Turn down', 'Odrzuć'),
})

add('js.wl', {
    'review_pending':  ('Waiting for review', 'Czeka na przegląd'),
    'review_approved': ('Approved', 'Zatwierdzone'),
    'review_rejected': ('Turned down', 'Odrzucone'),
    # `approve` and `reject` already exist in js.py for the DESCRIPTION queue, which is a different
    # decision about a different thing — these two are about whether the tracker serves the torrent.
    'review_approve':  ('Approve', 'Zatwierdź'),
    'review_reject':   ('Turn down', 'Odrzuć'),
    'approved_n':      ('Approved :n', 'Zatwierdzono :n'),
    'rejected_n':      ('Turned down :n', 'Odrzucono :n'),
    'partner':         ('via :name', 'przez :name'),
    'lbl_review':      ('Review', 'Przegląd'),
    # Duplicated from a.wl above ON PURPOSE. The panel's JS bundle carries every `js.` string and
    # nothing else (langJsBundle), so a script reaching for an `a.` key renders the key itself. The
    # rule in includes/lang.php is to duplicate rather than widen the bundle.
    'cl_opts_create':  ('New API key', 'Nowy klucz API'),
    'cl_opts_edit':    ('API key settings', 'Ustawienia klucza API'),
    'cl_save':         ('Save', 'Zapisz'),
    'cl_settings':     ('Settings', 'Ustawienia'),
    'cl_docs_copy':    ('Copy the guide link', 'Kopiuj link do instrukcji'),
    'cl_approve_rev':  ('Hold for review', 'Wstrzymaj do przeglądu'),
    'cl_scope_hint':   ('What the key may call. A key handed to somebody else should be the narrowest '
                        'scope that still does their job.',
                        'Co klucz może wywoływać. Klucz przekazany komuś innemu powinien mieć najwęższy '
                        'zakres, który wciąż wystarcza do jego zadania.'),
    'cl_scope_locked': ('The scope is fixed when the key is made — a key whose reach can change is a key '
                        'nobody can reason about. Make a new one instead.',
                        'Zakres ustala się przy tworzeniu klucza — klucz, którego zasięg może się zmienić, to '
                        'klucz, o którym nikt nie potrafi nic pewnego powiedzieć. Zrób raczej nowy.'),
})

# ── the key editor in the whitelist panel ───────────────────────────────────
add('a.wl', {
    'cl_opts_create':  ('New API key', 'Nowy klucz API'),
    'cl_opts_edit':    ('API key settings', 'Ustawienia klucza API'),
    'cl_label':        ('Who this key is for', 'Dla kogo jest ten klucz'),
    'cl_label_ph':     ('Partner forum, mirror bot…', 'Forum partnera, bot mirrora…'),
    'cl_scope_hint':   ('What the key may call. A key handed to somebody else should be the narrowest '
                        'scope that still does their job.',
                        'Co klucz może wywoływać. Klucz przekazany komuś innemu powinien mieć najwęższy '
                        'zakres, który wciąż wystarcza do jego zadania.'),
    'cl_scope_locked': ('The scope is fixed when the key is made — a key whose reach can change is a key '
                        'nobody can reason about. Make a new one instead.',
                        'Zakres ustala się przy tworzeniu klucza — klucz, którego zasięg może się zmienić, to '
                        'klucz, o którym nikt nie potrafi nic pewnego powiedzieć. Zrób raczej nowy.'),
    'cl_approve':      ('What happens to what they send', 'Co się dzieje z tym, co przyślą'),
    'cl_approve_auto': ('Publish immediately', 'Publikuj od razu'),
    'cl_approve_rev':  ('Hold for review', 'Wstrzymaj do przeglądu'),
    'cl_approve_hint': ('Held submissions land in the whitelist with <em>Waiting</em> against them and are '
                        'not served by the tracker until somebody approves them.',
                        'Wstrzymane zgłoszenia trafiają do whitelisty ze statusem <em>Czeka</em> i tracker ich '
                        'nie serwuje, dopóki ktoś ich nie zatwierdzi.'),
    'cl_fields':       ('Require with every item', 'Wymagaj przy każdej pozycji'),
    'cl_f_name':       ('Title', 'Tytuł'),
    'cl_f_url':        ('Link back to the post', 'Link do wpisu'),
    'cl_f_source_id':  ('Their own post id', 'Ich własne id wpisu'),
    'cl_fields_hint':  ('An item missing one of these is refused on its own, with the reason — the rest of '
                        'the batch still goes through.',
                        'Pozycja bez którejś z nich jest odrzucana osobno, z podaniem powodu — reszta paczki '
                        'i tak przechodzi.'),
    'cl_docs':         ('Send them this guide', 'Wyślij im tę instrukcję'),
    'cl_docs_hint':    ('The address changes with the choices above and describes exactly this key. It '
                        'carries no secret, so it can travel in the same mail as the key.',
                        'Adres zmienia się razem z wyborami powyżej i opisuje dokładnie ten klucz. Nie niesie '
                        'żadnego sekretu, więc może jechać w tym samym mailu co klucz.'),
    'cl_docs_copy':    ('Copy the guide link', 'Kopiuj link do instrukcji'),
    'cl_docs_open':    ('Open the guide', 'Otwórz instrukcję'),
    'cl_settings':     ('Settings', 'Ustawienia'),
    'cl_save':         ('Save', 'Zapisz'),
})

add('js.wl', {
    'cl_saved':        ('Key settings saved', 'Zapisano ustawienia klucza'),
})

add('settings', {
    'api_auto_approve':      ('Approval', 'Zatwierdzanie'),
    'api_auto_approve_auto': ('Publish immediately', 'Publikuj od razu'),
    'api_auto_approve_review': ('Hold for review', 'Wstrzymaj do przeglądu'),
    'api_required_fields':   ('Require with every item', 'Wymagaj przy każdej pozycji'),
    'api_docs_link':         ('Integration guide for this key', 'Instrukcja integracji dla tego klucza'),
    'api_docs_copy':         ('Copy the guide link', 'Kopiuj link do instrukcji'),
})
