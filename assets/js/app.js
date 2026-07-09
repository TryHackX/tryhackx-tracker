// === CAPTCHA Modal System ===
let captchaWidgetId = null;
let captchaResolve = null;

function onRecaptchaLoad() {
    // Widget rendered on demand inside modal
}

function showCaptchaModal() {
    return new Promise((resolve) => {
        const overlay = document.getElementById('captcha-overlay');
        const container = document.getElementById('captcha-widget');
        if (!overlay || !container || typeof grecaptcha === 'undefined' || typeof RECAPTCHA_SITEKEY === 'undefined') {
            resolve('');
            return;
        }
        captchaResolve = resolve;
        // Reset widget if already rendered
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

// Close modal on overlay click (outside the box)
document.addEventListener('click', (e) => {
    const overlay = document.getElementById('captcha-overlay');
    if (e.target === overlay) {
        overlay.classList.remove('show');
        if (captchaResolve) { captchaResolve(''); captchaResolve = null; }
    }
});

async function fetchWithCaptcha(endpoint, data) {
    const body = JSON.stringify(data);
    let res, json;
    try {
        res = await fetch(APP_API + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body,
        });
        json = await res.json();
    } catch {
        return { error: 'Server returned an invalid response. The request may be too large.' };
    }

    if (json.captcha_required) {
        const token = await showCaptchaModal();
        if (!token) return { error: 'CAPTCHA cancelled' };
        data['g-recaptcha-response'] = token;
        try {
            const res2 = await fetch(APP_API + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(data),
            });
            return await res2.json();
        } catch {
            return { error: 'Server returned an invalid response after CAPTCHA.' };
        }
    }
    return json;
}

function startCooldown(btn, seconds) {
    const originalText = btn.textContent;
    let remaining = seconds;
    btn.disabled = true;
    btn.textContent = `Wait (${remaining}s)`;
    const interval = setInterval(() => {
        remaining--;
        if (remaining <= 0) {
            clearInterval(interval);
            btn.disabled = false;
            btn.textContent = originalText;
        } else {
            btn.textContent = `Wait (${remaining}s)`;
        }
    }, 1000);
}

// Shared handling for a failed form submission (report / appeal / status-appeal all behaved
// identically here). `messages` maps error codes to friendly text; `rate_limit` has a default.
// Highlights any `fields` the server flagged and starts the resubmit cooldown.
function showFormSubmitError(form, alert, btn, json, messages = {}) {
    const map = { rate_limit: 'Rate limit exceeded. Try again in an hour.', ...messages };
    const code = json && json.error;
    alert.className = 'alert alert-error show';
    alert.textContent = (code && map[code]) ? map[code] : (code || 'An error occurred.');
    if (json && Array.isArray(json.fields)) {
        json.fields.forEach(f => {
            const input = form.querySelector(`[name="${f}"]`);
            if (input) input.closest('.form-group')?.classList.add('has-error');
        });
    }
    startCooldown(btn, 5);
}

// Shared handling for a network/transport failure on form submit.
function showFormNetworkError(alert, btn) {
    alert.className = 'alert alert-error show';
    alert.textContent = 'Network error. Please try again.';
    startCooldown(btn, 5);
}

document.addEventListener('DOMContentLoaded', () => {
    const reportForm = document.getElementById('report-form');
    if (reportForm) {
        reportForm.addEventListener('submit', handleReportSubmit);
    }

    const statusForm = document.getElementById('status-form');
    if (statusForm) {
        statusForm.addEventListener('submit', handleStatusCheck);
    }

    // Clear validation errors on input
    document.querySelectorAll('.form-group input, .form-group textarea').forEach(el => {
        el.addEventListener('input', () => {
            el.closest('.form-group')?.classList.remove('has-error');
        });
    });

    // Real-time hex validation for infoHash
    const hashInput = document.getElementById('infoHash');
    const hashHint = document.getElementById('hash-hint');
    if (hashInput && hashHint) {
        hashInput.addEventListener('input', () => {
            const v = hashInput.value;
            if (!v) { hashHint.textContent = ''; hashHint.style.color = ''; return; }
            const nonHex = v.replace(/[a-fA-F0-9]/g, '');
            if (nonHex.length > 0) {
                hashHint.textContent = '— contains non-hex characters';
                hashHint.style.color = 'var(--error)';
            } else if (v.length < 40) {
                hashHint.textContent = '— ' + v.length + '/40 characters';
                hashHint.style.color = 'var(--warning)';
            } else {
                hashHint.textContent = '— valid';
                hashHint.style.color = 'var(--success)';
            }
            validateMagnetCross();
        });
    }

    // Magnet link cross-validation
    const magnetInput = document.getElementById('magnetLink');
    const magnetHint = document.getElementById('magnet-hint');
    if (magnetInput && magnetHint) {
        magnetInput.addEventListener('input', () => {
            validateMagnetCross();
        });
    }

    // Block check form
    const blockCheckForm = document.getElementById('block-check-form');
    if (blockCheckForm) {
        blockCheckForm.addEventListener('submit', handleBlockCheck);
    }

    // Appeal form
    const appealForm = document.getElementById('appeal-form');
    if (appealForm) {
        appealForm.addEventListener('submit', handleAppealSubmit);
    }

    // Appeal character counter
    const appealMsg = document.getElementById('appeal-message');
    const appealCounter = document.getElementById('appeal-counter');
    if (appealMsg && appealCounter) {
        const max = parseInt(appealMsg.dataset.maxlength || '2000');
        appealMsg.addEventListener('input', () => {
            appealCounter.textContent = appealMsg.value.length + '/' + max;
            appealCounter.style.color = appealMsg.value.length > max * 0.9 ? 'var(--warning)' : '';
            if (appealMsg.value.length >= max) appealCounter.style.color = 'var(--error)';
        });
    }

    // Status appeal form (Check Report Status page)
    const statusAppealForm = document.getElementById('status-appeal-form');
    if (statusAppealForm) {
        statusAppealForm.addEventListener('submit', handleStatusAppealSubmit);
    }

    // Status appeal character counter
    const statusAppealMsg = document.getElementById('status-appeal-message');
    const statusAppealCounter = document.getElementById('status-appeal-counter');
    if (statusAppealMsg && statusAppealCounter) {
        const max = parseInt(statusAppealMsg.dataset.maxlength || '2000');
        statusAppealMsg.addEventListener('input', () => {
            statusAppealCounter.textContent = statusAppealMsg.value.length + '/' + max;
            statusAppealCounter.style.color = statusAppealMsg.value.length > max * 0.9 ? 'var(--warning)' : '';
            if (statusAppealMsg.value.length >= max) statusAppealCounter.style.color = 'var(--error)';
        });
    }

    // Character counter for message textarea
    const msgArea = document.getElementById('add_message');
    const msgCounter = document.getElementById('msg-counter');
    if (msgArea && msgCounter) {
        const max = parseInt(msgArea.dataset.maxlength || '2000');
        msgCounter.textContent = msgArea.value.length + '/' + max;
        msgArea.addEventListener('input', () => {
            msgCounter.textContent = msgArea.value.length + '/' + max;
            msgCounter.style.color = msgArea.value.length > max * 0.9 ? 'var(--warning)' : '';
            if (msgArea.value.length >= max) msgCounter.style.color = 'var(--error)';
        });
    }

    // Transparency page — multi-sort via clickable headers
    if (document.getElementById('trans-table')) {
        updateTransSortIcons();
        loadTransparency();
        document.querySelectorAll('.trans-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.sort;
                const exclusive = th.dataset.exclusive;
                const idx = transSortStack.findIndex(s => s.col === col);

                // Remove mutually exclusive column if present
                if (exclusive) {
                    const exIdx = transSortStack.findIndex(s => s.col === exclusive);
                    if (exIdx !== -1) transSortStack.splice(exIdx, 1);
                }

                if (idx === -1) {
                    transSortStack.push({ col, dir: 'asc' });
                } else if (transSortStack[idx].dir === 'asc') {
                    transSortStack[idx].dir = 'desc';
                } else {
                    transSortStack.splice(idx, 1);
                }
                updateTransSortIcons();
                loadTransparency(1);
            });
        });
    }

    // Initialize tracker stats page or homepage widget
    initTrackerStats();
});

