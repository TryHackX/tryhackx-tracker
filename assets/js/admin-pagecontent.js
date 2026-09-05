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

    const state = { page: null, lang: null, defaults: {}, max: 60000, dirty: false, closing: false, note: '' };
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
                op: 'preview', page: state.page, lang: state.lang,
                format: $('pc-format').value, body: $('pc-body').value,
            });
            if (r.error) { setError(r.error); return; }
            setError(r.warning || '');
            // Server-rendered by includes/richtext.php, the same call the public page makes.
            $('pc-preview').innerHTML = r.html || '';
        }, 350);
    }

    /**
     * The language rail.
     *
     * A tab per installed language with a dot saying what that language already has: live, a draft,
     * or nothing. Switching tabs re-fetches, because each language is a separate page — and it asks
     * first when there are unsaved changes, since a tab click that silently threw away an
     * afternoon's writing would be the worst control on this screen.
     */
    function renderLangs(list, current, serving) {
        const rail = $('pc-langs');
        rail.textContent = '';
        (list || []).forEach((l) => {
            const b = el('button', { className: 'pc-lang' + (l.code === current ? ' active' : '') });
            b.type = 'button';
            b.appendChild(el('span', { className: 'pc-lang-code', text: l.code.toUpperCase() }));
            b.appendChild(el('span', { className: 'pc-lang-name', text: l.name }));
            const dot = l.enabled ? 'live' : (l.stored ? 'draft' : 'none');
            b.appendChild(el('span', { className: 'pc-lang-dot pc-dot-' + dot }));
            b.title = l.name + ' — ' + (l.enabled ? 'your version is live'
                     : l.stored ? 'saved as a draft' : 'no version written yet');
            if (l.code !== current) b.addEventListener('click', () => switchLang(l.code));
            rail.appendChild(b);
        });
        // Which version a visitor reading THIS language actually gets. Worth saying out loud: the
        // fallback chain means it is often a version written for a different language.
        const note = $('pc-serving');
        if (!serving) {
            note.textContent = 'Visitors reading this language get the built-in page.';
        } else if (serving === current) {
            note.textContent = 'Visitors reading this language get this version.';
        } else {
            note.textContent = 'Visitors reading this language currently get the '
                             + serving.toUpperCase() + ' version — nothing is published here yet.';
        }
    }

    /**
     * The placeholders a home section may use — chips that insert `{{name}}` at the caret.
     *
     * Listed rather than documented: a name you can see and click is one you will not mistype, and
     * an unknown placeholder is removed from the page rather than printed.
     */
    function renderPlaceholders(list) {
        const box = $('pc-placeholders');
        if (!box) return;
        box.textContent = '';
        box.hidden = !list.length;
        if (!list.length) return;
        box.appendChild(el('span', { className: 'wl-small text-muted me-1', text: 'Paste in:' }));
        list.forEach(p => {
            const b = el('button', { className: 'pc-ph', type: 'button', title: p.what || '' });
            b.appendChild(el('code', { text: '{{' + p.name + '}}' }));
            b.addEventListener('click', () => {
                const ta = $('pc-body');
                const at = ta.selectionStart || 0, to = ta.selectionEnd || at;
                const tok = '{{' + p.name + '}}';
                ta.value = ta.value.slice(0, at) + tok + ta.value.slice(to);
                ta.focus(); ta.selectionStart = ta.selectionEnd = at + tok.length;
                state.dirty = true; count(); schedulePreview();
            });
            box.appendChild(b);
        });
    }

    async function switchLang(code) {
        if (state.dirty && !await confirmAction('Switch language?',
                'Your changes to this page have not been saved. Switching loses them.',
                { okLabel: 'Discard and switch', danger: true })) return;
        state.dirty = false;
        open(state.page, code);
    }

    async function open(page, lang) {
        setError('');
        state.page = page;
        state.dirty = false;
        state.closing = false;   // a fresh visit must ask again
        const r = await apiCall('admin/page_content&page=' + encodeURIComponent(page)
                                + (lang ? '&lang=' + encodeURIComponent(lang) : ''));
        if (r.error) { showToast(r.error, 'danger'); return; }
        state.lang = r.lang;
        state.defaults = r.default || {};
        state.max = r.max || 60000;
        state.note = r.note || '';
        renderLangs(r.languages, r.lang, r.serving);
        renderPlaceholders(r.placeholders || []);
        $('pc-title').textContent = r.label + ' · ' + String(r.lang).toUpperCase()
            + ' — ' + (r.stored ? (r.enabled ? 'your version, live' : 'your draft') : 'built-in page');
        $('pc-format').value = r.format || 'markdown';
        $('pc-enabled').checked = !!r.enabled;
        $('pc-body').value = r.body || '';
        $('pc-note').textContent = state.note;
        $('pc-saved').textContent = r.stored && r.updated_at
            ? 'Last saved ' + r.updated_at + (r.updated_by ? ' by ' + r.updated_by : '')
            : 'Never edited in this language.';
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
                op: 'save', page: state.page, lang: state.lang, format: $('pc-format').value,
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
            'Your ' + String(state.lang).toUpperCase() + ' text for this page is deleted and the built-in '
            + 'one comes back — written for how the tracker is configured right now. Other languages are '
            + 'left alone. This cannot be undone.',
            { okLabel: 'Restore', danger: true });
        if (!ok) return;
        const r = await apiCall('admin/page_content', 'POST', {
            op: 'reset', page: state.page, lang: state.lang, format: $('pc-format').value,
        });
        if (r.error) { setError(r.error); return; }
        showToast(r.message || 'Restored.', 'success');
        setTimeout(() => window.location.reload(), 600);
    }

    // ── wiring ──────────────────────────────────────────────────────────────
    document.addEventListener('click', (e) => {
        const b = e.target.closest('.pc-edit');
        if (b) { e.preventDefault(); open(b.dataset.page, b.dataset.lang || null); }
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
    // Bootstrap wants a synchronous answer here and asking is asynchronous, so the close is
    // cancelled, the question asked, and the modal closed again once the answer is in.
    // The home page arranger opens this editor on a section: one entry point, no second editor.
    window.PageContentEditor = { open };

    modalEl.addEventListener('hide.bs.modal', (e) => {
        if (!state.dirty || state.closing) return;
        e.preventDefault();
        confirmAction('Close without saving?',
            'Your changes to this page have not been saved. Closing now loses them.',
            { okLabel: 'Discard changes', danger: true }).then((ok) => {
                if (!ok) return;
                state.closing = true;
                state.dirty = false;
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            });
    });
})();
