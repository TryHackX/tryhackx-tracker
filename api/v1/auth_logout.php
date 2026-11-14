<?php
/**
 * POST v1/auth/logout — the forum says one of its people signed out over there.
 *
 *   Body:  {"external_id":"412"}
 *   Reply: {"ok":true,"found":true,"user_id":7}
 *
 * ── why this does not delete a session ─────────────────────────────────────────────────────────
 * A session here is a cookie in somebody's browser and a file on this server, and neither is
 * addressable from a server-to-server call: the tracker does not know which of a hundred session
 * files belongs to forum user 412. What it can do is mark the LINK as signed out, and the next
 * request that arrives carrying a session opened through that link is refused and cleaned up
 * (authBridgeSessionEnded, read in currentUser).
 *
 * So the effect is "signed out here too", one page load later at the latest. Sessions that were NOT
 * opened through the bridge are untouched on purpose — somebody who signed in here with their own
 * password did not do that through the forum, and the forum does not get to end it.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/auth/logout', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);
if (!authBridgeEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'bridge_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
$externalId = trim((string)($payload['external_id'] ?? ''));
if ($externalId === '') jsonResponse(['ok' => false, 'error' => 'external_id_required'], 422);

$identity = authIdentityFind($db, (int)$client['id'], $externalId);
// Not an error. A forum signing out somebody who never came here is the normal case, and answering
// 404 would have integrators special-casing it on every logout.
if (!$identity) jsonResponse(['ok' => true, 'found' => false, 'server_time' => time()]);

authIdentityMarkLogout($db, (int)$identity['id']);
// Any ticket minted for this person and not yet spent dies with the session it was going to open.
$db->prepare("UPDATE auth_handoffs SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
   ->execute([(int)$identity['user_id']]);

jsonResponse(['ok' => true, 'found' => true, 'user_id' => (int)$identity['user_id'], 'server_time' => time()]);
