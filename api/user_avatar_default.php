<?php
/**
 * GET user_avatar_default&l=<A-Z or 0-9>&c=<0-11> — the generated picture: a letter on a colour.
 *
 * No identifier of anybody in the address. The letter is the first letter or digit of a name and the
 * colour a hash of it (userAvatarLetter(), userAvatarColourIndex()), so a person keeps theirs, and 36
 * letters × 12 colours is the whole space — a browser that has seen a few pages has most of it
 * cached for a year. Only the canonical form answers (one upper-case character, a bare integer), so
 * there is exactly one address per picture and nothing to vary a cache by.
 *
 * An SVG with a <rect> and a <text> in it and nothing else, served with nosniff and a policy that
 * forbids everything, like every other image stream on the site.
 */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$l = (string)($_GET['l'] ?? '');
$c = (string)($_GET['c'] ?? '');
if (!preg_match('/^[A-Z0-9]$/', $l) || !preg_match('/^(?:[0-9]|1[01])$/', $c)) jsonResponse(['error' => 'not_found'], 404);

$etag = '"av1-' . $l . '-' . $c . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header_remove('Content-Security-Policy');
header_remove('Content-Security-Policy-Report-Only');
header("Content-Security-Policy: default-src 'none'");
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
$svg = userAvatarDefaultSvg($l, (int)$c);
header('Content-Type: image/svg+xml');
header('Content-Length: ' . strlen($svg));
header('Content-Disposition: inline; filename="avatar-' . $l . '.svg"');
echo $svg;
exit;