// Copy text helper
function copyText(btn, sourceId) {
    const el = document.getElementById(sourceId);
    const text = el ? el.textContent.trim() : '';
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        btn.style.color = 'var(--success, #4caf50)';
        setTimeout(() => { btn.innerHTML = orig; btn.style.color = ''; }, 1500);
    });
}

// Extract hash from magnet link
function extractHashFromMagnet(magnet) {
    const m = magnet.match(/urn:btih:([a-fA-F0-9]{40})/i);
    if (m) return m[1].toLowerCase();
    // Base32 encoded hash (32 chars)
    const b32 = magnet.match(/urn:btih:([A-Z2-7]{32})/i);
    if (b32) {
        try {
            const decoded = base32ToHex(b32[1].toUpperCase());
            if (decoded && decoded.length === 40) return decoded.toLowerCase();
        } catch {}
    }
    return null;
}

// Mirror of PHP `base32ToHex()` in includes/functions.php — client-side magnet validation only;
// the server re-validates authoritatively. Keep both in sync if the decoding logic changes.
function base32ToHex(base32) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of base32) {
        const val = alphabet.indexOf(c);
        if (val === -1) return null;
        bits += val.toString(2).padStart(5, '0');
    }
    let hex = '';
    for (let i = 0; i + 4 <= bits.length; i += 4) {
        hex += parseInt(bits.substr(i, 4), 2).toString(16);
    }
    return hex;
}

function validateMagnetCross() {
    const magnetInput = document.getElementById('magnetLink');
    const magnetHint = document.getElementById('magnet-hint');
    const hashInput = document.getElementById('infoHash');
    if (!magnetInput || !magnetHint || !hashInput) return;

    const magnet = magnetInput.value.trim();
    if (!magnet) {
        magnetHint.textContent = '';
        magnetHint.style.color = '';
        magnetInput.closest('.form-group')?.classList.remove('has-error');
        return;
    }

    if (!magnet.startsWith('magnet:?')) {
        magnetHint.textContent = '— must start with magnet:?';
        magnetHint.style.color = 'var(--error)';
        return;
    }

    if (!/[?&]xt=urn:btih:/i.test(magnet)) {
        magnetHint.textContent = '— missing xt=urn:btih: parameter';
        magnetHint.style.color = 'var(--error)';
        return;
    }

    const extractedHash = extractHashFromMagnet(magnet);
    if (!extractedHash) {
        magnetHint.textContent = '— invalid hash format (expected 40 hex or 32 base32 chars)';
        magnetHint.style.color = 'var(--error)';
        return;
    }

    const currentHash = hashInput.value.trim().toLowerCase();
    if (currentHash && currentHash.length === 40 && /^[a-f0-9]{40}$/.test(currentHash)) {
        if (extractedHash === currentHash) {
            magnetHint.textContent = '— hash matches';
            magnetHint.style.color = 'var(--success)';
        } else {
            magnetHint.textContent = '— hash MISMATCH with Info Hash field';
            magnetHint.style.color = 'var(--error)';
        }
    } else {
        magnetHint.textContent = '— hash extracted: ' + extractedHash.substring(0, 8) + '...';
        magnetHint.style.color = 'var(--text-muted)';
    }
}

// Email obfuscation reveal
function revealEmail(el) {
    if (typeof OBF_EMAIL === 'undefined') return;
    const email = OBF_EMAIL.map(c => String.fromCharCode(c)).join('');
    el.textContent = email;
    el.href = 'mailto:' + email;
    el.onclick = null;
}

async function handleReportSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const alert = document.getElementById('report-alert');
    const btn = document.getElementById('report-submit');

    form.querySelectorAll('.form-group').forEach(g => g.classList.remove('has-error'));
    alert.className = 'alert';
    alert.textContent = '';

    let valid = true;
    ['name', 'representative', 'company', 'objectTitle'].forEach(f => {
        const input = form.querySelector(`[name="${f}"]`);
        if (!input.value.trim()) {
            input.closest('.form-group').classList.add('has-error');
            valid = false;
        }
    });

    const emailInput = form.querySelector('[name="email"]');
    if (!emailInput.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        emailInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }

    const linkInput = form.querySelector('[name="link"]');
    try { new URL(linkInput.value); } catch {
        linkInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }

    const hashInput = form.querySelector('[name="infoHash"]');
    if (!hashInput.value.match(/^[a-fA-F0-9]{40}$/)) {
        hashInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }

    // Magnet link cross-validation (optional field, but if filled must be valid)
    const magnetInput = form.querySelector('[name="magnet_link"]');
    if (magnetInput && magnetInput.value.trim()) {
        const magnet = magnetInput.value.trim();
        if (!magnet.startsWith('magnet:?') || !/[?&]xt=urn:btih:/i.test(magnet)) {
            magnetInput.closest('.form-group').classList.add('has-error');
            valid = false;
        } else {
            const extractedHash = extractHashFromMagnet(magnet);
            if (!extractedHash) {
                magnetInput.closest('.form-group').classList.add('has-error');
                valid = false;
            } else if (hashInput.value.match(/^[a-fA-F0-9]{40}$/) && extractedHash !== hashInput.value.toLowerCase()) {
                magnetInput.closest('.form-group').classList.add('has-error');
                valid = false;
            }
        }
    }

    if (!valid) return;

    btn.disabled = true;

    const data = {};
    new FormData(form).forEach((v, k) => data[k] = v);

    try {
        const json = await fetchWithCaptcha('submit_report', data);

        if (json.success) {
            alert.className = 'alert alert-success show';
            alert.textContent = 'Report submitted successfully! Your report number: #' + json.id;
            form.reset();
            const mc = document.getElementById('msg-counter');
            if (mc) {
                const maxLen = document.getElementById('add_message')?.dataset.maxlength || '2000';
                mc.textContent = '0/' + maxLen;
                mc.style.color = '';
            }
            btn.disabled = false;
        } else {
            showFormSubmitError(form, alert, btn, json, {
                duplicate: 'A report with this Info Hash already exists.',
            });
        }
    } catch {
        showFormNetworkError(alert, btn);
    }
}

