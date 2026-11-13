<?php
/**
 * POST v1/auth/merge — attach a forum identity to an account that already exists here.
 *
 *   Body:  {"external_id":"412", "login":"kasia"}          — or "user_id": 7
 *          {"external_id":"412", "unlink":true}            — the other direction
 *   Reply: {"ok":true,"linked":true,"user":{"id":7,"username":"kasia"}}
 *
 * ── why this is a separate endpoint from login ─────────────────────────────────────────────────
 * v1/auth/login will not take over an existing account: a key that can assert an email address
 * could otherwise assert the administrator's. This one does exactly that takeover, and that is why
 * it is deliberate, named, audited, and refuses every case where it would have to guess.
 *
 * The intended use is the moment somebody presses "connect my forum account" — the forum knows who
 * they are over there, they have just proved who they are over here, and the two sides agree. What
 * this endpoint must never become is a way to link an account whose owner never asked, so it takes
 * an explicit account and never a pattern.
 *
 * `unlink` removes the link and nothing else. The tracker account stays, with everything on it —
 * unlinking is not deleting, and a person who leaves the forum does not lose their favourites.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/auth/merge', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);
if (!authBridgeEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'bridge_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
$externalId = trim((string)($payload['external_id'] ?? ''));
if ($externalId === '' || strlen($externalId) > 191) jsonResponse(['ok' => false, 'error' => 'external_id_required'], 422);

$existing = authIdentityFind($db, (int)$client['id'], $externalId);

// ── unlink ──────────────────────────────────────────────────────────────────────────────────────
if (!empty($payload['unlink'])) {
    if (!$existing) jsonResponse(['ok' => true, 'linked' => false, 'found' => false, 'server_time' => time()]);
    $db->prepare("DELETE FROM user_identities WHERE id = ?")->execute([(int)$existing['id']]);
    // Any ticket already minted for this person and not yet spent dies with the link. Without this
    // there is a two-minute window in which a handoff issued a moment before the unlink still opens
    // a session — and it would open one the far side can no longer end, because the row
    // authBridgeSessionEnded() reads is the one just deleted.
    $db->prepare("UPDATE auth_handoffs SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
       ->execute([(int)$existing['user_id']]);
    // The sessions that link was holding open go with it — authBridgeSessionEnded() reads a missing
    // identity as a revoked one, which is what this is.
    auditLog($db, 'auth.bridge.unlink', ['target_type' => 'user', 'target_id' => (int)$existing['user_id'],
        'summary' => $client['label'] . ' ✕ ' . $externalId,
        'detail' => ['client_id' => (int)$client['id'], 'external_id' => $externalId]]);
    jsonResponse(['ok' => true, 'linked' => false, 'found' => true,
                  'user_id' => (int)$existing['user_id'], 'server_time' => time()]);
}

// ── link ────────────────────────────────────────────────────────────────────────────────────────
$user = null;
if (!empty($payload['user_id'])) {
    $user = userFindById($db, (int)$payload['user_id']);
} elseif (trim((string)($payload['login'] ?? '')) !== '') {
    $user = userFindByLogin($db, trim((string)$payload['login']));
} else {
    jsonResponse(['ok' => false, 'error' => 'login_or_user_id_required'], 422);
}
if (!$user) jsonResponse(['ok' => false, 'error' => 'user_not_found'], 404);
if (($user['status'] ?? 'active') !== 'active') jsonResponse(['ok' => false, 'error' => 'account_suspended'], 403);

// Already linked, and to somebody else: two people claim one account, or one person claims two.
// Neither is a thing to resolve by guessing — say which conflict it is and let a person decide.
if ($existing && (int)$existing['user_id'] !== (int)$user['id']) {
    jsonResponse(['ok' => false, 'error' => 'external_id_linked_elsewhere',
                  'linked_user_id' => (int)$existing['user_id']], 409);
}
$other = $db->prepare("SELECT external_id FROM user_identities WHERE user_id = ? AND client_id = ? LIMIT 1");
$other->execute([(int)$user['id'], (int)$client['id']]);
$otherExt = (string)($other->fetchColumn() ?: '');
if ($otherExt !== '' && $otherExt !== $externalId) {
    jsonResponse(['ok' => false, 'error' => 'account_linked_elsewhere', 'linked_external_id' => $otherExt], 409);
}

$identity = authIdentityLink($db, (int)$user['id'], $client, [
    'external_id' => $externalId,
    'name' => (string)($payload['username'] ?? ''),
    'email' => (string)($payload['email'] ?? ''),
]);
auditLog($db, 'auth.bridge.link', ['target_type' => 'user', 'target_id' => (int)$user['id'],
    'summary' => $client['label'] . ' → ' . $user['username'],
    'detail' => ['client_id' => (int)$client['id'], 'external_id' => $externalId, 'explicit' => true]]);

jsonResponse([
    'ok' => true, 'linked' => true, 'created' => false,
    'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'status' => $user['status']],
    'identity_id' => (int)($identity['id'] ?? 0),
    'server_time' => time(),
]);
