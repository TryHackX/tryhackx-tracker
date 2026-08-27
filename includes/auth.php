<?php

function isLoggedIn(): bool {
    return !empty($_SESSION['loggedin']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Where the panel lives (Settings → Admin Access & Sessions)
// ─────────────────────────────────────────────────────────────────────────────
// `admin_login_path` is the ?action= value that shows the sign-in form — 'admin' by default, but it
// can be moved to something unguessable (?action=admin123yzx). The panel pages themselves keep their
// classic actions (admin / settings / admin-index / admin-users / admin-whitelist) so every internal
// link and bookmark still works once signed in; for a signed-out visitor those URLs answer according
// to `admin_hidden_behavior` instead of handing out a login form.

/** ?action= values a custom sign-in path must not shadow (public pages + the panel's own actions). */
function adminReservedActions(): array {
    return ['home', 'info', 'tos', 'report', 'status', 'transparency', 'unsubscribe', 'stats',
            'whitelist', 'login', 'register', 'account', 'reset', 'verify', 'emailchange', 'search',
            'settings', 'admin-whitelist', 'admin-index', 'admin-users', 'admin-backups',
            'admin-traffic', 'notfound'];
}

/** The panel actions that exist regardless of where the sign-in form lives. */
function adminPanelActions(): array {
    return ['admin', 'settings', 'admin-whitelist', 'admin-index', 'admin-users', 'admin-backups',
            'admin-traffic'];
}

/**
 * The panel's navigation bar, in the order it is shown. Every page renders the WHOLE list through
 * templates/admin/_header_actions.php, current page included and marked active — each template used
 * to carry its own hand-edited copy with its own link simply deleted, which is why you could not see
 * where you were, and why the dashboard linked to no sub-page at all.
 *
 * `anchor` is the Settings deep link to use while that page is open, so "Settings" still lands on
 * the section you were just looking at.
 */
function adminNavItems(): array {
    return [
        ['action' => 'admin',           'label' => 'Reports',   'icon' => 'bi-flag',        'anchor' => ''],
        ['action' => 'admin-whitelist', 'label' => 'Whitelist', 'icon' => 'bi-list-check',  'anchor' => ''],
        ['action' => 'admin-index',     'label' => 'Index',     'icon' => 'bi-collection',  'anchor' => '#section-index'],
        ['action' => 'admin-traffic',   'label' => 'Traffic',   'icon' => 'bi-speedometer2','anchor' => '#section-netlimit'],
        ['action' => 'admin-users',     'label' => 'Users',     'icon' => 'bi-people',      'anchor' => '#section-users'],
        ['action' => 'admin-backups',   'label' => 'Backups',   'icon' => 'bi-archive',     'anchor' => '#section-backups'],
    ];
}

/** The ?action= value that opens the admin sign-in form (default 'admin'; garbage falls back). */
function adminLoginPath(array $cfg): string {
    $p = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)($cfg['admin_login_path'] ?? 'admin'))));
    if ($p === null || $p === '' || strlen($p) > 64) return 'admin';
    if (in_array($p, adminReservedActions(), true)) return 'admin';
    return $p;
}

/** True when the panel has been moved off the default ?action=admin address. */
function adminLoginPathCustom(array $cfg): bool { return adminLoginPath($cfg) !== 'admin'; }

/**
 * What a signed-out visitor gets on a panel URL that is NOT the sign-in path:
 *   'home'  redirect to the front page (default — the panel leaves no trace)
 *   'login' show the sign-in form on every panel URL (classic behaviour)
 *   '404'   a site-styled 404 page with a 404 status
 */
function adminHiddenBehavior(array $cfg): string {
    $m = (string)($cfg['admin_hidden_behavior'] ?? 'home');
    return in_array($m, ['home', 'login', '404'], true) ? $m : 'home';
}

/**
 * Full admin-session check: logged in AND within the idle + absolute lifetime limits. An expired
 * session is destroyed here so a stolen/forgotten session cookie can't be used indefinitely.
 *   - admin_session_idle_minutes    : max gap between requests (rolling; refreshed each call)
 *   - admin_session_absolute_hours  : hard cap since login, regardless of activity
 * Either limit set to 0 disables that particular check.
 */
function adminSessionValid(array $cfg): bool {
    if (empty($_SESSION['loggedin'])) return false;

    $now       = time();
    $loginTime = (int)($_SESSION['login_time'] ?? 0);
    $lastSeen  = (int)($_SESSION['last_activity'] ?? $loginTime);
    $idleMax   = max(0, (int)($cfg['admin_session_idle_minutes'] ?? 30)) * 60;
    $absMax    = max(0, (int)($cfg['admin_session_absolute_hours'] ?? 12)) * 3600;

    if ($absMax > 0 && $loginTime > 0 && ($now - $loginTime) >= $absMax) { adminPanelSessionExpire(); return false; }
    if ($idleMax > 0 && ($now - $lastSeen) >= $idleMax)                   { adminPanelSessionExpire(); return false; }

    // panel session opened via the site sign-in of an admin-group user: the grant only lives as
    // long as that user is still active AND still in the admin group (a revoke/ban takes effect
    // on the next panel request, not at the next login). Never invalidate the classic session.
    if (!empty($_SESSION['admin_via_user']) && function_exists('userIsAdminGroup') && function_exists('getDb')) {
        try {
            $db = getDb();
            $u = userFindById($db, (int)$_SESSION['admin_via_user']);
            if (!$u || $u['status'] !== 'active' || !userIsAdminGroup($db, (int)$u['id'])) {
                unset($_SESSION['admin_via_user'], $_SESSION['loggedin'], $_SESSION['login_time'], $_SESSION['last_activity']);
                return false;
            }
        } catch (\Throwable $e) { /* DB hiccup — fall through, the idle limits still guard */ }
    }

    $_SESSION['last_activity'] = $now;
    return true;
}

