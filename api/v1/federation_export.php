<?php
/**
 * POST v1/federation/export — one page of resolved index metadata for a federation peer.
 * Requires the 'federation' scope and fed_export_enabled=1.
 *
 *   Body: {"since": <unix ts of meta_fetched_at cursor, 0 = from the beginning>,
 *          "after": "<info_hash tie-break within the same second, optional>",
 *          "limit": N (<= fed_export_max_batch), "files": true|false, "gzip": true|false}
 *
 * Reply: {"ok":true,"node":..., "rows":[{h,n,s,fc,pl,sl:[S,L],seen:{f,l,c},mf,files?:[[path,size]..]}],
 *         "next":{"since":ts,"after":hash}|null, "has_more":bool, "server_time":T}
 * With "gzip":true the JSON body is gzip-compressed (Content-Encoding: gzip) — meant for the
 * peer's federation.py, which requests it by default.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/federation/export', $rawBody);
apiRequireScope($client, 'federation');
if (!fedExportEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'export_disabled'], 403);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) $payload = [];
$since = max(0, (int)($payload['since'] ?? 0));
$after = strtolower(trim((string)($payload['after'] ?? '')));
$limit = (int)($payload['limit'] ?? fedExportMaxBatch($cfg));
$withFiles = fedExportFiles($cfg) && (!isset($payload['files']) || !empty($payload['files']));

$res = fedExportRows($db, $cfg, $since, $after, $limit, $withFiles);

$out = [
    'ok' => true, 'node' => fedNodeName($cfg), 'server_time' => time(),
    'rows' => $res['rows'], 'next' => $res['next'], 'has_more' => $res['has_more'],
];
if (!empty($payload['gzip'])) {
    $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Encoding: gzip');
        echo gzencode($json, 6);
        exit;
    }
}
jsonResponse($out);
