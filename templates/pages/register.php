<?php
$regOpen = usersRegistrationEnabled($cfg) && captchaConfigured($cfg);
$meUser = currentUser($db);
$regEmailRequired = userEmailVerifyRequired($cfg);
$regTermsText = trim((string)($cfg['users_terms_text'] ?? ''));
?>
<h1><?= _h('register.h1') ?></h1>

<?php if ($meUser !== null): ?>
<p><?= __('register.already', ['user' => sanitize($meUser['username'])]) ?></p>
<p><a class="btn" href="<?= $baseUrl ?>?action=account"><?= _h('common.go_account') ?></a></p>
<?php elseif (!$regOpen): ?>
<p><?= __('register.closed', ['why' => usersRegistrationEnabled($cfg) && !captchaConfigured($cfg)
        ? __('register.closed_captcha') : '']) ?></p>
<p><?= __('register.have_account', ['url' => sanitize($baseUrl . '?action=login')]) ?></p>
<?php else: ?>
<p><?= __('register.intro', [
        'verify'  => $regEmailRequired ? __('register.intro_verify') : '',
        'captcha' => __(captchaProvider($cfg) === 'recaptcha_v3' ? 'register.captcha_v3' : 'register.captcha_std'),
     ]) ?></p>
<div id="register-alert" class="alert"></div>
<form id="register-form" class="user-form" novalidate data-email-required="<?= $regEmailRequired ? '1' : '0' ?>" data-terms-custom="<?= $regTermsText !== '' ? '1' : '0' ?>">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="form-group">
        <label for="reg-username"><?= _h('register.username') ?> <small class="form-hint"><?= _h('register.username_hint') ?></small></label>
        <input type="text" id="reg-username" name="username" maxlength="32" autocomplete="username" required>
        <div class="error-msg"><?= _h('register.username_err') ?></div>
    </div>
    <div class="form-group">
        <label for="reg-email"><?= _h('register.email') ?> <small class="form-hint"><?= _h($regEmailRequired ? 'register.email_hint_req' : 'register.email_hint_opt') ?></small></label>
        <input type="email" id="reg-email" name="email" maxlength="190" autocomplete="email"<?= $regEmailRequired ? ' required' : '' ?>>
        <div class="error-msg"><?= _h($regEmailRequired ? 'register.email_err_req' : 'register.email_err_opt') ?></div>
    </div>
    <div class="form-group">
        <label for="reg-password"><?= _h('register.password') ?></label>
        <input type="password" id="reg-password" name="password" maxlength="200" autocomplete="new-password" required>
        <div class="pw-checklist" id="reg-pw-checklist"></div>
        <div class="error-msg"><?= _h('register.password_err') ?></div>
    </div>
    <div class="form-group">
        <label for="reg-password2"><?= _h('register.password2') ?></label>
        <input type="password" id="reg-password2" name="password2" maxlength="200" autocomplete="new-password" required>
        <div class="error-msg"><?= _h('register.password2_err') ?></div>
    </div>
    <div class="form-group">
        <label class="search-check reg-terms"><input type="checkbox" id="reg-terms"><span class="search-check-box" aria-hidden="true"></span>
            <span><?= __('register.terms', [
                'url'    => sanitize($baseUrl . '?action=tos'),
                'target' => $regTermsText !== '' ? '' : ' target="_blank"',
            ]) ?></span></label>
        <div class="error-msg"><?= _h('register.terms_err') ?></div>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="register-submit"><?= _h('register.submit') ?></button></div>
</form>
<p class="user-links"><?= _h('register.have') ?> <a href="<?= $baseUrl ?>?action=login"><?= _h('common.sign_in') ?></a>.</p>
<?php if ($regTermsText !== ''): ?>
<div class="files-overlay" id="terms-overlay" hidden>
    <div class="files-box" role="dialog" aria-modal="true" aria-labelledby="terms-title">
        <div class="files-head">
            <h3 id="terms-title"><?= _h('register.terms_title') ?></h3>
            <button type="button" class="files-close" id="terms-close" title="<?= _h('common.close') ?>">&times;</button>
        </div>
        <div class="files-body terms-body"><?= nl2br(sanitize($regTermsText)) ?></div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
