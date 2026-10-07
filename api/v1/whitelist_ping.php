<?php
/**
 * GET v1/whitelist/ping — authenticated health check for API consumers (the forum's "Test connection").
 * Response: {"ok":true,"server_time":T,"mode":"whitelist|blacklist","whitelist_count":N,"api_version":1,"client":"label"}
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => __('api.common.method_not_allowed')], 405);
}
$rawBody = $_SERVER['REQUEST_METHOD'] === 'POST' ? apiReadRawBody() : '';
$client  = apiAuthenticate($db, $cfg, 'v1/whitelist/ping', $rawBody);
apiRequireScope($client, 'whitelist');

// A database that cannot count is not a healthy node: 503, never "ok, 0 torrents" (1.74.0, QUAL-26 — the partner's
// "Test connection" read a database error as an empty whitelist).
try { $count = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 0")->fetchColumn(); }
catch (\Throwable $e) {
    error_log('[api v1] whitelist/ping: the count failed: ' . $e->getMessage());
    if (!headers_sent()) header('Retry-After: 60');
    jsonResponse(['ok' => false, 'error' => __('api.db_unavailable')], 503);
}

jsonResponse([
    'ok' => true,
    'server_time' => time(),
    'mode' => trackerMode($cfg),
    'whitelist_count' => $count,
    'api_version' => 1,
    'client' => (string)$client['label'],
]);
