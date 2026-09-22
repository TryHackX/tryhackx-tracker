/**
 * Settings → Profiles: the site's default picture and default cover (1.63.0).
 *
 * The same editor the account page uses (assets/js/media-editor.js, window.MediaEditor) — a picked
 * file is framed locally first and sent with its framing in one multipart request — and the same
 * endpoint family: admin/user_media, which answers every write with the site's new state so the two
 * blocks redraw from what the server now holds rather than from what this page hoped it did.
 *
 * None of this is part of the Settings form: the file inputs carry no name, nothing here travels with
 * a Save, and each change takes effect the moment the server says so — which the hint under each
 * block tells the owner. Uploads skip AdminCommon.apiCall because that one speaks JSON only; the CSRF
 * header is the panel's own (document.body.dataset.csrf), the one apiCall would have sent.
 *
 * `js.mediaadmin.*` rather than `js.media.*` for what is new here: the second prefix is public and
 * rides along on every public page, and none of this is ever read outside the panel.
 */
(function () {
    'use strict';
    const dataEl = document.getElementById('adm-media-data');
    if (!dataEl || !window.AdminCommon || !window.MediaEditor) return;
    const { apiCall, showToast, confirmAction } = window.AdminCommon;
    const t = window.t || ((k) => k);
    let site;
    try { site = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    if (!site || !site.avatar) return;
    const API = () => document.body.dataset.apiBase || '';
    const CSRF = () => document.body.dataset.csrf || '';
    const BUTTONS = { primary: 'btn btn-sm btn-primary', secondary: 'btn btn-sm btn-outline-secondary' };

    async function upload(kind, file, st) {
        const fd = new FormData();
        fd.append('op', 'default_upload');
        fd.append('kind', kind);
        fd.append('x', String(st.x));
        fd.append('y', String(st.y));
        fd.append('zoom', String(st.zoom));
        fd.append('file', file, file.name || 'image');
        let j;
        try {
            const res = await fetch(API() + 'admin/user_media', { method: 'POST', body: fd,
                                    headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF() } });
            j = await res.json();
        } catch (e) { return { ok: false, message: t('js.mediaadmin.failed') }; }
        return j && j.success ? { ok: true, message: j.message, site: j.site } : { ok: false, message: (j && j.error) || t('js.mediaadmin.failed') };
    }

    function block(kind) {
        const root = document.getElementById('adm-media-' + kind);
        if (!root) return;
        const preview = document.getElementById('adm-media-' + kind + '-preview');
        const drop = document.getElementById('adm-media-' + kind + '-drop');
        const input = document.getElementById('adm-media-' + kind + '-file');
        const adjust = document.getElementById('adm-media-' + kind + '-adjust');
        const remove = document.getElementById('adm-media-' + kind + '-remove');

        /** The preview: the default picture's own 128 square, or the cover painted with its framing. */
        function paint() {
            const st = site[kind] || {};
            preview.textContent = '';
            if (adjust) adjust.hidden = !st.has;
            if (remove) remove.hidden = !st.has;
            if (!st.has) { preview.appendChild(document.createTextNode(t('js.mediaadmin.not_set'))); return; }
            const im = document.createElement('img');
            im.alt = '';
            if (kind === 'avatar') {
                im.src = st.preview;
            } else {
                // The same three numbers the profile page paints with, through the CSSOM.
                preview.style.setProperty('--fe-x', st.x + '%');
                preview.style.setProperty('--fe-y', st.y + '%');
                preview.style.setProperty('--fe-z', String(st.zoom));
                im.className = 'adm-media-framed';
                im.src = st.src;
            }
            preview.appendChild(im);
        }

        const edit = (src, fresh, file) => window.MediaEditor.open({
            mode: kind, src: src, fresh: fresh, buttons: BUTTONS,
            title: t(kind === 'cover' ? 'js.mediaadmin.title_cover' : 'js.mediaadmin.title_avatar'),
            x: fresh ? 50 : site[kind].x, y: fresh ? 50 : site[kind].y, zoom: fresh ? 1 : site[kind].zoom,
            coverH: site.cover_h, coverHm: site.cover_hm, desktopW: site.desk_w, phoneW: site.phone_w,
            note: t(kind === 'cover' ? 'js.mediaadmin.note_cover' : 'js.mediaadmin.note_avatar'),
            returnFocus: fresh ? drop : adjust,
            save: async (st) => {
                let r;
                if (fresh) r = await upload(kind, file, st);
                else {
                    const j = await apiCall('admin/user_media', 'POST', { op: 'default_position', kind: kind, x: st.x, y: st.y, zoom: st.zoom });
                    r = j && j.success ? { ok: true, message: j.message, site: j.site } : { ok: false, message: (j && j.error) || t('js.mediaadmin.failed') };
                }
                if (r.ok) { site = Object.assign(site, r.site || {}); paintAll(); showToast(r.message || 'OK', 'success'); }
                return r;
            },
        });

        function take(file) {
            if (input) input.value = '';
            const bad = window.MediaEditor.preflight(file, Number(site.max_bytes) || 0);
            if (bad) { showToast(bad, 'warning'); return; }
            const url = URL.createObjectURL(file);
            edit(url, true, file).finally(() => URL.revokeObjectURL(url));
        }
        if (drop && input) {
            input.setAttribute('tabindex', '-1');
            input.addEventListener('change', () => { if (input.files && input.files[0]) take(input.files[0]); });
            drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
            ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('dragging'); }));
            ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('dragging')));
            drop.addEventListener('drop', (e) => {
                e.preventDefault();
                const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (f) take(f);
            });
        }
        if (adjust) adjust.addEventListener('click', () => { if (site[kind].has && site[kind].src) edit(site[kind].src, false, null); });
        if (remove) remove.addEventListener('click', async () => {
            if (!(await confirmAction(t('js.mediaadmin.remove_title'), t(kind === 'cover' ? 'js.mediaadmin.remove_q_cover' : 'js.mediaadmin.remove_q_avatar')))) return;
            const j = await apiCall('admin/user_media', 'POST', { op: 'default_remove', kind: kind });
            if (!j || !j.success) { showToast((j && j.error) || t('js.mediaadmin.failed'), 'danger'); return; }
            site = Object.assign(site, j.site || {});
            paintAll();
            showToast(j.message || t('js.mediaadmin.removed'), 'success');
        });
        return paint;
    }
    const painters = [block('avatar'), block('cover')].filter(Boolean);
    function paintAll() { painters.forEach((p) => p()); }
    paintAll();
})();
