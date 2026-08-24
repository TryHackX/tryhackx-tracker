<?php
/**
 * User accounts (schema v7): registration/login, groups with JSON permissions, timed memberships,
 * in-app notifications, remember-me and password-reset tokens.
 *
 * Everything is OFF unless users_enabled=1. Design:
 *   - The `guest` group is the permission BASELINE for every visitor — anonymous or logged in.
 *     A logged-in user adds the permissions of every ACTIVE group they hold (union; expired
 *     memberships are ignored and reaped by usersTick()).
 *   - With users_enabled=0 every check falls back to today's public behaviour: the index stays
 *     admin-only (index.* = false) and the classic public pages/stats stay visible (true). The
 *     feature toggles that already exist (tracker_stats_enabled, whitelist_public_enabled, …)
 *     remain master switches — permissions only ever narrow, never widen.
 *   - The single-admin panel session (includes/auth.php) bypasses permission checks entirely.
 *   - User sessions live in the same PHP session as everything else under their own keys
 *     (user_id / user_login_time), so an admin can also be logged in as a user.
 */

const USER_REMEMBER_COOKIE = 'thx_remember';
const USER_REMEMBER_DAYS   = 30;
const USER_RESET_TTL_MIN   = 120;   // password-reset link lifetime (minutes)

function usersEnabled(array $cfg): bool { return (($cfg['users_enabled'] ?? '0') === '1'); }
function usersRegistrationEnabled(array $cfg): bool { return usersEnabled($cfg) && (($cfg['users_registration_enabled'] ?? '1') === '1'); }
function usersLinksVisible(array $cfg): bool { return usersEnabled($cfg) && (($cfg['users_links_visible'] ?? '1') === '1'); }
function usersDefaultGroupSlug(array $cfg): string { return trim((string)($cfg['users_default_group'] ?? 'member')) ?: 'member'; }
function usersNotifyExpiryDays(array $cfg): int { return max(0, min(30, (int)($cfg['users_notify_expiry_days'] ?? 3))); }

/** Registry of every permission a group can carry. Key => human description (admin UI + docs). */
function userPermissionList(): array {
    return [
        'index.view'     => 'Search the observed-hash index (the public search page)',
        'index.files'    => 'See file lists in index search results',
        'index.magnet'   => 'See info hashes / copy magnet links in index search results',
        'whitelist.view' => 'Browse the public whitelist page',
        'stats.view'     => 'View the tracker statistics page',
        'stats.timeline' => 'See the statistics timeline chart',
        'home.stats'     => 'See the live stats widget on the home page',
    ];
}

/** What the site looks like with the user system disabled: index gated, the classic pages public. */
function userLegacyDefault(string $perm): bool {
    return !str_starts_with($perm, 'index.');
}

