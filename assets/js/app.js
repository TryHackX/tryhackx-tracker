// === CAPTCHA ===
// The modal itself lives in assets/js/captcha.js (window.showCaptchaModal / window.captchaReset),
// shared with the admin panel and provider-agnostic (reCAPTCHA v2 / v3, Turnstile, hCaptcha).
// `action` is only used by reCAPTCHA v3 (invisible: no modal, a score token is fetched silently);
// fetchWithCaptcha() derives it from the endpoint name (e.g. 'submit_report').
function requestCaptchaToken(action) {
    if (typeof window.showCaptchaModal !== 'function') return Promise.resolve(null);
    return window.showCaptchaModal({ action: action || 'submit' });
}

/** True when the last prompt failed on the widget/loader instead of being cancelled by the user. */
function captchaUnavailable() {
    return typeof window.captchaWasUnavailable === 'function' && window.captchaWasUnavailable();
}

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
        const token = await requestCaptchaToken(endpoint);
        // No token: either the visitor closed the box, or the widget itself failed (bad site key,
        // domain not allow-listed, provider blocked) — those two need different advice.
        if (!token) return { error: captchaUnavailable() ? 'CAPTCHA could not load — reload the page or try again later.' : 'CAPTCHA cancelled' };
        // Send under both names: `captcha_token` (generic) and the legacy reCAPTCHA field name.
        data['captcha_token'] = token;
        data['g-recaptcha-response'] = token;
        // Up to 3 attempts, 1 s apart: the server's own verifier call can die on a congested
        // uplink — retrying the SAME solved token usually succeeds a moment later, so the user
        // is not bounced back to a fresh CAPTCHA for a network hiccup.
        let last = null;
        for (let attempt = 1; attempt <= 3; attempt++) {
            try {
                const res2 = await fetch(APP_API + endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(data),
                });
                last = await res2.json();
            } catch {
                last = { error: 'Server returned an invalid response after CAPTCHA.' };
            }
            const failed = last && typeof last.error === 'string' && last.error.indexOf('CAPTCHA verification failed') !== -1;
            if (!failed) return last;
            if (attempt < 3) await new Promise(r => setTimeout(r, 1000));
        }
        return last;
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

    // Public whitelist registration page (?action=whitelist)
    initWhitelistPage();

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
            // Whitelist mode: the reported hash may not even be registered here (nothing to serve).
            if (json.whitelisted === false) {
                alert.textContent += ' Note: this info hash is not registered on this tracker (nothing to serve); the report is kept so the hash can be pre-banned.';
            }
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
            // Whitelist mode: a second badge — registered (served) / not registered — independent of blocks
            const wlRow = document.getElementById('bc-row-whitelist');
            if (wlRow) {
                if (typeof json.whitelisted === 'boolean') {
                    const wlEl = document.getElementById('bc-whitelist');
                    wlEl.textContent = '';
                    const badge = document.createElement('span');
                    badge.className = 'status-badge ' + (json.whitelisted ? 'checked' : 'blocked');
                    badge.textContent = json.whitelisted ? 'Whitelisted' : 'Not whitelisted';
                    wlEl.appendChild(badge);
                    wlEl.appendChild(document.createTextNode(json.whitelisted ? ' — registered, the tracker serves this swarm' : ' — not registered on this tracker (nothing is served)'));
                    wlRow.style.display = '';
                } else {
                    wlRow.style.display = 'none';
                }
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

// === Whitelist registration page (?action=whitelist) ===
// Client-side pre-validation mirrors the server (parseHashInput): one magnet link or 40-hex hash per
// token; the server is authoritative. Rendering uses textContent/escHtml only — inputs and names
// come from the user / other people.
function wlParseToken(tok) {
    tok = tok.trim();
    if (!tok) return null;
    if (/^magnet:\?/i.test(tok)) return extractHashFromMagnet(tok);
    if (/^(urn:btih:)?[a-f0-9]{40}$/i.test(tok)) return tok.replace(/^urn:btih:/i, '').toLowerCase();
    if (/^(urn:btih:)?[a-z2-7]{32}$/i.test(tok)) {
        try { const h = base32ToHex(tok.replace(/^urn:btih:/i, '').toUpperCase()); if (h && h.length === 40) return h; } catch {}
    }
    return '';
}

function wlCountInput(text) {
    const seen = new Set();
    let valid = 0, invalid = 0;
    text.split(/[\s,;]+/).filter(Boolean).forEach(tok => {
        const h = wlParseToken(tok);
        if (h === null) return;
        if (h) { if (!seen.has(h)) { seen.add(h); valid++; } } else invalid++;
    });
    return { valid, invalid };
}

function initWhitelistPage() {
    const form = document.getElementById('wl-form');
    const checkForm = document.getElementById('wl-check-form');
    if (form) {
        const ta = document.getElementById('wl-input');
        const counter = document.getElementById('wl-counter');
        const max = parseInt(ta.dataset.max || '20', 10);
        const refresh = () => {
            const c = wlCountInput(ta.value);
            counter.textContent = `${c.valid} valid` + (c.invalid ? ` / ${c.invalid} invalid` : '') + (c.valid > max ? ` — max ${max}` : '');
            counter.style.color = (c.valid > max) ? 'var(--error)' : (c.invalid ? 'var(--warning)' : '');
        };
        ta.addEventListener('input', refresh);
        refresh();
        form.addEventListener('submit', handleWhitelistSubmit);
    }
    if (checkForm) checkForm.addEventListener('submit', handleWhitelistCheck);
}

async function handleWhitelistSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const alert = document.getElementById('wl-alert');
    const btn = document.getElementById('wl-submit');
    const ta = document.getElementById('wl-input');
    const group = ta.closest('.form-group');
    const max = parseInt(ta.dataset.max || '20', 10);
    alert.className = 'alert';
    group.classList.remove('has-error');

    const c = wlCountInput(ta.value);
    if (c.valid === 0) { group.classList.add('has-error'); return; }
    if (c.valid > max) {
        alert.className = 'alert alert-error show';
        alert.textContent = `Too many hashes — at most ${max} per submission.`;
        return;
    }
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = 'Registering…';
    try {
        // The optional fields only make sense for a single torrent: one description cannot describe
        // twelve of them, and silently attaching it to all twelve would be worse than refusing.
        const srcEl = document.getElementById('wl-source');
        const descEl = document.getElementById('wl-desc');
        const fmtEl = document.getElementById('wl-desc-format');
        const payload = { input: ta.value, csrf_token: form.csrf_token.value };
        if (srcEl && srcEl.value.trim()) payload.source_url = srcEl.value.trim();
        if (descEl && descEl.value.trim()) {
            payload.description = descEl.value;
            payload.description_format = fmtEl ? fmtEl.value : 'bbcode';
        }
        const json = await fetchWithCaptcha('whitelist_submit', payload);
        if (json.success) {
            alert.className = 'alert alert-success show';
            const s = json.summary || {};
            const parts = [];
            if (s.added) parts.push(`${s.added} registered`);
            if (s.exists) parts.push(`${s.exists} already registered`);
            if (s.banned) parts.push(`${s.banned} banned`);
            if (s.invalid) parts.push(`${s.invalid} invalid`);
            let msg = parts.join(', ') + '.';
            if (s.added) {
                const secs = parseInt(json.active_in_seconds || 0, 10);
                msg += secs > 0 ? ` New hashes become active on the tracker within ~${secs} s.` : ' New hashes are active on the tracker.';
            }
            if (json.file_ok === false) msg += ' (Warning: the tracker list file could not be updated — the admin has been notified.)';
            // When the tracker checks submissions, "registered" is not the end of the story yet.
            if (json.probe && json.probe.on && (json.probe.hashes || []).length) {
                msg += ' They are being checked now — see below.';
                if (window.wlWatchProbe) window.wlWatchProbe(json.probe.hashes, json.probe.timeout_minutes);
            }
            if (json.content_proposed) {
                msg += ' This torrent already had a description, so yours was submitted as a proposed '
                     + 'change for a moderator to look at.';
            } else if (json.content_pending) {
                msg += ' Your description and link are waiting to be reviewed.';
            }
            alert.textContent = msg;
            renderWhitelistResults(json);
            ta.value = '';
            document.getElementById('wl-counter').textContent = '0 valid';
            btn.textContent = orig;
            startCooldown(btn, 10);
        } else {
            btn.textContent = orig;
            const messages = {
                rate_limit: 'Too many submissions from your network. Try again later.',
                daily_cap: 'Daily registration limit reached. Try again tomorrow.',
                too_many: `Too many hashes — at most ${max} per submission.`,
                no_valid: 'No valid magnet links or info hashes found.',
                registration_disabled: 'Public registration is disabled on this tracker.',
                registration_unavailable: 'Registration is temporarily unavailable (CAPTCHA not configured).',
                'CAPTCHA cancelled': 'CAPTCHA cancelled — please try again.',
                'CAPTCHA verification failed': 'CAPTCHA verification failed — please try again.',
            };
            showFormSubmitError(form, alert, btn, json, messages);
            if (json && json.retry_after) startCooldown(btn, Math.min(120, parseInt(json.retry_after, 10) || 60));
            if (json && Array.isArray(json.results)) renderWhitelistResults(json);
        }
    } catch {
        btn.textContent = orig;
        showFormNetworkError(alert, btn);
    }
}

