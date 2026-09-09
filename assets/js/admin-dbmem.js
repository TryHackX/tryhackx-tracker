/**
 * "Database memory" card on the admin Traffic page — includes/dbmem.php on the server.
 *
 * One table: what the engine runs with now, what the drop-in says, and a field for the new value
 * with the unit the reader chose (MiB/GiB for sizes, plain for counts). A badge on every row says
 * whether the engine changes it live or waits for a restart, because the answer differs between
 * MariaDB and MySQL and between versions, and the helper is the one that knows. Apply and Restart
 * are separate buttons behind separate password prompts: the first never restarts anything, the
 * second restarts a database that every service on the machine shares.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    const card = document.getElementById('dbmem-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction, promptPassword, fmtBytes } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);
    const POLL_MS = 20000;
    const MIB = 1024 * 1024, GIB = 1024 * MIB;

    const state = { data: null, wanted: {}, unit: {} };

    const num = (v) => (v === null || v === undefined || isNaN(v)) ? '—' : Math.round(v).toLocaleString();
    const isBytes = (k) => !(k === 'max_connections' || k === 'table_open_cache');

    /* ── polling ─────────────────────────────────────────────────────────── */
    let seq = 0, painted = 0, tick = null;

    async function load(force) {
        if (!force && document.hidden) return;
        const my = ++seq;
        let j;
        try { j = await apiCall('admin/dbmem_status'); }
        catch (e) { if (my > painted) { painted = my; fatal((e && e.message) || t('js.dbmem.network_error')); } return; }
        if (my <= painted) return;
        painted = my;
        if (!j || j.enabled === false) { fatal(t('js.dbmem.helper_off')); return; }
        if (!j.ok) { fatal(j.error || t('js.dbmem.helper_no_answer')); return; }
        state.data = j;
        seedWanted();
        if (formDirty() || gridHasFocus()) return;
        render();
    }

    function gridHasFocus() {
        const g = $('dm-grid');
        return !!(g && document.activeElement && g.contains(document.activeElement));
    }

    function fatal(msg) {
        const g = $('dm-grid');
        g.textContent = '';
        g.appendChild(el('div', { className: 'nl-note nl-note-bad', text: msg || t('js.dbmem.unavailable') }));
        g.appendChild(el('div', {}, [el('button', { className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button',
            onclick: () => { painted = 0; load(true); } }, [el('i', { className: 'bi bi-arrow-clockwise' }), ' ' + t('js.dbmem.try_again')])]));
        $('dm-notes').textContent = '';
        ['btn-dm-apply', 'btn-dm-restart', 'btn-dm-reset'].forEach(id => { const b = $(id); if (b) b.disabled = true; });
    }

    /** The wanted map starts as what runs, so a row the operator never touches is not a change. */
    function seedWanted() {
        const vars = (state.data.status || {}).vars || {};
        (state.data.key_names || []).forEach(k => {
            if (state.wanted[k] === undefined && vars[k] !== undefined) state.wanted[k] = Number(vars[k]);
            if (!state.unit[k]) state.unit[k] = isBytes(k) ? (Number(vars[k]) >= GIB ? 'GiB' : 'MiB') : 'n';
        });
    }
    function formDirty() {
        const vars = (state.data && state.data.status || {}).vars || {};
        return Object.keys(state.wanted).some(k => vars[k] !== undefined && Number(vars[k]) !== Number(state.wanted[k]));
    }
    function toUnit(bytes, u) { return u === 'GiB' ? bytes / GIB : u === 'MiB' ? bytes / MIB : bytes; }
    function fromUnit(v, u) { return Math.round(u === 'GiB' ? v * GIB : u === 'MiB' ? v * MIB : v); }
    function human(k, v) { return isBytes(k) ? fmtBytes(Number(v) || 0) : num(v); }

    /* ── render ───────────────────────────────────────────────────────────── */
    function render() {
        const d = state.data, st = d.status || {}, vars = st.vars || {}, s = st.status || {}, keys = st.keys || {}, file = st.file_values || {};
        const g = $('dm-grid');
        g.textContent = '';
        $('dm-updated').textContent = new Date(d.server_time * 1000).toLocaleTimeString();

        // the engine, the machine
        const head = el('div', { className: 'dm-facts' });
        const fact = (label, value, cls) => head.appendChild(el('div', { className: 'dm-fact' + (cls ? ' ' + cls : '') }, [
            el('span', { className: 'dm-fact-k', text: label }), el('span', { className: 'dm-fact-v', text: value })]));   // returns the row, so a caller can add a title
        fact(t('js.dbmem.engine'), (st.engine === 'mariadb' ? 'MariaDB' : 'MySQL') + ' ' + (st.server_version || '').split('-')[0] + (st.unit ? ' · ' + st.unit : ''));
        fact(t('js.dbmem.ram'), fmtBytes((st.mem_total_kb || 0) * 1024) + ' · ' + t('js.dbmem.available', { v: fmtBytes((st.mem_available_kb || 0) * 1024) }));
        const total = Number(s.Innodb_buffer_pool_pages_total) || 0, free = Number(s.Innodb_buffer_pool_pages_free) || 0;
        if (total) fact(t('js.dbmem.pool_used'), Math.round(100 * (total - free) / total) + ' %');
        const reads = Number(s.Innodb_buffer_pool_reads) || 0, reqs = Number(s.Innodb_buffer_pool_read_requests) || 0;
        if (reqs) fact(t('js.dbmem.disk_reads'), (100 * reads / reqs).toFixed(2) + ' %');
        const tmpAll = Number(s.Created_tmp_tables) || 0, tmpDisk = Number(s.Created_tmp_disk_tables) || 0;
        if (tmpAll) fact(t('js.dbmem.tmp_disk'), Math.round(100 * tmpDisk / tmpAll) + ' %');
        fact(t('js.dbmem.connections'), t('js.dbmem.of_limit', { used: num(s.Threads_connected), peak: num(s.Max_used_connections), limit: num(vars.max_connections) }));
        // WHAT THE POOL IS FOR. Every other number here describes the pool; without these three,
        // "is the pool big enough?" cannot be answered from this screen at all — and on this
        // deployment the catalogue is several times the pool, which is the single most useful thing
        // the card can say. `title` carries the data/index split and the row estimate, because a
        // table that is mostly index is a different problem from one that is mostly rows.
        const sz = d.sizes || {};
        if (sz.db != null) fact(t('js.dbmem.db_size'), fmtBytes(sz.db));
        ['index_hashes', 'index_files'].forEach(name => {
            const tsz = (sz.tables || {})[name];
            if (!tsz) return;
            const f = fact(t('js.dbmem.table_size', { table: name }), fmtBytes(tsz.total));
            f.title = t('js.dbmem.table_size_title', {
                data: fmtBytes(tsz.data), index: fmtBytes(tsz.index),
                rows: tsz.rows == null ? '—' : Number(tsz.rows).toLocaleString(),
            });
        });
        g.appendChild(head);

        // the table
        const table = el('table', { className: 'table table-dark table-sm dm-table' });
        table.appendChild(el('thead', {}, [el('tr', {}, [
            el('th', { text: t('js.dbmem.col_key') }), el('th', { text: t('js.dbmem.col_live') }),
            el('th', { text: t('js.dbmem.col_file') }), el('th', { text: t('js.dbmem.col_new') }), el('th', { text: '' })])]));
        const tb = el('tbody', {});
        (d.key_names || []).forEach(k => {
            const meta = keys[k] || {};
            if (meta.supported === false) return;
            const live = vars[k] !== undefined ? Number(vars[k]) : null;
            const inFile = file[k] !== undefined ? Number(file[k]) : null;
            const tr = el('tr', { className: 'dm-row' + (inFile !== null && live !== null && inFile !== live ? ' dm-row-pending' : '') });
            tr.appendChild(el('td', {}, [el('div', { className: 'dm-key', text: t('js.dbmem.k_' + k) }), el('div', { className: 'dm-hint', text: t('js.dbmem.h_' + k) })]));
            tr.appendChild(el('td', { className: 'font-mono', text: live === null ? '—' : human(k, live) }));
            tr.appendChild(el('td', { className: 'font-mono text-muted', text: inFile === null ? '—' : human(k, inFile) }));
            // the input, in the reader's unit
            const cell = el('td', { className: 'dm-input-cell' });
            const inp = el('input', { type: 'number', className: 'form-control form-control-sm bg-dark text-light border-secondary dm-input', min: '0', step: isBytes(k) ? '0.25' : '1' });
            inp.dataset.key = k;
            inp.value = state.wanted[k] === undefined ? '' : String(parseFloat(toUnit(state.wanted[k], state.unit[k]).toFixed(2)));
            inp.addEventListener('input', () => {
                const v = parseFloat(inp.value);
                if (isNaN(v)) return;
                state.wanted[k] = fromUnit(v, state.unit[k]);
                tr.classList.toggle('dm-row-dirty', live !== null && state.wanted[k] !== live);
            });
            cell.appendChild(inp);
            if (isBytes(k)) {
                const sel = el('select', { className: 'form-select form-select-sm bg-dark text-light border-secondary dm-unit' });
                ['MiB', 'GiB'].forEach(u => sel.appendChild(el('option', { value: u, text: u })));
                sel.value = state.unit[k];
                sel.addEventListener('change', () => { state.unit[k] = sel.value; inp.value = String(parseFloat(toUnit(state.wanted[k], sel.value).toFixed(2))); });
                cell.appendChild(sel);
            }
            tr.appendChild(cell);
            const badge = meta.dynamic ? el('span', { className: 'dm-badge dm-badge-live', text: t('js.dbmem.live_badge'), title: t('js.dbmem.live_title') })
                                       : el('span', { className: 'dm-badge dm-badge-restart', text: t('js.dbmem.restart_badge'), title: t('js.dbmem.restart_title_badge') });
            tr.appendChild(el('td', {}, [badge]));
            tb.appendChild(tr);
        });
        table.appendChild(tb);
        g.appendChild(el('div', { className: 'table-responsive' }, [table]));

        // notes: pending restart, deferred write, advice, last outcome
        const notes = $('dm-notes');
        notes.textContent = '';
        if (d.restart_pending && d.restart_pending.length) {
            notes.appendChild(el('div', { className: 'nl-note nl-note-warn', text: t('js.dbmem.restart_pending', { keys: d.restart_pending.join(', ') }) }));
        }
        if (d.pending) notes.appendChild(el('div', { className: 'nl-note nl-note-info', text: t('js.dbmem.deferred') }));
        (d.advice || []).forEach(a => notes.appendChild(el('div', { className: 'nl-note ' + (a.level === 'warn' ? 'nl-note-warn' : 'nl-note-info'), text: a.text })));
        if (d.last_error) notes.appendChild(el('div', { className: 'nl-note nl-note-bad', text: d.last_error }));
        if (st.file) notes.appendChild(el('div', { className: 'wl-small text-muted mt-1', text: t('js.dbmem.file_line', { file: st.file, present: st.file_present ? t('js.dbmem.file_present') : t('js.dbmem.file_absent') }) }));
        ['btn-dm-apply', 'btn-dm-restart', 'btn-dm-reset'].forEach(id => { const b = $(id); if (b) b.disabled = false; });
    }

    /* ── actions ─────────────────────────────────────────────────────────── */
    function changedPairs() {
        const vars = (state.data.status || {}).vars || {}, out = {};
        Object.keys(state.wanted).forEach(k => { if (vars[k] === undefined || Number(state.wanted[k]) !== Number(vars[k])) out[k] = Math.round(state.wanted[k]); });
        return out;
    }

    async function apply() {
        if (!state.data) return;
        const pairs = changedPairs();
        if (!Object.keys(pairs).length) { showToast(t('js.dbmem.nothing_changed'), 'info'); return; }
        const list = Object.keys(pairs).map(k => t('js.dbmem.k_' + k) + ': ' + human(k, pairs[k])).join(' · ');
        const pw = await promptPassword(t('js.dbmem.apply_title'), t('js.dbmem.apply_body') + '\n' + list);
        if (!pw) return;
        const btn = $('btn-dm-apply'); btn.disabled = true;
        try {
            const r = await apiCall('admin/dbmem_apply', 'POST', { op: 'apply', values: pairs, password: pw });
            if (!r || r.error) { showToast((r && r.error) || t('js.dbmem.apply_failed'), 'danger'); return; }
            const applied = (r.result && r.result.applied) || {};
            let live = 0, restart = 0;
            const lines = [];
            Object.keys(applied).forEach(k => {
                const a = applied[k];
                if (a.live) live++; if (a.restart_required) restart++;
                if (a.note) lines.push(t('js.dbmem.k_' + k) + ': ' + a.note);
            });
            showToast(t('js.dbmem.applied', { n: live, r: restart }) + (r.result && r.result.deferred ? ' ' + t('js.dbmem.deferred') : ''), restart ? 'warning' : 'success');
            if (lines.length) $('dm-notes').appendChild(el('div', { className: 'nl-note nl-note-info', text: lines.join(' · ') }));
            state.wanted = {}; state.unit = {};
            painted = 0; await load(true);
        } catch (e) { showToast((e && e.message) || t('js.dbmem.apply_failed'), 'danger'); }
        finally { btn.disabled = false; }
    }

    async function restart() {
        if (!state.data) return;
        if (!(await confirmAction(t('js.dbmem.restart_title'), t('js.dbmem.restart_body'), { danger: true, okLabel: t('js.dbmem.restart_ok_label') }))) return;
        const pw = await promptPassword(t('js.dbmem.restart_title'), t('js.dbmem.restart_pw_body'));
        if (!pw) return;
        const btn = $('btn-dm-restart'); btn.disabled = true;
        const origHtml = btn.innerHTML;
        btn.textContent = t('js.dbmem.restarting');
        try {
            const r = await apiCall('admin/dbmem_apply', 'POST', { op: 'restart', ack: true, password: pw });
            if (!r || r.error) { showToast((r && r.error) || t('js.dbmem.restart_failed'), 'danger'); return; }
            showToast(t('js.dbmem.restart_done', { s: (r.result && r.result.seconds) || 0 }), 'success');
            state.wanted = {}; state.unit = {};
            painted = 0; await load(true);
        } catch (e) { showToast((e && e.message) || t('js.dbmem.restart_failed'), 'danger'); }
        finally { btn.innerHTML = origHtml; btn.disabled = false; }
    }

    function reset() { state.wanted = {}; state.unit = {}; if (state.data) { seedWanted(); render(); } }

    $('btn-dm-apply') && $('btn-dm-apply').addEventListener('click', apply);
    $('btn-dm-restart') && $('btn-dm-restart').addEventListener('click', restart);
    $('btn-dm-reset') && $('btn-dm-reset').addEventListener('click', reset);
    $('btn-dm-reload') && $('btn-dm-reload').addEventListener('click', () => { painted = 0; load(true); });

    load(true);
    tick = setInterval(() => load(false), POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(false); });
})();
