<?php
/**
 * POST v1/auth/status — what does the tracker think about this person right now?
 *
 *   Body:  {"external_id":"412"}   — or {"user_id":7} / {"login":"kasia"}
 *   Reply: {"ok":true,"linked":true,"user":{…},"external_id":"412",
 *           "last_login_at":"…","logged_out_at":null,"groups":[…]}
 *
 * The forum polls this to notice a sign-out that happened HERE — the other half of two-way
 * sign-out. The tracker deliberately makes no outbound call of its own: a webhook to an address in
 * a settings field is a request this server makes to wherever that field points, and a bridge is
 * not worth handing anybody that.
 *
 * `logged_out_at` is set the moment somebody signs out here through a bridged session, and cleared
 * the next time they sign in through the bridge.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/auth/status', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);
if (!authBridgeEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'bridge_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);

$identity = null;
$user = null;
$externalId = trim((string)($payload['external_id'] ?? ''));
if ($externalId !== '') {
    $identity = authIdentityFind($db, (int)$client['id'], $externalId);
    if ($identity) $user = userFindById($db, (int)$identity['user_id']);
} elseif (!empty($payload['user_id'])) {
    $user = userFindById($db, (int)$payload['user_id']);
} elseif (trim((string)($payload['login'] ?? '')) !== '') {
    $user = userFindByLogin($db, trim((string)$payload['login']));
} else {
    jsonResponse(['ok' => false, 'error' => 'external_id_or_user_required'], 422);
}

if (!$user) jsonResponse(['ok' => true, 'linked' => false, 'found' => false, 'server_time' => time()]);
if (!$identity) {
    $st = $db->prepare("SELECT * FROM user_identities WHERE user_id = ? AND client_id = ? LIMIT 1");
    $st->execute([(int)$user['id'], (int)$client['id']]);
    $identity = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

jsonResponse([
    'ok' => true,
    'found' => true,
    'linked' => $identity !== null,
    'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'status' => $user['status']],
    'external_id' => $identity ? (string)$identity['external_id'] : null,
    'provider' => $identity ? (string)$identity['provider'] : null,
    'last_login_at' => $identity ? $identity['last_login_at'] : null,
    'logged_out_at' => $identity ? $identity['logout_at'] : null,
    'groups' => userGroupsAll($db, (int)$user['id']),
    'server_time' => time(),
]);
