<?php
/**
 * POST v1/auth/verify — the OTHER direction: the forum redeems a ticket the tracker minted.
 *
 *   Body:  {"token":"…"}
 *   Reply: {"ok":true,"user":{"id":7,"username":"kasia","email":"…","status":"active"},
 *           "external_id":"412","groups":[…]}
 *
 * ── the flow this completes ────────────────────────────────────────────────────────────────────
 * Somebody signed in HERE presses "continue to the forum" (?action=bridge_out). The tracker mints a
 * one-time ticket and sends the browser to auth_bridge_return_url with ?thx_token=… on it. The
 * forum's server — never its browser — posts that ticket here, gets back who the person is, and
 * opens its own session for them.
 *
 * That is why the ticket goes in a POST body from the partner's server rather than being trusted in
 * the redirect: the redirect only carries a ticket, and the ticket is worth nothing without the key.
 *
 * `external_id` is the id THIS person already has on the calling forum, when the two are linked. A
 * null means the forum has never seen them and should register them on its side — the mirror image
 * of what v1/auth/login does here.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/auth/verify', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);
if (!authBridgeEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'bridge_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
$token = trim((string)($payload['token'] ?? ''));
if ($token === '') jsonResponse(['ok' => false, 'error' => 'token_required'], 422);

$row = authHandoffRedeem($db, $token, 'out');
// One answer for "never existed", "expired" and "already spent". Telling the three apart to an
// unauthenticated-in-this-respect caller is telling somebody holding a guessed ticket how close
// they got.
if (!$row) jsonResponse(['ok' => false, 'error' => 'invalid_token'], 403);
// A ticket minted for one partner is not another partner's to spend.
if ((int)$row['client_id'] !== (int)$client['id']) jsonResponse(['ok' => false, 'error' => 'invalid_token'], 403);

$user = userFindById($db, (int)$row['user_id']);
if (!$user) jsonResponse(['ok' => false, 'error' => 'user_not_found'], 404);
if (($user['status'] ?? 'active') !== 'active') jsonResponse(['ok' => false, 'error' => 'account_suspended'], 403);

$identity = null;
$st = $db->prepare("SELECT * FROM user_identities WHERE user_id = ? AND client_id = ? LIMIT 1");
$st->execute([(int)$user['id'], (int)$client['id']]);
$identity = $st->fetch(PDO::FETCH_ASSOC) ?: null;

authHandoffPrune($db);
jsonResponse([
    'ok' => true,
    'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'email' => $user['email'],
               'status' => $user['status'], 'email_verified' => (int)($user['email_verified'] ?? 0) === 1,
               'created_at' => $user['created_at']],
    'external_id' => $identity ? (string)$identity['external_id'] : null,
    'groups' => userGroupsAll($db, (int)$user['id']),
    'server_time' => time(),
]);
