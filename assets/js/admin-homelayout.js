/**
 * The home page arranger (Settings → Home page layout) — api/admin/home_layout.php on the server.
 *
 * Reordering is offered THREE ways, and that is not belt-and-braces: native HTML5 drag-and-drop
 * fires no events at all on a touch screen, and cannot be driven from a keyboard. So each row also
 * has ↑ / ↓ buttons which are the same operation, work everywhere, and are what a screen reader
 * announces. Drag is the fast path, not the only path.
 *
 * Rendered through textContent / createElement only.
 */
(function () {
    'use strict';

    if (typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction, promptModal } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);
    const modalEl = $('homeLayoutModal');
    const openBtn = $('hl-open');
    if (!modalEl || !openBtn) return;

    // `rows` IS the order. Everything else reads from it, and the DOM is redrawn from it after any
    // change — keeping a second copy of the order in the DOM is how a drag and a button end up
    // disagreeing about where a section went.
    let rows = [];
    let taglineDefault = '';
    let customMax = 6;
    let newSeq = 0;           // client-side keys for sections not yet saved: new_1, new_2, …

    /** Add a section of the operator's own. It gets its real custom_N key from the server on save. */
    async function addSection() {
        if (rows.filter(x => x.is_custom).length >= customMax) { showToast(t('js.homelayout.at_most_custom', {n: customMax}), 'info'); return; }
        const label = await promptModal({ title: t('js.homelayout.new_section'), label: t('js.homelayout.heading'), placeholder: t('js.homelayout.new_section_placeholder'), okLabel: t('js.homelayout.add'), maxlength: 80 });
        if (label === null || !label.trim()) return;
        rows.push({ key: 'new_' + (++newSeq), label: label.trim(), about: t('js.homelayout.own_section_about'),
                    fixed: false, is_custom: true, hidden: false, heading: 'custom', value: label.trim(), custom: false,
                    live: true, why: '', content: 'none' });
        dirty = true; render();
    }
    let dirty = false;
    let closing = false;      // set only while a confirmed close is in flight
    let dragKey = null;

    function setError(msg) {
        const box = $('hl-error');
        box.textContent = msg || '';
        box.classList.toggle('d-none', !msg);
    }

    function move(key, delta) {
        const i = rows.findIndex(r => r.key === key);
        const j = i + delta;
        if (i < 0 || j < 0 || j >= rows.length) return;
        const [r] = rows.splice(i, 1);
        rows.splice(j, 0, r);
        dirty = true;
        render();
        // Keep the keyboard on the button that was just pressed, at its new position — otherwise
        // focus falls back to <body> and the next press goes nowhere.
        const btn = document.querySelector('.hl-row[data-key="' + CSS.escape(key) + '"] .hl-' + (delta < 0 ? 'up' : 'down'));
        if (btn) btn.focus();
    }

    function dropOn(key) {
        if (!dragKey || dragKey === key) return;
        const from = rows.findIndex(r => r.key === dragKey);
        const to = rows.findIndex(r => r.key === key);
        if (from < 0 || to < 0) return;
        const [r] = rows.splice(from, 1);
        rows.splice(to, 0, r);
        dirty = true;
        render();
    }

    function render() {
        const list = $('hl-list');
        list.textContent = '';
        rows.forEach((r, i) => {
            const row = el('div', { className: 'hl-row' + (r.hidden ? ' hl-off' : ''), 'data-key': r.key });
            row.draggable = true;

            row.appendChild(el('div', { className: 'hl-grip', text: '⠿', 'aria-hidden': 'true' }));

            const mid = el('div', { className: 'hl-mid' });
            const head = el('div', { className: 'hl-head' }, [
                el('span', { className: 'hl-pos', text: String(i + 1) }),
                el('span', { className: 'hl-label', text: r.label }),
            ]);
            if (!r.live) {
                head.appendChild(el('span', { className: 'wl-badge wl-b-muted', text: t('js.homelayout.not_showing') }));
            }
            if (r.fixed) {
                head.appendChild(el('span', { className: 'wl-badge wl-b-muted', text: t('js.homelayout.always_shown') }));
            }
            // The text state: a dot like the Site pages card's — live, draft, or none.
            if (r.content && r.content !== 'none') {
                head.appendChild(el('span', { className: 'pc-lang-dot pc-dot-' + r.content, title: r.content === 'live' ? t('js.homelayout.text_live') : t('js.homelayout.draft_saved') }));
            }
            if (r.is_custom) head.appendChild(el('span', { className: 'wl-badge wl-b-ok', text: t('js.homelayout.your_section') }));
            mid.appendChild(head);
            mid.appendChild(el('div', { className: 'hl-about wl-small text-muted', text: r.about }));
            // The distinction the whole dialog turns on: a section can be *hidden here* or *off
            // over there*, and only one of them is fixed by dragging.
            if (!r.live) {
                mid.appendChild(el('div', { className: 'hl-why wl-small', text: r.why }));
            }
            if (r.heading !== null) {
                const inp = el('input', { className: 'form-control form-control-sm bg-dark text-light border-secondary hl-heading' });
                inp.type = 'text';
                inp.maxLength = 80;
                inp.value = r.value || '';
                inp.placeholder = r.heading;
                inp.setAttribute('aria-label', t('js.homelayout.heading_for', {label: r.label}));
                inp.addEventListener('input', () => { r.value = inp.value; dirty = true; });
                // A drag started inside a text box makes the box unselectable; the row is the
                // handle, the input is not.
                inp.addEventListener('mousedown', (e) => { e.stopPropagation(); row.draggable = false; });
                inp.addEventListener('blur', () => { row.draggable = true; });
                mid.appendChild(el('div', { className: 'hl-heading-wrap' }, [
                    el('span', { className: 'wl-small text-muted', text: t('js.homelayout.heading') }), inp,
                ]));
            }
            row.appendChild(mid);

            const acts = el('div', { className: 'hl-acts' });
            // Every section — built-in or custom — can carry the operator's own text. The editor
            // is the Site pages one, opened on the 'home:<key>' page, language rail and all.
            if (r.key !== 'header') {
                const ed = el('button', { className: 'btn btn-sm btn-outline-info hl-content' });
                ed.type = 'button'; ed.title = r.content === 'none' ? t('js.homelayout.write_text_title') : t('js.homelayout.edit_text_title');
                ed.appendChild(el('i', { className: 'bi bi-pencil-square' }));
                ed.appendChild(document.createTextNode(' ' + t('js.homelayout.text_btn')));
                ed.addEventListener('click', () => {
                    if (!window.PageContentEditor) { showToast(t('js.homelayout.editor_not_loaded'), 'danger'); return; }
                    // A custom section must be saved into the layout before it has a page to write to.
                    if (r.is_custom && !/^custom_[1-9][0-9]?$/.test(r.key)) { showToast(t('js.homelayout.save_layout_first'), 'info'); return; }
                    window.PageContentEditor.open('home:' + r.key, null);
                });
                acts.appendChild(ed);
            }
            if (r.is_custom) {
                const rm = el('button', { className: 'btn btn-sm btn-outline-danger hl-remove' });
                rm.type = 'button'; rm.title = t('js.homelayout.remove_title');
                rm.appendChild(el('i', { className: 'bi bi-trash' }));
                rm.addEventListener('click', async () => {
                    if (!await confirmAction(t('js.homelayout.remove_confirm_title', {label: r.label}), t('js.homelayout.remove_confirm_body'), { okLabel: t('js.homelayout.remove'), danger: true })) return;
                    rows = rows.filter(x => x.key !== r.key); dirty = true; render();
                });
                acts.appendChild(rm);
            }
            const up = el('button', { className: 'btn btn-sm btn-outline-secondary hl-up' });
            up.type = 'button'; up.title = t('js.homelayout.move_up'); up.setAttribute('aria-label', t('js.homelayout.move_up_label', {label: r.label}));
            up.disabled = i === 0;
            up.appendChild(el('i', { className: 'bi bi-arrow-up' }));
            up.addEventListener('click', () => move(r.key, -1));
            const down = el('button', { className: 'btn btn-sm btn-outline-secondary hl-down' });
            down.type = 'button'; down.title = t('js.homelayout.move_down'); down.setAttribute('aria-label', t('js.homelayout.move_down_label', {label: r.label}));
            down.disabled = i === rows.length - 1;
            down.appendChild(el('i', { className: 'bi bi-arrow-down' }));
            down.addEventListener('click', () => move(r.key, 1));
            acts.appendChild(up);
            acts.appendChild(down);

            if (!r.fixed) {
                const sw = el('div', { className: 'form-check form-switch hl-switch mb-0' });
                const cb = el('input', { className: 'form-check-input' });
                cb.type = 'checkbox';
                cb.checked = !r.hidden;
                cb.id = 'hl-vis-' + r.key;
                cb.addEventListener('change', () => { r.hidden = !cb.checked; dirty = true; render(); });
                const lb = el('label', { className: 'form-check-label wl-small', text: r.hidden ? t('js.homelayout.hidden') : t('js.homelayout.shown') });
                lb.htmlFor = cb.id;
                sw.appendChild(cb); sw.appendChild(lb);
                acts.appendChild(sw);
            }
            row.appendChild(acts);

            row.addEventListener('dragstart', (e) => {
                dragKey = r.key;
                row.classList.add('hl-dragging');
                e.dataTransfer.effectAllowed = 'move';
                // Firefox does not start a drag at all without payload on the transfer.
                try { e.dataTransfer.setData('text/plain', r.key); } catch (_) { /* ignore */ }
            });
            row.addEventListener('dragend', () => { dragKey = null; row.classList.remove('hl-dragging'); });
            row.addEventListener('dragover', (e) => {
                if (!dragKey || dragKey === r.key) return;
                e.preventDefault();
                row.classList.add('hl-over');
            });
            row.addEventListener('dragleave', () => row.classList.remove('hl-over'));
            row.addEventListener('drop', (e) => {
                e.preventDefault();
                row.classList.remove('hl-over');
                dropOn(r.key);
            });

            list.appendChild(row);
        });
    }

    async function open() {
        setError('');
        const r = await apiCall('admin/home_layout');
        if (r.error) { showToast(r.error, 'danger'); return; }
        rows = (r.sections || []).map(s => Object.assign({}, s));
        customMax = r.custom_max || 6;
        taglineDefault = r.tagline_default || '';
        $('hl-tagline').value = r.tagline || '';
        $('hl-tagline').placeholder = taglineDefault;
        $('hl-note').textContent = r.note || '';
        dirty = false;
        closing = false;         // a fresh visit must ask again
        render();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    async function save() {
        setError('');
        const btn = $('hl-save');
        btn.disabled = true;
        try {
            const headings = {};
            rows.forEach(r => { if (r.heading !== null) headings[r.key] = r.value || ''; });
            const res = await apiCall('admin/home_layout', 'POST', {
                op: 'save',
                order: rows.map(r => r.key),
                hidden: rows.filter(r => r.hidden).map(r => r.key),
                headings,
                tagline: $('hl-tagline').value,
                // The label doubles as the default heading; what was typed in the heading box wins.
                custom: rows.filter(r => r.is_custom).map(r => ({ key: r.key, label: (r.value || r.label || '').trim() || r.label })),
            });
            if (res.error) { setError(res.error); return; }
            dirty = false;
            showToast(res.message || t('js.homelayout.saved'), 'success');
            // The card outside carries the summary line; re-reading is the only way to keep it
            // honest without rendering it twice.
            setTimeout(() => window.location.reload(), 600);
        } finally {
            btn.disabled = false;
        }
    }

    async function reset() {
        const ok = await confirmAction(t('js.homelayout.reset_title'),
            t('js.homelayout.reset_body'),
            { okLabel: t('js.homelayout.restore'), danger: true });
        if (!ok) return;
        const res = await apiCall('admin/home_layout', 'POST', { op: 'reset' });
        if (res.error) { setError(res.error); return; }
        showToast(res.message || t('js.homelayout.restored'), 'success');
        setTimeout(() => window.location.reload(), 600);
    }

    openBtn.addEventListener('click', open);
    $('hl-save').addEventListener('click', save);
    $('hl-reset').addEventListener('click', reset);
    const addBtn = $('hl-add');
    if (addBtn) addBtn.addEventListener('click', addSection);
    $('hl-tagline').addEventListener('input', () => { dirty = true; });
    // Bootstrap wants a synchronous answer here and asking is asynchronous, so the close is
    // cancelled, the question asked, and the modal closed again once the answer is in.
    modalEl.addEventListener('hide.bs.modal', (e) => {
        if (!dirty || closing) return;
        e.preventDefault();
        confirmAction(t('js.homelayout.close_title'),
            t('js.homelayout.close_body'),
            { okLabel: t('js.homelayout.discard_changes'), danger: true }).then((ok) => {
                if (!ok) return;
                closing = true;
                dirty = false;
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            });
    });
})();
