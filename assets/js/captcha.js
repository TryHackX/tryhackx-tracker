// === CAPTCHA Modal System (shared by the public site, admin dashboard and admin login) ===
// Provider-agnostic: works with Google reCAPTCHA v2 (grecaptcha) or Cloudflare Turnstile.
// The page must include, before this file, the head tags produced by captchaHeadTags():
//   const CAPTCHA_PROVIDER = 'recaptcha' | 'turnstile'; const CAPTCHA_SITEKEY = '...';
// and the modal markup:
//   <div class="captcha-overlay" id="captcha-overlay"><div class="captcha-box"> ... <div id="captcha-widget"></div></div></div>
// Exposes window.showCaptchaModal() -> Promise<string|null> (token, or null when the user cancels
// / CAPTCHA is unavailable) and window.captchaReset().
(function () {
    let captchaWidgetId = null;
    let captchaResolve = null;

    // Called by the reCAPTCHA loader (api.js?onload=onRecaptchaLoad). Widget is rendered on demand.
    window.onRecaptchaLoad = window.onRecaptchaLoad || function () {};

    function provider() {
        return (typeof CAPTCHA_PROVIDER !== 'undefined' && CAPTCHA_PROVIDER === 'turnstile') ? 'turnstile' : 'recaptcha';
    }

    function siteKey() {
        if (typeof CAPTCHA_SITEKEY !== 'undefined') return CAPTCHA_SITEKEY;
        if (typeof RECAPTCHA_SITEKEY !== 'undefined') return RECAPTCHA_SITEKEY;
        return '';
    }

    function libReady() {
        return provider() === 'turnstile' ? (typeof turnstile !== 'undefined') : (typeof grecaptcha !== 'undefined' && typeof grecaptcha.render === 'function');
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

    window.showCaptchaModal = function () {
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

    // Close modal on overlay click (outside the box) — counts as cancel.
    document.addEventListener('click', (e) => {
        const overlay = document.getElementById('captcha-overlay');
        if (overlay && e.target === overlay) {
            finish(null);
        }
    });
})();
