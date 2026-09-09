<?php
/**
 * The sign-in bridge: one community, two sites, one account.
 *
 * ── what it is for ─────────────────────────────────────────────────────────────────────────────
 * A tracker usually sits next to a forum, and the forum is where the community already is. Asking
 * people to register twice is asking half of them not to bother, so the bridge lets the forum say
 * "the person at this browser is our user 412, and I vouch for them" — and the tracker signs that
 * person in, making the account first if it has never seen them.
 *
 * It runs both ways. The forum can send somebody here signed in, and a person already signed in
 * here can be sent to the forum signed in. Neither side holds the other's password; what crosses is
 * a one-time ticket that is worthless a minute later and worthless twice.
 *
 * ── what it deliberately does not do ───────────────────────────────────────────────────────────
 * It does not match people up by email address. A key that can assert an address is a key that can
 * assert the ADMINISTRATOR'S address, and "sign me in as whoever owns this mailbox" is account
 * takeover with a helpful face. The operator can switch that on for an install where the tracker
 * verified the addresses itself (auth_bridge_merge = 'email_verified'), and it ships off.
 *
 * It never gives out a password and never accepts one. A bridged account keeps the unusable hash it
 * was created with until the person sets one through the ordinary reset flow — which is why they can
 * always still sign in here if the forum disappears.
 *
 * ── the ticket ─────────────────────────────────────────────────────────────────────────────────
 * Only the SHA-256 of a ticket is stored, for the same reason as a password: the row is enough to
 * become somebody if the plaintext is in it. Redemption is a single UPDATE guarded on used_at IS
 * NULL, so two browsers racing the same ticket cannot both win.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Settings
// ─────────────────────────────────────────────────────────────────────────────

/** The master switch. There is no bridge without the account system it bridges to. */
function authBridgeEnabled(array $cfg): bool {
    return usersEnabled($cfg) && (($cfg['auth_bridge_enabled'] ?? '0') === '1');
}

/** May the bridge make an account for somebody it has never seen, or only sign in linked ones? */
function authBridgeMayCreate(array $cfg): bool { return ($cfg['auth_bridge_create'] ?? '1') === '1'; }

/** 'none' | 'email_verified' — whether an unlinked identity may claim an existing local account. */
function authBridgeMergeMode(array $cfg): string {
    $m = (string)($cfg['auth_bridge_merge'] ?? 'none');
    return in_array($m, ['none', 'email_verified'], true) ? $m : 'none';
}

/** Seconds a handoff ticket lives. It has to survive one redirect, so the range is deliberately small. */
function authBridgeTtl(array $cfg): int { return max(30, min(900, (int)($cfg['auth_bridge_ttl'] ?? 120))); }

/** Where "continue to the forum" goes. Empty switches the outbound half off. */
function authBridgeReturnUrl(array $cfg): string { return authBridgeSafeUrl((string)($cfg['auth_bridge_return_url'] ?? '')); }

/** The forum's own sign-in page, offered on this site's login form. Empty = not offered. */
function authBridgeLoginUrl(array $cfg): string { return authBridgeSafeUrl((string)($cfg['auth_bridge_login_url'] ?? '')); }

/** Does signing out on one side end the session on the other. */
function authBridgeLogoutBoth(array $cfg): bool { return ($cfg['auth_bridge_logout'] ?? '1') === '1'; }

/**
 * An absolute http(s) address, or ''.
 *
 * These two settings become a redirect the browser follows, so the scheme check is the whole point:
 * `javascript:` in a settings field would be a stored XSS with an operator's own hand on it, and a
 * scheme-relative `//evil.example` reads as a path to a person and as a host to a browser.
 */
function authBridgeSafeUrl(string $url): string {
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) return '';
    if (!preg_match('#^https?://#i', $url)) return '';
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) return '';
    return $url;
}

// ─────────────────────────────────────────────────────────────────────────────
// Identities
// ─────────────────────────────────────────────────────────────────────────────

