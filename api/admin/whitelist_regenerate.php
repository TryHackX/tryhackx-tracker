<?php
requirePost();

$input = readJsonBody();
$wantReload = !empty($input['reload']) && $input['reload'] !== '0' && $input['reload'] !== 'false';

$r = whitelistRegenerate($db, $cfg);

$reload = null;
if ($r['ok'] && $wantReload) {
    whitelistMarkDirty(true);
    $reload = whitelistMaybeReload($cfg, true);
    if ($reload === null) {
        $reload = ['attempted' => false, 'ok' => false, 'output' => 'Reload not attempted: service name not configured or systemctl not available.'];
    }
}

// 200 always — the card renders success=false inline (busy / empty / permissions)
jsonResponse([
    'success' => (bool)$r['ok'],
    'count' => (int)$r['count'],
    'bytes' => (int)$r['bytes'],
    'ms' => (int)$r['ms'],
    'error' => $r['error'],
    'busy' => (bool)($r['busy'] ?? false),
    'errors' => $r['errors'] ?? [],
    'suggestions' => $r['suggestions'] ?? [],
    'reload' => $reload,
]);
