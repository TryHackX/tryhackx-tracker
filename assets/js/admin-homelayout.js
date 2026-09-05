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
    const { apiCall, el, showToast, confirmAction } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);
    const modalEl = $('homeLayoutModal');
    const openBtn = $('hl-open');
    if (!modalEl || !openBtn) return;

    // `rows` IS the order. Everything else reads from it, and the DOM is redrawn from it after any
    // change — keeping a second copy of the order in the DOM is how a drag and a button end up
    // disagreeing about where a section went.
    let rows = [];
    let taglineDefault = '';
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
                head.appendChild(el('span', { className: 'wl-badge wl-b-muted', text: 'not showing' }));
            }
            if (r.fixed) {
                head.appendChild(el('span', { className: 'wl-badge wl-b-muted', text: 'always shown' }));
            }
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
                inp.setAttribute('aria-label', 'Heading for ' + r.label);
                inp.addEventListener('input', () => { r.value = inp.value; dirty = true; });
                // A drag started inside a text box makes the box unselectable; the row is the
                // handle, the input is not.
                inp.addEventListener('mousedown', (e) => { e.stopPropagation(); row.draggable = false; });
                inp.addEventListener('blur', () => { row.draggable = true; });
                mid.appendChild(el('div', { className: 'hl-heading-wrap' }, [
                    el('span', { className: 'wl-small text-muted', text: 'Heading' }), inp,
                ]));
            }
            row.appendChild(mid);

            const acts = el('div', { className: 'hl-acts' });
            const up = el('button', { className: 'btn btn-sm btn-outline-secondary hl-up' });
            up.type = 'button'; up.title = 'Move up'; up.setAttribute('aria-label', 'Move ' + r.label + ' up');
            up.disabled = i === 0;
            up.appendChild(el('i', { className: 'bi bi-arrow-up' }));
            up.addEventListener('click', () => move(r.key, -1));
            const down = el('button', { className: 'btn btn-sm btn-outline-secondary hl-down' });
            down.type = 'button'; down.title = 'Move down'; down.setAttribute('aria-label', 'Move ' + r.label + ' down');
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
                const lb = el('label', { className: 'form-check-label wl-small', text: r.hidden ? 'Hidden' : 'Shown' });
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
            });
            if (res.error) { setError(res.error); return; }
            dirty = false;
            showToast(res.message || 'Saved.', 'success');
            // The card outside carries the summary line; re-reading is the only way to keep it
            // honest without rendering it twice.
            setTimeout(() => window.location.reload(), 600);
        } finally {
            btn.disabled = false;
        }
    }

    async function reset() {
        const ok = await confirmAction('Restore the built-in layout?',
            'The sections go back to the order they ship in, nothing is hidden, and every heading '
            + 'and the tagline return to their original wording. This cannot be undone.',
            { okLabel: 'Restore', danger: true });
        if (!ok) return;
        const res = await apiCall('admin/home_layout', 'POST', { op: 'reset' });
        if (res.error) { setError(res.error); return; }
        showToast(res.message || 'Restored.', 'success');
        setTimeout(() => window.location.reload(), 600);
    }

    openBtn.addEventListener('click', open);
    $('hl-save').addEventListener('click', save);
    $('hl-reset').addEventListener('click', reset);
    $('hl-tagline').addEventListener('input', () => { dirty = true; });
    // Bootstrap wants a synchronous answer here and asking is asynchronous, so the close is
    // cancelled, the question asked, and the modal closed again once the answer is in.
    modalEl.addEventListener('hide.bs.modal', (e) => {
        if (!dirty || closing) return;
        e.preventDefault();
        confirmAction('Close without saving?',
            'Your changes to the layout have not been saved. Closing now loses them.',
            { okLabel: 'Discard changes', danger: true }).then((ok) => {
                if (!ok) return;
                closing = true;
                dirty = false;
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            });
    });
})();
