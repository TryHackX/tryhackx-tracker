<?php
/**
 * A public profile: ?action=u&name=<username>
 *
 * ── one shape of 404 for every no ──────────────────────────────────────────────────────────────
 * A bad name, no such account, a suspended one, a hidden profile, the feature switched off, the
 * permission missing — all render the same page. Registration already answers "is this name taken",
 * so this is not about keeping that secret: it is about not offering a cheap, scriptable way to tell
 * HIDDEN from NONEXISTENT.
 *
 * ── the shell only ─────────────────────────────────────────────────────────────────────────────
 * The lists come from api/user_favourites.php and api/user_uploads.php, the way
 * templates/pages/search.php gets its rows. The heavy queries stay out of the render, and out of the
 * exclusive session lock a render holds.
 *
 * A stranger sees the name and "member since". Never the email, never the last sign-in, never the
 * groups — those are facts about the person, not about what they have published.
 */
$viewer = currentUser($db);
$want = trim((string)($_GET['name'] ?? ''));

$profile = null;
if ($viewer !== null && profilesEnabled($cfg) && userValidUsername($want)) {
    $candidate = userFindByLogin($db, $want);
    if ($candidate && ($candidate['status'] ?? '') === 'active') {
        $isSelf = (int)$candidate['id'] === (int)$viewer['id'];
        // Somebody may always read their own profile, exactly as a stranger would see it — that is
        // the only honest way to answer "what does this look like to other people?".
        if ($isSelf || userCan($db, $cfg, 'favourites.view_others')) $profile = $candidate;
    }
}

if ($profile === null):
?>
<h1><?= _h('profile.h1') ?></h1>
<p><?= _h('profile.not_found') ?></p>
<p><a class="btn btn-secondary" href="<?= $baseUrl ?>"><?= _h('common.back_home') ?></a></p>
<?php
    return;
endif;

$isSelf = (int)$profile['id'] === (int)$viewer['id'];
// What this profile may SHOW is decided here, once, and handed to the page as data attributes.
// Each half asks the same three things: does the site allow it, does their group allow it, did they
// say yes. A stale checkbox must not outlive the group that permitted it.
// userIdHasGrantedPermission(), not userIdHasPermission(): what a stranger may see of somebody is
// decided by the grant their group actually carries, and never by the blanket that lets an
// administrator work the site. The "who has this" overlay reads the same stored JSON in SQL, so the
// two views of one setting cannot disagree.
$showFav = favPublicEnabled($cfg)
    && ($isSelf || ((int)($profile['fav_public'] ?? 0) === 1
                    && userIdHasGrantedPermission($db, $cfg, (int)$profile['id'], 'favourites.public')));
// Likes or ratings (1.69.0, includes/profilevotes.php) — the one gate the endpoint behind the section
// asks as well: the site's switch and ratings on; for somebody else, their group's GRANT of
// rating.public (not the admin blanket) and their own yes. Your own profile shows yours either way.
$showVotes = function_exists('profileVotesShownTo') && profileVotesShownTo($db, $cfg, $profile, $viewer);
$showUploads = uploadsPossible($cfg) && uploadsPublicEnabled($cfg)
    && ($isSelf || userIdHasGrantedPermission($db, $cfg, (int)$profile['id'], 'uploads.public'));
$canMagnet = userCan($db, $cfg, 'index.magnet');
// Lists, the same shape of decision one line lower: the site switch, their group's grant, their own
// section flag — and then each list's own is_public, which the endpoint applies.
$profLists = listsContext($db, $cfg, $viewer);
$profPeople = peopleContext($db, $cfg, $viewer);
// The READER's own answers about the favourites feature. Only one of them is used here: whether
// THEY may put a submission on a profile, which decides whether the visibility toggle is drawn on
// their own page. Asking the grant (favContext does) and drawing the control from it keeps the
// button and the endpoint behind it agreeing — a toggle that posts a 403 is a broken switch.
$profFav = favContext($db, $cfg, $viewer);
// The cluster's extra announce ports, exactly as templates/pages/search.php emits them: a magnet
// built on this page has to name every port the tracker answers on, not only the two the Settings
// page shows.
$profExtra = array_values(array_diff(function_exists('announceUrls') ? announceUrls($cfg) : [],
                                     array_filter([(string)($cfg['announce_url'] ?? ''), (string)($cfg['announce_url_https'] ?? '')])));
