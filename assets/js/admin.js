const API_BASE = document.body.dataset.apiBase;

// CAPTCHA modal lives in assets/js/captcha.js (window.showCaptchaModal), shared with the public site.
// `action` is the reCAPTCHA v3 action name (v3 shows no modal — the token is fetched silently).
function requestCaptchaToken(action) {
    if (typeof window.showCaptchaModal !== 'function') return Promise.resolve('');
    return window.showCaptchaModal({ action: action || 'submit' }).then((t) => t || '');
}

/** True when the last prompt failed on the widget/loader instead of being cancelled by the admin. */
function captchaUnavailable() {
    return typeof window.captchaWasUnavailable === 'function' && window.captchaWasUnavailable();
}

let currentPage = 1;
let sortStack = [{ col: 'date', dir: 'desc' }];
// The dashboard fired a request on the click itself. The header row is rebuilt whenever the source
// changes, so this timer has to live out here — one created inside the forEach would give every
// column its own, and debounce nothing at all.
const SORT_DEBOUNCE_MS = 1200;   // the same number as AdminCommon.DEBOUNCE.sort — this file does not load admin-common.js
let sortTimer = null;
function reloadAfterSort() {
    clearTimeout(sortTimer);
    sortTimer = setTimeout(() => {
        (source === 'appeals' || source === 'appeal_archives') ? loadAppeals() : loadReports();
    }, SORT_DEBOUNCE_MS);
}
let currentReport = null;
let searchTerm = '';
let filterStatus = 'all';
let source = 'reports';
let searchTimeout = null;

function confirmAction(message) {
    return new Promise((resolve) => {
        const modalEl = document.getElementById('confirmModal');
        document.getElementById('confirmModal-msg').textContent = message;
        const modal = new bootstrap.Modal(modalEl);

        const okBtn = document.getElementById('confirmModal-ok');
        const cancelBtn = document.getElementById('confirmModal-cancel');

        function cleanup() {
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
        }

        let resolved = false;
        function onOk() { resolved = true; cleanup(); modal.hide(); resolve(true); }
        function onCancel() { resolved = true; cleanup(); modal.hide(); resolve(false); }
        function onHidden() { if (!resolved) { cleanup(); resolve(false); } }

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        modalEl.addEventListener('hidden.bs.modal', onHidden);

        modal.show();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateSortIcons();
    loadReports();

    document.getElementById('btn-logout').addEventListener('click', handleLogout);
    document.getElementById('btn-archive-all').addEventListener('click', handleArchiveAll);
    document.getElementById('modal-block').addEventListener('click', handleBlock);
    document.getElementById('modal-unblock').addEventListener('click', handleUnblock);
    document.getElementById('modal-archive').addEventListener('click', handleArchive);
    document.getElementById('modal-restore').addEventListener('click', handleRestore);
    document.getElementById('modal-delete-perm').addEventListener('click', handleDeletePermOpen);
    document.getElementById('delete-perm-form').addEventListener('submit', handleDeletePermSubmit);
    document.getElementById('modal-send-email').addEventListener('click', handleSendEmail);
    document.getElementById('appeal-accept').addEventListener('click', () => handleResolveAppeal('accepted'));
    document.getElementById('appeal-reject').addEventListener('click', () => handleResolveAppeal('rejected'));
    document.getElementById('appeal-restore').addEventListener('click', handleRestoreAppeal);

    // ── Delegated handlers for the rows this file BUILDS ─────────────────────
    //
    // These were thirteen onclick=""/ondblclick="" attributes written into innerHTML. A nonce does
    // NOT rescue an inline event handler: script-src blocks every on*= attribute unless the policy
    // says 'unsafe-inline', which is exactly the escape hatch the whole Content-Security-Policy
    // change exists to remove. Two listeners on `document` instead — the rows are replaced on every
    // page of results, so binding per-row would mean re-binding on every render anyway, and this is
    // already the house pattern (app.js's [data-md] toolbar, admin-users.js, admin-whitelist.js).
    document.addEventListener('click', (e) => {
        const hash = e.target.closest('[data-copy-hash]');
        if (hash) { copyHash(hash, hash.dataset.copyHash); return; }
        const magnet = e.target.closest('[data-copy-magnet]');
        if (magnet) { copyMagnet(magnet); return; }
        const rep = e.target.closest('[data-report-id]');
        if (rep) { openModal(parseInt(rep.dataset.reportId, 10)); return; }
        const app = e.target.closest('[data-appeal-id]');
        if (app) { openAppealModal(parseInt(app.dataset.appealId, 10)); return; }
        const cross = e.target.closest('[data-appeal-report]');
        // preventDefault() takes the place of the old `return false` on an href="#" link.
        if (cross) { e.preventDefault(); openReportFromAppeal(parseInt(cross.dataset.appealReport, 10), cross.dataset.appealHash || ''); return; }
        const pager = e.target.closest('[data-page]');
        if (pager && !pager.disabled) { goPage(parseInt(pager.dataset.page, 10)); }
    });
    document.addEventListener('dblclick', (e) => {
        const cell = e.target.closest('[data-edit-id][data-edit-field]');
        if (cell) inlineEdit(cell, parseInt(cell.dataset.editId, 10), cell.dataset.editField);
    });

    loadAppealsBadge();
    initTrackerService();

    // Multi-level sorting
    document.querySelectorAll('.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.sort;
            const idx = sortStack.findIndex(s => s.col === col);
            if (idx === -1) {
                sortStack.push({ col, dir: 'asc' });
            } else if (sortStack[idx].dir === 'asc') {
                sortStack[idx].dir = 'desc';
            } else {
                sortStack.splice(idx, 1);
            }
            updateSortIcons();
            reloadAfterSort();
        });
    });

    // Search with debounce
    const searchInput = document.getElementById('search-input');
    const searchClear = document.getElementById('search-clear');

    function updateClearBtn() {
        searchClear.classList.toggle('visible', searchInput.value.length > 0);
    }

    searchInput.addEventListener('input', (e) => {
        updateClearBtn();
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            searchTerm = e.target.value.trim();
            currentPage = 1;
            (source === 'appeals' || source === 'appeal_archives') ? loadAppeals() : loadReports();
        }, 400);   // AdminCommon.DEBOUNCE.search
    });

    searchClear.addEventListener('click', () => {
        if (!searchInput.value) return;
        let delay = 20;
        const eraseStep = () => {
            if (searchInput.value.length > 0) {
                searchInput.value = searchInput.value.slice(0, -1);
                delay = Math.max(5, delay * 0.85);
                updateClearBtn();
                setTimeout(eraseStep, delay);
            } else {
                searchTerm = '';
                currentPage = 1;
                (source === 'appeals' || source === 'appeal_archives') ? loadAppeals() : loadReports();
            }
        };
        eraseStep();
    });

    // Status filter
    document.getElementById('filter-status').addEventListener('change', (e) => {
        filterStatus = e.target.value;
        currentPage = 1;
        (source === 'appeals' || source === 'appeal_archives') ? loadAppeals() : loadReports();
    });

    // Source tabs
    document.querySelectorAll('.source-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.source-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            source = tab.dataset.source;
            currentPage = 1;
            filterStatus = 'all';
            updateFilterOptions(source);
            updateTableHeaders(source);
            if (source === 'appeals' || source === 'appeal_archives') {
                loadAppeals();
            } else {
                loadReports();
            }
            document.getElementById('btn-archive-all').style.display = (source !== 'reports') ? 'none' : '';
        });
    });
});

