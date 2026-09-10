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
$accLists = listsContext($db, $cfg, $meUser);
$accPeople = peopleContext($db, $cfg, $meUser);
// Whether this reader may search inside file names — the same permission the search page asks, and
// the endpoints ask it again themselves.
$mayFileSearch = userCan($db, $cfg, 'index.files');
// Account security: the second factor (when the operator has switched the feature on) and where this
// account is signed in. Both are about the account itself rather than about what it publishes, so
// they sit in the Profile card under their own heading and not in the privacy block.
$accTwofa = ['feature' => user2faFeatureEnabled($cfg), 'enabled' => false, 'required' => false, 'why' => null, 'left' => 0];
if ($accTwofa['feature']) {
    $accNeed = user2faRequiredFor($db, $cfg, $meUser);
    $accTwofa['enabled']  = user2faEnabled($db, (int)$meUser['id']);
    $accTwofa['required'] = (bool)$accNeed['required'];
    $accTwofa['why']      = $accNeed['why'];
    $accTwofa['left']     = user2faRecoveryLeft($db, (int)$meUser['id']);
}
// The uploads tab is hidden ENTIRELY, not shown empty, where a submission cannot happen — a tab that
// can only ever be empty teaches people the feature is broken rather than absent.
$accShowUploads = $accFav['uploads'] && uploadsPublicEnabled($cfg);
$accTabs = $accFav['may_use'] || $accShowUploads || $accLists['may_use']
        || $accPeople['may_message'] || $accPeople['may_friend'];
