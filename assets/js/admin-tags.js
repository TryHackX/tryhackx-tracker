(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, showToast, confirmAction, promptModal, renderPagination, fmtDate, debounce, DEBOUNCE } = A;
    const $ = (id) => document.getElementById(id);
    const S = { page: 1, status: '', q: '', sort: 'uses', manage: false, rows: [] };
    const STATUS = { approved: ['js.tags.st_approved', 'wl-b-ok'], pending: ['js.tags.st_pending', 'wl-b-pending'], rejected: ['js.tags.st_rejected', 'wl-b-bad'] };

    function btn(icon, cls, label, fn) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls + ' wl-act', title: label, 'aria-label': label }, el('i', { className: 'bi ' + icon }));
        b.addEventListener('click', fn);
        return b;
    }

    async function load() {
        const qs = new URLSearchParams({ page: String(S.page), sort: S.sort });
        if (S.status) qs.set('status', S.status);
        if (S.q) qs.set('q', S.q);
        const r = await apiCall('admin/tags&' + qs.toString());
        if (!r || r.error) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); return; }
        S.manage = !!r.manage;
        S.rows = r.rows || [];
        $('tags-total').textContent = t.key('js.tags.n_tags', { n: t.num(r.total || 0) });
        const badge = $('tags-pending-badge');
        badge.textContent = r.pending ? t.key('js.tags.n_pending', { n: t.num(r.pending) }) : '';
        badge.classList.toggle('d-hidden', !r.pending);
        render();
        renderPagination($('tags-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p) => { S.page = p; load(); } });
    }

    async function act(op, ids, extra) {
        const r = await apiCall('admin/tag_action', 'POST', Object.assign({ op: op, ids: ids }, extra || {}));
        if (!r || !r.success) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); return false; }
        showToast(t.key('js.tags.done'), 'success');
        load();
        return true;
    }

    function swatch(color) {
        const s = el('span', { className: 'tags-swatch' });
        if (color) s.style.background = color;
        return s;
    }

    function editRow(tr, row) {
        const cell = tr.firstChild;
        cell.textContent = '';
        const name = el('input', { type: 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary', maxlength: '40', value: row.name, 'aria-label': t.key('js.tags.name') });
        const color = el('input', { type: 'color', className: 'form-control form-control-color form-control-sm', value: row.color || '#8fa3bf', 'aria-label': t.key('js.tags.color') });
        const noColor = el('label', { className: 'form-check-label wl-small' }, [el('input', { type: 'checkbox', className: 'form-check-input me-1' }), t.key('js.tags.no_color')]);
        noColor.querySelector('input').checked = !row.color;
        const desc = el('input', { type: 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary', maxlength: '200', value: row.description || '', placeholder: t.key('js.tags.description'), 'aria-label': t.key('js.tags.description') });
        const save = el('button', { type: 'button', className: 'btn btn-sm btn-success' }, t.key('js.tags.save'));
        const cancel = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary' }, t.key('js.tags.cancel'));
        save.addEventListener('click', () => act('save', [row.id], { name: name.value, color: noColor.querySelector('input').checked ? '' : color.value, description: desc.value }));
        cancel.addEventListener('click', () => render());
        cell.append(el('div', { className: 'tags-edit' }, [name, el('div', { className: 'tags-edit-row' }, [color, noColor]), desc, el('div', { className: 'tags-edit-row' }, [save, cancel])]));
        name.focus();
    }

    async function merge(row) {
        const target = await promptModal({ title: t.key('js.tags.merge_title', { name: row.name }), label: t.key('js.tags.merge_label'), placeholder: t.key('js.tags.merge_ph'), maxlength: 40 });
        if (target == null || String(target).trim() === '') return;
        const r = await apiCall('admin/tags&q=' + encodeURIComponent(String(target).trim()) + '&per_page=50');
        const want = String(target).trim().toLowerCase();
        const hit = (r && r.rows || []).find(x => x.id !== row.id && (x.slug === want || x.name.toLowerCase() === want));
        if (!hit) { showToast(t.key('js.tags.merge_none'), 'error'); return; }
        if (await confirmAction(t.key('js.tags.merge_title', { name: row.name }), t.key('js.tags.merge_confirm', { from: row.name, into: hit.name }))) act('merge', [row.id], { into: hit.id });
    }

    function render() {
        const tb = $('tags-body');
        tb.textContent = '';
        if (!S.rows.length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 5, className: 'text-center text-muted py-4', text: t.key('js.tags.empty') })));
            return;
        }
        S.rows.forEach(row => {
            const tr = el('tr', {});
            const chip = el('span', { className: 'fchip fchip-x' }, row.name);
            if (row.color) chip.style.setProperty('--fc', row.color);
            const nameCell = el('td', {}, [el('div', { className: 'tags-name' }, [swatch(row.color), chip, el('span', { className: 'wl-small text-muted', text: row.tag })])]);
            if (row.description) nameCell.appendChild(el('div', { className: 'wl-small text-muted', text: row.description }));
            tr.appendChild(nameCell);
            const st = STATUS[row.status] || STATUS.approved;
            const stCell = el('td', { className: 'col-badge' }, el('span', { className: 'wl-badge ' + st[1] }, t.key(st[0])));
            if (row.hidden) stCell.appendChild(el('span', { className: 'wl-badge wl-b-muted ms-1' }, t.key('js.tags.st_hidden')));
            tr.appendChild(stCell);
            tr.appendChild(el('td', { className: 'font-mono', text: t.num(row.uses || 0) }));
            tr.appendChild(el('td', { className: 'wl-small' }, [el('div', { text: row.created_by_name || t.key('js.tags.by_' + row.origin) }), el('div', { className: 'text-muted', text: fmtDate(row.created_at) })]));
            const acts = el('td', { className: 'th-actions' });
            if (row.status !== 'approved') acts.appendChild(btn('bi-check-lg', 'btn-outline-success', t.key('js.tags.approve'), () => act('approve', [row.id])));
            if (row.status !== 'rejected') acts.appendChild(btn('bi-x-lg', 'btn-outline-warning', t.key('js.tags.reject'), async () => {
                if (await confirmAction(t.key('js.tags.reject'), t.key('js.tags.reject_confirm', { name: row.name, n: t.num(row.uses || 0) }))) act('reject', [row.id]);
            }));
            if (S.manage) {
                acts.appendChild(btn('bi-pencil', 'btn-outline-info', t.key('js.tags.edit'), () => editRow(tr, row)));
                acts.appendChild(btn(row.hidden ? 'bi-eye' : 'bi-eye-slash', 'btn-outline-secondary', t.key(row.hidden ? 'js.tags.unhide' : 'js.tags.hide'), () => act(row.hidden ? 'unhide' : 'hide', [row.id])));
                acts.appendChild(btn('bi-box-arrow-in-right', 'btn-outline-secondary', t.key('js.tags.merge'), () => merge(row)));
                acts.appendChild(btn('bi-trash', 'btn-outline-danger', t.key('js.tags.delete'), async () => {
                    if (await confirmAction(t.key('js.tags.delete'), t.key('js.tags.delete_confirm', { name: row.name, n: t.num(row.uses || 0) }), { danger: true })) act('delete', [row.id]);
                }));
            }
            tr.appendChild(acts);
            tb.appendChild(tr);
        });
    }

    function init() {
        const q = $('tags-f-q');
        q.addEventListener('input', debounce(() => { S.q = q.value.trim(); S.page = 1; load(); }, DEBOUNCE.search));
        $('tags-f-status').addEventListener('change', (e) => { S.status = e.target.value; S.page = 1; load(); });
        $('tags-f-sort').addEventListener('change', (e) => { S.sort = e.target.value; S.page = 1; load(); });
        if (new URLSearchParams(location.search).get('status') === 'pending') { S.status = 'pending'; $('tags-f-status').value = 'pending'; }
        load();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
