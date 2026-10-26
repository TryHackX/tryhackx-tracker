<?php
$viewer = usersEnabled($cfg) ? currentUser($db) : null;
$moderator = (function_exists('panelCan') && panelCan($db, $cfg, 'panel.whitelist.content'))
          || ($viewer !== null && userCan($db, $cfg, 'content.delete_any'));
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$img = imagesServe($db, (int)($_GET['id'] ?? 0), (int)($_GET['w'] ?? 0), (int)($_GET['h'] ?? 0),
                   strtolower((string)($_GET['ext'] ?? '')), isset($_GET['full']), $viewer ? (int)$viewer['id'] : null, $moderator);
if ($img === null) jsonResponse(['error' => 'not_found'], 404);

header('ETag: ' . $img['etag']);
header('Cache-Control: ' . ($img['public'] ? 'public, max-age=31536000, immutable' : 'private, no-store'));
header('X-Content-Type-Options: nosniff');
header_remove('Content-Security-Policy');
header_remove('Content-Security-Policy-Report-Only');
header("Content-Security-Policy: default-src 'none'");
if ($img['public'] && trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $img['etag']) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $img['mime']);
header('Content-Length: ' . strlen($img['data']));
header('Content-Disposition: inline; filename="' . $img['name'] . '"');
header('Accept-Ranges: none');
echo $img['data'];
exit;
