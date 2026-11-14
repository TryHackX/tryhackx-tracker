<?php
requirePost();

// One-off at mode switch: every hash in the legacy blacklist file becomes a banned hash (source=import).
$r = whitelistImportBlacklist($db, $cfg);

jsonResponse([
    'success' => $r['error'] === null,
    'imported' => (int)$r['imported'],
    'skipped' => (int)$r['skipped'],
    'invalid' => (int)$r['invalid'],
    'error' => $r['error'],
]);
