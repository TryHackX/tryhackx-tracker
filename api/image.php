<?php
$viewer = usersEnabled($cfg) ? currentUser($db) : null;
$moderator = function_exists('panelCan') && (panelCan($db, $cfg, 'panel.whitelist.content') || panelCan($db, $cfg, 'panel.images.view')
                                             || panelCan($db, $cfg, 'panel.whitelist.view') || panelCan($db, $cfg, 'panel.reports.images.view'));
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$img = imagesServe($db, (int)($_GET['id'] ?? 0), (int)($_GET['w'] ?? 0), (int)($_GET['h'] ?? 0),
                   strtolower((string)($_GET['ext'] ?? '')), isset($_GET['full']), $viewer ? (int)$viewer['id'] : null, $moderator);
if ($img === null) {
    $img = imagesServeRemoved($db, (int)($_GET['id'] ?? 0), (int)($_GET['w'] ?? 0), (int)($_GET['h'] ?? 0),
                              strtolower((string)($_GET['ext'] ?? '')), __('images.removed_placeholder'));
}
if ($img === null) jsonResponse(['error' => 'not_found'], 404);
imagesSend($img);
