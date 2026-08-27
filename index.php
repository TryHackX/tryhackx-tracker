<?php
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_samesite' => 'Lax',
]);

// Check if installed
if (!file_exists(__DIR__ . '/config/installed.lock')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/whitelist.php';
require_once __DIR__ . '/includes/schedule.php';
require_once __DIR__ . '/includes/stats_timeline.php';
require_once __DIR__ . '/includes/index.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/federation.php';
require_once __DIR__ . '/includes/netlimit.php';
require_once __DIR__ . '/includes/backup.php';

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
autoArchiveOldReports($db, $cfg);
autoArchiveOldAppeals($db, $cfg);
pruneOldSentEmails($db, $cfg);
whitelistJanitor($db, $cfg);
$csrfToken = generateCsrfToken();

$action = $_GET['action'] ?? 'home';
$action = preg_replace('/[^a-z0-9_-]/', '', strtolower($action));

$routes = [
    'home'         => 'templates/pages/home.php',
    'info'         => 'templates/pages/info.php',
    'tos'          => 'templates/pages/tos.php',
    'report'       => 'templates/pages/report.php',
    'status'       => 'templates/pages/status.php',
    'transparency' => 'templates/pages/transparency.php',
    'unsubscribe'  => 'templates/pages/unsubscribe.php',
    'stats'        => 'templates/pages/stats.php',
    'whitelist'    => 'templates/pages/whitelist.php',
    'login'        => 'templates/pages/login.php',
    'register'     => 'templates/pages/register.php',
    'account'      => 'templates/pages/account.php',
    'reset'        => 'templates/pages/reset.php',
    'verify'       => 'templates/pages/verify.php',
    'emailchange'  => 'templates/pages/emailchange.php',
    'search'       => 'templates/pages/search.php',
];

$baseUrl = getBaseUrl();

// ── Admin panel ──
// The sign-in form lives at ?action=<admin_login_path> ('admin' by default, movable to an
// unguessable address). The panel pages keep their classic actions so every internal link and
// bookmark survives a path change; a signed-out visitor on any OTHER panel URL is answered by
// adminHiddenBehavior() — by default sent to the front page rather than shown a login form.
$adminLoginAction = adminLoginPath($cfg);
$adminPanelActions = adminPanelActions();
if (in_array($action, $adminPanelActions, true) || $action === $adminLoginAction) {
    if (adminSessionValid($cfg)) {
        if (!in_array($action, $adminPanelActions, true)) {   // the sign-in address leads to the dashboard
            header('Location: ' . $baseUrl . '?action=admin');
            exit;
        }
        if ($action === 'settings') {
            include __DIR__ . '/templates/admin/settings.php';
        } elseif ($action === 'admin-whitelist') {
            include __DIR__ . '/templates/admin/whitelist.php';
        } elseif ($action === 'admin-index') {
            include __DIR__ . '/templates/admin/index_page.php';
        } elseif ($action === 'admin-users') {
            include __DIR__ . '/templates/admin/users.php';
        } elseif ($action === 'admin-backups') {
            include __DIR__ . '/templates/admin/backups.php';
        } elseif ($action === 'admin-traffic') {
            include __DIR__ . '/templates/admin/traffic.php';
        } else {
            include __DIR__ . '/templates/admin/dashboard.php';
        }
        exit;
    }
    $behavior = adminHiddenBehavior($cfg);
    if ($action === $adminLoginAction || $behavior === 'login') {
        $action = 'adminlogin';
        $pageTemplate = __DIR__ . '/templates/pages/adminlogin.php';
        include __DIR__ . '/templates/layout.php';
        exit;
    }
    if ($behavior === '404') {
        http_response_code(404);
        $action = 'notfound';
        $pageTemplate = __DIR__ . '/templates/pages/notfound.php';
        include __DIR__ . '/templates/layout.php';
        exit;
    }
    header('Location: ' . $baseUrl);
    exit;
}

// user pages exist only while the account system is on (and the account page needs a session)
if (in_array($action, ['login', 'register', 'account', 'reset', 'verify', 'emailchange', 'search'], true) && !usersEnabled($cfg)) {
    $action = 'home';
}

if (!isset($routes[$action])) {
    $action = 'home';
}

$pageTemplate = __DIR__ . '/' . $routes[$action];
include __DIR__ . '/templates/layout.php';
