S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('perm', {
    'whitelist.request': ('Ask for a torrent from the index to be put on the whitelist, or support such a request',
                          'Prośba o dodanie torrentu z indeksu na whitelistę albo poparcie takiej prośby'),
})

add('settings', {
    'wlreq_title': ('Requests to put a torrent on the whitelist', 'Wnioski o dodanie na whitelistę'),
    'wlreq_intro': ('In the Info panel a member asks for a torrent from the index to be put on the whitelist, where it stays in the search when the swarm goes quiet. One request per torrent — others support it. You decide under Whitelist → Requests.',
                    'W panelu Info członek prosi o dodanie torrentu z indeksu na whitelistę, gdzie zostaje on w wyszukiwarce, gdy rój ucichnie. Jeden wniosek na torrent — inni go popierają. Decydujesz w Whitelist → Wnioski.'),
    'wlreq_enabled': ('Members may ask', 'Członkowie mogą prosić'),
    'wlreq_days': ('Days before a rejected torrent can be asked for again', 'Po ilu dniach odrzucony torrent można zgłosić ponownie'),
    'wlreq_days_hint': ('0 = at once.', '0 = od razu.'),
    'wlreq_per_day': ('Requests and supports a member may make a day', 'Wniosków i poparć jednej osoby na dobę'),
    'wlreq_per_day_hint': ('Moderators decide in the panel without a limit.', 'Moderatorzy decydują w panelu bez limitu.'),
    'wlreq_note': ('A note with the request', 'Notatka do wniosku'),
})

add('a.wl', {
    'tab_requests': ('Requests', 'Wnioski'),
    'rq_filter': ('Which requests', 'Które wnioski'),
    'rq_open': ('Waiting', 'Oczekujące'),
    'rq_approved': ('Approved', 'Przyjęte'),
    'rq_rejected': ('Rejected', 'Odrzucone'),
    'rq_intro': ('Members ask for torrents from the index to be put on the whitelist. “Put it on the whitelist” does what Promote does on the Index page; everybody who asked is told the decision.',
                 'Członkowie proszą o dodanie torrentów z indeksu na whitelistę. „Dodaj do whitelisty” robi to samo co „Promote” na stronie Index; każdy, kto prosił, dostaje powiadomienie o decyzji.'),
})

add('js.wl', {
    'rq_total': ('Requests: :n', 'Wniosków: :n'),
    'rq_off': ('Requests are switched off in Settings; the list stays.', 'Wnioski są wyłączone w Ustawieniach; lista zostaje.'),
    'rq_none': ('No requests here.', 'Brak wniosków.'),
    'rq_reject_title': ('Reject the request for “:name”', 'Odrzuć wniosek dla „:name”'),
    'rq_reply_label': ('An answer for the people who asked (optional)', 'Odpowiedź dla proszących (opcjonalnie)'),
    'rq_reply_ph': ('For example: no seeders', 'Na przykład: brak seedów'),
    'rq_approve': ('Put it on the whitelist', 'Dodaj do whitelisty'),
    'rq_approve_confirm': ('Put “:name” on the whitelist?', 'Dodać „:name” do whitelisty?'),
    'rq_reject': ('Reject', 'Odrzuć'),
    'rq_done': ('Done.', 'Gotowe.'),
    'rq_open_info': ('Open Info', 'Otwórz Info'),
    'rq_gone': ('deleted account', 'usunięte konto'),
    'rq_seeders': ('seeders :n', 'seedy :n'),
    'rq_seen': ('seen :date', 'widziany :date'),
    'rq_gone_index': ('no longer in the index', 'nie ma już w indeksie'),
    'rq_people_one': ('1 person', '1 osoba'),
    'rq_people_few': (':n people', ':n osoby'),
    'rq_people_many': (':n people', ':n osób'),
    'rq_decided': (':date, :who', ':date, :who'),
    'rq_col_torrent': ('Torrent', 'Torrent'),
    'rq_col_people': ('Asked', 'Prośby'),
    'rq_col_swarm': ('Swarm', 'Rój'),
    'rq_col_when': ('When', 'Kiedy'),
    'rq_col_actions': ('Actions', 'Akcje'),
})

add('js.app', {
    'wlreq_ask': ('Ask for the whitelist', 'Poproś o whitelistę'),
    'wlreq_title': ('On the whitelist a torrent stays in the search even when the swarm goes quiet; a moderator decides.',
                    'Na whiteliście torrent zostaje w wyszukiwarce nawet, gdy rój ucichnie; decyduje moderator.'),
    'wlreq_support': ('Support the request (:n)', 'Poprzyj wniosek (:n)'),
    'wlreq_note_ph': ('Why? (optional)', 'Dlaczego? (opcjonalnie)'),
    'wlreq_send': ('Send the request', 'Wyślij wniosek'),
    'wlreq_send_support': ('Support', 'Poprzyj'),
    'wlreq_cancel': ('Cancel', 'Anuluj'),
    'wlreq_waiting_one': ('Whitelist request: waiting (1 person)', 'Wniosek o whitelistę: czeka (1 osoba)'),
    'wlreq_waiting_few': ('Whitelist request: waiting (:n people)', 'Wniosek o whitelistę: czeka (:n osoby)'),
    'wlreq_waiting_many': ('Whitelist request: waiting (:n people)', 'Wniosek o whitelistę: czeka (:n osób)'),
    'wlreq_waiting_title': ('Your request is in; a moderator decides.', 'Wniosek jest wysłany; decyduje moderator.'),
    'wlreq_again': ('Whitelist request rejected — again from :date', 'Wniosek o whitelistę odrzucony — ponownie od :date'),
    'wlreq_again_title': ('A moderator rejected the last request for this torrent.', 'Moderator odrzucił ostatni wniosek dla tego torrentu.'),
})

add('api.wlreq', {
    'forbidden': ('You cannot ask for the whitelist.', 'Nie możesz prosić o whitelistę.'),
    'rate': ('Too many tries — try again later.', 'Za dużo prób — spróbuj później.'),
    'listed': ('This torrent is already on the whitelist.', 'Ten torrent już jest na whiteliście.'),
    'not_found': ('This torrent is not in the index.', 'Tego torrentu nie ma w indeksie.'),
    'cooldown': ('A request for this torrent was rejected recently; it can be asked for again :days days after that.',
                 'Wniosek dla tego torrentu niedawno odrzucono; ponownie można prosić po :days dniach od odrzucenia.'),
    'limit': ('Today’s limit of :n requests is used up.', 'Dzisiejszy limit wniosków (:n) jest wyczerpany.'),
    'invalid': ('That did not work.', 'Nie udało się.'),
    'sent': ('Request sent — a moderator decides.', 'Wniosek wysłany — decyduje moderator.'),
    'already': ('This request is already yours.', 'Ten wniosek już jest od Ciebie.'),
    'e_invalid': ('That did not work.', 'Nie udało się.'),
    'e_not_found': ('The request is already decided.', 'Wniosek jest już rozpatrzony.'),
    'e_banned': ('This hash is banned.', 'Ten hash jest zablokowany.'),
    'e_add_failed': ('Could not put it on the whitelist.', 'Nie udało się dodać do whitelisty.'),
})

add('wlreq', {
    'n_approved': ('“:name” is on the whitelist now', '„:name” jest już na whiteliście'),
    'n_rejected': ('The whitelist request for “:name” was rejected', 'Wniosek o whitelistę dla „:name” odrzucono'),
})
