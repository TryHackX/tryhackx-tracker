<?php
// session_start() used to be the first statement in this file. It is now further down, immediately
// after ensureSchema(), because the Secure flag on the session cookie is a setting and a setting
// lives in the database. See the block there.

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
require_once __DIR__ . '/includes/favourites.php';
require_once __DIR__ . '/includes/lists.php';
// The partner guide (?action=apidocs) cleans its own query string through the SAME function
// the endpoint uses, so an operator cannot be shown a page describing fields the API would
// not actually require. Without this the page silently fell back to 'nothing beyond the hash'.
require_once __DIR__ . '/includes/api_auth.php';
require_once __DIR__ . '/includes/authbridge.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/federation.php';
require_once __DIR__ . '/includes/pagecontent.php';
require_once __DIR__ . '/includes/homelayout.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/netlimit.php';
require_once __DIR__ . '/includes/iplist.php';
require_once __DIR__ . '/includes/backup.php';
require_once __DIR__ . '/includes/opentracker.php';
require_once __DIR__ . '/includes/sysctl.php';
require_once __DIR__ . '/includes/dbmem.php';
require_once __DIR__ . '/includes/cluster.php';
// The page needs this too, not only api.php: templates/admin/traffic.php decides whether to load
// admin-tuner.js with `function_exists('tunerEnabled')`, and without the include that test is false
// on every page render — so the probe's card stayed hidden for ever while its endpoint answered
// "enabled: true" to anyone who asked it directly. A feature can be switched on, reachable, and
// still invisible.
require_once __DIR__ . '/includes/tuner.php';

// MariaDB being down used to be a blank 500 from an uncaught PDOException -- the one page that ought
// to say "come back in a minute" said nothing at all, and said it slowly (the connect waited for the
// TCP timeout). A 503 with Retry-After is what a crawler, a load balancer and a person all understand,
// and the template behind it touches nothing but the dictionary, so it cannot fail the same way.
// One log line per failed request: the message names the host and the reason, and that is what the
// operator greps for at three in the morning.
try {
    $db = getDb();
} catch (PDOException $e) {
    error_log('[tracker] database unavailable: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 60');
    // A fixed policy, and a literal one: $cfg does not exist here (that is why this branch is being
    // taken), so cspPolicy() cannot be called without turning a database outage into a fatal on the
    // one page whose entire job is to still work. The template is a <style> block and no script.
    header('Content-Security-Policy: ' . CSP_MAINTENANCE);
    include __DIR__ . '/templates/maintenance.php';
    exit;
}
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

// THE SESSION STARTS HERE, and nothing above it may read $_SESSION.
//
// It used to be line 2, where its Secure flag was `!empty($_SERVER['HTTPS'])`. On this deployment
// that was correct (measured 2026-09-08: PHP sees HTTPS='on'), but it is correct only while nothing
// terminates TLS ahead of nginx — to that expression a Cloudflare or load-balancer deployment is a
// plain-HTTP site. cookie_secure_mode answers the question properly, and it is a setting, so the
// call had to come down below getSettings().
//
// Verified before moving it: no file under includes/ touches $_SESSION at file scope, and the
// database-down branch above renders templates/maintenance.php, which uses neither a session nor a
// CSRF token. The move does create a failure class that could not exist at line 2 — a byte of
// output before this line (a deprecation with display_errors on, a BOM, a warning out of a
// migration) makes the Set-Cookie unsendable and nobody can sign in. A log line rather than a
// fatal: a panel that still renders is what lets the operator read the log line.
if (headers_sent($hsFile, $hsLine)) {
    error_log('[tracker] output began at ' . $hsFile . ':' . $hsLine . ' before session_start — the session cookie cannot be set');
}
session_start(sessionCookieParams($cfg));
sendSecurityHeaders($cfg);

autoArchiveOldReports($db, $cfg);
autoArchiveOldAppeals($db, $cfg);
pruneOldSentEmails($db, $cfg);
whitelistJanitor($db, $cfg);
$csrfToken = generateCsrfToken();

// BEFORE any output: langInit() may set the language cookie, and a cookie after the first byte
// is a cookie that never arrives. The signed-in user's saved choice is passed in so the
// language follows the account rather than the browser -- currentUser() is already cached by
// includes/users.php, so this costs nothing extra.
$langUser = usersEnabled($cfg) ? currentUser($db) : null;
langInit($cfg, $langUser['language'] ?? null);

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
    // A PROFILE'S ADDRESS PUTS THE NAME IN ITS OWN PARAMETER, and the reason is two lines above:
    // $action is lower-cased and stripped of everything but [a-z0-9_-], while userValidUsername()
    // allows a dot and both cases. A name can never be an action, so the collision problem does not
    // arise — 'Bob.Smith' would have become 'bobsmith', a different person or nobody.
    'u'            => 'templates/pages/profile.php',
    // The partner integration guide. Unlisted rather than locked: nothing on it is secret — it is
    // the shape of a public API — and the key it documents travels separately, from a person. It
    // reads its own configuration out of the query string, so the operator hands a partner an
    // address that describes THEIR key rather than one page covering every combination.
    'apidocs'      => 'templates/pages/apidocs.php',
];

$baseUrl = getBaseUrl();

// ── The sign-in bridge ──
// Before the router, because neither of its two addresses renders anything: one spends a one-time
// ticket and sets a session cookie, the other mints one and leaves. Both end in a redirect, and a
// redirect after the first byte of a page is a redirect that never happens.
authBridgeHandleRoute($db, $cfg, $action, $baseUrl);

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
        // The panel gets its own policy: Bootstrap's bundle comes from jsDelivr on every panel page
        // (script-src), the page-content preview frames itself (frame-src 'self'), and nothing may
        // frame the panel (frame-ancestors 'none'). sendSecurityHeaders() already sent the public
        // one above; header() replaces it, so the response carries exactly one.
        cspSend($cfg, 'panel');
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
if (in_array($action, ['login', 'register', 'account', 'reset', 'verify', 'emailchange', 'search', 'u'], true) && !usersEnabled($cfg)) {
    $action = 'home';
}

if (!isset($routes[$action])) {
    $action = 'home';
}

$pageTemplate = __DIR__ . '/' . $routes[$action];

// An operator's replacement for a shipped page (Settings → Site pages). Only ever an override: the
// template stays the default, so a page that was never edited costs one query that finds nothing and
// "restore" is a delete rather than a copy that has to be kept in step.
$customPage = null;
if (function_exists('pageContentActive') && in_array($action, PAGECONTENT_PAGES, true)) {
    $customPage = pageContentActive($db, $action, $cfg);
    if ($customPage) $pageTemplate = __DIR__ . '/templates/pages/_custom.php';
}

include __DIR__ . '/templates/layout.php';
