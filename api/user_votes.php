<?php
/**
 * A member's likes or ratings, one page of the table at a time (1.69.0, includes/profilevotes.php).
 *
 *   GET user_votes[&user=<name>][&page=][&per_page=][&search=][&files=0|1][&sort=][&dir=asc|desc]
 *                 stars mode:  [&own_min=1..10][&own_max=1..10][&avg_min=0..500][&avg_max=0..500]
 *                 thumbs mode: [&vote=all|up|down][&min_votes=0..]
 *   → {success, own, mode, min_votes, rows, total, page, pages, per_page, params}
 *
 * Without `user` it is the reader's own list (the account page's tab); with it, a profile's as that
 * reader may see it. `sort` is one of date|own|score|votes|name|size|seeders (date, newest first, by
 * default); every parameter is validated and clamped by profileVotesParams() and the answer says what
 * was actually used, so a page can show the filter it really got.
 *
 * ── one 404 for every "no" ─────────────────────────────────────────────────────────────────────
 * The feature or ratings switched off, a name nobody has, a suspended account, a hidden list, a
 * missing grant, a block — the same answer, as api/user_favourites.php gives: registration already
 * tells anybody that a name is taken, so this is about not handing out a cheap way to tell HIDDEN from
 * NONEXISTENT. The decision is profileVotesShownTo(), the one the profile page asks.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(['error' => 'method_not_allowed'], 405);
if (!profileVotesEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);

$me = currentUser($db);
$who = trim((string)($_GET['user'] ?? ''));
if ($who === '') {
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    $owner = $me;
} else {
    // Profiles are for signed-in readers, and everything after this line answers like a missing name.
    if (!$me || !userValidUsername($who)) jsonResponse(['error' => 'not_found'], 404);
    $owner = userFindByLogin($db, $who);
    if (!$owner || !profileVotesShownTo($db, $cfg, $owner, $me)) jsonResponse(['error' => 'not_found'], 404);
}
$isOwn = (int)$owner['id'] === (int)$me['id'];

// Read-only from here on, and the queries below are the slow part: the session lock must not hold
// every other request of this browser behind them.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
// A list about a person is not a thing to leave in a shared cache (see api/user_favourites.php).
header('Cache-Control: private, no-store');

$p = profileVotesParams($_GET);
$reader = [
    'is_owner'  => $isOwn,
    // The same pair api/index_info.php and the favourites list ask before a whitelist row reaches
    // anybody, and the magnet permission the search page withholds a hash behind.
    'can_wl'    => userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1',
    'can_hash'  => userCan($db, $cfg, 'index.magnet'),
    'file_hits' => null,
];
// Inside file names too, as on a favourites list: gated on index.files (searching a file list you may
// not open is still reading it), and charged to the SAME hourly bucket the search page's file search
// spends — a loop over ?search= here must not be a cheaper way to spend the database.
$wantFiles = $p['files'] && userCan($db, $cfg, 'index.files');
if ($p['search'] !== '' && $wantFiles && mb_strlen($p['search']) >= 2) {
    if (!rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), (int)($cfg['rate_limit_index_search'] ?? 120), 3600)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
    }
    $reader['file_hits'] = profileVotesFileHits($db, $cfg, (int)$owner['id'], $p['search']);
}

$res = profileVotesList($db, $cfg, (int)$owner['id'], $p, $reader, userDisplayTimezone($me, $cfg));
$p['files'] = $wantFiles;
$p['page'] = $res['page'];
jsonResponse([
    'success'   => true,
    'own'       => $isOwn,
    'mode'      => repMode($cfg),
    'min_votes' => repMinVotes($cfg),
    'rows'      => $res['rows'],
    'total'     => $res['total'],
    'page'      => $res['page'],
    'pages'     => $res['pages'],
    'per_page'  => $res['per_page'],
    'params'    => $p,
]);
