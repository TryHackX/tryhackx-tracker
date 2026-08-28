<?php
// Details for one index row (details modal), by info_hash.
$hash = strtolower(trim((string)($_GET['hash'] ?? '')));
if (!isValidInfoHash($hash)) jsonResponse(['error' => 'Invalid hash'], 400);

$stmt = $db->prepare("SELECT * FROM index_hashes WHERE info_hash = ?");
$stmt->execute([$hash]);
$row = $stmt->fetch();
if (!$row) jsonResponse(['error' => 'Not found'], 404);

$item = $row;
unset($item['meta_claim']);
foreach (['seen_count', 'last_seeders', 'last_leechers', 'last_completed', 'peak_seeders', 'total_size', 'files_count', 'piece_length', 'scrape_seeders', 'scrape_leechers', 'scrape_completed', 'meta_priority'] as $k) {
    if (array_key_exists($k, $item)) $item[$k] = $item[$k] !== null ? (int)$item[$k] : null;
}
$item['protected'] = ($item['protected_until'] !== null && strtotime((string)$item['protected_until']) >= time());
$item['promoted'] = $item['promoted_at'] !== null;

// live scrape (best effort), then reflect into the item
$scrape = null;
try { $scrape = indexScrapeOne($db, $cfg, $hash); } catch (\Throwable $e) { $scrape = null; }
if ($scrape) {
    $item['scrape_seeders'] = $scrape['seeders'];
    $item['scrape_leechers'] = $scrape['leechers'];
    $item['scrape_completed'] = $scrape['completed'];
    $item['scraped_at'] = $scrape['scraped_at'];
}

$filesLimit = 5000;
$fs = $db->prepare("SELECT path, size FROM index_files WHERE info_hash = ? ORDER BY id LIMIT ?");
$fs->bindValue(1, $hash, PDO::PARAM_STR);
$fs->bindValue(2, $filesLimit + 1, PDO::PARAM_INT);
$fs->execute();
$files = [];
foreach ($fs->fetchAll() as $f) $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
$filesTruncated = count($files) > $filesLimit;
if ($filesTruncated) $files = array_slice($files, 0, $filesLimit);

// already whitelisted / banned?
$wl = $db->prepare("SELECT id FROM whitelist WHERE info_hash = ? LIMIT 1");
$wl->execute([$hash]);
$isWhitelisted = (bool)$wl->fetchColumn();
$isBanned = isHashBanned($db, $hash);

jsonResponse([
    'item' => $item,
    'magnet' => buildMagnet($hash, $row['name'], $cfg),
    'files' => $files,
    'files_truncated' => $filesTruncated,
    'scrape' => $scrape,
    'whitelisted' => $isWhitelisted,
    'banned' => $isBanned,
    // Shown to an administrator whatever its review state: a moderator cannot judge text
    // they are not allowed to see.
    'content' => richtextContentFor($db, $cfg, $hash, true),
]);