?>
<div class="account-head">
    <h1><?= __('account.h1_named', ['user' => sanitize($meUser['username'])]) ?></h1>
    <?php /* The two things somebody does with their own profile, where they can see them: at the top
             of the page, beside the one button that was already there. Down in Privacy they were a
             quiet grey pair under a paragraph, and the first person to look for them missed them. */ ?>
    <div class="account-head-acts">
        <?php if (($cfg['search_share_enabled'] ?? '1') === '1'): ?>
        <button type="button" class="btn btn-secondary js-profile-share" data-user="<?= sanitize((string)$meUser['username']) ?>"
                title="<?= _h('profile.share_title') ?>"><?= _h('search.share') ?></button>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= $baseUrl ?>?action=u&amp;name=<?= urlencode((string)$meUser['username']) ?>"><?= _h('account.open_profile') ?></a>
        <button type="button" class="btn btn-secondary" id="account-logout"><?= _h('common.sign_out') ?></button>
    </div>
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
    <?php if ($accLists['may_use']): ?>
    <button type="button" class="rt-tab" data-pane="lists"><?= _h('account.tab_lists') ?></button>
    <?php endif; ?>
    <?php if ($accPeople['may_message']): ?>
    <button type="button" class="rt-tab" data-pane="messages"><?= _h('account.tab_messages') ?>
        <span class="pm-unread-badge" id="pm-unread"<?= $accPeople['unread'] ? '' : ' hidden' ?>><?= (int)$accPeople['unread'] ?></span></button>
    <?php endif; ?>
    <?php if ($accPeople['may_friend']): ?>
    <button type="button" class="rt-tab" data-pane="people"><?= _h('account.tab_people') ?>
        <?php /* Somebody waiting for an answer is the one thing on this page that is about to go
                 stale, so it is the one that carries a number. */ ?>
        <span class="pm-unread-badge" id="pe-incoming" hidden></span></button>
    <?php endif; ?>
    <?php if ($accPeople['may_directory']): ?>
    <button type="button" class="rt-tab" data-pane="members"><?= _h('people.dir_h1') ?></button>
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

        <?php /* ── Account security ──────────────────────────────────────────────────────────────
                 The second factor and the list of signed-in devices, in the card that already holds
                 the mail and language preferences and in the row shape those use. Both answer the
                 same question — "who else can be me?" — and neither is about what this account
                 publishes, which is what the privacy block below is for. */ ?>
        <div class="acc-mail-prefs acc-security-block" id="acc-security">
            <h3 class="acc-sub"><?= _h('account.security') ?></h3>
            <?php if ($accTwofa['feature']): ?>
            <label class="search-check acc-pref-row">
                <input type="checkbox" id="acc-2fa"<?= $accTwofa['enabled'] ? ' checked' : '' ?>><span class="search-check-box" aria-hidden="true"></span>
                <span class="acc-pref-text">
                    <span class="acc-pref-name"><?= _h('account.twofa') ?> <span class="acc-pref-state" id="acc-2fa-state"><?= _h($accTwofa['enabled'] ? 'account.twofa_on' : 'account.twofa_off') ?></span></span>
                    <span class="acc-pref-note"><?= _h('account.twofa_note') ?></span>
                </span>
            </label>
            <?php if ($accTwofa['required'] && !$accTwofa['enabled']): ?>
            <?php /* Not a scolding: it says what is actually withheld, which for 'panel' is the panel
                     and nothing else — the account itself keeps working. */ ?>
            <p class="acc-perm-warn"><?= __('account.twofa_required_' . $accTwofa['why']) ?></p>
            <?php endif; ?>
            <?php if ($accTwofa['enabled'] && $accTwofa['left'] > 0): ?>
            <p class="text-muted acc-pref-note" id="acc-2fa-left"><?= __('account.twofa_left', ['n' => (int)$accTwofa['left']]) ?>
                <button type="button" class="btn btn-secondary btn-small" id="acc-2fa-newcodes"><?= _h('account.twofa_newcodes') ?></button></p>
            <?php endif; ?>
            <?php if (userIdHasPermission($db, $cfg, (int)$meUser['id'], 'panel.access')): ?>
            <?php /* Somebody who can open the panel has TWO of these, and they are not the same one.
                     Said here rather than left to be discovered when the panel asks for a code that
                     this page never issued. */ ?>
            <p class="text-muted acc-pref-note"><?= __('account.twofa_panel_note') ?></p>
            <?php endif; ?>
            <div class="acc-2fa-box" id="acc-2fa-box" hidden></div>
            <?php endif; ?>

            <div class="acc-sessions" id="acc-sessions">
                <p class="acc-sessions-head" id="acc-sessions-head"><?= _h('common.loading') ?></p>
                <div class="acc-sessions-list" id="acc-sessions-list"></div>
                <div class="acc-sessions-act" id="acc-sessions-act" hidden>
                    <input type="password" class="profile-search" id="acc-sessions-pass" autocomplete="current-password" placeholder="<?= _h('account.cur_pass') ?>">
                    <button type="button" class="btn btn-secondary btn-small" id="acc-sessions-go"><?= _h('account.sessions_clear') ?></button>
                    <span class="text-muted" id="acc-sessions-msg"></span>
                </div>
                <p class="text-muted acc-pref-note"><?= __('account.sessions_note') ?></p>
            </div>
        </div>
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
<?php /* The privacy block belongs to whichever of these features is on: favourites, lists, or both.
         Gating it on favourites alone hid the lists switch on an install that runs lists without
         them. */ ?>
