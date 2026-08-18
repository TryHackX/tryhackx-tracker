<?php
requirePost();

$input = readJsonBody();
$ids = $input['ids'] ?? [];
if (!is_array($ids)) $ids = [$ids];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

if (!$ids) {
    jsonResponse(['error' => 'No IDs provided'], 400);
}
if (count($ids) > 500) {
    jsonResponse(['error' => 'Too many IDs (max 500)'], 400);
}

$removed = whitelistRemove($db, $cfg, $ids);

jsonResponse(['success' => true, 'removed' => $removed]);
