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
        var panes = ['overview', 'favourites', 'uploads'];
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
        [['acc-fav-public', 'fav_public'], ['acc-fav-listed', 'fav_listed']].forEach(function (pair) {
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
    });

    window.Favourites = { makeStar: makeStar, paintStar: paintStar };
})();
