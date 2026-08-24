<?php
/**
 * POST v1/users/revoke — sales/shop integration: remove a group (refund / chargeback).
 * Requires the 'users' scope. Body: {"login": ..., "group": "<slug>"}
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/users/revoke', $rawBody);
apiRequireScope($client, 'users');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
$u = userFindByLogin($db, trim((string)($payload['login'] ?? '')));
if (!$u) jsonResponse(['ok' => false, 'error' => 'user_not_found'], 404);
$group = userGroupBySlug($db, trim((string)($payload['group'] ?? '')));
if (!$group) jsonResponse(['ok' => false, 'error' => 'group_not_found'], 404);
$removed = userRevokeGroup($db, (int)$u['id'], (int)$group['id']);
jsonResponse(['ok' => true, 'removed' => $removed, 'server_time' => time()]);
