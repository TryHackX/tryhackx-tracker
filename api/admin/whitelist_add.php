<?php
requirePost();

$input = readJsonBody();
$text = (string)($input['input'] ?? '');
$name = isset($input['name']) ? cleanTorrentName((string)$input['name']) : null;

if (trim($text) === '') {
    jsonResponse(['error' => 'No input provided'], 400);
}

$parsed = parseHashInput($text, 500);
$items = $parsed['items'];

$hasValid = false;
foreach ($items as &$it) {
    if ($it['hash'] !== null) {
        $hasValid = true;
        // Optional display name applies to items that did not carry their own dn=
        if ($name !== null && ($it['name'] === null || $it['name'] === '')) $it['name'] = $name;
    }
}
unset($it);

if (!$hasValid) {
    $results = [];
    foreach ($items as $i => $it) {
        $results[] = ['index' => $i, 'input' => $it['input'], 'hash' => null, 'status' => 'invalid', 'error' => $it['error'] ?? 'invalid'];
    }
    jsonResponse(['error' => 'No valid magnet links or info hashes found', 'results' => $results, 'too_many' => $parsed['too_many']], 400);
}

$r = whitelistAddHashes($db, $cfg, $items, ['source' => 'admin', 'ip' => getClientIp($cfg), 'auto_meta' => true]);

jsonResponse([
    'success' => true,
    'results' => $r['results'],
    'summary' => $r['summary'],
    'file' => $r['file'],
    'reload' => $r['reload'],
    'active_in_seconds' => $r['active_in_seconds'],
    'too_many' => $parsed['too_many'],
]);
