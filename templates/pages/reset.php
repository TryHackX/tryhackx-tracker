<?php
$resetToken = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['token'] ?? '')));
$hasToken = strlen($resetToken) === 64;
?>
<h1><?= _h('reset.h1') ?></h1>

<?php if ($hasToken): ?>
<p><?= _h('reset.set_new') ?></p>
<div id="resetc-alert" class="alert"></div>
<form id="reset-confirm-form" class="user-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" id="resetc-token" value="<?= sanitize($resetToken) ?>">
    <div class="form-group">
        <label for="resetc-password"><?= _h('reset.new_pass') ?></label>
        <input type="password" id="resetc-password" maxlength="200" autocomplete="new-password" required>
        <div class="pw-checklist" id="resetc-pw-checklist"></div>
    </div>
    <div class="form-group">
        <label for="resetc-password2"><?= _h('reset.repeat') ?></label>
        <input type="password" id="resetc-password2" maxlength="200" autocomplete="new-password" required>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="resetc-submit"><?= _h('reset.submit_new') ?></button></div>
</form>
<?php elseif (!captchaConfigured($cfg)): ?>
<p><?= _h('reset.no_captcha') ?></p>
<?php else: ?>
<p><?= _h('reset.intro') ?></p>
<div id="reset-alert" class="alert"></div>
<form id="reset-request-form" class="user-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="form-group">
        <label for="reset-login"><?= _h('login.login_label') ?></label>
        <input type="text" id="reset-login" maxlength="190" autocomplete="username" required>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="reset-submit"><?= _h('reset.send') ?></button></div>
</form>
<?php endif; ?>
<p class="user-links"><a href="<?= $baseUrl ?>?action=login"><?= _h('reset.back') ?></a></p>