async function apiCall(endpoint, method = 'GET', body = null) {
    const opts = { method, headers: {} };
    // Attach the CSRF token to every admin request; the server enforces it on writes.
    const csrf = document.body.dataset.csrf;
    if (csrf) opts.headers['X-CSRF-Token'] = csrf;
    if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(API_BASE + endpoint, opts);
    return res.json();
}

async function loadReports() {
    const sortParam = sortStack.length
        ? sortStack.map(s => s.col + ':' + s.dir).join(',')
        : 'date:desc';
    const params = new URLSearchParams({
        page: currentPage,
        sort: sortParam,
        source: source,
    });
    if (searchTerm) params.set('search', searchTerm);
    if (filterStatus !== 'all') params.set('status', filterStatus);

    const json = await apiCall('admin/fetch_reports&' + params.toString());

    // Update pending badges
    updateBadge('reports-badge', json.pending_reports);
    updateBadge('archives-badge', json.pending_archives);

    const tbody = document.getElementById('reports-body');
    const totalEl = document.getElementById('total-count');

    if (!json.reports || json.reports.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 table-empty-state">' + esc(t('js.reports.no_reports_found')) + '</td></tr>';
        totalEl.textContent = t('js.reports.total', {n: 0});
        document.getElementById('pagination').innerHTML = '';
        return;
    }

    totalEl.textContent = t('js.reports.total', {n: json.total});

    tbody.innerHTML = json.reports.map(r => {
        let statusBadge;
        if (r.blocked) {
            statusBadge = '<span class="badge-table badge-blocked">' + esc(t('js.reports.status_blocked')) + '</span>';
        } else if (r.checked) {
            statusBadge = '<span class="badge-table badge-reviewed">' + esc(t('js.reports.status_reviewed')) + '</span>';
        } else {
            statusBadge = '<span class="badge-table badge-pending">' + esc(t('js.reports.status_pending')) + '</span>';
        }
        return `
        <tr>
            <td class="dash-id">${r.id}</td>
            <td title="${escAttr(r.name)}">${esc(r.name)}</td>
            <td title="${escAttr(r.email)}"><small>${esc(r.email)}</small></td>
            <td class="editable-cell" title="${escAttr(r.company)}" data-edit-id="${r.id}" data-edit-field="company">${esc(r.company)}</td>
            <td class="editable-cell" title="${escAttr(r.representative)}" data-edit-id="${r.id}" data-edit-field="representative">${esc(r.representative)}</td>
            <td title="${escAttr(r.objectTitle)}">${esc(r.objectTitle)}</td>
            <td class="hash-cell hash-copy" title="${escAttr(t('js.reports.click_to_copy', {hash: r.infoHash}))}" data-copy-hash="${r.infoHash}">${r.infoHash}</td>
            <td title="${escAttr(r.ip)}"><small>${esc(r.ip)}</small></td>
            <td class="col-badge">${statusBadge}</td>
            <td class="dash-date"><small>${r.timestamp}</small></td>
            <td class="td-actions"><button class="btn btn-sm btn-outline-info" data-report-id="${r.id}"><i class="bi bi-three-dots"></i></button></td>
        </tr>`;
    }).join('');

    renderPagination(json.total, json.page, json.pages);
}

