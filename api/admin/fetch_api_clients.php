<?php
// List API clients (never the secret hash).
$st = $db->query("SELECT id, label, key_id, secret_hint, scope, enabled, created_at, last_used_at,
                         last_used_ip, requests_count, auto_approve, abuse_auto_block, required_fields
                    FROM api_clients ORDER BY id DESC");
$clients = [];
foreach ($st->fetchAll() as $c) {
    $c['id'] = (int)$c['id'];
    $c['enabled'] = (int)$c['enabled'];
    $c['requests_count'] = (int)$c['requests_count'];
    $c['auto_approve'] = (int)$c['auto_approve'] === 1;
    $c['abuse_auto_block'] = (int)$c['abuse_auto_block'] === 1;
    $c['required_fields'] = apiClientCleanFields($c['required_fields'], (string)$c['scope']);
    // Built here rather than in the browser: the guide address has to match what the SERVER thinks
    // the key is configured to do, and the browser only knows what it last drew.
    // approve= from auto_approve, block= from abuse_auto_block — an `all` key has both chapters (1.73.0).
    $c['docs_url'] = apiClientDocsUrl((string)$c['scope'], $c['auto_approve'], $c['required_fields'], $c['abuse_auto_block']);
    $clients[] = $c;
}
jsonResponse(['clients' => $clients, 'api_enabled' => (($cfg['api_enabled'] ?? '0') === '1')]);