async function handleStatusCheck(e) {
    e.preventDefault();
    const form = e.target;
    const alert = document.getElementById('status-alert');
    const result = document.getElementById('status-result');

    form.querySelectorAll('.form-group').forEach(g => g.classList.remove('has-error'));
    alert.className = 'alert';
    result.style.display = 'none';

    let query = form.querySelector('[name="search_query"]').value.trim();
    const email = form.querySelector('[name="email"]').value.trim();

    if (!query) {
        form.querySelector('[name="search_query"]').closest('.form-group').classList.add('has-error');
        return;
    }

    // If it's a magnet link, extract the hash
    if (query.startsWith('magnet:?')) {
        const extracted = extractHashFromMagnet(query);
        if (!extracted) {
            form.querySelector('[name="search_query"]').closest('.form-group').classList.add('has-error');
            alert.className = 'alert alert-error show';
            alert.textContent = 'Could not extract a valid hash from the magnet link.';
            return;
        }
        query = extracted;
    }

    // Email is always required
    const emailField = form.querySelector('[name="email"]');
    if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        emailField.closest('.form-group').classList.add('has-error');
        alert.className = 'alert alert-error show';
        alert.textContent = 'Email address is required to check report status.';
        return;
    }

    const body = {
        search_query: query,
        email: email,
        csrf_token: form.querySelector('[name="csrf_token"]')?.value || '',
    };

    try {
        const json = await fetchWithCaptcha('check_status', body);

        if (json.success) {
            document.getElementById('res-id').textContent = '#' + json.id;
            document.getElementById('res-reporter').textContent = json.name || '—';
            document.getElementById('res-email').textContent = json.email || '—';
            document.getElementById('res-company').textContent = json.company || '—';
            document.getElementById('res-representative').textContent = json.representative || '—';
            const linkEl = document.getElementById('res-link');
            if (json.link) {
                linkEl.innerHTML = '<a href="' + escHtml(json.link) + '" target="_blank" class="status-link">' + escHtml(json.link) + '</a>';
            } else {
                linkEl.textContent = '—';
            }

            document.getElementById('res-object').textContent = json.objectTitle || '—';
            document.getElementById('res-hash').textContent = json.infoHash;
            const magnetRow = document.getElementById('res-magnet-row');
            if (json.magnet_link) {
                document.getElementById('res-magnet').textContent = json.magnet_link;
                magnetRow.style.display = '';
            } else {
                magnetRow.style.display = 'none';
            }
            document.getElementById('res-date').textContent = json.timestamp;

            const statusLabels = { pending: 'Awaiting Review', checked: 'Reviewed', blocked: 'Blocked', archived: 'Archived / Closed' };
            const statusEl = document.getElementById('res-status');
            let badges = '';
            if (json.blocked) {
                badges += '<span class="status-badge blocked">Blocked</span> ';
            }
            if (json.checked && !json.blocked && json.archived) {
                badges += '<span class="status-badge checked">Reviewed</span> ';
            }
            if (json.archived) {
                badges += '<span class="status-badge archived">Archived / Closed</span>';
            }
            if (!badges) {
                badges = '<span class="status-badge ' + json.status + '">' + (statusLabels[json.status] || json.status) + '</span>';
            }
            statusEl.innerHTML = badges;

            // Show appeal form only for archived-without-block (request blocking/re-examination)
            const statusAppealSection = document.getElementById('status-appeal-section');
            if (statusAppealSection) {
                if (json.archived && !json.blocked) {
                    statusAppealSection.style.display = '';
                    document.getElementById('status-appeal-hash').value = json.infoHash;
                    document.getElementById('status-appeal-type').value = 'block';
                    document.getElementById('status-appeal-report-id').value = json.id;
                    document.getElementById('status-appeal-desc').textContent = 'This report was archived without blocking. If you believe this hash should be blocked, you can submit an appeal for re-examination.';
                } else {
                    statusAppealSection.style.display = 'none';
                }
            }

            result.style.display = 'block';
        } else {
            alert.className = 'alert alert-error show';
            alert.textContent = json.error === 'not_found' ? 'No report found for the provided data.' : (json.error || 'Error');
        }
    } catch {
        alert.className = 'alert alert-error show';
        alert.textContent = 'Network error.';
    }
}

// Block check
async function handleBlockCheck(e) {
    e.preventDefault();
    const form = e.target;
    const alert = document.getElementById('block-check-alert');
    const result = document.getElementById('block-check-result');

    form.querySelectorAll('.form-group').forEach(g => g.classList.remove('has-error'));
    alert.className = 'alert';
    alert.textContent = '';
    result.style.display = 'none';

    let query = form.querySelector('[name="block_query"]').value.trim();
    if (!query) {
        form.querySelector('[name="block_query"]').closest('.form-group').classList.add('has-error');
        return;
    }

    // If it's a magnet link, extract the hash
    let hash = query;
    if (query.startsWith('magnet:?')) {
        hash = extractHashFromMagnet(query);
        if (!hash) {
            alert.className = 'alert alert-error show';
            alert.textContent = 'Could not extract a valid hash from the magnet link.';
            return;
        }
    }

    if (!/^[a-fA-F0-9]{40}$/.test(hash)) {
        form.querySelector('[name="block_query"]').closest('.form-group').classList.add('has-error');
        alert.className = 'alert alert-error show';
        alert.textContent = 'Enter a valid 40-character hex hash or a magnet link.';
        return;
    }

    try {
        const json = await fetchWithCaptcha('check_block', { hash: hash.toLowerCase() });

        if (json.success) {
            document.getElementById('bc-hash').textContent = json.infoHash;
            const statusEl = document.getElementById('bc-status');
            if (json.blocked) {
                statusEl.innerHTML = '<span class="status-badge blocked">Blocked</span>';
            } else {
                statusEl.innerHTML = '<span class="status-badge checked">Not Blocked</span>';
            }
            document.getElementById('bc-row-company').style.display = json.blocked ? '' : 'none';
            document.getElementById('bc-row-entity').style.display = json.blocked ? '' : 'none';
            document.getElementById('bc-company').textContent = json.company || '—';
            document.getElementById('bc-entity').textContent = json.representative || '—';
            // Show appeal section only when blocked
            const appealSection = document.getElementById('appeal-section');
            if (appealSection) {
                appealSection.style.display = json.blocked ? '' : 'none';
                if (json.blocked) {
                    document.getElementById('appeal-hash').value = json.infoHash;
                }
            }
            result.style.display = 'block';
        } else {
            alert.className = 'alert alert-error show';
            alert.textContent = json.error || 'Error';
        }
    } catch {
        alert.className = 'alert alert-error show';
        alert.textContent = 'Network error.';
    }
}