function renderPagination(total, page, pages) {
    const el = document.getElementById('pagination');
    // Shared renderer (First / Prev / page box / Next / Last) from admin-common.js when it is loaded.
    if (window.AdminCommon && typeof window.AdminCommon.renderPagination === 'function') {
        window.AdminCommon.renderPagination(el, { total, page, pages, onPage: goPage });
        return;
    }
    if (pages <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = `
        <button ${page <= 1 ? 'disabled' : ''} data-page="${page - 1}"><i class="bi bi-chevron-left"></i> ${esc(t('js.reports.prev'))}</button>
        <span>${esc(t('js.reports.page_of', {page: page, pages: pages}))}</span>
        <button ${page >= pages ? 'disabled' : ''} data-page="${page + 1}">${esc(t('js.reports.next'))} <i class="bi bi-chevron-right"></i></button>
    `;
}

function goPage(p) {
    currentPage = p;
    if (source === 'appeals' || source === 'appeal_archives') {
        loadAppeals();
    } else {
        loadReports();
    }
}

// Safe markdown-like rendering (escapes first, then applies formatting)
function renderMessage(raw) {
    if (!raw) return '';
    let s = esc(raw);
    // Bold: **text**
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Italic: *text*
    s = s.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
    // Inline code: `code`
    s = s.replace(/`(.+?)`/g, '<code class="md-inline-code">$1</code>');
    // Line breaks
    s = s.replace(/\n/g, '<br>');
    return s;
}

async function openModal(id) {
    const endpoint = source === 'archives'
        ? 'admin/fetch_reports&id=' + id + '&source=archives'
        : 'admin/fetch_reports&id=' + id;
    const json = await apiCall(endpoint);
    if (!json.report) return;

    currentReport = json.report;
    const r = currentReport;
    const isArchive = source === 'archives';

    const messageHtml = r.add_message
        ? `<div class="report-message-block"><p class="msg-block-header">${esc(t('js.reports.message'))}</p><div class="report-message-content">${renderMessage(r.add_message)}</div></div>`
        : '';

    document.getElementById('modal-report-info').innerHTML = `
        <div class="report-info-grid">
            <p><strong>${esc(t('js.reports.col_id'))}:</strong> ${r.id}</p>
            <p><strong>${esc(t('js.reports.col_name'))}:</strong> ${esc(r.name)}</p>
            <p><strong>${esc(t('js.reports.col_email'))}:</strong> ${esc(r.email)}</p>
            <p><strong>${esc(t('js.reports.col_company'))}:</strong> ${esc(r.company)}</p>
            <p><strong>${esc(t('js.reports.lbl_representative'))}:</strong> ${esc(r.representative)}</p>
            <p><strong>${esc(t('js.reports.col_object'))}:</strong> ${esc(r.objectTitle)}</p>
            <p><strong>${esc(t('js.reports.lbl_link'))}:</strong> <a href="${escAttr(r.link)}" rel="noopener noreferrer" target="_blank" class="text-info">${esc(r.link)}</a></p>
            <p><strong>${esc(t('js.reports.lbl_hash'))}:</strong> <code class="text-info">${r.infoHash}</code></p>
            ${r.magnet_link ? '<p><strong>' + esc(t('js.reports.lbl_magnet')) + ':</strong></p><div class="magnet-wrapper"><code id="modal-magnet-code" class="text-info magnet-code">' + esc(r.magnet_link) + '</code><button type="button" data-copy-magnet class="btn btn-sm magnet-copy-btn" title="' + escAttr(t('js.reports.copy_magnet_link')) + '"><i class="bi bi-clipboard"></i></button></div>' : ''}
            <p><strong>${esc(t('js.reports.col_ip'))}:</strong> ${r.ip} &nbsp; <strong>${esc(t('js.reports.col_date'))}:</strong> ${r.timestamp}</p>
        </div>
        ${messageHtml}
    `;

    document.getElementById('modal-email-msg').value = '';
    document.getElementById('modal-alert').innerHTML = '';
    document.getElementById('modal-blacklist-warning').style.display = 'none';

    if (isArchive) {
        // Archive view — show restore + block (if not blocked)
        document.getElementById('modal-block').style.display = r.blocked ? 'none' : '';
        document.getElementById('modal-unblock').style.display = 'none';
        document.getElementById('modal-archive').style.display = 'none';
        document.getElementById('modal-restore').style.display = '';
        document.getElementById('modal-delete-perm').style.display = '';
        document.getElementById('modal-actions').style.display = '';
        document.getElementById('modal-email-section').style.display = 'none';
        document.getElementById('modal-email-msg').style.display = 'none';
        document.getElementById('modal-send-email').closest('.text-center').style.display = 'none';
    } else {
        // Active reports view
        document.getElementById('modal-block').style.display = r.blocked ? 'none' : '';
        document.getElementById('modal-unblock').style.display = r.blocked ? '' : 'none';
        document.getElementById('modal-archive').style.display = '';
        document.getElementById('modal-restore').style.display = 'none';
        document.getElementById('modal-delete-perm').style.display = '';
        document.getElementById('modal-actions').style.display = '';
        document.getElementById('modal-email-section').style.display = '';
        document.getElementById('modal-email-msg').style.display = '';
        document.getElementById('modal-send-email').closest('.text-center').style.display = '';
    }

    const modal = new bootstrap.Modal(document.getElementById('actionModal'));
    modal.show();

    // Auto-mark as reviewed and send "under review" notification for pending reports
    if (!isArchive && !r.checked && !r.blocked) {
        apiCall('admin/notify_review', 'POST', { id: r.id }).then(res => {
            if (res.success) {
                if (res.marked_reviewed) loadReports();
                if (!res.already_sent && !res.skipped) {
                    showToast('success', t('js.reports.review_notification_sent'));
                }
            }
        });
    }
}

async function handleBlock() {
    if (!currentReport) return;
    const isArchive = source === 'archives';
    const msg = isArchive
        ? t('js.reports.confirm_block_archive')
        : t('js.reports.confirm_block_and_archive');
    if (!await confirmAction(msg)) return;
    const endpoint = isArchive ? 'admin/block_archived' : 'admin/block_hash';
    const json = await apiCall(endpoint, 'POST', { id: currentReport.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
        let toastMsg = json.message || t('js.reports.hash_blocked');
        if (json.auto_closed > 0) toastMsg += json.auto_closed > 1 ? t('js.reports.auto_closed_many', {n: json.auto_closed}) : t('js.reports.auto_closed_one');
        showToast('success', toastMsg);
        if (json.blacklist_warning) {
            showToast('error', json.blacklist_warning);
        }
        loadReports();
        refreshTrackerWarnings();
    } else {
        showModalAlert('error', json.error || t('js.reports.error'));
    }
}

async function handleUnblock() {
    if (!currentReport) return;
    if (!await confirmAction(t('js.reports.confirm_unblock'))) return;
    const json = await apiCall('admin/unblock_hash', 'POST', { id: currentReport.id });
    if (json.success) {
        let unblockMsg = json.message || t('js.reports.hash_unblocked');
        if (json.auto_closed > 0) unblockMsg += json.auto_closed > 1 ? t('js.reports.auto_closed_many', {n: json.auto_closed}) : t('js.reports.auto_closed_one');
        showToast('success', unblockMsg);
        currentReport.blocked = 0;
        document.getElementById('modal-block').style.display = '';
        document.getElementById('modal-unblock').style.display = 'none';
        if (json.blacklist_warning) {
            showModalAlert('warning', json.blacklist_warning);
        }
        loadReports();
        refreshTrackerWarnings();
    } else {
        showModalAlert('error', json.error || t('js.reports.error'));
    }
}

async function handleArchive() {
    if (!currentReport) return;
    if (!await confirmAction(t('js.reports.confirm_archive'))) return;
    const json = await apiCall('admin/delete_report', 'POST', { id: currentReport.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
        showToast('success', t('js.reports.report_archived'));
        loadReports();
    } else {
        showModalAlert('error', json.error || t('js.reports.error'));
    }
}

async function handleRestore() {
    if (!currentReport) return;
    if (!await confirmAction(t('js.reports.confirm_restore'))) return;
    const json = await apiCall('admin/restore_report', 'POST', { id: currentReport.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
        showToast('success', t('js.reports.report_restored'));
        loadReports();
        refreshTrackerWarnings();
    } else {
        showModalAlert('error', json.error || t('js.reports.error'));
    }
}

async function handleSendEmail() {
    if (!currentReport) return;
    const msg = document.getElementById('modal-email-msg').value.trim();
    if (!msg) {
        showModalAlert('error', t('js.reports.enter_message'));
        return;
    }
    const json = await apiCall('admin/send_email', 'POST', {
        id: currentReport.id,
        message: msg,
    });
    if (json.success) {
        showToast('success', t('js.reports.email_sent'));
        document.getElementById('modal-email-msg').value = '';
    } else {
        showModalAlert('error', json.error || t('js.reports.email_failed'));
    }
}

async function handleArchiveAll() {
    if (!await confirmAction(t('js.reports.confirm_archive_all'))) return;
    const json = await apiCall('admin/delete_all', 'POST');
    if (json.success) {
        showToast('success', t('js.reports.archived_count', {n: json.archived || 0}));
        loadReports();
    }
}

/**
 * Where to send the browser after logging out: the configured admin sign-in address. A plain
 * reload (or a hardcoded ?action=admin) dead-ends on the public front page — a signed-out visitor
 * only gets the form at admin_login_path (Settings -> Admin Access & Sessions).
 */
function adminLoginUrl() {
    const base = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '');
    return base + '?action=' + (document.body.dataset.loginPath || 'admin');
}

async function handleLogout() {
    await apiCall('admin/logout', 'POST');
    window.location.href = adminLoginUrl();
}

function showModalAlert(type, msg) {
    const el = document.getElementById('modal-alert');
    const cls = type === 'success' ? 'alert-success' : type === 'warning' ? 'alert-warning' : 'alert-danger';
    el.innerHTML = `<div class="alert ${cls} py-1 px-2 modal-alert-sm">${esc(msg)}</div>`;
    setTimeout(() => {
        const alertDiv = el.querySelector('.modal-alert-sm');
        if (alertDiv) alertDiv.classList.add('alert-fade');
    }, 4500);
    setTimeout(() => el.innerHTML = '', 5000);
}

function showToast(type, msg) {
    const container = document.getElementById('toast-container');
    const icon = type === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-circle-fill text-danger';
    const id = 'toast-' + Date.now();
    container.insertAdjacentHTML('beforeend', `
        <div id="${id}" class="toast align-items-center border-0 show toast-dark" role="alert">
            <div class="d-flex">
                <div class="toast-body text-light"><i class="bi ${icon}"></i> ${esc(msg)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-toast-close></button>
            </div>
        </div>
    `);
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

function updateSortIcons() {
    document.querySelectorAll('.sortable').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (!icon) return;
        const col = th.dataset.sort;
        const idx = sortStack.findIndex(s => s.col === col);
        const oldBadge = th.querySelector('.sort-priority');
        if (oldBadge) oldBadge.remove();

        if (idx !== -1) {
            const s = sortStack[idx];
            icon.className = s.dir === 'asc' ? 'bi bi-arrow-up sort-icon active' : 'bi bi-arrow-down sort-icon active';
            if (sortStack.length > 1) {
                const badge = document.createElement('sup');
                badge.className = 'sort-priority';
                badge.textContent = idx + 1;
                icon.after(badge);
            }
        } else {
            icon.className = 'bi bi-arrow-down-up sort-icon';
        }
    });
}

function updateTableHeaders(src) {
    const thead = document.querySelector('#reports-table thead tr');
    if (!thead) return;

    const isAppeal = src === 'appeals' || src === 'appeal_archives';

    // Fixed-layout column widths (admin.css .dash-c-*): the two views pin different columns.
    const colgroup = document.getElementById('reports-colgroup');
    if (colgroup) {
        colgroup.innerHTML = isAppeal
            ? '<col class="dash-c-id"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-type"><col class="dash-c-report"><col class="dash-c-hash"><col class="dash-c-ip"><col class="dash-c-status"><col class="dash-c-date"><col class="dash-c-actions">'
            : '<col class="dash-c-id"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-hash"><col class="dash-c-ip"><col class="dash-c-status"><col class="dash-c-date"><col class="dash-c-actions">';
    }

    if (isAppeal) {
        thead.innerHTML = `
            <th class="sortable" data-sort="id">${esc(t('js.reports.col_id'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="name">${esc(t('js.reports.col_name'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="email">${esc(t('js.reports.col_email'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th>${esc(t('js.reports.col_description'))}</th>
            <th class="sortable col-badge" data-sort="type">${esc(t('js.reports.col_type'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="report">${esc(t('js.reports.col_report'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="hash">${esc(t('js.reports.col_info_hash'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="ip">${esc(t('js.reports.col_ip'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable col-badge" data-sort="status">${esc(t('js.reports.col_status'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="date">${esc(t('js.reports.col_date'))} <i class="bi bi-arrow-down sort-icon active"></i></th>
            <th class="th-actions">${esc(t('js.reports.col_actions'))}</th>
        `;
    } else {
        thead.innerHTML = `
            <th class="sortable" data-sort="id">${esc(t('js.reports.col_id'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="name">${esc(t('js.reports.col_name'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="email">${esc(t('js.reports.col_email'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="company">${esc(t('js.reports.col_company'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="representative">${esc(t('js.reports.col_entity'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="object">${esc(t('js.reports.col_object'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="hash">${esc(t('js.reports.col_hash'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="ip">${esc(t('js.reports.col_ip'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable col-badge" data-sort="blocked">${esc(t('js.reports.col_status'))} <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="date">${esc(t('js.reports.col_date'))} <i class="bi bi-arrow-down sort-icon active"></i></th>
            <th class="th-actions">${esc(t('js.reports.col_actions'))}</th>
        `;
    }

    // Re-attach sort handlers
    thead.querySelectorAll('.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.sort;
            const idx = sortStack.findIndex(s => s.col === col);
            if (idx === -1) {
                sortStack.push({ col, dir: 'asc' });
            } else if (sortStack[idx].dir === 'asc') {
                sortStack[idx].dir = 'desc';
            } else {
                sortStack.splice(idx, 1);
            }
            updateSortIcons();
            reloadAfterSort();
        });
    });

    // Reset sort stack and update icons
    sortStack = [{ col: 'date', dir: 'desc' }];
    updateSortIcons();
}

