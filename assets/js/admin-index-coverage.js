/**
 * "Scrape coverage" card on the admin Index page — api/admin/index_polls.php on the server.
 *
 * The index is built from one file the tracker hands over every half hour. Until this card existed,
 * the only visible fact about that was the last poll's line of text — so a poll that quietly started
 * failing, or a tracker that grew past what one poll can walk, looked exactly like a healthy one.
 *
 * THE UNIT IS THE PASS, NOT THE POLL (1.72.0)
 * -------------------------------------------
 * When the scrape takes longer to walk than the time budget allows, a poll is cut at the budget and
 * the next one continues from the cursor: the two together are the whole scrape. Judged one poll at a
 * time, the continuing half of every such pair read as a collapse — production said "worst poll
 * 0.1 %" about the 1 453-entry tail of a pass that had walked everything, and "21 arrived truncated"
 * about cuts, the words for a broken download. The server groups the polls into passes
 * (includes/index.php indexPollPasses()); the tiles are per pass, and a pass whose newest poll was cut
 * is in progress — never a low number.
 *
 * THE CHART
 * ---------
 * One bar per pass, its polls stacked in it bottom-up — the bars are real polls, drawn as the parts of
 * what they did together. The height is what each poll DELIVERED (entries past the point it started
 * from; the server does that subtraction), the darker part what it kept, and the dashed line is the
 * tracker's own count: a bar that reaches the line walked the whole scrape. Each part says what it did
 * on hover or tap ("continues the previous poll from entry 1 586 043 — together 100 %").
 *
 * Drawn as SVG through createElementNS / textContent only — every part is an element of its own, so it
 * can carry its words, be pointed at on a phone, and be found by a test. The traffic card's uPlot draws
 * lines on a canvas; a stack of parts per pass is not a line.
 */
