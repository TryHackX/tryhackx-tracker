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
    <?php if (isCaptchaEnabled($cfg, 'login')): ?>
    <div class="captcha-overlay" id="captcha-overlay">
        <div class="captcha-box">
            <p>Please verify you are human</p>
            <div id="captcha-widget" class="captcha-widget"></div>
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
            for (let attempt = 0; attempt < 2 && json.captcha_required; attempt++) {
                const token = await window.showCaptchaModal();
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
