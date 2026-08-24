<?php
$resetToken = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['token'] ?? '')));
$hasToken = strlen($resetToken) === 64;
?>
<h1>Password reset</h1>

<?php if ($hasToken): ?>
<p>Set a new password for your account.</p>
<div id="resetc-alert" class="alert"></div>
<form id="reset-confirm-form" class="user-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" id="resetc-token" value="<?= sanitize($resetToken) ?>">
    <div class="form-group">
        <label for="resetc-password">New password</label>
        <input type="password" id="resetc-password" maxlength="200" autocomplete="new-password" required>
        <div class="pw-checklist" id="resetc-pw-checklist"></div>
    </div>
    <div class="form-group">
        <label for="resetc-password2">Repeat password</label>
        <input type="password" id="resetc-password2" maxlength="200" autocomplete="new-password" required>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="resetc-submit">Set new password</button></div>
</form>
<?php elseif (!captchaConfigured($cfg)): ?>
<p>Password reset is unavailable (CAPTCHA is not configured on this site). Contact the site admin.</p>
<?php else: ?>
<p>Enter your username or email. If the account exists and has an email address, a reset link is sent to it (valid for 2 hours).</p>
<div id="reset-alert" class="alert"></div>
<form id="reset-request-form" class="user-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="form-group">
        <label for="reset-login">Username or email</label>
        <input type="text" id="reset-login" maxlength="190" autocomplete="username" required>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="reset-submit">Send reset link</button></div>
</form>
<?php endif; ?>
<p class="user-links"><a href="<?= $baseUrl ?>?action=login">Back to sign in</a></p>
