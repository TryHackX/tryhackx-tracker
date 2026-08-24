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

if ($action === 'admin' || $action === 'settings' || $action === 'admin-whitelist' || $action === 'admin-index' || $action === 'admin-users') {
    if (adminSessionValid($cfg)) {
        if ($action === 'settings') {
            include __DIR__ . '/templates/admin/settings.php';
        } elseif ($action === 'admin-whitelist') {
            include __DIR__ . '/templates/admin/whitelist.php';
        } elseif ($action === 'admin-index') {
            include __DIR__ . '/templates/admin/index_page.php';
        } elseif ($action === 'admin-users') {
            include __DIR__ . '/templates/admin/users.php';
        } else {
            include __DIR__ . '/templates/admin/dashboard.php';
        }
    } else {
        include __DIR__ . '/templates/admin/login.php';
    }
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
