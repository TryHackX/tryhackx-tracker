<?php

function isLoggedIn(): bool {
    return !empty($_SESSION['loggedin']);
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

    if ($absMax > 0 && $loginTime > 0 && ($now - $loginTime) >= $absMax) { logout(); return false; }
    if ($idleMax > 0 && ($now - $lastSeen) >= $idleMax)                   { logout(); return false; }

    $_SESSION['last_activity'] = $now;
    return true;
}

function requireAuth(?array $cfg = null): void {
    $cfg = $cfg ?? ($GLOBALS['cfg'] ?? []);
    if (!adminSessionValid($cfg)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
}

function attemptLogin(string $username, string $password, array $cfg): bool {
    $adminUser = $cfg['admin_username'] ?? 'admin';
    // Always run password_verify (even on a wrong username) and compare both fields with
    // constant-time functions so response timing can't be used to enumerate the username.
    $passOk = password_verify($password, ADMIN_PASSWORD_HASH);
    $userOk = hash_equals($adminUser, $username);
    if ($userOk && $passOk) {
        session_regenerate_id(true);
        $_SESSION['loggedin'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
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
