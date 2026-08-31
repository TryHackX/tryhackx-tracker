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
            grid.appendChild(kv('Backups', [badge('unavailable', 'wl-b-bad'), ' ',
                el('span', { className: 'wl-small text-muted', text: j.error })]));
        } else if (chk.mode === 'script') {
            grid.appendChild(kv('Covers', [badge('database + files', 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: 'via Backup-serwera.sh' }),
                el('div', { className: 'wl-small text-muted', text: 'Database, configuration, lists, units and firewall rules — whatever the profile asks for.' })]));
        } else if (chk.mariadb_dump) {
            // Not a degraded state: backing up the whole server is a different job, done by a
            // different tool. Say what this covers, and do not nag about what it deliberately is not.
            grid.appendChild(kv('Covers', [badge('database only', 'wl-b-ok'), ' ',
                el('span', { className: 'text-muted', text: 'the tracker database' }),
                el('div', { className: 'wl-small text-muted', text: 'Whole-server backups — mail, the forum, certificates — are a separate job. If Backup-serwera.sh is installed here, this page will use it and offer its items too.' })]));
        } else {
            grid.appendChild(kv('Mode', [badge('nothing available', 'wl-b-bad'),
                el('div', { className: 'wl-small text-muted', text: (chk.error || 'No dump client and no toolkit on this machine.') })]));
        }

        grid.appendChild(kv('Profile', [
            el('span', { text: cfg.profile_label || cfg.profile || '—' }),
            el('div', { className: 'wl-small text-muted', text: cfg.items || '' }),
        ]));

        // 2. the schedule
        const sc = j.schedule || {};
        const schedParts = cfg.enabled
            ? [sc.valid ? badge('on', 'wl-b-ok') : badge('off', 'wl-b-muted'), ' ',
               el('span', { className: 'text-muted', text: sc.describe || '' })]
            : [badge('off', 'wl-b-muted'), ' ',
               el('span', { className: 'wl-small text-muted', text: 'Backups are switched off in Settings — nothing runs on a timer.' })];
        if (cfg.enabled && sc.next) {
            schedParts.push(el('div', { className: 'wl-small text-muted', text: 'next: ' + when(sc.next) }));
        }
        grid.appendChild(kv('Schedule', schedParts));

        // 3. the last run
        if (st.state && st.state !== 'idle') {
            const okRun = st.state === 'done';
            const parts = [badge(okRun ? 'ok' : (st.state === 'running' ? 'running' : 'FAILED'),
                                 okRun ? 'wl-b-ok' : (st.state === 'running' ? 'wl-b-pending' : 'wl-b-bad')), ' ',
                el('span', { className: 'text-muted', text: st.finished_at ? fmtAgo(Math.floor(j.server_time - st.finished_at)) + ' ago' : (st.started_at ? 'started ' + fmtAgo(Math.floor(j.server_time - st.started_at)) + ' ago' : '') })];
            if (st.bytes) parts.push(el('div', { className: 'wl-small text-muted', text: bytes(st.bytes) + (st.encrypted ? ' · encrypted' : '') }));
            if (st.error) parts.push(el('div', { className: 'wl-small text-danger', text: st.error }));
            if (st.pruned) parts.push(el('div', { className: 'wl-small text-muted', text: 'rotation removed: ' + st.pruned }));
            grid.appendChild(kv('Last run', parts));
        } else {
            grid.appendChild(kv('Last run', [el('span', { className: 'text-muted', text: 'never — press "Back up now"' })]));
        }

        // 4. where the archives are and how much room is left
        grid.appendChild(kv('Directory', [
            el('code', { className: 'wl-path', text: cfg.dir || '—' }),
            el('div', { className: 'wl-small text-muted', text:
                (j.archives || []).length + ' archive(s) · ' + bytes(j.total_bytes || 0) + ' used' +
                (j.free_bytes ? ' · ' + bytes(j.free_bytes) + ' free' : '') }),
        ]));
        grid.appendChild(kv('Retention', [
            el('span', { text: (cfg.keep ? 'keep ' + cfg.keep : 'no count limit')
                + ' · ' + (cfg.keep_days ? cfg.keep_days + ' days' : 'no age limit')
                + ' · ' + (cfg.max_gb ? 'max ' + cfg.max_gb + ' GB' : 'no size limit') }),
            el('div', { className: 'wl-small text-muted', text: 'The oldest go first, and the last archive standing is never deleted.' }),
        ]));
        grid.appendChild(kv('Encryption', cfg.gpg
            ? [badge('gpg', 'wl-b-ok'), ' ', el('span', { className: 'text-muted', text: cfg.gpg })]
            : [badge('none', 'wl-b-warn'), ' ', el('span', { className: 'wl-small text-muted', text: 'Archives are written in the clear. Set a GPG recipient in Settings if they ever leave this server.' })]));

        $('bk-dir-label').textContent = cfg.dir || '';
        $('bk-total').textContent = (j.archives || []).length + ' archive(s) · ' + bytes(j.total_bytes || 0);
        $('bk-status-updated').textContent = 'updated ' + new Date().toLocaleTimeString();
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
            el('span', { text: ' Last failure: ' + j.last_error + (j.last_error_at ? ' (' + fmtAgo(Math.floor(j.server_time - j.last_error_at)) + ' ago)' : '') }),
        ]));
        if (!(j.archives || []).length && (chk.mode)) box.appendChild(el('div', { className: 'nl-note nl-note-warn' }, [
            el('i', { className: 'bi bi-hdd-stack' }),
            el('span', { text: ' There is no backup of this tracker yet. Press "Back up now" — the first one tells you how long it takes and how big it is.' }),
        ]));
    }

    function renderProgress(st, running) {
        const box = $('bk-progress');
        box.classList.toggle('d-hidden', !running);
        if (!running) return;
        $('bk-progress-step').textContent = st.step || 'Working…';
        const bits = [];
        if (st.id) bits.push(st.id);
        if (st.started_at) bits.push('started ' + when(st.started_at));
        if (st.bytes) bits.push(bytes(st.bytes));
        $('bk-progress-meta').textContent = bits.join(' · ');
        $('bk-progress-log').textContent = st.log_tail || '';
        $('bk-progress-log').scrollTop = $('bk-progress-log').scrollHeight;
    }

    // ── sorting ──────────────────────────────────────────────────────────────
    // The list is a handful of files the helper already handed over, so this sorts in place: no
    // request, no debounce, nothing to wait for. Same header behaviour as every other table in the
    // panel (desc → asc → off, multiple keys with priority badges) via the shared sort stack.
    let bkSort = null;
    let lastList = [];
    let lastServerTime = 0;   // re-sorting repaints the rows, and the "x ago" column is server-relative
    let lastCheck = null;     // the empty-table wording depends on it, so keep it for a repaint
    const SORT_KEYS = {
        when:      a => a.ts || 0,
        profile:   a => (a.profile || (a.mode === 'builtin' ? 'database only' : '')).toLowerCase(),
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
                text: (j.check && j.check.mode) ? 'No archives yet.' : 'Backups are not available on this machine — see the status above.' })]));
            return;
        }
        list.forEach(a => {
            const acts = el('div', { className: 'wl-actions' });
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-info wl-act', type: 'button', title: 'Check the archive is intact',
                onclick: () => ask('verify', { id: a.id }) }, [el('i', { className: 'bi bi-patch-check' })]));
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-secondary wl-act', type: 'button', title: 'Download (single-use link)',
                onclick: () => ask('token', { id: a.id }) }, [el('i', { className: 'bi bi-download' })]));
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-warning wl-act', type: 'button', title: 'Restore from this archive',
                onclick: () => openRestore(a) }, [el('i', { className: 'bi bi-arrow-counterclockwise' })]));
            acts.appendChild(el('button', { className: 'btn btn-sm btn-outline-danger wl-act', type: 'button', title: 'Delete this archive',
                onclick: () => ask('delete', { id: a.id }) }, [el('i', { className: 'bi bi-trash' })]));

            const integrity = a.verified === true ? [badge('verified', 'wl-b-ok')]
                : a.verified === false ? [badge('FAILED', 'wl-b-bad')]
                : [badge('not checked', 'wl-b-muted')];
            if (a.encrypted) integrity.push(' ', el('i', { className: 'bi bi-lock-fill text-info', title: 'Encrypted with GPG' }));

            tb.appendChild(el('tr', {}, [
                el('td', {}, [el('span', { text: when(a.ts) }),
                              el('div', { className: 'wl-small text-muted', text: a.ts ? fmtAgo(Math.floor(j.server_time - a.ts)) + ' ago' : '' })]),
                el('td', {}, [el('span', { text: a.profile || (a.mode === 'builtin' ? 'database only' : '—') }),
                    // The built-in dump names every file tracker-db-*, whatever profile made it, so
                    // the filename contradicts the choice. The items are the truth and are shown here.
                    a.items ? el('div', { className: 'wl-small text-muted', title: a.items,
                                          text: includesFullDb(a) ? 'full database + files' : String(a.items).split(',').length + ' items' }) : '',
                              a.mode === 'builtin' ? el('div', { className: 'wl-small text-warning', text: 'built-in dump' }) : null]),
                el('td', {}, [
                    el('span', { text: bytes(a.size || 0) }),
                    // An archive that holds the whole database looks alarmingly small next to it, so
                    // the ratio is shown rather than left for the reader to doubt.
                    (a.size && state.status && state.status.db_bytes && includesFullDb(a))
                        ? el('div', { className: 'wl-small text-muted',
                                      title: 'The database is ' + bytes(state.status.db_bytes)
                                           + ' on disk; this archive is gzipped',
                                      text: '≈' + Math.round(state.status.db_bytes / a.size) + '× compressed' })
                        : '',
                ]),
                el('td', {}, [el('span', { className: 'wl-small text-muted', text: a.items || (a.mode === 'builtin' ? 'tracker database' : '—') })]),
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
            title: 'Make a backup now', ok: 'Back up now', cls: 'btn-outline-success',
            text: () => {
                const c = (state.status && state.status.configured) || {};
                return 'Writes into ' + (c.dir || 'the backup directory') +
                       (c.nice !== undefined ? ', niced to ' + c.nice + ' so it does not fight the tracker for disk' : '') +
                       '. It continues on the server even if you close this page. Choosing a profile here '
                       + 'affects this run only — the schedule keeps whatever Settings says.';
            },
        },
        cancel: { title: 'Cancel the running backup', ok: 'Stop it', cls: 'btn-outline-warning',
                  text: () => 'Stops the backup that is in progress. A half-written archive is removed rather than left looking valid.' },
        verify: { title: 'Check the archive', ok: 'Check it', cls: 'btn-outline-info',
                  text: () => 'Recomputes the checksum and reads the archive back. On a big archive this is a minute of disk I/O on a live box, which is why it asks first.' },
        prune:  { title: 'Apply the retention rules now', ok: 'Rotate', cls: 'btn-outline-secondary',
                  text: () => {
                      const c = (state.status && state.status.configured) || {};
                      const limits = [c.keep ? 'keep ' + c.keep : null, c.keep_days ? c.keep_days + ' days' : null,
                                      c.max_gb ? 'max ' + c.max_gb + ' GB' : null].filter(Boolean).join(', ');
                      return 'Deletes archives that are outside the limits in Settings' + (limits ? ' (' + limits + ')' : '') +
                             ', oldest first. The newest archive is never deleted.';
                  } },
        delete: { title: 'Delete this archive', ok: 'Delete', cls: 'btn-outline-danger',
                  text: () => 'Removes the archive and its metadata from the server for good. If it is the only copy you have, it is gone.' },
        token:  { title: 'Download this archive', ok: 'Get the link', cls: 'btn-outline-secondary',
                  text: () => 'The archive contains every database password on this machine. The link works once and expires in five minutes — do not paste it anywhere.' },
        restore: { title: 'Restore files from this archive', ok: 'Restore', cls: 'btn-outline-warning',
                   text: () => 'Overwrites the selected files on this server. Each one that is replaced keeps a .bak-<stamp> copy next to it, so this step is itself reversible.' },
        'restore-db': {
            title: 'Restore the DATABASE', ok: 'Overwrite the database', cls: 'btn-outline-danger', needsName: true,
            text: () => 'This overwrites the live "' + state.dbName + '" database with the copy inside the archive. ' +
                        'Everything written since that backup is lost. The server dumps the database as it is right now first, ' +
                        'next to the archives, so there is a way back.',
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
            el('span', { text: 'Archive: ' }), el('code', { text: state.pending.id })]));
        if (state.pending.items) extra.appendChild(el('div', { className: 'wl-small text-muted mb-2' }, [
            el('span', { text: 'Items: ' }), el('code', { text: state.pending.items })]));
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
                value: pr.id, text: pr.label + (pr.id === cfgNow.profile ? ' — configured' : '') })));
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working…';
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
                    showToast('Download starting — the link is now spent.', 'success');
                    window.location.href = r.url;
                } else if (p.op === 'verify') {
                    // a verification result is the answer, not a side effect — say which way it went
                    showToast(r.message || 'Checked', 'success');
                } else {
                    showToast(r.message || 'Done', 'success');
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
                alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: r.error || 'Failed' }));
                if (r.output) alert.appendChild(el('pre', { className: 'bk-log', text: r.output }));
            }
            if (p.op === 'restore' && !r.success) {
                const outEl = $('bk-restore-output');
                outEl.textContent = (r.error || '') + (r.output ? NL2 + r.output : '');
                outEl.classList.remove('d-hidden');
            }
        } catch {
            alert.appendChild(el('div', { className: 'alert alert-danger py-2 wl-small', text: 'Network error' }));
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
                a.mode === 'builtin' ? 'This archive is a plain database dump — there are no files in it to restore.'
                                     : 'This archive contains no file items.' }));
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
        if (!items) { alert.appendChild(el('div', { className: 'alert alert-warning py-2 wl-small', text: 'Tick at least one item first.' })); return; }
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
            onChange: () => renderRows({ archives: lastList, server_time: lastServerTime, check: lastCheck }),
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
            if (!items) { alert.appendChild(el('div', { className: 'alert alert-warning py-2 wl-small', text: 'Tick at least one item first.' })); return; }
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
