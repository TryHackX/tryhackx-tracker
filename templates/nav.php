<nav class="main-nav">
    <div class="nav-links">
        <a href="<?= $baseUrl ?>" class="<?= $action === 'home' ? 'active' : '' ?>"><?= _h('nav.home') ?></a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=info" class="<?= $action === 'info' ? 'active' : '' ?>"><?= _h('nav.info') ?></a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=tos" class="<?= $action === 'tos' ? 'active' : '' ?>"><?= _h('nav.terms') ?></a>
        <span class="sep">|</span>
        <?php if ((trackerMode($cfg) === 'whitelist' || (function_exists('scheduleEnabled') && scheduleEnabled($cfg))) && ($cfg['whitelist_public_enabled'] ?? '1') === '1'): ?>
        <a href="<?= $baseUrl ?>?action=whitelist" class="<?= $action === 'whitelist' ? 'active' : '' ?>"><?= _h('nav.whitelist') ?></a>
        <span class="sep">|</span>
        <?php endif; ?>
        <a href="<?= $baseUrl ?>?action=report" class="<?= $action === 'report' ? 'active' : '' ?>"><?= _h('nav.report') ?></a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=status" class="<?= $action === 'status' ? 'active' : '' ?>"><?= _h('nav.status') ?></a>
        <?php if (($cfg['transparency_enabled'] ?? '1') === '1'): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=transparency" class="<?= $action === 'transparency' ? 'active' : '' ?>"><?= _h('nav.transparency') ?></a>
        <?php endif; ?>
        <?php if (($cfg['tracker_stats_enabled'] ?? '0') === '1' && userCan($db, $cfg, 'stats.view')): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=stats" class="<?= $action === 'stats' ? 'active' : '' ?>"><?= _h('nav.stats') ?></a>
        <?php endif; ?>
        <?php if (usersEnabled($cfg) && indexEnabled($cfg) && ($cfg['index_search_enabled'] ?? '1') === '1' && userCan($db, $cfg, 'index.view')): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=search" class="<?= $action === 'search' ? 'active' : '' ?>"><?= _h('nav.search') ?></a>
        <?php endif; ?>
        <?php /* The shoutbox, with a number of its own (1.60.0). A feature-gated link like Stats and
                 Search above it — and the number deliberately does NOT join the badge beside the
                 account name: that one counts notifications and waiting messages, a reader who sees
                 5 on it has two tabs to go and look in, and a third meaning would leave it answering
                 nothing. assets/js/app.js fills this one from the same pulse. */ ?>
        <?php if (function_exists('shoutNav') && shoutNav($cfg) && shoutMayView($db, $cfg)): ?>
        <span class="sep">|</span>
        <a href="<?= sanitize(shoutNavUrl($cfg, $baseUrl)) ?>" class="<?= $action === 'shoutbox' ? 'active' : '' ?>"><?= _h('nav.shoutbox') ?><span class="nav-unread nav-shout-unread" id="nav-shout-unread" hidden data-uid="<?= (int)($navUser['id'] ?? 0) ?>" data-pulse="<?= (int)siteLiveSeconds($cfg) ?>" title="<?= _h('nav.shoutbox_unread_title') ?>"></span></a>
        <?php endif; ?>
        <?php $navUser = $navUser ?? (usersEnabled($cfg) ? currentUser($db) : null); ?>
        <?php /* The member directory used to be a nav entry of its own. It is a tab of the account
                 page now, and the account is already in this bar — a second link to the same page,
                 active-highlighting differently, was one entry too many. `?action=members` still
                 redirects, because links to it are out in the world. */ ?>
        <?php $accountActive = in_array($action, ['account', 'login', 'register', 'reset', 'verify'], true); ?>
        <?php if ($navUser !== null): ?>
        <span class="sep">|</span>
        <?php /* The reader's own picture before their name (1.63.0) — `$navUser` is the whole account row,
                 so this is no query at all; nothing while pictures are switched off. `js-avatar-me`
                 is how assets/js/media-editor.js finds it to follow a new picture without a reload. */ ?>
        <a href="<?= $baseUrl ?>?action=account" class="nav-user <?= $accountActive ? 'active' : '' ?>"><?= function_exists('userAvatarHtml') ? userAvatarHtml($navUser, 20, $baseUrl, 'avatar nav-av js-avatar-me', $cfg) : '' ?><?= sanitize($navUser['username']) ?><?php $navSounds = soundClientConfig($db, $cfg, $navUser, $baseUrl); ?><span class="nav-unread" id="nav-unread" hidden data-uid="<?= (int)$navUser['id'] ?>" data-pulse="<?= (int)siteLiveSeconds($cfg) ?>"<?= $navSounds ? ' data-sounds="' . sanitize(json_encode($navSounds, JSON_UNESCAPED_SLASHES)) . '"' : '' ?>></span></a><?php /* Sounds (1.56.0): what this reader plays rides on the badge as data-sounds, and the note beside it is
         shown by assets/js/sounds.js only while the browser refuses to let the page make a sound. */ ?><?php if ($navSounds): ?><span class="sound-chip" id="sound-chip" hidden title="<?= _h('nav.sounds_locked_title') ?>">&#128263; <?= _h('nav.sounds_locked') ?></span><?php endif; ?>
        <?php elseif (usersLinksVisible($cfg)): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=login" class="<?= $accountActive ? 'active' : '' ?>"><?= _h('nav.account') ?></a>
        <?php endif; ?>
<?php
// THE LANGUAGE SWITCHER -- the last item in the link row, not a second column.
//
// It used to be a sibling of `.nav-links` with `space-between` between them. That works only while
// the links fit on one line; with longer labels (Polish) the link row takes the full width, wraps,
// and the switcher lands alone on a third line at the left edge. As the last item in the row it
// wraps with everything else and stays centred, whatever the labels say.
//
// Rendered only when there is a choice to make: one language is not a switcher, it is a label that
// does nothing. The links keep the visitor on the page they are reading (`?lang=` is added to the
// CURRENT query, not to the front page), because a control that also throws away where you were is
// a control people learn not to press.
$langOpts = function_exists('langForSwitcher') ? langForSwitcher($cfg) : [];
if (count($langOpts) > 1):
    $langNow = langCurrent();
    $langQuery = $_GET;
    unset($langQuery['lang']);
?>
        <span class="sep">|</span>
        <span class="lang-switch" role="group" aria-label="<?= _h('common.language') ?>">
            <?php foreach ($langOpts as $langCode => $langName):
                $langQuery['lang'] = $langCode;
                $langHref = $baseUrl . '?' . http_build_query($langQuery);
            ?>
            <a href="<?= sanitize($langHref) ?>" class="lang-opt<?= $langCode === $langNow ? ' active' : '' ?>"
               hreflang="<?= sanitize($langCode) ?>" title="<?= sanitize($langName) ?>"
               <?= $langCode === $langNow ? 'aria-current="true"' : '' ?>><?= sanitize(strtoupper($langCode)) ?></a>
            <?php endforeach; ?>
        </span>
<?php endif; ?>
    </div>
</nav>
