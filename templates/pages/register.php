<?php
$regOpen = usersRegistrationEnabled($cfg) && captchaConfigured($cfg);
$meUser = currentUser($db);
?>
<h1>Create an account</h1>

<?php if ($meUser !== null): ?>
<p>You are already signed in as <strong><?= sanitize($meUser['username']) ?></strong>.</p>
<p><a class="btn" href="<?= $baseUrl ?>?action=account">Go to your account</a></p>
<?php elseif (!$regOpen): ?>
<p>Registration is currently <strong>closed</strong><?= usersRegistrationEnabled($cfg) && !captchaConfigured($cfg) ? ' (CAPTCHA is not configured on this site)' : '' ?>.</p>
<p><a href="<?= $baseUrl ?>?action=login">Sign in</a> if you already have an account.</p>
<?php else: ?>
<p>An account gives you access to member features — what exactly depends on the groups the admin grants. Registration is free; <?= captchaProvider($cfg) === 'recaptcha_v3' ? 'an invisible CAPTCHA check runs on submit' : 'a CAPTCHA is required' ?>.</p>
<div id="register-alert" class="alert"></div>
<form id="register-form" class="user-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="form-group">
        <label for="reg-username">Username <small class="form-hint">3&ndash;32 chars: letters, digits, _ . -</small></label>
        <input type="text" id="reg-username" name="username" maxlength="32" autocomplete="username" required>
        <div class="error-msg">3&ndash;32 characters: letters, digits and _ . -</div>
    </div>
    <div class="form-group">
        <label for="reg-email">Email <small class="form-hint">optional &mdash; used only for password resets and notifications</small></label>
        <input type="email" id="reg-email" name="email" maxlength="190" autocomplete="email">
        <div class="error-msg">That email address does not look valid</div>
    </div>
    <div class="form-group">
        <label for="reg-password">Password <small class="form-hint">at least 8 characters</small></label>
        <input type="password" id="reg-password" name="password" maxlength="200" autocomplete="new-password" required>
        <div class="error-msg">At least 8 characters</div>
    </div>
    <div class="form-group">
        <label for="reg-password2">Repeat password</label>
        <input type="password" id="reg-password2" name="password2" maxlength="200" autocomplete="new-password" required>
        <div class="error-msg">Passwords do not match</div>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="register-submit">Create account</button></div>
</form>
<p class="user-links">Already have an account? <a href="<?= $baseUrl ?>?action=login">Sign in</a>.</p>
<?php endif; ?>
