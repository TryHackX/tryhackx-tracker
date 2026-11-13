/**
 * The reported comments, descriptions and shouts on the Reports page (1.71.0, includes/reports.php).
 *
 * ── what a card is ──────────────────────────────────────────────────────────────────────────────
 * One reported THING, not one report: its words once — rendered on the server by that kind's own renderer,
 * the one the public page uses —, and, when they changed after the report or are gone, the words as they were
 * reported; where it lives (a link to the page, in a new tab); its author and what the account already is
 * (banned, silenced, staff, how often reported, how often warned and the latest warnings); and every report
 * about it, newest first — the reporter, the reason, and what became of it.
 *
 * ── what a moderator may do ─────────────────────────────────────────────────────────────────────
 * With `panel.reports.<kind>.handle`: Close / Reopen, Remove the words, Warn the author, silence or ban the
 * author for a length (and lift it). Every action that reaches the author is SILENT or LOUD — the radio pair on
 * the card: silently, the author is told nothing; as a warning, they get a notification in their own language
 * with the reason typed here (which a warning needs), kept on their account. The reporters hear the outcome,
 * with the answer typed for them; nobody is ever told who reported. An account that can open the panel, and
 * your own, are offered nothing (the server refuses them too), and a guest's words have no account to act on.
 *
 * The tab bar belongs to assets/js/admin.js; this listens to it, as admin-messages.js does, and shows its view
 * on its three tabs. Loaded only where one of them is drawn (templates/admin/dashboard.php).
 */
