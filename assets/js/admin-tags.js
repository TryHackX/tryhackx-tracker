(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, showToast, confirmAction, promptModal, renderPagination, fmtDate, debounce, DEBOUNCE } = A;
    const $ = (id) => document.getElementById(id);
    const S = { page: 1, status: '', q: '', sort: 'uses', cat: '', manage: false, rows: [], sel: new Set() };
    const K = { cats: [], sugg: [], manage: false };
    const VIEWS = ['list', 'cats', 'changes', 'forum'];

    function tabCount(view, n) {
        const b = $('tags-n-' + view);
        if (!b) return;
        b.textContent = n ? t.num(n) : '';
        b.classList.toggle('d-hidden', !n);
    }

    function switchView(view) {
        if (!VIEWS.includes(view)) view = 'list';
        document.querySelectorAll('#tags-tabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.view === view));
        VIEWS.forEach(v => $('tags-view-' + v).classList.toggle('d-hidden', v !== view));
        try { localStorage.setItem('thx_tags_view', view); } catch (e) {}
    }

    function catOf(id) {
        return K.cats.find(c => c.id === id) || null;
    }

    function catOptions(sel, first) {
        const keep = sel.value;
        sel.textContent = '';
        first.forEach(([v, label]) => sel.appendChild(el('option', { value: v }, label)));
        K.cats.forEach(c => sel.appendChild(el('option', { value: String(c.id) }, c.l)));
        if ([...sel.options].some(o => o.value === keep)) sel.value = keep;
    }

    function syncBulk() {
        const n = S.sel.size;
        $('tags-bulk').classList.toggle('d-hidden', n === 0 || !S.manage);
        $('tags-bulk-n').textContent = t.key('js.tags.n_selected', { n: t.num(n) });
        const all = $('tags-check-all');
        all.checked = S.rows.length > 0 && S.rows.every(r => S.sel.has(r.id));
    }
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
        if (S.cat) qs.set('cat', S.cat);
        const r = await apiCall('admin/tags&' + qs.toString());
        if (!r || r.error) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); return; }
        S.manage = !!r.manage;
        S.rows = r.rows || [];
        $('tags-total').textContent = t.key('js.tags.n_tags', { n: t.num(r.total || 0) });
        const badge = $('tags-pending-badge');
        badge.textContent = r.pending ? t.key('js.tags.n_pending', { n: t.num(r.pending) }) : '';
        badge.classList.toggle('d-hidden', !r.pending);
        tabCount('list', r.pending || 0);
        render();
        syncBulk();
        renderPagination($('tags-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p) => { S.page = p; load(); } });
    }

    async function act(op, ids, extra) {
        const r = await apiCall('admin/tag_action', 'POST', Object.assign({ op: op, ids: ids }, extra || {}));
        if (!r || !r.success) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); return false; }
        showToast(t.key('js.tags.done'), 'success');
        load();
        loadCats();
        loadForum();
        return true;
    }

    const SITE = document.body.dataset.site || '';
    const searchHref = (id) => SITE + '?action=search&tags=' + encodeURIComponent(id);

    function chip(label, g, color, extra, title) {
        const s = el('span', { className: 'fchip fchip-' + g + ' tags-chip' + (extra || ''), title: title || null }, label);
        if (color) s.style.setProperty('--fc', color);
        return s;
    }

    function chipLink(id, label, g, color) {
        const a = el('a', { className: 'fchip fchip-' + g + ' tags-chip', href: searchHref(id), target: '_blank', rel: 'noopener', title: t.key('js.tags.open_search') }, label);
        if (color) a.style.setProperty('--fc', color);
        return a;
    }

    const PALETTE = ['#ffd54f', '#ffb74d', '#ff8a80', '#f48fb1', '#ce93d8', '#9fa8da', '#90caf9', '#4fc3f7', '#80deea', '#81c784', '#c5e1a5', '#b0bec5'];

    function editRow(tr, row) {
        const cell = tr.children[1];
        cell.textContent = '';
        const name = el('input', { type: 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary', maxlength: '40', value: row.name, 'aria-label': t.key('js.tags.name') });
        let current = row.color || '';
        const preview = chip(row.name, 'x', null, ' tags-preview');
        const native = el('input', { type: 'color', className: 'tags-color-native', value: current || '#8fa3bf', 'aria-label': t.key('js.tags.color_custom') });
        const swatches = el('div', { className: 'tags-swatches', role: 'radiogroup', 'aria-label': t.key('js.tags.color') });
        const paint = () => {
            if (current) preview.style.setProperty('--fc', current); else preview.style.removeProperty('--fc');
            let known = false;
            swatches.querySelectorAll('.tags-swatch').forEach(b => {
                const on = b.dataset.c === current;
                if (on) known = true;
                b.setAttribute('aria-checked', on ? 'true' : 'false');
            });
            custom.classList.toggle('is-on', !known);
            custom.style.setProperty('--sw', current || 'transparent');
        };
        const paletteButton = (c, title, kids) => {
            const b = el('button', { type: 'button', className: 'tags-swatch' + (c ? '' : ' tags-swatch-none'), role: 'radio', title, 'aria-label': title, dataset: { c } }, kids || null);
            if (c) b.style.setProperty('--sw', c);
            b.addEventListener('click', () => { current = c; if (c) native.value = c; paint(); });
            return b;
        };
        swatches.appendChild(paletteButton('', t.key('js.tags.no_color'), el('i', { className: 'bi bi-slash-circle', 'aria-hidden': 'true' })));
        PALETTE.forEach(c => swatches.appendChild(paletteButton(c, c)));
        const custom = el('label', { className: 'tags-swatch tags-swatch-custom', title: t.key('js.tags.color_custom') }, [el('i', { className: 'bi bi-palette', 'aria-hidden': 'true' }), native]);
        native.addEventListener('input', () => { current = native.value; paint(); });
        swatches.appendChild(custom);
        name.addEventListener('input', () => { preview.textContent = name.value.trim() || row.name; });
        const desc = el('input', { type: 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary', maxlength: '200', value: row.description || '', placeholder: t.key('js.tags.description'), 'aria-label': t.key('js.tags.description') });
        const cat = el('select', { className: 'form-select form-select-sm bg-dark text-light border-secondary w-auto', 'aria-label': t.key('js.tags.k_label') });
        catOptions(cat, [['0', t.key('js.tags.k_none')]]);
        cat.value = String(row.category_id || 0);
        const save = el('button', { type: 'button', className: 'btn btn-sm btn-success' }, t.key('js.tags.save'));
        const cancel = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary' }, t.key('js.tags.cancel'));
        save.addEventListener('click', () => act('save', [row.id], { name: name.value, color: current, description: desc.value, category: Number(cat.value) }));
        cancel.addEventListener('click', () => render());
        cell.append(el('div', { className: 'tags-edit' }, [
            name,
            el('div', { className: 'tags-edit-color' }, [el('span', { className: 'wl-small text-muted', text: t.key('js.tags.color') }), swatches]),
            el('div', { className: 'tags-edit-row' }, [el('span', { className: 'wl-small text-muted', text: t.key('js.tags.preview') }), preview]),
            desc,
            el('div', { className: 'tags-edit-row' }, [el('span', { className: 'wl-small text-muted', text: t.key('js.tags.k_label') }), cat]),
            el('div', { className: 'tags-edit-row' }, [save, cancel]),
        ]));
        paint();
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
            tb.appendChild(el('tr', {}, el('td', { colSpan: 7, className: 'text-center text-muted py-4', text: t.key('js.tags.empty') })));
            return;
        }
        S.rows.forEach(row => {
            const tr = el('tr', {});
            const live = row.status === 'approved' && !row.hidden;
            const box = el('input', { type: 'checkbox', className: 'form-check-input', checked: S.sel.has(row.id), 'aria-label': t.key('js.tags.select', { name: row.name }) });
            box.addEventListener('change', () => { if (box.checked) S.sel.add(row.id); else S.sel.delete(row.id); syncBulk(); });
            tr.appendChild(el('td', { className: 'tags-check' }, box));
            const nameCell = el('td', {}, [el('div', { className: 'tags-name' }, [
                live ? chipLink(row.tag, row.name, 'x', row.color) : chip(row.name, 'x', row.color, row.status === 'pending' ? ' fchip-pending' : ''),
                el('span', { className: 'wl-small text-muted', text: row.tag })])]);
            if (row.description) nameCell.appendChild(el('div', { className: 'wl-small text-muted', text: row.description }));
            tr.appendChild(nameCell);
            const rc = row.category_id ? catOf(row.category_id) : null;
            tr.appendChild(el('td', { className: 'tags-cat-col' }, rc ? chip(rc.l, 'x', rc.color) : el('span', { className: 'wl-small text-muted', text: t.key('js.tags.k_none') })));
            const st = STATUS[row.status] || STATUS.approved;
            const stCell = el('td', { className: 'col-badge' }, el('span', { className: 'wl-badge ' + st[1] }, t.key(st[0])));
            if (row.hidden) stCell.appendChild(el('span', { className: 'wl-badge wl-b-muted ms-1' }, t.key('js.tags.st_hidden')));
            tr.appendChild(stCell);
            tr.appendChild(el('td', { className: 'font-mono' }, live && row.uses
                ? el('a', { href: searchHref(row.tag), target: '_blank', rel: 'noopener', title: t.key('js.tags.open_search') }, t.num(row.uses)) : t.num(row.uses || 0)));
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

    const F = { manage: false, on: true, clients: [], targets: [], q: '', page: {} };
    const PER = 25;
    const FSTATE = { proposed: ['js.tags.f_st_proposed', 'wl-b-pending'], approved: ['js.tags.f_st_approved', 'wl-b-ok'], ignored: ['js.tags.f_st_ignored', 'wl-b-muted'] };
    const FGROUPS = ['q', 'v', 'h', 'a', 's', 'l', 'g', 'x'];

    function btnText(cls, label, fn) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls }, label);
        b.addEventListener('click', fn);
        return b;
    }

    async function loadForum() {
        const r = await apiCall('admin/tag_forum');
        if (!r || r.error) return;
        F.manage = !!r.manage;
        F.on = r.on !== false;
        F.clients = r.clients || [];
        F.targets = r.targets || [];
        tabCount('forum', F.clients.reduce((s, c) => s + (c.proposed || 0), 0));
        renderForum();
    }

    async function forumAct(op, client, extra) {
        const r = await apiCall('admin/tag_action', 'POST', Object.assign({ op: op, client: client }, extra || {}));
        if (!r || !r.success) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); loadForum(); return; }
        showToast(t.key('js.tags.f_done', { n: t.num(r.n || 0) }), 'success');
        loadForum();
        load();
    }

    function targetSelect(c, row) {
        const sel = el('select', { className: 'form-select form-select-sm bg-dark text-light border-secondary tags-f-target', 'aria-label': t.key('js.tags.f_target', { name: row.name }) });
        const fresh = el('option', { value: 'new' }, t.key('js.tags.f_new', { name: row.name }));
        sel.appendChild(fresh);
        let picked = row.to && row.to.new ? fresh : null;
        const groups = {};
        F.targets.forEach(x => { (groups[x.g] = groups[x.g] || []).push(x); });
        FGROUPS.forEach(g => {
            if (!groups[g]) return;
            const og = el('optgroup', { label: t.key('js.filters.t_' + g) });
            groups[g].forEach(x => {
                const o = el('option', { value: x.id }, x.l);
                if (!picked && x.id === row.target) picked = o;
                og.appendChild(o);
            });
            sel.appendChild(og);
        });
        if (!picked) {
            picked = el('option', { value: row.target }, (row.to && row.to.l) || row.target);
            sel.insertBefore(picked, fresh.nextSibling);
        }
        picked.selected = true;
        sel.disabled = !F.manage;
        sel.addEventListener('change', () => forumAct('forum_target', c.id, { slug: row.slug, target: sel.value }));
        return sel;
    }

    function renderForum() {
        const box = $('tags-forum-body');
        if (!box) return;
        box.textContent = '';
        if (!F.on) box.appendChild(el('div', { className: 'wl-small text-warning mb-2', text: t.key('js.tags.f_off') }));
        if (!F.clients.length) { box.appendChild(el('div', { className: 'wl-small text-muted', text: t.key('js.tags.f_empty') })); return; }
        F.clients.forEach(c => {
            const head = el('div', { className: 'tags-f-head' }, [
                el('strong', { text: c.label }),
                el('span', { className: 'wl-badge ' + (c.on ? 'wl-b-ok' : 'wl-b-muted') }, t.key(c.on ? 'js.tags.f_import_on' : 'js.tags.f_import_off')),
            ]);
            if (c.proposed) head.appendChild(el('span', { className: 'wl-badge wl-b-pending' }, t.key('js.tags.f_n_proposed', { n: t.num(c.proposed) })));
            if (F.manage) {
                if (c.proposed) head.appendChild(btnText('btn-outline-success', t.key('js.tags.f_approve_all'), () => forumAct('forum_all', c.id)));
                const label = t.key(c.on ? 'js.tags.f_turn_off' : 'js.tags.f_turn_on');
                head.appendChild(btnText(c.on ? 'btn-outline-warning' : 'btn-success', label, async () => {
                    if (await confirmAction(label, t.key(c.on ? 'js.tags.f_off_confirm' : 'js.tags.f_on_confirm', { name: c.label }))) forumAct('forum_import', c.id, { on: !c.on });
                }));
            }
            box.appendChild(head);
            const q = F.q.toLowerCase();
            const rows = q ? c.rows.filter(row => [row.name, row.slug, row.parent || '', (row.to && row.to.l) || '', row.target].join(' ').toLowerCase().includes(q)) : c.rows;
            const pages = Math.max(1, Math.ceil(rows.length / PER));
            const page = Math.min(pages, F.page[c.id] || 1);
            if (!rows.length) {
                box.appendChild(el('div', { className: 'wl-small text-muted py-2', text: t.key('js.tags.f_none_match') }));
                return;
            }
            const tb = el('tbody', {});
            rows.slice((page - 1) * PER, page * PER).forEach(row => {
                const st = FSTATE[row.state] || FSTATE.proposed;
                const acts = el('td', { className: 'th-actions' });
                if (F.manage && row.state !== 'approved') acts.appendChild(btn('bi-check-lg', 'btn-outline-success', t.key('js.tags.approve'), () => forumAct('forum_approve', c.id, { slug: row.slug })));
                if (F.manage && row.state !== 'ignored') acts.appendChild(btn('bi-x-lg', 'btn-outline-warning', t.key('js.tags.f_ignore'), () => forumAct('forum_ignore', c.id, { slug: row.slug })));
                const to = row.to || {};
                const preview = to.new ? chip(to.l || row.name, 'x', row.color, ' fchip-pending', t.key('js.tags.f_new_title'))
                    : chipLink(row.target, to.l || row.target, to.g || 'x', to.c || null);
                tb.appendChild(el('tr', { className: row.state === 'ignored' ? 'tags-f-ignored' : null }, [
                    el('td', {}, el('div', { className: 'tags-name' }, [chip(row.name, 'forum', row.color),
                        el('span', { className: 'wl-small text-muted', text: row.parent ? row.parent + ' / ' + row.slug : row.slug })])),
                    el('td', { className: 'font-mono', text: t.num(row.n || 0) }),
                    el('td', {}, el('div', { className: 'tags-f-to-cell' }, [preview, targetSelect(c, row)])),
                    el('td', { className: 'col-badge' }, el('span', { className: 'wl-badge ' + st[1] }, t.key(st[0]))),
                    acts,
                ]));
            });
            box.appendChild(el('div', { className: 'table-responsive' }, el('table', { className: 'table table-dark table-hover wl-table dash-table tags-f-table' }, [
                el('thead', {}, el('tr', {}, [el('th', { text: t.key('js.tags.f_col_forum') }), el('th', { className: 'tags-f-n', text: t.key('js.tags.f_col_n') }),
                    el('th', { className: 'tags-f-to', text: t.key('js.tags.f_col_target') }), el('th', { className: 'col-badge', text: t.key('js.tags.f_col_state') }),
                    el('th', { className: 'th-actions', text: t.key('js.tags.f_col_actions') })])),
                tb,
            ])));
            const pager = el('div', { className: 'admin-pagination tags-f-pager' });
            box.appendChild(pager);
            renderPagination(pager, { total: rows.length, page, pages, onPage: (p) => { F.page[c.id] = p; renderForum(); } });
        });
    }

    const CH = { view: 'waiting', pending: [], recent: [], n: 0, review: 'open', page: 1 };
    const CACT = { add: 'js.tags.c_add', remove: 'js.tags.c_remove', approve: 'js.tags.c_approve', reject: 'js.tags.c_reject', block: 'js.tags.c_block' };

    async function loadChanges() {
        const r = await apiCall('admin/tag_changes');
        if (!r || r.error) return;
        CH.pending = r.pending || [];
        CH.recent = r.recent || [];
        CH.n = r.n_pending || 0;
        CH.review = r.review || 'open';
        tabCount('changes', CH.n);
        renderChanges();
    }

    async function changeAct(op, items, all) {
        const r = await apiCall('admin/tag_action', 'POST', { op: op, items: items, all: !!all });
        if (!r || !r.success) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); return; }
        showToast(t.key('js.tags.f_done', { n: t.num(r.n || 0) }), 'success');
        loadChanges();
        load();
    }

    function renderChanges() {
        const box = $('tags-changes-body');
        if (!box) return;
        box.textContent = '';
        const tabs = el('div', { className: 'tags-c-tabs' }, [
            btnText(CH.view === 'waiting' ? 'btn-info' : 'btn-outline-secondary', t.key('js.tags.c_waiting', { n: t.num(CH.n) }), () => { CH.view = 'waiting'; CH.page = 1; renderChanges(); }),
            btnText(CH.view === 'recent' ? 'btn-info' : 'btn-outline-secondary', t.key('js.tags.c_recent'), () => { CH.view = 'recent'; CH.page = 1; renderChanges(); }),
        ]);
        if (CH.view === 'waiting' && CH.n) tabs.appendChild(btnText('btn-outline-success', t.key('js.tags.c_approve_all'), () => changeAct('change_approve', [], true)));
        tabs.appendChild(el('span', { className: 'wl-small text-muted', text: t.key(CH.review === 'approve' ? 'js.tags.c_mode_approve' : 'js.tags.c_mode_open') }));
        box.appendChild(tabs);
        const waiting = CH.view === 'waiting';
        const rows = waiting ? CH.pending : CH.recent;
        if (!rows.length) {
            box.appendChild(el('div', { className: 'wl-small text-muted py-2', text: t.key(waiting ? 'js.tags.c_none_waiting' : 'js.tags.c_none') }));
            return;
        }
        const tb = el('tbody', {});
        const pages = Math.max(1, Math.ceil(rows.length / PER));
        CH.page = Math.min(pages, CH.page);
        rows.slice((CH.page - 1) * PER, CH.page * PER).forEach(row => {
            const item = [{ hash: row.hash, tag: row.tag }];
            const acts = el('td', { className: 'th-actions' });
            if (waiting) {
                acts.appendChild(btn('bi-check-lg', 'btn-outline-success', t.key('js.tags.approve'), () => changeAct('change_approve', item)));
                acts.appendChild(btn('bi-x-lg', 'btn-outline-warning', t.key('js.tags.reject'), () => changeAct('change_reject', item)));
            } else if (row.action === 'add' || row.action === 'approve') {
                if (row.still === null || row.still === undefined) acts.appendChild(el('span', { className: 'wl-small text-muted', text: t.key('js.tags.c_gone') }));
                else acts.appendChild(btn('bi-x-lg', 'btn-outline-warning', t.key('js.tags.c_take_off'), () => changeAct('change_remove', item)));
            }
            const c = row.chip || {};
            const cells = [
                el('td', { className: 'wl-small', text: fmtDate(row.at) }),
                el('td', { className: 'wl-small', text: row.who || t.key('js.tags.c_panel') }),
            ];
            if (!waiting) cells.push(el('td', { className: 'col-badge' }, el('span', { className: 'wl-badge ' + (row.action === 'add' || row.action === 'approve' ? 'wl-b-ok' : 'wl-b-muted') }, t.key(CACT[row.action] || 'js.tags.c_add'))));
            cells.push(el('td', {}, chip(c.l || row.tag, c.g || 'x', c.c || null, waiting ? ' fchip-pending' : '')),
                el('td', { className: 'tags-c-name' }, el('a', { href: SITE + '?action=search&hash=' + encodeURIComponent(row.hash), target: '_blank', rel: 'noopener' }, row.torrent || row.hash)),
                acts);
            tb.appendChild(el('tr', {}, cells));
        });
        const head = [el('th', { className: 'tags-c-when', text: t.key('js.tags.c_when') }), el('th', { className: 'tags-c-who', text: t.key('js.tags.c_who') })];
        if (!waiting) head.push(el('th', { className: 'col-badge', text: t.key('js.tags.c_what') }));
        head.push(el('th', { className: 'tags-c-tag', text: t.key('js.tags.c_tag') }), el('th', { text: t.key('js.tags.c_torrent') }),
            el('th', { className: 'th-actions', text: t.key('js.tags.f_col_actions') }));
        box.appendChild(el('div', { className: 'table-responsive' }, el('table', { className: 'table table-dark table-hover wl-table dash-table tags-c-table' }, [el('thead', {}, el('tr', {}, head)), tb])));
        const pager = el('div', { className: 'admin-pagination tags-f-pager' });
        box.appendChild(pager);
        renderPagination(pager, { total: rows.length, page: CH.page, pages, onPage: (p) => { CH.page = p; renderChanges(); } });
    }

    async function loadCats() {
        const r = await apiCall('admin/tag_cats');
        if (!r || r.error) return;
        K.cats = r.cats || [];
        K.sugg = r.suggestions || [];
        K.manage = !!r.manage;
        tabCount('cats', K.sugg.length);
        catOptions($('tags-f-cat'), [['', t.key('js.tags.k_all')], ['none', t.key('js.tags.k_none')]]);
        catOptions($('tags-bulk-cat'), [['0', t.key('js.tags.k_none')]]);
        renderCats();
        render();
    }

    async function catAct(op, extra) {
        const r = await apiCall('admin/tag_action', 'POST', Object.assign({ op: op }, extra || {}));
        if (!r || !r.success) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); return false; }
        showToast(t.key('js.tags.done'), 'success');
        await loadCats();
        load();
        return true;
    }

    function editCat(tr, c) {
        const cell = tr.firstChild;
        cell.textContent = '';
        const name = el('input', { type: 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary', maxlength: '40', value: c.l, 'aria-label': t.key('js.tags.name') });
        if (c.grp) name.disabled = true;
        const color = el('input', { type: 'color', className: 'tags-cat-color', value: c.color || '#8fa3bf', 'aria-label': t.key('js.tags.color') });
        const save = el('button', { type: 'button', className: 'btn btn-sm btn-success' }, t.key('js.tags.save'));
        const cancel = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary' }, t.key('js.tags.cancel'));
        save.addEventListener('click', () => catAct('cat_save', { id: c.id, name: name.value, color: color.value }));
        cancel.addEventListener('click', () => renderCats());
        cell.append(el('div', { className: 'tags-edit' }, [name, el('div', { className: 'tags-edit-row' }, [color, save, cancel])]));
        name.focus();
    }

    function renderCats() {
        const box = $('tags-cats-body');
        box.textContent = '';
        if (K.manage) {
            const name = el('input', { type: 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary tags-cat-name', maxlength: '40', placeholder: t.key('js.tags.k_new_ph'), 'aria-label': t.key('js.tags.k_new_ph') });
            const color = el('input', { type: 'color', className: 'tags-cat-color', value: '#8fa3bf', 'aria-label': t.key('js.tags.color') });
            const add = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success' }, [el('i', { className: 'bi bi-plus-lg' }), ' ', t.key('js.tags.k_add')]);
            const go = async () => { if (name.value.trim() && await catAct('cat_add', { name: name.value, color: color.value })) name.value = ''; };
            add.addEventListener('click', go);
            name.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
            box.appendChild(el('div', { className: 'tags-f-tools' }, [name, color, add]));
        }
        const tb = el('tbody', {});
        K.cats.forEach(c => {
            const acts = el('td', { className: 'th-actions' });
            const tr = el('tr', {});
            if (K.manage) {
                acts.appendChild(btn('bi-arrow-up', 'btn-outline-secondary', t.key('js.tags.k_up'), () => catAct('cat_move', { id: c.id, dir: -1 })));
                acts.appendChild(btn('bi-arrow-down', 'btn-outline-secondary', t.key('js.tags.k_down'), () => catAct('cat_move', { id: c.id, dir: 1 })));
                acts.appendChild(btn('bi-pencil', 'btn-outline-info', t.key('js.tags.edit'), () => editCat(tr, c)));
                if (!c.grp) acts.appendChild(btn('bi-trash', 'btn-outline-danger', t.key('js.tags.delete'), async () => {
                    if (await confirmAction(t.key('js.tags.delete'), t.key('js.tags.k_delete_confirm', { name: c.l, n: t.num(c.n) }), { danger: true })) catAct('cat_delete', { id: c.id });
                }));
            }
            const kind = c.grp ? 'js.tags.k_builtin' : (c.origin === 'system' ? 'js.tags.k_starter' : (c.origin === 'member' ? 'js.tags.k_from_member' : 'js.tags.k_from_mod'));
            const count = el('button', { type: 'button', className: 'btn btn-link btn-sm p-0 font-mono', title: t.key('js.tags.k_show') }, t.num(c.n));
            count.addEventListener('click', () => { S.cat = String(c.id); $('tags-f-cat').value = S.cat; S.page = 1; switchView('list'); load(); });
            tr.append(
                el('td', {}, el('div', { className: 'tags-name' }, [chip(c.l, 'x', c.color), el('span', { className: 'wl-small text-muted', text: c.slug })])),
                el('td', { className: 'wl-small' }, [t.key(kind), c.created_by_name ? ' · ' + c.created_by_name : null]),
                el('td', {}, count),
                acts);
            tb.appendChild(tr);
        });
        box.appendChild(el('div', { className: 'table-responsive' }, el('table', { className: 'table table-dark table-hover wl-table dash-table tags-k-table' }, [
            el('thead', {}, el('tr', {}, [el('th', { text: t.key('js.tags.k_col_cat') }), el('th', { text: t.key('js.tags.k_col_kind') }),
                el('th', { className: 'tags-f-n', text: t.key('js.tags.k_col_n') }), el('th', { className: 'th-actions', text: t.key('js.tags.f_col_actions') })])),
            tb,
        ])));
        box.appendChild(el('h3', { className: 'tags-f-title tags-k-sugg-title' }, [el('i', { className: 'bi bi-lightbulb' }), ' ', t.key('js.tags.k_sugg_title')]));
        if (!K.sugg.length) {
            box.appendChild(el('div', { className: 'wl-small text-muted', text: t.key('js.tags.k_sugg_none') }));
            return;
        }
        const sb = el('tbody', {});
        K.sugg.forEach(s => {
            const acts = el('td', { className: 'th-actions' });
            if (K.manage) {
                acts.appendChild(btn('bi-check-lg', 'btn-outline-success', t.key('js.tags.approve'), () => catAct('sugg_accept', { id: s.id })));
                acts.appendChild(btn('bi-x-lg', 'btn-outline-warning', t.key('js.tags.reject'), () => catAct('sugg_reject', { id: s.id })));
            }
            sb.appendChild(el('tr', {}, [
                el('td', {}, chipLink(s.tag, s.tag_name, 'x', s.tag_color)),
                el('td', {}, s.cat ? el('strong', { text: s.cat }) : el('span', {}, [el('span', { className: 'wl-badge wl-b-pending' }, t.key('js.tags.k_new_badge')), ' ', el('strong', { text: s.new || '' })])),
                el('td', { className: 'wl-small text-muted', text: s.now || t.key('js.tags.k_none') }),
                el('td', { className: 'wl-small' }, [el('div', { text: s.who || '—' }), el('div', { className: 'text-muted', text: fmtDate(s.at) })]),
                acts,
            ]));
        });
        box.appendChild(el('div', { className: 'table-responsive' }, el('table', { className: 'table table-dark table-hover wl-table dash-table tags-k-table' }, [
            el('thead', {}, el('tr', {}, [el('th', { text: t.key('js.tags.c_tag') }), el('th', { text: t.key('js.tags.k_col_proposed') }),
                el('th', { text: t.key('js.tags.k_col_now') }), el('th', { text: t.key('js.tags.c_who') }), el('th', { className: 'th-actions', text: t.key('js.tags.f_col_actions') })])),
            sb,
        ])));
    }

    function init() {
        document.querySelectorAll('#tags-tabs .source-tab').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));
        const want = new URLSearchParams(location.search).get('view');
        let stored = '';
        try { stored = localStorage.getItem('thx_tags_view') || ''; } catch (e) {}
        switchView(want || (new URLSearchParams(location.search).get('status') === 'pending' ? 'list' : stored) || 'list');
        $('tags-f-cat').addEventListener('change', (e) => { S.cat = e.target.value; S.page = 1; load(); });
        $('tags-check-all').addEventListener('change', (e) => { S.rows.forEach(r => { if (e.target.checked) S.sel.add(r.id); else S.sel.delete(r.id); }); render(); syncBulk(); });
        $('tags-bulk-clear').addEventListener('click', () => { S.sel.clear(); render(); syncBulk(); });
        $('tags-bulk-delete').addEventListener('click', async () => {
            if (!S.sel.size) return;
            if (!await confirmAction(t.key('js.tags.delete'), t.key('js.tags.bulk_delete_confirm', { n: t.num(S.sel.size) }), { danger: true })) return;
            if (await act('delete', [...S.sel])) { S.sel.clear(); syncBulk(); }
        });
        $('tags-bulk-move').addEventListener('click', async () => {
            if (!S.sel.size) return;
            if (await catAct('cat_assign', { ids: [...S.sel], category: Number($('tags-bulk-cat').value) })) { S.sel.clear(); syncBulk(); }
        });
        const q = $('tags-f-q');
        q.addEventListener('input', debounce(() => { S.q = q.value.trim(); S.page = 1; load(); }, DEBOUNCE.search));
        $('tags-f-status').addEventListener('change', (e) => { S.status = e.target.value; S.page = 1; load(); });
        $('tags-f-sort').addEventListener('change', (e) => { S.sort = e.target.value; S.page = 1; load(); });
        if (new URLSearchParams(location.search).get('status') === 'pending') { S.status = 'pending'; $('tags-f-status').value = 'pending'; }
        const fq = $('tags-f-search');
        if (fq) fq.addEventListener('input', debounce(() => { F.q = fq.value.trim(); F.page = {}; renderForum(); }, DEBOUNCE.search));
        const refresh = $('tags-f-refresh');
        if (refresh) refresh.addEventListener('click', async () => {
            refresh.disabled = true;
            const r = await apiCall('admin/tag_action', 'POST', { op: 'forum_refresh' });
            refresh.disabled = false;
            if (!r || !r.success) { showToast((r && (r.message || r.error)) || t.key('js.tags.failed'), 'error'); return; }
            showToast(t.key('js.tags.f_refreshed', { n: t.num(r.n || 0), added: t.num(r.added || 0), removed: t.num(r.removed || 0) }), 'success');
            loadForum();
            load();
        });
        load();
        loadCats();
        loadChanges();
        loadForum();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
