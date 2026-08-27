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
        ];
        defs.forEach(([label, value, cls, title]) => {
            if (!value) return;
            const pct = ppsToPct(value);
            const m = el('span', { className: 'nl-mark ' + cls, title: title + ': ' + num(value) + ' pps' });
            m.style.left = pct + '%';
            m.appendChild(el('span', { className: 'nl-mark-label', text: label }));
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
            if (cur < r.floor) {
                box.appendChild(el('div', { className: 'text-warning wl-small', text: 'At ' + num(cur) + ' pps you would be dropping traffic the tracker normally serves.' }));
            } else if (cur > r.peak * 2 && r.peak > 0) {
                box.appendChild(el('div', { className: 'text-muted wl-small', text: 'At ' + num(cur) + ' pps the limit is far above anything measured — it would effectively never trigger.' }));
            }
        }
    }

    function setPps(v, fromInput) {
        state.pps = Math.max(PPS_MIN, Math.min(PPS_MAX, parseInt(v, 10) || PPS_MIN));
        if (!fromInput) $('net-pps-input').value = state.pps;
        const range = $('net-pps-range');
        range.value = ppsToPos(state.pps);
        // WebKit cannot fill the rail up to the thumb on its own (Gecko has ::-moz-range-progress);
        // the CSS reads this as a gradient stop.
        range.style.setProperty('--nl-fill', ppsToPct(state.pps) + '%');
        renderAdvice();
    }

    // ── chart ────────────────────────────────────────────────────────────────
    function buildChart() {
        if (typeof uPlot === 'undefined') return null;
        const host = $('net-chart');
        const w = Math.max(200, host.clientWidth || card.clientWidth || 800);
        const axisBase = () => ({ stroke: '#8a8a9a', font: '11px system-ui, -apple-system, Segoe UI, sans-serif', ticks: { stroke: '#2a2a3a', width: 1 }, grid: { stroke: 'rgba(255,255,255,0.06)', width: 1 } });
        const series = [{ label: 'Time', value: (u, v) => v == null ? '—' : fmtTime(v) }];
        SERIES.forEach(s => series.push({
            label: s.label, stroke: s.color, width: 1.5, show: s.on, spanGaps: false,
            dash: s.dash || undefined, points: { size: 5 }, value: (u, v) => num(v),
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
