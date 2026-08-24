<?php
/**
 * POST v1/users/lookup — sales/shop integration: look a user up by username or email.
 * Requires the 'users' scope. Body: {"login": "<username or email>"}
 * Reply: {"ok":true,"found":bool,"user":{id,username,email,status,created_at},"groups":[...]}
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/users/lookup', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
$login = trim((string)($payload['login'] ?? ''));
if ($login === '') jsonResponse(['ok' => false, 'error' => 'login_required'], 422);
$u = userFindByLogin($db, $login);
if (!$u) jsonResponse(['ok' => true, 'found' => false]);

jsonResponse([
    'ok' => true, 'found' => true,
    'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'],
               'status' => $u['status'], 'created_at' => $u['created_at']],
    'groups' => userGroupsAll($db, (int)$u['id']),
    'server_time' => time(),
]);
