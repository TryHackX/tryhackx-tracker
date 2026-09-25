/**
 * Settings → Site → Font Awesome (1.69.0): the source, the package's style files, the style the
 * site's icons use with a live preview, and the packages themselves.
 *
 * The three selects and the hidden list of style files are SETTINGS: they sit in the settings form
 * and save with the page (api/admin/save_settings.php checks them against what is installed). What
 * this file adds is everything that is not a setting:
 *   * the preview — every change of the four asks admin/iconpacks?op=preview what that setup WOULD
 *     load and draw (judged the way the save will judge it), and draws the site's common icons with it
 *     in an iframe: an unsaved setup needs its own stylesheets, and Font Awesome 6 and 7 cannot share a
 *     document. It then measures, in that frame, how many of the map's icons the chosen style's own
 *     font draws — a 7.x family like Jelly has a few hundred glyphs, and the rest are drawn by the
 *     classic family through the font chain the site's stylesheet pins. A package that came with its
 *     index has that worked out on the server already (iconStyleHas()); the measurement then only
 *     reports what the index got wrong;
 *   * the style checkboxes of the chosen package, with each file's size and whether all.css has it;
 *   * the packages table (use, verify, delete), the zip upload and the install from a server path,
 *     each write behind the owner's password, with the import's report shown as it came back.
 *
 * Built with textContent and createElement throughout: a package's names come from a file somebody
 * uploaded. `js.iconpack.*` is not in LANG_JS_PUBLIC — none of it is read outside the panel.
 */
