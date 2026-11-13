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
 * what they did together. Each part is the NEW ground its poll walked (1.72.1: from the furthest entry
 * the pass had reached before it, or its own start if that is further, to its end — the server works it
 * out), the darker part what it kept, and the dashed line is the tracker's own count: a bar that
 * reaches the line walked the whole scrape. Each part says what it did on hover or tap ("continues the
 * previous poll from entry 1 586 043 — together 100 %").
 *
 * WHAT 1.72.1 CHANGED, from production's own month (2026-09-04 – 09-30, 1 274 polls, 1 122 passes)
 * -----------------------------------------------------------------------------------------------
 * * The stack was the SUM of what each poll delivered past its own start. A short download that joins
 *   an open pass starts at entry 0 and walks again what the pass had walked — the 04.09 09:29 pass,
 *   thirteen short downloads and the poll that finished it, stood at 5 017 793 over a scrape of
 *   1 468 888, and the month's y axis ran to 6M with every other bar squashed under a third of it.
 *   Now a part is only its new ground: a poll that walked old ground again adds nothing to the height —
 *   it is a sliver inside the bar, and its words say "read again from the start: N entries, M of them
 *   new". The y axis is the larger of the tracker's count and the furthest entry, never a sum.
 * * One bar per pass cannot work past a few hundred passes: at 1 440 px a week's bars were 2 px, two
 *   weeks' 1 px, a month's less — and a 1-px bar is all 1-px stroke, the colour of the background, so
 *   on the month and on All only the dashed line was left. When the passes' own spacing is under
 *   MIN_SLOT pixels, the passes are grouped into buckets of time from BUCKET_STEPS — the smallest that
 *   fits the chart's width, worked out on every redraw, so a resize or a phone re-buckets — and each
 *   bucket is one bar: the MEAN coverage of its passes (the statistic of the "Average coverage per
 *   pass" tile) of the bucket's tracker count, the darker part its kept share, a light tick where its
 *   worst pass stood when that fell below WORST_MARK, and a strip at its foot for a download that ended
 *   early (amber) or a failed poll (red). The whole column answers a pointer or a finger.
 * * The vertical white stroke on the month was the tracker's REAL count: 289 419 at 07.09 12:45, the
 *   one poll after a restart, between 1 579 527 and 1 367 400 — a V an hour wide, drawn across 26 days.
 *   A bucket's count is the largest the tracker reported in it (a restart only ever lowers it, for a poll
 *   or two); its words say "lo – hi" when they differ. The one row with no count (05.09 12:51, NULL)
 *   was always a gap, and stays one: the line breaks where a count is unknown, never drops to zero.
 * * "All" starts at the oldest row, not at the retention's start (production drew 64 empty days).
 *
 * Drawn as SVG through createElementNS / textContent only — every part is an element of its own, so it
 * can carry its words, be pointed at on a phone, and be found by a test. The traffic card's uPlot draws
 * lines on a canvas; a stack of parts per pass is not a line.
 */
(function () {
    'use strict';

    const card = document.getElementById('idx-cov-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, fmtAgo } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const POLL_MS = 60000;
    const NS = 'http://www.w3.org/2000/svg';
    const H = 240;
    const M = { top: 10, right: 8, bottom: 22, left: 44 };
    // The narrowest a bar and the space after it may be (px) before the passes are grouped, and the
    // buckets they are grouped into (seconds, on the local clock: 1 h … 12 h, a day, two days, a week).
    const MIN_SLOT = 4;
    const BUCKET_STEPS = [3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800];
    // Below this a bucket's worst pass gets its tick — the line where the average tile stops being green.
    const WORST_MARK = 95;

    let range = '24h';
    let last = null;
    let pinned = null;          // the part whose words stay open after a tap…
    let pinnedTs = null;        // …and which poll it is, so a redraw (the minute's reload, a resize) keeps them open
    let loadSeq = 0;            // which request is the current one (see load())

    const fmt = (n) => t.num(Number(n || 0));   // a count in the page's language (1.73.1)
    // 99.99 is the whole scrape: the tracker's count and the file's length differ by a handful
    const pct = (v) => (v === null || v === undefined) ? '—' : (v >= 99.95 ? '100' : Number(v).toFixed(1)) + ' %';
    const dur = (s) => {
        const n = Math.max(0, Math.round(Number(s) || 0));
        return n >= 60 ? t.key('js.coverage.dur_ms', {m: Math.floor(n / 60), s: n % 60}) : t.key('js.coverage.dur_s', {s: n});
    };
    const pollsText = (n) => n === 1 ? t.key('js.coverage.polls_one') : t.key('js.coverage.polls_n', {n: fmt(n)});
    const pad2 = (n) => (n < 10 ? '0' : '') + n;
    const when = (ts) => { const d = new Date(ts * 1000); return pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()); };
    const nowTs = () => Math.floor(Date.now() / 1000);

    // THE PANEL'S OWN TILE, not one that merely looks like it in the source.
    //
    // The first version used `.wl-kv` with `.wl-kv-k` / `.wl-kv-v`. `.wl-kv` is the CONTAINER for a
    // list of tiles (the shape modals use), and the other two have no rules in admin.css at all — so
    // the tiles came out as bare dark boxes with the text welded to the border and the label in body
    // type. The card next to this one on Traffic uses `.wl-kv-item` / `.wl-kv-label` /
    // `.wl-kv-value`, which is where the padding, the border and the small-caps label live.
    // `value` and `note` may be lists of pieces (1.73.0): a t.key() word among them keeps its key, so the live language
    // switch says it again — glued into one string it could not (assets/js/i18n.js). joined() makes such a list.
    const joined = (list, sep) => list.filter(Boolean).reduce((out, x, i) => (i ? out.push(sep, x) : out.push(x), out), []);
    const pieces = (v) => (Array.isArray(v) ? v : [v]);
    function tile(label, value, cls, note, id) {
        return el('div', { className: 'wl-kv-item', dataset: id ? { tile: id } : undefined }, [
            el('div', { className: 'wl-kv-label', text: label }),
            el('div', { className: 'wl-kv-value' }, [
                el('span', { className: cls || '' }, pieces(value)),
                note ? el('div', { className: 'wl-small text-muted' }, pieces(note)) : '',
            ]),
        ]);
    }

    function renderSummary(d) {
        const box = $('idx-cov-summary');
        box.textContent = '';
        const s = d.summary || {};
        const counted = s.counted || 0;
        // WHEN, as well as how many (1.72.1). A month's window counted production's 52 short downloads of
        // 04.09 – 05.09 and said them as a current warning three weeks later, "if it keeps happening, look
        // at the tracker's side of it". Recent = in the last day of the window (IDX_POLL_RECENT on the
        // server, which counts them): "the last 2 h 5 min ago"; older: "the last on 05.09 12:22 — none
        // since", in the card's own dd.mm hh:mm, the axis's format.
        const now = d.now || nowTs();
        const lastWords = (ts, recent) => !ts ? null
            : recent ? t.key('js.coverage.last_ago', {ago: fmtAgo(Math.max(0, now - ts))}) : t.key('js.coverage.last_old', {when: when(ts)});

        // The headline is coverage, because that is the question: did we see the whole tracker? Per
        // PASS — a pass counts every poll that continues it.
        let covCls = 'text-muted';
        if (s.avg_coverage !== null && s.avg_coverage !== undefined) {
            covCls = s.avg_coverage >= 95 ? 'text-success' : s.avg_coverage >= 70 ? 'text-warning' : 'text-danger';
        }
        // An average over one pass is not an average, and colouring it red says something the
        // number cannot support. Below three finished passes the figures are shown plainly.
        const thin = counted < 3;
        box.appendChild(tile(t.key('js.coverage.avg_coverage'), pct(s.avg_coverage), thin ? '' : covCls,
            !counted ? t.key('js.coverage.no_finished_pass')
                : thin ? t.key('js.coverage.over_passes_thin', {n: fmt(counted)}) : t.key('js.coverage.over_passes', {n: fmt(counted)}), 'avg'));
        // The worst pass says when it began; its colour is a warning only while it is recent (1.72.1).
        const worstLow = !thin && s.min_coverage !== null && s.min_coverage !== undefined && s.min_coverage < 70;
        box.appendChild(tile(t.key('js.coverage.worst_pass'), pct(s.min_coverage), (worstLow && s.worst_recent) ? 'text-warning' : '',
            (s.worst_ts && s.min_coverage !== null && s.min_coverage !== undefined && s.min_coverage < 99.95)
                ? t.key('js.coverage.worst_when', {when: when(s.worst_ts)}) : null, 'worst'));
        box.appendChild(tile(t.key('js.coverage.passes_in_window'), fmt(s.passes), '',
            t.key('js.coverage.passes_polls_note', {n: fmt(s.polls)}), 'passes'));
        // A cut is the budget doing its job, not a fault: muted, and said as what it is.
        box.appendChild(tile(t.key('js.coverage.arrived_truncated'), fmt(s.cut), 'text-muted',
            s.cut ? t.key('js.coverage.truncated_note') : null, 'cut'));
        // A download that really ended short keeps a word of its own, and only appears when it happened —
        // a warning while it is recent, a date once it is not.
        if (s.short) {
            const recent = (s.short_recent || 0) > 0;
            box.appendChild(tile(t.key('js.coverage.ended_early'), fmt(s.short), recent ? 'text-warning' : 'text-muted',
                recent ? joined([t.key('js.coverage.ended_early_note'), lastWords(s.short_last, true)], ' · ')
                       : lastWords(s.short_last, false), 'short'));
        }
        const failRecent = (s.failed_recent || 0) > 0;
        box.appendChild(tile(t.key('js.coverage.failed'), fmt(s.failed), (s.failed && failRecent) ? 'text-danger' : 'text-muted',
            s.failed ? lastWords(s.failed_last, failRecent) : null, 'failed'));
        const lp = s.last_pass;
        if (lp) {
            if (lp.status === 'in_progress') {
                box.appendChild(tile(t.key('js.coverage.last_pass'), t.key('js.coverage.in_progress_value', {n: fmt(lp.walked)}), 'text-info',
                    t.key('js.coverage.in_progress_note', {n: fmt(lp.walked)}), 'last'));
            } else {
                box.appendChild(tile(t.key('js.coverage.last_pass'),
                    [fmt(lp.walked)].concat(lp.rows_total ? [' ', t.key('js.coverage.of_total', {n: fmt(lp.rows_total)})] : []), '',
                    joined([pct(lp.coverage), pollsText(lp.polls), t.key('js.coverage.poll_time', {t: dur(lp.seconds)})], ' · '), 'last'));
            }
        }

        // A note only when there is something to say. "Everything is fine" does not need a sentence.
        const note = $('idx-cov-note');
        note.textContent = '';
        if (d.unavailable) {
            note.appendChild(el('p', { className: 'wl-small text-muted mb-0',
                text: d.message || t.key('js.coverage.no_history') }));
            return;
        }
        const alerts = [];
        if (s.short) {
            // "If it keeps happening…" only while it does: in the last day. Before that it is history,
            // said plainly with its date.
            if ((s.short_recent || 0) > 0 && s.short_last) {
                alerts.push(['alert-warning', 'short', t.key('js.coverage.ended_early_alert', {n: fmt(s.short), ago: fmtAgo(Math.max(0, now - s.short_last))})]);
            } else {
                alerts.push(['alert-secondary', 'short', t.key('js.coverage.ended_early_old_alert', {n: fmt(s.short), when: s.short_last ? when(s.short_last) : '—'})]);
            }
        }
        // Every finished pass took more than one poll: the budget is shorter than the scrape. Said as
        // what it is — nothing lost — with the measured estimate of what a longer budget would do.
        if (counted && s.min_polls > 1) {
            const text = [t.key('js.coverage.all_truncated_alert', {budget: s.budget, max: s.budget_max})];
            const e = s.estimate;
            if (e) {
                text.push(' ', t.key(e.polls > 1 ? 'js.coverage.estimate_many' : 'js.coverage.estimate_one',
                    {rate: fmt(e.rate), scrape: fmt(e.scrape), needs: fmt(e.needs), budget: e.budget, polls: e.polls}));
                if (e.polls > 1 && s.budget_max && e.needs <= s.budget_max) text.push(' ', t.key('js.coverage.estimate_at_max', {max: s.budget_max}));
            }
            alerts.push(['alert-info', 'multi', text]);
        }
        if (s.avg_coverage !== null && s.avg_coverage !== undefined && s.avg_coverage < 70 && counted > 2) {
            alerts.push(['alert-info', 'low', t.key('js.coverage.low_coverage_alert', {pct: pct(s.avg_coverage)})]);
        }
        alerts.forEach(([cls, id, text], i) => note.appendChild(el('div', {
            className: 'alert ' + cls + ' py-2 wl-small ' + (i < alerts.length - 1 ? 'mb-2' : 'mb-0'), dataset: { alert: id } }, pieces(text))));
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
    // Up to a quarter of a year between ticks (1.72.1): the week was the last step, and a phone showed
    // "All" (90 days) as thirteen dates in 244 px, printed over each other.
    const TIME_STEPS = [1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 345600, 604800, 1209600, 2419200, 4838400, 7862400];
    function timeTicks(x0, x1, most) {
        let step = TIME_STEPS[TIME_STEPS.length - 1];
        for (const s of TIME_STEPS) { if ((x1 - x0) / s <= most) { step = s; break; } }
        const d = new Date(x0 * 1000);
        d.setHours(0, 0, 0, 0);
        const out = [];
        if (step >= 86400) {
            // Whole days on the local calendar, so a tick stays on a midnight (a date) across a change of
            // the clocks — adding seconds put a weekly tick at 23:00 after one.
            const k = Math.round(step / 86400);
            while (d.getTime() / 1000 < x0) d.setDate(d.getDate() + 1);
            for (; d.getTime() / 1000 <= x1; d.setDate(d.getDate() + k)) out.push(Math.floor(d.getTime() / 1000));
            return out;
        }
        let ts = Math.floor(d.getTime() / 1000);
        while (ts < x0) ts += step;
        for (; ts <= x1; ts += step) out.push(ts);
        return out;
    }
    const tickLabel = (ts) => { const d = new Date(ts * 1000); return (d.getHours() === 0 && d.getMinutes() === 0) ? pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) : pad2(d.getHours()) + ':' + pad2(d.getMinutes()); };

    // Buckets on the local clock: an hour bucket starts on a whole hour (a 6-hour one at 00, 06, 12, 18),
    // a day at midnight, and two days or a week are counted in whole days from a Monday (2024-01-01 was
    // one), so every week starts on a Monday. Date arithmetic, not seconds: a day with a clock change is
    // still one day.
    function bucketFloor(ts, step) {
        const d = new Date(ts * 1000);
        if (step < 86400) {
            const h = step / 3600;
            d.setHours(Math.floor(d.getHours() / h) * h, 0, 0, 0);
            return Math.floor(d.getTime() / 1000);
        }
        d.setHours(0, 0, 0, 0);
        const k = Math.round(step / 86400);
        if (k === 1) return Math.floor(d.getTime() / 1000);
        const n = Math.round((d.getTime() - new Date(2024, 0, 1).getTime()) / 86400000);
        return Math.floor(new Date(2024, 0, 1 + Math.floor(n / k) * k).getTime() / 1000);
    }
    function bucketNext(b, step) {
        const d = new Date(b * 1000);
        if (step < 86400) d.setHours(d.getHours() + step / 3600);
        else d.setDate(d.getDate() + Math.round(step / 86400));
        return Math.floor(d.getTime() / 1000);
    }
    function spanName(step) {
        if (step < 86400) return t.key('js.coverage.span_hours', {n: step / 3600});
        if (step === 86400) return t.key('js.coverage.span_day');
        if (step === 172800) return t.key('js.coverage.span_two_days');
        return t.key('js.coverage.span_week');
    }
    function spanWords(b0, b1, step) {
        const day = (ts) => { const d = new Date(ts * 1000); return pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1); };
        const hm = (ts) => { const d = new Date(ts * 1000); return pad2(d.getHours()) + ':' + pad2(d.getMinutes()); };
        if (step === 86400) return day(b0);
        if (step > 86400) return day(b0) + ' – ' + day(b1 - 1);
        return day(b0) + ' ' + hm(b0) + ' – ' + hm(b1);
    }

    /** The words for one part: what the poll did, and what its pass came to. */
    function partWords(p, pass, soFar) {
        const lines = [];
        lines.push(pass.polls > 1 ? t.key('js.coverage.tip_head', {when: when(p.ts), pos: p.pos, of: pass.polls})
                                  : t.key('js.coverage.tip_head_one', {when: when(p.ts)}));
        if (p.kind === 'error') {
            lines.push(t.key('js.coverage.tip_error', {err: p.error || '?'}));
        } else if (p.kind === 'short') {
            lines.push(t.key('js.coverage.tip_short', {n: fmt(p.entries), reason: p.partial || '?'}));
        } else if (p.kind === 'start') {
            lines.push(p.cut ? t.key('js.coverage.tip_start_cut', {n: fmt(p.entries)}) : t.key('js.coverage.tip_start_full', {n: fmt(p.entries)}));
        } else if (p.delivered === 0 && !p.cut) {
            lines.push(t.key('js.coverage.tip_resume_empty', {from: fmt(p.start), pct: pct(pass.coverage)}));
        } else if (p.cut) {
            lines.push(t.key('js.coverage.tip_resume_cut', {from: fmt(p.start), pct: pct(soFar)}));
        } else {
            lines.push(t.key('js.coverage.tip_resume', {from: fmt(p.start), pct: pct(pass.coverage)}));
        }
        // Old ground walked a second time (a short download restarting inside an open pass): it adds
        // nothing to the bar but what was new in it, and says so.
        if (p.again > 0) {
            lines.push(p.new > 0 ? t.key('js.coverage.tip_again', {n: fmt(p.entries), m: fmt(p.new)}) : t.key('js.coverage.tip_again_none', {n: fmt(p.entries)}));
        }
        lines.push(t.key('js.coverage.tip_numbers', {d: fmt(p.delivered), k: fmt(p.kept), t: dur(p.ms / 1000)}));
        const state = pass.began_before ? t.key('js.coverage.pass_began_before')
            : t.key('js.coverage.pass_' + (pass.status === 'in_progress' ? 'in_progress' : pass.status));
        const passPct = pass.status === 'in_progress' ? t.key('js.coverage.in_progress_value', {n: fmt(pass.walked)})
            : pass.began_before ? '—' : pct(pass.coverage);
        lines.push(t.key('js.coverage.tip_pass', {pct: passPct, state: state}));
        return lines;
    }

    /** The words for one bucket: its time, what it holds, and how the passes in it did — the tiles' words. */
    function bucketWords(b, step) {
        const lines = [spanWords(b.b0, b.b1, step)];
        // Glued lines are plain words (t()): the chart draws itself again on `langswap` from the answer it holds.
        lines.push(t('js.coverage.bucket_passes', {n: fmt(b.passes.length)}) + ' · ' + t('js.coverage.passes_polls_note', {n: fmt(b.polls)}));
        lines.push(t('js.coverage.avg_coverage') + ': ' + pct(b.avg)
            + (b.worst ? ' · ' + t('js.coverage.worst_pass') + ': ' + pct(b.worst.coverage) : ''));
        lines.push(t('js.coverage.arrived_truncated') + ': ' + fmt(b.cut));
        if (b.short) lines.push(t('js.coverage.ended_early') + ': ' + fmt(b.short));
        lines.push(t('js.coverage.failed') + ': ' + fmt(b.failed));
        if (b.hi) lines.push(t('js.coverage.legend_tracker') + ': ' + (b.lo < b.hi * 0.99 ? fmt(b.lo) + ' – ' + fmt(b.hi) : fmt(b.hi)));
        if (b.open) lines.push(t.key('js.coverage.in_progress_note', {n: fmt(b.open.walked)}));
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
        box.querySelectorAll('.idx-cov-seg.is-on, .idx-cov-bucket.is-on').forEach(n => n.classList.remove('is-on'));
        (part.closest('.idx-cov-bucket') || part).classList.add('is-on');
    }
    function hideTip() {
        const box = $('idx-cov-chart');
        const tip = box && box.querySelector('.idx-cov-tip');
        if (tip) tip.hidden = true;
        if (box) box.querySelectorAll('.idx-cov-seg.is-on, .idx-cov-bucket.is-on').forEach(n => n.classList.remove('is-on'));
        pinned = null;
        pinnedTs = null;
    }
    function pin(part) {
        const box = $('idx-cov-chart');
        // a bucket's target is its whole column: the words open over its bar
        const r = box.getBoundingClientRect(), pr = (part.__anchor || part).getBoundingClientRect();
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
        const line = [];   // pieces, each t.key() word keeping its key (1.73.0)
        if (one) {
            line.push(t.key('js.coverage.one_poll_line', {when: new Date(one.ts * 1000).toLocaleString(),
                delivered: fmt(one.delivered), kept: fmt(one.kept)}));
            if (pass && pass.status !== 'in_progress' && !pass.began_before && pass.coverage !== null) {
                line.push(' · ', t.key('js.coverage.one_poll_coverage', {pct: pct(pass.coverage)}));
            }
            if (one.partial) line.push(' · ', t.key('js.index.ended_early_badge'));
            else if (one.cut) line.push(' · ', t.key('js.coverage.one_poll_truncated'));
        }
        box.appendChild(el('div', { className: 'idx-cov-empty' }, [
            el('div', { text: points.length ? t.key('js.coverage.one_poll_so_far') : t.key('js.coverage.no_polls_yet') }),
            one ? el('div', { className: 'idx-cov-empty-one' }, line) : '',
            points.length ? el('div', { className: 'idx-cov-empty-hint', text: t.key('js.coverage.wider_range_hint') }) : '',
        ]));
    }

    /** One bar per pass, its polls stacked in it — each part the new ground its poll walked. */
    function drawPasses(root, c) {
        const { passes, byPass, X, Y, pw } = c;
        // A bar's width follows the passes' own spacing (the median gap), so a day of half-hourly
        // passes and a week of them both read as bars — at least 3 px (the spacing is at least
        // MIN_SLOT here), never a slab. Under 5 px the dark 1-px outline would eat the fill: none.
        const xs = passes.map(x => X(x.first_ts));
        const gaps = [];
        for (let i = 1; i < xs.length; i++) gaps.push(xs[i] - xs[i - 1]);
        gaps.sort((a, b) => a - b);
        const gap = gaps.length ? gaps[gaps.length >> 1] : pw / 4;
        const bw = Math.max(3, Math.min(18, gap * 0.7));
        if (bw < 5) root.classList.add('idx-cov-thin');

        const facts = { kinds: new Set(), progress: false };
        const bars = svg('g', { class: 'idx-cov-bars' });
        passes.forEach((pass, i) => {
            const g = svg('g', { class: 'idx-cov-pass' + (pass.status === 'in_progress' ? ' idx-cov-open' : ''),
                'data-pass': i, 'data-status': pass.status });
            const bx = xs[i];
            let reach = 0, top = 0, slivers = 0;
            byPass[i].forEach(p => {
                facts.kinds.add(p.kind);
                reach = Math.max(reach, p.entries);
                const soFar = p.rows_total ? Math.min(100, (100 * reach) / p.rows_total) : null;
                // Where the part stands: on the ground it walked for the first time in this pass (the
                // server's new_from / new; a reply without them is the 1.72.0 stack of deliveries).
                const from = p.new_from !== undefined ? p.new_from : top;
                const nw = p.new !== undefined ? p.new : p.delivered;
                let y1, hgt;
                if (nw > 0) {
                    y1 = Y(from);
                    hgt = Math.max(2, y1 - Y(from + nw));        // a tail of a few entries still has a part to point at
                    slivers = 0;
                } else {
                    // Nothing new: a sliver INSIDE the bar, under what the pass had reached — pointable,
                    // and adding nothing to the height. Several in a row stack downwards.
                    hgt = 2;
                    y1 = Math.min(Y(0), Y(Math.max(top, from)) + hgt * (slivers + 1));
                    slivers++;
                }
                const part = svg('rect', { class: 'idx-cov-seg idx-cov-k-' + p.kind, x: bx, y: y1 - hgt, width: bw, height: hgt,
                    'data-ts': p.ts, 'data-pos': p.pos, 'data-kind': p.kind, role: 'img' });
                part.__words = partWords(p, pass, soFar);
                part.setAttribute('aria-label', part.__words.join('. '));
                g.appendChild(part);
                if (nw > 0 && p.kept > 0 && p.delivered > 0) {
                    const kh = Math.max(1, hgt * Math.min(1, p.kept / p.delivered));
                    g.appendChild(svg('rect', { class: 'idx-cov-kept', x: bx, y: y1 - kh, width: bw, height: kh }));
                }
                top = Math.max(top, from + nw);
            });
            // In progress: what is still to walk, as an outline up to the tracker's count.
            if (pass.status === 'in_progress' && pass.rows_total && pass.rows_total > top) {
                facts.progress = true;
                g.appendChild(svg('rect', { class: 'idx-cov-rest', x: bx, y: Y(pass.rows_total), width: bw, height: Math.max(0, Y(top) - Y(pass.rows_total)) }));
            }
            bars.appendChild(g);
        });
        root.appendChild(bars);
        return facts;
    }

    /** One bar per bucket of time — the passes that began in it, and the tracker's count read in it. */
    function drawBuckets(root, c) {
        const { passes, points, X, Y, step, slots, x1, ph } = c;
        const slotOf = (ts) => {
            if (ts <= slots[0]) return 0;
            let lo = 0, hi = slots.length - 1;
            while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (slots[mid] <= ts) lo = mid; else hi = mid - 1; }
            return lo;
        };
        const B = slots.map((b0, i) => ({ i, b0, b1: i + 1 < slots.length ? slots[i + 1] : x1, passes: [], polls: 0, cut: 0, short: 0, failed: 0, counts: [] }));
        passes.forEach(x => B[slotOf(x.first_ts)].passes.push(x));
        points.forEach(p => {
            const pass = passes[p.pass];
            if (pass) {
                // a poll is counted in its pass's bar, as the tiles count it in the window
                const b = B[slotOf(pass.first_ts)];
                b.polls++;
                if (p.cut) b.cut++;
                if (p.partial) b.short++;
                if (p.error) b.failed++;
            }
            // …and the tracker's count where and when it was read; NULL or 0 is unknown, not a count
            if (p.rows_total > 0) B[slotOf(p.ts)].counts.push(p.rows_total);
        });

        const facts = { step, worst: false, short: false, failed: false, progress: false };
        const bars = svg('g', { class: 'idx-cov-bars' });
        B.forEach(b => {
            const done = b.passes.filter(x => x.counted && x.coverage !== null && x.coverage !== undefined);
            b.avg = done.length ? done.reduce((a, x) => a + x.coverage, 0) / done.length : null;
            b.worst = null;
            done.forEach(x => { if (!b.worst || x.coverage <= b.worst.coverage) b.worst = x; });
            b.hi = b.counts.length ? Math.max(...b.counts) : null;
            b.lo = b.counts.length ? Math.min(...b.counts) : null;
            b.open = b.passes.find(x => x.status === 'in_progress') || null;
            if (!b.passes.length) return;

            // The bar: the mean coverage of the finished passes, of the bucket's count. With none
            // finished it is the pass in progress (dimmed, what is left outlined), or what a pass that
            // began before these rows walked.
            let use, topV, dim = false;
            if (done.length) { use = done; topV = b.hi ? (b.avg / 100) * b.hi : done.reduce((a, x) => a + x.walked, 0) / done.length; }
            else if (b.open) { use = [b.open]; topV = b.open.walked; dim = true; }
            else { use = b.passes; topV = Math.max(...b.passes.map(x => x.walked || 0)); }
            const px0 = X(b.b0), px1 = X(b.b1), wSlot = Math.max(0, px1 - px0);
            const bw = Math.max(3, wSlot - Math.max(1, wSlot * 0.25));
            const bx = px0 + (wSlot - bw) / 2;
            const yBase = Y(0), yTop = Math.min(yBase - 1, Y(topV));
            const g = svg('g', { class: 'idx-cov-bucket' + (dim ? ' idx-cov-open' : ''), 'data-bucket': b.i, 'data-b0': b.b0, 'data-b1': b.b1,
                'data-passes': b.passes.length, 'data-polls': b.polls, 'data-avg': b.avg === null ? '' : b.avg.toFixed(2),
                'data-worst': b.worst ? b.worst.coverage : '', 'data-hi': b.hi === null ? '' : b.hi, 'data-lo': b.lo === null ? '' : b.lo,
                'data-short': b.short, 'data-failed': b.failed, 'data-cut': b.cut });
            const bar = svg('rect', { class: 'idx-cov-bar', x: bx, y: yTop, width: bw, height: yBase - yTop });
            g.appendChild(bar);
            // the darker part: what was kept of the ground the passes walked
            const ground = use.reduce((a, x) => a + (x.ground || x.walked || 0), 0);
            const keptN = use.reduce((a, x) => a + (x.kept_new || 0), 0);
            if (ground > 0 && keptN > 0) {
                const kh = Math.max(1, (yBase - yTop) * Math.min(1, keptN / ground));
                g.appendChild(svg('rect', { class: 'idx-cov-kept', x: bx, y: yBase - kh, width: bw, height: kh }));
            }
            if (dim && b.open.rows_total && b.open.rows_total > topV) {
                facts.progress = true;
                g.appendChild(svg('rect', { class: 'idx-cov-rest', x: bx, y: Y(b.open.rows_total), width: bw, height: Math.max(0, yTop - Y(b.open.rows_total)) }));
            }
            // The worst pass, where it fell short: a light tick across the bar at its level.
            if (b.worst && b.worst.coverage < WORST_MARK) {
                facts.worst = true;
                const wy = Y(b.hi ? (b.worst.coverage / 100) * b.hi : b.worst.walked);
                g.appendChild(svg('rect', { class: 'idx-cov-worst', x: bx - 1.5, y: wy - 1, width: bw + 3, height: 2 }));
            }
            // A download that ended early, a failed poll: a strip at the bar's foot, in the legend's colours.
            let foot = 0;
            if (b.short) { facts.short = true; g.appendChild(svg('rect', { class: 'idx-cov-ev idx-cov-ev-short', x: bx, y: yBase - 3, width: bw, height: 3 })); foot = 3; }
            if (b.failed) { facts.failed = true; g.appendChild(svg('rect', { class: 'idx-cov-ev idx-cov-ev-error', x: bx, y: yBase - 3 - foot, width: bw, height: 3 })); }
            // The whole column is what a pointer or a finger finds — a 3-px bar is no target on a phone.
            const hit = svg('rect', { class: 'idx-cov-seg idx-cov-hit', x: px0, y: M.top, width: wSlot, height: ph,
                'data-ts': 'b' + b.b0, 'data-kind': 'bucket', role: 'img' });
            hit.__words = bucketWords(b, step);
            hit.__anchor = bar;
            hit.setAttribute('aria-label', hit.__words.join('. '));
            g.appendChild(hit);
            bars.appendChild(g);
        });
        root.appendChild(bars);

        // The tracker's count per bucket, at the bucket's middle — a gap where a bucket has none.
        let path = '', pen = false;
        B.forEach(b => {
            if (!b.hi) { pen = false; return; }
            path += (pen ? 'L' : 'M') + ((X(b.b0) + X(b.b1)) / 2).toFixed(1) + ' ' + Y(b.hi).toFixed(1) + ' ';
            pen = true;
        });
        facts.path = path.trim();
        return facts;
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
        // three passes in it is still drawn as a day. "All" asks for the history there is, so it starts
        // at the oldest row (1.72.1) — from the retention's start it drew 64 empty days.
        const now = nowTs();
        let x0 = d.range === 'all' ? all[0].ts : Math.min(d.from || points[0].ts, points[0].ts);
        let x1 = Math.max(now, ...passes.map(x => x.end_ts || x.last_ts));
        const W = Math.max(240, box.clientWidth || 800);
        const pw = W - M.left - M.right, ph = H - M.top - M.bottom;

        // One bar per pass while there is room for one: the passes' median spacing at this width. Below
        // MIN_SLOT the passes are grouped, into the smallest bucket of time that has the room, and the
        // axis is widened to whole buckets. Worked out on every redraw — a resize, a phone turned round.
        const perSec = pw / Math.max(1, x1 - x0);
        const starts = passes.map(x => Math.max(x0, x.first_ts));
        const spacing = [];
        for (let i = 1; i < starts.length; i++) spacing.push((starts[i] - starts[i - 1]) * perSec);
        spacing.sort((a, b) => a - b);
        const slot = spacing.length ? spacing[spacing.length >> 1] : pw / 4;
        let step = 0, slots = null;
        if (slot < MIN_SLOT) {
            step = BUCKET_STEPS.find(s => s * perSec >= MIN_SLOT) || BUCKET_STEPS[BUCKET_STEPS.length - 1];
            slots = [];
            for (let b = bucketFloor(x0, step); b < x1; b = bucketNext(b, step)) slots.push(b);
            x0 = slots[0];
            x1 = bucketNext(slots[slots.length - 1], step);
        }
        const X = (ts) => M.left + ((Math.max(x0, ts) - x0) / Math.max(1, x1 - x0)) * pw;

        // The y axis: the larger of the tracker's count and the furthest entry a pass reached, with a
        // little headroom — never a sum (1.72.1; the stack of re-walked parts took the month to 6M).
        let top = 1;
        passes.forEach(x => { top = Math.max(top, x.walked || 0, x.rows_total || 0); });
        points.forEach(p => { if (p.rows_total) top = Math.max(top, p.rows_total); });
        const ystep = niceStep(top * 1.05, 5);
        const yMax = Math.ceil((top * 1.05) / ystep) * ystep;
        const Y = (v) => M.top + ph - (v / yMax) * ph;

        const root = svg('svg', { class: 'idx-cov-svg', width: W, height: H, viewBox: '0 0 ' + W + ' ' + H,
            role: 'group', 'data-mode': step ? 'bucket' : 'pass', 'data-step': step || null, 'data-ymax': yMax,
            'aria-label': step ? t.key('js.coverage.chart_aria_buckets', {p: fmt(passes.length), n: fmt(points.length), span: spanName(step)})
                               : t.key('js.coverage.chart_aria', {p: fmt(passes.length), n: fmt(points.length)}) });

        // grid + y axis
        const gy = svg('g', { class: 'idx-cov-axis' });
        for (let v = 0; v <= yMax + 0.5; v += ystep) {
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

        let facts, path = '';
        if (step) {
            facts = drawBuckets(root, { passes, points, X, Y, step, slots, x1, ph });
            path = facts.path;
        } else {
            facts = drawPasses(root, { passes, byPass, X, Y, pw });
            // the tracker's own count, where it was known — a gap where it was not
            let pen = false;
            points.forEach(p => {
                if (!p.rows_total) { pen = false; return; }
                path += (pen ? 'L' : 'M') + X(p.ts).toFixed(1) + ' ' + Y(p.rows_total).toFixed(1) + ' ';
                pen = true;
            });
            path = path.trim();
        }
        if (path) root.appendChild(svg('path', { class: 'idx-cov-tracker', d: path }));

        box.textContent = '';
        box.appendChild(root);
        legend(d, facts);

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
    function legend(d, facts) {
        let box = $('idx-cov-legend');
        if (!box) {
            box = el('div', { className: 'idx-cov-legend', id: 'idx-cov-legend' });
            $('idx-cov-chart').after(box);
        }
        box.textContent = '';
        if (!d) return;
        const item = (k, text) => box.appendChild(el('span', { className: 'idx-cov-leg', dataset: { leg: k } }, [
            el('span', { className: 'idx-cov-sw idx-cov-sw-' + k, 'aria-hidden': 'true' }), text]));
        if (facts && facts.step) {
            // one bar per bucket: what a bar is, and the marks it can carry
            item('bucket', t.key('js.coverage.legend_bucket', {span: spanName(facts.step)}));
            item('kept', t.key('js.coverage.legend_kept'));
            if (facts.worst) item('worst', t.key('js.coverage.legend_worst', {pct: WORST_MARK}));
            if (facts.short) item('short', t.key('js.coverage.legend_short'));
            if (facts.failed) item('error', t.key('js.coverage.legend_error'));
            item('tracker', t.key('js.coverage.legend_tracker'));
            if (facts.progress) item('rest', t.key('js.coverage.legend_progress'));
            return;
        }
        const kinds = new Set((d.points || []).concat(d.lead || []).map(p => p.kind));
        const items = [['start', 'legend_start'], ['resume', 'legend_resume']];
        if (kinds.has('short')) items.push(['short', 'legend_short']);
        if (kinds.has('error')) items.push(['error', 'legend_error']);
        items.push(['kept', 'legend_kept'], ['tracker', 'legend_tracker']);
        if ((d.passes || []).some(x => x.status === 'in_progress')) items.push(['rest', 'legend_progress']);
        items.forEach(([k, key]) => item(k, t.key('js.coverage.' + key)));
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
                text: t.key('js.coverage.load_failed', {error: r.error}) }));
            return;
        }
        last = r;
        const u = $('idx-cov-updated');
        if (u) u.textContent = t.key('js.coverage.updated_line', {p: fmt((r.passes || []).length), n: fmt((r.points || []).length), range: range});
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
    // The width decides between one bar per pass and buckets, so a resize draws anew — and re-buckets.
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { if (last) draw(last); }, 150);
    });
    // A live language switch (1.73.0): the chart's bars carry whole sentences for a screen reader (each a pass, its
    // polls, its coverage), made from the answer — drawn again from that answer, no request. The tiles and notes
    // above it keep their keys and follow by themselves (assets/js/i18n.js).
    document.addEventListener('langswap', () => { if (last) draw(last); });

    load();
    setInterval(load, POLL_MS);
})();