$mayFileSearch = userCan($db, $cfg, 'index.files');   // see the account page: the same gate, asked once
// A block with `hide_profile` closes the page for that one reader, and closes it the way every
// other "no" closes it: the same not-found page a name nobody has renders. Telling them "you have
// been blocked" here would answer a question they did not ask and confirm the account exists.
if (!$isSelf && profileHiddenFrom($db, (int)$profile['id'], (int)$viewer['id'])) {
    echo '<h1>' . _h('profile.h1') . '</h1><p>' . _h('profile.not_found') . '</p>';
    echo '<p><a class="btn btn-secondary" href="' . $baseUrl . '">' . _h('common.back_home') . '</a></p>';
    return;
}
$profState   = $profPeople['may_friend'] && !$isSelf ? friendState($db, (int)$viewer['id'], (int)$profile['id']) : 'none';
$profBlocked = !$isSelf && blockRow($db, (int)$viewer['id'], (int)$profile['id']) !== null;
$showLists = $profLists['enabled'] && ($isSelf ? $profLists['may_use'] : ($profLists['may_view'] && listsVisibleFor($db, $cfg, $profile)));
// The description (1.69.0, includes/profilebio.php): what the page SHOWS is asked of the account the
// words belong to (hidden, never deleted, while its groups do not grant profile.bio), and the editor is
// drawn only on your own profile while you may write one. Another member's empty description draws
// nothing at all; your own empty one is the dashed "write something" box.
$bioHtml = function_exists('profileBioFor') ? profileBioFor($db, $cfg, $profile) : '';
$bioEdit = $isSelf && function_exists('profileBioMayWrite') && profileBioMayWrite($db, $cfg, $viewer);
?>
<h1><?= _h('profile.h1') ?></h1>
<?php /* The token every POST from this page needs — following somebody, blocking them, un-starring
         a row of your own list. Without it the scripts here posted an empty token and every write
         answered 403, which reads exactly like a permission problem and is not one. */ ?>
<input type="hidden" id="account-csrf" value="<?= $csrfToken ?>">

