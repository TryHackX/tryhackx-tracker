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
        // The first batch comes with index.files; loading the rest of a long list — page after page
        // — is its own grant, so a group can be shown a list without being handed 40 000 rows of it.
        // How big a batch is and how far "the rest" goes are index_files_batch / index_files_max
        // (2 000 and 20 000 as shipped, Settings → File list loading, 1.38.0); the grant decides
        // WHO may page at all and is checked before any of those numbers. Both halves are tests on
        // the ?offset= of api/index_files.php — which is why that endpoint has no keyset cursor.
        'index.files_all' => 'Load the whole file list in index search results (beyond the first page)',
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
        // ── favourites, profiles and uploads (v47) ──
        // Four ids for four separate decisions, because "may they keep a list" and "may that list be
        // read by a stranger" are not the same question and an operator will want to answer them
        // differently.
        'favourites.use'         => 'Keep a list of favourite torrents',
        'favourites.public'      => 'Let their favourites list be shown on their public profile',
        'favourites.view_others' => "See other people's profiles and favourite lists",
        'uploads.public'         => 'Let their registered torrents be shown on their public profile',
        // ── lists (v51) ──
        // Two ids again, for the same reason: making a collection and publishing one are separate
        // decisions. `lists.public` is what a group needs before any single list of theirs can be
        // marked public — the site-wide switch and their own per-list choice are the other two.
        'lists.use'    => 'Make lists of torrents and keep them',
        'lists.public' => 'Let their lists be shown on their public profile',
        // ── people reaching each other (v52) ──
        // Sending and REPORTING are separate ids on purpose: an account that has been trusted with
        // an inbox has not thereby been trusted to put somebody's private words in front of a
        // moderator, and an operator may want to withdraw one without the other.
        'pm.send'        => 'Send private messages to other members',
        'pm.report'      => 'Report a message to the moderators',
        'friends.use'    => 'Follow other members and accept friend requests',
        'directory.view' => 'Browse the member directory',

        // ── the admin panel ──
        //
        // Everything above is about the PUBLIC site. Everything below is about the panel, and until
        // now the panel had no permissions at all: every one of its endpoints was gated by
        // "is there a panel session", so the only two states were owner and stranger. That is what
        // made a moderator impossible — there was nothing to grant.
        //
        // What is deliberately ABSENT matters as much as what is here. There is no id for Settings,
        // for restoring a backup, for deleting a user, for editing groups, for API clients, for the
        // firewall/sysctl/opentracker helpers, or for anything that changes the machine. Those stay
        // with the owner, and having no id means they cannot be granted by accident. The password
        // confirmation those actions require is checked against the OWNER's password, so they are
        // structurally out of a moderator's reach anyway — this list simply agrees with that.
        'panel.access'           => 'PANEL — open the admin panel at all (every other panel permission needs this)',
        'panel.reports.view'     => 'PANEL — see the Reports page and the appeals list',
        'panel.reports.status'   => 'PANEL — change a report\'s status and notes',
        'panel.reports.block'    => 'PANEL — block and unblock reported hashes',
        'panel.reports.email'    => 'PANEL — email a reporter and send review notifications',
        'panel.reports.archive'  => 'PANEL — archive, restore and delete reports',
        // The panel side of the same feature. NOT granted to anybody by the migration, not even to
        // the seeded moderator group: reading a reported private message is a different kind of
        // access from working the torrent-report queue, and an operator has to hand it out on
        // purpose. `panel.messages.view` shows the queue and the two messages a report carries;
        // `panel.messages.handle` closes a report or deletes the message it names.
        'panel.messages.view'   => 'See reported private messages (only the reported line and the one before it)',
        'panel.messages.handle' => 'Close a message report, or delete the message it names',
        'panel.appeals.resolve'  => 'PANEL — resolve and restore appeals',
        'panel.whitelist.view'   => 'PANEL — see the Whitelist and Index pages',
        'panel.whitelist.add'    => 'PANEL — register hashes from the panel',
        'panel.whitelist.delete' => 'PANEL — delete whitelist rows',
        'panel.whitelist.ban'    => 'PANEL — ban and unban hashes',
        'panel.whitelist.meta'   => 'PANEL — queue metadata fetches and refresh seeders',
        'panel.whitelist.content'=> 'PANEL — approve or reject submitted descriptions and rewrites',
        'panel.users.view'       => 'PANEL — see the Users page',
        'panel.users.edit'       => 'PANEL — change a user\'s status and email verification',
        'panel.users.notify'     => 'PANEL — send a user an in-app notification',
        'panel.users.groups'     => 'PANEL — grant and revoke groups (never the admin group)',
        // Look, but not touch. EVERY operation in api/admin/backup_action.php demands the admin
        // password, and adminReauth() checks it against the OWNER's hash — which a moderator does
        // not have and must not be given. So there is no "run a backup" permission: one would be a
        // promise the code cannot keep, and a checkbox that grants nothing is worse than no checkbox.
        'panel.backups.view'     => 'PANEL — see the Backups page and the backup list (running, restoring and deleting stay with the owner)',
        'panel.traffic.view'     => 'PANEL — see the Traffic page (read-only; the controls stay with the owner)',
        // Reading the log is its own permission and is NOT in the moderator seed. A moderator who can
        // see every action of every colleague is a different job from moderating, and the operator
        // should decide whether it is the same person.
        'panel.audit.view'       => 'PANEL — read the audit log (who did what in the panel)',
    ];
}

