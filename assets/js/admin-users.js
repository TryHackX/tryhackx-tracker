/* Admin — Users & Groups page (?action=admin-users). Uses window.AdminCommon helpers.
   All dynamic DOM is built with el()/textContent — usernames, emails, notes and group names are
   user-controlled. */
(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, showToast, confirmAction, makeSortStack, renderPagination, fmtDate } = A;
    const $ = (id) => document.getElementById(id);

    const state = {
        view: 'users',
        us: { page: 1, search: '', status: '', group: '', rows: [] },
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
    async function loadUsers() {
        const qs = new URLSearchParams({ page: state.us.page, sort: usSort.serialize() });
        if (state.us.search) qs.set('search', state.us.search);
        if (state.us.status) qs.set('status', state.us.status);
        if (state.us.group) qs.set('group_id', state.us.group);
        const r = await apiCall('admin/fetch_users&' + qs.toString());
        if (r.error) { showToast('Users: ' + r.error, 'danger'); return; }
        state.us.rows = r.rows || [];
        renderStatus(r.counts || { total: 0, active: 0, banned: 0 }, !!r.enabled);
        $('us-total').textContent = (r.total || 0).toLocaleString() + ' users';
        const tb = $('us-body');
        tb.textContent = '';
        if (!state.us.rows.length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 8, className: 'text-center text-muted py-4', text: 'No users match.' })));
        }
        state.us.rows.forEach(u => {
            const tr = el('tr', {});
            tr.appendChild(el('td', { className: 'wl-id', text: String(u.id) }));
            tr.appendChild(el('td', {}, el('strong', { text: u.username })));
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
            act.appendChild(mkBtn('bi-trash', 'Delete', 'btn-outline-danger', () => deleteUser(u)));
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
    function ueValidate() {
        const email = $('ue-email'), p1 = $('ue-password'), p2 = $('ue-password2');
        const emailOk = email.value.trim() === '' || EMAIL_RE.test(email.value.trim());
        const passOk = p1.value === '' || (p1.value.length >= 8 && p1.value.length <= 200);
        const matchOk = p1.value === '' || p2.value === p1.value;
        email.classList.toggle('is-invalid', !emailOk);
        p1.classList.toggle('is-invalid', !passOk);
        p2.classList.toggle('is-invalid', !matchOk);
        return emailOk && passOk && matchOk;
    }
    function ueSyncPass2() {
        $('ue-password2-wrap').classList.toggle('d-hidden', $('ue-password').value === '');
    }
    function openEdit(u) {
        editUser = u;
        $('ue-name').textContent = u.username;
        $('ue-status').value = u.status;
        $('ue-email').value = u.email || '';
        $('ue-password').value = '';
        $('ue-password2').value = '';
        ['ue-email', 'ue-password', 'ue-password2'].forEach(id => $(id).classList.remove('is-invalid'));
        ueSyncPass2();
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

    // ── wiring ──────────────────────────────────────────────────────────────
    function switchView(view) {
        state.view = view;
        document.querySelectorAll('#us-tabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.view === view));
        $('view-users').classList.toggle('d-hidden', view !== 'users');
        $('view-groups').classList.toggle('d-hidden', view !== 'groups');
        if (view === 'groups') renderGroups();
    }
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

    document.addEventListener('DOMContentLoaded', () => {
        usSort = makeSortStack({ table: $('us-table'), defaultSort: [{ col: 'created', dir: 'desc' }], onChange: () => { state.us.page = 1; loadUsers(); } });
        usSort.bindHeaders();
        document.querySelectorAll('#us-tabs .source-tab').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));
        $('us-search').addEventListener('input', debounce(() => { state.us.search = $('us-search').value.trim(); state.us.page = 1; loadUsers(); }, 300));
        $('us-search-clear').addEventListener('click', () => { $('us-search').value = ''; state.us.search = ''; state.us.page = 1; loadUsers(); });
        $('us-filter-status').addEventListener('change', () => { state.us.status = $('us-filter-status').value; state.us.page = 1; loadUsers(); });
        $('us-filter-group').addEventListener('change', () => { state.us.group = $('us-filter-group').value; state.us.page = 1; loadUsers(); });
        $('ue-save').addEventListener('click', saveEdit);
        $('ue-email').addEventListener('input', ueValidate);
        $('ue-password').addEventListener('input', () => { ueSyncPass2(); ueValidate(); });
        $('ue-password2').addEventListener('input', ueValidate);
        $('ug-save').addEventListener('click', saveGrant);
        $('ug-duration').addEventListener('change', () => $('ug-custom').classList.toggle('d-hidden', $('ug-duration').value !== 'custom'));
        $('un-send').addEventListener('click', sendNotify);
        $('btn-group-new').addEventListener('click', () => openGroupEditor(null));
        $('ge-save').addEventListener('click', saveGroup);
        const logout = $('btn-logout');
        if (logout) logout.addEventListener('click', async () => { try { await apiCall('admin/logout', 'POST', {}); } catch (e) {} location.href = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '') + '?action=admin'; });
        loadGroups().then(loadUsers);
    });
})();
