<?php
// Create an API client. The bearer token is returned ONCE — only its hash is stored.
requirePost();

$input = readJsonBody();
$label = trim((string)($input['label'] ?? ''));
if ($label === '') {
    jsonResponse(['error' => __('api.api_client.label_required')], 400);
}
$scope = trim((string)($input['scope'] ?? 'whitelist'));
if (!in_array($scope, apiClientScopes(), true)) {
    jsonResponse(['error' => __('api.api_client.invalid_scope')], 400);
}
$c = apiClientCreate($db, $label, $scope);
jsonResponse([
    'success' => true,
    'id' => $c['id'],
    'key_id' => $c['key_id'],
    'secret' => $c['secret'],
    'label' => $c['label'],
    'scope' => $c['scope'],
    'bearer' => $c['key_id'] . '.' . $c['secret'],
]);
