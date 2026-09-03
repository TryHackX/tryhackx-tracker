/**
 * "Address lists" card on the admin Traffic page — includes/iplist.php on the server.
 *
 * Whole networks and whole countries, from a pasted file or a URL that is re-downloaded on a timer.
 * Three behaviours, and the card's job is to make which one a row has impossible to misread:
 *
 *   Allow           never dropped, whatever else says
 *   Block           dropped always
 *   Under pressure  dropped only when the machine is busy
 *
 * Nothing here reaches the firewall except "Push to firewall". Every other action writes to the
 * database and to the file the root helper reads, so a list can be built, its parsed entry count
 * checked, and only then enforced — which is why the push is the one action that asks for the
 * password.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    const card = document.getElementById('iplists-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction, promptPassword } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const MAX_UPLOAD = 8 * 1024 * 1024;

    let state = null;
    let busy = false;

    const fmt = (n) => Number(n || 0).toLocaleString();

    function ago(sqlDate) {
        if (!sqlDate) return 'never';
        const t = Date.parse(String(sqlDate).replace(' ', 'T') + 'Z');
        if (!isFinite(t)) return String(sqlDate);
        const s = Math.max(0, (Date.now() - t) / 1000);
        if (s < 90) return Math.round(s) + ' s ago';
        if (s < 5400) return Math.round(s / 60) + ' min ago';
        if (s < 172800) return Math.round(s / 3600) + ' h ago';
        return Math.round(s / 86400) + ' d ago';
    }

    // A row's label has to say which of the three behaviours it has: "block" on its own is exactly
    // the ambiguity this feature exists to remove.
    function badge(l) {
        if (l.kind === 'allow') return ['Allow', 'text-success', 'never dropped, whatever else says'];
        if (l.mode === 'soft') return ['Under pressure', 'text-warning', 'dropped only when the machine is busy'];
        return ['Block', 'text-danger', 'dropped always'];
    }

    function iconBtn(icon, title, cls, fn) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls + ' ms-1 py-0 px-1', title: title },
                     [el('i', { className: 'bi ' + icon })]);
        b.addEventListener('click', fn);
        return b;
    }

    function render() {
        if (!state) return;
        const box = $('ipl-list');
        box.textContent = '';

        const c = state.counts || {};
        const total = (c.allow || 0) + (c.hard || 0) + (c.soft || 0);
        const head = $('ipl-updated');
        if (head) {
            head.textContent = state.enabled
                ? fmt(total) + ' entries enforced · ' + fmt(state.max) + ' max'
                : 'switched off in Settings — nothing here is enforced';
            head.className = 'wl-status-updated' + (state.enabled ? '' : ' text-warning');
        }

        if (!state.lists.length) {
            box.appendChild(el('p', { className: 'wl-small text-muted mb-0',
                text: 'No lists yet. "Add a list" takes a URL such as a country zone file from ipdeny.com, '
                    + 'or a file you upload.' }));
            return;
        }

        const thead = el('thead', null, [el('tr', null, [
            el('th', { style: 'width:1%' }),
            el('th', { text: 'List' }),
            el('th', { text: 'What it does' }),
            el('th', { className: 'text-end', text: 'Entries' }),
            el('th', { text: 'Source' }),
            el('th', { className: 'text-end', text: 'Actions' }),
        ])]);
        const tb = el('tbody');

        state.lists.forEach((l) => {
            const tr = el('tr', { className: l.enabled ? null : 'ipl-off' });

            const cb = el('input', { type: 'checkbox', className: 'form-check-input',
                                     title: l.enabled ? 'Enabled' : 'Disabled' });
            cb.checked = !!l.enabled;
            cb.addEventListener('change', () => act('toggle', { id: l.id, enabled: cb.checked }));
            tr.appendChild(el('td', null, [el('div', { className: 'form-check form-switch mb-0' }, [cb])]));

            const name = el('td', null, [el('span', { className: 'text-light', text: l.name })]);
            if (l.last_error) {
                name.appendChild(el('br'));
                name.appendChild(el('span', {
                    className: 'wl-small text-danger',
                    title: 'The entries shown are the last copy that worked — a failed refresh changes nothing',
                    text: '⚠ ' + l.last_error,
                }));
            }
            tr.appendChild(name);

            const b = badge(l);
            tr.appendChild(el('td', null, [
                el('span', { className: b[1], text: b[0] }),
                el('br'),
                el('span', { className: 'wl-small text-muted', text: b[2] }),
            ]));

            tr.appendChild(el('td', { className: 'text-end' },
                [el('span', { className: 'text-light', text: fmt(l.entries) })]));

            const src = el('td');
            if (l.source === 'url') {
                src.appendChild(el('a', {
                    className: 'wl-small', href: l.url, target: '_blank', rel: 'noopener noreferrer',
                    title: l.url, text: l.url.length > 46 ? l.url.slice(0, 44) + '…' : l.url,
                }));
                src.appendChild(el('br'));
                src.appendChild(el('span', { className: 'wl-small text-muted',
                    text: 'fetched ' + ago(l.last_fetch_at) + ' · every ' + fmt(l.ttl_minutes) + ' min' }));
            } else {
                src.appendChild(el('span', { className: 'wl-small text-muted', text: 'uploaded ' + ago(l.last_fetch_at) }));
            }
            tr.appendChild(src);

            const acts = el('td', { className: 'text-end text-nowrap' });
            if (l.source === 'url') {
                acts.appendChild(iconBtn('bi-arrow-clockwise', 'Download it again now', 'btn-outline-info',
                    () => act('refresh', { id: l.id })));
            } else {
                acts.appendChild(iconBtn('bi-upload', 'Replace the entries from a file', 'btn-outline-info',
                    () => replaceEntries(l)));
            }
            acts.appendChild(iconBtn('bi-trash', 'Delete this list', 'btn-outline-danger', async () => {
                const ok = await confirmAction('Delete this list?',
                    'This removes "' + l.name + '" and its ' + fmt(l.entries) + ' entries. '
                    + 'The firewall keeps enforcing it until the next push.', { danger: true, okLabel: 'Delete' });
                if (ok) act('delete', { id: l.id });
            }));
            tr.appendChild(acts);
            tb.appendChild(tr);
        });

        box.appendChild(el('table', { className: 'table table-sm table-dark align-middle mb-0 ipl-table' }, [thead, tb]));

        // Not an error, but the one thing about precedence somebody will otherwise work out the hard
        // way: an address on both sides gets through, because the trusted set is matched first.
        if (state.conflicts && state.conflicts.length) {
            box.appendChild(el('div', { className: 'alert alert-info py-2 wl-small mb-0 mt-2',
                text: state.conflicts.join(', ') + (state.conflicts.length === 1 ? ' is' : ' are')
                    + ' on a block list AND in your trusted addresses. The trusted entry wins — '
                    + (state.conflicts.length === 1 ? 'it' : 'they') + ' will not be dropped.' }));
        }
        box.appendChild(el('p', { className: 'wl-small text-muted mb-0 mt-2',
            text: 'Enforced now: ' + fmt(c.allow) + ' allowed, ' + fmt(c.hard) + ' blocked, '
                + fmt(c.soft) + ' dropped only under pressure'
                + (state.manual && state.manual.length
                    ? ' — plus ' + state.manual.length + ' trusted addresses, which win over all of it.'
                    : '.') }));
    }

    async function load() {
        const r = await apiCall('admin/ip_lists');
        if (r.error) {
            $('ipl-list').textContent = '';
            $('ipl-list').appendChild(el('p', { className: 'wl-small text-danger mb-0',
                text: 'Could not read the lists: ' + r.error }));
            return;
        }
        state = r;
        render();
    }

    async function act(op, body) {
        if (busy) return;
        busy = true;
        try {
            const r = await apiCall('admin/ip_list_action', 'POST', Object.assign({ op: op }, body));
            if (r.error) showToast(r.error, 'danger');
            else showToast(r.message || 'Done.', r.note ? 'warning' : 'success');
            await load();
        } finally {
            busy = false;
        }
    }

    function readFile(file, onText) {
        if (file.size > MAX_UPLOAD) { showToast('That file is larger than 8 MiB.', 'danger'); return; }
        const rd = new FileReader();
        rd.onload = () => onText(String(rd.result || ''));
        rd.onerror = () => showToast('Could not read that file.', 'danger');
        rd.readAsText(file);
    }

    function replaceEntries(l) {
        const inp = el('input', { type: 'file', accept: '.txt,.zone,.list,.cidr,text/plain', style: 'display:none' });
        inp.addEventListener('change', () => {
            const f = inp.files && inp.files[0];
            if (f) readFile(f, (t) => act('entries', { id: l.id, text: t }));
            inp.remove();
        });
        document.body.appendChild(inp);
        inp.click();
    }

    // ── the add dialog ──────────────────────────────────────────────────────
    function syncAddForm() {
        const url = $('ipl-source').value === 'url';
        $('ipl-url-wrap').classList.toggle('d-none', !url);
        $('ipl-text-wrap').classList.toggle('d-none', url);
        // An allow list has no "when": hard and soft are two ways of refusing, and this one accepts.
        $('ipl-mode-wrap').classList.toggle('invisible', $('ipl-kind').value === 'allow');
    }

    function openAdd() {
        ['ipl-name', 'ipl-url', 'ipl-text'].forEach((id) => { $(id).value = ''; });
        $('ipl-file').value = '';
        $('ipl-kind').value = 'block';
        $('ipl-mode').value = 'hard';
        $('ipl-source').value = 'url';
        $('ipl-ttl').value = card.dataset.ttl || '720';
        $('ipl-error').classList.add('d-none');
        syncAddForm();
        bootstrap.Modal.getOrCreateInstance($('iplAddModal')).show();
    }

    async function saveAdd() {
        const err = $('ipl-error');
        err.classList.add('d-none');
        const body = {
            op: 'create',
            name: $('ipl-name').value.trim(),
            kind: $('ipl-kind').value,
            mode: $('ipl-mode').value,
            source: $('ipl-source').value,
            url: $('ipl-url').value.trim(),
            ttl_minutes: parseInt($('ipl-ttl').value, 10) || 720,
            text: $('ipl-text').value,
        };
        const bad = !body.name ? 'Give the list a name.'
            : (body.source === 'url' && !/^https?:\/\//i.test(body.url))
                ? 'A URL list needs an http:// or https:// address.'
                : (body.source === 'manual' && body.text.trim() === '')
                    ? 'Paste some addresses or choose a file.' : '';
        if (bad) { err.textContent = bad; err.classList.remove('d-none'); return; }

        const save = $('ipl-save');
        save.disabled = true;
        try {
            const r = await apiCall('admin/ip_list_action', 'POST', body);
            if (r.error) { err.textContent = r.error; err.classList.remove('d-none'); return; }
            bootstrap.Modal.getOrCreateInstance($('iplAddModal')).hide();
            showToast(r.message || 'Added.', r.note ? 'warning' : 'success');
            await load();
        } finally {
            save.disabled = false;
        }
    }

    // ── pushing to the firewall ─────────────────────────────────────────────
    async function push() {
        const pw = await promptPassword('Load the lists',
            'This is the moment packets start being dropped. The enabled lists below go into the '
            + 'firewall; everything you have done so far only touched the panel.');
        if (!pw) return;
        const b = $('btn-ipl-push');
        b.disabled = true;
        try {
            const r = await apiCall('admin/ip_list_action', 'POST', { op: 'push', password: pw, enabled: true });
            if (r.error) { showToast(r.error, 'danger'); return; }
            showToast(r.message || 'Loaded.', 'success');
            card.dataset.enabled = '1';
            await load();
        } finally {
            b.disabled = false;
        }
    }

    $('btn-ipl-add').addEventListener('click', openAdd);
    $('btn-ipl-push').addEventListener('click', push);
    $('ipl-save').addEventListener('click', saveAdd);
    $('ipl-source').addEventListener('change', syncAddForm);
    $('ipl-kind').addEventListener('change', syncAddForm);
    $('ipl-file').addEventListener('change', () => {
        const f = $('ipl-file').files && $('ipl-file').files[0];
        if (f) readFile(f, (t) => { $('ipl-text').value = t; });
    });

    load();
})();
