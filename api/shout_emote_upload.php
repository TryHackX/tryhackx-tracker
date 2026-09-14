<?php
/**
 * POST shout_emote_upload {csrf_token, code, name, data, sticker} — add a picture to the room.
 *
 * `data` is base64, or the whole `data:image/png;base64,…` URL FileReader hands over; the prefix is
 * tolerated and only the payload counts. Nothing about the file NAME or the declared type reaches
 * the database: includes/shout.php decides from the bytes what this is, whether an SVG is one this
 * site will serve at all, and how big it is on screen.
 *
 * The gates run in the order a person would ask the questions — is the request real, is there
 * somebody making it, does the room exist, do emotes exist, may THEY add one, are they going too
 * fast — and only then, what did they send. `shout.upload_emote` is a separate permission from
 * `shout.post` on purpose: adding to a shared vocabulary is not the same act as saying something.
 */
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!shoutEmotesEnabled($cfg)) jsonResponse(['error' => 'disabled', 'message' => __('api.shout.disabled')], 403);
if (!userCan($db, $cfg, 'shout.upload_emote')) {
    jsonResponse(['error' => 'no_permission', 'message' => __('api.emote.no_permission')], 403);
}
// An address ceiling on top of the per-account cap: the cap bounds how many pictures one member
// ends up owning, this bounds how hard a script may try.
if (!rateLimitAllow('emoteupload', ipBucket(getClientIp($cfg)), 30, 300)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 300], 429);
}

// Refused before the base64 is decoded: 4/3 of the cap plus padding is the longest string that
// could possibly decode to an acceptable size, so anything past it is answered without allocating.
$maxKb = shoutEmoteMaxKb($cfg);
$b64 = (string)($input['data'] ?? '');
if (($comma = strpos($b64, ',')) !== false && str_starts_with($b64, 'data:')) $b64 = substr($b64, $comma + 1);
if (strlen($b64) > (int)($maxKb * 1024 * 4 / 3) + 64) {
    jsonResponse(['error' => 'too_large', 'message' => __('api.emote.too_large', ['kb' => $maxKb])], 413);
}
$bytes = base64_decode($b64, true);
if ($bytes === false || $bytes === '') jsonResponse(['error' => 'not_image', 'message' => __('api.emote.not_image')], 400);

$r = shoutEmoteStore($db, $cfg, (string)($input['code'] ?? ''), (string)($input['name'] ?? ''), $bytes,
                     (int)$me['id'], !empty($input['sticker']));
if (empty($r['ok'])) {
    // The lang key carries the limit it was judged against, so the message names the same number
    // the form under it printed.
    $vars = ['kb' => $maxKb, 'px' => shoutEmoteMaxPx($cfg), 'n' => shoutEmotePerUser($cfg),
             'code' => (string)($r['detail'] ?? '')];
    jsonResponse(['error' => substr((string)$r['error'], strlen('api.emote.')), 'message' => __((string)$r['error'], $vars),
                  'detail' => (string)($r['detail'] ?? '')], (int)($r['status'] ?? 400));
}
// `pending` is the whole of what the approval gate looks like from out here: the row is stored and
// it is theirs, but nobody else sees it until somebody with `shout.moderate` says so. The page marks
// their own card as waiting rather than pretending the picture is live — being told "added" about a
// picture that does not work is how somebody uploads it a second time.
$pending = !empty($r['pending']);
jsonResponse(['success' => true, 'pending' => $pending,
              'message' => __($pending ? 'api.emote.waiting' : 'api.emote.added'),
              'emote' => shoutEmoteForClient($r['row'], getBaseUrl())]);
