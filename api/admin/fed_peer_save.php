<?php
/**
 * Admin: create/update a federation peer. Body: {id?, name, base_url, bearer?('' keep | 'CLEAR' | key),
 * pull_enabled?, pull_files?, grant_inbound?}. With grant_inbound=1 an api_clients row (scope
 * 'federation') is created for the peer and its bearer is returned ONCE — hand it to the peer admin.
 */
requirePost();
$input = readJsonBody();
$id = isset($input['id']) && (int)$input['id'] > 0 ? (int)$input['id'] : null;
$res = fedPeerSave($db, $id, $input);
if (isset($res['error'])) jsonResponse(['error' => $res['error']], 400);
$peerId = (int)$res['id'];

$inbound = null;
if (!empty($input['grant_inbound'])) {
    $st = $db->prepare("SELECT name, api_client_id FROM fed_peers WHERE id = ?");
    $st->execute([$peerId]);
    $peer = $st->fetch(PDO::FETCH_ASSOC);
    if ($peer && (int)($peer['api_client_id'] ?? 0) > 0) {
        jsonResponse(['error' => 'This peer already has inbound access. Delete and re-add the peer (or the API client) to rotate the key.'], 400);
    }
    $c = apiClientCreate($db, 'federation: ' . $peer['name'], 'federation');
    $db->prepare("UPDATE fed_peers SET api_client_id = ? WHERE id = ?")->execute([(int)$c['id'], $peerId]);
    $inbound = ['bearer' => $c['key_id'] . '.' . $c['secret'], 'key_id' => $c['key_id']];
}
jsonResponse(['success' => true, 'id' => $peerId, 'inbound' => $inbound]);
