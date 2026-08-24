<?php
// List API clients (never the secret hash).
$st = $db->query("SELECT id, label, key_id, secret_hint, scope, enabled, created_at, last_used_at, last_used_ip, requests_count FROM api_clients ORDER BY id DESC");
$clients = [];
foreach ($st->fetchAll() as $c) {
    $c['id'] = (int)$c['id'];
    $c['enabled'] = (int)$c['enabled'];
    $c['requests_count'] = (int)$c['requests_count'];
    $clients[] = $c;
}
jsonResponse(['clients' => $clients, 'api_enabled' => (($cfg['api_enabled'] ?? '0') === '1')]);
