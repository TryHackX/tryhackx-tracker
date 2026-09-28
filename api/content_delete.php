<?php
/**
 * POST content_delete — take a description down from the Info panel (1.70.0, includes/content.php).
 *
 *   {"csrf_token":"…","hash":"<hex40 | base32 | magnet>"}
 *   → {success, message, right: "own"|"any", withdrawn: n}
 *
 * Who may: the author of record, with `content.delete_own` (their own words, in whatever state), or
 * a holder of `content.delete_any` (a published description) — contentDeleteRight(), asked of the
 * signed-in ACCOUNT; a panel session alone is nobody here (the panel has its own Clear). The words and
 * the source link go the way the panel's Clear takes them, the proposals waiting on them are
 * withdrawn, one audit line is written, and the author is told when it was somebody else.
 *
 * A registered torrent's record is answered only for a reader who may see registered torrents (the
 * Info panel's own rule): anybody else gets the 404 a hash with nothing on it gets. Not tied to
 * descriptions being switched on: taking your own words down must not depend on new ones being
 * accepted. Two clicks in the page (the second is the confirmation), rate limited per account.
 */
require_once __DIR__ . '/../includes/content.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'POST required'], 405);
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = usersEnabled($cfg) ? currentUser($db) : null;
if ($me === null) jsonResponse(['error' => __('api.content.delete_denied')], 401);
if (!rateLimitAllow('contentdel', 'u' . (int)$me['id'], 30, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}
$p = parseMagnetOrHash((string)($input['hash'] ?? ''));
if ($p['hash'] === null) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

$rec = contentRecordFor($db, $p['hash']);
$canWl = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';
if ($rec !== null && $rec['kind'] === 'wl' && !$canWl) $rec = null;
if ($rec === null || !contentOccupied($rec)) jsonResponse(['error' => __('api.content.nothing_to_delete')], 404);
$right = contentDeleteRight($db, $cfg, $rec, $me);
if ($right === null) jsonResponse(['error' => __('api.content.delete_denied')], 403);

$r = contentDelete($db, $cfg, $rec, $me, $right);
jsonResponse([
    'success'   => true,
    'right'     => $right,
    'withdrawn' => (int)$r['withdrawn'],
    'message'   => __($right === 'own' ? 'api.content.deleted_own' : 'api.content.deleted_any'),
]);
