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
            ? 'updated ' + fmtAgo(Math.max(0, state.server_time - state.updated_at)) + ' ago' : '';
        $('tn-cancel').classList.toggle('d-hidden', !state.running);
        $('tn-start').disabled = state.running || !state.available;
        $('tn-dry').disabled = state.running || !state.available;

        const g = $('tn-grid');
        g.textContent = '';
        g.appendChild(kv('Moving', [el('span', { text: {
            inbound: 'the receive limit', outbound: 'the reply budget', both: 'both together',
        }[state.what] || 'the receive limit' })]));
        g.appendChild(kv('State', [
            el('span', { className: 'wl-badge ' + (state.running ? 'wl-b-warn' : 'wl-b-muted'),
                         text: state.running ? (state.phase || 'running') : (state.phase || 'idle') }),
            state.dry_run ? el('span', { className: 'wl-badge wl-b-muted', text: 'rehearsal' }) : '',
        ]));
        if (state.baseline && state.baseline.arriving_pps) {
            g.appendChild(kv('Arriving when it started', num(Math.round(state.baseline.arriving_pps)) + ' pps'));
        }
        if (state.plan && state.plan.length) {
            g.appendChild(kv('Plan', state.plan.map(p => num(p)).join(' → ') + ' pps'));
        }
        if (state.running && state.eta_s) {
            const left = Math.max(0, state.started_at + state.eta_s - state.server_time);
            g.appendChild(kv('About', fmtAgo(left) + ' left'));
        }
        // The one fact that makes this safe to press, said on the card rather than only in the docs.
        g.appendChild(kv('The way back', state.has_restore
            ? [el('span', { className: 'wl-badge wl-b-ok', text: 'recorded' }),
               el('div', { className: 'wl-small text-muted', text: 'the settings go back even if the run is killed' })]
            : [el('span', { className: 'wl-badge wl-b-muted', text: 'nothing to restore' })]));

        renderProgress();
        renderReport();

        const note = $('tn-note');
        if (state.stale) {
            note.className = 'nl-note nl-note-warn';
            note.textContent = 'A run stopped without finishing. The janitor puts the settings back within a minute.';
        } else if (state.requested) {
            note.className = 'nl-note nl-note-info';
            note.textContent = 'Requested — the janitor starts it on its next tick, within a minute.';
        } else if (state.error) {
            note.className = 'nl-note nl-note-bad';
            note.textContent = 'The last run failed: ' + state.error;
        } else if (!state.available) {
            note.className = 'nl-note nl-note-warn';
            note.textContent = 'tools/tuner.py is not on this server, so there is nothing to start.';
        } else {
            note.className = 'nl-note nl-note-info';
            note.textContent = 'Each step holds a limit for a few minutes and watches what happens — to the tracker '
                + 'and to everything else on this machine. It stops early if anything else starts dropping packets, '
                + 'and it always puts the settings back. Nothing is applied for you.';
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
            row.appendChild(el('span', { className: 'tn-step-pps', text: num(s.limit_pps) + ' pps' }));
            row.appendChild(el('span', { className: 'tn-step-fig',
                text: s.served_pps === null ? 'served —' : 'served ' + num(Math.round(s.served_pps)) }));
            row.appendChild(el('span', { className: 'tn-step-fig',
                text: s.dropped_pps === null ? 'dropped —' : 'dropped ' + num(Math.round(s.dropped_pps)) }));
            row.appendChild(el('span', { className: 'tn-step-fig',
                text: s.load_per_core === null ? 'load —' : 'load ' + s.load_per_core.toFixed(2) + '/core' }));
            row.appendChild(el('span', { className: 'tn-step-verdict', text: s.ok ? 'no harm' : s.harm }));
            box.appendChild(row);
        });
    }

    function renderReport() {
        const box = $('tn-report');
        const rep = state.report;
        box.classList.toggle('d-hidden', !rep || state.running);
        if (!rep || state.running) return;
        box.textContent = '';
        box.appendChild(el('div', { className: 'tn-report-head', text: 'What the last run found' }));
        box.appendChild(el('div', { className: 'tn-report-summary', text: rep.summary || '' }));

        const acts = el('div', { className: 'tn-report-acts' });
        // Only the values the run held. A suggestion the machine never actually ran at would be a
        // guess wearing a measurement's clothes.
        [['suggested_safe', 'Apply the safe limit'], ['suggested_minimum', 'Apply the minimum that refuses nothing']]
            .forEach(([key, label]) => {
                const v = rep[key];
                if (!v) return;
                const b = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success' },
                    label + ' (' + num(v) + ' pps)');
                b.addEventListener('click', () => applyLimit(v));
                acts.appendChild(b);
            });
        if (!acts.children.length) {
            acts.appendChild(el('span', { className: 'wl-small text-muted',
                text: 'The run did not get far enough to suggest a value.' }));
        }
        box.appendChild(acts);
    }

    async function applyLimit(pps) {
        if (!await confirmAction('Apply ' + Number(pps).toLocaleString() + ' pps',
            'This is the inbound firewall limit, set to a value this run actually held and watched.',
            { after: 'It replaces whatever is in force now, and persists across reboots like any other limit.',
              okLabel: 'Apply' })) return;
        const pw = await promptPassword('Apply the limit', 'Confirm with the admin password.');
        if (!pw) return;
        const r = await apiCall('admin/tuner', 'POST', { op: 'apply', pps, password: pw });
        showToast((r && (r.message || r.error)) || 'Failed', r && r.success ? 'success' : 'error');
        load();
    }

    async function start(dry) {
        const what = dry
            ? 'Walks the whole plan and changes nothing. Useful for checking the plumbing before a real run.'
            : 'Moves the inbound firewall limit through several values, holding each for a few minutes on a LIVE '
              + 'machine. It stops early if anything else starts losing packets.';
        if (!await confirmAction(dry ? 'Rehearse the probe' : 'Run the stability probe', what, {
            after: dry ? '' : 'The current settings are written down before the first change, so they go back even if '
                          + 'this is interrupted or the machine reboots.',
            okLabel: dry ? 'Rehearse' : 'Run it', danger: !dry })) return;
        const pw = await promptPassword('Stability probe', 'Confirm with the admin password.');
        if (!pw) return;
        const r = await apiCall('admin/tuner', 'POST',
            { op: 'start', dry_run: !!dry, steps: 6, dwell: dry ? 30 : 180,
              what: $('tn-what').value, password: pw });
        showToast((r && (r.message || r.error)) || 'Failed', r && r.success ? 'success' : 'error');
        load();
    }

    async function cancel() {
        const pw = await promptPassword('Stop the run', 'Confirm with the admin password.');
        if (!pw) return;
        const r = await apiCall('admin/tuner', 'POST', { op: 'cancel', password: pw });
        showToast((r && (r.message || r.error)) || 'Failed', r && r.success ? 'success' : 'error');
        load();
    }

    $('tn-start').addEventListener('click', () => start(false));
    $('tn-dry').addEventListener('click', () => start(true));
    $('tn-cancel').addEventListener('click', cancel);
    load();
})();