<?php
// The picture and the cover (1.63.0, includes/usermedia.php). Their own cover, else the site's default
// cover, else the plain head this page always had. The band is painted the way the research's
// section 5 paints it (see .cv-paint in assets/css/style.css), and its numbers — the image address,
// the focal point, the zoom, the two heights — arrive in a nonce'd <style> for this one element: on
// the first frame, before any script, and never as a style="" attribute (userCoverStyleBlock()).
$profCover = function_exists('userCoverFor') ? userCoverFor($profile, $baseUrl, $cfg) : null;
$profTopCls = 'profile-top';
if ($profCover !== null) {
    $profTopCls .= ' has-cover cv-overlay-' . userCoverOverlay($cfg) . ($profCover['zoom'] < 1 ? ' cv-zoomout' : '');
    echo userCoverStyleBlock('#profile-top', $profCover, $cfg);
}
?>
<div class="<?= sanitize($profTopCls) ?>" id="profile-top">
<?php if ($profCover !== null): ?>
<div class="cv-paint" aria-hidden="true"><div class="cv-shade"></div></div>
<?php endif; ?>
<div class="profile-head<?= ($bioHtml !== '' || $bioEdit) ? ' has-bio' : '' ?>">
    <?php /* Left of the name at 64 px, cut from the 128 square — or the letter, or the site's default.
             Nothing at all when pictures are switched off (userAvatarHtml() answers ''). */ ?>
    <?= function_exists('userAvatarHtml') ? userAvatarHtml($profile, 64, $baseUrl, 'profile-avatar', $cfg) : '' ?>
    <?php /* The text column beside the picture (1.69.0): the name row, and under it the description —
             the Flarum hero the owner pointed at, in this site's own type and colours. One column, so
             a long description grows the head downwards beside the picture instead of wrapping under it. */ ?>
    <div class="profile-head-main">
    <div class="profile-head-row">
    <span class="profile-name"><?= sanitize($profile['username']) ?></span>
    <?php if (!empty($profile['created_at'])): ?>
    <span class="profile-since"><?= _h('profile.member_since', ['date' => sanitize(substr((string)$profile['created_at'], 0, 10))]) ?></span>
    <?php endif; ?>
    <?php if ($isSelf): ?>
    <span class="profile-you"><?= _h('profile.this_is_you') ?></span>
    <?php endif; ?>
    <?php /* Behind the same switch as every other Share on the site: nothing here is secret — the
             address is built from the name — so the setting is about whether the operator wants the
             site handing out links at all, and a profile is not an exception to that. The fallback
             box below it is what appears on plain HTTP, where the clipboard API does not exist. */ ?>
    <?php if (!$isSelf && ($profPeople['may_message'] || $profPeople['may_friend'])): ?>
    <?php /* What this reader may do about this person. Drawn by assets/js/people.js from these
             answers — the page decides, the script only renders. */ ?>
    <span class="profile-people" id="profile-people"
          data-user="<?= sanitize($profile['username']) ?>"
          data-state="<?= sanitize($profState) ?>"
          data-blocked="<?= $profBlocked ? '1' : '0' ?>"
          data-pm="<?= $profPeople['may_message'] ? '1' : '0' ?>"
          data-friends="<?= $profPeople['may_friend'] ? '1' : '0' ?>"
          data-block="<?= ($profPeople['may_message'] || $profPeople['may_friend']) ? '1' : '0' ?>"></span>
    <?php endif; ?>
    <?php if (($cfg['search_share_enabled'] ?? '1') === '1'): ?>
    <button type="button" class="btn btn-secondary btn-small profile-share js-profile-share" id="profile-share"
            data-user="<?= sanitize($profile['username']) ?>"
            title="<?= _h('profile.share_title') ?>"><?= _h('search.share') ?></button>
    <?php endif; ?>
    </div><?php /* /.profile-head-row */ ?>
    <?php if ($bioHtml !== '' || $bioEdit): ?>
    <?php /* The words are the member's own: `data-lang-keep` keeps the in-place language switch out of
             them where nothing else in the element is in the page's language, and dir="auto" lets a
             right-to-left text read the right way in its own block (the bidi overrides that could make
             a line lie about itself were taken out when it was saved). On your own profile the text is
             also the button that opens the editor (assets/js/profile-bio.js) — a link inside it still
             just opens the link — so there it carries no data-lang-keep: its title is in the page's
             language, and the words are the same in both renders, so the switch finds nothing in them
             to change. The source travels in data-source, for its owner's page only. Every node here
             has an id, because lang-swap.js pairs by id. */ ?>
    <div class="profile-bio<?= $bioEdit ? ' is-editable' : '' ?>" id="profile-bio"
         <?php if ($bioEdit): ?>data-edit="1" data-max="<?= profileBioMax($cfg) ?>" data-cap="<?= profileBioSourceCap($cfg) ?>"
         data-source="<?= sanitize(profileBioClean((string)($profile['bio'] ?? ''))) ?>"<?php endif; ?>>
        <?php if ($bioEdit): ?>
        <?php /* Named by its own words (a screen reader reads the description), described as the way
                 to edit it — an aria-label would have replaced the text it is a button for. */ ?>
        <span class="profile-bio-hint" id="profile-bio-hint" hidden><?= _h('profile.bio_edit_title') ?></span>
        <div class="profile-bio-text" id="profile-bio-text" dir="auto" role="button" tabindex="0"
             title="<?= _h('profile.bio_edit_title') ?>" aria-describedby="profile-bio-hint"<?= $bioHtml === '' ? ' hidden' : '' ?>><?= $bioHtml ?></div>
        <button type="button" class="profile-bio-ph" id="profile-bio-ph"<?= $bioHtml !== '' ? ' hidden' : '' ?>><?= _h('profile.bio_placeholder') ?></button>
        <?php else: ?>
        <div class="profile-bio-text" id="profile-bio-text" dir="auto" data-lang-keep><?= $bioHtml ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div><?php /* /.profile-head-main */ ?>
</div>
</div><?php /* /#profile-top */ ?>

<?php if (!$showFav && !$showVotes && !$showUploads && !$showLists): ?>
<p class="text-muted"><?= _h('profile.nothing_shared') ?></p>
<?php endif; ?>

