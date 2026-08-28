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
    const { apiCall, el, showToast } = window.AdminCommon;

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
            panel.appendChild(el('div', { className: 'nl-note nl-note-bad', text: 'Could not read the status.' }));
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
            [el('i', { className: 'bi bi-clipboard' }), ' Copy them']);
        btn.addEventListener('click', () => {
            navigator.clipboard.writeText(codes.join('\n')).then(
                () => showToast('Copied. Put them somewhere that is not this machine.', 'success'),
                () => showToast('Could not copy — select the text instead.', 'error'));
        });
        box.appendChild(btn);
        return box;
    }

    function render() {
        panel.textContent = '';
        if (!state || !state.success) {
            panel.appendChild(el('div', { className: 'nl-note nl-note-bad', text: 'Could not read the status.' }));
            return;
        }
        if (state.writable === false) {
            panel.appendChild(el('div', { className: 'nl-note nl-note-bad', text:
                'config/ is not writable by the web server, so a secret could not be stored. Fix that before '
                + 'turning this on — a setup that cannot be saved would leave your app generating codes for a '
                + 'secret this server has forgotten.' }));
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
            key.appendChild(el('div', { className: 'wl-small text-muted mt-2', text: 'Setup key (type this into your authenticator app):' }));
            key.appendChild(el('pre', { className: 'nl-preview mt-1', text: setup.secret_grouped }));
            key.appendChild(el('div', { className: 'wl-small text-muted', text: 'Account: the admin username. Algorithm SHA1, 6 digits, 30 seconds — the defaults every app uses.' }));
            key.appendChild(el('div', { className: 'wl-small text-muted mt-1', text: 'Full otpauth URI, if your app accepts one:' }));
            key.appendChild(el('pre', { className: 'nl-preview mt-1', text: setup.uri }));
            panel.appendChild(key);
            panel.appendChild(recoveryBlock(setup.recovery,
                'Save these ten recovery codes NOW — they are shown once and never again. Each one works a '
                + 'single time, and they are the only way back if you lose the app.'));

            const ci = codeInput('tf-confirm-code');
            panel.appendChild(row('Code from the app *', ci));
            const go = el('button', { className: 'btn btn-sm btn-outline-success', type: 'button' },
                [el('i', { className: 'bi bi-check-lg' }), ' Turn it on']);
            const cancel = el('button', { className: 'btn btn-sm btn-outline-secondary ms-2', type: 'button' }, ['Cancel']);
            go.addEventListener('click', async () => {
                go.disabled = true;
                try {
                    const r = await call('confirm', { code: ci.value });
                    if (r.success) { showToast(r.message, 'success'); await load(); }
                    else { showToast(r.error || 'Failed.', 'error'); }
                } catch { showToast('Network error', 'error'); }
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
                el('span', { className: 'wl-badge wl-b-muted', text: 'off' }),
                el('span', { className: 'wl-small text-muted', text: '  The panel asks for a password only.' }),
            ]));
            const pw = pwInput('tf-begin-pw');
            panel.appendChild(row('Admin password *', pw));
            const btn = el('button', { className: 'btn btn-sm btn-outline-success mt-1', type: 'button' },
                [el('i', { className: 'bi bi-shield-lock' }), ' Set it up']);
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                try {
                    const r = await call('begin', { password: pw.value });
                    if (r.success) { setup = r; render(); }
                    else showToast(r.error || 'Failed.', 'error');
                } catch { showToast('Network error', 'error'); }
                btn.disabled = false;
            });
            panel.appendChild(btn);
            return;
        }

        /* ── on ──────────────────────────────────────────────────────────── */
        const head = el('div', { className: 'mb-2' });
        head.appendChild(el('span', { className: 'wl-badge wl-b-ok', text: 'on' }));
        head.appendChild(el('span', { className: 'wl-small text-muted',
            text: '  Since ' + (state.confirmed_at ? new Date(state.confirmed_at * 1000).toLocaleString() : '?')
                + ' · ' + state.recovery_left + ' recovery code(s) left' }));
        panel.appendChild(head);
        if (state.recovery_left <= 2) {
            panel.appendChild(el('div', { className: 'nl-note nl-note-warn', text:
                'Only ' + state.recovery_left + ' recovery code(s) left. Generate a new set now, while you can '
                + 'still sign in — they are what stands between a lost phone and a lost panel.' }));
        }

        const mk = (title, opName, okLabel, cls, hint) => {
            const wrap = el('div', { className: 'nl-note mt-2' });
            wrap.appendChild(el('div', { className: 'wl-kv-k', text: title }));
            if (hint) wrap.appendChild(el('div', { className: 'wl-small text-muted', text: hint }));
            const pw = pwInput('tf-' + opName + '-pw');
            const ci = codeInput('tf-' + opName + '-code', '123456 or a recovery code');
            wrap.appendChild(row('Admin password *', pw));
            wrap.appendChild(row('Current code *', ci));
            const btn = el('button', { className: 'btn btn-sm ' + cls, type: 'button' }, [okLabel]);
            btn.addEventListener('click', async () => {
                if (opName === 'disable' && !window.confirm('Turn two-factor authentication off?\n\nThe secret and every recovery code are deleted. Signing in will need the password alone again.')) return;
                btn.disabled = true;
                try {
                    const r = await call(opName, { password: pw.value, code: ci.value });
                    if (r.success) {
                        showToast(r.message || 'Done.', 'success');
                        if (r.recovery) {
                            // New codes replace every old one, so they get the same once-only treatment.
                            panel.textContent = '';
                            panel.appendChild(recoveryBlock(r.recovery,
                                'Ten new recovery codes. Every previous one stopped working just now — save these, '
                                + 'they are shown once.'));
                            const done = el('button', { className: 'btn btn-sm btn-outline-secondary mt-2', type: 'button' }, ['I have saved them']);
                            done.addEventListener('click', load);
                            panel.appendChild(done);
                            return;
                        }
                        await load();
                    } else showToast(r.error || 'Failed.', 'error');
                } catch { showToast('Network error', 'error'); }
                btn.disabled = false;
            });
            wrap.appendChild(btn);
            return wrap;
        };

        panel.appendChild(mk('New recovery codes', 'regen', 'Generate ten new codes', 'btn-outline-warning',
            'Replaces every existing code. Use this after you have signed in with one, or if you are not sure where the old list is.'));
        panel.appendChild(mk('Turn it off', 'disable', 'Turn off', 'btn-outline-danger',
            'Needs the password AND a current code — the whole point is that somebody who only has the password cannot switch it off.'));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
