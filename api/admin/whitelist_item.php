<?php
// Details for one whitelist row (details modal).
//
// Addressed by `id` from the table, or by `hash` from a link: a shared address names the torrent,
// not the row number it happens to have in this installation's table. Both find the same row and
// answer the same way; `id` wins when somebody sends both.
$id = (int)($_GET['id'] ?? 0);
$hash = strtolower(trim((string)($_GET['hash'] ?? '')));
if ($id < 1 && !preg_match('/^[0-9a-f]{40}$/', $hash)) {
    jsonResponse(['error' => __('api.common.invalid_id')], 400);
}

if ($id >= 1) {
    $stmt = $db->prepare("SELECT * FROM whitelist WHERE id = ?");
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare("SELECT * FROM whitelist WHERE info_hash = ? LIMIT 1");
    $stmt->execute([$hash]);
}
$row = $stmt->fetch();
if (!$row) {
    jsonResponse(['error' => __('api.common.not_found')], 404);
}

// Live scrape (cached in the row for WL_SCRAPE_TTL seconds); never let a tracker hiccup break the modal
$scrape = null;
try {
    $scrape = scrapeOpenTracker($db, $cfg, $row, false);
} catch (\Throwable $e) {
    $scrape = null;
}

// What the CATALOGUE knows about the same hash. The whitelist row says when we were told about the
// torrent; index_hashes says when the tracker last actually saw it and how big the swarm ever got —
// and those were visible in the Index panel and the public Info panel but not here, which is most of
// what "the whitelist details have fewer details" meant. Missing is normal: a registered hash that
// has never been announced has no index row.
$idx = null;
try {
    $st = $db->prepare("SELECT first_seen, last_seen, seen_count, last_seeders, last_leechers,
                               last_completed, peak_seeders, meta_status AS idx_meta_status,
                               total_size AS idx_total_size, files_count AS idx_files_count, promoted_at
                          FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([(string)$row['info_hash']]);
    $idx = $st->fetch() ?: null;
} catch (\Throwable $e) { $idx = null; }

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
// files_all=1 lifts the cap: the operator pressed "load all" on a page that is theirs to wait on —
// or the panel is in 'all' mode and the modal's first request already carries it. Both numbers are
// settings since 1.38.0, clamped on read; the substitution is the same as api/admin/index_item.php.
$filesAll   = (($_GET['files_all'] ?? '') === '1');
$filesMax   = indexFilesAdminMax($cfg);
$filesLimit = $filesAll ? $filesMax : indexFilesAdminBatch($cfg);
$fs = $db->prepare("SELECT path, size FROM whitelist_files WHERE whitelist_id = ? ORDER BY id LIMIT ?");
// The id of the row that was FOUND, never the one that was asked for. On the hash path $id is the
// 0 that (int)($_GET['id'] ?? 0) produced — that branch is entered precisely when $id < 1 — so
// binding it here meant `whitelist_id = 0`, which matches nothing: every hash-addressed modal
// showed an empty list and then said "Single-file torrent or no file list stored" under a stat
// strip reading "27 files". Everything else in this file already reads $row.
$fs->bindValue(1, (int)$row['id'], PDO::PARAM_INT);
$fs->bindValue(2, $filesLimit + 1, PDO::PARAM_INT);
$fs->execute();
$files = [];
foreach ($fs->fetchAll() as $f) {
    $files[] = ['path' => (string)$f['path'], 'size' => (int)$f['size']];
}
// Two separate facts, as in api/admin/index_item.php: `files_truncated` means more rows are in the
// table (that is the Load-all button), `files_short` means the stored list ended below the row's
// own files_count because the worker never wrote the rest.
$sf = indexFilesShortfall($item['files_count'] ?? null, count($files), $filesLimit);
$filesTruncated = $sf['truncated'];
if ($filesTruncated) $files = array_slice($files, 0, $filesLimit);
// And a third, as in api/admin/index_item.php: rows are waiting but the panel's own total ends the
// list here, so there is no request left to make and the Load-all button must not be offered.
$filesCapped = indexFilesCapped($filesTruncated, 0, count($files), $filesMax);
if ($filesCapped) $filesTruncated = false;

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
    'files_capped' => $filesCapped,
    'files_short' => $sf['short'],
    'scrape' => $scrape,
    'banned_reason' => $bannedReason,
    'api_client' => $apiClient,
    'index' => $idx,
    // Shown to an administrator whatever its review state: a moderator cannot judge text
    // they are not allowed to see.
    'content' => richtextContentFor($db, $cfg, $row['info_hash'], true),
]);