function renderWhitelistResults(json) {
    const box = document.getElementById('wl-results');
    const list = document.getElementById('wl-results-list');
    const summary = document.getElementById('wl-results-summary');
    if (!box || !list) return;
    list.textContent = '';
    const results = Array.isArray(json.results) ? json.results : [];
    if (!results.length) { box.hidden = true; return; }
    const labels = { added: 'Registered', exists: 'Already registered', banned: 'Banned', invalid: 'Invalid' };
    results.forEach(r => {
        const row = document.createElement('div');
        row.className = 'wl-row wl-' + (r.status || 'invalid');
        const badge = document.createElement('span');
        badge.className = 'wl-badge';
        badge.textContent = labels[r.status] || r.status;
        const main = document.createElement('div');
        main.className = 'wl-row-main';
        const hash = document.createElement('code');
        hash.className = 'wl-hash';
        hash.textContent = r.hash || (r.input || '').slice(0, 80);
        main.appendChild(hash);
        if (r.error) {
            const err = document.createElement('div');
            err.className = 'wl-error';
            err.textContent = r.error;
            main.appendChild(err);
        }
        const magnet = r.hash && json.magnets ? json.magnets[r.hash] : null;
        if (magnet) {
            const mrow = document.createElement('div');
            mrow.className = 'wl-magnet';
            const mcode = document.createElement('code');
            mcode.textContent = magnet;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'copy-btn wl-copy';
            b.title = 'Copy magnet link';
            b.textContent = 'Copy';
            b.addEventListener('click', () => {
                navigator.clipboard.writeText(magnet).then(() => { b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1500); });
            });
            mrow.appendChild(mcode);
            mrow.appendChild(b);
            main.appendChild(mrow);
        }
        row.appendChild(badge);
        row.appendChild(main);
        list.appendChild(row);
    });
    if (summary) summary.textContent = '';
    box.hidden = false;
}

