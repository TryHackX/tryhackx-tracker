S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('settings', {
    'forum_desc_heading': ('Descriptions from the forum', 'Opisy z forum'),
    'forum_desc_sub': ('the first post of a topic, sent by a forum\'s key', 'pierwszy post tematu, wysłany kluczem forum'),
    'forum_desc_conflict': ('When the description was changed here', 'Gdy opis zmieniono tutaj'),
    'forum_desc_conflict_proposal': ('The forum version becomes a proposal for moderators',
                                     'Wersja z forum trafia do moderatorów jako propozycja'),
    'forum_desc_conflict_keep': ('Keep it and mark the difference', 'Zostaw go i oznacz rozbieżność'),
    'forum_desc_conflict_replace': ('Replace it, the old one kept in its history', 'Zastąp go, a stary zachowaj w historii'),
    'forum_desc_conflict_hint': (
        'A new version of the post replaces a description nobody changed here. One that was written or edited here is '
        'never overwritten on its own: the forum version waits under Moderation → To review → Proposed changes, or the '
        'description stays as it is and is marked as different from the forum (the "Forum: needs a look" state), or — '
        'only for a description that came from the forum, and only on a key that publishes at once — it is replaced and '
        'the old version kept in its history. Whether a key sends descriptions at all, and whether they wait for a '
        'moderator, is set on that key (API clients tab).',
        'Nowa wersja posta zastępuje opis, którego nikt tutaj nie zmieniał. Opisu napisanego albo poprawionego tutaj '
        'import nigdy sam nie nadpisuje: wersja z forum czeka w Moderacja → Do przejrzenia → Propozycje zmian albo opis '
        'zostaje bez zmian i jest oznaczony jako różny od forum (stan „Forum: wymaga uwagi”), albo — tylko gdy opis '
        'pochodzi z forum i tylko przy kluczu, który publikuje od razu — zostaje zastąpiony, a stara wersja trafia do '
        'jego historii. Czy klucz w ogóle wysyła opisy i czy czekają na moderatora, ustawia się przy kluczu (karta Klienci API).'),
})

add('a.wl', {
    'rv_st_forum': ('From the forum: needs attention', 'Z forum: wymaga uwagi'),
    'cl_describe': ('Descriptions from the forum', 'Opisy z forum'),
    'cl_describe_off': ('Off', 'Wyłączone'),
    'cl_describe_queue': ('Into the review queue', 'Do kolejki'),
    'cl_describe_publish': ('Published at once', 'Publikowane od razu'),
    'cl_describe_hint': (
        'What <code>v1/whitelist/describe</code> does with the first post of a topic this key sends: nothing, a '
        'description that waits for a moderator, or one published at once — only for torrents this key registered. '
        'What happens to a description changed here is set in Settings → Descriptions &amp; source links.',
        'Co <code>v1/whitelist/describe</code> robi z pierwszym postem tematu wysłanym tym kluczem: nic, opis czekający '
        'na moderatora albo opublikowany od razu — tylko dla torrentów zarejestrowanych tym kluczem. Co się dzieje z '
        'opisem zmienionym tutaj, ustawia się w Ustawieniach → Opisy i linki źródłowe.'),
    'cl_describe_accounts': ('Authors\' accounts', 'Konta autorów'),
    'cl_describe_accounts_hint': (
        'A forum author linked to an account through the sign-in bridge is credited with that account, anyone else with '
        'their forum name. Choose the key the bridge runs on.',
        'Autor z forum połączony z kontem przez most logowania jest podpisany tym kontem, a pozostali swoją nazwą z '
        'forum. Wybierz klucz, na którym działa most.'),
})

