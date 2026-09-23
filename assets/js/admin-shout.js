/**
 * Settings → Shoutbox: the one control on that card that is not a setting.
 *
 * Everything else in the section is an ordinary field saved with the form. "Purge" deletes rows, and
 * rows deleted here are not coming back from a settings backup — so it asks twice: once for the
 * decision (confirmAction, with the number of days spelled out) and once for the owner's password,
 * which is what api/admin/shout_purge.php checks with requireAdminReauth().
 *
 * The day box has an id and no name on purpose: it is an argument to this button, never a setting,
 * and a `name` would send it along with every save of the page.
 */
(function () {
    'use strict';
    const root = document.getElementById('admin-shout');
    if (!root || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, showToast, confirmAction, promptPassword } = window.AdminCommon;
    const t = window.t;
    const daysIn = document.getElementById('shout-purge-days');
    const btn = document.getElementById('shout-purge-run');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const raw = (daysIn && daysIn.value || '').trim();
        // Empty means EVERYTHING, and that is the reading the confirmation has to say out loud —
        // an empty number box is exactly how somebody wipes a room by accident.
        const days = raw === '' ? null : Math.max(0, Math.min(3650, parseInt(raw, 10) || 0));
        const title = t('js.shoutadmin.purge_title');
        const what = days === null ? t('js.shoutadmin.purge_all') : t('js.shoutadmin.purge_older', { days: days });
        if (!await confirmAction(title, what, { okLabel: t('js.shoutadmin.purge_ok'), danger: true })) return;
        const pw = await promptPassword(title, t('js.shoutadmin.purge_password'));
        if (!pw) return;
        btn.disabled = true;
        try {
            const r = await apiCall('admin/shout_purge', 'POST', { password: pw, older_than_days: days });
            showToast(r.success ? (r.message || t('js.shoutadmin.purge_done', { n: r.deleted || 0 }))
                                : (r.error || t('js.shoutadmin.purge_failed')), r.success ? 'success' : 'error');
            if (r.success && daysIn) daysIn.value = '';
        } catch {
            showToast(t('js.shoutadmin.purge_failed'), 'error');
        } finally {
            btn.disabled = false;
        }
    });
})();

/**
 * Settings → Shoutbox → the emote manager (1.59.0, rebuilt in 1.59.1).
 *
 * The switches beside it are ordinary settings saved with the form. This is the other half: the
 * pictures themselves, which are rows rather than files, so adding and removing one is an API call
 * and not a save. Its own IIFE for that reason — the Purge button above has nothing to do with it,
 * and a page missing one of the two must still get the other.
 *
 * ── what 1.59.1 changed, and why ───────────────────────────────────────────────────────────────
 *
 * It was a LIST: a preview far to the left, a wide gap, the code, some meta, then three buttons
 * against the right edge, and the buttons were named after what the row currently IS (`Switch off`,
 * `Inline`) rather than after what pressing them does. A table with headings answers the questions
 * in the order somebody asks them — what is it, what is it called, how big, whose, since when — and
 * the row says what it is in two chips so the buttons are free to say what they do.
 *
 * ── the queue ──────────────────────────────────────────────────────────────────────────────────
 *
 * With `shout_emote_approval` on, a MEMBER's upload lands switched off and nobody else sees it
 * anywhere. Those rows are lifted out of the table into a panel above it, because a queue somebody
 * is waiting on is not a row in the middle of thirty others. Approving is the `enable` op — it is
 * the same act — and the endpoint writes the audit line.
 *
 * An upload travels as base64 inside JSON through AdminCommon.apiCall, the same road every other
 * panel call takes and with the same CSRF header; the server decides from the BYTES what it is and
 * refuses an SVG carrying a script, a handler or a reference to somebody else's server. Nothing here
 * validates the file — a check in a browser is a courtesy, not a boundary — except the size and the
 * two name rules, and those only to say no before sending something the server will refuse anyway.
 */
