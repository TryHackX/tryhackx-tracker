<?php $meUser = currentUser($db); ?>
<?php if ($meUser === null): ?>
<h1>Account</h1>
<p>You are not signed in.</p>
<p><a class="btn" href="<?= $baseUrl ?>?action=login">Sign in</a></p>
<?php else: ?>
<?php $accHasEmail = trim((string)($meUser['email'] ?? '')) !== ''; $accVerified = (int)$meUser['email_verified'] === 1; ?>
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
            <tr><td>Email</td><td><span id="acc-email"><?= $accHasEmail ? sanitize($meUser['email']) : '<em>none</em>' ?></span>
                <?php if ($accHasEmail): ?>
                <span id="acc-email-badge" class="acc-badge <?= $accVerified ? 'acc-badge-ok' : 'acc-badge-warn' ?>"><?= $accVerified ? 'verified' : 'unverified' ?></span>
                <?php if (!$accVerified): ?><button type="button" class="btn btn-secondary btn-small" id="acc-verify-send" title="Send the confirmation link again">Resend link</button><?php endif; ?>
                <?php endif; ?>
            </td></tr>
            <tr><td>Member since</td><td><?= sanitize((string)$meUser['created_at']) ?></td></tr>
            <tr><td>Last sign-in</td><td><?= sanitize((string)($meUser['last_login_at'] ?? '—')) ?></td></tr>
        </table>
        <?php if ($accHasEmail && !$accVerified): ?>
        <p class="text-muted acc-verify-note">A confirmation link was sent to this address — open it to verify. Unverified addresses still receive password resets.</p>
        <?php endif; ?>
    </div>
    <div class="account-card">
        <h2>Your groups</h2>
        <div id="acc-groups"><span class="text-muted">Loading&hellip;</span></div>
    </div>
</div>

<h2 class="section-heading-spaced">Notifications <span id="acc-unread-badge" class="acc-badge" hidden></span>
    <span class="acc-notif-tools">
        <button type="button" class="btn btn-secondary btn-small" id="acc-mark-all">Mark all read</button>
        <button type="button" class="btn btn-secondary btn-small" id="acc-delete-read" title="Remove every notification you have already read">Delete read</button>
    </span></h2>
<div id="acc-notifications"><span class="text-muted">Loading&hellip;</span></div>
<div class="trans-pagination acc-notif-pagination" id="acc-notif-pagination"></div>
<p class="text-muted acc-notif-note">Read notifications are removed automatically after 90 days (365 days for unread ones).</p>

<h2 class="section-heading-spaced">Change email / password</h2>
<div id="account-alert" class="alert"></div>
<form id="account-form" class="user-form" novalidate>
    <div class="form-group">
        <label for="acc-cur-pass">Current password <small class="form-hint">required for any change</small></label>
        <input type="password" id="acc-cur-pass" autocomplete="current-password" maxlength="200" required>
    </div>
    <div class="form-group">
        <label for="acc-new-email">Email <small class="form-hint">edit to change &middot; clear the box to remove your address</small></label>
        <input type="email" id="acc-new-email" maxlength="190" autocomplete="email" value="<?= sanitize((string)($meUser['email'] ?? '')) ?>">
        <div class="error-msg">That email address does not look valid</div>
    </div>
    <div class="form-group">
        <label for="acc-new-pass">New password <small class="form-hint">leave empty to keep the current one; at least 8 characters</small></label>
        <input type="password" id="acc-new-pass" maxlength="200" autocomplete="new-password">
        <div class="error-msg">At least 8 characters</div>
    </div>
    <div class="form-group" id="acc-new-pass2-group" hidden>
        <label for="acc-new-pass2">Repeat new password</label>
        <input type="password" id="acc-new-pass2" maxlength="200" autocomplete="new-password">
        <div class="error-msg">Passwords do not match</div>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="account-save">Save changes</button></div>
</form>
<?php endif; ?>
