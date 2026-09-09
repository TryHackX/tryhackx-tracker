/* Admin — observed-hash Index page (?action=admin-index). Uses window.AdminCommon helpers. */
(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, esc, showToast, confirmAction, flashTip, makeSortStack, renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard, hashFromUrl, bindHashModal, animatedClear, bindSearchClear, buildFileTree, busyDot } = A;
    const $ = (id) => document.getElementById(id);

    const state = { page: 1, pages: 1, search: '', searchFiles: false, meta: '', life: '', sort: [{ col: 'last', dir: 'desc' }], selected: new Set(), rows: [] };
    const nearRadius = () => Math.max(1, parseInt(document.body.dataset.nearPages || '2', 10) || 2);
    // index_files_admin_mode. 'button' is what the panel has always done — one slice, then "Load the
    // whole file list"; 'scroll' fires that same second request when the operator reaches the end of
    // the tree; 'all' puts files_all=1 on the modal's FIRST request, so there is no second request
    // at all. Deliberately no paging endpoint behind any of this: both item endpoints scrape the
    // tracker live before they return files, so one request per slice would be one scrape per slice.
    // How big a slice is and how much "all" may mean are index_files_admin_batch / _max, applied by
    // the endpoint — the panel never sends a limit of its own.
    const filesMode = () => (['scroll', 'button', 'all'].includes(document.body.dataset.filesMode) ? document.body.dataset.filesMode : 'button');
    let modal = null;
    // magnet built client-side from the announce URLs on <body> (same as the public search page)
    const announces = [document.body.dataset.announce, document.body.dataset.announceHttps].filter(Boolean);
    function magnetFor(hash, name) {
        let m = 'magnet:?xt=urn:btih:' + hash;
        if (name) m += '&dn=' + encodeURIComponent(name);
        announces.forEach(u => { m += '&tr=' + encodeURIComponent(u); });
        return m;
    }

    // ── status card ──────────────────────────────────────────────────────────
    async function loadStatus() {
        try {
            const s = await apiCall('admin/index_status');
            // apiCall() NEVER THROWS on a non-2xx: it puts the status in `error` and returns.
            // So a transient 500 used to reach renderStatus() as an object with no counts in it,
            // and a live 1.9 M-row index was drawn as empty — which is exactly the sort of thing an
            // operator acts on. A failed poll leaves the last good numbers where they are and says
            // so, because "we could not ask" is a different fact from "there is nothing there".
            if (!s || s.error) { markStale(s && s.error); return; }
            stale = false;
            renderStatus(s);
        } catch (e) { markStale(t('js.index.could_not_reach')); }
    }

    /**
     * Say that the numbers on screen are the last ones we managed to fetch.
     *
     * Deliberately does NOT clear the card. The previous values are still the best information
     * available; blanking them would replace "slightly old" with "apparently nothing".
     */
    let stale = false;
    function markStale(why) {
        if (stale) return;
        stale = true;
        const box = document.getElementById('idx-status-card');
        if (!box) return;
        const n = el('div', { className: 'alert alert-warning py-2 wl-small mt-2 idx-stale',
            text: t('js.index.stale_status', { why: why ? ' (' + why + ')' : '' }) });
        if (!box.querySelector('.idx-stale')) box.appendChild(n);
    }
    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text }); }
    function kv(label, value) {
        return el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: label }), el('div', { className: 'wl-kv-value' }, value)]);
    }
    const num = (n) => (Number(n) || 0).toLocaleString();
    function renderStatus(s) {
        const c = s.counts || {}, st = s.state || {};
        $('idx-disabled-note').style.display = s.enabled ? 'none' : '';
        const grid = $('idx-status-grid');
        grid.textContent = '';
        grid.appendChild(kv(t('js.index.index'), [
            badge(s.enabled ? t('js.index.enabled') : t('js.index.disabled'), s.enabled ? 'wl-b-ok' : 'wl-b-warn'), ' ',
            el('span', { className: 'text-muted wl-small', text: t('js.index.not_whitelist') }),
        ]));
        const overCap = (c.total || 0) > (s.max_rows || 0);
        grid.appendChild(kv(t('js.index.db_rows'), [
            el('span', { text: t('js.index.n_total', { n: num(c.total) }) }), ' · ',
            el('span', { className: 'text-muted', text: t('js.index.cap_n', { n: num(s.max_rows) }) }),
            ...(overCap ? [' ', badge(t('js.index.over_cap'), 'wl-b-warn')] : []),
        ]));
        grid.appendChild(kv(t('js.index.lifecycle'), [
            el('span', { text: t('js.index.n_in_grace', { n: num(c.in_grace) }) }), ' · ',
            el('span', { text: t('js.index.n_protected', { n: num(c.protected) }) }), ' · ',
            el('span', { text: t('js.index.n_promoted', { n: num(c.promoted) }) }),
            el('div', { className: 'wl-small text-muted', text: t('js.index.grace_protect', { grace: s.grace_days, protect: s.protect_days }) }),
        ]));
        grid.appendChild(kv(t('js.index.metadata'), [
            el('span', { text: t('js.index.n_done', { n: num(c.meta_done) }) }), ' · ',
            el('span', { text: t('js.index.n_queued', { n: num((c.meta_pending || 0) + (c.meta_fetching || 0)) }) }), ' · ',
            el('span', { text: t('js.index.n_failed', { n: num(c.meta_failed) }) }),
            el('div', { className: 'wl-small text-muted', text: (s.meta_auto_queue ? t('js.index.auto_queue_on') : t('js.index.budget_today', { used: num(st.meta_budget_used), total: num(s.meta_daily_budget) })) + ' · ' + t('js.index.n_file_entries', { n: num(c.files) }) }),
        ]));
        // The lifecycle, as two rates side by side. A total that falls for days looks like data loss
        // until you can see that expiry and resolution are simply running at different speeds — and
        // that the grace window is the setting that decides which one wins.
        const fl = s.flow || {};
        if (fl.expiring_24h !== undefined) {
            const win = Number(s.grace_days) || 0;
            const cover = fl.days_to_cover;
            const mismatch = cover && win && cover > win;
            grid.appendChild(kv(t('js.index.lifecycle_rates'), [
                el('span', { text: t('js.index.n_resolved_24h', { n: num(fl.resolved_24h) }) }), ' · ',
                el('span', { className: mismatch ? 'text-warning' : '', text: t('js.index.n_expiring_24h', { n: num(fl.expiring_24h) }) }),
                el('div', {
                    className: 'wl-small text-muted',
                    text: cover
                        ? t('js.index.pass_rate', { cover, win }) +
                          (mismatch ? t('js.index.pass_rate_mismatch') : '')
                        : t('js.index.pass_rate_none'),
                }),
            ]));
        }
        // How long a stored file list may be, as the WORKER reports it — not as this page's own
        // settings would predict. The equivalent check for parallel fetches and fetch order lives in
        // whitelistStatus(), inside its whitelist-mode branch, so it never renders on an open
        // tracker; this is the page where index_files is actually a thing, so it says it here.
        const wk = s.worker || {};
        if (wk.max_files || wk.max_files_asked) {
            const parts = [el('span', {
                text: wk.max_files ? t('js.index.worker_files_running', { n: num(wk.max_files) })
                                   : t('js.index.worker_files_unknown'),
            })];
            const ws = wk.max_files_state || 'ok';
            if (ws === 'unsupported') {
                parts.push(el('div', { className: 'wl-small text-warning',
                    text: t('js.index.worker_files_unsupported', { asked: num(wk.max_files_asked) }) }));
            } else if (ws === 'over_max') {
                parts.push(el('div', { className: 'wl-small text-warning',
                    text: t('js.index.worker_files_over_max', { running: num(wk.max_files), asked: num(wk.max_files_asked), max: num(wk.max_files_max) }) }));
            } else if (ws === 'mismatch') {
                parts.push(el('div', { className: 'wl-small text-warning',
                    text: t('js.index.worker_files_mismatch', { running: num(wk.max_files), asked: num(wk.max_files_asked) }) }));
            } else if (!wk.max_files_asked) {
                parts.push(el('div', { className: 'wl-small text-muted',
                    text: t('js.index.worker_files_config', { n: num(wk.max_files_config || wk.max_files) }) }));
            }
            grid.appendChild(kv(t('js.index.worker_files'), parts));
        }
        const lp = st.last_poll;
        grid.appendChild(kv(t('js.index.poll'), [
            st.last_poll_at ? el('span', { text: fmtDate(new Date(st.last_poll_at * 1000).toISOString()) }) : badge(t('js.index.never'), 'wl-b-muted'),
            ...(lp && lp.truncated ? [' ', badge(t('js.index.truncated_resumes'), 'wl-b-pending')] : []),
            el('div', { className: 'wl-small text-muted', text: (lp ? t('js.index.poll_stats', { seen: num(lp.entries), kept: num(lp.kept), s: (lp.ms / 1000).toFixed(1) }) : '') + t('js.index.every_n_min', { n: s.poll_minutes }) }),
        ]));
        grid.appendChild(kv(t('js.index.last_error'), st.last_error
            ? [badge(t('js.index.error_badge'), 'wl-b-bad'), ' ', el('span', { className: 'wl-small', text: st.last_error })]
            : [badge(t('js.index.none'), 'wl-b-ok')]));
        // A transfer that ended early is NOT an error: what arrived was parsed and the resume cursor
        // moved, exactly as it does when the time budget stops a pass. Showing it as an error would
        // train the operator to ignore the error line; showing nothing would hide a tracker that is
        // mis-framing its own replies. So it gets its own row, and only while it is the last thing
        // that happened.
        const par = st.last_partial;
        if (par) {
            grid.appendChild(kv(t('js.index.partial_fetch'), [
                badge(t('js.index.kept_what_arrived'), 'wl-b-pending'), ' ',
                el('span', { className: 'wl-small', text: t('js.index.partial_detail', { n: num(par.entries), mib: (par.bytes / 1048576).toFixed(1), reason: par.reason }) }),
            ]));
        }
        $('idx-status-updated').textContent = t('js.index.source_url', { url: (s.source_url || '').replace(/^https?:\/\//, '') });
    }

    // ── list ─────────────────────────────────────────────────────────────────
    function sortParam() { return state.sort.map(s => s.col + ':' + s.dir).join(','); }
    function listQuery(page) {
        const qs = new URLSearchParams({ page: String(page), sort: sortParam() });
        const pp = $('idx-perpage');
        if (pp && pp.value !== '25') qs.set('per_page', pp.value);
        if (state.search) qs.set('search', state.search);
        if (state.searchFiles) qs.set('search_files', '1');
        if (state.meta) qs.set('meta', state.meta);
        if (state.life) qs.set('life', state.life);
        return qs;
    }
    let loadSeq = 0;
    async function load(silent = false) {
        const qs = listQuery(state.page);
        const my = ++loadSeq;   // rapid sort/filter clicks: only the newest response may render
        // visible feedback: user actions dim the table, silent live-refreshes only pulse the dot
        busyDot($('idx-total'), true);
        if (!silent) $('idx-table').classList.add('tbl-loading');
        const settle = () => { if (my === loadSeq) { busyDot($('idx-total'), false); $('idx-table').classList.remove('tbl-loading'); } };
        let data;
        try { data = await apiCall('admin/fetch_index&' + qs.toString()); }
        catch (e) { settle(); if (!silent) showToast(t('js.index.load_failed', { error: e.message }), 'error'); return; }
        if (my !== loadSeq) return;
        settle();
        // apiCall resolves on non-2xx too (error body carries .error) — don't render an auth/server error as "empty"
        if (data.error) { if (!silent) showToast(t('js.index.load_failed', { error: data.error }), 'error'); return; }
        state.rows = data.rows || [];
        state.pages = data.pages || 1;
        renderRows(data);
        $('idx-total').textContent = t('js.index.n_rows', { n: (data.total || 0).toLocaleString() });
        renderPagination($('idx-pagination'), { total: data.total, page: data.page, pages: data.pages, onPage: (p) => { state.page = p; load(); } });
        syncBulkbar();
    }
    function metaBadge(status, err) {
        const map = { none: ['—', 'status-badge archived'], pending: [t('js.index.meta_pending'), 'status-badge pending'], fetching: [t('js.index.meta_fetching'), 'status-badge pending'], done: [t('js.index.meta_done'), 'status-badge'], failed: [t('js.index.meta_failed'), 'status-badge blocked'] };
        const m = map[status] || map.none;
        const b = el('span', { className: 'status-badge-sm ' + m[1], title: err || '' }, m[0]);
        return b;
    }
    function renderRows(data) {
        const tb = $('idx-body');
        tb.textContent = '';
        if (!state.rows.length) {
            tb.appendChild(el('tr', {}, el('td', { colSpan: 10, className: 'text-center text-muted py-4', text: data.enabled ? t('js.index.empty_enabled') : t('js.index.empty_disabled') })));
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
                el('button', { type: 'button', className: 'btn btn-sm wl-copy', title: t('js.index.copy_hash') }, el('i', { className: 'bi bi-clipboard' })),
            ]);
            hashCell.querySelector('button').addEventListener('click', (e) => { e.stopPropagation(); copyToClipboard(r.info_hash, e.currentTarget); });
            tr.appendChild(hashCell);
            const badges = [];
            if (r.protected) badges.push(el('span', { className: 'status-badge-sm status-badge', title: t('js.index.protected_title'), text: '🛡' }));
            if (r.promoted) badges.push(el('span', { className: 'status-badge-sm status-badge', title: t('js.index.promoted_title'), text: '★' }));
            tr.appendChild(el('td', { className: 'wl-name-cell' }, [el('span', { text: r.name || '—', title: r.name || '' }), ...badges]));
            tr.appendChild(el('td', { className: 'font-mono', text: r.total_size ? fmtBytes(r.total_size) : '—' }));
            tr.appendChild(el('td', { className: 'font-mono', text: r.files_count != null ? String(r.files_count) : '—' }));
            const sl = (r.scrape_seeders != null ? r.scrape_seeders : r.last_seeders) + ' / ' + (r.scrape_leechers != null ? r.scrape_leechers : r.last_leechers);
            tr.appendChild(el('td', { className: 'font-mono', title: t('js.index.peak_seeders_n', { n: r.peak_seeders || 0 }) }, sl));
            tr.appendChild(el('td', { className: 'font-mono', text: String(r.seen_count || 0) }));
            tr.appendChild(el('td', { className: 'idx-dates' }, [el('span', { className: 'text-muted', text: fmtDate(r.first_seen) }), el('br'), el('span', { text: fmtDate(r.last_seen) })]));
            tr.appendChild(el('td', {}, metaBadge(r.meta_status, r.meta_error)));
            const act = el('td', { className: 'th-actions' });
            const view = el('button', { type: 'button', className: 'btn btn-sm btn-outline-info wl-act', title: t('js.index.details') }, el('i', { className: 'bi bi-eye' }));
            view.addEventListener('click', () => openModal(r.info_hash));
            const mag = el('a', { className: 'btn btn-sm btn-outline-secondary wl-act', title: t('js.index.open_magnet'), href: magnetFor(r.info_hash, r.name) }, el('i', { className: 'bi bi-magnet' }));
            const promote = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success wl-act', title: t('js.index.promote_whitelist') }, el('i', { className: 'bi bi-arrow-up-circle' }));
            promote.addEventListener('click', () => promoteHashes([r.info_hash]));
            act.appendChild(view); act.appendChild(mag); act.appendChild(promote);
            tr.appendChild(act);
            tr.addEventListener('click', (e) => { if (e.target.closest('button') || e.target.closest('input') || e.target.closest('a')) return; openModal(r.info_hash); });
            tb.appendChild(tr);
        });
        const all = $('idx-check-all');
        all.checked = state.rows.length > 0 && state.rows.every(r => state.selected.has(r.info_hash));
    }

    function syncBulkbar() {
        const n = state.selected.size;
        $('idx-bulkbar').classList.toggle('d-hidden', n === 0);
        $('idx-sel-count').textContent = t('js.index.n_selected', { n });
    }

    // ── modal ──────────────────────────────────────────────────────────────

    /**
     * The submitter's source link and description, as a block for a detail panel.
     *
     * Sits between the key/value grid and the file list: the numbers are what the panel is for, the
     * files are the long part, and this belongs in between. The description starts COLLAPSED — an
     * essay that pushes the file list below the fold helps nobody who came here to look at a torrent.
     *
     * Returns null when there is nothing to show, so callers can append it unconditionally.
     */
    function contentBlock(c) {
        if (!c) return null;
        const hasText = !!c.description_html;
        const hasLink = !!c.source_url;
        if (!hasText && !hasLink) return null;

        const wrap = el('div', { className: 'wl-content-block' });

        if (c.content_status && c.content_status !== 'approved') {
            wrap.appendChild(el('div', {
                className: 'wl-badge ' + (c.content_status === 'pending' ? 'wl-b-pending' : 'wl-b-warn'),
                text: c.content_status === 'pending'
                    ? t('js.index.content_pending')
                    : t('js.index.content_rejected', { note: c.rejected_note ? ' — ' + c.rejected_note : '' }),
            }));
        }

        if (hasLink) {
            const row = el('div', { className: 'rt-src-row' });
            row.appendChild(el('span', { className: 'wl-kv-label', text: t('js.index.source') }));
            const a = el('a', {
                className: 'rt-src-url', href: c.source_url, text: c.source_url,
                rel: 'nofollow noopener noreferrer ugc', target: '_blank',
                title: c.source_trusted ? t('js.index.source_trusted') : t('js.index.source_offsite'),
            });
            // Not our link. The confirmation is the panel's too: an administrator clicking through a
            // queue is exactly the person who should not open one by accident.
            if (!c.source_trusted) a.setAttribute('data-external', '1');
            row.appendChild(a);
            wrap.appendChild(row);
        }

        if (hasText) {
            const det = el('details', { className: 'rt-collapse' });
            det.appendChild(el('summary', { text: t('js.index.description') }));
            const body = el('div', { className: 'rt-body' });
            // Built on the server by includes/richtext.php from fully escaped input with a fixed tag
            // whitelist. Everything else in this file goes through el()/textContent.
            body.innerHTML = c.description_html;
            det.appendChild(body);
            wrap.appendChild(det);
        }
        return wrap;
    }

    // One row of the catalogue, addressable: ?action=admin-index&hash=<40 hex> opens this modal, and
    // the button it puts in the header copies that address back.
    let hashView = null;
    async function openModal(hash) {
        modal = modal || bootstrap.Modal.getOrCreateInstance($('idxModal'));
        hashView = hashView || bindHashModal($('idxModal'), 'admin-index');
        hashView.show(hash);
        const body = $('idx-modal-body');
        body.textContent = ''; body.appendChild(el('div', { className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' ' + t('js.common.loading')]));
        modal.show();
        let d;
        try { d = await apiCall('admin/index_item' + (filesMode() === 'all' ? '&files_all=1' : '') + '&hash=' + encodeURIComponent(hash)); }
        catch (e) { body.textContent = ''; body.appendChild(el('div', { className: 'text-danger', text: t('js.index.failed_error', { error: e.message }) })); return; }
        const it = d.item || {};
        body.textContent = '';
        const kv = (k, v) => el('div', { className: 'idx-mkv' }, [el('span', { className: 'idx-mkv-k', text: k }), el('span', { className: 'idx-mkv-v', text: v })]);
        const grid = el('div', { className: 'idx-mgrid' }, [
            kv(t('js.index.info_hash'), it.info_hash),
            kv(t('js.index.name'), it.name || '—'),
            kv(t('js.index.size'), it.total_size ? t('js.index.size_files', { size: fmtBytes(it.total_size), n: it.files_count || 0 }) : '—'),
            kv(t('js.index.seeders_leechers'), (it.scrape_seeders != null ? it.scrape_seeders : it.last_seeders) + ' / ' + (it.scrape_leechers != null ? it.scrape_leechers : it.last_leechers)),
            kv(t('js.index.peak_seeders'), String(it.peak_seeders || 0)),
            kv(t('js.index.seen_count'), String(it.seen_count || 0)),
            kv(t('js.index.first_seen'), fmtDate(it.first_seen)),
            kv(t('js.index.last_seen'), fmtDate(it.last_seen)),
            kv(t('js.index.grace_until'), fmtDate(it.grace_until)),
            kv(t('js.index.protected_until'), it.protected_until ? fmtDate(it.protected_until) : '—'),
            kv(t('js.index.metadata'), it.meta_status + (it.meta_error ? ' — ' + it.meta_error : '')),
            kv(t('js.index.promoted'), it.promoted_at ? fmtDate(it.promoted_at) : t('js.index.no')),
        ]);
        body.appendChild(grid);
        if (d.whitelisted) body.appendChild(el('div', { className: 'alert alert-info py-1 px-2 my-2', text: t('js.index.already_whitelisted') }));
        if (d.banned) body.appendChild(el('div', { className: 'alert alert-warning py-1 px-2 my-2', text: t('js.index.hash_banned') }));
        // magnet
        const magWrap = el('div', { className: 'idx-magnet my-2' }, [
            el('input', { className: 'form-control form-control-sm bg-dark text-light border-secondary font-mono', readonly: true, value: d.magnet }),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', title: t('js.index.copy_magnet') }, el('i', { className: 'bi bi-clipboard' })),
            // Open, not just copy. The row listing has had this since the page was written; the detail
            // view, which is where somebody actually stops to read, had only the clipboard.
            el('a', { className: 'btn btn-sm btn-outline-secondary', href: d.magnet,
                      title: t('js.index.open_in_client'), 'aria-label': t('js.index.open_magnet') },
               el('i', { className: 'bi bi-magnet' })),
        ]);
        magWrap.querySelector('button').addEventListener('click', (e) => copyToClipboard(d.magnet, e.currentTarget));
        body.appendChild(magWrap);
        const cb = contentBlock(d.content);
        if (cb) body.appendChild(cb);
        // actions
        const actions = el('div', { className: 'd-flex flex-wrap gap-2 my-2' }, [
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-success', id: 'm-promote' }, [el('i', { className: 'bi bi-arrow-up-circle' }), ' ' + t('js.index.btn_promote')]),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', id: 'm-meta' }, [el('i', { className: 'bi bi-cloud-download' }), ' ' + t('js.index.btn_fetch_meta')]),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', id: 'm-scrape' }, [el('i', { className: 'bi bi-arrow-repeat' }), ' ' + t('js.index.btn_refresh_sl')]),
            el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', id: 'm-delete' }, [el('i', { className: 'bi bi-trash' }), ' ' + t('js.index.delete')]),
        ]);
        body.appendChild(actions);
        actions.querySelector('#m-promote').addEventListener('click', () => promoteHashes([hash], true));
        actions.querySelector('#m-meta').addEventListener('click', async () => { try { const r = await apiCall('admin/index_fetch_meta', 'POST', { hashes: [hash] }); if (!r.success || r.error) { showToast(r.error || t('js.index.queue_failed'), 'error'); return; } showToast(t('js.index.queued_n_meta', { n: r.queued })); } catch (e) { showToast(e.message, 'error'); } });
        actions.querySelector('#m-scrape').addEventListener('click', async (e) => { try { const r = await apiCall('admin/index_scrape', 'POST', { hash }); if (r.success) { showToast(t('js.index.scraped_sl', { s: r.scrape.seeders, l: r.scrape.leechers })); openModal(hash); } else showToast(r.error || t('js.index.scrape_failed'), 'warning'); } catch (er) { showToast(er.message, 'error'); } });
        actions.querySelector('#m-delete').addEventListener('click', async () => { if (await confirmAction(t('js.index.delete_entry_title'), t('js.index.delete_entry_body'), { danger: true, okLabel: t('js.index.delete') })) { try { const r = await apiCall('admin/index_delete', 'POST', { hashes: [hash] }); if (!r.success || r.error) { showToast(r.error || t('js.index.delete_failed'), 'error'); return; } showToast(t('js.index.deleted')); modal.hide(); state.selected.delete(hash); load(); loadStatus(); } catch (e) { showToast(e.message, 'error'); } } });
        // files
        if (d.files && d.files.length) {
            const list = el('div', { className: 'idx-files mt-2' });
            // Two numbers, two lines apart, that nobody was comparing: the Size row above prints the
            // row's files_count (27 260) and this heading printed the stored list's length (5 000).
            // The worker only ever writes its first max_files paths, so for a big torrent those are
            // different numbers and the modal read as if the list were complete. Say both.
            const fill = (files, truncated, short, capped) => {
                const nodes = [el('h6', { className: 'text-muted', text: short && it.files_count
                    ? t('js.index.files_n_of', { n: files.length.toLocaleString(), total: Number(it.files_count).toLocaleString() })
                    : t('js.index.files_n', { n: files.length + (truncated || capped ? '+' : '') }) })];
                nodes.push(buildFileTree(files));
                if (short) nodes.push(el('div', { className: 'text-muted small mt-1', text: t('js.index.files_stored_cap', { n: files.length.toLocaleString() }) }));
                // A third sentence for a third state: rows are waiting and no button will bring
                // them, because this list already stands on the panel's own total.
                if (capped) nodes.push(el('div', { className: 'text-muted small mt-1', text: t('js.index.files_capped', { n: files.length.toLocaleString() }) }));
                list.replaceChildren(...nodes);
            };
            fill(d.files, d.files_truncated, d.files_short, d.files_capped);
            if (d.files_truncated) {
                // The reply is capped so the modal opens fast; the operator can ask for the rest.
                // Only offered when rows really are waiting AND a bigger request exists — a short
                // list is not one of those, and neither is one already at index_files_admin_max.
                const all = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary mt-2', text: t('js.index.files_load_all') });
                let asked = false;
                const loadAll = async () => {
                    if (asked) return;
                    asked = true; all.disabled = true; all.textContent = t('js.common.loading');
                    try {
                        const full = await apiCall('admin/index_item&files_all=1&hash=' + encodeURIComponent(hash));
                        if (full && full.files) { fill(full.files, false, !!full.files_short, !!full.files_capped); return; }
                    } catch (e) { /* put the button back below */ }
                    asked = false; all.disabled = false; all.textContent = t('js.index.files_load_all');
                };
                all.addEventListener('click', loadAll);
                list.appendChild(all);
                if (filesMode() === 'scroll' && 'IntersectionObserver' in window) {
                    // The public page's "keep loading while scrolling", expressed with the one extra
                    // request the panel has: a sentinel under the tree, fetched once when it is
                    // reached and then disconnected, so a modal left open cannot ask twice.
                    const sentinel = el('div');
                    list.appendChild(sentinel);
                    new IntersectionObserver((entries, obs) => {
                        if (entries.some(e => e.isIntersecting)) { obs.disconnect(); loadAll(); }
                    }, { root: null, rootMargin: '200px' }).observe(sentinel);
                }
            }
            body.appendChild(list);
        }
    }

    // ── bulk actions ─────────────────────────────────────────────────────────
    async function promoteHashes(hashes, fromModal) {
        if (!hashes.length) return;
        if (!(await confirmAction(t('js.index.promote_whitelist'), t('js.index.promote_body', { n: hashes.length }), { okLabel: t('js.index.promote') }))) return;
        try {
            const r = await apiCall('admin/index_promote', 'POST', { hashes });
            if (!r.success || r.error) { showToast(r.error || t('js.index.promote_failed'), 'error'); return; }
            showToast(t('js.index.promoted_n', { n: r.promoted }) + (r.summary ? t('js.index.promoted_summary', { added: r.summary.added || 0, existed: r.summary.exists || 0 }) : ''));
            if (fromModal && modal) modal.hide();
            hashes.forEach(h => state.selected.delete(h));
            load(); loadStatus();
        } catch (e) { showToast(e.message, 'error'); }
    }
    async function deleteSelected() {
        const hashes = [...state.selected];
        if (!hashes.length) return;
        if (!(await confirmAction(t('js.index.delete_from_index'), t('js.index.delete_n_body', { n: hashes.length }), { danger: true, okLabel: t('js.index.delete') }))) return;
        try { const r = await apiCall('admin/index_delete', 'POST', { hashes }); if (!r.success || r.error) { showToast(r.error || t('js.index.delete_failed'), 'error'); return; } showToast(t('js.index.deleted_n', { n: r.removed })); state.selected.clear(); load(); loadStatus(); } catch (e) { showToast(e.message, 'error'); }
    }
    async function metaSelected() {
        const hashes = [...state.selected];
        if (!hashes.length) return;
        try { const r = await apiCall('admin/index_fetch_meta', 'POST', { hashes }); if (!r.success || r.error) { showToast(r.error || t('js.index.queue_failed'), 'error'); return; } showToast(t('js.index.queued_n_meta', { n: r.queued })); } catch (e) { showToast(e.message, 'error'); }
    }
    async function metaScope(scope) {
        if (scope === 'all' && !(await confirmAction(t('js.index.refetch_all_title'),
            t('js.index.refetch_all_body'),
            { okLabel: t('js.index.queue_all') }))) return;
        try { const r = await apiCall('admin/index_fetch_meta', 'POST', { scope }); if (!r.success || r.error) { showToast(r.error || t('js.index.queue_failed'), 'error'); return; } showToast(t('js.index.queued_n_meta', { n: r.queued })); loadStatus(); } catch (e) { showToast(e.message, 'error'); }
    }
    /**
     * Rows of the current page plus the pages around it (same search/filters/sort), deduped by hash.
     * The scope (query params, page, rows) is SNAPSHOTTED at entry — typing in the search box or
     * clicking pagination while the sequential fetches run must not mix two result sets.
     */
    let collectingNear = false;   // shared guard: a click during collection must not start/stop a scrape
    async function collectNearRows() {
        const radius = nearRadius();
        const curPage = state.page, curRows = state.rows;
        const baseQs = listQuery(curPage).toString();
        const from = Math.max(1, curPage - radius), to = Math.min(state.pages || 1, curPage + radius);
        const seen = new Set(), rows = [];
        for (let p = from; p <= to; p++) {
            let pageRows;
            if (p === curPage) pageRows = curRows;
            else {
                const qs = new URLSearchParams(baseQs);
                qs.set('page', String(p));
                const data = await apiCall('admin/fetch_index&' + qs.toString());
                if (data.error) throw new Error(data.error);
                pageRows = data.rows || [];
            }
            for (const r of pageRows) { if (!seen.has(r.info_hash)) { seen.add(r.info_hash); rows.push(r); } }
        }
        return rows;
    }
    /** Queue metadata for the given rows, skipping everything that already has (or is fetching) it. */
    async function metaRows(rows, what) {
        const targets = rows.filter(r => r.meta_status === 'none' || r.meta_status === 'failed').map(r => r.info_hash);
        if (!targets.length) { showToast(t('js.index.nothing_to_queue_in', { what }), 'info'); return; }
        let queued = 0;
        for (let i = 0; i < targets.length; i += 500) {
            const r = await apiCall('admin/index_fetch_meta', 'POST', { hashes: targets.slice(i, i + 500) });
            if (!r.success || r.error) { showToast(r.error || t('js.index.queue_failed'), 'error'); return; }
            queued += Number(r.queued) || 0;
        }
        showToast(t('js.index.queued_of', { n: queued, total: targets.length, what }));
        load(); loadStatus();
    }
    async function metaNearPages() {
        if (collectingNear) return;
        collectingNear = true;
        try { await metaRows(await collectNearRows(), t('js.index.near_pages_n', { n: nearRadius() })); }
        catch (e) { showToast(t('js.index.near_pages_failed', { error: e.message }), 'error'); }
        finally { collectingNear = false; }
    }
    async function promptDateRange() {
        const from = await A.promptModal({ title: t('js.index.custom_range'), label: t('js.index.range_from'), placeholder: '2026-08-20' });
        if (from === null || !from.trim()) return null;
        const to = await A.promptModal({ title: t('js.index.custom_range'), label: t('js.index.range_to'), placeholder: '2026-08-23' });
        if (to === null) return null;
        return { from: from.trim(), to: (to || '').trim() };
    }
    async function metaDate(hours) {
        const body = { scope: 'date' };
        if (hours === 'custom') { const r = await promptDateRange(); if (!r) return; body.from = r.from; if (r.to) body.to = r.to; }
        else body.since_hours = Number(hours);
        try { const r = await apiCall('admin/index_fetch_meta', 'POST', body); if (!r.success || r.error) { showToast(r.error || t('js.index.queue_failed'), 'error'); return; } showToast(r.queued ? t('js.index.queued_n_range', { n: r.queued.toLocaleString(), from: r.from, to: r.to }) : t('js.index.nothing_queue_window'), r.queued ? 'success' : 'info'); loadStatus(); }
        catch (e) { showToast(e.message, 'error'); }
    }
    async function restoreMeta() {
        try {
            const r = await apiCall('admin/index_fetch_meta', 'POST', { scope: 'restore' });
            if (!r.success || r.error) { showToast(r.error || t('js.index.rebuild_failed'), 'error'); return; }
            showToast(r.restored ? t('js.index.restored_n', { n: r.restored.toLocaleString() }) : t('js.index.nothing_restore'), r.restored ? 'success' : 'info');
            load(); loadStatus();
        } catch (e) { showToast(e.message, 'error'); }
    }
    async function cancelMetaQueue() {
        if (!(await confirmAction(t('js.index.cancel_queue_title'), t('js.index.cancel_queue_body'), { danger: true, okLabel: t('js.index.cancel_queue_ok') }))) return;
        try { const r = await apiCall('admin/index_fetch_meta', 'POST', { scope: 'cancel' }); if (!r.success || r.error) { showToast(r.error || t('js.index.cancel_failed'), 'error'); return; } showToast(t('js.index.cancelled_n', { n: (r.cancelled || 0).toLocaleString() }) + (r.restored ? t('js.index.cancelled_restored', { n: r.restored.toLocaleString() }) : '')); load(); loadStatus(); }
        catch (e) { showToast(e.message, 'error'); }
    }
    let scrapeRunning = false, scrapeStop = false;
    /** scope 'page' scrapes hashList (default: the rows on screen) in chunks of 500; other scopes are server-driven. */
    async function scrapeBulk(scope, dateBody, hashList) {
        const label = $('idx-scrape-label');
        const btn = $('btn-idx-scrape-bulk'), caret = $('btn-idx-scrape-caret');
        if (scrapeRunning) { scrapeStop = true; label.textContent = t('js.index.stopping'); return; }   // second click = stop
        if (collectingNear && !hashList) { showToast(t('js.index.near_collecting'), 'info'); return; }
        scrapeRunning = true; scrapeStop = false;
        const orig = label.textContent;
        const origTitle = btn.title;
        caret.disabled = true;
        btn.title = t('js.index.click_to_stop');
        let total = 0, guard = 0, stopped = false, broke = false;
        label.textContent = t('js.index.stop_scraping');
        const chunks = [];
        if (scope === 'page') {
            const src = hashList || state.rows.map(r => r.info_hash);
            for (let i = 0; i < src.length; i += 500) chunks.push(src.slice(i, i + 500));
            if (!chunks.length) chunks.push([]);
        } else chunks.push(null);   // server-driven scope: one chunk, cursor does the walking
        try {
            outer:
            for (const chunk of chunks) {
                let after = '';
                // The server counts what is left ONCE, on the first call of a run (a full count over
                // the table is not free); after that it answers null and this keeps the arithmetic.
                let left = null;
                do {
                    const body = chunk !== null ? { scope, hashes: chunk, after } : { scope, after };
                    if (dateBody) Object.assign(body, dateBody);
                    const r = await apiCall('admin/index_scrape_bulk', 'POST', body);
                    if (!r.success || r.error) { showToast(r.error || t('js.index.scrape_failed'), 'error'); broke = true; break outer; }
                    total += r.scraped || 0;
                    after = r.after || '';
                    if (r.remaining !== null && r.remaining !== undefined) left = r.remaining;
                    else if (left !== null) left = Math.max(0, left - (r.processed || 0));
                    label.textContent = t('js.index.stop_scraped_n', { n: total }) + (left ? t('js.index.n_left', { n: left }) : '');
                    if (r.warning) { showToast(r.warning, 'warning'); broke = true; break outer; }
                    if (scrapeStop) { stopped = true; break outer; }
                    if (!r.truncated) break;
                } while (++guard < 500);
            }
            if (!broke) showToast(stopped ? t('js.index.stopped_refreshed', { n: total }) : t('js.index.refreshed_sl', { n: total }), stopped ? 'info' : 'success');
            load();
        } catch (e) { showToast(e.message, 'error'); }
        finally { label.textContent = orig; btn.title = origTitle; caret.disabled = false; scrapeRunning = false; scrapeStop = false; }
    }
    async function scrapeNearPages() {
        if (scrapeRunning) { scrapeBulk('page'); return; }   // acts as the stop path
        if (collectingNear) return;
        collectingNear = true;                               // blocks other scrape starts during collection
        const label = $('idx-scrape-label');
        const orig = label.textContent;
        label.textContent = t('js.index.collecting');
        let rows;
        try { rows = await collectNearRows(); }
        catch (e) { showToast(t('js.index.near_pages_failed', { error: e.message }), 'error'); return; }
        finally { collectingNear = false; label.textContent = orig; }
        if (!rows.length) { showToast(t('js.index.no_near_rows'), 'info'); return; }
        scrapeBulk('page', null, rows.map(r => r.info_hash));
    }

    // ── wiring ─────────────────────────────────────────────────────────────
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
    // Clicking a column header redraws the arrows immediately; only the fetch waits. The wait has to
    // cover a whole DECISION, not a single click — the direction cycles desc → asc → off, so picking
    // a column and then its direction is two or three clicks, and at 450 ms the first one had already
    // fired a request (and, with several sort keys, a wrong one).
    const SORT_DEBOUNCE_MS = window.AdminCommon.DEBOUNCE.sort;   // one number for every list — admin-common.js
    function init() {
        const loadDebounced = debounce(() => load(), SORT_DEBOUNCE_MS);
        makeSortStack({ table: $('idx-table'), defaultSort: [{ col: 'last', dir: 'desc' }], onChange: (stack) => { state.sort = stack; state.page = 1; loadDebounced(); } }).bindHeaders();
        $('idx-search').addEventListener('input', debounce(() => { state.search = $('idx-search').value.trim(); state.page = 1; load(); }, window.AdminCommon.DEBOUNCE.search));
        bindSearchClear($('idx-search'), $('idx-search-clear'), () => { state.search = ''; state.page = 1; load(); });
        $('idx-search-files').addEventListener('change', () => { state.searchFiles = $('idx-search-files').checked; state.page = 1; load(); });
        $('idx-filter-meta').addEventListener('change', () => { state.meta = $('idx-filter-meta').value; state.page = 1; load(); });
        $('idx-filter-life').addEventListener('change', () => { state.life = $('idx-filter-life').value; state.page = 1; load(); });
        const idxPp = $('idx-perpage');
        if (idxPp) {
            try { const v = localStorage.getItem('thx_idx_perpage'); if (v && [...idxPp.options].some(o => o.value === v)) idxPp.value = v; } catch (e) {}
            idxPp.addEventListener('change', () => { try { localStorage.setItem('thx_idx_perpage', idxPp.value); } catch (e) {} state.page = 1; load(); });
        }
        $('idx-check-all').addEventListener('change', (e) => { state.rows.forEach(r => { if (e.target.checked) state.selected.add(r.info_hash); else state.selected.delete(r.info_hash); }); renderRows({ enabled: true }); syncBulkbar(); });
        $('btn-idx-promote').addEventListener('click', () => promoteHashes([...state.selected]));
        $('btn-idx-delete').addEventListener('click', deleteSelected);
        $('btn-idx-meta-sel').addEventListener('click', metaSelected);
        $('btn-idx-clearsel').addEventListener('click', () => { state.selected.clear(); renderRows({ enabled: true }); syncBulkbar(); });
        document.querySelectorAll('#idx-meta-bulk-group [data-meta-scope]').forEach(b => b.addEventListener('click', () => metaScope(b.dataset.metaScope)));
        document.querySelectorAll('#idx-meta-bulk-group [data-meta-page]').forEach(b => b.addEventListener('click', () => metaRows(state.rows, t('js.index.this_page'))));
        document.querySelectorAll('#idx-meta-bulk-group [data-meta-near]').forEach(b => b.addEventListener('click', () => metaNearPages()));
        document.querySelectorAll('#idx-meta-bulk-group [data-meta-date]').forEach(b => b.addEventListener('click', () => metaDate(b.dataset.metaDate === 'custom' ? 'custom' : Number(b.dataset.metaDate))));
        document.querySelectorAll('#idx-meta-bulk-group [data-meta-cancel]').forEach(b => b.addEventListener('click', () => cancelMetaQueue()));
        document.querySelectorAll('#idx-meta-bulk-group [data-meta-restore]').forEach(b => b.addEventListener('click', () => restoreMeta()));
        $('btn-idx-scrape-bulk').addEventListener('click', () => scrapeBulk('page'));
        document.querySelectorAll('#idx-scrape-bulk-group [data-scrape-scope]').forEach(b => b.addEventListener('click', () => scrapeBulk(b.dataset.scrapeScope)));
        document.querySelectorAll('#idx-scrape-bulk-group [data-scrape-near]').forEach(b => b.addEventListener('click', () => scrapeNearPages()));
        document.querySelectorAll('#idx-scrape-bulk-group [data-scrape-date]').forEach(b => b.addEventListener('click', async () => {
            if (b.dataset.scrapeDate === 'custom') { const r = await promptDateRange(); if (!r) return; scrapeBulk('date', r.to ? { from: r.from, to: r.to } : { from: r.from }); }
            else scrapeBulk('date', { since_hours: Number(b.dataset.scrapeDate) });
        }));
        $('btn-idx-poll').addEventListener('click', async () => {
            const btn = $('btn-idx-poll'); btn.disabled = true;
            const orig = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + esc(t('js.index.polling'));
            try { const r = await apiCall('admin/index_poll_now', 'POST', {}); if (r.success) showToast(t('js.index.poll_result', { seen: r.entries.toLocaleString(), kept: r.kept.toLocaleString(), ms: r.ms }) + (r.truncated ? t('js.index.truncated_suffix') : '')); else showToast(r.error || t('js.index.poll_failed'), 'warning'); load(); loadStatus(); }
            catch (e) { showToast(e.message, 'error'); }
            finally { btn.disabled = false; btn.innerHTML = orig; }
        });
        const logout = $('btn-logout');
        if (logout) logout.addEventListener('click', async () => { try { await apiCall('admin/logout', 'POST', {}); } catch (e) {} location.href = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '') + '?action=' + (document.body.dataset.loginPath || 'admin'); });
        load(); loadStatus();
        // A link straight to one row. It opens on top of the list rather than instead of it, so the
        // page behind is the page the sender was on.
        const linked = hashFromUrl();
        if (linked) openModal(linked);
        setInterval(loadStatus, 30000);
        // live view while metadata resolves: silently refresh the current page every 5 s when the
        // meta filter is pending/fetching or any visible row still is — sort/filters/selection survive
        setInterval(() => {
            if (document.hidden) return;
            const busyFilter = state.meta === 'pending' || state.meta === 'fetching';
            const busyRows = state.rows.some(r => r.meta_status === 'pending' || r.meta_status === 'fetching');
            if (busyFilter || busyRows) load(true);
        }, 5000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
