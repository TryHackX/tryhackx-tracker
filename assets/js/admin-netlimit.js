/**
 * "UDP traffic" card on the admin Traffic page — includes/netlimit.php on the server.
 *
 * What it has to make understandable: an admin who has never typed "packets per second" in their
 * life must be able to pick a sensible threshold. So the card never asks for a bare number:
 *   · three live counters (arriving / served / dropped) refreshed every few seconds;
 *   · a chart of the same three series, so the daily shape is visible;
 *   · the median / P95 / peak of the last week drawn ON the slider, with a plain-English sentence
 *     saying which value is suggested and below which one normal traffic starts being dropped.
 *
 * Everything that changes the firewall goes through the admin password modal and one endpoint
 * (admin/net_apply). "Preview ruleset" is the one action that does not — it renders and syntax-
 * checks the file on the server without loading it, which is exactly what you want to be able to
 * look at before you commit.
 *
 * Renders through textContent / createElement only: the helper's output (rule text, error messages)
 * is server-owned but still goes into the DOM as text, never as HTML.
 */
(function () {
    'use strict';

    const card = document.getElementById('net-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, fmtAgo } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const POLL_MS = 5000;          // live counters
    const CHART_MS = 60000;        // the series only grows one point per sample interval
    const PPS_MIN = parseInt(card.dataset.min, 10) || 1000;
    const PPS_MAX = parseInt(card.dataset.max, 10) || 1000000;
    const SLIDER_STEPS = 1000;

    // Mirrors NET_LOAD_BUSY in includes/netlimit.php — shown in the sentences, so it must not drift.
    const NL_BUSY_LOAD = 0.85;
    const RANGES = [['1h', '1h'], ['6h', '6h'], ['24h', '24h'], ['7d', '7d'], ['14d', '2w'], ['30d', '1m']];
    const SERIES = [
        { key: 'pps_total',  label: t('js.net.series_arriving'),  color: '#4a9eff', on: true },
        { key: 'pps_passed', label: t('js.net.series_served'),    color: '#66bb6a', on: true },
        { key: 'pps_capped', label: t('js.net.series_dropped'),   color: '#ff5252', on: true },
        { key: 'limit_pps',  label: t('js.net.series_limit'),     color: '#ffb74d', on: true, dash: [6, 4] },
    ];
    const STORE_RANGE = 'tracker_net_range';
    const STORE_COLLAPSE = 'tracker_net_collapsed';

    const num = (v) => (v == null || isNaN(v)) ? '—' : Math.round(v).toLocaleString();
    const pad2 = (n) => (n < 10 ? '0' : '') + n;
    const fmtTime = (ts) => { const d = new Date(ts * 1000); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()); };
    const fmtAxis = (v) => {
        if (v == null) return '';
        const a = Math.abs(v);
        if (a >= 1e6) return (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (a >= 1e3) return (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
        return String(Math.round(v));
    };
    const badge = (text, cls) => el('span', { className: 'wl-badge ' + (cls || 'wl-b-muted'), text: text });
    const kv = (label, value) => el('div', { className: 'wl-kv-item' }, [
        el('div', { className: 'wl-kv-label', text: label }),
        el('div', { className: 'wl-kv-value' }, value),
    ]);

    // ── logarithmic slider ───────────────────────────────────────────────────
    // 1 000 … 1 000 000 on a linear slider would put every realistic value in the first 5 % of the
    // track. On a log track one step is a constant *percentage*, which is how the number behaves.
    const LOG_MIN = Math.log(PPS_MIN), LOG_SPAN = Math.log(PPS_MAX) - Math.log(PPS_MIN);
    function posToPps(pos) {
        const raw = Math.exp(LOG_MIN + (LOG_SPAN * pos) / SLIDER_STEPS);
        const step = raw >= 100000 ? 5000 : (raw >= 10000 ? 1000 : 500);
        return Math.max(PPS_MIN, Math.min(PPS_MAX, Math.round(raw / step) * step));
    }
    function ppsToPos(pps) {
        const v = Math.max(PPS_MIN, Math.min(PPS_MAX, pps || PPS_MIN));
        return Math.round(((Math.log(v) - LOG_MIN) / LOG_SPAN) * SLIDER_STEPS);
    }
    const ppsToPct = (pps) => (ppsToPos(pps) / SLIDER_STEPS) * 100;

    // ── state ────────────────────────────────────────────────────────────────
    const state = {
        pps: parseInt(card.dataset.pps, 10) || 30000,
        burst: parseInt(card.dataset.burst, 10) || 100,
        port: parseInt(card.dataset.port, 10) || 6969,
        range: '24h',
        status: null,
        recommend: null,
        collapsed: false,
        pending: null,      // the action waiting for a password
        chart: null,
        pollTimer: null,
        chartTimer: null,
    };
    try {
        const saved = localStorage.getItem(STORE_RANGE);
        if (saved && RANGES.some(r => r[0] === saved)) state.range = saved;
        state.collapsed = localStorage.getItem(STORE_COLLAPSE) === '1';
    } catch (e) { /* private mode — defaults are fine */ }

    // ── live status ──────────────────────────────────────────────────────────
    // `force` is the difference between "the admin just opened/expanded this" and "the 5-second
    // timer fired": a background tab must not keep forking a helper process on the server, but a
    // deliberate load has to happen even in an embedded pane that always reports itself as hidden.
    let statusSeq = 0;      // requests started
    let statusPainted = 0;  // the newest answer already on screen
    let statusBusy = 0;     // seq of the request in flight, 0 when idle
    let statusBusyAt = 0;   // when it started, so a connection that never settles cannot wedge us
    let watchdog = null;

    async function loadStatus(force) {
        if (state.collapsed) return;
        if (!force && document.hidden) return;
        // One request at a time. This endpoint forks a helper, and PHP serialises requests that
        // share a session, so firing a new one every POLL_MS against a slower answer only builds a
        // queue — and with the old "is this still the newest request?" guard EVERY answer in that
        // queue was thrown away as stale, so the card sat on "Reading the firewall…" forever while
        // the server was answering perfectly well.
        if (statusBusy && (Date.now() - statusBusyAt) < 30000) return;
        const seq = ++statusSeq;
        statusBusy = seq; statusBusyAt = Date.now();
        armWatchdog();
        let j;
        try {
            j = await apiCall('admin/net_status');
        } catch (e) {
            // Returning quietly here is indistinguishable, on screen, from a server that never
            // answers: the loading state stays up and the admin has no idea anything went wrong.
            if (seq > statusPainted) { statusPainted = seq; renderFatal(t('js.net.status_unreachable', {err: (e && e.message ? e.message : t('js.net.network_error'))}), true); }
            return;
        } finally {
            if (statusBusy === seq) { statusBusy = 0; clearWatchdog(); }
        }
        // Repaint only with an answer NEWER than what is on screen. Comparing against statusSeq
        // (requests started) instead of statusPainted (answers shown) is what caused the hang.
        if (seq <= statusPainted) return;
        statusPainted = seq;
        if (!j || j.error && !j.configured) { renderFatal(j && j.error, true); return; }
        state.status = j;
        if (j.recommend) state.recommend = j.recommend;
        renderStatus(j);
        renderMarks();
        renderAdvice();
        renderEgressTune(j);
        // Repaint the zones, but NOT the input the operator may be in the middle of. Typing
        // "4" toward 45000 clamps state.pps to PPS_MIN; the poll then wrote 1000 over the "4"
        // with the caret at the end, and the next keystrokes appended to the wrong number.
        setPps(state.pps, ppsFieldBusy());
    }

    // A fetch that never settles runs neither the try nor the catch above, so nothing would ever
    // replace the loading state. This is the only branch left that can do it.
    function armWatchdog() {
        clearWatchdog();
        if (statusPainted) return;   // something real is already on screen; leave it there
        watchdog = setTimeout(() => {
            watchdog = null;
            if (statusPainted) return;
            renderFatal(t('js.net.status_timeout'), true);
        }, 15000);
    }
    function clearWatchdog() { if (watchdog) { clearTimeout(watchdog); watchdog = null; } }

    function renderFatal(msg, retry) {
        const grid = $('net-grid');
        grid.textContent = '';
        const parts = [badge(t('js.net.unavailable'), 'wl-b-bad'), ' ',
            el('span', { className: 'wl-small text-muted', text: msg || t('js.net.status_no_answer') })];
        if (retry) parts.push(el('div', {}, [el('button', {
            className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
            onclick: () => { statusPainted = 0; statusBusy = 0; renderLoading(); loadStatus(true); },
        }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' ' + t('js.net.try_again')])]));
        grid.appendChild(kv(t('js.net.firewall'), parts));
    }

    function renderLoading() {
        const grid = $('net-grid');
        grid.textContent = '';
        grid.appendChild(el('div', { className: 'wl-status-loading' }, [
            el('span', { className: 'spinner-border spinner-border-sm', role: 'status' }), ' ' + t('js.net.reading_firewall')]));
    }

    // "Not persistent" has three different causes and only one of them is the admin's to fix, so
    // the card says which one it is. The common one on a hardened box is not a fault at all: the
    // panel's PHP runs under systemd ProtectSystem, /etc is read-only inside that mount namespace,
    // and the janitor — an ordinary unit — writes the file within a minute instead.
    function persistNote(fw, j) {
        if (fw.dir_writable === false || j.persist_deferred) {
            return el('div', { className: 'wl-small text-muted' }, [
                el('i', { className: 'bi bi-clock-history' }),
                ' ' + t('js.net.persist_deferred', {path: (fw.file_path || '/etc/nftables.d')})]);
        }
        if (fw.include_ok === false) {
            return el('div', { className: 'wl-small text-warning', text: t('js.net.persist_no_include') });
        }
        if (fw.file_matches === false && fw.file_present) {
            return el('div', { className: 'wl-small text-warning', text:
                t('js.net.persist_differs', {what: (fw.file_mode === 'count' ? t('js.net.counting_only') : t('js.net.pps_value', {n: num(fw.file_pps)}))}) });
        }
        return el('div', { className: 'wl-small text-warning', text: t('js.net.persist_not_saved') });
    }

    function renderStatus(j) {
        const grid = $('net-grid');
        grid.textContent = '';
        const fw = j.firewall || {};
        const live = j.live || {};
        const pps = live.pps || {};
        const epps = live.epps || {};
        const cfg = j.configured || {};

        // 1. what the firewall is doing right now
        if (j.error) {
            grid.appendChild(kv(t('js.net.firewall'), [badge(t('js.net.unavailable'), 'wl-b-bad'), ' ',
                el('span', { className: 'wl-small text-muted', text: j.error })]));
        } else if (!fw.nft) {
            grid.appendChild(kv(t('js.net.firewall'), [badge(t('js.net.no_nftables'), 'wl-b-bad'), ' ',
                el('span', { className: 'wl-small text-muted', text: t('js.net.nft_missing') })]));
        } else if (fw.table && fw.mode === 'count') {
            // counters loaded, no drop rule: measuring, not throttling — say so unambiguously
            const parts = [badge(t('js.net.counting_only'), 'wl-b-pending'), ' ',
                el('span', { className: 'text-muted', text: t('js.net.port_nothing_dropped', {port: fw.port}) }),
                el('div', { className: 'wl-small text-muted', text: t('js.net.counting_rules_note') })];
            if (!fw.persistent) parts.push(persistNote(fw, j));
            grid.appendChild(kv(t('js.net.inbound_limit'), parts));
        } else if (fw.table) {
            const parts = [badge(t('js.net.pps_value', {n: num(fw.pps)}), 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: t('js.net.burst_port', {burst: num(fw.burst), port: fw.port}) })];
            if (!fw.persistent) parts.push(persistNote(fw, j));
            const src = j.last_apply && j.last_apply.source;
            if (src) parts.push(el('div', { className: 'wl-small text-muted', text: t('js.net.set_by', {src: src, ago: (j.last_apply.at ? t('js.net.ago', {t: fmtAgo(Math.floor(j.server_time - j.last_apply.at))}) : '')}) }));
            grid.appendChild(kv(t('js.net.inbound_limit'), parts));
        } else {
            grid.appendChild(kv(t('js.net.inbound_limit'), [badge(t('js.net.not_loaded'), 'wl-b-muted'), ' ',
                el('span', { className: 'wl-small text-muted', text: cfg.limit ? t('js.net.settings_say_on') : t('js.net.panel_drops_nothing') })]));
        }

        // 2. the three numbers this whole card exists for
        const hasLive = Object.keys(pps).length > 0;
        const stale = live.stale ? ' ' + t('js.net.last_known') : '';
        // "Arriving" is the honest word for it: our chain runs before everything else on this port,
        // so this is the raw arrival rate, whatever anybody else drops afterwards.
        grid.appendChild(kv(t('js.net.arriving') + stale, hasLive
            ? [el('strong', { text: num(pps.in_total) }), el('span', { className: 'nl-unit', text: ' pps' }),
               el('div', { className: 'wl-small text-muted', text: t('js.net.measured_over', {s: (live.span || 0)}) })]
            : [el('span', { className: 'text-muted', text: t('js.net.measuring') })]));
        // Our chain sits at `priority filter - 5`, i.e. BEFORE the distribution's filter table. So this
        // is what got past OUR rules — if somebody else's rule limits the same port downstream, the
        // tracker receives less than this, and saying "served to the tracker" would overstate it.
        const foreign = (fw.manual_rules || []).length > 0;
        const passedParts = hasLive
            ? [el('strong', { text: num(pps.in_passed) }), el('span', { className: 'nl-unit', text: ' pps' })]
            : [el('span', { className: 'text-muted', text: '—' })];
        if (hasLive && foreign) passedParts.push(el('div', { className: 'wl-small text-warning', text: t('js.net.foreign_rule_less') }));
        grid.appendChild(kv(foreign ? t('js.net.past_our_rules') : t('js.net.served_to_tracker'), passedParts));
        const dropped = hasLive ? (pps.in_capped || 0) : null;
        grid.appendChild(kv(t('js.net.dropped_by_limit'), hasLive
            ? [el('strong', { className: dropped > 0 ? 'text-warning' : '', text: num(dropped) }), el('span', { className: 'nl-unit', text: ' pps' }),
               el('div', { className: 'wl-small text-muted', text: dropped > 0
                   ? t('js.net.pct_never_reaches', {pct: Math.round((dropped / Math.max(1, pps.in_total)) * 100)})
                   : t('js.net.nothing_dropped_now') })]
            : [el('span', { className: 'text-muted', text: '—' })]));

        // 3. the other lever, side by side (we only show it — it is installed by hand)
        const eg = fw.egress || {};
        if (eg.table) {
            const hasE = Object.keys(epps).length > 0;
            grid.appendChild(kv(t('js.net.outbound_budget'), [
                badge(t('js.net.pps_value', {n: num(eg.pps)}), 'wl-b-ok'), ' ',
                el('span', { className: 'wl-small text-muted', text: hasE ? t('js.net.out_capped', {out: num((epps.announce_ok || 0) + (epps.passed_good || 0)), capped: num(epps.capped)}) : t('js.net.measuring') }),
                el('div', { className: 'wl-small text-muted', text: t('js.net.egress_note') }),
            ]));
        }

        // 4. automatic mode
        if (cfg.auto) {
            const a = j.auto_state || {};
            const parts = [badge(t('js.net.on'), 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: t('js.net.auto_target', {target: num(cfg.auto_target), min: num(cfg.auto_min), max: num(cfg.auto_max)}) })];
            if (a.over || a.under) parts.push(el('div', { className: 'wl-small text-muted', text: t('js.net.for_samples', {dir: (a.over ? t('js.net.above_target') : t('js.net.below_target')), n: Math.max(a.over, a.under), h: a.hysteresis}) }));
            if (a.last_move_at) parts.push(el('div', { className: 'wl-small text-muted', text: t('js.net.last_move', {move: (a.last_move || '?'), ago: t('js.net.ago', {t: fmtAgo(Math.floor(j.server_time - a.last_move_at))}), note: (a.note ? ' — ' + a.note : '')}) }));
            grid.appendChild(kv(t('js.net.automatic_mode'), parts));
        }

        // 5. panic countdown
        if (j.panic) {
            grid.appendChild(kv(t('js.net.emergency_throttle'), [
                badge(t('js.net.min_left', {n: Math.ceil(j.panic.seconds_left / 60)}), 'wl-b-warn'), ' ',
                el('span', { className: 'wl-small text-muted', text: j.panic.restore_enabled ? t('js.net.then_back_to', {n: num(j.panic.restore_pps)}) : t('js.net.then_removed') }),
                el('button', { className: 'btn btn-sm btn-outline-secondary ms-2', type: 'button', onclick: () => ask('restore') }, [el('i', { className: 'bi bi-arrow-counterclockwise' }), ' ' + t('js.net.undo_now')]),
            ]));
        }

        if (typeof j.load_per_core === 'number') {
            grid.appendChild(kv(t('js.net.machine_load'), [
                el('span', { text: t('js.net.per_core', {n: j.load_per_core.toFixed(2)}) }), ' ',
                el('span', { className: 'wl-small text-muted', text: j.cpus ? t('js.net.cores', {n: j.cpus}) : '' }),
            ]));
            // The processes behind the load: CPU of one core (like top) and resident memory, from
            // /proc, over the interval since the previous poll. Nothing here is a setting; it is the
            // answer to "is it the database or the tracker".
            if (j.procs && Object.keys(j.procs).length) {
                const wrap = el('div', { className: 'nl-procs' });
                Object.keys(j.procs).forEach(k => {
                    const p = j.procs[k];
                    if (!p.procs) return;
                    wrap.appendChild(el('span', { className: 'nl-proc', title: t('js.net.proc_title', { n: p.procs }) }, [
                        el('span', { className: 'nl-proc-name', text: p.label }),
                        el('span', { className: 'nl-proc-cpu', text: p.cpu_pct === null || p.cpu_pct === undefined ? '—' : p.cpu_pct.toFixed(p.cpu_pct < 10 ? 1 : 0) + ' %' }),
                        el('span', { className: 'nl-proc-rss', text: window.AdminCommon.fmtBytes(p.rss_bytes || 0) }),
                    ]));
                });
                grid.appendChild(kv(t('js.net.procs'), [wrap]));
            }
        }

        // The metadata worker's share of the machine, from two polls of raw counters.
        //
        // Machine load says the box is busy; it never says WHO. On this server the worker is the
        // heaviest thing after the tracker itself, and "is the fetcher eating the machine" was a
        // question the page could not answer.
        // THE TILE KEEPS THE LAST NUMBER IT HAD.
        //
        // A share of a CPU needs two readings a few seconds apart, so between them there is nothing
        // new to say — and saying "measuring…" replaces a number the reader was looking at with a
        // word, every time the card refreshes. The measurement carries on in the background and the
        // figure is swapped in when it is ready; only a worker that has never been measured shows
        // anything else.
        const wcpu = workerCpuShare(j.worker_cpu, j.cpus);
        if (wcpu !== null) lastWorkerCpu = wcpu;
        const shown = wcpu || lastWorkerCpu;
        if (shown) {
            grid.appendChild(kv(t('js.net.metadata_worker'), [
                el('span', { className: shown.core > 90 ? 'text-warning' : '',
                             text: t('js.net.pct_of_core', {n: shown.core.toFixed(0)}) }), ' ',
                el('span', { className: 'wl-small text-muted',
                             text: t('js.net.pct_of_box', {box: shown.box.toFixed(1), s: shown.window}) }),
            ]));
        } else if (j.worker_cpu) {
            // First reading of the session: there is genuinely nothing to show yet, and a row that
            // appears from nowhere a moment later is worse than a row that says what it is waiting for.
            grid.appendChild(kv(t('js.net.metadata_worker'), [
                el('span', { className: 'wl-small text-muted', text: t('js.net.worker_first_reading') }),
            ]));
        } else if (j.worker_cpu === null) {
            lastWorkerCpu = null;
            grid.appendChild(kv(t('js.net.metadata_worker'), [
                el('span', { className: 'wl-small text-muted', text: t('js.net.worker_not_here') }),
            ]));
        }

        renderNotes(j);
        $('net-updated').textContent = t('js.net.port_updated', {port: (fw.port || cfg.port), time: new Date().toLocaleTimeString()});
    }

    /** Warnings that need a sentence, not a tile: foreign rules on the same port, persistence, errors. */
    /**
     * Two readings of the worker's counters into a share of a CPU.
     *
     * The previous reading is kept here rather than on the server for the reason the OpenTracker card
     * documents: computing it server-side would mean sleeping inside a web request. The guards are
     * the ones that can actually fire — a restart (the pid or its start time changed, so the counter
     * went backwards), a window too short to divide by, and a machine whose core count is unknown.
     * A ">100 % of the box" clamp is not among them: numerator and denominator come from the same
     * /proc/stat clock, so that cannot happen.
     */
    const MIN_WINDOW_S = 8;
    let prevWorker = null;
    // The last share actually measured, so a poll that lands mid-window redraws the tile with the
    // figure it already had instead of blanking it.
    let lastWorkerCpu = null;
    function workerCpuShare(now, cpus) {
        if (!now || !now.pid || !now.total) { prevWorker = null; return null; }
        const prev = prevWorker;
        prevWorker = now;
        if (!prev) return null;
        // A restart resets the process's own clock; carrying the old reading across it would show a
        // enormous negative or a nonsensical spike.
        if (prev.pid !== now.pid || prev.started !== now.started) { lastWorkerCpu = null; return null; }
        const dProc = now.ticks - prev.ticks;
        const dTotal = now.total - prev.total;
        if (dTotal <= 0 || dProc < 0 || dProc > dTotal) return null;
        const hz = now.hz || 100;
        const windowS = dTotal / hz / (cpus || 1);
        // At 100 Hz and a five-second poll, a worker using a few per cent of a core is quantised into
        // steps of tens of per cent. Waiting for a wider window is the difference between a number and
        // a jitter generator.
        if (windowS < MIN_WINDOW_S) { prevWorker = prev; return null; }
        const box = 100 * dProc / dTotal;
        return { box, core: box * (cpus || 1), window: Math.round(windowS) };
    }

    function renderNotes(j) {
        const box = $('net-notes');
        box.textContent = '';
        const fw = j.firewall || {};
        const rules = fw.manual_rules || [];
        if (rules.length) {
            const list = el('ul', { className: 'nl-note-list' });
            rules.forEach(r => {
                list.appendChild(el('li', {}, [
                    el('code', { text: r.family + ' ' + r.table + ' / ' + r.chain }), ' ',
                    el('span', { className: 'text-muted', text: r.rule }),
                    el('div', { className: 'wl-small' }, [
                        el('span', { className: 'text-muted', text: t('js.net.remove_yourself') + ' ' }),
                        el('code', { text: r.undo }),
                    ]),
                ]));
            });
            box.appendChild(el('div', { className: 'nl-note nl-note-info' }, [
                el('div', {}, [el('i', { className: 'bi bi-info-circle' }), el('strong', { text: ' ' + t('js.net.foreign_rule_title') }),
                    el('span', { text: ' ' + t('js.net.foreign_rule_body') })]),
                list,
            ]));
        }
        if (j.last_error) {
            box.appendChild(el('div', { className: 'nl-note nl-note-bad' }, [
                el('i', { className: 'bi bi-exclamation-triangle' }),
                el('span', { text: ' ' + t('js.net.last_failure', {err: j.last_error, ago: (j.last_error_at ? ' (' + t('js.net.ago', {t: fmtAgo(Math.floor(j.server_time - j.last_error_at))}) + ')' : '')}) }),
            ]));
        }
        // The counters live in the firewall: with no table of ours there is nothing to count, so the
        // monitor would quietly record zeros and the suggestion would be meaningless. Say that, and
        // offer the one action that fixes it.
        if (j.configured && j.configured.monitor && fw.nft && !fw.table && !j.error) {
            box.appendChild(el('div', { className: 'nl-note nl-note-warn' }, [
                el('div', {}, [el('i', { className: 'bi bi-exclamation-triangle' }),
                    el('strong', { text: ' ' + t('js.net.monitor_not_counting_title') }),
                    el('span', { text: ' ' + t('js.net.monitor_not_counting_body') })]),
                el('button', { className: 'btn btn-sm btn-outline-info mt-2', type: 'button',
                               onclick: () => ask('monitor') }, [el('i', { className: 'bi bi-activity' }), ' ' + t('js.net.start_counting')]),
            ]));
        }
        if (j.configured && j.configured.monitor && j.last_tick_at && (j.server_time - j.last_tick_at) > 300) {
            box.appendChild(el('div', { className: 'nl-note nl-note-warn' }, [
                el('i', { className: 'bi bi-clock-history' }),
                el('span', { text: ' ' + t('js.net.janitor_stale', {t: fmtAgo(Math.floor(j.server_time - j.last_tick_at))}) }),
            ]));
        }
    }

    // ── slider, marks, advice ────────────────────────────────────────────────
    // A ruler for the track. Decades every 1/3 of the way (three decades, 1 000 … 1 000 000) with
    // 2/3/5 in between, so a value can be read off the slider instead of guessed from the thumb.
    // Drawn once — it never changes.
    const SCALE_TICKS = [1000, 2000, 3000, 5000, 10000, 20000, 30000, 50000,
                         100000, 200000, 300000, 500000, 1000000];
    const SCALE_MAJOR = [1000, 10000, 100000, 1000000];
    const scaleLabel = (v) => v >= 1e6 ? (v / 1e6) + 'M' : (v / 1e3) + 'k';

    function renderScale() {
        const host = $('net-scale');
        if (!host) return;
        host.textContent = '';
        const inRange = SCALE_TICKS.filter(v => v >= PPS_MIN && v <= PPS_MAX);
        inRange.forEach((v, i) => {
            const major = SCALE_MAJOR.includes(v);
            const t = el('span', { className: 'nl-scale-tick' + (major ? ' nl-scale-major' : '')
                + (i === 0 ? ' nl-scale-first' : '') + (i === inRange.length - 1 ? ' nl-scale-last' : '') });
            t.style.left = ppsToPct(v) + '%';
            if (major) t.appendChild(el('span', { className: 'nl-scale-label', text: scaleLabel(v) }));
            host.appendChild(t);
        });
    }

    function renderMarks() {
        const marks = $('net-marks');
        marks.textContent = '';
        const r = state.recommend;
        if (!r || !r.samples) return;
        const defs = [
            [t('js.net.mark_median'), r.median, 'nl-mark-median', t('js.net.mark_median_title')],
            ['P95', r.p95, 'nl-mark-p95', t('js.net.mark_p95_title')],
            [t('js.net.mark_peak'), r.peak, 'nl-mark-peak', t('js.net.mark_peak_title')],
        ].filter(d => d[1]).map(([label, value, cls, title]) => ({
            label, value, cls, title, pct: ppsToPct(value),
        })).sort((a, b) => a.pct - b.pct);

        // On a saturated port these three land within a fraction of a percent of each other — here
        // the median is 169 919, P95 172 407 and the peak 173 423, which on a logarithmic track is
        // the same pixel. Three labels at one x drew an unreadable smudge, so anything that would
        // collide with the mark to its left gets a row of its own, and its tick grows down to meet
        // it. Nothing is dropped: all three values still matter when picking a limit.
        const LABEL_GAP_PCT = 7;
        const MARK_ROW_REM = 1.05;   // taller than the 0.68rem label's own line box, or rows clip
        const rowEnds = [];
        defs.forEach(d => {
            let row = 0;
            while (rowEnds[row] !== undefined && d.pct - rowEnds[row] < LABEL_GAP_PCT) row++;
            rowEnds[row] = d.pct;
            d.row = row;
        });

        // Where the machine itself started to struggle. It belongs on the SAME ruler as the traffic
        // marks, because that is the comparison being made: this much traffic, that much load.
        const lc = (state.status && state.status.load_curve) || null;
        if (lc && lc.busy_pps) {
            const pctB = ppsToPct(lc.busy_pps);
            const b = el('span', { className: 'nl-mark nl-mark-busy' + (pctB > 72 ? ' nl-mark-flip' : ''),
                title: t('js.net.busy_title', {load: NL_BUSY_LOAD, n: num(lc.busy_pps)}) });
            b.style.left = pctB + '%';
            b.style.setProperty('--nl-tick-h', '2.6rem');
            b.appendChild(el('span', { className: 'nl-mark-label', text: t('js.net.busy') }));
            b.querySelector('.nl-mark-label').style.top = (0.15 + 3 * MARK_ROW_REM) + 'rem';
            marks.appendChild(b);
        }

        defs.forEach(d => {
            // Past ~72 % of the track a label placed to the right would hang off the card, so it
            // swaps to the left of its own tick.
            const flip = d.pct > 72;
            const m = el('span', { className: 'nl-mark ' + d.cls + (flip ? ' nl-mark-flip' : ''),
                                   title: d.title + ': ' + num(d.value) + ' pps' });
            m.style.left = d.pct + '%';
            // The tick reaches down to its own row so you can tell which label belongs to which
            // mark; the label sits BESIDE it, so the bar never crosses the text.
            m.style.setProperty('--nl-tick-h', (0.5 + d.row * MARK_ROW_REM) + 'rem');
            const lab = el('span', { className: 'nl-mark-label', text: d.label });
            if (d.row) lab.style.top = (0.15 + d.row * MARK_ROW_REM) + 'rem';
            m.appendChild(lab);
            marks.appendChild(m);
        });
    }

    function renderAdvice() {
        const box = $('net-advice');
        box.textContent = '';
        const r = state.recommend;
        if (!r) return;
        box.appendChild(el('span', { text: r.text || '' }));
        if (r.samples && r.suggested) {
            const cur = state.pps;
            // `r.floor` is derived from ARRIVALS. In a flood that is the swarm, not the traffic the
            // tracker serves, so warning "you would be dropping traffic the tracker normally serves"
            // against it was simply false: here it fired at 48 000 pps while only 39 800 pps was
            // getting through. When a live rate exists, judge against THAT.
            // A limit ABOVE the point where the machine was already struggling is not protection,
            // it is a number that will never fire before the box does. Say so — that is the whole
            // reason for measuring load next to traffic.
            const lc2 = (state.status && state.status.load_curve) || null;
            if (lc2 && lc2.busy_pps && cur > lc2.busy_pps) {
                box.appendChild(el('div', { className: 'text-warning wl-small', text:
                    t('js.net.advice_busy', {load: NL_BUSY_LOAD, busy: num(lc2.busy_pps), cur: num(cur)}) }));
            } else if (lc2 && !lc2.busy_pps && lc2.why) {
                box.appendChild(el('div', { className: 'wl-small text-muted', text: t('js.net.load_study', {why: lc2.why}) }));
            }
            const ref = inboundReference();
            if (ref > 0) {
                if (cur < ref) {
                    box.appendChild(el('div', { className: 'text-danger wl-small', text: t('js.net.advice_cutting', {cur: num(cur), ref: num(ref)}) }));
                } else if (cur < ref * ZONE_HEADROOM) {
                    box.appendChild(el('div', { className: 'text-warning wl-small', text: t('js.net.advice_headroom', {cur: num(cur), ref: num(ref)}) }));
                } else if (cur > r.peak * 2 && r.peak > 0) {
                    box.appendChild(el('div', { className: 'text-muted wl-small', text: t('js.net.advice_far_above', {cur: num(cur)}) }));
                }
            } else if (cur < r.floor) {
                box.appendChild(el('div', { className: 'text-warning wl-small', text: t('js.net.adv_dropping_arriving', {n: num(cur)}) }));
            } else if (cur > r.peak * 2 && r.peak > 0) {
                box.appendChild(el('div', { className: 'text-muted wl-small', text: t('js.net.adv_never_trigger', {n: num(cur)}) }));
            }
        }
    }

    // ── risk zones painted onto a slider track ───────────────────────────────
    // A number alone does not tell you whether it is a sensible one. Both of these sliders have the
    // same shape of danger and it is at the LOW end: a budget under the rate that is genuinely
    // happening cuts into traffic the tracker is really carrying. So the track is painted red below
    // the measured demand, amber for the little headroom above it, and left alone past that.
    //
    // The reference rate is measured, never guessed — with nothing measured yet the zones simply do
    // not appear, because inventing a threshold would be worse than showing none.
    const ZONE_HEADROOM = 1.3;   // amber up to 30 % over the measured rate: no room for a spike
    const ZONE_CEILING_SLACK = 1.15;   // amber just over the machine's ceiling, red past that

    // Two different dangers, one track:
    //   LOW end  — a budget under what is genuinely flowing cuts into traffic that is really there;
    //   HIGH end — a budget above the rate at which THIS machine was already struggling is not
    //              protection at all, because the box gives out before the rule ever fires.
    // The high end only appears once the load study has something to say. Colouring it from a guess
    // would be worse than leaving it grey, which is exactly what happens with no measurement.
    function zoneCss(low, ceiling) {
        const stops = [];
        const put = (colour, fromPct, toPct) => {
            if (toPct <= fromPct) return;
            stops.push(colour + ' ' + fromPct + '%', colour + ' ' + toPct + '%');
        };
        const RED = 'rgba(255,82,82,0.42)', AMBER = 'rgba(255,183,77,0.34)', PLAIN = '#1c232b';
        let cursor = 0;
        if (low && low > 0) {
            const red = ppsToPct(low);
            const amber = ppsToPct(Math.min(PPS_MAX, low * ZONE_HEADROOM));
            put(RED, 0, red); put(AMBER, red, amber); cursor = amber;
        }
        let hiAmber = 100, hiRed = 100;
        if (ceiling && ceiling > 0) {
            hiAmber = ppsToPct(ceiling);
            hiRed = ppsToPct(Math.min(PPS_MAX, ceiling * ZONE_CEILING_SLACK));
        }
        put(PLAIN, cursor, Math.max(cursor, hiAmber));
        if (ceiling && ceiling > 0) { put(AMBER, Math.max(cursor, hiAmber), hiRed); put(RED, hiRed, 100); }
        if (!stops.length) return '';
        return 'linear-gradient(90deg, ' + stops.join(', ') + ')';
    }

    function paintSlider(rangeEl, pps, reference, ceiling) {
        if (!rangeEl) return;
        rangeEl.style.setProperty('--nl-fill', ppsToPct(pps) + '%');
        const zones = zoneCss(reference, ceiling);
        if (zones) rangeEl.style.setProperty('--nl-zones', zones);
        else rangeEl.style.removeProperty('--nl-zones');
        // The thumb takes the colour of the zone it is standing in, so the state is readable at a
        // glance without reading the sentence underneath.
        const tooLow  = !!reference && pps < reference;
        const tight   = !!reference && pps >= reference && pps < reference * ZONE_HEADROOM;
        const overTop = !!ceiling && pps > ceiling * ZONE_CEILING_SLACK;
        const nearTop = !!ceiling && !overTop && pps > ceiling;
        rangeEl.classList.toggle('nl-in-danger', tooLow || overTop);
        rangeEl.classList.toggle('nl-in-caution', !tooLow && !overTop && (tight || nearTop));
    }

    /** The rate at which this machine was measured to be struggling, or 0 when the study has none. */
    function machineCeiling() {
        const lc = (state.status && state.status.load_curve) || null;
        return (lc && lc.busy_pps) ? lc.busy_pps : 0;
    }

    // ── outbound budget (table inet ottrack) ─────────────────────────────────
    // A tracker answers what it accepts, so this is the other half of the same decision — and the
    // half that decides whether www and SSH stay usable while a swarm is shouting. The helper could
    // always set it; the card only ever displayed it.
    const eState = { pps: 50000, loaded: false, ref: 0 };

    function egressMeasured(j) {
        // What the tracker is actually sending: replies to announces plus everything else that got
        // past the budget. `capped` is what the budget already refused, so it is NOT demand met —
        // but it IS demand, so it belongs in the reference the zones are drawn from.
        const e = (j.live && j.live.epps) || {};
        if (!Object.keys(e).length) return 0;
        return (e.announce_ok || 0) + (e.passed_good || 0) + (e.capped || 0);
    }

    function egressEnabled(on) {
        const ids = ['net-epps-input', 'net-epps-range', 'btn-net-esuggest', 'btn-net-eapply'];
        ids.forEach(id => { const n = $(id); if (n) n.disabled = !on; });
    }

    function renderEgressTune(j) {
        const wrap = $('net-egress-tune');
        if (!wrap) return;
        const eg = (j.firewall && j.firewall.egress) || {};
        // The block is in the page from the first paint, so "no rule yet" is a state it renders
        // rather than a reason to vanish: a section that appears a second after the reader arrives
        // moves everything under their cursor, and one that never appears looks like a missing
        // feature instead of an absent rule.
        wrap.classList.remove('nl-tune-pending');
        if (!eg.table) {
            egressEnabled(false);
            const adv = $('net-eadvice');
            if (adv) {
                adv.textContent = '';
                adv.appendChild(el('div', { className: 'nl-note nl-note-info', text: t('js.net.egress_no_rule') }));
            }
            return;
        }
        egressEnabled(true);
        eState.ref = egressMeasured(j);
        // No invented fallback here either. If the helper reports a budget that does not parse, the
        // honest state is "we do not know what is in force", which is the disabled state — not 50000,
        // a real-looking number that was never read from anything.
        const epps = parseInt(eg.pps, 10);
        if (!eState.loaded && epps > 0) { eState.pps = epps; eState.loaded = true; setEpps(eState.pps); }
        else if (!eState.loaded) { egressEnabled(false); }
        else paintSlider($('net-epps-range'), eState.pps, eState.ref, machineCeiling());
        renderEgressScale();
        renderEgressAdvice(eg);
    }

    function renderEgressScale() {
        const host = $('net-escale');
        if (!host) return;
        host.textContent = '';
        const inRange = SCALE_TICKS.filter(v => v >= PPS_MIN && v <= PPS_MAX);
        inRange.forEach((v, i) => {
            const major = SCALE_MAJOR.includes(v);
            const t = el('span', { className: 'nl-scale-tick' + (major ? ' nl-scale-major' : '')
                + (i === 0 ? ' nl-scale-first' : '') + (i === inRange.length - 1 ? ' nl-scale-last' : '') });
            t.style.left = ppsToPct(v) + '%';
            if (major) t.appendChild(el('span', { className: 'nl-scale-label', text: scaleLabel(v) }));
            host.appendChild(t);
        });
        const marks = $('net-emarks');
        if (!marks) return;
        marks.textContent = '';
        if (!eState.ref) return;
        const pctE = ppsToPct(eState.ref);
        const m = el('span', { className: 'nl-mark nl-mark-median' + (pctE > 72 ? ' nl-mark-flip' : ''),
                               title: t('js.net.egress_measured_title', {n: num(eState.ref)}) });
        m.style.left = pctE + '%';
        m.style.setProperty('--nl-tick-h', '0.5rem');
        m.appendChild(el('span', { className: 'nl-mark-label', text: t('js.net.egress_sending_now') }));
        marks.appendChild(m);
    }

    function renderEgressAdvice(eg) {
        const box = $('net-eadvice');
        if (!box) return;
        box.textContent = '';
        const inForce = parseInt(eg.pps, 10) || 0;
        const lines = [];
        lines.push(el('div', { text: t('js.net.egress_in_force', {n: num(inForce)}) }));
        // A budget that is live but missing from the file is gone at the next reboot, and there is
        // no way to find that out except by rebooting. So say it here instead.
        if (eg.file === false) {
            lines.push(el('div', { className: 'text-warning', text: t('js.net.egress_no_file') }));
        } else if (eg.file_matches === false) {
            lines.push(el('div', { className: 'text-warning', text: t('js.net.egress_file_mismatch', {n: num(eg.file_pps || 0)}) }));
        }
        if (!eState.ref) {
            lines.push(el('div', { className: 'text-muted', text: t('js.net.egress_no_rate') }));
        } else {
            lines.push(el('div', { text: t('js.net.egress_measured_now', {n: num(eState.ref)}) }));
            if (eState.pps < eState.ref) {
                lines.push(el('div', { className: 'text-danger', text: t('js.net.egress_too_low', {n: num(eState.pps)}) }));
            } else if (eState.pps < eState.ref * ZONE_HEADROOM) {
                lines.push(el('div', { className: 'text-warning', text: t('js.net.egress_tight', {n: num(eState.pps)}) }));
            }
        }
        lines.forEach(l => box.appendChild(l));
    }

    function setEpps(v, fromInput) {
        eState.pps = Math.max(PPS_MIN, Math.min(PPS_MAX, parseInt(v, 10) || PPS_MIN));
        if (!fromInput) $('net-epps-input').value = eState.pps;
        $('net-epps-range').value = ppsToPos(eState.pps);
        paintSlider($('net-epps-range'), eState.pps, eState.ref, machineCeiling());
        const eg = (state.status && state.status.firewall && state.status.firewall.egress) || {};
        renderEgressAdvice(eg);
    }

    // What the inbound limit must not cut into: the rate currently GETTING THROUGH. Arrivals are
    // the swarm shouting, not demand — using them here would paint the whole track red and tell the
    // admin nothing. Not everything getting through is demand the tracker must serve either, which
    // is why the sentence under the slider says "currently getting through" and not "demand".
    function inboundReference() {
        const pps = (state.status && state.status.live && state.status.live.pps) || {};
        return pps.in_passed || 0;
    }

    /**
     * Is the operator holding the pps field right now?
     *
     * `fromInput` on setPps() already means "do not write the box", so this reuses that flag rather
     * than adding a second concept. Focus is the honest test: a blurred field is not being edited,
     * and a focused one is — whatever its value happens to be at this instant.
     */
    function ppsFieldBusy() {
        const el = $('net-pps-input');
        return !!el && document.activeElement === el;
    }

    function setPps(v, fromInput) {
        state.pps = Math.max(PPS_MIN, Math.min(PPS_MAX, parseInt(v, 10) || PPS_MIN));
        if (!fromInput) $('net-pps-input').value = state.pps;
        const range = $('net-pps-range');
        range.value = ppsToPos(state.pps);
        // WebKit cannot fill the rail up to the thumb on its own (Gecko has ::-moz-range-progress);
        // the CSS reads --nl-fill as a gradient stop and --nl-zones as the layer beneath it.
        paintSlider(range, state.pps, inboundReference(), machineCeiling());
        renderAdvice();
    }

    // ── chart ────────────────────────────────────────────────────────────────
    function buildChart() {
        if (typeof uPlot === 'undefined') return null;
        const host = $('net-chart');
        const w = Math.max(200, host.clientWidth || card.clientWidth || 800);
        const axisBase = () => ({ stroke: '#8a8a9a', font: '11px system-ui, -apple-system, Segoe UI, sans-serif', ticks: { stroke: '#2a2a3a', width: 1 }, grid: { stroke: 'rgba(255,255,255,0.06)', width: 1 } });
        const series = [{ label: t('js.net.chart_time'), value: (u, v) => v == null ? '—' : fmtTime(v) }];
        /**
         * Draw a marker ONLY where the line cannot show the value on its own.
         *
         * Two hooks, because uPlot splits the job: `points.show` answers "any markers at all?" with a
         * BOOLEAN, and `points.filter` answers "which ones?" with an index array. Returning the array
         * from `show` looked like it worked and did the opposite — an array is truthy, so a single
         * isolated sample switched markers on for the entire series. That is the speckling that
         * appeared at 7d and 2w and not at 24h: those ranges happen to contain one isolated point.
         *
         * uPlot's own default for `show` turns markers on once the average pixel gap passes a
         * threshold, so the same chart is clean on a laptop and covered in dots on a wide monitor —
         * a decision about the window, not about the data.
         *
         * A genuinely isolated sample — a value with a gap on both sides — still needs a marker, or it
         * renders as nothing at all: `spanGaps: false` means there is no line segment to draw it on.
         * `filter` returns null rather than an empty array when there are none, because the draw call
         * is `(show || filter) && paint(filter)` and `[]` is truthy. The cursor's own hover point is
         * drawn separately by uPlot and is untouched.
         */
        const isolatedIdxs = (u, seriesIdx) => {
            const data = u.data[seriesIdx];
            const s = u.series[seriesIdx];
            if (s._isoFor === data) return s._isoIdxs;          // recomputed only when the data changes
            const out = [];
            for (let i = 0; i < data.length; i++) {
                if (data[i] == null) continue;
                const prev = i > 0 ? data[i - 1] : null;
                const next = i < data.length - 1 ? data[i + 1] : null;
                if (prev == null && next == null) out.push(i);
            }
            s._isoFor = data; s._isoIdxs = out;
            return out;
        };
        const isolatedShow   = (u, seriesIdx) => isolatedIdxs(u, seriesIdx).length > 0;
        const isolatedFilter = (u, seriesIdx) => { const a = isolatedIdxs(u, seriesIdx); return a.length ? a : null; };
        SERIES.forEach(s => series.push({
            label: s.label, stroke: s.color, width: 1.5, show: s.on, spanGaps: false,
            dash: s.dash || undefined, points: { show: isolatedShow, filter: isolatedFilter, size: 5 }, value: (u, v) => num(v),
        }));
        return new uPlot({
            width: w, height: 200,
            scales: { x: { time: true }, y: { auto: true, range: (u, min, max) => [0, Math.max(1, (isFinite(max) ? max : 0) * 1.05)] } },
            axes: [Object.assign(axisBase(), { space: 70, values: (u, vals) => vals.map(v => {
                const d = new Date(v * 1000);
                return (d.getHours() === 0 && d.getMinutes() === 0) ? pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) : pad2(d.getHours()) + ':' + pad2(d.getMinutes());
            }) }),
                   Object.assign(axisBase(), { size: 56, values: (u, vals) => vals.map(fmtAxis) })],
            series: series,
            legend: { live: true },
            cursor: { x: true, y: false, drag: { x: false, y: false } },
            padding: [8, 8, 0, 4],
        }, [[], [], [], [], []], host);
    }

    // Which chart request is the current one. Switching range twice quickly used to leave the
    // slower FIRST answer painted over the second, so the chart showed a range the buttons did not.
    let chartSeq = 0;

    async function loadChart(force) {
        if (state.collapsed) return;
        if (!force && document.hidden) return;
        if (!state.chart) state.chart = buildChart();
        if (!state.chart) return;
        const seq = ++chartSeq;
        const asked = state.range;
        let j;
        try { j = await apiCall('admin/net_samples&range=' + encodeURIComponent(asked)); } catch { return; }
        // A late answer for a range nobody is looking at any more is thrown away, not drawn.
        if (seq !== chartSeq) return;
        if (!j || !j.ok) return;
        const s = j.series || {};
        const data = [s.t || []].concat(SERIES.map(def => (s[def.key] || []).map(v => (v == null ? null : Number(v)))));
        state.chart.setData(data);
        const empty = !(s.t && s.t.length);
        $('net-chart').classList.toggle('nl-chart-empty', empty);
        $('net-chart').dataset.empty = empty
            ? (j.monitor ? t('js.net.chart_no_samples') : t('js.net.chart_monitor_off'))
            : '';
    }

    function renderRanges() {
        const box = $('net-ranges');
        box.textContent = '';
        RANGES.forEach(([key, label]) => {
            const b = el('button', { className: 'tl-range-btn' + (key === state.range ? ' active' : ''), type: 'button', text: label });
            b.dataset.range = key;
            box.appendChild(b);
        });
    }

    // ── actions ──────────────────────────────────────────────────────────────
    const MODAL_COPY = {
        apply: {
            title: t('js.net.modal_apply_title'),
            ok: t('js.net.modal_apply_ok'),
            okClass: 'btn-outline-success',
            text: () => t('js.net.modal_apply_text', {pps: num(state.pps), port: state.port, burst: num(state.burst)}),
            undo: () => t('js.net.modal_apply_undo'),
            undoCode: () => 'sudo nft delete table inet ottrack_in && sudo rm /etc/nftables.d/ottrack-in.nft',
        },
        off: {
            title: t('js.net.modal_off_title'),
            ok: t('js.net.modal_off_ok'),
            okClass: 'btn-outline-warning',
            text: () => t('js.net.modal_off_text'),
            undo: () => t('js.net.modal_off_undo'),
            undoCode: () => '',
        },
        panic: {
            title: t('js.net.modal_panic_title'),
            ok: t('js.net.modal_panic_ok'),
            okClass: 'btn-outline-danger',
            text: () => t('js.net.modal_panic_text', {port: state.port}),
            undo: () => t('js.net.modal_panic_undo'),
            undoCode: () => '',
        },
        egress: {
            title: t('js.net.modal_egress_title'),
            ok: t('js.net.modal_egress_ok'),
            okClass: 'btn-outline-success',
            text: () => t('js.net.modal_egress_text', {n: num(eState.pps)}),
            undo: () => t('js.net.modal_egress_undo'),
            undoCode: () => 'sudo nft -f /etc/nftables.d/ottrack.nft',
        },
        monitor: {
            title: t('js.net.modal_monitor_title'),
            ok: t('js.net.modal_monitor_ok'),
            okClass: 'btn-outline-info',
            text: () => t('js.net.modal_monitor_text', {port: state.port}),
            undo: () => t('js.net.modal_apply_undo'),
            undoCode: () => 'sudo nft delete table inet ottrack_in && sudo rm /etc/nftables.d/ottrack-in.nft',
        },
        restore: {
            title: t('js.net.modal_restore_title'),
            ok: t('js.net.modal_restore_ok'),
            okClass: 'btn-outline-success',
            text: () => t('js.net.modal_restore_text'),
            undo: () => '',
            undoCode: () => '',
        },
    };

    function ask(op) {
        const copy = MODAL_COPY[op];
        if (!copy) return;
        state.pending = op;
        $('net-modal-title').textContent = copy.title;
        $('net-modal-text').textContent = copy.text();
        const undo = $('net-modal-undo');
        undo.textContent = '';
        if (copy.undo()) {
            const d = el('div', { className: 'wl-small text-muted' }, [el('span', { text: copy.undo() })]);
            if (copy.undoCode()) d.appendChild(el('code', { text: copy.undoCode() }));
            undo.appendChild(d);
        }
        const okBtn = $('net-confirm-ok');
        okBtn.className = 'btn btn-sm ' + copy.okClass;
        okBtn.textContent = '';
        okBtn.appendChild(el('i', { className: 'bi bi-check-lg' }));
        okBtn.appendChild(document.createTextNode(' ' + copy.ok));
        $('net-confirm-alert').textContent = '';
        $('net-confirm-password').value = '';
        const modal = bootstrap.Modal.getOrCreateInstance($('netConfirmModal'));
        modal.show();
        setTimeout(() => $('net-confirm-password').focus(), 300);
    }

    async function runPending(e) {
        e.preventDefault();
        const op = state.pending;
        if (!op) return;
        const alert = $('net-confirm-alert');
        const btn = $('net-confirm-ok');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.net.working');
        alert.textContent = '';
        const body = { op: op, password: $('net-confirm-password').value };
        if (op === 'apply') { body.pps = state.pps; body.burst = state.burst; body.port = state.port; }
        if (op === 'monitor') { body.port = state.port; }
        if (op === 'egress') { body.pps = eState.pps; }
        try {
            const r = await apiCall('admin/net_apply', 'POST', body);
            if (r.success) {
                bootstrap.Modal.getOrCreateInstance($('netConfirmModal')).hide();
                showToast(r.message || t('js.net.done'), 'success');
                if ((op === 'apply' || op === 'monitor') && r.persistent === false) {
                    showToast(t('js.net.not_persistent'), 'warning');
                }
                state.pending = null;
                loadStatus();
            } else {
                alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || t('js.net.failed') }));
                if (r.output) alert.appendChild(el('pre', { className: 'nl-preview nl-preview-sm', text: r.output }));
            }
        } catch {
            alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: t('js.net.network_error_2') }));
        }
        btn.disabled = false;
        btn.innerHTML = orig;
    }

    async function preview() {
        const btn = $('btn-net-preview');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.net.rendering');
        try {
            const r = await apiCall('admin/net_apply', 'POST', { op: 'preview', pps: state.pps, burst: state.burst, port: state.port });
            if (r.success) {
                $('net-preview-file').textContent = r.file || '/etc/nftables.d/ottrack-in.nft';
                $('net-preview-body').textContent = r.ruleset || '';
                bootstrap.Modal.getOrCreateInstance($('netPreviewModal')).show();
            } else {
                showToast(r.error || t('js.net.preview_failed'), 'error');
            }
        } catch { showToast(t('js.net.network_error_2'), 'error'); }
        btn.disabled = false;
        btn.innerHTML = orig;
    }

    // ── wiring ───────────────────────────────────────────────────────────────
    // `booting` keeps the first setCollapsed(false) from firing a load that init() is about to fire
    // anyway: two identical requests left the starting line together, and the guard below discarded
    // whichever answered first.
    let booting = true;
    function setCollapsed(v) {
        state.collapsed = v;
        $('net-body').classList.toggle('d-hidden', v);
        const btn = $('btn-net-toggle');
        btn.setAttribute('aria-expanded', v ? 'false' : 'true');
        const label = btn.querySelector('span'), icon = btn.querySelector('i');
        if (label) label.textContent = v ? t('js.net.expand') : t('js.net.collapse');
        if (icon) icon.className = v ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
        try { localStorage.setItem(STORE_COLLAPSE, v ? '1' : '0'); } catch (e) {}
        if (!v && !booting) { loadStatus(true); loadChart(true); }
    }

    function init() {
        setPps(state.pps);
        renderScale();
        renderRanges();
        setCollapsed(state.collapsed);

        $('net-pps-range').addEventListener('input', (e) => setPps(posToPps(parseInt(e.target.value, 10))));
        $('net-pps-input').addEventListener('input', (e) => setPps(e.target.value, true));
        $('net-pps-input').addEventListener('change', () => setPps($('net-pps-input').value));
        $('net-ranges').addEventListener('click', (e) => {
            const b = e.target.closest('.tl-range-btn');
            if (!b) return;
            state.range = b.dataset.range;
            try { localStorage.setItem(STORE_RANGE, state.range); } catch (err) {}
            renderRanges();
            loadChart(true);
        });
        $('btn-net-suggest').addEventListener('click', () => {
            const r = state.recommend;
            if (!r || !r.suggested) { showToast(t('js.net.toast_no_measurements'), 'warning'); return; }
            setPps(r.suggested);
            showToast(t('js.net.toast_suggested', {n: num(r.suggested)}), 'success');
        });
        const eRange = $('net-epps-range'), eInput = $('net-epps-input');
        if (eRange) eRange.addEventListener('input', (e) => setEpps(posToPps(parseInt(e.target.value, 10))));
        if (eInput) {
            eInput.addEventListener('input', (e) => setEpps(e.target.value, true));
            eInput.addEventListener('change', () => setEpps(eInput.value));
        }
        const eSuggest = $('btn-net-esuggest');
        if (eSuggest) eSuggest.addEventListener('click', () => {
            if (!eState.ref) { showToast(t('js.net.toast_egress_none'), 'warning'); return; }
            // Twice what is going out: clear of the amber band, and still a real cap.
            const v = Math.min(PPS_MAX, Math.max(PPS_MIN, Math.round(eState.ref * 2 / 1000) * 1000));
            setEpps(v);
            showToast(t('js.net.toast_egress_suggested', {n: num(v)}), 'success');
        });
        const eApply = $('btn-net-eapply');
        if (eApply) eApply.addEventListener('click', () => ask('egress'));
        $('btn-net-preview').addEventListener('click', preview);
        $('btn-net-apply').addEventListener('click', () => ask('apply'));
        $('btn-net-off').addEventListener('click', () => ask('off'));
        $('btn-net-panic').addEventListener('click', () => ask('panic'));
        $('net-confirm-form').addEventListener('submit', runPending);
        $('btn-net-toggle').addEventListener('click', () => setCollapsed(!state.collapsed));

        let resizeTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                if (state.chart) state.chart.setSize({ width: Math.max(200, $('net-chart').clientWidth || 800), height: 200 });
            }, 200);
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) { loadStatus(true); loadChart(true); } });

        booting = false;
        loadStatus(true);
        loadChart(true);
        state.pollTimer = setInterval(loadStatus, POLL_MS);
        state.chartTimer = setInterval(loadChart, CHART_MS);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
