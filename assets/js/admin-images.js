(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, showToast, confirmAction, fmtDate, fmtBytes, renderPagination, debounce, DEBOUNCE } = A;
    const $ = (id) => document.getElementById(id);
    const site = document.body.dataset.site || '';

    const S = { view: 'list', offset: 0, per: 0, q: '', state: '', sort: '', qStatus: 'pending', qPage: 1, mayManage: false, rows: [] };
    const perPage = () => {
        const tracks = getComputedStyle($('img-grid')).gridTemplateColumns.split(' ').filter((x) => x && x !== 'none').length || 1;
        return Math.max(12, Math.min(120, tracks * Math.max(1, Math.round(48 / tracks))));
    };

    const word = (key, params) => t.key(key, params);
    const hostOf = (u) => { try { return new URL(u).host; } catch (e) { return String(u || ''); } };
    const say = (text, bad) => {
        const box = $('img-said');
        if (!box) return;
        box.textContent = text || '';
        box.classList.toggle('text-danger', !!bad);
    };
    const failWord = (j) => (j && (j.error || j.message)) || word('js.aimg.failed');

    function button(icon, label, cls, onClick) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls, title: label, 'aria-label': label },
            [el('i', { className: 'bi ' + icon, 'aria-hidden': 'true' })]);
        b.addEventListener('click', () => onClick(b));
        return b;
    }

    function stateWord(s) {
        const k = 'js.aimg.st_' + String(s || '');
        return t.has(k) ? word(k) : String(s || '');
    }

    function stat(icon, value, label) {
        return el('div', { className: 'img-stat' }, [el('i', { className: 'bi ' + icon, 'aria-hidden': 'true' }),
            el('div', null, [el('div', { className: 'img-stat-v', text: value }), el('div', { className: 'img-stat-l', text: label })])]);
    }

    function summary(sum, hosts) {
        const box = $('img-summary');
        if (!box || !sum) return;
        const q = sum.queue || {};
        box.replaceChildren(
            stat('bi-images', t.num(sum.images), word('js.aimg.stat_pictures')),
            stat('bi-hdd', fmtBytes(sum.bytes), word('js.aimg.stat_space')),
            stat('bi-globe2', t.num(sum.public), word('js.aimg.stat_public')),
            stat('bi-lock', t.num(Math.max(0, sum.images - sum.public)), word('js.aimg.stat_private')),
            stat('bi-cloud-download', t.num(sum.copies), word('js.aimg.stat_copied')),
            stat('bi-hourglass-split', t.num(Number(q.pending) || 0), word('js.aimg.stat_queue')));
        const badge = $('img-queue-badge');
        const waiting = (Number(q.pending) || 0) + (Number(q.failed) || 0);
        badge.textContent = waiting ? String(waiting) : '';
        badge.classList.toggle('d-hidden', !waiting);
        const hb = $('img-hosts');
        if (hb) hb.textContent = hosts && hosts.length ? word('js.aimg.hosts', { list: hosts.join(', ') }) : word('js.aimg.hosts_none');
    }

    let usedModal = null;
    let usedFrom = null;
    let usedSeq = 0;
    async function showUsed(r, b) {
        if (!usedModal) {
            usedModal = el('div', { className: 'modal img-used-modal', id: 'img-used-modal', tabindex: '-1', 'aria-labelledby': 'img-used-title' }, [
                el('div', { className: 'modal-dialog modal-dialog-centered modal-dialog-scrollable' }, [
                    el('div', { className: 'modal-content bg-dark' }, [
                        el('div', { className: 'modal-header border-secondary' }, [
                            el('h5', { className: 'modal-title text-light', id: 'img-used-title' }),
                            el('button', { type: 'button', className: 'btn-close btn-close-white', 'data-bs-dismiss': 'modal', 'aria-label': word('js.common.close') }),
                        ]),
                        el('div', { className: 'modal-body', id: 'img-used-body' }),
                    ]),
                ]),
            ]);
            document.body.appendChild(usedModal);
            usedModal.addEventListener('hidden.bs.modal', () => {
                usedSeq++;
                if (usedFrom && usedFrom.isConnected) usedFrom.focus();
                usedFrom = null;
            });
        }
        const mine = ++usedSeq;
        usedFrom = b;
        $('img-used-title').textContent = word('js.aimg.where') + ' — #' + r.id;
        const box = $('img-used-body');
        box.textContent = word('js.common.loading');
        bootstrap.Modal.getOrCreateInstance(usedModal).show();
        const j = await apiCall('admin/images&view=used&id=' + encodeURIComponent(r.id));
        if (mine !== usedSeq) return;
        box.textContent = '';
        if (!j || !j.success) {
            box.appendChild(el('div', { className: 'text-danger', text: failWord(j) }));
            return;
        }
        if (!j.used.length) {
            box.appendChild(el('div', { className: 'text-muted', text: word('js.aimg.unused') }));
            return;
        }
        const ul = el('ul', { className: 'img-used-list' });
        j.used.forEach((u) => {
            let what = '';
            if (u.name) what = u.name;
            else if (u.hash) what = u.hash.slice(0, 12) + '…';
            else if (u.page) what = u.page + ' (' + u.lang + ')';
            const a = el('a', { href: site + u.link, target: '_blank', rel: 'noopener', text: word('js.aimg.kind_' + u.kind) + (what ? ': ' + what : '') });
            ul.appendChild(el('li', null, [a, ' ', el('span', { className: 'text-muted', text: stateWord(u.state) })]));
        });
        box.appendChild(ul);
    }

    async function remove(r, b) {
        b.disabled = true;
        const u = await apiCall('admin/images&view=used&id=' + encodeURIComponent(r.id));
        b.disabled = false;
        const places = u && u.success && Array.isArray(u.used) ? u.used.length : 0;
        const ask = places ? t.plural(places, 'js.aimg.delete_used', { id: r.id, n: t.num(places) }) : word('js.aimg.delete_q', { id: r.id });
        const ok = await confirmAction(word('js.aimg.delete_title'), ask, { okLabel: word('js.aimg.delete') });
        if (!ok) return;
        b.disabled = true;
        const j = await apiCall('admin/image_action', 'POST', { op: 'delete', id: r.id });
        if (j && j.success) {
            showToast(j.message || word('js.aimg.deleted'), 'success');
            load();
            return;
        }
        b.disabled = false;
        showToast(failWord(j), 'danger');
    }

    function zoom(r) {
        if (!window.SiteLightbox) {
            window.open(r.full_url, '_blank', 'noopener');
            return;
        }
        const list = S.rows.map((x) => ({ src: x.full_url, href: x.full_url, alt: x.name || ('#' + x.id) }));
        window.SiteLightbox.get().show(list, Math.max(0, S.rows.indexOf(r)), null);
    }

    function card(r) {
        const img = el('img', { src: r.url, alt: '', loading: 'lazy', decoding: 'async' });
        if (r.w > 0 && r.h > 0) { img.width = r.w; img.height = r.h; }
        const flags = el('span', { className: 'img-flags' }, [
            el('span', { className: 'img-flag ' + (r.used ? 'img-flag-pub' : 'img-flag-priv'), title: r.used && r.public_at ? fmtDate(r.public_at) : '',
                text: word(r.used ? 'js.aimg.public' : 'js.aimg.private') }),
            r.animated ? el('span', { className: 'img-flag', text: 'GIF' }) : null,
        ]);
        const thumb = el('a', { className: 'img-thumb', href: r.full_url, target: '_blank', rel: 'noopener', title: word('js.aimg.open_full') }, [img, flags]);
        thumb.addEventListener('click', (e) => {
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
            e.preventDefault();
            zoom(r);
        });
        let who;
        if (r.source === 'copy') who = el('span', { className: 'img-who', title: r.source_url, text: word('js.aimg.copied_from', { host: hostOf(r.source_url) }) });
        else if (r.user) who = el('span', { className: 'img-who', text: word('js.aimg.by', { name: r.user }) });
        else who = el('span', { className: 'img-who text-muted', text: word('js.aimg.no_owner') });
        const meta = el('div', { className: 'img-meta' }, [
            el('div', { className: 'img-line img-top' }, [el('strong', { text: '#' + r.id }), ' ',
                el('span', { className: 'text-muted', text: r.full_w + '×' + r.full_h + ' · ' + fmtBytes(r.bytes) })]),
            el('div', { className: 'img-line' }, [who]),
            el('div', { className: 'img-line text-muted', text: fmtDate(r.created_at) }),
            r.name ? el('div', { className: 'img-line text-muted img-name', title: r.name, text: r.name }) : null,
        ]);
        const whereBtn = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary img-where', title: word('js.aimg.where'), 'aria-haspopup': 'dialog' },
            [el('i', { className: 'bi bi-diagram-3', 'aria-hidden': 'true' }), ' ', word('js.aimg.where_short')]);
        whereBtn.addEventListener('click', () => showUsed(r, whereBtn));
        const acts = el('div', { className: 'img-acts' }, [
            whereBtn,
            button('bi-box-arrow-up-right', word('js.aimg.open_full'), 'btn-outline-secondary', () => window.open(r.full_url, '_blank', 'noopener')),
            S.mayManage ? button('bi-trash', word('js.aimg.delete'), 'btn-outline-danger img-del', (b) => remove(r, b)) : null,
        ]);
        return el('div', { className: 'img-card', id: 'img-card-' + r.id }, [thumb, meta, acts]);
    }

    async function load(keep) {
        const grid = $('img-grid');
        S.per = keep || perPage();
        const p = new URLSearchParams({ offset: String(S.offset), per: String(S.per) });
        if (S.q) p.set('q', S.q);
        if (S.state) p.set('state', S.state);
        if (S.sort) p.set('sort', S.sort);
        const j = await apiCall('admin/images&' + p.toString());
        grid.textContent = '';
        if (!j || !j.success) {
            grid.appendChild(el('div', { className: 'text-danger py-3', text: failWord(j) }));
            return;
        }
        S.mayManage = !!j.may_manage;
        summary(j.summary, j.hosts);
        $('img-total').textContent = t.plural(j.total, 'js.aimg.total', { n: t.num(j.total) });
        S.rows = j.rows || [];
        S.offset = Number(j.offset) || 0;
        if (!S.rows.length) grid.appendChild(el('div', { className: 'text-muted py-4 img-none', text: word('js.aimg.none') }));
        S.rows.forEach((r) => grid.appendChild(card(r)));
        const per = Number(j.per_page) || S.per;
        renderPagination($('img-pagination'), { total: j.total, page: j.page, pages: j.pages, onPage: (n) => { S.offset = (n - 1) * per; load(per); } });
    }

    async function queueAct(op, row, b) {
        b.disabled = true;
        const j = await apiCall('admin/image_action', 'POST', { op, id: row.id });
        if (j && j.success) {
            showToast(j.message || word('js.aimg.done'), 'success');
            loadQueue();
            return;
        }
        b.disabled = false;
        showToast(failWord(j), 'danger');
    }

    async function loadQueue() {
        const body = $('img-q-body');
        const p = new URLSearchParams({ view: 'queue', status: S.qStatus, page: String(S.qPage) });
        $('img-q-recheck').hidden = true;
        const j = await apiCall('admin/images&' + p.toString());
        body.textContent = '';
        if (!j || !j.success) {
            body.appendChild(el('tr', null, el('td', { colspan: 5, className: 'text-danger py-3', text: failWord(j) })));
            return;
        }
        S.mayManage = !!j.may_manage;
        $('img-q-recheck').hidden = !S.mayManage || S.qStatus !== 'done';
        summary(j.summary, j.hosts);
        $('img-q-total').textContent = t.plural(j.total, 'js.aimg.copies', { n: t.num(j.total) });
        if (!j.rows.length) body.appendChild(el('tr', null, el('td', { colspan: 5, className: 'text-muted py-4 text-center', text: word('js.aimg.q_none') })));
        j.rows.forEach((r) => {
            const url = el('td', { className: 'img-q-url' }, [el('a', { href: r.url, target: '_blank', rel: 'noopener noreferrer', text: r.url, title: r.url })]);
            const st = [el('span', { className: 'badge-table ' + (r.status === 'done' ? 'wl-b-ok' : (r.status === 'failed' ? 'wl-b-bad' : 'wl-b-pending')), text: stateWord(r.status) })];
            if (r.error) {
                const http = /^http_(\d{3})$/.exec(r.error);
                const text = http ? word('js.aimg.err_http', { code: http[1] }) : (t.has('js.aimg.err_' + r.error) ? word('js.aimg.err_' + r.error) : r.error);
                st.push(' ', el('span', { className: 'text-muted wl-small', text }));
            }
            const when = r.status === 'done' ? fmtDate(r.done_at) : (r.status === 'pending' ? word('js.aimg.next', { at: fmtDate(r.next_at) }) : fmtDate(r.created_at));
            const acts = el('td', { className: 'wl-actions' });
            if (r.status === 'done' && r.image_id) {
                acts.appendChild(button('bi-image', word('js.aimg.show_image'), 'btn-outline-secondary', () => {
                    S.q = '#' + r.image_id;
                    $('img-f-q').value = S.q;
                    S.offset = 0;
                    show('list');
                }));
            }
            if (S.mayManage && (r.status === 'failed' || r.status === 'ignored')) acts.appendChild(button('bi-arrow-repeat', word('js.aimg.retry'), 'btn-outline-secondary', (b) => queueAct('retry', r, b)));
            if (S.mayManage && (r.status === 'pending' || r.status === 'failed')) acts.appendChild(button('bi-x-lg', word('js.aimg.forget'), 'btn-outline-danger', (b) => queueAct('forget', r, b)));
            body.appendChild(el('tr', null, [url, el('td', null, st), el('td', { className: 'wl-num', text: String(r.tries) }),
                el('td', { className: 'wl-date', text: when }), acts]));
        });
        renderPagination($('img-q-pagination'), { total: j.total, page: j.page, pages: j.pages, onPage: (n) => { S.qPage = n; loadQueue(); } });
    }

    function show(view) {
        S.view = view;
        document.querySelectorAll('#img-tabs .source-tab').forEach((b) => {
            const on = b.dataset.view === view;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        $('img-view-list').classList.toggle('d-hidden', view !== 'list');
        $('img-view-queue').classList.toggle('d-hidden', view !== 'queue');
        say('');
        if (view === 'queue') loadQueue(); else load();
    }

    document.querySelectorAll('#img-tabs .source-tab').forEach((b) => b.addEventListener('click', () => show(b.dataset.view)));
    const research = debounce(() => { S.q = $('img-f-q').value.trim(); S.offset = 0; load(); }, DEBOUNCE.search);
    $('img-f-q').addEventListener('input', research);
    $('img-f-state').addEventListener('change', (e) => { S.state = e.target.value; S.offset = 0; load(); });
    $('img-f-sort').addEventListener('change', (e) => { S.sort = e.target.value; S.offset = 0; load(); });
    window.addEventListener('resize', debounce(() => { if (S.view === 'list' && S.per && perPage() !== S.per) load(); }, 250));
    $('img-f-clear').addEventListener('click', () => {
        S.q = ''; S.state = ''; S.sort = ''; S.offset = 0;
        $('img-f-q').value = ''; $('img-f-state').value = ''; $('img-f-sort').value = '';
        load();
    });
    $('img-q-status').addEventListener('change', (e) => { S.qStatus = e.target.value; S.qPage = 1; loadQueue(); });
    $('img-q-recheck').addEventListener('click', async (e) => {
        const b = e.currentTarget;
        b.disabled = true;
        const j = await apiCall('admin/image_action', 'POST', { op: 'recheck' });
        b.disabled = false;
        if (j && j.success) showToast(j.message || word('js.aimg.done'), 'success');
        else showToast(failWord(j), 'error');
    });
    show(location.hash === '#queue' ? 'queue' : 'list');
})();
