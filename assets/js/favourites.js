/**
 * The public-side half of favourites, profiles and "my torrents".
 *
 * Three things live here, and they share one file because they share one vocabulary — a row that
 * names a torrent, a pager, and a star:
 *
 *   · the star, delegated from `document`, so a row drawn later gets it for free;
 *   · the profile page (?action=u&name=…) and the account page's two tabs, which are the same two
 *     lists rendered from the same two endpoints with different switches;
 *   · the "who has this in favourites" overlay on the search page's Info panel.
 *
 * Everything renders through textContent / createElement: a torrent name is a stranger's text and a
 * username is a stranger's text.
 *
 * This file is loaded on every public page, like app.js, and every entry point begins by asking
 * whether its own markup is present. A page without favourites costs one querySelector.
 */
(function () {
    'use strict';

    var API = (typeof APP_API === 'string') ? APP_API : 'api.php?endpoint=';
    var BASE = (typeof APP_BASE === 'string') ? APP_BASE : '';

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'className') n.className = v;
            else if (k === 'text') n.textContent = v;
            else if (k === 'dataset') Object.keys(v).forEach(function (d) { n.dataset[d] = v[d]; });
            else n.setAttribute(k, v === true ? '' : v);
        });
        (Array.isArray(kids) ? kids : kids ? [kids] : []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
        });
        return n;
    }

    // Every public page carries a token, but under a different name each time: the search page has
    // #search-csrf, the account page #account-csrf, the forms a hidden name="csrf_token". Ask for all
    // three rather than assume — the first version of this looked for two and the account page's
    // checkboxes silently posted an empty token.
    function csrf() {
        var i = document.getElementById('search-csrf')
             || document.getElementById('account-csrf')
             || document.querySelector('input[name="csrf_token"]');
        return i ? i.value : '';
    }

    async function get(qs) {
        try {
            var r = await fetch(API + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return await r.json();
        } catch (e) { return null; }
    }

    async function post(endpoint, body) {
        try {
            body.csrf_token = csrf();
            var r = await fetch(API + endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            });
            return await r.json();
        } catch (e) { return null; }
    }

    function fmtBytes(n) {
        if (n === null || n === undefined) return '—';
        var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'], i = 0, v = Number(n);
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return (i === 0 ? v : v.toFixed(1)) + ' ' + u[i];
    }

    function magnetFor(hash, name, trackers) {
        var m = 'magnet:?xt=urn:btih:' + hash;
        if (name) m += '&dn=' + encodeURIComponent(name);
        (trackers || []).forEach(function (u) { if (u) m += '&tr=' + encodeURIComponent(u); });
        return m;
    }

    /* ───────────────────────────── the star ───────────────────────────── */

    /**
     * One delegated listener for every star on the page, present and future.
     *
     * The state lives on the button (`data-on`), not in a map, because the rows are redrawn on every
     * search and a map would have to be invalidated by whoever redrew them — which is exactly the
     * kind of bookkeeping that gets forgotten in the third place it is needed.
     */
    function paintStar(btn, on) {
        btn.dataset.on = on ? '1' : '0';
        btn.classList.toggle('fav-on', !!on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.title = on ? t('js.fav.remove') : t('js.fav.add');
        btn.setAttribute('aria-label', btn.title);
        btn.textContent = on ? '★' : '☆';
    }

    function makeStar(hash, on) {
        var b = el('button', { type: 'button', className: 'fav-star', dataset: { favHash: hash } });
        paintStar(b, on);
        return b;
    }

    document.addEventListener('click', async function (e) {
        var btn = e.target.closest ? e.target.closest('.fav-star') : null;
        if (!btn || btn.disabled) return;
        e.preventDefault();
        var hash = btn.dataset.favHash;
        if (!hash) return;
        var want = btn.dataset.on !== '1';
        btn.disabled = true;
        // Painted before the reply and put back if the reply disagrees: the star is a toggle people
        // tap in a list, and a star that waits for a round trip feels broken.
        paintStar(btn, want);
        var r = await post('user_favourites', { op: want ? 'add' : 'remove', hash: hash });
        btn.disabled = false;
        if (!r || !r.success) {
            paintStar(btn, !want);
            var msg = r && r.error === 'fav_limit' ? t('js.fav.limit', { n: r.limit }) : t('js.fav.failed');
            if (typeof showToastPub === 'function') showToastPub(msg);
            else if (window.console) window.console.warn(msg);
            return;
        }
        paintStar(btn, r.on);
        // Every star for the same hash on the page moves together — the row and the Info panel can
        // both be showing it.
        document.querySelectorAll('.fav-star[data-fav-hash="' + hash + '"]').forEach(function (o) {
            if (o !== btn) paintStar(o, r.on);
        });
        document.dispatchEvent(new CustomEvent('favourites:changed', { detail: { hash: hash, on: r.on } }));
    });

    /* ─────────────────────────── list rendering ─────────────────────────── */

    function torrentRow(r, opts) {
        var row = el('div', { className: 'pf-row' });
        var main = el('div', { className: 'pf-main' });
        if (r.name) {
            main.appendChild(el('span', { className: 'pf-name', title: r.name, text: r.name }));
        } else {
            // A favourite outlives the catalogue row on purpose: the janitor prunes index_hashes and
            // emptying somebody's list along with it would be a silent loss. The hash alone still
            // builds a working magnet.
            main.appendChild(el('span', { className: 'pf-name pf-gone', title: t('js.fav.gone_title'), text: t('js.fav.gone') }));
        }
        if (r.banned) main.appendChild(el('span', { className: 'pf-badge pf-badge-bad', text: t('js.fav.blocked') }));
        if (opts.statusBadges && r.status) {
            main.appendChild(el('span', { className: 'pf-badge pf-st-' + r.status, text: t('js.fav.status_' + r.status) }));
        }
        if (opts.statusBadges && r.content_status && r.content_status !== 'none') {
            main.appendChild(el('span', { className: 'pf-badge pf-badge-muted', text: t('js.fav.content_' + r.content_status) }));
        }
        row.appendChild(main);

        // Three cells, always three: .pf-meta is a three-column grid, and a row that skipped the
        // swarm cell used to push its hash into the swarm's column and break the alignment for the
        // whole list. An unknown swarm is a dash, which is also what the reader is owed.
        var meta = el('div', { className: 'pf-meta' });
        meta.appendChild(el('span', { text: fmtBytes(r.total_size) }));
        meta.appendChild(el('span', {
            text: (r.seeders === null || r.seeders === undefined)
                ? '—'
                : r.seeders + ' / ' + (r.leechers === null || r.leechers === undefined ? '—' : r.leechers),
        }));
        meta.appendChild(el('span', { className: 'pf-hash', text: (r.info_hash || '').slice(0, 12) }));
        row.appendChild(meta);

        var acts = el('div', { className: 'pf-acts' });
        // No magnet for a banned hash: the tracker refuses to serve it, and a link that cannot work
        // is worse than saying so.
        if (r.info_hash && !r.banned && opts.trackers) {
            acts.appendChild(el('a', { className: 'btn btn-small', href: magnetFor(r.info_hash, r.name, opts.trackers), text: 'Magnet' }));
        }
        // The same Info the search results have, opening the same panel — the markup for it is a
        // partial these pages include now. A row that names a torrent and cannot say what it IS
        // sends the reader back to the search page to type the name in again.
        if (r.info_hash && window.TorrentInfo && document.getElementById('info-overlay')) {
            var inf = el('button', { type: 'button', className: 'btn btn-secondary btn-small pf-info',
                                     title: t('js.app.info_title'), text: t('js.app.info') });
            inf.addEventListener('click', function () { window.TorrentInfo.open(r.info_hash, r.name || null); });
            acts.appendChild(inf);
        }
        if (opts.star && r.info_hash) acts.appendChild(makeStar(r.info_hash, true));
        if (opts.visibility && r.info_hash) {
            var v = el('button', { type: 'button', className: 'pf-vis' + (r.public ? ' pf-vis-on' : ''),
                                   dataset: { visHash: r.info_hash },
                                   text: r.public ? t('js.fav.public_on') : t('js.fav.public_off') });
            v.setAttribute('aria-pressed', r.public ? 'true' : 'false');
            acts.appendChild(v);
        }
        row.appendChild(acts);
        return row;
    }

    function renderPagerInto(box, page, pages, go) {
        box.textContent = '';
        if (pages <= 1) return;
        var mk = function (label, target, disabled) {
            var b = el('button', { type: 'button', text: label });
            b.disabled = !!disabled;
            b.addEventListener('click', function () { go(target); });
            return b;
        };
        box.appendChild(mk(t('js.common.pg_prev'), page - 1, page <= 1));
        box.appendChild(el('span', { className: 'pg-total', text: t('js.app.page_of', { page: page, pages: pages }) }));
        box.appendChild(mk(t('js.common.pg_next'), page + 1, page >= pages));
    }

    /**
     * A list section: search box, sort, optional status filter, rows, pager.
     * Used four times — favourites and uploads, on the profile and on the account page — because
     * they are the same thing with different switches, and a fourth copy is where a bug hides.
     */
    function listSection(cfg) {
        var listEl = document.getElementById(cfg.list);
        if (!listEl) return null;
        var pagerEl = document.getElementById(cfg.pager);
        var totalEl = document.getElementById(cfg.total);
        var searchEl = document.getElementById(cfg.search);
        var sortEl = document.getElementById(cfg.sort);
        var statusEl = cfg.status ? document.getElementById(cfg.status) : null;
        var page = 1, timer = 0;

        async function load(p) {
            page = p || 1;
            listEl.textContent = '';
            listEl.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = cfg.endpoint + '&page=' + page + '&per_page=25';
            if (cfg.user) qs += '&user=' + encodeURIComponent(cfg.user);
            if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
            if (sortEl && sortEl.value) qs += '&sort=' + encodeURIComponent(sortEl.value);
            if (statusEl && statusEl.value) qs += '&status=' + encodeURIComponent(statusEl.value);
            var j = await get(qs);
            listEl.textContent = '';
            if (!j || !j.success) {
                listEl.appendChild(el('div', { className: 'pf-empty', text: (j && j.error === 'login_required') ? t('js.app.search_login_required') : t('js.fav.load_failed') }));
                if (totalEl) totalEl.textContent = '';
                if (pagerEl) pagerEl.textContent = '';
                return;
            }
            if (!j.rows.length) {
                listEl.appendChild(el('div', { className: 'pf-empty', text: cfg.emptyText || t('js.fav.nothing') }));
            }
            j.rows.forEach(function (r) { listEl.appendChild(torrentRow(r, cfg)); });
            if (totalEl) totalEl.textContent = j.total ? t('js.app.results_many', { n: j.total.toLocaleString() }) : '';
            if (pagerEl) renderPagerInto(pagerEl, j.page, j.pages, load);
            if (typeof cfg.onLoad === 'function') cfg.onLoad(j);
        }

        var reload = function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); };
        if (searchEl) searchEl.addEventListener('input', reload);
        if (sortEl) sortEl.addEventListener('change', function () { load(1); });
        if (statusEl) statusEl.addEventListener('change', function () { load(1); });
        // Un-starring on your own list should take the row away, not leave a dead star behind.
        if (cfg.reloadOnChange) document.addEventListener('favourites:changed', function () { load(page); });
        load(1);
        return { reload: function () { load(page); } };
    }

    // "on my profile" toggles, delegated for the same reason the star is.
    document.addEventListener('click', async function (e) {
        var b = e.target.closest ? e.target.closest('.pf-vis') : null;
        if (!b || b.disabled) return;
        var want = b.getAttribute('aria-pressed') !== 'true';
        b.disabled = true;
        var r = await post('user_uploads', { op: 'visibility', hash: b.dataset.visHash, value: want ? 1 : 0 });
        b.disabled = false;
        if (!r || !r.success) return;
        b.setAttribute('aria-pressed', want ? 'true' : 'false');
        b.classList.toggle('pf-vis-on', want);
        b.textContent = want ? t('js.fav.public_on') : t('js.fav.public_off');
    });

    /** The announce URLs a magnet needs, off whichever element carries them. */
    function trackersFrom(elm) {
        if (!elm) return null;
        var t2 = [elm.dataset.announce, elm.dataset.announceHttps].filter(Boolean);
        return elm.dataset.magnet === '1' ? t2 : null;
    }

    /* ──────────────────────────── lists ─────────────────────────────
     *
     * A list is a thing somebody made: a name, the torrents they put in it, and their own answer to
     * "who may see this". So it is drawn as a CARD rather than as a row — a row is a line in a
     * table, and a list is something you open.
     *
     * The same cards render three times: the owner's account page (where they can be edited), a
     * public profile (where they cannot), and inside the picker that the Info panel opens. One
     * renderer, because a second copy is where the visibility rules would drift apart.
     */

    function listCardsInto(box, cfg) {
        var state = { lists: [], openId: 0, ctx: {} };

        function itemsBox(countHolder, list) {
            var wrap = el('div', { className: 'list-items' });
            var tools = el('div', { className: 'profile-toolbar list-items-tools' });
            var search = el('input', { type: 'text', className: 'profile-search', maxlength: 120,
                                       placeholder: t('js.fav.search_ph') });
            var sort = el('select', { title: t('js.fav.sort') });
            [['added:desc', 'sort_added'], ['name:asc', 'sort_name'], ['size:desc', 'sort_size'],
             ['seeders:desc', 'sort_seeders']].forEach(function (o) {
                sort.appendChild(el('option', { value: o[0], text: t('js.fav.' + o[1]) }));
            });
            var total = el('span', { className: 'profile-total' });
            tools.appendChild(search); tools.appendChild(sort); tools.appendChild(total);
            wrap.appendChild(tools);

            // Adding by hash or magnet, for the reason the endpoint gives: in blacklist mode there
            // is nothing to search, and somebody gathering a pack has the hashes in their hand.
            if (list.own) {
                var addRow = el('div', { className: 'list-add' });
                var addIn = el('input', { type: 'text', className: 'profile-search', maxlength: 2048,
                                          placeholder: t('js.lists.add_ph') });
                var addGo = el('button', { type: 'button', className: 'btn btn-small', text: t('js.lists.add') });
                var addMsg = el('span', { className: 'list-add-msg text-muted' });
                // Format first, in the browser, and only then a question for the server. The look of
                // an info hash is decidable here — forty hex characters, or a magnet carrying them —
                // so a typo costs no request at all, and the check that DOES cost one is debounced
                // and sits behind the same rate limit as adding.
                var hashOf = function (v) {
                    v = (v || '').trim();
                    var m = /^([0-9a-fA-F]{40})$/.exec(v);
                    if (m) return m[1].toLowerCase();
                    m = /[?&]xt=urn:btih:([0-9a-fA-F]{40})(?![0-9a-zA-Z])/.exec(v);
                    if (m) return m[1].toLowerCase();
                    if (/^magnet:\?/i.test(v) && /[?&]xt=urn:btih:[a-zA-Z2-7]{32}(?![a-zA-Z0-9])/.test(v)) return 'base32';
                    return null;
                };
                var checkTimer = 0;
                var setState = function (cls, text) {
                    addIn.classList.remove('list-add-bad', 'list-add-ok');
                    if (cls) addIn.classList.add(cls);
                    addMsg.textContent = text || '';
                };
                addIn.addEventListener('input', function () {
                    clearTimeout(checkTimer);
                    var v = addIn.value.trim();
                    if (!v) { setState(null, ''); addGo.disabled = false; return; }
                    var h = hashOf(v);
                    if (!h) { setState('list-add-bad', t('js.lists.add_failed_invalid')); addGo.disabled = true; return; }
                    setState(null, t('js.lists.checking'));
                    addGo.disabled = false;
                    if (h === 'base32') { setState(null, ''); return; }   // the server decodes those
                    checkTimer = setTimeout(async function () {
                        var r = await post('user_list_items', { op: 'check', list: list.id, magnet: v });
                        if (addIn.value.trim() !== v) return;             // they kept typing
                        if (!r || !r.success) { setState(null, ''); return; }
                        if (r.blocked) { setState('list-add-bad', t('js.lists.add_failed_blocked')); addGo.disabled = true; return; }
                        if (!r.known) { setState('list-add-bad', t('js.lists.add_failed_unknown')); addGo.disabled = true; return; }
                        setState('list-add-ok', r.name ? t('js.lists.known_named', { name: r.name }) : t('js.lists.known'));
                    }, 600);
                });
                var doAdd = async function () {
                    var v = addIn.value.trim();
                    if (!v) return;
                    addGo.disabled = true;
                    var r = await post('user_list_items', { op: 'add', list: list.id, magnet: v });
                    addGo.disabled = false;
                    if (!r || !r.success) {
                        var why = (r && r.error) === 'list_full' ? 'full'
                            : (r && r.error) === 'hash_blocked' ? 'blocked'
                            : (r && r.error) === 'hash_unknown' ? 'unknown' : 'invalid';
                        setState('list-add-bad', t('js.lists.add_failed_' + why));
                        return;
                    }
                    setState(null, '');
                    addIn.value = '';
                    addMsg.textContent = t('js.lists.added');
                    list.items = r.items;
                    var cnt = countHolder.querySelector('.list-count');
                    if (cnt) cnt.textContent = t(r.items === 1 ? 'js.lists.count_one' : 'js.lists.count_many', { n: r.items });
                    load(1);
                };
                addGo.addEventListener('click', doAdd);
                addIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
                addRow.appendChild(addIn); addRow.appendChild(addGo); addRow.appendChild(addMsg);
                wrap.appendChild(addRow);
            }

            var rows = el('div', { className: 'profile-list' });
            var pager = el('div', { className: 'trans-pagination' });
            wrap.appendChild(rows); wrap.appendChild(pager);

            var timer = 0;
            async function load(p) {
                rows.textContent = '';
                rows.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
                var qs = 'user_list_items&list=' + list.id + '&page=' + (p || 1) + '&per_page=25'
                       + '&sort=' + encodeURIComponent(sort.value);
                if (search.value.trim()) qs += '&search=' + encodeURIComponent(search.value.trim());
                var j = await get(qs);
                rows.textContent = '';
                if (!j || !j.success) {
                    rows.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') }));
                    return;
                }
                if (!j.rows.length) rows.appendChild(el('div', { className: 'pf-empty', text: t('js.lists.empty') }));
                j.rows.forEach(function (r) {
                    var row = torrentRow(r, { trackers: cfg.trackers, star: false });
                    if (list.own) {
                        var rm = el('button', { type: 'button', className: 'btn btn-secondary btn-small list-remove',
                                                title: t('js.lists.remove_title'), text: '×' });
                        rm.addEventListener('click', async function () {
                            rm.disabled = true;
                            var rr = await post('user_list_items', { op: 'remove', list: list.id, hash: r.info_hash });
                            if (!rr || !rr.success) { rm.disabled = false; return; }
                            list.items = rr.items;
                            var c2 = countHolder.querySelector('.list-count');
                            if (c2) c2.textContent = t(rr.items === 1 ? 'js.lists.count_one' : 'js.lists.count_many', { n: rr.items });
                            load(1);
                        });
                        row.querySelector('.pf-acts').appendChild(rm);
                    }
                    rows.appendChild(row);
                });
                total.textContent = j.total ? t('js.app.results_many', { n: j.total.toLocaleString() }) : '';
                renderPagerInto(pager, j.page, j.pages, load);
            }
            search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });
            sort.addEventListener('change', function () { load(1); });
            load(1);
            return wrap;
        }

        function card(list) {
            var c = el('div', { className: 'list-card' + (list.is_public ? ' list-card-public' : '') });
            // The whole card opens it. A name that happens to be a link is a target somebody has to
            // aim at; the card is the thing on the screen that IS the list.
            c.addEventListener('click', function (e) {
                if (e.target.closest('button') || e.target.closest('input') || e.target.closest('a')) return;
                openListOverlay(list, cfg, state);
            });
            var head = el('div', { className: 'list-card-head' });
            var name = el('button', { type: 'button', className: 'list-name', text: list.name, title: t('js.lists.open') });
            head.appendChild(name);
            head.appendChild(el('span', { className: 'list-count text-muted',
                text: t(list.items === 1 ? 'js.lists.count_one' : 'js.lists.count_many', { n: list.items }) }));
            if (list.is_public) head.appendChild(el('span', { className: 'pf-badge list-badge-public', text: t('js.lists.public') }));
            c.appendChild(head);
            if (list.description) c.appendChild(el('div', { className: 'list-desc text-muted', text: list.description }));

            if (list.own) {
                var acts = el('div', { className: 'list-card-acts' });
                if (state.ctx.mayPublish) {
                    var vis = el('button', { type: 'button', className: 'pf-vis' + (list.is_public ? ' pf-vis-on' : ''),
                                             text: t(list.is_public ? 'js.lists.vis_on' : 'js.lists.vis_off') });
                    vis.setAttribute('aria-pressed', list.is_public ? 'true' : 'false');
                    vis.addEventListener('click', async function () {
                        var want = !list.is_public;
                        vis.disabled = true;
                        var r = await post('user_lists', { op: 'visibility', id: list.id, value: want ? 1 : 0 });
                        vis.disabled = false;
                        if (!r || !r.success) return;
                        list.is_public = r.is_public;
                        render();
                    });
                    acts.appendChild(vis);
                }
                var ren = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.lists.rename') });
                ren.addEventListener('click', function () { renameInline(c, list); });
                acts.appendChild(ren);
                var del = el('button', { type: 'button', className: 'btn btn-secondary btn-small list-del', text: t('js.lists.delete') });
                del.addEventListener('click', async function () {
                    // Two clicks, no dialog: the second click is the confirmation, and the button
                    // says so in between. A list is somebody's work and one stray click is not consent.
                    if (del.dataset.armed !== '1') {
                        del.dataset.armed = '1';
                        del.textContent = t('js.lists.delete_sure');
                        setTimeout(function () { if (del.dataset.armed === '1') { del.dataset.armed = '0'; del.textContent = t('js.lists.delete'); } }, 4000);
                        return;
                    }
                    var r = await post('user_lists', { op: 'delete', id: list.id });
                    if (!r || !r.success) return;
                    state.lists = state.lists.filter(function (x) { return x.id !== list.id; });
                    render();
                });
                acts.appendChild(del);
                c.appendChild(acts);
            }

            name.addEventListener('click', function () { openListOverlay(list, cfg, state); });
            return c;
        }

        function renameInline(c, list) {
            var row = el('div', { className: 'list-rename' });
            var input = el('input', { type: 'text', className: 'profile-search', maxlength: 80, value: list.name });
            var go = el('button', { type: 'button', className: 'btn btn-small', text: t('js.lists.save') });
            go.addEventListener('click', async function () {
                var v = input.value.trim();
                if (!v) return;
                go.disabled = true;
                var r = await post('user_lists', { op: 'rename', id: list.id, name: v });
                go.disabled = false;
                if (!r || !r.success) return;
                list.name = r.name;
                render();
            });
            row.appendChild(input); row.appendChild(go);
            c.appendChild(row);
            input.focus();
        }

        function render() {
            box.textContent = '';
            if (!state.lists.length) {
                box.appendChild(el('div', { className: 'pf-empty', text: t('js.lists.none') }));
                return;
            }
            state.lists.forEach(function (l) { box.appendChild(card(l)); });
        }

        async function load() {
            box.textContent = '';
            box.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = 'user_lists';
            if (cfg.user) qs += '&user=' + encodeURIComponent(cfg.user);
            if (cfg.searchEl && cfg.searchEl.value.trim()) qs += '&search=' + encodeURIComponent(cfg.searchEl.value.trim());
            var j = await get(qs);
            if (!j || !j.success) {
                box.textContent = '';
                box.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') }));
                return;
            }
            state.ctx = { mayPublish: !!j.may_publish, maxLists: j.max_lists, maxItems: j.max_items };
            state.lists = (j.lists || []).map(function (l) { return Object.assign({}, l, { own: !!j.own }); });
            if (cfg.totalEl) {
                cfg.totalEl.textContent = state.lists.length
                    ? t(state.lists.length === 1 ? 'js.lists.count_lists_one' : 'js.lists.count_lists_many', { n: state.lists.length })
                    : '';
            }
            render();
        }

        // The overlay borrows this shelf's renderer: same trackers, same permissions, same rows.
        itemsBoxFor = function (holder, list) { return itemsBox(holder, list); };
        state.reload = load;
        return { load: load, create: async function (name) {
            var r = await post('user_lists', { op: 'create', name: name });
            if (r && r.success) await load();
            return r;
        } };
    }

    /**
     * One list, in a window of its own.
     *
     * It used to unfold inside its card, which put a search box, an add box and twenty-five rows
     * into a tile sized for a name and a count. The rows here are the same rows the search results
     * use and they need the width.
     */
    function openListOverlay(list, cfg, state) {
        var box = document.getElementById('list-overlay');
        if (!box) return;
        var head = document.getElementById('lo-title');
        var body = document.getElementById('lo-body');
        head.textContent = '';
        head.appendChild(el('span', { className: 'lo-name', text: list.name }));
        head.appendChild(el('span', { className: 'list-count text-muted',
            text: t(list.items === 1 ? 'js.lists.count_one' : 'js.lists.count_many', { n: list.items }) }));
        if (list.is_public) head.appendChild(el('span', { className: 'pf-badge list-badge-public', text: t('js.lists.public') }));
        body.textContent = '';
        if (list.description) body.appendChild(el('div', { className: 'list-desc text-muted', text: list.description }));
        body.appendChild(itemsBoxFor(head, list, cfg));
        box.hidden = false;
        document.addEventListener('keydown', escList);
        // The shelf behind it has to agree when this closes: the count on the card is the number
        // somebody just changed in here.
        box.dataset.reload = '1';
        window.__listReload = state && state.reload ? state.reload : null;
    }
    function escList(e) { if (e.key === 'Escape') closeListOverlay(); }
    function closeListOverlay() {
        var box = document.getElementById('list-overlay');
        if (!box) return;
        box.hidden = true;
        document.removeEventListener('keydown', escList);
        if (typeof window.__listReload === 'function') window.__listReload();
    }
    function initListOverlay() {
        var box = document.getElementById('list-overlay');
        if (!box) return;
        box.addEventListener('click', function (e) { if (e.target === box) closeListOverlay(); });
        var x = document.getElementById('lo-close');
        if (x) x.addEventListener('click', closeListOverlay);
    }
    // Set by listCardsInto() so the overlay can build its rows with that shelf's own settings.
    var itemsBoxFor = function () { return el('div'); };

    function initLists() {
        var own = document.getElementById('account-lists');
        var pub = document.getElementById('pl-cards');
        if (own) {
            var box = document.getElementById('ul-cards');
            var searchEl = document.getElementById('ul-search');
            var api = listCardsInto(box, {
                trackers: trackersFrom(own), searchEl: searchEl,
                totalEl: document.getElementById('ul-total'),
            });
            var timer = 0;
            if (searchEl) searchEl.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(api.load, 350); });
            var mk = document.getElementById('ul-new');
            if (mk) mk.addEventListener('click', function () { newListInline(own, api); });
            api.load();
        }
        if (pub) {
            var body = document.getElementById('profile-body');
            var papi = listCardsInto(pub, {
                trackers: trackersFrom(body || pub),
                user: body ? body.dataset.user : '',
                searchEl: document.getElementById('pl-search'),
                totalEl: document.getElementById('pl-total'),
            });
            var t2 = 0;
            var ps = document.getElementById('pl-search');
            if (ps) ps.addEventListener('input', function () { clearTimeout(t2); t2 = setTimeout(papi.load, 350); });
            papi.load();
        }
    }

    function newListInline(section, api) {
        var holder = section.querySelector('.profile-toolbar');
        if (!holder || holder.querySelector('.list-new-form')) return;
        var form = el('div', { className: 'list-new-form' });
        var input = el('input', { type: 'text', className: 'profile-search', maxlength: 80, placeholder: t('js.lists.new_ph') });
        var go = el('button', { type: 'button', className: 'btn btn-small', text: t('js.lists.create') });
        var msg = el('span', { className: 'text-muted list-add-msg' });
        var submit = async function () {
            var v = input.value.trim();
            if (!v) { input.focus(); return; }
            go.disabled = true;
            var r = await api.create(v);
            go.disabled = false;
            if (!r || !r.success) {
                msg.textContent = t(r && r.error === 'too_many_lists' ? 'js.lists.too_many' : 'js.fav.load_failed');
                return;
            }
            form.remove();
        };
        go.addEventListener('click', submit);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
        form.appendChild(input); form.appendChild(go); form.appendChild(msg);
        holder.after(form);
        input.focus();
    }

    /* ── the picker, opened from the Info panel ───────────────────────── */

    function initListPicker() {
        var box = document.getElementById('lp-overlay');
        if (!box) return;
        var body = document.getElementById('lp-body');
        var msg = document.getElementById('lp-msg');
        var hash = null, name = null;

        function close() { box.hidden = true; document.removeEventListener('keydown', esc); }
        function esc(e) { if (e.key === 'Escape') close(); }
        box.addEventListener('click', function (e) { if (e.target === box) close(); });
        var x = document.getElementById('lp-close');
        if (x) x.addEventListener('click', close);

        async function draw() {
            body.textContent = '';
            body.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var j = await get('user_lists&hash=' + encodeURIComponent(hash));
            body.textContent = '';
            if (!j || !j.success) { body.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') })); return; }
            if (!j.lists.length) { body.appendChild(el('div', { className: 'pf-empty', text: t('js.lists.none_yet') })); return; }
            j.lists.forEach(function (l) {
                var label = el('label', { className: 'search-check lp-row' });
                var cb = el('input', { type: 'checkbox' });
                cb.checked = !!l.has;
                label.appendChild(cb);
                label.appendChild(el('span', { className: 'search-check-box' }));
                label.appendChild(el('span', { className: 'lp-name', text: l.name }));
                label.appendChild(el('span', { className: 'text-muted lp-count',
                    text: t(l.items === 1 ? 'js.lists.count_one' : 'js.lists.count_many', { n: l.items }) }));
                cb.addEventListener('change', async function () {
                    cb.disabled = true;
                    var r = await post('user_list_items', { op: cb.checked ? 'add' : 'remove', list: l.id, magnet: hash });
                    cb.disabled = false;
                    if (!r || !r.success) {
                        cb.checked = !cb.checked;
                        msg.textContent = t(r && r.error === 'list_full' ? 'js.lists.add_failed_full'
                            : r && r.error === 'hash_blocked' ? 'js.lists.add_failed_blocked' : 'js.fav.load_failed');
                        return;
                    }
                    msg.textContent = cb.checked ? t('js.lists.added') : t('js.lists.removed');
                    // The number beside the name is the number this click just changed. Leaving it
                    // stale is how a page teaches somebody to reload it to find out what happened.
                    if (typeof r.items === 'number') {
                        l.items = r.items;
                        var c = label.querySelector('.lp-count');
                        if (c) c.textContent = t(r.items === 1 ? 'js.lists.count_one' : 'js.lists.count_many', { n: r.items });
                    }
                });
                body.appendChild(label);
            });
        }

        var go = document.getElementById('lp-new-go');
        var nameIn = document.getElementById('lp-new-name');
        if (go && nameIn) {
            var mk = async function () {
                var v = nameIn.value.trim();
                if (!v) { nameIn.focus(); return; }
                go.disabled = true;
                var r = await post('user_lists', { op: 'create', name: v });
                if (r && r.success) {
                    // Made from a torrent's panel, so the torrent goes into it: that is what the
                    // reader was doing when they typed the name.
                    var added = await post('user_list_items', { op: 'add', list: r.id, magnet: hash });
                    if (added && !added.success && added.error === 'hash_unknown') {
                        msg.textContent = t('js.lists.add_failed_unknown');
                    }
                    nameIn.value = '';
                    msg.textContent = t('js.lists.added');
                    await draw();
                } else {
                    msg.textContent = t(r && r.error === 'too_many_lists' ? 'js.lists.too_many' : 'js.fav.load_failed');
                }
                go.disabled = false;
            };
            go.addEventListener('click', mk);
            nameIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); mk(); } });
        }

        window.openListPicker = function (h, n) {
            hash = h; name = n || null;
            msg.textContent = '';
            box.hidden = false;
            document.addEventListener('keydown', esc);
            draw();
        };
    }

    /** The "+" beside the star in the Info panel. Null where lists are off or not permitted. */
    function makeListButton(hash, name) {
        var o = document.getElementById('info-overlay');
        if (!o || o.dataset.lists !== '1' || typeof window.openListPicker !== 'function') return null;
        var b = el('button', { type: 'button', className: 'search-share lp-open', title: t('js.lists.pick_title'), text: '+' });
        b.addEventListener('click', function () { window.openListPicker(hash, name); });
        return b;
    }

    /* ─────────────────────────── the profile page ─────────────────────────── */

    function initProfile() {
        var root = document.getElementById('profile-body');
        if (!root) return;
        var trackers = [root.dataset.announce, root.dataset.announceHttps].filter(Boolean);
        var user = root.dataset.user;
        var mine = root.dataset.self === '1';
        if (root.dataset.fav === '1') {
            listSection({
                endpoint: 'user_favourites', user: user, list: 'pf-fav-list', pager: 'pf-fav-pager',
                total: 'pf-fav-total', search: 'pf-fav-search', sort: 'pf-fav-sort',
                trackers: root.dataset.magnet === '1' ? trackers : null,
                star: mine, reloadOnChange: mine,
            });
        }
        if (root.dataset.uploads === '1') {
            listSection({
                endpoint: 'user_uploads', user: user, list: 'pf-up-list', pager: 'pf-up-pager',
                total: 'pf-up-total', search: 'pf-up-search', sort: 'pf-up-sort', status: 'pf-up-status',
                trackers: root.dataset.magnet === '1' ? trackers : null,
                statusBadges: true, visibility: mine,
            });
        }
    }

    /* ───────────────────── the account page's two tabs ───────────────────── */

    function initAccountTabs() {
        var host = document.getElementById('account-fav');
        if (!host) return;
        var trackers = [host.dataset.announce, host.dataset.announceHttps].filter(Boolean);
        listSection({
            endpoint: 'user_favourites', list: 'af-list', pager: 'af-pager', total: 'af-total',
            search: 'af-search', sort: 'af-sort',
            trackers: host.dataset.magnet === '1' ? trackers : null,
            star: true, reloadOnChange: true,
            emptyText: host.dataset.emptyText || '',
        });
        var up = document.getElementById('account-uploads');
        if (up) {
            listSection({
                endpoint: 'user_uploads', list: 'au-list', pager: 'au-pager', total: 'au-total',
                search: 'au-search', sort: 'au-sort', status: 'au-status',
                trackers: up.dataset.magnet === '1' ? trackers : null,
                statusBadges: true, visibility: up.dataset.mayPublish === '1',
                emptyText: up.dataset.emptyText || '',
            });
        }
    }

    /* ────────────────── "who has this in favourites" ────────────────── */

    function initWho() {
        var box = document.getElementById('who-overlay');
        if (!box) return;
        var body = document.getElementById('who-body');
        var pager = document.getElementById('who-pager');
        var search = document.getElementById('who-search');
        var hash = null, page = 1, timer = 0;

        function close() { box.hidden = true; document.removeEventListener('keydown', esc); }
        function esc(e) { if (e.key === 'Escape') close(); }
        box.addEventListener('click', function (e) { if (e.target === box) close(); });
        var x = document.getElementById('who-close');
        if (x) x.addEventListener('click', close);

        async function load(p) {
            page = p || 1;
            body.textContent = '';
            body.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = 'hash_favourites&hash=' + encodeURIComponent(hash) + '&page=' + page + '&per_page=50';
            if (search && search.value.trim()) qs += '&search=' + encodeURIComponent(search.value.trim());
            var j = await get(qs);
            body.textContent = '';
            if (!j || !j.success) { body.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') })); return; }
            var head = document.getElementById('who-total');
            if (head) head.textContent = j.total === 1 ? t('js.fav.who_one') : t('js.fav.who_count', { n: j.total.toLocaleString() });
            // An empty list here is a decision, not a failure, and it has to say so. The count above
            // is the whole truth about how many people hold this; the names below are only those who
            // agreed to be named. Without the second line the overlay reads as broken.
            if (!j.rows.length) {
                body.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.who_none') }));
                body.appendChild(el('p', { className: 'text-muted who-why', text: t('js.fav.who_why') }));
            }
            var ul = el('div', { className: 'who-names' });
            j.rows.forEach(function (r) {
                ul.appendChild(el('a', { className: 'who-name', href: BASE + '?action=u&name=' + encodeURIComponent(r.username), text: r.username }));
            });
            body.appendChild(ul);
            // The public LISTS it is on. A different answer to the same question, and often the
            // more useful one: it says what somebody keeps this WITH.
            if (j.lists && j.lists.length) {
                body.appendChild(el('div', { className: 'who-lists-head text-muted', text: t('js.lists.who_head') }));
                var lb = el('div', { className: 'who-names who-lists' });
                j.lists.forEach(function (l) {
                    // NOT .who-name: that class means "a person on this list", and a chip that is a
                    // collection is a different kind of answer. They share a look, not a meaning.
                    var a = el('a', { className: 'who-list',
                                      href: BASE + '?action=u&name=' + encodeURIComponent(l.username) + '#lists' });
                    a.appendChild(el('span', { className: 'who-list-name', text: l.name }));
                    a.appendChild(el('span', { className: 'who-list-by text-muted', text: t('js.lists.who_by', { user: l.username }) }));
                    lb.appendChild(a);
                });
                body.appendChild(lb);
            }
            if (pager) renderPagerInto(pager, j.page, j.pages, load);
        }
        if (search) search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });

        window.openWhoFavourited = function (h) {
            hash = h;
            box.hidden = false;
            document.addEventListener('keydown', esc);
            if (search) search.value = '';
            load(1);
        };
    }

    /* ─────────────────── the account page's tab bar ─────────────────── */

    function initTabs() {
        var bar = document.getElementById('acc-tabs');
        if (!bar) return;
        var panes = ['overview', 'favourites', 'uploads', 'lists'];
        function show(name) {
            if (panes.indexOf(name) === -1) name = 'overview';
            panes.forEach(function (p) {
                var el2 = document.getElementById('acc-pane-' + p);
                if (el2) el2.hidden = p !== name;
            });
            // Everything below the panes — notifications, the password form — belongs with the
            // overview: it is the account, not a list.
            var rest = document.getElementById('acc-pane-rest');
            if (rest) rest.hidden = name !== 'overview';
            bar.querySelectorAll('.rt-tab').forEach(function (b) {
                b.classList.toggle('active', b.dataset.pane === name);
            });
        }
        bar.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.rt-tab') : null;
            if (!b) return;
            // The hash, so the tab survives a reload and can be linked to. replaceState rather than
            // assigning location.hash: this is a view, not a place to come back to with Back.
            try { history.replaceState(history.state, '', '#' + b.dataset.pane); } catch (err) { /* opaque origin */ }
            show(b.dataset.pane);
        });
        show((location.hash || '').replace('#', ''));
        window.addEventListener('hashchange', function () { show((location.hash || '').replace('#', '')); });
    }

    /* ─────────────────── the two privacy checkboxes ─────────────────── */

    function initPrivacy() {
        var box = document.getElementById('acc-privacy');
        if (!box) return;
        [['acc-fav-public', 'fav_public'], ['acc-fav-listed', 'fav_listed'],
         ['acc-lists-public', 'lists_public']].forEach(function (pair) {
            var input = document.getElementById(pair[0]);
            if (!input) return;
            input.addEventListener('change', async function () {
                var body = {};
                body[pair[1]] = input.checked ? 1 : 0;
                input.disabled = true;
                var r = await post('user_privacy', body);
                input.disabled = false;
                // Put the box back if the server disagreed: a checkbox that shows a state the server
                // does not hold is worse than one that refuses to move.
                if (!r || !r.success) input.checked = !input.checked;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initPrivacy();
        initProfile();
        initAccountTabs();
        initWho();
        initListPicker();   // before initLists(): the "+" asks whether the picker exists
        initListOverlay();
        initLists();
    });

    window.Favourites = { makeStar: makeStar, paintStar: paintStar };
    // The Info panel asks for this button the way it asks for the star — app.js owns the panel and
    // knows nothing about lists, which is the point.
    window.Lists = { makeAddButton: makeListButton };
})();