<?php if (($accFav['may_use'] && ($accFav['public_ok'] || $accFav['who_ok']))
          || ($accLists['may_use'] && $accLists['public_ok'])
          || $accPeople['pm'] || $accPeople['directory']): ?>
        <?php /* In the card that already holds the mail and language preferences, not a card of its
                 own: these are two more answers about the same account, and a separate card would
                 make them look like a separate subject. */ ?>
        <div class="acc-mail-prefs acc-privacy-block" id="acc-privacy">
            <h3 class="acc-sub"><?= _h('account.fav_privacy') ?></h3>
            <?php if ($accFav['may_use'] && ($accFav['may_publish'] || $accFav['publish_blocked'])): ?>
            <label class="search-check acc-check"><input type="checkbox" id="acc-fav-public"<?= (int)($meUser['fav_public'] ?? 0) === 1 ? ' checked' : '' ?>><span class="search-check-box" aria-hidden="true"></span>
                <span><?= _h('account.fav_public_label') ?></span></label>
            <p class="text-muted acc-verify-note"><?= __('account.fav_public_hint', ['name' => sanitize($meUser['username'])]) ?></p>
            <?php if ($accFav['publish_blocked']): ?>
            <p class="acc-perm-warn"><?= __('account.needs_grant', ['perm' => 'favourites.public']) ?></p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($accLists['may_use'] && ($accLists['may_publish'] || $accLists['publish_blocked'])): ?>
            <label class="search-check acc-check"><input type="checkbox" id="acc-lists-public"<?= (int)($meUser['lists_public'] ?? 0) === 1 ? ' checked' : '' ?>><span class="search-check-box" aria-hidden="true"></span>
                <span><?= _h('account.lists_public_label') ?></span></label>
            <p class="text-muted acc-verify-note"><?= __('account.lists_public_hint') ?></p>
            <?php if ($accLists['publish_blocked']): ?>
            <p class="acc-perm-warn"><?= __('account.needs_grant', ['perm' => 'lists.public']) ?></p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($accPeople['directory']): ?>
            <label class="search-check acc-check"><input type="checkbox" id="acc-profile-listed"<?= (int)($meUser['profile_listed'] ?? 0) === 1 ? ' checked' : '' ?>><span class="search-check-box" aria-hidden="true"></span>
                <span><?= _h('account.profile_listed_label') ?></span></label>
            <p class="text-muted acc-verify-note"><?= __('account.profile_listed_hint') ?></p>
            <?php endif; ?>
            <?php if ($accPeople['pm']): ?>
            <div class="acc-pm-who">
                <label class="cl-label" for="acc-pm-who"><?= _h('account.pm_who_label') ?></label>
                <select id="acc-pm-who" class="profile-search acc-pm-select">
                    <option value=""<?= ($meUser['pm_who'] ?? null) === null ? ' selected' : '' ?>><?= __('account.pm_who_default', ['what' => _h('account.pm_who_' . $accPeople['who_default'])]) ?></option>
                    <option value="all"<?= ($meUser['pm_who'] ?? null) === 'all' ? ' selected' : '' ?>><?= _h('account.pm_who_all') ?></option>
                    <option value="friends"<?= ($meUser['pm_who'] ?? null) === 'friends' ? ' selected' : '' ?>><?= _h('account.pm_who_friends') ?></option>
                    <option value="nobody"<?= ($meUser['pm_who'] ?? null) === 'nobody' ? ' selected' : '' ?>><?= _h('account.pm_who_nobody') ?></option>
                </select>
                <p class="text-muted acc-verify-note"><?= __('account.pm_who_hint') ?></p>
            </div>
            <?php endif; ?>
            <?php if ($accFav['may_use'] && $accFav['who_ok']): ?>
            <label class="search-check acc-check"><input type="checkbox" id="acc-fav-listed"<?= (int)($meUser['fav_listed'] ?? 0) === 1 ? ' checked' : '' ?>><span class="search-check-box" aria-hidden="true"></span>
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
            <?php if ($mayFileSearch): ?>
            <label class="search-check" title="<?= _h('search.files_title') ?>"><input type="checkbox" id="af-files" checked><span class="search-check-box" aria-hidden="true"></span> <?= _h('search.files') ?></label>
            <?php endif; ?>
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
        <label for="acc-new-email"><?= _h('account.email') ?> <small class="form-hint"><?= __('account.email_hint') ?><?=
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

<?php if ($accLists['may_use']): ?>
<?php /* Lists. A card each, because a list is a thing with a name and a decision attached — a row
         in a table would be the wrong shape for something you open. The cards and everything under
         them are drawn by assets/js/favourites.js from api/user_lists.php. */ ?>
