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
$accFav = favContext($db, $cfg, $meUser);
// The uploads tab is hidden ENTIRELY, not shown empty, where a submission cannot happen — a tab that
// can only ever be empty teaches people the feature is broken rather than absent.
$accShowUploads = $accFav['uploads'] && uploadsPublicEnabled($cfg);
$accTabs = $accFav['may_use'] || $accShowUploads;
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

<?php if ($accTabs): ?>
<?php /* Tabs by location.hash, not by ?query — nothing in this codebase reads a query parameter on
         this page, and a hash costs no request. Visually the .rt-tabs pattern the rich-text editor
         already uses, so the page does not grow a second idea of what a tab looks like. */ ?>
<div class="rt-tabs acc-tabs" id="acc-tabs" role="tablist">
    <button type="button" class="rt-tab active" data-pane="overview"><?= _h('account.tab_overview') ?></button>
    <?php if ($accFav['may_use']): ?>
    <button type="button" class="rt-tab" data-pane="favourites"><?= _h('account.tab_favourites') ?></button>
    <?php endif; ?>
    <?php if ($accShowUploads): ?>
    <button type="button" class="rt-tab" data-pane="uploads"><?= _h('account.tab_uploads') ?></button>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="acc-pane" id="acc-pane-overview">
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
<?php
// The interface language lives here, under the groups, as a sub-section — not as a third card. A
// card for one <select> read as a feature and sat mostly empty. Same sub-section style the Profile
// card uses for the mail preferences, so the two cards look like each other.
//
// Only when there is a choice. One language is not a preference, it is a fact about the site, and a
// select with a single option is a control that teaches people it does nothing.
$accLangs = function_exists('langForUsers') ? langForUsers($cfg) : [];
if (count($accLangs) > 1):
    $accLangNow = (string)($meUser['language'] ?? '');
