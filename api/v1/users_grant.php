<?php
/**
 * POST v1/users/grant — sales/shop integration: grant a group after a purchase.
 * Requires the 'users' or the narrower 'shop' scope.
 *   Body: {"login": "<username or email>" | "user_id": N | "external_id": "<their id>",
 *          "group": "<slug>",
 *          "duration": "1d|7d|14d|1m|3m|6m|1y|permanent" OR "until": "Y-m-d[ H:i[:s]]",
 *          "order_id": "<the shop's own order id>", "force": true,
 *          "note": "order #123", "email": true|false}
 * Duration grants EXTEND an existing membership (from max(now, current expiry)) — repeat purchases
 * stack. "until" replaces the expiry instead. The user gets an in-app notification (+ email opt-in).
 * Reply: {"ok":true,"user_id":N,"group":slug,"previous_expires_at":…|null,"expires_at":…|null,
 *         "effective":bool,"reason":"…","order_id":"…","replayed":bool}
 *
 * ── what 1.65.0 added, and why ────────────────────────────────────────────────────────────────
 * A shop's webhook retries. That is not a fault in the shop — a dropped connection, a slow reply, a
 * redelivered queue message all look the same from here — and an endpoint that extends by a month on
 * every call hands out three months for one payment. So an optional `order_id` makes the call
 * idempotent: the first one does the work and records it, and every retry gets the SAME answer back
 * with `replayed: true`, having changed nothing.
 *
 * It also answers three questions a shop could not ask before: WHO (a username is not the only name
 * a customer has — `user_id` and the bridge's `external_id` name the same person), WHAT HAPPENED
 * (`previous_expires_at` beside `expires_at`, so the shop can print "extended to" rather than
 * guessing), and WAS IT WORTH ANYTHING (`effective: false` with a reason, because an unverified or
 * banned account is at guest level whatever its groups say — the grant is recorded regardless, and
 * starts working the moment the obstacle goes).
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/users/grant', $rawBody);
apiRequireScope($client, 'users', 'shop');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);

$target = userApiTarget($db, $client, $payload);
if (isset($target['error'])) jsonResponse(['ok' => false, 'error' => $target['error']], (int)$target['status']);
$u = $target['user'];
$group = userGroupBySlug($db, trim((string)($payload['group'] ?? '')));
if (!$group) jsonResponse(['ok' => false, 'error' => 'group_not_found'], 404);
if ($group['slug'] === 'guest') jsonResponse(['ok' => false, 'error' => 'group_not_grantable'], 422);

// A machine-to-machine key exists to move members between ordinary groups after a purchase or a
// forum action. Handing out PANEL access is not that: userMaybeOpenPanelSession() gives any member
// of the admin group a full panel session at their next sign-in, where panelCan() is unconditionally
// true — Settings, backups (whose archives carry every database password on this box) and the
// sudo-backed helpers. api/admin/user_grant.php refuses this for a moderator with a session; a
// bearer token in somebody else's shop is not a stronger actor than that.
$gPerms = userGroupPermissions($group['permissions'] ?? '');
$gCarriesPanel = ($group['slug'] === 'admin');
foreach (array_keys($gPerms) as $gp) { if (userIsPanelPermission($gp)) { $gCarriesPanel = true; break; } }
if ($gCarriesPanel) {
    jsonResponse(['ok' => false, 'error' => 'group_not_grantable',
                  'detail' => 'A group that carries panel access can only be granted by the site owner.'], 403);
}

$uid = (int)$u['id'];
$gid = (int)$group['id'];
$clientId = (int)$client['id'];
$orderId = trim((string)($payload['order_id'] ?? ''));
if ($orderId !== '' && !userValidOrderId($orderId)) {
    jsonResponse(['ok' => false, 'error' => 'invalid_order_id',
                  'detail' => 'An order id is printable text, at most 64 characters.'], 422);
}

/** The stored answer to an order that has been seen before. Identical every time, by construction. */
$replay = function (array $row) use ($client): void {
    auditNote(['target_type' => 'user', 'target_id' => (string)$row['user_id'],
               'summary' => 'order ' . $row['order_id'] . ' replayed (nothing changed)',
               'detail' => ['order_id' => $row['order_id'], 'action' => $row['action']]]);
    jsonResponse(['ok' => true, 'user_id' => (int)$row['user_id'], 'group_id' => (int)$row['group_id'],
                  'previous_expires_at' => $row['prev_expires_at'], 'expires_at' => $row['new_expires_at'],
                  'order_id' => $row['order_id'], 'order_state' => $row['action'], 'replayed' => true,
                  'server_time' => time()]);
};
// Before anything is computed: a retry must not even evaluate a duration, because "1m from now" is
// a different date every time it is asked.
if ($orderId !== '') {
    $seen = userOrderFind($db, $clientId, $orderId);
    if ($seen !== null) $replay($seen);
}

