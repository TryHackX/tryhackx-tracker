<?php
/**
 * GET shout_emote&id=N — the bytes of one emote or sticker, as the image it was sniffed to be.
 *
 * Public, like api/sound.php and for the same reason: a picture in a room is not a secret, it is
 * referenced from every rendered line, and a page the reader may not see does not link to it in the
 * first place. The URL carries the first eight characters of the sha1 (shoutEmoteUrl), so a
 * replaced emote is a new address and this one may be cached for a month.
 *
 * ── the SVG, which is the whole reason this file has headers of its own ────────────────────────
 *
 * An SVG is a document, not a picture: it can carry a script, an event handler and references to
 * other origins, and served from this origin it would run with this origin's rights. Three walls,
 * any one of which is meant to be enough:
 *
 *   1. includes/shout.php refused it at upload (shoutEmoteSvgIssue) — nothing with a script, a
 *      handler, javascript:, data:text/html, foreignObject or an href that leaves the document is
 *      in the table at all.
 *   2. The Content-Type is the one THIS CODE sniffed, never one an uploader declared, and
 *      X-Content-Type-Options says the browser may not go looking for a better idea.
 *   3. `default-src 'none'` on this response: no script, no fetch, no frame, nothing to load —
 *      whatever the first two missed has nowhere to go. `style-src 'unsafe-inline'` is the one
 *      exception, because a hand-written SVG legitimately carries a <style> block.
 *
 * A row that is switched OFF still streams: the manager in Settings previews what it is about to
 * switch back on, and hiding the bytes would only mean an empty box there. What "disabled" means is
 * that shoutRenderEmotes() no longer writes the tag — which is where it matters.
 */
require_once __DIR__ . '/../includes/shout.php';

// NOT gated on the feature switch: a row that exists still streams (the docblock above says why —
// the manager previews what it is about to switch on, and shoutRenderEmotes() is where "off" bites).
// A picture is not a secret, the same reading api/sound.php takes.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$row = shoutEmoteGet($db, (int)($_GET['id'] ?? 0));
if ($row === null) jsonResponse(['error' => 'not_found'], 404);

$etag = '"' . $row['sha1'] . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
// header() replaces a header of the same name, so an enforcing site policy sent by
// sendSecurityHeaders() is swapped for this stricter one rather than added to. The REPORT-ONLY one
// is a different header name and would have survived, and so would an enforcing policy the web
// server itself added — a client handed two of them enforces the intersection, which is safe and
// untidy. Both are taken off first, so what leaves here is exactly one policy: this one.
header_remove('Content-Security-Policy');
header_remove('Content-Security-Policy-Report-Only');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . strlen((string)$row['data']));
header('Content-Disposition: inline; filename="emote-' . (int)$row['id'] . '.' . shoutEmoteExt((string)$row['mime']) . '"');
header('Accept-Ranges: none');
echo $row['data'];
exit;
