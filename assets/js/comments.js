/**
 * Comments on a torrent (1.71.0): the Info panel's "Comments (N)" section — the thread, the composer, and
 * what the author and a moderator may do to a comment. The server decides and renders everything
 * (includes/comments.php); this draws what it is sent and sends what the reader does.
 *
 * WHERE. assets/js/app.js's openInfo() asks window.Comments.section(json, hash) for the section, with the
 * count its one answer carries (api/index_info.php), and places it where Settings says (1.72.0,
 * comments_position: the panel's very end by default, before the files, or after the rating as in 1.71.0);
 * nothing else of the thread is in that answer. It opens folded unless Settings says unfolded
 * (comments_expanded). The thread is asked for (api/comment_list.php) when the section is OPEN: at once when
 * it opens so (its own opening's `toggle`; the IntersectionObserver on the panel's scroller asks too, and
 * finds it loading), and on the click that opens it when it is folded — measured: 0 requests while folded,
 * even in view, 1 on the click; unfolded, 1 at once, the section out of view.
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
 * REPLIES (1.72.0). Each top-level comment is a thread (.cm-thread#cm-thread-N): the comment, a fold ("Hide
 * replies" / "Show N replies") and its replies nested under it (.cm-kids#cm-kids-N, a left rule per level, narrower
 * steps on a phone), oldest first, as deep as the answer's `reply_depth`; a reply deeper than that (the setting was
 * lowered) is drawn at the deepest level allowed, after what it answers, saying "in reply to NAME". A thread comes
 * with its first replies and "Show N more replies" at its end (api/comment_list.php, thread=R&after=ID). A Reply
 * icon button stands first among the actions of every comment the server says this reader may answer
 * (`can_reply`: never at the limit, never a held or a deleted one); it opens the composer under the comment — the
 * same editor, picker, @ list, counter, anti-spam wait and CAPTCHA as the one at the foot — "Replying to NAME" and an
 * x to cancel. One is open at a time; closing it (the x, or Esc — the Info panel's layered Esc closes it before the
 * panel: it is marked data-esc-layer, app.js escLayer()) keeps its words for as long as the section lives. A comment
 * that went while replies hang from it keeps its place as "[deleted]" / "[removed by a moderator]" (`tomb`), and
 * goes with its last reply. Every row, container and composer has an id of its own (comment-N, cm-node-N,
 * cm-kids-N, cm-more-N, cm-rp-N): the live language switch and the place keeper pair by id.
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
        b.setAttribute('data-tip', tip || name);   // setAttribute keeps a t.key() word's key (dataset would not), 1.73.0
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
        const write = el('button', 'rt-tab active', t.key('js.comments.write'));
        write.type = 'button'; write.dataset.rt = 'write';
        const prev = el('button', 'rt-tab', t.key('js.comments.preview'));
        prev.type = 'button'; prev.dataset.rt = 'preview';
        const count = el('span', 'rt-counter');
        count.id = id + '-count';
        const fmt = el('input');
        fmt.type = 'hidden'; fmt.id = id + '-format'; fmt.value = 'bbcode';
        tabs.append(write, prev, count, fmt);
        const tools = el('div', 'rt-tools');
        tools.id = id + '-tools';
        tools.setAttribute('role', 'toolbar');
        tools.setAttribute('aria-label', t.key('js.comments.toolbar'));
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
        tools.appendChild(group([['bold', 'bi-type-bold', t.key('js.comments.tb_bold')], ['italic', 'bi-type-italic', t.key('js.comments.tb_italic')],
                                 ['underline', 'bi-type-underline', t.key('js.comments.tb_underline')], ['strike', 'bi-type-strikethrough', t.key('js.comments.tb_strike')]]));
        const blocks = [];
        if (me.links) blocks.push(['link', 'bi-link-45deg', t.key('js.comments.tb_link')]);
        blocks.push(['quote', 'bi-quote', t.key('js.comments.tb_quote')], ['spoiler', 'bi-eye-slash', t.key('js.comments.tb_spoiler')], ['code', 'bi-code-slash', t.key('js.comments.tb_code')]);
        tools.appendChild(group(blocks));
        if (me.picker) {
            // The picker (assets/js/emoji-picker.js), with what the server says this reader may use in a comment:
            // emoji, Font Awesome's icons, the site's emotes — never stickers (a comment draws them as emotes).
            const g = el('span', 'rt-tool-group rt-tool-emoji');
            const b = el('button', 'rt-emoji-btn');
            b.type = 'button';
            b.id = id + '-emoji';
            const name = t.key(me.picker.emotes ? 'js.comments.emoji_emotes' : 'js.comments.emoji');
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
            pop.setAttribute('aria-label', t.key('js.comments.mention_list'));
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
    // WHERE AND HOW IT OPENS (1.72.0): Settings says where the section stands in the panel (comments_position — the
    // panel's very end by default; app.js places it) and whether it opens unfolded (comments_expanded — folded by
    // default: its heading carries the count, and a click opens it and loads the thread).

    /**
     * The section for one torrent, or null when there is nothing to draw (the answer carries no `comments`:
     * comments off, or none this reader may know of). `json.comments` = {view, count, post, signed_in, position,
     * expanded} — where it stands is app.js's (openInfo()), whether it opens unfolded is this file's (1.72.0:
     * folded unless Settings says otherwise; a notification's link opens it anyway, to land on its comment, and
     * so does `opts.open` — the panel drawn again for the same torrent with the section open).
     */
    function section(json, hash, opts) {
        const info = json && json.comments;
        if (!info) return null;
        // A notification's link: #comment-N — read NOW, before anything else can move the address.
        let target = 0;
        const hm = /^#comment-(\d{1,19})$/.exec(location.hash || '');
        if (hm) target = Number(hm[1]);
        const sec = el('details', 'rt-collapse info-section info-comments');
        sec.id = 'info-comments';
        sec.open = !!info.expanded || target > 0 || !!(opts && opts.open);
        sec.dataset.hash = hash;
        const sum = el('summary', 'cm-summary');
        const headText = el('span', 'cm-head-text');
        sum.append(glyph('bi-chevron-right disc-chev'), glyph('bi-chat-left-text cm-head-icon'), headText);
        sec.appendChild(sum);
        const body = el('div', 'cm-section-body');
        sec.appendChild(body);
        let count = Number(info.count) || 0;
        const drawHead = () => { headText.textContent = count > 0 ? t.key('js.comments.heading', { n: count }) : t.key('js.comments.heading_none'); };
        drawHead();
        if (!info.view) {
            // Words exist that this reader may not read: said, so the space does not read as "nobody said anything".
            body.appendChild(el('p', 'text-muted cm-hidden-note', t.key('js.comments.members_only')));
            return sec;
        }
        const pendingNote = el('p', 'cm-pending-note', '');
        pendingNote.hidden = true;
        const earlierBtn = el('button', 'btn btn-secondary btn-small cm-earlier', t.key('js.comments.earlier'));
        earlierBtn.type = 'button';
        earlierBtn.hidden = true;
        const list = el('div', 'cm-list');
        list.setAttribute('aria-live', 'polite');
        const empty = el('p', 'text-muted cm-empty', t.key('js.comments.none_yet'));
        empty.hidden = true;
        const status = el('p', 'text-muted cm-status', t.key('js.common.loading'));
        const compose = el('div', 'cm-compose');
        body.append(pendingNote, earlierBtn, list, empty, status, compose);

        let loaded = false, loading = false, oldest = 0, me = null, earlierPages = 0;
        // The tree (1.72.0). `nodes`: comment id → {row, node, art, kids, box, level, root, isRoot} — every comment
        // drawn, top-level and reply, tombstones included; `threads`: top-level id → its fold, its "Show more", how
        // far its replies are loaded; `depth`: how deep this site's threads may go (the answer's reply_depth).
        // `drafts`: the words of every reply composer, `openRp` which one is open, `folded` the threads folded — kept
        // across a reload of the list. (They were also carried across the search page's redraw of the whole panel on
        // a language switch; nothing is redrawn on a switch any more, 1.73.0.)
        const nodes = new Map(), threads = new Map(), drafts = new Map(), folded = new Set();
        let depth = 0, openRp = 0;

        const threadsOf = () => [...list.querySelectorAll(':scope > .cm-thread')];
        const setCount = (n) => { count = Math.max(0, Number(n) || 0); drawHead(); };
        const syncEmpty = () => { empty.hidden = threadsOf().length > 0; };

        async function load(before) {
            if (loading) return;
            loading = true;
            earlierBtn.disabled = true;
            const j = await getJ('comment_list&hash=' + encodeURIComponent(hash) + (before ? '&before=' + before : '')
                                 + (target ? '&find=' + target : ''));
            loading = false;
            earlierBtn.disabled = false;
            if (!sec.isConnected) return;
            if (!j || !j.success) {
                status.hidden = false;
                status.textContent = (j && j.message) || t.key('js.comments.load_failed');
                return;
            }
            status.hidden = true;
            depth = Math.max(0, Number(j.reply_depth) || 0);
            const rows = Array.isArray(j.rows) ? j.rows : [];
            if (!before) {
                // Drawn again from nothing: what the reader had open or folded is read first, and put back after.
                list.textContent = '';
                nodes.clear();
                threads.clear();
            }
            const frag = document.createDocumentFragment();
            rows.forEach((r) => frag.appendChild(drawThread(r)));
            if (before) list.insertBefore(frag, list.firstChild); else list.appendChild(frag);
            threads.forEach((th) => syncThread(th));
            if (rows.length) oldest = Number(rows[0].id) || oldest;
            earlierBtn.hidden = !j.earlier;
            setCount(j.count);
            if (Number(j.pending) > 0) {
                pendingNote.hidden = false;
                pendingNote.textContent = t.key('js.comments.pending_note', { n: Number(j.pending) });
            } else {
                pendingNote.hidden = true;
            }
            if (!me) { me = j.me || {}; drawComposer(); }
            syncEmpty();
            loaded = true;
            // The reply composer that was open when the section was drawn again opens again, with its words.
            if (!before && openRp) {
                const was = openRp;
                openRp = 0;
                const n = nodes.get(was);
                if (n && n.row.can_reply) openReply(n.row);
            }
            if (target) seek(j.earlier);
        }

        /** Bring a notification's comment into view, loading earlier pages until it is there (its thread unfolded). */
        function seek(more) {
            const row = document.getElementById('comment-' + target);
            if (row) {
                target = 0;
                const n = nodes.get(Number(row.dataset.id));
                const th = n ? threads.get(n.root) : null;
                if (th && th.folded) setFold(th, false);
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

        /* ── the tree (1.72.0) ── */
        /** Who a comment is by, as a reply names it: "@name", "Guest #tag", "a deleted account" — '' for a tombstone. */
        const nameOf = (r) => {
            if (!r || r.tomb) return '';
            if (r.guest) return r.guest_tag ? t.key('js.comments.guest_named', { tag: r.guest_tag }) : t.key('js.comments.guest');
            if (r.gone || !r.user) return t.key('js.comments.deleted_account');
            return '@' + r.user;
        };

        /** A top-level comment and everything under it: the comment, the fold, its replies, "Show more". */
        function drawThread(r) {
            const n = drawNode(r, 0, list, true);
            const node = n.node;
            node.classList.add('cm-thread');
            node.id = 'cm-thread-' + r.id;
            const bar = el('div', 'cm-thread-bar');
            const fold = el('button', 'btn btn-secondary btn-small cm-fold');
            fold.type = 'button';
            fold.id = 'cm-fold-' + r.id;
            fold.setAttribute('aria-controls', n.kids.id);
            bar.appendChild(fold);
            bar.hidden = true;
            node.insertBefore(bar, n.kids);
            const more = el('button', 'btn btn-secondary btn-small cm-more');
            more.type = 'button';
            more.id = 'cm-more-' + r.id;
            more.hidden = true;
            node.appendChild(more);
            // At no depth (replies off) the replies written before are drawn flat under their comment.
            n.kids.classList.toggle('cm-kids-flat', depth === 0);
            const th = { root: Number(r.id), n, bar, fold, more, last: 0, more_n: 0, folded: folded.has(Number(r.id)), busy: false };
            threads.set(Number(r.id), th);
            fold.addEventListener('click', () => setFold(th, !th.folded));
            more.addEventListener('click', () => loadMore(th));
            const tr = r.thread || {};
            (Array.isArray(tr.rows) ? tr.rows : []).forEach((x) => placeReply(x));
            th.last = Number(tr.last) || 0;
            th.more_n = Math.max(0, Number(tr.more) || 0);
            syncThread(th);
            return node;
        }

        /** One comment's node: its row (or its tombstone) and the box its replies go in. */
        function drawNode(r, level, box, isRoot) {
            const node = el('div', 'cm-node');
            node.id = 'cm-node-' + r.id;
            node.dataset.id = String(r.id);
            const kids = el('div', 'cm-kids');
            kids.id = 'cm-kids-' + r.id;
            const entry = { row: r, node, art: null, kids, box, level, root: isRoot ? Number(r.id) : Number(r.root || 0), isRoot: !!isRoot };
            nodes.set(Number(r.id), entry);
            entry.art = r.tomb ? drawTomb(r) : drawRow(r);
            node.append(entry.art, kids);
            return entry;
        }

        /**
         * A reply, where it belongs: nested one level under what it answers — or, deeper than this site's threads may
         * go now, beside the deepest level on the way (flattened, "in reply to NAME"); in the box, in id order (a reply
         * is always younger than what it answers, so a thread loaded oldest first is appended in order — a tombstone
         * that arrives with a later batch goes in its place).
         */
        function placeReply(x) {
            const id = Number(x.id);
            if (!id || nodes.has(id)) return;
            const rootN = nodes.get(Number(x.root));
            if (!rootN) return;                         // its thread is not on this page
            const p = nodes.get(Number(x.parent)) || rootN;
            const want = Math.min(Number(x.depth) || 1, depth);
            let box, level;
            if (p.level < want) { box = p.kids; level = p.level + 1; }
            else if (p.isRoot) { box = p.kids; level = 0; }
            else { box = p.box; level = p.level; }
            const entry = drawNode(x, level, box, false);
            let before = null;
            for (const c of box.children) {
                if (c.classList.contains('cm-node') && Number(c.dataset.id) > id) { before = c; break; }
            }
            box.insertBefore(entry.node, before);
        }

        /** A comment that went while replies hang from it: its place, and what it says — nothing of whose it was. */
        function drawTomb(r) {
            const art = el('article', 'cm-row cm-tomb');
            art.id = 'comment-' + r.id;
            art.dataset.id = String(r.id);
            art.appendChild(el('span', 'cm-tomb-text', t.key(r.tomb === 'removed' ? 'js.comments.tomb_removed' : 'js.comments.tomb_deleted')));
            return art;
        }

        /** A comment drawn again (corrected, let through): the same node, its row replaced. */
        function swapRow(r) {
            const n = nodes.get(Number(r.id));
            if (!n) return;
            n.row = r;
            const art = r.tomb ? drawTomb(r) : drawRow(r);
            n.art.replaceWith(art);
            n.art = art;
            const th = threads.get(n.root);
            if (th) syncThread(th);
        }

        /** Does anything drawn hang from this comment? */
        const hasKids = (id) => { for (const x of nodes.values()) if (Number(x.row.parent) === id) return true; return false; };

        /**
         * A comment taken down: a tombstone in its place while replies hang from it (`tomb`, the server's word), else
         * gone — and a tombstone above it that has nothing left under it goes with it, up the chain.
         */
        function removeNode(id, tomb) {
            let n = nodes.get(Number(id));
            if (!n) return;
            // A composer open under it has nothing to answer any more.
            const box = rpBoxes.get(Number(id));
            if (box) { box.remove(); rpBoxes.delete(Number(id)); }
            drafts.delete(Number(id));
            if (openRp === Number(id)) openRp = 0;
            if (tomb) {
                swapRow(Object.assign({}, n.row, { tomb, status: 'deleted', can_reply: false, can_edit: false, can_delete: null, can_report: false }));
                return;
            }
            const root = n.root;
            for (;;) {
                const parent = n.isRoot ? 0 : Number(n.row.parent);
                if (openRp === Number(n.row.id)) openRp = 0;
                n.node.remove();
                nodes.delete(Number(n.row.id));
                if (n.isRoot) { threads.delete(Number(n.row.id)); break; }
                const p = nodes.get(parent);
                if (!p || !p.row.tomb || hasKids(parent)) break;
                n = p;
            }
            const th = threads.get(root);
            if (th) syncThread(th);
        }

        /** The thread's fold and its "Show more", as the replies drawn and still to come say. */
        function syncThread(th) {
            if (!th.n.node.isConnected && !th.n.node.parentNode) return;
            let drawn = 0;
            for (const x of nodes.values()) if (!x.isRoot && x.root === th.root && !x.row.tomb) drawn++;
            const any = th.n.kids.querySelector(':scope > .cm-node') !== null;
            const total = drawn + th.more_n;
            th.bar.hidden = !any;
            th.n.kids.hidden = th.folded;
            th.fold.setAttribute('aria-expanded', th.folded ? 'false' : 'true');
            // The icon and its words: the gap between them is the site's buttons' own (a row, style.css), no space.
            th.fold.textContent = '';
            th.fold.append(glyph(th.folded ? 'bi-chevron-down' : 'bi-chevron-up'),
                           th.folded ? (total === 1 ? t.key('js.comments.fold_show_one') : t.key('js.comments.fold_show', { n: total })) : t.key('js.comments.fold_hide'));
            th.more.hidden = th.folded || th.more_n <= 0;
            th.more.textContent = th.more_n === 1 ? t.key('js.comments.more_replies_one') : t.key('js.comments.more_replies', { n: th.more_n });
            th.n.node.classList.toggle('cm-folded', th.folded);
        }

        function setFold(th, on) {
            th.folded = !!on;
            if (th.folded) folded.add(th.root); else folded.delete(th.root);
            syncThread(th);
        }

        /** "Show N more replies": the thread's next batch, each in its place. */
        async function loadMore(th) {
            if (th.busy) return;
            th.busy = true;
            th.more.disabled = true;
            const j = await getJ('comment_list&hash=' + encodeURIComponent(hash) + '&thread=' + th.root + '&after=' + th.last);
            th.busy = false;
            th.more.disabled = false;
            if (!j || !j.success) { tip(th.more, (j && j.message) || t.key('js.comments.load_failed')); return; }
            if (j.reply_depth !== undefined) depth = Math.max(0, Number(j.reply_depth) || 0);
            (Array.isArray(j.rows) ? j.rows : []).forEach((x) => placeReply(x));
            th.last = Math.max(th.last, Number(j.last) || 0);
            th.more_n = Math.max(0, Number(j.more) || 0);
            setCount(j.count);
            syncThread(th);
        }

        /* ── the composer under a comment (1.72.0) ── */
        const rpBoxes = new Map();

        /** The Reply button's press: the composer under that comment — the one open before goes, its words kept. */
        function openReply(r) {
            const id = Number(r.id);
            const n = nodes.get(id);
            if (!n || !me) return;
            if (openRp && openRp !== id) closeReply(openRp, false);
            const th = threads.get(n.root);
            if (th && th.folded && !n.isRoot) setFold(th, false);
            let box = rpBoxes.get(id);
            if (!box || !box.isConnected) box = buildReply(r);
            if (box.parentNode !== n.node || box.previousElementSibling !== n.art) n.art.after(box);
            box.hidden = false;
            box.dataset.escAt = String(Date.now());
            openRp = id;
            const btn = n.art.querySelector('.cm-reply');
            if (btn) btn.setAttribute('aria-expanded', 'true');
            const ta = box.querySelector('textarea');
            if (ta) {
                ta.focus();
                try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) { /* not a text box */ }
            }
        }

        /** Put the composer away (the x, Esc): hidden, its words kept for when Reply is pressed again. */
        function closeReply(id, refocus) {
            const box = rpBoxes.get(Number(id));
            if (box) { box.hidden = true; delete box.dataset.escAt; }
            if (openRp === Number(id)) openRp = 0;
            const n = nodes.get(Number(id));
            const btn = n && n.art ? n.art.querySelector('.cm-reply') : null;
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
                if (refocus && btn.isConnected) btn.focus();
            }
        }

        function buildReply(r) {
            const id = Number(r.id);
            const box = el('div', 'cm-reply-box');
            box.id = 'cm-rp-box-' + id;
            box.dataset.escLayer = '1';
            const head = el('div', 'cm-reply-head');
            head.appendChild(el('span', 'cm-reply-to-label', t.key('js.comments.replying_to', { name: nameOf(r) })));
            const x = iconBtn('btn btn-secondary btn-small cm-reply-x', 'bi-x-lg', t.key('js.comments.reply_cancel'));
            head.appendChild(x);
            // What the foot's composer says before anybody types, where it matters here too: a guest's rules, and a
            // new account's links drawn as text.
            let note = null;
            if (me.guest) note = el('p', 'text-muted cm-as cm-reply-as', t.key(me.review ? 'js.comments.as_guest_review' : 'js.comments.as_guest'));
            else if (me.links && me.links_text) note = el('p', 'form-hint cm-hint cm-reply-as', t.key('js.comments.links_text', { days: me.new_days || '' }));
            const taId = 'cm-rp-' + id;
            const { wrap, ta } = buildEditor(taId, me, t.key(me.mentions ? 'js.comments.reply_placeholder' : 'js.comments.reply_placeholder_plain'));
            ta.rows = 2;
            const foot = el('div', 'cm-reply-foot');
            const send = el('button', 'btn btn-small cm-reply-send', t.key('js.comments.reply_send'));
            send.type = 'button';
            const msg = el('span', 'text-muted cm-reply-msg');
            msg.setAttribute('aria-live', 'polite');
            foot.append(send, msg);
            box.append(head);
            if (note) box.append(note);
            box.append(wrap, foot);
            // In the document before the editor is mounted: mount() finds its parts by id.
            const n = nodes.get(id);
            n.art.after(box);
            rpBoxes.set(id, box);
            ta.value = drafts.get(id) || '';
            mountEditor(taId, me);
            if (ta.value) ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.addEventListener('input', () => { if (ta.value) drafts.set(id, ta.value); else drafts.delete(id); });
            const mentionKey = me.mentions ? mentions(ta, box) : null;
            x.addEventListener('click', () => closeReply(id, true));
            // The Info panel's Esc (app.js escLayer()): this box is a layer of its own inside it, closed first.
            box.addEventListener('esclayer:close', () => closeReply(id, true));
            const doSend = async () => {
                if (send.disabled) return;
                const body = ta.value.trim();
                if (!body) { msg.textContent = t.key('js.comments.empty'); ta.focus(); return; }
                send.disabled = true;
                msg.textContent = t.key('js.comments.sending');
                const data = { csrf_token: csrfToken(sec), hash, body, parent: id };
                // The same door as a comment: the anti-spam layer (a wait counts down on the button), a guest's CAPTCHA
                // every time, asked first.
                const doPost = (extra) => postJ('comment_post', Object.assign({}, data, extra || {}));
                const j = window.Antispam
                    ? await window.Antispam.send(doPost, { button: send, note: (s) => { msg.textContent = s || ''; }, action: 'comment_post',
                                                          solveFirst: !!me.guest && !!me.captcha })
                    : (typeof fetchWithCaptcha === 'function' ? await fetchWithCaptcha('comment_post', data, !!me.guest && !!me.captcha)
                                                              : await doPost({}));
                if (!(window.Antispam && window.Antispam.waiting(send))) send.disabled = false;
                if (!j || !j.success) {
                    if (!(window.Antispam && window.Antispam.waiting(send))) msg.textContent = (j && (j.message || j.error)) || t.key('js.comments.failed');
                    return;
                }
                drafts.delete(id);
                setCount(j.count);
                const btn = nodes.get(id) && nodes.get(id).art.querySelector('.cm-reply');
                box.remove();
                rpBoxes.delete(id);
                if (openRp === id) openRp = 0;
                if (btn) btn.setAttribute('aria-expanded', 'false');
                if (j.comment && !j.pending) {
                    placeReply(j.comment);
                    const pn = nodes.get(id);
                    const th = pn ? threads.get(pn.root) : null;
                    if (th) {
                        if (th.folded) setFold(th, false);
                        // "Show N more replies" goes on from the last reply it fetched: while some are still to come,
                        // the new one (younger than all of them) must not move that cursor past them — it comes again
                        // with the last batch and is not drawn twice.
                        if (th.more_n <= 0) th.last = Math.max(th.last, Number(j.comment.id) || 0);
                        syncThread(th);
                    }
                    const row = document.getElementById('comment-' + j.comment.id);
                    if (row) {
                        row.classList.add('cm-target');
                        try { row.scrollIntoView({ block: 'nearest' }); } catch (e) { /* not scrollable */ }
                        setTimeout(() => row.classList.remove('cm-target'), 2500);
                    }
                } else if (btn) {
                    tip(btn, j.message || '');
                }
            };
            send.addEventListener('click', doSend);
            ta.addEventListener('keydown', (e) => {
                if (mentionKey && mentionKey(e)) return;
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); doSend(); }
            });
            return box;
        }

        /* ── a row ── */
        function drawRow(r) {
            const row = el('article', 'cm-row' + (r.own ? ' cm-own' : '') + (r.status === 'pending' ? ' cm-held' : ''));
            row.id = 'comment-' + r.id;
            row.dataset.id = String(r.id);
            const head = el('div', 'cm-head');
            const who = el(r.profile ? 'a' : 'span', 'cm-who av-who');
            if (r.guest) {
                who.classList.add('cm-guest');
                who.textContent = r.guest_tag ? t.key('js.comments.guest_named', { tag: r.guest_tag }) : t.key('js.comments.guest');
                who.title = t.key('js.comments.guest_title');
            } else if (r.gone || !r.user) {
                who.classList.add('cm-gone');
                who.textContent = t.key('js.comments.deleted_account');
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
                const ed = el('span', 'cm-edited', t.key(r.edited_mod ? 'js.comments.edited_mod' : 'js.comments.edited'));
                if (r.edited_at) ed.title = t.key('js.comments.edited_title', { at: r.edited_at });
                head.appendChild(ed);
            }
            if (r.status === 'pending') head.appendChild(el('span', 'cm-held-badge', t.key('js.comments.held')));
            // A reply drawn beside what it answers rather than under it (deeper than the site's threads may go now,
            // 1.72.0) says whom it answers.
            const self = nodes.get(Number(r.id));
            if (self && !self.isRoot && (Number(r.depth) || 0) > self.level) {
                const p = nodes.get(Number(r.parent));
                const pn = p ? nameOf(p.row) : '';
                head.appendChild(el('span', 'cm-reply-to', pn ? t.key('js.comments.reply_to', { name: pn }) : t.key('js.comments.reply_to_gone')));
            }
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
                fold.appendChild(el('span', 'text-muted', t.key('js.comments.blocked')));
                const show = el('button', 'btn btn-secondary btn-small cm-blocked-show', t.key('js.comments.show'));
                show.type = 'button';
                show.setAttribute('aria-expanded', 'false');
                show.addEventListener('click', () => {
                    const open = text.hidden;
                    text.hidden = !open;
                    show.textContent = t.key(open ? 'js.comments.hide' : 'js.comments.show');
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
            // Reply (1.72.0): first among the actions, where the server says this reader may answer this comment — a
            // visible one, not at the thread's limit. The composer opens under it.
            if (r.can_reply) {
                const b = iconBtn('btn btn-secondary btn-small cm-reply', 'bi-reply', t.key('js.comments.reply'), t.key('js.comments.reply_tip'));
                b.setAttribute('aria-expanded', openRp === Number(r.id) ? 'true' : 'false');
                b.setAttribute('aria-controls', 'cm-rp-box-' + r.id);
                b.addEventListener('click', () => {
                    if (openRp === Number(r.id)) { closeReply(r.id, false); return; }
                    openReply(r);
                });
                acts.appendChild(b);
            }
            if (r.can_approve) {
                const b = iconBtn('btn btn-secondary btn-small cm-approve', 'bi-check-lg', t.key('js.comments.approve'), t.key('js.comments.approve_tip'));
                b.addEventListener('click', async () => {
                    b.disabled = true;
                    const j = await postJ('comment_approve', { csrf_token: csrfToken(sec), id: r.id });
                    if (!j || !j.success) { b.disabled = false; tip(b, (j && j.message) || t.key('js.comments.failed')); return; }
                    if (j.comment) swapRow(j.comment);
                    setCount(j.count);
                    pendingNote.hidden = true;
                });
                acts.appendChild(b);
            }
            if (r.can_edit) {
                const b = iconBtn('btn btn-secondary btn-small cm-edit', 'bi-pencil-square', t.key('js.comments.edit'),
                                  t.key(r.own ? 'js.comments.edit_tip_own' : 'js.comments.edit_tip_any'));
                b.addEventListener('click', () => openEdit(r, row, text, acts));
                acts.appendChild(b);
            }
            if (r.can_delete) {
                const own = r.can_delete === 'own';
                const name = t.key('js.comments.delete');
                const dtip = t.key(own ? 'js.comments.delete_tip_own' : 'js.comments.delete_tip_any');
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
                        b.setAttribute('data-tip', dtip);
                        if (armTip) { armTip.drop(); armTip = null; }
                    };
                    b.addEventListener('click', async () => {
                        if (b.dataset.armed !== '1') {
                            b.dataset.armed = '1';
                            b.classList.add('is-armed');
                            if (icon()) icon().className = 'bi bi-trash-fill';
                            b.setAttribute('aria-label', t.key('js.comments.delete_sure'));
                            b.setAttribute('data-tip', t.key('js.comments.delete_sure'));
                            if (typeof window.pubTip === 'function') armTip = window.pubTip(b, t.key('js.comments.delete_sure'), { hold: true });
                            armTimer = setTimeout(disarm, 4000);
                            return;
                        }
                        disarm();
                        b.disabled = true;
                        const j = await postJ('comment_delete', { csrf_token: csrfToken(sec), id: r.id });
                        if (!j || !j.success) { b.disabled = false; tip(b, (j && j.message) || t.key('js.comments.failed')); return; }
                        removeNode(r.id, j.tomb || '');
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
            const lab = el('label', 'cm-reason-label', t.key('js.comments.reason_label'));
            lab.htmlFor = id;
            const inp = el('input', 'profile-search cm-reason-input');
            inp.type = 'text'; inp.id = id; inp.maxLength = 255; inp.placeholder = t.key('js.comments.reason_ph');
            const go = el('button', 'btn btn-small cm-reason-go', t.key('js.comments.reason_go'));
            go.type = 'button';
            const no = el('button', 'btn btn-secondary btn-small cm-reason-cancel', t.key('js.common.cancel'));
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
                if (!reason) { msg.textContent = t.key('js.comments.reason_needed'); inp.focus(); return; }
                go.disabled = true;
                const j = await postJ('comment_delete', { csrf_token: csrfToken(sec), id: r.id, reason });
                go.disabled = false;
                if (!j || !j.success) { msg.textContent = (j && j.message) || t.key('js.comments.failed'); return; }
                removeNode(r.id, j.tomb || '');
                setCount(j.count);
                syncEmpty();
            });
        }

        /** Correcting a comment in place: the words as stored, the same editor, Save and Cancel. */
        async function openEdit(r, row, text, acts) {
            if (row.querySelector('.cm-edit-box')) return;
            const src = await getJ('comment_edit&id=' + encodeURIComponent(r.id));
            if (!src || !src.success) { tip(acts, (src && src.message) || t.key('js.comments.failed')); return; }
            const id = 'cm-ed-' + r.id;
            // A moderator's correction of somebody else's words keeps THEIR right to link, and may say why.
            const edMe = Object.assign({}, me || {}, { picker: me && me.picker ? me.picker : null });
            const { wrap, ta } = buildEditor(id, edMe, t.key('js.comments.placeholder_edit'));
            const box = el('div', 'cm-edit-box');
            box.appendChild(wrap);
            let reason = null;
            if (!src.own) {
                reason = el('input', 'profile-search cm-edit-reason');
                reason.type = 'text'; reason.maxLength = 255; reason.placeholder = t.key('js.comments.edit_reason_ph');
                reason.setAttribute('aria-label', t.key('js.comments.edit_reason_ph'));
                box.appendChild(reason);
            }
            const foot = el('div', 'cm-edit-foot');
            const save = el('button', 'btn btn-small cm-edit-save', t.key('js.comments.save'));
            save.type = 'button';
            const cancel = el('button', 'btn btn-secondary btn-small cm-edit-cancel', t.key('js.common.cancel'));
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
            // A layer of its own inside the Info panel (1.72.0, app.js escLayer()): Esc puts the correction away first,
            // as Cancel does — it closed the whole panel until now.
            box.dataset.escLayer = '1';
            box.dataset.escAt = String(Date.now());
            box.addEventListener('esclayer:close', () => { close(); const e = acts.querySelector('.cm-edit'); if (e) e.focus(); });
            const doSave = async () => {
                if (save.disabled) return;
                const body = ta.value.trim();
                if (!body) { msg.textContent = t.key('js.comments.empty'); return; }
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
                    if (!(window.Antispam && window.Antispam.waiting(save))) msg.textContent = (j && j.message) || t.key('js.comments.failed');
                    return;
                }
                if (j.comment) swapRow(j.comment); else close();
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
                    p.append(t.key('js.comments.why_login'), ' ');
                    const a = el('a', 'cm-sign-in', t.key('js.comments.sign_in'));
                    try { const u = new URL(location.href); u.search = '?action=login'; u.hash = ''; a.href = u.href; } catch (e) { a.href = '?action=login'; }
                    p.appendChild(a);
                } else if (why === 'muted') {
                    p.textContent = t.key('js.comments.why_muted', { until: me.until || '' });
                } else if (why === 'guest_captcha') {
                    p.textContent = t.key('js.comments.why_guest_captcha');
                } else if (why === 'no_permission') {
                    p.textContent = t.key('js.comments.why_no_permission');
                } else {
                    return;
                }
                compose.appendChild(p);
                return;
            }
            const id = 'cm-new';
            const who = el('p', 'text-muted cm-as');
            who.textContent = me.guest ? t.key(me.review ? 'js.comments.as_guest_review' : 'js.comments.as_guest') : t.key('js.comments.as_member', { name: me.name || '' });
            const { wrap, ta } = buildEditor(id, me, t.key(me.mentions ? 'js.comments.placeholder' : 'js.comments.placeholder_plain'));
            const hint = el('p', 'form-hint cm-hint', t.key(me.links ? 'js.comments.hint' : 'js.comments.hint_nolinks'));
            // A new account's links are shown as text (1.71.0, the anti-spam layer): said before anybody types one.
            if (me.links && me.links_text) hint.append(' ', t.key('js.comments.links_text', { days: me.new_days || '' }));
            const foot = el('div', 'cm-compose-foot');
            const send = el('button', 'btn btn-small cm-send', t.key('js.comments.send'));
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
                if (!body) { msg.textContent = t.key('js.comments.empty'); ta.focus(); return; }
                send.disabled = true;
                msg.textContent = t.key('js.comments.sending');
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
                    if (!(window.Antispam && window.Antispam.waiting(send))) msg.textContent = (j && (j.message || j.error)) || t.key('js.comments.failed');
                    return;
                }
                msg.textContent = j.message || '';
                ta.value = '';
                ta.dispatchEvent(new Event('input', { bubbles: true }));
                if (j.comment && !j.pending) {
                    // A new top-level comment is a thread of its own (1.72.0), at the end.
                    list.appendChild(drawThread(j.comment));
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
        // The live language switch needs nothing from here (1.73.0): every word this file draws is a t.key() word that
        // keeps its key (assets/js/i18n.js), so the switch says it again where it stands — an open reply, the words
        // in a composer, a folded thread and the place in the list stay as they are. (It used to ask for the whole
        // list again on every switch and draw it from nothing.)
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
                    if (status) status.textContent = (j && j.message) || t.key('js.comments.failed');
                    return;
                }
                if (j.prefs && typeof j.prefs[cb.dataset.pref] === 'boolean') cb.checked = j.prefs[cb.dataset.pref];
                if (status) {
                    status.textContent = t.key('js.comments.prefs_saved');
                    setTimeout(() => { if (status.textContent === t.words('js.comments.prefs_saved')) status.textContent = ''; }, 2500);
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