// Appeal submission
async function handleAppealSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const alert = document.getElementById('appeal-alert');
    const btn = document.getElementById('appeal-submit');

    form.querySelectorAll('.form-group').forEach(g => g.classList.remove('has-error'));
    alert.className = 'alert';
    alert.textContent = '';

    let valid = true;
    const nameInput = form.querySelector('[name="name"]');
    if (!nameInput.value.trim()) {
        nameInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }
    const emailInput = form.querySelector('[name="email"]');
    if (!emailInput.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        emailInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }
    const msgInput = form.querySelector('[name="message"]');
    if (!msgInput.value.trim()) {
        msgInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }

    if (!valid) return;

    btn.disabled = true;

    const data = {};
    new FormData(form).forEach((v, k) => data[k] = v);

    try {
        const json = await fetchWithCaptcha('submit_appeal', data);

        if (json.success) {
            alert.className = 'alert alert-success show';
            alert.textContent = 'Appeal submitted successfully! You will be notified by email when it is reviewed.';
            form.reset();
            document.getElementById('appeal-hash').value = document.getElementById('bc-hash').textContent;
            const counter = document.getElementById('appeal-counter');
            if (counter) counter.textContent = '0/' + (document.getElementById('appeal-message')?.dataset.maxlength || '2000');
            btn.disabled = false;
        } else {
            showFormSubmitError(form, alert, btn, json);
        }
    } catch {
        showFormNetworkError(alert, btn);
    }
}

// Status page appeal submission (for archived-without-block or blocked+archived)
async function handleStatusAppealSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const alert = document.getElementById('status-appeal-alert');
    const btn = document.getElementById('status-appeal-submit');

    form.querySelectorAll('.form-group').forEach(g => g.classList.remove('has-error'));
    alert.className = 'alert';
    alert.textContent = '';

    let valid = true;
    const nameInput = form.querySelector('[name="name"]');
    if (!nameInput.value.trim()) {
        nameInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }
    const emailInput = form.querySelector('[name="email"]');
    if (!emailInput.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        emailInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }
    const msgInput = form.querySelector('[name="message"]');
    if (!msgInput.value.trim()) {
        msgInput.closest('.form-group').classList.add('has-error');
        valid = false;
    }

    if (!valid) return;

    btn.disabled = true;

    const data = {};
    new FormData(form).forEach((v, k) => data[k] = v);

    try {
        const json = await fetchWithCaptcha('submit_appeal', data);

        if (json.success) {
            alert.className = 'alert alert-success show';
            alert.textContent = 'Appeal submitted successfully! You will be notified by email when it is reviewed.';
            form.querySelector('[name="name"]').value = '';
            form.querySelector('[name="email"]').value = '';
            form.querySelector('[name="message"]').value = '';
            const counter = document.getElementById('status-appeal-counter');
            if (counter) counter.textContent = '0/' + (document.getElementById('status-appeal-message')?.dataset.maxlength || '2000');
            btn.disabled = false;
        } else {
            showFormSubmitError(form, alert, btn, json);
        }
    } catch {
        showFormNetworkError(alert, btn);
    }
}

// Transparency page — multi-sort
let transCurrentPage = 1;
let transSortStack = [{ col: 'total', dir: 'desc' }];

async function loadTransparency(page) {
    if (page) transCurrentPage = page;
    const sortParam = transSortStack.length
        ? transSortStack.map(s => s.col + ':' + s.dir).join(',')
        : 'total:desc';
    const params = new URLSearchParams({ page: transCurrentPage, sort: sortParam });

    try {
        const res = await fetch(APP_API + 'transparency&' + params.toString());
        const json = await res.json();

        document.getElementById('transparency-loading').style.display = 'none';
        document.getElementById('transparency-content').style.display = 'block';

        if (!json.success || !json.data.length) {
            document.getElementById('trans-body').innerHTML = '<tr><td colspan="7" class="transparency-empty">No data available.</td></tr>';
            document.getElementById('trans-summary').innerHTML = '<p>No transparency data available yet.</p>';
            document.getElementById('trans-pagination').innerHTML = '';
            return;
        }

        const a = json.aggregates || {};
        const pct = (n) => a.total_requests ? ' (' + Math.round(n / a.total_requests * 100) + '%)' : '';
        document.getElementById('trans-summary').innerHTML =
            '<div class="trans-stats">' +
            '<div class="trans-stat-accent"><strong>' + (a.total_entities || 0) + '</strong><br><small>Organizations</small></div>' +
            '<div class="trans-stat-accent"><strong>' + (a.total_groups || json.total) + '</strong><br><small>Groups</small></div>' +
            '<div class="trans-stat-text"><strong>' + (a.total_requests || 0) + '</strong><br><small>Total Requests</small></div>' +
            '<div class="trans-stat-success"><strong>' + (a.total_reviewed || 0) + pct(a.total_reviewed || 0) + '</strong><br><small>Reviewed</small></div>' +
            '<div class="trans-stat-error"><strong>' + (a.total_blocked || 0) + pct(a.total_blocked || 0) + '</strong><br><small>Blocked</small></div>' +
            '<div class="trans-stat-warning"><strong>' + (a.total_pending || 0) + pct(a.total_pending || 0) + '</strong><br><small>Awaiting Review</small></div>' +
            '</div>';

        const offset = (json.page - 1) * json.data.length;
        document.getElementById('trans-body').innerHTML = json.data.map((r, i) => `
            <tr>
                <td>${offset + i + 1}</td>
                <td>${escHtml(r.company)}</td>
                <td>${escHtml(r.representative)}</td>
                <td>${r.total_requests}</td>
                <td>${r.accepted}</td>
                <td>${r.blocked}</td>
                <td>${r.pending}</td>
            </tr>
        `).join('');

        // Pagination
        const pagEl = document.getElementById('trans-pagination');
        if (json.pages <= 1) {
            pagEl.innerHTML = '';
        } else {
            pagEl.innerHTML = `
                <button ${json.page <= 1 ? 'disabled' : ''} onclick="loadTransparency(${json.page - 1})">Prev</button>
                <span>Page ${json.page} of ${json.pages}</span>
                <button ${json.page >= json.pages ? 'disabled' : ''} onclick="loadTransparency(${json.page + 1})">Next</button>
            `;
        }
    } catch {
        document.getElementById('transparency-loading').textContent = 'Failed to load data.';
    }
}

