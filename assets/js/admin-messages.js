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
        // as it comes: a t.key() word keeps its key (String() would leave its words only — 1.73.0)
        if (text !== undefined && text !== null) n.textContent = t.isKey(text) ? text : String(text);
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

    /**
     * A sentence with people in it (1.63.0): each person's picture right before their name, wherever
     * the language put the name — through assets/js/avatar.js, from the ADDRESSES the server built,
     * and the plain sentence where that file is not on the page or pictures are switched off.
     */
    function withFaces(tag, cls, key, slots) {
        var span = el(tag, cls);
        var names = Object.keys(slots);
        if (typeof window.userAvatarPhrase !== 'function') {
            var plain = {};
            names.forEach(function (k) { plain[k] = slots[k].name; });
            span.textContent = t.key(key, plain);
            return span;
        }
        var vars = {}, people = [];
        names.forEach(function (k, i) {
            vars[k] = window.userAvatarSlot(i);
            people.push([window.userAvatarImg({ username: slots[k].name, avatar: String(slots[k].avatar || '') }, 20, 'avatar msgrep-av'),
                         slots[k].name]);
        });
        span.appendChild(window.userAvatarPhrase(t.key(key, vars), people));
        return span;
    }

    function card(rep) {
        var c = el('div', 'msgrep-card' + (rep.status === 'closed' ? ' msgrep-closed' : ''));
        var head = el('div', 'msgrep-head');
        head.appendChild(withFaces('span', 'msgrep-who', 'js.msgrep.head', {
            reporter: { name: rep.reporter, avatar: rep.reporter_avatar },
            reported: { name: rep.reported, avatar: rep.reported_avatar },
        }));
        head.appendChild(el('span', 'msgrep-when text-muted', rep.created_at));
        if (rep.status === 'closed') head.appendChild(el('span', 'badge-table badge-reviewed', t.key('js.msgrep.closed')));
        c.appendChild(head);

        // What the reported account already is. A report read without this looks like a first
        // offence whether it is the first or the fifth.
        var st0 = rep.state || {};
        var facts = [];
        if (st0.reports > 1) facts.push(t.key('js.msgrep.seen_before', { n: st0.reports }));
        if (st0.muted_until) facts.push(t.key('js.msgrep.is_muted', { date: st0.muted_until.slice(0, 16) }));
        if (st0.banned) facts.push(st0.banned_until ? t.key('js.msgrep.is_banned_until', { date: st0.banned_until.slice(0, 16) })
                                                    : t.key('js.msgrep.is_banned'));
        if (st0.staff) facts.push(t.key('js.msgrep.is_staff'));
        // The warnings this account has had (1.71.0) — from this card, the reported comments', descriptions' and
        // shouts' cards, anywhere a moderator chose to be loud.
        if (st0.warnings > 0) facts.push(t.key('js.creport.warned_n', { n: st0.warnings }));
        // pieces, each fact a t.key() word that keeps its key (1.73.0: joined into one string they kept none)
        if (facts.length) { var fl = el('div', 'msgrep-state'); facts.forEach(function (f, i) { if (i) fl.append(' · '); fl.append(f); }); c.appendChild(fl); }
        if (st0.latest_warnings && st0.latest_warnings.length) {
            var wl = el('ul', 'msgrep-warnings');
            wl.setAttribute('aria-label', t.key('js.creport.latest_warnings'));
            st0.latest_warnings.forEach(function (w) {
                wl.appendChild(el('li', null, t.key('js.creport.warning_line', { at: String(w.at || '').slice(0, 16), reason: w.reason || '' })));
            });
            c.appendChild(el('div', 'msgrep-warn-head text-muted', t.key('js.creport.latest_warnings')));
            c.appendChild(wl);
        }
        // What was already said about it: the answer the reporter was given, and the note for the log.
        if (rep.reply) c.appendChild(el('div', 'msgrep-answer', t.key('js.msgrep.answered', { text: rep.reply })));
        if (rep.note) c.appendChild(el('div', 'msgrep-answer text-muted', t.key('js.msgrep.noted', { text: rep.note })));

        if (rep.reason) c.appendChild(el('div', 'msgrep-reason', rep.reason));

        // The context first, then the reported line — the order they were said in, which is the
        // only order in which two lines can be read as an exchange.
        if (rep.context) {
            var ctx = el('div', 'msgrep-msg msgrep-ctx');
            ctx.appendChild(withFaces('div', 'msgrep-label text-muted', 'js.msgrep.context',
                                      { user: { name: rep.context.from, avatar: rep.context.from_avatar } }));
            var cb = el('div', 'msgrep-body richtext');
            cb.innerHTML = rep.context.html;          // server-rendered through the shared sanitizer
            ctx.appendChild(cb);
            ctx.appendChild(el('div', 'msgrep-when text-muted', rep.context.at));
            c.appendChild(ctx);
        } else {
            c.appendChild(el('div', 'msgrep-label text-muted', t.key('js.msgrep.none_context')));
        }

        var box = el('div', 'msgrep-msg msgrep-reported');
        box.appendChild(el('div', 'msgrep-label text-muted', t.key('js.msgrep.reported_line')));
        if (rep.message) {
            var mb = el('div', 'msgrep-body richtext');
            mb.innerHTML = rep.message.html;
            box.appendChild(mb);
            box.appendChild(el('div', 'msgrep-when text-muted', rep.message.at));
        } else {
            box.appendChild(el('div', 'msgrep-gone text-muted', t.key('js.msgrep.gone')));
        }
        c.appendChild(box);

        if (mayHandle) {
            var st = rep.state || {};
            var acts = el('div', 'msgrep-acts');
            var note = document.createElement('input');
            note.type = 'text';
            note.className = 'form-control form-control-sm bg-dark text-light border-secondary msgrep-note-in';
            note.placeholder = t.key('js.msgrep.note_ph');
            note.maxLength = 500;
            acts.appendChild(note);
            // The answer to the reporter. A second box rather than a checkbox on the first: the two
            // texts have two readers, and "a note for the log" was being read as "an answer" by the
            // person typing it while nobody ever received it.
            var reply = document.createElement('input');
            reply.type = 'text';
            reply.className = 'form-control form-control-sm bg-dark text-light border-secondary msgrep-reply-in';
            reply.placeholder = t.key('js.msgrep.reply_ph');
            reply.maxLength = 500;
            acts.appendChild(reply);

            var say = el('span', 'msgrep-said text-muted');

            /* ── silently, or as a warning (1.71.0) ───────────────────────────────────────────
             * What the AUTHOR is told when a line of theirs is removed, or they are silenced or banned:
             * nothing, or a warning in their own language with the reason typed here — which a warning
             * needs, and which is kept on the account. Silent is where the choice starts. */
            var modeRow = el('div', 'msgrep-acts msgrep-mode');
            modeRow.appendChild(el('span', 'msgrep-mode-label', t.key('js.creport.mode_label')));
            var radio = function (value, label, on) {
                var lab = el('label', 'msgrep-mode-opt');
                var r = document.createElement('input');
                r.type = 'radio';
                r.name = 'msgrep-mode-' + rep.id;
                r.value = value;
                r.checked = !!on;
                lab.appendChild(r);
                lab.append(' ', label);   // the word as a piece: a t.key() word keeps its key (1.73.0)
                modeRow.appendChild(lab);
                return r;
            };
            radio('silent', t.key('js.creport.mode_silent'), true);
            var loudRadio = radio('loud', t.key('js.creport.mode_loud'), false);
            var reasonIn = document.createElement('input');
            reasonIn.type = 'text';
            reasonIn.className = 'form-control form-control-sm bg-dark text-light border-secondary msgrep-reason-in';
            reasonIn.placeholder = t.key('js.creport.reason_ph');
            reasonIn.setAttribute('aria-label', t.key('js.creport.reason_ph'));
            reasonIn.maxLength = 500;
            modeRow.appendChild(reasonIn);
            modeRow.title = t.key('js.creport.mode_hint');

            /** One action, posted with whatever is in the note field. */
            var run = async function (action, days, btn) {
                var loud = action === 'warn' || loudRadio.checked;
                if (loud && action !== 'close' && action !== 'reopen' && !reasonIn.value.trim()) {
                    say.textContent = t.key('js.creport.err_reason');
                    reasonIn.focus();
                    return;
                }
                btn.disabled = true;
                var r = await api('admin/message_report_action', 'POST',
                                  { id: rep.id, action: action, days: days || 0, note: note.value.trim(), reply: reply.value.trim(),
                                    mode: loud ? 'loud' : 'silent', reason: reasonIn.value.trim() });
                btn.disabled = false;
                if (r && r.success) { load(page); return; }
                // The refusals a moderator can actually hit, said in words rather than left as
                // a button that did nothing.
                say.textContent = t.key(r && r.error === 'target_is_staff' ? 'js.msgrep.err_staff'
                                  : r && r.error === 'target_is_you' ? 'js.msgrep.err_you'
                                  : r && r.error === 'reason_required' ? 'js.creport.err_reason'
                                  : r && r.error === 'ban_is_permanent' ? 'js.creport.err_permanent'
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
                    var yes = button(t.key('js.msgrep.yes'), 'btn-danger', function (yb) { go(yb); });
                    var no = button(t.key('js.msgrep.no'), 'btn-outline-secondary', function () { row.replaceWith(t.relabel(b)); });
                    row.appendChild(yes); row.appendChild(no);
                    b.replaceWith(row);
                });
            };

            acts.appendChild(button(rep.status === 'closed' ? t.key('js.msgrep.reopen') : t.key('js.msgrep.close'),
                                    'btn-outline-secondary',
                                    function (b) { run(rep.status === 'closed' ? 'reopen' : 'close', 0, b); }));
            if (rep.message) {
                acts.appendChild(confirmThen(acts, t.key('js.msgrep.delete_q'), t.key('js.msgrep.delete'), 'btn-outline-danger',
                                             function (b) { run('delete_message', 0, b); }));
            }
            // A warning and nothing else (1.71.0) — always told, so it asks the reason; never to staff.
            if (!st.staff) {
                acts.appendChild(confirmThen(acts, t.key('js.creport.warn_q', { user: rep.reported }), t.key('js.creport.warn'), 'btn-outline-warning',
                                             function (b) { run('warn', 0, b); }));
            }
            c.appendChild(acts);
            if (!st.staff) c.appendChild(modeRow);

            /* ── what to do about the ACCOUNT ─────────────────────────────────────────────────
             * Deleting the line answers the message. It does not answer the person, and until now
             * that was the only answer this page had. */
            var pun = el('div', 'msgrep-acts msgrep-punish');
            if (st.staff) {
                pun.appendChild(el('span', 'text-muted', t.key('js.msgrep.staff_note')));
            } else {
                var pick = document.createElement('select');
                pick.className = 'form-select form-select-sm bg-dark text-light border-secondary msgrep-pick';
                [['mute:1', 'mute_1'], ['mute:7', 'mute_7'], ['mute:30', 'mute_30'], ['mute:0', 'mute_forever'],
                 ['ban:7', 'ban_7'], ['ban:30', 'ban_30'], ['ban:0', 'ban_forever']].forEach(function (o) {
                    var op = document.createElement('option');
                    op.value = o[0];
                    op.textContent = t.key('js.msgrep.' + o[1]);
                    pick.appendChild(op);
                });
                pun.appendChild(pick);
                pun.appendChild(button(t.key('js.msgrep.apply'), 'btn-outline-warning', function (b) {
                    var parts = pick.value.split(':');
                    var q = t.key('js.msgrep.sure_' + parts[0], { user: rep.reported });
                    var row = el('span', 'msgrep-confirm');
                    row.appendChild(el('span', 'msgrep-confirm-q', q));
                    row.appendChild(button(t.key('js.msgrep.yes'), 'btn-danger', function (yb) { run(parts[0], Number(parts[1]), yb); }));
                    row.appendChild(button(t.key('js.msgrep.no'), 'btn-outline-secondary', function () { row.replaceWith(t.relabel(b)); }));
                    b.replaceWith(row);
                }));
                if (st.muted_until) pun.appendChild(button(t.key('js.msgrep.unmute'), 'btn-outline-success', function (b) { run('unmute', 0, b); }));
                // Only a ban this card can lift: one with a date. A dateless ban is the owner's, from the Users page.
                if (st.banned && st.banned_until) pun.appendChild(button(t.key('js.msgrep.unban'), 'btn-outline-success', function (b) { run('unban', 0, b); }));
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
        if (!j || !j.reports) { listEl.appendChild(el('div', 'text-muted py-3', t.key('js.msgrep.empty'))); return; }
        mayHandle = !!j.may_handle;
        if (badge) {
            badge.textContent = j.open ? String(j.open) : '';
            badge.classList.toggle('d-hidden', !j.open);
        }
        if (!j.reports.length) { listEl.appendChild(el('div', 'text-muted py-3', t.key('js.msgrep.empty'))); return; }
        j.reports.forEach(function (r) { listEl.appendChild(card(r)); });

        pagerEl.textContent = '';
        if (j.pages > 1) {
            // Chevrons as icons (1.68.0), with the words a screen reader needs on the button.
            var mk = function (icon, label, target, disabled) {
                var b = el('button');
                var i = el('i', icon);
                i.setAttribute('aria-hidden', 'true');
                b.appendChild(i);
                b.title = label;
                b.setAttribute('aria-label', label);
                b.disabled = !!disabled;
                b.addEventListener('click', function () { load(target); });
                return b;
            };
            pagerEl.appendChild(mk('bi bi-chevron-left', t.key('js.common.pg_prev'), j.page - 1, j.page <= 1));
            pagerEl.appendChild(el('span', 'pg-total', j.page + ' / ' + j.pages));
            pagerEl.appendChild(mk('bi bi-chevron-right', t.key('js.common.pg_next'), j.page + 1, j.page >= j.pages));
        }
    }

    if (searchEl) searchEl.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });
    if (statusEl) statusEl.addEventListener('change', function () { load(1); });

    // The source tabs switch between the reports table and this view. The table's own script owns
    // the tab bar, so this listens rather than takes over: one click, two readers.
    var TORRENT = ['reports', 'archives', 'appeals', 'appeal_archives'];
    document.addEventListener('click', function (e) {
        var tab = e.target.closest ? e.target.closest('.source-tab') : null;
        if (!tab) return;
        var mine = tab.dataset.source === 'messages';
        view.classList.toggle('d-hidden', !mine);
        // The torrent reports' toolbar, table and pages are up on their own four tabs and nowhere else
        // (1.71.0: the reported comments, descriptions and shouts are tabs of this bar too).
        var torrent = TORRENT.indexOf(tab.dataset.source) >= 0;
        document.querySelectorAll('[data-torrent-part]').forEach(function (n) { n.classList.toggle('d-hidden', !torrent); });
        if (mine) load(1);
    });

    // The badge is worth having before anybody opens the tab: an unattended queue is the thing an
    // operator most needs to be told about. When this is the first tab the session has (it may read
    // messages and not the torrent queue), the page opens on it.
    load(1);
    var own = document.querySelector('.source-tab[data-source="messages"]');
    view.classList.toggle('d-hidden', !(own && own.classList.contains('active')));
})();
