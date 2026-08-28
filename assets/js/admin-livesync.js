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
        grid.appendChild(kv('State', [
            badge(armed ? 'ON' : 'off', armed ? 'wl-b-ok' : 'wl-b-muted'),
            ' ',
            el('span', { className: 'wl-small text-muted',
                text: armed ? 'opentracker is started with the sync flags' : 'opentracker runs with its own command line' }),
        ]));
        grid.appendChild(kv('Tunnel', [
            el('span', { text: (st.bind_ip || '—') + ' → ' + (st.peer || '—') }),
            ' ',
            st.iface ? badge(st.iface, st.iface_is_tunnel ? 'wl-b-ok' : 'wl-b-bad') : badge('no interface', 'wl-b-warn'),
        ]));
        grid.appendChild(kv('Sync port', [
            el('span', { text: st.port ? ('UDP ' + st.port) : '—' }), ' ',
            st.listening ? badge('listening on ' + st.listening, 'wl-b-ok')
                         : badge(armed ? 'NOT listening' : 'not armed', armed ? 'wl-b-bad' : 'wl-b-muted'),
        ]));
        grid.appendChild(kv('WireGuard', st.wg_ifaces && st.wg_ifaces.length
            ? [el('span', { text: st.wg_ifaces.join(', ') })]
            : [badge('none found', 'wl-b-warn'), ' ',
               el('span', { className: 'wl-small text-muted', text: 'the sync port may only live inside a tunnel' })]));
        if (st.at) grid.appendChild(kv('Checked', [el('span', { className: 'wl-small text-muted', text: fmtDate(new Date(st.at * 1000).toISOString()) })]));
        body.appendChild(grid);

        const notes = $('ls-notes');
        notes.textContent = '';
        (warnings || []).forEach(w => notes.appendChild(el('div', { className: 'nl-note nl-note-bad', text: w })));
        if (!armed && !(st.wg_ifaces || []).length) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-info', text:
                'There is no tunnel on this machine yet, so there is nothing safe to bind to. Press Test in '
                + 'Settings — it prints the WireGuard commands. The panel does not run them for you: '
                + 'generating a private key and writing it into /etc is not something it should do '
                + 'half-blind, without being able to see the other end.' }));
        }
        $('btn-ls-arm').disabled = armed;
        $('btn-ls-off').disabled = !armed;
    }

    async function load() {
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'status' });
        if (!r || !r.success) {
            $('ls-body').textContent = '';
            $('ls-body').appendChild(el('div', { className: 'nl-note nl-note-bad', text: (r && r.error) || 'Could not read the status.' }));
            return;
        }
        render(r.status || {}, r.warnings || []);
    }

    async function plan() {
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'plan' });
        if (!r || !r.success) { showToast((r && r.error) || 'Refused', 'error'); return; }
        // The exact command line, before anything is written. This is the one place an operator can
        // see what overriding ExecStart actually means on their machine.
        await confirmAction('This is what would run', 'opentracker would be started with:',
            { code: r.execstart || '', after: 'Nothing has been changed. Use "Turn on" to apply it.',
              okLabel: 'Understood', danger: false });
    }

    async function arm() {
        if (!await confirmAction('Turn on live peer sync',
            'This restarts opentracker with a sync port bound to the tunnel address. The protocol has '
            + 'no authentication: anything that can reach that port can inject peers into every swarm '
            + 'this tracker serves. The helper refuses if the address is not on a tunnel.',
            { okLabel: 'Turn it on', danger: true })) return;
        const pw = await promptPassword('Turn on live peer sync', 'Confirm with the admin password.');
        if (!pw) return;
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'apply', password: pw });
        showToast((r && (r.message || r.error)) || 'Failed', r && r.success ? 'success' : 'error');
        load();
    }

    async function disarm() {
        if (!await confirmAction('Turn off live peer sync',
            'opentracker restarts with its own command line and the sync port closes.',
            { okLabel: 'Turn it off', danger: false })) return;
        const pw = await promptPassword('Turn off live peer sync', 'Confirm with the admin password.');
        if (!pw) return;
        const r = await apiCall('admin/livesync_apply', 'POST', { op: 'revert', password: pw });
        showToast((r && (r.message || r.error)) || 'Failed', r && r.success ? 'success' : 'error');
        load();
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('btn-ls-plan').addEventListener('click', plan);
        $('btn-ls-arm').addEventListener('click', arm);
        $('btn-ls-off').addEventListener('click', disarm);
        load();
    });
})();
