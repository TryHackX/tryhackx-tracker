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
        $('pc-count').textContent = t('js.pagecontent.count', { n: n.toLocaleString(), max: state.max.toLocaleString() });
        $('pc-count').className = 'wl-small ' + (n > state.max ? 'text-danger' : 'text-muted');
    }

    /** Ask the server to render exactly what the page will render. Debounced: it is a POST. */
    /**
     * The preview is an iframe with the PUBLIC stylesheet (data-css) and nothing of the panel's,
     * because the panel's CSS knows nothing about the stats widget or the announce box. The frame's
     * document is rebuilt through srcdoc on every change; the markup itself is what the public page
     * would show, so what the operator sees is what a visitor gets.
     */
    function setPreview(html) {
        const fr = $('pc-preview');
        if (!fr || fr.tagName !== 'IFRAME') { if (fr) fr.innerHTML = html; return; }
        const css = fr.dataset.css || '', base = fr.dataset.base || '/';
        fr.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><base href="' + base.replace(/"/g, '&quot;') + '">'
            + '<link rel="stylesheet" href="' + css.replace(/"/g, '&quot;') + '">'
            + '<style>html,body{background:#000011;margin:0}body{padding:0.9rem 1.1rem 0.9rem 1.4rem}.container{max-width:none;padding:0}'
            + 'ol,ul{padding-left:1.6em;margin-left:0}'
            + 'main{margin:0}.rt-page h1{margin-top:0}</style></head>'
            + '<body class="page-preview"><div class="container"><main><div class="rt rt-page rt-home">' + html + '</div></main></div></body></html>';
    }

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
            setPreview(r.html || '');
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
            b.title = l.name + ' — ' + (l.enabled ? t('js.pagecontent.lang_live')
                     : l.stored ? t('js.pagecontent.lang_draft') : t('js.pagecontent.lang_none'));
            if (l.code !== current) b.addEventListener('click', () => switchLang(l.code));
            rail.appendChild(b);
        });
        // Which version a visitor reading THIS language actually gets. Worth saying out loud: the
        // fallback chain means it is often a version written for a different language.
        const note = $('pc-serving');
        if (!serving) {
            note.textContent = t('js.pagecontent.serving_builtin');
        } else if (serving === current) {
            note.textContent = t('js.pagecontent.serving_this');
        } else {
            note.textContent = t('js.pagecontent.serving_other', { lang: serving.toUpperCase() });
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
        box.appendChild(el('span', { className: 'wl-small text-muted me-1', text: t('js.pagecontent.paste_in') }));
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
        if (state.dirty && !await confirmAction(t('js.pagecontent.switch_title'),
                t('js.pagecontent.switch_body'),
                { okLabel: t('js.pagecontent.switch_ok'), danger: true })) return;
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
            + ' — ' + (r.stored ? (r.enabled ? t('js.pagecontent.title_live') : t('js.pagecontent.title_draft')) : t('js.pagecontent.title_builtin'));
        $('pc-format').value = r.format || 'markdown';
        $('pc-enabled').checked = !!r.enabled;
        $('pc-body').value = r.body || '';
        $('pc-note').textContent = state.note;
        $('pc-saved').textContent = r.stored && r.updated_at
            ? (r.updated_by ? t('js.pagecontent.last_saved_by', { at: r.updated_at, by: r.updated_by }) : t('js.pagecontent.last_saved', { at: r.updated_at }))
            : t('js.pagecontent.never_edited');
        // A format the operator switched off in Settings must not be offered here.
        const allowed = r.formats || ['bbcode', 'markdown'];
        [...$('pc-format').options].forEach(o => { o.disabled = !allowed.includes(o.value); });
        count();
        setPreview('');
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
            showToast(r.message || t('js.pagecontent.saved'), 'success');
            // The card in Settings carries the badge and the timestamp; re-reading the page is the
            // only way to keep it honest without duplicating the rendering here.
            setTimeout(() => window.location.reload(), 600);
        } finally {
            btn.disabled = false;
        }
    }

    async function restore() {
        const ok = await confirmAction(t('js.pagecontent.restore_title'),
            t('js.pagecontent.restore_body', { lang: String(state.lang).toUpperCase() }),
            { okLabel: t('js.pagecontent.restore_ok'), danger: true });
        if (!ok) return;
        const r = await apiCall('admin/page_content', 'POST', {
            op: 'reset', page: state.page, lang: state.lang, format: $('pc-format').value,
        });
        if (r.error) { setError(r.error); return; }
        showToast(r.message || t('js.pagecontent.restored'), 'success');
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
        confirmAction(t('js.pagecontent.close_title'),
            t('js.pagecontent.close_body'),
            { okLabel: t('js.pagecontent.close_ok'), danger: true }).then((ok) => {
                if (!ok) return;
                state.closing = true;
                state.dirty = false;
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            });
    });
})();
