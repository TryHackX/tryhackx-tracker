<?php $meUser = currentUser($db); ?>
<?php if ($meUser === null): ?>
<h1><?= _h('account.h1') ?></h1>
<p><?= _h('account.not_signed') ?></p>
<p><a class="btn" href="<?= $baseUrl ?>?action=login"><?= _h('common.sign_in') ?></a></p>
<?php else: ?>
<?php
$accHasEmail = trim((string)($meUser['email'] ?? '')) !== '';
$accVerified = (int)$meUser['email_verified'] === 1;
$accRestricted = userEmailVerifyRequired($cfg) && !($accHasEmail && $accVerified) && !userIsAdminGroup($db, (int)$meUser['id']);
$accPending = userEmailChangeState($db, $meUser);
$accCooldownDays = userEmailChangeCooldownDays($cfg);
?>
<div class="account-head">
    <h1><?= __('account.h1_named', ['user' => sanitize($meUser['username'])]) ?></h1>
    <button type="button" class="btn btn-secondary" id="account-logout"><?= _h('common.sign_out') ?></button>
</div>
<input type="hidden" id="account-csrf" value="<?= $csrfToken ?>">
<?php if ($accRestricted): ?>
<div class="alert alert-error show"><?= __('account.restricted', [
    'hint' => __($accHasEmail ? 'account.restricted_has' : 'account.restricted_none'),
]) ?></div>
<?php endif; ?>
<?php if ($accPending !== null): ?>
<div class="alert alert-success show" id="acc-pending-box"><?= __('account.pending', [
        'email' => $accPending['pending_email'] === '' ? __('account.pending_removal') : sanitize($accPending['pending_email']),
        'stage' => __($accPending['stage'] === 'old' ? 'account.stage_current' : 'account.stage_new'),
    ]) ?>
    <button type="button" class="btn btn-secondary btn-small" id="acc-cancel-echange"><?= _h('account.cancel_change') ?></button></div>
<?php endif; ?>

<div class="account-grid">
    <div class="account-card">
        <h2><?= _h('account.profile') ?></h2>
        <table class="account-kv">
            <tr><td><?= _h('account.username') ?></td><td><?= sanitize($meUser['username']) ?></td></tr>
            <tr><td><?= _h('account.email') ?></td><td>
                <div class="acc-email-line">
                    <span id="acc-email" class="acc-email-addr" data-has-email="<?= $accHasEmail ? '1' : '0' ?>"><?= $accHasEmail ? sanitize($meUser['email']) : '<em>' . _h('common.none') . '</em>' ?></span>
                    <?php if ($accHasEmail): ?>
                    <span id="acc-email-badge" class="acc-badge <?= $accVerified ? 'acc-badge-ok' : 'acc-badge-warn' ?>"><?= _h($accVerified ? 'account.verified' : 'account.unverified') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($accHasEmail && !$accVerified): ?>
                <button type="button" class="btn btn-secondary btn-small acc-email-resend" id="acc-verify-send" title="<?= _h('account.resend_title') ?>"><?= _h('account.resend') ?></button>
                <?php endif; ?>
            </td></tr>
            <tr><td><?= _h('account.member_since') ?></td><td><?= sanitize((string)$meUser['created_at']) ?></td></tr>
            <tr><td><?= _h('account.last_login') ?></td><td><?= sanitize((string)($meUser['last_login_at'] ?? '—')) ?></td></tr>
        </table>
        <?php if ($accHasEmail && !$accVerified): ?>
        <p class="text-muted acc-verify-note"><?= _h('account.verify_note') ?></p>
        <?php endif; ?>
        <?php if ($accHasEmail): ?>
        <div class="acc-mail-prefs">
            <h3 class="acc-sub"><?= _h('account.mail_prefs') ?></h3>
            <label class="search-check acc-pref-row">
                <input type="checkbox" id="acc-mail-pref"><span class="search-check-box" aria-hidden="true"></span>
                <span class="acc-pref-text">
                    <span class="acc-pref-name"><?= _h('account.pref_account') ?> <span class="acc-pref-state" id="acc-mail-pref-label">&hellip;</span></span>
                    <span class="acc-pref-note"><?= _h('account.pref_account_note') ?></span>
                </span>
            </label>
            <label class="search-check acc-pref-row">
                <input type="checkbox" id="acc-bulk-pref"><span class="search-check-box" aria-hidden="true"></span>
                <span class="acc-pref-text">
                    <span class="acc-pref-name"><?= _h('account.pref_bulk') ?> <span class="acc-pref-state" id="acc-bulk-pref-label">&hellip;</span></span>
                    <span class="acc-pref-note"><?= _h('account.pref_bulk_note') ?></span>
                </span>
            </label>
        </div>
        <?php endif; ?>
    </div>
    <div class="account-card">
        <h2><?= _h('account.groups') ?></h2>
        <div id="acc-groups"><span class="text-muted"><?= _h('common.loading') ?></span></div>
    </div>
    <div class="account-card">
        <h2><?= _h('account.lang_head') ?></h2>
