<?php
requirePost();

$input = readJsonBody();
$hash = strtolower(trim((string)($input['hash'] ?? '')));

if (!isValidInfoHash($hash)) {
    jsonResponse(['error' => 'Invalid info hash'], 400);
}

whitelistUnban($db, $cfg, $hash);

jsonResponse(['success' => true]);