<div id="profile-body"
     data-user="<?= sanitize($profile['username']) ?>"
     data-self="<?= $isSelf ? '1' : '0' ?>"
     data-fav="<?= $showFav ? '1' : '0' ?>"
     data-uploads="<?= $showUploads ? '1' : '0' ?>"
     data-lists="<?= $showLists ? '1' : '0' ?>"
     data-magnet="<?= $canMagnet ? '1' : '0' ?>"
     data-may-publish="<?= $profFav['uploads_pub'] ? '1' : '0' ?>"
     data-share="<?= ($cfg['search_share_enabled'] ?? '1') === '1' ? '1' : '0' ?>"
     data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>"
     data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>"
     data-announce-extra="<?= sanitize(implode(' ', $profExtra)) ?>">
    <?php if ($showFav): ?>
    <section class="profile-section" id="profile-fav">
        <h2><?= _h('profile.favourites') ?></h2>
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="pf-fav-search" maxlength="120" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
            <select id="pf-fav-sort" title="<?= _h('profile.sort') ?>">
                <option value="added:desc"><?= _h('profile.sort_added') ?></option>
                <option value="name:asc"><?= _h('profile.sort_name') ?></option>
                <option value="size:desc"><?= _h('profile.sort_size') ?></option>
                <option value="seeders:desc"><?= _h('profile.sort_seeders') ?></option>
            </select>
            <?php if ($mayFileSearch): ?>
            <label class="search-check" title="<?= _h('search.files_title') ?>"><input type="checkbox" id="pf-fav-files" checked><span class="search-check-box" aria-hidden="true"></span> <?= _h('search.files') ?></label>
            <?php endif; ?>
            <span class="profile-total" id="pf-fav-total"></span>
        </div>
        <div class="profile-list" id="pf-fav-list"></div>
        <div class="trans-pagination" id="pf-fav-pager"></div>
    </section>
    <?php endif; ?>

    <?php if ($showVotes): ?>
    <?php /* Likes or ratings (1.69.0): right after Favourites, before Uploads and Lists. The same partial
             as the account page's tab; "your" on your own profile, "their" on anybody else's. */ ?>
    <section class="profile-section" id="profile-votes">
        <h2 id="profile-votes-heading"><?= _h('profile.votes_' . repMode($cfg)) ?></h2>
        <?php $pvUser = (string)$profile['username']; $pvSelf = $isSelf; $pvExtra = $profExtra; include __DIR__ . '/../partials/votes_section.php'; ?>
    </section>
    <?php endif; ?>

    <?php if ($showUploads): ?>
    <section class="profile-section" id="profile-uploads">
        <h2><?= _h('profile.uploads') ?></h2>
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="pf-up-search" maxlength="120" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
            <select id="pf-up-status" title="<?= _h('profile.status') ?>">
                <option value=""><?= _h('profile.status_any') ?></option>
                <option value="live"><?= _h('profile.status_live') ?></option>
                <option value="waiting"><?= _h('profile.status_waiting') ?></option>
                <option value="refused"><?= _h('profile.status_refused') ?></option>
                <option value="blocked"><?= _h('profile.status_blocked') ?></option>
            </select>
            <select id="pf-up-sort" title="<?= _h('profile.sort') ?>">
                <option value="added:desc"><?= _h('profile.sort_added') ?></option>
                <option value="name:asc"><?= _h('profile.sort_name') ?></option>
                <option value="size:desc"><?= _h('profile.sort_size') ?></option>
                <option value="seeders:desc"><?= _h('profile.sort_seeders') ?></option>
            </select>
            <span class="profile-total" id="pf-up-total"></span>
        </div>
        <div class="profile-list" id="pf-up-list"></div>
        <div class="trans-pagination" id="pf-up-pager"></div>
    </section>
    <?php endif; ?>

    <?php if ($showLists): ?>
    <?php /* Their PUBLIC lists only — the endpoint decides that, not this page. A card each, the
             same shape the owner sees on their account page, and opening one shows what is in it. */ ?>
    <section class="profile-section" id="profile-lists">
        <h2><?= _h('profile.lists') ?></h2>
        <div class="profile-toolbar">
            <input type="text" class="profile-search" id="pl-search" maxlength="80" placeholder="<?= _h('lists.search_ph') ?>" autocomplete="off">
            <span class="profile-total" id="pl-total"></span>
        </div>
        <div class="lists-cards" id="pl-cards"></div>
    </section>
    <?php endif; ?>
</div>

<?php /* The Info panel, so a row on these lists can answer "what IS this?" without sending the
         reader back to the search page. Same markup, same script, same overlay — see the partial. */ ?>
<?php include __DIR__ . '/../partials/info_overlay.php'; ?>
