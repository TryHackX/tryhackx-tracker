<?php
/**
 * POST rate_hash — one visitor's opinion of one torrent.
 *
 * Two shapes, one table: a thumb (1 or -1) or a star rating (1..10, half a star each). Which one is
 * accepted is repAllowedValues($cfg), so switching the mode does not need a second endpoint. Any
 * 40-hex hash may be rated, whitelisted or not: an opinion about a torrent is not a statement about
 * whether this tracker serves it.
 *
 * The interesting part is not the counting, it is who is allowed to press the button. Four layers,
 * because no single one holds: a UNIQUE key in the database (not a SELECT in PHP, which is a race),
 * the shared rate limiter, the CAPTCHA points scheme this site already has, and a weight that makes
 * an anonymous vote worth less than an account's.
 *
 * GET returns the current standing without voting, so a page can show a score to somebody who is
 * not allowed to change it. It only LOOKS at the hourly budget (repVoteRefusal() without spending).
 *
 * POST {hash, vote, csrf_token} casts — {op: 'vote'} is the same, and the default. POST {hash, op: 'remove',
 * csrf_token} takes this reader's vote back (1.71.0, repRemoveVote()): the page's second press on the thumb
 * or the half star it already cast. An explicit operation, not a toggle: the page says what it wants and
 * this does exactly that — removing a vote that is not there succeeds and changes nothing. A removal
 * passes every gate a vote does and pays the same: the CAPTCHA points below and the hour's budget.
 *   → {success, rating, my_vote[, removed]}
 */
if (!repEnabled($cfg)) jsonResponse(['error' => __('api.rep.disabled')], 404);

$hash = strtolower(trim((string)($_GET['hash'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? '' : ''))));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    $hash = strtolower(trim((string)($input['hash'] ?? '')));
}
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => true, 'rating' => repFor($db, $cfg, $hash),
                  'my_vote' => repMyVote($db, $cfg, $hash),
                  'can_vote' => repVoteRefusal($db, $cfg) === null,
                  'why_not' => repVoteRefusal($db, $cfg)]);
}

if (empty($input['csrf_token']) || !is_string($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}

// Which of the two: asked for by name, never guessed from the vote's value (1.71.0).
$op = $input['op'] ?? 'vote';
if (!is_string($op) || !in_array($op, ['vote', 'remove'], true)) {
    jsonResponse(['error' => __('api.rep.unknown_op')], 400);
}

// How fast, and the CAPTCHA (1.71.0): the site's one anti-spam layer (includes/antispam.php, context `vote`) —
// ten free (somebody rating as they browse), then 2 s, 5 s, 10 s and 30 s between votes, two quiet minutes and
// it starts over; whoever keeps at the top meets a CAPTCHA. Until 1.71.0 a vote could never meet one: the gate
// here asked isCaptchaRequired('vote'), which reads a `recaptcha_on_vote` switch no setting ever defined.
// Asked only of somebody who may vote at all (the refusal peeked first, spending nothing); a vote taken back is
// a press of the same button and costs the same. A vote still adds `captcha_pts_vote` to the smart score the
// site's forms read (the report, status and appeal forms, the sign-in).
if (($why = repVoteRefusal($db, $cfg)) !== null) jsonResponse(['error' => $why], 403);
$as = antispamCheck($db, $cfg, 'vote', antispamSubject(usersEnabled($cfg) ? currentUser($db) : null, getClientIp($cfg)), null, ['input' => $input]);
if (!$as['ok']) jsonResponse($as['body'] + ['captcha' => $as['kind'] === 'captcha'], (int)$as['status']);
addCaptchaPoints($cfg, 'vote');

if ($op === 'remove') {
    $r = repRemoveVote($db, $cfg, $hash);
    if (!empty($r['error'])) { antispamRelease($db, $as['ticket']); jsonResponse(['error' => $r['error']], 403); }
    antispamRecord($db, $as['ticket']);
    jsonResponse(['success' => true, 'removed' => (bool)$r['removed'], 'rating' => repFor($db, $cfg, $hash),
                  'my_vote' => repMyVote($db, $cfg, $hash)]);
}
$vote = (int)($input['vote'] ?? 0);
$r = repCastVote($db, $cfg, $hash, $vote);
if (!empty($r['error'])) { antispamRelease($db, $as['ticket']); jsonResponse(['error' => $r['error']], 403); }
antispamRecord($db, $as['ticket']);
jsonResponse(['success' => true, 'rating' => repFor($db, $cfg, $hash),
              'my_vote' => repMyVote($db, $cfg, $hash)]);
