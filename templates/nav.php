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
        <?php $navUser = $navUser ?? (usersEnabled($cfg) ? currentUser($db) : null); ?>
        <?php /* The member directory used to be a nav entry of its own. It is a tab of the account
                 page now, and the account is already in this bar — a second link to the same page,
                 active-highlighting differently, was one entry too many. `?action=members` still
                 redirects, because links to it are out in the world. */ ?>
        <?php $accountActive = in_array($action, ['account', 'login', 'register', 'reset', 'verify'], true); ?>
        <?php if ($navUser !== null): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=account" class="nav-user <?= $accountActive ? 'active' : '' ?>"><?= sanitize($navUser['username']) ?><span class="nav-unread" id="nav-unread" hidden></span></a>
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
