<?php
/**
 * GET sounds — the library a signed-in reader may choose from, their own preferences, and the site
 * defaults the owner picked. Drawn by the account page's Sounds tab (assets/js/sounds.js).
 *
 * 401 for a visitor, 403 when the feature is off or the reader's groups do not carry sounds.use.
 */
require_once __DIR__ . '/../includes/sounds.php';

if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);
if (!soundsEnabled($cfg) || !userCan($db, $cfg, 'sounds.use')) jsonResponse(['error' => 'sounds_off'], 403);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$lib = soundLibrary($db, getBaseUrl());
jsonResponse([
    'success'  => true,
    'library'  => soundLibraryForClient($lib),
    'prefs'    => soundPrefsFor($db, $u, $lib),
    'defaults' => soundSiteDefaults($cfg, $lib),
    'kinds'    => soundEventKinds(),
    'pre_max'  => SOUNDS_PREROLL_MAX_MS,
]);
