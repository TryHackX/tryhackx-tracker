<?php
/**
 * POST rate_hash — one visitor's opinion of one torrent. Up or down, nothing else.
 *
 * The interesting part is not the counting, it is who is allowed to press the button. Four layers,
 * because no single one holds: a UNIQUE key in the database (not a SELECT in PHP, which is a race),
 * the shared rate limiter, the CAPTCHA points scheme this site already has, and a weight that makes
 * an anonymous vote worth less than an account's.
 *
 * GET returns the current standing without voting, so a page can show a score to somebody who is
 * not allowed to change it.
 */
if (!repEnabled($cfg)) jsonResponse(['error' => 'Ratings are off on this tracker.'], 404);

$hash = strtolower(trim((string)($_GET['hash'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? '' : ''))));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    $hash = strtolower(trim((string)($input['hash'] ?? '')));
}
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => 'Invalid hash'], 400);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => true, 'rating' => repFor($db, $cfg, $hash),
                  'my_vote' => repMyVote($db, $cfg, $hash),
                  'can_vote' => repVoteRefusal($db, $cfg) === null,
                  'why_not' => repVoteRefusal($db, $cfg)]);
}

if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

// The CAPTCHA joins in through the points scheme already here: steady use is never interrupted,
// fifty votes in a minute meets a challenge, and nobody had to write a bot detector.
if (captchaConfigured($cfg) && isCaptchaRequired($cfg, 'vote')) {
    $token = (string)($input['captcha_token'] ?? '');
    if ($token === '' || !verifyCaptcha($token, $cfg)) {
        jsonResponse(['error' => 'captcha_required', 'captcha' => true], 428);
    }
    onCaptchaSolved();
}
addCaptchaPoints($cfg, 'vote');

$vote = (int)($input['vote'] ?? 0);
$r = repCastVote($db, $cfg, $hash, $vote);
if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 403);
jsonResponse(['success' => true, 'rating' => repFor($db, $cfg, $hash),
              'my_vote' => repMyVote($db, $cfg, $hash)]);
