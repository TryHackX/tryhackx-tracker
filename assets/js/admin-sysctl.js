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
    const { apiCall, el, showToast } = window.AdminCommon;

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
            if (my > painted) { painted = my; fatal((e && e.message) || 'network error'); }
            return;
        } finally {
            if (busy === my) busy = 0;
        }
        if (my <= painted) return;
        painted = my;
        if (!j || j.enabled === false) { fatal('The helper is not configured or the feature is off.'); return; }
        if (!j.ok) { fatal(j.error || 'The helper did not answer.'); return; }
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
        render();
    }

    function fatal(msg) {
        const g = $('sy-grid');
        g.textContent = '';
        g.appendChild(el('div', { className: 'nl-note nl-note-bad', text: msg || 'unavailable' }));
        g.appendChild(el('div', {}, [el('button', {
            className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
            onclick: () => { painted = 0; busy = 0; load(true); },
        }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' Try again'])]));
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
        now.appendChild(el('div', { className: 'sy-now-raw', text: num(cur) + ' bytes' }));
        if (base[k] !== undefined && String(base[k]) !== String(cur)) {
            now.appendChild(el('div', { className: 'sy-now-base', text: 'was ' + humanBytes(base[k]) + ' before the panel' }));
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
            const parts = [humanBytes(bytes) + ' — ' + num(bytes) + ' bytes'];
            const mt = memTotalBytes();
            if (mt > 0) parts.push(((bytes / mt) * 100).toFixed(2) + '% of this machine’s memory');
            if (bytes !== cur && cur > 0) parts.push((bytes > cur ? '×' + (bytes / cur).toFixed(1) + ' of what is in force' : 'lower than what is in force'));
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
            ctl.appendChild(el('div', { className: 'sy-sugg', text: 'suggested ' + humanBytes(s.value) + ' — ' + s.why }));
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
        now.appendChild(el('div', { className: 'sy-now-v', text: num(cur) + ' packets' }));
        now.appendChild(el('div', { className: 'sy-now-raw', text: 'per CPU — ' + num(cur * cpus) + ' across ' + cpus + ' cores' }));
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
            echo.textContent = num(v) + ' per CPU · ' + num(v * cpus) + ' packets queued across ' + cpus + ' cores';
            echo.classList.toggle('sy-echo-warn', v > cur * 4 && cur > 0);
        };
        inp.addEventListener('input', recompute);
        const wrap = el('div', { className: 'sy-input-wrap' });
        wrap.appendChild(inp);
        wrap.appendChild(el('span', { className: 'sy-unit-static', text: 'packets / CPU' }));
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
            now.appendChild(el('div', { className: 'sy-now-raw', text: curParts.map(num).join(' / ') + ' pages of ' + num(ps) + ' B' }));
        }
        const used = Number((state.status || {}).udp_pages_used || 0);
        now.appendChild(el('div', { className: 'sy-now-base', text: 'all UDP sockets are using ' + num(used) + ' pages right now (' + humanBytes(used * ps) + ')' }));
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
        const unitLabel = el('span', { className: 'sy-unit-static', text: 'MiB each' });
        const echo = el('div', { className: 'sy-echo' });
        const recompute = () => {
            const pages = inputs.map(i => Math.round(((parseFloat(i.value) || 0) * MIB) / ps));
            state.wanted[k] = pages.join(' ');
            const mt = memTotalBytes();
            const share = (p) => mt > 0 ? ((p * ps / mt) * 100).toFixed(1) + '%' : '?';
            echo.textContent = pages.map(num).join(' / ') + ' pages · '
                + pages.map(p => humanBytes(p * ps)).join(' / ') + ' · '
                + pages.map(share).join(' / ') + ' of memory';
            const bad = !(pages[0] < pages[1] && pages[1] < pages[2])
                || (mt > 0 && pages[2] * ps > mt / 4)
                || (mt > 0 && pages[0] * ps > mt / 100);
            echo.classList.toggle('sy-echo-warn', bad);
            if (!(pages[0] < pages[1] && pages[1] < pages[2])) {
                echo.textContent += ' — must increase: min < pressure < max';
            } else if (mt > 0 && pages[0] * ps > mt / 100) {
                echo.textContent += ' — min above 1% of memory is memory promised away, not a limit';
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
            ? (humanBytes(st.mem_total_kb * 1024) + ' RAM · ' + st.cpus + ' cores · page ' + num(st.page_size) + ' B')
            : '';

        // The verdict first: it decides which of the rows below is even worth reading.
        if (state.verdict && state.verdict.known) {
            g.appendChild(el('div', {
                className: 'nl-note ' + (state.verdict.asks ? 'nl-note-info' : 'nl-note-warn'),
                text: state.verdict.text,
            }));
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

        renderArmed();

        const notes = $('sy-notes');
        notes.textContent = '';
        if (state.lastError) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-bad', text: 'Last helper error: ' + state.lastError }));
        }
        if (state.lastRevert) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-warn',
                text: 'A change was put back automatically (' + (state.lastRevert.why || 'not confirmed') + ').' }));
        }
        (state.advice || []).forEach(a => {
            notes.appendChild(el('div', {
                className: 'nl-note ' + (a.level === 'bad' ? 'nl-note-bad' : a.level === 'warn' ? 'nl-note-warn' : 'nl-note-info'),
                text: a.text,
            }));
        });
    }

    function renderArmed() {
        const wrap = $('sy-armed');
        const req = state.request;
        if (req && !state.armed) {
            wrap.classList.remove('d-hidden');
            $('sy-armed-text').textContent = 'Queued (' + req.op + '). The janitor performs it within a minute — nothing has changed yet.';
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
            ? 'This change is in force and will undo itself unless you keep it.'
            : 'This change did NOT fully take effect. Keeping it is refused — put it back and look at the per-key result.';
        const keys = $('sy-armed-keys');
        keys.textContent = '';
        Object.keys(state.armed.keys || {}).forEach(k => {
            const r = state.armed.keys[k];
            keys.appendChild(el('div', {
                className: 'sy-armed-key ' + (r.landed ? '' : 'sy-armed-key-bad'),
                text: (r.landed ? '✓ ' : '✗ ') + k + ': ' + r.got + (r.landed ? '' : ' (asked for ' + r.wanted + ')'),
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
            ? ('undoes itself in ' + Math.floor(left / 60) + 'm ' + (left % 60) + 's — worst case '
               + Math.ceil(worst / 60) + ' min, since '
               + (state.armed.watchdog === 'systemd' ? 'systemd holds the timer' : 'the only watchdog left is the janitor, which runs once a minute'))
            : 'the window has passed — the undo is running';
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
            if (!names.length) { showToast('Nothing is different from what is already in force.', 'info'); return; }
            $('sy-modal-title').textContent = 'Apply for ' + Math.round((state.confirmSeconds || 120) / 60) + ' minute(s)';
            $('sy-modal-text').textContent =
                'These ' + names.length + ' setting(s) take effect within a minute and then put themselves back '
                + 'automatically unless you press Keep. Nothing is written to /etc until you do, so a reboot '
                + 'undoes this too. If the machine becomes unreachable, it repairs itself without you.';
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
                        text: 'I mean ' + meta.sysctl + ' — this changes the buffer given to EVERY socket created afterwards, not just the tracker’s.' });
                    line.appendChild(cb); line.appendChild(lb);
                    acks.appendChild(line);
                }
            });
        } else if (op === 'confirm') {
            $('sy-modal-title').textContent = 'Keep these settings permanently';
            $('sy-modal-text').textContent =
                'This writes /etc/sysctl.d/99-tracker-panel.conf, cancels the scheduled undo, and makes the '
                + 'change survive a reboot — which is the escape hatch everything else here depends on. '
                + 'That is why it asks for the password and Put-it-back does not.';
            $('sy-modal-undo').textContent = '';
            $('sy-modal-undo').appendChild(el('div', { className: 'wl-small text-muted',
                text: 'Undo afterwards: the same Put it back button, or sudo /usr/local/sbin/tracker-sysctl.sh revert.' }));
        }
        const ok = $('sy-confirm-ok');
        ok.textContent = '';
        ok.appendChild(el('i', { className: 'bi bi-check-lg' }));
        ok.appendChild(document.createTextNode(op === 'arm' ? ' Apply for now' : ' Keep it'));
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
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working…';
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
                showToast(r.message || 'Queued.', 'success');
                painted = 0;
                load(true);
            } else {
                alert.textContent = '';
                alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: r.error || 'Failed.' }));
            }
        } catch (err) {
            alert.textContent = '';
            alert.appendChild(el('div', { className: 'nl-note nl-note-bad', text: 'Network error.' }));
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function revert() {
        if (!window.confirm('Put the captured values back?\n\nThis restores what the machine had before the panel first touched these settings, and removes the panel’s file. It never asks for a password, on purpose.')) return;
        try {
            const r = await apiCall('admin/sysctl_apply', 'POST', { op: 'revert' });
            showToast(r.success ? (r.message || 'Queued.') : (r.error || 'Failed.'), r.success ? 'success' : 'error');
            painted = 0;
            load(true);
        } catch { showToast('Network error', 'error'); }
    }

    async function preview() {
        const pairs = changedPairs();
        if (!Object.keys(pairs).length) { showToast('Nothing is different from what is already in force.', 'info'); return; }
        try {
            const r = await apiCall('admin/sysctl_apply', 'POST', { op: 'preview', values: pairs });
            $('sy-preview-title').textContent = r.file || 'File preview';
            $('sy-preview-body').textContent = r.content || r.error || '(nothing)';
            bootstrap.Modal.getOrCreateInstance($('syPreviewModal')).show();
        } catch { showToast('Network error', 'error'); }
    }

    function useSuggested() {
        const s = state.suggest || {};
        const names = Object.keys(s);
        if (!names.length) {
            showToast('Nothing here is supported by a measurement on this machine right now — so nothing is suggested.', 'info');
            return;
        }
        names.forEach(k => { state.wanted[k] = String(s[k].value); });
        render();
        showToast('Filled in ' + names.length + ' value(s) the counters actually support.', 'success');
    }

    function init() {
        $('btn-sy-arm').addEventListener('click', () => ask('arm'));
        $('btn-sy-confirm').addEventListener('click', () => ask('confirm'));
        $('btn-sy-revert').addEventListener('click', revert);
        $('btn-sy-preview').addEventListener('click', preview);
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
