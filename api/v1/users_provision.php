<?php
/**
 * POST v1/users/provision — sales/shop integration: create an account for a buyer.
 * Requires the 'users' scope.
 *   Body: {"username": ..., "email": ..., "password": "optional — generated when absent",
 *          "group": "optional slug", "duration": "... (with group)", "note": ...}
 * Reply: {"ok":true,"user_id":N,"username":...,"password":"<only when generated — show it once>"}
 * Existing username/email → 409 with a code, so the shop can fall back to v1/users/grant.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/users/provision', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
$password = (string)($payload['password'] ?? '');
$generated = false;
if ($password === '') { $password = bin2hex(random_bytes(9)); $generated = true; }

$r = userCreate($db, $cfg, (string)($payload['username'] ?? ''), (string)($payload['email'] ?? ''), $password, getClientIp($cfg), 'api:' . $client['label']);
if (isset($r['error'])) {
    $code = in_array($r['error'], ['username_taken', 'email_taken'], true) ? 409 : 422;
    jsonResponse(['ok' => false, 'error' => $r['error']], $code);
}
$u = $r['user'];
userNotify($db, (int)$u['id'], 'welcome', 'Welcome to ' . ($cfg['site_name'] ?? 'the tracker') . '!',
    'Your account was created' . ($generated ? ' — please change the generated password after your first login.' : '.'));

$grant = null;
$groupSlug = trim((string)($payload['group'] ?? ''));
if ($groupSlug !== '') {
    $group = userGroupBySlug($db, $groupSlug);
    if ($group && $group['slug'] !== 'guest') {
        $expiresAt = userDurationExpiry($db, (int)$u['id'], (int)$group['id'], (string)($payload['duration'] ?? 'permanent'));
        if ($expiresAt !== '') {
            userGrantGroup($db, (int)$u['id'], (int)$group['id'], $expiresAt, 'api:' . $client['label'], mb_substr(trim((string)($payload['note'] ?? '')), 0, 255));
            $grant = ['group' => $group['slug'], 'expires_at' => $expiresAt];
        }
    }
}
jsonResponse(['ok' => true, 'user_id' => (int)$u['id'], 'username' => $u['username'],
              'password' => $generated ? $password : null, 'grant' => $grant, 'server_time' => time()]);
