/**
 * Comments on a torrent (1.71.0): the Info panel's "Comments (N)" section — the thread, the composer, and
 * what the author and a moderator may do to a comment. The server decides and renders everything
 * (includes/comments.php); this draws what it is sent and sends what the reader does.
 *
 * WHERE. assets/js/app.js's openInfo() asks window.Comments.section(json, hash) for the section after the
 * rating block, with the count its one answer carries (api/index_info.php); nothing else of the thread is
 * in that answer. The thread is asked for (api/comment_list.php) when the section comes into view in the
 * panel — an IntersectionObserver on the panel's own scroller — or is opened.
 *
 * THE THREAD reads oldest to newest, and opens on its NEWEST page, with "Show earlier comments" above it:
 * a new comment lands at the end, beside the composer, and a notification's link (#comment-N) lands on its
 * comment, loading earlier pages until it is there (ten at most).
 *
 * A ROW: the author's picture and name (a link to the profile where it opens for this reader), "Guest #tag"
 * for a guest, the time in the reader's zone (the full moment and its offset in the title), "edited" — "by a
 * moderator" when it was one —, a held guest comment's mark, and the actions as icon buttons (part A's
 * kind: the name in aria-label, the explanation in data-tip): Let it through (a moderator, a held guest
 * comment), Edit (in place, the same editor), Delete (your own: a second press confirms; somebody else's, a
 * moderator's: a reason, which its author is shown). A comment by a member THIS reader blocked is folded
 * away behind "Hidden — this member is blocked by you" and a Show button.
 *
 * FOR PART E: window.Comments.onActions(fn) — fn(row, actsElement, api) is called for every row drawn, so
 * the Report button goes in beside Edit and Delete without this file knowing about reports; `row.can_report`
 * is the server's word on it (false until then).
 *
 * THE COMPOSER is the site's rich-text editor (window.RichText.mount — tabs, counter, Preview through
 * api/richtext_preview.php with for=comment, Ctrl+B/I/K), its toolbar limited to what a comment may hold —
 * b i u s, the link only for a writer who may link, quote, spoiler, code — and the emoji picker for the
 * comment context. Ctrl+Enter sends. `@` offers members' names (api/shout_mentions.php, for=comment), as
 * the shoutbox's composer does. A guest's comment asks for a CAPTCHA every time (fetchWithCaptcha(),
 * app.js, solved first); a member's only when the site's points say so (the server answers 428). A reader
 * who may not write is told why: sign in, no permission, silenced until a date, no CAPTCHA for guests.
 *
 * Tokens: csrfToken() (app.js, the page's meta) — never a page's own id, and no csrf() of this file's own.
 */
