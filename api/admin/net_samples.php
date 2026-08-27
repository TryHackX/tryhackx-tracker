<?php
/**
 * GET — the packets/second series behind the "UDP traffic" chart.
 *
 * Auth is enforced by the router (admin/*); GET, so CSRF-exempt. Never public: unlike the swarm
 * timeline this shows how close the machine is to its limits, which is operational detail.
 *
 * Query: ?range=1h|6h|24h|7d|14d|30d  (or &from=&to= as UNIX seconds)
 * Buckets are chosen server-side (includes/netlimit.php → netlimitBucketFor) so a wide range never
 * ships more than NET_MAX_POINTS points.
 */

$ranges = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '14d' => 1209600, '30d' => 2592000];
$now = time();

$from = isset($_GET['from']) ? (int)$_GET['from'] : 0;
$to   = isset($_GET['to'])   ? (int)$_GET['to']   : 0;
if ($from <= 0 || $to <= $from) {
    $key = strtolower(trim((string)($_GET['range'] ?? '24h')));
    $span = $ranges[$key] ?? $ranges['24h'];
    $to = $now;
    $from = $now - $span;
} else {
    // a hand-made window is still bounded by the retention, so a crafted URL cannot ask for a scan
    // of the whole table
    $from = max($from, $now - netlimitKeepDays($cfg) * 86400 - 86400);
    $to   = min($to, $now + 60);
}

try {
    $data = netlimitSeries($db, $cfg, $from, $to);
} catch (\Throwable $e) {
    jsonResponse(['error' => 'Could not read the traffic samples: ' . $e->getMessage()], 500);
}

$data['ok'] = true;
$data['server_time'] = $now;
$data['sample_seconds'] = netlimitSampleSeconds($cfg);
$data['monitor'] = netlimitMonitorEnabled($cfg);
jsonResponse($data);
