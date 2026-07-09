<?php
$pageTitles = [
    'home' => 'Home', 'info' => 'Info', 'tos' => 'Terms',
    'report' => 'Report', 'status' => 'Status',
    'transparency' => 'Transparency', 'unsubscribe' => 'Unsubscribe',
];
$recaptchaNeeded = ($action === 'report' && isRecaptchaEnabled($cfg, 'report'))
    || ($action === 'status' && (isRecaptchaEnabled($cfg, 'status') || isRecaptchaEnabled($cfg, 'block_check') || isRecaptchaEnabled($cfg, 'appeal')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitles[$action] ?? 'Home') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/style.css<?= assetVer('assets/css/style.css') ?>">
    <?php if ($action === 'transparency' || $action === 'stats'): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <?php endif; ?>
    <?php if ($recaptchaNeeded): ?>
    <script src="https://www.google.com/recaptcha/api.js?onload=onRecaptchaLoad&render=explicit" async defer></script>
    <?php endif; ?>
</head>
<body<?= ($action === 'transparency' || $action === 'stats') ? ' class="page-' . $action . '"' : '' ?>>
    <div class="container">
        <?php include __DIR__ . '/nav.php'; ?>
        <main>
            <?php include $pageTemplate; ?>
        </main>
        <footer>
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
                $trackerPart = 'Powered by ' . ($tUrl ? '<a href="' . sanitize($tUrl) . '" class="footer-link" target="_blank">' . $tName . '</a>' : $tName);
                if ($tAuthor) {
                    $trackerPart .= ' by ' . ($tAuthorUrl ? '<a href="' . sanitize($tAuthorUrl) . '" class="footer-link" target="_blank">' . sanitize($tAuthor) . '</a>' : sanitize($tAuthor));
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
                $footerLine2[] = ($oUrl ? '<a href="' . sanitize($oUrl) . '" class="footer-link" target="_blank">' . $oName . '</a>' : $oName) . ' since ' . (int)$osSinceYear;
            }
            if (!empty($cfg['github_url'])) {
                $footerLine2[] = '<a href="' . sanitize($cfg['github_url']) . '" class="footer-link" target="_blank"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg> GitHub</a>';
            }
            $footerLine2[] = 'Content rights waived via CC0';
            ?>
            <p><?= implode(' &bull; ', $footerLine2) ?></p>
        </footer>
    </div>
    <?php if ($recaptchaNeeded): ?>
    <div class="captcha-overlay" id="captcha-overlay">
        <div class="captcha-box">
            <p>Please verify you are human</p>
            <div id="captcha-widget" class="captcha-widget"></div>
        </div>
    </div>
    <?php endif; ?>
    <script>
    const APP_BASE = '<?= $baseUrl ?>';
    const APP_API = '<?= $baseUrl ?>api.php?endpoint=';
    <?php if ($recaptchaNeeded): ?>
    const RECAPTCHA_SITEKEY = '<?= sanitize($cfg['recaptcha_site_key'] ?? '') ?>';
    <?php endif; ?>
    <?php if (($cfg['contact_obfuscate'] ?? '0') === '1' && !empty($cfg['site_email'])): ?>
    const OBF_EMAIL = <?= obfuscateEmail($cfg['site_email']) ?>;
    <?php endif; ?>
    </script>
    <script src="<?= $baseUrl ?>assets/js/app.js<?= assetVer('assets/js/app.js') ?>"></script>
</body>
</html>
