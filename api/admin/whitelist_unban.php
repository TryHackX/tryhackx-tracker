<?php
requirePost();

$input = readJsonBody();
$hash = strtolower(trim((string)($input['hash'] ?? '')));

if (!isValidInfoHash($hash)) {
    jsonResponse(['error' => __('api.wl.invalid_info_hash')], 400);
}

whitelistUnban($db, $cfg, $hash);

jsonResponse(['success' => true]);
