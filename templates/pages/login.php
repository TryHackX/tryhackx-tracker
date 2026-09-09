<?php $meUser = currentUser($db); ?>
<h1><?= _h('login.h1') ?></h1>

<?php if ($meUser !== null): ?>
<p><?= __('login.already', ['user' => sanitize($meUser['username'])]) ?></p>
<p><a class="btn" href="<?= $baseUrl ?>?action=account"><?= _h('common.go_account') ?></a></p>
<?php else: ?>
<?php
// The forum's own sign-in page, when the operator configured one. It is a plain link, not a form:
// everything that follows happens over there and comes back as a one-time ticket.
$bridgeLogin = function_exists('authBridgeEnabled') && authBridgeEnabled($cfg) ? authBridgeLoginUrl($cfg) : '';
$bridgeName = $bridgeLogin !== '' ? authBridgeProviderName($db, $cfg) : '';
?>
<div class="user-card">
    <?php /* A ticket that expired or was spent lands here. "Try again" is not useful advice — the
             person cannot mint a new one from this side — so the sentence says who can. */ ?>
    <?php if (($_GET['bridge'] ?? '') === 'failed'): ?>
    <div class="alert alert-warning show"><?= _h('bridge.failed') ?></div>
    <?php endif; ?>
    <div id="login-alert" class="alert"></div>
    <?php if ($bridgeLogin !== ''): ?>
    <div class="bridge-signin">
        <a class="btn bridge-btn" href="<?= sanitize($bridgeLogin) ?>" rel="nofollow noopener"><?= _h('bridge.sign_in_with', ['name' => $bridgeName]) ?></a>
        <div class="bridge-or"><?= _h('bridge.or') ?></div>
    </div>
    <?php endif; ?>
    <?php /* Solve the CAPTCHA BEFORE the first POST when the server already knows it will ask.
             The handshake — post, get 400 with captcha_required, solve, post again — still exists
             and still covers the case where the requirement appears between this render and the
             submit. What it should not do is happen on every ordinary sign-in: the wasted request
             is rejected, so the browser prints a red "400 (Bad Request)" in the console of a login
             that worked perfectly. */ ?>
    <form id="login-form" class="user-form" novalidate data-captcha-first="<?= isCaptchaRequired($cfg, 'login') ? '1' : '0' ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <div class="form-group">
            <label for="login-login"><?= _h('login.login_label') ?></label>
            <input type="text" id="login-login" name="login" maxlength="190" autocomplete="username" required>
            <div class="error-msg"><?= _h('login.login_err') ?></div>
        </div>
        <div class="form-group">
            <label for="login-password"><?= _h('login.password') ?></label>
            <input type="password" id="login-password" name="password" maxlength="200" autocomplete="current-password" required>
            <div class="error-msg"><?= _h('login.password_err') ?></div>
        </div>
        <div class="form-group">
            <label for="login-session"><?= _h('login.stay') ?></label>
            <select id="login-session" name="session">
                <?php foreach (userSessionChoices() as $code => [$ttl, $label]): ?>
                <option value="<?= $code ?>"<?= $code === 'forever' ? ' selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-center"><button type="submit" class="btn" id="login-submit"><?= _h('common.sign_in') ?></button></div>
    </form>
    <p class="user-links">
        <?php if (usersRegistrationEnabled($cfg)): ?><?= _h('login.no_account') ?> <a href="<?= $baseUrl ?>?action=register"><?= _h('common.register') ?></a> <?= _h('login.free') ?><br><?php endif; ?>
        <?= _h('login.forgot') ?> <a href="<?= $baseUrl ?>?action=reset"><?= _h('login.reset_it') ?></a>.
    </p>
</div>
<?php endif; ?>