/**
 * Group presets — a starting point for the group editor, not a second registry.
 *
 * Each names permissions that exist in userPermissionList(); a preset naming an id that does not
 * exist is dropped by the editor rather than granted, and users_test.php checks every id here is
 * real. Presets are kept deliberately narrow: the operator adds to them, the preset never carries
 * something they did not mean to hand out.
 */
function userGroupPresets(): array {
    return [
        'moderator' => [
            'label' => 'Moderator',
            'about' => 'Works the report queue and the whitelist. No users, no backups, no log.',
            'perms' => ['panel.access', 'panel.reports.view', 'panel.reports.status', 'panel.reports.block',
                        'panel.reports.email', 'panel.reports.archive', 'panel.appeals.resolve',
                        'panel.whitelist.view', 'panel.whitelist.add', 'panel.whitelist.delete',
                        'panel.whitelist.ban', 'panel.whitelist.meta', 'panel.whitelist.content'],
        ],
        'reviewer' => [
            'label' => 'Content reviewer',
            'about' => 'Approves or rejects descriptions and rewrites; sees the whitelist, changes nothing else.',
            'perms' => ['panel.access', 'panel.whitelist.view', 'panel.whitelist.content'],
        ],
        'curator' => [
            'label' => 'Whitelist curator',
            'about' => 'Registers, bans and refreshes hashes. Never touches reports or users.',
            'perms' => ['panel.access', 'panel.whitelist.view', 'panel.whitelist.add', 'panel.whitelist.delete',
                        'panel.whitelist.ban', 'panel.whitelist.meta'],
        ],
        'auditor' => [
            'label' => 'Read-only auditor',
            'about' => 'Sees every page and the audit log, and can change nothing.',
            'perms' => ['panel.access', 'panel.reports.view', 'panel.whitelist.view', 'panel.users.view',
                        'panel.backups.view', 'panel.traffic.view', 'panel.audit.view'],
        ],
        'member' => [
            'label' => 'Site member',
            'about' => 'The public-site features, no panel at all.',
            'perms' => ['index.view', 'index.files', 'index.files_all', 'index.magnet', 'whitelist.view', 'whitelist.add',
                        'stats.view', 'stats.timeline', 'home.stats', 'rating.vote', 'content.submit', 'content.propose',
                        'favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public',
                        'lists.use', 'lists.public',
                        'pm.send', 'pm.report', 'friends.use', 'directory.view'],
        ],
    ];
}

/** Is this permission id one of the panel ones? */
function userIsPanelPermission(string $perm): bool { return str_starts_with($perm, 'panel.'); }

