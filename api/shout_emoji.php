<?php
/**
 * GET shout_emoji — Font Awesome's faces for the shoutbox picker (1.69.0, includes/emoji.php).
 *
 *   ?v=<fingerprint>&lang=<code>   →  {success, mode, style, known, styles{key: {label, classes, layers}},
 *                                       pages[{id, tab}], faces[{n, p, l, k, v[], e}]}
 *
 * Only the faces: the ordinary emoji are a static file per language (assets/emoji/), which the browser
 * caches on its own. This answer depends on the package in use, the styles it loads, the two settings
 * and the language — all of which are in the URL the page builds (emojiFaVersion() in `v`, the reader's
 * language in `lang`), so a match of `v` may be cached for a day and anything else is not cached at all.
 * `lang` only picks the words (a face's Polish label is this project's, its English one the package
 * index's); a code nobody installed means the page's own language.
 *
 * Who may ask: whoever may read the room, as for its emotes. A read path: the session lock goes first.
 */
if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
if (!shoutMayView($db, $cfg)) {
    jsonResponse(['error' => $me ? 'no_permission' : 'login_required'], $me ? 403 : 401);
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$lang = strtolower((string)($_GET['lang'] ?? ''));
if (!function_exists('langAvailable') || !isset(langAvailable()[$lang])) $lang = langCurrent();
$v = (string)($_GET['v'] ?? '');
header($v !== '' && hash_equals(emojiFaVersion($cfg), $v) ? 'Cache-Control: private, max-age=86400' : 'Cache-Control: private, no-store');
jsonResponse(['success' => true] + emojiFaClientData($cfg, $lang));
