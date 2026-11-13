<?php
// Admin: remove one group membership. Body: {id, group_id}
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$groupId = (int)($input['group_id'] ?? 0);
$victim = userFindById($db, $id);
if (!$victim) jsonResponse(['error' => __('api.users.not_found')], 404);
if ($groupId <= 0) jsonResponse(['error' => __('api.users.group_id_required')], 400);

// Symmetric to user_grant.php: a moderator may not TAKE a panel group away either. Revoking is not
// obviously dangerous until you picture one moderator quietly removing another, or stripping the
// owner's own second account.
$gp = $db->prepare("SELECT slug, permissions FROM user_groups WHERE id = ?");
$gp->execute([$groupId]);
$grow = $gp->fetch(PDO::FETCH_ASSOC);
if ($grow) {
    $carriesPanel = $grow['slug'] === 'admin';
    foreach (array_keys(userGroupPermissions($grow['permissions'] ?? '')) as $k) {
        if (userIsPanelPermission($k)) { $carriesPanel = true; break; }
    }
    if ($carriesPanel && !empty($_SESSION['admin_via_user'])
        && !userIsAdminGroup($db, (int)$_SESSION['admin_via_user'])) {
        jsonResponse(['error' => __('api.users.owner_only_panel_group_revoke')], 403);
    }
}
if (userIsRootAdmin($victim, $cfg)) {
    $slug = $db->prepare("SELECT slug FROM user_groups WHERE id = ?");
    $slug->execute([$groupId]);
    if ($slug->fetchColumn() === 'admin') {
        jsonResponse(['error' => __('api.users.owner_keeps_admin')], 400);
    }
}
$ok = userRevokeGroup($db, $id, $groupId);
jsonResponse(['success' => true, 'removed' => $ok]);