(function () {
    'use strict';
    if (typeof window.t !== 'function') return;
    const t = window.t;
    const API = typeof APP_API !== 'undefined' ? APP_API : 'api.php?endpoint=';
    const EARLIER_MAX = 10;             // pages a notification's link may load to find its comment
    const MENTION_MIN = 2, MENTION_WAIT = 250;
    const hooks = [];

    const el = (tag, cls, text) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    };
    const glyph = (cls) => { const i = document.createElement('i'); i.className = 'bi ' + cls; i.setAttribute('aria-hidden', 'true'); return i; };
    /** An icon button, part A's kind: one glyph, the name for a screen reader, the explanation in the site's tooltip. */
    const iconBtn = (cls, icon, name, tip) => {
        const b = el('button', cls + ' ic-btn');
        b.type = 'button';
        b.setAttribute('aria-label', name);
        b.dataset.tip = tip || name;
        b.appendChild(glyph(icon));
        return b;
    };
    const getJ = async (path) => {
        try { return await (await fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json(); }
        catch (e) { return null; }
    };
    const postJ = async (endpoint, body) => {
        if (typeof postJson === 'function') return postJson(endpoint, body);
        try {
            const r = await fetch(API + endpoint, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
            return await r.json();
        } catch (e) { return null; }
    };
    const profileHref = (name) => {
        try {
            const u = new URL(location.href);
            u.search = ''; u.hash = '';
            u.searchParams.set('action', 'u');
            u.searchParams.set('name', name);
            return u.href;
        } catch (e) { return '#'; }
    };
    const tip = (anchor, text) => { if (typeof window.pubTip === 'function') window.pubTip(anchor, text); };

    /**
     * What a READER will see, counted as the server counts it (commentParse() in includes/comments.php): the
     * text and any literal tags, not the tags that format it, in code points. A twin for the counter while
     * typing; the server's count — shown after a Preview, and the one a send is judged by — decides.
     */
    function visibleChars(src, links) {
        const LS = String.fromCharCode(0x2028), PS = String.fromCharCode(0x2029);
        const s = String(src || '').split(LS).join('\n').split(PS).join('\n').replace(/\r\n?/g, '\n');
        const re = /\[(?:(b|i|u|s)|\/(b|i|u|s|url|quote|spoiler|code)|(quote|spoiler)(?:=([^\]\n]{0,80}))?|(code)|url(?:(=)([^\]\n]*))?)\]/gi;
        const len = (x) => [...x].length;
        let n = 0, pos = 0, m;
        const stack = [], lit = { quote: 0, spoiler: 0 };
        const has = (tag) => stack.includes(tag);
        re.lastIndex = 0;
        while ((m = re.exec(s)) !== null) {
            n += len(s.slice(pos, m.index));
            pos = m.index + m[0].length;
            const tok = m[0];
            if (m[1]) { if (stack.length >= 8) n += len(tok); else stack.push(m[1].toLowerCase()); continue; }
            if (m[2]) {
                const name = m[2].toLowerCase();
                if (name === 'code') { n += len(tok); continue; }
                if ((name === 'quote' || name === 'spoiler') && lit[name] > 0) { lit[name]--; n += len(tok); continue; }
                const idx = stack.lastIndexOf(name);
                if (idx < 0) { n += len(tok); continue; }
                const reopen = stack.splice(idx + 1).filter((x) => ['b', 'i', 'u', 's'].includes(x));
                stack.splice(idx, 1);
                stack.push(...reopen);
                continue;
            }
            if (m[3]) {
                const kind = m[3].toLowerCase();
                if (has(kind)) { lit[kind]++; n += len(tok); continue; }
                if (has('url') || stack.length >= 8) { n += len(tok); continue; }
                if (m[4]) n += len(m[4].trim().replace(/^(["'])(.*)\1$/, '$2').slice(0, kind === 'quote' ? 64 : 80));
                stack.push(kind);
                continue;
            }
            if (m[5]) {
                const close = s.slice(pos).search(/\[\/code\]/i);
                if (close < 0) { n += len(tok); continue; }
                n += len(s.slice(pos, pos + close).replace(/^\n+|\n+$/g, ''));
                pos += close + '[/code]'.length;
                re.lastIndex = pos;
                continue;
            }
            // [url] / [url=…]: only a writer who may link makes a link of it; otherwise it is text
            if (!links || has('url') || stack.length >= 8) { n += len(tok); continue; }
            if (m[6]) { stack.push('url'); continue; }
            const close = s.slice(pos).search(/\[\/url\]/i);
            if (close < 0) { n += len(tok); continue; }
            n += len(s.slice(pos, pos + close).trim());
            pos += close + '[/url]'.length;
            re.lastIndex = pos;
        }
        n += len(s.slice(pos));
        return n;
    }

    /**
     * The editor: the markup window.RichText.mount() finds by convention round an id (`<id>-count`,
     * `-tools`, `-format`, `-preview`, `-help`, `-emoji`), with a toolbar of what a comment may hold. It has
     * to be in the document before it is mounted — mount() looks its parts up by id.
     */
    function buildEditor(id, me, placeholder) {
        const wrap = el('div', 'cm-editor-wrap');
        const ed = el('div', 'rt-editor cm-editor');
        const tabs = el('div', 'rt-tabs');
        const write = el('button', 'rt-tab active', t('js.comments.write'));
        write.type = 'button'; write.dataset.rt = 'write';
        const prev = el('button', 'rt-tab', t('js.comments.preview'));
        prev.type = 'button'; prev.dataset.rt = 'preview';
        const count = el('span', 'rt-counter');
        count.id = id + '-count';
        const fmt = el('input');
        fmt.type = 'hidden'; fmt.id = id + '-format'; fmt.value = 'bbcode';
        tabs.append(write, prev, count, fmt);
        const tools = el('div', 'rt-tools');
        tools.id = id + '-tools';
        tools.setAttribute('role', 'toolbar');
        tools.setAttribute('aria-label', t('js.comments.toolbar'));
        const group = (items) => {
            const g = el('span', 'rt-tool-group');
            items.forEach(([md, icon, title]) => {
                const b = el('button');
                b.type = 'button';
                b.dataset.md = md;
                b.title = title;
                b.setAttribute('aria-label', title);
                b.appendChild(glyph(icon));
                g.appendChild(b);
            });
            return g;
        };
        tools.appendChild(group([['bold', 'bi-type-bold', t('js.comments.tb_bold')], ['italic', 'bi-type-italic', t('js.comments.tb_italic')],
                                 ['underline', 'bi-type-underline', t('js.comments.tb_underline')], ['strike', 'bi-type-strikethrough', t('js.comments.tb_strike')]]));
        const blocks = [];
        if (me.links) blocks.push(['link', 'bi-link-45deg', t('js.comments.tb_link')]);
        blocks.push(['quote', 'bi-quote', t('js.comments.tb_quote')], ['spoiler', 'bi-eye-slash', t('js.comments.tb_spoiler')], ['code', 'bi-code-slash', t('js.comments.tb_code')]);
        tools.appendChild(group(blocks));
        if (me.picker) {
            // The picker (assets/js/emoji-picker.js), with what the server says this reader may use in a comment:
            // emoji, Font Awesome's icons, the site's emotes — never stickers (a comment draws them as emotes).
            const g = el('span', 'rt-tool-group rt-tool-emoji');
            const b = el('button', 'rt-emoji-btn');
            b.type = 'button';
            b.id = id + '-emoji';
            const name = t(me.picker.emotes ? 'js.comments.emoji_emotes' : 'js.comments.emoji');
            b.title = name;
            b.setAttribute('aria-label', name);
            b.setAttribute('aria-haspopup', 'dialog');
            b.setAttribute('aria-expanded', 'false');
            b.dataset.emojiFor = 'comment';
            b.dataset.emojiFiles = JSON.stringify(me.picker.files || {});
            b.dataset.emojiFa = me.picker.fa || 'off';
            b.dataset.emojiFaV = me.picker.fa_v || '';
            b.dataset.emotes = me.picker.emotes ? '1' : '0';
            b.dataset.stickers = '0';
            b.dataset.emotesPage = me.picker.emotes_page ? '1' : '0';
            b.appendChild(glyph('bi-emoji-smile'));
            g.appendChild(b);
            tools.appendChild(g);
        }
        const ta = el('textarea', 'cm-textarea');
        ta.id = id;
        ta.rows = 3;
        ta.maxLength = Number(me.source_max) || 2000;
        ta.placeholder = placeholder;
        ta.setAttribute('aria-label', placeholder);
        ta.setAttribute('dir', 'auto');
        if (!me.links) {
            // The editor's Ctrl+K puts a link in, and a writer who may not link would only be refused it on
            // sending: here the key does nothing. Registered before mount() adds the editor's own handler,
            // so stopping the rest of this element's listeners stops that one.
            ta.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && !e.altKey && String(e.key || '').toLowerCase() === 'k') e.stopImmediatePropagation();
            });
        }
        const pv = el('div', 'rt-preview rt-body cm-preview');
        pv.id = id + '-preview';
        pv.hidden = true;
        ed.append(tabs, tools, ta, pv);
        const help = el('div', 'form-hint cm-help');
        help.id = id + '-help';
        help.setAttribute('aria-live', 'polite');
        wrap.append(ed, help);
        return { wrap, ta };
    }

    function mountEditor(id, me) {
        if (!window.RichText || typeof window.RichText.mount !== 'function') return;
        const max = Number(me.max) || 500;
        window.RichText.mount(id, { previewFor: 'comment', measure: (text) => ({ used: visibleChars(text, !!me.links), limit: max }) });
    }

    /* ─────────────────────────── the @ list (the shoutbox's, for a comment) ─────────────────────────── */
    function mentions(ta, host) {
        let pop = null, names = [], at = -1, q = '', sel = 0, timer = 0, flight = false;
        const cache = {};
        // A '.' or '-' just typed after a name ends it (1.71.0 — the room's rule, shoutMentionCandidates()).
        const token = () => {
            if (typeof ta.selectionStart !== 'number' || ta.selectionStart !== ta.selectionEnd) return null;
            const m = /(?:^|[^\w@])@((?:[A-Za-z0-9_.-]{0,31}[A-Za-z0-9_])?)$/.exec(ta.value.slice(0, ta.selectionStart));
            return m ? { q: m[1], at: ta.selectionStart - m[1].length } : null;
        };
        const close = () => { clearTimeout(timer); if (pop) { pop.remove(); pop = null; } names = []; at = -1; };
        const mark = () => {
            if (!pop) return;
            [...pop.children].forEach((b, i) => { b.classList.toggle('active', i === sel); b.setAttribute('aria-selected', i === sel ? 'true' : 'false'); });
        };
        const pick = (name) => {
            if (at < 0) return;
            const before = ta.value.slice(0, at), after = ta.value.slice(at + q.length);
            const add = String(name) + (/^\s/.test(after) ? '' : ' ');
            close();                                    // (forgets `at` — the caret is placed from `before`)
            if (ta.maxLength > 0 && before.length + add.length + after.length > ta.maxLength) return;
            ta.value = before + add + after;
            const p = before.length + add.length;
            try { ta.setSelectionRange(p, p); } catch (e) { /* a box that will not be told */ }
            ta.focus();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        };
        const show = (list, tok) => {
            if (pop) { pop.remove(); pop = null; }
            names = list || [];
            if (!names.length) { at = -1; return; }
            at = tok.at; q = tok.q;
            if (sel >= names.length) sel = 0;
            pop = el('div', 'shout-mention-pop cm-mention-pop');
            pop.setAttribute('role', 'listbox');
            pop.setAttribute('aria-label', t('js.comments.mention_list'));
            names.forEach((n) => {
                const b = el('button', 'shout-mention-item', n);
                b.type = 'button';
                b.setAttribute('role', 'option');
                b.addEventListener('mousedown', (ev) => { ev.preventDefault(); pick(n); });
                pop.appendChild(b);
            });
            host.appendChild(pop);
            mark();
        };
        const fetchNames = async (tok) => {
            const key = tok.q.toLowerCase();
            if (Object.prototype.hasOwnProperty.call(cache, key)) { show(cache[key], tok); return; }
            if (flight) return;
            flight = true;
            let j = null;
            try { j = await getJ('shout_mentions&for=comment&q=' + encodeURIComponent(tok.q)); } finally { flight = false; }
            const list = (j && j.success && Array.isArray(j.names)) ? j.names.slice(0, 8) : [];
            cache[key] = list;
            const now = token();
            if (!now || now.q !== tok.q || now.at !== tok.at) return;
            show(list, tok);
        };
        ta.addEventListener('input', () => {
            clearTimeout(timer);
            const tok = token();
            if (!tok || tok.q.length < MENTION_MIN) { close(); return; }
            sel = 0;
            timer = setTimeout(() => fetchNames(tok), MENTION_WAIT);
        });
        ta.addEventListener('blur', () => setTimeout(close, 150));
        // Returns true when the list took the key: Enter picks a name instead of sending.
        return function key(e) {
            if (!pop || !names.length) return false;
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return true; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                sel = (sel + (e.key === 'ArrowDown' ? 1 : names.length - 1)) % names.length;
                mark();
                return true;
            }
            if ((e.key === 'Enter' || e.key === 'Tab') && !e.shiftKey && !e.altKey && !e.isComposing) {
                e.preventDefault();
                pick(names[sel]);
                return true;
            }
            return false;
        };
    }

    /* ─────────────────────────────────────── one section ─────────────────────────────────────── */

    /**
     * The section for one torrent, or null when there is nothing to draw (the answer carries no `comments`:
     * comments off, or none this reader may know of). `json.comments` = {view, count, post, signed_in}.
     */
    function section(json, hash) {
        const info = json && json.comments;
        if (!info) return null;
        const sec = el('details', 'rt-collapse info-section info-comments');
        sec.id = 'info-comments';
        sec.open = true;
        sec.dataset.hash = hash;
        const sum = el('summary', 'cm-summary');
        const headText = el('span', 'cm-head-text');
        sum.append(glyph('bi-chevron-right disc-chev'), glyph('bi-chat-left-text cm-head-icon'), headText);
        sec.appendChild(sum);
        const body = el('div', 'cm-section-body');
        sec.appendChild(body);
        let count = Number(info.count) || 0;
        const drawHead = () => { headText.textContent = count > 0 ? t('js.comments.heading', { n: count }) : t('js.comments.heading_none'); };
        drawHead();
        if (!info.view) {
            // Words exist that this reader may not read: said, so the space does not read as "nobody said anything".
            body.appendChild(el('p', 'text-muted cm-hidden-note', t('js.comments.members_only')));
            return sec;
        }
        const pendingNote = el('p', 'cm-pending-note', '');
        pendingNote.hidden = true;
        const earlierBtn = el('button', 'btn btn-secondary btn-small cm-earlier', t('js.comments.earlier'));
        earlierBtn.type = 'button';
        earlierBtn.hidden = true;
        const list = el('div', 'cm-list');
        list.setAttribute('aria-live', 'polite');
        const empty = el('p', 'text-muted cm-empty', t('js.comments.none_yet'));
        empty.hidden = true;
        const status = el('p', 'text-muted cm-status', t('js.common.loading'));
        const compose = el('div', 'cm-compose');
        body.append(pendingNote, earlierBtn, list, empty, status, compose);

        // A notification's link: #comment-N — read NOW, before anything else can move the address.
        let target = 0;
        const hm = /^#comment-(\d{1,19})$/.exec(location.hash || '');
        if (hm) target = Number(hm[1]);

        let loaded = false, loading = false, oldest = 0, me = null, earlierPages = 0;

        const rowsOf = () => [...list.querySelectorAll('.cm-row')];
        const setCount = (n) => { count = Math.max(0, Number(n) || 0); drawHead(); };
        const syncEmpty = () => { empty.hidden = rowsOf().length > 0; };

        async function load(before) {
            if (loading) return;
            loading = true;
            earlierBtn.disabled = true;
            const j = await getJ('comment_list&hash=' + encodeURIComponent(hash) + (before ? '&before=' + before : ''));
            loading = false;
            earlierBtn.disabled = false;
            if (!sec.isConnected) return;
            if (!j || !j.success) {
                status.hidden = false;
                status.textContent = (j && j.message) || t('js.comments.load_failed');
                return;
            }
            status.hidden = true;
            const rows = Array.isArray(j.rows) ? j.rows : [];
            const frag = document.createDocumentFragment();
            rows.forEach((r) => frag.appendChild(drawRow(r)));
            if (before) list.insertBefore(frag, list.firstChild); else { list.textContent = ''; list.appendChild(frag); }
            if (rows.length) oldest = Number(rows[0].id) || oldest;
            earlierBtn.hidden = !j.earlier;
            setCount(j.count);
            if (Number(j.pending) > 0) {
                pendingNote.hidden = false;
                pendingNote.textContent = t('js.comments.pending_note', { n: Number(j.pending) });
            } else {
                pendingNote.hidden = true;
            }
            if (!me) { me = j.me || {}; drawComposer(); }
            syncEmpty();
            loaded = true;
            if (target) seek(j.earlier);
        }

        /** Bring a notification's comment into view, loading earlier pages until it is there. */
        function seek(more) {
            const row = document.getElementById('comment-' + target);
            if (row) {
                target = 0;
                row.classList.add('cm-target');
                try { row.scrollIntoView({ block: 'center' }); } catch (e) { row.scrollIntoView(); }
                setTimeout(() => row.classList.remove('cm-target'), 4000);
                try { history.replaceState(history.state, '', location.pathname + location.search); } catch (e) { /* not addressable */ }
                return;
            }
            if (more && earlierPages < EARLIER_MAX) { earlierPages++; load(oldest); return; }
            target = 0;
        }

        earlierBtn.addEventListener('click', () => load(oldest));

        /* ── a row ── */
        function drawRow(r) {
            const row = el('article', 'cm-row' + (r.own ? ' cm-own' : '') + (r.status === 'pending' ? ' cm-held' : ''));
            row.id = 'comment-' + r.id;
            row.dataset.id = String(r.id);
            const head = el('div', 'cm-head');
            const who = el(r.profile ? 'a' : 'span', 'cm-who av-who');
            if (r.guest) {
                who.classList.add('cm-guest');
                who.textContent = t('js.comments.guest') + (r.guest_tag ? ' #' + r.guest_tag : '');
                who.title = t('js.comments.guest_title');
            } else if (r.gone || !r.user) {
                who.classList.add('cm-gone');
                who.textContent = t('js.comments.deleted_account');
            } else {
                if (r.profile) who.href = profileHref(r.user);
                const pic = typeof window.userAvatarImg === 'function' ? window.userAvatarImg({ username: r.user, avatar: String(r.avatar || '') }, 24, 'avatar cm-av') : null;
                if (pic) who.appendChild(pic);
                who.appendChild(document.createTextNode(r.user));
            }
            const time = el('time', 'cm-time', r.time || '');
            if (r.at) { time.title = r.at; time.dateTime = r.ts ? new Date(r.ts * 1000).toISOString() : ''; }
            head.append(who, time);
            if (r.edited) {
                const ed = el('span', 'cm-edited', t(r.edited_mod ? 'js.comments.edited_mod' : 'js.comments.edited'));
                if (r.edited_at) ed.title = t('js.comments.edited_title', { at: r.edited_at });
                head.appendChild(ed);
            }
            if (r.status === 'pending') head.appendChild(el('span', 'cm-held-badge', t('js.comments.held')));
            const acts = el('span', 'cm-acts');
            head.appendChild(acts);
            row.appendChild(head);
            const text = el('div', 'rt-body cm-text');
            text.setAttribute('dir', 'auto');
            // Built on the server out of fully escaped input with the comment's own allow-list
            // (commentParse(), includes/comments.php) — the same markup every reader is handed.
            text.innerHTML = r.html || '';
            if (r.blocked) {
                // Somebody this reader blocked: folded away, and theirs to open.
                const fold = el('div', 'cm-blocked');
                fold.appendChild(el('span', 'text-muted', t('js.comments.blocked')));
                const show = el('button', 'btn btn-secondary btn-small cm-blocked-show', t('js.comments.show'));
                show.type = 'button';
                show.setAttribute('aria-expanded', 'false');
                show.addEventListener('click', () => {
                    const open = text.hidden;
                    text.hidden = !open;
                    show.textContent = t(open ? 'js.comments.hide' : 'js.comments.show');
                    show.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                fold.appendChild(show);
                text.hidden = true;
                row.appendChild(fold);
            }
            row.appendChild(text);
            drawActs(r, row, acts, text);
            hooks.forEach((fn) => { try { fn(r, acts, { hash, row, reload: () => load(0) }); } catch (e) { /* a hook's own fault */ } });
            return row;
        }

        function drawActs(r, row, acts, text) {
            if (r.can_approve) {
                const b = iconBtn('btn btn-secondary btn-small cm-approve', 'bi-check-lg', t('js.comments.approve'), t('js.comments.approve_tip'));
                b.addEventListener('click', async () => {
                    b.disabled = true;
                    const j = await postJ('comment_approve', { csrf_token: csrfToken(sec), id: r.id });
                    if (!j || !j.success) { b.disabled = false; tip(b, (j && j.message) || t('js.comments.failed')); return; }
                    if (j.comment) row.replaceWith(drawRow(j.comment));
                    setCount(j.count);
                    pendingNote.hidden = true;
                });
                acts.appendChild(b);
            }
            if (r.can_edit) {
                const b = iconBtn('btn btn-secondary btn-small cm-edit', 'bi-pencil-square', t('js.comments.edit'),
                                  t(r.own ? 'js.comments.edit_tip_own' : 'js.comments.edit_tip_any'));
                b.addEventListener('click', () => openEdit(r, row, text, acts));
                acts.appendChild(b);
            }
            if (r.can_delete) {
                const own = r.can_delete === 'own';
                const name = t('js.comments.delete');
                const dtip = t(own ? 'js.comments.delete_tip_own' : 'js.comments.delete_tip_any');
                const b = iconBtn('btn btn-secondary btn-small cm-delete', 'bi-trash', name, dtip);
                acts.appendChild(b);
                if (own) {
                    // Your own: two presses, the first arms it (the filled bin, in the error colour, the tip held).
                    let armTimer = 0, armTip = null;
                    const icon = () => b.querySelector(':scope > .bi');
                    const disarm = () => {
                        clearTimeout(armTimer);
                        b.dataset.armed = '0';
                        b.classList.remove('is-armed');
                        if (icon()) icon().className = 'bi bi-trash';
                        b.setAttribute('aria-label', name);
                        b.dataset.tip = dtip;
                        if (armTip) { armTip.drop(); armTip = null; }
                    };
                    b.addEventListener('click', async () => {
                        if (b.dataset.armed !== '1') {
                            b.dataset.armed = '1';
                            b.classList.add('is-armed');
                            if (icon()) icon().className = 'bi bi-trash-fill';
                            b.setAttribute('aria-label', t('js.comments.delete_sure'));
                            b.dataset.tip = t('js.comments.delete_sure');
                            if (typeof window.pubTip === 'function') armTip = window.pubTip(b, t('js.comments.delete_sure'), { hold: true });
                            armTimer = setTimeout(disarm, 4000);
                            return;
                        }
                        disarm();
                        b.disabled = true;
                        const j = await postJ('comment_delete', { csrf_token: csrfToken(sec), id: r.id });
                        if (!j || !j.success) { b.disabled = false; tip(b, (j && j.message) || t('js.comments.failed')); return; }
                        row.remove();
                        setCount(j.count);
                        syncEmpty();
                    });
                } else {
                    // Somebody else's (a moderator's): the reason first — its author is shown it.
                    b.addEventListener('click', () => openReason(r, row, b));
                }
            }
        }

        /** A moderator's removal: a reason, in place, sent with the removal. */
        function openReason(r, row, btn) {
            if (row.querySelector('.cm-reason')) return;
            const box = el('div', 'cm-reason');
            const id = 'cm-reason-' + r.id;
            const lab = el('label', 'cm-reason-label', t('js.comments.reason_label'));
            lab.htmlFor = id;
            const inp = el('input', 'profile-search cm-reason-input');
            inp.type = 'text'; inp.id = id; inp.maxLength = 255; inp.placeholder = t('js.comments.reason_ph');
            const go = el('button', 'btn btn-small cm-reason-go', t('js.comments.reason_go'));
            go.type = 'button';
            const no = el('button', 'btn btn-secondary btn-small cm-reason-cancel', t('js.common.cancel'));
            no.type = 'button';
            const msg = el('span', 'text-muted cm-reason-msg');
            msg.setAttribute('aria-live', 'polite');
            box.append(lab, inp, go, no, msg);
            row.appendChild(box);
            btn.disabled = true;
            inp.focus();
            const done = () => { box.remove(); btn.disabled = false; btn.focus(); };
            no.addEventListener('click', done);
            inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go.click(); } else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(); } });
            go.addEventListener('click', async () => {
                const reason = inp.value.trim();
                if (!reason) { msg.textContent = t('js.comments.reason_needed'); inp.focus(); return; }
                go.disabled = true;
                const j = await postJ('comment_delete', { csrf_token: csrfToken(sec), id: r.id, reason });
                go.disabled = false;
                if (!j || !j.success) { msg.textContent = (j && j.message) || t('js.comments.failed'); return; }
                row.remove();
                setCount(j.count);
                syncEmpty();
            });
        }

        /** Correcting a comment in place: the words as stored, the same editor, Save and Cancel. */
        async function openEdit(r, row, text, acts) {
            if (row.querySelector('.cm-edit-box')) return;
            const src = await getJ('comment_edit&id=' + encodeURIComponent(r.id));
            if (!src || !src.success) { tip(acts, (src && src.message) || t('js.comments.failed')); return; }
            const id = 'cm-ed-' + r.id;
            // A moderator's correction of somebody else's words keeps THEIR right to link, and may say why.
            const edMe = Object.assign({}, me || {}, { picker: me && me.picker ? me.picker : null });
            const { wrap, ta } = buildEditor(id, edMe, t('js.comments.placeholder_edit'));
            const box = el('div', 'cm-edit-box');
            box.appendChild(wrap);
            let reason = null;
            if (!src.own) {
                reason = el('input', 'profile-search cm-edit-reason');
                reason.type = 'text'; reason.maxLength = 255; reason.placeholder = t('js.comments.edit_reason_ph');
                reason.setAttribute('aria-label', t('js.comments.edit_reason_ph'));
                box.appendChild(reason);
            }
            const foot = el('div', 'cm-edit-foot');
            const save = el('button', 'btn btn-small cm-edit-save', t('js.comments.save'));
            save.type = 'button';
            const cancel = el('button', 'btn btn-secondary btn-small cm-edit-cancel', t('js.common.cancel'));
            cancel.type = 'button';
            const msg = el('span', 'text-muted cm-edit-msg');
            msg.setAttribute('aria-live', 'polite');
            foot.append(save, cancel, msg);
            box.appendChild(foot);
            text.hidden = true;
            acts.hidden = true;
            row.appendChild(box);
            ta.value = String(src.body || '');
            mountEditor(id, edMe);
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.focus();
            try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) { /* not a text box */ }
            const close = () => { box.remove(); text.hidden = false; acts.hidden = false; };
            cancel.addEventListener('click', close);
            const doSave = async () => {
                if (save.disabled) return;
                const body = ta.value.trim();
                if (!body) { msg.textContent = t('js.comments.empty'); return; }
                save.disabled = true;
                const payload = { csrf_token: csrfToken(sec), id: r.id, body };
                if (reason && reason.value.trim()) payload.reason = reason.value.trim();
                // A correction passes the anti-spam layer (1.71.0): a few seconds from the last — a wait counts
                // down on Save (assets/js/antispam.js).
                const doPost = (extra) => postJ('comment_edit', Object.assign({}, payload, extra || {}));
                const j = window.Antispam
                    ? await window.Antispam.send(doPost, { button: save, note: (s) => { msg.textContent = s || ''; }, action: 'comment_edit' })
                    : await doPost({});
                if (!(window.Antispam && window.Antispam.waiting(save))) save.disabled = false;
                if (!j || !j.success) {
                    if (!(window.Antispam && window.Antispam.waiting(save))) msg.textContent = (j && j.message) || t('js.comments.failed');
                    return;
                }
                if (j.comment) row.replaceWith(drawRow(j.comment)); else close();
            };
            save.addEventListener('click', doSave);
            ta.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); doSave(); } });
        }

        /* ── the composer ── */
        function drawComposer() {
            // Drawn again after the language changed: whatever was being written is carried over.
            const was = compose.querySelector('#cm-new');
            const kept = was ? was.value : '';
            compose.textContent = '';
            if (!me.can_post) {
                const why = me.why || '';
                const p = el('p', 'text-muted cm-why cm-why-' + (why || 'none'));
                if (why === 'login') {
                    p.appendChild(document.createTextNode(t('js.comments.why_login') + ' '));
                    const a = el('a', 'cm-sign-in', t('js.comments.sign_in'));
                    try { const u = new URL(location.href); u.search = '?action=login'; u.hash = ''; a.href = u.href; } catch (e) { a.href = '?action=login'; }
                    p.appendChild(a);
                } else if (why === 'muted') {
                    p.textContent = t('js.comments.why_muted', { until: me.until || '' });
                } else if (why === 'guest_captcha') {
                    p.textContent = t('js.comments.why_guest_captcha');
                } else if (why === 'no_permission') {
                    p.textContent = t('js.comments.why_no_permission');
                } else {
                    return;
                }
                compose.appendChild(p);
                return;
            }
            const id = 'cm-new';
            const who = el('p', 'text-muted cm-as');
            who.textContent = me.guest ? t(me.review ? 'js.comments.as_guest_review' : 'js.comments.as_guest') : t('js.comments.as_member', { name: me.name || '' });
            const { wrap, ta } = buildEditor(id, me, t(me.mentions ? 'js.comments.placeholder' : 'js.comments.placeholder_plain'));
            const hint = el('p', 'form-hint cm-hint', t(me.links ? 'js.comments.hint' : 'js.comments.hint_nolinks'));
            // A new account's links are shown as text (1.71.0, the anti-spam layer): said before anybody types one.
            if (me.links && me.links_text) hint.appendChild(document.createTextNode(' ' + t('js.comments.links_text', { days: me.new_days || '' })));
            const foot = el('div', 'cm-compose-foot');
            const send = el('button', 'btn btn-small cm-send', t('js.comments.send'));
            send.type = 'button';
            const msg = el('span', 'text-muted cm-send-msg');
            msg.setAttribute('aria-live', 'polite');
            foot.append(send, msg);
            compose.append(who, wrap, hint, foot);
            if (kept) ta.value = kept;
            mountEditor(id, me);
            const mentionKey = me.mentions ? mentions(ta, compose) : null;
            const doSend = async () => {
                if (send.disabled) return;
                const body = ta.value.trim();
                if (!body) { msg.textContent = t('js.comments.empty'); ta.focus(); return; }
                send.disabled = true;
                msg.textContent = t('js.comments.sending');
                const data = { csrf_token: csrfToken(sec), hash, body };
                // Through the site's anti-spam layer (1.71.0, assets/js/antispam.js). A guest solves a CAPTCHA
                // every time, so it is asked for first; a member only when the server says so (428) — at the top
                // of the comment ladder — and the same comment goes again. A wait counts down on Send.
                const doPost = (extra) => postJ('comment_post', Object.assign({}, data, extra || {}));
                const j = window.Antispam
                    ? await window.Antispam.send(doPost, { button: send, note: (s) => { msg.textContent = s || ''; }, action: 'comment_post',
                                                          solveFirst: !!me.guest && !!me.captcha })
                    : (typeof fetchWithCaptcha === 'function' ? await fetchWithCaptcha('comment_post', data, !!me.guest && !!me.captcha)
                                                              : await doPost({}));
                if (!(window.Antispam && window.Antispam.waiting(send))) send.disabled = false;
                if (!j || !j.success) {
                    if (!(window.Antispam && window.Antispam.waiting(send))) msg.textContent = (j && (j.message || j.error)) || t('js.comments.failed');
                    return;
                }
                msg.textContent = j.message || '';
                ta.value = '';
                ta.dispatchEvent(new Event('input', { bubbles: true }));
                if (j.comment && !j.pending) {
                    list.appendChild(drawRow(j.comment));
                    syncEmpty();
                }
                setCount(j.count);
            };
            send.addEventListener('click', doSend);
            ta.addEventListener('keydown', (e) => {
                if (mentionKey && mentionKey(e)) return;
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); doSend(); }
            });
        }

        /* ── when to load: in view, or opened ── */
        const kick = () => { if (!loaded && !loading && sec.open) load(0); };
        sec.addEventListener('toggle', kick);
        setTimeout(() => {
            if (!sec.isConnected) return;
            if ('IntersectionObserver' in window) {
                const root = sec.closest('.files-body') || null;
                const io = new IntersectionObserver((entries) => {
                    if (entries.some((e) => e.isIntersecting)) { io.disconnect(); kick(); }
                }, { root, rootMargin: '200px' });
                io.observe(sec);
            } else {
                kick();
            }
            // A notification's link, or a thread asked for straight away: no waiting for the scroll.
            if (target) kick();
        }, 0);
        // The live language switch cannot reach what this file drew: drawn again, in the new language —
        // unless the page redraws the whole panel itself (the search page does, and this section goes with it).
        document.addEventListener('langswap', function redraw() {
            if (!sec.isConnected) { document.removeEventListener('langswap', redraw); return; }
            drawHead();
            earlierBtn.textContent = t('js.comments.earlier');
            empty.textContent = t('js.comments.none_yet');
            if (loaded) { me = null; load(0); }
        });
        return sec;
    }

    /* ─────────────────── the account page: which comments I am told about ─────────────────── */
    function initPrefs() {
        const box = document.getElementById('acc-comment-prefs');
        if (!box) return;
        const status = document.getElementById('acc-comment-prefs-msg');
        box.querySelectorAll('input[type="checkbox"][data-pref]').forEach((cb) => {
            cb.addEventListener('change', async () => {
                cb.disabled = true;
                const j = await postJ('comment_prefs', { csrf_token: csrfToken(box), [cb.dataset.pref]: cb.checked ? 1 : 0 });
                cb.disabled = false;
                if (!j || !j.success) {
                    cb.checked = !cb.checked;
                    if (status) status.textContent = (j && j.message) || t('js.comments.failed');
                    return;
                }
                if (j.prefs && typeof j.prefs[cb.dataset.pref] === 'boolean') cb.checked = j.prefs[cb.dataset.pref];
                if (status) {
                    status.textContent = t('js.comments.prefs_saved');
                    setTimeout(() => { if (status.textContent === t('js.comments.prefs_saved')) status.textContent = ''; }, 2500);
                }
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPrefs);
    else initPrefs();

    window.Comments = {
        section,
        /** Part E: fn(row, actsElement, {hash, row, reload}) for every comment drawn — the Report button's door. */
        onActions: (fn) => { if (typeof fn === 'function') hooks.push(fn); },
        visibleChars,
    };
})();