function updateTransSortIcons() {
    document.querySelectorAll('.trans-sortable').forEach(th => {
        const icon = th.querySelector('.trans-sort-icon');
        if (!icon) return;
        const col = th.dataset.sort;
        const idx = transSortStack.findIndex(s => s.col === col);
        const oldBadge = th.querySelector('.trans-sort-priority');
        if (oldBadge) oldBadge.remove();

        if (idx !== -1) {
            const s = transSortStack[idx];
            icon.className = s.dir === 'asc' ? 'bi bi-arrow-up trans-sort-icon active' : 'bi bi-arrow-down trans-sort-icon active';
            if (transSortStack.length > 1) {
                const badge = document.createElement('sup');
                badge.className = 'trans-sort-priority';
                badge.textContent = idx + 1;
                icon.after(badge);
            }
        } else {
            icon.className = 'bi bi-arrow-down-up trans-sort-icon';
        }
    });
}

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// Attribute-safe escaping (also escapes quotes) for values interpolated into HTML attributes
// like data-tooltip="...". escHtml alone does NOT escape quotes and could break out of an attr.
function escAttr(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// === Tracker Telemetry (Stats Page & Home Widget) ===
//
// State machine per surface (stats page / home widget):
//   - FRESH        : countdown to next refresh, then trigger a non-blocking sync.
//   - SYNC_NEEDED  : kick off a blocking fetch (only one in-flight at a time per surface).
//   - SYNCING      : another request is fetching upstream; poll cache with backoff.
//   - ERROR        : retry after a longer delay.
//
// Each surface owns its own timers and AbortController. Surfaces are independent and never
// interfere with each other's polling. All paths go through scheduleNextPoll(...) which clears
// any previous timer first, so we can't accidentally stack overlapping polls.
let statsLoopInterval = null;
let statsCountdownTimer = null;
let statsPollTimer = null;
let statsHomePollTimer = null;
let statsAbortController = null;
let statsHomeAbortController = null;
let statsInFlight = false;       // stats page: a fetch is currently in flight
let statsHomeInFlight = false;   // home widget: a fetch is currently in flight
let statsSyncBackoff = 0;        // stats page: backoff iteration counter while syncing_in_background
let statsHomeSyncBackoff = 0;    // home widget: same, for home
let statsLastRenderedAt = 0;     // last fetched_at we actually rendered on stats page
let statsHomeLastRenderedAt = 0; // last fetched_at we actually rendered on home widget
let statsSyncingUiTimer = null;  // stats page: deferred "Syncing Swarms..." UI flip
let statsHomeSyncingUiTimer = null; // home widget: same
// Defer the "Syncing Swarms..." UI by this many ms. Fast fetches (fresh cache, ~30-100ms)
// complete before the timer fires, so the user sees no flicker. Only genuinely slow upstream
// syncs hold the timer long enough to trigger the syncing visual state.
const SYNCING_UI_DEFER_MS = 400;
// Honest, generic progress messages (cycled while genuinely waiting on the upstream fetch).
// Kept accurate on purpose — they describe what's actually happening, not invented "steps".
let statsLoadingTexts = [
    { title: "Contacting tracker", sub: "Requesting live statistics..." },
    { title: "Fetching swarm data", sub: "Waiting for the tracker to respond..." },
    { title: "Loading statistics", sub: "Reading peer and connection counts..." },
    { title: "Almost ready", sub: "Preparing the dashboard..." }
];
let statsLoadingCycleIndex = 0;
let statsLoadingCycleTimer = null;

// Exponential-backoff delay (in ms) for polling while syncing_in_background.
//
// Why polling exists at all: the only way the browser can find out that a server-side fetch
// has finished is to ask. There is no push channel. While the "Syncing Swarms..." banner is
// up, JS is asking the server "is the cache fresh yet?" every N seconds — N grows after each
// poll so we don't hammer the server.
//
// Sequence: 2s, 4s, 8s, 8s, 8s... (cap at 8 seconds)
// Typical upstream fetch takes 1-3 seconds, so usually only 1-2 polls are needed before the
// dashboard shows fresh data and the normal countdown takes over.
function syncPollDelayMs(iteration) {
    const base = 2000;
    const cap = 8000;
    return Math.min(cap, base * Math.pow(2, Math.max(0, iteration)));
}

function initTrackerStats() {
    const statsContainer = document.getElementById('stats-page-container');
    const homeWidget = document.getElementById('home-stats-widget');

    if (statsContainer) {
        const hasCache = statsContainer.dataset.hasCache === '1';
        const cacheFresh = statsContainer.dataset.cacheFresh === '1';
        const remainingSeconds = parseInt(statsContainer.dataset.remainingSeconds || '0');
        // Remember what the server already rendered so subsequent polls can early-out if they
        // return the same fetched_at.
        statsLastRenderedAt = parseInt(statsContainer.dataset.fetchedAt || '0') || 0;

        if (hasCache) {
            // The server already pre-rendered the dashboard with cached data. NEVER flash the
            // big loader over real data — that is what made "Loading..." vanish into a blank
            // dashboard. Show the data immediately; decide only whether to refresh.
            stopStatsLoadingAnimation();
            document.getElementById('stats-loader')?.classList.add('hidden');
            document.getElementById('stats-error')?.classList.add('hidden');
            document.getElementById('stats-dashboard')?.classList.remove('hidden');
            if (cacheFresh) {
                // Cache still within its TTL: just resume the countdown locally. No fetch — the
                // first real refresh happens when the countdown ends.
                startStatsCountdown(Math.max(1, remainingSeconds));
            } else {
                // Data present but past its TTL: keep showing it and refresh in the background
                // (non-blocking). The dashboard stays visible the entire time.
                loadStatsFull(false, false);
            }
        } else {
            // No data at all (first ever load) — show the loader and do a blocking fetch.
            startStatsLoadingAnimation();
            loadStatsFull(true, true);
        }

        const retryBtn = document.getElementById('btn-stats-retry');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => {
                document.getElementById('stats-error').classList.add('hidden');
                document.getElementById('stats-loader').classList.remove('hidden');
                startStatsLoadingAnimation();
                statsSyncBackoff = 0;
                loadStatsFull(true, true);
            });
        }
    } else if (homeWidget) {
        const hasCache = homeWidget.dataset.hasCache === '1';
        const cacheFresh = homeWidget.dataset.cacheFresh === '1';
        const remainingSeconds = parseInt(homeWidget.dataset.remainingSeconds || '0');
        statsHomeLastRenderedAt = parseInt(homeWidget.dataset.fetchedAt || '0') || 0;

        if (!hasCache || !cacheFresh) {
            loadStatsHome(!hasCache);
        } else {
            // Server already pre-rendered fresh widget data — no need to fetch on page load.
            const beacon = homeWidget.querySelector('.home-stat-beacon');
            if (beacon) {
                beacon.classList.remove('syncing');
                beacon.title = "Live Syncing";
            }
            clearTimeout(statsHomePollTimer);
            statsHomePollTimer = setTimeout(() => {
                loadStatsHome(false);
            }, Math.max(1, remainingSeconds) * 1000);
        }
    }
}

function startStatsLoadingAnimation() {
    clearInterval(statsLoadingCycleTimer);
    statsLoadingCycleIndex = 0;
    const titleEl = document.getElementById('stats-loader-title');
    const subEl = document.getElementById('stats-loader-subtitle');
    
    if (titleEl && subEl) {
        titleEl.textContent = statsLoadingTexts[0].title;
        subEl.textContent = statsLoadingTexts[0].sub;
        
        statsLoadingCycleTimer = setInterval(() => {
            statsLoadingCycleIndex = (statsLoadingCycleIndex + 1) % statsLoadingTexts.length;
            titleEl.style.opacity = 0;
            subEl.style.opacity = 0;
            
            setTimeout(() => {
                titleEl.textContent = statsLoadingTexts[statsLoadingCycleIndex].title;
                subEl.textContent = statsLoadingTexts[statsLoadingCycleIndex].sub;
                titleEl.style.opacity = 1;
                subEl.style.opacity = 1;
            }, 300);
        }, 2200);
    }
}

function stopStatsLoadingAnimation() {
    clearInterval(statsLoadingCycleTimer);
}

