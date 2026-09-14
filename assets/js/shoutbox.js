/**
 * The shoutbox, keeping up with itself.
 *
 * One script for both places the box appears — the block on the front page and ?action=shoutbox —
 * because they are the same markup with a different row count, and two scripts for one set of ids
 * would be two answers to every question about them.
 *
 * ── it never redraws ───────────────────────────────────────────────────────────────────────────
 * The starting point comes WITH the first render (`data-newest` on #shoutbox), so the first tick
 * asks for rows newer than what is already on the screen rather than for the list again. New rows
 * are appended; nothing that is already there is touched. That is not a refinement — it is the
 * whole reason somebody can be halfway through a sentence when a shout lands and still have their
 * sentence. Dedup is by the ids in the DOM, which is the only copy that matters after a retry, a
 * slow connection or a send that raced its own poll.
 *
 * ── it asks nothing when nobody is looking ─────────────────────────────────────────────────────
 * A background tab (`document.hidden`), a box inside a hidden ancestor (`offsetParent === null`,
 * which is how the account page's tabs hide a pane) and a flight already in the air all mean the
 * same thing: skip this tick. The counter on the badge is somebody else's job and comes from the
 * pulse; this only fetches text for a box that is on the screen.
 *
 * Row bodies arrive as HTML the SERVER rendered, through the same richtextRender() every
 * description and message goes through. There is exactly one place in this codebase that decides
 * what may be displayed, and it is not here.
 */
