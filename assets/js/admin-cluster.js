/**
 * "OpenTracker instances" card on the admin Traffic page — includes/cluster.php on the server.
 *
 * The card shows the WHOLE roster, including the installer's own unit, which it never manages. Two
 * reasons: a roster that lists only what the panel created is not a roster, and seeing the primary
 * beside the extras is what makes the asymmetry obvious rather than surprising — the panel can stop
 * and remove an instance it made, and it deliberately cannot do either to the one the installer made.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    const card = document.getElementById('cluster-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const POLL_MS = 20000;
    const state = { roster: null, propose: null, announce: [], warnings: [] };

    let seq = 0, painted = 0, busy = 0, busyAt = 0;

    async function load(force) {
        if (busy && (Date.now() - busyAt) < 30000) return;
        if (!force && document.hidden) return;
        const my = ++seq;
        busy = my; busyAt = Date.now();
        let j;
        try {
            j = await apiCall('admin/ot_cluster_status');
        } catch (e) {
            if (my > painted) { painted = my; fatal((e && e.message) || 'network error'); }
            return;
        } finally { if (busy === my) busy = 0; }
        if (my <= painted) return;
        painted = my;
        if (!j || j.enabled === false) { fatal('The helper is not configured or the feature is off.'); return; }
        if (!j.ok) { fatal(j.error || 'The helper did not answer.'); return; }
        state.roster = j.roster || {};
        state.propose = j.propose || {};
        state.announce = j.announce || [];
        state.warnings = j.warnings || [];
        state.autoLimiter = !!j.auto_limiter_on;
        state.perfNote = j.perf_scope_note || '';
        render();
    }

    function fatal(msg) {
        const b = $('cl-body');
        b.textContent = '';
        b.appendChild(el('div', { className: 'nl-note nl-note-bad', text: msg || 'unavailable' }));
        b.appendChild(el('div', {}, [el('button', {
            className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
            onclick: () => { painted = 0; busy = 0; load(true); },
        }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' Try again'])]));
        $('cl-notes').textContent = '';
    }

    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text: text }); }

    function instanceRow(i, isPrimary) {
        const row = el('div', { className: 'sy-row' });

        const left = el('div', { className: 'sy-key' });
        left.appendChild(el('div', { className: 'sy-key-label', text: isPrimary ? 'primary' : String(i.name || '?') }));
        left.appendChild(el('code', { className: 'sy-key-name', text: String(i.unit || '') }));
        left.appendChild(el('div', { className: 'sy-key-what', text: isPrimary
            ? 'The unit the installer made. The panel never touches it: everything else on this card is added beside it, and removing every extra leaves this exactly as it was.'
            : 'Shares this tracker’s accesslist, its white/black mode and its binary. Only the ports differ.' }));
        row.appendChild(left);

        const mid = el('div', { className: 'sy-now' });
        const active = String(i.state || '') === 'active';
        mid.appendChild(el('div', {}, [badge(active ? 'running' : (i.state || 'unknown'), active ? 'wl-b-ok' : 'wl-b-bad')]));
        mid.appendChild(el('div', { className: 'sy-now-raw',
            text: 'UDP ' + (i.udp_port || '?') + ' · TCP ' + (i.tcp_port || '?') + (i.workers ? ' · ' + i.workers + ' workers' : '') }));
        // The one thing a shared binary symlink cannot prevent: a config symlink that drifted.
        if (!isPrimary && i.running_build && i.conf_mode && i.running_build !== 'unknown'
            && i.conf_mode !== 'unknown' && i.running_build !== i.conf_mode) {
            mid.appendChild(el('div', { className: 'sy-now-base text-warning',
                text: 'running the ' + i.running_build + ' build while its config says ' + i.conf_mode + ' — needs a restart' }));
        } else if (!isPrimary && i.conf_mode) {
            mid.appendChild(el('div', { className: 'sy-now-base', text: 'mode ' + i.conf_mode }));
        }
        row.appendChild(mid);

        const act = el('div', { className: 'sy-ctl' });
        if (isPrimary) {
            act.appendChild(el('div', { className: 'wl-small text-muted', text: 'managed by the installer, not from here' }));
        } else {
            const restart = el('button', { className: 'btn btn-sm btn-outline-warning me-1', type: 'button' },
                [el('i', { className: 'bi bi-bootstrap-reboot' }), ' Restart']);
            restart.addEventListener('click', () => op('restart', i.name));
            const remove = el('button', { className: 'btn btn-sm btn-outline-danger', type: 'button' },
                [el('i', { className: 'bi bi-trash' }), ' Remove']);
            remove.addEventListener('click', () => op('remove', i.name));
            act.appendChild(restart);
            act.appendChild(remove);
        }
        row.appendChild(act);
        return row;
    }

    function render() {
        const b = $('cl-body');
        b.textContent = '';
        const r = state.roster || {};
        const list = (r.instances || []);
        $('cl-updated').textContent = list.length
            ? (list.length + ' extra ' + (list.length === 1 ? 'instance' : 'instances'))
            : 'no extra instances';

        const rows = el('div', { className: 'sy-rows' });
        if (r.primary && r.primary.unit) rows.appendChild(instanceRow(r.primary, true));
        list.forEach(i => rows.appendChild(instanceRow(i, false)));
        b.appendChild(rows);

        if (!list.length) {
            b.appendChild(el('div', { className: 'nl-note nl-note-info', text:
                'No extra instances. Before adding one, read the verdict on the performance card above: '
                + 'a second tracker helps only when the first one\'s busiest UDP worker is at the ceiling '
                + 'with one thread per core. Anything else and it adds the one thing that is not short.' }));
        }

        // The announce URLs, which are the only way a client ever reaches an extra port.
        if (state.announce && state.announce.length) {
            const box = el('div', { className: 'nl-note nl-note-info' });
            box.appendChild(el('div', { text: 'Announce URLs to publish (the extras are listed only while they are running):' }));
            box.appendChild(el('pre', { className: 'nl-preview mt-1', text: state.announce.join('\n') }));
            b.appendChild(box);
        }

        const notes = $('cl-notes');
        notes.textContent = '';
        (state.warnings || []).forEach(w => {
            notes.appendChild(el('div', {
                className: 'nl-note ' + (w.level === 'danger' ? 'nl-note-bad' : 'nl-note-warn'), text: w.text,
            }));
        });
        if (state.autoLimiter) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-bad', text:
                'The automatic inbound limiter is on. Its counters only see the primary\'s port, so an extra '
                + 'instance would hide most of the traffic from it while leaving the load — and it would answer '
                + 'by throttling the primary. Creating an instance is refused until it is off.' }));
        }
        if (state.perfNote && list.length) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-warn', text: state.perfNote }));
        }
    }

    /* ── operations ──────────────────────────────────────────────────────── */

    async function op(kind, name) {
        const what = kind === 'remove'
            ? 'Remove instance "' + name + '"?\n\nIts unit is stopped and disabled and its files are deleted. '
              + 'The swarm on that port goes with it; clients retry against whatever else you publish.'
            : 'Restart instance "' + name + '"?\n\nAnnounces in flight on its port are lost and peers retry.';
        if (!window.confirm(what)) return;
        const pw = window.prompt('Admin password to confirm:');
        if (!pw) return;
        try {
            const r = await apiCall('admin/ot_cluster_apply', 'POST', { op: kind, name: name, password: pw });
            showToast(r.success ? (r.message || 'Done.') : (r.error || 'Failed.'), r.success ? 'success' : 'error');
            painted = 0; load(true);
        } catch { showToast('Network error', 'error'); }
    }

    function openAdd() {
        const p = state.propose || {};
        $('cl-name').value = '';
        $('cl-udp').value = p.udp || '';
        $('cl-tcp').value = p.tcp || '';
        $('cl-affinity').value = '';
        $('cl-workers').value = '0';
        $('cl-password').value = '';
        $('cl-add-alert').textContent = '';
        const plan = $('cl-plan');
        plan.textContent = '';
        plan.appendChild(el('div', { className: 'wl-small text-muted',
            text: p.udp ? ('Suggested ports: ' + p.why) : ('No port could be proposed: ' + (p.why || 'unknown')) }));
        bootstrap.Modal.getOrCreateInstance($('clAddModal')).show();
        setTimeout(() => $('cl-name').focus(), 300);
    }

    async function planPorts() {
        const box = $('cl-plan');
        box.textContent = '';
        try {
            const r = await apiCall('admin/ot_cluster_apply', 'POST', {
                op: 'plan', name: $('cl-name').value.trim(),
                udp: parseInt($('cl-udp').value, 10) || 0, tcp: parseInt($('cl-tcp').value, 10) || 0,
            });
            const res = r.result || {};
            if (r.success) {
                box.appendChild(el('div', { className: 'nl-note nl-note-info', text: 'Both ports look free.' }));
            } else {
                box.appendChild(el('div', { className: 'nl-note nl-note-bad', text: res.problems || r.error || 'Refused.' }));
            }
            // Stated, not implied: neither check can see a daemon that happens to be stopped.
            if (res.warnings) box.appendChild(el('div', { className: 'wl-small text-muted', text: res.warnings }));
        } catch { box.appendChild(el('div', { className: 'nl-note nl-note-bad', text: 'Network error.' })); }
    }

    async function create(e) {
        e.preventDefault();
        const alert = $('cl-add-alert');
        const btn = $('cl-add-ok');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating…';
        alert.textContent = '';
        try {
            const r = await apiCall('admin/ot_cluster_apply', 'POST', {
                op: 'create',
                name: $('cl-name').value.trim(),
                udp: parseInt($('cl-udp').value, 10) || 0,
                tcp: parseInt($('cl-tcp').value, 10) || 0,
                affinity: $('cl-affinity').value.trim(),
                workers: parseInt($('cl-workers').value, 10) || 0,
                password: $('cl-password').value,
            });
            if (r.success) {
                bootstrap.Modal.getInstance($('clAddModal')).hide();
                showToast(r.message || 'Created.', 'success');
                painted = 0; load(true);
            } else {
                alert.textContent = '';
                alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: r.error || 'Failed.' }));
                const j = r.result || {};
                if (j.journal) alert.appendChild(el('pre', { className: 'nl-preview mt-1', text: String(j.journal).slice(0, 800) }));
                if (r.output) alert.appendChild(el('pre', { className: 'nl-preview mt-1', text: String(r.output).slice(0, 600) }));
            }
        } catch {
            alert.textContent = '';
            alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: 'Network error.' }));
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function reloadAll() {
        try {
            const r = await apiCall('admin/ot_cluster_apply', 'POST', { op: 'reload' });
            showToast(r.success ? (r.message || 'Reloaded.') : (r.error || 'Failed.'), r.success ? 'success' : 'error');
            painted = 0; load(true);
        } catch { showToast('Network error', 'error'); }
    }

    function init() {
        $('btn-cl-add').addEventListener('click', openAdd);
        $('btn-cl-reload').addEventListener('click', reloadAll);
        $('btn-cl-plan').addEventListener('click', planPorts);
        $('cl-add-form').addEventListener('submit', create);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });
        load(true);
        setInterval(load, POLL_MS);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
