/**
 * Reporting what people write in public (1.71.0, includes/reports.php): a comment, a torrent's description,
 * a shout. The server decides who may report what and says so on every row it hands out (`can_report`,
 * `reported`); this draws the flag and the box, and sends what the reader wrote (api/content_report.php).
 *
 * THE FLAG is an icon button of part A's kind where the row's other actions are icon buttons — a comment's
 * row (through window.Comments.onActions(), the door part D left for it) and the Info panel's description
 * actions (assets/js/app.js asks window.Reports.button()) —, and a room control like its neighbours in a
 * shout's row (drawn by the server and by assets/js/shoutbox.js, which asks window.Reports.open()). Pressed,
 * it opens a small box IN PLACE: what is wrong with it, Send, Cancel, and the promise that only the
 * moderators read it and the author never learns who reported. Sent, the flag turns into the filled flag,
 * "Reported" — on this page at once, and on every page after, from the server's `reported`.
 *
 * The box keeps its own Esc (app.js's layers stand aside while it has the focus), sends with Enter, and
 * posts its token through csrfToken() — the page's meta — like every public script.
 */
(function () {
    'use strict';
    if (typeof window.t !== 'function') return;
    const t = window.t;
    const API = typeof APP_API !== 'undefined' ? APP_API : 'api.php?endpoint=';
    const REASON_MAX = 300;

    const el = (tag, cls, text) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    };
    const tip = (anchor, text) => { if (typeof window.pubTip === 'function') window.pubTip(anchor, text); };
    const post = async (body) => {
        if (typeof postJson === 'function') return postJson('content_report', body);
        try {
            const r = await fetch(API + 'content_report', { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
            return await r.json();
        } catch (e) { return null; }
    };

    /** The flag as "Reported": the filled flag, its words, and a press that only says so again. */
    function markIcon(btn) {
        btn.dataset.reported = '1';
        btn.classList.add('is-reported');
        btn.setAttribute('aria-label', t('js.report.reported'));
        btn.setAttribute('aria-pressed', 'true');
        btn.dataset.tip = t('js.report.reported_tip');
        const i = btn.querySelector(':scope > .bi');
        if (i) i.className = 'bi bi-flag-fill';
    }

    /**
     * The Report button of a comment's row or of the description's actions: one glyph, its name for a screen
     * reader, the explanation in the site's tooltip (part A's `.ic-btn`, the classes of the row it joins).
     * `target`: {kind, id} or {kind, hash}; `host`: where the box opens (after `after`, or at the end).
     */
    function button(target, reported, cls, host, after) {
        // `report-` and never `rep-`: that prefix is the ratings' (.rep-btn is a vote).
        const b = el('button', 'btn btn-secondary btn-small ic-btn report-btn' + (cls ? ' ' + cls : ''));
        b.type = 'button';
        const i = document.createElement('i');
        i.className = 'bi bi-flag';
        i.setAttribute('aria-hidden', 'true');
        b.appendChild(i);
        b.setAttribute('aria-label', t('js.report.report'));
        b.dataset.tip = t('js.report.tip_' + target.kind);
        if (reported) markIcon(b);
        b.addEventListener('click', () => {
            if (b.dataset.reported === '1') { tip(b, t('js.report.reported_tip')); return; }
            open(b, typeof host === 'function' ? host() : host, target, () => markIcon(b), after);
        });
        return b;
    }

    /**
     * The box, in place: under `host` (or right after `after` inside it). `done()` runs once the server took
     * the report (or says this reader already reported it). One box at a time in a host.
     */
    function open(btn, host, target, done, after) {
        if (!host || host.querySelector(':scope > .report-box')) return;
        const box = el('div', 'report-box');
        const id = 'report-in-' + target.kind + '-' + (target.id || target.hash || '') + '-' + Math.random().toString(36).slice(2, 7);
        const lab = el('label', 'report-label', t('js.report.label'));
        lab.htmlFor = id;
        const inp = el('input', 'profile-search report-input');
        inp.type = 'text';
        inp.id = id;
        inp.maxLength = REASON_MAX;
        inp.placeholder = t('js.report.placeholder');
        inp.autocomplete = 'off';
        const go = el('button', 'btn btn-small report-send', t('js.report.send'));
        go.type = 'button';
        const no = el('button', 'btn btn-secondary btn-small report-cancel', t('js.common.cancel'));
        no.type = 'button';
        const note = el('div', 'report-private text-muted', t('js.report.private'));
        const msg = el('span', 'report-msg text-muted');
        msg.setAttribute('aria-live', 'polite');
        const row = el('div', 'report-row');
        row.append(inp, go, no);
        box.append(lab, row, note, msg);
        if (after && after.parentNode === host) after.after(box); else host.appendChild(box);
        btn.setAttribute('aria-expanded', 'true');
        inp.focus();
        const close = (refocus) => {
            box.remove();
            btn.setAttribute('aria-expanded', 'false');
            if (refocus && btn.isConnected) btn.focus();
        };
        no.addEventListener('click', () => close(true));
        inp.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); go.click(); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
        });
        go.addEventListener('click', async () => {
            if (go.disabled) return;
            const reason = inp.value.trim();
            if (!reason) { msg.textContent = t('js.report.need_reason'); inp.focus(); return; }
            go.disabled = true;
            const body = { csrf_token: csrfToken(box), kind: target.kind, reason };
            if (target.id) body.id = target.id;
            if (target.hash) body.hash = target.hash;
            // Through the anti-spam layer's helper (1.71.0, assets/js/antispam.js): a CAPTCHA it asks for, and the
            // same report again; a wait counts down on Send with the sentence beside it.
            const anti = window.Antispam || null;
            const j = anti ? await anti.send((extra) => post(Object.assign({}, body, extra || {})),
                                             { button: go, note: (s) => { msg.textContent = s || ''; }, action: 'content_report' })
                           : await post(body);
            if (!(anti && anti.waiting(go))) go.disabled = false;
            if (!j || !j.success) {
                if (!(anti && anti.waiting(go))) msg.textContent = (j && j.message) || t('js.report.failed');
                return;
            }
            if (typeof done === 'function') done(j);
            // Said where the reader is looking, and the box goes: the flag beside it now says "Reported".
            close(false);
            tip(btn, j.message || t('js.report.sent'));
        });
    }

    // A comment's row (part D's door): somebody else's comment, for a reader who may report — `can_report` —
    // the flag beside Edit and Delete; `reported`, pressed.
    if (window.Comments && typeof window.Comments.onActions === 'function') {
        window.Comments.onActions((r, acts, ctx) => {
            if (!r || !r.can_report || !acts) return;
            acts.appendChild(button({ kind: 'comment', id: r.id }, !!r.reported, 'cm-report', ctx && ctx.row ? ctx.row : acts.closest('.cm-row')));
        });
    }

    window.Reports = { button, open, markIcon };
})();
