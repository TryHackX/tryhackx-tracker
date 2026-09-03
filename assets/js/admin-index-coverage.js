/**
 * "Scrape coverage" chart on the admin Index page — api/admin/index_polls.php on the server.
 *
 * The index is built from one file the tracker hands over every half hour. Until this card existed,
 * the only visible fact about that was the last poll's line of text — so a poll that quietly started
 * arriving truncated, or a tracker that grew past what one poll can carry, looked exactly like a
 * healthy one. This is that series.
 *
 * THE NUMBER THIS CHART IS CAREFUL ABOUT
 * --------------------------------------
 * `delivered` is entries PAST THE RESUME CURSOR, not entries walked. A poll that resumes counts
 * everything an earlier pass already handled, so plotting raw entries would show a resumed poll as
 * a triumph and the fresh one after it as a collapse. The server does that subtraction; this file
 * only draws it, and says so on the card.
 *
 * Renders through textContent / createElement only. The chart is uPlot, the same library the swarm
 * timeline and the traffic card use.
 */
(function () {
    'use strict';

    const card = document.getElementById('idx-cov-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const POLL_MS = 60000;

    let range = '24h';
    let chart = null;
    let last = null;

    const fmt = (n) => Number(n || 0).toLocaleString();
    const pct = (v) => (v === null || v === undefined) ? '—' : v.toFixed(1) + ' %';

    function tile(label, value, cls) {
        return el('div', { className: 'wl-kv' }, [
            el('div', { className: 'wl-kv-k', text: label }),
            el('div', { className: 'wl-kv-v' }, [el('span', { className: cls || '', text: value })]),
        ]);
    }

    function renderSummary(d) {
        const box = $('idx-cov-summary');
        box.textContent = '';
        const s = d.summary || {};
        const l = s.last || null;

        // The headline is coverage, because that is the question: did we see the whole tracker?
        let covCls = 'text-muted';
        if (s.avg_coverage !== null && s.avg_coverage !== undefined) {
            covCls = s.avg_coverage >= 95 ? 'text-success' : s.avg_coverage >= 70 ? 'text-warning' : 'text-danger';
        }
        box.appendChild(tile('Average coverage', pct(s.avg_coverage), covCls));
        box.appendChild(tile('Worst poll', pct(s.min_coverage),
            (s.min_coverage !== null && s.min_coverage < 70) ? 'text-warning' : ''));
        box.appendChild(tile('Polls in this window', fmt(s.polls)));
        box.appendChild(tile('Arrived truncated', fmt(s.truncated),
            s.truncated ? 'text-warning' : 'text-muted'));
        box.appendChild(tile('Failed', fmt(s.failed), s.failed ? 'text-danger' : 'text-muted'));
        if (l) {
            box.appendChild(tile('Last poll delivered', fmt(l.delivered)
                + (l.rows_total ? ' of ' + fmt(l.rows_total) : '')));
        }

        // A note only when there is something to say. "Everything is fine" does not need a sentence.
        const note = $('idx-cov-note');
        note.textContent = '';
        if (d.unavailable) {
            note.appendChild(el('p', { className: 'wl-small text-muted mb-0',
                text: d.message || 'No history yet.' }));
            return;
        }
        if (s.polls && s.truncated === s.polls) {
            note.appendChild(el('div', { className: 'alert alert-warning py-2 wl-small mb-0',
                text: 'Every poll in this window arrived truncated. The scrape is larger than one poll '
                    + 'can carry — the index is being built a slice at a time, and coverage per poll is '
                    + 'not the same thing as coverage over a day.' }));
        } else if (s.avg_coverage !== null && s.avg_coverage !== undefined && s.avg_coverage < 70 && s.polls > 2) {
            note.appendChild(el('div', { className: 'alert alert-info py-2 wl-small mb-0',
                text: 'Polls are delivering about ' + pct(s.avg_coverage) + ' of what the tracker reports. '
                    + 'That is normal while a resume cursor is walking a large scrape; it is worth looking at '
                    + 'if it stays here with no truncated polls.' }));
        }
    }

    function draw(points) {
        const box = $('idx-cov-chart');
        if (!points.length) {
            box.textContent = '';
            box.appendChild(el('div', { className: 'idx-cov-empty',
                text: 'No polls recorded in this window yet. The first one lands on the next scrape poll.' }));
            chart = null;
            return;
        }

        const xs = points.map(p => p.ts);
        const delivered = points.map(p => p.delivered);
        const kept = points.map(p => p.kept);
        // NULL, not 0, where the tracker's own count was missing: uPlot draws a gap, which is the
        // truth, instead of a line dropping to the floor, which is not.
        const coverage = points.map(p => (p.coverage === null || p.coverage === undefined) ? null : p.coverage);
        const data = [xs, delivered, kept, coverage];

        const opts = {
            width: box.clientWidth || 800,
            height: 240,
            scales: { x: { time: true }, y: { auto: true }, pct: { auto: false, range: [0, 105] } },
            axes: [
                { stroke: '#8a94a2', grid: { stroke: '#22303f' }, ticks: { stroke: '#22303f' } },
                { stroke: '#8a94a2', grid: { stroke: '#22303f' }, ticks: { stroke: '#22303f' },
                  values: (u, vals) => vals.map(v => v >= 1000 ? (v / 1000) + 'k' : v) },
                { scale: 'pct', side: 1, stroke: '#8a94a2', grid: { show: false },
                  values: (u, vals) => vals.map(v => v + '%') },
            ],
            series: [
                { label: 'Time' },
                { label: 'Delivered', stroke: '#4d9fd6', width: 1.5, fill: 'rgba(77,159,214,0.10)' },
                { label: 'Kept', stroke: '#6cc38a', width: 1.5 },
                { label: 'Coverage', scale: 'pct', stroke: '#e0b96c', width: 1.5, dash: [4, 3] },
            ],
        };

        if (chart) { chart.setData(data); chart.setSize({ width: box.clientWidth || 800, height: 240 }); return; }
        box.textContent = '';
        chart = new uPlot(opts, data, box);
    }

    async function load() {
        // `&`, not `?` — apiCall() prepends "api.php?endpoint=", so a second `?` makes the range part
        // of the endpoint's NAME and the router answers "unknown endpoint". admin-netlimit.js:746
        // passes its own range the same way.
        const r = await apiCall('admin/index_polls&range=' + encodeURIComponent(range));
        if (r.error) {
            $('idx-cov-note').textContent = '';
            $('idx-cov-note').appendChild(el('p', { className: 'wl-small text-danger mb-0',
                text: 'Could not read the poll history: ' + r.error }));
            return;
        }
        last = r;
        const u = $('idx-cov-updated');
        if (u) u.textContent = (r.points || []).length + ' polls · ' + range;
        renderSummary(r);
        draw(r.points || []);
    }

    $('idx-cov-ranges').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-range]');
        if (!b) return;
        range = b.dataset.range;
        [...$('idx-cov-ranges').querySelectorAll('button')].forEach(x => x.classList.toggle('active', x === b));
        load();
    });

    window.addEventListener('resize', () => {
        if (chart && last) chart.setSize({ width: $('idx-cov-chart').clientWidth || 800, height: 240 });
    });

    load();
    setInterval(load, POLL_MS);
})();
