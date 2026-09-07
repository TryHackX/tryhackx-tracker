/* Admin — live peer sync card on the Traffic page (?action=admin-traffic).
   Rendered only when the feature is switched on in Settings, so this file loads on nobody else's
   page. Everything is built with el()/textContent: the peer address and the helper's own error
   strings are the untrusted parts here. */
(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, showToast, confirmAction, promptPassword, fmtDate } = A;
    const $ = (id) => document.getElementById(id);
    if (!$('livesync-card')) return;

    function kv(label, value) {
        return el('div', { className: 'wl-kv-item' }, [
            el('div', { className: 'wl-kv-label', text: label }),
            el('div', { className: 'wl-kv-value' }, value),
        ]);
    }
    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text }); }

    function render(st, warnings) {
        const body = $('ls-body');
        body.textContent = '';
        const grid = el('div', { className: 'wl-status-grid' });

        const armed = !!st.armed;
        grid.appendChild(kv(t('js.livesync.state'), [
            badge(armed ? t('js.livesync.on') : t('js.livesync.off'), armed ? 'wl-b-ok' : 'wl-b-muted'),
            ' ',
            el('span', { className: 'wl-small text-muted',
                text: armed ? t('js.livesync.state_armed') : t('js.livesync.state_own_cmdline') }),
        ]));
        grid.appendChild(kv(t('js.livesync.tunnel'), [
            el('span', { text: (st.bind_ip || '—') + ' → ' + (st.peer || '—') }),
            ' ',
            st.iface ? badge(st.iface, st.iface_is_tunnel ? 'wl-b-ok' : 'wl-b-bad') : badge(t('js.livesync.no_interface'), 'wl-b-warn'),
        ]));
        grid.appendChild(kv(t('js.livesync.sync_port'), [
            el('span', { text: st.port ? ('UDP ' + st.port) : '—' }), ' ',
            st.listening ? badge(t('js.livesync.listening_on', {addr: st.listening}), 'wl-b-ok')
                         : badge(armed ? t('js.livesync.not_listening') : t('js.livesync.not_armed'), armed ? 'wl-b-bad' : 'wl-b-muted'),
        ]));
        grid.appendChild(kv('WireGuard', st.wg_ifaces && st.wg_ifaces.length
            ? [el('span', { text: st.wg_ifaces.join(', ') })]
            : [badge(t('js.livesync.none_found'), 'wl-b-warn'), ' ',
               el('span', { className: 'wl-small text-muted', text: t('js.livesync.port_only_in_tunnel') })]));
        if (st.at) grid.appendChild(kv(t('js.livesync.checked'), [el('span', { className: 'wl-small text-muted', text: fmtDate(new Date(st.at * 1000).toISOString()) })]));
        body.appendChild(grid);

        const notes = $('ls-notes');
        notes.textContent = '';
        (warnings || []).forEach(w => notes.appendChild(el('div', { className: 'nl-note nl-note-bad', text: w })));
        if (!armed && !(st.wg_ifaces || []).length) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-info', text: t('js.livesync.no_tunnel_note') }));
        }
        $('btn-ls-arm').disabled = armed;
        $('btn-ls-off').disabled = !armed;
    }

    async function load() {
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'status' });
        if (!r || !r.success) {
            $('ls-body').textContent = '';
            $('ls-body').appendChild(el('div', { className: 'nl-note nl-note-bad', text: (r && r.error) || t('js.livesync.status_read_failed') }));
            return;
        }
        render(r.status || {}, r.warnings || []);
    }

    async function plan() {
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'plan' });
        if (!r || !r.success) { showToast((r && r.error) || t('js.livesync.refused'), 'error'); return; }
        // The exact command line, before anything is written. This is the one place an operator can
        // see what overriding ExecStart actually means on their machine.
        await confirmAction(t('js.livesync.plan_title'), t('js.livesync.plan_body'),
            { code: r.execstart || '', after: t('js.livesync.plan_after'),
              okLabel: t('js.livesync.understood'), danger: false });
    }

    async function arm() {
        if (!await confirmAction(t('js.livesync.arm_title'), t('js.livesync.arm_body'),
            { okLabel: t('js.livesync.arm_ok'), danger: true })) return;
        const pw = await promptPassword(t('js.livesync.arm_title'), t('js.livesync.confirm_password'));
        if (!pw) return;
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'apply', password: pw });
        showToast((r && (r.message || r.error)) || t('js.livesync.failed'), r && r.success ? 'success' : 'error');
        load();
    }

    async function disarm() {
        if (!await confirmAction(t('js.livesync.disarm_title'), t('js.livesync.disarm_body'),
            { okLabel: t('js.livesync.disarm_ok'), danger: false })) return;
        const pw = await promptPassword(t('js.livesync.disarm_title'), t('js.livesync.confirm_password'));
        if (!pw) return;
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'revert', password: pw });
        showToast((r && (r.message || r.error)) || t('js.livesync.failed'), r && r.success ? 'success' : 'error');
        load();
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('btn-ls-plan').addEventListener('click', plan);
        $('btn-ls-arm').addEventListener('click', arm);
        $('btn-ls-off').addEventListener('click', disarm);
        load();
    });
})();
