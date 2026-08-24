// === Admin › Whitelist page ===
// Views: Whitelist | Banned hashes | API clients | API bans, plus the tracker whitelist status card.
// All dynamic DOM is built with AdminCommon.el / textContent — names, file paths, IPs, reasons and
// ban snapshots are attacker-controlled. Row data lives in Maps keyed by id; buttons carry data-id.
(function () {
    'use strict';
    // Quick feedback (copies) goes through copyToClipboard → flashTip (tooltip on the clicked element);
    // showToast is reserved for real outcomes (added / banned / deleted / errors).
    const { apiCall, el, showToast, confirmAction, promptModal, makeSortStack, renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard, animatedClear, bindSearchClear, buildFileTree } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const bodyDs = document.body.dataset;

    // ───────────────────────── state ─────────────────────────
    const state = {
        view: 'whitelist',
        wl: { page: 1, pages: 1, search: '', searchFiles: false, source: '', meta: '', banned: 'active', ip: '', group: false, rows: new Map(), selected: new Set(), ipCounts: {} },
        bn: { page: 1, search: '', rows: new Map() },
        ab: { page: 1, search: '', status: 'active', rows: new Map() },
        cl: { rows: new Map() },
        status: null,
    };
    let wlSort, bnSort, abSort;
    let statusTimer = null;
    let detailPollTimer = null;
    let detailPollStart = 0;

    // ───────────────────────── helpers ─────────────────────────
    function badge(text, cls) { return el('span', { className: 'wl-badge ' + (cls || ''), text }); }
    function sourceBadge(src) {
        const map = { web: 'wl-b-web', api: 'wl-b-api', admin: 'wl-b-admin', forum: 'wl-b-forum' };
        return badge(src || '?', map[src] || '');
    }
    function metaBadge(status) {
        const map = { none: ['—', 'wl-b-muted'], pending: ['pending', 'wl-b-pending'], fetching: ['fetching', 'wl-b-pending'], done: ['done', 'wl-b-ok'], failed: ['failed', 'wl-b-bad'] };
        const [t, c] = map[status] || [status || '—', 'wl-b-muted'];
        return badge(t, c);
    }
    function isHttpUrl(u) { return typeof u === 'string' && /^https?:\/\//i.test(u); }
    function iconBtn(icon, title, cls, onclick) {
        return el('button', { type: 'button', className: 'btn btn-sm ' + (cls || 'btn-outline-secondary') + ' wl-act', title, onclick }, [el('i', { className: 'bi ' + icon })]);
    }
    function ipColor(ip) {
        let h = 0;
        for (let i = 0; i < ip.length; i++) h = (h * 31 + ip.charCodeAt(i)) >>> 0;
        return `hsl(${h % 360} 60% 45%)`;
    }
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
    function magnetFor(hash, name) {
        let m = 'magnet:?xt=urn:btih:' + hash;
        if (name) m += '&dn=' + encodeURIComponent(name);
        [bodyDs.announce, bodyDs.announceHttps].forEach(u => { if (u) m += '&tr=' + encodeURIComponent(u); });
        return m;
    }

    // ───────────────────────── status card ─────────────────────────
    async function loadStatus() {
        try {
            const s = await apiCall('admin/whitelist_status');
            if (s.error) { showToast('Status: ' + s.error, 'danger'); return; }
            state.status = s;
            renderStatus(s);
        } catch { showToast('Could not load whitelist status', 'danger'); }
    }

    function kv(label, value, cls) {
        return el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: label }), el('div', { className: 'wl-kv-value ' + (cls || '') }, value)]);
    }

    function renderStatus(s) {
        const grid = $('wl-status-grid');
        grid.textContent = '';
        const st = s.state || {};
        const perm = s.permissions || {};
        const modeBadge = badge(s.mode === 'whitelist' ? 'WHITELIST' : 'BLACKLIST', s.mode === 'whitelist' ? 'wl-b-ok' : 'wl-b-warn');
        grid.appendChild(kv('Tracker mode', [modeBadge]));
        // scheduled mode (whitelist hours): off / desired + next change / last switch outcome
        const sc = s.schedule;
        if (sc && sc.enabled) {
            const parts = [];
            if (!sc.valid) {
                parts.push(badge('INVALID JSON', 'wl-b-bad'));
            } else {
                const inSync = sc.desired === s.mode;
                parts.push(badge('wants ' + (sc.desired === 'whitelist' ? 'WHITELIST' : 'BLACKLIST'), inSync ? 'wl-b-ok' : 'wl-b-warn'));
                if (!inSync) parts.push(' ', badge('out of sync', 'wl-b-warn'));
                parts.push(' ', el('span', { className: 'text-muted', text: sc.next_change ? 'next change ' + fmtDate(new Date(sc.next_change * 1000).toISOString()) + ' (' + (sc.next_change_local || '') + ' ' + sc.tz + ')' : 'no change scheduled' }));
            }
            parts.push(el('div', { className: 'wl-small text-muted', text: sc.describe || '' }));
            if (sc.last_result) {
                const okRes = sc.last_result === 'ok';
                parts.push(el('div', { className: 'wl-small' }, [
                    el('span', { className: 'text-muted', text: 'last switch: ' }),
                    badge(okRes ? 'ok' : 'FAILED', okRes ? 'wl-b-ok' : 'wl-b-bad'), ' ',
                    el('span', { className: 'text-muted', text: (sc.last_from && sc.last_to ? sc.last_from + ' → ' + sc.last_to + ' ' : '') + (sc.last_switch_at ? fmtAgo(Math.floor(s.server_time - sc.last_switch_at)) + ' ago' : (sc.last_attempt_at ? 'attempted ' + fmtAgo(Math.floor(s.server_time - sc.last_attempt_at)) + ' ago' : '')) }),
                ]));
                if (!okRes && sc.last_error) parts.push(el('div', { className: 'wl-small text-danger', text: sc.last_error }));
                if (sc.last_notes) parts.push(el('div', { className: 'wl-small text-muted', text: sc.last_notes }));
            }
            if (!sc.cmd_set) parts.push(el('div', { className: 'wl-small text-muted', text: 'no switch command — only the web setting flips' }));
            grid.appendChild(kv('Schedule', parts));
        } else {
            grid.appendChild(kv('Schedule', [badge('off', 'wl-b-muted'), ' ', el('span', { className: 'text-muted wl-small', text: 'fixed mode (Settings → Scheduled mode)' })]));
        }
        grid.appendChild(kv('Whitelist file', [
            el('code', { className: 'wl-path', text: s.path || '(not configured)' }), ' ',
            perm.ok ? badge('ok', 'wl-b-ok') : badge('problem', 'wl-b-bad'),
        ]));
        grid.appendChild(kv('DB rows', [
            el('span', { text: `${s.counts.active} active` }), ' · ',
            el('span', { text: `${s.counts.banned_rows} banned rows` }), ' · ',
            el('span', { text: `${s.counts.banned_hashes} banned hashes` }),
        ]));
        grid.appendChild(kv('Metadata queue', [
            el('span', { text: `${s.counts.pending_meta} pending` }), ' · ',
            el('span', { text: `${s.counts.fetching_meta} fetching` }),
        ]));
        const f = s.file || {};
        grid.appendChild(kv('File', f.exists
            ? [el('span', { text: `≈${f.lines_estimate} lines · ${fmtBytes(f.size)}` }), ' · ', el('span', { className: 'text-muted', text: 'modified ' + (f.mtime ? fmtDate(new Date(f.mtime * 1000).toISOString()) : '—') })]
            : [badge('missing', 'wl-b-bad')]));
        grid.appendChild(kv('Last regeneration', st.generated_at ? [el('span', { text: fmtDate(new Date(st.generated_at * 1000).toISOString()) + ` (${st.count} hashes, +${st.appended_since_regen} appended since)` })] : ['never']));
        const pend = st.pending_reload
            ? [badge(st.urgent ? 'urgent' : 'pending', st.urgent ? 'wl-b-warn' : 'wl-b-pending'), ' ', el('span', { className: 'text-muted', text: st.dirty_since ? 'for ' + fmtAgo(Math.floor(s.server_time - st.dirty_since)) + (s.next_reload_in ? `, next in ~${s.next_reload_in} s` : '') : '' })]
            : [badge('in sync', 'wl-b-ok')];
        if (st.regen_needed) pend.push(' ', badge('regen needed', 'wl-b-warn'));
        grid.appendChild(kv('Tracker reload', pend));
        const lr = st.last_reload_attempt_at
            ? [badge(st.last_reload_ok ? 'ok' : 'FAILED', st.last_reload_ok ? 'wl-b-ok' : 'wl-b-bad'), ' ', el('span', { className: 'text-muted', text: fmtAgo(Math.floor(s.server_time - st.last_reload_attempt_at)) + ' ago' + (st.fail_count ? ` · ${st.fail_count} consecutive failures` : '') })]
            : ['never'];
        if (!st.last_reload_ok && st.last_reload_output) lr.push(el('div', { className: 'wl-small text-danger', text: st.last_reload_output }));
        grid.appendChild(kv('Last reload', lr));
        const hb = s.worker_heartbeat_age;
        grid.appendChild(kv('Metadata worker', hb === null || hb === undefined
            ? [badge('not running', 'wl-b-muted'), ' ', el('span', { className: 'text-muted wl-small', text: 'no heartbeat file' })]
            : [badge(hb < 120 ? 'alive' : 'stale', hb < 120 ? 'wl-b-ok' : 'wl-b-warn'), ' ', el('span', { className: 'text-muted', text: 'heartbeat ' + fmtAgo(hb) + ' ago' })]));
        grid.appendChild(kv('Service', [
            el('code', { text: (s.service && s.service.name) || '(none)' }), ' ',
            s.service && s.service.auto_reload ? badge('auto-reload', 'wl-b-ok') : badge('auto-reload off', 'wl-b-warn'),
            s.service && !s.service.exec ? [' ', badge('exec() disabled', 'wl-b-bad')] : null,
        ].flat()));

        const warn = $('wl-status-warnings');
        warn.textContent = '';
        const list = (s.warnings || []).slice();
        if (!perm.ok && perm.errors && perm.errors.length && !list.some(w => w.text.startsWith('Whitelist file problem'))) {
            list.unshift({ level: 'danger', text: perm.errors.join(' ') });
        }
        if (list.length) {
            list.forEach(w => warn.appendChild(el('li', { className: 'wl-warn-' + (w.level === 'danger' ? 'danger' : 'warn') }, [el('i', { className: 'bi ' + (w.level === 'danger' ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill') }), ' ', w.text])));
            (perm.suggestions || []).forEach(sg => warn.appendChild(el('li', { className: 'wl-warn-hint', text: sg })));
            warn.classList.remove('d-hidden');
        } else {
            warn.classList.add('d-hidden');
        }
        $('wl-status-updated').textContent = 'updated ' + new Date().toLocaleTimeString();
        $('tab-badge-whitelist').textContent = s.counts.active ? String(s.counts.active) : '';
        $('tab-badge-banned').textContent = s.counts.banned_hashes ? String(s.counts.banned_hashes) : '';
    }

    async function regenerate() {
        if (!await confirmAction('Regenerate whitelist file', 'Rewrite the whitelist file from the database (atomic) and ask the tracker to reload it?', { okLabel: 'Regenerate', danger: false })) return;
        const btn = $('btn-wl-regen');
        btn.disabled = true;
        try {
            const r = await apiCall('admin/whitelist_regenerate', 'POST', { reload: true });
            if (r.success) {
                showToast(`File written: ${r.count} hashes, ${fmtBytes(r.bytes)} in ${r.ms} ms` + (r.reload ? (r.reload.ok ? ' · tracker reloaded' : ' · reload NOT done: ' + (r.reload.output || 'see status')) : ''), r.reload && r.reload.ok === false ? 'warning' : 'success');
            } else {
                showToast('Regeneration failed: ' + (r.error || 'unknown') + (r.suggestions && r.suggestions.length ? ' — ' + r.suggestions.join(' ') : ''), 'danger');
            }
        } catch { showToast('Network error', 'danger'); }
        btn.disabled = false;
        loadStatus();
    }

    async function importBlacklist() {
        if (!await confirmAction('Import blacklist', 'Import every hash from the legacy blacklist file into the banned hashes list?', { okLabel: 'Import', danger: false })) return;
        try {
            const r = await apiCall('admin/whitelist_import_blacklist', 'POST', {});
            if (r.success) showToast(`Imported ${r.imported} new bans (${r.skipped} already banned, ${r.invalid} invalid lines)`, 'success');
            else showToast('Import failed: ' + (r.error || 'unknown'), 'danger');
        } catch { showToast('Network error', 'danger'); }
        loadStatus();
        if (state.view === 'banned') loadBanned();
    }

    function initReloadModal() {
        const modalEl = $('wlReloadModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        $('btn-wl-reload').addEventListener('click', () => {
            if (!bodyDs.service) { showToast('No tracker service name configured in Settings.', 'warning'); return; }
            $('wl-reload-alert').textContent = '';
            $('wl-reload-password').value = '';
            modal.show();
            setTimeout(() => $('wl-reload-password').focus(), 300);
        });
        $('wl-reload-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const alert = $('wl-reload-alert');
            const pw = $('wl-reload-password').value;
            const btn = e.target.querySelector('button[type=submit]');
            btn.disabled = true;
            alert.textContent = '';
            try {
                const r = await apiCall('admin/reload_tracker', 'POST', { password: pw });
                if (r.success) {
                    modal.hide();
                    showToast(r.message || 'Tracker reloaded', 'success');
                    loadStatus();
                } else {
                    alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || 'Reload failed' }));
                }
            } catch { alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: 'Network error' })); }
            btn.disabled = false;
        });
    }

    // ───────────────────────── tabs ─────────────────────────
    function switchView(view) {
        state.view = view;
        document.querySelectorAll('#wl-tabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.view === view));
        ['whitelist', 'banned', 'clients', 'bans'].forEach(v => $('view-' + v).classList.toggle('d-hidden', v !== view));
        if (view === 'whitelist') loadWhitelist();
        else if (view === 'banned') loadBanned();
        else if (view === 'clients') loadClients();
        else if (view === 'bans') loadApiBans();
    }

    // ───────────────────────── whitelist view ─────────────────────────
    function wlQuery() {
        const w = state.wl;
        const p = new URLSearchParams();
        p.set('page', String(w.page));
        p.set('sort', wlSort.serialize());
        if (w.search) p.set('search', w.search);
        if (w.searchFiles) p.set('search_files', '1');
        if (w.source) p.set('source', w.source);
        if (w.meta) p.set('meta', w.meta);
        p.set('banned', w.banned);
        if (w.ip) p.set('ip', w.ip);
        if (w.group) p.set('group', 'ip');
        return p.toString();
    }

    let wlLoadSeq = 0;
    async function loadWhitelist(silent = false) {
        const body = $('wl-body');
        const my = ++wlLoadSeq;   // rapid sort/filter clicks: only the newest response may render
        if (!silent) {
            body.textContent = '';
            body.appendChild(el('tr', null, el('td', { colspan: 12, className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' Loading…'])));
        }
        let r;
        try { r = await apiCall('admin/fetch_whitelist&' + wlQuery()); } catch { r = { error: 'Network error' }; }
        if (my !== wlLoadSeq) return;
        body.textContent = '';
        if (r.error) {
            body.appendChild(el('tr', null, el('td', { colspan: 12, className: 'text-center text-danger py-4', text: r.error })));
            return;
        }
        state.wl.rows = new Map(r.rows.map(x => [x.id, x]));
        state.wl.pages = r.pages || 1;
        state.wl.ipCounts = r.ip_counts || {};
        state.wl.selected = new Set([...state.wl.selected].filter(id => state.wl.rows.has(id)));
        $('wl-total').textContent = `${r.total} entries`;
        if (!r.rows.length) {
            body.appendChild(el('tr', null, el('td', { colspan: 12, className: 'text-center text-muted py-4', text: state.wl.search || state.wl.ip ? 'No entries match your search.' : 'The whitelist is empty. Use "Add hashes" or let the forum sync fill it.' })));
        }
        let lastIp = null;
        r.rows.forEach(row => {
            const tr = el('tr', { dataset: { id: String(row.id) } });
            if (row.banned) tr.classList.add('wl-row-banned');
            if (state.wl.group) {
                tr.style.borderLeft = '4px solid ' + ipColor(row.ip || '');
                if (row.ip !== lastIp) tr.classList.add('wl-group-first');
            }
            const cb = el('input', { type: 'checkbox', className: 'form-check-input wl-row-check', dataset: { id: String(row.id) } });
            cb.checked = state.wl.selected.has(row.id);
            tr.appendChild(el('td', null, cb));
            tr.appendChild(el('td', { className: 'wl-id', text: String(row.id) }));
            const hashCell = el('td', { className: 'wl-hash-cell', title: row.info_hash + ' — click to copy' }, [el('code', { text: row.info_hash })]);
            hashCell.addEventListener('click', (e) => copyToClipboard(row.info_hash, e.currentTarget));
            tr.appendChild(hashCell);
            const nameTd = el('td', { className: 'wl-name', title: row.name || '' });
            nameTd.appendChild(el('span', { text: row.name || '—' }));
            tr.appendChild(nameTd);
            tr.appendChild(el('td', { className: 'wl-size', text: row.total_size ? fmtBytes(row.total_size) : '—' }));
            tr.appendChild(el('td', { className: 'wl-files-col', text: row.files_count != null ? String(row.files_count) : '—' }));
            const srcTd = el('td', { className: 'wl-source' }, sourceBadge(row.source));
            if (row.source_ref && isHttpUrl(row.source_ref.url)) {
                srcTd.appendChild(document.createTextNode(' '));
                srcTd.appendChild(el('a', { href: row.source_ref.url, target: '_blank', rel: 'noopener noreferrer', title: 'Open source post', className: 'wl-ref-link' }, el('i', { className: 'bi bi-box-arrow-up-right' })));
            }
            tr.appendChild(srcTd);
            const ipTd = el('td', { className: 'wl-ip', title: row.ip || '' });
            if (row.ip) {
                const a = el('a', { href: '#', title: 'Filter by ' + row.ip, text: row.ip });
                a.addEventListener('click', (e) => { e.preventDefault(); setIpFilter(row.ip); });
                ipTd.appendChild(a);
                if (state.wl.group && row.ip !== lastIp && state.wl.ipCounts[row.ip] > 1) {
                    ipTd.appendChild(document.createTextNode(' '));
                    ipTd.appendChild(el('span', { className: 'wl-ip-count', title: 'entries from this IP', text: '×' + state.wl.ipCounts[row.ip] }));
                }
            } else ipTd.textContent = '—';
            tr.appendChild(ipTd);
            const metaTd = el('td', { className: 'wl-meta' }, metaBadge(row.meta_status));
            if (row.meta_status === 'failed' && row.meta_error) metaTd.title = row.meta_error;
            tr.appendChild(metaTd);
            tr.appendChild(el('td', { className: 'wl-sl', text: row.scraped_at ? `${row.scrape_seeders ?? 0} / ${row.scrape_leechers ?? 0}` : '—', title: row.scraped_at ? 'scraped ' + fmtDate(row.scraped_at) : 'not scraped yet' }));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(row.created_at) }));
            const act = el('td', { className: 'wl-actions' });
            act.appendChild(iconBtn('bi-eye', 'Details', 'btn-outline-info', () => openDetails(row.id)));
            const magOpen = el('a', { className: 'btn btn-sm btn-outline-secondary wl-act', title: 'Open magnet link in your torrent client', href: magnetFor(row.info_hash, row.name) }, el('i', { className: 'bi bi-magnet' }));
            act.appendChild(magOpen);
            act.appendChild(iconBtn('bi-clipboard', 'Copy magnet link', 'btn-outline-secondary', (e) => copyToClipboard(magnetFor(row.info_hash, row.name), e.currentTarget)));
            if (row.banned) act.appendChild(iconBtn('bi-unlock', 'Unban', 'btn-outline-success', () => unbanHash(row.info_hash)));
            else act.appendChild(iconBtn('bi-lock', 'Ban', 'btn-outline-warning', () => banRows([row.id])));
            act.appendChild(iconBtn('bi-trash', 'Delete', 'btn-outline-danger', () => deleteRows([row.id])));
            tr.appendChild(act);
            tr.addEventListener('click', (e) => { if (e.target.closest('button') || e.target.closest('input') || e.target.closest('a') || e.target.closest('.wl-hash-cell')) return; openDetails(row.id); });
            body.appendChild(tr);
            lastIp = row.ip;
        });
        renderPagination($('wl-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p) => { state.wl.page = p; loadWhitelist(); } });
        updateBulkBar();
        $('wl-select-all').checked = r.rows.length > 0 && r.rows.every(x => state.wl.selected.has(x.id));
        renderChips();
    }

    // Live view while the worker drains the queue: refresh the visible page every 5 s when the
    // meta filter is pending/fetching OR any visible row is still pending/fetching — sort, filters,
    // pagination and selection all survive (silent reload).
    setInterval(() => {
        if (document.hidden || state.view !== 'whitelist') return;
        const busyFilter = state.wl.meta === 'pending' || state.wl.meta === 'fetching';
        const busyRows = [...state.wl.rows.values()].some(x => x.meta_status === 'pending' || x.meta_status === 'fetching');
        if (busyFilter || busyRows) loadWhitelist(true);
    }, 5000);

    function setIpFilter(ip) {
        state.wl.ip = ip; state.wl.page = 1;
        loadWhitelist();
    }

    function renderChips() {
        const c = $('wl-chips');
        c.textContent = '';
        if (state.wl.ip) {
            const chip = el('span', { className: 'wl-chip' }, ['IP: ', el('code', { text: state.wl.ip }), ' ']);
            const x = el('button', { type: 'button', className: 'wl-chip-x', title: 'Clear IP filter' }, el('i', { className: 'bi bi-x' }));
            x.addEventListener('click', () => { state.wl.ip = ''; state.wl.page = 1; loadWhitelist(); });
            chip.appendChild(x);
            c.appendChild(chip);
        }
        c.classList.toggle('d-hidden', !c.children.length);
    }

    function updateBulkBar() {
        const n = state.wl.selected.size;
        $('wl-bulk').classList.toggle('d-hidden', n === 0);
        $('wl-bulk-count').textContent = `${n} selected`;
    }

    async function deleteRows(ids) {
        if (!ids.length) return;
        if (!await confirmAction('Delete from whitelist', `Remove ${ids.length} ${ids.length === 1 ? 'entry' : 'entries'} from the whitelist? The tracker stops serving them after the next reload.`, { okLabel: 'Delete' })) return;
        try {
            const r = await apiCall('admin/whitelist_delete', 'POST', { ids });
            if (r.success) { showToast(`Removed ${r.removed} entries`, 'success'); ids.forEach(id => state.wl.selected.delete(id)); }
            else showToast(r.error || 'Delete failed', 'danger');
        } catch { showToast('Network error', 'danger'); }
        loadWhitelist(); loadStatus();
    }

    async function banRows(ids) {
        if (!ids.length) return;
        const noun = ids.length === 1 ? 'hash' : 'hashes';
        const reason = await promptModal({
            title: `Ban ${ids.length} ${noun}`,
            label: 'Reason (optional)',
            placeholder: 'e.g. DMCA notice #1234',
            hint: 'Banned hashes are removed from the served whitelist and cannot be re-registered until unbanned.',
            okLabel: `Ban ${ids.length} ${noun}`,
            danger: true,
            multiline: true,
            maxlength: 255,
        });
        if (reason === null) return;
        try {
            const r = await apiCall('admin/whitelist_ban', 'POST', { ids, reason: reason.trim() });
            if (r.success) { showToast(`Banned ${r.banned} new hashes (${r.affected} removed from the served list)`, 'success'); ids.forEach(id => state.wl.selected.delete(id)); }
            else showToast(r.error || 'Ban failed', 'danger');
        } catch { showToast('Network error', 'danger'); }
        loadWhitelist(); loadStatus();
    }

    async function unbanHash(hash) {
        if (!await confirmAction('Unban hash', `Lift the ban on ${hash}? If a whitelist row exists it becomes active again.`, { okLabel: 'Unban', danger: false })) return;
        try {
            const r = await apiCall('admin/whitelist_unban', 'POST', { hash });
            if (r.success) showToast('Ban lifted', 'success'); else showToast(r.error || 'Unban failed', 'danger');
        } catch { showToast('Network error', 'danger'); }
        if (state.view === 'banned') loadBanned(); else loadWhitelist();
        loadStatus();
    }

    async function fetchMeta(ids, refresh) {
        if (!ids.length) return;
        try {
            let queued = 0, hb;
            for (let i = 0; i < ids.length; i += 500) {
                const r = await apiCall('admin/whitelist_fetch_meta', 'POST', { ids: ids.slice(i, i + 500), refresh: !!refresh });
                if (!r.success) { showToast(r.error || 'Request failed', 'danger'); return; }
                queued += Number(r.queued) || 0;
                hb = r.worker_heartbeat_age;
            }
            let msg = `Queued ${queued} of ${ids.length}`;
            if (hb === null || hb === undefined) msg += ' — warning: the metadata worker seems to be down (no heartbeat)';
            else if (hb > 120) msg += ` — warning: worker heartbeat is ${fmtAgo(hb)} old`;
            showToast(msg, hb === null || hb === undefined || hb > 120 ? 'warning' : 'success');
        } catch { showToast('Network error', 'danger'); }
    }

    // ── "This page" / "Near pages" helpers (near radius comes from the admin_near_pages setting) ──
    const nearRadius = () => Math.max(1, parseInt(bodyDs.nearPages || '2', 10) || 2);
    let collectingNear = false;   // shared guard: a click during collection must not start/stop a scrape
    /**
     * Rows of the current page plus the pages around it (same search/filters/sort), deduped by id.
     * The scope (query string, page, rows) is SNAPSHOTTED at entry — typing in the search box or
     * clicking pagination while the sequential fetches run must not mix two result sets.
     */
    async function collectNearRowsWl() {
        const radius = nearRadius();
        const curPage = state.wl.page, curRows = [...state.wl.rows.values()];
        const baseQs = wlQuery();
        const from = Math.max(1, curPage - radius), to = Math.min(state.wl.pages || 1, curPage + radius);
        const seen = new Set(), rows = [];
        for (let p = from; p <= to; p++) {
            let pageRows;
            if (p === curPage) pageRows = curRows;
            else {
                const qs = new URLSearchParams(baseQs);
                qs.set('page', String(p));
                const r = await apiCall('admin/fetch_whitelist&' + qs.toString());
                if (r.error) throw new Error(r.error);
                pageRows = r.rows || [];
            }
            for (const row of pageRows) { if (!seen.has(row.id)) { seen.add(row.id); rows.push(row); } }
        }
        return rows;
    }
    /** Queue metadata for the given rows, skipping everything that already has (or is fetching) it. */
    async function metaRowsWl(rows, what) {
        const ids = rows.filter(r => r.meta_status === 'none' || r.meta_status === 'failed').map(r => r.id);
        if (!ids.length) { showToast('Nothing to queue — no missing/failed rows in ' + what, 'info'); return; }
        try {
            let queued = 0, hb;
            for (let i = 0; i < ids.length; i += 500) {
                const r = await apiCall('admin/whitelist_fetch_meta', 'POST', { ids: ids.slice(i, i + 500), refresh: false });
                if (!r.success) { showToast(r.error || 'Request failed', 'danger'); return; }
                queued += Number(r.queued) || 0;
                hb = r.worker_heartbeat_age;
            }
            let msg = `Queued ${queued} of ${ids.length} missing/failed (${what})`;
            if (hb === null || hb === undefined) msg += ' — warning: the metadata worker seems to be down (no heartbeat)';
            else if (hb > 120) msg += ` — warning: worker heartbeat is ${fmtAgo(hb)} old`;
            showToast(msg, hb === null || hb === undefined || hb > 120 ? 'warning' : 'success');
        } catch { showToast('Network error', 'danger'); return; }
        loadStatus(); loadWhitelist();
    }
    async function metaNearPagesWl() {
        if (collectingNear) return;
        collectingNear = true;
        try { await metaRowsWl(await collectNearRowsWl(), 'near pages ±' + nearRadius()); }
        catch (e) { showToast('Near pages failed: ' + (e.message || 'error'), 'danger'); }
        finally { collectingNear = false; }
    }

    // ───────────────────────── bulk tools (toolbar dropdowns) ─────────────────────────
    const META_SCOPE_LABEL = { missing: 'missing (never fetched)', failed: 'failed', missing_failed: 'missing + failed', all: 'active' };

    /** Bulk "Fetch metadata": one UPDATE server-side; the worker drains the queue one hash after another. */
    async function queueMetaScope(scope) {
        if (!META_SCOPE_LABEL[scope]) return;
        if (scope === 'all' && !await confirmAction('Re-fetch all metadata', 'Queue EVERY active hash for a metadata re-fetch (already fetched ones included)? The worker processes the queue one hash after another — a big list can take hours.', { okLabel: 'Queue all', danger: false })) return;
        const btn = $('btn-wl-meta-bulk');
        btn.disabled = true;
        try {
            const r = await apiCall('admin/whitelist_meta_queue', 'POST', { scope });
            if (r.success) {
                const n = Number(r.queued) || 0;
                let msg = n === 0
                    ? `Nothing to queue — no ${META_SCOPE_LABEL[scope]} hashes without a pending fetch`
                    : `Queued ${n} ${META_SCOPE_LABEL[scope]} ${n === 1 ? 'hash' : 'hashes'} — the worker fetches them one by one in the background`;
                let type = n === 0 ? 'info' : 'success';
                if (n > 0 && (r.worker_heartbeat_age === null || r.worker_heartbeat_age === undefined)) { msg += ' — warning: the metadata worker seems to be down (no heartbeat)'; type = 'warning'; }
                else if (n > 0 && r.worker_heartbeat_age > 120) { msg += ` — warning: worker heartbeat is ${fmtAgo(r.worker_heartbeat_age)} old`; type = 'warning'; }
                showToast(msg, type);
            } else showToast(r.error || 'Request failed', 'danger');
        } catch { showToast('Network error', 'danger'); }
        btn.disabled = false;
        loadStatus(); loadWhitelist();
    }

    /** Ask for a custom from/to (Y-m-d, `to` empty = now). Returns {from,to} or null when cancelled/invalid. */
    async function promptDateRange() {
        const from = await promptModal({ title: 'Custom date range', label: 'From (YYYY-MM-DD or YYYY-MM-DD HH:MM)', placeholder: '2026-08-20' });
        if (from === null || !from.trim()) return null;
        const to = await promptModal({ title: 'Custom date range', label: 'To (empty = now; a date covers the whole day)', placeholder: '2026-08-23' });
        if (to === null) return null;
        return { from: from.trim(), to: (to || '').trim() };
    }

    /** Queue metadata for rows ADDED within a window (missing+failed only). hours = number | 'custom'. */
    async function queueMetaDate(hours) {
        const body = { scope: 'date' };
        if (hours === 'custom') {
            const r = await promptDateRange();
            if (!r) return;
            body.from = r.from; if (r.to) body.to = r.to;
        } else body.since_hours = Number(hours);
        const btn = $('btn-wl-meta-bulk');
        btn.disabled = true;
        try {
            const r = await apiCall('admin/whitelist_meta_queue', 'POST', body);
            if (r.success) {
                const n = Number(r.queued) || 0;
                let msg = n === 0 ? 'Nothing to queue in that window (missing + failed only)' : `Queued ${n} ${n === 1 ? 'hash' : 'hashes'} added ${r.from} → ${r.to}`;
                let type = n === 0 ? 'info' : 'success';
                if (n > 0 && (r.worker_heartbeat_age === null || r.worker_heartbeat_age === undefined)) { msg += ' — warning: the metadata worker seems to be down'; type = 'warning'; }
                showToast(msg, type);
            } else showToast(r.error || 'Request failed', 'danger');
        } catch { showToast('Network error', 'danger'); }
        btn.disabled = false;
    }

    /** Stop the metadata backlog: every queued (pending) row goes back to `none`; active fetches finish. */
    async function cancelMetaQueue() {
        if (!await confirmAction('Cancel queued metadata', 'Reset every QUEUED (pending) hash back to "no metadata"? Rows being fetched right now still finish. You can re-queue any time.', { okLabel: 'Cancel queue', danger: true })) return;
        try {
            const r = await apiCall('admin/whitelist_meta_queue', 'POST', { scope: 'cancel' });
            if (r.success) showToast(`Cancelled ${r.cancelled} queued ${r.cancelled === 1 ? 'fetch' : 'fetches'}`);
            else showToast(r.error || 'Request failed', 'danger');
        } catch { showToast('Network error', 'danger'); }
        loadWhitelist();
    }

    let scrapeRunning = false;
    let scrapeStop = false;
    /**
     * Bulk "Refresh S/L": the server scrapes 50 hashes per tracker request within a time budget and answers
     * truncated=true + a cursor while rows remain — loop until done, showing progress in the button label.
     */
    async function scrapeBulk(scope, dateBody, idList) {
        const btn = $('btn-wl-scrape-bulk'), caret = $('btn-wl-scrape-caret'), label = $('wl-scrape-label');
        if (scrapeRunning) { scrapeStop = true; label.textContent = 'Stopping…'; return; }   // second click = stop
        if (collectingNear && !idList) { showToast('Near-pages collection is running — wait a moment', 'info'); return; }
        const ids = scope === 'page' ? (idList || [...state.wl.rows.keys()]) : null;
        if (scope === 'page' && !ids.length) { showToast('No rows on this page to scrape', 'info'); return; }
        // the 'page' scope takes at most 500 ids per request — near pages can exceed that, so chunk
        const chunks = ids ? Array.from({ length: Math.ceil(ids.length / 500) }, (_, i) => ids.slice(i * 500, i * 500 + 500)) : [null];
        scrapeRunning = true; scrapeStop = false;
        const origLabel = label.textContent;
        const origTitle = btn.title;
        caret.disabled = true;                       // the main button stays clickable — it is the Stop button now
        btn.classList.add('wl-busy');
        btn.title = 'Click to stop after the current batch';
        const progress = (t) => { label.textContent = t; };
        let scraped = 0, requests = 0, failed = 0, rounds = 0, warning = null, aborted = false, stopped = false;
        progress('Stop · scraping…');
        try {
            outer:
            for (const chunk of chunks) {
                let afterId = 0;
                for (;;) {
                    rounds++;
                    const body = { scope, after_id: afterId };
                    if (chunk) body.ids = chunk;
                    if (dateBody) Object.assign(body, dateBody);
                    const r = await apiCall('admin/whitelist_scrape_bulk', 'POST', body);
                    if (!r.success) { showToast(r.error || 'Scrape failed', 'danger'); aborted = true; break outer; }
                    scraped += Number(r.scraped) || 0;
                    requests += Number(r.requests) || 0;
                    failed += Number(r.failed) || 0;
                    if (r.after_id) afterId = r.after_id;
                    if (r.warning) warning = r.warning;
                    progress(`Stop · scraped ${scraped}…` + (r.remaining ? ` (${r.remaining} left)` : ''));
                    if (scrapeStop) { stopped = true; break outer; }
                    if (!r.truncated || rounds >= 200) break;
                }
                if (rounds >= 200) break;
            }
        } catch { showToast('Network error', 'danger'); aborted = true; }
        if (stopped) showToast(`Stopped — scraped ${scraped} before stopping`, 'info');
        if (!aborted && !stopped) {
            const what = scope === 'page' ? 'this page' : scope === 'stale' ? 'stale rows' : scope === 'date' ? 'the selected window' : 'all active hashes';
            if (scraped === 0 && warning) showToast(`Scrape (${what}): ${warning}`, 'warning');
            else showToast(`Scraped ${scraped} ${scraped === 1 ? 'hash' : 'hashes'} (${what}) in ${requests} tracker request${requests === 1 ? '' : 's'}` + (failed ? ` — ${failed} request${failed === 1 ? '' : 's'} got no answer` : '') + (warning ? ` — ${warning}` : ''), failed || warning ? 'warning' : 'success');
        }
        label.textContent = origLabel;
        btn.classList.remove('wl-busy');
        btn.title = origTitle;
        caret.disabled = false;
        scrapeRunning = false; scrapeStop = false;
        loadWhitelist();
    }

    // ───────────────────────── details modal ─────────────────────────
    function stopDetailPoll() { if (detailPollTimer) { clearTimeout(detailPollTimer); detailPollTimer = null; } }

    async function openDetails(id, isPoll = false) {
        const modalEl = $('wlDetailsModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const body = $('wd-body');
        if (!isPoll) {
            stopDetailPoll();
            detailPollStart = Date.now();
            body.textContent = '';
            body.appendChild(el('div', { className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' Loading…']));
            $('wd-title-id').textContent = '#' + id;
            modal.show();
            modalEl.addEventListener('hidden.bs.modal', stopDetailPoll, { once: true });
        }
        let r;
        try { r = await apiCall('admin/whitelist_item&id=' + encodeURIComponent(id)); } catch { r = { error: 'Network error' }; }
        if (r.error) { body.textContent = ''; body.appendChild(el('div', { className: 'alert alert-danger', text: r.error })); return; }
        renderDetails(r);
        const ms = r.item.meta_status;
        if ((ms === 'pending' || ms === 'fetching') && Date.now() - detailPollStart < 120000 && modalEl.classList.contains('show')) {
            detailPollTimer = setTimeout(() => openDetails(id, true), 3000);
        }
    }

    function renderDetails(r) {
        const it = r.item;
        const body = $('wd-body');
        body.textContent = '';
        const magnet = r.magnet || magnetFor(it.info_hash, it.name);

        const kvBox = el('div', { className: 'wl-kv' });
        const row = (label, valueNodes) => kvBox.appendChild(el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: label }), el('div', { className: 'wl-kv-value' }, valueNodes)]));
        // Monospace box + aligned copy button; the copy feedback is a tooltip on that button (flashTip).
        const copyBox = (text, title) => el('div', { className: 'wl-copybox' }, [
            el('code', { className: 'wl-copybox-code', text }),
            el('button', { type: 'button', className: 'btn btn-sm wl-copybox-btn', title, 'aria-label': title, onclick: (e) => copyToClipboard(text, e.currentTarget) }, el('i', { className: 'bi bi-clipboard' })),
        ]);
        row('Info hash', [copyBox(it.info_hash, 'Copy hash')]);
        row('Magnet link', [copyBox(magnet, 'Copy magnet link')]);
        row('Name', [el('span', { text: it.name || '—' })]);
        row('Size', [el('span', { text: it.total_size ? `${fmtBytes(it.total_size)} · ${it.files_count || 0} files` + (it.piece_length ? ` · piece ${fmtBytes(it.piece_length)}` : '') : '—' })]);
        const metaNodes = [metaBadge(it.meta_status)];
        if (it.meta_status === 'failed' && it.meta_error) metaNodes.push(' ', el('span', { className: 'text-danger wl-small', text: it.meta_error }));
        if (it.meta_fetched_at) metaNodes.push(' ', el('span', { className: 'text-muted wl-small', text: 'fetched ' + fmtDate(it.meta_fetched_at) }));
        if (it.meta_status === 'pending' || it.meta_status === 'fetching') metaNodes.push(' ', el('span', { className: 'spinner-border spinner-border-sm text-info' }));
        metaNodes.push(' ', el('button', { type: 'button', className: 'btn btn-sm btn-outline-info wl-act', onclick: async () => { await fetchMeta([it.id], it.meta_status === 'done' || it.meta_status === 'failed'); openDetails(it.id); } }, [el('i', { className: 'bi bi-cloud-download' }), ' ' + (it.meta_status === 'done' ? 'Refresh metadata' : 'Fetch metadata')]));
        row('Metadata', metaNodes);
        const sc = r.scrape;
        const scrapeNodes = sc
            ? [el('span', { text: `${sc.seeders} seeders · ${sc.leechers} leechers · ${sc.completed} completed` }), ' ', el('span', { className: 'text-muted wl-small', text: (sc.cached ? 'cached ' : '') + fmtDate(sc.scraped_at) })]
            : [el('span', { className: 'text-muted', text: 'no scrape data' })];
        scrapeNodes.push(' ', el('button', { type: 'button', className: 'btn btn-sm btn-outline-info wl-act', onclick: async (e) => { e.currentTarget.disabled = true; const s = await apiCall('admin/whitelist_scrape', 'POST', { id: it.id }); if (!s.success) showToast(s.error || 'Scrape failed', 'warning'); openDetails(it.id, true); } }, [el('i', { className: 'bi bi-arrow-repeat' }), ' Scrape now']));
        row('Swarm', scrapeNodes);
        const srcNodes = [sourceBadge(it.source)];
        if (it.ip) { const a = el('a', { href: '#', text: it.ip, title: 'Filter by IP' }); a.addEventListener('click', (e) => { e.preventDefault(); bootstrap.Modal.getOrCreateInstance($('wlDetailsModal')).hide(); switchView('whitelist'); setIpFilter(it.ip); }); srcNodes.push(' ', a); if (it.ip_bucket && it.ip_bucket !== it.ip) srcNodes.push(' ', el('span', { className: 'text-muted wl-small', text: '(' + it.ip_bucket + ')' })); }
        if (r.api_client) srcNodes.push(' · ', el('span', { text: 'API client: ' + r.api_client.label }));
        if (it.source_ref) {
            const ref = it.source_ref;
            const parts = [];
            if (ref.discussion_id) parts.push('discussion #' + ref.discussion_id);
            if (ref.post_id) parts.push('post #' + ref.post_id);
            if (parts.length) srcNodes.push(' · ', el('span', { className: 'text-muted wl-small', text: parts.join(', ') }));
            if (isHttpUrl(ref.url)) srcNodes.push(' ', el('a', { href: ref.url, target: '_blank', rel: 'noopener noreferrer', className: 'wl-ref-link' }, [el('i', { className: 'bi bi-box-arrow-up-right' }), ' open']));
        }
        row('Source', srcNodes);
        if (it.banned || r.banned_reason) {
            const b = r.banned_reason || {};
            row('Ban', [badge('BANNED', 'wl-b-bad'), ' ', el('span', { text: (b.reason || '') + (b.source ? ` (${b.source}${b.source_id ? ' #' + b.source_id : ''})` : '') }), b.created_at ? el('span', { className: 'text-muted wl-small', text: ' · ' + fmtDate(b.created_at) }) : null]);
        }
        row('Created / updated', [el('span', { text: fmtDate(it.created_at) + ' / ' + fmtDate(it.updated_at) })]);
        body.appendChild(kvBox);

        // files tree
        const filesBox = el('div', { className: 'wl-files' });
        filesBox.appendChild(el('div', { className: 'wl-label mb-1', text: r.files && r.files.length ? `Files (${r.files.length}${r.files_truncated ? '+, truncated' : ''})` : 'Files' }));
        if (r.files && r.files.length) filesBox.appendChild(buildFileTree(r.files));
        else filesBox.appendChild(el('div', { className: 'text-muted wl-small', text: it.meta_status === 'done' ? 'Single-file torrent or no file list stored.' : 'No file list yet — fetch metadata.' }));
        body.appendChild(filesBox);

        // actions
        const actions = el('div', { className: 'd-flex justify-content-end gap-2 mt-3 flex-wrap' });
        if (it.banned) actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-outline-success', onclick: async () => { await unbanHash(it.info_hash); openDetails(it.id, true); } }, [el('i', { className: 'bi bi-unlock' }), ' Unban']));
        else actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-outline-warning', onclick: async () => { await banRows([it.id]); openDetails(it.id, true); } }, [el('i', { className: 'bi bi-lock' }), ' Ban']));
        actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', onclick: async () => { const before = state.wl.rows.size; await deleteRows([it.id]); if (!state.wl.rows.has(it.id) || before === 0) bootstrap.Modal.getOrCreateInstance($('wlDetailsModal')).hide(); } }, [el('i', { className: 'bi bi-trash' }), ' Delete']));
        actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-secondary', 'data-bs-dismiss': 'modal', text: 'Close' }));
        body.appendChild(actions);
    }

    // buildFileTree lives in AdminCommon (shared with the Index modal)

    // ───────────────────────── add hashes modal ─────────────────────────
    function initAddModal() {
        const modal = bootstrap.Modal.getOrCreateInstance($('wlAddModal'));
        $('btn-wl-add').addEventListener('click', () => { $('wl-add-input').value = ''; $('wl-add-results').textContent = ''; modal.show(); setTimeout(() => $('wl-add-input').focus(), 300); });
        $('wl-add-submit').addEventListener('click', async () => {
            const btn = $('wl-add-submit');
            const input = $('wl-add-input').value;
            const out = $('wl-add-results');
            out.textContent = '';
            if (!input.trim()) return;
            btn.disabled = true;
            try {
                const r = await apiCall('admin/whitelist_add', 'POST', { input });
                if (r.success) {
                    const s = r.summary;
                    showToast(`Added ${s.added} · existing ${s.exists} · banned ${s.banned} · invalid ${s.invalid}` + (r.file && r.file.ok === false ? ' — file write FAILED: ' + (r.file.error || '') : ''), r.file && r.file.ok === false ? 'danger' : 'success');
                    renderAddResults(out, r.results);
                    $('wl-add-input').value = '';
                    loadWhitelist(); loadStatus();
                } else {
                    showToast(r.error || 'Add failed', 'danger');
                    if (r.results) renderAddResults(out, r.results);
                }
            } catch { showToast('Network error', 'danger'); }
            btn.disabled = false;
        });
    }

    function renderAddResults(container, results) {
        container.textContent = '';
        const cls = { added: 'wl-b-ok', exists: 'wl-b-api', banned: 'wl-b-bad', invalid: 'wl-b-warn' };
        (results || []).forEach(x => {
            container.appendChild(el('div', { className: 'wl-add-row' }, [badge(x.status, cls[x.status] || ''), ' ', el('code', { text: x.hash || (x.input || '').slice(0, 60) }), x.error ? el('span', { className: 'text-muted wl-small', text: ' — ' + x.error }) : null]));
        });
    }

    // ───────────────────────── banned view ─────────────────────────
    async function loadBanned() {
        const body = $('bn-body');
        body.textContent = '';
        body.appendChild(el('tr', null, el('td', { colspan: 6, className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' Loading…'])));
        const p = new URLSearchParams({ page: String(state.bn.page), sort: bnSort.serialize() });
        if (state.bn.search) p.set('search', state.bn.search);
        let r;
        try { r = await apiCall('admin/fetch_banned&' + p.toString()); } catch { r = { error: 'Network error' }; }
        body.textContent = '';
        if (r.error) { body.appendChild(el('tr', null, el('td', { colspan: 6, className: 'text-center text-danger py-4', text: r.error }))); return; }
        $('bn-total').textContent = `${r.total} banned`;
        if (!r.rows.length) body.appendChild(el('tr', null, el('td', { colspan: 6, className: 'text-center text-muted py-4', text: 'No banned hashes.' })));
        r.rows.forEach(row => {
            const tr = el('tr');
            const hc = el('td', { className: 'wl-hash-cell', title: row.info_hash + ' — click to copy' }, el('code', { text: row.info_hash }));
            hc.addEventListener('click', (e) => copyToClipboard(row.info_hash, e.currentTarget));
            tr.appendChild(hc);
            tr.appendChild(el('td', { className: 'wl-name', text: row.name || '—', title: row.name || '' }));
            tr.appendChild(el('td', { className: 'wl-reason', text: row.reason || '—', title: row.reason || '' }));
            tr.appendChild(el('td', { className: 'wl-source' }, badge(row.source + (row.source_id ? ' #' + row.source_id : ''), 'wl-b-muted')));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(row.created_at) }));
            const act = el('td', { className: 'wl-actions' });
            if (row.whitelist_id) act.appendChild(iconBtn('bi-eye', 'Whitelist entry details', 'btn-outline-info', () => openDetails(row.whitelist_id)));
            act.appendChild(iconBtn('bi-unlock', 'Unban', 'btn-outline-success', () => unbanHash(row.info_hash)));
            tr.appendChild(act);
            body.appendChild(tr);
        });
        renderPagination($('bn-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p2) => { state.bn.page = p2; loadBanned(); } });
    }

    function initBanModal() {
        const modal = bootstrap.Modal.getOrCreateInstance($('bnAddModal'));
        $('btn-bn-add').addEventListener('click', () => { $('bn-add-input').value = ''; $('bn-add-reason').value = ''; $('bn-add-alert').textContent = ''; modal.show(); });
        $('bn-add-submit').addEventListener('click', async () => {
            const input = $('bn-add-input').value;
            if (!input.trim()) return;
            const btn = $('bn-add-submit');
            btn.disabled = true;
            try {
                const r = await apiCall('admin/banned_add', 'POST', { input, reason: $('bn-add-reason').value });
                if (r.success) { showToast(`Banned ${r.banned} new hashes (${r.affected} removed from the served list, ${r.invalid} invalid lines)`, 'success'); modal.hide(); loadBanned(); loadStatus(); }
                else { $('bn-add-alert').textContent = ''; $('bn-add-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || 'Ban failed' })); }
            } catch { showToast('Network error', 'danger'); }
            btn.disabled = false;
        });
    }

    // ───────────────────────── API clients view ─────────────────────────
    async function loadClients() {
        const body = $('cl-body');
        body.textContent = '';
        let r;
        try { r = await apiCall('admin/fetch_api_clients'); } catch { r = { error: 'Network error' }; }
        if (r.error) { body.appendChild(el('tr', null, el('td', { colspan: 10, className: 'text-center text-danger py-4', text: r.error }))); return; }
        state.cl.rows = new Map(r.clients.map(c => [c.id, c]));
        $('cl-total').textContent = `${r.clients.length} client${r.clients.length === 1 ? '' : 's'}` + (r.api_enabled ? '' : ' · API DISABLED in Settings');
        if (!r.clients.length) body.appendChild(el('tr', null, el('td', { colspan: 10, className: 'text-center text-muted py-4', text: 'No API clients yet. Create one and paste its bearer token into the forum extension settings.' })));
        r.clients.forEach(c => {
            const tr = el('tr');
            tr.appendChild(el('td', { className: 'wl-cl-label', text: c.label, title: c.label }));
            tr.appendChild(el('td', {}, badge(c.scope || 'whitelist', c.scope === 'all' ? 'wl-b-warn' : '')));
            tr.appendChild(el('td', { className: 'wl-mono' }, el('code', { text: c.key_id })));
            tr.appendChild(el('td', { className: 'wl-mono' }, el('code', { className: 'text-muted', text: '····' + (c.secret_hint || '') })));
            const sw = el('input', { type: 'checkbox', className: 'form-check-input', role: 'switch', title: c.enabled ? 'Enabled — click to disable' : 'Disabled — click to enable' });
            sw.checked = !!c.enabled;
            sw.addEventListener('change', async () => {
                const rr = await apiCall('admin/api_client_update', 'POST', { id: c.id, enabled: sw.checked ? 1 : 0 });
                if (rr.success) showToast(sw.checked ? 'Client enabled' : 'Client disabled', 'success'); else { showToast(rr.error || 'Update failed', 'danger'); sw.checked = !sw.checked; }
            });
            tr.appendChild(el('td', { className: 'wl-enabled' }, el('div', { className: 'form-check form-switch m-0 d-inline-block' }, sw)));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(c.created_at) }));
            tr.appendChild(el('td', { className: 'wl-date', text: c.last_used_at ? fmtDate(c.last_used_at) : 'never' }));
            tr.appendChild(el('td', { className: 'wl-ip', text: c.last_used_ip || '—', title: c.last_used_ip || '' }));
            tr.appendChild(el('td', { className: 'wl-num', text: String(c.requests_count) }));
            const act = el('td', { className: 'wl-actions' });
            act.appendChild(iconBtn('bi-pencil', 'Rename', 'btn-outline-secondary', async () => {
                const label = await promptModal({ title: 'Rename API client', label: 'Label', value: c.label, okLabel: 'Rename', maxlength: 100 });
                if (label === null || !label.trim()) return;
                const rr = await apiCall('admin/api_client_update', 'POST', { id: c.id, label: label.trim() });
                if (rr.success) { showToast('Renamed', 'success'); loadClients(); } else showToast(rr.error || 'Rename failed', 'danger');
            }));
            act.appendChild(iconBtn('bi-trash', 'Delete client', 'btn-outline-danger', async () => {
                if (!await confirmAction('Delete API client', `Delete client "${c.label}"? Any system still using this key will get 403 and its IP will be banned on the next attempt.`, { okLabel: 'Delete' })) return;
                const rr = await apiCall('admin/api_client_delete', 'POST', { id: c.id });
                if (rr.success) { showToast('Client deleted', 'success'); loadClients(); } else showToast(rr.error || 'Delete failed', 'danger');
            }));
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function initClientCreate() {
        const tokenModal = bootstrap.Modal.getOrCreateInstance($('tokenModal'));
        $('btn-cl-create').addEventListener('click', async () => {
            const label = await promptModal({ title: 'Create API client', label: 'Client label', placeholder: 'e.g. Flarum forum', hint: 'The bearer token is shown once, right after creation.', okLabel: 'Create', maxlength: 100 });
            if (label === null || !label.trim()) return;
            const scope = await promptModal({ title: 'Client scope', label: 'Scope', value: 'whitelist', hint: 'Which v1 endpoints this key may call: whitelist (forum sync), users (sales/shop API), federation (peer metadata exchange) or all.', okLabel: 'Create', maxlength: 16 });
            if (scope === null) return;
            const scopeVal = scope.trim().toLowerCase() || 'whitelist';
            if (!['whitelist', 'users', 'federation', 'all'].includes(scopeVal)) { showToast('Scope must be whitelist, users, federation or all', 'warning'); return; }
            try {
                const r = await apiCall('admin/api_client_create', 'POST', { label: label.trim(), scope: scopeVal });
                if (r.success) {
                    $('token-label').textContent = r.label;
                    $('token-keyid').textContent = r.key_id;
                    $('token-value').textContent = r.bearer;
                    tokenModal.show();
                    loadClients();
                } else showToast(r.error || 'Create failed', 'danger');
            } catch { showToast('Network error', 'danger'); }
        });
        $('token-copy').addEventListener('click', (e) => copyToClipboard($('token-value').textContent, e.currentTarget));
    }

    // ───────────────────────── API bans view ─────────────────────────
    async function loadApiBans() {
        const body = $('ab-body');
        body.textContent = '';
        body.appendChild(el('tr', null, el('td', { colspan: 9, className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' Loading…'])));
        const p = new URLSearchParams({ page: String(state.ab.page), sort: abSort.serialize(), status: state.ab.status });
        if (state.ab.search) p.set('search', state.ab.search);
        let r;
        try { r = await apiCall('admin/fetch_api_bans&' + p.toString()); } catch { r = { error: 'Network error' }; }
        body.textContent = '';
        if (r.error) { body.appendChild(el('tr', null, el('td', { colspan: 9, className: 'text-center text-danger py-4', text: r.error }))); return; }
        state.ab.rows = new Map(r.rows.map(x => [x.id, x]));
        $('ab-total').textContent = `${r.total} ban${r.total === 1 ? '' : 's'}`;
        if (!r.rows.length) body.appendChild(el('tr', null, el('td', { colspan: 9, className: 'text-center text-muted py-4', text: state.ab.status === 'active' ? 'No active API bans.' : 'No API bans recorded.' })));
        r.rows.forEach(b => {
            const tr = el('tr', { className: b.active ? '' : 'wl-row-muted' });
            tr.appendChild(el('td', { className: 'wl-ip wl-mono', title: b.ip }, el('code', { text: b.ip })));
            tr.appendChild(el('td', { className: 'text-muted wl-small wl-mono', text: b.ip_bucket !== b.ip ? b.ip_bucket : '', title: b.ip_bucket !== b.ip ? b.ip_bucket : '' }));
            tr.appendChild(el('td', { className: 'wl-reason', title: b.detail || b.reason || '' }, [badge(b.reason, b.reason === 'manual' ? 'wl-b-muted' : 'wl-b-bad'), b.detail ? el('div', { className: 'text-muted wl-small wl-ellipsis', text: b.detail }) : null]));
            tr.appendChild(el('td', { className: 'wl-mono' }, b.key_id ? el('code', { text: b.key_id }) : '—'));
            tr.appendChild(el('td', { className: 'wl-small wl-endpoint', text: b.endpoint || '—', title: b.endpoint || '' }));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(b.created_at) }));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(b.expires_at) }));
            tr.appendChild(el('td', { className: 'wl-date', text: b.lifted_at ? fmtDate(b.lifted_at) + (b.lifted_by ? ' (' + b.lifted_by + ')' : '') : (b.active ? '' : 'expired') }));
            const act = el('td', { className: 'wl-actions' });
            act.appendChild(iconBtn('bi-eye', b.has_snapshot ? `View request (${fmtBytes(b.snapshot_len)})` : 'View', 'btn-outline-info', () => openSnapshot(b.id)));
            if (b.active) act.appendChild(iconBtn('bi-unlock', 'Lift ban', 'btn-outline-success', () => liftBan(b.id)));
            tr.appendChild(act);
            body.appendChild(tr);
        });
        renderPagination($('ab-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p2) => { state.ab.page = p2; loadApiBans(); } });
    }

    async function liftBan(id) {
        if (!await confirmAction('Lift API ban', 'Lift this ban now? The IP can call the API again immediately.', { okLabel: 'Lift', danger: false })) return;
        const r = await apiCall('admin/api_ban_lift', 'POST', { id });
        if (r.success) { showToast('Ban lifted', 'success'); loadApiBans(); } else showToast(r.error || 'Failed', 'danger');
    }

    let currentSnapshotJson = '';
    async function openSnapshot(id) {
        const modal = bootstrap.Modal.getOrCreateInstance($('snapshotModal'));
        $('snapshot-title-id').textContent = '#' + id;
        $('snapshot-meta').textContent = '';
        $('snapshot-pre').textContent = 'Loading…';
        $('snapshot-lift').classList.add('d-hidden');
        modal.show();
        const r = await apiCall('admin/fetch_api_bans&id=' + encodeURIComponent(id));
        if (r.error) { $('snapshot-pre').textContent = r.error; return; }
        const b = r.ban;
        const meta = $('snapshot-meta');
        const add = (k, v) => meta.appendChild(el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: k }), el('div', { className: 'wl-kv-value', text: v == null || v === '' ? '—' : String(v) })]));
        add('IP', b.ip + (b.ip_bucket !== b.ip ? ' (' + b.ip_bucket + ')' : ''));
        add('Reason', b.reason + (b.detail ? ' — ' + b.detail : ''));
        add('Key ID', b.key_id);
        add('Endpoint', b.endpoint);
        add('Created', fmtDate(b.created_at));
        add('Expires', fmtDate(b.expires_at) + (b.active ? ' (active)' : ''));
        add('Lifted', b.lifted_at ? fmtDate(b.lifted_at) + (b.lifted_by ? ' by ' + b.lifted_by : '') : '');
        currentSnapshotJson = b.request_snapshot == null ? '' : (typeof b.request_snapshot === 'string' ? b.request_snapshot : JSON.stringify(b.request_snapshot, null, 2));
        $('snapshot-pre').textContent = currentSnapshotJson || '(no snapshot stored — manual ban, or the snapshot rate limit was hit)';
        if (b.active) {
            const lift = $('snapshot-lift');
            lift.classList.remove('d-hidden');
            lift.onclick = async () => { await liftBan(b.id); modal.hide(); };
        }
    }

    function initApiBanAdd() {
        const modal = bootstrap.Modal.getOrCreateInstance($('abAddModal'));
        $('btn-ab-add').addEventListener('click', () => { $('ab-add-ip').value = ''; $('ab-add-days').value = bodyDs.apiBanDays || '30'; $('ab-add-reason').value = ''; $('ab-add-alert').textContent = ''; modal.show(); });
        $('ab-add-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const alert = $('ab-add-alert');
            alert.textContent = '';
            const r = await apiCall('admin/api_ban_add', 'POST', { ip: $('ab-add-ip').value.trim(), days: parseInt($('ab-add-days').value, 10) || 0, reason: $('ab-add-reason').value });
            if (r.success) { showToast(`Banned ${$('ab-add-ip').value.trim()} for ${r.days} days`, 'success'); modal.hide(); loadApiBans(); }
            else alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || 'Failed' }));
        });
        $('snapshot-copy').addEventListener('click', (e) => copyToClipboard(currentSnapshotJson || '', e.currentTarget));
    }

    // ───────────────────────── init ─────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        const loadWlDebounced = debounce(() => loadWhitelist(), 250);
        wlSort = makeSortStack({ table: $('wl-table'), defaultSort: [{ col: 'date', dir: 'desc' }], onChange: () => { state.wl.page = 1; loadWlDebounced(); } });
        bnSort = makeSortStack({ table: $('bn-table'), defaultSort: [{ col: 'date', dir: 'desc' }], onChange: () => { state.bn.page = 1; loadBanned(); } });
        abSort = makeSortStack({ table: $('ab-table'), defaultSort: [{ col: 'date', dir: 'desc' }], onChange: () => { state.ab.page = 1; loadApiBans(); } });
        wlSort.bindHeaders(); bnSort.bindHeaders(); abSort.bindHeaders();

        document.querySelectorAll('#wl-tabs .source-tab').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));

        $('btn-logout').addEventListener('click', async () => { await apiCall('admin/logout', 'POST', {}); window.location.reload(); });
        $('btn-wl-regen').addEventListener('click', regenerate);
        $('btn-wl-import').addEventListener('click', importBlacklist);
        initReloadModal();
        initAddModal();
        initBanModal();
        initClientCreate();
        initApiBanAdd();

        // whitelist toolbar
        const searchInput = $('wl-search');
        const doSearch = debounce(() => { state.wl.search = searchInput.value.trim(); state.wl.page = 1; loadWhitelist(); }, 350);
        searchInput.addEventListener('input', doSearch);
        bindSearchClear(searchInput, $('wl-search-clear'), () => { state.wl.search = ''; state.wl.page = 1; loadWhitelist(); });
        $('wl-search-files').addEventListener('change', (e) => { state.wl.searchFiles = e.target.checked; if (state.wl.search) loadWhitelist(); });
        $('wl-filter-source').addEventListener('change', (e) => { state.wl.source = e.target.value; state.wl.page = 1; loadWhitelist(); });
        $('wl-filter-meta').addEventListener('change', (e) => { state.wl.meta = e.target.value; state.wl.page = 1; loadWhitelist(); });
        $('wl-filter-banned').addEventListener('change', (e) => { state.wl.banned = e.target.value; state.wl.page = 1; loadWhitelist(); });
        $('wl-group-ip').addEventListener('change', (e) => {
            state.wl.group = e.target.checked; state.wl.page = 1;
            if (state.wl.group) wlSort.set([{ col: 'ip', dir: 'asc' }, { col: 'date', dir: 'desc' }]); else wlSort.reset();
            loadWhitelist();
        });
        // selection
        $('wl-select-all').addEventListener('change', (e) => {
            state.wl.rows.forEach((row, id) => { if (e.target.checked) state.wl.selected.add(id); else state.wl.selected.delete(id); });
            document.querySelectorAll('.wl-row-check').forEach(cb => { cb.checked = e.target.checked; });
            updateBulkBar();
        });
        $('wl-body').addEventListener('change', (e) => {
            if (!e.target.classList.contains('wl-row-check')) return;
            const id = parseInt(e.target.dataset.id, 10);
            if (e.target.checked) state.wl.selected.add(id); else state.wl.selected.delete(id);
            updateBulkBar();
        });
        $('btn-bulk-clear').addEventListener('click', () => { state.wl.selected.clear(); document.querySelectorAll('.wl-row-check').forEach(cb => { cb.checked = false; }); $('wl-select-all').checked = false; updateBulkBar(); });
        $('btn-bulk-delete').addEventListener('click', () => deleteRows([...state.wl.selected]));
        $('btn-bulk-ban').addEventListener('click', () => banRows([...state.wl.selected]));
        $('btn-bulk-meta').addEventListener('click', () => fetchMeta([...state.wl.selected], false));
        // toolbar bulk tools (dropdown items carry data-meta-scope / data-scrape-scope). Close the menu
        // explicitly first: the actions disable the toggle while running, and Bootstrap skips auto-close
        // for a disabled toggle.
        const closeMenu = (toggleId) => { const t = $(toggleId); if (t && bootstrap.Dropdown) bootstrap.Dropdown.getOrCreateInstance(t).hide(); };
        document.querySelectorAll('#wl-meta-bulk-group [data-meta-scope]').forEach(b => b.addEventListener('click', () => { closeMenu('btn-wl-meta-bulk'); queueMetaScope(b.dataset.metaScope); }));
        document.querySelectorAll('#wl-meta-bulk-group [data-meta-date]').forEach(b => b.addEventListener('click', () => { closeMenu('btn-wl-meta-bulk'); queueMetaDate(b.dataset.metaDate === 'custom' ? 'custom' : Number(b.dataset.metaDate)); }));
        document.querySelectorAll('#wl-meta-bulk-group [data-meta-cancel]').forEach(b => b.addEventListener('click', () => { closeMenu('btn-wl-meta-bulk'); cancelMetaQueue(); }));
        document.querySelectorAll('#wl-meta-bulk-group [data-meta-page]').forEach(b => b.addEventListener('click', () => { closeMenu('btn-wl-meta-bulk'); metaRowsWl([...state.wl.rows.values()], 'this page'); }));
        document.querySelectorAll('#wl-meta-bulk-group [data-meta-near]').forEach(b => b.addEventListener('click', () => { closeMenu('btn-wl-meta-bulk'); metaNearPagesWl(); }));
        document.querySelectorAll('#wl-scrape-bulk-group [data-scrape-scope]').forEach(b => b.addEventListener('click', () => { closeMenu('btn-wl-scrape-caret'); scrapeBulk(b.dataset.scrapeScope); }));
        document.querySelectorAll('#wl-scrape-bulk-group [data-scrape-near]').forEach(b => b.addEventListener('click', async () => {
            closeMenu('btn-wl-scrape-caret');
            if (scrapeRunning) { scrapeBulk('page'); return; }   // acts as the stop path
            if (collectingNear) return;
            collectingNear = true;                               // blocks other scrape starts during collection
            const label = $('wl-scrape-label');
            const orig = label.textContent;
            label.textContent = 'Collecting…';
            let rows;
            try { rows = await collectNearRowsWl(); }
            catch (e) { showToast('Near pages failed: ' + (e.message || 'error'), 'danger'); return; }
            finally { collectingNear = false; label.textContent = orig; }
            if (!rows.length) { showToast('No rows in the near pages', 'info'); return; }
            scrapeBulk('page', null, rows.map(r => r.id));
        }));
        document.querySelectorAll('#wl-scrape-bulk-group [data-scrape-date]').forEach(b => b.addEventListener('click', async () => {
            closeMenu('btn-wl-scrape-caret');
            if (b.dataset.scrapeDate === 'custom') { const r = await promptDateRange(); if (!r) return; scrapeBulk('date', r.to ? { from: r.from, to: r.to } : { from: r.from }); }
            else scrapeBulk('date', { since_hours: Number(b.dataset.scrapeDate) });
        }));
        $('btn-wl-scrape-bulk').addEventListener('click', () => scrapeBulk('page'));

        // banned toolbar
        const bnSearch = $('bn-search');
        bnSearch.addEventListener('input', debounce(() => { state.bn.search = bnSearch.value.trim(); state.bn.page = 1; loadBanned(); }, 350));
        $('bn-search-clear').addEventListener('click', () => { bnSearch.value = ''; state.bn.search = ''; state.bn.page = 1; loadBanned(); });
        // api bans toolbar
        const abSearch = $('ab-search');
        abSearch.addEventListener('input', debounce(() => { state.ab.search = abSearch.value.trim(); state.ab.page = 1; loadApiBans(); }, 350));
        $('ab-search-clear').addEventListener('click', () => { abSearch.value = ''; state.ab.search = ''; state.ab.page = 1; loadApiBans(); });
        $('ab-status').addEventListener('change', (e) => { state.ab.status = e.target.value; state.ab.page = 1; loadApiBans(); });

        loadStatus();
        statusTimer = setInterval(loadStatus, 30000);
        loadWhitelist();
    });
})();
