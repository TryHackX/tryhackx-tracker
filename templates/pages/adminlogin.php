<?php
/**
 * Admin sign-in form.
 *
 * Rendered through the public layout (templates/layout.php) so the staff entrance carries the same
 * look as the rest of the site — nav, footer, fonts, CAPTCHA overlay and notice all come for free.
 * The form is reached at ?action=<admin_login_path> (Settings → Admin Access & Sessions; 'admin' by
 * default); the panel pages themselves keep their classic ?action=admin / settings / … addresses.
 */
// Proof that this session found the sign-in address: api/admin/login.php requires the marker while
// the panel lives on a custom path, so the (unmovable) login endpoint cannot be hammered by a bot
// that never located this page. Harmless on a default install, where the check is not applied.
$_SESSION['admin_login_form_at'] = time();
?>
<h1>Admin sign in</h1>
<p class="text-muted">Staff area of <?= sanitize($cfg['site_name'] ?? 'this tracker') ?>.</p>

<div class="user-card">
    <div id="admin-login-alert" class="alert"></div>
    <form id="admin-login-form" class="user-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <div class="form-group">
            <label for="al-username">Username</label>
            <input type="text" id="al-username" name="username" maxlength="190" autocomplete="username" required autofocus>
        </div>
        <div class="form-group">
            <label for="al-password">Password</label>
            <input type="password" id="al-password" name="password" maxlength="200" autocomplete="current-password" required>
        </div>
        <div class="form-center"><button type="submit" class="btn" id="al-submit">Sign in</button></div>
    </form>
    <p class="user-links"><a href="<?= $baseUrl ?>">Back to the tracker</a></p>
</div>

<script>
(function () {
    const form = document.getElementById('admin-login-form');
    const alertEl = document.getElementById('admin-login-alert');
    const api = '<?= $baseUrl ?>api.php?endpoint=';
    const fail = (msg) => { alertEl.className = 'alert alert-error show'; alertEl.textContent = msg; };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('al-submit');
        const data = {
            username: form.username.value,
            password: form.password.value,
            csrf_token: form.csrf_token.value,
        };
        const post = () => fetch(api + 'admin/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        }).then(r => r.json());

        btn.disabled = true;
        try {
            // Refresh this page's sign-in marker before posting: while the panel address is custom,
            // api/admin/login.php only accepts sign-ins from a session that has opened this page, and
            // a form left open past the marker's lifetime would otherwise be told the password is
            // wrong. Re-fetching our own URL re-stamps it and costs one cheap GET.
            try { await fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' }); } catch {}
            let json = await post();
            // CAPTCHA may be demanded up to twice (a solved token can expire while typing) — re-show it.
            // (reCAPTCHA v3: no modal — showCaptchaModal() fetches a score token silently.)
            for (let attempt = 0; attempt < 2 && json.captcha_required; attempt++) {
                const token = await window.showCaptchaModal({ action: 'admin_login' });
                if (!token) { fail(window.captchaWasUnavailable && window.captchaWasUnavailable() ? 'CAPTCHA could not load — reload the page or try again later.' : 'CAPTCHA cancelled'); return; }
                data['captcha_token'] = token;
                data['g-recaptcha-response'] = token;
                json = await post();
            }

            if (json.success) {
                window.location.reload();
            } else if ((json.error || '').indexOf('CSRF') !== -1) {
                // the session behind this page expired (PHP session GC) — reload for a fresh token
                fail('This login page had expired — reloading, please try again.');
                setTimeout(() => window.location.reload(), 1200);
            } else {
                fail(json.error === 'Invalid credentials' ? 'Invalid username or password' : (json.error || 'Login failed'));
            }
        } catch {
            fail('Network error');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