async function handleWhitelistCheck(e) {
    e.preventDefault();
    const input = document.getElementById('wl-check-input');
    const alert = document.getElementById('wl-check-alert');
    const btn = document.getElementById('wl-check-submit');
    const raw = input.value.trim();
    alert.className = 'alert';
    const h = wlParseToken(raw);
    if (!h) {
        alert.className = 'alert alert-error show';
        alert.textContent = 'Enter a magnet link or a 40-character info hash.';
        return;
    }
    btn.disabled = true;
    try {
        const res = await fetch(APP_API + 'whitelist_check&hash=' + encodeURIComponent(h), { headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        if (json.success) {
            if (json.banned) {
                alert.className = 'alert alert-error show';
                alert.textContent = `Hash ${json.hash} is BANNED on this tracker.`;
            } else if (json.whitelisted) {
                alert.className = 'alert alert-success show';
                alert.textContent = `Hash ${json.hash} is registered` + (json.added_at ? ` (since ${json.added_at})` : '') + '.';
            } else {
                alert.className = 'alert alert-error show';
                alert.textContent = json.mode === 'whitelist' ? `Hash ${json.hash} is NOT registered on this tracker.` : `Hash ${json.hash} is served (open tracker mode).`;
            }
        } else {
            alert.className = 'alert alert-error show';
            alert.textContent = json.error || 'Lookup failed.';
        }
    } catch {
        alert.className = 'alert alert-error show';
        alert.textContent = 'Network error. Please try again.';
    } finally {
        startCooldown(btn, 3);
    }
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

// === User accounts (?action=login / register / account / reset) + index search (?action=search) ===
// All rendering uses textContent — usernames, group names, notification titles and torrent names
// are untrusted. Endpoints: user_login/user_register/user_logout/user_me/user_update/
// user_notifications/user_verify_send/user_reset_request/user_reset_confirm/index_search/index_files.
(function () {
    'use strict';
    const $id = (x) => document.getElementById(x);
    const csrfOf = (form) => (form.querySelector('[name="csrf_token"]') || $id('account-csrf') || { value: '' }).value;
    const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    function showAlert(el, msg, ok) {
        el.className = 'alert show ' + (ok ? 'alert-success' : 'alert-error');
        el.textContent = msg;
    }
    // torrent sizes are powers of 1024 — label them with the matching IEC units (KiB/MiB/GiB)
    function fmtBytesPub(n) {
        n = Number(n);
        if (!isFinite(n) || n <= 0) return '—';
        const u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(n >= 100 ? 0 : n >= 10 ? 1 : 2)) + ' ' + u[i];
    }
    // ── password policy (mirrors userPasswordIssues() server-side) + live checklist UI ──
    const PW_REQS = [
        ['At least 8 characters', (p) => p.length >= 8 && p.length <= 200],
        ['A lowercase letter', (p) => /[a-z]/.test(p)],
        ['An uppercase letter', (p) => /[A-Z]/.test(p)],
        ['A special character', (p) => /[^a-zA-Z0-9]/.test(p)],
        ['A digit', (p) => /[0-9]/.test(p)],
    ];
    const pwValid = (p) => PW_REQS.every(([, t]) => t(p));
    /** Requirement checklist under a password box; optional=true hides it while the box is empty. */
    function bindPwChecklist(input, box, optional) {
        if (!input || !box) return;
        const items = PW_REQS.map(([label]) => {
            const li = document.createElement('div');
            li.className = 'pw-req';
            const ic = document.createElement('span');
            ic.className = 'pw-req-ic';
            ic.textContent = '✗';
            li.appendChild(ic);
            li.appendChild(document.createTextNode(' ' + label));
            box.appendChild(li);
            return li;
        });
        const sync = () => {
            const p = input.value;
            if (optional) box.hidden = p === '';
            PW_REQS.forEach(([, test], i) => {
                const ok = test(p);
                items[i].classList.toggle('ok', ok);
                items[i].querySelector('.pw-req-ic').textContent = ok ? '✓' : '✗';
            });
        };
        input.addEventListener('input', sync);
        sync();
    }
    /** Tiny tooltip above an element ("Marked 3 read") — auto-fades, no library. */
    function pubTip(target, text) {
        if (!target) return;
        const old = target.querySelector(':scope > .pub-tip');
        if (old) old.remove();
        const tip = document.createElement('span');
        tip.className = 'pub-tip';
        tip.textContent = text;
        target.style.position = 'relative';
        target.appendChild(tip);
        requestAnimationFrame(() => tip.classList.add('show'));
        setTimeout(() => { tip.classList.remove('show'); setTimeout(() => tip.remove(), 250); }, 1800);
    }
    /** Accelerating "held backspace" clear (same effect as the admin toolbars). */
    function animatedClearPub(input, done) {
        if (!input) { if (done) done(); return; }
        if (input.__clearing) return;
        input.__clearing = true;
        if (input.value.length > 40) input.value = input.value.slice(-40);
        let delay = 55;
        const step = () => {
            if (!input.value.length) { input.__clearing = false; if (done) done(); return; }
            input.value = input.value.slice(0, -1);
            delay = Math.max(7, delay * 0.82);
            setTimeout(step, delay);
        };
        step();
    }
    /** Append `text` to `parent`, wrapping every occurrence of any of `tokens` in <mark>. */
    function markInto(parent, text, tokens) {
        text = String(text == null ? '' : text);
        if (!tokens || !tokens.length) { parent.appendChild(document.createTextNode(text)); return; }
        let rest = text;
        while (rest) {
            let best = -1, bestLen = 0;
            const low = rest.toLowerCase();
            for (const t of tokens) {
                const i = low.indexOf(t);
                if (i !== -1 && (best === -1 || i < best || (i === best && t.length > bestLen))) { best = i; bestLen = t.length; }
            }
            if (best === -1) { parent.appendChild(document.createTextNode(rest)); break; }
            if (best > 0) parent.appendChild(document.createTextNode(rest.slice(0, best)));
            const m = document.createElement('mark');
            m.textContent = rest.slice(best, best + bestLen);
            parent.appendChild(m);
            rest = rest.slice(best + bestLen);
        }
    }
    const queryTokens = (q) => [...new Set(String(q).toLowerCase().split(/[^\p{L}\p{N}]+/u).filter(t => t.length >= 2))];

    /**
     * Live per-field validation: `check` returns true when the CURRENT value is acceptable.
     * The error only shows after the field was touched (blurred once) or a submit was attempted,
     * so users are not yelled at while still typing their first character.
     */
    function liveValidate(input, check) {
        if (!input) return () => true;
        const group = input.closest('.form-group');
        let touched = false;
        const apply = () => { if (group) group.classList.toggle('has-error', touched && !check()); };
        input.addEventListener('input', apply);
        input.addEventListener('blur', () => { touched = true; apply(); });
        return (forceTouch) => { if (forceTouch) touched = true; apply(); return check(); };
    }
    const fmtDatePub = (s) => {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? String(s) : d.toLocaleString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    };
    const postJson = async (endpoint, body) => {
        try {
            const res = await fetch(APP_API + endpoint, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            return await res.json();
        } catch { return null; }
    };
    const getJson = async (endpoint) => {
        try { return await (await fetch(APP_API + endpoint, { headers: { 'Accept': 'application/json' } })).json(); }
        catch { return null; }
    };

    // ── sign in ──
    function initLogin() {
        const form = $id('login-form');
        if (!form) return;
        const login = $id('login-login'), pass = $id('login-password');
        const vLogin = liveValidate(login, () => login.value.trim() !== '');
        const vPass = liveValidate(pass, () => pass.value !== '');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alert = $id('login-alert'), btn = $id('login-submit');
            const ok = [vLogin(true), vPass(true)].every(Boolean);
            if (!ok) return;
            btn.disabled = true;
            const json = await fetchWithCaptcha('user_login', {
                csrf_token: csrfOf(form),
                login: login.value.trim(),
                password: pass.value,
                session: ($id('login-session') || { value: 'forever' }).value,
            });
            if (json && json.success) {
                showAlert(alert, 'Signed in — loading your account…', true);
                window.location.href = APP_BASE + '?action=account';
            } else {
                showAlert(alert, (json && json.error) || 'Sign-in failed', false);
                btn.disabled = false;
            }
        });
    }

    // ── register ──
    function initRegister() {
        const form = $id('register-form');
        if (!form) return;
        const u = $id('reg-username'), em = $id('reg-email'), p1 = $id('reg-password'), p2 = $id('reg-password2');
        // real-time validation: errors appear once a field was left (or on submit), then track typing
        const emailRequired = form.dataset.emailRequired === '1';
        const vUser = liveValidate(u, () => /^[A-Za-z0-9_.-]{3,32}$/.test(u.value.trim()));
        const vMail = liveValidate(em, () => (emailRequired ? em.value.trim() !== '' : true) && (em.value.trim() === '' || EMAIL_RE.test(em.value.trim())));
        bindPwChecklist(p1, $id('reg-pw-checklist'), false);
        const vP1 = liveValidate(p1, () => pwValid(p1.value));
        const vP2 = liveValidate(p2, () => p2.value === p1.value);
        const terms = $id('reg-terms');
        const vTerms = () => { const ok = terms.checked; terms.closest('.form-group').classList.toggle('has-error', !ok); return ok; };
        terms.addEventListener('change', vTerms);
        // custom terms (admin-pasted text) open in a modal instead of the tos page
        if (form.dataset.termsCustom === '1') {
            const overlay = $id('terms-overlay');
            const close = () => { overlay.hidden = true; };
            $id('reg-terms-link').addEventListener('click', (e) => { e.preventDefault(); overlay.hidden = false; });
            $id('terms-close').addEventListener('click', close);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        }
        p1.addEventListener('input', () => p2.dispatchEvent(new Event('input')));   // re-check the repeat too
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alert = $id('register-alert'), btn = $id('register-submit');
            const ok = [vUser(true), vMail(true), vP1(true), vP2(true), vTerms()].every(Boolean);
            if (!ok) return;
            btn.disabled = true;
            const json = await fetchWithCaptcha('user_register', {
                csrf_token: csrfOf(form),
                username: u.value.trim(), email: em.value.trim(), password: p1.value,
                terms_accepted: 1,
            });
            if (json && json.success) {
                showAlert(alert, 'Account created — welcome!' + (json.verify_sent ? ' A confirmation link was sent to your email address.' : ''), true);
                window.location.href = APP_BASE + '?action=account';
            } else {
                showAlert(alert, (json && json.error) || 'Registration failed', false);
                btn.disabled = false;
            }
        });
    }

    // ── account page ──
    async function loadAccount() {
        const groupsBox = $id('acc-groups');
        const me = await getJson('user_me');
        if (!me || !me.success) { groupsBox.textContent = 'Could not load your groups.'; return; }
        groupsBox.textContent = '';
        if (!me.groups.length) {
            const none = document.createElement('span');
            none.className = 'text-muted';
            none.textContent = 'No groups yet.';
            groupsBox.appendChild(none);
        } else {
            me.groups.forEach(g => {
                const div = document.createElement('div');
                div.className = 'acc-group';
                const name = document.createElement('strong');
                name.textContent = g.name;
                if (g.color && /^#[0-9a-fA-F]{3,8}$/.test(g.color)) name.style.color = g.color;
                div.appendChild(name);
                const until = document.createElement('span');
                until.className = 'text-muted';
                until.textContent = g.expires_at ? ' — until ' + fmtDatePub(g.expires_at) : ' — permanent';
                div.appendChild(until);
                if (g.description) {
                    const d = document.createElement('div');
                    d.className = 'acc-group-desc text-muted';
                    d.textContent = g.description;
                    div.appendChild(d);
                }
                groupsBox.appendChild(div);
            });
        }
        const navBadge = $id('nav-unread'), accBadge = $id('acc-unread-badge');
        if (navBadge) { navBadge.textContent = String(me.unread); navBadge.hidden = me.unread <= 0; }
        if (accBadge) { accBadge.textContent = me.unread + ' unread'; accBadge.hidden = me.unread <= 0; }
    }
    let notifPage = 1;
    async function loadNotifications(page) {
        if (page) notifPage = page;
        const box = $id('acc-notifications');
        const pag = $id('acc-notif-pagination');
        const json = await getJson('user_notifications&page=' + notifPage);
        if (!json || !json.success) { box.textContent = 'Could not load notifications.'; return; }
        box.textContent = '';
        if (pag) pag.textContent = '';
        if (!json.notifications.length) {
            const none = document.createElement('span');
            none.className = 'text-muted';
            none.textContent = 'Nothing here yet.';
            box.appendChild(none);
            return;
        }
        json.notifications.forEach(n => {
            const item = document.createElement('div');
            item.className = 'acc-notif' + (n.read_at ? ' acc-notif-read' : '');
            const head = document.createElement('div');
            head.className = 'acc-notif-head';
            const t = document.createElement('strong');
            t.textContent = n.title;
            head.appendChild(t);
            const when = document.createElement('span');
            when.className = 'text-muted';
            when.textContent = fmtDatePub(n.created_at);
            head.appendChild(when);
            item.appendChild(head);
            if (n.body) {
                const b = document.createElement('div');
                b.className = 'acc-notif-body';
                b.textContent = n.body;
                item.appendChild(b);
            }
            if (!n.read_at) {
                const mark = document.createElement('button');
                mark.type = 'button';
                mark.className = 'btn btn-secondary btn-small';
                mark.textContent = 'Mark read';
                mark.addEventListener('click', async () => {
                    await postJson('user_notifications', { csrf_token: $id('account-csrf').value, ids: [n.id] });
                    loadNotifications(); loadAccount();
                });
                item.appendChild(mark);
            }
            box.appendChild(item);
        });
        if (pag && json.pages > 1) {
            const mk = (label, target, disabled) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.disabled = !!disabled;
                b.addEventListener('click', () => loadNotifications(target));
                return b;
            };
            pag.appendChild(mk('‹ Prev', json.page - 1, json.page <= 1));
            const info = document.createElement('span');
            info.textContent = 'Page ' + json.page + ' of ' + json.pages + ' · ' + json.total + ' total';
            pag.appendChild(info);
            pag.appendChild(mk('Next ›', json.page + 1, json.page >= json.pages));
        }
    }
    function initAccount() {
        if (!$id('account-form')) {
            // not on the account page — still light up the nav badge for signed-in users
            if ($id('nav-unread')) {
                getJson('user_me').then(me => {
                    if (me && me.success && me.unread > 0) { const b = $id('nav-unread'); b.textContent = String(me.unread); b.hidden = false; }
                });
            }
            return;
        }
        loadAccount();
        loadNotifications(1);
        $id('acc-mark-all').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const r = await postJson('user_notifications', { csrf_token: $id('account-csrf').value, all: 1 });
            pubTip(btn, r && r.success ? (r.marked > 0 ? 'Marked ' + r.marked + ' read' : 'Nothing unread') : 'Failed');
            loadNotifications(); loadAccount();
        });
        const delRead = $id('acc-delete-read');
        if (delRead) delRead.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const r = await postJson('user_notifications', { csrf_token: $id('account-csrf').value, delete_read: 1 });
            pubTip(btn, r && r.success ? (r.deleted > 0 ? 'Deleted ' + r.deleted : 'Nothing to delete') : 'Failed');
            if (r && r.success) loadNotifications(1);
        });
        const cancelEc = $id('acc-cancel-echange');
        if (cancelEc) cancelEc.addEventListener('click', async () => {
            cancelEc.disabled = true;
            const r = await postJson('user_update', { csrf_token: $id('account-csrf').value, cancel_email_change: 1 });
            if (r && r.success) location.reload();
            else cancelEc.disabled = false;
        });
        // Two preferences, one endpoint. Account mail and announcements are separate on purpose:
        // somebody who wants no announcements still needs the password-reset message to arrive.
        const mailPref = $id('acc-mail-pref');
        const bulkPref = $id('acc-bulk-pref');
        if (mailPref || bulkPref) {
            const label = $id('acc-mail-pref-label');
            const bulkLabel = $id('acc-bulk-pref-label');
            getJson('user_email_prefs').then(r => {
                if (!r || !r.success) {
                    if (label) label.textContent = 'unavailable';
                    if (bulkLabel) bulkLabel.textContent = 'unavailable';
                    return;
                }
                if (mailPref) { mailPref.checked = !!r.enabled; label.textContent = r.enabled ? 'enabled' : 'disabled'; }
                if (bulkPref) { bulkPref.checked = !!r.bulk_enabled; bulkLabel.textContent = r.bulk_enabled ? 'enabled' : 'disabled'; }
            });
            const bind = (box, lab, type) => {
                if (!box) return;
                box.addEventListener('change', async () => {
                    const r = await postJson('user_email_prefs', {
                        csrf_token: $id('account-csrf').value, enabled: box.checked ? 1 : 0, type });
                    if (r && r.success) lab.textContent = r.enabled ? 'enabled' : 'disabled';
                    else { box.checked = !box.checked; }
                });
            };
            bind(mailPref, label, 'account');
            bind(bulkPref, bulkLabel, 'bulk');
        }
        const verifyBtn = $id('acc-verify-send');
        if (verifyBtn) verifyBtn.addEventListener('click', async () => {
            verifyBtn.disabled = true;
            const r = await postJson('user_verify_send', { csrf_token: $id('account-csrf').value });
            if (r && r.success && r.sent) { verifyBtn.textContent = 'Sent ✓'; }
            else { verifyBtn.textContent = 'Failed'; verifyBtn.title = (r && (r.message || r.error)) || 'Could not send'; setTimeout(() => { verifyBtn.textContent = 'Resend link'; verifyBtn.disabled = false; }, 4000); }
        });
        $id('account-logout').addEventListener('click', async () => {
            await postJson('user_logout', { csrf_token: $id('account-csrf').value });
            window.location.href = APP_BASE;
        });
        // live validation: email format + repeat box when it changes; password policy + repeat box
        const emailIn = $id('acc-new-email'), email2In = $id('acc-new-email2'), passIn = $id('acc-new-pass'), pass2In = $id('acc-new-pass2');
        const pass2Group = $id('acc-new-pass2-group'), email2Group = $id('acc-new-email2-group');
        const emailChanged = () => {
            const curEmail = $id('acc-email').textContent.trim();
            const had = curEmail !== '' && curEmail !== 'none';
            return emailIn.value.trim() !== (had ? curEmail : '');
        };
        bindPwChecklist(passIn, $id('acc-pw-checklist'), true);
        const vMail = liveValidate(emailIn, () => emailIn.value.trim() === '' || EMAIL_RE.test(emailIn.value.trim()));
        const vMail2 = liveValidate(email2In, () => !emailChanged() || emailIn.value.trim() === '' || email2In.value.trim() === emailIn.value.trim());
        const vPass = liveValidate(passIn, () => passIn.value === '' || pwValid(passIn.value));
        const vPass2 = liveValidate(pass2In, () => passIn.value === '' || pass2In.value === passIn.value);
        emailIn.addEventListener('input', () => {
            email2Group.hidden = !emailChanged() || emailIn.value.trim() === '';
            if (email2In.value !== '') email2In.dispatchEvent(new Event('input'));
        });
        passIn.addEventListener('input', () => {
            pass2Group.hidden = passIn.value === '';
            if (pass2In.value !== '') pass2In.dispatchEvent(new Event('input'));
        });
        $id('account-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const alert = $id('account-alert'), btn = $id('account-save');
            const curEmail = $id('acc-email').textContent.trim();
            const hadEmail = curEmail !== '' && curEmail !== 'none';
            const newEmail = emailIn.value.trim();
            if (![vMail(true), vMail2(true), vPass(true), vPass2(true)].every(Boolean)) return;
            const body = { csrf_token: $id('account-csrf').value, current_password: $id('acc-cur-pass').value };
            // an emptied box removes the address; anything different from the current one changes it
            if (newEmail !== (hadEmail ? curEmail : '')) body.email = newEmail;
            if (passIn.value !== '') body.new_password = passIn.value;
            if (body.email === undefined && body.new_password === undefined) { showAlert(alert, 'Nothing to change.', false); return; }
            btn.disabled = true;
            const json = await postJson('user_update', body);
            btn.disabled = false;
            if (json && json.success) {
                let msg = 'Saved.';
                if (json.changed.includes('password')) msg += ' Use the new password next time you sign in.';
                if (json.email_stage === 'old') msg += ' Email change started — confirm it from your CURRENT mailbox first (step 1 of 2), then from the new one.';
                else if (json.email_stage === 'done_direct') msg += json.verify_sent ? ' A verification link was sent to the new address.' : ' Email saved.';
                showAlert(alert, msg, true);
                $id('acc-cur-pass').value = ''; passIn.value = ''; pass2In.value = ''; pass2Group.hidden = true;
                email2In.value = ''; email2Group.hidden = true; $id('acc-pw-checklist').hidden = true;
                if (json.email_stage === 'done_direct') {
                    $id('acc-email').textContent = body.email || 'none';
                } else if (json.email_stage === 'old') {
                    setTimeout(() => location.reload(), 2500);   // show the pending-change banner
                }
            } else {
                showAlert(alert, (json && json.error) || 'Update failed', false);
            }
        });
    }

    // ── password reset ──
    function initReset() {
        const reqForm = $id('reset-request-form');
        if (reqForm) {
            reqForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const alert = $id('reset-alert'), btn = $id('reset-submit');
                btn.disabled = true;
                const json = await fetchWithCaptcha('user_reset_request', {
                    csrf_token: csrfOf(reqForm), login: $id('reset-login').value.trim(),
                });
                btn.disabled = false;
                if (json && json.success) showAlert(alert, json.message || 'Check your inbox.', true);
                else showAlert(alert, (json && json.error) || 'Request failed', false);
            });
        }
        const confForm = $id('reset-confirm-form');
        if (confForm) {
            bindPwChecklist($id('resetc-password'), $id('resetc-pw-checklist'), false);
            confForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const alert = $id('resetc-alert'), btn = $id('resetc-submit');
                const p1 = $id('resetc-password'), p2 = $id('resetc-password2');
                if (!pwValid(p1.value)) { showAlert(alert, 'The password does not meet the requirements.', false); return; }
                if (p1.value !== p2.value) { showAlert(alert, 'Passwords do not match.', false); return; }
                btn.disabled = true;
                const json = await postJson('user_reset_confirm', { csrf_token: csrfOf(confForm), token: $id('resetc-token').value, password: p1.value });
                btn.disabled = false;
                if (json && json.success) {
                    showAlert(alert, 'Password changed — you can sign in now.', true);
                    setTimeout(() => { window.location.href = APP_BASE + '?action=login'; }, 1200);
                } else showAlert(alert, (json && json.error) || 'Reset failed', false);
            });
        }
    }

    // ── index search ──
    function initSearch() {
        const form = $id('search-form');
        if (!form) return;
        const canMagnet = form.dataset.canMagnet === '1';
        const canFiles = form.dataset.canFiles === '1';
        // Every port, not just the first. Extra opentracker instances listen on their own ports and
        // share nothing between them, so a magnet that names one port is only ever answered by one
        // process. The attribute is empty without the cluster, which leaves this unchanged.
        const announces = [form.dataset.announce, form.dataset.announceHttps]
            .concat((form.dataset.announceExtra || '').split(/\s+/))
            .filter(Boolean);
        const input = $id('search-input'), clearBtn = $id('search-clear');
        const bestBox = $id('search-best'), filesBox = $id('search-files');
        const magnetFor = (hash, name) => {
            let m = 'magnet:?xt=urn:btih:' + hash;
            if (name) m += '&dn=' + encodeURIComponent(name);
            announces.forEach(u => { m += '&tr=' + encodeURIComponent(u); });
            return m;
        };
        // ── multi-column sort stack on the table headers (desc → asc → off, priority badges) ──
        const headers = [...document.querySelectorAll('#search-table th.search-sortable')];
        const sortStack = [];
        function serializeSort() {
            const parts = [];
            if (!bestBox || bestBox.checked) parts.push('relevance:desc');
            sortStack.forEach(x => parts.push(x.col + ':' + x.dir));
            if (!parts.length) parts.push('seeders:desc');
            return parts.join(',');
        }
        function updateSortIcons() {
            headers.forEach(th => {
                const icon = th.querySelector('.search-sort-icon');
                const idx = sortStack.findIndex(x => x.col === th.dataset.sort);
                const old = th.querySelector('.search-sort-priority');
                if (old) old.remove();
                if (idx === -1) { icon.textContent = '↕'; icon.classList.remove('active'); return; }
                icon.textContent = sortStack[idx].dir === 'asc' ? '▲' : '▼';
                icon.classList.add('active');
                if (sortStack.length > 1) {
                    const sup = document.createElement('sup');
                    sup.className = 'search-sort-priority';
                    sup.textContent = String(idx + 1);
                    icon.after(sup);
                }
            });
        }
        headers.forEach(th => th.addEventListener('click', () => {
            const col = th.dataset.sort;
            const idx = sortStack.findIndex(x => x.col === col);
            if (idx === -1) sortStack.push({ col, dir: 'desc' });
            else if (sortStack[idx].dir === 'desc') sortStack[idx].dir = 'asc';
            else sortStack.splice(idx, 1);
            updateSortIcons();
            run(1);
        }));
        if (bestBox) bestBox.addEventListener('change', () => run(1));
        const perPageSel = $id('search-perpage');
        try { const saved = localStorage.getItem('thx_search_perpage'); if (saved && perPageSel && [...perPageSel.options].some(o => o.value === saved)) perPageSel.value = saved; } catch (e) {}
        if (perPageSel) perPageSel.addEventListener('change', () => { try { localStorage.setItem('thx_search_perpage', perPageSel.value); } catch (e) {} run(1); });
        let curPage = 1, seq = 0;
        let lastTokens = [], lastFilesSearch = false;
        function setLoading(on) {
            const table = $id('search-table');
            table.classList.toggle('search-loading', on);
            const t = $id('search-total');
            if (on) { t.dataset.prev = t.textContent; t.textContent = 'Searching…'; }
        }
        async function run(page) {
            curPage = page;
            const my = ++seq;   // stale responses (fast typing) must not overwrite newer ones
            const alert = $id('search-alert'), table = $id('search-table'), body = $id('search-body'), note = $id('search-note');
            alert.className = 'alert';
            setLoading(true);
            const qs = new URLSearchParams({ page: String(page), sort: serializeSort() });
            if (perPageSel) qs.set('per_page', perPageSel.value);
            const q = input.value.trim();
            if (q) qs.set('search', q);
            const filesOn = !!(filesBox && filesBox.checked);
            if (filesOn) qs.set('search_files', '1');
            const contentSel = $id('search-content');
            if (contentSel && contentSel.value) qs.set('content', contentSel.value);
            const json = await getJson('index_search&' + qs.toString());
            if (my !== seq) return;
            setLoading(false);
            if (!json || !json.success) {
                table.hidden = true;
                note.hidden = true;
                $id('search-total').textContent = '';
                renderPager(1, 1, 0);
                const code = json && json.error;
                showAlert(alert, code === 'rate_limit' ? 'Rate limit reached — try again later.'
                    : code === 'login_required' ? 'Please sign in to search.'
                    : code || 'Search failed.', false);
                return;
            }
            lastTokens = q ? queryTokens(q) : [];
            lastFilesSearch = filesOn && !!q;
            body.textContent = '';
            json.rows.forEach(r => {
                const tr = document.createElement('tr');
                const nameTd = document.createElement('td');
                nameTd.className = 'search-name';
                const nameSpan = document.createElement('span');
                nameSpan.title = r.name || '';
                if (lastTokens.length) markInto(nameSpan, r.name || '(no name)', lastTokens);
                else nameSpan.textContent = r.name || '(no name)';
                nameTd.appendChild(nameSpan);
                if (r.src === 'whitelist') {
                    const wb = document.createElement('span');
                    wb.className = 'search-wl-badge';
                    wb.title = 'Registered on this tracker (whitelisted)';
                    wb.textContent = 'WL';
                    nameTd.appendChild(wb);
                }
                // The state of the words attached to it, not of the torrent. Only shown when there
                // is a state to show: an index row nobody has written about has none.
                if (r.content_status === 'approved' || r.content_status === 'rejected') {
                    const cb = document.createElement('span');
                    cb.className = 'search-wl-badge search-cs-' + r.content_status;
                    cb.title = r.content_status === 'approved'
                        ? 'The description and source link were reviewed and published'
                        : 'The description was reviewed and turned down — the torrent itself is unaffected';
                    cb.textContent = r.content_status === 'approved' ? 'OK' : 'REJ';
                    nameTd.appendChild(cb);
                }
                if (r.files_count) {
                    const fc = document.createElement(canFiles && r.info_hash ? 'button' : 'span');
                    fc.className = 'search-files-chip' + (lastFilesSearch ? ' chip-hit' : '');
                    fc.textContent = r.files_count + (r.files_count === 1 ? ' file' : ' files');
                    if (canFiles && r.info_hash) {
                        fc.type = 'button';
                        fc.title = lastFilesSearch ? 'Show the file list (your query also matched file names here)' : 'Show the file list';
                        fc.addEventListener('click', () => openFiles(r.info_hash, r.name));
                    }
                    nameTd.appendChild(fc);
                }
                tr.appendChild(nameTd);
                const sizeTd = document.createElement('td');
                sizeTd.className = 'search-num';
                sizeTd.textContent = fmtBytesPub(r.size);
                tr.appendChild(sizeTd);
                const slTd = document.createElement('td');
                slTd.className = 'search-num';
                slTd.textContent = (r.seeders == null ? '—' : r.seeders) + ' / ' + (r.leechers == null ? '—' : r.leechers);
                tr.appendChild(slTd);
                if (json.rep_in_results) {
                    const repTd = document.createElement('td');
                    repTd.className = 'search-num search-rep';
                    if (r.rep) {
                        // The count comes with the percentage, always. A column that shows only
                        // "100%" makes one vote look like four hundred.
                        repTd.className += r.rep.pct >= 50 ? ' search-rep-up' : ' search-rep-down';
                        repTd.textContent = r.rep.pct + '%';
                        repTd.title = r.rep.total + (r.rep.total === 1 ? ' rating' : ' ratings');
                    } else {
                        repTd.textContent = '—';
                        repTd.title = 'Too few ratings to show a score';
                    }
                    tr.appendChild(repTd);
                }
                const seenTd = document.createElement('td');
                seenTd.className = 'search-num';
                seenTd.textContent = fmtDatePub(r.last_seen);
                tr.appendChild(seenTd);
                if (canMagnet) {
                    const magTd = document.createElement('td');
                    magTd.className = 'search-actions';
                    if (r.info_hash) {
                        const a = document.createElement('a');
                        a.href = magnetFor(r.info_hash, r.name);
                        a.className = 'btn btn-small search-act-btn';
                        a.title = 'Open in your torrent client';
                        a.textContent = 'Magnet';
                        magTd.appendChild(a);
                        const copy = document.createElement('button');
                        copy.type = 'button';
                        copy.className = 'btn btn-secondary btn-small search-act-btn';
                        copy.title = 'Copy the magnet link';
                        copy.textContent = 'Copy';
                        copy.addEventListener('click', () => {
                            if (!navigator.clipboard) return;
                            navigator.clipboard.writeText(magnetFor(r.info_hash, r.name))
                                .then(() => { copy.textContent = '✓'; copy.classList.add('copied'); setTimeout(() => { copy.textContent = 'Copy'; copy.classList.remove('copied'); }, 1200); })
                                .catch(() => {});
                        });
                        magTd.appendChild(copy);
                        const info = document.createElement('button');
                        info.type = 'button';
                        info.className = 'btn btn-secondary btn-small search-act-btn';
                        info.title = 'What this is, where it came from, and how the swarm looks';
                        info.textContent = 'Info';
                        info.addEventListener('click', () => openInfo(r.info_hash, r.name));
                        magTd.appendChild(info);
                    }
                    tr.appendChild(magTd);
                }
                body.appendChild(tr);
            });
            table.hidden = json.rows.length === 0;
            $id('search-total').textContent = json.total === 0 ? '' : json.total.toLocaleString() + ' result' + (json.total === 1 ? '' : 's');
            note.hidden = json.total !== 0;
            note.textContent = json.total === 0 ? 'Nothing found.' : '';
            renderPager(json.page, json.pages, json.total);
        }
        // « First / ‹ Prev / Page [n] of M · X rows / Next › / Last » — same pattern as the admin tables
        function renderPager(page, pages, total) {
            const box = $id('search-pagination');
            box.textContent = '';
            if (pages <= 1) return;
            const go = (p) => { p = Math.min(pages, Math.max(1, Math.round(p))); if (p !== page) run(p); };
            const mk = (label, target, disabled, cls) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.disabled = !!disabled;
                if (cls) b.className = cls;
                b.addEventListener('click', () => go(target));
                return b;
            };
            box.appendChild(mk('« First', 1, page <= 1, 'pg-edge'));
            box.appendChild(mk('‹ Prev', page - 1, page <= 1));
            const jump = document.createElement('span');
            jump.className = 'pg-jump';
            jump.appendChild(document.createTextNode('Page '));
            const inp = document.createElement('input');
            inp.type = 'number'; inp.min = '1'; inp.max = String(pages); inp.value = String(page);
            inp.className = 'pg-input'; inp.title = 'Go to page (Enter)';
            const jumpTo = () => { const n = Number(String(inp.value).trim()); if (isFinite(n) && n >= 1) go(n); else inp.value = String(page); };
            inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); jumpTo(); } });
            inp.addEventListener('change', jumpTo);
            inp.addEventListener('focus', () => inp.select());
            jump.appendChild(inp);
            jump.appendChild(document.createTextNode(' of ' + pages));
            box.appendChild(jump);
            if (total) {
                const tot = document.createElement('span');
                tot.className = 'pg-total';
                tot.textContent = '· ' + total.toLocaleString() + ' rows';
                box.appendChild(tot);
            }
            box.appendChild(mk('Next ›', page + 1, page >= pages));
            box.appendChild(mk('Last »', pages, page >= pages, 'pg-edge'));
        }
        // ── file-list modal: collapsible folder tree; matches marked when searching file names ──
        const overlay = $id('files-overlay');
        function closeFiles() { if (overlay) { overlay.hidden = true; document.removeEventListener('keydown', escFiles); } }
        function escFiles(e) { if (e.key === 'Escape') closeFiles(); }
        function buildTreePub(files, tokens) {
            const root = { dirs: new Map(), files: [] };
            files.forEach(f => {
                const parts = String(f.path).split('/').filter(Boolean);
                let node = root;
                for (let i = 0; i < parts.length - 1; i++) {
                    if (!node.dirs.has(parts[i])) node.dirs.set(parts[i], { dirs: new Map(), files: [] });
                    node = node.dirs.get(parts[i]);
                }
                node.files.push({ name: parts[parts.length - 1] || String(f.path), size: f.size });
            });
            const container = document.createElement('div');
            container.className = 'ftree';
            const countFiles = (n) => { let c = n.files.length; n.dirs.forEach(d => { c += countFiles(d); }); return c; };
            const nameEl = (cls, text) => {
                const sp = document.createElement('span');
                sp.className = cls;
                sp.title = text;
                if (tokens && tokens.length) markInto(sp, text, tokens);
                else sp.textContent = text;
                return sp;
            };
            (function render(node, parent, depth) {
                [...node.dirs.keys()].sort().forEach(name => {
                    const subNode = node.dirs.get(name);
                    const det = document.createElement('details');
                    if (depth === 0) det.open = true;
                    const sum = document.createElement('summary');
                    sum.appendChild(nameEl('ftree-dir', name));
                    const cnt = document.createElement('span');
                    cnt.className = 'text-muted ftree-count';
                    cnt.textContent = ' (' + countFiles(subNode) + ')';
                    sum.appendChild(cnt);
                    det.appendChild(sum);
                    const inner = document.createElement('div');
                    inner.className = 'ftree-children';
                    render(subNode, inner, depth + 1);
                    det.appendChild(inner);
                    parent.appendChild(det);
                });
                node.files.sort((a, b) => a.name.localeCompare(b.name)).forEach(f => {
                    const line = document.createElement('div');
                    line.className = 'ftree-file';
                    line.appendChild(nameEl('ftree-name', f.name));
                    const sz = document.createElement('span');
                    sz.className = 'ftree-size text-muted';
                    sz.textContent = fmtBytesPub(f.size);
                    line.appendChild(sz);
                    parent.appendChild(line);
                });
            })(root, container, 0);
            return container;
        }

        // ── the Info panel ──────────────────────────────────────────────────
        //
        // Everything about one hash in one place: the source link (behind the leaving-the-site
        // confirmation, because it is not our link), the description as its author wrote it, the
        // numbers, and the file list at the bottom. The "N files" chip beside a result still opens
        // the plain tree on its own — somebody who only wants the file names should not have to read
        // an essay to reach them.
        const infoOverlay = $id('info-overlay');
        let infoHash = null;

        function closeInfo() {
            if (!infoOverlay) return;
            infoOverlay.hidden = true;
            infoHash = null;
            document.removeEventListener('keydown', escInfo);
        }
        function escInfo(e) { if (e.key === 'Escape') closeInfo(); }

        function infoRow(label, value) {
            const d = document.createElement('div');
            d.className = 'info-kv';
            const l = document.createElement('span');
            l.className = 'info-kv-label';
            l.textContent = label;
            const v = document.createElement('span');
            v.className = 'info-kv-value';
            if (value instanceof Node) v.appendChild(value); else v.textContent = value == null ? '—' : String(value);
            d.appendChild(l); d.appendChild(v);
            return d;
        }

        async function castVote(hash, dir, holder) {
            const csrf = ($id('search-csrf') || {}).value || '';
            const r = await postJson('rate_hash', { hash, vote: dir, csrf_token: csrf });
            if (!r) return;
            if (r.captcha) {
                // The points scheme decided this visitor needs a challenge. Reopening the panel is
                // the honest way to get one: the CAPTCHA belongs to the page, not to this button.
                holder.textContent = 'Please solve the CAPTCHA on the page and try again.';
                return;
            }
            if (!r.success) {
                const why = document.createElement('div');
                why.className = 'rep-label text-muted';
                why.textContent = r.error || 'That did not go through.';
                holder.appendChild(why);
                return;
            }
            // Redraw from the server's answer, never from an optimistic guess: the whole value of a
            // score is that it is the server's count and not the browser's.
            openInfo(hash, null);
        }

        async function openInfo(hash, name) {
            if (!infoOverlay) return;
            const body = $id('info-body'), title = $id('info-title');
            infoHash = hash;
            title.textContent = name || 'Details';
            body.textContent = 'Loading…';
            infoOverlay.hidden = false;
            document.addEventListener('keydown', escInfo);
            const json = await getJson('index_info&hash=' + encodeURIComponent(hash));
            if (infoOverlay.hidden || infoHash !== hash) return;
            body.textContent = '';
            if (!json || !json.success) {
                body.textContent = (json && json.error) || 'Could not load the details.';
                return;
            }
            title.textContent = json.name || name || 'Details';

            // 1. where it came from
            if (json.source_url) {
                const row = document.createElement('div');
                row.className = 'rt-src-row';
                const lab = document.createElement('strong');
                lab.textContent = 'Source:';
                const a = document.createElement('a');
                a.className = 'rt-src-url';
                a.href = json.source_url;
                a.textContent = json.source_url;
                a.rel = 'nofollow noopener noreferrer ugc';
                a.target = '_blank';
                // Not our link. Off-site ones get the confirmation; the operator's own trusted
                // domains do not, because warning about your own site teaches people to click through.
                if (!json.source_trusted) a.setAttribute('data-external', '1');
                row.appendChild(lab); row.appendChild(a);
                body.appendChild(row);
            }

            // 2. what it is
            if (json.description_html) {
                const d = document.createElement('div');
                d.className = 'rt-body';
                // Built on the server by includes/richtext.php out of fully escaped input with a
                // fixed tag whitelist. This is the only assignment of innerHTML on the public pages.
                d.innerHTML = json.description_html;
                body.appendChild(d);
            } else if (!json.source_url) {
                const none = document.createElement('p');
                none.className = 'text-muted';
                none.textContent = 'Nobody has written anything about this one.';
                body.appendChild(none);
            }

            // 3. what people think of it
            //
            // The bar shows the split AND the count, because "100% from one vote" and "100% from
            // four hundred" are not the same fact and a bar that draws them identically is lying.
            // Below the operator's threshold there is no bar at all — just how many votes are in.
            if (json.rating) {
                const rep = document.createElement('div');
                rep.className = 'rep-block';
                const r = json.rating;

                if (r.percent !== null) {
                    const bar = document.createElement('div');
                    bar.className = 'rep-bar';
                    bar.setAttribute('role', 'img');
                    bar.setAttribute('aria-label', r.percent + '% positive from ' + r.total + ' ratings');
                    const up = document.createElement('span');
                    up.className = 'rep-bar-up';
                    up.style.width = r.percent + '%';
                    bar.appendChild(up);
                    rep.appendChild(bar);
                    const label = document.createElement('div');
                    label.className = 'rep-label';
                    label.textContent = r.percent + '% positive · ' + r.up + ' up, ' + r.down + ' down';
                    rep.appendChild(label);
                } else {
                    const label = document.createElement('div');
                    label.className = 'rep-label text-muted';
                    label.textContent = r.total === 0
                        ? 'Nobody has rated this yet.'
                        : r.total + ' of ' + r.min_votes + ' ratings needed before a score is shown.';
                    rep.appendChild(label);
                }

                if (json.can_vote) {
                    const acts = document.createElement('div');
                    acts.className = 'rep-acts';
                    const mk = (dir, glyph, title) => {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'btn btn-secondary btn-small rep-btn' + (json.my_vote === dir ? ' rep-mine' : '');
                        b.textContent = glyph;
                        b.title = title;
                        b.addEventListener('click', () => castVote(hash, dir, rep));
                        return b;
                    };
                    acts.appendChild(mk(1, '\u25B2 Good', 'This is what it says it is'));
                    acts.appendChild(mk(-1, '\u25BC Bad', 'Fake, mislabelled or broken'));
                    rep.appendChild(acts);
                } else if (json.vote_refusal) {
                    const why = document.createElement('div');
                    why.className = 'rep-label text-muted';
                    why.textContent = json.vote_refusal;
                    rep.appendChild(why);
                }
                body.appendChild(rep);
            }

            // 4. the numbers
            const st = json.stats || {};
            const grid = document.createElement('div');
            grid.className = 'info-grid';
            const sl = document.createElement('span');
            sl.id = 'info-sl';
            sl.textContent = (st.seeders == null ? '—' : st.seeders) + ' seeders / '
                           + (st.leechers == null ? '—' : st.leechers) + ' leechers';
            grid.appendChild(infoRow('Swarm', sl));
            if (st.completed != null) grid.appendChild(infoRow('Completed', Number(st.completed).toLocaleString()));
            if (st.peak_seeders != null) grid.appendChild(infoRow('Peak seeders', Number(st.peak_seeders).toLocaleString()));
            if (st.total_size != null) grid.appendChild(infoRow('Size', fmtBytesPub(st.total_size)));
            if (st.files_count != null) grid.appendChild(infoRow('Files', Number(st.files_count).toLocaleString()));
            if (st.first_seen) grid.appendChild(infoRow('First seen', fmtDatePub(st.first_seen)));
            if (st.last_seen) grid.appendChild(infoRow('Last seen', fmtDatePub(st.last_seen)));
            if (st.seen_count != null) grid.appendChild(infoRow('Times seen', Number(st.seen_count).toLocaleString()));
            if (json.whitelisted) grid.appendChild(infoRow('Registered', 'yes — served by this tracker'));
            const hashEl = document.createElement('code');
            hashEl.className = 'info-hash';
            hashEl.textContent = json.info_hash;
            grid.appendChild(infoRow('Info hash', hashEl));
            body.appendChild(grid);

            if (json.can_refresh) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-secondary btn-small';
                btn.textContent = 'Refresh seeders';
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    const prev = btn.textContent;
                    btn.textContent = 'Asking the tracker…';
                    const r = await postJson('index_info&hash=' + encodeURIComponent(hash), {
                        op: 'refresh', csrf_token: ($id('search-csrf') || {}).value || '' });
                    if (r && r.success) {
                        sl.textContent = r.seeders + ' seeders / ' + r.leechers + ' leechers';
                        btn.textContent = 'Refreshed';
                    } else {
                        btn.textContent = (r && r.error) || 'The tracker did not answer';
                    }
                    setTimeout(() => { btn.textContent = prev; btn.disabled = false; }, 4000);
                });
                body.appendChild(btn);
            }

            // 5. the files, last, because the panel is about the torrent and this is the long part
            if (json.can_files && st.files_count) {
                const det = document.createElement('details');
                det.className = 'rt-collapse';
                det.open = true;
                const sum = document.createElement('summary');
                sum.textContent = 'Files (' + Number(st.files_count).toLocaleString() + ')';
                det.appendChild(sum);
                const holder = document.createElement('div');
                holder.className = 'rt-body';
                holder.textContent = 'Loading…';
                det.appendChild(holder);
                body.appendChild(det);
                const fj = await getJson('index_files&hash=' + encodeURIComponent(hash));
                if (infoOverlay.hidden || infoHash !== hash) return;
                holder.textContent = '';
                if (fj && fj.success && fj.files && fj.files.length) {
                    holder.appendChild(buildTreePub(fj.files, []));
                    if (fj.truncated) {
                        const more = document.createElement('p');
                        more.className = 'text-muted';
                        more.textContent = 'List truncated — this torrent has more files.';
                        holder.appendChild(more);
                    }
                } else {
                    holder.textContent = 'No file list stored for this entry.';
                }
            }
        }

        if (infoOverlay) {
            infoOverlay.addEventListener('click', (e) => { if (e.target === infoOverlay) closeInfo(); });
            const ic = $id('info-close');
            if (ic) ic.addEventListener('click', closeInfo);
        }

        async function openFiles(hash, name) {
            if (!overlay) return;
            const body = $id('files-body'), title = $id('files-title');
            title.textContent = name || 'Files';
            body.textContent = 'Loading…';
            overlay.hidden = false;
            document.addEventListener('keydown', escFiles);
            const json = await getJson('index_files&hash=' + encodeURIComponent(hash));
            if (overlay.hidden) return;
            body.textContent = '';
            if (!json || !json.success) {
                body.textContent = (json && json.error) || 'Could not load the file list.';
                return;
            }
            title.textContent = (json.name || name || 'Files') + ' — ' + json.files.length + (json.truncated ? '+' : '') + ' files';
            if (!json.files.length) { body.textContent = 'No file list stored for this entry.'; return; }
            body.appendChild(buildTreePub(json.files, lastFilesSearch ? lastTokens : []));
            if (json.truncated) {
                const more = document.createElement('p');
                more.className = 'text-muted';
                more.textContent = 'List truncated — this torrent has more files.';
                body.appendChild(more);
            }
        }
        if (overlay) {
            overlay.addEventListener('click', (e) => { if (e.target === overlay) closeFiles(); });
            $id('files-close').addEventListener('click', closeFiles);
        }
        // ── wiring: live search (debounced), accelerating clear-X, checkboxes, Enter = immediate ──
        const runDebounced = debounce(() => run(1), 300);
        const syncClear = () => { if (clearBtn) clearBtn.hidden = input.value === ''; };
        input.addEventListener('input', () => { syncClear(); runDebounced(); });
        if (clearBtn) clearBtn.addEventListener('click', () => animatedClearPub(input, () => { syncClear(); input.focus(); run(1); }));
        form.addEventListener('submit', (e) => { e.preventDefault(); run(1); });
        if (filesBox) filesBox.addEventListener('change', () => run(1));
        const contentFilter = $id('search-content');
        if (contentFilter) contentFilter.addEventListener('change', () => run(1));
        updateSortIcons();
        syncClear();
        run(1);
    }

    document.addEventListener('DOMContentLoaded', () => {
        initLogin();
        initRegister();
        initAccount();
        initReset();
        initSearch();
    });
})();