// What the membership looks like BEFORE this order — the thing a refund has to put back, and the
// number the shop prints beside the new one. A row that exists with expires_at NULL is a permanent
// membership; no row at all is also NULL, which is why userMembership() answers with the row.
$before = userMembership($db, $uid, $gid);
$prevMember = $before !== null;
$prevExpires = $before !== null ? $before['expires_at'] : null;

$until = trim((string)($payload['until'] ?? ''));
$durationCode = '';
if ($until !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) $until .= ' 23:59:59';
    $t = strtotime($until);
    if ($t === false || $t <= time()) jsonResponse(['ok' => false, 'error' => 'invalid_until'], 422);
    // `until` REPLACES, which on a permanent membership means taking one away. A shop that sells a
    // year to somebody who already has forever should not silently end their forever because its
    // template always sends an end date — so it is refused, and a caller that really means it says
    // so. (A timed membership may be shortened without ceremony: the shop is the one that sold it,
    // and `previous_expires_at` in the reply says what it was.)
    if ($prevMember && $prevExpires === null && empty($payload['force'])) {
        jsonResponse(['ok' => false, 'error' => 'would_shorten_permanent',
                      'detail' => 'This membership is permanent. Send "force": true to replace it with an end date.'], 409);
    }
    $expiresAt = date('Y-m-d H:i:s', $t);
} else {
    $durationCode = (string)($payload['duration'] ?? 'permanent');
    $expiresAt = userDurationExpiry($db, $uid, $gid, $durationCode);
    if ($expiresAt === '') jsonResponse(['ok' => false, 'error' => 'invalid_duration', 'allowed' => ['1d','7d','14d','1m','3m','6m','1y','permanent']], 422);
}

// The test-and-set. Whoever inserts the row does the grant; anybody who does not, replays what the
// winner wrote. Two retries landing in the same millisecond therefore produce one month, not two.
if ($orderId !== '') {
    $won = userOrderClaim($db, [
        'client_id' => $clientId, 'order_id' => $orderId, 'user_id' => $uid, 'group_id' => $gid,
        'action' => 'grant', 'duration' => $durationCode, 'until' => $until !== '' ? $expiresAt : null,
        'prev_member' => $prevMember, 'prev_expires_at' => $prevExpires, 'new_expires_at' => $expiresAt,
    ]);
    if (!$won) {
        $seen = userOrderFind($db, $clientId, $orderId);
        if ($seen !== null) $replay($seen);
    }
}

$note = mb_substr(trim((string)($payload['note'] ?? '')), 0, 255);
userGrantGroup($db, $uid, $gid, $expiresAt, 'api:' . $client['label'], $note);
if (!empty($payload['email'])) {
    userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — you are now in the "' . $group['name'] . '" group',
        'Access granted ' . ($expiresAt === null ? 'permanently' : 'until ' . $expiresAt) . '.' . ($note !== '' ? "\nNote: " . $note : ''));
}

// Recorded whether or not it does anything today: the shop paid for it, and an account that verifies
// its address next week must find the month it bought waiting rather than gone.
$eff = userGrantEffective($db, $cfg, $u);
auditNote([
    'target_type' => 'user', 'target_id' => (string)$uid,
    'summary' => 'granted "' . $group['slug'] . '" until ' . ($expiresAt ?? 'forever')
               . ($orderId !== '' ? ' (order ' . $orderId . ')' : ''),
    'detail' => ['group' => $group['slug'], 'order_id' => $orderId, 'duration' => $durationCode,
                 'previous_expires_at' => $prevExpires, 'expires_at' => $expiresAt,
                 'effective' => $eff['effective'], 'reason' => $eff['reason']],
]);
jsonResponse(['ok' => true, 'user_id' => $uid, 'group' => $group['slug'],
              'previous_expires_at' => $prevExpires, 'expires_at' => $expiresAt,
              'effective' => $eff['effective'], 'reason' => $eff['reason'],
              'order_id' => $orderId !== '' ? $orderId : null, 'replayed' => false,
              'server_time' => time()]);