add('js.wl', {
    'cl_describe_acc_self': ('Linked through this key', 'Połączone przez ten klucz'),
    'cl_describe_acc_none': ('None — forum names only', 'Żaden — tylko nazwy z forum'),
    'cl_describe_badge_queue': ('descriptions: queue', 'opisy: kolejka'),
    'cl_describe_badge_publish': ('descriptions: published', 'opisy: od razu'),
    'from_forum': ('from the forum', 'z forum'),
    'rv_no_text': ('no description here', 'brak opisu tutaj'),
    'forum_by': ('by :user', 'autor: :user'),
    'forum_topic': ('topic', 'temat'),
    'forum_label': ('Forum:', 'Forum:'),
    'forum_state_drift': ('the topic has a newer version (:at). This description was changed here, so it was left as it is.',
                          'temat ma nowszą wersję (:at). Ten opis zmieniono tutaj, więc został bez zmian.'),
    'forum_state_too_long': ('the first post is too long to bring over (:chars characters, the limit is :max).',
                             'pierwszy post jest za długi, by go przenieść (:chars znaków, limit :max).'),
    'forum_state_invalid': ('the first post was not brought over: :note', 'pierwszego posta nie przeniesiono: :note'),
    'forum_state_removed': ('the description from the forum was deleted here. It comes back only when the post changes, and then into the queue.',
                            'opis z forum usunięto tutaj. Wróci dopiero po zmianie posta, i to do kolejki.'),
    'forum_state_declined': ('the version from the forum was turned down. It comes back only when the topic\'s first post changes.',
                             'wersję z forum odrzucono. Wróci dopiero, gdy pierwszy post tematu się zmieni.'),
    'forum_state_withdrawn': ("the topic is hidden on the forum now: the forum's own text was taken off here, a version changed here stays. "
                              "It comes back when the topic can be seen again.",
                              'temat jest teraz ukryty na forum: tekst z forum zdjęto tutaj, wersja zmieniona tutaj zostaje. '
                              'Wróci, gdy temat znów będzie widoczny.'),
})

add('js.app', {
    'from_forum': ('from the forum', 'z forum'),
    'from_forum_title': ('This description is the first post of a topic on the forum. Open the topic',
                         'Ten opis to pierwszy post tematu na forum. Otwórz temat'),
    'from_forum_title_at': ('This description is the first post of a topic on the forum, last changed there :at. Open the topic',
                            'Ten opis to pierwszy post tematu na forum, ostatnio zmieniony tam :at. Otwórz temat'),
    'forum_too_long': ('The description is on the forum: its first post is too long to bring over here (:chars characters, the limit is :max).',
                       'Opis jest na forum: jego pierwszy post jest za długi, by go tu przenieść (:chars znaków, limit :max).'),
    'forum_too_long_newer': ('The newest version on the forum is too long to bring over (:chars characters, the limit is :max); this one is older.',
                             'Najnowsza wersja na forum jest za długa, by ją przenieść (:chars znaków, limit :max); ta tutaj jest starsza.'),
    'forum_drift': ('The forum has a newer version of this description (:at). It was changed here, so it was not overwritten.',
                    'Na forum jest nowsza wersja tego opisu (:at). Opis zmieniono tutaj, więc nie został nadpisany.'),
    'forum_proposed': ('A newer version from the forum waits for a moderator under Moderation → To review → Proposed changes.',
                       'Nowsza wersja z forum czeka na moderatora w Moderacja → Do przejrzenia → Propozycje zmian.'),
    'forum_invalid': ('The version from the forum was not brought over: :note', 'Wersji z forum nie przeniesiono: :note'),
    'forum_removed': ('The description from the forum was deleted here. It comes back only when the post changes, and then into the queue.',
                      'Opis z forum usunięto tutaj. Wróci dopiero po zmianie posta, i to do kolejki.'),
    'forum_declined': ('The newest version from the forum was turned down.', 'Najnowszą wersję z forum odrzucono.'),
    'forum_withdrawn': ("The topic is hidden on the forum now: the forum's own text was taken off here, a version changed here stays. "
                        "It comes back when the topic can be seen again.",
                        'Temat jest teraz ukryty na forum: tekst z forum zdjęto tutaj, wersja zmieniona tutaj zostaje. '
                        'Wróci, gdy temat znów będzie widoczny.'),
    'forum_open': ('Open the topic', 'Otwórz temat'),
})

