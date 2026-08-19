<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <?php if (isCaptchaEnabled($cfg, 'login')): ?>
    <?= captchaHeadTags($cfg) ?>
    <?php endif; ?>
    <!-- CAPTCHA modal styles (.captcha-overlay / .captcha-box) are shared in assets/css/admin.css -->
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
        <?php if (isCaptchaEnabled($cfg, 'login')): ?>
        <?= captchaNoticeHtml($cfg, 'captcha-notice text-muted') ?>
        <?php endif; ?>
    </div>
    <?php if (isCaptchaEnabled($cfg, 'login')): ?>
    <div class="captcha-overlay" id="captcha-overlay">
        <div class="captcha-box">
            <p>Please verify you are human</p>
            <div id="captcha-widget" class="captcha-widget"></div>
            <div class="captcha-actions"><button type="button" class="btn btn-secondary captcha-cancel" id="captcha-cancel">Cancel</button></div>
        </div>
    </div>
    <?php endif; ?>
    <script src="<?= $baseUrl ?>assets/js/captcha.js<?= assetVer('assets/js/captcha.js') ?>"></script>
    <script>
    const API_BASE = '<?= $baseUrl ?>api.php?endpoint=';
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

            // CAPTCHA may be demanded up to twice (a solved token can expire while typing) — re-show it.
            // (reCAPTCHA v3: no modal — showCaptchaModal() fetches a score token silently.)
            for (let attempt = 0; attempt < 2 && json.captcha_required; attempt++) {
                const token = await window.showCaptchaModal({ action: 'admin_login' });
                if (!token) { alertEl.className = 'alert-box error'; alertEl.textContent = 'CAPTCHA cancelled'; return; }
                data['captcha_token'] = token;
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
            } else if ((json.error || '').indexOf('CSRF') !== -1) {
                // the session behind this page expired (PHP session GC) — reload for a fresh token
                alertEl.className = 'alert-box error';
                alertEl.textContent = 'This login page had expired — reloading, please try again.';
                setTimeout(() => window.location.reload(), 1200);
            } else {
                alertEl.className = 'alert-box error';
                alertEl.textContent = json.error === 'Invalid credentials' ? 'Invalid username or password' : (json.error || 'Login failed');
            }
        } catch {
            alertEl.className = 'alert-box error';
            alertEl.textContent = 'Network error';
        }
    });
    </script>
</body>
</html>
