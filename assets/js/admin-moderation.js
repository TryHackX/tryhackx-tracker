/* Admin — the Moderation page (?action=admin-moderation, 1.75.0, includes/moderation.php).
   Three views of what has been done to members and what can still be undone:
     Restricted now        every member silenced or banned this moment — lift it, with a note the member may be told;
     Warnings              the whole log (warnings, removed words, silences, bans, the silent ones, the automatic penalties),
                           keyset-paged and filtered — revoke a warning, optionally lifting what it left running;
     Automatic penalties   the ladder of rules (admin/moderation_rules), its switch, and the log of what it did.
   Uses window.AdminCommon. All dynamic DOM is built with el()/textContent — names and reasons are user-controlled; the only
   innerHTML is the server's own rendering of a moderator's BBCode / Markdown (reportTextHtml(): the site's renderer on
   escaped input), the markup every report card is handed. */
(function () {
    'use strict';
    const A = window.AdminCommon;
    if (!A) return;
    const { apiCall, el, showToast, confirmAction, fmtDate } = A;
    const $ = (id) => document.getElementById(id);

    const S = {
        view: 'restricted',
        mayHandle: false, mayRules: false, mayDateless: false, enabled: false,
        warn: { next: null, seq: 0 },
        auto: { next: null },
        rules: [],
        liftCtx: null, revokeCtx: null,
    };
    let liftField = null, revokeField = null;
    let said = null;

    // ── small helpers ───────────────────────────────────────────────────────────────────────────────
    const say = (text, bad) => {
        if (!said) said = $('mod-said');
        if (!said) return;
        said.textContent = '';
        if (text) said.append(text);
        said.classList.toggle('text-danger', !!bad);
    };
    const face = (name, avatar, cls) => (typeof window.userAvatarImg === 'function' && avatar
        ? window.userAvatarImg({ username: name, avatar: String(avatar || '') }, 20, 'avatar msgrep-av ' + (cls || '')) : null);
    const who = (name, avatar) => el('span', { className: 'msgrep-who' }, [face(name, avatar), el('strong', { text: name })]);
    const chip = (key, cls, params) => el('span', { className: 'badge-table ' + (cls || ''), text: t.key(key, params) });
    const FOREVER = (s) => !!s && String(s).slice(0, 4) >= '2099';
    const usersUrl = () => document.body.dataset.usersUrl || '';
    const errWord = (r) => {
        const e = r && r.error;
        if (e === 'invalid_text' && r.message) return r.message;
        const k = 'js.moderation.err_' + e;
        return t.has(k) ? t.key(k) : t.key('js.moderation.err_failed');
    };

    /** A block of the SERVER's rendering of a moderator's BBCode / Markdown (reportTextHtml()). */
    function richBlock(html) {
        const d = el('div', { className: 'arf-answer rt-body richtext' });
        d.innerHTML = html;
        return d;
    }
    /** What a moderator wrote: rendered when it has a format, its lines as they were typed when it has none. */
    function reasonBlock(w) {
        if (w.auto && w.auto_info) {
            return el('div', { className: 'mod-reason text-muted', text: t.key('js.moderation.auto_reason', { n: w.auto_info.count, days: w.auto_info.window }) });
        }
        if (w.reason_html) return richBlock(w.reason_html);
        if (w.reason) return el('div', { className: 'mod-reason', text: w.reason });
        return el('div', { className: 'mod-reason text-muted', text: t.key('js.moderation.no_reason') });
    }
    function button(label, cls, onClick, icon) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + (cls || 'btn-outline-secondary') }, [
            icon ? el('i', { className: 'bi bi-' + icon, 'aria-hidden': 'true' }) : null, icon ? ' ' : null, label]);
        b.addEventListener('click', () => onClick(b));
        return b;
    }

    // ── Restricted now ──────────────────────────────────────────────────────────────────────────────
    async function loadRestricted() {
        const list = $('mod-restricted-list');
        const r = await apiCall('admin/fetch_moderation&tab=restricted');
        if (r.error) { list.textContent = ''; list.appendChild(el('div', { className: 'text-danger py-3', text: errWord(r) })); return; }
        S.mayHandle = !!r.may_handle; S.mayRules = !!r.may_rules; S.mayDateless = !!r.may_dateless; S.enabled = !!r.enabled;
        list.textContent = '';
        const badge = $('mod-restricted-badge');
        badge.textContent = (r.rows || []).length ? String((r.rows || []).length) : '';
        badge.classList.toggle('d-hidden', !(r.rows || []).length);
        if (!(r.rows || []).length) { list.appendChild(el('div', { className: 'text-muted py-3', text: t.key('js.moderation.none_restricted') })); return; }
        if (r.more) list.appendChild(el('div', { className: 'settings-hint', text: t.key('js.moderation.restricted_cut') }));
        r.rows.forEach(u => list.appendChild(restrictedCard(u)));
    }

    function restrictionBlock(u, what) {
        const x = u[what];
        const w = x.warning;
        const box = el('div', { className: 'mod-restriction mod-' + what });
        const head = el('div', { className: 'mod-restriction-head' });
        head.appendChild(chip(what === 'ban' ? 'js.moderation.chip_banned' : 'js.moderation.chip_muted', 'badge-pending'));
        if (what === 'ban' && x.dateless) head.appendChild(el('span', { text: t.key('js.moderation.until_dateless') }));
        else if (FOREVER(x.until)) head.appendChild(el('span', { text: t.key('js.moderation.until_lifted') }));
        else head.appendChild(el('span', { text: t.key('js.moderation.until', { date: fmtDate(x.until) }) }));
        box.appendChild(head);
        if (w) {
            const by = w.auto ? t.key('js.moderation.by_auto') : t.key('js.moderation.by', { name: w.by || '—' });
            box.appendChild(el('div', { className: 'mod-meta text-muted wl-small' }, [
                by, ' · ', fmtDate(w.at), ' · ', t.key(w.loud ? 'js.moderation.told' : 'js.moderation.silent_mark'),
                w.revoked ? [' · ', t.key('js.moderation.mark_revoked')] : null,
            ].flat().filter(Boolean)));
            box.appendChild(reasonBlock(w));
        } else if (what === 'ban' && x.dateless) {
            box.appendChild(el('div', { className: 'mod-reason text-muted', text: t.key('js.moderation.dateless_note') }));
        } else {
            box.appendChild(el('div', { className: 'mod-reason text-muted', text: t.key('js.moderation.no_record') }));
        }
        if (S.mayHandle) {
            const can = !(what === 'ban' && x.dateless && !S.mayDateless);
            const b = button(t.key(what === 'ban' ? 'js.moderation.lift_ban' : 'js.moderation.lift_mute'), 'btn-outline-success mod-lift', () => openLift(u, what), 'unlock');
            if (!can) { b.disabled = true; b.title = t.key('js.moderation.err_ban_is_permanent'); }
            box.appendChild(el('div', { className: 'msgrep-acts' }, b));
        }
        return box;
    }

    function restrictedCard(u) {
        const c = el('div', { className: 'msgrep-card mod-card' });
        c.dataset.user = String(u.id);
        const head = el('div', { className: 'msgrep-head' }, [who(u.name, u.avatar)]);
        if (u.staff) head.appendChild(chip('js.moderation.staff', 'badge-reviewed'));
        head.appendChild(el('span', { className: 'text-muted wl-small', text: t.key('js.moderation.warned_n', { n: u.warnings }) }));
        c.appendChild(head);
        if (u.ban) c.appendChild(restrictionBlock(u, 'ban'));
        if (u.mute) c.appendChild(restrictionBlock(u, 'mute'));
        const foot = el('div', { className: 'msgrep-acts' });
        foot.appendChild(button(t.key('js.moderation.all_warnings'), 'btn-outline-secondary', () => {
            $('mod-f-member').value = u.name;
            switchView('warnings');
        }, 'list-ul'));
        const a = el('a', { className: 'btn btn-sm btn-outline-info', href: usersUrl() + '#find=' + encodeURIComponent(u.name), target: '_blank', rel: 'noopener' },
                     [el('i', { className: 'bi bi-person', 'aria-hidden': 'true' }), ' ', t.key('js.moderation.open_user')]);
        foot.appendChild(a);
        c.appendChild(foot);
        return c;
    }

    // ── lifting ─────────────────────────────────────────────────────────────────────────────────────
    function liftBox() {
        if (!liftField) {
            liftField = A.adminRichField({ compact: true, rows: 2, maxLength: 2000, countMax: 2000,
                placeholder: t.key('js.moderation.lift_note_ph'), ariaLabel: t.key('js.moderation.lift_note_ph') });
            $('mod-lift-rich').appendChild(liftField.el);
        }
        return liftField;
    }
    function openLift(u, what) {
        const x = u[what];
        S.liftCtx = { user: u, what };
        $('mod-lift-title').textContent = '';
        $('mod-lift-title').append(t.key(what === 'ban' ? 'js.moderation.lift_title_ban' : 'js.moderation.lift_title_mute'));
        $('mod-lift-name').textContent = u.name;
        $('mod-lift-lead').textContent = '';
        $('mod-lift-lead').append(t.key(what === 'ban' ? 'js.moderation.lift_lead_ban' : 'js.moderation.lift_lead_mute'));
        // the member is told by default when they were told of the penalty (a warning, an automatic one); a silent one stays silent
        $('mod-lift-tell').checked = !!(x.warning && (x.warning.loud || x.warning.auto));
        $('mod-lift-alert').textContent = '';
        liftBox().reset();
        bootstrap.Modal.getOrCreateInstance($('modLiftModal')).show();
    }
    async function doLift() {
        const ctx = S.liftCtx;
        if (!ctx) return;
        const f = liftBox();
        const btn = $('mod-lift-go');
        btn.disabled = true;
        const r = await apiCall('admin/moderation_action', 'POST', { op: 'lift', user_id: ctx.user.id, what: ctx.what,
            loud: $('mod-lift-tell').checked, note: f.getValue().trim(), note_format: f.getFormat() });
        btn.disabled = false;
        if (!r.success) {
            $('mod-lift-alert').textContent = '';
            $('mod-lift-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: errWord(r) }));
            return;
        }
        bootstrap.Modal.getOrCreateInstance($('modLiftModal')).hide();
        showToast(t.key(r.noop ? 'js.moderation.lift_noop' : ctx.what === 'ban' ? 'js.moderation.lifted_ban' : 'js.moderation.lifted_mute', { user: ctx.user.name }));
        loadRestricted();
    }

    // ── Warnings ────────────────────────────────────────────────────────────────────────────────────
    function filtersQs() {
        let qs = '';
        [['member', 'mod-f-member'], ['moderator', 'mod-f-moderator'], ['action', 'mod-f-action'], ['state', 'mod-f-state'],
         ['from', 'mod-f-from'], ['to', 'mod-f-to']].forEach(([k, id]) => {
            const v = $(id) && $(id).value ? $(id).value.trim() : '';
            if (v) qs += '&' + k + '=' + encodeURIComponent(v);
        });
        return qs;
    }

    function chipsFor(w) {
        const out = [chip('js.moderation.act_' + w.action, w.action === 'ban' || w.action === 'mute' ? 'badge-pending' : 'badge-reviewed')];
        if (w.auto) out.push(chip('js.moderation.mark_auto', 'badge-reviewed'));
        else out.push(chip(w.loud ? 'js.moderation.mark_loud' : 'js.moderation.mark_silent', w.loud ? 'badge-reviewed' : 'text-muted'));
        if (w.revoked) out.push(chip('js.moderation.mark_revoked', 'badge-reviewed mod-chip-revoked'));
        return out;
    }

    function warningCard(w, withRevoke) {
        const c = el('div', { className: 'msgrep-card mod-card mod-warning' + (w.revoked ? ' mod-revoked' : '') });
        c.dataset.id = String(w.id);
        const head = el('div', { className: 'msgrep-head' }, [who(w.user, w.avatar)]);
        chipsFor(w).forEach(ch => head.appendChild(ch));
        head.appendChild(el('span', { className: 'msgrep-when text-muted', text: fmtDate(w.at) }));
        // where it came from: a report on a comment / description / shout (a link), a message (no place to open), by hand, automatic
        const src = el('span', { className: 'mod-source text-muted wl-small' });
        if (w.where) {
            const a = el('a', { href: w.where, target: '_blank', rel: 'noopener' }, t.key('js.moderation.kind_' + w.kind));
            src.appendChild(a);
        } else {
            src.append(t.key('js.moderation.kind_' + (w.kind || 'manual')));
        }
        head.appendChild(src);
        c.appendChild(head);
        const meta = el('div', { className: 'mod-meta text-muted wl-small' });
        meta.append(w.auto ? t.key('js.moderation.by_auto') : t.key('js.moderation.by', { name: w.by || '—' }));
        c.appendChild(meta);
        c.appendChild(el('div', { className: 'mod-body' }, reasonBlock(w)));
        if (w.revoked) {
            const rv = el('div', { className: 'mod-revoke-line text-muted wl-small' }, [t.key('js.moderation.revoked_by', { name: w.revoked_by || '—', at: fmtDate(w.revoked_at) })]);
            if (w.revoke_note_html) rv.appendChild(richBlock(w.revoke_note_html));
            else if (w.revoke_note) rv.append(' ', w.revoke_note);
            c.appendChild(rv);
        } else if (withRevoke && S.mayHandle) {
            c.appendChild(el('div', { className: 'msgrep-acts' }, button(t.key('js.moderation.revoke'), 'btn-outline-warning mod-revoke', () => openRevoke(w), 'arrow-counterclockwise')));
        }
        return c;
    }

    async function loadWarnings(append) {
        const list = $('mod-warnings-list');
        const my = ++S.warn.seq;
        let qs = filtersQs();
        if (append && S.warn.next) qs += '&after=' + S.warn.next;
        const r = await apiCall('admin/fetch_moderation&tab=warnings' + qs);
        if (my !== S.warn.seq) return;
        if (!append) list.textContent = '';
        if (r.error) { list.appendChild(el('div', { className: 'text-danger py-3', text: errWord(r) })); return; }
        S.mayHandle = !!r.may_handle; S.mayDateless = !!r.may_dateless;
        $('mod-warnings-total').textContent = t.key('js.moderation.n_rows', { n: t.num(r.total || 0) });
        if (!(r.rows || []).length && !append) list.appendChild(el('div', { className: 'text-muted py-3', text: t.key('js.moderation.none_warnings') }));
        (r.rows || []).forEach(w => list.appendChild(warningCard(w, true)));
        S.warn.next = r.next || null;
        $('mod-warnings-more').classList.toggle('d-hidden', !S.warn.next);
    }

    // ── revoking ────────────────────────────────────────────────────────────────────────────────────
    function revokeBox() {
        if (!revokeField) {
            revokeField = A.adminRichField({ compact: true, rows: 2, maxLength: 2000, countMax: 2000,
                placeholder: t.key('js.moderation.revoke_note_ph'), ariaLabel: t.key('js.moderation.revoke_note_ph') });
            $('mod-revoke-rich').appendChild(revokeField.el);
        }
        return revokeField;
    }
    function openRevoke(w) {
        S.revokeCtx = w;
        $('mod-revoke-name').textContent = w.user;
        const what = $('mod-revoke-what');
        what.textContent = '';
        what.appendChild(el('div', {}, [chipsFor(w), ' ', fmtDate(w.at)].flat()));
        what.appendChild(reasonBlock(w));
        // told only when the member was told of it; lifted only when something runs now
        $('mod-revoke-tell-wrap').hidden = !w.loud;
        $('mod-revoke-tell').checked = !!w.loud;
        const now = w.now;
        const running = [];
        if (now && now.mute) running.push(t.key('js.moderation.revoke_running_mute', { date: FOREVER(now.mute) ? t.key('js.moderation.until_lifted_short') : fmtDate(now.mute) }));
        if (now && now.ban) running.push(t.key(now.dateless ? 'js.moderation.revoke_running_ban_dateless' : 'js.moderation.revoke_running_ban',
                                                { date: FOREVER(now.ban) ? t.key('js.moderation.until_lifted_short') : fmtDate(now.ban) }));
        $('mod-revoke-lift-wrap').hidden = !running.length;
        $('mod-revoke-lift').checked = false;
        const lab = $('mod-revoke-lift-label');
        lab.textContent = '';
        lab.append(t.key('js.moderation.revoke_lift'));
        running.forEach(p => { lab.append(' · '); lab.append(p); });
        $('mod-revoke-alert').textContent = '';
        revokeBox().reset();
        bootstrap.Modal.getOrCreateInstance($('modRevokeModal')).show();
    }
    async function doRevoke() {
        const w = S.revokeCtx;
        if (!w) return;
        const f = revokeBox();
        const btn = $('mod-revoke-go');
        btn.disabled = true;
        const r = await apiCall('admin/moderation_action', 'POST', { op: 'revoke', id: w.id, note: f.getValue().trim(), note_format: f.getFormat(),
            tell: !$('mod-revoke-tell-wrap').hidden && $('mod-revoke-tell').checked,
            lift_current: !$('mod-revoke-lift-wrap').hidden && $('mod-revoke-lift').checked });
        btn.disabled = false;
        if (!r.success) {
            $('mod-revoke-alert').textContent = '';
            $('mod-revoke-alert').appendChild(el('div', { className: 'alert alert-danger py-2 wl-small mt-2', text: errWord(r) }));
            return;
        }
        bootstrap.Modal.getOrCreateInstance($('modRevokeModal')).hide();
        let msg = t.key('js.moderation.revoked_done');
        if (r.lift_skipped) msg = t.key('js.moderation.revoked_skipped');
        showToast(msg);
        if (S.view === 'warnings') loadWarnings(false); else if (S.view === 'auto') loadAuto(false);
    }

    // ── Automatic penalties ─────────────────────────────────────────────────────────────────────────
    function ruleRow(r) {
        const num = (v, min, max, label) => {
            const i = el('input', { type: 'number', className: 'form-control form-control-sm bg-dark text-light border-secondary mod-r-num', min: String(min), max: String(max), 'aria-label': label });
            i.value = String(v);
            return i;
        };
        const on = el('input', { type: 'checkbox', 'aria-label': t.key('js.moderation.r_enabled') });
        on.checked = !!r.enabled;
        const warnings = num(r.warnings, 1, 100, t.key('js.moderation.r_warnings'));
        const window_ = num(r.window_days, 1, 365, t.key('js.moderation.r_window'));
        const action = el('select', { className: 'form-select form-select-sm bg-dark text-light border-secondary w-auto', 'aria-label': t.key('js.moderation.r_action') }, [
            el('option', { value: 'mute', text: t.key('js.moderation.r_mute') }), el('option', { value: 'ban', text: t.key('js.moderation.r_ban') })]);
        action.value = r.action;
        const dur = num(r.duration_days, 0, 365, t.key('js.moderation.r_duration'));
        const tr = el('tr', { className: 'mod-rule' });
        tr.dataset.id = String(r.id || 0);
        tr.appendChild(el('td', { className: 'mod-r-on' }, el('label', { className: 'search-check' }, [on, el('span', { className: 'search-check-box', 'aria-hidden': 'true' })])));
        tr.appendChild(el('td', {}, warnings));
        tr.appendChild(el('td', {}, window_));
        tr.appendChild(el('td', {}, action));
        tr.appendChild(el('td', {}, dur));
        const del = button('', 'btn-outline-danger', () => { tr.remove(); }, 'trash');
        del.title = t.key('js.moderation.r_remove');
        del.setAttribute('aria-label', t.key('js.moderation.r_remove'));
        tr.appendChild(el('td', { className: 'text-end' }, del));
        if (!S.mayRules) tr.querySelectorAll('input, select, button').forEach(n => { n.disabled = true; });
        tr._get = () => ({ id: Number(tr.dataset.id) || 0, enabled: on.checked ? 1 : 0, warnings: Number(warnings.value), window_days: Number(window_.value),
                           action: action.value, duration_days: Number(dur.value) });
        return tr;
    }
    function renderRules() {
        const body = $('mod-rules-body');
        body.textContent = '';
        if (!S.rules.length) body.appendChild(el('tr', { className: 'mod-rules-empty' }, el('td', { colSpan: 6, className: 'text-center text-muted py-3', text: t.key('js.moderation.no_rules') })));
        S.rules.forEach(r => body.appendChild(ruleRow(r)));
        $('mod-auto-on').checked = S.enabled;
        $('mod-auto-on').disabled = !S.mayRules;
        $('mod-rules-acts').hidden = !S.mayRules;
        $('mod-rules-readonly').hidden = S.mayRules;
        paintAutoState();
    }
    function paintAutoState() {
        const s = $('mod-auto-state');
        s.textContent = '';
        s.append(t.key(S.enabled ? 'js.moderation.auto_state_on' : 'js.moderation.auto_state_off'));
    }
    async function saveRules() {
        const rows = [...document.querySelectorAll('#mod-rules-body tr.mod-rule')].map(tr => tr._get());
        const btn = $('mod-rules-save');
        btn.disabled = true;
        const r = await apiCall('admin/moderation_rules', 'POST', { rules: rows, enabled: $('mod-auto-on').checked });
        btn.disabled = false;
        const out = $('mod-rules-said');
        out.textContent = '';
        if (!r.success) {
            out.className = 'text-danger wl-small';
            out.append(r.message || errWord(r));
            if (r.row >= 0) { const trs = document.querySelectorAll('#mod-rules-body tr.mod-rule'); if (trs[r.row]) trs[r.row].classList.add('mod-rule-bad'); }
            return;
        }
        out.className = 'text-muted wl-small';
        out.append(t.key('js.moderation.rules_saved'));
        S.rules = r.rules || [];
        S.enabled = !!r.enabled;
        renderRules();
        showToast(t.key('js.moderation.rules_saved'));
    }
    async function loadAuto(append) {
        const list = $('mod-auto-list');
        let qs = '';
        if (append && S.auto.next) qs += '&after=' + S.auto.next;
        const r = await apiCall('admin/fetch_moderation&tab=auto' + qs);
        if (!append) list.textContent = '';
        if (r.error) { list.appendChild(el('div', { className: 'text-danger py-3', text: errWord(r) })); return; }
        S.mayHandle = !!r.may_handle; S.mayRules = !!r.may_rules; S.mayDateless = !!r.may_dateless; S.enabled = !!r.enabled;
        if (!append) { S.rules = r.rules || []; renderRules(); }
        $('mod-auto-total').textContent = t.key('js.moderation.n_rows', { n: t.num(r.total || 0) });
        if (!(r.rows || []).length && !append) list.appendChild(el('div', { className: 'text-muted py-3', text: t.key('js.moderation.none_auto') }));
        (r.rows || []).forEach(w => list.appendChild(warningCard(w, true)));
        S.auto.next = r.next || null;
        $('mod-auto-more').classList.toggle('d-hidden', !S.auto.next);
    }

    // ── wiring ──────────────────────────────────────────────────────────────────────────────────────
    function switchView(view) {
        S.view = view;
        say('');
        document.querySelectorAll('#mod-tabs .source-tab').forEach(b => b.classList.toggle('active', b.dataset.view === view));
        ['restricted', 'warnings', 'auto'].forEach(v => $('mod-view-' + v).classList.toggle('d-hidden', v !== view));
        if (view === 'restricted') loadRestricted();
        if (view === 'warnings') { S.warn.next = null; loadWarnings(false); }
        if (view === 'auto') { S.auto.next = null; loadAuto(false); }
    }
    const soon = A.debounce(() => { S.warn.next = null; loadWarnings(false); }, A.DEBOUNCE ? A.DEBOUNCE.search : 400);

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('#mod-tabs .source-tab').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));
        ['mod-f-member', 'mod-f-moderator'].forEach(id => $(id).addEventListener('input', soon));
        ['mod-f-action', 'mod-f-state', 'mod-f-from', 'mod-f-to'].forEach(id => $(id).addEventListener('change', soon));
        $('mod-f-clear').addEventListener('click', () => {
            ['mod-f-member', 'mod-f-moderator', 'mod-f-from', 'mod-f-to'].forEach(id => { $(id).value = ''; });
            ['mod-f-action', 'mod-f-state'].forEach(id => { $(id).value = ''; });
            soon();
        });
        $('mod-warnings-more').addEventListener('click', () => loadWarnings(true));
        $('mod-auto-more').addEventListener('click', () => loadAuto(true));
        $('mod-lift-go').addEventListener('click', doLift);
        $('mod-revoke-go').addEventListener('click', doRevoke);
        $('mod-rule-add').addEventListener('click', () => {
            const body = $('mod-rules-body');
            const empty = body.querySelector('.mod-rules-empty');
            if (empty) empty.remove();
            body.appendChild(ruleRow({ id: 0, enabled: 1, warnings: 3, window_days: 30, action: 'mute', duration_days: 3 }));
        });
        $('mod-rules-save').addEventListener('click', saveRules);
        $('mod-auto-on').addEventListener('change', () => { S.enabled = $('mod-auto-on').checked; paintAutoState(); });
        const logout = $('btn-logout');
        if (logout) logout.addEventListener('click', async () => { try { await apiCall('admin/logout', 'POST', {}); } catch (e) {} location.href = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '') + '?action=' + (document.body.dataset.loginPath || 'admin'); });
        switchView('restricted');
    });
})();
