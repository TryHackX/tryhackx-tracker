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
    // `perm` is the panel permission that opens this page. Settings has none on purpose: no
    // permission id exists for it, so only the owner's own session reaches it.
    return [
        ['action' => 'admin',           'label' => 'Reports',   'icon' => 'bi-flag',        'anchor' => '',                  'perm' => 'panel.reports.view'],
        ['action' => 'admin-whitelist', 'label' => 'Whitelist', 'icon' => 'bi-list-check',  'anchor' => '',                  'perm' => 'panel.whitelist.view'],
        ['action' => 'admin-index',     'label' => 'Index',     'icon' => 'bi-collection',  'anchor' => '#section-index',    'perm' => 'panel.whitelist.view'],
        ['action' => 'admin-traffic',   'label' => 'Traffic',   'icon' => 'bi-speedometer2','anchor' => '#section-netlimit', 'perm' => 'panel.traffic.view'],
        ['action' => 'admin-users',     'label' => 'Users',     'icon' => 'bi-people',      'anchor' => '#section-users',    'perm' => 'panel.users.view'],
        ['action' => 'admin-backups',   'label' => 'Backups',   'icon' => 'bi-archive',     'anchor' => '#section-backups',  'perm' => 'panel.backups.view'],
    ];
}

/** The nav items THIS panel session may open. The owner sees all of them. */
function adminNavItemsFor(PDO $db, array $cfg): array {
    if (!function_exists('panelCan')) return adminNavItems();
    return array_values(array_filter(adminNavItems(), fn($i) => panelCan($db, $cfg, $i['perm'])));
}

/** May this panel session open ?action=<$action>? Settings and the dashboard are owner-only. */
function adminPageAllowed(PDO $db, array $cfg, string $action): bool {
    if (!function_exists('panelCan')) return true;
    if ($action === 'settings') return panelCan($db, $cfg, 'panel.settings.__never__');
    foreach (adminNavItems() as $i) {
        if ($i['action'] === $action) return panelCan($db, $cfg, $i['perm']);
    }
    return panelCan($db, $cfg, 'panel.unknown.__never__');   // unknown page → owner only
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
            // Admin group OR panel.access: a moderator holds a panel session on the strength of a
            // granted permission rather than of admin membership. Losing either one closes the panel
            // on the next request, which is the property this block existed for.
            $stillAllowed = $u && $u['status'] === 'active'
                && (userIsAdminGroup($db, (int)$u['id'])
                    || (function_exists('userHasPanelAccess') && userHasPanelAccess($db, (int)$u['id'])));
            if (!$stillAllowed) {
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

/* ── re-confirming the password, and what happens when it keeps being wrong ──
 *
 * Every dangerous action in the panel asks for the password again. That check sat inline at fourteen
 * call sites as a bare `password_verify()`, which meant somebody who already HAD a session -- a
 * borrowed laptop, an unlocked screen, a stolen cookie -- could guess at it for as long as they
 * liked. The session gate stops an outsider; it does nothing about the person already inside, and
 * the second factor is exactly the case the password prompt exists for.
 *
 * So there is now one function, and it is the only way to check that password:
 *
 *   - every wrong answer costs progressively more time, starting immediately;
 *   - after `admin_reauth_max_attempts` the SESSION IS DESTROYED. Not the action refused -- the
 *     session. Getting back in means the sign-in page, which has the CAPTCHA and the IP lockout
 *     that this path deliberately does not duplicate;
 *   - failures count against that same IP lockout, so guessing here poisons the way back in rather
 *     than being a quiet side door around it.
 *
 * The counter lives in the session AND on disk. The session copy survives nothing, which is the
 * point; the disk copy is what makes a fresh cookie no help at all.
 */

function adminReauthMaxAttempts(array $cfg): int {
    return max(1, min(20, (int)($cfg['admin_reauth_max_attempts'] ?? 5) ?: 5));
}

/** How long a wrong answer costs, in microseconds: 0, 0.5 s, 1 s, 2 s, 4 s, capped at 8. */
function adminReauthDelayUs(int $failuresSoFar): int {
    if ($failuresSoFar <= 0) return 0;
    return (int)(min(8.0, 0.25 * (2 ** $failuresSoFar)) * 1000000);
}

/**
 * Check the admin password for a dangerous action.
 *
 * Returns ['ok' => bool, 'error' => string, 'left' => int, 'locked_out' => bool]. On `locked_out`
 * the session is already gone by the time this returns and the caller must say so and stop.
 */
function adminReauth(string $password, array $cfg): array {
    $max = adminReauthMaxAttempts($cfg);
    $failed = (int)($_SESSION['reauth_failures'] ?? 0);

    if ($password === '' || ADMIN_PASSWORD_HASH === '') {
        return ['ok' => false, 'error' => 'Password required', 'left' => max(0, $max - $failed),
                'locked_out' => false];
    }
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        unset($_SESSION['reauth_failures']);
        return ['ok' => true, 'error' => '', 'left' => $max, 'locked_out' => false];
    }

    $failed++;
    $_SESSION['reauth_failures'] = $failed;
    // Count it where the sign-in page will see it too: guessing in here must not be a way to avoid
    // the lockout that guards the front door.
    recordLoginFailure(getClientIp(), $cfg);
    usleep(adminReauthDelayUs($failed));

    if ($failed >= $max) {
        logout();
        return ['ok' => false, 'left' => 0, 'locked_out' => true,
                'error' => 'Wrong password ' . $failed . ' times — you have been signed out. '
                         . 'Sign in again to continue; repeated failures there lock the address out.'];
    }
    $left = $max - $failed;
    return ['ok' => false, 'left' => $left, 'locked_out' => false,
            'error' => 'Wrong password. ' . $left . ' ' . ($left === 1 ? 'attempt' : 'attempts')
                     . ' left before this session is signed out.'];
}

/**
 * The whole check in one line for an endpoint: verifies, or sends the reply and exits.
 *
 * Deliberately exits rather than returning false. An endpoint that forgot to check the return value
 * would carry out the action with a wrong password, and that is not a mistake this code should leave
 * available.
 */
function requireAdminReauth(string $password, array $cfg): void {
    $r = adminReauth($password, $cfg);
    if ($r['ok']) return;
    jsonResponse(['error' => $r['error'], 'signed_out' => $r['locked_out'],
                  'attempts_left' => $r['left']], $r['locked_out'] ? 401 : 403);
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
