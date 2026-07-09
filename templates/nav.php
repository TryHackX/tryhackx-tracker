<nav class="main-nav">
    <div class="nav-links">
        <a href="<?= $baseUrl ?>" class="<?= $action === 'home' ? 'active' : '' ?>">Home</a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=info" class="<?= $action === 'info' ? 'active' : '' ?>">Info</a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=tos" class="<?= $action === 'tos' ? 'active' : '' ?>">Terms</a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=report" class="<?= $action === 'report' ? 'active' : '' ?>">Report</a>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=status" class="<?= $action === 'status' ? 'active' : '' ?>">Status</a>
        <?php if (($cfg['transparency_enabled'] ?? '1') === '1'): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=transparency" class="<?= $action === 'transparency' ? 'active' : '' ?>">Transparency</a>
        <?php endif; ?>
        <?php if (($cfg['tracker_stats_enabled'] ?? '0') === '1'): ?>
        <span class="sep">|</span>
        <a href="<?= $baseUrl ?>?action=stats" class="<?= $action === 'stats' ? 'active' : '' ?>">Stats</a>
        <?php endif; ?>
    </div>
</nav>
