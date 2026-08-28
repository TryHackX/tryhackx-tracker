<?php
/**
 * GET whitelist_probe&hashes=a,b,c — how the submissions are getting on.
 *
 * The form polls this while a submission proves itself: metadata in, at least one peer, and the
 * torrent naming this tracker. Read-only, rate-limited, and it never starts anything — starting is
 * what the submit endpoint did.
 *
 * The states are the ones the page shows verbatim, because "nobody is sharing this" and "we could
 * not read the torrent" send somebody to completely different places, and collapsing them into
 * "failed" would be throwing away the only useful half of the answer.
 */
if (!wlProbeEnabled($cfg)) jsonResponse(['error' => 'This tracker does not check submissions.'], 404);

$raw = (string)($_GET['hashes'] ?? '');
$hashes = array_values(array_filter(array_map('trim', explode(',', $raw))));
if (!$hashes) jsonResponse(['error' => 'No hashes given'], 400);
if (count($hashes) > 64) jsonResponse(['error' => 'Too many at once'], 400);

if (!rateLimitAllow('wlprobe', ipBucket(getClientIp($cfg)), 240, 60)) {
    jsonResponse(['error' => 'Slow down.'], 429);
}

$status = wlProbeStatus($db, $hashes);
$done = 0;
foreach ($status as $s) if (in_array($s['state'], ['passed', 'failed', 'none', 'unknown'], true)) $done++;

jsonResponse([
    'success'  => true,
    'items'    => $status,
    'finished' => $done === count($status),
    'timeout_minutes' => wlProbeTimeoutMinutes($cfg),
]);
