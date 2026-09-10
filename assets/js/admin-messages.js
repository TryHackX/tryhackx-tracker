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
                var tok = (document.querySelector('meta[name="csrf-token"]') || {}).content
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
            var acts = el('div', 'msgrep-acts');
            var note = document.createElement('input');
            note.type = 'text';
            note.className = 'form-control form-control-sm bg-dark text-light border-secondary msgrep-note-in';
            note.placeholder = t('js.msgrep.note_ph');
            note.maxLength = 500;
            acts.appendChild(note);

            var act = function (label, action, cls) {
                var b = el('button', 'btn btn-sm ' + (cls || 'btn-outline-secondary'), label);
                b.addEventListener('click', async function () {
                    if (action === 'delete_message' && b.dataset.armed !== '1') {
                        b.dataset.armed = '1';
                        b.textContent = t('js.msgrep.delete_sure');
                        setTimeout(function () {
                            if (b.dataset.armed === '1') { b.dataset.armed = '0'; b.textContent = t('js.msgrep.delete'); }
                        }, 4000);
                        return;
                    }
                    b.disabled = true;
                    var r = await api('admin/message_report_action', 'POST',
                                      { id: rep.id, action: action, note: note.value.trim() });
                    b.disabled = false;
                    if (r && r.success) load(page);
                });
                return b;
            };
            acts.appendChild(act(rep.status === 'closed' ? t('js.msgrep.reopen') : t('js.msgrep.close'),
                                 rep.status === 'closed' ? 'reopen' : 'close'));
            if (rep.message) acts.appendChild(act(t('js.msgrep.delete'), 'delete_message', 'btn-outline-danger'));
            c.appendChild(acts);
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
