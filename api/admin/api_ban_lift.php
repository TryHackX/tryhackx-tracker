<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}
$st = $db->prepare("UPDATE api_bans SET lifted_at = NOW(), lifted_by = 'admin' WHERE id = ? AND lifted_at IS NULL");
$st->execute([$id]);
jsonResponse(['success' => true, 'lifted' => $st->rowCount() > 0]);
