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
        { key: 'pps_total',  label: 'Arriving',  color: '#4a9eff', on: true },
        { key: 'pps_passed', label: 'Served',    color: '#66bb6a', on: true },
        { key: 'pps_capped', label: 'Dropped',   color: '#ff5252', on: true },
        { key: 'limit_pps',  label: 'Limit',     color: '#ffb74d', on: true, dash: [6, 4] },
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
            if (seq > statusPainted) { statusPainted = seq; renderFatal('The panel could not reach the status endpoint (' + (e && e.message ? e.message : 'network error') + ').', true); }
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
        setPps(state.pps);   // repaint the inbound zones now that a fresh measurement is in
    }

    // A fetch that never settles runs neither the try nor the catch above, so nothing would ever
    // replace the loading state. This is the only branch left that can do it.
    function armWatchdog() {
        clearWatchdog();
        if (statusPainted) return;   // something real is already on screen; leave it there
        watchdog = setTimeout(() => {
            watchdog = null;
            if (statusPainted) return;
            renderFatal('The status endpoint has not answered for 15 seconds. The server may be out of PHP workers.', true);
        }, 15000);
    }
    function clearWatchdog() { if (watchdog) { clearTimeout(watchdog); watchdog = null; } }

    function renderFatal(msg, retry) {
        const grid = $('net-grid');
        grid.textContent = '';
        const parts = [badge('unavailable', 'wl-b-bad'), ' ',
            el('span', { className: 'wl-small text-muted', text: msg || 'The status endpoint did not answer.' })];
        if (retry) parts.push(el('div', {}, [el('button', {
            className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
            onclick: () => { statusPainted = 0; statusBusy = 0; renderLoading(); loadStatus(true); },
        }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' Try again'])]));
        grid.appendChild(kv('Firewall', parts));
    }

    function renderLoading() {
        const grid = $('net-grid');
        grid.textContent = '';
        grid.appendChild(el('div', { className: 'wl-status-loading' }, [
            el('span', { className: 'spinner-border spinner-border-sm', role: 'status' }), ' Reading the firewall…']));
    }

    // "Not persistent" has three different causes and only one of them is the admin's to fix, so
    // the card says which one it is. The common one on a hardened box is not a fault at all: the
    // panel's PHP runs under systemd ProtectSystem, /etc is read-only inside that mount namespace,
    // and the janitor — an ordinary unit — writes the file within a minute instead.
    function persistNote(fw, j) {
        if (fw.dir_writable === false || j.persist_deferred) {
            return el('div', { className: 'wl-small text-muted' }, [
                el('i', { className: 'bi bi-clock-history' }),
                ' Live, not saved yet — the panel’s PHP cannot write ' + (fw.file_path || '/etc/nftables.d') +
                ' (systemd ProtectSystem). The janitor saves it within a minute; until then a reboot would undo it.']);
        }
        if (fw.include_ok === false) {
            return el('div', { className: 'wl-small text-warning', text:
                'Loaded, but nftables.conf does not include the directory, so it will be gone after a reboot. Run the availability test in Settings for the one line to add.' });
        }
        if (fw.file_matches === false && fw.file_present) {
            return el('div', { className: 'wl-small text-warning', text:
                'Loaded, but the saved copy is a DIFFERENT ruleset (' + (fw.file_mode === 'count' ? 'counting only' : num(fw.file_pps) + ' pps') +
                ') — after a reboot that one comes back, not this one.' });
        }
        return el('div', { className: 'wl-small text-warning', text:
            'Loaded, but NOT saved — it will be gone after a reboot. Run the availability test in Settings.' });
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
            grid.appendChild(kv('Firewall', [badge('unavailable', 'wl-b-bad'), ' ',
                el('span', { className: 'wl-small text-muted', text: j.error })]));
        } else if (!fw.nft) {
            grid.appendChild(kv('Firewall', [badge('no nftables', 'wl-b-bad'), ' ',
                el('span', { className: 'wl-small text-muted', text: 'nft is not installed — the limit cannot be loaded on this machine.' })]));
        } else if (fw.table && fw.mode === 'count') {
            // counters loaded, no drop rule: measuring, not throttling — say so unambiguously
            const parts = [badge('counting only', 'wl-b-pending'), ' ',
                el('span', { className: 'text-muted', text: 'port ' + fw.port + ' · nothing is dropped' }),
                el('div', { className: 'wl-small text-muted', text: 'The rules in force contain no drop at all — they exist to measure. Pick a limit below once there are enough samples.' })];
            if (!fw.persistent) parts.push(persistNote(fw, j));
            grid.appendChild(kv('Inbound limit', parts));
        } else if (fw.table) {
            const parts = [badge(num(fw.pps) + ' pps', 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: 'burst ' + num(fw.burst) + ' · port ' + fw.port })];
            if (!fw.persistent) parts.push(persistNote(fw, j));
            const src = j.last_apply && j.last_apply.source;
            if (src) parts.push(el('div', { className: 'wl-small text-muted', text: 'set by ' + src + ' ' + (j.last_apply.at ? fmtAgo(Math.floor(j.server_time - j.last_apply.at)) + ' ago' : '') }));
            grid.appendChild(kv('Inbound limit', parts));
        } else {
            grid.appendChild(kv('Inbound limit', [badge('not loaded', 'wl-b-muted'), ' ',
                el('span', { className: 'wl-small text-muted', text: cfg.limit ? 'Settings say it should be on — press "Apply limit" to load it.' : 'Nothing is being dropped by the panel.' })]));
        }

        // 2. the three numbers this whole card exists for
        const hasLive = Object.keys(pps).length > 0;
        const stale = live.stale ? ' (last known)' : '';
        // "Arriving" is the honest word for it: our chain runs before everything else on this port,
        // so this is the raw arrival rate, whatever anybody else drops afterwards.
        grid.appendChild(kv('Arriving' + stale, hasLive
            ? [el('strong', { text: num(pps.in_total) }), el('span', { className: 'nl-unit', text: ' pps' }),
               el('div', { className: 'wl-small text-muted', text: 'measured over the last ' + (live.span || 0) + ' s' })]
            : [el('span', { className: 'text-muted', text: 'measuring…' })]));
        // Our chain sits at `priority filter - 5`, i.e. BEFORE the distribution's filter table. So this
        // is what got past OUR rules — if somebody else's rule limits the same port downstream, the
        // tracker receives less than this, and saying "served to the tracker" would overstate it.
        const foreign = (fw.manual_rules || []).length > 0;
        const passedParts = hasLive
            ? [el('strong', { text: num(pps.in_passed) }), el('span', { className: 'nl-unit', text: ' pps' })]
            : [el('span', { className: 'text-muted', text: '—' })];
        if (hasLive && foreign) passedParts.push(el('div', { className: 'wl-small text-warning', text:
            'Another rule further down the chain limits this port as well, so the tracker actually receives less than this. See the note below.' }));
        grid.appendChild(kv(foreign ? 'Past our rules' : 'Served to the tracker', passedParts));
        const dropped = hasLive ? (pps.in_capped || 0) : null;
        grid.appendChild(kv('Dropped by the limit', hasLive
            ? [el('strong', { className: dropped > 0 ? 'text-warning' : '', text: num(dropped) }), el('span', { className: 'nl-unit', text: ' pps' }),
               el('div', { className: 'wl-small text-muted', text: dropped > 0
                   ? Math.round((dropped / Math.max(1, pps.in_total)) * 100) + ' % of what arrives never reaches OpenTracker'
                   : 'nothing is being dropped right now' })]
            : [el('span', { className: 'text-muted', text: '—' })]));

        // 3. the other lever, side by side (we only show it — it is installed by hand)
        const eg = fw.egress || {};
        if (eg.table) {
            const hasE = Object.keys(epps).length > 0;
            grid.appendChild(kv('Outbound budget (replies)', [
                badge(num(eg.pps) + ' pps', 'wl-b-ok'), ' ',
                el('span', { className: 'wl-small text-muted', text: hasE ? num((epps.announce_ok || 0) + (epps.passed_good || 0)) + ' pps out · ' + num(epps.capped) + ' pps capped' : 'measuring…' }),
                el('div', { className: 'wl-small text-muted', text: 'table inet ottrack — what the tracker answers. Capping it is what keeps the rest of the machine reachable.' }),
            ]));
        }

        // 4. automatic mode
        if (cfg.auto) {
            const a = j.auto_state || {};
            const parts = [badge('on', 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: 'target ' + num(cfg.auto_target) + ' pps, band ' + num(cfg.auto_min) + '–' + num(cfg.auto_max) })];
            if (a.over || a.under) parts.push(el('div', { className: 'wl-small text-muted', text: (a.over ? 'above target' : 'below target') + ' for ' + Math.max(a.over, a.under) + ' of ' + a.hysteresis + ' samples' }));
            if (a.last_move_at) parts.push(el('div', { className: 'wl-small text-muted', text: 'last move: ' + (a.last_move || '?') + ' ' + fmtAgo(Math.floor(j.server_time - a.last_move_at)) + ' ago' + (a.note ? ' — ' + a.note : '') }));
            grid.appendChild(kv('Automatic mode', parts));
        }

        // 5. panic countdown
        if (j.panic) {
            grid.appendChild(kv('Emergency throttle', [
                badge(Math.ceil(j.panic.seconds_left / 60) + ' min left', 'wl-b-warn'), ' ',
                el('span', { className: 'wl-small text-muted', text: j.panic.restore_enabled ? 'then back to ' + num(j.panic.restore_pps) + ' pps' : 'then the limit is removed again' }),
                el('button', { className: 'btn btn-sm btn-outline-secondary ms-2', type: 'button', onclick: () => ask('restore') }, [el('i', { className: 'bi bi-arrow-counterclockwise' }), ' Undo now']),
            ]));
        }

        if (typeof j.load_per_core === 'number') {
            grid.appendChild(kv('Machine load', [
                el('span', { text: j.load_per_core.toFixed(2) + ' per core' }), ' ',
                el('span', { className: 'wl-small text-muted', text: j.cpus ? '(' + j.cpus + ' cores)' : '' }),
            ]));
        }

        renderNotes(j);
        $('net-updated').textContent = 'port ' + (fw.port || cfg.port) + ' · updated ' + new Date().toLocaleTimeString();
    }

    /** Warnings that need a sentence, not a tile: foreign rules on the same port, persistence, errors. */
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
                        el('span', { className: 'text-muted', text: 'remove it yourself when you no longer want it: ' }),
                        el('code', { text: r.undo }),
                    ]),
                ]));
            });
            box.appendChild(el('div', { className: 'nl-note nl-note-info' }, [
                el('div', {}, [el('i', { className: 'bi bi-info-circle' }), el('strong', { text: ' Another rule already limits this port.' }),
                    el('span', { text: ' It is not ours and the panel never touches it — both limits apply, the stricter one wins. Rules added by hand usually live only in RAM and disappear on reboot; the panel\'s does not.' })]),
                list,
            ]));
        }
        if (j.last_error) {
            box.appendChild(el('div', { className: 'nl-note nl-note-bad' }, [
                el('i', { className: 'bi bi-exclamation-triangle' }),
                el('span', { text: ' Last failure: ' + j.last_error + (j.last_error_at ? ' (' + fmtAgo(Math.floor(j.server_time - j.last_error_at)) + ' ago)' : '') }),
            ]));
        }
        // The counters live in the firewall: with no table of ours there is nothing to count, so the
        // monitor would quietly record zeros and the suggestion would be meaningless. Say that, and
        // offer the one action that fixes it.
        if (j.configured && j.configured.monitor && fw.nft && !fw.table && !j.error) {
            box.appendChild(el('div', { className: 'nl-note nl-note-warn' }, [
                el('div', {}, [el('i', { className: 'bi bi-exclamation-triangle' }),
                    el('strong', { text: ' The monitor is on but nothing is being counted.' }),
                    el('span', { text: ' The counters live in the firewall, and none of our rules are loaded — every sample would be zero. Load the counting-only rules: they contain no drop at all, so nothing is throttled.' })]),
                el('button', { className: 'btn btn-sm btn-outline-info mt-2', type: 'button',
                               onclick: () => ask('monitor') }, [el('i', { className: 'bi bi-activity' }), ' Start counting…']),
            ]));
        }
        if (j.configured && j.configured.monitor && j.last_tick_at && (j.server_time - j.last_tick_at) > 300) {
            box.appendChild(el('div', { className: 'nl-note nl-note-warn' }, [
                el('i', { className: 'bi bi-clock-history' }),
                el('span', { text: ' The janitor has not sampled for ' + fmtAgo(Math.floor(j.server_time - j.last_tick_at)) + ' — check that tracker-whitelist-janitor.timer is running, or the chart will stay flat.' }),
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
            ['median', r.median, 'nl-mark-median', 'Median — the ordinary rate'],
            ['P95', r.p95, 'nl-mark-p95', 'P95 — busier than 95 % of the time'],
            ['peak', r.peak, 'nl-mark-peak', 'Peak — the busiest sample recorded'],
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
                title: 'Median load reached ' + NL_BUSY_LOAD + ' per core around ' + num(lc.busy_pps) + ' pps' });
            b.style.left = pctB + '%';
            b.style.setProperty('--nl-tick-h', '2.6rem');
            b.appendChild(el('span', { className: 'nl-mark-label', text: 'busy' }));
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
                    'This machine was already at ' + NL_BUSY_LOAD + ' load per core around ' + num(lc2.busy_pps)
                    + ' pps, so a limit of ' + num(cur) + ' pps would let it get there before the rule ever fires.'
                    + ' (Load is the whole box — mail and the forum live here too — so treat it as a ceiling, not a verdict.)' }));
            } else if (lc2 && !lc2.busy_pps && lc2.why) {
                box.appendChild(el('div', { className: 'wl-small text-muted', text: 'Load study: ' + lc2.why }));
            }
            const ref = inboundReference();
            if (ref > 0) {
                if (cur < ref) {
                    box.appendChild(el('div', { className: 'text-danger wl-small', text: 'At ' + num(cur) + ' pps you would be cutting into the ' + num(ref) + ' pps that is getting through right now.' }));
                } else if (cur < ref * ZONE_HEADROOM) {
                    box.appendChild(el('div', { className: 'text-warning wl-small', text: 'At ' + num(cur) + ' pps there is little headroom over the ' + num(ref) + ' pps getting through right now — a normal spike would hit the limit.' }));
                } else if (cur > r.peak * 2 && r.peak > 0) {
                    box.appendChild(el('div', { className: 'text-muted wl-small', text: 'At ' + num(cur) + ' pps the limit is far above anything measured — it would effectively never trigger.' }));
                }
            } else if (cur < r.floor) {
                box.appendChild(el('div', { className: 'text-warning wl-small', text: 'At ' + num(cur) + ' pps you would be dropping packets that are currently arriving.' }));
            } else if (cur > r.peak * 2 && r.peak > 0) {
                box.appendChild(el('div', { className: 'text-muted wl-small', text: 'At ' + num(cur) + ' pps the limit is far above anything measured — it would effectively never trigger.' }));
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
                adv.appendChild(el('div', { className: 'nl-note nl-note-info', text:
                    'There is no outbound rule to tune yet. The panel creates the table inet ottrack the '
                    + 'first time a budget is applied, or the firewall helper does when it sets one — until '
                    + 'then the tracker answers at whatever rate it likes, which is the default behaviour.' }));
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
                               title: 'Measured outbound rate: ' + num(eState.ref) + ' pps' });
        m.style.left = pctE + '%';
        m.style.setProperty('--nl-tick-h', '0.5rem');
        m.appendChild(el('span', { className: 'nl-mark-label', text: 'sending now' }));
        marks.appendChild(m);
    }

    function renderEgressAdvice(eg) {
        const box = $('net-eadvice');
        if (!box) return;
        box.textContent = '';
        const inForce = parseInt(eg.pps, 10) || 0;
        const lines = [];
        lines.push(el('div', { text: 'In force: ' + num(inForce) + ' pps. This is table inet ottrack, on the way OUT — what the tracker '
            + 'answers. Capping it is what keeps the rest of the machine reachable while a swarm is shouting.' }));
        // A budget that is live but missing from the file is gone at the next reboot, and there is
        // no way to find that out except by rebooting. So say it here instead.
        if (eg.file === false) {
            lines.push(el('div', { className: 'text-warning', text:
                'There is no file for this budget on disk, so it will not come back after a reboot — the egress table was installed by hand.' }));
        } else if (eg.file_matches === false) {
            lines.push(el('div', { className: 'text-warning', text:
                'Live, but the saved copy still says ' + num(eg.file_pps || 0) + ' pps — after a reboot that is what would come back.'
                + ' The janitor rewrites it within a minute; if this sticks, the availability test in Settings says why.' }));
        }
        if (!eState.ref) {
            lines.push(el('div', { className: 'text-muted', text: 'No outbound rate measured yet, so there is nothing to judge a number against — the coloured zones appear once there is.' }));
        } else {
            lines.push(el('div', { text: 'Measured going out right now: ' + num(eState.ref) + ' pps (replies plus whatever the budget already refused).' }));
            if (eState.pps < eState.ref) {
                lines.push(el('div', { className: 'text-danger', text: 'At ' + num(eState.pps) + ' pps you would be refusing replies the tracker is sending right now — peers would see timeouts, not a slower tracker.' }));
            } else if (eState.pps < eState.ref * ZONE_HEADROOM) {
                lines.push(el('div', { className: 'text-warning', text: 'At ' + num(eState.pps) + ' pps there is almost no headroom over what is already going out; a normal spike would hit the cap.' }));
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
        const series = [{ label: 'Time', value: (u, v) => v == null ? '—' : fmtTime(v) }];
        // Markers only where the line cannot show the value on its own. uPlot's default turns them on
        // once the average pixel gap between samples passes a threshold, so the same chart is clean on
        // a laptop and speckled white on a wide monitor — a decision about the window, not the data.
        // A sample with a gap on both sides still needs one: with spanGaps false there is no line
        // segment to draw it on, so without a marker it is invisible. The hover point uPlot draws for
        // the cursor is separate and untouched.
        const isolatedOnly = (u, seriesIdx, idx0, idx1) => {
            const data = u.data[seriesIdx];
            const out = [];
            for (let i = idx0; i <= idx1; i++) {
                if (data[i] == null) continue;
                const prev = i > 0 ? data[i - 1] : null;
                const next = i < data.length - 1 ? data[i + 1] : null;
                if (prev == null && next == null) out.push(i);
            }
            return out.length ? out : false;
        };
        SERIES.forEach(s => series.push({
            label: s.label, stroke: s.color, width: 1.5, show: s.on, spanGaps: false,
            dash: s.dash || undefined, points: { show: isolatedOnly, size: 5 }, value: (u, v) => num(v),
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

    async function loadChart(force) {
        if (state.collapsed) return;
        if (!force && document.hidden) return;
        if (!state.chart) state.chart = buildChart();
        if (!state.chart) return;
        let j;
        try { j = await apiCall('admin/net_samples&range=' + encodeURIComponent(state.range)); } catch { return; }
        if (!j || !j.ok) return;
        const s = j.series || {};
        const data = [s.t || []].concat(SERIES.map(def => (s[def.key] || []).map(v => (v == null ? null : Number(v)))));
        state.chart.setData(data);
        const empty = !(s.t && s.t.length);
        $('net-chart').classList.toggle('nl-chart-empty', empty);
        $('net-chart').dataset.empty = empty
            ? (j.monitor ? 'No samples in this range yet — the janitor records one per interval.' : 'The traffic monitor is off (Settings → UDP traffic & rate limit).')
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
            title: 'Apply the inbound limit',
            ok: 'Apply limit',
            okClass: 'btn-outline-success',
            text: () => 'Load an nftables rule that drops everything above ' + num(state.pps) + ' packets/second on UDP port ' + state.port
                + ' (burst ' + num(state.burst) + '). Packets dropped here never reach OpenTracker.',
            undo: () => 'Undo: the "Remove limit" button — or on the server, ' ,
            undoCode: () => 'sudo nft delete table inet ottrack_in && sudo rm /etc/nftables.d/ottrack-in.nft',
        },
        off: {
            title: 'Remove the inbound limit',
            ok: 'Remove limit',
            okClass: 'btn-outline-warning',
            text: () => 'Delete our nftables table and its file. The tracker port stops being throttled by the panel — anything else limiting it (your own rules, the outbound budget) keeps working.',
            undo: () => 'This also switches the automatic mode off, because there would be no limit left for it to move.',
            undoCode: () => '',
        },
        panic: {
            title: 'Throttle hard for 15 minutes',
            ok: 'Throttle now',
            okClass: 'btn-outline-danger',
            text: () => 'Clamp UDP port ' + state.port + ' to 10 000 packets/second right now. This WILL drop traffic the tracker normally serves — it is the "the box is drowning, buy me fifteen minutes" button.',
            undo: () => 'The janitor puts the previous setting back automatically after 15 minutes (and "Undo now" appears in the card meanwhile), so it cannot be forgotten.',
            undoCode: () => '',
        },
        egress: {
            title: 'Change the outbound budget',
            ok: 'Apply budget',
            okClass: 'btn-outline-success',
            text: () => 'Set the reply budget in table inet ottrack to ' + num(eState.pps) + ' packets/second. This is what the '
                + 'tracker is allowed to SEND: below what it is actually sending, peers get timeouts rather than a slower tracker; '
                + 'far above it, nothing protects the rest of the machine when a swarm shouts.',
            undo: () => 'One rule is swapped by handle, so the counters keep running. Undo: set it back, or on the server, ',
            undoCode: () => 'sudo nft -f /etc/nftables.d/ottrack.nft',
        },
        monitor: {
            title: 'Start counting (nothing is dropped)',
            ok: 'Start counting',
            okClass: 'btn-outline-info',
            text: () => 'Loads an nftables table with three counters on UDP port ' + state.port + ' and NO drop rule — '
                      + 'the chain accepts by default and contains nothing that can discard a packet. It is a meter, not a valve. '
                      + 'After an hour or two the slider below will carry the median, P95 and peak that were actually measured.',
            undo: () => 'Undo: the "Remove limit" button — or on the server, ',
            undoCode: () => 'sudo nft delete table inet ottrack_in && sudo rm /etc/nftables.d/ottrack-in.nft',
        },
        restore: {
            title: 'Undo the emergency throttle',
            ok: 'Restore',
            okClass: 'btn-outline-success',
            text: () => 'Put back the limit that was in force before the emergency throttle (or remove the limit entirely, if there was none).',
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working…';
        alert.textContent = '';
        const body = { op: op, password: $('net-confirm-password').value };
        if (op === 'apply') { body.pps = state.pps; body.burst = state.burst; body.port = state.port; }
        if (op === 'monitor') { body.port = state.port; }
        if (op === 'egress') { body.pps = eState.pps; }
        try {
            const r = await apiCall('admin/net_apply', 'POST', body);
            if (r.success) {
                bootstrap.Modal.getOrCreateInstance($('netConfirmModal')).hide();
                showToast(r.message || 'Done', 'success');
                if ((op === 'apply' || op === 'monitor') && r.persistent === false) {
                    showToast('The rule is live but will not survive a reboot — see the availability test in Settings.', 'warning');
                }
                state.pending = null;
                loadStatus();
            } else {
                alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || 'Failed' }));
                if (r.output) alert.appendChild(el('pre', { className: 'nl-preview nl-preview-sm', text: r.output }));
            }
        } catch {
            alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: 'Network error' }));
        }
        btn.disabled = false;
        btn.innerHTML = orig;
    }

    async function preview() {
        const btn = $('btn-net-preview');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Rendering…';
        try {
            const r = await apiCall('admin/net_apply', 'POST', { op: 'preview', pps: state.pps, burst: state.burst, port: state.port });
            if (r.success) {
                $('net-preview-file').textContent = r.file || '/etc/nftables.d/ottrack-in.nft';
                $('net-preview-body').textContent = r.ruleset || '';
                bootstrap.Modal.getOrCreateInstance($('netPreviewModal')).show();
            } else {
                showToast(r.error || 'Preview failed', 'error');
            }
        } catch { showToast('Network error', 'error'); }
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
        if (label) label.textContent = v ? 'Expand' : 'Collapse';
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
            if (!r || !r.suggested) { showToast('No measurements yet — turn the monitor on and come back in an hour.', 'warning'); return; }
            setPps(r.suggested);
            showToast('Slider set to the suggested ' + num(r.suggested) + ' pps. Nothing has been applied yet.', 'success');
        });
        const eRange = $('net-epps-range'), eInput = $('net-epps-input');
        if (eRange) eRange.addEventListener('input', (e) => setEpps(posToPps(parseInt(e.target.value, 10))));
        if (eInput) {
            eInput.addEventListener('input', (e) => setEpps(e.target.value, true));
            eInput.addEventListener('change', () => setEpps(eInput.value));
        }
        const eSuggest = $('btn-net-esuggest');
        if (eSuggest) eSuggest.addEventListener('click', () => {
            if (!eState.ref) { showToast('Nothing measured going out yet — there is no number to suggest from.', 'warning'); return; }
            // Twice what is going out: clear of the amber band, and still a real cap.
            const v = Math.min(PPS_MAX, Math.max(PPS_MIN, Math.round(eState.ref * 2 / 1000) * 1000));
            setEpps(v);
            showToast('Slider set to ' + num(v) + ' pps — twice what is measured going out. Nothing applied yet.', 'success');
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
