(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A || !document.getElementById('mod-view-review')) return;
    const { apiCall, el, showToast, confirmAction, promptModal, promptPassword, renderPagination, fmtDate } = A;
    const $ = (id) => document.getElementById(id);
    const icon = (cls) => el('i', { className: 'bi ' + cls, 'aria-hidden': 'true' });
    const num = (n) => (typeof t.num === 'function' ? t.num(n) : String(n));
    const when = (s) => (s ? fmtDate(String(s).replace(' ', 'T')) : '');
    const FORMATS = { bbcode: 'BBCode', markdown: 'Markdown', flarum: 'Flarum' };
    const STATE = {
        pending: ['bi-hourglass-split', 'rq-st-wait', 'js.wl.rv_waiting'],
        approved: ['bi-check-circle', 'rq-st-ok', 'js.wl.rv_published'],
        rejected: ['bi-x-circle', 'rq-st-no', 'js.wl.rv_rejected'],
        none: ['bi-dash-circle', 'rq-st-none', 'js.wl.rv_no_text'],
    };
    const FORUM = {
        drift: 'js.wl.forum_state_drift',
        too_long: 'js.wl.forum_state_too_long',
        invalid: 'js.wl.forum_state_invalid',
        removed: 'js.wl.forum_state_removed',
        declined: 'js.wl.forum_state_declined',
        withdrawn: 'js.wl.forum_state_withdrawn',
    };
    const DEFAULTS = { status: 'pending', source: '', format: '', author: '', search: '', sort: 'oldest', per: 25 };
    const S = {
        f: Object.assign({}, DEFAULTS), page: 1, rows: [], total: 0, pages: 1, mayPunish: false, sub: 'queue', pub: false, seq: 0, eseq: 0,
        sel: new Map(), open: new Set(), html: new Map(), busy: false,
    };
    const keyOf = (r) => r.kind + ':' + r.id;
    const pick = (r) => ({ kind: r.kind, id: r.id, sha: r.sha });

    function iconBtn(ic, cls, label, fn) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls + ' rq-ic', title: label, 'aria-label': label }, icon(ic));
        b.addEventListener('click', fn);
        return b;
    }
    function textBtn(ic, cls, label, fn) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls }, [icon(ic), ' ', label]);
        b.addEventListener('click', fn);
        return b;
    }
    function face(name, avatar) {
        return typeof window.userAvatarImg === 'function' && avatar
            ? window.userAvatarImg({ username: name, avatar: String(avatar) }, 18, 'avatar rq-av') : null;
    }
    function errText(j) {
        if (j && j.message) return j.message;
        const code = j && j.error ? String(j.error) : '';
        return t.has('js.moderation.err_' + code) ? t.key('js.moderation.err_' + code) : (code || t.key('js.wl.failed'));
    }

    function paintCounts(r) {
        const waiting = (r.waiting || 0) + (r.edits_pending || 0);
        const tab = $('mod-review-badge');
        if (tab) tab.textContent = waiting ? String(waiting) : '';
        const nav = $('nav-review-badge');
        if (nav) {
            nav.textContent = waiting ? String(waiting) : '';
            nav.classList.toggle('d-hidden', !waiting);
        }
        if (r.waiting !== undefined) $('rq-q-count').textContent = r.waiting ? String(r.waiting) : '';
        if (r.edits_pending !== undefined) $('rq-e-count').textContent = r.edits_pending ? String(r.edits_pending) : '';
    }

    function filtered() {
        return S.f.search !== '' || S.f.author !== '' || S.f.source !== '' || S.f.format !== '';
    }

    async function load() {
        const seq = ++S.seq;
        const list = $('rq-list');
        list.classList.add('rq-loading');
        if (!S.rows.length) list.replaceChildren(el('div', { className: 'wl-status-loading', text: t.key('js.common.loading') }));
        const r = await apiCall('admin/wl_content', 'POST', Object.assign({ op: 'list', page: S.page }, S.f));
        if (seq !== S.seq) return;
        list.classList.remove('rq-loading');
        if (!r || !r.success) {
            list.replaceChildren(el('div', { className: 'nl-note nl-note-bad', text: (r && r.error) || t.key('js.wl.review_load_failed') }));
            return;
        }
        S.rows = r.rows || [];
        S.total = r.total || 0;
        S.pages = r.pages || 1;
        S.page = r.page || 1;
        S.mayPunish = !!r.may_punish;
        S.repMode = r.rep_mode === 'stars' ? 'stars' : 'thumbs';
        paintCounts(r);
        $('rq-total').textContent = S.total
            ? t.key(S.f.status === 'pending' ? 'js.wl.n_waiting' : 'js.wl.n_shown', { n: num(S.total) }) : '';
        list.replaceChildren();
        if (!S.rows.length) {
            const title = filtered() ? t.key('js.common.no_results')
                : (S.f.status === 'forum' ? t.key('js.review.empty_forum')
                    : (S.f.status === 'pending' ? t.key('js.common.nothing_waiting') : t.key('js.common.nothing_here')));
            list.appendChild(A.emptyState(title, filtered() ? 'bi-search' : 'bi-inbox', ''));
        } else {
            S.rows.forEach(row => list.appendChild(rowEl(row)));
        }
        renderPagination($('rq-pagination'), {
            total: S.total, page: S.page, pages: S.pages,
            onPage: (p) => { S.page = p; load().then(() => $('mod-view-review').scrollIntoView({ block: 'start' })); },
        });
        paintBulk();
    }

    function authorOn(name) {
        return S.f.author !== '' && S.f.author.toLowerCase() === String(name).toLowerCase();
    }
    function authorChip(r) {
        if (r.author) {
            const on = authorOn(r.author);
            const b = el('button', { type: 'button', className: 'rq-author' + (on ? ' is-on' : ''), title: t.key(on ? 'js.review.author_all' : 'js.review.author_only'), 'aria-pressed': on ? 'true' : 'false' },
                [face(r.author, r.author_avatar), el('span', { text: r.author })]);
            b.addEventListener('click', () => setAuthor(r.author));
            if (r.author_hidden) b.appendChild(el('span', { className: 'wl-badge wl-b-muted', title: t.key('js.wl.name_hidden_title'), text: t.key('js.wl.name_hidden') }));
            return b;
        }
        if (r.forum_author) {
            const on = authorOn(r.forum_author);
            const b = el('button', { type: 'button', className: 'rq-author rq-author-forum' + (on ? ' is-on' : ''), title: t.key(on ? 'js.review.author_all' : 'js.review.author_only'), 'aria-pressed': on ? 'true' : 'false' },
                [icon('bi-chat-left-text'), el('span', { text: t.key('js.review.forum_author', { user: r.forum_author }) })]);
            b.addEventListener('click', () => setAuthor(r.forum_author));
            return b;
        }
        return el('span', { className: 'rq-author text-muted', text: '—' });
    }

    function sourceChip(r) {
        if (r.from_forum) {
            const parts = [icon('bi-chat-square-text'), ' ', t.key('js.wl.from_forum')];
            if (!r.forum_url) return el('span', { className: 'rq-src rq-src-forum' }, parts);
            return el('a', { href: r.forum_url, target: '_blank', rel: 'noopener noreferrer', className: 'rq-src rq-src-forum', title: t.key('js.wl.forum_topic') },
                parts.concat([' ', icon('bi-box-arrow-up-right rq-ext')]));
        }
        if (r.kind === 'idx') return el('span', { className: 'rq-src rq-src-idx', title: t.key('js.wl.rv_index_only_title') }, [icon('bi-collection'), ' ', t.key('js.wl.rv_index_only')]);
        return el('span', { className: 'rq-src' }, [icon('bi-pencil'), ' ', t.key('js.review.written_here')]);
    }

    function fact(ic, title, value) {
        return el('span', { className: 'rq-fact', title }, [icon(ic), ' ', value]);
    }

    function rowEl(r) {
        const k = keyOf(r);
        const open = S.open.has(k);
        const st = STATE[r.content_status] || STATE.pending;
        const row = el('article', { className: 'rq-row ' + st[1] + (open ? ' rq-open' : '') });
        row.dataset.key = k;
        const cb = el('input', { type: 'checkbox', className: 'form-check-input rq-cb', 'aria-label': r.name || r.info_hash });
        cb.checked = S.sel.has(k);
        cb.addEventListener('change', () => {
            if (cb.checked) S.sel.set(k, pick(r)); else S.sel.delete(k);
            paintBulk();
        });
        const toggle = el('button', { type: 'button', className: 'rq-toggle', 'aria-expanded': open ? 'true' : 'false',
            title: t.key(open ? 'js.review.hide_text' : 'js.review.open_text') },
            [icon('bi-chevron-right rq-chev'), el('span', { className: 'rq-name', text: r.name || t.key('js.wl.no_name_yet') })]);
        toggle.addEventListener('click', () => {
            if (S.open.has(k)) S.open.delete(k); else S.open.add(k);
            row.replaceWith(rowEl(r));
        });
        const stateEl = el('span', { className: 'rq-state', title: t.key('js.wl.rv_state_title', { state: t.key(st[2]) }) }, [icon(st[0]), ' ', t.key(st[2])]);
        const meta = el('div', { className: 'rq-meta' }, [
            authorChip(r),
            sourceChip(r),
            el('span', { className: 'rq-fmt', text: FORMATS[r.description_format] || r.description_format || '' }),
            fact('bi-text-paragraph', t.key('js.review.chars'), num(r.chars)),
            r.pics ? fact('bi-image', t.key('js.review.pics'), num(r.pics)) : null,
            r.vids ? fact('bi-youtube', t.key('js.review.vids'), num(r.vids)) : null,
            fact('bi-clock', t.key('js.review.sent'), when(r.created_at)),
            el('code', { className: 'rq-hash', title: r.info_hash, text: String(r.info_hash).slice(0, 12) }),
        ]);
        if (r.votes_count) {
            const stars = S.repMode === 'stars';
            meta.appendChild(el('span', { className: 'wl-badge ' + (r.score_x100 >= (stars ? 250 : 5000) ? 'wl-b-ok' : 'wl-b-warn') }, stars
                ? [icon('bi-star-fill'), ' ', t.key(r.votes_count === 1 ? 'js.wl.rating_stars_one' : 'js.wl.rating_stars_many', { stars: num(Math.round(r.score_x100 / 10) / 10), n: r.votes_count })]
                : [t.key(r.votes_count === 1 ? 'js.wl.rating_one' : 'js.wl.rating_many', { pct: Math.round(r.score_x100 / 100), n: r.votes_count })]));
        }
        const acts = el('div', { className: 'rq-acts' });
        if (r.content_status !== 'approved' && r.content_status !== 'none') acts.appendChild(iconBtn('bi-check-lg', 'btn-outline-success', t.key('js.wl.publish'), () => decide(r, 'approve')));
        if (r.content_status !== 'rejected' && r.content_status !== 'none') acts.appendChild(iconBtn('bi-x-lg', 'btn-outline-warning', t.key('js.wl.reject'), () => rejectOne(r)));
        row.appendChild(el('div', { className: 'rq-head' }, [cb, el('div', { className: 'rq-main' }, [el('div', { className: 'rq-line' }, [toggle, stateEl]), meta]), acts]));
        if (open) row.appendChild(detailEl(r));
        return row;
    }

    function fillDesc(desc, html) {
        desc.innerHTML = html;
        if (!desc.childNodes.length) desc.appendChild(el('p', { className: 'text-muted', text: t.key('js.wl.nothing_paren') }));
    }

    function detailEl(r) {
        const k = keyOf(r);
        const box = el('div', { className: 'rq-detail' });
        const facts = el('div', { className: 'rq-dfacts' });
        if (r.source_url) {
            facts.appendChild(el('div', { className: 'rv-src' }, [
                el('span', { className: 'rv-label', text: t.key('js.wl.source_link') }),
                el('code', { className: 'rv-url', text: r.source_url }),
                el('span', { className: 'wl-badge ' + (r.source_trusted ? 'wl-b-ok' : 'wl-b-warn'), text: t.key(r.source_trusted ? 'js.wl.trusted_domain' : 'js.wl.off_site') }),
            ]));
        }
        if (r.content_status === 'rejected' && r.content_rejected_note) {
            facts.appendChild(el('div', { className: 'rv-note' }, [el('strong', { text: t.key('js.wl.rejected_label') }), ' ', r.content_rejected_note]));
        }
        if (FORUM[r.forum_state]) {
            const [chars, max] = String(r.forum_note || '').split('/');
            facts.appendChild(el('div', { className: 'rv-note' }, [el('strong', { text: t.key('js.wl.forum_label') }), ' ',
                t.key(FORUM[r.forum_state], { note: r.forum_note || '', at: when(r.forum_at), chars: num(Number(chars) || 0), max: num(Number(max) || 0) })]));
        }
        if (facts.childNodes.length) box.appendChild(facts);
        const desc = el('div', { className: 'rq-desc rt-body' });
        box.appendChild(desc);
        const cached = S.html.get(k + ':' + r.sha);
        if (cached !== undefined) {
            fillDesc(desc, cached);
        } else {
            desc.appendChild(el('div', { className: 'wl-status-loading', text: t.key('js.common.loading') }));
            apiCall('admin/wl_content', 'POST', { op: 'item', id: r.id, kind: r.kind }).then(j => {
                if (!j || !j.success) {
                    desc.replaceChildren(el('div', { className: 'nl-note nl-note-bad', text: (j && j.error) || t.key('js.wl.failed') }));
                    return;
                }
                if (j.sha && j.sha !== r.sha) {
                    r.sha = j.sha;
                    if (S.sel.has(k)) S.sel.set(k, pick(r));
                }
                S.html.set(k + ':' + r.sha, j.description_html || '');
                fillDesc(desc, j.description_html || '');
            });
        }
        const foot = el('div', { className: 'rq-dacts' });
        if (r.content_status !== 'approved' && r.content_status !== 'none') foot.appendChild(textBtn('bi-check-lg', 'btn-outline-success', t.key('js.wl.publish'), () => decide(r, 'approve')));
        if (r.content_status !== 'rejected' && r.content_status !== 'none') foot.appendChild(textBtn('bi-x-lg', 'btn-outline-warning', t.key('js.wl.reject'), () => rejectOne(r)));
        if (r.content_status !== 'none') foot.appendChild(textBtn('bi-trash', 'btn-outline-danger', t.key('js.wl.delete_text'), () => clearOne(r)));
        if (S.mayPunish && r.author_id) {
            foot.appendChild(el('span', { className: 'rq-dsep' }));
            foot.appendChild(textBtn('bi-exclamation-triangle', 'btn-outline-warning', t.key('js.review.warn'), () => punish(r, 'warn')));
            foot.appendChild(textBtn('bi-mic-mute', 'btn-outline-warning', t.key('js.review.mute'), () => punish(r, 'mute')));
            foot.appendChild(textBtn('bi-slash-circle', 'btn-outline-danger', t.key('js.review.ban'), () => punish(r, 'ban')));
        } else if (S.mayPunish && !r.author_id && r.forum_author) {
            foot.appendChild(el('span', { className: 'rq-dsep' }));
            foot.appendChild(el('span', { className: 'text-muted wl-small', text: t.key('js.review.no_account') }));
        }
        if (r.kind === 'wl') {
            foot.appendChild(el('a', { className: 'btn btn-sm btn-outline-secondary rq-site', href: (document.body.dataset.searchUrl || '?action=search&hash=') + r.info_hash,
                target: '_blank', rel: 'noopener' }, [icon('bi-box-arrow-up-right'), ' ', t.key('js.review.open_site')]));
        }
        box.appendChild(foot);
        return box;
    }

    function setAuthor(name) {
        const off = authorOn(name);
        $('rq-author').value = off ? '' : name;
        S.f.author = off ? '' : name;
        S.page = 1;
        S.sel.clear();
        load().then(() => showToast(off ? t.key('js.review.author_every') : t.key('js.review.author_shown', { user: name, n: num(S.total) }), 'info'));
    }

    async function decide(r, op, note) {
        const body = { op, id: r.id, kind: r.kind, sha: r.sha };
        if (op === 'reject') body.note = note || '';
        const j = await apiCall('admin/wl_content', 'POST', body);
        if (j && j.changed) {
            showToast(t.key('js.review.changed'), 'warning');
            S.html.delete(keyOf(r) + ':' + r.sha);
            return load();
        }
        showToast((j && (j.message || j.error)) || t.key('js.wl.failed'), j && j.success ? 'success' : 'error');
        if (j && j.success) {
            S.sel.delete(keyOf(r));
            S.open.delete(keyOf(r));
            return load();
        }
        return null;
    }

    async function rejectOne(r) {
        const note = await promptModal({ title: t.key('js.wl.reject_text_title'), label: t.key('js.wl.reject_why_label'),
            placeholder: t.key('js.wl.reject_why_placeholder'), maxlength: 255, okLabel: t.key('js.wl.reject') });
        if (note === null) return;
        decide(r, 'reject', note);
    }

    async function clearOne(r) {
        if (!await confirmAction(t.key('js.wl.delete_text_title_2'), t.key('js.wl.delete_text_body'),
            { code: r.info_hash, after: t.key('js.wl.delete_text_after'), okLabel: t.key('js.wl.delete'), danger: true })) return;
        const pw = await promptPassword(t.key('js.wl.delete_text_title_2'), t.key('js.wl.confirm_admin_password'));
        if (!pw) return;
        const j = await apiCall('admin/wl_content', 'POST', { op: 'clear', id: r.id, kind: r.kind, password: pw });
        showToast((j && (j.message || j.error)) || t.key('js.wl.failed'), j && j.success ? 'success' : 'error');
        if (j && j.success) {
            S.sel.delete(keyOf(r));
            S.open.delete(keyOf(r));
            load();
        }
    }

    function paintBulk() {
        const n = S.sel.size;
        $('rq-selected').textContent = n ? t.key('js.review.selected', { n: num(n) }) : '';
        $('rq-approve-sel').disabled = !n || S.busy;
        $('rq-reject-sel').disabled = !n || S.busy;
        $('rq-delete-sel').disabled = !n || S.busy;
        $('rq-clear-sel').hidden = !n;
        const more = $('rq-select-matching');
        more.hidden = S.busy || S.total <= S.rows.length || n >= Math.min(S.total, 2000);
        more.textContent = t.key('js.review.select_matching', { n: num(Math.min(S.total, 2000)) });
        const onPage = S.rows.filter(r => S.sel.has(keyOf(r))).length;
        const all = $('rq-page-all');
        all.checked = S.rows.length > 0 && onPage === S.rows.length;
        all.indeterminate = onPage > 0 && onPage < S.rows.length;
        document.querySelectorAll('#rq-list .rq-row').forEach(row => {
            const cb = row.querySelector('.rq-cb');
            if (cb) cb.checked = S.sel.has(row.dataset.key);
        });
    }

    async function selectMatching() {
        const j = await apiCall('admin/wl_content', 'POST', Object.assign({ op: 'ids' }, S.f));
        if (!j || !j.success) {
            showToast((j && j.error) || t.key('js.wl.failed'), 'error');
            return;
        }
        (j.items || []).forEach(it => S.sel.set(it.kind + ':' + it.id, it));
        paintBulk();
    }

    async function runBulk(action, note, password) {
        const items = [...S.sel.values()];
        if (!items.length) return;
        S.busy = true;
        paintBulk();
        let done = 0, changed = 0, failed = 0;
        for (let i = 0; i < items.length; i += 100) {
            $('rq-progress').textContent = t.key('js.review.working', { done: num(i), n: num(items.length) });
            const chunk = items.slice(i, i + 100);
            const j = await apiCall('admin/wl_content', 'POST', Object.assign({ op: 'bulk', action, note: note || '', items: chunk }, password ? { password } : {}));
            if (!j || !j.success) {
                failed += chunk.length;
                continue;
            }
            done += j.done || 0;
            changed += j.changed || 0;
            failed += j.failed || 0;
        }
        S.busy = false;
        $('rq-progress').textContent = '';
        S.sel.clear();
        const parts = [String(t.key('js.review.done_ok', { n: num(done) }))];
        if (changed) parts.push(String(t.key('js.review.done_changed', { n: num(changed) })));
        if (failed) parts.push(String(t.key('js.review.done_failed', { n: num(failed) })));
        showToast(parts.join(' '), changed || failed ? 'warning' : 'success');
        S.page = 1;
        load();
    }

    let pnRow = null;
    let pnField = null;
    function paintPunish() {
        const warn = $('rq-pn-action').value === 'warn';
        $('rq-pn-days-wrap').hidden = warn;
        $('rq-pn-tell-wrap').hidden = warn;
    }
    function punish(r, action) {
        pnRow = r;
        if (!pnField) {
            pnField = A.adminRichField({ compact: true, rows: 3, maxLength: 2000, countMax: 2000,
                placeholder: t.key('js.review.reason_needed'), ariaLabel: t.key('js.review.reason_needed') });
            $('rq-pn-rich').appendChild(pnField.el);
        } else {
            pnField.reset();
        }
        $('rq-pn-name').textContent = r.author || '';
        $('rq-pn-action').value = action;
        $('rq-pn-reject').checked = r.content_status === 'pending';
        $('rq-pn-reject-wrap').hidden = r.content_status === 'rejected' || r.content_status === 'none';
        $('rq-pn-alert').textContent = '';
        paintPunish();
        bootstrap.Modal.getOrCreateInstance($('rqPunishModal')).show();
    }
    async function punishGo() {
        const r = pnRow;
        if (!r) return;
        const action = $('rq-pn-action').value;
        const loud = action === 'warn' || $('rq-pn-tell').checked;
        const reason = pnField.getValue().trim();
        const say = (text) => $('rq-pn-alert').replaceChildren(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text }));
        if (loud && reason === '') {
            say(t.key('js.review.reason_needed'));
            pnField.focus();
            return;
        }
        const go = $('rq-pn-go');
        go.disabled = true;
        const j = await apiCall('admin/moderation_action', 'POST', {
            op: 'apply', user_id: r.author_id, action, loud, reason, reason_format: pnField.getFormat(),
            days: Math.max(0, parseInt($('rq-pn-days').value, 10) || 0),
            src: { kind: 'description', id: r.id, hash: r.info_hash },
        });
        go.disabled = false;
        if (!j || !j.success) {
            say(errText(j));
            return;
        }
        bootstrap.Modal.getOrCreateInstance($('rqPunishModal')).hide();
        showToast(t.key('js.review.acted_' + action), 'success');
        if (!$('rq-pn-reject-wrap').hidden && $('rq-pn-reject').checked) await decide(r, 'reject', reason.slice(0, 255));
    }

    function rvBy(row) {
        const span = el('span', { className: 'wl-small text-muted' });
        if (typeof window.userAvatarPhrase !== 'function') span.textContent = t.key('js.wl.rv_by', { user: row.author });
        else {
            const pic = window.userAvatarImg({ username: row.author, avatar: String(row.author_avatar || '') }, 20, 'avatar rv-av');
            span.appendChild(window.userAvatarPhrase(t.key('js.wl.rv_by', { user: window.userAvatarSlot(0) }), [[pic, row.author]]));
        }
        if (row.author_hidden) {
            span.appendChild(document.createTextNode(' '));
            span.appendChild(el('span', { className: 'wl-badge wl-b-muted rv-name-hidden', title: t.key('js.wl.name_hidden_title'), text: t.key('js.wl.name_hidden') }));
        }
        return span;
    }

    function rvForum(row) {
        const span = el('span', { className: 'wl-small rv-forum' }, [el('span', { className: 'wl-badge wl-b-muted', text: t.key('js.wl.from_forum') })]);
        if (!row.author && row.forum_author) {
            span.appendChild(document.createTextNode(' '));
            span.appendChild(el('span', { className: 'text-muted', text: t.key('js.wl.forum_by', { user: row.forum_author }) }));
        }
        if (row.forum_url) {
            span.appendChild(document.createTextNode(' '));
            span.appendChild(el('a', { href: row.forum_url, target: '_blank', rel: 'noopener noreferrer', text: t.key('js.wl.forum_topic') }));
        }
        return span;
    }

    async function loadEdits() {
        const seq = ++S.eseq;
        const box = $('rq-edits-list');
        box.replaceChildren(el('div', { className: 'wl-status-loading', text: t.key('js.common.loading') }));
        const r = await apiCall('admin/wl_content', 'POST', { op: 'edits' });
        if (seq !== S.eseq) return;
        box.textContent = '';
        if (!r || !r.success) {
            box.appendChild(el('div', { className: 'nl-note nl-note-bad', text: (r && r.error) || t.key('js.wl.edits_load_failed') }));
            return;
        }
        $('rq-edits-total').textContent = r.total ? t.key('js.wl.n_waiting', { n: num(r.total) }) : '';
        $('rq-e-count').textContent = r.total ? String(r.total) : '';
        if (!(r.rows || []).length) {
            box.appendChild(A.emptyState(t.key('js.wl.no_rewrites'), 'bi-inbox', t.key('js.wl.no_rewrites_hint')));
            return;
        }
        r.rows.forEach(row => box.appendChild(editCard(row)));
    }

    function editCard(row) {
        const removal = row.note === 'removal of a picture its owner deleted';
        const isEdit = removal || (row.edit_kind === 'edit' && !row.as_rewrite);
        const card = el('div', { className: 'rv-card rv-st-wait' + (isEdit ? ' rv-kind-edit' : ' rv-kind-rewrite') });
        card.appendChild(el('div', { className: 'rv-head' }, [
            el('div', { className: 'rv-title' }, [
                isEdit
                    ? el('span', { className: 'rv-state rv-state-edit', title: t.key('js.wl.edit_kind_title') }, [icon('bi-pencil'), ' ', t.key('js.wl.edit_kind')])
                    : el('span', { className: 'rv-state', title: t.key('js.wl.rewrite_title') }, [icon('bi-pencil-square'), ' ', t.key('js.wl.rewrite')]),
                el('strong', { className: 'rv-name', text: row.name || t.key('js.wl.no_name_yet') }),
            ]),
            el('div', { className: 'rv-meta' }, [
                el('code', { className: 'rv-hash', text: row.info_hash }),
                el('span', { className: 'wl-small text-muted', text: when(row.created_at) }),
                row.ip && !row.forum_url ? el('span', { className: 'wl-small text-muted', text: t.key('js.wl.from_ip', { ip: row.ip }) }) : '',
                row.author ? rvBy(row) : '',
                row.forum_url || row.forum_author ? rvForum(row) : '',
                row.kind === 'idx' ? el('span', { className: 'wl-badge wl-b-warn', text: t.key('js.wl.rv_index_only') }) : '',
            ]),
        ]));
        if (removal) {
            card.appendChild(el('div', { className: 'rv-note' }, [icon('bi-image'), ' ', t.key('js.review.removal')]));
        }
        card.appendChild(el('div', { className: 'rv-kind-line wl-small' }, [
            isEdit
                ? el('span', { className: 'rv-share', text: t.key('js.wl.edit_share', { pct: row.share }) })
                : el('span', { className: 'text-muted', text: row.edit_kind === 'edit' ? t.key('js.wl.edit_as_rewrite') : t.key('js.wl.rewrite_line') }),
            isEdit && row.cur_author ? el('span', { className: 'text-muted' }, [' ', t.key(removal ? 'js.review.removal_author_stays' : 'js.wl.edit_author_stays', { user: row.cur_author })]) : '',
        ]));
        const cmp = el('div', { className: 'rv-diff' });
        const side = (label, html, url, trusted) => {
            const col = el('div', { className: 'rv-diff-col' });
            col.appendChild(el('div', { className: 'rv-label', text: label }));
            if (url) {
                col.appendChild(el('div', { className: 'rv-src' }, [
                    el('code', { className: 'rv-url', text: url }),
                    trusted === null ? '' : el('span', { className: 'wl-badge ' + (trusted ? 'wl-b-ok' : 'wl-b-warn'),
                        text: trusted ? t.key('js.wl.trusted_domain') : t.key('js.wl.off_site') }),
                ]));
            }
            const d = el('div', { className: 'rv-desc rq-desc rt-body' });
            if (html) d.innerHTML = html;
            else d.appendChild(el('p', { className: 'text-muted', text: t.key('js.wl.nothing_paren') }));
            col.appendChild(d);
            return col;
        };
        cmp.appendChild(side(t.key('js.wl.published_now'), row.cur_html, row.cur_source_url, null));
        cmp.appendChild(side(t.key('js.wl.proposed'), row.new_html, row.source_url, !!row.new_trusted));
        card.appendChild(cmp);
        const acts = el('div', { className: 'rv-acts' });
        acts.appendChild(textBtn('bi-check-lg', 'btn-outline-success', t.key('js.wl.apply'), async () => {
            if (!await confirmAction(isEdit ? t.key('js.wl.apply_edit_title') : t.key('js.wl.apply_rewrite_title'),
                isEdit ? t.key(removal ? 'js.review.removal_apply_body' : 'js.wl.apply_edit_body', { pct: row.share }) : t.key('js.wl.apply_rewrite_body'),
                { code: row.info_hash, after: t.key('js.wl.apply_rewrite_after'), okLabel: t.key('js.wl.apply') })) return;
            const j = await apiCall('admin/wl_content', 'POST', { op: 'edit_apply', id: row.id, sha: row.sha });
            if (j && j.changed) {
                showToast(t.key('js.review.changed'), 'warning');
                loadEdits();
                return;
            }
            showToast((j && (j.message || j.error)) || t.key('js.wl.failed'), j && j.success ? 'success' : 'error');
            if (j && j.success) loadEdits();
        }));
        acts.appendChild(textBtn('bi-x-lg', 'btn-outline-warning', t.key('js.wl.reject'), async () => {
            const j = await apiCall('admin/wl_content', 'POST', { op: 'edit_reject', id: row.id });
            showToast((j && (j.message || j.error)) || t.key('js.wl.failed'), j && j.success ? 'success' : 'error');
            if (j && j.success) loadEdits();
        }));
        card.appendChild(acts);
        return card;
    }

    function showSub(sub) {
        const pane = sub === 'edits' ? 'edits' : 'queue';
        if (pane === 'queue' && (sub === 'published') !== S.pub) {
            const published = sub === 'published';
            S.pub = published;
            S.f.status = published ? 'approved' : DEFAULTS.status;
            S.f.sort = published ? 'newest' : DEFAULTS.sort;
            $('rq-status').value = S.f.status;
            $('rq-sort').value = S.f.sort;
            S.page = 1;
            S.sel.clear();
        }
        S.sub = sub;
        document.querySelectorAll('#rq-subtabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.sub === sub));
        $('rq-pane-queue').classList.toggle('d-hidden', pane !== 'queue');
        $('rq-pane-edits').classList.toggle('d-hidden', pane !== 'edits');
        if (pane === 'edits') loadEdits(); else load();
    }

    function refilter() {
        S.page = 1;
        S.sel.clear();
        load();
    }

    function openPictures(e) {
        const img = e.target.closest('.rq-desc img');
        if (!img || !window.SiteLightbox) return;
        e.preventDefault();
        const imgs = [...img.closest('.rq-desc').querySelectorAll('img')];
        const list = imgs.map(i => {
            const full = i.getAttribute('data-full') || i.getAttribute('src') || '';
            return { src: full, href: full, alt: i.getAttribute('alt') || '' };
        });
        window.SiteLightbox.get().show(list, Math.max(0, imgs.indexOf(img)), img);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const soon = A.debounce(refilter, (A.DEBOUNCE && A.DEBOUNCE.search) || 400);
        $('rq-search').addEventListener('input', () => { S.f.search = $('rq-search').value.trim(); soon(); });
        $('rq-author').addEventListener('input', () => { S.f.author = $('rq-author').value.trim(); soon(); });
        A.bindSearchClear($('rq-search'), $('rq-search-clear'), () => $('rq-search').dispatchEvent(new Event('input')));
        A.bindSearchClear($('rq-author'), $('rq-author-clear'), () => $('rq-author').dispatchEvent(new Event('input')));
        [['rq-status', 'status'], ['rq-source', 'source'], ['rq-format', 'format'], ['rq-sort', 'sort'], ['rq-per', 'per']].forEach(([id, k]) => {
            $(id).addEventListener('change', () => {
                S.f[k] = k === 'per' ? (parseInt($(id).value, 10) || 25) : $(id).value;
                refilter();
            });
        });
        $('rq-clear').addEventListener('click', () => {
            S.f = Object.assign({}, DEFAULTS);
            $('rq-search').value = '';
            $('rq-author').value = '';
            $('rq-search-clear').classList.remove('visible');
            $('rq-author-clear').classList.remove('visible');
            $('rq-status').value = DEFAULTS.status;
            $('rq-source').value = '';
            $('rq-format').value = '';
            $('rq-sort').value = DEFAULTS.sort;
            $('rq-per').value = String(DEFAULTS.per);
            refilter();
        });
        $('rq-page-all').addEventListener('change', () => {
            const on = $('rq-page-all').checked;
            S.rows.forEach(r => { if (on) S.sel.set(keyOf(r), pick(r)); else S.sel.delete(keyOf(r)); });
            paintBulk();
        });
        $('rq-select-matching').addEventListener('click', selectMatching);
        $('rq-clear-sel').addEventListener('click', () => { S.sel.clear(); paintBulk(); });
        $('rq-approve-sel').addEventListener('click', async () => {
            if (!await confirmAction(t.key('js.review.approve_title', { n: num(S.sel.size) }), t.key('js.review.approve_body'),
                { okLabel: t.key('js.wl.publish') })) return;
            runBulk('approve');
        });
        $('rq-reject-sel').addEventListener('click', async () => {
            const note = await promptModal({ title: t.key('js.review.reject_title', { n: num(S.sel.size) }), label: t.key('js.wl.reject_why_label'),
                placeholder: t.key('js.wl.reject_why_placeholder'), maxlength: 255, okLabel: t.key('js.wl.reject') });
            if (note === null) return;
            runBulk('reject', note);
        });
        $('rq-delete-sel').addEventListener('click', async () => {
            if (!await confirmAction(t.key('js.review.delete_title', { n: num(S.sel.size) }), t.key('js.review.delete_body'),
                { okLabel: t.key('js.wl.delete'), danger: true })) return;
            const pw = await promptPassword(t.key('js.review.delete_title', { n: num(S.sel.size) }), t.key('js.wl.confirm_admin_password'));
            if (!pw) return;
            runBulk('clear', '', pw);
        });
        document.querySelectorAll('#rq-subtabs .source-tab').forEach(b => b.addEventListener('click', () => showSub(b.dataset.sub)));
        $('rq-list').addEventListener('click', openPictures);
        $('rq-edits-list').addEventListener('click', openPictures);
        $('rq-pn-action').addEventListener('change', paintPunish);
        $('rq-pn-go').addEventListener('click', punishGo);
    });

    window.ModReview = { show: () => showSub(S.sub) };
})();
