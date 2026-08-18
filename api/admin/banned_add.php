<?php
// Ban hashes typed/pasted in the admin panel (magnets or 40-hex, one per line).
requirePost();

$input = readJsonBody();
$text = (string)($input['input'] ?? '');
$reason = trim((string)($input['reason'] ?? ''));
if (trim($text) === '') {
    jsonResponse(['error' => 'No input provided'], 400);
}
$parsed = parseHashInput($text, 500);
$hashes = [];
$invalid = 0;
foreach ($parsed['items'] as $it) {
    if (!empty($it['hash'])) $hashes[$it['hash']] = true; else $invalid++;
}
if (!$hashes) {
    jsonResponse(['error' => 'No valid magnet links or info hashes found', 'invalid' => $invalid], 400);
}
$r = whitelistBan($db, $cfg, array_keys($hashes), ['source' => 'admin', 'reason' => $reason !== '' ? $reason : 'Banned from admin panel']);
jsonResponse(['success' => true, 'banned' => $r['banned'], 'affected' => $r['affected'], 'invalid' => $invalid, 'too_many' => $parsed['too_many']]);
