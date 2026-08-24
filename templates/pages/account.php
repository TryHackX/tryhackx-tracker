<?php $meUser = currentUser($db); ?>
<?php if ($meUser === null): ?>
<h1>Account</h1>
<p>You are not signed in.</p>
<p><a class="btn" href="<?= $baseUrl ?>?action=login">Sign in</a></p>
<?php else: ?>
<div class="account-head">
    <h1>Account &mdash; <?= sanitize($meUser['username']) ?></h1>
    <button type="button" class="btn btn-secondary" id="account-logout">Sign out</button>
</div>
<input type="hidden" id="account-csrf" value="<?= $csrfToken ?>">

<div class="account-grid">
    <div class="account-card">
        <h2>Profile</h2>
        <table class="account-kv">
            <tr><td>Username</td><td><?= sanitize($meUser['username']) ?></td></tr>
            <tr><td>Email</td><td id="acc-email"><?= $meUser['email'] !== null ? sanitize($meUser['email']) : '<em>none</em>' ?></td></tr>
            <tr><td>Member since</td><td><?= sanitize((string)$meUser['created_at']) ?></td></tr>
            <tr><td>Last sign-in</td><td><?= sanitize((string)($meUser['last_login_at'] ?? '—')) ?></td></tr>
        </table>
    </div>
    <div class="account-card">
        <h2>Your groups</h2>
        <div id="acc-groups"><span class="text-muted">Loading&hellip;</span></div>
    </div>
</div>

<h2 class="section-heading-spaced">Notifications <span id="acc-unread-badge" class="acc-badge" hidden></span>
    <button type="button" class="btn btn-secondary btn-small" id="acc-mark-all">Mark all read</button></h2>
<div id="acc-notifications"><span class="text-muted">Loading&hellip;</span></div>

<h2 class="section-heading-spaced">Change email / password</h2>
<div id="account-alert" class="alert"></div>
<form id="account-form" class="user-form" novalidate>
    <div class="form-group">
        <label for="acc-cur-pass">Current password <small class="form-hint">required for any change</small></label>
        <input type="password" id="acc-cur-pass" autocomplete="current-password" maxlength="200" required>
    </div>
    <div class="form-group">
        <label for="acc-new-email">New email <small class="form-hint">leave unchanged to keep; empty box + &ldquo;remove email&rdquo; clears it</small></label>
        <input type="email" id="acc-new-email" maxlength="190" autocomplete="email" value="<?= sanitize((string)($meUser['email'] ?? '')) ?>">
        <label class="user-remember"><input type="checkbox" id="acc-remove-email"> Remove my email address</label>
    </div>
    <div class="form-group">
        <label for="acc-new-pass">New password <small class="form-hint">leave empty to keep the current one</small></label>
        <input type="password" id="acc-new-pass" maxlength="200" autocomplete="new-password">
    </div>
    <div class="form-center"><button type="submit" class="btn" id="account-save">Save changes</button></div>
</form>
<?php endif; ?>