function updateFilterOptions(src) {
    const filter = document.getElementById('filter-status');
    filter.value = 'all';

    const reportOptions = [
        { value: 'all', text: t('js.reports.filter_all_statuses') },
        { value: 'pending', text: t('js.reports.filter_awaiting_review') },
        { value: 'reviewed', text: t('js.reports.status_reviewed') },
        { value: 'blocked', text: t('js.reports.status_blocked') },
    ];
    const appealOptions = [
        { value: 'all', text: t('js.reports.filter_all_statuses') },
        { value: 'pending', text: t('js.reports.status_pending') },
        { value: 'accepted', text: t('js.reports.status_accepted') },
        { value: 'rejected', text: t('js.reports.status_rejected') },
    ];
    const appealArchiveOptions = [
        { value: 'all', text: t('js.reports.filter_all_statuses') },
        { value: 'accepted', text: t('js.reports.status_accepted') },
        { value: 'rejected', text: t('js.reports.status_rejected') },
    ];

    let options;
    if (src === 'appeal_archives') {
        options = appealArchiveOptions;
    } else if (src === 'appeals') {
        options = appealOptions;
    } else {
        options = reportOptions;
    }

    filter.innerHTML = options.map(o => `<option value="${o.value}">${esc(o.text)}</option>`).join('');
}