(function () {
    'use strict';

    const card = document.getElementById('idx-cov-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const POLL_MS = 60000;
    const NS = 'http://www.w3.org/2000/svg';
    const H = 240;
    const M = { top: 10, right: 8, bottom: 22, left: 44 };

    let range = '24h';
    let last = null;
    let pinned = null;          // the part whose words stay open after a tap…
    let pinnedTs = null;        // …and which poll it is, so a redraw (the minute's reload, a resize) keeps them open
    let loadSeq = 0;            // which request is the current one (see load())

    const fmt = (n) => Number(n || 0).toLocaleString();
    // 99.99 is the whole scrape: the tracker's count and the file's length differ by a handful
    const pct = (v) => (v === null || v === undefined) ? '—' : (v >= 99.95 ? '100' : Number(v).toFixed(1)) + ' %';
    const dur = (s) => {
        const n = Math.max(0, Math.round(Number(s) || 0));
        return n >= 60 ? t('js.coverage.dur_ms', {m: Math.floor(n / 60), s: n % 60}) : t('js.coverage.dur_s', {s: n});
    };
    const pollsText = (n) => n === 1 ? t('js.coverage.polls_one') : t('js.coverage.polls_n', {n: fmt(n)});
    const pad2 = (n) => (n < 10 ? '0' : '') + n;
    const when = (ts) => { const d = new Date(ts * 1000); return pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()); };

    // THE PANEL'S OWN TILE, not one that merely looks like it in the source.
    //
    // The first version used `.wl-kv` with `.wl-kv-k` / `.wl-kv-v`. `.wl-kv` is the CONTAINER for a
    // list of tiles (the shape modals use), and the other two have no rules in admin.css at all — so
    // the tiles came out as bare dark boxes with the text welded to the border and the label in body
    // type. The card next to this one on Traffic uses `.wl-kv-item` / `.wl-kv-label` /
    // `.wl-kv-value`, which is where the padding, the border and the small-caps label live.
    function tile(label, value, cls, note, id) {
        return el('div', { className: 'wl-kv-item', dataset: id ? { tile: id } : undefined }, [
            el('div', { className: 'wl-kv-label', text: label }),
            el('div', { className: 'wl-kv-value' }, [
                el('span', { className: cls || '', text: value }),
                note ? el('div', { className: 'wl-small text-muted', text: note }) : '',
            ]),
        ]);
    }

    function renderSummary(d) {
        const box = $('idx-cov-summary');
        box.textContent = '';
        const s = d.summary || {};
        const counted = s.counted || 0;

        // The headline is coverage, because that is the question: did we see the whole tracker? Per
        // PASS — a pass counts every poll that continues it.
        let covCls = 'text-muted';
        if (s.avg_coverage !== null && s.avg_coverage !== undefined) {
            covCls = s.avg_coverage >= 95 ? 'text-success' : s.avg_coverage >= 70 ? 'text-warning' : 'text-danger';
        }
        // An average over one pass is not an average, and colouring it red says something the
        // number cannot support. Below three finished passes the figures are shown plainly.
        const thin = counted < 3;
        box.appendChild(tile(t('js.coverage.avg_coverage'), pct(s.avg_coverage), thin ? '' : covCls,
            !counted ? t('js.coverage.no_finished_pass')
                : thin ? t('js.coverage.over_passes_thin', {n: fmt(counted)}) : t('js.coverage.over_passes', {n: fmt(counted)}), 'avg'));
        box.appendChild(tile(t('js.coverage.worst_pass'), pct(s.min_coverage),
            (!thin && s.min_coverage !== null && s.min_coverage < 70) ? 'text-warning' : '', null, 'worst'));
        box.appendChild(tile(t('js.coverage.passes_in_window'), fmt(s.passes), '',
            t('js.coverage.passes_polls_note', {n: fmt(s.polls)}), 'passes'));
        // A cut is the budget doing its job, not a fault: muted, and said as what it is.
        box.appendChild(tile(t('js.coverage.arrived_truncated'), fmt(s.cut), 'text-muted',
            s.cut ? t('js.coverage.truncated_note') : null, 'cut'));
        // A download that really ended short keeps a word of its own, and only appears when it happened.
        if (s.short) box.appendChild(tile(t('js.coverage.ended_early'), fmt(s.short), 'text-warning',
            t('js.coverage.ended_early_note'), 'short'));
        box.appendChild(tile(t('js.coverage.failed'), fmt(s.failed), s.failed ? 'text-danger' : 'text-muted', null, 'failed'));
        const lp = s.last_pass;
        if (lp) {
            if (lp.status === 'in_progress') {
                box.appendChild(tile(t('js.coverage.last_pass'), t('js.coverage.in_progress_value', {n: fmt(lp.walked)}), 'text-info',
                    t('js.coverage.in_progress_note', {n: fmt(lp.walked)}), 'last'));
            } else {
                box.appendChild(tile(t('js.coverage.last_pass'),
                    fmt(lp.walked) + (lp.rows_total ? ' ' + t('js.coverage.of_total', {n: fmt(lp.rows_total)}) : ''), '',
                    [pct(lp.coverage), pollsText(lp.polls), t('js.coverage.poll_time', {t: dur(lp.seconds)})].join(' · '), 'last'));
            }
        }

        // A note only when there is something to say. "Everything is fine" does not need a sentence.
        const note = $('idx-cov-note');
        note.textContent = '';
        if (d.unavailable) {
            note.appendChild(el('p', { className: 'wl-small text-muted mb-0',
                text: d.message || t('js.coverage.no_history') }));
            return;
        }
        const alerts = [];
        if (s.short) alerts.push(['alert-warning', 'short', t('js.coverage.ended_early_alert', {n: fmt(s.short)})]);
        // Every finished pass took more than one poll: the budget is shorter than the scrape. Said as
        // what it is — nothing lost — with the measured estimate of what a longer budget would do.
        if (counted && s.min_polls > 1) {
            let text = t('js.coverage.all_truncated_alert', {budget: s.budget, max: s.budget_max});
            const e = s.estimate;
            if (e) {
                text += ' ' + t(e.polls > 1 ? 'js.coverage.estimate_many' : 'js.coverage.estimate_one',
                    {rate: fmt(e.rate), scrape: fmt(e.scrape), needs: fmt(e.needs), budget: e.budget, polls: e.polls});
                if (e.polls > 1 && s.budget_max && e.needs <= s.budget_max) text += ' ' + t('js.coverage.estimate_at_max', {max: s.budget_max});
            }
            alerts.push(['alert-info', 'multi', text]);
        }
        if (s.avg_coverage !== null && s.avg_coverage !== undefined && s.avg_coverage < 70 && counted > 2) {
            alerts.push(['alert-info', 'low', t('js.coverage.low_coverage_alert', {pct: pct(s.avg_coverage)})]);
        }
        alerts.forEach(([cls, id, text], i) => note.appendChild(el('div', {
            className: 'alert ' + cls + ' py-2 wl-small ' + (i < alerts.length - 1 ? 'mb-2' : 'mb-0'), dataset: { alert: id }, text: text })));
    }

    // ── the chart ────────────────────────────────────────────────────────────
    function svg(tag, attrs, text) {
        const n = document.createElementNS(NS, tag);
        if (attrs) Object.keys(attrs).forEach(k => { if (attrs[k] !== null && attrs[k] !== undefined) n.setAttribute(k, attrs[k]); });
        if (text !== undefined) n.textContent = text;
        return n;
    }
    function niceStep(max, ticks) {
        const raw = Math.max(1, max) / Math.max(1, ticks);
        const mag = Math.pow(10, Math.floor(Math.log10(raw)));
        const f = raw / mag;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * mag;
    }
    const axisNum = (v) => v >= 1e6 ? (v / 1e6).toFixed(2).replace(/\.?0+$/, '') + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'k' : String(v);
    const TIME_STEPS = [1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 345600, 604800];
    function timeTicks(x0, x1, most) {
        let step = TIME_STEPS[TIME_STEPS.length - 1];
        for (const s of TIME_STEPS) { if ((x1 - x0) / s <= most) { step = s; break; } }
        const d = new Date(x0 * 1000);
        d.setHours(0, 0, 0, 0);
        let ts = Math.floor(d.getTime() / 1000);
        while (ts < x0) ts += step;
        const out = [];
        for (; ts <= x1; ts += step) out.push(ts);
        return out;
    }
    const tickLabel = (ts) => { const d = new Date(ts * 1000); return (d.getHours() === 0 && d.getMinutes() === 0) ? pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) : pad2(d.getHours()) + ':' + pad2(d.getMinutes()); };

    /** The words for one part: what the poll did, and what its pass came to. */
    function partWords(p, pass, soFar) {
        const lines = [];
        lines.push(pass.polls > 1 ? t('js.coverage.tip_head', {when: when(p.ts), pos: p.pos, of: pass.polls})
                                  : t('js.coverage.tip_head_one', {when: when(p.ts)}));
        if (p.kind === 'error') {
            lines.push(t('js.coverage.tip_error', {err: p.error || '?'}));
        } else if (p.kind === 'short') {
            lines.push(t('js.coverage.tip_short', {n: fmt(p.entries), reason: p.partial || '?'}));
        } else if (p.kind === 'start') {
            lines.push(p.cut ? t('js.coverage.tip_start_cut', {n: fmt(p.entries)}) : t('js.coverage.tip_start_full', {n: fmt(p.entries)}));
        } else if (p.delivered === 0 && !p.cut) {
            lines.push(t('js.coverage.tip_resume_empty', {from: fmt(p.start), pct: pct(pass.coverage)}));
        } else if (p.cut) {
            lines.push(t('js.coverage.tip_resume_cut', {from: fmt(p.start), pct: pct(soFar)}));
        } else {
            lines.push(t('js.coverage.tip_resume', {from: fmt(p.start), pct: pct(pass.coverage)}));
        }
        lines.push(t('js.coverage.tip_numbers', {d: fmt(p.delivered), k: fmt(p.kept), t: dur(p.ms / 1000)}));
        const state = pass.began_before ? t('js.coverage.pass_began_before')
            : t('js.coverage.pass_' + (pass.status === 'in_progress' ? 'in_progress' : pass.status));
        const passPct = pass.status === 'in_progress' ? t('js.coverage.in_progress_value', {n: fmt(pass.walked)})
            : pass.began_before ? '—' : pct(pass.coverage);
        lines.push(t('js.coverage.tip_pass', {pct: passPct, state: state}));
        return lines;
    }

    function showTip(part, x, y) {
        const box = $('idx-cov-chart');
        let tip = box.querySelector('.idx-cov-tip');
        if (!tip) { tip = el('div', { className: 'idx-cov-tip' }); box.appendChild(tip); }
        tip.textContent = '';
        (part.__words || []).forEach((line, i) => tip.appendChild(el('div', { className: i === 0 ? 'idx-cov-tip-head' : '', text: line })));
        tip.hidden = false;
        const bw = box.clientWidth, tw = tip.offsetWidth, th = tip.offsetHeight;
        tip.style.left = Math.max(0, Math.min(bw - tw, x + 12)) + 'px';
        tip.style.top = Math.max(0, y - th - 10) + 'px';
        box.querySelectorAll('.idx-cov-seg.is-on').forEach(n => n.classList.remove('is-on'));
        part.classList.add('is-on');
    }
    function hideTip() {
        const box = $('idx-cov-chart');
        const tip = box && box.querySelector('.idx-cov-tip');
        if (tip) tip.hidden = true;
        if (box) box.querySelectorAll('.idx-cov-seg.is-on').forEach(n => n.classList.remove('is-on'));
        pinned = null;
        pinnedTs = null;
    }
    function pin(part) {
        const box = $('idx-cov-chart');
        const r = box.getBoundingClientRect(), pr = part.getBoundingClientRect();
        pinned = null;
        showTip(part, pr.left - r.left + pr.width / 2, pr.top - r.top);
        pinned = part;
        pinnedTs = part.dataset.ts;
    }

    function empty(points, passes) {
        const box = $('idx-cov-chart');
        box.textContent = '';
        const one = points[0] || null;
        const pass = one ? passes[one.pass] : null;
        let line = '';
        if (one) {
            line = t('js.coverage.one_poll_line', {when: new Date(one.ts * 1000).toLocaleString(),
                delivered: fmt(one.delivered), kept: fmt(one.kept)});
            if (pass && pass.status !== 'in_progress' && !pass.began_before && pass.coverage !== null) {
                line += ' · ' + t('js.coverage.one_poll_coverage', {pct: pct(pass.coverage)});
            }
            if (one.partial) line += ' · ' + t('js.index.ended_early_badge');
            else if (one.cut) line += ' · ' + t('js.coverage.one_poll_truncated');
        }
        box.appendChild(el('div', { className: 'idx-cov-empty' }, [
            el('div', { text: points.length ? t('js.coverage.one_poll_so_far') : t('js.coverage.no_polls_yet') }),
            one ? el('div', { className: 'idx-cov-empty-one', text: line }) : '',
            points.length ? el('div', { className: 'idx-cov-empty-hint', text: t('js.coverage.wider_range_hint') }) : '',
        ]));
    }

    function draw(d) {
        const box = $('idx-cov-chart');
        const points = d.points || [];
        const passes = d.passes || [];
        const keep = pinnedTs;
        pinned = null; pinnedTs = null;
        // One poll is a sentence, not a chart: a single bar on a day-wide axis reads as a rendering fault.
        if (points.length < 2) { empty(points, passes); legend(null); return; }

        const all = (d.lead || []).concat(points);
        const byPass = passes.map(() => []);
        all.forEach(p => { if (byPass[p.pass]) byPass[p.pass].push(p); });

        // The x axis is the WINDOW THAT WAS ASKED FOR, not the spread of whatever came back: a day with
        // three passes in it is still drawn as a day.
        const now = Math.floor(Date.now() / 1000);
        const x0 = Math.min(d.from || points[0].ts, points[0].ts);
        const x1 = Math.max(now, ...passes.map(x => x.end_ts || x.last_ts));
        const W = Math.max(240, box.clientWidth || 800);
        const pw = W - M.left - M.right, ph = H - M.top - M.bottom;
        const X = (ts) => M.left + ((Math.max(x0, ts) - x0) / Math.max(1, x1 - x0)) * pw;

        let top = 1;
        passes.forEach((x, i) => {
            top = Math.max(top, byPass[i].reduce((a, p) => a + p.delivered, 0), x.rows_total || 0);
        });
        points.forEach(p => { if (p.rows_total) top = Math.max(top, p.rows_total); });
        const step = niceStep(top * 1.05, 5);
        const yMax = Math.ceil((top * 1.05) / step) * step;
        const Y = (v) => M.top + ph - (v / yMax) * ph;

        // A bar's width follows the passes' own spacing (the median gap), so a day of half-hourly
        // passes and a month of them both read as bars — each at least a pixel, never a slab.
        const xs = passes.map(x => X(x.first_ts));
        const gaps = [];
        for (let i = 1; i < xs.length; i++) gaps.push(xs[i] - xs[i - 1]);
        gaps.sort((a, b) => a - b);
        const gap = gaps.length ? gaps[gaps.length >> 1] : pw / 4;
        const bw = Math.max(1, Math.min(18, gap * 0.7));

        const root = svg('svg', { class: 'idx-cov-svg', width: W, height: H, viewBox: '0 0 ' + W + ' ' + H,
            role: 'group', 'aria-label': t('js.coverage.chart_aria', {p: fmt(passes.length), n: fmt(points.length)}) });

        // grid + y axis
        const gy = svg('g', { class: 'idx-cov-axis' });
        for (let v = 0; v <= yMax + 0.5; v += step) {
            const y = Math.round(Y(v)) + 0.5;
            gy.appendChild(svg('line', { class: 'idx-cov-grid', x1: M.left, x2: W - M.right, y1: y, y2: y }));
            gy.appendChild(svg('text', { x: M.left - 5, y: y + 3.5, 'text-anchor': 'end' }, axisNum(v)));
        }
        // x axis
        timeTicks(x0, x1, Math.max(2, Math.floor(pw / 70))).forEach(ts => {
            const x = Math.round(X(ts)) + 0.5;
            gy.appendChild(svg('line', { class: 'idx-cov-tick', x1: x, x2: x, y1: M.top + ph, y2: M.top + ph + 4 }));
            gy.appendChild(svg('text', { x: x, y: H - 6, 'text-anchor': 'middle' }, tickLabel(ts)));
        });
        root.appendChild(gy);

        // the passes: one bar each, its polls stacked bottom-up
        const bars = svg('g', { class: 'idx-cov-bars' });
        passes.forEach((pass, i) => {
            const g = svg('g', { class: 'idx-cov-pass' + (pass.status === 'in_progress' ? ' idx-cov-open' : ''),
                'data-pass': i, 'data-status': pass.status });
            const bx = xs[i];
            let base = 0, reach = 0;
            byPass[i].forEach(p => {
                reach = Math.max(reach, p.entries);
                const soFar = p.rows_total ? Math.min(100, (100 * reach) / p.rows_total) : null;
                const y1 = Y(base), y2 = Y(base + p.delivered);
                const hgt = Math.max(2, y1 - y2);            // a poll that delivered nothing still has a part to point at
                const part = svg('rect', { class: 'idx-cov-seg idx-cov-k-' + p.kind, x: bx, y: y1 - hgt, width: bw, height: hgt,
                    'data-ts': p.ts, 'data-pos': p.pos, 'data-kind': p.kind, role: 'img' });
                part.__words = partWords(p, pass, soFar);
                part.setAttribute('aria-label', part.__words.join('. '));
                g.appendChild(part);
                if (p.kept > 0 && p.delivered > 0) {
                    const kh = Math.max(1, hgt * Math.min(1, p.kept / p.delivered));
                    g.appendChild(svg('rect', { class: 'idx-cov-kept', x: bx, y: y1 - kh, width: bw, height: kh }));
                }
                base += p.delivered;
            });
            // In progress: what is still to walk, as an outline up to the tracker's count.
            if (pass.status === 'in_progress' && pass.rows_total && pass.rows_total > base) {
                g.appendChild(svg('rect', { class: 'idx-cov-rest', x: bx, y: Y(pass.rows_total), width: bw, height: Math.max(0, Y(base) - Y(pass.rows_total)) }));
            }
            bars.appendChild(g);
        });
        root.appendChild(bars);

        // the tracker's own count, where it was known — a gap where it was not
        let path = '', pen = false;
        points.forEach(p => {
            if (!p.rows_total) { pen = false; return; }
            path += (pen ? 'L' : 'M') + X(p.ts).toFixed(1) + ' ' + Y(p.rows_total).toFixed(1) + ' ';
            pen = true;
        });
        if (path) root.appendChild(svg('path', { class: 'idx-cov-tracker', d: path.trim() }));

        box.textContent = '';
        box.appendChild(root);
        legend(d);

        // Pointing at a part says what it did; a tap keeps the words open until the next tap — and
        // across the minute's reload, which draws the chart anew.
        root.addEventListener('pointermove', (e) => {
            if (pinned) return;
            const part = e.target.closest && e.target.closest('.idx-cov-seg');
            if (!part) { hideTip(); return; }
            const r = box.getBoundingClientRect();
            showTip(part, e.clientX - r.left, e.clientY - r.top);
        });
        root.addEventListener('pointerleave', () => { if (!pinned) hideTip(); });
        root.addEventListener('click', (e) => {
            const part = e.target.closest && e.target.closest('.idx-cov-seg');
            if (!part || part === pinned) { hideTip(); return; }
            pin(part);
        });
        if (keep) {
            const again = root.querySelector('.idx-cov-seg[data-ts="' + keep + '"]');
            if (again) pin(again);
        }
    }

    /** What the colours mean — only the ones on the chart, so a phone is not handed seven labels. */
    function legend(d) {
        let box = $('idx-cov-legend');
        if (!box) {
            box = el('div', { className: 'idx-cov-legend', id: 'idx-cov-legend' });
            $('idx-cov-chart').after(box);
        }
        box.textContent = '';
        if (!d) return;
        const kinds = new Set((d.points || []).concat(d.lead || []).map(p => p.kind));
        const items = [['start', 'legend_start'], ['resume', 'legend_resume']];
        if (kinds.has('short')) items.push(['short', 'legend_short']);
        if (kinds.has('error')) items.push(['error', 'legend_error']);
        items.push(['kept', 'legend_kept'], ['tracker', 'legend_tracker']);
        if ((d.passes || []).some(x => x.status === 'in_progress')) items.push(['rest', 'legend_progress']);
        items.forEach(([k, key]) => box.appendChild(el('span', { className: 'idx-cov-leg', dataset: { leg: k } }, [
            el('span', { className: 'idx-cov-sw idx-cov-sw-' + k, 'aria-hidden': 'true' }), t('js.coverage.' + key)])));
    }

    async function load() {
        // Only the newest request paints. The first load (24h) and a click on another range leave
        // together, and whichever answered LAST was drawn — so a slower day could land over the six
        // hours the buttons said, and wipe the chart under the reader's pointer. The traffic card's
        // chart has had the same guard since it met the same race (chartSeq in admin-netlimit.js).
        const seq = ++loadSeq;
        // `&`, not `?` — apiCall() prepends "api.php?endpoint=", so a second `?` makes the range part
        // of the endpoint's NAME and the router answers "unknown endpoint". admin-netlimit.js:746
        // passes its own range the same way.
        const r = await apiCall('admin/index_polls&range=' + encodeURIComponent(range));
        if (seq !== loadSeq) return;
        if (r.error) {
            $('idx-cov-note').textContent = '';
            $('idx-cov-note').appendChild(el('p', { className: 'wl-small text-danger mb-0',
                text: t('js.coverage.load_failed', {error: r.error}) }));
            return;
        }
        last = r;
        const u = $('idx-cov-updated');
        if (u) u.textContent = t('js.coverage.updated_line', {p: fmt((r.passes || []).length), n: fmt((r.points || []).length), range: range});
        renderSummary(r);
        draw(r);
    }

    $('idx-cov-ranges').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-range]');
        if (!b) return;
        range = b.dataset.range;
        [...$('idx-cov-ranges').querySelectorAll('button')].forEach(x => x.classList.toggle('active', x === b));
        load();
    });

    // A tap anywhere else puts the words away.
    document.addEventListener('click', (e) => {
        if (pinned && !e.target.closest('#idx-cov-chart')) hideTip();
    });
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { if (last) draw(last); }, 150);
    });

    load();
    setInterval(load, POLL_MS);
})();
