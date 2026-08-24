<?php
/**
 * GET/POST v1/federation/ping — authenticated health check for federation peers.
 * Requires the 'federation' scope. Reports what this node exports so a peer can sanity-check
 * its configuration before the first pull.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}
$rawBody = $_SERVER['REQUEST_METHOD'] === 'POST' ? apiReadRawBody() : '';
$client = apiAuthenticate($db, $cfg, 'v1/federation/ping', $rawBody);
apiRequireScope($client, 'federation');

$exportable = 0;
try { $exportable = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status = 'done'")->fetchColumn(); } catch (\Throwable $e) {}

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