?>
        <div class="acc-mail-prefs acc-lang-block">
            <h3 class="acc-sub"><?= _h('account.lang_head') ?></h3>
            <p class="text-muted acc-verify-note acc-lang-note"><?= _h('account.lang_note') ?></p>
            <select id="acc-language" class="acc-language">
                <option value=""<?= $accLangNow === '' ? ' selected' : '' ?>><?= _h('account.lang_site') ?></option>
                <?php foreach ($accLangs as $accCode => $accName): ?>
                <option value="<?= sanitize($accCode) ?>"<?= $accLangNow === $accCode ? ' selected' : '' ?>><?= sanitize($accName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
<?php endif; ?>
<?php if ($accFav['may_use'] && ($accFav['public_ok'] || $accFav['who_ok'])): ?>
        <?php /* In the card that already holds the mail and language preferences, not a card of its
                 own: these are two more answers about the same account, and a separate card would
                 make them look like a separate subject. */ ?>
        <div class="acc-mail-prefs acc-privacy-block" id="acc-privacy">
            <h3 class="acc-sub"><?= _h('account.fav_privacy') ?></h3>
            <?php if ($accFav['may_publish']): ?>
            <label class="acc-check"><input type="checkbox" id="acc-fav-public"<?= (int)($meUser['fav_public'] ?? 0) === 1 ? ' checked' : '' ?>>
                <span><?= _h('account.fav_public_label') ?></span></label>
            <p class="text-muted acc-verify-note"><?= __('account.fav_public_hint', ['name' => sanitize($meUser['username'])]) ?></p>
            <?php endif; ?>
            <?php if ($accFav['who_ok']): ?>
            <label class="acc-check"><input type="checkbox" id="acc-fav-listed"<?= (int)($meUser['fav_listed'] ?? 0) === 1 ? ' checked' : '' ?>>
                <span><?= _h('account.fav_listed_label') ?></span></label>
            <p class="text-muted acc-verify-note"><?= __('account.fav_listed_hint') ?></p>
            <?php endif; ?>
        </div>
<?php endif; ?>
<?php
// WHERE THIS ACCOUNT SIGNS IN FROM. Shown to the person holding it, not only to the operator: an
// account that a forum created on somebody's behalf should say so to them, and the sentence about
// "your own password" is the answer to "what happens if the forum goes away".
$accBridgeOn = function_exists('authBridgeEnabled') && authBridgeEnabled($cfg);
$accIdents = $accBridgeOn ? authIdentitiesForUser($db, (int)$meUser['id']) : [];
$accBridgeOut = $accBridgeOn ? authBridgeReturnUrl($cfg) : '';
?>
<?php if ($accBridgeOn): ?>
        <div class="acc-mail-prefs acc-bridge-block" id="acc-bridge">
            <h3 class="acc-sub"><?= _h('bridge.linked_heading') ?></h3>
            <?php if ($accIdents): ?>
            <?php foreach ($accIdents as $accId): ?>
            <p class="acc-bridge-line">
                <?= _h('bridge.linked_via', ['name' => (string)($accId['provider'] ?? '')]) ?>
                <?php if (!empty($accId['external_name'])): ?>
                <span class="text-muted"><?= _h('bridge.linked_as', ['name' => (string)$accId['external_name']]) ?></span>
                <?php endif; ?>
                <span class="text-muted"><?= _h('bridge.linked_since', ['date' => (string)($accId['created_at'] ?? '')]) ?></span>
            </p>
            <?php endforeach; ?>
            <?php if ($accBridgeOut !== ''): ?>
            <p class="acc-bridge-go">
                <a class="btn btn-secondary btn-small" href="<?= $baseUrl ?>?action=bridge_out" rel="nofollow"><?= _h('bridge.continue_to', ['name' => authBridgeProviderName($db, $cfg)]) ?></a>
                <span class="text-muted acc-verify-note"><?= _h('bridge.continue_hint') ?></span>
            </p>
            <?php endif; ?>
            <?php else: ?>
            <p class="text-muted acc-verify-note"><?= _h('bridge.not_linked') ?></p>
            <?php endif; ?>
        </div>
<?php endif; ?>
    </div>
</div>

</div><?php /* /#acc-pane-overview */ ?>

<?php if ($accFav['may_use']): ?>
<div class="acc-pane" id="acc-pane-favourites" hidden>
    <h2 class="section-heading-spaced"><?= _h('account.fav_heading') ?></h2>
    <div id="account-fav" class="profile-section"
         data-magnet="<?= userCan($db, $cfg, 'index.magnet') ? '1' : '0' ?>"
         data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>"
         data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>"
         data-empty-text="<?= _h('account.fav_none') ?>">
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="af-search" maxlength="120" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
            <select id="af-sort" title="<?= _h('profile.sort') ?>">
                <option value="added:desc"><?= _h('profile.sort_added') ?></option>
                <option value="name:asc"><?= _h('profile.sort_name') ?></option>
                <option value="size:desc"><?= _h('profile.sort_size') ?></option>
                <option value="seeders:desc"><?= _h('profile.sort_seeders') ?></option>
            </select>
            <span class="profile-total" id="af-total"></span>
        </div>
        <div class="profile-list" id="af-list"></div>
        <div class="trans-pagination" id="af-pager"></div>
    </div>
</div>
<?php endif; ?>

<?php if ($accShowUploads): ?>
<div class="acc-pane" id="acc-pane-uploads" hidden>
    <h2 class="section-heading-spaced"><?= _h('account.tab_uploads') ?></h2>
    <div id="account-uploads" class="profile-section"
         data-magnet="<?= userCan($db, $cfg, 'index.magnet') ? '1' : '0' ?>"
         data-may-publish="<?= $accFav['uploads_pub'] ? '1' : '0' ?>"
         data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>"
         data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>"
         data-empty-text="<?= _h('account.uploads_none') ?>">
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="au-search" maxlength="120" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
            <select id="au-status" title="<?= _h('profile.status') ?>">
                <option value=""><?= _h('profile.status_any') ?></option>
                <option value="live"><?= _h('profile.status_live') ?></option>
                <option value="waiting"><?= _h('profile.status_waiting') ?></option>
                <option value="refused"><?= _h('profile.status_refused') ?></option>
                <option value="blocked"><?= _h('profile.status_blocked') ?></option>
            </select>
            <select id="au-sort" title="<?= _h('profile.sort') ?>">
                <option value="added:desc"><?= _h('profile.sort_added') ?></option>
                <option value="name:asc"><?= _h('profile.sort_name') ?></option>
                <option value="size:desc"><?= _h('profile.sort_size') ?></option>
                <option value="seeders:desc"><?= _h('profile.sort_seeders') ?></option>
            </select>
            <span class="profile-total" id="au-total"></span>
        </div>
        <div class="profile-list" id="au-list"></div>
        <div class="trans-pagination" id="au-pager"></div>
    </div>
</div>
<?php endif; ?>

<div class="acc-pane" id="acc-pane-rest">
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
</div><?php /* /#acc-pane-rest */ ?>
<?php endif; ?>
