/**
 * Settings → Shoutbox: the one control on that card that is not a setting.
 *
 * Everything else in the section is an ordinary field saved with the form. "Purge" deletes rows,
 * and rows deleted here are not coming back from a settings backup — so it asks twice: once for the
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
 * Settings → Shoutbox → the emote manager (1.59.0).
 *
 * The five switches beside it are ordinary settings saved with the form. This is the other half:
 * the pictures themselves, which are rows rather than files, so adding and removing one is an API
 * call and not a save. Its own IIFE for that reason — the Purge button above has nothing to do with
 * it, and a page missing one of the two must still get the other.
 *
 * An upload travels as base64 inside JSON through AdminCommon.apiCall, the same road every other
 * panel call takes and with the same CSRF header; the server decides from the BYTES what it is and
 * refuses an SVG carrying a script, a handler or a reference to somebody else's server. Nothing
 * here validates the file — a check in a browser is a courtesy, not a boundary — except the size,
 * and that only to say no before sending a megabyte the server will refuse anyway.
 */
(function () {
    'use strict';
    const root = document.getElementById('admin-emotes');
    if (!root || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, el, showToast, confirmAction } = window.AdminCommon;
    const t = window.t;
    const list = document.getElementById('admin-emotes-list');
    const fileIn = document.getElementById('admin-emote-file');
    const codeIn = document.getElementById('admin-emote-code');
    const nameIn = document.getElementById('admin-emote-name');
    const stickIn = document.getElementById('admin-emote-sticker');
    const btn = document.getElementById('admin-emote-upload');
    const drop = document.getElementById('admin-emote-drop');
    const maxKb = Number(root.dataset.maxKb) || 64;

    /** "Thumbs Up!.png" -> "thumbs_up": what the code box is filled with when it is left empty. */
    function slug(name) {
        return String(name).replace(/\.[a-z0-9]+$/i, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 32);
    }

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
            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) { dropped = f; markFile(f); }
        });
        drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } });
        fileIn.addEventListener('change', () => { dropped = null; markFile(chosen()); });
    }

    async function op(body, okKey) {
        let j;
        try { j = await apiCall('admin/shout_emotes', 'POST', body); }
        catch (e) { showToast(t('js.shoutadmin.emote_failed'), 'danger'); return null; }
        if (!j.success) { showToast(j.error || t('js.shoutadmin.emote_failed'), 'danger'); return null; }
        render(j.emotes || []);
        showToast(j.message || t(okKey), 'success');
        return j;
    }

    function render(rows) {
        list.textContent = '';
        if (!rows.length) { list.appendChild(el('div', { className: 'text-muted small' }, t('js.shoutadmin.emote_none'))); return; }
        const table = el('table', { className: 'table table-dark table-sm align-middle mb-0 admin-emotes-table' });
        rows.forEach((r) => {
            const img = el('img', { src: r.url, alt: ':' + r.code + ':', className: 'admin-emote-preview' });
            img.style.maxWidth = '32px';
            img.style.maxHeight = '32px';
            if (!r.enabled) img.style.opacity = '0.35';

            const onoff = el('button', { type: 'button', className: 'btn btn-sm me-1 ' + (r.enabled ? 'btn-outline-secondary' : 'btn-outline-success') },
                             t(r.enabled ? 'js.shoutadmin.emote_disable' : 'js.shoutadmin.emote_enable'));
            onoff.addEventListener('click', () => op({ op: r.enabled ? 'disable' : 'enable', id: r.id }, 'js.shoutadmin.emote_saved'));

            const stick = el('button', { type: 'button', className: 'btn btn-sm me-1 ' + (r.sticker ? 'btn-info' : 'btn-outline-info'),
                                         title: t('js.shoutadmin.emote_sticker_hint') },
                             t(r.sticker ? 'js.shoutadmin.emote_sticker' : 'js.shoutadmin.emote_inline'));
            stick.addEventListener('click', () => op({ op: 'sticker', id: r.id, on: !r.sticker }, 'js.shoutadmin.emote_saved'));

            const del = el('button', { type: 'button', className: 'btn btn-outline-danger btn-sm' }, t('js.shoutadmin.emote_delete'));
            del.addEventListener('click', async () => {
                // `code` is the modal's own slot for an identifier the reader is meant to CHECK —
                // the token, not a sentence with the token buried in it.
                if (!(await confirmAction(t('js.shoutadmin.emote_delete_title'), t('js.shoutadmin.emote_delete_confirm'),
                                          { code: ':' + r.code + ':', danger: true }))) return;
                op({ op: 'delete', id: r.id }, 'js.shoutadmin.emote_deleted');
            });

            const size = (r.w && r.h ? r.w + '×' + r.h + ' · ' : '') + Math.max(1, Math.round(r.bytes / 1024)) + ' KB · ' + r.mime;
            table.appendChild(el('tr', {}, [
                el('td', { className: 'text-center' }, [img]),
                el('td', {}, [el('code', {}, ':' + r.code + ':'), el('div', { className: 'text-muted small' }, r.name)]),
                el('td', { className: 'text-muted small' }, [
                    document.createTextNode(size),
                    el('div', {}, r.uploader ? '@' + r.uploader : t('js.shoutadmin.emote_site')),
                ]),
                el('td', { className: 'text-end text-nowrap' }, [onoff, stick, del]),
            ]));
        });
        list.appendChild(table);
    }

    async function load() {
        let j;
        try { j = await apiCall('admin/shout_emotes'); } catch (e) { showToast(t('js.shoutadmin.emote_failed'), 'danger'); return; }
        if (!j.success) { showToast(j.error || t('js.shoutadmin.emote_failed'), 'danger'); return; }
        render(j.emotes || []);
    }

    if (btn) btn.addEventListener('click', () => {
        const f = chosen();
        if (!f) { showToast(t('js.shoutadmin.emote_pick_file'), 'warning'); return; }
        if (f.size > maxKb * 1024) { showToast(t('js.shoutadmin.emote_too_large', { kb: maxKb }), 'danger'); return; }
        const code = (codeIn && codeIn.value.trim().toLowerCase()) || slug(f.name);
        if (!/^[a-z0-9_]{2,32}$/.test(code)) { showToast(t('js.shoutadmin.emote_bad_code'), 'danger'); return; }
        const reader = new FileReader();
        reader.onload = async () => {
            btn.disabled = true;
            const j = await op({ op: 'upload', code: code, name: (nameIn && nameIn.value.trim()) || '',
                                 data: String(reader.result), sticker: !!(stickIn && stickIn.checked) },
                               'js.shoutadmin.emote_added');
            btn.disabled = false;
            if (!j) return;
            if (fileIn) fileIn.value = '';
            if (codeIn) codeIn.value = '';
            if (nameIn) nameIn.value = '';
            if (stickIn) stickIn.checked = false;
            dropped = null;
            markFile(null);
        };
        reader.onerror = () => showToast(t('js.shoutadmin.emote_failed'), 'danger');
        reader.readAsDataURL(f);
    });

    load();
})();
