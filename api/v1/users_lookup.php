<?php
/**
 * POST v1/users/lookup — sales/shop integration: look a user up.
 * Requires the 'users' or the narrower 'shop' scope.
 *   Body: {"login": "<username or email>"} — or {"user_id": N} / {"external_id": "<their id>"}
 * Reply: {"ok":true,"found":bool,"user":{id,username,email,status,created_at},"groups":[...],
 *         "effective":bool,"reason":"…"}
 *
 * `effective` is here so a shop can check BEFORE selling: an account whose address is unverified
 * sits at guest level whatever it is granted, and telling the customer that at the till is cheaper
 * than telling them after they have paid.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/users/lookup', $rawBody);
apiRequireScope($client, 'users', 'shop');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
// The historic error code for "you told me nothing", kept exactly: a caller written against 1.51.0
// must not start seeing a code it has never heard of.
if (trim((string)($payload['login'] ?? '')) === '' && (int)($payload['user_id'] ?? 0) <= 0
    && trim((string)($payload['external_id'] ?? '')) === '') {
    jsonResponse(['ok' => false, 'error' => 'login_required'], 422);
}
$target = userApiTarget($db, $client, $payload);
// A lookup that finds nobody is an ANSWER, not an error — that is what this endpoint is for.
if (($target['error'] ?? '') === 'user_not_found') jsonResponse(['ok' => true, 'found' => false, 'server_time' => time()]);
if (isset($target['error'])) jsonResponse(['ok' => false, 'error' => $target['error']], (int)$target['status']);
$u = $target['user'];

$eff = userGrantEffective($db, $cfg, $u);
jsonResponse([
    'ok' => true, 'found' => true,
    'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'],
               'status' => $u['status'], 'created_at' => $u['created_at']],
    'groups' => userGroupsAll($db, (int)$u['id']),
    'effective' => $eff['effective'], 'reason' => $eff['reason'],
    'server_time' => time(),
]);
