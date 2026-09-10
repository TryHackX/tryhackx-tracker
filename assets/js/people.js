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
            else if (k === 'dataset') Object.keys(v).forEach(function (d) { n.dataset[d] = v[d]; });
            else n.setAttribute(k, v === true ? '' : v);
        });
        (Array.isArray(kids) ? kids : kids ? [kids] : []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
        });
        return n;
    }

    function csrf() {
        var i = document.getElementById('account-csrf')
             || document.getElementById('search-csrf')
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
    function when(s) { return String(s || '').replace('T', ' ').slice(0, 16); }

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

        function badge(n) {
            var b = document.getElementById('pm-unread');
            if (b) { b.textContent = n ? String(n) : ''; b.hidden = !n; }
            var nav = document.querySelector('.nav-pm-badge');
            if (nav) { nav.textContent = n ? String(n) : ''; nav.hidden = !n; }
        }

        async function loadInbox() {
            list.textContent = '';
            list.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            // Names are filtered here, because the list is already in the browser. Looking inside
            // the messages is the server's job and costs a request, so it only happens when the
            // box beside the filter is ticked and there is something to look for.
            var q = (searchEl && searchEl.value.trim()) || '';
            var deep = !!(deepEl && deepEl.checked) && q.length >= 2;
            var j = await get('user_messages' + (deep ? '&deep=1&search=' + encodeURIComponent(q) : ''));
            list.textContent = '';
            if (!j || !j.success) {
                list.appendChild(el('div', { className: 'pf-empty',
                    text: t(j && j.error === 'rate_limit' ? 'js.pm.search_slow' : 'js.fav.load_failed') }));
                return;
            }
            badge(j.unread);
            var ql = q.toLowerCase();
            var rows = (j.threads || []).filter(function (x) { return deep || !ql || x.with.toLowerCase().indexOf(ql) !== -1; });
            if (!rows.length) { list.appendChild(el('div', { className: 'pf-empty', text: t(q ? 'js.pm.no_match' : 'js.pm.no_threads') })); return; }
            rows.forEach(function (x) {
                var row = el('button', { type: 'button', className: 'pm-row' + (x.unread ? ' pm-row-unread' : '') });
                row.appendChild(el('span', { className: 'pm-who', text: x.with }));
                row.appendChild(el('span', { className: 'pm-preview text-muted', text: (x.mine ? t('js.pm.you_prefix') : '') + x.preview }));
                if (x.unread) row.appendChild(el('span', { className: 'pm-count', text: String(x.unread) }));
                row.appendChild(el('span', { className: 'pm-when text-muted', text: when(x.last_at) }));
                row.addEventListener('click', function () { openThread(x.with); });
                list.appendChild(row);
            });
        }

        function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = 0; } }

        /** One message, as a row. The first draw and every later arrival go through here. */
        function renderMsg(m) {
            var wrap = el('div', { className: 'pm-msg' + (m.mine ? ' pm-msg-mine' : '') });
            wrap.dataset.id = String(m.id || 0);
            if (m.mine) wrap.dataset.mine = '1';
            wrap.appendChild(el('div', { className: 'pm-body richtext', html: m.html }));
            var foot = el('div', { className: 'pm-msg-foot text-muted' });
            foot.appendChild(el('span', { text: when(m.created) }));
            if (m.mine && m.read) foot.appendChild(el('span', { className: 'pm-read', text: t('js.pm.read') }));
            if (!m.mine && mayReport) {
                var rep = el('button', { type: 'button', className: 'pm-report',
                                         title: t('js.pm.report_title'),
                                         text: (m.reported ? '⚑ ' : '⚐ ') + (m.reported ? t('js.pm.reported') : t('js.pm.report')) });
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
            if (!j || !j.success || openWith !== name || !msgsBox) return;
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
                badge(j.unread);
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
                        foot.appendChild(el('span', { className: 'pm-read', text: t('js.pm.read') }));
                    }
                });
            }
            if (typingLine) typingLine.hidden = !j.typing;
        }

        async function openThread(name) {
            openWith = name;
            stopPoll();
            msgsBox = null; typingLine = null; lastId = 0;
            pane.textContent = '';
            pane.hidden = false;
            pane.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var j = await get('user_messages&with=' + encodeURIComponent(name));
            if (openWith !== name) return;
            pane.textContent = '';
            if (!j || !j.success) {
                // "There is no such account" is an answer, not a failure to load one — and it is the
                // answer somebody typing a name into the box above will get wrong first.
                pane.appendChild(el('div', { className: 'pf-empty',
                    text: t(j && j.error === 'not_found' ? 'js.pm.why_not_found' : 'js.fav.load_failed') }));
                return;
            }
            badge(j.unread);

            var head = el('div', { className: 'pm-head' });
            head.appendChild(el('a', { className: 'pm-head-name', href: BASE + '?action=u&name=' + encodeURIComponent(name), text: name }));
            var back = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.pm.back') });
            back.addEventListener('click', function () { stopPoll(); pane.hidden = true; openWith = null; loadInbox(); });
            head.appendChild(back);
            var hide = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.pm.hide') });
            hide.addEventListener('click', async function () {
                await post('user_messages', { op: 'hide', with: name });
                stopPoll();
                pane.hidden = true; openWith = null; loadInbox();
            });
            head.appendChild(hide);
            pane.appendChild(head);

            mayReport = !!j.may_report;
            var body = el('div', { className: 'pm-msgs' });
            (j.rows || []).forEach(function (m) {
                if (m.id > lastId) lastId = m.id;
                body.appendChild(renderMsg(m));
            });
            pane.appendChild(body);
            msgsBox = body;
            // "…is writing", under the conversation and above the box being written in — which is
            // where it is true.
            typingLine = el('div', { className: 'pm-typing text-muted', text: t('js.pm.typing', { user: name }) });
            typingLine.hidden = true;
            pane.appendChild(typingLine);
            typingAllowed = !!j.typing_on;
            live = Number(j.live || 0);

            if (j.can_write) {
                mountComposer(pane, name, function () { openThread(name); });
            } else {
                pane.appendChild(el('div', { className: 'pm-closed', text: t('js.pm.why_' + (j.reason || 'nobody')) }));
            }
            body.scrollTop = body.scrollHeight;
            // Only once the conversation is on screen: a timer started before the first draw would
            // ask about a thread this page has not read yet.
            if (live > 0) pollTimer = setInterval(pollOnce, live * 1000);
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
            box.onclick = function (e) { if (e.target === box) close(); };
            var x = document.getElementById('pmreport-close');
            if (x) x.onclick = close;
            go.onclick = async function () {
                go.disabled = true;
                var r = await post('user_messages', { op: 'report', message: id, reason: why.value.trim() });
                go.disabled = false;
                if (!r || !r.success) { msg.textContent = t('js.pm.report_failed'); return; }
                btn.textContent = t('js.pm.reported');
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
        if (deepEl) deepEl.addEventListener('change', loadInbox);
        // The tab bar shows this pane without reloading the page — a hash change is a same-document
        // navigation, and clicking a tab is not a navigation at all. Without a hook the inbox stayed
        // whatever it was when the page first loaded, which is exactly when somebody has come back
        // to it to see what arrived.
        window.PM = {
            refresh: function (name) {
                if (name) { openThread(name); return; }
                if (openWith) openThread(openWith); else loadInbox();
            },
        };
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
            wrap.appendChild(tpl.content.cloneNode(true));
            ta = wrap.querySelector('#pm-body');
        }
        if (!ta) {
            rich = false;
            ta = el('textarea', { className: 'pm-input', rows: 3, maxlength: 20000, placeholder: t('js.pm.write_ph') });
            wrap.appendChild(ta);
        }
        var send = el('button', { type: 'button', className: 'btn btn-small', text: t('js.pm.send') });
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
            var body = ta.value.trim();
            if (!body) { ta.focus(); return; }
            var fmtEl = rich ? document.getElementById('pm-body-format') : null;
            send.disabled = true;
            var r = await post('user_messages', {
                op: 'send', to: name, body: body,
                // Whichever tab they wrote in. The server validates it either way — richtext.php is
                // the one place that decides what a message may contain.
                format: fmtEl && fmtEl.value ? fmtEl.value : 'bbcode',
            });
            send.disabled = false;
            if (!r || !r.success) {
                msg.textContent = t('js.pm.why_' + ((r && r.error) || 'failed'));
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

        async function load() {
            listEl.textContent = '';
            listEl.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = 'user_people&view=' + view;
            if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
            var j = await get(qs);
            listEl.textContent = '';
            if (!j || !j.success) { listEl.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') })); return; }
            counts(j.counts || {});
            if (!j.rows.length) { listEl.appendChild(el('div', { className: 'pf-empty', text: t('js.people.empty_' + view) })); return; }
            j.rows.forEach(function (p) { listEl.appendChild(personRow(p, view, load, !!j.may_message)); });
        }

        function personRow(p, kind, reload, mayMessage) {
            var row = el('div', { className: 'pf-row pe-row' });
            var main = el('div', { className: 'pf-main' });
            main.appendChild(el('a', { className: 'pf-name', href: BASE + '?action=u&name=' + encodeURIComponent(p.username), text: p.username }));
            if (p.since) main.appendChild(el('span', { className: 'text-muted pe-since', text: when(p.since) }));
            if (kind === 'blocks' && p.hide_profile) main.appendChild(el('span', { className: 'pf-badge', text: t('js.people.hidden') }));
            row.appendChild(main);
            var acts = el('div', { className: 'pf-acts' });
            var act = function (label, op, extra) {
                var b = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: label });
                b.addEventListener('click', async function () {
                    b.disabled = true;
                    var body = { op: op, user: p.username };
                    if (extra) Object.keys(extra).forEach(function (k) { body[k] = extra[k]; });
                    var r = await post('user_people', body);
                    b.disabled = false;
                    if (r && r.success) reload();
                });
                return b;
            };
            // Writing to a friend from the row that says they are one. Not on the blocks tab: the
            // point of that list is the people this reader is not talking to.
            if (mayMessage && kind !== 'blocks') {
                var w = el('a', { className: 'btn btn-secondary btn-small',
                                  href: '#messages:' + encodeURIComponent(p.username),
                                  text: t('js.pm.message') });
                // The tab bar reacts to the hash CHANGING. Clicking a link to the hash the page is
                // already on changes nothing, so that one case is asked for directly.
                w.addEventListener('click', function () {
                    if (location.hash !== w.getAttribute('href')) return;
                    if (window.PM && typeof window.PM.refresh === 'function') window.PM.refresh(p.username);
                });
                acts.appendChild(w);
            }
            if (kind === 'incoming') { acts.appendChild(act(t('js.people.accept'), 'accept')); acts.appendChild(act(t('js.people.decline'), 'decline')); }
            if (kind === 'pending')  acts.appendChild(act(t('js.people.cancel'), 'unfollow'));
            if (kind === 'friends')  acts.appendChild(act(t('js.people.unfriend'), 'unfollow'));
            if (kind === 'blocks') {
                acts.appendChild(act(p.hide_profile ? t('js.people.show_profile') : t('js.people.hide_profile'),
                                     'block_hide', { value: p.hide_profile ? 0 : 1 }));
                acts.appendChild(act(t('js.people.unblock'), 'unblock'));
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
            listEl.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = 'user_directory&page=' + (page || 1) + '&per_page=30';
            if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
            if (sortEl && sortEl.value) qs += '&sort=' + encodeURIComponent(sortEl.value);
            var j = await get(qs);
            listEl.textContent = '';
            if (!j || !j.success) { listEl.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') })); return; }
            if (totalEl) totalEl.textContent = j.total ? t('js.people.count', { n: j.total.toLocaleString() }) : '';
            if (!j.rows.length) { listEl.appendChild(el('div', { className: 'pf-empty', text: t('js.people.dir_empty') })); return; }
            j.rows.forEach(function (p) {
                var row = el('div', { className: 'pf-row pe-row' });
                var main = el('div', { className: 'pf-main' });
                main.appendChild(el('a', { className: 'pf-name', href: BASE + '?action=u&name=' + encodeURIComponent(p.username), text: p.username }));
                main.appendChild(el('span', { className: 'text-muted pe-since', text: t('js.people.since', { date: p.since }) }));
                if (p.state !== 'none') main.appendChild(el('span', { className: 'pf-badge', text: t('js.people.state_' + p.state) }));
                // The reader is in their own directory — they asked to be listed. What they are not
                // offered is a message to themselves or a friend request to themselves.
                if (p.self) main.appendChild(el('span', { className: 'pf-badge pf-badge-you', text: t('js.people.you') }));
                row.appendChild(main);
                var acts = el('div', { className: 'pf-acts' });
                if (j.may_message && !p.self) {
                    acts.appendChild(el('a', { className: 'btn btn-secondary btn-small',
                                               href: BASE + '?action=account#messages:' + encodeURIComponent(p.username),
                                               text: t('js.pm.message') }));
                }
                if (j.may_friend && p.state === 'none' && !p.self) {
                    var f = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.people.follow') });
                    f.addEventListener('click', async function () {
                        f.disabled = true;
                        var r = await post('user_people', { op: 'follow', user: p.username });
                        if (r && r.success) load(page);
                        else f.disabled = false;
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
                    pager.appendChild(mk(t('js.common.pg_prev'), j.page - 1, j.page <= 1));
                    pager.appendChild(el('span', { className: 'pg-total', text: t('js.app.page_of', { page: j.page, pages: j.pages }) }));
                    pager.appendChild(mk(t('js.common.pg_next'), j.page + 1, j.page >= j.pages));
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
                                    text: t('js.pm.message') });
                box.appendChild(msg);
            }
            if (box.dataset.friends === '1' && !blocked) {
                var label = state === 'friends' ? 'unfriend' : state === 'following' ? 'cancel'
                          : state === 'follower' ? 'accept' : 'follow';
                var op = label === 'accept' ? 'accept' : (state === 'none' ? 'follow' : 'unfollow');
                var b = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.people.' + label) });
                b.addEventListener('click', async function () {
                    b.disabled = true;
                    var r = await post('user_people', { op: op, user: name });
                    b.disabled = false;
                    if (r && r.success) { state = r.state || 'none'; draw(); }
                });
                box.appendChild(b);
                if (state !== 'none') box.appendChild(el('span', { className: 'pf-badge', text: t('js.people.state_' + state) }));
            }
            if (box.dataset.block === '1') {
                var bb = el('button', { type: 'button', className: 'btn btn-secondary btn-small',
                                        text: t(blocked ? 'js.people.unblock' : 'js.people.block') });
                bb.addEventListener('click', function () {
                    if (blocked) {
                        post('user_people', { op: 'unblock', user: name }).then(function (r) {
                            if (r && r.success) { blocked = false; draw(); }
                        });
                        return;
                    }
                    openBlockDialog(name, function () { blocked = true; state = 'none'; draw(); });
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
            post('user_people', { op: 'block', user: name }).then(function (r) { if (r && r.success && onDone) onDone(); });
            return;
        }
        var who = document.getElementById('bk-who');
        var hide = document.getElementById('bk-hide');
        var go = document.getElementById('bk-go');
        if (who) who.textContent = name;
        if (hide) hide.checked = false;
        box.hidden = false;
        function close() { box.hidden = true; document.removeEventListener('keydown', esc); }
        function esc(e) { if (e.key === 'Escape') close(); }
        document.addEventListener('keydown', esc);
        var x = document.getElementById('bk-close');
        if (x) x.onclick = close;
        box.onclick = function (e) { if (e.target === box) close(); };
        if (go) go.onclick = async function () {
            go.disabled = true;
            var r = await post('user_people', { op: 'block', user: name, hide_profile: hide && hide.checked ? 1 : 0 });
            go.disabled = false;
            if (r && r.success) { close(); if (onDone) onDone(); }
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        initInbox();
        initPeople();
        initDirectory();
        initProfileActions();
    });
})();
