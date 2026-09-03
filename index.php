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
require_once __DIR__ . '/includes/richtext.php';
require_once __DIR__ . '/includes/reputation.php';
require_once __DIR__ . '/includes/wlmaint.php';
require_once __DIR__ . '/includes/wlprobe.php';
require_once __DIR__ . '/includes/schedule.php';
require_once __DIR__ . '/includes/stats_timeline.php';
require_once __DIR__ . '/includes/index.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twofa.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/federation.php';
require_once __DIR__ . '/includes/netlimit.php';
require_once __DIR__ . '/includes/backup.php';
require_once __DIR__ . '/includes/opentracker.php';
require_once __DIR__ . '/includes/sysctl.php';
require_once __DIR__ . '/includes/cluster.php';
// The page needs this too, not only api.php: templates/admin/traffic.php decides whether to load
// admin-tuner.js with `function_exists('tunerEnabled')`, and without the include that test is false
// on every page render — so the probe's card stayed hidden for ever while its endpoint answered
// "enabled: true" to anyone who asked it directly. A feature can be switched on, reachable, and
// still invisible.
require_once __DIR__ . '/includes/tuner.php';

$db = getDb();
// A ceiling on how long ONE query may run inside a web request. Not a substitute for writing the
// query properly — a bad plan is still a bug — but the difference between a bad plan costing one
// visitor an error page and it holding a php-fpm child until the whole site stops answering. The
// pool here has five children; one search that ran for twenty-four minutes was enough to take
// every page down, and each retry started another.
//
// SESSION only, and only for requests served over the web: the janitor, the metadata worker and
// mariadb-dump all run legitimately long statements from the CLI and must not be touched. MariaDB
// applies it to SELECTs; anything it cannot apply to is left alone.
if (PHP_SAPI !== 'cli') {
    try { $db->exec('SET SESSION max_statement_time = 20'); } catch (\Throwable $e) { /* older server: no such variable */ }
}

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
        // A panel session is no longer the same thing as the owner's session: a moderator holds one
        // too, and reaches only the pages their groups grant. Sending them to a page they may open
        // beats a bare 403 — but if they may open none, the panel is not for them at all.
        if (!adminPageAllowed($db, $cfg, $action)) {
            $first = adminNavItemsFor($db, $cfg);
            if ($first) { header('Location: ' . $baseUrl . '?action=' . $first[0]['action']); exit; }
            adminPanelSessionExpire();
            header('Location: ' . $baseUrl);
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
        } elseif ($action === 'admin-audit') {
            include __DIR__ . '/templates/admin/audit.php';
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
