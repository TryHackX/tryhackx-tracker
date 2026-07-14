const API_BASE = document.body.dataset.apiBase;

let captchaWidgetId = null;
let captchaResolve = null;

window.onRecaptchaLoad = function() {};

function showCaptchaModal() {
    return new Promise((resolve) => {
        const overlay = document.getElementById('captcha-overlay');
        const container = document.getElementById('captcha-widget');
        if (!overlay || !container || typeof grecaptcha === 'undefined' || typeof RECAPTCHA_SITEKEY === 'undefined') {
            resolve('');
            return;
        }
        captchaResolve = resolve;
        if (captchaWidgetId !== null) {
            grecaptcha.reset(captchaWidgetId);
        } else {
            captchaWidgetId = grecaptcha.render(container, {
                sitekey: RECAPTCHA_SITEKEY,
                theme: 'dark',
                callback: (token) => {
                    overlay.classList.remove('show');
                    if (captchaResolve) { captchaResolve(token); captchaResolve = null; }
                },
            });
        }
        overlay.classList.add('show');
    });
}

document.addEventListener('click', (e) => {
    const overlay = document.getElementById('captcha-overlay');
    if (overlay && e.target === overlay) {
        overlay.classList.remove('show');
        if (captchaResolve) { captchaResolve(''); captchaResolve = null; }
    }
});


let currentPage = 1;
let sortStack = [{ col: 'date', dir: 'desc' }];
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
            (source === 'appeals' || source === 'appeal_archives') ? loadAppeals() : loadReports();
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
        }, 300);
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
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 table-empty-state">No reports found.</td></tr>';
        totalEl.textContent = 'Total: 0';
        document.getElementById('pagination').innerHTML = '';
        return;
    }

    totalEl.textContent = 'Total: ' + json.total;

    tbody.innerHTML = json.reports.map(r => {
        let statusBadge;
        if (r.blocked) {
            statusBadge = '<span class="badge-table badge-blocked">Blocked</span>';
        } else if (r.checked) {
            statusBadge = '<span class="badge-table badge-reviewed">Reviewed</span>';
        } else {
            statusBadge = '<span class="badge-table badge-pending">Pending</span>';
        }
        return `
        <tr>
            <td>${r.id}</td>
            <td>${esc(r.name)}</td>
            <td><small>${esc(r.email)}</small></td>
            <td class="editable-cell" ondblclick="inlineEdit(this, ${r.id}, 'company')">${esc(r.company)}</td>
            <td class="editable-cell" ondblclick="inlineEdit(this, ${r.id}, 'representative')">${esc(r.representative)}</td>
            <td>${esc(r.objectTitle)}</td>
            <td class="hash-cell hash-copy" title="Click to copy: ${r.infoHash}" onclick="copyHash(this, '${r.infoHash}')">${r.infoHash}</td>
            <td><small>${esc(r.ip)}</small></td>
            <td class="col-badge">${statusBadge}</td>
            <td><small>${r.timestamp}</small></td>
            <td class="td-actions"><button class="btn btn-sm btn-outline-info" onclick="openModal(${r.id})"><i class="bi bi-three-dots"></i></button></td>
        </tr>`;
    }).join('');

    renderPagination(json.total, json.page, json.pages);
}