function updateBadge(id, count) {
    const badge = document.getElementById(id);
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count;
        badge.classList.remove('d-hidden');
    } else {
        badge.classList.add('d-hidden');
    }
}

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// For double-quoted attribute values (title="..."): esc() leaves quotes alone (innerHTML serialisation only
// escapes & < >), so a value containing a quote could otherwise close the attribute.
function escAttr(str) {
    return esc(str).replace(/"/g, '&quot;');
}

function copyHash(td, hash) {
    navigator.clipboard.writeText(hash).then(() => {
        const orig = td.textContent;
        td.textContent = t('js.reports.copied');
        td.style.color = '#4caf50';
        setTimeout(() => { td.textContent = orig; td.style.color = ''; }, 1200);
    });
}

function copyMagnet(btn) {
    const code = document.getElementById('modal-magnet-code');
    if (!code) return;
    navigator.clipboard.writeText(code.textContent.trim()).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.style.color = '#4caf50';
        setTimeout(() => { btn.innerHTML = orig; btn.style.color = '#888'; }, 1500);
    });
}

// --- Inline edit ---

function inlineEdit(td, id, field) {
    if (td.querySelector('input')) return; // Already editing
    const original = td.textContent.trim();
    const input = document.createElement('input');
    input.type = 'text';
    input.value = original;
    input.className = 'inline-edit-input';
    input.maxLength = 255;
    td.textContent = '';
    td.appendChild(input);
    input.focus();
    input.select();

    let saved = false;
    async function save() {
        if (saved) return;
        saved = true;
        const value = input.value.trim();
        if (!value || value === original) {
            td.textContent = original;
            return;
        }
        td.textContent = value;
        const json = await apiCall('admin/update_field', 'POST', {
            id, field, value,
            source: (source === 'archives') ? 'archives' : 'reports',
        });
        if (json.success) {
            td.textContent = json.value;
            showToast('success', t('js.reports.field_updated', {field: field.charAt(0).toUpperCase() + field.slice(1)}));
        } else {
            td.textContent = original;
            showToast('error', json.error || t('js.reports.update_failed'));
        }
    }

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        if (e.key === 'Escape') { saved = true; td.textContent = original; }
    });
    input.addEventListener('blur', save);
}

// --- Appeals ---

let currentAppeal = null;

async function loadAppealsBadge() {
    try {
        const json = await apiCall('admin/fetch_appeals&page=1');
        updateBadge('appeals-badge', json.pending_count);
    } catch {}
}

async function loadAppeals() {
    const sortParam = sortStack.length
        ? sortStack.map(s => s.col + ':' + s.dir).join(',')
        : 'date:desc';
    const params = new URLSearchParams({ page: currentPage, sort: sortParam });
    if (source === 'appeal_archives') params.set('source', 'archives');
    if (searchTerm) params.set('search', searchTerm);
    if (filterStatus !== 'all') params.set('status', filterStatus);

    const json = await apiCall('admin/fetch_appeals&' + params.toString());
    const tbody = document.getElementById('reports-body');
    const totalEl = document.getElementById('total-count');

    // Update badge
    updateBadge('appeals-badge', json.pending_count);

    if (!json.appeals || json.appeals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 table-empty-state">' + t('js.reports.no_appeals') + '</td></tr>';
        totalEl.textContent = t('js.reports.total', {n: 0});
        document.getElementById('pagination').innerHTML = '';
        return;
    }

    totalEl.textContent = t('js.reports.total', {n: json.total});

    tbody.innerHTML = json.appeals.map(a => {
        const statusMap = { pending: 'badge-pending', accepted: 'badge-reviewed', rejected: 'badge-blocked', reviewed: 'badge-reviewed' };
        const labelMap = { pending: t('js.reports.status_pending'), accepted: t('js.reports.status_accepted'), rejected: t('js.reports.status_rejected'), reviewed: t('js.reports.status_reviewed') };
        const badge = `<span class="badge-table ${statusMap[a.status] || 'badge-pending'}">${labelMap[a.status] || a.status}</span>`;
        const typeBadge = a.appeal_type === 'block'
            ? '<span class="badge-table badge-type-block">' + t('js.reports.type_block') + '</span>'
            : '<span class="badge-table badge-type-unblock">' + t('js.reports.type_unblock') + '</span>';
        const shortMsg = a.message ? (a.message.length > 50 ? esc(a.message.substring(0, 50)) + '...' : esc(a.message)) : '';
        return `
        <tr>
            <td class="dash-id">${a.id}</td>
            <td title="${escAttr(a.name)}">${esc(a.name)}</td>
            <td title="${escAttr(a.email)}"><small>${esc(a.email)}</small></td>
            <td class="col-desc" title="${escAttr(a.message)}"><small>${shortMsg}</small></td>
            <td class="col-badge">${typeBadge}</td>
            <td>${a.report_id ? '<a href="#" class="text-info" data-appeal-report="' + a.report_id + '" data-appeal-hash="' + escAttr(a.infoHash) + '">#' + a.report_id + '</a>' : '—'}</td>
            <td class="hash-cell hash-copy" title="${t('js.reports.click_to_copy', {hash: a.infoHash})}" data-copy-hash="${a.infoHash}">${a.infoHash}</td>
            <td title="${escAttr(a.ip)}"><small>${esc(a.ip)}</small></td>
            <td class="col-badge">${badge}</td>
            <td class="dash-date"><small>${a.timestamp}</small></td>
            <td class="td-actions"><button class="btn btn-sm btn-outline-info" data-appeal-id="${a.id}"><i class="bi bi-three-dots"></i></button></td>
        </tr>`;
    }).join('');

    renderPagination(json.total, json.page, json.pages);
}

