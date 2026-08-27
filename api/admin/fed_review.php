<?php
/**
 * Admin: the federation quarantine queue (fed_import_mode = review).
 *
 *   {"op":"list",   "peer":"", "state":"pending", "limit":50, "offset":0}
 *   {"op":"counts"}
 *   {"op":"accept", "ids":[..]}            or {"op":"accept","peer":"name","password":"…"}
 *   {"op":"reject", "ids":[..]}            or {"op":"reject","peer":"name","password":"…"}
 *   {"op":"unreject","ids":[..]}
 *
 * Accepting one row at a time is a click; accepting a peer's entire backlog publishes whatever it
 * sent without anyone having read it, which is a different kind of decision — so that one asks for
 * the admin password, and the per-row operations do not.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? 'list');
$peer = mb_substr(trim((string)($input['peer'] ?? '')), 0, 64);
$ids = is_array($input['ids'] ?? null) ? array_slice($input['ids'], 0, 5000) : [];

if ($op === 'counts') {
    jsonResponse(['success' => true, 'counts' => fedReviewCounts($db)]);
}

if ($op === 'list') {
    $state = ($input['state'] ?? 'pending') === 'rejected' ? 'rejected' : 'pending';
    jsonResponse([
        'success' => true,
        'state'   => $state,
        'rows'    => fedReviewList($db, $peer, $state, (int)($input['limit'] ?? 50), (int)($input['offset'] ?? 0)),
        'counts'  => fedReviewCounts($db),
    ]);
}

if (!in_array($op, ['accept', 'reject', 'unreject'], true)) {
    jsonResponse(['error' => 'Unknown operation'], 400);
}
if (!$ids && $peer === '') {
    jsonResponse(['error' => 'Nothing selected'], 400);
}
// The password gate is on the sweeping form only. A whole peer's queue can be tens of thousands of
// descriptions nobody has looked at, and "accept all" publishes every one of them.
if (!$ids) {
    $password = (string)($input['password'] ?? '');
    if ($password === '' || ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        jsonResponse(['error' => 'Wrong admin password'], 403);
    }
}

try {
    if ($op === 'accept') {
        $t = fedReviewAccept($db, $ids, $peer);
        $msg = 'Accepted ' . number_format($t['accepted']) . ' package(s)'
             . ($t['files'] ? ', ' . number_format($t['files']) . ' file records' : '')
             . ($t['skipped'] ? '; ' . number_format($t['skipped']) . ' were already resolved here and were left alone' : '') . '.';
        jsonResponse(['success' => true, 'message' => $msg, 'tally' => $t, 'counts' => fedReviewCounts($db)]);
    }
    if ($op === 'reject') {
        $n = fedReviewReject($db, $ids, $peer);
        jsonResponse(['success' => true, 'counts' => fedReviewCounts($db),
            'message' => 'Rejected ' . number_format($n) . ' package(s). They will not be offered again.']);
    }
    $n = fedReviewUnreject($db, $ids, $peer);
    jsonResponse(['success' => true, 'counts' => fedReviewCounts($db),
        'message' => 'Cleared ' . number_format($n) . ' rejection(s). The peer may offer them again on the next pull.']);
} catch (\Throwable $e) {
    error_log('[fed review] ' . $e->getMessage());
    jsonResponse(['error' => 'The queue could not be updated: ' . $e->getMessage()], 500);
}
