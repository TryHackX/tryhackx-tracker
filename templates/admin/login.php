<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <?php if (isRecaptchaEnabled($cfg, 'login')): ?>
    <script src="https://www.google.com/recaptcha/api.js?onload=onRecaptchaLoad&render=explicit" async defer></script>
    <?php endif; ?>
    <style>
    .captcha-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:9999; justify-content:center; align-items:center; }
    .captcha-overlay.show { display:flex; }
    .captcha-box { background:#1e1e1e; border:1px solid #333; border-radius:8px; padding:1.5rem; text-align:center; max-width:340px; }
    .captcha-box p { color:#888; font-size:.85rem; margin-bottom:1rem; }
    .captcha-box .captcha-widget { display:inline-block; }
    </style>
</head>
<body class="admin-body">
    <div class="login-container">
        <h2>Login</h2>
        <p class="text-muted"><?= sanitize($cfg['site_name'] ?? 'Tracker') ?></p>
        <div id="login-alert" class="alert-box"></div>
        <form id="login-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="mb-3"><label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required autofocus></div>
            <div class="mb-3"><label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required></div>
            <button type="submit" class="btn btn-primary w-100">Sign in</button>
        </form>
    </div>
    <?php if (isRecaptchaEnabled($cfg, 'login')): ?>
    <div class="captcha-overlay" id="captcha-overlay">
        <div class="captcha-box">
            <p>Please verify you are human</p>
            <div id="captcha-widget" class="captcha-widget"></div>
        </div>
    </div>
    <?php endif; ?>
    <script>
    const API_BASE = '<?= $baseUrl ?>api.php?endpoint=';
    <?php if (isRecaptchaEnabled($cfg, 'login')): ?>
    const RECAPTCHA_SITEKEY = '<?= sanitize($cfg['recaptcha_site_key'] ?? '') ?>';
    <?php endif; ?>

    let captchaWidgetId = null;
    let captchaResolve = null;

    function onRecaptchaLoad() {}

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
        if (e.target === document.getElementById('captcha-overlay')) {
            document.getElementById('captcha-overlay').classList.remove('show');
            if (captchaResolve) { captchaResolve(''); captchaResolve = null; }
        }
    });

    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const alertEl = document.getElementById('login-alert');
        const data = {
            username: form.username.value,
            password: form.password.value,
            csrf_token: form.csrf_token.value,
        };

        try {
            const res = await fetch(API_BASE + 'admin/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            let json = await res.json();

            if (json.captcha_required) {
                const token = await showCaptchaModal();
                if (!token) { alertEl.className = 'alert-box error'; alertEl.textContent = 'CAPTCHA cancelled'; return; }
                data['g-recaptcha-response'] = token;
                const res2 = await fetch(API_BASE + 'admin/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                });
                json = await res2.json();
            }

            if (json.success) {
                window.location.reload();
            } else {
                alertEl.className = 'alert-box error';
                alertEl.textContent = 'Invalid username or password';
            }
        } catch {
            alertEl.className = 'alert-box error';
            alertEl.textContent = 'Network error';
        }
    });
    </script>
</body>
</html>
