<?php
// Admin: remove one group membership. Body: {id, group_id}
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$groupId = (int)($input['group_id'] ?? 0);
$victim = userFindById($db, $id);
if (!$victim) jsonResponse(['error' => 'User not found'], 404);
if ($groupId <= 0) jsonResponse(['error' => 'group_id required'], 400);
if (userIsRootAdmin($victim, $cfg)) {
    $slug = $db->prepare("SELECT slug FROM user_groups WHERE id = ?");
    $slug->execute([$groupId]);
    if ($slug->fetchColumn() === 'admin') {
        jsonResponse(['error' => 'The site owner cannot lose the admin group.'], 400);
    }
}
$ok = userRevokeGroup($db, $id, $groupId);
jsonResponse(['success' => true, 'removed' => $ok]);
