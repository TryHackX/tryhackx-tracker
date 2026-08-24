<nav class="main-nav">
    <div class="nav-links">
        <a href="<?= $baseUrl ?>" class="<?= $action === 'home' ? 'active' : '' ?>">Home</a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=info" class="<?= $action === 'info' ? 'active' : '' ?>">Info</a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=tos" class="<?= $action === 'tos' ? 'active' : '' ?>">Terms</a>
        <span class="sep">|</span>
        <?php if ((trackerMode($cfg) === 'whitelist' || (function_exists('scheduleEnabled') && scheduleEnabled($cfg))) && ($cfg['whitelist_public_enabled'] ?? '1') === '1'): ?>
        <a href="<?= $baseUrl ?>?action=whitelist" class="<?= $action === 'whitelist' ? 'active' : '' ?>">Whitelist</a>
        <span class="sep">|</span>
        <?php endif; ?>
        <a href="<?= $baseUrl ?>?action=report" class="<?= $action === 'report' ? 'active' : '' ?>">Report</a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=status" class="<?= $action === 'status' ? 'active' : '' ?>">Status</a>
        <?php if (($cfg['transparency_enabled'] ?? '1') === '1'): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=transparency" class="<?= $action === 'transparency' ? 'active' : '' ?>">Transparency</a>
        <?php endif; ?>
        <?php if (($cfg['tracker_stats_enabled'] ?? '0') === '1' && userCan($db, $cfg, 'stats.view')): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=stats" class="<?= $action === 'stats' ? 'active' : '' ?>">Stats</a>
        <?php endif; ?>
        <?php if (usersEnabled($cfg) && indexEnabled($cfg) && userCan($db, $cfg, 'index.view')): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=search" class="<?= $action === 'search' ? 'active' : '' ?>">Search</a>
        <?php endif; ?>
        <?php $navUser = $navUser ?? (usersEnabled($cfg) ? currentUser($db) : null); ?>
        <?php if ($navUser !== null): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=account" class="nav-user <?= $action === 'account' ? 'active' : '' ?>"><?= sanitize($navUser['username']) ?><span class="nav-unread" id="nav-unread" hidden></span></a>
        <?php elseif (usersLinksVisible($cfg)): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=login" class="<?= $action === 'login' ? 'active' : '' ?>">Sign in</a>
        <?php if (usersRegistrationEnabled($cfg)): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=register" class="<?= $action === 'register' ? 'active' : '' ?>">Register</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</nav>
