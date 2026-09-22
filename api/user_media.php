<?php
/**
 * GET user_media&h=<first 16 of a sha1>&s=<size> — one stored picture, cover or thumb (1.63.0).
 *
 * CONTENT-ADDRESSED. The address is part of a hash and a size and nothing else: no account id, no
 * file name (the research's 6b.3 — the extension put the uploader's own file name into every public
 * URL). A new picture, a new framing or a new cover is a new hash and therefore a new address, so
 * this one never changes and may be cached for a year, `immutable`.
 *
 *   s = 64 | 128 | 256   a picture's square: the largest one at or below what was asked, because a
 *                        small source may never have had the larger sizes (never upscaled)
 *   s = 0                a cover — or, to its OWNER and to a panel session only, the uncropped source
 *                        a picture is cut from: it may show exactly what somebody cropped away
 *   s = 1000             a cover's thumb (the cover itself when it was already that small)
 *
 * "Not found" is also the answer for "not yours" and for "switched off", so the stream never tells
 * anybody that something exists. The headers are the emote stream's (api/shout_emote.php): the type
 * THIS site encoded, nosniff, and a policy of its own that forbids everything — nothing here is a
 * document, so `default-src 'none'` without even the inline style an SVG emote needs.
 */
// Who is asking, read BEFORE the session is let go of: the source is its owner's alone. Everything
// after this is a read, and an image request must not hold this browser's session lock while it
// streams — a profile with twenty avatars on it is twenty of these at once.
$viewer = usersEnabled($cfg) ? currentUser($db) : null;
$panel = function_exists('isLoggedIn') && isLoggedIn();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$row = userMediaFind($db, $cfg, strtolower((string)($_GET['h'] ?? '')), (int)($_GET['s'] ?? 0),
                     $viewer ? (int)$viewer['id'] : null, $panel);
if ($row === null) jsonResponse(['error' => 'not_found'], 404);

$private = $row['kind'] === 'avatar_src';
$etag = '"' . $row['sha1'] . '-' . $row['kind'] . '-' . (int)($row['size'] ?? 0) . '"';
header('ETag: ' . $etag);
// The source is private and not kept: a shared computer's cache is not where somebody's uncropped
// original should outlive their session. Everything else is public and never changes.
header('Cache-Control: ' . ($private ? 'private, no-store' : 'public, max-age=31536000, immutable'));
header('X-Content-Type-Options: nosniff');
header_remove('Content-Security-Policy');
header_remove('Content-Security-Policy-Report-Only');
header("Content-Security-Policy: default-src 'none'");
if (!$private && trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
$name = match ($row['kind']) {
    'avatar'      => 'avatar-' . (int)$row['size'],
    'avatar_src'  => 'avatar-source',
    'cover_thumb' => 'cover-thumb',
    default       => 'cover',
};
header('Content-Type: image/webp');
header('Content-Length: ' . strlen((string)$row['data']));
header('Content-Disposition: inline; filename="' . $name . '.webp"');
header('Accept-Ranges: none');
echo $row['data'];
exit;
