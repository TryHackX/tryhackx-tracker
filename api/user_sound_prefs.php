<?php
/**
 * POST user_sound_prefs — a reader's own sound preferences. Body: {csrf_token, prefs: {...}}.
 *
 * Whatever arrives is clamped and checked against the library before it is stored (includes/sounds.php,
 * soundPrefsValidate); the reply carries the set that was actually kept and the config the page's
 * script would play from now on (null when nothing would be played).
 */
require_once __DIR__ . '/../includes/sounds.php';

requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);
if (!soundsEnabled($cfg) || !userCan($db, $cfg, 'sounds.use')) jsonResponse(['error' => 'sounds_off'], 403);

$lib = soundLibrary($db, getBaseUrl());
$prefs = soundPrefsValidate($input['prefs'] ?? null, array_keys($lib));
soundPrefsSave($db, (int)$u['id'], $prefs);
$u['sound_prefs'] = json_encode($prefs);
jsonResponse([
    'success' => true,
    'prefs'   => $prefs,
    'client'  => soundClientConfig($db, $cfg, $u, getBaseUrl()),
]);
