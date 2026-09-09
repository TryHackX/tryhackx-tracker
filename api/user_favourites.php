<?php
/**
 * The favourites list: the owner's own, or a profile's as a stranger may see it.
 *
 *   GET  user_favourites[&user=<name>][&page=][&per_page=][&search=][&sort=]
 *   POST user_favourites  {op:'toggle'|'add'|'remove', hash, csrf_token}
 *
 * ── one 404 for every "no" ─────────────────────────────────────────────────────────────────────
 * A wrong name, no such account, a suspended one, a hidden list, the feature switched off, the
 * permission missing — all the same answer. Registration already tells anybody that a name is
 * taken, so this is not about keeping that secret; it is about not handing out a cheap, scriptable
 * way to tell HIDDEN apart from NONEXISTENT.
 *
 * ── two cheap queries, never a join ────────────────────────────────────────────────────────────
 * The user's own hashes first (bounded by fav_max_per_user), then the metadata for one page by
 * literal IN(). Joining user_favourites to a 3.4-million-row catalogue with a MATCH() on it is the
 * plan that took this server off the air for twenty-four minutes; see includes/index.php.
 */
if (!favEnabled($cfg)) jsonResponse(['error' => 'favourites_disabled'], 404);

$me = currentUser($db);

/* ───────────────────────────── POST: the star ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    if (!userCan($db, $cfg, 'favourites.use')) jsonResponse(['error' => 'no_permission'], 403);
    // The star is exactly as automatable as the rating button beside it, and it writes to a table in
    // a database three other applications share.
    $perHour = (int)($cfg['rate_limit_favourites'] ?? 240);
    if (!rateLimitAllow('favtoggle', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
    }
    $hash = strtolower(trim((string)($input['hash'] ?? '')));
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

    // A hash somebody cannot find is a hash they cannot favourite: the same rule api/index_info.php
    // applies. Without it the star would be a way to confirm that an unlisted hash exists.
    if (!userCan($db, $cfg, 'index.view')) jsonResponse(['error' => 'no_permission'], 403);

    $op = (string)($input['op'] ?? 'toggle');
    $want = $op === 'add' ? true : ($op === 'remove' ? false : null);
    $r = favToggle($db, $cfg, (int)$me['id'], $hash, $want);
    if (!empty($r['error'])) {
        jsonResponse(['error' => $r['error'], 'limit' => $r['limit'] ?? favMaxPerUser($cfg)], 409);
    }
    jsonResponse(['success' => true, 'on' => $r['on'], 'count' => favCountOf($db, (int)$me['id'])]);
}

/* ───────────────────────────── GET: a list ──────────────────────────────── */

$who = trim((string)($_GET['user'] ?? ''));
$owner = null;          // whose list this is
$isOwn = false;

if ($who === '') {
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    if (!userCan($db, $cfg, 'favourites.use')) jsonResponse(['error' => 'no_permission'], 403);
    $owner = $me;
    $isOwn = true;
} else {
    // Somebody else's. Every gate below answers with the same 404 as a name that does not exist.
    if (!$me) jsonResponse(['error' => 'not_found'], 404);                       // profiles are for signed-in readers
    if (!profilesEnabled($cfg) || !favPublicEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);
    if (!userCan($db, $cfg, 'favourites.view_others')) jsonResponse(['error' => 'not_found'], 404);
    if (!userValidUsername($who)) jsonResponse(['error' => 'not_found'], 404);
    $owner = userFindByLogin($db, $who);
    if (!$owner || ($owner['status'] ?? '') !== 'active') jsonResponse(['error' => 'not_found'], 404);
    $isOwn = (int)$owner['id'] === (int)($me['id'] ?? 0);
    if (!$isOwn) {
        if ((int)($owner['fav_public'] ?? 0) !== 1) jsonResponse(['error' => 'not_found'], 404);
        // Their group has to allow a public list, not just their own checkbox: an operator who takes
        // `favourites.public` away from a group means it, and a stale checkbox must not outlive it.
        if (!userIdHasPermission($db, $cfg, (int)$owner['id'], 'favourites.public')) jsonResponse(['error' => 'not_found'], 404);
    }
}

// Read-only from here on, and the queries below are the slow part.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
// A list about a person is not a thing to leave in a shared cache. jsonResponse() sets no cache
// headers of its own (includes/functions.php), so this is the only place it can be said.
header('Cache-Control: private, no-store');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
$search = trim((string)($_GET['search'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'added:desc');

$hashes = favHashesOf($db, (int)$owner['id'], favMaxPerUser($cfg));
$rows = $hashes ? favRowsFor($db, $hashes) : [];
// The order of $hashes is "newest favourited first" and favRowsFor preserves it, so `added` needs no
// column of its own — it is the order the list already arrived in.
$byHash = array_flip($hashes);
$addedRank = static fn(array $r): int => $byHash[$r['info_hash']] ?? PHP_INT_MAX;

if ($search !== '') {
    // A LIKE over an array PHP already holds. This set is one person's favourites, capped at
    // fav_max_per_user — never a table scan. Do not copy this to anything that reads a table.
    $needle = mb_strtolower($search);
    $rows = array_values(array_filter($rows, static function (array $r) use ($needle) {
        return ($r['name'] !== null && str_contains(mb_strtolower((string)$r['name']), $needle))
            || str_starts_with($r['info_hash'], strtolower($needle));
    }));
}

[$col, $dir] = array_pad(explode(':', $sort, 2), 2, 'desc');
$desc = strtolower($dir) !== 'asc';
$cmp = [
    'added'   => static fn($a, $b) => $addedRank($a) <=> $addedRank($b),
    'name'    => static fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']),
    'size'    => static fn($a, $b) => (int)$a['total_size'] <=> (int)$b['total_size'],
    'seeders' => static fn($a, $b) => (int)$a['seeders'] <=> (int)$b['seeders'],
][$col] ?? null;
if ($cmp === null) { $cmp = $cmp = static fn($a, $b) => $addedRank($a) <=> $addedRank($b); $desc = false; }
usort($rows, static fn($a, $b) => $desc ? -$cmp($a, $b) : $cmp($a, $b));
// `added:desc` IS the arrival order, so descending must not reverse it twice.
if ($col === 'added' && $desc) $rows = array_reverse($rows);

$total = count($rows);
$slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
$canMagnet = userCan($db, $cfg, 'index.magnet');
foreach ($slice as &$r) {
    // A banned hash renders without a magnet: the tracker refuses to serve it, and handing over a
    // link that cannot work is worse than saying so.
    if (!$canMagnet || $r['banned']) $r['info_hash'] = $canMagnet ? $r['info_hash'] : null;
    foreach (['total_size', 'files_count', 'seeders', 'leechers'] as $k) {
        $r[$k] = $r[$k] !== null ? (int)$r[$k] : null;
    }
}
unset($r);

jsonResponse([
    'success'  => true,
    'own'      => $isOwn,
    'user'     => ['username' => $owner['username'], 'member_since' => $owner['created_at'] ?? null],
    'rows'     => $slice,
    'total'    => $total,
    'page'     => $page,
    'pages'    => max(1, (int)ceil($total / $perPage)),
    'per_page' => $perPage,
    'max'      => favMaxPerUser($cfg),
    'capped'   => count($hashes) >= favMaxPerUser($cfg),
]);