/* ── leaving the site ───────────────────────────────────────────────────────
 *
 * Any link a SUBMITTER wrote carries data-external unless its domain is on the operator's trusted
 * list. Following one is a decision the visitor should get to make knowingly: this site exists to be
 * careful about what it points at, and a link in a description is not something it has checked.
 *
 * One delegated listener, on the document. Descriptions are rendered into the page at all sorts of
 * moments — a search result, a detail panel, a modal opened from another modal — and binding at
 * render time would mean every one of those places having to remember. Whoever adds the next place
 * gets this for free, which is the only way a rule like this survives.
 */
(function () {
    'use strict';

    function closeLeave(box) {
        if (box && box.parentNode) box.parentNode.removeChild(box);
        document.removeEventListener('keydown', onEsc, true);
    }
    let openBox = null;
    function onEsc(e) { if (e.key === 'Escape') { closeLeave(openBox); openBox = null; } }

    function askBeforeLeaving(url) {
        const box = document.createElement('div');
        box.className = 'leave-modal';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');

        const inner = document.createElement('div');
        inner.className = 'leave-box';

        const h = document.createElement('h3');
        h.textContent = 'You are leaving this site';
        inner.appendChild(h);

        const p1 = document.createElement('p');
        p1.textContent = 'This link was written by whoever registered the torrent, not by us. '
            + 'We have not checked where it goes and we are not responsible for what is there. '
            + 'Open it at your own risk.';
        inner.appendChild(p1);

        // textContent, never innerHTML: the URL is the untrusted part of this dialog, and a dialog
        // warning about an untrusted link would be an absurd place to inject one.
        const u = document.createElement('code');
        u.className = 'leave-url';
        u.textContent = url;
        inner.appendChild(u);

        const acts = document.createElement('div');
        acts.className = 'leave-acts';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-secondary';
        cancel.textContent = 'Stay here';
        cancel.addEventListener('click', () => { closeLeave(box); openBox = null; });
        const go = document.createElement('a');
        go.className = 'btn';
        go.href = url;
        go.target = '_blank';
        go.rel = 'nofollow noopener noreferrer ugc';
        go.textContent = 'Open anyway';
        go.addEventListener('click', () => { closeLeave(box); openBox = null; });
        acts.appendChild(cancel);
        acts.appendChild(go);
        inner.appendChild(acts);

        box.appendChild(inner);
        box.addEventListener('click', (e) => { if (e.target === box) { closeLeave(box); openBox = null; } });
        document.body.appendChild(box);
        document.addEventListener('keydown', onEsc, true);
        openBox = box;
        cancel.focus();
    }

    document.addEventListener('click', function (e) {
        const a = e.target && e.target.closest ? e.target.closest('a[data-external]') : null;
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href)) return;
        e.preventDefault();
        askBeforeLeaving(href);
    }, true);

    window.askBeforeLeaving = askBeforeLeaving;
})();

