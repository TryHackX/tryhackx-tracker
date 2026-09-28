<?php
/**
 * GET shout_emoji — Font Awesome for the shoutbox picker (1.69.0, includes/emoji.php).
 *
 *   ?v=<fingerprint>&lang=<code>   →  {success, mode, scope, style, classic, known, styles{key: {label, classes,
 *                                       layers}}, pages[{id, tab}], faces[{n, p, l, k, v[], e}],
 *                                       catalog?{v, n, cats[[id, label]]}}
 *   ?part=catalog&v=<version>      →  the package's catalogue of every icon (1.70.0, catalog.json:
 *                                       iconpackCatalogBuild() describes it), as stored
 *
 * Only Font Awesome: the ordinary emoji are a static file per language (assets/emoji/), which the browser
 * caches on its own. The first answer depends on the package in use, the styles it loads, the three
 * settings and the language — all of which are in the URL the page builds (emojiFaVersion() in `v`, the
 * reader's language in `lang`), so a match of `v` may be cached for a day and anything else is not
 * cached at all. `lang` only picks the words (a face's Polish label is this project's, its English one
 * the package index's; the categories' names are this project's in both); a code nobody installed means
 * the page's own language.
 *
 * The catalogue (1.70.0) is the same for every reader and every language: it depends on the package
 * alone, so its `v` is the package's hash (iconpackCatalogVersion()) and a match is kept by the browser
 * for a year. The picker asks for it only when its scope needs it — `all` once the picker has opened,
 * `search` once somebody searches — and never with the scope at `faces` or the faces off, when this
 * answers 404. Served as the file is, compressed when the browser takes gzip: about 133 KB for Pro
 * 7.3.1's 4,349 icons (468 KB as stored), 71 KB for 6.7.2's 3,814 (255 KB).
 *
 * Who may ask: whoever may read the room, as for its emotes. A read path: the session lock goes first.
 *
 * ── by context (1.70.0) ─────────────────────────────────────────────────────────────────────────
 * The picker is every editor's now (assets/js/emoji-picker.js), so `for` says whose it is: absent, the
 * room's — gated as above, exactly as before; `message`, `description`, `bio` or `list`, the permission
 * that writes THAT text (emojiPickerGate(), includes/emoji.php: pm.send, content.submit or
 * content.propose, profile.bio, lists.use), whether the room is on or not. The answer is the same in
 * every context — the faces, the catalogue — and so is its caching; only who may have it differs.
 * Anything else in `for` is refused (400), never read as some other context.
 */
$for = emojiPickerFor($_GET['for'] ?? null);
if ($for === null) jsonResponse(['error' => 'bad_for'], 400);
if ($for === 'shout') {
    if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
    $me = currentUser($db);
    if (!shoutMayView($db, $cfg)) {
        jsonResponse(['error' => $me ? 'no_permission' : 'login_required'], $me ? 403 : 401);
    }
} elseif (($gate = emojiPickerGate($db, $cfg, $for)) !== null) {
    jsonResponse(['error' => $gate['error']], $gate['status']);
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$v = (string)($_GET['v'] ?? '');
if ((string)($_GET['part'] ?? '') === 'catalog') {
    $ctx = emojiFaContext($cfg);
    if ($ctx['mode'] === 'off' || $ctx['scope'] === 'faces') jsonResponse(['error' => 'off'], 404);
    $id = (string)$ctx['id'];
    $body = iconpackCatalogSummary($id) !== null ? (string)@file_get_contents(iconpackCatalogPath($id)) : '';
    if ($body === '') {
        // A package installed by 1.69.0 has none yet: built now from its own metadata, and written for
        // every request after this one (iconpackCatalogOf()) — or, where the store cannot be written,
        // this request's copy.
        $c = iconpackCatalogOf($id);
        if ($c === null) jsonResponse(['error' => 'no_catalog'], 404);
        $body = (string)json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $want = iconpackCatalogVersion($ctx['setup']['pack']);
    while (ob_get_level()) ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header($v !== '' && hash_equals($want, $v) ? 'Cache-Control: private, max-age=31536000, immutable' : 'Cache-Control: private, no-store');
    if (!ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
    echo $body;
    exit;
}

$lang = strtolower((string)($_GET['lang'] ?? ''));
if (!function_exists('langAvailable') || !isset(langAvailable()[$lang])) $lang = langCurrent();
header($v !== '' && hash_equals(emojiFaVersion($cfg), $v) ? 'Cache-Control: private, max-age=86400' : 'Cache-Control: private, no-store');
jsonResponse(['success' => true] + emojiFaClientData($cfg, $lang));
