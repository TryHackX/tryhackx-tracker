<?php
/**
 * The descriptions a member wrote, one page of the table at a time (1.70.0, includes/profiledescs.php).
 *
 *   GET user_descriptions[&user=<name>][&page=][&per_page=][&search=][&sort=date|name|role][&dir=asc|desc]
 *   → {success, own, rows, total, page, pages, per_page, params}
 *
 * Without `user` it is the reader's own list (the account page's tab: every state, their own waiting
 * and turned-down proposals too); with it, a profile's as that reader may see it — published only.
 * Every parameter is validated and clamped by profileDescsParams(), and the answer says what was used.
 *
 * ── one 404 for every "no" ─────────────────────────────────────────────────────────────────────
 * The feature or descriptions switched off, a name nobody has, a suspended account, a hidden list, a
 * hidden NAME, a missing grant, a block — the same answer, as api/user_votes.php gives. The decision is
 * profileDescsShownTo(), the one the profile page asks.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(['error' => 'method_not_allowed'], 405);
if (!profileDescsEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);

$me = currentUser($db);
$who = trim((string)($_GET['user'] ?? ''));
if ($who === '') {
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    $owner = $me;
} else {
    if (!$me || !userValidUsername($who)) jsonResponse(['error' => 'not_found'], 404);
    $owner = userFindByLogin($db, $who);
    if (!$owner || !profileDescsShownTo($db, $cfg, $owner, $me)) jsonResponse(['error' => 'not_found'], 404);
}
$isOwn = (int)$owner['id'] === (int)$me['id'];

// Read-only from here on: the session lock must not hold this browser's other requests behind it.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$p = profileDescsParams($_GET);
$reader = [
    'is_owner' => $isOwn,
    // The pair api/index_info.php asks before a registered torrent reaches anybody, and the magnet
    // permission a hash is withheld behind.
    'can_wl'   => userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1',
    'can_hash' => userCan($db, $cfg, 'index.magnet'),
];
$res = profileDescsList($db, $cfg, (int)$owner['id'], $p, $reader, userDisplayTimezone($me, $cfg));
$p['page'] = $res['page'];
jsonResponse([
    'success'  => true,
    'own'      => $isOwn,
    // Each row's star in the READER's state (1.72.1, favMarkRows()): one question for the page.
    'rows'     => favMarkRows($db, $cfg, $res['rows']),
    'total'    => $res['total'],
    'page'     => $res['page'],
    'pages'    => $res['pages'],
    'per_page' => $res['per_page'],
    'params'   => $p,
]);
