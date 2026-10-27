(function () {
    'use strict';
    if (window.UserImages) return;

    var API = (typeof APP_API === 'string') ? APP_API : 'api.php?endpoint=';
    var T = window.t;

    function el(tag, cls, attrs, kids) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (attrs) Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') n.textContent = v;
            else n.setAttribute(k, v === true ? '' : v);
        });
        (kids || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.append(c);
        });
        return n;
    }

    function glyph(name) {
        return el('i', 'bi ' + name, { 'aria-hidden': 'true' });
    }

    function iconBtn(name, label, cls) {
        return el('button', 'btn btn-secondary btn-small ic-btn' + (cls ? ' ' + cls : ''), { type: 'button', title: label, 'aria-label': label }, [glyph(name)]);
    }

    async function get(qs) {
        try {
            var r = await fetch(API + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return await r.json();
        } catch (e) {
            return null;
        }
    }

    async function post(body) {
        try {
            body.csrf_token = window.csrfToken();
            var r = await fetch(API + 'user_images', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            });
            return await r.json();
        } catch (e) {
            return null;
        }
    }

    function toast(text) {
        if (typeof window.siteToast === 'function') window.siteToast({ text: text, key: 'user-images' });
    }

    function when(s) {
        var d = new Date(String(s || '').replace(' ', 'T') + 'Z');
        return isNaN(d.getTime()) ? String(s || '') : d.toLocaleDateString(document.documentElement.lang || undefined);
    }

    var overlay = null;
    var whereSeq = 0;

    function whereOverlay() {
        if (overlay) return overlay;
        var shut = el('button', 'files-close', { type: 'button', title: T.key('js.common.close'), 'aria-label': T.key('js.common.close') }, [glyph('bi-x-lg')]);
        var body = el('div', 'files-body ug-where-body');
        var box = el('div', 'files-overlay', { id: 'ug-where-overlay', hidden: true }, [
            el('div', 'files-box ug-where-box', { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'ug-where-title' }, [
                el('div', 'files-head', null, [el('h3', null, { id: 'ug-where-title', text: T.key('js.images.ug_where') }), shut]),
                body,
            ]),
        ]);
        document.body.appendChild(box);
        var from = null;
        var onKey = function (e) { if (e.key === 'Escape') hide(); };
        var layer = typeof escLayer === 'function' ? escLayer(box, hide) : {
            on: function () { document.addEventListener('keydown', onKey); },
            off: function () { document.removeEventListener('keydown', onKey); },
        };
        function hide() {
            box.hidden = true;
            layer.off();
            whereSeq++;
            if (from && from.isConnected) { try { from.focus(); } catch (e) { from = null; } }
            from = null;
        }
        if (typeof closeOnBackdrop === 'function') closeOnBackdrop(box, hide);
        shut.addEventListener('click', hide);
        overlay = {
            body: body,
            open: function (btn) { from = btn; box.hidden = false; layer.on(); shut.focus(); },
        };
        return overlay;
    }

    async function where(btn, r) {
        var w = whereOverlay();
        var mine = ++whereSeq;
        var list = el('div', 'ug-where-list', null, [el('div', 'pf-loading who-msg', { text: T.key('js.common.loading') })]);
        w.body.replaceChildren(el('div', 'ug-where-pic', null, [
            el('img', null, { src: r.url, alt: '', decoding: 'async' }),
            el('div', 'ug-where-what', null, [
                r.name ? el('div', 'ug-name', { text: r.name, title: r.name }) : null,
                el('div', 'ug-dims', { text: T.num(r.full_w) + '×' + T.num(r.full_h) + ' · ' + T.bytes(r.bytes) }),
            ]),
        ]), list);
        w.open(btn);
        var j = await get('user_images&view=where&id=' + encodeURIComponent(r.id));
        if (mine !== whereSeq) return;
        var used = j && j.success && Array.isArray(j.used) ? j.used : null;
        if (used === null || !used.length) {
            list.replaceChildren(el('div', 'pf-empty who-msg', { text: T.key(used === null ? 'js.images.mine_failed' : 'js.images.ug_where_none') }));
            return;
        }
        list.replaceChildren.apply(list, used.map(function (u) {
            var label = u.name ? u.name : (u.page ? u.page + ' (' + u.lang + ')' : '#' + u.id);
            return el('div', 'ug-where-row', null, [
                el('span', 'ug-where-kind', { text: T.key('js.images.ug_kind_' + (u.kind || 'description')) }),
                el('a', null, { href: u.link, text: label }),
            ]);
        }));
    }

    function mount(root) {
        if (root.dataset.ugMounted) return;
        root.dataset.ugMounted = '1';
        var own = root.dataset.own === '1';
        var user = root.dataset.user || '';
        var S = { offset: 0, per: 0, filter: '', rows: [], loaded: false, seq: 0 };

        var filter = null;
        if (own) {
            filter = el('select', 'ug-filter', { 'aria-label': T.key('js.images.ug_filter') }, [
                el('option', null, { value: '', text: T.key('js.images.ug_filter_all') }),
                el('option', null, { value: 'used', text: T.key('js.images.ug_filter_used') }),
                el('option', null, { value: 'unused', text: T.key('js.images.ug_filter_unused') }),
                el('option', null, { value: 'removal', text: T.key('js.images.ug_filter_removal') }),
            ]);
            filter.addEventListener('change', function () { S.filter = filter.value; S.offset = 0; load(); });
        }
        var total = el('span', 'ug-total');
        var grid = el('ul', 'ug-grid');
        var empty = el('p', 'ug-empty', { hidden: true });
        var prev = el('button', 'btn btn-secondary btn-small', { type: 'button' }, [glyph('bi-chevron-left'), ' ', T.key('js.images.prev')]);
        var next = el('button', 'btn btn-secondary btn-small', { type: 'button' }, [T.key('js.images.next'), ' ', glyph('bi-chevron-right')]);
        var info = el('span', 'ug-page');
        var pager = el('div', 'ug-pager', { hidden: true }, [prev, info, next]);
        root.append(el('div', 'ug-toolbar', null, [filter, total]), grid, empty, pager);
        prev.addEventListener('click', function () { S.offset = Math.max(0, S.offset - S.per); load(S.per); });
        next.addEventListener('click', function () { S.offset += S.per; load(S.per); });

        function perPage() {
            var tracks = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(function (x) { return x && x !== 'none'; }).length || 1;
            return Math.max(6, Math.min(60, tracks * Math.max(1, Math.round(30 / tracks))));
        }

        function lightbox(r) {
            if (!window.SiteLightbox) { window.open(r.full_url, '_blank', 'noopener'); return; }
            var list = S.rows.map(function (x) { return { src: x.full_url, href: x.full_url, alt: x.name || '', report: false }; });
            window.SiteLightbox.get().show(list, Math.max(0, S.rows.indexOf(r)), null);
        }

        function remove(btn, li, r) {
            var q = T.key(r.uses > 0 ? 'js.images.ug_delete_used_q' : 'js.images.ug_delete_q');
            var go = async function () {
                var j = await post({ op: 'delete', id: r.id });
                if (!j || !j.success) { toast((j && j.error) || T.key('js.images.delete_failed')); return false; }
                toast(j.message || T.key('js.images.deleted'));
                load(S.per);
                return true;
            };
            if (typeof window.askInPlace === 'function') window.askInPlace(btn, q, go, { host: li });
            else if (window.confirm(q)) go();
        }

        async function keep(btn, r) {
            btn.disabled = true;
            var j = await post({ op: 'cancel', id: r.id });
            btn.disabled = false;
            if (!j || !j.success) { toast((j && j.error) || T.key('js.images.delete_failed')); return; }
            toast(j.message || '');
            load(S.per);
        }

        function card(r) {
            var img = el('img', null, { src: r.url, alt: r.name || '', loading: 'lazy', decoding: 'async', draggable: 'false' });
            if (r.w > 0 && r.h > 0) { img.width = r.w; img.height = r.h; }
            var thumb = el('button', 'ug-thumb', { type: 'button', title: T.key('js.images.ug_open'), 'aria-label': T.key('js.images.ug_open') }, [img]);
            thumb.addEventListener('click', function () { lightbox(r); });
            var badges = el('span', 'ug-badges', null, [
                own && r.removal ? el('span', 'ug-badge ug-badge-wait', { text: T.key('js.images.ug_removal_badge') }) : null,
                own && !r.removal && r.uses > 0 ? el('span', 'ug-badge', { text: T.key('js.images.ug_used', { n: T.num(r.uses) }) }) : null,
                r.animated ? el('span', 'ug-badge', { text: 'GIF' }) : null,
            ]);
            var meta = el('div', 'ug-meta', null, [
                own && r.name ? el('div', 'ug-name', { text: r.name, title: r.name }) : null,
                el('div', 'ug-dims', { text: T.num(r.full_w) + '×' + T.num(r.full_h) + ' · ' + T.bytes(r.bytes) }),
                el('div', 'ug-dims', { text: when(r.created_at) }),
            ]);
            var li = el('li', 'ug-card' + (r.removal ? ' is-removal' : ''), null, [thumb, badges.children.length ? badges : null, meta]);
            if (!own) return li;
            var acts = el('div', 'ug-acts');
            var copy = iconBtn('bi-link-45deg', T.key('js.images.ug_copy'));
            copy.addEventListener('click', async function () {
                try { await navigator.clipboard.writeText(r.url); copy.classList.add('copied'); setTimeout(function () { copy.classList.remove('copied'); }, 1200); } catch (e) { window.prompt('', r.url); }
            });
            acts.appendChild(copy);
            if (r.uses > 0) {
                var w = iconBtn('bi-diagram-3', T.key('js.images.ug_where'));
                w.setAttribute('aria-haspopup', 'dialog');
                w.addEventListener('click', function () { where(w, r); });
                acts.appendChild(w);
            }
            if (r.removal) {
                var k = el('button', 'btn btn-secondary btn-small ug-keep', { type: 'button' }, [glyph('bi-arrow-counterclockwise'), ' ', T.key('js.images.ug_cancel')]);
                k.addEventListener('click', function () { keep(k, r); });
                acts.appendChild(k);
            } else {
                var del = iconBtn('bi-trash', T.key('js.images.ug_delete'), 'ug-del');
                del.addEventListener('click', function () { remove(del, li, r); });
                acts.appendChild(del);
            }
            li.append(acts);
            return li;
        }

        async function load(keepPer) {
            var mine = ++S.seq;
            S.per = keepPer || perPage();
            var qs = 'user_images&offset=' + S.offset + '&per=' + S.per;
            if (own) qs += '&filter=' + encodeURIComponent(S.filter);
            if (user) qs += '&user=' + encodeURIComponent(user) + '&public=1';
            var j = await get(qs);
            if (mine !== S.seq) return;
            S.loaded = true;
            if (!j || !j.success) {
                grid.replaceChildren();
                empty.textContent = T.key('js.images.mine_failed');
                empty.hidden = false;
                pager.hidden = true;
                return;
            }
            S.rows = j.rows || [];
            S.offset = Number(j.offset) || 0;
            grid.replaceChildren.apply(grid, S.rows.map(card));
            total.textContent = T.plural(Number(j.total) || 0, 'js.images.ug_total', { n: T.num(Number(j.total) || 0) });
            empty.textContent = T.key(own ? (S.filter ? 'js.images.ug_none_filter' : 'js.images.ug_none') : 'js.images.ug_none_public');
            empty.hidden = S.rows.length > 0;
            pager.hidden = (Number(j.pages) || 1) <= 1;
            prev.disabled = (Number(j.page) || 1) <= 1;
            next.disabled = (Number(j.page) || 1) >= (Number(j.pages) || 1);
            info.textContent = T.key('js.images.page', { page: T.num(Number(j.page) || 1), pages: T.num(Number(j.pages) || 1) });
        }

        var resizeTimer = 0;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { if (S.loaded && root.offsetParent !== null && perPage() !== S.per) load(); }, 250);
        });
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                if (!entries.some(function (e) { return e.isIntersecting; })) return;
                io.disconnect();
                load();
            });
            io.observe(root);
        } else {
            load();
        }
    }

    function init() {
        document.querySelectorAll('.ug-root').forEach(mount);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    window.UserImages = { mount: mount };
})();
