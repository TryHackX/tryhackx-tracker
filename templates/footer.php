<?php
/**
 * The footer, in one place, for the public pages AND for every panel page.
 *
 * It used to live inline in templates/layout.php, which no panel page includes — so the panel had
 * no footer at all, and the operator had nowhere to read what the site says about itself or which
 * build they are looking at. Two callers, one file:
 *
 *   · templates/layout.php includes it inside .container;
 *   · each templates/admin/*.php includes it after .admin-container closes, with $footerInPanel
 *     set, which is what picks the panel's own spacing and the panel side of `version_display`.
 *
 * $recaptchaNeeded is a public-layout variable; a panel page never sets it, so it is read with
 * !empty() rather than assumed. Everything else comes from $cfg, which both callers already have.
 */
$footerInPanel = !empty($footerInPanel);
?>
<footer class="site-footer<?= $footerInPanel ? ' admin-footer' : '' ?>">
    <?php
    $startYear = (int)($cfg['footer_start_year'] ?? date('Y'));
    $currentYear = (int)date('Y');
    $yearStr = ($currentYear > $startYear) ? "$startYear - $currentYear" : (string)$startYear;

    $footerLine1 = [];
    $footerLine1[] = "&copy; $yearStr";

    if (($cfg['footer_brand_enabled'] ?? '1') === '1' && !empty($cfg['footer_brand_name'])) {
        $bName = sanitize($cfg['footer_brand_name']);
        $bUrl = $cfg['footer_brand_url'] ?? '';
        $footerLine1[] = $bUrl ? '<a href="' . sanitize($bUrl) . '" class="footer-brand" target="_blank">' . $bName . '</a>' : $bName;
    }

    if (($cfg['footer_tracker_enabled'] ?? '1') === '1' && !empty($cfg['footer_tracker_name'])) {
        $tName = sanitize($cfg['footer_tracker_name']);
        $tUrl = $cfg['footer_tracker_url'] ?? '';
        $tAuthor = $cfg['footer_tracker_author'] ?? '';
        $tAuthorUrl = $cfg['footer_tracker_author_url'] ?? '';
        $trackerPart = __('footer.powered_by') . ' ' . ($tUrl ? '<a href="' . sanitize($tUrl) . '" class="footer-link" target="_blank">' . $tName . '</a>' : $tName);
        if ($tAuthor) {
            $trackerPart .= ' ' . __('footer.by_author') . ' ' . ($tAuthorUrl ? '<a href="' . sanitize($tAuthorUrl) . '" class="footer-link" target="_blank">' . sanitize($tAuthor) . '</a>' : sanitize($tAuthor));
        }
        $footerLine1[] = $trackerPart;
    }
    ?>
    <p><?= implode(' &bull; ', $footerLine1) ?></p>
    <?php
    $footerLine2 = [];
    if (($cfg['footer_os_enabled'] ?? '1') === '1' && !empty($cfg['footer_os_name'])) {
        $oName = sanitize($cfg['footer_os_name']);
        $oUrl = $cfg['footer_os_url'] ?? '';
        $osSinceYear = $cfg['footer_os_since_year'] ?? $startYear;
        $footerLine2[] = ($oUrl ? '<a href="' . sanitize($oUrl) . '" class="footer-link" target="_blank">' . $oName . '</a>' : $oName) . ' ' . __('footer.since') . ' ' . (int)$osSinceYear;
    }
    if (!empty($cfg['github_url'])) {
        $footerLine2[] = '<a href="' . sanitize($cfg['github_url']) . '" class="footer-link" target="_blank"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg> GitHub</a>';
    }
    $footerLine2[] = _h('footer.cc0');
    ?>
    <p><?= implode(' &bull; ', $footerLine2) ?></p>
    <?php
    // The build. Where it may appear is `version_display` (none | public | panel | both);
    // the default is the panel, because a version number on a public page tells a visitor
    // which published bugs to try, and an operator needs to know which build is serving.
    if (versionShown($cfg, $footerInPanel)): ?>
    <p class="footer-version"><?= _h('footer.version', ['v' => TRACKER_VERSION]) ?></p>
    <?php endif; ?>
    <?php if (!empty($recaptchaNeeded)): ?>
    <?= captchaNoticeHtml($cfg) ?>
    <?php endif; ?>
</footer>