/* ── the description preview ────────────────────────────────────────────────
 *
 * The renderer lives on the server, and this does not change that. Turning the text into HTML in
 * the browser would put the one guarantee this feature rests on — that the output contains only tags
 * the server itself wrote — in the least trustworthy place in the system. So the preview is a round
 * trip to the same function the visitor's page will use. Slower, and correct.
 *
 * Debounced, because it is somebody typing, and the endpoint is a parser anybody can call.
 */
(function () {
    'use strict';
    const ta = document.getElementById('wl-desc');
    if (!ta) return;
    const box = document.getElementById('wl-desc-preview');
    const counter = document.getElementById('wl-desc-count');
    const help = document.getElementById('wl-desc-help');
    const tabs = [...document.querySelectorAll('.rt-tab')];
    const fmtEl = document.getElementById('wl-desc-format');
    const csrf = document.querySelector('#wl-form input[name="csrf_token"]');
    let timer = null;
    let lastAsked = '';

    function show(which) {
        tabs.forEach(t => t.classList.toggle('active', t.dataset.rt === which));
        ta.hidden = which !== 'write';
        box.hidden = which !== 'preview';
        if (which === 'preview') render();
    }

    async function render() {
        const text = ta.value;
        const fmt = fmtEl ? fmtEl.value : 'bbcode';
        const key = fmt + String.fromCharCode(31) + text;
        if (key === lastAsked) return;
        lastAsked = key;
        if (!text.trim()) {
            box.textContent = '';
            box.appendChild(Object.assign(document.createElement('p'), {
                className: 'text-muted', textContent: 'Nothing to preview yet.' }));
            return;
        }
        const r = await postJson('richtext_preview', {
            text, format: fmt, csrf_token: csrf ? csrf.value : '' });
        if (!r) { box.textContent = 'Could not reach the server.'; return; }
        if (!r.success) { box.textContent = r.error || 'Could not render that.'; return; }
        // The server built this from fully escaped input with a fixed tag whitelist
        // (includes/richtext.php). It is the same string the public page will show.
        box.innerHTML = r.html;
        if (counter) {
            const bits = [r.length + '/' + r.limit + ' characters'];
            if (r.images.limit > 0 || r.images.used) bits.push(r.images.used + '/' + r.images.limit + ' images');
            if (r.links.limit > 0 || r.links.used) bits.push(r.links.used + '/' + r.links.limit + ' links');
            counter.textContent = bits.join(' · ');
        }
        if (help) {
            help.textContent = r.problem || '';
            help.classList.toggle('form-hint-bad', !!r.problem);
        }
    }

    tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.rt)));
    ta.addEventListener('input', () => {
        clearTimeout(timer);
        if (!box.hidden) timer = setTimeout(render, 400);
    });
    if (fmtEl) fmtEl.addEventListener('change', () => { lastAsked = ''; if (!box.hidden) render(); });
})();

