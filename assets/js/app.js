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
        return { error: t('js.app.invalid_response') };
    }

    if (json.captcha_required) {
        const token = await requestCaptchaToken(endpoint);
        // No token: either the visitor closed the box, or the widget itself failed (bad site key,
        // domain not allow-listed, provider blocked) — those two need different advice.
        if (!token) return { error: captchaUnavailable() ? t('js.app.captcha_unavailable') : t('js.app.captcha_cancelled') };
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
                last = { error: t('js.app.invalid_response_after_captcha') };
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
    btn.textContent = t('js.app.wait_seconds', {n: remaining});
    const interval = setInterval(() => {
        remaining--;
        if (remaining <= 0) {
            clearInterval(interval);
            btn.disabled = false;
            btn.textContent = originalText;
        } else {
            btn.textContent = t('js.app.wait_seconds', {n: remaining});
        }
    }, 1000);
}

// Shared handling for a failed form submission (report / appeal / status-appeal all behaved
// identically here). `messages` maps error codes to friendly text; `rate_limit` has a default.
// Highlights any `fields` the server flagged and starts the resubmit cooldown.
function showFormSubmitError(form, alert, btn, json, messages = {}) {
    const map = { rate_limit: t('js.app.rate_limit'), ...messages };
    const code = json && json.error;
    alert.className = 'alert alert-error show';
    alert.textContent = (code && map[code]) ? map[code] : (code || t('js.app.error_occurred'));
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
    alert.textContent = t('js.app.network_error_retry');
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
                hashHint.textContent = t('js.app.hash_non_hex');
                hashHint.style.color = 'var(--error)';
            } else if (v.length < 40) {
                hashHint.textContent = t('js.app.hash_length', {n: v.length});
                hashHint.style.color = 'var(--warning)';
            } else {
                hashHint.textContent = t('js.app.hash_valid');
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
        magnetHint.textContent = t('js.app.magnet_prefix');
        magnetHint.style.color = 'var(--error)';
        return;
    }

    if (!/[?&]xt=urn:btih:/i.test(magnet)) {
        magnetHint.textContent = t('js.app.magnet_missing_xt');
        magnetHint.style.color = 'var(--error)';
        return;
    }

    const extractedHash = extractHashFromMagnet(magnet);
    if (!extractedHash) {
        magnetHint.textContent = t('js.app.magnet_invalid_hash');
        magnetHint.style.color = 'var(--error)';
        return;
    }

    const currentHash = hashInput.value.trim().toLowerCase();
    if (currentHash && currentHash.length === 40 && /^[a-f0-9]{40}$/.test(currentHash)) {
        if (extractedHash === currentHash) {
            magnetHint.textContent = t('js.app.magnet_hash_matches');
            magnetHint.style.color = 'var(--success)';
        } else {
            magnetHint.textContent = t('js.app.magnet_hash_mismatch');
            magnetHint.style.color = 'var(--error)';
        }
    } else {
        magnetHint.textContent = t('js.app.magnet_hash_extracted', {hash: extractedHash.substring(0, 8)});
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
            alert.textContent = t('js.app.report_submitted', {id: json.id});
            // Whitelist mode: the reported hash may not even be registered here (nothing to serve).
            if (json.whitelisted === false) {
                alert.textContent += ' ' + t('js.app.report_not_registered_note');
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
                duplicate: t('js.app.report_duplicate'),
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
            alert.textContent = t('js.app.magnet_extract_failed');
            return;
        }
        query = extracted;
    }

    // Email is always required
    const emailField = form.querySelector('[name="email"]');
    if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        emailField.closest('.form-group').classList.add('has-error');
        alert.className = 'alert alert-error show';
        alert.textContent = t('js.app.status_email_required');
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
                // A URL a STRANGER submitted, on a public page. Escaping alone is not enough: it
                // closes the attribute breakout but leaves `javascript:` intact, because a scheme
                // is not markup. So the scheme is checked first and anything that is not http(s)
                // is shown as text rather than made clickable — a reporter does not get to choose
                // what a visitor's browser executes.
                const ok = /^https?:\/\//i.test(String(json.link).trim());
                if (ok) {
                    linkEl.innerHTML = '<a href="' + escAttr(json.link) + '" rel="noopener noreferrer" target="_blank" class="status-link">' + escHtml(json.link) + '</a>';
                } else {
                    linkEl.textContent = json.link;
                }
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

            const statusLabels = { pending: t('js.app.status_pending'), checked: t('js.app.status_checked'), blocked: t('js.app.status_blocked'), archived: t('js.app.status_archived') };
            const statusEl = document.getElementById('res-status');
            let badges = '';
            if (json.blocked) {
                badges += '<span class="status-badge blocked">' + escHtml(t('js.app.status_blocked')) + '</span> ';
            }
            if (json.checked && !json.blocked && json.archived) {
                badges += '<span class="status-badge checked">' + escHtml(t('js.app.status_checked')) + '</span> ';
            }
            if (json.archived) {
                badges += '<span class="status-badge archived">' + escHtml(t('js.app.status_archived')) + '</span>';
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
                    document.getElementById('status-appeal-desc').textContent = t('js.app.status_appeal_desc');
                } else {
                    statusAppealSection.style.display = 'none';
                }
            }

            result.style.display = 'block';
        } else {
            alert.className = 'alert alert-error show';
            alert.textContent = json.error === 'not_found' ? t('js.app.status_not_found') : (json.error || t('js.app.error'));
        }
    } catch {
        alert.className = 'alert alert-error show';
        alert.textContent = t('js.app.network_error');
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
            alert.textContent = t('js.app.magnet_extract_failed');
            return;
        }
    }

    if (!/^[a-fA-F0-9]{40}$/.test(hash)) {
        form.querySelector('[name="block_query"]').closest('.form-group').classList.add('has-error');
        alert.className = 'alert alert-error show';
        alert.textContent = t('js.app.block_enter_valid_hash');
        return;
    }

    try {
        const json = await fetchWithCaptcha('check_block', { hash: hash.toLowerCase() });

        if (json.success) {
            document.getElementById('bc-hash').textContent = json.infoHash;
            const statusEl = document.getElementById('bc-status');
            if (json.blocked) {
                statusEl.innerHTML = '<span class="status-badge blocked">' + escHtml(t('js.app.status_blocked')) + '</span>';
            } else {
                statusEl.innerHTML = '<span class="status-badge checked">' + escHtml(t('js.app.status_not_blocked')) + '</span>';
            }
            // Whitelist mode: a second badge — registered (served) / not registered — independent of blocks
            const wlRow = document.getElementById('bc-row-whitelist');
            if (wlRow) {
                if (typeof json.whitelisted === 'boolean') {
                    const wlEl = document.getElementById('bc-whitelist');
                    wlEl.textContent = '';
                    const badge = document.createElement('span');
                    badge.className = 'status-badge ' + (json.whitelisted ? 'checked' : 'blocked');
                    badge.textContent = json.whitelisted ? t('js.app.whitelisted') : t('js.app.not_whitelisted');
                    wlEl.appendChild(badge);
                    wlEl.appendChild(document.createTextNode(json.whitelisted ? t('js.app.whitelisted_desc') : t('js.app.not_whitelisted_desc')));
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
            alert.textContent = json.error || t('js.app.error');
        }
    } catch {
        alert.className = 'alert alert-error show';
        alert.textContent = t('js.app.network_error');
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
            alert.textContent = t('js.app.appeal_submitted');
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
            alert.textContent = t('js.app.appeal_submitted');
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
            document.getElementById('trans-body').innerHTML = '<tr><td colspan="7" class="transparency-empty">' + t('js.app.trans_no_data') + '</td></tr>';
            document.getElementById('trans-summary').innerHTML = '<p>' + t('js.app.trans_no_data_yet') + '</p>';
            document.getElementById('trans-pagination').innerHTML = '';
            return;
        }

        const a = json.aggregates || {};
        const pct = (n) => a.total_requests ? ' (' + Math.round(n / a.total_requests * 100) + '%)' : '';
        document.getElementById('trans-summary').innerHTML =
            '<div class="trans-stats">' +
            '<div class="trans-stat-accent"><strong>' + (a.total_entities || 0) + '</strong><br><small>' + t('js.app.trans_organizations') + '</small></div>' +
            '<div class="trans-stat-accent"><strong>' + (a.total_groups || json.total) + '</strong><br><small>' + t('js.app.trans_groups') + '</small></div>' +
            '<div class="trans-stat-text"><strong>' + (a.total_requests || 0) + '</strong><br><small>' + t('js.app.trans_total_requests') + '</small></div>' +
            '<div class="trans-stat-success"><strong>' + (a.total_reviewed || 0) + pct(a.total_reviewed || 0) + '</strong><br><small>' + t('js.app.trans_reviewed') + '</small></div>' +
            '<div class="trans-stat-error"><strong>' + (a.total_blocked || 0) + pct(a.total_blocked || 0) + '</strong><br><small>' + t('js.app.trans_blocked') + '</small></div>' +
            '<div class="trans-stat-warning"><strong>' + (a.total_pending || 0) + pct(a.total_pending || 0) + '</strong><br><small>' + t('js.app.trans_awaiting_review') + '</small></div>' +
            '</div>';

        // json.data.length is the size of THIS page, and the last page is short — using it
        // made the final page start its numbering again from a smaller offset. The page SIZE
        // is what the server paged by, and it is the same for every page.
        const pageSize = Number(json.per_page) || Number(json.limit) || json.data.length;
        const offset = (json.page - 1) * pageSize;
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
                <button ${json.page <= 1 ? 'disabled' : ''} onclick="loadTransparency(${json.page - 1})">${t('js.app.prev')}</button>
                <span>${t('js.app.page_of', {page: json.page, pages: json.pages})}</span>
                <button ${json.page >= json.pages ? 'disabled' : ''} onclick="loadTransparency(${json.page + 1})">${t('js.app.next')}</button>
            `;
        }
    } catch {
        document.getElementById('transparency-loading').textContent = t('js.app.load_failed');
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
            counter.textContent = t('js.app.wl_count_valid', {n: c.valid}) + (c.invalid ? ' / ' + t('js.app.wl_count_invalid', {n: c.invalid}) : '') + (c.valid > max ? ' — ' + t('js.app.wl_count_max', {n: max}) : '');
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
        alert.textContent = t('js.app.wl_too_many', {n: max});
        return;
    }
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = t('js.app.wl_registering');
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
            if (s.added) parts.push(t('js.app.wl_sum_added', {n: s.added}));
            if (s.exists) parts.push(t('js.app.wl_sum_exists', {n: s.exists}));
            if (s.banned) parts.push(t('js.app.wl_sum_banned', {n: s.banned}));
            if (s.invalid) parts.push(t('js.app.wl_sum_invalid', {n: s.invalid}));
            let msg = parts.join(', ') + '.';
            if (s.added) {
                const secs = parseInt(json.active_in_seconds || 0, 10);
                msg += secs > 0 ? ' ' + t('js.app.wl_active_within', {n: secs}) : ' ' + t('js.app.wl_active_now');
            }
            if (json.file_ok === false) msg += ' ' + t('js.app.wl_file_warning');
            // When the tracker checks submissions, "registered" is not the end of the story yet.
            if (json.probe && json.probe.on && (json.probe.hashes || []).length) {
                msg += ' ' + t('js.app.wl_probe_checking');
                if (window.wlWatchProbe) window.wlWatchProbe(json.probe.hashes, json.probe.timeout_minutes);
            }
            if (json.content_proposed) {
                msg += ' ' + t('js.app.wl_content_proposed');
            } else if (json.content_pending) {
                msg += ' ' + t('js.app.wl_content_pending');
            }
            alert.textContent = msg;
            renderWhitelistResults(json);
            ta.value = '';
            document.getElementById('wl-counter').textContent = t('js.app.wl_count_valid', {n: 0});
            btn.textContent = orig;
            startCooldown(btn, 10);
        } else {
            btn.textContent = orig;
            const messages = {
                rate_limit: t('js.app.wl_err_rate_limit'),
                daily_cap: t('js.app.wl_err_daily_cap'),
                too_many: t('js.app.wl_too_many', {n: max}),
                no_valid: t('js.app.wl_err_no_valid'),
                registration_disabled: t('js.app.wl_err_disabled'),
                registration_unavailable: t('js.app.wl_err_unavailable'),
                'CAPTCHA cancelled': t('js.app.captcha_cancelled_2'),
                'CAPTCHA verification failed': t('js.app.captcha_failed'),
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
    const labels = { added: t('js.app.wl_label_added'), exists: t('js.app.wl_label_exists'), banned: t('js.app.wl_label_banned'), invalid: t('js.app.wl_label_invalid') };
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
            b.title = t('js.app.copy_magnet');
            b.textContent = t('js.app.copy');
            b.addEventListener('click', () => {
                navigator.clipboard.writeText(magnet).then(() => { b.textContent = t('js.app.copied'); setTimeout(() => { b.textContent = t('js.app.copy'); }, 1500); });
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
        alert.textContent = t('js.app.wl_check_enter');
        return;
    }
    btn.disabled = true;
    try {
        const res = await fetch(APP_API + 'whitelist_check&hash=' + encodeURIComponent(h), { headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        if (json.success) {
            if (json.banned) {
                alert.className = 'alert alert-error show';
                alert.textContent = t('js.app.wl_check_banned', {hash: json.hash});
            } else if (json.whitelisted) {
                alert.className = 'alert alert-success show';
                alert.textContent = t('js.app.wl_check_registered', {hash: json.hash}) + (json.added_at ? ' ' + t('js.app.wl_check_since', {date: json.added_at}) : '') + '.';
            } else {
                alert.className = 'alert alert-error show';
                alert.textContent = json.mode === 'whitelist' ? t('js.app.wl_check_not_registered', {hash: json.hash}) : t('js.app.wl_check_open', {hash: json.hash});
            }
        } else {
            alert.className = 'alert alert-error show';
            alert.textContent = json.error || t('js.app.lookup_failed');
        }
    } catch {
        alert.className = 'alert alert-error show';
        alert.textContent = t('js.app.network_error_2');
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
    { title: t('js.app.stats_load1_title'), sub: t('js.app.stats_load1_sub') },
    { title: t('js.app.stats_load2_title'), sub: t('js.app.stats_load2_sub') },
    { title: t('js.app.stats_load3_title'), sub: t('js.app.stats_load3_sub') },
    { title: t('js.app.stats_load4_title'), sub: t('js.app.stats_load4_sub') }
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
                beacon.title = t('js.app.stats_live');
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
        if (countdownText) countdownText.textContent = t('js.app.stats_syncing');
        if (!isFirstLoad && badge) {
            badge.classList.add('syncing');
            if (beaconText) beaconText.textContent = t('js.app.stats_syncing');
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
        showStatsError(t('js.app.stats_net_error'));
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
            const errMsg = (json && json.error) ? json.error : t('js.app.stats_server_error');
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
                if (beaconText) beaconText.textContent = t('js.app.stats_syncing');
            }
            if (countdownBar) {
                countdownBar.style.transition = 'none';
                countdownBar.style.width = '100%';
                countdownBar.classList.add('syncing');
            }
            if (countdownText) countdownText.textContent = t('js.app.stats_syncing');

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
                if (beaconText) beaconText.textContent = t('js.app.stats_syncing');
            }
            if (countdownBar) {
                countdownBar.style.transition = 'none';
                countdownBar.style.width = '100%';
                countdownBar.classList.add('syncing');
            }
            if (countdownText) countdownText.textContent = t('js.app.stats_syncing');
            // Fire a blocking sync. statsInFlight guard prevents re-entry; the call schedules
            // its own next poll/countdown on completion.
            loadStatsFull(true, false);
        } else {
            // Cache is fresh. Start countdown.
            statsSyncBackoff = 0;
            if (badge) {
                badge.classList.remove('hidden');
                badge.classList.remove('syncing');
                if (beaconText) beaconText.textContent = t('js.app.stats_live');
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
        if (subSeedsEl) subSeedsEl.textContent = t('js.app.pct_of_total_peers', {pct: seedPct});
        if (subLeechEl) subLeechEl.textContent = t('js.app.pct_of_total_peers', {pct: leechPct});
    } else {
        if (subSeedsEl) subSeedsEl.textContent = t('js.app.of_peers', {peers: peersFmt});
        if (subLeechEl) subLeechEl.textContent = t('js.app.of_peers', {peers: peersFmt});
    }
    if (subPeersEl) subPeersEl.textContent = t('js.app.leech_seed_summary', {leechers: leechFmt, seeds: seedsFmt});
    
    document.getElementById('val-uptime').textContent = res.uptime_string;
    document.getElementById('val-tracker-id').textContent = res.tracker_id || t('js.app.na');
    
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
        a.innerHTML = escHtml(t('js.app.git_commit')) + ' <i class="bi bi-box-arrow-up-right"></i>';
        versionEl.appendChild(a);
    } else {
        versionEl.textContent = res.version || t('js.app.na');
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
                let severity = t('js.app.severity_low');
                if (err.code.startsWith('5')) {
                    badgeClass += 'blocked';
                    severity = t('js.app.severity_critical');
                } else if (err.code.startsWith('400')) {
                    badgeClass += 'pending';
                    severity = t('js.app.severity_moderate');
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
        container.innerHTML = '<div class="text-center text-muted w-100 py-3">' + escHtml(t('js.app.no_heat_profile')) + '</div>';
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
        const tooltipText = escAttr(t('js.app.interval_tooltip', {interval: item.interval, count: item.count.toLocaleString()}));

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
        if (text) text.textContent = t('js.app.syncing_swarms');
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

    if (text) text.textContent = t('js.app.next_update_in', {n: seconds});

    // The TEXT label still ticks down — but it only changes the textContent, no layout work.
    const totalTime = seconds * 1000;
    const startTime = performance.now();

    statsCountdownTimer = setInterval(() => {
        const elapsed = performance.now() - startTime;
        const currentRemaining = Math.max(0, Math.ceil(seconds - (elapsed / 1000)));
        if (text) text.textContent = t('js.app.next_update_in', {n: currentRemaining});

        if (elapsed >= totalTime) {
            clearInterval(statsCountdownTimer);
            if (bar) {
                bar.style.transition = 'none';
                bar.style.width = '100%';
                bar.classList.add('syncing');
            }
            if (text) text.textContent = t('js.app.syncing_swarms');
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
            beacon.title = t('js.app.syncing_swarms');
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
        if (beacon) { beacon.classList.remove('syncing'); beacon.title = t('js.app.sync_failed'); }
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
            if (beacon) { beacon.classList.add('syncing'); beacon.title = t('js.app.syncing_swarms'); }
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
            if (beacon) { beacon.classList.remove('syncing'); beacon.title = t('js.app.live_syncing'); }
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
            if (beacon) { beacon.classList.remove('syncing'); beacon.title = t('js.app.sync_failed'); }
            statsHomePollTimer = setTimeout(() => loadStatsHome(false), 15000);
        }
    }
}

/* ── the two request helpers, at FILE scope on purpose ───────────────────────
 *
 * These used to live inside the accounts IIFE below, and two later features called them from their
 * own IIFEs: the description preview and the "watching a submission prove itself" list. Both threw
 * ReferenceError on their first call — silently, because both call sites are async and neither was
 * awaited, so the rejection went nowhere and the feature simply never did anything. The preview box
 * opened empty; the probe list never appeared.
 *
 * A file-scope const is the fix rather than `window.postJson`: it keeps one definition, and the next
 * IIFE that needs it gets it by lexical scope instead of by remembering to export.
 */
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

// === User accounts (?action=login / register / account / reset) + index search (?action=search) ===
// All rendering uses textContent — usernames, group names, notification titles and torrent names
// are untrusted. Endpoints: user_login/user_register/user_logout/user_me/user_update/
// user_notifications/user_verify_send/user_reset_request/user_reset_confirm/index_search/index_files.
(function () {
    'use strict';
    const $id = (x) => document.getElementById(x);
    const csrfOf = (form) => (form.querySelector('[name="csrf_token"]') || $id('account-csrf') || { value: '' }).value;
    // An address is checked with its DOMAIN treated as a HOSTNAME. The old test was
    //   /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    // which asked only "is there an @ and a dot after it", and therefore accepted
    // dsaddsas@wp\/.pl — a backslash and a slash inside the domain. The server's
    // filter_var(FILTER_VALIDATE_EMAIL) rejects that, so the form went green and the request came
    // back 400: the live check was telling the user the opposite of what would happen.
    //
    // Verified against filter_var over 37 addresses: there is no input this accepts that the server
    // refuses. It is stricter in five places, all of them things nobody can actually receive mail at
    // (a bracketed IP literal, a one-letter TLD, a hyphenated or digit-suffixed TLD).
    const EMAIL_LOCAL = "[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*";
    const EMAIL_LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
    const EMAIL_TLD = '(?:[A-Za-z]{2,}|xn--[A-Za-z0-9-]{2,})';   // xn-- keeps the IDN TLDs working
    const EMAIL_RE = new RegExp('^' + EMAIL_LOCAL + '@' + EMAIL_LABEL
                                + '(?:\\.' + EMAIL_LABEL + ')*\\.' + EMAIL_TLD + '$');
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
        [t('js.app.pw_min_len'), (p) => p.length >= 8 && p.length <= 200],
        [t('js.app.pw_lower'), (p) => /[a-z]/.test(p)],
        [t('js.app.pw_upper'), (p) => /[A-Z]/.test(p)],
        [t('js.app.pw_special'), (p) => /[^a-zA-Z0-9]/.test(p)],
        [t('js.app.pw_digit'), (p) => /[0-9]/.test(p)],
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
                showAlert(alert, t('js.app.signed_in_loading'), true);
                window.location.href = APP_BASE + '?action=account';
            } else {
                showAlert(alert, (json && json.error) || t('js.app.signin_failed'), false);
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
                showAlert(alert, t('js.app.account_created') + (json.verify_sent ? ' ' + t('js.app.verify_link_sent') : ''), true);
                window.location.href = APP_BASE + '?action=account';
            } else {
                showAlert(alert, (json && json.error) || t('js.app.register_failed'), false);
                btn.disabled = false;
            }
        });
    }

    // ── account page ──
    async function loadAccount() {
        const groupsBox = $id('acc-groups');
        const me = await getJson('user_me');
        if (!me || !me.success) { groupsBox.textContent = t('js.app.groups_load_failed'); return; }
        groupsBox.textContent = '';
        if (!me.groups.length) {
            const none = document.createElement('span');
            none.className = 'text-muted';
            none.textContent = t('js.app.no_groups');
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
                until.textContent = ' — ' + (g.expires_at ? t('js.app.group_until', {date: fmtDatePub(g.expires_at)}) : t('js.app.group_permanent'));
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
        if (accBadge) { accBadge.textContent = t('js.app.unread_count', {n: me.unread}); accBadge.hidden = me.unread <= 0; }
    }
    let notifPage = 1;
    async function loadNotifications(page) {
        if (page) notifPage = page;
        const box = $id('acc-notifications');
        const pag = $id('acc-notif-pagination');
        const json = await getJson('user_notifications&page=' + notifPage);
        if (!json || !json.success) { box.textContent = t('js.app.notif_load_failed'); return; }
        box.textContent = '';
        if (pag) pag.textContent = '';
        if (!json.notifications.length) {
            const none = document.createElement('span');
            none.className = 'text-muted';
            none.textContent = t('js.common.nothing_here');
            box.appendChild(none);
            return;
        }
        json.notifications.forEach(n => {
            const item = document.createElement('div');
            item.className = 'acc-notif' + (n.read_at ? ' acc-notif-read' : '');
            const head = document.createElement('div');
            head.className = 'acc-notif-head';
            const strongEl = document.createElement('strong');
            strongEl.textContent = n.title;
            head.appendChild(strongEl);
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
                mark.textContent = t('js.app.mark_read');
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
            pag.appendChild(mk(t('js.app.pg_prev'), json.page - 1, json.page <= 1));
            const info = document.createElement('span');
            info.textContent = t('js.app.page_of_total', {page: json.page, pages: json.pages, total: json.total});
            pag.appendChild(info);
            pag.appendChild(mk(t('js.app.pg_next'), json.page + 1, json.page >= json.pages));
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
            pubTip(btn, r && r.success ? (r.marked > 0 ? t('js.app.marked_read', {n: r.marked}) : t('js.app.nothing_unread')) : t('js.app.failed'));
            loadNotifications(); loadAccount();
        });
        const delRead = $id('acc-delete-read');
        if (delRead) delRead.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const r = await postJson('user_notifications', { csrf_token: $id('account-csrf').value, delete_read: 1 });
            pubTip(btn, r && r.success ? (r.deleted > 0 ? t('js.app.deleted_n', {n: r.deleted}) : t('js.app.nothing_to_delete')) : t('js.app.failed'));
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
                    if (label) label.textContent = t('js.app.pref_unavailable');
                    if (bulkLabel) bulkLabel.textContent = t('js.app.pref_unavailable');
                    return;
                }
                if (mailPref) { mailPref.checked = !!r.enabled; label.textContent = r.enabled ? t('js.app.pref_enabled') : t('js.app.pref_disabled'); }
                if (bulkPref) { bulkPref.checked = !!r.bulk_enabled; bulkLabel.textContent = r.bulk_enabled ? t('js.app.pref_enabled') : t('js.app.pref_disabled'); }
            });
            const bind = (box, lab, type) => {
                if (!box) return;
                box.addEventListener('change', async () => {
                    const r = await postJson('user_email_prefs', {
                        csrf_token: $id('account-csrf').value, enabled: box.checked ? 1 : 0, type });
                    if (r && r.success) lab.textContent = r.enabled ? t('js.app.pref_enabled') : t('js.app.pref_disabled');
                    else { box.checked = !box.checked; }
                });
            };
            bind(mailPref, label, 'account');
            bind(bulkPref, bulkLabel, 'bulk');
        }
        // The interface language. The whole UI is server-rendered, so the page is reloaded once the
        // choice is stored -- swapping the strings in place would need a second copy of every one of
        // them in JavaScript, and two copies of a translation is one that goes out of date.
        const langSel = $id('acc-language');
        if (langSel) langSel.addEventListener('change', async () => {
            langSel.disabled = true;
            const r = await postJson('user_language', {
                csrf_token: $id('account-csrf').value, language: langSel.value });
            if (r && r.success) { window.location.reload(); return; }
            langSel.disabled = false;
        });
        const verifyBtn = $id('acc-verify-send');
        if (verifyBtn) verifyBtn.addEventListener('click', async () => {
            verifyBtn.disabled = true;
            const r = await postJson('user_verify_send', { csrf_token: $id('account-csrf').value });
            if (r && r.success && r.sent) { verifyBtn.textContent = t('js.app.verify_sent'); }
            else { verifyBtn.textContent = t('js.app.failed'); verifyBtn.title = (r && (r.message || r.error)) || t('js.app.verify_could_not_send'); setTimeout(() => { verifyBtn.textContent = t('js.app.verify_resend'); verifyBtn.disabled = false; }, 4000); }
        });
        $id('account-logout').addEventListener('click', async () => {
            await postJson('user_logout', { csrf_token: $id('account-csrf').value });
            window.location.href = APP_BASE;
        });
        // live validation: email format + repeat box when it changes; password policy + repeat box
        const emailIn = $id('acc-new-email'), email2In = $id('acc-new-email2'), passIn = $id('acc-new-pass'), pass2In = $id('acc-new-pass2');
        const pass2Group = $id('acc-new-pass2-group'), email2Group = $id('acc-new-email2-group');
        const emailChanged = () => {
            // `data-has-email`, not the visible word: the placeholder used to be the literal string
            // "none" and this compared against it, so translating that one word would have made
            // every account look like it had an address called "brak".
            const box = $id('acc-email');
            const had = box.dataset.hasEmail === '1';
            return emailIn.value.trim() !== (had ? box.textContent.trim() : '');
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
            if (body.email === undefined && body.new_password === undefined) { showAlert(alert, t('js.app.nothing_to_change'), false); return; }
            btn.disabled = true;
            const json = await postJson('user_update', body);
            btn.disabled = false;
            if (json && json.success) {
                let msg = t('js.app.saved');
                if (json.changed.includes('password')) msg += ' ' + t('js.app.acc_use_new_password');
                if (json.email_stage === 'old') msg += ' ' + t('js.app.acc_email_change_started');
                else if (json.email_stage === 'done_direct') msg += ' ' + (json.verify_sent ? t('js.app.acc_verify_link_sent') : t('js.app.acc_email_saved'));
                showAlert(alert, msg, true);
                $id('acc-cur-pass').value = ''; passIn.value = ''; pass2In.value = ''; pass2Group.hidden = true;
                email2In.value = ''; email2Group.hidden = true; $id('acc-pw-checklist').hidden = true;
                if (json.email_stage === 'done_direct') {
                    $id('acc-email').textContent = body.email || t('js.app.acc_email_none');
                } else if (json.email_stage === 'old') {
                    setTimeout(() => location.reload(), 2500);   // show the pending-change banner
                }
            } else {
                showAlert(alert, (json && json.error) || t('js.app.update_failed'), false);
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
                if (json && json.success) showAlert(alert, json.message || t('js.app.reset_check_inbox'), true);
                else showAlert(alert, (json && json.error) || t('js.app.request_failed'), false);
            });
        }
        const confForm = $id('reset-confirm-form');
        if (confForm) {
            bindPwChecklist($id('resetc-password'), $id('resetc-pw-checklist'), false);
            confForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const alert = $id('resetc-alert'), btn = $id('resetc-submit');
                const p1 = $id('resetc-password'), p2 = $id('resetc-password2');
                if (!pwValid(p1.value)) { showAlert(alert, t('js.app.pw_requirements'), false); return; }
                if (p1.value !== p2.value) { showAlert(alert, t('js.app.pw_mismatch'), false); return; }
                btn.disabled = true;
                const json = await postJson('user_reset_confirm', { csrf_token: csrfOf(confForm), token: $id('resetc-token').value, password: p1.value });
                btn.disabled = false;
                if (json && json.success) {
                    showAlert(alert, t('js.app.pw_changed_sign_in'), true);
                    setTimeout(() => { window.location.href = APP_BASE + '?action=login'; }, 1200);
                } else showAlert(alert, (json && json.error) || t('js.app.reset_failed'), false);
            });
        }
    }

    // ── index search ──
    function initSearch() {
        const form = $id('search-form');
        if (!form) return;
        const canMagnet = form.dataset.canMagnet === '1';
        const canFiles = form.dataset.canFiles === '1';
        // How the file list fills up (index_files_mode): 'scroll' keeps asking as the reader reaches
        // the end, 'button' never asks unprompted, 'all' chains pages until the server says stop.
        // Only the mode comes from the page — how big a page is and how many of them are allowed are
        // the server's business, and api/index_files.php answers `capped` when the total is reached.
        // Both file overlays are inside this closure, so one read serves them both.
        const filesMode = ['scroll', 'button', 'all'].includes(form.dataset.filesMode) ? form.dataset.filesMode : 'scroll';
        // Every port, not just the first. Extra opentracker instances listen on their own ports and
        // share nothing between them, so a magnet that names one port is only ever answered by one
        // process. The attribute is empty without the cluster, which leaves this unchanged.
        const announces = [form.dataset.announce, form.dataset.announceHttps]
            .concat((form.dataset.announceExtra || '').split(/\s+/))
            .filter(Boolean);
        const input = $id('search-input'), clearBtn = $id('search-clear');
        const bestBox = $id('search-best'), filesBox = $id('search-files');
        // Mirrors indexSearchTooShort() in includes/index.php, same regex and same floor. A one- or
        // two-character term cannot use the fulltext index and scans the whole catalogue twice, and
        // the 400 ms debounce below sends exactly that on the first keystroke of every search — so it
        // is not sent at all, and the sentence the server would answer with is shown under the box
        // instead. [...q] counts characters the way mb_strlen does; "ąę".length would say 2 as well,
        // but an emoji would come out as 2 for one character.
        const hint = $id('search-hint');
        const HASH_PREFIX_RE = /^[a-f0-9]{6,40}$/i;
        const tooShort = (q) => q !== '' && [...q].length < 3 && !HASH_PREFIX_RE.test(q);
        const showHint = (msg) => { if (!hint) return; hint.textContent = msg || ''; hint.hidden = !msg; };
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
            runSortDebounced();
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
            const tot = $id('search-total');
            if (on) { tot.dataset.prev = tot.textContent; tot.textContent = t('js.app.searching'); }
        }
        async function run(page) {
            curPage = page;
            const my = ++seq;   // stale responses (fast typing) must not overwrite newer ones
            const alert = $id('search-alert'), table = $id('search-table'), body = $id('search-body'), note = $id('search-note');
            alert.className = 'alert';
            const q = input.value.trim();
            if (tooShort(q)) {
                // The counter was still bumped above: a reply to "abc" that lands after the user
                // deleted a letter must not repaint the table under a term that is not being searched.
                setLoading(false);
                table.hidden = true;
                note.hidden = true;
                $id('search-total').textContent = '';
                renderPager(1, 1, 0);
                showHint(t('js.app.search_too_short'));
                return;
            }
            showHint('');
            setLoading(true);
            const qs = new URLSearchParams({ page: String(page), sort: serializeSort() });
            if (perPageSel) qs.set('per_page', perPageSel.value);
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
                // The server applying the same rule (a stale page, or a hex floor that moved on one
                // side first) is the hint line, not a red alert: the message is the one the box
                // would have shown before sending, in the language the server rendered it in.
                if (json && json.code === 'search_too_short') { showHint(json.error || t('js.app.search_too_short')); return; }
                const code = json && json.error;
                showAlert(alert, code === 'rate_limit' ? t('js.app.search_rate_limit')
                    : code === 'login_required' ? t('js.app.search_login_required')
                    : code || t('js.app.search_failed'), false);
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
                if (lastTokens.length) markInto(nameSpan, r.name || t('js.app.no_name'), lastTokens);
                else nameSpan.textContent = r.name || t('js.app.no_name');
                nameTd.appendChild(nameSpan);
                if (r.src === 'whitelist') {
                    const wb = document.createElement('span');
                    wb.className = 'search-wl-badge';
                    wb.title = t('js.app.wl_badge_title');
                    wb.textContent = 'WL';
                    nameTd.appendChild(wb);
                }
                // The state of the words attached to it, not of the torrent. Only shown when there
                // is a state to show: an index row nobody has written about has none.
                if (r.content_status === 'approved' || r.content_status === 'rejected' || r.content_status === 'pending') {
                    nameTd.appendChild(contentStatusIcon(r.content_status));
                }
                if (r.files_count) {
                    const fc = document.createElement(canFiles && r.info_hash ? 'button' : 'span');
                    fc.className = 'search-files-chip' + (lastFilesSearch ? ' chip-hit' : '');
                    fc.textContent = r.files_count === 1 ? t('js.app.files_one') : t('js.app.files_many', {n: r.files_count});
                    if (canFiles && r.info_hash) {
                        fc.type = 'button';
                        fc.title = lastFilesSearch ? t('js.app.show_files_matched') : t('js.app.show_files');
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
                    if (r.rep && r.rep.mode === 'stars') {
                        // Compact in a table cell: the number, then the glyph. Five drawn stars in
                        // every row of a fifty-row table is noise, and the tooltip carries the count.
                        repTd.className += ' search-rep-stars';
                        repTd.textContent = r.rep.stars.toFixed(1) + ' ★';
                        repTd.title = r.rep.total === 1 ? t('js.app.rep_stars_title_one', {stars: r.rep.stars.toFixed(1)})
                                    : t('js.app.rep_stars_title_many', {stars: r.rep.stars.toFixed(1), n: r.rep.total});
                    } else if (r.rep) {
                        // The count comes with the percentage, always. A column that shows only
                        // "100%" makes one vote look like four hundred.
                        repTd.className += r.rep.pct >= 50 ? ' search-rep-up' : ' search-rep-down';
                        repTd.textContent = r.rep.pct + '%';
                        repTd.title = r.rep.total === 1 ? t('js.app.ratings_one') : t('js.app.ratings_many', {n: r.rep.total});
                    } else {
                        repTd.textContent = '—';
                        repTd.title = t('js.app.rep_too_few');
                    }
                    tr.appendChild(repTd);
                }
                const seenTd = document.createElement('td');
                seenTd.className = 'search-num';
                seenTd.textContent = fmtDatePub(r.last_seen);
                tr.appendChild(seenTd);
                if (canMagnet) {
                    const magTd = document.createElement('td');
                    magTd.className = 'search-actions search-c-actions-cell';
                    // the flex lives on a wrapper, not on the <td> — see .search-c-actions-cell
                    const actWrap = document.createElement('div');
                    actWrap.className = 'search-c-actions-inner';
                    if (r.info_hash) {
                        const a = document.createElement('a');
                        a.href = magnetFor(r.info_hash, r.name);
                        a.className = 'btn btn-small search-act-btn';
                        a.title = t('js.app.magnet_title');
                        a.textContent = t('js.app.magnet');
                        actWrap.appendChild(a);
                        const copy = document.createElement('button');
                        copy.type = 'button';
                        copy.className = 'btn btn-secondary btn-small search-act-btn';
                        copy.title = t('js.app.copy_magnet_title');
                        copy.textContent = t('js.app.copy');
                        copy.addEventListener('click', () => {
                            if (!navigator.clipboard) return;
                            navigator.clipboard.writeText(magnetFor(r.info_hash, r.name))
                                .then(() => { copy.textContent = '✓'; copy.classList.add('copied'); setTimeout(() => { copy.textContent = t('js.app.copy'); copy.classList.remove('copied'); }, 1200); })
                                .catch(() => {});
                        });
                        actWrap.appendChild(copy);
                        const info = document.createElement('button');
                        info.type = 'button';
                        info.className = 'btn btn-secondary btn-small search-act-btn';
                        info.title = t('js.app.info_title');
                        info.textContent = t('js.app.info');
                        info.addEventListener('click', () => openInfo(r.info_hash, r.name));
                        actWrap.appendChild(info);
                    }
                    magTd.appendChild(actWrap);
                    tr.appendChild(magTd);
                }
                body.appendChild(tr);
            });
            table.hidden = json.rows.length === 0;
            $id('search-total').textContent = json.total === 0 ? '' : (json.total === 1 ? t('js.app.results_one') : t('js.app.results_many', {n: json.total.toLocaleString()}));
            note.hidden = json.total !== 0;
            note.textContent = json.total === 0 ? t('js.app.nothing_found') : '';
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
            box.appendChild(mk(t('js.app.pg_first'), 1, page <= 1, 'pg-edge'));
            box.appendChild(mk(t('js.app.pg_prev'), page - 1, page <= 1));
            const jump = document.createElement('span');
            jump.className = 'pg-jump';
            jump.appendChild(document.createTextNode(t('js.app.pg_page') + ' '));
            const inp = document.createElement('input');
            inp.type = 'number'; inp.min = '1'; inp.max = String(pages); inp.value = String(page);
            inp.className = 'pg-input'; inp.title = t('js.app.pg_goto');
            const jumpTo = () => { const n = Number(String(inp.value).trim()); if (isFinite(n) && n >= 1) go(n); else inp.value = String(page); };
            inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); jumpTo(); } });
            inp.addEventListener('change', jumpTo);
            inp.addEventListener('focus', () => inp.select());
            jump.appendChild(inp);
            jump.appendChild(document.createTextNode(' ' + t('js.app.pg_of', {pages: pages})));
            box.appendChild(jump);
            if (total) {
                const tot = document.createElement('span');
                tot.className = 'pg-total';
                tot.textContent = '· ' + t('js.app.pg_rows', {n: total.toLocaleString()});
                box.appendChild(tot);
            }
            box.appendChild(mk(t('js.app.pg_next'), page + 1, page >= pages));
            box.appendChild(mk(t('js.app.pg_last'), pages, page >= pages, 'pg-edge'));
        }
        // ── file-list modal: collapsible folder tree; matches marked when searching file names ──
        const overlay = $id('files-overlay');
        function closeFiles() { if (overlay) { overlay.hidden = true; document.removeEventListener('keydown', escFiles); } }
        function escFiles(e) { if (e.key === 'Escape') closeFiles(); }
        // One DOM node per file, and this tree is rebuilt from scratch on every page that arrives —
        // so ten pages of 2 000 is ten rebuilds of a tree growing to 20 000 lines. The panel's tree
        // has had a cap since it was written (AdminCommon.buildFileTree); this one had none, which
        // was survivable only while a stored list could not be longer than a few thousand paths.
        // 5 000 is that number: the per-torrent storage cap the worker ships with, so every list
        // that draws in full today still draws in full. Past it the leaves are not created at all
        // (the panel's version creates them and only hides them — not a model to copy at this size)
        // and one line says how many are missing. Folder counts stay true; they are counted, not
        // drawn.
        const PUB_TREE_LEAVES = 5000;
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
            let drawn = 0, skipped = 0;
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
                    if (drawn >= PUB_TREE_LEAVES) { skipped++; return; }
                    drawn++;
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
            if (skipped) {
                const cut = document.createElement('p');
                cut.className = 'text-muted';
                cut.textContent = t('js.app.files_tree_cap', {n: drawn.toLocaleString(), rest: skipped.toLocaleString()});
                container.appendChild(cut);
            }
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

        /**
         * Five stars, half a star at a time.
         *
         * Ten clickable halves rather than five stars: the storage is in halves and the pointer has
         * to be able to reach one, or "3.5" would be a value nobody can actually cast. Hovering
         * previews what a click would set, which is the whole reason a star widget feels different
         * from a number field — and leaving restores what is really stored, so a hover never lies
         * about the current state.
         */
        function buildStars(r, json, hash) {
            const wrap = document.createElement('div');
            wrap.className = 'stars-wrap';

            const row = document.createElement('div');
            row.className = 'stars' + (json.can_vote ? ' stars-live' : '');
            row.setAttribute('role', json.can_vote ? 'group' : 'img');

            const shown = r.stars === null ? 0 : r.stars;
            const mine = json.my_vote > 0 ? json.my_vote / 2 : 0;

            // Paint to a value in stars (0..5), filling halves.
            const paint = (value) => {
                [...row.querySelectorAll('.star')].forEach((st, i) => {
                    const full = value >= i + 1;
                    const half = !full && value >= i + 0.5;
                    st.classList.toggle('star-full', full);
                    st.classList.toggle('star-half', half);
                });
            };

            for (let i = 0; i < 5; i++) {
                const st = document.createElement('span');
                st.className = 'star';
                st.setAttribute('aria-hidden', 'true');
                // Two hit areas per star: left half and right half.
                if (json.can_vote) {
                    [0.5, 1].forEach(part => {
                        const hit = document.createElement('button');
                        hit.type = 'button';
                        hit.className = 'star-hit star-hit-' + (part === 0.5 ? 'l' : 'r');
                        const value = i + part;
                        hit.title = value === 1 ? t('js.app.stars_one') : t('js.app.stars_many', {n: value});
                        hit.setAttribute('aria-label', t('js.app.rate_aria', {n: value}));
                        hit.addEventListener('mouseenter', () => paint(value));
                        hit.addEventListener('focus', () => paint(value));
                        hit.addEventListener('click', () => castVote(hash, Math.round(value * 2), wrap));
                        st.appendChild(hit);
                    });
                }
                row.appendChild(st);
            }
            // Leaving puts back what is actually stored — the visitor's own rating if they have one,
            // otherwise the average. A widget that keeps the last hovered value is telling them
            // something they never did.
            row.addEventListener('mouseleave', () => paint(mine || shown));
            paint(mine || shown);
            wrap.appendChild(row);

            const label = document.createElement('div');
            label.className = 'rep-label';
            if (r.stars === null) {
                label.classList.add('text-muted');
                label.textContent = r.total === 0
                    ? t('js.app.stars_nobody_yet')
                    : t('js.app.stars_needed', {n: r.total, min: r.min_votes});
            } else {
                label.textContent = r.stars.toFixed(1) + ' / 5 · '
                    + (r.total === 1 ? t('js.app.ratings_one') : t('js.app.ratings_many', {n: r.total}))
                    + (mine ? ' · ' + t('js.app.stars_yours', {n: mine}) : '');
            }
            wrap.appendChild(label);
            row.title = r.stars === null
                ? t('js.app.stars_so_far', {n: r.total, min: r.min_votes})
                : (r.total === 1 ? t('js.app.rep_stars_title_one', {stars: r.stars.toFixed(1)}) : t('js.app.rep_stars_title_many', {stars: r.stars.toFixed(1), n: r.total}));
            if (!json.can_vote && json.vote_refusal) row.title += ' — ' + json.vote_refusal;
            return wrap;
        }

        async function castVote(hash, dir, holder) {
            const csrf = ($id('search-csrf') || {}).value || '';
            const r = await postJson('rate_hash', { hash, vote: dir, csrf_token: csrf });
            if (!r) return;
            if (r.captcha) {
                // The points scheme decided this visitor needs a challenge. Reopening the panel is
                // the honest way to get one: the CAPTCHA belongs to the page, not to this button.
                holder.textContent = t('js.app.vote_captcha');
                return;
            }
            if (!r.success) {
                const why = document.createElement('div');
                why.className = 'rep-label text-muted';
                why.textContent = r.error || t('js.app.vote_failed');
                holder.appendChild(why);
                return;
            }
            // Redraw from the server's answer, never from an optimistic guess: the whole value of a
            // score is that it is the server's count and not the browser's.
            openInfo(hash, null);
        }

        /**
         * The review state of a description, as an icon beside the name.
         *
         * Inline SVG rather than an icon font. The shapes are the familiar ones — a clock, a tick in
         * a circle, a cross in a circle — but pulling in a whole font (and another CDN host, past a
         * CSP that currently allows none for fonts) to draw three 14-pixel glyphs is a lot of
         * machinery for very little. This renders identically, costs nothing, inherits its colour
         * from the class, and cannot fail to load.
         *
         * Colour alone is never the message: each icon carries a title, and the shapes differ, so it
         * still reads for somebody who cannot tell the three colours apart.
         */
        function contentStatusIcon(status) {
            const PATHS = {
                // clock — waiting
                pending: 'M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z',
                // check
                approved: 'M10.97 4.97a.75.75 0 0 1 1.07 1.05l-3.99 4.99a.75.75 0 0 1-1.08.02L4.324 8.384a.75.75 0 1 1 1.06-1.06l2.094 2.093 3.473-4.425a.235.235 0 0 1 .02-.022z',
                // cross
                rejected: 'M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z',
            };
            const TITLES = {
                pending:  t('js.app.cs_pending'),
                approved: t('js.app.cs_approved'),
                rejected: t('js.app.cs_rejected'),
            };
            const NS = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(NS, 'svg');
            svg.setAttribute('viewBox', '0 0 16 16');
            svg.setAttribute('width', '13');
            svg.setAttribute('height', '13');
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', TITLES[status]);
            const ring = document.createElementNS(NS, 'circle');
            ring.setAttribute('cx', '8'); ring.setAttribute('cy', '8'); ring.setAttribute('r', '7');
            ring.setAttribute('fill', 'none'); ring.setAttribute('stroke', 'currentColor'); ring.setAttribute('stroke-width', '1.2');
            const path = document.createElementNS(NS, 'path');
            path.setAttribute('d', PATHS[status]);
            path.setAttribute('fill', 'currentColor');
            svg.appendChild(ring); svg.appendChild(path);
            const wrap = document.createElement('span');
            wrap.className = 'search-cs-icon search-cs-' + status;
            wrap.title = TITLES[status];
            wrap.appendChild(svg);
            return wrap;
        }

        async function openInfo(hash, name) {
            if (!infoOverlay) return;
            const body = $id('info-body'), title = $id('info-title');
            infoHash = hash;
            title.textContent = name || t('js.app.details');
            body.textContent = t('js.common.loading');
            infoOverlay.hidden = false;
            document.addEventListener('keydown', escInfo);
            const json = await getJson('index_info&hash=' + encodeURIComponent(hash));
            if (infoOverlay.hidden || infoHash !== hash) return;
            body.textContent = '';
            if (!json || !json.success) {
                body.textContent = (json && json.error) || t('js.app.details_load_failed');
                return;
            }
            title.textContent = json.name || name || t('js.app.details');

            const st = json.stats || {};

            // 1. the numbers people actually opened this for, before anything else.
            //
            // Seeders and leechers used to be one sentence three quarters of the way down a flat
            // list, below "Times seen". Reading order is a claim about importance, and that one was
            // wrong: whether anything is sharing it is the first question, and every other field is
            // context for the answer.
            const strip = document.createElement('div');
            strip.className = 'info-strip';
            const statCell = (value, label, cls) => {
                const c = document.createElement('div');
                c.className = 'info-stat' + (cls ? ' ' + cls : '');
                const v = document.createElement('span');
                v.className = 'info-stat-v';
                if (value instanceof Node) v.appendChild(value); else v.textContent = value;
                const l = document.createElement('span');
                l.className = 'info-stat-l';
                l.textContent = label;
                c.appendChild(v); c.appendChild(l);
                return c;
            };
            const seedV = document.createElement('span');
            seedV.id = 'info-sl-seed';
            seedV.textContent = st.seeders == null ? '—' : Number(st.seeders).toLocaleString();
            const leechV = document.createElement('span');
            leechV.id = 'info-sl-leech';
            leechV.textContent = st.leechers == null ? '—' : Number(st.leechers).toLocaleString();
            strip.appendChild(statCell(seedV, t('js.app.stat_seeders'), 'info-stat-seed'));
            strip.appendChild(statCell(leechV, t('js.app.stat_leechers'), 'info-stat-leech'));
            if (st.completed != null) strip.appendChild(statCell(Number(st.completed).toLocaleString(), t('js.app.stat_completed')));
            if (st.total_size != null) strip.appendChild(statCell(fmtBytesPub(st.total_size), t('js.app.stat_size')));
            if (st.files_count != null) strip.appendChild(statCell(Number(st.files_count).toLocaleString(), st.files_count === 1 ? t('js.app.stat_file') : t('js.app.stat_files')));
            body.appendChild(strip);

            // 2. the two chips that qualify those numbers, on one line with the refresh control.
            const chips = document.createElement('div');
            chips.className = 'info-chips';
            if (json.whitelisted) {
                const chip = document.createElement('span');
                chip.className = 'info-chip info-chip-ok';
                chip.textContent = t('js.app.chip_registered');
                chip.title = t('js.app.chip_registered_title');
                chips.appendChild(chip);
            }
            if (st.last_seen) {
                const chip = document.createElement('span');
                chip.className = 'info-chip';
                chip.textContent = t('js.app.last_seen', {date: fmtDatePub(st.last_seen)});
                chips.appendChild(chip);
            }
            if (json.can_refresh) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-secondary btn-small info-refresh';
                btn.textContent = t('js.app.refresh');
                btn.title = t('js.app.refresh_title');
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    const prev = btn.textContent;
                    btn.textContent = t('js.app.asking');
                    const r = await postJson('index_info&hash=' + encodeURIComponent(hash), {
                        op: 'refresh', csrf_token: ($id('search-csrf') || {}).value || '' });
                    if (r && r.success) {
                        seedV.textContent = Number(r.seeders).toLocaleString();
                        leechV.textContent = Number(r.leechers).toLocaleString();
                        btn.textContent = t('js.app.refreshed');
                    } else {
                        btn.textContent = (r && r.error) || t('js.app.no_answer');
                    }
                    setTimeout(() => { btn.textContent = prev; btn.disabled = false; }, 4000);
                });
                chips.appendChild(btn);
            }
            if (chips.children.length) body.appendChild(chips);

            // 3. where it came from
            if (json.source_url) {
                const row = document.createElement('div');
                row.className = 'rt-src-row info-section';
                const lab = document.createElement('strong');
                lab.textContent = t('js.app.source_label');
                const a = document.createElement('a');
                a.className = 'rt-src-url';
                a.href = json.source_url;
                a.textContent = json.source_url;
                a.rel = 'nofollow noopener noreferrer ugc';
                a.target = '_blank';
                // Not our link. Off-site ones get the confirmation; the operator's own trusted
                // domains do not, because warning about your own site teaches people to click through.
                if (!json.source_trusted) a.setAttribute('data-external', '1');
                if (json.source_auto) {
                    // Added by the importer, not typed into the form. Saying so is the difference
                    // between "the uploader vouched for this link" and "this is where we found it".
                    const tag = document.createElement('span');
                    tag.className = 'info-chip info-chip-auto';
                    tag.textContent = t('js.app.source_auto');
                    tag.title = json.source_auto_note || t('js.app.source_auto_title');
                    row.appendChild(lab); row.appendChild(a); row.appendChild(tag);
                } else {
                    row.appendChild(lab); row.appendChild(a);
                }
                body.appendChild(row);
            }

            // 2. what it is
            if (json.description_html) {
                const d = document.createElement('div');
                d.className = 'rt-body info-section';
                // Built on the server by includes/richtext.php out of fully escaped input with a
                // fixed tag whitelist. This is the only assignment of innerHTML on the public pages.
                d.innerHTML = json.description_html;
                body.appendChild(d);
            } else if (!json.source_url) {
                const none = document.createElement('p');
                none.className = 'text-muted info-section';
                none.textContent = t('js.app.no_description');
                body.appendChild(none);
            }

            // 3. what people think of it
            //
            // Two modes share this block. Whichever it is, the COUNT is always shown next to the
            // score: "5 stars" and "5 stars from one vote" are different claims, and a widget that
            // renders them identically is making the stronger one on no evidence.
            if (json.rating) {
                const rep = document.createElement('div');
                rep.className = 'rep-block info-section';
                const r = json.rating;

                if (r.mode === 'stars') {
                    rep.appendChild(buildStars(r, json, hash));
                } else if (r.percent !== null) {
                    const bar = document.createElement('div');
                    bar.className = 'rep-bar';
                    bar.setAttribute('role', 'img');
                    bar.setAttribute('aria-label', t('js.app.rating_aria', {percent: r.percent, total: r.total}));
                    const up = document.createElement('span');
                    up.className = 'rep-bar-up';
                    up.style.width = r.percent + '%';
                    bar.appendChild(up);
                    rep.appendChild(bar);
                    const label = document.createElement('div');
                    label.className = 'rep-label';
                    label.textContent = t('js.app.rating_label', {percent: r.percent, up: r.up, down: r.down});
                    rep.appendChild(label);
                } else {
                    const label = document.createElement('div');
                    label.className = 'rep-label text-muted';
                    label.textContent = r.total === 0
                        ? t('js.app.no_ratings')
                        : t('js.app.ratings_needed', {total: r.total, min: r.min_votes});
                    rep.appendChild(label);
                }

                if (json.can_vote && r.mode !== 'stars') {
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
                    acts.appendChild(mk(1, '▲ ' + t('js.app.vote_good'), t('js.app.vote_good_title')));
                    acts.appendChild(mk(-1, '▼ ' + t('js.app.vote_bad'), t('js.app.vote_bad_title')));
                    rep.appendChild(acts);
                } else if (!json.can_vote && json.vote_refusal) {
                    const why = document.createElement('div');
                    why.className = 'rep-label text-muted';
                    why.textContent = json.vote_refusal;
                    rep.appendChild(why);
                }
                body.appendChild(rep);
            }

            // 4. the rest: provenance and identity, under a heading so it reads as a footnote to the
            //    strip above rather than as another list of equally important facts.
            const grid = document.createElement('div');
            grid.className = 'info-grid';
            if (st.peak_seeders != null) grid.appendChild(infoRow(t('js.app.row_peak_seeders'), Number(st.peak_seeders).toLocaleString()));
            if (st.first_seen) grid.appendChild(infoRow(t('js.app.row_first_seen'), fmtDatePub(st.first_seen)));
            if (st.seen_count != null) grid.appendChild(infoRow(t('js.app.row_times_seen'), Number(st.seen_count).toLocaleString()));
            const hashEl = document.createElement('code');
            hashEl.className = 'info-hash';
            hashEl.textContent = json.info_hash;
            grid.appendChild(infoRow(t('js.app.row_info_hash'), hashEl));
            const det = document.createElement('div');
            det.className = 'info-section';
            const detH = document.createElement('div');
            detH.className = 'info-sub';
            detH.textContent = t('js.app.record_heading');
            det.appendChild(detH);
            det.appendChild(grid);
            body.appendChild(det);

            // 5. the files, last, because the panel is about the torrent and this is the long part
            if (json.can_files && st.files_count) {
                const det = document.createElement('details');
                det.className = 'rt-collapse info-section';
                det.open = true;
                const sum = document.createElement('summary');
                // The torrent's OWN file count, which is not the length of the list below it: the
                // catalogue stores only the first few thousand paths of a huge torrent, so an
                // 18 000-file torrent has 5 000 rows here. The heading is rewritten with both
                // numbers as pages arrive rather than promising a count nothing can deliver.
                const totalFiles = Number(st.files_count) || 0;
                sum.textContent = t('js.app.files_count', {n: totalFiles.toLocaleString()});
                det.appendChild(sum);
                const holder = document.createElement('div');
                holder.className = 'rt-body';
                holder.textContent = t('js.common.loading');
                det.appendChild(holder);
                body.appendChild(det);
                // Paged: the first slice now, and the next one when the mode says so — on reaching
                // the end of the list (an IntersectionObserver on a sentinel), on the button, or
                // straight away until the server stops answering with more. The tree is rebuilt from
                // everything loaded so far — cheap next to the fetch, and it keeps one code path for
                // the folder structure — with a leaf cap so the rebuild stays cheap at a high total.
                const allFiles = [];
                // `stalled` is set by a failed page and stops the AUTOMATIC asking only — the button
                // stays live. Without it a 429 from the shared search bucket met an observer that
                // re-fires whenever the sentinel is on screen, and the answer to being rate-limited
                // was another request.
                let next = 0, more = false, loading = false, stalled = false;
                const tree = document.createElement('div');
                const notes = document.createElement('div');
                const note = (text) => {
                    const p = document.createElement('p');
                    p.className = 'text-muted';
                    p.textContent = text;
                    notes.appendChild(p);
                };
                const foot = document.createElement('div');
                foot.className = 'files-more';
                const btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'btn btn-secondary btn-small';
                const sentinel = document.createElement('div');
                sentinel.className = 'files-sentinel';
                foot.appendChild(btn); foot.appendChild(sentinel);
                const render = () => {
                    tree.replaceChildren(buildTreePub(allFiles, []));
                    sum.textContent = (totalFiles && allFiles.length < totalFiles)
                        ? t('js.app.files_count_of', {n: allFiles.length.toLocaleString(), total: totalFiles.toLocaleString()})
                        : t('js.app.files_count', {n: (totalFiles || allFiles.length).toLocaleString()});
                    btn.textContent = loading ? t('js.common.loading') : t('js.app.files_load_more', {n: allFiles.length.toLocaleString()});
                    btn.disabled = loading;
                    foot.hidden = !more;
                };
                const loadMore = async () => {
                    if (loading || (!more && next > 0)) return false;
                    loading = true; if (next > 0) render();
                    const fj = await getJson('index_files&hash=' + encodeURIComponent(hash) + '&offset=' + next);
                    loading = false;
                    if (infoOverlay.hidden || infoHash !== hash) return false;
                    if (!fj || !fj.success) {
                        if (next === 0) { holder.textContent = t('js.app.no_file_list'); return false; }
                        // Leave `more` alone: the reader may still press the button. It is the
                        // unattended asking that stops, and render() puts the button back to
                        // "Load more" instead of leaving it stuck on "Loading…" for ever.
                        stalled = true; render();
                        return false;
                    }
                    stalled = false;
                    (fj.files || []).forEach(f => allFiles.push(f));
                    next = typeof fj.next === 'number' ? fj.next : allFiles.length;
                    // More pages exist AND this visitor may ask for them (index.files_all); without
                    // the grant the list stops here and says so. A capped reply never claims
                    // truncation, so this is already false when the site's own total ended the list.
                    more = !!fj.truncated && !!fj.can_more;
                    if (next === 0 || !allFiles.length) { holder.textContent = t('js.app.no_file_list'); return false; }
                    // The notes hang below the tree and are written AFTER the holder is emptied of
                    // its "Loading…". They used to be appended before that line, so the one message
                    // the reader needed was wiped by the same call that attached the list.
                    if (!tree.parentNode) { holder.textContent = ''; holder.appendChild(tree); holder.appendChild(foot); holder.appendChild(notes); }
                    if (fj.truncated && !fj.can_more) note(t('js.app.files_truncated'));
                    // Not an error and not a permission: the list simply ends short of the count in
                    // the heading, because that is all the catalogue ever stored for this torrent.
                    if (fj.stored_short) note(t('js.app.files_stored_cap', {n: Number(fj.stored_total || allFiles.length).toLocaleString()}));
                    // The site's own ceiling, not a permission and not the worker's storage cap:
                    // there are more rows and this page is not going to fetch them.
                    if (fj.capped) note(t('js.app.files_cap_reached', {n: Number(fj.max || allFiles.length).toLocaleString()}));
                    render();
                    return true;
                };
                // One request at a time, and a failure ends the chain rather than retrying it: every
                // page spends a token from the per-IP bucket this endpoint shares with the search box.
                const loadAll = async () => { while (more && !stalled) { if (!await loadMore()) break; } };
                btn.addEventListener('click', loadMore);
                if (filesMode === 'scroll' && 'IntersectionObserver' in window) {
                    new IntersectionObserver((entries) => { if (entries.some(e => e.isIntersecting) && more && !stalled) loadMore(); },
                                             { root: null, rootMargin: '200px' }).observe(sentinel);
                }
                await loadMore();
                if (filesMode === 'all') await loadAll();
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
            title.textContent = name || t('js.app.files');
            body.textContent = t('js.common.loading');
            overlay.hidden = false;
            document.addEventListener('keydown', escFiles);
            const json = await getJson('index_files&hash=' + encodeURIComponent(hash));
            if (overlay.hidden) return;
            body.textContent = '';
            if (!json || !json.success) {
                body.textContent = (json && json.error) || t('js.app.files_load_failed');
                return;
            }
            if (!json.files.length) { title.textContent = (json.name || name || t('js.app.files')); body.textContent = t('js.app.no_file_list'); return; }
            // The same paging as the info overlay: the first page now, the next when the reader
            // reaches the end of the list or presses the button — only with index.files_all.
            const allFiles = json.files.slice();
            let next = typeof json.next === 'number' ? json.next : allFiles.length;
            let more = !!json.truncated && !!json.can_more, loading = false, stalled = false;
            // What the torrent says it holds, against what the catalogue actually stored. The two
            // differ for every torrent bigger than the worker's per-torrent cap, and the title said
            // only the second number while the search row said the first.
            const totalFiles = Number(json.files_count) || 0;
            const tree = document.createElement('div');
            const notes = document.createElement('div');
            const note = (text) => { const p = document.createElement('p'); p.className = 'text-muted'; p.textContent = text; notes.appendChild(p); };
            const foot = document.createElement('div'); foot.className = 'files-more';
            const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn btn-secondary btn-small';
            const sentinel = document.createElement('div'); sentinel.className = 'files-sentinel';
            foot.appendChild(btn); foot.appendChild(sentinel);
            const render = () => {
                const head = (totalFiles && allFiles.length < totalFiles)
                    ? t('js.app.files_n_of', {n: allFiles.length.toLocaleString(), total: totalFiles.toLocaleString()})
                    : t('js.app.files_n', {n: allFiles.length.toLocaleString() + (more || (json.truncated && !json.can_more) ? '+' : '')});
                title.textContent = (json.name || name || t('js.app.files')) + ' — ' + head;
                tree.replaceChildren(buildTreePub(allFiles, lastFilesSearch ? lastTokens : []));
                btn.textContent = loading ? t('js.common.loading') : t('js.app.files_load_more', {n: allFiles.length.toLocaleString()});
                btn.disabled = loading; foot.hidden = !more;
            };
            const loadMore = async () => {
                if (loading || !more) return false;
                loading = true; render();
                const fj = await getJson('index_files&hash=' + encodeURIComponent(hash) + '&offset=' + next);
                loading = false;
                if (overlay.hidden) return false;
                if (!fj || !fj.success) {
                    // The button stays live; only the observer and the 'all' chain give up. This
                    // used to set more=false, which removed the reader's only way to try again.
                    stalled = true; render();
                    return false;
                }
                stalled = false;
                (fj.files || []).forEach(f => allFiles.push(f)); next = typeof fj.next === 'number' ? fj.next : allFiles.length; more = !!fj.truncated && !!fj.can_more;
                if (fj.stored_short) note(t('js.app.files_stored_cap', {n: Number(fj.stored_total || allFiles.length).toLocaleString()}));
                if (fj.capped) note(t('js.app.files_cap_reached', {n: Number(fj.max || allFiles.length).toLocaleString()}));
                render();
                return true;
            };
            const loadAll = async () => { while (more && !stalled) { if (!await loadMore()) break; } };
            btn.addEventListener('click', loadMore);
            if (filesMode === 'scroll' && 'IntersectionObserver' in window) {
                new IntersectionObserver((entries) => { if (entries.some(e => e.isIntersecting) && more && !stalled) loadMore(); }, { root: null, rootMargin: '200px' }).observe(sentinel);
            }
            body.appendChild(tree); body.appendChild(foot); body.appendChild(notes);
            if (json.truncated && !json.can_more) note(t('js.app.files_truncated'));
            // The list ends here and ends short: the rest was never written, so there is nothing to
            // load and nothing wrong — say it once, quietly, under the tree.
            if (json.stored_short) note(t('js.app.files_stored_cap', {n: Number(json.stored_total || allFiles.length).toLocaleString()}));
            // The first page can already stand on the site's total when the two numbers are equal.
            if (json.capped) note(t('js.app.files_cap_reached', {n: Number(json.max || allFiles.length).toLocaleString()}));
            render();
            if (filesMode === 'all') await loadAll();
        }
        if (overlay) {
            overlay.addEventListener('click', (e) => { if (e.target === overlay) closeFiles(); });
            $id('files-close').addEventListener('click', closeFiles);
        }
        // ── wiring: live search (debounced), accelerating clear-X, checkboxes, Enter = immediate ──
        // The same two waits as the panel's lists (AdminCommon.DEBOUNCE in admin-common.js, which the
        // public site does not load): a sort click redraws the arrows at once and fetches when the
        // decision is made; typing waits the shorter one.
        const runDebounced = debounce(() => run(1), 400);
        const runSortDebounced = debounce(() => run(1), 1200);
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

/* ── switching the language keeps the scroll position ─────────────────────────
 * The header switcher reloads the page; the reader was halfway down Info or Terms comparing the two
 * languages. Store the position for a few seconds, restore it on the next load of the same page. */
(function () {
    const KEY = 'thx_lang_place_pub';
    document.addEventListener('click', (e) => {
        const a = e.target.closest('a.lang-opt');
        if (!a) return;
        try { sessionStorage.setItem(KEY, JSON.stringify({ path: location.pathname + location.search.replace(/([?&])lang=[^&]*&?/, '$1').replace(/[?&]$/, ''), y: window.scrollY, at: Date.now() })); } catch (err) {}
    }, true);
    let place = null;
    try { place = JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.removeItem(KEY); } catch (err) { place = null; }
    if (!place || Date.now() - (place.at || 0) > 15000) return;
    const here = location.pathname + location.search.replace(/([?&])lang=[^&]*&?/, '$1').replace(/[?&]$/, '');
    if (place.path !== here) return;
    document.addEventListener('DOMContentLoaded', () => { window.scrollTo(0, place.y || 0); setTimeout(() => window.scrollTo(0, place.y || 0), 400); });
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
        h.textContent = t('js.app.leave_title');
        inner.appendChild(h);

        const p1 = document.createElement('p');
        p1.textContent = t('js.app.leave_body');
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
        cancel.textContent = t('js.app.leave_stay');
        cancel.addEventListener('click', () => { closeLeave(box); openBox = null; });
        const go = document.createElement('a');
        go.className = 'btn';
        go.href = url;
        go.target = '_blank';
        go.rel = 'nofollow noopener noreferrer ugc';
        go.textContent = t('js.app.leave_open');
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

/* ── the description editor ─────────────────────────────────────────────────
 *
 * The renderer lives on the server, and this does not change that. Turning the text into HTML in
 * the browser would put the one guarantee this feature rests on — that the output contains only tags
 * the server itself wrote — in the least trustworthy place in the system. So the preview is a round
 * trip to the same function the visitor's page will use. Slower, and correct.
 *
 * The toolbar is the same idea as the admin bulk-mail composer: a <textarea> gets no Ctrl+B from the
 * browser, so the keys and the buttons run one wrapper that inserts whichever syntax the selected
 * format uses. A contenteditable box would give Ctrl+B for free and cost a second renderer, a paste
 * sanitiser and a tag whitelist to police — for text typed by strangers, that is the wrong trade.
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
    const syntax = document.getElementById('wl-desc-syntax');
    const tools = document.getElementById('wl-desc-tools');
    const tabs = [...document.querySelectorAll('.rt-tab')];
    const fmtEl = document.getElementById('wl-desc-format');
    const csrf = document.querySelector('#wl-form input[name="csrf_token"]');
    let timer = null;
    let lastShown = null;          // {key, ok} — what the box is currently displaying

    // What each button inserts, per format. A kind missing from a format's table means the format
    // cannot express it, and the button hides — Markdown has no colour and no font size, and a
    // button that writes markup the renderer will not honour teaches the wrong thing.
    //
    // A third element, when present, means "this is a whole block": it is inserted on its own lines
    // rather than wrapped around the caret.
    const SYNTAX = {
        markdown: {
            bold: ['**', '**'], italic: ['*', '*'], strike: ['~~', '~~'], code: ['`', '`'],
            highlight: ['==', '=='], sub: ['~', '~'], sup: ['^', '^'],
            link: ['[', '](https://example.org)'], image: ['![](', ')'],
            quote: ['> ', ''], list: ['- ', ''], olist: ['1. ', ''],
            spoiler: ['||', '||'],
            table: ['| A | B |\n|---|---|\n| 1 | 2 |', '', true],
            hr: ['\n---\n', '', true],
        },
        bbcode: {
            bold: ['[b]', '[/b]'], italic: ['[i]', '[/i]'], underline: ['[u]', '[/u]'],
            strike: ['[s]', '[/s]'], code: ['[code]', '[/code]'],
            color: ['[color=#e74c3c]', '[/color]'], size: ['[size=18]', '[/size]'],
            highlight: ['[highlight=yellow]', '[/highlight]'],
            sub: ['[sub]', '[/sub]'], sup: ['[sup]', '[/sup]'],
            link: ['[url=https://example.org]', '[/url]'], image: ['[img]', '[/img]'],
            quote: ['[quote]', '[/quote]'],
            list: ['[list]\n[*] ', '\n[/list]'], olist: ['[list=1]\n[*] ', '\n[/list]'],
            spoiler: ['[spoiler=Title]', '[/spoiler]'], center: ['[center]', '[/center]'],
            table: ['[table]\n[tr][th]A[/th][th]B[/th][/tr]\n[tr][td]1[/td][td]2[/td][/tr]\n[/table]', '', true],
            hr: ['\n[hr]\n', '', true],
        },
    };
    const HINT = {
        markdown: t('js.app.hint_markdown'),
        bbcode:   t('js.app.hint_bbcode'),
    };
    const fmt = () => (fmtEl && fmtEl.value === 'markdown') ? 'markdown' : 'bbcode';

    /** Wrap the selection, or drop a stub at the caret and select it so typing replaces it. */
    function wrap(kind) {
        const syn = SYNTAX[fmt()];
        if (!syn || !syn[kind]) return;
        const [open, close, block] = syn[kind];
        const start = ta.selectionStart, end = ta.selectionEnd;
        const sel = ta.value.slice(start, end);
        if (block) {
            // A whole construct, dropped in as-is. Wrapping the selection in a table skeleton would
            // produce a table with the author's sentence in its header, which nobody wants.
            const pre = start > 0 && ta.value[start - 1] !== '\n' ? '\n' : '';
            ta.setRangeText(pre + open + '\n', start, end, 'end');
            ta.focus();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }
        // A line-prefix mark (quote, and a Markdown list) belongs at the start of EVERY selected
        // line: wrapping the block instead would quote only its first line.
        const linewise = close === '';
        const inserted = linewise
            ? (sel || 'text').split('\n').map(l => open + l).join('\n')
            : open + (sel || 'text') + close;
        ta.setRangeText(inserted, start, end, 'end');
        if (!sel) ta.setSelectionRange(start + open.length, start + open.length + 4);
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function show(which) {
        tabs.forEach(t => t.classList.toggle('active', t.dataset.rt === which));
        ta.hidden = which !== 'write';
        if (tools) tools.hidden = which !== 'write';
        box.hidden = which !== 'preview';
        if (which === 'preview') render();
    }

    async function render() {
        const text = ta.value;
        const f = fmt();
        const key = f + String.fromCharCode(31) + text;
        // Only skip the round trip when the box already holds a GOOD render of this exact text. The
        // previous version recorded the key before asking and never cleared it on failure, so one
        // 403, one rate-limited 429 or one dropped connection wedged the preview for that text for
        // good — the retry the visitor pressed did nothing at all.
        if (lastShown && lastShown.ok && lastShown.key === key) return;
        if (!text.trim()) {
            box.textContent = '';
            box.appendChild(Object.assign(document.createElement('p'), {
                className: 'text-muted', textContent: t('js.app.nothing_to_preview') }));
            lastShown = { key, ok: true };
            return;
        }
        box.textContent = t('js.app.rendering');
        const r = await postJson('richtext_preview', {
            text, format: f, csrf_token: csrf ? csrf.value : '' });
        if (!r) { box.textContent = t('js.app.server_unreachable'); lastShown = { key, ok: false }; return; }
        if (!r.success) { box.textContent = r.error || t('js.app.render_failed'); lastShown = { key, ok: false }; return; }
        // The server built this from fully escaped input with a fixed tag whitelist
        // (includes/richtext.php). It is the same string the public page will show.
        box.innerHTML = r.html;
        lastShown = { key, ok: true };
        if (counter) {
            const bits = [t('js.app.count_characters', {used: r.length, limit: r.limit})];
            if (r.images.limit > 0 || r.images.used) bits.push(t('js.app.count_images', {used: r.images.used, limit: r.images.limit}));
            if (r.links.limit > 0 || r.links.used) bits.push(t('js.app.count_links', {used: r.links.used, limit: r.links.limit}));
            counter.textContent = bits.join(' · ');
        }
        if (help) {
            help.textContent = r.problem || '';
            help.classList.toggle('form-hint-bad', !!r.problem);
        }
    }

    function syncFormat() {
        if (syntax) syntax.textContent = HINT[fmt()];
        // Hide the buttons this format has no syntax for, and the group that empties with them.
        if (tools) {
            const syn = SYNTAX[fmt()] || {};
            tools.querySelectorAll('[data-md]').forEach(b => { b.hidden = !syn[b.dataset.md]; });
            tools.querySelectorAll('.rt-tool-group').forEach(g => {
                g.hidden = ![...g.querySelectorAll('[data-md]')].some(b => !b.hidden);
            });
        }
        lastShown = null;                       // the same text renders differently in the other syntax
        if (!box.hidden) render();
    }

    tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.rt)));
    if (tools) tools.addEventListener('click', (e) => {
        const b = e.target.closest('[data-md]');
        if (b) wrap(b.dataset.md);
    });
    ta.addEventListener('keydown', (e) => {
        if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
        const kind = { b: 'bold', i: 'italic', k: 'link' }[e.key.toLowerCase()];
        if (!kind) return;
        e.preventDefault();
        wrap(kind);
    });
    ta.addEventListener('input', () => {
        clearTimeout(timer);
        // The counter is worth having while WRITING too, not only after a preview — it is the only
        // thing that tells somebody they are near the character limit before the form refuses them.
        if (counter && ta.maxLength > 0) counter.textContent = t('js.app.count_characters', {used: ta.value.length, limit: ta.maxLength});
        if (!box.hidden) timer = setTimeout(render, 400);
    });
    if (fmtEl && fmtEl.tagName === 'SELECT') fmtEl.addEventListener('change', syncFormat);
    syncFormat();
    if (counter && ta.maxLength > 0) counter.textContent = t('js.app.count_characters', {used: 0, limit: ta.maxLength});
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
        probing: [t('js.app.probe_checking'), 'wl-probe-wait'],
        passed:  [t('js.app.probe_passed'), 'wl-probe-ok'],
        failed:  [t('js.app.probe_failed'), 'wl-probe-bad'],
        none:    [t('js.app.chip_registered'), 'wl-probe-ok'],
        unknown: [t('js.app.probe_failed'), 'wl-probe-bad'],
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
                if (it.seeders != null) bits.push(t('js.app.probe_swarm', {seeders: it.seeders, leechers: it.leechers || 0}));
                if (it.files != null) bits.push(it.files === 1 ? t('js.app.probe_one_file') : t('js.app.probe_files', {n: it.files}));
                detail.textContent = bits.join(' · ');
            } else if (it.state === 'failed' || it.state === 'unknown') {
                // The reason, verbatim from the server. It is the whole point of the line.
                detail.textContent = it.error || t('js.app.probe_not_passed');
            } else {
                detail.textContent = it.meta === 'done'
                    ? t('js.app.probe_meta_done')
                    : t('js.app.probe_meta_wait');
            }
            li.appendChild(detail);
            list.appendChild(li);
        });
    }

    async function poll(hashes, timeoutMinutes) {
        const r = await getJson('whitelist_probe&hashes=' + encodeURIComponent(hashes.join(',')));
        if (!r || !r.success) {
            note.textContent = (r && r.error) || t('js.app.probe_progress_failed');
            return;
        }
        draw(r.items);
        const waiting = Object.keys(r.items).filter(h => r.items[h].state === 'probing').length;
        if (waiting === 0) {
            note.textContent = t('js.app.done');
            clearTimeout(timer);
            return;
        }
        const mins = Math.round((Date.now() - started) / 60000);
        note.textContent = t('js.app.probe_waiting', {n: waiting, timeout: timeoutMinutes})
            + (mins >= 1 ? ' ' + t('js.app.probe_so_far', {mins: mins}) : '');
        // Every three seconds. Faster tells nobody anything: the worker polls its queue on its own
        // schedule and the answer cannot change in between.
        timer = setTimeout(() => poll(hashes, timeoutMinutes), 3000);
    }

    window.wlWatchProbe = function (hashes, timeoutMinutes) {
        if (!hashes || !hashes.length) return;
        started = Date.now();
        box.hidden = false;
        note.textContent = hashes.length === 1 ? t('js.app.probe_start_one') : t('js.app.probe_start_many', {n: hashes.length});
        clearTimeout(timer);
        poll(hashes, timeoutMinutes || 10);
    };
})();
