/**
 * The stability probe's card.
 *
 * Three states and nothing in between: idle (with the last report, if there is one), running (a live
 * step list), and finished (the report, with an Apply that only offers values the run actually held).
 *
 * The Apply is deliberately narrow. A report whose buttons could set any number would be decoration —
 * the entire value of this feature is that the figure it offers was applied to the real machine for
 * three minutes while somebody watched what else broke.
 */
(function () {
    'use strict';
    const card = document.getElementById('tn-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction, promptPassword, fmtAgo } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);

    const POLL_IDLE = 15000;
    const POLL_RUNNING = 5000;
    let timer = null;
    let state = {};

    const num = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString());

    async function load() {
        const r = await apiCall('admin/tuner', 'POST', { op: 'status' });
        if (!r || !r.success) return;
        state = r;
        render();
        clearTimeout(timer);
        timer = setTimeout(load, r.running ? POLL_RUNNING : POLL_IDLE);
    }

    function kv(label, value, cls) {
        return el('div', { className: 'wl-kv-item' }, [
            el('div', { className: 'wl-kv-label', text: label }),
            el('div', { className: 'wl-kv-value ' + (cls || '') }, value),
        ]);
    }

    function render() {
        card.classList.toggle('d-hidden', !state.enabled);
        if (!state.enabled) return;

        $('tn-updated').textContent = state.updated_at
            ? t('js.tuner.updated_ago', {ago: fmtAgo(Math.max(0, state.server_time - state.updated_at))}) : '';
        $('tn-cancel').classList.toggle('d-hidden', !state.running);
        $('tn-start').disabled = state.running || !state.available;
        $('tn-dry').disabled = state.running || !state.available;

        const g = $('tn-grid');
        g.textContent = '';
        g.appendChild(kv(t('js.tuner.moving'), [el('span', { text: {
            inbound: t('js.tuner.what_inbound'), outbound: t('js.tuner.what_outbound'), both: t('js.tuner.what_both'),
        }[state.what] || t('js.tuner.what_inbound') })]));
        g.appendChild(kv(t('js.tuner.state'), [
            el('span', { className: 'wl-badge ' + (state.running ? 'wl-b-warn' : 'wl-b-muted'),
                         text: state.running ? (state.phase || t('js.tuner.phase_running')) : (state.phase || t('js.tuner.phase_idle')) }),
            state.dry_run ? el('span', { className: 'wl-badge wl-b-muted', text: t('js.tuner.test_run') }) : '',
        ]));
        if (state.baseline && state.baseline.arriving_pps) {
            g.appendChild(kv(t('js.tuner.arriving_at_start'), t('js.tuner.pps', {n: num(Math.round(state.baseline.arriving_pps))})));
        }
        if (state.plan && state.plan.length) {
            g.appendChild(kv(t('js.tuner.plan'), t('js.tuner.pps', {n: state.plan.map(p => num(p)).join(' → ')})));
        }
        if (state.running && state.eta_s) {
            const left = Math.max(0, state.started_at + state.eta_s - state.server_time);
            g.appendChild(kv(t('js.tuner.about'), t('js.tuner.time_left', {t: fmtAgo(left)})));
        }
        // The one fact that makes this safe to press, said on the card rather than only in the docs.
        g.appendChild(kv(t('js.tuner.way_back'), state.has_restore
            ? [el('span', { className: 'wl-badge wl-b-ok', text: t('js.tuner.restore_recorded') }),
               el('div', { className: 'wl-small text-muted', text: t('js.tuner.restore_note') })]
            : [el('span', { className: 'wl-badge wl-b-muted', text: t('js.tuner.nothing_to_restore') })]));

        renderProgress();
        renderReport();

        const note = $('tn-note');
        if (state.stale) {
            note.className = 'nl-note nl-note-warn';
            note.textContent = t('js.tuner.note_stale');
        } else if (state.requested) {
            note.className = 'nl-note nl-note-info';
            note.textContent = t('js.tuner.note_requested');
        } else if (state.error) {
            note.className = 'nl-note nl-note-bad';
            note.textContent = t('js.tuner.note_failed', {error: state.error});
        } else if (!state.available) {
            note.className = 'nl-note nl-note-warn';
            note.textContent = t('js.tuner.note_unavailable');
        } else {
            note.className = 'nl-note nl-note-info';
            note.textContent = t('js.tuner.note_idle');
        }
    }

    function renderProgress() {
        const box = $('tn-progress');
        const steps = state.steps || [];
        box.classList.toggle('d-hidden', !steps.length);
        if (!steps.length) return;
        box.textContent = '';
        steps.forEach(s => {
            const row = el('div', { className: 'tn-step' + (s.ok ? '' : ' tn-step-bad') });
            row.appendChild(el('span', { className: 'tn-step-pps', text: t('js.tuner.pps', {n: num(s.limit_pps)}) }));
            row.appendChild(el('span', { className: 'tn-step-fig',
                text: t('js.tuner.step_served', {n: s.served_pps === null ? '—' : num(Math.round(s.served_pps))}) }));
            row.appendChild(el('span', { className: 'tn-step-fig',
                text: t('js.tuner.step_dropped', {n: s.dropped_pps === null ? '—' : num(Math.round(s.dropped_pps))}) }));
            row.appendChild(el('span', { className: 'tn-step-fig',
                text: s.load_per_core === null ? t('js.tuner.step_load_none') : t('js.tuner.step_load', {n: s.load_per_core.toFixed(2)}) }));
            row.appendChild(el('span', { className: 'tn-step-verdict', text: s.ok ? t('js.tuner.no_harm') : s.harm }));
            box.appendChild(row);
        });
    }

    function renderReport() {
        const box = $('tn-report');
        const rep = state.report;
        box.classList.toggle('d-hidden', !rep || state.running);
        if (!rep || state.running) return;
        box.textContent = '';
        box.appendChild(el('div', { className: 'tn-report-head', text: t('js.tuner.report_head') }));
        // A run whose limits changed nothing is not a quiet result, it is a broken one — and it is
        // the shape that already produced a confident recommendation nobody should have followed.
        // It gets a warning of its own rather than a sentence buried in the summary.
        if (rep.inconclusive) {
            box.appendChild(el('div', { className: 'alert alert-warning py-2 wl-small mb-2',
                text: rep.inconclusive }));
        }
        box.appendChild(el('div', { className: 'tn-report-summary', text: rep.summary || '' }));

        const acts = el('div', { className: 'tn-report-acts' });
        // Only the values the run held. A suggestion the machine never actually ran at would be a
        // guess wearing a measurement's clothes.
        // The label names what the button will move. An outbound run's values go to the reply budget,
        // and calling that "the limit" was how the same number could be applied to the wrong one.
        const outbound = state.what === 'outbound';
        [['suggested_safe', outbound ? t('js.tuner.apply_safe_outbound') : t('js.tuner.apply_safe')],
         ['suggested_minimum', outbound ? t('js.tuner.apply_minimum_outbound') : t('js.tuner.apply_minimum')]]
            .forEach(([key, label]) => {
                const v = rep[key];
                if (!v) return;
                const b = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success' },
                    label + ' (' + t('js.tuner.pps', {n: num(v)}) + ')');
                b.addEventListener('click', () => applyLimit(v));
                acts.appendChild(b);
            });
        if (!acts.children.length) {
            acts.appendChild(el('span', { className: 'wl-small text-muted',
                text: rep.inconclusive
                    ? t('js.tuner.no_value_inconclusive')
                    : t('js.tuner.no_value_short') }));
        }
        box.appendChild(acts);
    }

    async function applyLimit(pps) {
        if (!await confirmAction(t('js.tuner.apply_title', {n: Number(pps).toLocaleString()}),
            t('js.tuner.apply_body'),
            { after: t('js.tuner.apply_after'),
              okLabel: t('js.tuner.apply_ok') })) return;
        const pw = await promptPassword(t('js.tuner.apply_limit'), t('js.tuner.confirm_password'));
        if (!pw) return;
        const r = await apiCall('admin/tuner', 'POST', { op: 'apply', pps, password: pw });
        showToast((r && (r.message || r.error)) || t('js.tuner.failed'), r && r.success ? 'success' : 'error');
        load();
    }

    async function start(dry) {
        const what = dry
            ? t('js.tuner.start_dry_body')
            : t('js.tuner.start_real_body');
        if (!await confirmAction(dry ? t('js.tuner.start_dry_title') : t('js.tuner.start_real_title'), what, {
            after: dry ? '' : t('js.tuner.start_real_after'),
            okLabel: dry ? t('js.tuner.start_dry_ok') : t('js.tuner.start_real_ok'), danger: !dry })) return;
        const pw = await promptPassword(t('js.tuner.probe_title'), t('js.tuner.confirm_password'));
        if (!pw) return;
        const r = await apiCall('admin/tuner', 'POST',
            { op: 'start', dry_run: !!dry, steps: 6, dwell: dry ? 30 : 180,
              what: $('tn-what').value, password: pw });
        showToast((r && (r.message || r.error)) || t('js.tuner.failed'), r && r.success ? 'success' : 'error');
        load();
    }

    async function cancel() {
        const pw = await promptPassword(t('js.tuner.stop_title'), t('js.tuner.confirm_password'));
        if (!pw) return;
        const r = await apiCall('admin/tuner', 'POST', { op: 'cancel', password: pw });
        showToast((r && (r.message || r.error)) || t('js.tuner.failed'), r && r.success ? 'success' : 'error');
        load();
    }

    $('tn-start').addEventListener('click', () => start(false));
    $('tn-dry').addEventListener('click', () => start(true));
    $('tn-cancel').addEventListener('click', cancel);
    load();
})();