/**
 * What the site looks like with the user system disabled: index gated, the classic pages public.
 *
 * The content and rating permissions ARE granted here, and that is the whole point: with accounts
 * switched off there are no groups to grant anything, so the features' own settings (rep_who_can_vote,
 * whitelist_submit_mode, wl_allow_description) are the only policy there is. Returning false would not
 * be caution — it would switch those features off for every install that does not use accounts.
 *
 * This function is reached ONLY when the users feature is off (see userCan). With it on, an absent key
 * means denied, which is why introducing a permission has to come with a grant in includes/schema.php.
 */
function userLegacyDefault(string $perm): bool {
    // The panel is never opened by a fallback. With accounts switched off there is nobody to BE a
    // moderator, and the final `return true` below would otherwise hand every panel permission to
    // the whole world the moment one was registered.
    if (userIsPanelPermission($perm)) return false;
    if (str_starts_with($perm, 'rating.') || str_starts_with($perm, 'content.')) return true;
    // The other way round from rating.* and content.*, and for the reason that decides both: those
    // two work without accounts, so answering false would switch them off for every install that
    // does not use the user system. Favourites and profiles do not exist without an account at all —
    // there is nothing to be permissive about.
    if (str_starts_with($perm, 'favourites.') || str_starts_with($perm, 'uploads.')) return false;
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
    // The other half of two-way sign-out. The tracker makes no outbound call — it marks the link,
    // and the partner sees it on their next v1/auth/status. A webhook to an address out of a
    // settings field would be this server fetching whatever that field points at.
    if (!empty($_SESSION['bridge_identity']) && function_exists('authIdentityMarkLogout')) {
        $bcfg = $GLOBALS['cfg'] ?? [];
        if (!function_exists('authBridgeLogoutBoth') || authBridgeLogoutBoth(is_array($bcfg) ? $bcfg : [])) {
            try { authIdentityMarkLogout($db, (int)$_SESSION['bridge_identity']); } catch (\Throwable $e) { /* signing out must not fail */ }
        }
    }
    unset($_SESSION['user_id'], $_SESSION['user_login_time'], $_SESSION['user_expires_at'],
          $_SESSION['bridge_identity'], $_SESSION['bridge_login_at']);
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
/**
 * Bans and mutes whose moment has passed.
 *
 * Called from the account tick in the janitor. Nothing DEPENDS on this running — a `banned_until`
 * in the past is already not in force, because everything that reads these columns compares them
 * with NOW() — but a row that says "banned until last Tuesday" is a row somebody will misread, and
 * an account that shows as banned in the panel a month after its week is over is a support mail.
 */
function userLiftExpiredPunishments(PDO $db): array {
    $out = ['unbanned' => 0, 'unmuted' => 0];
    try {
        $st = $db->prepare("UPDATE users SET status = 'active', banned_until = NULL
                             WHERE status = 'banned' AND banned_until IS NOT NULL AND banned_until < NOW() LIMIT 500");
        $st->execute();
        $out['unbanned'] = $st->rowCount();
        $st2 = $db->prepare("UPDATE users SET pm_muted_until = NULL
                              WHERE pm_muted_until IS NOT NULL AND pm_muted_until < NOW() LIMIT 500");
        $st2->execute();
        $out['unmuted'] = $st2->rowCount();
    } catch (\Throwable $e) { /* a database that predates v55 */ }
    return $out;
}

function userMaybeOpenPanelSession(PDO $db, array $user): void {
    // BOTH conditions, which is what panelCan() downstream already assumes.
    //
    // This used to require admin-group membership, and it is the ONLY writer of
    // $_SESSION['admin_via_user'] — so a user granted panel.access plus a handful of panel.*
    // permissions never got a panel session, and the entire moderator permission map was
    // unreachable by the audience it was written for. The feature existed and nobody could use it.
    if (!userIsAdminGroup($db, (int)$user['id']) && !userHasPanelAccess($db, (int)$user['id'])) return;
    // A second factor, where the operator has said one is required.
    //
    // Enforced HERE and nowhere else: the account still signs in, reads its messages and keeps its
    // favourites — only the panel stays shut, and the account page says why and offers the switch.
    // Refusing the sign-in itself would lock somebody out of their own account over a setting an
    // administrator changed while they were asleep, which is how a requirement gets switched off.
    if (function_exists('user2faRequiredFor')) {
        $cfg2 = $GLOBALS['cfg'] ?? [];
        $need = user2faRequiredFor($db, is_array($cfg2) ? $cfg2 : [], $user);
        if ($need['required'] && !user2faEnabled($db, (int)$user['id'])) {
            $_SESSION['panel_needs_2fa'] = true;
            return;
        }
    }
    unset($_SESSION['panel_needs_2fa']);
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
    // WHERE this cookie went, so "signed in on N devices" can describe each one rather than showing
    // a list of identical rows. Written on every rotation too, so the entry follows the cookie.
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at, ip, ua)
                  VALUES (?, 'remember', ?, FROM_UNIXTIME(?), ?, ?)")
       ->execute([$userId, hash('sha256', $token), $expiresAt,
                  mb_substr((string)(function_exists('getClientIp') ? getClientIp($GLOBALS['cfg'] ?? []) : ''), 0, 45) ?: null,
                  mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null]);
    // cookieBaseParams() reads $GLOBALS['cfg'] when it is not handed one — the pattern getClientIp()
    // already uses. Deliberately NOT a new parameter on this function: its call site in
    // userTryRememberLogin() is pinned by exact source text in tests/audit_fixes_test.php, and a
    // fourth argument would have bought nothing but a broken suite and an edit to api/user_login.php.
    setcookie(USER_REMEMBER_COOKIE, $userId . '.' . $token, cookieBaseParams(null, ['expires' => $expiresAt]));
}

function userRememberClear(PDO $db): void {
    $raw = (string)($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($raw !== '' && preg_match('/^(\d{1,10})\.([a-f0-9]{64})$/', $raw, $m)) {
        $db->prepare("DELETE FROM user_tokens WHERE type = 'remember' AND user_id = ? AND token_hash = ?")
           ->execute([(int)$m[1], hash('sha256', $m[2])]);
    }
    if ($raw !== '') {
        // The delete had NO `secure` key while the issue above set one, so the two disagreed about
        // the cookie's attributes. Same params now, differing only in the expiry. A browser drops a
        // Set-Cookie carrying Secure over a plain-HTTP request, so on such a request the deletion
        // may not land — harmless, because the token row is deleted just above and what is left in
        // the browser is a dead string.
        setcookie(USER_REMEMBER_COOKIE, '', cookieBaseParams(null, ['expires' => time() - 3600]));
        unset($_COOKIE[USER_REMEMBER_COOKIE]);
    }
}

/** Try a remember-cookie login (called lazily from currentUser). Returns the user row or null. */
function userTryRememberLogin(PDO $db): ?array {
    $raw = (string)($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($raw === '' || !preg_match('/^(\d{1,10})\.([a-f0-9]{64})$/', $raw, $m)) return null;
    // The token's own age is compared against the account's epoch below — a cookie issued before
    // "sign out everywhere else" must not walk back in through this door.
    $st = $db->prepare("SELECT user_id, UNIX_TIMESTAMP(expires_at) AS exp, UNIX_TIMESTAMP(created_at) AS born FROM user_tokens WHERE type = 'remember' AND user_id = ? AND token_hash = ? AND expires_at >= NOW() AND used_at IS NULL");
    $st->execute([(int)$m[1], hash('sha256', $m[2])]);
    $tok = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tok) return null;
    $u = userFindById($db, (int)$m[1]);
    if (!$u || $u['status'] !== 'active') return null;
    if ((int)($u['sessions_valid_from'] ?? 0) > (int)$tok['born']) return null;
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
    // AFTER the session id has been regenerated, or the panel flags land on the old session and are
    // thrown away. A remember-me return is a sign-in; it must open the panel like one.
    userMaybeOpenPanelSession($db, $u);
    return $u;
}

/**
 * The devices this account is signed in on, newest first.
 *
 * What the site actually KNOWS is remember-me tokens: one per browser that asked to be remembered,
 * rotated on every return. A plain session with no cookie behind it leaves no row anywhere — PHP
 * sessions are files on disk with no account in their name — so the list says what it can prove and
 * the account page names the current browser separately rather than pretending to enumerate them.
 *
 * `current` marks the row this request's own cookie belongs to, because "which of these is me?" is
 * the first question anybody asks of a list like this.
 */
function userSessionList(PDO $db, int $userId): array
{
    $mine = '';
    $raw = (string)($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($raw !== '' && preg_match('/^(\d{1,10})\.([a-f0-9]{64})$/', $raw, $m) && (int)$m[1] === $userId) {
        $mine = hash('sha256', $m[2]);
    }
    $st = $db->prepare("SELECT token_hash, ip, ua, UNIX_TIMESTAMP(created_at) AS created,
                               UNIX_TIMESTAMP(expires_at) AS expires
                          FROM user_tokens
                         WHERE user_id = ? AND type = 'remember' AND used_at IS NULL AND expires_at >= NOW()
                         ORDER BY created_at DESC LIMIT 50");
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            // The hash never leaves the server: a caller that could name a token could revoke
            // somebody else's. Rows are addressed by their position in this list instead.
            'current' => $mine !== '' && hash_equals((string)$r['token_hash'], $mine),
            'ip'      => $r['ip'] !== null ? (string)$r['ip'] : null,
            'ua'      => $r['ua'] !== null ? (string)$r['ua'] : null,
            'created' => (int)$r['created'],
            'expires' => (int)$r['expires'],
        ];
    }
    return $out;
}

/**
 * End every session of this account except the one making the request.
 *
 * Two halves, and both are needed: the remember cookies go (so nothing can walk back in), and
 * `sessions_valid_from` is stamped (so the sessions that are already open stop being honoured —
 * see currentUser()). The current browser is kept by re-stamping its own login time afterwards and
 * issuing it a fresh cookie, because the person doing this is not the one they are worried about.
 *
 * Returns how many remember tokens were destroyed.
 */
function userSignOutOthers(PDO $db, int $userId, bool $keepCurrent = true): int
{
    $keepHash = '';
    $raw = (string)($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($keepCurrent && $raw !== '' && preg_match('/^(\d{1,10})\.([a-f0-9]{64})$/', $raw, $m) && (int)$m[1] === $userId) {
        $keepHash = hash('sha256', $m[2]);
    }
    if ($keepHash !== '') {
        $st = $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember' AND token_hash <> ?");
        $st->execute([$userId, $keepHash]);
    } else {
        $st = $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'");
        $st->execute([$userId]);
    }
    $gone = $st->rowCount();
    $now = time();
    $db->prepare("UPDATE users SET sessions_valid_from = ? WHERE id = ?")->execute([$now, $userId]);
    // This session survives its own sweep: the stamp is "everything older than now is over", and
    // this request is now.
    if ($keepCurrent && session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['user_id'] ?? 0) === $userId) {
        $_SESSION['user_login_time'] = $now;
    }
    return $gone;
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
        // "Sign out everywhere else", and a password change, stamp users.sessions_valid_from. Any
        // session that began before that instant is over — this is the only place that can end a
        // session belonging to a browser we are not currently talking to.
        //
        // Both sides are unix times PHP wrote. The column is a BIGINT rather than a DATETIME for
        // exactly that reason: a comparison between a clock the database keeps and a clock PHP keeps
        // is a comparison this project has already got wrong once.
        if ($u && (int)($u['sessions_valid_from'] ?? 0) > (int)($_SESSION['user_login_time'] ?? 0)) {
            unset($_SESSION['user_id'], $_SESSION['user_login_time'], $_SESSION['user_expires_at']);
            $u = null;
        }
        // A session OPENED THROUGH THE BRIDGE ends when the far side says the person signed out
        // over there. Only those: an ordinary sign-in on this site is not the forum's to end, and
        // the flag is on the session, so nobody else's request grows a query for this.
        //
        // The remember-me fallback below is left alone deliberately. A bridged sign-in never issues
        // a remember cookie, so for a purely bridged visitor there is nothing to fall back to; if
        // one exists it is because this person also signed in here with their own password, and
        // that credential is theirs, not the forum's to revoke.
        if ($u && !empty($_SESSION['bridge_identity']) && function_exists('authBridgeSessionEnded')
            && authBridgeSessionEnded($db)) {
            unset($_SESSION['user_id'], $_SESSION['user_login_time'], $_SESSION['user_expires_at'],
                  $_SESSION['bridge_identity'], $_SESSION['bridge_login_at']);
            $u = null;
        }
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

/**
 * Everything that belongs to one account, removed with it.
 *
 * api/admin/user_delete.php used to list the tables inline, which meant every new table that holds a
 * user_id had to be remembered THERE. It was not: `hash_votes` keeps its voter as
 * (voter_type='user', voter_key=<id>), so a deleted account's votes stayed in the table and went on
 * counting towards every score they had touched — a live bug, not a hypothesis, and one no test
 * could have caught because nothing named the invariant.
 *
 * One function, one place to add the next table. Returns what it removed, so the caller can say.
 */
function userDeleteCascade(PDO $db, int $userId): array {
    $gone = [];
    $del = function (string $sql, array $args, string $label) use ($db, &$gone) {
        try {
            $st = $db->prepare($sql);
            $st->execute($args);
            $n = $st->rowCount();
            if ($n > 0) $gone[$label] = $n;
        } catch (\Throwable $e) { /* a table this install does not have yet is not a failure */ }
    };
    $del("DELETE FROM user_group_members WHERE user_id = ?", [$userId], 'groups');
    $del("DELETE FROM user_notifications WHERE user_id = ?", [$userId], 'notifications');
    $del("DELETE FROM user_tokens WHERE user_id = ?", [$userId], 'tokens');
    $del("DELETE FROM user_favourites WHERE user_id = ?", [$userId], 'favourites');
    // Lists, and the rows inside them. The items are keyed by list, not by user, so they have to go
    // FIRST — deleting the lists first would leave orphans keyed to ids that no longer exist, and
    // nothing would ever look at them again to notice.
    $del("DELETE i FROM user_list_items i JOIN user_lists l ON l.id = i.list_id WHERE l.user_id = ?", [$userId], 'list_items');
    $del("DELETE FROM user_lists WHERE user_id = ?", [$userId], 'lists');
    // Everything that was between this account and somebody else. The messages go with the threads
    // — a conversation with a gap where one side used to be is not a conversation anybody can read
    // — and the reports go with the messages they point at, because a queue whose rows name a
    // message that no longer exists is a queue nobody can work.
    $del("DELETE r FROM message_reports r JOIN message_threads t ON t.id = r.thread_id
           WHERE t.u_low = ? OR t.u_high = ?", [$userId, $userId], 'message_reports');
    $del("DELETE m FROM user_messages m JOIN message_threads t ON t.id = m.thread_id
           WHERE t.u_low = ? OR t.u_high = ?", [$userId, $userId], 'messages');
    $del("DELETE FROM message_threads WHERE u_low = ? OR u_high = ?", [$userId, $userId], 'threads');
    $del("DELETE FROM user_friends WHERE user_id = ? OR friend_id = ?", [$userId, $userId], 'friends');
    $del("DELETE FROM user_blocks WHERE user_id = ? OR blocked_id = ?", [$userId, $userId], 'blocks');
    // The pair, not an id column: hash_votes identifies a voter as a type plus a key, because an
    // anonymous vote is keyed by an IP bucket instead.
    $del("DELETE FROM hash_votes WHERE voter_type = 'user' AND voter_key = ?", [(string)$userId], 'votes');
    // The bridge links and any ticket still outstanding. Leaving an identity behind would leave a
    // partner able to open a session for an account that no longer exists — the row would point at
    // a gap, and the next bridged sign-in for that external id would find it and fail confusingly
    // rather than making the person a new account.
    $del("DELETE FROM user_identities WHERE user_id = ?", [$userId], 'bridge_links');
    $del("DELETE FROM auth_handoffs WHERE user_id = ?", [$userId], 'bridge_tickets');
    // Submissions are NOT deleted — a whitelist row is a torrent the tracker serves, and deleting an
    // account is not a reason to stop serving it. The attribution goes, so it stops appearing on a
    // profile that no longer exists.
    $del("UPDATE whitelist SET submitter_id = NULL, submitter_public = 0 WHERE submitter_id = ?", [$userId], 'submissions_unlinked');
    $del("DELETE FROM users WHERE id = ?", [$userId], 'user');
    return $gone;
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
 * Does a NAMED user hold a permission — not the caller.
 *
 * userCan() answers about whoever is asking, which is the wrong question when a page has to decide
 * what somebody ELSE's profile may show. A stale checkbox on a row must not outlive the group that
 * allowed it: an operator who takes `favourites.public` away from a group means it, and a list that
 * kept showing because a `fav_public` column still said 1 would be the checkbox overruling them.
 */
function userIdHasPermission(PDO $db, array $cfg, int $userId, string $perm): bool {
    if (!usersEnabled($cfg)) return userLegacyDefault($perm);
    if ($userId <= 0) return false;
    $perms = userEffectivePermissions($db, $userId, $cfg);
    return !empty($perms[$perm]);
}

/** Does this user hold panel.access through any of their active groups? */
function userHasPanelAccess(PDO $db, int $userId): bool {
    $p = userEffectivePermissions($db, $userId);
    return !empty($p['panel.access']);
}

/**
 * THE PANEL permission check. Deliberately NOT userCan().
 *
 * userCan() begins with `if (isLoggedIn()) return true;` — any panel session passes every check,
 * which is right for the public site (the owner should see everything) and catastrophic here: it
 * would make a moderator omnipotent the moment they held a panel session, which is the whole point
 * of having one.
 *
 * So this asks a different question. A CLASSIC panel session — signed in with the owner's password —
 * keeps its total bypass; there is one owner and the panel is theirs. A session opened by
 * piggy-backing on a user sign-in resolves that user's effective permissions, and gets exactly
 * those. Anything not granted is denied, including every id that does not exist.
 */
function panelCan(PDO $db, array $cfg, string $perm): bool {
    if (empty($_SESSION['loggedin'])) return false;
    $viaUser = (int)($_SESSION['admin_via_user'] ?? 0);
    if ($viaUser <= 0) return true;                 // the owner's own session
    if (userIsAdminGroup($db, $viaUser)) return true;
    $p = userEffectivePermissions($db, $viaUser);
    return !empty($p[$perm]);
}

/** panelCan() or a 403. For endpoints; mirrors requireAuth()'s shape. */
function panelRequire(string $perm): void {
    global $db, $cfg;
    if (!($db instanceof PDO) || !is_array($cfg)) { jsonResponse(['error' => __('api.users.forbidden')], 403); }
    if (!panelCan($db, $cfg, $perm)) {
        jsonResponse(['error' => __('api.users.panel_no_access')], 403);
    }
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
    $out = ['enabled' => usersEnabled($cfg), 'expired' => 0, 'warned' => 0, 'pruned' => 0,
            'unbanned' => 0, 'unmuted' => 0, 'error' => null];
    if (!$out['enabled']) return $out;
    try {
        // Timed bans and mutes whose moment has passed. First, because the rest of this tick may
        // notify people and a notification to somebody the site still calls banned reads oddly.
        $lift = userLiftExpiredPunishments($db);
        $out['unbanned'] = $lift['unbanned'];
        $out['unmuted'] = $lift['unmuted'];
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
