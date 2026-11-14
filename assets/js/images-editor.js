(function () {
    'use strict';

    const API = (typeof APP_API === 'string') ? APP_API : 'api.php?endpoint=';
    const PARALLEL = 2;
    const NAME_MAX = 60;
    const STOP = ['daily_limit', 'quota', 'not_allowed', 'csrf'];
    const IMAGE_EXT = /\.(?:jpe?g|png|gif|webp|avif|bmp|heic|heif|jxl)$/i;
    const MIRRORED = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'fontVariant', 'fontStretch', 'fontKerning',
        'fontFeatureSettings', 'lineHeight', 'letterSpacing', 'wordSpacing', 'textTransform', 'textIndent', 'textAlign',
        'tabSize', 'direction', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'whiteSpace', 'overflowWrap',
        'wordBreak', 'hyphens'];

    function balanceRows(count, maxPerRow = 4) {
        if (!(count > 0)) return [];
        const rowCount = Math.ceil(count / maxPerRow);
        const base = Math.floor(count / rowCount);
        const remainder = count % rowCount;
        const rows = [];
        for (let r = 0; r < rowCount; r++) rows.push(base + (r < remainder ? 1 : 0));
        return rows;
    }

    function labelsBlock(urls) {
        const list = Array.from(urls || []);
        if (!list.length) return '';
        const rows = balanceRows(list.length);
        let i = 0;
        let out = '[thxlabels=' + rows[0] + ']';
        rows.forEach((size) => {
            out += '[thxrow]';
            for (let j = 0; j < size; j++) out += '[img]' + list[i++] + '[/img]';
            out += '[/thxrow]';
        });
        return out + '[/thxlabels]';
    }

    function imageMarkup(url, format) {
        return format === 'markdown' ? '![](' + url + ')' : '[img]' + url + '[/img]';
    }

    function labelsAllowed(format) {
        return format === 'bbcode' || format === 'flarum';
    }

    const instances = new WeakMap();
    const queue = [];
    let running = 0;
    let seq = 0;
    const limitsFor = {};
    const limitsAsk = {};
    const placeArg = (place) => (place ? '&place=' + encodeURIComponent(place) : '');

    function el(tag, cls, attrs, kids) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (attrs) Object.keys(attrs).forEach((k) => { if (attrs[k] !== null && attrs[k] !== undefined) n.setAttribute(k, attrs[k]); });
        if (kids) n.append(...kids.filter((k) => k !== null && k !== undefined));
        return n;
    }

    function icon(name) {
        return el('i', 'bi ' + name, { 'aria-hidden': 'true' });
    }

    function iconBtn(name, label, cls) {
        return el('button', 'btn btn-secondary btn-small ' + (cls ? cls + ' ' : '') + 'ic-btn',
            { type: 'button', 'aria-label': label, 'data-tip': label }, [icon(name)]);
    }

    const safeUrl = (u) => typeof u === 'string' && /^https?:\/\/[^\s[\]()<>"']+$/i.test(u);
    const serverSays = (j) => (j && typeof j.error === 'string' && j.error.trim() !== '' ? j.error : '');
    const shortName = (file) => {
        const s = String((file && file.name) || 'image').replace(/[[\]\r\n\t]+/g, ' ').trim() || 'image';
        return s.length > NAME_MAX ? s.slice(0, NAME_MAX - 1) + '…' : s;
    };
    const isImage = (file) => !!file && (/^image\//i.test(file.type || '') || (!file.type && IMAGE_EXT.test(file.name || '')));
    const hasFiles = (dt) => !!dt && Array.from(dt.types || []).indexOf('Files') !== -1;

    async function list(page, place) {
        try {
            const r = await fetch(API + 'images&page=' + encodeURIComponent(page) + placeArg(place), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const j = await r.json();
            if (j && j.success && j.limits && typeof j.limits === 'object') limitsFor[place || ''] = j.limits;
            return j;
        } catch (e) {
            return null;
        }
    }

    async function post(body, place) {
        try {
            const r = await fetch(API + 'images' + placeArg(place), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            });
            return await r.json();
        } catch (e) {
            return null;
        }
    }

    function ensureLimits(place) {
        const k = place || '';
        if (limitsFor[k]) return Promise.resolve(limitsFor[k]);
        if (!limitsAsk[k]) limitsAsk[k] = list(1, k).then(() => { limitsAsk[k] = null; return limitsFor[k] || null; });
        return limitsAsk[k];
    }

    function send(file, node, onProgress, place) {
        return new Promise((resolve) => {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken(node));
            fd.append('file', file, file.name || 'image.png');
            const x = new XMLHttpRequest();
            x.open('POST', API + 'images' + placeArg(place));
            x.setRequestHeader('Accept', 'application/json');
            x.upload.addEventListener('progress', (e) => { if (e.lengthComputable && e.total > 0) onProgress(e.loaded / e.total); });
            x.addEventListener('load', () => {
                let j = null;
                try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
                resolve({ status: x.status, json: j });
            });
            x.addEventListener('error', () => resolve({ status: 0, json: null }));
            x.addEventListener('abort', () => resolve({ status: 0, json: null }));
            x.send(fd);
        });
    }

    function pump() {
        while (running < PARALLEL && queue.length) {
            const item = queue.shift();
            running++;
            item.run().catch(() => {}).then(() => { running--; pump(); });
        }
    }

    function dropQueued(error) {
        queue.splice(0).forEach((item) => item.cancel(error));
    }

    function offsetFromPoint(ta, x, y) {
        const doc = ta.ownerDocument;
        if (!doc.caretPositionFromPoint && !doc.caretRangeFromPoint) return null;
        const cs = getComputedStyle(ta);
        const box = ta.getBoundingClientRect();
        const m = doc.createElement('div');
        MIRRORED.forEach((p) => { m.style[p] = cs[p]; });
        Object.assign(m.style, {
            position: 'fixed', boxSizing: 'border-box', margin: '0', border: '0', overflow: 'hidden',
            opacity: '0', zIndex: '2147483647', pointerEvents: 'auto',
            left: (box.left + (parseFloat(cs.borderLeftWidth) || 0)) + 'px',
            top: (box.top + (parseFloat(cs.borderTopWidth) || 0) - ta.scrollTop) + 'px',
            width: ta.clientWidth + 'px',
            minHeight: (ta.clientHeight + ta.scrollTop) + 'px',
        });
        const text = doc.createTextNode(ta.value);
        const tail = doc.createTextNode('​');
        m.append(text, tail);
        doc.body.appendChild(m);
        let node = null, offset = 0;
        try {
            if (doc.caretPositionFromPoint) {
                const p = doc.caretPositionFromPoint(x, y);
                if (p) { node = p.offsetNode; offset = p.offset; }
            } else {
                const r = doc.caretRangeFromPoint(x, y);
                if (r) { node = r.startContainer; offset = r.startOffset; }
            }
        } finally {
            m.remove();
        }
        if (node === text) return Math.min(offset, ta.value.length);
        if (node === tail || (node === m && offset > 0)) return ta.value.length;
        if (node === m) return 0;
        return null;
    }

    function attach(opts) {
        const ta = opts && opts.textarea;
        const id = opts && opts.id;
        if (!ta || !id) return null;
        if (instances.has(ta)) return instances.get(ta);
        const part = (s) => document.getElementById(id + '-images-' + s);
        const root = document.getElementById(id + '-images');
        const addBtn = part('add'), fileIn = part('file'), mineBtn = part('mine');
        const ups = part('ups'), msg = part('msg'), say = part('say'), panel = part('panel');
        if (!root || !addBtn || !fileIn || !mineBtn || !ups || !msg || !panel) return null;
        const fmt = typeof opts.format === 'function' ? opts.format : () => 'bbcode';
        const place = root.dataset.place || '';
        const labelsOn = root.dataset.labels !== '0';
        const sep = place === 'comment' ? ' ' : '\n';
        const fmtEl = document.getElementById(id + '-format');
        const editor = ta.closest('.rt-editor') || ta;
        let pending = 0;
        let caret = null;

        function range() {
            const len = ta.value.length;
            const [s, e] = caret && document.activeElement !== ta ? caret : [ta.selectionStart, ta.selectionEnd];
            const from = Math.max(0, Math.min(s, len));
            return [from, Math.max(from, Math.min(e, len))];
        }

        function remember() {
            if (document.activeElement !== ta) caret = [ta.selectionEnd, ta.selectionEnd];
        }

        function note(text) {
            const s = String(text);
            if ([...msg.children].some((c) => c.textContent === s)) return;
            msg.appendChild(el('div', 'rt-images-note', null, [text]));
        }

        function announce(text) {
            if (!say) return;
            say.textContent = '';
            setTimeout(() => { say.textContent = text; }, 60);
        }

        function insertText(text, block) {
            if (ta.readOnly || ta.disabled) return false;
            const [start, end] = range();
            const pre = block && start > 0 && ta.value[start - 1] !== '\n' ? '\n' : '';
            ta.setRangeText(pre + text + (block ? '\n' : ''), start, end, 'end');
            remember();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
        }

        function swap(job, markup) {
            const v = ta.value;
            const at = v.indexOf(job.ph);
            if (at === -1) return;
            let to = at + job.ph.length;
            if (markup === null && job.tail && v.slice(to, to + job.tail.length) === job.tail) to += job.tail.length;
            const put = markup === null ? '' : markup;
            const move = (p) => (p >= to ? p + put.length - (to - at) : (p > at ? at + put.length : p));
            const sel = [ta.selectionStart, ta.selectionEnd];
            ta.setRangeText(put, at, to, 'preserve');
            if (document.activeElement === ta) ta.setSelectionRange(move(sel[0]), move(sel[1]));
            if (caret) caret = caret.map(move);
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function upRow(job) {
            job.bar = el('progress', 'rt-images-progress', { max: '100', 'aria-label': t.key('js.images.progress_label', { name: job.name }) });
            job.state = el('span', 'rt-images-up-state', null, [t.key('js.images.waiting')]);
            job.row = el('li', 'rt-images-up', null, [el('span', 'rt-images-up-name', null, [job.name]), job.bar, job.state]);
            ups.appendChild(job.row);
            ups.hidden = false;
        }

        function progress(job, p) {
            const pct = Math.max(0, Math.min(100, Math.round(p * 100)));
            job.bar.value = pct;
            job.state.textContent = pct >= 100 ? t.key('js.images.processing') : t.key('js.images.percent', { n: t.num(pct) });
        }

        function finish(job) {
            pending = Math.max(0, pending - 1);
            if (job.row) job.row.remove();
            if (!ups.children.length) ups.hidden = true;
            if (!pending && view.open) view.load(view.page);
        }

        function bump(image) {
            const lim = limitsFor[place];
            if (!lim) return;
            lim.today = (Number(lim.today) || 0) + 1;
            lim.used_bytes = (Number(lim.used_bytes) || 0) + (Number(image.bytes) || 0);
            view.quota();
        }

        async function upload(job) {
            try {
                job.state.textContent = t.key('js.images.percent', { n: t.num(0) });
                job.bar.value = 0;
                const r = await send(job.file, ta, (p) => progress(job, p), place);
                const j = r.json;
                if (j && j.success && j.image && safeUrl(j.image.url)) {
                    swap(job, imageMarkup(j.image.url, fmt()));
                    bump(j.image);
                    announce(t.key('js.images.uploaded', { name: job.name }));
                    return;
                }
                swap(job, null);
                const error = serverSays(j) || (r.status === 413 ? t.key('js.images.too_big_server') : t.key('js.images.upload_failed'));
                if (j && STOP.indexOf(j.code) !== -1) {
                    note(error);
                    dropQueued(error);
                } else {
                    note(t.key('js.images.failed_named', { name: job.name, error }));
                }
            } finally {
                finish(job);
            }
        }

        function cancel(job, error) {
            swap(job, null);
            note(error);
            finish(job);
        }

        async function add(files, at, focus) {
            if (ta.readOnly || ta.disabled || !files.length) return;
            msg.replaceChildren();
            pending++;
            let lim = null;
            try { lim = await ensureLimits(place); } finally { pending--; }
            const max = lim ? Number(lim.max_bytes) || 0 : 0;
            const good = [];
            files.forEach((file) => {
                const name = shortName(file);
                if (!isImage(file)) note(t.key('js.images.not_image', { name }));
                else if (max > 0 && file.size > max) note(t.key('js.images.too_big', { name, max: t.bytes(max) }));
                else good.push(file);
            });
            if (!good.length) return;
            const jobs = good.map((file, i) => {
                const name = shortName(file);
                return { file, name, ph: t('js.images.placeholder', { name, n: ++seq }), tail: i < good.length - 1 ? sep : '' };
            });
            const pos = at === null ? null : Math.max(0, Math.min(at, ta.value.length));
            const [from, to] = pos === null ? range() : [pos, pos];
            ta.setRangeText(jobs.map((j) => j.ph + j.tail).join(''), from, to, 'end');
            if (focus) ta.focus();
            remember();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            jobs.forEach((job) => {
                pending++;
                upRow(job);
                queue.push({ run: () => upload(job), cancel: (error) => cancel(job, error) });
            });
            announce(t.plural(jobs.length, 'js.images.uploading'));
            pump();
        }

        function over(on) {
            editor.classList.toggle('rt-images-over', on);
            root.classList.toggle('rt-images-dragging', on);
        }

        const view = (() => {
            const picked = new Map();
            const v = { open: false, page: 1, pages: 1 };
            let built = false, loadSeq = 0;
            let quotaEl, grid, status, labelsBtn, labelsTxt, labelsHint, pager, prevBtn, nextBtn, pageInfo;

            function build() {
                quotaEl = el('div', 'rt-images-quota');
                grid = el('ul', 'rt-images-grid');
                status = el('p', 'rt-images-status', { role: 'status' });
                labelsTxt = el('span', null, null, [t.key('js.images.labels')]);
                labelsBtn = el('button', 'btn btn-secondary btn-small rt-images-labels', { type: 'button' }, [icon('bi-ui-checks-grid'), labelsTxt]);
                labelsHint = el('span', 'rt-images-labels-hint', null, [t.key('js.images.labels_hint')]);
                prevBtn = iconBtn('bi-chevron-left', t.key('js.images.prev'));
                nextBtn = iconBtn('bi-chevron-right', t.key('js.images.next'));
                pageInfo = el('span', 'rt-images-page');
                pager = el('span', 'rt-images-pager', null, [prevBtn, pageInfo, nextBtn]);
                panel.append(quotaEl, el('p', 'rt-images-tip', null, [t.key('js.images.mine_tip')]), grid, status,
                    el('div', 'rt-images-foot', null, [labelsBtn, labelsHint, pager]));
                prevBtn.addEventListener('click', () => { if (v.page > 1) load(v.page - 1); });
                nextBtn.addEventListener('click', () => { if (v.page < v.pages) load(v.page + 1); });
                labelsBtn.addEventListener('click', () => {
                    if (!picked.size || !labelsOn || !labelsAllowed(fmt())) return;
                    if (!insertText(labelsBlock([...picked.values()]), true)) return;
                    picked.clear();
                    grid.querySelectorAll('.rt-images-pick input').forEach((b) => { b.checked = false; });
                    grid.querySelectorAll('.rt-images-card.is-picked').forEach((c) => c.classList.remove('is-picked'));
                    sync();
                    announce(t.key('js.images.labels_inserted'));
                });
                built = true;
            }

            function quota() {
                if (!built) return;
                const limits = limitsFor[place];
                if (!limits) { quotaEl.replaceChildren(); return; }
                const today = Number(limits.today) || 0, perDay = Number(limits.per_day) || 0;
                const used = Number(limits.used_bytes) || 0, cap = Number(limits.quota_bytes) || 0;
                const usedTxt = used > 0 ? t.bytes(used) : t.num(0);
                const a = el('span', 'rt-images-quota-part', null, [perDay > 0
                    ? t.key('js.images.quota_today', { today: t.num(today), limit: t.num(perDay) })
                    : t.key('js.images.quota_today_free', { today: t.num(today) })]);
                const b = el('span', 'rt-images-quota-part', null, [cap > 0
                    ? t.key('js.images.quota_space', { used: usedTxt, quota: t.bytes(cap) })
                    : t.key('js.images.quota_space_free', { used: usedTxt })]);
                a.classList.toggle('is-full', perDay > 0 && today >= perDay);
                b.classList.toggle('is-full', cap > 0 && used >= cap);
                const dayUsed = Number(limits.today_bytes) || 0, dayCap = Number(limits.per_day_bytes) || 0;
                const c = dayCap > 0 ? el('span', 'rt-images-quota-part', null,
                    [t.key('js.images.quota_today_mb', { used: dayUsed > 0 ? t.bytes(dayUsed) : t.num(0), limit: t.bytes(dayCap) })]) : null;
                if (c) c.classList.toggle('is-full', dayUsed >= dayCap);
                quotaEl.replaceChildren(...[a, c, b].filter(Boolean));
            }

            function sync() {
                if (!built) return;
                const on = labelsOn && labelsAllowed(fmt());
                labelsBtn.hidden = !on;
                labelsHint.hidden = !on;
                grid.querySelectorAll('.rt-images-pick').forEach((p) => { p.hidden = !on; });
                labelsBtn.disabled = picked.size === 0;
                labelsTxt.textContent = picked.size ? t.key('js.images.labels_n', { n: t.num(picked.size) }) : t.key('js.images.labels');
            }

            function insertOne(url) {
                if (insertText(imageMarkup(url, fmt()), false)) announce(t.key('js.images.inserted'));
            }

            function remove(btn, li, r) {
                if (typeof window.askInPlace !== 'function') return;
                const at = [...grid.children].indexOf(li);
                window.askInPlace(btn, t.key('js.images.delete_q'), async () => {
                    const j = await post({ op: 'delete', id: r.id, csrf_token: csrfToken(ta) }, place);
                    msg.replaceChildren();
                    if (!j || !j.success) {
                        note(serverSays(j) || t.key('js.images.delete_failed'));
                        return false;
                    }
                    picked.delete(r.id);
                    const lim = limitsFor[place];
                    if (lim) lim.used_bytes = Math.max(0, (Number(lim.used_bytes) || 0) - (Number(r.bytes) || 0));
                    announce(t.key('js.images.deleted'));
                    load(grid.children.length <= 1 && v.page > 1 ? v.page - 1 : v.page, at);
                    return true;
                }, { host: li });
            }

            function card(r) {
                const url = r.url;
                const name = String(r.name || '').trim() || ('#' + r.id);
                const img = el('img', null, { src: url, alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' });
                if (Number(r.w) > 0 && Number(r.h) > 0) { img.width = Number(r.w); img.height = Number(r.h); }
                const thumb = el('div', 'rt-images-thumb', {
                    role: 'button', tabindex: '0', draggable: 'true',
                    'aria-label': t.key('js.images.insert_one', { name }),
                    'data-tip': t.key('js.images.thumb_tip', { name, w: t.num(r.w), h: t.num(r.h), size: t.bytes(r.bytes) }),
                }, [img]);
                thumb.addEventListener('click', () => insertOne(url));
                thumb.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter' && e.key !== ' ') return;
                    e.preventDefault();
                    insertOne(url);
                });
                thumb.addEventListener('dragstart', (e) => {
                    if (!e.dataTransfer) return;
                    e.dataTransfer.effectAllowed = 'copy';
                    e.dataTransfer.setData('text/plain', imageMarkup(url, fmt()));
                });
                const box = el('input', null, { type: 'checkbox', 'aria-label': t.key('js.images.pick', { name }) });
                box.checked = picked.has(r.id);
                const pick = el('label', 'search-check rt-images-pick', null, [box, el('span', 'search-check-box', { 'aria-hidden': 'true' })]);
                const tags = el('span', 'rt-images-tags', null, [
                    r.used ? el('span', 'rt-images-tag', { 'data-tip': t.key('js.images.in_use_tip') }, [t.key('js.images.in_use')]) : null,
                    r.animated ? el('span', 'rt-images-tag', null, [t.key('js.images.animated')]) : null,
                ]);
                const copyLink = iconBtn('bi-link-45deg', t.key('js.images.copy_link'));
                const copyCode = iconBtn('bi-code-slash', t.key('js.images.copy_bbcode'));
                const del = iconBtn('bi-trash', t.key('js.images.delete'), 'rt-images-del');
                const li = el('li', 'rt-images-card' + (box.checked ? ' is-picked' : ''), null,
                    [thumb, pick, tags.children.length ? tags : null, el('div', 'rt-images-acts', null, [copyLink, copyCode, del])]);
                box.addEventListener('change', () => {
                    if (box.checked) picked.set(r.id, url); else picked.delete(r.id);
                    li.classList.toggle('is-picked', box.checked);
                    sync();
                });
                copyLink.addEventListener('click', () => copy(copyLink, url, t.key('js.images.copy_link')));
                copyCode.addEventListener('click', () => copy(copyCode, imageMarkup(url, 'bbcode'), t.key('js.images.copy_bbcode')));
                del.addEventListener('click', () => remove(del, li, r));
                return li;
            }

            async function load(n, focusAt) {
                if (!built) build();
                const mine = ++loadSeq;
                status.hidden = false;
                status.textContent = t.key('js.images.mine_loading');
                const j = await list(n, place);
                if (mine !== loadSeq) return;
                quota();
                if (!j || !j.success) {
                    grid.replaceChildren();
                    pager.hidden = true;
                    status.textContent = serverSays(j) || t.key('js.images.mine_failed');
                    return;
                }
                const per = Math.max(1, Number(j.per_page) || 1);
                const total = Math.max(0, Number(j.total) || 0);
                v.pages = Math.max(1, Math.ceil(total / per));
                v.page = Math.max(1, Math.min(Number(j.page) || n, v.pages));
                const rows = (Array.isArray(j.rows) ? j.rows : []).filter((r) => r && safeUrl(r.url));
                if (!rows.length && total > 0 && n > v.pages) { load(v.pages, focusAt); return; }
                const held = [...grid.children].findIndex((c) => c.contains(document.activeElement));
                grid.replaceChildren(...rows.map(card));
                status.textContent = rows.length ? '' : t.key('js.images.mine_empty');
                status.hidden = rows.length > 0;
                pager.hidden = v.pages <= 1;
                pageInfo.textContent = t.key('js.images.page', { page: t.num(v.page), pages: t.num(v.pages) });
                prevBtn.disabled = v.page <= 1;
                nextBtn.disabled = v.page >= v.pages;
                sync();
                const back = focusAt !== undefined ? focusAt : held;
                if (back >= 0) {
                    const thumbs = grid.querySelectorAll('.rt-images-thumb');
                    const target = thumbs.length ? thumbs[Math.min(back, thumbs.length - 1)] : mineBtn;
                    target.focus();
                }
            }

            function show(on) {
                v.open = on;
                panel.hidden = !on;
                mineBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
                if (on) {
                    panel.dataset.escAt = String(Date.now());
                    load(v.page);
                } else {
                    delete panel.dataset.escAt;
                    if (panel.contains(document.activeElement)) mineBtn.focus();
                }
            }

            v.load = load;
            v.quota = quota;
            v.sync = sync;
            v.show = show;
            return v;
        })();

        function copy(btn, text, label) {
            if (typeof window.ShareLink === 'function') { window.ShareLink(btn, text, label); return; }
            if (navigator.clipboard) navigator.clipboard.writeText(text).catch(() => {});
        }

        addBtn.addEventListener('click', () => fileIn.click());
        fileIn.addEventListener('change', () => {
            const files = Array.from(fileIn.files || []);
            fileIn.value = '';
            if (files.length) add(files, null, false);
        });
        mineBtn.addEventListener('click', () => view.show(!view.open));
        panel.addEventListener('esclayer:close', () => view.show(false));
        panel.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape' || e.defaultPrevented) return;
            e.preventDefault();
            view.show(false);
        });

        ta.addEventListener('paste', (e) => {
            const cd = e.clipboardData;
            if (!cd || cd.getData('text/plain')) return;
            const items = Array.from(cd.items || []);
            const files = (items.length ? items.filter((i) => i.kind === 'file').map((i) => i.getAsFile()) : Array.from(cd.files || []))
                .filter(isImage);
            if (!files.length) return;
            e.preventDefault();
            add(files, null, false);
        });

        [ta, root].forEach((zone) => {
            zone.addEventListener('dragover', (e) => {
                if (!hasFiles(e.dataTransfer)) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                over(true);
            });
            zone.addEventListener('dragleave', () => over(false));
            zone.addEventListener('drop', (e) => {
                if (!hasFiles(e.dataTransfer)) return;
                e.preventDefault();
                over(false);
                const files = Array.from(e.dataTransfer.files || []);
                add(files, zone === ta ? offsetFromPoint(ta, e.clientX, e.clientY) : null, zone === ta);
            });
        });
        ta.addEventListener('dragend', () => over(false));
        ta.addEventListener('blur', () => { caret = [ta.selectionStart, ta.selectionEnd]; });
        ta.addEventListener('focus', () => { caret = null; });

        if (fmtEl && fmtEl.tagName === 'SELECT') fmtEl.addEventListener('change', () => view.sync());

        const inst = { busy: () => pending > 0 };
        instances.set(ta, inst);
        return inst;
    }

    function mini(opts) {
        const ta = opts && opts.textarea;
        const place = opts && opts.place;
        const host = opts && opts.host;
        if (!ta || !place || !host) return null;
        if (instances.has(ta)) return instances.get(ta);
        const allowed = typeof APP_IMG_PLACES === 'object' && APP_IMG_PLACES ? APP_IMG_PLACES : {};
        if (!(Number(allowed[place]) > 0)) return null;
        const fmt = typeof opts.format === 'function' ? opts.format : () => 'bbcode';
        const label = t.key('js.images.mini_add');
        const btn = el('button', opts.cls || null, { type: 'button', title: label, 'aria-label': label }, [icon('bi-image')]);
        const file = el('input', null, { type: 'file', accept: 'image/*', hidden: '' });
        file.multiple = true;
        const say = el('div', 'rt-images-mini-say', { role: 'status', 'aria-live': 'polite' });
        let pending = 0;

        function put(markup) {
            const len = ta.value.length;
            const s = Math.min(typeof ta.selectionStart === 'number' ? ta.selectionStart : len, len);
            const e = Math.min(Math.max(typeof ta.selectionEnd === 'number' ? ta.selectionEnd : s, s), len);
            const before = ta.value.slice(0, s);
            const after = ta.value.slice(e);
            const ins = (before && !/\s$/.test(before) ? ' ' : '') + markup + (after && !/^\s/.test(after) ? ' ' : '');
            ta.value = before + ins + after;
            const at = before.length + ins.length;
            ta.focus();
            ta.setSelectionRange(at, at);
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }

        async function upload(files) {
            const list = Array.from(files || []).filter(isImage);
            if (!list.length) return;
            btn.disabled = true;
            for (const f of list) {
                pending++;
                say.textContent = t.key('js.images.mini_sending');
                const r = await send(f, ta, () => {}, place);
                pending--;
                const j = r && r.json;
                if (j && j.success && j.image && safeUrl(j.image.url)) {
                    put(imageMarkup(j.image.url, fmt()));
                    say.textContent = '';
                    continue;
                }
                say.textContent = serverSays(j) || t.key('js.images.mini_failed');
                if (j && STOP.indexOf(j.code) !== -1) break;
            }
            btn.disabled = false;
        }

        btn.addEventListener('mousedown', (e) => e.preventDefault());
        btn.addEventListener('click', () => file.click());
        file.addEventListener('change', () => {
            const fs = Array.from(file.files || []);
            file.value = '';
            upload(fs);
        });
        ta.addEventListener('paste', (e) => {
            const fs = e.clipboardData ? Array.from(e.clipboardData.files || []).filter(isImage) : [];
            if (!fs.length) return;
            e.preventDefault();
            upload(fs);
        });
        if (host.classList.contains('rt-tools')) host.appendChild(el('span', 'rt-tool-group', null, [btn]));
        else host.appendChild(btn);
        host.appendChild(file);
        ta.insertAdjacentElement('afterend', say);
        const inst = { busy: () => pending > 0 };
        instances.set(ta, inst);
        return inst;
    }

    window.ImagesEditor = {
        attach,
        mini,
        busy: (ta) => { const i = ta ? instances.get(ta) : null; return !!(i && i.busy()); },
        balanceRows,
        labelsBlock,
        imageMarkup,
        labelsAllowed,
    };
})();
