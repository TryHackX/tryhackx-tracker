/**
 * The Terms / Info page editor (Settings → Site pages) — api/admin/page_content.php on the server.
 *
 * Two panes: the text on the left, how it will actually look on the right. The preview is rendered
 * BY THE SERVER, through the same `richtextRender()` the public page uses, rather than by a second
 * renderer in JavaScript — a preview produced by different code from the page is a preview of a
 * different page, and the whole point of this dialog is to see what visitors will see.
 *
 * Rendered through textContent / createElement, except the preview itself, which is server-rendered
 * HTML from the project's own sanitising renderer and is inserted as markup on purpose.
 */
(function () {
    'use strict';

    if (typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);
    const modalEl = $('pageEditModal');
    if (!modalEl) return;

    const state = { page: null, defaults: {}, max: 60000, dirty: false, note: '' };
    let previewTimer = null;

    function setError(msg) {
        const box = $('pc-error');
        box.textContent = msg || '';
        box.classList.toggle('d-none', !msg);
    }

    function count() {
        const n = $('pc-body').value.length;
        $('pc-count').textContent = n.toLocaleString() + ' / ' + state.max.toLocaleString() + ' characters';
        $('pc-count').className = 'wl-small ' + (n > state.max ? 'text-danger' : 'text-muted');
    }

    /** Ask the server to render exactly what the page will render. Debounced: it is a POST. */
    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(async () => {
            const r = await apiCall('admin/page_content', 'POST', {
                op: 'preview', page: state.page, format: $('pc-format').value, body: $('pc-body').value,
            });
            if (r.error) { setError(r.error); return; }
            setError(r.warning || '');
            // Server-rendered by includes/richtext.php, the same call the public page makes.
            $('pc-preview').innerHTML = r.html || '';
        }, 350);
    }

    async function open(page) {
        setError('');
        state.page = page;
        state.dirty = false;
        const r = await apiCall('admin/page_content?page=' + encodeURIComponent(page));
        if (r.error) { showToast(r.error, 'danger'); return; }
        state.defaults = r.default || {};
        state.max = r.max || 60000;
        state.note = r.note || '';
        $('pc-title').textContent = r.label + ' — ' + (r.stored ? 'your version' : 'built-in page');
        $('pc-format').value = r.format || 'markdown';
        $('pc-enabled').checked = !!r.enabled;
        $('pc-body').value = r.body || '';
        $('pc-note').textContent = state.note;
        $('pc-saved').textContent = r.stored && r.updated_at
            ? 'Last saved ' + r.updated_at + (r.updated_by ? ' by ' + r.updated_by : '')
            : 'Never edited.';
        // A format the operator switched off in Settings must not be offered here.
        const allowed = r.formats || ['bbcode', 'markdown'];
        [...$('pc-format').options].forEach(o => { o.disabled = !allowed.includes(o.value); });
        count();
        $('pc-preview').innerHTML = '';
        schedulePreview();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    async function save() {
        setError('');
        const btn = $('pc-save');
        btn.disabled = true;
        try {
            const r = await apiCall('admin/page_content', 'POST', {
                op: 'save', page: state.page, format: $('pc-format').value,
                body: $('pc-body').value, enabled: $('pc-enabled').checked,
            });
            if (r.error) { setError(r.error); return; }
            state.dirty = false;
            showToast(r.message || 'Saved.', 'success');
            // The card in Settings carries the badge and the timestamp; re-reading the page is the
            // only way to keep it honest without duplicating the rendering here.
            setTimeout(() => window.location.reload(), 600);
        } finally {
            btn.disabled = false;
        }
    }

    async function restore() {
        const ok = await confirmAction('Restore the built-in page?',
            'Your text for this page is deleted and visitors see the page that ships with the panel — '
            + 'written for how the tracker is configured right now. This cannot be undone.',
            { okLabel: 'Restore', danger: true });
        if (!ok) return;
        const r = await apiCall('admin/page_content', 'POST', {
            op: 'reset', page: state.page, format: $('pc-format').value,
        });
        if (r.error) { setError(r.error); return; }
        showToast(r.message || 'Restored.', 'success');
        setTimeout(() => window.location.reload(), 600);
    }

    // ── wiring ──────────────────────────────────────────────────────────────
    document.addEventListener('click', (e) => {
        const b = e.target.closest('.pc-edit');
        if (b) { e.preventDefault(); open(b.dataset.page); }
    });
    $('pc-body').addEventListener('input', () => { state.dirty = true; count(); schedulePreview(); });
    $('pc-format').addEventListener('change', () => {
        // SWITCHING FORMAT DOES NOT REWRITE WHAT WAS TYPED.
        //
        // The two syntaxes are not interchangeable and there is no converter here, so the text is
        // left exactly as it is and only the renderer changes. The one case where replacing it is
        // right is when the box still holds a default nobody has edited — then the default in the
        // other format is what the operator plainly wanted.
        const cur = $('pc-body').value.trim();
        const wasDefault = Object.values(state.defaults).some(d => (d || '').trim() === cur);
        if (wasDefault && state.defaults[$('pc-format').value]) {
            $('pc-body').value = state.defaults[$('pc-format').value];
            count();
        }
        schedulePreview();
    });
    $('pc-save').addEventListener('click', save);
    $('pc-restore').addEventListener('click', restore);
    modalEl.addEventListener('hide.bs.modal', (e) => {
        if (!state.dirty) return;
        if (!window.confirm('Close without saving? Your changes to this page are lost.')) e.preventDefault();
    });
})();