<div class="acc-pane" id="acc-pane-lists" hidden>
    <h2 class="section-heading-spaced"><?= _h('account.tab_lists') ?></h2>
    <div id="account-lists" class="profile-section"
         data-magnet="<?= userCan($db, $cfg, 'index.magnet') ? '1' : '0' ?>"
         data-may-publish="<?= $accLists['may_publish'] ? '1' : '0' ?>"
         data-public-ok="<?= $accLists['public_ok'] ? '1' : '0' ?>"
         data-max-lists="<?= (int)$accLists['max_lists'] ?>"
         data-max-items="<?= (int)$accLists['max_items'] ?>"
         data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>"
         data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>">
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="ul-search" maxlength="80" placeholder="<?= _h('lists.search_ph') ?>" autocomplete="off">
            <?php /* A shelf of lists is searched by the NAME of the list — until somebody is looking
                     for the list that has a particular torrent in it, which is the other half of the
                     question and the half a name cannot answer. Both are checkboxes rather than a
                     mode, because the answer is usually "either". */ ?>
            <label class="search-check" title="<?= _h('lists.find_items_title') ?>"><input type="checkbox" id="ul-items"><span class="search-check-box" aria-hidden="true"></span> <?= _h('lists.find_items') ?></label>
            <?php if ($mayFileSearch): ?>
            <label class="search-check" title="<?= _h('search.files_title') ?>"><input type="checkbox" id="ul-files"><span class="search-check-box" aria-hidden="true"></span> <?= _h('search.files') ?></label>
            <?php endif; ?>
            <button type="button" class="btn btn-small" id="ul-new"><?= _h('lists.new') ?></button>
            <span class="profile-total" id="ul-total"></span>
        </div>
        <p class="text-muted lists-intro"><?= __('lists.intro') ?></p>
        <div class="lists-cards" id="ul-cards"></div>
    </div>
</div>
<?php endif; ?>

<?php if ($accPeople['may_message']): ?>
<?php /* Messages. The inbox on the left, the conversation beside it — and nothing about either is
         rendered here: api/user_messages.php decides what this reader may see, including whether
         they may write at all, and says why when they may not. */ ?>
