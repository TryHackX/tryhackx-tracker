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
    // What a permission allows, said by its id (1.73.0): perm.<id> from the page's bundle (templates/admin/users.php
    // carries `perm.`) — a t.key() word, so it follows the live language switch; for an id the bundle does not know,
    // the server's words (admin/fetch_groups answers them in the reader's language).
    const permWords = (id) => (t.has('perm.' + id) ? t.key('perm.' + id) : (state.permList[id] || ''));
    let usSort;
    let editUser = null, grantUser = null, notifyUser = null, editGroup = null;

    // ── status card ─────────────────────────────────────────────────────────
    function kv(label, value) {
        return el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: label }), el('div', { className: 'wl-kv-value' }, value)]);
    }
    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text }); }
    // An account's status (users.status) as words: the keys, so a badge is written with t.key() and follows a switch.
    const STATUS_WORD = { active: 'js.users.status_active', banned: 'js.users.status_banned' };

    /**
     * The picture beside a member's name (1.63.0 phase B): assets/js/avatar.js, drawn from
     * `name_avatar` — the address the server built — and null while pictures are switched off.
     */
    function face(u, size, cls) {
        return typeof window.userAvatarImg === 'function'
            ? window.userAvatarImg({ username: u.username, avatar: String(u.name_avatar || '') }, size, 'avatar ' + cls) : null;
    }
    /**
     * A window's title: the picture at 48, then the name, inside the span the template gave the name
     * — one unit (`.av-who`) that a long name can never wrap away from its picture.
     */
    function titleName(id, u) {
        const span = $(id);
        span.classList.add('av-who');
        span.textContent = u.username;
        const pic = face(u, 48, 'us-title-av');
        if (pic) span.prepend(pic);
    }
    function renderStatus(counts, enabled) {
        const grid = $('us-status-grid');
        grid.textContent = '';
        $('us-disabled-note').style.display = enabled ? 'none' : '';
        grid.appendChild(kv(t.key('js.users.accounts'), [badge(enabled ? t.key('js.users.enabled') : t.key('js.users.disabled'), enabled ? 'wl-b-ok' : 'wl-b-warn')]));
        grid.appendChild(kv(t.key('js.users.users'), [
            el('span', { text: t.key('js.users.n_total', { n: counts.total }) }), ' · ',
            el('span', { text: t.key('js.users.n_active', { n: counts.active }) }), ' · ',
            el('span', { className: counts.banned ? 'text-warning' : '', text: t.key('js.users.n_banned', { n: counts.banned }) }),
        ]));
        grid.appendChild(kv(t.key('js.users.groups'), [el('span', { text: String(state.groups.length) }), ' ',
            el('span', { className: 'text-muted wl-small', text: t.key('js.users.guest_note') })]));
    }

    // ── groups data (shared) ────────────────────────────────────────────────
    async function loadGroups() {
        const r = await apiCall('admin/fetch_groups');
        if (r.error) { showToast(t.key('js.users.groups_error', { error: r.error }), 'danger'); return; }
        state.groups = r.groups || [];
        state.permList = r.permission_list || {};
        state.presets = r.presets || {};
        // 1.72.0: the consent ids (userConsentPermissions()) — marked in the editor and the matrix, and on the Admin
        // group the only boxes that are a choice: every other one is held by its blanket.
        state.consent = new Set(r.consent || []);
        // group filter select
        const sel = $('us-filter-group');
        const cur = sel.value;
        sel.textContent = '';
        sel.appendChild(el('option', { value: '', text: t.key('js.users.all_groups') }));
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
        if (r.error) { showToast(t.key('js.users.users_error', { error: r.error }), 'danger'); return; }
        state.us.rows = r.rows || [];
        state.canModerate = !!r.can_moderate;
        renderStatus(r.counts || { total: 0, active: 0, banned: 0 }, !!r.enabled);
        $('us-total').textContent = t.key('js.users.count_users', { n: t.num(r.total || 0) });
        const tb = $('us-body');
        tb.textContent = '';
        if (!state.us.rows.length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 9, className: 'text-center text-muted py-4', text: t.key('js.users.no_users_match') })));
        }
        state.us.rows.forEach(u => {
            const tr = el('tr', {});
            // A name for the row's box (1.74.0, UX-11).
            const pick = el('input', { type: 'checkbox', className: 'us-pick', 'aria-label': t.key('js.users.pick_row', { name: u.username }) });
            pick.checked = state.picked.has(u.id);
            pick.addEventListener('change', () => {
                if (pick.checked) state.picked.add(u.id); else state.picked.delete(u.id);
                syncPickAll();
                if (state.view === 'write') refreshPreview();
            });
            tr.appendChild(el('td', { className: 'us-c-pick' },
                el('label', { className: 'search-check' }, [pick, el('span', { className: 'search-check-box' })])));
            tr.appendChild(el('td', { className: 'wl-id', text: String(u.id) }));
            // The name and the owner's shield in ONE row (1.73.0, admin.css "The users table"): the name shrinks and takes
            // the ellipsis, its whole in its title, and the shield never does. In the cell's one ellipsising line a long
            // name pushed the shield and the chips after it under the cell's "…".
            const nameTd = el('td', {}, [el('div', { className: 'us-name-row' }, [
                el('span', { className: 'av-who' }, [face(u, 24, 'us-row-av'), el('strong', { text: u.username, title: u.username })]),
                u.root_admin ? el('i', { className: 'bi bi-shield-lock-fill text-warning ms-1', title: t.key('js.users.owner_protected') }) : null])]);
            // WHERE THIS ACCOUNT CAN SIGN IN FROM. Under the name because that is the question it
            // qualifies: this row is not only a member here, somebody else can also sign in as them.
            const bridges = (u.identities || []).length ? el('div', { className: 'us-bridges' }) : null;
            if (bridges) nameTd.appendChild(bridges);
            (u.identities || []).forEach(idt => {
                bridges.appendChild(el('span', {
                    className: 'us-bridge' + (idt.signed_out_there ? ' us-bridge-out' : ''),
                    // the word a t.key() word of its own (1.73.0); the account's ids after it are data
                    title: t.key('js.users.bridge_title_full', { provider: idt.provider || '?', id: idt.external_id
                         + (idt.external_name ? ' (' + idt.external_name + ')' : '') }),
                    text: t.key('js.users.bridge_via', { name: idt.provider || '?' }),
                }));
            });
            tr.appendChild(nameTd);
            // The address ellipsizes on its own and the "verified" badge stands outside it (1.68.1): as
            // one line of the cell, a long address pushed the badge past the edge and "…" took its place.
            tr.appendChild(el('td', { className: 'wl-small', title: u.email ? (u.email_verified ? t.key('js.users.email_verified') : t.key('js.users.email_not_verified')) : '' },
                el('span', { className: 'us-email' }, [el('span', { className: 'us-email-text', text: u.email || '—', title: u.email || '' }),
                    u.email && u.email_verified ? el('i', { className: 'bi bi-patch-check-fill text-success ms-1', title: t.key('js.users.verified') }) : null])));
            // …and how many warnings the member has had (1.71.0), beside the status: the latest ones are in the
            // edit window.
            const warned = Number(u.warnings) || 0;
            // The status is a word of the dictionary, not the stored value (it said "ACTIVE" / "BANNED" on a Polish page).
            // …and, since 1.75.0, until WHEN it holds: a ban's end ("until lifted" is the 2099 date; a ban with no date at all is
            // the owner's, from the status box in the edit window) and the end of a silence, each under the badge.
            const untilBits = [];
            if (u.status === 'banned') {
                untilBits.push(el('span', { className: 'us-until', text: !u.banned_until ? t.key('js.users.ban_no_end')
                    : String(u.banned_until).slice(0, 4) >= '2099' ? t.key('js.users.until_lifted') : t.key('js.users.ban_until', { date: fmtDate(u.banned_until) }) }));
            }
            if (u.muted_until) {
                untilBits.push(el('span', { className: 'us-until', text: String(u.muted_until).slice(0, 4) >= '2099' ? t.key('js.users.mute_lifted')
                    : t.key('js.users.mute_until', { date: fmtDate(u.muted_until) }) }));
            }
            tr.appendChild(el('td', { className: 'col-badge' }, [badge(STATUS_WORD[u.status] ? t.key(STATUS_WORD[u.status]) : u.status, u.status === 'active' ? 'wl-b-ok' : 'wl-b-bad'),
                warned ? el('span', { className: 'us-warned', title: t.key('js.users.warned_badge', { n: warned }), 'aria-label': t.key('js.users.warned_badge', { n: warned }) },
                            [el('i', { className: 'bi bi-exclamation-triangle', 'aria-hidden': 'true' }), ' ' + warned]) : null, ...untilBits]));
            // A cell of badges (admin.css, `.col-badge`): the groups wrap onto as many lines as they need.
            const gTd = el('td', { className: 'col-badge col-start' });
            (u.groups || []).forEach(g => {
                const b = el('span', {
                    className: 'us-group-badge' + (g.active ? '' : ' us-inactive'),
                    // One t.key() word (1.73.0): an inactive grant's state and its term as the two placeholders of a pair,
                    // each a t.key() word of its own — glued with + they kept no key for the live language switch.
                    title: g.active
                        ? (g.expires_at ? t.key('js.users.until', { date: g.expires_at }) : t.key('js.users.permanent'))
                        : t.key('js.users.badge_title_pair', {
                            state: new Date(String(g.granted_at).replace(' ', 'T')) > new Date() ? t.key('js.users.starts_at', { date: g.granted_at }) : t.key('js.users.expired'),
                            term: g.expires_at ? t.key('js.users.until', { date: g.expires_at }) : t.key('js.users.permanent') }),
                }, [g.name, g.expires_at ? ' ' : null,
                    g.expires_at ? el('i', { className: 'bi bi-hourglass-split', 'aria-hidden': 'true' }) : null]);
                if (g.color && /^#[0-9a-fA-F]{3,8}$/.test(g.color)) b.style.borderColor = g.color;
                gTd.appendChild(b);
            });
            if (!(u.groups || []).length) gTd.appendChild(el('span', { className: 'text-muted', text: '—' }));
            tr.appendChild(gTd);
            tr.appendChild(el('td', { className: 'wl-small text-muted', text: fmtDate(u.created_at), title: t.key('js.users.ip', { ip: u.created_ip || '—' }) }));
            tr.appendChild(el('td', { className: 'wl-small text-muted', text: u.last_login_at ? fmtDate(u.last_login_at) : t.key('js.users.never'), title: t.key('js.users.ip', { ip: u.last_login_ip || '—' }) }));
            const act = el('td', { className: 'th-actions' });
            const mkBtn = (icon, title, cls, fn) => {
                const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls + ' wl-act', title }, el('i', { className: 'bi ' + icon }));
                b.addEventListener('click', fn);
                return b;
            };
            act.appendChild(mkBtn('bi-award', t.key('js.users.grant_group'), 'btn-outline-success', () => openGrant(u)));
            act.appendChild(mkBtn('bi-pencil', t.key('js.users.edit'), 'btn-outline-info', () => openEdit(u)));
            act.appendChild(mkBtn('bi-bell', t.key('js.users.send_notification'), 'btn-outline-info', () => openNotify(u)));
            const delBtn = mkBtn('bi-trash', u.root_admin ? t.key('js.users.owner_no_delete') : t.key('js.users.delete'), 'btn-outline-danger', () => deleteUser(u));
            if (u.root_admin) delBtn.disabled = true;
            act.appendChild(delBtn);
            tr.appendChild(act);
            // second row of group management: revoke buttons live in the grant modal instead — keep the table lean
            tb.appendChild(tr);
        });
        renderPagination($('us-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p) => { state.us.page = p; loadUsers(); } });
    }

    async function deleteUser(u) {
        if (!(await confirmAction(t.key('js.users.delete_user_title'), t.key('js.users.delete_user_body', { name: u.username }), { danger: true, okLabel: t.key('js.users.delete') }))) return;
        const r = await apiCall('admin/user_delete', 'POST', { id: u.id });
        if (r.success) { showToast(t.key('js.users.deleted_user', { name: u.username })); loadUsers(); }
        else showToast(r.error || t.key('js.users.delete_failed'), 'danger');
    }

    // ── user edit modal ─────────────────────────────────────────────────────
    // An address is checked with its DOMAIN treated as a HOSTNAME. The old test was
    //   /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    // which asked only "is there an @ and a dot after it", and therefore accepted
    // dsaddsas@wp\/.pl — a backslash and a slash inside the domain. The server's
    // filter_var(FILTER_VALIDATE_EMAIL) rejects that, so the form went green and the request came
    // back 400: the live check was telling the user the opposite of what would happen.
    //
    // Verified against filter_var over 37 addresses: there is no input this accepts that the server
    // refuses. It is stricter in five places, all of them things nobody can actually receive mail at
    // (a bracketed IP literal, a one-letter TLD, a hyphenated or digit-suffixed TLD).
    const EMAIL_LOCAL = "[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*";
    const EMAIL_LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
    const EMAIL_TLD = '(?:[A-Za-z]{2,}|xn--[A-Za-z0-9-]{2,})';   // xn-- keeps the IDN TLDs working
    const EMAIL_RE = new RegExp('^' + EMAIL_LOCAL + '@' + EMAIL_LABEL
                                + '(?:\\.' + EMAIL_LABEL + ')*\\.' + EMAIL_TLD + '$');
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
        titleName('ue-name', u);
        $('ue-status').value = u.status;
        // the site owner cannot be banned — grey the option out
        const bannedOpt = $('ue-status').querySelector('option[value="banned"]');
        if (bannedOpt) { bannedOpt.disabled = !!u.root_admin; bannedOpt.title = u.root_admin ? t.key('js.users.owner_no_ban') : ''; }
        $('ue-email').value = u.email || '';
        $('ue-email2').value = '';
        $('ue-password').value = '';
        $('ue-password2').value = '';
        ['ue-email', 'ue-email2', 'ue-password', 'ue-password2'].forEach(id => $(id).classList.remove('is-invalid'));
        ueSyncRepeats();
        $('ue-alert').textContent = '';
        ueMedia(u);
        ueBio(u);
        ueWarnings(u);
        ueModeration(u);
        bootstrap.Modal.getOrCreateInstance($('usEditModal')).show();
    }
    /**
     * The warnings this member has had (1.71.0): how many, and the latest five from admin/fetch_users — when,
     * what came with it, the moderator's reason and who gave it. Read-only here; a warning is given on the
     * Reports page, where the report is.
     */
    function ueWarnings(u) {
        const box = $('ue-warnings-text');
        if (!box) return;
        box.textContent = '';
        const n = Number(u.warnings) || 0;
        if (!n) { box.appendChild(el('span', { className: 'text-muted', text: t.key('js.users.warnings_none') })); return; }
        box.appendChild(el('div', { className: 'text-warning', text: t.key('js.users.warnings_count', { n }) }));
        const ul = el('ul', { className: 'ue-warnings-list' });
        (u.latest_warnings || []).forEach(w => {
            // 1.75.0: a REVOKED row stays in the list, struck through; a silent one and an automatic penalty are marked.
            const marks = [];
            if (w.auto) marks.push(t.key('js.moderation.mark_auto'));
            else if (w.loud === false) marks.push(t.key('js.moderation.mark_silent'));
            if (w.revoked) marks.push(t.key('js.moderation.mark_revoked'));
            const tail = marks.length ? [' (', ...marks.flatMap((m, i) => (i ? [', ', m] : [m])), ')'] : [];
            const li = el('li', { className: w.revoked ? 'mod-revoked' : null });
            if (w.auto && w.auto_info) {
                li.append(t.key('js.users.warning_head', { at: String(w.at || '').slice(0, 16), action: t.key('js.users.warn_action_' + (w.action || 'warn')), by: t.key('js.moderation.by_auto') }),
                          ' ', t.key('js.moderation.auto_reason', { n: w.auto_info.count, days: w.auto_info.window }), ...tail);
            } else if (w.reason_html) {
                // A BBCode / Markdown reason (1.75.0): the server's rendering of it (the site's renderer on escaped input).
                const body = el('div', { className: 'arf-answer rt-body richtext' });
                body.innerHTML = w.reason_html;
                li.append(t.key('js.users.warning_head', { at: String(w.at || '').slice(0, 16), action: t.key('js.users.warn_action_' + (w.action || 'warn')), by: w.by || '—' }), ...tail, body);
            } else {
                li.append(t.key('js.users.warning_line', { at: String(w.at || '').slice(0, 16), action: t.key('js.users.warn_action_' + (w.action || 'warn')),
                                                           reason: w.reason || '', by: w.by || '—' }), ...tail);
            }
            ul.appendChild(li);
        });
        box.appendChild(ul);
    }

    // ── warn, silence and ban by hand; lift (1.75.0) ────────────────────────────────────────────────
    // The Moderation block of the edit window: the SAME path the report cards use (includes/moderation.php, through
    // admin/moderation_action), with a reason in adminRichField(). Acts at once — it is not part of Save. A ban with NO end date
    // (the owner's, from the status box above) is lifted by that box, not here.
    let modField = null;
    function modBox() {
        if (!modField) {
            modField = A.adminRichField({ compact: true, rows: 2, maxLength: 2000, countMax: 2000,
                placeholder: t.key('js.moderation.reason_ph'), ariaLabel: t.key('js.moderation.reason_ph') });
            $('ue-mod-rich').appendChild(modField.el);
        }
        return modField;
    }
    function modSay(text, bad) {
        const m = $('ue-mod-msg');
        if (!m) return;
        m.textContent = '';
        if (text) m.append(text);
        m.className = 'small ' + (bad ? 'text-danger' : 'text-success');
    }
    const modErr = (r) => (r && r.error === 'invalid_text' && r.message) ? r.message
        : (t.has('js.moderation.err_' + (r && r.error)) ? t.key('js.moderation.err_' + r.error) : t.key('js.moderation.err_failed'));
    function ueModeration(u) {
        const box = $('ue-moderation');
        if (!box) return;
        // Not for the owner's own account (it cannot be punished) and not for a session without panel.moderation.handle.
        box.hidden = !state.canModerate || !!u.root_admin;
        if (box.hidden) return;
        modBox().reset();
        modSay('');
        const st = $('ue-mod-state');
        st.textContent = '';
        const bits = [];
        if (u.status === 'banned') {
            bits.push(!u.banned_until ? t.key('js.users.ban_no_end_long') : String(u.banned_until).slice(0, 4) >= '2099' ? t.key('js.users.until_lifted_long')
                : t.key('js.users.ban_until_long', { date: fmtDate(u.banned_until) }));
        }
        if (u.muted_until) {
            bits.push(String(u.muted_until).slice(0, 4) >= '2099' ? t.key('js.users.mute_lifted_long') : t.key('js.users.mute_until_long', { date: fmtDate(u.muted_until) }));
        }
        if (!bits.length) bits.push(t.key('js.users.mod_clean'));
        bits.forEach((b, i) => { if (i) st.append(' · '); st.append(b); });
        // lifting a silence, and a ban that has an end date (a dateless one: the status box)
        $('ue-mod-lift-mute').hidden = !u.muted_until;
        $('ue-mod-lift-ban').hidden = !(u.status === 'banned' && u.banned_until);
        syncModMode();
    }
    /** A warning is always loud: its silent choice goes. */
    function syncModMode() {
        const warn = $('ue-mod-action').value.startsWith('warn:');
        $('ue-mod-silent').disabled = warn;
        if (warn) $('ue-mod-loud').checked = true;
    }
    async function ueModRefresh() {
        const id = editUser ? editUser.id : 0;
        await loadUsers();
        const fresh = (state.us.rows || []).find(x => x.id === id);
        if (fresh) { editUser = fresh; ueModeration(fresh); ueWarnings(fresh); }
    }
    async function ueModApply() {
        if (!editUser) return;
        const [action, days] = $('ue-mod-action').value.split(':');
        const f = modBox();
        const loud = action === 'warn' || $('ue-mod-loud').checked;
        if (loud && f.isEmpty()) { modSay(t.key('js.moderation.err_reason_required'), true); f.focus(); return; }
        if (!(await confirmAction(t.key('js.moderation.confirm_title'), t.key('js.moderation.sure_' + action, { user: editUser.username }), { okLabel: t.key('js.moderation.confirm_ok') }))) return;
        const btn = $('ue-mod-apply');
        btn.disabled = true;
        const r = await apiCall('admin/moderation_action', 'POST', { op: 'apply', user_id: editUser.id, action, days: Number(days), loud,
            reason: f.getValue().trim(), reason_format: f.getFormat() });
        btn.disabled = false;
        if (!r.success) { modSay(modErr(r), true); return; }
        showToast(t.key('js.moderation.done_' + action, { user: editUser.username }));
        if (r.auto) showToast(t.key('js.moderation.done_auto'), 'warning');
        await ueModRefresh();
    }
    async function ueModLift(what) {
        if (!editUser) return;
        const f = modBox();
        const r = await apiCall('admin/moderation_action', 'POST', { op: 'lift', user_id: editUser.id, what,
            loud: $('ue-mod-loud').checked, note: f.getValue().trim(), note_format: f.getFormat() });
        if (!r.success) { modSay(modErr(r), true); return; }
        showToast(t.key(what === 'ban' ? 'js.moderation.lifted_ban' : 'js.moderation.lifted_mute', { user: editUser.username }));
        await ueModRefresh();
    }
    /**
     * The description on their profile (1.69.0): the rendering admin/fetch_users sent — made by the
     * same function the profile uses — and a Clear that takes it away at once through admin/user_bio,
     * asked first like Remove picture, because the text is deleted for good and the member is told.
     */
    function ueBio(u) {
        const box = $('ue-bio');
        if (!box) return;
        box.hidden = !u.has_bio;
        // Server-rendered (includes/profilebio.php): escaped text and five tags, nothing else.
        $('ue-bio-text').innerHTML = u.has_bio ? String(u.bio_html || '') : '';
        $('ue-bio-hidden').hidden = !u.has_bio || !!u.bio_shown;
    }
    async function ueClearBio() {
        if (!editUser) return;
        if (!(await confirmAction(t.key('js.users.bio_clear_title'), t.key('js.users.bio_clear_q', { user: editUser.username })))) return;
        const r = await apiCall('admin/user_bio', 'POST', { op: 'clear', id: editUser.id });
        if (!r.success) { showToast(r.error || t.key('js.users.bio_failed'), 'danger'); return; }
        if (r.user) { editUser.has_bio = !!r.user.has_bio; editUser.bio_html = String(r.user.html || ''); editUser.bio_shown = !!r.user.shown; }
        ueBio(editUser);
        showToast(r.message || t.key('js.users.bio_cleared'));
        loadUsers();
    }
    /**
     * The picture and the cover (1.63.0): the pair of buttons appears only for what the account has.
     * Taking one down happens at once (admin/user_media), not on Save — the member is told in a
     * notification and the server keeps nothing — so it asks first, and says both of those things.
     */
    function ueMedia(u) {
        const box = $('ue-media');
        if (!box) return;
        const thumb = $('ue-media-avatar');
        box.hidden = !(u.has_avatar || u.has_cover);
        $('ue-remove-avatar').hidden = !u.has_avatar;
        $('ue-remove-cover').hidden = !u.has_cover;
        thumb.hidden = !u.has_avatar;
        if (u.has_avatar && u.avatar) thumb.src = u.avatar; else thumb.removeAttribute('src');
    }
    async function ueRemoveMedia(what) {
        if (!editUser) return;
        const q = t.key(what === 'cover' ? 'js.mediaadmin.user_remove_cover_q' : 'js.mediaadmin.user_remove_avatar_q', { user: editUser.username });
        if (!(await confirmAction(t.key('js.mediaadmin.remove_title'), q))) return;
        const r = await apiCall('admin/user_media', 'POST', { op: what === 'cover' ? 'remove_cover' : 'remove_avatar', id: editUser.id });
        if (!r.success) { showToast(r.error || t.key('js.mediaadmin.failed'), 'danger'); return; }
        if (r.user) {
            editUser.has_avatar = r.user.has_avatar; editUser.has_cover = r.user.has_cover; editUser.avatar = r.user.avatar;
            if (typeof r.user.name_avatar === 'string') { editUser.name_avatar = r.user.name_avatar; titleName('ue-name', editUser); }
        }
        ueMedia(editUser);
        showToast(r.message || t.key('js.mediaadmin.removed'));
        loadUsers();
    }
    async function saveEdit() {
        if (!ueValidate()) return;
        // The status only when the moderator CHANGED it (1.75.0): it used to be sent with every save, and "banned" written over a
        // ban that had an end date made it dateless — editing an address ended a timed ban's date. (The server ignores a
        // status the account already has, too.)
        // The address the same way (1.75.0): the server marks an address it is given as verified, so sending the unchanged one
        // with every save verified an address nobody had confirmed whenever only a password or a group was being set.
        const body = { id: editUser.id };
        const mail = $('ue-email').value.trim();
        if (mail !== (editUser.email || '')) body.email = mail;
        if ($('ue-status').value !== editUser.status) body.status = $('ue-status').value;
        if ($('ue-password').value !== '') body.password = $('ue-password').value;
        // Nothing was changed: nothing to send (the server would answer "nothing to change" inside the window).
        if (Object.keys(body).length === 1) {
            showToast(t.key('js.users.nothing_changed'));
            bootstrap.Modal.getOrCreateInstance($('usEditModal')).hide();
            return;
        }
        const r = await apiCall('admin/user_update', 'POST', body);
        if (r.success) {
            showToast(t.key('js.users.saved'));
            bootstrap.Modal.getOrCreateInstance($('usEditModal')).hide();
            loadUsers();
        } else {
            $('ue-alert').textContent = '';
            $('ue-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || t.key('js.users.save_failed') }));
        }
    }

    // ── add user ────────────────────────────────────────────────────────────
    //
    // The one decision worth making explicit is what happens to the email address, because
    // `users_require_email_verify` decides what an unverified account may DO and an admin creating
    // an account by hand should not have to guess which of the three states they just produced.
    // Live validation, the same rules the server enforces.
    //
    // The public registration form has had this since 1.8.0; the admin dialog was posting blind and
    // finding out from a 400. An admin creating an account for somebody else has no more patience
    // for that than a visitor does, and the password rules in particular are not guessable from a
    // sentence — you find out which of the five you missed only after pressing the button.
    const UA_PW_REQS = [
        [t.key('js.users.pw_length'), (p) => p.length >= 8 && p.length <= 200],
        [t.key('js.users.pw_lower'), (p) => /[a-z]/.test(p)],
        [t.key('js.users.pw_upper'), (p) => /[A-Z]/.test(p)],
        [t.key('js.users.pw_special'), (p) => /[^a-zA-Z0-9]/.test(p)],
        [t.key('js.users.pw_digit'), (p) => /[0-9]/.test(p)],
    ];
    // Mirrors userValidUsername() and userValidEmail() in includes/users.php.
    const uaUserOk = (v) => /^[A-Za-z0-9_.-]{3,32}$/.test(v);
    const uaMailOk = (v) => v.length <= 190 && EMAIL_RE.test(v);
    let uaPwItems = null;

    function uaBuildPwList() {
        const box = $('ua-pw-reqs');
        if (!box || uaPwItems) return;
        uaPwItems = UA_PW_REQS.map(([label]) => {
            const li = el('div', { className: 'pw-req' }, [
                el('i', { className: 'bi bi-x-lg pw-req-ic', 'aria-hidden': 'true' }),
                el('span', { text: ' ' + label }),
            ]);
            box.appendChild(li);
            return li;
        });
    }

    function uaValidate() {
        uaBuildPwList();
        const user = $('ua-username').value.trim();
        const mail = $('ua-email').value.trim();
        const pw = $('ua-password').value;
        const needMail = $('ua-verify').value !== 'none';

        const userOk = user === '' ? null : uaUserOk(user);
        $('ua-username').classList.toggle('is-invalid', userOk === false);
        $('ua-username-msg').textContent = userOk === false
            ? t.key('js.users.username_rule') : '';

        // An address is required unless verification is "no email", because the panel would
        // otherwise promise a verified address or a sent link for something that does not exist.
        let mailOk = null;
        if (mail !== '') mailOk = uaMailOk(mail);
        else if (needMail) mailOk = false;
        $('ua-email').classList.toggle('is-invalid', mailOk === false);
        $('ua-email-msg').textContent = mailOk === false
            ? (mail === '' ? t.key('js.users.email_required')
                           : t.key('js.users.email_invalid')) : '';

        let pwOk = true;
        UA_PW_REQS.forEach(([, test], i) => {
            const ok = test(pw);
            if (!ok) pwOk = false;
            uaPwItems[i].classList.toggle('ok', ok);
            uaPwItems[i].querySelector('.pw-req-ic').className = ok ? 'bi bi-check-lg pw-req-ic' : 'bi bi-x-lg pw-req-ic';
        });

        const allOk = userOk === true && mailOk !== false && pwOk;
        $('ua-save').disabled = !allOk;
        return allOk;
    }

    function uaHint() {
        const v = $('ua-verify').value;
        const req = $('ua-email-req');
        req.textContent = v === 'none' ? t.key('js.users.optional') : t.key('js.users.required');
        $('ua-verify-hint').textContent = {
            auto: t.key('js.users.verify_auto'),
            send: t.key('js.users.verify_send'),
            none: t.key('js.users.verify_none'),
        }[v] || '';
    }
    function uaGenerate() {
        // Generated in the browser and shown in clear: this password has to be passed on to a person,
        // and a value the admin cannot read is a value they will replace with "Password1!".
        const sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%^&*?-_=+'];
        const rnd = n => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };
        let out = sets.map(s => s[rnd(s.length)]);              // one of each class, guaranteed
        const all = sets.join('');
        while (out.length < 18) out.push(all[rnd(all.length)]);
        for (let i = out.length - 1; i > 0; i--) { const j = rnd(i + 1); [out[i], out[j]] = [out[j], out[i]]; }
        $('ua-password').value = out.join('');
        if (uaPwItems) uaValidate();
    }
    function openAdd() {
        ['ua-username', 'ua-email', 'ua-password'].forEach(id => { $(id).value = ''; $(id).classList.remove('is-invalid'); });
        $('ua-verify').value = 'auto';
        $('ua-status').value = 'active';
        $('ua-error').classList.add('d-none');
        uaGenerate();
        uaHint();
        uaValidate();
        bootstrap.Modal.getOrCreateInstance($('userAddModal')).show();
        setTimeout(() => $('ua-username').focus(), 200);
    }
    async function saveAdd() {
        const err = $('ua-error');
        err.classList.add('d-none');
        if (!uaValidate()) return;
        const body = {
            username: $('ua-username').value.trim(),
            email: $('ua-email').value.trim(),
            password: $('ua-password').value,
            verify: $('ua-verify').value,
            status: $('ua-status').value,
        };
        const btn = $('ua-save');
        btn.disabled = true;
        try {
            const r = await apiCall('admin/user_create', 'POST', body);
            if (r.success) {
                // The message distinguishes "created and verified" from "created but the mail did not
                // go out", because those need different things from the admin next.
                showToast(r.message || t.key('js.users.account_created'));
                bootstrap.Modal.getOrCreateInstance($('userAddModal')).hide();
                loadUsers();
            } else {
                err.textContent = r.error || t.key('js.users.create_failed');
                err.classList.remove('d-none');
            }
        } finally {
            btn.disabled = false;
        }
    }

    // ── grant modal ─────────────────────────────────────────────────────────
    function openGrant(u) {
        grantUser = u;
        titleName('ug-name', u);
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
            list.appendChild(el('div', { className: 'wl-label form-label', text: t.key('js.users.current_memberships') }));
            u.groups.forEach(g => {
                const row = el('div', { className: 'd-flex align-items-center gap-2 wl-small mb-1' }, [
                    el('span', { className: 'us-group-badge' + (g.active ? '' : ' us-inactive'), text: g.name }),
                    el('span', { className: 'text-muted', text: g.expires_at ? t.key('js.users.until', { date: g.expires_at }) : t.key('js.users.permanent') }),
                ]);
                const rm = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger wl-act', title: t.key('js.users.revoke') }, el('i', { className: 'bi bi-x-lg' }));
                const gid = (state.groups.find(x => x.slug === g.slug) || {}).id;
                if (u.root_admin && g.slug === 'admin') { rm.disabled = true; rm.title = t.key('js.users.owner_no_revoke'); }
                rm.addEventListener('click', async () => {
                    if (!(await confirmAction(t.key('js.users.revoke_group_title'), t.key('js.users.revoke_group_body', { group: g.name, name: u.username }), { danger: true, okLabel: t.key('js.users.revoke') }))) return;
                    const r = await apiCall('admin/user_revoke', 'POST', { id: u.id, group_id: gid });
                    if (r.success) { showToast(t.key('js.users.revoked')); bootstrap.Modal.getOrCreateInstance($('usGrantModal')).hide(); loadUsers(); }
                    else showToast(r.error || t.key('js.users.revoke_failed'), 'danger');
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
            showToast(r.expires_at ? t.key('js.users.granted_until', { group: r.group, date: r.expires_at }) : t.key('js.users.granted_permanent', { group: r.group }));
            bootstrap.Modal.getOrCreateInstance($('usGrantModal')).hide();
            loadUsers();
        } else {
            $('ug-alert').textContent = '';
            $('ug-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || t.key('js.users.grant_failed') }));
        }
    }

    // ── notify modal ────────────────────────────────────────────────────────
    // The note to one member takes the site's formats (1.75.0): the shared box, adminRichField() — plain, and BBCode /
    // Markdown where the site has them on — and its server preview. Made once, when the window is first opened.
    let notifyField = null;
    function notifyBox() {
        if (!notifyField) {
            notifyField = A.adminRichField({ textId: 'un-body', formatId: 'un-format', rows: 4, maxLength: 5000, countMax: 5000, compact: true });
            $('un-rich').appendChild(notifyField.el);
        }
        return notifyField;
    }
    function openNotify(u) {
        notifyUser = u;
        titleName('un-name', u);
        $('un-title').value = ''; notifyBox().reset();
        $('un-email').checked = false;
        $('un-alert').textContent = '';
        bootstrap.Modal.getOrCreateInstance($('usNotifyModal')).show();
    }
    async function sendNotify() {
        const r = await apiCall('admin/user_notify', 'POST', { id: notifyUser.id, title: $('un-title').value.trim(), body: notifyBox().getValue().trim(), format: notifyBox().getFormat(), email: $('un-email').checked ? 1 : 0 });
        if (r.success) {
            showToast(r.mailed ? t.key('js.users.sent_email') : t.key('js.users.sent'));
            bootstrap.Modal.getOrCreateInstance($('usNotifyModal')).hide();
        } else {
            $('un-alert').textContent = '';
            $('un-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || t.key('js.users.send_failed') }));
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
            // The name, the slug and the permissions ellipsise with their whole in a title (1.73.0): a long name, its
            // "(system)" after it, a 64-character slug and a list of seventy ids were cut with nothing to read them in.
            // the name and, for a system group, the suffix as one t.key() word (1.73.0: glued with + it kept no key)
            tr.appendChild(el('td', { title: g.is_system ? t.key('js.users.system_title', { name: g.name, suffix: t.key('js.users.system_suffix') }) : g.name },
                [nameEl, g.is_system ? el('span', { className: 'text-muted wl-small', text: t.key('js.users.system_suffix') }) : null]));
            tr.appendChild(el('td', { className: 'font-mono wl-small', text: g.slug, title: g.slug }));
            tr.appendChild(el('td', { text: String(g.priority) }));
            tr.appendChild(el('td', { className: 'col-badge' }, g.is_default ? badge(t.key('js.users.default_badge'), 'wl-b-ok') : el('span', { className: 'text-muted', text: '—' })));
            tr.appendChild(el('td', { text: String(g.members) }));
            const permKeys = Object.keys(g.permissions || {});
            // The Admin group holds every capability by its blanket (and, since 1.72.0, stores them too): seventy ids
            // in a row say nothing a person can read, so its cell says what is true and names its consent, the one
            // part of it that is a choice.
            let permText = permKeys.length ? permKeys.join(', ') : t.key('js.users.none');
            if (g.slug === 'admin') {
                const given = permKeys.filter(k => state.consent.has(k));
                permText = given.length ? t.key('js.users.perms_admin', { list: given.join(', ') }) : t.key('js.users.perms_admin_none');
            }
            tr.appendChild(el('td', { className: 'wl-small text-muted', text: permText, title: permText }));
            const act = el('td', { className: 'th-actions' });
            const edit = el('button', { type: 'button', className: 'btn btn-sm btn-outline-info wl-act', title: t.key('js.users.edit') }, el('i', { className: 'bi bi-pencil' }));
            edit.addEventListener('click', () => openGroupEditor(g));
            act.appendChild(edit);
            // A seeded group's recommended set (1.72.0): what is missing, what a reset would take away.
            if (g.recommended) {
                const rec = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success wl-act gr-rec-btn',
                                           title: t.key('js.users.rec_btn'), 'aria-label': t.key('js.users.rec_btn'), dataset: { slug: g.slug } },
                               el('i', { className: 'bi bi-magic', 'aria-hidden': 'true' }));
                rec.addEventListener('click', () => openRecommended(g));
                act.appendChild(rec);
            }
            if (!g.is_system) {
                const del = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger wl-act', title: t.key('js.users.delete') }, el('i', { className: 'bi bi-trash' }));
                del.addEventListener('click', async () => {
                    if (!(await confirmAction(t.key('js.users.delete_group_title'), t.key('js.users.delete_group_body', { name: g.name, n: g.members }), { danger: true, okLabel: t.key('js.users.delete') }))) return;
                    const r = await apiCall('admin/group_delete', 'POST', { id: g.id });
                    if (r.success) { showToast(t.key('js.users.deleted')); loadGroups(); }
                    else showToast(r.error || t.key('js.users.delete_failed'), 'danger');
                });
                act.appendChild(del);
            }
            tr.appendChild(act);
            tb.appendChild(tr);
        });
        renderMatrix();
    }

    /** Groups across, permissions down — the same data as the key list, in a shape a person reads. */
    function renderMatrix() {
        const tbl = $('gr-matrix');
        if (!tbl) return;
        tbl.textContent = '';
        const groups = state.groups || [];
        const perms = Object.keys(state.permList || {});
        if (!groups.length || !perms.length) return;
        const thead = el('thead', {});
        const hr = el('tr', {}, [el('th', { text: t.key('js.users.matrix_permission') })]);
        groups.forEach(g => {
            const th = el('th', { className: 'gr-matrix-g', title: g.slug }, [g.name]);
            if (g.color && /^#[0-9a-fA-F]{3,8}$/.test(g.color)) th.style.color = g.color;
            hr.appendChild(th);
        });
        thead.appendChild(hr); tbl.appendChild(thead);
        const tbody = el('tbody', {});
        let section = '';
        perms.forEach(key => {
            const sec = key.startsWith('panel.') ? 'PANEL' : 'SITE';
            if (sec !== section) {
                section = sec;
                tbody.appendChild(el('tr', { className: 'gr-matrix-sec' }, [el('td', { colspan: String(groups.length + 1), text: sec === 'PANEL' ? t.key('js.users.matrix_panel') : t.key('js.users.matrix_site') })]));
            }
            const consent = state.consent.has(key);
            const tr = el('tr', { className: consent ? 'gr-matrix-consent' : null, dataset: { perm: key } }, [el('td', { title: permWords(key) }, [el('code', { text: key }),
                consent ? el('span', { className: 'gr-consent-badge', title: t.key('js.users.consent_title') }, t.key('js.users.consent_badge')) : null])]);
            groups.forEach(g => {
                const on = !!(g.permissions && g.permissions[key]);
                // The Admin group passes every capability check by its blanket, whatever is stored: that is a tick of its
                // own kind. Its consent is a grant like anybody's and shows what is stored (1.72.0).
                if (g.slug === 'admin' && !consent) {
                    tr.appendChild(el('td', { className: 'gr-matrix-c on blanket', dataset: { slug: g.slug } }, [
                        el('i', { className: 'bi bi-check-lg', title: t.key('js.users.blanket_cell', { group: g.name, key: key }) })]));
                    return;
                }
                tr.appendChild(el('td', { className: 'gr-matrix-c' + (on ? ' on' : ''), dataset: { slug: g.slug } }, [
                    on ? el('i', { className: 'bi bi-check-lg', title: t.key('js.users.matrix_has', { group: g.name, key: key }) }) : el('i', { className: 'bi bi-dot gr-matrix-off', 'aria-hidden': 'true' })]));
            });
            tbody.appendChild(tr);
        });
        tbl.appendChild(tbody);
    }
    function openGroupEditor(g) {
        editGroup = g;   // null = new
        $('ge-title').textContent = g ? t.key('js.users.group_edit_title', { name: g.name }) : t.key('js.users.group_new');
        $('ge-name').value = g ? g.name : '';
        $('ge-slug').value = g ? g.slug : '';
        $('ge-slug').disabled = !!(g && g.is_system);
        $('ge-color').value = g ? g.color : '';
        $('ge-priority').value = g ? String(g.priority) : '0';
        $('ge-desc').value = g ? g.description : '';
        $('ge-default').checked = !!(g && g.is_default);
        $('ge-alert').textContent = '';
        // Presets. One click ticks a set; the checkboxes below stay visible and editable, so a
        // preset is a starting point the operator can see through, not a lock.
        const pre = $('ge-presets');
        if (pre) {
            pre.textContent = '';
            pre.appendChild(el('span', { className: 'wl-small text-muted me-1', text: t.key('js.users.presets_start_from') }));
            // A preset never touches a box that is not a choice (the Admin group's capabilities, 1.72.0).
            // The preset's name and line are the server's, in the reader's language (a.users.rec_name_* / rec_about_*):
            // found back by key (1.73.0), so they follow the live language switch like the words around them.
            Object.entries(state.presets || {}).forEach(([key, p]) => {
                const b = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary ge-preset', title: t.find(p.about || '', 'a.users.rec_about_') },
                             [t.find(p.label || key, 'a.users.rec_name_')]);
                b.addEventListener('click', () => {
                    const set = new Set(p.perms || []);
                    $('ge-perms').querySelectorAll('input[data-perm]:not(:disabled)').forEach(cb => { cb.checked = set.has(cb.dataset.perm); });
                });
                pre.appendChild(b);
            });
            const none = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary ge-preset', title: t.key('js.users.presets_untick') }, [t.key('js.users.presets_none')]);
            none.addEventListener('click', () => $('ge-perms').querySelectorAll('input[data-perm]:not(:disabled)').forEach(cb => { cb.checked = false; }));
            pre.appendChild(none);
        }
        const box = $('ge-perms');
        box.textContent = '';
        // The Admin group (1.72.0): every capability is its blanket's — its members pass that check whatever is ticked —
        // so those boxes are drawn ticked and cannot be cleared (the save keeps them either way, api/admin/group_save.php);
        // its consent boxes are real choices, and one line above the list says which is which.
        const isAdmin = !!(g && g.slug === 'admin');
        let note = $('ge-admin-note');
        if (!note) {
            note = el('p', { id: 'ge-admin-note', className: 'ge-admin-note wl-small' });
            box.parentNode.insertBefore(note, box);
        }
        note.textContent = isAdmin ? t.key('js.users.consent_admin_line') : '';
        note.hidden = !isAdmin;
        Object.keys(state.permList).forEach((key) => {
            const id = 'gp-' + key.replace(/\./g, '-');
            const consent = state.consent.has(key);
            const blanket = isAdmin && !consent;
            const wrap = el('div', { className: 'form-check' + (blanket ? ' ge-blanket' : '') + (consent ? ' ge-consent' : ''),
                                     title: blanket ? t.key('js.users.blanket_box') : (consent ? t.key('js.users.consent_title') : null) }, [
                el('input', { className: 'form-check-input', type: 'checkbox', id, dataset: { perm: key }, disabled: blanket }),
                // the id, then what it allows — in pieces, so the words keep their key (1.73.0)
                el('label', { className: 'form-check-label', for: id }, [el('code', { text: key }),
                    consent ? el('span', { className: 'gr-consent-badge' }, t.key('js.users.consent_badge')) : null, ' — ', permWords(key)]),
            ]);
            wrap.querySelector('input').checked = blanket || !!(g && g.permissions && g.permissions[key]);
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
            showToast(t.key('js.users.group_saved'));
            bootstrap.Modal.getOrCreateInstance($('grEditModal')).hide();
            loadGroups();
        } else {
            $('ge-alert').textContent = '';
            $('ge-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: r.error || t.key('js.users.save_failed') }));
        }
    }

    // ── a seeded group's recommended set (1.72.0) ───────────────────────────
    //
    // The window shows exactly what the server's plan is (api/admin/group_recommended, GET): what "Add what is
    // missing" writes, what a reset would also take away — and on the Admin group the consent it can give, a tick of
    // its own that asks the plan again. Applying sends that plan back as `expect`: a group that changed since it was
    // shown is not touched, and the window redraws with the difference as it is now (the server does exactly what the
    // screen showed, never what a stale one would imply). A reset that takes anything away asks a second time.
    let recGroup = null, recPreview = null, recSeq = 0;
    function recIds(ids) {
        const wrap = el('div', { className: 'gr-rec-ids' });
        if (!ids.length) { wrap.appendChild(el('span', { className: 'text-muted wl-small', text: t.key('js.users.rec_none') })); return wrap; }
        ids.forEach(k => {
            const consent = state.consent.has(k);
            // The permission's description on the id, what "consent" means on its badge (1.73.0: one title glued from
            // both kept no key, and stayed in the old language after a live switch).
            wrap.appendChild(el('code', { className: 'gr-rec-id' + (consent ? ' is-consent' : ''), dataset: { perm: k },
                                          title: permWords(k) || null },
                                [k, consent ? el('span', { className: 'gr-consent-badge', title: t.key('js.users.consent_title') }, t.key('js.users.consent_badge')) : null]));
        });
        return wrap;
    }
    function setRecButtons(p) {
        $('gr-rec-add').disabled = !p || !p.add.length;
        // A reset is more than "add" only when it takes something away; otherwise it is the same request twice.
        $('gr-rec-reset').disabled = !p || !p.remove.length;
    }
    function recAlert(text, kind) {
        const box = $('gr-rec-alert');
        box.textContent = '';
        if (text) box.appendChild(el('div', { className: 'alert alert-' + (kind || 'danger') + ' py-2 wl-small mt-2', text }));
    }
    function renderRecPreview(p) {
        recPreview = p;
        const body = $('gr-rec-body');
        body.textContent = '';
        // The set's name and line are the server's (a.users.rec_name_* / rec_about_*): found back by key (t.find(), the
        // page's bundle carries a.users.rec_ — templates/admin/users.php), so they follow the live language switch (1.73.0).
        body.appendChild(el('p', { className: 'gr-rec-set mb-1' }, [t.key('js.users.rec_set', { label: t.find(p.recommended.label, 'a.users.rec_name_'), n: p.recommended.ids.length })]));
        if (p.recommended.about) body.appendChild(el('p', { className: 'gr-rec-about wl-small text-muted mb-2', text: t.find(p.recommended.about, 'a.users.rec_about_') }));
        if (p.blanket) body.appendChild(el('p', { className: 'gr-rec-blanket wl-small mb-2' }, [el('i', { className: 'bi bi-shield-check', 'aria-hidden': 'true' }), ' ', t.key('js.users.rec_blanket')]));
        if (!p.add.length && !p.remove.length && !(p.consent && p.consent.add.length)) {
            body.appendChild(el('p', { className: 'gr-rec-exact mb-2' }, [el('i', { className: 'bi bi-check-circle', 'aria-hidden': 'true' }), ' ', t.key('js.users.rec_exact')]));
        }
        body.appendChild(el('div', { className: 'gr-rec-sec', id: 'gr-rec-add-sec' }, [
            el('h6', { className: 'gr-rec-head', text: t.key('js.users.rec_add_head', { n: p.add.length }) }), recIds(p.add)]));
        body.appendChild(el('div', { className: 'gr-rec-sec', id: 'gr-rec-remove-sec' }, [
            el('h6', { className: 'gr-rec-head', text: t.key('js.users.rec_remove_head', { n: p.remove.length }) }), recIds(p.remove)]));
        if (p.consent) {
            const pending = p.consent.add || [];
            const given = (p.consent.ids || []).filter(k => (p.held || []).includes(k));
            const box = el('div', { className: 'gr-rec-consent' });
            if (pending.length) {
                const cb = el('input', { className: 'form-check-input', type: 'checkbox', id: 'gr-rec-consent' });
                cb.checked = !!p.consent.applied;
                cb.addEventListener('change', () => loadRecPreview(cb.checked));
                box.appendChild(el('div', { className: 'form-check' }, [cb,
                    el('label', { className: 'form-check-label', for: 'gr-rec-consent', text: t.key('js.users.rec_consent_label', { ids: pending.join(', ') }) })]));
            }
            if (given.length) box.appendChild(el('p', { className: 'wl-small text-muted mb-1', text: t.key('js.users.rec_consent_held', { ids: given.join(', ') }) }));
            box.appendChild(el('p', { className: 'gr-rec-consent-why wl-small text-muted mb-0', text: t.key('js.users.rec_consent_why') }));
            body.appendChild(box);
        }
        setRecButtons(p);
    }
    async function loadRecPreview(consent) {
        if (!recGroup) return;
        const my = ++recSeq;
        setRecButtons(null);
        const r = await apiCall('admin/group_recommended&id=' + encodeURIComponent(recGroup.id) + (consent ? '&consent=1' : ''));
        if (my !== recSeq) return;   // a newer question was asked meanwhile (the consent tick pressed twice)
        if (r.error) { recAlert(r.error); return; }
        recAlert('');
        renderRecPreview(r);
    }
    function openRecommended(g) {
        recGroup = g; recPreview = null;
        $('gr-rec-title').textContent = t.key('js.users.rec_title', { name: g.name });
        $('gr-rec-body').textContent = t.key('js.common.loading');
        recAlert('');
        setRecButtons(null);
        bootstrap.Modal.getOrCreateInstance($('grRecModal')).show();
        loadRecPreview(false);
    }
    async function applyRecommended(mode) {
        const p = recPreview;
        if (!p) return;
        if (mode === 'reset') {
            // The second question: what goes, by name, and who loses it.
            const yes = await confirmAction(t.key('js.users.rec_confirm_title'), t.key('js.users.rec_confirm_body', { n: p.remove.length, name: p.group.name }),
                { danger: true, okLabel: t.key('js.users.rec_confirm_ok'), code: p.remove.join(' '),
                  after: t.key('js.users.rec_confirm_after', { m: p.group.members, missing: p.add.length ? p.add.join(', ') : t.key('js.users.rec_none') }) });
            if (!yes) return;
        }
        const consent = !!(p.consent && p.consent.applied);
        setRecButtons(null);
        const r = await apiCall('admin/group_recommended', 'POST', { id: p.group.id, mode, consent, expect: { add: p.add, remove: p.remove } });
        if (r.success) {
            const name = p.group.name;
            showToast(!r.changed ? t.key('js.users.rec_nothing', { name })
                : (mode === 'reset' ? t.key('js.users.rec_reset_done', { name, added: r.added.length, removed: r.removed.length })
                                    : t.key('js.users.rec_added', { name, n: r.added.length })));
            bootstrap.Modal.getOrCreateInstance($('grRecModal')).hide();
            loadGroups();
            return;
        }
        if (r.code === 'changed' && r.preview) {
            renderRecPreview(r.preview);
            recAlert(t.key('js.users.rec_changed'), 'warning');
            loadGroups();
            return;
        }
        recAlert(t.key('js.users.rec_failed', { error: r.error || ('HTTP ' + r.__status) }));
        setRecButtons(p);
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
        box.appendChild(el('span', { className: 'text-muted', text: t.key('js.users.write_counting') }));
        const r = await apiCall('admin/bulk_send', 'POST', { op: 'preview', audience: writeAudience() });
        if (!r || !r.success) {
            box.textContent = '';
            box.appendChild(el('span', { className: 'text-danger', text: (r && r.error) || t.key('js.users.write_count_failed') }));
            return;
        }
        $('bm-off-note').style.display = r.enabled ? 'none' : '';
        bmFillFormats(r.formats);
        // "Send…" said nothing about who is about to be written to, which is the one fact that
        // matters before pressing it. The count comes from the same preview that fills the note
        // below, so the label and the sentence can never disagree.
        const lbl = $('bm-send-label');
        if (lbl) {
            const mode = $('bm-mode').value;
            const who = mode === 'group'
                ? ($('bm-group').options[$('bm-group').selectedIndex] || {}).text || t.key('js.users.write_who_group')
                : (mode === 'selected' ? t.key('js.users.write_who_selected') : t.key('js.users.write_who_all'));
            const n = $('bm-email').checked ? r.recipients : r.audience;
            lbl.textContent = n > 0
                ? t.key('js.users.write_send_to', { who: who, n: n })
                : t.key('js.users.write_nobody');
        }
        box.textContent = '';
        box.appendChild(el('strong', { text: String(r.recipients) }));
        const why = [];
        if (r.no_email) why.push(t.key('js.users.write_why_no_email', { n: r.no_email }));
        if (r.opted_out) why.push(t.key('js.users.write_why_opted_out', { n: r.opted_out }));
        if (r.unsubscribed) why.push(t.key('js.users.write_why_unsubscribed', { n: r.unsubscribed }));
        // Pieces, each sentence a t.key() word that keeps its key for the live language switch (1.73.0).
        box.append(t.key('js.users.write_of_audience', { n: r.audience }),
            ...(why.length ? [' - '].concat(why.reduce((out, w, i) => (i ? out.push(', ', w) : out.push(w), out), [])) : []), '. ');
        box.appendChild(el('span', { className: 'text-muted wl-small',
            text: t.key('js.users.write_inapp_note', { n: r.audience }) }));
    }

    async function loadBatches() {
        const tb = $('bm-batches');
        if (!tb) return;
        const r = await apiCall('admin/bulk_send', 'POST', { op: 'batches' });
        tb.textContent = '';
        if (!r || !r.success) return;
        $('bm-depth').textContent = r.depth ? t.key('js.users.write_depth', { n: r.depth, rate: r.per_minute }) : '';
        if (!(r.batches || []).length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 7, className: 'text-center text-muted py-4', text: t.key('js.users.write_nothing_sent') })));
            return;
        }
        r.batches.forEach(b => {
            const tr = el('tr', {});
            tr.appendChild(el('td', { className: 'wl-small', text: fmtDate(String(b.started).replace(' ', 'T')) }));
            tr.appendChild(el('td', { text: b.subject || '-', title: b.subject || '' }));   // ellipsised, whole in its title (1.73.0)
            tr.appendChild(el('td', { text: String(b.total) }));
            tr.appendChild(el('td', { text: String(b.sent) }));
            tr.appendChild(el('td', { className: Number(b.failed) ? 'text-warning' : '', text: String(b.failed) }));
            tr.appendChild(el('td', { text: String(b.pending) }));
            const act = el('td', { className: 'td-actions' });
            if (Number(b.pending) > 0) {
                const stop = el('button', { type: 'button', className: 'btn btn-sm btn-outline-warning wl-act', title: t.key('js.users.batch_stop_title') },
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
        const pw = await askPassword(t.key('js.users.batch_stop_head'),
            t.key('js.users.batch_stop_body', { n: pending }));
        if (!pw) return;
        const r = await apiCall('admin/bulk_send', 'POST', { op: 'cancel', batch_id: id, password: pw });
        showToast((r && (r.message || r.error)) || t.key('js.users.done'), r && r.success ? 'success' : 'error');
        loadBatches();
    }

    // ── the message editor ──────────────────────────────────────────────────
    //
    // The box — format select, toolbar, textarea, counter, preview — is adminRichField() (assets/js/admin-common.js, 1.75.0),
    // the component every window that takes words with a format uses; this file says only what is particular to a BULK
    // message: the formats are the audience preview's (plain, the site's BBCode / Markdown, and HTML where the server can
    // parse it), the limit is 60 000 BYTES (a copy is written for every recipient), and the preview is the WHOLE MAIL —
    // the frame (or none), the body, the footer — made by admin/bulk_send (op: render → bulkMailParts(), the function the
    // janitor sends with) and shown in a frame with no permissions. The old inline editor (a toolbar, a textarea, an
    // innerHTML preview) is gone: an innerHTML preview of an administrator's HTML would have put it into the panel.
    const BULK_MAX_BYTES = 60000;
    let bmField = null, bmFormatsSet = false;
    function bmFormat() { return bmField ? bmField.getFormat() : 'plain'; }
    /** 'none' when "Site frame" is unticked: the author's own design, with only the footer the law asks for. */
    function bmFrame() { const c = $('bm-frame'); return c && !c.checked ? 'none' : 'site'; }
    function bmBox() {
        if (!bmField) {
            bmField = A.adminRichField({
                textId: 'bm-body', formatId: 'bm-format', rows: 9,
                countMax: BULK_MAX_BYTES, countBytes: true,
                placeholder: t.key('js.rich.bulk_ph'),
                formats: ['plain'],                 // the audience preview says which (bmFillFormats())
                preview: 'frame',
                previewFetch: async (text, format) => {
                    const r = await apiCall('admin/bulk_send', 'POST', { op: 'render', subject: $('bm-subject').value.trim(), body: text, format, frame: bmFrame() });
                    return r && r.success ? { html: r.html, problem: r.problem } : null;
                },
            });
            $('bm-rich').appendChild(bmField.el);
        }
        return bmField;
    }
    /** Offer only the formats the server says (plain, the site's, and HTML where it can be cleaned) — once. */
    function bmFillFormats(formats) {
        if (!Array.isArray(formats) || bmFormatsSet) return;
        bmFormatsSet = true;
        bmBox().setFormats(formats);
    }
    async function sendTest() {
        const r = await apiCall('admin/bulk_send', 'POST',
            { op: 'test', subject: $('bm-subject').value, body: bmBox().getValue(), format: bmFormat(), frame: bmFrame() });
        showToast((r && (r.message || r.error)) || t.key('js.users.failed'), r && r.success ? 'success' : 'error');
    }

    async function sendWrite() {
        const subject = $('bm-subject').value.trim();
        const body = bmBox().getValue().trim();
        if (!subject || !body) { showToast(t.key('js.users.write_need_subject_body'), 'error'); return; }
        const wantMail = $('bm-email').checked;
        const wantNotify = $('bm-notify').checked;
        if (!wantMail && !wantNotify) { showToast(t.key('js.users.write_need_channel'), 'error'); return; }
        const pre = await apiCall('admin/bulk_send', 'POST', { op: 'preview', audience: writeAudience() });
        if (!pre || !pre.success) { showToast((pre && pre.error) || t.key('js.users.write_audience_failed'), 'error'); return; }
        const what = [];
        if (wantNotify) what.push(t.key('js.users.write_count_notify', { n: pre.audience }));
        if (wantMail) what.push(t.key('js.users.write_count_email', { n: pre.recipients }));
        // The number again, at the moment of committing. This is the last point at which somebody can
        // notice that "everyone" is larger than they had pictured.
        if (!await confirmAction(t.key('js.users.write_send_head'),
            t.key('js.users.write_send_body', { what: what.join(t.key('js.users.write_and')) }),
            { okLabel: t.key('js.users.write_send_ok'), danger: true })) return;
        const pw = await askPassword(t.key('js.users.write_send_head'), t.key('js.users.write_send_password'));
        if (!pw) return;
        const r = await apiCall('admin/bulk_send', 'POST', {
            op: 'queue', password: pw, audience: writeAudience(),
            subject, body, format: bmFormat(), frame: bmFrame(), notify: wantNotify, email: wantMail });
        showToast((r && (r.message || r.error)) || t.key('js.users.failed'), r && r.success ? 'success' : 'error');
        if (r && r.success) { $('bm-subject').value = ''; bmBox().reset(); loadBatches(); }
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
    const SORT_DEBOUNCE_MS = window.AdminCommon.DEBOUNCE.sort;   // one number for every list — admin-common.js

    document.addEventListener('DOMContentLoaded', () => {
        const loadUsersDebounced = debounce(() => loadUsers(), SORT_DEBOUNCE_MS);
        usSort = makeSortStack({ table: $('us-table'), defaultSort: [{ col: 'created', dir: 'desc' }], onChange: () => { state.us.page = 1; loadUsersDebounced(); } });
        usSort.bindHeaders();
        document.querySelectorAll('#us-tabs .source-tab').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));
        $('us-search').addEventListener('input', debounce(() => { state.us.search = $('us-search').value.trim(); state.us.page = 1; loadUsers(); }, window.AdminCommon.DEBOUNCE.search));
        bindSearchClear($('us-search'), $('us-search-clear'), () => { state.us.search = ''; state.us.page = 1; loadUsers(); });
        $('us-filter-status').addEventListener('change', () => { state.us.status = $('us-filter-status').value; state.us.page = 1; loadUsers(); });
        $('us-filter-group').addEventListener('change', () => { state.us.group = $('us-filter-group').value; state.us.page = 1; loadUsers(); });
        $('ue-save').addEventListener('click', saveEdit);
        if ($('ue-remove-avatar')) $('ue-remove-avatar').addEventListener('click', () => ueRemoveMedia('avatar'));
        if ($('ue-remove-cover')) $('ue-remove-cover').addEventListener('click', () => ueRemoveMedia('cover'));
        if ($('ue-bio-clear')) $('ue-bio-clear').addEventListener('click', ueClearBio);
        // the Moderation block of the edit window (1.75.0)
        if ($('ue-mod-apply')) {
            $('ue-mod-apply').addEventListener('click', ueModApply);
            $('ue-mod-lift-mute').addEventListener('click', () => ueModLift('mute'));
            $('ue-mod-lift-ban').addEventListener('click', () => ueModLift('ban'));
            $('ue-mod-action').addEventListener('change', syncModMode);
        }
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
        // The composer's box (adminRichField()) is made now, so its format select and textarea exist for the first look.
        bmBox();
        // "Site frame" and the subject are part of the mail the preview shows (the frame, the document's title).
        $('bm-frame').addEventListener('change', () => bmBox().refresh());
        $('bm-subject').addEventListener('input', A.debounce(() => bmBox().refresh(), 600));
        // The label carries a count that depends on which channel is ticked, so it is refreshed when
        // that changes rather than only when the audience does.
        ['bm-email', 'bm-notify'].forEach(id => {
            const el2 = $(id);
            if (el2) el2.addEventListener('change', refreshPreview);
        });
        $('bm-test').addEventListener('click', sendTest);
        $('bm-send').addEventListener('click', sendWrite);
        $('ge-save').addEventListener('click', saveGroup);
        $('gr-rec-add').addEventListener('click', () => applyRecommended('add'));
        $('gr-rec-reset').addEventListener('click', () => applyRecommended('reset'));
        const logout = $('btn-logout');
        if (logout) logout.addEventListener('click', async () => { try { await apiCall('admin/logout', 'POST', {}); } catch (e) {} location.href = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '') + '?action=' + (document.body.dataset.loginPath || 'admin'); });
        const addBtn = $('us-add-btn');
        if (addBtn) addBtn.addEventListener('click', openAdd);
        const uaSave = $('ua-save');
        if (uaSave) uaSave.addEventListener('click', saveAdd);
        const uaGen = $('ua-gen');
        if (uaGen) uaGen.addEventListener('click', uaGenerate);
        const uaVerify = $('ua-verify');
        if (uaVerify) uaVerify.addEventListener('change', () => { uaHint(); uaValidate(); });
        ['ua-username', 'ua-email', 'ua-password'].forEach(id => {
            const f = $(id);
            if (f) { f.addEventListener('input', uaValidate); f.addEventListener('blur', uaValidate); }
        });
        // "Open user" on the Moderation page (1.75.0) arrives as #find=<name>: the list is searched for that name and, when it is
        // there, its edit window opens.
        let findName = '';
        const fm = /^#find=(.+)$/.exec(location.hash || '');
        if (fm) { try { findName = decodeURIComponent(fm[1]); } catch (e) { findName = ''; } }
        if (findName) { $('us-search').value = findName; state.us.search = findName; }
        loadGroups().then(loadUsers).then(() => {
            if (!findName) return;
            const hit = (state.us.rows || []).find(x => String(x.username).toLowerCase() === findName.toLowerCase());
            if (hit) openEdit(hit);
        });
    });
})();
