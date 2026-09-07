/**
 * Admin → Backups (?action=admin-backups) — includes/backup.php on the server.
 *
 * The page has one job beyond listing files: make it obvious whether the backups an admin *thinks*
 * they have actually exist and are readable. So every row carries its integrity state, the status
 * card says in plain words what each archive covers, and a run streams its own log while it happens
 * instead of spinning silently.
 *
 * "Database only" is a scope, not a failure: backing up a whole server is a different job for a
 * different tool. The card states what it covers and leaves it at that.
 *
 * Everything that changes something goes through one password modal and one endpoint
 * (admin/backup_action). Restoring the database additionally makes a person type the database name,
 * which is the same guard Backup-serwera.sh enforces at a terminal.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    if (typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, fmtAgo, fmtBytes, fmtDate, makeSortStack } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);
    const POLL_IDLE = 15000;     // nothing running: the list barely changes
    const POLL_BUSY = 2000;      // a run is in flight: show it moving

    const state = {
        dbName: document.body.dataset.backupDb || 'tracker',
        archives: [],
        status: null,
        pending: null,           // {op, id, needsName, …}
        restoreId: null,
        timer: null,
        busy: false,
    };

    const badge = (text, cls) => el('span', { className: 'wl-badge ' + (cls || 'wl-b-muted'), text: text });
    const kv = (label, value) => el('div', { className: 'wl-kv-item' }, [
        el('div', { className: 'wl-kv-label', text: label }),
        el('div', { className: 'wl-kv-value' }, value),
    ]);
    const when = (ts) => ts ? fmtDate(new Date(ts * 1000).toISOString()) : '—';
    // AdminCommon.fmtBytes renders 0 as an em dash, which reads as "unknown" here — an empty
    // backup directory really is zero bytes, and saying so is the point.
    const bytes = (n) => (Number(n) > 0 ? fmtBytes(n) : '0 B');
    const NL2 = String.fromCharCode(10, 10);   // blank line between a message and the raw output

    // ── status ───────────────────────────────────────────────────────────────
    async function load() {
        let j;
        try { j = await apiCall('admin/backup_status'); } catch { return; }
        if (!j) return;
        state.status = j;
        state.archives = j.archives || [];
        renderStatus(j);
        renderRows(j);
        const running = !!(j.status && j.status.running);
        renderProgress(j.status, running);
        if (running !== state.busy) {
            state.busy = running;
            clearInterval(state.timer);
            state.timer = setInterval(load, running ? POLL_BUSY : POLL_IDLE);
        }
        $('btn-bk-cancel').classList.toggle('d-hidden', !running);
        // The buttons start disabled in the markup: their confirmation text quotes the live
        // settings, so offering them before the first answer arrives would show "undefined".
        $('btn-bk-run').disabled = running;
        $('btn-bk-prune').disabled = false;
    }

    function renderStatus(j) {
        const grid = $('bk-status-grid');
        grid.textContent = '';
        const cfg = j.configured || {};
        const chk = j.check || {};
        const st = j.status || {};

        // 1. can this machine make a backup at all, and of what
        if (j.error && !chk.mode) {
            grid.appendChild(kv(t('js.backups.label_backups'), [badge(t('js.backups.unavailable'), 'wl-b-bad'), ' ',
                el('span', { className: 'wl-small text-muted', text: j.error })]));
        } else if (chk.mode === 'script') {
            grid.appendChild(kv(t('js.backups.label_covers'), [badge(t('js.backups.covers_db_files'), 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: t('js.backups.via_script') }),
                el('div', { className: 'wl-small text-muted', text: t('js.backups.covers_script_desc') })]));
        } else if (chk.mariadb_dump) {
            // Not a degraded state: backing up the whole server is a different job, done by a
            // different tool. Say what this covers, and do not nag about what it deliberately is not.
            grid.appendChild(kv(t('js.backups.label_covers'), [badge(t('js.backups.db_only'), 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: t('js.backups.covers_tracker_db') }),
                el('div', { className: 'wl-small text-muted', text: t('js.backups.covers_db_only_desc') })]));
        } else {
            grid.appendChild(kv(t('js.backups.label_mode'), [badge(t('js.backups.nothing_available'), 'wl-b-bad'),
                el('div', { className: 'wl-small text-muted', text: (chk.error || t('js.backups.no_tools')) })]));
        }

        grid.appendChild(kv(t('js.backups.label_profile'), [
            el('span', { text: cfg.profile_label || cfg.profile || '—' }),
            el('div', { className: 'wl-small text-muted', text: cfg.items || '' }),
        ]));

        // 2. the schedule
        const sc = j.schedule || {};
        const schedParts = cfg.enabled
            ? [sc.valid ? badge(t('js.backups.on'), 'wl-b-ok') : badge(t('js.backups.off'), 'wl-b-muted'), ' ',
               el('span', { className: 'text-muted', text: sc.describe || '' })]
            : [badge(t('js.backups.off'), 'wl-b-muted'), ' ',
               el('span', { className: 'wl-small text-muted', text: t('js.backups.sched_off_desc') })];
        if (cfg.enabled && sc.next) {
            schedParts.push(el('div', { className: 'wl-small text-muted', text: t('js.backups.next_run', { when: when(sc.next) }) }));
        }
        grid.appendChild(kv(t('js.backups.label_schedule'), schedParts));

        // 3. the last run
        if (st.state && st.state !== 'idle') {
            const okRun = st.state === 'done';
            const parts = [badge(okRun ? t('js.backups.status_ok') : (st.state === 'running' ? t('js.backups.status_running') : t('js.backups.status_failed')),
                                 okRun ? 'wl-b-ok' : (st.state === 'running' ? 'wl-b-pending' : 'wl-b-bad')), ' ',
                el('span', { className: 'text-muted', text: st.finished_at ? t('js.backups.ago', { t: fmtAgo(Math.floor(j.server_time - st.finished_at)) }) : (st.started_at ? t('js.backups.started_ago', { t: fmtAgo(Math.floor(j.server_time - st.started_at)) }) : '') })];
            if (st.bytes) parts.push(el('div', { className: 'wl-small text-muted', text: bytes(st.bytes) + (st.encrypted ? t('js.backups.encrypted_suffix') : '') }));
            if (st.error) parts.push(el('div', { className: 'wl-small text-danger', text: st.error }));
            if (st.pruned) parts.push(el('div', { className: 'wl-small text-muted', text: t('js.backups.rotation_removed', { n: st.pruned }) }));
            grid.appendChild(kv(t('js.backups.label_last_run'), parts));
        } else {
            grid.appendChild(kv(t('js.backups.label_last_run'), [el('span', { className: 'text-muted', text: t('js.backups.never_run') })]));
        }

        // 4. where the archives are and how much room is left
        grid.appendChild(kv(t('js.backups.label_directory'), [
            el('code', { className: 'wl-path', text: cfg.dir || '—' }),
            el('div', { className: 'wl-small text-muted', text:
                t('js.backups.usage', { n: (j.archives || []).length, used: bytes(j.total_bytes || 0) }) +
                (j.free_bytes ? t('js.backups.free', { free: bytes(j.free_bytes) }) : '') }),
        ]));
        grid.appendChild(kv(t('js.backups.label_retention'), [
            el('span', { text: (cfg.keep ? t('js.backups.keep_n', { n: cfg.keep }) : t('js.backups.no_count_limit'))
                + ' · ' + (cfg.keep_days ? t('js.backups.n_days', { n: cfg.keep_days }) : t('js.backups.no_age_limit'))
                + ' · ' + (cfg.max_gb ? t('js.backups.max_gb', { n: cfg.max_gb }) : t('js.backups.no_size_limit')) }),
            el('div', { className: 'wl-small text-muted', text: t('js.backups.retention_desc') }),
        ]));
        grid.appendChild(kv(t('js.backups.label_encryption'), cfg.gpg
            ? [badge('gpg', 'wl-b-ok'), ' ', el('span', { className: 'text-muted', text: cfg.gpg })]
            : [badge(t('js.backups.none'), 'wl-b-warn'), ' ', el('span', { className: 'wl-small text-muted', text: t('js.backups.encryption_none_desc') })]));

        $('bk-dir-label').textContent = cfg.dir || '';
        $('bk-total').textContent = t('js.backups.total', { n: (j.archives || []).length, size: bytes(j.total_bytes || 0) });
        $('bk-status-updated').textContent = t('js.backups.updated_at', { time: new Date().toLocaleTimeString() });
        renderNotes(j);
    }

    function renderNotes(j) {
        const box = $('bk-notes');
        box.textContent = '';
        const chk = j.check || {};
        if (chk.hint) box.appendChild(el('div', { className: 'nl-note nl-note-info' }, [el('i', { className: 'bi bi-info-circle' }), ' ' + chk.hint]));
        if (chk.include_ok === false) { /* not applicable here */ }
        if (j.error && chk.mode) box.appendChild(el('div', { className: 'nl-note nl-note-warn' }, [el('i', { className: 'bi bi-exclamation-triangle' }), ' ' + j.error]));
        if (j.last_error) box.appendChild(el('div', { className: 'nl-note nl-note-bad' }, [
            el('i', { className: 'bi bi-x-octagon' }),
            el('span', { text: ' ' + t('js.backups.last_failure', { error: j.last_error }) + (j.last_error_at ? ' (' + t('js.backups.ago', { t: fmtAgo(Math.floor(j.server_time - j.last_error_at)) }) + ')' : '') }),
        ]));
        if (!(j.archives || []).length && (chk.mode)) box.appendChild(el('div', { className: 'nl-note nl-note-warn' }, [
            el('i', { className: 'bi bi-hdd-stack' }),
            el('span', { text: ' ' + t('js.backups.no_backup_yet') }),
        ]));
    }

    function renderProgress(st, running) {
        const box = $('bk-progress');
        box.classList.toggle('d-hidden', !running);
        if (!running) return;
        $('bk-progress-step').textContent = st.step || t('js.backups.working');
        const bits = [];
        if (st.id) bits.push(st.id);
        if (st.started_at) bits.push(t('js.backups.started_at', { when: when(st.started_at) }));
        if (st.bytes) bits.push(bytes(st.bytes));
        $('bk-progress-meta').textContent = bits.join(' · ');
        $('bk-progress-log').textContent = st.log_tail || '';
        $('bk-progress-log').scrollTop = $('bk-progress-log').scrollHeight;
    }

    // ── sorting ──────────────────────────────────────────────────────────────
    // The list is a handful of files the helper already handed over, so this sorts in place: no
    // request. It still waits the shared sort debounce before repainting, because a header click is
    // one of two or three (desc → asc → off) and a table that reshuffles under every click reads as
    // jumpy; the arrows move at once, the rows follow when the decision is made. Same header
    // behaviour as every other table in the panel via the shared sort stack.
    let bkSort = null;
    let lastList = [];
    let lastServerTime = 0;   // re-sorting repaints the rows, and the "x ago" column is server-relative
    let lastCheck = null;     // the empty-table wording depends on it, so keep it for a repaint
    const SORT_KEYS = {
        when:      a => a.ts || 0,
        profile:   a => (a.profile || (a.mode === 'builtin' ? t('js.backups.db_only') : '')).toLowerCase(),
        size:      a => a.size || 0,
        // three states, ordered worst-first so one click surfaces what needs attention
        integrity: a => (a.verified === false ? 0 : a.verified === true ? 2 : 1),
    };
    function sortList(list) {
        const stack = bkSort ? bkSort.get() : [];
        if (!stack.length) return list;
        return list.slice().sort((x, y) => {
            for (const { col, dir } of stack) {
                const get = SORT_KEYS[col];
                if (!get) continue;
                const a = get(x), b = get(y);
                if (a < b) return dir === 'asc' ? -1 : 1;
                if (a > b) return dir === 'asc' ? 1 : -1;
            }
            return 0;
        });
    }

    // ── the table ────────────────────────────────────────────────────────────
    function renderRows(j) {
        const tb = $('bk-rows');
        tb.textContent = '';
        lastList = j.archives || [];
        if (j.server_time) lastServerTime = j.server_time;
        if (j.check) lastCheck = j.check;
        const list = sortList(lastList);
        if (!list.length) {
            tb.appendChild(el('tr', {}, [el('td', { colspan: '6', className: 'text-center text-muted py-4',
                text: (j.check && j.check.mode) ? t('js.backups.no_archives') : t('js.backups.not_available_here') })]));
            return;
        }
        list.forEach(a => {
            const acts = el('div', { className: 'wl-actions' });
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-info wl-act', type: 'button', title: t('js.backups.verify_title'),
                onclick: () => ask('verify', { id: a.id }) }, [el('i', { className: 'bi bi-patch-check' })]));
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-secondary wl-act', type: 'button', title: t('js.backups.download_title'),
                onclick: () => ask('token', { id: a.id }) }, [el('i', { className: 'bi bi-download' })]));
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-warning wl-act', type: 'button', title: t('js.backups.restore_title'),
                onclick: () => openRestore(a) }, [el('i', { className: 'bi bi-arrow-counterclockwise' })]));
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-danger wl-act', type: 'button', title: t('js.backups.delete_title'),
                onclick: () => ask('delete', { id: a.id }) }, [el('i', { className: 'bi bi-trash' })]));

            const integrity = a.verified === true ? [badge(t('js.backups.verified'), 'wl-b-ok')]
                : a.verified === false ? [badge(t('js.backups.status_failed'), 'wl-b-bad')]
                : [badge(t('js.backups.not_checked'), 'wl-b-muted')];
            if (a.encrypted) integrity.push(' ', el('i', { className: 'bi bi-lock-fill text-info', title: t('js.backups.encrypted_gpg') }));

            tb.appendChild(el('tr', {}, [
                el('td', {}, [el('span', { text: when(a.ts) }),
                              el('div', { className: 'wl-small text-muted', text: a.ts ? t('js.backups.ago', { t: fmtAgo(Math.floor(j.server_time - a.ts)) }) : '' })]),
                el('td', {}, [el('span', { text: a.profile || (a.mode === 'builtin' ? t('js.backups.db_only') : '—') }),
                    // The built-in dump names every file tracker-db-*, whatever profile made it, so
                    // the filename contradicts the choice. The items are the truth and are shown here.
                    a.items ? el('div', { className: 'wl-small text-muted', title: a.items,
                                          text: includesFullDb(a) ? t('js.backups.full_db_files') : t('js.backups.n_items', { n: String(a.items).split(',').length }) }) : '',
                              a.mode === 'builtin' ? el('div', { className: 'wl-small text-warning', text: t('js.backups.builtin_dump') }) : null]),
                el('td', {}, [
                    el('span', { text: bytes(a.size || 0) }),
                    // An archive that holds the whole database looks alarmingly small next to it, so
                    // the ratio is shown rather than left for the reader to doubt.
                    (a.size && state.status && state.status.db_bytes && includesFullDb(a))
                        ? el('div', { className: 'wl-small text-muted',
                                      title: t('js.backups.db_size_title', { size: bytes(state.status.db_bytes) })
                                           + '',
                                      text: t('js.backups.compressed', { n: Math.round(state.status.db_bytes / a.size) }) })
                        : '',
                ]),
                el('td', {}, [el('span', { className: 'wl-small text-muted', text: a.items || (a.mode === 'builtin' ? t('js.backups.tracker_db') : '—') })]),
                el('td', {}, integrity),
                el('td', { className: 'th-actions' }, [acts]),
            ]));
        });
    }

    // ── actions ──────────────────────────────────────────────────────────────
    /** Does this archive's item list include the FULL database (not the light dump)? */
    function includesFullDb(a) {
        const items = String(a.items || '');
        return items.indexOf('tracker-db') !== -1 && items.indexOf('tracker-db-lekka') === -1;
    }

    const COPY = {
        run: {
            title: t('js.backups.run_title'), ok: t('js.backups.run_ok'), cls: 'btn-outline-success',
            text: () => {
                const c = (state.status && state.status.configured) || {};
                return t('js.backups.run_text', { dir: c.dir || t('js.backups.backup_dir') }) +
                       (c.nice !== undefined ? t('js.backups.run_nice', { n: c.nice }) : '') +
                       t('js.backups.run_text_tail');
            },
        },
        cancel: { title: t('js.backups.cancel_title'), ok: t('js.backups.cancel_ok'), cls: 'btn-outline-warning',
                  text: () => t('js.backups.cancel_text') },
        verify: { title: t('js.backups.verify_modal_title'), ok: t('js.backups.verify_ok'), cls: 'btn-outline-info',
                  text: () => t('js.backups.verify_text') },
        prune:  { title: t('js.backups.prune_title'), ok: t('js.backups.prune_ok'), cls: 'btn-outline-secondary',
                  text: () => {
                      const c = (state.status && state.status.configured) || {};
                      const limits = [c.keep ? t('js.backups.keep_n', { n: c.keep }) : null, c.keep_days ? t('js.backups.n_days', { n: c.keep_days }) : null,
                                      c.max_gb ? t('js.backups.max_gb', { n: c.max_gb }) : null].filter(Boolean).join(', ');
                      return t('js.backups.prune_text') + (limits ? ' (' + limits + ')' : '') +
                             t('js.backups.prune_text_tail');
                  } },
        delete: { title: t('js.backups.delete_title'), ok: t('js.backups.delete_ok'), cls: 'btn-outline-danger',
                  text: () => t('js.backups.delete_text') },
        token:  { title: t('js.backups.token_title'), ok: t('js.backups.token_ok'), cls: 'btn-outline-secondary',
                  text: () => t('js.backups.token_text') },
        restore: { title: t('js.backups.restore_files_title'), ok: t('js.backups.restore_ok'), cls: 'btn-outline-warning',
                   text: () => t('js.backups.restore_text') },
        'restore-db': {
            title: t('js.backups.restore_db_title'), ok: t('js.backups.restore_db_ok'), cls: 'btn-outline-danger', needsName: true,
            text: () => t('js.backups.restore_db_text', { db: state.dbName }),
        },
    };

    function ask(op, opts) {
        const c = COPY[op];
        if (!c) return;
        state.pending = Object.assign({ op: op }, opts || {});
        $('bk-modal-title').textContent = c.title;
        $('bk-modal-text').textContent = c.text();
        const extra = $('bk-modal-extra');
        extra.textContent = '';
        if (state.pending.id) extra.appendChild(el('div', { className: 'wl-small text-muted mb-2' }, [
            el('span', { text: t('js.backups.archive_label') }), el('code', { text: state.pending.id })]));
        if (state.pending.items) extra.appendChild(el('div', { className: 'wl-small text-muted mb-2' }, [
            el('span', { text: t('js.backups.items_label') }), el('code', { text: state.pending.items })]));
        // The profile picker appears for a manual run only. Every other operation acts on an archive
        // that already exists, where "what to back up" is a question about the past.
        const profRow = $('bk-confirm-profile-row');
        const profSel = $('bk-confirm-profile');
        profRow.classList.toggle('d-hidden', op !== 'run');
        if (op === 'run') {
            const cfgNow = (state.status && state.status.configured) || {};
            const list = (state.status && state.status.profiles) || [];
            profSel.textContent = '';
            list.forEach(pr => profSel.appendChild(el('option', {
                value: pr.id, text: pr.label + (pr.id === cfgNow.profile ? t('js.backups.configured_suffix') : '') })));
            profSel.value = cfgNow.profile || (list[0] && list[0].id) || '';
            const hint = $('bk-confirm-profile-hint');
            const describe = () => {
                const chosen = list.find(x => x.id === profSel.value);
                hint.textContent = (chosen && chosen.hint) || '';
            };
            profSel.onchange = describe;
            describe();
        }
        $('bk-confirm-name-row').classList.toggle('d-hidden', !c.needsName);
        $('bk-confirm-name').value = '';
        if (c.needsName) $('bk-confirm-name').placeholder = state.dbName;
        const okBtn = $('bk-confirm-ok');
        okBtn.className = 'btn btn-sm ' + c.cls;
        okBtn.textContent = '';
        okBtn.appendChild(el('i', { className: 'bi bi-check-lg' }));
        okBtn.appendChild(document.createTextNode(' ' + c.ok));
        $('bk-confirm-alert').textContent = '';
        $('bk-confirm-password').value = '';
        bootstrap.Modal.getOrCreateInstance($('bkConfirmModal')).show();
        setTimeout(() => (c.needsName ? $('bk-confirm-name') : $('bk-confirm-password')).focus(), 300);
    }

    async function runPending(e) {
        e.preventDefault();
        const p = state.pending;
        if (!p) return;
        const alert = $('bk-confirm-alert');
        const btn = $('bk-confirm-ok');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.backups.working');
        alert.textContent = '';

        const body = { op: p.op, password: $('bk-confirm-password').value };
        if (p.id) body.id = p.id;
        if (p.items) body.items = p.items;
        // Send the profile only for a run, and only when it differs from nothing — the endpoint
        // already falls back to the configured one for an empty value.
        if (p.op === 'run') {
            const chosen = $('bk-confirm-profile').value;
            if (chosen) body.profile = chosen;
        }
        if (p.dry_run) body.dry_run = true;
        if (p.op === 'restore-db') { body.db = state.dbName; body.confirm = $('bk-confirm-name').value.trim(); }

        try {
            const r = await apiCall('admin/backup_action', 'POST', body);
            if (r.success) {
                bootstrap.Modal.getOrCreateInstance($('bkConfirmModal')).hide();
                if (p.op === 'token' && r.url) {
                    // Navigating starts the download; the token is burned by the first request.
                    showToast(t('js.backups.download_starting'), 'success');
                    window.location.href = r.url;
                } else if (p.op === 'verify') {
                    // a verification result is the answer, not a side effect — say which way it went
                    showToast(r.message || t('js.backups.checked'), 'success');
                } else {
                    showToast(r.message || t('js.backups.done'), 'success');
                }
                // a restore (dry run or not) reports inside the restore modal, which stays open
                if (p.op === 'restore') {
                    const outEl = $('bk-restore-output');
                    outEl.textContent = (r.message || '') + (r.output ? NL2 + r.output : '');
                    outEl.classList.remove('d-hidden');
                }
                if (p.op === 'run') { state.busy = true; clearInterval(state.timer); state.timer = setInterval(load, POLL_BUSY); }
                if (p.op === 'restore-db') bootstrap.Modal.getOrCreateInstance($('bkRestoreModal')).hide();
                state.pending = null;
                load();
            } else {
                alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || t('js.backups.failed') }));
                if (r.output) alert.appendChild(el('pre', { className: 'bk-log', text: r.output }));
            }
            if (p.op === 'restore' && !r.success) {
                const outEl = $('bk-restore-output');
                outEl.textContent = (r.error || '') + (r.output ? NL2 + r.output : '');
                outEl.classList.remove('d-hidden');
            }
        } catch {
            alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: t('js.backups.network_error') }));
        }
        btn.disabled = false;
        btn.innerHTML = orig;
    }

    // ── restore ──────────────────────────────────────────────────────────────
    function openRestore(a) {
        state.restoreId = a.id;
        $('bk-restore-id').textContent = a.id;
        $('bk-restore-alert').textContent = '';
        $('bk-restore-output').textContent = '';
        $('bk-restore-output').classList.add('d-hidden');

        const box = $('bk-restore-items');
        box.textContent = '';
        const items = (a.items || '').split(',').map(s => s.trim()).filter(Boolean);
        const fileItems = items.filter(i => !/-db(-lekka)?$/.test(i));
        const dbItems = items.filter(i => /-db(-lekka)?$/.test(i));
        if (!fileItems.length) {
            box.appendChild(el('div', { className: 'wl-small text-muted', text:
                a.mode === 'builtin' ? t('js.backups.builtin_no_files')
                                     : t('js.backups.no_file_items') }));
        } else {
            fileItems.forEach(i => {
                const id = 'bk-it-' + i;
                box.appendChild(el('div', { className: 'form-check' }, [
                    el('input', { className: 'form-check-input', type: 'checkbox', id: id, value: i }),
                    el('label', { className: 'form-check-label', for: id, text: i }),
                ]));
            });
        }
        $('bk-db-restore-box').classList.toggle('d-hidden', !dbItems.length);
        bootstrap.Modal.getOrCreateInstance($('bkRestoreModal')).show();
    }

    function selectedItems() {
        return [...document.querySelectorAll('#bk-restore-items input:checked')].map(c => c.value).join(',');
    }

    async function restoreDry() {
        const items = selectedItems();
        const alert = $('bk-restore-alert');
        alert.textContent = '';
        if (!items) { alert.appendChild(el('div', { className: 'alert alert-warning py-2 wl-small', text: t('js.backups.tick_one') })); return; }
        // The dry run changes nothing, but it still runs a privileged command — so it asks for the
        // password like everything else on this page.
        ask('restore', { id: state.restoreId, items: items, dry_run: true });
    }

    // ── wiring ───────────────────────────────────────────────────────────────
    function init() {
        // Newest first is what an admin wants to see when this page opens: the archive they are
        // about to rely on is almost always the last one taken.
        bkSort = makeSortStack({
            table: $('bk-table'),
            defaultSort: [{ col: 'when', dir: 'desc' }],
            onChange: window.AdminCommon.debounce(() => renderRows({ archives: lastList, server_time: lastServerTime, check: lastCheck }), window.AdminCommon.DEBOUNCE.sort),
        });
        bkSort.bindHeaders();
        $('btn-bk-run').addEventListener('click', () => ask('run', {}));
        $('btn-bk-cancel').addEventListener('click', () => ask('cancel', {}));
        $('btn-bk-prune').addEventListener('click', () => ask('prune', {}));
        $('bk-confirm-form').addEventListener('submit', runPending);
        // The password modal opens ON TOP of the restore modal. Bootstrap drops body.modal-open when
        // the top one closes, which unlocks scrolling under the one still open — put it back.
        $('bkConfirmModal').addEventListener('hidden.bs.modal', () => {
            if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
        });
        $('btn-bk-restore-dry').addEventListener('click', restoreDry);
        $('btn-bk-restore-go').addEventListener('click', () => {
            const items = selectedItems();
            const alert = $('bk-restore-alert');
            alert.textContent = '';
            if (!items) { alert.appendChild(el('div', { className: 'alert alert-warning py-2 wl-small', text: t('js.backups.tick_one') })); return; }
            ask('restore', { id: state.restoreId, items: items });
        });
        $('btn-bk-restore-db').addEventListener('click', () => ask('restore-db', { id: state.restoreId }));
        $('btn-logout').addEventListener('click', async () => {
            await apiCall('admin/logout', 'POST');
            window.location.href = '?action=' + (document.body.dataset.loginPath || 'admin');
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
        load();
        state.timer = setInterval(load, POLL_IDLE);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
