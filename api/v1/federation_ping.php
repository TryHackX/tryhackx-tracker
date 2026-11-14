<?php
/**
 * GET/POST v1/federation/ping — authenticated health check for federation peers.
 * Requires the 'federation' scope. Reports what this node exports so a peer can sanity-check
 * its configuration before the first pull.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => __('api.common.method_not_allowed')], 405);
}
$rawBody = $_SERVER['REQUEST_METHOD'] === 'POST' ? apiReadRawBody() : '';
$client = apiAuthenticate($db, $cfg, 'v1/federation/ping', $rawBody);
apiRequireScope($client, 'federation');

// A database that cannot count is not a healthy node: 503, never "ok, 0 rows to export" (1.74.0, QUAL-26).
try { $exportable = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status = 'done'")->fetchColumn(); }
catch (\Throwable $e) {
    error_log('[api v1] federation/ping: the count failed: ' . $e->getMessage());
    if (!headers_sent()) header('Retry-After: 60');
    jsonResponse(['ok' => false, 'error' => __('api.db_unavailable')], 503);
}

jsonResponse([
    'ok' => true,
    'server_time' => time(),
    'node' => fedNodeName($cfg),
    'federation_enabled' => fedEnabled($cfg),
    'export_enabled' => fedExportEnabled($cfg),
    'export_files' => fedExportFiles($cfg),
    'export_max_batch' => fedExportMaxBatch($cfg),
    'exportable_rows' => $exportable,
    'api_version' => 1,
    'client' => $client['label'],
]);