async function openReportFromAppeal(reportId, infoHash) {
    // Close appeal modal if open
    const appealModalEl = document.getElementById('appealModal');
    const appealModal = bootstrap.Modal.getInstance(appealModalEl);
    if (appealModal) appealModal.hide();

    // Try reports first, then archives
    let json = await apiCall('admin/fetch_reports&id=' + reportId);
    let reportSource = 'reports';
    if (!json.report) {
        json = await apiCall('admin/fetch_reports&id=' + reportId + '&source=archives');
        reportSource = 'archives';
    }
    if (!json.report) {
        showToast('error', t('js.reports.report_not_found', {id: reportId}));
        return;
    }

    // Temporarily set source to show the right buttons
    const prevSource = source;
    source = reportSource;
    currentReport = json.report;
    const r = currentReport;
    const isArchive = reportSource === 'archives';

    const messageHtml = r.add_message
        ? `<div class="report-message-block"><p class="msg-block-header">${t('js.reports.lbl_message')}</p><div class="report-message-content">${renderMessage(r.add_message)}</div></div>`
        : '';

    document.getElementById('modal-report-info').innerHTML = `
        <div class="report-info-grid">
            <p><strong>${t('js.reports.lbl_id')}:</strong> ${r.id}${isArchive ? ' <span class="badge bg-secondary badge-archived-sm">' + t('js.reports.archived') + '</span>' : ''}</p>
            <p><strong>${t('js.reports.lbl_name')}:</strong> ${esc(r.name)}</p>
            <p><strong>${t('js.reports.lbl_email')}:</strong> ${esc(r.email)}</p>
            <p><strong>${t('js.reports.lbl_company')}:</strong> ${esc(r.company)}</p>
            <p><strong>${t('js.reports.lbl_representative')}:</strong> ${esc(r.representative)}</p>
            <p><strong>${t('js.reports.lbl_object')}:</strong> ${esc(r.objectTitle)}</p>
            <p><strong>${t('js.reports.lbl_link')}:</strong> <a href="${escAttr(r.link)}" rel="noopener noreferrer" target="_blank" class="text-info">${esc(r.link)}</a></p>
            <p><strong>${t('js.reports.lbl_hash')}:</strong> <code class="text-info">${r.infoHash}</code></p>
            ${r.magnet_link ? '<p><strong>' + t('js.reports.lbl_magnet') + ':</strong></p><div class="magnet-wrapper"><code id="modal-magnet-code" class="text-info magnet-code">' + esc(r.magnet_link) + '</code><button type="button" data-copy-magnet class="btn btn-sm magnet-copy-btn" title="' + escAttr(t('js.reports.copy_magnet')) + '"><i class="bi bi-clipboard"></i></button></div>' : ''}
            <p><strong>${t('js.reports.lbl_ip')}:</strong> ${r.ip} &nbsp; <strong>${t('js.reports.lbl_date')}:</strong> ${r.timestamp}</p>
        </div>
        ${messageHtml}
    `;

    document.getElementById('modal-email-msg').value = '';
    document.getElementById('modal-alert').innerHTML = '';
    document.getElementById('modal-blacklist-warning').style.display = 'none';

    // Hide all action buttons — this is read-only from appeal context
    document.getElementById('modal-actions').style.display = 'none';
    document.getElementById('modal-email-section').style.display = 'none';
    document.getElementById('modal-email-msg').style.display = 'none';
    document.getElementById('modal-send-email').closest('.text-center').style.display = 'none';

    const modal = new bootstrap.Modal(document.getElementById('actionModal'));
    modal.show();

    // Restore source when modal closes
    document.getElementById('actionModal').addEventListener('hidden.bs.modal', function restoreSource() {
        source = prevSource;
        document.getElementById('actionModal').removeEventListener('hidden.bs.modal', restoreSource);
    });
}

async function openAppealModal(id) {
    const appealSource = source === 'appeal_archives' ? '&source=archives' : '';
    const json = await apiCall('admin/fetch_appeals&id=' + id + appealSource);
    if (!json.appeal) return;

    currentAppeal = json.appeal;
    const a = currentAppeal;

    const appealType = a.appeal_type || 'unblock';
    const typeBadge = appealType === 'block'
        ? '<span class="badge-table badge-type-block badge-type-lg">' + t('js.reports.block_request') + '</span>'
        : '<span class="badge-table badge-type-unblock badge-type-lg">' + t('js.reports.unblock_request') + '</span>';
    const statusLabels = { pending: t('js.reports.status_pending'), accepted: t('js.reports.status_accepted'), rejected: t('js.reports.status_rejected'), reviewed: t('js.reports.status_reviewed') };

    document.getElementById('appeal-modal-info').innerHTML = `
        <div class="report-info-grid">
            <p><strong>${t('js.reports.lbl_appeal_id')}:</strong> ${a.id} ${typeBadge}</p>
            <p><strong>${t('js.reports.lbl_name')}:</strong> ${esc(a.name)}</p>
            <p><strong>${t('js.reports.lbl_email')}:</strong> ${esc(a.email)}</p>
            <p><strong>${t('js.reports.lbl_report_no')}:</strong> ${a.report_id ? '<a href="#" class="text-info" data-appeal-report="' + a.report_id + '" data-appeal-hash="' + escAttr(a.infoHash) + '">#' + a.report_id + '</a>' : '—'}</p>
            <p><strong>${t('js.reports.lbl_hash')}:</strong> <code class="text-info">${a.infoHash}</code></p>
            <p><strong>${t('js.reports.lbl_ip')}:</strong> ${a.ip} &nbsp; <strong>${t('js.reports.lbl_date')}:</strong> ${a.timestamp}</p>
            <p><strong>${t('js.reports.lbl_status')}:</strong> ${statusLabels[a.status] || a.status}</p>
        </div>
        <div class="report-message-block">
            <p class="msg-block-header">${t('js.reports.appeal_message')}</p>
            <div class="report-message-content">${renderMessage(a.message)}</div>
        </div>
        ${a.admin_response ? '<div class="report-message-block admin-response"><p class="msg-block-header admin-response">' + t('js.reports.admin_response') + '</p><div class="report-message-content">' + renderMessage(a.admin_response) + '</div></div>' : ''}
    `;

    const isPending = a.status === 'pending';
    const isArchiveView = source === 'appeal_archives';

    const responseHr = document.getElementById('appeal-response-hr');
    const responseHeader = document.getElementById('appeal-response-header');

    if (isArchiveView) {
        // In appeal archives: show Restore button, hide Accept/Reject
        document.getElementById('appeal-modal-actions').style.display = '';
        document.getElementById('appeal-accept').style.display = 'none';
        document.getElementById('appeal-reject').style.display = 'none';
        document.getElementById('appeal-restore').style.display = '';
        document.getElementById('appeal-response-msg').style.display = 'none';
        if (responseHr) responseHr.style.display = 'none';
        if (responseHeader) responseHeader.style.display = 'none';
    } else if (isPending) {
        // Active pending appeal: show Accept/Reject, hide Restore
        document.getElementById('appeal-modal-actions').style.display = '';
        document.getElementById('appeal-accept').style.display = '';
        document.getElementById('appeal-reject').style.display = '';
        document.getElementById('appeal-restore').style.display = 'none';
        document.getElementById('appeal-response-msg').style.display = '';
        if (responseHr) responseHr.style.display = '';
        if (responseHeader) responseHeader.style.display = '';
    } else {
        // Active non-pending: hide all actions
        document.getElementById('appeal-modal-actions').style.display = 'none';
        document.getElementById('appeal-response-msg').style.display = 'none';
        if (responseHr) responseHr.style.display = 'none';
        if (responseHeader) responseHeader.style.display = 'none';
    }

    document.getElementById('appeal-response-msg').value = '';
    document.getElementById('appeal-modal-alert').innerHTML = '';

    const modal = new bootstrap.Modal(document.getElementById('appealModal'));
    modal.show();
}

