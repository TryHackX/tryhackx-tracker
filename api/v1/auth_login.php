<?php
/**
 * POST v1/auth/login — the forum signs one of its people in here.
 *
 *   Body:  {"external_id":"412", "username":"kasia", "email":"kasia@example.org"}
 *   Reply: {"ok":true, "created":false, "merged":false,
 *           "user":{"id":7,"username":"kasia","status":"active"},
 *           "handoff":{"token":"…","url":"https://tracker…/?action=bridge&token=…","expires_in":120}}
 *
 * ── what the caller does with that ─────────────────────────────────────────────────────────────
 * Send the browser to `handoff.url`. That is the whole integration: the ticket is spent there, a
 * real session cookie is set for this site, and the person lands signed in. Nothing about a session
 * can be done from a server-to-server call — the cookie belongs to the browser, and the browser has
 * to visit us to receive one.
 *
 * The ticket is single-use and lives for auth_bridge_ttl seconds (120 by default). Mint it when the
 * person clicks, not in advance.
 *
 * Requires the 'users' scope, the same one as provisioning accounts: both amount to "this key can
 * decide who exists here".
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/auth/login', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);
if (!authBridgeEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'bridge_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);

$r = authBridgeResolve($db, $cfg, $client, [
    'external_id' => (string)($payload['external_id'] ?? ''),
    'name'        => (string)($payload['username'] ?? ($payload['name'] ?? '')),
    'email'       => (string)($payload['email'] ?? ''),
]);
if (isset($r['error'])) {
    // 409 for "somebody already has that here", 422 for a malformed assertion, 403 for a rule the
    // operator set. The partner can tell the three apart and only one of them is worth retrying.
    $code = 422;
    if ($r['error'] === 'no_account' || $r['error'] === 'account_suspended') $code = 403;
    if ($r['error'] === 'merge_ambiguous' || str_starts_with($r['error'], 'create_failed:')) $code = 409;
    jsonResponse(['ok' => false, 'error' => $r['error']], $code);
}

$user = $r['user'];
$ttl = authBridgeTtl($cfg);
$token = authHandoffMint($db, (int)$user['id'], (int)$client['id'], 'in', $ttl, getClientIp($cfg));
authHandoffPrune($db);

jsonResponse([
    'ok' => true,
    'created' => (bool)$r['created'],
    'merged' => (bool)$r['merged'],
    'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'status' => $user['status']],
    'handoff' => [
        'token' => $token,
        // Absolute, because the partner's server is what redirects a browser here: a bare "/…"
        // means their site, and their site does not have this route.
        'url' => apiAbsoluteBase($cfg) . '/?action=bridge&token=' . $token,
        'expires_in' => $ttl,
    ],
    'server_time' => time(),
]);
