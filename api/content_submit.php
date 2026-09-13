<?php
/**
 * POST content_submit — attach a description (and/or a source link) to a hash from the Info panel.
 *
 *   {"csrf_token":"…","hash":"<hex40 | base32 | magnet>","description":"…","description_format":"bbcode|markdown","source_url":"…"}
 *
 * The same door the whitelist form's optional fields go through (contentAttach in includes/content.php),
 * reachable in either tracker mode and for a torrent the tracker has only SEEN: the whitelist form
 * exists in whitelist mode and only registers, so until 1.53.0 nobody could describe a torrent in
 * blacklist mode at all. An empty record is filled (published or queued, per Settings); an occupied
 * one gets a proposal a moderator decides on. Gated by content.submit / content.propose, and rate
 * limited per address like every form a stranger can fill in.
 */
require_once __DIR__ . '/../includes/content.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'POST required'], 405);
$input = readJsonBody();
if (!contentEnabled($cfg)) jsonResponse(['error' => __('api.content.descriptions_disabled')], 404);
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!userCan($db, $cfg, 'content.submit') && !userCan($db, $cfg, 'content.propose')) {
    jsonResponse(['error' => __('api.content.access_required')], 403);
}
if (!rateLimitAllow('content', ipBucket(getClientIp($cfg)), 30, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}
$p = parseMagnetOrHash((string)($input['hash'] ?? ''));
if ($p['hash'] === null) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

$me = usersEnabled($cfg) ? currentUser($db) : null;
$r = contentAttach($db, $cfg, $p['hash'], [
    'description'        => (string)($input['description'] ?? ''),
    'description_format' => (string)($input['description_format'] ?? 'bbcode'),
    'source_url'         => (string)($input['source_url'] ?? ''),
], $me, getClientIp($cfg));
if (empty($r['ok'])) jsonResponse(['error' => (string)$r['error']], (int)($r['code'] ?? 400));

jsonResponse([
    'success'  => true,
    'saved'    => !empty($r['saved']),
    'pending'  => !empty($r['pending']),
    'proposed' => !empty($r['proposed']),
    'kind'     => $r['kind'],
    'message'  => !empty($r['proposed']) ? __('api.content.proposed')
                : (!empty($r['pending']) ? __('api.content.saved_pending') : __('api.content.saved_published')),
]);
