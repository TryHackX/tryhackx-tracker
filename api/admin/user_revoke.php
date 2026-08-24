<?php
// Admin: remove one group membership. Body: {id, group_id}
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$groupId = (int)($input['group_id'] ?? 0);
if (!userFindById($db, $id)) jsonResponse(['error' => 'User not found'], 404);
if ($groupId <= 0) jsonResponse(['error' => 'group_id required'], 400);
$ok = userRevokeGroup($db, $id, $groupId);
jsonResponse(['success' => true, 'removed' => $ok]);
