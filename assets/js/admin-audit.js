/**
 * The panel's log: who did what, when, and whether it worked.
 *
 * Everything here is a READ. There is no delete button and no edit, deliberately — a log that the
 * panel it records can rewrite is not evidence of anything. Retention is a setting and the janitor
 * enforces it.
 */
(function () {
    'use strict';
    const { apiCall, el, renderPagination, fmtDate, bindSearchClear } = window.AdminCommon;
    const $ = (id) => document.getElementById(id);
    // admin-common exports no debounce; each page that types into a filter has its own three-liner.
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    const state = { page: 1, group: 'all', actor: '', search: '', failed: false, serverTime: 0 };

    // Actions get a colour by AREA rather than one per action: twelve colours would be a legend, and
    // a legend nobody reads is decoration.
    const GROUP_STYLE = {
        auth:     { cls: 'au-g-auth',     label: 'sign-in' },
        settings: { cls: 'au-g-settings', label: 'settings' },
        content:  { cls: 'au-g-content',  label: 'content' },
        hashes:   { cls: 'au-g-hashes',   label: 'hashes' },
        reports:  { cls: 'au-g-reports',  label: 'reports' },
        users:    { cls: 'au-g-users',    label: 'users' },
        machine:  { cls: 'au-g-machine',  label: 'machine' },
        mail:     { cls: 'au-g-mail',     label: 'mail' },
        api:      { cls: 'au-g-api',      label: 'api' },
        other:    { cls: 'au-g-other',    label: 'other' },
    };

    const ACTOR_TITLE = {
        owner:     'Signed in with the panel password — the site owner',
        admin:     'A user account in the admin group',
        moderator: 'A user account with panel access but not admin',
        user:      'An ordinary signed-in visitor',
        api:       'A server-to-server API client',
        system:    'The janitor or another automatic process',
    };

    async function load() {
        const r = await apiCall('admin/audit_log', 'POST', {
            op: 'list', page: state.page, group: state.group, actor: state.actor,
            search: state.search, failed_only: state.failed,
        });
        const body = $('au-rows');
        body.textContent = '';
        if (!r || !r.success) {
            body.appendChild(el('tr', {}, el('td', { colSpan: 5, className: 'table-empty-state' },
                (r && r.error) || 'Could not read the log.')));
            return;
        }

        $('au-note').textContent = r.enabled
            ? 'Kept for ' + r.keep_days + ' days, then removed by the janitor. Credentials are never recorded — a '
              + 'setting that holds one shows as “changed”, not as its value.'
            : 'Logging is switched OFF in Settings, so nothing new is being recorded. What is here is history.';

        $('au-total').textContent = r.total
            ? r.total.toLocaleString() + (r.total === 1 ? ' entry' : ' entries')
            : '';

        fillGroups(r.groups);

        if (!r.rows.length) {
            body.appendChild(el('tr', {}, el('td', { colSpan: 5, className: 'table-empty-state' },
                state.search || state.failed || state.group !== 'all' || state.actor
                    ? 'Nothing matches those filters.'
                    : 'Nothing has been recorded yet.')));
            renderPagination($('au-pagination'), { total: 0, page: 1, pages: 1, onPage: () => {} });
            return;
        }

        r.rows.forEach(row => body.appendChild(rowEl(row)));
        renderPagination($('au-pagination'), {
            total: r.total, page: r.page, pages: r.pages,
            onPage: (p) => { state.page = p; load(); },
        });
    }

    function rowEl(row) {
        const g = GROUP_STYLE[row.action_group] || GROUP_STYLE.other;
        const tr = el('tr', { className: row.ok ? '' : 'au-failed' });

        const when = new Date(String(row.at).replace(' ', 'T'));
        tr.appendChild(el('td', { className: 'au-c-when' }, [
            el('span', { text: fmtDate(when.toISOString()) }),
        ]));

        tr.appendChild(el('td', { className: 'au-c-who' }, [
            el('span', { text: row.actor_name || '—' }),
            el('div', { className: 'wl-small text-muted', text: row.actor_type,
                        title: ACTOR_TITLE[row.actor_type] || '' }),
        ]));

        const what = el('td', { className: 'au-c-what' }, [
            el('span', { className: 'au-badge ' + g.cls, text: row.action }),
        ]);
        if (!row.ok) what.appendChild(el('span', { className: 'au-badge au-failed-badge', text: 'failed' }));
        tr.appendChild(what);

        const cell = el('td', {});
        cell.appendChild(el('div', { className: 'au-summary', text: row.summary || '—' }));
        if (row.target_id) {
            cell.appendChild(el('code', { className: 'au-target', text: row.target_id }));
        }
        // The detail is folded away: a settings save can touch twenty keys and the row above it still
        // has to be readable. Opened, it is the whole answer to "what exactly changed".
        if (row.detail) cell.appendChild(detailEl(row.detail));
        tr.appendChild(cell);

        tr.appendChild(el('td', { className: 'au-c-ip' }, el('code', { className: 'wl-small', text: row.ip || '—' })));
        return tr;
    }

    function detailEl(detail) {
        const det = el('details', { className: 'au-detail' });
        det.appendChild(el('summary', { text: 'details' }));
        // A settings diff has a shape worth rendering as a table; anything else is shown as it came.
        const isDiff = Object.values(detail).every(v => v && typeof v === 'object'
            && ('from' in v || 'to' in v || 'changed' in v));
        if (isDiff && Object.keys(detail).length) {
            const t = el('table', { className: 'au-diff' });
            Object.keys(detail).forEach(k => {
                const v = detail[k];
                t.appendChild(el('tr', {}, [
                    el('td', { className: 'au-diff-k' }, el('code', { text: k })),
                    v.changed
                        ? el('td', { className: 'au-diff-hidden', colSpan: 2, text: 'changed (value not recorded)' })
                        : el('td', { className: 'au-diff-from' }, el('code', { text: String(v.from ?? '') || '(empty)' })),
                    v.changed ? '' : el('td', { className: 'au-diff-to' }, el('code', { text: String(v.to ?? '') || '(empty)' })),
                ]));
            });
            det.appendChild(t);
        } else {
            det.appendChild(el('pre', { className: 'au-json', text: JSON.stringify(detail, null, 2) }));
        }
        return det;
    }

    let groupsFilled = false;
    function fillGroups(groups) {
        if (groupsFilled || !Array.isArray(groups)) return;
        const sel = $('au-group');
        groups.forEach(g => sel.appendChild(el('option', {
            value: g, text: (GROUP_STYLE[g] || { label: g }).label })));
        groupsFilled = true;
    }

    async function fillActors() {
        const r = await apiCall('admin/audit_log', 'POST', { op: 'actors' });
        if (!r || !r.success) return;
        const sel = $('au-actor');
        (r.actors || []).forEach(a => sel.appendChild(el('option', {
            value: a.actor_name, text: a.actor_name + ' (' + a.n + ')' })));
    }

    document.addEventListener('DOMContentLoaded', () => {
        const search = $('au-search');
        const doSearch = debounce(() => { state.search = search.value.trim(); state.page = 1; load(); }, 350);
        search.addEventListener('input', doSearch);
        bindSearchClear(search, $('au-search-clear'), () => { state.search = ''; state.page = 1; load(); });
        $('au-group').addEventListener('change', e => { state.group = e.target.value; state.page = 1; load(); });
        $('au-actor').addEventListener('change', e => { state.actor = e.target.value; state.page = 1; load(); });
        $('au-failed').addEventListener('change', e => { state.failed = e.target.checked; state.page = 1; load(); });
        fillActors();
        load();
    });
})();
