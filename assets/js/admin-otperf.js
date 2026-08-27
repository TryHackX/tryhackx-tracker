/**
 * "OpenTracker — performance" card on the admin Traffic page — includes/opentracker.php on the server.
 *
 * The card's job is to show what is IN FORCE, which is not the same as what Settings says. A panel
 * that prints your saved values back at you is worse than none: it tells you the machine is doing
 * something it may not be doing. So every number here comes from `systemctl show` and opentracker's
 * own config, and where the two disagree the card says so.
 *
 * The one measurement that is not a knob — the socket receive buffer — is here too, because it is
 * what actually explains lost announces and nobody thinks to look at it. The panel reports it and
 * gives the command; it does not write sysctls, because those are system-wide and belong to whoever
 * owns the machine.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    const card = document.getElementById('ot-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const POLL_MS = 15000;      // this changes when somebody presses a button, not by itself
    const num = (v) => (v == null || isNaN(v)) ? '—' : Math.round(v).toLocaleString();
    const state = { pending: null, status: null, cfg: null };

    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text: text }); }
    function kv(label, children) {
        const box = el('div', { className: 'wl-kv-item' });
        box.appendChild(el('div', { className: 'wl-kv-k', text: label }));
        const v = el('div', { className: 'wl-kv-v' });
        (Array.isArray(children) ? children : [children]).forEach(c => {
            if (c == null) return;
            v.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        box.appendChild(v);
        return box;
    }

    // ── status ───────────────────────────────────────────────────────────────
    let seq = 0, painted = 0, busy = 0, busyAt = 0, watchdog = null;

    async function load(force) {
        if (busy && (Date.now() - busyAt) < 30000) return;
        if (!force && document.hidden) return;
        const my = ++seq;
        busy = my; busyAt = Date.now();
        arm();
        let j;
        try {
            j = await apiCall('admin/ot_status');
        } catch (e) {
            if (my > painted) { painted = my; fatal('Could not reach the status endpoint (' + ((e && e.message) || 'network error') + ').'); }
            return;
        } finally {
            if (busy === my) { busy = 0; clearWd(); }
        }
        if (my <= painted) return;
        painted = my;
        if (!j || (j.error && !j.status)) { fatal(j && j.error); return; }
        state.status = j.status;
        state.cfg = j.configured;
        render(j);
    }

    function arm() {
        clearWd();
        if (painted) return;
        watchdog = setTimeout(() => { watchdog = null; if (!painted) fatal('The service status has not answered for 15 seconds.'); }, 15000);
    }
    function clearWd() { if (watchdog) { clearTimeout(watchdog); watchdog = null; } }

    function fatal(msg) {
        const g = $('ot-grid');
        g.textContent = '';
        g.appendChild(kv('Service', [badge('unavailable', 'wl-b-bad'), ' ',
            el('span', { className: 'wl-small text-muted', text: msg || 'The helper did not answer.' }),
            el('div', {}, [el('button', {
                className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
                onclick: () => { painted = 0; busy = 0; load(true); },
            }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' Try again'])])]));
        $('ot-notes').textContent = '';
    }

    function render(j) {
        const s = j.status || {};
        const c = j.configured || {};
        const g = $('ot-grid');
        g.textContent = '';
        $('ot-updated').textContent = s.unit ? ('unit ' + s.unit + ' · ' + s.cpus + ' cores') : '';

        g.appendChild(kv('Service', [
            badge(s.active ? 'running' : 'stopped', s.active ? 'wl-b-ok' : 'wl-b-bad'), ' ',
            el('span', { className: 'wl-small text-muted', text: s.unit || '' })]));

        // What is loaded versus what the panel would write. Two numbers, and the gap between them
        // is the only thing on this card worth acting on.
        const wparts = [badge(num(s.workers) + ' threads', s.workers > 0 ? 'wl-b-ok' : 'wl-b-muted')];
        if (c.udp_workers && !j.workers_in_sync) {
            wparts.push(' ', el('span', { className: 'wl-small text-warning',
                text: 'settings say ' + num(c.udp_workers) + ' — needs a restart to take effect' }));
        }
        if (s.workers_consistent === false) {
            wparts.push(el('div', { className: 'wl-small text-warning',
                text: 'The white and black config files disagree, so this would change when the tracker switches mode.' }));
        }
        g.appendChild(kv('UDP workers', wparts));

        g.appendChild(kv('Scheduling', [
            el('span', { text: 'nice ' + (s.nice === undefined ? '—' : s.nice) }),
            el('span', { className: 'nl-unit', text: '  ·  weight ' + (s.cpu_weight || 'default') }),
            el('div', { className: 'wl-small text-muted', text: (s.cpu_affinity ? 'pinned to cores ' + s.cpu_affinity : 'every core') })]));

        g.appendChild(kv('Open files', [el('span', { text: num(s.limit_nofile) }),
            el('div', { className: 'wl-small text-muted', text: 'LimitNOFILE' })]));

        // The panel's own file: present or not, and whether what it says is what is running.
        const dparts = [badge(s.dropin_present ? 'written' : 'not written', s.dropin_present ? 'wl-b-ok' : 'wl-b-muted')];
        if (s.dropin_present && j.in_sync === false) {
            dparts.push(' ', el('span', { className: 'wl-small text-warning', text: 'in force differs from Settings — press Apply' }));
        }
        dparts.push(el('div', { className: 'wl-small text-muted', text: s.dropin || '' }));
        if (s.dropin_writable === false) {
            dparts.push(el('div', { className: 'wl-small text-muted',
                text: 'That directory is read-only for the panel’s PHP (systemd ProtectSystem), so Apply would fail from here.' }));
        }
        g.appendChild(kv('Panel drop-in', dparts));

        const notes = $('ot-notes');
        notes.textContent = '';
        (j.advice || []).forEach(a => {
            notes.appendChild(el('div', {
                className: 'nl-note ' + (a.level === 'warn' ? 'nl-note-warn' : 'nl-note-info'),
                text: a.text,
            }));
        });
    }

    // ── the operations ───────────────────────────────────────────────────────
    const COPY = {
        apply: {
            title: 'Apply the performance settings',
            ok: 'Apply', okClass: 'btn-outline-success',
            text: () => 'Write nice, CPU weight, affinity and the file limit into the panel’s own drop-in and reload systemd. '
                + 'Nice and CPU weight take effect immediately; affinity and the file limit only on a restart.',
            undo: () => 'Undo: the Reset button, which deletes that one file. Nothing the installer put there is touched.',
        },
        workers: {
            title: 'Change the UDP worker count',
            ok: 'Write workers', okClass: 'btn-outline-warning',
            text: () => 'Write listen.udp.workers into BOTH mode config files, so the count cannot change when the tracker '
                + 'switches white/black. opentracker reads it only at start-up, so this does nothing until a restart.',
            undo: () => 'More threads help only while packets are queueing. If the dropped count on this card is zero, this will not make the tracker faster.',
        },
        reset: {
            title: 'Remove the panel’s drop-in',
            ok: 'Remove it', okClass: 'btn-outline-secondary',
            text: () => 'Delete 90-tracker-panel.conf and reload systemd. Everything the panel ever changed about the unit goes with it; '
                + 'the installer’s own files stay exactly as they are.',
            undo: () => 'listen.udp.workers is deliberately left alone — that is opentracker’s own setting, not ours.',
        },
        restart: {
            title: 'Restart the tracker',
            ok: 'Restart', okClass: 'btn-outline-danger',
            text: () => 'Restart the service. Announces in flight are lost and peers retry, which for a UDP tracker means a '
                + 'few seconds of raised traffic — not an outage, but not free either.',
            undo: () => 'This is what makes affinity, the file limit and the worker count actually take effect.',
        },
    };

    function ask(op) {
        const copy = COPY[op];
        if (!copy) return;
        state.pending = op;
        $('ot-modal-title').textContent = copy.title;
        $('ot-modal-text').textContent = copy.text();
        const undo = $('ot-modal-undo');
        undo.textContent = '';
        undo.appendChild(el('div', { className: 'wl-small text-muted', text: copy.undo() }));
        $('ot-workers-row').classList.toggle('d-hidden', op !== 'workers');
        if (op === 'workers') {
            const s = state.status || {};
            $('ot-workers-input').value = (state.cfg && state.cfg.udp_workers) || s.workers || 4;
            $('ot-workers-input').max = String(Math.max(8, (s.cpus || 4) * 4));
        }
        const okBtn = $('ot-confirm-ok');
        okBtn.className = 'btn btn-sm ' + copy.okClass;
        okBtn.textContent = '';
        okBtn.appendChild(el('i', { className: 'bi bi-check-lg' }));
        okBtn.appendChild(document.createTextNode(' ' + copy.ok));
        $('ot-confirm-alert').textContent = '';
        $('ot-confirm-password').value = '';
        bootstrap.Modal.getOrCreateInstance($('otConfirmModal')).show();
        setTimeout(() => $('ot-confirm-password').focus(), 300);
    }

    async function run(e) {
        e.preventDefault();
        const op = state.pending;
        if (!op) return;
        const alert = $('ot-confirm-alert');
        const btn = $('ot-confirm-ok');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working…';
        alert.textContent = '';
        const body = { op: op, password: $('ot-confirm-password').value };
        if (op === 'workers') body.workers = parseInt($('ot-workers-input').value, 10) || 4;
        try {
            const r = await apiCall('admin/ot_apply', 'POST', body);
            if (r.success) {
                bootstrap.Modal.getInstance($('otConfirmModal')).hide();
                showToast(r.message || 'Done.', 'success');
                painted = 0;
                load(true);
            } else {
                alert.textContent = '';
                alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: r.error || 'Failed.' }));
                if (r.output) alert.appendChild(el('pre', { className: 'nl-preview mt-1', text: String(r.output).slice(0, 600) }));
            }
        } catch (err) {
            alert.textContent = '';
            alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: 'Network error.' }));
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function preview() {
        try {
            const r = await apiCall('admin/ot_apply', 'POST', { op: 'preview' });
            $('ot-preview-title').textContent = r.file || 'Drop-in preview';
            $('ot-preview-body').textContent = r.content || r.error || '(nothing)';
            bootstrap.Modal.getOrCreateInstance($('otPreviewModal')).show();
        } catch (e) { showToast('Network error', 'error'); }
    }

    function init() {
        $('btn-ot-apply').addEventListener('click', () => ask('apply'));
        $('btn-ot-workers').addEventListener('click', () => ask('workers'));
        $('btn-ot-reset').addEventListener('click', () => ask('reset'));
        $('btn-ot-restart').addEventListener('click', () => ask('restart'));
        $('btn-ot-preview').addEventListener('click', preview);
        $('ot-confirm-form').addEventListener('submit', run);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });
        load(true);
        setInterval(load, POLL_MS);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
