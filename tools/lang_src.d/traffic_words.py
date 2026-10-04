# -*- coding: utf-8 -*-
"""The Traffic page's server sentences (1.73.0, part F)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

Admin -> Traffic, the UDP traffic card (and, below, the OpenTracker card's advice and failures, English
the same way). Three things admin/net_status answered in English on every page:
the paragraph under the slider (includes/netlimit.php netlimitRecommendText(): the last days' median, P95
and peak, and what a limit would do -- or, in a flood, why the arrivals are not the number to pick), the
load study's reason for having no answer (netlimitLoadCurve()'s `why`), and the card's own failures
(the helper not configured, exec() off, no answer). Each sentence is a key now: the server says it in the
reader's language AND answers its key and numbers, and the card writes every sentence as a word that
keeps its key (assets/js/admin-netlimit.js), so the paragraph follows the live language switch; the
traffic page's bundle carries api.net.

Polish: the card's own words -- "przychodzące" (arriving), "zapora" (the firewall), "mediana", "szczyt",
"pps" as it is; no gendered verb forms (the second person in the present tense: "zaczynasz odrzucać").
The numbers are placeholders the card fills with its own number format. :days is 2-30 (one day has its
own sentence), and every count from 2 up takes "dni".
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the recommendation under the slider (netlimitRecommendParts()) ─────────────────────────────────────────────
add('api.net', {
    'rec_none':       ('No traffic has been recorded yet. Start counting and come back in an hour — the suggestion needs measurements, not guesses.',
                       'Nie zarejestrowano jeszcze żadnego ruchu. Włącz liczenie i wróć za godzinę — sugestia potrzebuje pomiarów, nie zgadywania.'),
    'rec_stats_day':  ('Last day: median :median pps, P95 :p95 pps, peak :peak pps.',
                       'Ostatni dzień: mediana :median pps, P95 :p95 pps, szczyt :peak pps.'),
    'rec_stats':      ('Last :days days: median :median pps, P95 :p95 pps, peak :peak pps.',
                       'Ostatnie :days dni: mediana :median pps, P95 :p95 pps, szczyt :peak pps.'),
    'rec_few':        ('That is fewer than 60 samples — treat the numbers as a first impression, not a recommendation.',
                       'To mniej niż 60 próbek — traktuj te liczby jako pierwsze wrażenie, a nie zalecenie.'),
    # In a flood the caveat leads, and the arrivals' figure is only a reference at the end (see the function).
    'rec_flood':      ('Those are ARRIVALS, not demand — a tracker whose old swarm keeps calling receives far more than it serves, '
                       'so a limit anywhere near them would never fire.',
                       'To są pakiety PRZYCHODZĄCE, a nie zapotrzebowanie — tracker, do którego stary swarm wciąż się dobija, '
                       'odbiera znacznie więcej, niż obsługuje, więc limit gdziekolwiek w pobliżu tych liczb nigdy by nie zadziałał.'),
    'rec_flood_passed': ('What is actually getting through right now is :passed pps, and THAT is the number to pick from: '
                         'choose what you are willing to hand OpenTracker, with some headroom. Packets above it cost you nothing, '
                         'because the firewall drops them before the tracker ever sees them.',
                         'W tej chwili naprawdę przechodzi :passed pps i TO jest liczba, od której trzeba wychodzić: wybierz tyle, '
                         'ile chcesz oddać OpenTrackerowi, z pewnym zapasem. Pakiety ponad nią nic cię nie kosztują, bo zapora '
                         'odrzuca je, zanim tracker w ogóle je zobaczy.'),
    'rec_flood_pick': ('Pick the number you are willing to hand OpenTracker, not one taken from the arrivals above. Packets over it '
                       'cost you nothing, because the firewall drops them before the tracker ever sees them.',
                       'Wybierz liczbę, którą chcesz oddać OpenTrackerowi, a nie wziętą z przychodzących powyżej. Pakiety ponad nią '
                       'nic cię nie kosztują, bo zapora odrzuca je, zanim tracker w ogóle je zobaczy.'),
    'rec_flood_ref':  ('(For reference, a limit above the arrivals would be around :n pps.)',
                       '(Dla porównania: limit powyżej przychodzących wynosiłby około :n pps.)'),
    'rec_normal':     ('A limit at :suggested pps (P95 + 5 %) would essentially never trigger; below roughly :floor pps you start '
                       'dropping packets that are currently arriving.',
                       'Limit na poziomie :suggested pps (P95 + 5 %) praktycznie nigdy by nie zadziałał; poniżej mniej więcej '
                       ':floor pps zaczynasz odrzucać pakiety, które teraz przychodzą.'),
})

# ── why the load study has no answer (netlimitLoadCurve(); the card: "Load study: :why") ───────────────────────
add('api.net', {
    'load_few':   ('not enough readings yet — the load study needs at least :min samples with a load recorded, and there are :n.',
                   'za mało odczytów — badanie obciążenia potrzebuje próbek z zapisanym obciążeniem: co najmniej :min, a jest ich :n.'),
    'load_flat':  ('the traffic barely varied over this window (:lo–:hi pps), so there is nothing to compare a busy machine against.',
                   'ruch w tym oknie prawie się nie zmieniał (:lo–:hi pps), więc nie ma z czym porównać zapracowanej maszyny.'),
    'load_never': ('this machine never reached a load of :busy per core at any rate seen so far (busiest median :max) — there is no '
                   'ceiling to warn about yet.',
                   'ta maszyna przy żadnym widzianym dotąd ruchu nie doszła do obciążenia :busy na rdzeń (najwyższa mediana :max) — '
                   'nie ma jeszcze sufitu, przed którym trzeba by ostrzegać.'),
})

# ── the card's own failure (admin/net_status; the other two are api.net.no_helper_2 and api.net.exec_disabled) ──
add('api.net', {
    'helper_no_answer': ('The firewall helper did not answer.', 'Pomocnik zapory nie odpowiedział.'),
})

# ── the OpenTracker card beside it (includes/opentracker.php otRun() / otAdvice(), admin/ot_status) ────────────────
# Its advice was English on every page while the DB memory and sysctl cards' advice (api.dbmem.adv_*, api.sysctl.adv_*)
# was not; so were its failures. The command and the setting's name in it are a command and a name in both languages.
add('api.ot', {
    'no_helper':      ('No OpenTracker helper command is configured (Settings → OpenTracker performance).',
                       'Nie skonfigurowano polecenia pomocnika OpenTrackera (Ustawienia → OpenTracker — wydajność).'),
    'bad_command':    ('The helper command contains characters that are not allowed.',
                       'Polecenie pomocnika zawiera niedozwolone znaki.'),
    'exec_disabled':  ('PHP exec() is disabled on this server — the panel cannot reach the helper.',
                       'Funkcja exec() PHP jest wyłączona na tym serwerze — panel nie może uruchomić pomocnika.'),
    'no_answer':      ('The helper did not answer.', 'Pomocnik nie odpowiedział.'),
    'adv_workers_few':  ('opentracker runs :workers UDP worker threads on :cpus cores. More threads help only while packets '
                         'are actually queueing — check the dropped count below before raising it.',
                         'Wątki UDP opentrackera: :workers, rdzenie: :cpus. Więcej wątków pomaga tylko wtedy, gdy pakiety '
                         'naprawdę czekają w kolejce — zanim zwiększysz ich liczbę, sprawdź poniżej licznik odrzuconych.'),
    'adv_workers_many': ('There are more UDP workers (:workers) than cores (:cpus). Past one per core the threads mostly '
                         'compete with each other.',
                         'Wątków UDP (:workers) jest więcej niż rdzeni (:cpus). Powyżej jednego na rdzeń wątki głównie '
                         'konkurują ze sobą.'),
    'adv_workers_disagree': ('The whitelist and blacklist config files disagree about the worker count, so it would change '
                             'when the tracker switches mode. Applying a value from here writes both.',
                             'Pliki konfiguracji whitelisty i blacklisty podają różną liczbę wątków, więc zmieniałaby się ona '
                             'przy przełączeniu trybu trackera. Zastosowanie wartości stąd zapisuje oba.'),
    'adv_rmem':       ('The kernel caps every socket buffer at :bytes bytes (net.core.rmem_max). A packet dropped there cost the '
                       'machine everything except the answer — unlike one the firewall drops, which costs nothing. Raising it '
                       'is a system-wide sysctl, so the panel does not do it for you: sudo sysctl -w net.core.rmem_max=8388608',
                       'Jądro ogranicza każdy bufor gniazda do :bytes B (net.core.rmem_max). Pakiet odrzucony w tym miejscu '
                       'kosztował maszynę wszystko poza odpowiedzią — w przeciwieństwie do odrzuconego przez zaporę, który nic '
                       'nie kosztuje. Podniesienie limitu to ustawienie sysctl dla całego systemu, więc panel nie robi tego za '
                       'ciebie: sudo sysctl -w net.core.rmem_max=8388608'),
    'adv_rmem_drops': ('The kernel caps every socket buffer at :bytes bytes (net.core.rmem_max), and this socket has already '
                       'discarded :drops packets because its queue was full. A packet dropped there cost the machine '
                       'everything except the answer — unlike one the firewall drops, which costs nothing. Raising it is a '
                       'system-wide sysctl, so the panel does not do it for you: sudo sysctl -w net.core.rmem_max=8388608',
                       'Jądro ogranicza każdy bufor gniazda do :bytes B (net.core.rmem_max), a to gniazdo, bo jego kolejka była '
                       'pełna, odrzuciło już pakiety w liczbie :drops. Pakiet odrzucony w tym miejscu kosztował maszynę wszystko '
                       'poza odpowiedzią — w przeciwieństwie do odrzuconego przez zaporę, który nic nie kosztuje. Podniesienie '
                       'limitu to ustawienie sysctl dla całego systemu, więc panel nie robi tego za ciebie: '
                       'sudo sysctl -w net.core.rmem_max=8388608'),
    'adv_dropins':    ('Other drop-ins are present and are never touched by the panel: :files. systemd merges them, and the '
                       'highest-numbered file wins a conflict.',
                       'Są też inne pliki drop-in, których panel nigdy nie rusza: :files. systemd je łączy, a przy konflikcie '
                       'wygrywa plik o najwyższym numerze.'),
})
