<?php
/**
 * GET/POST admin/shout_emotes — the emote manager (Settings → Shoutbox).
 *
 * GET   every emote, enabled or not, with who uploaded it and the limits in force.
 * POST  {"op": "list"}
 *       {"op": "upload",  "code": …, "name": …, "data": <base64>, "sticker": bool}
 *       {"op": "enable"|"disable"|"delete", "id": N}
 *       {"op": "sticker", "id": N, "on": bool}
 *
 * Not in adminEndpointPermission(), so this is owner-only like the rest of Settings — a moderator
 * takes a picture down through the public endpoint (shout_emote_delete, `shout.moderate`), which is
 * the authority they have; deciding what the site OFFERS is not.
 *
 * Uploads travel as base64 inside JSON, the same road every other panel call takes and with the
 * same CSRF header, and land in includes/shout.php: it decides from the bytes whether this is a
 * picture at all, refuses an SVG carrying anything executable, measures it and caps it. The
 * uploader is recorded as NULL — the site put it there, and the per-person cap is about members
 * filling a shared room, which the owner adding one from Settings is not.
 */
require_once __DIR__ . '/../../includes/shout.php';

$base = getBaseUrl();
$listing = function () use ($db, $cfg, $base): array {
    $rows = [];
    // Everything, enabled or not — the manager has to list what it may switch back on.
    foreach (shoutEmotes($db, $cfg, false) as $e) {
        $rows[] = [
            'id' => $e['id'], 'code' => $e['code'], 'name' => $e['name'], 'mime' => $e['mime'],
            'bytes' => $e['bytes'], 'w' => $e['width'], 'h' => $e['height'],
            'sticker' => $e['is_sticker'], 'enabled' => $e['enabled'],
            'url' => shoutEmoteUrl($e, $base), 'created_at' => $e['created_at'],
            'uploaded_by' => $e['uploaded_by'], 'uploader' => null,
        ];
    }
    // One query for the names rather than one per row; NULL stays NULL, which the page prints as
    // "the site" rather than as a missing account.
    $ids = array_values(array_unique(array_filter(array_column($rows, 'uploaded_by'), fn($v) => $v !== null)));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, username FROM users WHERE id IN ($in)");
        $st->execute($ids);
        $names = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $names[(int)$u['id']] = (string)$u['username'];
        foreach ($rows as $i => $r) {
            if ($r['uploaded_by'] !== null) $rows[$i]['uploader'] = $names[(int)$r['uploaded_by']] ?? null;
        }
    }
    return $rows;
};
$limits = fn(): array => ['max_kb' => shoutEmoteMaxKb($cfg), 'max_px' => shoutEmoteMaxPx($cfg),
                          'per_user' => shoutEmotePerUser($cfg), 'enabled' => shoutEmotesEnabled($cfg),
                          'stickers' => shoutStickersEnabled($cfg)];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => true, 'emotes' => $listing()] + $limits());
}

$maxKb = shoutEmoteMaxKb($cfg);
// Refused before the body is read at all: reading many megabytes only to say no is work nobody
// asked for.
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxKb * 1024 * 2 + 4096) {
    jsonResponse(['error' => __('api.emote.too_large', ['kb' => $maxKb])], 413);
}
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
$id = (int)($input['id'] ?? 0);

if ($op === '' || $op === 'list') {
    jsonResponse(['success' => true, 'emotes' => $listing()] + $limits());
}

if ($op === 'upload') {
    $b64 = (string)($input['data'] ?? '');
    if (($comma = strpos($b64, ',')) !== false && str_starts_with($b64, 'data:')) $b64 = substr($b64, $comma + 1);
    if (strlen($b64) > (int)($maxKb * 1024 * 4 / 3) + 64) {
        jsonResponse(['error' => __('api.emote.too_large', ['kb' => $maxKb])], 413);
    }
    $bytes = base64_decode($b64, true);
    if ($bytes === false || $bytes === '') jsonResponse(['error' => __('api.emote.not_image')], 400);
    // NULL uploader: the site's own, exempt from the per-person cap by construction.
    $r = shoutEmoteStore($db, $cfg, (string)($input['code'] ?? ''), (string)($input['name'] ?? ''), $bytes,
                         null, !empty($input['sticker']));
    if (empty($r['ok'])) {
        $vars = ['kb' => $maxKb, 'px' => shoutEmoteMaxPx($cfg), 'n' => shoutEmotePerUser($cfg),
                 'code' => (string)($r['detail'] ?? '')];
        jsonResponse(['error' => __((string)$r['error'], $vars), 'detail' => (string)($r['detail'] ?? '')],
                     (int)($r['status'] ?? 400));
    }
    auditLog($db, 'shout.emote_add', ['target_type' => 'shout', 'target_id' => (int)$r['row']['id'],
        'summary' => ':' . (string)$r['row']['code'] . ': (' . (string)$r['row']['mime'] . ', ' . (int)$r['row']['bytes'] . ' B)']);
    jsonResponse(['success' => true, 'message' => __('api.emote.added'), 'emotes' => $listing(),
                  'added' => ['id' => (int)$r['row']['id'], 'code' => (string)$r['row']['code']]] + $limits());
}

if ($op === 'enable' || $op === 'disable') {
    if (!shoutEmoteSetFlag($db, $id, 'enabled', $op === 'enable')) {
        // rowCount() is 0 when the row is already in that state as well as when it does not exist;
        // the listing below is what the page believes afterwards either way.
        if (shoutEmoteGet($db, $id) === null) jsonResponse(['error' => __('api.emote.unknown')], 404);
    }
    jsonResponse(['success' => true, 'message' => __('api.emote.saved'), 'emotes' => $listing()] + $limits());
}

if ($op === 'sticker') {
    if (!shoutEmoteSetFlag($db, $id, 'is_sticker', !empty($input['on'])) && shoutEmoteGet($db, $id) === null) {
        jsonResponse(['error' => __('api.emote.unknown')], 404);
    }
    jsonResponse(['success' => true, 'message' => __('api.emote.saved'), 'emotes' => $listing()] + $limits());
}

if ($op === 'delete') {
    $r = shoutEmoteDelete($db, $id, null);
    if (empty($r['ok'])) jsonResponse(['error' => __((string)$r['error'])], (int)($r['status'] ?? 400));
    auditLog($db, 'shout.emote_delete', ['target_type' => 'shout', 'target_id' => (int)$r['row']['id'],
        'summary' => ':' . (string)$r['row']['code'] . ':']);
    jsonResponse(['success' => true, 'message' => __('api.emote.deleted'), 'emotes' => $listing()] + $limits());
}

jsonResponse(['error' => __('api.emote.unknown_op')], 400);