async function loadStatsFull(forceSync = false, isFirstLoad = false) {
    const container = document.getElementById('stats-page-container');
    if (!container) return;

    // Guard against overlapping fetches on the same surface. The poller / countdown should
    // be the only path that calls into this function while one is already in flight.
    if (statsInFlight) return;

    const intervalSec = parseInt(container.dataset.interval || '10');
    const source = container.dataset.source || 'stats';
    const badge = document.getElementById('stats-live-badge');
    const beaconText = document.getElementById('stats-beacon-text');
    const countdownBar = document.getElementById('countdown-bar');
    const countdownText = document.getElementById('countdown-text');

    // Stop any pending timers — we are about to take a network round trip.
    clearInterval(statsCountdownTimer);
    clearTimeout(statsPollTimer);
    clearTimeout(statsSyncingUiTimer);

    // Defer the "Syncing Swarms..." UI: if the fetch is fast (fresh cache, ~30-100ms) we
    // don't want a visible flash. Only when the fetch genuinely takes time should the user
    // see syncing feedback. The timer is cleared in every code path that runs after the
    // fetch returns, so leftover timers can't accidentally flip the UI later.
    statsSyncingUiTimer = setTimeout(() => {
        if (countdownBar) {
            countdownBar.style.transition = 'none';
            countdownBar.style.width = '100%';
            countdownBar.classList.add('syncing');
        }
        if (countdownText) countdownText.textContent = "Syncing Swarms...";
        if (!isFirstLoad && badge) {
            badge.classList.add('syncing');
            if (beaconText) beaconText.textContent = "Syncing Swarms...";
        }
    }, SYNCING_UI_DEFER_MS);

    if (statsAbortController) {
        statsAbortController.abort();
    }
    statsAbortController = new AbortController();
    const signal = statsAbortController.signal;

    const fetchStartTime = Date.now();
    const url = APP_BASE + 'api.php?endpoint=tracker_stats&source=' + encodeURIComponent(source)
              + (forceSync ? '' : '&stale_ok=1');

    statsInFlight = true;
    let json = null;
    try {
        const res = await fetch(url, { signal });
        json = await res.json();
    } catch (e) {
        statsInFlight = false;
        clearTimeout(statsSyncingUiTimer);
        if (e.name === 'AbortError') return;
        stopStatsLoadingAnimation();
        // Retry after a longer pause on transport-level errors.
        showStatsError('Connection timed out or network error. Retrying soon...');
        clearTimeout(statsPollTimer);
        statsPollTimer = setTimeout(() => {
            document.getElementById('stats-error')?.classList.add('hidden');
            document.getElementById('stats-loader')?.classList.remove('hidden');
            startStatsLoadingAnimation();
            loadStatsFull(false, false);
        }, 10000);
        return;
    }
    statsInFlight = false;
    clearTimeout(statsSyncingUiTimer);

    const fetchElapsed = Date.now() - fetchStartTime;
    const minLoadingMs = json && json.min_loading_ms !== undefined ? parseInt(json.min_loading_ms) : 1000;
    const remainingDelay = isFirstLoad ? Math.max(0, minLoadingMs - fetchElapsed) : 0;

    setTimeout(() => {
        stopStatsLoadingAnimation();

        if (!json || !json.success) {
            // Server returned a non-success payload (e.g. 503 with no cache). Wait, then retry.
            const errMsg = (json && json.error) ? json.error : 'Server error occurred.';
            if (json && (json.syncing_in_background || json.sync_required)) {
                // The server is busy but answering — fall back to polling rather than an error UI.
                const d = syncPollDelayMs(statsSyncBackoff);
                statsSyncBackoff++;
                clearTimeout(statsPollTimer);
                statsPollTimer = setTimeout(() => loadStatsFull(false, false), d);
            } else {
                showStatsError(errMsg);
                clearTimeout(statsPollTimer);
                statsPollTimer = setTimeout(() => {
                    document.getElementById('stats-error')?.classList.add('hidden');
                    document.getElementById('stats-loader')?.classList.remove('hidden');
                    startStatsLoadingAnimation();
                    loadStatsFull(false, false);
                }, 10000);
            }
            return;
        }

        // Success: render whatever cache the server gave us (may be stale).
        document.getElementById('stats-loader').classList.add('hidden');
        document.getElementById('stats-error').classList.add('hidden');
        document.getElementById('stats-dashboard').classList.remove('hidden');
        renderStatsDashboard(json);

        if (json.syncing_in_background) {
            // Another request is fetching upstream — show "Syncing", poll with backoff.
            if (badge) {
                badge.classList.remove('hidden');
                badge.classList.add('syncing');
                if (beaconText) beaconText.textContent = "Syncing Swarms...";
            }
            if (countdownBar) {
                countdownBar.style.transition = 'none';
                countdownBar.style.width = '100%';
                countdownBar.classList.add('syncing');
            }
            if (countdownText) countdownText.textContent = "Syncing Swarms...";

            const d = syncPollDelayMs(statsSyncBackoff);
            statsSyncBackoff++;
            clearTimeout(statsPollTimer);
            statsPollTimer = setTimeout(() => loadStatsFull(false, false), d);
        } else if (json.sync_required) {
            // Cache is stale and nobody is fetching yet. WE will trigger the blocking fetch,
            // but we keep displaying the stale cache while it runs. Reset backoff.
            statsSyncBackoff = 0;
            if (badge) {
                badge.classList.remove('hidden');
                badge.classList.add('syncing');
                if (beaconText) beaconText.textContent = "Syncing Swarms...";
            }
            if (countdownBar) {
                countdownBar.style.transition = 'none';
                countdownBar.style.width = '100%';
                countdownBar.classList.add('syncing');
            }
            if (countdownText) countdownText.textContent = "Syncing Swarms...";
            // Fire a blocking sync. statsInFlight guard prevents re-entry; the call schedules
            // its own next poll/countdown on completion.
            loadStatsFull(true, false);
        } else {
            // Cache is fresh. Start countdown.
            statsSyncBackoff = 0;
            if (badge) {
                badge.classList.remove('hidden');
                badge.classList.remove('syncing');
                if (beaconText) beaconText.textContent = "Live Syncing";
            }
            const remainingSec = json.remaining_seconds !== undefined ? parseInt(json.remaining_seconds) : intervalSec;
            startStatsCountdown(remainingSec);
        }
    }, remainingDelay);
}

function showStatsError(msg) {
    document.getElementById('stats-loader').classList.add('hidden');
    document.getElementById('stats-dashboard').classList.add('hidden');
    document.getElementById('stats-live-badge')?.classList.add('hidden');
    
    const bar = document.getElementById('countdown-bar');
    if (bar) {
        bar.classList.remove('syncing');
        bar.style.width = '0%';
    }
    
    const errEl = document.getElementById('stats-error');
    const msgEl = document.getElementById('stats-error-msg');
    if (errEl && msgEl) {
        msgEl.textContent = msg;
        errEl.classList.remove('hidden');
    }
}

