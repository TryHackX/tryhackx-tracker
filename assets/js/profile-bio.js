/**
 * The description on your own profile, edited where it is read (1.69.0, includes/profilebio.php).
 *
 * The profile page draws the text (or, when there is none, a dashed "write something" box); on your
 * own profile a click on either — or Enter/Space on it, or arriving with #bio from the account page —
 * turns it into a toolbar, a textarea, a counter and Save/Cancel, in the same place. Save posts the
 * source to api/profile_bio.php and puts the HTML the SERVER rendered in place: nothing here turns
 * BBCode into markup, because the one guarantee this feature rests on — only five tags, every other
 * character text — belongs to the server, not to the least trustworthy place in the system.
 *
 * Not window.RichText.mount(): that editor is built around a preview round trip, a format switch and
 * a counter of raw source length, and throws without a preview box. This one needs five buttons, a
 * counter of what a READER will see, and Esc / Ctrl+Enter. The buttons follow its convention
 * (`data-tag` here, the same icons and titles as the other toolbars).
 *
 * The counter is a twin of profileBioClean() and of the counting half of profileBioParse() — same
 * character classes, same tag grammar, same rules for links — so the number under the box is the
 * number the save is judged by. The server's count is the one that decides; `window.ProfileBio.measure`
 * is exported so scratchpad/shots/profile_bio_check.js can hold the two to each other.
 *
 * Every node this builds carries an id, because assets/js/lang-swap.js pairs nodes by id and would
 * otherwise try to line a script-built node up with something the server rendered. On `langswap` the
 * labels are re-read from the new bundle.
 */
