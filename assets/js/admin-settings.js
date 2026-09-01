/**
 * Settings page: group sub-menu + ranked search.
 *
 * The page is one long form of ~150 settings. This adds two ways through it:
 *   · a sub-menu of groups (Site & pages, Security & CAPTCHA, …) that shows one group at a time;
 *   · a search box that ranks whole SECTIONS whose name matches first, then INDIVIDUAL settings
 *     (their section shown with only the matching fields), and finally the remaining sections of
 *     any group the query names ("security", "federation") under a divider.
 *
 * The index is built from what is on the page (labels, hints, field names, and for blocks that are
 * not plain fields — the peer table, the donation rows — their text) merged with the hidden keyword
 * list served by api.php?endpoint=admin/settings_catalog (includes/settings_catalog.php): synonyms
 * an admin might type that no label contains ("bot", "smtp", "cron", "hidden url"). Those words are
 * never rendered, so the page stays clean and screen readers do not read them out. A control that
 * carries no name of its own (the timeline range checkboxes, the donation rows) can opt into the
 * index with data-setting="<setting key>".
 *
 * Everything here is presentation only: nothing changes what the form submits (hidden inputs are
 * still submitted — only their visibility is touched, and the filter is lifted for the moment the
 * browser validates the form), and with JavaScript off the page is exactly the old single scroll.
 */