/* ── watching a submission prove itself ─────────────────────────────────────
 *
 * When the tracker checks submissions, registering stops being instant: the metadata has to arrive
 * from the DHT and a scrape has to find somebody actually sharing it. That is a wait of seconds to
 * minutes, and a form that just sits there during it looks broken.
 *
 * So each hash gets its own line and its own state, and the states say WHICH half failed. "Nobody is
 * sharing this" and "we could not read the torrent" send somebody to completely different places,
 * and folding them into "failed" throws away the only useful part of the answer.
 */
(function () {
    'use strict';
    const box = document.getElementById('wl-probe');
    if (!box) return;
    const list = document.getElementById('wl-probe-list');
    const note = document.getElementById('wl-probe-note');
    let timer = null;
    let started = 0;

    const LABEL = {
        probing: ['Checking…', 'wl-probe-wait'],
        passed:  ['Registered and being served', 'wl-probe-ok'],
        failed:  ['Not registered', 'wl-probe-bad'],
        none:    ['Registered', 'wl-probe-ok'],
        unknown: ['Not registered', 'wl-probe-bad'],
    };

    function draw(items) {
        list.textContent = '';
        Object.keys(items).forEach(hash => {
            const it = items[hash];
            const li = document.createElement('li');
            const [text, cls] = LABEL[it.state] || LABEL.unknown;
            li.className = 'wl-probe-item ' + cls;

            const head = document.createElement('div');
            head.className = 'wl-probe-head';
            const name = document.createElement('strong');
            name.textContent = it.name || hash.slice(0, 16) + '…';
            head.appendChild(name);
            const st = document.createElement('span');
            st.className = 'wl-probe-state';
            st.textContent = text;
            head.appendChild(st);
            li.appendChild(head);

            const detail = document.createElement('div');
            detail.className = 'wl-probe-detail';
            if (it.state === 'passed' || it.state === 'none') {
                const bits = [];
                if (it.seeders != null) bits.push(it.seeders + ' seeders, ' + (it.leechers || 0) + ' leechers');
                if (it.files != null) bits.push(it.files + (it.files === 1 ? ' file' : ' files'));
                detail.textContent = bits.join(' · ');
            } else if (it.state === 'failed' || it.state === 'unknown') {
                // The reason, verbatim from the server. It is the whole point of the line.
                detail.textContent = it.error || 'it did not pass the check';
            } else {
                detail.textContent = it.meta === 'done'
                    ? 'metadata is in — looking for peers on this tracker…'
                    : 'waiting for the torrent metadata…';
            }
            li.appendChild(detail);
            list.appendChild(li);
        });
    }

    async function poll(hashes, timeoutMinutes) {
        const r = await getJson('whitelist_probe&hashes=' + encodeURIComponent(hashes.join(',')));
        if (!r || !r.success) {
            note.textContent = (r && r.error) || 'Could not read the progress.';
            return;
        }
        draw(r.items);
        const waiting = Object.keys(r.items).filter(h => r.items[h].state === 'probing').length;
        if (waiting === 0) {
            note.textContent = 'Done.';
            clearTimeout(timer);
            return;
        }
        const mins = Math.round((Date.now() - started) / 60000);
        note.textContent = waiting + (waiting === 1 ? ' still being checked' : ' still being checked')
            + ' — this can take a few minutes, and gives up after ' + timeoutMinutes + '.'
            + (mins >= 1 ? ' (' + mins + ' min so far)' : '');
        // Every three seconds. Faster tells nobody anything: the worker polls its queue on its own
        // schedule and the answer cannot change in between.
        timer = setTimeout(() => poll(hashes, timeoutMinutes), 3000);
    }

    window.wlWatchProbe = function (hashes, timeoutMinutes) {
        if (!hashes || !hashes.length) return;
        started = Date.now();
        box.hidden = false;
        note.textContent = 'Checking ' + hashes.length + (hashes.length === 1 ? ' submission…' : ' submissions…');
        clearTimeout(timer);
        poll(hashes, timeoutMinutes || 10);
    };
})();