(function () {
    'use strict';

    /* ── the twin of includes/profilebio.php ─────────────────────────────────────────────────── */
    var MAX_LINKS = 3;
    var MAX_DEPTH = 8;
    // profileBioClean()'s class, character for character: C0 but \n, DEL and C1, U+180E, U+200B, the bidi
    // embeddings/overrides/isolates, U+2060–2064, U+FEFF, U+FFF9–FFFB.
    var cc = function (a, b) { return String.fromCharCode(a) + (b === undefined ? '' : '-' + String.fromCharCode(b)); };
    var STRIP = new RegExp('[' + cc(0x00, 0x09) + cc(0x0B, 0x1F) + cc(0x7F, 0x9F) + cc(0x180E) + cc(0x200B) + cc(0x202A, 0x202E)
                           + cc(0x2060, 0x2064) + cc(0x2066, 0x2069) + cc(0xFEFF) + cc(0xFFF9, 0xFFFB) + ']', 'g');
    // Built from code points, not written as escapes: a tool that turns an escape into its character
    // leaves a LINE SEPARATOR inside a regular expression literal, and that ends the literal.
    var NEWLINES = new RegExp('\r\n|\r|' + cc(0x2028) + '|' + cc(0x2029), 'g');
    var NOT_ADDRESS = new RegExp('[\\s' + cc(0x00, 0x1F) + cc(0x7F) + cc(0x85) + cc(0x180E) + ']');

    function clean(raw) {
        var s = String(raw == null ? '' : raw).replace(NEWLINES, '\n').replace(/\t/g, ' ');
        s = s.replace(STRIP, '').replace(/ {2,}/g, ' ').replace(/ *\n */g, '\n').replace(/\n{3,}/g, '\n\n');
        return s.replace(/^[ \n]+|[ \n]+$/g, '');
    }

    /** Code points, as mb_strlen() counts them — an emoji is one, a Polish letter is one. */
    function cpLen(s) { return Array.from(s).length; }

    function utf8Len(s) {
        try { return new TextEncoder().encode(s).length; } catch (e) { return s.length; }
    }

    /** PHP's trim() set: space, \t, \n, \r, \0, \x0B. */
    function phpTrim(s) { return s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, ''); }

    /** The address as profileBioUrl() reads it before judging it: trimmed, quotes round it forgiven. */
    function addrOf(raw) {
        var u = phpTrim(String(raw));
        if (u.length >= 2 && (u[0] === '"' || u[0] === "'") && u[u.length - 1] === u[0]) u = phpTrim(u.slice(1, -1));
        return u;
    }

    /**
     * profileBioUrl() + richtextSafeUrl(): no white space or control character, http(s) with a host,
     * at most 500 bytes. The host is looked for the way PHP's parse_url() finds it — an empty user
     * part, a port past 65535 or no host at all is no address.
     */
    function urlOk(raw) {
        var u = addrOf(raw);
        if (u === '' || NOT_ADDRESS.test(u)) return false;
        if (utf8Len(u) > 500) return false;
        var m = /^https?:\/\/([^\/?#]*)/i.exec(u);
        if (!m) return false;
        var auth = m[1];
        var at = auth.lastIndexOf('@');
        if (at === 0) return false;
        if (at > 0) auth = auth.slice(at + 1);
        var port = /:(\d*)$/.exec(auth);
        if (port && !(auth[0] === '[' && !/\]:\d*$/.test(auth))) {
            if (port[1] !== '' && Number(port[1]) > 65535) return false;
            auth = auth.slice(0, port.index);
        }
        return auth !== '';
    }

    /**
     * What a reader will see of a CLEANED text: {chars, links, bad, lines}. The walk of
     * profileBioParse() without the HTML — a stack, a closer that crosses another tag closes it and
     * reopens it (a link never), an empty link shows its address.
     */
    function measure(s) {
        var re = /\[(?:(b|i|u|s)|\/(b|i|u|s|url)|url(?:(=)([^\]\n]*))?)\]/gi;
        var closeRe = /\[\/url\]/gi;
        var chars = 0, links = 0, bad = 0, pos = 0, nextCloser = -1;
        var stack = [];
        var text = function (t) { chars += cpLen(t); };
        var close = function (f) { if (f.tag === 'url' && chars === f.mark) text(f.url); };
        var inLink = function () { for (var k = 0; k < stack.length; k++) if (stack[k].tag === 'url') return true; return false; };
        var m;
        re.lastIndex = 0;
        while ((m = re.exec(s)) !== null) {
            var tok = m[0], at = m.index;
            text(s.slice(pos, at));
            pos = at + tok.length;
            re.lastIndex = pos;
            if (m[1] !== undefined) {
                if (stack.length >= MAX_DEPTH) { text(tok); continue; }
                stack.push({ tag: m[1].toLowerCase(), mark: chars });
                continue;
            }
            if (m[2] !== undefined) {
                var name = m[2].toLowerCase(), idx = -1;
                for (var k = stack.length - 1; k >= 0; k--) { if (stack[k].tag === name) { idx = k; break; } }
                if (idx < 0) { text(tok); continue; }
                var reopen = [];
                while (stack.length - 1 > idx) {
                    var f = stack.pop();
                    close(f);
                    if (f.tag !== 'url') reopen.unshift(f.tag);
                }
                close(stack.pop());
                for (var r = 0; r < reopen.length; r++) stack.push({ tag: reopen[r], mark: chars });
                continue;
            }
            if (inLink() || stack.length >= MAX_DEPTH) { text(tok); continue; }
            if (m[3] !== undefined) {
                if (!urlOk(m[4] || '')) { bad++; text(tok); continue; }
                if (++links > MAX_LINKS) { text(tok); continue; }
                stack.push({ tag: 'url', mark: chars, url: addrOf(m[4] || '') });
                continue;
            }
            if (nextCloser < pos) {
                closeRe.lastIndex = pos;
                var cm = closeRe.exec(s);
                nextCloser = cm ? cm.index : Infinity;
            }
            if (nextCloser === Infinity) { text(tok); continue; }
            var inner = s.slice(pos, nextCloser);
            if (!urlOk(inner)) { bad++; text(tok); continue; }
            if (++links > MAX_LINKS) { text(tok); continue; }
            text(addrOf(inner));
            pos = nextCloser + 6;
            re.lastIndex = pos;
        }
        text(s.slice(pos));
        while (stack.length) close(stack.pop());
        return { chars: chars, links: links, bad: bad, lines: s === '' ? 0 : s.split('\n').length };
    }

    window.ProfileBio = { clean: clean, measure: function (raw) { return measure(clean(raw)); } };

    /* ── the editor ──────────────────────────────────────────────────────────────────────────── */
    var root = document.getElementById('profile-bio');
    if (!root || root.getAttribute('data-edit') !== '1') return;
    var textEl = document.getElementById('profile-bio-text');
    var phEl = document.getElementById('profile-bio-ph');
    var max = parseInt(root.getAttribute('data-max') || '300', 10) || 300;
    var cap = parseInt(root.getAttribute('data-cap') || '1200', 10) || 1200;
    var T = function (k, v) { return typeof window.t === 'function' ? window.t(k, v) : k; };

    // The five tags, with the icons and shortcuts the other toolbars on the site use.
    var TOOLS = [
        { tag: 'b', icon: 'bi-type-bold', label: 'js.bio.bold' },
        { tag: 'i', icon: 'bi-type-italic', label: 'js.bio.italic' },
        { tag: 'u', icon: 'bi-type-underline', label: 'js.bio.underline' },
        { tag: 's', icon: 'bi-type-strikethrough', label: 'js.bio.strike' },
        { tag: 'url', icon: 'bi-link-45deg', label: 'js.bio.link' },
    ];
    var editor = null, tools = null, ta = null, count = null, err = null, saveBtn = null, cancelBtn = null;
    var busy = false;

    function node(tag, cls, id) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (id) n.id = id;
        return n;
    }

    function build() {
        editor = node('div', 'profile-bio-editor', 'profile-bio-editor');
        editor.hidden = true;
        tools = node('div', 'profile-bio-tools', 'profile-bio-tools');
        tools.setAttribute('role', 'toolbar');
        TOOLS.forEach(function (d) {
            var b = node('button', 'profile-bio-tool', 'profile-bio-tool-' + d.tag);
            b.type = 'button';
            b.setAttribute('data-tag', d.tag);
            var i = node('i', 'bi ' + d.icon);
            i.setAttribute('aria-hidden', 'true');
            b.appendChild(i);
            tools.appendChild(b);
        });
        ta = node('textarea', 'profile-bio-input', 'profile-bio-input');
        ta.rows = 3;
        ta.maxLength = cap;
        ta.setAttribute('dir', 'auto');
        ta.setAttribute('aria-describedby', 'profile-bio-count');
        var foot = node('div', 'profile-bio-foot', 'profile-bio-foot');
        saveBtn = node('button', 'btn btn-small profile-bio-save', 'profile-bio-save');
        saveBtn.type = 'button';
        cancelBtn = node('button', 'profile-bio-cancel', 'profile-bio-cancel');
        cancelBtn.type = 'button';
        count = node('span', 'profile-bio-count', 'profile-bio-count');
        foot.appendChild(saveBtn);
        foot.appendChild(cancelBtn);
        foot.appendChild(count);
        err = node('div', 'profile-bio-err', 'profile-bio-err');
        err.setAttribute('role', 'alert');
        err.hidden = true;
        editor.appendChild(tools);
        editor.appendChild(ta);
        editor.appendChild(err);
        editor.appendChild(foot);
        root.appendChild(editor);

        tools.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-tag]') : null;
            if (b) wrap(b.getAttribute('data-tag'));
        });
        // The toolbar must not take the focus (and the selection) away from the text on a click.
        tools.addEventListener('mousedown', function (e) { if (e.target.closest && e.target.closest('[data-tag]')) e.preventDefault(); });
        ta.addEventListener('input', function () { recount(); grow(); });
        saveBtn.addEventListener('click', save);
        cancelBtn.addEventListener('click', cancel);
        editor.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return; }
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); save(); return; }
            if (e.target === ta && (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey) {
                var tag = { b: 'b', i: 'i', k: 'url' }[String(e.key).toLowerCase()];
                if (tag) { e.preventDefault(); wrap(tag); }
            }
        });
        label();
    }

    /** Every word this script puts on the page, from the dictionary — again after a language swap. */
    function label() {
        if (!editor) return;
        tools.setAttribute('aria-label', T('js.bio.toolbar'));
        TOOLS.forEach(function (d) {
            var b = document.getElementById('profile-bio-tool-' + d.tag);
            if (b) { b.title = T(d.label); b.setAttribute('aria-label', T(d.label)); }
        });
        ta.setAttribute('aria-label', T('js.bio.input'));
        ta.placeholder = T('js.bio.input_ph');
        saveBtn.textContent = busy ? T('js.bio.saving') : T('js.bio.save');
        saveBtn.title = T('js.bio.save_title');
        cancelBtn.textContent = T('js.bio.cancel');
        cancelBtn.title = T('js.bio.cancel_title');
        recount();
    }

    function recount() {
        if (!ta) return;
        var m = measure(clean(ta.value));
        count.textContent = T('js.bio.count', { n: m.chars, max: max });
        count.title = T('js.bio.count_title', { n: m.chars, max: max });
        count.classList.toggle('is-near', m.chars <= max && m.chars >= Math.floor(max * 0.9));
        count.classList.toggle('is-over', m.chars > max);
    }

    /** The box grows with what is in it, up to a point, and scrolls after that. */
    function grow() {
        if (!ta) return;
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight + 2, 320) + 'px';
    }

    /**
     * Wrap the selection in a tag, or put an empty pair at the caret. A link wraps an address as
     * `[url]…[/url]`; anything else gets `[url=https://]…[/url]` with the caret where the address goes.
     */
    function wrap(tag) {
        var start = ta.selectionStart, end = ta.selectionEnd, sel = ta.value.slice(start, end);
        var open = '[' + tag + ']', close = '[/' + tag + ']', caret = null;
        if (tag === 'url') {
            if (/^https?:\/\/\S+$/i.test(sel)) { open = '[url]'; }
            else { open = '[url=https://]'; caret = start + open.length - 1; }
        }
        ta.setRangeText(open + sel + close, start, end, 'end');
        if (caret !== null) ta.setSelectionRange(caret, caret);
        else if (sel) ta.setSelectionRange(start + open.length, start + open.length + sel.length);
        else ta.setSelectionRange(start + open.length, start + open.length);
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function showError(msg) {
        if (!err) return;
        err.textContent = msg || '';
        err.hidden = !msg;
    }

    function setBusy(on) {
        busy = on;
        saveBtn.disabled = on;
        cancelBtn.disabled = on;
        saveBtn.textContent = on ? T('js.bio.saving') : T('js.bio.save');
    }

    function open() {
        if (busy) return;
        if (!editor) build();
        if (!editor.hidden) { ta.focus(); return; }
        ta.value = root.getAttribute('data-source') || '';
        showError('');
        if (textEl) textEl.hidden = true;
        if (phEl) phEl.hidden = true;
        editor.hidden = false;
        recount();
        grow();
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }

    /** Back to what the page shows: the text when there is one, the dashed box when there is none. */
    function close() {
        var had = editor && editor.contains(document.activeElement);
        if (editor) editor.hidden = true;
        var empty = !textEl || textEl.innerHTML.trim() === '';
        if (textEl) textEl.hidden = empty;
        if (phEl) phEl.hidden = !empty;
        if (had) (empty ? phEl : textEl).focus();
    }

    /** Esc and Cancel: whatever was typed goes, and the text that was there is shown again as it was. */
    function cancel() {
        if (busy) return;
        showError('');
        close();
    }

    function csrf() {
        var f = document.getElementById('account-csrf');
        return f ? f.value : '';
    }

    async function save() {
        if (busy || !ta) return;
        setBusy(true);
        showError('');
        var r = null;
        try {
            var res = await fetch(APP_API + 'profile_bio', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ csrf_token: csrf(), bio: ta.value }),
            });
            r = await res.json();
        } catch (e) { r = null; }
        setBusy(false);
        if (!r || !r.success) {
            showError((r && (r.message || r.error)) || T('js.bio.failed'));
            ta.focus();
            return;
        }
        root.setAttribute('data-source', typeof r.text === 'string' ? r.text : '');
        // The server's own rendering (includes/profilebio.php): escaped text and five tags, nothing
        // else. It is the same string the next page load will print.
        if (textEl) textEl.innerHTML = typeof r.html === 'string' ? r.html : '';
        close();
    }

    // A click on the text opens the editor — except on a link inside it, which is a link, and at the
    // end of a drag that selected some of it, which is somebody copying.
    if (textEl) {
        textEl.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('a')) return;
            var sel = window.getSelection ? String(window.getSelection()) : '';
            if (sel !== '' && textEl.contains(window.getSelection().anchorNode)) return;
            open();
        });
        textEl.addEventListener('keydown', function (e) {
            if (e.target !== textEl) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });
    }
    if (phEl) phEl.addEventListener('click', open);
    document.addEventListener('langswap', label);

    // Arriving from the account page's "Edit on your profile": the editor is already open. The hash is
    // taken off again, so a reload shows the profile rather than reopening the box.
    if (location.hash === '#bio') {
        open();
        try { history.replaceState(history.state, '', location.pathname + location.search); } catch (e) { /* opaque origin */ }
    }
})();
