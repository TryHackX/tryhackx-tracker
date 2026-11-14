<?php
/**
 * GET hash_who&hash=<40 hex>&section=fav|votes|lists[&page=][&per_page=][&search=] — "who has this" (1.70.0),
 * one section of the overlay at a time (includes/who.php says who is in each, and why the count obeys every
 * gate the rows do).
 *
 *   → {success, section, rows, total, page, pages, per_page[, mode]}
 *     fav   rows: {username, avatar}
 *     votes rows: {username, avatar, vote}, and mode 'thumbs' (vote ±1) | 'stars' (vote 1–10, half stars)
 *     lists rows: {name, slug, username, items, avatar}
 *
 * 20 a page unless asked (at most 50), a page past the end empty, `search` by name (a person's; a list's or
 * its owner's). The overlay asks for all three sections' first page at once, in parallel, and then each
 * section's next page or search on its own.
 *
 * ── one 404 for every "no" ─────────────────────────────────────────────────────────────────────
 * A section switched off (or the feature under it), nobody signed in, a reader whose account may not look
 * up hashes or see people, a section that does not exist — the same answer, before the hash is even read:
 * none of them is a question about this torrent, and an answer that told them apart would be one.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(['error' => 'method_not_allowed'], 405);

$section = is_string($_GET['section'] ?? null) ? (string)$_GET['section'] : '';
$me = currentUser($db);
$open = whoSections($db, $cfg, $me);
if (!in_array($section, WHO_SECTIONS, true) || empty($open[$section])) jsonResponse(['error' => 'not_found'], 404);

// Three sections to one look, so three requests where the 1.69.0 overlay made one: as many LOOKS an hour
// as it allowed, in a bucket of its own — opening the overlay must not spend the reader's catalogue searches.
$perHour = (int)($cfg['rate_limit_index_search'] ?? 120);
if (!rateLimitAllow('hashwho', ipBucket(getClientIp($cfg)), $perHour * count(WHO_SECTIONS), 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$hash = strtolower(trim(is_string($_GET['hash'] ?? null) ? (string)$_GET['hash'] : ''));
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

// Read-only from here on: the session lock must not hold this browser's other requests behind the queries.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
// A list of people is not a thing to leave in a shared cache (see api/user_favourites.php).
header('Cache-Control: private, no-store');

$res = whoPage($db, $cfg, $section, $hash, (int)$me['id'], whoParams($_GET));
jsonResponse(['success' => true, 'section' => $section] + $res);
