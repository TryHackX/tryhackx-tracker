<?php
// Details for one whitelist row (details modal).
$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

$stmt = $db->prepare("SELECT * FROM whitelist WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    jsonResponse(['error' => 'Not found'], 404);
}

// Live scrape (cached in the row for WL_SCRAPE_TTL seconds); never let a tracker hiccup break the modal
$scrape = null;
try {
    $scrape = scrapeOpenTracker($db, $cfg, $row, false);
} catch (\Throwable $e) {
    $scrape = null;
}

$item = $row;
unset($item['meta_claim']);
$item['id'] = (int)$item['id'];
$item['banned'] = (int)$item['banned'];
$item['api_client_id'] = $item['api_client_id'] !== null ? (int)$item['api_client_id'] : null;
foreach (['total_size', 'files_count', 'piece_length', 'scrape_seeders', 'scrape_leechers', 'scrape_completed', 'meta_priority'] as $k) {
    if (array_key_exists($k, $item)) $item[$k] = $item[$k] !== null ? (int)$item[$k] : null;
}
$ref = null;
if (!empty($item['source_ref'])) {
    $decoded = json_decode((string)$item['source_ref'], true);
    if (is_array($decoded)) $ref = $decoded;
}
$item['source_ref'] = $ref;
if ($scrape) {
    $item['scrape_seeders'] = $scrape['seeders'];
    $item['scrape_leechers'] = $scrape['leechers'];
    $item['scrape_completed'] = $scrape['completed'];
    $item['scraped_at'] = $scrape['scraped_at'];
}

// Files (capped)
$filesLimit = 5000;
$fs = $db->prepare("SELECT path, size FROM whitelist_files WHERE whitelist_id = ? ORDER BY id LIMIT ?");
$fs->bindValue(1, $id, PDO::PARAM_INT);
$fs->bindValue(2, $filesLimit + 1, PDO::PARAM_INT);
$fs->execute();
$files = [];
foreach ($fs->fetchAll() as $f) {
    $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
}
$filesTruncated = count($files) > $filesLimit;
if ($filesTruncated) $files = array_slice($files, 0, $filesLimit);

// Ban reason (if any)
$bs = $db->prepare("SELECT info_hash, reason, source, source_id, created_at FROM banned_hashes WHERE info_hash = ? LIMIT 1");
$bs->execute([$row['info_hash']]);
$bannedReason = $bs->fetch() ?: null;
if ($bannedReason) $bannedReason['source_id'] = $bannedReason['source_id'] !== null ? (int)$bannedReason['source_id'] : null;

// API client (if the row came through the S2S API)
$apiClient = null;
if (!empty($row['api_client_id'])) {
    $cs = $db->prepare("SELECT id, label FROM api_clients WHERE id = ? LIMIT 1");
    $cs->execute([(int)$row['api_client_id']]);
    $c = $cs->fetch();
    if ($c) $apiClient = ['id' => (int)$c['id'], 'label' => (string)$c['label']];
}

jsonResponse([
    'item' => $item,
    'magnet' => buildMagnet($row['info_hash'], $row['name'], $cfg),
    'files' => $files,
    'files_truncated' => $filesTruncated,
    'scrape' => $scrape,
    'banned_reason' => $bannedReason,
    'api_client' => $apiClient,
]);
