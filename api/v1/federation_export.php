<?php
/**
 * POST v1/federation/export — one page of resolved index metadata for a federation peer.
 * Requires the 'federation' scope and fed_export_enabled=1.
 *
 *   Body: {"since": <unix ts of meta_fetched_at cursor, 0 = from the beginning>,
 *          "after": "<info_hash tie-break within the same second, optional>",
 *          "limit": N (<= fed_export_max_batch), "files": true|false, "gzip": true|false,
 *          "format": "ndjson" (optional — stream one row per line instead of one JSON document)}
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
// Which peer is asking. Knowing that lets the export leave out whatever this same peer gave us,
// which is the difference between a steady exchange and two nodes trading the same rows for ever.
$callerPeer = fedPeerNameForClient($db, (int)($client['id'] ?? 0));

// ── NDJSON: one JSON object per line, sent as it is built ────────────────────────────────────
// The buffered reply below assembles the whole page — rows AND every file record of every row — in
// memory before a byte leaves. `fed_export_max_batch` counts TORRENTS, so a page of 20 000 rows of
// a hundred files each is two million records in a PHP array. This mode never holds more than one
// chunk, and stops on whichever of rows / bytes / file-records runs out first, so a heavy catalogue
// produces smaller pages by itself instead of the peer having to guess a batch size.
//
// Shape: line 1 is the header, then one row per line, and the LAST line is the trailer carrying the
// cursor. A truncated transfer is therefore detectable — no trailer, no cursor, nothing committed.
if (($payload['format'] ?? '') === 'ndjson') {
    while (ob_get_level()) ob_end_clean();

    // Compress incrementally rather than gzencode()ing a finished string: it keeps memory flat AND
    // lets the byte budget count what actually goes on the wire. SYNC_FLUSH once per line would
    // cost too much ratio, so the deflate context is flushed per chunk (see $flushEvery below).
    $gzip = !empty($payload['gzip']) && function_exists('deflate_init');
    $ctx = $gzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
    if ($gzip && $ctx === false) { $gzip = false; $ctx = null; }

    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store');
    if ($gzip) header('Content-Encoding: gzip');

    $sent = 0;
    $pending = 0;
    $flushEvery = 64;   // lines between deflate SYNC_FLUSHes: flat memory, accurate count, sane ratio
    $put = static function (string $chunk, bool $final = false) use (&$ctx, $gzip, &$sent, &$pending, $flushEvery): int {
        if (!$gzip) { echo $chunk; $sent += strlen($chunk); flush(); return strlen($chunk); }
        $pending++;
        $mode = ($final || $pending >= $flushEvery) ? ($final ? ZLIB_FINISH : ZLIB_SYNC_FLUSH) : ZLIB_NO_FLUSH;
        if ($mode !== ZLIB_NO_FLUSH) $pending = 0;
        $out = deflate_add($ctx, $chunk, $mode);
        if ($out === false) return 0;
        echo $out;
        $sent += strlen($out);
        if ($mode !== ZLIB_NO_FLUSH) flush();
        return strlen($out);
    };

    $put(json_encode([
        'ok' => true, 'node' => fedNodeName($cfg), 'server_time' => time(),
        'format' => 'ndjson',
        'limits' => ['rows' => min($limit, fedExportMaxBatch($cfg)),
                     'bytes' => fedExportMaxBytes($cfg), 'files' => fedExportMaxFiles($cfg)],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    $res = fedExportStream($db, $cfg, $since, $after, $limit, $withFiles, $put, $callerPeer);

    $put(json_encode([
        'end' => true, 'rows' => $res['rows'], 'files' => $res['files'],
        'next' => $res['next'], 'has_more' => $res['has_more'], 'stopped_by' => $res['stopped_by'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", true);

    apiChargeBytes($db, $client, $sent);
    exit;
}

$res = fedExportRows($db, $cfg, $since, $after, $limit, $withFiles, $callerPeer);

$out = [
    'ok' => true, 'node' => fedNodeName($cfg), 'server_time' => time(),
    'rows' => $res['rows'], 'next' => $res['next'], 'has_more' => $res['has_more'],
];
// The request that asks for a page is a few bytes; the reply is the part that costs bandwidth, so
// that is what the daily budget has to count.
if (!empty($payload['gzip'])) {
    $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false) {
        $body = gzencode($json, 6);
        apiChargeBytes($db, $client, strlen($body));
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Encoding: gzip');
        echo $body;
        exit;
    }
}
$plain = json_encode($out, JSON_UNESCAPED_UNICODE);
apiChargeBytes($db, $client, $plain === false ? 0 : strlen($plain));
jsonResponse($out);
