<?php
/**
 * The panel's navigation bar, rendered from one list (adminNavItems() in includes/auth.php).
 *
 * Every page shows every page, current one included and marked active. Each template used to carry
 * its own hand-edited copy of this markup with its own entry deleted, so the bar silently changed
 * shape from page to page — from Whitelist there was no "Whitelist", from Index no "Index", and the
 * dashboard linked to no sub-page at all. Nothing told you where you were.
 *
 * Expects $current (the ?action= value of the page including this) and $baseUrl.
 * Settings keeps its per-page deep link, so it still opens on the section you were just looking at.
 */
$current = $current ?? '';
$navExtra = $navExtra ?? null;   // an optional block rendered first, inside the bar (see dashboard.php)
?>
<div class="admin-header-actions">
    <?php if (!empty($navExtra) && is_file($navExtra)) include $navExtra; ?>
    <?php foreach (adminNavItems() as $item): ?>
        <?php $isHere = $item['action'] === $current; ?>
        <a href="<?= $baseUrl ?>?action=<?= $item['action'] ?>"
           class="btn btn-sm btn-outline-info<?= $isHere ? ' active' : '' ?>"
           <?= $isHere ? 'aria-current="page"' : '' ?>><i class="bi <?= $item['icon'] ?>"></i> <?= $item['label'] ?></a>
    <?php endforeach; ?>
    <?php
    // The anchor belongs to the page you are LEAVING, not to Settings itself: from Users, "Settings"
    // opens the Users section. On the Settings page itself the button is the active one.
    $anchor = '';
    foreach (adminNavItems() as $item) { if ($item['action'] === $current) { $anchor = $item['anchor']; break; } }
    ?>
    <a href="<?= $baseUrl ?>?action=settings<?= $anchor ?>"
       class="btn btn-sm btn-outline-info<?= $current === 'settings' ? ' active' : '' ?>"
       <?= $current === 'settings' ? 'aria-current="page"' : '' ?>><i class="bi bi-gear"></i> Settings</a>
    <button class="btn btn-sm btn-outline-danger" id="btn-logout"><i class="bi bi-box-arrow-right"></i> Logout</button>
</div>