(function () {
    'use strict';

    var view = document.getElementById('crep-view');
    if (!view) return;

    var listEl = document.getElementById('crep-list');
    var pagerEl = document.getElementById('crep-pagination');
    var saidEl = document.getElementById('crep-said');
    var searchEl = document.getElementById('crep-search');
    var statusEl = document.getElementById('crep-status');
    var authorEl = document.getElementById('crep-author');
    var reporterEl = document.getElementById('crep-reporter');
    var fromEl = document.getElementById('crep-from');
    var toEl = document.getElementById('crep-to');
    var clearEl = document.getElementById('crep-clear');
    var TORRENT = ['reports', 'archives', 'appeals', 'appeal_archives'];
    var kind = null, page = 1, timer = 0, mayHandle = false, seq = 0;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        // as it comes: a t.key() word keeps its key (String() would leave its words only — 1.73.0)
        if (text !== undefined && text !== null) n.textContent = t.isKey(text) ? text : String(text);
        return n;
    }

    /** The panel's requests: its token is on the body (admin-common.js' CSRF()), in the header and the body. */
    async function api(endpoint, method, body) {
        try {
            var opts = { method: method || 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (body) {
                var tok = document.body.dataset.csrf || '';
                opts.headers['Content-Type'] = 'application/json';
                opts.headers['X-CSRF-Token'] = tok;
                body.csrf_token = tok;
                opts.body = JSON.stringify(body);
            }
            var r = await fetch('api.php?endpoint=' + endpoint, opts);
            return await r.json();
        } catch (e) { return null; }
    }

    /**
     * A sentence with a person in it: their picture right before their name, wherever the language put it
     * (assets/js/avatar.js), and the plain sentence where pictures are off. `plain` are the other words.
     */
    function phrase(tag, cls, key, person, plain) {
        var span = el(tag, cls);
        var vars = {};
        Object.keys(plain || {}).forEach(function (k) { vars[k] = String(plain[k]).split('\u0001').join(''); });
        if (!person || typeof window.userAvatarPhrase !== 'function' || !person.avatar) {
            vars.user = person ? person.name : '';
            span.textContent = t.key(key, vars);
            return span;
        }
        vars.user = window.userAvatarSlot(0);
        span.appendChild(window.userAvatarPhrase(t.key(key, vars),
            [[window.userAvatarImg({ username: person.name, avatar: String(person.avatar || '') }, 20, 'avatar msgrep-av'), person.name]]));
        return span;
    }

    /** A block of the SERVER's rendering of a moderator's BBCode / Markdown (reportTextHtml(): the site's renderer on escaped input). */
    function richBlock(html) {
        var d = el('div', 'arf-answer rt-body richtext');
        d.innerHTML = html;
        return d;
    }

    /** What the author's account already is: the facts line and the latest warnings. */
    function authorState(c, st) {
        if (!st) return;
        var facts = [];
        if (st.reports > 1) facts.push(t.key('js.msgrep.seen_before', { n: st.reports }));
        if (st.warnings > 0) facts.push(t.key('js.creport.warned_n', { n: st.warnings }));
        if (st.muted_until) facts.push(t.key('js.creport.is_muted', { date: String(st.muted_until).slice(0, 16) }));
        if (st.banned) facts.push(st.banned_until ? t.key('js.msgrep.is_banned_until', { date: String(st.banned_until).slice(0, 16) }) : t.key('js.msgrep.is_banned'));
        if (st.staff) facts.push(t.key('js.msgrep.is_staff'));
        if (st.you) facts.push(t.key('js.creport.is_you'));
        // pieces, each fact a t.key() word that keeps its key (1.73.0: joined into one string they kept none)
        if (facts.length) { var fl = el('div', 'msgrep-state'); facts.forEach(function (f, i) { if (i) fl.append(' · '); fl.append(f); }); c.appendChild(fl); }
        if (st.latest_warnings && st.latest_warnings.length) {
            c.appendChild(el('div', 'msgrep-warn-head text-muted', t.key('js.creport.latest_warnings')));
            var ul = el('ul', 'msgrep-warnings');
            st.latest_warnings.forEach(function (w) {
                // 1.75.0: a REVOKED warning stays in the list, struck through; a silent action and an automatic penalty are marked
                var marks = [];
                if (w.auto) marks.push(t.key('js.moderation.mark_auto'));
                else if (w.loud === false) marks.push(t.key('js.moderation.mark_silent'));
                if (w.revoked) marks.push(t.key('js.moderation.mark_revoked'));
                var tail = marks.length ? [' (', marks.flatMap(function (m, i) { return i ? [', ', m] : [m]; }), ')'].flat() : [];
                var li;
                if (w.auto && w.auto_info) {
                    li = el('li', null, t.key('js.creport.warning_line', { at: String(w.at || '').slice(0, 16), reason: t.key('js.moderation.auto_reason', { n: w.auto_info.count, days: w.auto_info.window }) }));
                } else if (w.reason_html) {
                    // A BBCode / Markdown reason (1.75.0): the server's rendering of it (the site's renderer on escaped input).
                    li = el('li', null, t.key('js.creport.warning_head', { at: String(w.at || '').slice(0, 16) }));
                    tail.forEach(function (p) { li.append(p); });
                    tail = [];
                    li.appendChild(richBlock(w.reason_html));
                } else {
                    li = el('li', null, t.key('js.creport.warning_line', { at: String(w.at || '').slice(0, 16), reason: w.reason || '' }));
                }
                tail.forEach(function (p) { li.append(p); });
                if (w.revoked) li.className = 'mod-revoked';
                ul.appendChild(li);
            });
            c.appendChild(ul);
        }
    }

    function stateWord(g) {
        if (g.kind === 'image' && !g.exists && g.state === 'deleted') return t.key(g.blocked ? 'js.creport.state_image_blocked' : 'js.creport.state_image_removed');
        if (g.kind === 'torrent' && !g.exists && g.state === 'banned') return t.key('js.creport.state_torrent_banned');
        if (!g.exists) return t.key(g.state === 'none' ? 'js.creport.state_none' : g.state === 'deleted' ? 'js.creport.state_deleted' : 'js.creport.state_gone');
        if (g.kind === 'comment' && g.state === 'pending') return t.key('js.creport.state_pending');
        if (g.kind === 'description' && g.state !== 'approved') return t.key('js.creport.state_unpublished');
        return '';
    }

    function places(c, list) {
        var line = el('div', 'crep-places text-muted');
        if (!list.length) { line.appendChild(el('span', null, t.key('js.creport.not_used'))); c.appendChild(line); return; }
        line.appendChild(el('span', null, t.key('js.creport.used_in')));
        list.forEach(function (p) {
            line.append(' ');
            var label = p.kind === 'page' ? t.key('js.creport.place_page', { name: p.page || '' }) : t.key('js.creport.place_' + p.kind);
            if (p.hash) {
                var a = el('a', 'crep-place', label);
                a.href = '?action=search&hash=' + encodeURIComponent(p.hash);
                a.target = '_blank';
                a.rel = 'noopener';
                a.title = p.hash;
                line.appendChild(a);
            } else {
                line.appendChild(el('span', 'crep-place', label));
            }
        });
        c.appendChild(line);
    }

    function card(g) {
        var c = el('div', 'msgrep-card crep-card' + (g.open ? '' : ' msgrep-closed'));
        c.dataset.kind = g.kind;
        c.dataset.target = String(g.target_id);
        c.dataset.hash = g.hash || '';
        var head = el('div', 'msgrep-head');
        head.appendChild(el('span', 'badge-table crep-kind', t.key('js.creport.kind_' + g.kind)));
        var person = g.author ? { name: g.author, avatar: g.author_avatar } : null;
        var nobody = g.guest_tag ? t.key('js.creport.guest', { tag: g.guest_tag }) : t.key(g.author_state === null && !g.author ? 'js.creport.no_author' : 'js.creport.nobody');
        head.appendChild(phrase('span', 'msgrep-who', g.kind === 'image' && g.copy ? 'js.creport.head_image_copy' : (g.kind === 'torrent' && !g.author ? 'js.creport.head_torrent_index' : 'js.creport.head_' + g.kind), person || { name: nobody, avatar: '' },
                                { name: g.name || (g.hash ? g.hash.slice(0, 12) + '…' : '') }));
        if (g.where) {
            var a = el('a', 'crep-where', t.key('js.creport.open_site'));
            a.href = g.where;
            a.target = '_blank';
            a.rel = 'noopener';
            head.appendChild(a);
        }
        var sw = stateWord(g);
        if (sw) head.appendChild(el('span', 'crep-gone-mark text-muted', sw));
        head.appendChild(el('span', 'badge-table ' + (g.open ? 'badge-pending' : 'badge-reviewed'),
                            g.open ? t.key('js.creport.open_n', { n: g.open }) : t.key('js.msgrep.closed')));
        c.appendChild(head);

        authorState(c, g.author_state);

        // The words, as they are now — server-rendered by the kind's own renderer, the markup a reader is handed.
        var box = el('div', 'msgrep-msg msgrep-reported');
        if (g.exists) {
            var body = el('div', 'msgrep-body richtext crep-body');
            body.innerHTML = g.html || '';
            box.appendChild(body);
        } else {
            box.appendChild(el('div', 'msgrep-gone text-muted', sw || t.key('js.creport.state_gone')));
        }
        c.appendChild(box);
        if (g.kind === 'image' && g.exists) places(c, g.places || []);
        // …and as they were reported, when that is not what is there now.
        if (g.reported_html) {
            var was = el('div', 'msgrep-msg msgrep-ctx crep-was');
            was.appendChild(el('div', 'msgrep-label text-muted', t.key(g.exists ? 'js.creport.changed' : 'js.creport.as_reported')));
            var wb = el('div', 'msgrep-body richtext crep-body');
            wb.innerHTML = g.reported_html;
            was.appendChild(wb);
            c.appendChild(was);
        }

        // Every report about it, newest first.
        var rs = el('div', 'crep-reports');
        rs.appendChild(el('div', 'msgrep-label text-muted', t.key('js.creport.reports_n', { n: (g.reports || []).length })));
        var ul = el('ul', 'crep-report-list');
        (g.reports || []).forEach(function (r) {
            var li = el('li', 'crep-report' + (r.status === 'open' ? ' crep-report-open' : ''));
            var line = el('div', 'crep-report-head');
            line.appendChild(phrase('span', 'crep-reporter', 'js.creport.report_by', { name: r.reporter || '?', avatar: r.reporter_avatar }, {}));
            line.appendChild(el('span', 'msgrep-when text-muted', r.created_at));
            if (r.status === 'open') {
                line.appendChild(el('span', 'badge-table badge-pending', t.key('js.creport.status_open')));
            } else {
                line.appendChild(el('span', 'crep-outcome text-muted', t.key('js.creport.handled', {
                    outcome: t.key('js.creport.outcome_' + (r.outcome || 'closed')), user: r.handled_by || '—', at: String(r.handled_at || '').slice(0, 16) })));
            }
            if (r.snapshot_differs) line.appendChild(el('span', 'crep-other-words text-muted', t.key('js.creport.report_other_words')));
            li.appendChild(line);
            li.appendChild(el('div', 'msgrep-reason', r.reason));
            if (r.reply_html) {
                var ans = el('div', 'msgrep-answer', t.key('js.msgrep.answered_lead'));
                ans.appendChild(richBlock(r.reply_html));
                li.appendChild(ans);
            } else if (r.reply) li.appendChild(el('div', 'msgrep-answer', t.key('js.msgrep.answered', { text: r.reply })));
            if (r.note) li.appendChild(el('div', 'msgrep-answer text-muted', t.key('js.msgrep.noted', { text: r.note })));
            ul.appendChild(li);
        });
        rs.appendChild(ul);
        c.appendChild(rs);

        if (mayHandle) actions(c, g);
        return c;
    }

    function actions(c, g) {
        var st = g.author_state;
        // An account the panel may act on: one exists, it cannot open the panel, and it is not the moderator's own.
        var account = !!st && !st.staff && !st.you;
        var acts = el('div', 'msgrep-acts');
        var note = document.createElement('input');
        note.type = 'text';
        note.className = 'form-control form-control-sm bg-dark text-light border-secondary msgrep-note-in';
        note.placeholder = t.key('js.msgrep.note_ph');
        note.setAttribute('aria-label', t.key('js.msgrep.note_ph'));
        note.maxLength = 500;
        acts.appendChild(note);
        // The answer to the reporters (1.75.0): lines and a format — adminRichField(), assets/js/admin-common.js — the
        // site's own box with its toolbar and the server's preview. The note above stays what it was: the panel's own, plain.
        var reply = window.AdminCommon.adminRichField({ compact: true, rows: 2, maxLength: 2000, countMax: 2000,
            placeholder: t.key('js.creport.reply_ph'), ariaLabel: t.key('js.creport.reply_ph') });
        var replyBox = el('div', 'crep-rich crep-reply-rich');
        replyBox.appendChild(reply.el);
        c.appendChild(replyBox);     // above the note and the buttons
        c.appendChild(acts);

        // Silently, or as a warning — for everything that reaches the author.
        var modeRow = el('div', 'msgrep-acts msgrep-mode');
        var loudRadio = null, reasonIn = null;
        if (account) {
            modeRow.appendChild(el('span', 'msgrep-mode-label', t.key('js.creport.mode_label')));
            var radio = function (value, label, on) {
                var lab = el('label', 'msgrep-mode-opt');
                var r = document.createElement('input');
                r.type = 'radio';
                r.name = 'crep-mode-' + g.kind + '-' + g.target_id + '-' + (g.hash || '');
                r.value = value;
                r.checked = !!on;
                lab.appendChild(r);
                lab.append(' ', label);   // the word as a piece: a t.key() word keeps its key (1.73.0)
                modeRow.appendChild(lab);
                return r;
            };
            radio('silent', t.key('js.creport.mode_silent'), true);
            loudRadio = radio('loud', t.key('js.creport.mode_loud'), false);
            // …and the reason the author is told (1.75.0), the same box: it comes to them as a warning in their notifications.
            reasonIn = window.AdminCommon.adminRichField({ compact: true, rows: 2, maxLength: 2000, countMax: 2000,
                placeholder: t.key('js.creport.reason_ph'), ariaLabel: t.key('js.creport.reason_ph') });
            var reasonBox = el('div', 'crep-rich crep-reason-rich');
            reasonBox.appendChild(reasonIn.el);
            modeRow.appendChild(reasonBox);
            modeRow.appendChild(el('div', 'crep-mode-hint text-muted', t.key('js.creport.mode_hint')));
            c.appendChild(modeRow);
        }

        var say = el('span', 'msgrep-said text-muted');
        say.setAttribute('aria-live', 'polite');
        var run = async function (action, days, btn) {
            var loud = action === 'warn' || (!!loudRadio && loudRadio.checked);
            var reaches = ['remove', 'warn', 'mute', 'ban'].indexOf(action) >= 0;
            if (loud && reaches && account && reasonIn.isEmpty()) {
                say.textContent = t.key('js.creport.err_reason');
                reasonIn.focus();
                return;
            }
            btn.disabled = true;
            var r = await api('admin/content_report_action', 'POST', {
                kind: g.kind, target_id: g.target_id, hash: g.hash || '', action: action, days: days || 0,
                mode: loud && account ? 'loud' : 'silent',
                reason: reasonIn ? reasonIn.getValue().trim() : '', reason_format: reasonIn ? reasonIn.getFormat() : 'plain',
                note: note.value.trim(),
                reply: reply.getValue().trim(), reply_format: reply.getFormat(),
                seen: action === 'remove' ? (g.sha || '') : '',
                block: action === 'remove' && blockBox && blockBox.checked ? 1 : 0,
            });
            btn.disabled = false;
            if (r && r.success) {
                var msg = t.key('js.creport.done_' + action, { n: r.told || 0 });
                if (r.warning) msg += t.key('js.creport.done_loud');
                if (saidEl) saidEl.textContent = msg;
                load(page);
                return;
            }
            var e = r && r.error;
            // A text the server refused (too long, a picture, too many links, a format the site does not take): its own sentence.
            if (e === 'invalid_text' && r.message) { say.textContent = r.message; return; }
            say.textContent = t.key(e === 'target_is_staff' ? 'js.msgrep.err_staff'
                              : e === 'target_is_you' ? 'js.msgrep.err_you'
                              : e === 'reason_required' ? 'js.creport.err_reason'
                              : e === 'no_author' ? 'js.creport.err_no_author'
                              : e === 'changed' ? 'js.creport.err_changed'
                              : e === 'gone' ? 'js.creport.err_gone'
                              : e === 'ban_is_permanent' ? 'js.creport.err_permanent'
                              : 'js.msgrep.err_failed');
        };
        var button = function (label, cls, onClick) {
            var b = el('button', 'btn btn-sm ' + (cls || 'btn-outline-secondary'), label);
            b.type = 'button';
            b.addEventListener('click', function () { onClick(b); });
            return b;
        };
        /** Anything that cannot be undone asks first, in place — a question that stays until it is answered. */
        var confirmThen = function (question, label, cls, go) {
            return button(label, cls, function (b) {
                var row = el('span', 'msgrep-confirm');
                row.appendChild(el('span', 'msgrep-confirm-q', question));
                row.appendChild(button(t.key('js.msgrep.yes'), 'btn-danger', function (yb) { go(yb); }));
                row.appendChild(button(t.key('js.msgrep.no'), 'btn-outline-secondary', function () { row.replaceWith(t.relabel(b)); }));   // back from out of the page: said again if the language changed (1.73.0)
                b.replaceWith(row);
            });
        };

        var blockBox = null;
        var btns = el('div', 'msgrep-acts crep-btns');
        btns.appendChild(button(g.open ? t.key('js.msgrep.close') : t.key('js.msgrep.reopen'), 'btn-outline-secondary crep-close',
                                function (b) { run(g.open ? 'close' : 'reopen', 0, b); }));
        if (g.exists && g.kind === 'image') {
            btns.appendChild(confirmThen(t.key('js.creport.remove_image_q'), t.key('js.creport.remove_image'), 'btn-outline-danger crep-remove',
                                         function (b) { run('remove', 0, b); }));
            var bl = el('label', 'crep-block-opt');
            blockBox = document.createElement('input');
            blockBox.type = 'checkbox';
            bl.appendChild(blockBox);
            bl.append(' ', t.key('js.creport.block_image'));
            btns.appendChild(bl);
        } else if (g.exists && g.kind === 'torrent') {
            btns.appendChild(confirmThen(t.key('js.creport.remove_torrent_q'), t.key('js.creport.remove_torrent'), 'btn-outline-danger crep-remove',
                                         function (b) { run('remove', 0, b); }));
        } else if (g.exists) btns.appendChild(confirmThen(t.key('js.creport.remove_q'), t.key('js.creport.remove'), 'btn-outline-danger crep-remove',
                                                   function (b) { run('remove', 0, b); }));
        if (account) btns.appendChild(confirmThen(t.key('js.creport.warn_q', { user: g.author }), t.key('js.creport.warn'), 'btn-outline-warning crep-warn',
                                                  function (b) { run('warn', 0, b); }));
        c.appendChild(btns);

        // What to do about the ACCOUNT — the message card's lengths, the same confirmation.
        var pun = el('div', 'msgrep-acts msgrep-punish');
        if (account) {
            var pick = document.createElement('select');
            pick.className = 'form-select form-select-sm bg-dark text-light border-secondary msgrep-pick';
            pick.setAttribute('aria-label', t.key('js.msgrep.apply'));
            [['mute:1', 'mute_1'], ['mute:7', 'mute_7'], ['mute:30', 'mute_30'], ['mute:0', 'mute_forever'],
             ['ban:7', 'ban_7'], ['ban:30', 'ban_30'], ['ban:0', 'ban_forever']].forEach(function (o) {
                var op = document.createElement('option');
                op.value = o[0];
                op.textContent = t.key('js.creport.' + o[1]);
                pick.appendChild(op);
            });
            pun.appendChild(pick);
            pun.appendChild(button(t.key('js.msgrep.apply'), 'btn-outline-warning crep-apply', function (b) {
                var parts = pick.value.split(':');
                var row = el('span', 'msgrep-confirm');
                row.appendChild(el('span', 'msgrep-confirm-q', t.key('js.creport.sure_' + parts[0], { user: g.author })));
                row.appendChild(button(t.key('js.msgrep.yes'), 'btn-danger', function (yb) { run(parts[0], Number(parts[1]), yb); }));
                row.appendChild(button(t.key('js.msgrep.no'), 'btn-outline-secondary', function () { row.replaceWith(t.relabel(b)); }));   // back from out of the page: said again if the language changed (1.73.0)
                b.replaceWith(row);
            }));
            if (st.muted_until) pun.appendChild(button(t.key('js.msgrep.unmute'), 'btn-outline-success crep-unmute', function (b) { run('unmute', 0, b); }));
            // Only a ban this card can lift: one with a date. A dateless ban is the owner's, from the Users page.
            if (st.banned && st.banned_until) pun.appendChild(button(t.key('js.msgrep.unban'), 'btn-outline-success crep-unban', function (b) { run('unban', 0, b); }));
        } else if (st && (st.staff || st.you)) {
            pun.appendChild(el('span', 'text-muted', t.key('js.creport.account_note')));
        } else {
            pun.appendChild(el('span', 'text-muted', t.key(g.kind === 'torrent' ? 'js.creport.no_owner_torrent' : 'js.creport.err_no_author')));
        }
        pun.appendChild(say);
        c.appendChild(pun);
    }

    function filtersQs() {
        var qs = '&status=' + encodeURIComponent(statusEl ? statusEl.value : 'open');
        [['q', searchEl], ['author', authorEl], ['reporter', reporterEl], ['from', fromEl], ['to', toEl]].forEach(function (p) {
            var v = p[1] && p[1].value ? p[1].value.trim() : '';
            if (v) qs += '&' + p[0] + '=' + encodeURIComponent(v);
        });
        return qs;
    }

    async function load(p) {
        if (!kind) return;
        page = p || 1;
        var my = ++seq;
        var j = await api('admin/content_reports&kind=' + kind + '&page=' + page + filtersQs());
        if (my !== seq) return;       // a newer request (another tab, a filter typed on) is on its way
        listEl.textContent = '';
        pagerEl.textContent = '';
        if (!j || !j.groups) { listEl.appendChild(el('div', 'text-muted py-3', t.key('js.msgrep.empty'))); return; }
        mayHandle = !!j.may_handle;
        badge(kind, j.open);
        if (!j.groups.length) { listEl.appendChild(el('div', 'text-muted py-3', t.key('js.msgrep.empty'))); return; }
        j.groups.forEach(function (g) { listEl.appendChild(card(g)); });
        if (j.pages > 1) {
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

    function badge(k, n) {
        // the header's one Reports number takes this tab's count (admin-common.js reportsWaiting(), 1.74.3)
        if (window.AdminCommon && window.AdminCommon.reportsWaiting) window.AdminCommon.reportsWaiting(k, n);
        var b = document.getElementById('crep-badge-' + k);
        if (!b) return;
        b.textContent = n ? String(n) : '';
        b.classList.toggle('d-hidden', !n);
    }

    /** The three badges, before anybody opens a tab: an unattended queue is what an operator most needs to know. */
    async function badges() {
        var j = await api('admin/content_reports&counts=1');
        if (!j || !j.kinds) return;
        Object.keys(j.kinds).forEach(function (k) { badge(k, j.kinds[k].open); });
    }

    var soon = function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 400); };
    [searchEl, authorEl, reporterEl].forEach(function (n) { if (n) n.addEventListener('input', soon); });
    [statusEl, fromEl, toEl].forEach(function (n) { if (n) n.addEventListener('change', function () { load(1); }); });
    if (clearEl) clearEl.addEventListener('click', function () {
        [searchEl, authorEl, reporterEl, fromEl, toEl].forEach(function (n) { if (n) n.value = ''; });
        if (statusEl) statusEl.value = 'open';
        load(1);
    });

    function show(tab) {
        var k = tab ? tab.dataset.crepKind : '';
        var mine = !!k;
        view.classList.toggle('d-hidden', !mine);
        var torrent = !!tab && TORRENT.indexOf(tab.dataset.source) >= 0;
        document.querySelectorAll('[data-torrent-part]').forEach(function (n) { n.classList.toggle('d-hidden', !torrent); });
        if (!mine) { kind = null; return; }
        if (kind !== k) { listEl.textContent = ''; if (saidEl) saidEl.textContent = ''; }
        kind = k;
        load(1);
    }
    // The tab bar is assets/js/admin.js's; this listens to the same click, as admin-messages.js does.
    document.addEventListener('click', function (e) {
        var tab = e.target.closest ? e.target.closest('.source-tab') : null;
        if (tab) show(tab);
    });

    badges();
    // The page opens on one of these tabs when it is the first the session has.
    var active = document.querySelector('.source-tab.active[data-crep-kind]');
    if (active) show(active);
})();
