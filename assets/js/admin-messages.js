/**
 * The reported-messages view on the Reports page.
 *
 * ── what this file may show, and what it must not ──────────────────────────────────────────────
 *
 * A report carries the message it names and the one before it. That is what the endpoint returns
 * and it is all this renders: there is no "open the conversation" button here, because there is no
 * endpoint behind one and there is not meant to be. Two people's correspondence is not evidence in
 * bulk, and somebody deciding about one line does not need the rest of it.
 *
 * Loaded only on the Reports page, and only where `panel.messages.view` is held — the tab itself is
 * not drawn otherwise (templates/admin/dashboard.php).
 */
(function () {
    'use strict';

    var view = document.getElementById('msgrep-view');
    if (!view) return;

    var listEl = document.getElementById('msgrep-list');
    var pagerEl = document.getElementById('msgrep-pagination');
    var searchEl = document.getElementById('msgrep-search');
    var statusEl = document.getElementById('msgrep-status');
    var badge = document.getElementById('msgrep-badge');
    var page = 1, timer = 0, mayHandle = false;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    async function api(endpoint, method, body) {
        try {
            var opts = { method: method || 'GET', credentials: 'same-origin',
                         headers: { Accept: 'application/json' } };
            if (body) {
                opts.headers['Content-Type'] = 'application/json';
                // WHERE THE PANEL KEEPS ITS TOKEN: on the body, as `data-csrf` (see the CSRF()
                // helper in admin-common.js). This file looked for a <meta> and an <input>, found
                // neither, and posted an empty token — so every action on this card was answered
                // 403 and looked like a button that did nothing. The two fallbacks stay for a page
                // that carries the token the older way.
                var tok = document.body.dataset.csrf
                       || (document.querySelector('meta[name="csrf-token"]') || {}).content
                       || (document.querySelector('input[name="csrf_token"]') || {}).value || '';
                opts.headers['X-CSRF-Token'] = tok;
                body.csrf_token = tok;
                opts.body = JSON.stringify(body);
            }
            var r = await fetch('api.php?endpoint=' + endpoint, opts);
            return await r.json();
        } catch (e) { return null; }
    }

    function card(rep) {
        var c = el('div', 'msgrep-card' + (rep.status === 'closed' ? ' msgrep-closed' : ''));
        var head = el('div', 'msgrep-head');
        head.appendChild(el('span', 'msgrep-who', t('js.msgrep.head', { reporter: rep.reporter, reported: rep.reported })));
        head.appendChild(el('span', 'msgrep-when text-muted', rep.created_at));
        if (rep.status === 'closed') head.appendChild(el('span', 'badge-table badge-reviewed', t('js.msgrep.closed')));
        c.appendChild(head);

        // What the reported account already is. A report read without this looks like a first
        // offence whether it is the first or the fifth.
        var st0 = rep.state || {};
        var facts = [];
        if (st0.reports > 1) facts.push(t('js.msgrep.seen_before', { n: st0.reports }));
        if (st0.muted_until) facts.push(t('js.msgrep.is_muted', { date: st0.muted_until.slice(0, 16) }));
        if (st0.banned) facts.push(st0.banned_until ? t('js.msgrep.is_banned_until', { date: st0.banned_until.slice(0, 16) })
                                                    : t('js.msgrep.is_banned'));
        if (st0.staff) facts.push(t('js.msgrep.is_staff'));
        if (facts.length) c.appendChild(el('div', 'msgrep-state', facts.join(' · ')));

        if (rep.reason) c.appendChild(el('div', 'msgrep-reason', rep.reason));

        // The context first, then the reported line — the order they were said in, which is the
        // only order in which two lines can be read as an exchange.
        if (rep.context) {
            var ctx = el('div', 'msgrep-msg msgrep-ctx');
            ctx.appendChild(el('div', 'msgrep-label text-muted', t('js.msgrep.context', { user: rep.context.from })));
            var cb = el('div', 'msgrep-body richtext');
            cb.innerHTML = rep.context.html;          // server-rendered through the shared sanitizer
            ctx.appendChild(cb);
            ctx.appendChild(el('div', 'msgrep-when text-muted', rep.context.at));
            c.appendChild(ctx);
        } else {
            c.appendChild(el('div', 'msgrep-label text-muted', t('js.msgrep.none_context')));
        }

        var box = el('div', 'msgrep-msg msgrep-reported');
        box.appendChild(el('div', 'msgrep-label text-muted', t('js.msgrep.reported_line')));
        if (rep.message) {
            var mb = el('div', 'msgrep-body richtext');
            mb.innerHTML = rep.message.html;
            box.appendChild(mb);
            box.appendChild(el('div', 'msgrep-when text-muted', rep.message.at));
        } else {
            box.appendChild(el('div', 'msgrep-gone text-muted', t('js.msgrep.gone')));
        }
        c.appendChild(box);

        if (mayHandle) {
            var st = rep.state || {};
            var acts = el('div', 'msgrep-acts');
            var note = document.createElement('input');
            note.type = 'text';
            note.className = 'form-control form-control-sm bg-dark text-light border-secondary msgrep-note-in';
            note.placeholder = t('js.msgrep.note_ph');
            note.maxLength = 500;
            acts.appendChild(note);

            var say = el('span', 'msgrep-said text-muted');

            /** One action, posted with whatever is in the note field. */
            var run = async function (action, days, btn) {
                btn.disabled = true;
                var r = await api('admin/message_report_action', 'POST',
                                  { id: rep.id, action: action, days: days || 0, note: note.value.trim() });
                btn.disabled = false;
                if (r && r.success) { load(page); return; }
                // The two refusals a moderator can actually hit, said in words rather than left as
                // a button that did nothing.
                say.textContent = t(r && r.error === 'target_is_staff' ? 'js.msgrep.err_staff'
                                  : r && r.error === 'target_is_you' ? 'js.msgrep.err_you'
                                  : 'js.msgrep.err_failed');
            };

            var button = function (label, cls, onClick) {
                var b = el('button', 'btn btn-sm ' + (cls || 'btn-outline-secondary'), label);
                b.addEventListener('click', function () { onClick(b); });
                return b;
            };

            /**
             * Anything that cannot be undone asks first, in place.
             *
             * The old delete armed itself on the first click and disarmed after four seconds, which
             * is a confirmation somebody has to WIN — and it looked, correctly, like a button that
             * did not work. This is two buttons: the question stays until it is answered.
             */
            var confirmThen = function (holder, question, label, cls, go) {
                return button(label, cls, function (b) {
                    var row = el('span', 'msgrep-confirm');
                    row.appendChild(el('span', 'msgrep-confirm-q', question));
                    var yes = button(t('js.msgrep.yes'), 'btn-danger', function (yb) { go(yb); });
                    var no = button(t('js.msgrep.no'), 'btn-outline-secondary', function () { row.replaceWith(b); });
                    row.appendChild(yes); row.appendChild(no);
                    b.replaceWith(row);
                });
            };

            acts.appendChild(button(rep.status === 'closed' ? t('js.msgrep.reopen') : t('js.msgrep.close'),
                                    'btn-outline-secondary',
                                    function (b) { run(rep.status === 'closed' ? 'reopen' : 'close', 0, b); }));
            if (rep.message) {
                acts.appendChild(confirmThen(acts, t('js.msgrep.delete_q'), t('js.msgrep.delete'), 'btn-outline-danger',
                                             function (b) { run('delete_message', 0, b); }));
            }
            c.appendChild(acts);

            /* ── what to do about the ACCOUNT ─────────────────────────────────────────────────
             * Deleting the line answers the message. It does not answer the person, and until now
             * that was the only answer this page had. */
            var pun = el('div', 'msgrep-acts msgrep-punish');
            if (st.staff) {
                pun.appendChild(el('span', 'text-muted', t('js.msgrep.staff_note')));
            } else {
                var pick = document.createElement('select');
                pick.className = 'form-select form-select-sm bg-dark text-light border-secondary msgrep-pick';
                [['mute:1', 'mute_1'], ['mute:7', 'mute_7'], ['mute:30', 'mute_30'], ['mute:0', 'mute_forever'],
                 ['ban:7', 'ban_7'], ['ban:30', 'ban_30'], ['ban:0', 'ban_forever']].forEach(function (o) {
                    var op = document.createElement('option');
                    op.value = o[0];
                    op.textContent = t('js.msgrep.' + o[1]);
                    pick.appendChild(op);
                });
                pun.appendChild(pick);
                pun.appendChild(button(t('js.msgrep.apply'), 'btn-outline-warning', function (b) {
                    var parts = pick.value.split(':');
                    var q = t('js.msgrep.sure_' + parts[0], { user: rep.reported });
                    var row = el('span', 'msgrep-confirm');
                    row.appendChild(el('span', 'msgrep-confirm-q', q));
                    row.appendChild(button(t('js.msgrep.yes'), 'btn-danger', function (yb) { run(parts[0], Number(parts[1]), yb); }));
                    row.appendChild(button(t('js.msgrep.no'), 'btn-outline-secondary', function () { row.replaceWith(b); }));
                    b.replaceWith(row);
                }));
                if (st.muted_until) pun.appendChild(button(t('js.msgrep.unmute'), 'btn-outline-success', function (b) { run('unmute', 0, b); }));
                if (st.banned) pun.appendChild(button(t('js.msgrep.unban'), 'btn-outline-success', function (b) { run('unban', 0, b); }));
            }
            pun.appendChild(say);
            c.appendChild(pun);
        }
        return c;
    }

    async function load(p) {
        page = p || 1;
        listEl.textContent = '';
        var qs = 'admin/fetch_message_reports&status=' + (statusEl ? statusEl.value : 'open') + '&page=' + page;
        if (searchEl && searchEl.value.trim()) qs += '&search=' + encodeURIComponent(searchEl.value.trim());
        var j = await api(qs);
        listEl.textContent = '';
        if (!j || !j.reports) { listEl.appendChild(el('div', 'text-muted py-3', t('js.msgrep.empty'))); return; }
        mayHandle = !!j.may_handle;
        if (badge) {
            badge.textContent = j.open ? String(j.open) : '';
            badge.classList.toggle('d-hidden', !j.open);
        }
        if (!j.reports.length) { listEl.appendChild(el('div', 'text-muted py-3', t('js.msgrep.empty'))); return; }
        j.reports.forEach(function (r) { listEl.appendChild(card(r)); });

        pagerEl.textContent = '';
        if (j.pages > 1) {
            var mk = function (label, target, disabled) {
                var b = el('button', null, label);
                b.disabled = !!disabled;
                b.addEventListener('click', function () { load(target); });
                return b;
            };
            pagerEl.appendChild(mk('‹', j.page - 1, j.page <= 1));
            pagerEl.appendChild(el('span', 'pg-total', j.page + ' / ' + j.pages));
            pagerEl.appendChild(mk('›', j.page + 1, j.page >= j.pages));
        }
    }

    if (searchEl) searchEl.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });
    if (statusEl) statusEl.addEventListener('change', function () { load(1); });

    // The source tabs switch between the reports table and this view. The table's own script owns
    // the tab bar, so this listens rather than takes over: one click, two readers.
    document.addEventListener('click', function (e) {
        var tab = e.target.closest ? e.target.closest('.source-tab') : null;
        if (!tab) return;
        var mine = tab.dataset.source === 'messages';
        view.classList.toggle('d-hidden', !mine);
        // Everything the reports table draws goes away while this view is up, and comes back after.
        ['reports-table-card', 'pagination'].forEach(function (id) {
            var n = document.getElementById(id);
            if (n) n.classList.toggle('d-hidden', mine);
        });
        var toolbars = document.querySelectorAll('.admin-toolbar-card');
        toolbars.forEach(function (n) {
            if (view.contains(n)) return;
            n.classList.toggle('d-hidden', mine);
        });
        if (mine) load(1);
    });

    // The badge is worth having before anybody opens the tab: an unattended queue is the thing an
    // operator most needs to be told about.
    load(1);
    view.classList.add('d-hidden');
})();