function renderStatsDashboard(res) {
    // Skip the entire render if we've already shown this exact data. Polling while a sync
    // is in progress returns the same cached payload over and over — there is no reason to
    // re-run animateNumber, recompute percentages, or touch any DOM nodes in that case.
    // This is what previously made it *look* like the dashboard was updating every few
    // seconds even though nothing had actually changed server-side.
    const at = parseInt(res.fetched_at || 0) || 0;
    if (at > 0 && at === statsLastRenderedAt) {
        return;
    }
    if (at > 0) statsLastRenderedAt = at;

    const container = document.getElementById('stats-page-container');
    const peerStyle = (container && container.dataset.peerLabelStyle) || 'percent';

    animateNumber('val-torrents', res.torrents);
    animateNumber('val-seeds', res.seeds);
    animateNumber('val-completed', res.completed);
    // The 3rd card is either Leechers or a combined Peers total depending on the
    // admin-selected style; only one of these elements exists in the DOM.
    if (document.getElementById('val-leechers')) animateNumber('val-leechers', res.leechers);
    if (document.getElementById('val-peers')) animateNumber('val-peers', res.peers);

    const totalPeers = res.peers || 1;
    const seedPct = Math.round((res.seeds / totalPeers) * 100);
    const leechPct = Math.round((res.leechers / totalPeers) * 100);
    const peersFmt = Number(res.peers).toLocaleString();
    const seedsFmt = Number(res.seeds).toLocaleString();
    const leechFmt = Number(res.leechers).toLocaleString();

    const subSeedsEl = document.getElementById('sub-seeds');
    const subLeechEl = document.getElementById('sub-leechers');
    const subPeersEl = document.getElementById('sub-peers');
    if (peerStyle === 'percent') {
        if (subSeedsEl) subSeedsEl.textContent = `${seedPct}% of total peers`;
        if (subLeechEl) subLeechEl.textContent = `${leechPct}% of total peers`;
    } else {
        if (subSeedsEl) subSeedsEl.textContent = `of ${peersFmt} peers`;
        if (subLeechEl) subLeechEl.textContent = `of ${peersFmt} peers`;
    }
    if (subPeersEl) subPeersEl.textContent = `${leechFmt} leechers · ${seedsFmt} seeds`;
    
    document.getElementById('val-uptime').textContent = res.uptime_string;
    document.getElementById('val-tracker-id').textContent = res.tracker_id || 'N/A';
    
    const versionEl = document.getElementById('val-version');
    // res.version comes from the upstream tracker XML — treat it as untrusted. Only render it as
    // a link when it is an explicit http(s) URL, and build the node via the DOM (href setter is
    // scheme-checked above) so a crafted value can't inject markup or a javascript: URL.
    versionEl.textContent = '';
    if (res.version && /^https?:\/\//i.test(res.version)) {
        const a = document.createElement('a');
        a.href = res.version;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.className = 'status-link font-mono';
        a.style.fontSize = '0.75rem';
        a.innerHTML = 'Git Commit <i class="bi bi-box-arrow-up-right"></i>';
        versionEl.appendChild(a);
    } else {
        versionEl.textContent = res.version || 'N/A';
    }
    
    const udpCount = res.connections.udp.connect + res.connections.udp.announce + res.connections.udp.scrape;
    const tcpCount = res.connections.tcp.accept + res.connections.tcp.announce + res.connections.tcp.scrape;
    const totalConns = (udpCount + tcpCount) || 1;
    const udpPct = Math.round((udpCount / totalConns) * 100);
    const tcpPct = 100 - udpPct;
    
    document.getElementById('val-udp-pct').textContent = udpPct + '%';
    document.getElementById('val-tcp-pct').textContent = tcpPct + '%';
    
    const barUdp = document.getElementById('bar-udp');
    const barTcp = document.getElementById('bar-tcp');
    if (barUdp && barTcp) {
        barUdp.style.width = udpPct + '%';
        barTcp.style.width = tcpPct + '%';
    }
    
    document.getElementById('val-udp-connect').textContent = res.connections.udp.connect.toLocaleString();
    document.getElementById('val-udp-announce').textContent = res.connections.udp.announce.toLocaleString();
    document.getElementById('val-udp-scrape').textContent = res.connections.udp.scrape.toLocaleString();
    document.getElementById('val-udp-mismatch').textContent = res.connections.udp.mismatch.toLocaleString();
    
    document.getElementById('val-tcp-accept').textContent = res.connections.tcp.accept.toLocaleString();
    document.getElementById('val-tcp-announce').textContent = res.connections.tcp.announce.toLocaleString();
    document.getElementById('val-tcp-scrape').textContent = res.connections.tcp.scrape.toLocaleString();
    document.getElementById('val-tcp-sync').textContent = res.connections.livesync.toLocaleString();
    
    renderRenewHeatmap(res.renew_intervals || []);
    
    const debugPanel = document.getElementById('debug-diagnostics-panel');
    const errorsBody = document.getElementById('http-errors-body');
    if (debugPanel && errorsBody) {
        if (res.http_errors && res.http_errors.length > 0) {
            debugPanel.classList.remove('hidden');
            errorsBody.innerHTML = res.http_errors.map(err => {
                let badgeClass = 'status-badge-sm status-badge ';
                let severity = 'Low';
                if (err.code.startsWith('5')) {
                    badgeClass += 'blocked';
                    severity = 'Critical';
                } else if (err.code.startsWith('400')) {
                    badgeClass += 'pending';
                    severity = 'Moderate';
                } else {
                    badgeClass += 'archived';
                }
                return `<tr>
                    <td class="font-mono text-white">${escHtml(err.code)}</td>
                    <td class="font-mono">${err.count.toLocaleString()}</td>
                    <td><span class="${badgeClass}">${severity}</span></td>
                </tr>`;
            }).join('');
        } else {
            debugPanel.classList.add('hidden');
        }
    }
}

function renderRenewHeatmap(intervals) {
    const container = document.getElementById('renew-heatmap');
    if (!container) return;
    
    if (intervals.length === 0) {
        container.innerHTML = '<div class="text-center text-muted w-100 py-3">No activity heat profile available.</div>';
        return;
    }
    
    const maxCount = Math.max(...intervals.map(i => i.count)) || 1;
    
    container.innerHTML = intervals.map(item => {
        let level = 0;
        const ratio = item.count / maxCount;
        if (item.count > 0) {
            if (ratio < 0.1) level = 1;
            else if (ratio < 0.4) level = 2;
            else if (ratio < 0.75) level = 3;
            else level = 4;
        }
        
        // item.interval comes from upstream XML — escape before interpolating into markup.
        const label = escHtml(item.interval);
        const tooltipText = escAttr(`Interval ${item.interval}m: ${item.count.toLocaleString()} renews`);

        return `<div class="heat-block level-${level}" data-tooltip="${tooltipText}">
            <span>${label}</span>
        </div>`;
    }).join('');
}

function animateNumber(id, endVal) {
    const el = document.getElementById(id);
    if (!el) return;
    
    const startVal = parseInt(el.textContent.replace(/,/g, '')) || 0;
    if (startVal === endVal) {
        el.textContent = endVal.toLocaleString();
        return;
    }
    
    const duration = 800;
    const startTime = performance.now();
    
    function update(now) {
        const elapsed = now - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const ease = progress * (2 - progress);
        const current = Math.floor(startVal + (endVal - startVal) * ease);
        el.textContent = current.toLocaleString();
        
        if (progress < 1) {
            requestAnimationFrame(update);
        } else {
            el.textContent = endVal.toLocaleString();
        }
    }
    requestAnimationFrame(update);
}