/**
 * End the PANEL part of the session on an EXPLICIT logout (api/admin/logout.php). A panel session
 * piggy-backing on a user sign-in (admin_via_user) must NOT destroy the whole PHP session — the owner
 * would be logged out of the public site too. The classic password panel session keeps the historical
 * full logout().
 */
function adminPanelSessionDrop(): void {
    if (!empty($_SESSION['admin_via_user'])) {
        unset($_SESSION['admin_via_user'], $_SESSION['loggedin'], $_SESSION['login_time'], $_SESSION['last_activity']);
        return;
    }
    logout();
}

/**
 * The panel session TIMED OUT (idle / absolute cap). Unlike an explicit logout this must NOT destroy
 * the session: index.php renders the sign-in form in the very same request, and a destroyed session
 * throws away both the CSRF token that form prints and the sign-in marker it stores — the admin's
 * first attempt would then fail with "this login page had expired". Dropping the panel keys and
 * rotating the id gives the same security property (a stolen pre-timeout cookie is worthless).
 */
function adminPanelSessionExpire(): void {
    unset($_SESSION['admin_via_user'], $_SESSION['loggedin'], $_SESSION['login_time'], $_SESSION['last_activity']);
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

function requireAuth(?array $cfg = null): void {
    $cfg = $cfg ?? ($GLOBALS['cfg'] ?? []);
    if (!adminSessionValid($cfg)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
}

/**
 * Are these the right credentials? Grants nothing.
 *
 * Split out because with two-factor authentication the password is only the first half: the session
 * must not exist until the second half is done, or a stolen password would be enough to reach the
 * panel for as long as it took to notice.
 */
function adminCredentialsValid(string $username, string $password, array $cfg): bool {
    $adminUser = $cfg['admin_username'] ?? 'admin';
    // Always run password_verify (even on a wrong username) and compare both fields with
    // constant-time functions so response timing can't be used to enumerate the username.
    $passOk = password_verify($password, ADMIN_PASSWORD_HASH);
    $userOk = hash_equals($adminUser, $username);
    return $userOk && $passOk;
}

/** Actually sign in. Call only once every factor has been satisfied. */
function adminGrantSession(): void {
    session_regenerate_id(true);
    unset($_SESSION['2fa_pending']);
    $_SESSION['loggedin'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
}

function attemptLogin(string $username, string $password, array $cfg): bool {
    if (!adminCredentialsValid($username, $password, $cfg)) return false;
    adminGrantSession();
    return true;
}

/** How long the half-finished login may sit waiting for a code. */
const TWOFA_PENDING_TTL = 300;

function twofaPendingStart(): void {
    // Regenerated here too: the id that carried the password must not be the one that carries the
    // session afterwards, and an attacker who fixed the id before the login gets nothing from it.
    session_regenerate_id(true);
    $_SESSION['2fa_pending'] = time();
}
function twofaPendingActive(): bool {
    $at = (int)($_SESSION['2fa_pending'] ?? 0);
    if ($at <= 0) return false;
    if (time() - $at > TWOFA_PENDING_TTL) { unset($_SESSION['2fa_pending']); return false; }
    return true;
}

// --- Brute-force throttle (file-based, per client IP) -----------------------
// Self-contained: no DB schema change. State lives in config/login_attempts.json
// (that directory is denied to the web by config/.htaccess).

function loginAttemptsFile(): string {
    return __DIR__ . '/../config/login_attempts.json';
}

/** Returns [allData, timestampsForIp] with entries older than the window pruned. */
function loginThrottleState(string $ip, int $windowSec): array {
    $file = loginAttemptsFile();
    $data = [];
    if (is_file($file)) {
        $raw  = @file_get_contents($file);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    $now = time();
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array)$times, fn($t) => ($now - (int)$t) < $windowSec));
        if (empty($data[$k])) unset($data[$k]);
    }
    return [$data, $data[$ip] ?? []];
}

function loginLockWindowSec(array $cfg): int {
    return max(1, (int)($cfg['login_lockout_minutes'] ?? 15)) * 60;
}

function isLoginLocked(string $ip, array $cfg): bool {
    $max = max(1, (int)($cfg['login_lockout_attempts'] ?? 5));
    [, $times] = loginThrottleState($ip, loginLockWindowSec($cfg));
    return count($times) >= $max;
}

function recordLoginFailure(string $ip, array $cfg): void {
    [$data, $times] = loginThrottleState($ip, loginLockWindowSec($cfg));
    $times[] = time();
    $data[$ip] = $times;
    @file_put_contents(loginAttemptsFile(), json_encode($data), LOCK_EX);
}

function clearLoginFailures(string $ip): void {
    $file = loginAttemptsFile();
    if (!is_file($file)) return;
    $raw  = @file_get_contents($file);
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    if (isset($data[$ip])) {
        unset($data[$ip]);
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
