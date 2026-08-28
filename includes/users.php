<?php
/**
 * User accounts (schema v7): registration/login, groups with JSON permissions, timed memberships,
 * in-app notifications, remember-me and password-reset tokens.
 *
 * Everything is OFF unless users_enabled=1. Design:
 *   - The `guest` group holds the permissions of ANONYMOUS visitors only. A signed-in user has
 *     exactly the union of their ACTIVE groups' permissions (expired memberships are ignored and
 *     reaped by usersTick()) — guest is NOT inherited, so a group can be narrower than guest.
 *   - Members of the system `admin` group pass every permission check.
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
const USER_VERIFY_TTL_H    = 72;    // email-verification link lifetime (hours)
const USER_ECHANGE_TTL_H   = 24;    // email-change confirmation links (old + new step)

/** Sign-in duration choices (login form "Stay signed in for"): code => [seconds|null (forever), label]. */
function userSessionChoices(): array {
    return [
        'forever' => [null, 'Forever (until you sign out)'],
        '1h'      => [3600, '1 hour'],
        '1d'      => [86400, '1 day'],
        '30d'     => [30 * 86400, '30 days'],
    ];
}

function usersEnabled(array $cfg): bool { return (($cfg['users_enabled'] ?? '0') === '1'); }
/** Registration requires an email + only VERIFIED accounts get their groups (unverified = guest level). */
function userEmailVerifyRequired(array $cfg): bool { return usersEnabled($cfg) && (($cfg['users_require_email_verify'] ?? '1') === '1'); }
function userEmailChangeCooldownDays(array $cfg): int { return max(0, min(365, (int)($cfg['users_email_change_cooldown_days'] ?? 30))); }
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
        'whitelist.view' => 'Browse the public whitelist page (whitelisted torrents also show up in search)',
        'whitelist.add'  => 'Register hashes on the whitelist (used when registration is set to "registered users")',
        'stats.view'     => 'View the tracker statistics page',
        'stats.timeline' => 'See the statistics timeline chart',
        'home.stats'     => 'See the live stats widget on the home page',
        // Everything below arrived with the descriptions, ratings and rewrite proposals. Shipping a
        // feature that groups cannot govern means the only choices are "everybody" and "nobody",
        // which is not a permission system, it is a switch.
        'rating.vote'     => 'Rate torrents up or down (needs ratings switched on in Settings)',
        'content.submit'  => 'Attach a source link and a description when registering a torrent',
        'content.propose' => 'Propose a rewrite of a description somebody else wrote',
    ];
}

/**
 * What the site looks like with the user system disabled: index gated, the classic pages public.
 *
 * The content and rating permissions are NOT granted here. With accounts switched off there is no
 * identity to attach a rating or a description to, and the features' own settings decide instead —
 * this function must not become a second, invisible place that turns them on.
 */
function userLegacyDefault(string $perm): bool {
    if (str_starts_with($perm, 'rating.') || str_starts_with($perm, 'content.')) return true;
    return !str_starts_with($perm, 'index.');
}

function userValidUsername(string $u): bool { return (bool)preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $u); }
function userValidEmail(string $e): bool { return $e !== '' && strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

/** Human wording of the password policy — one place, reused by every endpoint's error message. */
const USER_PASSWORD_RULES = 'at least 8 characters with a lowercase and an uppercase letter, a digit and a special character';

/**
 * Password policy (1.8.0): min 8 / max 200 chars, at least one lowercase, uppercase, digit and
 * special character. Returns the FAILED requirement codes (empty = acceptable) — the register /
 * account forms mirror this list as a live checklist. Applies to NEW passwords only; existing
 * hashes keep working.
 */
function userPasswordIssues(string $p): array {
    $issues = [];
    if (strlen($p) < 8 || strlen($p) > 200) $issues[] = 'length';
    if (!preg_match('/[a-z]/', $p)) $issues[] = 'lower';
    if (!preg_match('/[A-Z]/', $p)) $issues[] = 'upper';
    if (!preg_match('/[0-9]/', $p)) $issues[] = 'digit';
    if (!preg_match('/[^a-zA-Z0-9]/', $p)) $issues[] = 'special';
    return $issues;
}
function userValidPassword(string $p): bool { return userPasswordIssues($p) === []; }

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
    if (!userValidPassword($password)) return ['error' => 'weak_password'];
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

/** $ttlSeconds bounds this sign-in (login form choice); null = no deadline ("forever"). */
function userSessionStart(PDO $db, array $user, string $ip = '', ?int $ttlSeconds = null): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_login_time'] = time();
    if ($ttlSeconds !== null && $ttlSeconds > 0) $_SESSION['user_expires_at'] = time() + $ttlSeconds;
    else unset($_SESSION['user_expires_at']);
    $db->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?")
       ->execute([$ip !== '' ? $ip : null, (int)$user['id']]);
}

