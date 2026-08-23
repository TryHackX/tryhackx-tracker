/* Admin — observed-hash Index page (?action=admin-index). Uses window.AdminCommon helpers. */
(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, esc, showToast, confirmAction, flashTip, makeSortStack, renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard } = A;
    const $ = (id) => document.getElementById(id);

    const state = { page: 1, search: '', searchFiles: false, meta: '', life: '', sort: [{ col: 'last', dir: 'desc' }], selected: new Set(), rows: [] };
    let modal = null;

    // ── status card ──────────────────────────────────────────────────────────
    async function loadStatus() {
        try {
            const s = await apiCall('admin/index_status');
            renderStatus(s);
        } catch (e) { /* leave the spinner */ }
    }
    function tile(label, value, cls) {
        return el('div', { className: 'wl-kv' + (cls ? ' ' + cls : '') }, [el('span', { className: 'wl-kv-label', text: label }), el('span', { className: 'wl-kv-val', text: value })]);
    }
    function renderStatus(s) {
        const c = s.counts || {}, st = s.state || {};
        $('idx-disabled-note').style.display = s.enabled ? 'none' : '';
        const grid = $('idx-status-grid');
        grid.textContent = '';
        grid.appendChild(tile('Status', s.enabled ? 'Enabled' : 'Disabled', s.enabled ? 'ok' : 'warn'));
        grid.appendChild(tile('Rows', (c.total || 0).toLocaleString()));
        grid.appendChild(tile('Protected', (c.protected || 0).toLocaleString()));
        grid.appendChild(tile('In grace', (c.in_grace || 0).toLocaleString()));
        grid.appendChild(tile('Promoted', (c.promoted || 0).toLocaleString()));
        grid.appendChild(tile('Meta done', (c.meta_done || 0).toLocaleString()));
        grid.appendChild(tile('Meta pending', ((c.meta_pending || 0) + (c.meta_fetching || 0)).toLocaleString()));
        grid.appendChild(tile('Meta failed', (c.meta_failed || 0).toLocaleString()));
        grid.appendChild(tile('Files', (c.files || 0).toLocaleString()));
        grid.appendChild(tile('Poll every', s.poll_minutes + ' min'));
        grid.appendChild(tile('Meta budget', (st.meta_budget_used || 0) + ' / ' + s.meta_daily_budget + '/day'));
        const lp = st.last_poll;
        grid.appendChild(tile('Last poll', st.last_poll_at ? fmtDate(new Date(st.last_poll_at * 1000).toISOString()) : 'never'));
        if (lp) grid.appendChild(tile('Last poll result', `${(lp.entries || 0).toLocaleString()} seen · ${(lp.kept || 0).toLocaleString()} kept${lp.truncated ? ' (truncated)' : ''} · ${lp.ms || 0} ms`));
        if (st.last_error) grid.appendChild(tile('Last error', st.last_error, 'warn'));
        $('idx-status-updated').textContent = 'grace ' + s.grace_days + ' d · protect ' + s.protect_days + ' d · cap ' + (s.max_rows || 0).toLocaleString();
    }

    // ── list ─────────────────────────────────────────────────────────────────
    function sortParam() { return state.sort.map(s => s.col + ':' + s.dir).join(','); }
    async function load() {
        const qs = new URLSearchParams({ page: state.page, sort: sortParam() });
        if (state.search) qs.set('search', state.search);
        if (state.searchFiles) qs.set('search_files', '1');
        if (state.meta) qs.set('meta', state.meta);
        if (state.life) qs.set('life', state.life);
        let data;
        try { data = await apiCall('admin/fetch_index&' + qs.toString()); }
        catch (e) { showToast('Failed to load index: ' + e.message, 'error'); return; }
        // apiCall resolves on non-2xx too (error body carries .error) — don't render an auth/server error as "empty"
        if (data.error) { showToast('Failed to load index: ' + data.error, 'error'); return; }
        state.rows = data.rows || [];
        renderRows(data);
        $('idx-total').textContent = (data.total || 0).toLocaleString() + ' rows';
        renderPagination($('idx-pagination'), { total: data.total, page: data.page, pages: data.pages, onPage: (p) => { state.page = p; load(); } });
        syncBulkbar();
    }
    function metaBadge(status, err) {
        const map = { none: ['—', 'status-badge archived'], pending: ['pending', 'status-badge pending'], fetching: ['fetching', 'status-badge pending'], done: ['done', 'status-badge'], failed: ['failed', 'status-badge blocked'] };
        const m = map[status] || map.none;
        const b = el('span', { className: 'status-badge-sm ' + m[1], title: err || '' }, m[0]);
        return b;
    }
    function renderRows(data) {
        const tb = $('idx-body');
        tb.textContent = '';
        if (!state.rows.length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 9, className: 'text-center text-muted py-4', text: data.enabled ? 'No observed hashes yet — the poll fills this during OPEN hours.' : 'The index is disabled.' })));
            return;
        }
        state.rows.forEach(r => {
            const tr = el('tr', { className: state.selected.has(r.info_hash) ? 'table-active' : null });
            const cb = el('input', { type: 'checkbox', className: 'idx-row-check' });
            cb.checked = state.selected.has(r.info_hash);
            cb.addEventListener('change', () => { if (cb.checked) state.selected.add(r.info_hash); else state.selected.delete(r.info_hash); tr.classList.toggle('table-active', cb.checked); syncBulkbar(); });
            tr.appendChild(el('td', {}, cb));
            const hashShort = r.info_hash.slice(0, 12) + '…';
            const hashCell = el('td', { className: 'wl-hash-cell font-mono', title: r.info_hash }, [
                el('span', { text: hashShort }),
                el('button', { type: 'button', className: 'btn btn-sm wl-copy', title: 'Copy hash' }, el('i', { className: 'bi bi-clipboard' })),
            ]);
            hashCell.querySelector('button').addEventListener('click', (e) => { e.stopPropagation(); copyToClipboard(r.info_hash, e.currentTarget); });
            tr.appendChild(hashCell);
            const badges = [];
            if (r.protected) badges.push(el('span', { className: 'status-badge-sm status-badge', title: 'Protected (active + metadata)', text: '🛡' }));
            if (r.promoted) badges.push(el('span', { className: 'status-badge-sm status-badge', title: 'Promoted to whitelist', text: '★' }));
            tr.appendChild(el('td', { className: 'wl-name-cell' }, [el('span', { text: r.name || '—', title: r.name || '' }), ...badges]));
            tr.appendChild(el('td', { className: 'font-mono', text: r.total_size ? fmtBytes(r.total_size) : '—' }));
            const sl = (r.scrape_seeders != null ? r.scrape_seeders : r.last_seeders) + ' / ' + (r.scrape_leechers != null ? r.scrape_leechers : r.last_leechers);
            tr.appendChild(el('td', { className: 'font-mono', title: 'peak seeders ' + (r.peak_seeders || 0) }, sl));
            tr.appendChild(el('td', { className: 'font-mono', text: String(r.seen_count || 0) }));
            tr.appendChild(el('td', { className: 'idx-dates' }, [el('span', { className: 'text-muted', text: fmtDate(r.first_seen) }), el('br'), el('span', { text: fmtDate(r.last_seen) })]));
            tr.appendChild(el('td', {}, metaBadge(r.meta_status, r.meta_error)));
            const act = el('td', { className: 'th-actions' });
            const view = el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', title: 'Details' }, el('i', { className: 'bi bi-eye' }));
            view.addEventListener('click', () => openModal(r.info_hash));
            const promote = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success', title: 'Promote to whitelist' }, el('i', { className: 'bi bi-arrow-up-circle' }));
            promote.addEventListener('click', () => promoteHashes([r.info_hash]));
            act.appendChild(view); act.appendChild(promote);
            tr.appendChild(act);
            tr.addEventListener('click', (e) => { if (e.target.closest('button') || e.target.closest('input')) return; openModal(r.info_hash); });
            tb.appendChild(tr);
        });
        const all = $('idx-check-all');
        all.checked = state.rows.length > 0 && state.rows.every(r => state.selected.has(r.info_hash));
    }

    function syncBulkbar() {
        const n = state.selected.size;
        $('idx-bulkbar').classList.toggle('d-hidden', n === 0);
        $('idx-sel-count').textContent = n + ' selected';
    }

    // ── modal ──────────────────────────────────────────────────────────────
    async function openModal(hash) {
        modal = modal || bootstrap.Modal.getOrCreateInstance($('idxModal'));
        const body = $('idx-modal-body');
        body.textContent = ''; body.appendChild(el('div', { className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' Loading…']));
        modal.show();
        let d;
        try { d = await apiCall('admin/index_item&hash=' + encodeURIComponent(hash)); }
        catch (e) { body.textContent = ''; body.appendChild(el('div', { className: 'text-danger', text: 'Failed: ' + e.message })); return; }
        const it = d.item || {};
        body.textContent = '';
        const kv = (k, v) => el('div', { className: 'idx-mkv' }, [el('span', { className: 'idx-mkv-k', text: k }), el('span', { className: 'idx-mkv-v', text: v })]);
        const grid = el('div', { className: 'idx-mgrid' }, [
            kv('Info hash', it.info_hash),
            kv('Name', it.name || '—'),
            kv('Size', it.total_size ? fmtBytes(it.total_size) + ' (' + (it.files_count || 0) + ' files)' : '—'),
            kv('Seeders / leechers', (it.scrape_seeders != null ? it.scrape_seeders : it.last_seeders) + ' / ' + (it.scrape_leechers != null ? it.scrape_leechers : it.last_leechers)),
            kv('Peak seeders', String(it.peak_seeders || 0)),
            kv('Seen count', String(it.seen_count || 0)),
            kv('First seen', fmtDate(it.first_seen)),
            kv('Last seen', fmtDate(it.last_seen)),
            kv('Grace until', fmtDate(it.grace_until)),
            kv('Protected until', it.protected_until ? fmtDate(it.protected_until) : '—'),
            kv('Metadata', it.meta_status + (it.meta_error ? ' — ' + it.meta_error : '')),
            kv('Promoted', it.promoted_at ? fmtDate(it.promoted_at) : 'no'),
        ]);
        body.appendChild(grid);
        if (d.whitelisted) body.appendChild(el('div', { className: 'alert alert-info py-1 px-2 my-2', text: 'Already in the whitelist.' }));
        if (d.banned) body.appendChild(el('div', { className: 'alert alert-warning py-1 px-2 my-2', text: 'This hash is banned.' }));
        // magnet
        const magWrap = el('div', { className: 'idx-magnet my-2' }, [
            el('input', { className: 'form-control form-control-sm bg-dark text-light border-secondary font-mono', readonly: true, value: d.magnet }),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', title: 'Copy magnet' }, el('i', { className: 'bi bi-clipboard' })),
        ]);
        magWrap.querySelector('button').addEventListener('click', (e) => copyToClipboard(d.magnet, e.currentTarget));
        body.appendChild(magWrap);
        // actions
        const actions = el('div', { className: 'd-flex flex-wrap gap-2 my-2' }, [
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-success', id: 'm-promote' }, [el('i', { className: 'bi bi-arrow-up-circle' }), ' Promote → whitelist']),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', id: 'm-meta' }, [el('i', { className: 'bi bi-cloud-download' }), ' Fetch metadata']),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', id: 'm-scrape' }, [el('i', { className: 'bi bi-arrow-repeat' }), ' Refresh S/L']),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', id: 'm-delete' }, [el('i', { className: 'bi bi-trash' }), ' Delete']),
        ]);
        body.appendChild(actions);
        actions.querySelector('#m-promote').addEventListener('click', () => promoteHashes([hash], true));
        actions.querySelector('#m-meta').addEventListener('click', async () => { try { const r = await apiCall('admin/index_fetch_meta', 'POST', { hashes: [hash] }); if (!r.success || r.error) { showToast(r.error || 'Queue failed', 'error'); return; } showToast('Queued ' + r.queued + ' for metadata'); } catch (e) { showToast(e.message, 'error'); } });
        actions.querySelector('#m-scrape').addEventListener('click', async (e) => { try { const r = await apiCall('admin/index_scrape', 'POST', { hash }); if (r.success) { showToast('Scraped: ' + r.scrape.seeders + ' / ' + r.scrape.leechers); openModal(hash); } else showToast(r.error || 'Scrape failed', 'warning'); } catch (er) { showToast(er.message, 'error'); } });
        actions.querySelector('#m-delete').addEventListener('click', async () => { if (await confirmAction('Delete index entry', 'Remove this hash from the index?', { danger: true, okLabel: 'Delete' })) { try { const r = await apiCall('admin/index_delete', 'POST', { hashes: [hash] }); if (!r.success || r.error) { showToast(r.error || 'Delete failed', 'error'); return; } showToast('Deleted'); modal.hide(); state.selected.delete(hash); load(); loadStatus(); } catch (e) { showToast(e.message, 'error'); } } });
        // files
        if (d.files && d.files.length) {
            const list = el('div', { className: 'idx-files mt-2' }, [el('h6', { className: 'text-muted', text: 'Files (' + d.files.length + (d.files_truncated ? '+' : '') + ')' })]);
            const ul = el('ul', { className: 'idx-file-list' });
            d.files.forEach(f => ul.appendChild(el('li', {}, [el('span', { className: 'idx-file-path', text: f.path, title: f.path }), el('span', { className: 'idx-file-size text-muted', text: fmtBytes(f.size) })])));
            list.appendChild(ul);
            body.appendChild(list);
        }
    }

    // ── bulk actions ─────────────────────────────────────────────────────────
    async function promoteHashes(hashes, fromModal) {
        if (!hashes.length) return;
        if (!(await confirmAction('Promote to whitelist', 'Add ' + hashes.length + ' hash(es) to the whitelist (source: admin)? They will be served by the tracker.', { okLabel: 'Promote' }))) return;
        try {
            const r = await apiCall('admin/index_promote', 'POST', { hashes });
            if (!r.success || r.error) { showToast(r.error || 'Promote failed', 'error'); return; }
            showToast('Promoted ' + r.promoted + (r.summary ? ' (added ' + (r.summary.added || 0) + ', existed ' + (r.summary.exists || 0) + ')' : ''));
            if (fromModal && modal) modal.hide();
            hashes.forEach(h => state.selected.delete(h));
            load(); loadStatus();
        } catch (e) { showToast(e.message, 'error'); }
    }
    async function deleteSelected() {
        const hashes = [...state.selected];
        if (!hashes.length) return;
        if (!(await confirmAction('Delete from index', 'Delete ' + hashes.length + ' hash(es) from the index?', { danger: true, okLabel: 'Delete' }))) return;
        try { const r = await apiCall('admin/index_delete', 'POST', { hashes }); if (!r.success || r.error) { showToast(r.error || 'Delete failed', 'error'); return; } showToast('Deleted ' + r.removed); state.selected.clear(); load(); loadStatus(); } catch (e) { showToast(e.message, 'error'); }
    }
    async function metaSelected() {
        const hashes = [...state.selected];
        if (!hashes.length) return;
        try { const r = await apiCall('admin/index_fetch_meta', 'POST', { hashes }); if (!r.success || r.error) { showToast(r.error || 'Queue failed', 'error'); return; } showToast('Queued ' + r.queued + ' for metadata'); } catch (e) { showToast(e.message, 'error'); }
    }
    async function metaScope(scope) {
        try { const r = await apiCall('admin/index_fetch_meta', 'POST', { scope }); if (!r.success || r.error) { showToast(r.error || 'Queue failed', 'error'); return; } showToast('Queued ' + r.queued + ' for metadata'); loadStatus(); } catch (e) { showToast(e.message, 'error'); }
    }
    async function scrapeBulk(scope) {
        const label = $('idx-scrape-label');
        const orig = label.textContent;
        let after = '', total = 0, guard = 0;
        try {
            do {
                const body = scope === 'page' ? { scope, hashes: state.rows.map(r => r.info_hash), after } : { scope, after };
                const r = await apiCall('admin/index_scrape_bulk', 'POST', body);
                total += r.scraped || 0;
                after = r.after || '';
                label.textContent = 'Scraped ' + total + (r.remaining ? ' (' + r.remaining + ' left)' : '');
                if (r.warning) { showToast(r.warning, 'warning'); break; }
                if (!r.truncated) break;
            } while (++guard < 500);
            showToast('Refreshed S/L for ' + total + ' hashes');
            load();
        } catch (e) { showToast(e.message, 'error'); }
        finally { label.textContent = orig; }
    }

    // ── wiring ─────────────────────────────────────────────────────────────
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
    function init() {
        makeSortStack({ table: $('idx-table'), defaultSort: [{ col: 'last', dir: 'desc' }], onChange: (stack) => { state.sort = stack; state.page = 1; load(); } }).bindHeaders();
        $('idx-search').addEventListener('input', debounce(() => { state.search = $('idx-search').value.trim(); state.page = 1; load(); }, 300));
        $('idx-search-clear').addEventListener('click', () => { $('idx-search').value = ''; state.search = ''; state.page = 1; load(); });
        $('idx-search-files').addEventListener('change', () => { state.searchFiles = $('idx-search-files').checked; state.page = 1; load(); });
        $('idx-filter-meta').addEventListener('change', () => { state.meta = $('idx-filter-meta').value; state.page = 1; load(); });
        $('idx-filter-life').addEventListener('change', () => { state.life = $('idx-filter-life').value; state.page = 1; load(); });
        $('idx-check-all').addEventListener('change', (e) => { state.rows.forEach(r => { if (e.target.checked) state.selected.add(r.info_hash); else state.selected.delete(r.info_hash); }); renderRows({ enabled: true }); syncBulkbar(); });
        $('btn-idx-promote').addEventListener('click', () => promoteHashes([...state.selected]));
        $('btn-idx-delete').addEventListener('click', deleteSelected);
        $('btn-idx-meta-sel').addEventListener('click', metaSelected);
        $('btn-idx-clearsel').addEventListener('click', () => { state.selected.clear(); renderRows({ enabled: true }); syncBulkbar(); });
        document.querySelectorAll('#idx-meta-bulk-group [data-meta-scope]').forEach(b => b.addEventListener('click', () => metaScope(b.dataset.metaScope)));
        $('btn-idx-scrape-bulk').addEventListener('click', () => scrapeBulk('page'));
        document.querySelectorAll('#idx-scrape-bulk-group [data-scrape-scope]').forEach(b => b.addEventListener('click', () => scrapeBulk(b.dataset.scrapeScope)));
        $('btn-idx-poll').addEventListener('click', async () => {
            const btn = $('btn-idx-poll'); btn.disabled = true;
            const orig = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Polling…';
            try { const r = await apiCall('admin/index_poll_now', 'POST', {}); if (r.success) showToast('Poll: ' + r.entries.toLocaleString() + ' seen, ' + r.kept.toLocaleString() + ' kept in ' + r.ms + ' ms' + (r.truncated ? ' (truncated)' : '')); else showToast(r.error || 'Poll failed', 'warning'); load(); loadStatus(); }
            catch (e) { showToast(e.message, 'error'); }
            finally { btn.disabled = false; btn.innerHTML = orig; }
        });
        const logout = $('btn-logout');
        if (logout) logout.addEventListener('click', async () => { try { await apiCall('admin/logout', 'POST', {}); } catch (e) {} location.href = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '') + '?action=admin'; });
        load(); loadStatus();
        setInterval(loadStatus, 30000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
