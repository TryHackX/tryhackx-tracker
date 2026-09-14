/**
 * Settings → Sounds: the owner's uploads (list, preview, delete, add) and a preview button beside
 * each site-default select. The two selects themselves are ordinary settings saved with the form;
 * this only keeps their option lists in step with what is uploaded and removed.
 *
 * An upload travels as base64 inside JSON through AdminCommon.apiCall — the same road as every
 * other panel call, with the same CSRF header — and the server decides from the bytes what it is.
 */
(function () {
    'use strict';
    const root = document.getElementById('admin-sounds');
    if (!root || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, el, showToast, confirmAction } = window.AdminCommon;
    const t = window.t;
    const list = document.getElementById('admin-sounds-list');
    const fileIn = document.getElementById('admin-sound-file');
    const nameIn = document.getElementById('admin-sound-name');
    const btn = document.getElementById('admin-sound-upload');
    const maxBytes = Number(root.dataset.maxBytes) || 524288;
    const drop = document.getElementById('admin-sound-drop');

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
    if (drop) {
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
    const urls = {};                     // sid -> url, for the preview buttons
    let player = null;

    function preview(url) {
        if (!url) { showToast(t('js.sounds.nothing_to_play'), 'info'); return; }
        try { if (player) player.pause(); } catch (e) { /* ignore */ }
        player = new Audio(url);
        player.play().catch(() => showToast(t('js.sounds.preview_failed'), 'danger'));
    }
    const selects = () => Array.from(document.querySelectorAll('select.js-sound-select'));

    function render(rows) {
        list.textContent = '';
        if (!rows.length) { list.appendChild(el('div', { className: 'text-muted small' }, t('js.sounds.no_uploads'))); return; }
        const table = el('table', { className: 'table table-dark table-sm align-middle mb-0 admin-sounds-table' });
        rows.forEach((r) => {
            urls[r.sid] = r.url;
            const play = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary me-1', title: t('js.sounds.play') }, [el('i', { className: 'bi bi-play-fill' })]);
            play.addEventListener('click', () => preview(r.url));
            const del = el('button', { type: 'button', className: 'btn btn-outline-danger btn-sm' }, t('js.sounds.delete'));
            del.addEventListener('click', async () => {
                if (!(await confirmAction(t('js.sounds.delete'), t('js.sounds.delete_confirm', { name: r.name })))) return;
                let j;
                try { j = await apiCall('admin/sounds', 'POST', { op: 'delete', id: r.id }); }
                catch (e) { showToast(t('js.sounds.failed'), 'danger'); return; }
                if (!j.success) { showToast(j.error || t('js.sounds.failed'), 'danger'); return; }
                selects().forEach((s) => {
                    const o = s.querySelector('option[value="' + r.sid + '"]');
                    if (o) { if (s.value === o.value) s.value = ''; o.remove(); }
                });
                delete urls[r.sid];
                render(j.sounds);
                showToast(j.message || 'OK', 'success');
            });
            table.appendChild(el('tr', {}, [
                el('td', {}, r.name),
                el('td', { className: 'text-muted small' }, Math.round(r.bytes / 1024) + ' KB' + (r.ms ? ' · ' + (r.ms / 1000).toFixed(1) + ' s' : '') + ' · ' + r.mime),
                el('td', { className: 'text-end text-nowrap' }, [play, del]),
            ]));
        });
        list.appendChild(table);
    }

    async function load() {
        let j;
        try { j = await apiCall('admin/sounds'); } catch (e) { showToast(t('js.sounds.failed'), 'danger'); return; }
        if (!j.success) { showToast(j.error || t('js.sounds.failed'), 'danger'); return; }
        (j.builtins || []).forEach((b) => { urls[b.id] = b.url; });
        render(j.sounds || []);
    }

    btn.addEventListener('click', () => {
        const f = chosen();
        if (!f) { showToast(t('js.sounds.pick_file'), 'warning'); return; }
        if (f.size > maxBytes) { showToast(t('js.sounds.too_large', { kb: Math.round(maxBytes / 1024) }), 'danger'); return; }
        const reader = new FileReader();
        reader.onload = async () => {
            btn.disabled = true;
            const name = nameIn.value.trim() || f.name.replace(/\.[a-z0-9]+$/i, '');
            let j;
            try { j = await apiCall('admin/sounds', 'POST', { op: 'upload', name, data: String(reader.result) }); }
            catch (e) { btn.disabled = false; showToast(t('js.sounds.failed'), 'danger'); return; }
            btn.disabled = false;
            if (!j.success) { showToast(j.error || t('js.sounds.failed'), 'danger'); return; }
            selects().forEach((s) => s.appendChild(el('option', { value: j.added.sid }, j.added.name)));
            fileIn.value = '';
            nameIn.value = '';
            dropped = null;
            markFile(null);
            render(j.sounds || []);
            showToast(j.message || 'OK', 'success');
        };
        reader.onerror = () => showToast(t('js.sounds.failed'), 'danger');
        reader.readAsDataURL(f);
    });

    // ▶ beside each default select: hear what is picked before saving it.
    document.querySelectorAll('.js-sound-preview').forEach((b) => b.addEventListener('click', () => {
        const sel = document.getElementById(b.dataset.target);
        preview(sel && sel.value ? urls[sel.value] : '');
    }));

    load();
})();