function startStatsCountdown(seconds) {
    clearInterval(statsCountdownTimer);
    clearTimeout(statsLoopInterval);

    const bar = document.getElementById('countdown-bar');
    const text = document.getElementById('countdown-text');
    const container = document.getElementById('stats-page-container');

    // The full configured interval (e.g. tracker_stats_page_interval = 10). Used to compute
    // where the bar should START. On a fresh sync, seconds === fullInterval so we start at
    // 100%. On a mid-cycle page refresh (cache 4 sec old of 10 sec TTL), seconds=6 and
    // fullInterval=10, so the bar starts at 60% and animates the rest of the way to 0%.
    const fullInterval = container ? Math.max(1, parseInt(container.dataset.interval) || seconds) : seconds;

    if (bar) {
        bar.classList.remove('syncing');
    }

    if (seconds <= 0) {
        if (text) text.textContent = "Syncing Swarms...";
        if (bar) {
            bar.style.transition = 'none';
            bar.style.width = '100%';
            bar.classList.add('syncing');
        }
        loadStatsFull(true, false);
        return;
    }

    // Smooth bar: one CSS transition over the remaining countdown — GPU-accelerated, no jitter.
    if (bar) {
        const startPct = Math.max(0, Math.min(100, (seconds / fullInterval) * 100));
        bar.style.transition = 'none';
        bar.style.width = startPct + '%';
        // Force the browser to apply the reset before starting the new transition,
        // otherwise the two style writes get coalesced and the bar appears to "jump".
        void bar.offsetWidth;
        bar.style.transition = `width ${seconds}s linear`;
        bar.style.width = '0%';
    }

    if (text) text.textContent = `Next update in ${seconds}s`;

    // The TEXT label still ticks down — but it only changes the textContent, no layout work.
    const totalTime = seconds * 1000;
    const startTime = performance.now();

    statsCountdownTimer = setInterval(() => {
        const elapsed = performance.now() - startTime;
        const currentRemaining = Math.max(0, Math.ceil(seconds - (elapsed / 1000)));
        if (text) text.textContent = `Next update in ${currentRemaining}s`;

        if (elapsed >= totalTime) {
            clearInterval(statsCountdownTimer);
            if (bar) {
                bar.style.transition = 'none';
                bar.style.width = '100%';
                bar.classList.add('syncing');
            }
            if (text) text.textContent = "Syncing Swarms...";
            loadStatsFull(true, false);
        }
    }, 250);
}

function renderHomeStats(json) {
    const widget = document.getElementById('home-stats-widget');
    if (!widget) return;

    widget.querySelector('.home-stats-skeleton')?.classList.add('hidden');
    widget.querySelector('.home-stats-content')?.classList.remove('hidden');

    // Same dedup logic as renderStatsDashboard — don't touch DOM if data hasn't changed.
    const at = parseInt(json.fetched_at || 0) || 0;
    if (at > 0 && at === statsHomeLastRenderedAt) {
        return;
    }
    if (at > 0) statsHomeLastRenderedAt = at;

    document.getElementById('home-val-torrents').textContent = json.torrents.toLocaleString();
    document.getElementById('home-val-seeds').textContent = json.seeds.toLocaleString();
    // 3rd figure is Leechers or Peers depending on the admin-selected style — only one exists.
    const homeLe = document.getElementById('home-val-leechers');
    if (homeLe) homeLe.textContent = json.leechers.toLocaleString();
    const homePe = document.getElementById('home-val-peers');
    if (homePe) homePe.textContent = Number(json.peers).toLocaleString();
    document.getElementById('home-val-completed').textContent = json.completed.toLocaleString();
    document.getElementById('home-val-uptime').textContent = json.uptime_string;
}

async function loadStatsHome(forceSync = false) {
    const widget = document.getElementById('home-stats-widget');
    if (!widget) return;
    if (statsHomeInFlight) return;

    const intervalSec = parseInt(widget.dataset.interval || '10');
    const source = widget.dataset.source || 'home';
    const beacon = widget.querySelector('.home-stat-beacon');

    clearTimeout(statsHomePollTimer);
    clearTimeout(statsHomeSyncingUiTimer);

    // Defer the beacon's syncing state by SYNCING_UI_DEFER_MS so quick fetches don't flicker.
    statsHomeSyncingUiTimer = setTimeout(() => {
        if (beacon) {
            beacon.classList.add('syncing');
            beacon.title = "Syncing Swarms...";
        }
    }, SYNCING_UI_DEFER_MS);

    if (statsHomeAbortController) {
        statsHomeAbortController.abort();
    }
    statsHomeAbortController = new AbortController();
    const signal = statsHomeAbortController.signal;

    const url = APP_BASE + 'api.php?endpoint=tracker_stats&source=' + encodeURIComponent(source)
              + (forceSync ? '' : '&stale_ok=1');

    statsHomeInFlight = true;
    let json = null;
    try {
        const res = await fetch(url, { signal });
        json = await res.json();
    } catch (e) {
        statsHomeInFlight = false;
        clearTimeout(statsHomeSyncingUiTimer);
        if (e.name === 'AbortError') return;
        if (beacon) { beacon.classList.remove('syncing'); beacon.title = "Sync failed"; }
        clearTimeout(statsHomePollTimer);
        statsHomePollTimer = setTimeout(() => loadStatsHome(false), 15000);
        return;
    }
    statsHomeInFlight = false;
    clearTimeout(statsHomeSyncingUiTimer);

    if (json && json.success) {
        renderHomeStats(json);

        if (json.syncing_in_background) {
            // Wait for the existing sync to finish — exponential backoff, not fixed 2s.
            if (beacon) { beacon.classList.add('syncing'); beacon.title = "Syncing Swarms..."; }
            const d = syncPollDelayMs(statsHomeSyncBackoff);
            statsHomeSyncBackoff++;
            statsHomePollTimer = setTimeout(() => loadStatsHome(false), d);
        } else if (json.sync_required) {
            // Trigger a blocking sync ourselves. Reset backoff.
            statsHomeSyncBackoff = 0;
            loadStatsHome(true);
        } else {
            // Fresh cache. Schedule the next poll at the home interval (not the server cache TTL).
            statsHomeSyncBackoff = 0;
            if (beacon) { beacon.classList.remove('syncing'); beacon.title = "Live Syncing"; }
            const remainingSec = json.remaining_seconds !== undefined ? parseInt(json.remaining_seconds) : intervalSec;
            statsHomePollTimer = setTimeout(() => loadStatsHome(false), Math.max(1, remainingSec) * 1000);
        }
    } else {
        // Non-success payload — either a transient 503 (busy) or a real error.
        if (json && (json.syncing_in_background || json.sync_required)) {
            const d = syncPollDelayMs(statsHomeSyncBackoff);
            statsHomeSyncBackoff++;
            statsHomePollTimer = setTimeout(() => loadStatsHome(false), d);
        } else {
            if (beacon) { beacon.classList.remove('syncing'); beacon.title = "Sync failed"; }
            statsHomePollTimer = setTimeout(() => loadStatsHome(false), 15000);
        }
    }
}
