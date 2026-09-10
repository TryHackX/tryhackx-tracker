<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => __('api.federation.invalid_id')], 400);
}
$sets = [];
$params = [];
if (array_key_exists('enabled', $input)) {
    $sets[] = 'enabled = ?';
    $params[] = (!empty($input['enabled']) && $input['enabled'] !== '0' && $input['enabled'] !== 'false') ? 1 : 0;
}
if (isset($input['label'])) {
    $label = mb_substr(trim((string)$input['label']), 0, 100);
    if ($label === '') jsonResponse(['error' => __('api.federation.label_empty')], 400);
    $sets[] = 'label = ?';
    $params[] = $label;
}
if (array_key_exists('auto_approve', $input)) {
    $sets[] = 'auto_approve = ?';
    $params[] = (!empty($input['auto_approve']) && $input['auto_approve'] !== '0' && $input['auto_approve'] !== 'false') ? 1 : 0;
}
if (array_key_exists('abuse_auto_block', $input)) {
    $sets[] = 'abuse_auto_block = ?';
    $params[] = (!empty($input['abuse_auto_block']) && $input['abuse_auto_block'] !== '0' && $input['abuse_auto_block'] !== 'false') ? 1 : 0;
}
if (array_key_exists('required_fields', $input)) {
    // Cleaned against THIS key's scope, read from the row rather than taken from the request: the
    // scope of an existing key is not editable, so a body naming fields from another scope is either
    // a stale browser or somebody's script, and neither gets to widen what the key demands.
    $scopeSt = $db->prepare("SELECT scope FROM api_clients WHERE id = ?");
    $scopeSt->execute([$id]);
    $rowScope = (string)($scopeSt->fetchColumn() ?: 'whitelist');
    $sets[] = 'required_fields = ?';
    $params[] = implode(',', apiClientCleanFields($input['required_fields'], $rowScope));
}
if (!$sets) {
    jsonResponse(['error' => __('api.federation.nothing_to_update')], 400);
}
$params[] = $id;
$st = $db->prepare("UPDATE api_clients SET " . implode(', ', $sets) . " WHERE id = ?");
$st->execute($params);
if ($st->rowCount() === 0) {
    $chk = $db->prepare("SELECT 1 FROM api_clients WHERE id = ?");
    $chk->execute([$id]);
    if (!$chk->fetchColumn()) jsonResponse(['error' => __('api.federation.client_not_found')], 404);
}
jsonResponse(['success' => true]);
