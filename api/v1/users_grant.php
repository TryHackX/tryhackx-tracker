<?php
/**
 * POST v1/users/grant — sales/shop integration: grant a group after a purchase.
 * Requires the 'users' scope.
 *   Body: {"login": "<username or email>", "group": "<slug>",
 *          "duration": "1d|7d|14d|1m|3m|6m|1y|permanent" OR "until": "Y-m-d[ H:i[:s]]",
 *          "note": "order #123", "email": true|false}
 * Duration grants EXTEND an existing membership (from max(now, current expiry)) — repeat purchases
 * stack. "until" replaces the expiry instead. The user gets an in-app notification (+ email opt-in).
 * Reply: {"ok":true,"user_id":N,"group":slug,"expires_at":...|null}
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/users/grant', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
$u = userFindByLogin($db, trim((string)($payload['login'] ?? '')));
if (!$u) jsonResponse(['ok' => false, 'error' => 'user_not_found'], 404);
$group = userGroupBySlug($db, trim((string)($payload['group'] ?? '')));
if (!$group) jsonResponse(['ok' => false, 'error' => 'group_not_found'], 404);
if ($group['slug'] === 'guest') jsonResponse(['ok' => false, 'error' => 'group_not_grantable'], 422);

$until = trim((string)($payload['until'] ?? ''));
if ($until !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) $until .= ' 23:59:59';
    $t = strtotime($until);
    if ($t === false || $t <= time()) jsonResponse(['ok' => false, 'error' => 'invalid_until'], 422);
    $expiresAt = date('Y-m-d H:i:s', $t);
} else {
    $expiresAt = userDurationExpiry($db, (int)$u['id'], (int)$group['id'], (string)($payload['duration'] ?? 'permanent'));
    if ($expiresAt === '') jsonResponse(['ok' => false, 'error' => 'invalid_duration', 'allowed' => ['1d','7d','14d','1m','3m','6m','1y','permanent']], 422);
}
$note = mb_substr(trim((string)($payload['note'] ?? '')), 0, 255);
userGrantGroup($db, (int)$u['id'], (int)$group['id'], $expiresAt, 'api:' . $client['label'], $note);
if (!empty($payload['email'])) {
    userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — you are now in the "' . $group['name'] . '" group',
        'Access granted ' . ($expiresAt === null ? 'permanently' : 'until ' . $expiresAt) . '.' . ($note !== '' ? "\nNote: " . $note : ''));
}
jsonResponse(['ok' => true, 'user_id' => (int)$u['id'], 'group' => $group['slug'], 'expires_at' => $expiresAt, 'server_time' => time()]);
