<?php
// Titles come from the dictionary; the key is the action, so a page without one falls back to
// Home exactly as before rather than printing a dotted key into the browser tab.
$pageTitle = langHas('title.' . $action) ? __('title.' . $action) : __('title.home');
$recaptchaNeeded = ($action === 'report' && isCaptchaEnabled($cfg, 'report'))
    || ($action === 'status' && (isCaptchaEnabled($cfg, 'status') || isCaptchaEnabled($cfg, 'block_check') || isCaptchaEnabled($cfg, 'appeal')))
    || ($action === 'whitelist' && captchaConfigured($cfg))
    || (in_array($action, ['register', 'reset'], true) && captchaConfigured($cfg))
    || (in_array($action, ['login', 'adminlogin'], true) && isCaptchaEnabled($cfg, 'login'));
// swarm timeline chart (vendored uPlot) — only on the stats page when enabled, public and permitted
$timelineNeeded = ($action === 'stats' && ($cfg['tracker_stats_enabled'] ?? '0') === '1' && statsTimelineEnabled($cfg) && statsTimelinePublic($cfg)
    && userCan($db, $cfg, 'stats.timeline'));
// the logged-in user (null when the account system is off or nobody is signed in) — nav + pages use it
$navUser = usersEnabled($cfg) ? currentUser($db) : null;
?>
<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($action === 'apidocs'): ?>
    <?php /* Unlisted, not secret: it is handed to one partner in a mail, and a search engine
             indexing it would put an integration guide for this tracker in front of everybody who
             was not sent it. Nothing here is protected by that — the key is. */ ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= sanitize($pageTitle) ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl, LANG_JS_PUBLIC) ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/style.css<?= assetVer('assets/css/style.css') ?>">
    <!-- shared with the admin whitelist / index pages so the three "everything about one hash"
         panels look like each other (assets/css/detail-panel.css) -->
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/detail-panel.css<?= assetVer('assets/css/detail-panel.css') ?>">
    <?php if ($action === 'transparency' || $action === 'stats'): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <?php endif; ?>
    <?php if ($timelineNeeded): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/vendor/uplot/uPlot.min.css<?= assetVer('assets/vendor/uplot/uPlot.min.css') ?>">
    <?php endif; ?>
    <?php if ($recaptchaNeeded): ?>
    <?= captchaHeadTags($cfg) ?>
    <?php endif; ?>
</head>
<body<?= in_array($action, ['transparency', 'stats', 'search'], true) ? ' class="page-' . $action . '"' : '' ?>>
    <div class="container">
        <?php include __DIR__ . '/nav.php'; ?>
        <main>
            <?php include $pageTemplate; ?>
        </main>
        <?php include __DIR__ . '/footer.php'; ?>
    </div>
    <?php if ($recaptchaNeeded): ?>
    <div class="captcha-overlay" id="captcha-overlay">
        <div class="captcha-box">
            <p><?= _h('captcha.verify_human') ?></p>
            <div id="captcha-widget" class="captcha-widget"></div>
            <div class="captcha-actions"><button type="button" class="btn btn-secondary captcha-cancel" id="captcha-cancel"><?= _h('common.cancel') ?></button></div>
        </div>
    </div>
    <?php endif; ?>
    <script<?= nonceAttr() ?>>
    const APP_BASE = '<?= $baseUrl ?>';
    const APP_API = '<?= $baseUrl ?>api.php?endpoint=';
    <?php if (($cfg['contact_obfuscate'] ?? '0') === '1' && !empty($cfg['site_email'])): ?>
    const OBF_EMAIL = <?= obfuscateEmail($cfg['site_email']) ?>;
    <?php endif; ?>
    </script>
    <script src="<?= $baseUrl ?>assets/js/captcha.js<?= assetVer('assets/js/captcha.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/app.js<?= assetVer('assets/js/app.js') ?>"></script>
    <?php /* The star, the profile lists and the "who has this" overlay. Loaded on every public page
             for the same reason app.js is: the star is delegated from `document` and has to be there
             before the first row is drawn. Every entry point inside it asks whether its own markup
             exists first, so a page without any of this costs one querySelector. */ ?>
    <?php if (favEnabled($cfg) || profilesEnabled($cfg) || listsEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/favourites.js<?= assetVer('assets/js/favourites.js') ?>"></script>
    <?php endif; ?>
    <?php /* People: the inbox, friends and blocks, the directory, and the buttons a public profile
             grows. Same rule as above — every entry point asks for its own markup first. */ ?>
    <?php if (pmEnabled($cfg) || friendsEnabled($cfg) || directoryEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/people.js<?= assetVer('assets/js/people.js') ?>"></script>
    <?php endif; ?>
    <?php if ($timelineNeeded): ?>
    <script src="<?= $baseUrl ?>assets/vendor/uplot/uPlot.iife.min.js<?= assetVer('assets/vendor/uplot/uPlot.iife.min.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/stats-timeline.js<?= assetVer('assets/js/stats-timeline.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
