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
    <?php foreach (adminNavItemsFor($db, $cfg) as $item): ?>
        <?php
        $isHere = $item['action'] === $current;
        // The labels themselves live in adminNavItems(), in English, because that list is also read
        // by code that has nothing to do with rendering. Translate them here, keyed by the action id
        // (hyphens are not legal in a dictionary key, so admin-users becomes admin_users). A page
        // added to that list without a key here keeps showing its English label rather than a key.
        $labelKey = 'a.head.nav.' . str_replace('-', '_', $item['action']);
        $label = langHas($labelKey) ? _h($labelKey) : htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        ?>
        <a href="<?= $baseUrl ?>?action=<?= $item['action'] ?>"
           class="btn btn-sm btn-outline-info<?= $isHere ? ' active' : '' ?>"
           <?= $isHere ? 'aria-current="page"' : '' ?>><i class="bi <?= $item['icon'] ?>"></i> <?= $label ?></a>
    <?php endforeach; ?>
    <?php
    // The anchor belongs to the page you are LEAVING, not to Settings itself: from Users, "Settings"
    // opens the Users section. On the Settings page itself the button is the active one.
    $anchor = '';
    foreach (adminNavItems() as $item) { if ($item['action'] === $current) { $anchor = $item['anchor']; break; } }
    ?>
    <?php // Settings has no permission id, so only the owner's own session can open it — and a button
          // that leads to a 403 is worse than no button. ?>
    <?php if (adminPageAllowed($db, $cfg, 'settings')): ?>
    <a href="<?= $baseUrl ?>?action=settings<?= $anchor ?>"
       class="btn btn-sm btn-outline-info<?= $current === 'settings' ? ' active' : '' ?>"
       <?= $current === 'settings' ? 'aria-current="page"' : '' ?>><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
    <?php endif; ?>
    <?php
    // The language switcher, the same ?lang= links the public nav uses, kept on the page you are on.
    // Only when there is a choice: one language is a fact, not a control.
    $hdrLangs = function_exists('langForSwitcher') ? langForSwitcher($cfg) : [];
    if (count($hdrLangs) > 1):
        $hdrNow = langCurrent();
        $hdrQuery = $_GET; unset($hdrQuery['lang']);
    ?>
    <span class="admin-lang" role="group" aria-label="<?= _h('common.language') ?>">
        <?php foreach ($hdrLangs as $hc => $hn): $hdrQuery['lang'] = $hc; ?>
        <a href="<?= $baseUrl ?>?<?= sanitize(http_build_query($hdrQuery)) ?>" class="admin-lang-opt<?= $hc === $hdrNow ? ' active' : '' ?>"
           hreflang="<?= sanitize($hc) ?>" title="<?= sanitize($hn) ?>" <?= $hc === $hdrNow ? 'aria-current="true"' : '' ?>><?= sanitize(strtoupper($hc)) ?></a>
        <?php endforeach; ?>
    </span>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-danger" id="btn-logout"><i class="bi bi-box-arrow-right"></i> <?= _h('a.head.logout') ?></button>
</div>
