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
    const { apiCall, el, showToast, confirmAction, promptPassword } = window.AdminCommon;

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
            if (my > painted) { painted = my; fatal((e && e.message) || t('js.cluster.network_error')); }
            return;
        } finally { if (busy === my) busy = 0; }
        if (my <= painted) return;
        painted = my;
        if (!j || j.enabled === false) { fatal(t('js.cluster.helper_off')); return; }
        if (!j.ok) { fatal(j.error || t('js.cluster.helper_silent')); return; }
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
        b.appendChild(el('div', { className: 'nl-note nl-note-bad', text: msg || t('js.cluster.unavailable') }));
        b.appendChild(el('div', {}, [el('button', {
            className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
            onclick: () => { painted = 0; busy = 0; load(true); },
        }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' ' + t('js.cluster.try_again')])]));
        $('cl-notes').textContent = '';
    }

    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text: text }); }

    function instanceRow(i, isPrimary) {
        const row = el('div', { className: 'sy-row' });

        const left = el('div', { className: 'sy-key' });
        left.appendChild(el('div', { className: 'sy-key-label', text: isPrimary ? t('js.cluster.primary') : String(i.name || '?') }));
        left.appendChild(el('code', { className: 'sy-key-name', text: String(i.unit || '') }));
        left.appendChild(el('div', { className: 'sy-key-what', text: isPrimary
            ? t('js.cluster.primary_what')
            : t('js.cluster.extra_what') }));
        row.appendChild(left);

        const mid = el('div', { className: 'sy-now' });
        const active = String(i.state || '') === 'active';
        mid.appendChild(el('div', {}, [badge(active ? t('js.cluster.running') : (i.state || t('js.cluster.unknown')), active ? 'wl-b-ok' : 'wl-b-bad')]));
        mid.appendChild(el('div', { className: 'sy-now-raw',
            text: t('js.cluster.ports', { udp: i.udp_port || '?', tcp: i.tcp_port || '?' }) + (i.workers ? t('js.cluster.workers', { n: i.workers }) : '') }));
        // The one thing a shared binary symlink cannot prevent: a config symlink that drifted.
        if (!isPrimary && i.running_build && i.conf_mode && i.running_build !== 'unknown'
            && i.conf_mode !== 'unknown' && i.running_build !== i.conf_mode) {
            mid.appendChild(el('div', { className: 'sy-now-base text-warning',
                text: t('js.cluster.build_drift', { build: i.running_build, mode: i.conf_mode }) }));
        } else if (!isPrimary && i.conf_mode) {
            mid.appendChild(el('div', { className: 'sy-now-base', text: t('js.cluster.mode', { mode: i.conf_mode }) }));
        }
        row.appendChild(mid);

        const act = el('div', { className: 'sy-ctl' });
        if (isPrimary) {
            act.appendChild(el('div', { className: 'wl-small text-muted', text: t('js.cluster.managed_by_installer') }));
        } else {
            const restart = el('button', { className: 'btn btn-sm btn-outline-warning me-1', type: 'button' },
                [el('i', { className: 'bi bi-bootstrap-reboot' }), ' ' + t('js.cluster.restart')]);
            restart.addEventListener('click', () => op('restart', i.name));
            const remove = el('button', { className: 'btn btn-sm btn-outline-danger', type: 'button' },
                [el('i', { className: 'bi bi-trash' }), ' ' + t('js.cluster.remove')]);
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
        const n = list.length, few = n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20);
        $('cl-updated').textContent = n
            ? t(n === 1 ? 'js.cluster.extra_one' : (few ? 'js.cluster.extra_few' : 'js.cluster.extra_many'), { n: n })
            : t('js.cluster.no_extras');

        const rows = el('div', { className: 'sy-rows' });
        if (r.primary && r.primary.unit) rows.appendChild(instanceRow(r.primary, true));
        list.forEach(i => rows.appendChild(instanceRow(i, false)));
        b.appendChild(rows);

        if (!list.length) {
            b.appendChild(el('div', { className: 'nl-note nl-note-info', text: t('js.cluster.no_extras_note') }));
        }

        // The announce URLs, which are the only way a client ever reaches an extra port.
        if (state.announce && state.announce.length) {
            const box = el('div', { className: 'nl-note nl-note-info' });
            box.appendChild(el('div', { text: t('js.cluster.announce_urls') }));
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
            notes.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.cluster.auto_limiter') }));
        }
        if (state.perfNote && list.length) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-warn', text: state.perfNote }));
        }
    }

    /* ── operations ──────────────────────────────────────────────────────── */

    async function op(kind, name) {
        const title = kind === 'remove' ? t('js.cluster.remove_title', { name: name }) : t('js.cluster.restart_title', { name: name });
        const what = kind === 'remove'
            ? t('js.cluster.remove_what')
            : t('js.cluster.restart_what');
        if (!await confirmAction(title, what, { okLabel: kind === 'remove' ? t('js.cluster.remove') : t('js.cluster.restart'), danger: true })) return;
        // promptPassword(), not window.prompt(): that one shows the password in clear on screen.
        const pw = await promptPassword(title, t('js.cluster.password_why'));
        if (!pw) return;
        try {
            const r = await apiCall('admin/ot_cluster_apply', 'POST', { op: kind, name: name, password: pw });
            showToast(r.success ? (r.message || t('js.cluster.done')) : (r.error || t('js.cluster.failed')), r.success ? 'success' : 'error');
            painted = 0; load(true);
        } catch { showToast(t('js.cluster.network_error'), 'error'); }
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
            text: p.udp ? t('js.cluster.suggested_ports', { why: p.why }) : t('js.cluster.no_port', { why: p.why || t('js.cluster.unknown') }) }));
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
                box.appendChild(el('div', { className: 'nl-note nl-note-info', text: t('js.cluster.ports_free') }));
            } else {
                box.appendChild(el('div', { className: 'nl-note nl-note-bad', text: res.problems || r.error || t('js.cluster.refused') }));
            }
            // Stated, not implied: neither check can see a daemon that happens to be stopped.
            if (res.warnings) box.appendChild(el('div', { className: 'wl-small text-muted', text: res.warnings }));
        } catch { box.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.cluster.network_error_dot') })); }
    }

    async function create(e) {
        e.preventDefault();
        const alert = $('cl-add-alert');
        const btn = $('cl-add-ok');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.cluster.creating');
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
                showToast(r.message || t('js.cluster.created'), 'success');
                painted = 0; load(true);
            } else {
                alert.textContent = '';
                alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: r.error || t('js.cluster.failed') }));
                const j = r.result || {};
                if (j.journal) alert.appendChild(el('pre', { className: 'nl-preview mt-1', text: String(j.journal).slice(0, 800) }));
                if (r.output) alert.appendChild(el('pre', { className: 'nl-preview mt-1', text: String(r.output).slice(0, 600) }));
            }
        } catch {
            alert.textContent = '';
            alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.cluster.network_error_dot') }));
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function reloadAll() {
        try {
            const r = await apiCall('admin/ot_cluster_apply', 'POST', { op: 'reload' });
            showToast(r.success ? (r.message || t('js.cluster.reloaded')) : (r.error || t('js.cluster.failed')), r.success ? 'success' : 'error');
            painted = 0; load(true);
        } catch { showToast(t('js.cluster.network_error'), 'error'); }
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