<div class="acc-pane" id="acc-pane-messages" hidden>
    <h2 class="section-heading-spaced"><?= _h('account.tab_messages') ?></h2>
    <div id="account-messages" class="profile-section pm-wrap"
         data-max-chars="<?= (int)$accPeople['max_chars'] ?>"
         data-max-day="<?= (int)$accPeople['max_per_day'] ?>">
        <div class="pm-left">
            <div class="profile-toolbar">
                <input type="text" class="profile-search" id="pm-search" maxlength="60" placeholder="<?= _h('pm.search_ph') ?>" autocomplete="off">
                <?php /* An inbox with nobody in it needs a way in. The name box below only appears
                         when this is pressed — a page that opens with an empty form on it is a page
                         telling somebody to fill it in. */ ?>
                <button type="button" class="btn btn-small" id="pm-new"><?= _h('pm.new') ?></button>
            </div>
            <?php /* Searching the CONVERSATIONS is free — the list is already in the browser. Looking
                     INSIDE them is a LIKE over this reader's own messages, so it is a checkbox they
                     turn on, it waits for them to stop typing, and the endpoint holds its own
                     rate limit. */ ?>
            <label class="search-check pm-search-deep" title="<?= _h('pm.search_deep_title') ?>"><input type="checkbox" id="pm-deep"><span class="search-check-box" aria-hidden="true"></span> <?= _h('pm.search_deep') ?></label>
            <div class="pm-new-row" id="pm-new-row" hidden>
                <input type="text" class="profile-search" id="pm-new-who" maxlength="32" placeholder="<?= _h('pm.new_ph') ?>" autocomplete="off" list="pm-friends">
                <datalist id="pm-friends"></datalist>
                <button type="button" class="btn btn-small" id="pm-new-go"><?= _h('pm.new_go') ?></button>
            </div>
            <div class="pm-list" id="pm-threads"></div>
        </div>
        <div class="pm-right" id="pm-thread" hidden></div>
    </div>
    <p class="text-muted pm-note"><?= __('pm.note', ['n' => (int)$accPeople['max_per_day']]) ?></p>

    <?php /* Reporting one line. A window rather than window.prompt(), because a prompt cannot say
             what the moderator will and will not see — and that sentence is the whole reason
             somebody is willing to press the button. */ ?>
    <div class="files-overlay" id="pmreport-overlay" hidden>
        <div class="files-box pmreport-box" role="dialog" aria-modal="true" aria-labelledby="pmreport-title">
            <div class="files-head">
                <h3 id="pmreport-title"><?= _h('pm.report_head') ?></h3>
                <button type="button" class="files-close" id="pmreport-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>">&times;</button>
            </div>
            <div class="files-body">
                <p class="text-muted pmreport-note"><?= __('pm.report_note') ?></p>
                <textarea id="pmreport-why" class="pm-input" rows="3" maxlength="500" placeholder="<?= _h('pm.report_ph') ?>"></textarea>
                <div class="pmreport-acts">
                    <button type="button" class="btn btn-small" id="pmreport-go"><?= _h('pm.report_send') ?></button>
                    <span class="text-muted" id="pmreport-msg"></span>
                </div>
            </div>
        </div>
    </div>

    <?php /* THE COMPOSER, cloned into a conversation when one opens.
             It is the editor the description form uses — tabs, the formatting rail, the live
             preview and the counter — because a message is the same kind of text and the site
             already knows how to write one. Rendered here rather than built in JavaScript so its
             labels come from the same dictionary as everything else on the page. */ ?>
    <template id="pm-editor-tpl">
        <div class="rt-editor pm-editor">
            <div class="rt-tabs">
                <button type="button" class="rt-tab active" data-rt="write"><?= _h('whitelist.write') ?></button>
                <button type="button" class="rt-tab" data-rt="preview"><?= _h('whitelist.preview') ?></button>
                <span class="rt-counter" id="pm-body-count"></span>
                <select id="pm-body-format" class="rt-format" title="<?= _h('whitelist.format_title') ?>">
                    <option value="bbcode">BBCode</option>
                    <option value="markdown">Markdown</option>
                </select>
            </div>
            <div class="rt-tools" id="pm-body-tools" role="toolbar" aria-label="<?= _h('rt.toolbar') ?>">
                <span class="rt-tool-group">
                    <button type="button" data-md="bold" title="<?= _h('rt.bold') ?>"><strong>B</strong></button>
                    <button type="button" data-md="italic" title="<?= _h('rt.italic') ?>"><em>I</em></button>
                    <button type="button" data-md="underline" title="<?= _h('rt.underline') ?>"><u>U</u></button>
                    <button type="button" data-md="strike" title="<?= _h('rt.strike') ?>"><s>S</s></button>
                </span>
                <span class="rt-tool-group">
                    <button type="button" data-md="color" title="<?= _h('rt.color') ?>">&#127912;</button>
                    <button type="button" data-md="size" title="<?= _h('rt.size') ?>">A&#8593;</button>
                    <button type="button" data-md="highlight" title="<?= _h('rt.highlight') ?>">&#9635;</button>
                    <button type="button" data-md="sub" title="<?= _h('rt.sub') ?>">X&#8322;</button>
                    <button type="button" data-md="sup" title="<?= _h('rt.sup') ?>">X&#178;</button>
                </span>
                <span class="rt-tool-group">
                    <button type="button" data-md="link" title="<?= _h('rt.link') ?>">&#128279;</button>
                    <button type="button" data-md="image" title="<?= _h('rt.image') ?>">&#128444;</button>
                    <button type="button" data-md="list" title="<?= _h('rt.list') ?>">&#8226;&nbsp;<?= _h('rt.list_word') ?></button>
                    <button type="button" data-md="olist" title="<?= _h('rt.olist') ?>">1.&nbsp;<?= _h('rt.list_word') ?></button>
                </span>
                <span class="rt-tool-group">
                    <button type="button" data-md="quote" title="<?= _h('rt.quote') ?>">&rdquo;</button>
                    <button type="button" data-md="code" title="<?= _h('rt.code') ?>">&lt;/&gt;</button>
                    <button type="button" data-md="table" title="<?= _h('rt.table') ?>">&#9636;</button>
                    <button type="button" data-md="spoiler" title="<?= _h('rt.spoiler') ?>">&#128065;</button>
                    <button type="button" data-md="center" title="<?= _h('rt.center') ?>">&#8801;</button>
                    <button type="button" data-md="hr" title="<?= _h('rt.hr') ?>">&mdash;</button>
                </span>
            </div>
            <textarea id="pm-body" class="pm-input" rows="6" maxlength="<?= (int)$accPeople['max_chars'] ?>" placeholder="<?= _h('js.pm.write_ph') ?>"></textarea>
            <div class="rt-preview rt-body" id="pm-body-preview" hidden></div>
        </div>
        <?php /* The list of tags is a wall of brackets, and it was sitting under every conversation
                 whether anybody wanted it or not. Folded away: open once, and the browser remembers
                 nothing — which is right, because the person who needs it needs it once. */ ?>
        <details class="rt-syntax-fold">
            <summary><?= _h('rt.syntax_help') ?></summary>
            <div class="form-hint" id="pm-body-syntax"></div>
        </details>
        <div class="form-hint" id="pm-body-help"></div>
    </template>
