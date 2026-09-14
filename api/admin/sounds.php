<?php
/**
 * GET/POST admin/sounds — the owner's sound uploads (Settings → Sounds).
 *
 * GET   the uploads with their sizes and lengths, the shipped files, and the limits.
 * POST  {"op": "upload", "name": …, "data": <base64>}  |  {"op": "delete", "id": N}
 *
 * Not in the permission map, so this is owner-only, like the rest of Settings. The upload travels as
 * base64 inside JSON rather than as a multipart form — the same road every other panel call takes,
 * with the same CSRF header — and lands in includes/sounds.php, which decides from the BYTES whether
 * it is a sound at all (MP3 frames, an Ogg page, a RIFF/WAVE header), how long it is, and refuses
 * anything else. The name is display text and is cleaned, bounded and stored as text.
 */
require_once __DIR__ . '/../../includes/sounds.php';

$base = getBaseUrl();
$listing = function () use ($db, $base): array {
    $rows = [];
    foreach (soundCustomList($db) as $r) {
        $rows[] = ['id' => (int)$r['id'], 'sid' => 'c:' . (int)$r['id'], 'name' => (string)$r['name'], 'mime' => (string)$r['mime'],
                   'bytes' => (int)$r['bytes'], 'ms' => $r['duration_ms'] === null ? null : (int)$r['duration_ms'],
                   'url' => soundCustomUrl($r, $base), 'created_at' => (string)$r['created_at']];
    }
    return $rows;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success'   => true,
        'sounds'    => $listing(),
        'builtins'  => soundLibraryForClient(soundBuiltins($base)),
        'max_bytes' => SOUNDS_MAX_BYTES,
        'max_count' => SOUNDS_MAX_CUSTOM,
        'max_ms'    => SOUNDS_MAX_MS,
    ]);
}

// Refused before the body is read at all: 512 KB of audio is at most ~700 KB of base64 plus the
// JSON around it, and reading a body many times that size only to say no is work nobody asked for.
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > SOUNDS_MAX_BYTES * 2) jsonResponse(['error' => __('api.sounds.too_large')], 413);
$input = readJsonBody();
$op = (string)($input['op'] ?? '');

if ($op === 'upload') {
    $b64 = (string)($input['data'] ?? '');
    // A data: URL prefix is tolerated — FileReader.readAsDataURL produces one — but only the payload counts.
    if (($comma = strpos($b64, ',')) !== false && str_starts_with($b64, 'data:')) $b64 = substr($b64, $comma + 1);
    // 4/3 of the cap, plus padding: anything longer cannot decode to an acceptable size, so it is
    // refused before base64_decode is asked to allocate for it.
    if (strlen($b64) > (int)(SOUNDS_MAX_BYTES * 4 / 3) + 16) jsonResponse(['error' => __('api.sounds.too_large')], 400);
    $bytes = base64_decode($b64, true);
    if ($bytes === false) jsonResponse(['error' => __('api.sounds.not_audio')], 400);
    $r = soundStore($db, (string)($input['name'] ?? ''), $bytes);
    if (!$r['ok']) jsonResponse(['error' => __($r['error'])], 400);
    jsonResponse(['success' => true, 'message' => __('api.sounds.uploaded'), 'sounds' => $listing(),
                  'added' => ['id' => $r['row']['id'], 'sid' => 'c:' . $r['row']['id'], 'name' => $r['row']['name']]]);
}

if ($op === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if (!soundDelete($db, $id)) jsonResponse(['error' => __('api.sounds.unknown')], 404);
    jsonResponse(['success' => true, 'message' => __('api.sounds.deleted'), 'sounds' => $listing()]);
}

jsonResponse(['error' => __('api.sounds.unknown_op')], 400);
