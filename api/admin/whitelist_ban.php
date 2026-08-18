<?php
requirePost();

$input = readJsonBody();
$ids = $input['ids'] ?? [];
$hashesIn = $input['hashes'] ?? [];
if (!is_array($ids)) $ids = [$ids];
if (!is_array($hashesIn)) $hashesIn = [$hashesIn];
$reason = trim((string)($input['reason'] ?? ''));

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
$hashes = [];
foreach ($hashesIn as $h) {
    $h = strtolower(trim((string)$h));
    if (isValidInfoHash($h)) $hashes[$h] = true;
}

if (count($ids) > 500 || count($hashes) > 500) {
    jsonResponse(['error' => 'Too many items (max 500)'], 400);
}

// Resolve ids → hashes
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT info_hash FROM whitelist WHERE id IN ($ph)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) $hashes[strtolower($h)] = true;
}
$hashes = array_keys($hashes);

if (!$hashes) {
    jsonResponse(['error' => 'No valid hashes to ban'], 400);
}
if (count($hashes) > 500) {
    jsonResponse(['error' => 'Too many items (max 500)'], 400);
}

$r = whitelistBan($db, $cfg, $hashes, ['source' => 'admin', 'reason' => $reason !== '' ? $reason : 'Banned from admin panel']);

jsonResponse(['success' => true, 'banned' => $r['banned'], 'affected' => $r['affected']]);
