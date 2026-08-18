<?php
// Create an API client. The bearer token is returned ONCE — only its hash is stored.
requirePost();

$input = readJsonBody();
$label = trim((string)($input['label'] ?? ''));
if ($label === '') {
    jsonResponse(['error' => 'Label is required'], 400);
}
$c = apiClientCreate($db, $label);
jsonResponse([
    'success' => true,
    'id' => $c['id'],
    'key_id' => $c['key_id'],
    'secret' => $c['secret'],
    'label' => $c['label'],
    'bearer' => $c['key_id'] . '.' . $c['secret'],
]);