/** The identity a partner's key asserts, or null. Keyed by (key, their id) — see the schema note. */
function authIdentityFind(PDO $db, int $clientId, string $externalId): ?array {
    $st = $db->prepare("SELECT * FROM user_identities WHERE client_id = ? AND external_id = ? LIMIT 1");
    $st->execute([$clientId, $externalId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Every bridge a tracker account is reachable through. Used to SHOW where an account came from. */
function authIdentitiesForUser(PDO $db, int $userId): array {
    $st = $db->prepare("SELECT * FROM user_identities WHERE user_id = ? ORDER BY created_at ASC");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * One line naming where an account signs in from, or '' — the answer to "show the source".
 * A provider whose key has since been deleted still names itself: the label is copied at link time.
 */
function authIdentityLabel(PDO $db, int $userId): string {
    $rows = authIdentitiesForUser($db, $userId);
    $names = [];
    foreach ($rows as $r) {
        $n = trim((string)($r['provider'] ?? ''));
        if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
    }
    return implode(', ', $names);
}

/**
 * Link a tracker account to a partner's user. Returns the identity row.
 *
 * The UNIQUE on (user_id, client_id) is what stops one forum holding two identities pointing at the
 * same account — which would mean two different forum users could both sign in as that person.
 */
function authIdentityLink(PDO $db, int $userId, array $client, array $ext): array {
    $st = $db->prepare("INSERT INTO user_identities
            (user_id, client_id, provider, external_id, external_name, external_email, last_login_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE external_name = VALUES(external_name),
                                external_email = VALUES(external_email),
                                provider = VALUES(provider),
                                last_login_at = NOW()");
    $st->execute([
        $userId, (int)$client['id'], mb_substr((string)($client['label'] ?? ''), 0, 64),
        (string)$ext['external_id'],
        isset($ext['name']) && $ext['name'] !== '' ? mb_substr((string)$ext['name'], 0, 191) : null,
        isset($ext['email']) && $ext['email'] !== '' ? mb_substr((string)$ext['email'], 0, 191) : null,
    ]);
    return authIdentityFind($db, (int)$client['id'], (string)$ext['external_id']) ?? [];
}

/** Mark the far side's sign-out. Read back by authBridgeSessionEnded() on the next request here. */
function authIdentityMarkLogout(PDO $db, int $identityId): void {
    $db->prepare("UPDATE user_identities SET logout_at = NOW() WHERE id = ?")->execute([$identityId]);
}

/**
 * A free username derived from what the partner calls the person.
 *
 * Their name first, because that is the name the community knows them by; a numbered suffix when it
 * is taken, because refusing the sign-in over a name collision would be a dead end the person cannot
 * do anything about. The fallback is 'user<n>' rather than the external id — an id is the partner's
 * to change and often is not a name at all.
 */
function authBridgeUsername(PDO $db, string $preferred, string $externalId): string {
    $base = preg_replace('/[^A-Za-z0-9_.-]/', '', $preferred);
    $base = trim((string)$base, '.-_');
    if (strlen($base) < 3) $base = 'user' . preg_replace('/[^A-Za-z0-9]/', '', substr($externalId, 0, 12));
    if (strlen($base) < 3) $base = 'user';
    $base = substr($base, 0, 28);
    $taken = static function (PDO $db, string $name): bool {
        $st = $db->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    };
    if (userValidUsername($base) && !$taken($db, $base)) return $base;
    for ($i = 2; $i <= 200; $i++) {
        $try = substr($base, 0, 32 - strlen((string)$i)) . $i;
        if (userValidUsername($try) && !$taken($db, $try)) return $try;
    }
    // 200 collisions on one name is not a name problem any more; a random tail always terminates.
    return substr($base, 0, 24) . bin2hex(random_bytes(3));
}

// ─────────────────────────────────────────────────────────────────────────────
// Handoff tickets
// ─────────────────────────────────────────────────────────────────────────────

/** Mint a one-time ticket. Returns the PLAINTEXT — it is never retrievable from the row again. */
function authHandoffMint(PDO $db, int $userId, int $clientId, string $direction, int $ttl, string $ip = ''): string {
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO auth_handoffs (token_hash, user_id, client_id, direction, expires_at, created_ip)
                  VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)")
       ->execute([hash('sha256', $token), $userId, $clientId, $direction === 'out' ? 'out' : 'in',
                  max(30, $ttl), $ip !== '' ? substr($ip, 0, 45) : null]);
    return $token;
}

/**
 * Spend a ticket. Returns the row, or null when it never existed / has expired / was already spent.
 *
 * The claim is the UPDATE, not the SELECT. Two requests arriving with the same ticket both find an
 * unused row if you look first and mark second; only one of them can be the statement that changed
 * a row, so that one is the winner and the other gets null.
 */
function authHandoffRedeem(PDO $db, string $token, string $direction): ?array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $hash = hash('sha256', $token);
    $st = $db->prepare("UPDATE auth_handoffs SET used_at = NOW()
                         WHERE token_hash = ? AND direction = ? AND used_at IS NULL AND expires_at > NOW()");
    $st->execute([$hash, $direction === 'out' ? 'out' : 'in']);
    if ($st->rowCount() !== 1) return null;
    $sel = $db->prepare("SELECT * FROM auth_handoffs WHERE token_hash = ? LIMIT 1");
    $sel->execute([$hash]);
    return $sel->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Drop spent and expired tickets. Called from the janitor and, cheaply, when one is minted.
 * A spent ticket is kept for an hour so that a person who double-clicks a link is told "already
 * used" rather than "no such ticket" — the same event, but only one of those sentences is true.
 */
function authHandoffPrune(PDO $db): int {
    $st = $db->prepare("DELETE FROM auth_handoffs
                         WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                            OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))");
    $st->execute();
    return $st->rowCount();
}

// ─────────────────────────────────────────────────────────────────────────────
// Signing somebody in
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Find, link or create the tracker account behind a partner's assertion.
 *
 * Returns ['user' => row, 'identity' => row, 'created' => bool, 'merged' => bool] or
 * ['error' => code] with codes: bridge_off | users_off | invalid_external_id | no_account |
 * merge_ambiguous | create_failed:<reason> | account_suspended.
 *
 * The order is: a link we already have, then a merge if the operator allows one, then a new account.
 * Each step is narrower than the one before it, and the first that answers wins — an existing link
 * is a decision somebody already made and nothing later may override it.
 */
function authBridgeResolve(PDO $db, array $cfg, array $client, array $ext): array {
    if (!usersEnabled($cfg)) return ['error' => 'users_off'];
    if (!authBridgeEnabled($cfg)) return ['error' => 'bridge_off'];
    $externalId = trim((string)($ext['external_id'] ?? ''));
    if ($externalId === '' || strlen($externalId) > 191) return ['error' => 'invalid_external_id'];
    $ext['external_id'] = $externalId;
    $email = trim((string)($ext['email'] ?? ''));
    $name = trim((string)($ext['name'] ?? ''));

    // 1. already linked.
    $identity = authIdentityFind($db, (int)$client['id'], $externalId);
    if ($identity) {
        $user = userFindById($db, (int)$identity['user_id']);
        // The account can have been deleted or suspended here since the link was made. Both are
        // answers the partner needs, and neither is "make them a new one".
        if (!$user) return ['error' => 'no_account'];
        if (($user['status'] ?? 'active') !== 'active') return ['error' => 'account_suspended'];
        $identity = authIdentityLink($db, (int)$user['id'], $client, $ext);
        return ['user' => $user, 'identity' => $identity, 'created' => false, 'merged' => false];
    }

    // 2. the operator may allow an existing local account to be claimed by a matching VERIFIED
    //    address. Off by default; see the header.
    if ($email !== '' && authBridgeMergeMode($cfg) === 'email_verified' && userValidEmail($email)) {
        $st = $db->prepare("SELECT * FROM users WHERE email = ? AND email_verified = 1 LIMIT 1");
        $st->execute([$email]);
        $cand = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cand) {
            if (($cand['status'] ?? 'active') !== 'active') return ['error' => 'account_suspended'];
            // One forum, one identity per account. If this account is already reachable through this
            // same key under a different external id, two people are claiming it and neither wins.
            $ex = $db->prepare("SELECT 1 FROM user_identities WHERE user_id = ? AND client_id = ? LIMIT 1");
            $ex->execute([(int)$cand['id'], (int)$client['id']]);
            if ($ex->fetchColumn()) return ['error' => 'merge_ambiguous'];
            $identity = authIdentityLink($db, (int)$cand['id'], $client, $ext);
            auditLog($db, 'auth.bridge.merge', ['target_type' => 'user', 'target_id' => (int)$cand['id'],
                'summary' => $client['label'] . ' → ' . $cand['username'],
                'detail' => ['client_id' => (int)$client['id'], 'external_id' => $externalId, 'matched_on' => 'email_verified']]);
            return ['user' => $cand, 'identity' => $identity, 'created' => false, 'merged' => true];
        }
    }

    // 3. a new account.
    if (!authBridgeMayCreate($cfg)) return ['error' => 'no_account'];
    $username = authBridgeUsername($db, $name !== '' ? $name : $externalId, $externalId);
    // A password nobody knows, including us. The account is reachable through the bridge, and through
    // the ordinary "forgot my password" flow if it has an address — never with this string.
    $password = bin2hex(random_bytes(24)) . 'aZ9!';
    $r = userCreate($db, $cfg, $username, userValidEmail($email) ? $email : '', $password,
                    getClientIp($cfg), 'bridge:' . $client['label']);
    if (isset($r['error'])) {
        // email_taken with merging off is the honest case: somebody already has an account here with
        // that address, and taking it over is exactly what the operator said not to do.
        return ['error' => 'create_failed:' . $r['error']];
    }
    $user = $r['user'];
    // The partner already knows who this person is; making them confirm an address to a site they
    // did not choose to register with is a step that only loses people. The tracker still has not
    // verified anything itself, and does not claim to: email_verified stays 0.
    $identity = authIdentityLink($db, (int)$user['id'], $client, $ext);
    auditLog($db, 'auth.bridge.create', ['target_type' => 'user', 'target_id' => (int)$user['id'],
        'summary' => $client['label'] . ' → ' . $user['username'],
        'detail' => ['client_id' => (int)$client['id'], 'external_id' => $externalId]]);
    return ['user' => $user, 'identity' => $identity, 'created' => true, 'merged' => false];
}

/**
 * Has the far side ended this session since it was opened?
 *
 * Only asked of sessions that were OPENED through the bridge — an ordinary sign-in on this site is
 * not the forum's to end, and the hot path must not grow a query for everybody else's sake.
 */
function authBridgeSessionEnded(PDO $db): bool {
    if (empty($_SESSION['bridge_identity']) || empty($_SESSION['bridge_login_at'])) return false;
    // The comparison happens IN THE DATABASE.
    //
    // logout_at is written by NOW() in the database's session time zone; the session remembers a
    // PHP unix timestamp. Reading the column back with strtotime() would interpret a UTC datetime
    // in PHP's own zone, and this install sets db_time_zone = '+00:00' while PHP runs on local
    // time — a two-hour window in which a sign-out on the far side quietly did nothing. UNIX_TIMESTAMP()
    // converts using the same zone the value was written in, so both sides are the same clock.
    $st = $db->prepare("SELECT logout_at IS NOT NULL AND UNIX_TIMESTAMP(logout_at) >= ? AS ended
                          FROM user_identities WHERE id = ? LIMIT 1");
    $st->execute([(int)$_SESSION['bridge_login_at'], (int)$_SESSION['bridge_identity']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    // A deleted identity is a revoked one: the link the session rests on is gone.
    if (!$row) return true;
    return (int)$row['ended'] === 1;
}

/** Note on the session which bridge it came in through, so the check above knows to run at all. */
function authBridgeMarkSession(int $identityId): void {
    $_SESSION['bridge_identity'] = $identityId;
    $_SESSION['bridge_login_at'] = time();
}

// ─────────────────────────────────────────────────────────────────────────────
// The two browser-facing routes
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Where to send somebody after a bridged sign-in.
 *
 * A `next` that is not a plain path on this site is dropped rather than corrected: "?next=" on a
 * login endpoint is the classic open-redirect, and the only safe reading of `//evil.example` here
 * is "not ours".
 */
function authBridgeNext(string $raw, string $baseUrl): string {
    $raw = trim($raw);
    if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//') || strlen($raw) > 300) {
        return $baseUrl;
    }
    if (strpbrk($raw, "\r\n\t") !== false) return $baseUrl;
    return $raw;
}

/** Add a parameter to an address that may or may not already carry a query string. */
function authBridgeAppendParam(string $url, string $key, string $value): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value);
}

/**
 * The partner key to mint an OUTBOUND ticket for.
 *
 * The one this person already signs in through, when they have one — that is the forum they are
 * being sent to. Otherwise the single enabled key that could redeem it, because on the install this
 * is built for there IS one forum. Two candidates and no link is a question the code cannot answer,
 * so it answers null and the button is not offered.
 */
function authBridgeOutClient(PDO $db, int $userId): ?array {
    $st = $db->prepare("SELECT c.* FROM user_identities i JOIN api_clients c ON c.id = i.client_id
                         WHERE i.user_id = ? AND c.enabled = 1 ORDER BY i.last_login_at DESC LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $all = $db->query("SELECT * FROM api_clients WHERE enabled = 1 AND scope IN ('users','all')")->fetchAll(PDO::FETCH_ASSOC);
    return count($all) === 1 ? $all[0] : null;
}

/**
 * What to CALL the far side in a sentence a visitor reads.
 *
 * The operator already named it once, when they made the key — reusing that label means the button
 * says "Sign in with Forum TryHackX" rather than a generic word, and means there is not a second
 * setting saying the same thing that can drift away from the first. Falls back to a generic when
 * more than one key could be the one, because a wrong specific name is worse than a right vague one.
 */
function authBridgeProviderName(PDO $db, array $cfg): string {
    $all = $db->query("SELECT label FROM api_clients WHERE enabled = 1 AND scope IN ('users','all')")->fetchAll(PDO::FETCH_ASSOC);
    if (count($all) === 1) {
        $l = trim((string)$all[0]['label']);
        if ($l !== '') return $l;
    }
    return __('bridge.provider_default');
}

/**
 * Handle ?action=bridge and ?action=bridge_out. Redirects and exits when it handles one; returns
 * false otherwise so index.php carries on with its own routing.
 *
 * Both live here rather than as template files because neither renders a page: one spends a ticket
 * and sets a cookie, the other mints a ticket and leaves. A template that only ever calls header()
 * is a page in name only, and putting them in $routes would have them reached after output starts.
 */
function authBridgeHandleRoute(PDO $db, array $cfg, string $action, string $baseUrl): bool {
    if ($action !== 'bridge' && $action !== 'bridge_out') return false;
    $home = rtrim($baseUrl, '/') . '/';
    if (!authBridgeEnabled($cfg)) { header('Location: ' . $home, true, 302); exit; }

    if ($action === 'bridge') {
        $token = (string)($_GET['token'] ?? '');
        $row = authHandoffRedeem($db, $token, 'in');
        if (!$row) {
            // Expired, spent or invented. The login page says so in words the person can act on
            // ("ask the forum to send you again") rather than a bare failure.
            header('Location: ' . authBridgeAppendParam($home . '?action=login', 'bridge', 'failed'), true, 302);
            exit;
        }
        $user = userFindById($db, (int)$row['user_id']);
        if (!$user || ($user['status'] ?? 'active') !== 'active') {
            header('Location: ' . authBridgeAppendParam($home . '?action=login', 'bridge', 'failed'), true, 302);
            exit;
        }
        $st = $db->prepare("SELECT * FROM user_identities WHERE user_id = ? AND client_id = ? LIMIT 1");
        $st->execute([(int)$user['id'], (int)$row['client_id']]);
        $identity = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        userSessionStart($db, $user, getClientIp($cfg), null);
        if ($identity) {
            // Signing in clears the far side's sign-out: this is a NEW session, and the mark that
            // ended the last one must not end this one too.
            $db->prepare("UPDATE user_identities SET logout_at = NULL, last_login_at = NOW() WHERE id = ?")
               ->execute([(int)$identity['id']]);
            authBridgeMarkSession((int)$identity['id']);
        }
        // DELIBERATELY NOT userMaybeOpenPanelSession().
        //
        // The ordinary login form opens the admin panel for an admin-group member, because it has
        // just checked their password. This has checked a partner's key. Those are not the same
        // claim: a forum that can say "this browser is user 7" would otherwise be a forum that can
        // open the administrator's panel, and the operator who handed out that key was agreeing to
        // let it sign members in, not to let it administer the tracker. An admin arriving through
        // the bridge is signed in to the SITE and signs in to the panel the usual way.
        auditLog($db, 'auth.bridge.login', ['target_type' => 'user', 'target_id' => (int)$user['id'],
            'summary' => $user['username'], 'detail' => ['client_id' => (int)$row['client_id']]]);
        header('Location: ' . authBridgeNext((string)($_GET['next'] ?? ''), $home), true, 302);
        exit;
    }

    // ?action=bridge_out — "continue to the forum", for somebody already signed in here.
    $me = currentUser($db);
    $return = authBridgeReturnUrl($cfg);
    if (!$me || $return === '') { header('Location: ' . $home, true, 302); exit; }
    $client = authBridgeOutClient($db, (int)$me['id']);
    if (!$client) { header('Location: ' . $home, true, 302); exit; }
    $token = authHandoffMint($db, (int)$me['id'], (int)$client['id'], 'out', authBridgeTtl($cfg), getClientIp($cfg));
    authHandoffPrune($db);
    header('Location: ' . authBridgeAppendParam($return, 'thx_token', $token), true, 302);
    exit;
}
