<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}
$sets = [];
$params = [];
if (array_key_exists('enabled', $input)) {
    $sets[] = 'enabled = ?';
    $params[] = (!empty($input['enabled']) && $input['enabled'] !== '0' && $input['enabled'] !== 'false') ? 1 : 0;
}
if (isset($input['label'])) {
    $label = mb_substr(trim((string)$input['label']), 0, 100);
    if ($label === '') jsonResponse(['error' => 'Label cannot be empty'], 400);
    $sets[] = 'label = ?';
    $params[] = $label;
}
if (!$sets) {
    jsonResponse(['error' => 'Nothing to update'], 400);
}
$params[] = $id;
$st = $db->prepare("UPDATE api_clients SET " . implode(', ', $sets) . " WHERE id = ?");
$st->execute($params);
if ($st->rowCount() === 0) {
    $chk = $db->prepare("SELECT 1 FROM api_clients WHERE id = ?");
    $chk->execute([$id]);
    if (!$chk->fetchColumn()) jsonResponse(['error' => 'Client not found'], 404);
}
jsonResponse(['success' => true]);