function userValidUsername(string $u): bool { return (bool)preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $u); }
function userValidEmail(string $e): bool { return $e !== '' && strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

// ─────────────────────────────────────────────────────────────────────────────
// Lookup / create / authenticate
// ─────────────────────────────────────────────────────────────────────────────

function userFindById(PDO $db, int $id): ?array {
    if ($id <= 0) return null;
    $st = $db->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

/** Find by username OR email (the login field accepts both). */
function userFindByLogin(PDO $db, string $login): ?array {
    $login = trim($login);
    if ($login === '' || strlen($login) > 190) return null;
    $st = $db->prepare("SELECT * FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1");
    $st->execute([$login, $login]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

/**
 * Create a user and grant the default group. Returns ['user'=>row] or ['error'=>code] with codes
 * invalid_username | invalid_email | weak_password | username_taken | email_taken.
 */
function userCreate(PDO $db, array $cfg, string $username, string $email, string $password, string $ip = '', string $grantedBy = 'registration'): array {
    $username = trim($username);
    $email = trim($email);
    if (!userValidUsername($username)) return ['error' => 'invalid_username'];
    if ($email !== '' && !userValidEmail($email)) return ['error' => 'invalid_email'];
    if (strlen($password) < 8 || strlen($password) > 200) return ['error' => 'weak_password'];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $st = $db->prepare("INSERT INTO users (username, email, pass_hash, created_ip) VALUES (?, ?, ?, ?)");
        $st->execute([$username, $email !== '' ? $email : null, $hash, $ip !== '' ? $ip : null]);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) {   // duplicate key — which one?
            $chk = $db->prepare("SELECT 1 FROM users WHERE username = ?");
            $chk->execute([$username]);
            return ['error' => $chk->fetchColumn() ? 'username_taken' : 'email_taken'];
        }
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    // default group(s): the configured slug plus anything flagged is_default
    $slugs = [usersDefaultGroupSlug($cfg)];
    $groups = $db->query("SELECT id, slug FROM user_groups WHERE is_default = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groups as $g) $slugs[] = $g['slug'];
    foreach (array_unique($slugs) as $slug) {
        if ($slug === 'guest') continue;   // guest is the implicit baseline, never a membership
        $g = userGroupBySlug($db, $slug);
        if ($g) userGrantGroup($db, $id, (int)$g['id'], null, $grantedBy, 'default group', false);
    }
    return ['user' => userFindById($db, $id)];
}

/** Verify credentials. Returns the user row (status checked by the caller) or null. */
function userAuthenticate(PDO $db, string $login, string $password): ?array {
    $u = userFindByLogin($db, $login);
    // burn ~the same time when the user does not exist
    $hash = $u['pass_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok = password_verify($password, $hash);
    return ($ok && $u) ? $u : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Session + remember-me
// ─────────────────────────────────────────────────────────────────────────────

function userSessionStart(PDO $db, array $user, string $ip = ''): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_login_time'] = time();
    $db->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?")
       ->execute([$ip !== '' ? $ip : null, (int)$user['id']]);
}

function userSessionLogout(PDO $db): void {
    userRememberClear($db);
    unset($_SESSION['user_id'], $_SESSION['user_login_time']);
    $GLOBALS['__current_user_cache'] = null;
    $GLOBALS['__current_user_loaded'] = false;
}

/** Issue a remember-me cookie (value "<id>.<64 hex>"; only the sha256 is stored). */
function userRememberIssue(PDO $db, int $userId): void {
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, 'remember', ?, NOW() + INTERVAL " . USER_REMEMBER_DAYS . " DAY)")
       ->execute([$userId, hash('sha256', $token)]);
    setcookie(USER_REMEMBER_COOKIE, $userId . '.' . $token, [
        'expires' => time() + USER_REMEMBER_DAYS * 86400,
        'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

function userRememberClear(PDO $db): void {
    $raw = (string)($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($raw !== '' && preg_match('/^(\d{1,10})\.([a-f0-9]{64})$/', $raw, $m)) {
        $db->prepare("DELETE FROM user_tokens WHERE type = 'remember' AND user_id = ? AND token_hash = ?")
           ->execute([(int)$m[1], hash('sha256', $m[2])]);
    }
    if ($raw !== '') {
        setcookie(USER_REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[USER_REMEMBER_COOKIE]);
    }
}

/** Try a remember-cookie login (called lazily from currentUser). Returns the user row or null. */
function userTryRememberLogin(PDO $db): ?array {
    $raw = (string)($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($raw === '' || !preg_match('/^(\d{1,10})\.([a-f0-9]{64})$/', $raw, $m)) return null;
    $st = $db->prepare("SELECT user_id FROM user_tokens WHERE type = 'remember' AND user_id = ? AND token_hash = ? AND expires_at >= NOW() AND used_at IS NULL");
    $st->execute([(int)$m[1], hash('sha256', $m[2])]);
    if (!$st->fetchColumn()) return null;
    $u = userFindById($db, (int)$m[1]);
    if (!$u || $u['status'] !== 'active') return null;
    // rotate: burn this token, hand out a fresh one (stolen-cookie replay shows up as a failed login)
    $db->prepare("UPDATE user_tokens SET used_at = NOW() WHERE type = 'remember' AND user_id = ? AND token_hash = ?")
       ->execute([(int)$m[1], hash('sha256', $m[2])]);
    // privilege elevation — regenerate the session id exactly like the password login path does,
    // so a pre-planted PHPSESSID (session fixation) never becomes an authenticated session
    if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_login_time'] = time();
    if (!headers_sent()) userRememberIssue($db, (int)$u['id']);
    return $u;
}

/** The logged-in user of this request, or null. Cached per request. */
function currentUser(PDO $db): ?array {
    if (!empty($GLOBALS['__current_user_loaded'])) return $GLOBALS['__current_user_cache'];
    $GLOBALS['__current_user_loaded'] = true;
    $GLOBALS['__current_user_cache'] = null;
    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    $u = null;
    if (!empty($_SESSION['user_id'])) {
        $u = userFindById($db, (int)$_SESSION['user_id']);
        if ($u && $u['status'] !== 'active') { unset($_SESSION['user_id'], $_SESSION['user_login_time']); $u = null; }
    }
    if ($u === null) $u = userTryRememberLogin($db);
    $GLOBALS['__current_user_cache'] = $u;
    return $u;
}

// ─────────────────────────────────────────────────────────────────────────────
// Groups + permissions
// ─────────────────────────────────────────────────────────────────────────────

function userGroupBySlug(PDO $db, string $slug): ?array {
    $st = $db->prepare("SELECT * FROM user_groups WHERE slug = ?");
    $st->execute([trim($slug)]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    return $g ?: null;
}

function userGroupPermissions(?string $json): array {
    $p = json_decode((string)$json, true);
    if (!is_array($p)) return [];
    $out = [];
    $known = userPermissionList();
    foreach ($p as $k => $v) { if (isset($known[$k]) && $v) $out[$k] = true; }
    return $out;
}

/** ACTIVE memberships of a user with the group rows joined (expired and not-yet-started ignored). */
function userGroups(PDO $db, int $userId): array {
    $st = $db->prepare(
        "SELECT g.*, m.granted_at, m.expires_at, m.granted_by, m.note
         FROM user_group_members m JOIN user_groups g ON g.id = m.group_id
         WHERE m.user_id = ? AND m.granted_at <= NOW() AND (m.expires_at IS NULL OR m.expires_at >= NOW())
         ORDER BY g.priority DESC, g.name");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Every membership of a user, active or not (admin view / account page). */
function userGroupsAll(PDO $db, int $userId): array {
    $st = $db->prepare(
        "SELECT g.slug, g.name, g.color, m.id AS member_id, m.group_id, m.granted_at, m.expires_at, m.granted_by, m.note,
                (m.granted_at <= NOW() AND (m.expires_at IS NULL OR m.expires_at >= NOW())) AS active
         FROM user_group_members m JOIN user_groups g ON g.id = m.group_id
         WHERE m.user_id = ? ORDER BY g.priority DESC, g.name");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['member_id'] = (int)$r['member_id']; $r['group_id'] = (int)$r['group_id']; $r['active'] = (bool)$r['active']; }
    return $rows;
}

/** Effective permission set: guest baseline + every active group of $userId (null = anonymous). */
function userEffectivePermissions(PDO $db, ?int $userId): array {
    static $cache = [];
    $key = (string)($userId ?? 0);
    if (isset($cache[$key])) return $cache[$key];
    $perms = [];
    $guest = userGroupBySlug($db, 'guest');
    if ($guest) $perms = userGroupPermissions($guest['permissions']);
    if ($userId) {
        foreach (userGroups($db, $userId) as $g) {
            $perms += userGroupPermissions($g['permissions']);
        }
    }
    return $cache[$key] = $perms;
}

/**
 * THE permission check. Admin panel session → always true. Users feature off → the legacy public
 * behaviour. Otherwise: guest baseline plus the current user's groups.
 */
function userCan(PDO $db, array $cfg, string $perm): bool {
    if (function_exists('isLoggedIn') && isLoggedIn()) return true;   // panel admin
    if (!usersEnabled($cfg)) return userLegacyDefault($perm);
    $u = currentUser($db);
    $perms = userEffectivePermissions($db, $u ? (int)$u['id'] : null);
    return !empty($perms[$perm]);
}

/**
 * Grant (or extend) a group. $expiresAt = 'Y-m-d H:i:s' or null (permanent). On an existing
 * membership the new expiry REPLACES the old one (callers implementing "extend by duration"
 * compute the new date from max(now, old expiry) themselves — see userDurationExpiry()).
 * Returns the membership summary. Sends an in-app notification unless $notify = false.
 */
function userGrantGroup(PDO $db, int $userId, int $groupId, ?string $expiresAt, string $grantedBy = '', string $note = '', bool $notify = true, ?string $grantedAt = null): array {
    $db->prepare(
        "INSERT INTO user_group_members (user_id, group_id, granted_at, expires_at, granted_by, note)
         VALUES (?, ?, COALESCE(?, NOW()), ?, ?, ?)
         ON DUPLICATE KEY UPDATE granted_at = VALUES(granted_at), expires_at = VALUES(expires_at),
                                 granted_by = VALUES(granted_by), note = VALUES(note), warned_at = NULL")
       ->execute([$userId, $groupId, $grantedAt, $expiresAt, mb_substr($grantedBy, 0, 64), mb_substr($note, 0, 255)]);
    $g = $db->prepare("SELECT name, slug FROM user_groups WHERE id = ?");
    $g->execute([$groupId]);
    $group = $g->fetch(PDO::FETCH_ASSOC) ?: ['name' => '?', 'slug' => '?'];
    if ($notify) {
        $when = ($grantedAt !== null ? 'from ' . $grantedAt . ' ' : '');
        $until = $expiresAt === null ? 'permanently' : 'until ' . $expiresAt;
        userNotify($db, $userId, 'group-granted', 'You are now in the "' . $group['name'] . '" group',
            'Access granted ' . $when . $until . '.' . ($note !== '' ? ' Note: ' . $note : ''));
    }
    return ['group' => $group['slug'], 'granted_at' => $grantedAt, 'expires_at' => $expiresAt];
}

function userRevokeGroup(PDO $db, int $userId, int $groupId, bool $notify = true): bool {
    $g = $db->prepare("SELECT name FROM user_groups WHERE id = ?");
    $g->execute([$groupId]);
    $name = (string)($g->fetchColumn() ?: '?');
    $st = $db->prepare("DELETE FROM user_group_members WHERE user_id = ? AND group_id = ?");
    $st->execute([$userId, $groupId]);
    if ($st->rowCount() > 0 && $notify) {
        userNotify($db, $userId, 'group-revoked', 'Your "' . $name . '" group access was removed');
        return true;
    }
    return $st->rowCount() > 0;
}

/**
 * Map a duration code to the new expiry, extending from the current membership when present.
 * A membership that is already PERMANENT (expires_at NULL) stays permanent — a duration grant
 * must never downgrade it to a timed one (repeat shop purchases would otherwise revoke access).
 */
function userDurationExpiry(PDO $db, int $userId, int $groupId, string $duration): ?string {
    $map = ['1d' => 'P1D', '7d' => 'P7D', '14d' => 'P14D', '1m' => 'P1M', '3m' => 'P3M', '6m' => 'P6M', '1y' => 'P1Y'];
    if ($duration === 'permanent') return null;
    if (!isset($map[$duration])) return '';   // '' = invalid (distinguish from null = permanent)
    $st = $db->prepare("SELECT expires_at FROM user_group_members WHERE user_id = ? AND group_id = ?");
    $st->execute([$userId, $groupId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);      // fetchColumn() can't tell "no row" from "NULL expiry"
    if ($row && $row['expires_at'] === null) return null;   // already permanent — keep it
    $base = new DateTime();
    if ($row && $row['expires_at'] !== null) {
        $curDt = new DateTime((string)$row['expires_at']);
        if ($curDt > $base) $base = $curDt;    // extend, don't restart
    }
    $base->add(new DateInterval($map[$duration]));
    return $base->format('Y-m-d H:i:s');
}

// ─────────────────────────────────────────────────────────────────────────────
// Notifications
// ─────────────────────────────────────────────────────────────────────────────

function userNotify(PDO $db, int $userId, string $type, string $title, string $body = ''): void {
    $db->prepare("INSERT INTO user_notifications (user_id, type, title, body) VALUES (?, ?, ?, ?)")
       ->execute([$userId, mb_substr($type, 0, 32), mb_substr($title, 0, 190), $body !== '' ? $body : null]);
}

/** Best-effort email copy of a notification (only when mail is set up and the user has an address). */
function userNotifyMail(PDO $db, array $cfg, array $user, string $subject, string $bodyText): void {
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !function_exists('sendEmail')) return;
    if (function_exists('isUnsubscribed') && isUnsubscribed($db, $email, 'account')) return;
    try {
        ob_start();
        $html = buildEmailHtml(['title' => $subject, 'greeting' => 'Hello ' . $user['username'] . ',', 'body' => $bodyText, 'details' => []], $cfg);
        @sendEmail($email, $subject, $bodyText, $html, $cfg);
        ob_end_clean();
    } catch (\Throwable $e) { if (ob_get_level()) ob_end_clean(); }
}

function userUnreadCount(PDO $db, int $userId): int {
    $st = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND read_at IS NULL");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

// ─────────────────────────────────────────────────────────────────────────────
// Password reset
// ─────────────────────────────────────────────────────────────────────────────

function userResetCreate(PDO $db, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $db->prepare("DELETE FROM user_tokens WHERE type = 'reset' AND user_id = ?")->execute([$userId]);
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, 'reset', ?, NOW() + INTERVAL " . USER_RESET_TTL_MIN . " MINUTE)")
       ->execute([$userId, hash('sha256', $token)]);
    return $token;
}

/** Validate a reset token; returns the user id or null. $burn marks it used (do this on success only). */
function userResetConsume(PDO $db, string $token, bool $burn): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $db->prepare("SELECT id, user_id FROM user_tokens WHERE type = 'reset' AND token_hash = ? AND expires_at >= NOW() AND used_at IS NULL");
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ($burn) $db->prepare("UPDATE user_tokens SET used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
    return (int)$row['user_id'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Janitor tick
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every-minute janitor tick: reap expired memberships (+ notification), warn before expiry, prune
 * old notifications and dead tokens (hourly). Cheap on the indexes; never throws.
 */
function usersTick(PDO $db, array $cfg, ?int $now = null): array {
    $out = ['enabled' => usersEnabled($cfg), 'expired' => 0, 'warned' => 0, 'pruned' => 0, 'error' => null];
    if (!$out['enabled']) return $out;
    try {
        // expired memberships → drop + notify
        $st = $db->query(
            "SELECT m.id, m.user_id, g.name FROM user_group_members m JOIN user_groups g ON g.id = m.group_id
             WHERE m.expires_at IS NOT NULL AND m.expires_at < NOW() LIMIT 500");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $db->prepare("DELETE FROM user_group_members WHERE id = ?")->execute([(int)$m['id']]);
            userNotify($db, (int)$m['user_id'], 'group-expired', 'Your "' . $m['name'] . '" group access expired');
            $out['expired']++;
        }
        // expiry warnings, once per membership
        $days = usersNotifyExpiryDays($cfg);
        if ($days > 0) {
            $st = $db->prepare(
                "SELECT m.id, m.user_id, m.expires_at, g.name FROM user_group_members m JOIN user_groups g ON g.id = m.group_id
                 WHERE m.expires_at IS NOT NULL AND m.warned_at IS NULL
                   AND m.expires_at >= NOW() AND m.expires_at < NOW() + INTERVAL ? DAY LIMIT 500");
            $st->execute([$days]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $db->prepare("UPDATE user_group_members SET warned_at = NOW() WHERE id = ?")->execute([(int)$m['id']]);
                userNotify($db, (int)$m['user_id'], 'group-expiring', 'Your "' . $m['name'] . '" group access expires soon',
                    'Access ends ' . $m['expires_at'] . '.');
                $u = userFindById($db, (int)$m['user_id']);
                if ($u) userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — your "' . $m['name'] . '" access expires soon',
                    'Your "' . $m['name'] . '" group access ends ' . $m['expires_at'] . '.');
                $out['warned']++;
            }
        }
        // hourly prune: read notifications > 90 d, unread > 365 d, dead tokens
        $stateFile = __DIR__ . '/../config/users_state.json';
        $state = is_file($stateFile) ? (json_decode((string)@file_get_contents($stateFile), true) ?: []) : [];
        $now = $now ?? time();
        if ($now - (int)($state['last_prune_at'] ?? 0) >= 3600) {
            $out['pruned'] += (int)$db->exec("DELETE FROM user_notifications WHERE read_at IS NOT NULL AND created_at < NOW() - INTERVAL 90 DAY LIMIT 5000");
            $out['pruned'] += (int)$db->exec("DELETE FROM user_notifications WHERE created_at < NOW() - INTERVAL 365 DAY LIMIT 5000");
            $out['pruned'] += (int)$db->exec("DELETE FROM user_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");
            @file_put_contents($stateFile, json_encode(['last_prune_at' => $now]), LOCK_EX);
        }
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        error_log('[users tick] ' . $e->getMessage());
    }
    return $out;
}
