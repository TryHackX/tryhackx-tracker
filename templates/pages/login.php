<?php $meUser = currentUser($db); ?>
<h1>Sign in</h1>

<?php if ($meUser !== null): ?>
<p>You are signed in as <strong><?= sanitize($meUser['username']) ?></strong>.</p>
<p><a class="btn" href="<?= $baseUrl ?>?action=account">Go to your account</a></p>
<?php else: ?>
<div class="user-card">
    <div id="login-alert" class="alert"></div>
    <form id="login-form" class="user-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <div class="form-group">
            <label for="login-login">Username or email</label>
            <input type="text" id="login-login" name="login" maxlength="190" autocomplete="username" required>
            <div class="error-msg">Enter your username or email address</div>
        </div>
        <div class="form-group">
            <label for="login-password">Password</label>
            <input type="password" id="login-password" name="password" maxlength="200" autocomplete="current-password" required>
            <div class="error-msg">Enter your password</div>
        </div>
        <div class="form-group">
            <label for="login-session">Stay signed in for</label>
            <select id="login-session" name="session">
                <?php foreach (userSessionChoices() as $code => [$ttl, $label]): ?>
                <option value="<?= $code ?>"<?= $code === 'forever' ? ' selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-center"><button type="submit" class="btn" id="login-submit">Sign in</button></div>
    </form>
    <p class="user-links">
        <?php if (usersRegistrationEnabled($cfg)): ?>No account yet? <a href="<?= $baseUrl ?>?action=register">Register</a> — it is free.<br><?php endif; ?>
        Forgot your password? <a href="<?= $baseUrl ?>?action=reset">Reset it</a>.
    </p>
</div>
<?php endif; ?>
