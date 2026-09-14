/**
 * Settings → Sounds: the owner's uploads and a preview button beside each site-default select.
 *
 * The uploads are a table, one row per sound, and the last column before the buttons answers the
 * question the owner actually has in front of a list of sounds — "is anything using this?" — by
 * reading the selects on this very page rather than the saved settings, so it keeps telling the
 * truth while a change is still unsaved. Deleting a sound therefore knows what it is about to
 * clear and says so before it does.
 *
 * The selects are two <optgroup>s, drawn by templates/admin/settings.php: what ships with the
 * tracker, and what the owner added. This file only keeps the second group in step — a new sound
 * goes in by name, a renamed one moves to where its new name belongs, a deleted one takes the
 * group with it when it was the last.
 *
 * An upload travels as base64 inside JSON through AdminCommon.apiCall — the same road as every
 * other panel call, with the same CSRF header — and the server decides from the bytes what it is
 * and whether the name is free. Both rules are checked here first: refusing a name after reading
 * 512 KB and encoding it is a slow way to say something that was knowable at once.
 *
 * `js.sndadmin.*` rather than `js.sounds.*` for what is new here: that prefix is in LANG_JS_PUBLIC
 * and rides along on every public page, and none of this is read outside the panel.
 */
(function () {
    'use strict';
    const root = document.getElementById('admin-sounds');
    if (!root || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, el, showToast, confirmAction, promptModal, fmtDate } = window.AdminCommon;
    const t = window.t;
    const list = document.getElementById('admin-sounds-list');
    const countEl = document.getElementById('admin-sounds-count');
    const fileIn = document.getElementById('admin-sound-file');
    const nameIn = document.getElementById('admin-sound-name');
    const btn = document.getElementById('admin-sound-upload');
    const drop = document.getElementById('admin-sound-drop');
    const maxBytes = Number(root.dataset.maxBytes) || 524288;
    const addLabel = btn.textContent.trim();
    let maxCount = Number(root.dataset.maxCount) || 40;

    // What the sniffed type is CALLED. "audio/mpeg" is the honest answer to a question nobody asked.
    const TYPES = { 'audio/mpeg': 'MP3', 'audio/ogg': 'Ogg', 'audio/wav': 'WAV' };
    const NAME_MIN = 2;
    const selects = () => Array.from(document.querySelectorAll('select.js-sound-select'));

    let rows = [];            // the uploads, as the server last listed them
    let builtins = [];        // the shipped clips: their names are taken too
    const urls = {};          // sid -> url, for every preview button on the page
    let busy = false;

    // ── the drop zone ────────────────────────────────────────────────────────
    /** The box says which file it holds — the same zone the language install uses. */
    function markFile(file) {
        if (!drop) return;
        drop.classList.toggle('has-file', !!file);
        const main = drop.querySelector('.ipl-drop-main');
        if (!main) return;
        main.textContent = '';
        if (file) main.appendChild(document.createTextNode(file.name + ' · ' + Math.round(file.size / 1024) + ' KB'));
        else { main.appendChild(el('u', { text: t('js.sounds.drop_choose') })); main.appendChild(document.createTextNode(' ' + t('js.sounds.drop_or'))); }
    }
    let dropped = null;          // a file that came by drag rather than through the dialog
    function chosen() { return dropped || (fileIn.files && fileIn.files[0]) || null; }

    /**
     * "email-notification-48213.mp3" → "Email notification". The name is optional and this is what
     * it falls back to: the extension off, a download's trailing id off, the separators a file name
     * is forced to use turned back into spaces.
     */
    function prettyFromFile(fileName) {
        let s = String(fileName || '').replace(/\.[a-z0-9]+$/i, '');
        s = s.replace(/[-_]\d{3,}$/, '');
        s = s.replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim();
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    }

    /** A file is picked: name the box, and offer the name it suggests while the box is still empty. */
    function tookFile(f) {
        markFile(f);
        if (f && !nameIn.value.trim()) nameIn.value = prettyFromFile(f.name);
    }
    if (drop) {
        // dragover must be cancelled or the browser navigates to the file, losing the page.
        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('dragging'); }));
        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('dragging')));
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            if (busy) return;
            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) { dropped = f; tookFile(f); }
        });
        drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } });
        fileIn.addEventListener('change', () => { dropped = null; tookFile(chosen()); });
    }

    // ── playing one ──────────────────────────────────────────────────────────
    let player = null;
    function preview(url) {
        if (!url) { showToast(t('js.sounds.nothing_to_play'), 'info'); return; }
        try { if (player) player.pause(); } catch (e) { /* ignore */ }
        player = new Audio(url);
        player.play().catch(() => showToast(t('js.sounds.preview_failed'), 'danger'));
    }

    // ── names ────────────────────────────────────────────────────────────────
    const key = (s) => String(s || '').trim().replace(/\s+/g, ' ').toLowerCase();

    /** Is the library already answering to this name? The shipped clips count; so does every upload. */
    function nameTaken(name, exceptId) {
        const k = key(name);
        if (!k) return false;
        return builtins.some((b) => key(b.name) === k) || rows.some((r) => r.id !== exceptId && key(r.name) === k);
    }
    /** The two rules the owner can break without leaving the page. includes/sounds.php checks them again. */
    function checkName(name, exceptId) {
        if (name.length < NAME_MIN) { showToast(t('js.sndadmin.name_short'), 'warning'); return false; }
        if (nameTaken(name, exceptId)) { showToast(t('js.sndadmin.name_taken', { name: name }), 'warning'); return false; }
        return true;
    }

    // ── the options in the selects ───────────────────────────────────────────
    /** The "Your own" group, made on the spot when the first upload needs somewhere to go. */
    function ownGroup(sel) {
        let g = sel.querySelector('optgroup[data-sound-own]');
        if (!g) { g = el('optgroup', { label: t('js.sndadmin.group_own'), 'data-sound-own': true }); sel.appendChild(g); }
        return g;
    }
    /** An option into (or back into) its place: inside "Your own", by name. */
    function placeOption(sel, opt) {
        const g = ownGroup(sel);
        const before = Array.from(g.children).find((o) => o !== opt && o.textContent.localeCompare(opt.textContent, undefined, { sensitivity: 'base' }) > 0);
        g.insertBefore(opt, before || null);
    }
    const optionFor = (sel, sid) => sel.querySelector('optgroup[data-sound-own] > option[value="' + sid + '"]');

    function addOption(sid, name) {
        selects().forEach((s) => placeOption(s, el('option', { value: sid }, name)));
    }
    function retitleOption(sid, name) {
        selects().forEach((s) => {
            const o = optionFor(s, sid);
            if (!o) return;
            o.textContent = name;
            placeOption(s, o);          // its new name may belong somewhere else in the group
        });
    }
    function dropOption(sid) {
        selects().forEach((s) => {
            const o = optionFor(s, sid);
            if (!o) return;
            const g = o.parentElement;
            if (s.value === sid) s.value = '';
            o.remove();
            if (g && g.tagName === 'OPTGROUP' && !g.children.length) g.remove();
        });
        paintUsed();
    }

    // ── the table ────────────────────────────────────────────────────────────
    /** Which site defaults on this page point at this sound RIGHT NOW, saved or not. */
    function usedBy(sid) {
        return selects().filter((s) => s.value === sid).map((s) => s.dataset.sndLabel || s.name);
    }
    function paintUsed() {
        Array.from(list.querySelectorAll('[data-used-for]')).forEach((cell) => {
            const names = usedBy(cell.dataset.usedFor);
            cell.textContent = '';
            if (!names.length) { cell.appendChild(el('span', { className: 'text-muted' }, '—')); return; }
            names.forEach((nm) => cell.appendChild(el('span', { className: 'admin-sound-used' }, nm)));
        });
    }
    function paintCount() {
        if (!countEl) return;
        countEl.hidden = false;
        countEl.textContent = t('js.sndadmin.count', { n: rows.length, max: maxCount });
        countEl.classList.toggle('is-full', rows.length >= maxCount);
    }

    function rowFor(r) {
        const play = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', title: t('js.sounds.play'), 'aria-label': t('js.sounds.play') },
                        [el('i', { className: 'bi bi-play-fill' })]);
        play.addEventListener('click', () => preview(r.url));
        const ren = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', title: t('js.sndadmin.rename'), 'aria-label': t('js.sndadmin.rename') },
                       [el('i', { className: 'bi bi-pencil' })]);
        ren.addEventListener('click', () => rename(r));
        const del = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', title: t('js.sounds.delete'), 'aria-label': t('js.sounds.delete') },
                       [el('i', { className: 'bi bi-trash' })]);
        del.addEventListener('click', () => remove(r));
        const facts = [TYPES[r.mime] || r.mime, Math.round(r.bytes / 1024) + ' KB', r.ms ? (r.ms / 1000).toFixed(1) + ' s' : null];
        return el('tr', {}, [
            el('td', { className: 'admin-sound-cell-name' }, r.name),
            el('td', { className: 'text-muted small text-nowrap' }, facts.filter(Boolean).join(' · ')),
            el('td', { className: 'text-muted small text-nowrap' }, fmtDate(r.created_at)),
            el('td', { className: 'small admin-sound-cell-used', dataset: { usedFor: r.sid } }),
            el('td', { className: 'text-end text-nowrap' }, [el('div', { className: 'd-inline-flex gap-1' }, [play, ren, del])]),
        ]);
    }

    function render(listing) {
        rows = Array.isArray(listing) ? listing : [];
        list.textContent = '';
        paintCount();
        if (!rows.length) { list.appendChild(el('div', { className: 'text-muted small' }, t('js.sounds.no_uploads'))); return; }
        const table = el('table', { className: 'table table-dark table-sm align-middle mb-0 admin-sounds-table' }, [
            el('thead', {}, [el('tr', {}, [
                el('th', { scope: 'col' }, t('js.sndadmin.col_name')),
                el('th', { scope: 'col' }, t('js.sndadmin.col_file')),
                el('th', { scope: 'col' }, t('js.sndadmin.col_added')),
                el('th', { scope: 'col' }, t('js.sndadmin.col_used')),
                el('th', { scope: 'col', className: 'text-end' }, [el('span', { className: 'visually-hidden' }, t('js.sndadmin.col_actions'))]),
            ])]),
        ]);
        const body = el('tbody');
        rows.forEach((r) => { urls[r.sid] = r.url; body.appendChild(rowFor(r)); });
        table.appendChild(body);
        list.appendChild(table);
        paintUsed();
    }

    // ── what the buttons do ──────────────────────────────────────────────────
    async function rename(r) {
        const v = await promptModal({ title: t('js.sndadmin.rename_title'), label: t('js.sndadmin.rename_label'), value: r.name,
                                      maxlength: 60, hint: t('js.sndadmin.rename_hint'), okLabel: t('js.sndadmin.rename') });
        if (v === null) return;
        const name = String(v).trim();
        if (name === r.name) return;
        if (!checkName(name, r.id)) return;
        let j;
        try { j = await apiCall('admin/sounds', 'POST', { op: 'rename', id: r.id, name: name }); }
        catch (e) { showToast(t('js.sounds.failed'), 'danger'); return; }
        if (!j.success) { showToast(j.error || t('js.sounds.failed'), 'danger'); return; }
        retitleOption(j.renamed.sid, j.renamed.name);
        render(j.sounds);
        showToast(j.message || 'OK', 'success');
    }

    async function remove(r) {
        const used = usedBy(r.sid);
        const opts = used.length ? { after: t('js.sndadmin.delete_clears', { what: used.join(', ') }) } : {};
        if (!(await confirmAction(t('js.sounds.delete'), t('js.sounds.delete_confirm', { name: r.name }), opts))) return;
        let j;
        try { j = await apiCall('admin/sounds', 'POST', { op: 'delete', id: r.id }); }
        catch (e) { showToast(t('js.sounds.failed'), 'danger'); return; }
        if (!j.success) { showToast(j.error || t('js.sounds.failed'), 'danger'); return; }
        dropOption(r.sid);
        delete urls[r.sid];
        render(j.sounds);
        showToast(j.message || 'OK', 'success');
    }

    /** The Add button and the name box both lead here, and neither runs twice over one upload. */
    function setBusy(on) {
        busy = on;
        btn.disabled = on;
        nameIn.disabled = on;
        if (fileIn) fileIn.disabled = on;
        btn.textContent = '';
        btn.appendChild(el('i', { className: on ? 'bi bi-hourglass-split' : 'bi bi-plus-lg' }));
        btn.appendChild(document.createTextNode(' ' + (on ? t('js.sndadmin.adding') : addLabel)));
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
        if (!f) { showToast(t('js.sounds.pick_file'), 'warning'); if (drop) drop.focus(); return; }
        if (f.size > maxBytes) { showToast(t('js.sounds.too_large', { kb: Math.round(maxBytes / 1024) }), 'danger'); return; }
        const name = nameIn.value.trim() || prettyFromFile(f.name);
        if (!checkName(name, 0)) { nameIn.value = name; nameIn.focus(); nameIn.select(); return; }
        setBusy(true);
        let data, j;
        try { data = await readFile(f); }
        catch (e) { setBusy(false); showToast(t('js.sounds.failed'), 'danger'); return; }
        try { j = await apiCall('admin/sounds', 'POST', { op: 'upload', name: name, data: data }); }
        catch (e) { setBusy(false); showToast(t('js.sounds.failed'), 'danger'); return; }
        setBusy(false);
        if (!j.success) { showToast(j.error || t('js.sounds.failed'), 'danger'); nameIn.focus(); return; }
        addOption(j.added.sid, j.added.name);
        fileIn.value = '';
        nameIn.value = '';
        dropped = null;
        markFile(null);
        render(j.sounds || []);
        showToast(j.message || 'OK', 'success');
        if (drop) drop.focus();          // ready for the next one, without reaching for the mouse
    }

    btn.addEventListener('click', add);
    // The box lives inside the settings <form>: Enter here would save the whole page instead.
    nameIn.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } });

    // ▶ beside each default select: hear what is picked before saving it. And when one changes, the
    // "Used as" column is out of date the moment it does, so it is redrawn rather than left lying.
    document.querySelectorAll('.js-sound-preview').forEach((b) => b.addEventListener('click', () => {
        const sel = document.getElementById(b.dataset.target);
        preview(sel && sel.value ? urls[sel.value] : '');
    }));
    selects().forEach((s) => s.addEventListener('change', paintUsed));

    async function load() {
        let j;
        try { j = await apiCall('admin/sounds'); } catch (e) { showToast(t('js.sounds.failed'), 'danger'); return; }
        if (!j.success) { showToast(j.error || t('js.sounds.failed'), 'danger'); return; }
        builtins = j.builtins || [];
        builtins.forEach((b) => { urls[b.id] = b.url; });
        if (j.max_count) maxCount = Number(j.max_count);
        render(j.sounds || []);
    }

    load();
})();
