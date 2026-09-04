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

    function tile(label, value, cls, note) {
        return el('div', { className: 'wl-kv' }, [
            el('div', { className: 'wl-kv-k', text: label }),
            el('div', { className: 'wl-kv-v' }, [
                el('span', { className: cls || '', text: value }),
                note ? el('span', { className: 'wl-small text-muted idx-cov-note-inline', text: note }) : '',
            ]),
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
        // An average over one poll is not an average, and colouring it red says something the
        // number cannot support. Below three polls the figures are shown plainly.
        const thin = (s.polls || 0) < 3;
        box.appendChild(tile('Average coverage', pct(s.avg_coverage), thin ? '' : covCls,
            thin ? 'over ' + fmt(s.polls) + ' poll' + (s.polls === 1 ? '' : 's') + ' — too few to average'
                 : 'over ' + fmt(s.polls) + ' polls'));
        box.appendChild(tile('Worst poll', pct(s.min_coverage),
            (!thin && s.min_coverage !== null && s.min_coverage < 70) ? 'text-warning' : ''));
        box.appendChild(tile('Polls in this window', fmt(s.polls)));
        box.appendChild(tile('Arrived truncated', fmt(s.truncated),
            s.truncated ? 'text-warning' : 'text-muted',
            s.truncated ? 'the scrape did not fit in one poll' : null));
        box.appendChild(tile('Failed', fmt(s.failed), s.failed ? 'text-danger' : 'text-muted'));
        if (l) {
            box.appendChild(tile('Last poll delivered', fmt(l.delivered)
                + (l.rows_total ? ' of ' + fmt(l.rows_total) : ''),
                l.delivered === 0 ? 'text-muted' : '',
                l.delivered === 0 ? 'nothing new — it resumed past what an earlier poll had' : null));
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

    function draw(points, from) {
        const box = $('idx-cov-chart');
        // FEWER THAN TWO POINTS IS NOT A CHART.
        //
        // uPlot given a single x value has no range to work with and invents one — the first live
        // poll produced an axis running from Oct 2026 to May 2029 with one dot pinned to the left
        // edge, which looks like a broken chart rather than like a table with one row in it. A line
        // needs two points; until then this says so.
        if (points.length < 2) {
            box.textContent = '';
            const one = points[0] || null;
            box.appendChild(el('div', { className: 'idx-cov-empty' }, [
                el('div', { text: points.length
                    ? 'One poll so far in this window — a line needs two. The next one draws it.'
                    : 'No polls recorded in this window yet. The first one lands on the next scrape poll.' }),
                one ? el('div', { className: 'idx-cov-empty-one',
                    text: new Date(one.ts * 1000).toLocaleString() + ' · '
                        + fmt(one.delivered) + ' delivered · ' + fmt(one.kept) + ' kept'
                        + (one.coverage !== null ? ' · ' + pct(one.coverage) + ' coverage' : '')
                        + (one.truncated ? ' · arrived truncated' : '') }) : '',
                points.length ? el('div', { className: 'idx-cov-empty-hint',
                    text: 'Try a wider range if there should be more.' }) : '',
            ]));
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

        // The x axis is the WINDOW THAT WAS ASKED FOR, not the spread of whatever came back. A
        // 24-hour range with three polls in it should still be drawn as a day, or the reader is
        // looking at a differently-shaped chart every time a poll lands.
        const now = Math.floor(Date.now() / 1000);
        const xFrom = Math.min(from || xs[0], xs[0]);
        const opts = {
            width: box.clientWidth || 800,
            height: 240,
            scales: { x: { time: true, range: [xFrom, Math.max(now, xs[xs.length - 1])] },
                      y: { auto: true }, pct: { auto: false, range: [0, 105] } },
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

        // A range change alters the x scale, which uPlot fixes at construction — so the chart is
        // rebuilt when the window moves and only re-fed when it has not.
        if (chart && chart.__from === xFrom) {
            chart.setData(data);
            chart.setSize({ width: box.clientWidth || 800, height: 240 });
            return;
        }
        if (chart) { chart.destroy(); chart = null; }
        box.textContent = '';
        chart = new uPlot(opts, data, box);
        chart.__from = xFrom;
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
        draw(r.points || [], r.from || 0);
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
