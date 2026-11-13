<?php
/**
 * GET hash_check&hash=… — what this tracker knows about one info hash (the status page).
 *
 * The input is whatever a person has to hand: a 40-hex hash, a 32-character base32 one, or a whole
 * magnet link — parsed by the same parseMagnetOrHash() the whitelist form uses, so the two never
 * disagree about what counts as a hash.
 *
 * Gated by status.hash_check (members by default; the guest group gets it only if the operator hands
 * it out) and rate-limited per address: a hash the tracker has never met is answered too, and an
 * unbounded "do you know this one?" is a free oracle for walking the catalogue.
 *
 * That one permission buys the question. Each section of the answer is still the catalogue's, and
 * hashCheckGates() asks the permissions that publish it elsewhere — index.view for the swarm,
 * whitelist.view for the registration, content.view for the words — so this page is not the way
 * round the Info panel's gates. Known-or-unknown and the ban are answered for every reader who gets
 * this far, because a ban is the thing people come here to check.
 */
require_once __DIR__ . '/../includes/hashcheck.php';

if (!userCan($db, $cfg, 'status.hash_check')) {
    jsonResponse(['error' => __('api.hashcheck.denied')], 403);
}
$perHour = (int)($cfg['rate_limit_hash_check'] ?? 120);
if (!rateLimitAllow('hashcheck', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}
// Read-only from here: let go of the session so this lookup does not queue the reader's other requests.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$p = parseMagnetOrHash((string)($_GET['hash'] ?? ''));
if ($p['hash'] === null) {
    jsonResponse(['error' => $p['error'] === 'empty' ? __('api.hashcheck.empty') : (string)$p['error']], 400);
}
jsonResponse(['success' => true] + hashCheckLookup($db, $cfg, $p['hash'], hashCheckGates($db, $cfg)));
