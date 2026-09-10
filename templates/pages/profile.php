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
$showFav = favPublicEnabled($cfg)
    && ($isSelf || ((int)($profile['fav_public'] ?? 0) === 1
                    && userIdHasPermission($db, $cfg, (int)$profile['id'], 'favourites.public')));
$showUploads = uploadsPossible($cfg) && uploadsPublicEnabled($cfg)
    && ($isSelf || userIdHasPermission($db, $cfg, (int)$profile['id'], 'uploads.public'));
$canMagnet = userCan($db, $cfg, 'index.magnet');
?>
<h1><?= _h('profile.h1') ?></h1>

<div class="profile-head">
    <span class="profile-name"><?= sanitize($profile['username']) ?></span>
    <?php if (!empty($profile['created_at'])): ?>
    <span class="profile-since"><?= _h('profile.member_since', ['date' => sanitize(substr((string)$profile['created_at'], 0, 10))]) ?></span>
    <?php endif; ?>
    <?php if ($isSelf): ?>
    <span class="profile-you"><?= _h('profile.this_is_you') ?></span>
    <?php endif; ?>
    <?php /* No switch of its own: a profile IS the page somebody hands to somebody else, and this
             button only writes down the address the reader is already at. The fallback box below it
             is what appears on plain HTTP, where the clipboard API does not exist. */ ?>
    <button type="button" class="search-share profile-share" id="profile-share"
            title="<?= _h('profile.share_title') ?>"><?= _h('search.share') ?></button>
</div>

<?php if (!$showFav && !$showUploads): ?>
<p class="text-muted"><?= _h('profile.nothing_shared') ?></p>
<?php endif; ?>

<div id="profile-body"
     data-user="<?= sanitize($profile['username']) ?>"
     data-self="<?= $isSelf ? '1' : '0' ?>"
     data-fav="<?= $showFav ? '1' : '0' ?>"
     data-uploads="<?= $showUploads ? '1' : '0' ?>"
     data-magnet="<?= $canMagnet ? '1' : '0' ?>"
     data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>"
     data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>">
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
            <span class="profile-total" id="pf-fav-total"></span>
        </div>
        <div class="profile-list" id="pf-fav-list"></div>
        <div class="trans-pagination" id="pf-fav-pager"></div>
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
</div>
