<?php
/**
 * Admin: undo everything imported from one peer (F7).
 *
 *   {"op":"count","peer":"name"}                     — what it would touch
 *   {"op":"run","peer":"name","password":"…"}        — one bounded slice of the work
 *
 * "Undo" puts each row back to unresolved; it does not delete it. The hash was observed by this
 * tracker's own swarm and carries local history the peer never provided — first_seen, seen_count,
 * the seeder peaks. Only the description came from them, so only the description goes.
 *
 * The work is sliced rather than done in one statement because a peer that has been feeding this
 * node for a month can own a million rows, and one statement over a million rows on a MariaDB
 * shared with mail and a forum takes something else down as a side effect. Each call does at most
 * PURGE_SLICE rows and says how many are left; the browser calls again until it reaches zero. For a
 * genuinely enormous backlog `worker/federation.py --purge NAME` does the same work without a
 * browser tab having to stay open, and the endpoint says so.
 */
requirePost();
$input = readJsonBody();
$peer = mb_substr(trim((string)($input['peer'] ?? '')), 0, 64);
$op = (string)($input['op'] ?? 'count');
if ($peer === '' || !fedPeerValidName($peer)) jsonResponse(['error' => 'Which peer?'], 400);

const PURGE_SLICE = 2000;     // rows per request — a second or two of work, never a locked table

$c = fedPurgeCount($db, $peer);
if ($op === 'count') {
    jsonResponse(['success' => true] + $c + [
        'cli' => 'sudo -u www-data python3 /opt/tracker-metadata/federation.py /etc/tracker-metadata.conf --purge ' . $peer,
    ]);
}
if ($op !== 'run') jsonResponse(['error' => 'Unknown operation'], 400);

$password = (string)($input['password'] ?? '');
requireAdminReauth($password, $cfg);

$done = 0;
try {
    while ($done < PURGE_SLICE) {
        $n = fedPurgeBatch($db, $peer, min(500, PURGE_SLICE - $done));
        if ($n === 0) break;
        $done += $n;
    }
    $db->prepare("DELETE FROM fed_review WHERE peer_name = ?")->execute([$peer]);
} catch (\Throwable $e) {
    error_log('[fed purge] ' . $e->getMessage());
    jsonResponse(['error' => 'Stopped after ' . number_format($done) . ' row(s): ' . $e->getMessage()], 500);
}
$left = fedPurgeCount($db, $peer);
jsonResponse([
    'success'   => true,
    'done'      => $done,
    'remaining' => $left['rows'],
    'message'   => $left['rows'] > 0
        ? number_format($done) . ' row(s) undone, ' . number_format($left['rows']) . ' still to go.'
        : number_format($done) . ' row(s) returned to unresolved. Nothing from this peer is left.',
]);
