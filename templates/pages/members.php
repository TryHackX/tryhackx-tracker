<?php
/**
 * The member directory: ?action=members
 *
 * A way to FIND somebody, not a census. It lists the people who ticked "put me on the directory"
 * and nobody else — `users.profile_listed`, which is a different sentence from "my profile is
 * public" and is deliberately not inferred from it.
 *
 * Everything below the shell comes from api/user_directory.php: the shape of one row, who is on the
 * page, and what this reader may do about them. This file only decides whether the page exists.
 */
$dirViewer = currentUser($db);
$dirCtx = peopleContext($db, $cfg, $dirViewer);

if (!$dirCtx['may_directory']):
?>
<h1><?= _h('people.dir_h1') ?></h1>
<p><?= _h($dirViewer === null ? 'people.dir_sign_in' : 'people.dir_off') ?></p>
<p><a class="btn btn-secondary" href="<?= $baseUrl ?>"><?= _h('common.back_home') ?></a></p>
<?php
    return;
endif;
?>
<h1><?= _h('people.dir_h1') ?></h1>
<p class="text-muted"><?= __('people.dir_intro') ?></p>
<input type="hidden" id="account-csrf" value="<?= $csrfToken ?>">

<div id="member-directory" class="profile-section">
    <div class="profile-toolbar">
        <input type="text" class="profile-search" id="dir-search" maxlength="60" placeholder="<?= _h('people.dir_search_ph') ?>" autocomplete="off">
        <span class="profile-total" id="dir-total"></span>
    </div>
    <div class="profile-list" id="dir-list"></div>
    <div class="trans-pagination" id="dir-pager"></div>
</div>
