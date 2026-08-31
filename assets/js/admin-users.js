/* Admin — Users & Groups page (?action=admin-users). Uses window.AdminCommon helpers.
   All dynamic DOM is built with el()/textContent — usernames, emails, notes and group names are
   user-controlled. */
(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, showToast, confirmAction, makeSortStack, renderPagination, fmtDate, bindSearchClear, busyDot } = A;
    const $ = (id) => document.getElementById(id);

    const state = {
        view: 'users',
        us: { page: 1, search: '', status: '', group: '', rows: [] },
        // Ticked users survive paging and searching: an audience assembled across three pages is
        // still one audience, and losing it on a filter change would be maddening.
        picked: new Set(),
        groups: [],           // fetch_groups rows (shared by both views + the grant modal)
        permList: {},         // perm key => description
    };
    let usSort;
    let editUser = null, grantUser = null, notifyUser = null, editGroup = null;

    // ── status card ─────────────────────────────────────────────────────────
    function kv(label, value) {
        return el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: label }), el('div', { className: 'wl-kv-value' }, value)]);
    }
    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text }); }
    function renderStatus(counts, enabled) {
        const grid = $('us-status-grid');
        grid.textContent = '';
        $('us-disabled-note').style.display = enabled ? 'none' : '';
        grid.appendChild(kv('Accounts', [badge(enabled ? 'ENABLED' : 'DISABLED', enabled ? 'wl-b-ok' : 'wl-b-warn')]));
        grid.appendChild(kv('Users', [
            el('span', { text: `${counts.total} total` }), ' · ',
            el('span', { text: `${counts.active} active` }), ' · ',
            el('span', { className: counts.banned ? 'text-warning' : '', text: `${counts.banned} banned` }),
        ]));
        grid.appendChild(kv('Groups', [el('span', { text: String(state.groups.length) }), ' ',
            el('span', { className: 'text-muted wl-small', text: 'guest = anonymous only · signed-in users get the union of their own groups' })]));
    }

    // ── groups data (shared) ────────────────────────────────────────────────
    async function loadGroups() {
        const r = await apiCall('admin/fetch_groups');
        if (r.error) { showToast('Groups: ' + r.error, 'danger'); return; }
        state.groups = r.groups || [];
        state.permList = r.permission_list || {};
        // group filter select
        const sel = $('us-filter-group');
        const cur = sel.value;
        sel.textContent = '';
        sel.appendChild(el('option', { value: '', text: 'All groups' }));
        state.groups.forEach(g => sel.appendChild(el('option', { value: String(g.id), text: g.name })));
        sel.value = cur;
        if (state.view === 'groups') renderGroups();
    }

    // ── users view ──────────────────────────────────────────────────────────
    let usLoadSeq = 0;
    async function loadUsers() {
        const qs = new URLSearchParams({ page: state.us.page, sort: usSort.serialize() });
        if (state.us.search) qs.set('search', state.us.search);
        if (state.us.status) qs.set('status', state.us.status);
        if (state.us.group) qs.set('group_id', state.us.group);
        const my = ++usLoadSeq;
        busyDot($('us-total'), true);
        $('us-table').classList.add('tbl-loading');
        const r = await apiCall('admin/fetch_users&' + qs.toString());
        if (my !== usLoadSeq) return;
        busyDot($('us-total'), false);
        $('us-table').classList.remove('tbl-loading');
        if (r.error) { showToast('Users: ' + r.error, 'danger'); return; }
        state.us.rows = r.rows || [];
        renderStatus(r.counts || { total: 0, active: 0, banned: 0 }, !!r.enabled);
        $('us-total').textContent = (r.total || 0).toLocaleString() + ' users';
        const tb = $('us-body');
        tb.textContent = '';
        if (!state.us.rows.length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 9, className: 'text-center text-muted py-4', text: 'No users match.' })));
        }
        state.us.rows.forEach(u => {
            const tr = el('tr', {});
            const pick = el('input', { type: 'checkbox', className: 'us-pick' });
            pick.checked = state.picked.has(u.id);
            pick.addEventListener('change', () => {
                if (pick.checked) state.picked.add(u.id); else state.picked.delete(u.id);
                syncPickAll();
                if (state.view === 'write') refreshPreview();
            });
            tr.appendChild(el('td', { className: 'us-c-pick' },
                el('label', { className: 'search-check' }, [pick, el('span', { className: 'search-check-box' })])));
            tr.appendChild(el('td', { className: 'wl-id', text: String(u.id) }));
            tr.appendChild(el('td', {}, [el('strong', { text: u.username }), u.root_admin ? el('i', { className: 'bi bi-shield-lock-fill text-warning ms-1', title: 'Site owner (panel admin) — protected account' }) : null]));
            tr.appendChild(el('td', { className: 'wl-small', title: u.email ? (u.email_verified ? 'verified address' : 'not verified') : '' },
                [u.email || '—', u.email && u.email_verified ? el('i', { className: 'bi bi-patch-check-fill text-success ms-1', title: 'verified' }) : null]));
            tr.appendChild(el('td', {}, badge(u.status, u.status === 'active' ? 'wl-b-ok' : 'wl-b-bad')));
            const gTd = el('td', {});
            (u.groups || []).forEach(g => {
                const b = el('span', {
                    className: 'us-group-badge' + (g.active ? '' : ' us-inactive'),
                    title: (g.active ? '' : (new Date(String(g.granted_at).replace(' ', 'T')) > new Date() ? 'starts ' + g.granted_at : 'expired') + ' · ')
                        + (g.expires_at ? 'until ' + g.expires_at : 'permanent'),
                    text: g.name + (g.expires_at ? ' ⏱' : ''),
                });
                if (g.color && /^#[0-9a-fA-F]{3,8}$/.test(g.color)) b.style.borderColor = g.color;
                gTd.appendChild(b);
            });
            if (!(u.groups || []).length) gTd.appendChild(el('span', { className: 'text-muted', text: '—' }));
            tr.appendChild(gTd);
            tr.appendChild(el('td', { className: 'wl-small text-muted', text: fmtDate(u.created_at), title: 'IP: ' + (u.created_ip || '—') }));
            tr.appendChild(el('td', { className: 'wl-small text-muted', text: u.last_login_at ? fmtDate(u.last_login_at) : 'never', title: 'IP: ' + (u.last_login_ip || '—') }));
            const act = el('td', { className: 'th-actions' });
            const mkBtn = (icon, title, cls, fn) => {
                const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls + ' wl-act', title }, el('i', { className: 'bi ' + icon }));
                b.addEventListener('click', fn);
                return b;
            };
            act.appendChild(mkBtn('bi-award', 'Grant group', 'btn-outline-success', () => openGrant(u)));
            act.appendChild(mkBtn('bi-pencil', 'Edit', 'btn-outline-info', () => openEdit(u)));
            act.appendChild(mkBtn('bi-bell', 'Send notification', 'btn-outline-info', () => openNotify(u)));
            const delBtn = mkBtn('bi-trash', u.root_admin ? 'Site owner — cannot be deleted' : 'Delete', 'btn-outline-danger', () => deleteUser(u));
            if (u.root_admin) delBtn.disabled = true;
            act.appendChild(delBtn);
            tr.appendChild(act);
            // second row of group management: revoke buttons live in the grant modal instead — keep the table lean
            tb.appendChild(tr);
        });
        renderPagination($('us-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p) => { state.us.page = p; loadUsers(); } });
    }

    async function deleteUser(u) {
        if (!(await confirmAction('Delete user', 'Permanently delete "' + u.username + '" with all group memberships and notifications?', { danger: true, okLabel: 'Delete' }))) return;
        const r = await apiCall('admin/user_delete', 'POST', { id: u.id });
        if (r.success) { showToast('Deleted ' + u.username); loadUsers(); }
        else showToast(r.error || 'Delete failed', 'danger');
    }

    // ── user edit modal ─────────────────────────────────────────────────────
    const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const passOk = (p) => p.length >= 8 && p.length <= 200 && /[a-z]/.test(p) && /[A-Z]/.test(p) && /[0-9]/.test(p) && /[^a-zA-Z0-9]/.test(p);
    function ueValidate() {
        const email = $('ue-email'), email2 = $('ue-email2'), p1 = $('ue-password'), p2 = $('ue-password2');
        const emailChanged = email.value.trim() !== (editUser && editUser.email ? editUser.email : '');
        const emailOk = email.value.trim() === '' || EMAIL_RE.test(email.value.trim());
        const email2Ok = !emailChanged || email.value.trim() === '' || email2.value.trim() === email.value.trim();
        const p1Ok = p1.value === '' || passOk(p1.value);
        const matchOk = p1.value === '' || p2.value === p1.value;
        email.classList.toggle('is-invalid', !emailOk);
        email2.classList.toggle('is-invalid', !email2Ok);
        p1.classList.toggle('is-invalid', !p1Ok);
        p2.classList.toggle('is-invalid', !matchOk);
        return emailOk && email2Ok && p1Ok && matchOk;
    }
    function ueSyncRepeats() {
        $('ue-password2-wrap').classList.toggle('d-hidden', $('ue-password').value === '');
        const changed = $('ue-email').value.trim() !== (editUser && editUser.email ? editUser.email : '') && $('ue-email').value.trim() !== '';
        $('ue-email2-wrap').classList.toggle('d-hidden', !changed);
    }
    function openEdit(u) {
        editUser = u;
        $('ue-name').textContent = u.username;
        $('ue-status').value = u.status;
        // the site owner cannot be banned — grey the option out
        const bannedOpt = $('ue-status').querySelector('option[value="banned"]');
        if (bannedOpt) { bannedOpt.disabled = !!u.root_admin; bannedOpt.title = u.root_admin ? 'Site owner — cannot be banned' : ''; }
        $('ue-email').value = u.email || '';
        $('ue-email2').value = '';
        $('ue-password').value = '';
        $('ue-password2').value = '';
        ['ue-email', 'ue-email2', 'ue-password', 'ue-password2'].forEach(id => $(id).classList.remove('is-invalid'));
        ueSyncRepeats();
        $('ue-alert').textContent = '';
        bootstrap.Modal.getOrCreateInstance($('usEditModal')).show();
    }
    async function saveEdit() {
        if (!ueValidate()) return;
        const body = { id: editUser.id, status: $('ue-status').value, email: $('ue-email').value.trim() };
        if ($('ue-password').value !== '') body.password = $('ue-password').value;
        const r = await apiCall('admin/user_update', 'POST', body);
        if (r.success) {
            showToast('Saved');
            bootstrap.Modal.getOrCreateInstance($('usEditModal')).hide();
            loadUsers();
        } else {
            $('ue-alert').textContent = '';
            $('ue-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || 'Save failed' }));
        }
    }

    // ── grant modal ─────────────────────────────────────────────────────────
    function openGrant(u) {
        grantUser = u;
        $('ug-name').textContent = u.username;
        const sel = $('ug-group');
        sel.textContent = '';
        state.groups.filter(g => g.slug !== 'guest').forEach(g => sel.appendChild(el('option', { value: String(g.id), text: g.name + ' (' + g.slug + ')' })));
        $('ug-duration').value = 'permanent';
        $('ug-custom').classList.add('d-hidden');
        $('ug-from').value = ''; $('ug-to').value = ''; $('ug-note').value = '';
        $('ug-email').checked = false;
        $('ug-alert').textContent = '';
        // current memberships with revoke buttons
        let list = $('ug-current');
        if (!list) {
            list = el('div', { id: 'ug-current', className: 'mt-2' });
            $('ug-alert').before(list);
        }
        list.textContent = '';
        if ((u.groups || []).length) {
            list.appendChild(el('div', { className: 'wl-label form-label', text: 'Current memberships' }));
            u.groups.forEach(g => {
                const row = el('div', { className: 'd-flex align-items-center gap-2 wl-small mb-1' }, [
                    el('span', { className: 'us-group-badge' + (g.active ? '' : ' us-inactive'), text: g.name }),
                    el('span', { className: 'text-muted', text: g.expires_at ? 'until ' + g.expires_at : 'permanent' }),
                ]);
                const rm = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger wl-act', title: 'Revoke' }, el('i', { className: 'bi bi-x-lg' }));
                const gid = (state.groups.find(x => x.slug === g.slug) || {}).id;
                if (u.root_admin && g.slug === 'admin') { rm.disabled = true; rm.title = 'Site owner — the admin group cannot be revoked'; }
                rm.addEventListener('click', async () => {
                    if (!(await confirmAction('Revoke group', 'Remove "' + g.name + '" from ' + u.username + '?', { danger: true, okLabel: 'Revoke' }))) return;
                    const r = await apiCall('admin/user_revoke', 'POST', { id: u.id, group_id: gid });
                    if (r.success) { showToast('Revoked'); bootstrap.Modal.getOrCreateInstance($('usGrantModal')).hide(); loadUsers(); }
                    else showToast(r.error || 'Revoke failed', 'danger');
                });
                row.appendChild(rm);
                list.appendChild(row);
            });
        }
        bootstrap.Modal.getOrCreateInstance($('usGrantModal')).show();
    }
    async function saveGrant() {
        const duration = $('ug-duration').value;
        const body = { id: grantUser.id, group: $('ug-group').value, duration, note: $('ug-note').value.trim(), email: $('ug-email').checked ? 1 : 0 };
        if (duration === 'custom') { body.from = $('ug-from').value.trim(); body.to = $('ug-to').value.trim(); }
        const r = await apiCall('admin/user_grant', 'POST', body);
        if (r.success) {
            showToast('Granted ' + r.group + (r.expires_at ? ' until ' + r.expires_at : ' permanently'));
            bootstrap.Modal.getOrCreateInstance($('usGrantModal')).hide();
            loadUsers();
        } else {
            $('ug-alert').textContent = '';
            $('ug-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || 'Grant failed' }));
        }
    }

    // ── notify modal ────────────────────────────────────────────────────────
    function openNotify(u) {
        notifyUser = u;
        $('un-name').textContent = u.username;
        $('un-title').value = ''; $('un-body').value = '';
        $('un-email').checked = false;
        $('un-alert').textContent = '';
        bootstrap.Modal.getOrCreateInstance($('usNotifyModal')).show();
    }
    async function sendNotify() {
        const r = await apiCall('admin/user_notify', 'POST', { id: notifyUser.id, title: $('un-title').value.trim(), body: $('un-body').value.trim(), email: $('un-email').checked ? 1 : 0 });
        if (r.success) {
            showToast('Sent' + (r.mailed ? ' (+email)' : ''));
            bootstrap.Modal.getOrCreateInstance($('usNotifyModal')).hide();
        } else {
            $('un-alert').textContent = '';
            $('un-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || 'Send failed' }));
        }
    }

    // ── groups view ─────────────────────────────────────────────────────────
    function renderGroups() {
        const tb = $('gr-body');
        tb.textContent = '';
        state.groups.forEach(g => {
            const tr = el('tr', {});
            const nameEl = el('strong', { text: g.name });
            if (g.color && /^#[0-9a-fA-F]{3,8}$/.test(g.color)) nameEl.style.color = g.color;
            tr.appendChild(el('td', {}, [nameEl, g.is_system ? el('span', { className: 'text-muted wl-small', text: ' (system)' }) : null]));
            tr.appendChild(el('td', { className: 'font-mono wl-small', text: g.slug }));
            tr.appendChild(el('td', { text: String(g.priority) }));
            tr.appendChild(el('td', {}, g.is_default ? badge('default', 'wl-b-ok') : el('span', { className: 'text-muted', text: '—' })));
            tr.appendChild(el('td', { text: String(g.members) }));
            const permKeys = Object.keys(g.permissions || {});
            tr.appendChild(el('td', { className: 'wl-small text-muted', text: permKeys.length ? permKeys.join(', ') : '(none)' }));
            const act = el('td', { className: 'th-actions' });
            const edit = el('button', { type: 'button', className: 'btn btn-sm btn-outline-info wl-act', title: 'Edit' }, el('i', { className: 'bi bi-pencil' }));
            edit.addEventListener('click', () => openGroupEditor(g));
            act.appendChild(edit);
            if (!g.is_system) {
                const del = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger wl-act', title: 'Delete' }, el('i', { className: 'bi bi-trash' }));
                del.addEventListener('click', async () => {
                    if (!(await confirmAction('Delete group', 'Delete "' + g.name + '"? ' + g.members + ' membership(s) are removed with it.', { danger: true, okLabel: 'Delete' }))) return;
                    const r = await apiCall('admin/group_delete', 'POST', { id: g.id });
                    if (r.success) { showToast('Deleted'); loadGroups(); }
                    else showToast(r.error || 'Delete failed', 'danger');
                });
                act.appendChild(del);
            }
            tr.appendChild(act);
            tb.appendChild(tr);
        });
    }
    function openGroupEditor(g) {
        editGroup = g;   // null = new
        $('ge-title').textContent = g ? 'Edit group — ' + g.name : 'New group';
        $('ge-name').value = g ? g.name : '';
        $('ge-slug').value = g ? g.slug : '';
        $('ge-slug').disabled = !!(g && g.is_system);
        $('ge-color').value = g ? g.color : '';
        $('ge-priority').value = g ? String(g.priority) : '0';
        $('ge-desc').value = g ? g.description : '';
        $('ge-default').checked = !!(g && g.is_default);
        $('ge-alert').textContent = '';
        const box = $('ge-perms');
        box.textContent = '';
        Object.entries(state.permList).forEach(([key, desc]) => {
            const id = 'gp-' + key.replace(/\./g, '-');
            const wrap = el('div', { className: 'form-check' }, [
                el('input', { className: 'form-check-input', type: 'checkbox', id, dataset: { perm: key } }),
                el('label', { className: 'form-check-label', for: id }, [el('code', { text: key }), ' — ' + desc]),
            ]);
            wrap.querySelector('input').checked = !!(g && g.permissions && g.permissions[key]);
            box.appendChild(wrap);
        });
        bootstrap.Modal.getOrCreateInstance($('grEditModal')).show();
    }
    async function saveGroup() {
        const permissions = {};
        $('ge-perms').querySelectorAll('input[data-perm]').forEach(cb => { if (cb.checked) permissions[cb.dataset.perm] = true; });
        const body = {
            slug: $('ge-slug').value.trim().toLowerCase(), name: $('ge-name').value.trim(),
            description: $('ge-desc').value.trim(), color: $('ge-color').value.trim(),
            priority: parseInt($('ge-priority').value, 10) || 0,
            is_default: $('ge-default').checked ? 1 : 0, permissions,
        };
        if (editGroup) body.id = editGroup.id;
        const r = await apiCall('admin/group_save', 'POST', body);
        if (r.success) {
            showToast('Group saved');
            bootstrap.Modal.getOrCreateInstance($('grEditModal')).hide();
            loadGroups();
        } else {
            $('ge-alert').textContent = '';
            $('ge-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || 'Save failed' }));
        }
    }


    // -- writing to members --------------------------------------------------
    //
    // Two different things behind one form. A notification is a row in a table and costs nothing. An
    // email leaves the machine, so it is queued and drained by the janitor a few a minute: this
    // server sends through mail() with nothing in front of it, and a burst from a domain that
    // normally sends a handful a day is what gets the password-reset mail filed as spam.
    //
    // Nothing here sends without showing the real number first. "Everyone" that quietly means 41 of
    // 53 is the kind of surprise that surfaces a week later as "why did I never hear about it".

    function syncPickAll() {
        const all = $('us-pick-all');
        if (!all) return;
        const rows = state.us.rows || [];
        all.checked = rows.length > 0 && rows.every(u => state.picked.has(u.id));
        all.indeterminate = !all.checked && rows.some(u => state.picked.has(u.id));
    }

    function writeAudience() {
        const mode = $('bm-mode').value;
        if (mode === 'group') return { mode: 'group', group_id: parseInt($('bm-group').value, 10) || 0 };
        if (mode === 'all') return { mode: 'all' };
        return { mode: 'selected', ids: [...state.picked] };
    }

    function fillWriteGroups() {
        const sel = $('bm-group');
        if (!sel || sel.dataset.filled === '1') return;
        sel.textContent = '';
        (state.groups || []).forEach(g => sel.appendChild(el('option', { value: String(g.id), text: g.name })));
        sel.dataset.filled = '1';
    }

    async function refreshPreview() {
        const box = $('bm-preview');
        if (!box) return;
        $('bm-group-wrap').style.display = $('bm-mode').value === 'group' ? '' : 'none';
        box.textContent = '';
        box.appendChild(el('span', { className: 'text-muted', text: 'Counting...' }));
        const r = await apiCall('admin/bulk_send', 'POST', { op: 'preview', audience: writeAudience() });
        if (!r || !r.success) {
            box.textContent = '';
            box.appendChild(el('span', { className: 'text-danger', text: (r && r.error) || 'Could not count.' }));
            return;
        }
        $('bm-off-note').style.display = r.enabled ? 'none' : '';
        bmFillFormats(r.formats);
        box.textContent = '';
        box.appendChild(el('strong', { text: String(r.recipients) }));
        const why = [];
        if (r.no_email) why.push(r.no_email + ' with no address');
        if (r.opted_out) why.push(r.opted_out + ' opted out of announcements');
        if (r.unsubscribed) why.push(r.unsubscribed + ' unsubscribed');
        box.appendChild(document.createTextNode(' of ' + r.audience + ' would receive the email'
            + (why.length ? ' - ' + why.join(', ') : '') + '. '));
        box.appendChild(el('span', { className: 'text-muted wl-small',
            text: 'In-app notifications reach all ' + r.audience + ': there is nothing to opt out of, they only exist inside the site.' }));
    }

    async function loadBatches() {
        const tb = $('bm-batches');
        if (!tb) return;
        const r = await apiCall('admin/bulk_send', 'POST', { op: 'batches' });
        tb.textContent = '';
        if (!r || !r.success) return;
        $('bm-depth').textContent = r.depth ? (r.depth + ' still waiting - ' + r.per_minute + '/min') : '';
        if (!(r.batches || []).length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 7, className: 'text-center text-muted py-4', text: 'Nothing sent yet.' })));
            return;
        }
        r.batches.forEach(b => {
            const tr = el('tr', {});
            tr.appendChild(el('td', { className: 'wl-small', text: fmtDate(String(b.started).replace(' ', 'T')) }));
            tr.appendChild(el('td', { text: b.subject || '-' }));
            tr.appendChild(el('td', { text: String(b.total) }));
            tr.appendChild(el('td', { text: String(b.sent) }));
            tr.appendChild(el('td', { className: Number(b.failed) ? 'text-warning' : '', text: String(b.failed) }));
            tr.appendChild(el('td', { text: String(b.pending) }));
            const act = el('td', { className: 'td-actions' });
            if (Number(b.pending) > 0) {
                const stop = el('button', { type: 'button', className: 'btn btn-sm btn-outline-warning wl-act', title: 'Stop what has not gone out yet' },
                    el('i', { className: 'bi bi-stop-circle' }));
                stop.addEventListener('click', () => cancelBatch(b.batch_id, Number(b.pending)));
                act.appendChild(stop);
            }
            tr.appendChild(act);
            tb.appendChild(tr);
        });
    }

    const askPassword = (title, message) => A.promptPassword(title, message);

    async function cancelBatch(id, pending) {
        const pw = await askPassword('Stop this send',
            'Stop the ' + pending + ' message(s) that have not gone out yet? Anything already delivered cannot be recalled.');
        if (!pw) return;
        const r = await apiCall('admin/bulk_send', 'POST', { op: 'cancel', batch_id: id, password: pw });
        showToast((r && (r.message || r.error)) || 'Done', r && r.success ? 'success' : 'error');
        loadBatches();
    }

    // ── the message editor ──────────────────────────────────────────────────
    //
    // Ctrl+B in a <textarea> does nothing on its own; the browser only wires those shortcuts to
    // contenteditable regions. So the shortcuts and the buttons run the same function, which wraps
    // the selection in whichever syntax the chosen format uses. A contenteditable box would have
    // given Ctrl+B for free and cost a second renderer, a paste sanitiser and an HTML whitelist to
    // police — the markup the site already renders is the cheaper honest answer.
    // Kept in step with SYNTAX in assets/js/app.js — the same renderer serves both, so a button here
    // that writes something the public editor cannot would be a lie about what the site supports.
    // A third element means "a whole block": inserted on its own lines instead of wrapped.
    const MD_SYNTAX = {
        markdown: {
            bold: ['**', '**'], italic: ['*', '*'], strike: ['~~', '~~'], code: ['`', '`'],
            highlight: ['==', '=='],
            link: ['[', '](https://example.org)'], image: ['![](', ')'],
            quote: ['> ', ''], list: ['- ', ''], olist: ['1. ', ''],
            table: ['| A | B |\n|---|---|\n| 1 | 2 |', '', true],
            hr: ['\n---\n', '', true],
        },
        bbcode: {
            bold: ['[b]', '[/b]'], italic: ['[i]', '[/i]'], underline: ['[u]', '[/u]'],
            strike: ['[s]', '[/s]'], code: ['[code]', '[/code]'],
            color: ['[color=#e74c3c]', '[/color]'], size: ['[size=18]', '[/size]'],
            highlight: ['[highlight=yellow]', '[/highlight]'],
            link: ['[url=https://example.org]', '[/url]'], image: ['[img]', '[/img]'],
            quote: ['[quote]', '[/quote]'],
            list: ['[list]\n[*] ', '\n[/list]'], olist: ['[list=1]\n[*] ', '\n[/list]'],
            center: ['[center]', '[/center]'],
            table: ['[table]\n[tr][th]A[/th][th]B[/th][/tr]\n[tr][td]1[/td][td]2[/td][/tr]\n[/table]', '', true],
            hr: ['\n[hr]\n', '', true],
        },
    };

    function bmFormat() { const f = $('bm-format'); return f ? f.value : 'plain'; }

    /** Wrap the selection (or drop a stub at the caret) and keep the caret somewhere useful. */
    function bmWrap(kind) {
        const ta = $('bm-body');
        const syn = MD_SYNTAX[bmFormat()];
        if (!ta || !syn || !syn[kind]) return;
        const [open, close, block] = syn[kind];
        const start = ta.selectionStart, end = ta.selectionEnd;
        const sel = ta.value.slice(start, end);
        if (block) {
            // A whole construct. Wrapping a selection in a table skeleton would put the author's
            // sentence in the header, which is never what they meant.
            const pre = start > 0 && ta.value[start - 1] !== '\n' ? '\n' : '';
            ta.setRangeText(pre + open + '\n', start, end, 'end');
            ta.focus();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }
        // Line-prefix marks (quote, list in Markdown) belong at the start of every selected line,
        // not wrapped around the block — otherwise a three-line quote quotes only its first line.
        const linewise = close === '' ;
        let inserted;
        if (linewise) {
            const lines = (sel || 'text').split('\n');
            inserted = lines.map(l => open + l).join('\n');
        } else {
            inserted = open + (sel || 'text') + close;
        }
        ta.setRangeText(inserted, start, end, 'end');
        if (!sel) {
            // No selection: select the placeholder so the next keystroke replaces it.
            const at = start + (linewise ? open.length : open.length);
            ta.setSelectionRange(at, at + 4);
        }
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    let bmPrevTimer = null;
    async function bmRenderPreview() {
        const wrap = $('bm-preview-wrap'), out = $('bm-preview-html');
        if (!wrap || !out) return;
        const fmt = bmFormat();
        const body = $('bm-body').value;
        if (fmt === 'plain' || body.trim() === '') { wrap.classList.add('d-hidden'); return; }
        const r = await apiCall('admin/bulk_send', 'POST', { op: 'render', body, format: fmt });
        if (!r || !r.success) { wrap.classList.add('d-hidden'); return; }
        wrap.classList.remove('d-hidden');
        // Server-rendered from fully escaped input by includes/richtext.php, the same call the
        // janitor makes when it builds the mail.
        out.innerHTML = r.html;
    }
    function bmPreviewSoon() { clearTimeout(bmPrevTimer); bmPrevTimer = setTimeout(bmRenderPreview, 350); }

    function bmSyncFormatUi() {
        const fmt = bmFormat();
        const tools = $('bm-tools');
        if (tools) {
            tools.classList.toggle('d-hidden', fmt === 'plain');
            const syn = MD_SYNTAX[fmt] || {};
            tools.querySelectorAll('[data-md]').forEach(b => { b.hidden = !syn[b.dataset.md]; });
            tools.querySelectorAll('.bm-tool-group').forEach(g => {
                g.hidden = ![...g.querySelectorAll('[data-md]')].some(b => !b.hidden);
            });
        }
        const hint = $('bm-fmt-hint');
        if (hint) {
            hint.textContent = fmt === 'plain'
                ? 'Plain text: line breaks are kept and nothing else is interpreted.'
                : (fmt === 'markdown'
                    ? 'Markdown: **bold**, *italic*, ~~strike~~, ==mark==, # heading, - list, 1. list, > quote, `code`, tables, [text](url), ![](image). Ctrl+B / Ctrl+I / Ctrl+K.'
                    : 'BBCode: [b] [i] [u] [s] [color=#hex] [size=18] [center] [hr] [quote] [list] [list=1] [table] [highlight] [url] [img]. Ctrl+B / Ctrl+I / Ctrl+K.');
        }
        bmRenderPreview();
    }

    /** Offer only the formats the site has switched on, plus plain text. */
    function bmFillFormats(formats) {
        const sel = $('bm-format');
        if (!sel || !Array.isArray(formats) || sel.dataset.filled === '1') return;
        const label = { plain: 'Plain text', markdown: 'Markdown', bbcode: 'BBCode' };
        sel.textContent = '';
        formats.forEach(f => sel.appendChild(el('option', { value: f, text: label[f] || f })));
        sel.dataset.filled = '1';
        bmSyncFormatUi();
    }

    async function sendTest() {
        const r = await apiCall('admin/bulk_send', 'POST',
            { op: 'test', subject: $('bm-subject').value, body: $('bm-body').value, format: bmFormat() });
        showToast((r && (r.message || r.error)) || 'Failed', r && r.success ? 'success' : 'error');
    }

    async function sendWrite() {
        const subject = $('bm-subject').value.trim();
        const body = $('bm-body').value.trim();
        if (!subject || !body) { showToast('A subject and a message are both required.', 'error'); return; }
        const wantMail = $('bm-email').checked;
        const wantNotify = $('bm-notify').checked;
        if (!wantMail && !wantNotify) { showToast('Choose a notification, an email, or both.', 'error'); return; }
        const pre = await apiCall('admin/bulk_send', 'POST', { op: 'preview', audience: writeAudience() });
        if (!pre || !pre.success) { showToast((pre && pre.error) || 'Could not count the audience.', 'error'); return; }
        const what = [];
        if (wantNotify) what.push(pre.audience + ' notification(s)');
        if (wantMail) what.push(pre.recipients + ' email(s)');
        // The number again, at the moment of committing. This is the last point at which somebody can
        // notice that "everyone" is larger than they had pictured.
        if (!await confirmAction('Send to members',
            'This sends ' + what.join(' and ') + '. Emails cannot be recalled once they leave.',
            { okLabel: 'Send', danger: true })) return;
        const pw = await askPassword('Send to members', 'Confirm with the admin password.');
        if (!pw) return;
        const r = await apiCall('admin/bulk_send', 'POST', {
            op: 'queue', password: pw, audience: writeAudience(),
            subject, body, format: bmFormat(), notify: wantNotify, email: wantMail });
        showToast((r && (r.message || r.error)) || 'Failed', r && r.success ? 'success' : 'error');
        if (r && r.success) { $('bm-subject').value = ''; $('bm-body').value = ''; bmRenderPreview(); loadBatches(); }
    }

    // ── wiring ──────────────────────────────────────────────────────────────
    function switchView(view) {
        state.view = view;
        document.querySelectorAll('#us-tabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.view === view));
        $('view-users').classList.toggle('d-hidden', view !== 'users');
        $('view-groups').classList.toggle('d-hidden', view !== 'groups');
        $('view-write').classList.toggle('d-hidden', view !== 'write');
        if (view === 'groups') renderGroups();
        if (view === 'write') { fillWriteGroups(); refreshPreview(); loadBatches(); }
    }
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
    // Clicking a column header redraws the arrows immediately; only the fetch waits. The wait has to
    // cover a whole DECISION, not a single click — the direction cycles desc → asc → off, so picking
    // a column and then its direction is two or three clicks, and at 450 ms the first one had already
    // fired a request (and, with several sort keys, a wrong one).
    const SORT_DEBOUNCE_MS = 900;

    document.addEventListener('DOMContentLoaded', () => {
        const loadUsersDebounced = debounce(() => loadUsers(), SORT_DEBOUNCE_MS);
        usSort = makeSortStack({ table: $('us-table'), defaultSort: [{ col: 'created', dir: 'desc' }], onChange: () => { state.us.page = 1; loadUsersDebounced(); } });
        usSort.bindHeaders();
        document.querySelectorAll('#us-tabs .source-tab').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));
        $('us-search').addEventListener('input', debounce(() => { state.us.search = $('us-search').value.trim(); state.us.page = 1; loadUsers(); }, 300));
        bindSearchClear($('us-search'), $('us-search-clear'), () => { state.us.search = ''; state.us.page = 1; loadUsers(); });
        $('us-filter-status').addEventListener('change', () => { state.us.status = $('us-filter-status').value; state.us.page = 1; loadUsers(); });
        $('us-filter-group').addEventListener('change', () => { state.us.group = $('us-filter-group').value; state.us.page = 1; loadUsers(); });
        $('ue-save').addEventListener('click', saveEdit);
        $('ue-email').addEventListener('input', () => { ueSyncRepeats(); ueValidate(); });
        $('ue-email2').addEventListener('input', ueValidate);
        $('ue-password').addEventListener('input', () => { ueSyncRepeats(); ueValidate(); });
        $('ue-password2').addEventListener('input', ueValidate);
        $('ug-save').addEventListener('click', saveGrant);
        $('ug-duration').addEventListener('change', () => $('ug-custom').classList.toggle('d-hidden', $('ug-duration').value !== 'custom'));
        $('un-send').addEventListener('click', sendNotify);
        $('btn-group-new').addEventListener('click', () => openGroupEditor(null));
        const pickAll = $('us-pick-all');
        if (pickAll) pickAll.addEventListener('change', () => {
            (state.us.rows || []).forEach(u => { if (pickAll.checked) state.picked.add(u.id); else state.picked.delete(u.id); });
            document.querySelectorAll('#us-body .us-pick').forEach(b => { b.checked = pickAll.checked; });
            if (state.view === 'write') refreshPreview();
        });
        $('bm-mode').addEventListener('change', refreshPreview);
        $('bm-group').addEventListener('change', refreshPreview);
        $('bm-refresh').addEventListener('click', refreshPreview);
        $('bm-format').addEventListener('change', bmSyncFormatUi);
        $('bm-tools').addEventListener('click', (e) => {
            const b = e.target.closest('[data-md]');
            if (b) bmWrap(b.dataset.md);
        });
        $('bm-body').addEventListener('input', bmPreviewSoon);
        $('bm-body').addEventListener('keydown', (e) => {
            if (!(e.ctrlKey || e.metaKey) || e.altKey || bmFormat() === 'plain') return;
            const kind = { b: 'bold', i: 'italic', k: 'link' }[e.key.toLowerCase()];
            if (!kind) return;
            e.preventDefault();
            bmWrap(kind);
        });
        $('bm-test').addEventListener('click', sendTest);
        $('bm-send').addEventListener('click', sendWrite);
        $('ge-save').addEventListener('click', saveGroup);
        const logout = $('btn-logout');
        if (logout) logout.addEventListener('click', async () => { try { await apiCall('admin/logout', 'POST', {}); } catch (e) {} location.href = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '') + '?action=' + (document.body.dataset.loginPath || 'admin'); });
        loadGroups().then(loadUsers);
    });
})();