function renderPagination(total, page, pages) {
    const el = document.getElementById('pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = `
        <button ${page <= 1 ? 'disabled' : ''} onclick="goPage(${page - 1})"><i class="bi bi-chevron-left"></i> Prev</button>
        <span>Page ${page} of ${pages}</span>
        <button ${page >= pages ? 'disabled' : ''} onclick="goPage(${page + 1})">Next <i class="bi bi-chevron-right"></i></button>
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
        ? `<div class="report-message-block"><p class="msg-block-header">Message</p><div class="report-message-content">${renderMessage(r.add_message)}</div></div>`
        : '';

    document.getElementById('modal-report-info').innerHTML = `
        <div class="report-info-grid">
            <p><strong>ID:</strong> ${r.id}</p>
            <p><strong>Name:</strong> ${esc(r.name)}</p>
            <p><strong>Email:</strong> ${esc(r.email)}</p>
            <p><strong>Company:</strong> ${esc(r.company)}</p>
            <p><strong>Representative:</strong> ${esc(r.representative)}</p>
            <p><strong>Object:</strong> ${esc(r.objectTitle)}</p>
            <p><strong>Link:</strong> <a href="${esc(r.link)}" target="_blank" class="text-info">${esc(r.link)}</a></p>
            <p><strong>Hash:</strong> <code class="text-info">${r.infoHash}</code></p>
            ${r.magnet_link ? '<p><strong>Magnet:</strong></p><div class="magnet-wrapper"><code id="modal-magnet-code" class="text-info magnet-code">' + esc(r.magnet_link) + '</code><button type="button" onclick="copyMagnet(this)" class="btn btn-sm magnet-copy-btn" title="Copy magnet link"><i class="bi bi-clipboard"></i></button></div>' : ''}
            <p><strong>IP:</strong> ${r.ip} &nbsp; <strong>Date:</strong> ${r.timestamp}</p>
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
                    showToast('success', 'Review notification sent to reporter');
                }
            }
        });
    }
}

async function handleBlock() {
    if (!currentReport) return;
    const isArchive = source === 'archives';
    const msg = isArchive
        ? 'Block this hash in the archive? The reporter will be notified.'
        : 'Block this hash and archive the report? The reporter will be notified.';
    if (!await confirmAction(msg)) return;
    const endpoint = isArchive ? 'admin/block_archived' : 'admin/block_hash';
    const json = await apiCall(endpoint, 'POST', { id: currentReport.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
        let toastMsg = json.message || 'Hash blocked';
        if (json.auto_closed > 0) toastMsg += ' (' + json.auto_closed + ' appeal' + (json.auto_closed > 1 ? 's' : '') + ' auto-closed)';
        showToast('success', toastMsg);
        if (json.blacklist_warning) {
            showToast('error', json.blacklist_warning);
        }
        loadReports();
        refreshTrackerWarnings();
    } else {
        showModalAlert('error', json.error || 'Error');
    }
}

async function handleUnblock() {
    if (!currentReport) return;
    if (!await confirmAction('Are you sure you want to unblock this hash?')) return;
    const json = await apiCall('admin/unblock_hash', 'POST', { id: currentReport.id });
    if (json.success) {
        let unblockMsg = json.message || 'Hash unblocked';
        if (json.auto_closed > 0) unblockMsg += ' (' + json.auto_closed + ' appeal' + (json.auto_closed > 1 ? 's' : '') + ' auto-closed)';
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
        showModalAlert('error', json.error || 'Error');
    }
}

async function handleArchive() {
    if (!currentReport) return;
    if (!await confirmAction('Archive this report without blocking? The reporter will be notified that the case is closed.')) return;
    const json = await apiCall('admin/delete_report', 'POST', { id: currentReport.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
        showToast('success', 'Report archived');
        loadReports();
    } else {
        showModalAlert('error', json.error || 'Error');
    }
}

async function handleRestore() {
    if (!currentReport) return;
    if (!await confirmAction('Restore this report to active reports?')) return;
    const json = await apiCall('admin/restore_report', 'POST', { id: currentReport.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
        showToast('success', 'Report restored to active');
        loadReports();
        refreshTrackerWarnings();
    } else {
        showModalAlert('error', json.error || 'Error');
    }
}

async function handleSendEmail() {
    if (!currentReport) return;
    const msg = document.getElementById('modal-email-msg').value.trim();
    if (!msg) {
        showModalAlert('error', 'Please enter a message');
        return;
    }
    const json = await apiCall('admin/send_email', 'POST', {
        id: currentReport.id,
        message: msg,
    });
    if (json.success) {
        showToast('success', 'Email sent');
        document.getElementById('modal-email-msg').value = '';
    } else {
        showModalAlert('error', json.error || 'Failed to send email');
    }
}

async function handleArchiveAll() {
    if (!await confirmAction('Archive all reviewed reports?')) return;
    const json = await apiCall('admin/delete_all', 'POST');
    if (json.success) {
        showToast('success', 'Archived ' + (json.archived || 0) + ' reports');
        loadReports();
    }
}

async function handleLogout() {
    await apiCall('admin/logout', 'POST');
    window.location.reload();
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
                <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
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

    if (isAppeal) {
        thead.innerHTML = `
            <th class="sortable" data-sort="id">ID <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="email">Email <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th>Description</th>
            <th class="sortable col-badge" data-sort="type">Type <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="report">Report <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="hash">Info Hash <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="ip">IP <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable col-badge" data-sort="status">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="date">Date <i class="bi bi-arrow-down sort-icon active"></i></th>
            <th class="th-actions">Actions</th>
        `;
    } else {
        thead.innerHTML = `
            <th class="sortable" data-sort="id">ID <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="email">Email <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="company">Company <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="representative">Entity <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="object">Object <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="hash">Info Hash <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="ip">IP <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable col-badge" data-sort="blocked">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
            <th class="sortable" data-sort="date">Date <i class="bi bi-arrow-down sort-icon active"></i></th>
            <th class="th-actions">Actions</th>
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
            (source === 'appeals' || source === 'appeal_archives') ? loadAppeals() : loadReports();
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
        { value: 'all', text: 'All statuses' },
        { value: 'pending', text: 'Awaiting Review' },
        { value: 'reviewed', text: 'Reviewed' },
        { value: 'blocked', text: 'Blocked' },
    ];
    const appealOptions = [
        { value: 'all', text: 'All statuses' },
        { value: 'pending', text: 'Pending' },
        { value: 'accepted', text: 'Accepted' },
        { value: 'rejected', text: 'Rejected' },
    ];
    const appealArchiveOptions = [
        { value: 'all', text: 'All statuses' },
        { value: 'accepted', text: 'Accepted' },
        { value: 'rejected', text: 'Rejected' },
    ];

    let options;
    if (src === 'appeal_archives') {
        options = appealArchiveOptions;
    } else if (src === 'appeals') {
        options = appealOptions;
    } else {
        options = reportOptions;
    }

    filter.innerHTML = options.map(o => `<option value="${o.value}">${o.text}</option>`).join('');
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

function copyHash(td, hash) {
    navigator.clipboard.writeText(hash).then(() => {
        const orig = td.textContent;
        td.textContent = 'Copied!';
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
            showToast('success', field.charAt(0).toUpperCase() + field.slice(1) + ' updated');
        } else {
            td.textContent = original;
            showToast('error', json.error || 'Update failed');
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
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 table-empty-state">No appeals found.</td></tr>';
        totalEl.textContent = 'Total: 0';
        document.getElementById('pagination').innerHTML = '';
        return;
    }

    totalEl.textContent = 'Total: ' + json.total;

    tbody.innerHTML = json.appeals.map(a => {
        const statusMap = { pending: 'badge-pending', accepted: 'badge-reviewed', rejected: 'badge-blocked', reviewed: 'badge-reviewed' };
        const labelMap = { pending: 'Pending', accepted: 'Accepted', rejected: 'Rejected', reviewed: 'Reviewed' };
        const badge = `<span class="badge-table ${statusMap[a.status] || 'badge-pending'}">${labelMap[a.status] || a.status}</span>`;
        const typeBadge = a.appeal_type === 'block'
            ? '<span class="badge-table badge-type-block">Block</span>'
            : '<span class="badge-table badge-type-unblock">Unblock</span>';
        const shortMsg = a.message ? (a.message.length > 50 ? esc(a.message.substring(0, 50)) + '...' : esc(a.message)) : '';
        return `
        <tr>
            <td>${a.id}</td>
            <td>${esc(a.name)}</td>
            <td><small>${esc(a.email)}</small></td>
            <td class="col-desc"><small title="${esc(a.message)}">${shortMsg}</small></td>
            <td class="col-badge">${typeBadge}</td>
            <td>${a.report_id ? '<a href="#" class="text-info" onclick="openReportFromAppeal(' + a.report_id + ', \'' + esc(a.infoHash) + '\');return false;">#' + a.report_id + '</a>' : '—'}</td>
            <td class="hash-cell hash-copy" title="Click to copy: ${a.infoHash}" onclick="copyHash(this, '${a.infoHash}')">${a.infoHash}</td>
            <td><small>${esc(a.ip)}</small></td>
            <td class="col-badge">${badge}</td>
            <td><small>${a.timestamp}</small></td>
            <td class="td-actions"><button class="btn btn-sm btn-outline-info" onclick="openAppealModal(${a.id})"><i class="bi bi-three-dots"></i></button></td>
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
        showToast('error', 'Report #' + reportId + ' not found');
        return;
    }

    // Temporarily set source to show the right buttons
    const prevSource = source;
    source = reportSource;
    currentReport = json.report;
    const r = currentReport;
    const isArchive = reportSource === 'archives';

    const messageHtml = r.add_message
        ? `<div class="report-message-block"><p class="msg-block-header">Message</p><div class="report-message-content">${renderMessage(r.add_message)}</div></div>`
        : '';

    document.getElementById('modal-report-info').innerHTML = `
        <div class="report-info-grid">
            <p><strong>ID:</strong> ${r.id}${isArchive ? ' <span class="badge bg-secondary badge-archived-sm">Archived</span>' : ''}</p>
            <p><strong>Name:</strong> ${esc(r.name)}</p>
            <p><strong>Email:</strong> ${esc(r.email)}</p>
            <p><strong>Company:</strong> ${esc(r.company)}</p>
            <p><strong>Representative:</strong> ${esc(r.representative)}</p>
            <p><strong>Object:</strong> ${esc(r.objectTitle)}</p>
            <p><strong>Link:</strong> <a href="${esc(r.link)}" target="_blank" class="text-info">${esc(r.link)}</a></p>
            <p><strong>Hash:</strong> <code class="text-info">${r.infoHash}</code></p>
            ${r.magnet_link ? '<p><strong>Magnet:</strong></p><div class="magnet-wrapper"><code id="modal-magnet-code" class="text-info magnet-code">' + esc(r.magnet_link) + '</code><button type="button" onclick="copyMagnet(this)" class="btn btn-sm magnet-copy-btn" title="Copy magnet link"><i class="bi bi-clipboard"></i></button></div>' : ''}
            <p><strong>IP:</strong> ${r.ip} &nbsp; <strong>Date:</strong> ${r.timestamp}</p>
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
        ? '<span class="badge-table badge-type-block badge-type-lg">Block Request</span>'
        : '<span class="badge-table badge-type-unblock badge-type-lg">Unblock Request</span>';

    document.getElementById('appeal-modal-info').innerHTML = `
        <div class="report-info-grid">
            <p><strong>Appeal ID:</strong> ${a.id} ${typeBadge}</p>
            <p><strong>Name:</strong> ${esc(a.name)}</p>
            <p><strong>Email:</strong> ${esc(a.email)}</p>
            <p><strong>Report #:</strong> ${a.report_id ? '<a href="#" class="text-info" onclick="openReportFromAppeal(' + a.report_id + ', \'' + esc(a.infoHash) + '\');return false;">#' + a.report_id + '</a>' : '—'}</p>
            <p><strong>Hash:</strong> <code class="text-info">${a.infoHash}</code></p>
            <p><strong>IP:</strong> ${a.ip} &nbsp; <strong>Date:</strong> ${a.timestamp}</p>
            <p><strong>Status:</strong> ${a.status}</p>
        </div>
        <div class="report-message-block">
            <p class="msg-block-header">Appeal Message</p>
            <div class="report-message-content">${renderMessage(a.message)}</div>
        </div>
        ${a.admin_response ? '<div class="report-message-block admin-response"><p class="msg-block-header admin-response">Admin Response</p><div class="report-message-content">' + renderMessage(a.admin_response) + '</div></div>' : ''}
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
    const label = status === 'accepted' ? 'accept' : 'reject';
    if (!await confirmAction(`Are you sure you want to ${label} this appeal?`)) return;

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
        if (json.unblocked) extra += ' — hash unblocked';
        if (json.blocked) extra += ' — hash blocked';
        if (json.auto_closed > 0) extra += ' (' + json.auto_closed + ' related appeal' + (json.auto_closed > 1 ? 's' : '') + ' auto-closed)';
        showToast('success', json.message + extra);
        loadAppeals();
        loadAppealsBadge();
        refreshTrackerWarnings();
    } else {
        const el = document.getElementById('appeal-modal-alert');
        el.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || 'Error')}</div>`;
        setTimeout(() => el.innerHTML = '', 5000);
    }
}

async function handleRestoreAppeal() {
    if (!currentAppeal) return;
    if (!await confirmAction('Restore this appeal to active reviews? The appellant will be notified.')) return;

    const json = await apiCall('admin/restore_appeal', 'POST', { id: currentAppeal.id });
    if (json.success) {
        bootstrap.Modal.getInstance(document.getElementById('appealModal')).hide();
        showToast('success', json.message || 'Appeal restored');
        loadAppeals();
        loadAppealsBadge();
    } else {
        const el = document.getElementById('appeal-modal-alert');
        el.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || 'Error')}</div>`;
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
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...';

    const payload = {
        id: currentReport.id,
        password: password,
        reason: reason,
        source: source
    };

    try {
        let json = await apiCall('admin/delete_permanently', 'POST', payload);

        if (json.captcha_required) {
            const token = await showCaptchaModal();
            if (!token) {
                alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">CAPTCHA verification required.</div>`;
                setTimeout(() => {
                    const alertDiv = alertEl.querySelector('.modal-alert-sm');
                    if (alertDiv) alertDiv.classList.add('alert-fade');
                }, 4500);
                setTimeout(() => alertEl.innerHTML = '', 5000);
                return;
            }
            payload['g-recaptcha-response'] = token;
            json = await apiCall('admin/delete_permanently', 'POST', payload);
        }

        if (json.success) {
            const deletePermModalEl = document.getElementById('deletePermModal');
            const deletePermModal = bootstrap.Modal.getInstance(deletePermModalEl);
            if (deletePermModal) deletePermModal.hide();
            showToast('success', json.message || 'Report deleted permanently');
            loadReports();
            refreshTrackerWarnings();
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || 'Error')}</div>`;
            setTimeout(() => {
                const alertDiv = alertEl.querySelector('.modal-alert-sm');
                if (alertDiv) alertDiv.classList.add('alert-fade');
            }, 4500);
            setTimeout(() => alertEl.innerHTML = '', 5000);
        }
    } catch (err) {
        alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">Network error or unexpected response.</div>`;
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
        ? 'Restart unavailable: PHP exec() is disabled on the server'
        : ('Restart the tracker service' + (json.service ? ' (' + json.service + ')' : ''));

    // The Reload button shares the same exec-availability gate.
    const reloadBtn = document.getElementById('btn-reload-tracker');
    if (reloadBtn) {
        reloadBtn.disabled = json.exec_available === false;
        reloadBtn.title = reloadBtn.disabled
            ? 'Reload unavailable: PHP exec() is disabled on the server'
            : ('Reload the tracker blacklist (SIGHUP, no downtime)' + (json.service ? ' — ' + json.service : ''));
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
        title: 'Restart recommendations',
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
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Restarting...';

    try {
        const json = await apiCall('admin/restart_tracker', 'POST', { password: pw });
        if (json.success) {
            const modalEl = document.getElementById('restartTrackerModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            showToast('success', json.message || 'Tracker restarted');
            refreshTrackerWarnings();
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || 'Restart failed')}</div>`;
            setTimeout(() => {
                const alertDiv = alertEl.querySelector('.modal-alert-sm');
                if (alertDiv) alertDiv.classList.add('alert-fade');
            }, 6500);
            setTimeout(() => alertEl.innerHTML = '', 7000);
        }
    } catch {
        alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">Network error.</div>`;
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
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Reloading...';

    try {
        const json = await apiCall('admin/reload_tracker', 'POST', { password: pw });
        if (json.success) {
            const modalEl = document.getElementById('reloadTrackerModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            showToast('success', json.message || 'Tracker blacklist reloaded');
            refreshTrackerWarnings();
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">${esc(json.error || 'Reload failed')}</div>`;
            setTimeout(() => {
                const alertDiv = alertEl.querySelector('.modal-alert-sm');
                if (alertDiv) alertDiv.classList.add('alert-fade');
            }, 6500);
            setTimeout(() => alertEl.innerHTML = '', 7000);
        }
    } catch {
        alertEl.innerHTML = `<div class="alert alert-danger py-1 px-2 modal-alert-sm">Network error.</div>`;
        setTimeout(() => alertEl.innerHTML = '', 5000);
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}
