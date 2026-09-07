/**
 * "Kernel network buffers" card on the admin Traffic page — includes/sysctl.php on the server.
 *
 * ── the two things this file exists to prevent ──────────────────────────────
 *
 * 1. A wrong unit. The kernel counts three different things here: bytes for the four socket buffers,
 *    PACKETS for the per-CPU queue, and PAGES for the machine-wide UDP pool. A number that is
 *    sensible in one is ruinous in another — a "3145728" copied from a tuning guide is 3 MB if you
 *    think it is bytes and 12 GB if the kernel reads it as pages, and it will be read as pages. So
 *    every field here is a number plus a unit the reader chose, the byte and page forms are both
 *    shown at all times, and pages are never typed.
 *
 * 2. A number with nothing behind it. Each row states what its key actually does and, where the
 *    machine has a counter that speaks to it, what that counter currently says. A suggestion is
 *    offered only where a counter supports it; the keys with no local evidence say so in as many
 *    words rather than being quietly filled in.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    const card = document.getElementById('sysctl-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction, promptPassword, copyToClipboard } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const POLL_MS = 15000;
    const MIN_GAP_MS = 2500;

    const state = {
        status: null, keys: null, suggest: null, armed: null,
        wanted: {},          // key -> string in the kernel's own unit
        unit: {},            // key -> 'B' | 'KiB' | 'MiB'  (byte keys) / 'MiB' | 'pages' (udp_mem)
        pending: null,
    };

    const KIB = 1024, MIB = 1024 * 1024;

    function humanBytes(b) {
        b = Number(b) || 0;
        if (b <= 0) return '0 B';
        const u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let i = 0, v = b;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        if (v >= 100 || i === 0) return Math.round(v).toLocaleString() + ' ' + u[i];
        // "8.00 MiB" and "8 MiB" are the same number and look like different ones. The operator is
        // checking this against what they meant to type, so the zeros go.
        return String(parseFloat(v.toFixed(2))) + ' ' + u[i];
    }
    const num = (v) => (v === null || v === undefined || isNaN(v)) ? '—' : Math.round(v).toLocaleString();

    function pageSize() { return Math.max(1, Number((state.status || {}).page_size) || 4096); }
    function memTotalBytes() { return (Number((state.status || {}).mem_total_kb) || 0) * 1024; }

    /* ── polling ─────────────────────────────────────────────────────────── */
    let seq = 0, painted = 0, busy = 0, busyAt = 0, lastLoadAt = 0, tick = null;

    async function load(force) {
        if (busy && (Date.now() - busyAt) < 30000) return;
        if (!force && document.hidden) return;
        if (painted && (Date.now() - lastLoadAt) < MIN_GAP_MS) return;
        lastLoadAt = Date.now();
        const my = ++seq;
        busy = my; busyAt = Date.now();
        let j;
        try {
            j = await apiCall('admin/sysctl_status');
        } catch (e) {
            if (my > painted) { painted = my; fatal((e && e.message) || t('js.sysctl.network_error')); }
            return;
        } finally {
            if (busy === my) busy = 0;
        }
        if (my <= painted) return;
        painted = my;
        if (!j || j.enabled === false) { fatal(t('js.sysctl.helper_off')); return; }
        if (!j.ok) { fatal(j.error || t('js.sysctl.helper_no_answer')); return; }
        state.status = j.status;
        state.keys = j.keys;
        state.suggest = j.suggest || {};
        state.armed = j.armed || null;
        state.verdict = j.verdict || {};
        state.advice = j.advice || [];
        state.confirmSeconds = j.confirm_seconds || 120;
        state.request = j.request || null;
        state.lastError = j.last_error || null;
        state.lastRevert = j.last_revert_at ? { at: j.last_revert_at, why: j.last_revert_reason } : null;
        seedWanted();
        // DO NOT REPAINT OVER SOMEBODY'S HANDS.
        //
        // render() rebuilds every row from scratch, so a 15-second poll landing mid-edit replaced
        // the input being typed into and moved the caret with it. The new numbers are already in
        // `state`; they will be drawn the moment the form is clean again (Reset, or a successful
        // apply, both call render()). Stale-by-one-tick beats un-editable.
        if (formDirty() || gridHasFocus()) return;
        render();
    }

    /** Is the operator inside one of the grid's controls right now? */
    function gridHasFocus() {
        const g = $('sy-grid');
        return !!(g && document.activeElement && g.contains(document.activeElement));
    }

    function fatal(msg) {
        const g = $('sy-grid');
        g.textContent = '';
        g.appendChild(el('div', { className: 'nl-note nl-note-bad', text: msg || t('js.sysctl.unavailable') }));
        g.appendChild(el('div', {}, [el('button', {
            className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
            onclick: () => { painted = 0; busy = 0; load(true); },
        }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' ' + t('js.sysctl.try_again')])]));
        $('sy-notes').textContent = '';
    }

    /**
     * The wanted map starts as what is actually in force, so a row the admin never touches is
     * submitted unchanged rather than becoming a change they did not intend to make.
     */
    function seedWanted() {
        const vals = (state.status && state.status.values) || {};
        Object.keys(state.keys || {}).forEach(k => {
            if (state.wanted[k] === undefined) state.wanted[k] = String(vals[k] === undefined ? '' : vals[k]);
            if (state.unit[k] === undefined) {
                const meta = state.keys[k];
                state.unit[k] = meta.unit === 'pages3' ? 'MiB' : (meta.unit === 'bytes' ? 'KiB' : 'packets');
            }
        });
    }

    /* ── one row per key ─────────────────────────────────────────────────── */

    function bytesRow(k, meta) {
        const vals = (state.status && state.status.values) || {};
        const base = ((state.status && state.status.baseline) || {}).values || {};
        const cur = Number(vals[k] || 0);
        const row = el('div', { className: 'sy-row' });

        row.appendChild(el('div', { className: 'sy-key' }, [
            el('div', { className: 'sy-key-label', text: meta.label }),
            el('code', { className: 'sy-key-name', text: meta.sysctl }),
            el('div', { className: 'sy-key-what', text: meta.what }),
        ]));

        const now = el('div', { className: 'sy-now' });
        now.appendChild(el('div', { className: 'sy-now-v', text: humanBytes(cur) }));
        now.appendChild(el('div', { className: 'sy-now-raw', text: t('js.sysctl.n_bytes', {n: num(cur)}) }));
        if (base[k] !== undefined && String(base[k]) !== String(cur)) {
            now.appendChild(el('div', { className: 'sy-now-base', text: t('js.sysctl.was_before', {v: humanBytes(base[k])}) }));
        }
        row.appendChild(now);

        const ctl = el('div', { className: 'sy-ctl' });
        const unit = state.unit[k];
        const div = unit === 'MiB' ? MIB : (unit === 'KiB' ? KIB : 1);
        const inp = el('input', {
            type: 'number', className: 'form-control form-control-sm bg-dark text-light border-secondary sy-input',
            min: '0', step: 'any',
        });
        inp.value = String(Math.round((Number(state.wanted[k] || 0) / div) * 1000) / 1000);
        const sel = el('select', { className: 'form-select form-select-sm bg-dark text-light border-secondary sy-unit' });
        ['B', 'KiB', 'MiB'].forEach(u => {
            const o = el('option', { text: u });
            o.value = u;
            if (u === unit) o.selected = true;
            sel.appendChild(o);
        });
        const echo = el('div', { className: 'sy-echo' });
        const recompute = () => {
            const mul = sel.value === 'MiB' ? MIB : (sel.value === 'KiB' ? KIB : 1);
            const bytes = Math.round((parseFloat(inp.value) || 0) * mul);
            state.wanted[k] = String(bytes);
            state.unit[k] = sel.value;
            const parts = [t('js.sysctl.bytes_echo', {h: humanBytes(bytes), n: num(bytes)})];
            const mt = memTotalBytes();
            if (mt > 0) parts.push(t('js.sysctl.pct_of_memory', {p: ((bytes / mt) * 100).toFixed(2)}));
            if (bytes !== cur && cur > 0) parts.push((bytes > cur ? t('js.sysctl.times_in_force', {x: (bytes / cur).toFixed(1)}) : t('js.sysctl.lower_than_force')));
            echo.textContent = parts.join(' · ');
            echo.classList.toggle('sy-echo-warn', mt > 0 && bytes > mt / 8);
        };
        inp.addEventListener('input', recompute);
        sel.addEventListener('change', () => {
            // Changing the unit keeps the VALUE, not the number: switching KiB to MiB on "8" must not
            // silently mean a thousandfold change.
            const mul = sel.value === 'MiB' ? MIB : (sel.value === 'KiB' ? KIB : 1);
            inp.value = String(Math.round((Number(state.wanted[k] || 0) / mul) * 1000) / 1000);
            recompute();
        });
        const wrap = el('div', { className: 'sy-input-wrap' });
        wrap.appendChild(inp); wrap.appendChild(sel);
        ctl.appendChild(wrap);
        ctl.appendChild(echo);
        if (state.suggest && state.suggest[k]) {
            const s = state.suggest[k];
            ctl.appendChild(el('div', { className: 'sy-sugg', text: t('js.sysctl.suggested', {v: humanBytes(s.value), why: s.why}) }));
        }
        row.appendChild(ctl);
        recompute();
        return row;
    }

    function packetsRow(k, meta) {
        const vals = (state.status && state.status.values) || {};
        const cur = Number(vals[k] || 0);
        const cpus = Math.max(1, Number((state.status || {}).cpus) || 1);
        const row = el('div', { className: 'sy-row' });
        row.appendChild(el('div', { className: 'sy-key' }, [
            el('div', { className: 'sy-key-label', text: meta.label }),
            el('code', { className: 'sy-key-name', text: meta.sysctl }),
            el('div', { className: 'sy-key-what', text: meta.what }),
        ]));
        const now = el('div', { className: 'sy-now' });
        now.appendChild(el('div', { className: 'sy-now-v', text: t('js.sysctl.n_packets', {n: num(cur)}) }));
        now.appendChild(el('div', { className: 'sy-now-raw', text: t('js.sysctl.per_cpu_across', {n: num(cur * cpus), c: cpus}) }));
        row.appendChild(now);

        const ctl = el('div', { className: 'sy-ctl' });
        const inp = el('input', {
            type: 'number', className: 'form-control form-control-sm bg-dark text-light border-secondary sy-input',
            min: String(meta.min), max: String(meta.max), step: '100',
        });
        inp.value = String(state.wanted[k] || cur);
        const echo = el('div', { className: 'sy-echo' });
        const recompute = () => {
            const v = Math.max(0, parseInt(inp.value, 10) || 0);
            state.wanted[k] = String(v);
            echo.textContent = t('js.sysctl.packets_echo', {v: num(v), n: num(v * cpus), c: cpus});
            echo.classList.toggle('sy-echo-warn', v > cur * 4 && cur > 0);
        };
        inp.addEventListener('input', recompute);
        const wrap = el('div', { className: 'sy-input-wrap' });
        wrap.appendChild(inp);
        wrap.appendChild(el('span', { className: 'sy-unit-static', text: t('js.sysctl.packets_per_cpu') }));
        ctl.appendChild(wrap);
        ctl.appendChild(echo);
        row.appendChild(ctl);
        recompute();
        return row;
    }

    /**
     * udp_mem is the one that catches people. Three numbers, in pages, and the pages are of whatever
     * size this kernel uses. Typed in MiB, displayed in both, and every figure is also shown as a
     * share of RAM — because a page count large enough to exceed the machine's memory looks exactly
     * like a page count that does not.
     */
    function pagesRow(k, meta) {
        const vals = (state.status && state.status.values) || {};
        const ps = pageSize();
        const curParts = String(vals[k] || '').trim().split(/\s+/).map(x => parseInt(x, 10) || 0);
        const row = el('div', { className: 'sy-row sy-row-wide' });
        row.appendChild(el('div', { className: 'sy-key' }, [
            el('div', { className: 'sy-key-label', text: meta.label }),
            el('code', { className: 'sy-key-name', text: meta.sysctl }),
            el('div', { className: 'sy-key-what', text: meta.what }),
        ]));
        const now = el('div', { className: 'sy-now' });
        if (curParts.length === 3) {
            now.appendChild(el('div', { className: 'sy-now-v', text: curParts.map(p => humanBytes(p * ps)).join(' / ') }));
            now.appendChild(el('div', { className: 'sy-now-raw', text: t('js.sysctl.pages_of', {n: curParts.map(num).join(' / '), ps: num(ps)}) }));
        }
        const used = Number((state.status || {}).udp_pages_used || 0);
        now.appendChild(el('div', { className: 'sy-now-base', text: t('js.sysctl.udp_using', {n: num(used), h: humanBytes(used * ps)}) }));
        row.appendChild(now);

        const ctl = el('div', { className: 'sy-ctl' });
        const wantParts = String(state.wanted[k] || vals[k] || '').trim().split(/\s+/).map(x => parseInt(x, 10) || 0);
        const inputs = [];
        const labels = ['min', 'pressure', 'max'];
        const grid = el('div', { className: 'sy-three' });
        labels.forEach((lab, idx) => {
            const cell = el('div', { className: 'sy-three-cell' });
            cell.appendChild(el('label', { className: 'sy-three-label', text: lab }));
            const inp = el('input', {
                type: 'number', className: 'form-control form-control-sm bg-dark text-light border-secondary',
                min: '0', step: 'any',
            });
            inp.value = String(Math.round(((wantParts[idx] || 0) * ps / MIB) * 100) / 100);
            inputs.push(inp);
            cell.appendChild(inp);
            grid.appendChild(cell);
        });
        const unitLabel = el('span', { className: 'sy-unit-static', text: t('js.sysctl.mib_each') });
        const echo = el('div', { className: 'sy-echo' });
        const recompute = () => {
            const pages = inputs.map(i => Math.round(((parseFloat(i.value) || 0) * MIB) / ps));
            state.wanted[k] = pages.join(' ');
            const mt = memTotalBytes();
            const share = (p) => mt > 0 ? ((p * ps / mt) * 100).toFixed(1) + '%' : '?';
            echo.textContent = t('js.sysctl.pages_echo', {
                p: pages.map(num).join(' / '),
                h: pages.map(p => humanBytes(p * ps)).join(' / '),
                s: pages.map(share).join(' / ') });
            const bad = !(pages[0] < pages[1] && pages[1] < pages[2])
                || (mt > 0 && pages[2] * ps > mt / 4)
                || (mt > 0 && pages[0] * ps > mt / 100);
            echo.classList.toggle('sy-echo-warn', bad);
            if (!(pages[0] < pages[1] && pages[1] < pages[2])) {
                echo.textContent += t('js.sysctl.must_increase');
            } else if (mt > 0 && pages[0] * ps > mt / 100) {
                echo.textContent += t('js.sysctl.min_too_high');
            }
        };
        inputs.forEach(i => i.addEventListener('input', recompute));
        ctl.appendChild(grid);
        ctl.appendChild(unitLabel);
        ctl.appendChild(echo);
        row.appendChild(ctl);
        recompute();
        return row;
    }

    function render() {
        const g = $('sy-grid');
        g.textContent = '';
        const st = state.status || {};
        $('sy-updated').textContent = st.mem_total_kb
            ? t('js.sysctl.machine_line', {ram: humanBytes(st.mem_total_kb * 1024), cpus: st.cpus, page: num(st.page_size)})
            : '';

        // The verdict first: it decides which of the rows below is even worth reading.
        if (state.verdict && state.verdict.known) {
            const note = el('div', {
                className: 'nl-note ' + (state.verdict.stale ? 'nl-note-bad'
                                       : (state.verdict.asks ? 'nl-note-info' : 'nl-note-warn')),
            }, el('span', { text: state.verdict.text }));
            // The verdict for a stale socket ends with "RESTART THE TRACKER", and until now there was
            // no way to do that from the sentence saying to. Advice with the action attached is the
            // difference between a page that explains and a page that works.
            if (state.verdict.stale) {
                const act = el('div', { className: 'sy-verdict-acts' });
                const btn = el('button', { type: 'button', className: 'btn btn-sm btn-outline-warning',
                    title: t('js.sysctl.restart_title') },
                    [el('i', { className: 'bi bi-arrow-clockwise' }), ' ' + t('js.sysctl.restart_now')]);
                btn.addEventListener('click', restartForBuffer);
                act.appendChild(btn);
                note.appendChild(act);
            }
            g.appendChild(note);
        }

        const order = ['rmem_max', 'rmem_default', 'udp_rmem_min', 'wmem_max', 'wmem_default', 'udp_wmem_min', 'netdev_max_backlog', 'udp_mem'];
        const box = el('div', { className: 'sy-rows' });
        order.forEach(k => {
            const meta = (state.keys || {})[k];
            if (!meta) return;
            if (meta.unit === 'bytes') box.appendChild(bytesRow(k, meta));
            else if (meta.unit === 'packets') box.appendChild(packetsRow(k, meta));
            else box.appendChild(pagesRow(k, meta));
        });
        g.appendChild(box);

        const restore = $('btn-sy-restore');
        if (restore) {
            const canMachine = restorable();
            const dirty = formDirty();
            restore.disabled = !canMachine && !dirty;
            // Say which of the two it would do, so the button is never a surprise.
            restore.title = canMachine
                ? t('js.sysctl.restore_title_machine')
                : (dirty
                    ? t('js.sysctl.restore_title_dirty')
                    : t('js.sysctl.restore_title_none'));
            const label = restore.querySelector('.sy-restore-label');
            if (label) label.textContent = ' ' + ((!canMachine && dirty) ? t('js.sysctl.discard_edits') : t('js.sysctl.restore_defaults'));
        }

        renderArmed();

        const notes = $('sy-notes');
        notes.textContent = '';
        if (state.lastError) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.sysctl.last_helper_error', {e: state.lastError}) }));
        }
        if (state.lastRevert) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-warn',
                text: t('js.sysctl.auto_reverted', {why: state.lastRevert.why || t('js.sysctl.not_confirmed')}) }));
        }
        (state.advice || []).forEach(a => {
            const box2 = el('div', {
                className: 'nl-note ' + (a.level === 'bad' ? 'nl-note-bad' : a.level === 'warn' ? 'nl-note-warn' : 'nl-note-info'),
            }, el('span', { text: a.text }));
            // Some advice ends in a command the panel deliberately does not run — rps_cpus is
            // system-wide, like the sysctls beside it. A copy button is the honest middle: the panel
            // does not touch the machine, and the operator does not retype a hex mask by hand.
            if (a.command) {
                const row = el('div', { className: 'sy-verdict-acts' });
                row.appendChild(el('code', { className: 'sy-cmd', text: a.command }));
                const cp = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary' },
                    [el('i', { className: 'bi bi-clipboard' }), ' ' + t('js.sysctl.copy')]);
                cp.addEventListener('click', () => {
                    copyToClipboard(a.command);
                    showToast(t('js.sysctl.command_copied'), 'success');
                });
                row.appendChild(cp);
                box2.appendChild(row);
            }
            notes.appendChild(box2);
        });
    }

    /**
     * Restart the tracker so its socket is recreated with the buffer size now in force.
     *
     * The same endpoint and the same password the Restart button on the performance card uses — this
     * one is simply next to the sentence that asks for it. A restart drops the socket for a moment;
     * peers retry on their own, which is why this is a warning and not a refusal.
     */
    async function restartForBuffer() {
        if (!await confirmAction(t('js.sysctl.restart_confirm_title'),
            t('js.sysctl.restart_confirm_body'),
            { after: t('js.sysctl.restart_confirm_after'),
              okLabel: t('js.sysctl.restart_ok'), danger: true })) return;
        const pw = await promptPassword(t('js.sysctl.restart_confirm_title'), t('js.sysctl.restart_pw_text'));
        if (!pw) return;
        const r = await apiCall('admin/restart_tracker', 'POST', { password: pw });
        showToast((r && (r.message || r.error)) || t('js.sysctl.failed'), r && r.success ? 'success' : 'error');
        setTimeout(load, 3000);
    }

    function renderArmed() {
        const wrap = $('sy-armed');
        const req = state.request;
        if (req && !state.armed) {
            wrap.classList.remove('d-hidden');
            $('sy-armed-text').textContent = t('js.sysctl.queued_op', {op: req.op});
            $('sy-countdown').textContent = '';
            $('sy-armed-keys').textContent = '';
            $('btn-sy-confirm').disabled = true;
            $('btn-sy-revert').disabled = false;
            return;
        }
        if (!state.armed) { wrap.classList.add('d-hidden'); return; }
        wrap.classList.remove('d-hidden');
        $('btn-sy-confirm').disabled = !state.armed.all_landed;
        $('btn-sy-revert').disabled = false;
        $('sy-armed-text').textContent = state.armed.all_landed
            ? t('js.sysctl.armed_landed')
            : t('js.sysctl.armed_not_landed');
        const keys = $('sy-armed-keys');
        keys.textContent = '';
        Object.keys(state.armed.keys || {}).forEach(k => {
            const r = state.armed.keys[k];
            keys.appendChild(el('div', {
                className: 'sy-armed-key ' + (r.landed ? '' : 'sy-armed-key-bad'),
                text: (r.landed ? '✓ ' : '✗ ') + k + ': ' + r.got + (r.landed ? '' : ' ' + t('js.sysctl.asked_for', {v: r.wanted})),
            }));
        });
        paintCountdown();
    }

    function paintCountdown() {
        const c = $('sy-countdown');
        if (!c || !state.armed) return;
        const left = Math.max(0, (state.armed.deadline || 0) - Math.floor(Date.now() / 1000));
        const worst = left + ((state.armed.watchdog === 'systemd') ? 5 : 60);
        // The worst case, not the nominal window. The difference between "it will come back" and
        // "I should power-cycle now" lives in that gap, and the optimistic number is the one that
        // gets somebody to wait too long.
        c.textContent = left > 0
            ? t('js.sysctl.undo_in', { m: Math.floor(left / 60), s: (left % 60), w: Math.ceil(worst / 60),
               who: (state.armed.watchdog === 'systemd' ? t('js.sysctl.watchdog_systemd') : t('js.sysctl.watchdog_janitor')) })
            : t('js.sysctl.window_passed');
    }

    /* ── operations ──────────────────────────────────────────────────────── */

    function changedPairs() {
        const vals = (state.status && state.status.values) || {};
        const out = {};
        Object.keys(state.wanted).forEach(k => {
            const w = String(state.wanted[k] || '').trim();
            if (w === '') return;
            const cur = String(vals[k] === undefined ? '' : vals[k]).trim().replace(/\s+/g, ' ');
            if (w.replace(/\s+/g, ' ') === cur) return;
            out[k] = w;
        });
        return out;
    }

    function ask(op) {
        state.pending = op;
        const acks = $('sy-modal-acks');
        acks.textContent = '';
        $('sy-modal-warnings').textContent = '';
        $('sy-confirm-alert').textContent = '';
        $('sy-confirm-password').value = '';

        if (op === 'arm') {
            const pairs = changedPairs();
            const names = Object.keys(pairs);
            if (!names.length) { showToast(t('js.sysctl.nothing_different'), 'info'); return; }
            $('sy-modal-title').textContent = t('js.sysctl.apply_for_minutes', {n: Math.round((state.confirmSeconds || 120) / 60)});
            $('sy-modal-text').textContent = t('js.sysctl.arm_text', {n: names.length});
            const undo = $('sy-modal-undo');
            undo.textContent = '';
            names.forEach(k => {
                const meta = state.keys[k];
                const cur = ((state.status || {}).values || {})[k];
                undo.appendChild(el('div', { className: 'wl-small',
                    text: meta.sysctl + ': ' + cur + '  →  ' + pairs[k] }));
                if (meta.ack) {
                    const id = 'sy-ack-' + k;
                    const line = el('div', { className: 'form-check' });
                    const cb = el('input', { type: 'checkbox', className: 'form-check-input', id: id });
                    cb.dataset.ackKey = k;
                    const lb = el('label', { className: 'form-check-label wl-small', htmlFor: id,
                        text: t('js.sysctl.ack_text', {key: meta.sysctl}) });
                    line.appendChild(cb); line.appendChild(lb);
                    acks.appendChild(line);
                }
            });
        } else if (op === 'confirm') {
            $('sy-modal-title').textContent = t('js.sysctl.keep_title');
            $('sy-modal-text').textContent = t('js.sysctl.keep_text');
            $('sy-modal-undo').textContent = '';
            $('sy-modal-undo').appendChild(el('div', { className: 'wl-small text-muted',
                text: t('js.sysctl.keep_undo') }));
        }
        const ok = $('sy-confirm-ok');
        ok.textContent = '';
        ok.appendChild(el('i', { className: 'bi bi-check-lg' }));
        ok.appendChild(document.createTextNode(' ' + (op === 'arm' ? t('js.sysctl.apply_now') : t('js.sysctl.keep_it'))));
        bootstrap.Modal.getOrCreateInstance($('syConfirmModal')).show();
        setTimeout(() => $('sy-confirm-password').focus(), 300);
    }

    async function run(e) {
        e.preventDefault();
        const op = state.pending;
        if (!op) return;
        const alert = $('sy-confirm-alert');
        const btn = $('sy-confirm-ok');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.sysctl.working');
        alert.textContent = '';
        const body = { op: op, password: $('sy-confirm-password').value };
        if (op === 'arm') {
            body.values = changedPairs();
            body.ack = Array.from(document.querySelectorAll('#sy-modal-acks input:checked')).map(c => c.dataset.ackKey);
            if (state.status && !state.status.systemd_run) body.ack_no_watchdog = true;
        }
        try {
            const r = await apiCall('admin/sysctl_apply', 'POST', body);
            if (r.success) {
                bootstrap.Modal.getInstance($('syConfirmModal')).hide();
                showToast(r.message || t('js.sysctl.queued'), 'success');
                painted = 0;
                load(true);
            } else {
                alert.textContent = '';
                alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: r.error || t('js.sysctl.failed') }));
            }
        } catch (err) {
            alert.textContent = '';
            alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.sysctl.network_error') }));
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    /**
     * "Restore defaults" means the values THIS MACHINE had before the panel first touched them, and
     * not the distribution's defaults. Those are different things: the operator may have tuned this
     * box years ago, and handing them a vendor default while calling it an undo would be a change
     * dressed as a restoration. When the panel has never altered anything there is nothing captured,
     * so the button says so rather than doing nothing quietly.
     */
    function restorable() {
        const st = state.status || {};
        return !!(st.baseline && st.baseline.values && Object.keys(st.baseline.values).length) || !!st.file_present;
    }

    /** Has the operator typed something into this form that is not what the kernel is running? */
    function formDirty() {
        return Object.keys(changedPairs()).length > 0;
    }

    /**
     * Put the form back to what is actually in force.
     *
     * Nothing is sent anywhere: this undoes typing, not a change to the machine. It matters because
     * the two are easy to confuse — somebody who has filled three fields in and thought better of it
     * is looking for exactly this button, and telling them "nothing to undo" because the panel has
     * never written a sysctl would be answering a question they did not ask.
     */
    function resetForm() {
        const vals = (state.status && state.status.values) || {};
        Object.keys(state.wanted).forEach(k => { state.wanted[k] = String(vals[k] === undefined ? '' : vals[k]); });
        render();
        showToast(t('js.sysctl.reset_done'), 'success');
    }

    async function revert() {
        // Two different undos behind one button, because from the reader's side they are one idea:
        // "put it back". Unsaved edits are discarded locally; a change the panel actually applied is
        // restored on the machine. When both exist the machine takes priority and the form follows.
        if (!restorable()) {
            if (formDirty()) { resetForm(); return; }
            showToast(t('js.sysctl.nothing_to_revert'), 'info');
            return;
        }
        if (!await confirmAction(t('js.sysctl.revert_title'),
            t('js.sysctl.revert_body'),
            { okLabel: t('js.sysctl.revert_ok'), danger: true })) return;
        try {
            const r = await apiCall('admin/sysctl_apply', 'POST', { op: 'revert' });
            showToast(r.success ? (r.message || t('js.sysctl.queued')) : (r.error || t('js.sysctl.failed')), r.success ? 'success' : 'error');
            painted = 0;
            load(true);
        } catch { showToast(t('js.sysctl.network_error'), 'error'); }
    }

    async function preview() {
        const pairs = changedPairs();
        if (!Object.keys(pairs).length) { showToast(t('js.sysctl.nothing_different'), 'info'); return; }
        try {
            const r = await apiCall('admin/sysctl_apply', 'POST', { op: 'preview', values: pairs });
            $('sy-preview-title').textContent = r.file || t('js.sysctl.file_preview');
            $('sy-preview-body').textContent = r.content || r.error || t('js.sysctl.nothing_paren');
            bootstrap.Modal.getOrCreateInstance($('syPreviewModal')).show();
        } catch { showToast(t('js.sysctl.network_error'), 'error'); }
    }

    function useSuggested() {
        const s = state.suggest || {};
        const names = Object.keys(s);
        if (!names.length) {
            showToast(t('js.sysctl.no_suggestions'), 'info');
            return;
        }
        names.forEach(k => { state.wanted[k] = String(s[k].value); });
        render();
        showToast(t('js.sysctl.filled_in', {n: names.length}), 'success');
    }

    function init() {
        $('btn-sy-arm').addEventListener('click', () => ask('arm'));
        $('btn-sy-confirm').addEventListener('click', () => ask('confirm'));
        $('btn-sy-revert').addEventListener('click', revert);
        $('btn-sy-preview').addEventListener('click', preview);
        $('btn-sy-restore').addEventListener('click', revert);
        $('btn-sy-suggest').addEventListener('click', useSuggested);
        $('sy-confirm-form').addEventListener('submit', run);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });
        load(true);
        setTimeout(() => load(true), 4000);
        setInterval(load, POLL_MS);
        tick = setInterval(paintCountdown, 1000);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
