<?php $meUser = currentUser($db); ?>
<h1><?= _h('login.h1') ?></h1>

<?php if ($meUser !== null): ?>
<p><?= __('login.already', ['user' => sanitize($meUser['username'])]) ?></p>
<p><a class="btn" href="<?= $baseUrl ?>?action=account"><?= _h('common.go_account') ?></a></p>
<?php else: ?>
<div class="user-card">
    <div id="login-alert" class="alert"></div>
    <form id="login-form" class="user-form" novalidate>
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
