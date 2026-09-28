<?php
/**
 * GET hash_favourites&hash=<40 hex>[&page=][&per_page=][&search=] — who has this in their favourites: the
 * 1.69.0 answer, kept as it was for whoever reads it. The overlay itself asks api/hash_who.php since 1.70.0,
 * one section at a time (favourites, likes / ratings, lists), and the queries are that endpoint's: this file
 * and that one read the same functions (includes/who.php), so the two can never disagree about who is shown.
 *
 * ── the rule, and why the count obeys it too ───────────────────────────────────────────────────
 *
 * Somebody appears here only when every gate above them says yes: the site allows public favourites
 * and allows this list, their group holds `favourites.public`, their own list is public, and they
 * have not asked to be left off other people's lists (`fav_listed`). Any single no removes them from
 * the rows AND from the total.
 *
 * "14 people have this, 3 shown" would be a leak with a delay. The difference is stable and
 * cumulative, so whoever polls the counter learns the exact moment a hidden person favourited
 * something — a channel nobody asked to open and no user setting closes. So a hidden reader is not
 * counted anonymously; they are not there at all.
 *
 * ── and why the permission is decided in SQL ───────────────────────────────────────────────────
 *
 * Permissions live as JSON on group rows, so the decision cannot be expressed in SQL directly — but
 * it must be MADE there. Filtering in PHP after the LIMIT makes `total` a lie, and paginating a lie
 * is a leak: page two shows rows page one's count did not admit to. userGroupIdsWithPermission()
 * does one cheap query, decodes in PHP, and hands the WHERE a literal list of integers.
 */
if (!favWhoEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);

$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'not_found'], 404);
// A hash somebody cannot find is a hash they cannot ask about — the same rule api/index_info.php
// applies, and the reason this is checked before anything else is read.
if (!userCan($db, $cfg, 'index.view')) jsonResponse(['error' => 'not_found'], 404);
if (!userCan($db, $cfg, 'favourites.view_others')) jsonResponse(['error' => 'not_found'], 404);

$perHour = (int)($cfg['rate_limit_index_search'] ?? 120);
if (!rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$hash = strtolower(trim(is_string($_GET['hash'] ?? null) ? (string)$_GET['hash'] : ''));
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

// After the checks, before the queries — the pattern api/index_search.php documents at length.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

// This answer's own paging, as 1.69.0 had it: 25 a page by default, 100 at most.
$p = whoParams($_GET, 25, 100);
$fav = whoFavPage($db, $cfg, $hash, (int)$me['id'], $p);

// The public LISTS this hash is on — the overlay's Lists section, its first twenty, with the same gates
// (and its own switch, who_lists_enabled). Only on the first page: it is context for the answer, not a
// second paginated thing here; api/hash_who.php pages it.
$lists = $fav['page'] === 1 && whoListsEnabled($cfg)
    ? whoListsPage($db, $cfg, $hash, (int)$me['id'], ['page' => 1, 'per_page' => WHO_PER_PAGE, 'search' => ''])['rows'] : [];

jsonResponse([
    'success'  => true,
    'lists'    => $lists,
    // A name and the picture beside it (1.63.0) — as an ADDRESS, never an id, and no timestamp: what this
    // reply says about a person is that they agreed to be named, and nothing else.
    'rows'     => $fav['rows'],
    'total'    => $fav['total'],
    'page'     => $fav['page'],
    'pages'    => $fav['pages'],
    'per_page' => $fav['per_page'],
]);
