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
// The two partner settings, applied straight after creation rather than threaded through
// apiClientCreate(): that function is also called by the installer and by tests, and a new required
// argument there would be a new way to get it wrong in three places instead of one.
$autoApprove = array_key_exists('auto_approve', $input) ? (!empty($input['auto_approve']) ? 1 : 0) : 1;
$fields = apiClientCleanFields($input['required_fields'] ?? []);
$db->prepare("UPDATE api_clients SET auto_approve = ?, required_fields = ? WHERE id = ?")
   ->execute([$autoApprove, implode(',', $fields), (int)$c['id']]);
jsonResponse([
    'success' => true,
    'id' => $c['id'],
    'key_id' => $c['key_id'],
    'secret' => $c['secret'],
    'label' => $c['label'],
    'scope' => $c['scope'],
    'bearer' => $c['key_id'] . '.' . $c['secret'],
    'auto_approve' => $autoApprove === 1,
    'required_fields' => $fields,
    // The address of the instructions to send with the key. It carries no secret — only which
    // choices were made — so it is safe in the same mail as the key without being the key.
    'docs_url' => apiClientDocsUrl((string)$c['scope'], $autoApprove === 1, $fields),
]);
