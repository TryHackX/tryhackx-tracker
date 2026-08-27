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
        sec.el.classList.remove('settings-section-hit');
        show(sec.el, visible);
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

    // Saving while filtered: the browser validates a form BEFORE its submit event, and a control that
    // fails validation inside a display:none section cannot be focused — Chrome then aborts the submit
    // with only a console warning ("not focusable"), so the button would look dead. Lift the filter for
    // that moment; if validation passes (submit fires) put it straight back, and if it fails leave
    // everything visible so the offending field is on screen. Bound at document level in the capture
    // phase because the page has TWO forms: the settings form and the Security & Credentials one,
    // whose own required fields can be filtered away just as easily.
    let restoreAfterSubmit = null;
    document.addEventListener('click', (e) => {
        const btn = e.target.closest && e.target.closest('button[type="submit"], input[type="submit"]');
        if (!btn || !btn.form) return;
        restoreAfterSubmit = null;           // never carry a stale filter over from a failed attempt
        if (!norm(input.value) && group === 'all') return;
        restoreAfterSubmit = { q: input.value, group: group };
        input.value = '';
        group = 'all';
        applyGroup();
    }, true);
    document.addEventListener('submit', () => {
        if (!restoreAfterSubmit) return;
        const st = restoreAfterSubmit;
        restoreAfterSubmit = null;
        setTimeout(() => { input.value = st.q; group = st.group; runSearch(); }, 0);
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