add('apidocs', {
    'k_describe': ('Descriptions from the forum', 'Opisy z forum'),
    'describe_off_short': ('off', 'wyłączone'),
    'describe_queue_short': ('into the review queue', 'do kolejki'),
    'describe_publish_short': ('published at once', 'publikowane od razu'),
    'ep_wl_describe': ('Send the first post of a topic as the description of the torrents it carries.',
                       'Wysłanie pierwszego posta tematu jako opisu torrentów, które zawiera.'),
    'h_describe': ('Descriptions from a forum', 'Opisy z forum'),
    'describe_body_queue': (
        'After its magnets went through <code>v1/whitelist/submit</code>, the first post of a topic is sent here as the '
        'description of those torrents: its source exactly as the author wrote it, in the <code>flarum</code> format. '
        'Descriptions from this key <strong>wait for a moderator</strong>. A new version of the post replaces a '
        'description nobody changed here; one that was changed here is handled as the operator decided. Send a topic '
        'again whenever you like: a text that did not change answers <code>unchanged</code> and writes nothing.',
        'Gdy magnety tematu przeszły już przez <code>v1/whitelist/submit</code>, jego pierwszy post trafia tutaj jako '
        'opis tych torrentów: źródło dokładnie tak, jak napisał je autor, w formacie <code>flarum</code>. Opisy z tego '
        'klucza <strong>czekają na moderatora</strong>. Nowa wersja posta zastępuje opis, którego nikt tutaj nie '
        'zmieniał; opis zmieniony tutaj jest traktowany tak, jak zdecydował operator. Temat można wysyłać ponownie '
        'kiedykolwiek: niezmieniony tekst odpowiada <code>unchanged</code> i niczego nie zapisuje.'),
    'describe_body_publish': (
        'After its magnets went through <code>v1/whitelist/submit</code>, the first post of a topic is sent here as the '
        'description of those torrents: its source exactly as the author wrote it, in the <code>flarum</code> format. '
        'Descriptions from this key are <strong>published at once</strong>. A new version of the post replaces a '
        'description nobody changed here; one that was changed here is handled as the operator decided. Send a topic '
        'again whenever you like: a text that did not change answers <code>unchanged</code> and writes nothing.',
        'Gdy magnety tematu przeszły już przez <code>v1/whitelist/submit</code>, jego pierwszy post trafia tutaj jako '
        'opis tych torrentów: źródło dokładnie tak, jak napisał je autor, w formacie <code>flarum</code>. Opisy z tego '
        'klucza są <strong>publikowane od razu</strong>. Nowa wersja posta zastępuje opis, którego nikt tutaj nie '
        'zmieniał; opis zmieniony tutaj jest traktowany tak, jak zdecydował operator. Temat można wysyłać ponownie '
        'kiedykolwiek: niezmieniony tekst odpowiada <code>unchanged</code> i niczego nie zapisuje.'),
    'describe_limits': (
        'Send your forum\'s own limit for the length of a post as <code>max_chars</code>: descriptions are taken up to '
        'it, :cap characters at most; without it, the limit for descriptions written on this tracker applies. A first '
        'post longer than that is not cut: send <code>"content": null</code> with its <code>chars</code>, or the whole '
        'text — it answers <code>too_long</code> and nothing is written. At most :topics topics a call, :hashes hashes '
        'a topic and :mb MB a body.',
        'Wyślij w <code>max_chars</code> limit długości posta obowiązujący na Twoim forum: opisy są przyjmowane do '
        'niego, najwyżej :cap znaków; bez niego obowiązuje limit opisów pisanych na tym trackerze. Dłuższy pierwszy '
        'post nie jest ucinany: wyślij <code>"content": null</code> z jego <code>chars</code> albo cały tekst — '
        'odpowiedź to <code>too_long</code> i nic nie zostaje zapisane. Najwyżej :topics tematów w wywołaniu, '
        ':hashes hashy w temacie i :mb MB treści.'),
    'describe_off_note': (
        'While descriptions are off for this key or on the tracker, the call answers <code>{"ok": false, "error": '
        '"describe_off"}</code> with status 200. That is not a failure: keep sending magnets and try descriptions again later.',
        'Gdy opisy są wyłączone dla tego klucza albo na trackerze, wywołanie odpowiada <code>{"ok": false, "error": '
        '"describe_off"}</code> ze statusem 200. To nie błąd: wysyłaj dalej magnety, a opisy spróbuj później.'),
    'ds_created': ('A description was written and published.', 'Opis został zapisany i opublikowany.'),
    'ds_queued': ('A description was written and waits for a moderator.', 'Opis został zapisany i czeka na moderatora.'),
    'ds_updated': ('Nobody changed the description here, so the new version replaced it.',
                   'Nikt tutaj nie zmieniał opisu, więc nowa wersja go zastąpiła.'),
    'ds_replaced': ('The description had been changed here; as the operator decided, the new version replaced it and the old one is kept in its history.',
                    'Opis zmieniono tutaj; zgodnie z decyzją operatora nowa wersja go zastąpiła, a stara jest w jego historii.'),
    'ds_proposed': ('The new version waits for a moderator as a proposal.', 'Nowa wersja czeka na moderatora jako propozycja.'),
    'ds_kept': ('The description had been changed here and stays as it is, marked as different from the forum.',
                'Opis zmieniono tutaj i zostaje bez zmian, oznaczony jako różny od forum.'),
    'ds_unchanged': ('The same text as last time: nothing was written.', 'Ten sam tekst co ostatnio: nic nie zapisano.'),
    'ds_too_long': ('Longer than the limit: nothing was written, nothing was cut.', 'Dłuższy niż limit: nic nie zapisano i nic nie ucięto.'),
    'ds_invalid': ('An empty post, or a format switched off on the tracker — the detail says which.',
                   'Pusty post albo format wyłączony na trackerze — szczegóły mówią, co.'),
    'ds_not_registered': ('Nobody registered the hash on this tracker: send it to v1/whitelist/submit first.',
                          'Nikt nie zarejestrował tego hasha na trackerze: najpierw wyślij go do v1/whitelist/submit.'),
    'ds_registered_elsewhere': ('The hash is registered here, but not by this key — on the site or through another key — so its description is not this key\'s to write.',
                                'Hash jest tu zarejestrowany, ale nie przez ten klucz — na stronie albo innym kluczem — więc jego opis nie należy do tego klucza.'),
    'ds_banned': ('The hash is banned on the tracker.', 'Hash jest zablokowany na trackerze.'),
    'ds_other_topic': ('The hash belongs to another topic: the one whose first post registered it, or the one that described it first.',
                       'Hash należy do innego tematu: tego, którego pierwszy post go zarejestrował, albo tego, który opisał go pierwszy.'),
    'ds_stale': ('An older version than the one already here: nothing was written.', 'Starsza wersja niż ta, która już tu jest: nic nie zapisano.'),
    'ds_withdrawn': ("The topic was withdrawn: the forum's text was taken off and its waiting proposal withdrawn; a description changed here stays.",
                     'Temat wycofano: tekst z forum zdjęto, a czekającą propozycję wycofano; opis zmieniony tutaj zostaje.'),
    'ds_released': ('Withdrawn, and the hash is no longer bound to the topic: another topic may describe it.',
                    'Wycofano, a hash nie jest już związany z tematem: może go opisać inny temat.'),
    'describe_withdraw': (
        'A topic guests can no longer see is sent with <code>"visible": false</code> and its hashes, without its text; add '
        '<code>"gone": true</code> when the topic or its first post was deleted, or the hashes left the post. A tracker that '
        'takes these says so with <code>describe_max_hashes</code> in the ping and the <code>withdrawn</code> and '
        '<code>released</code> counts in the summary.',
        'Temat, którego goście już nie widzą, wysyłasz z <code>"visible": false</code> i jego hashami, bez tekstu; dodaj '
        '<code>"gone": true</code>, gdy temat albo jego pierwszy post usunięto lub hashe zniknęły z posta. Tracker, który to '
        'przyjmuje, mówi o tym przez <code>describe_max_hashes</code> w pingu oraz liczniki <code>withdrawn</code> i '
        '<code>released</code> w podsumowaniu.'),
    'describe_audience': (
        '<code>"audience"</code> says who could read the topic on your side: <code>guests</code> (the default) or '
        '<code>members</code>. A description sent with <code>members</code> is shown here to signed-in people only.',
        '<code>"audience"</code> mówi, kto mógł przeczytać temat po Twojej stronie: <code>guests</code> (domyślnie) albo '
        '<code>members</code>. Opis wysłany z <code>members</code> jest tu pokazywany tylko zalogowanym.'),
    'ds_removed': ('The description was deleted here; the same text is not brought back.', 'Opis usunięto tutaj; ten sam tekst nie wraca.'),
    'ds_declined': ('A moderator turned this version down.', 'Moderator odrzucił tę wersję.'),
})