<?php
// Only when there is a choice. One language is not a preference, it is a fact about the site, and a
// select with a single option is a control that teaches people it does nothing.
$accLangs = function_exists('langForUsers') ? langForUsers($cfg) : [];
if (count($accLangs) > 1):
    $accLangNow = (string)($meUser['language'] ?? '');
?>
        <p class="text-muted acc-verify-note"><?= _h('account.lang_note') ?></p>
        <select id="acc-language" class="acc-language">
            <option value=""<?= $accLangNow === '' ? ' selected' : '' ?>><?= _h('account.lang_site') ?></option>
            <?php foreach ($accLangs as $accCode => $accName): ?>
            <option value="<?= sanitize($accCode) ?>"<?= $accLangNow === $accCode ? ' selected' : '' ?>><?= sanitize($accName) ?></option>
            <?php endforeach; ?>
        </select>
<?php else: ?>
        <p class="text-muted acc-verify-note"><?= sanitize(reset($accLangs) ?: 'English') ?></p>
<?php endif; ?>
    </div>
</div>

<h2 class="section-heading-spaced"><?= _h('account.notifications') ?> <span id="acc-unread-badge" class="acc-badge" hidden></span>
    <span class="acc-notif-tools">
        <button type="button" class="btn btn-secondary btn-small" id="acc-mark-all"><?= _h('account.mark_all') ?></button>
        <button type="button" class="btn btn-secondary btn-small" id="acc-delete-read" title="<?= _h('account.delete_read_title') ?>"><?= _h('account.delete_read') ?></button>
    </span></h2>
<div id="acc-notifications"><span class="text-muted"><?= _h('common.loading') ?></span></div>
<div class="trans-pagination acc-notif-pagination" id="acc-notif-pagination"></div>
<p class="text-muted acc-notif-note"><?= _h('account.notif_note') ?></p>

<h2 class="section-heading-spaced"><?= _h('account.change_head') ?></h2>
<div id="account-alert" class="alert"></div>
<form id="account-form" class="user-form" novalidate>
    <div class="form-group">
        <label for="acc-cur-pass"><?= _h('account.cur_pass') ?> <small class="form-hint"><?= _h('account.cur_pass_hint') ?></small></label>
        <input type="password" id="acc-cur-pass" autocomplete="current-password" maxlength="200" required>
    </div>
    <div class="form-group">
        <label for="acc-new-email"><?= _h('account.email') ?> <small class="form-hint"><?= _h('account.email_hint') ?><?=
        $accHasEmail ? __('account.email_hint_confirm')
            . ($accCooldownDays > 0 ? __('account.email_hint_cooldown', ['days' => $accCooldownDays]) : '') : '' ?></small></label>
        <input type="email" id="acc-new-email" maxlength="190" autocomplete="email" value="<?= sanitize((string)($meUser['email'] ?? '')) ?>">
        <div class="error-msg"><?= _h('account.email_err') ?></div>
    </div>
    <div class="form-group" id="acc-new-email2-group" hidden>
        <label for="acc-new-email2"><?= _h('account.email2') ?></label>
        <input type="email" id="acc-new-email2" maxlength="190" autocomplete="off">
        <div class="error-msg"><?= _h('account.email2_err') ?></div>
    </div>
    <div class="form-group">
        <label for="acc-new-pass"><?= _h('account.new_pass') ?> <small class="form-hint"><?= _h('account.new_pass_hint') ?></small></label>
        <input type="password" id="acc-new-pass" maxlength="200" autocomplete="new-password">
        <div class="pw-checklist" id="acc-pw-checklist" hidden></div>
        <div class="error-msg"><?= _h('account.new_pass_err') ?></div>
    </div>
    <div class="form-group" id="acc-new-pass2-group" hidden>
        <label for="acc-new-pass2"><?= _h('account.new_pass2') ?></label>
        <input type="password" id="acc-new-pass2" maxlength="200" autocomplete="new-password">
        <div class="error-msg"><?= _h('account.new_pass2_err') ?></div>
    </div>
    <div class="form-center"><button type="submit" class="btn" id="account-save"><?= _h('account.save') ?></button></div>
</form>
<?php endif; ?>
