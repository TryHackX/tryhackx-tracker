# -*- coding: utf-8 -*-
"""The traffic cards tell the truth about what production is doing (1.72.0)

Scrape coverage counted per PASS (assets/js/admin-index-coverage.js, api/admin/index_polls.php): a poll
the time budget cut is continued by the next one, and the two together are the whole scrape. The poll's
time budget and the estimate under it (Settings → Index). The inbound limiter card on Traffic
(assets/js/admin-netlimit.js): who loaded the limit, in words; a burst that is too small for the limit;
handshakes per announce from the statistics timeline.

The rewritten words of the coverage card's older keys stay where they were (js.py, admin_index.py,
admin_settings.py); what is new is here.

1.72.1: the chart over long windows — one bar per bucket of time when one per pass would be narrower than
a few pixels (its legend, its span words, its tooltip), a re-read poll's "read again from the start", and
WHEN the short downloads and failed polls were (a warning only while recent).
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the coverage card: per pass ──────────────────────────────────────────────
add('js.coverage', {
    'passes_in_window': ('Passes in this window',
        'Przebiegi w tym oknie'),
    'passes_polls_note': ('from :n polls',
        'odpytania: :n'),
    'worst_pass': ('Worst pass',
        'Najgorszy przebieg'),
    'over_passes': ('over :n finished passes',
        'zakończone przebiegi: :n'),
    'over_passes_thin': ('over :n finished pass(es) — too few to average',
        'zakończone przebiegi: :n — za mało, żeby uśredniać'),
    'no_finished_pass': ('no pass has finished in this window yet',
        'w tym oknie żaden przebieg jeszcze się nie zakończył'),
    'ended_early': ('Download ended early',
        'Pobranie urwane przed końcem'),
    'ended_early_note': ('the tracker stopped sending before the end; what arrived was read from the start',
        'tracker przestał wysyłać przed końcem; to, co dotarło, zostało przeczytane od początku'),
    # 1.72.1: WHEN, as well as how many — the warning (and "if it keeps happening…") only while one
    # happened in the last day; older ones are history with a date (production's 52 of 04.09 – 05.09)
    'ended_early_alert': ('Downloads that ended early in this window: :n, the last :ago ago. The tracker stopped sending before the end of the scrape; what arrived was read from the start and the cursor kept its place, so the next poll went on from where the pass had got. If it keeps happening, look at the tracker’s side of it.',
        'Pobrania urwane przed końcem w tym oknie: :n, ostatnie :ago temu. Tracker przestał wysyłać, zanim skończył się scrape; to, co dotarło, zostało przeczytane od początku, a kursor został na swoim miejscu, więc następne odpytanie ruszyło dalej od miejsca, do którego doszedł przebieg. Jeśli to się powtarza, przyjrzyj się trackerowi.'),
    'ended_early_old_alert': ('Downloads that ended early in this window: :n, the last on :when — none since. What arrived was read from the start and the cursor kept its place, so the next poll went on from where the pass had got.',
        'Pobrania urwane przed końcem w tym oknie: :n, ostatnie :when — od tamtej pory żadnego. To, co dotarło, zostało przeczytane od początku, a kursor został na swoim miejscu, więc następne odpytanie ruszyło dalej od miejsca, do którego doszedł przebieg.'),
    # under the short-download and failed-poll tiles ("pobranie", "odpytanie": both neuter in Polish)
    'last_ago': ('the last :ago ago',
        'ostatnie :ago temu'),
    'last_old': ('the last on :when — none since',
        'ostatnie :when — od tamtej pory żadnego'),
    'worst_when': ('the pass that began :when',
        'przebieg rozpoczęty :when'),
    'last_pass': ('Last pass',
        'Ostatni przebieg'),
    'polls_one': ('one poll',
        'jedno odpytanie'),
    'polls_n': (':n polls',
        'odpytania: :n'),
    'poll_time': (':t of polling',
        ':t odpytywania'),
    'dur_s': (':s s',
        ':s s'),
    'dur_ms': (':m min :s s',
        ':m min :s s'),
    'in_progress_value': (':n so far',
        ':n do tej pory'),
    'in_progress_note': ('in progress — the next poll continues from entry :n',
        'w toku — następne odpytanie kontynuuje od wpisu :n'),

    # one bar segment = one poll; the tooltip says what it did and what its pass came to
    'tip_head': (':when · poll :pos of :of in this pass',
        ':when · odpytanie :pos z :of w tym przebiegu'),
    'tip_head_one': (':when · the whole pass in one poll',
        ':when · cały przebieg w jednym odpytaniu'),
    'tip_start_cut': ('walked from the start to entry :n — cut by the time budget; the next poll continues where this one stopped',
        'przeszło od początku do wpisu :n — przycięte przez budżet czasu; następne odpytanie kontynuuje tam, gdzie to się zatrzymało'),
    'tip_start_full': ('walked the whole scrape from the start: :n entries',
        'przeszło cały scrape od początku: wpisów :n'),
    'tip_resume': ('continues the previous poll from entry :from — together :pct',
        'kontynuuje poprzednie odpytanie od wpisu :from — razem :pct'),
    'tip_resume_cut': ('continues the previous poll from entry :from — :pct so far; cut by the time budget again, the next poll continues where this one stopped',
        'kontynuuje poprzednie odpytanie od wpisu :from — na razie :pct; znów przycięte przez budżet czasu, następne odpytanie kontynuuje tam, gdzie to się zatrzymało'),
    'tip_resume_empty': ('resumed at entry :from and found nothing past it — the scrape had shrunk below that point; together :pct',
        'wznowiło od wpisu :from i nic za nim nie znalazło — scrape skurczył się poniżej tego miejsca; razem :pct'),
    'tip_short': ('the download ended early: :n entries arrived and were read from the start (:reason)',
        'pobranie urwało się przed końcem: dotarło wpisów :n, przeczytanych od początku (:reason)'),
    'tip_error': ('the poll failed: :err',
        'odpytanie się nie powiodło: :err'),
    # 1.72.1: a short download restarting inside an open pass walks old ground again — its part is only
    # what was new in it
    'tip_again': ('read again from the start: :n entries, :m of them new',
        'przeczytane ponownie od początku: wpisów :n, z czego nowych :m'),
    'tip_again_none': ('read again from the start: :n entries, none of them new — nothing added to the pass',
        'przeczytane ponownie od początku: wpisów :n, żadnego nowego — nic nie dodało do przebiegu'),
    'tip_numbers': ('delivered :d · kept :k · :t',
        'dostarczone :d · zachowane :k · :t'),
    'tip_pass': ('this pass: :pct · :state',
        'ten przebieg: :pct · :state'),
    'pass_complete': ('complete',
        'zakończony'),
    'pass_in_progress': ('in progress — the next poll continues it',
        'w toku — następne odpytanie go kontynuuje'),
    'pass_error': ('ended by a failed poll',
        'zakończony nieudanym odpytaniem'),
    'pass_abandoned': ('stopped — the next poll began again from the start',
        'przerwany — następne odpytanie zaczęło od początku'),
    'pass_began_before': ('began before this window',
        'zaczął się przed tym oknem'),

    'legend_start': ('From the start',
        'Od początku'),
    'legend_resume': ('Continued',
        'Kontynuacja'),
    'legend_short': ('Download ended early',
        'Pobranie urwane'),
    'legend_error': ('Failed',
        'Nieudane'),
    'legend_kept': ('darker: kept',
        'ciemniej: zachowane'),
    'legend_tracker': ('Tracker’s torrents',
        'Torrenty trackera'),
    'legend_progress': ('In progress',
        'W toku'),
    'chart_aria': ('Scrape coverage: :p passes from :n polls',
        'Pokrycie scrape: przebiegi :p, odpytania :n'),

    # 1.72.1: when one bar per pass would be narrower than a few pixels, one bar per bucket of time
    'chart_aria_buckets': ('Scrape coverage: :p passes from :n polls, one bar per :span',
        'Pokrycie scrape: przebiegi :p, odpytania :n, jeden słupek na :span'),
    'legend_bucket': ('One bar = :span, the average of its passes',
        'Jeden słupek = :span, średnia jego przebiegów'),
    'legend_worst': ('Worst pass, below :pct %',
        'Najgorszy przebieg, poniżej :pct %'),
    'bucket_passes': ('Passes: :n',
        'Przebiegi: :n'),
    'span_hours': (':n h',
        ':n h'),
    'span_day': ('1 day',
        '1 dzień'),
    'span_two_days': ('2 days',
        '2 dni'),
    'span_week': ('1 week',
        '1 tydzień'),

    'estimate_one': ('At the last full pass’s pace (:rate entries/s) the scrape (:scrape torrents) needs about :needs s — with the budget at :budget s one poll walks it.',
        'W tempie ostatniego pełnego przebiegu (:rate wpisów/s) scrape (torrentów: :scrape) potrzebuje około :needs s — przy budżecie :budget s wystarczy jedno odpytanie.'),
    'estimate_many': ('At the last full pass’s pace (:rate entries/s) the scrape (:scrape torrents) needs about :needs s — with the budget at :budget s it takes :polls polls.',
        'W tempie ostatniego pełnego przebiegu (:rate wpisów/s) scrape (torrentów: :scrape) potrzebuje około :needs s — przy budżecie :budget s potrzeba odpytań: :polls.'),
    'estimate_at_max': ('At :max s one poll would walk it.',
        'Przy :max s wystarczyłoby jedno odpytanie.'),
})

add('js.index', {
    'ended_early_badge': ('the download ended early',
        'pobranie urwało się przed końcem'),
    'ended_early_suffix': (' — the download ended early; what arrived was read',
        ' — pobranie urwało się przed końcem; to, co dotarło, zostało przeczytane'),
})


# ── the poll's time budget: what it means for this scrape (Settings → Index) ──
_EST = {
    'one': ('The last full pass walked :rate entries/s; the current scrape (:scrape torrents) needs about :needs s — with the budget at :budget s one poll walks it.',
            'Ostatni pełny przebieg szedł w tempie :rate wpisów/s; obecny scrape (torrentów: :scrape) potrzebuje około :needs s — przy budżecie :budget s wystarczy jedno odpytanie.'),
    'many': ('The last full pass walked :rate entries/s; the current scrape (:scrape torrents) needs about :needs s — with the budget at :budget s it takes :polls polls.',
             # "odpytań: :polls" (1.74.0): a count as a label reads right for every number — "potrzeba 3 odpytań" did not.
             'Ostatni pełny przebieg szedł w tempie :rate wpisów/s; obecny scrape (torrentów: :scrape) potrzebuje około :needs s — przy budżecie :budget s potrzeba odpytań: :polls.'),
}
add('settings', {
    'index_poll_estimate_one': _EST['one'],
    'index_poll_estimate_many': _EST['many'],
    'index_poll_estimate_none': ('No complete pass in the last week to measure the pace from — the estimate appears after the next one.',
        'W ostatnim tygodniu nie było pełnego przebiegu, z którego dałoby się zmierzyć tempo — szacunek pojawi się po następnym.'),
})
# the same two sentences for admin-settings.js, which redoes the last clause as the field changes
add('js.settings', {
    'poll_estimate_one': _EST['one'],
    'poll_estimate_many': _EST['many'],
})


# ── who loaded the inbound limit (net_state.json last_apply_source, in words) ─
# includes/netlimit.php netlimitSourceWords(): every source the code passes to netlimitApply(),
# netlimitApplyMonitor() and netlimitOff() — the last two add ':count' / ':off' — has its words here
# ('-' becomes '_', the ':suffix' a key of its own); a code without words prints as itself. The card
# reads "set <ago>, <these words>".
add('api.net.src', {
    'admin': ('from the panel (Apply limit)',
        'z panelu (Zastosuj limit)'),
    'admin.count': ('from the panel — counting only (Start counting), nothing dropped',
        'z panelu — tylko zliczanie (Zacznij zliczać), nic nie jest odrzucane'),
    'admin.off': ('from the panel — removed (Remove limit)',
        'z panelu — usunięty (Usuń limit)'),
    'auto': ('by the automatic mode',
        'przez tryb automatyczny'),
    'panic': ('by the emergency throttle (Throttle hard)',
        'przez awaryjne dławienie (Mocno zdław)'),
    'panic_restore': ('when the emergency throttle ended — the previous limit put back',
        'po końcu awaryjnego dławienia — przywrócony poprzedni limit'),
    'panic_restore.off': ('when the emergency throttle ended — removed again, as it was before',
        'po końcu awaryjnego dławienia — znów usunięty, jak przed nim'),
    'lists': ('when the IP lists changed — the janitor loaded the same limit with the new lists',
        'po zmianie list IP — janitor wczytał ten sam limit z nowymi listami'),
    'lists_off': ('when the IP lists were switched off — the janitor loaded the same limit without them',
        'po wyłączeniu list IP — janitor wczytał ten sam limit bez nich'),
    'probe': ('from the panel — a step of the stability probe (Apply)',
        'z panelu — krok testu stabilności (Zastosuj)'),
    'preview': ('a preview — nothing was loaded',
        'podgląd — nic nie zostało wczytane'),
})

add('js.net', {
    'set_by_noago': ('set :src',
        'ustawiony :src'),

    # the burst: a bucket too small for the limit passes less than the limit
    'burst_hint': ('Over the last hour the limiter let through :served pps on average under a limit of :limit (:served_pct %) while dropping :dropped_pct % of what arrived: bursts from the network card overflow a bucket of :burst packets before the limit is reached. A burst of about :suggested packets (≈ :ms ms at :limit pps) would let the full limit through without raising it — Settings → Inbound limit → Burst, then Traffic → Apply limit.',
        'W ostatniej godzinie ogranicznik przepuszczał średnio :served pps przy limicie :limit (:served_pct %), odrzucając :dropped_pct % tego, co przychodziło: serie pakietów z karty sieciowej przepełniają kubełek na :burst pakietów, zanim limit zostanie osiągnięty. Burst około :suggested pakietów (≈ :ms ms przy :limit pps) przepuściłby pełny limit bez jego podnoszenia — Ustawienia → Limit ruchu przychodzącego → Burst, potem Ruch → Zastosuj limit.'),
    'burst_open': ('Open Settings → Inbound limit',
        'Otwórz Ustawienia → Limit ruchu przychodzącego'),

    # handshakes per announce, from the statistics timeline
    'handshakes': ('Handshakes per announce',
        'Uzgodnienia połączenia na announce'),
    'handshakes_hour': (':v in the last hour',
        ':v w ostatniej godzinie'),
    'handshakes_day': (':v over 24 h',
        ':v w ciągu 24 h'),
    'handshakes_note': ('A client shakes hands (a UDP connect) before it announces, so about one per announce is normal; well above one means clients are repeating the handshake because their packets or the replies were dropped — at the inbound limit, for instance.',
        'Klient uzgadnia połączenie (UDP connect), zanim wyśle announce, więc około jednego na announce to norma; wyraźnie więcej znaczy, że klienci powtarzają uzgadnianie, bo ich pakiety albo odpowiedzi zostały odrzucone — na przykład na limicie ruchu przychodzącego.'),
    'handshakes_title': (':c handshakes for :a announces',
        'uzgodnienia: :c, announce: :a'),
})
