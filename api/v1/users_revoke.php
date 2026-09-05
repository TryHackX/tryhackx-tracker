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

// The same two guards its siblings enforce, which this endpoint alone lacked.
//
// A shop's bearer token is not a stronger actor than a moderator with a session: it may move
// members between ORDINARY groups, and nothing more. Revoking a group that carries panel access is
// an administrative act (api/admin/user_revoke.php refuses it for a moderator), and stripping the
// admin group from the site owner is refused everywhere — this was the one door left open.
$gCarriesPanel = ($group['slug'] === 'admin');
foreach (array_keys(userGroupPermissions($group['permissions'] ?? '')) as $gp) {
    if (userIsPanelPermission($gp)) { $gCarriesPanel = true; break; }
}
if ($gCarriesPanel) {
    jsonResponse(['ok' => false, 'error' => 'group_not_revocable',
                  'detail' => 'A group that carries panel access can only be changed by the site owner.'], 403);
}
if ($group['slug'] === 'admin' && userIsRootAdmin($u, $cfg)) {
    jsonResponse(['ok' => false, 'error' => 'owner_protected',
                  'detail' => 'The site owner cannot lose the admin group.'], 403);
}
$removed = userRevokeGroup($db, (int)$u['id'], (int)$group['id']);
jsonResponse(['ok' => true, 'removed' => $removed, 'server_time' => time()]);
