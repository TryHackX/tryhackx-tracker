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


add('title', {
    # The browser tab of ?action=apidocs (templates/layout.php reads title.<action>; without one the tab said "Home").
    'apidocs':   ('Integration guide', 'Instrukcja integracji'),
})

add('apidocs', {
    'h1':        ('Integration guide', 'Instrukcja integracji'),
    'api_off':   ('The API is switched off on this tracker right now. Requests will be refused until the operator turns it back on.',
                  'API jest w tej chwili wyłączone na tym trackerze. Żądania będą odrzucane, dopóki operator go nie włączy.'),
    'intro':     ('This page describes <strong>your</strong> key. The address you were sent carries the answers the operator chose for it, so what is written below is what your key will actually do — not one page covering every possibility. Your key itself is not on this page; it came in the same message, from a person.',
                  'Ta strona opisuje <strong>twój</strong> klucz. Adres, który dostałeś, niesie odpowiedzi wybrane dla niego przez operatora, więc to, co jest niżej, jest tym, co twój klucz naprawdę zrobi — a nie jedną stroną o wszystkich możliwościach. Samego klucza tu nie ma; przyszedł w tej samej wiadomości, od człowieka.'),
    'k_endpoint': ('Endpoint', 'Endpoint'),
    'k_approval': ('Approval', 'Zatwierdzanie'),
    'k_required': ('Required with every item', 'Wymagane przy każdej pozycji'),
    'k_required_report': ('Required with every report', 'Wymagane przy każdym zgłoszeniu'),
    'approve_auto':   ('Automatic — accepted hashes are served immediately',
                       'Automatyczne — przyjęte hashe są serwowane od razu'),
    'approve_review': ('Reviewed — a person looks at every submission before the tracker serves it',
                       'Z przeglądem — człowiek ogląda każde zgłoszenie, zanim tracker zacznie je serwować'),
    'required_none':  ('nothing beyond the hash', 'nic poza hashem'),
    'h_auth':    ('Authentication', 'Uwierzytelnianie'),
    'auth_body': ('Two headers on every request. The bearer is your key id (16 hex characters) and your secret (64 hex characters) joined by a dot. Send it over HTTPS and store it the way you store a password — anybody holding it can do everything your key can.',
                  'Dwa nagłówki przy każdym żądaniu. Bearer to identyfikator klucza (16 znaków szesnastkowych) i sekret (64 znaki szesnastkowe) połączone kropką. Wysyłaj po HTTPS i przechowuj jak hasło — kto go ma, może wszystko to, co twój klucz.'),
    'auth_ban':  ('<strong>A wrong key bans the address it came from.</strong> A malformed, unknown or wrong key is answered with <code>403</code> and the address it came from (IPv4, or the IPv6 /64) is banned for :days: from then on every request from there is refused, even with the right key, until the operator lifts it. A request with no <code>Authorization</code> header at all gets <code>401</code> and is not banned. So test with the right key, and never retry a <code>403</code> in a loop.',
                  '<strong>Zły klucz banuje adres, z którego przyszedł.</strong> Na klucz o błędnym formacie, nieznany albo zły odpowiedzią jest <code>403</code>, a adres, z którego przyszedł (IPv4 albo /64 IPv6), zostaje zbanowany na :days: od tej chwili każde żądanie stamtąd jest odrzucane, nawet z dobrym kluczem, dopóki operator nie zdejmie bana. Żądanie w ogóle bez nagłówka <code>Authorization</code> dostaje <code>401</code> i nie jest banowane. Dlatego testuj z dobrym kluczem i nigdy nie ponawiaj <code>403</code> w pętli.'),
    'ep_wl_submit': ('Register torrents: a batch of magnet links or info hashes.',
                     'Rejestracja torrentów: paczka linków magnet albo info hashy.'),
    'ep_wl_status': ('Ask what became of hashes you sent — or of any hash.',
                     'Pytanie, co się stało z wysłanymi hashami — albo z dowolnym hashem.'),
    'ep_wl_ping':   ('Check that the key works. Changes nothing.', 'Sprawdzenie, czy klucz działa. Niczego nie zmienia.'),
    'h_request': ('The request', 'Żądanie'),
    'request_body': ('One POST, one JSON body, any number of items up to the batch limit. An item is a magnet link or an info hash — 40 hex characters or 32 in base32 — given as a bare string, as <code>{"magnet": …}</code> or as <code>{"hash": …}</code>; an object may add a <code>name</code> and a <code>ref</code> (<code>post_id</code>, <code>discussion_id</code>, a <code>url</code> back to your post).',
                     'Jeden POST, jedno ciało JSON, dowolnie wiele pozycji do limitu paczki. Pozycja to link magnet albo info hash — 40 znaków szesnastkowych albo 32 w base32 — podany jako sam napis, jako <code>{"magnet": …}</code> albo jako <code>{"hash": …}</code>; obiekt może dodać <code>name</code> i <code>ref</code> (<code>post_id</code>, <code>discussion_id</code>, <code>url</code> twojego wpisu).'),
    'required_note': ('Your key requires <code>:fields</code> on every item. An item without them is refused as <code>invalid</code> and named in the reply — it is not registered and then hidden, it is not registered at all.',
                      'Twój klucz wymaga <code>:fields</code> przy każdej pozycji. Pozycja bez nich jest odrzucana jako <code>invalid</code> i nazwana w odpowiedzi — nie jest rejestrowana i ukrywana, tylko w ogóle nie jest rejestrowana.'),
    'h_reply':   ('The reply', 'Odpowiedź'),
    'reply_auto': ('One result per item, in the order you sent them, plus a summary. <code>added</code> means it is registered; the tracker serves it after its next reload of the list, which <code>active_in_seconds</code> says is due.',
                   'Jeden wynik na pozycję, w kolejności wysyłki, plus podsumowanie. <code>added</code> znaczy, że hash jest zarejestrowany; tracker zacznie go serwować po najbliższym przeładowaniu listy, a <code>active_in_seconds</code> mówi, kiedy ono nastąpi.'),
    'mode_note': ('<code>mode</code> is the tracker\'s mode right now. Only in <code>whitelist</code> mode does registration decide what is served — and only then does <code>pending</code> or a rejection hold a hash back; in <code>blacklist</code> mode the tracker serves every torrent anyway, and a registration counts again once it is back in whitelist mode (during a schedule\'s whitelist hours, for example).',
                  '<code>mode</code> to obecny tryb trackera. Tylko w trybie <code>whitelist</code> rejestracja decyduje o tym, co jest serwowane — i tylko wtedy <code>pending</code> albo odrzucenie wstrzymuje hash; w trybie <code>blacklist</code> tracker i tak serwuje każdy torrent, a rejestracja znów się liczy, gdy tracker wróci do trybu whitelisty (na przykład w godzinach whitelisty według harmonogramu).'),
    'reply_review': ('One result per item, in the order you sent them, plus a summary. Your key goes through review, so a new hash comes back as <code>pending</code>: the row exists, and the tracker will not serve it until somebody here approves it. Do not show it to your users as live.',
                     'Jeden wynik na pozycję, w kolejności wysyłki, plus podsumowanie. Twój klucz przechodzi przez przegląd, więc nowy hash wraca jako <code>pending</code>: wiersz istnieje, ale tracker nie będzie go serwował, dopóki ktoś tutaj go nie zatwierdzi. Nie pokazuj tego swoim użytkownikom jako działającego.'),
    'h_status':  ('What each status means', 'Co znaczy każdy status'),
    'col_status': ('Status', 'Status'),
    'col_means': ('Meaning', 'Znaczenie'),
    'st_added':  ('Registered. Served from the tracker\'s next reload of the list (<code>active_in_seconds</code>).',
                  'Zarejestrowane. Serwowane od najbliższego przeładowania listy przez tracker (<code>active_in_seconds</code>).'),
    'st_pending': ('Registered and waiting for a person. Not served yet.', 'Zarejestrowane i czeka na człowieka. Jeszcze nie serwowane.'),
    'st_exists': ('This hash is already here — registered, still waiting for review, or turned down. Nothing changed, and this is not an error; <code>v1/whitelist/status</code> says which.',
                  'Ten hash już tu jest — zarejestrowany, wciąż czeka na przegląd albo został odrzucony. Nic się nie zmieniło i to nie jest błąd; <code>v1/whitelist/status</code> powie, które z nich.'),
    'st_banned': ('Blocked on this tracker. It will not be served whoever sends it.',
                  'Zablokowane na tym trackerze. Nie będzie serwowane, kto by go nie wysłał.'),
    'st_invalid': ('Not a usable magnet or hash, or missing a field your key requires. The reason is in <code>error</code>.',
                   'To nie jest poprawny link magnet ani hash albo brakuje pola wymaganego przez twój klucz. Powód jest w <code>error</code>.'),
    'h_rules':   ('Rules worth knowing before you write the client', 'Zasady, które warto znać przed napisaniem klienta'),
    'rule_idempotent': ('<strong>Idempotent.</strong> Sending the same hash again is safe and answers <code>exists</code>. You do not need to remember what you have already sent.',
                        '<strong>Idempotentne.</strong> Ponowne wysłanie tego samego hasha jest bezpieczne i daje <code>exists</code>. Nie musisz pamiętać, co już wysłałeś.'),
    'rule_additive':   ('<strong>Additive only.</strong> There is deliberately no remove endpoint — a key that can register cannot unregister, so a compromised key cannot empty the tracker.',
                        '<strong>Tylko dodawanie.</strong> Celowo nie ma endpointu usuwania — klucz, który rejestruje, nie może wyrejestrować, więc przejęty klucz nie opróżni trackera.'),
    'rule_batch':      ('<strong>Batch.</strong> Up to :n items per request. A larger batch is refused whole (<code>422</code> <code>too_many_items</code>) rather than truncated, so you never have to guess which half arrived.',
                        '<strong>Paczka.</strong> Do :n pozycji na żądanie. Większa jest odrzucana w całości (<code>422</code> <code>too_many_items</code>), a nie ucinana — nigdy nie musisz zgadywać, która połowa doszła.'),
    'rule_limits':     ('<strong>Rate limits.</strong> Per key: :per_min requests a minute and :bytes a day of traffic (the federation export counts what it sends back). Over either you get <code>429</code> <code>rate_limited</code> with <code>retry_after</code> and a <code>Retry-After</code> header — never a ban.',
                        '<strong>Limity tempa.</strong> Na klucz: limit żądań na minutę wynosi :per_min, a limit ruchu na dobę :bytes (eksport federacji liczy to, co odsyła). Po przekroczeniu któregoś dostajesz <code>429</code> <code>rate_limited</code> z <code>retry_after</code> i nagłówkiem <code>Retry-After</code> — nigdy bana.'),
    'rule_limits_off': ('<strong>Rate limits.</strong> None are set on this tracker right now.',
                        '<strong>Limity tempa.</strong> Ten tracker nie ma teraz żadnych.'),
    'rule_errors':     ('<strong>Errors.</strong> <code>401</code> no <code>Authorization</code> header; <code>403</code> a wrong key (which also bans the address for :days — see above), a disabled key, or a key without this endpoint\'s scope (<code>detail</code> says which); <code>404</code> no such endpoint, or nothing found; <code>405</code> the wrong method; <code>409</code> a conflict the reply names; <code>413</code> a body over 512 KB; <code>422</code> the JSON is not what this endpoint takes; <code>429</code> a rate limit; <code>503</code> the API — or what this endpoint needs, accounts or the sign-in bridge — is switched off. A per-item problem is never a 4xx — it is a <code>status</code> on that item.',
                        '<strong>Błędy.</strong> <code>401</code> brak nagłówka <code>Authorization</code>; <code>403</code> zły klucz (co dodatkowo banuje adres na :days — patrz wyżej), klucz wyłączony albo klucz bez zakresu tego endpointu (<code>detail</code> mówi, który z tych przypadków zachodzi); <code>404</code> nie ma takiego endpointu albo niczego nie znaleziono; <code>405</code> zła metoda; <code>409</code> konflikt nazwany w odpowiedzi; <code>413</code> ciało ponad 512 KB; <code>422</code> JSON nie w tym kształcie; <code>429</code> limit tempa; <code>503</code> API — albo to, czego ten endpoint potrzebuje: konta lub mostek logowania — jest wyłączone. Problem pojedynczej pozycji nigdy nie jest 4xx — jest statusem przy tej pozycji.'),
    'col_endpoint': ('Endpoint', 'Endpoint'),

    # ── the sign-in bridge ───────────────────────────────────────────────────
    'h_bridge':  ('Signing your members in here', 'Logowanie tutaj waszych użytkowników'),
    'bridge_intro': ('Your key can sign your people in on this tracker without them registering a second time. '
                     'Two of the four steps happen in the browser, so build it in this order:',
                     'Wasz klucz może zalogować waszych ludzi na tym trackerze bez zakładania konta drugi raz. '
                     'Dwa z czterech kroków dzieją się w przeglądarce, więc budujcie to w tej kolejności:'),
    'bridge_step1': ('Somebody signed in on your side clicks through to the tracker. Your <em>server</em> posts '
                     'their id to <code>v1/auth/login</code>.',
                     'Ktoś zalogowany u was klika link do trackera. Wasz <em>serwer</em> wysyła jego id na '
                     '<code>v1/auth/login</code>.'),
    'bridge_step2': ('The reply carries a one-time <code>handoff.url</code>. Redirect the browser to it — that '
                     'is the only way a session cookie for this site can be created.',
                     'Odpowiedź niesie jednorazowy <code>handoff.url</code>. Przekierujcie tam przeglądarkę — '
                     'tylko tak może powstać ciasteczko sesji dla tej strony.'),
    'bridge_step3': ('The ticket is spent on arrival, lives :ttl s here (<code>handoff.expires_in</code> '
                     'says so), and works exactly once. Mint it when the person clicks, never in advance. Add '
                     '<code>&amp;next=/path</code> to the address to land them on a page of this site.',
                     'Bilet zużywa się przy wejściu, żyje tutaj :ttl s (mówi o tym <code>handoff.expires_in</code>) '
                     'i działa dokładnie raz. Twórzcie go w chwili kliknięcia, nigdy wcześniej. Dopiszcie '
                     '<code>&amp;next=/ścieżka</code> do adresu, żeby użytkownik trafił na konkretną stronę tego '
                     'trackera.'),
    'bridge_off':   ('The sign-in bridge is switched off on this tracker right now, so the <code>v1/auth/*</code> '
                     'endpoints answer <code>503</code> <code>bridge_disabled</code>.',
                     'Mostek logowania jest w tej chwili wyłączony na tym trackerze, więc endpointy <code>v1/auth/*</code> '
                     'odpowiadają <code>503</code> <code>bridge_disabled</code>.'),
    'bridge_login_rules': ('<code>v1/auth/login</code> finds the account linked to your <code>external_id</code>; '
                           'failing that it may link an existing account with the same verified address, or create '
                           'one — as the operator allows. A name already taken here gets a number added, and an account '
                           'it creates has no confirmed address, so where this site requires one it has a guest\'s rights '
                           'until the address is confirmed. An address '
                           'that already has an account here, which the operator does not let the bridge claim, is '
                           '<code>409</code> <code>create_failed:email_taken</code>: link that account with '
                           '<code>v1/auth/merge</code>. <code>403</code> <code>no_account</code> means the bridge may '
                           'not create one, <code>account_suspended</code> that the account here is suspended.',
                           '<code>v1/auth/login</code> znajduje konto powiązane z waszym <code>external_id</code>; '
                           'jeśli takiego nie ma, może powiązać istniejące konto z tym samym potwierdzonym adresem albo '
                           'utworzyć nowe — na tyle, na ile pozwala operator. Nazwa zajęta tutaj dostaje dopisany numer, '
                           'a utworzone przez niego konto nie ma potwierdzonego adresu, więc tam, gdzie ta strona tego '
                           'wymaga, ma uprawnienia gościa, dopóki adres nie zostanie potwierdzony. '
                           'Adres, który ma już tu konto, a którego operator nie pozwala mostkowi przejąć, to '
                           '<code>409</code> <code>create_failed:email_taken</code>: powiążcie to konto przez '
                           '<code>v1/auth/merge</code>. <code>403</code> <code>no_account</code> znaczy, że mostek nie '
                           'może utworzyć konta, a <code>account_suspended</code>, że konto tutaj jest zawieszone.'),
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
                       'żeby dowiedzieć się, kto to jest. Token jedzie w przekierowaniu; wykorzystać go może tylko '
                       'wasz klucz.'),
    'bridge_warning': ('<strong>Never put the bearer key in a browser.</strong> Every call here is made by your '
                       'server. What reaches the browser is a ticket that is worthless soon after it is minted, '
                       'and once it has been used — that difference is the whole security of this design.',
                       '<strong>Nigdy nie umieszczajcie klucza bearer w przeglądarce.</strong> Każde wywołanie '
                       'tutaj robi wasz serwer. Do przeglądarki trafia bilet, który po krótkim czasie albo po '
                       'pierwszym użyciu jest już bezwartościowy — na tej różnicy stoi całe bezpieczeństwo tego '
                       'rozwiązania.'),
    'ep_auth_login':  ('Find, link or create the account behind one of your users, and mint the ticket that '
                       'signs them in.',
                       'Znajdź, powiąż albo utwórz konto stojące za twoim użytkownikiem i wydaj bilet, który go '
                       'zaloguje.'),
    'ep_auth_logout': ('They signed out on your side. Ends their bridged session here.',
                       'Wylogował się u was. Kończy jego mostkowaną sesję tutaj.'),
    'ep_auth_verify': ('Redeem a <code>thx_token</code> the tracker sent you, and learn whose it is.',
                       'Wykorzystaj <code>thx_token</code>, który przysłał tracker, i dowiedz się, czyj jest.'),
    'ep_auth_merge':  ('Attach your user to an account that already exists here, or detach them. Never guesses '
                       '— it takes an exact account.',
                       'Przypnij swojego użytkownika do konta, które już tu istnieje, albo go odepnij. Nigdy nie '
                       'zgaduje — bierze konkretne konto.'),
    'ep_auth_status': ('What the tracker currently thinks: linked or not, when they last came through, and when '
                       'the link was last signed out — by your logout call or here.',
                       'Co tracker sądzi w tej chwili: powiązany czy nie, kiedy ostatnio przyszedł i kiedy '
                       'powiązanie ostatnio się wylogowało — waszym wywołaniem logout albo tutaj.'),

    # ── the other two scopes: a map, not a manual ────────────────────────────
    'h_users':   ('Accounts', 'Konta'),
    'users_intro': ('Your key can also work with accounts directly. Every one of these is a POST with a JSON '
                    'body; a reply that went through carries <code>"ok": true</code>, a refusal an '
                    '<code>error</code>.',
                    'Wasz klucz może też pracować bezpośrednio na kontach. Każde z tych wywołań to POST z ciałem '
                    'JSON; odpowiedź, która przeszła, niesie <code>"ok": true</code>, a odmowa — <code>error</code>.'),
    'ep_users_lookup':    ('Is there an account with this username or address — and would a grant take effect on '
                           'it (<code>effective</code>, <code>reason</code>)? Not found is <code>"found": false</code>, '
                           'not an error.',
                           'Czy istnieje konto o tej nazwie albo adresie — i czy nadanie by na nim zadziałało '
                           '(<code>effective</code>, <code>reason</code>)? Brak konta to <code>"found": false</code>, '
                           'a nie błąd.'),
    'users_identity': ('Every call names the account with exactly one of <code>login</code> (a username or an '
                       'e-mail address), <code>user_id</code>, or <code>external_id</code> — the last only for a key '
                       'that also runs the sign-in bridge, because it names a link that key made. None is '
                       '<code>422</code> <code>login_required</code>, more than one <code>identity_ambiguous</code>.',
                       'Każde wywołanie wskazuje konto dokładnie jednym z pól: <code>login</code> (nazwa użytkownika '
                       'albo adres e-mail), <code>user_id</code> albo <code>external_id</code> — to ostatnie tylko '
                       'przy kluczu, który obsługuje też mostek logowania, bo wskazuje powiązanie utworzone przez ten '
                       'klucz. Brak wszystkich to <code>422</code> <code>login_required</code>, więcej niż jedno — '
                       '<code>identity_ambiguous</code>.'),
    'users_provision_note': ('<code>v1/users/provision</code> is not idempotent: a retry after a lost reply gets '
                             '<code>409</code> <code>username_taken</code>, and a password the tracker generated is '
                             'gone with the lost reply — send your own <code>password</code> if you may need to retry.',
                             '<code>v1/users/provision</code> nie jest idempotentne: ponowienie po zgubionej '
                             'odpowiedzi dostaje <code>409</code> <code>username_taken</code>, a hasło wygenerowane '
                             'przez tracker przepada razem z tą odpowiedzią — wysyłajcie własne <code>password</code>, '
                             'jeśli możecie potrzebować ponowienia.'),
    'users_off':  ('Accounts are switched off on this tracker right now, so these endpoints answer <code>503</code> '
                   '<code>users_disabled</code>.',
                   'Konta są w tej chwili wyłączone na tym trackerze, więc te endpointy odpowiadają <code>503</code> '
                   '<code>users_disabled</code>.'),
    'ep_users_provision': ('Create one. The password is returned once when you do not supply one.',
                           'Utwórz je. Hasło wraca raz, jeśli sam go nie podasz.'),
    'ep_users_grant':     ('Put an account in a group, permanently or until a date. With an <code>order_id</code> it is safe to repeat.',
                           'Wstaw konto do grupy, na stałe albo do daty. Z <code>order_id</code> to wywołanie można bezpiecznie powtarzać.'),
    'ep_users_revoke':    ('Take it back out — the whole membership, or just the time one order paid for.',
                           'Wyjmij je z powrotem — całe członkostwo albo tylko czas opłacony jednym zamówieniem.'),

    # ── selling a group: the chapter a shop integrator actually needs ────────
    # Written as the order things happen in, because that is how somebody builds a webhook — and the
    # retry paragraph is the point of the whole chapter.
    'h_shop':    ('Selling a group', 'Sprzedawanie grupy'),
    'shop_intro': ('A shop that sells a membership here needs three calls and one habit: <strong>send your own order id with every grant</strong>. '
                   'With it, a webhook that fires twice — a dropped connection, a redelivered queue message — sells one month instead of two.',
                   'Sklep, który sprzedaje tu członkostwo, potrzebuje trzech wywołań i jednego nawyku: <strong>przy każdym nadaniu wysyłajcie własne id zamówienia</strong>. '
                   'Dzięki niemu webhook wywołany dwa razy — zerwane połączenie, ponownie dostarczona wiadomość z kolejki — sprzedaje jeden miesiąc, a nie dwa.'),
    'shop_step1': ('Ask the operator for a key with the <code>shop</code> scope. It opens exactly three endpoints: lookup, grant and revoke.',
                   'Poproście operatora o klucz z zakresem <code>shop</code>. Otwiera dokładnie trzy endpointy: lookup, grant i revoke.'),
    'shop_step2': ('Find the buyer with <code>v1/users/lookup</code>. <code>login</code> takes a username or an e-mail address, and <code>user_id</code> the number the lookup returned, if you stored it at the first sale; send exactly one of the two. (<code>external_id</code> works only for a key that also runs the sign-in bridge — a shop key uses these two.)',
                   'Znajdźcie kupującego przez <code>v1/users/lookup</code>. <code>login</code> przyjmuje nazwę użytkownika albo adres e-mail, a <code>user_id</code> numer zwrócony przez lookup, jeśli zapisaliście go przy pierwszej sprzedaży; wyślijcie dokładnie jedno z dwóch. (<code>external_id</code> działa tylko przy kluczu, który obsługuje też mostek logowania — klucz sklepu używa tych dwóch.)'),
    'shop_step3': ('Grant the group with your order id. <code>duration</code> is one of <code>1d</code>, <code>7d</code>, <code>14d</code>, <code>1m</code>, <code>3m</code>, <code>6m</code>, <code>1y</code> or <code>permanent</code> and extends what is already there (from the later of today and the current end date); <code>until</code> — a date in the future, which means the end of that day — replaces it instead. A grant with neither is <strong>permanent</strong>, and a duration never shortens a permanent membership. <code>order_id</code> is 1–64 printable characters, remembered per key; <code>"email": true</code> also mails the member.',
                   'Nadajcie grupę ze swoim id zamówienia. <code>duration</code> to jedno z: <code>1d</code>, <code>7d</code>, <code>14d</code>, <code>1m</code>, <code>3m</code>, <code>6m</code>, <code>1y</code> albo <code>permanent</code> i przedłuża to, co już jest (od późniejszej z dwóch dat: dzisiejszej i obecnego końca); <code>until</code> — data w przyszłości, oznaczająca koniec tego dnia — zamiast tego je zastępuje. Nadanie bez żadnego z nich jest <strong>bezterminowe</strong>, a duration nigdy nie skraca członkostwa bezterminowego. <code>order_id</code> to 1–64 drukowalne znaki, zapamiętywane dla klucza; <code>"email": true</code> wysyła też maila do członka.'),
    'shop_errors': ('What can go wrong: <code>404</code> <code>user_not_found</code> or <code>group_not_found</code>; <code>group_not_grantable</code> for the guest group and for any group that opens the admin panel; <code>422</code> <code>invalid_duration</code> (with the allowed list), <code>invalid_until</code> or <code>invalid_order_id</code>; <code>409</code> <code>would_shorten_permanent</code>. On revoke: <code>404</code> <code>order_not_found</code>, <code>409</code> <code>order_mismatch</code> (that order was for another account or group); an order already refunded answers <code>"replayed": true</code>, and a refunded <code>order_id</code> never grants again.',
                    'Co może pójść nie tak: <code>404</code> <code>user_not_found</code> albo <code>group_not_found</code>; <code>group_not_grantable</code> dla grupy gości i każdej grupy otwierającej panel administracyjny; <code>422</code> <code>invalid_duration</code> (z listą dozwolonych), <code>invalid_until</code> albo <code>invalid_order_id</code>; <code>409</code> <code>would_shorten_permanent</code>. Przy revoke: <code>404</code> <code>order_not_found</code>, <code>409</code> <code>order_mismatch</code> (to zamówienie dotyczyło innego konta albo grupy); zamówienie już zwrócone odpowiada <code>"replayed": true</code>, a zwrócone <code>order_id</code> nigdy więcej niczego nie nadaje.'),
    'shop_step4': ('On a refund, send the same order id to <code>v1/users/revoke</code>. Only that order\'s time is taken back — a second, later purchase keeps its own.',
                   'Przy zwrocie wyślijcie to samo id zamówienia do <code>v1/users/revoke</code>. Cofnięty zostanie tylko czas z tego zamówienia — druga, późniejsza zakupiona porcja zostaje.'),
    'shop_retry': ('A repeated <code>order_id</code> changes nothing and returns the stored answer with <code>"replayed": true</code>. '
                   'The dates in it are the ones from the first call, so your customer sees the same end date however many times the webhook fired.',
                   'Powtórzone <code>order_id</code> nic nie zmienia i zwraca zapisaną odpowiedź z <code>"replayed": true</code>. '
                   'Daty w niej są tymi z pierwszego wywołania, więc klient widzi tę samą datę końca niezależnie od tego, ile razy webhook zadziałał.'),
    'shop_effective': ('<code>effective: false</code> means the grant was recorded but does nothing yet, and <code>reason</code> says why: '
                       '<code>email_unverified</code> (this site requires a confirmed address, and theirs is not) or <code>banned</code>. '
                       'Tell the customer — the membership starts working the moment they fix it, and nothing has to be bought again.',
                       '<code>effective: false</code> znaczy, że nadanie zostało zapisane, ale na razie nic nie daje, a <code>reason</code> mówi dlaczego: '
                       '<code>email_unverified</code> (ta strona wymaga potwierdzonego adresu, a adres klienta nie jest potwierdzony) albo <code>banned</code>. '
                       'Powiedzcie o tym klientowi — członkostwo zacznie działać, gdy tylko to naprawi, i niczego nie trzeba kupować drugi raz.'),
    'shop_permanent': ('An <code>until</code> that would cut short a membership somebody already has for ever is refused with <code>would_shorten_permanent</code>. Send <code>"force": true</code> if you really mean it.',
                       '<code>until</code>, które skróciłoby członkostwo posiadane już na zawsze, jest odrzucane z <code>would_shorten_permanent</code>. Wyślijcie <code>"force": true</code>, jeśli naprawdę o to chodzi.'),
    'shop_group': ('The group slug is the operator\'s choice — <code>premium</code> on a default install. A group that carries admin-panel access can never be granted this way.',
                   'Slug grupy wybiera operator — na domyślnej instalacji to <code>premium</code>. Grupy dającej dostęp do panelu administracyjnego nie da się nadać tą drogą.'),
    'h_fed':     ('Federation', 'Federacja'),
    'fed_intro': ('Your key pulls torrent metadata — names, sizes, file lists — from this tracker\'s index, page by page. Registered and banned hashes are never exported, and neither are the rows your own node gave this one.',
                  'Wasz klucz pobiera z indeksu tego trackera metadane torrentów — nazwy, rozmiary, listy plików — strona po stronie. Zarejestrowane i zbanowane hashe nigdy nie są eksportowane, podobnie jak wiersze, które ten tracker dostał od waszego węzła.'),
    'ep_fed_ping':   ('Prove the two sides can reach each other and agree on who is who. Changes nothing.',
                      'Sprawdzenie, czy obie strony się widzą i zgadzają co do tego, kto jest kim. Niczego nie zmienia.'),
    'ep_fed_export': ('One page of the index\'s resolved metadata, from a cursor.',
                      'Jedna strona rozwiązanych metadanych indeksu, od kursora.'),
    'fed_export_body': ('<code>since</code> is the cursor (a unix time; 0 starts from the beginning) with <code>after</code> as its tie-break, and the reply\'s <code>next</code> is the one to send next, <code>null</code> at the end (<code>has_more</code> says the same). <code>limit</code> is at most :max rows; <code>files</code> asks for the file lists where this tracker shares them; <code>gzip</code> compresses the reply; <code>"format": "ndjson"</code> streams one row per line with a trailer that carries the cursor. A row is <code>h</code> (the hash), <code>n</code> (the name), <code>s</code> (the size), <code>fc</code> (files), <code>pl</code> (piece length), <code>sl</code> (seeders, leechers), <code>seen</code> and, when asked for, <code>files</code>. The reply\'s size counts against your key\'s daily traffic.',
                        '<code>since</code> to kursor (czas uniksowy; 0 zaczyna od początku) z <code>after</code> jako rozstrzygnięciem remisu, a <code>next</code> z odpowiedzi to ten, który należy wysłać następnym razem, <code>null</code> na końcu (to samo mówi <code>has_more</code>). <code>limit</code> to liczba wierszy, najwyżej :max; <code>files</code> prosi o listy plików tam, gdzie ten tracker je udostępnia; <code>gzip</code> kompresuje odpowiedź; <code>"format": "ndjson"</code> strumieniuje jeden wiersz na linię, z zamknięciem niosącym kursor. Wiersz to <code>h</code> (hash), <code>n</code> (nazwa), <code>s</code> (rozmiar), <code>fc</code> (pliki), <code>pl</code> (długość kawałka), <code>sl</code> (seedy, leecherzy), <code>seen</code> i, na życzenie, <code>files</code>. Rozmiar odpowiedzi liczy się do dobowego ruchu waszego klucza.'),
    'fed_off':   ('Sharing the index is switched off on this tracker right now, so the export answers <code>403</code> <code>export_disabled</code>.',
                  'Udostępnianie indeksu jest w tej chwili wyłączone na tym trackerze, więc eksport odpowiada <code>403</code> <code>export_disabled</code>.'),

    'ck_invalid': ('Not a usable magnet or hash; <code>error</code> says why.',
                   'To nie jest poprawny link magnet ani hash; <code>error</code> mówi dlaczego.'),
    'h_ping':    ('Checking the key', 'Sprawdzenie klucza'),
    'ping_body': ('An authenticated call that changes nothing: it answers with the tracker\'s mode, how many torrents are registered and the name your key carries here. Use it for a "test connection" button.',
                  'Uwierzytelnione wywołanie, które niczego nie zmienia: odpowiada trybem trackera, liczbą zarejestrowanych torrentów i nazwą, pod jaką wasz klucz jest tu zapisany. Nadaje się na przycisk „sprawdź połączenie”.'),
    'abuse_fields': ('<code>reporter</code> (<code>name</code>, <code>representative</code>, <code>company</code>, <code>email</code>) and <code>statement</code> may be sent once for the whole batch, or on an item, where they win for that item; <code>title</code> (at most 255 characters), <code>evidence_url</code> (an http or https address) and <code>reason</code> belong to each item. Up to :n items per request.',
                     '<code>reporter</code> (<code>name</code>, <code>representative</code>, <code>company</code>, <code>email</code>) i <code>statement</code> można wysłać raz dla całej paczki albo przy pozycji, gdzie wygrywają dla tej pozycji; <code>title</code> (najwyżej 255 znaków), <code>evidence_url</code> (adres http albo https) i <code>reason</code> należą do każdej pozycji. Do :n pozycji na żądanie.'),
    'required_note_abuse': ('Your key requires <code>:fields</code> for every report — on the item, or for <code>reporter</code> and <code>statement</code> once for the batch. A report without them is refused as <code>invalid</code> and named in the reply.',
                            'Twój klucz wymaga <code>:fields</code> przy każdym zgłoszeniu — przy pozycji albo, w przypadku <code>reporter</code> i <code>statement</code>, raz dla paczki. Zgłoszenie bez nich jest odrzucane jako <code>invalid</code> i nazwane w odpowiedzi.'),
    'curl_note_ping': ('A reply with <code>"ok": true</code> means the key works; it changes nothing on the tracker.',
                       'Odpowiedź z <code>"ok": true</code> znaczy, że klucz działa; niczego nie zmienia na trackerze.'),
    'curl_note_lookup': ('A reply with <code>"ok": true</code> — found or not — means the key works; a lookup changes nothing.',
                         'Odpowiedź z <code>"ok": true</code> — znaleziono konto czy nie — znaczy, że klucz działa; lookup niczego nie zmienia.'),
    'curl_note_abuse': ('There is no harmless call for a reporting key, so this sends an empty batch: <code>422</code> <code>no_items</code> means the key and its scope were accepted and nothing was filed.',
                        'Dla klucza zgłoszeń nie ma nieszkodliwego wywołania, więc to polecenie wysyła pustą paczkę: <code>422</code> <code>no_items</code> znaczy, że klucz i jego zakres zostały przyjęte, a nic nie zostało zgłoszone.'),
    'h_curl':    ('One command to check it works', 'Jedno polecenie, żeby sprawdzić, czy działa'),
    # Number separators for the numbers this page quotes ("1,000" is one in Polish): templates/pages/apidocs.php.
    # Polish groups thousands with a space — a NO-BREAK one, so "1 000" never splits across two lines.
    'num_decimal':   ('.', ','),
    'num_thousands': (',', ' '),
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
    'cl_scope_wl':     ('whitelist — register torrents', 'whitelist — rejestrowanie torrentów'),
    'cl_scope_users':  ('users — accounts and the sign-in bridge', 'users — konta i mostek logowania'),
    'cl_scope_fed':    ('federation — exchange lists with another tracker',
                        'federation — wymiana list z innym trackerem'),
    'cl_scope_all':    ('all — everything above (use sparingly)', 'all — wszystko powyższe (używaj oszczędnie)'),
    'cl_can':          ('What this key may call', 'Co ten klucz może wywołać'),
    'cl_only_wl':      ('Approval and required fields apply to registering torrents, so they are only '
                        'asked for a key that can do it.',
                        'Zatwierdzanie i wymagane pola dotyczą rejestrowania torrentów, więc pytamy o nie '
                        'tylko przy kluczu, który to potrafi.'),
    'cl_bridge_on':    ('This key can sign the partner\'s members in here through the bridge. It is on.',
                        'Ten klucz może logować tutaj użytkowników partnera przez mostek. Mostek jest włączony.'),
    'cl_bridge_off':   ('The sign-in bridge is off, so the five v1/auth/* endpoints answer 503 for this '
                        'key. Turn it on in Settings → Sign-in bridge.',
                        'Mostek logowania jest wyłączony, więc pięć endpointów v1/auth/* odpowie temu '
                        'kluczowi 503. Włącz go w Ustawieniach → Mostek logowania.'),
    'cl_fed_note':     ('What this key may take is set once for every peer, in Settings → Federation.',
                        'Co ten klucz może pobrać, ustawia się raz dla wszystkich peerów, w Ustawieniach → Federacja.'),
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
    'n_waiting_review': (':n waiting for review', ':n czeka na przegląd'),
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

    # ── the scope, named by what it lets a key DO ───────────────────────────
    # "users" and "federation" are words from the code. On a screen where somebody is deciding how
    # much to trust a partner, the option has to say what it buys them.
    'cl_scope_wl':     ('whitelist — register torrents', 'whitelist — rejestrowanie torrentów'),
    'cl_scope_users':  ('users — accounts and the sign-in bridge', 'users — konta i mostek logowania'),
    'cl_scope_fed':    ('federation — exchange lists with another tracker',
                        'federation — wymiana list z innym trackerem'),
    'cl_scope_all':    ('all — everything above (use sparingly)', 'all — wszystko powyższe (używaj oszczędnie)'),
    'cl_can':          ('What this key may call', 'Co ten klucz może wywołać'),
    'cl_can_none':     ('Nothing — pick a scope.', 'Nic — wybierz zakres.'),

    'cl_f_hash':       ('The torrent itself', 'Sam torrent'),
    'cl_f_always':     ('always required', 'zawsze wymagane'),

    # ── what a scope means for the rest of the dialog ───────────────────────
    'cl_only_wl':      ('Approval and required fields apply to <strong>registering torrents</strong>, so '
                        'they are only asked for a key that can do it.',
                        'Zatwierdzanie i wymagane pola dotyczą <strong>rejestrowania torrentów</strong>, '
                        'więc pytamy o nie tylko przy kluczu, który to potrafi.'),
    'cl_bridge_on':    ('This key can sign the partner\'s members in here through the bridge. It is on.',
                        'Ten klucz może logować tutaj użytkowników partnera przez mostek. Mostek jest włączony.'),
    'cl_bridge_off':   ('The sign-in bridge is <strong>off</strong>, so the five <code>v1/auth/*</code> '
                        'endpoints answer 503 for this key. Turn it on in Settings → Sign-in bridge.',
                        'Mostek logowania jest <strong>wyłączony</strong>, więc pięć endpointów '
                        '<code>v1/auth/*</code> odpowie temu kluczowi 503. Włącz go w Ustawieniach → Mostek logowania.'),
    'cl_fed_note':     ('What this key may take is set once for every peer, in Settings → Federation — '
                        'not here.',
                        'Co ten klucz może pobrać, ustawia się raz dla wszystkich peerów, w Ustawieniach → '
                        'Federacja — nie tutaj.'),
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


# ── recovered 2026-09-13 ────────────────────────────────────────────────────
# These strings were added straight to lang/en.php and lang/pl.php between 1.43 and 1.50 and never to
# these sources, so the first regeneration since then (1.50.1) dropped all of them. They are written
# back here, where the generator reads from. tests/lang_test.php now checks that the generated files
# match the sources, so a string added to the generated file alone fails the battery on the spot.
add('', {
    'apidocs.ab_already': ('The tracker already refuses this hash. Nothing was filed, because there is nothing left to ask for.',
        'Tracker już odrzuca ten hash. Nic nie zostało zgłoszone, bo nie ma już o co prosić.'),
    'apidocs.ab_blocked': ('The report was filed and the torrent was blocked immediately. It is no longer served to anybody. <code>error</code> <code>blocked_in_database_only</code> means the tracker\'s list file could not be written yet: the block is recorded and reaches the tracker with the next write.',
        'Zgłoszenie zostało przyjęte, a torrent natychmiast zablokowany. Nie jest już nikomu serwowany. <code>error</code> <code>blocked_in_database_only</code> znaczy, że nie udało się jeszcze zapisać pliku listy trackera: blokada jest zapisana i dotrze do trackera przy następnym zapisie.'),
    'apidocs.ab_duplicate': ('A report about this hash is already open here. <code>report_id</code> names it.',
        'Zgłoszenie o tym hashu jest już tutaj otwarte. <code>report_id</code> wskazuje które.'),
    'apidocs.ab_invalid': ('The item could not be read, or it is missing a field this key must send. <code>error</code> names which.',
        'Nie dało się odczytać pozycji albo brakuje pola, którego ten klucz musi używać. <code>error</code> mówi którego.'),
    'apidocs.ab_received': ('The report was filed and is waiting for a person to decide. The torrent is untouched until then.',
        'Zgłoszenie zostało przyjęte i czeka na decyzję człowieka. Do tego czasu torrent pozostaje nietknięty.'),
    'apidocs.abuse_intro': ('One request may carry several reports. Each item names a torrent by <code>magnet</code> or <code>hash</code>; everything else describes the claim. Items are answered one by one, so a report that cannot be read does not take the rest of the batch down with it.',
        'Jedno żądanie może nieść kilka zgłoszeń. Każda pozycja wskazuje torrent przez <code>magnet</code> albo <code>hash</code>; reszta opisuje roszczenie. Pozycje są rozpatrywane pojedynczo, więc zgłoszenie, którego nie da się odczytać, nie pociąga za sobą całej paczki.'),
    'apidocs.abuse_note_auto': ('<strong>This key blocks on arrival.</strong> A hash you send here stops being served to anybody who has that torrent, without a person reading the report first. Send only what you are certain of; an appeal is the only way back.',
        '<strong>Ten klucz blokuje od razu.</strong> Hash wysłany tutaj przestaje być serwowany wszystkim, którzy mają ten torrent, bez czytania zgłoszenia przez człowieka. Wysyłaj tylko to, czego jesteś pewien; jedyną drogą powrotną jest odwołanie.'),
    'apidocs.abuse_note_review': ('Reports from this key are <strong>held for review</strong>. They land in the operator\'s queue with your name against them, and nothing about the torrent changes until somebody decides.',
        'Zgłoszenia z tego klucza są <strong>wstrzymywane do przeglądu</strong>. Trafiają do kolejki operatora z twoją nazwą i w sprawie torrenta nic się nie zmienia, dopóki ktoś nie zdecyduje.'),
    'apidocs.abuse_reply_auto': ('Every item comes back with what happened to it. <code>blocked</code> means the tracker has already stopped serving that torrent.',
        'Każda pozycja wraca z informacją, co się z nią stało. <code>blocked</code> znaczy, że tracker już przestał serwować ten torrent.'),
    'apidocs.abuse_reply_review': ('Every item comes back with what happened to it. <code>received</code> means it is in the queue — not that it has been acted on.',
        'Każda pozycja wraca z informacją, co się z nią stało. <code>received</code> znaczy, że jest w kolejce — nie że ktoś już ją rozpatrzył.'),
    'apidocs.block_auto': ('Blocked immediately, without review',
        'Natychmiastowa blokada, bez przeglądu'),
    'apidocs.block_review': ('Held for review by a person',
        'Wstrzymane do przeglądu przez człowieka'),
    'apidocs.check_body_auto': ('Your key publishes straight to the tracker, so a row is normally live from the tracker\'s next reload of the list. This endpoint answers anyway — a hash can be banned later, and a reconciliation that checks rather than assumes is a reconciliation that stays correct. Send one hash (<code>hash=</code>) or several (<code>hashes=</code>, comma-separated) in the query string, or a batch in the body.',
        'Twój klucz publikuje prosto na tracker, więc wpis zwykle jest aktywny od najbliższego przeładowania listy przez tracker. Ten endpoint i tak odpowiada — hash może zostać później zablokowany, a uzgodnienie, które sprawdza, zamiast zakładać, pozostaje poprawne. Wyślij jeden hash (<code>hash=</code>) albo kilka (<code>hashes=</code>, rozdzielone przecinkami) w query stringu albo paczkę w treści żądania.'),
    'apidocs.check_body_review': ('Your submissions wait for a person, so <code>pending</code> is not the end of the story. Ask this endpoint what happened: it returns the decision and, when a row was turned down, the note the moderator wrote about it. Send one hash in the query string, or a batch in the body.',
        'Twoje zgłoszenia czekają na człowieka, więc <code>pending</code> to nie koniec historii. Zapytaj ten endpoint, co się z nimi stało: zwraca decyzję, a przy odrzuceniu także notatkę, którą napisał moderator. Wyślij jeden hash w query stringu albo paczkę w treści żądania.'),
    'apidocs.check_note': ('The note is returned only to the key that submitted the row — it was written for you. Anybody else asking about the same hash sees the status and <code>mine: false</code>.',
        'Notatkę dostaje wyłącznie klucz, który przysłał dany wpis — była napisana do ciebie. Ktokolwiek inny pytający o ten sam hash widzi status i <code>mine: false</code>.'),
    'apidocs.ck_banned': ('Refused by this tracker. Re-submitting will not change it.',
        'Odrzucony przez ten tracker. Ponowne wysłanie tego nie zmieni.'),
    'apidocs.ck_live': ('Being served by the tracker.',
        'Serwowany przez tracker.'),
    'apidocs.ck_pending': ('Waiting for a person. Not being served yet.',
        'Czeka na człowieka. Jeszcze nie jest serwowany.'),
    'apidocs.ck_rejected': ('Turned down. Not being served; <code>review_note</code> says why.',
        'Odrzucony. Nie jest serwowany; <code>review_note</code> mówi dlaczego.'),
    'apidocs.ck_unknown': ('Not registered here. (A hash banned here without ever being registered also answers unknown — submitting it would say <code>banned</code>.)',
        'Nie jest tu zarejestrowany. (Hash zbanowany tutaj, choć nigdy nie był zarejestrowany, również odpowiada unknown — jego wysłanie dałoby <code>banned</code>.)'),
    'apidocs.h_abuse': ('Reporting a torrent',
        'Zgłaszanie torrenta'),
    'apidocs.h_abuse_reply': ('What comes back',
        'Co wraca w odpowiedzi'),
    'apidocs.h_check': ('Asking what happened',
        'Pytanie, co się stało'),
    'apidocs.k_blocking': ('What happens to a report',
        'Co się dzieje ze zgłoszeniem'),
})
