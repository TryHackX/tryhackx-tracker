<?php
// Admin: permanently delete a user with all memberships, notifications and tokens.
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$victim = userFindById($db, $id);
if (!$victim) jsonResponse(['error' => 'User not found'], 404);
if (userIsRootAdmin($victim, $cfg)) jsonResponse(['error' => 'The site owner account cannot be deleted.'], 400);
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
$db->prepare("DELETE FROM user_notifications WHERE user_id = ?")->execute([$id]);
$db->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$id]);
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
jsonResponse(['success' => true]);
