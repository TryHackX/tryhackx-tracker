// === Admin › Whitelist page ===
// Views: Whitelist | Banned hashes | API clients | API bans, plus the tracker whitelist status card.
// All dynamic DOM is built with AdminCommon.el / textContent — names, file paths, IPs, reasons and
// ban snapshots are attacker-controlled. Row data lives in Maps keyed by id; buttons carry data-id.
(function () {
    'use strict';
    // Quick feedback (copies) goes through copyToClipboard → flashTip (tooltip on the clicked element);
    // showToast is reserved for real outcomes (added / banned / deleted / errors).
    const { apiCall, el, showToast, confirmAction, promptModal, promptPassword, makeSortStack, renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard, hashFromUrl, bindHashModal, animatedClear, bindSearchClear, buildFileTree, busyDot } = window.AdminCommon;

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
        const map = { none: ['—', 'wl-b-muted'], pending: [t('js.wl.meta_pending'), 'wl-b-pending'], fetching: [t('js.wl.meta_fetching'), 'wl-b-pending'], done: [t('js.wl.meta_done'), 'wl-b-ok'], failed: [t('js.wl.meta_failed'), 'wl-b-bad'] };
        const [txt, c] = map[status] || [status || '—', 'wl-b-muted'];
        return badge(txt, c);
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
    // Clicking a column header redraws the arrows immediately; only the fetch waits. The wait has to
    // cover a whole DECISION, not a single click — the direction cycles desc → asc → off, so picking
    // a column and then its direction is two or three clicks, and at 450 ms the first one had already
    // fired a request (and, with several sort keys, a wrong one).
    const SORT_DEBOUNCE_MS = window.AdminCommon.DEBOUNCE.sort;   // one number for every list — admin-common.js
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
            if (s.error) { showToast(t('js.wl.status_error', {error: s.error}), 'danger'); return; }
            state.status = s;
            renderStatus(s);
        } catch { showToast(t('js.wl.status_load_failed'), 'danger'); }
    }

    /** Ask the server what is really running, and say so out loud. */
    async function modeTest(btn) {
        const prev = btn.textContent;
        btn.disabled = true;
        btn.textContent = t('js.wl.asking');
        const r = await apiCall('admin/tracker_mode', 'POST', { op: 'status' });
        btn.textContent = prev;
        btn.disabled = false;
        showToast((r && (r.message || r.error)) || t('js.wl.no_answer'), r && r.success ? 'success' : 'error');
        loadStatus();
    }

    /**
     * Switch for real: prepare the list, run the helper, restart the service, then flip the setting.
     *
     * The service restart is the risky part, so this takes the admin password like every other action
     * that changes the machine. The setting is flipped only after the helper confirms — a panel that
     * says whitelist while the blacklist build serves the swarm is not a degraded state, it is a wrong
     * one, and it is the exact failure this whole path exists to prevent.
     */
    async function modeSwitch(mode) {
        const to = mode === 'whitelist' ? t('js.wl.mode_whitelist') : t('js.wl.mode_blacklist');
        if (!await confirmAction(t('js.wl.switch_title', {to}),
            t('js.wl.switch_body'),
            { after: mode === 'whitelist'
                ? t('js.wl.switch_after_whitelist')
                : t('js.wl.switch_after_blacklist'),
              okLabel: t('js.wl.switch_ok'), danger: true })) return;
        const pw = await promptPassword(t('js.wl.switch_pw_title'), t('js.wl.switch_pw_body'));
        if (!pw) return;
        const r = await apiCall('admin/tracker_mode', 'POST', { op: 'switch', mode, password: pw });
        showToast((r && (r.message || r.error)) || t('js.wl.failed'), r && r.success ? 'success' : 'error');
        loadStatus();
    }

    function kv(label, value, cls) {
        return el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: label }), el('div', { className: 'wl-kv-value ' + (cls || '') }, value)]);
    }

    function renderStatus(s) {
        const grid = $('wl-status-grid');
        grid.textContent = '';
        const st = s.state || {};
        const perm = s.permissions || {};
        // Tracker mode: what the PANEL says, and what the tracker is actually running.
        //
        // These used to be assumed identical, and were not: the setting governs which list the panel
        // generates and what the public pages promise, while the running mode is whichever binary and
        // config the symlinks point at. Only the schedule ever moved those. So this tile shows both,
        // and says plainly when they disagree instead of repeating the setting back to the operator.
        const ag = (s.schedule && s.schedule.agreement) || null;
        const modeParts = [badge(s.mode === 'whitelist' ? t('js.wl.mode_whitelist') : t('js.wl.mode_blacklist'),
                                 s.mode === 'whitelist' ? 'wl-b-ok' : 'wl-b-warn')];
        if (ag && ag.known && ag.match === false) {
            modeParts.push(' ', badge(t('js.wl.tracker_running', {mode: String(ag.actual || '').toUpperCase()}), 'wl-b-bad'));
            modeParts.push(el('div', { className: 'wl-small text-danger', text: t('js.wl.mode_disagree') }));
        } else if (ag && ag.known) {
            modeParts.push(' ', el('span', { className: 'wl-small text-muted', text: t('js.wl.mode_confirmed') }));
        } else if (ag) {
            // Unknown is not a mismatch, and must not be drawn as one.
            modeParts.push(el('div', { className: 'wl-small text-muted', text:
                t('js.wl.mode_unknown') + (ag.error ? ': ' + ag.error : '.') }));
        }
        const modeActs = el('div', { className: 'wl-mode-acts' });
        const testBtn = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary',
            title: t('js.wl.test_title') },
            [el('i', { className: 'bi bi-search' }), ' ' + t('js.wl.test_btn')]);
        testBtn.addEventListener('click', () => modeTest(testBtn));
        modeActs.appendChild(testBtn);
        const other = s.mode === 'whitelist' ? 'blacklist' : 'whitelist';
        const modeAcc = (m) => m === 'whitelist' ? t('js.wl.mode_whitelist_acc') : t('js.wl.mode_blacklist_acc');
        const needsSwitch = ag && ag.known && ag.match === false;
        const swBtn = el('button', { type: 'button',
            className: 'btn btn-sm ' + (needsSwitch ? 'btn-outline-warning' : 'btn-outline-secondary'),
            title: needsSwitch
                ? t('js.wl.switch_now_title', {mode: modeAcc(s.mode)})
                : t('js.wl.switch_other_title', {mode: modeAcc(other)}) },
            [el('i', { className: 'bi bi-arrow-left-right' }),
             needsSwitch ? ' ' + t('js.wl.switch_now_btn') : ' ' + t('js.wl.switch_other_btn', {mode: modeAcc(other)})]);
        swBtn.addEventListener('click', () => modeSwitch(needsSwitch ? s.mode : other));
        modeActs.appendChild(swBtn);
        modeParts.push(modeActs);
        grid.appendChild(kv(t('js.wl.kv_tracker_mode'), modeParts));
        // scheduled mode (whitelist hours): off / desired + next change / last switch outcome
        const sc = s.schedule;
        if (sc && sc.enabled) {
            const parts = [];
            if (!sc.valid) {
                parts.push(badge(t('js.wl.sched_invalid'), 'wl-b-bad'));
            } else {
                const inSync = sc.desired === s.mode;
                parts.push(badge(t('js.wl.sched_wants', {mode: sc.desired === 'whitelist' ? t('js.wl.mode_whitelist') : t('js.wl.mode_blacklist')}), inSync ? 'wl-b-ok' : 'wl-b-warn'));
                if (!inSync) parts.push(' ', badge(t('js.wl.sched_out_of_sync'), 'wl-b-warn'));
                parts.push(' ', el('span', { className: 'text-muted', text: sc.next_change ? t('js.wl.sched_next', {when: fmtDate(new Date(sc.next_change * 1000).toISOString()) + ' (' + (sc.next_change_local || '') + ' ' + sc.tz + ')'}) : t('js.wl.sched_none') }));
            }
            parts.push(el('div', { className: 'wl-small text-muted', text: sc.describe || '' }));
            if (sc.last_result) {
                const okRes = sc.last_result === 'ok';
                parts.push(el('div', { className: 'wl-small' }, [
                    el('span', { className: 'text-muted', text: t('js.wl.sched_last') + ' ' }),
                    badge(okRes ? t('js.wl.ok') : t('js.wl.failed_upper'), okRes ? 'wl-b-ok' : 'wl-b-bad'), ' ',
                    el('span', { className: 'text-muted', text: (sc.last_from && sc.last_to ? sc.last_from + ' → ' + sc.last_to + ' ' : '') + (sc.last_switch_at ? t('js.wl.ago', {ago: fmtAgo(Math.floor(s.server_time - sc.last_switch_at))}) : (sc.last_attempt_at ? t('js.wl.attempted_ago', {ago: fmtAgo(Math.floor(s.server_time - sc.last_attempt_at))}) : '')) }),
                ]));
                if (!okRes && sc.last_error) parts.push(el('div', { className: 'wl-small text-danger', text: sc.last_error }));
                if (sc.last_notes) parts.push(el('div', { className: 'wl-small text-muted', text: sc.last_notes }));
            }
            if (!sc.cmd_set) parts.push(el('div', { className: 'wl-small text-muted', text: t('js.wl.sched_no_cmd') }));
            grid.appendChild(kv(t('js.wl.kv_schedule'), parts));
        } else {
            grid.appendChild(kv(t('js.wl.kv_schedule'), [badge(t('js.wl.off'), 'wl-b-muted'), ' ', el('span', { className: 'text-muted wl-small', text: t('js.wl.sched_fixed') })]));
        }
        grid.appendChild(kv(t('js.wl.kv_wl_file'), [
            el('code', { className: 'wl-path', text: s.path || t('js.wl.not_configured') }), ' ',
            perm.ok ? badge(t('js.wl.ok'), 'wl-b-ok') : badge(t('js.wl.problem'), 'wl-b-bad'),
        ]));
        grid.appendChild(kv(t('js.wl.kv_db_rows'), [
            el('span', { text: t('js.wl.n_active', {n: s.counts.active}) }), ' · ',
            el('span', { text: t('js.wl.n_banned_rows', {n: s.counts.banned_rows}) }), ' · ',
            el('span', { text: t('js.wl.n_banned_hashes', {n: s.counts.banned_hashes}) }),
        ]));
        grid.appendChild(kv(t('js.wl.kv_meta_queue'), [
            el('span', { text: t('js.wl.n_pending', {n: s.counts.pending_meta}) }), ' · ',
            el('span', { text: t('js.wl.n_fetching', {n: s.counts.fetching_meta}) }),
        ]));
        const f = s.file || {};
        grid.appendChild(kv(t('js.wl.kv_file'), f.exists
            ? [el('span', { text: t('js.wl.file_lines', {n: f.lines_estimate, size: fmtBytes(f.size)}) }), ' · ', el('span', { className: 'text-muted', text: t('js.wl.modified', {when: f.mtime ? fmtDate(new Date(f.mtime * 1000).toISOString()) : '—'}) })]
            : [badge(t('js.wl.missing'), 'wl-b-bad')]));
        grid.appendChild(kv(t('js.wl.kv_last_regen'), st.generated_at ? [el('span', { text: fmtDate(new Date(st.generated_at * 1000).toISOString()) + ' ' + t('js.wl.regen_detail', {count: st.count, appended: st.appended_since_regen}) })] : [t('js.wl.never')]));
        const pend = st.pending_reload
            ? [badge(st.urgent ? t('js.wl.urgent') : t('js.wl.pending'), st.urgent ? 'wl-b-warn' : 'wl-b-pending'), ' ', el('span', { className: 'text-muted', text: st.dirty_since ? t('js.wl.pending_for', {ago: fmtAgo(Math.floor(s.server_time - st.dirty_since))}) + (s.next_reload_in ? t('js.wl.next_in', {s: s.next_reload_in}) : '') : '' })]
            : [badge(t('js.wl.in_sync'), 'wl-b-ok')];
        if (st.regen_needed) pend.push(' ', badge(t('js.wl.regen_needed'), 'wl-b-warn'));
        grid.appendChild(kv(t('js.wl.kv_tracker_reload'), pend));
        const lr = st.last_reload_attempt_at
            ? [badge(st.last_reload_ok ? t('js.wl.ok') : t('js.wl.failed_upper'), st.last_reload_ok ? 'wl-b-ok' : 'wl-b-bad'), ' ', el('span', { className: 'text-muted', text: t('js.wl.ago', {ago: fmtAgo(Math.floor(s.server_time - st.last_reload_attempt_at))}) + (st.fail_count ? t('js.wl.consecutive_failures', {n: st.fail_count}) : '') })]
            : [t('js.wl.never')];
        if (!st.last_reload_ok && st.last_reload_output) lr.push(el('div', { className: 'wl-small text-danger', text: st.last_reload_output }));
        grid.appendChild(kv(t('js.wl.kv_last_reload'), lr));
        const hb = s.worker_heartbeat_age;
        grid.appendChild(kv(t('js.wl.kv_meta_worker'), hb === null || hb === undefined
            ? [badge(t('js.wl.not_running'), 'wl-b-muted'), ' ', el('span', { className: 'text-muted wl-small', text: t('js.wl.no_heartbeat') })]
            : [badge(hb < 120 ? t('js.wl.alive') : t('js.wl.stale'), hb < 120 ? 'wl-b-ok' : 'wl-b-warn'), ' ', el('span', { className: 'text-muted', text: t('js.wl.heartbeat_ago', {ago: fmtAgo(hb)}) })]));
        grid.appendChild(kv(t('js.wl.kv_service'), [
            el('code', { text: (s.service && s.service.name) || t('js.wl.none') }), ' ',
            s.service && s.service.auto_reload ? badge(t('js.wl.auto_reload'), 'wl-b-ok') : badge(t('js.wl.auto_reload_off'), 'wl-b-warn'),
            s.service && !s.service.exec ? [' ', badge(t('js.wl.exec_disabled'), 'wl-b-bad')] : null,
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
        $('wl-status-updated').textContent = t('js.wl.updated_at', {time: new Date().toLocaleTimeString()});
        $('tab-badge-whitelist').textContent = s.counts.active ? String(s.counts.active) : '';
        $('tab-badge-banned').textContent = s.counts.banned_hashes ? String(s.counts.banned_hashes) : '';
    }

    async function regenerate() {
        if (!await confirmAction(t('js.wl.regen_title'), t('js.wl.regen_body'), { okLabel: t('js.wl.regen_ok'), danger: false })) return;
        const btn = $('btn-wl-regen');
        btn.disabled = true;
        try {
            const r = await apiCall('admin/whitelist_regenerate', 'POST', { reload: true });
            if (r.success) {
                showToast(t('js.wl.regen_written', {count: r.count, size: fmtBytes(r.bytes), ms: r.ms}) + (r.reload ? (r.reload.ok ? t('js.wl.regen_reloaded') : t('js.wl.regen_not_reloaded', {out: r.reload.output || t('js.wl.see_status')})) : ''), r.reload && r.reload.ok === false ? 'warning' : 'success');
            } else {
                showToast(t('js.wl.regen_failed', {error: r.error || t('js.wl.unknown')}) + (r.suggestions && r.suggestions.length ? ' — ' + r.suggestions.join(' ') : ''), 'danger');
            }
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
        btn.disabled = false;
        loadStatus();
    }

    async function importBlacklist() {
        if (!await confirmAction(t('js.wl.import_title'), t('js.wl.import_body'), { okLabel: t('js.wl.import_ok'), danger: false })) return;
        try {
            const r = await apiCall('admin/whitelist_import_blacklist', 'POST', {});
            if (r.success) showToast(t('js.wl.import_done', {n: r.imported, skipped: r.skipped, invalid: r.invalid}), 'success');
            else showToast(t('js.wl.import_failed', {error: r.error || t('js.wl.unknown')}), 'danger');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
        loadStatus();
        if (state.view === 'banned') loadBanned();
    }

    function initReloadModal() {
        const modalEl = $('wlReloadModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        $('btn-wl-reload').addEventListener('click', () => {
            if (!bodyDs.service) { showToast(t('js.wl.reload_no_service'), 'warning'); return; }
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
                    showToast(r.message || t('js.wl.reloaded'), 'success');
                    loadStatus();
                } else {
                    alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || t('js.wl.reload_failed') }));
                }
            } catch { alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: t('js.wl.network_error') })); }
            btn.disabled = false;
        });
    }

    // ───────────────────────── tabs ─────────────────────────
    function switchView(view) {
        state.view = view;
        document.querySelectorAll('#wl-tabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.view === view));
        ['whitelist', 'banned', 'clients', 'bans', 'review'].forEach(v => $('view-' + v).classList.toggle('d-hidden', v !== view));
        if (view === 'whitelist') loadWhitelist();
        else if (view === 'banned') loadBanned();
        else if (view === 'clients') loadClients();
        else if (view === 'bans') loadApiBans();
        else if (view === 'review') loadReview();
    }


    // ───────────────────────── review queue ─────────────────────────
    //
    // Source links and descriptions written by submitters, waiting to be published. The torrents
    // themselves are already registered and stay that way whatever happens here: a description
    // nobody approved is a description nobody sees, not a reason to stop serving a swarm.
    //
    // The description is shown RENDERED, using the same renderer the public page uses. Reviewing the
    // source would mean waving through whatever an image tag turns out to point at.

    const rvState = { page: 1, status: 'pending', search: '', sub: 'queue' };
    let rvSearchTimer = null;

    function rvShowSub(sub) {
        rvState.sub = sub;
        document.querySelectorAll('#rv-subtabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.sub === sub));
        $('rv-pane-queue').classList.toggle('d-hidden', sub !== 'queue');
        $('rv-pane-edits').classList.toggle('d-hidden', sub !== 'edits');
        if (sub === 'edits') loadEdits();
    }

    async function loadReview() {
        const box = $('rv-list');
        if (!box) return;
        box.textContent = '';
        box.appendChild(el('div', { className: 'wl-status-loading', text: t('js.common.loading') }));
        const r = await apiCall('admin/wl_content', 'POST',
            { op: 'list', page: rvState.page, status: rvState.status, search: rvState.search });
        box.textContent = '';
        if (!r || !r.success) {
            box.appendChild(el('div', { className: 'nl-note nl-note-bad', text: (r && r.error) || t('js.wl.review_load_failed') }));
            return;
        }
        // Both counters mean "waiting", never "matching the current filter": a badge that followed
        // the filter would read zero with a full queue behind it.
        const badge = $('tab-badge-review');
        const waiting = (r.waiting || 0) + (r.edits_pending || 0);
        if (badge) badge.textContent = waiting ? String(waiting) : '';
        $('rv-sub-count').textContent = r.waiting ? String(r.waiting) : '';
        $('rv-edits-count').textContent = r.edits_pending ? String(r.edits_pending) : '';
        $('rv-total').textContent = r.total
            ? (rvState.status === 'pending' ? t('js.wl.n_waiting', {n: r.total}) : t('js.wl.n_shown', {n: r.total}))
            : '';
        if (!r.review_on) {
            box.appendChild(el('div', { className: 'nl-note nl-note-info', text: t('js.wl.review_off') }));
        }
        if (!(r.rows || []).length) {
            // An empty queue is a state, not a note: the shared empty-state block, with a hint that says
            // what would fill it.
            box.appendChild(AdminCommon.emptyState(
                rvState.search ? t('js.common.no_results')
                    : (rvState.status === 'pending' ? t('js.common.nothing_waiting') : t('js.common.nothing_here')),
                rvState.search ? 'bi-search' : 'bi-inbox',
                rvState.search ? '' : t('js.wl.review_empty_hint')));
            renderPagination($('rv-pagination'), { total: 0, page: 1, pages: 1, onPage: () => {} });
            return;
        }
        r.rows.forEach(row => box.appendChild(reviewCard(row)));
        renderPagination($('rv-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (pg) => { rvState.page = pg; loadReview(); } });
    }

    /** The state of one submission, as an icon and a word rather than a colour alone. */
    const RV_STATE = {
        pending:  { icon: 'bi-hourglass-split', cls: 'rv-st-wait', text: t('js.wl.rv_waiting') },
        approved: { icon: 'bi-check-circle',    cls: 'rv-st-ok',   text: t('js.wl.rv_published') },
        rejected: { icon: 'bi-x-circle',        cls: 'rv-st-no',   text: t('js.wl.rv_rejected') },
    };

    function reviewCard(row) {
        const state = RV_STATE[row.content_status] || RV_STATE.pending;
        const card = el('div', { className: 'rv-card ' + state.cls });

        const head = el('div', { className: 'rv-head' });
        const title = el('div', { className: 'rv-title' }, [
            el('span', { className: 'rv-state', title: t('js.wl.rv_state_title', {state: state.text}) },
               [el('i', { className: 'bi ' + state.icon }), ' ' + state.text]),
            el('strong', { className: 'rv-name', text: row.name || t('js.wl.no_name_yet') }),
        ]);
        const meta = el('div', { className: 'rv-meta' }, [
            el('code', { className: 'rv-hash', text: row.info_hash }),
            el('span', { className: 'wl-small text-muted', text: fmtDate(String(row.created_at).replace(' ', 'T')) }),
            el('span', { className: 'wl-badge wl-b-muted', text: t('js.wl.via_source', {source: row.source || 'web'}) }),
        ]);
        // Ratings decide the order of this queue, so the number that did the deciding is on the card.
        // A moderator who cannot see why something is at the top is being asked to trust a sort.
        if (row.votes_count) {
            meta.appendChild(el('span', {
                className: 'wl-badge ' + (row.score_x100 >= 5000 ? 'wl-b-ok' : 'wl-b-warn'),
                title: t('js.wl.rv_sort_title'),
                text: row.votes_count === 1
                      ? t('js.wl.rating_one', {pct: Math.round(row.score_x100 / 100), n: row.votes_count})
                      : t('js.wl.rating_many', {pct: Math.round(row.score_x100 / 100), n: row.votes_count}),
            }));
        }
        head.appendChild(title);
        head.appendChild(meta);
        card.appendChild(head);

        if (row.source_url) {
            const line = el('div', { className: 'rv-src' });
            line.appendChild(el('span', { className: 'rv-label', text: t('js.wl.source_link') }));
            // Not a live link, on purpose. A moderator deciding whether a link is acceptable should
            // not have to visit it to find out, and one accidental click is how that happens.
            line.appendChild(el('code', { className: 'rv-url', text: row.source_url }));
            line.appendChild(el('span', {
                className: 'wl-badge ' + (row.source_trusted ? 'wl-b-ok' : 'wl-b-warn'),
                text: row.source_trusted ? t('js.wl.trusted_domain') : t('js.wl.off_site'),
            }));
            card.appendChild(line);
        }

        if (row.description_html) {
            const sec = el('div', { className: 'rv-sec' });
            sec.appendChild(el('div', { className: 'rv-label', text: t('js.wl.description_fmt', {fmt: row.description_format || 'bbcode'}) }));
            const d = el('div', { className: 'rv-desc rt-body' });
            // The server built this string from escaped input with a fixed tag whitelist
            // (includes/richtext.php). It is the only place in this file that assigns innerHTML.
            d.innerHTML = row.description_html;
            sec.appendChild(d);
            card.appendChild(sec);
        }

        if (row.content_status === 'rejected' && row.content_rejected_note) {
            card.appendChild(el('div', { className: 'rv-note' },
                [el('strong', { text: t('js.wl.rejected_label') + ' ' }), row.content_rejected_note]));
        }

        const acts = el('div', { className: 'rv-acts' });
        if (row.content_status !== 'approved') {
            const ok = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success wl-act' },
                [el('i', { className: 'bi bi-check-lg' }), ' ' + t('js.wl.publish')]);
            ok.addEventListener('click', () => reviewAct(row.id, 'approve'));
            acts.appendChild(ok);
        }
        if (row.content_status !== 'rejected') {
            const no = el('button', { type: 'button', className: 'btn btn-sm btn-outline-warning wl-act' },
                [el('i', { className: 'bi bi-x-lg' }), ' ' + t('js.wl.reject')]);
            no.addEventListener('click', () => reviewReject(row.id));
            acts.appendChild(no);
        }
        const del = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger wl-act', title: t('js.wl.delete_text_title') },
            [el('i', { className: 'bi bi-trash' }), ' ' + t('js.wl.delete_text')]);
        del.addEventListener('click', () => reviewClear(row.id, row.info_hash));
        acts.appendChild(del);
        card.appendChild(acts);
        return card;
    }

    // ───────────────────── proposed rewrites ─────────────────────
    //
    // The endpoint has been there since the proposals shipped; this is the screen that was missing,
    // which is why Settings could point at "To review → Rewrites" and find nothing there.

    async function loadEdits() {
        const box = $('rv-edits-list');
        if (!box) return;
        box.textContent = '';
        box.appendChild(el('div', { className: 'wl-status-loading', text: t('js.common.loading') }));
        const r = await apiCall('admin/wl_content', 'POST', { op: 'edits' });
        box.textContent = '';
        if (!r || !r.success) {
            box.appendChild(el('div', { className: 'nl-note nl-note-bad', text: (r && r.error) || t('js.wl.edits_load_failed') }));
            return;
        }
        $('rv-edits-total').textContent = r.total ? t('js.wl.n_waiting', {n: r.total}) : '';
        $('rv-edits-count').textContent = r.total ? String(r.total) : '';
        if (!(r.rows || []).length) {
            // An empty queue is the normal state here, not a problem, and a bare grey note in a box
            // reads like something failed to load. Say what the empty state MEANS instead.
            box.appendChild(el('div', { className: 'rv-empty' }, [
                el('i', { className: 'bi bi-inbox rv-empty-icon' }),
                el('div', { className: 'rv-empty-title', text: t('js.wl.no_rewrites') }),
                el('div', { className: 'rv-empty-hint',
                            text: t('js.wl.no_rewrites_hint') }),
            ]));
            return;
        }
        r.rows.forEach(row => box.appendChild(editCard(row)));
    }

    function editCard(row) {
        const card = el('div', { className: 'rv-card rv-st-wait' });
        card.appendChild(el('div', { className: 'rv-head' }, [
            el('div', { className: 'rv-title' }, [
                el('span', { className: 'rv-state', title: t('js.wl.rewrite_title') },
                   [el('i', { className: 'bi bi-pencil-square' }), ' ' + t('js.wl.rewrite')]),
                el('strong', { className: 'rv-name', text: row.name || t('js.wl.no_name_yet') }),
            ]),
            el('div', { className: 'rv-meta' }, [
                el('code', { className: 'rv-hash', text: row.info_hash }),
                el('span', { className: 'wl-small text-muted', text: fmtDate(String(row.created_at).replace(' ', 'T')) }),
                el('span', { className: 'wl-small text-muted', text: row.ip ? t('js.wl.from_ip', {ip: row.ip}) : '' }),
            ]),
        ]));

        // Side by side, both rendered. A rewrite that reads tamer in source and worse on screen is
        // the whole risk of accepting one, so the comparison has to be of what people will SEE.
        const cmp = el('div', { className: 'rv-diff' });
        const side = (label, html, url, trusted) => {
            const col = el('div', { className: 'rv-diff-col' });
            col.appendChild(el('div', { className: 'rv-label', text: label }));
            if (url) {
                col.appendChild(el('div', { className: 'rv-src' }, [
                    el('code', { className: 'rv-url', text: url }),
                    trusted === null ? '' : el('span', {
                        className: 'wl-badge ' + (trusted ? 'wl-b-ok' : 'wl-b-warn'),
                        text: trusted ? t('js.wl.trusted_domain') : t('js.wl.off_site') }),
                ]));
            }
            const d = el('div', { className: 'rv-desc rt-body' });
            // Server-rendered, same renderer as the public page (includes/richtext.php).
            d.innerHTML = html || '<p class="text-muted">' + t('js.wl.nothing_paren') + '</p>';
            col.appendChild(d);
            return col;
        };
        cmp.appendChild(side(t('js.wl.published_now'), row.cur_html, row.cur_source_url, null));
        cmp.appendChild(side(t('js.wl.proposed'), row.new_html, row.source_url, !!row.new_trusted));
        card.appendChild(cmp);

        const acts = el('div', { className: 'rv-acts' });
        const ok = el('button', { type: 'button', className: 'btn btn-sm btn-outline-success wl-act',
            title: t('js.wl.apply_title') },
            [el('i', { className: 'bi bi-check-lg' }), ' ' + t('js.wl.apply')]);
        ok.addEventListener('click', async () => {
            if (!await confirmAction(t('js.wl.apply_rewrite_title'),
                t('js.wl.apply_rewrite_body'),
                { code: row.info_hash, after: t('js.wl.apply_rewrite_after'), okLabel: t('js.wl.apply') })) return;
            const r = await apiCall('admin/wl_content', 'POST', { op: 'edit_apply', id: row.id });
            showToast((r && (r.message || r.error)) || t('js.wl.failed'), r && r.success ? 'success' : 'error');
            if (r && r.success) loadEdits();
        });
        const no = el('button', { type: 'button', className: 'btn btn-sm btn-outline-warning wl-act' },
            [el('i', { className: 'bi bi-x-lg' }), ' ' + t('js.wl.reject')]);
        no.addEventListener('click', async () => {
            const r = await apiCall('admin/wl_content', 'POST', { op: 'edit_reject', id: row.id });
            showToast((r && (r.message || r.error)) || t('js.wl.failed'), r && r.success ? 'success' : 'error');
            if (r && r.success) loadEdits();
        });
        acts.appendChild(ok); acts.appendChild(no);
        card.appendChild(acts);
        return card;
    }

    async function reviewAct(id, op, extra) {
        const r = await apiCall('admin/wl_content', 'POST', Object.assign({ op, id }, extra || {}));
        showToast((r && (r.message || r.error)) || t('js.wl.failed'), r && r.success ? 'success' : 'error');
        if (r && r.success) loadReview();
    }

    async function reviewReject(id) {
        const note = await promptModal({
            title: t('js.wl.reject_text_title'),
            label: t('js.wl.reject_why_label'),
            placeholder: t('js.wl.reject_why_placeholder'),
            maxlength: 255,
            okLabel: t('js.wl.reject'),
        });
        if (note === null) return;
        reviewAct(id, 'reject', { note });
    }

    async function reviewClear(id, hash) {
        if (!await confirmAction(t('js.wl.delete_text_title_2'), t('js.wl.delete_text_body'),
            { code: hash, after: t('js.wl.delete_text_after'), okLabel: t('js.wl.delete'), danger: true })) return;
        const pw = await promptPassword(t('js.wl.delete_text_title_2'), t('js.wl.confirm_admin_password'));
        if (!pw) return;
        reviewAct(id, 'clear', { password: pw });
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
        const pp = $('wl-perpage');
        if (pp && pp.value !== '25') p.set('per_page', pp.value);
        return p.toString();
    }

    let wlLoadSeq = 0;
    async function loadWhitelist(silent = false) {
        const body = $('wl-body');
        const my = ++wlLoadSeq;   // rapid sort/filter clicks: only the newest response may render
        // keep the old rows on screen: user actions dim the table, silent live-refreshes pulse the dot
        busyDot($('wl-total'), true);
        if (!silent) $('wl-table').classList.add('tbl-loading');
        let r;
        try { r = await apiCall('admin/fetch_whitelist&' + wlQuery()); } catch { r = { error: t('js.wl.network_error') }; }
        if (my !== wlLoadSeq) return;
        busyDot($('wl-total'), false);
        $('wl-table').classList.remove('tbl-loading');
        body.textContent = '';
        if (r.error) {
            body.appendChild(el('tr', null, el('td', { colspan: 12, className: 'text-center text-danger py-4', text: r.error })));
            return;
        }
        state.wl.rows = new Map(r.rows.map(x => [x.id, x]));
        state.wl.pages = r.pages || 1;
        state.wl.ipCounts = r.ip_counts || {};
        state.wl.selected = new Set([...state.wl.selected].filter(id => state.wl.rows.has(id)));
        $('wl-total').textContent = t('js.wl.entries_total', {n: r.total});
        if (!r.rows.length) {
            body.appendChild(el('tr', null, el('td', { colspan: 12, className: 'text-center text-muted py-4', text: state.wl.search || state.wl.ip ? t('js.wl.no_entries_match') : t('js.wl.whitelist_empty') })));
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
            const hashCell = el('td', { className: 'wl-hash-cell', title: t('js.wl.hash_click_copy', {hash: row.info_hash}) }, [el('code', { text: row.info_hash })]);
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
                srcTd.appendChild(el('a', { href: row.source_ref.url, target: '_blank', rel: 'noopener noreferrer', title: t('js.wl.open_source_post'), className: 'wl-ref-link' }, el('i', { className: 'bi bi-box-arrow-up-right' })));
            }
            tr.appendChild(srcTd);
            const ipTd = el('td', { className: 'wl-ip', title: row.ip || '' });
            if (row.ip) {
                const a = el('a', { href: '#', title: t('js.wl.filter_by_ip', {ip: row.ip}), text: row.ip });
                a.addEventListener('click', (e) => { e.preventDefault(); setIpFilter(row.ip); });
                ipTd.appendChild(a);
                if (state.wl.group && row.ip !== lastIp && state.wl.ipCounts[row.ip] > 1) {
                    ipTd.appendChild(document.createTextNode(' '));
                    ipTd.appendChild(el('span', { className: 'wl-ip-count', title: t('js.wl.entries_from_ip'), text: '×' + state.wl.ipCounts[row.ip] }));
                }
            } else ipTd.textContent = '—';
            tr.appendChild(ipTd);
            const metaTd = el('td', { className: 'wl-meta' }, metaBadge(row.meta_status));
            if (row.meta_status === 'failed' && row.meta_error) metaTd.title = row.meta_error;
            tr.appendChild(metaTd);
            tr.appendChild(el('td', { className: 'wl-sl', text: row.scraped_at ? `${row.scrape_seeders ?? 0} / ${row.scrape_leechers ?? 0}` : '—', title: row.scraped_at ? t('js.wl.scraped_at', {date: fmtDate(row.scraped_at)}) : t('js.wl.not_scraped_yet') }));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(row.created_at) }));
            const act = el('td', { className: 'wl-actions' });
            act.appendChild(iconBtn('bi-eye', t('js.wl.details'), 'btn-outline-info', () => openDetails(row.id)));
            const magOpen = el('a', { className: 'btn btn-sm btn-outline-secondary wl-act', title: t('js.wl.open_magnet_title'), href: magnetFor(row.info_hash, row.name) }, el('i', { className: 'bi bi-magnet' }));
            act.appendChild(magOpen);
            act.appendChild(iconBtn('bi-clipboard', t('js.wl.copy_magnet'), 'btn-outline-secondary', (e) => copyToClipboard(magnetFor(row.info_hash, row.name), e.currentTarget)));
            if (row.banned) act.appendChild(iconBtn('bi-unlock', t('js.wl.unban'), 'btn-outline-success', () => unbanHash(row.info_hash)));
            else act.appendChild(iconBtn('bi-lock', t('js.wl.ban'), 'btn-outline-warning', () => banRows([row.id])));
            act.appendChild(iconBtn('bi-trash', t('js.wl.delete'), 'btn-outline-danger', () => deleteRows([row.id])));
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
            const chip = el('span', { className: 'wl-chip' }, [t('js.wl.ip_prefix'), el('code', { text: state.wl.ip }), ' ']);
            const x = el('button', { type: 'button', className: 'wl-chip-x', title: t('js.wl.clear_ip_filter') }, el('i', { className: 'bi bi-x' }));
            x.addEventListener('click', () => { state.wl.ip = ''; state.wl.page = 1; loadWhitelist(); });
            chip.appendChild(x);
            c.appendChild(chip);
        }
        c.classList.toggle('d-hidden', !c.children.length);
    }

    function updateBulkBar() {
        const n = state.wl.selected.size;
        $('wl-bulk').classList.toggle('d-hidden', n === 0);
        $('wl-bulk-count').textContent = t('js.wl.selected_count', {n});
    }

    async function deleteRows(ids) {
        if (!ids.length) return;
        if (!await confirmAction(t('js.wl.delete_from_wl_title'), t(ids.length === 1 ? 'js.wl.delete_confirm_one' : 'js.wl.delete_confirm_many', {n: ids.length}), { okLabel: t('js.wl.delete') })) return;
        try {
            const r = await apiCall('admin/whitelist_delete', 'POST', { ids });
            if (r.success) { showToast(t('js.wl.removed_entries', {n: r.removed}), 'success'); ids.forEach(id => state.wl.selected.delete(id)); }
            else showToast(r.error || t('js.wl.delete_failed'), 'danger');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
        loadWhitelist(); loadStatus();
    }

    async function banRows(ids) {
        if (!ids.length) return;
        const banLabel = t(ids.length === 1 ? 'js.wl.ban_n_one' : 'js.wl.ban_n_many', {n: ids.length});
        const reason = await promptModal({
            title: banLabel,
            label: t('js.wl.reason_optional'),
            placeholder: t('js.wl.ban_reason_placeholder'),
            hint: t('js.wl.ban_hint'),
            okLabel: banLabel,
            danger: true,
            multiline: true,
            maxlength: 255,
        });
        if (reason === null) return;
        try {
            const r = await apiCall('admin/whitelist_ban', 'POST', { ids, reason: reason.trim() });
            if (r.success) { showToast(t('js.wl.banned_result', {n: r.banned, affected: r.affected}), 'success'); ids.forEach(id => state.wl.selected.delete(id)); }
            else showToast(r.error || t('js.wl.ban_failed'), 'danger');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
        loadWhitelist(); loadStatus();
    }

    async function unbanHash(hash) {
        if (!await confirmAction(t('js.wl.unban_hash_title'), t('js.wl.unban_hash_body'),
            { code: hash, after: t('js.wl.unban_hash_after'),
              okLabel: t('js.wl.unban'), danger: false })) return;
        try {
            const r = await apiCall('admin/whitelist_unban', 'POST', { hash });
            if (r.success) showToast(t('js.wl.ban_lifted'), 'success'); else showToast(r.error || t('js.wl.unban_failed'), 'danger');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
        if (state.view === 'banned') loadBanned(); else loadWhitelist();
        loadStatus();
    }

    async function fetchMeta(ids, refresh) {
        if (!ids.length) return;
        try {
            let queued = 0, hb;
            for (let i = 0; i < ids.length; i += 500) {
                const r = await apiCall('admin/whitelist_fetch_meta', 'POST', { ids: ids.slice(i, i + 500), refresh: !!refresh });
                if (!r.success) { showToast(r.error || t('js.wl.request_failed'), 'danger'); return; }
                queued += Number(r.queued) || 0;
                hb = r.worker_heartbeat_age;
            }
            let msg = t('js.wl.queued_of', {n: queued, total: ids.length});
            if (hb === null || hb === undefined) msg += t('js.wl.worker_down_warn');
            else if (hb > 120) msg += t('js.wl.worker_heartbeat_old', {age: fmtAgo(hb)});
            showToast(msg, hb === null || hb === undefined || hb > 120 ? 'warning' : 'success');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
    }

    // ── "This page" / "Near pages" helpers (near radius comes from the admin_near_pages setting) ──
    const nearRadius = () => Math.max(1, parseInt(bodyDs.nearPages || '2', 10) || 2);
    // index_files_admin_mode, exactly as on the Index page: 'button' (the panel's own long-standing
    // behaviour), 'scroll' fetches the rest when the operator reaches the end of the tree, 'all'
    // asks for the whole list on the modal's first request. The sizes are the endpoint's business.
    const filesMode = () => (['scroll', 'button', 'all'].includes(bodyDs.filesMode) ? bodyDs.filesMode : 'button');
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
        if (!ids.length) { showToast(t('js.wl.nothing_to_queue_in', {what}), 'info'); return; }
        try {
            let queued = 0, hb;
            for (let i = 0; i < ids.length; i += 500) {
                const r = await apiCall('admin/whitelist_fetch_meta', 'POST', { ids: ids.slice(i, i + 500), refresh: false });
                if (!r.success) { showToast(r.error || t('js.wl.request_failed'), 'danger'); return; }
                queued += Number(r.queued) || 0;
                hb = r.worker_heartbeat_age;
            }
            let msg = t('js.wl.queued_of_missing', {n: queued, total: ids.length, what});
            if (hb === null || hb === undefined) msg += t('js.wl.worker_down_warn');
            else if (hb > 120) msg += t('js.wl.worker_heartbeat_old', {age: fmtAgo(hb)});
            showToast(msg, hb === null || hb === undefined || hb > 120 ? 'warning' : 'success');
        } catch { showToast(t('js.wl.network_error'), 'danger'); return; }
        loadStatus(); loadWhitelist();
    }
    async function metaNearPagesWl() {
        if (collectingNear) return;
        collectingNear = true;
        try { await metaRowsWl(await collectNearRowsWl(), t('js.wl.near_pages_what', {n: nearRadius()})); }
        catch (e) { showToast(t('js.wl.near_pages_failed', {msg: e.message || t('js.wl.error_word')}), 'danger'); }
        finally { collectingNear = false; }
    }

    // ───────────────────────── bulk tools (toolbar dropdowns) ─────────────────────────
    const META_SCOPE_LABEL = { missing: t('js.wl.scope_missing'), failed: t('js.wl.scope_failed'), missing_failed: t('js.wl.scope_missing_failed'), all: t('js.wl.scope_all') };

    /** Bulk "Fetch metadata": one UPDATE server-side; the worker drains the queue one hash after another. */
    async function queueMetaScope(scope) {
        if (!META_SCOPE_LABEL[scope]) return;
        if (scope === 'all' && !await confirmAction(t('js.wl.refetch_all_title'), t('js.wl.refetch_all_body'), { okLabel: t('js.wl.queue_all'), danger: false })) return;
        const btn = $('btn-wl-meta-bulk');
        btn.disabled = true;
        try {
            const r = await apiCall('admin/whitelist_meta_queue', 'POST', { scope });
            if (r.success) {
                const n = Number(r.queued) || 0;
                let msg = n === 0
                    ? t('js.wl.nothing_to_queue_scope', {scope: META_SCOPE_LABEL[scope]})
                    : t(n === 1 ? 'js.wl.queued_scope_one' : 'js.wl.queued_scope_many', {n, scope: META_SCOPE_LABEL[scope]});
                let type = n === 0 ? 'info' : 'success';
                if (n > 0 && (r.worker_heartbeat_age === null || r.worker_heartbeat_age === undefined)) { msg += t('js.wl.worker_down_warn'); type = 'warning'; }
                else if (n > 0 && r.worker_heartbeat_age > 120) { msg += t('js.wl.worker_heartbeat_old', {age: fmtAgo(r.worker_heartbeat_age)}); type = 'warning'; }
                showToast(msg, type);
            } else showToast(r.error || t('js.wl.request_failed'), 'danger');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
        btn.disabled = false;
        loadStatus(); loadWhitelist();
    }

    /** Ask for a custom from/to (Y-m-d, `to` empty = now). Returns {from,to} or null when cancelled/invalid. */
    async function promptDateRange() {
        const from = await promptModal({ title: t('js.wl.custom_date_range'), label: t('js.wl.date_from_label'), placeholder: '2026-08-20' });
        if (from === null || !from.trim()) return null;
        const to = await promptModal({ title: t('js.wl.custom_date_range'), label: t('js.wl.date_to_label'), placeholder: '2026-08-23' });
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
                let msg = n === 0 ? t('js.wl.nothing_to_queue_window') : t(n === 1 ? 'js.wl.queued_added_one' : 'js.wl.queued_added_many', {n, from: r.from, to: r.to});
                let type = n === 0 ? 'info' : 'success';
                if (n > 0 && (r.worker_heartbeat_age === null || r.worker_heartbeat_age === undefined)) { msg += t('js.wl.worker_down_warn_short'); type = 'warning'; }
                showToast(msg, type);
            } else showToast(r.error || t('js.wl.request_failed'), 'danger');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
        btn.disabled = false;
    }

    /** Stop the metadata backlog: every queued (pending) row goes back to `none`; active fetches finish. */
    async function cancelMetaQueue() {
        if (!await confirmAction(t('js.wl.cancel_queue_title'), t('js.wl.cancel_queue_body'), { okLabel: t('js.wl.cancel_queue'), danger: true })) return;
        try {
            const r = await apiCall('admin/whitelist_meta_queue', 'POST', { scope: 'cancel' });
            if (r.success) showToast(t(r.cancelled === 1 ? 'js.wl.cancelled_one' : 'js.wl.cancelled_many', {n: r.cancelled}) + (r.restored ? t('js.wl.restored_done', {n: r.restored}) : ''));
            else showToast(r.error || t('js.wl.request_failed'), 'danger');
        } catch { showToast(t('js.wl.network_error'), 'danger'); }
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
        if (scrapeRunning) { scrapeStop = true; label.textContent = t('js.wl.stopping'); return; }   // second click = stop
        if (collectingNear && !idList) { showToast(t('js.wl.near_pages_running'), 'info'); return; }
        const ids = scope === 'page' ? (idList || [...state.wl.rows.keys()]) : null;
        if (scope === 'page' && !ids.length) { showToast(t('js.wl.no_rows_to_scrape'), 'info'); return; }
        // the 'page' scope takes at most 500 ids per request — near pages can exceed that, so chunk
        const chunks = ids ? Array.from({ length: Math.ceil(ids.length / 500) }, (_, i) => ids.slice(i * 500, i * 500 + 500)) : [null];
        scrapeRunning = true; scrapeStop = false;
        const origLabel = label.textContent;
        const origTitle = btn.title;
        caret.disabled = true;                       // the main button stays clickable — it is the Stop button now
        btn.classList.add('wl-busy');
        btn.title = t('js.wl.click_to_stop_batch');
        const progress = (t) => { label.textContent = t; };
        let scraped = 0, requests = 0, failed = 0, rounds = 0, warning = null, aborted = false, stopped = false;
        progress(t('js.wl.stop_scraping'));
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
                    if (!r.success) { showToast(r.error || t('js.wl.scrape_failed'), 'danger'); aborted = true; break outer; }
                    scraped += Number(r.scraped) || 0;
                    requests += Number(r.requests) || 0;
                    failed += Number(r.failed) || 0;
                    if (r.after_id) afterId = r.after_id;
                    if (r.warning) warning = r.warning;
                    progress(t('js.wl.stop_scraped', {n: scraped}) + (r.remaining ? t('js.wl.remaining_left', {n: r.remaining}) : ''));
                    if (scrapeStop) { stopped = true; break outer; }
                    if (!r.truncated || rounds >= 200) break;
                }
                if (rounds >= 200) break;
            }
        } catch { showToast(t('js.wl.network_error'), 'danger'); aborted = true; }
        if (stopped) showToast(t('js.wl.stopped_scraped', {n: scraped}), 'info');
        if (!aborted && !stopped) {
            const what = scope === 'page' ? t('js.wl.what_this_page') : scope === 'stale' ? t('js.wl.what_stale_rows') : scope === 'date' ? t('js.wl.what_selected_window') : t('js.wl.what_all_active');
            if (scraped === 0 && warning) showToast(t('js.wl.scrape_warning', {what, warning}), 'warning');
            else showToast(t(scraped === 1 ? 'js.wl.scraped_one' : 'js.wl.scraped_many', {n: scraped, what}) + t(requests === 1 ? 'js.wl.in_requests_one' : 'js.wl.in_requests_many', {n: requests}) + (failed ? ' — ' + t(failed === 1 ? 'js.wl.no_answer_one' : 'js.wl.no_answer_many', {n: failed}) : '') + (warning ? ` — ${warning}` : ''), failed || warning ? 'warning' : 'success');
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

    // One whitelist row, addressable: ?action=admin-whitelist&hash=<40 hex> opens this modal.
    // A LINK NAMES THE TORRENT, NOT THE ROW NUMBER — `id` is this installation's own counter and
    // means nothing anywhere else, so the address carries the hash and api/admin/whitelist_item.php
    // takes either. Inside the page the table still passes ids, which are what it has.
    let wlHashView = null;
    const isHash = (v) => /^[0-9a-f]{40}$/i.test(String(v));
    // Whether the details modal is still the thing the operator is looking at. NOT
    // `classList.contains('show')`: Bootstrap adds that class a backdrop transition after
    // modal.show() returns, so a reply that arrives quickly would find it absent and conclude the
    // modal had been closed. Set where we open it, cleared where the browser tells us it is gone.
    let detailShowing = false;

    async function openDetails(id, isPoll = false) {
        const modalEl = $('wlDetailsModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const body = $('wd-body');
        const byHash = isHash(id);
        if (!isPoll) {
            stopDetailPoll();
            detailPollStart = Date.now();
            body.textContent = '';
            body.appendChild(el('div', { className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' ' + t('js.common.loading')]));
            $('wd-title-id').textContent = byHash ? String(id).toLowerCase().slice(0, 10) + '…' : '#' + id;
            wlHashView = wlHashView || bindHashModal(modalEl, 'admin-whitelist');
            if (byHash) wlHashView.show(id);
            detailShowing = true;
            modal.show();
            modalEl.addEventListener('hidden.bs.modal', () => { detailShowing = false; stopDetailPoll(); }, { once: true });
        }
        let r;
        const key = byHash ? '&hash=' + encodeURIComponent(String(id).toLowerCase()) : '&id=' + encodeURIComponent(id);
        try { r = await apiCall('admin/whitelist_item' + (filesMode() === 'all' ? '&files_all=1' : '') + key); } catch { r = { error: t('js.wl.network_error') }; }
        if (r.error) { body.textContent = ''; body.appendChild(el('div', { className: 'alert alert-danger', text: r.error })); return; }
        renderDetails(r);
        // The hash is only known once the row has arrived, which is why this is here and not above:
        // a modal opened by id can still be shared, because by now we know what it is showing.
        //
        // Guarded on the modal still being open, exactly like the poll scheduler below. This request
        // does a live scrapeOpenTracker() on every call, so Escape within the second it takes is an
        // ordinary thing to do — and hidden.bs.modal has then already cleared the address, so a late
        // write here would put ?hash= back with nothing left to take it away again. The next F5
        // would reopen a modal nobody asked for.
        if (!isPoll && r.item && r.item.info_hash && detailShowing) {
            if (wlHashView) wlHashView.show(r.item.info_hash);
            if (byHash) $('wd-title-id').textContent = '#' + r.item.id;
        }
        const ms = r.item.meta_status;
        if ((ms === 'pending' || ms === 'fetching') && Date.now() - detailPollStart < 120000 && detailShowing) {
            detailPollTimer = setTimeout(() => openDetails(id, true), 3000);
        }
    }


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
                    ? t('js.wl.waiting_review_not_public')
                    : t('js.wl.rejected_not_public', {note: c.rejected_note ? ' — ' + c.rejected_note : ''}),
            }));
        }

        if (hasLink) {
            const row = el('div', { className: 'rt-src-row' });
            row.appendChild(el('span', { className: 'wl-kv-label', text: t('js.wl.source') }));
            const a = el('a', {
                className: 'rt-src-url', href: c.source_url, text: c.source_url,
                rel: 'nofollow noopener noreferrer ugc', target: '_blank',
                title: c.source_trusted ? t('js.wl.src_trusted_title') : t('js.wl.src_offsite_title'),
            });
            // Not our link. The confirmation is the panel's too: an administrator clicking through a
            // queue is exactly the person who should not open one by accident.
            if (!c.source_trusted) a.setAttribute('data-external', '1');
            row.appendChild(a);
            wrap.appendChild(row);
        }

        if (hasText) {
            const det = el('details', { className: 'rt-collapse' });
            det.appendChild(el('summary', { text: t('js.wl.description') }));
            const body = el('div', { className: 'rt-body' });
            // Built on the server by includes/richtext.php from fully escaped input with a fixed tag
            // whitelist. Everything else in this file goes through el()/textContent.
            body.innerHTML = c.description_html;
            det.appendChild(body);
            wrap.appendChild(det);
        }
        return wrap;
    }

    /**
     * The swarm numbers, as the strip the public Info panel uses.
     *
     * Same classes, same file (assets/css/detail-panel.css), so the two panels describing the same
     * torrent look like the same thing. They did not: the public panel got a redesign and the admin
     * panels kept a flat key/value list, because the CSS lived in the public-only stylesheet.
     */
    function statStrip(cells) {
        const strip = el('div', { className: 'info-strip' });
        cells.forEach(([value, label, cls]) => {
            if (value === null || value === undefined) return;
            strip.appendChild(el('div', { className: 'info-stat' + (cls ? ' ' + cls : '') }, [
                el('span', { className: 'info-stat-v', text: String(value) }),
                el('span', { className: 'info-stat-l', text: label }),
            ]));
        });
        return strip.children.length ? strip : null;
    }

    function renderDetails(r) {
        const it = r.item;
        const body = $('wd-body');
        body.textContent = '';
        const magnet = r.magnet || magnetFor(it.info_hash, it.name);

        // The numbers first, the identifying facts under them. Whether anything is sharing this is
        // the first question an admin has too; it used to be the sixth row of a flat list.
        const idx = r.index || null;
        const sc0 = r.scrape;
        const strip = statStrip([
            [sc0 ? sc0.seeders : (it.scrape_seeders != null ? it.scrape_seeders : null), t('js.wl.stat_seeders'), 'info-stat-seed'],
            [sc0 ? sc0.leechers : (it.scrape_leechers != null ? it.scrape_leechers : null), t('js.wl.stat_leechers'), 'info-stat-leech'],
            [sc0 ? sc0.completed : (it.scrape_completed != null ? it.scrape_completed : null), t('js.wl.stat_completed')],
            [it.total_size ? fmtBytes(it.total_size) : null, t('js.wl.stat_size')],
            [it.files_count != null ? it.files_count : null, it.files_count === 1 ? t('js.wl.stat_file') : t('js.wl.stat_files')],
            [idx && idx.peak_seeders != null ? idx.peak_seeders : null, t('js.wl.stat_peak_seeders')],
        ]);
        if (strip) body.appendChild(strip);

        const kvBox = el('div', { className: 'wl-kv' });
        const row = (label, valueNodes) => kvBox.appendChild(el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: label }), el('div', { className: 'wl-kv-value' }, valueNodes)]));
        // Monospace box + aligned copy button; the copy feedback is a tooltip on that button (flashTip).
        const copyBox = (text, title) => el('div', { className: 'wl-copybox' }, [
            el('code', { className: 'wl-copybox-code', text }),
            el('button', { type: 'button', className: 'btn btn-sm wl-copybox-btn', title, 'aria-label': title, onclick: (e) => copyToClipboard(text, e.currentTarget) }, el('i', { className: 'bi bi-clipboard' })),
        ]);
        // Copying a magnet and then finding somewhere to paste it is two steps too many when the
        // whole point of opening this panel was to look at one torrent. The row listing already has an
        // open button; the detail view, where somebody has deliberately stopped to read, did not.
        const magnetBox = (text) => {
            const box = copyBox(text, t('js.wl.copy_magnet'));
            box.appendChild(el('a', {
                className: 'btn btn-sm wl-copybox-btn', href: text,
                title: t('js.wl.open_in_client'), 'aria-label': t('js.wl.open_magnet_in_client'),
            }, el('i', { className: 'bi bi-magnet' })));
            return box;
        };
        row(t('js.wl.lbl_info_hash'), [copyBox(it.info_hash, t('js.wl.copy_hash'))]);
        row(t('js.wl.lbl_magnet'), [magnetBox(magnet)]);
        row(t('js.wl.lbl_name'), [el('span', { text: it.name || '—' })]);
        row(t('js.wl.lbl_size'), [el('span', { text: it.total_size ? t('js.wl.size_line', { size: fmtBytes(it.total_size), n: it.files_count || 0 }) + (it.piece_length ? ' · ' + t('js.wl.piece_size', { size: fmtBytes(it.piece_length) }) : '') : '—' })]);
        const metaNodes = [metaBadge(it.meta_status)];
        if (it.meta_status === 'failed' && it.meta_error) metaNodes.push(' ', el('span', { className: 'text-danger wl-small', text: it.meta_error }));
        if (it.meta_fetched_at) metaNodes.push(' ', el('span', { className: 'text-muted wl-small', text: t('js.wl.fetched_at', { date: fmtDate(it.meta_fetched_at) }) }));
        if (it.meta_status === 'pending' || it.meta_status === 'fetching') metaNodes.push(' ', el('span', { className: 'spinner-border spinner-border-sm text-info' }));
        metaNodes.push(' ', el('button', { type: 'button', className: 'btn btn-sm btn-outline-info wl-act', onclick: async () => { await fetchMeta([it.id], it.meta_status === 'done' || it.meta_status === 'failed'); openDetails(it.id); } }, [el('i', { className: 'bi bi-cloud-download' }), ' ' + (it.meta_status === 'done' ? t('js.wl.refresh_metadata') : t('js.wl.fetch_metadata'))]));
        row(t('js.wl.lbl_metadata'), metaNodes);
        const sc = r.scrape;
        const scrapeNodes = sc
            ? [el('span', { text: t('js.wl.swarm_line', { s: sc.seeders, l: sc.leechers, c: sc.completed }) }), ' ', el('span', { className: 'text-muted wl-small', text: (sc.cached ? t('js.wl.cached') + ' ' : '') + fmtDate(sc.scraped_at) })]
            : [el('span', { className: 'text-muted', text: t('js.wl.no_scrape_data') })];
        scrapeNodes.push(' ', el('button', { type: 'button', className: 'btn btn-sm btn-outline-info wl-act', onclick: async (e) => { e.currentTarget.disabled = true; const s = await apiCall('admin/whitelist_scrape', 'POST', { id: it.id }); if (!s.success) showToast(s.error || t('js.wl.scrape_failed'), 'warning'); openDetails(it.id, true); } }, [el('i', { className: 'bi bi-arrow-repeat' }), ' ' + t('js.wl.scrape_now')]));
        row(t('js.wl.lbl_swarm'), scrapeNodes);
        const srcNodes = [sourceBadge(it.source)];
        if (it.ip) { const a = el('a', { href: '#', text: it.ip, title: t('js.wl.filter_by_ip_2') }); a.addEventListener('click', (e) => { e.preventDefault(); bootstrap.Modal.getOrCreateInstance($('wlDetailsModal')).hide(); switchView('whitelist'); setIpFilter(it.ip); }); srcNodes.push(' ', a); if (it.ip_bucket && it.ip_bucket !== it.ip) srcNodes.push(' ', el('span', { className: 'text-muted wl-small', text: '(' + it.ip_bucket + ')' })); }
        if (r.api_client) srcNodes.push(' · ', el('span', { text: t('js.wl.api_client', { name: r.api_client.label }) }));
        if (it.source_ref) {
            const ref = it.source_ref;
            const parts = [];
            if (ref.discussion_id) parts.push(t('js.wl.discussion_ref', { id: ref.discussion_id }));
            if (ref.post_id) parts.push(t('js.wl.post_ref', { id: ref.post_id }));
            if (parts.length) srcNodes.push(' · ', el('span', { className: 'text-muted wl-small', text: parts.join(', ') }));
            if (isHttpUrl(ref.url)) srcNodes.push(' ', el('a', { href: ref.url, target: '_blank', rel: 'noopener noreferrer', className: 'wl-ref-link' }, [el('i', { className: 'bi bi-box-arrow-up-right' }), ' ' + t('js.wl.open')]));
        }
        row(t('js.wl.lbl_source'), srcNodes);
        if (it.banned || r.banned_reason) {
            const b = r.banned_reason || {};
            row(t('js.wl.lbl_ban'), [badge(t('js.wl.badge_banned'), 'wl-b-bad'), ' ', el('span', { text: (b.reason || '') + (b.source ? ` (${b.source}${b.source_id ? ' #' + b.source_id : ''})` : '') }), b.created_at ? el('span', { className: 'text-muted wl-small', text: ' · ' + fmtDate(b.created_at) }) : null]);
        }
        // Ratings: the LIST already showed these and the detail panel did not, which is a strange
        // place to lose information — you click a row to see MORE.
        if (it.votes_count) {
            const pct = Math.round((it.score_x100 || 0) / 100);
            row(t('js.wl.lbl_rating'), [
                badge(pct + '%', pct >= 50 ? 'wl-b-ok' : 'wl-b-warn'), ' ',
                el('span', { className: 'text-muted wl-small',
                    text: t(it.votes_count === 1 ? 'js.wl.from_rating' : 'js.wl.from_ratings', { n: it.votes_count })
                        + (it.votes_up || it.votes_down ? ' ' + t('js.wl.up_down', { up: it.votes_up || 0, down: it.votes_down || 0 }) : '') }),
            ]);
        }
        // "Prove it" state, when the tracker is set to make submissions prove themselves.
        if (it.probe_status && it.probe_status !== 'none') {
            // enum('none','probing','passed','failed') — the names are the schema's, not invented here
            const cls = { passed: 'wl-b-ok', failed: 'wl-b-bad', probing: 'wl-b-pending' }[it.probe_status] || 'wl-b-muted';
            const probeNodes = [badge(it.probe_status, cls)];
            if (it.probe_error) probeNodes.push(' ', el('span', { className: 'text-danger wl-small', text: it.probe_error }));
            if (it.probe_started_at) probeNodes.push(' ', el('span', { className: 'text-muted wl-small', text: t('js.wl.started_at', { date: fmtDate(it.probe_started_at) }) }));
            row(t('js.wl.lbl_proof'), probeNodes);
        }
        if (it.dead_since) {
            row(t('js.wl.lbl_dead_since'), [el('span', { className: 'text-warning', text: fmtDate(it.dead_since) }), ' ',
                el('span', { className: 'text-muted wl-small', text: t('js.wl.dead_since_hint') })]);
        }
        // What the CATALOGUE knows. Absent for a registered hash nobody has announced yet, which is
        // itself worth seeing — it means the tracker has never been asked for this torrent.
        if (idx) {
            const seenNodes = [el('span', { text: fmtDate(idx.first_seen) + ' → ' + fmtDate(idx.last_seen) })];
            if (idx.seen_count != null) seenNodes.push(' ', el('span', { className: 'text-muted wl-small', text: '· ' + t('js.wl.seen_times', { n: idx.seen_count }) }));
            row(t('js.wl.lbl_seen_by_tracker'), seenNodes);
        } else {
            row(t('js.wl.lbl_seen_by_tracker'), [el('span', { className: 'text-muted', text: t('js.wl.never_announced') })]);
        }
        row(t('js.wl.lbl_created_updated'), [el('span', { text: fmtDate(it.created_at) + ' / ' + fmtDate(it.updated_at) })]);
        body.appendChild(kvBox);

        // The submitter own words, between the numbers above and the files below.
        const cb = contentBlock(r.content);
        if (cb) body.appendChild(cb);

        // files tree
        const filesBox = el('div', { className: 'wl-files' });
        // The same honesty as the Index modal: the label prints the stored list against the row's own
        // files_count whenever the worker stopped short of it, and the missing-list sentence hangs on
        // whether there are files — not on whether the reply was truncated, which put "single file or
        // no list stored" underneath a perfectly complete tree.
        const fillFiles = (files, truncated, short, capped) => {
            const nodes = [el('div', { className: 'wl-label mb-1', text: !files.length ? t('js.wl.files')
                : (short && it.files_count
                    ? t('js.wl.files_n_of', { n: files.length.toLocaleString(), total: Number(it.files_count).toLocaleString() })
                    : t('js.wl.files_n', { n: files.length, more: (truncated || capped) ? t('js.wl.files_truncated') : '' })) })];
            if (files.length) nodes.push(buildFileTree(files));
            if (files.length && short) nodes.push(el('div', { className: 'text-muted wl-small', text: t('js.wl.files_stored_cap', { n: files.length.toLocaleString() }) }));
            // Rows are waiting and no button will fetch them: this list is already as long as the
            // panel is allowed to load (index_files_admin_max).
            if (files.length && capped) nodes.push(el('div', { className: 'text-muted wl-small', text: t('js.wl.files_capped', { n: files.length.toLocaleString() }) }));
            if (!files.length) nodes.push(el('div', { className: 'text-muted wl-small', text: it.meta_status === 'done' ? t('js.wl.single_file_or_no_list') : t('js.wl.no_file_list_yet') }));
            filesBox.replaceChildren(...nodes);
        };
        fillFiles(r.files || [], !!r.files_truncated, !!r.files_short, !!r.files_capped);
        if (r.files_truncated) {
            // Capped for a fast modal; the operator can ask for the whole list. Offered only when
            // rows are actually waiting in the table — a list the worker wrote short has none, and
            // neither has one that already reached the panel's total.
            const all = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary mt-2', text: t('js.wl.files_load_all') });
            let asked = false;
            const loadAll = async () => {
                if (asked) return;
                asked = true; all.disabled = true; all.textContent = t('js.common.loading');
                try {
                    const full = await apiCall('admin/whitelist_item&files_all=1&id=' + encodeURIComponent(id));
                    if (full && full.files) { fillFiles(full.files, false, !!full.files_short, !!full.files_capped); return; }
                } catch (e) { /* put the button back below */ }
                asked = false; all.disabled = false; all.textContent = t('js.wl.files_load_all');
            };
            all.addEventListener('click', loadAll);
            filesBox.appendChild(all);
            if (filesMode() === 'scroll' && 'IntersectionObserver' in window) {
                // The same sentinel as the Index modal: reach the end of the tree and the rest is
                // fetched once, then the observer is disconnected so it cannot ask again.
                const sentinel = el('div');
                filesBox.appendChild(sentinel);
                new IntersectionObserver((entries, obs) => {
                    if (entries.some(e => e.isIntersecting)) { obs.disconnect(); loadAll(); }
                }, { root: null, rootMargin: '200px' }).observe(sentinel);
            }
        }
        body.appendChild(filesBox);

        // actions
        const actions = el('div', { className: 'd-flex justify-content-end gap-2 mt-3 flex-wrap' });
        if (it.banned) actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-outline-success', onclick: async () => { await unbanHash(it.info_hash); openDetails(it.id, true); } }, [el('i', { className: 'bi bi-unlock' }), ' ' + t('js.wl.unban')]));
        else actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-outline-warning', onclick: async () => { await banRows([it.id]); openDetails(it.id, true); } }, [el('i', { className: 'bi bi-lock' }), ' ' + t('js.wl.ban')]));
        actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', onclick: async () => { const before = state.wl.rows.size; await deleteRows([it.id]); if (!state.wl.rows.has(it.id) || before === 0) bootstrap.Modal.getOrCreateInstance($('wlDetailsModal')).hide(); } }, [el('i', { className: 'bi bi-trash' }), ' ' + t('js.wl.delete')]));
        actions.appendChild(el('button', { type: 'button', className: 'btn btn-sm btn-secondary', 'data-bs-dismiss': 'modal', text: t('js.wl.close') }));
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
                    showToast(t('js.wl.add_summary', { added: s.added, exists: s.exists, banned: s.banned, invalid: s.invalid }) + (r.file && r.file.ok === false ? ' — ' + t('js.wl.add_file_failed', { error: r.file.error || '' }) : ''), r.file && r.file.ok === false ? 'danger' : 'success');
                    renderAddResults(out, r.results);
                    $('wl-add-input').value = '';
                    loadWhitelist(); loadStatus();
                } else {
                    showToast(r.error || t('js.wl.add_failed'), 'danger');
                    if (r.results) renderAddResults(out, r.results);
                }
            } catch { showToast(t('js.wl.network_error'), 'danger'); }
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
        body.appendChild(el('tr', null, el('td', { colspan: 6, className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' ' + t('js.common.loading')])));
        const p = new URLSearchParams({ page: String(state.bn.page), sort: bnSort.serialize() });
        if (state.bn.search) p.set('search', state.bn.search);
        let r;
        try { r = await apiCall('admin/fetch_banned&' + p.toString()); } catch { r = { error: t('js.wl.network_error') }; }
        body.textContent = '';
        if (r.error) { body.appendChild(el('tr', null, el('td', { colspan: 6, className: 'text-center text-danger py-4', text: r.error }))); return; }
        $('bn-total').textContent = t('js.wl.banned_total', { n: r.total });
        if (!r.rows.length) body.appendChild(el('tr', null, el('td', { colspan: 6, className: 'text-center text-muted py-4', text: t('js.wl.no_banned_hashes') })));
        r.rows.forEach(row => {
            const tr = el('tr');
            const hc = el('td', { className: 'wl-hash-cell', title: t('js.wl.hash_click_to_copy', { hash: row.info_hash }) }, el('code', { text: row.info_hash }));
            hc.addEventListener('click', (e) => copyToClipboard(row.info_hash, e.currentTarget));
            tr.appendChild(hc);
            tr.appendChild(el('td', { className: 'wl-name', text: row.name || '—', title: row.name || '' }));
            tr.appendChild(el('td', { className: 'wl-reason', text: row.reason || '—', title: row.reason || '' }));
            tr.appendChild(el('td', { className: 'wl-source' }, badge(row.source + (row.source_id ? ' #' + row.source_id : ''), 'wl-b-muted')));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(row.created_at) }));
            const act = el('td', { className: 'wl-actions' });
            if (row.whitelist_id) act.appendChild(iconBtn('bi-eye', t('js.wl.whitelist_entry_details'), 'btn-outline-info', () => openDetails(row.whitelist_id)));
            act.appendChild(iconBtn('bi-unlock', t('js.wl.unban'), 'btn-outline-success', () => unbanHash(row.info_hash)));
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
                if (r.success) { showToast(t('js.wl.ban_add_summary', { banned: r.banned, affected: r.affected, invalid: r.invalid }), 'success'); modal.hide(); loadBanned(); loadStatus(); }
                else { $('bn-add-alert').textContent = ''; $('bn-add-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || t('js.wl.ban_failed') })); }
            } catch { showToast(t('js.wl.network_error'), 'danger'); }
            btn.disabled = false;
        });
    }

    // ───────────────────────── API clients view ─────────────────────────
    async function loadClients() {
        const body = $('cl-body');
        body.textContent = '';
        let r;
        try { r = await apiCall('admin/fetch_api_clients'); } catch { r = { error: t('js.wl.network_error') }; }
        if (r.error) { body.appendChild(el('tr', null, el('td', { colspan: 10, className: 'text-center text-danger py-4', text: r.error }))); return; }
        state.cl.rows = new Map(r.clients.map(c => [c.id, c]));
        $('cl-total').textContent = t(r.clients.length === 1 ? 'js.wl.clients_one' : 'js.wl.clients_many', { n: r.clients.length }) + (r.api_enabled ? '' : ' · ' + t('js.wl.api_disabled_in_settings'));
        if (!r.clients.length) body.appendChild(el('tr', null, el('td', { colspan: 10, className: 'text-center text-muted py-4', text: t('js.wl.no_api_clients') })));
        r.clients.forEach(c => {
            const tr = el('tr');
            tr.appendChild(el('td', { className: 'wl-cl-label', text: c.label, title: c.label }));
            tr.appendChild(el('td', {}, badge(c.scope || 'whitelist', c.scope === 'all' ? 'wl-b-warn' : '')));
            tr.appendChild(el('td', { className: 'wl-mono' }, el('code', { text: c.key_id })));
            tr.appendChild(el('td', { className: 'wl-mono' }, el('code', { className: 'text-muted', text: '····' + (c.secret_hint || '') })));
            const sw = el('input', { type: 'checkbox', className: 'form-check-input', role: 'switch', title: c.enabled ? t('js.wl.enabled_click_disable') : t('js.wl.disabled_click_enable') });
            sw.checked = !!c.enabled;
            sw.addEventListener('change', async () => {
                const rr = await apiCall('admin/api_client_update', 'POST', { id: c.id, enabled: sw.checked ? 1 : 0 });
                if (rr.success) showToast(sw.checked ? t('js.wl.client_enabled') : t('js.wl.client_disabled'), 'success'); else { showToast(rr.error || t('js.wl.update_failed'), 'danger'); sw.checked = !sw.checked; }
            });
            tr.appendChild(el('td', { className: 'wl-enabled' }, el('div', { className: 'form-check form-switch m-0 d-inline-block' }, sw)));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(c.created_at) }));
            tr.appendChild(el('td', { className: 'wl-date', text: c.last_used_at ? fmtDate(c.last_used_at) : t('js.wl.never') }));
            tr.appendChild(el('td', { className: 'wl-ip', text: c.last_used_ip || '—', title: c.last_used_ip || '' }));
            tr.appendChild(el('td', { className: 'wl-num', text: String(c.requests_count) }));
            const act = el('td', { className: 'wl-actions' });
            act.appendChild(iconBtn('bi-pencil', t('js.wl.rename'), 'btn-outline-secondary', async () => {
                const label = await promptModal({ title: t('js.wl.rename_api_client'), label: t('js.wl.label'), value: c.label, okLabel: t('js.wl.rename'), maxlength: 100 });
                if (label === null || !label.trim()) return;
                const rr = await apiCall('admin/api_client_update', 'POST', { id: c.id, label: label.trim() });
                if (rr.success) { showToast(t('js.wl.renamed'), 'success'); loadClients(); } else showToast(rr.error || t('js.wl.rename_failed'), 'danger');
            }));
            act.appendChild(iconBtn('bi-trash', t('js.wl.delete_client'), 'btn-outline-danger', async () => {
                if (!await confirmAction(t('js.wl.delete_api_client'), t('js.wl.delete_client_confirm', { name: c.label }), { okLabel: t('js.wl.delete') })) return;
                const rr = await apiCall('admin/api_client_delete', 'POST', { id: c.id });
                if (rr.success) { showToast(t('js.wl.client_deleted'), 'success'); loadClients(); } else showToast(rr.error || t('js.wl.delete_failed'), 'danger');
            }));
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function initClientCreate() {
        const tokenModal = bootstrap.Modal.getOrCreateInstance($('tokenModal'));
        $('btn-cl-create').addEventListener('click', async () => {
            const label = await promptModal({ title: t('js.wl.create_api_client'), label: t('js.wl.client_label'), placeholder: t('js.wl.client_label_placeholder'), hint: t('js.wl.token_shown_once'), okLabel: t('js.wl.create'), maxlength: 100 });
            if (label === null || !label.trim()) return;
            const scope = await promptModal({ title: t('js.wl.client_scope'), label: t('js.wl.scope'), value: 'whitelist', hint: t('js.wl.scope_hint'), okLabel: t('js.wl.create'), maxlength: 16 });
            if (scope === null) return;
            const scopeVal = scope.trim().toLowerCase() || 'whitelist';
            if (!['whitelist', 'users', 'federation', 'all'].includes(scopeVal)) { showToast(t('js.wl.scope_invalid'), 'warning'); return; }
            try {
                const r = await apiCall('admin/api_client_create', 'POST', { label: label.trim(), scope: scopeVal });
                if (r.success) {
                    $('token-label').textContent = r.label;
                    $('token-keyid').textContent = r.key_id;
                    $('token-value').textContent = r.bearer;
                    tokenModal.show();
                    loadClients();
                } else showToast(r.error || t('js.wl.create_failed'), 'danger');
            } catch { showToast(t('js.wl.network_error'), 'danger'); }
        });
        $('token-copy').addEventListener('click', (e) => copyToClipboard($('token-value').textContent, e.currentTarget));
    }

    // ───────────────────────── API bans view ─────────────────────────
    async function loadApiBans() {
        const body = $('ab-body');
        body.textContent = '';
        body.appendChild(el('tr', null, el('td', { colspan: 9, className: 'text-center text-muted py-4' }, [el('span', { className: 'spinner-border spinner-border-sm' }), ' ' + t('js.common.loading')])));
        const p = new URLSearchParams({ page: String(state.ab.page), sort: abSort.serialize(), status: state.ab.status });
        if (state.ab.search) p.set('search', state.ab.search);
        let r;
        try { r = await apiCall('admin/fetch_api_bans&' + p.toString()); } catch { r = { error: t('js.wl.network_error') }; }
        body.textContent = '';
        if (r.error) { body.appendChild(el('tr', null, el('td', { colspan: 9, className: 'text-center text-danger py-4', text: r.error }))); return; }
        state.ab.rows = new Map(r.rows.map(x => [x.id, x]));
        $('ab-total').textContent = t(r.total === 1 ? 'js.wl.bans_one' : 'js.wl.bans_many', { n: r.total });
        if (!r.rows.length) body.appendChild(el('tr', null, el('td', { colspan: 9, className: 'text-center text-muted py-4', text: state.ab.status === 'active' ? t('js.wl.no_active_api_bans') : t('js.wl.no_api_bans') })));
        r.rows.forEach(b => {
            const tr = el('tr', { className: b.active ? '' : 'wl-row-muted' });
            tr.appendChild(el('td', { className: 'wl-ip wl-mono', title: b.ip }, el('code', { text: b.ip })));
            tr.appendChild(el('td', { className: 'text-muted wl-small wl-mono', text: b.ip_bucket !== b.ip ? b.ip_bucket : '', title: b.ip_bucket !== b.ip ? b.ip_bucket : '' }));
            tr.appendChild(el('td', { className: 'wl-reason', title: b.detail || b.reason || '' }, [badge(b.reason, b.reason === 'manual' ? 'wl-b-muted' : 'wl-b-bad'), b.detail ? el('div', { className: 'text-muted wl-small wl-ellipsis', text: b.detail }) : null]));
            tr.appendChild(el('td', { className: 'wl-mono' }, b.key_id ? el('code', { text: b.key_id }) : '—'));
            tr.appendChild(el('td', { className: 'wl-small wl-endpoint', text: b.endpoint || '—', title: b.endpoint || '' }));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(b.created_at) }));
            tr.appendChild(el('td', { className: 'wl-date', text: fmtDate(b.expires_at) }));
            tr.appendChild(el('td', { className: 'wl-date', text: b.lifted_at ? fmtDate(b.lifted_at) + (b.lifted_by ? ' (' + b.lifted_by + ')' : '') : (b.active ? '' : t('js.wl.expired')) }));
            const act = el('td', { className: 'wl-actions' });
            act.appendChild(iconBtn('bi-eye', b.has_snapshot ? t('js.wl.view_request', { size: fmtBytes(b.snapshot_len) }) : t('js.wl.view'), 'btn-outline-info', () => openSnapshot(b.id)));
            if (b.active) act.appendChild(iconBtn('bi-unlock', t('js.wl.lift_ban'), 'btn-outline-success', () => liftBan(b.id)));
            tr.appendChild(act);
            body.appendChild(tr);
        });
        renderPagination($('ab-pagination'), { total: r.total, page: r.page, pages: r.pages, onPage: (p2) => { state.ab.page = p2; loadApiBans(); } });
    }

    async function liftBan(id) {
        if (!await confirmAction(t('js.wl.lift_api_ban'), t('js.wl.lift_ban_confirm'), { okLabel: t('js.wl.lift'), danger: false })) return;
        const r = await apiCall('admin/api_ban_lift', 'POST', { id });
        if (r.success) { showToast(t('js.wl.ban_lifted'), 'success'); loadApiBans(); } else showToast(r.error || t('js.wl.failed'), 'danger');
    }

    let currentSnapshotJson = '';
    async function openSnapshot(id) {
        const modal = bootstrap.Modal.getOrCreateInstance($('snapshotModal'));
        $('snapshot-title-id').textContent = '#' + id;
        $('snapshot-meta').textContent = '';
        $('snapshot-pre').textContent = t('js.common.loading');
        $('snapshot-lift').classList.add('d-hidden');
        modal.show();
        const r = await apiCall('admin/fetch_api_bans&id=' + encodeURIComponent(id));
        if (r.error) { $('snapshot-pre').textContent = r.error; return; }
        const b = r.ban;
        const meta = $('snapshot-meta');
        const add = (k, v) => meta.appendChild(el('div', { className: 'wl-kv-item' }, [el('div', { className: 'wl-kv-label', text: k }), el('div', { className: 'wl-kv-value', text: v == null || v === '' ? '—' : String(v) })]));
        add(t('js.wl.snap_ip'), b.ip + (b.ip_bucket !== b.ip ? ' (' + b.ip_bucket + ')' : ''));
        add(t('js.wl.snap_reason'), b.reason + (b.detail ? ' — ' + b.detail : ''));
        add(t('js.wl.snap_key_id'), b.key_id);
        add(t('js.wl.snap_endpoint'), b.endpoint);
        add(t('js.wl.snap_created'), fmtDate(b.created_at));
        add(t('js.wl.snap_expires'), fmtDate(b.expires_at) + (b.active ? ' (' + t('js.wl.active') + ')' : ''));
        add(t('js.wl.snap_lifted'), b.lifted_at ? fmtDate(b.lifted_at) + (b.lifted_by ? ' ' + t('js.wl.lifted_by', { name: b.lifted_by }) : '') : '');
        currentSnapshotJson = b.request_snapshot == null ? '' : (typeof b.request_snapshot === 'string' ? b.request_snapshot : JSON.stringify(b.request_snapshot, null, 2));
        $('snapshot-pre').textContent = currentSnapshotJson || t('js.wl.no_snapshot');
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
            if (r.success) { showToast(t('js.wl.ip_banned_for_days', { ip: $('ab-add-ip').value.trim(), n: r.days }), 'success'); modal.hide(); loadApiBans(); }
            else alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || t('js.wl.failed') }));
        });
        $('snapshot-copy').addEventListener('click', (e) => copyToClipboard(currentSnapshotJson || '', e.currentTarget));
    }

    // ───────────────────────── init ─────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        // Same wait on all three tabs: Banned and API bans used to fire on the click itself, which is
        // the same complaint as a too-short debounce, only worse.
        const loadWlDebounced = debounce(() => loadWhitelist(), SORT_DEBOUNCE_MS);
        const loadBnDebounced = debounce(() => loadBanned(), SORT_DEBOUNCE_MS);
        const loadAbDebounced = debounce(() => loadApiBans(), SORT_DEBOUNCE_MS);
        wlSort = makeSortStack({ table: $('wl-table'), defaultSort: [{ col: 'date', dir: 'desc' }], onChange: () => { state.wl.page = 1; loadWlDebounced(); } });
        bnSort = makeSortStack({ table: $('bn-table'), defaultSort: [{ col: 'date', dir: 'desc' }], onChange: () => { state.bn.page = 1; loadBnDebounced(); } });
        abSort = makeSortStack({ table: $('ab-table'), defaultSort: [{ col: 'date', dir: 'desc' }], onChange: () => { state.ab.page = 1; loadAbDebounced(); } });
        wlSort.bindHeaders(); bnSort.bindHeaders(); abSort.bindHeaders();

        document.querySelectorAll('#wl-tabs .source-tab').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));

        $('btn-logout').addEventListener('click', async () => { await apiCall('admin/logout', 'POST', {}); window.location.href = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '') + '?action=' + (document.body.dataset.loginPath || 'admin'); });
        $('btn-wl-regen').addEventListener('click', regenerate);
        $('btn-wl-import').addEventListener('click', importBlacklist);
        initReloadModal();
        initAddModal();
        initBanModal();
        initClientCreate();
        initApiBanAdd();

        // review toolbar + sub-tabs
        const rvSearch = $('rv-search');
        if (rvSearch) {
            const doRv = debounce(() => { rvState.search = rvSearch.value.trim(); rvState.page = 1; loadReview(); }, window.AdminCommon.DEBOUNCE.search);
            rvSearch.addEventListener('input', doRv);
            bindSearchClear(rvSearch, $('rv-search-clear'), () => { rvState.search = ''; rvState.page = 1; loadReview(); });
            $('rv-status').addEventListener('change', (e) => { rvState.status = e.target.value; rvState.page = 1; loadReview(); });
            $('rv-subtabs').addEventListener('click', (e) => {
                const b = e.target.closest('[data-sub]');
                if (b) rvShowSub(b.dataset.sub);
            });
        }

        // whitelist toolbar
        const searchInput = $('wl-search');
        const doSearch = debounce(() => { state.wl.search = searchInput.value.trim(); state.wl.page = 1; loadWhitelist(); }, window.AdminCommon.DEBOUNCE.search);
        searchInput.addEventListener('input', doSearch);
        bindSearchClear(searchInput, $('wl-search-clear'), () => { state.wl.search = ''; state.wl.page = 1; loadWhitelist(); });
        $('wl-search-files').addEventListener('change', (e) => { state.wl.searchFiles = e.target.checked; if (state.wl.search) loadWhitelist(); });
        $('wl-filter-source').addEventListener('change', (e) => { state.wl.source = e.target.value; state.wl.page = 1; loadWhitelist(); });
        $('wl-filter-meta').addEventListener('change', (e) => { state.wl.meta = e.target.value; state.wl.page = 1; loadWhitelist(); });
        const wlPp = $('wl-perpage');
        if (wlPp) {
            try { const v = localStorage.getItem('thx_wl_perpage'); if (v && [...wlPp.options].some(o => o.value === v)) wlPp.value = v; } catch (e) {}
            wlPp.addEventListener('change', () => { try { localStorage.setItem('thx_wl_perpage', wlPp.value); } catch (e) {} state.wl.page = 1; loadWhitelist(); });
        }
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
        document.querySelectorAll('#wl-meta-bulk-group [data-meta-restore]').forEach(b => b.addEventListener('click', async () => {
            closeMenu('btn-wl-meta-bulk');
            const r = await apiCall('admin/whitelist_meta_queue', 'POST', { scope: 'restore' });
            if (r.success) { showToast(r.restored ? t('js.wl.restored_rows', { n: r.restored }) : t('js.wl.nothing_to_restore'), r.restored ? 'success' : 'info'); loadWhitelist(); loadStatus(); }
            else showToast(r.error || t('js.wl.rebuild_failed'), 'danger');
        }));
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
            label.textContent = t('js.wl.collecting');
            let rows;
            try { rows = await collectNearRowsWl(); }
            catch (e) { showToast(t('js.wl.near_pages_failed_2', { error: e.message || t('js.wl.error') }), 'danger'); return; }
            finally { collectingNear = false; label.textContent = orig; }
            if (!rows.length) { showToast(t('js.wl.no_rows_near_pages'), 'info'); return; }
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
        bnSearch.addEventListener('input', debounce(() => { state.bn.search = bnSearch.value.trim(); state.bn.page = 1; loadBanned(); }, window.AdminCommon.DEBOUNCE.search));
        $('bn-search-clear').addEventListener('click', () => { bnSearch.value = ''; state.bn.search = ''; state.bn.page = 1; loadBanned(); });
        // api bans toolbar
        const abSearch = $('ab-search');
        abSearch.addEventListener('input', debounce(() => { state.ab.search = abSearch.value.trim(); state.ab.page = 1; loadApiBans(); }, window.AdminCommon.DEBOUNCE.search));
        $('ab-search-clear').addEventListener('click', () => { abSearch.value = ''; state.ab.search = ''; state.ab.page = 1; loadApiBans(); });
        $('ab-status').addEventListener('change', (e) => { state.ab.status = e.target.value; state.ab.page = 1; loadApiBans(); });

        loadStatus();
        statusTimer = setInterval(loadStatus, 30000);
        loadWhitelist();
        const linked = hashFromUrl();
        if (linked) openDetails(linked);
    });
})();
