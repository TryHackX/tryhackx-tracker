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
        <?php if (usersEnabled($cfg) && indexEnabled($cfg) && ($cfg['index_search_enabled'] ?? '1') === '1' && userCan($db, $cfg, 'index.view')): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=search" class="<?= $action === 'search' ? 'active' : '' ?>">Search</a>
        <?php endif; ?>
        <?php $navUser = $navUser ?? (usersEnabled($cfg) ? currentUser($db) : null); ?>
        <?php $accountActive = in_array($action, ['account', 'login', 'register', 'reset', 'verify'], true); ?>
        <?php if ($navUser !== null): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=account" class="nav-user <?= $accountActive ? 'active' : '' ?>"><?= sanitize($navUser['username']) ?><span class="nav-unread" id="nav-unread" hidden></span></a>
        <?php elseif (usersLinksVisible($cfg)): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=login" class="<?= $accountActive ? 'active' : '' ?>">Account</a>
        <?php endif; ?>
    </div>
</nav>