(function () {
    'use strict';
    const root = document.getElementById('admin-iconpacks');
    if (!root || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, el, showToast, confirmAction, promptPassword, fmtBytes, debounce } = window.AdminCommon;
    const t = window.t;
    const $ = (id) => document.getElementById(id);
    const sourceSel = $('setting-fa_source'), packSel = $('setting-fa_pack'), styleSel = $('setting-fa_style');
    const stylesIn = $('setting-fa_pack_styles'), stylesBox = $('ip-styles'), stylesGrid = $('ip-styles-grid'), stylesCore = $('ip-styles-core');
    const frame = $('ip-preview'), note = $('ip-preview-note'), cover = $('ip-coverage');
    const list = $('ip-list'), countEl = $('ip-count'), report = $('ip-report');
    const drop = $('ip-drop'), fileIn = $('ip-file'), upBtn = $('ip-upload'), pathIn = $('ip-path'), pathBtn = $('ip-install-path');
    const libSel = document.getElementById('setting-icon_library');
    if (!sourceSel || !packSel || !styleSel || !stylesIn || !frame) return;

    let state = null;          // the last GET: packages, setup, limits, preview names
    let preview = null;        // the last preview answer
    let dropped = null;
    let busy = false;
    let seq = 0;

    const ticks = () => { try { const j = JSON.parse(stylesIn.value || '[]'); return Array.isArray(j) ? j : []; } catch (e) { return []; } };
    const setTicks = (arr) => { stylesIn.value = JSON.stringify(arr); };

    // ── the preview ──────────────────────────────────────────────────────────
    async function refresh() {
        const my = ++seq;
        // `&`, not `?`: the API base already ends in `?endpoint=`.
        const q = 'admin/iconpacks&op=preview&source=' + encodeURIComponent(sourceSel.value)
            + '&pack=' + encodeURIComponent(packSel.value || '') + '&styles=' + encodeURIComponent(stylesIn.value || '[]')
            + '&style=' + encodeURIComponent(styleSel.value || 'solid');
        let j;
        try { j = await apiCall(q); } catch (e) { return; }
        if (my !== seq) return;           // a newer change asked meanwhile
        packSel.closest('[data-setting]').classList.toggle('ip-dim', sourceSel.value !== 'pack');
        if (!j.success) {
            preview = null;
            stylesBox.hidden = true;
            paintFrame(null, null);
            cover.textContent = '';
            cover.appendChild(el('div', { className: 'alert alert-warning py-1 px-2 wl-small mb-0' }, [el('i', { className: 'bi bi-exclamation-triangle' }), ' ' + (j.error || t('js.iconpack.failed'))]));
            return;
        }
        preview = j;
        paintChoices(j.setup, j.applied);
        paintStyles(j.setup);
        paintFrame(j.setup, j.map);
        paintCoverage(j.setup.coverage, null);
        paintNote();
    }
    const refreshSoon = debounce(refresh, 250);

    /** The style select offers what loads; a choice that no longer loads becomes what the save would make it. */
    function paintChoices(setup, applied) {
        const want = styleSel.value;
        styleSel.textContent = '';
        Object.entries(setup.choices || {}).forEach(([k, label]) => styleSel.appendChild(el('option', { value: k }, label)));
        styleSel.value = Object.prototype.hasOwnProperty.call(setup.choices || {}, want) ? want : (applied && applied.fa_style) || 'solid';
    }

    function paintStyles(setup) {
        const ps = setup.pack_styles || [];
        stylesBox.hidden = setup.source !== 'pack' || !ps.length;
        stylesGrid.textContent = '';
        if (stylesBox.hidden) return;
        // What loads anyway (all.css, or the core file's Solid and Brands) is one line, not a column of
        // ticked boxes nobody can untick; the separate files are the choice.
        stylesCore.textContent = '';
        const fixed = ps.filter((s) => s.fixed);
        stylesCore.appendChild(el('span', {}, (setup.core === 'fontawesome' ? t('js.iconpack.core_fontawesome') : t('js.iconpack.core_all')) + ' '));
        stylesCore.appendChild(el('span', { className: 'ip-core-list' }, fixed.map((s) => s.label + (s.layers > 1 ? ' (' + t('js.iconpack.two_layers') + ')' : '')).join(' · ')));
        const on = ticks();
        ps.filter((s) => !s.fixed).forEach((s) => {
            const id = 'ip-st-' + s.key;
            const box = el('input', { type: 'checkbox', className: 'form-check-input', id: id, value: s.key });
            box.checked = on.includes(s.key);
            box.disabled = !s.choosable;
            box.addEventListener('change', () => {
                const now = ticks().filter((k) => k !== s.key);
                if (box.checked) now.push(s.key);
                setTicks(now);
                refreshSoon();
            });
            // What ticking it costs a visitor: the stylesheet, and the font the first icon in it fetches.
            const size = (s.css_bytes ? fmtBytes(s.css_bytes) : '') + (s.font_bytes ? ' + ' + fmtBytes(s.font_bytes) : '');
            const row = el('label', { className: 'ip-style' + (s.loaded ? ' is-loaded' : ''), for: id, title: String(s.file || '') }, [
                box,
                el('span', { className: 'ip-style-name' }, s.label),
                el('span', { className: 'ip-style-facts' }, String(s.weight)),
                size ? el('span', { className: 'ip-style-size', title: t('js.iconpack.size_title') }, size) : null,
                s.layers > 1 ? el('span', { className: 'wl-badge wl-b-muted', title: t('js.iconpack.two_layers_title') }, t('js.iconpack.two_layers')) : null,
            ]);
            stylesGrid.appendChild(row);
        });
        if (!stylesGrid.children.length) stylesGrid.appendChild(el('div', { className: 'text-muted small' }, t('js.iconpack.no_extra')));
    }

    /** The site's common icons, drawn by the setup being previewed, in a document of its own. */
    function paintFrame(setup, map) {
        const base = frame.dataset.base || '/';
        const q = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        let head = '<!doctype html><html><head><meta charset="utf-8"><base href="' + q(base) + '">';
        if (setup) {
            (setup.css || []).forEach((c) => {
                head += '<link rel="stylesheet" href="' + q(c.href) + '"' + (c.integrity ? ' integrity="' + q(c.integrity) + '" crossorigin="anonymous"' : '') + '>';
            });
        }
        head += '<link rel="stylesheet" href="' + q(frame.dataset.css || '') + '">'
            + '<style>html,body{background:#0d0f14;margin:0}body{padding:.55rem .7rem;font-size:15px}'
            + '.ipv-row{display:flex;flex-wrap:wrap;gap:.35rem .55rem;align-items:center}'
            + '.ipv{display:inline-flex;align-items:center;justify-content:center;width:2.1em;height:2.1em;border:1px solid #232733;border-radius:4px;font-size:1.2em;color:#cdd1da}'
            + '.ipv-probe{position:absolute;left:-9999px;top:0}</style></head><body>';
        let body = '<div class="ipv-row">';
        const names = (state && state.preview) || [];
        if (setup && map) names.forEach((n) => { if (map[n]) body += '<span class="ipv" title="bi-' + q(n) + ' → ' + q(map[n]) + '"><i class="bi bi-' + q(n) + ' ' + q(map[n]) + '" aria-hidden="true"></i></span>'; });
        body += '</div>';
        // Every entry of the map, off screen, for the measurement below.
        if (setup && map) {
            body += '<div class="ipv-probe" id="ipv-probe">';
            Object.keys(map).forEach((n) => { body += '<i class="bi bi-' + q(n) + ' ' + q(map[n]) + '" data-n="' + q(n) + '"></i>'; });
            body += '</div>';
        }
        frameSetup = setup;
        frame.srcdoc = head + body + '</body></html>';
    }
    // One listener for every rebuild: it measures whatever setup the frame was last filled with.
    let frameSetup = null;
    frame.addEventListener('load', () => probe(frameSetup));

    /**
     * How many of the map's icons the chosen style's OWN font has. The site's stylesheet names the
     * classic family after the chosen one, so a glyph the chosen family lacks is still drawn — by the
     * classic font. Each glyph is drawn on a canvas in the frame twice: through that chain, and by the
     * classic font alone; the same pixels mean the chain fell through to classic. (Comparing with a
     * font's missing-glyph box instead is fooled on Windows, whose own icon fonts draw much of the
     * private-use area Font Awesome lives in.) A glyph the classic font lacks too draws nothing.
     *
     * Only for the families that carry a subset — 7.x's Jelly, Slab, Utility and the rest. Classic,
     * duotone, sharp and sharp duotone carry every icon of their edition, and a duotone font's first
     * layer is often the classic glyph to the pixel, so measuring those would only report fallbacks
     * that are not there.
     */
    const FULL_FAMILIES = ['classic', 'duotone', 'sharp', 'sharp-duotone', 'brands'];
    /** The glyph a computed `content` draws: its first string. Font Awesome 7 writes `var(--fa) / ""` —
     *  the icon, then an empty text for screen readers — which computes to `"X" / ""`; stripping the
     *  outer quotes would leave `X" / "`, three characters of another font drawn beside the icon. */
    const glyphOf = (c) => {
        const m = /^"((?:[^"\\]|\\.)*)"/.exec(String(c || ''));
        return m ? m[1].replace(/\\(.)/g, '$1') : '';
    };
    async function probe(setup) {
        if (!setup) return;
        const my = seq;
        const doc = frame.contentDocument, win = frame.contentWindow;
        if (!doc || !win) return;
        const box = doc.getElementById('ipv-probe');
        if (!box) return;
        const pick = (setup.styles || {})[setup.style] || null;
        if (!pick || FULL_FAMILIES.includes(pick.family)) {
            if (preview) paintCoverage(preview.setup.coverage, { own: box.children.length, other: 0, missing: 0, lacks: [], family: pick ? pick.font_family : '' });
            return;
        }
        try { await doc.fonts.ready; } catch (e) { /* the draw says it */ }
        const cv = doc.createElement('canvas'); cv.width = 48; cv.height = 48;
        const ctx = cv.getContext('2d', { willReadFrequently: true });
        const draw = (font, ch) => { ctx.clearRect(0, 0, 48, 48); ctx.font = font; ctx.fillStyle = '#000'; ctx.textBaseline = 'top'; ctx.fillText(ch, 4, 4); return ctx.getImageData(0, 0, 48, 48).data; };
        const same = (a, b) => { for (let i = 3; i < a.length; i += 4) if (a[i] !== b[i]) return false; return true; };
        const blank = (a) => { for (let i = 3; i < a.length; i += 4) if (a[i]) return false; return true; };
        const chosen = (setup.styles || {})[setup.style] || null;
        const classic = '"' + String(((setup.styles || {}).solid || {}).font_family || '').replace(/"/g, '') + '"';
        let own = 0, other = 0, missing = 0;
        const lacks = [];
        for (const i of box.children) {
            const cs = win.getComputedStyle(i, '::before');
            const ch = glyphOf(cs.content);
            if (!ch) { missing++; continue; }
            const first = String(cs.fontFamily || '').split(',')[0].trim();
            const w = cs.fontWeight + ' 36px ';
            try { await doc.fonts.load(w + first, ch); await doc.fonts.load(w + classic, ch); } catch (e) { /* ignore */ }
            if (my !== seq) return;
            const alone = draw(w + classic, ch);
            if (first.replace(/"/g, '') === classic.replace(/"/g, '') || /Brands/.test(first)) {
                if (blank(alone) && !/Brands/.test(first)) missing++; else own++;
                continue;
            }
            const chain = draw(w + first + ', ' + classic, ch);
            if (same(chain, alone)) {
                if (blank(alone)) missing++;
                else { other++; lacks.push(i.dataset.n); }
            } else own++;
        }
        if (my !== seq || !preview) return;
        paintCoverage(preview.setup.coverage, { own, other, missing, lacks, family: chosen ? chosen.font_family : '' });
    }

    function paintCoverage(c, measured) {
        cover.textContent = '';
        if (!c) return;
        const line = el('div', { className: 'ip-cov-line' }, [
            el('strong', {}, t('js.iconpack.cov_total', { n: c.total })), ' ',
            el('span', { className: 'wl-badge wl-b-ok' }, t('js.iconpack.cov_exact', { n: c.exact })), ' ',
            c.twins ? el('span', { className: 'wl-badge wl-b-api' }, t('js.iconpack.cov_twins', { n: c.twins })) : null, c.twins ? ' ' : null,
            el('span', { className: 'wl-badge wl-b-muted' }, t('js.iconpack.cov_approx', { n: c.approx })), ' ',
            el('span', { className: 'wl-badge ' + (c.fallbacks.length ? 'wl-b-warn' : 'wl-b-muted') }, t('js.iconpack.cov_fallbacks', { n: c.fallbacks.length })),
        ]);
        cover.appendChild(line);
        // A package with an index (1.69.0) has already had what the chosen family lacks drawn in the
        // classic family by the server — the "Not in …, so drawn in …" list below — so "every icon here is
        // drawn by Jelly itself" would be untrue then; the measurement speaks only of what it finds beyond
        // that list (a glyph the index promised and the font does not have).
        const known = c.fallbacks.some((f) => f.why === 'style_lacks');
        if (measured && (measured.missing || measured.other || !known)) {
            cover.appendChild(el('div', { className: 'ip-cov-measure ' + (measured.missing ? 'text-danger' : (measured.other ? 'text-warning' : 'text-muted')) },
                measured.missing ? t('js.iconpack.probe_missing', { n: measured.missing })
                    : (measured.other ? t('js.iconpack.probe_other', { n: measured.other, family: measured.family }) : t('js.iconpack.probe_all', { family: measured.family }))));
        }
        if (!c.fallbacks.length) return;
        // Grouped by reason and style: fifty outlines drawn in Regular because Sharp Regular is not
        // ticked is one sentence, not fifty.
        const groups = {};
        c.fallbacks.forEach((f) => {
            const k = f.why + '|' + (f.wanted || '') + '|' + (f.style || '');
            (groups[k] = groups[k] || { f, names: [] }).names.push(f.bi);
        });
        // A style by the name Font Awesome gives it ("Duotone Regular"), not by its key.
        const labels = {};
        ((preview && preview.setup.pack_styles) || []).forEach((s) => { labels[s.key] = s.label; });
        Object.entries((preview && preview.setup.styles) || {}).forEach(([k, s]) => { labels[k] = labels[k] || s.label; });
        const lab = (k) => labels[k] || k;
        const det = el('details', { className: 'ip-cov-list' }, [el('summary', {}, [el('i', { className: 'bi bi-chevron-right disc-chev', 'aria-hidden': 'true' }), ' ' + t('js.iconpack.cov_show')])]);
        Object.values(groups).forEach(({ f, names }) => {
            let why;
            if (f.why === 'twin_missing') why = t('js.iconpack.fb_twin', { wanted: f.wanted });
            else if (f.why === 'name_missing') why = t('js.iconpack.fb_name', { wanted: f.wanted });
            else if (f.why === 'style_not_loaded') why = t('js.iconpack.fb_not_loaded', { wanted: lab(f.wanted), drawn: lab(f.style) });
            else why = t('js.iconpack.fb_style', { wanted: lab(f.wanted), drawn: lab(f.style) });
            det.appendChild(el('div', { className: 'ip-fb' }, [el('span', { className: 'ip-fb-why' }, why), ' ', el('code', {}, names.map((n) => 'bi-' + n).join(' '))]));
        });
        cover.appendChild(det);
    }

    function paintNote() {
        const lib = libSel ? libSel.value : 'bootstrap';
        note.textContent = lib === 'fontawesome' ? '' : t('js.iconpack.note_bootstrap');
    }

    // ── the packages ─────────────────────────────────────────────────────────
    function renderList() {
        const packs = (state && state.packages) || [];
        list.textContent = '';
        if (countEl) { countEl.hidden = false; countEl.textContent = t('js.iconpack.count', { n: packs.filter((p) => !p.broken).length, max: state.limits.packages }); }
        if (!packs.length) { list.appendChild(el('div', { className: 'text-muted small' }, t('js.iconpack.none'))); return; }
        const table = el('table', { className: 'table table-dark table-sm align-middle mb-0 ip-table' }, [
            el('thead', {}, [el('tr', {}, [
                el('th', { scope: 'col' }, t('js.iconpack.col_package')),
                el('th', { scope: 'col' }, t('js.iconpack.col_styles')),
                el('th', { scope: 'col' }, t('js.iconpack.col_size')),
                el('th', { scope: 'col' }, t('js.iconpack.col_installed')),
                el('th', { scope: 'col', className: 'text-end' }, [el('span', { className: 'visually-hidden' }, t('js.iconpack.col_actions'))]),
            ])]),
        ]);
        const body = el('tbody');
        packs.forEach((p) => body.appendChild(rowFor(p)));
        table.appendChild(body);
        list.appendChild(table);
    }

    function rowFor(p) {
        if (p.broken) {
            const del = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', title: t('js.iconpack.delete'), 'aria-label': t('js.iconpack.delete') }, [el('i', { className: 'bi bi-trash' })]);
            del.addEventListener('click', () => remove(p));
            return el('tr', {}, [el('td', { colspan: '4' }, [el('code', {}, p.id), ' ', el('span', { className: 'wl-badge wl-b-bad' }, t('js.iconpack.broken'))]),
                                 el('td', { className: 'text-end' }, [del])]);
        }
        const use = el('button', { type: 'button', className: 'btn btn-sm btn-outline-info', title: t('js.iconpack.activate_title') }, [el('i', { className: 'bi bi-check2-circle' }), ' ' + t('js.iconpack.activate')]);
        use.addEventListener('click', () => activate(p));
        use.hidden = !!p.active;
        const ver = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', title: t('js.iconpack.verify'), 'aria-label': t('js.iconpack.verify') }, [el('i', { className: 'bi bi-shield-check' })]);
        ver.addEventListener('click', () => verify(p, ver));
        const del = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', title: p.active ? t('js.iconpack.delete_active') : t('js.iconpack.delete'), 'aria-label': t('js.iconpack.delete') }, [el('i', { className: 'bi bi-trash' })]);
        del.disabled = !!p.active;
        del.addEventListener('click', () => remove(p));
        const name = el('td', { className: 'ip-cell-name' }, [
            el('span', { className: 'ip-name' }, 'Font Awesome ' + (p.edition === 'pro' ? 'Pro' : 'Free') + ' ' + p.version),
            p.active ? el('span', { className: 'wl-badge wl-b-ok ms-1' }, t('js.iconpack.active')) : null,
            el('div', { className: 'ip-id' }, p.id),
        ]);
        const styles = el('td', { className: 'small' }, [t('js.iconpack.n_styles', { n: p.styles }),
            p.outside_all ? el('div', { className: 'text-muted' }, t('js.iconpack.n_outside', { n: p.outside_all })) : null]);
        const size = el('td', { className: 'small text-nowrap' }, [fmtBytes(p.bytes), el('div', { className: 'text-muted' }, t('js.iconpack.n_icons', { n: p.icons }))]);
        const when = el('td', { className: 'small' }, [String(p.installed_at || '').replace('T', ' ').replace('Z', ' UTC'),
            el('div', { className: 'text-muted' }, t('js.iconpack.by', { who: p.installed_by || '?' })
                + (p.metadata && p.metadata !== 'none' ? ' · ' + t('js.iconpack.meta_' + (p.metadata === 'pro' ? 'pro' : p.metadata === 'free' ? 'free' : 'unknown')) : ''))]);
        return el('tr', { className: p.active ? 'ip-row-active' : '' }, [name, styles, size, when,
            el('td', { className: 'text-end text-nowrap' }, [el('div', { className: 'd-inline-flex gap-1' }, [use, ver, del])])]);
    }

    /** The package select follows the table: a new package appears in it, a deleted one leaves it. */
    function syncPackSelect() {
        const packs = ((state && state.packages) || []).filter((p) => !p.broken);
        const was = packSel.value;
        packSel.textContent = '';
        if (!packs.length) packSel.appendChild(el('option', { value: '' }, t('js.iconpack.none_short')));
        packs.forEach((p) => packSel.appendChild(el('option', { value: p.id }, 'Font Awesome ' + (p.edition === 'pro' ? 'Pro' : 'Free') + ' ' + p.version + ' — ' + p.id)));
        if (packs.some((p) => p.id === was)) packSel.value = was;
        const packOpt = sourceSel.querySelector('option[value="pack"]');
        if (packOpt) packOpt.disabled = !packs.length && sourceSel.value !== 'pack';
    }

    /** A write that needs the password: asked for first, posted with it, the reply's refusal shown as it is. */
    async function withPassword(title, fn) {
        const pw = await promptPassword(title, t('js.iconpack.password_why'));
        if (pw === null) return null;
        const j = await fn(pw);
        if (j && j.signed_out) { showToast(j.error || t('js.iconpack.failed'), 'danger'); setTimeout(() => location.reload(), 1500); return null; }
        return j;
    }

    async function activate(p) {
        const j = await withPassword(t('js.iconpack.activate_title'), (pw) => apiCall('admin/iconpacks', 'POST', { op: 'activate', id: p.id, confirm_password: pw }));
        if (!j) return;
        if (!j.success) { showToast(j.error || t('js.iconpack.failed'), 'danger'); return; }
        showToast(j.message || 'OK', 'success');
        // The head of this very page loads the old stylesheet; the new one is a reload away.
        setTimeout(() => location.reload(), 900);
    }

    async function verify(p, btn) {
        btn.disabled = true;
        let j;
        try { j = await apiCall('admin/iconpacks&op=verify&id=' + encodeURIComponent(p.id)); } catch (e) { j = { error: t('js.iconpack.failed') }; }
        btn.disabled = false;
        if (!j.success) { showToast(j.error || t('js.iconpack.failed'), 'danger'); return; }
        const v = j.verify;
        if (v.ok) showToast(t('js.iconpack.verify_ok', { n: v.files }), 'success');
        else showToast(t('js.iconpack.verify_bad', { missing: v.missing.length, changed: v.changed.length }), 'danger');
    }

    async function remove(p) {
        if (!(await confirmAction(t('js.iconpack.delete'), t('js.iconpack.delete_confirm', { id: p.id }), { code: p.id }))) return;
        const j = await withPassword(t('js.iconpack.delete'), (pw) => apiCall('admin/iconpacks', 'POST', { op: 'delete', id: p.id, confirm_password: pw }));
        if (!j) return;
        if (!j.success) { showToast(j.error || t('js.iconpack.failed'), 'danger'); return; }
        state = j;
        renderList();
        syncPackSelect();
        refreshSoon();
        showToast(j.message || 'OK', 'success');
    }

    // ── adding one ───────────────────────────────────────────────────────────
    function markFile(f) {
        drop.classList.toggle('has-file', !!f);
        const main = drop.querySelector('.ipl-drop-main');
        main.textContent = '';
        if (f) main.appendChild(document.createTextNode(f.name + ' · ' + fmtBytes(f.size)));
        else { main.appendChild(el('u', {}, t('js.iconpack.drop_choose'))); main.appendChild(document.createTextNode(' ' + t('js.iconpack.drop_or'))); }
    }
    const chosen = () => dropped || (fileIn.files && fileIn.files[0]) || null;
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('dragging'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('dragging')));
    drop.addEventListener('drop', (e) => {
        e.preventDefault();
        if (busy) return;
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) { dropped = f; markFile(f); }
    });
    drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } });
    fileIn.addEventListener('change', () => { dropped = null; markFile(chosen()); });

    function setBusy(on) {
        busy = on;
        [upBtn, pathBtn, pathIn, fileIn].forEach((x) => { if (x) x.disabled = on; });
        root.classList.toggle('is-busy', on);
    }

    /** What the import said: where it found the package, what it kept and skipped, or why it refused. */
    function paintReport(j) {
        report.hidden = false;
        report.textContent = '';
        const r = j.report || {};
        report.classList.toggle('is-refused', !j.success);
        report.appendChild(el('div', { className: 'ip-report-head' }, [el('i', { className: 'bi ' + (j.success ? 'bi-check-circle-fill text-success' : 'bi-x-circle text-danger') }), ' ',
            j.success ? (j.message || '') : (j.error || t('js.iconpack.failed'))]));
        const dl = el('dl', { className: 'ip-report-list' });
        const add = (k, v) => { if (v === null || v === undefined || v === '') return; dl.appendChild(el('dt', {}, k)); dl.appendChild(el('dd', {}, v)); };
        if (r.root !== null && r.root !== undefined) add(t('js.iconpack.rep_root'), r.root === '' ? t('js.iconpack.rep_root_top') : r.root);
        if (r.ok) {
            add(t('js.iconpack.rep_package'), 'Font Awesome ' + (r.edition === 'pro' ? 'Pro' : 'Free') + ' ' + r.version + ' — ' + t('js.iconpack.n_styles', { n: r.styles }));
            const kinds = {};
            (r.kept || []).forEach((k) => { const d = String(k.path).split('/')[0]; kinds[d] = (kinds[d] || 0) + 1; });
            add(t('js.iconpack.rep_kept'), t('js.iconpack.rep_kept_n', { n: (r.kept || []).length, size: fmtBytes(r.bytes) }) + ' (' + Object.entries(kinds).map(([d, n]) => d + ' ' + n).join(', ') + ')');
        }
        const sk = Object.entries(r.skipped || {});
        if (sk.length) add(t('js.iconpack.rep_skipped'), sk.map(([k, n]) => k.replace(/^outside:/, t('js.iconpack.rep_outside') + ' ').replace(/^too_large:/, t('js.iconpack.rep_too_large') + ' ') + ' ' + n).join(', '));
        (r.skipped_notes || []).forEach((n) => add(t('js.iconpack.rep_note'), n.path + ' — ' + t('js.iconpack.note_' + n.why)));
        (r.refused || []).forEach((x) => add(t('js.iconpack.rep_refused'), x.path + (x.detail && x.detail !== x.path ? ' — ' + x.detail : '')));
        report.appendChild(dl);
    }

    async function install(kind) {
        if (busy) return;
        let body = null;
        if (kind === 'upload') {
            const f = chosen();
            if (!f) { showToast(t('js.iconpack.pick_file'), 'warning'); drop.focus(); return; }
            const lim = state && state.limits ? Number(state.limits.upload) : 0;
            if (lim > 0 && f.size > lim) { showToast(t('js.iconpack.upload_too_large', { max: fmtBytes(lim) }), 'danger'); return; }
            body = f;
        } else if (!pathIn.value.trim()) { showToast(t('js.iconpack.pick_path'), 'warning'); pathIn.focus(); return; }
        const pw = await promptPassword(t('js.iconpack.install_title'), t('js.iconpack.password_why'));
        if (pw === null) return;
        setBusy(true);
        let j;
        try {
            if (kind === 'upload') {
                // multipart, not base64 in JSON: a Pro zip is tens of megabytes, and PHP streams a
                // multipart file to disk instead of holding it (and a copy a third larger) in memory.
                const fd = new FormData();
                fd.append('op', 'upload');
                fd.append('confirm_password', pw);
                fd.append('file', body, body.name);
                const res = await fetch((document.body.dataset.apiBase || '') + 'admin/iconpacks', {
                    method: 'POST', headers: { 'X-CSRF-Token': document.body.dataset.csrf || '', 'Accept': 'application/json' }, body: fd,
                });
                try { j = await res.json(); } catch (e) { j = { error: t('js.iconpack.upload_too_large', { max: fmtBytes(state.limits.upload) }) }; }
            } else {
                j = await apiCall('admin/iconpacks', 'POST', { op: 'install_path', path: pathIn.value.trim(), confirm_password: pw });
            }
        } catch (e) { j = { error: t('js.iconpack.failed') }; }
        setBusy(false);
        if (j && j.signed_out) { showToast(j.error, 'danger'); setTimeout(() => location.reload(), 1500); return; }
        if (j && j.packages) { state = j; renderList(); syncPackSelect(); refreshSoon(); }
        if (j && (j.report || j.success !== undefined)) paintReport(j);
        if (!j || !j.success) { showToast((j && j.error) || t('js.iconpack.failed'), 'danger'); return; }
        showToast(j.message || 'OK', 'success');
        if (kind === 'upload') { fileIn.value = ''; dropped = null; markFile(null); }
    }
    upBtn.addEventListener('click', () => install('upload'));
    pathBtn.addEventListener('click', () => install('path'));
    // The box lives inside the settings <form>: Enter would save the whole page instead.
    pathIn.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); install('path'); } });

    [sourceSel, packSel, styleSel].forEach((s) => s.addEventListener('change', () => {
        // A different package: its own ticks, which start empty.
        if (s === packSel) setTicks([]);
        refreshSoon();
    }));
    if (libSel) libSel.addEventListener('change', paintNote);

    // Built by this script, so the in-place language switch cannot reach it: redraw from the last answers.
    document.addEventListener('langswap', () => {
        if (state) renderList();
        if (preview) { paintStyles(preview.setup); paintCoverage(preview.setup.coverage, null); paintNote(); }
        markFile(chosen());
    });

    async function load() {
        let j;
        try { j = await apiCall('admin/iconpacks'); } catch (e) { list.textContent = t('js.iconpack.failed'); return; }
        if (!j.success) { list.textContent = j.error || t('js.iconpack.failed'); return; }
        state = j;
        renderList();
        refresh();
    }
    load();
})();
