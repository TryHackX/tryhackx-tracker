<?php
/**
 * whitelist_check — public lookup: is this hash registered / banned on the tracker?
 * GET ?hash=<40hex|magnet> or POST {"hash": "..."} / {"magnet": "..."}. Rate limited per IP bucket.
 * Never reveals who registered a hash (no IPs, no sources).
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    $raw = trim((string)($input['hash'] ?? $input['magnet'] ?? ''));
} else {
    $raw = trim((string)($_GET['hash'] ?? ''));
}
if ($raw === '' || strlen($raw) > 2100) {
    jsonResponse(['error' => 'Invalid info hash'], 400);
}
$p = parseMagnetOrHash($raw);
if (empty($p['hash'])) {
    jsonResponse(['error' => 'Invalid info hash or magnet link'], 400);
}
if (!rateLimitAllow('whitelist_check', ipBucket(getClientIp($cfg)), 30, 3600)) {
    jsonResponse(['error' => 'Too many lookups. Please wait a while and try again.'], 429);
}
$row = isHashWhitelisted($db, $p['hash']);
$banned = isHashBanned($db, $p['hash']) || ($row && (int)$row['banned'] === 1);
jsonResponse([
    'success'     => true,
    'hash'        => $p['hash'],
    'mode'        => trackerMode($cfg),
    'whitelisted' => $row !== null && (int)$row['banned'] === 0,
    'banned'      => $banned,
    'added_at'    => $row ? substr((string)$row['created_at'], 0, 10) : null,
]);
