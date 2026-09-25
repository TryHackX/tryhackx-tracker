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
// The account page's Picture and Cover drop zones (1.63.0) are the Emotes page's own, with the
// picture and cover editor's stylesheet and script behind them — only while either feature is on,
// so an account page without them costs nothing.
$mediaEditor = $action === 'account' && function_exists('userAvatarsEnabled') && (userAvatarsEnabled($cfg) || userCoversEnabled($cfg));
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
    <?php /* The icon font, on EVERY page (1.68.0). It used to come only with the handful of actions
             known to draw an icon, and anything drawn elsewhere — the inbox's bin with pictures and
             covers switched off — was an empty box. Which font is Settings → Site's choice.
             BEFORE the site's own stylesheets, as in the panel: Font Awesome styles the icon element
             itself (display, line-height), and a site rule of the same weight that places an icon
             must win over it the way it wins over Bootstrap Icons, which only styles ::before. */ ?>
    <?= iconFontTag($cfg) ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/style.css<?= assetVer('assets/css/style.css') ?>">
    <!-- shared with the admin whitelist / index pages so the three "everything about one hash"
         panels look like each other (assets/css/detail-panel.css) -->
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/detail-panel.css<?= assetVer('assets/css/detail-panel.css') ?>">
    <?php if ($timelineNeeded): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/vendor/uplot/uPlot.min.css<?= assetVer('assets/vendor/uplot/uPlot.min.css') ?>">
    <?php endif; ?>
    <?php if ($mediaEditor): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/media-editor.css<?= assetVer('assets/css/media-editor.css') ?>">
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
    <?php /* The two facts window.userAvatarUrl() needs to build the same address userAvatarUrl() does:
             whether pictures exist at all, and the site's default picture (its address prefix) when the
             owner chose one. Nothing about any account — those ride along with each row. */ ?>
    const APP_MEDIA = <?= json_encode(function_exists('userAvatarsEnabled')
        ? ['avatars' => userAvatarsEnabled($cfg),
           'def' => userAvatarDefaultMode($cfg) === 'image' ? substr(strtolower((string)$cfg['avatar_default_sha']), 0, 16) : '']
        : ['avatars' => false, 'def' => '']) ?>;
    <?php if (($cfg['contact_obfuscate'] ?? '0') === '1' && !empty($cfg['site_email'])): ?>
    const OBF_EMAIL = <?= obfuscateEmail($cfg['site_email']) ?>;
    <?php endif; ?>
    </script>
    <script src="<?= $baseUrl ?>assets/js/captcha.js<?= assetVer('assets/js/captcha.js') ?>"></script>
    <?php /* The picture beside every name (1.63.0), before everything that draws a row of people. */ ?>
    <script src="<?= $baseUrl ?>assets/js/avatar.js<?= assetVer('assets/js/avatar.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/app.js<?= assetVer('assets/js/app.js') ?>"></script>
    <?php /* The star, the profile lists and the "who has this" overlay. Loaded on every public page
             for the same reason app.js is: the star is delegated from `document` and has to be there
             before the first row is drawn. Every entry point inside it asks whether its own markup
             exists first, so a page without any of this costs one querySelector. */ ?>
    <?php /* …and the likes / ratings table (1.69.0) with the account page's tab router, which lives in
             the same file: a site with ratings and nothing else still has that tab to switch to. */ ?>
    <?php if (favEnabled($cfg) || profilesEnabled($cfg) || listsEnabled($cfg) || soundsEnabled($cfg)
              || (function_exists('profileVotesEnabled') && profileVotesEnabled($cfg))): ?>
    <script src="<?= $baseUrl ?>assets/js/favourites.js<?= assetVer('assets/js/favourites.js') ?>"></script>
    <?php endif; ?>
    <?php /* People: the inbox, friends and blocks, the directory, and the buttons a public profile
             grows. Same rule as above — every entry point asks for its own markup first. */ ?>
    <?php if (pmEnabled($cfg) || friendsEnabled($cfg) || directoryEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/people.js<?= assetVer('assets/js/people.js') ?>"></script>
    <?php endif; ?>
    <?php /* Sounds: the badge's data-sounds says whether this reader hears anything; the account page's
             tab lives in the same file. Loaded only while the feature is on at all. */ ?>
    <?php if (soundsEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/sounds.js<?= assetVer('assets/js/sounds.js') ?>"></script>
    <?php endif; ?>
    <?php /* The shoutbox: the widget on the front page and the page of its own are one script, and
             it begins by asking whether #shoutbox is on this page at all. function_exists() as well
             as the setting, so the page still renders while the feature's own half is landing. */ ?>
    <?php if (function_exists('shoutEnabled') && shoutEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/shoutbox.js<?= assetVer('assets/js/shoutbox.js') ?>"></script>
    <?php endif; ?>
    <?php /* The account page's security block: the second factor and the signed-in devices. Only
             on that page — both halves are drawn nowhere else, and there is no reason for every
             visitor to carry them. */ ?>
    <?php if ($action === 'account'): ?>
    <script src="<?= $baseUrl ?>assets/js/account-security.js<?= assetVer('assets/js/account-security.js') ?>"></script>
    <?php endif; ?>
    <?php /* The picture and cover editor: the account page only, and only while either feature is on. */ ?>
    <?php if ($mediaEditor): ?>
    <script src="<?= $baseUrl ?>assets/js/media-editor.js<?= assetVer('assets/js/media-editor.js') ?>"></script>
    <?php endif; ?>
    <?php /* The description's editor in place (1.69.0): a profile page only, while the feature is on. It
             begins by asking whether this page is its reader's own editable one. */ ?>
    <?php if ($action === 'u' && function_exists('profileBioEnabled') && profileBioEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/profile-bio.js<?= assetVer('assets/js/profile-bio.js') ?>"></script>
    <?php endif; ?>
    <?php if ($timelineNeeded): ?>
    <script src="<?= $baseUrl ?>assets/vendor/uplot/uPlot.iife.min.js<?= assetVer('assets/vendor/uplot/uPlot.iife.min.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/stats-timeline.js<?= assetVer('assets/js/stats-timeline.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
