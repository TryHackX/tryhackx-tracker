/**
 * Swarm timeline chart (uPlot) — shared by the public stats page and the admin Whitelist page.
 *
 * Mount points: any element with [data-timeline]; attributes:
 *   data-api   = api base (".../api.php?endpoint=")   (falls back to APP_API / body[data-api-base])
 *   data-range = initial range (24h|7d|14d|30d|90d)    (localStorage remembers the last choice)
 *   data-compact = "1" → shorter charts (admin card)
 *
 * Three panes: swarm gauges (seeds / leechers / peers on the left axis, torrents + whitelisted
 * torrents + indexed hashes on the right axis), request rates (UDP / HTTP announces, connects, scrapes per second,
 * derived server-side from OpenTracker cumulative counters), and a Binance-style ranger (mini overview
 * with a draggable/resizable window that pans/zooms both charts). Hours in OPEN (blacklist) mode are
 * shaded; click a legend entry to hide/show a series, drag to zoom, double-click to reset.
 */
(function () {
    'use strict';
    if (typeof window === 'undefined') return;

    const RANGES = [['24h', '24h'], ['7d', '7d'], ['14d', '2w'], ['30d', '1m'], ['90d', '3m'], ['all', 'All']];
    const GAUGES = [
        { key: 'seeds',           label: 'Seeds',                color: '#4a9eff', scale: 'peers',    on: true  },
        { key: 'leechers',        label: 'Leechers',             color: '#ff5252', scale: 'peers',    on: true  },
        { key: 'peers',           label: 'Peers',                color: '#ffb74d', scale: 'peers',    on: true  },
        { key: 'torrents',        label: 'Torrents',             color: '#b388ff', scale: 'torrents', on: true  },
        { key: 'whitelist_count', label: 'Whitelisted torrents', color: '#9e9e9e', scale: 'torrents', on: false },
        { key: 'index_rows',      label: 'Indexed hashes',       color: '#26a69a', scale: 'torrents', on: false },
    ];
    const RATES = [
        { key: 'udp_rps',     label: 'UDP announces/s',  color: '#26c6da', on: true  },
        { key: 'tcp_rps',     label: 'HTTP announces/s', color: '#66bb6a', on: true  },
        { key: 'connect_rps', label: 'UDP connects/s',   color: '#8d6e63', on: false },
        { key: 'scrape_rps',  label: 'Scrapes/s',        color: '#ec407a', on: false },
    ];
    const OPEN_FILL = 'rgba(255, 152, 0, 0.075)';
    const STORE_KEY = 'tracker_timeline_range';

    const fmtInt = (v) => (v == null || isNaN(v)) ? '—' : Math.round(v).toLocaleString();
    const fmtRate = (v) => (v == null || isNaN(v)) ? '—' : (v >= 100 ? Math.round(v).toLocaleString() : v.toFixed(v >= 10 ? 1 : 2));
    const fmtAxis = (v) => {
        if (v == null) return '';
        const a = Math.abs(v);
        if (a >= 1e9) return (v / 1e9).toFixed(1).replace(/\.0$/, '') + 'G';
        if (a >= 1e6) return (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (a >= 1e3) return (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
        return String(Math.round(v * 100) / 100);
    };
    const pad2 = (n) => (n < 10 ? '0' : '') + n;
    const fmtTime = (ts) => { const d = new Date(ts * 1000); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()); };
    const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

    /** Fill the canvas under OPEN-mode spans (mode 0). Runs before series are drawn. */
    function drawOpenSpans(u, data) {
        const mode = data && data.mode;
        if (!mode || !mode.length) return;
        const ctx = u.ctx, t = u.data[0];
        const top = u.bbox.top, h = u.bbox.height, left = u.bbox.left, right = u.bbox.left + u.bbox.width;
        ctx.save(); ctx.fillStyle = OPEN_FILL;
        let i = 0;
        while (i < t.length) {
            if (mode[i] === 0) {
                let j = i;
                while (j + 1 < t.length && mode[j + 1] === 0) j++;
                // span ends where the next sample (if any) starts so adjacent points touch
                const tEnd = (j + 1 < t.length) ? t[j + 1] : t[j] + (u.scales.x.max - u.scales.x.min) * 0.002;
                const x0 = Math.max(left, u.valToPos(t[i], 'x', true));
                const x1 = Math.min(right, u.valToPos(tEnd, 'x', true));
                if (x1 > x0) ctx.fillRect(x0, top, x1 - x0, h);
                i = j + 1;
            } else i++;
        }
        ctx.restore();
    }

    const axisBase = () => ({ stroke: '#8a8a9a', font: '11px system-ui, -apple-system, Segoe UI, sans-serif', ticks: { stroke: '#2a2a3a', width: 1 }, grid: { stroke: 'rgba(255,255,255,0.06)', width: 1 } });

    function makeChart(host, width, height, defs, yFmt, legendFmt, payloadRef, sync, isRate, onXScale) {
        const scales = { x: { time: true } };
        const axes = [Object.assign(axisBase(), { space: 70, values: (u, vals) => {
            const span = (u.scales.x.max || 0) - (u.scales.x.min || 0);
            return vals.map(v => {
                const d = new Date(v * 1000);
                if (span > 120 * 86400) return pad2(d.getMonth() + 1) + '.' + d.getFullYear();   // 'All' after a year+: months repeat, show the year
                return (d.getHours() === 0 && d.getMinutes() === 0) ? pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) : pad2(d.getHours()) + ':' + pad2(d.getMinutes());
            });
        } })];
        const zeroBased = (u, min, max) => [0, Math.max(1, (isFinite(max) ? max : 0) * 1.05)];
        if (isRate) {
            scales.rps = { auto: true, range: zeroBased };
            axes.push(Object.assign(axisBase(), { scale: 'rps', size: 56, values: (u, vals) => vals.map(yFmt) }));
        } else {
            scales.peers = { auto: true, range: zeroBased };
            scales.torrents = { auto: true, range: zeroBased };
            axes.push(Object.assign(axisBase(), { scale: 'peers', size: 56, values: (u, vals) => vals.map(yFmt) }));
            axes.push(Object.assign(axisBase(), { scale: 'torrents', side: 1, size: 56, stroke: '#b388ff', grid: { show: false }, values: (u, vals) => vals.map(yFmt) }));
        }
        const series = [{ label: 'Time', value: (u, v) => v == null ? '—' : fmtTime(v) }];
        // points: uPlot's default shows markers only for isolated samples (a value with gaps on both sides) — keep it
        defs.forEach(d => series.push({ label: d.label, stroke: d.color, width: 1.5, scale: isRate ? 'rps' : d.scale, show: d.on, spanGaps: false, points: { size: 5 }, value: (u, v) => legendFmt(v) }));
        const opts = {
            width, height, scales, axes, series,
            legend: { live: true },
            cursor: { x: true, y: false, sync: sync ? { key: sync, setSeries: false } : undefined, drag: { x: true, y: false, setScale: true } },
            hooks: {
                drawClear: [u => drawOpenSpans(u, payloadRef.current)],
                setScale: onXScale ? [(u, key) => { if (key === 'x') onXScale(u); }] : [],
            },
            padding: [8, 8, 0, 4],
        };
        const data = [[]].concat(defs.map(() => []));
        return new uPlot(opts, data, host);
    }

    function mount(container) {
        if (container.__timelineMounted) return; container.__timelineMounted = true;
        const api = container.dataset.api || (typeof APP_API !== 'undefined' ? APP_API : (document.body.dataset.apiBase || 'api.php?endpoint='));
        const compact = container.dataset.compact === '1';
        let range = container.dataset.range || '';
        try { range = localStorage.getItem(STORE_KEY) || range; } catch (e) {}
        if (!RANGES.some(r => r[0] === range)) range = '24h';

        // skeleton
        const head = el('div', 'tl-head');
        const ranges = el('div', 'tl-ranges');
        RANGES.forEach(([key, label]) => { const b = el('button', 'tl-range-btn' + (key === range ? ' active' : ''), label); b.type = 'button'; b.dataset.range = key; ranges.appendChild(b); });
        const meta = el('div', 'tl-meta');
        const status = el('span', 'tl-status', 'Loading…');
        const hint = el('span', 'tl-hint', 'Click a legend entry to toggle a series · drag on a chart to zoom (the visible span reloads at a finer resolution) · drag / resize the window on the mini-chart to pan · double-click to reset · shaded = OPEN hours');
        meta.appendChild(status);
        head.appendChild(ranges); head.appendChild(meta);
        container.appendChild(head);
        const mainHost = el('div', 'tl-chart tl-chart-main');
        const rateTitle = el('div', 'tl-subtitle', 'Requests per second (derived from the tracker counters)');
        const rateHost = el('div', 'tl-chart tl-chart-rate');
        const rangerHost = el('div', 'tl-chart tl-ranger');
        const empty = el('div', 'tl-empty d-hidden', 'No samples yet — the janitor timer records one sample per interval; come back in a few minutes.');
        container.appendChild(mainHost); container.appendChild(rateTitle); container.appendChild(rateHost); container.appendChild(rangerHost); container.appendChild(empty); container.appendChild(hint);

        const payloadRef = { current: null };
        const syncKey = 'tl-' + Math.random().toString(36).slice(2);
        const mainH = compact ? 220 : 300, rateH = compact ? 110 : 140, rangerH = 46;
        const MIN_W = 200;   // phones: the card is ~300 px wide; a larger floor would overflow it
        const w = Math.max(MIN_W, container.clientWidth || 800);
        // xScaleSync / updateBrush are function declarations (hoisted) so the charts can reference them at init
        const main = makeChart(mainHost, w, mainH, GAUGES, fmtAxis, fmtInt, payloadRef, syncKey, false, (u) => xScaleSync(u));
        const rate = makeChart(rateHost, w, rateH, RATES, fmtAxis, fmtRate, payloadRef, syncKey, true, (u) => xScaleSync(u));
        // charts with the same cursor.sync.key subscribe themselves to the uPlot sync group

        // ── ranger (Binance-style brush): a mini overview chart with a draggable / resizable window ──
        const ranger = new uPlot({
            width: w, height: rangerH,
            scales: { x: { time: true }, y: { auto: true, range: (u, min, max) => [0, Math.max(1, (isFinite(max) ? max : 0) * 1.05)] } },
            axes: [{ show: false }, { show: false }],
            legend: { show: false },
            cursor: { show: false, drag: { x: false, y: false } },
            series: [{}, { stroke: '#4a9eff', width: 1, fill: 'rgba(74, 158, 255, 0.10)', points: { show: false }, spanGaps: false }],
            padding: [2, 8, 2, 4],
        }, [[], []], rangerHost);
        const over = ranger.over || ranger.root.querySelector('.u-over');
        const brush = el('div', 'tl-brush');
        const hL = el('div', 'tl-brush-h tl-brush-l');
        const hR = el('div', 'tl-brush-h tl-brush-r');
        brush.appendChild(hL); brush.appendChild(hR);
        over.appendChild(brush);
        over.style.touchAction = 'none';

        let syncing = false;
        // clamping/brush extent = the FULL loaded range (ranger always holds the base payload, even
        // while the main charts show a re-fetched fine-grained zoom window)
        function dataExt() { const t = ranger.data && ranger.data[0]; return (t && t.length > 1) ? [t[0], t[t.length - 1]] : null; }
        function updateBrush() {
            const ext = dataExt();
            if (!ext) { brush.style.display = 'none'; return; }
            const l = ranger.valToPos(Math.max(ext[0], main.scales.x.min), 'x');
            const r = ranger.valToPos(Math.min(ext[1], main.scales.x.max), 'x');
            // right after the FIRST setData the ranger scale may not be committed yet → NaN positions
            if (!isFinite(l) || !isFinite(r)) { brush.style.display = 'none'; return; }
            brush.style.display = '';
            brush.style.left = Math.max(0, Math.min(l, r)) + 'px';
            brush.style.width = Math.max(6, Math.abs(r - l)) + 'px';
        }
        /** Set the visible x window on both charts (clamped to the loaded data). */
        function applyWindow(min, max) {
            const ext = dataExt(); if (!ext) return;
            const minSpan = Math.max((payloadRef.current && payloadRef.current.step || 60) * 3, 300);
            min = Math.max(ext[0], Math.min(min, ext[1] - minSpan));
            max = Math.min(ext[1], Math.max(max, min + minSpan));
            syncing = true;
            main.setScale('x', { min, max });
            rate.setScale('x', { min, max });
            syncing = false;
            updateBrush();
            requestWindow(false);
        }
        /** A drag-zoom / double-click reset on one chart mirrors to the other and to the brush. */
        function xScaleSync(u) {
            if (syncing) return;
            syncing = true;
            const s = u.scales.x;
            (u === main ? rate : main).setScale('x', { min: s.min, max: s.max });
            syncing = false;
            updateBrush();
            requestWindow(false);
        }
        let dragMode = null, dragStartX = 0, dragWin = null;
        const startDrag = (mode) => (ev) => {
            if (!dataExt()) return;
            dragMode = mode; dragStartX = ev.clientX;
            dragWin = [main.scales.x.min, main.scales.x.max];
            ev.preventDefault(); ev.stopPropagation();
        };
        brush.addEventListener('pointerdown', startDrag('move'));
        hL.addEventListener('pointerdown', startDrag('l'));
        hR.addEventListener('pointerdown', startDrag('r'));
        over.addEventListener('pointerdown', (ev) => {           // click outside the window: centre it there, then drag
            if (ev.target !== over) return;
            const ext = dataExt(); if (!ext) return;
            const rect = over.getBoundingClientRect();
            const tAt = ranger.posToVal(ev.clientX - rect.left, 'x');
            const span = main.scales.x.max - main.scales.x.min;
            applyWindow(tAt - span / 2, tAt + span / 2);
            dragMode = 'move'; dragStartX = ev.clientX;
            dragWin = [main.scales.x.min, main.scales.x.max];
            ev.preventDefault();
        });
        document.addEventListener('pointermove', (ev) => {
            if (!dragMode) return;
            const ext = dataExt(); if (!ext) { dragMode = null; return; }
            const s = ranger.scales.x;
            const secPerPx = (s.max - s.min) / Math.max(1, over.clientWidth);
            const dt = (ev.clientX - dragStartX) * secPerPx;
            if (dragMode === 'move') {
                const span = dragWin[1] - dragWin[0];
                let nmin = Math.max(ext[0], Math.min(dragWin[0] + dt, ext[1] - span));
                applyWindow(nmin, nmin + span);
            } else if (dragMode === 'l') applyWindow(dragWin[0] + dt, dragWin[1]);
            else applyWindow(dragWin[0], dragWin[1] + dt);
        });
        document.addEventListener('pointerup', () => { dragMode = null; });
        over.addEventListener('dblclick', () => { const ext = dataExt(); if (ext) applyWindow(ext[0], ext[1]); });

        let seq = 0, pending = false, timer = null, lastOk = 0;
        // a drag-zoom narrows the x scale below the data extents; background refreshes must not undo it
        const isZoomed = (u) => { const d = u.data && u.data[0]; return !!d && d.length > 1 && (u.scales.x.min > d[0] || u.scales.x.max < d[d.length - 1]); };
        const put = (u, d, keepZoom) => { if (keepZoom && isZoomed(u)) { u.setData(d, false); u.redraw(); } else u.setData(d); };
        const stepTxt = (s) => s >= 3600 ? (s / 3600) + ' h' : (s >= 60 ? (s / 60) + ' min' : s + ' s');
        const statusFor = (p, zoomed) => p.points.toLocaleString() + ' points · ' + stepTxt(p.step) + ' resolution (' + p.table + (zoomed ? ' · zoom' : '') + ') · updated ' + fmtTime(p.generated_at);
        // zoom-window refetch state: while active, main+rate show a fine-grained slice fetched with
        // &from/&to (the server picks raw → 5m → 1h for the span); the ranger keeps the full range
        let basePayload = null;
        const winState = { active: false, from: 0, to: 0, step: 0, seq: 0 };
        let winTimer = null;
        const setCharts = (p) => {
            payloadRef.current = p;
            const t = p.t || [];
            main.setData([t].concat(GAUGES.map(d => p[d.key] || t.map(() => null))), false);
            rate.setData([t].concat(RATES.map(d => p[d.key] || t.map(() => null))), false);
            main.redraw(); rate.redraw();
        };
        const applyBase = () => {
            if (!basePayload) return;
            winState.active = false; winState.seq++;
            setCharts(basePayload);
            status.textContent = statusFor(basePayload, false);
        };
        // which step could the server give us for this span? (mirror of its raw→5m→1h pick, retention aside)
        const candidateStep = (span) => {
            const interval = (basePayload && basePayload.interval) || 60;
            if (span / interval <= 3000) return interval;
            if (span / 300 <= 4500) return 300;
            return 3600;
        };
        function requestWindow(force) {
            clearTimeout(winTimer);
            winTimer = setTimeout(() => fetchWindow(force), 350);
        }
        async function fetchWindow(force) {
            const ext = dataExt();
            if (!ext || !basePayload) return;
            const min = main.scales.x.min, max = main.scales.x.max;
            if (!isFinite(min) || !isFinite(max) || max <= min) return;
            if (min <= ext[0] && max >= ext[1]) { if (winState.active) applyBase(); return; }   // back to full view
            if (winState.active) {
                // uPlot's built-in double-click reset snaps to the extents of the LOADED slice, not the
                // full range — treat "scale covers every loaded point" as reset intent and zoom out fully
                const d = main.data && main.data[0];
                if (d && d.length > 1 && min <= d[0] && max >= d[d.length - 1]) {
                    applyBase();
                    applyWindow(ext[0], ext[1]);
                    return;
                }
            }
            const span = max - min;
            const cand = candidateStep(span);
            const curStep = winState.active ? winState.step : basePayload.step;
            const covered = winState.active && min >= winState.from && max <= winState.to;
            if (!force && cand >= curStep && (covered || !winState.active)) return;   // nothing finer to gain
            const pad = span * 0.25;   // padded fetch so small pans don't refetch immediately
            const from = Math.max(ext[0], Math.floor(min - pad));
            const to = Math.ceil(Math.min(ext[1] + 3600, max + pad));
            const my = ++winState.seq;
            try {
                const r = await fetch(api + 'stats_timeline&range=' + encodeURIComponent(range) + '&from=' + from + '&to=' + to, { credentials: 'same-origin', cache: 'no-store' });
                const j = await r.json();
                if (my !== winState.seq || !r.ok || !j || !j.success || !j.t) return;
                if (!force && winState.active && j.step >= winState.step && covered) return;
                winState.active = true; winState.from = j.from; winState.to = j.to; winState.step = j.step;
                setCharts(j);
                updateBrush();
                status.textContent = statusFor(j, true);
            } catch (e) { /* keep whatever is on screen */ }
        }
        const apply = (p, keepZoom) => {
            basePayload = p;
            const t = p.t || [];
            if (keepZoom && winState.active) {
                // background refresh while zoomed into a refetched window: refresh the ranger/base
                // only, then bring the fine slice up to date too — never clobber it with coarse data
                ranger.setData([t, p.seeds || t.map(() => null)]);
                updateBrush();
                requestWindow(true);
                return;
            }
            winState.active = false; winState.seq++;
            payloadRef.current = p;
            put(main, [t].concat(GAUGES.map(d => p[d.key] || t.map(() => null))), keepZoom);
            put(rate, [t].concat(RATES.map(d => p[d.key] || t.map(() => null))), keepZoom);
            ranger.setData([t, p.seeds || t.map(() => null)]);
            // the ranger's auto x scale may be committed after this tick — try now AND on the next task
            // (setTimeout, not rAF: rAF never fires in hidden/background tabs)
            updateBrush();
            setTimeout(updateBrush, 0);
            const none = !t.length;
            empty.classList.toggle('d-hidden', !none);
            mainHost.classList.toggle('d-hidden', none); rateHost.classList.toggle('d-hidden', none); rateTitle.classList.toggle('d-hidden', none);
            rangerHost.classList.toggle('d-hidden', none);
            status.textContent = none ? 'No data for this range yet' : statusFor(p, false);
        };
        // force = user action (range click / expand): always fires and resets the zoom; timer ticks never stack
        // and a response that was superseded by a newer request is dropped
        const load = (force) => {
            if (pending && !force) return;
            const my = ++seq; pending = true;
            status.textContent = 'Loading…';
            fetch(api + 'stats_timeline&range=' + encodeURIComponent(range) + (force ? '&_=' + Date.now() : ''), { credentials: 'same-origin', cache: 'no-store' })
                .then(r => r.json().then(j => ({ ok: r.ok, j })))
                .then(({ ok, j }) => {
                    if (my !== seq) return;
                    if (!ok || !j || !j.success) { status.textContent = (j && j.error) ? j.error : 'Timeline unavailable'; return; }
                    lastOk = Date.now();
                    apply(j, !force);
                })
                .catch(() => { if (my === seq) status.textContent = 'Timeline unavailable (network)'; })
                .finally(() => { if (my === seq) pending = false; });
        };
        ranges.addEventListener('click', (ev) => {
            const b = ev.target.closest('.tl-range-btn'); if (!b) return;
            range = b.dataset.range;
            try { localStorage.setItem(STORE_KEY, range); } catch (e) {}
            ranges.querySelectorAll('.tl-range-btn').forEach(x => x.classList.toggle('active', x === b));
            load(true);
        });
        // auto-refresh every 60 s while visible
        const schedule = () => { clearInterval(timer); timer = setInterval(() => { if (document.visibilityState === 'visible') load(false); }, 60000); };
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && Date.now() - lastOk > 60000) load(false); });
        // responsive width
        if (typeof ResizeObserver !== 'undefined') {
            let raf = null;
            new ResizeObserver(() => { if (raf) return; raf = requestAnimationFrame(() => { raf = null; const cw = Math.max(MIN_W, container.clientWidth || w); main.setSize({ width: cw, height: mainH }); rate.setSize({ width: cw, height: rateH }); ranger.setSize({ width: cw, height: rangerH }); updateBrush(); }); }).observe(container);
        }
        load(false); schedule();
        container.__timeline = { main, rate, ranger, setWindow: applyWindow, reload: () => load(true) };
    }

    /** Collapse buttons: <button data-tl-collapse="<id of the [data-timeline] element>">; state kept in localStorage. */
    function bindCollapse() {
        document.querySelectorAll('[data-tl-collapse]').forEach(btn => {
            const target = document.getElementById(btn.dataset.tlCollapse);
            if (!target) return;
            const key = 'tracker_timeline_collapsed_' + btn.dataset.tlCollapse;
            const label = btn.querySelector('span'), icon = btn.querySelector('i');
            const setState = (collapsed) => {
                target.classList.toggle('d-hidden', collapsed);
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                if (label) label.textContent = collapsed ? 'Expand' : 'Collapse';
                if (icon) icon.className = collapsed ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
                if (!collapsed && target.__timeline) target.__timeline.reload();
            };
            let collapsed = false;
            try { collapsed = localStorage.getItem(key) === '1'; } catch (e) {}
            setState(collapsed);
            btn.addEventListener('click', () => { collapsed = !collapsed; try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {} setState(collapsed); });
        });
    }

    function boot() {
        if (typeof uPlot === 'undefined') return;
        document.querySelectorAll('[data-timeline]').forEach(mount);
        bindCollapse();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    window.StatsTimeline = { mount: mount, boot: boot };
})();
