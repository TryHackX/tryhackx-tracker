<?php
/**
 * GET sound&id=N — the bytes of one uploaded sound, as the audio file it was sniffed to be.
 *
 * Public, like the files under assets/sounds/ are: a sound is not a secret, and the account page,
 * the Settings page (a panel session, not an account) and every page's script all need it. Strong
 * caching — the URL carries the first eight characters of the sha1 (includes/sounds.php,
 * soundCustomUrl), so a replaced sound is a new URL and the old one may be kept for a month.
 *
 * The session is released before the read; the Content-Type is the one the sniff decided at upload,
 * never anything the uploader declared, and X-Content-Type-Options says so to the browser.
 */
require_once __DIR__ . '/../includes/sounds.php';

// Off in Settings means off: the tab, the script and this stream alike.
if (!soundsEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$id = (int)($_GET['id'] ?? 0);
$row = soundCustomGet($db, $id);
if ($row === null) jsonResponse(['error' => 'not_found'], 404);

$etag = '"' . $row['sha1'] . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
$ext = ['audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav'][$row['mime']] ?? 'bin';
header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . strlen((string)$row['data']));
header('Content-Disposition: inline; filename="sound-' . (int)$row['id'] . '.' . $ext . '"');
header('Accept-Ranges: none');
echo $row['data'];
exit;