async function handleResolveAppeal(status) {
    if (!currentAppeal) return;
    const confirmMsg = status === 'accepted' ? t('js.reports.confirm_accept_appeal') : t('js.reports.confirm_reject_appeal');
    if (!await confirmAction(confirmMsg)) return;

    const appealType = currentAppeal.appeal_type || 'unblock';
    const body = {
        id: currentAppeal.id,
        status: status,
        admin_response: document.getElementById('appeal-response-msg').value.trim(),
    };

    // Accepting an appeal automatically performs the requested action
    if (status === 'accepted') {
        if (appealType === 'block') {
            body.do_block = true;
        } else {
            body.unblock = true;
        }
    }

    const json = await apiCall('admin/resolve_appeal', 'POST', body);
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('appealModal')).hide();
        let extra = '';
        if (json.unblocked) extra += t('js.reports.extra_hash_unblocked');
        if (json.blocked) extra += t('js.reports.extra_hash_blocked');
        if (json.auto_closed > 0) extra += t('js.reports.extra_auto_closed', {n: json.auto_closed});
        showToast('success', json.message + extra);
        loadAppeals();
        loadAppealsBadge();
        refreshTrackerWarnings();
    } else {
        const el = document.getElementById('appeal-modal-alert');
        el.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || t('js.reports.error'))}</div>`;
        setTimeout(() => el.innerHTML = '', 5000);
    }
}

async function handleRestoreAppeal() {
    if (!currentAppeal) return;
    if (!await confirmAction(t('js.reports.confirm_restore_appeal'))) return;

    const json = await apiCall('admin/restore_appeal', 'POST', { id: currentAppeal.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('appealModal')).hide();
        showToast('success', json.message || t('js.reports.appeal_restored'));
        loadAppeals();
        loadAppealsBadge();
    } else {
        const el = document.getElementById('appeal-modal-alert');
        el.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || t('js.reports.error'))}</div>`;
        setTimeout(() => el.innerHTML = '', 5000);
    }
}

function handleDeletePermOpen() {
    if (!currentReport) return;
    
    // Hide actionModal
    const actionModalEl = document.getElementById('actionModal');
    const actionModal = bootstrap.Modal.getInstance(actionModalEl);
    if (actionModal) actionModal.hide();

    // Reset delete perm modal fields
    document.getElementById('del-password').value = '';
    document.getElementById('del-reason').value = '';
    document.getElementById('del-modal-alert').innerHTML = '';

    // Show deletePermModal
    const deletePermModalEl = document.getElementById('deletePermModal');
    const deletePermModal = new bootstrap.Modal(deletePermModalEl);
    deletePermModal.show();
}