(function () {
    'use strict';
    const root = document.getElementById('admin-emotes');
    if (!root || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, el, showToast, confirmAction, promptModal, fmtDate } = window.AdminCommon;
    const t = window.t;
    const list = document.getElementById('admin-emotes-list');
    const waitBox = document.getElementById('admin-emotes-waiting');
    const countEl = document.getElementById('admin-emotes-count');
    const fileIn = document.getElementById('admin-emote-file');
    const codeIn = document.getElementById('admin-emote-code');
    const nameIn = document.getElementById('admin-emote-name');
    const stickIn = document.getElementById('admin-emote-sticker');
    const btn = document.getElementById('admin-emote-upload');
    const drop = document.getElementById('admin-emote-drop');
    const maxKb = Number(root.dataset.maxKb) || 64;
    const addLabel = btn ? btn.textContent.trim() : '';

    // What the sniffed type is CALLED. "image/svg+xml" is the honest answer to a question nobody asked.
    const TYPES = { 'image/svg+xml': 'SVG', 'image/png': 'PNG', 'image/gif': 'GIF', 'image/webp': 'WebP' };
    const NAME_MIN = 2;

    let rows = [];                                     // as the server last listed them
    let busy = false;

    /** "Thumbs Up!.png" -> "thumbs_up": what the code box is filled with when it is left empty. */
    function slug(name) {
        return String(name).replace(/\.[a-z0-9]+$/i, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 32);
    }

    /**
     * "thumbs_up" -> "Thumbs up". The same fallback shoutEmotePrettyName() applies on the server, so
     * the name this side checks for a collision is the name that would actually be stored.
     */
    function prettyFromCode(code) {
        const s = String(code || '').replace(/[-_]+/g, ' ').trim();
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    }

    // ── names ────────────────────────────────────────────────────────────────
    const key = (s) => String(s || '').trim().replace(/\s+/g, ' ').toLowerCase();

    /** Is another emote already answering to this name? Switched-off and waiting ones count. */
    function nameTaken(name, exceptId) {
        const k = key(name);
        if (!k) return false;
        return rows.some((r) => r.id !== exceptId && key(r.name) === k);
    }
    /** The two rules the owner can break without leaving the page. includes/shout.php checks them again. */
    function checkName(name, exceptId) {
        if (name.length < NAME_MIN) { showToast(t('js.shoutadmin.emote_name_short'), 'warning'); return false; }
        if (nameTaken(name, exceptId)) { showToast(t('js.shoutadmin.emote_name_taken', { name: name }), 'warning'); return false; }
        return true;
    }

    // ── the drop zone ────────────────────────────────────────────────────────
    /** The box says which file it holds — the same zone Settings → Sounds uses. */
    function markFile(file) {
        if (!drop) return;
        drop.classList.toggle('has-file', !!file);
        const main = drop.querySelector('.ipl-drop-main');
        if (!main) return;
        main.textContent = '';
        if (file) main.appendChild(document.createTextNode(file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB'));
        else {
            main.appendChild(el('u', { text: t('js.shoutadmin.emote_drop_choose') }));
            main.appendChild(document.createTextNode(' ' + t('js.shoutadmin.emote_drop_or')));
        }
        if (file && codeIn && !codeIn.value.trim()) codeIn.value = slug(file.name);
    }
    let dropped = null;          // a file that came by drag rather than through the dialog
    function chosen() { return dropped || (fileIn && fileIn.files && fileIn.files[0]) || null; }
    if (drop && fileIn) {
        // dragover must be cancelled or the browser navigates to the file, losing the page.
        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('dragging'); }));
        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('dragging')));
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            if (busy) return;
            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) { dropped = f; markFile(f); }
        });
        drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } });
        fileIn.addEventListener('change', () => { dropped = null; markFile(chosen()); });
    }

    // ── one call, one repaint ────────────────────────────────────────────────
    async function op(body, okKey) {
        let j;
        try { j = await apiCall('admin/shout_emotes', 'POST', body); }
        catch (e) { showToast(t('js.shoutadmin.emote_failed'), 'danger'); return null; }
        if (!j.success) { showToast(j.error || t('js.shoutadmin.emote_failed'), 'danger'); return null; }
        render(j.emotes || []);
        showToast(j.message || t(okKey), 'success');
        return j;
    }

    // ── the pieces a row is made of ──────────────────────────────────────────
    function preview(r) {
        const img = el('img', { src: r.url, alt: ':' + r.code + ':', title: ':' + r.code + ':', className: 'admin-emote-preview' });
        return img;
    }
    function chip(text, kind) {
        return el('span', { className: 'admin-emote-chip admin-emote-chip-' + kind }, text);
    }
    /** The code somebody types, and under it the name they see when they point at it. */
    function identity(r) {
        return el('td', {}, [
            el('code', { className: 'admin-emote-code' }, ':' + r.code + ':'),
            el('div', { className: 'admin-emote-name' }, r.name),
        ]);
    }
    /** Type · size · dimensions, in that order: what it is, then how heavy, then how big on screen. */
    function facts(r) {
        const bits = [TYPES[r.mime] || r.mime, Math.max(1, Math.round(r.bytes / 1024)) + ' KB',
                      (r.w && r.h) ? r.w + '×' + r.h : null];
        return el('td', { className: 'text-muted small text-nowrap' }, bits.filter(Boolean).join(' · '));
    }
    /** Who put it there: a link to the profile, or the site for one that belongs to nobody. */
    function who(r) {
        if (!r.uploader) return el('td', { className: 'text-muted small' }, t('js.shoutadmin.emote_site'));
        const base = document.body.dataset.apiBase || '';
        // apiBase is "<base>api.php?endpoint=" — the site root is everything before the file name.
        const site = base.replace(/api\.php.*$/, '');
        // The uploader's picture first inside the link (1.63.0), from the ADDRESS the server built —
        // assets/js/avatar.js, which answers null while pictures are switched off.
        const pic = typeof window.userAvatarImg === 'function'
            ? window.userAvatarImg({ username: r.uploader, avatar: String(r.uploader_avatar || '') }, 20, 'avatar emote-av-admin') : null;
        return el('td', { className: 'small' }, [
            el('a', { className: 'av-who', href: site + '?action=u&name=' + encodeURIComponent(r.uploader), target: '_blank', rel: 'noopener' }, [pic, '@' + r.uploader]),
        ]);
    }

    function delButton(r) {
        const del = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger' }, t('js.shoutadmin.emote_delete'));
        del.addEventListener('click', async () => {
            // `code` is the modal's own slot for an identifier the reader is meant to CHECK —
            // the token, not a sentence with the token buried in it.
            if (!(await confirmAction(t('js.shoutadmin.emote_delete_title'), t('js.shoutadmin.emote_delete_confirm'),
                                      { code: ':' + r.code + ':', danger: true }))) return;
            op({ op: 'delete', id: r.id }, 'js.shoutadmin.emote_deleted');
        });
        return del;
    }

    // ── the queue: a member's upload nobody else can see yet ─────────────────
    function waitingRow(r) {
        const yes = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success me-1' }, t('js.shoutadmin.emote_approve'));
        yes.addEventListener('click', () => op({ op: 'enable', id: r.id }, 'js.shoutadmin.emote_approved'));
        return el('tr', {}, [
            el('td', { className: 'admin-emote-shot' }, [preview(r)]),
            identity(r),
            facts(r),
            who(r),
            el('td', { className: 'text-muted small text-nowrap' }, fmtDate(r.created_at)),
            el('td', { className: 'text-end text-nowrap' }, [el('div', { className: 'd-inline-flex gap-1' }, [yes, delButton(r)])]),
        ]);
    }

    function paintWaiting(pending) {
        if (!waitBox) return;
        waitBox.textContent = '';
        waitBox.hidden = pending.length === 0;
        if (!pending.length) return;
        waitBox.appendChild(el('h6', { className: 'admin-emotes-waiting-title' },
            [el('i', { className: 'bi bi-hourglass-split' }), t('js.shoutadmin.emote_waiting_head', { n: pending.length })]));
        waitBox.appendChild(el('small', { className: 'admin-emotes-waiting-hint' }, t('js.shoutadmin.emote_waiting_hint')));
        const table = el('table', { className: 'table table-dark table-sm align-middle mb-0 admin-emotes-table' });
        const body = el('tbody');
        pending.forEach((r) => body.appendChild(waitingRow(r)));
        table.appendChild(body);
        waitBox.appendChild(table);
    }

    // ── the table ────────────────────────────────────────────────────────────
    function rowFor(r) {
        const onoff = el('button', { type: 'button', className: 'btn btn-sm me-1 ' + (r.enabled ? 'btn-outline-secondary' : 'btn-outline-success') },
                         t(r.enabled ? 'js.shoutadmin.emote_disable' : 'js.shoutadmin.emote_enable'));
        onoff.addEventListener('click', () => op({ op: r.enabled ? 'disable' : 'enable', id: r.id }, 'js.shoutadmin.emote_saved'));

        const stick = el('button', { type: 'button', className: 'btn btn-sm btn-outline-info me-1', title: t('js.shoutadmin.emote_sticker_hint') },
                         t(r.sticker ? 'js.shoutadmin.emote_make_emote' : 'js.shoutadmin.emote_make_sticker'));
        stick.addEventListener('click', () => op({ op: 'sticker', id: r.id, on: !r.sticker }, 'js.shoutadmin.emote_saved'));

        const ren = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary me-1' }, t('js.shoutadmin.emote_rename'));
        ren.addEventListener('click', () => rename(r));

        // What the row IS. The buttons above say what they will DO to it, so this is the only place
        // that has to answer "is this one live, and is it big or inline".
        const state = el('td', {}, [
            chip(t(r.enabled ? 'js.shoutadmin.emote_state_on' : 'js.shoutadmin.emote_state_off'), r.enabled ? 'on' : 'off'),
            chip(t(r.sticker ? 'js.shoutadmin.emote_kind_sticker' : 'js.shoutadmin.emote_kind_emote'), 'kind'),
        ]);

        return el('tr', { className: r.enabled ? '' : 'admin-emote-off' }, [
            el('td', { className: 'admin-emote-shot' }, [preview(r)]),
            identity(r),
            state,
            facts(r),
            who(r),
            el('td', { className: 'text-muted small text-nowrap' }, fmtDate(r.created_at)),
            el('td', { className: 'text-end text-nowrap' }, [el('div', { className: 'd-inline-flex gap-1' }, [onoff, stick, ren, delButton(r)])]),
        ]);
    }

    function paintCount(shown) {
        if (!countEl) return;
        countEl.hidden = shown.length === 0;
        countEl.textContent = t('js.shoutadmin.count', { n: shown.filter((r) => r.enabled).length, max: shown.length });
    }

    function render(listing) {
        rows = Array.isArray(listing) ? listing : [];
        // Waiting rows are lifted OUT of the table: they are the same feature as the rest, but they
        // are a question somebody asked and the others are not. The question belongs to the ROW: a
        // moderator switching an approved emote off must not put it back here, and an operator
        // switching the gate off must not bury an upload nobody has answered for yet.
        const pending = rows.filter((r) => r.pending);
        const rest = rows.filter((r) => pending.indexOf(r) < 0);
        paintWaiting(pending);
        list.textContent = '';
        paintCount(rest);
        if (!rest.length) { list.appendChild(el('div', { className: 'text-muted small' }, t('js.shoutadmin.emote_none'))); return; }
        const table = el('table', { className: 'table table-dark table-sm align-middle mb-0 admin-emotes-table' }, [
            el('thead', {}, [el('tr', {}, [
                el('th', { scope: 'col' }, [el('span', { className: 'visually-hidden' }, t('js.shoutadmin.col_emote'))]),
                el('th', { scope: 'col' }, t('js.shoutadmin.col_code')),
                el('th', { scope: 'col' }, t('js.shoutadmin.col_state')),
                el('th', { scope: 'col' }, t('js.shoutadmin.col_file')),
                el('th', { scope: 'col' }, t('js.shoutadmin.col_who')),
                el('th', { scope: 'col' }, t('js.shoutadmin.col_added')),
                el('th', { scope: 'col', className: 'text-end' }, [el('span', { className: 'visually-hidden' }, t('js.shoutadmin.col_actions'))]),
            ])]),
        ]);
        const body = el('tbody');
        rest.forEach((r) => body.appendChild(rowFor(r)));
        table.appendChild(body);
        list.appendChild(table);
    }

    // ── what the buttons do ──────────────────────────────────────────────────
    /**
     * Rename. Only the display text changes: the CODE is what people type into a sentence, and a
     * code that moved would break every line that already says it — which is why the modal does not
     * offer one.
     */
    async function rename(r) {
        const v = await promptModal({ title: t('js.shoutadmin.emote_rename_title'), label: t('js.shoutadmin.emote_rename_label'),
                                      value: r.name, maxlength: 60, hint: t('js.shoutadmin.emote_rename_hint'),
                                      okLabel: t('js.shoutadmin.emote_rename') });
        if (v === null) return;
        const name = String(v).trim();
        if (name === r.name) return;
        // Empty is allowed through: the server reads it as "back to the prettified code", and it
        // refuses that too if the fallback is already somebody else's name.
        if (name !== '' && !checkName(name, r.id)) return;
        op({ op: 'rename', id: r.id, name: name }, 'js.shoutadmin.emote_renamed');
    }

    /** The Add button and the name box both lead here, and neither runs twice over one upload. */
    function setBusy(on) {
        busy = on;
        if (!btn) return;
        btn.disabled = on;
        [codeIn, nameIn, fileIn].forEach((n) => { if (n) n.disabled = on; });
        btn.textContent = '';
        btn.appendChild(el('i', { className: on ? 'bi bi-hourglass-split' : 'bi bi-plus-lg' }));
        btn.appendChild(document.createTextNode(' ' + (on ? t('js.shoutadmin.emote_adding') : addLabel)));
    }
    const readFile = (f) => new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error || new Error('read'));
        reader.readAsDataURL(f);
    });

    async function add() {
        if (busy) return;
        const f = chosen();
        if (!f) { showToast(t('js.shoutadmin.emote_pick_file'), 'warning'); if (drop) drop.focus(); return; }
        if (f.size > maxKb * 1024) { showToast(t('js.shoutadmin.emote_too_large', { kb: maxKb }), 'danger'); return; }
        const code = (codeIn && codeIn.value.trim().toLowerCase()) || slug(f.name);
        if (!/^[a-z0-9_]{2,32}$/.test(code)) { showToast(t('js.shoutadmin.emote_bad_code'), 'danger'); if (codeIn) codeIn.focus(); return; }
        // An empty name box means the prettified code, here and on the server — so the collision is
        // checked against the name that would actually be stored rather than against nothing.
        const typed = (nameIn && nameIn.value.trim()) || '';
        if (!checkName(typed || prettyFromCode(code), 0)) { if (nameIn) { nameIn.focus(); nameIn.select(); } return; }
        setBusy(true);
        let data, j;
        try { data = await readFile(f); }
        catch (e) { setBusy(false); showToast(t('js.shoutadmin.emote_failed'), 'danger'); return; }
        j = await op({ op: 'upload', code: code, name: typed, data: data, sticker: !!(stickIn && stickIn.checked) },
                     'js.shoutadmin.emote_added');
        setBusy(false);
        if (!j) return;
        if (fileIn) fileIn.value = '';
        if (codeIn) codeIn.value = '';
        if (nameIn) nameIn.value = '';
        if (stickIn) stickIn.checked = false;
        dropped = null;
        markFile(null);
        if (drop) drop.focus();          // ready for the next one, without reaching for the mouse
    }

    if (btn) btn.addEventListener('click', add);
    // Both boxes live inside the settings <form>: Enter here would save the whole page instead.
    [codeIn, nameIn].forEach((n) => {
        if (n) n.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } });
    });

    async function load() {
        let j;
        try { j = await apiCall('admin/shout_emotes'); } catch (e) { showToast(t('js.shoutadmin.emote_failed'), 'danger'); return; }
        if (!j.success) { showToast(j.error || t('js.shoutadmin.emote_failed'), 'danger'); return; }
        render(j.emotes || []);
    }

    load();
})();

