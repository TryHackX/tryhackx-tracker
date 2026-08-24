<?php
// Admin: delete a group (memberships are removed with it). System groups are protected.
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$st = $db->prepare("SELECT is_system FROM user_groups WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) jsonResponse(['error' => 'Group not found'], 404);
if ((int)$row['is_system'] === 1) jsonResponse(['error' => 'System groups (guest / member) cannot be deleted'], 400);
$db->prepare("DELETE FROM user_group_members WHERE group_id = ?")->execute([$id]);
$db->prepare("DELETE FROM user_groups WHERE id = ?")->execute([$id]);
jsonResponse(['success' => true]);