(function () {
    'use strict';

    var box = document.getElementById('shoutbox');
    if (!box) return;

    var API = (typeof APP_API === 'string') ? APP_API : 'api.php?endpoint=';
    var BASE = (typeof APP_BASE === 'string') ? APP_BASE : '';

    var listEl = document.getElementById('shout-list');
    var olderBtn = document.getElementById('shout-older');
    var ta = document.getElementById('shout-body');
    var sendBtn = document.getElementById('shout-send');
    var countEl = document.getElementById('shout-count');
    var noteEl = document.getElementById('shout-note');
    var fmtEl = document.getElementById('shout-body-format');
    if (!listEl) return;

    var newest = Number(box.dataset.newest || 0);
    var oldest = Number(box.dataset.oldest || 0);
    var live = Number(box.dataset.live || 0);
    var maxChars = Number(box.dataset.max || 500) || 500;
    var format = box.dataset.format || 'bbcode';
    var meId = Number(box.dataset.me || 0);
    var seen = 0;                 // the highest id this reader has been told about
    var polling = false;
    var timer = 0;

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
        var i = document.getElementById('shout-csrf')
             || document.getElementById('account-csrf')
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

    /** Is this box on the screen of a tab somebody is looking at? */
    function visible() { return !document.hidden && box.offsetParent !== null; }

    function note(text) { if (noteEl) noteEl.textContent = text || ''; }

    /**
     * What went wrong, in this language.
     *
     * A code the endpoint documents becomes a sentence from the dictionary. Anything else is either
     * a sentence the server already translated (the CSRF answer is one) or nothing we can name —
     * and "something went wrong" is a better answer than an error code nobody can act on.
     */
    var CODES = { flood: 1, too_long: 1, muted: 1, empty: 1, invalid_body: 1, disabled: 1,
                  no_permission: 1, login_required: 1, not_found: 1, rate_limit: 1 };
    function errText(r) {
        var code = (r && r.error) || 'failed';
        if (typeof code !== 'string') code = 'failed';
        if (code === 'flood') return t('js.shout.err_flood', { seconds: Number(r.retry_after || 0) });
        if (code === 'too_long') return t('js.shout.err_too_long', { limit: Number(r.limit || maxChars) });
        if (code === 'muted') return t('js.shout.err_muted', { until: String(r.until || '') });
        if (CODES[code]) return t('js.shout.err_' + code);
        // Already a sentence rather than a code — show it rather than swallowing it.
        if (/\s/.test(code)) return code;
        return t('js.shout.err_failed');
    }

    /** One shout, exactly as templates/partials/shoutbox_widget.php draws it. */
    function renderRow(r) {
        var name = String(r.user || '');
        var at = String(r.at || '');
        var row = el('div', { className: 'shout-row' + (r.own ? ' shout-row-own' : '') + (r.mentions_me ? ' shout-row-mention' : '') });
        row.dataset.id = String(Number(r.id) || 0);
        row.dataset.user = name;
        row.appendChild(el('a', { className: 'shout-who', href: BASE + '?action=u&name=' + encodeURIComponent(name), text: name }));
        row.appendChild(el('span', { className: 'shout-time', title: at, text: at.slice(11, 16) }));
        row.appendChild(el('span', { className: 'shout-body rt-body', html: r.html || '' }));
        if (r.deletable) {
            row.appendChild(el('button', { type: 'button', className: 'shout-del',
                                           title: t('js.shout.delete'), 'aria-label': t('js.shout.delete'), text: '×' }));
        }
        return row;
    }

    /**
     * Append the rows that are not already there, and follow the list down only if the reader was
     * already at the bottom of it. Scrolling somebody away from the line they are reading is worse
     * than a row they have to scroll to.
     */
    function appendRows(rows) {
        var atBottom = listEl.scrollHeight - listEl.scrollTop - listEl.clientHeight < 40;
        var added = [];
        (rows || []).forEach(function (r) {
            var id = Number(r && r.id) || 0;
            if (!id || listEl.querySelector('.shout-row[data-id="' + id + '"]')) return;
            listEl.appendChild(renderRow(r));
            if (id > newest) newest = id;
            if (!oldest || id < oldest) oldest = id;
            added.push(r);
        });
        if (added.length) {
            var empty = listEl.querySelector('.shout-empty');
            if (empty) empty.remove();
            if (atBottom) listEl.scrollTop = listEl.scrollHeight;
        }
        return added;
    }

    function bottom() { listEl.scrollTop = listEl.scrollHeight; }

    /**
     * "I have seen up to here."
     *
     * One column on the account (`users.shout_seen_id`), so this is the whole of what makes the
     * badge stop counting. Only while the box is actually on the screen, and never twice for the
     * same id — a marker that goes backwards or repeats is a write for nothing.
     */
    function markSeen(id) {
        if (!meId || !id || id <= seen || !visible()) return;
        seen = id;
        post('shout_seen', { id: id });
    }

    function stop() { if (timer) { clearInterval(timer); timer = 0; } }

    async function poll() {
        if (!live || polling || !visible()) return;
        polling = true;
        var j = null;
        try { j = await get('shout_list&after=' + newest); } finally { polling = false; }
        if (!j) return;
        if (!j.success) {
            // Switched off, signed out, or the permission taken away mid-session: stop asking. The
            // box stays on the screen with what it had, which is honest — those rows were real.
            if (j.error === 'disabled' || j.error === 'no_permission' || j.error === 'login_required') stop();
            return;
        }
        var added = appendRows(j.rows);
        if (Number(j.newest) > newest) newest = Number(j.newest);
        if (!added.length) return;
        markSeen(newest);
        // The hook the sound player listens on (and F3's nav counter will). The counts themselves
        // come from the pulse — this only says that rows landed while somebody was looking.
        window.dispatchEvent(new CustomEvent('shout:new', { detail: { rows: added } }));
    }

    /** Older rows, pasted in ABOVE without moving what is being read. */
    async function older() {
        if (!olderBtn || olderBtn.disabled || !oldest) return;
        olderBtn.disabled = true;
        var j = await get('shout_list&before=' + oldest);
        olderBtn.disabled = false;
        if (!j || !j.success) { note(errText(j)); return; }
        var rows = j.rows || [];
        if (!rows.length) { olderBtn.hidden = true; note(t('js.shout.no_more')); return; }
        // Measured before the insert and restored after it: the browser keeps scrollTop where it
        // was, which means the content under it has moved down by exactly the height added.
        var wasHeight = listEl.scrollHeight, wasTop = listEl.scrollTop;
        var frag = document.createDocumentFragment();
        rows.forEach(function (r) {
            var id = Number(r && r.id) || 0;
            if (!id || listEl.querySelector('.shout-row[data-id="' + id + '"]')) return;
            frag.appendChild(renderRow(r));           // rows arrive newest LAST, so order is kept
            if (!oldest || id < oldest) oldest = id;
        });
        var empty = listEl.querySelector('.shout-empty');
        if (empty) empty.remove();
        listEl.insertBefore(frag, listEl.firstChild);
        listEl.scrollTop = wasTop + (listEl.scrollHeight - wasHeight);
        olderBtn.hidden = !j.has_more;
        note('');
    }

    /**
     * Deleting a line: the question waits in the place the button was.
     *
     * The same shape as the report cards in the panel — a question with a yes and a no, in the row
     * it is about. window.confirm() would ask from outside the page, about a row it cannot show.
     */
    function askDelete(btn) {
        var row = btn.closest('.shout-row');
        if (!row) return;
        var id = Number(row.dataset.id) || 0;
        var ask = el('span', { className: 'shout-confirm' });
        ask.appendChild(el('span', { className: 'shout-confirm-q', text: t('js.shout.delete_q') }));
        var yes = el('button', { type: 'button', className: 'shout-yes', text: t('js.shout.yes') });
        var no = el('button', { type: 'button', className: 'shout-no', text: t('js.shout.no') });
        yes.addEventListener('click', async function () {
            yes.disabled = true;
            var r = await post('shout_delete', { id: id });
            if (r && r.success) { row.remove(); note(''); return; }
            // A row somebody else already deleted is gone either way: take it off the screen.
            if (r && r.error === 'not_found') { row.remove(); return; }
            note(errText(r));
            ask.replaceWith(btn);
        });
        no.addEventListener('click', function () { ask.replaceWith(btn); });
        ask.appendChild(yes);
        ask.appendChild(no);
        btn.replaceWith(ask);
    }

    function countUpdate() {
        if (!countEl || !ta) return;
        countEl.textContent = t('js.shout.chars', { n: ta.value.length, max: maxChars });
    }

    async function send() {
        if (!ta || !sendBtn) return;
        var body = ta.value.trim();
        if (!body) { ta.focus(); return; }
        sendBtn.disabled = true;
        var r = await post('shout_post', {
            body: body,
            // Whichever syntax they picked; the server validates it either way, and where the
            // tracker's format is 'plain' there is no select and nothing to pick.
            format: fmtEl && fmtEl.value ? fmtEl.value : format,
        });
        sendBtn.disabled = false;
        if (!r || !r.success) { note(errText(r)); return; }
        ta.value = '';
        countUpdate();
        note('');
        if (r.row) {
            // Straight from the answer, without waiting for a tick: the line somebody just wrote
            // appearing a few seconds later reads as a send that did not work.
            appendRows([r.row]);
            bottom();
            markSeen(newest);
        }
    }

    /* ─────────────────────────── wiring ─────────────────────────── */

    // Delegated, so a row drawn by the server and a row drawn above by this script behave the same.
    listEl.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.shout-del') : null;
        if (b && listEl.contains(b)) askDelete(b);
    });
    if (olderBtn) olderBtn.addEventListener('click', older);
    if (sendBtn) sendBtn.addEventListener('click', send);
    if (ta) {
        ta.addEventListener('input', countUpdate);
        ta.addEventListener('keydown', function (e) {
            // Enter sends, Shift+Enter starts a line. A shoutbox is a conversation, and reaching for
            // a button after every sentence is what makes one feel like a form.
            if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey || e.isComposing) return;
            e.preventDefault();
            send();
        });
        countUpdate();
        // The write/preview tabs, the syntax help and the counter under them, from the editor the
        // rest of the site writes in. Never in 'plain': there is no syntax to preview.
        if (format !== 'plain' && window.RichText && typeof window.RichText.mount === 'function') {
            window.RichText.mount('shout-body', { previewFor: 'shout' });
        }
    }

    // Everything already on the screen has been seen by whoever is looking at it.
    markSeen(newest);
    if (live > 0) timer = setInterval(poll, live * 1000);
    // One tick on demand, for the browser checks and for anything that wants to catch up now.
    window.ShoutTick = poll;
    window.Shout = { tick: poll, older: older, newest: function () { return newest; } };
})();
