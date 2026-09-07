/**
 * Settings → Languages — api/admin/languages.php on the server.
 *
 * Ported from TryHackX-Files and kept behaviourally the same, including the part that is easy to
 * get wrong: THREE lists, not one. `enabled` decides which languages exist for visitors at all;
 * within that, `switcher` is what the header control offers and `users` is what an account may
 * pick (which also bounds automatic browser matching). A language kept out of the last two is
 * still reachable by an explicit ?lang= link — hiding a control is not withdrawing a translation,
 * and the table says so rather than making the operator infer it.
 *
 * Rendered through textContent / createElement only.
 */
(function () {
    'use strict';

    if (typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);
    const body = $('lang-body');
    if (!body) return;

    let langs = [];
    let known = [];
    let pending = null;      // strings parsed from the picked file, until it is submitted
    let dupSource = null;

    const fmt = (n) => Number(n || 0).toLocaleString();

    async function load() {
        const d = await apiCall('admin/languages');
        if (d.error) { showToast(d.error, 'danger'); return; }
        langs = d.languages || [];
        known = d.known || [];
        render(d);
    }

    /** One switch. Rendered as a real checkbox so it is keyboard-reachable and announced. */
    function toggle(l, scope, on, disabled, title) {
        const wrap = el('div', { className: 'form-check form-switch mb-0' });
        const cb = el('input', { className: 'form-check-input' });
        cb.type = 'checkbox';
        cb.checked = !!on;
        cb.disabled = !!disabled;
        cb.id = 'lang-' + scope + '-' + l.code;
        if (title) cb.title = title;
        cb.setAttribute('aria-label', ({ enabled: t('js.languages.scope_enabled'), switcher: t('js.languages.scope_switcher'), users: t('js.languages.scope_users') })[scope] + ' — ' + l.name);
        cb.addEventListener('change', () => flip(l.code, scope, cb.checked, cb));
        wrap.appendChild(cb);
        return wrap;
    }

    async function flip(code, scope, on, cb) {
        cb.disabled = true;
        const d = await apiCall('admin/languages', 'POST', { op: 'toggle', code, scope, enabled: on });
        if (d.error) {
            showToast(d.error, 'danger');
            cb.checked = !on;       // the server refused; show what is actually stored
        }
        cb.disabled = false;
        load();
    }

    function render(d) {
        body.textContent = '';
        $('lang-default').value = d.default || 'en';
        // The site default select only offers languages that are actually enabled, plus "automatic".
        const sel = $('lang-default');
        sel.textContent = '';
        sel.appendChild(el('option', { value: 'auto', text: t('js.languages.auto_default') }));
        langs.filter(l => l.enabled).forEach(l => {
            sel.appendChild(el('option', { value: l.code, text: l.name + ' (' + l.code.toUpperCase() + ')' }));
        });
        sel.value = d.default || 'en';

        $('lang-auto').checked = !!d.auto;
        $('lang-ref').textContent = t('js.languages.ref_strings', { n: fmt(d.reference) });
        // A lang/ directory the web user cannot write to means every install and copy will fail.
        // Better said here, once, than discovered as "could not write the language file".
        $('lang-writable').classList.toggle('d-none', !!d.writable);

        langs.forEach(l => {
            const tr = el('tr');

            const name = el('td');
            name.appendChild(el('div', { className: 'lang-name', text: l.name }));
            const badges = el('div', { className: 'lang-badges' });
            badges.appendChild(el('code', { className: 'lang-code', text: l.code }));
            if (l.builtIn) badges.appendChild(el('span', { className: 'wl-badge wl-b-muted', text: t('js.languages.ships_badge') }));
            if (l.code === d.default) badges.appendChild(el('span', { className: 'wl-badge wl-b-ok', text: t('js.languages.site_default_badge') }));
            name.appendChild(badges);
            tr.appendChild(name);

            // Coverage against English, which is what everything falls back to — so the number
            // means "how much of what the app asks for is answered", not "how big is this file".
            const cov = el('td');
            const bar = el('div', { className: 'lang-cov' });
            const fill = el('i');
            fill.style.width = Math.min(100, l.coverage) + '%';
            if (l.coverage < 60) fill.className = 'low';
            else if (l.coverage < 95) fill.className = 'mid';
            bar.appendChild(fill);
            cov.appendChild(bar);
            cov.appendChild(el('div', { className: 'wl-small text-muted lang-cov-text',
                text: t('js.languages.cov_text', { pct: l.coverage, n: fmt(l.strings) })
                    + (l.missing ? t('js.languages.cov_missing', { n: fmt(l.missing) }) : '') }));
            tr.appendChild(cov);

            const on = el('td', { className: 'lang-col-sw' });
            on.appendChild(l.builtIn
                ? el('span', { className: 'lang-lock', title: t('js.languages.lock_title') }, [
                      el('i', { className: 'bi bi-lock-fill' })])
                : toggle(l, 'enabled', l.enabled, false));
            tr.appendChild(on);

            // The two visibility columns are meaningless for a language nobody can reach.
            const sw = el('td', { className: 'lang-col-sw' });
            sw.appendChild(l.enabled ? toggle(l, 'switcher', l.switcher, false)
                                     : el('span', { className: 'text-muted', text: '—' }));
            tr.appendChild(sw);

            const us = el('td', { className: 'lang-col-sw' });
            us.appendChild(l.enabled ? toggle(l, 'users', l.users, false)
                                     : el('span', { className: 'text-muted', text: '—' }));
            tr.appendChild(us);

            const acts = el('td', { className: 'lang-col-acts' });
            const actsBox = el('div', { className: 'lang-acts' });
            const dup = el('button', { className: 'btn btn-sm btn-outline-secondary' });
            dup.type = 'button'; dup.title = t('js.languages.copy_title');
            dup.appendChild(el('i', { className: 'bi bi-files' }));
            dup.addEventListener('click', () => askDuplicate(l.code));
            actsBox.appendChild(dup);

            const exp = el('button', { className: 'btn btn-sm btn-outline-secondary' });
            exp.type = 'button'; exp.title = t('js.languages.download_title');
            exp.appendChild(el('i', { className: 'bi bi-download' }));
            exp.addEventListener('click', () => exportLang(l.code));
            actsBox.appendChild(exp);

            if (!l.builtIn) {
                const del = el('button', { className: 'btn btn-sm btn-outline-danger' });
                del.type = 'button'; del.title = t('js.languages.remove');
                del.appendChild(el('i', { className: 'bi bi-trash' }));
                del.addEventListener('click', () => askDelete(l));
                actsBox.appendChild(del);
            }
            acts.appendChild(actsBox);
            tr.appendChild(acts);
            body.appendChild(tr);
        });
    }

    /** Download a language as JSON — the starting point for translating it. */
    async function exportLang(code) {
        const d = await apiCall('admin/languages&export=' + encodeURIComponent(code));
        if (d.error) { showToast(d.error, 'danger'); return; }
        const blob = new Blob([JSON.stringify({ code: d.code, strings: d.strings }, null, 2)],
                              { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'tracker-lang-' + code + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    }

    // ── duplicate ───────────────────────────────────────────────────────────
    function askDuplicate(source) {
        dupSource = source;
        $('ld-source').textContent = source.toUpperCase();
        $('ld-code').value = '';
        setMsg('ld-msg', '');
        bootstrap.Modal.getOrCreateInstance($('langDupModal')).show();
        setTimeout(() => $('ld-code').focus(), 200);
    }

    async function submitDuplicate() {
        const code = ($('ld-code').value || '').trim().toLowerCase();
        if (!/^[a-z]{2,3}$/.test(code)) { setMsg('ld-msg', t('js.languages.code_len')); return; }
        const d = await apiCall('admin/languages', 'POST', { op: 'duplicate', source: dupSource, code });
        if (d.error) { setMsg('ld-msg', d.error); return; }
        bootstrap.Modal.getOrCreateInstance($('langDupModal')).hide();
        showToast(d.message || t('js.languages.copied'), 'success');
        load();
    }

    // ── install ─────────────────────────────────────────────────────────────
    function openUpload() {
        $('lu-code').value = '';
        $('lu-file').value = '';
        $('lu-file-info').textContent = '';
        markFile(null);
        $('lu-code-name').textContent = '';
        $('lu-pick-label').textContent = t('js.languages.choose_language');
        pending = null;
        setMsg('lu-msg', '');
        renderMenu();
        bootstrap.Modal.getOrCreateInstance($('langUploadModal')).show();
    }

    /**
     * The language dropdown — every code the panel has a name for, installed ones marked.
     *
     * Installed languages are LISTED, not hidden: choosing one is how a translation is replaced with
     * a newer file, and a control that silently omits that option looks broken to somebody who came
     * to do exactly that. They are labelled instead.
     */
    function renderMenu() {
        const menu = $('lu-menu');
        menu.textContent = '';
        const free = known.filter(l => !l.installed);
        const taken = known.filter(l => l.installed);
        const add = (list, header) => {
            if (!list.length) return;
            menu.appendChild(el('li', {}, [el('h6', { className: 'dropdown-header', text: header })]));
            list.forEach(l => {
                const a = el('button', { className: 'dropdown-item', type: 'button' }, [
                    el('span', { className: 'lang-dd-code', text: l.code }),
                    l.name,
                ]);
                a.addEventListener('click', () => { $('lu-code').value = l.code; onCode(); });
                menu.appendChild(el('li', {}, [a]));
            });
        };
        add(free, t('js.languages.menu_free'));
        add(taken, t('js.languages.menu_taken'));
        if (!menu.childNodes.length) {
            menu.appendChild(el('li', {}, [el('div', { className: 'dropdown-item-text wl-dd-note',
                text: t('js.languages.menu_all_installed') })]));
        }
    }

    /**
     * The native name of a language code, from the browser's own ISO table — or null.
     *
     * Intl.DisplayNames answers with the CODE ITSELF for something it does not know, which is not
     * a name; that case is null here so the caller can say "no name for it" honestly. Asked in the
     * language's own locale first (so `de` gives "Deutsch", not "German"), English as the fallback.
     */
    function intlName(code) {
        if (typeof Intl === 'undefined' || typeof Intl.DisplayNames !== 'function') return null;
        for (const loc of [code, 'en']) {
            try {
                const n = new Intl.DisplayNames([loc], { type: 'language', fallback: 'none' }).of(code);
                if (n && n.toLowerCase() !== code.toLowerCase()) return n;
            } catch (_) { /* an invalid tag throws RangeError; try the next locale */ }
        }
        return null;
    }

    /** Resolve the typed code to a name as it is typed — and say NOW what will be refused later. */
    function onCode() {
        const code = ($('lu-code').value || '').trim().toLowerCase();
        const out = $('lu-code-name');
        out.className = 'wl-small mt-1';
        const picked = known.find(l => l.code === code);
        $('lu-pick-label').textContent = code
            ? (picked ? picked.name + ' (' + code.toUpperCase() + ')' : code.toUpperCase())
            : t('js.languages.choose_language');
        if (!code) { out.textContent = ''; return; }
        const k = known.find(l => l.code === code);
        if (langs.some(l => l.code === code && l.builtIn)) {
            out.textContent = t('js.languages.builtin_no_upload');
            out.className = 'wl-small text-warning';
        } else if (k && k.installed) {
            out.textContent = t('js.languages.already_installed', { name: k.name });
            out.className = 'wl-small text-warning';
        } else if (k) {
            out.textContent = '→ ' + k.name;
            out.className = 'wl-small text-success';
        } else if (/^[a-z]{2,3}$/.test(code)) {
            // The panel's own table is short on purpose (it feeds the dropdown). The browser ships
            // the whole ISO list, so a typed code the panel has no name for is still RECOGNISED:
            // Intl.DisplayNames gives its native name for anything the browser knows.
            const native = intlName(code);
            if (native) {
                out.textContent = '→ ' + native;
                out.className = 'wl-small text-success';
                $('lu-pick-label').textContent = native + ' (' + code.toUpperCase() + ')';
            } else {
                out.textContent = t('js.languages.accepted_no_name', { code: code.toUpperCase() });
                out.className = 'wl-small text-muted';
            }
        } else {
            out.textContent = t('js.languages.code_len_example');
            out.className = 'wl-small text-warning';
        }
    }

    /** Parse the picked file in the browser, so problems surface before anything is sent. */
    /** Show which file the zone holds — the box itself says so, like the address-list import. */
    function markFile(file) {
        const z = $('lu-drop');
        if (!z) return;
        z.classList.toggle('has-file', !!file);
        const main = z.querySelector('.ipl-drop-main');
        if (main) {
            main.textContent = '';
            if (file) main.appendChild(document.createTextNode(file.name));
            else { main.appendChild(el('u', { text: t('js.languages.choose_file') })); main.appendChild(document.createTextNode(t('js.languages.or_drop'))); }
        }
    }

    function onFile(dropped) {
        const input = $('lu-file');
        const info = $('lu-file-info');
        const file = dropped || (input.files && input.files[0]);
        pending = null;
        markFile(file || null);
        if (!file) { info.textContent = ''; return; }
        const reader = new FileReader();
        reader.onload = () => {
            try {
                const parsed = JSON.parse(String(reader.result));
                // Either a bare map of strings or an export wrapped as {code, strings}.
                const strings = (parsed && typeof parsed === 'object' && parsed.strings) ? parsed.strings : parsed;
                const keys = Object.keys(strings || {});
                if (!keys.length) throw new Error('empty');
                pending = strings;
                info.textContent = t('js.languages.strings_read', { n: fmt(keys.length), file: file.name });
                if (parsed && parsed.code && !$('lu-code').value) {
                    $('lu-code').value = String(parsed.code).toLowerCase();
                    onCode();
                }
            } catch (e) {
                info.textContent = t('js.languages.not_json');
            }
        };
        reader.readAsText(file);
    }

    async function submitUpload() {
        const code = ($('lu-code').value || '').trim().toLowerCase();
        if (!/^[a-z]{2,3}$/.test(code)) { setMsg('lu-msg', t('js.languages.code_len')); return; }
        if (!pending) { setMsg('lu-msg', t('js.languages.pick_file_first')); return; }
        const d = await apiCall('admin/languages', 'POST', { op: 'upload', code, strings: pending });
        if (d.error) { setMsg('lu-msg', d.error); return; }
        bootstrap.Modal.getOrCreateInstance($('langUploadModal')).hide();
        showToast(d.message || t('js.languages.installed'), 'success');
        load();
    }

    async function askDelete(l) {
        const ok = await confirmAction(t('js.languages.remove_title', { name: l.name }),
            t('js.languages.remove_body', { code: l.code }),
            { okLabel: t('js.languages.remove'), danger: true });
        if (!ok) return;
        const d = await apiCall('admin/languages', 'POST', { op: 'delete', code: l.code });
        if (d.error) { showToast(d.error, 'danger'); return; }
        showToast(d.message || t('js.languages.removed'), 'success');
        load();
    }

    function setMsg(id, text) {
        const box = $(id);
        box.textContent = text || '';
        box.classList.toggle('d-none', !text);
    }

    // ── wiring ──────────────────────────────────────────────────────────────
    $('lang-default').addEventListener('change', async (e) => {
        const d = await apiCall('admin/languages', 'POST', { op: 'default', code: e.target.value });
        if (d.error) { showToast(d.error, 'danger'); load(); return; }
        showToast(d.message || t('js.languages.saved'), 'success');
        load();
    });
    $('lang-auto').addEventListener('change', async (e) => {
        const d = await apiCall('admin/languages', 'POST', { op: 'auto', enabled: e.target.checked });
        if (d.error) { showToast(d.error, 'danger'); return; }
        showToast(d.message || t('js.languages.saved'), 'success');
    });
    $('lang-add').addEventListener('click', openUpload);
    $('lu-code').addEventListener('input', onCode);
    $('lu-file').addEventListener('change', () => onFile(null));
    const luDrop = $('lu-drop');
    if (luDrop) {
        // dragover must be cancelled or the browser navigates to the file, losing the dialog.
        ['dragenter', 'dragover'].forEach(ev => luDrop.addEventListener(ev, (e) => {
            e.preventDefault(); luDrop.classList.add('dragging');
        }));
        ['dragleave', 'drop'].forEach(ev => luDrop.addEventListener(ev, () => luDrop.classList.remove('dragging')));
        luDrop.addEventListener('drop', (e) => {
            e.preventDefault();
            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) onFile(f);
        });
        luDrop.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('lu-file').click(); }
        });
    }
    $('lu-submit').addEventListener('click', submitUpload);
    $('ld-submit').addEventListener('click', submitDuplicate);
    $('ld-code').addEventListener('keydown', (e) => { if (e.key === 'Enter') submitDuplicate(); });
    $('lang-template').addEventListener('click', () => exportLang('en'));

    load();
})();
