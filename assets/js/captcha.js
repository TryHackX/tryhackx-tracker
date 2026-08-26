// === CAPTCHA Modal System (shared by the public site, admin dashboard and admin login) ===
// Provider-agnostic: works with Google reCAPTCHA v2 (grecaptcha widget), Google reCAPTCHA v3
// (invisible — grecaptcha.execute, no modal at all), Cloudflare Turnstile or hCaptcha.
// The page must include, before this file, the head tags produced by captchaHeadTags():
//   const CAPTCHA_PROVIDER = 'recaptcha' | 'recaptcha_v3' | 'turnstile' | 'hcaptcha'; const CAPTCHA_SITEKEY = '...';
// and, for the widget providers, the modal markup:
//   <div class="captcha-overlay" id="captcha-overlay"><div class="captcha-box"> ... <div id="captcha-widget"></div></div></div>
// Exposes window.showCaptchaModal(opts?) -> Promise<string|null> (token, or null when the user cancels
// / CAPTCHA is unavailable), window.captchaReset() and window.captchaWasUnavailable() (true when the
// last prompt ended in a widget error rather than a cancel, so callers can say so). `opts.action`
// (optional) is the reCAPTCHA v3 action name (letters, digits, / and _ only; default 'submit');
// ignored by the other providers.
(function () {
    let captchaWidgetId = null;
    let captchaResolve = null;
    let widgetErrors = 0;          // per prompt — a permanently broken widget must not loop forever

    // Called by the provider loader (…api.js?onload=onCaptchaApiLoad). captchaHeadTags() defines the
    // real callback in an inline script that runs BEFORE the vendor script, so the signal can never be
    // missed; this is the fallback for pages that somehow load this file first. Widgets are rendered on
    // demand, never from the callback — hCaptcha explicitly warns against rendering during SDK setup.
    window.onCaptchaApiLoad = window.onCaptchaApiLoad || function () { window.captchaApiReady = true; };
    window.onRecaptchaLoad = window.onRecaptchaLoad || window.onCaptchaApiLoad;   // legacy name

    const PROVIDERS = ['recaptcha', 'recaptcha_v3', 'turnstile', 'hcaptcha'];

    function provider() {
        if (typeof CAPTCHA_PROVIDER === 'undefined') return 'recaptcha';
        return PROVIDERS.indexOf(CAPTCHA_PROVIDER) !== -1 ? CAPTCHA_PROVIDER : 'recaptcha';
    }

    function siteKey() {
        if (typeof CAPTCHA_SITEKEY !== 'undefined') return CAPTCHA_SITEKEY;
        if (typeof RECAPTCHA_SITEKEY !== 'undefined') return RECAPTCHA_SITEKEY;
        return '';
    }

    /** The SDK is loaded AND (where the provider offers an onload signal) fully set up. */
    function libReady() {
        // hCaptcha's docs: "Do not call hcaptcha.render() right away if you are using render=explicit
        // to avoid racing SDK setup" — the global appears before setup finishes, so the onload
        // callback is the only authoritative signal for it.
        if (provider() === 'hcaptcha') return window.captchaApiReady === true && libUsable();
        return libUsable();
    }

    /** The SDK looks callable. Used as a fallback if the onload signal never arrives. */
    function libUsable() {
        const p = provider();
        if (p === 'turnstile') return typeof turnstile !== 'undefined' && typeof turnstile.render === 'function';
        if (p === 'hcaptcha') return typeof hcaptcha !== 'undefined' && typeof hcaptcha.render === 'function';
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
        if (token) window.captchaLastError = null;
        if (captchaResolve) {
            const r = captchaResolve;
            captchaResolve = null;
            r(token);
        }
    }

    /** True when the last prompt died on a widget/loader error instead of a user cancel. */
    window.captchaWasUnavailable = function () { return !!window.captchaLastError; };

    /**
     * A widget error (network blip, but also the classic first-run mistakes: site key of the wrong
     * provider, or a key whose allowed-domain list does not include this host — Turnstile 110200,
     * hCaptcha "invalid-site-key"). One silent retry, then give up: resetting on every error made the
     * widget re-render and fail again in a tight loop with the modal stuck open.
     */
    function onWidgetError(kind) {
        widgetErrors++;
        if (widgetErrors <= 1) { resetWidget(); return; }
        window.captchaLastError = kind || 'widget-error';
        try { console.warn('[captcha] ' + provider() + ' widget error (' + window.captchaLastError + ') — check the site key and its allowed domains'); } catch {}
        finish(null);
    }

    /** grecaptcha's first widget id is 0, so `null`/`undefined` — not falsiness — mean "no widget". */
    function hasWidget() { return captchaWidgetId !== null && captchaWidgetId !== undefined; }

    /** Forget the current widget and empty its box, so the next prompt renders a fresh one. */
    function dropWidget() {
        const p = provider();
        try {
            if (hasWidget()) {
                if (p === 'turnstile' && typeof turnstile.remove === 'function') turnstile.remove(captchaWidgetId);
                else if (p === 'hcaptcha' && typeof hcaptcha.remove === 'function') hcaptcha.remove(captchaWidgetId);
            }
        } catch {}
        captchaWidgetId = null;
        const box = document.getElementById('captcha-widget');
        if (box) box.innerHTML = '';   // a half-rendered widget would block the next render
    }

    function resetWidget() {
        if (!hasWidget()) return;
        try {
            const p = provider();
            if (p === 'turnstile') turnstile.reset(captchaWidgetId);
            else if (p === 'hcaptcha') hcaptcha.reset(captchaWidgetId);
            else grecaptcha.reset(captchaWidgetId);
        } catch {}
    }

    function renderWidget(container) {
        const key = siteKey();
        const p = provider();
        if (p === 'turnstile') {
            return turnstile.render(container, {
                sitekey: key,
                theme: 'dark',
                retry: 'never',                       // we own the retry policy (onWidgetError)
                callback: (token) => finish(token),
                'error-callback': (code) => { onWidgetError(code); },
                'expired-callback': () => { resetWidget(); },
            });
        }
        if (p === 'hcaptcha') {
            return hcaptcha.render(container, {
                sitekey: key,
                theme: 'dark',
                callback: (token) => finish(token),
                'error-callback': (err) => { onWidgetError(err); },
                'expired-callback': () => { resetWidget(); },
                'chalexpired-callback': () => { resetWidget(); },
            });
        }
        return grecaptcha.render(container, {
            sitekey: key,
            theme: 'dark',
            callback: (token) => finish(token),
            'error-callback': () => { onWidgetError('recaptcha-error'); },
            'expired-callback': () => { resetWidget(); },
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
            const guard = setTimeout(() => { window.captchaLastError = 'v3-timeout'; settle(null); }, 15000);
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
            // No modal, no widget: fetch a score token in the background. There is nothing for the
            // visitor to cancel here, so EVERY empty answer is a failure — record why, or the caller
            // would tell them they "cancelled" something they never saw.
            window.captchaLastError = null;
            if (!siteKey()) { window.captchaLastError = 'no-sitekey'; return Promise.resolve(null); }
            const action = v3Action(opts && opts.action);
            return waitForLib(4000).then((ok) => {
                if (!ok) { window.captchaLastError = 'loader-timeout'; return null; }
                return executeV3(action).then((t) => {
                    window.captchaLastError = t ? null : (window.captchaLastError || 'v3-execute-failed');
                    return t;
                });
            });
        }
        const overlay = document.getElementById('captcha-overlay');
        const container = document.getElementById('captcha-widget');
        if (!overlay || !container || !siteKey()) return Promise.resolve(null);
        // A previous, still-open prompt is superseded (treated as cancelled). The resolver is stored
        // SYNCHRONOUSLY — while we wait for the widget script below, a second click must be able to
        // see (and settle) the first prompt, or that first caller would hang forever.
        if (captchaResolve) { const r = captchaResolve; captchaResolve = null; r(null); }
        let settle;
        const prompt = new Promise((resolve) => { settle = resolve; });
        captchaResolve = settle;
        widgetErrors = 0;
        window.captchaLastError = null;
        // Open the box BEFORE rendering: the widget providers measure their container, and one
        // rendered inside a display:none overlay comes out zero-sized (an invisible checkbox).
        overlay.classList.add('show');
        // The widget script is async/defer — a click that beat it used to fail instantly with
        // "CAPTCHA cancelled"; wait for it instead (the box is already open, so the wait is visible).
        waitForLib(8000).then((ok) => {
            if (captchaResolve !== settle) return;                  // superseded or already settled
            if (!ok && !libUsable()) { window.captchaLastError = 'loader-timeout'; finish(null); return; }
            try {
                if (hasWidget()) {
                    resetWidget();
                } else {
                    // A provider that declines to render (blank/foreign site key, occupied container)
                    // returns undefined instead of throwing: without this check the id would be poisoned,
                    // every later prompt would take the reset branch, no callback could ever fire and the
                    // caller would wait on an empty box forever.
                    const id = renderWidget(container);
                    if (id === null || id === undefined) {
                        dropWidget();
                        window.captchaLastError = 'render-failed';
                        finish(null);
                        return;
                    }
                    captchaWidgetId = id;
                }
            } catch (e) {
                dropWidget();
                window.captchaLastError = 'render-failed';
                finish(null);
            }
        });
        return prompt;
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