</div>
<?php endif; ?>

<?php if ($accPeople['may_friend']): ?>
<?php /* Friends, requests in both directions, and the block list — four views of one table, which
         is what the endpoint serves and what the tab bar below switches between. */ ?>
<div class="acc-pane" id="acc-pane-people" hidden>
    <h2 class="section-heading-spaced"><?= _h('account.tab_people') ?></h2>
    <div id="account-people" class="profile-section">
        <div class="rt-tabs pe-tabs" id="pe-tabs" role="tablist">
            <button type="button" class="rt-tab active" data-view="friends"><?= _h('people.friends') ?></button>
            <button type="button" class="rt-tab" data-view="incoming"><?= _h('people.incoming') ?></button>
            <button type="button" class="rt-tab" data-view="pending"><?= _h('people.pending') ?></button>
            <button type="button" class="rt-tab" data-view="blocks"><?= _h('people.blocks') ?></button>
        </div>
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="pe-search" maxlength="60" placeholder="<?= _h('people.search_ph') ?>" autocomplete="off">
        </div>
        <div class="profile-list" id="pe-list"></div>
    </div>
</div>
<?php endif; ?>

<?php if ($accPeople['may_directory']): ?>
<?php /* The member directory, as a tab rather than a page of its own: it is one more list of people
         beside the reader's own, and ?action=members now lands here. */ ?>
<div class="acc-pane" id="acc-pane-members" hidden>
    <h2 class="section-heading-spaced"><?= _h('people.dir_h1') ?></h2>
    <div id="member-directory" class="profile-section">
        <p class="text-muted lists-intro"><?= __('people.dir_intro') ?></p>
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="dir-search" maxlength="60" placeholder="<?= _h('people.dir_search_ph') ?>" autocomplete="off">
            <select id="dir-sort" title="<?= _h('profile.sort') ?>">
                <option value="name:asc"><?= _h('people.dir_sort_name') ?></option>
                <option value="name:desc"><?= _h('people.dir_sort_name_desc') ?></option>
                <option value="since:desc"><?= _h('people.dir_sort_new') ?></option>
                <option value="since:asc"><?= _h('people.dir_sort_old') ?></option>
            </select>
            <span class="profile-total" id="dir-total"></span>
        </div>
        <div class="profile-list" id="dir-list"></div>
        <div class="trans-pagination" id="dir-pager"></div>
    </div>
</div>
<?php endif; ?>

<?php /* The Info panel, so a row on these lists can answer "what IS this?" without sending the
         reader back to the search page. Same markup, same script, same overlay — see the partial. */ ?>
<?php include __DIR__ . '/../partials/info_overlay.php'; ?>