async function handleDeletePermSubmit(e) {
    e.preventDefault();
    if (!currentReport) return;

    const password = document.getElementById('del-password').value;
    const reason = document.getElementById('del-reason').value.trim();
    const alertEl = document.getElementById('del-modal-alert');
    alertEl.innerHTML = '';

    const btn = e.target.querySelector('button[type="submit"]');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.reports.deleting');

    const payload = {
        id: currentReport.id,
        password: password,
        reason: reason,
        source: source
    };

    try {
        let json = await apiCall('admin/delete_permanently', 'POST', payload);

        if (json.captcha_required) {
            const token = await requestCaptchaToken('delete_permanently');
            if (!token) {
                // a widget/loader failure needs different advice than "you cancelled it"
                const msg = captchaUnavailable()
                    ? t('js.reports.captcha_could_not_load')
                    : t('js.reports.captcha_required');
                alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(msg)}</div>`;
                setTimeout(() => {
                    const alertDiv = alertEl.querySelector('.modal-alert-sm');
                    if (alertDiv) alertDiv.classList.add('alert-fade');
                }, 4500);
                setTimeout(() => alertEl.innerHTML = '', 5000);
                return;
            }
            payload['captcha_token'] = token;
            payload['g-recaptcha-response'] = token;
            json = await apiCall('admin/delete_permanently', 'POST', payload);
        }

        if (json.success) {
            const deletePermModalEl = document.getElementById('deletePermModal');
            const deletePermModal = bootstrap.Modal.getInstance(deletePermModalEl);
            if (deletePermModal) deletePermModal.hide();
            showToast('success', json.message || t('js.reports.report_deleted_permanently'));
            loadReports();
            refreshTrackerWarnings();
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || t('js.reports.error'))}</div>`;
            setTimeout(() => {
                const alertDiv = alertEl.querySelector('.modal-alert-sm');
                if (alertDiv) alertDiv.classList.add('alert-fade');
            }, 4500);
            setTimeout(() => alertEl.innerHTML = '', 5000);
        }
    } catch (err) {
        alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(t('js.reports.network_error_unexpected'))}</div>`;
        setTimeout(() => {
            const alertDiv = alertEl.querySelector('.modal-alert-sm');
            if (alertDiv) alertDiv.classList.add('alert-fade');
        }, 4500);
        setTimeout(() => alertEl.innerHTML = '', 5000);
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

// --- Tracker service: restart button + smart recommendations ----------------
// The whole cluster only exists when a service name is configured (see dashboard.php). Every helper
// below is a safe no-op when #tracker-svc is absent, so it can be called freely after any mutation.

let trackerWarnPopover = null;

function initTrackerService() {
    if (!document.getElementById('tracker-svc')) return;
    const restartBtn = document.getElementById('btn-restart-tracker');
    const form = document.getElementById('restart-tracker-form');
    if (restartBtn) {
        restartBtn.addEventListener('click', () => {
            const pw = document.getElementById('restart-password');
            if (pw) pw.value = '';
            document.getElementById('restart-modal-alert').innerHTML = '';
            // Mirror the live recommendations into the modal so the admin sees why they're restarting.
            renderRestartModalWarnings();
            new bootstrap.Modal(document.getElementById('restartTrackerModal')).show();
        });
    }
    if (form) form.addEventListener('submit', handleRestartTracker);

    // Reload (SIGHUP) — same confirm-with-password flow, but no downtime.
    const reloadBtn = document.getElementById('btn-reload-tracker');
    const reloadForm = document.getElementById('reload-tracker-form');
    if (reloadBtn) {
        reloadBtn.addEventListener('click', () => {
            const pw = document.getElementById('reload-password');
            if (pw) pw.value = '';
            document.getElementById('reload-modal-alert').innerHTML = '';
            renderReloadModalWarnings();
            new bootstrap.Modal(document.getElementById('reloadTrackerModal')).show();
        });
    }
    if (reloadForm) reloadForm.addEventListener('submit', handleReloadTracker);

    refreshTrackerWarnings();
    // Keep uptime-based advice fresh without a page reload (cheap cache-hit GET).
    setInterval(refreshTrackerWarnings, 120000);
    // Re-check when the admin returns to the tab.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshTrackerWarnings();
    });
}

let lastTrackerStatus = null;

async function refreshTrackerWarnings() {
    if (!document.getElementById('tracker-svc')) return;
    let json;
    try {
        json = await apiCall('admin/tracker_service_status');
    } catch {
        return;
    }
    if (!json || !json.enabled) return;
    lastTrackerStatus = json;
    applyTrackerWarnings(json);
}

let trackerWarnSig = null;

function applyTrackerWarnings(json) {
    const restartBtn = document.getElementById('btn-restart-tracker');
    const badge = document.getElementById('tracker-warn-badge');
    const countEl = document.getElementById('tracker-warn-count');
    if (!restartBtn || !badge) return;

    const level = json.level || 'none';
    const items = json.items || [];

    // Glow + disabled state are idempotent — always keep them in sync with the latest status.
    restartBtn.classList.remove('tracker-glow-warn', 'tracker-glow-danger');
    if (level === 'warn') restartBtn.classList.add('tracker-glow-warn');
    else if (level === 'danger') restartBtn.classList.add('tracker-glow-danger');
    restartBtn.disabled = json.exec_available === false;
    restartBtn.title = restartBtn.disabled
        ? t('js.reports.restart_unavailable')
        : (t('js.reports.restart_tracker_title') + (json.service ? ' (' + json.service + ')' : ''));

    // The Reload button shares the same exec-availability gate.
    const reloadBtn = document.getElementById('btn-reload-tracker');
    if (reloadBtn) {
        reloadBtn.disabled = json.exec_available === false;
        reloadBtn.title = reloadBtn.disabled
            ? t('js.reports.reload_unavailable')
            : (t('js.reports.reload_tracker_title') + (json.service ? ' — ' + json.service : ''));
    }

    // Only rebuild the chip + popover when something actually changed, so a background refresh
    // doesn't dispose a popover the admin is currently reading.
    const sig = level + '|' + items.map(it => it.level + ':' + it.text).join('|');
    if (sig === trackerWarnSig) return;
    trackerWarnSig = sig;

    if (trackerWarnPopover) { trackerWarnPopover.dispose(); trackerWarnPopover = null; }

    if (!items.length) {
        badge.classList.add('d-hidden');
        return;
    }

    badge.classList.remove('d-hidden', 'warn', 'danger');
    badge.classList.add(level === 'danger' ? 'danger' : 'warn');
    countEl.textContent = items.length;

    trackerWarnPopover = new bootstrap.Popover(badge, {
        html: true,
        title: t('js.reports.restart_recommendations'),
        content: trackerWarnListHtml(items),
        trigger: 'hover focus',
        placement: 'bottom',
        container: 'body',
        customClass: 'tracker-warn-popover',
    });
}

function trackerWarnListHtml(items) {
    return '<ul class="tracker-warn-ul">' + items.map(it =>
        `<li class="tw-${it.level === 'danger' ? 'danger' : 'warn'}"><i class="bi bi-dot"></i>${esc(it.text)}</li>`
    ).join('') + '</ul>';
}

function renderRestartModalWarnings() {
    const el = document.getElementById('restart-warn-list');
    if (!el) return;
    const items = (lastTrackerStatus && lastTrackerStatus.items) || [];
    el.innerHTML = items.length
        ? '<div class="tracker-modal-warnbox">' + trackerWarnListHtml(items) + '</div>'
        : '';
}

function renderReloadModalWarnings() {
    const el = document.getElementById('reload-warn-list');
    if (!el) return;
    const items = (lastTrackerStatus && lastTrackerStatus.items) || [];
    el.innerHTML = items.length
        ? '<div class="tracker-modal-warnbox">' + trackerWarnListHtml(items) + '</div>'
        : '';
}

async function handleRestartTracker(e) {
    e.preventDefault();
    const pw = document.getElementById('restart-password').value;
    const alertEl = document.getElementById('restart-modal-alert');
    alertEl.innerHTML = '';

    const btn = e.target.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.reports.restarting');

    try {
        const json = await apiCall('admin/restart_tracker', 'POST', { password: pw });
        if (json.success) {
            const modalEl = document.getElementById('restartTrackerModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            showToast('success', json.message || t('js.reports.tracker_restarted'));
            refreshTrackerWarnings();
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || t('js.reports.restart_failed'))}</div>`;
            setTimeout(() => {
                const alertDiv = alertEl.querySelector('.modal-alert-sm');
                if (alertDiv) alertDiv.classList.add('alert-fade');
            }, 6500);
            setTimeout(() => alertEl.innerHTML = '', 7000);
        }
    } catch {
        alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(t('js.reports.network_error'))}</div>`;
        setTimeout(() => alertEl.innerHTML = '', 5000);
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

async function handleReloadTracker(e) {
    e.preventDefault();
    const pw = document.getElementById('reload-password').value;
    const alertEl = document.getElementById('reload-modal-alert');
    alertEl.innerHTML = '';

    const btn = e.target.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + t('js.reports.reloading');

    try {
        const json = await apiCall('admin/reload_tracker', 'POST', { password: pw });
        if (json.success) {
            const modalEl = document.getElementById('reloadTrackerModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            showToast('success', json.message || t('js.reports.tracker_blacklist_reloaded'));
            refreshTrackerWarnings();
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || t('js.reports.reload_failed'))}</div>`;
            setTimeout(() => {
                const alertDiv = alertEl.querySelector('.modal-alert-sm');
                if (alertDiv) alertDiv.classList.add('alert-fade');
            }, 6500);
            setTimeout(() => alertEl.innerHTML = '', 7000);
        }
    } catch {
        alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(t('js.reports.network_error'))}</div>`;
        setTimeout(() => alertEl.innerHTML = '', 5000);
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}
