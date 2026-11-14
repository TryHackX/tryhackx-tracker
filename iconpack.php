<?php
/**
 * An installed Font Awesome package's stylesheets and fonts (1.69.0):
 *
 *   iconpack.php?p=<package id>&v=<content hash>-<rev>&f=css/all.css
 *
 * Only what the package's manifest lists, only its css/ and webfonts/ (the metadata and the licence
 * stay on the server), with the exact type, nosniff, and a year's immutable caching — the URL carries
 * the package's own hash, so a different package is a different URL. The decision is
 * iconpackServeRequest() in includes/iconpack.php; this file only sends it.
 *
 * A root file rather than an api.php endpoint on purpose: that one starts a session, opens the
 * database and runs the janitors, and a page asks for a stylesheet and several fonts at once. This
 * reads one manifest. The package files themselves live in config/iconpacks/, which the web server
 * refuses to serve (config/.htaccess) — they reach a browser through here or not at all.
 */
require_once __DIR__ . '/includes/iconpack.php';

$r = iconpackServeRequest($_GET, $_SERVER);
http_response_code($r['status']);
foreach ($r['headers'] as $h) header($h);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $r['status'] === 304) exit;
if (isset($r['body'])) {
    echo $r['body'];
} elseif (isset($r['path'])) {
    readfile($r['path']);
}
