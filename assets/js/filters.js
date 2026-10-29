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
        { key: 'vbitrate', label: 'vbitrate', group: 'quality', facts: true, desc: true, minOnly: true, mbps: true },
        { key: 'duration', label: 'duration', group: 'release', facts: true, desc: true },
        { key: 'seasons', label: 'seasons', group: 'release', facts: true, desc: true },
        { key: 'episodes', label: 'episodes', group: 'release', facts: true, desc: true },
        { key: 'rating', label: 'rating', group: 'ratings', rep: true, minOnly: true, pct: true },
        { key: 'votes', label: 'votes', group: 'ratings', rep: true, minOnly: true },
        { key: 'steam', label: 'steam', group: 'ratings', rep: true, minOnly: true, pct: true },
        { key: 'seen', label: 'seen', group: 'dates', date: true },
    ];
    const KINDS = ['movie', 'series', 'game', 'music', 'software', 'book', 'audiobook', 'adult'];
    const KIND_KEY = { movie: 'js.filters.movies', series: 'js.filters.series' };
    const GROUPS = [['x', 'tags'], ['q', 'quality'], ['v', 'quality'], ['h', 'quality'], ['a', 'quality'], ['s', 'quality'], ['l', 'language'], ['g', 'genre']];
    const PARAMS = ['type', 'tags', 'notags', 'pics', 'wl'].concat(RANGES.map(r => r.key));
    const TAG_ID = (id) => /^[qvhaslt]:[a-z0-9_]{1,20}$/.test(id) || /^g:[a-z0-9-]{2,40}$/.test(id) || /^x:[a-z0-9-]{1,64}$/.test(id);
    const OPEN_KEY = 'thx-search-filters-open';
    const TAG_PAGE = 18;
    const GRID_ROW = 4;
    const still = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

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
        if (r.date) return /^\d{4}-\d{2}-\d{2}$/.test(v);
        if (r.pct) return /^\d{1,3}$/.test(v) && parseInt(v, 10) <= 100;
        if (r.rating) return /^\d{1,2}(?:[.,]\d)?$/.test(v) && parseFloat(v.replace(',', '.')) <= 10;
        if (r.mbps) return /^\d{1,3}(?:[.,]\d{1,2})?$/.test(v);
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
        const S = { type: '', pics: false, wl: false, sel: new Set(), not: new Set(), ranges: {}, open: store(openKey, true) };
        const catalog = Array.isArray(opts.catalog) ? opts.catalog : [];
        const favs = Array.isArray(opts.favs) ? opts.favs : [];
        const labelOf = new Map(catalog.map(c => [c.id, c.l]));
        favs.forEach(c => { if (!labelOf.has(c.id)) labelOf.set(c.id, c.l); });
        const facts = !!opts.facts;
        const desc = !!opts.desc;
        const rep = !!opts.rep;
        const wlOpt = !!opts.wl;
        let timer = 0;

        const bar = el('div', 'sf-bar');
        const clear = el('button', btnCls + ' sf-clear');
        clear.type = 'button';
        clear.append(icon('bi-x-circle'), ' ', t.key('js.filters.clear'));
        bar.appendChild(clear);
        const seg = el('div', 'sf-type');
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', t.key('js.filters.type'));
        let kinds = [];
        try { kinds = JSON.parse(host.dataset.kinds || '[]').filter(k => KINDS.includes(k)); } catch (e) { kinds = []; }
        if (!kinds.length) kinds = ['movie', 'series'];
        const segBtns = [['', 'js.filters.all']].concat(kinds.map(k => [k, KIND_KEY[k] || 'js.filters.kind_' + k])).map(([v, key]) => {
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
        let tagSearch = null;
        let tagNone = null;
        if (facts && catalog.length) {
            const box = el('div', 'sf-tagsearch');
            const lab = el('label', 'sf-label', t.key('js.filters.tag_search'));
            tagSearch = el('input', panelUi ? 'form-control form-control-sm bg-dark text-light border-secondary sf-input' : 'profile-search sf-input');
            tagSearch.type = 'search';
            tagSearch.id = 'sf-tagsearch-' + (host.id || 'list');
            tagSearch.placeholder = t.key('js.filters.tag_search_ph');
            tagSearch.autocomplete = 'off';
            lab.htmlFor = tagSearch.id;
            tagNone = el('div', 'sf-hint text-muted', t.key('js.filters.tag_none'));
            tagNone.hidden = true;
            box.append(lab, tagSearch, tagNone, el('div', 'sf-hint text-muted', t.key('js.filters.tags_hint')));
            if (!panelUi) {
                const all = el('a', 'sf-alltags', t.key('js.filters.all_tags'));
                all.href = '?action=tags';
                box.appendChild(all);
            }
            inner.insertBefore(box, grid);
            tagSearch.addEventListener('input', () => flip(() => showTags()));
        }
        (facts ? ['tags', 'release', 'quality', 'language', 'genre', 'ratings', 'popularity', 'dates', 'description'] : ['release', 'ratings', 'popularity', 'dates']).concat(wlOpt ? ['whitelist'] : []).forEach(section);
        const inputs = {};
        RANGES.forEach(r => {
            if ((r.facts && !facts) || (r.desc && !desc) || (r.rep && !rep)) return;
            const box = el('div', 'sf-range' + (r.date ? ' sf-range-date' : ''));
            const id = 'sf-' + r.key;
            const lab = el('label', 'sf-label', t.key('js.filters.' + r.label));
            lab.htmlFor = id + '-min';
            box.appendChild(lab);
            const row = el('div', 'sf-range-row');
            const mk = (end) => {
                const i = el('input', panelUi ? 'form-control form-control-sm bg-dark text-light border-secondary sf-input' : 'profile-search sf-input');
                i.type = r.date ? 'date' : 'text';
                i.id = id + '-' + end;
                i.inputMode = r.unit ? 'text' : 'decimal';
                if (r.mbps) box.classList.add('sf-mbps');
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
        const favBtns = new Map();
        const tagButton = (c, g) => {
            const b = el('button', 'fchip fchip-' + g + ' sf-tag', c.l);
            b.type = 'button';
            b.setAttribute('aria-pressed', 'false');
            if (c.c) b.style.setProperty('--fc', c.c);
            b.dataset.s = [c.l, c.a || '', c.id].join(' ').toLowerCase();
            b.dataset.id = c.id;
            b.addEventListener('click', () => { toggle(c.id); changed(true); });
            return b;
        };
        if (facts && favs.length) {
            const wrap = el('div', 'sf-tags sf-mine');
            wrap.appendChild(el('div', 'sf-label', t.key('js.filters.t_mine')));
            const list = el('div', 'sf-tag-list');
            favs.forEach(c => {
                const b = tagButton(c, c.g || 'x');
                list.appendChild(b);
                favBtns.set(c.id, b);
            });
            wrap.appendChild(list);
            section('tags').appendChild(wrap);
        }
        const popBtns = new Map();
        const block = (label, tags, sec, into) => {
            const wrap = el('div', 'sf-tags');
            wrap.appendChild(el('div', 'sf-label', label));
            const list = el('div', 'sf-tag-list');
            tags.forEach(c => {
                const b = el('button', 'fchip fchip-' + c.g + ' sf-tag', c.l);
                b.type = 'button';
                b.setAttribute('aria-pressed', 'false');
                if (c.c) b.style.setProperty('--fc', c.c);
                b.dataset.s = [c.l, c.a || '', c.id].join(' ').toLowerCase();
                b.dataset.id = c.id;
                if (typeof c.n === 'number') {
                    b.dataset.n = String(c.n);
                    b.appendChild(el('span', 'sf-n', t.num(c.n)));
                    b.title = t.key('js.filters.count', { n: t.num(c.n) });
                }
                b.addEventListener('click', () => { toggle(c.id); changed(true); });
                list.appendChild(b);
                into.set(c.id, b);
            });
            wrap.appendChild(list);
            if (tags.length > TAG_PAGE) {
                wrap.dataset.lim = String(TAG_PAGE);
                const moreTagsBtn = el('button', 'sf-more-tags');
                moreTagsBtn.type = 'button';
                moreTagsBtn.addEventListener('click', () => moreTags(wrap));
                wrap.appendChild(moreTagsBtn);
            }
            section(sec).appendChild(wrap);
            return wrap;
        };
        if (facts) {
            const popMax = Number(host.dataset.popular) || 0;
            const pop = popMax > 0 ? catalog.filter(c => typeof c.n === 'number' && c.n > 0 && c.g !== 't' && (c.g !== 'g' || desc)).sort((a, b) => b.n - a.n).slice(0, popMax) : [];
            if (pop.length) block(t.key('js.filters.t_popular'), pop, 'tags', popBtns).classList.add('sf-popular');
            GROUPS.forEach(([g, sec]) => {
                if (g === 'g' && !desc) return;
                if (g !== 'x') {
                    const tags = catalog.filter(c => c.g === g || c.kg === g);
                    if (tags.length) block(t.key('js.filters.t_' + g), tags, sec, tagBtns);
                    return;
                }
                const own = new Map();
                catalog.filter(c => c.g === 'x' && (!c.kg || (c.kg === 'g' && !desc))).forEach(c => {
                    const k = c.k || '';
                    if (!own.has(k)) own.set(k, { o: k ? (c.ko || 0) : 200000, label: k ? c.kl : t.key('js.filters.t_x'), tags: [] });
                    own.get(k).tags.push(c);
                });
                [...own.values()].sort((a, b) => a.o - b.o).forEach(x => block(x.label, x.tags, sec, tagBtns));
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
        if (wlOpt) {
            const wlBox = el('label', panelUi ? 'form-check sf-check' : 'search-check sf-check');
            const win = el('input', panelUi ? 'form-check-input' : null);
            win.type = 'checkbox';
            win.addEventListener('change', () => { S.wl = win.checked; changed(true); });
            if (panelUi) {
                wlBox.append(win, el('span', 'form-check-label', t.key('js.filters.wl_only')));
            } else {
                wlBox.append(win, el('span', 'search-check-box'), ' ', t.key('js.filters.wl_only'));
                wlBox.querySelector('.search-check-box').setAttribute('aria-hidden', 'true');
            }
            section('whitelist').append(wlBox, el('div', 'sf-hint text-muted', t.key('js.filters.wl_only_hint')));
            S.wlInput = win;
        }

        Object.keys(sections).forEach(k => { if (sections[k].children.length <= 1) sections[k].remove(); });
        host.textContent = '';
        host.append(bar, panel);
        let relayout = 0;
        const queueLayout = () => {
            if (relayout) return;
            relayout = requestAnimationFrame(() => { relayout = 0; layout(); });
        };
        if ('ResizeObserver' in window) {
            const ro = new ResizeObserver(queueLayout);
            ro.observe(grid);
            [...grid.children].forEach(f => ro.observe(f));
        }
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(queueLayout);

        function toggle(id) {
            if (S.sel.has(id)) { S.sel.delete(id); S.not.add(id); }
            else if (S.not.has(id)) S.not.delete(id);
            else S.sel.add(id);
        }
        function showTags() {
            const q = tagSearch ? tagSearch.value.trim().toLowerCase() : '';
            let any = false;
            tagBtns.forEach((b, id) => {
                const on = S.sel.has(id) || S.not.has(id);
                const hit = !q || b.dataset.s.includes(q);
                b.hidden = !on && (!hit || b.dataset.n === '0');
                if (!b.hidden && hit) any = true;
            });
            popBtns.forEach(b => { b.hidden = q !== ''; });
            grid.querySelectorAll('.sf-tags').forEach(w => {
                const moreBtn = w.querySelector(':scope > .sf-more-tags');
                if (moreBtn) {
                    const lim = Number(w.dataset.lim) || TAG_PAGE;
                    const shown = Number(w.dataset.shown) || lim;
                    let k = 0;
                    if (!q) {
                        w.querySelectorAll('.sf-tag').forEach(b => {
                            if (b.hidden || S.sel.has(b.dataset.id) || S.not.has(b.dataset.id)) return;
                            k += 1;
                            if (k > shown) b.hidden = true;
                        });
                    }
                    const rest = Math.max(0, k - shown);
                    w.dataset.rest = String(rest);
                    moreBtn.hidden = q !== '' || k <= lim;
                    moreBtn.replaceChildren(icon(rest ? 'bi-chevron-down' : 'bi-chevron-up'), ' ', t.key(rest ? 'js.filters.more_tags' : 'js.filters.less_tags', { n: t.num(rest) }));
                }
                w.hidden = ![...w.querySelectorAll('.sf-tag')].some(b => !b.hidden);
            });
            grid.querySelectorAll('.sf-range, .sf-check').forEach(x => { x.hidden = q !== ''; });
            grid.querySelectorAll('.sf-group').forEach(f => { f.hidden = ![...f.children].some(x => x.tagName !== 'LEGEND' && !x.hidden); });
            if (tagNone) tagNone.hidden = !q || any;
            layout();
        }
        function moreTags(wrap) {
            const lim = Number(wrap.dataset.lim) || TAG_PAGE;
            const shown = Number(wrap.dataset.shown) || lim;
            const rest = Number(wrap.dataset.rest) || 0;
            const before = new Set([...wrap.querySelectorAll('.sf-tag')].filter(b => !b.hidden));
            wrap.dataset.shown = String(rest > 0 ? shown + lim : lim);
            flip(() => showTags());
            wrap.querySelectorAll('.sf-tag').forEach(b => {
                if (b.hidden || before.has(b)) return;
                b.classList.remove('sf-in');
                void b.offsetWidth;
                b.classList.add('sf-in');
            });
        }
        function layout() {
            const cs = getComputedStyle(grid);
            const cols = Math.max(1, cs.gridTemplateColumns.split(' ').filter(v => parseFloat(v) > 0).length);
            const gap = parseFloat(cs.columnGap) || 16;
            const groups = [...grid.children].filter(f => !f.hidden);
            groups.forEach(f => {
                const n = f.querySelectorAll('.sf-tag:not([hidden])').length;
                const want = n > 40 ? 3 : (n > 16 ? 2 : 1);
                f.style.gridColumn = 'span ' + Math.min(want, cols);
            });
            groups.forEach(f => {
                f.style.gridRowEnd = 'span ' + Math.max(1, Math.ceil((f.getBoundingClientRect().height + gap * 0.7) / GRID_ROW));
            });
        }
        function flip(change) {
            const before = new Map([...grid.children].filter(f => !f.hidden).map(f => [f, f.getBoundingClientRect()]));
            change();
            if (still() || !S.open || typeof Element.prototype.animate !== 'function') return;
            [...grid.children].forEach(f => {
                if (f.hidden) return;
                const a = before.get(f);
                const b = f.getBoundingClientRect();
                if (!a) {
                    f.animate([{ opacity: 0, transform: 'scale(0.98)' }, { opacity: 1, transform: 'none' }], { duration: 240, easing: 'ease-out' });
                    return;
                }
                const dx = a.left - b.left, dy = a.top - b.top;
                if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
                f.animate([{ transform: 'translate(' + dx + 'px, ' + dy + 'px)' }, { transform: 'none' }], { duration: 320, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)' });
            });
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
            return Object.keys(readRanges()).length + S.sel.size + S.not.size + (S.pics ? 1 : 0) + (S.wl ? 1 : 0);
        }
        function params() {
            const p = {};
            if (S.type) p.type = S.type;
            if (S.pics) p.pics = '1';
            if (S.wl) p.wl = '1';
            const byGroup = {};
            [...S.sel].forEach(id => {
                const g = id.charAt(0);
                if (g === 'g') { (byGroup[id] = byGroup[id] || []).push(id); return; }
                (byGroup[g] = byGroup[g] || []).push(id);
            });
            const sets = Object.keys(byGroup).map(k => byGroup[k].join('|'));
            if (sets.length) p.tags = sets.join(',');
            if (S.not.size) p.notags = [...S.not].join(',');
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
            const paintTag = (b, id) => {
                const on = S.sel.has(id);
                const not = S.not.has(id);
                b.classList.toggle('is-on', on);
                b.classList.toggle('is-not', not);
                b.setAttribute('aria-pressed', on ? 'true' : (not ? 'mixed' : 'false'));
                b.title = not ? t.key('js.filters.tag_not_title', { name: labelOf.get(id) || id }) : (b.dataset.n ? t.key('js.filters.count', { n: t.num(Number(b.dataset.n)) }) : '');
            };
            tagBtns.forEach(paintTag);
            favBtns.forEach(paintTag);
            popBtns.forEach(paintTag);
            if (S.picsInput) S.picsInput.checked = S.pics;
            if (S.wlInput) S.wlInput.checked = S.wl;
            clear.hidden = !(n || S.type);
            active.textContent = '';
            [...S.sel].forEach(id => active.appendChild(chip(labelOf.get(id) || id, () => S.sel.delete(id))));
            [...S.not].forEach(id => active.appendChild(chip(t.key('js.filters.chip_not', { name: labelOf.get(id) || id }), () => S.not.delete(id))));
            const rg = readRanges();
            Object.keys(rg).forEach(k => {
                const [a, b] = splitRange(rg[k]);
                const label = t.key(a && b ? 'js.filters.chip_range' : a ? 'js.filters.chip_min' : 'js.filters.chip_max', { name: t.key('js.filters.c_' + inputs[k].r.label), a: a, b: b });
                active.appendChild(chip(label, () => { inputs[k].min.value = ''; if (inputs[k].max) inputs[k].max.value = ''; }));
            });
            if (S.pics) active.appendChild(chip(t.key('js.filters.pics'), () => { S.pics = false; }));
            if (S.wl) active.appendChild(chip(t.key('js.filters.wl_only'), () => { S.wl = false; }));
            showTags();
        }
        function changed(now) {
            paint();
            if (typeof opts.onChange === 'function') opts.onChange();
        }
        clear.addEventListener('click', () => {
            S.type = ''; S.pics = false; S.wl = false; S.sel.clear(); S.not.clear();
            Object.keys(inputs).forEach(k => { inputs[k].min.value = ''; if (inputs[k].max) inputs[k].max.value = ''; inputs[k].min.classList.remove('sf-bad'); if (inputs[k].max) inputs[k].max.classList.remove('sf-bad'); });
            changed(true);
        });
        let slid = null;
        more.addEventListener('click', () => {
            const from = panel.getBoundingClientRect().height;
            if (slid) slid.cancel();
            S.open = !S.open;
            store(openKey, false, S.open);
            paint();
            if (still() || typeof panel.animate !== 'function') return;
            panel.classList.add('sf-moving');
            const a = panel.animate([{ height: from + 'px' }, { height: (S.open ? inner.scrollHeight : 0) + 'px' }], { duration: 200, easing: 'ease' });
            slid = a;
            a.onfinish = a.oncancel = () => { if (slid !== a) return; slid = null; panel.classList.remove('sf-moving'); };
        });
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
                S.type = kinds.includes(p.get('type')) ? p.get('type') : '';
                S.pics = p.get('pics') === '1';
                S.wl = wlOpt && p.get('wl') === '1';
                S.sel.clear();
                S.not.clear();
                String(p.get('tags') || '').split(',').forEach(set => set.split('|').forEach(id => {
                    id = id.trim();
                    if (TAG_ID(id)) S.sel.add(id);
                }));
                String(p.get('notags') || '').split(',').forEach(set => set.split('|').forEach(id => {
                    id = id.trim();
                    if (TAG_ID(id) && !S.sel.has(id)) S.not.add(id);
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
    function rateText(k, r) {
        const kind = k === 'a' && (r.c || r.ch) ? t.key('js.filters.k_audio_x', { codec: [r.c, r.ch].filter(Boolean).join(' ') })
            : t.key(k === 'o' ? 'js.filters.k_overall' : (k === 'v' ? 'js.filters.k_video' : 'js.filters.k_audio'));
        const mode = r.m === 'V' ? 'VBR' : (r.m === 'C' ? 'CBR' : '');
        if (!r.r) return t.key('js.filters.chip_rate_only_max', { kind: kind, max: fmtRate(r.x) });
        const withMax = r.x && r.x !== r.r;
        const p = { kind: kind, avg: fmtRate(r.r), max: withMax ? fmtRate(r.x) : '', mode: mode };
        return t.key(withMax ? (mode ? 'js.filters.chip_rate_max_mode' : 'js.filters.chip_rate_max') : (mode ? 'js.filters.chip_rate_mode' : 'js.filters.chip_rate'), p);
    }
    window.FactChips = {
        render(f, onPick, opts) {
            const o = opts || {};
            if (!f || (!f.tags && !f.year && !f.rates && !f.imdb && !f.filmweb)) return o.always ? el('div', 'fchips') : null;
            const box = el('div', 'fchips');
            const tags = Array.isArray(f.tags) ? f.tags : [];
            const lim = o.all ? tags.length : (f.max > 0 ? f.max : 6);
            const shown = tags.slice(0, lim);
            const rest = tags.slice(lim);
            const add = (tg) => {
                const b = el(onPick ? 'button' : 'span', 'fchip fchip-' + tg.g + (tg.p ? ' fchip-pending' : ''), tg.l);
                if (tg.c) b.style.setProperty('--fc', tg.c);
                if (tg.p) b.title = t.key('js.tags.pending_title');
                if (onPick) {
                    b.type = 'button';
                    b.title = t.key('js.filters.add', { name: tg.l });
                    b.addEventListener('click', (e) => { e.stopPropagation(); onPick(tg.id); });
                }
                if (typeof o.remove === 'function' && o.remove(tg)) {
                    const x = el('button', 'fchip-del');
                    x.type = 'button';
                    x.title = t.key('js.tags.remove', { name: tg.l });
                    x.setAttribute('aria-label', t.key('js.tags.remove', { name: tg.l }));
                    x.appendChild(icon('bi-x'));
                    x.addEventListener('click', (e) => { e.stopPropagation(); o.onRemove(tg); });
                    b.appendChild(x);
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
            ['o', 'v', 'a'].forEach(k => {
                const r = f.rates && f.rates[k];
                if (r && (r.r || r.x)) box.appendChild(factChip('fchip fchip-fact fchip-rate', rateText(k, r), t.key('js.filters.rate_title_' + k)));
            });
            if (f.imdb) box.appendChild(factChip('fchip fchip-fact', 'IMDb ' + Number(f.imdb).toFixed(1), 'IMDb'));
            if (f.filmweb) box.appendChild(factChip('fchip fchip-fact', 'Filmweb ' + Number(f.filmweb).toFixed(1), 'Filmweb'));
            return box;
        },
    };

    window.RepChip = function (rep) {
        if (!rep) return null;
        const stars = rep.mode === 'stars';
        if (stars ? rep.stars == null : rep.pct == null) return null;
        const up = !stars && rep.pct >= 50;
        const c = el('span', 'rep-chip ' + (stars ? 'rep-chip-stars' : (up ? 'rep-chip-up' : 'rep-chip-down')));
        const value = stars ? t.num(Math.round(rep.stars * 10) / 10) : rep.pct + '%';
        c.append(icon(stars ? 'bi-star-fill' : (up ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-down-fill')), el('span', null, value));
        const one = Number(rep.total) === 1;
        c.title = stars ? t.key(one ? 'js.app.rep_stars_title_one' : 'js.app.rep_stars_title_many', { stars: String(value), n: t.num(rep.total) })
                        : t.key(one ? 'js.app.rep_thumbs_title_one' : 'js.app.rep_thumbs_title_many', { pct: rep.pct, n: t.num(rep.total) });
        return c;
    };

    window.InfoGallery = function (list) {
        const box = document.createElement('div');
        box.className = 'info-gal info-section';
        box.setAttribute('role', 'group');
        box.setAttribute('aria-label', t.key('js.app.gal_label', {n: list.length}));
        const track = document.createElement('div');
        track.className = 'info-gal-track';
        const full = list.map((g) => ({ src: g.f, alt: '' }));
        list.forEach((g, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'info-gal-item';
            b.style.setProperty('--r', g.w > 0 && g.h > 0 ? (g.w / g.h).toFixed(3) : '1');
            b.setAttribute('aria-label', t.key('js.app.gal_open', {n: i + 1, total: list.length}));
            const img = document.createElement('img');
            img.alt = '';
            img.decoding = 'async';
            img.loading = i < 4 ? 'eager' : 'lazy';
            img.src = g.t;
            b.appendChild(img);
            b.addEventListener('click', () => { if (window.SiteLightbox) window.SiteLightbox.show(full, i, img); });
            track.appendChild(b);
        });
        box.appendChild(track);
        const still = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        const nav = (dir) => {
            const n = document.createElement('button');
            n.type = 'button';
            n.className = 'info-gal-nav ' + (dir < 0 ? 'info-gal-prev' : 'info-gal-next');
            n.setAttribute('aria-label', t.key(dir < 0 ? 'js.app.gal_prev' : 'js.app.gal_next'));
            n.appendChild(icon(dir < 0 ? 'bi-chevron-left' : 'bi-chevron-right'));
            n.addEventListener('click', () => track.scrollBy({ left: dir * Math.max(120, track.clientWidth * 0.8), behavior: still ? 'auto' : 'smooth' }));
            box.appendChild(n);
            return n;
        };
        const prev = nav(-1), next = nav(1);
        const sync = () => {
            const end = track.scrollWidth - track.clientWidth - 8;
            box.classList.toggle('can-prev', track.scrollLeft > 8);
            box.classList.toggle('can-next', track.scrollLeft < end);
            prev.disabled = track.scrollLeft <= 8;
            next.disabled = track.scrollLeft >= end;
        };
        track.addEventListener('scroll', sync, { passive: true });
        box.addEventListener('pointerenter', sync);
        requestAnimationFrame(sync);
        return box;
    };

    window.DescFold = function (d, fold, btnCls) {
        const wrap = el('div', 'info-desc');
        wrap.style.setProperty('--fold', fold + 'px');
        wrap.appendChild(d);
        const btn = el('button', (btnCls || 'btn btn-secondary btn-small') + ' info-desc-toggle');
        btn.type = 'button';
        const show = (open) => {
            wrap.classList.toggle('info-desc-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.replaceChildren(icon(open ? 'bi-chevron-up' : 'bi-chevron-down'), ' ', t.key(open ? 'js.app.desc_fold' : 'js.app.desc_unfold'));
        };
        btn.addEventListener('click', () => {
            const open = !wrap.classList.contains('info-desc-open');
            show(open);
            if (!open) wrap.scrollIntoView({ block: 'start' });
        });
        const measure = () => wrap.classList.toggle('info-desc-long', d.scrollHeight > fold + 96);
        d.addEventListener('load', measure, true);
        requestAnimationFrame(measure);
        show(false);
        wrap.appendChild(btn);
        return wrap;
    };

    const tsReduced = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    let tsSeen = null;
    window.ThumbSlider = {
        mount(box, thumb) {
            const list = thumb && Array.isArray(thumb.t) ? thumb.t : [];
            if (!list.length) return null;
            const full = Array.isArray(thumb.f) && thumb.f.length === list.length ? thumb.f : list;
            const speed = Math.max(2, Number(thumb.s) || 4) * 1000;
            box.classList.add('ts');
            if (thumb.w) box.style.setProperty('--thumb-w', Number(thumb.w) + 'px');
            box.setAttribute('role', 'button');
            box.tabIndex = 0;
            box.title = t.key('js.app.thumbs_open');
            box.setAttribute('aria-label', t.key('js.app.thumbs_open'));
            const layers = [el('img', 'ts-img is-on'), el('img', 'ts-img')];
            layers.forEach(im => { im.alt = ''; im.decoding = 'async'; im.loading = 'lazy'; });
            layers[0].src = list[0];
            box.append(layers[0], layers[1]);
            const count = list.length > 1 ? el('span', 'ts-n', '1/' + list.length) : null;
            if (count) box.appendChild(count);
            let at = 0, top = 0, timer = 0, hover = false;
            const show = (n) => {
                const next = layers[1 - top];
                next.onload = () => { next.classList.add('is-on'); layers[top].classList.remove('is-on'); top = 1 - top; };
                next.src = list[n];
                at = n;
                if (count) count.textContent = (n + 1) + '/' + list.length;
            };
            const api = {
                start() { if (list.length < 2 || timer || hover || tsReduced()) return; timer = setInterval(() => show((at + 1) % list.length), speed); },
                stop() { clearInterval(timer); timer = 0; },
            };
            box.addEventListener('mouseenter', () => { hover = true; api.stop(); });
            box.addEventListener('mouseleave', () => { hover = false; api.start(); });
            const open = (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (window.SiteLightbox) window.SiteLightbox.show(full.map(u => ({ src: u, href: u, alt: '' })), at, box);
            };
            box.addEventListener('click', open);
            box.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') open(e); });
            if (list.length > 1 && 'IntersectionObserver' in window) {
                tsSeen = tsSeen || new IntersectionObserver((entries) => entries.forEach(en => {
                    const a = en.target.thumbSlider;
                    if (!a) return;
                    if (en.isIntersecting) a.start(); else a.stop();
                }));
                box.thumbSlider = api;
                tsSeen.observe(box);
            }
            return api;
        },
    };

    function apiBase() { return (typeof APP_API === 'string') ? APP_API : 'api.php?endpoint='; }
    function csrf() { return window.csrfToken(); }
    async function postJ(endpoint, body) {
        try {
            const res = await fetch(apiBase() + endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(Object.assign({ csrf_token: csrf() }, body)) });
            return await res.json();
        } catch (e) { return null; }
    }
    async function getJ(endpoint) {
        try { return await (await fetch(apiBase() + endpoint, { headers: { 'Accept': 'application/json' } })).json(); } catch (e) { return null; }
    }

    window.TagEditor = {
        mount(host, json, onPick) {
            let facts = json.facts || null;
            const edit = json.tag_edit || null;
            let editing = false;
            const say = el('div', 'tag-edit-say text-muted');
            say.setAttribute('aria-live', 'polite');
            const addTag = async (body) => {
                const r = await postJ('tag_add', Object.assign({ hash: json.info_hash }, body));
                say.textContent = (r && r.message) || t.key('js.tags.failed');
                if (r && r.success) {
                    facts = r.facts || null;
                    draw();
                    const i = host.querySelector('.tag-edit-input');
                    if (i) i.focus();
                }
            };
            const removeTag = async (tg) => {
                const r = await postJ('tag_remove', { hash: json.info_hash, tag: tg.id });
                say.textContent = (r && r.message) || t.key('js.tags.failed');
                if (r && r.success) { facts = r.facts || null; draw(); }
            };
            const picker = () => {
                const wrap = el('div', 'tag-edit-picker');
                const input = el('input', 'profile-search tag-edit-input');
                input.type = 'text';
                input.maxLength = 40;
                input.autocomplete = 'off';
                input.placeholder = t.key(edit.create ? 'js.tags.add_or_create_ph' : 'js.tags.add_ph');
                input.setAttribute('aria-label', t.key('js.tags.add_label'));
                const list = el('div', 'tag-edit-sugg');
                let timer = 0;
                let items = [];
                const show = () => {
                    list.textContent = '';
                    const q = input.value.trim();
                    items.forEach(it => {
                        const b = el('button', 'fchip fchip-' + it.g + ' tag-edit-opt', it.l);
                        b.type = 'button';
                        if (it.c) b.style.setProperty('--fc', it.c);
                        b.addEventListener('click', () => addTag({ tag: it.id }));
                        list.appendChild(b);
                    });
                    if (edit.create && q && !items.some(it => it.l.toLowerCase() === q.toLowerCase())) {
                        const c = el('button', 'btn btn-secondary btn-small tag-edit-new');
                        c.type = 'button';
                        c.append(icon('bi-plus-lg'), ' ', t.key('js.tags.create', { name: q }));
                        c.addEventListener('click', () => addTag({ name: q }));
                        list.appendChild(c);
                    }
                };
                input.addEventListener('input', () => {
                    clearTimeout(timer);
                    const q = input.value.trim();
                    if (!q) { items = []; show(); return; }
                    timer = setTimeout(async () => {
                        const r = await getJ('tags_search&q=' + encodeURIComponent(q));
                        items = (r && r.tags) || [];
                        show();
                    }, 250);
                });
                input.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    const q = input.value.trim();
                    if (!q) return;
                    const exact = items.find(it => it.l.toLowerCase() === q.toLowerCase());
                    if (exact) addTag({ tag: exact.id });
                    else if (edit.create) addTag({ name: q });
                    else if (items[0]) addTag({ tag: items[0].id });
                });
                const have = new Set(((facts && facts.tags) || []).map(x => x.id));
                wrap.appendChild(input);
                const mineTags = (edit.mine || []).filter(s => !have.has(s.id));
                if (mineTags.length) {
                    const row = el('div', 'tag-edit-hints tag-edit-mine');
                    row.appendChild(el('span', 'tag-edit-hints-l', t.key('js.tags.mine_quick')));
                    mineTags.forEach(s => {
                        const b = el('button', 'fchip fchip-' + s.g + ' tag-edit-opt', s.l);
                        b.type = 'button';
                        b.title = t.key('js.tags.mine_quick_title', { name: s.l });
                        if (s.c) b.style.setProperty('--fc', s.c);
                        b.addEventListener('click', () => addTag({ tag: s.id }));
                        row.appendChild(b);
                    });
                    wrap.appendChild(row);
                }
                wrap.appendChild(list);
                const hints = (edit.suggest || []).filter(s => !have.has(s.id));
                if (hints.length) {
                    const row = el('div', 'tag-edit-hints');
                    row.appendChild(el('span', 'tag-edit-hints-l', t.key('js.tags.suggested')));
                    hints.forEach(s => {
                        const b = el('button', 'fchip fchip-' + s.g + ' tag-edit-opt', s.l);
                        b.type = 'button';
                        b.title = t.key('js.tags.suggested_why', { word: s.w });
                        if (s.c) b.style.setProperty('--fc', s.c);
                        b.addEventListener('click', () => addTag({ tag: s.id }));
                        row.appendChild(b);
                    });
                    wrap.appendChild(row);
                }
                return wrap;
            };
            const draw = () => {
                host.textContent = '';
                const chips = window.FactChips.render(facts, editing ? null : onPick,
                    editing ? { all: true, always: true, remove: (tg) => edit.moderate || !!tg.m, onRemove: removeTag } : null);
                if (chips) host.appendChild(chips);
                if (!edit) return;
                const bar = el('div', 'tag-edit-bar');
                const toggle = el('button', 'btn btn-secondary btn-small tag-edit-toggle');
                toggle.type = 'button';
                toggle.setAttribute('aria-expanded', editing ? 'true' : 'false');
                toggle.append(icon(editing ? 'bi-check2' : 'bi-tags'), ' ', t.key(editing ? 'js.tags.done_editing' : 'js.tags.edit_tags'));
                toggle.addEventListener('click', () => {
                    editing = !editing;
                    say.textContent = '';
                    draw();
                    const i = host.querySelector('.tag-edit-input');
                    if (editing && i) i.focus();
                });
                if (editing && edit.add) bar.append(picker(), toggle);
                else bar.appendChild(toggle);
                host.append(bar, say);
            };
            draw();
        },
    };

    function tagsPage() {
        const q = document.getElementById('tags-page-q');
        if (!q) return;
        const PAGE = 60;
        const mine = document.querySelector('.tags-mine .tags-list');
        const mineEmpty = document.querySelector('.tags-mine-empty');
        document.querySelectorAll('.tag-fav').forEach(b => b.addEventListener('click', async () => {
            const on = b.getAttribute('aria-pressed') !== 'true';
            b.disabled = true;
            const r = await postJ('tag_fav', { tag: b.dataset.id, on: on });
            b.disabled = false;
            if (!r || !r.success) { b.title = (r && r.message) || t.key('js.tags.failed'); return; }
            document.querySelectorAll('.tag-fav').forEach(x => {
                if (x.dataset.id !== b.dataset.id) return;
                x.setAttribute('aria-pressed', r.on ? 'true' : 'false');
                x.title = t.key(r.on ? 'js.tags.fav_off' : 'js.tags.fav_on');
                x.setAttribute('aria-label', x.title);
                const i = x.querySelector('i');
                if (i) i.className = 'bi ' + (r.on ? 'bi-star-fill' : 'bi-star');
            });
            if (!mine) return;
            const have = [...mine.querySelectorAll('a[data-id]')].find(a => a.dataset.id === b.dataset.id);
            const chip = b.parentElement ? b.parentElement.querySelector('a') : null;
            if (r.on && !have && chip) {
                const c = chip.cloneNode(true);
                c.querySelectorAll('.sf-n').forEach(n => n.remove());
                c.dataset.id = b.dataset.id;
                c.classList.add('sf-in');
                mine.appendChild(c);
            } else if (!r.on && have) {
                have.remove();
            }
            if (mineEmpty) mineEmpty.hidden = !!mine.querySelector('a');
        }));
        const catData = document.getElementById('tags-cat-data');
        let cats = [];
        try { cats = JSON.parse(catData ? catData.dataset.cats || '[]' : '[]'); } catch (e) { cats = []; }
        document.querySelectorAll('.tag-cat').forEach(b => b.addEventListener('click', () => {
            const d = el('dialog', 'rep-dialog tags-cat-dialog');
            const head = el('div', 'rep-dialog-head', t.key('js.tags.cat_title', { name: b.dataset.name || b.dataset.id }));
            const sel = el('select', 'profile-search tags-cat-sel');
            sel.setAttribute('aria-label', t.key('js.tags.cat_pick'));
            sel.appendChild(new Option(t.key('js.tags.cat_pick'), ''));
            cats.forEach(c => {
                const o = new Option(c.l, c.k);
                o.disabled = c.k === b.dataset.k;
                sel.appendChild(o);
            });
            const now = cats.find(c => c.k === b.dataset.k);
            sel.appendChild(new Option(t.key('js.tags.cat_new'), '~new'));
            const input = el('input', 'profile-search tags-cat-new');
            input.maxLength = 40;
            input.placeholder = t.key('js.tags.cat_new_ph');
            input.setAttribute('aria-label', t.key('js.tags.cat_new_ph'));
            input.hidden = true;
            sel.addEventListener('change', () => { input.hidden = sel.value !== '~new'; if (!input.hidden) input.focus(); });
            const say = el('div', 'tag-edit-say');
            const send = el('button', 'btn btn-small', t.key('js.tags.cat_send'));
            const cancel = el('button', 'btn btn-secondary btn-small', t.key('js.tags.cancel'));
            send.type = 'button';
            cancel.type = 'button';
            cancel.addEventListener('click', () => d.close());
            send.addEventListener('click', async () => {
                const k = sel.value;
                if (!k || (k === '~new' && !input.value.trim())) { say.textContent = t.key('js.tags.cat_need'); return; }
                send.disabled = true;
                const r = await postJ('tag_cat_suggest', { tag: b.dataset.id, category: k === '~new' ? '' : k, name: k === '~new' ? input.value.trim() : '' });
                send.disabled = false;
                say.textContent = (r && (r.message || r.error)) || t.key('js.tags.failed');
                if (r && r.success) setTimeout(() => d.close(), 1400);
            });
            const acts = el('div', 'tags-cat-acts');
            acts.append(send, cancel);
            d.append(head, el('p', 'text-muted tags-cat-hint', t.key('js.tags.cat_hint')));
            if (now) d.appendChild(el('p', 'text-muted tags-cat-hint', t.key('js.tags.cat_now', { name: now.l })));
            d.append(sel, input, say, acts);
            d.addEventListener('close', () => d.remove());
            document.body.appendChild(d);
            d.showModal();
            sel.focus();
        }));
        const none = document.getElementById('tags-page-none');
        document.querySelectorAll('.tags-group:not(.tags-mine)').forEach(g => {
            const per = Number(g.dataset.page) || PAGE;
            if (g.querySelectorAll('.tags-list a').length <= per) return;
            g.dataset.shown = String(per);
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'btn btn-secondary btn-small tags-more';
            more.addEventListener('click', () => {
                const rest = Number(g.dataset.rest) || 0;
                g.dataset.shown = String(rest > 0 ? Number(g.dataset.shown) + per : per);
                apply(true);
                if (!rest) g.scrollIntoView({ block: 'nearest' });
            });
            g.appendChild(more);
        });
        const apply = (reveal) => {
            const s = q.value.trim().toLowerCase();
            let any = false;
            document.querySelectorAll('.tags-group').forEach(g => {
                let seen = false;
                let k = 0;
                const lim = Number(g.dataset.shown) || 0;
                g.querySelectorAll('.tags-list a').forEach(a => {
                    const unit = a.closest('.tag-pair') || a;
                    const was = unit.hidden;
                    let hide = s !== '' && (g.dataset.g === 'popular' || !a.dataset.s.includes(s));
                    if (!hide && s === '' && lim) {
                        k += 1;
                        hide = k > lim;
                    }
                    unit.hidden = hide;
                    if (hide) return;
                    seen = true;
                    if (reveal && was) {
                        unit.classList.remove('sf-in');
                        void unit.offsetWidth;
                        unit.classList.add('sf-in');
                    }
                });
                const more = g.querySelector(':scope > .tags-more');
                if (more) {
                    const rest = Math.max(0, k - lim);
                    g.dataset.rest = String(rest);
                    more.hidden = s !== '';
                    more.replaceChildren(icon(rest ? 'bi-chevron-down' : 'bi-chevron-up'), ' ', t.key(rest ? 'js.tags.page_more' : 'js.tags.page_less', { n: t.num(rest) }));
                }
                g.hidden = !seen && !(s === '' && g.classList.contains('tags-mine'));
                if (seen) any = true;
            });
            if (none) none.hidden = any || s === '';
        };
        q.addEventListener('input', () => apply(false));
        apply(false);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tagsPage); else tagsPage();
})();