/**
 * Settings → Shoutbox → who may read and who may write (1.60.0).
 *
 * The SAME read-only matrix Users → Groups draws (renderMatrix in assets/js/admin-users.js), scoped
 * to the five `shout.*` ids and fed by the same endpoint — no new payload, and no second idea of
 * what a permission is. It is here because this is where somebody is standing when the question
 * comes up: they have just switched the room on and want to know who can actually use it.
 *
 * READ-ONLY on purpose. Granting is one page away and belongs where every other grant is made; a
 * second editor for the same rows would be a second thing to keep honest, and the answer to "why
 * can this person not write?" is a thing to SEE, not a thing to fix from here.
 *
 * Its own IIFE, like the two above: a page that is missing one of the three must still get the
 * others, and this one is the only one that does nothing at all until it is opened.
 */
(function () {
    'use strict';
    const wrap = document.getElementById('shout-matrix-wrap');
    const tbl = document.getElementById('shout-matrix');
    if (!wrap || !tbl || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, el } = window.AdminCommon;
    const t = window.t;
    // Written out rather than filtered on the `shout.` prefix, so a permission added to the registry
    // later shows up here because somebody decided it should and not because it was named alike.
    // 1.66.0: correcting your own line and editing anybody's, beside the delete each one mirrors.
    const IDS = ['shout.view', 'shout.post', 'shout.edit_own', 'shout.delete_own', 'shout.edit_any', 'shout.moderate',
                 'shout.upload_emote', 'shout.emote_auto'];
    let asked = false;

    function render(groups, permList) {
        tbl.textContent = '';
        // Only the ids the registry actually knows: a row for a permission nothing can grant would
        // be a column of dots that never changes.
        const keys = IDS.filter((k) => permList[k] !== undefined);
        if (!groups.length || !keys.length) return;
        const hr = el('tr', {}, [el('th', { text: t('js.shoutadmin.matrix_permission') })]);
        groups.forEach((g) => {
            const th = el('th', { className: 'gr-matrix-g', title: g.slug }, [g.name]);
            if (g.color && /^#[0-9a-fA-F]{3,8}$/.test(g.color)) th.style.color = g.color;
            hr.appendChild(th);
        });
        tbl.appendChild(el('thead', {}, [hr]));
        const tbody = el('tbody', {});
        keys.forEach((key) => {
            const tr = el('tr', {}, [el('td', { title: permList[key] || '' }, [el('code', { text: key })])]);
            groups.forEach((g) => {
                const on = !!(g.permissions && g.permissions[key]);
                tr.appendChild(el('td', { className: 'gr-matrix-c' + (on ? ' on' : '') }, [
                    on ? el('i', { className: 'bi bi-check-lg', title: t('js.shoutadmin.matrix_has', { group: g.name, key: key }) })
                       : el('span', { className: 'gr-matrix-off', text: '·' })]));
            });
            tbody.appendChild(tr);
        });
        tbl.appendChild(tbody);
    }

    // Fetched when the fold is opened and not before: Settings already makes a dozen calls on
    // arrival, and this one answers a question most visits to the page never ask. `asked` is put
    // back on a failure so closing and opening it again is a retry rather than a dead box.
    wrap.addEventListener('toggle', async () => {
        if (!wrap.open || asked) return;
        asked = true;
        let j;
        try { j = await apiCall('admin/fetch_groups'); } catch (e) { asked = false; return; }
        if (!j || j.error) { asked = false; return; }
        render(j.groups || [], j.permission_list || {});
    });
})();