(function () {
    'use strict';

    const toolbar = document.getElementById('settings-toolbar');
    const form = document.getElementById('settings-form');
    if (!toolbar || !form) return;

    const input = document.getElementById('settings-search');
    const clearBtn = document.getElementById('settings-search-clear');
    const countEl = document.getElementById('settings-search-count');
    const emptyEl = document.getElementById('settings-empty');
    const emptyQ = document.getElementById('settings-empty-q');
    const groupBar = document.getElementById('settings-groups');
    const saveRow = document.getElementById('settings-save-row');
    const alertRow = document.getElementById('settings-alert');
    const rule = document.getElementById('settings-rule');

    const norm = (s) => (s || '').toLowerCase().replace(/\s+/g, ' ').trim();
    const textOf = (el) => norm(el ? el.textContent : '');
    const show = (el, on) => { if (el) el.classList.toggle('d-hidden', !on); };

    // ── model ───────────────────────────────────────────────────────────────
    // Every section is a list of "items": one per settings cell (.row > div) plus one per block that
    // is not a plain cell (donation rows, the schedule table, the federation peer card). Indexing the
    // blocks too is what makes searching "peer" or "wallet" find them instead of hiding them.
    function makeItem(el) {
        const controls = [...el.querySelectorAll('input, select, textarea')];
        const names = controls.map(c => norm(c.name || c.id || '')).filter(Boolean);
        if (el.dataset.setting) names.push(norm(el.dataset.setting));
        return {
            el, names,
            label: textOf(el.querySelector('label, .form-label, h6')),
            hint: textOf(el).slice(0, 600),
            keywords: '',
            score: 0,
        };
    }

    const sections = [...document.querySelectorAll('.settings-section')].map((el, i) => {
        const anchor = document.createComment('settings-section');
        el.parentNode.insertBefore(anchor, el);
        const cells = [...el.querySelectorAll('.row > div')].map(makeItem);
        const extras = [...el.children]
            .filter(c => c.tagName !== 'H5' && !c.classList.contains('row'))
            .map(makeItem);
        return {
            el, i, anchor,
            items: cells.concat(extras),
            rows: [...el.querySelectorAll('.row')],
            group: el.dataset.group || '',
            title: norm(el.dataset.title || textOf(el.querySelector('h5'))),
            groupText: '',
            inForm: anchor.parentNode === form,
        };
    });

    // ── catalogue (hidden keywords + group keywords) ────────────────────────
    const apiBase = document.body.dataset.apiBase || 'api.php?endpoint=';
    function applyCatalogue(cat) {
        const kw = (cat && cat.keywords) || {};
        const groupKw = {};
        ((cat && cat.groups) || []).forEach(g => { groupKw[g.id] = norm(g.title + ' ' + (g.keywords || '')); });
        sections.forEach(sec => {
            sec.groupText = groupKw[sec.group] || '';
            sec.items.forEach(item => { item.keywords = norm(item.names.map(n => kw[n] || '').join(' ')); });
        });
    }
    // index what is on the page immediately: the search works even if the catalogue never answers
    applyCatalogue(null);
    fetch(apiBase + 'admin/settings_catalog', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(r => r.json())
        .then(j => { if (j && j.success) { applyCatalogue(j); if (norm(input.value)) runSearch(); } })
        .catch(() => { /* labels + hints only — still useful */ });

    // ── scoring ─────────────────────────────────────────────────────────────
    // Every token must be found somewhere in the setting; the score decides the order.
    const rxSafe = (t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    function itemScore(item, tokens) {
        let total = 0;
        for (const t of tokens) {
            let best = 0;
            for (const n of item.names) {
                if (n === t) best = Math.max(best, 130);
                else if (n.indexOf(t) !== -1) best = Math.max(best, 70);
            }
            if (item.label.indexOf(t) === 0) best = Math.max(best, 60);
            else if (new RegExp('\\b' + rxSafe(t)).test(item.label)) best = Math.max(best, 45);
            else if (item.label.indexOf(t) !== -1) best = Math.max(best, 30);
            if (item.keywords.indexOf(t) !== -1) best = Math.max(best, 22);
            if (item.hint.indexOf(t) !== -1) best = Math.max(best, 8);
            if (!best) return 0;            // one missing token → not a match at all
            total += best;
        }
        return total;
    }
    const TITLE_BONUS = 1000;   // "captcha" must open the CAPTCHA section, not a field that mentions it
    const GROUP_BONUS = 1;      // a group-name hit is the weakest reason to show a section

    // ── view state ──────────────────────────────────────────────────────────
    let group = 'all';

    // The divider that separates precise hits from "everything else in a matching group".
    const divider = document.createElement('div');
    divider.className = 'settings-divider d-hidden';
    divider.id = 'settings-divider';

    function resetSection(sec, visible) {
        sec.items.forEach(it => it.el.classList.remove('d-hidden', 'settings-hit'));
        sec.rows.forEach(r => r.classList.remove('d-hidden'));
        sec.el.classList.remove('settings-section-hit');
        show(sec.el, visible);
    }
    /**
     * A .row whose every cell the search hid must be hidden itself.
     *
     * Bootstrap builds the gutter out of a negative margin on .row and a matching padding on its
     * cells. Hide all the cells and the padding goes with them, but the negative margin stays — so an
     * emptied row does not merely collapse, it pulls everything after it a gutter's width UPWARDS.
     * Three emptied rows in a section (Backups does this on most queries) pulled the next row 48 px
     * up, straight over the rows above it. That is the "two things drawn on top of each other".
     */
    function syncRows(sec) {
        sec.rows.forEach(row => {
            // `display:none` counts as hidden too: a cell a *feature* hid (the fetch-order mix,
            // shown only in mix mode) is as invisible as one the search hid, and a row left
            // standing for it keeps Bootstrap's negative gutter with no padding to balance it.
            const anyVisible = [...row.children].some(c =>
                !c.classList.contains('d-hidden') && c.style.display !== 'none');
            row.classList.toggle('d-hidden', !anyVisible);
        });
    }
    /** Put the sections back in their original document order (search reorders them). */
    function restoreOrder() {
        sections.forEach(sec => { if (sec.anchor.nextSibling !== sec.el) sec.anchor.parentNode.insertBefore(sec.el, sec.anchor.nextSibling); });
    }
    /** The Save row belongs to the form: hide it when the filter left no section to save. */
    function syncChrome() {
        const formVisible = sections.some(s => s.inForm && !s.el.classList.contains('d-hidden'));
        show(saveRow, formVisible);
        show(alertRow, formVisible);
        const credVisible = sections.some(s => !s.inForm && !s.el.classList.contains('d-hidden'));
        show(rule, credVisible);
    }

    function applyGroup() {
        restoreOrder();
        show(divider, false);
        groupBar.classList.remove('settings-groups-muted');
        sections.forEach(sec => resetSection(sec, group === 'all' || sec.group === group));
        show(emptyEl, false);
        countEl.textContent = '';
        [...groupBar.querySelectorAll('.settings-group-btn')].forEach(b => {
            const on = b.dataset.group === group;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        syncChrome();
    }

    function runSearch() {
        const q = norm(input.value);
        show(clearBtn, q !== '');
        if (q === '') { applyGroup(); return; }

        const tokens = q.split(' ').filter(Boolean);
        const ranked = [];
        let fields = 0;

        sections.forEach(sec => {
            const titleHit = tokens.every(t => sec.title.indexOf(t) !== -1);
            const groupHit = tokens.every(t => sec.groupText.indexOf(t) !== -1);
            let best = 0, matched = 0;
            sec.items.forEach(item => {
                item.score = itemScore(item, tokens);
                if (item.score > best) best = item.score;
                if (item.score > 0) matched++;
            });
            const score = (titleHit ? TITLE_BONUS : 0) + best + (groupHit ? GROUP_BONUS : 0);
            if (!score) return;
            // whole section for a name/group hit; otherwise just the fields that matched
            ranked.push({ sec, score, whole: titleHit || best === 0 });
            if (best > 0) fields += matched;
        });

        ranked.sort((a, b) => (b.score - a.score) || (a.sec.i - b.sec.i));
        const visible = new Set(ranked.map(r => r.sec));
        sections.forEach(sec => resetSection(sec, visible.has(sec)));
        ranked.forEach(({ sec, whole }) => {
            if (whole) { sec.el.classList.add('settings-section-hit'); return; }
            sec.items.forEach(item => {
                // An item can sit inside another one (the donation rows inside the donation list, the
                // add-peer row inside the federation card). Those inner cells carry no name of their
                // own and always score 0 — hiding them would blank out the very block that matched.
                const hit = item.score > 0;
                const inHit = !hit && sec.items.some(o => o.score > 0 && o.el !== item.el && o.el.contains(item.el));
                item.el.classList.toggle('d-hidden', !hit && !inHit);
                item.el.classList.toggle('settings-hit', hit);
            });
            syncRows(sec);
        });

        // best matches first (only inside the form — the credentials block is its own <form> and
        // must never be moved into it), with a divider before the group-only leftovers
        const first = sections.find(s => s.inForm);
        if (first) {
            const mark = first.anchor;
            let dividerPlaced = false;
            ranked.forEach(({ sec, score }) => {
                if (!sec.inForm) return;
                if (!dividerPlaced && score <= GROUP_BONUS && ranked.some(r => r.score > GROUP_BONUS)) {
                    divider.textContent = 'Other sections in matching groups';
                    show(divider, true);
                    mark.parentNode.insertBefore(divider, mark);
                    dividerPlaced = true;
                }
                mark.parentNode.insertBefore(sec.el, mark);
            });
            if (!dividerPlaced) show(divider, false);
        }

        const groups = new Set(ranked.map(r => r.sec.group));
        countEl.textContent = ranked.length
            ? (fields ? fields + (fields === 1 ? ' setting' : ' settings') + ' · ' : '') +
              ranked.length + (ranked.length === 1 ? ' section' : ' sections') +
              ' in ' + groups.size + (groups.size === 1 ? ' group' : ' groups')
            : '';
        emptyQ.textContent = input.value.trim();
        show(emptyEl, ranked.length === 0);
        [...groupBar.querySelectorAll('.settings-group-btn')].forEach(b => {
            b.classList.toggle('active', b.dataset.group === 'all');
            b.setAttribute('aria-pressed', b.dataset.group === 'all' ? 'true' : 'false');
        });
        groupBar.classList.add('settings-groups-muted');
        syncChrome();
    }

    // ── wiring ──────────────────────────────────────────────────────────────
    let timer = null;
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(runSearch, 120); });
    // Escape clears the query exactly like the × button — including keeping the selected group.
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { input.value = ''; runSearch(); }
    });
    clearBtn.addEventListener('click', () => { input.value = ''; input.focus(); runSearch(); });
    groupBar.addEventListener('click', (e) => {
        const b = e.target.closest('.settings-group-btn');
        if (!b) return;
        group = b.dataset.group || 'all';
        input.value = '';
        show(clearBtn, false);
        applyGroup();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    // "/" or Ctrl+K from anywhere on the page jumps into the search — but never out of a dialog
    // (Bootstrap focuses the modal element itself, which is not a form control, so the tag check
    // alone would let the shortcut steal focus from the password confirmation).
    document.addEventListener('keydown', (e) => {
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'select' || tag === 'textarea' || e.target.isContentEditable) return;
        if (document.querySelector('.modal.show')) return;
        if (e.key === '/' || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });

    // Saving while filtered.
    //
    // The browser validates a form BEFORE its submit event, and a control that fails validation
    // inside a display:none section cannot be focused — Chrome then aborts the submit with only a
    // console warning ("not focusable"), so the button looks dead. That is a real problem and this
    // code exists for it.
    //
    // The first version solved it by lifting the WHOLE filter on every submit click and putting it
    // back in a setTimeout. Two things were wrong with that. The restore landed in a separate task,
    // so the browser got a rendering opportunity in between and painted all 27 sections at once —
    // the flash. And when validation actually failed the submit event never fired, so the restore
    // never ran and the admin lost their group and their query for good.
    //
    // So: touch nothing unless the form is genuinely invalid, and then unhide only what the browser
    // needs to be able to focus. A valid save — which is nearly every save — now does no DOM work at
    // all, so there is nothing to paint and nothing to restore. Bound at document level in the
    // capture phase because the page has TWO forms: the settings form and the Security & Credentials
    // one, whose own fields can be filtered away just as easily.
    function revealFor(el) {
        const sec = el.closest('.settings-section');
        for (let n = el; n && n !== sec; n = n.parentElement) n.classList.remove('d-hidden');
        if (sec) { sec.classList.remove('d-hidden'); sec.scrollIntoView({ block: 'center' }); }
        syncChrome();
    }
    document.addEventListener('click', (e) => {
        const btn = e.target.closest && e.target.closest('button[type="submit"], input[type="submit"]');
        if (!btn || !btn.form) return;
        if (btn.form.noValidate || btn.hasAttribute('formnovalidate')) return;
        // .validity.valid rather than .checkValidity(), which would fire an `invalid` event as a
        // side effect of merely asking.
        const bad = [...btn.form.elements].find(el => el.willValidate && !el.validity.valid);
        if (!bad) return;                       // the common case: not one class is touched
        revealFor(bad);
    }, true);

    // group counts in the sub-menu
    const counts = {};
    sections.forEach(s => { counts[s.group] = (counts[s.group] || 0) + 1; });
    [...groupBar.querySelectorAll('[data-count-for]')].forEach(el => {
        const n = counts[el.dataset.countFor] || 0;
        if (n) el.textContent = n;
    });

    /** #section-… links (e.g. from the Traffic page's "Timeline settings" button) still work. */
    function openHash() {
        const id = (location.hash || '').replace('#', '');
        if (!id) return;
        const sec = sections.find(s => s.el.id === id);
        if (!sec) return;
        group = sec.group;
        input.value = '';
        applyGroup();
        sec.el.scrollIntoView({ block: 'start' });
        sec.el.classList.add('settings-section-hit');
        setTimeout(() => sec.el.classList.remove('settings-section-hit'), 2500);
    }
    window.addEventListener('hashchange', openHash);
    openHash();
})();

/* ── the metadata fetch-order mix ────────────────────────────────────────────
 *
 * Seven shares that must add up to 100. The rule that makes this usable rather than a puzzle: you
 * edit ONE number and the others absorb the difference in proportion to what they already had, so
 * the total is never wrong and you are never asked to do arithmetic to make a change stick.
 *
 * The note under each field is the point of the whole control. A share is not meaningful in the
 * abstract -- it is meaningful relative to how many hashes the worker fetches at once. At 32
 * parallel fetches a 15 % share is roughly five of every wave; at 4 it is one wave in two. Rather
 * than forbid a small number, the panel says what it will actually do, and calls out the case where
 * it rounds to "less than one per wave" -- which is the shape of the "1 % means never" trap.
 */
(function () {
    const mode = document.getElementById('meta-order-mode');
    const wrap = document.getElementById('meta-order-mix');
    if (!mode || !wrap) return;
    const fields = Array.from(document.querySelectorAll('.meta-order-share'));
    const notes = Array.from(document.querySelectorAll('.meta-order-note'));
    const sumEl = document.getElementById('meta-order-sum');
    const liveEl = document.getElementById('meta-order-live');
    const conc = document.querySelector('input[name="meta_worker_concurrency"]');
    const DEFAULTS = { whitelist: 0, seeders: 70, newest: 15, seen: 0, completed: 0, random: 15, oldest: 0 };

    /* Which index each ordering rides on — the same table as includes/meta_order.php and
     * worker/worker.py. Shown so a mode reads as a query plan rather than a preference. */
    const PLAN = {
        oldest:    ['idx_index_meta', 'meta_priority DESC, meta_requested_at ASC'],
        newest:    ['idx_index_meta', 'meta_priority DESC, meta_requested_at DESC'],
        seeders:   ['idx_index_meta_seed', 'meta_priority DESC, last_seeders DESC'],
        seen:      ['idx_index_meta_seen', 'meta_priority DESC, seen_count DESC'],
        completed: ['idx_index_meta_completed', 'meta_priority DESC, last_completed DESC'],
        random:    ['PRIMARY', 'info_hash >= (random point), ORDER BY info_hash'],
        whitelist: ['—', 'the whitelist queue, in admin-priority order'],
    };

    const val = f => Math.max(0, Math.min(100, parseInt(f.value, 10) || 0));
    const showMix = () => { wrap.style.display = mode.value === 'mix' ? '' : 'none'; };

    /** How many parallel fetches the worker will really run: the panel override, else its own
     *  config, which the panel does not know. 8 is the placeholder shown in that field. */
    function concurrency() {
        const raw = conc ? parseInt(conc.value, 10) : NaN;
        return raw > 0 ? Math.min(64, raw) : 8;
    }

    function paintLive() {
        if (!liveEl) return;
        if (mode.value === 'mix') {
            const live = fields.filter(f => val(f) > 0)
                .sort((a, b) => val(b) - val(a))
                .map(f => val(f) + '% ' + f.dataset.share);
            liveEl.innerHTML = '<span class="settings-hint">Rotating over 100 claims: <strong>' +
                (live.length ? live.join(' · ') : 'nothing — set a share below') + '</strong>. ' +
                'A slot whose queue is empty falls through to the other queue.</span>';
            return;
        }
        const p = PLAN[mode.value] || ['—', '—'];
        liveEl.innerHTML = '<span class="settings-hint">Runs on <code>' + p[0] + '</code> as ' +
            '<code>' + p[1] + '</code>. The whitelist queue still drains first.</span>';
    }

    function paint() {
        const c = concurrency();
        let total = 0;
        fields.forEach(f => { total += val(f); });
        if (sumEl) {
            sumEl.textContent = total === 100 ? 'adds up to 100%' : 'adds up to ' + total + '% — will be corrected on save';
            sumEl.classList.toggle('text-warning', total !== 100);
        }
        notes.forEach(n => {
            const f = fields.find(x => x.dataset.share === n.dataset.note);
            if (!f) return;
            const p = val(f);
            f.classList.remove('is-thin');
            if (p === 0) {
                // Zero means two different things, and saying which one matters: for the whitelist
                // it is not "never" but "absolute priority", which is the default and the safe case.
                n.textContent = f.dataset.share === 'whitelist' ? 'whitelist drains first (default)' : 'off';
                n.classList.remove('text-warning');
                return;
            }
            const perWave = (p * c) / 100;
            if (perWave >= 1) {
                n.textContent = '≈ ' + (perWave >= 10 ? Math.round(perWave) : perWave.toFixed(1)) +
                    ' of every ' + c + ' fetches';
                n.classList.remove('text-warning');
            } else {
                // Not forbidden — but this is the case worth naming out loud.
                n.textContent = 'one every ' + Math.round(1 / perWave) + ' waves — thin at ' + c + ' parallel fetches';
                n.classList.add('text-warning');
                f.classList.add('is-thin');
            }
        });
        paintLive();
    }

    /** Keep the total at 100 by moving the difference into the OTHER shares, proportionally.
     *  When the others are all zero there is nothing to take from, so the remainder goes to the
     *  first of them — otherwise a single field could never be lowered. */
    function rebalance(changed) {
        const others = fields.filter(f => f !== changed);
        let want = val(changed);
        const pool = 100 - want;
        let have = 0;
        others.forEach(f => { have += val(f); });
        if (have <= 0) {
            others.forEach((f, i) => { f.value = i === 0 ? pool : 0; });
        } else {
            let given = 0;
            others.forEach((f, i) => {
                const share = i === others.length - 1 ? pool - given : Math.round(val(f) * pool / have);
                f.value = Math.max(0, share);
                given += Math.max(0, share);
            });
        }
        changed.value = want;
        paint();
    }

    fields.forEach(f => {
        f.addEventListener('change', () => rebalance(f));
        // Clamped on the way in, not only on change: a number left at 150 inside a container the
        // mode has since hidden fails HTML validation on a field the operator cannot see, and the
        // browser then refuses to submit the form without saying where.
        f.addEventListener('input', () => {
            const v = parseInt(f.value, 10);
            if (v > 100) f.value = 100;
            if (v < 0) f.value = 0;
            paint();
        });
    });
    if (conc) conc.addEventListener('input', paint);
    mode.addEventListener('change', () => { showMix(); paint(); });
    const reset = document.getElementById('meta-order-reset');
    if (reset) reset.addEventListener('click', () => {
        fields.forEach(f => { f.value = DEFAULTS[f.dataset.share] ?? 0; });
        paint();
    });
    showMix();
    paint();
})();
