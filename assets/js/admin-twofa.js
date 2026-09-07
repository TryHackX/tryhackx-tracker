/**
 * "Two-factor authentication" section on the admin Settings page — includes/twofa.php on the server.
 *
 * Three things this panel is careful about, because they are what goes wrong with 2FA:
 *
 *  - It never claims anything is on before the server says so. The secret is pending until a code
 *    generated from it has been verified, and the panel says that in as many words while it is.
 *  - The recovery codes are shown once, and the page says so before showing them rather than after.
 *  - The QR is drawn by this project's own encoder (includes/qr.php) and rendered inline. No hosted
 *    QR service and no CDN library is involved, because either would be handed a secret that is as
 *    good as the password. The typed key stays on screen underneath it.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    const panel = document.getElementById('tf-panel');
    if (!panel || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction } = window.AdminCommon;

    let state = null;
    let setup = null;          // the pending secret + codes, while a setup is in progress

    async function call(op, body) {
        return apiCall('admin/twofa', 'POST', Object.assign({ op: op }, body || {}));
    }

    async function load() {
        try {
            state = await call('status');
        } catch (e) {
            panel.textContent = '';
            panel.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.twofa.status_failed') }));
            return;
        }
        setup = null;
        render();
    }

    function row(label, node) {
        const d = el('div', { className: 'mb-2' });
        d.appendChild(el('label', { className: 'form-label', style: 'font-size:0.85rem;color:#bbb;', text: label }));
        d.appendChild(node);
        return d;
    }
    function pwInput(id) {
        return el('input', { type: 'password', id: id, className: 'form-control bg-dark text-light border-secondary',
                             autocomplete: 'current-password' });
    }
    function codeInput(id, ph) {
        return el('input', { type: 'text', id: id, className: 'form-control bg-dark text-light border-secondary',
                             maxLength: 32, placeholder: ph || '123456', autocomplete: 'one-time-code' });
    }

    /** Shown once, and the page says so before they appear rather than after. */
    function recoveryBlock(codes, heading) {
        const box = el('div', { className: 'nl-note nl-note-warn mt-2' });
        box.appendChild(el('div', { text: heading }));
        box.appendChild(el('pre', { className: 'nl-preview mt-1', text: codes.join('\n') }));
        const btn = el('button', { className: 'btn btn-sm btn-outline-secondary mt-1', type: 'button' },
            [el('i', { className: 'bi bi-clipboard' }), ' ' + t('js.twofa.copy_them')]);
        btn.addEventListener('click', () => {
            navigator.clipboard.writeText(codes.join('\n')).then(
                () => showToast(t('js.twofa.copied'), 'success'),
                () => showToast(t('js.twofa.copy_failed'), 'error'));
        });
        box.appendChild(btn);
        return box;
    }

    function render() {
        panel.textContent = '';
        if (!state || !state.success) {
            panel.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.twofa.status_failed') }));
            return;
        }
        if (state.writable === false) {
            panel.appendChild(el('div', { className: 'nl-note nl-note-bad', text: t('js.twofa.not_writable') }));
        }

        /* ── a setup in progress ─────────────────────────────────────────── */
        if (setup) {
            panel.appendChild(el('div', { className: 'nl-note nl-note-info', text: setup.note }));
            const key = el('div', { className: 'nl-note' });
            // The QR first, because scanning is what almost everybody will do; the key stays right
            // underneath for the people who cannot, or who are setting the app up on this same screen.
            // innerHTML is safe here and nowhere near a general habit: qr.svg is built by
            // includes/qr.php out of integers and fixed strings — no part of it comes from input.
            if (setup.qr) {
                const box = el('div', { className: 'tf-qr' });
                box.innerHTML = setup.qr;
                key.appendChild(box);
            }
            key.appendChild(el('div', { className: 'wl-small text-muted' + (setup.qr ? ' mt-1' : ''), text: setup.qr_note }));
            key.appendChild(el('div', { className: 'wl-small text-muted mt-2', text: t('js.twofa.setup_key') }));
            key.appendChild(el('pre', { className: 'nl-preview mt-1', text: setup.secret_grouped }));
            key.appendChild(el('div', { className: 'wl-small text-muted', text: t('js.twofa.account_note') }));
            key.appendChild(el('div', { className: 'wl-small text-muted mt-1', text: t('js.twofa.uri_note') }));
            key.appendChild(el('pre', { className: 'nl-preview mt-1', text: setup.uri }));
            panel.appendChild(key);
            panel.appendChild(recoveryBlock(setup.recovery, t('js.twofa.save_codes_now')));

            const ci = codeInput('tf-confirm-code');
            panel.appendChild(row(t('js.twofa.code_from_app'), ci));
            const go = el('button', { className: 'btn btn-sm btn-outline-success', type: 'button' },
                [el('i', { className: 'bi bi-check-lg' }), ' ' + t('js.twofa.turn_on')]);
            const cancel = el('button', { className: 'btn btn-sm btn-outline-secondary ms-2', type: 'button' }, [t('js.twofa.cancel')]);
            go.addEventListener('click', async () => {
                go.disabled = true;
                try {
                    const r = await call('confirm', { code: ci.value });
                    if (r.success) { showToast(r.message, 'success'); await load(); }
                    else { showToast(r.error || t('js.twofa.failed'), 'error'); }
                } catch { showToast(t('js.twofa.network_error'), 'error'); }
                go.disabled = false;
            });
            cancel.addEventListener('click', async () => { await call('cancel'); await load(); });
            const acts = el('div', { className: 'mt-2' });
            acts.appendChild(go); acts.appendChild(cancel);
            panel.appendChild(acts);
            return;
        }

        /* ── off ─────────────────────────────────────────────────────────── */
        if (!state.enabled) {
            panel.appendChild(el('div', {}, [
                el('span', { className: 'wl-badge wl-b-muted', text: t('js.twofa.badge_off') }),
                el('span', { className: 'wl-small text-muted', text: '  ' + t('js.twofa.password_only') }),
            ]));
            const pw = pwInput('tf-begin-pw');
            panel.appendChild(row(t('js.twofa.admin_password'), pw));
            const btn = el('button', { className: 'btn btn-sm btn-outline-success mt-1', type: 'button' },
                [el('i', { className: 'bi bi-shield-lock' }), ' ' + t('js.twofa.set_it_up')]);
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                try {
                    const r = await call('begin', { password: pw.value });
                    if (r.success) { setup = r; render(); }
                    else showToast(r.error || t('js.twofa.failed'), 'error');
                } catch { showToast(t('js.twofa.network_error'), 'error'); }
                btn.disabled = false;
            });
            panel.appendChild(btn);
            return;
        }

        /* ── on ──────────────────────────────────────────────────────────── */
        const head = el('div', { className: 'mb-2' });
        head.appendChild(el('span', { className: 'wl-badge wl-b-ok', text: t('js.twofa.badge_on') }));
        head.appendChild(el('span', { className: 'wl-small text-muted',
            text: '  ' + t('js.twofa.since_codes_left', { date: (state.confirmed_at ? new Date(state.confirmed_at * 1000).toLocaleString() : '?'),
                n: state.recovery_left }) }));
        panel.appendChild(head);
        if (state.recovery_left <= 2) {
            panel.appendChild(el('div', { className: 'nl-note nl-note-warn', text: t('js.twofa.few_codes_left', { n: state.recovery_left }) }));
        }

        const mk = (title, opName, okLabel, cls, hint) => {
            const wrap = el('div', { className: 'nl-note mt-2' });
            wrap.appendChild(el('div', { className: 'wl-kv-k', text: title }));
            if (hint) wrap.appendChild(el('div', { className: 'wl-small text-muted', text: hint }));
            const pw = pwInput('tf-' + opName + '-pw');
            const ci = codeInput('tf-' + opName + '-code', t('js.twofa.code_placeholder'));
            wrap.appendChild(row(t('js.twofa.admin_password'), pw));
            wrap.appendChild(row(t('js.twofa.current_code'), ci));
            const btn = el('button', { className: 'btn btn-sm ' + cls, type: 'button' }, [okLabel]);
            btn.addEventListener('click', async () => {
                if (opName === 'disable' && !await confirmAction(t('js.twofa.disable_title'),
                    t('js.twofa.disable_body'),
                    { okLabel: t('js.twofa.disable_ok'), danger: true })) return;
                btn.disabled = true;
                try {
                    const r = await call(opName, { password: pw.value, code: ci.value });
                    if (r.success) {
                        showToast(r.message || t('js.twofa.done'), 'success');
                        if (r.recovery) {
                            // New codes replace every old one, so they get the same once-only treatment.
                            panel.textContent = '';
                            panel.appendChild(recoveryBlock(r.recovery, t('js.twofa.new_codes')));
                            const done = el('button', { className: 'btn btn-sm btn-outline-secondary mt-2', type: 'button' }, [t('js.twofa.saved_them')]);
                            done.addEventListener('click', load);
                            panel.appendChild(done);
                            return;
                        }
                        await load();
                    } else showToast(r.error || t('js.twofa.failed'), 'error');
                } catch { showToast(t('js.twofa.network_error'), 'error'); }
                btn.disabled = false;
            });
            wrap.appendChild(btn);
            return wrap;
        };

        panel.appendChild(mk(t('js.twofa.regen_title'), 'regen', t('js.twofa.regen_ok'), 'btn-outline-warning',
            t('js.twofa.regen_hint')));
        panel.appendChild(mk(t('js.twofa.disable_section'), 'disable', t('js.twofa.disable_btn'), 'btn-outline-danger',
            t('js.twofa.disable_hint')));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
