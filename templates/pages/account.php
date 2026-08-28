<?php $meUser = currentUser($db); ?>
<?php if ($meUser === null): ?>
<h1>Account</h1>
<p>You are not signed in.</p>
<p><a class="btn" href="<?= $baseUrl ?>?action=login">Sign in</a></p>
<?php else: ?>
<?php
$accHasEmail = trim((string)($meUser['email'] ?? '')) !== '';
$accVerified = (int)$meUser['email_verified'] === 1;
$accRestricted = userEmailVerifyRequired($cfg) && !($accHasEmail && $accVerified) && !userIsAdminGroup($db, (int)$meUser['id']);
$accPending = userEmailChangeState($db, $meUser);
$accCooldownDays = userEmailChangeCooldownDays($cfg);
?>
<div class="account-head">
    <h1>Account &mdash; <?= sanitize($meUser['username']) ?></h1>
    <button type="button" class="btn btn-secondary" id="account-logout">Sign out</button>
</div>
<input type="hidden" id="account-csrf" value="<?= $csrfToken ?>">
<?php if ($accRestricted): ?>
<div class="alert alert-error show">Your email address is <strong>not verified</strong> — until you open the confirmation link, this account works at <strong>guest level</strong> (group permissions are paused).<?= $accHasEmail ? ' Check your inbox or use “Resend link” below.' : ' Add an email address below to receive the link.' ?></div>
<?php endif; ?>
<?php if ($accPending !== null): ?>
<div class="alert alert-success show" id="acc-pending-box">Email change to <strong><?= $accPending['pending_email'] === '' ? '(removal)' : sanitize($accPending['pending_email']) ?></strong> is waiting for confirmation from the <strong><?= $accPending['stage'] === 'old' ? 'current' : 'new' ?></strong> address.
    <button type="button" class="btn btn-secondary btn-small" id="acc-cancel-echange">Cancel the change</button></div>
<?php endif; ?>

<div class="account-grid">
    <div class="account-card">
        <h2>Profile</h2>
        <table class="account-kv">
            <tr><td>Username</td><td><?= sanitize($meUser['username']) ?></td></tr>
            <tr><td>Email</td><td>
                <div class="acc-email-line">
                    <span id="acc-email" class="acc-email-addr"><?= $accHasEmail ? sanitize($meUser['email']) : '<em>none</em>' ?></span>
                    <?php if ($accHasEmail): ?>
                    <span id="acc-email-badge" class="acc-badge <?= $accVerified ? 'acc-badge-ok' : 'acc-badge-warn' ?>"><?= $accVerified ? 'verified' : 'unverified' ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($accHasEmail && !$accVerified): ?>
                <button type="button" class="btn btn-secondary btn-small acc-email-resend" id="acc-verify-send" title="Send the confirmation link again">Resend link</button>
                <?php endif; ?>
            </td></tr>
            <tr><td>Member since</td><td><?= sanitize((string)$meUser['created_at']) ?></td></tr>
            <tr><td>Last sign-in</td><td><?= sanitize((string)($meUser['last_login_at'] ?? '—')) ?></td></tr>
        </table>
        <?php if ($accHasEmail && !$accVerified): ?>
        <p class="text-muted acc-verify-note">A confirmation link was sent to this address — open it to verify. Unverified addresses still receive password resets.</p>
        <?php endif; ?>
        <?php if ($accHasEmail): ?>
        <div class="acc-mail-prefs">
            <h3 class="acc-sub">What we may send you</h3>
            <label class="search-check acc-pref-row">
                <input type="checkbox" id="acc-mail-pref"><span class="search-check-box" aria-hidden="true"></span>
                <span class="acc-pref-text">
                    <span class="acc-pref-name">Account mail <span class="acc-pref-state" id="acc-mail-pref-label">&hellip;</span></span>
                    <span class="acc-pref-note">Expiry warnings, security notices and anything else about this account.</span>
                </span>
            </label>
            <label class="search-check acc-pref-row">
                <input type="checkbox" id="acc-bulk-pref"><span class="search-check-box" aria-hidden="true"></span>
                <span class="acc-pref-text">
                    <span class="acc-pref-name">Announcements <span class="acc-pref-state" id="acc-bulk-pref-label">&hellip;</span></span>
                    <span class="acc-pref-note">Occasional messages sent to everyone. Turning this off stops those only &mdash; password resets and security notices still reach you.</span>
                </span>
            </label>
        </div>
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
        <label for="acc-new-email">Email <small class="form-hint">edit to change &middot; clear the box to remove your address<?= $accHasEmail ? ' &middot; a change is confirmed from the current address first, then from the new one' . ($accCooldownDays > 0 ? '; next change possible ' . $accCooldownDays . ' days after the previous one' : '') : '' ?></small></label>
        <input type="email" id="acc-new-email" maxlength="190" autocomplete="email" value="<?= sanitize((string)($meUser['email'] ?? '')) ?>">
        <div class="error-msg">That email address does not look valid</div>
    </div>
    <div class="form-group" id="acc-new-email2-group" hidden>
        <label for="acc-new-email2">Repeat new email</label>
        <input type="email" id="acc-new-email2" maxlength="190" autocomplete="off">
        <div class="error-msg">Email addresses do not match</div>
    </div>
    <div class="form-group">
        <label for="acc-new-pass">New password <small class="form-hint">leave empty to keep the current one</small></label>
        <input type="password" id="acc-new-pass" maxlength="200" autocomplete="new-password">
        <div class="pw-checklist" id="acc-pw-checklist" hidden></div>
        <div class="error-msg">The password does not meet the requirements above</div>
    </div>
    <div class="form-group" id="acc-new-pass2-group" hidden>
        <label for="acc-new-pass2">Repeat new password</label>
        <input type="password" id="acc-new-pass2" maxlength="200" autocomplete="new-password">
        <div class="error-msg">Passwords do not match</div>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="account-save">Save changes</button></div>
</form>
<?php endif; ?>
