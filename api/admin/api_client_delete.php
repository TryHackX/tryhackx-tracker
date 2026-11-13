<?php
// Hard delete. Callers still using the key get "unknown_key" → banned, which is intended for a revoked key.
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => __('api.federation.invalid_id')], 400);
}
$st = $db->prepare("DELETE FROM api_clients WHERE id = ?");
$st->execute([$id]);
if ($st->rowCount() === 0) {
    jsonResponse(['error' => __('api.federation.client_not_found')], 404);
}
jsonResponse(['success' => true]);
