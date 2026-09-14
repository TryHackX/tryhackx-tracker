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
 */
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
