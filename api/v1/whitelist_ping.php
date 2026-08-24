<?php
/**
 * GET v1/whitelist/ping — authenticated health check for API consumers (the forum's "Test connection").
 * Response: {"ok":true,"server_time":T,"mode":"whitelist|blacklist","whitelist_count":N,"api_version":1,"client":"label"}
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}
$rawBody = $_SERVER['REQUEST_METHOD'] === 'POST' ? apiReadRawBody() : '';
$client  = apiAuthenticate($db, $cfg, 'v1/whitelist/ping', $rawBody);
apiRequireScope($client, 'whitelist');

$count = 0;
try { $count = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 0")->fetchColumn(); } catch (\Throwable $e) {}

jsonResponse([
    'ok' => true,
    'server_time' => time(),
    'mode' => trackerMode($cfg),
    'whitelist_count' => $count,
    'api_version' => 1,
    'client' => (string)$client['label'],
]);
