<?php
/**
 * GET shout_mentions&q=<prefix> — the names the composer's `@` is offering (1.61.0).
 *
 * ── it is a typeahead, which is why every bound here is a small number ─────────────────────────
 *
 * Somebody writing "@ali" makes one of these every few keystrokes, and a tracker's `users` table is
 * the one table that grows without anybody deciding it should. So this answers with names and
 * NOTHING else, eight of them, matched on a PREFIX — `username LIKE 'ali%'` is an index range and
 * `LIKE '%ali%'` is a scan of the whole table, which is the difference between a suggestion box and
 * a way to make the database somebody else's afternoon.
 *
 * The client does the other half (assets/js/shoutbox.js): nothing before two characters, a 250 ms
 * debounce, one request in the air at a time, and every prefix it has already asked about kept for
 * the life of the page so backspacing re-asks nothing. Neither half is trusted to be the only one —
 * the two-character floor and the limit are enforced again here, because a guard that lives only in
 * a browser is a guard.
 *
 * ── who is on it ──────────────────────────────────────────────────────────────────────────────
 *
 * `shout.post`, not `shout.view`: this list exists to help somebody WRITE a name, and a reader who
 * may only read the room has no use for it and no claim on it. Banned accounts and profiles hidden
 * from this reader are absent, the same two rules api/user_directory.php applies — a suggestion
 * that leads to somebody who has blocked you is worse than no suggestion.
 */
if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!userCan($db, $cfg, 'shout.post')) jsonResponse(['error' => 'no_permission'], 403);

// Read-only from here. PHP serialises one browser's requests on the session file, and somebody
// typing would otherwise have every other thing their page asks for queued behind the typeahead.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

// A person writing one @name costs two or three of these; a script gets about a second. The window
// is the one api/shout_list.php uses, with a ceiling sized for typing rather than for polling.
if (!rateLimitAllow('shoutmention', ipBucket(getClientIp($cfg)), 90, 60)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 60], 429);
}

$q = trim((string)($_GET['q'] ?? ''));
// Two characters, and the character class shoutMentionTokens() uses — so a string that could never
// be part of a mention is never a query. Fewer than two answers with nothing rather than with the
// first eight accounts on the tracker: "@a" is not somebody looking for a name, and a member list
// that falls out of an almost-empty box is a directory nobody consented to being in.
if (mb_strlen($q) < 2 || !preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $q)) {
    jsonResponse(['success' => true, 'names' => []]);
}

// The two LIKE metacharacters are escaped even though the class above admits only `_`: the rule
// that keeps this a prefix match has to survive somebody widening that class later.
$prefix = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
$st = $db->prepare("SELECT u.username FROM users u
                     WHERE u.status = 'active' AND u.username LIKE ?
                       AND NOT EXISTS (SELECT 1 FROM user_blocks b
                                        WHERE b.user_id = u.id AND b.blocked_id = ? AND b.hide_profile = 1)
                     ORDER BY u.username ASC LIMIT 8");
$st->execute([$prefix, (int)$me['id']]);

jsonResponse(['success' => true, 'names' => array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))]);