function userSessionLogout(PDO $db): void {
    userRememberClear($db);
    unset($_SESSION['user_id'], $_SESSION['user_login_time'], $_SESSION['user_expires_at']);
    // a panel session opened via the admin-group sign-in dies with the user session
    if (!empty($_SESSION['admin_via_user'])) {
        unset($_SESSION['admin_via_user'], $_SESSION['loggedin'], $_SESSION['login_time'], $_SESSION['last_activity']);
    }
    $GLOBALS['__current_user_cache'] = null;
    $GLOBALS['__current_user_loaded'] = false;
}

/**
 * Signing in on the PUBLIC site as an admin-group member also opens the ADMIN PANEL session, so
 * the owner does not have to log in twice. The panel session keeps its OWN idle / absolute limits
 * (admin_session_idle_minutes / admin_session_absolute_hours) — a "forever" site sign-in does NOT
 * keep the panel open forever; after the idle window the panel asks for its login again.
 */
function userMaybeOpenPanelSession(PDO $db, array $user): void {
    if (!userIsAdminGroup($db, (int)$user['id'])) return;
    $_SESSION['loggedin'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['admin_via_user'] = (int)$user['id'];
}

/**
 * Issue a remember-me cookie (value "<id>.<64 hex>"; only the sha256 is stored). $expiresAt
 * (unix) sets an absolute expiry — used by the login-duration choice and by token rotation
 * (a rotated token keeps the original deadline); default = 30 days from now.
 */
function userRememberIssue(PDO $db, int $userId, ?int $expiresAt = null): void {
    $expiresAt = $expiresAt ?? (time() + USER_REMEMBER_DAYS * 86400);
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, 'remember', ?, FROM_UNIXTIME(?))")
       ->execute([$userId, hash('sha256', $token), $expiresAt]);
    setcookie(USER_REMEMBER_COOKIE, $userId . '.' . $token, [
        'expires' => $expiresAt,
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
    $st = $db->prepare("SELECT user_id, UNIX_TIMESTAMP(expires_at) AS exp FROM user_tokens WHERE type = 'remember' AND user_id = ? AND token_hash = ? AND expires_at >= NOW() AND used_at IS NULL");
    $st->execute([(int)$m[1], hash('sha256', $m[2])]);
    $tok = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tok) return null;
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
    unset($_SESSION['user_expires_at']);   // the remember token's own expiry is the deadline now
    // rotation keeps the ORIGINAL absolute expiry, so a "1 day" sign-in really ends after 1 day
    if (!headers_sent()) userRememberIssue($db, (int)$u['id'], (int)$tok['exp']);
    return $u;
}

/** The logged-in user of this request, or null. Cached per request. */
function currentUser(PDO $db): ?array {
    if (!empty($GLOBALS['__current_user_loaded'])) return $GLOBALS['__current_user_cache'];
    $GLOBALS['__current_user_loaded'] = true;
    $GLOBALS['__current_user_cache'] = null;
    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    $u = null;
    if (!empty($_SESSION['user_expires_at']) && time() > (int)$_SESSION['user_expires_at']) {
        // timed sign-in ("1 hour" / "1 day" / "30 days") ran out — drop the session, remember-me may take over
        unset($_SESSION['user_id'], $_SESSION['user_login_time'], $_SESSION['user_expires_at']);
    }
    if (!empty($_SESSION['user_id'])) {
        $u = userFindById($db, (int)$_SESSION['user_id']);
        if ($u && $u['status'] !== 'active') { unset($_SESSION['user_id'], $_SESSION['user_login_time'], $_SESSION['user_expires_at']); $u = null; }
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

/**
 * Effective permission set. Anonymous ($userId null) = the `guest` group. A signed-in user gets
 * exactly the UNION of their active groups — guest is NOT inherited, so a logged-in user can have
 * fewer permissions than an anonymous visitor if their groups are narrower. Membership in the
 * system `admin` group grants every registered permission.
 */
function userEffectivePermissions(PDO $db, ?int $userId, ?array $cfg = null): array {
    static $cache = [];
    $gate = $cfg !== null && userEmailVerifyRequired($cfg);
    // the trusted flag is part of the cache key so verifying WITHIN a request (the ?action=verify
    // page) immediately unlocks the groups — a plain per-user key would keep serving guest perms
    $trusted = ($gate && $userId) ? userIsEmailTrusted($db, $userId) : true;
    $key = (string)($userId ?? 0) . ($gate ? ($trusted ? '|vt' : '|vu') : '');
    if (isset($cache[$key])) return $cache[$key];
    $guestPerms = function () use ($db): array {
        $guest = userGroupBySlug($db, 'guest');
        return $guest ? userGroupPermissions($guest['permissions']) : [];
    };
    $perms = [];
    if ($userId) {
        $groups = userGroups($db, $userId);
        $isAdmin = false;
        foreach ($groups as $g) if ($g['slug'] === 'admin') { $isAdmin = true; break; }
        if ($isAdmin) {   // site admins pass every check, current and future (verification gate included)
            $perms = array_fill_keys(array_keys(userPermissionList()), true);
        } elseif ($gate && !$trusted) {
            // verification required but the address is missing/unconfirmed → guest level until the
            // link in the mailbox is clicked (the account page itself stays reachable)
            $perms = $guestPerms();
        } else {
            foreach ($groups as $g) $perms += userGroupPermissions($g['permissions']);
        }
    } else {
        $perms = $guestPerms();
    }
    return $cache[$key] = $perms;
}

/** Does this user have a verified address? (used by the email-verification gate) */
function userIsEmailTrusted(PDO $db, int $userId): bool {
    $u = userFindById($db, $userId);
    return $u !== null && trim((string)($u['email'] ?? '')) !== '' && (int)$u['email_verified'] === 1;
}

/** Is this user an ACTIVE member of the system `admin` group? */
function userIsAdminGroup(PDO $db, int $userId): bool {
    foreach (userGroups($db, $userId) as $g) if ($g['slug'] === 'admin') return true;
    return false;
}

/**
 * Is this the ROOT admin — the panel admin mirrored into the user list (username matches the
 * panel's admin_username)? The root admin can never be deleted, banned or stripped of the admin
 * group from the user browser (that would lock the owner out of their own site).
 */
function userIsRootAdmin(array $user, array $cfg): bool {
    $panel = trim((string)($cfg['admin_username'] ?? ''));
    return $panel !== '' && hash_equals($panel, (string)($user['username'] ?? ''));
}

/**
 * THE permission check. Admin panel session → always true. Users feature off → the legacy public
 * behaviour. Otherwise: the guest group for anonymous visitors, the union of the signed-in user's
 * groups for everyone else (see userEffectivePermissions).
 */
function userCan(PDO $db, array $cfg, string $perm): bool {
    if (function_exists('isLoggedIn') && isLoggedIn()) return true;   // panel admin
    if (!usersEnabled($cfg)) return userLegacyDefault($perm);
    $u = currentUser($db);
    $perms = userEffectivePermissions($db, $u ? (int)$u['id'] : null, $cfg);
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

/**
 * Best-effort email copy of a notification (only when mail is set up and the user has an address).
 * $opts: action_url + action_label render a CTA button with the raw link underneath; title
 * overrides the heading. Every account mail carries the recipient's unsubscribe/preferences link.
 */
function userNotifyMail(PDO $db, array $cfg, array $user, string $subject, string $bodyText, array $opts = []): void {
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !function_exists('sendEmail')) return;
    if (function_exists('isUnsubscribed') && isUnsubscribed($db, $email, 'account')) return;
    try {
        ob_start();
        $unsub = function_exists('getUnsubscribeUrl') ? getUnsubscribeUrl($email, $cfg) : '';
        $plain = $bodyText . (!empty($opts['action_url']) ? "\n\n" . $opts['action_url'] : '');
        $html = buildEmailHtml([
            'title' => $opts['title'] ?? $subject,
            'greeting' => 'Hello ' . sanitize($user['username'] ?? '') . ',',
            'body' => nl2br(sanitize($bodyText)),
            'action_url' => $opts['action_url'] ?? '',
            'action_label' => $opts['action_label'] ?? '',
            'details' => [], 'unsubscribe_url' => $unsub,
        ], $cfg);
        @sendEmail($email, $subject, $plain, $html, $cfg, $unsub);
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
// Email verification
// ─────────────────────────────────────────────────────────────────────────────

/** Create (replacing any previous) an email-verification token for the user. Returns the raw token. */
function userVerifyCreate(PDO $db, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $db->prepare("DELETE FROM user_tokens WHERE type = 'verify' AND user_id = ?")->execute([$userId]);
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, 'verify', ?, NOW() + INTERVAL " . USER_VERIFY_TTL_H . " HOUR)")
       ->execute([$userId, hash('sha256', $token)]);
    return $token;
}

/** Consume a verification token: marks the user's email verified. Returns the user id or null. */
function userVerifyConsume(PDO $db, string $token): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $db->prepare("SELECT id, user_id FROM user_tokens WHERE type = 'verify' AND token_hash = ? AND expires_at >= NOW() AND used_at IS NULL");
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $db->prepare("UPDATE user_tokens SET used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
    $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([(int)$row['user_id']]);
    return (int)$row['user_id'];
}

/**
 * Best-effort verification mail (silent no-op when the user has no address or mail is unavailable).
 * Called after registration-with-email and after every email change. Returns true when a mail
 * was handed to the MTA.
 */
function userVerifySend(PDO $db, array $cfg, array $user): bool {
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !function_exists('sendEmail')) return false;
    $token = userVerifyCreate($db, (int)$user['id']);
    $link = mailAbsoluteUrl($cfg, '?action=verify&token=' . $token);
    $site = $cfg['site_name'] ?? 'Tracker';
    $text = "Hello {$user['username']},\n\nConfirm that this address belongs to your $site account by opening:\n$link\n\nThe link is valid for " . USER_VERIFY_TTL_H . " hours. If you did not request this, ignore this message.";
    try {
        $unsub = function_exists('getUnsubscribeUrl') ? getUnsubscribeUrl($email, $cfg) : '';
        $html = buildEmailHtml(['title' => 'Confirm your email address', 'greeting' => 'Hello ' . sanitize($user['username']) . ',',
            'body' => 'Confirm that this address belongs to your ' . sanitize($site) . ' account. The link is valid for ' . USER_VERIFY_TTL_H . ' hours. If you did not request this, simply ignore this message.',
            'action_url' => $link, 'action_label' => 'Confirm email address',
            'details' => [], 'unsubscribe_url' => $unsub], $cfg);
        return (bool)@sendEmail($email, $site . ' — confirm your email address', $text, $html, $cfg, $unsub);
    } catch (\Throwable $e) { return false; }
}

// ─────────────────────────────────────────────────────────────────────────────
// Email change — two-step confirmation (schema v9)
//
// Changing (or removing) the address is confirmed from the OLD mailbox first, then — for a change —
// from the NEW one; only then is anything written to `email`. A cooldown
// (users_email_change_cooldown_days, since the last COMPLETED change) blocks rapid flip-flopping,
// so a hijacked session cannot quietly steal the mailbox and cover its tracks.
// ─────────────────────────────────────────────────────────────────────────────

/** Pending-change state for the account page: null, or ['pending_email','stage' ('old'|'new')]. */
function userEmailChangeState(PDO $db, array $user): ?array {
    if (($user['pending_email'] ?? null) === null) return null;
    $st = $db->prepare("SELECT type FROM user_tokens WHERE user_id = ? AND type IN ('echange_old','echange_new') AND expires_at >= NOW() AND used_at IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$user['id']]);
    $type = $st->fetchColumn();
    if (!$type) return null;   // tokens expired — the pending value is dead weight
    return ['pending_email' => (string)$user['pending_email'], 'stage' => $type === 'echange_old' ? 'old' : 'new'];
}

function userEmailChangeCancel(PDO $db, int $userId): void {
    $db->prepare("UPDATE users SET pending_email = NULL WHERE id = ?")->execute([$userId]);
    $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type IN ('echange_old','echange_new')")->execute([$userId]);
}

/**
 * Begin a change/removal ($newEmail '' = remove). Returns ['error'=>code(,'until')] or
 * ['stage'=>'old'|'done_direct']. A user WITHOUT an old address gets the direct path (nothing to
 * confirm from) — the standard verification mail still guards the new address.
 */
function userEmailChangeStart(PDO $db, array $cfg, array $user, string $newEmail): array {
    $newEmail = trim($newEmail);
    if ($newEmail !== '' && !userValidEmail($newEmail)) return ['error' => 'invalid_email'];
    $old = trim((string)($user['email'] ?? ''));
    if ($newEmail === $old) return ['error' => 'same_email'];
    $days = userEmailChangeCooldownDays($cfg);
    if ($days > 0 && !empty($user['email_changed_at'])) {
        $until = strtotime((string)$user['email_changed_at']) + $days * 86400;
        if (time() < $until) return ['error' => 'cooldown', 'until' => date('Y-m-d H:i', $until)];
    }
    if ($newEmail !== '') {
        $dup = $db->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ?");
        $dup->execute([$newEmail, (int)$user['id']]);
        if ($dup->fetchColumn()) return ['error' => 'email_taken'];
    }
    if ($old === '') {
        $db->prepare("UPDATE users SET email = ?, email_verified = 0, email_changed_at = NOW() WHERE id = ?")
           ->execute([$newEmail === '' ? null : $newEmail, (int)$user['id']]);
        return ['stage' => 'done_direct'];
    }
    $db->prepare("UPDATE users SET pending_email = ? WHERE id = ?")->execute([$newEmail, (int)$user['id']]);
    $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type IN ('echange_old','echange_new')")->execute([(int)$user['id']]);
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, 'echange_old', ?, NOW() + INTERVAL " . USER_ECHANGE_TTL_H . " HOUR)")
       ->execute([(int)$user['id'], hash('sha256', $token)]);
    $link = mailAbsoluteUrl($cfg, '?action=emailchange&token=' . $token);
    userNotifyMail($db, $cfg, $user, ($cfg['site_name'] ?? 'Tracker') . ' — confirm your email change',
        'A change of your account email address was requested' . ($newEmail === '' ? ' (address REMOVAL)' : ' to: ' . $newEmail)
        . ".\nStep 1 of 2: confirm from THIS (current) address. The link is valid for " . USER_ECHANGE_TTL_H . " hours.\nIf this was not you, change your password immediately.",
        ['title' => 'Confirm your email change', 'action_url' => $link, 'action_label' => 'Yes, continue the change']);
    return ['stage' => 'old'];
}

/** Consume one step link (?action=emailchange&token=…). Returns a status array for the page. */
function userEmailChangeConsume(PDO $db, array $cfg, string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return ['error' => 'invalid'];
    $st = $db->prepare("SELECT id, user_id, type FROM user_tokens WHERE type IN ('echange_old','echange_new') AND token_hash = ? AND expires_at >= NOW() AND used_at IS NULL");
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['error' => 'invalid'];
    $u = userFindById($db, (int)$row['user_id']);
    if (!$u || ($u['pending_email'] ?? null) === null) return ['error' => 'invalid'];
    $db->prepare("UPDATE user_tokens SET used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
    $pending = (string)$u['pending_email'];
    if ($row['type'] === 'echange_old') {
        if ($pending === '') {   // removal — the old mailbox has spoken, done
            $db->prepare("UPDATE users SET email = NULL, email_verified = 0, pending_email = NULL, email_changed_at = NOW() WHERE id = ?")->execute([(int)$u['id']]);
            userNotify($db, (int)$u['id'], 'account', 'Your email address was removed', 'Confirmed from your previous address.');
            return ['stage' => 'removed'];
        }
        $t2 = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, 'echange_new', ?, NOW() + INTERVAL " . USER_ECHANGE_TTL_H . " HOUR)")
           ->execute([(int)$u['id'], hash('sha256', $t2)]);
        $link = mailAbsoluteUrl($cfg, '?action=emailchange&token=' . $t2);
        userNotifyMail($db, $cfg, ['email' => $pending, 'username' => $u['username']], ($cfg['site_name'] ?? 'Tracker') . ' — confirm your new email address',
            "The change was approved from the previous address.\nStep 2 of 2: confirm that THIS new address is yours. The link is valid for " . USER_ECHANGE_TTL_H . " hours.",
            ['title' => 'Confirm your new address', 'action_url' => $link, 'action_label' => 'Confirm new address']);
        return ['stage' => 'old_ok', 'pending' => $pending];
    }
    // echange_new — finalise (new address arrives already verified; cooldown clock restarts)
    try {
        $db->prepare("UPDATE users SET email = ?, email_verified = 1, pending_email = NULL, email_changed_at = NOW() WHERE id = ?")->execute([$pending, (int)$u['id']]);
    } catch (PDOException $e) {
        return ['error' => 'email_taken'];
    }
    userNotify($db, (int)$u['id'], 'account', 'Your email address was changed', 'New address: ' . $pending . ' (verified).');
    userNotifyMail($db, $cfg, ['email' => (string)$u['email'], 'username' => $u['username']], ($cfg['site_name'] ?? 'Tracker') . ' — your email address was changed',
        'The email on your account is now: ' . $pending . "\nIf this was not you, reset your password immediately.");
    // …and a written confirmation lands in the NEW mailbox too (the trail used to end with just
    // the browser page — pkt: "na nowym tylko link, nie ma potwierdzenia")
    userNotifyMail($db, $cfg, ['email' => $pending, 'username' => $u['username']], ($cfg['site_name'] ?? 'Tracker') . ' — email change confirmed',
        'Done! This address is now active and verified on your account. Account notices and password resets arrive here from now on.');
    return ['stage' => 'done', 'email' => $pending];
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
