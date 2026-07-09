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
require_once __DIR__ . '/includes/auth.php';

$db = getDb();
$cfg = getSettings($db);
autoArchiveOldReports($db, $cfg);
autoArchiveOldAppeals($db, $cfg);
pruneOldSentEmails($db, $cfg);
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
];

$baseUrl = getBaseUrl();

if ($action === 'admin' || $action === 'settings') {
    if (adminSessionValid($cfg)) {
        if ($action === 'settings') {
            include __DIR__ . '/templates/admin/settings.php';
        } else {
            include __DIR__ . '/templates/admin/dashboard.php';
        }
    } else {
        include __DIR__ . '/templates/admin/login.php';
    }
    exit;
}

if (!isset($routes[$action])) {
    $action = 'home';
}

$pageTemplate = __DIR__ . '/' . $routes[$action];
include __DIR__ . '/templates/layout.php';
