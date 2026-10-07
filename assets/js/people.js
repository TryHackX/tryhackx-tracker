/**
 * People: the inbox, one conversation, friends and blocks, the directory, and the buttons a public
 * profile grows when any of it is switched on.
 *
 * Its own file rather than more of favourites.js, because it is a different subject: that one is
 * about torrents somebody kept, this one is about people reaching each other. Both are loaded on
 * every public page and both begin by asking whether their own markup is present, so a page that
 * has none of this costs one querySelector.
 *
 * Everything renders through textContent / createElement, except a message body, which arrives as
 * HTML the server has already put through the same sanitizer the public descriptions use — there is
 * exactly one place in this codebase that decides what may be displayed, and it is not here.
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
            else if (k === 'html') n.innerHTML = v;          // server-rendered, already sanitized
            // data-* through setAttribute, which keeps a t.key() word's key (dataset would write its words only, 1.73.0)
            else if (k === 'dataset') Object.keys(v).forEach(function (d) { n.setAttribute('data-' + d.replace(/[A-Z]/g, function (c) { return '-' + c.toLowerCase(); }), v[d]); });
            else n.setAttribute(k, v === true ? '' : v);
        });
        (Array.isArray(kids) ? kids : kids ? [kids] : []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(t.child(c));   // a t.key() word as a <span> that keeps its key
        });
        return n;
    }

    // The page's token (1.71.0): window.csrfToken() in app.js, loaded before this file, reads the one the
    // layout publishes on every page — not a list of the ids some pages carried.
    function csrf() {
        return typeof window.csrfToken === 'function' ? window.csrfToken() : '';
    }
    async function get(qs) {
        try {
            var r = await fetch(API + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return await r.json();
        } catch (e) { return null; }
    }
    async function post(endpoint, body) {
        try {
            body = body || {};
            body.csrf_token = csrf();
            var r = await fetch(API + endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify(body),
            });
            return await r.json();
        } catch (e) { return null; }
    }
    /**
     * A people action that did not go through SAYS so (1.74.0): the limit in its own words, anything else as the star's
     * "That did not go through." — a toast (app.js siteToast()). Remove friend, Decline, Unblock and the rest used to
     * end in silence, the row standing as if it had worked, and the next click sent the same request again.
     */
    function peopleFailed(r) {
        if (typeof window.siteToast !== 'function') return;
        window.siteToast({ text: r && r.error === 'rate_limit' ? 'js.people.rate_limited' : 'js.fav.failed', key: 'people-failed' });
    }
    /**
     * Why a message cannot be sent, as the dictionary says it (1.74.0). The server's code was glued to `js.pm.why_`, and
     * two codes are named otherwise in the dictionary (`pm_disabled` is `why_disabled`) or had no sentence at all
     * (`login_required`): "js.pm.why_pm_disabled" stood under Send when messages were switched off with a conversation
     * open. A code without a sentence says "That did not go through."; an answer that is a sentence already (a refused
     * CSRF token) is said as it is.
     */
    var PM_WHY = { pm_disabled: 'disabled', own_message: 'failed', unknown_op: 'failed' };
    function pmWhy(code) {
        code = String(code || 'failed');
        var k = 'js.pm.why_' + (PM_WHY[code] || code);
        if (/^[a-z_]+$/.test(code) && t.has(k)) return t.key(k);
        if (/\s/.test(code)) return code;
        return t.key('js.pm.why_failed');
    }
    function when(s) { return String(s || '').replace('T', ' ').slice(0, 16); }
    // The reader's clock (1.73.0 part E): the server sends each moment as 'Y-m-d H:i' in the reader's zone (`time`,
    // `last_time`, `until_time`, `since_time` — api/user_messages.php, api/user_people.php), the way the shoutbox's
    // times have been since 1.62.0; the raw DATETIME (the database session's clock) is only the fallback of a reply
    // from before. Digits, not words: nothing here for the live language switch to say again.
    function localTime(local, raw) { return local ? String(local) : when(raw); }

    /**
     * Words this file writes, KEPT WITH THEIR KEYS (1.73.0).
     *
     * The live language switch (assets/js/lang-swap.js) rewrites the page from a freshly rendered copy of it, and a
     * node a script made has no counterpart in that copy. That is why the conversation's archive button (1.71.0)
     * went on saying "Take this conversation out of your inbox" after a switch to Polish: its tooltip was a t.key() at
     * creation, and nothing ever asked again. Part A gave this file keys of its own on the nodes it made for the
     * Archive and the Trash (data-pm-*) and a listener that said them again; part B made it the site's one rule —
     * every t.key() word keeps its key on the node it is written into (assets/js/i18n.js) — and tr() writes through it:
     * `text` the node's words, `tip` its data-tip (the site's tooltip, read by tipOnHover() in app.js when it
     * shows), `aria` its aria-label, `args` the placeholders. `keep`: one placeholder that must not break across
     * lines (a date — "2026-" at a line's end and "10-30" on the next is no date), set in a span of its own
     * (.pm-nowrap) inside the sentence — the sentence is then one with a place in it, the way avatar.js writes a
     * sentence with people in it (t.phraseMark()): the switch writes the new language's pieces round the same span.
     */
    function tr(node, spec) {
        var args = spec.args;
        if (spec.text && spec.keep && args && args[spec.keep] != null) {
            var kept = String(args[spec.keep]), place = {};
            place[spec.keep] = '\u00010\u0001';
            var w = t.key(spec.text, Object.assign({}, args, place));
            node.textContent = '';
            String(w).split(/\u0001(\d+)\u0001/).forEach(function (part, i) {
                if (i % 2 === 0) { if (part) node.appendChild(document.createTextNode(part)); return; }
                node.appendChild(el('span', { className: 'pm-nowrap', text: kept, 'data-slot': '0' }));
            });
            t.phraseMark(node, w);
        } else if (spec.text) t.text(node, spec.text, args);
        if (spec.tip) t.attr(node, 'data-tip', spec.tip, args);
        if (spec.aria) t.attr(node, 'aria-label', spec.aria, args);
        return node;
    }
    /**
     * An icon-only button: the glyph, its name (aria-label) and what it does (the site's data-tip), by key. `icon` is
     * the WHOLE class string ('bi bi-archive'), the site's one icon markup (assets/js/app.js iconEl()), so
     * tests/icons_test.php finds every name that is used.
     */
    function icBtn(cls, icon, nameKey, tipKey, args) {
        return tr(el('button', { type: 'button', className: cls + ' ic-btn' },
                     el('i', { className: icon, 'aria-hidden': 'true' })),
                  { aria: nameKey, tip: tipKey || nameKey, args: args });
    }

    /**
     * The picture beside a person's name (1.63.0), from the ADDRESS the server put in the row — these
     * endpoints send a name and never the account id behind it. window.userAvatarImg() lives in
     * assets/js/avatar.js and answers null while pictures are switched off, so every caller simply
     * skips a null. The class is the surface's own, never `.pf-name`'s: that one is also a torrent's.
     */
    function face(name, address, size, cls) {
        return typeof window.userAvatarImg === 'function'
            ? window.userAvatarImg({ username: name, avatar: String(address || '') }, size, 'avatar ' + cls) : null;
    }

    /**
     * Whether this tracker has the "…is writing" line switched on, and who to say it to.
     *
     * Module-level because the composer is built outside the inbox that knows the answer, and
     * because there is only ever one conversation open: two of these would be two conversations on
     * one screen, which this page does not have.
     */
    var typingAllowed = false;
    var typingSentAt = 0;
    function typingPing(name) {
        // At most one write every four seconds, whatever the keyboard does. The row it writes lives
        // a little longer than that, so a steady typist is continuously "writing" at the cost of a
        // quarter of a request per second.
        if (!typingAllowed || !name) return;
        var now = Date.now();
        if (now - typingSentAt < 4000) return;
        typingSentAt = now;
        post('user_messages', { op: 'typing', with: name });
    }

    /* ─────────────────────────── the inbox ─────────────────────────── */

    function initInbox() {
        var root = document.getElementById('account-messages');
        if (!root) return;
        var list = document.getElementById('pm-threads');
        var pane = document.getElementById('pm-thread');
        var searchEl = document.getElementById('pm-search');
        var deepEl = document.getElementById('pm-deep');
        var openWith = null, timer = 0;
        // Live state for the conversation that is open: how often to ask, what the last line we
        // have is, and where to put a new one. All of it resets when a different thread opens.
        var live = 0, pollTimer = 0, lastId = 0, msgsBox = null, typingLine = null, mayReport = false;
        // One poll at a time. A request that takes longer than the interval would otherwise be
        // overtaken by the next one, which asks with the SAME `after` id and appends the same line
        // twice — visible as a message that arrived in duplicate on a slow connection.
        var polling = false;
        // The Archive and the Trash (1.73.0). Which of the three places the list shows; how long the Trash keeps a
        // conversation (0: there is none, and Delete deletes at once — the page's number, then every answer's); the
        // list's last answer, which a live language switch draws again in the new words without asking; a sequence
        // number, so an answer for a place the reader has already left is not drawn over the one they went to; and
        // which part of the open conversation is on the screen — what is not in the Trash, or what is.
        var viewsEl = document.getElementById('pm-views');
        var noteArchive = document.getElementById('pm-note-archive');
        var noteTrash = document.getElementById('pm-note-trash');
        var emptyBtn = document.getElementById('pm-empty-trash');
        var view = 'inbox', trashDays = Number(root.dataset.trashDays || 0), lastList = null, listSeq = 0;
        var openPart = 'live', lastCounts = null;

        function badge(n, fromFriends) {
            var b = document.getElementById('pm-unread');
            if (b) { b.textContent = n ? String(n) : ''; b.hidden = !n; }
            // The account link in the navigation carries one number for the whole account, so the
            // messages half is handed to whoever owns that sum rather than written from here.
            if (window.NavUnread) window.NavUnread.set('pm', n);
            // this tab asked: this tab may play — and it knows how many of them are from friends
            if (window.Sounds) window.Sounds.observe({ unread_pm: n, unread_pm_friend: fromFriends });
        }

        /**
         * The inbox, keeping up with itself.
         *
         * A conversation that is not open still receives messages, and a list that only changes when
         * somebody reloads the page is a list that is wrong most of the time — which is how a new
         * message could arrive with the inbox on screen and nothing to show for it.
         *
         * It asks for two facts and no rows: the moment of the newest line anywhere in this inbox,
         * and how many are unread. The list is redrawn only when one of the two has moved from what
         * was drawn, so the usual tick costs one small request and changes nothing. It runs a little
         * slower than an open conversation does, because a list is read at a glance and a
         * conversation is watched.
         */
        var inboxTimer = 0, inboxStamp = null, inboxUnread = -1;
        function stopInbox() { if (inboxTimer) { clearInterval(inboxTimer); inboxTimer = 0; } }

        async function inboxPoll() {
            // Not while a conversation is open (that one asks for itself), not from a background
            // tab, and not from another tab of the account page — offsetParent is null for anything
            // with a hidden ancestor, which is the question being asked.
            if (openWith || !list || document.hidden || list.offsetParent === null || polling) return;
            polling = true;
            var j = null;
            try { j = await get('user_messages&poll=1'); }
            finally { polling = false; }
            if (!j || openWith) return;
            if (!j.success) { if (j.error === 'login_required') stopInbox(); return; }   // signed out meanwhile: stop asking
            if (j.off) { stopInbox(); return; }     // the operator switched it off mid-session
            badge(j.unread, j.unread_friend);
            // The baseline came with the list itself, so a message that arrived between drawing it
            // and this first tick is a difference, not something this tick quietly adopts.
            //
            // The unread count is asked as well as the moment, because the moment has a resolution
            // of one second: two messages inside the same second carry the same stamp, and the
            // second of them would wait for a third to be drawn.
            if (inboxStamp !== null && (j.stamp !== inboxStamp || j.unread !== inboxUnread)) loadInbox();
            else { inboxStamp = j.stamp; inboxUnread = j.unread; }
        }

        /**
         * What the list is being asked for: names matching the filter, or — with the box ticked and at
         * least two characters to look for — conversations holding those words.
         *
         * `key` is that question as one string, and `asked` is the key of the list on screen. "Search
         * inside messages" is an OPTION of the search, not a search: with nothing (or one character)
         * in the box it asks the same question as before, and ticking it reloaded that same list
         * anyway — the flash the owner saw in 1.69.0. So the box is remembered for the next search, and
         * ticking it reloads only when the question really changes (1.70.0).
         */
        var asked = null;
        function inboxQuery() {
            var q = (searchEl && searchEl.value.trim()) || '';
            var deep = !!(deepEl && deepEl.checked) && q.length >= 2;
            return { q: q, deep: deep, key: (deep ? 'deep:' : 'name:') + q };
        }

        /* ── the three places (1.73.0) ────────────────────────────────────────────────────────────
           The Inbox, the Archive (what "hide" was — nothing deleted, back in one click) and the Trash (Delete:
           restorable for the site's number of days, then deleted for good — for this reader; the other person's
           copy is never touched). The tabs are the page's (templates/pages/account.php); their numbers are kept
           here from every answer: conversations in the Archive and the Trash, and the unread messages in the two
           places the badge counts — so a number on the account link always has a place on this page to be read. */
        function paintCounts(c) {
            if (!c || !viewsEl) return;
            lastCounts = c;
            var tab = function (v) { return document.getElementById('pm-view-' + v); };
            ['archive', 'trash'].forEach(function (v) {
                var s = tab(v) && tab(v).querySelector('.pm-view-n');
                if (s) s.textContent = '(' + (Number(c[v]) || 0) + ')';
            });
            ['inbox', 'archive'].forEach(function (v) {
                var p = tab(v) && tab(v).querySelector('.pm-view-unread');
                if (!p) return;
                var n = Number(c['unread_' + v]) || 0;
                var num = p.querySelector('.pm-view-unread-n');
                if (num) num.textContent = String(n);
                p.hidden = !n;
            });
            // No Trash on this site (pm_trash_days 0) and nothing left in it: no tab for it.
            var tt = tab('trash');
            if (tt) {
                tt.hidden = !(trashDays > 0 || Number(c.trash) > 0);
                if (tt.hidden && view === 'trash') setView('inbox');
            }
            if (emptyBtn) emptyBtn.disabled = !Number(c.trash);
        }

        function setView(v, andLoad) {
            view = v;
            if (viewsEl) viewsEl.querySelectorAll('.rt-tab').forEach(function (b) {
                var on = b.dataset.view === v;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                b.tabIndex = on ? 0 : -1;
            });
            list.setAttribute('aria-labelledby', 'pm-view-' + v);
            if (noteArchive) noteArchive.hidden = v !== 'archive';
            if (noteTrash) noteTrash.hidden = v !== 'trash';
            if (andLoad !== false) loadInbox();
        }
        if (viewsEl) {
            viewsEl.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.rt-tab') : null;
                if (!b || b.hidden || b.dataset.view === view) return;
                setView(b.dataset.view);
            });
            // A tab list is walked with the arrows (and Home / End); the focus moves, and the place with it.
            viewsEl.addEventListener('keydown', function (e) {
                if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].indexOf(e.key) === -1) return;
                var tabs = [].slice.call(viewsEl.querySelectorAll('.rt-tab')).filter(function (b) { return !b.hidden; });
                var i = tabs.indexOf(document.activeElement);
                if (i === -1) return;
                e.preventDefault();
                var n = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1
                      : (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                tabs[n].focus();
                if (tabs[n].dataset.view !== view) setView(tabs[n].dataset.view);
            });
            setView('inbox', false);
        }

        async function loadInbox() {
            var my = ++listSeq, forView = view;
            list.textContent = '';
            list.appendChild(el('div', { className: 'pf-loading', text: t.key('js.common.loading') }));
            // Names are filtered here, because the list is already in the browser. Looking inside
            // the messages is the server's job and costs a request, so it only happens when the
            // box beside the filter is ticked and there is something to look for.
            var ask = inboxQuery(), q = ask.q, deep = ask.deep;
            asked = ask.key;
            var j = await get('user_messages&view=' + forView + (deep ? '&deep=1&search=' + encodeURIComponent(q) : ''));
            // A newer question is on its way — another place, another search: this answer is not what is on screen.
            if (my !== listSeq) return;
            list.textContent = '';
            if (!j || !j.success) {
                list.appendChild(el('div', { className: 'pf-empty',
                    text: t.key(j && j.error === 'rate_limit' ? 'js.pm.search_slow' : 'js.fav.load_failed') }));
                return;
            }
            badge(j.unread, j.unread_friend);
            if (typeof j.trash_days === 'number') trashDays = j.trash_days;
            paintCounts(j.counts);
            // An empty inbox has an empty stamp, and that IS a baseline: turning it into null made the
            // first message ever land silently, adopted by the first tick instead of drawn.
            inboxStamp = String(j.stamp || '');
            inboxUnread = Number(j.unread || 0);
            live = Number(j.live || live || 0);
            if (live > 0 && !inboxTimer) inboxTimer = setInterval(inboxPoll, Math.max(4, live) * 1000);
            lastList = { threads: j.threads || [], q: q, deep: deep, view: forView };
            renderList();
        }

        /** The list, from its last answer — also after a live language switch, in the new words, with no request. */
        function renderList() {
            if (!lastList) return;
            list.textContent = '';
            var ql = lastList.q.toLowerCase(), deep = lastList.deep, v = lastList.view;
            var rows = lastList.threads.filter(function (x) { return deep || !ql || x.with.toLowerCase().indexOf(ql) !== -1; });
            if (!rows.length) {
                list.appendChild(el('div', { className: 'pf-empty', text: t.key(lastList.q ? 'js.pm.no_match'
                    : v === 'archive' ? 'js.pm.no_archive' : v === 'trash' ? 'js.pm.no_trash' : 'js.pm.no_threads') }));
                return;
            }
            rows.forEach(function (x) { list.appendChild(rowFor(x, v)); });
        }

        function rowFor(x, v) {
            var row = el('button', { type: 'button', className: 'pm-row' + (x.unread ? ' pm-row-unread' : '') + (openWith === x.with ? ' pm-row-open' : '') });
            // A stable id, the way every polled row on this site carries one since 1.64.0 (see
            // templates/partials/shoutbox_widget.php): the live language switch pairs a living
            // node with the freshly rendered one by `id` first and by POSITION second, and a
            // list a script filled after the page was drawn is never the list a fresh render
            // holds. The inbox is written into an empty container, so nothing can be paired
            // with it today — the id is what keeps that true if the container is ever given a
            // line of its own. One thread per person, so the name IS the key.
            row.id = 'pm-th-' + x.with;
            // The picture takes a column of its own, beside both lines of the row: a person to the
            // left of what they last said, the way every inbox reads.
            var pic = face(x.with, x.avatar, 32, 'pm-av');
            if (pic) { row.classList.add('pm-row-av'); row.appendChild(pic); }
            // A long name is cut with "…" (1.74.0, style.css) — the whole of it in the tooltip; it used to run under the
            // unread count and the time, and off the side of a phone.
            row.appendChild(el('span', { className: 'pm-who', text: x.with, title: x.with }));
            // The count and the time are one cell, on the right of the name. Appended as two
            // children of the row they were two grid items, and the count — landing in the
            // column that holds the name — was stretched into a bar the width of the row.
            var meta = el('span', { className: 'pm-meta' });
            if (x.unread) meta.appendChild(el('span', { className: 'pm-count', text: String(x.unread) }));
            meta.appendChild(el('span', { className: 'pm-when text-muted', text: localTime(x.last_time, x.last_at) }));
            row.appendChild(meta);
            row.appendChild(el('span', { className: 'pm-preview text-muted' }, [x.mine ? t.key('js.pm.you_prefix') : '', x.preview]));   // pieces (1.73.0)
            row.addEventListener('click', function () {
                // Opening it IS reading it, and the list stands beside the conversation rather
                // than being replaced by it — so a row still saying "2 waiting" next to the
                // conversation those two are in is simply wrong until the next reload.
                row.classList.remove('pm-row-unread');
                var c = row.querySelector('.pm-count');
                if (c) c.remove();
                list.querySelectorAll('.pm-row-open').forEach(function (r) { r.classList.remove('pm-row-open'); });
                row.classList.add('pm-row-open');
                // The Trash's rows open what is in the Trash; the others what is not.
                openThread(x.with, { part: v === 'trash' ? 'trash' : '' });
            });
            /* ── what can be done with it from here (1.64.0 the bin; 1.73.0 all of them) ─────────────
               The row IS a button, so its actions cannot be inside it — a button inside a button is not
               markup a browser will keep. They go in a wrapper instead, beside the row: drawn on hover on a
               machine with a pointer, always on a touch screen (the stylesheet's `@media (hover: none)` arm).
               Two per place — Archive and Delete in the Inbox, Move to inbox and Delete in the Archive,
               Restore and Delete forever in the Trash — each an icon with its name and its tooltip. Delete
               and Delete forever ask first, in place, with the shoutbox's question; a move says so after, in a
               toast with Undo. Nothing is destroyed but by Delete forever, and then only for this reader. */
            var wrap = el('div', { className: 'pm-row-wrap' });
            wrap.appendChild(row);
            var acts = el('span', { className: 'pm-row-acts' });
            var args = { user: x.with };
            if (v === 'trash') {
                var rs = icBtn('pm-act pm-restore', 'bi bi-arrow-counterclockwise', 'js.pm.act_restore', 'js.pm.act_restore_tip', args);
                rs.addEventListener('click', function () { userOp('restore', x.with, {}, rs); });
                var pg = icBtn('pm-act pm-purge', 'bi bi-trash-fill', 'js.pm.act_purge', 'js.pm.act_purge_tip', args);
                pg.addEventListener('click', function () {
                    confirmThen(pg, 'js.pm.q_purge', wrap, function () { return userOp('purge', x.with, {}); });
                });
                acts.appendChild(rs);
                acts.appendChild(pg);
            } else {
                var ar = v === 'archive'
                    ? icBtn('pm-act pm-unarch', 'bi bi-inbox', 'js.pm.act_unarchive', 'js.pm.act_unarchive_tip', args)
                    : icBtn('pm-act pm-arch', 'bi bi-archive', 'js.pm.act_archive', 'js.pm.act_archive_tip', args);
                ar.addEventListener('click', function () { userOp(v === 'archive' ? 'unarchive' : 'archive', x.with, {}, ar); });
                var del = icBtn('pm-act pm-del', 'bi bi-trash', 'js.pm.act_trash', trashDays > 0 ? 'js.pm.act_trash_tip' : 'js.pm.act_trash_final_tip', args);
                del.addEventListener('click', function () {
                    // `upto`: the last message this row was drawn with — one that arrived since stays where it is.
                    confirmThen(del, trashDays > 0 ? 'js.pm.q_trash' : 'js.pm.q_trash_final', wrap,
                                function () { return userOp('trash', x.with, { upto: x.last_id }); });
                });
                acts.appendChild(ar);
                acts.appendChild(del);
            }
            wrap.appendChild(acts);
            return wrap;
        }

        /* ── doing it, saying so, taking it back (1.73.0) ────────────────────────────────────────── */

        /** The site's in-place question (assets/js/app.js) before fn; its button back if fn says it failed. */
        function confirmThen(btn, qKey, host, fn) {
            if (typeof window.askInPlace !== 'function') { fn(); return; }
            window.askInPlace(btn, t.key(qKey), async function () { return (await fn()) ? true : false; }, { host: host });
        }
        function toast(o) { if (typeof window.siteToast === 'function') window.siteToast(o); }

        /**
         * One explicit operation (includes/people.php): the tabs' numbers and the badge from its answer, the open
         * conversation closed when it has left (Archive, Trash, deleted for good) or drawn again where it came back
         * to, and the list asked for again. Null when it did not go through (and said so).
         */
        async function runOp(op, name, extra) {
            var r = await post('user_messages', Object.assign({ op: op, with: name }, extra || {}));
            if (!r || !r.success) { toast({ text: 'js.pm.why_failed' }); return null; }
            if (typeof r.trash_days === 'number') trashDays = r.trash_days;
            paintCounts(r.counts);
            badge(Number(r.unread) || 0);
            if (openWith === name) {
                if (op === 'archive' || op === 'trash' || op === 'purge') closePane();
                else openThread(name);
            }
            // The list drawn again before anything is said about it: the toast after this looks at where the focus is.
            await loadInbox();
            return r;
        }
        /** Where the keyboard's focus goes when a toast about this conversation leaves it: its row, else the place's tab. */
        function homeFor(name) {
            return function () { return document.getElementById('pm-th-' + name) || document.getElementById('pm-view-' + view); };
        }

        /**
         * What a button does: the operation, then a toast saying what happened — with Undo for ~5 s after a move
         * (Archive, Move to inbox, Delete into the Trash, Restore). The Undo is the explicit opposite operation; a
         * Trash's Undo names what that Trash did (to = the edge before it, from = the edge it set), so one that comes
         * too late — the Trash emptied, restored, grown since — changes nothing and says so. Delete forever and a
         * Delete with no Trash have nothing to undo, and their question said as much before they ran.
         */
        async function userOp(op, name, extra, srcBtn) {
            if (srcBtn) srcBtn.disabled = true;
            var r = await runOp(op, name, extra);
            if (srcBtn && srcBtn.isConnected) srcBtn.disabled = false;
            if (!r) return false;
            var text = 'js.pm.t_archived', undo = null;
            if (op === 'archive') {
                if (r.changed) undo = function () { return undoOp('unarchive', name, {}); };
            } else if (op === 'unarchive') {
                text = 'js.pm.t_unarchived';
                if (r.changed) undo = function () { return undoOp('archive', name, {}); };
            } else if (op === 'trash') {
                text = r.final ? 'js.pm.t_deleted' : 'js.pm.t_trashed';
                if (r.changed && !r.final) undo = function () { return undoOp('restore', name, { to: r.was, from: r.upto }); };
            } else if (op === 'restore') {
                text = r.place === 'archive' ? 'js.pm.t_restored_archive' : 'js.pm.t_restored_inbox';
                if (r.changed) undo = function () { return undoOp('trash', name, { upto: r.was }); };
            } else {
                text = 'js.pm.t_deleted';
            }
            toast({ text: text, args: { user: name }, key: 'pm:' + name, undo: undo, home: homeFor(name) });
            return true;
        }
        async function undoOp(op, name, extra) {
            var r = await runOp(op, name, extra);
            if (!r) return;
            toast({ text: r.changed ? 'js.pm.t_undone' : 'js.pm.t_stale', key: 'pm:' + name, home: homeFor(name) });
            // The Undo that had the keyboard's focus is going with its toast: the focus goes to what it brought back.
            var a = document.activeElement;
            if (!a || a === document.body || !a.isConnected || (a.closest && a.closest('.site-toast'))) {
                var h = homeFor(name)();
                if (h) h.focus({ preventScroll: true });
            }
        }

        async function emptyTrash() {
            var r = await post('user_messages', { op: 'empty_trash' });
            if (!r || !r.success) { toast({ text: 'js.pm.why_failed' }); return false; }
            paintCounts(r.counts);
            badge(Number(r.unread) || 0);
            if (openWith) { if (openPart === 'trash') closePane(); else openThread(openWith); }
            toast({ text: 'js.pm.t_emptied', args: { n: Number(r.purged) || 0 }, key: 'pm:empty' });
            loadInbox();
            return true;
        }
        if (emptyBtn) emptyBtn.addEventListener('click', function () {
            if (emptyBtn.disabled) return;
            confirmThen(emptyBtn, 'js.pm.q_empty', noteTrash, async function () { await emptyTrash(); return false; });
        });

        function closePane() {
            stopPoll();
            openWith = null; openPart = 'live';
            msgsBox = null; typingLine = null;
            pane.textContent = '';
            pane.hidden = true;
            list.querySelectorAll('.pm-row-open').forEach(function (r) { r.classList.remove('pm-row-open'); });
        }

        function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = 0; } }

        /**
         * The report button's face: the icon library's flag, filled once this message is reported
         * (1.68.0 — two flag characters until then), and the word beside it.
         */
        function reportFace(btn, reported) {
            btn.replaceChildren(el('i', { className: reported ? 'bi bi-flag-fill' : 'bi bi-flag', 'aria-hidden': 'true' }),
                                ' ', reported ? t.key('js.pm.reported') : t.key('js.pm.report'));   // a piece that keeps its key (1.73.0)
        }

        /** One message, as a row. The first draw and every later arrival go through here. */
        function renderMsg(m) {
            var wrap = el('div', { className: 'pm-msg' + (m.mine ? ' pm-msg-mine' : '') });
            wrap.id = 'pm-msg-' + (Number(m.id) || 0);     // 1.64.0 — see the inbox rows above
            wrap.dataset.id = String(m.id || 0);
            if (m.mine) wrap.dataset.mine = '1';
            wrap.appendChild(el('div', { className: 'pm-body richtext', html: m.html }));
            var foot = el('div', { className: 'pm-msg-foot text-muted' });
            foot.appendChild(el('span', { className: 'pm-time', text: localTime(m.time, m.created) }));
            if (m.mine && m.read) foot.appendChild(el('span', { className: 'pm-read', text: t.key('js.pm.read') }));
            if (!m.mine && mayReport) {
                var rep = el('button', { type: 'button', className: 'pm-report', title: t.key('js.pm.report_title') });
                reportFace(rep, !!m.reported);
                rep.disabled = !!m.reported;
                rep.addEventListener('click', function () { reportMessage(m.id, rep); });
                foot.appendChild(rep);
            }
            wrap.appendChild(foot);
            return wrap;
        }

        /**
         * The conversation, keeping up with itself.
         *
         * It asks for NEW ROWS ONLY and appends them — it never redraws the thread, because
         * redrawing would take away the half-written sentence in the box underneath it. Skipped
         * entirely while the tab is in the background: a conversation nobody is looking at does not
         * need to be up to date, and it catches up the moment they come back.
         */
        async function pollOnce() {
            // Nothing to ask while nobody can see the answer: another browser tab, or another tab
            // of the account page. offsetParent is null for anything with a hidden ancestor, which
            // is exactly the question — is this conversation on the screen?
            if (!openWith || !msgsBox || document.hidden || msgsBox.offsetParent === null || polling) return;
            var name = openWith;
            polling = true;
            var j = null;
            try { j = await get('user_messages&poll=1&with=' + encodeURIComponent(name) + '&after=' + lastId); }
            finally { polling = false; }
            if (!j || openWith !== name || !msgsBox) return;
            if (!j.success) { if (j.error === 'login_required') stopPoll(); return; }   // signed out meanwhile: stop asking
            if (j.off) { stopPoll(); return; }          // the operator switched it off mid-session
            var atBottom = msgsBox.scrollHeight - msgsBox.scrollTop - msgsBox.clientHeight < 40;
            (j.rows || []).forEach(function (m) {
                // Never the same line twice, whatever the network did: the DOM already holds the
                // ids it is showing, and that is the only copy that matters.
                if (m.id <= lastId || msgsBox.querySelector('.pm-msg[data-id="' + m.id + '"]')) return;
                lastId = m.id;
                msgsBox.appendChild(renderMsg(m));
            });
            if ((j.rows || []).length) {
                badge(j.unread, j.unread_friend);
                if (j.counts) paintCounts(j.counts);
                // Only follow the conversation down if they were already at the bottom of it —
                // scrolling somebody away from the line they are reading is worse than a missed row.
                if (atBottom) msgsBox.scrollTop = msgsBox.scrollHeight;
            }
            // "read", on my own side, without redrawing anything that is already correct.
            if (j.read_upto) {
                msgsBox.querySelectorAll('.pm-msg[data-mine="1"]').forEach(function (w) {
                    if (Number(w.dataset.id) > j.read_upto) return;
                    var foot = w.querySelector('.pm-msg-foot');
                    if (foot && !foot.querySelector('.pm-read')) {
                        foot.appendChild(el('span', { className: 'pm-read', text: t.key('js.pm.read') }));
                    }
                });
            }
            if (typingLine) typingLine.hidden = !j.typing;
        }

        async function openThread(name, opts) {
            opts = opts || {};
            openWith = name;
            openPart = 'live';
            stopPoll();
            msgsBox = null; typingLine = null; lastId = 0;
            pane.textContent = '';
            pane.hidden = false;
            pane.appendChild(el('div', { className: 'pf-loading', text: t.key('js.common.loading') }));
            var j = await get('user_messages&with=' + encodeURIComponent(name) + (opts.part === 'trash' ? '&part=trash' : ''));
            if (openWith !== name) return;
            pane.textContent = '';
            if (!j || !j.success) {
                // "There is no such account" is an answer, not a failure to load one — and it is the
                // answer somebody typing a name into the box above will get wrong first.
                pane.appendChild(el('div', { className: 'pf-empty',
                    text: t.key(j && j.error === 'not_found' ? 'js.pm.why_not_found' : 'js.fav.load_failed') }));
                return;
            }
            badge(j.unread, j.unread_friend);
            if (typeof j.trash_days === 'number') trashDays = j.trash_days;
            // Opening it read what it shows: the tab it is in loses that from its unread pill.
            if (j.counts) paintCounts(j.counts);
            // Which part is on the screen (1.73.0): what is not in the Trash, or — asked from the Trash, or all
            // there is — what is. The Trash's part is read, not written in: no live poll, no "…is writing".
            var part = j.part === 'trash' ? 'trash' : 'live';
            var st = j.state || {};
            openPart = part;
            var args = { user: name };

            var head = el('div', { className: 'pm-head' });
            // The picture inside the name's link: one unit the head's wrapping can never split, and
            // one more place to click through to the profile.
            head.appendChild(el('a', { className: 'pm-head-name', href: BASE + '?action=u&name=' + encodeURIComponent(name) },
                                [face(j.with || name, j.with_avatar, 32, 'pm-head-av'), name]));
            // The head's icons (1.71.0, 1.73.0): the arrow back to the list; then, for a conversation in the Inbox,
            // the archive box (into the Archive — nothing is deleted, and the toast's Undo or the Archive tab brings
            // it back) and the bin (into the Trash, after the in-place question). In the Archive the way back is the
            // bar's "Move to inbox" below, in the Trash the bar's Restore and Delete forever. Each named for a screen
            // reader and explained in the site's tooltip, by key (tr(): they follow the live language switch).
            var back = icBtn('btn btn-secondary btn-small pm-back', 'bi bi-arrow-left', 'js.pm.back', 'js.pm.back_title');
            back.addEventListener('click', function () { closePane(); loadInbox(); });
            head.appendChild(back);
            if (part === 'live' && st.place === 'inbox') {
                var arch = icBtn('btn btn-secondary btn-small pm-arch', 'bi bi-archive', 'js.pm.act_archive', 'js.pm.act_archive_tip', args);
                arch.addEventListener('click', function () { userOp('archive', name, {}, arch); });
                head.appendChild(arch);
            }
            if (part === 'live' && (st.place === 'inbox' || st.place === 'archive')) {
                var del = icBtn('btn btn-secondary btn-small pm-trash', 'bi bi-trash', 'js.pm.act_trash',
                                trashDays > 0 ? 'js.pm.act_trash_tip' : 'js.pm.act_trash_final_tip', args);
                del.addEventListener('click', function () {
                    // Up to the last line on the screen: one the poll has not brought yet stays where it is.
                    confirmThen(del, trashDays > 0 ? 'js.pm.q_trash' : 'js.pm.q_trash_final', head,
                                function () { return userOp('trash', name, lastId ? { upto: lastId } : {}); });
                });
                head.appendChild(del);
            }
            pane.appendChild(head);
            var bar = barFor(name, part, st);
            if (bar) pane.appendChild(bar);

            mayReport = !!j.may_report;
            var body = el('div', { className: 'pm-msgs' + (part === 'trash' ? ' pm-msgs-trash' : '') });
            (j.rows || []).forEach(function (m) {
                if (m.id > lastId) lastId = m.id;
                body.appendChild(renderMsg(m));
            });
            pane.appendChild(body);
            msgsBox = body;
            // "…is writing", under the conversation and above the box being written in — which is
            // where it is true.
            typingLine = el('div', { className: 'pm-typing text-muted', text: t.key('js.pm.typing', { user: name }) });
            typingLine.hidden = true;
            pane.appendChild(typingLine);
            typingAllowed = !!j.typing_on;
            live = Number(j.live || 0);

            if (j.can_write) {
                // Written from the Trash's part too: the new message starts the conversation again after it, and
                // what is in the Trash stays there (the bar then says so, with its Restore).
                mountComposer(pane, name, function () { openThread(name); });
            } else {
                pane.appendChild(el('div', { className: 'pm-closed', text: pmWhy(j.reason || 'nobody') }));
            }
            body.scrollTop = body.scrollHeight;
            // Only once the conversation is on screen: a timer started before the first draw would
            // ask about a thread this page has not read yet. Never for the Trash's part.
            if (live > 0 && part === 'live') pollTimer = setInterval(pollOnce, live * 1000);
        }

        /**
         * The bar under a conversation's head (1.73.0): where it is, when that is not simply the Inbox — the owner
         * wrote to somebody from the members list and found a conversation he had "hidden" with nothing to say so —
         * and the way back, as words. In the Archive: Move to inbox. In the Trash: when it is deleted for good,
         * Restore, and Delete forever (asked first). With earlier messages in the Trash while the conversation goes
         * on: how many, and Restore them. Null when there is nothing to say.
         */
        function barFor(name, part, st) {
            var lines = [];
            var line = function (textNode, buttons) {
                var l = el('div', { className: 'pm-bar-line' }, [textNode]);
                var acts = el('span', { className: 'pm-bar-acts' });
                buttons.forEach(function (b) { acts.appendChild(b); });
                l.appendChild(acts);
                lines.push(l);
                return l;
            };
            var words = function (key, args) { return tr(el('span', { className: 'pm-bar-text' }), { text: key, args: args, keep: args && args.date ? 'date' : '' }); };
            if (part === 'trash') {
                var rs = barBtn('pm-bar-restore', 'bi bi-arrow-counterclockwise', 'js.pm.act_restore');
                rs.addEventListener('click', function () { userOp('restore', name, {}, rs); });
                var pg = barBtn('pm-bar-purge', 'bi bi-trash-fill', 'js.pm.act_purge');
                pg.classList.replace('btn-secondary', 'btn-danger-soft');   // looks like what it does (1.74.0)
                var l1 = line(st.until ? words('js.pm.bar_trash', { date: localTime(st.until_time, st.until) }) : words('js.pm.bar_trash_plain'), [rs, pg]);
                pg.addEventListener('click', function () {
                    confirmThen(pg, 'js.pm.q_purge', l1, function () { return userOp('purge', name, {}); });
                });
            } else {
                if (st.place === 'archive') {
                    var ua = barBtn('pm-bar-unarch', 'bi bi-inbox', 'js.pm.act_unarchive');
                    ua.addEventListener('click', function () { userOp('unarchive', name, {}, ua); });
                    line(words('js.pm.bar_archive'), [ua]);
                }
                if (Number(st.trash) > 0) {
                    var ro = barBtn('pm-bar-restore', 'bi bi-arrow-counterclockwise', 'js.pm.bar_restore_older');
                    ro.addEventListener('click', function () { userOp('restore', name, {}, ro); });
                    line(words('js.pm.bar_older', { n: Number(st.trash) }), [ro]);
                }
            }
            if (!lines.length) return null;
            var bar = el('div', { className: 'pm-bar pm-bar-' + (part === 'trash' ? 'trash' : st.place === 'archive' ? 'archive' : 'older') });
            lines.forEach(function (l) { bar.appendChild(l); });
            return bar;
        }
        /** A button of the bar: an icon (its whole class string) and its words, the words by key. */
        function barBtn(cls, icon, textKey) {
            return el('button', { type: 'button', className: 'btn btn-secondary btn-small ' + cls },
                      [el('i', { className: icon, 'aria-hidden': 'true' }), ' ', tr(el('span'), { text: textKey })]);
        }

        /**
         * Reporting one line.
         *
         * A window rather than window.prompt(), for two reasons: a prompt is a browser dialog that
         * cannot say what the moderator will and will not see — and that sentence is the whole
         * reason somebody is willing to press the button — and a prompt cannot be styled, so the one
         * moment this page asks for a sentence looked like a page from another site.
         */
        function reportMessage(id, btn) {
            var box = document.getElementById('pmreport-overlay');
            var why = document.getElementById('pmreport-why');
            var go = document.getElementById('pmreport-go');
            var msg = document.getElementById('pmreport-msg');
            if (!box || !why || !go) {           // no markup on this page: nothing to open
                return;
            }
            why.value = '';
            msg.textContent = '';
            box.hidden = false;
            why.focus();
            var close = function () { box.hidden = true; document.removeEventListener('keydown', esc); };
            var esc = function (e) { if (e.key === 'Escape') close(); };
            document.addEventListener('keydown', esc);
            // The backdrop closes it only when the press STARTED there (1.64.0): a `click` is
            // delivered to the common ancestor of the press and the release, so a press inside the
            // dialog that is let go outside it raised one on the backdrop and shut the dialog.
            // Assigned rather than added, because this whole block runs again on every open.
            var fromBackdrop = false;
            box.onpointerdown = function (e) { fromBackdrop = e.target === box; };
            box.onclick = function (e) { if (e.target === box && fromBackdrop) close(); };
            var x = document.getElementById('pmreport-close');
            if (x) x.onclick = close;
            go.onclick = async function () {
                if (go.disabled) return;
                go.disabled = true;
                // A report passes the anti-spam layer as every report does (1.71.0): a CAPTCHA it asks for, the
                // same report again; a wait counts down on the button, the sentence beside it.
                var payload = { op: 'report', message: id, reason: why.value.trim() };
                var doPost = function (extra) { return post('user_messages', Object.assign({}, payload, extra || {})); };
                var r = window.Antispam
                    ? await window.Antispam.send(doPost, { button: go, note: function (s) { msg.textContent = s || ''; }, action: 'user_messages' })
                    : await doPost({});
                if (!(window.Antispam && window.Antispam.waiting(go))) go.disabled = false;
                if (!r || !r.success) {
                    if (r && (r.antispam || r.error === 'captcha_cancelled')) {
                        if (!(window.Antispam && window.Antispam.waiting(go))) msg.textContent = r.message || t.key('js.pm.report_failed');
                    } else {
                        msg.textContent = t.key('js.pm.report_failed');
                    }
                    return;
                }
                reportFace(btn, true);
                btn.disabled = true;
                close();
            };
        }

        /**
         * Writing to somebody who is not in the inbox yet.
         *
         * Every route into a conversation began somewhere else — a profile, the directory, a
         * notification — so an inbox with nobody in it was a page with nothing to do on it. The name
         * box is hidden until asked for, and the datalist beside it is the reader's friends, because
         * that is who they usually mean.
         */
        var newBtn = document.getElementById('pm-new');
        var newRow = document.getElementById('pm-new-row');
        var newWho = document.getElementById('pm-new-who');
        var newGo = document.getElementById('pm-new-go');
        if (newBtn && newRow && newWho && newGo) {
            var filled = false;
            newBtn.addEventListener('click', async function () {
                newRow.hidden = !newRow.hidden;
                if (newRow.hidden) return;
                newWho.focus();
                if (filled) return;
                filled = true;
                var j = await get('user_people&view=friends');
                var dl = document.getElementById('pm-friends');
                if (!dl || !j || !j.success) return;
                (j.rows || []).forEach(function (p) { dl.appendChild(el('option', { value: p.username })); });
            });
            var open = function () {
                var who = newWho.value.trim();
                if (!who) { newWho.focus(); return; }
                newWho.value = '';
                newRow.hidden = true;
                openThread(who);
            };
            newGo.addEventListener('click', open);
            newWho.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); open(); } });
        }

        // Longer than the 300 ms the name filter uses: a deep search is a query, and waiting for
        // somebody to stop typing is what keeps it to one.
        if (searchEl) searchEl.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(loadInbox, deepEl && deepEl.checked ? 600 : 300);
        });
        // Only when it changes the question (see inboxQuery()). When it does, it asks now and takes over
        // a keystroke still waiting to ask, rather than letting that one ask the same thing again.
        if (deepEl) deepEl.addEventListener('change', function () {
            if (inboxQuery().key === asked) return;
            clearTimeout(timer);
            loadInbox();
        });
        // The tab bar shows this pane without reloading the page — a hash change is a same-document
        // navigation, and clicking a tab is not a navigation at all. Without a hook the inbox stayed
        // whatever it was when the page first loaded, which is exactly when somebody has come back
        // to it to see what arrived.
        window.PM = {
            refresh: function (name) {
                if (name) { openThread(name); return; }
                if (openWith) openThread(openWith, { part: openPart === 'trash' ? 'trash' : '' }); else loadInbox();
            },
            // For the browser checks (1.73.0): which place, which conversation and part, the tabs' last numbers.
            state: function () {
                return { view: view, openWith: openWith, part: openPart, counts: lastCounts, trashDays: trashDays,
                         rows: lastList ? lastList.threads.length : null };
            },
        };
        // A live language switch needs nothing from here (1.73.0): the rows, the head, the bar and the buttons are
        // t.key() words that keep their keys (tr() above, assets/js/i18n.js); the three tabs and their notes are the
        // page's own. (The rows used to be drawn again from the list's last answer.)
        loadInbox();
        // Opening a conversation from somewhere else on the site: ?action=account#messages:name
        var m = /^#messages:(.+)$/.exec(location.hash || '');
        if (m) openThread(decodeURIComponent(m[1]));
        window.addEventListener('hashchange', function () {
            var mm = /^#messages:(.+)$/.exec(location.hash || '');
            if (mm) openThread(decodeURIComponent(mm[1]));
        });
    }

    /**
     * The box somebody types into — the editor the rest of the site writes in.
     *
     * It was a bare textarea while descriptions had tabs, a formatting rail, a live preview and a
     * counter; a message written in the same markup showed none of it, so the syntax was something
     * you had to already know. The markup is a <template> on the account page (so its labels come
     * from the same dictionary as the rest of that page) and assets/js/app.js mounts the behaviour.
     *
     * It appends ITSELF, because the editor finds its parts by id and they have to be in the
     * document before it can. Falls back to a plain box where the template is absent.
     */
    function mountComposer(parent, name, onSent) {
        var wrap = el('div', { className: 'pm-composer' });
        var tpl = document.getElementById('pm-editor-tpl');
        var rich = !!(tpl && tpl.content);
        var ta;
        if (rich) {
            var copy = tpl.content.cloneNode(true), copied = Array.prototype.slice.call(copy.childNodes);
            wrap.appendChild(copy);
            // The copy follows its template through the live language switch (assets/js/lang-swap.js, 1.73.0): its
            // tabs, its rail's tooltips, its format box — the server's words — are said again where they stand.
            if (window.LangSwap && window.LangSwap.adopt) window.LangSwap.adopt(copied, 'pm-editor-tpl');
            ta = wrap.querySelector('#pm-body');
        }
        if (!ta) {
            rich = false;
            ta = el('textarea', { className: 'pm-input', rows: 3, maxlength: 20000, placeholder: t.key('js.pm.write_ph') });
            wrap.appendChild(ta);
        }
        var send = el('button', { type: 'button', className: 'btn btn-small', text: t.key('js.pm.send') });
        var msg = el('span', { className: 'text-muted pm-msg-note' });
        var row = el('div', { className: 'pm-composer-row' });
        row.appendChild(send); row.appendChild(msg);
        wrap.appendChild(row);
        parent.appendChild(wrap);
        // Every keystroke asks; typingPing() is what turns that into one small write every four
        // seconds, and into nothing at all where the operator has not switched the line on.
        ta.addEventListener('input', function () { typingPing(name); });
        if (rich && window.RichText && typeof window.RichText.mount === 'function') {
            // The preview endpoint is told what this is: a message is gated on being allowed to send
            // one, not on being allowed to upload a torrent.
            window.RichText.mount('pm-body', { previewFor: 'message' });
        }

        send.addEventListener('click', async function () {
            if (send.disabled) return;
            var body = ta.value.trim();
            if (!body) { ta.focus(); return; }
            var fmtEl = rich ? document.getElementById('pm-body-format') : null;
            send.disabled = true;
            var payload = {
                op: 'send', to: name, body: body,
                // Whichever tab they wrote in. The server validates it either way — richtext.php is
                // the one place that decides what a message may contain.
                format: fmtEl && fmtEl.value ? fmtEl.value : 'bbcode',
            };
            // Through the anti-spam layer's helper (1.71.0, assets/js/antispam.js): a CAPTCHA it asks for is
            // solved and the message sent again; a wait — a new conversation too soon, too many of them this
            // hour — counts down on Send, with the server's sentence beside it.
            var doPost = function (extra) { return post('user_messages', Object.assign({}, payload, extra || {})); };
            var r = window.Antispam
                ? await window.Antispam.send(doPost, { button: send, note: function (s) { msg.textContent = s || ''; }, action: 'user_messages' })
                : await doPost({});
            if (!(window.Antispam && window.Antispam.waiting(send))) send.disabled = false;
            if (!r || !r.success) {
                // The layer's refusals carry their own sentence; the send's own codes are the dictionary's.
                if (r && (r.antispam || r.error === 'captcha_cancelled')) {
                    if (!(window.Antispam && window.Antispam.waiting(send))) msg.textContent = r.message || t.key('js.pm.why_failed');
                } else {
                    msg.textContent = pmWhy(r && r.error);
                }
                return;
            }
            ta.value = '';
            msg.textContent = '';
            if (typeof onSent === 'function') onSent();
        });
        return wrap;
    }

    /* ─────────────────────────── friends and blocks ─────────────────────────── */

    function initPeople() {
        var root = document.getElementById('account-people');
        if (!root) return;
        var tabs = document.getElementById('pe-tabs');
        var listEl = document.getElementById('pe-list');
        var searchEl = document.getElementById('pe-search');
        var view = 'friends', timer = 0;

        function counts(c) {
            // The tab bar above the panes carries the same number: somebody waiting for an answer is
            // the one thing on the account page that goes stale while it is being looked at.
            var head = document.getElementById('pe-incoming');
            if (head) { head.textContent = c.incoming ? String(c.incoming) : ''; head.hidden = !c.incoming; }
            if (!tabs) return;
            tabs.querySelectorAll('.rt-tab').forEach(function (b) {
                var n = c[b.dataset.view];
                var badge = b.querySelector('.pe-count');
                if (!badge) { badge = el('span', { className: 'pe-count' }); b.appendChild(badge); }
                badge.textContent = n ? ' ' + n : '';
            });
        }

        var loadSeq = 0;
        async function load() {
            listEl.textContent = '';
            listEl.appendChild(el('div', { className: 'pf-loading', text: t.key('js.common.loading') }));
            // The view this request is FOR. A click on another tab while it is in flight changes
            // `view`; an answer that arrives afterwards belongs to the old one and would draw those
            // rows with the new tab's buttons — friends offered "Unblock".
            var forView = view, my = ++loadSeq;
            var qs = 'user_people&view=' + forView;
            if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
            var j = await get(qs);
            if (my !== loadSeq || forView !== view) return;
            listEl.textContent = '';
            if (!j || !j.success) { listEl.appendChild(el('div', { className: 'pf-empty', text: t.key('js.fav.load_failed') })); return; }
            counts(j.counts || {});
            if (!j.rows.length) { listEl.appendChild(el('div', { className: 'pf-empty', text: t.key('js.people.empty_' + forView) })); return; }
            j.rows.forEach(function (p) { listEl.appendChild(personRow(p, forView, load, !!j.may_message)); });
        }

        function personRow(p, kind, reload, mayMessage) {
            var row = el('div', { className: 'pf-row pe-row' });
            row.id = 'pe-row-' + p.username;              // 1.64.0 — see the inbox rows above
            var main = el('div', { className: 'pf-main' });
            var pic = face(p.username, p.avatar, 32, 'pe-av');
            if (pic) main.appendChild(pic);
            main.appendChild(el('a', { className: 'pf-name', href: BASE + '?action=u&name=' + encodeURIComponent(p.username), text: p.username }));
            if (p.since) main.appendChild(el('span', { className: 'text-muted pe-since', text: localTime(p.since_time, p.since) }));
            if (kind === 'blocks' && p.hide_profile) main.appendChild(el('span', { className: 'pf-badge', text: t.key('js.people.hidden') }));
            row.appendChild(main);
            var acts = el('div', { className: 'pf-acts' });
            // `ask` (1.74.0): a question in the row first — for what the other person would have to undo (a friendship
            // removed, a request declined: only a new request and their yes bring it back). Those buttons look like
            // what they do (.btn-danger-soft), not like "Message" beside them. A failure says so (peopleFailed()).
            var act = function (label, op, extra, ask) {
                var b = el('button', { type: 'button', className: 'btn btn-small ' + (ask ? 'btn-danger-soft' : 'btn-secondary'), text: label });
                var go = async function () {
                    b.disabled = true;
                    var body = { op: op, user: p.username };
                    if (extra) Object.keys(extra).forEach(function (k) { body[k] = extra[k]; });
                    var r = await post('user_people', body);
                    b.disabled = false;
                    if (r && r.success) { reload(); return; }
                    peopleFailed(r);
                    return false;
                };
                b.addEventListener('click', function () {
                    if (ask && typeof window.askInPlace === 'function') window.askInPlace(b, ask, go, { host: row });
                    else go();
                });
                return b;
            };
            // Writing to a friend from the row that says they are one. Not on the blocks tab: the
            // point of that list is the people this reader is not talking to.
            if (mayMessage && kind !== 'blocks') {
                var w = el('a', { className: 'btn btn-secondary btn-small',
                                  href: '#messages:' + encodeURIComponent(p.username),
                                  text: t.key('js.pm.message') });
                // The tab bar reacts to the hash CHANGING. Clicking a link to the hash the page is
                // already on changes nothing, so that one case is asked for directly.
                w.addEventListener('click', function () {
                    if (location.hash !== w.getAttribute('href')) return;
                    if (window.PM && typeof window.PM.refresh === 'function') window.PM.refresh(p.username);
                });
                acts.appendChild(w);
            }
            if (kind === 'incoming') { acts.appendChild(act(t.key('js.people.accept'), 'accept')); acts.appendChild(act(t.key('js.people.decline'), 'decline', null, t.key('js.people.decline_q'))); }
            if (kind === 'pending')  acts.appendChild(act(t.key('js.people.cancel'), 'unfollow'));
            if (kind === 'friends')  acts.appendChild(act(t.key('js.people.unfriend'), 'unfollow', null, t.key('js.people.unfriend_q')));
            if (kind === 'blocks') {
                acts.appendChild(act(p.hide_profile ? t.key('js.people.show_profile') : t.key('js.people.hide_profile'),
                                     'block_hide', { value: p.hide_profile ? 0 : 1 }));
                acts.appendChild(act(t.key('js.people.unblock'), 'unblock'));
            }
            row.appendChild(acts);
            return row;
        }

        if (tabs) tabs.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.rt-tab') : null;
            if (!b) return;
            view = b.dataset.view;
            tabs.querySelectorAll('.rt-tab').forEach(function (x) { x.classList.toggle('active', x === b); });
            load();
        });
        if (searchEl) searchEl.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 300); });
        load();
    }

    /* ─────────────────────────── the directory ─────────────────────────── */

    function initDirectory() {
        var dirSeq = 0;   // a newer request supersedes an older answer, whichever lands last
        var root = document.getElementById('member-directory');
        if (!root) return;
        var listEl = document.getElementById('dir-list');
        var pager = document.getElementById('dir-pager');
        var totalEl = document.getElementById('dir-total');
        var searchEl = document.getElementById('dir-search');
        var sortEl = document.getElementById('dir-sort');
        var timer = 0;

        async function load(page) {
            listEl.textContent = '';
            listEl.appendChild(el('div', { className: 'pf-loading', text: t.key('js.common.loading') }));
            var qs = 'user_directory&page=' + (page || 1) + '&per_page=30';
            if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
            if (sortEl && sortEl.value) qs += '&sort=' + encodeURIComponent(sortEl.value);
            var my = ++dirSeq;
            var j = await get(qs);
            if (my !== dirSeq) return;             // a newer request is in flight: this answer is stale
            listEl.textContent = '';
            if (!j || !j.success) { listEl.appendChild(el('div', { className: 'pf-empty', text: t.key('js.fav.load_failed') })); return; }
            if (totalEl) totalEl.textContent = j.total ? t.key('js.people.count', { n: t.num(j.total) }) : '';
            if (!j.rows.length) { listEl.appendChild(el('div', { className: 'pf-empty', text: t.key('js.people.dir_empty') })); return; }
            j.rows.forEach(function (p) {
                var row = el('div', { className: 'pf-row pe-row' });
                row.id = 'dir-row-' + p.username;         // 1.64.0 — see the inbox rows above
                var main = el('div', { className: 'pf-main' });
                var pic = face(p.username, p.avatar, 32, 'pe-av');
                if (pic) main.appendChild(pic);
                main.appendChild(el('a', { className: 'pf-name', href: BASE + '?action=u&name=' + encodeURIComponent(p.username), text: p.username }));
                main.appendChild(el('span', { className: 'text-muted pe-since', text: t.key('js.people.since', { date: p.since }) }));
                if (p.state !== 'none') main.appendChild(el('span', { className: 'pf-badge', text: t.key('js.people.state_' + p.state) }));
                // The reader is in their own directory — they asked to be listed. What they are not
                // offered is a message to themselves or a friend request to themselves.
                if (p.self) main.appendChild(el('span', { className: 'pf-badge pf-badge-you', text: t.key('js.people.you') }));
                row.appendChild(main);
                var acts = el('div', { className: 'pf-acts' });
                if (j.may_message && !p.self) {
                    acts.appendChild(el('a', { className: 'btn btn-secondary btn-small',
                                               href: BASE + '?action=account#messages:' + encodeURIComponent(p.username),
                                               text: t.key('js.pm.message') }));
                }
                if (j.may_friend && p.state === 'none' && !p.self) {
                    var f = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t.key('js.people.follow') });
                    f.addEventListener('click', async function () {
                        f.disabled = true;
                        var r = await post('user_people', { op: 'follow', user: p.username });
                        if (r && r.success) load(page);
                        else { f.disabled = false; peopleFailed(r); }
                    });
                    acts.appendChild(f);
                }
                row.appendChild(acts);
                listEl.appendChild(row);
            });
            if (pager) {
                pager.textContent = '';
                if (j.pages > 1) {
                    var mk = function (label, target, disabled) {
                        var b = el('button', { type: 'button', text: label });
                        b.disabled = !!disabled;
                        b.addEventListener('click', function () { load(target); });
                        return b;
                    };
                    pager.appendChild(mk(t.key('js.common.pg_prev'), j.page - 1, j.page <= 1));
                    pager.appendChild(el('span', { className: 'pg-total', text: t.key('js.app.page_of', { page: j.page, pages: j.pages }) }));
                    pager.appendChild(mk(t.key('js.common.pg_next'), j.page + 1, j.page >= j.pages));
                }
            }
        }
        if (searchEl) searchEl.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 300); });
        if (sortEl) sortEl.addEventListener('change', function () { load(1); });
        // Inside a tab, this is a page most readers of the account page never open, so it is not
        // fetched until it is looked at. The tab bar in assets/js/favourites.js says when.
        var pane = root.closest ? root.closest('.acc-pane') : null;
        var loaded = false;
        window.Directory = { refresh: function () { if (loaded) return; loaded = true; load(1); } };
        // …unless it is already open. The tab bar picks the pane from the address on DOMContentLoaded
        // and assets/js/favourites.js gets that event first, so an arrival straight at #members asked
        // for a refresh before this file had anything to answer with.
        if (!pane || !pane.hidden) { loaded = true; load(1); }
    }

    /* ─────────────────────────── a public profile ─────────────────────────── */

    function initProfileActions() {
        var box = document.getElementById('profile-people');
        if (!box) return;
        var name = box.dataset.user;
        var state = box.dataset.state || 'none';
        var blocked = box.dataset.blocked === '1';

        function draw() {
            box.textContent = '';
            if (box.dataset.pm === '1') {
                var msg = el('a', { className: 'btn btn-secondary btn-small',
                                    href: BASE + '?action=account#messages:' + encodeURIComponent(name),
                                    text: t.key('js.pm.message') });
                box.appendChild(msg);
            }
            if (box.dataset.friends === '1' && !blocked) {
                var label = state === 'friends' ? 'unfriend' : state === 'following' ? 'cancel'
                          : state === 'follower' ? 'accept' : 'follow';
                var op = label === 'accept' ? 'accept' : (state === 'none' ? 'follow' : 'unfollow');
                // Remove friend asks first and looks like what it does (1.74.0, see personRow()); a failure says so.
                var b = el('button', { type: 'button', className: 'btn btn-small ' + (label === 'unfriend' ? 'btn-danger-soft' : 'btn-secondary'),
                                       text: t.key('js.people.' + label) });
                var go = async function () {
                    b.disabled = true;
                    var r = await post('user_people', { op: op, user: name });
                    b.disabled = false;
                    if (r && r.success) { state = r.state || 'none'; draw(); return; }
                    peopleFailed(r);
                    return false;
                };
                b.addEventListener('click', function () {
                    if (label === 'unfriend' && typeof window.askInPlace === 'function') window.askInPlace(b, t.key('js.people.unfriend_q'), go, { host: box });
                    else go();
                });
                box.appendChild(b);
                if (state !== 'none') box.appendChild(el('span', { className: 'pf-badge', text: t.key('js.people.state_' + state) }));
            }
            if (box.dataset.block === '1') {
                var bb = el('button', { type: 'button', className: 'btn btn-secondary btn-small pp-block',
                                        text: t.key(blocked ? 'js.people.unblock' : 'js.people.block') });
                bb.addEventListener('click', function () {
                    if (blocked) {
                        post('user_people', { op: 'unblock', user: name }).then(function (r) {
                            if (r && r.success) { blocked = false; draw(); }
                            else peopleFailed(r);
                        });
                        return;
                    }
                    // Drawn again, the button is a new one: the focus goes to it (now "Unblock"), not to <body>.
                    openBlockDialog(name, function () {
                        blocked = true; state = 'none'; draw();
                        var again = box.querySelector('.pp-block');
                        if (again) again.focus();
                    });
                });
                box.appendChild(bb);
            }
        }
        draw();
    }

    /**
     * The block dialog: two decisions, asked once.
     *
     * Stopping the messages and disappearing from somebody are different things, and the operator
     * asked for both — so the choice is made here, where the person is deciding, rather than being
     * implied by a single button.
     */
    function openBlockDialog(name, onDone) {
        var box = document.getElementById('block-overlay');
        if (!box) {
            // No markup on this page: block without the second question rather than not at all.
            post('user_people', { op: 'block', user: name }).then(function (r) { if (r && r.success && onDone) onDone(); else if (!(r && r.success)) peopleFailed(r); });
            return;
        }
        var who = document.getElementById('bk-who');
        var hide = document.getElementById('bk-hide');
        var go = document.getElementById('bk-go');
        if (who) who.textContent = name;
        if (hide) hide.checked = false;
        // A window like the others (1.74.0, app.js escLayer()): the focus goes into it as it opens, Tab stays in it,
        // and Esc or the × give it back to the Block button — it stayed on that button, Tab walked the page under the
        // window, and Esc left the focus on another part of the page.
        var layer = typeof escLayer === 'function' ? escLayer(box, close) : null;
        function close() {
            box.hidden = true;
            if (layer) layer.off(); else document.removeEventListener('keydown', esc);
        }
        function esc(e) { if (e.key === 'Escape') close(); }
        box.hidden = false;
        if (layer) layer.on(); else document.addEventListener('keydown', esc);
        var x = document.getElementById('bk-close');
        if (x) x.onclick = close;
        // See the report dialog above: the press has to have STARTED on the backdrop (1.64.0).
        var fromBackdrop = false;
        box.onpointerdown = function (e) { fromBackdrop = e.target === box; };
        box.onclick = function (e) { if (e.target === box && fromBackdrop) close(); };
        if (go) go.onclick = async function () {
            go.disabled = true;
            var r = await post('user_people', { op: 'block', user: name, hide_profile: hide && hide.checked ? 1 : 0 });
            go.disabled = false;
            if (r && r.success) { close(); if (onDone) onDone(); }
            else peopleFailed(r);
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        initInbox();
        initPeople();
        initDirectory();
        initProfileActions();
    });
})();
