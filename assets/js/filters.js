(function () {
    'use strict';
    if (window.SearchFilters) return;

    const RANGES = [
        { key: 'size', label: 'size', group: 'release', unit: true },
        { key: 'filecount', label: 'files', group: 'release' },
        { key: 'year', label: 'year', group: 'release', facts: true, year: true },
        { key: 'seeders', label: 'seeders', group: 'popularity', minOnly: true },
        { key: 'imdb', label: 'imdb', group: 'ratings', facts: true, desc: true, minOnly: true, rating: true },
        { key: 'filmweb', label: 'filmweb', group: 'ratings', facts: true, desc: true, minOnly: true, rating: true },
    ];
    const GROUPS = [['q', 'quality'], ['v', 'quality'], ['h', 'quality'], ['a', 'quality'], ['s', 'quality'], ['l', 'language']];
    const PARAMS = ['type', 'tags', 'pics'].concat(RANGES.map(r => r.key));
    const OPEN_KEY = 'thx-search-filters-open';

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function icon(name) {
        const i = el('i', 'bi ' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function store(key, get, value) {
        try {
            if (get) return localStorage.getItem(key) === '1';
            localStorage.setItem(key, value ? '1' : '0');
        } catch (e) { return false; }
        return false;
    }
    function validValue(r, v) {
        if (v === '') return true;
        if (r.unit) return /^\d+(?:[.,]\d+)?\s*(?:[kmgt](?:i?b)?|b)?$/i.test(v);
        if (r.year) return /^\d{4}$/.test(v);
        if (r.rating) return /^\d{1,2}(?:[.,]\d)?$/.test(v) && parseFloat(v.replace(',', '.')) <= 10;
        return /^\d{1,9}$/.test(v);
    }
    function splitRange(raw) {
        const s = String(raw || '');
        const i = s.indexOf('..');
        return i < 0 ? [s.trim(), s.trim()] : [s.slice(0, i).trim(), s.slice(i + 2).trim()];
    }

    function mount(host, opts) {
        const panelUi = opts.ui === 'panel';
        const openKey = panelUi ? OPEN_KEY + '-' + (host.id || 'panel') : OPEN_KEY;
        const btnCls = panelUi ? 'btn btn-sm btn-outline-secondary' : 'btn btn-secondary btn-small';
        const S = { type: '', pics: false, sel: new Set(), ranges: {}, open: store(openKey, true) };
        const catalog = Array.isArray(opts.catalog) ? opts.catalog : [];
        const labelOf = new Map(catalog.map(c => [c.id, c.l]));
        const facts = !!opts.facts;
        const desc = !!opts.desc;
        let timer = 0;

        const bar = el('div', 'sf-bar');
        const clear = el('button', btnCls + ' sf-clear');
        clear.type = 'button';
        clear.append(icon('bi-x-circle'), ' ', t.key('js.filters.clear'));
        bar.appendChild(clear);
        const seg = el('div', 'sf-type');
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', t.key('js.filters.type'));
        const segBtns = [['', 'js.filters.all'], ['movie', 'js.filters.movies'], ['series', 'js.filters.series']].map(([v, key]) => {
            const b = el('button', 'sf-type-btn', t.key(key));
            b.type = 'button';
            b.dataset.v = v;
            b.addEventListener('click', () => { S.type = v; paint(); changed(true); });
            seg.appendChild(b);
            return b;
        });
        if (facts) bar.appendChild(seg);
        const more = el('button', btnCls + ' sf-more');
        more.type = 'button';
        more.setAttribute('aria-expanded', 'false');
        bar.appendChild(more);
        const active = el('div', 'sf-active');
        bar.appendChild(active);
        const right = el('div', 'sf-right');
        bar.appendChild(right);
        (opts.moveRight || []).forEach(n => { if (n) right.appendChild(n); });

        const panel = el('div', 'sf-panel');
        const inner = el('div', 'sf-panel-inner');
        panel.appendChild(inner);
        const grid = el('div', 'sf-grid');
        inner.appendChild(grid);
        const sections = {};
        const section = (key) => {
            if (sections[key]) return sections[key];
            const f = el('fieldset', 'sf-group');
            f.appendChild(el('legend', null, t.key('js.filters.g_' + key)));
            grid.appendChild(f);
            return (sections[key] = f);
        };
        (facts ? ['release', 'quality', 'language', 'ratings', 'popularity', 'description'] : ['release', 'popularity']).forEach(section);
        const inputs = {};
        RANGES.forEach(r => {
            if ((r.facts && !facts) || (r.desc && !desc)) return;
            const box = el('div', 'sf-range');
            const id = 'sf-' + r.key;
            const lab = el('label', 'sf-label', t.key('js.filters.' + r.label));
            lab.htmlFor = id + '-min';
            box.appendChild(lab);
            const row = el('div', 'sf-range-row');
            const mk = (end) => {
                const i = el('input', panelUi ? 'form-control form-control-sm bg-dark text-light border-secondary sf-input' : 'profile-search sf-input');
                i.type = 'text';
                i.id = id + '-' + end;
                i.inputMode = r.unit ? 'text' : 'decimal';
                i.placeholder = t.key(end === 'min' ? 'js.filters.min' : 'js.filters.max');
                i.setAttribute('aria-label', t.key('js.filters.input_label', { name: t.key('js.filters.' + r.label), end: t.key(end === 'min' ? 'js.filters.min' : 'js.filters.max') }));
                i.autocomplete = 'off';
                i.addEventListener('input', () => {
                    const ok = validValue(r, i.value.trim());
                    i.classList.toggle('sf-bad', !ok);
                    i.setAttribute('aria-invalid', ok ? 'false' : 'true');
                    if (!ok) return;
                    clearTimeout(timer);
                    timer = setTimeout(() => changed(false), 500);
                });
                i.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    if (!i.classList.contains('sf-bad')) { clearTimeout(timer); changed(false); }
                });
                row.appendChild(i);
                return i;
            };
            const min = mk('min');
            let max = null;
            if (!r.minOnly) {
                row.appendChild(el('span', 'sf-dash', '–'));
                max = mk('max');
            }
            box.appendChild(row);
            if (r.unit) box.appendChild(el('div', 'sf-hint text-muted', t.key('js.filters.size_hint')));
            section(r.group).appendChild(box);
            inputs[r.key] = { min, max, r };
        });
        const tagBtns = new Map();
        if (facts) {
            GROUPS.forEach(([g, sec]) => {
                const tags = catalog.filter(c => c.g === g);
                if (!tags.length) return;
                const wrap = el('div', 'sf-tags');
                wrap.appendChild(el('div', 'sf-label', t.key('js.filters.t_' + g)));
                const list = el('div', 'sf-tag-list');
                tags.forEach(c => {
                    const b = el('button', 'fchip fchip-' + g + ' sf-tag', c.l);
                    b.type = 'button';
                    b.setAttribute('aria-pressed', 'false');
                    b.addEventListener('click', () => { toggle(c.id); changed(true); });
                    list.appendChild(b);
                    tagBtns.set(c.id, b);
                });
                wrap.appendChild(list);
                section(sec).appendChild(wrap);
            });
        }
        if (facts && desc) {
            const pics = el('label', panelUi ? 'form-check sf-check' : 'search-check sf-check');
            const pin = el('input', panelUi ? 'form-check-input' : null);
            pin.type = 'checkbox';
            pin.addEventListener('change', () => { S.pics = pin.checked; changed(true); });
            if (panelUi) {
                pics.append(pin, el('span', 'form-check-label', t.key('js.filters.pics')));
            } else {
                pics.append(pin, el('span', 'search-check-box'), ' ', t.key('js.filters.pics'));
                pics.querySelector('.search-check-box').setAttribute('aria-hidden', 'true');
            }
            section('description').appendChild(pics);
            S.picsInput = pin;
        }

        Object.keys(sections).forEach(k => { if (sections[k].children.length <= 1) sections[k].remove(); });
        host.textContent = '';
        host.append(bar, panel);

        function toggle(id) {
            if (S.sel.has(id)) S.sel.delete(id); else S.sel.add(id);
        }
        function readRanges() {
            const out = {};
            Object.keys(inputs).forEach(k => {
                const { min, max } = inputs[k];
                const a = min.value.trim(), b = max ? max.value.trim() : '';
                if (min.classList.contains('sf-bad') || (max && max.classList.contains('sf-bad'))) return;
                if (a === '' && b === '') return;
                out[k] = a + '..' + b;
            });
            return out;
        }
        function count() {
            return Object.keys(readRanges()).length + S.sel.size + (S.pics ? 1 : 0);
        }
        function params() {
            const p = {};
            if (S.type) p.type = S.type;
            if (S.pics) p.pics = '1';
            const byGroup = {};
            [...S.sel].forEach(id => {
                const g = id.charAt(0);
                if (g === 'g') { (byGroup[id] = byGroup[id] || []).push(id); return; }
                (byGroup[g] = byGroup[g] || []).push(id);
            });
            const sets = Object.keys(byGroup).map(k => byGroup[k].join('|'));
            if (sets.length) p.tags = sets.join(',');
            Object.assign(p, readRanges());
            return p;
        }
        function chip(label, onRemove) {
            const c = el('span', 'sf-chip');
            c.appendChild(el('span', null, label));
            const x = el('button', 'sf-chip-x');
            x.type = 'button';
            x.setAttribute('aria-label', t.key('js.filters.remove', { name: label }));
            x.appendChild(icon('bi-x'));
            x.addEventListener('click', () => { onRemove(); paint(); changed(true); });
            c.appendChild(x);
            return c;
        }
        function paint() {
            const n = count() ;
            more.textContent = '';
            more.append(icon('bi-sliders'), ' ', t.key('js.filters.more', { n: n }), ' ', icon(S.open ? 'bi-chevron-up' : 'bi-chevron-down'));
            more.setAttribute('aria-expanded', S.open ? 'true' : 'false');
            panel.classList.toggle('sf-open', S.open);
            if (S.open) panel.removeAttribute('inert'); else panel.setAttribute('inert', '');
            segBtns.forEach(b => {
                const on = b.dataset.v === S.type;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            tagBtns.forEach((b, id) => {
                const on = S.sel.has(id);
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (S.picsInput) S.picsInput.checked = S.pics;
            clear.hidden = !(n || S.type);
            active.textContent = '';
            [...S.sel].forEach(id => active.appendChild(chip(labelOf.get(id) || id, () => S.sel.delete(id))));
            const rg = readRanges();
            Object.keys(rg).forEach(k => {
                const [a, b] = splitRange(rg[k]);
                const label = t.key(a && b ? 'js.filters.chip_range' : a ? 'js.filters.chip_min' : 'js.filters.chip_max', { name: t.key('js.filters.c_' + inputs[k].r.label), a: a, b: b });
                active.appendChild(chip(label, () => { inputs[k].min.value = ''; if (inputs[k].max) inputs[k].max.value = ''; }));
            });
            if (S.pics) active.appendChild(chip(t.key('js.filters.pics'), () => { S.pics = false; }));
        }
        function changed(now) {
            paint();
            if (typeof opts.onChange === 'function') opts.onChange();
        }
        clear.addEventListener('click', () => {
            S.type = ''; S.pics = false; S.sel.clear();
            Object.keys(inputs).forEach(k => { inputs[k].min.value = ''; if (inputs[k].max) inputs[k].max.value = ''; inputs[k].min.classList.remove('sf-bad'); if (inputs[k].max) inputs[k].max.classList.remove('sf-bad'); });
            changed(true);
        });
        more.addEventListener('click', () => { S.open = !S.open; store(openKey, false, S.open); paint(); });
        paint();

        return {
            params,
            count,
            keys: PARAMS,
            addTag(id) {
                if (!id || S.sel.has(id)) return false;
                S.sel.add(id);
                changed(true);
                return true;
            },
            fromParams(p) {
                S.type = ['movie', 'series'].includes(p.get('type')) ? p.get('type') : '';
                S.pics = p.get('pics') === '1';
                S.sel.clear();
                String(p.get('tags') || '').split(',').forEach(set => set.split('|').forEach(id => {
                    id = id.trim();
                    if (/^[qvhaslt]:[a-z0-9_]{1,20}$/.test(id) || /^g:[a-z0-9-]{2,40}$/.test(id)) S.sel.add(id);
                }));
                Object.keys(inputs).forEach(k => {
                    const raw = p.get(k);
                    const [a, b] = raw ? splitRange(raw) : ['', ''];
                    inputs[k].min.value = validValue(inputs[k].r, a) ? a : '';
                    if (inputs[k].max) inputs[k].max.value = validValue(inputs[k].r, b) ? b : '';
                });
                paint();
            },
        };
    }

    window.SearchFilters = { mount, PARAMS };

    function factChip(cls, text, title) {
        const c = el('span', cls, text);
        if (title) c.title = title;
        return c;
    }
    function fmtRate(k) {
        return k >= 1000 ? t.num(Math.round(k / 100) / 10) + ' Mb/s' : t.num(k) + ' kb/s';
    }
    window.FactChips = {
        render(f, onPick) {
            if (!f || (!f.tags && !f.year && !f.vbitrate && !f.imdb && !f.filmweb)) return null;
            const box = el('div', 'fchips');
            const tags = Array.isArray(f.tags) ? f.tags : [];
            const shown = tags.slice(0, 6);
            const rest = tags.slice(6);
            const add = (tg) => {
                const b = el(onPick ? 'button' : 'span', 'fchip fchip-' + tg.g, tg.l);
                if (onPick) {
                    b.type = 'button';
                    b.title = t.key('js.filters.add', { name: tg.l });
                    b.addEventListener('click', (e) => { e.stopPropagation(); onPick(tg.id); });
                }
                box.appendChild(b);
            };
            shown.forEach(add);
            if (rest.length) {
                const m = el('button', 'fchip fchip-more', '+' + rest.length);
                m.type = 'button';
                m.title = t.key('js.filters.show_more', { n: rest.length });
                m.addEventListener('click', (e) => { e.stopPropagation(); m.remove(); rest.forEach(add); });
                box.appendChild(m);
            }
            if (f.year) box.appendChild(factChip('fchip fchip-fact', f.year, t.key('js.filters.year')));
            if (f.vbitrate) box.appendChild(factChip('fchip fchip-fact', fmtRate(f.vbitrate), t.key('js.filters.bitrate')));
            if (f.imdb) box.appendChild(factChip('fchip fchip-fact', 'IMDb ' + f.imdb, 'IMDb'));
            if (f.filmweb) box.appendChild(factChip('fchip fchip-fact', 'Filmweb ' + f.filmweb, 'Filmweb'));
            return box;
        },
    };
})();
