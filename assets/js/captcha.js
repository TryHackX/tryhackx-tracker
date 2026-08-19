// === CAPTCHA Modal System (shared by the public site, admin dashboard and admin login) ===
// Provider-agnostic: works with Google reCAPTCHA v2 (grecaptcha widget), Google reCAPTCHA v3
// (invisible — grecaptcha.execute, no modal at all) or Cloudflare Turnstile.
// The page must include, before this file, the head tags produced by captchaHeadTags():
//   const CAPTCHA_PROVIDER = 'recaptcha' | 'recaptcha_v3' | 'turnstile'; const CAPTCHA_SITEKEY = '...';
// and, for the widget providers, the modal markup:
//   <div class="captcha-overlay" id="captcha-overlay"><div class="captcha-box"> ... <div id="captcha-widget"></div></div></div>
// Exposes window.showCaptchaModal(opts?) -> Promise<string|null> (token, or null when the user cancels
// / CAPTCHA is unavailable) and window.captchaReset(). `opts.action` (optional) is the reCAPTCHA v3
// action name (letters, digits, / and _ only; default 'submit'); ignored by the other providers.
(function () {
    let captchaWidgetId = null;
    let captchaResolve = null;

    // Called by the reCAPTCHA loader (api.js?onload=onRecaptchaLoad). Widget is rendered on demand.
    window.onRecaptchaLoad = window.onRecaptchaLoad || function () {};

    function provider() {
        if (typeof CAPTCHA_PROVIDER === 'undefined') return 'recaptcha';
        return (CAPTCHA_PROVIDER === 'turnstile' || CAPTCHA_PROVIDER === 'recaptcha_v3') ? CAPTCHA_PROVIDER : 'recaptcha';
    }

    function siteKey() {
        if (typeof CAPTCHA_SITEKEY !== 'undefined') return CAPTCHA_SITEKEY;
        if (typeof RECAPTCHA_SITEKEY !== 'undefined') return RECAPTCHA_SITEKEY;
        return '';
    }

    function libReady() {
        const p = provider();
        if (p === 'turnstile') return typeof turnstile !== 'undefined';
        if (p === 'recaptcha_v3') {
            return typeof grecaptcha !== 'undefined' && typeof grecaptcha.ready === 'function' && typeof grecaptcha.execute === 'function';
        }
        return typeof grecaptcha !== 'undefined' && typeof grecaptcha.render === 'function';
    }

    function hideOverlay() {
        const overlay = document.getElementById('captcha-overlay');
        if (overlay) overlay.classList.remove('show');
    }

    function finish(token) {
        hideOverlay();
        if (captchaResolve) {
            const r = captchaResolve;
            captchaResolve = null;
            r(token);
        }
    }

    function resetWidget() {
        if (captchaWidgetId === null) return;
        try {
            if (provider() === 'turnstile') turnstile.reset(captchaWidgetId);
            else grecaptcha.reset(captchaWidgetId);
        } catch {}
    }

    function renderWidget(container) {
        const key = siteKey();
        if (provider() === 'turnstile') {
            return turnstile.render(container, {
                sitekey: key,
                theme: 'dark',
                callback: (token) => finish(token),
                'error-callback': () => { resetWidget(); },
                'expired-callback': () => { resetWidget(); },
            });
        }
        return grecaptcha.render(container, {
            sitekey: key,
            theme: 'dark',
            callback: (token) => finish(token),
        });
    }

    // ── reCAPTCHA v3 (invisible) ──
    // Google only accepts action names made of letters, digits, '/' and '_'.
    function v3Action(name) {
        const clean = String(name || '').replace(/[^A-Za-z0-9/_]/g, '');
        return clean || 'submit';
    }

    // The v3 loader is async/defer: a very fast submit can beat it, so wait a little for grecaptcha
    // before giving up (resolves true as soon as the library is usable, false after `ms`).
    function waitForLib(ms) {
        return new Promise((resolve) => {
            if (libReady()) { resolve(true); return; }
            const started = Date.now();
            const timer = setInterval(() => {
                if (libReady()) { clearInterval(timer); resolve(true); }
                else if (Date.now() - started >= ms) { clearInterval(timer); resolve(false); }
            }, 100);
        });
    }

    // Silent token: grecaptcha.ready → grecaptcha.execute(siteKey, {action}). Never rejects and never
    // hangs the caller: any failure (or a stuck loader) resolves null so the caller shows its usual
    // "CAPTCHA cancelled / unavailable" message.
    function executeV3(action) {
        return new Promise((resolve) => {
            let done = false;
            const settle = (t) => { if (!done) { done = true; resolve(t || null); } };
            const guard = setTimeout(() => settle(null), 15000);
            try {
                grecaptcha.ready(() => {
                    try {
                        Promise.resolve(grecaptcha.execute(siteKey(), { action: action }))
                            .then((t) => { clearTimeout(guard); settle(t); }, () => { clearTimeout(guard); settle(null); });
                    } catch { clearTimeout(guard); settle(null); }
                });
            } catch { clearTimeout(guard); settle(null); }
        });
    }

    window.showCaptchaModal = function (opts) {
        if (provider() === 'recaptcha_v3') {
            // No modal, no widget: fetch a score token in the background.
            if (!siteKey()) return Promise.resolve(null);
            const action = v3Action(opts && opts.action);
            return waitForLib(4000).then((ok) => ok ? executeV3(action) : null);
        }
        return new Promise((resolve) => {
            const overlay = document.getElementById('captcha-overlay');
            const container = document.getElementById('captcha-widget');
            if (!overlay || !container || !siteKey() || !libReady()) {
                resolve(null);
                return;
            }
            // A previous, still-open prompt is superseded (treated as cancelled).
            if (captchaResolve) { const r = captchaResolve; captchaResolve = null; r(null); }
            captchaResolve = resolve;
            try {
                if (captchaWidgetId !== null) {
                    resetWidget();
                } else {
                    captchaWidgetId = renderWidget(container);
                }
            } catch {
                captchaWidgetId = null;
                finish(null);
                return;
            }
            overlay.classList.add('show');
        });
    };

    window.captchaReset = function () {
        resetWidget();
    };

    // Cancelling only hides the overlay and resolves null — the widget stays rendered in the box, so a
    // cancelled prompt keeps its state (a later showCaptchaModal() resets it for a fresh token).
    // Backdrop click (outside the box) counts as cancel, but only when the press STARTED on the backdrop:
    // a drag that begins inside the box (selecting the challenge, a slipped tap) and ends on the overlay
    // must not close it.
    let pressedOnOverlay = false;
    document.addEventListener('mousedown', (e) => {
        const overlay = document.getElementById('captcha-overlay');
        pressedOnOverlay = !!overlay && e.target === overlay;
    }, true);
    document.addEventListener('click', (e) => {
        const overlay = document.getElementById('captcha-overlay');
        if (!overlay || !overlay.classList.contains('show')) return;
        if (e.target === overlay) {
            if (pressedOnOverlay) finish(null);
            pressedOnOverlay = false;
            return;
        }
        // explicit Cancel button inside the box (optional markup: .captcha-cancel / #captcha-cancel)
        if (e.target.closest && e.target.closest('.captcha-cancel, #captcha-cancel')) {
            e.preventDefault();
            finish(null);
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const overlay = document.getElementById('captcha-overlay');
        if (overlay && overlay.classList.contains('show')) finish(null);
    });
})();
