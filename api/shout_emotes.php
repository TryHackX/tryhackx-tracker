<?php
/**
 * GET shout_emotes — what the picker offers: every enabled emote and sticker.
 *
 * Signed in and holding `shout.view`, because this is the vocabulary of a room a guest does not
 * read. The answer is small and completely public within that room — {id, code, name, url, sticker,
 * w, h} — so it is cacheable by the browser for a minute and by nothing else.
 *
 * The limits travel with it so the upload form in the page can say "64 KB, 128 px, 20 each" without
 * a second request, and `may_upload` is the one thing here that depends on WHO is asking.
 *
 * A read path: the session lock goes before the first query, like shout_list's.
 *
 * ── by context (1.70.0) ─────────────────────────────────────────────────────────────────────────
 * `for` = message | description | bio | list: the picker of that editor (assets/js/emoji-picker.js),
 * gated by the permission that writes that text (emojiPickerGate(), includes/emoji.php) and by
 * `emotes_everywhere` (emotesEverywhere(), includes/shout.php) — not by the room's view. It offers what
 * that context DRAWS (emotePickerRows()): the approved, switched-on emotes, and the stickers only where
 * they are drawn as stickers — none in a profile's description. {success, emotes, stickers}; no upload
 * limits, which belong to the room's page. Absent, the room's answer, exactly as before.
 */
$for = emojiPickerFor($_GET['for'] ?? null);
if ($for === null) jsonResponse(['error' => 'bad_for'], 400);
if ($for !== 'shout') {
    if (($gate = emojiPickerGate($db, $cfg, $for)) !== null) jsonResponse(['error' => $gate['error']], $gate['status']);
    if (!emotesEverywhere($cfg)) jsonResponse(['error' => 'disabled'], 403);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Cache-Control: private, no-store');
    jsonResponse([
        'success'  => true,
        'emotes'   => emotePickerRows($db, $cfg, $for, getBaseUrl()),
        'stickers' => emoteStickerMode($for, $cfg) !== 'emote',
    ]);
}

if (!shoutEmotesEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
if (!shoutMayView($db, $cfg)) {
    jsonResponse(['error' => $me ? 'no_permission' : 'login_required'], $me ? 403 : 401);
}
$mayUpload = $me && userCan($db, $cfg, 'shout.upload_emote');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
// Not cached, even for a minute. The script asks for this ONCE per page already, and a minute of
// staleness is exactly long enough for somebody who has just uploaded an emote to be told, by their
// own browser, that it is not there. The pictures themselves are the cached part (a month, keyed by
// sha1); this is the index, and it is four lines of JSON.
header('Cache-Control: private, no-store');

$base = getBaseUrl();
$rows = [];
foreach (shoutEmotes($db, $cfg) as $e) $rows[] = shoutEmoteForClient($e, $base);

jsonResponse([
    'success'    => true,
    'emotes'     => $rows,
    'stickers'   => shoutStickersEnabled($cfg),
    'may_upload' => (bool)$mayUpload,
    'mine'       => $me ? shoutEmoteCountFor($db, (int)$me['id']) : 0,
    'max_kb'     => shoutEmoteMaxKb($cfg),
    'max_px'     => shoutEmoteMaxPx($cfg),
    'per_user'   => shoutEmotePerUser($cfg),
]);
