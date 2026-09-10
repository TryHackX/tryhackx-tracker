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
// The abuse half is the other way round on purpose: absent means REVIEW. `auto_approve` above
// defaults to 1 because a partner's registrations are not an escalation; a key that can take a
// torrent off the tracker starts with nobody having said it may (see api/v1/blacklist_submit.php).
$autoBlock = !empty($input['abuse_auto_block']) && $input['abuse_auto_block'] !== '0' && $input['abuse_auto_block'] !== 'false' ? 1 : 0;
$fields = apiClientCleanFields($input['required_fields'] ?? [], $scope);
$db->prepare("UPDATE api_clients SET auto_approve = ?, abuse_auto_block = ?, required_fields = ? WHERE id = ?")
   ->execute([$autoApprove, $autoBlock, implode(',', $fields), (int)$c['id']]);
jsonResponse([
    'success' => true,
    'id' => $c['id'],
    'key_id' => $c['key_id'],
    'secret' => $c['secret'],
    'label' => $c['label'],
    'scope' => $c['scope'],
    'bearer' => $c['key_id'] . '.' . $c['secret'],
    'auto_approve' => $autoApprove === 1,
    'abuse_auto_block' => $autoBlock === 1,
    'required_fields' => $fields,
    // The address of the instructions to send with the key. It carries no secret — only which
    // choices were made — so it is safe in the same mail as the key without being the key.
    'docs_url' => apiClientDocsUrl((string)$c['scope'], $autoApprove === 1, $fields),
]);
