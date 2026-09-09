<?php
// Admin: permanently delete a user with all memberships, notifications and tokens.
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$victim = userFindById($db, $id);
if (!$victim) jsonResponse(['error' => __('api.users.not_found')], 404);
if (userIsRootAdmin($victim, $cfg)) jsonResponse(['error' => __('api.users.owner_cannot_delete')], 400);
// The table list lives in userDeleteCascade() (includes/users.php), not here. It was here, and that
// is exactly why hash_votes was missed: a deleted account's votes stayed behind and went on counting.
$gone = userDeleteCascade($db, $id);
jsonResponse(['success' => true, 'removed' => $gone]);
