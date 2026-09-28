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
        // Every write to a list goes through here, so this is where the list window learns that the
        // shelf behind it is out of date (1.67.0, see closeListOverlay()). Marked when the write is
        // SENT, not when it is answered: a window closed while an add is still on its way must still
        // reload once the add has landed. 'check' is the add box asking whether a hash is known —
        // a question, not a change.
        var write = !!LIST_WRITES[endpoint] && !!body && body.op !== 'check';
        if (write) listWriteStarted();
        try {
            body.csrf_token = csrf();
            var r = await fetch(API + endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            });
            return await r.json();
        } catch (e) {
            return null;
        } finally {
            if (write) listWriteDone();
        }
    }

    /* ── the shelf behind a list's window (1.67.0) ──────────────────────────────────────────────
     *
     * Closing the window used to reload the shelf every time, because the count on a card is the
     * number somebody may just have changed inside. Most openings change nothing — a list opened,
     * read and closed re-fetched the whole shelf for nothing. Now the window is DIRTY only after a
     * write to a list (an item added or removed, from its own add box or its rows, or from the
     * "Put this in a list" picker the Info panel opens over it) and only a dirty window reloads the
     * shelf when it closes. The mark is per opening: openListOverlay() clears it.
     *
     * The two endpoints that change what a shelf shows. Nothing else a page here posts does: a star
     * is a favourite, not a list.
     */
    var LIST_WRITES = { user_lists: 1, user_list_items: 1 };
    var listDirty = false;          // something changed since the window opened
    var listWrites = 0;             // list writes still on their way
    var listReloadWhenIdle = null;  // a reload that is waiting for those to land
    function listWriteStarted() { listDirty = true; listWrites++; }
    function listWriteDone() {
        listWrites = Math.max(0, listWrites - 1);
        if (listWrites === 0 && listReloadWhenIdle) {
            var go = listReloadWhenIdle;
            listReloadWhenIdle = null;
            go();
        }
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
        // The icon library's star, empty or filled (1.68.0 — it was two star characters, which every
        // library draws the same way). The label above is what a screen reader hears.
        btn.replaceChildren(el('i', { className: on ? 'bi bi-star-fill' : 'bi bi-star', 'aria-hidden': 'true' }));
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

    /**
     * Magnet and Info, the two things every row that names a torrent offers — a favourites or uploads
     * row, a list's row, and a likes / ratings table row (1.69.0), which is why they live here once.
     */
    function addMagnetInfo(acts, r, trackers) {
        // No magnet for a banned hash: the tracker refuses to serve it, and a link that cannot work
        // is worse than saying so.
        if (r.info_hash && !r.banned && trackers) {
            // The word from the dictionary the search results' Magnet uses (js.app.magnet), not a
            // literal: a translation that renames the button there has to reach these rows too.
            acts.appendChild(el('a', { className: 'btn btn-small', href: magnetFor(r.info_hash, r.name, trackers), text: t('js.app.magnet') }));
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
    }

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
        // The hash is shown short and SAID to be short (the ellipsis); a click or a tap copies the
        // whole of it, which is the only thing anybody wants a hash for.
        //
        // "Copied!" is the site's tooltip (1.62.0), centred over the chip by pubTip(), and the chip
        // keeps saying the hash. Swapping its own label for the word used to leave the shorter word
        // against the right edge of a right-aligned column — beside what was clicked rather than on
        // it. Focusable and pressable from the keyboard as well, since it is a button in all but tag.
        if (r.info_hash) {
            var full = String(r.info_hash), short = full.slice(0, 12) + '…';
            var hs = el('span', { className: 'pf-hash pf-hash-copy', text: short, title: t('js.fav.hash_copy_title'),
                                  role: 'button', tabindex: '0' });
            var copyHash = function (e) {
                e.preventDefault(); e.stopPropagation();
                var done = function () {
                    hs.classList.add('is-copied');
                    setTimeout(function () { hs.classList.remove('is-copied'); }, 1500);
                    if (typeof window.pubTip === 'function') { window.pubTip(hs, t('js.common.copied')); return; }
                    // A page without app.js has no tooltip to borrow: say it in the chip, as before.
                    hs.textContent = t('js.common.copied');
                    setTimeout(function () { hs.textContent = short; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(full).then(done, function () { /* refused */ });
            };
            hs.addEventListener('click', copyHash);
            hs.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') copyHash(e); });
            meta.appendChild(hs);
        }
        row.appendChild(meta);

        var acts = el('div', { className: 'pf-acts' });
        addMagnetInfo(acts, r, opts.trackers);
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

    /**
     * Whether this reader may search inside file names.
     *
     * The same permission the search page asks (`index.files`), carried on the Info overlay because
     * that partial is included on every page that shows one of these lists — and asked AGAIN by the
     * endpoints, which are the ones that answer. This only decides whether to draw the control.
     */
    function canSearchFiles() {
        var o = document.getElementById('info-overlay');
        return !!o && o.dataset.canFiles === '1';
    }

    /**
     * The "also search file names" checkbox, in the shape the search page uses it.
     *
     * On by default here, unlike the search page. There it is a LIKE over the whole catalogue and
     * costs something; here it is bounded by the hashes already on the list — and somebody filtering
     * their own favourites for a file they remember is the case that made this necessary.
     */
    function fileCheck() {
        if (!canSearchFiles()) return null;
        var lab = el('label', { className: 'search-check', title: t('js.fav.files_title') });
        var cb = el('input', { type: 'checkbox' });
        cb.checked = true;
        lab.appendChild(cb);
        lab.appendChild(el('span', { className: 'search-check-box' }));
        lab.appendChild(el('span', { text: t('js.fav.files') }));
        return { label: lab, box: cb };
    }

    /**
     * What a search box is asking, together with the options beside it, as one string to compare — or ''
     * while the box is empty.
     *
     * An option ("also search file names", "also search what is on them") widens or narrows what the
     * WORDS match, so with no words it asks nothing new: the list is the one already on screen, and
     * reloading it for a tick only made it flash (1.70.0 — the owner saw Favourites and the inbox
     * refresh "for no reason"). Each list keeps the key it last asked with, and an option reloads only
     * when the key it makes is a different one. The option itself is kept as ticked and acts on the
     * next search.
     */
    function searchKey(input, options) {
        var s = input ? String(input.value || '').trim() : '';
        if (s === '') return '';
        return JSON.stringify([s].concat((options || []).map(function (o) { return !!(o && o.checked); })));
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
        var filesEl = cfg.files ? document.getElementById(cfg.files) : null;
        var page = 1, timer = 0, asked = null;

        async function load(p) {
            page = p || 1;
            asked = searchKey(searchEl, [filesEl]);
            listEl.textContent = '';
            listEl.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = cfg.endpoint + '&page=' + page + '&per_page=25';
            if (cfg.user) qs += '&user=' + encodeURIComponent(cfg.user);
            if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
            if (sortEl && sortEl.value) qs += '&sort=' + encodeURIComponent(sortEl.value);
            if (statusEl && statusEl.value) qs += '&status=' + encodeURIComponent(statusEl.value);
            // Sent either way: the endpoint's own default is "yes", and a box somebody UNTICKED has
            // to be able to say so.
            if (filesEl) qs += '&files=' + (filesEl.checked ? '1' : '0');
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
        // A search option: only when it changes what is asked (searchKey()), and then at once, taking over
        // a keystroke that is still waiting to ask.
        if (filesEl) filesEl.addEventListener('change', function () {
            if (searchKey(searchEl, [filesEl]) === asked) return;
            clearTimeout(timer);
            load(1);
        });
        // Un-starring on your own list should take the row away, not leave a dead star behind.
        if (cfg.reloadOnChange) document.addEventListener('favourites:changed', function () { load(page); });
        load(1);
        return { reload: function () { load(page); } };
    }

    /**
     * A short line beside a control that has just failed.
     *
     * Written before app.js exported pubTip() (it does from 1.61.0), and kept as a line rather than
     * a tooltip on purpose: a refusal has to stay readable for longer than a tooltip's second and a
     * half. It writes a span the CSS already styles. Silence was the bug: the toggle went back
     * to where it was and nothing on the screen said the server had refused it, which reads as a
     * button that does not work.
     */
    function sayNear(node, text) {
        if (!node || !node.parentNode) return;
        var old = node.parentNode.querySelector('.pf-vis-msg');
        if (old) old.remove();
        var m = el('span', { className: 'pf-vis-msg text-muted', text: text });
        node.parentNode.insertBefore(m, node.nextSibling);
        setTimeout(function () { if (m.parentNode) m.remove(); }, 4000);
    }

    // "on my profile" toggles, delegated for the same reason the star is.
    document.addEventListener('click', async function (e) {
        var b = e.target.closest ? e.target.closest('.pf-vis') : null;
        if (!b || b.disabled) return;
        var want = b.getAttribute('aria-pressed') !== 'true';
        b.disabled = true;
        var r = await post('user_uploads', { op: 'visibility', hash: b.dataset.visHash, value: want ? 1 : 0 });
        b.disabled = false;
        if (!r || !r.success) {
            // The permission can be gone since the page was drawn, the row can have stopped being
            // theirs. Either way the reader is owed a line saying the switch did not move.
            sayNear(b, t('js.fav.failed'));
            return;
        }
        b.setAttribute('aria-pressed', want ? 'true' : 'false');
        b.classList.toggle('pf-vis-on', want);
        b.textContent = want ? t('js.fav.public_on') : t('js.fav.public_off');
    });

    /**
     * Every announce URL a magnet should carry, off whichever element carries them.
     *
     * The two from Settings AND `data-announce-extra` — the cluster's other ports, which
     * announceUrls() knows about and the two settings do not. templates/pages/search.php has emitted
     * all three for a while; a magnet built here that named only two sent the client to a fraction
     * of the swarm.
     */
    function announceFrom(elm) {
        if (!elm) return [];
        return [elm.dataset.announce, elm.dataset.announceHttps]
            .concat(String(elm.dataset.announceExtra || '').split(/\s+/))
            .filter(Boolean);
    }

    /** The same list, or null where this reader may not have magnets at all. */
    function trackersFrom(elm) {
        if (!elm) return null;
        return elm.dataset.magnet === '1' ? announceFrom(elm) : null;
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
        var state = { lists: [], openId: 0, ctx: {}, loaded: false };

        // `hooks.onList(info)` (1.70.0): what the rows' answer says about the list itself — its description,
        // drawn by the server — for the window that shows it under the name.
        function itemsBox(countHolder, list, hooks) {
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
            var files = fileCheck();
            tools.appendChild(search); tools.appendChild(sort);
            if (files) tools.appendChild(files.label);
            tools.appendChild(total);
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

            var timer = 0, asked = null;
            async function load(p) {
                asked = searchKey(search, [files && files.box]);
                rows.textContent = '';
                rows.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
                var qs = 'user_list_items&list=' + list.id + '&page=' + (p || 1) + '&per_page=25'
                       + '&sort=' + encodeURIComponent(sort.value);
                if (search.value.trim()) qs += '&search=' + encodeURIComponent(search.value.trim());
                if (files) qs += '&files=' + (files.box.checked ? '1' : '0');
                var j = await get(qs);
                rows.textContent = '';
                if (!j || !j.success) {
                    rows.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') }));
                    return;
                }
                if (hooks && typeof hooks.onList === 'function' && j.list) hooks.onList(j.list);
                if (!j.rows.length) rows.appendChild(el('div', { className: 'pf-empty', text: t('js.lists.empty') }));
                j.rows.forEach(function (r) {
                    var row = torrentRow(r, { trackers: cfg.trackers, star: false });
                    // Something has to NAME the row: the hash where the reader may have it, and the
                    // row's own id where they may not (no `index.magnet`, or a banned row — the
                    // endpoint sends info_hash = null for both). Without the id, a reader who may
                    // not build magnets could fill a list and never empty it.
                    if (list.own && (r.info_hash || r.id)) {
                        var rm = el('button', { type: 'button', className: 'btn btn-secondary btn-small list-remove',
                                                title: t('js.lists.remove_title'), 'aria-label': t('js.lists.remove_title') },
                                    el('i', { className: 'bi bi-x-lg', 'aria-hidden': 'true' }));
                        rm.addEventListener('click', async function () {
                            rm.disabled = true;
                            var body = { op: 'remove', list: list.id };
                            if (r.info_hash) body.hash = r.info_hash; else body.id = r.id;
                            var rr = await post('user_list_items', body);
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
            // A search option: only when it changes what is asked (searchKey()).
            if (files) files.box.addEventListener('change', function () {
                if (searchKey(search, [files.box]) === asked) return;
                clearTimeout(timer);
                load(1);
            });
            load(1);
            return wrap;
        }

        function card(list) {
            var c = el('div', { className: 'list-card' + (list.is_public ? ' list-card-public' : ''), dataset: { listId: String(list.id) } });
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
            // The description's first line or two (1.70.0): the server's plain excerpt of what a reader sees,
            // no markup — the whole of it, drawn, is in the list's window. The stylesheet folds it to two lines.
            if (list.excerpt) c.appendChild(el('div', { className: 'list-desc text-muted', text: String(list.excerpt) }));

            var acts = el('div', { className: 'list-card-acts' });
            // A public list has an address, and the address can be handed over — with the button the
            // rest of the site uses for that, through the same clipboard routine (window.ShareLink,
            // assets/js/app.js). A private list has no address anybody else could open.
            if (list.is_public && shareEnabled()) acts.appendChild(shareButton(list, state));
            if (list.own) {
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
                // "Edit" (1.70.0 — it was "Rename", an inline box for the name): a window with the name and the
                // description, saved together. Only where that window is on the page (your own cards).
                if (listEdit) {
                    var edit = el('button', { type: 'button', className: 'btn btn-secondary btn-small list-edit',
                                              text: t('js.lists.edit'), title: t('js.lists.edit_title') });
                    edit.addEventListener('click', function () {
                        listEdit.open(list, {
                            returnTo: edit,
                            // The card says what was saved — its name, its excerpt — drawn again from the answer,
                            // with no second request; the focus goes back to the Edit button of the new card.
                            onSaved: function () {
                                render();
                                var again = box.querySelector('.list-card[data-list-id="' + String(list.id) + '"] .list-edit');
                                if (again) again.focus();
                            },
                        });
                    });
                    acts.appendChild(edit);
                }
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
            }
            if (acts.children.length) c.appendChild(acts);

            name.addEventListener('click', function () { openListOverlay(list, cfg, state); });
            return c;
        }

        function render() {
            box.textContent = '';
            if (!state.lists.length) {
                box.appendChild(el('div', { className: 'pf-empty', text: t('js.lists.none') }));
                return;
            }
            state.lists.forEach(function (l) { box.appendChild(card(l)); });
        }

        // The two switches that widen what "matches" means, when this shelf has them (the account
        // page's does; a profile's has neither): the torrents on a list, and the file names inside
        // those torrents. Search options — see searchKey().
        function options() { return [document.getElementById('ul-items'), document.getElementById('ul-files')]; }
        var asked = null;

        async function load() {
            asked = searchKey(cfg.searchEl, options());
            box.textContent = '';
            box.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = 'user_lists';
            if (cfg.user) qs += '&user=' + encodeURIComponent(cfg.user);
            if (cfg.searchEl && cfg.searchEl.value.trim()) {
                qs += '&search=' + encodeURIComponent(cfg.searchEl.value.trim());
                var opt = options();
                if (opt[0] && opt[0].checked) qs += '&items=1';
                if (opt[1] && opt[1].checked) qs += '&files=1';
            }
            var j = await get(qs);
            if (!j || !j.success) {
                box.textContent = '';
                box.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') }));
                return;
            }
            state.ctx = { mayPublish: !!j.may_publish, maxLists: j.max_lists, maxItems: j.max_items };
            state.owner = String(j.owner || '');
            state.lists = (j.lists || []).map(function (l) { return Object.assign({}, l, { own: !!j.own }); });
            state.loaded = true;
            paintTotal();
            render();
        }
        function paintTotal() {
            if (!cfg.totalEl) return;
            cfg.totalEl.textContent = state.lists.length
                ? t(state.lists.length === 1 ? 'js.lists.count_lists_one' : 'js.lists.count_lists_many', { n: state.lists.length })
                : '';
        }
        // A live language switch (assets/js/lang-swap.js): the cards are drawn by this script, so the walk
        // has nothing to put their words against — they are drawn again from the answer the shelf already
        // has, in the new language ("Edit" / "Edytuj"), with no request. Not before the first answer.
        document.addEventListener('langswap', function () {
            if (!state.loaded) return;
            paintTotal();
            render();
        });

        // The overlay borrows this shelf's renderer: same trackers, same permissions, same rows.
        itemsBoxFor = function (holder, list, hooks) { return itemsBox(holder, list, hooks); };
        state.reload = load;
        return { load: load, changed: function () {
            // Whether the search box and its options now ask what the shelf on screen was NOT asked.
            return searchKey(cfg.searchEl, options()) !== asked;
        }, create: async function (name) {
            var r = await post('user_lists', { op: 'create', name: name });
            if (r && r.success) await load();
            return r;
        }, open: function (slug) {
            // A shared address names the list by its slug; the overlay is the list.
            var hit = null;
            state.lists.forEach(function (l) { if (!hit && l.slug === slug) hit = l; });
            if (hit) openListOverlay(hit, cfg, state);
            return !!hit;
        } };
    }

    /* ─────────────────── handing a public list to somebody ─────────────────── */

    /** Sharing is one switch for the whole site (search_share_enabled); the sections that carry lists say whether it is on. */
    function shareEnabled() {
        return !!document.querySelector('[data-share="1"]');
    }
    /** The list's address: the owner's profile, opened on that list. Absolute, because it leaves this page. */
    function listAddress(list, state) {
        var owner = (state && state.owner) || '';
        if (!owner || !list.slug) return '';
        var u;
        try { u = new URL(location.href); } catch (e) { return ''; }
        u.search = '';
        u.hash = '';
        u.searchParams.set('action', 'u');
        u.searchParams.set('name', owner);
        return u.href + '#list:' + encodeURIComponent(list.slug);
    }
    function shareButton(list, state) {
        var b = el('button', { type: 'button', className: 'btn btn-secondary btn-small share-btn list-share',
                               text: t('js.lists.share'), title: t('js.lists.share_link') });
        b.addEventListener('click', function () {
            var url = listAddress(list, state);
            if (!url) return;
            if (typeof window.ShareLink === 'function') window.ShareLink(b, url, t('js.lists.share_link'));
        });
        return b;
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
        if (list.is_public && shareEnabled()) head.appendChild(shareButton(list, state));
        body.textContent = '';
        // The description (1.70.0), under the name: the server's own drawing of it (listDescRender() —
        // the renderer, the room's emotes, no picture from elsewhere), which comes with the rows. Nothing
        // is shown before it, and nothing at all for a list without one.
        var desc = el('div', { className: 'list-desc-full rt-body', hidden: true });
        var shownHtml = null;
        body.appendChild(desc);
        body.appendChild(itemsBoxFor(head, list, { onList: function (info) {
            var html = typeof info.description_html === 'string' ? info.description_html : '';
            if (html === shownHtml) return;           // every page of rows carries it; draw it once
            shownHtml = html;
            // The server built this from fully escaped input with a fixed set of tags (includes/richtext.php),
            // exactly as the Info panel's description is put in.
            desc.innerHTML = html;
            desc.hidden = html === '';
        } }));
        box.hidden = false;
        if (listLayer) listLayer.on();
        // The shelf behind it has to agree when this closes — the count on the card is the number
        // somebody may change in here — but only if something IS changed in here (1.67.0): a fresh
        // opening starts clean, and a list write marks it (post() above). A reload the last closing
        // left waiting for a write still on its way is left alone: it still has a shelf to fix.
        listDirty = false;
        window.__listReload = state && state.reload ? state.reload : null;
    }
    /**
     * One Esc, one layer (escLayer(), assets/js/app.js, 1.70.0): a window here closes on Esc only while it is
     * the top one — the Info panel opened from a list's row, the list picker or "Who has this" over the panel,
     * the "you are leaving" dialog over any of them close first. On a page without app.js, the document's Esc.
     */
    function layerFor(box, close) {
        if (typeof escLayer === 'function') return escLayer(box, close);
        var onKey = function (e) { if (e.key === 'Escape') close(); };
        return { on: function () { document.addEventListener('keydown', onKey); },
                 off: function () { document.removeEventListener('keydown', onKey); } };
    }
    var listLayer = null;
    function closeListOverlay() {
        var box = document.getElementById('list-overlay');
        if (!box) return;
        box.hidden = true;
        if (listLayer) listLayer.off();
        // Clean: read and closed, nothing to fetch. Dirty: the shelf once — after any write still on
        // its way has landed, so the card counts what the server now holds rather than what it held a
        // moment before the add arrived.
        var reload = typeof window.__listReload === 'function' ? window.__listReload : null;
        var dirty = listDirty;
        listDirty = false;
        if (!dirty || !reload) return;
        if (listWrites > 0) listReloadWhenIdle = reload;
        else reload();
    }
    function initListOverlay() {
        var box = document.getElementById('list-overlay');
        if (!box) return;
        listLayer = layerFor(box, closeListOverlay);
        // Only when the press began on the backdrop (1.64.0, assets/js/app.js): a click goes to the
        // common ancestor of the press and the release, so a drag off the dialog raises one here.
        closeOnBackdrop(box, closeListOverlay);
        var x = document.getElementById('lo-close');
        if (x) x.addEventListener('click', closeListOverlay);
    }
    // Set by listCardsInto() so the overlay can build its rows with that shelf's own settings.
    var itemsBoxFor = function () { return el('div'); };

    /* ─────────────────── a list's description, and its Edit window (1.70.0) ─────────────────── */

    /**
     * The text a reader sees, as a strip of the syntax — the TWIN of listDescStrip() in includes/lists.php,
     * pattern for pattern: change one, change both (tests/lists_test.php reads this one against that one,
     * and scratchpad/shots/lists_check.js holds the counter to the server's number). BBCode: a picture goes
     * whole, every tag the renderer knows goes and its words stay. Markdown: a picture goes, a link is its
     * words, the marks at the start of a line and the paired ones go. Both: U+2060 (the escape of the texts
     * v80 rewrote) draws nothing — taken out AFTER the syntax, so an escaped tag counts as the text it is —
     * and every run of white space is one character, as a page draws it.
     */
    function listDescStrip(text, fmt) {
        var s = String(text || '').replace(/\r\n?/g, '\n');
        if (fmt === 'markdown') {
            s = s.replace(/!\[[^\]\n]*\]\([^) \t\n\x0B\f\r]*\)/g, '');
            s = s.replace(/\[([^\]\n]*)\]\([^) \t\n\x0B\f\r]*\)/g, '$1');
            s = s.replace(/(^|\n)[ \t]*(?:#{1,6}|>|[-*+]|\d{1,3}[.)])[ \t]+/g, '$1');
            s = s.replace(/==/g, '');
            s = s.replace(/[*~^`|]/g, '');
        } else {
            s = s.replace(/\[img(?:=[^\]\n]*)?\][\s\S]*?\[\/img\]/gi, '');
            s = s.replace(/\[\/?(?:b|i|u|s|sub|sup|color|size|font|highlight|mark|center|right|left|quote|spoiler|url|email|list|table|tr|th|td|code|hide|postshide|youtube|yt|hr|\*)(?:=[^\]\n]*)?\]/gi, '');
        }
        return s.replace(/⁠/g, '').replace(/[ \t\n\x0B\f]+/g, ' ').replace(/^ +| +$/g, '');
    }
    /** How many characters a reader sees: code points (an emoji is one, a token counts as it is typed). */
    function listDescVisible(text, fmt) { return Array.from(listDescStrip(text, fmt)).length; }

    /**
     * The Edit window (templates/partials/list_edit.php): the name and the description, saved in ONE request
     * (op `edit`). The editor is the site's shared one, mounted once (window.RichText.mount(), assets/js/app.js)
     * with the picker on its last button and a counter that counts what a reader will see (the twin above) —
     * the same number its Preview and its save give. Nothing is saved until Save.
     *
     * Leaving it, the picture editor's rules (assets/js/media-editor.js, 1.64.0): Cancel, Esc and the backdrop
     * — a press that STARTED on the backdrop (closeOnBackdrop(), assets/js/app.js) — close at once when nothing
     * is changed, and ask "Discard the changes?" in the footer when something is; the × closes at once, or
     * with something unsaved arms itself for three seconds and says so beside itself; leaving the page asks
     * the browser's own question. Esc is the window's LAST: the picker open in it and the "you are leaving"
     * dialog over it take theirs first.
     */
    var listEdit = null;
    function initListEdit() {
        var box = document.getElementById('le-overlay');
        if (!box) return null;
        var $ = function (id) { return document.getElementById(id); };
        var nameIn = $('le-name'), ta = $('le-desc'), fmtEl = $('le-desc-format');
        var save = $('le-save'), cancel = $('le-cancel'), x = $('le-close'), msg = $('le-msg');
        var ask = $('le-ask'), discard = $('le-discard'), keep = $('le-keep'), hint = $('le-close-hint');
        var help = $('le-desc-help'), writeTab = $('le-desc-tab-write');
        if (!nameIn || !ta || !save) return null;
        var max = Number(box.dataset.descMax) || 1000;
        if (window.RichText && typeof window.RichText.mount === 'function') {
            window.RichText.mount('le-desc', {
                previewFor: 'list',
                measure: function (text, f) { return { used: listDescVisible(text, f), limit: max }; },
            });
        }
        var cur = null;                 // {list, start, onSaved, returnTo} while the window is open
        var saving = false, armed = 0;

        function fmtNow() { return fmtEl && fmtEl.value === 'markdown' ? 'markdown' : 'bbcode'; }
        function now() { return { name: nameIn.value.trim(), desc: ta.value.trim(), fmt: fmtNow() }; }
        function dirty() {
            if (!cur) return false;
            var a = cur.start, b = now();
            return a.name !== b.name || a.desc !== b.desc || (b.desc !== '' && a.fmt !== b.fmt);
        }
        function say(text, bad) {
            msg.textContent = text || '';
            msg.classList.toggle('le-msg-bad', !!bad);
        }
        function asking(on) {
            ask.hidden = !on;
            save.hidden = on;
            cancel.hidden = on;
        }
        function disarm() {
            if (!armed) return;
            clearTimeout(armed);
            armed = 0;
            hint.hidden = true;
            hint.textContent = '';
        }
        function picker() {
            var all = window.EmojiPicker && typeof window.EmojiPicker.attached === 'function' ? window.EmojiPicker.attached() : {};
            return all['le-desc-picker'] || null;
        }
        function guard(e) { if (dirty() && !saving) { e.preventDefault(); e.returnValue = ''; } }

        function finish() {
            if (!cur) return;
            var back = cur.returnTo;
            disarm();
            asking(false);
            var p = picker();
            if (p) p.close();
            box.hidden = true;
            window.removeEventListener('keydown', onKey, true);
            window.removeEventListener('beforeunload', guard);
            cur = null;
            if (back && back.isConnected) { try { back.focus(); } catch (e) { /* gone */ } }
        }
        function tryClose() {
            if (!cur || saving) return;
            disarm();
            if (dirty()) { asking(true); keep.focus(); return; }
            finish();
        }
        function closeX() {
            if (!cur || saving) return;
            if (!dirty() || armed) { finish(); return; }
            hint.textContent = t('js.lists.close_again');
            hint.hidden = false;
            armed = setTimeout(disarm, 3000);
        }
        /**
         * One listener, on the WINDOW in the capture phase, so it hears a key before anything on the page —
         * and then stands aside for what sits above the window: the picker open in it (its own capture
         * listener closes its variants, clears its search, closes itself) and the "you are leaving" dialog
         * a link in the Preview opens. Neither of those keeps its Esc from a listener further out.
         */
        function onKey(e) {
            if (!cur) return;
            var p = document.getElementById('le-desc-picker');
            if (e.key === 'Escape') {
                if ((p && !p.hidden) || document.querySelector('.leave-modal')) return;
                e.preventDefault();
                if (!ask.hidden) { keep.click(); return; }
                tryClose();
                return;
            }
            // Ctrl+Enter saves from anywhere in the window (the profile's editor's key).
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && box.contains(e.target) && !(p && p.contains(e.target))) {
                e.preventDefault();
                doSave();
                return;
            }
            // Any other key takes an armed × back: an armed control that is still armed later is a trap.
            if (armed && e.key !== 'Enter' && e.key !== ' ') disarm();
        }

        async function doSave() {
            if (!cur || saving) return;
            var s = now();
            if (!s.name) { say(t('js.lists.name_required'), true); nameIn.focus(); return; }
            saving = true;
            save.disabled = true;
            cancel.disabled = true;
            disarm();
            say(t('js.lists.saving'));
            var r = await post('user_lists', { op: 'edit', id: cur.list.id, name: s.name, description: s.desc, format: s.fmt });
            saving = false;
            save.disabled = false;
            cancel.disabled = false;
            if (!cur) return;
            if (!r || !r.success) {
                say((r && r.message) || t(r && r.error === 'rate_limit' ? 'js.lists.rate_limited' : 'js.lists.edit_failed'), true);
                return;
            }
            var l = cur.list, done = cur.onSaved;
            l.name = String(r.name || s.name);
            l.description = String(r.description || '');
            l.description_format = r.description_format === 'markdown' ? 'markdown' : 'bbcode';
            l.excerpt = String(r.excerpt || '');
            cur.start = now();          // nothing unsaved any more: leaving asks nothing
            finish();
            if (typeof done === 'function') done(l);
        }

        function open(list, o) {
            if (!list || !list.own) return;
            if (cur) finish();
            o = o || {};
            cur = { list: list, start: null, onSaved: o.onSaved || null, returnTo: o.returnTo || null };
            if (writeTab) writeTab.click();              // Write, whatever the last opening was left on
            nameIn.value = String(list.name || '');
            ta.value = String(list.description || '');
            if (fmtEl && fmtEl.tagName === 'SELECT') {
                fmtEl.value = list.description_format === 'markdown' ? 'markdown' : 'bbcode';
                fmtEl.dispatchEvent(new Event('change'));   // the rail for this syntax
            }
            ta.dispatchEvent(new Event('input', { bubbles: true }));   // the counter, for this text
            if (help) { help.textContent = ''; help.classList.remove('form-hint-bad'); }
            say('');
            asking(false);
            disarm();
            saving = false;
            save.disabled = false;
            cancel.disabled = false;
            cur.start = now();
            box.hidden = false;
            window.addEventListener('keydown', onKey, true);
            window.addEventListener('beforeunload', guard);
            nameIn.focus();
            try { nameIn.setSelectionRange(nameIn.value.length, nameIn.value.length); } catch (e) { /* not a text box */ }
        }

        save.addEventListener('click', doSave);
        cancel.addEventListener('click', tryClose);
        x.addEventListener('click', closeX);
        discard.addEventListener('click', finish);
        keep.addEventListener('click', function () {
            asking(false);
            (ta.hidden ? (writeTab || nameIn) : ta).focus();
        });
        nameIn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.isComposing) { e.preventDefault(); doSave(); }
        });
        // A press anywhere but on the × takes its arming away, like any other key.
        box.addEventListener('pointerdown', function (e) { if (armed && !x.contains(e.target)) disarm(); }, true);
        closeOnBackdrop(box, tryClose);
        // A live language switch: the window's own words are the server's and are swapped by id; the two
        // this script wrote (the counter, a message) are written again or dropped.
        document.addEventListener('langswap', function () {
            disarm();
            if (!cur) return;
            if (!saving) say('');
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        });
        return { open: open, close: finish, state: function () {
            // For the browser check: what the window holds and whether leaving it would ask.
            return { open: !!cur, dirty: dirty(), saving: saving, asking: !ask.hidden, armed: !!armed,
                     name: nameIn.value, desc: ta.value, fmt: fmtNow(), visible: listDescVisible(ta.value, fmtNow()), max: max };
        }, visible: listDescVisible, strip: listDescStrip };
    }

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
            var wider = [document.getElementById('ul-items'), document.getElementById('ul-files')];
            if (searchEl) searchEl.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(api.load, 350); });
            // Search options: only when they change what is asked (searchKey()).
            wider.forEach(function (c) {
                if (c) c.addEventListener('change', function () {
                    if (!api.changed()) return;
                    clearTimeout(timer);
                    api.load();
                });
            });
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
            // A shared address (#list:<slug>) opens that list once the shelf is there to open it from.
            papi.load().then(function () {
                var h = String(location.hash || '');
                if (h.indexOf('#list:') === 0) papi.open(decodeURIComponent(h.slice(6)));
            });
        }
    }

    function newListInline(section, api) {
        var holder = section.querySelector('.profile-toolbar');
        if (!holder) return;
        // The form is inserted AFTER the toolbar, so looking for it inside the toolbar found nothing
        // and every press of "New list" added another one. Pressing it again now closes the one that
        // is open, which is what a button that opened it should do.
        var open = section.querySelector('.list-new-form');
        if (open) { open.remove(); return; }
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

    /**
     * "Put this in a list".
     *
     * ── one box, at the top ───────────────────────────────────────────────────────────────────────
     * It had a list of checkboxes and, underneath them, a separate box for naming a new one. With a
     * dozen lists the reader scrolled past all of them to reach it, and with two dozen they scrolled
     * past it to reach the list they wanted. Now there is one box: it filters while they type, and
     * the button beside it appears only when what they have typed is not a list they already have.
     * The same keystrokes either way, and no decision to make before starting.
     *
     * The lists are fetched once per opening and filtered here. They are capped by
     * lists_max_per_user, so this is a small array — a request per keystroke would be the expensive
     * way to answer a question the browser already holds the answer to.
     */
    function initListPicker() {
        var box = document.getElementById('lp-overlay');
        if (!box) return;
        var body = document.getElementById('lp-body');
        var msg = document.getElementById('lp-msg');
        var pager = document.getElementById('lp-pager');
        var go = document.getElementById('lp-new-go');
        var nameIn = document.getElementById('lp-new-name');
        var hash = null, name = null;
        var all = [], page = 1, PER = 8;

        // Over the Info panel it was opened from: its Esc closes it and not the panel (1.70.0, layerFor()).
        var layer = layerFor(box, close);
        function close() { box.hidden = true; layer.off(); }
        closeOnBackdrop(box, close);        // the press has to have STARTED on the backdrop (1.64.0)
        var x = document.getElementById('lp-close');
        if (x) x.addEventListener('click', close);

        function query() { return nameIn ? nameIn.value.trim() : ''; }

        function rowFor(l) {
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
                l.has = cb.checked;
                msg.textContent = cb.checked ? t('js.lists.added') : t('js.lists.removed');
                // The number beside the name is the number this click just changed. Leaving it
                // stale is how a page teaches somebody to reload it to find out what happened.
                if (typeof r.items === 'number') {
                    l.items = r.items;
                    var c = label.querySelector('.lp-count');
                    if (c) c.textContent = t(r.items === 1 ? 'js.lists.count_one' : 'js.lists.count_many', { n: r.items });
                }
            });
            return label;
        }

        function render() {
            var q = query().toLowerCase();
            var rows = !q ? all : all.filter(function (l) { return l.name.toLowerCase().indexOf(q) !== -1; });
            var pages = Math.max(1, Math.ceil(rows.length / PER));
            if (page > pages) page = pages;

            if (go) {
                // Offered when nothing they have answers to what they typed. While the filter is
                // still showing lists, the rows are the answer — ticking one is what this window is
                // for, and a "make another" button beside them is a second way to do one thing.
                go.hidden = !q || rows.length > 0;
                go.textContent = t('js.lists.new_named', { name: query().length > 24 ? query().slice(0, 24) + '…' : query() });
            }

            body.textContent = '';
            if (!all.length) {
                body.appendChild(el('div', { className: 'pf-empty', text: t('js.lists.none_yet') }));
            } else if (!rows.length) {
                body.appendChild(el('div', { className: 'pf-empty', text: t('js.lists.no_match') }));
            } else {
                rows.slice((page - 1) * PER, page * PER).forEach(function (l) { body.appendChild(rowFor(l)); });
            }
            renderPagerInto(pager, page, pages, function (p) { page = p; render(); });
        }

        async function load() {
            body.textContent = '';
            if (pager) pager.textContent = '';
            body.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var j = await get('user_lists&hash=' + encodeURIComponent(hash));
            if (!j || !j.success) {
                body.textContent = '';
                body.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') }));
                return;
            }
            all = j.lists || [];
            page = 1;
            render();
        }

        if (go && nameIn) {
            var mk = async function () {
                var v = query();
                if (!v) { nameIn.focus(); return; }
                go.disabled = true;
                var r = await post('user_lists', { op: 'create', name: v });
                if (r && r.success) {
                    // Made from a torrent's panel, so the torrent goes into it: that is what the
                    // reader was doing when they typed the name.
                    var added = await post('user_list_items', { op: 'add', list: r.id, magnet: hash });
                    if (added && !added.success && added.error === 'hash_unknown') {
                        msg.textContent = t('js.lists.add_failed_unknown');
                    } else {
                        msg.textContent = t('js.lists.added');
                    }
                    nameIn.value = '';
                    go.disabled = false;
                    await load();
                    return;
                }
                msg.textContent = t(r && r.error === 'too_many_lists' ? 'js.lists.too_many' : 'js.fav.load_failed');
                go.disabled = false;
            };
            go.addEventListener('click', mk);
            nameIn.addEventListener('input', function () { page = 1; render(); });
            nameIn.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                if (!go.hidden) mk();
            });
        }

        window.openListPicker = function (h, n) {
            hash = h; name = n || null;
            msg.textContent = '';
            if (nameIn) nameIn.value = '';
            box.hidden = false;
            layer.on();
            load();
            if (nameIn) nameIn.focus();
        };
    }

    /** The plus beside the star in the Info panel. Null where lists are off or not permitted. */
    function makeListButton(hash, name) {
        var o = document.getElementById('info-overlay');
        if (!o || o.dataset.lists !== '1' || typeof window.openListPicker !== 'function') return null;
        var b = el('button', { type: 'button', className: 'search-share lp-open', title: t('js.lists.pick_title'), 'aria-label': t('js.lists.pick_title') },
                   el('i', { className: 'bi bi-plus-lg', 'aria-hidden': 'true' }));
        b.addEventListener('click', function () { window.openListPicker(hash, name); });
        return b;
    }

    /* ─────────────────────────── the profile page ─────────────────────────── */

    function initProfile() {
        var root = document.getElementById('profile-body');
        if (!root) return;
        var trackers = announceFrom(root);
        var user = root.dataset.user;
        var mine = root.dataset.self === '1';
        if (root.dataset.fav === '1') {
            listSection({
                endpoint: 'user_favourites', user: user, list: 'pf-fav-list', pager: 'pf-fav-pager',
                total: 'pf-fav-total', search: 'pf-fav-search', sort: 'pf-fav-sort', files: 'pf-fav-files',
                trackers: root.dataset.magnet === '1' ? trackers : null,
                star: mine, reloadOnChange: mine,
            });
        }
        if (root.dataset.uploads === '1') {
            listSection({
                endpoint: 'user_uploads', user: user, list: 'pf-up-list', pager: 'pf-up-pager',
                total: 'pf-up-total', search: 'pf-up-search', sort: 'pf-up-sort', status: 'pf-up-status',
                trackers: root.dataset.magnet === '1' ? trackers : null,
                // Their own page, AND their group actually grants `uploads.public` — the same
                // question api/user_uploads.php asks before it writes. The account page's uploads
                // tab has drawn it from that answer for a while; here the toggle appeared for
                // anybody looking at their own profile and every press answered 403.
                statusBadges: true, visibility: mine && root.dataset.mayPublish === '1',
            });
        }
    }

    /* ───────────────────── the account page's two tabs ───────────────────── */

    function initAccountTabs() {
        var host = document.getElementById('account-fav');
        if (!host) return;
        var trackers = announceFrom(host);
        listSection({
            endpoint: 'user_favourites', list: 'af-list', pager: 'af-pager', total: 'af-total',
            search: 'af-search', sort: 'af-sort', files: 'af-files',
            trackers: host.dataset.magnet === '1' ? trackers : null,
            star: true, reloadOnChange: true,
            emptyText: host.dataset.emptyText || '',
        });
        var up = document.getElementById('account-uploads');
        if (up) {
            listSection({
                endpoint: 'user_uploads', list: 'au-list', pager: 'au-pager', total: 'au-total',
                search: 'au-search', sort: 'au-sort', status: 'au-status',
                // This section carries its own announce attributes, so it answers for itself.
                trackers: trackersFrom(up),
                statusBadges: true, visibility: up.dataset.mayPublish === '1',
                emptyText: up.dataset.emptyText || '',
            });
        }
    }

    /* ───────────────── likes / ratings: the account tab and the profile section ─────────────────
     *
     * The torrents a member voted on, as a table (1.69.0, includes/profilevotes.php): one component for
     * both places, fed by api/user_votes.php, inside the shell templates/partials/votes_section.php
     * renders. The header sorts (one column at a time, the search table's arrows), the toolbar filters
     * — in star mode by a range of the member's own rating and of the overall average, in thumbs mode by
     * which way they voted and how many votes a torrent has — and the favourites pager pages. Filters
     * and the sort survive paging; changing either goes back to page 1.
     *
     * The server decides everything a reader may see: whether the list exists for them at all, which
     * rows, whether a score is shown (never below the site's minimum number of votes), what the time
     * reads in their zone. This only draws it — with textContent, a torrent's name being a stranger's
     * text — and redraws it from the last answer on a live language switch, because the rows are the
     * one part of this table the switch cannot reach (assets/js/lang-swap.js leaves script-built rows
     * alone, and the render it fetches has an empty body).
     */

    /** Stars, in half steps, read-only: the Info panel's markup (a dim star under a clipped lit one). */
    function starsReadOnly(value) {
        var n = Math.max(0, Math.min(5, Math.round(Number(value) * 2) / 2));
        var wrap = el('span', { className: 'stars pv-stars', role: 'img',
                                'aria-label': t('js.votes.stars_aria', { n: n % 1 ? n.toFixed(1) : String(n) }) });
        for (var i = 0; i < 5; i++) {
            var full = n >= i + 1, half = !full && n >= i + 0.5;
            wrap.appendChild(el('span', { className: 'star' + (full ? ' star-full' : half ? ' star-half' : ''), 'aria-hidden': 'true' }, [
                el('span', { className: 'star-layer star-back' }, el('i', { className: 'bi bi-star-fill', 'aria-hidden': 'true' })),
                el('span', { className: 'star-layer star-front' }, el('i', { className: 'bi bi-star-fill', 'aria-hidden': 'true' })),
            ]));
        }
        return wrap;
    }

    /** A thumb, up or down — the icons of the Info panel's two buttons, filled, because this one is cast. */
    function thumbFor(vote) {
        var up = vote > 0, word = t(up ? 'js.votes.up' : 'js.votes.down');
        return el('span', { className: 'pv-thumb ' + (up ? 'pv-thumb-up' : 'pv-thumb-down'), role: 'img', 'aria-label': word, title: word },
                  el('i', { className: up ? 'bi bi-hand-thumbs-up-fill' : 'bi bi-hand-thumbs-down-fill', 'aria-hidden': 'true' }));
    }

    function initVotes() {
        var root = document.getElementById('votes-section');
        if (!root) return;
        var byId = function (id) { return document.getElementById(id); };
        var stars = root.dataset.mode === 'stars';
        var mine = root.dataset.self === '1';
        var user = root.dataset.user || '';
        var trackers = trackersFrom(root);
        var search = byId('pv-search'), files = byId('pv-files'), totalEl = byId('pv-total'), msg = byId('pv-msg');
        var wrap = byId('pv-wrap'), table = byId('pv-table'), body = byId('pv-body'), pager = byId('pv-pager');
        if (!table || !body) return;
        // The filters of this mode, by the name the endpoint reads them under, and what each one is when
        // it filters nothing.
        var ctl = stars
            ? { own_min: byId('pv-own-min'), own_max: byId('pv-own-max'), avg_min: byId('pv-avg-min'), avg_max: byId('pv-avg-max') }
            : { vote: byId('pv-vote'), min_votes: byId('pv-min-votes') };
        var IDLE = stars ? { own_min: '1', own_max: '10', avg_min: '0', avg_max: '500' } : { vote: 'all', min_votes: '0' };
        // A column's first click sorts the way a reader asks first: names A to Z, everything else the
        // most, the best or the newest first. A second click turns it round.
        var FIRST = { name: 'asc' };
        var heads = Array.prototype.slice.call(root.querySelectorAll('#pv-table .pv-sort'));
        var sort = 'date', dir = 'desc', page = 1, seq = 0, timer = 0, last = null, asked = null;

        function valueOf(k) {
            var c = ctl[k];
            var v = c ? String(c.value).trim() : '';
            return v === '' ? IDLE[k] : v;
        }

        function query(p) {
            var q = 'user_votes&page=' + p + '&per_page=25&sort=' + sort + '&dir=' + dir;
            if (user) q += '&user=' + encodeURIComponent(user);
            var s = search ? search.value.trim() : '';
            if (s) q += '&search=' + encodeURIComponent(s);
            // Sent either way, as on a favourites list: the endpoint's own default is "yes", and a box
            // somebody UNTICKED has to be able to say so.
            if (files) q += '&files=' + (files.checked ? '1' : '0');
            Object.keys(ctl).forEach(function (k) { if (ctl[k]) q += '&' + k + '=' + encodeURIComponent(valueOf(k)); });
            return q;
        }

        /** Did the answer's own parameters narrow anything? Then an empty table means "nothing matches". */
        function narrowed(pr) {
            if (!pr) return false;
            if (pr.search) return true;
            return stars ? (pr.own_min > 1 || pr.own_max < 10 || pr.avg_min > 0 || pr.avg_max < 500)
                         : (pr.vote !== 'all' || pr.min_votes > 0);
        }

        /** Put the controls on what the server actually used: a range given backwards comes back swapped. */
        function sync(pr) {
            if (!pr) return;
            Object.keys(ctl).forEach(function (k) {
                var c = ctl[k];
                if (!c || pr[k] === undefined || c === document.activeElement) return;
                if (String(c.value) !== String(pr[k])) c.value = String(pr[k]);
            });
        }

        function paintSort() {
            heads.forEach(function (b) {
                var on = b.dataset.sort === sort;
                var icon = b.querySelector('.search-sort-icon');
                // The search table's three arrows (1.68.0): both ways while idle, one way while sorting.
                if (icon) icon.className = on ? (dir === 'asc' ? 'bi bi-arrow-up search-sort-icon active' : 'bi bi-arrow-down search-sort-icon active')
                                              : 'bi bi-arrow-down-up search-sort-icon';
                b.classList.toggle('active', on);
                var th = b.closest('th');
                if (th) th.setAttribute('aria-sort', on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
            });
        }

        /** The header's own words, for the labels a phone shows inside each card (they follow a language switch). */
        function labels() {
            var out = {};
            table.querySelectorAll('thead th[data-col]').forEach(function (th) {
                var b = th.querySelector('.pv-sort');
                out[th.dataset.col] = String((b || th).textContent || '').replace(/\s+/g, ' ').trim();
            });
            return out;
        }

        function say(text) {
            wrap.hidden = true;
            msg.textContent = text;
            msg.hidden = false;
        }

        // A value is one piece: on a phone, where the header's word stands in front of it, a line may
        // break after that label but never inside the value ("2026-09-" on one line, "11" on the next).
        function cell(col, lab, value) {
            var v = typeof value === 'string' ? el('span', { className: 'pv-v', text: value }) : value;
            return el('td', { className: 'pv-cell pv-' + col, 'data-label': lab[col] || null }, v === undefined ? null : v);
        }

        function scoreCell(r, lab, min) {
            var c = cell('score', lab);
            if (!r.score_shown) {
                // Below the site's minimum a score is not a score, here as everywhere: a dash, and the
                // count that is still missing in the tooltip.
                c.textContent = '—';
                c.classList.add('text-muted');
                c.title = t(stars ? 'js.votes.too_few_stars' : 'js.votes.too_few_thumbs', { n: r.votes_count, min: min });
                return c;
            }
            if (stars) {
                var s = (r.score_x100 / 100).toFixed(1);
                c.classList.add('search-rep-stars');
                c.appendChild(document.createTextNode(s + ' '));
                c.appendChild(el('i', { className: 'bi bi-star-fill', 'aria-hidden': 'true' }));
                c.title = t('js.votes.avg_title', { stars: s, n: r.votes_count });
            } else {
                var pct = Math.round(r.score_x100 / 100);
                c.classList.add(pct >= 50 ? 'search-rep-up' : 'search-rep-down');
                c.textContent = pct + '%';
                c.title = t('js.votes.score_title', { pct: pct, up: r.votes_up, down: r.votes_down });
            }
            return c;
        }

        function rowFor(r, lab, min) {
            var tr = el('tr');
            var name = el('td', { className: 'pv-cell pv-name' });
            if (r.name) {
                name.appendChild(el('span', { className: 'pv-title', title: r.name, text: r.name }));
            } else {
                // Gone from the catalogue (or not the reader's to see): the vote is still theirs.
                name.appendChild(el('span', { className: 'pf-gone', title: t('js.fav.gone_title'), text: t('js.fav.gone') }));
            }
            if (r.banned) name.appendChild(el('span', { className: 'pf-badge pf-badge-bad', text: t('js.fav.blocked') }));
            tr.appendChild(name);
            tr.appendChild(cell('size', lab, fmtBytes(r.total_size)));
            tr.appendChild(cell('sl', lab, r.seeders === null || r.seeders === undefined ? '—'
                : r.seeders + ' / ' + (r.leechers === null || r.leechers === undefined ? '—' : r.leechers)));
            tr.appendChild(cell('own', lab, stars ? starsReadOnly(r.own_vote / 2) : thumbFor(r.own_vote)));
            tr.appendChild(scoreCell(r, lab, min));
            tr.appendChild(cell('votes', lab, Number(r.votes_count || 0).toLocaleString()));
            // The date in the cell, the whole moment with its offset in the tooltip — both in the
            // reader's own zone, as the server wrote them.
            var when = cell('date', lab, r.voted_at ? String(r.voted_at).slice(0, 10) : '—');
            if (r.voted_full) when.title = r.voted_full;
            tr.appendChild(when);
            var acts = el('div', { className: 'pf-acts pv-acts-in' });
            addMagnetInfo(acts, r, trackers);
            tr.appendChild(el('td', { className: 'pv-cell pv-acts' }, acts));
            return tr;
        }

        function render() {
            var j = last;
            if (!j) return;
            paintSort();
            totalEl.textContent = j.total ? t('js.votes.total', { n: Number(j.total).toLocaleString() }) : '';
            body.textContent = '';
            if (!j.rows.length) {
                say(narrowed(j.params) ? t('js.votes.none_match')
                    : mine ? t(stars ? 'js.votes.none_own_stars' : 'js.votes.none_own_thumbs') : t('js.fav.nothing'));
                pager.textContent = '';
                return;
            }
            var lab = labels();
            j.rows.forEach(function (r) { body.appendChild(rowFor(r, lab, j.min_votes)); });
            msg.hidden = true;
            wrap.hidden = false;
            renderPagerInto(pager, j.page, j.pages, load);
        }

        async function load(p) {
            var mySeq = ++seq;
            page = p || 1;
            asked = searchKey(search, [files]);
            // The table stays while the next page is fetched, dimmed as the search table dims — only the
            // very first load has nothing to show and says so.
            if (last) table.classList.add('search-loading');
            var j = await get(query(page));
            if (mySeq !== seq) return;      // a newer question has been asked since: its answer is the one to draw
            table.classList.remove('search-loading');
            if (!j || !j.success) {
                last = null;
                totalEl.textContent = '';
                pager.textContent = '';
                say(j && j.error === 'login_required' ? t('js.app.search_login_required') : t('js.fav.load_failed'));
                return;
            }
            last = j;
            page = j.page;
            sync(j.params);
            render();
        }

        heads.forEach(function (b) {
            b.addEventListener('click', function () {
                var key = b.dataset.sort;
                if (key === sort) dir = dir === 'asc' ? 'desc' : 'asc';
                else { sort = key; dir = FIRST[key] || 'desc'; }
                paintSort();
                load(1);
            });
        });
        if (search) search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });
        // A search option, not a filter: only when it changes what is asked (searchKey()). The filters
        // below narrow the rows whatever the box says, so every change of theirs asks again.
        if (files) files.addEventListener('change', function () {
            if (searchKey(search, [files]) === asked) return;
            clearTimeout(timer);
            load(1);
        });
        Object.keys(ctl).forEach(function (k) {
            var c = ctl[k];
            if (!c) return;
            if (c.tagName === 'SELECT') c.addEventListener('change', function () { load(1); });
            else c.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 400); });
        });
        // Redrawn from the last answer in the new language — no second request for words. Without one (the
        // first request failed, or is still on its way) there is nothing to redraw, and the switch has just
        // put the render's "Loading…" back into the message: ask again, in the new language.
        document.addEventListener('langswap', function () { if (last) render(); else load(page); });
        // A vote changed in the Info panel opened from one of these rows (assets/js/app.js): the row is
        // out of date, and this is the one list on the site that is about exactly that.
        document.addEventListener('rating:changed', function () { load(page); });
        load(1);
    }

    /* ───────────── the descriptions a member wrote: the account tab and the profile section ─────────────
     *
     * The torrents a member described, or co-wrote with an edit (1.70.0, includes/profiledescs.php): the
     * likes table's look and its rules — one component for both places, fed by api/user_descriptions.php,
     * inside the shell templates/partials/descs_section.php renders; the header sorts (one column at a
     * time: the name A to Z first, the part and the date biggest / newest first), the search box narrows,
     * the pager pages and keeps both. Each row: the name and a line of what the description says, the
     * member's part in it — "Author", "Co-author, 25%", or (their own list only) an edit or a rewrite of
     * theirs still waiting or turned down — its state where it is not simply published, the date in the
     * reader's zone, Magnet and Info. Drawn with textContent; redrawn from the last answer on a live
     * language switch.
     */
    function initDescs() {
        var root = document.getElementById('descs-section');
        if (!root) return;
        var byId = function (id) { return document.getElementById(id); };
        var mine = root.dataset.self === '1';
        var user = root.dataset.user || '';
        var trackers = trackersFrom(root);
        var search = byId('pd-search'), totalEl = byId('pd-total'), msg = byId('pd-msg');
        var wrap = byId('pd-wrap'), table = byId('pd-table'), body = byId('pd-body'), pager = byId('pd-pager');
        if (!table || !body) return;
        var FIRST = { name: 'asc' };
        var heads = Array.prototype.slice.call(root.querySelectorAll('#pd-table .pv-sort'));
        var sort = 'date', dir = 'desc', page = 1, seq = 0, timer = 0, last = null;

        function query(p) {
            var q = 'user_descriptions&page=' + p + '&per_page=25&sort=' + sort + '&dir=' + dir;
            if (user) q += '&user=' + encodeURIComponent(user);
            var s = search ? search.value.trim() : '';
            if (s) q += '&search=' + encodeURIComponent(s);
            return q;
        }
        function paintSort() {
            heads.forEach(function (b) {
                var on = b.dataset.sort === sort;
                var icon = b.querySelector('.search-sort-icon');
                if (icon) icon.className = on ? (dir === 'asc' ? 'bi bi-arrow-up search-sort-icon active' : 'bi bi-arrow-down search-sort-icon active')
                                              : 'bi bi-arrow-down-up search-sort-icon';
                b.classList.toggle('active', on);
                var th = b.closest('th');
                if (th) th.setAttribute('aria-sort', on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
            });
        }
        function labels() {
            var out = {};
            table.querySelectorAll('thead th[data-col]').forEach(function (th) {
                var b = th.querySelector('.pv-sort');
                out[th.dataset.col] = String((b || th).textContent || '').replace(/\s+/g, ' ').trim();
            });
            return out;
        }
        function say(text) { wrap.hidden = true; msg.textContent = text; msg.hidden = false; }
        function roleText(r) {
            if (r.role === 'author') return t('js.descs.role_author');
            if (r.role === 'coauthor') return t('js.descs.role_coauthor', { pct: r.share });
            return t(r.role === 'edit' ? 'js.descs.role_edit' : 'js.descs.role_rewrite');
        }
        function rowFor(r, lab) {
            var tr = el('tr');
            var name = el('td', { className: 'pv-cell pv-name pd-name' });
            var head = el('div', { className: 'pd-head' });
            if (r.name) head.appendChild(el('span', { className: 'pv-title', title: r.name, text: r.name }));
            else head.appendChild(el('span', { className: 'pf-gone', title: t('js.fav.gone_title'), text: t('js.fav.gone') }));
            if (r.banned) head.appendChild(el('span', { className: 'pf-badge pf-badge-bad', text: t('js.fav.blocked') }));
            // Its state, where it is anything but published (only its own reader is ever sent one).
            if (r.status && r.status !== 'published') {
                head.appendChild(el('span', { className: 'pf-badge pd-st pd-st-' + r.status, text: t('js.descs.st_' + r.status) }));
            }
            name.appendChild(head);
            // What it says, a line or two — or, for a description that is a source link alone, the link.
            var ex = r.excerpt ? r.excerpt : (r.source_url ? t('js.descs.excerpt_source', { url: r.source_url }) : '');
            if (ex) name.appendChild(el('div', { className: 'pd-excerpt text-muted', title: ex, text: ex }));
            tr.appendChild(name);
            tr.appendChild(el('td', { className: 'pv-cell pd-role', 'data-label': lab.role || null },
                              el('span', { className: 'pv-v', text: roleText(r) })));
            var when = el('td', { className: 'pv-cell pv-date pd-date', 'data-label': lab.date || null },
                          el('span', { className: 'pv-v', text: r.at ? String(r.at).slice(0, 10) : '—' }));
            if (r.at_full) when.title = r.at_full;
            tr.appendChild(when);
            var acts = el('div', { className: 'pf-acts pv-acts-in' });
            addMagnetInfo(acts, r, trackers);
            tr.appendChild(el('td', { className: 'pv-cell pv-acts' }, acts));
            return tr;
        }
        function render() {
            var j = last;
            if (!j) return;
            paintSort();
            totalEl.textContent = j.total ? t('js.descs.total', { n: Number(j.total).toLocaleString() }) : '';
            body.textContent = '';
            if (!j.rows.length) {
                say(j.params && j.params.search ? t('js.descs.none_match') : (mine ? t('js.descs.none_own') : t('js.fav.nothing')));
                pager.textContent = '';
                return;
            }
            var lab = labels();
            j.rows.forEach(function (r) { body.appendChild(rowFor(r, lab)); });
            msg.hidden = true;
            wrap.hidden = false;
            renderPagerInto(pager, j.page, j.pages, load);
        }
        async function load(p) {
            var mySeq = ++seq;
            page = p || 1;
            if (last) table.classList.add('search-loading');
            var j = await get(query(page));
            if (mySeq !== seq) return;
            table.classList.remove('search-loading');
            if (!j || !j.success) {
                last = null;
                totalEl.textContent = '';
                pager.textContent = '';
                say(j && j.error === 'login_required' ? t('js.app.search_login_required') : t('js.fav.load_failed'));
                return;
            }
            last = j;
            page = j.page;
            render();
        }
        heads.forEach(function (b) {
            b.addEventListener('click', function () {
                var key = b.dataset.sort;
                if (key === sort) dir = dir === 'asc' ? 'desc' : 'asc';
                else { sort = key; dir = FIRST[key] || 'desc'; }
                paintSort();
                load(1);
            });
        });
        if (search) search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });
        document.addEventListener('langswap', function () { if (last) render(); else load(page); });
        load(1);
    }

    /* ──────── "who has this in favourites" — and, since 1.70.0, who liked or rated it, and its lists ──────── */

    /**
     * "Who has this" (templates/partials/info_overlay.php, api/hash_who.php, includes/who.php): up to three
     * sections, in this order, each drawn only where the server says this reader may open it — Favourites, Likes
     * or Ratings (by the rating mode: a thumb beside each name, or their stars, half stars included), Lists (the
     * list's name, "by" its owner, opening it on the owner's profile as a list's Share link does). When the
     * overlay opens every section asks for its first 20 at once — in parallel, each on its own; then each has a
     * search of its own (by name, shown once there is more than one page to search) and "Show more" for the next
     * 20. An empty section says so, in its own words; one the server no longer opens (switched off since the page
     * was drawn) is hidden. Everything is drawn with textContent, a name being a stranger's text, and drawn again
     * from what it holds on a live language switch (lang-swap.js leaves these containers alone: data-lang-keep).
     */
    function initWho() {
        var box = document.getElementById('who-overlay');
        if (!box) return;
        var PER = 20;
        var secs = Array.prototype.slice.call(box.querySelectorAll('.who-sec[data-sec]')).map(function (root) {
            var name = root.dataset.sec;
            return {
                name: name, root: root, mode: root.dataset.mode === 'stars' ? 'stars' : 'thumbs',
                body: document.getElementById('who-' + name + '-body'), count: document.getElementById('who-' + name + '-count'),
                search: document.getElementById('who-' + name + '-search'), more: document.getElementById('who-' + name + '-more'),
                rows: [], total: 0, page: 0, q: '', seq: 0, state: 'idle', many: false, timer: 0,
            };
        }).filter(function (s) { return s.body && s.count && s.more; });
        var hash = null, returnTo = null;
        var layer = layerFor(box, close);   // one Esc, one layer: the Info panel under it stays

        function close() {
            box.hidden = true;
            layer.off();
            // An answer still in the air belongs to this opening, not to the next.
            secs.forEach(function (s) { clearTimeout(s.timer); s.seq++; });
            if (returnTo && returnTo.isConnected) { try { returnTo.focus(); } catch (e) { /* gone */ } }
            returnTo = null;
        }
        closeOnBackdrop(box, close);        // the press has to have STARTED on the backdrop (1.64.0)
        var x = document.getElementById('who-close');
        if (x) x.addEventListener('click', close);

        var lang = function () { return document.documentElement.lang || undefined; };
        var num = function (n) { return Number(n).toLocaleString(lang()); };
        // The picture beside each name (1.63.0), from the ADDRESS the server built (these rows never carry an
        // id); window.userAvatarImg() is assets/js/avatar.js, and answers null while pictures are switched off.
        var face = function (name, address) {
            return typeof window.userAvatarImg === 'function'
                ? window.userAvatarImg({ username: name, avatar: String(address || '') }, 20, 'avatar who-av') : null;
        };

        /** A person: their picture and name, a link to their profile — and, among the likes, their vote. */
        function personChip(s, r) {
            var a = el('a', { className: 'who-name' + (s.name === 'votes' ? ' who-voter' : ''),
                              href: BASE + '?action=u&name=' + encodeURIComponent(r.username) },
                       [face(r.username, r.avatar), el('span', { className: 'who-name-text', text: r.username })]);
            if (s.name === 'votes') a.appendChild(s.mode === 'stars' ? starsReadOnly(Number(r.vote) / 2) : thumbFor(Number(r.vote)));
            return a;
        }
        /**
         * A list: NOT .who-name — that class means "a person on this list", and a chip that is a collection is a
         * different kind of answer; they share a look, not a meaning. The address is the LIST (`#list:<slug>`,
         * the address its Share button hands out and the profile page opens), not the shelf it sits on. The
         * owner's picture sits right before their NAME inside "by <name>", wherever the language puts the name.
         */
        function listChip(r) {
            var a = el('a', { className: 'who-list',
                              href: BASE + '?action=u&name=' + encodeURIComponent(r.username) + '#list:' + encodeURIComponent(r.slug || '') });
            a.appendChild(el('span', { className: 'who-list-name', text: r.name }));
            var by = el('span', { className: 'who-list-by text-muted' });
            if (typeof window.userAvatarPhrase === 'function') {
                by.appendChild(window.userAvatarPhrase(t('js.lists.who_by', { user: window.userAvatarSlot(0) }),
                                                       [[face(r.username, r.avatar), r.username]]));
            } else {
                by.textContent = t('js.lists.who_by', { user: r.username });
            }
            a.appendChild(by);
            return a;
        }
        function countText(s) {
            if (s.q !== '') return t('js.who.found', { n: num(s.total) });
            if (s.name === 'lists') return s.total === 1 ? t('js.who.lists_one') : t('js.who.lists_n', { n: num(s.total) });
            return s.total === 1 ? t('js.who.people_one') : t('js.who.people_n', { n: num(s.total) });
        }
        // An empty section is a decision, not a failure, and says so in its own words; the line under the
        // sections (#who-why, the server's) says why a section can be empty.
        function emptyText(s) {
            if (s.q !== '') return t('js.who.no_match');
            if (s.name === 'fav') return t('js.who.fav_none');
            if (s.name === 'lists') return t('js.who.lists_none');
            return s.mode === 'stars' ? t('js.who.votes_none_stars') : t('js.who.votes_none_thumbs');
        }

        /** The section as it stands: its rows, its count, its "Show more", its search box. */
        function draw(s) {
            s.body.textContent = '';
            var busyFirst = s.state === 'loading' && !s.rows.length;
            if (busyFirst) s.body.appendChild(el('div', { className: 'pf-loading who-msg', text: t('js.common.loading') }));
            else if (s.state === 'error') s.body.appendChild(el('div', { className: 'pf-empty who-msg', text: t('js.fav.load_failed') }));
            else if (s.state === 'limited') s.body.appendChild(el('div', { className: 'pf-empty who-msg', text: t('js.who.rate_limited') }));
            else if (!s.rows.length) s.body.appendChild(el('div', { className: 'pf-empty who-msg', text: emptyText(s) }));
            else {
                var wrap = el('div', { className: 'who-names' + (s.name === 'lists' ? ' who-lists' : '') });
                s.rows.forEach(function (r) { wrap.appendChild(s.name === 'lists' ? listChip(r) : personChip(s, r)); });
                s.body.appendChild(wrap);
            }
            s.count.textContent = s.state === 'ok' || (s.state === 'loading' && s.rows.length) ? countText(s) : '';
            var left = s.total - s.rows.length;
            s.more.hidden = !(left > 0 && (s.state === 'ok' || s.state === 'loading'));
            s.more.disabled = s.state === 'loading';
            s.more.textContent = s.state === 'loading' ? t('js.common.loading') : t('js.who.more', { n: num(Math.min(left, PER)) });
            // Something to search: more than one page of it, or a search already typed (so it can be cleared).
            if (s.search) s.search.hidden = !(s.many || s.q !== '' || s.search.value !== '');
        }

        /** Page one (a new question) or the next page (append). The newest question wins; an older answer is dropped. */
        async function load(s, append) {
            var mine = ++s.seq;
            var page = append ? s.page + 1 : 1;
            if (!append) { s.rows = []; s.total = 0; s.page = 0; }
            s.state = 'loading';
            draw(s);
            var qs = 'hash_who&section=' + s.name + '&hash=' + encodeURIComponent(hash) + '&page=' + page + '&per_page=' + PER;
            if (s.q !== '') qs += '&search=' + encodeURIComponent(s.q);
            var j = await get(qs);
            if (mine !== s.seq) return;
            if (j && j.error === 'not_found') { s.state = 'gone'; s.root.hidden = true; return; }
            if (j && j.error === 'rate_limit') { s.state = 'limited'; draw(s); return; }
            if (!j || !j.success || !Array.isArray(j.rows)) { s.state = 'error'; draw(s); return; }
            if (s.name === 'votes' && (j.mode === 'stars' || j.mode === 'thumbs')) s.mode = j.mode;
            s.rows = append ? s.rows.concat(j.rows) : j.rows.slice();
            s.total = Number(j.total) || 0;
            s.page = Number(j.page) || page;
            if (s.q === '' && s.total > PER) s.many = true;
            s.state = 'ok';
            draw(s);
        }

        secs.forEach(function (s) {
            s.more.addEventListener('click', function () { if (s.state === 'ok') load(s, true); });
            if (!s.search) return;
            // Every keystroke (debounced) asks again, as the 1.69.0 box did — the rows may have changed under an
            // unchanged word — and Enter asks at once.
            s.search.addEventListener('input', function () {
                clearTimeout(s.timer);
                s.timer = setTimeout(function () { s.q = s.search.value.trim(); load(s, false); }, 350);
            });
            s.search.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.isComposing) return;
                e.preventDefault();
                clearTimeout(s.timer);
                s.q = s.search.value.trim();
                load(s, false);
            });
        });
        // A live language switch: the server's words (the headings, the placeholders, the line under them) are
        // swapped by id; the ones written here are written again from what each section holds.
        document.addEventListener('langswap', function () { secs.forEach(draw); });

        window.openWhoFavourited = function (h) {
            hash = h;
            returnTo = document.activeElement;
            secs.forEach(function (s) {
                clearTimeout(s.timer);
                s.q = ''; s.many = false; s.root.hidden = false;
                if (s.search) s.search.value = '';
            });
            box.hidden = false;
            layer.on();
            // All the sections' first pages at once: three requests in the air together, each drawn as it lands.
            secs.forEach(function (s) { load(s, false); });
        };
        // For the browser checks: what each section holds.
        window.WhoHas = { state: function () {
            return secs.map(function (s) { return { sec: s.name, state: s.state, rows: s.rows.length, total: s.total, page: s.page, q: s.q, mode: s.mode, hidden: s.root.hidden }; });
        } };
    }

    /* ─────────────────── the account page's tab bar ─────────────────── */

    function initTabs() {
        var bar = document.getElementById('acc-tabs');
        if (!bar) return;
        // Every pane the page may carry: a name missing here falls back to the overview (1.69.0 added
        // `votes`, the likes / ratings tab right after Favourites; 1.70.0 `descriptions`, whose TAB sits
        // right after that one — the order of the tabs is the template's, this list is only who exists).
        var panes = ['overview', 'favourites', 'votes', 'uploads', 'lists', 'messages', 'people', 'members', 'sounds', 'descriptions'];
        function show(name) {
            // `#messages:somebody` opens the inbox AT that conversation — the part before the colon
            // is the pane, the rest belongs to people.js. A tab bar that did not know that fell
            // back to the overview and left the reader looking at their e-mail preferences.
            name = String(name || '').split(':')[0];
            // A name the router knows is not yet a pane THIS page carries: a link kept from before a
            // feature was switched off (#votes, #sounds, #messages) found no pane by that name and hid
            // every pane there was — an empty page. It lands on the overview instead (1.69.0).
            if (panes.indexOf(name) === -1 || !document.getElementById('acc-pane-' + name)) name = 'overview';
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
            // An inbox that is being shown again is an inbox somebody came back to. assets/js/people.js
            // owns it; this only says "you are on screen now".
            // The directory is a whole page of strangers; it is fetched the first time somebody
            // asks to see it, not on every visit to the account page.
            if (name === 'members' && window.Directory && typeof window.Directory.refresh === 'function') {
                window.Directory.refresh();
            }
            if (name === 'messages' && window.PM && typeof window.PM.refresh === 'function') {
                var who = String(location.hash || '').split(':')[1];
                window.PM.refresh(who ? decodeURIComponent(who) : null);
            }
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
        // Who may write to me is a choice of four, not a checkbox: the empty value means "whatever
        // the site says", which is a real answer and not the absence of one.
        var who = document.getElementById('acc-pm-who');
        if (who) {
            who.addEventListener('change', async function () {
                who.disabled = true;
                var r = await post('user_privacy', { pm_who: who.value });
                who.disabled = false;
                if (!r || !r.success) return;
            });
        }
        [['acc-fav-public', 'fav_public'], ['acc-fav-listed', 'fav_listed'], ['acc-votes-public', 'votes_public'], ['acc-votes-listed', 'votes_listed'],
         ['acc-descs-public', 'descriptions_public'], ['acc-credit-public', 'content_credit_public'],
         ['acc-lists-public', 'lists_public'], ['acc-profile-listed', 'profile_listed']].forEach(function (pair) {
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
                if (!r || !r.success) { input.checked = !input.checked; return; }
                // With the name hidden the descriptions list is shown to nobody else either (1.70.0): the
                // sentence under that switch follows the name switch as the server now holds it.
                var hiddenNote = document.getElementById('acc-descs-name-hidden');
                if (hiddenNote && typeof r.content_credit_public === 'boolean') hiddenNote.hidden = r.content_credit_public;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initPrivacy();
        initProfile();
        initAccountTabs();
        initVotes();
        initDescs();
        initWho();
        initListPicker();   // before initLists(): the "+" asks whether the picker exists
        initListOverlay();
        listEdit = initListEdit();  // before initLists() too: a card draws Edit only where the window is
        initLists();
    });

    window.Favourites = { makeStar: makeStar, paintStar: paintStar };
    // The Edit window, for the browser checks (its state and the counter's twin).
    window.ListEdit = { get: function () { return listEdit; }, visible: listDescVisible, strip: listDescStrip };
    // The Info panel asks for this button the way it asks for the star — app.js owns the panel and
    // knows nothing about lists, which is the point.
    window.Lists = { makeAddButton: makeListButton };
})();
