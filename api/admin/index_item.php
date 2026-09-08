<?php
// Details for one index row (details modal), by info_hash.
$hash = strtolower(trim((string)($_GET['hash'] ?? '')));
if (!isValidInfoHash($hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

$stmt = $db->prepare("SELECT * FROM index_hashes WHERE info_hash = ?");
$stmt->execute([$hash]);
$row = $stmt->fetch();
if (!$row) jsonResponse(['error' => __('api.common.not_found')], 404);

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

// files_all=1 lifts the cap: the operator pressed "load all" on a page that is theirs to wait on —
// or the panel is in 'all' mode, in which case the modal's FIRST request carries it and there is no
// second one. Both numbers are settings since 1.38.0; the ceiling still has to be a number, because
// this is one fetchAll followed by one json_encode in php-fpm on the same box as MariaDB.
$filesAll   = (($_GET['files_all'] ?? '') === '1');
$filesMax   = indexFilesAdminMax($cfg);
$filesLimit = $filesAll ? $filesMax : indexFilesAdminBatch($cfg);
$fs = $db->prepare("SELECT path, size FROM index_files WHERE info_hash = ? ORDER BY id LIMIT ?");
$fs->bindValue(1, $hash, PDO::PARAM_STR);
$fs->bindValue(2, $filesLimit + 1, PDO::PARAM_INT);
$fs->execute();
$files = [];
foreach ($fs->fetchAll() as $f) $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
// Two separate facts, because they need two separate answers. `files_truncated` still means only
// "more rows are in the table" and is what puts the Load-all button on the modal; `files_short`
// means the stored list ended below the row's own files_count, which no amount of loading fixes.
// Folding the second into the first would have shown a button that returns the same 5 000 rows.
$sf = indexFilesShortfall($item['files_count'] ?? null, count($files), $filesLimit);
$filesTruncated = $sf['truncated'];
if ($filesTruncated) $files = array_slice($files, 0, $filesLimit);
// A third state, and the one that makes the Load-all button honest: rows are waiting but this reply
// already stands on the panel's total, so no request exists that would bring them. Offering the
// button here would repeat exactly the list already on screen — the dead button 1.38.0 avoided once
// already, when a short list was nearly folded into `files_truncated`. Capped and truncated are
// therefore never both true, here for the same reason as in api/index_files.php.
$filesCapped = indexFilesCapped($filesTruncated, 0, count($files), $filesMax);
if ($filesCapped) $filesTruncated = false;

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
    'files_capped' => $filesCapped,
    'files_short' => $sf['short'],
    'scrape' => $scrape,
    'whitelisted' => $isWhitelisted,
    'banned' => $isBanned,
    // Shown to an administrator whatever its review state: a moderator cannot judge text
    // they are not allowed to see.
    'content' => richtextContentFor($db, $cfg, $hash, true),
]);
