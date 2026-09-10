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

    /* ─────────────────────────── the inbox ─────────────────────────── */

    function initInbox() {
        var root = document.getElementById('account-messages');
        if (!root) return;
        var list = document.getElementById('pm-threads');
        var pane = document.getElementById('pm-thread');
        var searchEl = document.getElementById('pm-search');
        var openWith = null, timer = 0;

        function badge(n) {
            var b = document.getElementById('pm-unread');
            if (b) { b.textContent = n ? String(n) : ''; b.hidden = !n; }
            var nav = document.querySelector('.nav-pm-badge');
            if (nav) { nav.textContent = n ? String(n) : ''; nav.hidden = !n; }
        }

        async function loadInbox() {
            list.textContent = '';
            list.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var j = await get('user_messages');
            list.textContent = '';
            if (!j || !j.success) { list.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') })); return; }
            badge(j.unread);
            var q = (searchEl && searchEl.value.trim().toLowerCase()) || '';
            var rows = (j.threads || []).filter(function (x) { return !q || x.with.toLowerCase().indexOf(q) !== -1; });
            if (!rows.length) { list.appendChild(el('div', { className: 'pf-empty', text: t('js.pm.no_threads') })); return; }
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

        async function openThread(name) {
            openWith = name;
            pane.textContent = '';
            pane.hidden = false;
            pane.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var j = await get('user_messages&with=' + encodeURIComponent(name));
            if (openWith !== name) return;
            pane.textContent = '';
            if (!j || !j.success) { pane.appendChild(el('div', { className: 'pf-empty', text: t('js.fav.load_failed') })); return; }
            badge(j.unread);

            var head = el('div', { className: 'pm-head' });
            head.appendChild(el('a', { className: 'pm-head-name', href: BASE + '?action=u&name=' + encodeURIComponent(name), text: name }));
            var back = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.pm.back') });
            back.addEventListener('click', function () { pane.hidden = true; openWith = null; loadInbox(); });
            head.appendChild(back);
            var hide = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.pm.hide') });
            hide.addEventListener('click', async function () {
                await post('user_messages', { op: 'hide', with: name });
                pane.hidden = true; openWith = null; loadInbox();
            });
            head.appendChild(hide);
            pane.appendChild(head);

            var body = el('div', { className: 'pm-msgs' });
            (j.rows || []).forEach(function (m) {
                var wrap = el('div', { className: 'pm-msg' + (m.mine ? ' pm-msg-mine' : '') });
                wrap.appendChild(el('div', { className: 'pm-body richtext', html: m.html }));
                var foot = el('div', { className: 'pm-msg-foot text-muted' });
                foot.appendChild(el('span', { text: when(m.created) }));
                if (m.mine && m.read) foot.appendChild(el('span', { className: 'pm-read', text: t('js.pm.read') }));
                if (!m.mine && j.may_report) {
                    var rep = el('button', { type: 'button', className: 'pm-report',
                                             text: m.reported ? t('js.pm.reported') : t('js.pm.report') });
                    rep.disabled = !!m.reported;
                    rep.addEventListener('click', function () { reportMessage(m.id, rep); });
                    foot.appendChild(rep);
                }
                wrap.appendChild(foot);
                body.appendChild(wrap);
            });
            pane.appendChild(body);

            if (j.can_write) {
                pane.appendChild(composer(name, function () { openThread(name); }));
            } else {
                pane.appendChild(el('div', { className: 'pm-closed', text: t('js.pm.why_' + (j.reason || 'nobody')) }));
            }
            body.scrollTop = body.scrollHeight;
        }

        async function reportMessage(id, btn) {
            var reason = prompt(t('js.pm.report_why'));
            if (reason === null) return;
            btn.disabled = true;
            var r = await post('user_messages', { op: 'report', message: id, reason: reason });
            if (r && r.success) { btn.textContent = t('js.pm.reported'); } else { btn.disabled = false; }
        }

        if (searchEl) searchEl.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(loadInbox, 300); });
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

    /** The box somebody types into. Used in the inbox and in the "write to them" overlay. */
    function composer(name, onSent) {
        var wrap = el('div', { className: 'pm-composer' });
        var ta = el('textarea', { className: 'pm-input', rows: 3, maxlength: 20000, placeholder: t('js.pm.write_ph') });
        var send = el('button', { type: 'button', className: 'btn btn-small', text: t('js.pm.send') });
        var msg = el('span', { className: 'text-muted pm-msg-note' });
        send.addEventListener('click', async function () {
            var body = ta.value.trim();
            if (!body) { ta.focus(); return; }
            send.disabled = true;
            var r = await post('user_messages', { op: 'send', to: name, body: body, format: 'bbcode' });
            send.disabled = false;
            if (!r || !r.success) {
                msg.textContent = t('js.pm.why_' + ((r && r.error) || 'failed'));
                return;
            }
            ta.value = '';
            msg.textContent = '';
            if (typeof onSent === 'function') onSent();
        });
        wrap.appendChild(ta);
        var row = el('div', { className: 'pm-composer-row' });
        row.appendChild(send); row.appendChild(msg);
        wrap.appendChild(row);
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
            j.rows.forEach(function (p) { listEl.appendChild(personRow(p, view, load)); });
        }

        function personRow(p, kind, reload) {
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
        var timer = 0;

        async function load(page) {
            listEl.textContent = '';
            listEl.appendChild(el('div', { className: 'pf-loading', text: t('js.common.loading') }));
            var qs = 'user_directory&page=' + (page || 1) + '&per_page=30';
            if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
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
                row.appendChild(main);
                var acts = el('div', { className: 'pf-acts' });
                if (j.may_message) {
                    acts.appendChild(el('a', { className: 'btn btn-secondary btn-small',
                                               href: BASE + '?action=account#messages:' + encodeURIComponent(p.username),
                                               text: t('js.pm.message') }));
                }
                if (j.may_friend && p.state === 'none') {
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
        load(1);
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
